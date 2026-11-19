<?php
declare(strict_types=1);
/*
 * Data terbuka BMKG (diambil di sisi server, tanpa masalah CORS):
 *  - Prakiraan cuaca per desa:        https://api.bmkg.go.id/publik/prakiraan-cuaca?adm4={kode}
 *  - Peringatan dini cuaca (CAP):     https://www.bmkg.go.id/alerts/nowcast/id
 * Wajib mencantumkan BMKG sebagai sumber data. Batas akses BMKG: 60 permintaan/menit/IP (cache di sini jauh di bawahnya).
 * BMKG TIDAK menyediakan tinggi gelombang, pasang surut, dan arus lewat API terbuka ini.
 */
const BMKG_TTL = 900;          // data dianggap segar 15 menit
const BMKG_RETRY = 120;        // setelah gagal, tunggu 2 menit sebelum mencoba lagi
const BMKG_SRC = ['cuaca' => ['BMKG - Prakiraan Cuaca Terbuka (api.bmkg.go.id)', 'Data resmi (API)', 'https://data.bmkg.go.id/prakiraan-cuaca/'], 'cap' => ['BMKG - Peringatan Dini Cuaca (CAP nowcast)', 'Data resmi (CAP)', 'https://data.bmkg.go.id/peringatan-dini-cuaca/']];
const SEV = ['Extreme' => ['Ekstrem', '#D64933'], 'Severe' => ['Parah', '#EA6A12'], 'Moderate' => ['Sedang', '#E6A100'], 'Minor' => ['Ringan', '#3B82C4']];

function storage_dir(): string { $d = APP_ROOT . '/storage'; if (!is_dir($d)) @mkdir($d, 0775, true); return $d; }
function bmkg_settings(): array { $f = storage_dir() . '/pengaturan.json'; $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null; return is_array($j) ? $j : []; }
function bmkg_save_settings(array $s): void { @file_put_contents(storage_dir() . '/pengaturan.json', json_encode($s, JSON_PRETTY_PRINT), LOCK_EX); }
function bmkg_cfg(): array { $c = cfg('bmkg', []); $s = bmkg_settings(); return ['adm4' => $s['adm4'] ?? ($c['adm4'] ?? '36.04.08.2009'), 'prov' => $c['prov'] ?? 'banten', 'lokasi' => 'Desa Pulo Panjang, Kec. Puloampel, Kab. Serang, Banten', 'auto_uid' => (int) ($s['auto_uid'] ?? 0)]; }
function bmkg_state(): array { $f = storage_dir() . '/bmkg_state.json'; $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null; return is_array($j) ? $j : ['at' => 0, 'tried' => 0, 'err' => '', 'cuaca' => null, 'alerts' => null]; }
function bmkg_save_state(array $s): void { @file_put_contents(storage_dir() . '/bmkg_state.json', json_encode($s, JSON_UNESCAPED_UNICODE), LOCK_EX); }

/* ---------- pengambilan HTTP dengan cache berkas ---------- */
function bmkg_http(string $url, int $ttl): ?string {
    $dir = storage_dir() . '/cache'; if (!is_dir($dir)) @mkdir($dir, 0775, true); $f = $dir . '/' . md5($url) . '.cache'; $age = is_file($f) ? time() - (int) filemtime($f) : PHP_INT_MAX;
    if ($age < $ttl) { $c = @file_get_contents($f); if ($c !== false && $c !== '') return $c; }
    $body = null; $ua = 'SeaWarning/2.0 (aplikasi peringatan dini banjir rob; sumber data BMKG)';
    if (function_exists('curl_init')) {
        $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_USERAGENT => $ua]);
        $b = curl_exec($ch); $st = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch); if ($b !== false && $st >= 200 && $st < 300) $body = (string) $b;
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 6, 'follow_location' => 1, 'ignore_errors' => true, 'header' => "User-Agent: $ua\r\n"]]);
        $b = @file_get_contents($url, false, $ctx); $ok = false; foreach (($http_response_header ?? []) as $h) if (preg_match('~^HTTP/\S+\s+2\d\d~', $h)) { $ok = true; break; } if ($b !== false && $ok) $body = $b;
    }
    if ($body !== null && $body !== '' && preg_match('/^\s*[\[{<]/', $body)) { @file_put_contents($f, $body, LOCK_EX); return $body; }
    if (is_file($f)) { $c = @file_get_contents($f); if ($c !== false && $c !== '') return $c; } // cache lama lebih baik daripada tidak ada
    return null;
}

