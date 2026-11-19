<?php
declare(strict_types=1);
/*
 * Halaman Pasang Surut: analisis data prediksi BIG/SRGI 2024-2028.
 * Tampilan: ringkasan lima tahun, detail per tahun, detail per bulan, dan
 * matriks perbandingan tahun x bulan (tipe pasut dan muka air tertinggi).
 */

const PASUT_W = 760; // lebar grafik baku

/* ---------------------------------------------------------------- bantu ---------- */
function pasut_fmt_m($v, int $d = 3): string { return $v === null ? '–' : (($v >= 0 ? '+' : '') . nf($v, $d)); }

/* Kartu statistik muka air untuk satu blok analisis (tahun, bulan, atau gabungan). */
function pasut_tiles(array $a, array $ev = []): string {
    $s = $a['stat']; $t = $a['tipe'];
    $h = stat_tile(nf($t['F'] ?? 0, 3), 'Bilangan Formzahl', e($t['nama'] ?? '–'), $t['warna'] ?? 'var(--primary)')
        . stat_tile(pasut_fmt_m($s['z_maks'] ?? null), 'Muka air tertinggi', $a['ekstrem']['maks_ts'] ? fmt_dt($a['ekstrem']['maks_ts']) . ' WIB' : '', '#D64933', 'm')
        . stat_tile(pasut_fmt_m($s['z_min'] ?? null), 'Muka air terendah', $a['ekstrem']['min_ts'] ? fmt_dt($a['ekstrem']['min_ts']) . ' WIB' : '', '#2E6FB0', 'm')
        . stat_tile(nf($s['tunggang_maks'] ?? 0, 3), 'Tunggang maksimum', 'Selisih tertinggi–terendah', '#5E7480', 'm');
    if ($ev) {
        $h .= stat_tile(nf($ev['waspada']['jam'] ?? 0), 'Jam ≥ Waspada', nf($ev['waspada']['hari'] ?? 0) . ' hari', '#E6A100', 'jam')
            . stat_tile(nf($ev['siaga']['jam'] ?? 0), 'Jam ≥ Siaga', nf($ev['siaga']['hari'] ?? 0) . ' hari', '#D64933', 'jam')
            . stat_tile(nf($ev['bahaya']['jam'] ?? 0), 'Jam ≥ Bahaya', nf($ev['bahaya']['hari'] ?? 0) . ' hari', '#8E2B20', 'jam')
            . stat_tile(pasut_fmt_m($s['MHWL'] ?? null), 'MHWL', 'Rata-rata pasang tinggi', '#2E9E9B', 'm');
    }
    return '<div class="grid g4 keep2" style="margin-bottom:18px">' . $h . '</div>';
}

/* Tabel konstanta harmonik. */
function pasut_tabel_konstanta(array $a, array $set): string {
    $rows = ''; $adaInf = false;
    foreach ($set as $c) {
        $x = $a['amp'][$c] ?? null; if (!$x) continue;
        $inf = !empty($x['inferensi']);
        $rows .= '<tr><td><b class="mono">' . e($c) . '</b>' . ($inf ? '<span class="sub" style="color:var(--warn)">inferensi</span>' : '') . '</td>'
            . '<td class="r num">' . nf(PASUT_SPEED[$c], 7) . '</td><td class="r num">'
            . nf($x['A'], 4) . '</td><td class="r num">' . nf($x['g'], 2) . '</td></tr>';
        $adaInf = $adaInf || $inf;
    }
    return '<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Komponen</th><th class="r">σ (°/jam)</th><th class="r">Amplitudo A (m)</th><th class="r">Fase g (°)</th></tr></thead><tbody>'
        . $rows . '</tbody></table></div><p class="faint" style="font-size:12.5px;margin-top:10px">Model: z(t) = Z₀ + Σ A·cos(σt − g), dengan Z₀ = '
        . pasut_fmt_m($a['Z0'], 5) . ' m. Sisa (RMS) ' . nf($a['rms'], 4) . ' m; ragam terjelaskan R² = ' . nf($a['r2'] * 100, 2) . '%. '
        . 'Seluruh komponen dicocokkan dengan least-squares'
        . ($adaInf ? ', kecuali yang bertanda <b>inferensi</b> yang diisi dari rasio baku karena tidak terpisah pada rekaman sependek ini' : '') . '.</p>';
}

/* Tabel muka air acuan. */
function pasut_tabel_muka_air(array $s): string {
    $b = [
        ['HHWL (Highest High Water Level)', $s['HHWL_formula'] ?? null, 'Z₀ + (M2+S2+N2+K2+K1+O1+P1)'],
        ['Muka air tertinggi teramati', $s['z_maks'] ?? null, 'Nilai maksimum pada deret prediksi'],
        ['MHWL (Mean High Water Level)', $s['MHWL'] ?? null, 'Rata-rata seluruh puncak pasang'],
        ['MSL (Mean Sea Level)', $s['Z0_MSL'] ?? null, 'Z₀ hasil analisis harmonik'],
        ['MLWL (Mean Low Water Level)', $s['MLWL'] ?? null, 'Rata-rata seluruh lembah surut'],
        ['Muka air terendah teramati', $s['z_min'] ?? null, 'Nilai minimum pada deret prediksi'],
        ['LLWL (Lowest Low Water Level)', $s['LLWL_formula'] ?? null, 'Z₀ − (M2+S2+N2+K2+K1+O1+P1)'],
        ['LWS (chart datum)', $s['LWS'] ?? null, 'Z₀ − (M2+S2+K1+O1)'],
    ];
    $rows = '';
    foreach ($b as [$l, $v, $ket]) $rows .= '<tr><td><b>' . e($l) . '</b></td><td class="r num">' . pasut_fmt_m($v, 4) . '</td><td class="wrap muted">' . e($ket) . '</td></tr>';
    return '<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Muka air acuan</th><th class="r">Tinggi (m dari MSL)</th><th>Perhitungan</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
}

