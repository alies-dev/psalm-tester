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
composer require --dev alies-dev/psalm-tester vimeo/psalm
```

Requires PHP 8.2+, Composer 2 and PHPUnit 11, 12 or 13. Psalm is not a dependency of this package: install the
version you test against (6.10+ or 7).

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
to that directory. Run them:

```shell
vendor/bin/phpunit tests/Psalm/PsalmTest.php
vendor/bin/phpunit --filter array_values                  # every data set whose name matches
vendor/bin/phpunit --filter 'testPhpt@array_values.phpt'  # exactly one data set
```

Only the selected tests are analyzed, so `--filter` also makes the Psalm run cheaper.

Do not pass a directory holding fixtures to PHPUnit on the command line (`vendor/bin/phpunit tests/Psalm`): PHPUnit
then also runs every `.phpt` file as its own PHPT test, executing the code instead of analyzing it. A `<directory>` in
`phpunit.xml` is safe, since it collects only `*Test.php` by default.

## Writing fixtures

The expectation is Psalm's output formatted as one `<IssueType> on line <n>: <message>` line per issue, sorted by line
and column. Line numbers count from the top of the `.phpt` file, not from `--FILE--`. An empty expectation means "no
issues".

The default config ([src/psalm.xml](src/psalm.xml)) is strict: `errorLevel="1"`, `findUnusedCode`,
`findUnusedVariablesAndParams`, `reportMixedIssues` and more. That is why the examples name variables `$_list`: a plain
`$list` that is never read adds an `UnusedVariable` issue. Use [your own config](#configuring-the-tester) if that is
too strict.

Assert an exact type without printing it, with [`@psalm-check-type-exact`](https://psalm.dev/docs/annotating_code/supported_annotations/):

```phpt
--FILE--
<?php

$_list = array_values(['a' => 1, 'b' => 2]);
/** @psalm-check-type-exact $_list = non-empty-list<1|2> */
--EXPECT--
```

Use `--EXPECTF--` where part of the output should not be pinned, e.g. a line number that shifts when the fixture is
edited:

```phpt
--FILE--
<?php

echo str_repeat('-', '3');
--EXPECTF--
InvalidScalarArgument on line %d: Argument 2 of str_repeat expects int, but '3' provided
```

Skip a fixture on an environment condition:

```phpt
--SKIPIF--
<?php if (PHP_VERSION_ID < 80400) { echo 'skip requires PHP 8.4'; }
--FILE--
<?php

/** @psalm-trace $_list */
$_list = array_values(['a' => 1, 'b' => 2]);
--EXPECT--
Trace on line 7: $_list: non-empty-list<1|2>
```

Run one fixture with a different Psalm config (a relative path resolves against the working directory):

```phpt
--ARGS--
--config=tests/Psalm/psalm-lenient.xml
--FILE--
<?php

$list = array_values(['a' => 1, 'b' => 2]);
--EXPECT--
```

Here `tests/Psalm/psalm-lenient.xml` sets `findUnusedCode="false"`, so the unused `$list` is not reported.

Document a known wrong result instead of hiding it. The test reports as incomplete while the output mismatches, and
fails once it matches, so the stale `--XFAIL--` gets removed:

```phpt
--XFAIL--
Psalm does not evaluate explode() on literal strings
--FILE--
<?php

/** @psalm-trace $_parts */
$_parts = explode(',', 'a,b');
--EXPECT--
Trace on line 7: $_parts: list{'a', 'b'}
```

All fixtures that share the same arguments are analyzed in one Psalm run and share one symbol table. Keep class and
function names unique across them, otherwise Psalm reports `DuplicateClass` or `DuplicateFunction`.

## Supported phpt sections

The format comes from php-src: [phpt file layout](https://qa.php.net/phpt_details.php),
[writing tests](https://php.github.io/php-src/miscellaneous/writing-tests.html),
[run-tests.php](https://github.com/php/php-src/blob/master/run-tests.php). psalm-tester supports a subset and gives
some sections a Psalm meaning:

| Section | In psalm-tester |
|---|---|
| `--TEST--` | Optional description, ignored. |
| `--FILE--` | Required. Code that Psalm analyzes; it is never executed. |
| `--EXPECT--` | Compared byte for byte with the formatted output. Unlike php-src, neither side is trimmed: a blank line after the last issue is part of the expectation. |
| `--EXPECTF--` | Matched with PHPUnit's [`assertStringMatchesFormat()`](https://docs.phpunit.de/en/11.5/assertions.html#assertstringmatchesformat) placeholders (`%d`, `%s`, `%a`, ...), run by PHPUnit rather than php-src's `run-tests.php`. |
| `--ARGS--` | Psalm CLI arguments (php-src: arguments for the script). See [Psalm arguments](#psalm-arguments). |
| `--SKIPIF--` | PHP script run in its own process, in the tester's working directory and environment. Output starting with `skip` (case insensitive) skips the test with the rest as reason. php-src's `xfail`, `warn` and `info` prefixes are not recognized. |
| `--XFAIL--` | Reason the output is expected to mismatch. Mismatch: PHPUnit incomplete. Match: PHPUnit failure (php-src only warns). |

`--CLEAN--`, `--ENV--` and `--INI--` throw "not supported by psalm-tester". Any other section (`--POST--`,
`--EXTENSIONS--`, `--FILE_EXTERNAL--`, `--EXPECTREGEX--`, ...) throws "Unknown section", and a repeated section throws
"Duplicate section". A malformed file errors only its own test.

### Psalm arguments

Psalm runs without a shell, with these arguments in order:

1. the tester's arguments (default `--no-progress --no-diff`, see `withArguments()`);
2. `--config=<withConfig() path>`, unless step 1 already has a config option;
3. the fixture's `--ARGS--`, split into words like a shell would (quotes and backslashes work, a trailing backslash
   continues the line, nothing is expanded).

A config option in `--ARGS--` (`--config=x`, `--config x`, `-c x`) replaces the one from steps 1 and 2, so Psalm never
sees two. The tester passes the files to analyze itself, so `-f` and anything Psalm would read as a path are rejected:
`withArguments()` throws, and a fixture with such `--ARGS--` (or an unterminated quote) errors only its own test. The
tester also adds `--output-format=json` and `--no-cache`.

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

A plugin author's `tests/Psalm/psalm.xml` needs no `<projectFiles>`, since the tester passes the files:

```xml
<?xml version="1.0"?>
<psalm
    errorLevel="1"
    findUnusedCode="false"
    xmlns="https://getpsalm.org/schema/config"
