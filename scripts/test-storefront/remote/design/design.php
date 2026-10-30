<?php
// The shop's look (storefront.py --cart shop setup design): the AnyPress theme dressed like anypress.io, with its fonts,
// colours and drawings, a header and footer, the plan page template, and the home, plans, cart, checkout and account
// pages, plus the words on every plan's page. Everything lands where an owner edits it (Styles, Templates, Pages), so
// it can be changed in the Site Editor afterwards. Idempotent: running it again puts the design back.
//
// Needs the AnyPress theme active, FluentCart, the plans (product slugs <kind>-<size>) and anypress-shop.php in
// mu-plugins. Prices on the home and plans pages are read from the plans when this runs: run it again after a price
// change.

if ( 'anypress' !== get_template() ) {
	echo "The AnyPress theme is not active\n";
	return;
}

$ap_dir  = __DIR__;
$ap_data = json_decode( file_get_contents( "$ap_dir/shop.json" ), true );

// ---------------------------------------------------------------------------------------------------------- files
// Drawings and pictures go to uploads/anypress-shop, fonts to the Font Library's folder.
$ap_up   = wp_upload_dir();
$ap_dest = $ap_up['basedir'] . '/anypress-shop';
define( 'ANYPRESS_SHOP_ASSETS', set_url_scheme( $ap_up['baseurl'] . '/anypress-shop', 'relative' ) );
wp_mkdir_p( $ap_dest );
foreach ( array( 'drawings', 'images' ) as $ap_sub ) {
	foreach ( glob( "$ap_dir/$ap_sub/*" ) as $ap_file ) {
		copy( $ap_file, $ap_dest . '/' . basename( $ap_file ) );
	}
}
$ap_fonts = wp_get_font_dir();
wp_mkdir_p( $ap_fonts['path'] );
foreach ( glob( "$ap_dir/fonts/*.woff2" ) as $ap_file ) {
	copy( $ap_file, $ap_fonts['path'] . '/' . basename( $ap_file ) );
}

/** A drawing or picture copied above. */
function ap_asset( $name ) {
	return ANYPRESS_SHOP_ASSETS . '/' . $name;
}

// ---------------------------------------------------------------------------------------------------------- fonts
// Hanken Grotesk for everything, Caveat for the handwritten notes: the anypress.io pair, in the Font Library.
$ap_font_families = array();
foreach (
	array(
		array( 'Hanken Grotesk', 'hanken-grotesk', '"Hanken Grotesk", system-ui, sans-serif', 'hanken-grotesk.woff2', '100 900' ),
		array( 'Caveat', 'caveat', 'Caveat, cursive', 'caveat.woff2', '400 700' ),
	) as list( $ap_name, $ap_slug, $ap_stack, $ap_file, $ap_weight )
) {
	$ap_face = array(
		'fontFamily' => $ap_name,
		'fontStyle'  => 'normal',
		'fontWeight' => $ap_weight,
		'src'        => $ap_fonts['url'] . '/' . $ap_file,
	);
	$ap_family_post = get_posts( array( 'post_type' => 'wp_font_family', 'name' => $ap_slug, 'numberposts' => 1, 'post_status' => 'any' ) );
	$ap_family_id   = $ap_family_post ? $ap_family_post[0]->ID : wp_insert_post(
		array(
			'post_type'    => 'wp_font_family',
			'post_status'  => 'publish',
			'post_title'   => $ap_name,
			'post_name'    => $ap_slug,
			'post_content' => wp_slash( wp_json_encode( array( 'name' => $ap_name, 'slug' => $ap_slug, 'fontFamily' => $ap_stack ) ) ),
		)
	);
	if ( ! get_posts( array( 'post_type' => 'wp_font_face', 'post_parent' => $ap_family_id, 'numberposts' => 1, 'post_status' => 'any' ) ) ) {
		wp_insert_post(
			array(
				'post_type'    => 'wp_font_face',
				'post_status'  => 'publish',
				'post_parent'  => $ap_family_id,
				'post_title'   => "$ap_name $ap_weight",
				'post_content' => wp_slash( wp_json_encode( $ap_face ) ),
			)
		);
	}
	$ap_font_families[] = array(
		'name'       => $ap_name,
		'slug'       => $ap_slug,
		'fontFamily' => $ap_stack,
		'fontFace'   => array( $ap_face ),
	);
}

// ---------------------------------------------------------------------------------------------------------- styles
// The theme's colour slugs keep their names (pages and kits use them) and take the anypress.io colours: ink, paper,
// the honey of the logo's ribbon, and the readable accent for links and small coloured text.
$ap_palette = array(
	array( 'primary', 'Accent', '#c8264d' ),
	array( 'primary-accent', 'Honey light', '#fff3d6' ),
	array( 'primary-alt', 'Honey', '#ffc75a' ),
	array( 'primary-alt-accent', 'Ink on honey', '#141414' ),
	array( 'main', 'Ink', '#141414' ),
	array( 'main-accent', 'Line on ink', '#e9e4de' ),
	array( 'base', 'Paper', '#ffffff' ),
	array( 'secondary', 'Ink soft', '#5f5a55' ),
	array( 'tertiary', 'Paper 2', '#faf8f5' ),
	array( 'border-light', 'Line', '#e9e4de' ),
	array( 'border-dark', 'Ink line', '#141414' ),
);
$ap_custom_colours = array(
	array( 'rose', 'Rose', '#ee2f5e' ),
	array( 'amber', 'Amber', '#ffb338' ),
	array( 'tick-green', 'Tick green', '#17703d' ),
);
$ap_to_palette     = static fn( $rows ) => array_map( static fn( $c ) => array( 'slug' => $c[0], 'name' => $c[1], 'color' => $c[2] ), $rows );

$ap_styles = array(
	'version'                     => 3,
	'isGlobalStylesUserThemeJSON' => true,
	'settings'                    => array(
		'color'      => array(
			'palette' => array(
				'theme'  => $ap_to_palette( $ap_palette ),
				'custom' => $ap_to_palette( $ap_custom_colours ),
			),
		),
		'typography' => array( 'fontFamilies' => array( 'custom' => $ap_font_families ) ),
	),
	'styles'                      => array(
		'color'      => array(
			'background' => 'var:preset|color|base',
			'text'       => 'var:preset|color|main',
		),
		'typography' => array(
			'fontFamily' => 'var:preset|font-family|hanken-grotesk',
			'lineHeight' => '1.6',
		),
		'elements'   => array(
			'heading' => array(
				'typography' => array(
					'fontFamily'    => 'var:preset|font-family|hanken-grotesk',
					'fontWeight'    => '800',
					'letterSpacing' => '-0.025em',
					'lineHeight'    => '1.05',
				),
			),
			'link'    => array(
				'color'  => array( 'text' => 'var:preset|color|primary' ),
				':hover' => array( 'color' => array( 'text' => 'var:preset|color|main' ) ),
			),
			'button'  => array(
				'color'      => array(
					'background' => 'var:preset|color|main',
					'text'       => 'var:preset|color|base',
				),
				'border'     => array(
					'radius' => '10px',
					'width'  => '1.5px',
					'style'  => 'solid',
					'color'  => 'var:preset|color|main',
				),
				'typography' => array( 'fontWeight' => '700' ),
				':hover'     => array(
					'color' => array(
						'background' => 'var:preset|color|main',
						'text'       => 'var:preset|color|base',
					),
				),
			),
		),
		// The theme's light section styles put the accent on buttons; here buttons stay ink everywhere but on dark bands.
		'blocks'     => array(
			'core/group' => array(
				'variations' => array_fill_keys(
					array( 'section-tint', 'section-brand-soft', 'section-card' ),
					array(
						'elements' => array(
							'button' => array(
								'color'  => array( 'background' => 'var:preset|color|main', 'text' => 'var:preset|color|base' ),
								':hover' => array( 'color' => array( 'background' => 'var:preset|color|main', 'text' => 'var:preset|color|base' ) ),
							),
						),
					)
				),
			),
		),
		'css'        => file_get_contents( "$ap_dir/shop.css" ),
	),
);
WP_Theme_JSON_Resolver::clean_cached_data();
$ap_styles_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
wp_update_post( array( 'ID' => $ap_styles_id, 'post_content' => wp_slash( wp_json_encode( $ap_styles ) ) ) );
WP_Theme_JSON_Resolver::clean_cached_data();

