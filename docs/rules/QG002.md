# QG002: Non-Idempotent Financial or Side-Effect Job With Retries

- **Identifier:** `queueGuard.nonIdempotentFinancialJob`
- **Default Severity:** `error`

## What It Catches

Queued classes configured with retries (`$tries > 1` or retry backoffs) that execute monetary mutations or external side-effects without passing an idempotency key or guarding via `$this->job->attempts()`.

## Real Incident Story

A subscription renewal job charged customers via Stripe. When an unrelated timeout occurred writing the invoice PDF after the charge, Horizon retried the job. Because the charge call lacked an idempotency key, 420 customers were billed twice.

## Bad Code

```php
class ChargeCustomer implements ShouldQueue
{
    public int $tries = 3;

    public function handle(): void
    {
        // BAD: Will execute again if a subsequent statement throws an exception
        \Stripe\Charge::create(['amount' => 5000, 'currency' => 'usd']);
    }
}
```

## Good Code

```php
class ChargeCustomer implements ShouldQueue
{
    public int $tries = 3;

    public function handle(): void
    {
        // GOOD: Stripe deduplicates retries matching the idempotency key
        \Stripe\Charge::create(
            ['amount' => 5000, 'currency' => 'usd'],
            ['idempotency_key' => 'order_' . $this->orderId]
        );
    }
}
```

## Exemptions

```php
/** @queue-safe QG002 */
class ChargeCustomer implements ShouldQueue { ... }
```
