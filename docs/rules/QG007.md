# QG007: Queue Dispatch Inside Database Transaction Without afterCommit

- **Identifier:** `queueGuard.dispatchInsideTransaction`
- **Default Severity:** `error`
- **Autofix Available:** Yes (`--fix`)

## What It Catches

Dispatching queue jobs inside an active `DB::transaction()` block without chaining `->afterCommit()` or configuring `$afterCommit = true`.

## Real Incident Story

A registration flow created a `User` record inside `DB::transaction()` and immediately dispatched `SendWelcomeEmail::dispatch($user)`. A Horizon worker picked up the job in under 3 milliseconds—before the database transaction committed. The worker threw `ModelNotFoundException: No query results for model [App\Models\User]`.

## Bad Code

```php
DB::transaction(function () {
    $user = User::create([...]);
    SendWelcomeEmail::dispatch($user);
});
```

## Good Code

```php
DB::transaction(function () {
    $user = User::create([...]);
    SendWelcomeEmail::dispatch($user)->afterCommit();
});
```
