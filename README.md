# Psalm Tester

[![Latest Stable Version](https://poser.pugx.org/alies-dev/psalm-tester/v/stable.png)](https://packagist.org/packages/alies-dev/psalm-tester)
[![Total Downloads](https://poser.pugx.org/alies-dev/psalm-tester/downloads.png)](https://packagist.org/packages/alies-dev/psalm-tester)
[![psalm-level](https://shepherd.dev/github/alies-dev/psalm-tester/level.svg)](https://shepherd.dev/github/alies-dev/psalm-tester)
[![type-coverage](https://shepherd.dev/github/alies-dev/psalm-tester/coverage.svg)](https://shepherd.dev/github/alies-dev/psalm-tester)

Regression tests for what Psalm reports, written as `.phpt` files and run by PHPUnit.
Each file holds a PHP snippet and the exact issues Psalm must report for it, type assertions included.
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

$_list = array_values(['a' => 1, 'b' => 2]);
/** @psalm-check-type-exact $_list = non-empty-list<1|2> */
--EXPECT--
```

The empty `--EXPECT--` means "Psalm reports no issues", so the test passes exactly when the inferred type matches.

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

Had the tag said `list<int>`, the test would fail with:

```
Failed asserting that two strings are identical.
--- Expected
+++ Actual
@@ @@
-''
+'CheckType on line 5: Checked variable $_list = list<int> does not match $_list = non-empty-list<1|2>'
```

## Writing fixtures

The expectation is one `<IssueType> on line <n>: <message>` line per issue, sorted by line and column, with line
numbers counted from the top of the `.phpt` file. The default config ([src/psalm.xml](src/psalm.xml)) is strict
(`errorLevel="1"`, `findUnusedCode`, ...), hence `$_list`: an unread `$list` would add an `UnusedVariable` issue.

### Writing type assertions

[`@psalm-check-type-exact`](https://psalm.dev/docs/annotating_code/supported_annotations/) compares types
semantically: `1|2` equals `2|1`, but `list<int>` differs from `non-empty-list<int>`. Unlike a traced type, it does
not depend on how a Psalm version prints types.

- Put the tag on its own line right after the statement that sets the variable, at top level or inside a function
  body. On a class or method docblock it is silently ignored.
- The variable must exist at that point, or Psalm reports `InvalidDocblock`.
- To find the type, add `/** @psalm-trace $x */` temporarily, copy the traced type into the tag, remove the trace.
- Plain `@psalm-check-type` only checks that the actual type is contained in the given one.

### Other expectations

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

$_parts = explode(',', 'a,b');
/** @psalm-check-type-exact $_parts = list{'a', 'b'} */
--EXPECT--
```

It reports as incomplete while the output mismatches, and fails once it matches, so a stale `--XFAIL--` gets removed.

Fixtures with the same arguments share one Psalm run and symbol table, so keep class and function names unique.

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
