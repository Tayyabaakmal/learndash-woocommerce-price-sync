# LearnDash × WooCommerce Price Sync

Makes LearnDash always display the **live** price of a course's linked
WooCommerce product — regular price, active sale, or scheduled sale —
instead of the manually entered LearnDash "Course Price" field.

No more editing the price in two places. Set the price (and any sale)
on the WooCommerce product as usual, and every LearnDash course page,
grid, archive, and widget stays in sync automatically.

## Why

If you sell courses through **WooCommerce for LearnDash**, the course
price shown on the LearnDash side is a separate, manually-typed field.
It's easy to forget to update it when you change the WooCommerce
product's price or run a sale — so students see one price on the
course page and a different one at checkout.

This plugin fixes that by pulling the price straight from WooCommerce
at render time, using WooCommerce's own `get_price()`,
`get_regular_price()`, and `is_on_sale()` — so it's always correct,
including for scheduled sales that start/end automatically.

## Requirements

- [LearnDash LMS](https://www.learndash.com/)
- [WooCommerce](https://woocommerce.com/)
- [WooCommerce for LearnDash](https://www.learndash.com/add-on/woocommerce/) add-on
  (this plugin reads the `_related_course` product meta it creates —
  no extra configuration needed per course)

## Installation

**Option 1 — mu-plugin (recommended)**
Upload `learndash-woocommerce-price-sync.php` to
`/wp-content/mu-plugins/`. It'll be active automatically and can't be
accidentally deactivated.

**Option 2 — regular plugin**
Upload the file to `/wp-content/plugins/learndash-woocommerce-price-sync/`
and activate it from **Plugins** in wp-admin.

**Option 3 — code snippet plugin (e.g. WPCode)**
Paste the file's contents into a new PHP snippet. **Remove the opening
`<?php` tag** first — most snippet plugins add it automatically, and
leaving it in can cause a parse error that silently disables the
snippet.

After installing, it's a good idea (not required) to clear each
course's "Course Price" field under **LearnDash → Settings → Access &
Pricing**, since it's no longer read — this just avoids confusing
future editors.

## How it works

LearnDash builds every price display through one central function,
`learndash_get_course_price()`, filterable via
`learndash_get_course_price`. This plugin hooks that filter and, if
the course has a linked WooCommerce product, replaces the price with
markup built from the product's live price data.

The course ↔ product relationship is read from the standard
`_related_course` product meta that **WooCommerce for LearnDash**
already creates, so there's nothing new to set up per course.

## Notes & gotchas

- **Page/CDN caching** — if the site uses a page cache (WP Rocket,
  LiteSpeed Cache, Cloudflare, etc.), make sure course pages are
  excluded or have a short TTL around scheduled sale start/end times,
  the same way you'd handle WooCommerce product pages. Otherwise a
  *cached* course page can lag behind the live price until the cache
  clears.
- **A course showing an unstyled, un-synced price** almost always
  means that course isn't linked to a WooCommerce product — check the
  product's "WooCommerce for LearnDash" field and confirm the course
  is selected there.
- **No sale price shown for a course** — the plugin only shows the
  struck-through "old price / new price" format when
  `is_on_sale()` is true for the linked product. If a course shows
  just a single price, the linked product currently doesn't have an
  active sale price set.
- **The bundled styling is intentionally `!important`-heavy** (color,
  weight, size, and spacing) because this markup gets dropped into an
  unknown theme's price row — often nested inside an `<a>` tag — and
  themes commonly have their own higher-specificity rules for that
  spot. If you want the price to look different, target
  `.ldwc-price`, `.ldwc-old`, `.ldwc-new`, or `.ldwc-regular` in your
  own theme CSS with an equal-or-higher-specificity `!important` rule.

## License

MIT — use it, modify it, ship it.
