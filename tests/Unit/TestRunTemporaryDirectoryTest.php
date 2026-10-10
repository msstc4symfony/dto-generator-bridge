<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit;

use Msstc4Symfony\DtoGeneratorBridge\Test\Support\TestRunTemporaryDirectory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TestRunTemporaryDirectoryTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/runs-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        TestRunTemporaryDirectory::remove($this->base);
    }

    public function testTheSuiteRunsInsideItsOwnDirectoryUnderVar(): void
    {
        $base = realpath(dirname(__DIR__, 2) . '/var/tmp') . '/' . TestRunTemporaryDirectory::owner();

        self::assertSame($base, dirname(sys_get_temp_dir()));
        self::assertStringStartsWith('run-' . getmypid() . '-', basename(sys_get_temp_dir()));
    }

    public function testCreatesAFreshDirectoryPerRun(): void
    {
        $first = TestRunTemporaryDirectory::create($this->base, time());
        $second = TestRunTemporaryDirectory::create($this->base, time());

        self::assertDirectoryExists($first);
        self::assertDirectoryExists($second);
        self::assertNotSame($first, $second);
        self::assertSame($this->base, dirname($first));
    }

    public function testRemovesRunsLeftBehindForMoreThanAnHour(): void
    {
        $now = time();
        $stale = $this->base . '/run-1-stale';
        $recent = $this->base . '/run-2-recent';
        $foreign = $this->base . '/keep';
        foreach ([$stale . '/nested', $recent, $foreign] as $directory) {
            mkdir($directory, 0777, true);
        }

        touch($stale . '/nested/file.lock');
        touch($stale, $now - 3601);
        // A mutant of the lock path can drop the separator and leave a file next to the runs.
        $strayFile = $this->base . '/run-3-strayfile.lock';
        touch($strayFile, $now - 3601);
        touch($recent, $now - 3599);
        touch($foreign, $now - 7200);

        TestRunTemporaryDirectory::create($this->base, $now);

        self::assertDirectoryDoesNotExist($stale);
        self::assertFileDoesNotExist($strayFile);
        self::assertDirectoryExists($recent);
        self::assertDirectoryExists($foreign);
    }

    public function testRemoveDeletesTheTreeIncludingReadOnlyFilesAndSymlinks(): void
    {
        $run = TestRunTemporaryDirectory::create($this->base, time());
        mkdir($run . '/a/b', 0777, true);
        file_put_contents($run . '/a/b/file', 'x');
        chmod($run . '/a/b/file', 0444);
        chmod($run . '/a/b', 0555);
        $outside = $this->base . '/outside';
        mkdir($outside);
        symlink($outside, $run . '/link');

        TestRunTemporaryDirectory::remove($run);

        self::assertFileDoesNotExist($run);
        self::assertDirectoryExists($outside);
    }

    public function testNamesTheOwnerByEffectiveUser(): void
    {
        self::assertMatchesRegularExpression('/^(?:u\\d+|shared)\z/', TestRunTemporaryDirectory::owner());
    }

    public function testAnIsolatedProcessRemovesItsDirectoryOnExit(): void
    {
        $code = sprintf(
            'require %s; %s::isolate(%s); touch(sys_get_temp_dir() . "/left.lock"); echo sys_get_temp_dir();',
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            TestRunTemporaryDirectory::class,
            var_export($this->base, true),
        );
        $errors = sys_get_temp_dir() . '/child-' . bin2hex(random_bytes(4)) . '.err';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']], $pipes);
        self::assertIsResource($process);
        $run = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        $stderr = (string) file_get_contents($errors);
        unlink($errors);

        self::assertSame(0, $status, $stderr);
        self::assertNotSame('', $run, $stderr);
        self::assertSame(realpath($this->base) . '/' . TestRunTemporaryDirectory::owner(), dirname($run));
        self::assertDirectoryDoesNotExist($run);
        // Shared like /tmp, so a run as root first leaves a base every user can still create a directory in.
        self::assertSame(01777, fileperms($this->base) & 07777);
    }

    public function testExplainsWhyTheBaseCannotBeCreated(): void
    {
        mkdir($this->base);
        chmod($this->base, 0555);
        if (is_writable($this->base)) {
            chmod($this->base, 0700);
            self::markTestSkipped('Permissions are not enforced for this user.');
        }

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Permission denied; a parent owned by another user');

            TestRunTemporaryDirectory::create($this->base . '/u1', time());
        } finally {
            chmod($this->base, 0700);
        }
    }

    public function testRefusesABaseItCannotWriteTo(): void
    {
        mkdir($this->base);
        chmod($this->base, 0555);
        if (is_writable($this->base)) {
            chmod($this->base, 0700);
            self::markTestSkipped('Permissions are not enforced for this user.');
        }

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('is not writable');

            TestRunTemporaryDirectory::create($this->base, time());
        } finally {
            chmod($this->base, 0700);
        }
    }

    public function testRemoveDeletesEntriesThatAreNeitherFilesNorDirectories(): void
    {
        if (!function_exists('posix_mkfifo')) {
            self::markTestSkipped('posix_mkfifo() is unavailable.');
        }

        $run = TestRunTemporaryDirectory::create($this->base, time());
        posix_mkfifo($run . '/pipe', 0600);

        TestRunTemporaryDirectory::remove($run);

        self::assertFileDoesNotExist($run);
    }

    public function testRemoveIgnoresAMissingDirectory(): void
    {
        TestRunTemporaryDirectory::remove($this->base . '/missing');

        self::assertDirectoryDoesNotExist($this->base . '/missing');
    }

    public function testIsolateFailsWhenTheTemporaryDirectoryWasAlreadyResolved(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sys_temp_dir');

        TestRunTemporaryDirectory::isolate($this->base);
    }
}
