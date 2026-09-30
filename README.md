# Psalm Tester

Test Psalm via phpt files!

[![Latest Stable Version](https://poser.pugx.org/alies-dev/psalm-tester/v/stable.png)](https://packagist.org/packages/alies-dev/psalm-tester)
[![Total Downloads](https://poser.pugx.org/alies-dev/psalm-tester/downloads.png)](https://packagist.org/packages/alies-dev/psalm-tester)
[![psalm-level](https://shepherd.dev/github/alies-dev/psalm-tester/level.svg)](https://shepherd.dev/github/alies-dev/psalm-tester)
[![type-coverage](https://shepherd.dev/github/alies-dev/psalm-tester/coverage.svg)](https://shepherd.dev/github/alies-dev/psalm-tester)

## Installation

```shell
composer require --dev alies-dev/psalm-tester
```

## Quick start

### 1. Write a test in phpt format

`tests/Psalm/phpt/array_values.phpt`

```phpt
--FILE--
<?php

/** @psalm-trace $_list */
$_list = array_values(['a' => 1, 'b' => 2]);

--EXPECT--
Trace on line 5: $_list: non-empty-list<1|2>
```

To avoid hardcoding error details, use `--EXPECTF--` and its format placeholders (`%s`, `%d`, ...):

```phpt
--EXPECTF--
Trace on line %d: $_list: non-empty-list<%s>
```

Lines are counted in the `.phpt` file, so an error on the first line of the `--FILE--` section above is reported as
line 2.

### 2. Add a test case

`tests/Psalm/PsalmTest.php`

```php
<?php

use AliesDev\PsalmTester\PsalmPhptTestCase;
use AliesDev\PsalmTester\PsalmTester;

final class PsalmTest extends PsalmPhptTestCase
{
    protected static function phptDirectory(): string { return __DIR__ . '/phpt'; }
    protected static function tester(): PsalmTester { return PsalmTester::create()->withConfig(__DIR__ . '/psalm.xml'); }
}
```

Every `*.phpt` file under `phptDirectory()` (recursively) becomes one data set of `testPhpt`, named by its path
relative to that directory (e.g. `sub/array_values.phpt`), in sorted order. `tester()` is optional; the default is
`PsalmTester::create()`.

Before the first test runs, all selected files are handed to one `PsalmTester::run()` call: their `--SKIPIF--` scripts
are evaluated concurrently and the remaining files are analyzed together (see [How tests run](#how-tests-run)). A
skipped file is reported via `markTestSkipped()` with its SKIPIF reason; a malformed file errors only its own test.

Only the tests PHPUnit will run are analyzed, so `--filter` (and `--exclude-filter`, `--group`, ...) keeps a run cheap:

```shell
vendor/bin/phpunit --filter 'array_values'                  # any data set whose name matches
vendor/bin/phpunit --filter 'testPhpt@sub/array_values.phpt' # exactly one data set
```

PHPUnit has no public API for the selected tests, so `PsalmPhptTestCase` reads them from the running test suite. If that
is not possible (e.g. a test runs in a separate process), results stay correct, but each test is analyzed in its own
Psalm run.

## The phpt format

| Section | Meaning |
|---|---|
| `--FILE--` | Required. The code Psalm analyzes. |
| `--EXPECT--` | Psalm's output must be identical to this, one `<IssueType> on line <n>: <message>` line per issue. |
| `--EXPECTF--` | Like `--EXPECT--`, with the format placeholders of PHPUnit's `assertStringMatchesFormat()`. |
| `--EXPECT_EXTERNAL--`, `--EXPECTF_EXTERNAL--` | The path of a file holding the expectation. |
| `--ARGS--` | Extra Psalm arguments for this test (see below). |
| `--SKIPIF--` | A PHP script; if its output starts with `skip`, the test is skipped with the rest of that output as reason. |

Any other section throws. `--CLEAN--`, `--ENV--` and `--INI--` from PHP's own phpt format are rejected with an explicit
"not supported" message rather than silently ignored.

The SKIPIF script runs in its own PHP process (so `exit()` or `die()` in it cannot end the test run), in the tester's
working directory and environment:

```phpt
--SKIPIF--
<?php if (PHP_VERSION_ID < 80400) { echo 'skip requires PHP 8.4+'; }
```

### Psalm arguments

Every Psalm run gets the tester's arguments (default `--no-progress --no-diff`), then `--config=<the configured
psalm.xml>`, then the test's `--ARGS--`. If `--ARGS--` contains its own `--config`, it replaces the configured one:

```phpt
--ARGS--
--config=tests/Psalm/psalm-strict.xml --taint-analysis
--FILE--
...
```

## Configuring the tester

`PsalmTester::create()` takes no parameters; each `with*()` method returns a configured copy.

| Method | Default |
|---|---|
| `withPsalm(string $binary)` | the `vimeo/psalm` binary installed via Composer |
| `withConfig(string $psalmXml)` | the minimal [psalm.xml](src/psalm.xml) shipped with this package |
| `withArguments(string $args)` | `--no-progress --no-diff` |
| `withTimeout(?float $seconds)` | `null` (no timeout) |
| `withConcurrency(int $n)` | one per CPU core; bounds SKIPIF scripts and Psalm runs |
| `withWorkingDirectory(string $dir)` | the current one; relative `--config` paths resolve against it |
| `withEnv(array $env)` | none; extra variables for Psalm and SKIPIF processes |
| `withProgress(bool $on)` | `true`: one `<arguments>: <n> tests` line per Psalm run on STDERR |
| `withTemporaryDirectory(string $dir)` | `<system temp dir>/psalm_test` |

## Using the tester directly

`PsalmPhptTestCase` is a thin layer over `PsalmTester::run()`, which takes any iterable of `Phpt` and returns one
`Result` per test, with the same keys and order:

```php
use AliesDev\PsalmTester\Outcome;
use AliesDev\PsalmTester\Phpt;
use AliesDev\PsalmTester\PsalmTester;

$results = PsalmTester::create()->run([
    'values' => Phpt::fromFile(__DIR__ . '/array_values.phpt'),
]);

$result = $results['values'];
$result->outcome;  // Outcome::Passed, Failed, Skipped or Error
$result->output;   // "Trace on line 5: $_list: non-empty-list<1|2>"
$result->issues;   // list<Issue>, each with type, line, column and message
$result->reason;   // why it was skipped or errored, else null
$result->assert(); // report it to PHPUnit: assertion with diff, skip, or failure
```

`runOne(Phpt $phpt): Result` runs a single test the same way. `new Phpt(code: ..., expectation: Expectation::exact(...))`
builds a test in code instead of from a file.

`Outcome::XFailed`, `Outcome::XPassed`, `Outcome::Updated` and `Phpt::$xfail` are reserved for upcoming `--XFAIL--`
and update mode support; `run()` does not produce them yet.

## How tests run

`run()` evaluates all SKIPIF scripts first, then analyzes the remaining tests with **one Psalm run per distinct argument
set** instead of one per file, so a plugin with an expensive boot (e.g. one that boots a Laravel application) pays it
once per argument set. Up to `withConcurrency()` Psalm runs go at once; the rest wait for a free slot. If Psalm's output
cannot be decoded, `run()` throws right away and kills the Psalm runs still going.

> **Important:** all files of one argument set are analyzed in a single Psalm run, so they share a global symbol table.
> Keep class and function names unique across `.phpt` files with the same arguments, otherwise Psalm reports
> `DuplicateClass` / `DuplicateFunction` errors.

Each Psalm run gets its own empty cache directory (`XDG_CACHE_HOME`, `TMPDIR`, `TMP` and `TEMP` point at it) and
`--no-cache` unless its arguments already contain it: that cache would be thrown away after the run, and writing it
roughly doubled the wall time of a 700 file suite.

With `withTimeout($seconds)`, a Psalm run still going `$seconds` after it started (time spent waiting for a free slot
does not count) is killed together with its child processes, and each of its tests gets `Outcome::Error` naming the
arguments and the timeout. Other runs are unaffected.

## Upgrading from 0.3

| 0.3 | 0.4 |
|---|---|
| `PsalmTest` | `Phpt` |
| `PsalmTest::fromPhptFile($file)` | `Phpt::fromFile($file)` |
| `new PsalmTest($code, $constraint, $arguments, $codeFirstLine)` | `new Phpt($code, $expectation, $arguments, $codeFirstLine)`, with `Expectation::exact()` / `Expectation::format()` instead of a PHPUnit constraint |
| `PsalmTest::$constraint` | `Phpt::$expectation`, a value object; `$expectation->constraint()` builds the constraint |
| `PsalmTest::getSkipReason($file)` | removed: `run()` evaluates `--SKIPIF--` (concurrently) and reports `Outcome::Skipped` with the reason |
| `PsalmTester::create($psalmPath, $defaultArguments, $temporaryDirectory, $showProgress)` | `PsalmTester::create()` plus `withPsalm()`, `withArguments()` / `withConfig()`, `withTemporaryDirectory()`, `withProgress()` |
| `defaultArguments` including `--config=...` | `withConfig()` for the config, `withArguments()` for the rest |
| `--ARGS--` replaced the default arguments | `--ARGS--` is appended to the configured arguments; its `--config` replaces the configured config. Files repeating the full defaults keep working. |
| `$tester->runBatch($tests)` returning output strings | `$tester->run($phpts)` returning `Result` objects (`$result->output` is the old string) |
| `$tester->test($test)` | `$tester->runOne($phpt)->assert()` |
| a hand-written `TestCase` with discovery, a data provider and `runBatch()` | `PsalmPhptTestCase` (see [Quick start](#quick-start)) |
| unknown sections threw `Section X is not supported.` | still throw, naming the file; `--CLEAN--`, `--ENV--`, `--INI--` get a "not supported by psalm-tester" message |
