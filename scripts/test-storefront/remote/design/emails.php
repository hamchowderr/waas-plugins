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

	// Emails already in the automations keep the style they were made with, so give them the new one too.
	$ap_restyled = 0;
	foreach ( \FluentCrm\App\Models\FunnelCampaign::all() as $ap_campaign ) {
		$ap_cs                    = (array) $ap_campaign->settings;
		$ap_cs['template_config'] = array_merge( (array) ( $ap_cs['template_config'] ?? array() ), $ap_style );
		$ap_campaign->settings    = $ap_cs;
		$ap_campaign->save();
		++$ap_restyled;
	}
	echo "FluentCRM: email style, sender name and footer set; $ap_restyled automation emails restyled\n";
}
