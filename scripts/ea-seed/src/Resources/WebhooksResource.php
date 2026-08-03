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
        foreach ($client->getAll('/api/v1/webhooks') as $row) {
            if (isset($row['name'])) {
                $existing[$row['name']] = $row;
            }
        }

        $changes = [];

        foreach ($manifest->webhooks as $webhook) {
            $actions = json_encode(array_values($webhook['actions']), JSON_UNESCAPED_SLASHES);

            // Webhooks_model::api_decode() only recognises camelCase 'secretToken'
            // and 'isSslVerified' (single-word fields stay lowercase) — sending the
            // snake_case names here means api_decode()'s array_key_exists() check
            // never matches and the value is silently dropped, so secret_token is
            // never actually persisted. This mirrors the camelCase convention
            // ProvidersResource/ServicesResource already use for multi-word fields.
            $payload = [
                'name' => $webhook['name'],
                'url' => $webhook['url'],
                'actions' => $actions,
                'secretToken' => $webhook['secret_token'],
                'isSslVerified' => $webhook['is_ssl_verified'],
                'notes' => '',
            ];

            $match = $existing[$webhook['name']] ?? null;

            if ($match === null) {
                if ($dryRun) {
                    $changes[] = new Change('webhook', $webhook['name'], 'create');
                    continue;
                }

                $created = $client->post('/api/v1/webhooks', $payload);
                $ids->webhooks[$webhook['name']] = self::validatedId($created, 'webhook', $webhook['name']);
                $changes[] = new Change('webhook', $webhook['name'], 'create', $ids->webhooks[$webhook['name']]);
                continue;
            }

            $id = (int) $match['id'];
            $ids->webhooks[$webhook['name']] = $id;

            // Webhooks_model::api_encode() returns secret_token and is_ssl_verified
            // straight from the row, so — unlike the provider password — drift on
            // these fields IS detectable, and a rotated secret must not report noop.
            $unchanged = ($match['url'] ?? null) === $webhook['url']
                && ($match['actions'] ?? null) === $actions
                && ($match['secret_token'] ?? null) === $webhook['secret_token']
                && (bool) ($match['is_ssl_verified'] ?? false) === $webhook['is_ssl_verified'];

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

    /**
     * A create response with no positive integer id means the API returned a
     * body that did not carry the record it claims to have created — storing
     * (int) null (which casts to 0) is how a bad id ended up written to an
     * emitted env file previously.
     */
    private static function validatedId(array $created, string $resource, string $label): int
    {
        $id = $created['id'] ?? null;

        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            throw new \RuntimeException(
                "Created $resource '$label' but the API response had no usable id "
                . '(got ' . var_export($id, true) . '). Refusing to store 0.'
            );
        }

        $id = (int) $id;

        if ($id <= 0) {
            throw new \RuntimeException(
                "Created $resource '$label' but the API returned a non-positive id ($id)."
            );
        }

        return $id;
    }
}
