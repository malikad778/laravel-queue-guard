# QG003: Missing SerializesModels Trait on Queued Class

- **Identifier:** `queueGuard.missingSerializesModels`
- **Default Severity:** `error`
- **Autofix Available:** Yes (`--fix`)

## What It Catches

Queued classes (jobs, listeners, mailables, notifications) accepting Eloquent models or collections in their constructor parameters without utilizing `SerializesModels`.

## Real Incident Story

A queued email notification received a freshly loaded `$user` model with deeply nested relation trees. Without `SerializesModels`, PHP serialized the entire in-memory object graph (14 MB) into Redis, rapidly exhausting Redis memory and discarding queued jobs.

## Bad Code

```php
class SendOrderInvoice implements ShouldQueue
{
    // BAD: Entire Eloquent model instance and loaded relations serialized into Redis
    public function __construct(public Order $order) {}
}
```

## Good Code

```php
use Illuminate\Queue\SerializesModels;

class SendOrderInvoice implements ShouldQueue
{
    use SerializesModels; // GOOD: Serializes only model identifier and reloads fresh from DB

    public function __construct(public Order $order) {}
}
```

## Autofix

```bash
vendor/bin/queue-guard analyse app --fix
```
