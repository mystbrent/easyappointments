<?php

namespace Tests\Unit\EaSeed\Resources;

use EaSeed\EaClient;
use EaSeed\Ids;
use EaSeed\Manifest;
use EaSeed\Resources\ServicesResource;
use Tests\TestCase;

class ServicesResourceTest extends TestCase
{
    private array $calls = [];

    protected function setUp(): void
    {
        putenv('SV_API_KEY=tok');
        putenv('SV_ADMIN_PASSWORD=pw');
        $this->calls = [];
    }

    private function manifest(): Manifest
    {
        return Manifest::fromArray([
            'product' => 'p',
            'base_url' => 'http://ea.test',
            'admin' => [
                'first_name' => 'A', 'email' => 'a@example.com',
                'username' => 'a', 'password_env' => 'SV_ADMIN_PASSWORD',
            ],
            'api_token_env' => 'SV_API_KEY',
            'service_categories' => [['name' => 'Rooms']],
            'services' => [
                ['key' => 'MASTERMIND_HEIST', 'name' => 'Mastermind Heist', 'duration' => 60,
                 'attendants_number' => 8, 'category' => 'Rooms', 'requires_approval' => true],
                ['key' => 'ZODIAC_KILLER', 'name' => 'Zodiac Killer', 'duration' => 60,
                 'attendants_number' => 6, 'category' => 'Rooms'],
            ],
        ]);
    }

    /**
     * @param array $existingCategories rows returned by GET /service_categories
     * @param array $existingServices   rows returned by GET /services
     */
    private function client(array $existingCategories, array $existingServices): EaClient
    {
        $nextId = 100;

        return new EaClient('http://ea.test', 'tok',
            function ($method, $url, $body) use ($existingCategories, $existingServices, &$nextId) {
                $this->calls[] = [$method, $url, $body];

                if ($method === 'GET' && str_contains($url, 'service_categories')) {
                    return ['status' => 200, 'body' => json_encode($existingCategories)];
                }
                if ($method === 'GET') {
                    return ['status' => 200, 'body' => json_encode($existingServices)];
                }

                return ['status' => 200, 'body' => json_encode(['id' => $nextId++])];
            });
    }

    public function testCreatesCategoriesAndServicesAndRecordsTheirIds()
    {
        $ids = new Ids();

        $changes = ServicesResource::converge($this->client([], []), $this->manifest(), $ids, false);

        $this->assertSame(100, $ids->categories['Rooms']);
        $this->assertSame(101, $ids->services['MASTERMIND_HEIST']);
        $this->assertSame(102, $ids->services['ZODIAC_KILLER']);
        $this->assertSame('create', $changes[0]->action);
    }

    public function testReusesAnExistingCategoryByName()
    {
        $ids = new Ids();

        ServicesResource::converge(
            $this->client([['id' => 5, 'name' => 'Rooms']], []),
            $this->manifest(),
            $ids,
            false
        );

        $this->assertSame(5, $ids->categories['Rooms']);
    }

    public function testMatchesAnExistingServiceByNameAndSkipsWhenIdentical()
    {
        $ids = new Ids();
        $existing = [[
            'id' => 7, 'name' => 'Mastermind Heist', 'duration' => 60,
            'attendantsNumber' => 8, 'serviceCategoryId' => 5, 'price' => 0.0, 'currency' => '',
        ]];

        $changes = ServicesResource::converge(
            $this->client([['id' => 5, 'name' => 'Rooms']], $existing),
            $this->manifest(),
            $ids,
            false
        );

        $this->assertSame(7, $ids->services['MASTERMIND_HEIST']);

        $heist = array_values(array_filter(
            $changes,
            fn($c) => $c->label === 'Mastermind Heist'
        ))[0];
        $this->assertSame('noop', $heist->action);
    }

    public function testUpdatesAnExistingServiceWhoseDurationDiffers()
    {
        $ids = new Ids();
        $existing = [[
            'id' => 7, 'name' => 'Mastermind Heist', 'duration' => 45,
            'attendantsNumber' => 8, 'serviceCategoryId' => 5, 'price' => 0.0, 'currency' => '',
        ]];

        $changes = ServicesResource::converge(
            $this->client([['id' => 5, 'name' => 'Rooms']], $existing),
            $this->manifest(),
            $ids,
            false
        );

        $heist = array_values(array_filter($changes, fn($c) => $c->label === 'Mastermind Heist'))[0];
        $this->assertSame('update', $heist->action);

        $puts = array_filter($this->calls, fn($c) => $c[0] === 'PUT');
        $this->assertNotEmpty($puts);
    }

    public function testDryRunAssignsNoIdsAndIssuesNoWrites()
    {
        $ids = new Ids();

        $changes = ServicesResource::converge($this->client([], []), $this->manifest(), $ids, true);

        $this->assertSame([], $ids->services);
        $this->assertSame('create', $changes[0]->action);
        $this->assertEmpty(array_filter($this->calls, fn($c) => $c[0] !== 'GET'));
    }
}
