# PHPyh Psalm Tester

Test Psalm via phpt files!

[![Latest Stable Version](https://poser.pugx.org/phpyh/psalm-tester/v/stable.png)](https://packagist.org/packages/phpyh/psalm-tester)
[![Total Downloads](https://poser.pugx.org/phpyh/psalm-tester/downloads.png)](https://packagist.org/packages/phpyh/psalm-tester)
[![psalm-level](https://shepherd.dev/github/phpyh/psalm-tester/level.svg)](https://shepherd.dev/github/phpyh/psalm-tester)
[![type-coverage](https://shepherd.dev/github/phpyh/psalm-tester/coverage.svg)](https://shepherd.dev/github/phpyh/psalm-tester)

## Installation

```shell
composer require --dev phpyh/psalm-tester
```

## Basic usage

### 1. Write a test in phpt format

`tests/array_values.phpt`

```phpt
--FILE--
<?php

/** @psalm-trace $_list */
$_list = array_values(['a' => 1, 'b' => 2]);

--EXPECT--
Trace on line 9: $_list: non-empty-list<1|2>
```

To avoid hardcoding error details, you can use `EXPECTF`:

```phpt
--EXPECTF--
Trace on line %d: $_list: non-empty-list<%s>
```

### 2. Add a test suite

`tests/PsalmTest.php`

```php
<?php

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use PHPyh\PsalmTester\PsalmTester;
use PHPyh\PsalmTester\StaticAnalysisTest;

final class PsalmTest extends TestCase
{
    private ?PsalmTester $psalmTester = null;

    #[TestWith([__DIR__ . '/array_values.phpt'])]
    public function testPhptFiles(string $phptFile): void
    {
        $this->psalmTester ??= PsalmTester::create();
        $this->psalmTester->test(StaticAnalysisTest::fromPhptFile($phptFile));
    }
}
```

## Passing different arguments to Psalm

By default `PsalmTester` runs Psalm with `--no-progress --no-diff --config=`[psalm.xml](src/psalm.xml).

You can change this at the `PsalmTester` level:

```php
use PHPyh\PsalmTester\PsalmTester;

PsalmTester::create(
    defaultArguments: '--no-progress --no-cache --config=my_default_config.xml',
);
```

or for each test individually using `--ARGS--` section:

```phpt
--ARGS--
--no-progress --config=my_special_config.xml
--FILE--
...
--EXPECT--
...
```

## Batch execution

By default, `test()` spawns a separate Psalm process per `.phpt` file.
For plugins with expensive boot costs (e.g., Laravel plugin boots a full application), this means each test pays the full startup overhead.

`runBatch()` groups tests by their argument string and runs **one Psalm invocation per group**,
then distributes results back to individual tests using the `file_path` field in Psalm's JSON output.

```php
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPyh\PsalmTester\PsalmTester;
use PHPyh\PsalmTester\PsalmTest;

final class MyPsalmTest extends TestCase
{
    /** @var array<string, string> */
    private static array $batchResults = [];

    /** @var array<string, PsalmTest> */
    private static array $testData = [];

    public static function setUpBeforeClass(): void
    {
        $tester = PsalmTester::create(
            defaultArguments: '--no-progress --no-diff --config=' . __DIR__ . '/psalm.xml',
        );

        foreach (self::discoverPhptFiles() as $name => $path) {
            self::$testData[$name] = PsalmTest::fromPhptFile($path);
        }

        self::$batchResults = $tester->runBatch(self::$testData);
    }

    #[DataProvider('providePhptFiles')]
    public function testPhptFiles(string $name): void
    {
        Assert::assertThat(
            self::$batchResults[$name],
            self::$testData[$name]->constraint,
        );
    }

    // ... data provider and discovery methods
}
```

> **Important:** Since all files in a batch group are analyzed in a single Psalm run, they share a global symbol table.
> Ensure that class and function names are unique across `.phpt` files within the same argument group,
> otherwise Psalm will report `DuplicateClass` / `DuplicateFunction` errors.

For design details, see [src/parallel.md](src/parallel.md).
