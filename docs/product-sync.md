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
   Read permission is enough for product imports. Automatic webhook setup also
   needs write permission; the app reports insufficient permissions when you try setup.
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
- Before archiving, each synced product the run did not see is looked up by id.
  Pages are read by offset, so a product deleted mid-run can push a live one
  past a page boundary; a product the store still publishes is imported
  instead of archived. Products added or removed during a run do not fail it.
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

And Horizon, kept running by Supervisor. Queues run on Redis
(`QUEUE_CONNECTION=redis`); worker processes and the 900-second timeout are set
in `config/horizon.php`:

```
php artisan horizon
```

Locally, `composer dev` already runs both — it starts the server, Horizon,
the scheduler, logs and Vite. The dashboard is at `/horizon`; outside `local`
only the emails in `HorizonServiceProvider` can open it.

## Constraints worth knowing

- **WooCommerce requires HTTPS** for consumer key and secret authentication.
  Over plain HTTP it mandates OAuth 1.0a, which this app does not implement,
  so a non-TLS store cannot be synced. Plain HTTP addresses are accepted only
  in the `local` environment, for testing against a fake store.
- **Stores must be on public addresses.** Every request resolves the store
  host again, refuses it if any address is private, loopback, link-local or
  otherwise reserved, and pins the connection to the addresses it checked.
  A store whose DNS starts pointing inside the network fails its next sync
  without retrying. Set `WOOCOMMERCE_ALLOW_PRIVATE_HOSTS` only to reach a
  fake store locally.
- **Credentials are encrypted with `APP_KEY`.** Rotating that key leaves them
  undecryptable; the settings page will ask for them to be entered again.
  There is no recovery.
- **SQLite** is fine for development and a small install, but a queue worker
  and web traffic writing concurrently is the weakest link here. Move to
  MySQL or PostgreSQL before this feature sees large catalogs.
- A product that changes from simple to variable in WooCommerce is skipped and
  then archived locally, since it is no longer importable.

## Configuration

| Variable                          | Default                      | Purpose                                                                                           |
| --------------------------------- | ---------------------------- | ------------------------------------------------------------------------------------------------- |
| `WOOCOMMERCE_PER_PAGE`            | 100                          | Products fetched per API request.                                                                 |
| `WOOCOMMERCE_RETRIES`             | 3                            | Attempts per request for transient failures.                                                      |
| `WOOCOMMERCE_MAX_PAGES`           | 500                          | Hard stop, so a misbehaving store cannot loop forever.                                            |
| `WOOCOMMERCE_ALLOW_PRIVATE_HOSTS` | on only when `APP_ENV=local` | Allow stores on private or loopback addresses, for a local fake store. Leave off everywhere else. |

### Queue visibility and incomplete catalogs

The sync job timeout is 900 seconds. Keep `DB_QUEUE_RETRY_AFTER`,
`REDIS_QUEUE_RETRY_AFTER`, or `BEANSTALKD_QUEUE_RETRY_AFTER` above that timeout;
the defaults are 960 seconds. Existing environment overrides must also be
updated. SQS deployments need an equivalent visibility timeout. Rebuild cached
configuration and restart supervised workers when deploying configuration changes.

A sync rejects malformed catalog responses, redirects, and catalogs that reach
`WOOCOMMERCE_MAX_PAGES` before completion can be established, as does a failed
lookup of unseen products. Pages already imported remain saved, but unseen
products are not archived on these failures. Configure the store's final canonical
URL if its previous address redirects.

## Near-real-time updates with webhooks

Run `php artisan migrate` when deploying this feature and restart Horizon
with `php artisan horizon:terminate`. Webhooks use the same queue as full syncs.
Set `APP_URL` to the public HTTPS address of this app. The delivery URL must be
reachable from the WooCommerce server; localhost cannot receive remote events.

Open the shop's **Sync** settings, save your API credentials, then click
**Set up webhooks automatically**. The app creates or repairs four active
webhooks: product created, updated, deleted, and restored. It generates an
encrypted secret if needed, and reuses the existing secret and matching webhooks
on subsequent attempts. The webhook user must be able to manage webhooks and
the key must have Read/Write access. Permission errors appear only after clicking;
there is no separate permission setup step.

The button uses the **saved** credentials, so save changes to the key or store
address before clicking. **Check and repair webhooks** can reactivate disabled
webhooks and update managed delivery URLs after `APP_URL` changes. Other webhook
destinations are left alone. Partial progress is kept. A timed-out creation is
not automatically repeated: the next attempt lists remote webhooks first to find
any webhook that was created before the response was lost. A shared lock prevents
simultaneous setup attempts or credential changes during setup. The success
message records the last completed setup, not continuous webhook health.

For manual setup, expand **Manual webhook setup**, enter a random secret of at
least 32 characters and click **Save manual webhook settings**. Create the four
active product webhooks in WooCommerce → Settings → Advanced → Webhooks using
the displayed URL and the same secret. Blank keeps the saved secret. Changing
the store address clears the secret and managed webhook references; changing a
secret clears the successful-setup status until setup is run again.

Edit a published simple product in WooCommerce and check the local product
after the queue worker processes the notification. Keep a daily or hourly full
sync enabled as reconciliation.

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

### Choosing a schedule

The schedule form previews the selected run times before you save:

- **Hourly:** choose a minute, such as `:15`, to run at 9:15, 10:15, and so on.
- **Every 6 hours:** choose one run time; the preview lists all four daily runs.
- **Daily:** choose the time to run each day.
- **Weekly:** choose a weekday and time.

Times use the timezone shown below the controls. New schedules default to the
browser's timezone when supported. Existing schedules keep their saved timezone;
use **Change timezone** to choose another, or **Use my timezone** to switch back.
The preview updates as you edit, but changes take effect only after **Save
schedule**. Turning off scheduled sync does not stop webhooks or manual sync.
