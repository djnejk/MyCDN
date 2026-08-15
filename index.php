<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use MyCDN\Auth;
use MyCDN\Database;
use MyCDN\FileRepository;
use MyCDN\UploadManager;

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

function admin_human_size(int $bytes): string
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

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        throw new RuntimeException('Invalid CSRF token.');
    }
}

function flash_notice(string $message): void
{
    $_SESSION['notice'] = $message;
}

function flash_error(string $message): void
{
    $_SESSION['error'] = $message;
}

function admin_current_url(): string
{
    $query = $_SERVER['QUERY_STRING'] ?? '';
    return 'index.php' . ($query !== '' ? '?' . $query : '');
}

function admin_file_url(array $file): string
{
    return public_file_url($file);
}

function admin_image_details(array $file): string
{
    $parts = [];
    if (!empty($file['width']) && !empty($file['height'])) {
        $parts[] = (int) $file['width'] . 'x' . (int) $file['height'];
    }
    if (!empty($file['color_scheme'])) {
        $parts[] = (string) $file['color_scheme'];
    }

    return implode(' / ', $parts);
}

$auth = new Auth($config);
$error = $_SESSION['error'] ?? null;
$notice = $_SESSION['notice'] ?? null;
unset($_SESSION['error'], $_SESSION['notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'login') {
    if ($auth->login((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        redirect('index.php');
    }
    flash_error('Spatne prihlasovaci udaje.');
    redirect('index.php');
}

$repository = null;
$files = [];
$categories = [];
$query = trim((string) ($_GET['q'] ?? ''));
$activeCategory = trim((string) ($_GET['category'] ?? ''));

if ($auth->check()) {
    try {
        $database = new Database($config);
        $repository = new FileRepository($database->pdo(), $config['database']['table_prefix'] ?? '');
        $uploads = new UploadManager($config, $repository);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'upload') {
            verify_csrf();
            $category = $uploads->sanitizeCategory($_POST['category'] ?? null);
            $altTexts = is_array($_POST['alt_texts'] ?? null) ? $_POST['alt_texts'] : [];
            $created = 0;
            foreach ($uploads->normalizeFilesArray($_FILES['files'] ?? []) as $index => $file) {
                $uploads->upload($file, $altTexts[$index] ?? null, 'admin', $category);
                $created++;
            }
            flash_notice($created === 1 ? 'Soubor byl nahran.' : 'Soubory byly nahrany.');
            redirect(admin_current_url());
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'metadata') {
            verify_csrf();
            $category = $uploads->sanitizeCategory($_POST['category'] ?? null);
            $altText = trim((string) ($_POST['alt_text'] ?? ''));
            $repository->updateMetadata((int) ($_POST['id'] ?? 0), $category, $altText !== '' ? $altText : null);
            flash_notice('Metadata byla ulozena.');
            redirect(admin_current_url());
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete') {
            verify_csrf();
            $deleted = $repository->delete((int) ($_POST['id'] ?? 0));
            if ($deleted !== null) {
                $uploads->deleteStoredFile($deleted);
                flash_notice('Soubor byl smazan.');
                redirect(admin_current_url());
            }
            flash_error('Soubor nebyl nalezen.');
            redirect(admin_current_url());
        }

        $categories = $repository->categories();
        $files = $repository->list(100, 0, $query !== '' ? $query : null, $activeCategory !== '' ? $activeCategory : null);
    } catch (Throwable $exception) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            flash_error($exception->getMessage());
            redirect(admin_current_url());
        }

        flash_error($exception->getMessage());
        $error = $_SESSION['error'];
        unset($_SESSION['error']);
    }
}

