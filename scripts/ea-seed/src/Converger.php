<?php

namespace EaSeed;

use EaSeed\Resources\ProvidersResource;
use EaSeed\Resources\ServicesResource;
use EaSeed\Resources\SettingsResource;
use EaSeed\Resources\WebhooksResource;

/**
 * Phase 1. Runs each resource in dependency order.
 *
 * REST cannot be transactional, so the contract is ordered, independently
 * re-runnable steps: if a step throws, everything before it has already
 * converged and re-running picks up from there.
 */
final class Converger
{
    public function __construct(private readonly EaClient $client)
    {
    }

    /** @return array{changes: list<Change>, ids: Ids} */
    public function run(Manifest $manifest, bool $dryRun): array
    {
        $ids = new Ids();
        $changes = [];

        foreach ([
            SettingsResource::class,
            ServicesResource::class,
            ProvidersResource::class,
            WebhooksResource::class,
        ] as $resource) {
            $changes = array_merge($changes, $resource::converge($this->client, $manifest, $ids, $dryRun));
        }

        return ['changes' => $changes, 'ids' => $ids];
    }
}
