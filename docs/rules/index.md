# Rules Catalog

Queue Guard includes 9 core rules covering transactions, financial idempotency, serialization, timeouts, and daemon contexts:

| Rule ID | Identifier | Default Severity | Title |
|---|---|---|---|
| [**QG001**](/rules/QG001-http-inside-transaction) | `queueGuard.httpInsideTransaction` | `error` | HTTP or External Network Call Inside Database Transaction |
| [**QG002**](/rules/QG002-non-idempotent-financial-job) | `queueGuard.nonIdempotentFinancialJob` | `error` | Non-Idempotent Financial or Side-Effect Job With Retries |
| [**QG003**](/rules/QG003-missing-serializes-models) | `queueGuard.missingSerializesModels` | `error` | Missing SerializesModels Trait on Queued Class Accepting Models |
| [**QG004**](/rules/QG004-unbounded-job-timeout) | `queueGuard.unboundedJobTimeout` | `warning` | Unbounded Job Timeout or Missing Retry Policy |
| [**QG005**](/rules/QG005-batch-without-finally) | `queueGuard.batchWithoutFinally` | `warning` | Bus::batch() or Bus::chain() Without Finally or Catch Callback |
| [**QG006**](/rules/QG006-sync-dispatch-in-loop) | `queueGuard.syncDispatchInLoop` | `warning` | Synchronous or Individual Queue Dispatch Inside a Loop |
| [**QG007**](/rules/QG007-dispatch-inside-transaction) | `queueGuard.dispatchInsideTransaction` | `error` | Queue Dispatch Inside Database Transaction Without afterCommit |
| [**QG008**](/rules/QG008-retry-after-timeout-mismatch) | `queueGuard.retryAfterTimeoutMismatch` | `error` | Queue retry_after Is Less Than or Equal to Job Timeout |
| [**QG009**](/rules/QG009-request-context-in-queued-class) | `queueGuard.requestContextInQueuedClass` | `error` | HTTP Request or Session Context Used Inside Queued Class |
