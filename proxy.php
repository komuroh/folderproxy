<?php
// folderproxy: 社内 -> このサーバー -> 遅い/遠い元サイト(PukiWiki 等)の編集用リバースプロキシ
// ・Cookie(PHPセッション)とPOST(multipart含む)をそのまま中継
// ・本文中の中国側URLをプロキシのURLに書き換え
// ・静的ファイルのみキャッシュ

if (!is_file(__DIR__ . '/config.php')) {
	http_response_code(500);
	exit('config.php がありません。config.sample.php を config.php にコピーして編集してください。');
}
$cfg = require __DIR__ . '/config.php';

// 大きなファイルでも途中で切れないように。ブラウザが閉じられてもキャッシュ保存は最後まで続ける
@set_time_limit(0);
ignore_user_abort(true);
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) ob_end_clean();

function ip_allowed($ip, array $list) {
	if (!$list) return true;
	foreach ($list as $e) {
		if (strpos($e, '/') === false) { if ($ip === $e) return true; continue; }
		list($net, $bits) = explode('/', $e);
		$bits = (int)$bits;
		if ($bits < 0 || $bits > 32) continue;
		$mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
		if ((ip2long($ip) & $mask) === (ip2long($net) & $mask)) return true;
	}
	return false;
}

if (!ip_allowed($_SERVER['REMOTE_ADDR'], $cfg['allow_ips'])) {
	http_response_code(403);
	exit('Forbidden');
}

$https      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443;
$host_base   = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
// 設置フォルダを自動判定 (例: https://example.com/tools/folderproxy/proxy.php -> /tools/folderproxy)
$base_path   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$proxy_base  = $host_base . $base_path;
$origin     = rtrim($cfg['origin_base'], '/');
$origin_host = $cfg['site_host'] ?? parse_url($origin, PHP_URL_HOST);   // 書換対象/Hostヘッダ(公開ホスト名)
$site = 'https://' . $origin_host;
// 本文/Locationを書き換える対象ホスト一覧(site_host + rewrite_hosts)
$rw_hosts = array_values(array_unique(array_merge([$origin_host], $cfg['rewrite_hosts'] ?? [])));
$origin_scheme = parse_url($origin, PHP_URL_SCHEME);

$method = $_SERVER['REQUEST_METHOD'];
// 中国側へ送るパス: 設置フォルダ名と proxy.php を取り除く
$uri = $_SERVER['REQUEST_URI'];
if ($base_path !== '' && strpos($uri, $base_path) === 0) $uri = substr($uri, strlen($base_path));
$uri = preg_replace('#^/proxy\.php(?=[/?]|$)#', '', $uri);
if ($uri === '' || $uri[0] !== '/') $uri = '/' . $uri;

// ---- 静的ファイルキャッシュ(GETのみ) ----
$path = parse_url($uri, PHP_URL_PATH);
$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$cacheable = ($method === 'GET' && in_array($ext, $cfg['cache_ext'], true));
$cache_file = $cache_meta = null;
if ($cacheable) {
	if (!is_dir($cfg['cache_dir'])) @mkdir($cfg['cache_dir'], 0755, true);
	$key = md5($uri);
	$cache_file = "{$cfg['cache_dir']}/$key.bin";
	$cache_meta = "{$cfg['cache_dir']}/$key.json";
	if (is_file($cache_file) && is_file($cache_meta) && time() - filemtime($cache_file) < $cfg['cache_ttl']) {
		$m = json_decode(file_get_contents($cache_meta), true) ?: [];
		$recheck = (int)($cfg['cache_recheck'] ?? 60);
		$valid = true;
		// 前回の確認から $recheck 秒たっていたら、中国側にHEADだけ送って更新日時/ETag/サイズを比べる(本体は取らない)
		if ($recheck > 0 && time() - ($m['checked'] ?? 0) >= $recheck) {
			$hh = [];
			$hc = curl_init($origin . $uri);
			curl_setopt_array($hc, [
				CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_HTTPHEADER => ['Host: ' . $origin_host],
				CURLOPT_SSL_VERIFYPEER => $cfg['verify_ssl'], CURLOPT_SSL_VERIFYHOST => $cfg['verify_ssl'] ? 2 : 0,
				CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$hh) {
					if (strpos($l, ':') !== false) { [$k, $v] = explode(':', $l, 2); $hh[strtolower(trim($k))] = trim($v); }
					return strlen($l);
				},
			]);
			if ($cfg['origin_ip'] !== '') curl_setopt($hc, CURLOPT_RESOLVE, ["$origin_host:{$cfg['origin_port']}:{$cfg['origin_ip']}"]);
			curl_exec($hc);
			$hs = curl_getinfo($hc, CURLINFO_HTTP_CODE);
			curl_close($hc);
			if ($hs === 200) {   // 確認できた場合だけ判定(失敗・タイムアウト時は手元のキャッシュを使う)
				$same = ($hh['last-modified'] ?? '') === ($m['lm'] ?? '')
				     && ($hh['etag'] ?? '') === ($m['etag'] ?? '')
				     && ($hh['content-length'] ?? '') === ($m['len'] ?? '');
				if ($same) { $m['checked'] = time(); @file_put_contents($cache_meta, json_encode($m)); }
				else $valid = false;
			} elseif ($hs >= 400) {
				$valid = false;   // 中国側で削除された
			}
		}
		if ($valid) {
			header('Content-Type: ' . ($m['type'] ?? 'application/octet-stream'));
			header('Cache-Control: public, max-age=' . max(0, $recheck));
			header('X-Proxy-Cache: HIT');
			readfile($cache_file);
			exit;
		}
	}
}