>
    <plugins>
        <pluginClass class="Psalm\PhpUnitPlugin\Plugin"/>
    </plugins>
</psalm>
```

| Method | Default |
|---|---|
| `withPsalm(string $binary)` | the `vimeo/psalm` binary installed via Composer |
| `withConfig(string $psalmXml)` | the strict [src/psalm.xml](src/psalm.xml) |
| `withArguments(string ...$args)` | `'--no-progress', '--no-diff'`; one argument per parameter, replaces the default |
| `withTimeout(?float $seconds)` | `null`, no timeout; an expired run is killed with its child processes (on Windows, only the Psalm process itself) |
| `withConcurrency(int $n)` | one per CPU core; bounds concurrent SKIPIF scripts and concurrent Psalm runs |
| `withWorkingDirectory(string $dir)` | the current one; relative `--config` paths resolve against it |
| `withEnv(array $env)` | none; extra variables for Psalm and SKIPIF processes (not `XDG_CACHE_HOME`, `TMPDIR`, `TMP`, `TEMP`) |
| `withTemporaryDirectory(string $dir)` | `<system temp dir>/psalm_test` |

## Using PsalmTester directly

`PsalmPhptTestCase` is a thin layer over `PsalmTester::run()`, which takes any iterable of `Phpt` and returns one
`Result` per entry, with the same keys:

```php
<?php

declare(strict_types=1);

use AliesDev\PsalmTester\Expectation;
use AliesDev\PsalmTester\Phpt;
use AliesDev\PsalmTester\PsalmTester;

require __DIR__ . '/vendor/autoload.php';

$results = PsalmTester::create()->run([
    'from file' => Phpt::fromFile(__DIR__ . '/tests/Psalm/phpt/array_values.phpt'),
    'in code' => new Phpt(
        code: "<?php\necho str_repeat('-', '3');",
        expectation: Expectation::exact(''),
    ),
]);

foreach ($results as $name => $result) {
    printf("%s: %s\n%s\n", $name, $result->outcome->name, $result->output);

    foreach ($result->issues as $issue) {
        printf("  %s at %d:%d\n", $issue->type, $issue->line, $issue->column);
    }
}
```

```
from file: Passed
Trace on line 5: $_list: non-empty-list<1|2>
  Trace at 5:1
in code: Failed
InvalidScalarArgument on line 2: Argument 2 of str_repeat expects int, but '3' provided
  InvalidScalarArgument at 2:22
```

`runOne(Phpt $phpt)` runs a single test. `$result->reason` explains a skip or an error, and `$result->assert()` reports
the result to PHPUnit:

| `Outcome` | `assert()` |
|---|---|
| `Passed`, `Failed` | assertion on the output, with a diff on failure |
| `Skipped` | `markTestSkipped()` with the SKIPIF reason |
| `XFailed` | `markTestIncomplete()` with the `--XFAIL--` reason |
| `XPassed` | failure asking to remove `--XFAIL--` |
| `Error` | failure with the reason |

## How tests run

- `PsalmPhptTestCase` hands every selected fixture to one `run()` call before the first test. It reads the selection
  from the running PHPUnit suite; when it cannot (e.g. a test in a separate process), each test runs on its own, with
  the same results.
- SKIPIF scripts run first, concurrently. Only their output decides, as in php-src.
- The remaining fixtures are analyzed with one Psalm run per distinct argument set, so an expensive plugin boot is paid
  once per set. Up to `withConcurrency()` runs go at once.
- Each run gets `--no-cache` and its own empty cache directory (`XDG_CACHE_HOME`, `TMPDIR`, `TMP`, `TEMP`).
- A run that crashes, exits with a status other than 0 or 2, prints something other than Psalm's JSON issue list,
  reports issues in other files, or exceeds `withTimeout()` gives `Outcome::Error` to each of its tests. It never
  passes. Other runs are unaffected.
- `run()` throws only when the tester itself fails (e.g. it cannot write a temporary file), after killing the runs
  still going.
