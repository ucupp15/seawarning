<?php
declare(strict_types=1);
/* Komponen tampilan dan kerangka halaman (HTML dirender di server). */

const DISCLAIMER = 'Aplikasi ini adalah sistem pendukung informasi dan kesiapsiagaan berbasis data penelitian. Aplikasi ini tidak menggantikan sistem peringatan resmi pemerintah atau BMKG.';
const LVL_IC = ['checkc', 'alert', 'octagon', 'info'];
function lvl_icon(int $rank, int $s = 16): string { return ic(LVL_IC[min($rank, 3)], $s); }
function tingkat_tag(?array $t, bool $lg = false): string { return $t ? '<span class="tag ' . ($lg ? 'lg' : '') . '" style="--c:' . e($t['warna']) . '">' . lvl_icon(rank_of($t['id_tingkat']), $lg ? 20 : 16) . e($t['nama_tingkat']) . '</span>' : '<span class="tag plain">Belum dianalisis</span>'; }
function status_tag(string $st, bool $lg = false): string { $s = status_meta()[$st] ?? status_meta()['Normal']; return '<span class="tag ' . ($lg ? 'lg' : '') . '" style="--c:' . e($s['c']) . '">' . ic($s['icon'], $lg ? 20 : 16) . e($st) . '</span>'; }
function jenis_tag(string $j): string { return $j === 'simulasi' ? '<span class="tag" style="--c:var(--warn)">Simulasi</span>' : '<span class="tag plain">' . e(jenis_label($j)) . '</span>'; }
function page_head(string $title, string $desc = '', string $acts = ''): string { return '<div class="ph"><div><h1>' . e($title) . '</h1>' . ($desc ? '<p>' . $desc . '</p>' : '') . '</div>' . ($acts ? '<div class="acts">' . $acts . '</div>' : '') . '</div>'; }
function empty_state(string $title, string $msg, string $icon = 'info'): string { return '<div class="empty">' . ic($icon, 34) . '<b>' . e($title) . '</b><div>' . $msg . '</div></div>'; }
function note_box(string $msg, string $kind = '', string $icon = 'info'): string { return '<div class="note ' . e($kind) . '">' . ic($icon, 20) . '<div>' . $msg . '</div></div>'; }
function stat_tile($v, string $l, string $s = '', string $c = 'var(--primary)', string $unit = ''): string { return '<div class="stat" style="--c:' . e($c) . '"><div class="v num">' . $v . ($unit ? '<small>' . e($unit) . '</small>' : '') . '</div><div class="l">' . e($l) . '</div>' . ($s ? '<div class="s">' . $s . '</div>' : '') . '</div>'; }
function seg(array $items, string $active, callable $href, string $label = ''): string { // [[nilai, label, ikon?]]
    $h = '<div class="seg" role="group" aria-label="' . e($label) . '">'; foreach ($items as $i) $h .= '<a href="' . e($href($i[0])) . '" role="button" aria-pressed="' . ($i[0] === $active ? 'true' : 'false') . '" style="text-decoration:none"><span style="display:inline-flex;align-items:center;gap:7px;height:34px;padding:0 14px">' . (isset($i[2]) ? ic($i[2], 16) : '') . e($i[1]) . '</span></a>'; return $h . '</div>';
}
function scale_html(float $skor): string {
    $ts = tingkat_list(); $dmin = $ts[0]['skor_min']; $dmax = end($ts)['skor_maks']; $tot = ($dmax - $dmin) ?: 1; $pos = clamp(($skor - $dmin) / $tot * 100, 0, 100); $h = '<div class="scale" role="img" aria-label="Skor ' . nf($skor, 2) . '">';
    foreach ($ts as $t) $h .= '<i style="flex:' . (($t['skor_maks'] - $t['skor_min']) ?: 0.1) . ';background:' . e($t['warna']) . '" title="' . e($t['nama_tingkat']) . '"></i>'; return $h . '<span class="mk" style="left:' . round($pos, 1) . '%"><span>' . nf($skor, 2) . '</span></span></div>';
}
function steps_html(int $idTingkat, int $n = 99): string {
    $t = row('tingkat_kerawanan', $idTingkat); $list = array_slice(tindakan_list($idTingkat), 0, $n);
    if (!$list) return empty_state('Belum ada tindakan', 'Administrator belum menambahkan anjuran untuk tingkat ini.', 'shield');
    return '<ol class="steps" style="--c:' . e($t['warna'] ?? 'var(--primary)') . '">' . implode('', array_map(fn($x) => '<li><div>' . e($x['isi_tindakan']) . '</div></li>', $list)) . '</ol>';
}
function driver_chip(array $d): string {
    [$mn, $mx] = skor_bounds($d['p']['id_parameter']); $up = $mx > $mn && $d['skor'] >= $mx; $dn = $mx > $mn && $d['skor'] <= $mn;
    $col = $up ? 'var(--bad)' : ($dn ? 'var(--ok)' : 'var(--warn)'); $note = $up ? 'meningkatkan kerawanan' : ($dn ? 'menurunkan kerawanan' : 'pengaruh sedang');
    return '<span class="tag" style="--c:' . $col . '" title="' . e($d['p']['nama_parameter'] . ': ' . $note) . '">' . ic($up ? 'up' : ($dn ? 'down' : 'chevr'), 14) . e($d['p']['nama_parameter']) . ': ' . nfa($d['nilai']) . ' ' . e($d['p']['satuan']) . '</span>';
}
function my_wil_id(): ?int { $id = (int) ($_COOKIE['my_wil'] ?? 0); return $id && row('wilayah', $id) ? $id : null; }
function lokasi_text(): string { return 'Pulo Panjang, Kec. Puloampel, Kab. Serang, Banten'; }

