# Recipe: Transaction afterCommit (QG001 & QG007)

## The Issue

In high-concurrency systems, queue workers pick up jobs in milliseconds. If a job is dispatched inside `DB::transaction()` before the transaction has committed, the worker may query the database before changes are visible, throwing `ModelNotFoundException` or processing stale records.

Similarly, executing HTTP requests inside a transaction holds database locks for the entire duration of the remote network latency.

## Best Practice: Explicit `afterCommit()`

```php
use App\Jobs\SendWelcomeEmail;
use Illuminate\Support\Facades\DB;

DB::transaction(function () use ($user) {
    $user->save();

    // Safe: Dispatched only after commit
    SendWelcomeEmail::dispatch($user)->afterCommit();
});
```

## Best Practice: Job Property

On the job class itself:

```php
class SendWelcomeEmail implements ShouldQueue
{
    public bool $afterCommit = true;
}
```

## Best Practice: `DB::afterCommit()` for HTTP

```php
DB::transaction(function () use ($order) {
    $order->update(['status' => 'completed']);

    DB::afterCommit(fn () => Http::post('https://erp.internal/orders', $order->toArray()));
});
```
