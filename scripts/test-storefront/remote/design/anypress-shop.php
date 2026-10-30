<?php
/**
 * Plugin Name: AnyPress shop
 * Description: The shop's block styles (sketch panels, tick lists, size table) and two small shortcodes for the plan pages (the size switcher and the way back to the plans), and the AnyPress logo and support address in WaaS Host's emails. Installed by the shop's `design` step.
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block styles in the anypress.io look. Owners pick them in the editor's Styles panel like any other.
 */
add_action(
	'init',
	static function () {
		$ink   = 'var(--wp--preset--color--main)';
		$honey = 'var(--wp--preset--color--primary-alt)';
		$rose  = 'var(--wp--preset--color--rose,#ee2f5e)';
		foreach ( array( 'core/group', 'core/column', 'core/image' ) as $block ) {
			$sel = 'core/image' === $block ? '.wp-block-image.is-style-sketch img' : '.is-style-sketch';
			register_block_style(
				$block,
				array(
					'name'         => 'sketch',
					'label'        => __( 'Sketch panel', 'anypress-shop' ),
					'inline_style' => "$sel{border:1.5px solid $ink;border-radius:18px;box-shadow:8px 8px 0 $honey}",
				)
			);
			$sel = 'core/image' === $block ? '.wp-block-image.is-style-sketch-rose img' : '.is-style-sketch-rose';
			register_block_style(
				$block,
				array(
					'name'         => 'sketch-rose',
					'label'        => __( 'Sketch panel, rose', 'anypress-shop' ),
					'inline_style' => "$sel{border:1.5px solid $ink;border-radius:18px;box-shadow:8px 8px 0 $rose}",
				)
			);
		}
		register_block_style(
			'core/group',
			array(
				'name'         => 'card-line',
				'label'        => __( 'Card with a thin line', 'anypress-shop' ),
				'inline_style' => '.is-style-card-line{border:1px solid var(--wp--preset--color--border-light);border-radius:16px;background:var(--wp--preset--color--base)}',
			)
		);
		register_block_style(
			'core/list',
			array(
				'name'         => 'ticks',
				'label'        => __( 'Ticks', 'anypress-shop' ),
				'inline_style' => '.wp-block-list.is-style-ticks{list-style:none;padding-inline-start:0}.wp-block-list.is-style-ticks>li{position:relative;padding-inline-start:1.7em;margin-block:.35em}.wp-block-list.is-style-ticks>li::before{content:"";position:absolute;inset-inline-start:0;top:.3em;width:1.05em;height:1.05em;border-radius:50%;background:var(--wp--preset--color--tick-green,#17703d) url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 16 16%27%3E%3Cpath d=%27M4 8.5l2.6 2.5L12 5.5%27 fill=%27none%27 stroke=%27white%27 stroke-width=%272%27 stroke-linecap=%27round%27 stroke-linejoin=%27round%27/%3E%3C/svg%3E") center/80% no-repeat}',
			)
		);
		register_block_style(
			'core/paragraph',
			array(
				'name'         => 'hand',
				'label'        => __( 'Handwritten note', 'anypress-shop' ),
				'inline_style' => '.is-style-hand{font-family:var(--wp--preset--font-family--caveat),cursive;font-weight:700;font-size:1.55rem;line-height:1.1;color:var(--wp--preset--color--primary)}',
			)
		);
		register_block_style(
			'core/table',
			array(
				'name'         => 'compare',
				'label'        => __( 'Size table', 'anypress-shop' ),
				'inline_style' => '.wp-block-table.is-style-compare table{border-collapse:separate;border-spacing:0;border:1.5px solid var(--wp--preset--color--main);border-radius:16px;overflow:hidden;background:var(--wp--preset--color--base)}.wp-block-table.is-style-compare th,.wp-block-table.is-style-compare td{border:0;border-bottom:1px solid var(--wp--preset--color--border-light);padding:.8em 1em;text-align:start}.wp-block-table.is-style-compare tr:last-child td{border-bottom:0}.wp-block-table.is-style-compare thead th{background:var(--wp--preset--color--main);color:var(--wp--preset--color--base);font-weight:800}.wp-block-table.is-style-compare tbody td:first-child{color:var(--wp--preset--color--secondary)}.wp-block-table.is-style-compare td strong{font-size:1.15em}',
			)
		);
	}
);

