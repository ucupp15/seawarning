<?php
declare(strict_types=1);
/*
 * Evakuasi dan titik aman Pulo Panjang.
 *
 * ASAL DATA, DINYATAKAN TERBUKA:
 *  - Koordinat titik aman  : hitungan geometri atas garis pantai digitasi pengguna
 *                            (titik interior berjarak maksimum ke garis pantai).
 *                            Lihat tools/geometri_evakuasi.py dan data/evakuasi.json.
 *  - Elevasi               : BELUM ADA pada tingkat titik. Yang tersedia hanya nilai
 *                            DEMNAS tingkat kampung di tabel data_parameter. Kolom
 *                            elevasi_m pada titik_evakuasi sengaja DIBIARKAN KOSONG
 *                            sampai diekstraksi dari DEMNAS atau diukur di lapangan.
 *  - Jumlah KK             : pernyataan Kepala Desa (SRSentra, 22 Mei 2026), dibagi
 *                            rata ke tujuh kampung. Estimasi, bukan data kantor desa.
 *  - Jiwa, waktu tempuh    : ASUMSI perencanaan, nilainya dicantumkan di antarmuka.
 *
 * SELURUH TITIK BERSTATUS "USULAN" dan belum ditetapkan BPBD Kabupaten Serang
 * maupun Pemerintah Desa Pulo Panjang.
 */

function evak_file(): string { return APP_ROOT . '/data/evakuasi.json'; }
function evak_meta(): array {
    static $d = null;
    if ($d === null) {
        $f = evak_file();
        $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        $d = is_array($j) ? $j : [];
    }
    return $d;
}
/* Data penduduk per kampung dari Sensus Registrasi Penduduk Pemerintah Kabupaten Serang (data/penduduk.json). */
function penduduk_data(): array { static $d = null; if ($d === null) { $f = APP_ROOT . '/data/penduduk.json'; $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null; $d = is_array($j) ? $j : ['kampung' => []]; } return $d; }
function penduduk_kampung(string $nama): ?array { foreach (penduduk_data()['kampung'] ?? [] as $k) if (strcasecmp($k['kampung'], $nama) === 0) return $k; return null; }
/* Total desa (KK dari Rekap DTSEN, jiwa dari data penduduk desa) dan kelompok usia (papan data desa). Keduanya tingkat desa, tidak dirinci per kampung. */
function penduduk_desa(): ?array { $d = penduduk_data()['desa'] ?? null; return is_array($d) && !empty($d['kk']) ? $d : null; }
function penduduk_usia(): ?array { $u = penduduk_data()['kelompok_usia'] ?? null; return is_array($u) && !empty($u['kelompok']) ? $u : null; }
function penduduk_usia_html(bool $ringkas = false): string {
    $u = penduduk_usia(); if (!$u) return '';
    $tl = 0; $tp = 0; foreach ($u['kelompok'] as $k) { $tl += (int) $k['laki']; $tp += (int) $k['perempuan']; }
    $mx = max(1, ...array_map(fn($k) => max((int) $k['laki'], (int) $k['perempuan']), $u['kelompok']));
    $bar = $ringkas ? fn(int $v, string $c) => '<span class="num">' . nf($v) . '</span>' : fn(int $v, string $c) => '<div style="display:flex;align-items:center;gap:8px"><div style="flex:1;min-width:40px;height:8px;border-radius:4px;background:var(--surface-2)"><div style="width:' . round($v / $mx * 100, 1) . '%;height:100%;border-radius:4px;background:' . $c . '"></div></div><span class="num" style="min-width:3.2em;text-align:right">' . nf($v) . '</span></div>';
    $h = '<h3 style="font-size:16px;margin:16px 0 8px">Penduduk menurut kelompok usia</h3><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Usia</th><th>' . ($ringkas ? 'L' : 'Laki-laki') . '</th><th>' . ($ringkas ? 'P' : 'Perempuan') . '</th><th class="r">Jumlah</th></tr></thead><tbody>';
    foreach ($u['kelompok'] as $k) { $l = (int) $k['laki']; $pr = (int) $k['perempuan']; $h .= '<tr><td><b>' . e($k['usia']) . '</b></td><td>' . $bar($l, '#1F7FB8') . '</td><td>' . $bar($pr, '#D0628A') . '</td><td class="r num"><b>' . nf($l + $pr) . '</b></td></tr>'; }
    $h .= '<tr><td><b>Jumlah</b></td><td class="num"><b>' . nf($tl) . '</b></td><td class="num"><b>' . nf($tp) . '</b></td><td class="r num"><b>' . nf($tl + $tp) . '</b></td></tr></tbody></table></div>';
    return $h . '<p class="muted" style="font-size:13px;margin-top:8px">Sumber: ' . e($u['sumber']) . '. ' . e($u['catatan'] ?? '') . '</p>';
}
function penduduk_sumber(): string { return (string) (penduduk_data()['sumber'] ?? 'data kantor desa'); }
function evak_asumsi(): array { return evak_meta()['asumsi'] ?? []; }
function evak_pulau(): array { return evak_meta()['pulau'] ?? []; }
function evak_belum(): array { return evak_meta()['belum_tersedia'] ?? []; }
function evak_dasar(): array { return evak_meta()['dasar_data'] ?? []; }

