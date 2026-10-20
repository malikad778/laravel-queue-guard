# QG009: HTTP Request or Session Context Used Inside Queued Class

- **Identifier:** `queueGuard.requestContextInQueuedClass`
- **Default Severity:** `error`

## What It Catches

Invoking HTTP request helpers (`request()`, `Request::*`), authentication (`auth()->user()`, `Auth::id()`), or session methods (`session()`) inside queued class handlers.

## Real Incident Story

An audit log job invoked `request()->ip()` and `auth()->id()` to record who initiated an asynchronous task. Because queue workers run in long-lived CLI processes without an incoming HTTP request, `auth()->id()` and `request()->ip()` consistently evaluated to `null`, corrupting audit logs.

## Bad Code

```php
class ProcessAuditLog implements ShouldQueue
{
    public function handle(): void
    {
        // BAD: Evaluates to null in CLI queue worker daemon
        $ip = request()->ip();
        $userId = auth()->id();
    }
}
```

## Good Code

```php
class ProcessAuditLog implements ShouldQueue
{
    // GOOD: Captured at dispatch time in the HTTP request and passed into the job
    public function __construct(
        public string $ip,
        public int $userId
    ) {}

    public function handle(): void
    {
        Log::info("Action by {$this->userId} from {$this->ip}");
    }
}
```
