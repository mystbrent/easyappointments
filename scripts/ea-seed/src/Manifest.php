<?php

namespace EaSeed;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A parsed, validated vendor manifest.
 *
 * Manifests are committed, so they never contain credentials — a `*_env` key
 * names an environment variable that is resolved here at load time.
 */
final class Manifest
{
    private const DAYS = [
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
    ];

    private function __construct(
        public readonly string $product,
        public readonly string $baseUrl,
        public readonly array $admin,
        public readonly string $apiToken,
        public readonly array $settings,
        public readonly array $serviceCategories,
        public readonly array $services,
        public readonly array $providers,
        public readonly array $webhooks,
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (!is_readable($path)) {
            throw new ManifestException("Manifest not readable: $path");
        }

        try {
            $raw = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new ManifestException(
                "Manifest $path is not valid YAML: {$e->getMessage()}"
            );
        }

        if ($raw !== null && (!is_array($raw) || array_is_list($raw))) {
            throw new ManifestException(
                "Manifest $path must be a YAML mapping, got "
                . (is_array($raw) ? 'a list' : gettype($raw))
            );
        }

        return self::fromArray($raw ?? [], $path);
    }

    public static function fromArray(array $raw, string $source = 'manifest'): self
    {
        $product = self::req($raw, 'product', $source);
        $baseUrl = rtrim(self::req($raw, 'base_url', $source), '/');

        $adminRaw = $raw['admin'] ?? [];
        $admin = [
            'first_name' => self::req($adminRaw, 'first_name', "$source admin"),
            'last_name' => $adminRaw['last_name'] ?? '',
            'email' => self::req($adminRaw, 'email', "$source admin"),
            'username' => self::req($adminRaw, 'username', "$source admin"),
            'password' => self::env(self::req($adminRaw, 'password_env', "$source admin")),
        ];

        $apiToken = self::env(self::req($raw, 'api_token_env', $source));

        $categories = [];
        foreach ($raw['service_categories'] ?? [] as $c) {
            $categories[] = ['name' => self::req($c, 'name', "$source service_categories")];
        }
        $categoryNames = array_column($categories, 'name');

        $services = [];
        $seenServiceKeys = [];
        foreach ($raw['services'] ?? [] as $s) {
            $key = self::req($s, 'key', "$source services");
            if (isset($seenServiceKeys[$key])) {
                throw new ManifestException("Duplicate service key '$key' in $source.");
            }
            $seenServiceKeys[$key] = true;

            $category = $s['category'] ?? null;
            if ($category !== null && !in_array($category, $categoryNames, true)) {
                throw new ManifestException(
                    "Service '$key' references category '$category', which is not declared in $source."
                );
            }

            $services[] = [
                'key' => $key,
                'name' => self::req($s, 'name', "$source services"),
                'duration' => (int) self::req($s, 'duration', "$source services"),
                'attendants_number' => (int) ($s['attendants_number'] ?? 1),
                'category' => $category,
                'price' => (float) ($s['price'] ?? 0),
                'currency' => $s['currency'] ?? '',
                'requires_approval' => (bool) ($s['requires_approval'] ?? false),
            ];
        }
        $serviceKeys = array_column($services, 'key');

        $providers = [];
        $seenProviderKeys = [];
        foreach ($raw['providers'] ?? [] as $p) {
            $key = self::req($p, 'key', "$source providers");
            if (isset($seenProviderKeys[$key])) {
                throw new ManifestException("Duplicate provider key '$key' in $source.");
            }
            $seenProviderKeys[$key] = true;

            foreach ($p['services'] ?? [] as $ref) {
                if (!in_array($ref, $serviceKeys, true)) {
                    throw new ManifestException(
                        "Provider '$key' references service key '$ref', which is not declared in $source."
                    );
                }
            }

            $pSettings = $p['settings'] ?? [];
            $providers[] = [
                'key' => $key,
                'first_name' => self::req($p, 'first_name', "$source providers"),
                'last_name' => $p['last_name'] ?? '',
                'email' => self::req($p, 'email', "$source providers"),
                'phone' => self::req($p, 'phone', "$source providers"),
                'timezone' => $p['timezone'] ?? 'UTC',
                'services' => array_values($p['services'] ?? []),
                'settings' => [
                    'username' => self::req($pSettings, 'username', "$source providers.$key.settings"),
                    'password' => self::env(
                        self::req($pSettings, 'password_env', "$source providers.$key.settings")
                    ),
                    'working_plan' => self::workingPlan($pSettings['working_plan'] ?? [], $key, $source),
                ],
            ];
        }

        $webhooks = [];
        foreach ($raw['webhooks'] ?? [] as $w) {
            $webhooks[] = [
                'name' => self::req($w, 'name', "$source webhooks"),
                'url' => self::req($w, 'url', "$source webhooks"),
                'actions' => array_values($w['actions'] ?? []),
                'secret_token' => self::env(self::req($w, 'secret_token_env', "$source webhooks")),
                'is_ssl_verified' => (bool) ($w['is_ssl_verified'] ?? true),
            ];
        }

        return new self(
            $product,
            $baseUrl,
            $admin,
            $apiToken,
            $raw['settings'] ?? [],
            $categories,
            $services,
            $providers,
            $webhooks,
        );
    }

    /** Validate and normalise every weekday's session list. */
    private static function workingPlan(array $plan, string $providerKey, string $source): array
    {
        $out = [];

        foreach (self::DAYS as $day) {
            $sessions = $plan[$day] ?? [];
            if ($sessions === null) {
                $sessions = [];
            }

            $normalised = [];
            foreach ($sessions as $session) {
                $start = self::time($session['start'] ?? '', $day, $providerKey, $source);
                $end = self::time($session['end'] ?? '', $day, $providerKey, $source);

                if (strcmp($end, $start) <= 0) {
                    throw new ManifestException(
                        "Provider '$providerKey' $day session $start-$end is invalid: a session "
                        . 'cannot span midnight. Express a midnight session as a separate session '
                        . "starting at 00:00 on the following day. ($source)"
                    );
                }

                $normalised[] = ['start' => $start, 'end' => $end];
            }

            usort($normalised, fn(array $a, array $b) => strcmp($a['start'], $b['start']));

            for ($i = 1; $i < count($normalised); $i++) {
                if (strcmp($normalised[$i]['start'], $normalised[$i - 1]['end']) < 0) {
                    throw new ManifestException(
                        "Provider '$providerKey' has overlapping $day sessions "
                        . "({$normalised[$i - 1]['start']}-{$normalised[$i - 1]['end']} and "
                        . "{$normalised[$i]['start']}-{$normalised[$i]['end']}) in $source."
                    );
                }
            }

            $out[$day] = $normalised;
        }

        return $out;
    }

    private static function time(string $value, string $day, string $providerKey, string $source): string
    {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
            throw new ManifestException(
                "Provider '$providerKey' $day time '$value' is not a valid HH:MM value between "
                . "00:00 and 23:59. ($source)"
            );
        }

        return $value;
    }

    private static function req(array $arr, string $key, string $where): string|int|float
    {
        if (!array_key_exists($key, $arr) || $arr[$key] === null || $arr[$key] === '') {
            throw new ManifestException("Missing required key '$key' in $where.");
        }

        return $arr[$key];
    }

    private static function env(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            throw new ManifestException(
                "Environment variable '$name' is referenced by the manifest but is not set."
            );
        }

        return $value;
    }
}
