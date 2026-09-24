# MyCDN

MyCDN is a small self-hosted PHP file CDN/storage app with an admin panel, MySQL metadata storage, token-protected API uploads, categories, image previews, and cached dynamic image resizing.

## Features

- Admin login configured in `config.php`
- Drag and drop file uploads in the admin panel
- File categories for easier browsing and filtering
- Per-file alt text
- API uploads from other applications
- Multiple file uploads in one request
- File metadata stored in MySQL
- SHA-256 hashing
- Image width, height, and color scheme detection
- Public image endpoint with cached resizing
- Non-image files served through a controlled download endpoint
- Direct access to `uploads/` blocked by rewrite rules
- Configurable database table prefix
- Public-safe `config.php.example`; real `config.php` is ignored by Git

## Requirements

- PHP 8.2 or newer
- MySQL or MariaDB
- PDO MySQL extension
- Fileinfo extension
- GD extension for dynamic image resizing
- Apache with `mod_rewrite`, or Nginx with PHP-FPM, for clean image URLs in production

## Installation

1. Copy `config.php.example` to `config.php`.
2. Edit `config.php`:
   - `app.base_url`
   - database credentials
   - admin username and password hash
   - API tokens
   - allowed file extensions
3. Import `schema.sql`, or keep `app.auto_migrate` enabled.
4. Make sure PHP can write to `uploads/`.
5. Point your web server document root to this project.

Apache uses the included `.htaccess`. For Nginx, which does not read `.htaccess`, follow the [Nginx configuration guide](nginx.md).

In production, direct access to `uploads/` should remain blocked. Public file access should go through:

```text
/i/{uid}.{ext}
/f/{uid}.{ext}
```

For the PHP built-in development server, use `router.php` because `php -S` does not read `.htaccess`:

```bash
php -S localhost:8000 -t . router.php
```

## Configuration

The real config file must be named:

```text
config.php
```

### CORS

Cross-origin access to the API, images, and downloads is controlled in `config.php`:

```php
'cors' => [
    'allowed_origins' => [
        'https://www.example.com',
        'https://admin.example.com',
    ],
],
```

Origins must be exact and must not contain a trailing slash. To allow requests from every website, use:

```php
'cors' => [
    'allowed_origins' => ['*'],
],
```

Use an empty array (`[]`) to disable cross-origin access. The wildcard mode does not enable credentialed browser requests (cookies); API tokens should be sent in the `Authorization` or `X-Api-Token` header.

Keep it out of Git. This repository includes:

```text
config.php.example
```

Generate an admin password hash:

```bash
php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
```

Generate an API token:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Table names use the configured prefix:

```php
'table_prefix' => 'CDN_',
```

With that prefix, the main table is:

```text
CDN_files
```

## Admin Panel

The admin panel is available at:

```text
/index.php
```

It supports:

- drag and drop uploads
- multiple files at once
- per-file alt text
- categories
- folder-like category filtering
- image thumbnails
- copy URL button
- metadata editing
- file deletion

## API Authentication

All API endpoints require a token from `config.php`.

### Authentication depends on the server

How an authentication token reaches PHP can differ between web servers and hosting setups. Apache modules, CGI/FastCGI handlers, Nginx, reverse proxies, and some shared hosting providers may handle the `Authorization` header differently. A request can therefore return `401 Unauthorized` even when the token itself is correct, simply because PHP never received the header.

MyCDN supports three authentication methods for this reason:

| Method | Recommended use |
|---|---|
| `Authorization: Bearer YOUR_TOKEN` | Preferred method when the server forwards the `Authorization` header to PHP. |
| `X-Api-Token: YOUR_TOKEN` | Header fallback when `Authorization` is removed by the server or proxy. |
| `token=YOUR_TOKEN` | Form-field fallback for uploads and query-parameter fallback for GET requests. |

MyCDN checks them in the order shown above. If a client sends the token using multiple methods, all supplied values should be identical.

Recommended header:

```http
Authorization: Bearer YOUR_TOKEN
```

Recommended fallback for shared hosting:

```text
token=YOUR_TOKEN
```

You can send `token` as a regular `POST` form field during uploads. This is useful on hosting setups where Apache/PHP does not expose the `Authorization` header to the application.

Alternative header:

```http
X-Api-Token: YOUR_TOKEN
```

Unauthorized requests return:

```json
{
  "success": false,
  "error": "Unauthorized."
}
```