/* Garis ambang rob sebagai penanda pada grafik. */
/* $garis: kunci ambang yang digambar sebagai garis pada grafik di atasnya. */
function pasut_catatan_ambang(array $garis = ['waspada', 'siaga']): string {
    $am = pasut_ambang(); if (!$am) return '';
    $it = '';
    foreach ($am['tingkat'] as $k => $t) {
        $g = in_array($k, $garis, true);
        $it .= '<span class="it"><svg class="sw" width="22" height="16" viewBox="0 0 22 16">'
            . ($g ? '<rect y="6" width="22" height="4" rx="2" fill="' . e($t['warna']) . '"/>' : '<rect width="22" height="16" rx="4" fill="' . e($t['warna']) . '" opacity=".45"/>')
            . '</svg>' . e($t['nama']) . ' ≥ <b class="num" style="margin-left:4px">' . pasut_fmt_m($t['batas']) . ' m</b></span>';
    }
    return '<div class="legend">' . $it . '</div><p class="faint" style="font-size:12px;margin-top:4px">Ambang bergaris tebal digambar sebagai garis pada grafik; sisanya hanya nilai acuan.</p>';
}

/* ---------------------------------------------------------------- matriks tahun x bulan ---------- */
function pasut_matriks(string $mode): string {
    $ys = pasut_tahun_analisis();
    $th = '<th style="position:sticky;left:0;z-index:2">Tahun</th>';
    foreach (BLN as $b) $th .= '<th class="c">' . e($b) . '</th>';
    $th .= '<th class="c">Tahun penuh</th>';
    $tb = '';
    foreach ($ys as $y) {
        $a = pasut_analisis($y); if (!$a) continue;
        $ev = pasut_kejadian_tahun($y);
        $tb .= '<tr><td style="position:sticky;left:0;background:var(--surface);font-weight:700"><a href="' . e(url('pasut', ['th' => $y])) . '">' . $y . '</a></td>';
        for ($b = 1; $b <= 12; $b++) {
            $m = $a['bulan'][$b] ?? null;
            if (!$m) { $tb .= '<td class="c faint">–</td>'; continue; }
            [$v, $warna, $tip] = match ($mode) {
                'maks' => [pasut_fmt_m($m['stat']['z_maks'], 2), pasut_warna_skala($m['stat']['z_maks']), 'Tertinggi ' . pasut_fmt_m($m['stat']['z_maks'], 3) . ' m'],
                'rob' => [(string) (int) ($ev['bulan'][$b]['waspada']['jam'] ?? 0), pasut_warna_jam((int) ($ev['bulan'][$b]['waspada']['jam'] ?? 0)), ($ev['bulan'][$b]['waspada']['jam'] ?? 0) . ' jam ≥ Waspada'],
                default => [nf($m['F'], 2), $m['tipe']['warna'], $m['tipe']['nama'] . ', F = ' . nf($m['F'], 4)],
            };
            $tb .= '<td class="c"><a href="' . e(url('pasut', ['th' => $y, 'bl' => $b])) . '" style="text-decoration:none"><span class="cell-heat num" style="background:color-mix(in srgb,' . e($warna) . ' 30%,var(--surface));border:var(--bw) solid color-mix(in srgb,' . e($warna) . ' 72%,transparent)" title="' . e(BLN_PANJANG[$b - 1] . ' ' . $y . ': ' . $tip) . '">' . e($v) . '</span></a></td>';
        }
        $tot = match ($mode) {
            'maks' => pasut_fmt_m($a['stat']['z_maks'], 2),
            'rob' => (string) (int) ($ev['tahun']['waspada']['jam'] ?? 0),
            default => nf($a['F'], 2),
        };
        $tb .= '<td class="c"><b class="num">' . e($tot) . '</b></td></tr>';
    }
    return '<div class="tbl-wrap"><table class="tbl"><thead><tr>' . $th . '</tr></thead><tbody>' . $tb . '</tbody></table></div>';
}
function pasut_warna_skala(?float $z): string {
    $am = pasut_ambang(); if (!$am || $z === null) return 'var(--surface-3)';
    $hit = '#2E9E6B';
    foreach ($am['tingkat'] as $t) if ($z >= $t['batas']) $hit = $t['warna'];
    return $hit;
}
function pasut_warna_jam(int $j): string { return $j === 0 ? '#2E9E6B' : ($j < 15 ? '#E6A100' : ($j < 30 ? '#D64933' : '#8E2B20')); }

