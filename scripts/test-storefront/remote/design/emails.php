<?php
/**
 * The shop's emails in the AnyPress look, through settings only (FluentCart and FluentCRM keep their own layouts, so
 * plugin updates don't break them): the AnyPress logo, the same colours as the AnyPress API's account emails, a footer
 * naming support@anypress.io, and FluentCart replies going there (crm.php already sends CRM replies there). WaaS
 * Host's own emails get the logo and address from anypress-shop.php. Run by the design step after design.php has copied the images; safe to run again.
 *   wp eval-file emails.php --user=admin
 */
$ap_support = 'support@anypress.io';
$ap_up      = wp_get_upload_dir();
$ap_logo    = set_url_scheme( $ap_up['baseurl'] . '/anypress-shop/logo-email.png', 'https' );

if ( class_exists( '\FluentCart\Api\StoreSettings' ) ) {
	// Receipts and account emails open with the store logo (FluentCart shows it 50px high).
	( new \FluentCart\Api\StoreSettings() )->save( array( 'store_logo' => array( 'id' => '', 'url' => $ap_logo ) ) );
	\FluentCart\App\Services\Email\EmailNotifications::updateSettings(
		array(
			'from_name'      => 'AnyPress',
			'reply_to_name'  => 'AnyPress',
			'reply_to_email' => $ap_support,
			'email_footer'   => '<p>Questions? Write to <a href="mailto:' . $ap_support . '" style="color:#5f5a55">' . $ap_support . '</a> or just reply to this email.</p>',
		)
	);
	echo "FluentCart: logo, sender name, reply-to and footer set\n";
}

if ( defined( 'FLUENTCRM' ) ) {
	$ap_style = array(
		'content_width'         => 560,
		'content_border_radius' => '12px',
		'content_padding_top'   => '20px',
		'content_padding_right' => '32px',
		'content_padding_bottom' => '16px',
		'content_padding_left'  => '32px',
		'body_bg_color'         => '#faf8f5',
		'content_bg_color'      => '#ffffff',
		'headings_color'        => '#141414',
		'text_color'            => '#141414',
		'link_color'            => '#141414',
		'footer_text_color'     => '#5f5a55',
		'paragraph_line_height' => '1.55',
	);
	fluentcrm_update_option( 'global_email_style_config', array_merge( (array) fluentcrm_get_option( 'global_email_style_config', array() ), $ap_style ) );

	$ap_settings                   = (array) get_option( 'fluentcrm-global-settings', array() );
	$ap_email                      = (array) ( $ap_settings['email_settings'] ?? array() );
	$ap_email['from_name']         = 'AnyPress';
	$ap_email['email_footer']      = '{{crm.business_name}}, {{crm.business_address}}<br>You get these emails because you have an AnyPress account. <a href="##crm.manage_subscription_url##">Choose which emails you get</a> or <a href="##crm.unsubscribe_url##">unsubscribe</a>.';
	$ap_settings['email_settings'] = $ap_email;
	update_option( 'fluentcrm-global-settings', $ap_settings );

	// The automation emails themselves: each is written as plain paragraphs (crm-emails.json), so put it in the
	// AnyPress layout (the same as the API's account emails and WaaS Host's website emails) and send it as raw HTML,
	// with the unsubscribe links in its own footer. The source text comes from crm-emails.json by subject, so running
	// this again restyles from the original wording rather than wrapping twice.
	$ap_sources = array();
	$ap_json    = null;
	// In the repository it sits next to this folder; on the shop, where crm.php installed the automations from.
	foreach ( array( dirname( __DIR__ ) . '/crm-emails.json', '/opt/wpcli/x/crm-emails.json' ) as $ap_file ) {
		if ( ! $ap_json && is_readable( $ap_file ) ) {
			$ap_json = json_decode( (string) file_get_contents( $ap_file ), true );
		}
	}
	foreach ( (array) $ap_json as $ap_a ) {
		foreach ( $ap_a['emails'] as $ap_e ) {
			$ap_sources[ $ap_e['subject'] ] = $ap_e['body'];
		}
	}
	$ap_restyled = 0;
	foreach ( \FluentCrm\App\Models\FunnelCampaign::all() as $ap_campaign ) {
		$ap_body = $ap_sources[ $ap_campaign->email_subject ] ?? '';
		if ( '' === $ap_body ) {
			$ap_body = (string) $ap_campaign->email_body;
			if ( false !== strpos( $ap_body, 'data-anypress-email' ) ) {
				continue;
			}
		}
		$ap_campaign->email_body      = anypress_crm_email_html( $ap_body, (string) $ap_campaign->email_subject, (string) $ap_campaign->email_pre_header, $ap_support );
		$ap_campaign->design_template = 'raw_html';
		$ap_cs                        = (array) $ap_campaign->settings;
		$ap_cs['template_config']     = array_merge( (array) ( $ap_cs['template_config'] ?? array() ), $ap_style );
		$ap_campaign->settings        = $ap_cs;
		$ap_campaign->save();
		++$ap_restyled;
	}
	echo "FluentCRM: email style, sender name and footer set; $ap_restyled automation emails restyled\n";
}

