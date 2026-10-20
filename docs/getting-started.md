# Getting Started

## Requirements

- PHP 8.1 - 8.4
- Laravel 10.x, 11.x, or 12.x codebases (static analysis; Laravel is never booted)

## Installation

Install `malikad/laravel-queue-guard` via Composer:

```bash
composer require --dev malikad/laravel-queue-guard
```

## Running the CLI

Queue Guard requires zero configuration to start. Run analysis on your `app` directory:

```bash
vendor/bin/queue-guard analyse app
```

### CLI Flags

- `--format=console|json|sarif|github|checkstyle|junit|gitlab`: Set the output format.
- `--fail-on=error|warning|info`: Set the threshold for non-zero exit codes.
- `--fix`: Automatically apply safe AST fixes to detected code issues.
- `--baseline=file.json`: Filter out preexisting violations in legacy codebases.
- `--config=path`: Custom configuration file path.

## PHPStan Extension Mode

If using PHPStan, include `extension.neon` in your `phpstan.neon`:

```neon
includes:
    - vendor/malikad/laravel-queue-guard/extension.neon
```

Run PHPStan:

```bash
vendor/bin/phpstan analyse
```
