# Vertical Seeding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `ea-seed`, a declarative seeder that takes an empty EasyAppointments instance to fully configured from a per-vendor YAML manifest, and prove it by serving two unrelated organizations — Zeekr Gold Coast (car dealership) and Lostlocks (escape rooms).

**Architecture:** A PHP CLI (`scripts/ea-seed.php`) running inside the EA container. Phase 0 writes the admin user and API token straight to MySQL over PDO, because the API token is itself a setting and nothing can authenticate until it exists. Phase 1 converges settings, service categories, services, providers and webhooks over EA's REST API, matching existing records by natural key so re-runs are no-ops. It then emits a `.env.<product>` carrying the numeric IDs EA assigned.

**Tech Stack:** PHP 8.1+, PHPUnit 9.6, `symfony/yaml`, PDO MySQL, Docker Compose. No framework — seeder classes are plain PSR-4 and are unit-testable without booting CodeIgniter.

**Spec:** `docs/superpowers/specs/2026-08-03-vertical-seeding-and-infra-tuning-design.md`

## Global Constraints

- PHP `>=8.1`. Target PHPUnit `^9.6` (already in `require-dev`).
- `symfony/yaml` goes in `require`, **not** `require-dev` — `Dockerfile.production:21` runs `composer install --no-dev`.
- All seeder classes live under namespace `EaSeed\` mapped to `scripts/ea-seed/src/`. Tests under `Tests\Unit\EaSeed\` in `tests/Unit/EaSeed/`.
- Seeder classes must not reference CodeIgniter globals (`get_instance()`, `setting()`, `$CI`). They are plain PHP so they unit-test without a database.
- Run tests with `composer test` (`APP_ENV=testing php vendor/bin/phpunit`). PHPUnit's bootstrap is `index.php`, which loads `vendor/autoload.php` at line 74 — composer-autoloaded classes are available in tests.
- Table prefix is `ea_`. `ea_settings.name` is **not unique**; never use `INSERT ... ON DUPLICATE KEY UPDATE` against it.
- `ea_webhooks.actions` is TEXT holding a JSON **string**, e.g. `"[\"appointment_save\"]"`.
- Working plans are day-bounded: one `start`/`end` per weekday, max `23:59`, no session may cross midnight.
- Manifests are committed and contain **no secrets** — credentials are referenced via `*_env` keys resolved from the environment.
- Commit after every task. Conventional commit prefixes (`feat:`, `test:`, `chore:`, `docs:`).

---

### Task 1: Local multi-vendor harness

Two EA instances against one MySQL, mirroring production's one-server-many-databases shape. Also fixes the image so the seeder can actually run inside it.

**Files:**
- Create: `docker/local/init-vendors.sql`
- Modify: `docker-compose.local.yml` (full rewrite)
- Modify: `Dockerfile.production:57` (add `scripts/` to the runtime stage)
- Modify: `.dockerignore`

**Interfaces:**
- Consumes: nothing.
- Produces: two EA instances reachable at `http://localhost:8080` (Zeekr) and `http://localhost:8081` (Lostlocks); MySQL on `localhost:3307` with databases `ea_zeekr_gold_coast` and `ea_lostlocks`; `scripts/` present at `/var/www/html/scripts` in the image.

- [ ] **Step 1: Write the vendor database init script**

Create `docker/local/init-vendors.sql`:

```sql
-- Runs once on first MySQL start (docker-entrypoint-initdb.d).
-- Mirrors what scripts/provision-instance.sh does against Cloud SQL:
-- one database + one least-privilege user per vendor.

CREATE DATABASE IF NOT EXISTS ea_zeekr_gold_coast
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'ea_zeekr'@'%' IDENTIFIED BY 'zeekr_pass';
GRANT ALL PRIVILEGES ON ea_zeekr_gold_coast.* TO 'ea_zeekr'@'%';

CREATE DATABASE IF NOT EXISTS ea_lostlocks
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'ea_lostlocks'@'%' IDENTIFIED BY 'lostlocks_pass';
GRANT ALL PRIVILEGES ON ea_lostlocks.* TO 'ea_lostlocks'@'%';

FLUSH PRIVILEGES;
```

- [ ] **Step 2: Rewrite the compose file for two vendors**

Replace `docker-compose.local.yml` entirely:

```yaml
# Local multi-vendor harness. Two EasyAppointments instances share one MySQL —
# the same shape as production, where one Cloud SQL server hosts a separate
# database and user per vendor.
#
#   ea-zeekr-gold-coast  http://localhost:8080
#   ea-lostlocks         http://localhost:8081
#   mysql                localhost:3307
#
# Usage: docker compose -f docker-compose.local.yml up --build -d

services:
  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: rootpass
    ports:
      - "3307:3306"
    volumes:
      - ea_mysql_data:/var/lib/mysql
      - ./docker/local/init-vendors.sql:/docker-entrypoint-initdb.d/10-vendors.sql:ro
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-u", "root", "-prootpass"]
      interval: 5s
      timeout: 5s
      retries: 10
      start_period: 30s

  ea-zeekr-gold-coast:
    image: ea-local:latest
    build:
      context: .
      dockerfile: Dockerfile.production
    ports:
      - "8080:80"
    environment:
      EA_BASE_URL: "http://localhost:8080"
      EA_DB_HOST: mysql
      EA_DB_PORT: "3306"
      EA_DB_NAME: ea_zeekr_gold_coast
      EA_DB_USERNAME: ea_zeekr
      EA_DB_PASSWORD: zeekr_pass
      EA_DB_SSL: "false"
      EA_DEBUG_MODE: "true"
      EA_CORS_ORIGINS: "http://localhost:3000"
      EA_GOOGLE_SYNC: "false"
    depends_on:
      mysql:
        condition: service_healthy

  ea-lostlocks:
    image: ea-local:latest
    build:
      context: .
      dockerfile: Dockerfile.production
    ports:
      - "8081:80"
    environment:
      EA_BASE_URL: "http://localhost:8081"
      EA_DB_HOST: mysql
      EA_DB_PORT: "3306"
      EA_DB_NAME: ea_lostlocks
      EA_DB_USERNAME: ea_lostlocks
      EA_DB_PASSWORD: lostlocks_pass
      EA_DB_SSL: "false"
      EA_DEBUG_MODE: "true"
      EA_CORS_ORIGINS: "http://localhost:3001"
      EA_GOOGLE_SYNC: "false"
    depends_on:
      mysql:
        condition: service_healthy

volumes:
  ea_mysql_data:
```

Both services declare the same `image:` tag and the same build context, so the image builds once and is reused.

- [ ] **Step 3: Ship `scripts/` in the runtime image**

`Dockerfile.production` copies the runtime stage selectively and currently omits `scripts/`, so the seeder would not exist in the container. After line 57 (`COPY --from=builder /var/www/html/vendor ./vendor`), add:

```dockerfile
COPY --from=builder /var/www/html/scripts ./scripts
```

- [ ] **Step 4: Keep `scripts/` out of the ignore list**

Confirm `.dockerignore` does not exclude `scripts/`. It currently excludes `tests/`, `docs/`, `*.md`, `phpunit.xml` — none of which match. No edit needed unless a `scripts/` entry exists; if one does, remove it.

- [ ] **Step 5: Bring the stack up**

Run:

```bash
docker compose -f docker-compose.local.yml down -v
docker compose -f docker-compose.local.yml up --build -d
```

Expected: three containers running, image built once.

- [ ] **Step 6: Verify both instances and the databases**

Run:

```bash
curl -s -o /dev/null -w "zeekr:%{http_code}\n" http://localhost:8080/
curl -s -o /dev/null -w "lostlocks:%{http_code}\n" http://localhost:8081/
docker compose -f docker-compose.local.yml exec -T mysql \
  mysql -uroot -prootpass -sN -e "SHOW DATABASES LIKE 'ea_%';"
docker compose -f docker-compose.local.yml exec -T ea-zeekr-gold-coast ls /var/www/html/scripts
```

Expected: both HTTP codes `200`; both `ea_lostlocks` and `ea_zeekr_gold_coast` listed; `scripts` directory listing succeeds.

- [ ] **Step 7: Commit**

```bash
git add docker-compose.local.yml docker/local/init-vendors.sql Dockerfile.production
git commit -m "feat: local multi-vendor harness with two EA instances on shared MySQL"
```

---

### Task 2: Working plan compiler

Converts the sessions a vendor operates into EA's single `start`/`end` plus breaks. This is what makes Lostlocks' `00:00` slot representable.

**Files:**
- Create: `scripts/ea-seed/src/WorkingPlanCompiler.php`
- Test: `tests/Unit/EaSeed/WorkingPlanCompilerTest.php`
- Modify: `composer.json` (autoload)

**Interfaces:**
- Consumes: nothing.
- Produces: `EaSeed\WorkingPlanCompiler::compile(array $sessions): ?array` — takes a list of `['start' => 'HH:MM', 'end' => 'HH:MM']` and returns `['start' => string, 'end' => string, 'breaks' => list<array{start:string,end:string}>]`, or `null` for an empty list (closed that day). Assumes sessions are already validated and sorted by Task 3.

- [ ] **Step 1: Add the PSR-4 autoload mapping**

In `composer.json`, extend the `autoload.psr-4` block so it reads:

```json
    "autoload": {
        "psr-4": {
            "Tests\\": "tests/",
            "EaSeed\\": "scripts/ea-seed/src/"
        }
    }
```

Then run:

```bash
composer dump-autoload
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/EaSeed/WorkingPlanCompilerTest.php`:

