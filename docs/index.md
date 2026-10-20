---
layout: home

hero:
  name: Laravel Queue Guard
  text: Static analysis for Laravel queues, Horizon and FinTech concurrency bugs
  tagline: Catch double-charges, lock-holding HTTP calls and orphaned batches before they hit production.
  actions:
    - theme: brand
      text: Get Started
      link: /getting-started
    - theme: alt
      text: View Rules (QG001 - QG009)
      link: /rules/

features:
  - title: Zero-Bootstrap Standalone CLI
    details: Scans hundreds of files in under a second using nikic/php-parser AST without booting Laravel or connecting to databases.
  - title: First-Class PHPStan Extension
    details: Seamlessly integrates into your existing PHPStan and Larastan CI pipelines via extension.neon.
  - title: FinTech & Concurrency Safety
    details: Detects double-charges in retried jobs, lock-holding HTTP calls in transactions, and Horizon retry_after race conditions.
  - title: Automated AST Fixers
    details: Automatically refactor uncommitted dispatches, missing SerializesModels traits, and orphan batches with --fix.
---
