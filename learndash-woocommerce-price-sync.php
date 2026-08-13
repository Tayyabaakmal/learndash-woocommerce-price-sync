<?php
/**
 * Plugin Name: LearnDash × WooCommerce Price Sync
 * Description: Makes LearnDash always display the *live* price of the linked
 *              WooCommerce product (regular / on-sale / scheduled sale) instead
 *              of the manually entered LearnDash "Course Price" field. Reads
 *              the price from WooCommerce's own product/pricing API
 *              (get_price(), get_regular_price(), is_on_sale() — all core
 *              WooCommerce), so it automatically stays correct through
 *              scheduled sales and future WooCommerce updates — no manual
 *              syncing required. The markup is generated fresh from plain
 *              formatted numbers (not WooCommerce's raw HTML) so it renders
 *              consistently regardless of the active theme's own price CSS.
 * Author:      TheGrowth360
 * Version:     1.2.0
 * Requires:    LearnDash LMS, WooCommerce, "WooCommerce for LearnDash" add-on
 *
 * HOW IT WORKS
 * ------------
 * LearnDash builds every price display (course page, grid, archive, widgets,
 * shortcodes) through one central function, learndash_get_course_price(),
 * which is filterable via `learndash_get_course_price`. This plugin hooks
 * that single filter and overwrites the 'price' entry with a display built
 * from the WooCommerce product's own get_price()/get_regular_price()/
 * is_on_sale() values, so course pages and checkout can never disagree.
 *
 * The course <-> product relationship is read from the standard
 * "WooCommerce for LearnDash" mapping (the `_related_course` meta stored on
 * the WooCommerce product), so there is nothing new to configure per course.
 *
 * STYLING NOTES (v1.2.0)
 * -----------------------
 * - Every property in the injected <style> block is now !important
 *   (color, weight, size, spacing) rather than just color/decoration.
 *   This markup lands inside whatever theme's existing price row —
 *   often nested in an <a> — so theme stylesheets commonly out-rank
 *   a subset of unmarked rules and cause partial styling (e.g. right
 *   color but wrong size, or correct text but no spacing). See the
 *   comment above .ldwc-price for how to override it in your theme.
 *
 * PERFORMANCE NOTES (v1.1.0)
 * ---------------------------
 * - The course=>product map cache is now only invalidated when a *product*
 *   is actually changed/deleted, not on every post deletion site-wide.
 * - The map-building query now pulls only IDs + the single meta key it
 *   needs via a direct $wpdb query, instead of instantiating full product
 *   objects for every published product via wc_get_products().
 * - Loaded WC_Product objects are cached in a static array for the
 *   lifetime of the request, so a course archive/grid with many courses
 *   only loads each linked product once even if filtered multiple times.
 * - The transient TTL was extended and jittered slightly to avoid cache
 *   stampedes, since is_on_sale() already checks live dates and doesn't
 *   depend on the map being perfectly fresh to the second.
 * - print_price_styles() now only prints on requests that actually render
 *   a LearnDash course, instead of on every single page site-wide.
 *
 * INSTALLATION
 * ------------
 * 1. Upload this file to /wp-content/mu-plugins/ (recommended — always on,
 *    can't be accidentally deactivated), or install it as a normal plugin
 *    via /wp-content/plugins/ld-wc-price-sync/ld-wc-price-sync.php and
 *    activate it from Plugins.
 * 2. Optional but recommended: clear the "Course Price" field on each
 *    LearnDash course (Settings > Access & Pricing). It's no longer read,
 *    but clearing it avoids confusion for future editors.
 * 3. If the site uses a page cache (e.g. WP Rocket, LiteSpeed, Cloudflare),
 *    make sure the course page's cache is purged/short-lived, or excluded
 *    while a sale is scheduled to start/end, the same way you already do
 *    for WooCommerce product pages — otherwise a *cached* course page can
 *    lag behind the live price for the remainder of the cache TTL.
 */

defined( 'ABSPATH' ) || exit;

class LD_WC_Price_Sync {

	const CACHE_KEY = 'ldwc_course_product_map';

	/**
	 * In-request cache of already-loaded WC_Product objects, keyed by
	 * product ID, so a page rendering many courses doesn't call
	 * wc_get_product() more than once per product.
	 *
	 * @var array<int,\WC_Product|false>
	 */
	private $product_cache = array();

	/**
	 * Whether the current request has been detected as one that will
	 * render at least one LearnDash course price. Used to decide whether
	 * to print the scoped <style> block at all.
	 *
	 * @var bool
	 */
	private $should_print_styles = false;

