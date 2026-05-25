<?php

declare(strict_types=1);

namespace PoliPage\Symfony\Tests;

use Throwable;

/**
 * Snapshots the global error/exception handler stack in setUp() and unwinds
 * back to that baseline in tearDown().
 *
 * Why: Symfony's FrameworkBundle::boot() registers a global error handler
 * (driven by handle_all_throwables: true + php_errors.log: true in
 * TestKernel) and Kernel::shutdown() does NOT unregister it. PHPUnit 11.5+
 * marks any test that ends with a different handler stack as risky. This
 * trait restores the baseline so tests that boot a kernel are no longer
 * flagged.
 *
 * Usage: `use RestoresGlobalHandlers;` in any TestCase that boots a kernel.
 */
trait RestoresGlobalHandlers
{
    private mixed $errorHandlerBaseline = null;
    private mixed $exceptionHandlerBaseline = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->errorHandlerBaseline = self::peekErrorHandler();
        $this->exceptionHandlerBaseline = self::peekExceptionHandler();
    }

    protected function tearDown(): void
    {
        self::popUntil(
            self::peekErrorHandler(...),
            restore_error_handler(...),
            $this->errorHandlerBaseline,
        );
        self::popUntil(
            self::peekExceptionHandler(...),
            restore_exception_handler(...),
            $this->exceptionHandlerBaseline,
        );
        parent::tearDown();
    }

    private static function peekErrorHandler(): mixed
    {
        $current = set_error_handler(static fn (int $errno, string $errstr): bool => false);
        restore_error_handler();

        return $current;
    }

    private static function peekExceptionHandler(): mixed
    {
        $current = set_exception_handler(static function (Throwable $e): void {});
        restore_exception_handler();

        return $current;
    }

    private static function popUntil(callable $peek, callable $pop, mixed $target): void
    {
        // Defensive cap: real leaks are 1-2 deep; 50 is generous and prevents
        // an infinite loop if PHP ever changes restore semantics.
        for ($i = 0; $i < 50; ++$i) {
            $current = $peek();
            if ($current === $target || null === $current) {
                return;
            }
            $pop();
        }
    }
}
