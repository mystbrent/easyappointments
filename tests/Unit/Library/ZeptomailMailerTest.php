<?php

namespace Tests\Unit\Library;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;
use Zeptomail_mailer;

require_once APPPATH . 'libraries/Zeptomail_mailer.php';

/**
 * Plain PHPUnit case: the mailer must not need a booted framework or the network.
 */
class ZeptomailMailerTest extends TestCase {
    private const ENDPOINT = 'https://api.zeptomail.test/v1.1/email';

    /** @var array<int, array{url: string, headers: array, body: string}> */
    private array $requests = [];

    private function mailer(string $token = 'tok-123', int $status = 200, string $response = '{"request_id":"r1"}'): Zeptomail_mailer
    {
        $transport = function (string $url, array $headers, string $body) use ($status, $response): array {
            $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body];

            return [$status, $response];
        };

        $mailer = new Zeptomail_mailer($token, self::ENDPOINT, $transport);
        $mailer->CharSet = 'UTF-8';
        $mailer->setFrom('bookings@example.test', 'Leads Staging');
        $mailer->addReplyTo('owner@example.test');
        $mailer->addAddress('prospect@example.test', 'Pat Prospect');
        $mailer->Subject = 'Appointment booked';
        $mailer->Body = 'Booked';

        return $mailer;
    }

    private function sentPayload(): array
    {
        $this->assertCount(1, $this->requests);

        return json_decode($this->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_html_message_maps_to_the_zeptomail_payload(): void
    {
        $mailer = $this->mailer();
        $mailer->isHTML();
        $mailer->Body = '<p>Booked</p>';
        $mailer->AltBody = 'Booked';
        $mailer->addStringAttachment("BEGIN:VCALENDAR\r\nEND:VCALENDAR", 'invitation.ics', PHPMailer::ENCODING_BASE64, 'text/calendar');

        $this->assertTrue($mailer->send());

        $this->assertSame(self::ENDPOINT, $this->requests[0]['url']);
        $this->assertSame([
            'from' => ['address' => 'bookings@example.test', 'name' => 'Leads Staging'],
            'to' => [['email_address' => ['address' => 'prospect@example.test', 'name' => 'Pat Prospect']]],
            'reply_to' => [['address' => 'owner@example.test', 'name' => '']],
            'subject' => 'Appointment booked',
            'htmlbody' => '<p>Booked</p>',
            'textbody' => 'Booked',
            'attachments' => [[
                'name' => 'invitation.ics',
                'mime_type' => 'text/calendar',
                'content' => base64_encode("BEGIN:VCALENDAR\r\nEND:VCALENDAR"),
            ]],
        ], $this->sentPayload());
    }

    public function test_plain_text_message_sends_textbody_only(): void
    {
        $mailer = $this->mailer();
        $mailer->Body = 'Booked';

        $mailer->send();

        $payload = $this->sentPayload();
        $this->assertSame('Booked', $payload['textbody']);
        $this->assertArrayNotHasKey('htmlbody', $payload);
        $this->assertArrayNotHasKey('attachments', $payload);
    }

    public function test_authorization_header_adds_the_zoho_prefix_once(): void
    {
        $this->mailer('tok-123')->send();
        $this->mailer('Zoho-enczapikey tok-456')->send();

        $this->assertContains('Authorization: Zoho-enczapikey tok-123', $this->requests[0]['headers']);
        $this->assertContains('Authorization: Zoho-enczapikey tok-456', $this->requests[1]['headers']);
        $this->assertContains('Content-Type: application/json', $this->requests[0]['headers']);
    }

    public function test_api_error_throws_without_leaking_the_token(): void
    {
        $mailer = $this->mailer('tok-secret-789', 401, '{"error":{"code":"TM_4001","message":"Invalid API Token"}}');
        $mailer->Body = 'Booked';

        try {
            $mailer->send();
            $this->fail('send() should throw on a non-2xx response');
        } catch (Exception $e) {
            $this->assertStringContainsString('ZeptoMail HTTP 401', $e->getMessage());
            $this->assertStringContainsString('TM_4001', $e->getMessage());
            $this->assertStringNotContainsString('tok-secret-789', $e->getMessage());
        }
    }

    public function test_missing_token_throws_before_any_request(): void
    {
        $mailer = $this->mailer('');
        $mailer->Body = 'Booked';

        try {
            $mailer->send();
            $this->fail('send() should throw without a token');
        } catch (Exception $e) {
            $this->assertStringContainsString('EA_ZEPTOMAIL_TOKEN is not set', $e->getMessage());
        }

        $this->assertSame([], $this->requests);
    }
}
