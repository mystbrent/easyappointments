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

        // get() already returns settings (including workingPlan) and services for every
        // provider — there is no ?with= parameter to request them, and asking for
        // `settings` is rejected by Providers_model::load().
        $existing = [];
        foreach ($client->getAll('/api/v1/providers') as $row) {
            if (isset($row['email'])) {
                $existing[$row['email']] = $row;
            }
        }

        $changes = [];

        foreach ($manifest->providers as $provider) {
            $label = trim($provider['first_name'] . ' ' . $provider['last_name']);

            $serviceIds = [];

            foreach ($provider['services'] as $key) {
                if (isset($ids->services[$key])) {
                    $serviceIds[] = $ids->services[$key];
                    continue;
                }

                if (!$dryRun) {
                    throw new \RuntimeException(
                        "Provider '{$provider['key']}' references service key "
                        . "'$key', which has no id — services must converge first."
                    );
                }

                // Dry run against a not-yet-created service: no id exists yet.
                // Nothing is sent, so an incomplete list is harmless and lets
                // `plan` report the provider instead of crashing.
            }

            $payload = [
                'firstName' => $provider['first_name'],
                'lastName' => $provider['last_name'],
                'email' => $provider['email'],
                'phone' => $provider['phone'],
                'timezone' => $provider['timezone'],
                'services' => $serviceIds,
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
                $ids->providers[$provider['key']] = self::validatedId($created, 'provider', $label);
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

    /** Compare everything the manifest owns, ignoring the write-only password. */
    private static function matches(array $existing, array $payload): bool
    {
        foreach (['firstName', 'lastName', 'email', 'phone', 'timezone'] as $field) {
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