// ---------------------------------------------------------------------------------------------------------- plans
// Every plan by kind and size, with its monthly and yearly prices as FluentCart has them now.
$ap_settings   = new FluentCart\Api\StoreSettings();
$ap_plans_page = (int) $ap_settings->get( 'shop_page_id' );
$ap_sizes      = array();
foreach ( $ap_data['sizes'] as $ap_size ) {
	$ap_sizes[ $ap_size['id'] ] = $ap_size + array( 'short' => $ap_size['cpu'] . ' · ' . $ap_size['storage'] );
}
update_option( 'anypress_shop_sizes', $ap_sizes );
update_option( 'anypress_shop_plans_page', $ap_plans_page );
update_option( 'anypress_shop_kinds', array_column( array_map( static fn( $t ) => array( 'slug' => $t['slug'], 'id' => $t['id'], 'name' => $t['name'] ), $ap_data['types'] ), null, 'slug' ) );

/** "$12", or "$12.50" when there are cents. */
function ap_money( $cents ) {
	return '$' . ( $cents % 100 ? number_format( $cents / 100, 2 ) : number_format( $cents / 100 ) );
}

$ap_plans = array();
foreach ( $ap_data['types'] as $ap_type ) {
	foreach ( $ap_sizes as $ap_size_id => $ap_size ) {
		$ap_post = get_page_by_path( $ap_type['slug'] . '-' . $ap_size_id, OBJECT, 'fluent-products' );
		if ( ! $ap_post ) {
			continue;
		}
		$ap_plan = array( 'id' => $ap_post->ID, 'url' => get_permalink( $ap_post ), 'monthly' => 0, 'yearly' => 0 );
		foreach ( FluentCart\App\Models\ProductVariation::where( 'post_id', $ap_post->ID )->get() as $ap_variation ) {
			$ap_interval = $ap_variation->other_info['repeat_interval'] ?? '';
			if ( isset( $ap_plan[ $ap_interval ] ) && ! $ap_plan[ $ap_interval ] ) {
				$ap_plan[ $ap_interval ] = (int) $ap_variation->item_price;
			}
		}
		$ap_plans[ $ap_type['id'] ][ $ap_size_id ] = $ap_plan;
	}
}
$ap_email_pack = get_page_by_path( 'email-pack-10000', OBJECT, 'fluent-products' );

// ---------------------------------------------------------------------------------------------------------- blocks
// Small builders for the block markup below. They write what the editor itself saves, so pages open without errors.

