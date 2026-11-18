# folderproxy

**English** | [日本語](README.ja.md)

A drop-in PHP **reverse proxy for editing** a slow or distant website through a nearby server.
Put it in any folder on any PHP host and you can browse and edit a remote site (for example a PukiWiki or CMS hosted overseas). Logins (cookies) and writes (POST, file uploads) are relayed to the origin as they are.

## Main benefit: use a fast server's fat pipe to work with your overseas server

A server you run overseas (for example in China) can be slow when reached directly from an office or home in Japan, because of congested routes or limited international links. With folderproxy on a rental server or VPS, the traffic is split in two:

```
[office / home] ── fast domestic link ──> [rental server / VPS: folderproxy] ── fat, stable link ──> [your overseas server]
```

- Users talk **only to a nearby server**. The data center's fat connection takes over the slow international route.
- Static files such as images are **cached** on the proxy. From the second request on, they are served at domestic speed without going overseas (updates are checked automatically).
- No change to the origin server and no new line contract. **Just place it on a PHP-capable rental server.**
- Logins and writes use the same path, so **the editing work itself can feel faster**.

> How much faster depends on the difference between "user → origin" and "proxy server → origin".
> If the link from the proxy server to the origin is slow, first loads and writes will not get faster; only cache hits and the user-side leg do.
> Before deploying, measure the speed from the proxy server to the origin with `curl`.

## Features
- **Runs on PHP alone**: needs `.htaccess` (Apache) and PHP (cURL). No nginx or server configuration changes.
- **Any folder, any domain**: detects its install folder and rewrites URLs in HTML, CSS, JS, redirects and cookies.
- **Relays writes**: logins, forms and multipart file uploads.
- **Static file cache**: images, CSS, JS and fonts are saved while streaming. It sends a HEAD request to the origin at a set interval and re-fetches when the file has changed.
- **Per-site tweaks**: replace parts of specific pages with regular expressions (`body_regex`).
- **IP allow-list**: only the IPs you allow can use it.

## How it differs from other proxies
Other PHP reverse proxies include [no.php](https://github.com/michaelfranzl/no.php), [php-reverse-proxy](https://github.com/nbhr/php-reverse-proxy), [revprox](https://github.com/null-dev/revprox) and [php-ultra-small-proxy](https://packagist.org/packages/szczyglis/php-ultra-small-proxy).
folderproxy aims at **editing a slow origin from a different domain or subfolder than the origin's own path**.

| | folderproxy | no.php | php-reverse-proxy | revprox | ultra-small-proxy |
|---|---|---|---|---|---|
| URL rewriting in HTML/CSS/JS | Yes | No (redirects only) | Not documented | Not documented | Yes |
| Auto-detects install folder | Yes | Subfolder OK (origin must use the same path) | Yes | Not documented | Not documented |
| Login / cookies | Adjusts Domain/Path/Secure | Domain conversion | Not documented | Not documented | Yes |
| POST and file upload | Yes (multipart) | Yes | Not documented | Not documented | POST only |
| Static file cache | Yes (with update checks) | No | Yes | No | Yes |
| Streaming large files | Yes | Not documented | Not documented | Yes | Not documented |
| Per-page body replacement | Yes (`body_regex`) | No | No | No | No |
| IP allow-list | Yes | No | Not documented | Not documented | Not documented |
| License | MIT | MIT | Apache-2.0 | MIT | MIT |

The table is based on each project's README and similar pages; the actual behavior of their code has not been verified. "Not documented" does not mean the feature is missing. Please check each project for the latest details.

Other tools fit other goals:
- Same path as the origin and no body rewriting needed → a transparent proxy such as no.php
- Just streaming large videos or files → revprox
- An anonymous web proxy for viewing arbitrary URLs → PHP-Proxy, Glype, etc.

## Requirements
- PHP 7.4 or later (8.x recommended) with the cURL extension
- Apache with `mod_rewrite` (to use `.htaccess`). See below for nginx.

## Installation
1. Upload `proxy.php`, `.htaccess` and `config.sample.php` to any folder
   (for example `https://example.com/tools/folderproxy/`).
2. Copy `config.sample.php` to `config.php` and edit it:
   - `origin_base` / `site_host`: the origin's URL and host name
   - `rewrite_hosts`: other host names the origin's HTML uses for itself (if any)
   - `allow_ips`: **always set the IPs you allow**
3. Make the `cache/` folder writable (it is created automatically, but needs permission).
4. Open the folder's URL in a browser.

Serving over HTTPS is recommended, to protect login cookies.

## Configuration (`config.php`)
| Key | Meaning |
|---|---|
| `origin_base` | Origin URL (no trailing slash) |
| `site_host` | Host name the origin recognizes (Host header) |
| `rewrite_hosts` | Other host names in page bodies to rewrite to the proxy URL |
| `root_names` | Top-level names such as `"/skin/..."` in JS strings (rewritten with the folder name) |
| `body_regex` | `[path regex => [[search, replacement], ...]]` to replace parts of a page body |
| `origin_ip` / `origin_port` | Connect to the origin's IP directly instead of using DNS |
| `allow_ips` | IPs allowed to access (exact match / IPv4 CIDR). Empty means no restriction |
| `cache_ttl` / `cache_recheck` | Cache lifetime / interval for checking the origin for updates (seconds) |
| `cache_ext` / `cache_max_bytes` | File extensions to cache / size limit per file |
| `timeout` / `verify_ssl` | Seconds of silence from the origin before giving up (total transfer time is not limited) / certificate verification |

## Cache
- Applies to the extensions in `cache_ext` (GET only). HTML and admin pages are not cached.
- The upper bound is the total size of cacheable files on the site; in practice only files you actually open are stored.
- Old cache entries are not deleted automatically. Delete `cache/` to start over.

## Limitations and notes
- **Speed depends on the origin's link.** First loads and writes do not get faster. Only cache hits and the leg from users to the proxy server do.
- Intended for small-team editing. For heavy public traffic, use dedicated software such as nginx.
- Depending on the origin, body rewriting may miss some places. Adjust with `root_names` and `body_regex`.
- With virtual hosts, requests are rejected unless the host name matches what the origin recognizes (`site_host`).
- If you publish with `allow_ips` empty, anyone can use the entrance to the origin's admin pages.

## nginx
Instead of `.htaccess`, pass requests in the install folder to `proxy.php`.

```nginx
location /tools/folderproxy/ {
    try_files /tools/folderproxy/proxy.php =404;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/tools/folderproxy/proxy.php;
    fastcgi_param SCRIPT_NAME /tools/folderproxy/proxy.php;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
location ~ ^/tools/folderproxy/(config\.php|cache/) { deny all; }
```

(Adjust to your environment. Not tested.)

## License
MIT License. See `LICENSE`.
