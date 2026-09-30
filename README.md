# Psalm Tester

[![Latest Stable Version](https://poser.pugx.org/alies-dev/psalm-tester/v/stable.png)](https://packagist.org/packages/alies-dev/psalm-tester)
[![Total Downloads](https://poser.pugx.org/alies-dev/psalm-tester/downloads.png)](https://packagist.org/packages/alies-dev/psalm-tester)
[![psalm-level](https://shepherd.dev/github/alies-dev/psalm-tester/level.svg)](https://shepherd.dev/github/alies-dev/psalm-tester)
[![type-coverage](https://shepherd.dev/github/alies-dev/psalm-tester/coverage.svg)](https://shepherd.dev/github/alies-dev/psalm-tester)

Regression tests for what Psalm reports, written as `.phpt` files and run by PHPUnit.
Each file holds a PHP snippet and the exact issues (or traced types) Psalm must report for it.
It is built for authors of Psalm plugins and stubs, who need to pin inferred types and issues
without hand-writing a PHPUnit test and a Psalm invocation per case.

Changes: [CHANGELOG.md](CHANGELOG.md). Upgrading from 0.3: [UPGRADING.md](UPGRADING.md).

## Quick start

```shell
composer require --dev alies-dev/psalm-tester
```

Requires PHP 8.2+ and PHPUnit 11, 12 or 13; installs Psalm 6.10+ or 7.

A fixture, `tests/Psalm/phpt/array_values.phpt`:

```phpt
--FILE--
<?php

/** @psalm-trace $_list */
$_list = array_values(['a' => 1, 'b' => 2]);
--EXPECT--
Trace on line 5: $_list: non-empty-list<1|2>
```

A test case, `tests/Psalm/PsalmTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Psalm;

use AliesDev\PsalmTester\PsalmPhptTestCase;

final class PsalmTest extends PsalmPhptTestCase
{
    protected static function phptDirectory(): string
    {
        return __DIR__ . '/phpt';
    }
}
```

Every `*.phpt` file under `phptDirectory()` (recursively) becomes one data set of `testPhpt`, named by its path relative
to that directory. Only the selected data sets are analyzed, so `--filter` also makes the Psalm run cheaper:

```shell
vendor/bin/phpunit tests/Psalm/PsalmTest.php
vendor/bin/phpunit --filter array_values                  # every data set whose name matches
vendor/bin/phpunit --filter 'testPhpt@array_values.phpt'  # exactly one data set
```

Do not pass a directory holding fixtures on the command line (`vendor/bin/phpunit tests/Psalm`): PHPUnit then also
runs every `.phpt` file as its own PHPT test, executing the code instead of analyzing it. A `<directory>` in
`phpunit.xml` is safe, since it collects only `*Test.php` by default.

## Writing fixtures

The expectation is one `<IssueType> on line <n>: <message>` line per issue, sorted by line and column. Line numbers
count from the top of the `.phpt` file. An empty expectation means "no issues".

The default config ([src/psalm.xml](src/psalm.xml)) is strict (`errorLevel="1"`, `findUnusedCode`,
`reportMixedIssues`, ...). That is why the examples name variables `$_list`: a plain `$list` that is never read adds
an `UnusedVariable` issue.

Assert a type without printing it, with [`@psalm-check-type-exact`](https://psalm.dev/docs/annotating_code/supported_annotations/):

```phpt
--FILE--
<?php

$_list = array_values(['a' => 1, 'b' => 2]);
/** @psalm-check-type-exact $_list = non-empty-list<1|2> */
--EXPECT--
```

Leave out what should not be pinned, such as a line number that shifts when the fixture is edited:

```phpt
--FILE--
<?php

echo str_repeat('-', '3');
--EXPECTF--
InvalidScalarArgument on line %d: Argument 2 of str_repeat expects int, but '3' provided
```

Skip on an environment condition, pass extra Psalm arguments, and document a known wrong result:

```phpt
--SKIPIF--
<?php if (PHP_VERSION_ID < 80400) { echo 'skip requires PHP 8.4'; }
--ARGS--
--config=tests/Psalm/psalm-lenient.xml
--XFAIL--
Psalm does not evaluate explode() on literal strings
--FILE--
<?php

/** @psalm-trace $_parts */
$_parts = explode(',', 'a,b');
--EXPECT--
Trace on line 11: $_parts: list{'a', 'b'}
```

It reports as incomplete while the output mismatches, and fails once it matches, so a stale `--XFAIL--` gets removed.

Fixtures with the same arguments are analyzed in one Psalm run and share one symbol table: keep class and function
names unique across them, or Psalm reports `DuplicateClass` or `DuplicateFunction`.

## Supported phpt sections

The format comes from php-src ([phpt file layout](https://qa.php.net/phpt_details.php),
[writing tests](https://php.github.io/php-src/miscellaneous/writing-tests.html),
[run-tests.php](https://github.com/php/php-src/blob/master/run-tests.php)). psalm-tester supports a subset, with
Psalm semantics:

| Section | In psalm-tester |
|---|---|
| `--TEST--` | Optional description, ignored. |
| `--FILE--` | Required. Code that Psalm analyzes; it is never executed. |
| `--EXPECT--` | Compared byte for byte with the output. Unlike php-src, neither side is trimmed. |
| `--EXPECTF--` | Matched with PHPUnit's [`assertStringMatchesFormat()`](https://docs.phpunit.de/en/11.5/assertions.html#assertstringmatchesformat) (`%d`, `%s`, `%a`, ...). |
| `--ARGS--` | Psalm CLI arguments (php-src: script arguments), appended to the tester's. See below. |
| `--SKIPIF--` | PHP script run in its own process. Output starting with `skip` (case insensitive) skips the test, the rest being the reason. php-src's `xfail`, `warn` and `info` prefixes are not recognized. |
| `--XFAIL--` | Why the output is expected to mismatch. Mismatch: PHPUnit incomplete. Match: PHPUnit failure (php-src only warns). |
| `--EXPECT_EXTERNAL--`, `--EXPECTF_EXTERNAL--`, `--CLEAN--`, `--ENV--`, `--INI--` | Rejected as "not supported by psalm-tester". |

Any other section throws "Unknown section", and a repeated one "Duplicate section"; either errors only that test.

`--ARGS--` is split into words like a shell would (quotes and backslashes work, nothing is expanded) and appended to
the tester's arguments. A config option in it (`--config=x`, `--config x`, `-c x`) replaces the configured one.
The tester passes the files itself, so `-f` and paths are rejected.

## Configuring the tester

Override `tester()` in the test case (importing `AliesDev\PsalmTester\PsalmTester`). Every `with*()` method returns
a configured copy:

```php
protected static function tester(): PsalmTester
{
    return PsalmTester::create()
        ->withConfig(__DIR__ . '/psalm.xml')
        ->withTimeout(120.0);
}
```

A plugin's `tests/Psalm/psalm.xml` needs no `<projectFiles>`:

```xml
<?xml version="1.0"?>
<psalm errorLevel="1" findUnusedCode="false" xmlns="https://getpsalm.org/schema/config">
    <plugins>
        <pluginClass class="Psalm\PhpUnitPlugin\Plugin"/>
    </plugins>
</psalm>
```

| Method | Default |
|---|---|
| `withPsalm(string $binary)` | the installed `vimeo/psalm` binary |
| `withConfig(string $psalmXml)` | the strict [src/psalm.xml](src/psalm.xml) |
| `withArguments(string ...$args)` | `'--no-progress', '--no-diff'`; one argument per parameter, no shell |
| `withTimeout(?float $seconds)` | no timeout; an expired run is killed with its child processes (on Windows, only the Psalm process) |
| `withConcurrency(int $n)` | one per CPU core; bounds concurrent SKIPIF scripts and Psalm runs |
| `withWorkingDirectory(string $dir)` | the current one; relative `--config` paths resolve against it |
| `withEnv(array $env)` | none; extra variables for Psalm and SKIPIF processes |
| `withTemporaryDirectory(string $dir)` | `<system temp dir>/psalm_test` |

## Using PsalmTester directly

`PsalmPhptTestCase` is a thin layer over `PsalmTester::run()`, which takes an iterable of `Phpt` and returns a `Result`
per key. `runOne()` runs a single one:

```php
<?php

declare(strict_types=1);

use AliesDev\PsalmTester\Expectation;
use AliesDev\PsalmTester\Phpt;
use AliesDev\PsalmTester\PsalmTester;

require __DIR__ . '/vendor/autoload.php';

$result = PsalmTester::create()->runOne(new Phpt(
    code: "<?php\necho str_repeat('-', '3');",
    expectation: Expectation::exact(''),
));

echo $result->outcome->name, "\n", $result->output, "\n";
```

```
Failed
InvalidScalarArgument on line 2: Argument 2 of str_repeat expects int, but '3' provided
```

`Outcome` is `Passed`, `Failed`, `Skipped`, `XFailed`, `XPassed` or `Error`; `$result->reason` explains the last
four, `$result->issues` holds each issue's type, line, column and message, and `$result->assert()` reports the result
to PHPUnit. `Phpt::fromFile()` loads a fixture.

## How tests run

- SKIPIF scripts run first, concurrently. The rest is analyzed with one Psalm run (with `--no-cache`) per distinct
  argument set, so an expensive plugin boot is paid once per set; up to `withConcurrency()` runs go at once.
- A run that crashes, exits with a status other than 0 or 2, prints something other than Psalm's JSON issue list,
  reports issues in other files, or times out gives `Outcome::Error` to each of its tests. It never passes.
