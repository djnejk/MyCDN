<?php

declare(strict_types=1);

session_start();

/*
 * Nastavení doporučujeme předat přes proměnné prostředí:
 *   MYCDN_URL=https://cdn.example.com
 *   MYCDN_TOKEN=vas-api-token
 *
 * Pro rychlé lokální vyzkoušení lze hodnoty napsat přímo sem. Soubor s tokenem
 * ale nikdy neukládejte do veřejného repozitáře.
 */
$cdnUrl = rtrim((string) (getenv('MYCDN_URL') ?: 'https://cdn.example.com'), '/');
$apiToken = (string) (getenv('MYCDN_TOKEN') ?: 'SEM_VLOZTE_API_TOKEN');

if (!isset($_SESSION['example_csrf'])) {
    $_SESSION['example_csrf'] = bin2hex(random_bytes(32));
}

/** @return list<array{name: string, type: string, tmp_name: string, error: int, size: int}> */
function normalize_uploaded_files(array $files): array
{
    if (!is_array($files['name'] ?? null)) {
        return [$files];
    }

    $normalized = [];
    foreach ($files['name'] as $index => $name) {
        $normalized[] = [
            'name' => (string) $name,
            'type' => (string) ($files['type'][$index] ?? ''),
            'tmp_name' => (string) ($files['tmp_name'][$index] ?? ''),
            'error' => (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($files['size'][$index] ?? 0),
        ];
    }

    return $normalized;
}

function json_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['example_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        json_reply(['success' => false, 'error' => 'Platnost formuláře vypršela. Obnovte stránku.'], 403);
    }

    if (!extension_loaded('curl')) {
        json_reply(['success' => false, 'error' => 'Na serveru chybí PHP rozšíření cURL.'], 500);
    }

    if (!filter_var($cdnUrl, FILTER_VALIDATE_URL) || $apiToken === '' || $apiToken === 'SEM_VLOZTE_API_TOKEN') {
        json_reply(['success' => false, 'error' => 'Nejdříve nastavte MYCDN_URL a MYCDN_TOKEN v upload.php.'], 500);
    }

    if (!isset($_FILES['files'])) {
        json_reply(['success' => false, 'error' => 'Nebyl vybrán žádný soubor.'], 422);
    }

    $files = normalize_uploaded_files($_FILES['files']);
    $postFields = [
        // Fallback pro hostingy, které při předání do PHP odstraní
        // hlavičku Authorization. Token zůstává pouze mezi servery.
        'token' => $apiToken,
        'category' => trim((string) ($_POST['category'] ?? 'general')) ?: 'general',
        'alt' => trim((string) ($_POST['alt'] ?? '')),
        'metadata' => json_encode(
            ['source' => 'drag-and-drop-example'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
    ];

    foreach ($files as $index => $file) {
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            json_reply([
                'success' => false,
                'error' => 'Soubor „' . $file['name'] . '“ se nepodařilo přijmout.',
            ], 422);
        }

        $postFields['files[' . $index . ']'] = new CURLFile(
            $file['tmp_name'],
            $file['type'] ?: 'application/octet-stream',
            $file['name']
        );
    }

    $curl = curl_init($cdnUrl . '/api.php?action=upload');
    if ($curl === false) {
        json_reply(['success' => false, 'error' => 'Nepodařilo se inicializovat cURL.'], 500);
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiToken,
            'X-Api-Token: ' . $apiToken,
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 180,
    ]);

    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($body === false) {
        // Stav 424 Cloudflare nenahrazuje vlastní stránkou jako odpověď 502,
        // takže prohlížeč stále dostane konkrétní chybovou zprávu.
        json_reply(['success' => false, 'error' => 'Spojení s CDN selhalo: ' . $curlError], 424);
    }

    $response = json_decode($body, true);
    if (!is_array($response)) {
        json_reply([
            'success' => false,
            'error' => 'CDN vrátilo neplatnou odpověď (HTTP ' . $status . ').',
        ], 424);
    }

    // Chyby klienta (např. 401 nebo 422) zachováme. Chybu CDN serveru
    // převedeme na 424, aby Cloudflare nezakryl původní JSON svou 502 stránkou.
    $response['cdn_http_status'] = $status;
    $browserStatus = $status >= 200 && $status < 500 ? $status : 424;
    json_reply($response, $browserStatus);
}