/* ---------- pembacaan format resmi ---------- */
function bmkg_parse_cuaca(string $txt): array {
    $j = json_decode($txt, true); if (!is_array($j)) throw new RuntimeException('Respons cuaca BMKG bukan JSON.'); $items = [];
    $walk = function ($o) use (&$walk, &$items) { if (!is_array($o)) return; if (isset($o['utc_datetime']) || isset($o['local_datetime'])) { $items[] = $o; return; } foreach ($o as $v) $walk($v); };
    $walk($j['data'] ?? $j); $lok = $j['lokasi'] ?? ($j['data'][0]['lokasi'] ?? null); $rows = [];
    foreach ($items as $x) {
        $t = strtotime(isset($x['utc_datetime']) ? $x['utc_datetime'] . ' UTC' : (string) $x['local_datetime']); if (!$t) continue;
        $rows[] = ['t' => $t, 'suhu' => (float) ($x['t'] ?? 0), 'hu' => (float) ($x['hu'] ?? 0), 'cuaca' => (string) ($x['weather_desc'] ?? $x['weather'] ?? ''), 'ws' => (float) ($x['ws'] ?? 0), 'wd' => (string) ($x['wd'] ?? ''), 'awan' => (float) ($x['tcc'] ?? 0), 'jarak' => (string) ($x['vs_text'] ?? $x['vs'] ?? ''), 'analysis' => (string) ($x['analysis_date'] ?? '')];
    }
    if (!$rows) throw new RuntimeException('Format data cuaca BMKG tidak dikenali.'); usort($rows, fn($a, $b) => $a['t'] <=> $b['t']);
    $an = ''; foreach ($rows as $r) if ($r['analysis']) { $an = $r['analysis']; break; }
    return ['rows' => $rows, 'lokasi' => $lok ? ['desa' => $lok['desa'] ?? '', 'kec' => $lok['kecamatan'] ?? '', 'kab' => $lok['kotkab'] ?? '', 'prov' => $lok['provinsi'] ?? ''] : null, 'analysis' => $an];
}
function bmkg_xml(string $txt): DOMDocument { $d = new DOMDocument(); $prev = libxml_use_internal_errors(true); $ok = $d->loadXML($txt, LIBXML_NONET); libxml_clear_errors(); libxml_use_internal_errors($prev); if (!$ok) throw new RuntimeException('XML tidak valid'); return $d; }
function xtx(DOMNode $n, string $tag): string { $l = $n instanceof DOMDocument ? $n->getElementsByTagNameNS('*', $tag) : $n->getElementsByTagNameNS('*', $tag); if (!$l->length) { $l = $n->getElementsByTagName($tag); } return $l->length ? trim($l->item(0)->textContent) : ''; }
function bmkg_fetch_alerts(): array {
    $rssTxt = bmkg_http('https://www.bmkg.go.id/alerts/nowcast/id', 120); if ($rssTxt === null) throw new RuntimeException('Tidak dapat mengambil daftar peringatan dini BMKG.');
    $rss = bmkg_xml($rssTxt); $prov = bmkg_cfg()['prov']; $out = [];
    $items = []; foreach ($rss->getElementsByTagName('item') as $it) $items[] = ['title' => xtx($it, 'title'), 'link' => xtx($it, 'link'), 'desc' => xtx($it, 'description'), 'pub' => xtx($it, 'pubDate')];
    $sel = array_slice(array_values(array_filter($items, fn($i) => stripos($i['title'] . ' ' . $i['desc'], $prov) !== false)), 0, 3);
    foreach ($sel as $it) {
        $url = $it['link']; if (!preg_match('~^https://www\.bmkg\.go\.id/alerts/nowcast/(id|en)/[\w-]+\.xml$~', $url)) { if (!preg_match('~nowcast/(?:id|en)/([\w-]+?)(?:_alert)?(?:\.xml)?$~', $url, $m)) continue; $url = 'https://www.bmkg.go.id/alerts/nowcast/id/' . $m[1] . '_alert.xml'; }
        $capTxt = bmkg_http($url, 120); if ($capTxt === null) continue; try { $cap = bmkg_xml($capTxt); } catch (Throwable $e) { continue; }
        foreach ($cap->getElementsByTagNameNS('*', 'info') as $inf) {
            $areas = []; foreach ($inf->getElementsByTagNameNS('*', 'areaDesc') as $a) $areas[] = trim($a->textContent); $desc = xtx($inf, 'description'); $head = xtx($inf, 'headline'); $exp = xtx($inf, 'expires'); $expT = $exp ? strtotime($exp) : null;
            if ($expT && $expT < time()) continue;
            $out[] = ['event' => xtx($inf, 'event'), 'severity' => xtx($inf, 'severity'), 'urgency' => xtx($inf, 'urgency'), 'effective' => xtx($inf, 'effective'), 'expires' => $exp, 'headline' => $head, 'desc' => $desc, 'web' => xtx($inf, 'web'), 'areas' => $areas, 'lokal' => (bool) preg_match('/pulo\s*-?\s*ampel|puloampel/i', implode(' ', $areas) . ' ' . $desc . ' ' . $head)];
        }
    }
    return $out;
}
function bmkg_refresh(bool $force = false): bool {
    $s = bmkg_state(); if (!$force && time() - ($s['tried'] ?? 0) < 60) return false; $s['tried'] = time(); $s['err'] = ''; $okC = false; $okA = false;
    try { $txt = bmkg_http('https://api.bmkg.go.id/publik/prakiraan-cuaca?adm4=' . rawurlencode(bmkg_cfg()['adm4']), BMKG_TTL); if ($txt === null) throw new RuntimeException('Tidak dapat terhubung ke BMKG. Pastikan server memiliki akses internet.'); $s['cuaca'] = bmkg_parse_cuaca($txt); $okC = true; } catch (Throwable $e) { $s['err'] = $e->getMessage(); }
    try { $s['alerts'] = bmkg_fetch_alerts(); $okA = true; } catch (Throwable $e) { if (!$s['err']) $s['err'] = $e->getMessage(); }
    if ($okC || $okA) $s['at'] = time(); bmkg_save_state($s);
    if ($okC && ($uid = bmkg_cfg()['auto_uid']) && row('pengguna', $uid)) bmkg_apply($uid, true);
    return $okC || $okA;
}
function bmkg_ensure_fresh(): void { $s = bmkg_state(); if (time() - ($s['at'] ?? 0) > BMKG_TTL && time() - ($s['tried'] ?? 0) > BMKG_RETRY) bmkg_refresh(); }

