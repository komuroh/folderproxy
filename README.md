# folderproxy

フォルダに置くだけで動く、PHP製の**編集用リバースプロキシ**です。
遠い・遅い元サイト(例: 海外にある PukiWiki / CMS)を、近いサーバー経由で閲覧・編集できます。
ログイン(Cookie)や書き込み(POST、ファイルのアップロード)も元サイトへそのまま中継します。

A single-folder PHP reverse proxy for editing a slow or distant website through a nearby server. Drop it into any folder on any PHP host; no server configuration needed.

## 特長
- **PHPだけで動く**: `.htaccess`(Apache)とPHP(cURL)があればOK。nginx等の設定変更は不要
- **任意のフォルダ・別ドメインに置ける**: 設置フォルダを自動判定し、HTML/CSS/JS/リダイレクト/Cookie内のURLを書き換える
- **書き込みを中継**: ログイン、フォーム、multipartのファイルアップロードに対応
- **静的ファイルのキャッシュ**: 画像・CSS・JS・フォントをストリーミングしながら保存。一定間隔で元サイトへHEADを送り、更新があれば自動で取り直す
- **サイト固有の調整**: 特定ページの本文を正規表現で置換できる(`body_regex`)
- **IP制限**: 許可したIPだけがアクセスできる

## 他のプロキシとの違い
PHPで動くリバースプロキシには、[no.php](https://github.com/michaelfranzl/no.php)、[php-reverse-proxy](https://github.com/nbhr/php-reverse-proxy)、[revprox](https://github.com/null-dev/revprox)、[php-ultra-small-proxy](https://packagist.org/packages/szczyglis/php-ultra-small-proxy) などがあります。
folderproxy は、**元サイトのパスと違う場所(別ドメイン・サブフォルダ)に置いても、遅い元サイトを編集できる**ことを目的にしています。

| | folderproxy | no.php | php-reverse-proxy | revprox | ultra-small-proxy |
|---|---|---|---|---|---|
| HTML/CSS/JS 内のURL書き換え | あり | なし(リダイレクトのみ) | 記載なし | 記載なし | あり |
| 設置フォルダの自動判定 | あり | サブフォルダ可(元サイト側が同じパス前提) | あり | 記載なし | 記載なし |
| ログイン・Cookie | Domain/Path/Secure を調整 | ドメイン変換 | 記載なし | 記載なし | あり |
| POST・ファイルのアップロード | あり(multipart対応) | あり | 記載なし | 記載なし | POSTのみ |
| 静的ファイルのキャッシュ | あり(更新確認つき) | なし | あり | なし | あり |
| 大きなファイルのストリーミング | あり | 記載なし | 記載なし | あり | 記載なし |
| ページごとの本文置換 | あり(`body_regex`) | なし | なし | なし | なし |
| IP制限 | あり | なし | 記載なし | 記載なし | 記載なし |
| ライセンス | MIT | MIT | Apache-2.0 | MIT | MIT |

表は各プロジェクトのREADME等の記述から作ったもので、コードの実際の動作は確認していません。「記載なし」は、機能がないという意味ではありません。最新の内容は各プロジェクトで確認してください。

目的が違う場合は、他のものが向いています。
- 元サイトと同じパスで置ければよい、本文の書き換えが不要 → no.php のような透過プロキシ
- 大きな動画やファイルを流すだけ → revprox
- 任意のURLを見るための匿名Webプロキシ → PHP-Proxy、Glype など

## 必要なもの
- PHP 7.4 以上(8.x 推奨)、cURL 拡張
- Apache + `mod_rewrite`(`.htaccess` を使う場合)。nginx の場合は下記参照

## 設置
1. `proxy.php`、`.htaccess`、`config.sample.php` を任意のフォルダへアップロード
   (例: `https://example.com/tools/folderproxy/`)
2. `config.sample.php` を `config.php` にコピーして編集
   - `origin_base` / `site_host`: 元サイトのURLとホスト名
   - `rewrite_hosts`: 元サイトのHTMLが自分のURLとして出す別名(あれば)
   - `allow_ips`: **許可するIPを必ず設定する**
3. `cache/` フォルダを書き込み可能にする(自動で作られるが、権限が必要)
4. ブラウザで設置したフォルダのURLを開く

HTTPS での公開を推奨します(ログインのCookieを守るため)。

## 設定(`config.php`)
| キー | 意味 |
|---|---|
| `origin_base` | 元サイトのURL(末尾スラッシュなし) |
| `site_host` | 元サイトが認識するホスト名(Hostヘッダ) |
| `rewrite_hosts` | 本文内でプロキシのURLに書き換えたい別名ホスト |
| `root_names` | JS内の `"/skin/..."` のようなルート直下の名前(フォルダ名を付けて書き換え) |
| `body_regex` | `[パスの正規表現 => [[検索, 置換], ...]]` で本文を置換 |
| `origin_ip` / `origin_port` | DNSを使わず元サイトのIPを直接指定する場合 |
| `allow_ips` | アクセスを許可するIP(完全一致 / IPv4 CIDR)。空なら制限なし |
| `cache_ttl` / `cache_recheck` | キャッシュの有効期限 / 元サイトへの更新確認の間隔(秒) |
| `cache_ext` / `cache_max_bytes` | キャッシュする拡張子 / 1ファイルの上限 |
| `timeout` / `verify_ssl` | 元サイトへの通信タイムアウト / 証明書の検証 |

## キャッシュ
- 対象は `cache_ext` の拡張子(GETのみ)。HTMLや管理画面はキャッシュしません
- 上限は「サイト内のキャッシュ対象ファイルの合計」で、実際には開いたファイルだけが溜まります
- 古いキャッシュを自動削除する処理はありません。`cache/` を削除すれば溜め直されます

## 制限・注意
- **速度は元サイトの回線に依存します。** 初回の読み込みと書き込みは速くなりません。速くなるのは、キャッシュに当たる部分と、利用者から外部サーバーまでの区間です
- 想定は少人数の編集用です。大量アクセスの公開サイトには、nginx 等の専用ソフトを使ってください
- 元サイトにより、本文の書き換えで足りない箇所が出ることがあります。`root_names` や `body_regex` で調整してください
- 元サイトが認識するホスト名以外では、バーチャルホストで拒否される場合があります(`site_host`)
- `allow_ips` を空にして公開すると、誰でも元サイトの管理画面への入口を使えてしまいます

## nginx の場合
`.htaccess` の代わりに、設置フォルダ内のリクエストを `proxy.php` へ渡します。

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

(環境に合わせて調整してください。未検証です)

## ライセンス
MIT License(`LICENSE` を参照)