```php
<?php

namespace Tests\Unit\EaSeed;

use EaSeed\WorkingPlanCompiler;
use Tests\TestCase;

class WorkingPlanCompilerTest extends TestCase
{
    public function testEmptyDayCompilesToNull()
    {
        $this->assertNull(WorkingPlanCompiler::compile([]));
    }

    public function testSingleSessionHasNoBreaks()
    {
        $result = WorkingPlanCompiler::compile([
            ['start' => '09:00', 'end' => '16:00'],
        ]);

        $this->assertSame('09:00', $result['start']);
        $this->assertSame('16:00', $result['end']);
        $this->assertSame([], $result['breaks']);
    }

    public function testGapBetweenSessionsBecomesABreak()
    {
        $result = WorkingPlanCompiler::compile([
            ['start' => '09:00', 'end' => '12:00'],
            ['start' => '13:00', 'end' => '17:00'],
        ]);

        $this->assertSame('09:00', $result['start']);
        $this->assertSame('17:00', $result['end']);
        $this->assertSame([['start' => '12:00', 'end' => '13:00']], $result['breaks']);
    }

    public function testMidnightSessionAndEveningBlockCompileToOneInvertedBreak()
    {
        // Lostlocks: Mastermind Heist runs at 00:00, rooms also run 19:30 onwards.
        $result = WorkingPlanCompiler::compile([
            ['start' => '00:00', 'end' => '01:00'],
            ['start' => '19:30', 'end' => '23:59'],
        ]);

        $this->assertSame('00:00', $result['start']);
        $this->assertSame('23:59', $result['end']);
        $this->assertSame([['start' => '01:00', 'end' => '19:30']], $result['breaks']);
    }

    public function testThreeSessionsProduceTwoBreaks()
    {
        $result = WorkingPlanCompiler::compile([
            ['start' => '07:00', 'end' => '09:00'],
            ['start' => '11:00', 'end' => '13:00'],
            ['start' => '15:00', 'end' => '18:00'],
        ]);

        $this->assertSame('07:00', $result['start']);
        $this->assertSame('18:00', $result['end']);
        $this->assertSame([
            ['start' => '09:00', 'end' => '11:00'],
            ['start' => '13:00', 'end' => '15:00'],
        ], $result['breaks']);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `composer test -- --filter WorkingPlanCompilerTest`
Expected: FAIL with `Class "EaSeed\WorkingPlanCompiler" not found`.

- [ ] **Step 4: Write the implementation**

Create `scripts/ea-seed/src/WorkingPlanCompiler.php`:

```php
<?php

namespace EaSeed;

/**
 * Compiles the sessions a vendor operates into the single start/end plus breaks
 * that EasyAppointments stores per weekday.
 *
 * EA allows one contiguous range per day, so a vendor working two separated
 * blocks is expressed as the outer span with the gap carved out as a break.
 * This is what lets an evening block coexist with a 00:00 session.
 */