function ap_e( $text ) {
	return esc_html( $text );
}
function ap_attrs( $attrs ) {
	return $attrs ? ' ' . serialize_block_attributes( $attrs ) : '';
}
function ap_p( $text, $attrs = array(), $class = '' ) {
	$classes = trim( implode( ' ', array_filter( array( $attrs['className'] ?? '', isset( $attrs['textColor'] ) ? 'has-' . $attrs['textColor'] . '-color has-text-color' : '', isset( $attrs['fontSize'] ) ? 'has-' . $attrs['fontSize'] . '-font-size' : '', isset( $attrs['align'] ) ? 'has-text-align-' . $attrs['align'] : '', $class ) ) ) );
	return '<!-- wp:paragraph' . ap_attrs( $attrs ) . " -->\n<p" . ( $classes ? ' class="' . $classes . '"' : '' ) . ">$text</p>\n<!-- /wp:paragraph -->";
}
function ap_h( $text, $level = 2, $attrs = array() ) {
	if ( 2 !== $level ) {
		$attrs = array( 'level' => $level ) + $attrs;
	}
	$classes = trim( implode( ' ', array_filter( array( 'wp-block-heading', isset( $attrs['textAlign'] ) ? 'has-text-align-' . $attrs['textAlign'] : '', $attrs['className'] ?? '', isset( $attrs['fontSize'] ) ? 'has-' . $attrs['fontSize'] . '-font-size' : '' ) ) ) );
	$id      = isset( $attrs['anchor'] ) ? ' id="' . esc_attr( $attrs['anchor'] ) . '"' : '';
	return '<!-- wp:heading' . ap_attrs( $attrs ) . " -->\n<h$level class=\"$classes\"$id>$text</h$level>\n<!-- /wp:heading -->";
}
/** Spacing from the theme's scale, e.g. ap_pad( 'xx-large', 'xx-large' ). */
function ap_pad( $top, $bottom, $left = null, $right = null ) {
	$p = array( 'top' => "var:preset|spacing|$top" );
	if ( $right ) {
		$p['right'] = "var:preset|spacing|$right";
	}
	$p['bottom'] = "var:preset|spacing|$bottom";
	if ( $left ) {
		$p['left'] = "var:preset|spacing|$left";
	}
	return $p;
}
function ap_css_spacing( $spacing ) {
	$css = '';
	foreach ( array( 'margin', 'padding' ) as $kind ) {
		foreach ( $spacing[ $kind ] ?? array() as $side => $value ) {
			$css .= "$kind-$side:" . ( str_starts_with( $value, 'var:preset|spacing|' ) ? 'var(--wp--preset--spacing--' . substr( $value, 19 ) . ')' : $value ) . ';';
		}
	}
	return rtrim( $css, ';' );
}
/** A group. $attrs are the block's attributes; layout defaults to constrained. */
function ap_group( $inner, $attrs = array(), $tag = 'div' ) {
	$attrs += array( 'layout' => array( 'type' => 'constrained' ) );
	if ( 'div' !== $tag ) {
		$attrs = array( 'tagName' => $tag ) + $attrs;
	}
	$classes = array( 'wp-block-group' );
	if ( isset( $attrs['align'] ) ) {
		$classes[] = 'align' . $attrs['align'];
	}
	if ( isset( $attrs['className'] ) ) {
		$classes[] = $attrs['className'];
	}
	if ( isset( $attrs['textColor'] ) ) {
		$classes[] = 'has-' . $attrs['textColor'] . '-color has-text-color';
	}
	if ( isset( $attrs['backgroundColor'] ) ) {
		$classes[] = 'has-' . $attrs['backgroundColor'] . '-background-color has-background';
	}
	$style = ap_css_spacing( $attrs['style']['spacing'] ?? array() );
	if ( isset( $attrs['style']['border']['radius'] ) ) {
		$style = 'border-radius:' . $attrs['style']['border']['radius'] . ( $style ? ';' . $style : '' );
	}
	$id = isset( $attrs['anchor'] ) ? ' id="' . esc_attr( $attrs['anchor'] ) . '"' : '';
	return '<!-- wp:group' . ap_attrs( $attrs ) . " -->\n<$tag class=\"" . implode( ' ', $classes ) . "\"$id" . ( $style ? " style=\"$style\"" : '' ) . ">$inner</$tag>\n<!-- /wp:group -->";
}
/** A full-width band, with the theme's section style when given (tint, dark, brand-soft...). */
function ap_band( $inner, $section = '', $anchor = '', $top = 'xx-large', $bottom = 'xx-large', $extra = array() ) {
	$attrs = array( 'tagName' => 'section' );
	if ( $anchor ) {
		$attrs['anchor'] = $anchor;
	}
	$attrs += array( 'align' => 'full' );
	if ( $section ) {
		$attrs['className'] = "is-style-section-$section";
	}
	$attrs['style'] = array(
		'spacing' => array(
			'margin'  => array( 'top' => '0', 'bottom' => '0' ),
			'padding' => ap_pad( $top, $bottom, 'medium', 'medium' ),
		),
	);
	$attrs += $extra + array( 'layout' => array( 'type' => 'constrained', 'contentSize' => '72rem' ) );
	unset( $attrs['tagName'] );
	return ap_group( $inner, $attrs, 'section' );
}
/** A centered section head: handwritten note, heading, lead. */
function ap_head( $note, $title, $lead = '', $anchor = '' ) {
	$out = ( $note ? ap_p( ap_e( $note ), array( 'align' => 'center', 'className' => 'is-style-hand' ) ) . "\n\n" : '' )
		. ap_h( ap_e( $title ), 2, array_filter( array( 'textAlign' => 'center', 'anchor' => $anchor ) ) );
	if ( $lead ) {
		$out .= "\n\n" . ap_p( ap_e( $lead ), array( 'align' => 'center', 'textColor' => 'secondary', 'fontSize' => 'medium' ) );
	}
	return ap_group( $out, array( 'style' => array( 'spacing' => array( 'margin' => array( 'bottom' => 'var:preset|spacing|large' ) ) ), 'layout' => array( 'type' => 'constrained', 'contentSize' => '44rem' ) ) );
}
function ap_img( $src, $alt = '', $width = '', $class = '' ) {
	$attrs = array();
	if ( $width ) {
		$attrs['width'] = $width;
	}
	$attrs += array( 'sizeSlug' => 'full', 'linkDestination' => 'none' );
	if ( $class ) {
		$attrs['className'] = $class;
	}
	return '<!-- wp:image' . ap_attrs( $attrs ) . " -->\n<figure class=\"wp-block-image size-full" . ( $width ? ' is-resized' : '' ) . ( $class ? " $class" : '' ) . '"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '"' . ( $width ? " style=\"width:$width;height:auto\"" : '' ) . "/></figure>\n<!-- /wp:image -->";
}
function ap_list( $items, $class = 'is-style-ticks' ) {
	$lis = array_map( static fn( $i ) => "<!-- wp:list-item -->\n<li>$i</li>\n<!-- /wp:list-item -->", $items );
	return '<!-- wp:list' . ap_attrs( $class ? array( 'className' => $class ) : array() ) . " -->\n<ul class=\"wp-block-list" . ( $class ? " $class" : '' ) . '">' . implode( "\n\n", $lis ) . "</ul>\n<!-- /wp:list -->";
}
/** Buttons: each [ label, url, 'outline'|'' ]. */
function ap_buttons( $buttons, $justify = '' ) {
	$attrs = $justify ? array( 'layout' => array( 'type' => 'flex', 'justifyContent' => $justify ) ) : array();
	$out   = array();
	foreach ( $buttons as $b ) {
		$outline = ! empty( $b[2] );
		$out[]   = '<!-- wp:button' . ap_attrs( $outline ? array( 'className' => 'is-style-outline' ) : array() ) . " -->\n<div class=\"wp-block-button" . ( $outline ? ' is-style-outline' : '' ) . '"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $b[1] ) . '">' . ap_e( $b[0] ) . "</a></div>\n<!-- /wp:button -->";
	}
	return '<!-- wp:buttons' . ap_attrs( $attrs ) . " -->\n<div class=\"wp-block-buttons\">" . implode( "\n\n", $out ) . "</div>\n<!-- /wp:buttons -->";
}
/** Columns: each [ inner, width or '' ]. */
function ap_columns( $cols, $attrs = array() ) {
	$out = array();
	foreach ( $cols as $c ) {
		$ca    = array();
		$style = '';
		if ( ! empty( $c[1] ) ) {
			$ca['width'] = $c[1];
			$style       = ' style="flex-basis:' . $c[1] . '"';
		}
		if ( ! empty( $c[2] ) ) {
			$ca['className'] = $c[2];
		}
		$out[] = '<!-- wp:column' . ap_attrs( $ca ) . " -->\n<div class=\"wp-block-column" . ( ! empty( $c[2] ) ? ' ' . $c[2] : '' ) . "\"$style>" . $c[0] . "</div>\n<!-- /wp:column -->";
	}
	$classes = 'wp-block-columns' . ( isset( $attrs['verticalAlignment'] ) ? ' are-vertically-aligned-' . $attrs['verticalAlignment'] : '' ) . ( isset( $attrs['align'] ) ? ' align' . $attrs['align'] : '' );
	return '<!-- wp:columns' . ap_attrs( $attrs ) . " -->\n<div class=\"$classes\">" . implode( "\n\n", $out ) . "</div>\n<!-- /wp:columns -->";
}
/** A grid of items that wraps by itself, at least $min wide each. */
function ap_grid( $items, $min = '14rem', $gap = 'medium' ) {
	return ap_group(
		implode( "\n\n", $items ),
		array(
			'style'  => array( 'spacing' => array( 'blockGap' => "var:preset|spacing|$gap" ) ),
			'layout' => array( 'type' => 'grid', 'minimumColumnWidth' => $min ),
		)
	);
}
function ap_card( $inner, $style = 'card-line', $pad = 'medium' ) {
	return ap_group(
		$inner,
		array(
			'className' => "is-style-$style",
			'style'     => array( 'spacing' => array( 'padding' => ap_pad( $pad, $pad, $pad, $pad ), 'blockGap' => 'var:preset|spacing|small' ) ),
			'layout'    => array( 'type' => 'flex', 'orientation' => 'vertical', 'justifyContent' => 'stretch' ),
		)
	);
}
function ap_details( $q, $a ) {
	return "<!-- wp:details -->\n<details class=\"wp-block-details\"><summary>" . ap_e( $q ) . '</summary>' . ap_p( ap_e( $a ) ) . "</details>\n<!-- /wp:details -->";
}
function ap_shortcode( $code ) {
	return "<!-- wp:shortcode -->\n$code\n<!-- /wp:shortcode -->";
}

// ---------------------------------------------------------------------------------------------------------- shared sections
$ap_type_by_id = array_column( $ap_data['types'], null, 'id' );
$ap_from       = static fn( $type_id ) => $ap_plans[ $type_id ]['small']['monthly'] ?? 0;
$ap_mailto     = 'mailto:support@anypress.io';
define( 'ANYPRESS_SHOP_MAILTO', $ap_mailto );

