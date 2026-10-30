<?php

declare(strict_types=1);

// The local API of one contract case, run in its own process by MockServer
// (the app under test blocks this test's process while the SDK waits for an
// answer). Usage: php mock-server.php <config.json>, where the config is
// { "steps": [...] }, the case's `responses`.
//
// It listens on 127.0.0.1:0, prints {"origin": "http://127.0.0.1:<port>"} on
// stdout, answers request N with step N ({{mock}} replaced by its origin) and
// records each request. A `drop` step closes the connection without answering;
// a `hang` step never answers. Every answer closes its connection, so an HTTP
// client cannot resend a request on a reused one. `GET /__contract-mock__/stop`
// answers { requests, problems } and ends the process.

const STOP_PATH = '/__contract-mock__/stop';
const MAX_LIFETIME_SECONDS = 300;

/**
 * @return list<mixed>
 */
function readSteps(string $path): array
{
    $text = @file_get_contents($path);
    if (false === $text) {
        throw new RuntimeException('mock server: cannot read '.$path);
    }
    // Objects stay objects, so a JSON body `{}` is not sent back as `[]`.
    $config = json_decode($text, false, 512, \JSON_THROW_ON_ERROR);
    if (!$config instanceof stdClass || !is_array($config->steps ?? null) || !array_is_list($config->steps)) {
        throw new RuntimeException('mock server: the configuration has no steps list');
    }

    return $config->steps;
}

function substitute(mixed $value, string $origin): mixed
{
    if (is_string($value)) {
        return (string) preg_replace_callback('/\{\{([^}]*)\}\}/', static function (array $match) use ($origin): string {
            if ('mock' === $match[1]) {
                return $origin;
            }
            if (1 === preg_match('/^httpDate\+(\d+)$/', $match[1], $date)) {
                return gmdate('D, d M Y H:i:s', (int) round(microtime(true) + (int) $date[1])).' GMT';
            }
            throw new RuntimeException(sprintf('unknown placeholder {{%s}}', $match[1]));
        }, $value);
    }
    if (is_array($value)) {
        return array_map(static fn (mixed $item): mixed => substitute($item, $origin), $value);
    }
    if ($value instanceof stdClass) {
        $out = new stdClass();
        foreach (get_object_vars($value) as $key => $item) {
            $out->{$key} = substitute($item, $origin);
        }

        return $out;
    }

    return $value;
}

/**
 * @return array{int, array<string, string>, string}
 */
function renderStep(mixed $step, string $origin): array
{
    if (!$step instanceof stdClass || !is_int($step->status ?? null)) {
        throw new RuntimeException('a response has no integer status');
    }
    $headers = [];
    $rawHeaders = $step->headers ?? new stdClass();
    if (!$rawHeaders instanceof stdClass) {
        throw new RuntimeException('response headers must be an object');
    }
    foreach (get_object_vars($rawHeaders) as $name => $value) {
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('header %s must be a string', $name));
        }
        $headers[(string) $name] = (string) substitute($value, $origin);
    }
    $hasType = [] !== array_filter(array_keys($headers), static fn (string $name): bool => 'content-type' === strtolower($name));

    $body = '';
    if (property_exists($step, 'json')) {
        $body = json_encode(
            substitute($step->json, $origin),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
        if (!$hasType) {
            $headers['Content-Type'] = 'application/json';
        }
    } elseif (property_exists($step, 'base64')) {
        $decoded = is_string($step->base64) ? base64_decode($step->base64, true) : false;
        if (false === $decoded) {
            throw new RuntimeException('base64 is not valid base64');
        }
        $body = $decoded;
    } elseif (property_exists($step, 'text')) {
        if (!is_string($step->text)) {
            throw new RuntimeException('text must be a string');
        }
        $body = $step->text;
    }

    return [$step->status, $headers, $body];
}

/**
 * @param resource              $socket
 * @param array<string, string> $headers
 */
function answer($socket, int $status, array $headers, string $body): void
{
    $head = sprintf("HTTP/1.1 %d %s\r\n", $status, $status < 400 ? 'OK' : 'Error');
    foreach ($headers as $name => $value) {
        $head .= $name.': '.$value."\r\n";
    }
    $head .= 'Content-Length: '.strlen($body)."\r\nConnection: close\r\n\r\n";
    stream_set_blocking($socket, true);
    @fwrite($socket, $head.$body);
    fclose($socket);
}

/**
 * Removes the first complete request from a connection buffer.
 *
 * @return array{method: string, target: string, headers: array<string, string>}|null
 */
