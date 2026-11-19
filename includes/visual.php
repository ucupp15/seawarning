<?php
declare(strict_types=1);
/*
 * KOMPONEN VISUAL (v15): kartu status, tabel kampung, ringkasan dampak, batang skor.
 * Semuanya SVG/HTML yang dirender di server dan digerakkan CSS; tidak memerlukan JavaScript.
 */

function status_kata(string $nm, bool $warga = false): string { return $warga && $nm === 'Normal' ? 'Aman' : $nm; }
function kata_kampung(string $n): string { return preg_replace('/^Kampung\s+/i', '', $n); }

/* ---------- batang skor dengan penanda batas tingkat ---------- */
function skor_maks_total(): float { $m = 0.0; foreach (tingkat_list() as $t) $m = max($m, (float) $t['skor_maks']); return $m ?: 3.0; }
function skor_bar(float $skor, ?string $warna = null, bool $angka = true): string {
    $mx = skor_maks_total(); $ts = tingkat_list(); $tick = '';
    foreach (array_slice($ts, 1) as $t) $tick .= '<u style="left:' . round(min(100, $t['skor_min'] / $mx * 100), 1) . '%"></u>';
    return '<span class="sbar" style="--c:' . e($warna ?? 'var(--primary)') . '"><span class="sbar-t"><i style="width:' . round(clamp($skor / $mx, 0, 1) * 100, 1) . '%"></i>' . $tick . '</span>' . ($angka ? '<b class="num">' . nf($skor, 2) . '</b>' : '') . '</span>';
}
function cm_bar(float $cm, float $maks = 60.0): string {
    $c = $cm >= 50 ? 'var(--bad)' : ($cm >= 30 ? '#EA6A12' : ($cm >= 15 ? 'var(--warn)' : 'var(--primary)'));
    return '<span class="sbar" style="--c:' . $c . '"><span class="sbar-t"><i style="width:' . round(clamp($cm / $maks, 0, 1) * 100, 1) . '%"></i></span><b class="num">' . nfa($cm) . '</b></span>';
}

/* ---------- papan duga pasang surut ---------- */
function tide_next_high(int $now): ?array {
    $t0 = (int) (floor($now / 3600) * 3600) - 3600; $v = [];
    for ($i = 0; $i <= 50; $i++) { $z = pasut_at($t0 + $i * 3600); if ($z === null) return null; $v[] = [$t0 + $i * 3600, $z]; }
    for ($i = 1; $i < count($v) - 1; $i++) if ($v[$i][0] > $now && $v[$i][1] >= $v[$i - 1][1] && $v[$i][1] > $v[$i + 1][1]) return $v[$i];
    return null;
}

/* ---------- ringkasan pasang surut (angka kecil di kartu status) ---------- */
function tide_info(): ?array {
    $amb = function_exists('pasut_ambang') && pasut_ada() ? pasut_ambang() : []; $tg = $amb['tingkat'] ?? []; $now = time();
    $big = pasut_ada(); $z = $big ? pasut_at($now) : null;
    if ($z === null) { $c = nearest_osea(); $z = $c ? (float) $c['row']['pasut_m'] : null; $big = false; }
    if ($z === null) return null;
    $w = isset($tg['waspada']) ? (float) $tg['waspada']['batas'] : null; $s = isset($tg['siaga']) ? (float) $tg['siaga']['batas'] : null;
    $p = $big ? pasut_at($now - 1800) : null; $nh = $big ? tide_next_high($now) : null;
    return ['z' => $z, 'naik' => $p === null ? null : $z >= $p, 'w' => $w, 's' => $s, 'nh' => $nh, 'big' => $big,
        'stt' => $w !== null && $z >= ($s ?? 9) ? 'Di atas ambang Siaga' : ($w !== null && $z >= $w ? 'Di atas ambang Waspada' : 'Di bawah ambang Waspada')];
}

