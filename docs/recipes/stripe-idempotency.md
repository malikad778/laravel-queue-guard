# Recipe: Stripe Idempotency Keys (QG002)

When a Laravel queue job is retried by Horizon or the database queue worker, `handle()` re-runs from line 1.

If a payment call succeeded right before a network hiccup or timeout threw an exception, a second run charges the customer again.

## Safe Pattern: Idempotency Key

Always pass an `idempotency_key` based on your internal order/transaction ID:

```php
namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Stripe\PaymentIntent;

class ProcessOrderPayment implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(public string $orderId, public int $amountCents) {}

    public function handle(): void
    {
        PaymentIntent::create([
            'amount' => $this->amountCents,
            'currency' => 'usd',
            'metadata' => ['order_id' => $this->orderId],
        ], [
            'idempotency_key' => 'order_' . $this->orderId,
        ]);
    }
}
```

## Safe Pattern: Attempt Guard

If you only want the financial side-effect on the very first attempt:

```php
if ($this->attempts() > 1) {
    return;
}
```
