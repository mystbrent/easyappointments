<?php

namespace Tests\Unit\EaSeed\Resources;

use EaSeed\EaClient;
use EaSeed\Ids;
use EaSeed\Manifest;
use EaSeed\Resources\SettingsResource;
use Tests\TestCase;

class SettingsResourceTest extends TestCase
{
    private array $calls = [];

    protected function setUp(): void
    {
        putenv('S_API_KEY=tok');
        putenv('S_ADMIN_PASSWORD=pw');
        $this->calls = [];
    }

    private function manifest(array $settings): Manifest
    {
        return Manifest::fromArray([
            'product' => 'p',
            'base_url' => 'http://ea.test',
            'admin' => [
                'first_name' => 'A', 'email' => 'a@example.com',
                'username' => 'a', 'password_env' => 'S_ADMIN_PASSWORD',
            ],
            'api_token_env' => 'S_API_KEY',
            'settings' => $settings,
        ]);
    }

    private function client(array $current): EaClient
    {
        return new EaClient('http://ea.test', 'tok', function ($method, $url, $body) use ($current) {
            $this->calls[] = [$method, $url, $body];

            if ($method === 'GET') {
                return ['status' => 200, 'body' => json_encode($current)];
            }

            return ['status' => 200, 'body' => ''];
        });
    }

    public function testWritesASettingWhoseValueDiffers()
    {
        $client = $this->client([['name' => 'require_phone_number', 'value' => '1']]);

        $changes = SettingsResource::converge(
            $client,
            $this->manifest(['require_phone_number' => false]),
            new Ids(),
            false
        );

        $this->assertSame('update', $changes[0]->action);
        $this->assertContains(
            ['PUT', 'http://ea.test/api/v1/settings/require_phone_number', '{"value":"0"}'],
            $this->calls
        );
    }

    public function testSkipsASettingAlreadyAtTheDesiredValue()
    {
        $client = $this->client([['name' => 'company_name', 'value' => 'Lostlocks']]);

        $changes = SettingsResource::converge(
            $client,
            $this->manifest(['company_name' => 'Lostlocks']),
            new Ids(),
            false
        );

        $this->assertSame('noop', $changes[0]->action);
        $this->assertCount(1, $this->calls, 'only the initial GET should have been issued');
    }

    public function testDryRunReportsWithoutWriting()
    {
        $client = $this->client([['name' => 'company_name', 'value' => 'Old']]);

        $changes = SettingsResource::converge(
            $client,
            $this->manifest(['company_name' => 'New']),
            new Ids(),
            true
        );

        $this->assertSame('update', $changes[0]->action);
        $this->assertCount(1, $this->calls, 'dry run must not issue a PUT');
    }

    public function testRequestsAPageLargeEnoughToCoverEveryEaSetting()
    {
        // EA paginates at 20 by default; an unpaginated GET would silently
        // truncate and make every manifest key look absent.
        $client = $this->client([]);

        SettingsResource::converge($client, $this->manifest(['company_name' => 'Lostlocks']), new Ids(), false);

        $this->assertSame('GET', $this->calls[0][0]);
        $this->assertStringContainsString('length=', $this->calls[0][1]);
    }

    public function testBooleansAreSentAsOneAndZero()
    {
        $client = $this->client([]);

        SettingsResource::converge(
            $client,
            $this->manifest(['require_phone_number' => true]),
            new Ids(),
            false
        );

        $this->assertContains(
            ['PUT', 'http://ea.test/api/v1/settings/require_phone_number', '{"value":"1"}'],
            $this->calls
        );
    }
}
