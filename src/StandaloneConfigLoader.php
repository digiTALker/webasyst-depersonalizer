<?php
declare(strict_types=1);

final class StandaloneConfigLoader
{
    /**
     * Load database configuration from local file first, then from Webasyst config.
     *
     * @param string $baseDir Directory with this project.
     * @return array<string, mixed>
     */
    public static function load(string $baseDir): array
    {
        $localPath = $baseDir . DIRECTORY_SEPARATOR . 'config.local.php';
        if (is_file($localPath)) {
            $config = include $localPath;
            if (is_array($config)) {
                return self::normalize($config);
            }
        }

        $waConfigPath = self::findWebasystDbConfig($baseDir);
        if ($waConfigPath !== null) {
            $raw = include $waConfigPath;
            if (is_array($raw)) {
                $candidate = self::pickConnection($raw);
                if ($candidate !== null) {
                    return self::normalize($candidate + array(
                        '__source' => $waConfigPath,
                    ));
                }
            }
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
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function normalize(array $raw): array
    {
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
            '__source' => $raw['__source'] ?? null,
        );
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