/* ---------- rincian satu wilayah (warga) dan versi lanjutan (peran lain) ---------- */
function wilayah_result(array $w, array $o = []): string {
    if (user_role() === 'warga') return wilayah_result_warga($w, $o);
    $inf = info_wilayah($w['id_wilayah']); $t = $inf['tingkat'] ?? null; $drv = drivers($w['id_wilayah'], 3);
    $riw = where('riwayat_rob', fn($r) => $r['id_wilayah'] === $w['id_wilayah']); usort($riw, fn($a, $b) => strcmp($b['tanggal'], $a['tanggal'])); $mine = my_wil_id() === $w['id_wilayah'];
    $head = '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap"><div><h2 style="font-size:24px">' . e($w['nama_wilayah']) . '</h2><p class="muted" style="margin-top:4px">' . (wil_desc($w) ? e(wil_desc($w)) . '. ' : '') . e($w['tipe']) . ($w['jumlah_kk'] ? ', sekitar ' . nf($w['jumlah_kk']) . ' kepala keluarga (estimasi)' : '') . '</p></div>' . tingkat_tag($t, true) . '</div>';
    if (!$inf) return '<div class="stack">' . $head . note_box('Wilayah ini belum memiliki hasil analisis kerawanan. Administrator dapat menjalankan analisis pada menu Analisis Kerawanan.', 'warn', 'alert') . '</div>';
    $h = '<div class="stack" style="gap:16px">' . $head . '<div class="lvl-card" style="--c:' . e($t['warna']) . '">' . lvl_icon(rank_of($t['id_tingkat']), 22) . '<b>Artinya bagi Anda</b><p>' . e($t['makna_warga']) . '</p></div><div><div class="lbl">Skor kerawanan</div>' . scale_html($inf['skor']) . '</div>' . bmkg_live_note($w['id_wilayah']);
    if ($drv) $h .= '<div><div class="lbl" style="margin-bottom:8px">Faktor yang paling berpengaruh</div><div style="display:flex;gap:8px;flex-wrap:wrap">' . implode('', array_map('driver_chip', $drv)) . '</div></div>';
    $h .= '<div><div class="lbl" style="margin-bottom:10px">Yang perlu dilakukan</div>' . steps_html($t['id_tingkat'], $o['steps'] ?? 4) . '</div>';
    if ($riw) { $h .= '<div><div class="lbl" style="margin-bottom:6px">Kejadian rob terakhir di sini</div><div class="list">'; foreach (array_slice($riw, 0, 2) as $r) $h .= '<div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><span><b>' . fmt_d($r['tanggal']) . '</b><span class="faint">, ' . e($r['penyebab']) . '</span></span><span class="num">genangan ' . nfa($r['tinggi_genangan_cm']) . ' cm, ' . nfa($r['durasi_jam']) . ' jam</span></div>'; $h .= '</div></div>'; }
    $h .= '<div class="faint" style="font-size:12.5px">Analisis tanggal ' . fmt_d($inf['hasil']['tanggal_analisis']) . ' dengan metode ' . e(mb_strtolower($inf['hasil']['metode'])) . '. Nilai parameter masih contoh sampai data lapangan tersedia.</div>';
    if (($o['actions'] ?? true) !== false) $h .= '<div style="display:flex;gap:8px;flex-wrap:wrap"><form method="post" action="index.php?p=set-my" style="margin:0">' . csrf_field() . '<input type="hidden" name="id" value="' . $w['id_wilayah'] . '"><input type="hidden" name="back" value="' . e($_SERVER['REQUEST_URI'] ?? '') . '"><button class="btn ' . ($mine ? '' : 'btn-primary') . '" type="submit">' . ic('pin', 18) . ($mine ? 'Ini wilayah saya' : 'Jadikan wilayah saya') . '</button></form>' . (($o['mapBtn'] ?? true) !== false ? '<a class="btn" href="' . e(url('peta', ['sel' => $w['id_wilayah']])) . '">' . ic('map', 18) . 'Lihat di peta</a>' : '') . '</div>';
    return $h . '</div>';
}
function wilayah_advanced(array $w): string {
    $inf = info_wilayah($w['id_wilayah']); $c = compute_skor($w['id_wilayah']);
    $h = '<div class="stack" style="gap:14px"><div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap"><div><h2 style="font-size:22px">' . e($w['nama_wilayah']) . '</h2><p class="muted" style="margin-top:3px">' . (wil_desc($w) ? e(wil_desc($w)) . '. ' : '') . e($w['tipe']) . ($w['jumlah_kk'] ? ', sekitar ' . nf($w['jumlah_kk']) . ' KK' : '') . '</p></div>' . tingkat_tag($inf['tingkat'] ?? null, true) . '</div>';
    if ($inf) $h .= '<div><div class="lbl">Skor kerawanan</div>' . scale_html($inf['skor']) . '</div>';
    if ($c) { $d = array_values(array_filter($c['detail'], fn($x) => $x['bobot'] > 0)); usort($d, fn($a, $b) => $b['kontribusi'] <=> $a['kontribusi']); $h .= '<div><div class="lbl" style="margin-bottom:8px">Parameter penyusun</div><div class="bars">';
        foreach ($d as $x) { [, $mx] = skor_bounds($x['p']['id_parameter']); $h .= '<div class="b" style="grid-template-columns:minmax(90px,150px) 1fr 70px"><span title="' . e($x['p']['nama_parameter']) . '">' . e($x['p']['nama_parameter']) . '</span><div class="prog" style="--c:' . ($x['skor'] >= $mx ? 'var(--bad)' : ($x['skor'] <= 1 ? 'var(--ok)' : 'var(--warn)')) . '"><i style="width:' . round($x['skor'] / $mx * 100) . '%"></i></div><em class="num" style="font-size:12.5px">' . nfa($x['nilai']) . ' ' . e($x['p']['satuan']) . '</em></div>'; }
        $h .= '</div></div>'; }
    return $h . '</div>';
}
function warn_message(array $p): string { return '*[' . mb_strtoupper($p['status']) . '] ' . $p['judul'] . "*\n" . $p['isi'] . "\n\nBerlaku: " . fmt_dt($p['mulai']) . ' sampai ' . fmt_dt($p['berakhir']) . "\nSumber: " . status_sumber_label($p) . "\n\nInformasi dari Sistem Peringatan Dini Banjir Rob Pulo Panjang. Ikuti arahan perangkat desa dan pihak berwenang."; }
function warn_card(array $p, bool $share = false): string {
    $s = status_meta()[$p['status']] ?? status_meta()['Normal'];
    $h = '<article class="panel" style="--c:' . e($s['c']) . ';border-left:6px solid ' . e($s['c']) . '"><div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:10px">' . status_tag($p['status'], true) . '<span class="muted num" style="font-size:13.5px">' . fmt_dt($p['mulai']) . ' sampai ' . fmt_dt($p['berakhir']) . '</span></div><h2 style="font-size:22px;margin-bottom:8px">' . e($p['judul']) . '</h2><p style="max-width:75ch">' . e($p['isi']) . '</p><div class="muted" style="font-size:13.5px;margin-top:12px">Sumber: ' . e(status_sumber_label($p)) . (empty($p['asal']) || $p['asal'] === 'manual' ? ', diterbitkan oleh ' . e(label_of('pengguna', (int) ($p['id_pengguna'] ?? 0))) : ', ' . ($p['asal'] === 'penimpaan' ? 'ditetapkan administrator' : 'ditentukan otomatis oleh sistem')) . '</div>';
    if ($share) $h .= '<details style="margin-top:14px"><summary class="btn btn-sm" style="display:inline-flex;list-style:none;cursor:pointer">' . ic('share', 16) . 'Pesan untuk warga (salin)</summary><textarea class="textarea" rows="8" readonly style="margin-top:10px">' . e(warn_message($p)) . '</textarea><div class="hint">Pilih semua teks (Ctrl+A) lalu salin dan tempel ke WhatsApp.</div></details>';
    return $h . '</article>';
}
function profil_desa_html(bool $full): string {
    $p = D()['PROFIL_DESA']; $d = penduduk_desa();
    $kk = $d ? (int) $d['kk'] : (int) $p['kk']; $jiwa = $d ? (int) $d['jiwa'] : null;
    $head = '<div class="panel-h"><div><h2>Profil Desa Pulo Panjang</h2><p>' . ($d ? 'Data penduduk: ' . e($d['sumber']) : 'Pernyataan Kepala Desa, ' . fmt_d($p['tanggal'])) . '</p></div></div>';
    $body = $full ? '<div class="grid g4 keep2" style="margin-bottom:14px">' . stat_tile(nf($kk), 'Kepala keluarga', $d ? 'Rekap DTSEN' : 'Perkiraan Kepala Desa') . ($jiwa !== null ? stat_tile(nf($jiwa), 'Jiwa (individu)', 'Data penduduk desa', '#2E9E6B') : '') . stat_tile((string) $p['kampung'], 'Kampung', '', '#8B6F47') . stat_tile($p['rt'] . ' / ' . $p['rw'], 'RT / RW', '', '#5E7480') . '</div>'
        : '<dl class="kv" style="margin-bottom:10px"><dt>Kepala keluarga</dt><dd class="num">' . nf($kk) . '</dd>' . ($jiwa !== null ? '<dt>Jiwa (individu)</dt><dd class="num">' . nf($jiwa) . '</dd>' : '') . '<dt>Kampung</dt><dd class="num">' . $p['kampung'] . '</dd><dt>RT / RW</dt><dd class="num">' . $p['rt'] . ' / ' . $p['rw'] . '</dd><dt>Mata pencaharian utama</dt><dd>Nelayan</dd></dl>';
    $kkK = sum(array_column(penduduk_data()['kampung'] ?? [], 'kk')); $jwK = sum(array_column(penduduk_data()['kampung'] ?? [], 'jiwa'));
    $src = ($d ? 'Total keluarga: <b>' . e($d['sumber']) . '</b>. Total jiwa: <b>' . e($d['jiwa_sumber'] ?? $d['sumber']) . '</b>. ' : '') . 'RT/RW dan mata pencaharian: <a href="' . e($p['url']) . '" target="_blank" rel="noopener noreferrer">SRSentra, 22 Mei 2026</a>, pernyataan Kepala Desa ' . e($p['kades']) . '. Ketujuh kampung tercatat dengan koordinatnya (Penengahan, Pasir Putih, Peres, Baru, Sukarela, Sukadiri, Kebalen). Jumlah KK dan jiwa tiap kampung memakai <b>' . e(penduduk_sumber()) . '</b> (jumlah ketujuh kampung ' . nf($kkK) . ' KK, ' . nf($jwK) . ' jiwa). Ubah lewat menu Wilayah bila ada pemutakhiran.';
    return '<div class="panel">' . $head . $body . penduduk_usia_html(!$full) . '<p class="muted" style="font-size:13px;margin-top:10px">' . $src . '</p></div>';
}

