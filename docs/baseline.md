# Baseline & Legacy Codebases

When introducing Queue Guard to an established project, fixing every preexisting warning immediately may be impractical. The baseline mechanism lets you snapshot existing issues so CI only fails on **new** code.

## Generating the Baseline

Run:

```bash
vendor/bin/queue-guard baseline
```

This analyzes your codebase and records fingerprints of every current finding in `queue-guard-baseline.json`.

## How Fingerprints Work

Findings are fingerprinted by:
1. Rule ID (`QG001`, `QG002`, etc.)
2. Normalized relative file path
3. Normalized message text

Unlike simple line-based baselines, Queue Guard fingerprints remain valid even when surrounding code is edited or moved, as long as the underlying issue was not newly introduced.

## Using the Baseline

Queue Guard automatically loads `queue-guard-baseline.json` if present in your project root or specified via config:

```bash
vendor/bin/queue-guard analyse app --baseline=queue-guard-baseline.json
```
