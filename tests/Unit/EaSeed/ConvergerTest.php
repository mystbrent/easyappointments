<?php

namespace Tests\Unit\EaSeed;

use EaSeed\Converger;
use EaSeed\EaClient;
use EaSeed\Manifest;
use Tests\TestCase;

class ConvergerTest extends TestCase
{
    private array $order = [];

    protected function setUp(): void
    {
        putenv('CV_API_KEY=tok');
        putenv('CV_ADMIN_PASSWORD=pw');
        putenv('CV_PROVIDER_PASSWORD=ppw');
        putenv('CV_SECRET=whsec');
        $this->order = [];
    }

    private function manifest(): Manifest
    {
        return Manifest::fromArray([
            'product' => 'lostlocks',
            'base_url' => 'http://ea.test',
            'admin' => [
                'first_name' => 'A', 'email' => 'a@example.com',
                'username' => 'a', 'password_env' => 'CV_ADMIN_PASSWORD',
            ],
            'api_token_env' => 'CV_API_KEY',
            'settings' => ['require_phone_number' => false],
            'service_categories' => [['name' => 'Rooms']],
            'services' => [
                ['key' => 'HEIST', 'name' => 'Heist', 'duration' => 60, 'category' => 'Rooms'],
            ],
            'providers' => [[
                'key' => 'PRIMARY', 'first_name' => 'GM', 'email' => 'gm@lostlocks.test',
                'services' => ['HEIST'],
                'settings' => [
                    'username' => 'gm', 'password_env' => 'CV_PROVIDER_PASSWORD',
                    'working_plan' => ['monday' => [['start' => '19:30', 'end' => '23:59']]],
                ],
            ]],
            'webhooks' => [[
                'name' => 'PWA reconcile', 'url' => 'https://lostlocks.test/hook',
                'actions' => ['appointment_save'], 'secret_token_env' => 'CV_SECRET',
            ]],
        ]);
    }

    private function client(): EaClient
    {
        $nextId = 10;

        return new EaClient('http://ea.test', 'tok', function ($method, $url) use (&$nextId) {
            if ($method === 'GET') {
                $path = parse_url($url, PHP_URL_PATH);

                foreach (['settings', 'service_categories', 'services', 'providers', 'webhooks'] as $r) {
                    if (str_contains($path, $r)) {
                        $this->order[] = 'GET ' . $r;
                        break;
                    }
                }

                return ['status' => 200, 'body' => '[]'];
            }

            return ['status' => 200, 'body' => json_encode(['id' => $nextId++])];
        });
    }

    public function testConvergesInDependencyOrder()
    {
        (new Converger($this->client()))->run($this->manifest(), false);

        $this->assertSame(
            ['GET settings', 'GET service_categories', 'GET services', 'GET providers', 'GET webhooks'],
            $this->order
        );
    }

    public function testReturnsChangesAndPopulatedIds()
    {
        $result = (new Converger($this->client()))->run($this->manifest(), false);

        $this->assertNotEmpty($result['changes']);
        $this->assertArrayHasKey('HEIST', $result['ids']->services);
        $this->assertArrayHasKey('PRIMARY', $result['ids']->providers);
    }

    public function testDryRunStillReportsPlannedChanges()
    {
        $result = (new Converger($this->client()))->run($this->manifest(), true);

        $creates = array_filter($result['changes'], fn($c) => $c->action === 'create');
        $this->assertNotEmpty($creates);
    }
}