/* ---------------------------------------------------------------- grafik ---------- */
/* Grafik maks/min harian untuk seluruh periode atau satu tahun. */
function pasut_grafik_harian(?int $tahun = null, int $h = 260): string {
    $rows = pasut_seri_harian();
    if ($tahun !== null) $rows = array_values(array_filter($rows, fn($r) => (int) substr($r['tgl'], 0, 4) === $tahun));
    if (count($rows) < 2) return empty_state('Data belum tersedia', 'Belum ada data pasang surut pada rentang ini.', 'waves');
    $am = pasut_ambang();
    $xt = [];
    foreach ($rows as $r) {
        [$yy, $mm, $dd] = explode('-', $r['tgl']);
        if ($dd === '01' && ($tahun !== null || $mm === '01' || $mm === '07')) $xt[] = [$r['ts'], $tahun !== null ? BLN[(int) $mm - 1] : BLN[(int) $mm - 1] . ' ' . substr($yy, 2)];
    }
    $ser = [
        ['name' => 'Tertinggi harian', 'color' => '#D64933', 'pts' => array_map(fn($r) => [$r['ts'], $r['maks']], $rows), 'fmt' => fn($v) => nf($v, 3) . ' m'],
        ['name' => 'Terendah harian', 'color' => '#2E6FB0', 'pts' => array_map(fn($r) => [$r['ts'], $r['min']], $rows), 'fmt' => fn($v) => nf($v, 3) . ' m'],
    ];
    if ($am) foreach (['waspada', 'siaga'] as $k) {
        $b = $am['tingkat'][$k]['batas'];
        $ser[] = ['name' => 'Ambang ' . $am['tingkat'][$k]['nama'], 'color' => $am['tingkat'][$k]['warna'],
                  'pts' => [[$rows[0]['ts'], $b], [end($rows)['ts'], $b]], 'fmt' => fn($v) => nf($v, 3) . ' m'];
    }
    return line_chart(['series' => $ser, 'xTicks' => $xt, 'now' => time(), 'yFmt' => fn($v) => nf($v, 2),
        'xFmt' => fn($t) => fmt_d($t), 'label' => 'Grafik muka air harian', 'w' => PASUT_W, 'h' => $h]);
}
/* Grafik per jam untuk satu bulan. */
function pasut_grafik_bulan(int $tahun, int $bulan, int $h = 280): string {
    $t0 = (int) mktime(0, 0, 0, $bulan, 1, $tahun);
    $t1 = (int) mktime(23, 0, 0, $bulan, (int) date('t', $t0), $tahun);
    $pts = pasut_deret($t0, $t1);
    if (count($pts) < 2) return empty_state('Data belum tersedia', 'Belum ada data pasang surut pada bulan ini.', 'waves');
    $am = pasut_ambang(); $xt = [];
    for ($d = 1; $d <= (int) date('t', $t0); $d += 5) $xt[] = [(int) mktime(0, 0, 0, $bulan, $d, $tahun), (string) $d];
    $ser = [['name' => 'Muka air', 'color' => '#0D84A8', 'pts' => $pts, 'fmt' => fn($v) => nf($v, 3) . ' m']];
    if ($am) foreach (['waspada', 'siaga', 'bahaya'] as $k) {
        $b = $am['tingkat'][$k]['batas'];
        $ser[] = ['name' => 'Ambang ' . $am['tingkat'][$k]['nama'], 'color' => $am['tingkat'][$k]['warna'],
                  'pts' => [[$pts[0][0], $b], [end($pts)[0], $b]], 'fmt' => fn($v) => nf($v, 3) . ' m'];
    }
    return line_chart(['series' => $ser, 'xTicks' => $xt, 'area' => true, 'now' => time(), 'yFmt' => fn($v) => nf($v, 2),
        'xFmt' => fn($t) => fmt_dt($t) . ' WIB', 'label' => 'Grafik pasang surut per jam', 'w' => PASUT_W, 'h' => $h]);
}

/* ---------------------------------------------------------------- pembanding ---------- */
/* Baris pembanding: satu blok terhadap blok lain (bulan sebelumnya/sesudahnya, atau tahun lain). */
function pasut_baris_banding(string $label, ?array $a, ?array $ev, ?array $acuan, string $href = ''): string {
    if (!$a) return '<tr><td>' . e($label) . '</td><td colspan="5" class="faint">Data tidak tersedia</td></tr>';
    $d = fn(?float $x, ?float $y) => ($x === null || $y === null) ? '' : '<span class="sub num" style="color:' . ($x - $y > 0 ? 'var(--bad)' : ($x - $y < 0 ? 'var(--ok)' : 'var(--ink-3)')) . '">' . ($x - $y > 0 ? '▲ +' : ($x - $y < 0 ? '▼ ' : '')) . nf($x - $y, 3) . '</span>';
    $nm = $href ? '<a href="' . e($href) . '"><b>' . e($label) . '</b></a>' : '<b>' . e($label) . '</b>';
    return '<tr><td>' . $nm . '</td>'
        . '<td>' . pasut_tipe_tag($a['tipe']) . '<span class="sub num">F = ' . nf($a['F'], 3) . '</span></td>'
        . '<td class="r num">' . pasut_fmt_m($a['stat']['z_maks']) . ($acuan ? $d($a['stat']['z_maks'], $acuan['stat']['z_maks']) : '') . '</td>'
        . '<td class="r num">' . pasut_fmt_m($a['stat']['z_min']) . ($acuan ? $d($a['stat']['z_min'], $acuan['stat']['z_min']) : '') . '</td>'
        . '<td class="r num">' . nf($a['stat']['tunggang_maks'], 3) . ($acuan ? $d($a['stat']['tunggang_maks'], $acuan['stat']['tunggang_maks']) : '') . '</td>'
        . '<td class="r num">' . (int) ($ev['waspada']['jam'] ?? 0) . ' jam</td></tr>';
}
function pasut_tabel_banding(string $judul, array $baris): string {
    return '<div class="panel"><div class="panel-h"><h2>' . e($judul) . '</h2></div><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Periode</th><th>Tipe pasut</th><th class="r">Tertinggi (m)</th><th class="r">Terendah (m)</th><th class="r">Tunggang (m)</th><th class="r">≥ Waspada</th></tr></thead><tbody>'
        . implode('', $baris) . '</tbody></table></div><p class="faint" style="font-size:12.5px;margin-top:10px">Tanda ▲ dan ▼ menunjukkan selisih terhadap periode yang sedang dibuka.</p></div>';
}

/* ---------------------------------------------------------------- halaman ---------- */
function pg_pasut(): array {
    if (!pasut_ada()) {
        return ['title' => 'Pasang Surut', 'html' => page_head('Pasang Surut', 'Analisis data prediksi pasang surut BIG/SRGI.')
            . note_box('Berkas data prediksi BIG tidak ditemukan di <span class="mono">data/pasut_big/</span>. '
                . 'Letakkan berkas <span class="mono">data_pasut&lt;tahun&gt;.txt</span> hasil unduhan dari BIG pada folder tersebut, lalu muat ulang halaman ini.', 'warn', 'alert')];
    }
    if (user_role() === 'warga') return pasut_view_warga();
    $ys = pasut_tahun_analisis();
    $th = (int) gv('th', 0);
    $bl = (int) gv('bl', 0);
    if (!in_array($th, $ys, true)) $th = 0;
    if ($bl < 1 || $bl > 12) $bl = 0;

    /* unduhan CSV */
    if (gv('unduh') === 'csv') return pasut_unduh($th, $bl, $ys);

    $segTahun = seg(array_merge([['0', '5 tahun', 'layers']], array_map(fn($y) => [(string) $y, (string) $y, 'calendar'], $ys)),
        (string) $th, fn($v) => url('pasut', $v !== '0' ? ['th' => (int) $v] : []), 'Periode');
    $acts = $segTahun . '<a class="btn" href="' . e(url('pasut', array_filter(['th' => $th, 'bl' => $bl, 'unduh' => 'csv']))) . '">' . ic('download', 18) . 'Unduh CSV</a>';

    if ($th && $bl) return pasut_view_bulan($th, $bl, $ys, $acts);
    if ($th) return pasut_view_tahun($th, $ys, $acts);
    return pasut_view_gabungan($ys, $acts);
}

