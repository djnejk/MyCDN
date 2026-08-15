<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use MyCDN\Auth;
use MyCDN\Database;
use MyCDN\FileRepository;
use MyCDN\UploadManager;

security_headers();
header('Content-Type: application/json; charset=utf-8');

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        return trim($matches[1]);
    }

    $apiToken = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if ($apiToken !== '') {
        return trim($apiToken);
    }

    return $_REQUEST['token'] ?? null;
}

function public_file_payload(array $file): array
{
    $metadata = null;
    if (!empty($file['metadata'])) {
        $decodedMetadata = json_decode((string) $file['metadata'], true);
        $metadata = json_last_error() === JSON_ERROR_NONE ? $decodedMetadata : $file['metadata'];
    }

    return [
        'id' => (int) $file['id'],
        'uid' => $file['uid'],
        'original_name' => $file['original_name'],
        'stored_name' => $file['stored_name'],
        'mime_type' => $file['mime_type'],
        'extension' => $file['extension'],
        'size_bytes' => (int) $file['size_bytes'],
        'size_human' => human_size((int) $file['size_bytes']),
        'sha256' => $file['sha256'] ?? null,
        'metadata' => $metadata,
        'width' => isset($file['width']) ? (int) $file['width'] : null,
        'height' => isset($file['height']) ? (int) $file['height'] : null,
        'color_scheme' => $file['color_scheme'] ?? null,
        'category' => $file['category'] ?? 'general',
        'alt_text' => $file['alt_text'],
        'url' => public_file_url($file),
        'relative_path' => $file['relative_path'],
        'uploaded_by' => $file['uploaded_by'],
        'created_at' => $file['created_at'],
    ];
}

function human_size(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = (float) $bytes;
    foreach ($units as $unit) {
        if ($size < 1024 || $unit === 'TB') {
            return rtrim(rtrim(number_format($size, 2, '.', ''), '0'), '.') . ' ' . $unit;
        }
        $size /= 1024;
    }

    return $bytes . ' B';
}

try {
    rate_limit('api', (int) config_value('rate_limit.api_requests', 120), (int) config_value('rate_limit.api_window_seconds', 60));

    $auth = new Auth($config);
    if (!$auth->checkApiToken(bearer_token())) {
        json_response(['success' => false, 'error' => 'Unauthorized.'], 401);
    }

    $database = new Database($config);
    $repository = new FileRepository($database->pdo(), $config['database']['table_prefix'] ?? '');
    $uploads = new UploadManager($config, $repository);
    $action = $_GET['action'] ?? $_POST['action'] ?? 'upload';

    if ($action === 'upload') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(['success' => false, 'error' => 'Upload requires POST.'], 405);
        }

        $input = $_FILES['files'] ?? $_FILES['file'] ?? null;
        if ($input === null) {
            json_response(['success' => false, 'error' => 'Missing file field. Use file or files[].'], 422);
        }

        $category = $_POST['category'] ?? 'general';
        $altTexts = $_POST['alt_texts'] ?? $_POST['alts'] ?? null;
        $altText = $_POST['alt'] ?? $_POST['alt_text'] ?? null;
        $customMetadata = $_POST['metadata'] ?? null;
        $created = [];
        $errors = [];

        foreach ($uploads->normalizeFilesArray($input) as $index => $file) {
            try {
                $fileAltText = is_array($altTexts) ? ($altTexts[$index] ?? null) : $altText;
                $created[] = public_file_payload($uploads->upload($file, $fileAltText, 'api', $category, $customMetadata));
            } catch (Throwable $exception) {
                $errors[] = [
                    'index' => $index,
                    'name' => $file['name'] ?? null,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        json_response([
            'success' => count($created) > 0 && count($errors) === 0,
            'files' => $created,
            'errors' => $errors,
        ], count($created) > 0 ? 200 : 422);
    }

    if ($action === 'info') {
        $file = null;
        if (isset($_GET['id'])) {
            $file = $repository->findById((int) $_GET['id']);
        } elseif (isset($_GET['uid'])) {
            $file = $repository->findByUid((string) $_GET['uid']);
        }

        if ($file === null) {
            json_response(['success' => false, 'error' => 'File not found.'], 404);
        }

        json_response(['success' => true, 'file' => public_file_payload($file)]);
    }

    if ($action === 'list') {
        $category = isset($_GET['category']) ? (string) $_GET['category'] : null;
        $query = isset($_GET['q']) ? (string) $_GET['q'] : null;
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
        $files = array_map('public_file_payload', $repository->list($limit, 0, $query, $category));

        json_response(['success' => true, 'files' => $files]);
    }

    json_response(['success' => false, 'error' => 'Unknown action.'], 404);
} catch (Throwable $exception) {
    json_response(['success' => false, 'error' => 'Server error.'], 500);
}
