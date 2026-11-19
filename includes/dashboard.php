<?php
declare(strict_types=1);
/* Komponen halaman Beranda: kepala halaman, angka utama, tabel kampung, piramida penduduk, grafik pasang surut. */

function tanggal_panjang(?int $t = null): string {
    $t ??= time();
    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][(int) date('w', $t)];
    $bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][(int) date('n', $t)];
    return $hari . ', ' . date('j', $t) . ' ' . $bulan . ' ' . date('Y', $t);
}

/* Kepala halaman beranda: judul, status peringatan, dan ringkasan singkat di sisi kanan. */
function dash_banner(string $judul, string $sub, string $aksi = ''): string { return hero_baru($judul, $sub, $aksi, false); }

/* Baris angka utama. $items: [ikon, nilai, label, keterangan, warna, tautan?] */
function dash_kpi(array $items): string {
    $h = '<div class="db-kpis">';
    foreach ($items as $it) {
        [$icn, $v, $l, $s, $c] = $it; $href = $it[5] ?? '';
        $in = '<div class="db-kl">' . ic($icn, 17) . e($l) . '</div><div class="db-kv num">' . $v . '</div>' . ($s !== '' ? '<div class="db-ks">' . $s . '</div>' : '');
        $h .= $href ? '<a class="db-kpi" style="--c:' . e($c) . '" href="' . e($href) . '">' . $in . '</a>' : '<div class="db-kpi" style="--c:' . e($c) . '">' . $in . '</div>';
    }
    return $h . '</div>';
}
/* Piramida penduduk: laki-laki di kiri, perempuan di kanan. */
function dash_piramida(): string {
    $u = penduduk_usia(); if (!$u) return empty_state('Belum ada data kelompok usia', 'Isi bagian kelompok_usia pada data/penduduk.json.', 'users');
    $g = array_reverse($u['kelompok']); $mx = 1; $L = $P = 0;
    foreach ($g as $k) { $mx = max($mx, (int) $k['laki'], (int) $k['perempuan']); $L += (int) $k['laki']; $P += (int) $k['perempuan']; }
    $W = 560; $mid = 280; $lab = 44; $bw = $mid - $lab - 58; $rh = 44; $H = count($g) * $rh + 8;
    $s = '<svg class="db-pyr" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Piramida penduduk menurut kelompok usia">';
    foreach ($g as $i => $k) {
        $y = 4 + $i * $rh; $l = (int) $k['laki']; $p = (int) $k['perempuan']; $wl = $l / $mx * $bw; $wp = $p / $mx * $bw; $ty = $y + $rh / 2 + 5;
        $usia = $k['usia'] === '46 tahun ke atas' ? '46+' : str_replace(' tahun', '', $k['usia']);
        $s .= '<rect class="m" x="' . round($mid - $lab - $wl, 1) . '" y="' . ($y + 7) . '" width="' . round($wl, 1) . '" height="' . ($rh - 14) . '" rx="3"><title>Laki-laki ' . e($k['usia']) . ': ' . nf($l) . '</title></rect>'
            . '<rect class="f" x="' . ($mid + $lab) . '" y="' . ($y + 7) . '" width="' . round($wp, 1) . '" height="' . ($rh - 14) . '" rx="3"><title>Perempuan ' . e($k['usia']) . ': ' . nf($p) . '</title></rect>'
            . '<text class="lb" x="' . $mid . '" y="' . $ty . '" text-anchor="middle">' . e($usia) . '</text>'
            . '<text class="vl" x="' . round($mid - $lab - $wl - 6, 1) . '" y="' . $ty . '" text-anchor="end">' . nf($l) . '</text>'
            . '<text class="vl" x="' . round($mid + $lab + $wp + 6, 1) . '" y="' . $ty . '">' . nf($p) . '</text>';
    }
    return '<div class="db-pyr-h"><span class="m">Laki-laki <b class="num">' . nf($L) . '</b></span><span class="f">Perempuan <b class="num">' . nf($P) . '</b></span></div>' . $s . '</svg>'
        . '<p class="faint" style="font-size:12.5px;margin-top:6px">Usia dalam tahun. Sumber: ' . e($u['sumber']) . ', ' . nf($L + $P) . ' jiwa.</p>';
}

function dash_pasut(): string {
    $tc = tide_chart(); $cur = muka_air_kini();
    $head = $cur ? '<dl class="db-tide-h"><div><dt>Muka air sekarang</dt><dd class="num">' . nf($cur['row']['pasut_m'], 2) . ' m</dd></div>' . ($tc['peak'] ? '<div><dt>Tertinggi 24 jam ke depan</dt><dd class="num">' . nf($tc['peak']['pasut_m'], 2) . ' m, ' . fmt_t($tc['peak']['waktu']) . '</dd></div>' : '') . '</dl>' : '';
    return $head . $tc['html'];
}