If a correct token still returns `401 Unauthorized`:

1. Confirm that the token exactly matches an entry in `api_tokens` in the deployed `config.php`.
2. Try `X-Api-Token`, or include `token` as a multipart form field when uploading.
3. Check whether the web server or reverse proxy forwards `Authorization` to PHP.
4. Confirm that the request is being sent to the intended MyCDN installation.

Always use HTTPS when sending API tokens. Prefer headers or a POST form field; a token in a GET query string may be stored in browser history, access logs, or monitoring systems. Never expose a privileged CDN token in public browser-side JavaScript. If a server-side upload form contains the token, protect that form with its own login or access control so it cannot become a public upload proxy.

## Upload Files

```http
POST /api.php?action=upload
```

### Parameters

| Parameter | Type | Required | Description |
|---|---:|---:|---|
| `file` | file | yes/no | Upload one file. Use either `file` or `files[]`. |
| `files[]` | file[] | yes/no | Upload one or more files. |
| `category` | string | no | File category, for example `post`. Defaults to `general`. |
| `alt` | string | no | One alt text for all uploaded files. |
| `alt_text` | string | no | Alias for `alt`. |
| `alt_texts[]` | string[] | no | Per-file alt text, matched by upload order. |
| `alts[]` | string[] | no | Alias for `alt_texts[]`. |
| `metadata` | string/JSON | no | Custom metadata. Valid JSON is stored as structured data. |

Categories are created implicitly. If you upload with `category=post`, files are assigned to `post`. If the category does not exist yet, it appears after the first uploaded file.

By default, risky script-like and active document formats are not allowed. SVG uploads are disabled because SVG can contain JavaScript and other active content.

### cURL Example

```bash
curl -X POST "https://cdn.example.com/api.php?action=upload" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -F "token=YOUR_TOKEN" \
  -F "files[]=@image.jpg" \
  -F "files[]=@document.pdf" \
  -F "category=post" \
  -F "metadata={\"source\":\"posts\",\"post_id\":123}" \
  -F "alt_texts[]=Main post image" \
  -F "alt_texts[]=PDF attachment"
```

### PHP Example

```php
<?php

$ch = curl_init('https://cdn.example.com/api.php?action=upload');

$post = [
    'token' => 'YOUR_TOKEN',
    'files[]' => new CURLFile(__DIR__ . '/image.jpg'),
    'category' => 'post',
    'alt_texts[]' => 'Main post image',
    'metadata' => json_encode([
        'source' => 'posts',
        'post_id' => 123,
    ], JSON_UNESCAPED_UNICODE),
];

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $post,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer YOUR_TOKEN',
    ],
    CURLOPT_RETURNTRANSFER => true,
]);

$response = curl_exec($ch);
$data = json_decode($response, true);
curl_close($ch);
```

### Successful Response

```json
{
  "success": true,
  "files": [
    {
      "id": 1,
      "uid": "731865305ed6bf5414f88cd313baf838",
      "original_name": "image.jpg",
      "stored_name": "731865305ed6bf5414f88cd313baf838.jpg",
      "mime_type": "image/jpeg",
      "extension": "jpg",
      "size_bytes": 185686,
      "size_human": "181.33 KB",
      "sha256": "f2ca1bb6c7e907d06dafe4687e579fce...",
      "metadata": {
        "original_name": "image.jpg",
        "extension": "jpg",
        "client_mime_type": "image/jpeg",
        "detected_mime_type": "image/jpeg",
        "image": {
          "width": 1920,
          "height": 1080,
          "color_scheme": "rgb",
          "bits": 8,
          "channels": 3
        },
        "custom": {
          "source": "posts",
          "post_id": 123
        }
      },
      "width": 1920,
      "height": 1080,
      "color_scheme": "rgb",
      "category": "post",
      "alt_text": "Main post image",
      "url": "https://cdn.example.com/i/731865305ed6bf5414f88cd313baf838.jpg",
      "relative_path": "uploads/2026/08/731865305ed6bf5414f88cd313baf838.jpg",
      "uploaded_by": "api",
      "created_at": "2026-08-01 18:00:00"
    }
  ],
  "errors": []
}
```

For multi-file uploads, partial success is possible. In that case, `files` contains successfully uploaded files and `errors` contains failed items.

## Get File Info

```http
GET /api.php?action=info&uid=UID
GET /api.php?action=info&id=ID
```

### cURL Example

