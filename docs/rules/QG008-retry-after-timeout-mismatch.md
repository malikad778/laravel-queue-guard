# QG008: Queue retry_after Is Less Than or Equal to Job Timeout

- **Identifier:** `queueGuard.retryAfterTimeoutMismatch`
- **Default Severity:** `error`

## What It Catches

Mismatches where the connection `retry_after` setting in `config/queue.php` is shorter than or equal to a job's `$timeout` configuration.

## Real Incident Story

A PDF generation job had a timeout of 120 seconds. The queue connection had `retry_after = 90`. When processing large documents that took 100 seconds, the queue driver marked the job unacknowledged and released it back into the queue after 90 seconds. A second worker picked up the same job while the first was still writing, generating duplicate files and corrupting S3 uploads.

## Bad Code

```php
// config/queue.php: 'retry_after' => 90
class ExportReport implements ShouldQueue
{
    public int $timeout = 120; // BAD: Exceeds retry_after
}
```

## Good Code

```php
// config/queue.php: 'retry_after' => 180
class ExportReport implements ShouldQueue
{
    public int $timeout = 120; // GOOD: retry_after leaves 60s safety buffer
}
```
