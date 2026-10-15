<?php
/*
 * Plugin Name: WaaS store mail
 * Description: Sends the shop's email through the platform's mail relay (WAAS_MAIL_B64). A copy of the mu-plugin stores
 * get (waas-provisioning-api src/provisioner/coolify/agent.php); the setup script's configure step installs it.
 */
$waas_mail = json_decode((string) base64_decode((string) getenv('WAAS_MAIL_B64')), true);
if (!is_array($waas_mail) || empty($waas_mail['host']) || empty($waas_mail['from'])) return;
// The relay sends only from its verified domain: mail goes out from the platform's address under
// the store's name, and replies go to the address the store meant to send from.
add_action('phpmailer_init', function ($mailer) use ($waas_mail) {
    $mailer->isSMTP();
    $mailer->Host = $waas_mail['host'];
    $mailer->Port = (int) ($waas_mail['port'] ?? 465);
    $mailer->SMTPSecure = $mailer->Port === 465 ? 'ssl' : 'tls';
    $mailer->SMTPAuth = (string) ($waas_mail['user'] ?? '') !== '';
    $mailer->Username = (string) ($waas_mail['user'] ?? '');
    $mailer->Password = (string) ($waas_mail['pass'] ?? '');
    $name = (string) $mailer->FromName;
    if ($name === '' || $name === 'WordPress') $name = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
    $from = strtolower((string) $mailer->From);
    $host = preg_replace('/^www\./', '', strtolower((string) parse_url((string) get_option('home'), PHP_URL_HOST)));
    // WordPress's own default sender (wordpress@<site>) has no mailbox behind it.
    if ($from !== '' && $from !== strtolower($waas_mail['from']) && $from !== "wordpress@$host" && !$mailer->getReplyToAddresses()) {
        $mailer->addReplyTo($mailer->From, $name);
    }
    $mailer->setFrom($waas_mail['from'], $name, false);
    $mailer->Sender = $waas_mail['from'];
}, PHP_INT_MAX);
add_action('wp_mail_failed', function ($error) {
    error_log('WaaS store mail: ' . $error->get_error_message());
});
