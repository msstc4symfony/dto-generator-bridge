<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Support;

use Closure;
use FilesystemIterator;
use RuntimeException;
use SplFileInfo;
use UnexpectedValueException;

/**
 * Gives each PHPUnit process its own temporary directory, so the lock files and scratch trees the suite creates
 * (Infection starts a process per mutant) never pile up in the shared /tmp.
 */
final class TestRunTemporaryDirectory
{
    /**
     * A run that has not cleaned up after this long crashed or was killed. Age is the mtime of the run directory,
     * which the suite touches all the time by creating entries in it.
     */
    private const STALE_AFTER_SECONDS = 3600;

    /**
     * Must run before anything calls sys_get_temp_dir(): PHP resolves the directory once per process.
     */
    public static function isolate(string $base): void
    {
        $previous = getenv('TMPDIR');
        self::createShared($base);
        $run = self::create($base . '/' . self::owner(), time());
        putenv('TMPDIR=' . $run);
        if (sys_get_temp_dir() !== $run) {
            putenv($previous === false ? 'TMPDIR' : 'TMPDIR=' . $previous);
            self::remove($run);

            throw new RuntimeException(sprintf('sys_get_temp_dir() stays %s: it was resolved before the test bootstrap, or the sys_temp_dir ini setting overrides TMPDIR.', sys_get_temp_dir()));
        }

        $pid = getmypid();
        register_shutdown_function(static function () use ($run, $pid): void {
            // A forked child shares the directory with its parent; only the parent cleans it up.
            if (getmypid() === $pid) {
                self::remove($run);
            }
        });
    }

    /**
     * A container running as root must not leave directories the developer's own runs cannot write to or sweep.
     */
    public static function owner(): string
    {
        return function_exists('posix_geteuid') ? 'u' . posix_geteuid() : 'shared';
    }

    public static function create(string $base, int $now): string
    {
        // Parallel processes race to create the base; the loser's warning means nothing, any other one is the cause.
        $error = self::quietly(static fn (): bool => is_dir($base) || mkdir($base, 0777, true));
        $real = realpath($base);
        if ($real === false || !is_dir($real)) {
            $hint = $error !== null && strpos($error, 'Permission denied') !== false ? '; a parent owned by another user (a container run as root) is the usual cause' : '';

            throw new RuntimeException(sprintf('Cannot create %s: %s%s.', $base, $error ?? 'unknown error', $hint));
        }

        if (!is_writable($real)) {
            throw new RuntimeException($real . ' is not writable; remove it or run the tests as its owner.');
        }

        // Tests compare paths built from the base verbatim, so "tests/../var/tmp" must not leak into them.
        self::sweepStale($real, $now);
        $run = $real . '/run-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!mkdir($run, 0700)) {
            throw new RuntimeException('Cannot create ' . $run . '.');
        }

        return $run;
    }

    /**
     * Sticky and world-writable like /tmp, so whichever user creates it first, every user can keep a directory in it.
     */
    private static function createShared(string $directory): void
    {
        self::quietly(static fn (): bool => is_dir($directory) || (mkdir($directory, 0777, true) && chmod($directory, 01777)));
    }

    public static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            // Tests make files and directories read-only on purpose; the owner can still lift that.
            chmod($path, 0700);
            foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
                if ($entry instanceof SplFileInfo) {
                    self::remove($entry->getPathname());
                }
            }

            rmdir($path);

            return;
        }

        // A dangling symlink does not exist for file_exists().
        if (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    /**
     * Parallel processes (Infection starts several at once) sweep the same stale runs; whatever another one
     * removed first is no concern of this one.
     */
    private static function sweepStale(string $base, int $now): void
    {
        // Not glob(): the project path may contain its metacharacters.
        foreach (new FilesystemIterator($base, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry instanceof SplFileInfo || strncmp($entry->getFilename(), 'run-', 4) !== 0) {
                continue;
            }

            $run = $entry->getPathname();
            self::quietly(static function () use ($run, $now): void {
                $modified = filemtime($run);
                if ($modified !== false && $modified < $now - self::STALE_AFTER_SECONDS) {
                    self::remove($run);
                }
            });
        }
    }

    /**
     * @param Closure(): (bool|void) $action
     *
     * @return string|null the last warning the action raised
     */
    private static function quietly(Closure $action): ?string
    {
        $last = null;
        set_error_handler(static function (int $level, string $message) use (&$last): bool {
            $last = $message;

            return true;
        });

        try {
            $action();
        } catch (UnexpectedValueException $exception) {
            // A directory vanished while being walked.
        } finally {
            restore_error_handler();
        }

        return $last;
    }
}
