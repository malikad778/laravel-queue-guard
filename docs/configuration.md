# Configuration

To generate a custom config file, run:

```bash
vendor/bin/queue-guard init
```

This generates `config/queue-guard.php`.

## Reference Options

### `paths`
List of directories or files to analyse. Defaults to `['app', 'Modules']`.

### `exclude`
List of directories or file patterns to ignore during traversal. Defaults to `['app/Legacy', 'tests', 'vendor']`.

### `rules`
Per-rule configuration allowing you to toggle rules or override their severity:

```php
'rules' => [
    'QG001' => ['enabled' => true, 'severity' => 'error'],
    'QG004' => ['enabled' => true, 'severity' => 'warning'],
],
```

### `externalCallClasses`
Classes or SDKs that perform external network calls. Used by `QG001`:

```php
'externalCallClasses' => [
    'App\\Services\\CustomPaymentGateway',
    'Twilio\\Rest\\Client',
],
```

### `financialCallClasses`
Service classes with irreversible side effects or financial mutations. Used by `QG002`:

```php
'financialCallClasses' => [
    'App\\Services\\WalletService',
    'App\\Services\\Ledger',
],
```

### `maxCallDepth`
Number of intra-class method calls to follow when searching for network or dispatch calls inside transaction blocks. Defaults to `2`.
