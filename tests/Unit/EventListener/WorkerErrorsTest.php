<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Unit\EventListener;

use PHPUnit\Framework\TestCase;

/**
 * Several requests in one process after a stray echo: the listener leaves
 * alone only the response that really started, and still answers JSON for a
 * later request even though headers_sent() is true for the whole process.
 */
final class WorkerErrorsTest extends TestCase
{
    public function testOnlyTheResponseThatStartedIsLeftAlone(): void
    {
        $process = proc_open(
            [\PHP_BINARY, __DIR__.'/../../Fixtures/worker-errors.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, (string) $stderr);
        self::assertSame(
            'stray;'
            .str_pad('%PDF-1.4 ', 8192, 'x').';'
            .'{"code":"TEMPLATE_NOT_FOUND","message":"Template not found","status":404,"requestId":"req_1"};',
            $stdout,
        );
    }
}