/**
 * A CRM email in the AnyPress layout: logo, a card with the subject as its heading, the paragraphs, a paragraph that
 * holds only a link as a button (with the link written out), the sign-off as small print, and a footer with the
 * support address, the business line and the unsubscribe links.
 */
function anypress_crm_email_html( string $body, string $title, string $preheader, string $support ): string {
	$ink   = '#141414';
	$soft  = '#5f5a55';
	$line  = '#e9e4de';
	$paper = '#faf8f5';
	$font  = "-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif";
	$body  = (string) preg_replace_callback(
		'#<p>\s*<a href="([^"]*)">\s*(?:<strong>)?(.*?)(?:</strong>)?\s*</a>\s*</p>#s',
		static fn( $m ) => '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 12px"><tr><td style="background:' . $ink . ';border-radius:8px">'
			. '<a style="display:inline-block;padding:14px 24px;font-size:16px;font-weight:600;line-height:20px;color:#ffffff;text-decoration:none" href="' . $m[1] . '">' . $m[2] . '</a></td></tr></table>'
			. ( 0 === strpos( $m[1], 'http' ) ? '<p style="margin:0 0 24px;font-size:13px;line-height:19px;color:' . $soft . '">Or paste this link into your browser:<br><span style="color:' . $ink . ';word-break:break-all">' . $m[1] . '</span></p>' : '<div style="height:12px"></div>' ),
		$body
	);
	// The sign-off's "Questions?" line moves to the footer, as in the other emails.
	$body = (string) preg_replace( '#<br>\s*Questions\?.*?</p>#s', '</p>', $body );
	$body = (string) preg_replace( '#<p>(The AnyPress team)#', '<p style="margin:8px 0 16px;border-top:1px solid ' . $line . ';padding-top:16px;font-size:14px;line-height:21px;color:' . $soft . '">$1', $body );
	$body = strtr(
		$body,
		array(
			'<p>'      => '<p style="margin:0 0 16px;font-size:16px;line-height:25px;color:' . $ink . '">',
			'<a href=' => '<a style="color:' . $ink . '" href=',
			'<ul>'     => '<ul style="margin:0 0 16px;padding-left:22px;font-size:16px;line-height:25px;color:' . $ink . '">',
			'<ol>'     => '<ol style="margin:0 0 16px;padding-left:22px;font-size:16px;line-height:25px;color:' . $ink . '">',
		)
	);
	$small = 'color:' . $soft . ';text-decoration:underline';
	return '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>' . esc_html( $title ) . '</title></head>'
		. '<body style="margin:0;padding:0;background:' . $paper . '" data-anypress-email="1">'
		. ( '' !== $preheader ? '<div style="display:none;overflow:hidden;line-height:1px;opacity:0;max-height:0;max-width:0">' . esc_html( $preheader ) . '</div>' : '' )
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $paper . '"><tr><td align="center" style="padding:32px 12px;font-family:' . $font . '">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px">'
		. '<tr><td style="padding:0 4px 20px"><table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="padding-right:10px;vertical-align:middle"><img src="https://anypress.io/logo-512.png" width="36" height="36" alt="" style="display:block;border:0"></td><td style="vertical-align:middle"><span style="font-size:20px;font-weight:700;letter-spacing:-0.01em;color:' . $ink . '">AnyPress</span></td></tr></table></td></tr>'
		. '<tr><td style="background:#ffffff;border:1px solid ' . $line . ';border-radius:12px;overflow:hidden">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td height="4" style="height:4px;line-height:4px;font-size:0;background:#ff7a2a;background-image:linear-gradient(90deg,#ffc75a,#ff7a2a,#ee2f5e,#d21fa4)">&nbsp;</td></tr>'
		. '<tr><td style="padding:32px 32px 16px">'
		. '<h1 style="margin:0 0 16px;font-size:24px;line-height:30px;font-weight:700;letter-spacing:-0.01em;color:' . $ink . '">' . esc_html( $title ) . '</h1>'
		. $body
		. '</td></tr></table></td></tr>'
		. '<tr><td style="padding:20px 4px 0;font-size:13px;line-height:20px;color:' . $soft . '">'
		. 'Questions? Write to <a href="mailto:' . $support . '" style="' . $small . '">' . $support . '</a> or just reply to this email.<br>'
		. '{{crm.business_name}}, {{crm.business_address}}<br>'
		. 'You get these emails because you have an AnyPress account. <a href="##crm.manage_subscription_url##" style="' . $small . '">Choose which emails you get</a> or <a href="##crm.unsubscribe_url##" style="' . $small . '">unsubscribe</a>.'
		. '</td></tr></table></td></tr></table></body></html>';
}