// キャッシュに添える情報(更新確認用の更新日時/ETag/サイズ)
function cache_meta($h) {
	return ['type' => $h['content-type'] ?? 'application/octet-stream', 'lm' => $h['last-modified'] ?? '',
	        'etag' => $h['etag'] ?? '', 'len' => $h['content-length'] ?? '', 'checked' => time()];
}

// ---- リクエスト組み立て ----
function flatten_post($data, $prefix = '') {
	$out = [];
	foreach ($data as $k => $v) {
		$name = $prefix === '' ? $k : "{$prefix}[{$k}]";
		if (is_array($v)) $out += flatten_post($v, $name); else $out[$name] = $v;
	}
	return $out;
}
function flatten_files($files) {
	// $_FILES を name[sub] 形式に展開
	$out = [];
	foreach ($files as $field => $f) {
		if (is_array($f['name'])) {
			$walk = function ($n, $t, $e, $p, $prefix) use (&$walk, &$out) {
				foreach ($n as $i => $name) {
					$pf = "{$prefix}[{$i}]";
					if (is_array($name)) $walk($name, $t[$i], $e[$i], $p[$i], $pf);
					elseif ($e[$i] === UPLOAD_ERR_OK) $out[$pf] = new CURLFile($p[$i], $t[$i], $name);
				}
			};
			$walk($f['name'], $f['type'], $f['error'], $f['tmp_name'], $field);
		} elseif ($f['error'] === UPLOAD_ERR_OK) {
			$out[$field] = new CURLFile($f['tmp_name'], $f['type'], $f['name']);
		}
	}
	return $out;
}

$req_headers = ['Host: ' . $origin_host];
foreach (['Cookie' => 'HTTP_COOKIE', 'User-Agent' => 'HTTP_USER_AGENT', 'Accept' => 'HTTP_ACCEPT',
          'Accept-Language' => 'HTTP_ACCEPT_LANGUAGE', 'If-None-Match' => 'HTTP_IF_NONE_MATCH',
          'If-Modified-Since' => 'HTTP_IF_MODIFIED_SINCE', 'X-Requested-With' => 'HTTP_X_REQUESTED_WITH'] as $h => $s) {
	if (!empty($_SERVER[$s])) $req_headers[] = "$h: {$_SERVER[$s]}";
}
$req_headers[] = 'X-Forwarded-For: ' . $_SERVER['REMOTE_ADDR'];
foreach (['HTTP_REFERER' => 'Referer', 'HTTP_ORIGIN' => 'Origin'] as $s => $h) {
	if (!empty($_SERVER[$s])) {
		// Referer は設置フォルダ付き、Origin はホストのみ。どちらも中国側のURLに戻す
		$v = str_replace($proxy_base, $site, $_SERVER[$s]);
		if ($h === 'Origin') $v = str_replace($host_base, $site, $v);
		$req_headers[] = "$h: $v";
	}
}