/* ---- tampilan gabungan lima tahun ---- */
function pasut_view_gabungan(array $ys, string $acts): array {
    $g = pasut_gabungan();
    $mode = in_array((string) gv('m'), ['maks', 'rob'], true) ? (string) gv('m') : 'formzahl';
    $am = pasut_ambang();
    $h = page_head('Pasang Surut Pulo Panjang', 'Analisis harmonik data prediksi BIG/SRGI ' . $ys[0] . '–' . $ys[count($ys) - 1]
        . ' sebagai dasar prediksi rob lima tahun ke depan.', $acts);

    if (!$g) return ['title' => 'Pasang Surut', 'html' => $h . empty_state('Analisis gagal', 'Data terbaca tetapi analisis harmonik tidak dapat diselesaikan.', 'alert')];

    $h .= pasut_tiles($g);
    $h .= '<div class="panel" style="margin-bottom:18px"><div class="panel-h"><div><h2>Tipe pasang surut</h2><p>Kesimpulan dari seluruh ' . nf($g['jam']) . ' jam data</p></div>' . pasut_tipe_tag($g['tipe'], true) . '</div>'
        . '<div class="lvl-card" style="--c:' . e($g['tipe']['warna']) . '">' . ic('waves', 22) . '<b>' . e($g['tipe']['nama']) . ' (F = ' . nf($g['F'], 4) . ')</b><p>' . e($g['tipe']['desc']) . '</p></div>'
        . '<p class="muted" style="margin-top:12px;font-size:14px">Bilangan Formzahl F = (A<sub>K1</sub> + A<sub>O1</sub>) / (A<sub>M2</sub> + A<sub>S2</sub>) = ('
        . nf($g['amp']['K1']['A'], 4) . ' + ' . nf($g['amp']['O1']['A'], 4) . ') / (' . nf($g['amp']['M2']['A'], 4) . ' + ' . nf($g['amp']['S2']['A'], 4) . ') = <b>' . nf($g['F'], 4) . '</b>. '
        . 'Batas klasifikasi: F ≤ 0,25 semidiurnal; 0,25 &lt; F ≤ 1,5 campuran condong ganda; 1,5 &lt; F ≤ 3,0 campuran condong tunggal; F &gt; 3,0 diurnal.</p></div>';

    $h .= '<div class="panel" style="margin-bottom:18px"><div class="panel-h"><div><h2>Muka air harian ' . $ys[0] . '–' . $ys[count($ys) - 1] . '</h2><p>Nilai tertinggi dan terendah setiap hari, dengan garis ambang</p></div></div>'
        . pasut_grafik_harian(null, 280) . pasut_catatan_ambang() . '</div>';

    $segM = seg([['formzahl', 'Formzahl'], ['maks', 'Muka air tertinggi'], ['rob', 'Jam ≥ Waspada']], $mode, fn($v) => url('pasut', ['m' => $v]), 'Isi matriks');
    $h .= '<div class="panel" style="margin-bottom:18px"><div class="panel-h"><div><h2>Perbandingan tahun × bulan</h2><p>Klik sel untuk membuka rincian bulan tersebut</p></div>' . $segM . '</div>'
        . pasut_matriks($mode) . '<p class="faint" style="font-size:12.5px;margin-top:10px">'
        . ($mode === 'formzahl' ? 'Warna mengikuti tipe pasut bulan itu. Karena F berubah antar bulan, tipe pasut Pulo Panjang dapat berpindah kelas antara campuran condong ganda dan campuran condong tunggal.'
            : ($mode === 'maks' ? 'Warna mengikuti ambang rob yang terlampaui oleh muka air tertinggi bulan itu.'
                : 'Jumlah jam dengan muka air mencapai atau melewati ambang Waspada.')) . '</p></div>';


    /* daftar hari tertinggi */
    $top = pasut_hari_tertinggi(15); $tr = '';
    foreach ($top as $i => $r) {
        $st = pasut_status_ambang($r['z']);
        $tr .= '<tr><td class="c num">' . ($i + 1) . '</td><td><b>' . fmt_day($r['tgl']) . ' ' . substr($r['tgl'], 0, 4) . '</b></td><td class="r num">'
            . pasut_fmt_m($r['z']) . '</td><td class="num">' . date('H.i', $r['ts']) . ' WIB</td><td><span class="tag" style="--c:' . e($st['warna']) . '">' . e($st['nama']) . '</span></td>'
            . '<td><a href="' . e(url('pasut', ['th' => (int) substr($r['tgl'], 0, 4), 'bl' => (int) substr($r['tgl'], 5, 2)])) . '">Lihat bulan</a></td></tr>';
    }
    $h .= '<div class="panel" style="margin-bottom:18px"><div class="panel-h"><div><h2>15 hari dengan muka air tertinggi</h2><p>Periode ' . $ys[0] . '–' . $ys[count($ys) - 1] . ', untuk penjadwalan kesiapsiagaan</p></div></div>'
        . '<div class="tbl-wrap"><table class="tbl"><thead><tr><th class="c">#</th><th>Tanggal</th><th class="r">Muka air (m)</th><th>Puncak</th><th>Ambang</th><th></th></tr></thead><tbody>' . $tr . '</tbody></table></div>'
        . note_box('Puncak muka air tertinggi <b>tidak jatuh pada bulan purnama</b>, melainkan mengelompok pada bulan November–Januari dan Mei–Juni. '
            . 'Penyebabnya: di perairan ini komponen diurnal K1 dan O1 lebih besar daripada komponen semidiurnal M2 dan S2 (F = ' . nf($g['F'], 2) . '), '
            . 'sehingga pasang terbesar mengikuti <b>deklinasi bulan</b> (pasang tropik), bukan fase bulan baru atau purnama (pasang perbani–purnama). '
            . 'Istilah "rob purnama" atau "rob supermoon" karena itu kurang tepat untuk Pulo Panjang.', '', 'info') . '</div>';

    return ['title' => 'Pasang Surut', 'html' => $h];
}

