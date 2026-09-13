# WooCommerce product sync

Shops have a **type**. WordPress shops can connect to a WooCommerce store and
pull its catalog into the local products table, on a schedule and on demand.

Sync is **pull only**: WooCommerce is the source of truth. Local edits to a
synced product are overwritten on the next run, and a locally deleted synced
product comes back.

## Setting a shop up

1. Settings → Shops → _shop_ → **Sync**.
2. Enter the store address and a WooCommerce REST API consumer key and secret.
   Generate these in WooCommerce under **Settings → Advanced → REST API**.
   Read permission is enough.
3. Press **Test connection** to check the credentials before saving.
4. Press **Sync now**, or turn on a schedule.

Owners and admins configure the connection and schedule. Owners, admins and
members can all trigger a manual sync.

## What a sync does

- Fetches **published** simple products, 100 per page, oldest first. Drafts,
  pending and private products are never imported, so everything a sync
  brings in is live in the store and lands as **Active**.
- Matches each remote product on its stored WooCommerce id. Failing that, it
  matches an existing local product by SKU and adopts it, but only when that
  product is not already bound to a different remote product.
- Products missing a SKU get a synthesized `WC-<id>`, since SKUs are unique
  per shop.
- Products the store no longer publishes are set to **Archived**, never
  deleted, and only if every page of the run succeeded. That covers both a
  deleted product and one that was merely unpublished. Products created by
  hand in this app are never archived.
- **Variable products are not imported.** They keep their price and stock on
  child variations, which do not map onto a single local product. They are
  counted and reported as skipped.

## What it stores

Beyond name, SKU, price and stock, each synced product keeps:

| Field             | From                                                                                                            |
| ----------------- | --------------------------------------------------------------------------------------------------------------- |
| GTIN / EAN / UPC  | `global_unique_id`, falling back to `_gtin`, `_ean` or `_upc` product meta on stores older than WooCommerce 9.2 |
| Brand             | the first of `brands[]`, kept as a row and matched on the store's own id                                        |
| Categories        | the store's category tree, read from `products/categories` so nesting is kept, matched on the store's own id    |
| Short description | `short_description`, markup stripped                                                                            |
| Description       | `description`, markup stripped                                                                                  |
| Primary image     | first entry of `images[].src`                                                                                   |
| Gallery images    | the remaining `images[].src`                                                                                    |
| Store link        | `permalink`                                                                                                     |

Images are stored **as links only** — the files stay on the WooCommerce store
and are loaded from there by the browser.

## Production requirements

Scheduled syncs need **two processes that are not part of `artisan serve`**.
Without both, a schedule can be saved but will never fire.

A cron entry running the scheduler every minute:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

And a supervised queue worker:

```
php artisan queue:work --tries=3 --timeout=900
```

Locally, `composer dev` already runs both — it starts the server, a queue
worker, the scheduler, logs and Vite.

## Constraints worth knowing

- **WooCommerce requires HTTPS** for consumer key and secret authentication.
  Over plain HTTP it mandates OAuth 1.0a, which this app does not implement,
  so a non-TLS store cannot be synced. Plain HTTP addresses are accepted only
  in the `local` environment, for testing against a fake store.
- **Credentials are encrypted with `APP_KEY`.** Rotating that key leaves them
  undecryptable; the settings page will ask for them to be entered again.
  There is no recovery.
- **SQLite** is fine for development and a small install, but a queue worker
  and web traffic writing concurrently is the weakest link here. Move to
  MySQL or PostgreSQL before this feature sees large catalogs.
- A product that changes from simple to variable in WooCommerce is skipped and
  then archived locally, since it is no longer importable.

## Configuration

| Variable                | Default | Purpose                                                |
| ----------------------- | ------- | ------------------------------------------------------ |
| `WOOCOMMERCE_PER_PAGE`  | 100     | Products fetched per API request.                      |
| `WOOCOMMERCE_RETRIES`   | 3       | Attempts per request for transient failures.           |
| `WOOCOMMERCE_MAX_PAGES` | 500     | Hard stop, so a misbehaving store cannot loop forever. |

### Queue visibility and incomplete catalogs

The sync job timeout is 900 seconds. Keep `DB_QUEUE_RETRY_AFTER`,
`REDIS_QUEUE_RETRY_AFTER`, or `BEANSTALKD_QUEUE_RETRY_AFTER` above that timeout;
the defaults are 960 seconds. Existing environment overrides must also be
updated. SQS deployments need an equivalent visibility timeout. Rebuild cached
configuration and restart supervised workers when deploying configuration changes.

A sync rejects malformed catalog responses, redirects, and catalogs that reach
`WOOCOMMERCE_MAX_PAGES` before completion can be established. Product count
mismatches also prevent archiving. Pages already imported remain saved, but unseen
products are not archived on these failures. Configure the store's final canonical
URL if its previous address redirects.

## Near-real-time updates with webhooks

Run `php artisan migrate` when deploying this feature and restart queue workers
with `php artisan queue:restart`. Webhooks use the same queue worker as full syncs.
Set `APP_URL` to the public HTTPS address of this app. The delivery URL must be
reachable from the WooCommerce server; localhost cannot receive remote events.

1. Open the shop's **Sync** settings. Enter a random **Webhook secret** of at
   least 32 characters and save the connection. This secret is separate from
   the read-only REST API credentials and is encrypted at rest. Blank means
   keep the saved secret; changing the store address clears it unless a new
   secret is supplied.
2. Copy the **Webhook delivery URL** displayed below the secret field.
3. In WooCommerce → **Settings → Advanced → Webhooks**, create four active
   webhooks: **Product created**, **Product updated**, **Product deleted**, and
   **Product restored**. Use the same delivery URL and secret for each, with
   the current WooCommerce REST API payload version. Save each webhook.
4. Edit a published simple product in WooCommerce and check the local product
   after the queue worker processes the notification. Keep a daily or hourly
   full sync enabled as reconciliation.

The endpoint verifies the HMAC-SHA256 signature against the exact request body.
WooCommerce's unsigned activation ping returns 200 but never queues product work.
Authenticated events are recorded before queueing and acknowledged with 202;
finished duplicate deliveries do no work. Pending duplicates and retries are
safe to process. Jobs fetch the product's **current** REST API state, including
for deletion notifications, so a delayed deletion cannot archive a restored
product. Only a recognized WooCommerce missing-product response, an unpublished
product, or a non-simple product archives the corresponding local product.
Proxy errors, authentication errors and malformed responses never archive it.

Full and individual sync jobs share a per-shop lock. Busy jobs wait and retry;
transient failures use backoff, and rate limits honor Retry-After. Failed jobs
appear in Laravel's failed-job storage and can be retried with
`php artisan queue:retry <id>`. Individual updates are not full catalog runs and
therefore do not appear in the Recent syncs table. Delivery receipts are retained
in `woocommerce_webhook_deliveries`; they contain IDs and timestamps, not product
payloads or secrets. Deleting a connection cascades its receipts.

Webhooks improve latency but do not replace reconciliation. Category hierarchy
and standalone taxonomy changes are refreshed by full syncs. Stock changes made
by extensions that bypass normal WooCommerce product events may also require
reconciliation. WooCommerce may disable repeatedly failing webhooks; inspect
**WooCommerce → Status → Logs → webhooks-delivery** and re-enable after fixing
connectivity or secrets. See the [official webhook setup guide](https://woocommerce.com/document/webhooks/).