/** "Every plan includes": six tiles with drawings. */
function ap_included( $data, $section = 'tint' ) {
	$tiles = array();
	foreach ( $data['included'] as list( $drawing, $title, $body ) ) {
		$tiles[] = ap_group(
			ap_img( ap_asset( "$drawing.svg" ), '', '84px' ) . "\n\n" . ap_h( ap_e( $title ), 3, array( 'fontSize' => 'medium' ) ) . "\n\n" . ap_p( ap_e( $body ), array( 'textColor' => 'secondary' ) ),
			array( 'style' => array( 'spacing' => array( 'blockGap' => 'var:preset|spacing|small' ) ), 'layout' => array( 'type' => 'flex', 'orientation' => 'vertical' ) )
		);
	}
	return ap_band( ap_head( 'Looked after for you', 'Every plan includes', 'Whatever kind of site and size you pick, these come with it.' ) . "\n\n" . ap_grid( $tiles, '17rem', 'large' ), $section, 'included' );
}

/** The sizes side by side. With $type_id, the price row and links are that kind's; the current size is marked. */
function ap_sizes_table( $data, $plans, $type_id = '', $current = '' ) {
	$sizes = $data['sizes'];
	$head  = '<th>' . ap_e( 'Size' ) . '</th>';
	foreach ( $sizes as $s ) {
		$label = $s['name'] . ( $current === $s['id'] ? ' (this one)' : '' );
		$head .= '<th>' . ap_e( $label ) . '</th>';
	}
	$rows = array(
		array( 'Best for', array_column( $sizes, 'for' ) ),
		array( 'Computing power', array_column( $sizes, 'cpu' ) ),
		array( 'Memory', array_column( $sizes, 'memory' ) ),
		array( 'Storage', array_column( $sizes, 'storage' ) ),
		array( 'Newsletter emails a month', array_column( $sizes, 'emails' ) ),
		array( 'Backups', array_fill( 0, 3, 'Nightly, kept 7 days, plus weekly kept 4 weeks' ) ),
	);
	if ( $type_id ) {
		$prices = array();
		foreach ( $sizes as $s ) {
			$plan     = $plans[ $type_id ][ $s['id'] ] ?? null;
			$prices[] = $plan ? '<strong>' . ap_money( $plan['monthly'] ) . '</strong> a month<br>or ' . ap_money( $plan['yearly'] ) . ' a year' . ( $current === $s['id'] ? '' : '<br><a href="' . esc_url( $plan['url'] ) . '">Choose ' . ap_e( $s['name'] ) . '</a>' ) : '';
		}
		array_unshift( $rows, array( 'Price', $prices, true ) );
	} else {
		array_unshift( $rows, array( 'Price', array( 'The plan’s price', 'Twice the Small price', 'Four times the Small price' ) ) );
	}
	$body = '';
	foreach ( $rows as $r ) {
		$body .= '<tr><td>' . ap_e( $r[0] ) . '</td>';
		foreach ( $r[1] as $cell ) {
			$body .= '<td>' . ( empty( $r[2] ) ? ap_e( $cell ) : $cell ) . '</td>';
		}
		$body .= '</tr>';
	}
	return "<!-- wp:table {\"hasFixedLayout\":false,\"className\":\"is-style-compare\"} -->\n<figure class=\"wp-block-table is-style-compare\"><table><thead><tr>$head</tr></thead><tbody>$body</tbody></table></figure>\n<!-- /wp:table -->";
}

function ap_faq( $data, $items = null, $section = '' ) {
	$items = $items ?? $data['faq'];
	$qs    = implode( "\n\n", array_map( static fn( $f ) => ap_details( $f[0], $f[1] ), $items ) );
	return ap_band(
		ap_head( 'Good to know', 'Questions people ask' ) . "\n\n" . ap_group( $qs, array( 'className' => 'anypress-faq', 'layout' => array( 'type' => 'constrained', 'contentSize' => '46rem' ) ) ),
		$section,
		'questions'
	);
}

function ap_help_band() {
	$ap_mailto = ANYPRESS_SHOP_MAILTO;
	return ap_band(
		ap_columns(
			array(
				array( ap_p( ap_e( 'Not sure which to pick?' ), array( 'className' => 'is-style-hand' ) ) . "\n\n" . ap_h( ap_e( 'Ask a person. We answer every email.' ), 2, array( 'fontSize' => 'x-large' ) ) . "\n\n" . ap_p( ap_e( 'Tell us what your business does and we’ll tell you which plan and size fit. No sales call, just a reply.' ) ), '62%' ),
				array( ap_buttons( array( array( 'Email support@anypress.io', $ap_mailto ), array( 'Read the help pages', home_url( '/docs/' ), 'outline' ) ) ), '38%' ),
			),
			array( 'verticalAlignment' => 'center' )
		),
		'dark',
		'help'
	);
}

// ---------------------------------------------------------------------------------------------------------- pages
/** Creates or updates a page by slug. */
function ap_page( $slug, $title, $content, $template = '', $id = 0 ) {
	$page = $id ? get_post( $id ) : get_page_by_path( $slug );
	$args = array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => $title,
		'post_content' => wp_slash( $content ),
		'page_template' => $template,
	);
	if ( $page ) {
		$args['ID'] = $page->ID;
		return wp_update_post( $args );
	}
	$args['post_name'] = $slug;
	return wp_insert_post( $args );
}