function hero_dasar_html(bool $warga): string {
    $a = status_otomatis(); $h = '<ul class="h2-why">';
    foreach ($a['komponen'] as $k) {
        $c = status_meta()[STATUS_URUT[$k['rank']]]['c']; $kata = status_kata(STATUS_URUT[$k['rank']], $warga);
        $tx = match ($k['kunci']) {
            'pasut' => !$k['ada'] ? 'Prediksi pasang surut belum tersedia' : ($warga ? 'Pasang surut: air paling tinggi pukul ' . date('H.i', $k['puncak'][0]) : 'Pasang surut BIG: puncak ' . nf($k['puncak'][1], 2) . ' m pukul ' . date('H.i', $k['puncak'][0])),
            'bmkg' => !$k['ada'] ? 'Peringatan BMKG belum terhubung' : ($k['rank'] ? e_plain($k['teks']) : 'BMKG: tidak ada peringatan untuk Puloampel'),
            default => $k['rank'] ? ($k['n'] . ' laporan warga sudah diperiksa, air sampai ±' . $k['maks'] . ' cm') : 'Laporan warga: belum ada yang terverifikasi' };
        $h .= '<li style="--c:' . e($c) . '"><i></i><span>' . e($tx) . '</span><b>' . e($kata) . '</b></li>';
    }
    return $h . '</ul>';
}
function e_plain(string $s): string { return $s; }
/* ---------- kartu status ---------- */
function hero_baru(string $judul, string $sub, string $aksi = '', bool $warga = false): string {
    $w = status_peringatan(); $nm = $w['status'] ?? 'Normal'; $st = status_meta()[$nm]; $kata = status_kata($nm, $warga);
    $isi = $w ? (mb_strlen($w['isi']) > 200 ? rtrim(mb_substr($w['isi'], 0, 197)) . '…' : $w['isi']) : ($warga ? 'Tidak ada tanda bahaya rob saat ini. Tetap pantau kabar dari perangkat desa.' : ($st['say'] ?? ''));
    $ti = tide_info(); $tide = '';
    if ($ti) {
        $sisa = $ti['nh'] ? max(0, (int) round(($ti['nh'][0] - time()) / 60)) : 0; $sisaTxt = $sisa >= 60 ? intdiv($sisa, 60) . ' jam' . ($sisa % 60 >= 5 ? ' ' . ($sisa % 60) . ' menit' : '') : $sisa . ' menit';
        $tide = '<div class="sc-tide"><span class="sc-k">Tinggi air laut sekarang</span><div class="sc-v"><b class="num">' . e(nf($ti['z'], 2)) . '</b> m' . ($ti['naik'] === null ? '' : '<em>' . ($ti['naik'] ? 'naik' : 'surut') . '</em>') . '</div><div class="sc-s">' . e($ti['stt']) . ($ti['big'] ? '' : ' (data contoh)') . '</div>'
            . ($ti['nh'] ? '<div class="sc-s">Pasang tertinggi berikutnya ' . e(nf($ti['nh'][1], 2)) . ' m, pukul ' . date('H.i', $ti['nh'][0]) . ' WIB (' . e($sisaTxt) . ' lagi)</div>' : '') . '</div>';
    }
    return '<section class="status-card" style="--c:' . e($st['c']) . '" aria-label="Status banjir rob"><div class="sc-main"><div class="sc-top"><span class="sc-badge">' . e($kata) . '</span>' . ($w ? status_asal_tag($w) : '<span class="tag"><span>Otomatis</span></span>') . '<span class="sc-date">' . e(tanggal_panjang()) . '</span></div>'
        . '<h1>' . e($judul) . '</h1>' . ($sub !== '' ? '<p class="sc-sub">' . e($sub) . '</p>' : '') . '<p class="sc-say">' . e($isi) . '</p>' . hero_dasar_html($warga)
        . '<div class="sc-act">' . $aksi . '<a class="sc-link" href="' . e(url('peringatan')) . '">Dasar penentuan status</a></div></div>' . $tide . '</section>';
}

/* ---------- tabel kondisi kampung ---------- */
function dash_kampung(string $judul = 'Kondisi tiap kampung', string $ket = 'Diurutkan dari yang paling rawan.'): string {
    $rows = wil_rows(); usort($rows, fn($a, $b) => ($b['inf']['skor'] ?? 0) <=> ($a['inf']['skor'] ?? 0)); $tr = '';
    foreach ($rows as $r) {
        $w = $r['w']; $t = $r['inf']['tingkat'] ?? null; $sk = (float) ($r['inf']['skor'] ?? 0); $pdk = penduduk_kampung((string) $w['nama_wilayah']); $col = $t['warna'] ?? 'var(--ink-3)'; $dr = drivers((int) $w['id_wilayah'], 1)[0] ?? null;
        $tr .= '<tr><td><a href="' . e(url('peta', ['sel' => $w['id_wilayah']])) . '"><b>' . e(kata_kampung((string) $w['nama_wilayah'])) . '</b></a></td><td>' . tingkat_tag($t) . '</td><td>' . skor_bar($sk, $col) . '</td><td class="num">' . nf($pdk['jiwa'] ?? 0) . '</td><td class="hide-sm">' . ($dr ? e($dr['p']['nama_parameter']) : '–') . '</td></tr>';
    }
    return '<div class="panel" style="margin-bottom:18px"><div class="panel-h"><div><h2>' . e($judul) . '</h2><p>' . e($ket) . '</p></div><a class="btn btn-sm" href="' . e(url('peta')) . '">Buka peta</a></div><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Kampung</th><th>Tingkat</th><th>Skor (0 sampai ' . nf(skor_maks_total(), 0) . ')</th><th>Jiwa</th><th class="hide-sm">Penyebab utama</th></tr></thead><tbody>' . $tr . '</tbody></table></div></div>';
}

/* ---------- ringkasan dampak penduduk ---------- */
function dash_kpi_desa(): string {
    $grp = []; $tot = 0;
    foreach (array_reverse(distribusi()) as $x) { $j = 0; foreach ($x['rows'] as $r) { $p = penduduk_kampung((string) $r['w']['nama_wilayah']); $j += (int) ($p['jiwa'] ?? round(((int) ($r['w']['jumlah_kk'] ?? 0)) * 3.2)); } $grp[] = ['t' => $x['t'], 'n' => count($x['rows']), 'jiwa' => $j]; $tot += $j; }
    if (!$tot) return '';
    $h = '<div class="grid g4 keep2" style="margin-bottom:18px">' . stat_tile(nf($tot), 'Penduduk tercatat', count(tbl('wilayah')) . ' kampung');
    foreach ($grp as $g) $h .= stat_tile(nf($g['jiwa']), 'Jiwa, kerawanan ' . mb_strtolower($g['t']['nama_tingkat']), $g['n'] . ' kampung (' . nf($g['jiwa'] / $tot * 100, 0) . '%)', $g['t']['warna']);
    return $h . '</div>';
}