/**
 * A plan's page is one size of one kind of site (website-small, online-store-large...). Lists the sizes of the same
 * kind, so visitors can switch without going back to the plans.
 */
function anypress_shop_sibling_sizes() {
	$post = get_post();
	if ( ! $post || 'fluent-products' !== $post->post_type || ! preg_match( '/^(.+)-(small|medium|large)$/', $post->post_name, $m ) ) {
		return array();
	}
	$sizes = (array) get_option( 'anypress_shop_sizes', array() );
	$out   = array();
	foreach ( array( 'small', 'medium', 'large' ) as $size ) {
		$sibling = get_page_by_path( $m[1] . '-' . $size, OBJECT, 'fluent-products' );
		if ( $sibling && 'publish' === $sibling->post_status ) {
			$out[ $size ] = array(
				'url'     => get_permalink( $sibling ),
				'label'   => $sizes[ $size ]['name'] ?? ucfirst( $size ),
				'note'    => $sizes[ $size ]['short'] ?? '',
				'current' => $sibling->ID === $post->ID,
			);
		}
	}
	return array( 'kind' => $m[1], 'sizes' => $out );
}

add_shortcode(
	'anypress_sizes',
	static function () {
		$found = anypress_shop_sibling_sizes();
		if ( empty( $found['sizes'] ) ) {
			return '';
		}
		$html = '<nav class="anypress-sizes" aria-label="' . esc_attr__( 'Sizes', 'anypress-shop' ) . '"><span class="anypress-sizes__label">' . esc_html__( 'Size', 'anypress-shop' ) . '</span><ul>';
		foreach ( $found['sizes'] as $size ) {
			$html .= '<li><a href="' . esc_url( $size['url'] ) . '"' . ( $size['current'] ? ' aria-current="page"' : '' ) . '><strong>' . esc_html( $size['label'] ) . '</strong><span>' . esc_html( $size['note'] ) . '</span></a></li>';
		}
		return $html . '</ul></nav>';
	}
);

add_shortcode(
	'anypress_crumbs',
	static function () {
		$post = get_post();
		if ( ! $post ) {
			return '';
		}
		$plans = (int) get_option( 'anypress_shop_plans_page' );
		$found = anypress_shop_sibling_sizes();
		$kinds = (array) get_option( 'anypress_shop_kinds', array() );
		$html  = '<nav class="anypress-crumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'anypress-shop' ) . '"><a href="' . esc_url( $plans ? get_permalink( $plans ) : home_url( '/' ) ) . '">' . esc_html__( 'Plans', 'anypress-shop' ) . '</a>';
		if ( ! empty( $found['kind'] ) && isset( $kinds[ $found['kind'] ] ) ) {
			$html .= '<span aria-hidden="true">/</span><a href="' . esc_url( ( $plans ? get_permalink( $plans ) : home_url( '/' ) ) . '#' . $kinds[ $found['kind'] ]['id'] ) . '">' . esc_html( $kinds[ $found['kind'] ]['name'] ) . '</a>';
		}
		return $html . '</nav>';
	}
);

/**
 * The promises under the Buy button. A plan's are about the subscription; anything else (the email pack) gets its own.
 */
add_shortcode(
	'anypress_promises',
	static function () {
		$plan  = ! empty( anypress_shop_sibling_sizes()['sizes'] );
		$items = $plan
			? array( __( 'Pay yearly and get two months free', 'anypress-shop' ), __( '30-day money-back guarantee', 'anypress-shop' ), __( 'Cancel any time from your account', 'anypress-shop' ), __( 'A free anypress.site address, or your own domain', 'anypress-shop' ), __( 'Secure card checkout', 'anypress-shop' ) )
			: array( __( 'A one-time payment, not a subscription', 'anypress-shop' ), __( 'Added to your website right after you pay', 'anypress-shop' ), __( 'Pack emails never expire', 'anypress-shop' ), __( 'Secure card checkout', 'anypress-shop' ) );
		$lis   = '';
		foreach ( $items as $item ) {
			$lis .= "<!-- wp:list-item -->\n<li>" . esc_html( $item ) . "</li>\n<!-- /wp:list-item -->\n";
		}
		return do_blocks( "<!-- wp:list {\"className\":\"is-style-ticks\"} -->\n<ul class=\"wp-block-list is-style-ticks\">$lis</ul>\n<!-- /wp:list -->" );
	}
);

