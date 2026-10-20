# Automated Code Fixes (`--fix`)

Queue Guard includes built-in format-preserving AST fixers to instantly resolve safe issues without modifying unaffected code formatting.

## Running Autofix

```bash
vendor/bin/queue-guard analyse app --fix
```

## Supported Fixers

### 1. `AddSerializesModelsFixer` (QG003)
Inserts `use SerializesModels;` statement and imports `Illuminate\Queue\SerializesModels` on queued classes accepting Eloquent models in constructor parameters.

### 2. `AddFinallyStubFixer` (QG005)
Appends a `->finally(function (\Illuminate\Bus\Batch $batch) { ... })` stub chain method onto `Bus::batch([...])->dispatch()` calls that lack catch or finally callbacks.

### 3. `AddAfterCommitFixer` (QG007)
Appends `->afterCommit()` to uncommitted job dispatches inside database transactions.
