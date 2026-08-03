<?php

namespace Tests\Unit\EaSeed;

use EaSeed\EnvEmitter;
use EaSeed\Ids;
use EaSeed\Manifest;
use Tests\TestCase;

class EnvEmitterTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('EM_API_KEY=tok');
        putenv('EM_ADMIN_PASSWORD=pw');
        putenv('EM_PROVIDER_PASSWORD=ppw');
    }

    private function manifest(array $services): Manifest
    {
        return Manifest::fromArray([
            'product' => 'lostlocks',
            'base_url' => 'http://localhost:8081',
            'admin' => [
                'first_name' => 'A', 'email' => 'a@example.com',
                'username' => 'a', 'password_env' => 'EM_ADMIN_PASSWORD',
            ],
            'api_token_env' => 'EM_API_KEY',
            'settings' => ['default_timezone' => 'Australia/Brisbane'],
            'services' => $services,
            'providers' => [[
                'key' => 'PRIMARY', 'first_name' => 'GM', 'email' => 'gm@lostlocks.test',
                'phone' => '+61 7 5555 0200',
                'services' => array_column($services, 'key'),
                'settings' => ['username' => 'gm', 'password_env' => 'EM_PROVIDER_PASSWORD'],
            ]],
        ]);
    }

    private function ids(array $serviceIds): Ids
    {
        $ids = new Ids();
        $ids->services = $serviceIds;
        $ids->providers = ['PRIMARY' => 2];

        return $ids;
    }

    public function testEmitsBaseUrlProviderAndServiceIds()
    {
        $out = EnvEmitter::render(
            $this->manifest([
                ['key' => 'MASTERMIND_HEIST', 'name' => 'Mastermind Heist', 'duration' => 60],
                ['key' => 'ZODIAC_KILLER', 'name' => 'Zodiac Killer', 'duration' => 60],
            ]),
            $this->ids(['MASTERMIND_HEIST' => 1, 'ZODIAC_KILLER' => 2])
        );

        $this->assertStringContainsString('EA_BASE_URL=http://localhost:8081', $out);
        $this->assertStringContainsString('EA_PROVIDER_ID=2', $out);
        $this->assertStringContainsString('EA_SERVICE_MASTERMIND_HEIST=1', $out);
        $this->assertStringContainsString('EA_SERVICE_ZODIAC_KILLER=2', $out);
    }

    public function testEmitsTimezoneFromSettings()
    {
        $out = EnvEmitter::render(
            $this->manifest([['key' => 'A', 'name' => 'A', 'duration' => 60]]),
            $this->ids(['A' => 1])
        );

        $this->assertStringContainsString('EA_TIMEZONE=Australia/Brisbane', $out);
    }

    public function testEmitsGatedServiceIdsForServicesRequiringApproval()
    {
        $out = EnvEmitter::render(
            $this->manifest([
                ['key' => 'MASTERMIND_HEIST', 'name' => 'Mastermind Heist', 'duration' => 60,
                 'requires_approval' => true],
                ['key' => 'ZODIAC_KILLER', 'name' => 'Zodiac Killer', 'duration' => 60],
                ['key' => 'ZOMBIE_LAB', 'name' => 'Zombie Lab', 'duration' => 60,
                 'requires_approval' => true],
            ]),
            $this->ids(['MASTERMIND_HEIST' => 1, 'ZODIAC_KILLER' => 2, 'ZOMBIE_LAB' => 4])
        );

        $this->assertStringContainsString('EA_GATED_SERVICE_IDS=1,4', $out);
    }

    public function testOmitsGatedServiceIdsWhenNoServiceRequiresApproval()
    {
        $out = EnvEmitter::render(
            $this->manifest([['key' => 'TEST_DRIVE', 'name' => 'Test Drive', 'duration' => 60]]),
            $this->ids(['TEST_DRIVE' => 7])
        );

        $this->assertStringNotContainsString('EA_GATED_SERVICE_IDS', $out);
    }

    public function testNeverEmitsSecrets()
    {
        $out = EnvEmitter::render(
            $this->manifest([['key' => 'A', 'name' => 'A', 'duration' => 60]]),
            $this->ids(['A' => 1])
        );

        $this->assertStringNotContainsString('tok', $out);
        $this->assertStringNotContainsString('EA_API_KEY=', $out);
    }
}