/**
 * The Shortcode block wraps its output in a paragraph, which breaks the switcher's list. These render bare.
 */
add_filter(
	'render_block_core/shortcode',
	static function ( $html, $block ) {
		$code = trim( $block['innerHTML'] ?? '' );
		return in_array( $code, array( '[anypress_sizes]', '[anypress_crumbs]', '[anypress_promises]' ), true ) ? do_shortcode( $code ) : $html;
	},
	10,
	2
);

/**
 * The account page for someone signed out: a sign-in form right there, instead of FluentCart's "Login" button that
 * leads to a second page.
 */
function anypress_shop_sign_in() {
	$account = get_permalink();
	$plans   = (int) get_option( 'anypress_shop_plans_page' );
	$form    = wp_login_form(
		array(
			'echo'           => false,
			'redirect'       => $account,
			'label_username' => __( 'Email address', 'anypress-shop' ),
			'label_log_in'   => __( 'Sign in', 'anypress-shop' ),
			'remember'       => true,
		)
	);
	return '<div class="anypress-sign-in"><div class="anypress-sign-in__form"><h2>' . esc_html__( 'Sign in', 'anypress-shop' ) . '</h2><p>' . esc_html__( 'Use the email address you bought your plan with.', 'anypress-shop' ) . '</p>' . $form
		. '<p class="anypress-sign-in__lost"><a href="' . esc_url( wp_lostpassword_url( $account ) ) . '">' . esc_html__( 'Forgot your password? We’ll email you a link.', 'anypress-shop' ) . '</a></p></div>'
		. '<div class="anypress-sign-in__new"><h3>' . esc_html__( 'New to AnyPress?', 'anypress-shop' ) . '</h3><p>' . esc_html__( 'Your account is made when you buy your first plan. Pick one and you can sign in here a minute later.', 'anypress-shop' ) . '</p>'
		. '<p><a class="wp-element-button" href="' . esc_url( $plans ? get_permalink( $plans ) : home_url( '/' ) ) . '">' . esc_html__( 'See the plans', 'anypress-shop' ) . '</a></p></div></div>';
}
add_filter(
	'render_block_fluent-cart/customer-profile',
	static fn( $html ) => is_user_logged_in() ? $html : anypress_shop_sign_in()
);
add_filter(
	'pre_do_shortcode_tag',
	static fn( $out, $tag ) => ( 'fluent_cart_customer_profile' === $tag && ! is_user_logged_in() ) ? anypress_shop_sign_in() : $out,
	10,
	2
);

/**
 * The empty cart says where to go next, and the buttons read like the rest of the shop.
 */
add_filter(
	'gettext_fluent-cart',
	static function ( $text, $original ) {
		if ( 'Your cart is empty.' === $original ) {
			return __( 'Your cart is empty. Pick a plan and it will show up here.', 'anypress-shop' );
		}
		$words = array(
			'Continue Shopping' => __( 'See the plans', 'anypress-shop' ),
			'Add To Cart'       => __( 'Add to cart', 'anypress-shop' ),
			'Buy Now'           => __( 'Buy now', 'anypress-shop' ),
		);
		return $words[ $original ] ?? $text;
	},
	10,
	2
);

/**
 * WaaS Host's website emails: the AnyPress mark next to the name, and support@anypress.io in the footer and Reply-To,
 * as in the AnyPress API's account emails.
 */
add_filter( 'waas_email_logo_url', static fn () => 'https://anypress.io/logo-512.png' );
add_filter( 'waas_email_support_address', static fn () => 'support@anypress.io' );