final class WorkingPlanCompiler
{
    /**
     * @param list<array{start: string, end: string}> $sessions Validated, sorted ascending by start.
     * @return array{start: string, end: string, breaks: list<array{start: string, end: string}>}|null
     *         Null when the vendor is closed that day.
     */
    public static function compile(array $sessions): ?array
    {
        if ($sessions === []) {
            return null;
        }

        $sessions = array_values($sessions);
        $breaks = [];

        for ($i = 1; $i < count($sessions); $i++) {
            $breaks[] = [
                'start' => $sessions[$i - 1]['end'],
                'end' => $sessions[$i]['start'],
            ];
        }

        return [
            'start' => $sessions[0]['start'],
            'end' => $sessions[count($sessions) - 1]['end'],
            'breaks' => $breaks,
        ];
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `composer test -- --filter WorkingPlanCompilerTest`
Expected: PASS, 5 tests.

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock scripts/ea-seed/src/WorkingPlanCompiler.php tests/Unit/EaSeed/WorkingPlanCompilerTest.php
git commit -m "feat: compile vendor sessions into EA day-bounded working plans"
```

---

### Task 3: Manifest loading and validation

**Files:**
- Create: `scripts/ea-seed/src/ManifestException.php`
- Create: `scripts/ea-seed/src/Manifest.php`
- Test: `tests/Unit/EaSeed/ManifestTest.php`
- Modify: `composer.json` (add `symfony/yaml`)

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `EaSeed\ManifestException extends \RuntimeException`
  - `EaSeed\Manifest::fromArray(array $raw, string $source = 'manifest'): self`
  - `EaSeed\Manifest::fromFile(string $path): self`
  - Public readonly properties: `string $product`, `string $baseUrl`, `array $admin` (keys `first_name`, `last_name`, `email`, `username`, `password`), `string $apiToken`, `array $settings`, `array $serviceCategories`, `array $services`, `array $providers`, `array $webhooks`.
  - Each service: `key`, `name`, `duration`, `attendants_number`, `category`, `price`, `currency`, `requires_approval`.
  - Each provider: `key`, `first_name`, `last_name`, `email`, `timezone`, `services` (list of service keys), `settings` with `username`, `password`, `working_plan` (map of weekday → sorted session list).
  - Each webhook: `name`, `url`, `actions` (list), `secret_token`, `is_ssl_verified`.

- [ ] **Step 1: Add the YAML dependency**

Run:

```bash
composer require symfony/yaml:^6.4
```

Verify it landed in `require`, not `require-dev` — the production image builds with `--no-dev`:

```bash
grep -A1 '"symfony/yaml"' composer.json
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/EaSeed/ManifestTest.php`:

```php
<?php

namespace Tests\Unit\EaSeed;

use EaSeed\Manifest;
use EaSeed\ManifestException;
use Tests\TestCase;

class ManifestTest extends TestCase
{
    private function valid(): array
    {
        return [
            'product' => 'zeekr-gold-coast',
            'base_url' => 'http://localhost:8080',
            'admin' => [
                'first_name' => 'Zeekr',
                'last_name' => 'Admin',
                'email' => 'admin@zeekrgc.com.au',
                'username' => 'zeekradmin',
                'password_env' => 'TEST_ADMIN_PASSWORD',
            ],
            'api_token_env' => 'TEST_API_KEY',
            'settings' => ['require_phone_number' => true],
            'service_categories' => [['name' => 'Sales']],
            'services' => [[
                'key' => 'TEST_DRIVE',
                'name' => 'Zeekr 7X Test Drive',
                'duration' => 60,
                'attendants_number' => 1,
                'category' => 'Sales',
            ]],
            'providers' => [[
                'key' => 'PRIMARY',
                'first_name' => 'Zeekr',
                'last_name' => 'Sales Team',
                'email' => 'sales@zeekrgc.com.au',
                'timezone' => 'Australia/Brisbane',
                'services' => ['TEST_DRIVE'],
                'settings' => [
                    'username' => 'zeekrsales',
                    'password_env' => 'TEST_PROVIDER_PASSWORD',
                    'working_plan' => [
                        'monday' => [
                            ['start' => '13:00', 'end' => '17:00'],
                            ['start' => '09:00', 'end' => '12:00'],
                        ],
                        'sunday' => [],
                    ],
                ],
            ]],
            'webhooks' => [[
                'name' => 'PWA reconcile',
                'url' => 'https://example.com/api/webhooks/easyappointments',
                'actions' => ['appointment_save'],
                'secret_token_env' => 'TEST_WEBHOOK_SECRET',
            ]],
        ];
    }

    protected function setUp(): void
    {
        putenv('TEST_ADMIN_PASSWORD=adminpw');
        putenv('TEST_PROVIDER_PASSWORD=providerpw');
        putenv('TEST_API_KEY=tok_abc');
        putenv('TEST_WEBHOOK_SECRET=whsec');
    }

    public function testParsesAValidManifest()
    {
        $m = Manifest::fromArray($this->valid());

        $this->assertSame('zeekr-gold-coast', $m->product);
        $this->assertSame('http://localhost:8080', $m->baseUrl);
        $this->assertSame('TEST_DRIVE', $m->services[0]['key']);
        $this->assertSame(1, $m->services[0]['attendants_number']);
    }

    public function testResolvesSecretsFromTheEnvironment()
    {
        $m = Manifest::fromArray($this->valid());

        $this->assertSame('tok_abc', $m->apiToken);
        $this->assertSame('adminpw', $m->admin['password']);
        $this->assertSame('providerpw', $m->providers[0]['settings']['password']);
        $this->assertSame('whsec', $m->webhooks[0]['secret_token']);
    }

    public function testDefaultsRequiresApprovalToFalse()
    {
        $m = Manifest::fromArray($this->valid());

        $this->assertFalse($m->services[0]['requires_approval']);
    }

    public function testSortsSessionsByStartTime()
    {
        $m = Manifest::fromArray($this->valid());
        $monday = $m->providers[0]['settings']['working_plan']['monday'];

        $this->assertSame('09:00', $monday[0]['start']);
        $this->assertSame('13:00', $monday[1]['start']);
    }

    public function testRejectsSessionEndingBeforeItStarts()
    {
        $raw = $this->valid();
        $raw['providers'][0]['settings']['working_plan']['monday'] = [
            ['start' => '19:00', 'end' => '02:00'],
        ];

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/cannot span midnight/');
        Manifest::fromArray($raw);
    }

    public function testRejectsSessionEndingAfterTwentyThreeFiftyNine()
    {
        $raw = $this->valid();
        $raw['providers'][0]['settings']['working_plan']['monday'] = [
            ['start' => '19:00', 'end' => '24:00'],
        ];

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/HH:MM/');
        Manifest::fromArray($raw);
    }

    public function testRejectsOverlappingSessions()
    {
        $raw = $this->valid();
        $raw['providers'][0]['settings']['working_plan']['monday'] = [
            ['start' => '09:00', 'end' => '13:00'],
            ['start' => '12:00', 'end' => '17:00'],
        ];

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/overlap/');
        Manifest::fromArray($raw);
    }

    public function testRejectsProviderReferencingUnknownServiceKey()
    {
        $raw = $this->valid();
        $raw['providers'][0]['services'] = ['TEST_DRIVE', 'NOPE'];

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/NOPE/');
        Manifest::fromArray($raw);
    }

    public function testRejectsDuplicateServiceKeys()
    {
        $raw = $this->valid();
        $raw['services'][] = $raw['services'][0];

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/[Dd]uplicate/');
        Manifest::fromArray($raw);
    }

    public function testRejectsUnsetEnvironmentReference()
    {
        putenv('TEST_API_KEY');
        $raw = $this->valid();

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/TEST_API_KEY/');
        Manifest::fromArray($raw);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `composer test -- --filter ManifestTest`
Expected: FAIL with `Class "EaSeed\Manifest" not found`.

- [ ] **Step 4: Write the exception class**

Create `scripts/ea-seed/src/ManifestException.php`:

```php
<?php

namespace EaSeed;

/** Raised when a manifest is malformed. Always thrown before any write occurs. */
final class ManifestException extends \RuntimeException
{
}
```

- [ ] **Step 5: Write the manifest implementation**

Create `scripts/ea-seed/src/Manifest.php`:

```php
<?php

namespace EaSeed;

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

        return self::fromArray(Yaml::parseFile($path) ?? [], $path);
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
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `composer test -- --filter ManifestTest`
Expected: PASS, 10 tests.

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock scripts/ea-seed/src/Manifest.php scripts/ea-seed/src/ManifestException.php tests/Unit/EaSeed/ManifestTest.php
git commit -m "feat: parse and validate vendor manifests with session-based working plans"
```

---

### Task 4: EA REST client

**Files:**
- Create: `scripts/ea-seed/src/EaHttpException.php`
- Create: `scripts/ea-seed/src/EaClient.php`
- Test: `tests/Unit/EaSeed/EaClientTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `EaSeed\EaHttpException extends \RuntimeException` with `public readonly int $status`.
  - `EaSeed\EaClient::__construct(string $baseUrl, string $apiToken, ?callable $transport = null)` — `$transport` has signature `fn(string $method, string $url, ?string $body, array $headers): array{status:int, body:string}` and exists so tests can drive the client without a server.
  - `get(string $path): array`, `post(string $path, array $body): array`, `put(string $path, array $body): array`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/EaSeed/EaClientTest.php`:

```php
<?php

namespace Tests\Unit\EaSeed;

use EaSeed\EaClient;
use EaSeed\EaHttpException;
use Tests\TestCase;

class EaClientTest extends TestCase
{
    public function testGetSendsBearerTokenAndDecodesJson()
    {
        $seen = [];
        $client = new EaClient('http://ea.test', 'tok_abc', function ($method, $url, $body, $headers) use (&$seen) {
            $seen = compact('method', 'url', 'body', 'headers');
            return ['status' => 200, 'body' => '[{"id":7,"name":"Test Drive"}]'];
        });

        $result = $client->get('/api/v1/services');

        $this->assertSame('GET', $seen['method']);
        $this->assertSame('http://ea.test/api/v1/services', $seen['url']);
        $this->assertContains('Authorization: Bearer tok_abc', $seen['headers']);
        $this->assertSame(7, $result[0]['id']);
    }

    public function testPostEncodesTheBody()
    {
        $seen = [];
        $client = new EaClient('http://ea.test', 'tok_abc', function ($method, $url, $body, $headers) use (&$seen) {
            $seen = compact('method', 'body');
            return ['status' => 201, 'body' => '{"id":9}'];
        });

        $result = $client->post('/api/v1/services', ['name' => 'Showroom Visit']);

        $this->assertSame('POST', $seen['method']);
        $this->assertSame('{"name":"Showroom Visit"}', $seen['body']);
        $this->assertSame(9, $result['id']);
    }

    public function testEmptyResponseBodyDecodesToEmptyArray()
    {
        $client = new EaClient('http://ea.test', 'tok_abc', fn() => ['status' => 204, 'body' => '']);

        $this->assertSame([], $client->put('/api/v1/settings/company_name', ['value' => 'X']));
    }

    public function testNonSuccessStatusThrowsWithStatusAndBody()
    {
        $client = new EaClient('http://ea.test', 'tok_abc', fn() => [
            'status' => 401,
            'body' => 'Unauthorized',
        ]);

        try {
            $client->get('/api/v1/services');
            $this->fail('Expected EaHttpException');
        } catch (EaHttpException $e) {
            $this->assertSame(401, $e->status);
            $this->assertStringContainsString('Unauthorized', $e->getMessage());
        }
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter EaClientTest`
Expected: FAIL with `Class "EaSeed\EaClient" not found`.

- [ ] **Step 3: Write the exception class**

Create `scripts/ea-seed/src/EaHttpException.php`:

```php
<?php

namespace EaSeed;

final class EaHttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
```

- [ ] **Step 4: Write the client**

Create `scripts/ea-seed/src/EaClient.php`:

```php
<?php

namespace EaSeed;

/**
 * Authenticated REST client for the EasyAppointments v1 API.
 *
 * The transport is injectable so the client can be exercised in unit tests
 * without a running server; production use falls through to cURL.
 */
final class EaClient
{
    /** @var callable(string,string,?string,array):array{status:int,body:string} */
    private $transport;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiToken,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? [$this, 'curlTransport'];
    }

    public function get(string $path): array
    {
        return $this->send('GET', $path, null);
    }

    public function post(string $path, array $body): array
    {
        return $this->send('POST', $path, $body);
    }

    public function put(string $path, array $body): array
    {
        return $this->send('PUT', $path, $body);
    }

    private function send(string $method, string $path, ?array $body): array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiToken,
        ];

        $encoded = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES);
        $url = rtrim($this->baseUrl, '/') . $path;

        $response = ($this->transport)($method, $url, $encoded, $headers);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new EaHttpException(
                $response['status'],
                "EA {$response['status']} on {$method} {$path}: {$response['body']}"
            );
        }

        if (trim($response['body']) === '') {
            return [];
        }

        $decoded = json_decode($response['body'], true);

        return is_array($decoded) ? $decoded : [];
    }

    private function curlTransport(string $method, string $url, ?string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);

        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new EaHttpException(0, "Could not reach $url: $error");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $responseBody];
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `composer test -- --filter EaClientTest`
Expected: PASS, 4 tests.

- [ ] **Step 6: Commit**

```bash
git add scripts/ea-seed/src/EaClient.php scripts/ea-seed/src/EaHttpException.php tests/Unit/EaSeed/EaClientTest.php
git commit -m "feat: add authenticated EasyAppointments REST client"
```

---

### Task 5: Phase 0 bootstrap

Writes the admin user and API token directly to MySQL, because the API token is a setting and cannot be set through the API that requires it.

**Files:**
- Create: `scripts/ea-seed/src/Bootstrap.php`
- Test: `tests/Unit/EaSeed/BootstrapTest.php`

**Interfaces:**
- Consumes: `EaSeed\Manifest`.
- Produces: `EaSeed\Bootstrap::__construct(\PDO $pdo, callable $hasher, string $prefix = 'ea_')` where `$hasher` has signature `fn(string $salt, string $password): string`; and `run(Manifest $m): array` returning a list of human-readable strings describing what it did.

The hasher is injected because production must use EA's own `hash_password()` (SHA-256, salted, 100K iterations) from `application/helpers/password_helper.php`, while tests need determinism without loading CodeIgniter.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/EaSeed/BootstrapTest.php`:

```php
<?php

namespace Tests\Unit\EaSeed;

use EaSeed\Bootstrap;
use EaSeed\Manifest;
use PDO;
use Tests\TestCase;

class BootstrapTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        putenv('BOOT_ADMIN_PASSWORD=adminpw');
        putenv('BOOT_PROVIDER_PASSWORD=providerpw');
        putenv('BOOT_API_KEY=tok_abc');
        putenv('BOOT_WEBHOOK_SECRET=whsec');

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE ea_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT, first_name TEXT, last_name TEXT,
            email TEXT, timezone TEXT, language TEXT, id_roles INTEGER
        )');
        $this->pdo->exec('CREATE TABLE ea_user_settings (
            id_users INTEGER PRIMARY KEY, username TEXT, password TEXT, salt TEXT,
            notifications INTEGER, calendar_view TEXT
        )');
        $this->pdo->exec('CREATE TABLE ea_roles (id INTEGER PRIMARY KEY, slug TEXT, is_admin INTEGER)');
        $this->pdo->exec("INSERT INTO ea_roles (id, slug, is_admin) VALUES (1, 'admin', 1)");
        $this->pdo->exec('CREATE TABLE ea_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, value TEXT)');
        // The migrations create this row; its name column is NOT unique.
        $this->pdo->exec("INSERT INTO ea_settings (name, value) VALUES ('api_token', '')");
    }

    private function manifest(): Manifest
    {
        return Manifest::fromArray([
            'product' => 'zeekr-gold-coast',
            'base_url' => 'http://localhost:8080',
            'admin' => [
                'first_name' => 'Zeekr', 'last_name' => 'Admin',
                'email' => 'admin@zeekrgc.com.au', 'username' => 'zeekradmin',
                'password_env' => 'BOOT_ADMIN_PASSWORD',
            ],
            'api_token_env' => 'BOOT_API_KEY',
        ]);
    }

    private function bootstrap(): Bootstrap
    {
        return new Bootstrap($this->pdo, fn(string $salt, string $pw) => 'hashed:' . $salt . ':' . $pw);
    }

    public function testCreatesTheAdminUserAndCredentials()
    {
        $this->bootstrap()->run($this->manifest());

        $user = $this->pdo->query('SELECT * FROM ea_users')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('admin@zeekrgc.com.au', $user['email']);
        $this->assertSame(1, (int) $user['id_roles']);

        $settings = $this->pdo->query('SELECT * FROM ea_user_settings')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('zeekradmin', $settings['username']);
        $this->assertStringStartsWith('hashed:', $settings['password']);
        $this->assertNotEmpty($settings['salt']);
    }

    public function testUpdatesTheExistingApiTokenRowRatherThanInsertingADuplicate()
    {
        $this->bootstrap()->run($this->manifest());

        $rows = $this->pdo->query("SELECT value FROM ea_settings WHERE name = 'api_token'")
            ->fetchAll(PDO::FETCH_COLUMN);

        $this->assertCount(1, $rows, 'api_token must not be duplicated — ea_settings.name is not unique');
        $this->assertSame('tok_abc', $rows[0]);
    }

    public function testInsertsTheApiTokenRowWhenAbsent()
    {
        $this->pdo->exec("DELETE FROM ea_settings WHERE name = 'api_token'");

        $this->bootstrap()->run($this->manifest());

        $rows = $this->pdo->query("SELECT value FROM ea_settings WHERE name = 'api_token'")
            ->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame(['tok_abc'], $rows);
    }

    public function testIsIdempotent()
    {
        $this->bootstrap()->run($this->manifest());
        $this->bootstrap()->run($this->manifest());

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ea_users')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM ea_settings WHERE name='api_token'")->fetchColumn());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter BootstrapTest`
Expected: FAIL with `Class "EaSeed\Bootstrap" not found`.

- [ ] **Step 3: Write the implementation**

Create `scripts/ea-seed/src/Bootstrap.php`:

```php
<?php

namespace EaSeed;

use PDO;

/**
 * Phase 0. Writes the admin account and API token straight to the database.
 *
 * This is the only part of the seeder that bypasses the REST API, and it has to:
 * the API authenticates against the `api_token` setting, so that setting cannot
 * itself be written through the API.
 */
final class Bootstrap
{
    /** @var callable(string,string):string */
    private $hasher;

    /**
     * @param callable(string $salt, string $password): string $hasher
     *        Must be EA's own hash_password() in production.
     */
    public function __construct(
        private readonly PDO $pdo,
        callable $hasher,
        private readonly string $prefix = 'ea_',
    ) {
        $this->hasher = $hasher;
    }

    /** @return list<string> Human-readable description of what changed. */
    public function run(Manifest $manifest): array
    {
        $changes = [];

        $this->pdo->beginTransaction();

        try {
            $userId = $this->upsertAdmin($manifest->admin, $changes);
            $this->upsertAdminCredentials($userId, $manifest->admin, $changes);
            $this->upsertSetting('api_token', $manifest->apiToken, $changes);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $changes;
    }

    private function upsertAdmin(array $admin, array &$changes): int
    {
        $roleId = $this->adminRoleId();

        $find = $this->pdo->prepare("SELECT id FROM {$this->prefix}users WHERE email = :email");
        $find->execute([':email' => $admin['email']]);
        $existing = $find->fetchColumn();

        if ($existing !== false) {
            $changes[] = "admin {$admin['email']} — already present";

            return (int) $existing;
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO {$this->prefix}users (first_name, last_name, email, timezone, language, id_roles)
             VALUES (:first, :last, :email, :tz, :lang, :role)"
        );
        $insert->execute([
            ':first' => $admin['first_name'],
            ':last' => $admin['last_name'],
            ':email' => $admin['email'],
            ':tz' => 'UTC',
            ':lang' => 'english',
            ':role' => $roleId,
        ]);

        $changes[] = "admin {$admin['email']} — created";

        return (int) $this->pdo->lastInsertId();
    }

    private function upsertAdminCredentials(int $userId, array $admin, array &$changes): void
    {
        $salt = bin2hex(random_bytes(16));
        $password = ($this->hasher)($salt, $admin['password']);

        $find = $this->pdo->prepare(
            "SELECT id_users FROM {$this->prefix}user_settings WHERE id_users = :id"
        );
        $find->execute([':id' => $userId]);

        if ($find->fetchColumn() !== false) {
            $update = $this->pdo->prepare(
                "UPDATE {$this->prefix}user_settings
                 SET username = :username, password = :password, salt = :salt
                 WHERE id_users = :id"
            );
            $update->execute([
                ':username' => $admin['username'],
                ':password' => $password,
                ':salt' => $salt,
                ':id' => $userId,
            ]);
            $changes[] = "admin credentials — refreshed";

            return;
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO {$this->prefix}user_settings
             (id_users, username, password, salt, notifications, calendar_view)
             VALUES (:id, :username, :password, :salt, 1, 'default')"
        );
        $insert->execute([
            ':id' => $userId,
            ':username' => $admin['username'],
            ':password' => $password,
            ':salt' => $salt,
        ]);

        $changes[] = 'admin credentials — created';
    }

    /**
     * ea_settings.name is NOT unique and the primary key is an auto-increment id,
     * so an existing row must be UPDATEd by name. INSERT ... ON DUPLICATE KEY UPDATE
     * would silently create a second row.
     */
    private function upsertSetting(string $name, string $value, array &$changes): void
    {
        $find = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->prefix}settings WHERE name = :name");
        $find->execute([':name' => $name]);

        if ((int) $find->fetchColumn() > 0) {
            $update = $this->pdo->prepare(
                "UPDATE {$this->prefix}settings SET value = :value WHERE name = :name"
            );
            $update->execute([':value' => $value, ':name' => $name]);
            $changes[] = "setting $name — updated";

            return;
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO {$this->prefix}settings (name, value) VALUES (:name, :value)"
        );
        $insert->execute([':name' => $name, ':value' => $value]);
        $changes[] = "setting $name — created";
    }

    private function adminRoleId(): int
    {
        $stmt = $this->pdo->query("SELECT id FROM {$this->prefix}roles WHERE slug = 'admin'");
        $id = $stmt->fetchColumn();

        if ($id === false) {
            throw new \RuntimeException(
                'No admin role found. Run the EasyAppointments migrations before seeding.'
            );
        }

        return (int) $id;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test -- --filter BootstrapTest`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add scripts/ea-seed/src/Bootstrap.php tests/Unit/EaSeed/BootstrapTest.php
git commit -m "feat: bootstrap admin user and API token over PDO"
```

---

### Task 6: Change record, ID registry, and settings convergence

**Files:**
- Create: `scripts/ea-seed/src/Change.php`
- Create: `scripts/ea-seed/src/Ids.php`
- Create: `scripts/ea-seed/src/Resources/SettingsResource.php`
- Test: `tests/Unit/EaSeed/Resources/SettingsResourceTest.php`

**Interfaces:**
- Consumes: `EaSeed\EaClient`, `EaSeed\Manifest`.
- Produces:
  - `EaSeed\Change::__construct(string $resource, string $label, string $action, ?int $id = null)` with readonly properties of the same names; `$action` is one of `create`, `update`, `noop`.
  - `EaSeed\Ids` with public arrays `$categories` (name→id), `$services` (key→id), `$providers` (key→id), `$webhooks` (name→id).
  - `EaSeed\Resources\SettingsResource::converge(EaClient $c, Manifest $m, Ids $ids, bool $dryRun): array` returning `list<Change>`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/EaSeed/Resources/SettingsResourceTest.php`:

```php
<?php

namespace Tests\Unit\EaSeed\Resources;

use EaSeed\EaClient;
use EaSeed\Ids;
use EaSeed\Manifest;
use EaSeed\Resources\SettingsResource;
use Tests\TestCase;

class SettingsResourceTest extends TestCase
{
    private array $calls = [];

    protected function setUp(): void
    {
        putenv('S_API_KEY=tok');
        putenv('S_ADMIN_PASSWORD=pw');
        $this->calls = [];
    }

    private function manifest(array $settings): Manifest
    {
        return Manifest::fromArray([
            'product' => 'p',
            'base_url' => 'http://ea.test',
            'admin' => [
                'first_name' => 'A', 'email' => 'a@example.com',
                'username' => 'a', 'password_env' => 'S_ADMIN_PASSWORD',
            ],
            'api_token_env' => 'S_API_KEY',
            'settings' => $settings,
        ]);
    }

    private function client(array $current): EaClient
    {
        return new EaClient('http://ea.test', 'tok', function ($method, $url, $body) use ($current) {
            $this->calls[] = [$method, $url, $body];

            if ($method === 'GET') {
                return ['status' => 200, 'body' => json_encode($current)];
            }

            return ['status' => 200, 'body' => ''];
        });
    }

    public function testWritesASettingWhoseValueDiffers()
    {
        $client = $this->client([['name' => 'require_phone_number', 'value' => '1']]);

        $changes = SettingsResource::converge(
            $client,
            $this->manifest(['require_phone_number' => false]),
            new Ids(),
            false
        );

        $this->assertSame('update', $changes[0]->action);
        $this->assertContains(
            ['PUT', 'http://ea.test/api/v1/settings/require_phone_number', '{"value":"0"}'],
            $this->calls
        );
    }

    public function testSkipsASettingAlreadyAtTheDesiredValue()
    {
        $client = $this->client([['name' => 'company_name', 'value' => 'Lostlocks']]);

        $changes = SettingsResource::converge(
            $client,
            $this->manifest(['company_name' => 'Lostlocks']),
            new Ids(),
            false
        );

        $this->assertSame('noop', $changes[0]->action);
        $this->assertCount(1, $this->calls, 'only the initial GET should have been issued');
    }

    public function testDryRunReportsWithoutWriting()
    {
        $client = $this->client([['name' => 'company_name', 'value' => 'Old']]);

        $changes = SettingsResource::converge(
            $client,
            $this->manifest(['company_name' => 'New']),
            new Ids(),
            true
        );

        $this->assertSame('update', $changes[0]->action);
        $this->assertCount(1, $this->calls, 'dry run must not issue a PUT');
    }

    public function testBooleansAreSentAsOneAndZero()
    {
        $client = $this->client([]);

        SettingsResource::converge(
            $client,
            $this->manifest(['require_phone_number' => true]),
            new Ids(),
            false
        );

        $this->assertContains(
            ['PUT', 'http://ea.test/api/v1/settings/require_phone_number', '{"value":"1"}'],
            $this->calls
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter SettingsResourceTest`
Expected: FAIL with `Class "EaSeed\Change" not found` or `"EaSeed\Ids" not found`.

- [ ] **Step 3: Write the Change and Ids value objects**

Create `scripts/ea-seed/src/Change.php`:

```php
<?php

namespace EaSeed;

/** One converged resource, reported by both `plan` and `apply`. */
final class Change
{
    public function __construct(
        public readonly string $resource,
        public readonly string $label,
        public readonly string $action, // create | update | noop
        public readonly ?int $id = null,
    ) {
    }

    public function isNoop(): bool
    {
        return $this->action === 'noop';
    }
}
```

Create `scripts/ea-seed/src/Ids.php`:

```php
<?php

namespace EaSeed;

/**
 * Numeric ids assigned by EasyAppointments, keyed by the manifest's stable keys.
 * Threaded through convergence so later resources can resolve references and the
 * env emitter can write the ids the storefronts read.
 */
final class Ids
{
    /** @var array<string,int> category name => id */
    public array $categories = [];

    /** @var array<string,int> service key => id */
    public array $services = [];

    /** @var array<string,int> provider key => id */
    public array $providers = [];

    /** @var array<string,int> webhook name => id */
    public array $webhooks = [];
}
```

- [ ] **Step 4: Write the settings resource**

Create `scripts/ea-seed/src/Resources/SettingsResource.php`:

```php
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

        $current = [];
        foreach ($client->get('/api/v1/settings') as $row) {
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
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `composer test -- --filter SettingsResourceTest`
Expected: PASS, 4 tests.

- [ ] **Step 6: Commit**

```bash
git add scripts/ea-seed/src/Change.php scripts/ea-seed/src/Ids.php scripts/ea-seed/src/Resources/SettingsResource.php tests/Unit/EaSeed/Resources/SettingsResourceTest.php
git commit -m "feat: converge manifest settings onto an EA instance"
```

---

### Task 7: Service category and service convergence

**Files:**
- Create: `scripts/ea-seed/src/Resources/ServicesResource.php`
- Test: `tests/Unit/EaSeed/Resources/ServicesResourceTest.php`

**Interfaces:**
- Consumes: `EaSeed\EaClient`, `EaSeed\Manifest`, `EaSeed\Ids`, `EaSeed\Change`.
- Produces: `EaSeed\Resources\ServicesResource::converge(EaClient $c, Manifest $m, Ids $ids, bool $dryRun): array` returning `list<Change>` and populating `$ids->categories` and `$ids->services`.

Matching is by `name` for both categories and services — EasyAppointments has no external-id column, so the display name is the natural key.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/EaSeed/Resources/ServicesResourceTest.php`:

```php
<?php

namespace Tests\Unit\EaSeed\Resources;

use EaSeed\EaClient;
use EaSeed\Ids;
use EaSeed\Manifest;
use EaSeed\Resources\ServicesResource;
use Tests\TestCase;

class ServicesResourceTest extends TestCase
{
    private array $calls = [];

    protected function setUp(): void
    {
        putenv('SV_API_KEY=tok');
        putenv('SV_ADMIN_PASSWORD=pw');
        $this->calls = [];
    }

    private function manifest(): Manifest
    {
        return Manifest::fromArray([
            'product' => 'p',
            'base_url' => 'http://ea.test',
            'admin' => [
                'first_name' => 'A', 'email' => 'a@example.com',
                'username' => 'a', 'password_env' => 'SV_ADMIN_PASSWORD',
            ],
            'api_token_env' => 'SV_API_KEY',
            'service_categories' => [['name' => 'Rooms']],
            'services' => [
                ['key' => 'MASTERMIND_HEIST', 'name' => 'Mastermind Heist', 'duration' => 60,
                 'attendants_number' => 8, 'category' => 'Rooms', 'requires_approval' => true],
                ['key' => 'ZODIAC_KILLER', 'name' => 'Zodiac Killer', 'duration' => 60,
                 'attendants_number' => 6, 'category' => 'Rooms'],
            ],
        ]);
    }

    /**
     * @param array $existingCategories rows returned by GET /service_categories
     * @param array $existingServices   rows returned by GET /services
     */
    private function client(array $existingCategories, array $existingServices): EaClient
    {
        $nextId = 100;

        return new EaClient('http://ea.test', 'tok',
            function ($method, $url, $body) use ($existingCategories, $existingServices, &$nextId) {
                $this->calls[] = [$method, $url, $body];

                if ($method === 'GET' && str_contains($url, 'service_categories')) {
                    return ['status' => 200, 'body' => json_encode($existingCategories)];
                }
                if ($method === 'GET') {
                    return ['status' => 200, 'body' => json_encode($existingServices)];
                }

                return ['status' => 200, 'body' => json_encode(['id' => $nextId++])];
            });
    }

    public function testCreatesCategoriesAndServicesAndRecordsTheirIds()
    {
        $ids = new Ids();

        $changes = ServicesResource::converge($this->client([], []), $this->manifest(), $ids, false);

        $this->assertSame(100, $ids->categories['Rooms']);
        $this->assertSame(101, $ids->services['MASTERMIND_HEIST']);
        $this->assertSame(102, $ids->services['ZODIAC_KILLER']);
        $this->assertSame('create', $changes[0]->action);
    }

    public function testReusesAnExistingCategoryByName()
    {
        $ids = new Ids();

        ServicesResource::converge(
            $this->client([['id' => 5, 'name' => 'Rooms']], []),
            $this->manifest(),
            $ids,
            false
        );

        $this->assertSame(5, $ids->categories['Rooms']);
    }

    public function testMatchesAnExistingServiceByNameAndSkipsWhenIdentical()
    {
        $ids = new Ids();
        $existing = [[
            'id' => 7, 'name' => 'Mastermind Heist', 'duration' => 60,
            'attendantsNumber' => 8, 'serviceCategoryId' => 5, 'price' => 0.0, 'currency' => '',
        ]];

        $changes = ServicesResource::converge(
            $this->client([['id' => 5, 'name' => 'Rooms']], $existing),
            $this->manifest(),
            $ids,
            false
        );

        $this->assertSame(7, $ids->services['MASTERMIND_HEIST']);

        $heist = array_values(array_filter(
            $changes,
            fn($c) => $c->label === 'Mastermind Heist'
        ))[0];
        $this->assertSame('noop', $heist->action);
    }

    public function testUpdatesAnExistingServiceWhoseDurationDiffers()
    {
        $ids = new Ids();
        $existing = [[
            'id' => 7, 'name' => 'Mastermind Heist', 'duration' => 45,
            'attendantsNumber' => 8, 'serviceCategoryId' => 5, 'price' => 0.0, 'currency' => '',
        ]];

        $changes = ServicesResource::converge(
            $this->client([['id' => 5, 'name' => 'Rooms']], $existing),
            $this->manifest(),
            $ids,
            false
        );

        $heist = array_values(array_filter($changes, fn($c) => $c->label === 'Mastermind Heist'))[0];
        $this->assertSame('update', $heist->action);

        $puts = array_filter($this->calls, fn($c) => $c[0] === 'PUT');
        $this->assertNotEmpty($puts);
    }

    public function testDryRunAssignsNoIdsAndIssuesNoWrites()
    {
        $ids = new Ids();

        $changes = ServicesResource::converge($this->client([], []), $this->manifest(), $ids, true);

        $this->assertSame([], $ids->services);
        $this->assertSame('create', $changes[0]->action);
        $this->assertEmpty(array_filter($this->calls, fn($c) => $c[0] !== 'GET'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter ServicesResourceTest`
Expected: FAIL with `Class "EaSeed\Resources\ServicesResource" not found`.

- [ ] **Step 3: Write the implementation**

Create `scripts/ea-seed/src/Resources/ServicesResource.php`:

```php
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
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test -- --filter ServicesResourceTest`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add scripts/ea-seed/src/Resources/ServicesResource.php tests/Unit/EaSeed/Resources/ServicesResourceTest.php
git commit -m "feat: converge service categories and services, recording assigned ids"
```

---

### Task 8: Provider convergence

**Files:**
- Create: `scripts/ea-seed/src/Resources/ProvidersResource.php`
- Test: `tests/Unit/EaSeed/Resources/ProvidersResourceTest.php`

**Interfaces:**
- Consumes: `EaSeed\EaClient`, `EaSeed\Manifest`, `EaSeed\Ids` (reads `$ids->services`), `EaSeed\WorkingPlanCompiler`.
- Produces: `EaSeed\Resources\ProvidersResource::converge(EaClient $c, Manifest $m, Ids $ids, bool $dryRun): array` returning `list<Change>` and populating `$ids->providers`.

Providers are matched by `email`. The working plan sent to EA is the compiled form from Task 2.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/EaSeed/Resources/ProvidersResourceTest.php`:

```php
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
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter ProvidersResourceTest`
Expected: FAIL with `Class "EaSeed\Resources\ProvidersResource" not found`.

- [ ] **Step 3: Write the implementation**

Create `scripts/ea-seed/src/Resources/ProvidersResource.php`:

```php
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
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test -- --filter ProvidersResourceTest`
Expected: PASS, 9 tests.

- [ ] **Step 5: Commit**

```bash
git add scripts/ea-seed/src/Resources/ProvidersResource.php tests/Unit/EaSeed/Resources/ProvidersResourceTest.php
git commit -m "feat: converge providers with compiled working plans and resolved service ids"
```

---

### Task 9: Webhook convergence

**Files:**
- Create: `scripts/ea-seed/src/Resources/WebhooksResource.php`
- Test: `tests/Unit/EaSeed/Resources/WebhooksResourceTest.php`

**Interfaces:**
- Consumes: `EaSeed\EaClient`, `EaSeed\Manifest`, `EaSeed\Ids`.
- Produces: `EaSeed\Resources\WebhooksResource::converge(EaClient $c, Manifest $m, Ids $ids, bool $dryRun): array` returning `list<Change>` and populating `$ids->webhooks`.

`actions` must be sent as a JSON-encoded **string**, not an array — the column is TEXT.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/EaSeed/Resources/WebhooksResourceTest.php`:

```php
<?php

namespace Tests\Unit\EaSeed\Resources;

use EaSeed\EaClient;
use EaSeed\Ids;
use EaSeed\Manifest;
use EaSeed\Resources\WebhooksResource;
use Tests\TestCase;

class WebhooksResourceTest extends TestCase
{
    private array $calls = [];

    protected function setUp(): void
    {
        putenv('WH_API_KEY=tok');
        putenv('WH_ADMIN_PASSWORD=pw');
        putenv('WH_SECRET=whsec');
        $this->calls = [];
    }

    private function manifest(): Manifest
    {
        return Manifest::fromArray([
            'product' => 'p',
            'base_url' => 'http://ea.test',
            'admin' => [
                'first_name' => 'A', 'email' => 'a@example.com',
                'username' => 'a', 'password_env' => 'WH_ADMIN_PASSWORD',
            ],
            'api_token_env' => 'WH_API_KEY',
            'webhooks' => [[
                'name' => 'PWA reconcile',
                'url' => 'https://lostlocks.test/api/webhooks/easyappointments',
                'actions' => ['appointment_save', 'appointment_delete'],
                'secret_token_env' => 'WH_SECRET',
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

            return ['status' => 200, 'body' => json_encode(['id' => 3])];
        });
    }

    public function testSendsActionsAsAJsonEncodedString()
    {
        WebhooksResource::converge($this->client([]), $this->manifest(), new Ids(), false);

        $post = array_values(array_filter($this->calls, fn($c) => $c[0] === 'POST'))[0];

        $this->assertIsString($post[2]['actions']);
        $this->assertSame('["appointment_save","appointment_delete"]', $post[2]['actions']);
    }

    public function testCreatesAndRecordsTheId()
    {
        $ids = new Ids();

        $changes = WebhooksResource::converge($this->client([]), $this->manifest(), $ids, false);

        $this->assertSame(3, $ids->webhooks['PWA reconcile']);
        $this->assertSame('create', $changes[0]->action);
    }

    public function testSkipsWhenTheExistingWebhookAlreadyMatches()
    {
        $existing = [[
            'id' => 3,
            'name' => 'PWA reconcile',
            'url' => 'https://lostlocks.test/api/webhooks/easyappointments',
            'actions' => '["appointment_save","appointment_delete"]',
        ]];

        $changes = WebhooksResource::converge($this->client($existing), $this->manifest(), new Ids(), false);

        $this->assertSame('noop', $changes[0]->action);
        $this->assertEmpty(array_filter($this->calls, fn($c) => $c[0] !== 'GET'));
    }

    public function testUpdatesWhenTheUrlDiffers()
    {
        $existing = [[
            'id' => 3,
            'name' => 'PWA reconcile',
            'url' => 'https://old.test/hook',
            'actions' => '["appointment_save","appointment_delete"]',
        ]];

        $changes = WebhooksResource::converge($this->client($existing), $this->manifest(), new Ids(), false);

        $this->assertSame('update', $changes[0]->action);
        $this->assertContains('PUT', array_column($this->calls, 0));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter WebhooksResourceTest`
Expected: FAIL with `Class "EaSeed\Resources\WebhooksResource" not found`.

- [ ] **Step 3: Write the implementation**

Create `scripts/ea-seed/src/Resources/WebhooksResource.php`:

```php
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

            if (!$dryRun) {
                $client->put('/api/v1/webhooks/' . $id, $payload);
            }

            $changes[] = new Change('webhook', $webhook['name'], 'update', $id);
        }

        return $changes;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test -- --filter WebhooksResourceTest`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add scripts/ea-seed/src/Resources/WebhooksResource.php tests/Unit/EaSeed/Resources/WebhooksResourceTest.php
git commit -m "feat: converge webhooks with JSON-string encoded actions"
```

---

### Task 10: Env emitter

Produces the storefront's config, including `EA_GATED_SERVICE_IDS` for vendors that need it.

**Files:**
- Create: `scripts/ea-seed/src/EnvEmitter.php`
- Test: `tests/Unit/EaSeed/EnvEmitterTest.php`

**Interfaces:**
- Consumes: `EaSeed\Manifest`, `EaSeed\Ids`.
- Produces: `EaSeed\EnvEmitter::render(Manifest $m, Ids $ids): string` — the full file contents, trailing newline included.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/EaSeed/EnvEmitterTest.php`:

```php
<?php

namespace Tests\Unit\EaSeed;

use EaSeed\EnvEmitter;
use EaSeed\Ids;
use EaSeed\Manifest;
use Tests\TestCase;

class EnvEmitterTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('EM_API_KEY=tok');
        putenv('EM_ADMIN_PASSWORD=pw');
        putenv('EM_PROVIDER_PASSWORD=ppw');
    }

    private function manifest(array $services): Manifest
    {
        return Manifest::fromArray([
            'product' => 'lostlocks',
            'base_url' => 'http://localhost:8081',
            'admin' => [
                'first_name' => 'A', 'email' => 'a@example.com',
                'username' => 'a', 'password_env' => 'EM_ADMIN_PASSWORD',
            ],
            'api_token_env' => 'EM_API_KEY',
            'settings' => ['default_timezone' => 'Australia/Brisbane'],
            'services' => $services,
            'providers' => [[
                'key' => 'PRIMARY', 'first_name' => 'GM', 'email' => 'gm@lostlocks.test',
                'services' => array_column($services, 'key'),
                'settings' => ['username' => 'gm', 'password_env' => 'EM_PROVIDER_PASSWORD'],
            ]],
        ]);
    }

    private function ids(array $serviceIds): Ids
    {
        $ids = new Ids();
        $ids->services = $serviceIds;
        $ids->providers = ['PRIMARY' => 2];

        return $ids;
    }

    public function testEmitsBaseUrlProviderAndServiceIds()
    {
        $out = EnvEmitter::render(
            $this->manifest([
                ['key' => 'MASTERMIND_HEIST', 'name' => 'Mastermind Heist', 'duration' => 60],
                ['key' => 'ZODIAC_KILLER', 'name' => 'Zodiac Killer', 'duration' => 60],
            ]),
            $this->ids(['MASTERMIND_HEIST' => 1, 'ZODIAC_KILLER' => 2])
        );

        $this->assertStringContainsString('EA_BASE_URL=http://localhost:8081', $out);
        $this->assertStringContainsString('EA_PROVIDER_ID=2', $out);
        $this->assertStringContainsString('EA_SERVICE_MASTERMIND_HEIST=1', $out);
        $this->assertStringContainsString('EA_SERVICE_ZODIAC_KILLER=2', $out);
    }

    public function testEmitsTimezoneFromSettings()
    {
        $out = EnvEmitter::render(
            $this->manifest([['key' => 'A', 'name' => 'A', 'duration' => 60]]),
            $this->ids(['A' => 1])
        );

        $this->assertStringContainsString('EA_TIMEZONE=Australia/Brisbane', $out);
    }

    public function testEmitsGatedServiceIdsForServicesRequiringApproval()
    {
        $out = EnvEmitter::render(
            $this->manifest([
                ['key' => 'MASTERMIND_HEIST', 'name' => 'Mastermind Heist', 'duration' => 60,
                 'requires_approval' => true],
                ['key' => 'ZODIAC_KILLER', 'name' => 'Zodiac Killer', 'duration' => 60],
                ['key' => 'ZOMBIE_LAB', 'name' => 'Zombie Lab', 'duration' => 60,
                 'requires_approval' => true],
            ]),
            $this->ids(['MASTERMIND_HEIST' => 1, 'ZODIAC_KILLER' => 2, 'ZOMBIE_LAB' => 4])
        );

        $this->assertStringContainsString('EA_GATED_SERVICE_IDS=1,4', $out);
    }

    public function testOmitsGatedServiceIdsWhenNoServiceRequiresApproval()
    {
        $out = EnvEmitter::render(
            $this->manifest([['key' => 'TEST_DRIVE', 'name' => 'Test Drive', 'duration' => 60]]),
            $this->ids(['TEST_DRIVE' => 7])
        );

        $this->assertStringNotContainsString('EA_GATED_SERVICE_IDS', $out);
    }

    public function testNeverEmitsSecrets()
    {
        $out = EnvEmitter::render(
            $this->manifest([['key' => 'A', 'name' => 'A', 'duration' => 60]]),
            $this->ids(['A' => 1])
        );

        $this->assertStringNotContainsString('tok', $out);
        $this->assertStringNotContainsString('EA_API_KEY=', $out);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter EnvEmitterTest`
Expected: FAIL with `Class "EaSeed\EnvEmitter" not found`.

- [ ] **Step 3: Write the implementation**

Create `scripts/ea-seed/src/EnvEmitter.php`:

```php
<?php

namespace EaSeed;

/**
 * Renders the storefront's environment file from the ids EasyAppointments assigned.
 *
 * Only ids and public values are emitted. EA_API_KEY and EA_WEBHOOK_SECRET are
 * inputs to the seeder, resolved from the environment, and are never written here.
 */
final class EnvEmitter
{
    public static function render(Manifest $manifest, Ids $ids): string
    {
        $lines = [
            '# Generated by ea-seed for ' . $manifest->product . '. Do not edit by hand.',
            '# Re-generate with: ea-seed env verticals/' . $manifest->product . '.yml',
            '',
            'EA_BASE_URL=' . $manifest->baseUrl,
        ];

        foreach ($manifest->providers as $provider) {
            if (!isset($ids->providers[$provider['key']])) {
                continue;
            }

            $suffix = $provider['key'] === 'PRIMARY' ? '' : '_' . $provider['key'];
            $lines[] = 'EA_PROVIDER_ID' . $suffix . '=' . $ids->providers[$provider['key']];
        }

        $gated = [];

        foreach ($manifest->services as $service) {
            if (!isset($ids->services[$service['key']])) {
                continue;
            }

            $id = $ids->services[$service['key']];
            $lines[] = 'EA_SERVICE_' . $service['key'] . '=' . $id;

            if ($service['requires_approval']) {
                $gated[] = $id;
            }
        }

        if ($gated !== []) {
            sort($gated);
            $lines[] = 'EA_GATED_SERVICE_IDS=' . implode(',', $gated);
        }

        if (isset($manifest->settings['default_timezone'])) {
            $lines[] = 'EA_TIMEZONE=' . $manifest->settings['default_timezone'];
        }

        return implode("\n", $lines) . "\n";
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test -- --filter EnvEmitterTest`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add scripts/ea-seed/src/EnvEmitter.php tests/Unit/EaSeed/EnvEmitterTest.php
git commit -m "feat: emit storefront env files including gated service ids"
```

---

### Task 11: Converger and CLI

**Files:**
- Create: `scripts/ea-seed/src/Converger.php`
- Create: `scripts/ea-seed.php`
- Test: `tests/Unit/EaSeed/ConvergerTest.php`
- Modify: `.gitignore`

**Interfaces:**
- Consumes: everything from Tasks 2–10.
- Produces:
  - `EaSeed\Converger::__construct(EaClient $client)` and `run(Manifest $m, bool $dryRun): array` returning `['changes' => list<Change>, 'ids' => Ids]`.
  - CLI: `php scripts/ea-seed.php {plan|apply|env} <manifest.yml>`, exit `0` on success and `1` on failure.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/EaSeed/ConvergerTest.php`:

```php
<?php

namespace Tests\Unit\EaSeed;

use EaSeed\Converger;
use EaSeed\EaClient;
use EaSeed\Manifest;
use Tests\TestCase;

class ConvergerTest extends TestCase
{
    private array $order = [];

    protected function setUp(): void
    {
        putenv('CV_API_KEY=tok');
        putenv('CV_ADMIN_PASSWORD=pw');
        putenv('CV_PROVIDER_PASSWORD=ppw');
        putenv('CV_SECRET=whsec');
        $this->order = [];
    }

    private function manifest(): Manifest
    {
        return Manifest::fromArray([
            'product' => 'lostlocks',
            'base_url' => 'http://ea.test',
            'admin' => [
                'first_name' => 'A', 'email' => 'a@example.com',
                'username' => 'a', 'password_env' => 'CV_ADMIN_PASSWORD',
            ],
            'api_token_env' => 'CV_API_KEY',
            'settings' => ['require_phone_number' => false],
            'service_categories' => [['name' => 'Rooms']],
            'services' => [
                ['key' => 'HEIST', 'name' => 'Heist', 'duration' => 60, 'category' => 'Rooms'],
            ],
            'providers' => [[
                'key' => 'PRIMARY', 'first_name' => 'GM', 'email' => 'gm@lostlocks.test',
                'services' => ['HEIST'],
                'settings' => [
                    'username' => 'gm', 'password_env' => 'CV_PROVIDER_PASSWORD',
                    'working_plan' => ['monday' => [['start' => '19:30', 'end' => '23:59']]],
                ],
            ]],
            'webhooks' => [[
                'name' => 'PWA reconcile', 'url' => 'https://lostlocks.test/hook',
                'actions' => ['appointment_save'], 'secret_token_env' => 'CV_SECRET',
            ]],
        ]);
    }

    private function client(): EaClient
    {
        $nextId = 10;

        return new EaClient('http://ea.test', 'tok', function ($method, $url) use (&$nextId) {
            if ($method === 'GET') {
                foreach (['settings', 'service_categories', 'services', 'providers', 'webhooks'] as $r) {
                    if (str_contains($url, $r)) {
                        $this->order[] = 'GET ' . $r;
                        break;
                    }
                }

                return ['status' => 200, 'body' => '[]'];
            }

            return ['status' => 200, 'body' => json_encode(['id' => $nextId++])];
        });
    }

    public function testConvergesInDependencyOrder()
    {
        (new Converger($this->client()))->run($this->manifest(), false);

        $this->assertSame(
            ['GET settings', 'GET service_categories', 'GET services', 'GET providers', 'GET webhooks'],
            $this->order
        );
    }

    public function testReturnsChangesAndPopulatedIds()
    {
        $result = (new Converger($this->client()))->run($this->manifest(), false);

        $this->assertNotEmpty($result['changes']);
        $this->assertArrayHasKey('HEIST', $result['ids']->services);
        $this->assertArrayHasKey('PRIMARY', $result['ids']->providers);
    }

    public function testDryRunStillReportsPlannedChanges()
    {
        $result = (new Converger($this->client()))->run($this->manifest(), true);

        $creates = array_filter($result['changes'], fn($c) => $c->action === 'create');
        $this->assertNotEmpty($creates);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter ConvergerTest`
Expected: FAIL with `Class "EaSeed\Converger" not found`.

- [ ] **Step 3: Write the Converger**

Create `scripts/ea-seed/src/Converger.php`:

```php
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
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test -- --filter ConvergerTest`
Expected: PASS, 3 tests.

- [ ] **Step 5: Write the CLI entrypoint**

Create `scripts/ea-seed.php`:

```php
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
```

- [ ] **Step 6: Ignore emitted env files**

Append to `.gitignore`:

```
# Emitted by ea-seed — contains instance ids, regenerate with `ea-seed env`
.env.*
!.env.example
```

- [ ] **Step 7: Verify the CLI rejects a bad invocation**

Run:

```bash
php scripts/ea-seed.php; echo "exit=$?"
```

Expected: usage message on stderr, `exit=1`.

- [ ] **Step 8: Commit**

```bash
git add scripts/ea-seed.php scripts/ea-seed/src/Converger.php tests/Unit/EaSeed/ConvergerTest.php .gitignore
git commit -m "feat: add ea-seed CLI with plan, apply and env commands"
```

---

### Task 12: Vendor manifests and acceptance

Proves the six success criteria against the two real storefronts.

**Files:**
- Create: `verticals/zeekr-gold-coast.local.yml`
- Create: `verticals/lostlocks.local.yml`
- Create: `scripts/ea-seed-acceptance.sh`

**Interfaces:**
- Consumes: the CLI from Task 11 and the harness from Task 1.
- Produces: `.env.zeekr-gold-coast` and `.env.lostlocks`; a repeatable acceptance script.

- [ ] **Step 1: Write the Zeekr manifest**

Create `verticals/zeekr-gold-coast.local.yml`:

```yaml
# Vendor A — Zeekr Gold Coast (EV dealership).
# Consumed by ~/PitchTalks/pt-selfsell-zeekr-gold-coast-app
product: zeekr-gold-coast
base_url: http://localhost:8080

admin:
  first_name: Zeekr
  last_name: Admin
  email: admin@zeekrgc.local
  username: zeekradmin
  password_env: ZEEKR_ADMIN_PASSWORD
api_token_env: ZEEKR_API_KEY

settings:
  company_name: Zeekr Gold Coast
  company_email: bookings@zeekrgc.local
  company_link: https://zeekrgc.local
  default_timezone: Australia/Brisbane
  require_phone_number: true
  require_first_name: true
  require_last_name: true
  display_notes: true

service_categories:
  - name: Sales

services:
  - key: TEST_DRIVE
    name: Zeekr 7X Test Drive
    duration: 60
    attendants_number: 1
    category: Sales
    price: 0
    currency: AUD
  - key: SHOWROOM
    name: Showroom Visit
    duration: 30
    attendants_number: 6
    category: Sales
  - key: FINANCE
    name: Finance Consultation
    duration: 45
    attendants_number: 2
    category: Sales
  - key: TRADEIN
    name: Trade-in Appraisal
    duration: 30
    attendants_number: 2
    category: Sales

providers:
  - key: PRIMARY
    first_name: Zeekr
    last_name: Sales Team
    email: sales@zeekrgc.local
    timezone: Australia/Brisbane
    services: [TEST_DRIVE, SHOWROOM, FINANCE, TRADEIN]
    settings:
      username: zeekrsales
      password_env: ZEEKR_PROVIDER_PASSWORD
      working_plan:
        monday:    [{ start: "09:00", end: "12:00" }, { start: "13:00", end: "17:00" }]
        tuesday:   [{ start: "09:00", end: "12:00" }, { start: "13:00", end: "17:00" }]
        wednesday: [{ start: "09:00", end: "12:00" }, { start: "13:00", end: "17:00" }]
        thursday:  [{ start: "09:00", end: "12:00" }, { start: "13:00", end: "17:00" }]
        friday:    [{ start: "09:00", end: "12:00" }, { start: "13:00", end: "17:00" }]
        saturday:  [{ start: "09:00", end: "16:00" }]
        sunday:    []

webhooks:
  - name: PWA reconcile
    url: http://localhost:3000/api/webhooks/easyappointments
    actions: [appointment_save, appointment_delete]
    secret_token_env: ZEEKR_WEBHOOK_SECRET
    is_ssl_verified: false
```

- [ ] **Step 2: Write the Lostlocks manifest**

Create `verticals/lostlocks.local.yml`:

```yaml
# Vendor B — Lostlocks (escape rooms).
# Consumed by ~/PitchTalks/Verticals/escape-rooms/lostlocks
#
# Note the 00:00 session on every day: Mastermind Heist runs a midnight slot
# (app/api/slots/[roomId]/route.ts:15). Sessions compile to a 00:00-23:59 plan
# with the closed middle of the day carved out as a break.
product: lostlocks
base_url: http://localhost:8081

admin:
  first_name: Lostlocks
  last_name: Admin
  email: admin@lostlocks.local
  username: lostlocksadmin
  password_env: LOSTLOCKS_ADMIN_PASSWORD
api_token_env: LOSTLOCKS_API_KEY

settings:
  company_name: Lostlocks
  company_email: bookings@lostlocks.local
  company_link: https://lostlocks.local
  default_timezone: Australia/Brisbane
  require_phone_number: false
  require_first_name: true
  require_last_name: true
  display_notes: true

service_categories:
  - name: Rooms

services:
  - key: MASTERMIND_HEIST
    name: Mastermind Heist
    duration: 60
    attendants_number: 8
    category: Rooms
    requires_approval: true
  - key: ZODIAC_KILLER
    name: Zodiac Killer
    duration: 60
    attendants_number: 6
    category: Rooms
  - key: PRISON_BREAK
    name: Prison Break
    duration: 60
    attendants_number: 6
    category: Rooms
  - key: ZOMBIE_LAB
    name: Zombie Lab
    duration: 60
    attendants_number: 8
    category: Rooms
    requires_approval: true

providers:
  - key: PRIMARY
    first_name: Lostlocks
    last_name: Game Masters
    email: gm@lostlocks.local
    timezone: Australia/Brisbane
    services: [MASTERMIND_HEIST, ZODIAC_KILLER, PRISON_BREAK, ZOMBIE_LAB]
    settings:
      username: lostlocksgm
      password_env: LOSTLOCKS_PROVIDER_PASSWORD
      working_plan:
        monday:    [{ start: "00:00", end: "01:00" }, { start: "19:30", end: "23:59" }]
        tuesday:   [{ start: "00:00", end: "01:00" }, { start: "19:30", end: "23:59" }]
        wednesday: [{ start: "00:00", end: "01:00" }, { start: "19:30", end: "23:59" }]
        thursday:  [{ start: "00:00", end: "01:00" }, { start: "19:30", end: "23:59" }]
        friday:    [{ start: "00:00", end: "01:00" }, { start: "19:30", end: "23:59" }]
        saturday:  [{ start: "00:00", end: "01:00" }, { start: "19:30", end: "23:59" }]
        sunday:    [{ start: "00:00", end: "01:00" }, { start: "19:30", end: "23:59" }]

webhooks:
  - name: PWA reconcile
    url: http://localhost:3001/api/webhooks/easyappointments
    actions: [appointment_save, appointment_delete]
    secret_token_env: LOSTLOCKS_WEBHOOK_SECRET
    is_ssl_verified: false
```

- [ ] **Step 3: Write the acceptance script**

Create `scripts/ea-seed-acceptance.sh`:

```bash
#!/bin/bash
# Acceptance for the six success criteria in
# docs/superpowers/specs/2026-08-03-vertical-seeding-and-infra-tuning-design.md
#
# Assumes the harness is already up:
#   docker compose -f docker-compose.local.yml up --build -d
#
# Usage: ./scripts/ea-seed-acceptance.sh

set -euo pipefail

COMPOSE="docker compose -f docker-compose.local.yml"
FAIL=0

pass() { echo "  PASS  $1"; }
fail() { echo "  FAIL  $1"; FAIL=1; }

export ZEEKR_ADMIN_PASSWORD=zeekr-admin-pw
export ZEEKR_PROVIDER_PASSWORD=zeekr-provider-pw
export ZEEKR_API_KEY=zeekr-local-token
export ZEEKR_WEBHOOK_SECRET=zeekr-webhook-secret
export LOSTLOCKS_ADMIN_PASSWORD=lostlocks-admin-pw
export LOSTLOCKS_PROVIDER_PASSWORD=lostlocks-provider-pw
export LOSTLOCKS_API_KEY=lostlocks-local-token
export LOSTLOCKS_WEBHOOK_SECRET=lostlocks-webhook-secret

seed() { # seed <service> <manifest> <command>
    $COMPOSE exec -T \
        -e ZEEKR_ADMIN_PASSWORD -e ZEEKR_PROVIDER_PASSWORD -e ZEEKR_API_KEY -e ZEEKR_WEBHOOK_SECRET \
        -e LOSTLOCKS_ADMIN_PASSWORD -e LOSTLOCKS_PROVIDER_PASSWORD -e LOSTLOCKS_API_KEY -e LOSTLOCKS_WEBHOOK_SECRET \
        "$1" php /var/www/html/scripts/ea-seed.php "$3" "/var/www/html/$2"
}

echo "== Seeding both vendors =="
seed ea-zeekr-gold-coast verticals/zeekr-gold-coast.local.yml apply
seed ea-lostlocks        verticals/lostlocks.local.yml        apply

echo
echo "== Criterion 2: emitted env satisfies each storefront's contract =="
ZEEKR_ENV=$($COMPOSE exec -T ea-zeekr-gold-coast cat /var/www/html/.env.zeekr-gold-coast)
LOST_ENV=$($COMPOSE exec -T ea-lostlocks cat /var/www/html/.env.lostlocks)

for v in EA_BASE_URL EA_PROVIDER_ID EA_SERVICE_TEST_DRIVE EA_SERVICE_SHOWROOM \
         EA_SERVICE_FINANCE EA_SERVICE_TRADEIN EA_TIMEZONE; do
    grep -q "^$v=" <<<"$ZEEKR_ENV" && pass "zeekr $v" || fail "zeekr $v missing"
done

for v in EA_BASE_URL EA_PROVIDER_ID EA_SERVICE_MASTERMIND_HEIST EA_SERVICE_ZODIAC_KILLER \
         EA_SERVICE_PRISON_BREAK EA_SERVICE_ZOMBIE_LAB; do
    grep -q "^$v=" <<<"$LOST_ENV" && pass "lostlocks $v" || fail "lostlocks $v missing"
done

echo
echo "== Criterion 3: conflicting settings coexist =="
Z_PHONE=$(curl -s -H "Authorization: Bearer $ZEEKR_API_KEY" \
    http://localhost:8080/api/v1/settings/require_phone_number | grep -o '"value":"[^"]*"' | cut -d'"' -f4)
L_PHONE=$(curl -s -H "Authorization: Bearer $LOSTLOCKS_API_KEY" \
    http://localhost:8081/api/v1/settings/require_phone_number | grep -o '"value":"[^"]*"' | cut -d'"' -f4)

[ "$Z_PHONE" = "1" ] && pass "zeekr require_phone_number=1" || fail "zeekr require_phone_number=$Z_PHONE"
[ "$L_PHONE" = "0" ] && pass "lostlocks require_phone_number=0" || fail "lostlocks require_phone_number=$L_PHONE"

echo
echo "== Criterion 4: Lostlocks midnight slot is bookable =="
HEIST_ID=$(grep '^EA_SERVICE_MASTERMIND_HEIST=' <<<"$LOST_ENV" | cut -d= -f2)
PROVIDER_ID=$(grep '^EA_PROVIDER_ID=' <<<"$LOST_ENV" | cut -d= -f2)
TOMORROW=$(date -v+1d +%Y-%m-%d 2>/dev/null || date -d '+1 day' +%Y-%m-%d)

AVAIL=$(curl -s -H "Authorization: Bearer $LOSTLOCKS_API_KEY" \
    "http://localhost:8081/api/v1/availabilities?serviceId=$HEIST_ID&providerId=$PROVIDER_ID&date=$TOMORROW")

grep -q '"00:00"' <<<"$AVAIL" && pass "00:00 available for Mastermind Heist" \
    || fail "00:00 not in availabilities: $AVAIL"

echo
echo "== Criterion 5: gated service ids =="
GATED=$(grep '^EA_GATED_SERVICE_IDS=' <<<"$LOST_ENV" | cut -d= -f2 || true)
ZOMBIE_ID=$(grep '^EA_SERVICE_ZOMBIE_LAB=' <<<"$LOST_ENV" | cut -d= -f2)
EXPECTED=$(printf '%s\n%s\n' "$HEIST_ID" "$ZOMBIE_ID" | sort -n | paste -sd, -)

[ "$GATED" = "$EXPECTED" ] && pass "lostlocks EA_GATED_SERVICE_IDS=$GATED" \
    || fail "lostlocks gated ids were '$GATED', expected '$EXPECTED'"
grep -q 'EA_GATED_SERVICE_IDS' <<<"$ZEEKR_ENV" \
    && fail "zeekr should not emit EA_GATED_SERVICE_IDS" \
    || pass "zeekr omits EA_GATED_SERVICE_IDS"

echo
echo "== Criterion 6: re-running changes nothing =="
RERUN=$(seed ea-lostlocks verticals/lostlocks.local.yml apply)
grep -q '0 created, 0 updated' <<<"$RERUN" && pass "lostlocks re-apply is a no-op" \
    || fail "lostlocks re-apply reported changes: $(grep -E '[0-9]+ created' <<<"$RERUN")"

LOST_ENV_2=$($COMPOSE exec -T ea-lostlocks cat /var/www/html/.env.lostlocks)
[ "$LOST_ENV" = "$LOST_ENV_2" ] && pass "emitted env is byte-identical" || fail "emitted env changed"

echo
[ "$FAIL" -eq 0 ] && echo "ALL CRITERIA PASSED" || echo "ACCEPTANCE FAILED"
exit "$FAIL"
```

Make it executable:

```bash
chmod +x scripts/ea-seed-acceptance.sh
```

- [ ] **Step 4: Run acceptance**

Run:

```bash
docker compose -f docker-compose.local.yml down -v
docker compose -f docker-compose.local.yml up --build -d
sleep 45
./scripts/ea-seed-acceptance.sh
```

Expected: `ALL CRITERIA PASSED`, exit 0.

If criterion 4 fails, inspect the stored plan — the compiled break for that weekday should be `01:00`→`19:30`:

```bash
docker compose -f docker-compose.local.yml exec -T mysql \
  mysql -uroot -prootpass ea_lostlocks -sN \
  -e "SELECT working_plan FROM ea_user_settings WHERE username='lostlocksgm';"
```

- [ ] **Step 5: Run the full unit suite**

Run: `composer test`
Expected: PASS, all suites green.

- [ ] **Step 6: Commit**

```bash
git add verticals/ scripts/ea-seed-acceptance.sh
git commit -m "feat: add Zeekr and Lostlocks manifests with acceptance for all six criteria"
```

---

### Task 13: Retrofit `start-local.sh`

Replaces the hand-written seeding SQL with a call to the seeder, and makes one command bring up both vendors.

**Files:**
- Modify: `scripts/start-local.sh` (full rewrite)

**Interfaces:**
- Consumes: the harness, the CLI, and both manifests.
- Produces: `./scripts/start-local.sh [--rebuild] [--reset]` bringing the stack up and seeding both vendors.

- [ ] **Step 1: Rewrite the script**

Replace `scripts/start-local.sh` entirely:

```bash
#!/bin/bash
# =============================================================================
# EasyAppointments Local Multi-Vendor Startup
#
# Brings up the two-vendor harness and seeds each instance from its manifest.
# Idempotent — safe to run repeatedly; seeding converges rather than recreates.
#
# Usage:
#   ./scripts/start-local.sh              # start + seed
#   ./scripts/start-local.sh --rebuild    # force image rebuild, then start + seed
#   ./scripts/start-local.sh --reset      # wipe volumes, rebuild, start + seed
#
# Prerequisites: Docker Desktop running
# =============================================================================

set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE="docker compose -f docker-compose.local.yml"

# Local-only credentials. Real deployments resolve these from Secret Manager.
export ZEEKR_ADMIN_PASSWORD=zeekr-admin-pw
export ZEEKR_PROVIDER_PASSWORD=zeekr-provider-pw
export ZEEKR_API_KEY=zeekr-local-token
export ZEEKR_WEBHOOK_SECRET=zeekr-webhook-secret
export LOSTLOCKS_ADMIN_PASSWORD=lostlocks-admin-pw
export LOSTLOCKS_PROVIDER_PASSWORD=lostlocks-provider-pw
export LOSTLOCKS_API_KEY=lostlocks-local-token
export LOSTLOCKS_WEBHOOK_SECRET=lostlocks-webhook-secret

REBUILD=""
for arg in "$@"; do
    case "$arg" in
        --reset)   echo "Wiping volumes..."; $COMPOSE down -v; REBUILD="--build" ;;
        --rebuild) REBUILD="--build" ;;
        *)         echo "Unknown flag: $arg"; exit 1 ;;
    esac
done

echo "Starting stack..."
$COMPOSE up -d $REBUILD

wait_for_http() { # wait_for_http <name> <url>
    printf "Waiting for %s" "$1"
    for _ in $(seq 1 60); do
        if curl -sf -o /dev/null "$2"; then echo " ready"; return 0; fi
        printf "."
        sleep 2
    done
    echo " TIMEOUT"
    return 1
}

wait_for_http "zeekr-gold-coast" "http://localhost:8080/"
wait_for_http "lostlocks" "http://localhost:8081/"

seed() { # seed <service> <manifest>
    echo
    echo "Seeding $1..."
    $COMPOSE exec -T \
        -e ZEEKR_ADMIN_PASSWORD -e ZEEKR_PROVIDER_PASSWORD -e ZEEKR_API_KEY -e ZEEKR_WEBHOOK_SECRET \
        -e LOSTLOCKS_ADMIN_PASSWORD -e LOSTLOCKS_PROVIDER_PASSWORD -e LOSTLOCKS_API_KEY -e LOSTLOCKS_WEBHOOK_SECRET \
        "$1" php /var/www/html/scripts/ea-seed.php apply "/var/www/html/$2"
}

seed ea-zeekr-gold-coast verticals/zeekr-gold-coast.local.yml
seed ea-lostlocks        verticals/lostlocks.local.yml

echo
echo "Copying emitted env files out of the containers..."
$COMPOSE exec -T ea-zeekr-gold-coast cat /var/www/html/.env.zeekr-gold-coast > .env.zeekr-gold-coast
$COMPOSE exec -T ea-lostlocks        cat /var/www/html/.env.lostlocks        > .env.lostlocks

cat <<EOF

Ready.

  Zeekr Gold Coast   http://localhost:8080   (admin: zeekradmin / $ZEEKR_ADMIN_PASSWORD)
  Lostlocks          http://localhost:8081   (admin: lostlocksadmin / $LOSTLOCKS_ADMIN_PASSWORD)

Storefront config written to .env.zeekr-gold-coast and .env.lostlocks
EOF
```

- [ ] **Step 2: Verify a clean run from scratch**

Run:

```bash
./scripts/start-local.sh --reset
```

Expected: both instances reported ready, both seedings print `N created, 0 updated, 0 unchanged`, and both `.env.*` files appear in the repo root.

- [ ] **Step 3: Verify the second run is a no-op**

Run:

```bash
./scripts/start-local.sh
```

Expected: both seedings print `0 created, 0 updated, N unchanged`.

- [ ] **Step 4: Confirm acceptance still passes**

Run: `./scripts/ea-seed-acceptance.sh`
Expected: `ALL CRITERIA PASSED`.

- [ ] **Step 5: Commit**

```bash
git add scripts/start-local.sh
git commit -m "refactor: seed local vendors through ea-seed instead of hand-written SQL"
```

---

## Self-Review Notes

**Spec coverage.** All six success criteria map to Task 12's acceptance script (criteria 1–6) plus unit coverage in Tasks 2, 3, 8 and 10. The two-phase architecture is Tasks 5 and 11; manifest schema is Task 3; idempotency is Tasks 6–9 and criterion 6; the local harness is Task 1; error handling is Task 11's CLI. All six known traps from the spec are encoded: `ea_settings` non-unique name (Task 5), webhook actions as JSON string (Task 9), `password_helper` hashing (Tasks 5 and 11), row-presence installed detection (Task 5's upsert-by-email), no HTTP installation flow (Task 5 is pure SQL), day-bounded working plans (Tasks 2 and 3).

**Not covered by this plan.** Workstream A (infra right-sizing and the VPC connector question) is deliberately excluded — it is independent, touches only Terraform, and ships as its own PR. The deferred trimmed GCP tier is likewise out of scope.

**Type consistency.** `converge(EaClient, Manifest, Ids, bool): list<Change>` is identical across all four resource classes, so `Converger` can iterate them uniformly. `Ids` keys are consistent throughout: services and providers by manifest `key`, categories and webhooks by `name`.

**Fixed during review.** `ProvidersResource` originally always issued a PUT for an existing provider, which contradicted Task 12's criterion-6 assertion of `0 updated` on re-apply. It now fetches `/api/v1/providers?with=settings` and diffs the compiled working plan, service list, and core fields. Password is omitted from update payloads — it is write-only and cannot be compared, and omitting it also avoids resetting a live provider's credentials on every apply.

**Watch during execution.** The provider comparison uses `==` for the working plan array so key ordering does not cause false diffs. If EA returns times in a different form than sent (for example `09:00:00` rather than `09:00`), `testSkipsAProviderAlreadyMatchingTheManifest` will pass in unit tests but criterion 6 will fail against the live harness in Task 12. If that happens, normalise both sides to `H:i` inside `matches()` before comparing — do not weaken the assertion.