// Home --------------------------------------------------------------------------------------------------------
$ap_type_cards = array();
foreach ( $ap_data['types'] as $ap_type ) {
	$ap_small        = $ap_plans[ $ap_type['id'] ]['small'] ?? null;
	$ap_type_cards[] = ap_card(
		ap_img( ap_asset( $ap_type['drawing'] . '.svg' ), '', '110px' ) . "\n\n"
		. ap_h( ap_e( $ap_type['name'] ), 3, array( 'fontSize' => 'medium' ) ) . "\n\n"
		. ap_p( ap_e( $ap_type['pitch'] ), array( 'textColor' => 'secondary' ) ) . "\n\n"
		. ap_list( array_map( 'ap_e', $ap_type['inBox'] ) ) . "\n\n"
		. ap_p( $ap_small ? 'From <strong>' . ap_money( $ap_small['monthly'] ) . '</strong>/month' : '', array( 'className' => 'anypress-from' ) ) . "\n\n"
		. ap_buttons( array( array( 'See plans', get_permalink( $ap_plans_page ) . '#' . $ap_type['id'] ) ) ),
		'card-line',
		'small'
	);
}
$ap_steps = array();
foreach (
	array(
		array( 'pick', '1', 'Pick a kind and a size', 'Choose what you’re making and how big it needs to be. Pay monthly, or yearly and get two months free.' ),
		array( 'card', '2', 'Pay securely', 'Checkout takes a minute. Your first payment is covered by our 30-day money-back guarantee.' ),
		array( 'open', '3', 'Sign in to your new site', 'Your site is usually ready a few minutes later. We email you, and your account opens it with one click.' ),
	) as list( $ap_d, $ap_n, $ap_t, $ap_b )
) {
	$ap_steps[] = ap_group(
		ap_img( ap_asset( "$ap_d.svg" ), '', '120px' ) . "\n\n" . ap_p( ap_e( "Step $ap_n" ), array( 'className' => 'is-style-hand' ) ) . "\n\n" . ap_h( ap_e( $ap_t ), 3, array( 'fontSize' => 'medium' ) ) . "\n\n" . ap_p( ap_e( $ap_b ), array( 'textColor' => 'secondary' ) ),
		array( 'style' => array( 'spacing' => array( 'blockGap' => 'var:preset|spacing|small' ) ), 'layout' => array( 'type' => 'flex', 'orientation' => 'vertical' ) )
	);
}
$ap_home = ap_band(
	ap_columns(
		array(
			array(
				ap_p( ap_e( 'No tech skills needed' ), array( 'className' => 'is-style-hand' ) ) . "\n\n"
				. ap_h( 'Pick a website. It’s <mark style="background-color:var(--wp--preset--color--primary-alt)" class="has-inline-color">ready in minutes.</mark>', 1, array( 'fontSize' => 'xx-large' ) ) . "\n\n"
				. ap_p( ap_e( 'Real WordPress, set up and looked after for you. Choose what you’re making, pick a size, and sign in to a working site a few minutes after you pay.' ), array( 'textColor' => 'secondary', 'fontSize' => 'medium' ) ) . "\n\n"
				. ap_buttons( array( array( 'See the plans', '#types' ), array( 'Compare sizes', '#sizes', 'outline' ) ) ) . "\n\n"
				. ap_list( array( 'From ' . ap_money( $ap_from( 'website' ) ) . ' a month', '30-day money-back guarantee', 'Cancel any time from your account' ), 'is-style-ticks anypress-inline-ticks' ),
				'54%',
			),
			array( ap_img( ap_asset( 'website.jpg' ), 'An example website made with AnyPress: Juniper Street Bakery', '', 'is-style-sketch anypress-tilt' ), '46%' ),
		),
		array( 'verticalAlignment' => 'center', 'style' => array( 'spacing' => array( 'blockGap' => array( 'left' => 'var:preset|spacing|x-large' ) ) ) )
	),
	'',
	'',
	'x-large',
	'xx-large'
) . "\n\n"
. ap_band( ap_head( 'Start here', 'What are you making?', 'Five kinds of website, each set up with the tools it needs. Every one is real WordPress you can change later.', 'types' ) . "\n\n" . ap_grid( $ap_type_cards, '12.5rem', 'small' ), 'tint', 'types-band', 'xx-large', 'xx-large', array( 'layout' => array( 'type' => 'constrained', 'contentSize' => '80rem' ) ) ) . "\n\n"
. ap_band( ap_head( 'Three sizes', 'Every size, side by side', 'All sizes have the same features. Bigger sizes have more power, storage and newsletter emails for busier sites.', 'sizes' ) . "\n\n" . ap_sizes_table( $ap_data, $ap_plans ) . "\n\n" . ap_p( ap_e( 'Paying yearly? You get two months free on every size.' ), array( 'align' => 'center', 'textColor' => 'secondary' ) ) ) . "\n\n"
. ap_band( ap_head( 'How it works', 'From checkout to your own site in minutes' ) . "\n\n" . ap_grid( $ap_steps, '16rem', 'large' ), 'brand-soft', 'how' ) . "\n\n"
. ap_included( $ap_data, '' ) . "\n\n"
. ap_faq( $ap_data, null, 'tint' ) . "\n\n"
. ap_help_band();

$ap_home_id = ap_page( 'home', 'AnyPress: websites, ready in minutes', $ap_home, 'page-no-title' );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $ap_home_id );

// Plans -------------------------------------------------------------------------------------------------------
$ap_plan_sections = array();
$ap_band_styles   = array( '', 'tint' );
foreach ( $ap_data['types'] as $ap_i => $ap_type ) {
	$ap_size_cards = array();
	foreach ( $ap_data['sizes'] as $ap_size ) {
		$ap_plan = $ap_plans[ $ap_type['id'] ][ $ap_size['id'] ] ?? null;
		if ( ! $ap_plan ) {
			continue;
		}
		$ap_size_cards[] = ap_card(
			ap_p( ap_e( $ap_size['name'] ), array( 'className' => 'anypress-size-name' ) ) . "\n\n"
			. ap_p( '<strong>' . ap_money( $ap_plan['monthly'] ) . '</strong>/month', array( 'className' => 'anypress-price' ) ) . "\n\n"
			. ap_p( ap_e( 'or ' . ap_money( $ap_plan['yearly'] ) . ' a year, two months free' ), array( 'textColor' => 'secondary', 'fontSize' => 'small' ) ) . "\n\n"
			. ap_list( array( ap_e( $ap_size['cpu'] . ', ' . $ap_size['memory'] . ' memory' ), ap_e( $ap_size['storage'] . ' storage' ), ap_e( $ap_size['emails'] . ' newsletter emails a month' ) ), 'is-style-no-bullets anypress-specs' ) . "\n\n"
			. ap_buttons( array( array( 'Choose ' . $ap_size['name'], $ap_plan['url'] ) ) ),
			'small' === $ap_size['id'] ? 'sketch' : 'card-line'
		);
	}
	$ap_plan_sections[] = ap_band(
		ap_columns(
			array(
				array(
					ap_img( ap_asset( $ap_type['drawing'] . '.svg' ), '', '120px' ) . "\n\n"
					. ap_h( ap_e( $ap_type['name'] ), 2, array( 'anchor' => $ap_type['id'] ) ) . "\n\n"
					. ap_p( ap_e( $ap_type['pitch'] ), array( 'fontSize' => 'medium' ) ) . "\n\n"
					. ap_list( array_map( 'ap_e', $ap_type['inBox'] ) ) . "\n\n"
					. ap_p( 'See one working: <a href="' . esc_url( $ap_type['example']['url'] ) . '">' . ap_e( $ap_type['example']['name'] ) . '</a>, ' . ap_e( lcfirst( $ap_type['example']['what'] ) ), array( 'textColor' => 'secondary', 'fontSize' => 'small' ) ),
					'36%',
				),
				array( ap_grid( $ap_size_cards, '11rem', 'medium' ), '64%' ),
			),
			array( 'style' => array( 'spacing' => array( 'blockGap' => array( 'left' => 'var:preset|spacing|x-large' ) ) ) )
		),
		$ap_band_styles[ $ap_i % 2 ],
		'',
		'x-large',
		'x-large'
	);
}
$ap_email_band = $ap_email_pack ? ap_band(
	ap_card(
		ap_columns(
			array(
				array( ap_img( ap_asset( 'mailbox.svg' ), '', '110px' ), '18%' ),
				array( ap_h( ap_e( 'Need more newsletter emails?' ), 2, array( 'fontSize' => 'large' ) ) . "\n\n" . ap_p( ap_e( 'An email pack adds 10,000 emails to one of your websites, on top of its monthly allowance. Pack emails never expire, and buying one lifts a pause on campaigns right away.' ), array( 'textColor' => 'secondary' ) ), '57%' ),
				array( ap_buttons( array( array( 'Add an email pack', get_permalink( $ap_email_pack ) ) ) ), '25%' ),
			),
			array( 'verticalAlignment' => 'center' )
		),
		'sketch-rose',
		'large'
	),
	'',
	'email-packs',
	'x-large',
	'x-large'
) : '';
$ap_plans_content = ap_band(
	ap_group(
		ap_p( ap_e( 'Five kinds, three sizes' ), array( 'align' => 'center', 'className' => 'is-style-hand' ) ) . "\n\n"
		. ap_h( ap_e( 'Plans and prices' ), 1, array( 'textAlign' => 'center', 'fontSize' => 'xx-large' ) ) . "\n\n"
		. ap_p( ap_e( 'Pick the kind of site you’re making, then a size. Pay monthly, or yearly and get two months free. Every plan has a 30-day money-back guarantee.' ), array( 'align' => 'center', 'textColor' => 'secondary', 'fontSize' => 'medium' ) ) . "\n\n"
		. ap_group(
			implode(
				"\n\n",
				array_map( static fn( $t ) => ap_p( '<a href="#' . $t['id'] . '">' . ap_e( $t['short'] ) . '</a>', array( 'className' => 'anypress-chip' ) ), $ap_data['types'] )
			),
			array( 'className' => 'anypress-chips', 'layout' => array( 'type' => 'flex', 'flexWrap' => 'wrap', 'justifyContent' => 'center' ) )
		),
		array( 'layout' => array( 'type' => 'constrained', 'contentSize' => '46rem' ) )
	),
	'',
	'',
	'x-large',
	'large'
) . "\n\n"
. implode( "\n\n", $ap_plan_sections ) . "\n\n"
. $ap_email_band . "\n\n"
. ap_band( ap_head( 'Three sizes', 'Compare the sizes', 'Same features in every size. Bigger sizes have more room for busy sites.', 'sizes' ) . "\n\n" . ap_sizes_table( $ap_data, $ap_plans ), 'tint' ) . "\n\n"
. ap_faq( $ap_data, array_slice( $ap_data['faq'], 1, 4 ) ) . "\n\n"
. ap_help_band();
if ( $ap_plans_page ) {
	ap_page( 'shop', 'Plans', $ap_plans_content, 'page-no-title', $ap_plans_page );
}

