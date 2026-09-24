<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if (
    str_starts_with($path, '/uploads/')
    || str_starts_with($path, '/src/')
    || preg_match('#/(?:\.|config\.php$|config\.php\.example$|README\.md$|nginx\.md$|schema\.sql$|router\.php$)#', $path)
) {
    http_response_code(403);
    echo 'Forbidden.';
    return true;
}

if ($path !== '/' && is_file($file)) {
    return false;
}

if (preg_match('#^/i/([A-Fa-f0-9]{32})\.[A-Za-z0-9]+$#', $path, $matches)) {
    $_GET['uid'] = $matches[1];
    require __DIR__ . '/image.php';
    return true;
}

if (preg_match('#^/f/([A-Fa-f0-9]{32})\.[A-Za-z0-9]+$#', $path, $matches)) {
    $_GET['uid'] = $matches[1];
    require __DIR__ . '/file.php';
    return true;
}

return false;