/* ---------- hero status banjir rob ---------- */
function hero_status(array $o = []): string {
    $acts = $o['actions'] ?? [['peta', 'Lihat peta zona', 'map', 1], ['tindakan', 'Apa yang harus dilakukan?', 'shield', 0]]; $btn = '';
    foreach ($acts as $a) $btn .= '<a class="btn ' . (!empty($a[3]) ? 'btn-primary' : '') . '" href="' . e(url($a[0])) . '">' . e($a[1]) . '</a>';
    return hero_baru('Pantauan Banjir Rob Pulo Panjang', 'Status dihitung otomatis dari prediksi pasang surut BIG, peringatan BMKG, dan laporan warga.', $btn . ($o['extra'] ?? ''), false);
}
/* Penanda asal data per besaran: hanya pasang surut yang berasal dari prediksi BIG. */
function asal_pasut_tag(array $r): string {
    return (($r['jenis_data'] ?? '') === 'prediksi' && pasut_ada())
        ? '<span class="tag" style="--c:var(--ok)">' . ic('checkc', 14) . 'Prediksi BIG/SRGI</span>'
        : jenis_tag((string) ($r['jenis_data'] ?? 'simulasi'));
}
function asal_simulasi_tag(): string { return '<span class="tag" style="--c:var(--warn)">Simulasi</span>'; }
function kondisi_strip(): string {
    $cur = nearest_osea(); if (!$cur) return ''; $r = $cur['row']; if (($zb = pasut_at(time())) !== null) { $r['pasut_m'] = round($zb, 3); $r['jenis_data'] = 'prediksi'; }
    return '<div class="grid g4 keep2">' . stat_tile(nf($r['pasut_m'], 2), 'Pasang surut', asal_pasut_tag($r), 'var(--primary)', 'm') . stat_tile(nf($r['gelombang_m'], 2), 'Tinggi gelombang', asal_simulasi_tag(), '#3B82C4', 'm') . stat_tile(nf($r['angin_knot'], 1), 'Kecepatan angin', asal_simulasi_tag(), '#5E7480', 'knot') . stat_tile(nf($r['arus_ms'], 2), 'Kecepatan arus', asal_simulasi_tag(), '#2E9E9B', 'm/s') . '</div>'
        . '<p class="faint" style="font-size:12.5px;margin-top:10px">Pasang surut berasal dari prediksi resmi BIG/SRGI dan relatif terhadap MSL, sehingga dapat bernilai negatif saat surut. Gelombang, angin, dan arus belum tersedia lewat API terbuka BMKG dan masih simulasi. Nilai pada pukul ' . fmt_t($r['waktu']) . '.</p>';
}
function kondisi_compact(): string {
    $cur = nearest_osea(); if (!$cur) return empty_state('Belum ada data', 'Data oseanografi belum tersedia.', 'waves'); $r = $cur['row']; if (($zb = pasut_at(time())) !== null) { $r['pasut_m'] = round($zb, 3); $r['jenis_data'] = 'prediksi'; }
    $it = fn($i, $l, $v, $u, $src = '') => '<div style="display:flex;align-items:center;gap:12px;padding:9px 0;border-bottom:1px solid var(--line)"><span style="color:var(--primary)">' . ic($i, 22) . '</span><span style="flex:1">' . $l . ($src ? '<span class="sub">' . $src . '</span>' : '') . '</span><b class="num" style="font-size:17px">' . $v . '</b><span class="muted" style="width:42px">' . $u . '</span></div>';
    $sim = 'simulasi';
    return $it('waves', 'Pasang surut', nf($r['pasut_m'], 2), 'm', (($r['jenis_data'] ?? '') === 'prediksi' && pasut_ada()) ? 'prediksi BIG/SRGI' : $sim)
        . $it('chart', 'Gelombang', nf($r['gelombang_m'], 2), 'm', $sim) . $it('wind', 'Angin', nf($r['angin_knot'], 1), 'knot', $sim)
        . $it('compass', 'Arus', nf($r['arus_ms'], 2), 'm/s', $sim)
        . '<div class="faint" style="font-size:12.5px;margin-top:8px">Pukul ' . fmt_t($r['waktu']) . ' WIB. Pasang surut relatif MSL.</div>';
}

