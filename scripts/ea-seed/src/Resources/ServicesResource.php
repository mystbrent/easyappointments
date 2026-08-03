<?php

namespace EaSeed\Resources;

use EaSeed\Change;
use EaSeed\EaClient;
use EaSeed\Ids;
use EaSeed\Manifest;

/**
 * Converges service categories and services.
 *
 * EasyAppointments has no external-id column, so records are matched by display
 * name. A renamed service therefore creates a second record rather than renaming
 * the first; the CLI warns about that case.
 */
final class ServicesResource
{
    /** @return list<Change> */
    public static function converge(EaClient $client, Manifest $manifest, Ids $ids, bool $dryRun): array
    {
        $changes = [];

        $existingCategories = self::indexByName($client->get('/api/v1/service_categories'));

        foreach ($manifest->serviceCategories as $category) {
            $name = $category['name'];

            if (isset($existingCategories[$name])) {
                $ids->categories[$name] = (int) $existingCategories[$name]['id'];
                $changes[] = new Change('service_category', $name, 'noop', $ids->categories[$name]);
                continue;
            }

            if ($dryRun) {
                $changes[] = new Change('service_category', $name, 'create');
                continue;
            }

            $created = $client->post('/api/v1/service_categories', ['name' => $name]);
            $ids->categories[$name] = (int) $created['id'];
            $changes[] = new Change('service_category', $name, 'create', $ids->categories[$name]);
        }

        $existingServices = self::indexByName($client->get('/api/v1/services'));

        foreach ($manifest->services as $service) {
            $payload = [
                'name' => $service['name'],
                'duration' => $service['duration'],
                'attendantsNumber' => $service['attendants_number'],
                'price' => $service['price'],
                'currency' => $service['currency'],
                'availabilitiesType' => 'flexible',
                'serviceCategoryId' => $service['category'] === null
                    ? null
                    : ($ids->categories[$service['category']] ?? null),
            ];

            $existing = $existingServices[$service['name']] ?? null;

            if ($existing === null) {
                if ($dryRun) {
                    $changes[] = new Change('service', $service['name'], 'create');
                    continue;
                }

                $created = $client->post('/api/v1/services', $payload);
                $ids->services[$service['key']] = (int) $created['id'];
                $changes[] = new Change('service', $service['name'], 'create', $ids->services[$service['key']]);
                continue;
            }

            $id = (int) $existing['id'];
            $ids->services[$service['key']] = $id;

            if (self::matches($existing, $payload)) {
                $changes[] = new Change('service', $service['name'], 'noop', $id);
                continue;
            }

            if (!$dryRun) {
                $client->put('/api/v1/services/' . $id, $payload);
            }

            $changes[] = new Change('service', $service['name'], 'update', $id);
        }

        return $changes;
    }

    /** @return array<string,array> */
    private static function indexByName(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (isset($row['name'])) {
                $out[$row['name']] = $row;
            }
        }

        return $out;
    }

    private static function matches(array $existing, array $payload): bool
    {
        foreach ($payload as $key => $value) {
            $current = $existing[$key] ?? null;

            if (is_int($value) && (int) $current !== $value) {
                return false;
            }
            if (is_float($value) && abs((float) $current - $value) > 0.001) {
                return false;
            }
            if (is_string($value) && (string) $current !== $value) {
                return false;
            }
            if ($value === null && $current !== null) {
                return false;
            }
        }

        return true;
    }
}
