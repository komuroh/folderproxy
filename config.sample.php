<?php
return [
	// 元サイト(オリジン)。末尾スラッシュなし。http:// か https://
	'origin_base' => getenv('PROXY_ORIGIN') ?: 'https://origin.example.com',
	// 元サイトが認識するホスト名。Hostヘッダとして送る(バーチャルホスト対応)
	'site_host'   => 'origin.example.com',
	// 本文・Location でプロキシURLに書き換える追加ホスト名(site_host は自動で含む)
	'rewrite_hosts' => [],
	// JS内の文字列 "/skin/..." のような、ルート直下の名前(設置フォルダ名を付けて書き換える)。
	// HTMLの href/src/action や CSSの url(/..) は、ここに無くても自動で書き換わる
	'root_names'  => ['skin', 'image', 'attach', 'plugin'],
	// 特定ページの本文を置換する: [パスの正規表現 => [[検索の正規表現, 置換後], ...]]
	'body_regex'  => [
		// '#/upload\.php$#' => [
		//     ['#chunkSize:\s*\d+#',  'chunkSize: 262144'],
		//     ['#timeout:\s*120000#', 'timeout: 0'],
		// ],
	],
	// DNSが遅い/不安定な場合のみ、元サイトのIPを直接指定(空なら通常のDNS)
	'origin_ip'   => '',
	'origin_port' => 443,

	// このプロキシにアクセスできるIP (完全一致 or IPv4のCIDR)。
	// 空配列なら制限なし(非推奨。公開前に必ず設定する)
	'allow_ips'   => [
		// '203.0.113.10',
		// '198.51.100.0/24',
		// '2001:db8::1',   // IPv6は完全一致のみ
	],

	// 静的ファイルのキャッシュ(画像/CSS/JS/フォント)。HTMLと管理画面はキャッシュしない
	'cache_dir'   => __DIR__ . '/cache',
	'cache_ttl'   => 86400,
	// キャッシュ利用時、この秒数ごとに元サイトへHEADだけ送って更新を確認する(0で確認しない)
	'cache_recheck' => 60,
	'cache_ext'   => ['jpg', 'jpeg', 'png', 'gif', 'ico', 'css', 'js', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'svg'],
	'cache_max_bytes' => 5 * 1024 * 1024,

	// 元サイトから何秒間まったくデータが来なければ切るか(全体の所要時間は制限しない)
	'timeout'     => 120,
	'verify_ssl'  => true,   // 自己署名証明書なら false
];
