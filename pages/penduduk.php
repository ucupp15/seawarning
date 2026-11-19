<?php
declare(strict_types=1);
/* Halaman Data Penduduk: total desa, kelompok usia dan jenis kelamin, serta rincian tiap kampung. Sumber: data/penduduk.json. */

function pg_penduduk(): array {
    $d = penduduk_desa(); $u = penduduk_usia(); $pd = penduduk_data(); $kamp = $pd['kampung'] ?? [];
    $kkDesa = $d ? (int) $d['kk'] : 0;
    $jiwaDesa = $u ? (int) sum(array_map(fn($k) => (int) $k['laki'] + (int) $k['perempuan'], $u['kelompok'])) : ($d ? (int) $d['jiwa'] : 0);
    $lak = $u ? (int) sum(array_column($u['kelompok'], 'laki')) : 0;
    $per = $u ? (int) sum(array_column($u['kelompok'], 'perempuan')) : 0;
    $kkKamp = (int) sum(array_column($kamp, 'kk')); $jiwaKamp = (int) sum(array_column($kamp, 'jiwa'));

    $kpi = '<section class="dn-row pd-kpi rv" aria-label="Ringkasan penduduk">'
        . '<div class="dn"><span class="dn-ic">' . ic('users', 20) . '</span><b class="num">' . nf($jiwaDesa) . '</b><span>Jiwa, total desa</span></div>'
        . '<div class="dn"><span class="dn-ic">' . ic('home', 20) . '</span><b class="num">' . nf($kkDesa) . '</b><span>Kepala keluarga, total desa</span></div>'
        . '<div class="dn"><span class="dn-ic">' . ic('users', 20) . '</span><b class="num">' . nf($lak) . '</b><span>Laki-laki</span></div>'
        . '<div class="dn"><span class="dn-ic">' . ic('users', 20) . '</span><b class="num">' . nf($per) . '</b><span>Perempuan</span></div>'
        . '</section>';

    $usia = '';
    if ($u) {
        $rows = '';
        foreach ($u['kelompok'] as $k) {
            $t = (int) $k['laki'] + (int) $k['perempuan'];
            $rows .= '<tr><td>' . e((string) $k['usia']) . '</td><td class="r num">' . nf((int) $k['laki']) . '</td><td class="r num">' . nf((int) $k['perempuan']) . '</td><td class="r num"><b>' . nf($t) . '</b></td><td class="r num">' . nf($jiwaDesa ? $t / $jiwaDesa * 100 : 0, 1) . '%</td></tr>';
        }
        $rows .= '<tr class="pd-tot"><td><b>Total</b></td><td class="r num"><b>' . nf($lak) . '</b></td><td class="r num"><b>' . nf($per) . '</b></td><td class="r num"><b>' . nf($jiwaDesa) . '</b></td><td class="r num">100%</td></tr>';
        $usia = '<section class="ds rv"><div class="ds-h"><span class="ds-k">Kelompok usia dan jenis kelamin</span><h2>Komposisi penduduk desa</h2><p>Jumlah penduduk menurut kelompok usia, laki-laki dan perempuan, seluruh Desa Pulo Panjang.</p></div>'
            . '<div class="grid g2 pd-g" style="align-items:center"><div class="panel">' . dash_piramida() . '</div>'
            . '<div class="panel"><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Kelompok usia</th><th class="r">Laki-laki</th><th class="r">Perempuan</th><th class="r">Total</th><th class="r">Porsi</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '<p class="muted pd-note">' . e((string) ($u['catatan'] ?? '')) . '</p></div></div></section>';
    }

    $mx = max(1, ...array_column($kamp, 'jiwa') ?: [1]); $body = '';
    foreach ($kamp as $k) {
        $pc = round((int) $k['jiwa'] / $mx * 100, 1); $share = $jiwaKamp ? (int) $k['jiwa'] / $jiwaKamp * 100 : 0;
        $body .= '<tr><td><b>' . e(kata_kampung((string) $k['kampung'])) . '</b></td><td class="r num">' . nf((int) $k['kk']) . '</td><td class="r num"><b>' . nf((int) $k['jiwa']) . '</b></td><td class="r num">' . nf($share, 1) . '%</td><td><span class="pd-bar"><i style="--w:' . $pc . '%"></i></span></td><td>' . e((string) ($k['kepadatan'] ?? '')) . '</td></tr>';
    }
    $kampung = '<section class="ds rv"><div class="ds-h"><span class="ds-k">Rincian per kampung</span><h2>Penduduk tiap kampung</h2><p>Jumlah KK dan jiwa untuk tujuh kampung di Pulo Panjang, beserta tingkat kepadatannya.</p></div>'
        . '<div class="panel"><div class="tbl-wrap"><table class="tbl pd-tbl"><thead><tr><th>Kampung</th><th class="r">KK</th><th class="r">Jiwa</th><th class="r">Porsi</th><th>Perbandingan</th><th>Kepadatan</th></tr></thead><tbody>' . $body
        . '<tr class="pd-tot"><td><b>Total tujuh kampung</b></td><td class="r num"><b>' . nf($kkKamp) . '</b></td><td class="r num"><b>' . nf($jiwaKamp) . '</b></td><td class="r num">100%</td><td></td><td></td></tr></tbody></table></div>'
        . '<p class="muted pd-note"><b>Catatan:</b> jumlah jiwa tiap kampung (' . nf($jiwaKamp) . ' jiwa) sama dengan total desa dari papan data penduduk. KK per kampung berasal dari Sensus Registrasi Penduduk Kabupaten Serang (' . nf($kkKamp) . ' KK), sedangkan total KK desa (' . nf($kkDesa) . ' KK) berasal dari Rekap DTSEN. ' . e((string) ($pd['catatan_jiwa'] ?? '')) . '</p></div></section>';

    $h = '<div class="dash">' . hero_baru('Data Penduduk Pulo Panjang', 'Jumlah penduduk desa, kelompok usia dan jenis kelamin, serta rincian tiap kampung.', '', false)
        . '<div class="pd-wrap">' . $kpi . $usia . $kampung . '</div>'
        . '<p class="tj-src">Sumber: ' . e((string) ($pd['sumber'] ?? '')) . '; ' . e((string) ($d['sumber'] ?? '')) . '.</p></div>';
    return ['title' => 'Data Penduduk', 'html' => $h];
}
