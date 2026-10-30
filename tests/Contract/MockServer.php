<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Contract;

use RuntimeException;

/**
 * Handle on a mock-server.php process: starts it with a case's `responses`,
 * gives its origin, and stops it to read the requests it recorded.
 */
final class MockServer
{
    /** @var resource */
    private $process;

    /** @var resource */
    private $stdout;

    private readonly string $configPath;

    private readonly string $stderrPath;

    public readonly string $origin;

    /**
     * @param list<mixed> $steps the case's `responses`, decoded with objects
     */
    public function __construct(array $steps)
    {
        $this->configPath = self::tempFile('config');
        $this->stderrPath = self::tempFile('stderr');
        file_put_contents($this->configPath, json_encode(['steps' => $steps], \JSON_THROW_ON_ERROR));
        $process = proc_open(
            [\PHP_BINARY, __DIR__.'/mock-server.php', $this->configPath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $this->stderrPath, 'w']],
            $pipes,
        );
        if (!is_resource($process) || !is_resource($pipes[0] ?? null) || !is_resource($pipes[1] ?? null)) {
            throw new RuntimeException('Cannot start the mock server process');
        }
        $this->process = $process;
        fclose($pipes[0]);
        $this->stdout = $pipes[1];

        $line = fgets($this->stdout);
        $ready = is_string($line) ? json_decode($line, true) : null;
        if (!is_array($ready) || !is_string($ready['origin'] ?? null)) {
            $this->kill();
            throw new RuntimeException('The mock server did not start: '.$this->stderr());
        }
        $this->origin = $ready['origin'];
    }

    /**
     * Stops the server and returns what it recorded.
     *
     * @return array{requests: list<array{method: string, target: string, authorization: ?string, idempotencyKey: ?string}>, problems: list<string>}
     */
    public function stop(): array
    {
        try {
            $answer = $this->requestStop();
        } finally {
            $this->kill();
        }

        $requests = [];
        foreach (self::listField($answer, 'requests') as $raw) {
            if (!is_array($raw) || !is_string($raw['method'] ?? null) || !is_string($raw['target'] ?? null)) {
                throw new RuntimeException('The mock server recorded a malformed request');
            }
            $requests[] = [
                'method' => $raw['method'],
                'target' => $raw['target'],
                'authorization' => is_string($raw['authorization'] ?? null) ? $raw['authorization'] : null,
                'idempotencyKey' => is_string($raw['idempotencyKey'] ?? null) ? $raw['idempotencyKey'] : null,
            ];
        }
        $problems = array_map(
            static fn (mixed $problem): string => is_string($problem) ? $problem : (string) json_encode($problem),
            self::listField($answer, 'problems'),
        );

        return ['requests' => $requests, 'problems' => $problems];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function requestStop(): array
    {
        $socket = @stream_socket_client('tcp://'.substr($this->origin, strlen('http://')), timeout: 5.0);
        if (false === $socket) {
            throw new RuntimeException('Cannot reach the mock server to stop it: '.$this->stderr());
        }
        stream_set_timeout($socket, 10);
        fwrite($socket, "GET /__contract-mock__/stop HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        $raw = stream_get_contents($socket);
        fclose($socket);
        $bodyStart = is_string($raw) ? strpos($raw, "\r\n\r\n") : false;
        $answer = is_string($raw) && false !== $bodyStart ? json_decode(substr($raw, $bodyStart + 4), true) : null;
        if (!is_array($answer)) {
            throw new RuntimeException('The mock server gave no recording: '.$this->stderr());
        }

        return $answer;
    }

    /**
     * @param array<array-key, mixed> $answer
     *
     * @return list<mixed>
     */
    private static function listField(array $answer, string $name): array
    {
        $value = $answer[$name] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException(sprintf('The mock server recording has no %s list', $name));
        }

        return $value;
    }

    private function kill(): void
    {
        if (is_resource($this->stdout)) {
            fclose($this->stdout);
        }
        if (proc_get_status($this->process)['running']) {
            proc_terminate($this->process);
        }
        proc_close($this->process);
        @unlink($this->configPath);
    }

    private function stderr(): string
    {
        $text = @file_get_contents($this->stderrPath);

        return false === $text || '' === $text ? '(no output)' : trim($text);
    }

    private static function tempFile(string $kind): string
    {
        $path = tempnam(sys_get_temp_dir(), 'poli-contract-'.$kind.'-');
        if (false === $path) {
            throw new RuntimeException('Cannot create a temporary file');
        }

        return $path;
    }

    public function __destruct()
    {
        @unlink($this->stderrPath);
    }
}
