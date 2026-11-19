<?php
declare(strict_types=1);
/* Perhitungan kerawanan: skor parameter, skor total berbobot, tingkat, peringatan aktif, data oseanografi. */

function tingkat_list(): array { $t = tbl('tingkat_kerawanan'); usort($t, fn($a, $b) => $a['skor_min'] <=> $b['skor_min']); return $t; }
function rank_of(int $idTingkat): int { foreach (tingkat_list() as $i => $t) if ($t['id_tingkat'] === $idTingkat) return $i; return 0; }
function tingkat_by_name(string $n): ?array { foreach (tingkat_list() as $t) if ($t['nama_tingkat'] === $n) return $t; return null; }
function tingkat_for(float $skor): ?array { $ts = tingkat_list(); foreach ($ts as $t) if ($skor <= $t['skor_maks']) return $t; return $ts ? end($ts) : null; }
function kelas_of(int $idParam): array { $k = where('kelas_skor', fn($x) => $x['id_parameter'] === $idParam); usort($k, fn($a, $b) => $a['batas_bawah'] <=> $b['batas_bawah']); return $k; }
function score_for(int $idParam, float $nilai): ?int {
    $ks = kelas_of($idParam); if (!$ks) return null;
    foreach ($ks as $k) if ($nilai >= $k['batas_bawah'] && $nilai < $k['batas_atas']) return $k['skor'];
    return $nilai < $ks[0]['batas_bawah'] ? $ks[0]['skor'] : end($ks)['skor'];
}
function skor_bounds(int $idParam): array { $s = array_column(kelas_of($idParam), 'skor'); return $s ? [min($s), max($s)] : [1, 1]; }

function latest_data(int $idW): array { // data terbaru untuk tiap parameter pada satu wilayah
    $m = [];
    foreach (tbl('data_parameter') as $d) {
        if ($d['id_wilayah'] !== $idW) continue; $c = $m[$d['id_parameter']] ?? null;
        if (!$c || $d['tanggal_data'] > $c['tanggal_data'] || ($d['tanggal_data'] === $c['tanggal_data'] && $d['id_data'] > $c['id_data'])) $m[$d['id_parameter']] = $d;
    }
    return $m;
}
function compute_skor(int $idW, array $override = []): ?array { // $override: [id_parameter => nilai] (untuk simulasi atau indikasi langsung)
    $latest = latest_data($idW); $detail = []; $missing = [];
    foreach (tbl('parameter') as $p) {
        $d = $latest[$p['id_parameter']] ?? null; $nilai = $override[$p['id_parameter']] ?? ($d['nilai'] ?? null);
        if ($nilai === null) { if ($p['bobot'] > 0) $missing[] = $p; continue; }
        $skor = score_for($p['id_parameter'], (float) $nilai);
        $detail[] = ['p' => $p, 'nilai' => (float) $nilai, 'skor' => $skor, 'bobot' => $p['bobot'], 'kontribusi' => ($skor ?? 0) * $p['bobot'], 'data' => $d];
    }
    $w = array_values(array_filter($detail, fn($x) => $x['bobot'] > 0 && $x['skor'] !== null)); if (!$w) return null;
    $sb = sum(array_column($w, 'bobot')); $skor = round(sum(array_column($w, 'kontribusi')) / $sb, 2);
    return ['skor' => $skor, 'detail' => $detail, 'missing' => $missing, 'tingkat' => tingkat_for($skor)];
}
function latest_hasil(int $idW): ?array {
    $r = where('hasil_kerawanan', fn($h) => $h['id_wilayah'] === $idW); if (!$r) return null;
    usort($r, fn($a, $b) => [$b['tanggal_analisis'], $b['id_hasil']] <=> [$a['tanggal_analisis'], $a['id_hasil']]); return $r[0];
}
function info_wilayah(int $idW): ?array { // hasil analisis tersimpan terbaru + tingkatnya
    $h = latest_hasil($idW); if (!$h) return null; return ['hasil' => $h, 'tingkat' => row('tingkat_kerawanan', $h['id_tingkat']), 'skor' => $h['skor_total']];
}
function drivers(int $idW, int $n = 3): array {
    $c = compute_skor($idW); if (!$c) return [];
    $d = array_values(array_filter($c['detail'], fn($x) => $x['bobot'] > 0)); usort($d, fn($a, $b) => $b['kontribusi'] <=> $a['kontribusi']); return array_slice($d, 0, $n);
}
function run_analysis(int $uid, string $metode = 'Skoring berbobot (weighted overlay)'): array {
    $n = 0; $skip = [];
    foreach (tbl('wilayah') as $w) {
        $c = compute_skor($w['id_wilayah']); if (!$c) { $skip[] = $w['nama_wilayah']; continue; }
        ins('hasil_kerawanan', ['id_wilayah' => $w['id_wilayah'], 'id_tingkat' => $c['tingkat']['id_tingkat'], 'id_pengguna' => $uid, 'skor_total' => $c['skor'], 'tanggal_analisis' => date('Y-m-d'), 'metode' => $metode]); $n++;
    }
    return ['n' => $n, 'skip' => $skip];
}
function run_if_changed(int $uid, string $metode = 'Skoring berbobot (weighted overlay) dengan angin BMKG'): int {
    $n = 0;
    foreach (tbl('wilayah') as $w) {
        $c = compute_skor($w['id_wilayah']); if (!$c) continue; $last = latest_hasil($w['id_wilayah']);
        if (!$last || abs($last['skor_total'] - $c['skor']) > 1e-9 || $last['id_tingkat'] !== $c['tingkat']['id_tingkat']) {
            ins('hasil_kerawanan', ['id_wilayah' => $w['id_wilayah'], 'id_tingkat' => $c['tingkat']['id_tingkat'], 'id_pengguna' => $uid, 'skor_total' => $c['skor'], 'tanggal_analisis' => date('Y-m-d'), 'metode' => $metode]); $n++;
        }
    }
    return $n;
}
function rescore_all(): int { $n = 0; foreach (tbl('data_parameter') as $d) { $s = score_for($d['id_parameter'], (float) $d['nilai']); if ($s !== $d['skor']) { upd('data_parameter', $d['id_data'], ['skor' => $s]); $n++; } } return $n; }

