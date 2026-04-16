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

## Skipping tests conditionally

Add a `--SKIPIF--` section containing a PHP script that echoes a message starting with `skip` when the test should not run:

```phpt
--SKIPIF--
<?php if (PHP_VERSION_ID < 80200) { echo 'skip requires PHP 8.2+'; }
--FILE--
<?php
...
--EXPECT--
...
```

In your test suite, call `PsalmTest::getSkipReason()` before loading the test and pass the result to PHPUnit's `markTestSkipped()`:

```php
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use PHPyh\PsalmTester\PsalmTester;
use PHPyh\PsalmTester\PsalmTest;

final class PsalmTest extends TestCase
{
    private ?PsalmTester $psalmTester = null;

    #[TestWith([__DIR__ . '/array_values.phpt'])]
    public function testPhptFiles(string $phptFile): void
    {
        $skipReason = PsalmTest::getSkipReason($phptFile);

        if ($skipReason !== null) {
            $this->markTestSkipped($skipReason);
        }

        $this->psalmTester ??= PsalmTester::create();
        $this->psalmTester->test(PsalmTest::fromPhptFile($phptFile));
    }
}
```

The SKIPIF script runs in a separate PHP process, so `exit()`/`die()` calls in the script do not affect the test run. `getSkipReason()` returns the reason string with the leading `skip` token stripped (e.g. `"requires PHP 8.2+"`) or `null` if the test should run.
