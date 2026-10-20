<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Core;

enum RuleId: string
{
    case QG001 = 'QG001';
    case QG002 = 'QG002';
    case QG003 = 'QG003';
    case QG004 = 'QG004';
    case QG005 = 'QG005';
    case QG006 = 'QG006';
    case QG007 = 'QG007';
    case QG008 = 'QG008';
    case QG009 = 'QG009';
    case PARSE_ERROR = 'PARSE_ERROR';

    /**
     * @return list<self>
     */
    public static function qgRules(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $c): bool => str_starts_with($c->value, 'QG')));
    }

    public function phpstanIdentifier(): string
    {
        return match ($this) {
            self::QG001 => 'queueGuard.httpInsideTransaction',
            self::QG002 => 'queueGuard.nonIdempotentFinancialJob',
            self::QG003 => 'queueGuard.missingSerializesModels',
            self::QG004 => 'queueGuard.unboundedJobTimeout',
            self::QG005 => 'queueGuard.batchWithoutFinally',
            self::QG006 => 'queueGuard.syncDispatchInLoop',
            self::QG007 => 'queueGuard.dispatchInsideTransaction',
            self::QG008 => 'queueGuard.retryAfterTimeoutMismatch',
            self::QG009 => 'queueGuard.requestContextInQueuedClass',
            self::PARSE_ERROR => 'queueGuard.parseError',
        };
    }

    public function defaultSeverity(): Severity
    {
        return match ($this) {
            self::QG001, self::QG002, self::QG003, self::QG007, self::QG008, self::QG009, self::PARSE_ERROR => Severity::Error,
            self::QG004, self::QG005, self::QG006 => Severity::Warning,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::QG001 => 'HTTP or External Network Call Inside Database Transaction',
            self::QG002 => 'Non-Idempotent Financial or Side-Effect Job With Retries',
            self::QG003 => 'Missing SerializesModels Trait on Queued Class Accepting Models',
            self::QG004 => 'Unbounded Job Timeout or Missing Retry Policy',
            self::QG005 => 'Bus::batch() or Bus::chain() Without Finally or Catch Callback',
            self::QG006 => 'Synchronous or Individual Queue Dispatch Inside a Loop',
            self::QG007 => 'Queue Dispatch Inside Database Transaction Without afterCommit',
            self::QG008 => 'Queue retry_after Is Less Than or Equal to Job Timeout',
            self::QG009 => 'HTTP Request or Session Context Used Inside Queued Class',
            self::PARSE_ERROR => 'PHP Syntax or Parse Error',
        };
    }

    public function docsUrl(): string
    {
        return 'https://malikad778.github.io/laravel-queue-guard/rules/'.$this->value;
    }

    /**
     * @return array{why: string, bad: string, good: string}
     */
    public function explanation(): array
    {
        return match ($this) {
            self::QG001 => [
                'why' => 'Making HTTP requests inside DB::transaction() holds database row locks while waiting on remote network I/O, causing lock wait timeouts and cascading connection pool exhaustion.',
                'bad' => "DB::transaction(function () use (\$order) {\n    \$order->update(['status' => 'paid']);\n    Http::post('https://api.example.com/webhook', \$order->toArray());\n});",
                'good' => "DB::transaction(function () use (\$order) {\n    \$order->update(['status' => 'paid']);\n    DB::afterCommit(fn () => Http::post('https://api.example.com/webhook', \$order->toArray()));\n});",
            ],
            self::QG002 => [
                'why' => 'Retried jobs re-execute handle() from the top. If a payment call succeeded before a subsequent timeout or exception, the retry charges the customer a second time.',
                'bad' => "class ChargeCustomer implements ShouldQueue {\n    public int \$tries = 3;\n    public function handle(): void {\n        \\Stripe\\Charge::create(['amount' => 5000, 'currency' => 'usd']);\n    }\n}",
                'good' => "class ChargeCustomer implements ShouldQueue {\n    public int \$tries = 3;\n    public function handle(): void {\n        \\Stripe\\Charge::create(['amount' => 5000], ['idempotency_key' => 'order_' . \$this->orderId]);\n    }\n}",
            ],
            self::QG003 => [
                'why' => 'Passing an Eloquent Model to a queued class without SerializesModels serializes the entire model payload and loaded relations into Redis, risking stale attributes and huge payloads.',
                'bad' => "class SendOrderInvoice implements ShouldQueue {\n    public function __construct(public Order \$order) {}\n}",
                'good' => "class SendOrderInvoice implements ShouldQueue {\n    use SerializesModels;\n    public function __construct(public Order \$order) {}\n}",
            ],
            self::QG004 => [
                'why' => 'Jobs without an explicit $timeout or retry limit fall back to worker defaults and can hang workers indefinitely or retry forever on fatal states.',
                'bad' => "class SyncCatalog implements ShouldQueue {\n    public function handle(): void {}\n}",
                'good' => "class SyncCatalog implements ShouldQueue {\n    public int \$tries = 3;\n    public int \$timeout = 60;\n    public function handle(): void {}\n}",
            ],
            self::QG005 => [
                'why' => 'Dispatching a batch without finally() or catch() leaves parent workflows in an inconsistent state and fails to clean up resources when child jobs fail.',
                'bad' => 'Bus::batch($jobs)->dispatch();',
                'good' => "Bus::batch(\$jobs)->finally(function (Batch \$batch) {\n    // cleanup or mark complete\n})->dispatch();",
            ],
            self::QG006 => [
                'why' => 'Dispatching jobs or sending notifications one by one inside a loop causes N network round-trips to Redis or your mail provider.',
                'bad' => "foreach (\$users as \$user) {\n    SendPromoJob::dispatch(\$user);\n}",
                'good' => "\$jobs = collect(\$users)->map(fn (\$u) => new SendPromoJob(\$u));\nBus::batch(\$jobs)->dispatch();",
            ],
            self::QG007 => [
                'why' => 'Jobs dispatched inside an uncommitted transaction can be picked up by a fast Horizon worker before the transaction commits, causing ModelNotFoundException or stale reads.',
                'bad' => "DB::transaction(function () {\n    \$user = User::create([...]);\n    SendWelcomeEmail::dispatch(\$user);\n});",
                'good' => "DB::transaction(function () {\n    \$user = User::create([...]);\n    SendWelcomeEmail::dispatch(\$user)->afterCommit();\n});",
            ],
            self::QG008 => [
                'why' => 'When queue retry_after is less than or equal to a job timeout, Horizon releases the job back onto the queue while the first worker is still executing it, running the same job twice in parallel.',
                'bad' => "// config/queue.php: 'retry_after' => 90\nclass ExportReport implements ShouldQueue {\n    public int \$timeout = 120;\n}",
                'good' => "// config/queue.php: 'retry_after' => 180\nclass ExportReport implements ShouldQueue {\n    public int \$timeout = 120;\n}",
            ],
            self::QG009 => [
                'why' => 'Queue workers run in CLI daemon processes without an active HTTP request or session. Calling request(), auth()->user(), or session() inside handle() returns null or stale state.',
                'bad' => "class ProcessAudit implements ShouldQueue {\n    public function handle(): void {\n        \$ip = request()->ip();\n    }\n}",
                'good' => "class ProcessAudit implements ShouldQueue {\n    public function __construct(public string \$ip) {}\n    public function handle(): void {}\n}",
            ],
            self::PARSE_ERROR => [
                'why' => 'The file contains a PHP syntax or parse error and cannot be analyzed by php-parser.',
                'bad' => "<?php\nclass Incomplete { public function",
                'good' => "<?php\nclass Incomplete { public function handle(): void {} }",
            ],
        };
    }
}
