# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Upgrade steps between versions are in
[UPGRADING.md](UPGRADING.md).

## [0.4.0] (unreleased)

### Added

- `PsalmPhptTestCase`: implement `phptDirectory()` to get fixture discovery, SKIPIF handling, one batched Psalm run,
  and PHPUnit `--filter` narrowing (only the selected fixtures are analyzed).
- New API: `Phpt`, `Expectation`, `PsalmTester::create()` with `with*()` methods, and `run()` / `runOne()` returning a
  `Result` with an `Outcome`, the formatted output and structured `Issue`s.
- `--XFAIL--` section: an expected failure reports as incomplete, and as a failure once it passes.
- `withTimeout()` kills a hung Psalm run with its child processes (on Windows, only the Psalm process itself) and
  reports its tests as errors.
- `withConcurrency()`, `withWorkingDirectory()`, `withEnv()`.

### Changed

- `vimeo/psalm` (`^6.10 || ^7.0.0-beta16`) and `composer-runtime-api` (`^2`) are now required dependencies.
- Faster suites: `--SKIPIF--` scripts run concurrently, Psalm runs go through a bounded process runner (no shell, one
  run per argument set), and each run gets `--no-cache` with its own cache directory. psalm-plugin-laravel's type
  suite (758 fixtures) went from 28.6s to about 10s wall time, and to about 4.6s with `--filter` on one test.
- `--ARGS--` is appended to the configured arguments instead of replacing them; a `--config` in it replaces the
  configured config.
- Arguments no longer go through a shell: `withArguments()` takes one argument per parameter and `--ARGS--` is split
  into words.
- A repeated section throws `Duplicate section --X--` instead of silently replacing the earlier one. `--CLEAN--`,
  `--ENV--` and `--INI--` get an explicit "not supported by psalm-tester" message.

### Removed

- `--EXPECT_EXTERNAL--` and `--EXPECTF_EXTERNAL--` sections, now rejected as not supported: inline the expectation
  into the `.phpt` file.
- Progress output (the `showProgress` parameter of `create()`).

### Fixed

- A crashed Psalm run, an unexpected exit status, output that is not an issue list, or issues reported in other files
  now error the affected tests instead of passing them or throwing.

## [0.3.0] (2026-04-16)

### Added

- Batch execution of many fixtures in one Psalm run.
- Progress output: CLI arguments and test count per batch.
- `--SKIPIF--` section.

## [0.2.0] (2026-02-27)

### Changed

- `vimeo/psalm` (`^6.10 || ^7.0.0-beta16`) and `composer-runtime-api` (`^2`) are now required dependencies.
- Updated dependencies.

[0.4.0]: https://github.com/alies-dev/psalm-tester/compare/0.3.0...HEAD
[0.3.0]: https://github.com/alies-dev/psalm-tester/compare/0.2.0...0.3.0
[0.2.0]: https://github.com/alies-dev/psalm-tester/releases/tag/0.2.0
