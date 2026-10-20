# GitHub Actions

You can integrate Queue Guard into GitHub Actions using the composite action or SARIF reporting for GitHub Code Scanning.

## Option 1: Official GitHub Action

```yaml
name: Queue Guard

on: [push, pull_request]

jobs:
  analyse:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          coverage: none

      - name: Install dependencies
        run: composer install --prefer-dist --no-progress

      - uses: malikad778/laravel-queue-guard@v1
        with:
          paths: app
          fail-on: error
          format: github
```

## Option 2: SARIF Code Scanning

```yaml
      - name: Run Queue Guard SARIF
        run: vendor/bin/queue-guard analyse app --format=sarif > queue-guard.sarif
        continue-on-error: true

      - name: Upload SARIF report
        uses: github/codeql-action/upload-sarif@v3
        with:
          sarif_file: queue-guard.sarif
```
