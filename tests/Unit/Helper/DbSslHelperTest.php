<?php

namespace Tests\Unit\Helper;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once APPPATH . 'helpers/db_ssl_helper.php';

/**
 * Plain PHPUnit case: the helper is pure and must not need a booted framework.
 */
class DbSslHelperTest extends TestCase {
    private const PEM = "-----BEGIN CERTIFICATE-----\nMIIBszCCAVmgAwIBAgIUdGVzdA==\n-----END CERTIFICATE-----\n";

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ea-db-ssl-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function test_disabled_returns_false(): void
    {
        $this->assertFalse(ea_db_ssl_config([], $this->dir));
        $this->assertFalse(ea_db_ssl_config(['EA_DB_SSL' => 'false'], $this->dir));
        $this->assertFalse(ea_db_ssl_config(['EA_DB_SSL' => '0'], $this->dir));
    }

    public function test_enabled_without_pem_uses_system_bundle(): void
    {
        $this->assertSame(
            ['ssl_ca' => '/etc/ssl/certs/ca-certificates.crt', 'ssl_verify' => true],
            ea_db_ssl_config(['EA_DB_SSL' => 'true'], $this->dir),
        );
    }

    public function test_pem_written_once_and_reused(): void
    {
        $env = ['EA_DB_SSL' => 'true', 'EA_DB_SSL_CA_PEM' => self::PEM];

        $first = ea_db_ssl_config($env, $this->dir);

        $this->assertSame(self::PEM, file_get_contents($first['ssl_ca']));
        $this->assertSame($this->dir . '/ea-db-ca-' . hash('sha256', self::PEM) . '.pem', $first['ssl_ca']);
        $this->assertSame(0644, fileperms($first['ssl_ca']) & 0777);
        $this->assertTrue($first['ssl_verify']);

        touch($first['ssl_ca'], 1000000000);
        clearstatcache();
        $mtime = filemtime($first['ssl_ca']);

        $second = ea_db_ssl_config($env, $this->dir);
        clearstatcache();

        $this->assertSame($first['ssl_ca'], $second['ssl_ca']);
        $this->assertSame(self::PEM, file_get_contents($second['ssl_ca']));
        $this->assertSame($mtime, filemtime($second['ssl_ca']));
        $this->assertCount(1, glob($this->dir . '/*'), 'no temp files left behind');
    }

    public function test_non_pem_throws_without_value(): void
    {
        $secret = 'super-secret-not-a-pem';

        try {
            ea_db_ssl_config(['EA_DB_SSL' => 'true', 'EA_DB_SSL_CA_PEM' => $secret], $this->dir);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('EA_DB_SSL_CA_PEM is not a PEM certificate', $e->getMessage());
            $this->assertStringNotContainsString($secret, $e->getMessage());
        }
    }

    public function test_literal_backslash_n_pem_is_normalised(): void
    {
        $flat = str_replace("\n", '\n', self::PEM);
        $this->assertStringNotContainsString("\n", $flat);

        $config = ea_db_ssl_config(['EA_DB_SSL' => 'true', 'EA_DB_SSL_CA_PEM' => $flat], $this->dir);

        $this->assertSame(self::PEM, file_get_contents($config['ssl_ca']));
        $this->assertSame($this->dir . '/ea-db-ca-' . hash('sha256', self::PEM) . '.pem', $config['ssl_ca']);
    }

    public function test_pem_without_end_marker_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('EA_DB_SSL_CA_PEM is not a PEM certificate');

        ea_db_ssl_config(
            ['EA_DB_SSL' => 'true', 'EA_DB_SSL_CA_PEM' => "-----BEGIN CERTIFICATE-----\nMIIB\n"],
            $this->dir,
        );
    }

    public function test_verify_false_escape_hatch(): void
    {
        $config = ea_db_ssl_config(['EA_DB_SSL' => 'true', 'EA_DB_SSL_VERIFY' => 'false'], $this->dir);

        $this->assertFalse($config['ssl_verify']);
    }

    public function test_pdo_options_map_ca_and_verify(): void
    {
        $options = ea_db_pdo_ssl_options(['ssl_ca' => '/tmp/ca.pem', 'ssl_verify' => false]);

        $ca = class_exists('Pdo\Mysql') ? \Pdo\Mysql::ATTR_SSL_CA : \PDO::MYSQL_ATTR_SSL_CA;
        $verify = class_exists('Pdo\Mysql')
            ? \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT
            : \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT;

        $this->assertSame([$ca => '/tmp/ca.pem', $verify => false], $options);
    }

    public function test_connect_failure_detail_names_code_and_cause(): void
    {
        $e = new \mysqli_sql_exception("Access denied for user 'ea'@'10.0.0.1' (using password: YES)", 1045);

        $this->assertSame(
            "[1045] Access denied for user 'ea'@'10.0.0.1' (using password: YES)",
            ea_db_connect_failure_detail($e, 's3cret'),
        );
    }

    public function test_connect_failure_detail_scrubs_the_password_and_newlines(): void
    {
        $e = new \RuntimeException("bad s3cret\nsecond line s3cret", 0);

        $this->assertSame('[0] bad *** second line ***', ea_db_connect_failure_detail($e, 's3cret'));
    }

    public function test_connect_failure_detail_with_empty_password_leaves_message(): void
    {
        $e = new \RuntimeException('Unable to connect to the database.');

        $this->assertSame('[0] Unable to connect to the database.', ea_db_connect_failure_detail($e, ''));
    }
}
