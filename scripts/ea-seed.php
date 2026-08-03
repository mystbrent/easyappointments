<?php
/**
 * ea-seed — declarative seeding for an EasyAppointments instance.
 *
 * Usage (run inside the EA container):
 *   php scripts/ea-seed.php plan  verticals/<product>.yml   dry run, writes nothing
 *   php scripts/ea-seed.php apply verticals/<product>.yml   converge + emit .env
 *   php scripts/ea-seed.php env   verticals/<product>.yml   re-emit .env from live ids
 *
 * Phase 0 (admin user + api_token) needs the database, and reads EA_DB_* from
 * the environment. Phase 1 talks to base_url over HTTP.
 */

use EaSeed\Bootstrap;
use EaSeed\Converger;
use EaSeed\EaClient;
use EaSeed\EnvEmitter;
use EaSeed\Manifest;
use EaSeed\ManifestException;

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';

// password_helper.php (and constants.php, for MAX_PASSWORD_LENGTH) are guarded
// with `defined('BASEPATH') or exit(...)` because they normally load inside a
// booted CodeIgniter app. This CLI runs standalone, so BASEPATH is defined here
// purely to satisfy that guard — no other part of the CI framework is loaded.
define('BASEPATH', true);
require_once $root . '/application/config/constants.php';
require_once $root . '/application/helpers/password_helper.php';

$command = $argv[1] ?? '';
$path = $argv[2] ?? '';

if (!in_array($command, ['plan', 'apply', 'env'], true) || $path === '') {
    fwrite(STDERR, "Usage: ea-seed {plan|apply|env} <manifest.yml>\n");
    exit(1);
}

try {
    $manifest = Manifest::fromFile($path);
} catch (ManifestException $e) {
    fwrite(STDERR, "Manifest error: {$e->getMessage()}\n");
    exit(1);
}

$dryRun = $command === 'plan';

try {
    if ($command === 'apply') {
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                getenv('EA_DB_HOST') ?: 'localhost',
                getenv('EA_DB_PORT') ?: '3306',
                getenv('EA_DB_NAME') ?: ''
            ),
            getenv('EA_DB_USERNAME') ?: '',
            getenv('EA_DB_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        foreach ((new Bootstrap($pdo, 'hash_password'))->run($manifest) as $line) {
            fwrite(STDOUT, "  bootstrap  $line\n");
        }
    }

    $client = new EaClient($manifest->baseUrl, $manifest->apiToken);
    $result = (new Converger($client))->run($manifest, $dryRun);

    $counts = ['create' => 0, 'update' => 0, 'noop' => 0];

    foreach ($result['changes'] as $change) {
        $counts[$change->action]++;
        $verb = $dryRun && $change->action !== 'noop' ? 'would ' . $change->action : $change->action;
        $id = $change->id === null ? '' : " → {$change->id}";
        fwrite(STDOUT, sprintf("  %-18s %-8s %s%s\n", $change->resource, $verb, $change->label, $id));
    }

    fwrite(STDOUT, sprintf(
        "\n%d created, %d updated, %d unchanged\n",
        $counts['create'],
        $counts['update'],
        $counts['noop']
    ));

    if ($command === 'plan') {
        exit(0);
    }

    $envPath = $root . '/.env.' . $manifest->product;
    file_put_contents($envPath, EnvEmitter::render($manifest, $result['ids']));
    fwrite(STDOUT, "→ wrote $envPath\n");

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\nFailed: {$e->getMessage()}\n");
    fwrite(STDERR, "Nothing further was written. Re-run to converge from where this stopped.\n");
    exit(1);
}
