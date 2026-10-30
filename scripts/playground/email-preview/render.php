<?php
// Preview only: renders the shop's FluentCart and FluentCRM emails after design/emails.php, as JSON for
// email-preview.mjs. Needs FluentCart and FluentCRM active and the design folder at $design.
require_once '/wordpress/wp-load.php';
$design = '/wordpress/wp-content/anypress-design';
wp_set_current_user( 1 );
$up = wp_get_upload_dir();
wp_mkdir_p( $up['basedir'] . '/anypress-shop' );
copy( "$design/images/logo-email.png", $up['basedir'] . '/anypress-shop/logo-email.png' );
update_option( 'fluentcrm-global-settings', array( 'business_settings' => array( 'business_name' => 'Otaku Solutions', 'business_address' => '123 Example St, Montgomery, AL', 'admin_email' => 'support@anypress.io' ) ) );
( new \FluentCart\Api\StoreSettings() )->save( array( 'store_name' => 'AnyPress' ) );
ob_start();
require "$design/emails.php";
$log = ob_get_clean();

$out = array( 'log' => $log, 'cart' => array(), 'crm' => array() );

// FluentCart: the same render as its own "preview" button in Settings > Emails.
$picks = array( 'order_paid_customer', 'subscription_renewal_customer', 'order_refunded_customer', 'subscription_renewal_failed_customer' );
foreach ( \FluentCart\App\Services\Email\EmailNotifications::getNotifications() as $name => $n ) {
	if ( ! in_array( $name, $picks, true ) && ! in_array( $n['name'] ?? '', $picks, true ) ) {
		continue;
	}
	$template = $n['template_path'];
	$data     = ( new \FluentCart\App\Services\Email\EmailPreviewService() )->getPreviewData( $template );
	$body     = \FluentCart\App\Services\TemplateService::getTemplateByPathName( $template, $data );
	// FluentCart's own preview data has a stand-in order that its order header can't take (a fatal in 2.2), so the
	// header gets a real, unsaved Order with the same number and date.
	$real = new \FluentCart\App\Models\Order();
	$real->forceFill( array( 'id' => 0, 'invoice_no' => $data['order']->invoice_no ?? 'FC-1001', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	$header = (string) \FluentCart\App\App::make( 'view' )->make( 'emails.parts.order_header', array_merge( $data, array( 'order' => $real ) ) );
	$view     = (string) \FluentCart\App\App::make( 'view' )->make(
		'emails.general_template',
		array( 'emailBody' => $body, 'preheader' => '', 'header' => $header, 'emailFooter' => ( new \FluentCart\App\Services\Email\EmailNotificationMailer() )->getEmailFooter() )
	);
	$out['cart'][] = array(
		'name'    => $name,
		'title'   => $n['title'] ?? $name,
		'subject' => \FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder::make( $n['settings']['subject'] ?? '', $data ),
		'html'    => \FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder::make( $view, $data ),
	);
}

// FluentCRM: two of the shop's automation emails in the layout emails.php gives them, rendered the way FluentCRM sends.
$contact = \FluentCrm\App\Models\Subscriber::where( 'email', 'pat@example.com' )->first()
	?: \FluentCrm\App\Models\Subscriber::create( array( 'email' => 'pat@example.com', 'first_name' => 'Pat', 'status' => 'subscribed' ) );
$all     = json_decode( file_get_contents( '/wordpress/wp-content/anypress-remote/crm-emails.json' ), true );
foreach ( array( array( 'Email: Website owner welcome', 0 ), array( 'Email: Seller welcome', 0 ) ) as list( $title, $i ) ) {
	foreach ( $all as $a ) {
		if ( $a['title'] !== $title ) {
			continue;
		}
		$email    = $a['emails'][ $i ];
		$mock     = \FluentCrm\App\Models\FunnelCampaign::getMock();
		$campaign = \FluentCrm\App\Models\Campaign::create(
			array(
				'title'            => $email['subject'],
				'email_subject'    => $email['subject'],
				'email_pre_header' => $email['preheader'],
				'email_body'       => anypress_crm_email_html( $email['body'], $email['subject'], $email['preheader'], 'support@anypress.io' ),
				'design_template'  => 'raw_html',
				'settings'         => (array) $mock['settings'],
				'status'           => 'draft',
			)
		);
		$row = \FluentCrm\App\Models\CampaignEmail::create(
			array( 'campaign_id' => $campaign->id, 'subscriber_id' => $contact->id, 'email_address' => $contact->email, 'email_subject' => $email['subject'], 'status' => 'draft', 'email_hash' => md5( $title ) )
		);
		$out['crm'][] = array( 'title' => $title, 'subject' => $email['subject'], 'html' => $row->getEmailBody() );
	}
}
echo wp_json_encode( $out );