$isConfigured = filter_var($cdnUrl, FILTER_VALIDATE_URL)
    && $apiToken !== ''
    && $apiToken !== 'SEM_VLOZTE_API_TOKEN';
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MyCDN – nahrání souborů</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #090b10;
            --panel: #121620;
            --panel-2: #181e2b;
            --border: #2a3243;
            --text: #f3f6fb;
            --muted: #929bad;
            --accent: #7c6cff;
            --accent-2: #4bd4b8;
            --danger: #ff7185;
        }

        * { box-sizing: border-box; }

        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 32px 18px;
            color: var(--text);
            background:
                radial-gradient(circle at 15% 10%, #392b7750, transparent 32rem),
                radial-gradient(circle at 85% 85%, #155d5550, transparent 30rem),
                var(--bg);
            font: 16px/1.5 Inter, ui-sans-serif, system-ui, -apple-system, sans-serif;
        }

        .card {
            width: min(760px, 100%);
            padding: clamp(22px, 5vw, 42px);
            border: 1px solid var(--border);
            border-radius: 24px;
            background: color-mix(in srgb, var(--panel) 94%, transparent);
            box-shadow: 0 30px 80px #0007;
        }

        h1 { margin: 0; font-size: clamp(1.8rem, 5vw, 2.7rem); line-height: 1.1; }
        .lead { margin: 10px 0 28px; color: var(--muted); }

        .notice {
            margin-bottom: 20px;
            padding: 12px 15px;
            border: 1px solid #73533c;
            border-radius: 12px;
            color: #ffd2a8;
            background: #332418;
        }

        .drop-zone {
            display: grid;
            place-items: center;
            min-height: 230px;
            padding: 28px;
            text-align: center;
            cursor: pointer;
            border: 2px dashed #424d65;
            border-radius: 18px;
            background: var(--panel-2);
            transition: border-color .2s, background .2s, transform .2s;
        }

        .drop-zone:hover, .drop-zone.is-over {
            border-color: var(--accent);
            background: #201d3b;
            transform: translateY(-2px);
        }

        .drop-zone:focus-visible { outline: 3px solid #7c6cff60; outline-offset: 4px; }
        .upload-icon { width: 54px; height: 54px; margin-bottom: 12px; color: var(--accent-2); }
        .drop-zone strong { display: block; font-size: 1.15rem; }
        .drop-zone span { display: block; margin-top: 5px; color: var(--muted); font-size: .92rem; }
        #file-input { display: none; }

        .fields {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-top: 20px;
        }

        label { display: grid; gap: 7px; color: var(--muted); font-size: .86rem; }
        input[type="text"] {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 11px;
            color: var(--text);
            background: #0d1119;
            font: inherit;
        }

        input:focus { border-color: var(--accent); outline: 2px solid #7c6cff30; }
        .file-list { display: grid; gap: 9px; margin-top: 18px; }
        .file-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 13px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #0d1119;
        }

        .file-name { min-width: 0; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .file-size { color: var(--muted); font-size: .82rem; white-space: nowrap; }
        .remove {
            border: 0;
            color: var(--muted);
            background: none;
            cursor: pointer;
            font-size: 1.3rem;
        }
        .remove:hover { color: var(--danger); }

        .submit {
            width: 100%;
            margin-top: 20px;
            padding: 14px 20px;
            border: 0;
            border-radius: 12px;
            color: white;
            background: linear-gradient(135deg, var(--accent), #5c8dff);
            font: 700 1rem/1 inherit;
            cursor: pointer;
            box-shadow: 0 10px 28px #6c62ff35;
        }

        .submit:disabled { opacity: .45; cursor: not-allowed; box-shadow: none; }
        .progress { height: 7px; margin-top: 18px; overflow: hidden; border-radius: 99px; background: #272d3a; }
        .progress[hidden] { display: none; }
        .progress-bar { width: 0; height: 100%; background: linear-gradient(90deg, var(--accent), var(--accent-2)); transition: width .15s; }
        .status { min-height: 24px; margin: 13px 0 0; color: var(--muted); }
        .status.error { color: var(--danger); }
        .results { display: grid; gap: 8px; margin-top: 14px; }
        .result-link { color: var(--accent-2); overflow-wrap: anywhere; }

        @media (max-width: 600px) {
            .fields { grid-template-columns: 1fr; }
            .card { border-radius: 18px; }
            .drop-zone { min-height: 190px; }
        }
    </style>
</head>
<body>
<main class="card">
    <h1>Nahrát na MyCDN</h1>
    <p class="lead">Přetáhněte soubory do plochy nebo je vyberte ze zařízení.</p>

    <?php if (!$isConfigured): ?>
        <div class="notice">Před prvním uploadem nastavte <code>MYCDN_URL</code> a <code>MYCDN_TOKEN</code>.</div>
    <?php endif; ?>

    <form id="upload-form" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['example_csrf'], ENT_QUOTES, 'UTF-8') ?>">

        <div class="drop-zone" id="drop-zone" role="button" tabindex="0" aria-controls="file-input">
            <svg class="upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                <path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5M5 14v4a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4"/>
            </svg>
            <div>
                <strong>Přetáhněte soubory sem</strong>
                <span>nebo klikněte pro výběr · lze vybrat více souborů</span>
            </div>
        </div>
        <input id="file-input" type="file" name="files[]" multiple>

        <div class="fields">
            <label>
                Kategorie
                <input type="text" name="category" value="general" maxlength="100">
            </label>
            <label>
                Alternativní text
                <input type="text" name="alt" placeholder="Popis souborů" maxlength="500">
            </label>
        </div>

        <div class="file-list" id="file-list" aria-live="polite"></div>
        <button class="submit" id="submit" type="submit" disabled>Nahrát soubory</button>
        <div class="progress" id="progress" hidden><div class="progress-bar" id="progress-bar"></div></div>
        <p class="status" id="status" aria-live="polite"></p>
        <div class="results" id="results"></div>
    </form>
</main>

<script>
    const form = document.querySelector('#upload-form');
    const dropZone = document.querySelector('#drop-zone');
    const fileInput = document.querySelector('#file-input');
    const fileList = document.querySelector('#file-list');
    const submitButton = document.querySelector('#submit');
    const progress = document.querySelector('#progress');
    const progressBar = document.querySelector('#progress-bar');
    const status = document.querySelector('#status');
    const results = document.querySelector('#results');
    let selectedFiles = [];

    function formatSize(bytes) {
        if (bytes < 1024) return `${bytes} B`;
        const units = ['KB', 'MB', 'GB'];
        let size = bytes / 1024;
        let unit = 0;
        while (size >= 1024 && unit < units.length - 1) {
            size /= 1024;
            unit++;
        }
        return `${size.toFixed(size < 10 ? 1 : 0)} ${units[unit]}`;
    }

    function renderFiles() {
        fileList.replaceChildren();
        selectedFiles.forEach((file, index) => {
            const row = document.createElement('div');
            row.className = 'file-item';

            const name = document.createElement('span');
            name.className = 'file-name';
            name.textContent = file.name;

            const size = document.createElement('span');
            size.className = 'file-size';
            size.textContent = formatSize(file.size);

            const remove = document.createElement('button');
            remove.className = 'remove';
            remove.type = 'button';
            remove.setAttribute('aria-label', `Odebrat ${file.name}`);
            remove.textContent = '×';
            remove.addEventListener('click', () => {
                selectedFiles.splice(index, 1);
                renderFiles();
            });

            row.append(name, size, remove);
            fileList.append(row);
        });

        submitButton.disabled = selectedFiles.length === 0;
        submitButton.textContent = selectedFiles.length
            ? `Nahrát (${selectedFiles.length})`
            : 'Nahrát soubory';
    }

    function addFiles(files) {
        for (const file of files) {
            const duplicate = selectedFiles.some(item =>
                item.name === file.name && item.size === file.size && item.lastModified === file.lastModified
            );
            if (!duplicate) selectedFiles.push(file);
        }
        renderFiles();
    }

    dropZone.addEventListener('click', () => fileInput.click());
    dropZone.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            fileInput.click();
        }
    });
    fileInput.addEventListener('change', () => {
        addFiles(fileInput.files);
        fileInput.value = '';
    });

    ['dragenter', 'dragover'].forEach(type => dropZone.addEventListener(type, event => {
        event.preventDefault();
        dropZone.classList.add('is-over');
    }));
    ['dragleave', 'drop'].forEach(type => dropZone.addEventListener(type, event => {
        event.preventDefault();
        dropZone.classList.remove('is-over');
    }));
    dropZone.addEventListener('drop', event => addFiles(event.dataTransfer.files));

    form.addEventListener('submit', event => {
        event.preventDefault();
        if (!selectedFiles.length) return;

        const data = new FormData(form);
        data.delete('files[]');
        selectedFiles.forEach(file => data.append('files[]', file, file.name));

        submitButton.disabled = true;
        progress.hidden = false;
        progressBar.style.width = '0%';
        status.className = 'status';
        status.textContent = 'Nahrávám…';
        results.replaceChildren();

        const request = new XMLHttpRequest();
        request.open('POST', window.location.href);
        request.responseType = 'json';
        request.upload.addEventListener('progress', uploadEvent => {
            if (uploadEvent.lengthComputable) {
                progressBar.style.width = `${Math.round(uploadEvent.loaded / uploadEvent.total * 100)}%`;
            }
        });

        request.addEventListener('load', () => {
            const response = request.response;
            if (request.status < 200 || request.status >= 300 || !response?.success) {
                const firstError = response?.errors?.[0]?.error;
                throwUploadError(response?.error || firstError || 'Nahrávání se nezdařilo.');
                return;
            }

            progressBar.style.width = '100%';
            status.textContent = `Hotovo — nahráno souborů: ${response.files?.length || 0}.`;
            for (const file of response.files || []) {
                const link = document.createElement('a');
                link.className = 'result-link';
                link.textContent = file.url || file.original_name || 'Nahraný soubor';
                if (typeof file.url === 'string' && /^https?:\/\//i.test(file.url)) {
                    link.href = file.url;
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                }
                results.append(link);
            }
            selectedFiles = [];
            renderFiles();
        });

        request.addEventListener('error', () => throwUploadError('Server není dostupný.'));
        request.send(data);
    });

    function throwUploadError(message) {
        status.className = 'status error';
        status.textContent = message;
        progress.hidden = true;
        submitButton.disabled = selectedFiles.length === 0;
    }
</script>
</body>
</html>