function takeRequest(string &$buffer): ?array
{
    $headerEnd = strpos($buffer, "\r\n\r\n");
    if (false === $headerEnd) {
        return null;
    }
    $lines = explode("\r\n", substr($buffer, 0, $headerEnd));
    $requestLine = explode(' ', array_shift($lines));
    $headers = [];
    foreach ($lines as $line) {
        $colon = strpos($line, ':');
        if (false !== $colon) {
            $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }
    }
    $length = (int) ($headers['content-length'] ?? '0');
    if (strlen($buffer) < $headerEnd + 4 + $length) {
        return null;
    }
    $buffer = substr($buffer, $headerEnd + 4 + $length);

    return ['method' => $requestLine[0], 'target' => $requestLine[1] ?? '', 'headers' => $headers];
}

$steps = readSteps($argv[1] ?? '');
$listener = stream_socket_server('tcp://127.0.0.1:0');
$name = false === $listener ? false : stream_socket_get_name($listener, false);
if (false === $listener || false === $name) {
    fwrite(\STDERR, "mock server: cannot listen on 127.0.0.1\n");
    exit(2);
}
$origin = 'http://'.$name;
fwrite(\STDOUT, json_encode(['origin' => $origin], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n");
fflush(\STDOUT);

/** @var array<int, array{socket: resource, buffer: string, hung: bool}> $connections */
$connections = [];
/** @var list<array{method: string, target: string, authorization: string|null, idempotencyKey: string|null}> $requests */
$requests = [];
/** @var list<string> $problems */
$problems = [];
$deadline = microtime(true) + MAX_LIFETIME_SECONDS;

while (microtime(true) < $deadline) {
    $read = [$listener];
    foreach ($connections as $connection) {
        $read[] = $connection['socket'];
    }
    $write = null;
    $except = null;
    if (false === @stream_select($read, $write, $except, 0, 200_000)) {
        continue;
    }
    foreach ($read as $socket) {
        if ($socket === $listener) {
            $accepted = @stream_socket_accept($listener, 0);
            if (false !== $accepted) {
                stream_set_blocking($accepted, false);
                $connections[(int) $accepted] = ['socket' => $accepted, 'buffer' => '', 'hung' => false];
            }

            continue;
        }
        $id = (int) $socket;
        if (!isset($connections[$id])) {
            continue;
        }
        $chunk = @fread($socket, 65536);
        if (false === $chunk || '' === $chunk) {
            if (feof($socket)) {
                fclose($socket);
                unset($connections[$id]);
            }

            continue;
        }
        if ($connections[$id]['hung']) {
            continue;
        }
        $connections[$id]['buffer'] .= $chunk;
        $request = takeRequest($connections[$id]['buffer']);
        if (null === $request) {
            continue;
        }
        unset($connections[$id]);

        if (STOP_PATH === $request['target']) {
            foreach ($connections as $other) {
                fclose($other['socket']);
            }
            answer($socket, 200, ['Content-Type' => 'application/json'], json_encode(
                ['requests' => $requests, 'problems' => $problems],
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
            ));
            exit(0);
        }

        $requests[] = [
            'method' => $request['method'],
            'target' => $request['target'],
            'authorization' => $request['headers']['authorization'] ?? null,
            'idempotencyKey' => $request['headers']['idempotency-key'] ?? null,
        ];
        $step = $steps[count($requests) - 1] ?? null;
        if (null === $step) {
            // More requests than the case has answers: the recorded requests show it.
            answer($socket, 599, ['Content-Type' => 'application/json'], '{"error":"MOCK_EXHAUSTED"}');

            continue;
        }
        $fault = $step instanceof stdClass ? ($step->fault ?? null) : null;
        if ('drop' === $fault) {
            stream_socket_shutdown($socket, \STREAM_SHUT_RDWR);
            fclose($socket);

            continue;
        }
        if ('hang' === $fault) {
            // Never answered; closed when the client gives up, or at stop.
            $connections[$id] = ['socket' => $socket, 'buffer' => '', 'hung' => true];

            continue;
        }
        if (null !== $fault) {
            $problems[] = sprintf('request %d: unknown fault %s', count($requests), json_encode($fault));
            fclose($socket);

            continue;
        }
        try {
            [$status, $headers, $body] = renderStep($step, $origin);
        } catch (Throwable $e) {
            $problems[] = sprintf('request %d: %s', count($requests), $e->getMessage());
            fclose($socket);

            continue;
        }
        answer($socket, $status, $headers, $body);
    }
}

exit(3);
