<?php

declare(strict_types=1);

namespace AliesDev\PsalmTester;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestSuite;

/**
 * Runs every *.phpt file under phptDirectory() (recursively) as one data set of testPhpt(), named
 * by its path relative to phptDirectory(). Before the first test, the files PHPUnit will actually
 * run (after --filter and friends) are passed to one PsalmTester::run() call.
 *
 * @api
 */
abstract class PsalmPhptTestCase extends TestCase
{
    private const EMPTY_STATE = ['results' => [], 'errors' => []];

    /**
     * Keyed by concrete class: static properties are shared by every subclass of this base.
     *
     * @var array<string, array{results: array<string, Result>, errors: array<string, \Throwable>}>
     */
    private static array $state = [];

    /**
     * Directory holding the *.phpt files, searched recursively.
     *
     * @psalm-external-mutation-free
     */
    abstract protected static function phptDirectory(): string;

    /**
     * Override to configure the tester (config, arguments, timeout, ...).
     *
     * @psalm-pure
     */
    protected static function tester(): PsalmTester
    {
        return PsalmTester::create();
    }

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$state[static::class] = self::EMPTY_STATE;

        // Unknown selection (e.g. a test run in a separate process): each test prepares itself.
        self::prepare(self::selectedRelPaths() ?? []);
    }

    /**
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function tearDownAfterClass(): void
    {
        unset(self::$state[static::class]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    final public static function phptFiles(): iterable
    {
        foreach (self::discoverPhptFiles() as $relPath) {
            yield $relPath => [$relPath];
        }
    }

    /**
     * @throws \Throwable the error that made this file unusable, if any
     */
    #[DataProvider('phptFiles')]
    final public function testPhpt(string $relPath): void
    {
        $state = self::$state[static::class] ?? self::EMPTY_STATE;

        if (!isset($state['results'][$relPath]) && !isset($state['errors'][$relPath])) {
            self::$state[static::class] = $state;
            self::prepare([$relPath]);
            $state = self::$state[static::class];
        }

        if (isset($state['errors'][$relPath])) {
            throw $state['errors'][$relPath];
        }

        $state['results'][$relPath]->assert();
    }

    /**
     * Parses and runs the given files, merging the outcome into this class's state.
     *
     * @param list<string> $relPaths
     */
    private static function prepare(array $relPaths): void
    {
        $state = self::$state[static::class];
        $directory = self::resolvePhptDirectory();
        $phpts = [];

        foreach ($relPaths as $relPath) {
            try {
                $phpts[$relPath] = Phpt::fromFile($directory . '/' . $relPath);
            } catch (\Throwable $e) {
                // Reported by that file's own test, instead of erroring every test of the class.
                $state['errors'][$relPath] = $e;
            }
        }

        if ($phpts !== []) {
            $state['results'] = static::tester()->run($phpts) + $state['results'];
        }

        self::$state[static::class] = $state;
    }

    /**
     * The data set names of the testPhpt() tests PHPUnit is about to run, or null if unknown.
     *
     * PHPUnit exposes no public API for this, so this reads the class-level TestSuite that invokes
     * setUpBeforeClass() (TestSuite::invokeMethodsBeforeFirstTest() in PHPUnit 11.0 through 13.3):
     * iterating a TestSuite applies the injected --filter/--exclude-filter/--group/test-id filters,
     * and its tests are not yet consumed at that point. If no such suite is on the stack, tests are
     * still correct, just analyzed one Psalm run per test. The internal methods called here
     * (TestSuite::name(), TestCase::name(), TestCase::dataName()) are not guarded: a PHPUnit
     * release that drops one fails loudly instead of silently analyzing the wrong files.
     *
     * @return list<string>|null
     * @psalm-suppress InternalMethod see above: no public API exposes the selection
     */
    private static function selectedRelPaths(): ?array
    {
        foreach (\debug_backtrace(\DEBUG_BACKTRACE_PROVIDE_OBJECT | \DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $suite = $frame['object'] ?? null;

            if ($suite instanceof TestSuite && $suite->name() === static::class) {
                return \array_values(\array_unique(self::collectDataNames($suite)));
            }
        }

        return null;
    }

    /**
     * @return list<string>
     * @psalm-suppress InternalMethod
     */
    private static function collectDataNames(TestSuite $suite): array
    {
        $names = [];

        foreach ($suite as $test) {
            if ($test instanceof TestSuite) {
                \array_push($names, ...self::collectDataNames($test));
            } elseif ($test instanceof static && $test->name() === 'testPhpt') {
                $names[] = (string) $test->dataName();
            }
        }

        return $names;
    }

    /**
     * @return list<string> paths relative to phptDirectory(), always "/"-separated, sorted
     */
    private static function discoverPhptFiles(): array
    {
        $directory = self::resolvePhptDirectory();
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'phpt') {
                $files[] = \str_replace(\DIRECTORY_SEPARATOR, '/', \substr($file->getPathname(), \strlen($directory) + 1));
            }
        }

        \sort($files, \SORT_STRING);

        return $files;
    }

    private static function resolvePhptDirectory(): string
    {
        $directory = \rtrim(static::phptDirectory(), '/\\');

        if (!\is_dir($directory)) {
            throw new \LogicException(\sprintf('%s::phptDirectory() must return an existing directory, got "%s".', static::class, $directory));
        }

        return $directory;
    }
}