/* ---------------------------------------------------------------- data basis ---------- */
function evak_titik(): array { return tbl('titik_evakuasi'); }
function evak_jalur(): array { return tbl('jalur_evakuasi'); }
function evak_titik_of(int $idW): ?array { foreach (evak_titik() as $t) if ($t['id_wilayah'] === $idW) return $t; return null; }
function evak_jalur_of(int $idW): ?array { foreach (evak_jalur() as $j) if ($j['id_wilayah'] === $idW) return $j; return null; }
function evak_ada(): bool { return evak_titik() !== []; }

/* Jarak lingkaran besar (haversine), meter. */
function evak_jarak(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R = 6371008.8;
    $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
    $dp = $p2 - $p1; $dl = deg2rad($lon2 - $lon1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return 2 * $R * asin(min(1.0, sqrt($a)));
}

/* Kebutuhan penampungan satu kampung, dari jumlah KK dan asumsi yang dicantumkan. */
function evak_kebutuhan(int $idW): array {
    $w = row('wilayah', $idW);
    $as = evak_asumsi();
    $kk = (int) ($w['jumlah_kk'] ?? 0);
    $jpk = (float) ($as['jiwa_per_kk'] ?? 4);
    $m2 = (float) ($as['luas_per_jiwa_m2'] ?? 3.5);
    $sensus = penduduk_kampung((string) ($w['nama_wilayah'] ?? ''));
    if ($sensus) { $kk = (int) $sensus['kk']; $jiwa = (int) $sensus['jiwa']; $jpk = $kk ? $jiwa / $kk : $jpk; }   // data sensus mengalahkan asumsi
    else $jiwa = (int) round($kk * $jpk);
    return ['kk' => $kk, 'jiwa_per_kk' => $jpk, 'jiwa' => $jiwa, 'luas_per_jiwa' => $m2, 'luas_m2' => $jiwa * $m2, 'sensus' => (bool) $sensus];
}
function evak_kebutuhan_total(): array {
    $kk = 0; $jiwa = 0; $luas = 0.0;
    foreach (tbl('wilayah') as $w) { $k = evak_kebutuhan($w['id_wilayah']); $kk += $k['kk']; $jiwa += $k['jiwa']; $luas += $k['luas_m2']; }
    if ($d = penduduk_desa()) {   // total desa memakai Rekap DTSEN; rincian per kampung tetap dari sensus
        $m2 = (float) (evak_asumsi()['luas_per_jiwa_m2'] ?? 3.5);
        return ['kk' => (int) $d['kk'], 'jiwa' => (int) $d['jiwa'], 'luas_m2' => (int) $d['jiwa'] * $m2, 'sumber' => (string) $d['sumber'] . (isset($d['jiwa_sumber']) ? ' dan ' . mb_strtolower((string) $d['jiwa_sumber'], 'UTF-8') : ''), 'kk_kampung' => $kk, 'jiwa_kampung' => $jiwa];
    }
    return ['kk' => $kk, 'jiwa' => $jiwa, 'luas_m2' => $luas, 'sumber' => penduduk_sumber()];
}

/*
 * Urutan prioritas evakuasi tiap kampung.
 * Dasar yang dapat dipertanggungjawabkan, seluruhnya dari data yang ada:
 *   1. skor kerawanan hasil analisis (hasil_kerawanan)
 *   2. elevasi DEMNAS kampung (makin rendah makin diprioritaskan)
 *   3. jarak permukiman ke garis pantai (makin dekat makin diprioritaskan)
 *   4. jumlah KK terdampak
 *   5. waktu tempuh ke titik aman (makin lama makin diprioritaskan)
 * Tidak ada pembobotan baru yang dikarang: urutan memakai skor kerawanan sebagai
 * kunci utama, lalu elevasi, lalu jumlah KK, sebagai pemecah seri.
 */
function evak_prioritas(): array {
    $pElev = param_by_name('/elevasi|dem/i');
    $pJarak = param_by_name('/jarak.*pantai/i');
    $out = [];
    foreach (tbl('wilayah') as $w) {
        $id = $w['id_wilayah'];
        $inf = info_wilayah($id);
        $lat = latest_data($id);
        $t = evak_titik_of($id); $j = evak_jalur_of($id);
        $out[] = [
            'w' => $w,
            'inf' => $inf,
            'skor' => $inf['skor'] ?? null,
            'elev' => $pElev ? ($lat[$pElev['id_parameter']]['nilai'] ?? null) : null,
            'jarak_param' => $pJarak ? ($lat[$pJarak['id_parameter']]['nilai'] ?? null) : null,
            'titik' => $t,
            'jalur' => $j,
            'kebutuhan' => evak_kebutuhan($id),
        ];
    }
    usort($out, function ($a, $b) {
        $s = ($b['skor'] ?? -1) <=> ($a['skor'] ?? -1);
        if ($s) return $s;
        $e = ($a['elev'] ?? 1e9) <=> ($b['elev'] ?? 1e9);   // elevasi rendah lebih dulu
        if ($e) return $e;
        return ($b['kebutuhan']['kk'] ?? 0) <=> ($a['kebutuhan']['kk'] ?? 0);
    });
    foreach ($out as $i => &$r) $r['urutan'] = $i + 1;
    return $out;
}

/* Titik aman paling dalam di pulau (dipakai sebagai usulan titik kumpul utama). */
function evak_titik_utama(): ?array {
    $best = null;
    foreach (evak_titik() as $t) if ($best === null || ($t['jarak_pantai_m'] ?? 0) > ($best['jarak_pantai_m'] ?? 0)) $best = $t;
    return $best;
}

/* ---------------------------------------------------------------- pembibitan data ---------- */
/*
 * Mengisi tabel titik_evakuasi dan jalur_evakuasi dari data/evakuasi.json.
 * $ganti = true  : hapus isi lama lebih dulu (dipakai tombol "Muat ulang dari hasil analisis").
 * Mengembalikan [jumlah titik, jumlah jalur].
 */
function evak_seed(bool $ganti = false): array {
    $m = evak_meta();
    $titik = $m['titik_evakuasi'] ?? [];
    $jalur = $m['jalur_evakuasi'] ?? [];
    if (!$titik) return [0, 0];
    $idSumber = ensure_sumber('Analisis geometri garis pantai digitasi (turunan)', 'Turunan / analisis spasial', '');
    if ($ganti) {
        db()->exec('DELETE FROM `jalur_evakuasi`');
        db()->exec('DELETE FROM `titik_evakuasi`');
        tbl_flush('jalur_evakuasi'); tbl_flush('titik_evakuasi');
    }
    $peta = [];   // id pada berkas -> id pada basis data
    $nt = 0;
    foreach ($titik as $t) {
        $idW = (int) ($t['id_wilayah'] ?? 0);
        if (!row('wilayah', $idW)) continue;
        $peta[(int) $t['id_titik']] = ins('titik_evakuasi', [
            'id_wilayah' => $idW,
            'id_sumber' => $idSumber,
            'nama_titik' => (string) $t['nama_titik'],
            'jenis' => (string) $t['jenis'],
            'lintang' => (float) $t['lintang'],
            'bujur' => (float) $t['bujur'],
            'elevasi_m' => $t['elevasi_titik_m'] === null ? null : (float) $t['elevasi_titik_m'],
            'jarak_pantai_m' => (float) $t['jarak_pantai_m'],
            'kapasitas_orang' => $t['kapasitas_orang'] === null ? null : (int) $t['kapasitas_orang'],
            'status_validasi' => (string) ($t['status_validasi'] ?? 'usulan'),
            'keterangan' => (string) ($t['keterangan'] ?? ''),
        ]);
        $nt++;
    }
    $nj = 0;
    foreach ($jalur as $j) {
        $idT = $peta[(int) ($j['id_titik'] ?? 0)] ?? null;
        $idW = (int) ($j['id_wilayah'] ?? 0);
        if (!$idT || !row('wilayah', $idW)) continue;
        ins('jalur_evakuasi', [
            'id_wilayah' => $idW,
            'id_titik' => $idT,
            'panjang_lurus_m' => (float) $j['panjang_lurus_m'],
            'panjang_jalur_m' => (float) $j['panjang_jalur_estimasi_m'],
            'waktu_tempuh_menit' => (float) $j['waktu_tempuh_menit'],
            'keterangan' => (string) ($j['keterangan'] ?? ''),
        ]);
        $nj++;
    }
    return [$nt, $nj];
}

/* ---------------------------------------------------------------- tampilan ---------- */
function evak_status_tag(string $st, bool $lg = false): string {
    $m = ['usulan' => ['Usulan', 'var(--warn)', 'alert'], 'survei' => ['Sudah disurvei', '#2E9E9B', 'checkc'], 'ditetapkan' => ['Ditetapkan resmi', 'var(--ok)', 'checkc']];
    [$l, $c, $i] = $m[$st] ?? $m['usulan'];
    return '<span class="tag ' . ($lg ? 'lg' : '') . '" style="--c:' . $c . '">' . ic($i, $lg ? 20 : 16) . e($l) . '</span>';
}
function evak_elev_cell(?float $titik, ?float $kampung): string {
    if ($titik !== null) return '<b class="num">' . nf($titik, 2) . ' m</b>';
    if ($kampung !== null) return '<span class="num faint">' . nf($kampung, 2) . ' m</span><span class="sub" style="color:var(--warn)">nilai kampung, bukan titik</span>';
    return '<span class="faint">belum ada</span>';
}
/* Catatan status data evakuasi, selalu ditampilkan di atas halaman. */
function evak_catatan_status(): string {
    $b = evak_belum();
    return note_box('<b>Status data: USULAN.</b> Titik dan jalur di halaman ini adalah <b>hasil hitungan geometri</b> atas garis pantai digitasi, '
        . 'bukan penetapan resmi BPBD Kabupaten Serang atau Pemerintah Desa Pulo Panjang. Yang <b>belum tersedia</b> dan wajib dilengkapi sebelum dipakai: '
        . '<ul style="margin:8px 0 0 18px">' . implode('', array_map(fn($x) => '<li>' . e($x) . '</li>', $b)) . '</ul>', 'warn', 'alert');
}

/* ===================================================================================== peta evakuasi ====
 * Zonasi keselamatan 7 peringkat mengikuti legenda peta rujukan penelitian:
 *   1 Zona aman (hijau) | 2-3 Aman (hijau terang) | 4-5 Siaga/sedang (kuning-oranye) | 6 Rawan (merah muda) | 7 Sangat rawan (merah tua)
 * Peringkat 2-7 dihitung dari urutan prioritas hasil analisis (skor kerawanan, elevasi, jumlah KK), paling aman lebih dulu.
 * EVAK_ZONA_UTAMA: kampung yang dijadikan Peringkat 1 (zona aman utama) mengikuti peta rujukan penelitian.
 * Isi dengan '' agar peringkat 1 murni mengikuti hasil analisis.
 */
const EVAK_ZONA_UTAMA = 'Penengahan';
function evak_kelas_zona(int $rank, int $n): array {
    $r = $n > 1 ? (int) round(1 + ($rank - 1) * 6 / ($n - 1)) : 1;
    $K = [1 => ['aman', 'Zona aman', '#1E9E4A'], 2 => ['cukup', 'Aman', '#7BD43B'], 3 => ['cukup', 'Aman', '#7BD43B'], 4 => ['siaga', 'Siaga / sedang', '#F2A900'],
        5 => ['siaga', 'Siaga / sedang', '#F2A900'], 6 => ['rawan', 'Rawan (zona merah)', '#D13B3B'], 7 => ['rawan', 'Rawan (zona merah)', '#D13B3B']];
    return ['kunci' => $K[$r][0], 'label' => $K[$r][1], 'warna' => $K[$r][2]];
}
/* Zona hasil SURVEI LAPANGAN yang diverifikasi RT setempat. Menimpa kelas zona hasil hitungan untuk kampung yang disebut.
 * Kunci kelas: aman | cukup | siaga | rawan. Kosongkan array ini untuk kembali ke zonasi murni dari data. */
const EVAK_ZONA_SURVEI = ['Peres' => 'rawan', 'Pasir Putih' => 'rawan', 'Sukadiri' => 'rawan', 'Sukarela' => 'siaga'];
const EVAK_KELAS = ['aman' => ['Zona aman', '#1E9E4A', 1], 'cukup' => ['Aman', '#7BD43B', 2], 'siaga' => ['Siaga / sedang', '#F2A900', 3], 'rawan' => ['Rawan (zona merah)', '#D13B3B', 4]];
function evak_peringkat(): array {
    $list = array_reverse(evak_prioritas()); $n = count($list); $manual = false;
    if (EVAK_ZONA_UTAMA !== '') foreach ($list as $i => $r) if (mb_stripos($r['w']['nama_wilayah'], EVAK_ZONA_UTAMA) !== false) {
        if ($i !== 0) { array_splice($list, $i, 1); array_unshift($list, $r); $manual = true; } break;
    }
    foreach ($list as $i => &$r) { $r['dasar'] = $i + 1; $r['zona'] = evak_kelas_zona($i + 1, $n); $r['manual'] = $manual && $i === 0; $r['survei'] = false;
        foreach (EVAK_ZONA_SURVEI as $nama => $kunci) if (mb_stripos($r['w']['nama_wilayah'], $nama) !== false && isset(EVAK_KELAS[$kunci])) {
            [$lb, $wr] = EVAK_KELAS[$kunci]; $r['zona'] = ['kunci' => $kunci, 'label' => $lb, 'warna' => $wr]; $r['survei'] = true; } } unset($r);
    usort($list, fn($a, $b) => [EVAK_KELAS[$a['zona']['kunci']][2], $a['dasar']] <=> [EVAK_KELAS[$b['zona']['kunci']][2], $b['dasar']]);
    foreach ($list as $i => &$r) $r['rank'] = $i + 1; unset($r);
    return $list;
}
/* Titik tengah poligon kampung (centroid cincin terluas) dalam [lintang, bujur], sama dengan letak label di Peta Zona Kerawanan. */
function evak_pusat_poligon(array $w): ?array {
    $p = geo_rings(geo_of($w)); if (!$p) return null; $best = null;
    foreach ($p as $poly) { $a = abs(poly_area($poly[0])); if (!$best || $a > $best[0]) $best = [$a, $poly[0]]; }
    [$x, $y] = poly_centroid($best[1]);
    return ($x >= 90 && $x <= 145 && $y >= -12 && $y <= 8) ? [(float) $y, (float) $x] : null;
}
function evak_peta_berkas(): array { $f = APP_ROOT . '/data/peta_evakuasi.json'; $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null; return is_array($j) ? $j : []; }
function evak_peta_html(bool $warga): string {
    $pf = evak_peta_berkas(); $rk = evak_peringkat(); $n = count($rk);
    if (!$pf || !$rk) return note_box('Data peta evakuasi (<span class="mono">data/peta_evakuasi.json</span>) belum ada. Jalankan <span class="mono">tools/peta_evakuasi.py</span> atau salin berkasnya dari paket pembaruan.', 'warn', 'alert');
    $kamp = []; $leg = []; $items = ''; $utama = null;
    foreach ($rk as $r) {
        $w = $r['w']; $id = $w['id_wilayah']; $z = $r['zona']; $tk = $r['inf']['tingkat'] ?? null; if ($tk) $z['warna'] = (string) $tk['warna'];   // warna zona = warna tingkat kerawanan, sama dengan Peta Zona Kerawanan
        $t = $r['titik']; $j = $r['jalur']; $tg = $r['inf']['tingkat']['nama_tingkat'] ?? null; $mnt = $j ? max(1, (int) round((float) $j['waktu_tempuh_menit'])) : null;
        $baris = [['Zona keselamatan', 'Peringkat ' . $r['rank'] . ' dari ' . $n . ': ' . $z['label']]];
        if ($r['survei'] && $warga) $baris[] = ['Dasar zona', 'Survei lapangan, diverifikasi RT'];
        if ($mnt && $r['rank'] > 1) $baris[] = ['Ke titik kumpul kampung', 'sekitar ' . $mnt . ' menit berjalan kaki'];
        if ($r['rank'] === 1) $baris[] = ['Peran', 'Zona aman utama: tujuan akhir jalur evakuasi dari pesisir'];
        if (!$warga) {
            if ($tg) $baris[] = ['Tingkat kerawanan (analisis)', $tg . ($r['skor'] !== null ? ', skor ' . nf($r['skor'], 2) : '')];
            if ($r['elev'] !== null) $baris[] = ['Elevasi DEMNAS (nilai kampung)', nfa($r['elev']) . ' m'];
            if ($r['kebutuhan']['kk']) $baris[] = ['Penduduk (sensus)', nf($r['kebutuhan']['kk']) . ' KK, sekitar ' . nf($r['kebutuhan']['jiwa']) . ' jiwa'];
            if ($t) $baris[] = ['Jarak titik kumpul ke pantai', nf($t['jarak_pantai_m'], 0) . ' m (hitungan geometri)'];
            if ($r['manual']) $baris[] = ['Catatan', 'Peringkat 1 mengikuti peta rujukan penelitian, bukan urutan hasil analisis'];
            if ($r['survei']) $baris[] = ['Penetapan zona', 'Hasil survei lapangan, diverifikasi RT setempat (menimpa zona hasil hitungan)'];
        }
        $kamp[] = ['id' => $id, 'nama' => $w['nama_wilayah'], 'pendek' => short_name($w['nama_wilayah']), 'rank' => $r['rank'], 'kunci' => $z['kunci'], 'label' => $z['label'], 'warna' => $z['warna'],
            'geo' => geo_real($w), 'pusat' => evak_pusat_poligon($w) ?? wil_titik($w), 'lt' => $tk ? rank_of((int) $tk['id_tingkat']) === 1 : null, 'tk' => $t ? [(float) $t['lintang'], (float) $t['bujur']] : null, 'baris' => $baris];
        if ($r['rank'] === 1 && $t) $utama = ['nama' => $w['nama_wilayah'], 'latlng' => [(float) $t['lintang'], (float) $t['bujur']]];
        $lk = $tk ? 't' . rank_of((int) $tk['id_tingkat']) : 'z' . $z['kunci']; $leg[$lk] ??= ['warna' => $z['warna'], 'label' => $tk ? 'Kerawanan ' . mb_strtolower((string) $tk['nama_tingkat']) : $z['label'], 'rank' => [], 'kampung' => []]; $leg[$lk]['rank'][] = $r['rank']; $leg[$lk]['kampung'][] = short_name($w['nama_wilayah']);
        $items .= '<button type="button" class="evk-r" data-id="' . $id . '" aria-pressed="false" style="--c:' . e($z['warna']) . '"><span class="evk-n">' . $r['rank'] . '</span><span class="evk-t"><b>' . e($w['nama_wilayah']) . '</b><small>' . ($tk ? 'Kerawanan ' . e(mb_strtolower((string) $tk['nama_tingkat'])) : e($z['label'])) . ($mnt && $r['rank'] > 1 ? ', ' . $mnt . ' menit ke titik kumpul' : ($r['rank'] === 1 ? ', zona aman utama' : '')) . '</small></span></button>';
    }
    ksort($leg); $legend = []; foreach ($leg as $g) { sort($g['rank']); $g['rank'] = count($g['rank']) > 1 ? 'Peringkat ' . $g['rank'][0] . '-' . end($g['rank']) : 'Peringkat ' . $g['rank'][0]; $legend[] = $g; }
    $data = ['attr' => 'Tiles &copy; Esri &mdash; Source: Esri, Maxar, Earthstar Geographics, and the GIS User Community',
        'sat' => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', 'lab' => 'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}',
        'topo' => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/{z}/{y}/{x}', 'street' => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}',
        'kampung' => $kamp, 'legenda' => $legend, 'utama' => $utama, 'sabuk' => $pf['sabuk_pesisir'] ?? null, 'tempat' => $pf['tempat'] ?? [], 'pantai' => $pf['garis_pantai'] ?? [], 'warga' => $warga];
    return '<div class="evk"><div class="evk-left"><div class="evk-wrap"><div id="evk-map" class="leaf evk-leaf" role="application" aria-label="Peta zonasi dan jalur evakuasi rob Pulo Panjang"></div><div class="sat-msg" id="evk-msg"></div>'
        . '<noscript>' . note_box('Peta evakuasi memerlukan JavaScript. Daftar peringkat kampung tersedia di samping.', 'warn', 'alert') . '</noscript></div></div>'
        . '<aside class="evk-side" aria-label="Peringkat keselamatan kampung"><div class="evk-info" id="evk-info" hidden aria-live="polite"></div><div class="lbl" style="margin-bottom:10px">Peringkat keselamatan (1 = paling aman)</div><div class="evk-list">' . $items . '</div></aside>'
        . '<script type="application/json" id="evk-data">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script></div>' . evak_peta_catatan($warga);
}
function evak_peta_catatan(bool $warga): string {
    if ($warga) return '<p class="faint" style="font-size:13px;margin-top:12px">Peta ini adalah usulan penelitian dan belum ditetapkan resmi. Garis jalur menunjukkan arah, bukan jalan yang sudah disurvei. Ikuti arahan perangkat desa.</p>';
    $zu = EVAK_ZONA_UTAMA;
    return '<div class="panel evk-cara" style="margin-top:18px"><div class="panel-h"><h2>Cara membaca peta</h2></div><ul class="list" style="padding-left:20px;list-style:disc">'
        . '<li><b>Warna kampung</b> = tingkat kerawanan hasil analisis, sama dengan Peta Zona Kerawanan. Angka 1 sampai 7 = peringkat keselamatan evakuasi. ' . ($zu !== '' ? 'Peringkat 1 (zona aman utama) mengikuti peta rujukan penelitian, yaitu Kampung ' . e($zu) . '. ' : '') . (EVAK_ZONA_SURVEI ? '<b>Zona ' . e(implode(', ', array_keys(EVAK_ZONA_SURVEI))) . ' ditetapkan dari survei lapangan yang diverifikasi RT setempat</b> dan menimpa hasil hitungan. ' : '') . 'Kampung lain disusun dari skor kerawanan, elevasi, dan jumlah KK hasil analisis.</li>'
        . '<li><b>Sabuk merah pesisir</b> = zona sampai 100 m dari garis pantai digitasi. Ini zona jarak, bukan hasil model genangan.</li>'
        . '<li><b>Garis jingga bergerak</b> = arah evakuasi dari titik pesisir ke zona aman utama. Ini arah indikatif; jalur jalan nyata belum disurvei.</li></ul></div>';
}
function evak_peta_batasan(bool $warga): string {
    if ($warga) return '';
    return '<div class="panel" style="margin-top:16px;box-shadow:none"><div class="panel-h"><h2>Batasan data</h2></div><ul class="list" style="padding-left:20px;list-style:disc">'
        . '<li>Posisi Pantai Munir, Tanjung Saga, Pantai Ferry, Pantai Kelapa Enom, dan Jetty adalah <b>perkiraan</b> dari arah mata angin pada peta rujukan; verifikasi dengan survei lapangan.</li>'
        . '<li>Elevasi per titik, kapasitas tampung, dan jalan belum tersedia. Lihat tab Ringkasan dan prioritas.</li>'
        . '<li>Status seluruh titik: <b>usulan</b>, belum ditetapkan BPBD atau Pemerintah Desa.</li></ul></div>';
}

function evak_legenda_html(array $legend): string {
    $h = '<div class="evk-lg">';
    foreach ($legend as $g) $h .= '<div class="row"><i class="sw" style="background:' . e($g['warna']) . '"></i><div><b>' . e($g['label']) . '</b><small>' . e($g['rank']) . ': ' . e(implode(', ', $g['kampung'])) . '</small></div></div>';
    return $h . '<hr><div class="row"><i class="sw" style="background:rgba(215,38,30,.5);border-style:dashed"></i><div><b>Sabuk rob pesisir</b><small>sampai 100 m dari garis pantai</small></div></div>'
        . '<div class="row"><i class="sw" style="background:#ff7a1a"></i><div><b>Arah evakuasi</b><small>indikatif, jalan belum disurvei</small></div></div>'
        . '<div class="row"><i class="sw dm"></i><div><b>Titik kumpul usulan</b><small>satu untuk tiap kampung</small></div></div></div>';
}