// Cart, checkout, account, receipt: FluentCart's own blocks, with a heading and the reassurance around them. Their
// original content is kept in _anypress_original, so running this again starts from it.
function ap_wrap_store_page( $id, $title, $lead, $after = '', $template = 'page-no-title' ) {
	if ( ! $id || ! get_post( $id ) ) {
		return;
	}
	$original = get_post_meta( $id, '_anypress_original', true );
	if ( '' === $original ) {
		$original = get_post( $id )->post_content;
		update_post_meta( $id, '_anypress_original', wp_slash( $original ) );
	}
	$head = ap_band(
		ap_h( ap_e( $title ), 1, array( 'fontSize' => 'x-large' ) ) . ( $lead ? "\n\n" . ap_p( ap_e( $lead ), array( 'textColor' => 'secondary' ) ) : '' ),
		'',
		'',
		'large',
		'small',
		array( 'layout' => array( 'type' => 'constrained', 'contentSize' => '72rem' ) )
	);
	// FluentCart's older pages hold a bare shortcode; a Shortcode block keeps the page valid in the editor.
	$inner = has_blocks( $original ) ? $original : ap_shortcode( trim( $original ) );
	$body  = ap_band( $inner, '', '', 'small', 'xx-large', array( 'className' => 'anypress-store-page' ) );
	ap_page( get_post( $id )->post_name, get_post( $id )->post_title, $head . "\n\n" . $body . ( $after ? "\n\n" . $after : '' ), $template, $id );
}
$ap_trust = ap_band(
	ap_grid(
		array(
			ap_list( array( '<strong>30-day money-back guarantee</strong> on your first payment' ) ),
			ap_list( array( '<strong>Cancel any time</strong> from your account' ) ),
			ap_list( array( '<strong>Ready in minutes</strong>, and we email you the link' ) ),
		),
		'15rem'
	),
	'tint',
	'',
	'medium',
	'medium'
);
ap_wrap_store_page( (int) $ap_settings->get( 'cart_page_id' ), 'Your cart', 'Check your plan, then go to checkout.', $ap_trust );
ap_wrap_store_page( (int) $ap_settings->get( 'checkout_page_id' ), 'Checkout', 'A minute to finish. Your site is set up as soon as you pay.', $ap_trust, '' );
// The checkout page takes the page-checkout template below (no menu) by its slug.
ap_wrap_store_page( (int) $ap_settings->get( 'customer_profile_page_id' ), 'Your account', 'Open your websites, change your plan and get your invoices.', ap_help_band() );
ap_wrap_store_page( (int) $ap_settings->get( 'receipt_page_id' ), 'Thank you', 'Your website is being set up. We’ll email you as soon as it’s ready, usually in a few minutes.' );

// ---------------------------------------------------------------------------------------------------------- plan pages
// Each plan's words: what the kind of site does, this size against the others, what every plan includes, questions.
foreach ( $ap_data['types'] as $ap_type ) {
	foreach ( $ap_data['sizes'] as $ap_size ) {
		$ap_plan = $ap_plans[ $ap_type['id'] ][ $ap_size['id'] ] ?? null;
		if ( ! $ap_plan ) {
			continue;
		}
		$ap_features = array();
		foreach ( $ap_type['features'] as list( $ap_t, $ap_b ) ) {
			$ap_features[] = ap_card( ap_h( ap_e( $ap_t ), 3, array( 'fontSize' => 'medium' ) ) . "\n\n" . ap_p( ap_e( $ap_b ), array( 'textColor' => 'secondary' ) ) );
		}
		$ap_content = ap_band(
			ap_head( 'What you get', $ap_type['headline'], $ap_type['pitch'] ) . "\n\n" . ap_grid( $ap_features, '15rem' ) . "\n\n"
			. ap_p( 'Made for ' . ap_e( implode( ', ', array_map( 'lcfirst', array_slice( $ap_type['idealFor'], 0, -1 ) ) ) . ' and ' . lcfirst( end( $ap_type['idealFor'] ) ) ) . '. See one working: <a href="' . esc_url( $ap_type['example']['url'] ) . '">' . ap_e( $ap_type['example']['name'] ) . '</a>.', array( 'align' => 'center', 'textColor' => 'secondary' ) ),
			'',
			'what'
		) . "\n\n"
		. ap_band( ap_head( 'Pick your size', 'This size and the others', 'Every size has the same features. You can move to another size later: email us and it changes from your next renewal.', 'sizes' ) . "\n\n" . ap_sizes_table( $ap_data, $ap_plans, $ap_type['id'], $ap_size['id'] ), 'tint' ) . "\n\n"
		. ap_included( $ap_data, '' ) . "\n\n"
		. ap_faq( $ap_data, null, 'tint' );
		wp_update_post(
			array(
				'ID'           => $ap_plan['id'],
				'post_content' => wp_slash( $ap_content ),
				'post_excerpt' => wp_slash( $ap_type['pitch'] . ' ' . $ap_size['name'] . ': ' . $ap_size['cpu'] . ', ' . $ap_size['storage'] . ' storage, ' . $ap_size['emails'] . ' newsletter emails a month.' ),
			)
		);
	}
}
if ( $ap_email_pack ) {
	wp_update_post(
		array(
			'ID'           => $ap_email_pack->ID,
			'post_excerpt' => wp_slash( '10,000 extra newsletter emails for one of your websites, on top of its monthly allowance. They never expire.' ),
			'post_content' => wp_slash(
				ap_band(
					ap_head( 'How email packs work', 'More emails, whenever you need them' ) . "\n\n"
					. ap_group(
						ap_list(
							array(
								'Order, account and booking emails always send, whatever your allowance.',
								'Past your monthly allowance, newsletters and email sequences pause until next month.',
								'An email pack adds 10,000 emails to one website. Buying one lifts a pause right away.',
								'Pack emails never expire: they’re used only after the monthly allowance runs out.',
							)
						),
						array( 'layout' => array( 'type' => 'constrained', 'contentSize' => '40rem' ) )
					)
				)
			),
		)
	);
}

