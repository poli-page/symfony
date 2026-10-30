<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests\Unit\EventListener;

use PHPUnit\Framework\TestCase;

/**
 * A download that fails after the PDF headers and first bytes went out: the
 * listener must not append its JSON to the PDF body.
 */
final class MidStreamFailureTest extends TestCase
{
    public function testNothingIsAppendedAfterThePdfBytes(): void
    {
        $process = proc_open(
            [\PHP_BINARY, __DIR__.'/../../Fixtures/mid-stream-failure.php'],
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
        self::assertSame(str_pad('%PDF-1.4 ', 8192, 'x'), $stdout);
    }
}
