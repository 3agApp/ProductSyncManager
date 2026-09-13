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

### Implemented 2026-09-13 — Validate outbound destinations at request time

`WooCommerceClient` now resolves the store host on every request through `App\Services\Network\PublicHostGuard`, which checks every A and AAAA answer against Symfony's private subnets plus multicast, NAT64 and discard ranges, and refuses the host if any answer is non-public or none resolve. The request is then pinned to the checked addresses with `CURLOPT_RESOLVE`, so curl cannot look the name up again, while TLS still verifies the hostname. A blocked address fails a sync run without retrying. `StoreUrl` uses the same guard for early form feedback. `WOOCOMMERCE_ALLOW_PRIVATE_HOSTS` disables the guard and defaults on only when `APP_ENV=local`.

`tests/Feature/Sync/StoreDestinationTest.php` covers private, loopback, link-local, CGNAT, multicast, IPv6, IPv4-mapped and NAT64 answers, a mixed public/private answer, unresolvable hosts, pin formatting including IPv6 and custom ports, the job failure, and a host that rebinds between validation and connection. Tests use a fake resolver, so none touch real DNS. Separately, a local check confirmed curl honours the pin through Laravel's HTTP client against a loopback server.

Remaining: an `HTTP_PROXY`/`HTTPS_PROXY` in the worker environment would move the connection to the proxy and bypass the pin, and the incremental webhook job retries a blocked store until its retry window closes rather than failing at once. Deployment egress restrictions are still recommended as a second layer.

### Implemented 2026-09-13 — Serialize sync admission and make dispatch recoverable

Admission is now an enforced invariant rather than a check-then-insert. `sync_runs.active_shop_id` holds the shop id while a run is queued or running and null otherwise, maintained by a `saving` hook on `SyncRun`; its unique index makes the database refuse a second active run on SQLite, MySQL and PostgreSQL alike. `StartSyncRun` treats the resulting `UniqueConstraintViolationException` as "already running". The migration fails any older duplicate active runs before backfilling.

A dispatch that throws now reports the exception and marks the run failed with a user-facing message, freeing the shop immediately instead of waiting for the reaper; the manual sync page and scheduler both surface it. The job also exits when its run already finished, so a late retry cannot revive a run the reaper failed after a newer run took the slot.

Covered by `tests/Feature/Sync/StartSyncRunTest.php`. The invariant is enforced by the index rather than by timing, but truly concurrent starts have not been exercised against the production database driver.

### Implemented 2026-09-13 — Make product edits atomic with taxonomy writes

`ProductController::store` and `::update` now resolve the brand, save the product, resolve category paths and sync the pivot inside one `DB::transaction`, so a failure at any step leaves no new brand, category or product row and no partial update. `tests/Feature/Products/ProductSaveTransactionTest.php` injects a failure once category rows are being written, after the brand and product were saved, and checks both create and update roll back; both tests fail against the previous controller.

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