$ch = curl_init($origin . $uri);
curl_setopt_array($ch, [
	CURLOPT_CUSTOMREQUEST  => $method,
	CURLOPT_HTTPHEADER     => $req_headers,
	CURLOPT_FOLLOWLOCATION => false,   // リダイレクトはブラウザに返す(Locationは書き換え)
	CURLOPT_ENCODING       => '',      // gzip等は自動展開
	CURLOPT_TIMEOUT        => $cfg['timeout'],
	CURLOPT_CONNECTTIMEOUT => 20,
	CURLOPT_SSL_VERIFYPEER => $cfg['verify_ssl'],
	CURLOPT_SSL_VERIFYHOST => $cfg['verify_ssl'] ? 2 : 0,
]);
if ($cfg['origin_ip'] !== '') {
	curl_setopt($ch, CURLOPT_RESOLVE, ["$origin_host:{$cfg['origin_port']}:{$cfg['origin_ip']}"]);
}
if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
	$ctype = $_SERVER['CONTENT_TYPE'] ?? '';
	if (stripos($ctype, 'multipart/form-data') === 0) {
		// 境界は cURL が付け直す
		curl_setopt($ch, CURLOPT_POSTFIELDS, flatten_post($_POST) + flatten_files($_FILES));
	} else {
		curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
		if ($ctype !== '') curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($req_headers, ["Content-Type: $ctype"]));
	}
}

// ---- レスポンス処理(テキストはバッファして書換、それ以外はストリーム) ----
$is_text = false; $status = 200; $resp_headers = []; $mode = null; $buf = ''; $ctype_resp = ''; $cookies = [];
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($c, $line) use (&$status, &$resp_headers, &$cookies) {
	$t = trim($line);
	if (stripos($t, 'HTTP/') === 0) {            // 100 Continue やリダイレクト後の再開に備えリセット
		$status = (int)explode(' ', $t)[1]; $resp_headers = []; $cookies = [];
	} elseif (strpos($t, ':') !== false) {
		list($k, $v) = explode(':', $t, 2);
		$k = strtolower(trim($k)); $v = trim($v);
		if ($k === 'set-cookie') $cookies[] = $v; else $resp_headers[$k] = $v;
	}
	return strlen($line);
});

// "/skin/x.js" のような / 始まりの相対URLに設置フォルダ名を付ける
function rewrite_root_relative($s, $base_path, array $root_names) {
	if ($base_path === '') return $s;
	$b = preg_quote($base_path, '#');
	$names = $root_names ? implode('|', array_map(function ($n) { return preg_quote($n, '#'); }, $root_names)) : '(?!)';
	// href/src/action 属性
	$s = preg_replace('#\b(href|src|action|data-src|data-href|poster)(\s*=\s*)(["\'])/(?!/|' . ltrim($b, '/') . '(?:/|["\']))#i', '$1$2$3' . $base_path . '/', $s);
	// CSS: url(/..)  @import "/.."
	$s = preg_replace('#url\(\s*(["\']?)/(?!/|' . ltrim($b, '/') . '/)#i', 'url($1' . $base_path . '/', $s);
	$s = preg_replace('#(@import\s+)(["\'])/(?!/)#i', '$1$2' . $base_path . '/', $s);
	// JS の文字列 "/skin/..." "/akanri.php" など(既知のルート直下名のみ)
	$s = preg_replace('#(["\'])/(?=(?:' . $names . ')(?:[/.?"\']))#', '$1' . $base_path . '/', $s);
	return $s;
}
function rewrite_url_text($s, array $hosts, $proxy_base) {
	$p_host = preg_replace('#^https?:#', '', $proxy_base);   // //host/設置フォルダ
	$pairs = [];
	foreach ($hosts as $o_host) {
		$pairs += [
			"https://$o_host"   => $proxy_base,
			"http://$o_host"    => $proxy_base,
			"https:\\/\\/$o_host" => str_replace('/', '\\/', $proxy_base),
			"http:\\/\\/$o_host"  => str_replace('/', '\\/', $proxy_base),
			"//$o_host"         => $p_host,                          // $p_host は // で始まる
			"\\/\\/$o_host"       => str_replace('/', '\\/', $p_host),
			// URLエンコード済み(OAuthのredirect_uri等)
			"https%3A%2F%2F$o_host" => rawurlencode($proxy_base),
			"http%3A%2F%2F$o_host"  => rawurlencode($proxy_base),
		];
	}
	return strtr($s, $pairs);
}
function send_head($status, $h, $cookies, $rw_hosts, $proxy_base, $https, $skip_len) {
	http_response_code($status);
	header('X-Proxy-Rewrite: ' . implode(',', $rw_hosts));   // 動作確認用: 反映済みなら書換対象ホストが出る
	foreach (['content-type', 'cache-control', 'expires', 'last-modified', 'etag', 'content-disposition', 'content-language', 'pragma', 'x-robots-tag'] as $k) {
		if (isset($h[$k])) header($k . ': ' . $h[$k]);
	}
	if (!$skip_len && isset($h['content-length']) && !isset($h['content-encoding'])) header('Content-Length: ' . $h['content-length']);
	$base_path = (string)parse_url($proxy_base, PHP_URL_PATH);
	if (isset($h['location'])) {
		$loc = rewrite_url_text($h['location'], $rw_hosts, $proxy_base);
		if ($base_path !== '' && $loc !== '' && $loc[0] === '/' && substr($loc, 0, 2) !== '//' && strpos($loc, $base_path . '/') !== 0) $loc = $base_path . $loc;
		header('Location: ' . $loc);
	}
	foreach ($cookies as $c) {
		if ($base_path !== '') {   // 同じドメインの他サイトと混ざらないよう、Pathを設置フォルダ内に限定
			if (preg_match('/;\s*path=/i', $c)) $c = preg_replace('#;\s*path=/?([^;]*)#i', '; path=' . $base_path . '/$1', $c);
			else $c .= '; path=' . $base_path . '/';
		}
		$c = preg_replace('/;\s*domain=[^;]*/i', '', $c);
		if (!$https) $c = preg_replace('/;\s*secure/i', '', $c);
		header('Set-Cookie: ' . $c, false);
	}
}

