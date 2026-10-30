<?php
// Preview only: a WordPress Playground copy of the shop to try its design on. Makes FluentCart's pages, the 15 plans
// and the email pack as the live shop has them (names, slugs, monthly and yearly prices, pictures), help pages in
// BetterDocs, and legal pages, then runs the shop's design step. Run once, after the plugins and theme are active.
//
// Needs the design files at $design (the shop's remote/design folder).

$design = $design ?? dirname( __DIR__, 2 ) . '/test-storefront/remote/design';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
wp_set_current_user( 1 );

update_option( 'blogname', 'AnyPress' );
update_option( 'blogdescription', 'Real WordPress, set up and looked after for you' );
$GLOBALS['wp_rewrite']->set_permalink_structure( '/%postname%/' );

foreach ( get_posts( array( 'post_type' => array( 'post', 'page' ), 'numberposts' => -1, 'post_status' => 'any' ) ) as $old ) {
	wp_delete_post( $old->ID, true );
}
( new FluentCart\App\CPT\Pages() )->createPages();

/** A picture from the design folder, as a media item. */
function anypress_preview_media( $design, $file ) {
	static $done = array();
	if ( isset( $done[ $file ] ) ) {
		return $done[ $file ];
	}
	$tmp = wp_tempnam( $file );
	copy( "$design/images/$file", $tmp );
	$done[ $file ] = media_handle_sideload( array( 'name' => $file, 'tmp_name' => $tmp ), 0 );
	return $done[ $file ];
}

$kinds = array(
	'website'      => array( 'Website', 1200, 'website.jpg', 'A website or blog with SEO, forms, email marketing and an affiliate program.' ),
	'bookings'     => array( 'Bookings', 1900, 'bookings.jpg', 'A website that takes appointments and bookings, with a support desk.' ),
	'online-store' => array( 'Online Store', 2900, 'store.jpg', 'An online store with checkout, subscriptions, email marketing and affiliates.' ),
	'community'    => array( 'Community & Courses', 3900, 'courses.jpg', 'A members community with courses, plus a store and support desk.' ),
	'everything'   => array( 'Everything', 7900, 'everything.jpg', 'Every AnyPress feature: store, bookings, community, courses, support, email marketing and affiliates.' ),
);
$sub  = static fn( $interval ) => array( 'payment_type' => 'subscription', 'repeat_interval' => $interval, 'times' => '', 'trial_days' => 0, 'manage_setup_fee' => 'no' );
$made = array();
foreach ( $kinds as $slug => list( $name, $price, $image, $pitch ) ) {
	foreach ( array( 'small' => 1, 'medium' => 2, 'large' => 4 ) as $size => $times ) {
		$res = ( new FluentCart\App\Services\BulkProductInsertService() )->insertChunk(
			array(
				array(
					'_cid'         => "$slug-$size",
					'post_title'   => "$name (" . ucfirst( $size ) . ')',
					'post_name'    => "$slug-$size",
					'post_status'  => 'published',
					'post_content' => $pitch,
					'detail'       => array( 'fulfillment_type' => 'digital', 'variation_type' => 'simple_variations' ),
					'variants'     => array(
						array( 'variation_title' => 'Monthly', 'item_price' => $price * $times, 'other_info' => $sub( 'monthly' ) ),
						array( 'variation_title' => 'Yearly', 'item_price' => $price * $times * 10, 'other_info' => $sub( 'yearly' ) ),
					),
				),
			)
		);
		$id = $res['created'][0]['id'] ?? 0;
		if ( $id ) {
			wp_update_post( array( 'ID' => $id, 'post_name' => "$slug-$size" ) );
			set_post_thumbnail( $id, anypress_preview_media( $design, $image ) );
			$made[] = $id;
		}
	}
}
$res = ( new FluentCart\App\Services\BulkProductInsertService() )->insertChunk(
	array(
		array(
			'_cid'         => 'email-pack',
			'post_title'   => 'Email pack (10,000 emails)',
			'post_status'  => 'published',
			'post_content' => '10,000 extra emails for one of your websites, on top of its monthly allowance.',
			'detail'       => array( 'fulfillment_type' => 'digital', 'variation_type' => 'simple' ),
			'variants'     => array( array( 'variation_title' => 'Email pack', 'item_price' => 500, 'other_info' => array( 'payment_type' => 'onetime' ) ) ),
		),
	)
);
if ( ! empty( $res['created'][0]['id'] ) ) {
	wp_update_post( array( 'ID' => $res['created'][0]['id'], 'post_name' => 'email-pack-10000' ) );
	set_post_thumbnail( $res['created'][0]['id'], anypress_preview_media( $design, 'email-pack.jpg' ) );
}

// Help pages: the live shop's categories, with a few of its articles' titles.
if ( taxonomy_exists( 'doc_category' ) ) {
	$docs = array(
		'Getting Started'   => array( 'Welcome to AnyPress', 'Sign in and open your site’s editor' ),
		'Plans and billing' => array( 'Plans, sizes and prices', 'Cancel your plan', 'Refunds' ),
		'Your website'      => array( 'Use your own domain', 'Send email from your own domain', 'Backups and getting your site back', 'Move a WordPress site to AnyPress' ),
		'Selling websites'  => array( 'Your first day as a reseller', 'Reseller accounts', 'Add your team' ),
		'Affiliates'        => array( 'How the affiliate program works' ),
		'Automation'        => array( 'Use AnyPress in Zapier', 'Use AnyPress in Make', 'Use AnyPress in n8n' ),
		'Developers'        => array( 'API quickstart', 'Run AnyPress yourself' ),
	);
	foreach ( $docs as $category => $titles ) {
		$term = wp_insert_term( $category, 'doc_category' );
		if ( ! is_wp_error( $term ) ) {
			update_term_meta( (int) $term['term_id'], 'doc_category_order', count( get_terms( array( 'taxonomy' => 'doc_category', 'hide_empty' => false, 'fields' => 'ids' ) ) ) );
		}
		foreach ( $titles as $title ) {
			$doc = wp_insert_post( array( 'post_type' => 'docs', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '<!-- wp:paragraph --><p>The live help page has the full article. This preview only shows how help pages look.</p><!-- /wp:paragraph -->' ) );
			if ( ! is_wp_error( $term ) ) {
				wp_set_object_terms( $doc, (int) $term['term_id'], 'doc_category' );
			}
		}
	}
}

// The footer's legal pages and the affiliate page, with a line of text each.
foreach ( array( 'terms' => 'Terms of Service', 'privacy' => 'Privacy Policy', 'refund-policy' => 'Refund Policy', 'affiliates' => 'Affiliates' ) as $slug => $title ) {
	wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => '<!-- wp:paragraph --><p>The live shop has the full page. This preview only shows how a plain page looks.</p><!-- /wp:paragraph -->' ) );
}

require "$design/design.php";
flush_rewrite_rules();
echo 'plans: ' . count( $made ) . "\n";