/* ---------- peringatan dan data laut ---------- */
function active_warnings(): array {
    $now = time(); $ord = ['Normal' => 0, 'Waspada' => 1, 'Siaga' => 2, 'Awas' => 3];
    $r = where('peringatan', fn($p) => ts($p['mulai']) <= $now && $now <= ts($p['berakhir']));
    usort($r, fn($a, $b) => [$ord[$b['status']] ?? 0, $b['mulai']] <=> [$ord[$a['status']] ?? 0, $a['mulai']]); return $r;
}
function warn_state(array $p): array { $n = time(); return ts($p['mulai']) > $n ? ['Terjadwal', '#5E7480'] : (ts($p['berakhir']) < $n ? ['Berakhir', '#8493A0'] : ['Aktif', '#2E9E6B']); }
/* Real-time: tabel data_oseanografi dijaga agar selalu mencakup jendela -24 s.d. +48 jam dari jam sekarang.
   Bila jendela tidak tercakup (data lama), baris dibangkitkan ulang dari jam berjalan. Dipanggil sekali per permintaan. */
function osea_sinkron(): void {
    static $sudah = false; if ($sudah) return; $sudah = true;
    $now = (int) (floor(time() / 3600) * 3600);
    $ts = array_filter(array_map(fn($r) => ts($r['waktu']), tbl('data_oseanografi')), fn($t) => $t !== null);
    if ($ts && min($ts) <= $now - 24 * 3600 && max($ts) >= $now + 47 * 3600) return;
    $idSim = ensure_sumber('Data simulasi prototipe (bukan data resmi)', 'Simulasi', '');
    $idPasut = pasut_ada() ? sumber_pasut_big() : null;
    $baris = gen_osea($now, -24, 48, $idSim, $idPasut);
    db()->exec('DELETE FROM `data_oseanografi`'); tbl_flush('data_oseanografi');
    foreach ($baris as $r) ins('data_oseanografi', $r);
    tbl_flush('data_oseanografi');
}
function nearest_osea(): ?array {
    osea_sinkron(); $rows = tbl('data_oseanografi'); if (!$rows) return null; $now = time(); $best = null; $bd = PHP_INT_MAX;
    foreach ($rows as $r) { $d = abs(ts($r['waktu']) - $now); if ($d < $bd) { $bd = $d; $best = $r; } }
    return ['row' => $best, 'gap_h' => $bd / 3600];
}
function osea_series(int $fromH = -24, int $toH = 48): array {
    osea_sinkron(); $now = time(); $r = array_values(array_filter(array_map(fn($x) => $x + ['t' => ts($x['waktu'])], tbl('data_oseanografi')), fn($x) => $x['t'] >= $now + $fromH * 3600 && $x['t'] <= $now + $toH * 3600));
    usort($r, fn($a, $b) => $a['t'] <=> $b['t']); return $r;
}
/* v12: deret pasang surut langsung dari berkas BIG (bukan dari tabel data_oseanografi yang hanya berisi 72 jam
   sejak pemasangan). Bentuk baris sama dengan osea_series() agar grafik lama tetap dapat dipakai. */
