# Running MyCDN with Nginx

Nginx does not read `.htaccess` files. To run MyCDN behind Nginx, put the equivalent rules in the Nginx `server` block and use PHP-FPM to execute PHP files.

## Before you start

- Install Nginx, PHP 8.2 or newer, PHP-FPM, and the PHP extensions listed in [README.md](README.md#requirements).
- Point `root` at the MyCDN project directory (the directory containing `index.php`).
- Make sure the user running PHP-FPM can write to `uploads/`.
- Set `app.base_url` in `config.php` to the public HTTPS URL, without a trailing slash.
- Find the PHP-FPM socket or TCP address used by your system. The example below uses `/run/php/php8.2-fpm.sock`; its name may be different.

## Example server block

Create a site configuration such as `/etc/nginx/sites-available/mycdn` and adjust `server_name`, `root`, the TLS settings, and `fastcgi_pass`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name cdn.example.com;

    root /var/www/mycdn;
    index index.php;

    # Keep this at least as large as the maximum upload MyCDN should accept.
    # PHP's upload_max_filesize and post_max_size must allow the same size too.
    client_max_body_size 50m;

    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "no-referrer" always;
    add_header Permissions-Policy "geolocation=(), microphone=(), camera=()" always;

    # Clean public URLs. Existing query parameters, such as image resize options,
    # are retained by rewrite (for example ?s=800x600&m=crop).
    location ~ ^/i/([A-Fa-f0-9]{32})\.[A-Za-z0-9]+$ {
        rewrite ^/i/([A-Fa-f0-9]{32})\.[A-Za-z0-9]+$ /image.php?uid=$1 last;
    }

    location ~ ^/f/([A-Fa-f0-9]{32})\.[A-Za-z0-9]+$ {
        rewrite ^/f/([A-Fa-f0-9]{32})\.[A-Za-z0-9]+$ /file.php?uid=$1 last;
    }

    # Stored originals and application source must never be served directly.
    location ^~ /uploads/ {
        deny all;
    }

    location ^~ /src/ {
        deny all;
    }

    # Do not expose configuration, repository documentation, or helper files.
    location ~* ^/(?:config\.php(?:\.example)?|README\.md|nginx\.md|schema\.sql|router\.php)$ {
        deny all;
    }

    # Block dotfiles and dot-directories, but leave ACME challenges available.
    location ~ (^|/)\.(?!well-known(?:/|$)) {
        deny all;
    }

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        try_files $uri =404;

        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

The `HTTP_AUTHORIZATION` parameter explicitly passes bearer tokens to PHP. MyCDN also accepts `X-Api-Token` and the `token` request parameter as fallbacks.

For a production deployment, add HTTPS in this server block (or use a separate HTTPS server block) and redirect plain HTTP to HTTPS. API tokens and admin credentials must not be sent over unencrypted HTTP.

## Enable and test the site

On Debian or Ubuntu, a typical setup is:

```bash
sudo ln -s /etc/nginx/sites-available/mycdn /etc/nginx/sites-enabled/mycdn
sudo nginx -t
sudo systemctl reload nginx
```

Other distributions may load virtual hosts from a directory such as `/etc/nginx/conf.d/`; place the server block where that installation expects it. Always run `nginx -t` before reloading.

Check the deployment:

1. Open `/index.php` and sign in.
2. Upload a small image.
3. Open its `/i/{uid}.{ext}` URL and test a resized URL such as `?s=800x600&m=fit`.
4. Confirm that `/uploads/`, `/src/`, `/config.php`, and `/.git/` return `403` (or are otherwise inaccessible).
5. Call an API endpoint with an `Authorization: Bearer ...` header and confirm it does not return `401` for a valid token.

## Common problems

- **`502 Bad Gateway`:** `fastcgi_pass` points to the wrong PHP-FPM socket/address, or PHP-FPM is not running.
- **`413 Request Entity Too Large`:** increase `client_max_body_size`; also verify PHP's `upload_max_filesize` and `post_max_size`.
- **Clean URLs return `404`:** make sure the two `/i/` and `/f/` locations are inside the active `server` block and that the UID contains exactly 32 hexadecimal characters.
- **API returns `401`:** retain `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`, verify the configured token, and try the supported fallback methods described in the README.
- **Nginx starts but the app cannot upload:** grant the PHP-FPM user write access to `uploads/`; do not make the entire application directory world-writable.
