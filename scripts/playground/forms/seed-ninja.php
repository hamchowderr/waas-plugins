<?php
require '/wordpress/wp-load.php';
wp_set_current_user(1);
// Seed a Ninja Forms form (free field types), email + success message actions, 3 submissions, embed on page.
$fld = function($type,$label,$key,$extra=[]) { static $o=0; $o++;
  return array_merge(['type'=>$type,'label'=>$label,'key'=>$key,'order'=>$o,'label_pos'=>'above','required'=>0,'default'=>'','placeholder'=>'','element_class'=>'','container_class'=>''],$extra); };
$opts = fn(...$l) => array_map(fn($x,$i)=>['label'=>$x,'value'=>strtolower($x),'calc'=>'','selected'=>0,'order'=>$i], $l, array_keys($l));
$fields = [
  $fld('firstname','First Name','first_name',['required'=>1]),
  $fld('lastname','Last Name','last_name'),
  $fld('email','Email','email',['required'=>1]),
  $fld('phone','Phone','phone'),
  $fld('textbox','Website','website'),
  $fld('textarea','Message','message'),
  $fld('listselect','Topic','topic',['options'=>$opts('Sales','Support','Billing')]),
  $fld('listmultiselect','Multi topic','multi_topic',['options'=>$opts('A','B','C')]),
  $fld('listcheckbox','Interests','interests',['options'=>$opts('Anime','Gaming','Music')]),
  $fld('listradio','Contact method','contact_method',['options'=>$opts('Email','Phone')]),
  $fld('date','Event date','event_date',['date_format'=>'MM/DD/YYYY']),
  $fld('number','Guests','guests',['num_min'=>1,'num_max'=>10,'num_step'=>1]),
  $fld('hidden','Source','source',['default'=>'ninja-landing']),
  $fld('address','Address','address'),
  $fld('city','City','city'),
  $fld('liststate','State','state',['options'=>$opts('AL','GA')]),
  $fld('zip','Zip','zip'),
  $fld('listcountry','Country','country',['default'=>'US']),
  $fld('starrating','Rating','rating',['number_of_stars'=>5]),
  $fld('hr','Divider','divider'),
  $fld('html','HTML','html_1',['default'=>'<p>Ninja HTML block</p>']),
  $fld('confirm','Confirm Email','confirm_email',['confirm_field'=>'email']),
  $fld('checkbox','I agree to the privacy policy','gdpr',['required'=>1,'checked_value'=>'Checked','unchecked_value'=>'Unchecked']),
  $fld('spam','Spam question: 2+2?','spam_q',['spam_answer'=>'4']),
  $fld('submit','Send Ninja','submit',['processing_label'=>'Processing']),
];
$actions = [
  ['type'=>'save','label'=>'Store Submission','active'=>1],
  ['type'=>'email','label'=>'Admin Email','active'=>1,'to'=>'{system:admin_email}','email_subject'=>'Ninja lead {field:first_name}',
   'email_message'=>'Topic {field:topic}<br>{all_fields_table}','from_name'=>'Site','from_address'=>'','reply_to'=>'{field:email}','email_format'=>'html','cc'=>'','bcc'=>'audit@example.com'],
  ['type'=>'email','label'=>'User Confirmation','active'=>1,'to'=>'{field:email}','email_subject'=>'Thanks {field:first_name}','email_message'=>'We got it.','from_name'=>'','from_address'=>'','reply_to'=>'','email_format'=>'html','cc'=>'','bcc'=>''],
  ['type'=>'successmessage','label'=>'Success Message','active'=>1,'success_msg'=>'<p>CUSTOM-NINJA-CONFIRM: Thanks {field:first_name}!</p>'],
];
$import = ['settings'=>['title'=>'Ninja Kitchen Sink','hide_complete'=>1,'clear_complete'=>1,'logged_in'=>0,'not_logged_in_msg'=>'','sub_limit_number'=>'','sub_limit_msg'=>''],
  'fields'=>$fields,'actions'=>$actions];
$form = Ninja_Forms()->form()->import_form($import, true, false, false);
$fid = is_object($form) ? $form->get_id() : (is_array($form)? ($form['id']??0) : $form);
if (!$fid) { global $wpdb; $fid = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}nf3_forms WHERE title='Ninja Kitchen Sink' ORDER BY id DESC LIMIT 1"); }
// submissions
$byKey=[]; foreach (Ninja_Forms()->form($fid)->get_fields() as $f) $byKey[$f->get_setting('key')]=$f->get_id();
for ($i=1;$i<=3;$i++) {
  $sub = Ninja_Forms()->form($fid)->sub()->get();
  $vals = ['first_name'=>"Ninja$i",'last_name'=>'Tester','email'=>"ninja$i@example.com",'phone'=>"555-020$i",'website'=>"https://n$i.example.com",
    'message'=>"Ninja message $i",'topic'=>'support','multi_topic'=>['a','c'],'interests'=>['anime','music'],'contact_method'=>'phone',
    'event_date'=>"12/0$i/2026",'guests'=>$i,'source'=>'ninja-landing','address'=>"$i Main St",'city'=>'Atlanta','state'=>'GA','zip'=>'30301',
    'country'=>'US','rating'=>4,'gdpr'=>'Checked','confirm_email'=>"ninja$i@example.com"];
  foreach ($vals as $k=>$v) if (isset($byKey[$k])) $sub->update_field_value($byKey[$k], $v);
  $sub->save();
}
$page = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Ninja Contact',
  'post_content'=>'[ninja_form id='.$fid.']'."\n\n".'<!-- wp:ninja-forms/form {"formID":'.$fid.'} /-->']);
// A draft post, and a block widget with the shortcode.
$draft = wp_insert_post(['post_type'=>'post','post_status'=>'draft','post_title'=>'Draft with a Ninja form','post_content'=>'<!-- wp:ninja-forms/form {"formID":'.$fid.'} /-->']);
update_option('widget_block', [2 => ['content' => '<!-- wp:shortcode -->[ninja_form id='.$fid.']<!-- /wp:shortcode -->'], '_multiwidget' => 1]);
echo json_encode(['ninja_id'=>(int)$fid,'page'=>$page,'draft'=>$draft,'subs'=>count(Ninja_Forms()->form($fid)->get_subs())]),"\n";