/* ---- tampilan satu tahun ---- */
function pasut_view_tahun(int $y, array $ys, string $acts): array {
    $a = pasut_analisis($y); $ev = pasut_kejadian_tahun($y);
    $h = page_head('Pasang Surut ' . $y, 'Analisis harmonik dan ringkasan bulanan data prediksi BIG/SRGI tahun ' . $y . '.', $acts);
    if (!$a) return ['title' => 'Pasang Surut ' . $y, 'html' => $h . empty_state('Analisis gagal', 'Data tahun ' . $y . ' tidak dapat dianalisis.', 'alert')];

    $h .= pasut_tiles($a, $ev['tahun'] ?? []);
    $h .= '<div class="panel" style="margin-bottom:18px"><div class="panel-h"><div><h2>Muka air harian ' . $y . '</h2><p>Tertinggi dan terendah setiap hari</p></div>' . pasut_tipe_tag($a['tipe'], true) . '</div>'
        . pasut_grafik_harian($y, 270) . pasut_catatan_ambang() . '</div>';

    /* tabel bulanan */
    $tr = '';
    for ($b = 1; $b <= 12; $b++) {
        $m = $a['bulan'][$b] ?? null; $evb = $ev['bulan'][$b] ?? [];
        if (!$m) { $tr .= '<tr><td><b>' . BLN_PANJANG[$b - 1] . '</b></td><td colspan="7" class="faint">Tidak dianalisis</td></tr>'; continue; }
        $tr .= '<tr><td><a href="' . e(url('pasut', ['th' => $y, 'bl' => $b])) . '"><b>' . BLN_PANJANG[$b - 1] . '</b></a>'
            . (!$m['lengkap'] ? '<span class="sub" style="color:var(--warn)">' . $m['jam'] . '/' . $m['jam_diharapkan'] . ' jam</span>' : '') . '</td>'
            . '<td class="r num">' . nf($m['F'], 3) . '</td><td>' . pasut_tipe_tag($m['tipe']) . '</td>'
            . '<td class="r num">' . pasut_fmt_m($m['stat']['z_maks']) . '</td>'
            . '<td class="r num">' . pasut_fmt_m($m['stat']['z_min']) . '</td>'
            . '<td class="r num">' . nf($m['stat']['tunggang_maks'], 3) . '</td>'
            . '<td class="r num">' . (int) ($evb['waspada']['jam'] ?? 0) . '</td>'
            . '<td class="r num">' . (int) ($evb['siaga']['jam'] ?? 0) . '</td></tr>';
    }
    $h .= '<div class="panel" style="margin-bottom:18px"><div class="panel-h"><div><h2>Ringkasan per bulan ' . $y . '</h2><p>Klik nama bulan untuk rincian dan pembandingnya</p></div></div>'
        . '<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Bulan</th><th class="r">F</th><th>Tipe pasut</th><th class="r">Tertinggi (m)</th><th class="r">Terendah (m)</th><th class="r">Tunggang (m)</th><th class="r">Jam ≥ Waspada</th><th class="r">Jam ≥ Siaga</th></tr></thead><tbody>' . $tr . '</tbody></table></div></div>';

    /* pembanding antar tahun */
    $i = array_search($y, $ys, true);
    $baris = [pasut_baris_banding($y . ' (dibuka)', $a, $ev['tahun'] ?? [], null)];
    foreach ($ys as $yy) {
        if ($yy === $y) continue;
        $aa = pasut_analisis($yy); $ee = pasut_kejadian_tahun($yy);
        $baris[] = pasut_baris_banding((string) $yy . ($yy < $y ? ' (sebelumnya)' : ' (sesudahnya)'), $aa, $ee['tahun'] ?? [], $a, url('pasut', ['th' => $yy]));
    }
    $h .= pasut_tabel_banding('Perbandingan dengan tahun lain', $baris);

    $h .= '<div class="grid g2" style="margin:18px 0">'
        . '<div class="panel"><div class="panel-h"><h2>Konstanta harmonik ' . $y . '</h2></div>' . pasut_tabel_konstanta($a, PASUT_SET_TAHUN) . '</div>'
        . '<div class="panel"><div class="panel-h"><h2>Muka air acuan ' . $y . '</h2></div>' . pasut_tabel_muka_air($a['stat']) . '</div></div>';

    return ['title' => 'Pasang Surut ' . $y, 'html' => $h];
}

