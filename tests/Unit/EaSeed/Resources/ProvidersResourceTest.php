<?php

namespace Tests\Unit\EaSeed\Resources;

use EaSeed\EaClient;
use EaSeed\Ids;
use EaSeed\Manifest;
use EaSeed\Resources\ProvidersResource;
use Tests\TestCase;

class ProvidersResourceTest extends TestCase
{
    private array $calls = [];

    protected function setUp(): void
    {
        putenv('PR_API_KEY=tok');
        putenv('PR_ADMIN_PASSWORD=pw');
        putenv('PR_PROVIDER_PASSWORD=ppw');
        $this->calls = [];
    }

    private function manifest(): Manifest
    {
        return Manifest::fromArray([
            'product' => 'lostlocks',
            'base_url' => 'http://ea.test',
            'admin' => [
                'first_name' => 'A', 'email' => 'a@example.com',
                'username' => 'a', 'password_env' => 'PR_ADMIN_PASSWORD',
            ],
            'api_token_env' => 'PR_API_KEY',
            'services' => [
                ['key' => 'MASTERMIND_HEIST', 'name' => 'Mastermind Heist', 'duration' => 60],
            ],
            'providers' => [[
                'key' => 'PRIMARY',
                'first_name' => 'Lostlocks',
                'last_name' => 'Game Masters',
                'email' => 'gm@lostlocks.test',
                'timezone' => 'Australia/Brisbane',
                'services' => ['MASTERMIND_HEIST'],
                'settings' => [
                    'username' => 'lostlocksgm',
                    'password_env' => 'PR_PROVIDER_PASSWORD',
                    'working_plan' => [
                        'monday' => [
                            ['start' => '19:30', 'end' => '23:59'],
                            ['start' => '00:00', 'end' => '01:00'],
                        ],
                        'sunday' => [],
                    ],
                ],
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

            return ['status' => 200, 'body' => json_encode(['id' => 42])];
        });
    }

    private function lastWriteBody(): array
    {
        $writes = array_values(array_filter($this->calls, fn($c) => $c[0] !== 'GET'));

        return end($writes)[2];
    }

    public function testCreatesTheProviderAndRecordsItsId()
    {
        $ids = new Ids();
        $ids->services['MASTERMIND_HEIST'] = 7;

        $changes = ProvidersResource::converge($this->client([]), $this->manifest(), $ids, false);

        $this->assertSame(42, $ids->providers['PRIMARY']);
        $this->assertSame('create', $changes[0]->action);
    }

    public function testResolvesServiceKeysToNumericIds()
    {
        $ids = new Ids();
        $ids->services['MASTERMIND_HEIST'] = 7;

        ProvidersResource::converge($this->client([]), $this->manifest(), $ids, false);

        $this->assertSame([7], $this->lastWriteBody()['services']);
    }

    public function testSendsTheCompiledWorkingPlanWithTheMidnightSessionIntact()
    {
        $ids = new Ids();
        $ids->services['MASTERMIND_HEIST'] = 7;

        ProvidersResource::converge($this->client([]), $this->manifest(), $ids, false);

        $monday = $this->lastWriteBody()['settings']['workingPlan']['monday'];

        $this->assertSame('00:00', $monday['start']);
        $this->assertSame('23:59', $monday['end']);
        $this->assertSame([['start' => '01:00', 'end' => '19:30']], $monday['breaks']);
    }

    public function testClosedDaysAreSentAsNull()
    {
        $ids = new Ids();
        $ids->services['MASTERMIND_HEIST'] = 7;

        ProvidersResource::converge($this->client([]), $this->manifest(), $ids, false);

        $this->assertNull($this->lastWriteBody()['settings']['workingPlan']['sunday']);
    }

    /** A provider already matching the manifest, as EA returns it with ?with=settings. */
    private function converged(): array
    {
        return [[
            'id' => 2,
            'email' => 'gm@lostlocks.test',
            'firstName' => 'Lostlocks',
            'lastName' => 'Game Masters',
            'timezone' => 'Australia/Brisbane',
            'services' => [7],
            'settings' => [
                'username' => 'lostlocksgm',
                'workingPlan' => [
                    'monday' => [
                        'start' => '00:00', 'end' => '23:59',
                        'breaks' => [['start' => '01:00', 'end' => '19:30']],
                    ],
                    'tuesday' => null, 'wednesday' => null, 'thursday' => null,
                    'friday' => null, 'saturday' => null, 'sunday' => null,
                ],
            ],
        ]];
    }

    public function testMatchesAnExistingProviderByEmail()
    {
        $ids = new Ids();
        $ids->services['MASTERMIND_HEIST'] = 7;

        ProvidersResource::converge($this->client($this->converged()), $this->manifest(), $ids, false);

        $this->assertSame(2, $ids->providers['PRIMARY']);
    }

    public function testSkipsAProviderAlreadyMatchingTheManifest()
    {
        $ids = new Ids();
        $ids->services['MASTERMIND_HEIST'] = 7;

        $changes = ProvidersResource::converge(
            $this->client($this->converged()),
            $this->manifest(),
            $ids,
            false
        );

        $this->assertSame('noop', $changes[0]->action);
        $this->assertEmpty(array_filter($this->calls, fn($c) => $c[0] !== 'GET'));
    }

    public function testUpdatesWhenTheWorkingPlanDiffers()
    {
        $ids = new Ids();
        $ids->services['MASTERMIND_HEIST'] = 7;

        $stale = $this->converged();
        $stale[0]['settings']['workingPlan']['monday']['breaks'] = [
            ['start' => '01:00', 'end' => '18:00'],
        ];

        $changes = ProvidersResource::converge($this->client($stale), $this->manifest(), $ids, false);

        $this->assertSame('update', $changes[0]->action);
        $this->assertContains('PUT', array_column($this->calls, 0));
    }

    public function testUpdateOmitsThePasswordSoItIsNotResetOnEveryApply()
    {
        $ids = new Ids();
        $ids->services['MASTERMIND_HEIST'] = 7;

        $stale = $this->converged();
        $stale[0]['timezone'] = 'UTC';

        ProvidersResource::converge($this->client($stale), $this->manifest(), $ids, false);

        $this->assertArrayNotHasKey('password', $this->lastWriteBody()['settings']);
    }

    public function testRequestsProvidersWithTheirSettings()
    {
        $ids = new Ids();
        $ids->services['MASTERMIND_HEIST'] = 7;

        ProvidersResource::converge($this->client([]), $this->manifest(), $ids, false);

        $this->assertStringContainsString('with=settings', $this->calls[0][1]);
    }

    public function testDryRunIssuesNoWrites()
    {
        $ids = new Ids();
        $ids->services['MASTERMIND_HEIST'] = 7;

        ProvidersResource::converge($this->client([]), $this->manifest(), $ids, true);

        $this->assertEmpty(array_filter($this->calls, fn($c) => $c[0] !== 'GET'));
    }

    public function testDryRunToleratesUnresolvedServiceIds()
    {
        $ids = new Ids();

        $changes = ProvidersResource::converge($this->client([]), $this->manifest(), $ids, true);

        $this->assertSame('create', $changes[0]->action);
    }

    public function testApplyStillThrowsOnUnresolvedServiceId()
    {
        $ids = new Ids();

        $this->expectException(\RuntimeException::class);

        ProvidersResource::converge($this->client([]), $this->manifest(), $ids, false);
    }
}