```bash
curl "https://cdn.example.com/api.php?action=info&uid=731865305ed6bf5414f88cd313baf838" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

If your hosting strips the `Authorization` header:

```bash
curl "https://cdn.example.com/api.php?action=info&uid=731865305ed6bf5414f88cd313baf838&token=YOUR_TOKEN"
```

### Response

```json
{
  "success": true,
  "file": {
    "id": 1,
    "uid": "731865305ed6bf5414f88cd313baf838",
    "original_name": "image.jpg",
    "stored_name": "731865305ed6bf5414f88cd313baf838.jpg",
    "mime_type": "image/jpeg",
    "extension": "jpg",
    "size_bytes": 185686,
    "size_human": "181.33 KB",
    "sha256": "f2ca1bb6c7e907d06dafe4687e579fce...",
    "metadata": {},
    "width": 1920,
    "height": 1080,
    "color_scheme": "rgb",
    "category": "post",
    "alt_text": "Main post image",
    "url": "https://cdn.example.com/i/731865305ed6bf5414f88cd313baf838.jpg",
    "relative_path": "uploads/2026/08/731865305ed6bf5414f88cd313baf838.jpg",
    "uploaded_by": "api",
    "created_at": "2026-08-01 18:00:00"
  }
}
```

## List Files

```http
GET /api.php?action=list
GET /api.php?action=list&category=post
GET /api.php?action=list&q=logo
GET /api.php?action=list&category=post&q=hero&limit=50
```

### Parameters

| Parameter | Type | Required | Description |
|---|---:|---:|---|
| `category` | string | no | Filter by category. |
| `q` | string | no | Search in UID, SHA-256, original name, alt text, and category. |
| `limit` | int | no | Number of returned files. Maximum is 200. Defaults to 100. |

### cURL Example

```bash
curl "https://cdn.example.com/api.php?action=list&category=post&limit=50" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Fallback:

```bash
curl "https://cdn.example.com/api.php?action=list&category=post&limit=50&token=YOUR_TOKEN"
```

### Response

```json
{
  "success": true,
  "files": []
}
```

## Dynamic Images

Image files are served through the public image endpoint:

```text
https://cdn.example.com/i/UID.jpg
```

Resize to fit inside a maximum box:

```text
https://cdn.example.com/i/UID.jpg?s=1920x1080
```

Resize and center-crop to exact dimensions:

```text
https://cdn.example.com/i/UID.jpg?s=800x600&m=crop
```

### Image Parameters

| Parameter | Values | Description |
|---|---|---|
| `s` | `WIDTHxHEIGHT` | Requested image size. |
| `m` | `fit`, `crop` | `fit` keeps aspect ratio. `crop` creates exact dimensions with center crop. |

The default mode is `fit`. In `fit` mode, smaller images are not upscaled. Generated variants are cached here:

```text
uploads/cache/{uid}/{WIDTH}x{HEIGHT}_{mode}.{ext}
```

## Error Responses

Common API errors:

```json
{
  "success": false,
  "error": "Unauthorized."
}
```

```json
{
  "success": false,
  "error": "File not found."
}
```

```json
{
  "success": false,
  "error": "Missing file field. Use file or files[]."
}
```

HTTP status codes:

| Status | Meaning |
|---:|---|
| `200` | OK |
| `401` | Missing or invalid API token |
| `404` | File or action not found |
| `405` | Invalid HTTP method |
| `422` | Upload or validation error |
| `500` | Server error |

## Stored Metadata

The database stores file metadata only. Public URLs are derived from `app.base_url`, `uid`, `extension`, and `relative_path`.

| Column | Description |
|---|---|
| `uid` | Public file identifier used in image URLs. |
| `original_name` | Original uploaded filename. |
| `stored_name` | Physical stored filename. |
| `relative_path` | Relative path to the original file. |
| `mime_type` | Detected MIME type. |
| `extension` | File extension. |
| `size_bytes` | File size in bytes. |
| `sha256` | SHA-256 hash. |
| `metadata` | JSON metadata. |
| `width` / `height` | Image dimensions, otherwise `null`. |
| `color_scheme` | For example `rgb`, `rgba`, `cmyk`, `grayscale`, `indexed`, `vector`, or `unknown`. |
| `category` | File category, for example `post`. |
| `alt_text` | Alt text. |
| `uploaded_by` | Upload source, for example `api` or `admin`. |
| `created_at` | Upload timestamp. |
