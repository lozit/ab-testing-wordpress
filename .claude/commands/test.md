---
description: Run the PHPUnit test suite
---

Run the PHPUnit test suite.

If `composer.json` exposes a `test` script: `composer run test`.
Otherwise: `./vendor/bin/phpunit`.

Afterwards:
- If tests are green → briefly summarize the coverage of the recent changes.
- If tests are red → diagnose the first error, propose a fix, do NOT update snapshots / expectations without explicit validation (that would mask the bug).

Never mark a task "complete" if the suite isn't green.
