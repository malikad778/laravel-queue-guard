# QG004: Unbounded Job Timeout or Missing Retry Policy

- **Identifier:** `queueGuard.unboundedJobTimeout`
- **Default Severity:** `warning`

## What It Catches

`ShouldQueue` classes that lack an explicit `$timeout` property or timeout method, and omit `$tries`, `backoff()`, or `retryUntil()`.

## Real Incident Story

A sync job calling a partner API stalled when the partner hung TCP connections. Without `$timeout` set, the worker thread was frozen for hours, starving all other queued jobs from executing.

## Bad Code

```php
class SyncCatalog implements ShouldQueue
{
    // BAD: Missing timeout and retry policy
    public function handle(): void {}
}
```

## Good Code

```php
class SyncCatalog implements ShouldQueue
{
    public int $tries = 3;
    public int $timeout = 60;

    public function handle(): void {}
}
```
