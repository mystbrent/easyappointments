<?php

namespace EaSeed\Resources;

use EaSeed\Change;
use EaSeed\EaClient;
use EaSeed\Ids;
use EaSeed\Manifest;

/**
 * Converges webhooks, matching on name.
 *
 * `actions` is a TEXT column holding a JSON string, so the array from the
 * manifest is encoded before sending. Passing an array here silently produces
 * a webhook that never fires.
 */
final class WebhooksResource
{
    /** @return list<Change> */
    public static function converge(EaClient $client, Manifest $manifest, Ids $ids, bool $dryRun): array
    {
        if ($manifest->webhooks === []) {
            return [];
        }

        $existing = [];
        foreach ($client->get('/api/v1/webhooks') as $row) {
            if (isset($row['name'])) {
                $existing[$row['name']] = $row;
            }
        }

        $changes = [];

        foreach ($manifest->webhooks as $webhook) {
            $actions = json_encode(array_values($webhook['actions']), JSON_UNESCAPED_SLASHES);

            $payload = [
                'name' => $webhook['name'],
                'url' => $webhook['url'],
                'actions' => $actions,
                'secret_token' => $webhook['secret_token'],
                'is_ssl_verified' => $webhook['is_ssl_verified'],
                'notes' => '',
            ];

            $match = $existing[$webhook['name']] ?? null;

            if ($match === null) {
                if ($dryRun) {
                    $changes[] = new Change('webhook', $webhook['name'], 'create');
                    continue;
                }

                $created = $client->post('/api/v1/webhooks', $payload);
                $ids->webhooks[$webhook['name']] = (int) $created['id'];
                $changes[] = new Change('webhook', $webhook['name'], 'create', $ids->webhooks[$webhook['name']]);
                continue;
            }

            $id = (int) $match['id'];
            $ids->webhooks[$webhook['name']] = $id;

            $unchanged = ($match['url'] ?? null) === $webhook['url']
                && ($match['actions'] ?? null) === $actions;

            if ($unchanged) {
                $changes[] = new Change('webhook', $webhook['name'], 'noop', $id);
                continue;
            }

            // ea-seed does not manage notes — omit on update so an admin-entered note
            // survives a url/actions change. Same reasoning as the provider password
            // and secret_token: fields we cannot meaningfully compare are left alone.
            unset($payload['notes']);

            if (!$dryRun) {
                $client->put('/api/v1/webhooks/' . $id, $payload);
            }

            $changes[] = new Change('webhook', $webhook['name'], 'update', $id);
        }

        return $changes;
    }
}
