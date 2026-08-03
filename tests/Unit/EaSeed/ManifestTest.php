<?php

namespace Tests\Unit\EaSeed;

use EaSeed\Manifest;
use EaSeed\ManifestException;
use Tests\TestCase;

class ManifestTest extends TestCase
{
    private function valid(): array
    {
        return [
            'product' => 'zeekr-gold-coast',
            'base_url' => 'http://localhost:8080',
            'admin' => [
                'first_name' => 'Zeekr',
                'last_name' => 'Admin',
                'email' => 'admin@zeekrgc.com.au',
                'username' => 'zeekradmin',
                'password_env' => 'TEST_ADMIN_PASSWORD',
            ],
            'api_token_env' => 'TEST_API_KEY',
            'settings' => ['require_phone_number' => true],
            'service_categories' => [['name' => 'Sales']],
            'services' => [[
                'key' => 'TEST_DRIVE',
                'name' => 'Zeekr 7X Test Drive',
                'duration' => 60,
                'attendants_number' => 1,
                'category' => 'Sales',
            ]],
            'providers' => [[
                'key' => 'PRIMARY',
                'first_name' => 'Zeekr',
                'last_name' => 'Sales Team',
                'email' => 'sales@zeekrgc.com.au',
                'timezone' => 'Australia/Brisbane',
                'services' => ['TEST_DRIVE'],
                'settings' => [
                    'username' => 'zeekrsales',
                    'password_env' => 'TEST_PROVIDER_PASSWORD',
                    'working_plan' => [
                        'monday' => [
                            ['start' => '13:00', 'end' => '17:00'],
                            ['start' => '09:00', 'end' => '12:00'],
                        ],
                        'sunday' => [],
                    ],
                ],
            ]],
            'webhooks' => [[
                'name' => 'PWA reconcile',
                'url' => 'https://example.com/api/webhooks/easyappointments',
                'actions' => ['appointment_save'],
                'secret_token_env' => 'TEST_WEBHOOK_SECRET',
            ]],
        ];
    }

    protected function setUp(): void
    {
        putenv('TEST_ADMIN_PASSWORD=adminpw');
        putenv('TEST_PROVIDER_PASSWORD=providerpw');
        putenv('TEST_API_KEY=tok_abc');
        putenv('TEST_WEBHOOK_SECRET=whsec');
    }

    public function testParsesAValidManifest()
    {
        $m = Manifest::fromArray($this->valid());

        $this->assertSame('zeekr-gold-coast', $m->product);
        $this->assertSame('http://localhost:8080', $m->baseUrl);
        $this->assertSame('TEST_DRIVE', $m->services[0]['key']);
        $this->assertSame(1, $m->services[0]['attendants_number']);
    }

    public function testResolvesSecretsFromTheEnvironment()
    {
        $m = Manifest::fromArray($this->valid());

        $this->assertSame('tok_abc', $m->apiToken);
        $this->assertSame('adminpw', $m->admin['password']);
        $this->assertSame('providerpw', $m->providers[0]['settings']['password']);
        $this->assertSame('whsec', $m->webhooks[0]['secret_token']);
    }

    public function testDefaultsRequiresApprovalToFalse()
    {
        $m = Manifest::fromArray($this->valid());

        $this->assertFalse($m->services[0]['requires_approval']);
    }

    public function testSortsSessionsByStartTime()
    {
        $m = Manifest::fromArray($this->valid());
        $monday = $m->providers[0]['settings']['working_plan']['monday'];

        $this->assertSame('09:00', $monday[0]['start']);
        $this->assertSame('13:00', $monday[1]['start']);
    }

    public function testRejectsSessionEndingBeforeItStarts()
    {
        $raw = $this->valid();
        $raw['providers'][0]['settings']['working_plan']['monday'] = [
            ['start' => '19:00', 'end' => '02:00'],
        ];

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/cannot span midnight/');
        Manifest::fromArray($raw);
    }

    public function testRejectsSessionEndingAfterTwentyThreeFiftyNine()
    {
        $raw = $this->valid();
        $raw['providers'][0]['settings']['working_plan']['monday'] = [
            ['start' => '19:00', 'end' => '24:00'],
        ];

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/HH:MM/');
        Manifest::fromArray($raw);
    }

    public function testRejectsOverlappingSessions()
    {
        $raw = $this->valid();
        $raw['providers'][0]['settings']['working_plan']['monday'] = [
            ['start' => '09:00', 'end' => '13:00'],
            ['start' => '12:00', 'end' => '17:00'],
        ];

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/overlap/');
        Manifest::fromArray($raw);
    }

    public function testRejectsProviderReferencingUnknownServiceKey()
    {
        $raw = $this->valid();
        $raw['providers'][0]['services'] = ['TEST_DRIVE', 'NOPE'];

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/NOPE/');
        Manifest::fromArray($raw);
    }

    public function testRejectsDuplicateServiceKeys()
    {
        $raw = $this->valid();
        $raw['services'][] = $raw['services'][0];

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/[Dd]uplicate/');
        Manifest::fromArray($raw);
    }

    public function testRejectsUnsetEnvironmentReference()
    {
        putenv('TEST_API_KEY');
        $raw = $this->valid();

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/TEST_API_KEY/');
        Manifest::fromArray($raw);
    }
}
