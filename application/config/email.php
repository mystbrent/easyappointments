<?php defined('BASEPATH') or exit('No direct script access allowed');

// Add custom values by settings them to the $config array.
// Example: $config['smtp_host'] = 'smtp.gmail.com';
// @link https://codeigniter.com/user_guide/libraries/email.html

$config['useragent'] = 'Easy!Appointments';
$config['protocol'] = getenv('EA_MAIL_PROTOCOL') ?: 'mail';
$config['mailtype'] = 'html';

$smtp_host = getenv('EA_SMTP_HOST');

if ($smtp_host) {
    $config['protocol'] = getenv('EA_MAIL_PROTOCOL') ?: 'smtp';
    $config['smtp_host'] = $smtp_host;
    $config['smtp_user'] = getenv('EA_SMTP_USER') ?: '';
    $config['smtp_pass'] = getenv('EA_SMTP_PASS') ?: '';
    $config['smtp_crypto'] = getenv('EA_SMTP_CRYPTO') ?: 'tls';
    $config['smtp_port'] = (int) (getenv('EA_SMTP_PORT') ?: 587);
    $config['smtp_auth'] = true;
}

// ZeptoMail over HTTPS, for hosts that block outbound SMTP (DigitalOcean App Platform).
if (getenv('EA_MAIL_PROTOCOL') === 'zeptomail') {
    $config['protocol'] = 'zeptomail';
    $config['zeptomail_token'] = getenv('EA_ZEPTOMAIL_TOKEN') ?: '';
    $config['zeptomail_endpoint'] = getenv('EA_ZEPTOMAIL_ENDPOINT') ?: 'https://api.zeptomail.com/v1.1/email';
}

$from_address = getenv('EA_MAIL_FROM_ADDRESS');

if ($from_address) {
    $config['from_address'] = $from_address;
    $config['from_name'] = getenv('EA_MAIL_FROM_NAME') ?: '';
}
$config['crlf'] = "\r\n";
$config['newline'] = "\r\n";
