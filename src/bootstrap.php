<?php

declare(strict_types=1);

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    echo 'Missing config.php. Copy config.php.example to config.php and edit it.';
    exit;
}

$config = require $configFile;
date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'MyCDN\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

function config_value(string $path, mixed $default = null): mixed
{
    global $config;

    $value = $config;
    foreach (explode('.', $path) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }

    return $value;
}

function app_url(string $path = ''): string
{
    $baseUrl = rtrim((string) config_value('app.base_url', ''), '/');
    return $baseUrl . '/' . ltrim($path, '/');
}

function public_file_url(array $file, ?string $size = null, ?string $mode = null): string
{
    $mimeType = (string) ($file['mime_type'] ?? '');
    if (str_starts_with($mimeType, 'image/')) {
        $url = app_url('i/' . (string) $file['uid'] . '.' . (string) $file['extension']);
        $query = [];
        if ($size !== null && $size !== '') {
            $query['s'] = $size;
        }
        if ($mode !== null && $mode !== '') {
            $query['m'] = $mode;
        }

        return $query !== [] ? $url . '?' . http_build_query($query) : $url;
    }

    return app_url('f/' . (string) $file['uid'] . '.' . (string) $file['extension']);
}

function security_headers(bool $allowInline = false): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    if (!$allowInline) {
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox");
    }
}

function rate_limit(string $bucket, int $maxRequests, int $windowSeconds): void
{
    if (config_value('rate_limit.enabled', true) !== true) {
        return;
    }

    $ip = current_ip() ?? 'unknown';
    $key = preg_replace('/[^A-Za-z0-9._-]+/', '-', $bucket . '-' . $ip) ?: 'rate-limit';
    $dir = (string) config_value('rate_limit.dir', dirname(__DIR__) . '/uploads/.rate-limit');
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }

    $path = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    $now = time();
    $state = ['window' => $now, 'count' => 0];

    $handle = fopen($path, 'c+');
    if ($handle === false) {
        return;
    }

    flock($handle, LOCK_EX);
    $content = stream_get_contents($handle);
    if (is_string($content) && $content !== '') {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $state = $decoded;
        }
    }

    if (($now - (int) ($state['window'] ?? 0)) >= $windowSeconds) {
        $state = ['window' => $now, 'count' => 0];
    }

    $state['count'] = (int) ($state['count'] ?? 0) + 1;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($state));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    if ($state['count'] > $maxRequests) {
        http_response_code(429);
        header('Retry-After: ' . max(1, $windowSeconds - ($now - (int) $state['window'])));
        $wantsJson = false;
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type: application/json') === 0) {
                $wantsJson = true;
                break;
            }
        }

        echo $wantsJson
            ? json_encode(['success' => false, 'error' => 'Too many requests.'], JSON_UNESCAPED_SLASHES)
            : 'Too many requests.';
        exit;
    }
}

function stored_file_path(array $file): ?string
{
    $uploadDir = realpath((string) config_value('storage.upload_dir'));
    if ($uploadDir === false) {
        return null;
    }

    $publicPath = trim((string) config_value('storage.public_path', 'uploads'), '/');
    $relativePath = str_replace('\\', '/', (string) $file['relative_path']);
    if ($publicPath !== '' && str_starts_with($relativePath, $publicPath . '/')) {
        $relativePath = substr($relativePath, strlen($publicPath) + 1);
    }

    if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) {
        return null;
    }

    $path = realpath($uploadDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    if ($path === false || !str_starts_with($path, $uploadDir . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $path;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function current_ip(): ?string
{
    return $_SERVER['REMOTE_ADDR'] ?? null;
}
