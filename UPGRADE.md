# Upgrade Guide

## Upgrading From 0.1.x to 0.2.x

### New Rules Enabled by Default
- **QG007** (`DispatchInsideTransactionRule`): Errors if jobs are dispatched inside `DB::transaction()` without `afterCommit()`. If you need to temporarily silence this while refactoring, run `vendor/bin/queue-guard baseline` or configure `'enabled' => false` for `QG007` in `config/queue-guard.php`.
- **QG008** (`RetryAfterTimeoutMismatchRule`): Checks your `config/queue.php` retry timeouts.
- **QG009** (`RequestContextInQueuedClassRule`): Checks for `request()`, `auth()`, or `session()` inside queued class handlers.

### Autofix Support
You can now automatically remediate QG003, QG005, and QG007 by running:
```bash
vendor/bin/queue-guard analyse app --fix
```
