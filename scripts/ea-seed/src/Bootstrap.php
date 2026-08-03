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

    /**
     * Write-on-insert, skip-on-update — the same convention ProvidersResource
     * uses for the password field. A vendor admin who changes their password
     * in the EA UI must not have it silently reverted (and their session
     * killed) on the next apply.
     */
    private function upsertAdminCredentials(int $userId, array $admin, array &$changes): void
    {
        $find = $this->pdo->prepare(
            "SELECT id_users FROM {$this->prefix}user_settings WHERE id_users = :id"
        );
        $find->execute([':id' => $userId]);

        if ($find->fetchColumn() !== false) {
            $changes[] = 'admin credentials — unchanged';

            return;
        }

        $salt = bin2hex(random_bytes(16));
        $password = ($this->hasher)($salt, $admin['password']);

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
