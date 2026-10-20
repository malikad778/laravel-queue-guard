# QG005: Bus::batch() Without Finally or Catch Callback

- **Identifier:** `queueGuard.batchWithoutFinally`
- **Default Severity:** `warning`
- **Autofix Available:** Yes (`--fix`)

## What It Catches

Dispatching `Bus::batch([...])->dispatch()` without defining `finally()` or `catch()` callbacks to handle batch conclusion or failure.

## Real Incident Story

An image processing pipeline dispatched a 50-job batch. When one image failed validation, the entire batch was flagged cancelled, but without `finally()`, the parent order was left stuck in "processing" indefinitely.

## Bad Code

```php
Bus::batch($jobs)->dispatch();
```

## Good Code

```php
use Illuminate\Bus\Batch;

Bus::batch($jobs)
    ->catch(function (Batch $batch, \Throwable $e) {
        // Handle failure
    })
    ->finally(function (Batch $batch) {
        // Complete parent order
    })
    ->dispatch();
```
