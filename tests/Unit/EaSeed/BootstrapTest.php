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
