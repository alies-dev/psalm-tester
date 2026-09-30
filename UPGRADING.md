# Upgrading

## From 0.3 to 0.4

See [CHANGELOG.md](CHANGELOG.md) for the full list of changes.

| 0.3 | 0.4 |
|---|---|
| `vimeo/psalm` required by your project | required by psalm-tester (`^6.10 \|\| ^7.0.0-beta16`) |
| `PsalmTest` | `Phpt` |
| `PsalmTest::fromPhptFile($file)` | `Phpt::fromFile($file)` |
| `new PsalmTest($code, $constraint, $arguments, $codeFirstLine)` | `new Phpt($code, $expectation, $arguments, $codeFirstLine)`, with `Expectation::exact()` / `Expectation::format()` instead of a PHPUnit constraint |
| `PsalmTest::$constraint` | `Phpt::$expectation`, a value object; `$expectation->constraint()` builds the constraint |
| `PsalmTest::getSkipReason($file)` | removed: `run()` evaluates `--SKIPIF--` (concurrently) and reports `Outcome::Skipped` with the reason |
| `PsalmTester::create($psalmPath, $defaultArguments, $temporaryDirectory, $showProgress)` | `PsalmTester::create()` plus `withPsalm()`, `withArguments()` / `withConfig()`, `withTemporaryDirectory()`; `$showProgress` is gone (no progress output) |
| `defaultArguments` including `--config=...` | `withConfig()` for the config and `withArguments()` for the rest, or keep `--config=...` in `withArguments()` |
| `--ARGS--` replaced the default arguments | `--ARGS--` is appended to the configured arguments; its `--config` replaces the configured config. Files repeating the full defaults keep working. |
| `$tester->runBatch($tests)` returning output strings, throwing on undecodable Psalm output | `$tester->run($phpts)` returning `Result` objects (`$result->output` is the old string); undecodable output becomes `Outcome::Error` |
| `--ARGS--` and `defaultArguments` went through a shell | no shell: `withArguments()` takes one argument per parameter, `--ARGS--` is split into words |
| `--EXPECT_EXTERNAL--`, `--EXPECTF_EXTERNAL--` | removed: move the expectation into `--EXPECT--` / `--EXPECTF--` |
| `$tester->test($test)` | `$tester->runOne($phpt)->assert()` |
| a hand-written `TestCase` with discovery, a data provider and `runBatch()` | `PsalmPhptTestCase` (see the [README](README.md#quick-start)) |
| unknown sections threw `Section X is not supported.` | still throw, naming the file; `--EXPECT_EXTERNAL--`, `--EXPECTF_EXTERNAL--`, `--CLEAN--`, `--ENV--`, `--INI--` get a "not supported by psalm-tester" message |
| a repeated section silently replaced the earlier one | a repeated section throws `Duplicate section --X--` |
