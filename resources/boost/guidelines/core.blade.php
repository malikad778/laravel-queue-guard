{{-- Laravel Boost / AI Guidelines for Queue and Concurrency Safety --}}

When writing Laravel code that interacts with database transactions, queue jobs, notifications, or mailables:

1. **Never Make HTTP or External Calls Inside Transactions (QG001)**
   - Do NOT call `Http::*`, external SDKs, or send emails inside `DB::transaction()`.
   - Wrap in `DB::afterCommit(fn () => ...)` or move execution outside the transaction.

2. **Always Use Idempotency Keys on Retried Financial Jobs (QG002)**
   - When `$tries > 1`, always supply `['idempotency_key' => ...]` on Stripe / payment gateway calls or guard with `$this->job->attempts() == 1`.

3. **Always Add `SerializesModels` to Queued Classes (QG003)**
   - Any job, notification, or mailable accepting Eloquent models in its constructor must include `use Illuminate\Queue\SerializesModels;`.

4. **Explicitly Specify `$timeout` and `$tries` (QG004)**
   - Every `ShouldQueue` class must have `public int $tries = 3;` and `public int $timeout = 60;`.

5. **Always Supply `finally()` or `catch()` on `Bus::batch()` (QG005)**
   - Ensure batches have cleanup handlers so parent workflows do not hang.

6. **Avoid Dispatching Inside Loops (QG006)**
   - Use `Bus::batch()`, bulk notifications (`Notification::send($users, ...)`), or chunking instead of individual `dispatch()` inside loops.

7. **Always Use `afterCommit` When Dispatching Inside Transactions (QG007)**
   - When calling `dispatch()` inside `DB::transaction()`, always append `->afterCommit()` or set `public bool $afterCommit = true;` on the job.

8. **Ensure Queue `retry_after` Exceeds Job `$timeout` (QG008)**
   - In `config/queue.php`, ensure `retry_after` is at least 30-60 seconds greater than the job's `$timeout`.

9. **Never Use Request or Session State in Queued Jobs (QG009)**
   - Pass all necessary data (user ID, tenant ID, IP address) into the job constructor; do not invoke `request()`, `auth()`, or `session()` in CLI queue workers.