/* ---------- turunan data ---------- */
function bmkg_ringkas(): ?array {
    $c = bmkg_state()['cuaca'] ?? null; if (!$c || empty($c['rows'])) return null; $now = time();
    $win = array_values(array_filter($c['rows'], fn($r) => $r['t'] >= $now - 7200 && $r['t'] <= $now + 86400)); if (!$win) return null;
    $cur = $c['rows'][0]; foreach ($c['rows'] as $r) if (abs($r['t'] - $now) < abs($cur['t'] - $now)) $cur = $r; $mx = fn($k) => max(array_column($win, $k));
    return ['cur' => $cur, 'win' => $win, 'wsMax' => $mx('ws'), 'windKnot' => $mx('ws') / 1.852, 'awanMax' => $mx('awan'), 'suhuMax' => $mx('suhu'), 'hujan' => count(array_filter($win, fn($r) => preg_match('/hujan|petir/i', $r['cuaca']))), 'hujanLebat' => count(array_filter($win, fn($r) => preg_match('/hujan lebat|petir/i', $r['cuaca'])))];
}
function bmkg_status(): array { $s = bmkg_state(); $age = ($s['at'] ?? 0) ? time() - $s['at'] : PHP_INT_MAX; if ($age < 2400) return ['k' => 'live', 'c' => 'var(--ok)', 't' => 'Langsung dari BMKG', 'ic' => 'checkc']; if (($s['at'] ?? 0)) return ['k' => 'cache', 'c' => 'var(--warn)', 't' => 'Data BMKG terakhir (belum tersambung kembali)', 'ic' => 'alert']; return ['k' => 'none', 'c' => 'var(--ink-3)', 't' => 'Belum tersambung ke BMKG', 'ic' => 'info']; }
function bmkg_apply(int $uid, bool $quiet = false): ?array {
    $r = bmkg_ringkas(); $p = param_by_name('/angin/i'); if (!$r || !$p) return null;
    $src = ensure_sumber(...BMKG_SRC['cuaca']); $today = date('Y-m-d'); $nilai = round($r['windKnot'], 1); $skor = score_for($p['id_parameter'], $nilai);
    foreach (tbl('wilayah') as $w) {
        $ex = null; foreach (tbl('data_parameter') as $d) if ($d['id_wilayah'] === $w['id_wilayah'] && $d['id_parameter'] === $p['id_parameter'] && $d['id_sumber'] === $src && $d['tanggal_data'] === $today) { $ex = $d; break; }
        if ($ex) upd('data_parameter', $ex['id_data'], ['nilai' => $nilai, 'skor' => $skor]); else ins('data_parameter', ['id_wilayah' => $w['id_wilayah'], 'id_parameter' => $p['id_parameter'], 'id_sumber' => $src, 'id_pengguna' => $uid, 'nilai' => $nilai, 'skor' => $skor, 'tanggal_data' => $today]);
    }
    return ['nilai' => $nilai, 'n' => run_if_changed($uid)];
}
function bmkg_live_note(int $idW): string { // indikasi langsung tanpa menulis ke basis data
    $r = bmkg_ringkas(); $st = bmkg_status(); $p = param_by_name('/angin/i'); $inf = info_wilayah($idW); if (!$r || $st['k'] === 'none' || !$p || !$inf) return '';
    $c = compute_skor($idW, [$p['id_parameter'] => round($r['windKnot'], 1)]); if (!$c) return ''; $same = $c['tingkat']['id_tingkat'] === $inf['tingkat']['id_tingkat'];
    return '<div class="note" style="font-size:13px">' . ic('wind', 18) . '<div>Prakiraan angin BMKG 24 jam ke depan: maksimum <b>' . nf($r['windKnot'], 1) . ' knot</b> (' . nf($r['wsMax'], 0) . ' km/jam). ' . ($same ? 'Tingkat kerawanan tetap <b>' . e($c['tingkat']['nama_tingkat']) . '</b> (skor ' . nf($c['skor'], 2) . ').' : 'Dengan angin ini skor menjadi <b>' . nf($c['skor'], 2) . '</b> (tingkat <b>' . e($c['tingkat']['nama_tingkat']) . '</b>), berbeda dari hasil analisis tersimpan.') . ' <span class="faint">Sumber: BMKG.</span></div></div>';
}