?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(config_value('app.name', 'MyCDN')) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="shell">
    <header class="topbar">
        <div>
            <p class="eyebrow">PHP CDN</p>
            <h1><?= e(config_value('app.name', 'MyCDN')) ?></h1>
        </div>
        <?php if ($auth->check()): ?>
            <a class="button secondary" href="logout.php">Odhlasit</a>
        <?php endif; ?>
    </header>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($notice): ?>
        <div class="alert success"><?= e($notice) ?></div>
    <?php endif; ?>

    <?php if (!$auth->check()): ?>
        <section class="panel login-panel">
            <h2>Admin login</h2>
            <form method="post" class="form">
                <input type="hidden" name="form" value="login">
                <label>
                    Uzivatelske jmeno
                    <input name="username" autocomplete="username" required>
                </label>
                <label>
                    Heslo
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>
                <button class="button" type="submit">Prihlasit</button>
            </form>
        </section>
    <?php else: ?>
        <section class="grid">
            <div class="panel">
                <h2>Nahrat soubory</h2>
                <form method="post" enctype="multipart/form-data" class="form" id="uploadForm">
                    <input type="hidden" name="form" value="upload">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <label>
                        Kategorie
                        <input name="category" value="<?= e($activeCategory !== '' ? $activeCategory : 'general') ?>" maxlength="100" list="category-list" required>
                    </label>
                    <datalist id="category-list">
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= e($category['category']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <div class="dropzone" id="dropzone">
                        <input class="file-input" id="fileInput" type="file" name="files[]" multiple required>
                        <strong>Pretahni soubory sem</strong>
                        <span>nebo klikni pro vyber</span>
                    </div>
                    <div class="selected-files" id="selectedFiles"></div>
                    <button class="button" type="submit">Nahrat</button>
                </form>
            </div>

            <div class="panel">
                <h2>API</h2>
                <dl class="api-list">
                    <dt>Upload</dt>
                    <dd>POST <code><?= e(app_url('api.php?action=upload')) ?></code></dd>
                    <dt>Info</dt>
                    <dd>GET <code><?= e(app_url('api.php?action=info&uid=UID')) ?></code></dd>
                    <dt>Auth</dt>
                    <dd><code>Authorization: Bearer TOKEN</code></dd>
                    <dt>Fallback</dt>
                    <dd><code>token=TOKEN</code></dd>
                    <dt>Kategorie</dt>
                    <dd><code>category=post</code></dd>
                </dl>
            </div>
        </section>

        <section class="manager-layout">
            <aside class="panel sidebar">
                <h2>Kategorie</h2>
                <a class="folder-link <?= $activeCategory === '' ? 'active' : '' ?>" href="index.php<?= $query !== '' ? '?q=' . urlencode($query) : '' ?>">
                    <span>Vse</span>
                    <b><?= array_sum(array_map(static fn (array $item): int => (int) $item['file_count'], $categories)) ?></b>
                </a>
                <?php foreach ($categories as $category): ?>
                    <?php
                    $name = (string) $category['category'];
                    $params = ['category' => $name];
                    if ($query !== '') {
                        $params['q'] = $query;
                    }
                    ?>
                    <a class="folder-link <?= $activeCategory === $name ? 'active' : '' ?>" href="index.php?<?= e(http_build_query($params)) ?>">
                        <span><?= e($name) ?></span>
                        <b><?= (int) $category['file_count'] ?></b>
                    </a>
                <?php endforeach; ?>
            </aside>

            <div class="panel">
                <div class="section-head">
                    <h2><?= $activeCategory !== '' ? e($activeCategory) : 'Nahrane soubory' ?></h2>
                    <form method="get" class="search">
                        <?php if ($activeCategory !== ''): ?>
                            <input type="hidden" name="category" value="<?= e($activeCategory) ?>">
                        <?php endif; ?>
                        <input name="q" value="<?= e($query) ?>" placeholder="Hledat nazev, uid, kategorii nebo alt">
                        <button class="button secondary" type="submit">Hledat</button>
                    </form>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nahled</th>
                            <th>Soubor</th>
                            <th>Kategorie</th>
                            <th>Typ</th>
                            <th>Velikost</th>
                            <th>Obrazek</th>
                            <th>Alt</th>
                            <th>Vytvoreno</th>
                            <th>Akce</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($files === []): ?>
                            <tr>
                                <td colspan="10" class="empty">Zatim tu nic neni.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($files as $file): ?>
                            <?php $metaFormId = 'meta-' . (int) $file['id']; ?>
                            <?php $isImage = str_starts_with((string) $file['mime_type'], 'image/'); ?>
                            <?php $fileUrl = admin_file_url($file); ?>
                            <?php $thumbUrl = $isImage ? public_file_url($file, '160x120') : $fileUrl; ?>
                            <tr>
                                <td><?= (int) $file['id'] ?></td>
                                <td>
                                    <?php if ($isImage): ?>
                                        <a class="thumb" href="<?= e($fileUrl) ?>" target="_blank" rel="noopener">
                                            <img src="<?= e($thumbUrl) ?>" alt="<?= e($file['alt_text'] ?: $file['original_name']) ?>" loading="lazy">
                                        </a>
                                    <?php else: ?>
                                        <a class="file-badge" href="<?= e($fileUrl) ?>" target="_blank" rel="noopener">
                                            <?= e(strtoupper((string) $file['extension'])) ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?= e($fileUrl) ?>" target="_blank" rel="noopener"><?= e($file['original_name']) ?></a>
                                    <small><?= e($file['uid']) ?></small>
                                    <?php if (!empty($file['sha256'])): ?>
                                        <small>sha256: <?= e($file['sha256']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <input form="<?= e($metaFormId) ?>" name="category" value="<?= e($file['category'] ?? 'general') ?>" maxlength="100" list="category-list" required>
                                </td>
                                <td><?= e($file['mime_type']) ?></td>
                                <td><?= e(admin_human_size((int) $file['size_bytes'])) ?></td>
                                <td><?= e(admin_image_details($file)) ?></td>
                                <td>
                                    <input form="<?= e($metaFormId) ?>" name="alt_text" value="<?= e($file['alt_text']) ?>" maxlength="500">
                                </td>
                                <td><?= e($file['created_at']) ?></td>
                                <td class="actions">
                                    <button class="button tiny secondary copy-url" type="button" data-url="<?= e($fileUrl) ?>">Kopirovat URL</button>
                                    <form id="<?= e($metaFormId) ?>" method="post" class="inline-meta">
                                        <input type="hidden" name="form" value="metadata">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $file['id'] ?>">
                                        <button class="button tiny" type="submit">Ulozit</button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Opravdu smazat soubor?')">
                                        <input type="hidden" name="form" value="delete">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $file['id'] ?>">
                                        <button class="link-danger" type="submit">Smazat</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    <?php endif; ?>
</main>
<script src="assets/admin.js"></script>
</body>
</html>
