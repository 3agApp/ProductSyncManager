# Engineering review — 2026-09-12

The project has a solid Laravel/Inertia foundation: shop-scoped route bindings, policies and form requests, typed enums, focused sync actions, encrypted connection credentials, and meaningful feature and browser tests. A rewrite would add risk without addressing the main problems. Production failure handling deserves priority over cosmetic changes.

This review inspected routes, membership enforcement, product writes and filtering, invitations, the WooCommerce client/import job, scheduling, queue configuration, and representative React flows. This is a code and regression review, not a penetration test, production load test, or comprehensive visual/accessibility audit.

## Implemented

| Priority | Finding                                                                                                                                                      | Change                                                                                                                                                                                                        |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| P1       | Exhausting `max_pages` fell through to archiving products never fetched.                                                                                     | Product pagination now throws before archiving when completeness cannot be established. Category pagination also rejects reaching the cap instead of saving a partial tree.                                   |
| P1       | A 200 HTML maintenance page, null response, or malformed catalog could be interpreted as an empty store.                                                     | Require a JSON array of objects with positive integer IDs before importing. Reject prematurely empty product pages and mismatched reported/received totals before archiving.                                  |
| P1       | Queue reservations expired after 90 seconds while sync jobs could run for 900 seconds. Another worker could reserve the same job while it was still running. | Raise database, Redis, and Beanstalkd defaults to 960 seconds and document environment overrides.                                                                                                             |
| P1       | The HTTP client followed redirects from a user-configured store.                                                                                             | Disable redirects and reject non-success responses. Accept a nullable retry callback exception because Laravel supplies null for redirects. This reduces outbound request risk but does not fully solve SSRF. |
| P2       | Typing a search and immediately sorting/filtering could discard the search, then a delayed callback could overwrite the newer choice.                        | Cancel the pending debounce when applying filters and include the current search input in the same navigation. Added a browser regression test.                                                               |

A catalog without pagination headers needs an empty page to establish completion. If it reaches the configured cap first, it now fails conservatively. Successfully imported pages remain saved; archiving is withheld. The next attempt starts over, matching the existing retry design. A store redirect must be resolved by configuring its final canonical address.

## Remaining findings, in priority order

### P1 — Validate outbound destinations at connection time

`app/Rules/StoreUrl.php:68` uses `gethostbyname` during validation. This checks one IPv4 result, does not establish safety of all A/AAAA addresses, and does not bind the later HTTP connection to the validated address. A hostname can resolve differently when a worker eventually connects. Disabling redirects closes one path, but DNS rebinding and IPv6 handling still require work.

Resolve and validate all candidate addresses at request time, reject non-public destinations, and pin the validated address while retaining TLS hostname verification. Pair this with deployment egress restrictions. Verify public/private IPv4, IPv6, mixed DNS answers, and rebinding using controlled DNS/transport tests before calling this protection complete.

### P1 — Serialize sync admission and make dispatch recoverable

`app/Actions/Sync/StartSyncRun.php:28` checks for an active run and inserts a new one inside a transaction without locking a shared shop row. On databases allowing concurrent readers, two requests can both pass the check. The worker's `WithoutOverlapping()->dontRelease()` can discard one job and leave its run queued until the stalled-run reaper acts. A queue dispatch failure after the transaction commits can also leave a queued run with no job.

Serialize admission per shop with a database-aware lock or enforced active-run invariant. Make dispatch failure recoverable through an outbox or explicit reconciliation. Test concurrent manual/scheduled starts and queue unavailability against the intended production database; SQLite sequential tests do not establish that guarantee.

### P2 — Make product edits atomic with taxonomy writes

`app/Http/Controllers/Products/ProductController.php:240` and `:256` save the product before synchronizing categories; resolving submitted brands and category paths can also write new rows. If a later write fails, users get an error after some changes have already persisted. Enclose the complete product/brand/category operation in one transaction and add a failure-injection rollback test.

### P2 — Finish frontend state and accessibility verification

The product list initializes its search input from props but preserves local state across navigation. Browser history/shop changes deserve explicit tests to keep the displayed input and applied URL aligned. The implemented race fix covers immediate search plus sort/filter interactions, not every navigation sequence. Add mobile overflow, keyboard navigation, dialog focus, and dark-theme checks before a broader visual redesign. Existing page smoke tests establish rendering, not full accessibility.

## Validation

- Baseline: 286 backend tests passed; frontend type, lint, and formatting checks passed.
- After changes: 296 backend tests passed (1,315 assertions).
- Chromium: all 23 browser tests passed, including the new debounce regression.
- PHPStan: no errors (`--debug --memory-limit=512M` avoids a sandbox-restricted worker socket).
- TypeScript, frontend lint/formatting, Pint, and production build passed.
- Build reports an existing optional `fontaine` dependency warning; build succeeds.
- No live WooCommerce requests or production deployment performed. Browser tests use the project's dedicated `database/e2e.sqlite` database.

## Deployment note

Existing environment overrides take precedence over the new defaults. Set the chosen queue's retry interval above 900 seconds (960 is the new default), rebuild cached configuration, and restart supervised workers during deployment. For SQS, configure the equivalent visibility timeout in AWS. Laravel documents why a job timeout must be shorter than its retry interval: [Laravel 13 queue documentation](https://github.com/laravel/docs/blob/13.x/queues.md#job-expirations-and-timeouts).