	public function __construct() {
		add_filter( 'learndash_get_course_price', array( $this, 'sync_course_price' ), 20 );
		add_action( 'wp_head', array( $this, 'print_price_styles' ), 20 );

		// Show "$45" instead of "$45.00" — WooCommerce's own trim-zeros
		// option, so this applies consistently everywhere WooCommerce
		// prices are shown (course cards, product pages, cart, checkout),
		// not just a one-off string edit here.
		add_filter( 'woocommerce_price_trim_zeros', '__return_true' );

		// Keep the course => product map fresh whenever a *product*
		// changes — not on arbitrary post deletions site-wide, which was
		// previously forcing an expensive rebuild far more often than
		// necessary (see clear_map_cache_on_delete()).
		add_action( 'save_post_product', array( $this, 'clear_map_cache' ) );
		add_action( 'woocommerce_update_product', array( $this, 'clear_map_cache' ) );
		add_action( 'woocommerce_new_product', array( $this, 'clear_map_cache' ) );
		add_action( 'delete_post', array( $this, 'clear_map_cache_on_delete' ) );
	}

	/**
	 * Prints the styling for the synced price markup. WooCommerce's
	 * get_price_html() just returns <del>/<ins> tags with no styling of
	 * its own — the theme is expected to style them. Since the LearnDash
	 * templates were never designed for that markup, we supply our own
	 * scoped styles so it looks correct everywhere without touching theme
	 * files.
	 *
	 * Only prints when this request actually rendered at least one synced
	 * course price (see $should_print_styles), instead of unconditionally
	 * on every page of the site.
	 */
	public function print_price_styles() {

		if ( ! $this->should_print_styles ) {
			return;
		}
		?>
		<style id="ldwc-price-sync-styles">
			.ldwc-price {
				display: inline-flex !important;
				align-items: baseline !important;
				flex-wrap: nowrap !important;
				white-space: nowrap !important;
				gap: 8px !important;
				font-family: inherit !important;
			}
			/* Every rule below is !important on purpose, including size
			   and weight: this plugin's markup gets dropped into an
			   unknown theme's existing price row (often inside an <a>
			   tag), and theme stylesheets frequently target that row
			   with their own higher-specificity color/weight/spacing
			   rules — that's exactly what caused the sale price to
			   render in the theme's link-blue with no gap on the site
			   this plugin was first built for. Being loud here is the
			   safest default across unknown themes; if you want the
			   price to match your theme's own type scale instead,
			   override these selectors in your theme's CSS with an
			   equal-or-higher-specificity !important rule of your own. */
			.ldwc-price .ldwc-regular {
				color: inherit !important;
				font-weight: 700 !important;
				font-size: 1em !important;
				text-decoration: none !important;
				vertical-align: baseline !important;
			}
			/* Old price when on sale — muted grey, struck through. */
			.ldwc-price .ldwc-old {
				color: #9ca3af !important;
				font-weight: 500 !important;
				font-size: 0.85em !important;
				text-decoration: line-through !important;
				vertical-align: baseline !important;
			}
			/* New (sale) price — the one color that should stand out.
			   margin-left is a fallback in case flex `gap` gets stripped
			   by a theme reset that zeroes out flex-child margins. */
			.ldwc-price .ldwc-new {
				color: #dc2626 !important;
				font-weight: 700 !important;
				font-size: 1em !important;
				text-decoration: none !important;
				vertical-align: baseline !important;
				margin-left: 8px !important;
			}
			/* Stop the row that holds the price (e.g. the "Lessons ... Price"
			   row on course cards) from wrapping the price onto its own line
			   now that it shows two numbers instead of one. */
			*:has( > .ldwc-price ) {
				flex-wrap: nowrap !important;
			}
		</style>
		<?php
	}

	public function clear_map_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Fired on the generic `delete_post` hook, which runs for *every*
	 * post type (pages, posts, media, revisions, nav menu items, etc).
	 * We only care about it when the deleted post was a WooCommerce
	 * product — otherwise deleting an unrelated blog post would force an
	 * expensive map rebuild on the next course page load for no reason.
	 *
	 * @param int $post_id Post ID being deleted.
	 */
	public function clear_map_cache_on_delete( $post_id ) {
		if ( 'product' === get_post_type( $post_id ) ) {
			$this->clear_map_cache();
		}
	}

