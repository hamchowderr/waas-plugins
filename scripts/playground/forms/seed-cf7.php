<?php
require '/wordpress/wp-load.php';
wp_set_current_user(1);
// A Contact Form 7 form with many field types, a custom Mail and Mail (2) and a custom "sent" message, embedded
// on a page (by hash, by the legacy numeric id and as a block) and in a footer template part.
$form = <<<F
<label> Your name (required)
    [text* your-name placeholder "Jane Doe"] </label>

<label> Your email
    [email* your-email] </label>

<label> Phone
    [tel your-phone] </label>

<label> Website
    [url your-site] </label>

<label> Guests
    [number guests min:1 max:10 "2"] </label>

<label> Budget slider
    [range budget min:0 max:100] </label>

<label> Event date
    [date event-date] </label>

<label> Topic
    [select topic "Sales" "Support" "Billing"] </label>

<label> Multi topic
    [select multi-topic multiple "A" "B" "C"] </label>

<label> Interests
    [checkbox interests use_label_element "Anime" "Gaming" "Music"] </label>

<label> Contact method
    [radio contact-method default:1 "Email" "Phone"] </label>

<label> Attachment
    [file attachment limit:2mb filetypes:pdf|jpg|png] </label>

[hidden source-id "cf7-landing"]

<label> Message
    [textarea your-message] </label>

[acceptance gdpr] I agree to the privacy policy [/acceptance]

[quiz capital-quiz "Capital of France?|Paris"]

[submit "Send it"]
F;

$cf = WPCF7_ContactForm::get_template(['title' => 'CF7 Kitchen Sink']);
$cf->set_properties([
  'form' => $form,
  'mail' => [
    'active' => true,
    'subject' => 'CF7 lead: [your-name] about [topic]',
    'sender' => 'Site <wordpress@example.com>',
    'recipient' => 'sales@example.com',
    'body' => "From: [your-name] <[your-email]>\nTopic: [topic]\n\n[your-message]",
    'additional_headers' => 'Reply-To: [your-email]',
    'attachments' => '[attachment]',
    'use_html' => false, 'exclude_blank' => false,
  ],
  'mail_2' => [
    'active' => true,
    'subject' => 'Thanks [your-name]',
    'sender' => 'Site <wordpress@example.com>',
    'recipient' => '[your-email]',
    'body' => "We got your message.",
    'additional_headers' => '', 'attachments' => '', 'use_html' => false, 'exclude_blank' => false,
  ],
  'messages' => array_merge(wpcf7_messages() ? array_map(fn($m)=>$m['default'], wpcf7_messages()) : [], [
    'mail_sent_ok' => 'CUSTOM-CF7-CONFIRM: Thanks, we will reply within 24h.',
  ]),
]);
$id = $cf->save();
$cf = wpcf7_contact_form($id); // reload: hash() is only set after save
$post = get_post($id);
$block = '<!-- wp:contact-form-7/contact-form-selector {"id":' . $id . ',"hash":"' . $cf->hash() . '","title":"CF7 Kitchen Sink"} -->'
  . '<div class="wp-block-contact-form-7-contact-form-selector">[contact-form-7 id="' . $cf->hash() . '" title="CF7 Kitchen Sink"]</div>'
  . '<!-- /wp:contact-form-7/contact-form-selector -->';
$page = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'CF7 Contact',
  'post_content'=> '[contact-form-7 id="'.$cf->hash().'" title="CF7 Kitchen Sink"]' . "\n\n<!-- legacy id form -->\n" . '[contact-form-7 id="'.$id.'"]' . "\n\n" . $block]);
$part = wp_insert_post(['post_type'=>'wp_template_part','post_status'=>'publish','post_title'=>'Forms footer','post_name'=>'forms-footer',
  'post_content'=>'<!-- wp:paragraph --><p>Write to us</p><!-- /wp:paragraph -->' . "\n" . '<!-- wp:shortcode -->[contact-form-7 id="'.$cf->hash().'"]<!-- /wp:shortcode -->',
  'tax_input'=>['wp_theme'=>[get_stylesheet()],'wp_template_part_area'=>['footer']]]);
echo json_encode(['cf7_id'=>$id,'slug'=>$post->post_name,'hash'=>$cf->hash(),'page'=>$page,'part'=>$part]), "\n";
