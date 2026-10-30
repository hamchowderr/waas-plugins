<?php
require '/wordpress/wp-load.php';
wp_set_current_user(1);
// Seed a WPForms form: Lite fields + Pro-type fields (as left behind by a downgraded Pro site),
// field conditional logic, 2 notifications, 2 confirmations, page breaks. Embed on a page.
$id = wpforms()->form->add('WPForms Kitchen Sink');
$f = [];
$f[1]  = ['id'=>1,'type'=>'name','label'=>'Name','format'=>'first-last','required'=>'1'];
$f[2]  = ['id'=>2,'type'=>'email','label'=>'Email','required'=>'1'];
$f[3]  = ['id'=>3,'type'=>'phone','label'=>'Phone','format'=>'us'];
$f[4]  = ['id'=>4,'type'=>'text','label'=>'Company','placeholder'=>'Acme'];
$f[5]  = ['id'=>5,'type'=>'textarea','label'=>'Message'];
$f[6]  = ['id'=>6,'type'=>'select','label'=>'Topic','choices'=>[1=>['label'=>'Sales','value'=>''],2=>['label'=>'Support','value'=>''],3=>['label'=>'Billing','value'=>'']]];
$f[7]  = ['id'=>7,'type'=>'checkbox','label'=>'Interests','choices'=>[1=>['label'=>'Anime'],2=>['label'=>'Gaming'],3=>['label'=>'Music']]];
$f[8]  = ['id'=>8,'type'=>'radio','label'=>'Contact method','choices'=>[1=>['label'=>'Email','default'=>'1'],2=>['label'=>'Phone']]];
$f[9]  = ['id'=>9,'type'=>'pagebreak','label'=>'Next','position'=>'','title'=>'Step 2','next'=>'Next'];
$f[10] = ['id'=>10,'type'=>'date-time','label'=>'Event date','format'=>'date','date_format'=>'m/d/Y'];
$f[11] = ['id'=>11,'type'=>'number','label'=>'Guests'];
$f[12] = ['id'=>12,'type'=>'number-slider','label'=>'Budget','min'=>0,'max'=>100,'step'=>5,'default_value'=>20];
$f[13] = ['id'=>13,'type'=>'url','label'=>'Website'];
$f[14] = ['id'=>14,'type'=>'file-upload','label'=>'Attachment','extensions'=>'pdf, jpg, png','max_size'=>'2','max_file_number'=>'1'];
$f[15] = ['id'=>15,'type'=>'hidden','label'=>'Source','default_value'=>'wpforms-landing'];
$f[16] = ['id'=>16,'type'=>'text','label'=>'Phone time (only if Phone)',
          'conditional_logic'=>'1','conditional_type'=>'show',
          'conditionals'=>[[['field'=>'8','operator'=>'==','value'=>'2']]]];
$f[17] = ['id'=>17,'type'=>'gdpr-checkbox','label'=>'GDPR Agreement','required'=>'1','choices'=>[1=>['label'=>'I consent to having this website store my submitted information.']]];
$f[18] = ['id'=>18,'type'=>'divider','label'=>'Section'];
$f[19] = ['id'=>19,'type'=>'html','code'=>'<p>Custom HTML block</p>','label'=>'HTML'];
$f[20] = ['id'=>20,'type'=>'pagebreak','position'=>'bottom','prev'=>'Back'];

$data = [
  'id'=>$id,'field_id'=>21,'fields'=>$f,
  'settings'=>[
    'form_title'=>'WPForms Kitchen Sink','submit_text'=>'Send WPForms','submit_class'=>'my-btn',
    'notification_enable'=>'1',
    'notifications'=>[
      1=>['notification_name'=>'Admin notify','email'=>'{admin_email}','subject'=>'WPF lead from {field_id="1"}',
          'sender_name'=>'Site','sender_address'=>'{admin_email}','replyto'=>'{field_id="2"}','message'=>'{all_fields}'],
      2=>['notification_name'=>'Sales routed','email'=>'sales@example.com','subject'=>'Sales topic',
          'message'=>'Topic {field_id="6"} msg {field_id="5"}',
          'conditional_logic'=>'1','conditional_type'=>'go',
          'conditionals'=>[[['field'=>'6','operator'=>'==','value'=>'1']]]],
    ],
    'confirmations'=>[
      1=>['name'=>'Default','type'=>'message','message'=>'<p>CUSTOM-WPF-CONFIRM: Thanks {field_id="1"}!</p>','message_scroll'=>'1'],
      2=>['name'=>'Support redirect','type'=>'redirect','redirect'=>'https://example.com/support-thanks',
          'conditional_logic'=>'1','conditional_type'=>'go','conditionals'=>[[['field'=>'6','operator'=>'==','value'=>'2']]]],
    ],
  ],
];
wpforms()->form->update($id, $data);
$page = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'WPForms Contact',
  'post_content'=>'[wpforms id="'.$id.'" title="false"]' . "\n\n" . '<!-- wp:wpforms/form-selector {"formId":"'.$id.'"} /-->']);
// A synced pattern with the form's block.
$pattern = wp_insert_post(['post_type'=>'wp_block','post_status'=>'publish','post_title'=>'Contact us pattern',
  'post_content'=>'<!-- wp:heading --><h2 class="wp-block-heading">Contact us</h2><!-- /wp:heading -->' . "\n" . '<!-- wp:wpforms/form-selector {"formId":"'.$id.'"} /-->']);
echo json_encode(['wpforms_id'=>$id,'page'=>$page,'pattern'=>$pattern]), "\n";
