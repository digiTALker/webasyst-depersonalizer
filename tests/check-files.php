<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$files = array_merge(array($root . '/index.php', $root . '/config.local.php.example'),
    glob($root . '/src/Standalone*.php'), glob(__DIR__ . '/*.php'));
foreach ($files as $file) {
    $content = file_get_contents($file);
    if (!str_starts_with($content, '<?php') || str_contains($content, "\x00") || str_contains($content, "\xEF\xBB\xBF")) {
        throw new RuntimeException('Invalid PHP prefix, NUL or BOM: ' . basename($file));
    }
    if (str_contains($content, 'declare(strict_types=1);') &&
        !preg_match('/^<\?php\s+declare\(strict_types=1\);/', $content)) {
        throw new RuntimeException('strict_types is not the first statement: ' . basename($file));
    }
}
echo 'PASS PHP prefixes, strict_types and no BOM/NUL (' . count($files) . ' files)' . PHP_EOL;
