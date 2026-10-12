# Temu marketplace sync

A shop can list its products on Temu through the shop's own **self-developed
app** on Temu's open platform. Sync is **push only**: this app creates the
products on Temu and keeps their stock, price and details in step with the
catalog.

## Setting a shop up

1. The seller creates a self-developed app in Temu Seller Center
   (**Open Platform → Authorization management**) and Temu reviews it.
2. Settings → Shops → _shop_ → **Temu settings**.
3. Enter the **app key** and **app secret** from that page. Once the app is
   approved, the seller authorizes it and Temu shows an **access token**;
   paste it in too. The secret and token are encrypted and never sent back to
   the browser; leave the fields blank to keep what is saved.
4. Press **Test connection**. It calls `bg.open.accesstoken.info.get`, which
   changes nothing on Temu, and remembers the store id and the token's expiry
   date. Temu tokens expire (about three months); the page warns when one has,
   and a new one is pasted in the same way.
5. Choose the **default product behavior**. New connections start at
   **exclude all**, so one product can be set to **Always include** and
   checked in Seller Center before the rest of the catalog goes out.
6. Press **Sync now**. With **Sync after every WooCommerce sync** on, a sync
   also runs each time a WooCommerce sync finishes, and five minutes after
   webhooks change products (batched the same way as Nalda uploads).

Owners and admins change the settings and test the connection. Owners, admins
and members can start a sync.

All requests go to Temu's EU gateway,
`https://openapi-b-eu.temu.com/openapi/router`, which serves Switzerland, the
EU and the UK. Set `TEMU_BASE_URL` for a store in another region.

## Which products are listed

Every product can be set to **Follow shop default**, **Always include** or
**Always exclude** — on its edit page, or for many at once from the bulk bar.
A product is listed when:

- it is **Active**, **and**
- its price is above 0, **and**
- it has a main image, **and**
- it is set to always include, or it follows the default and the default is
  include all.

## What a sync does

Every product sent to Temu has a row in `temu_products` recording Temu's ids,
where the product stands, and the stock, price and content last sent. A sync:

1. **Reads back statuses** for every product already on Temu with
   `bg.local.goods.list.query`, storing Temu's sub-status and the SKU id.
2. **Walks the catalog**:

| Product                                 | Request                                            |
| --------------------------------------- | -------------------------------------------------- |
| Listed, not on Temu yet                 | `temu.local.goods.v3.add`                          |
| Listed, stock changed                   | `bg.local.goods.stock.edit` (sets the exact stock) |
| Listed, price changed                   | `bg.local.goods.priceorder.change.sku.price`       |
| Listed, retail price changed            | `bg.local.goods.partial.update` with `listPrice`   |
| Listed, name or description changed     | `bg.local.goods.partial.update`                    |
| Listed again after being taken off sale | `bg.local.goods.sale.status.set` with `onsale = 1` |
| No longer listed                        | `bg.local.goods.sale.status.set` with `onsale = 0` |

3. **Takes products deleted from the catalog off sale**. Their row outlives
   the product for this.

Only what changed since the last successful request is sent, so a run where
nothing changed makes nothing but the status check.

### Creating products

The V3 add call takes what the catalog knows and lets Temu work out the rest:
the category (from the names of the categories the product is filed under),
required attributes, compliance and manufacturer details (from the name and
description), and the variant structure. Temu downloads the images from their
URLs. The product goes out with:

- `externalGoodsId` `psm-<product id>` — stable even if the SKU is edited;
- `externalSkuId` — the shop's SKU (or the goods id if the SKU is unusable);
- the name, description (or short description), main and gallery images (up
  to 10), the brand as a `Brand` attribute, and the GTIN as a barcode;
- the discounted base price, the shop price as the recommended retail price,
  and the stock, with package weight and size left at 0 so Temu uses its
  defaults (100 g, 10 × 20 × 30 cm);
- Temu's default fulfilment time and the store's default shipping template.

A created product first shows as **Processing**. About ten minutes later Temu
has either submitted it for review or kept it as a **Draft** because it needs
something (a required attribute, an image it could not download). Drafts,
rejected products and failed requests are listed under **Needs attention**
with Temu's reason; fix them in Seller Center or in the catalog.

Temu refuses updates while it is still processing, reviewing or pricing a
product, and stock and price need the SKU id it only reports afterwards, so
those changes wait. The scheduler syncs shops with products still processing
every 15 minutes until they settle.

A product Temu refused to create is not sent again until it is edited, so an
unfixable product does not cost a request every run.

### Prices

Temu sells below the shop price: the **Temu discount** on the Temu page (10%
by default) comes off the WooCommerce price, and a brand can set its own
discount on the Brands page instead (blank follows the Temu page, 0% sells at
the shop price). The result is rounded to the nearest 5 Rappen and sent as
the **base price**, in the currency on the Temu page (CHF by default). Temu
shows Swiss prices with VAT included, as the WooCommerce price already is.

