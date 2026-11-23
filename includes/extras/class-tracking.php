<?php
/**
 * Analytics events, product feeds and structured data.
 *
 * Analytics (all optional, off until you enter an id): Google Analytics 4,
 * Meta (Facebook) Pixel and TikTok Pixel receive view_item, add_to_cart,
 * begin_checkout and purchase events. A purchase is reported once per order.
 * To respect consent, name a cookie in the settings: nothing loads until that
 * cookie exists and is not "no", "false", "0" or "denied". Third-party scripts
 * load from the providers' own servers only when you enter an id.
 *
 * Feeds: /?sidccofo_feed=google (Google Merchant Center, also accepted by
 * Meta catalogs) and /?sidccofo_feed=csv. Cached for an hour.
 *
 * Structured data: schema.org Product JSON-LD with price, availability and
 * rating on product pages.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;
use function SidcraftCommerce\currency;
use function SidcraftCommerce\base_currency;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Tracking
 */
class Tracking extends Tool {

	/** {@inheritDoc} */
	public function hooks() {
		$this->settings(
			'tracking',
			__( 'Analytics events, product feeds and schema', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			array(
				'trk_ga4'     => array( __( 'Google Analytics 4 measurement id', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Like G-ABC123XYZ. Loads Google’s script from googletagmanager.com when filled in.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'trk_meta'    => array( __( 'Meta (Facebook) Pixel id', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Loads Meta’s script from connect.facebook.net when filled in.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'trk_tiktok'  => array( __( 'TikTok Pixel id', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Loads TikTok’s script from analytics.tiktok.com when filled in.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'trk_consent' => array( __( 'Consent cookie name', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Optional. If set, tracking scripts load only when this cookie exists and isn’t no, false, 0 or denied.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'trk_schema'  => array( __( 'Product structured data (JSON-LD)', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Adds price, availability and rating for search engines. Turn off if another SEO plugin already does.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'trk_feed'    => array( __( 'Product feeds', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Publishes /?sidccofo_feed=google and /?sidccofo_feed=csv. Only public product details are in them.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'trk_brand'   => array( __( 'Brand name for feeds', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Used when a product has no brand. Empty = the site name.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			)
		);
		add_action( 'wp_head', array( $this, 'head' ), 5 );
		add_action( 'wp_footer', array( $this, 'footer' ), 30 );
		add_action( 'template_redirect', array( $this, 'feed' ), 1 );
	}

	/**
	 * Tracking allowed for this visitor?
	 *
	 * @return bool
	 */
	protected function allowed() {
		$name = trim( (string) setting( 'trk_consent', '' ) );
		$ok   = true;
		if ( '' !== $name ) {
			$v  = isset( $_COOKIE[ $name ] ) ? strtolower( sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) ) : '';
			$ok = '' !== $v && ! in_array( $v, array( 'no', 'false', '0', 'denied' ), true );
		}
		return (bool) apply_filters( 'sidcraft_commerce_tracking_allowed', $ok );
	}

	/**
	 * Ids, cleaned.
	 *
	 * @return array
	 */
	protected function ids() {
		return array(
			'ga4'    => preg_match( '/^G-[A-Z0-9]{4,14}$/', trim( (string) setting( 'trk_ga4', '' ) ) ) ? trim( (string) setting( 'trk_ga4', '' ) ) : '',
			'meta'   => preg_match( '/^\d{5,20}$/', trim( (string) setting( 'trk_meta', '' ) ) ) ? trim( (string) setting( 'trk_meta', '' ) ) : '',
			'tiktok' => preg_match( '/^[A-Z0-9]{8,30}$/', trim( (string) setting( 'trk_tiktok', '' ) ) ) ? trim( (string) setting( 'trk_tiktok', '' ) ) : '',
		);
	}

	/**
	 * Analytics scripts and Product JSON-LD.
	 */
	public function head() {
		if ( setting( 'trk_schema', 1 ) && is_singular( Post_Types::PRODUCT ) ) {
			$p = Product::get( get_the_ID() );
			if ( $p && $p->is_published() ) {
				$ld = array(
					'@context'    => 'https://schema.org',
					'@type'       => 'Product',
					'name'        => wp_strip_all_tags( $p->get_name() ),
					'description' => wp_strip_all_tags( $p->get_short_description() ),
					'url'         => $p->get_permalink(),
					'sku'         => $p->get_sku(),
				);
				$img = get_the_post_thumbnail_url( $p->get_id(), 'large' );
				if ( $img ) {
					$ld['image'] = $img;
				}
				if ( $p->is_purchasable() ) {
					$ld['offers'] = array(
						'@type'         => 'Offer',
						'price'         => number_format( (float) $p->get_price(), 2, '.', '' ),
						'priceCurrency' => base_currency(),
						'availability'  => $p->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
						'url'           => $p->get_permalink(),
					);
				}
				if ( $p->get_review_count() > 0 ) {
					$ld['aggregateRating'] = array(
						'@type'       => 'AggregateRating',
						'ratingValue' => round( (float) $p->get_average_rating(), 2 ),
						'reviewCount' => (int) $p->get_review_count(),
					);
				}
				echo '<script type="application/ld+json">' . wp_json_encode( apply_filters( 'sidcraft_commerce_product_schema', $ld, $p ), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with < and > escaped.
			}
		}
		$ids = $this->ids();
		if ( ! array_filter( $ids ) || ! $this->allowed() ) {
			return;
		}
		$js = 'window.sidccofoTrack={ga4:' . wp_json_encode( $ids['ga4'] ) . ',meta:' . wp_json_encode( $ids['meta'] ) . ',tiktok:' . wp_json_encode( $ids['tiktok'] ) . ',currency:' . wp_json_encode( currency() ) . '};';
		if ( $ids['ga4'] ) {
			$js .= 'window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config",' . wp_json_encode( $ids['ga4'] ) . ');';
		}
		if ( $ids['meta'] ) {
			$js .= '!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version="2.0";n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,"script","https://connect.facebook.net/en_US/fbevents.js");fbq("init",' . wp_json_encode( $ids['meta'] ) . ');fbq("track","PageView");';
		}
		if ( $ids['tiktok'] ) {
			$js .= '!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie"];ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.load=function(e){var n="https://analytics.tiktok.com/i18n/pixel/events.js";ttq._i=ttq._i||{};ttq._i[e]=[];ttq._t=ttq._t||{};ttq._t[e]=+new Date;var o=d.createElement("script");o.type="text/javascript";o.async=!0;o.src=n+"?sdkid="+e+"&lib="+t;var a=d.getElementsByTagName("script")[0];a.parentNode.insertBefore(o,a)};ttq.load(' . wp_json_encode( $ids['tiktok'] ) . ');ttq.page()}(window,document,"ttq");';
		}
		echo '<script>' . $js . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from validated ids with wp_json_encode().
		if ( $ids['ga4'] ) {
			echo '<script async src="' . esc_url( 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $ids['ga4'] ) ) . '"></script>' . "\n"; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Provider tag, only when the owner enters an id.
		}
	}

	/**
	 * Event data and the script that sends the events.
	 */
	public function footer() {
		$ids = $this->ids();
		if ( ! array_filter( $ids ) || ! $this->allowed() ) {
			return;
		}
		$data = array( 'view' => null, 'purchase' => null, 'checkout' => false );
		if ( is_singular( Post_Types::PRODUCT ) ) {
			$p = Product::get( get_the_ID() );
			if ( $p ) {
				$data['view'] = $this->item( $p );
			}
		}
		if ( is_page( \SidcraftCommerce\page_id( 'checkout' ) ) ) {
			$data['checkout'] = true;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only; access by the order key, like the page itself.
		$oid = isset( $_GET['sidccofo_order'] ) ? absint( $_GET['sidccofo_order'] ) : 0;
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable
		if ( $oid ) {
			$o = Order::get( $oid );
			if ( $o && $o->key_is_valid( $key ) && ! get_post_meta( $oid, '_sidccofo_tracked', true ) && in_array( $o->get_status(), array_merge( Post_Types::paid_statuses(), array( 'sidccofo-on-hold' ) ), true ) ) {
				update_post_meta( $oid, '_sidccofo_tracked', 1 );
				$items = array();
				foreach ( $o->get_items() as $it ) {
					$items[] = array( 'item_id' => (string) ( '' !== (string) $it['sku'] ? $it['sku'] : $it['product_id'] ), 'item_name' => wp_strip_all_tags( $it['name'] ), 'price' => (float) $it['price'], 'quantity' => (int) $it['quantity'] );
				}
				$data['purchase'] = array( 'transaction_id' => (string) $oid, 'value' => (float) $o->get_total(), 'currency' => $o->get_currency(), 'tax' => (float) $o->get_tax(), 'shipping' => (float) $o->get_shipping(), 'items' => $items );
			}
		}
		?>
<script>
(function(){var T=window.sidccofoTrack;if(!T)return;var D=<?php echo wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ); ?>;
function send(ev,p){var items=p.items||[];var value=p.value||0;var cur=p.currency||T.currency;
if(T.ga4&&window.gtag){var o={currency:cur,value:value,items:items};if(p.transaction_id){o.transaction_id=p.transaction_id;o.tax=p.tax;o.shipping=p.shipping;}gtag('event',ev,o);}
var fb={view_item:'ViewContent',add_to_cart:'AddToCart',begin_checkout:'InitiateCheckout',purchase:'Purchase'}[ev];
if(T.meta&&window.fbq&&fb){fbq('track',fb,{value:value,currency:cur,content_ids:items.map(function(i){return i.item_id}),content_type:'product'});}
var tt={view_item:'ViewContent',add_to_cart:'AddToCart',begin_checkout:'InitiateCheckout',purchase:'CompletePayment'}[ev];
if(T.tiktok&&window.ttq&&tt){ttq.track(tt,{value:value,currency:cur,contents:items.map(function(i){return{content_id:i.item_id,content_name:i.item_name,quantity:i.quantity||1,price:i.price}})});}}
if(D.view)send('view_item',{items:[D.view],value:D.view.price});
if(D.checkout)send('begin_checkout',{items:[],value:0});
if(D.purchase)send('purchase',D.purchase);
document.addEventListener('sc:added_to_cart',function(e){var b=e.detail&&e.detail.button;var id=e.detail&&e.detail.productId;send('add_to_cart',{items:[{item_id:String(id),item_name:'',quantity:e.detail.quantity||1}],value:0});});})();
</script>
		<?php
	}

	/**
	 * One product as an analytics item.
	 *
	 * @param Product $p Product.
	 * @return array
	 */
	protected function item( Product $p ) {
		return array(
			'item_id'   => (string) ( '' !== (string) $p->get_sku() ? $p->get_sku() : $p->get_id() ),
			'item_name' => wp_strip_all_tags( $p->get_name() ),
			'price'     => (float) $p->get_price(),
		);
	}

	/**
	 * Serve a feed.
	 */
	public function feed() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A public read-only feed.
		$kind = isset( $_GET['sidccofo_feed'] ) ? sanitize_key( wp_unslash( $_GET['sidccofo_feed'] ) ) : '';
		if ( '' === $kind || ! setting( 'trk_feed', 1 ) || ! in_array( $kind, array( 'google', 'csv' ), true ) ) {
			return;
		}
		$cache = get_transient( 'sidccofo_feed_' . $kind );
		if ( false === $cache ) {
			$cache = 'csv' === $kind ? $this->csv_feed() : $this->xml_feed();
			set_transient( 'sidccofo_feed_' . $kind, $cache, HOUR_IN_SECONDS );
		}
		nocache_headers();
		header( 'Content-Type: ' . ( 'csv' === $kind ? 'text/csv' : 'application/xml' ) . '; charset=utf-8' );
		echo $cache; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped when built.
		exit;
	}

	/**
	 * Products for feeds.
	 *
	 * @return array[]
	 */
	protected function rows() {
		$ids   = get_posts(
			array(
				'post_type'      => Post_Types::PRODUCT,
				'post_status'    => 'publish',
				'posts_per_page' => 2000,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$brand = '' !== trim( (string) setting( 'trk_brand', '' ) ) ? (string) setting( 'trk_brand', '' ) : get_bloginfo( 'name' );
		$rows  = array();
		foreach ( $ids as $id ) {
			$p = Product::get( $id );
			if ( ! $p || ! $p->is_purchasable() || $p->is_gift_card() || '' === $p->get_regular_price() ) {
				continue;
			}
			$brands = $p->get_brands();
			$desc   = trim( wp_strip_all_tags( $p->get_short_description() ) );
			if ( '' === $desc ) {
				$desc = wp_strip_all_tags( $p->get_name() );
			}
			$rows[] = array(
				'id'           => '' !== (string) $p->get_sku() ? $p->get_sku() : (string) $id,
				'title'        => wp_strip_all_tags( $p->get_name() ),
				'description'  => $desc,
				'link'         => $p->get_permalink(),
				'image_link'   => (string) get_the_post_thumbnail_url( $id, 'full' ),
				'availability' => $p->is_in_stock() ? 'in stock' : 'out of stock',
				'price'        => number_format( (float) $p->get_regular_price(), 2, '.', '' ) . ' ' . base_currency(),
				'sale_price'   => $p->has_sale_price() ? number_format( (float) $p->get_base_price(), 2, '.', '' ) . ' ' . base_currency() : '',
				'brand'        => $brands ? $brands[0]->name : $brand,
				'condition'    => 'new',
			);
		}
		return $rows;
	}

	/**
	 * Google Merchant / Meta catalog XML.
	 *
	 * @return string
	 */
	protected function xml_feed() {
		$x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel><title>' . esc_xml( get_bloginfo( 'name' ) ) . '</title><link>' . esc_xml( home_url( '/' ) ) . '</link><description>' . esc_xml( get_bloginfo( 'description' ) ) . "</description>\n";
		foreach ( $this->rows() as $r ) {
			$x .= '<item>';
			foreach ( $r as $k => $v ) {
				if ( '' === $v ) {
					continue;
				}
				$x .= '<g:' . $k . '>' . esc_xml( $v ) . '</g:' . $k . '>';
			}
			$x .= '<g:identifier_exists>no</g:identifier_exists></item>' . "\n";
		}
		return $x . '</channel></rss>';
	}

	/**
	 * CSV feed.
	 *
	 * @return string
	 */
	protected function csv_feed() {
		$rows = array( array( 'id', 'title', 'description', 'link', 'image_link', 'availability', 'price', 'sale_price', 'brand', 'condition' ) );
		foreach ( $this->rows() as $r ) {
			$rows[] = array_values( $r );
		}
		return Extras::csv( $rows );
	}
}