/* ---------- tampilan ---------- */
function bmkg_chip(): string { $st = bmkg_status(); $s = bmkg_state(); return '<span style="display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap"><span class="tag" style="--c:' . $st['c'] . '">' . ic($st['ic'], 15) . e($st['t']) . '</span>' . (($s['at'] ?? 0) ? '<span class="faint" style="font-size:12.5px">diperbarui ' . fmt_t(date('Y-m-d H:i:s', $s['at'])) . '</span>' : '') . '</span>'; }
function bmkg_empty(): string { return '<div class="empty">' . ic('waves', 30) . '<b>Belum ada data BMKG</b><div>' . e(bmkg_state()['err'] ?: 'Data belum pernah diambil.') . '</div><div style="margin-top:12px">' . bmkg_form('refresh', 'Coba ambil sekarang', 'refresh', 'btn btn-sm') . '</div></div>'; }
function bmkg_form(string $aksi, string $label, string $icon, string $cls = 'btn btn-sm', array $extra = []): string { $h = '<form method="post" action="index.php?p=bmkg" style="display:inline;margin:0">' . csrf_field() . '<input type="hidden" name="aksi" value="' . e($aksi) . '"><input type="hidden" name="back" value="' . e($_SERVER['REQUEST_URI'] ?? '') . '">'; foreach ($extra as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">'; return $h . '<button class="' . e($cls) . '" type="submit">' . ic($icon, 16) . e($label) . '</button></form>'; }
function bmkg_cuaca_html(bool $full): string {
    $r = bmkg_ringkas(); if (!$r) return bmkg_empty(); $rows = array_slice(array_values(array_filter(bmkg_state()['cuaca']['rows'], fn($x) => $x['t'] >= time() - 7200)), 0, $full ? 16 : 5);
    $fd = fn($t) => fmt_dayt(date('Y-m-d H:i:s', $t));
    if (!$full) return '<dl class="kv" style="margin-bottom:8px"><dt>Cuaca</dt><dd>' . e($r['cur']['cuaca'] ?: '–') . '</dd><dt>Angin maks 24 jam</dt><dd class="num">' . nf($r['windKnot'], 1) . ' knot</dd><dt>Periode berhujan/petir</dt><dd class="num">' . $r['hujan'] . ' dari ' . count($r['win']) . '</dd></dl>';
    $tiles = '<div class="grid g4 keep2" style="margin-bottom:16px">' . stat_tile(nf($r['windKnot'], 1), 'Angin maksimum 24 jam', nf($r['wsMax'], 0) . ' km/jam', '#5E7480', 'knot') . stat_tile(e($r['cur']['cuaca'] ?: '–'), 'Cuaca saat ini', fmt_t(date('Y-m-d H:i:s', $r['cur']['t'])), 'var(--primary)') . stat_tile($r['hujan'] . ' dari ' . count($r['win']), 'Periode berhujan/petir', '24 jam ke depan', '#3B82C4') . stat_tile(nf($r['awanMax'], 0) . '%', 'Tutupan awan maks', 'Suhu maks ' . nf($r['suhuMax'], 0) . '°C', '#8493A0') . '</div>';
    $tb = '<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Waktu</th><th>Cuaca</th><th class="r">Suhu</th><th class="r">Angin</th><th class="r">Awan</th><th class="r">Jarak pandang</th></tr></thead><tbody>';
    foreach ($rows as $x) $tb .= '<tr><td class="num">' . e($fd($x['t'])) . '</td><td>' . e($x['cuaca']) . '</td><td class="r num">' . nf($x['suhu'], 0) . '°C</td><td class="r num">' . nf($x['ws'], 0) . ' km/jam<span class="sub">' . nf($x['ws'] / 1.852, 1) . ' knot' . ($x['wd'] ? ', dari ' . e($x['wd']) : '') . '</span></td><td class="r num">' . nf($x['awan'], 0) . '%</td><td class="r num">' . e($x['jarak'] !== '' ? $x['jarak'] : '–') . ' km</td></tr>';
    return $tiles . $tb . '</tbody></table></div>';
}
function bmkg_attribution(): string { $c = bmkg_cfg(); $an = bmkg_state()['cuaca']['analysis'] ?? ''; return '<p class="faint" style="font-size:12.5px;margin-top:12px">Sumber data: <b>BMKG</b> (Badan Meteorologi, Klimatologi, dan Geofisika), prakiraan cuaca terbuka untuk ' . e($c['lokasi']) . ' (kode ' . e($c['adm4']) . ')' . ($an ? ', produksi data ' . e(str_replace('T', ' ', $an)) . ' UTC' : '') . '. Ini prakiraan cuaca darat. BMKG tidak menyediakan tinggi gelombang, pasang surut, dan arus lewat API terbuka ini.</p>'; }
function bmkg_alerts_html(bool $admin = false): string {
    $a = bmkg_state()['alerts'] ?? null;
    if ($a === null) return '<div class="empty" style="padding:20px">' . ic('bell', 28) . '<b>Peringatan dini cuaca BMKG belum diambil</b><div>' . e(bmkg_state()['err'] ?? '') . '</div></div>';
    if (!$a) return '<div style="display:flex;gap:12px;align-items:center;color:var(--ink-2)"><span style="color:var(--ok)">' . ic('checkc', 24) . '</span><div><b style="color:var(--ink)">Tidak ada peringatan dini cuaca BMKG yang aktif untuk Banten.</b><div style="font-size:13.5px">Pemeriksaan terakhir ' . (bmkg_state()['at'] ? fmt_t(date('Y-m-d H:i:s', bmkg_state()['at'])) : '–') . '.</div></div></div>';
    $h = '<div class="list">'; foreach ($a as $i => $x) { $sv = SEV[$x['severity']] ?? [$x['severity'] ?: 'Info', '#8493A0']; $ef = $x['effective'] ? strtotime($x['effective']) : 0; $ex = $x['expires'] ? strtotime($x['expires']) : 0;
        $h .= '<div><div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:6px"><span class="tag" style="--c:' . $sv[1] . '">' . ic('alert', 15) . e($sv[0]) . '</span>' . ($x['lokal'] ? '<span class="tag" style="--c:var(--bad)">' . ic('pin', 15) . 'Menyebut Pulo Ampel</span>' : '') . '<b>' . e($x['event'] ?: $x['headline']) . '</b></div>' . ($x['headline'] && $x['headline'] !== $x['event'] ? '<div style="font-size:14px">' . e($x['headline']) . '</div>' : '') . '<div class="muted" style="font-size:13px;margin-top:2px">' . ($ef ? 'Mulai ' . fmt_dayt(date('Y-m-d H:i:s', $ef)) : '') . ($ex ? ', berakhir ' . fmt_dayt(date('Y-m-d H:i:s', $ex)) : '') . '</div>' . ($x['desc'] ? '<p style="font-size:13.5px;margin-top:6px;color:var(--ink-2)">' . e(mb_strlen($x['desc']) > 320 ? mb_substr($x['desc'], 0, 317) . '…' : $x['desc']) . '</p>' : '')
            . ($admin ? '<a class="btn btn-sm" style="margin-top:8px" href="' . e(url('peringatan', ['c' => 'peringatan', 'aksi' => 'tambah', 'bmkg' => $i])) . '">' . ic('bell', 15) . 'Jadikan draf peringatan</a>' : '') . ($x['web'] ? ' <a href="' . e($x['web']) . '" target="_blank" rel="noopener noreferrer" style="font-size:13px;margin-left:8px">Infografik BMKG</a>' : '') . '</div>'; }
    return $h . '</div>';
}
function bmkg_alerts_mini(): string { $a = bmkg_state()['alerts'] ?? null; if ($a === null) return ''; $loc = count(array_filter($a, fn($x) => $x['lokal'])); return '<div class="hr"></div><div style="display:flex;gap:10px;align-items:center;font-size:13.5px"><span style="color:' . ($a ? 'var(--warn)' : 'var(--ok)') . '">' . ic($a ? 'alert' : 'checkc', 20) . '</span><span>' . ($a ? count($a) . ' peringatan dini cuaca BMKG aktif untuk Banten' . ($loc ? ', ' . $loc . ' menyebut Pulo Ampel' : '') . '.' : 'Tidak ada peringatan dini cuaca BMKG aktif untuk Banten.') . '</span></div><div class="faint" style="font-size:12px;margin-top:6px">Sumber: BMKG</div>'; }
function bmkg_admin_html(): string {
    $c = bmkg_cfg(); $r = bmkg_ringkas(); $auto = $c['auto_uid'] > 0;
    $ok = bmkg_lokasi_ok(); $lk = bmkg_lokasi(); $h = ($ok === false ? note_box('<b>Kode adm4 salah lokasi.</b> BMKG mengembalikan desa <b>' . e($lk['desa'] ?? '?') . '</b>, bukan Pulo Panjang. Perbaiki kode di bawah.', 'warn', 'alert') . '<div style="height:10px"></div>' : ($ok === true ? '<div class="tag" style="--c:var(--ok);margin-bottom:10px">' . ic('checkc', 15) . 'Lokasi terverifikasi: ' . e($lk['desa']) . ', ' . e($lk['kec'] ?? '') . '</div>' : '')) . '<div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:12px">' . bmkg_chip() . '<div style="display:flex;gap:8px;flex-wrap:wrap">' . bmkg_form('refresh', 'Ambil data BMKG sekarang', 'refresh') . ($r ? bmkg_form('apply', 'Terapkan angin BMKG ke analisis', 'play', 'btn btn-sm btn-primary') : '<button class="btn btn-sm btn-primary" disabled>' . ic('play', 16) . 'Terapkan angin BMKG ke analisis</button>') . '</div></div>';
    if ($r) $h .= '<dl class="kv" style="margin-bottom:12px"><dt>Angin maksimum 24 jam (BMKG)</dt><dd class="num">' . nf($r['windKnot'], 1) . ' knot (' . nf($r['wsMax'], 0) . ' km/jam)</dd><dt>Parameter yang diperbarui</dt><dd>Angin, pada seluruh wilayah, lalu analisis ulang (hasil baru hanya disimpan bila berubah)</dd></dl>';
    $h .= '<form method="post" action="index.php?p=bmkg" style="margin-bottom:12px">' . csrf_field() . '<input type="hidden" name="aksi" value="auto"><input type="hidden" name="back" value="' . e($_SERVER['REQUEST_URI'] ?? '') . '"><label class="switch"><input type="checkbox" name="auto" value="1" ' . ($auto ? 'checked' : '') . '><span>Terapkan otomatis tiap data BMKG diperbarui (berjalan di server)</span></label> <button class="btn btn-sm" type="submit" style="margin-left:8px">Simpan pilihan</button></form>';
    $h .= '<form method="post" action="index.php?p=bmkg" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">' . csrf_field() . '<input type="hidden" name="aksi" value="adm4"><input type="hidden" name="back" value="' . e($_SERVER['REQUEST_URI'] ?? '') . '"><div class="field" style="margin:0;flex:1;min-width:220px"><label for="bmkg-adm4">Kode wilayah BMKG (adm4)</label><input class="input" id="bmkg-adm4" name="adm4" value="' . e($c['adm4']) . '" maxlength="13" inputmode="numeric"></div><button class="btn btn-sm" type="submit">Simpan kode</button></form><div class="hint" style="margin-top:6px">Kode saat ini untuk ' . e($c['lokasi']) . ' (Kemendagri 36.04.08.2009). Ubah bila Anda memantau desa lain.</div>';
    return $h . '<div class="note" style="margin-top:14px;font-size:13px">' . ic('info', 18) . '<div><b>Tinggi gelombang, pasang surut, dan arus</b> tidak tersedia lewat API terbuka BMKG ini. Data tersebut masih simulasi atau diisi manual dari <a href="https://maritim.bmkg.go.id" target="_blank" rel="noopener noreferrer">maritim.bmkg.go.id</a> pada menu Data Oseanografi.</div></div>';
}

/* ---------- kartu cuaca Pulo Panjang (ide kartu cuaca + status), memakai slot prakiraan TERDEKAT dengan waktu sekarang ---------- */
function bmkg_lokasi(): array { $l = bmkg_state()['cuaca']['lokasi'] ?? null; return is_array($l) ? $l : []; }
function bmkg_lokasi_ok(): ?bool { $l = bmkg_lokasi(); if (!$l || ($l['desa'] ?? '') === '') return null; return (bool) preg_match('/pulo\s*panjang|pulopanjang/i', (string) $l['desa']); }
function bmkg_status_cuaca(): array { // status CUACA dari BMKG. BUKAN status banjir rob (rob ditentukan pasang surut/bulan, bukan angin)
    $a = bmkg_state()['alerts'] ?? null; $r = bmkg_ringkas();
    if ($a) { foreach ($a as $x) if ($x['lokal']) return ['var(--bad)', 'alert', 'BMKG menyebut Pulo Ampel dalam peringatan dini cuaca aktif', 'alert']; return ['var(--warn)', 'alert', count($a) . ' peringatan dini cuaca BMKG aktif untuk Banten', 'banten']; }
    if ($r && $r['hujanLebat']) return ['var(--warn)', 'alert', 'Prakiraan hujan lebat atau petir dalam 24 jam ke depan', 'hujan'];
    if ($r) return ['var(--ok)', 'checkc', 'Tidak ada peringatan cuaca ekstrem dari BMKG', 'aman'];
    return ['var(--ink-3)', 'info', 'Status cuaca belum tersedia', 'none'];
}
function bmkg_widget(): string {
    $r = bmkg_ringkas(); if (!$r) return bmkg_empty(); $c = $r['cur']; $st = bmkg_status_cuaca(); $lok = bmkg_lokasi(); $ok = bmkg_lokasi_ok();
    $tile = fn($l, $v, $u = '') => '<div style="padding:10px 12px;border:var(--bw) solid var(--line);border-radius:12px;background:var(--surface-2)"><div class="faint" style="font-size:12px">' . $l . '</div><div class="num" style="font-family:var(--font-display);font-size:20px;font-weight:600">' . $v . '<small style="font-size:12px;font-weight:500;color:var(--ink-3)"> ' . $u . '</small></div></div>';
    $next = array_slice(array_values(array_filter(bmkg_state()['cuaca']['rows'], fn($x) => $x['t'] > $c['t'])), 0, 3);
    $h = '<div class="wx"><div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap;margin-bottom:12px"><div><div style="font-size:19px;font-weight:700;font-family:var(--font-display)">' . e($c['cuaca'] ?: '–') . '</div><div class="faint" style="font-size:12.5px">Prakiraan untuk pukul ' . fmt_t(date('Y-m-d H:i:s', $c['t'])) . ' WIB (slot terdekat)</div></div>' . bmkg_chip() . '</div>'
        . '<div class="grid" style="grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:12px">' . $tile('Suhu', nf($c['suhu'], 0), '°C') . $tile('Kelembapan', nf($c['hu'], 0), '%') . $tile('Kec. angin', nf($c['ws'], 0), 'km/jam' . ($c['wd'] ? ', dari ' . e($c['wd']) : '')) . $tile('Tutupan awan', nf($c['awan'], 0), '%') . '</div>'
        . '<div style="display:flex;gap:10px;align-items:center;padding:10px 12px;border-radius:12px;border:var(--bw) solid color-mix(in srgb,' . $st[0] . ' 55%,transparent);background:color-mix(in srgb,' . $st[0] . ' 12%,var(--surface))"><span style="color:' . $st[0] . '">' . ic($st[1], 22) . '</span><div style="font-size:14px"><b>' . e($st[2]) . '</b><div class="faint" style="font-size:12px">Status cuaca dari BMKG. Ini bukan status banjir rob.</div></div></div>';
    if ($next) { $h .= '<div class="hr"></div><div style="display:grid;grid-template-columns:repeat(' . count($next) . ',minmax(0,1fr));gap:8px">'; foreach ($next as $x) $h .= '<div style="text-align:center;font-size:12.5px"><b class="num">' . fmt_t(date('Y-m-d H:i:s', $x['t'])) . '</b><div class="muted" style="line-height:1.3">' . e($x['cuaca']) . '</div><div class="num">' . nf($x['suhu'], 0) . '°C, ' . nf($x['ws'], 0) . ' km/jam</div></div>'; $h .= '</div>'; }
    $h .= '<div class="faint" style="font-size:12px;margin-top:10px">Lokasi data BMKG: ' . ($lok ? e(trim(($lok['desa'] ?? '') . ', ' . ($lok['kec'] ?? '') . ', ' . ($lok['kab'] ?? ''), ', ')) : '–') . ' (kode ' . e(bmkg_cfg()['adm4']) . '). Prakiraan 3-jam-an, dimutakhirkan BMKG 2 kali sehari. Sumber: <b>BMKG</b>.</div>';
    if ($ok === false) $h .= '<div style="margin-top:10px">' . note_box('Kode wilayah BMKG yang dipakai mengarah ke <b>' . e($lok['desa'] ?? '?') . '</b>, bukan Pulo Panjang. Administrator perlu memperbaiki kode adm4 (menu Analisis Kerawanan).', 'warn', 'alert') . '</div>';
    return $h . '</div>';
}
function bmkg_topbar_chip(): string { // chip kecil di bilah atas semua halaman; hanya membaca cache (tidak memicu pengambilan data)
    $s = bmkg_state(); if (($s['at'] ?? 0) === 0 || time() - $s['at'] > 86400) return ''; $r = bmkg_ringkas(); if (!$r) return ''; $c = $r['cur'];
    return '<a class="wx-chip" href="' . e(url('kondisi', ['tab' => 'bmkg'])) . '" title="Cuaca Pulo Panjang menurut BMKG (prakiraan pukul ' . fmt_t(date('Y-m-d H:i:s', $c['t'])) . ' WIB)"><span aria-hidden="true">' . ic('wind', 16) . '</span><span><b class="num">' . nf($c['suhu'], 0) . '°C</b> <span class="wx-c">' . e($c['cuaca']) . '</span></span></a>';
}
function bmkg_refresh_meta(): string { return '<meta http-equiv="refresh" content="900">'; } // muat ulang otomatis tiap 15 menit (HTML murni)

function bmkg_block(string $kind): string {
    bmkg_ensure_fresh();
    return match ($kind) {
        'compact' => bmkg_widget() . bmkg_alerts_mini(),
        'full' => '<div style="margin-bottom:14px">' . bmkg_chip() . '</div>' . bmkg_cuaca_html(true) . bmkg_attribution(),
        'alerts' => bmkg_alerts_html(user_role() === 'admin'),
        'admin' => bmkg_admin_html(), default => '' };
}
