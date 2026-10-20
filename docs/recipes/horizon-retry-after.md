# Recipe: Horizon retry_after vs Job Timeout (QG008)

## The Concurrency Hazard

Laravel's queue connections define a `retry_after` setting in `config/queue.php`:

```php
'redis' => [
    'driver' => 'redis',
    'connection' => 'default',
    'queue' => env('REDIS_QUEUE', 'default'),
    'retry_after' => 90,
],
```

If a job has:

```php
class GenerateMonthlyReport implements ShouldQueue
{
    public int $timeout = 120;
}
```

The queue manager assumes the worker died after 90 seconds and releases the job back onto the queue for another worker to pick up—even though Worker #1 is still processing it! Both workers now execute the heavy job concurrently.

## The Rule of Thumb

Always ensure:

$$\text{retry\_after} \ge \text{job \$timeout} + 30\text{ seconds}$$

For example, if your job timeout is `120`, configure `retry_after => 180`.