The shop price itself is sent as the **recommended retail price**
(`listPrice`), which Temu only takes when it is above the base price, so a
product sold at 0% has none.

On a listed product a new base price is a **price change request**
(`bg.local.goods.priceorder.change.sku.price`): Temu can review it, suggest
another price, or ask the seller to confirm it in Seller Center, so it may
take a while to show. A refused change is shown on the product under **Needs
attention**. A new retail price goes through `bg.local.goods.partial.update`
(per SKU, without `basePrice`). While a lower base price is still under
review, Temu refuses a retail price that is not above the old one (codes
150010041 / 150010083); that is not treated as an error, and the retail price
is sent again on the next sync.

Lowering the discount to 0% leaves the retail price Temu already has in place.

A **draft** has no price order for a change to amend, so its price waits until
Temu submits it; its stock and details are still sent.

## Orders

WooCommerce fulfils Temu orders and owns the stock; this app only carries
them across. With **Import orders into WooCommerce** on, the scheduler checks
Temu every five minutes (`bg.order.list.v2.get`, orders updated since the last
check, with half an hour of overlap) and creates each new order in
WooCommerce once. Creating the order lowers the stock there, and the usual
webhook → product sync sends the new stock to Temu.

| Temu status        | What happens                                                    |
| ------------------ | --------------------------------------------------------------- |
| Pending (1)        | Waits: Temu can still cancel it or change the address           |
| To ship (2)        | Created in WooCommerce                                          |
| Cancelled (3)      | Cancelled in WooCommerce if imported, which puts the stock back |
| Shipped, delivered | Left as it is; never imported if first seen in that state       |

The WooCommerce order is:

- **processing** and paid, payment method **Temu**, `created_via` `temu`, the
  Temu parent order number as the transaction id and in `_temu_parent_order_sn`;
- **billed to Temu** (name and company "Temu") **without an email address**,
  so WooCommerce sends the buyer no emails;
- **shipped to the buyer**, from `bg.order.shippinginfo.v2.get`. The address is
  sent straight to WooCommerce and not stored here;
- priced at the **base price** Temu pays the shop: the price last sent to Temu
  (`temu_products.sent_price`). Temu's API has no call that returns the
  accepted base price, so while a price change is under review the new price
  is used. WooCommerce reads REST line totals as before tax, so a shop whose
  prices include tax sends them net and WooCommerce adds the tax back;
- given a **Temu** shipping line at **Shipping paid by Temu** (5.50 by
  default), the shipping Temu pays per order under _Sales proceeds_ in its
  order details. Temu's order API does not return it, so it is a setting on
  the Temu page. Like the base price it is taken to include tax, so the
  order total is what Temu pays (6.20 + 5.50 = 11.70 for the first order);
- given a private note with the ship-by time.

Lines are matched by Temu's SKU id, then by SKU code. A line whose product left
the catalog is sent by name only (no stock change) and the note says so; a line
with no known Temu price fails the order.

A failed import is listed under **Orders** on the Temu page with
**Import again**. An import cut off before WooCommerce answered stays as
**Check WooCommerce** and is never sent again on its own, since WooCommerce
may have created it; check the store before importing it again.

Not yet covered: shipping is still confirmed in Temu Seller Center
(tracking is not sent back with `bg.logistics.shipment.v2.confirm`), and
returns and refunds stay in Seller Center.

## Not covered yet

- **Variable products**: like the rest of the app, only simple products sync.
- **Package dimensions**: Temu's defaults are used until the catalog stores
  weight and size.
- **Products deleted on Temu** are left alone; recreate them in Seller Center.
- **Image changes** after a product is created: Temu refuses a content update
  that carries the shop's image URLs, so images stay as they were when the
  product was created. Syncing them would mean uploading them through Temu's
  image upload API first.

## To confirm once the app is approved

These come from Temu's public documentation and have not been tried against a
live store yet:

- that the self-developed app's permissions include `temu.local.goods.v3.add`
  (the older `bg.local.goods.add` is listed in Temu's example token scope);
- how Temu words credential and rate-limit errors — the client recognises them
  from the message, since Temu does not document the gateway's codes;
- whether a product without variations is accepted with an empty
  `variations` list;
- the `reason` Temu expects on a price change;
- that a retail price change is accepted without `listPriceType`, which Temu's
  documentation describes only as "empty or 0 means listPrice is required".

## Production requirements

Syncs run on the queue worker and share a lock with the shop's WooCommerce
sync, so products are never sent from a half-synced catalog. The scheduler
fails syncs that stall for more than two hours. See
[product-sync.md](product-sync.md) for the cron entry and worker.