// 画像など書き換え不要のキャッシュ対象は、ダウンロードしながらブラウザへ流し(tee)、同時にキャッシュへ保存する
$tee = null; $tee_tmp = null; $tee_len = -1;
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($c, $data) use (&$mode, &$buf, &$status, &$resp_headers, &$cookies, &$is_text, &$tee, &$tee_tmp, &$tee_len, $cacheable, $cache_file, $cfg, $rw_hosts, $proxy_base, $https) {
	if ($mode === null) {
		$ct = strtolower($resp_headers['content-type'] ?? '');
		$is_text = (bool)preg_match('#^(text/|application/(json|javascript|xml|x-javascript|xhtml))#', $ct) || strpos($ct, '+xml') !== false;
		$clen = isset($resp_headers['content-length']) ? (int)$resp_headers['content-length'] : -1;
		if ($is_text) {
			$mode = 'buffer';
		} elseif ($cacheable && $status === 200 && $clen > 0 && $clen <= $cfg['cache_max_bytes'] && !isset($resp_headers['content-encoding'])) {
			$mode = 'tee'; $tee_len = $clen;
			$tee_tmp = $cache_file . '.' . getmypid() . '.tmp';
			$tee = @fopen($tee_tmp, 'wb');
			send_head($status, $resp_headers, $cookies, $rw_hosts, $proxy_base, $https, false);
		} else {
			$mode = 'stream';
			send_head($status, $resp_headers, $cookies, $rw_hosts, $proxy_base, $https, false);
		}
	}
	if ($mode === 'buffer') { $buf .= $data; return strlen($data); }
	echo $data;
	if ($tee) fwrite($tee, $data);
	flush();
	return strlen($data);
});

$ok = curl_exec($ch);
if ($ok === false) {
	http_response_code(502);
	header('Content-Type: text/plain; charset=UTF-8');
	echo "Bad Gateway: " . curl_error($ch);
	exit;
}
curl_close($ch);

if ($mode === 'tee') {   // 全体を受け取れた場合だけキャッシュとして確定
	if ($tee) {
		fclose($tee);
		if (filesize($tee_tmp) === $tee_len) {
			rename($tee_tmp, $cache_file);
			file_put_contents($cache_meta, json_encode(cache_meta($resp_headers)));
		} else {
			@unlink($tee_tmp);
		}
	}
	exit;
}

if ($mode === null || $mode === 'buffer') {      // 本文なし(304/リダイレクト等) / テキスト / キャッシュ対象
	$body = $is_text ? rewrite_root_relative(rewrite_url_text($buf, $rw_hosts, $proxy_base), $base_path, $cfg['root_names'] ?? []) : $buf;
	if ($is_text) {   // config.php の body_regex: 特定ページの本文を正規表現で置換(例: アップロードのチャンク/タイムアウト)
		foreach ($cfg['body_regex'] ?? [] as $path_re => $rules) {
			if (!preg_match($path_re, $path)) continue;
			foreach ($rules as $r) $body = preg_replace($r[0], $r[1], $body);
		}
	}
	send_head($status, $resp_headers, $cookies, $rw_hosts, $proxy_base, $https, true);
	header('Content-Length: ' . strlen($body));
	if ($cacheable && $status === 200 && strlen($body) <= $cfg['cache_max_bytes']) {
		file_put_contents($cache_file, $body);
		file_put_contents($cache_meta, json_encode(cache_meta($resp_headers)));
		header('X-Proxy-Cache: MISS');
	}
	echo $body;
}