/* ---- tampilan satu bulan ---- */
function pasut_view_bulan(int $y, int $b, array $ys, string $acts): array {
    $a = pasut_analisis($y); $m = $a['bulan'][$b] ?? null; $ev = pasut_kejadian_tahun($y);
    $nama = BLN_PANJANG[$b - 1] . ' ' . $y;
    $segBl = seg(array_map(fn($i) => [(string) ($i + 1), BLN[$i]], range(0, 11)), (string) $b, fn($v) => url('pasut', ['th' => $y, 'bl' => (int) $v]), 'Bulan');
    $h = page_head('Pasang Surut ' . $nama, 'Rincian per jam, tipe pasut bulan ini, dan pembanding bulan sebelumnya, sesudahnya, serta bulan yang sama pada tahun lain.', $acts)
        . '<div style="margin-bottom:16px">' . $segBl . '</div>';
    if (!$m) return ['title' => 'Pasang Surut ' . $nama, 'html' => $h . empty_state('Belum dianalisis', 'Data bulan ini tidak mencukupi untuk analisis harmonik.', 'alert')];

    $h .= pasut_tiles($m, $ev['bulan'][$b] ?? []);
    if (!$m['lengkap']) {
        $h .= note_box('Bulan ini memuat <b>' . $m['jam'] . ' jam</b> dari ' . $m['jam_diharapkan'] . ' jam yang semestinya ada. '
            . 'Penyebabnya berkas BIG bermula pukul 00.00 UTC (= 07.00 WIB), sehingga tujuh jam pertama Januari ' . $ys[0]
            . ' WIB berada di berkas tahun sebelumnya yang belum tersedia. Analisis tetap dijalankan atas jam yang ada.', 'warn', 'alert') . '<div style="height:16px"></div>';
    }
    $h .= '<div class="panel" style="margin-bottom:18px"><div class="panel-h"><div><h2>Muka air per jam, ' . e($nama) . '</h2><p>Seluruh ' . nf($m['jam']) . ' jam dengan garis ambang rob</p></div>' . pasut_tipe_tag($m['tipe'], true) . '</div>'
        . pasut_grafik_bulan($y, $b, 290) . pasut_catatan_ambang(['waspada', 'siaga', 'bahaya']) . '</div>';

    /* pembanding: bulan sebelumnya, sesudahnya, dan bulan sama pada tahun lain */
    $prev = $b === 1 ? [$y - 1, 12] : [$y, $b - 1];
    $next = $b === 12 ? [$y + 1, 1] : [$y, $b + 1];
    $get = function (int $yy, int $bb) use ($ys) {
        if (!in_array($yy, $ys, true)) return [null, []];
        $aa = pasut_analisis($yy);
        return [$aa['bulan'][$bb] ?? null, pasut_kejadian_tahun($yy)['bulan'][$bb] ?? []];
    };
    [$pm, $pe] = $get($prev[0], $prev[1]);
    [$nm, $ne] = $get($next[0], $next[1]);
    $baris = [
        pasut_baris_banding($nama . ' (dibuka)', $m, $ev['bulan'][$b] ?? [], null),
        pasut_baris_banding(BLN_PANJANG[$prev[1] - 1] . ' ' . $prev[0] . ' (sebelumnya)', $pm, $pe, $m, url('pasut', ['th' => $prev[0], 'bl' => $prev[1]])),
        pasut_baris_banding(BLN_PANJANG[$next[1] - 1] . ' ' . $next[0] . ' (sesudahnya)', $nm, $ne, $m, url('pasut', ['th' => $next[0], 'bl' => $next[1]])),
    ];
    $h .= pasut_tabel_banding('Pembanding bulan sebelumnya dan sesudahnya', $baris);

    $baris2 = [];
    foreach ($ys as $yy) {
        [$mm, $ee] = $get($yy, $b);
        $baris2[] = pasut_baris_banding(BLN_PANJANG[$b - 1] . ' ' . $yy . ($yy === $y ? ' (dibuka)' : ''), $mm, $ee, $yy === $y ? null : $m, url('pasut', ['th' => $yy, 'bl' => $b]));
    }
    $h .= '<div style="height:18px"></div>' . pasut_tabel_banding(BLN_PANJANG[$b - 1] . ' pada setiap tahun ' . $ys[0] . '–' . $ys[count($ys) - 1], $baris2);

    /* tabel harian */
    $tr = '';
    foreach ($m['harian'] as $d) {
        $st = pasut_status_ambang($d['maks']);
        $tgl = sprintf('%04d-%02d-%02d', $y, $b, $d['h']);
        $tr .= '<tr><td><b>' . fmt_day($tgl) . '</b></td><td class="r num">' . pasut_fmt_m($d['maks']) . '</td><td class="r num">' . pasut_fmt_m($d['min'])
            . '</td><td class="r num">' . nf($d['maks'] - $d['min'], 3) . '</td><td class="r num">' . pasut_fmt_m($d['rata']) . '</td>'
            . '<td><span class="tag" style="--c:' . e($st['warna']) . '">' . e($st['nama']) . '</span></td></tr>';
    }
    $h .= '<div style="height:18px"></div><div class="panel" style="margin-bottom:18px"><div class="panel-h"><div><h2>Rincian harian ' . e($nama) . '</h2></div></div>'
        . '<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Tanggal</th><th class="r">Tertinggi (m)</th><th class="r">Terendah (m)</th><th class="r">Tunggang (m)</th><th class="r">Rata-rata (m)</th><th>Ambang tertinggi</th></tr></thead><tbody>' . $tr . '</tbody></table></div></div>';

    $h .= '<div class="panel"><div class="panel-h"><h2>Konstanta harmonik ' . e($nama) . '</h2></div>' . pasut_tabel_konstanta($m, array_merge(PASUT_SET_BULAN, ['K2', 'P1']))
        . note_box('Pada rekaman satu bulan, K2 tidak dapat dipisahkan dari S2 dan P1 tidak dapat dipisahkan dari K1 menurut kriteria Rayleigh '
            . '(butuh sekitar 183 hari). Keduanya karena itu <b>tidak dicocokkan</b>, melainkan diisi dengan rasio inferensi standar '
            . 'K2 = ' . nf(PASUT_INFER_K2, 5) . ' × S2 dan P1 = ' . nf(PASUT_INFER_P1, 5) . ' × K1. Untuk konstanta yang dipakai sebagai rujukan, '
            . 'gunakan hasil analisis tahunan atau gabungan lima tahun.', 'warn', 'info') . '</div>';
    return ['title' => 'Pasang Surut ' . $nama, 'html' => $h];
}

