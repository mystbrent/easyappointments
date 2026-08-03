<?php

namespace Tests\Unit\EaSeed\Resources;

use EaSeed\EaClient;
use EaSeed\Ids;
use EaSeed\Manifest;
use EaSeed\Resources\WebhooksResource;
use Tests\TestCase;

class WebhooksResourceTest extends TestCase
{
    private array $calls = [];

    protected function setUp(): void
    {
        putenv('WH_API_KEY=tok');
        putenv('WH_ADMIN_PASSWORD=pw');
        putenv('WH_SECRET=whsec');
        $this->calls = [];
    }

    private function manifest(): Manifest
    {
        return Manifest::fromArray([
            'product' => 'p',
            'base_url' => 'http://ea.test',
            'admin' => [
                'first_name' => 'A', 'email' => 'a@example.com',
                'username' => 'a', 'password_env' => 'WH_ADMIN_PASSWORD',
            ],
            'api_token_env' => 'WH_API_KEY',
            'webhooks' => [[
                'name' => 'PWA reconcile',
                'url' => 'https://lostlocks.test/api/webhooks/easyappointments',
                'actions' => ['appointment_save', 'appointment_delete'],
                'secret_token_env' => 'WH_SECRET',
            ]],
        ]);
    }

    private function client(array $existing): EaClient
    {
        return new EaClient('http://ea.test', 'tok', function ($method, $url, $body) use ($existing) {
            $this->calls[] = [$method, $url, $body === null ? null : json_decode($body, true)];

            if ($method === 'GET') {
                return ['status' => 200, 'body' => json_encode($existing)];
            }

            return ['status' => 200, 'body' => json_encode(['id' => 3])];
        });
    }

    public function testSendsActionsAsAJsonEncodedString()
    {
        WebhooksResource::converge($this->client([]), $this->manifest(), new Ids(), false);

        $post = array_values(array_filter($this->calls, fn($c) => $c[0] === 'POST'))[0];

        $this->assertIsString($post[2]['actions']);
        $this->assertSame('["appointment_save","appointment_delete"]', $post[2]['actions']);
    }

    public function testCreatesAndRecordsTheId()
    {
        $ids = new Ids();

        $changes = WebhooksResource::converge($this->client([]), $this->manifest(), $ids, false);

        $this->assertSame(3, $ids->webhooks['PWA reconcile']);
        $this->assertSame('create', $changes[0]->action);
    }

    public function testSkipsWhenTheExistingWebhookAlreadyMatches()
    {
        $existing = [[
            'id' => 3,
            'name' => 'PWA reconcile',
            'url' => 'https://lostlocks.test/api/webhooks/easyappointments',
            'actions' => '["appointment_save","appointment_delete"]',
        ]];

        $changes = WebhooksResource::converge($this->client($existing), $this->manifest(), new Ids(), false);

        $this->assertSame('noop', $changes[0]->action);
        $this->assertEmpty(array_filter($this->calls, fn($c) => $c[0] !== 'GET'));
    }

    public function testUpdatesWhenTheUrlDiffers()
    {
        $existing = [[
            'id' => 3,
            'name' => 'PWA reconcile',
            'url' => 'https://old.test/hook',
            'actions' => '["appointment_save","appointment_delete"]',
        ]];

        $changes = WebhooksResource::converge($this->client($existing), $this->manifest(), new Ids(), false);

        $this->assertSame('update', $changes[0]->action);
        $this->assertContains('PUT', array_column($this->calls, 0));
    }
}