/* ---------- pemilih tampilan (tanpa JavaScript, memakai <details>) ---------- */
function skin_icon(): string { foreach (SKINS as $s) if ($s[0] === skin()) return $s[2]; return 'auto'; }
function skin_menu(): string {
    $h = '<details class="rel dd"><summary class="icon-btn" aria-label="Ubah tampilan" title="Ubah tampilan">' . ic(skin_icon(), 20) . '</summary><div class="pop" role="menu"><div class="ph-t">Tampilan</div><form method="post" action="index.php?p=skin">' . csrf_field() . '<input type="hidden" name="back" value="' . e($_SERVER['REQUEST_URI'] ?? 'index.php') . '">';
    foreach (SKINS as $s) $h .= '<button type="submit" name="skin" value="' . $s[0] . '" role="menuitemradio" aria-checked="' . (skin() === $s[0] ? 'true' : 'false') . '">' . ic($s[2], 18) . '<span style="flex:1">' . e($s[1]) . '</span>' . (skin() === $s[0] ? ic('check', 16) : '') . '</button>';
    return $h . '</form></div></details>';
}

/* ---------- kerangka halaman ---------- */
function html_head(string $title, string $extra = ''): string {
    $sk = skin(); return '<!DOCTYPE html><html lang="id"' . ($sk !== 'auto' ? ' data-skin="' . e($sk) . '"' : '') . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><title>' . e($title) . '</title><link rel="icon" href="assets/media/logo.png"><link rel="stylesheet" href="assets/css/leaflet.css"><link rel="stylesheet" href="assets/css/style.css?v=' . (int) @filemtime(APP_ROOT . '/assets/css/style.css') . '">' . $extra . '</head>';
}
function toasts(): string { $f = flashes(); if (!$f) return ''; $h = '<div class="toasts" aria-live="polite">'; foreach ($f as [$t, $m]) $h .= '<div class="toast ' . e($t) . '">' . ic($t === 'ok' ? 'checkc' : ($t === 'bad' ? 'octagon' : 'alert'), 20) . '<div>' . e($m) . '</div></div>'; return $h . '</div>'; }
function render_app(string $title, string $content, string $route, string $head_extra = '', string $foot = ''): void {
    $u = require_login(); $role = $u['peran']; $items = menu_items($role); $w = status_peringatan();
    echo html_head($title . ' | Peringatan Dini Rob Pulo Panjang', $head_extra), '<body>';
    echo '<input type="checkbox" id="navtoggle" class="navtoggle sr" aria-label="Buka menu"><label class="scrim" for="navtoggle"></label>';
    echo '<aside class="side" id="side" aria-label="Navigasi utama"><div class="brand wordmark"><img class="logo" src="assets/media/logo.png" alt="" width="46" height="41"><div><b>SEA WARNING</b><span>Pulo Panjang</span></div></div><nav class="nav">';
    foreach (menu($role) as [$g, $its]) { echo '<div class="nav-g">' . e($g) . '</div>'; foreach ($its as $i) echo '<a href="' . e(url($i[0])) . '"' . ($i[0] === $route ? ' aria-current="page"' : '') . '>' . ic($i[2], 20) . '<span>' . e($i[1]) . '</span></a>'; }
    echo '</nav><div class="side-user"><span class="avatar">' . e(mb_strtoupper(mb_substr(trim($u['nama']), 0, 1))) . '</span><div><b>' . e($u['nama']) . '</b><small>' . e(roles()[$role]['label']) . '</small></div><form method="post" action="index.php?p=logout" style="margin:0">' . csrf_field() . '<button class="icon-btn" type="submit" aria-label="Keluar" title="Keluar">' . ic('logout', 20) . '</button></form></div></aside>';
    echo '<div class="main"><header class="top"><label class="icon-btn menu-btn" for="navtoggle" aria-label="Buka menu">' . ic('menu', 22) . '</label><div class="grow"><div class="crumb">' . e(roles()[$role]['label']) . ' ' . ic('chevr', 14, 'faint') . ' <b>' . e($title) . '</b></div></div>';
    echo bmkg_topbar_chip();
    if ($w && $w['status'] !== 'Normal') { $sm = status_meta()[$w['status']]; echo '<a class="alert-chip" href="' . e(url('peringatan')) . '" style="--c:' . e($sm['c']) . '" title="' . e($w['judul']) . '">' . ic($sm['icon'], 17) . '<span>' . e($w['status']) . '</span></a>'; }
    if (in_array($role, PERAN_STAF_LAPORAN, true) && ($nl = laporan_menunggu())) echo '<a class="alert-chip" href="' . e(url('laporan')) . '" style="--c:#E6A100" title="Laporan warga menunggu verifikasi">' . ic('camera', 17) . '<span>' . $nl . ' laporan</span></a>';
    $demo = $role === 'admin' && cfg('demo_mode', false) && !cfg('mode_presentasi', false) ? note_box('<b>Mode demo menyala.</b> Siapa pun yang membuka halaman login dapat masuk sebagai Administrator tanpa kata sandi. Ubah <span class="mono">demo_mode</span> menjadi <span class="mono">false</span> di config.php sebelum web dapat diakses orang lain.', 'bad', 'alert') . '<div style="height:14px"></div>' : '';
    echo skin_menu(), '</header><main class="content" id="content">', $demo, $route === 'beranda' ? panduan_catatan_html($u) : '', $content, '</main></div>';
    echo '<nav class="bnav" aria-label="Navigasi cepat">'; foreach (BNAV[$role] as [$id, $lb]) echo '<a href="' . e(url($id)) . '"' . ($id === $route ? ' aria-current="page"' : '') . '>' . ic($items[$id][2], 22) . '<span>' . e($lb) . '</span></a>'; echo '<label for="navtoggle" role="button" tabindex="0">' . ic('menu', 22) . '<span>Menu</span></label></nav>';
    echo toasts(), $foot, '</body></html>';
}

