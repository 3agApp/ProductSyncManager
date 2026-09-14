# Nalda marketplace export

Any shop can send its products to [Nalda](https://nalda.com) as a CSV file,
uploaded to Nalda's SFTP server or downloaded and uploaded by hand in the
Seller Portal. Export is **push only**: this app is the source of truth for
what Nalda lists.

## Setting a shop up

1. Settings → Shops → _shop_ → **Nalda settings**.
2. Enter the SFTP user and password from the Nalda Seller Portal
   (**Products**). Every seller uploads to `sftp.nalda.com:2022`, so only the
   user and password are stored per shop. The password is encrypted.
   Credentials are optional — without them the file can still be downloaded.
3. Set the product defaults Nalda requires and the catalog does not store:
   country, currency, condition, delivery time and return period. The tax
   rate comes from the shop's tax settings (see below).
4. Choose the **default product behavior**: include all products, or exclude
   all products.
5. Press **Test connection**, then **Upload now**. With **Upload after every
   sync** on, a file is also uploaded each time a WooCommerce sync finishes.

Owners and admins change the settings. Owners, admins and members can upload
and download.

## Which products are listed

Every product can be set to **Follow shop default**, **Always include** or
**Always exclude** — on its edit page, or for many at once from the bulk bar
on the product list. A product is listed when:

- it has a GTIN, **and**
- it is **Active**, **and**
- its price is above 0, **and**
- it is set to always include, or it follows the default and the default is
  include all.

## Why removals are explicit

Nalda never removes a product because a file leaves it out. It only removes a
product when a row says so — `delete_product = yes`, `stock = 0` or
`delivery_time_days = 0`. So every file contains **every product with a
GTIN**:

| Product                                      | Row                                               |
| -------------------------------------------- | ------------------------------------------------- |
| Listed                                       | full details, real stock (0 stays listed)         |
| Excluded, draft, archived or priced 0        | full details, `stock = 0`, `delete_product = yes` |
| A GTIN listed before that no product has now | GTIN only, `stock = 0`, `delete_product = yes`    |
| No GTIN                                      | not written; counted as skipped                   |

The last row covers products deleted from this app, and products whose GTIN
was changed or cleared. The GTINs listed by the last **successful** upload are
kept in `nalda_listed_gtins` for this; a failed upload leaves them unchanged,
so the next one sends the removals again.

When two products share a GTIN only one row is written, preferring the listed
one, and the other is counted as skipped.

Products listed on Nalda before this export was set up are unknown to it. If
the catalog was uploaded some other way before, clear it once with **Alle
Produkte löschen** in the Seller Portal and then upload from here.

## Columns

The header row is exactly the one in Nalda's product template, all 37 columns
in the same order. Columns the catalog has no value for yet — `category`,
`google_category`, the dimensions and weights, `volume_ml`, `size`, `colour`
and the book fields — are written empty.

Filled from the catalog: `gtin`, `title`, `price`, `stock`, `main_image_url`,
`brand`, `seller_category` (the first category path), `description` (falling
back to the short description), `image_2_url` to `image_5_url` (the first four
gallery images) and `delete_product`. Filled from the shop's Nalda settings:
`country`, `condition`, `tax`, `currency`, `delivery_time_days` and
`return_days`.

## Prices and tax

Every shop has tax settings on its settings page: whether prices are entered
**including or excluding tax**, and its **standard tax rate**. A WooCommerce
sync copies both from the store (`woocommerce_prices_include_tax` and the
standard-class rate, preferring a Swiss one); a key that may not read tax
settings leaves what the shop has and the sync carries on.

- `tax` is the rate Nalda accepts, `8.1` or `2.6`, closest to the shop's rate.
- `price` is always written with two decimals (`11.60`). Nalda shows prices
  with VAT included, so:
    - when the shop's prices include tax, they are sent as they are;
    - when they exclude tax, the Nalda page offers **Send prices as they are**
      or **Add VAT to prices**, which applies the shop's rate and rounds to
      0.05 CHF.

Files are uploaded to the SFTP root under the file name set on the Nalda page
(`products.csv` by default), replacing the previous upload.

## Production requirements

Uploads run on the queue worker, and share a lock with the shop's sync so a
file is never built from a half-synced catalog. The scheduler fails uploads
that stall for more than an hour. See [product-sync.md](product-sync.md) for
the cron entry and worker.
