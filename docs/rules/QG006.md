# QG006: Synchronous or Individual Queue Dispatch Inside a Loop

- **Identifier:** `queueGuard.syncDispatchInLoop`
- **Default Severity:** `warning`

## What It Catches

Invoking individual job dispatches (`dispatch()`, `Notification::send()`, `Mail::to()->queue()`) inside iteration loops (`foreach`, `while`, `map`, `each`).

## Real Incident Story

A newsletter broadcast looped over 40,000 users calling `SendEmail::dispatch($user)`. Each dispatch triggered an individual Redis `rpush` network round-trip from the web server, timing out PHP-FPM requests and corrupting loop completion.

## Bad Code

```php
foreach ($users as $user) {
    SendPromoEmailJob::dispatch($user);
}
```

## Good Code

```php
use Illuminate\Support\Facades\Bus;

$jobs = collect($users)->map(fn ($u) => new SendPromoEmailJob($u));
Bus::batch($jobs)->dispatch();
```
