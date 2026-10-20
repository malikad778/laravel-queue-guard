# Recipe: Batching Bulk Notifications (QG006)

Dispatching jobs or notifications one-by-one inside a loop causes $N$ round-trip Redis pushes or SMTP handshakes:

```php
// ANTI-PATTERN: N round-trips
foreach ($users as $user) {
    SendNotificationJob::dispatch($user);
}
```

## Solution 1: `Bus::batch()`

Group the dispatches into a single pipeline batch:

```php
use Illuminate\Support\Facades\Bus;

$jobs = $users->map(fn ($user) => new SendNotificationJob($user));

Bus::batch($jobs)
    ->finally(function () {
        // cleanup or notification
    })
    ->dispatch();
```

## Solution 2: Bulk `Notification::send()`

```php
use Illuminate\Support\Facades\Notification;

Notification::send($users, new SystemMaintenanceAlert());
```
