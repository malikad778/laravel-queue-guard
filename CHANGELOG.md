# Changelog

All notable changes to `laravel-queue-guard` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-03-15

### Added
- **QG007**: `DispatchInsideTransactionDetector` and `DispatchInsideTransactionRule` catching dispatches inside `DB::transaction()` without `afterCommit`.
- **QG008**: `RetryAfterTimeoutMismatchDetector` detecting queue `retry_after <= $timeout` in job and connection configurations.
- **QG009**: `RequestContextInQueuedClassDetector` catching `request()`, `auth()`, `session()` context leakage in queued classes.
- Static config reader support for parsing `config/queue.php` and `config/horizon.php` array literals.
- Automated code fixers (`--fix`):
  - `AddSerializesModelsFixer` for QG003.
  - `AddFinallyStubFixer` for QG005.
  - `AddAfterCommitFixer` for QG007.
- Complete suite of 7 reporters: `Console`, `Json`, `Sarif`, `GithubAnnotations`, `Checkstyle`, `Junit`, and `GitlabCodeQuality`.
- Commands: `baseline`, `explain`, `init`, and `list-rules`.
- Baseline management via `queue-guard-baseline.json`.

## [0.1.0] - 2026-02-01

### Added
- Initial release of `malikad/laravel-queue-guard`.
- Core zero-bootstrap standalone CLI (`bin/queue-guard`) with `nikic/php-parser` engine.
- PHPStan extension (`extension.neon`) and adapter rules.
- Core rules:
  - **QG001**: `HttpInsideTransactionRule`
  - **QG002**: `NonIdempotentFinancialJobRule`
  - **QG003**: `MissingSerializesModelsRule`
  - **QG004**: `UnboundedJobTimeoutRule`
  - **QG005**: `BatchWithoutFinallyRule`
  - **QG006**: `SyncDispatchInLoopRule`
- Docblock exemption system with `/** @queue-safe */`.