/* ---- panel metode dan QC ---- */
function pasut_panel_metode(array $ys): string {
    $g = pasut_gabungan();
    $qc = '';
    foreach ($ys as $y) {
        $a = pasut_analisis($y); if (!$a) continue;
        $q = $a['qc'];
        $ok = !empty($q['lengkap']);
        $qc .= '<tr><td><b>' . $y . '</b></td><td class="r num">' . nf($q['baris_terbaca'] ?? 0) . '</td><td class="r num">' . nf($q['jam_tersusun'] ?? 0) . ' / ' . nf($q['jam_diharapkan'] ?? 0) . '</td>'
            . '<td class="r num">' . nf($q['jam_tahun_wib'] ?? 0) . ' / ' . nf($q['jam_tahun_wib_diharapkan'] ?? 0) . '</td>'
            . '<td class="c">' . (int) ($q['baris_kembar'] ?? 0) . '</td><td class="c">' . (int) ($q['lompatan_diisi_kosong'] ?? 0) . '</td><td class="c">' . (int) ($q['nilai_tak_wajar'] ?? 0) . '</td>'
            . '<td>' . ($ok ? '<span class="tag" style="--c:var(--ok)">Lengkap</span>' : '<span class="tag" style="--c:var(--warn)">Periksa</span>') . '</td></tr>';
    }
    return '<div class="panel" style="margin-bottom:18px"><div class="panel-h"><div><h2>Sumber data, metode, dan pemeriksaan mutu</h2><p>Agar seluruh angka di halaman ini dapat ditelusuri</p></div></div>'
        . '<p style="margin-bottom:14px">' . pasut_sumber_note() . '</p>'
        . '<div class="tbl-wrap" style="margin-bottom:14px"><table class="tbl"><thead><tr><th>Berkas</th><th class="r">Baris terbaca</th><th class="r">Jam (berkas/UTC)</th><th class="r">Jam (tahun WIB)</th><th class="c">Kembar</th><th class="c">Lompatan</th><th class="c">Tak wajar</th><th>Status</th></tr></thead><tbody>' . $qc . '</tbody></table></div>'
        . '<dl class="kv"><dt>Analisis harmonik</dt><dd>Least-squares terhadap model z(t) = Z₀ + Σ A·cos(σt − g); matriks normal diselesaikan dengan eliminasi Gauss berpivot.</dd>'
        . '<dt>Komponen tahunan</dt><dd class="mono">' . implode(', ', PASUT_SET_TAHUN) . '</dd>'
        . '<dt>Komponen bulanan</dt><dd class="mono">' . implode(', ', PASUT_SET_BULAN) . '</dd>'
        . '<dt>Komponen yang tidak dipakai</dt><dd>Sa dikeluarkan karena pada rekaman satu tahun 8.760 × 0,0410686° = 359,8° &lt; 360°, sehingga tidak terpisah dari Z₀ menurut kriteria Rayleigh dan membuat matriks normal menjadi ill-conditioned. M4, MS4, M6, Mf, Mm, dan Ssa juga tidak dipakai karena amplitudonya di bawah 0,00001 m pada data ini: produk prediksi BIG hanya memuat komponen astronomi utama, tanpa komponen perairan dangkal maupun komponen musiman.</dd>'
        . '<dt>Klasifikasi tipe</dt><dd>Bilangan Formzahl F = (A<sub>K1</sub> + A<sub>O1</sub>) / (A<sub>M2</sub> + A<sub>S2</sub>).</dd>'
        . '<dt>Ambang rob</dt><dd>Persentil empiris muka air seluruh periode: Normal P90, Waspada P98, Siaga P99,5, Bahaya P99,9. Bukan angka pilihan.</dd>'
        . '<dt>Pembagian kalender</dt><dd>Tahun dan bulan dipotong menurut <b>WIB</b>, sesuai waktu yang ditampilkan aplikasi. Jumlah jam tiap blok dilaporkan apa adanya pada tabel di atas.</dd>'
        . '<dt>Ketepatan pencocokan</dt><dd>R² gabungan ' . nf(($g['r2'] ?? 0) * 100, 2) . '%, sisa RMS ' . nf($g['rms'] ?? 0, 4) . ' m.</dd></dl>'
        . note_box('<b>Batas pemakaian.</b> Angka di halaman ini adalah pasang surut <b>astronomis</b>. Banjir rob di lapangan juga dipengaruhi '
            . 'tinggi gelombang, tekanan udara, angin yang mendorong air ke pantai, serta anomali muka laut musiman, yang semuanya <b>tidak termuat</b> '
            . 'dalam prediksi BIG. Muka air tertinggi dalam lima tahun hanya ' . pasut_fmt_m($g['stat']['z_maks'] ?? null) . ' m di atas MSL, '
            . 'sehingga rob yang menggenangi permukiman umumnya memerlukan tambahan dari faktor meteorologi. '
            . 'Gunakan halaman ini bersama data gelombang dan angin, bukan sendirian.', 'warn', 'alert') . '</div>';
}
function pasut_panel_qc(array $a): string {
    $q = $a['qc'];
    return '<div class="panel"><div class="panel-h"><h2>Pemeriksaan mutu data ' . $a['tahun'] . '</h2></div>'
        . '<dl class="kv"><dt>Baris terbaca dari berkas BIG</dt><dd class="num">' . nf($q['baris_terbaca'] ?? 0) . '</dd>'
        . '<dt>Jam tersusun (berkas, UTC)</dt><dd class="num">' . nf($q['jam_tersusun'] ?? 0) . ' dari ' . nf($q['jam_diharapkan'] ?? 0) . '</dd>'
        . '<dt>Jam dipakai analisis (tahun WIB)</dt><dd class="num">' . nf($q['jam_tahun_wib'] ?? 0) . ' dari ' . nf($q['jam_tahun_wib_diharapkan'] ?? 0) . '</dd>'
        . '<dt>Baris kembar</dt><dd class="num">' . (int) ($q['baris_kembar'] ?? 0) . '</dd>'
        . '<dt>Lompatan waktu diisi kosong</dt><dd class="num">' . (int) ($q['lompatan_diisi_kosong'] ?? 0) . '</dd>'
        . '<dt>Nilai di luar batas wajar (|z| &gt; 10 m)</dt><dd class="num">' . (int) ($q['nilai_tak_wajar'] ?? 0) . '</dd>'
        . '<dt>Rentang berkas</dt><dd class="mono">' . e($q['mulai_utc'] ?? '-') . ' s.d. ' . e($q['akhir_utc'] ?? '-') . ' UTC</dd>'
        . '<dt>Sisa pencocokan</dt><dd class="num">RMS ' . nf($a['rms'], 4) . ' m, R² ' . nf($a['r2'] * 100, 2) . '%</dd></dl>'
        . '<p class="faint" style="font-size:12.5px;margin-top:10px">' . pasut_sumber_note() . '</p></div>';
}

