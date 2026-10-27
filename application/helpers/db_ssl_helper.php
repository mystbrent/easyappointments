<?php defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Database TLS helpers.
 *
 * CodeIgniter 3's mysqli driver only negotiates TLS when `encrypt` is an ARRAY
 * (ssl_ca, ssl_verify, ...); a boolean is silently ignored. These helpers turn
 * the EA_DB_SSL* environment into that array, and into the equivalent PDO options
 * for the standalone ea-seed CLI.
 */

/**
 * Build the CodeIgniter `encrypt` array from the environment.
 *
 * @param array $env Environment variables (normally getenv()).
 * @param string $dir Directory where a CA delivered as PEM text is materialised.
 *
 * @return array|false False when TLS is off, otherwise ['ssl_ca' => string, 'ssl_verify' => bool].
 *
 * @throws InvalidArgumentException When EA_DB_SSL_CA_PEM is set but is not a PEM certificate.
 */
function ea_db_ssl_config(array $env, string $dir): array|false
{
    if (!filter_var($env['EA_DB_SSL'] ?? 'false', FILTER_VALIDATE_BOOLEAN)) {
        return false;
    }

    $verify = strtolower(trim((string) ($env['EA_DB_SSL_VERIFY'] ?? 'true'))) !== 'false';
    $pem = (string) ($env['EA_DB_SSL_CA_PEM'] ?? '');

    if ($pem === '') {
        return ['ssl_ca' => '/etc/ssl/certs/ca-certificates.crt', 'ssl_verify' => $verify];
    }

    // Deliberately do not echo the value: it may be a mis-pasted secret.
    if (!str_contains($pem, '-----BEGIN CERTIFICATE-----')) {
        throw new InvalidArgumentException('EA_DB_SSL_CA_PEM is not a PEM certificate');
    }

    $path = rtrim($dir, '/') . '/ea-db-ca-' . hash('sha256', $pem) . '.pem';

    if (!is_file($path)) {
        // Atomic publish: concurrent php-fpm workers never observe a partial file.
        $tmp = tempnam($dir, 'ea-db-ca-');

        if ($tmp === false || file_put_contents($tmp, $pem) === false || !chmod($tmp, 0644) || !rename($tmp, $path)) {
            if ($tmp !== false) {
                @unlink($tmp);
            }

            throw new RuntimeException('Could not write the database CA certificate');
        }
    }

    return ['ssl_ca' => $path, 'ssl_verify' => $verify];
}

/**
 * Map an ea_db_ssl_config() result onto PDO MySQL attributes.
 *
 * @param array $ssl The ['ssl_ca' => ..., 'ssl_verify' => ...] array.
 *
 * @return array PDO options keyed by attribute constant.
 */
function ea_db_pdo_ssl_options(array $ssl): array
{
    // PHP 8.4 deprecates PDO::MYSQL_ATTR_* in favour of Pdo\Mysql::ATTR_*.
    if (class_exists('Pdo\Mysql')) {
        return [
            \Pdo\Mysql::ATTR_SSL_CA => $ssl['ssl_ca'],
            \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => $ssl['ssl_verify'],
        ];
    }

    return [
        \PDO::MYSQL_ATTR_SSL_CA => $ssl['ssl_ca'],
        \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => $ssl['ssl_verify'],
    ];
}
