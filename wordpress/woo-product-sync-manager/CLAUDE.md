# Product Sync Manager for WooCommerce

The WooCommerce side of Product Sync Manager (the Laravel app at the root of this
repository). The app imports Nalda and Temu orders into WooCommerce over REST and reports
their delivery state back to the marketplaces; this plugin shows those orders inside
WooCommerce and keeps the delivery state up to date. It replaces the old
"WooCommerce Nalda Marketplace Sync" plugin (3agApp/woo-nalda-sync) and its license relay.

- Text domain / slug: `woo-product-sync-manager`
- PHP namespace: `WooProductSyncManager\` (PSR-4 → `includes/`)
- Prefix: `wpsm` — constants, hooks, options, meta keys.
- Built the way `woo-organization-accounts-pro` (SourovCodes) is; follow that plugin's
  `CLAUDE.md` for anything this file does not cover.

## What it does

| Piece | Class |
|---|---|
| Names every meta key the app writes | `Orders\Meta` |
| Reads a marketplace order / its amounts | `Orders\MarketplaceOrder`, `Orders\Money` |
| "Marketplace" column, "Nalda (Anna Muster)" buyer name | `Admin\OrderColumn` |
| Order panel: amounts, payout, ship-by, delivery fields, delivery note link; hides line bookkeeping | `Admin\OrderPanel` |
| Delivery state worked out on every order save | `Delivery\Status` |
| Printable delivery note | `Delivery\Note`, `templates/delivery-note.php` |
| No customer emails for marketplace orders | `Emails\CustomerEmails` |
| Marketplace block in the shop's own emails | `Emails\AdminDetails` |
| Buyer email into the Planzer CSV (`planzer/csv/content`) | `Integrations\Planzer` |
| Copies the old plugin's `_nalda_*` meta onto `_wpsm_*` (Action Scheduler) | `Migration\LegacyNalda` |
| Updates from this repository's GitHub releases | `Updates\Updater` |

**The meta keys are a contract with the Laravel app.** `Orders\Meta` here and the order
builders in `app/Actions/Temu` / `app/Actions/Nalda` must spell them the same way. Change
both sides together.

**Delivery state rules** (`Delivery\Status::derive()`): completed → DELIVERED, cancelled →
CANCELLED, refunded → RETURNED, a tracking code (Planzer writes `planzer_tracking_code` when
staff move an order to "Transmit to Planzer") → IN_DELIVERY, processing → IN_PREPARATION,
anything else keeps the current state. A state chosen in the panel sets
`_wpsm_delivery_status_manual` and is never overwritten until switched back to automatic.

## Requirements

WordPress 7.0, WooCommerce 11, PHP 8.2, **HPOS required** (the plugin stays inert without
it). No theme requirement — everything is in wp-admin. toysonline.ch runs PHP 8.2 with HPOS.

## Development

```bash
cd wordpress/woo-product-sync-manager
composer install
# Once: a WordPress + WooCommerce install for the tests, against Herd's MySQL (root, no password).
WP_ROOT="$HOME/.wp-tests/wpsm-wordpress" DB_PASS= DB_NAME=wpsm_tests ./bin/install-wp-tests-ci.sh
composer check   # validate, version, i18n, lint, tests — what CI runs
composer lint:fix
composer i18n    # after changing any translatable string; then translate both German catalogues
composer build   # dist/woo-product-sync-manager-<version>.zip and dist/update.json
```

The Laravel app's tools ignore this directory (`pint.json`, `vite.config.ts`); its
`sloppy` hook still runs on edits here and treats the plugin as its own project.

## Standards

`phpcs.xml.dist` (WooCommerce-Core) is the authority: tabs, Yoda conditions, a docblock on
every file, class and method, every user-facing string translated with the plugin's text
domain. Sanitise input, escape output, nonce **and** capability check on every write
(`edit_shop_orders`). English and German (`de_DE` du, `de_DE_formal` Sie) are both required;
`tests/I18nTest.php` fails on an untranslated string. Other German locales (`de_CH`, the
shop's) are mapped onto those two by `Plugin::map_german_locale()`.

## Releases

Plugin releases are this repository's only GitHub releases, tagged `plugin-v<version>`.
Bump the `Version:` header and `WPSM_VERSION` together (`bin/check-version.sh` checks), merge,
then push an annotated tag — its message is the release notes:

```bash
git tag -a plugin-v0.2.0 --cleanup=verbatim -F notes.md && git push origin plugin-v0.2.0
```

`.github/workflows/plugin-release.yml` runs the plugin CI on the tag, builds the zip and
publishes it with `update.json`, which installed copies read from
`releases/latest/download/update.json`. Bump `WPSM_DB_VERSION` when an upgrade has to
rewrite stored data, so `Migration\LegacyNalda` (or a later step) runs again.

## Production

toysonline.ch can be read over `ssh fly@toysonline.ch` (Docker, container
`toysonlinech-php-1`, wp-cli from `/var/www/html`) — **read only**. Plugin installs, updates
and settings are done in wp-admin by the shop, never over SSH.
