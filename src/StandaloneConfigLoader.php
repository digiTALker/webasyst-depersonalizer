<?php
declare(strict_types=1);

final class StandaloneConfigLoader
{
    /**
     * Load database configuration from Webasyst config and overlay local settings when present.
     *
     * @param string $baseDir Directory with this project.
     * @return array<string, mixed>
     */
    public static function load(string $baseDir): array
    {
        $localPath = $baseDir . DIRECTORY_SEPARATOR . 'config.local.php';
        $localConfig = null;
        if (is_file($localPath)) {
            $config = include $localPath;
            if (is_array($config)) {
                $localConfig = self::expandLocalConfig($config);
            }
        }

        $waConfigPath = self::findWebasystDbConfig($baseDir);
        if ($waConfigPath !== null) {
            $raw = include $waConfigPath;
            if (is_array($raw)) {
                $candidate = self::pickConnection($raw);
                if ($candidate !== null) {
                    if ($localConfig !== null) {
                        $candidate = array_replace($candidate, $localConfig);
                        $candidate['__source'] = $localPath . ' + ' . $waConfigPath;
                    } else {
                        $candidate['__source'] = $waConfigPath;
                    }

                    return self::normalize($candidate);
                }
            }
        }

        if ($localConfig !== null) {
            $localConfig['__source'] = $localPath;
            return self::normalize($localConfig);
        }

        return self::normalize(array(
            'driver'   => 'mysql',
            'host'     => '127.0.0.1',
            'port'     => 3306,
            'database' => '',
            'user'     => '',
            'password' => '',
            'charset'  => 'utf8mb4',
            '__source' => null,
        ));
    }

    /**
     * Build PDO DSN from normalized config.
     *
     * @param array<string, mixed> $config
     * @return string
     */
    public static function buildDsn(array $config): string
    {
        $driver = (string)$config['driver'];
        if ($driver !== 'mysql') {
            throw new RuntimeException('Only MySQL is supported by this script.');
        }

        $database = (string)$config['database'];
        $charset = (string)$config['charset'];

        if (!empty($config['socket'])) {
            return sprintf(
                'mysql:unix_socket=%s;dbname=%s;charset=%s',
                $config['socket'],
                $database,
                $charset
            );
        }

        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string)$config['host'],
            (int)$config['port'],
            $database,
            $charset
        );
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    public static function missingRequiredKeys(array $config): array
    {
        $missing = array();

        foreach (array('driver', 'database', 'user') as $key) {
            if (!isset($config[$key]) || trim((string)$config[$key]) === '') {
                $missing[] = $key;
            }
        }

        $hasSocket = isset($config['socket']) && trim((string)$config['socket']) !== '';
        $hasHost = isset($config['host']) && trim((string)$config['host']) !== '';
        if (!$hasSocket && !$hasHost) {
            $missing[] = 'host or socket';
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function normalize(array $raw): array
    {
        $raw = self::expandLocalConfig($raw);
        $driver = isset($raw['driver']) ? (string)$raw['driver'] : (string)($raw['type'] ?? 'mysql');
        if ($driver === 'mysqli') {
            $driver = 'mysql';
        }

        return array(
            'driver'   => $driver,
            'host'     => (string)($raw['host'] ?? '127.0.0.1'),
            'port'     => (int)($raw['port'] ?? 3306),
            'database' => (string)($raw['database'] ?? $raw['dbname'] ?? ''),
            'user'     => (string)($raw['user'] ?? ''),
            'password' => (string)($raw['password'] ?? ''),
            'charset'  => (string)($raw['charset'] ?? 'utf8mb4'),
            'socket'   => (string)($raw['socket'] ?? ''),
            'access_token' => (string)($raw['access_token'] ?? ''),
            'access_token_hash' => (string)($raw['access_token_hash'] ?? ''),
            'access_token_sha256' => (string)($raw['access_token_sha256'] ?? ''),
            'allow_prod' => !empty($raw['allow_prod']),
            '__source' => $raw['__source'] ?? null,
        );
    }

    /**
     * Accept both flat config.local.php and nested array('db' => array(...)).
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function expandLocalConfig(array $raw): array
    {
        if (!isset($raw['db']) || !is_array($raw['db'])) {
            return $raw;
        }

        $meta = $raw;
        unset($meta['db']);

        return array_replace($raw['db'], $meta);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null
     */
    private static function pickConnection(array $raw): ?array
    {
        if (isset($raw['type']) || isset($raw['host']) || isset($raw['database'])) {
            return $raw;
        }
        if (isset($raw['default']) && is_array($raw['default'])) {
            return $raw['default'];
        }
        foreach ($raw as $item) {
            if (is_array($item) && (isset($item['type']) || isset($item['host']) || isset($item['database']))) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Search for wa-config/db.php by walking up directories.
     *
     * @param string $baseDir
     * @return string|null
     */
    private static function findWebasystDbConfig(string $baseDir): ?string
    {
        $cursor = realpath($baseDir);
        if ($cursor === false) {
            return null;
        }

        for ($i = 0; $i < 6; $i++) {
            $candidate = $cursor . DIRECTORY_SEPARATOR . 'wa-config' . DIRECTORY_SEPARATOR . 'db.php';
            if (is_file($candidate)) {
                return $candidate;
            }
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                break;
            }
            $cursor = $parent;
        }

        return null;
    }
}
