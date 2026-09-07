<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

cors_headers();
handle_cors_preflight();

use MyCDN\Database;
use MyCDN\FileRepository;

function file_not_found(): never
{
    http_response_code(404);
    security_headers();
    echo 'File not found.';
    exit;
}

function safe_download_name(string $name): string
{
    $name = basename($name);
    $name = preg_replace('/[\r\n"]+/', '-', $name) ?: 'download';
    return substr($name, 0, 180);
}

try {
    rate_limit('file', (int) config_value('rate_limit.image_requests', 600), (int) config_value('rate_limit.image_window_seconds', 60));

    $uid = (string) ($_GET['uid'] ?? '');
    if (!preg_match('/^[A-Fa-f0-9]{32}$/', $uid)) {
        file_not_found();
    }

    $database = new Database($config);
    $repository = new FileRepository($database->pdo(), $config['database']['table_prefix'] ?? '');
    $file = $repository->findByUid($uid);
    if ($file === null || str_starts_with((string) $file['mime_type'], 'image/')) {
        file_not_found();
    }

    $path = stored_file_path($file);
    if ($path === null || !is_file($path)) {
        file_not_found();
    }

    security_headers();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . safe_download_name((string) $file['original_name']) . '"');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: public, max-age=31536000, immutable');
    header('ETag: "' . hash_file('sha256', $path) . '"');
    readfile($path);
} catch (Throwable) {
    file_not_found();
}
