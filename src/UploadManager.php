<?php

declare(strict_types=1);

namespace MyCDN;

use RuntimeException;

final class UploadManager
{
    public function __construct(
        private readonly array $config,
        private readonly FileRepository $files
    ) {
    }

    public function upload(array $upload, ?string $altText, string $uploadedBy, ?string $category = null, mixed $customMetadata = null): array
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE)));
        }

        $tmpName = (string) $upload['tmp_name'];
        if (!is_uploaded_file($tmpName)) {
            throw new RuntimeException('Invalid uploaded file.');
        }

        $originalName = $this->sanitizeFileName((string) $upload['name']);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = array_map('strtolower', $this->config['storage']['allowed_extensions'] ?? []);
        if ($extension === '' || !in_array($extension, $allowed, true)) {
            throw new RuntimeException('File extension is not allowed.');
        }

        $size = (int) $upload['size'];
        $maxSize = (int) ($this->config['storage']['max_file_size'] ?? 0);
        if ($maxSize > 0 && $size > $maxSize) {
            throw new RuntimeException('File is too large.');
        }

        $mimeType = $this->detectMimeType($tmpName);
        $this->assertAllowedMimeType($extension, $mimeType);
        $this->assertSafeImageDimensions($tmpName, $extension, $mimeType);

        $uid = bin2hex(random_bytes(16));
        $storedName = $uid . '.' . $extension;
        $datedPath = date('Y/m');
        $uploadDir = rtrim((string) $this->config['storage']['upload_dir'], DIRECTORY_SEPARATOR);
        $targetDir = $uploadDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $datedPath);

        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Cannot create upload directory.');
        }
        $this->writeUploadHtaccess($uploadDir);

        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($tmpName, $targetPath)) {
            throw new RuntimeException('Cannot save uploaded file.');
        }

        $analysis = $this->analyzeStoredFile($targetPath, $originalName, $extension, $mimeType, $upload['type'] ?? null, $customMetadata);
        $relativePath = trim((string) $this->config['storage']['public_path'], '/') . '/' . $datedPath . '/' . $storedName;

        return $this->files->create([
            'uid' => $uid,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'relative_path' => $relativePath,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size_bytes' => $size,
            'sha256' => $analysis['sha256'],
            'metadata' => json_encode($analysis['metadata'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'width' => $analysis['width'],
            'height' => $analysis['height'],
            'color_scheme' => $analysis['color_scheme'],
            'category' => $this->sanitizeCategory($category),
            'alt_text' => $altText !== null && trim($altText) !== '' ? trim($altText) : null,
            'uploaded_by' => $uploadedBy,
            'source_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    public function normalizeFilesArray(array $files): array
    {
        if (!isset($files['name'])) {
            return [];
        }

        if (!is_array($files['name'])) {
            return [$files];
        }

        $maxFiles = (int) ($this->config['storage']['max_files_per_upload'] ?? 20);
        if (count($files['name']) > $maxFiles) {
            throw new RuntimeException('Too many files in one upload.');
        }

        $normalized = [];
        foreach ($files['name'] as $index => $name) {
            $normalized[] = [
                'name' => $name,
                'type' => $files['type'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0,
            ];
        }

        return $normalized;
    }

    public function deleteStoredFile(array $file): void
    {
        $uploadDir = realpath((string) $this->config['storage']['upload_dir']);
        if ($uploadDir === false) {
            return;
        }

        $publicPath = trim((string) $this->config['storage']['public_path'], '/');
        $relativePath = str_replace('\\', '/', (string) $file['relative_path']);
        if ($publicPath !== '' && str_starts_with($relativePath, $publicPath . '/')) {
            $relativePath = substr($relativePath, strlen($publicPath) + 1);
        }

        if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) {
            return;
        }

        $path = realpath($uploadDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        if ($path !== false && is_file($path) && str_starts_with($path, $uploadDir . DIRECTORY_SEPARATOR)) {
            unlink($path);
        }
    }

    private function sanitizeFileName(string $name): string
    {
        // Browsers normally send only the file name, but strip both Unix and
        // Windows paths defensively while preserving Unicode and spaces.
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = trim($name);

        // The database column is VARCHAR(255); truncate by Unicode characters,
        // not bytes, so a multibyte character is never cut in half.
        $name = preg_replace('/\A(.{0,255}).*\z/us', '$1', $name) ?? '';

        return $name !== '' && $name !== '.' && $name !== '..' ? $name : 'file';
    }

    public function sanitizeCategory(?string $category): string
    {
        $category = trim((string) $category);
        if ($category === '') {
            return 'general';
        }

        $category = preg_replace('/[^A-Za-z0-9._-]+/', '-', $category) ?: 'general';
        $category = trim($category, '.-');

        return $category !== '' ? substr($category, 0, 100) : 'general';
    }

    private function detectMimeType(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }

        $mimeType = finfo_file($finfo, $path) ?: 'application/octet-stream';
        finfo_close($finfo);

        return $mimeType;
    }

    private function assertAllowedMimeType(string $extension, string $mimeType): void
    {
        $allowedMimeTypes = $this->config['storage']['allowed_mime_types'][$extension] ?? null;
        if (!is_array($allowedMimeTypes) || $allowedMimeTypes === []) {
            return;
        }

        if (!in_array($mimeType, $allowedMimeTypes, true)) {
            throw new RuntimeException('File content type does not match the allowed type for this extension.');
        }
    }

    private function assertSafeImageDimensions(string $path, string $extension, string $mimeType): void
    {
        if (!str_starts_with($mimeType, 'image/')) {
            return;
        }

        if ($extension === 'svg') {
            throw new RuntimeException('SVG uploads are not allowed.');
        }

        $size = @getimagesize($path);
        if ($size === false || empty($size[0]) || empty($size[1])) {
            throw new RuntimeException('Uploaded image is invalid.');
        }

        $pixels = (int) $size[0] * (int) $size[1];
        $maxPixels = (int) ($this->config['images']['max_source_pixels'] ?? 40000000);
        if ($pixels > $maxPixels) {
            throw new RuntimeException('Uploaded image dimensions are too large.');
        }
    }

    private function writeUploadHtaccess(string $uploadDir): void
    {
        if (!is_dir($uploadDir)) {
            return;
        }

        $path = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.htaccess';
        if (is_file($path)) {
            return;
        }

        $content = "Options -Indexes\n"
            . "php_flag engine off\n"
            . "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .cgi .pl .py .asp .aspx .jsp\n"
            . "<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|php8|phar|cgi|pl|py|asp|aspx|jsp)$\">\n"
            . "    Require all denied\n"
            . "</FilesMatch>\n";

        @file_put_contents($path, $content, LOCK_EX);
    }

    private function analyzeStoredFile(string $path, string $originalName, string $extension, string $mimeType, mixed $clientMimeType, mixed $customMetadata): array
    {
        $sha256 = hash_file('sha256', $path);
        if ($sha256 === false) {
            throw new RuntimeException('Cannot calculate SHA-256 hash.');
        }

        $image = $this->detectImageInfo($path, $extension, $mimeType);
        $metadata = [
            'original_name' => $originalName,
            'extension' => $extension,
            'client_mime_type' => is_string($clientMimeType) ? $clientMimeType : null,
            'detected_mime_type' => $mimeType,
            'image' => $image,
        ];

        if ($customMetadata !== null && $customMetadata !== '') {
            $metadata['custom'] = $this->normalizeCustomMetadata($customMetadata);
        }

        return [
            'sha256' => $sha256,
            'metadata' => $metadata,
            'width' => $image['width'],
            'height' => $image['height'],
            'color_scheme' => $image['color_scheme'],
        ];
    }

    private function detectImageInfo(string $path, string $extension, string $mimeType): array
    {
        $info = [
            'width' => null,
            'height' => null,
            'color_scheme' => null,
            'bits' => null,
            'channels' => null,
        ];

        if ($extension === 'svg' || $mimeType === 'image/svg+xml') {
            return array_merge($info, $this->detectSvgInfo($path), ['color_scheme' => 'vector']);
        }

        if (!str_starts_with($mimeType, 'image/')) {
            return $info;
        }

        $size = @getimagesize($path);
        if ($size === false) {
            return array_merge($info, ['color_scheme' => 'unknown']);
        }

        $channels = isset($size['channels']) ? (int) $size['channels'] : null;
        $bits = isset($size['bits']) ? (int) $size['bits'] : null;
        $colorScheme = $this->detectRasterColorScheme($path, $extension, $mimeType, $channels);

        return [
            'width' => isset($size[0]) ? (int) $size[0] : null,
            'height' => isset($size[1]) ? (int) $size[1] : null,
            'color_scheme' => $colorScheme,
            'bits' => $bits,
            'channels' => $channels,
        ];
    }

    private function detectRasterColorScheme(string $path, string $extension, string $mimeType, ?int $channels): string
    {
        if ($extension === 'gif') {
            return 'indexed';
        }

        if ($extension === 'png') {
            $handle = @fopen($path, 'rb');
            if ($handle !== false) {
                $header = fread($handle, 26);
                fclose($handle);
                $colorType = strlen((string) $header) >= 26 ? ord($header[25]) : null;

                return match ($colorType) {
                    0 => 'grayscale',
                    2 => 'rgb',
                    3 => 'indexed',
                    4 => 'grayscale-alpha',
                    6 => 'rgba',
                    default => 'unknown',
                };
            }
        }

        if ($mimeType === 'image/jpeg') {
            return match ($channels) {
                1 => 'grayscale',
                3 => 'rgb',
                4 => 'cmyk',
                default => 'unknown',
            };
        }

        return match ($channels) {
            1 => 'grayscale',
            3 => 'rgb',
            4 => 'rgba',
            default => 'unknown',
        };
    }

    private function detectSvgInfo(string $path): array
    {
        $content = file_get_contents($path, false, null, 0, 65536);
        if ($content === false) {
            return ['width' => null, 'height' => null];
        }

        $width = $this->extractSvgLength($content, 'width');
        $height = $this->extractSvgLength($content, 'height');
        if (($width === null || $height === null) && preg_match('/viewBox=["\']\s*[-.\d]+\s+[-.\d]+\s+([.\d]+)\s+([.\d]+)\s*["\']/i', $content, $matches)) {
            $width ??= (int) round((float) $matches[1]);
            $height ??= (int) round((float) $matches[2]);
        }

        return ['width' => $width, 'height' => $height];
    }

    private function extractSvgLength(string $content, string $attribute): ?int
    {
        if (!preg_match('/\s' . preg_quote($attribute, '/') . '=["\']\s*([.\d]+)/i', $content, $matches)) {
            return null;
        }

        return (int) round((float) $matches[1]);
    }

    private function normalizeCustomMetadata(mixed $metadata): mixed
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (!is_string($metadata)) {
            return $metadata;
        }

        $decoded = json_decode($metadata, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $metadata;
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large.',
            UPLOAD_ERR_PARTIAL => 'File was uploaded only partially.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload directory.',
            UPLOAD_ERR_CANT_WRITE => 'Cannot write uploaded file.',
            UPLOAD_ERR_EXTENSION => 'Upload was blocked by a PHP extension.',
            default => 'Unknown upload error.',
        };
    }
}