/* ---- unduhan CSV ---- */
function pasut_unduh(int $th, int $bl, array $ys): array {
    if ($th && $bl) {
        $t0 = (int) mktime(0, 0, 0, $bl, 1, $th);
        $t1 = (int) mktime(23, 0, 0, $bl, (int) date('t', $t0), $th);
        $rows = [['Waktu WIB', 'Waktu UTC', 'Muka air z (m dari MSL)', 'Ambang terlampaui']];
        foreach (pasut_deret($t0, $t1) as [$t, $v]) {
            $st = pasut_status_ambang($v);
            $rows[] = [date('Y-m-d H:i', $t), gmdate('Y-m-d H:i', $t), number_format($v, 3, '.', ''), $st['nama']];
        }
        send_csv(sprintf('pasut-big-%04d-%02d.csv', $th, $bl), $rows);
    }
    if ($th) {
        $a = pasut_analisis($th); $ev = pasut_kejadian_tahun($th);
        $rows = [['Bulan', 'Jam data', 'Formzahl', 'Tipe pasut', 'Tertinggi (m)', 'Terendah (m)', 'Tunggang (m)', 'MHWL (m)', 'MLWL (m)', 'HHWL formula (m)', 'LLWL formula (m)', 'Jam >= Waspada', 'Jam >= Siaga', 'Jam >= Bahaya']];
        for ($b = 1; $b <= 12; $b++) {
            $m = $a['bulan'][$b] ?? null; if (!$m) continue; $e = $ev['bulan'][$b] ?? [];
            $rows[] = [BLN_PANJANG[$b - 1], $m['jam'], round($m['F'], 6), $m['tipe']['nama'],
                round($m['stat']['z_maks'], 3), round($m['stat']['z_min'], 3), round($m['stat']['tunggang_maks'], 3),
                $m['stat']['MHWL'] === null ? '' : round($m['stat']['MHWL'], 4), $m['stat']['MLWL'] === null ? '' : round($m['stat']['MLWL'], 4),
                round($m['stat']['HHWL_formula'], 4), round($m['stat']['LLWL_formula'], 4),
                (int) ($e['waspada']['jam'] ?? 0), (int) ($e['siaga']['jam'] ?? 0), (int) ($e['bahaya']['jam'] ?? 0)];
        }
        send_csv("pasut-big-ringkasan-$th.csv", $rows);
    }
    $rows = [['Tahun', 'Bulan', 'Formzahl', 'Tipe pasut', 'Tertinggi (m)', 'Terendah (m)', 'Tunggang (m)', 'Jam >= Waspada', 'Jam >= Siaga', 'Jam >= Bahaya']];
    foreach ($ys as $y) {
        $a = pasut_analisis($y); if (!$a) continue; $ev = pasut_kejadian_tahun($y);
        for ($b = 1; $b <= 12; $b++) {
            $m = $a['bulan'][$b] ?? null; if (!$m) continue; $e = $ev['bulan'][$b] ?? [];
            $rows[] = [$y, BLN_PANJANG[$b - 1], round($m['F'], 6), $m['tipe']['nama'],
                round($m['stat']['z_maks'], 3), round($m['stat']['z_min'], 3), round($m['stat']['tunggang_maks'], 3),
                (int) ($e['waspada']['jam'] ?? 0), (int) ($e['siaga']['jam'] ?? 0), (int) ($e['bahaya']['jam'] ?? 0)];
        }
    }
    send_csv('pasut-big-matriks-tahun-bulan.csv', $rows);
}


/* ---- tampilan warga: kapan air laut tinggi, tanpa istilah teknis ---- */
function pasut_view_warga(): array {
    $hari0 = (int) strtotime('today'); $hari = [];
    for ($d = 0; $d < 90; $d++) {
        $t0 = $hari0 + $d * 86400; $best = null;
        foreach (pasut_deret($t0, $t0 + 23 * 3600) as [$t, $v]) if ($best === null || $v > $best[1]) $best = [$t, $v];
        if ($best) $hari[] = ['t0' => $t0, 'jam' => $best[0], 'z' => $best[1]];
    }
    if (!$hari) return ['title' => 'Jadwal Pasang Surut', 'html' => page_head('Jadwal Pasang Surut', 'Kapan air laut tinggi.') . note_box('Data pasang surut untuk tanggal ini belum tersedia.', 'warn', 'alert')];
    $row = fn($x) => '<tr><td><b>' . e(fmt_day(date('Y-m-d H:i:s', $x['t0']))) . '</b></td><td>Sekitar pukul ' . date('H.i', $x['jam']) . '</td><td>' . warga_tag_pasut($x['z']) . '</td></tr>';
    $tbl = fn($list) => '<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Hari</th><th>Air paling tinggi</th><th>Keadaan</th></tr></thead><tbody>' . implode('', array_map($row, $list)) . '</tbody></table></div>';
    $top = $hari; usort($top, fn($a, $b) => $b['z'] <=> $a['z']); $top = array_slice($top, 0, 5); usort($top, fn($a, $b) => $a['t0'] <=> $b['t0']);
    $h = page_head('Jadwal Pasang Surut', 'Kapan air laut tinggi, supaya Anda bisa bersiap lebih awal.')
        . '<div class="grid g2" style="align-items:start"><div class="panel"><div class="panel-h"><div><h2>7 hari ke depan</h2><p>Waktu air laut paling tinggi tiap hari.</p></div></div>' . $tbl(array_slice($hari, 0, 7)) . '</div>'
        . '<div class="panel"><div class="panel-h"><div><h2>Hari air tinggi dalam 3 bulan ke depan</h2><p>Lima hari yang paling perlu diwaspadai.</p></div></div>' . $tbl($top) . '</div></div>'
        . '<div style="height:18px"></div>' . note_box('<b>Cara membaca:</b> "Biasa" berarti air laut dalam batas yang lazim. "Agak tinggi", "Tinggi", dan "Sangat tinggi" berarti air laut lebih tinggi dari hari-hari biasa sehingga genangan di tempat rendah lebih mungkin terjadi.', '', 'info')
        . '<div style="height:12px"></div>' . note_box('Jadwal ini adalah prakiraan naik-turun air laut karena pengaruh bulan dan matahari, dari Badan Informasi Geospasial (BIG). Rob juga bisa terjadi karena angin kencang, gelombang tinggi, atau cuaca buruk, jadi tetap ikuti peringatan dini dan arahan perangkat desa.', 'warn', 'alert');
    return ['title' => 'Jadwal Pasang Surut', 'html' => $h];
}