/* ===================== Tampilan khusus warga: bahasa sehari-hari, tanpa istilah teknis ===================== */
function warga_judul_tingkat(int $rank): string { return ['Relatif aman', 'Perlu waspada', 'Rawan rob'][min($rank, 2)]; }
function warga_kata_pasut(string $key): string { return ['normal' => 'Biasa', 'waspada' => 'Agak tinggi', 'siaga' => 'Tinggi', 'bahaya' => 'Sangat tinggi'][$key] ?? 'Biasa'; }
function warga_tag_pasut(float $z): string {
    $a = pasut_status_ambang($z); if (!$a) return '<span class="tag plain">Belum ada data</span>';
    return '<span class="tag" style="--c:' . e($a['warna']) . '">' . e(warga_kata_pasut($a['key'])) . '</span>';
}
function wilayah_result_warga(array $w, array $o = []): string {
    $inf = info_wilayah($w['id_wilayah']); $t = $inf['tingkat'] ?? null; $mine = my_wil_id() === $w['id_wilayah'];
    $head = '<div><h2 style="font-size:26px">' . e($w['nama_wilayah']) . '</h2>' . ($w['jumlah_kk'] ? '<p class="muted" style="margin-top:4px">Sekitar ' . nf($w['jumlah_kk']) . ' kepala keluarga' . (($pd = penduduk_kampung((string) $w['nama_wilayah'])) ? ', sekitar ' . nf($pd['jiwa']) . ' jiwa' : '') . '</p>' : '') . '</div>';
    if (!$inf) return '<div class="stack">' . $head . note_box('Kampung ini belum dinilai. Silakan tanya perangkat desa.', 'warn', 'alert') . '</div>';
    $rk = rank_of($t['id_tingkat']);
    $h = '<div class="stack" style="gap:18px">' . $head . '<div class="lvl-card" style="--c:' . e($t['warna']) . ';padding:20px">' . lvl_icon($rk, 28) . '<b style="font-size:24px;display:block;margin:6px 0">' . e(warga_judul_tingkat($rk)) . '</b><p style="font-size:16px;line-height:1.5">' . e($t['makna_warga']) . '</p></div>';
    $h .= '<div><div class="lbl" style="margin-bottom:10px">Yang perlu Anda lakukan</div>' . steps_html($t['id_tingkat'], 4) . '</div>';
    $riw = where('riwayat_rob', fn($r) => $r['id_wilayah'] === $w['id_wilayah']); usort($riw, fn($a, $b) => strcmp($b['tanggal'], $a['tanggal']));
    if ($riw) $h .= '<p class="muted" style="font-size:15px">Rob terakhir yang tercatat di sini: <b>' . fmt_d($riw[0]['tanggal']) . '</b>, genangan sekitar ' . nfa($riw[0]['tinggi_genangan_cm']) . ' cm.</p>';
    if (($o['actions'] ?? true) !== false) $h .= '<div style="display:flex;gap:8px;flex-wrap:wrap"><form method="post" action="index.php?p=set-my" style="margin:0">' . csrf_field() . '<input type="hidden" name="id" value="' . $w['id_wilayah'] . '"><input type="hidden" name="back" value="' . e($_SERVER['REQUEST_URI'] ?? '') . '"><button class="btn ' . ($mine ? '' : 'btn-primary') . '" type="submit">' . ic('pin', 18) . ($mine ? 'Ini kampung saya' : 'Jadikan kampung saya') . '</button></form>' . (($o['mapBtn'] ?? true) !== false ? '<a class="btn" href="' . e(url('peta', ['sel' => $w['id_wilayah']])) . '">' . ic('map', 18) . 'Lihat di peta</a>' : '') . '</div>';
    return $h . '</div>';
}
/* Kartu status utama di beranda warga: satu kalimat jelas, dua keterangan singkat. */
function hero_warga(): string {
    $btn = '<a class="btn btn-primary" href="' . e(url('peta')) . '">Lihat peta kampung</a><a class="btn" href="' . e(url('tindakan')) . '">Apa yang harus dilakukan?</a><a class="btn" href="' . e(url('lapor')) . '">Laporkan genangan</a>';
    return hero_baru('Keadaan air laut di kampung kita', '', $btn, true);
}
