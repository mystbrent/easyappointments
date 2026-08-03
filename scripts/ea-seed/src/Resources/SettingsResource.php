<?php

namespace EaSeed\Resources;

use EaSeed\Change;
use EaSeed\EaClient;
use EaSeed\Ids;
use EaSeed\Manifest;

/** Converges the manifest's settings block onto ea_settings via the API. */
final class SettingsResource
{
    /** @return list<Change> */
    public static function converge(EaClient $client, Manifest $manifest, Ids $ids, bool $dryRun): array
    {
        if ($manifest->settings === []) {
            return [];
        }

        // EA paginates at 20 by default (Api::$default_length). EA ships well over 20
        // settings, so an unpaginated GET silently truncates and every manifest key
        // reads as absent — causing a PUT on every apply and breaking idempotency.
        $current = [];
        foreach ($client->getAll('/api/v1/settings') as $row) {
            if (isset($row['name'])) {
                $current[$row['name']] = (string) ($row['value'] ?? '');
            }
        }

        $changes = [];

        foreach ($manifest->settings as $name => $value) {
            $desired = self::scalar($value);

            if (array_key_exists($name, $current) && $current[$name] === $desired) {
                $changes[] = new Change('setting', $name, 'noop');
                continue;
            }

            if (!$dryRun) {
                $client->put('/api/v1/settings/' . rawurlencode((string) $name), ['value' => $desired]);
            }

            $changes[] = new Change('setting', $name, 'update');
        }

        return $changes;
    }

    /** EA stores every setting as a string; booleans are '1' and '0'. */
    private static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