// ---------------------------------------------------------------------------------------------------------- menu, header, footer
function ap_nav_link( $label, $url ) {
	return '<!-- wp:navigation-link ' . serialize_block_attributes( array( 'label' => $label, 'url' => $url, 'kind' => 'custom' ) ) . ' /-->';
}
$ap_account = get_permalink( (int) $ap_settings->get( 'customer_profile_page_id' ) );
$ap_cart    = get_permalink( (int) $ap_settings->get( 'cart_page_id' ) );
$ap_plans_u = get_permalink( $ap_plans_page );
$ap_menu    = implode(
	"\n",
	array(
		ap_nav_link( 'Plans', $ap_plans_u ),
		ap_nav_link( 'Compare sizes', $ap_plans_u . '#sizes' ),
		ap_nav_link( 'Help', home_url( '/docs/' ) ),
		ap_nav_link( 'Sell websites', 'https://anypress.io/sell' ),
		ap_nav_link( 'Account', $ap_account ),
	)
);
$ap_nav = get_posts( array( 'post_type' => 'wp_navigation', 'name' => 'anypress-shop-menu', 'numberposts' => 1, 'post_status' => 'any' ) );
$ap_nav = $ap_nav ? wp_update_post( array( 'ID' => $ap_nav[0]->ID, 'post_content' => wp_slash( $ap_menu ) ) ) : wp_insert_post(
	array(
		'post_type'    => 'wp_navigation',
		'post_status'  => 'publish',
		'post_title'   => 'Shop menu',
		'post_name'    => 'anypress-shop-menu',
		'post_content' => wp_slash( $ap_menu ),
	)
);

/** Saves a template part or template for the active theme, replacing an earlier copy. */
function ap_template( $type, $slug, $title, $content, $area = '' ) {
	foreach ( get_posts( array( 'post_type' => $type, 'name' => $slug, 'numberposts' => -1, 'post_status' => 'any', 'tax_query' => array( array( 'taxonomy' => 'wp_theme', 'field' => 'name', 'terms' => get_stylesheet() ) ) ) ) as $old ) {
		wp_delete_post( $old->ID, true );
	}
	$id = wp_insert_post(
		array(
			'post_type'    => $type,
			'post_status'  => 'publish',
			'post_name'    => $slug,
			'post_title'   => $title,
			'post_content' => wp_slash( $content ),
		)
	);
	wp_set_object_terms( $id, get_stylesheet(), 'wp_theme' );
	if ( $area ) {
		wp_set_object_terms( $id, $area, 'wp_template_part_area' );
	}
	return $id;
}

$ap_logo = '<!-- wp:image {"width":"132px","sizeSlug":"full","linkDestination":"custom","className":"anypress-logo"} -->' . "\n"
	. '<figure class="wp-block-image size-full is-resized anypress-logo"><a href="' . esc_url( home_url( '/' ) ) . '"><img src="' . esc_url( ap_asset( 'logo.svg' ) ) . '" alt="AnyPress" style="width:132px;height:auto"/></a></figure>' . "\n<!-- /wp:image -->";
$ap_announce = ap_group(
	ap_p( '30-day money-back guarantee · Ready in minutes · Cancel any time', array( 'align' => 'center', 'fontSize' => 'x-small' ) ),
	array(
		'align'           => 'full',
		'className'       => 'anypress-announce',
		'backgroundColor' => 'primary-alt',
		'textColor'       => 'primary-alt-accent',
		'style'           => array( 'spacing' => array( 'padding' => array( 'top' => '0.45rem', 'bottom' => '0.45rem' ) ) ),
	)
);
$ap_header = $ap_announce . "\n\n" . ap_group(
	ap_group(
		$ap_logo . "\n\n"
		. ap_group(
			'<!-- wp:navigation {"ref":' . (int) $ap_nav . ',"overlayMenu":"mobile","layout":{"type":"flex","justifyContent":"right"}} /-->' . "\n\n"
			. ap_p( '<a href="' . esc_url( $ap_cart ) . '">Cart</a>', array( 'className' => 'anypress-cart-link' ) ) . "\n\n"
			. ap_buttons( array( array( 'See the plans', $ap_plans_u ) ) ),
			array( 'style' => array( 'spacing' => array( 'blockGap' => 'var:preset|spacing|medium' ) ), 'layout' => array( 'type' => 'flex', 'flexWrap' => 'nowrap', 'justifyContent' => 'right' ) )
		),
		array( 'layout' => array( 'type' => 'flex', 'flexWrap' => 'nowrap', 'justifyContent' => 'space-between' ) )
	),
	array(
		'align'     => 'full',
		'className' => 'is-style-sticky anypress-header',
		'backgroundColor' => 'base',
		'style'     => array( 'spacing' => array( 'padding' => array( 'top' => 'var:preset|spacing|small', 'right' => 'var:preset|spacing|medium', 'bottom' => 'var:preset|spacing|small', 'left' => 'var:preset|spacing|medium' ) ) ),
		'layout'    => array( 'type' => 'constrained', 'contentSize' => '80rem' ),
	)
);
ap_template( 'wp_template_part', 'header', 'Header', $ap_header, 'header' );

ap_template(
	'wp_template_part',
	'header-checkout',
	'Checkout header',
	ap_group(
		ap_group(
			$ap_logo . "\n\n" . ap_p( '<a href="' . esc_url( $ap_plans_u ) . '">← Back to the plans</a>', array( 'fontSize' => 'small' ) ),
			array( 'layout' => array( 'type' => 'flex', 'flexWrap' => 'wrap', 'justifyContent' => 'space-between' ) )
		),
		array(
			'align'     => 'full',
			'className' => 'anypress-header',
			'style'     => array( 'spacing' => array( 'padding' => array( 'top' => 'var:preset|spacing|small', 'right' => 'var:preset|spacing|medium', 'bottom' => 'var:preset|spacing|small', 'left' => 'var:preset|spacing|medium' ) ) ),
			'layout'    => array( 'type' => 'constrained', 'contentSize' => '72rem' ),
		)
	),
	'header'
);

