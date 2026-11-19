<?php

declare(strict_types=1);

namespace MoonlyDays\VPK;

use Throwable;

/**
 * The single place that calls warning-prone filesystem functions. Each method turns
 * PHP's warning into a VpkException carrying PHP's own reason.
 *
 * @internal
 */
final class Filesystem
{
    /**
     * @return resource
     *
     * @throws VpkException
     */
    public static function open(string $path, string $mode): mixed
    {
        $handle = self::call(static fn (): mixed => fopen($path, $mode), $error);

        return $handle === false ? throw VpkException::openFailed($path, $error) : $handle;
    }

    /**
     * Create $dir and its parents, unless it already exists.
     *
     * @throws VpkException
     */
    public static function mkdir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        // is_dir() again: another process may have created it in the meantime.
        if (! self::call(static fn (): bool => mkdir($dir, 0777, true), $error) && ! is_dir($dir)) {
            throw VpkException::mkdirFailed($dir, $error);
        }
    }

    /**
     * @throws VpkException
     */
    public static function rename(string $from, string $to): void
    {
        if (! self::call(static fn (): bool => rename($from, $to), $error)) {
            throw VpkException::renameFailed($from, $to, $error);
        }
    }

    /**
     * Best-effort removal, for cleanup paths that are already handling another failure.
     */
    public static function delete(string $file): void
    {
        self::call(static fn (): bool => is_file($file) && unlink($file), $error);
    }

    /**
     * Create $file (and its directory) through a temporary ".part" file that is renamed into
     * place only when $write succeeds, so a failed write never leaves a partial file behind.
     *
     * @param  callable(resource): void  $write
     *
     * @throws VpkException
     */
    public static function writeFile(string $file, callable $write): void
    {
        self::mkdir(dirname($file));

        $temp = $file.'.part';
        $handle = self::open($temp, 'wb');

        try {
            try {
                $write($handle);
            } finally {
                fclose($handle);
            }

            self::rename($temp, $file);
        } catch (Throwable $throwable) {
            self::delete($temp);

            throw $throwable;
        }
    }

    /**
     * Run $fn, capturing (instead of emitting) any PHP warning; its message lands in $error.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private static function call(callable $fn, ?string &$error): mixed
    {
        $error = 'unknown error';
        set_error_handler(static function (int $level, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }
}