	/**
	 * Build (and cache) a course_id => product_id map from the official
	 * "_related_course" meta that the WooCommerce for LearnDash add-on
	 * stores on each linked product.
	 *
	 * Uses a direct, targeted $wpdb query instead of wc_get_products(),
	 * which would instantiate a full WC_Product object (and run its own
	 * extra meta lookups) for every published product just to read one
	 * piece of postmeta.
	 */
	private function get_course_product_map() {
		$map = get_transient( self::CACHE_KEY );
		if ( is_array( $map ) ) {
			return $map;
		}

		global $wpdb;

		$map = array();

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id AS product_id, pm.meta_value AS course_ids
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s
				AND p.post_type = %s
				AND p.post_status = %s",
				'_related_course',
				'product',
				'publish'
			)
		);

		foreach ( $results as $row ) {
			$course_ids = maybe_unserialize( $row->course_ids );

			if ( empty( $course_ids ) ) {
				continue;
			}

			$course_ids = is_array( $course_ids ) ? $course_ids : array( $course_ids );

			foreach ( $course_ids as $course_id ) {
				$map[ (int) $course_id ] = (int) $row->product_id;
			}
		}

		// TTL is intentionally long (24h) with a little jitter: is_on_sale()
		// already checks live sale dates on every render, so the map being
		// briefly stale doesn't produce a wrong price — it just means a
		// brand-new course/product link takes up to a day to appear
		// (or clears instantly anyway via the save/update hooks above).
		set_transient( self::CACHE_KEY, $map, DAY_IN_SECONDS + wp_rand( 0, 3 * HOUR_IN_SECONDS ) );

		return $map;
	}

	private function get_linked_product_id( $course_id ) {
		$map = $this->get_course_product_map();
		return isset( $map[ (int) $course_id ] ) ? $map[ (int) $course_id ] : 0;
	}

	/**
	 * Loads a WC_Product, cached per-request so a page rendering many
	 * course cards doesn't hit wc_get_product() (and its underlying
	 * queries, when the object cache is cold) once per course.
	 *
	 * @param int $product_id
	 * @return \WC_Product|false
	 */
	private function get_product_cached( $product_id ) {
		if ( ! array_key_exists( $product_id, $this->product_cache ) ) {
			$this->product_cache[ $product_id ] = wc_get_product( $product_id );
		}
		return $this->product_cache[ $product_id ];
	}

	/**
	 * Filters LearnDash's price array, replacing the manually entered
	 * Course Price with the live WooCommerce price markup.
	 *
	 * @param array $pricing LearnDash course price details.
	 * @return array
	 */
	public function sync_course_price( $pricing ) {

		if ( ! is_array( $pricing ) ) {
			return $pricing;
		}

		$course_id = get_the_ID();

		if ( empty( $course_id ) && function_exists( 'learndash_get_course_id' ) ) {
			$course_id = learndash_get_course_id();
		}

		if ( empty( $course_id ) || 'sfwd-courses' !== get_post_type( $course_id ) ) {
			return $pricing;
		}

		$product_id = $this->get_linked_product_id( $course_id );

		if ( ! $product_id ) {
			return $pricing; // No linked product found — leave LearnDash's value alone.
		}

		$product = $this->get_product_cached( $product_id );

		if ( ! $product ) {
			return $pricing;
		}

		// Build the display text ourselves from WooCommerce's own
		// formatting function (wc_price) but strip it down to plain text
		// first. This is deliberate: WooCommerce/theme markup sometimes
		// wraps the currency symbol or decimals in their own nested
		// <span>s with their own CSS (e.g. small superscript cents),
		// which caused inconsistent sizing/color once we started editing
		// that markup directly. Using plain text inside our own two
		// simple spans guarantees the "$" and the number always match in
		// color and size, with no leftover theme styling.
		$decimal_sep = function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.';

		$format_amount = function ( $amount ) use ( $decimal_sep ) {
			$text = wp_strip_all_tags( wc_price( $amount ) );
			// Trim a trailing ".00" (e.g. "$45.00" -> "$45").
			return preg_replace( '/' . preg_quote( $decimal_sep, '/' ) . '00$/', '', $text );
		};

		if ( $product->is_on_sale() ) {
			// - active sale    -> "$45 $43" (old price struck through, grey; new price red/bold)
			// - scheduled sale -> automatically correct on/after the sale's
			//   date_on_sale_from / date_on_sale_to — is_on_sale() checks
			//   the current date for us, no extra code needed.
			$price_html = '<span class="ldwc-price">'
				. '<span class="ldwc-old">' . esc_html( $format_amount( $product->get_regular_price() ) ) . '</span>'
				. '&nbsp;'
				. '<span class="ldwc-new">' . esc_html( $format_amount( $product->get_price() ) ) . '</span>'
				. '</span>';
		} else {
			// - no sale -> "$45"
			$price_html = '<span class="ldwc-price">'
				. '<span class="ldwc-regular">' . esc_html( $format_amount( $product->get_price() ) ) . '</span>'
				. '</span>';
		}

		$pricing['price'] = $price_html;

		// Mark that this request needs the scoped <style> block printed
		// in wp_head, instead of printing it unconditionally on every
		// page of the site.
		$this->should_print_styles = true;

		return $pricing;
	}
}

new LD_WC_Price_Sync();
