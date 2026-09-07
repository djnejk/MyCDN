<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

cors_headers();
handle_cors_preflight();

use MyCDN\Database;
use MyCDN\FileRepository;

function image_not_found(): never
{
    http_response_code(404);
    security_headers();
    echo 'Image not found.';
    exit;
}

function image_bad_request(string $message): never
{
    http_response_code(400);
    security_headers();
    echo $message;
    exit;
}

function image_source_path(array $file): string
{
    $path = stored_file_path($file);
    if ($path === null) {
        image_not_found();
    }

    return $path;
}

function image_send_file(string $path, string $mimeType): never
{
    if (!is_file($path)) {
        image_not_found();
    }

    header('Content-Type: ' . $mimeType);
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: public, max-age=31536000, immutable');
    header('ETag: "' . hash_file('sha256', $path) . '"');
    readfile($path);
    exit;
}

function image_size_from_query(): ?array
{
    $size = trim((string) ($_GET['s'] ?? ''));
    if ($size === '') {
        return null;
    }

    if (!preg_match('/^(\d{1,5})x(\d{1,5})$/', $size, $matches)) {
        image_bad_request('Invalid size. Use s=WIDTHxHEIGHT, for example s=1920x1080.');
    }

    $width = (int) $matches[1];
    $height = (int) $matches[2];
    $maxWidth = (int) config_value('images.max_resize_width', 3840);
    $maxHeight = (int) config_value('images.max_resize_height', 3840);

    if ($width < 1 || $height < 1 || $width > $maxWidth || $height > $maxHeight) {
        image_bad_request('Requested image size is outside allowed limits.');
    }

    $maxPixels = (int) config_value('images.max_resize_pixels', 12000000);
    if (($width * $height) > $maxPixels) {
        image_bad_request('Requested image size is too large.');
    }

    return [$width, $height];
}

function image_mode_from_query(): string
{
    $mode = (string) ($_GET['m'] ?? config_value('images.default_mode', 'fit'));
    return in_array($mode, ['fit', 'crop'], true) ? $mode : 'fit';
}

function image_cache_path(array $file, int $width, int $height, string $mode): string
{
    $extension = strtolower((string) $file['extension']);
    $cacheDir = rtrim((string) config_value('storage.cache_dir', dirname(__DIR__) . '/uploads/cache'), DIRECTORY_SEPARATOR);
    $fileDir = $cacheDir . DIRECTORY_SEPARATOR . (string) $file['uid'];
    if (!is_dir($fileDir) && !mkdir($fileDir, 0775, true) && !is_dir($fileDir)) {
        image_bad_request('Cannot create image cache directory.');
    }

    return $fileDir . DIRECTORY_SEPARATOR . $width . 'x' . $height . '_' . $mode . '.' . $extension;
}

function image_load(string $path, string $extension): GdImage
{
    $image = match ($extension) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($path),
        'png' => @imagecreatefrompng($path),
        'gif' => @imagecreatefromgif($path),
        'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default => false,
    };

    if (!$image instanceof GdImage) {
        image_bad_request('This image type cannot be resized by this server.');
    }

    return $image;
}

function image_save(GdImage $image, string $path, string $extension, string $mimeType): void
{
    $ok = match ($extension) {
        'jpg', 'jpeg' => imagejpeg($image, $path, (int) config_value('images.jpeg_quality', 86)),
        'png' => imagepng($image, $path, 6),
        'gif' => imagegif($image, $path),
        'webp' => function_exists('imagewebp') ? imagewebp($image, $path, (int) config_value('images.webp_quality', 82)) : false,
        default => false,
    };

    if (!$ok) {
        image_bad_request('Cannot save resized image.');
    }
}

function image_resize(string $sourcePath, string $targetPath, string $extension, int $targetWidth, int $targetHeight, string $mode): void
{
    if (!extension_loaded('gd')) {
        image_bad_request('GD extension is required for image resizing.');
    }

    $source = image_load($sourcePath, $extension);
    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    $maxSourcePixels = (int) config_value('images.max_source_pixels', 40000000);
    if (($sourceWidth * $sourceHeight) > $maxSourcePixels) {
        imagedestroy($source);
        image_bad_request('Source image is too large to resize.');
    }

    if ($mode === 'crop') {
        $sourceRatio = $sourceWidth / $sourceHeight;
        $targetRatio = $targetWidth / $targetHeight;
        if ($sourceRatio > $targetRatio) {
            $cropHeight = $sourceHeight;
            $cropWidth = (int) round($sourceHeight * $targetRatio);
        } else {
            $cropWidth = $sourceWidth;
            $cropHeight = (int) round($sourceWidth / $targetRatio);
        }
        $srcX = (int) floor(($sourceWidth - $cropWidth) / 2);
        $srcY = (int) floor(($sourceHeight - $cropHeight) / 2);
        $destWidth = $targetWidth;
        $destHeight = $targetHeight;
    } else {
        $scale = min($targetWidth / $sourceWidth, $targetHeight / $sourceHeight, 1);
        $cropWidth = $sourceWidth;
        $cropHeight = $sourceHeight;
        $srcX = 0;
        $srcY = 0;
        $destWidth = max(1, (int) round($sourceWidth * $scale));
        $destHeight = max(1, (int) round($sourceHeight * $scale));
    }

    $resized = imagecreatetruecolor($destWidth, $destHeight);
    if (in_array($extension, ['png', 'gif', 'webp'], true)) {
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefilledrectangle($resized, 0, 0, $destWidth, $destHeight, $transparent);
    }

    imagecopyresampled($resized, $source, 0, 0, $srcX, $srcY, $destWidth, $destHeight, $cropWidth, $cropHeight);
    image_save($resized, $targetPath, $extension, '');
    imagedestroy($source);
    imagedestroy($resized);
}

try {
    rate_limit('image', (int) config_value('rate_limit.image_requests', 600), (int) config_value('rate_limit.image_window_seconds', 60));

    $uid = (string) ($_GET['uid'] ?? '');
    if (!preg_match('/^[A-Fa-f0-9]{32}$/', $uid)) {
        image_not_found();
    }

    $database = new Database($config);
    $repository = new FileRepository($database->pdo(), $config['database']['table_prefix'] ?? '');
    $file = $repository->findByUid($uid);
    if ($file === null || !str_starts_with((string) $file['mime_type'], 'image/')) {
        image_not_found();
    }

    $sourcePath = image_source_path($file);
    $extension = strtolower((string) $file['extension']);
    $mimeType = (string) $file['mime_type'];
    if ($extension === 'svg' || $mimeType === 'image/svg+xml') {
        image_bad_request('SVG images are not served inline.');
    }

    $requestedSize = image_size_from_query();

    if ($requestedSize === null) {
        image_send_file($sourcePath, $mimeType);
    }

    [$width, $height] = $requestedSize;
    $mode = image_mode_from_query();
    $cachePath = image_cache_path($file, $width, $height, $mode);

    if (!is_file($cachePath) || filemtime($cachePath) < filemtime($sourcePath)) {
        image_resize($sourcePath, $cachePath, $extension, $width, $height, $mode);
    }

    image_send_file($cachePath, $mimeType);
} catch (Throwable $exception) {
    http_response_code(500);
    security_headers();
    echo 'Server error.';
}
