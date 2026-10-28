<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * ---------------------------------------------------------------------------- */

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * PHPMailer that delivers through the ZeptoMail HTTP API instead of SMTP.
 *
 * Hosts that block outbound SMTP ports (DigitalOcean App Platform) can still reach an HTTPS API.
 * PHPMailer keeps building and validating the message; only the final hand-off changes.
 *
 * @link https://www.zoho.com/zeptomail/help/api/email-sending.html
 *
 * @package Libraries
 */
class Zeptomail_mailer extends PHPMailer
{
    public const DEFAULT_ENDPOINT = 'https://api.zeptomail.com/v1.1/email';

    private string $token;

    private string $endpoint;

    private bool $html = false;

    /**
     * @var callable(string $url, array $headers, string $body): array{0: int, 1: string}
     */
    private $transport;

    /**
     * @param string $token ZeptoMail send-mail token, with or without the "Zoho-enczapikey " prefix.
     * @param string $endpoint API URL.
     * @param callable|null $transport HTTP POST; defaults to cURL. Tests inject a fake.
     */
    public function __construct(string $token, string $endpoint = self::DEFAULT_ENDPOINT, ?callable $transport = null)
    {
        parent::__construct(true);

        $this->token = trim($token);
        $this->endpoint = $endpoint;
        $this->transport = $transport ?? [$this, 'curl_post'];
        $this->Mailer = 'zeptomail';
    }

    /**
     * Note whether the body is HTML before PHPMailer rewrites ContentType for multipart messages.
     *
     * @throws Exception
     */
    public function preSend(): bool
    {
        $this->html = $this->ContentType === static::CONTENT_TYPE_TEXT_HTML;

        return parent::preSend();
    }

    /**
     * Deliver the message prepared by preSend().
     *
     * @throws Exception
     */
    public function postSend(): bool
    {
        if ($this->token === '') {
            throw new Exception('EA_ZEPTOMAIL_TOKEN is not set');
        }

        $authorization = str_starts_with($this->token, 'Zoho-enczapikey ')
            ? $this->token
            : 'Zoho-enczapikey ' . $this->token;

        $headers = ['Authorization: ' . $authorization, 'Content-Type: application/json', 'Accept: application/json'];

        [$status, $response] = ($this->transport)(
            $this->endpoint,
            $headers,
            json_encode($this->zeptomail_payload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );

        if ($status < 200 || $status >= 300) {
            // The response never echoes the token, but scrub it anyway: this message reaches logs.
            $detail = str_replace($this->token, '***', substr((string) $response, 0, 300));

            throw new Exception('ZeptoMail HTTP ' . $status . ': ' . $detail);
        }

        return true;
    }

    /**
     * The API request body for the current message.
     */
    public function zeptomail_payload(): array
    {
        $payload = [
            'from' => ['address' => $this->From, 'name' => $this->FromName],
            'to' => array_map(
                fn(array $to) => ['email_address' => ['address' => $to[0], 'name' => $to[1]]],
                $this->getToAddresses(),
            ),
        ];

        if ($this->getCcAddresses()) {
            $payload['cc'] = array_map(
                fn(array $cc) => ['email_address' => ['address' => $cc[0], 'name' => $cc[1]]],
                $this->getCcAddresses(),
            );
        }

        if ($this->getBccAddresses()) {
            $payload['bcc'] = array_map(
                fn(array $bcc) => ['email_address' => ['address' => $bcc[0], 'name' => $bcc[1]]],
                $this->getBccAddresses(),
            );
        }

        if ($this->getReplyToAddresses()) {
            $payload['reply_to'] = array_values(
                array_map(fn(array $reply) => ['address' => $reply[0], 'name' => $reply[1]], $this->getReplyToAddresses()),
            );
        }

        $payload['subject'] = $this->Subject;

        if ($this->html) {
            $payload['htmlbody'] = $this->Body;

            if ($this->AltBody !== '') {
                $payload['textbody'] = $this->AltBody;
            }
        } else {
            $payload['textbody'] = $this->Body;
        }

        $attachments = [];

        foreach ($this->getAttachments() as $attachment) {
            // [0] content or path, [2] name, [4] MIME type, [5] true for string attachments.
            $content = $attachment[5] ? $attachment[0] : file_get_contents($attachment[0]);

            $attachments[] = ['name' => $attachment[2], 'mime_type' => $attachment[4], 'content' => base64_encode($content)];
        }

        if ($attachments) {
            $payload['attachments'] = $attachments;
        }

        return $payload;
    }

    /**
     * @return array{0: int, 1: string} HTTP status and response body.
     *
     * @throws Exception
     */
    private function curl_post(string $url, array $headers, string $body): array
    {
        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($curl);

        if ($response === false) {
            $error = curl_error($curl);
            curl_close($curl);

            throw new Exception('ZeptoMail request failed: ' . $error);
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return [$status, (string) $response];
    }
}
