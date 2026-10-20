# Contributing to laravel-queue-guard

Thank you for considering contributing to `laravel-queue-guard`!

## Development Setup

1. Clone the repository:
   ```bash
   git clone https://github.com/malikad778/laravel-queue-guard.git
   cd laravel-queue-guard
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Run the test suite:
   ```bash
   composer test
   ```

4. Run code style checks:
   ```bash
   composer pint
   ```

5. Run PHPStan static analysis:
   ```bash
   composer phpstan
   ```

## Adding a New Rule

1. Define a new enum case in `src/Core/RuleId.php` with title, identifier, default severity, explanation, and docs URL.
2. Implement the detector in `src/Detectors/` implementing `Malikad\QueueGuard\Contracts\Detector`.
3. Add the PHPStan adapter in `src/PHPStan/Rules/` extending `AbstractQueueGuardRule`.
4. Register the detector and PHPStan rule in `extension.neon`.
5. Add unit and fixture tests under `tests/Detectors/`.
6. Add rule documentation under `docs/rules/`.

## False-Positive Policy

If you find a false positive, please submit an issue using the `False Positive` template with a minimal code reproduction. Confirmed false positives will be fixed in a patch release within 7 days.
