# QG001: HTTP or External Network Call Inside Database Transaction

- **Identifier:** `queueGuard.httpInsideTransaction`
- **Default Severity:** `error`

## What It Catches

Making external network requests (`Http::post()`, Stripe SDK, Mail, Guzzle) while holding an open database transaction (`DB::transaction()` or `DB::beginTransaction()`).

## Real Incident Story

During a flash sale, a payment provider suffered a 2.5-second latency spike. Because HTTP requests were made directly inside `DB::transaction()`, hundreds of MySQL row locks were held open simultaneously. Within 30 seconds, the database connection pool was exhausted, taking the entire production application down.

## Bad Code

```php
DB::transaction(function () use ($order) {
    $order->update(['status' => 'paid']);
    // BAD: Holds database row locks while awaiting external network I/O
    Http::post('https://api.example.com/webhook', $order->toArray());
});
```

## Good Code

```php
DB::transaction(function () use ($order) {
    $order->update(['status' => 'paid']);
    // GOOD: Defers network I/O until AFTER database commit
    DB::afterCommit(fn () => Http::post('https://api.example.com/webhook', $order->toArray()));
});
```

## How It Is Detected

Queue Guard searches for closure scopes passed to `DB::transaction()` or code between `DB::beginTransaction()` and `DB::commit()`. It identifies external calls through matched facades, Guzzle clients, payment SDKs, and custom classes configured in `externalCallClasses`.

## Exemptions

```php
/** @queue-safe QG001 */
DB::transaction(function () { ... });
```