$ap_footer_col = static fn( $title, $links ) => ap_h( ap_e( $title ), 3, array( 'fontSize' => 'small' ) ) . "\n\n" . ap_list( array_map( static fn( $l ) => '<a href="' . esc_url( $l[1] ) . '">' . ap_e( $l[0] ) . '</a>', $links ), 'is-style-no-bullets' );
$ap_footer     = ap_group(
	ap_columns(
		array(
			array( ap_img( ap_asset( 'logo-dark.svg' ), 'AnyPress', '132px' ) . "\n\n" . ap_p( ap_e( 'Real WordPress, set up and looked after for you.' ), array( 'textColor' => 'main-accent' ) ) . "\n\n" . ap_p( 'Questions? <a href="' . esc_url( $ap_mailto ) . '">support@anypress.io</a>' ), '34%' ),
			array( $ap_footer_col( 'Shop', array( array( 'Plans', $ap_plans_u ), array( 'Compare sizes', $ap_plans_u . '#sizes' ), array( 'Email packs', $ap_plans_u . '#email-packs' ), array( 'Your account', $ap_account ), array( 'Cart', $ap_cart ) ) ) ),
			array( $ap_footer_col( 'Help', array( array( 'Help pages', home_url( '/docs/' ) ), array( 'Refund policy', home_url( '/refund-policy/' ) ), array( 'Terms of service', home_url( '/terms/' ) ), array( 'Privacy policy', home_url( '/privacy/' ) ) ) ) ),
			array( $ap_footer_col( 'AnyPress', array( array( 'anypress.io', 'https://anypress.io' ), array( 'Sell websites', 'https://anypress.io/sell' ), array( 'Affiliates', home_url( '/affiliates/' ) ) ) ) ),
		),
		array( 'style' => array( 'spacing' => array( 'blockGap' => array( 'left' => 'var:preset|spacing|large' ) ) ) )
	) . "\n\n"
	. ap_p( '© AnyPress, by Otaku Solutions.', array( 'textColor' => 'main-accent', 'fontSize' => 'x-small' ) ),
	array(
		'align'     => 'full',
		'className' => 'is-style-section-dark anypress-footer',
		'style'     => array( 'spacing' => array( 'margin' => array( 'top' => '0' ), 'padding' => array( 'top' => 'var:preset|spacing|x-large', 'right' => 'var:preset|spacing|medium', 'bottom' => 'var:preset|spacing|large', 'left' => 'var:preset|spacing|medium' ) ) ),
		'layout'    => array( 'type' => 'constrained', 'contentSize' => '72rem' ),
	)
);
ap_template( 'wp_template_part', 'footer', 'Footer', $ap_footer, 'footer' );

// Checkout without the menu, so nothing pulls buyers away mid-payment.
ap_template(
	'wp_template',
	'page-checkout',
	'Checkout',
	'<!-- wp:template-part {"slug":"header-checkout","tagName":"header"} /-->' . "\n\n"
	. '<!-- wp:group {"tagName":"main","style":{"spacing":{"margin":{"top":"0"}}},"layout":{"type":"constrained"}} -->' . "\n"
	. '<main class="wp-block-group" style="margin-top:0"><!-- wp:post-content {"align":"full","layout":{"type":"constrained"}} /--></main>' . "\n<!-- /wp:group -->\n\n"
	. '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->'
);

// A plan's page: the example site on the left; on the right the plan, its sizes, monthly or yearly, and buy.
$ap_buy_column = ap_p( ap_e( 'Ready a few minutes after you pay' ), array( 'className' => 'is-style-hand' ) ) . "\n\n"
	. '<!-- wp:post-title {"level":1,"fontSize":"x-large"} /-->' . "\n\n"
	. '<!-- wp:fluent-cart/excerpt /-->' . "\n\n"
	. ap_shortcode( '[anypress_sizes]' ) . "\n\n"
	. '<!-- wp:fluent-cart/buy-section {"inside_product_info":"yes"} /-->' . "\n\n"
	. ap_list( array( 'Pay yearly and get two months free', '30-day money-back guarantee', 'Cancel any time from your account', 'A free anypress.site address, or your own domain', 'Secure card checkout' ) );
$ap_single     = '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' . "\n\n"
	. '<!-- wp:group {"tagName":"main","align":"full","style":{"spacing":{"margin":{"top":"0"}}},"layout":{"type":"constrained"}} -->' . "\n"
	. '<main class="wp-block-group alignfull" style="margin-top:0">'
	. ap_group(
		ap_shortcode( '[anypress_crumbs]' ) . "\n\n"
		. '<!-- wp:fluent-cart/product-info {"className":"anypress-product"} -->' . "\n"
		. '<div class="wp-block-fluent-cart-product-info anypress-product">'
		. ap_columns(
			array(
				array( ap_group( '<!-- wp:fluent-cart/product-gallery {"inside_product_info":"yes"} /-->', array( 'className' => 'is-style-sketch anypress-gallery', 'layout' => array( 'type' => 'default' ) ) ), '55%' ),
				array( $ap_buy_column, '45%', 'anypress-buy' ),
			),
			array( 'style' => array( 'spacing' => array( 'blockGap' => array( 'left' => 'var:preset|spacing|x-large' ) ) ) )
		)
		. "</div>\n<!-- /wp:fluent-cart/product-info -->",
		array(
			'align' => 'full',
			'style' => array( 'spacing' => array( 'padding' => ap_pad( 'medium', 'large', 'medium', 'medium' ) ) ),
			'layout' => array( 'type' => 'constrained', 'contentSize' => '72rem' ),
		)
	)
	. "\n\n" . '<!-- wp:post-content {"align":"full","layout":{"type":"constrained"}} /-->'
	. "</main>\n<!-- /wp:group -->\n\n"
	. '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
ap_template( 'wp_template', 'single-fluent-products', 'Single Product', $ap_single );

// The help pages' home (BetterDocs): a heading and its search, the topics, the questions, then the way to a person.
if ( post_type_exists( 'docs' ) ) {
	ap_template(
		'wp_template',
		'archive-docs',
		'Docs',
		'<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' . "\n\n"
		. '<!-- wp:group {"tagName":"main","style":{"spacing":{"margin":{"top":"0"}}},"layout":{"type":"constrained"}} -->' . "\n"
		. '<main class="wp-block-group" style="margin-top:0">'
		. ap_band(
			ap_group(
				ap_p( ap_e( 'We’re here to help' ), array( 'align' => 'center', 'className' => 'is-style-hand' ) ) . "\n\n"
				. ap_h( ap_e( 'Help pages' ), 1, array( 'textAlign' => 'center', 'fontSize' => 'xx-large' ) ) . "\n\n"
				. ap_p( ap_e( 'Short answers about your website, your plan and selling websites. Search, or pick a topic.' ), array( 'align' => 'center', 'textColor' => 'secondary', 'fontSize' => 'medium' ) ) . "\n\n"
				. '<!-- wp:betterdocs/searchbox {"blockId":"anypress-docs-search"} /-->',
				array( 'layout' => array( 'type' => 'constrained', 'contentSize' => '44rem' ) )
			),
			'tint',
			'',
			'x-large',
			'x-large'
		) . "\n\n"
		. ap_band( '<!-- wp:betterdocs/categorygrid {"blockId":"anypress-docs-topics"} /-->' . "\n\n" . '<!-- wp:betterdocs/faq {"blockId":"anypress-docs-faq"} /-->', '', 'topics', 'x-large', 'x-large', array( 'className' => 'anypress-docs', 'layout' => array( 'type' => 'constrained', 'contentSize' => '72rem' ) ) )
		. "\n\n" . ap_help_band()
		. "</main>\n<!-- /wp:group -->\n\n"
		. '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->'
	);
}

update_option( 'anypress_shop_design', gmdate( 'c' ) );
echo wp_json_encode(
	array(
		'home'  => get_permalink( $ap_home_id ),
		'plans' => $ap_plans_u,
		'kinds' => count( $ap_plans ),
		'plan'  => $ap_plans['website']['small']['url'] ?? '',
	)
) . "\n";