function pasut_series_rows(int $fromH, int $toH): array {
    if (!function_exists('pasut_ada') || !pasut_ada()) return []; $now = time(); $out = [];
    foreach (pasut_deret($now + $fromH * 3600, $now + $toH * 3600) as [$t, $z]) $out[] = ['t' => $t, 'waktu' => db_dt($t), 'pasut_m' => round($z, 3), 'jenis_data' => 'prediksi'];
    return $out;
}
/* Muka air sekarang: prediksi BIG bila tersedia, bila tidak baris data_oseanografi terdekat. */
function muka_air_kini(): ?array {
    $now = time(); $z = function_exists('pasut_at') ? pasut_at($now) : null;
    if ($z !== null) { $p = pasut_at($now - 1800); return ['row' => ['waktu' => db_dt($now), 'pasut_m' => round($z, 3), 'jenis_data' => 'prediksi'], 'gap_h' => 0, 'big' => true, 'naik' => $p === null ? null : $z >= $p]; }
    $c = nearest_osea(); if ($c) $c['big'] = false; return $c;
}
function tindakan_list(int $idTingkat): array { $r = where('tindakan', fn($t) => $t['id_tingkat'] === $idTingkat); usort($r, fn($a, $b) => $a['urutan'] <=> $b['urutan']); return $r; }
function jenis_label(string $j): string { return ['prediksi' => 'Prediksi', 'simulasi' => 'Simulasi', 'observasi' => 'Observasi'][$j] ?? $j; }
function param_by_name(string $re): ?array { foreach (tbl('parameter') as $p) if (preg_match($re, $p['nama_parameter'])) return $p; return null; }

/*
 * ---------- Data oseanografi ----------
 * PASANG SURUT: diambil dari PREDIKSI RESMI BIG/SRGI (berkas data/pasut_big/),
 * bukan lagi simulasi. Nilai relatif MSL setempat, karena itu dapat BERNILAI NEGATIF.
 * GELOMBANG, ANGIN, ARUS: belum tersedia lewat API terbuka BMKG, sehingga masih
 * dibangkitkan sebagai SIMULASI dan ditandai demikian pada setiap tampilan.
 */
function tide_at(float $h): float {
    $v = pasut_at((int) round($h * 3600));
    if ($v !== null) return $v;                       // prediksi BIG tersedia untuk jam ini
    /* Cadangan, hanya bila jam berada di luar rentang berkas BIG (mis. di luar 2024-2028).
       Model kasar ini SIMULASI dan tidak terpakai selama berkas BIG mencakup waktunya. */
    $M2 = 12.4206; $K1 = 23.934; $spring = cos(2 * M_PI * $h / (14.77 * 24) * 2);
    return 0.34 * cos(2 * M_PI * ($h - 3.1) / $M2) * (1 + 0.28 * $spring) + 0.16 * cos(2 * M_PI * ($h - 8.4) / $K1);
}
/* Benar bila pasang surut pada jam itu berasal dari prediksi BIG. */
function tide_dari_big(float $h): bool { return pasut_at((int) round($h * 3600)) !== null; }
/*
 * Membangkitkan baris data_oseanografi.
 * $idSumber      : sumber untuk baris yang pasang surutnya SIMULASI.
 * $idSumberPasut : sumber BIG/SRGI, dipakai bila pasang surut berasal dari prediksi BIG.
 * jenis_data menjadi 'prediksi' bila pasang surut dari BIG, dan 'simulasi' bila tidak.
 * Catatan kejujuran data: dalam satu baris, hanya kolom pasut_m yang berasal dari BIG;
 * gelombang_m, angin_knot, dan arus_ms tetap simulasi. Antarmuka menandai tiap
 * besaran secara terpisah (lihat kondisi_strip() dan kondisi_compact() di layout.php).
 */
function gen_osea(int $baseTs, int $fromH, int $toH, int $idSumber, ?int $idSumberPasut = null): array {
    mt_srand((int) floor($baseTs / 86400)); $rows = [];
    for ($k = $fromH; $k <= $toH; $k++) {
        $t = $baseTs + $k * 3600; $h = $t / 3600;
        $dariBig = tide_dari_big($h);
        $pasut = tide_at($h); $dh = abs(tide_at($h + 0.5) - tide_at($h - 0.5)); $rnd = fn() => mt_rand() / mt_getrandmax();
        $gel = clamp(0.38 + 0.18 * sin($h / 9) + 0.1 * sin($h / 3.7 + 1) + ($rnd() - 0.5) * 0.06, 0.1, 1.6);
        $ang = clamp(9 + 4 * sin($h / 11 + 2) + 2 * sin($h / 4) + ($rnd() - 0.5) * 1.2, 2, 30);
        $aru = clamp(0.1 + $dh * 0.85 + ($rnd() - 0.5) * 0.03, 0.04, 1.2);
        $rows[] = ['id_sumber' => ($dariBig && $idSumberPasut) ? $idSumberPasut : $idSumber, 'waktu' => db_dt($t),
            'pasut_m' => round($pasut, 3), 'gelombang_m' => round($gel, 2), 'angin_knot' => round($ang, 1),
            'arus_ms' => round($aru, 2), 'jenis_data' => $dariBig ? 'prediksi' : 'simulasi'];
    }
    return $rows;
}
/* id sumber_data untuk prediksi pasut BIG (dibuat bila belum ada). */
function sumber_pasut_big(): int { return ensure_sumber(PASUT_SUMBER, 'Data sekunder (prediksi pasut nasional)', PASUT_SUMBER_URL); }
