<?php

namespace EaSeed\Resources;

use EaSeed\Change;
use EaSeed\EaClient;
use EaSeed\Ids;
use EaSeed\Manifest;
use EaSeed\WorkingPlanCompiler;

/**
 * Converges providers, matching on email.
 *
 * Service references are stored in the manifest as stable keys and resolved here
 * to the numeric ids assigned by ServicesResource, so a manifest never contains
 * an id that EasyAppointments chose.
 */
final class ProvidersResource
{
    /** @return list<Change> */
    public static function converge(EaClient $client, Manifest $manifest, Ids $ids, bool $dryRun): array
    {
        if ($manifest->providers === []) {
            return [];
        }

        // ?with=settings is required — without it EA omits workingPlan, and the
        // provider would be rewritten on every apply.
        $existing = [];
        foreach ($client->get('/api/v1/providers?with=settings') as $row) {
            if (isset($row['email'])) {
                $existing[$row['email']] = $row;
            }
        }

        $changes = [];

        foreach ($manifest->providers as $provider) {
            $label = trim($provider['first_name'] . ' ' . $provider['last_name']);

            $payload = [
                'firstName' => $provider['first_name'],
                'lastName' => $provider['last_name'],
                'email' => $provider['email'],
                'timezone' => $provider['timezone'],
                'services' => array_values(array_map(
                    fn(string $key) => $ids->services[$key]
                        ?? throw new \RuntimeException(
                            "Provider '{$provider['key']}' references service key '$key', "
                            . 'which has no id — services must converge first.'
                        ),
                    $provider['services']
                )),
                'settings' => [
                    'username' => $provider['settings']['username'],
                    'password' => $provider['settings']['password'],
                    'notifications' => true,
                    'calendarView' => 'default',
                    'workingPlan' => self::compilePlan($provider['settings']['working_plan']),
                ],
            ];

            $match = $existing[$provider['email']] ?? null;

            if ($match === null) {
                if ($dryRun) {
                    $changes[] = new Change('provider', $label, 'create');
                    continue;
                }

                $created = $client->post('/api/v1/providers', $payload);
                $ids->providers[$provider['key']] = (int) $created['id'];
                $changes[] = new Change('provider', $label, 'create', $ids->providers[$provider['key']]);
                continue;
            }

            $id = (int) $match['id'];
            $ids->providers[$provider['key']] = $id;

            if (self::matches($match, $payload)) {
                $changes[] = new Change('provider', $label, 'noop', $id);
                continue;
            }

            // Password is omitted on update so an apply never resets a live
            // provider's credentials — and so it never forces a spurious diff.
            unset($payload['settings']['password']);

            if (!$dryRun) {
                $client->put('/api/v1/providers/' . $id, $payload);
            }

            $changes[] = new Change('provider', $label, 'update', $id);
        }

        return $changes;
    }

    /** Compare everything the manifest owns, ignoring the write-only password. */
    private static function matches(array $existing, array $payload): bool
    {
        foreach (['firstName', 'lastName', 'email', 'timezone'] as $field) {
            if ((string) ($existing[$field] ?? '') !== (string) $payload[$field]) {
                return false;
            }
        }

        $currentServices = array_map('intval', $existing['services'] ?? []);
        sort($currentServices);
        $desiredServices = $payload['services'];
        sort($desiredServices);

        if ($currentServices !== $desiredServices) {
            return false;
        }

        $currentSettings = $existing['settings'] ?? [];

        if ((string) ($currentSettings['username'] ?? '') !== (string) $payload['settings']['username']) {
            return false;
        }

        return ($currentSettings['workingPlan'] ?? null) == $payload['settings']['workingPlan'];
    }

    /** @return array<string,array|null> */
    private static function compilePlan(array $workingPlan): array
    {
        $compiled = [];

        foreach ($workingPlan as $day => $sessions) {
            $compiled[$day] = WorkingPlanCompiler::compile($sessions);
        }

        return $compiled;
    }
}
