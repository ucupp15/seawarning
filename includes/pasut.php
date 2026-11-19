<?php
declare(strict_types=1);
/*
 * Pasang surut: pembacaan data prediksi BIG/SRGI, analisis harmonik least-squares,
 * bilangan Formzahl, klasifikasi tipe pasut, statistik muka air, dan ambang banjir rob.
 *
 * SUMBER DATA  : Badan Informasi Geospasial (BIG), Direktorat Sistem Referensi Geospasial
 *                (SRGI), produk "Prediksi Pasang Surut", srgi@big.go.id.
 * BERKAS       : data/pasut_big/data_pasut{tahun}.txt  -- DISALIN APA ADANYA dari BIG,
 *                tidak diubah satu karakter pun. Jangan sunting berkas ini.
 * DATUM        : tinggi muka air z (m) relatif terhadap Mean Sea Level (MSL) setempat.
 * WAKTU BERKAS : UTC. Ditampilkan dalam WIB (UTC+7) di seluruh aplikasi.
 * RESOLUSI     : 1 jam.
 */

const PASUT_LAT = -5.93014699, PASUT_LON = 106.15350779;
const PASUT_SUMBER = 'Prediksi Pasang Surut BIG - Direktorat Sistem Referensi Geospasial (SRGI)';
const PASUT_SUMBER_URL = 'https://srgi.big.go.id/';

/* Kecepatan sudut komponen harmonik (derajat/jam) -- nilai standar astronomi pasut. */
const PASUT_SPEED = [
    'M2' => 28.9841042, 'S2' => 30.0000000, 'N2' => 28.4397295, 'K2' => 30.0821373,
    'K1' => 15.0410686, 'O1' => 13.9430356, 'P1' => 14.9589314, 'Q1' => 13.3986609,
    'M4' => 57.9682084, 'MS4' => 58.9841042, 'M6' => 86.9523127,
    'Mf' => 1.0980331, 'Mm' => 0.5443747, 'Sa' => 0.0410686, 'Ssa' => 0.0821373,
];
/*
 * PEMILIHAN KOMPONEN -- dasar: kriteria Rayleigh (dua komponen baru terpisah bila
 * selisih kecepatan sudut x panjang rekaman >= 360 derajat).
 *
 * Rekaman 1 tahun (8760-8784 jam), 8 komponen utama. Semua pasangan terpisah:
 *   M2-N2 dan O1-Q1 : 8760 x 0,5443747 = 4769 derajat
 *   S2-K2 dan K1-P1 : 8760 x 0,0821373 =  720 derajat
 * Sa (0,0410686 derajat/jam) TIDAK dipakai: 8760 x 0,0410686 = 359,8 derajat < 360,
 * artinya Sa tidak terpisah dari Z0 pada rekaman 1 tahun. Memasukkannya membuat
 * matriks normal ill-conditioned dan amplitudo K1/P1/S2/K2 ikut bergeser.
 * M4, MS4, M6, Mf, Mm, Ssa juga tidak dipakai: pada data prediksi BIG amplitudonya
 * < 0,00001 m (produk BIG hanya memuat komponen astronomi utama, tanpa komponen
 * perairan dangkal maupun komponen musiman), sehingga hanya menambah derau numerik.
 */
const PASUT_SET_TAHUN = ['M2', 'S2', 'N2', 'K2', 'K1', 'O1', 'P1', 'Q1'];
/*
 * Rekaman 1 bulan (~720 jam): K2 tidak terpisah dari S2 dan P1 tidak terpisah dari K1
 * (butuh ~183 hari; 720 x 0,0821373 = 59 derajat). Keduanya DIKELUARKAN dari
 * pencocokan lalu diisi dengan rasio inferensi standar di pasut_infer().
 * Yang tersisa tetap terpisah: M2-N2 dan O1-Q1 = 720 x 0,5443747 = 392 derajat.
 */
const PASUT_SET_BULAN = ['M2', 'S2', 'N2', 'K1', 'O1', 'Q1'];
const PASUT_INFER_K2 = 0.27215; // K2 = 0,27215 x S2
const PASUT_INFER_P1 = 0.33093; // P1 = 0,33093 x K1

/* Klasifikasi tipe pasut menurut bilangan Formzahl. */
const PASUT_TIPE = [
    ['maks' => 0.25, 'nama' => 'Semidiurnal (Harian Ganda)', 'key' => 'semidiurnal', 'warna' => '#2E6FB0',
        'desc' => 'Dua kali pasang dan dua kali surut setiap hari dengan tinggi yang hampir sama.'],
    ['maks' => 1.5, 'nama' => 'Campuran Condong Harian Ganda', 'key' => 'campuran-ganda', 'warna' => '#2E9E9B',
        'desc' => 'Dua kali pasang dan dua kali surut setiap hari, tetapi tinggi dan selang waktunya berbeda.'],
    ['maks' => 3.0, 'nama' => 'Campuran Condong Harian Tunggal', 'key' => 'campuran-tunggal', 'warna' => '#E6A100',
        'desc' => 'Umumnya satu kali pasang dan satu kali surut sehari; kadang dua kali dengan tinggi yang sangat berbeda.'],
    ['maks' => INF, 'nama' => 'Diurnal (Harian Tunggal)', 'key' => 'diurnal', 'warna' => '#C2561E',
        'desc' => 'Satu kali pasang dan satu kali surut dalam satu hari.'],
];

function pasut_dir(): string { return APP_ROOT . '/data/pasut_big'; }

/* ---------------------------------------------------------------- cache berkas */
function pasut_cache(string $key, callable $buat, string $stamp = '') {
    $d = storage_dir() . '/cache';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    $f = $d . '/pasut_' . preg_replace('/[^a-z0-9_]/i', '', $key) . '.ser';
    if (is_file($f)) {
        $raw = @file_get_contents($f);
        if ($raw !== false) {
            $c = @unserialize($raw);
            if (is_array($c) && ($c['stamp'] ?? null) === $stamp && ($c['v'] ?? null) === APP_VERSION) return $c['data'];
        }
    }
    $data = $buat();
    @file_put_contents($f, serialize(['stamp' => $stamp, 'v' => APP_VERSION, 'data' => $data]), LOCK_EX);
    return $data;
}
function pasut_cache_clear(): int {
    $n = 0;
    foreach (glob(storage_dir() . '/cache/pasut_*.ser') ?: [] as $f) { if (@unlink($f)) $n++; }
    return $n;
}

/* ---------------------------------------------------------------- pembacaan berkas BIG */
function pasut_tahun_tersedia(): array {
    static $y = null;
    if ($y !== null) return $y;
    $y = [];
    foreach (glob(pasut_dir() . '/data_pasut*.txt') ?: [] as $f) {
        if (preg_match('/data_pasut(\d{4})\.txt$/', $f, $m)) $y[] = (int) $m[1];
    }
    sort($y);
    return $y;
}
function pasut_stamp(int $tahun): string {
    $f = pasut_dir() . "/data_pasut$tahun.txt";
    return is_file($f) ? $tahun . ':' . filesize($f) . ':' . filemtime($f) : 'kosong';
}

/*
 * Membaca satu berkas tahun. Keluaran:
 *   ['t0' => timestamp UNIX baris pertama, 'z' => [float,...] per jam,
 *    'qc' => [...]  hasil pemeriksaan kelengkapan]
 * Baris yang tidak sesuai pola diabaikan (baris judul berkas BIG).
 */
function pasut_baca(int $tahun): ?array {
    return pasut_cache("y$tahun", function () use ($tahun) {
        $f = pasut_dir() . "/data_pasut$tahun.txt";
        if (!is_file($f)) return null;
        $fh = @fopen($f, 'r');
        if (!$fh) return null;
        $z = []; $t0 = null; $tPrev = null; $lompat = 0; $ganda = 0; $takWajar = 0; $nBaris = 0;
        $re = '/^\s*(-?\d+\.\d+)\s+(-?\d+\.\d+)\s+(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2}):(\d{2})\s+(-?\d+\.\d+)\s*$/';
        while (($line = fgets($fh)) !== false) {
            if (!preg_match($re, $line, $m)) continue;
            $nBaris++;
            $t = gmmktime((int) $m[6], (int) $m[7], (int) $m[8], (int) $m[4], (int) $m[5], (int) $m[3]);
            $v = (float) $m[9];
            if (abs($v) > 10) { $takWajar++; continue; }           // penjagaan nilai tak wajar
            if ($t0 === null) { $t0 = $t; $z[] = $v; $tPrev = $t; continue; }
            $d = $t - $tPrev;
            if ($d === 0) { $ganda++; continue; }                   // baris kembar
            if ($d !== 3600) {                                      // ada lompatan: isi dengan null
                $n = (int) round($d / 3600);
                if ($n > 1 && $n < 24 * 40) { for ($i = 1; $i < $n; $i++) { $z[] = null; $lompat++; } }
                elseif ($n < 1) { continue; }                       // urutan mundur: diabaikan
            }
            $z[] = $v; $tPrev = $t;
        }
        fclose($fh);
        if ($t0 === null) return null;
        $kabisat = ($tahun % 4 === 0 && ($tahun % 100 !== 0 || $tahun % 400 === 0));
        $harap = $kabisat ? 8784 : 8760;
        return ['tahun' => $tahun, 't0' => $t0, 'z' => $z, 'qc' => [
            'baris_terbaca' => $nBaris, 'jam_tersusun' => count($z), 'jam_diharapkan' => $harap,
            'lengkap' => count($z) === $harap && $lompat === 0,
            'lompatan_diisi_kosong' => $lompat, 'baris_kembar' => $ganda, 'nilai_tak_wajar' => $takWajar,
            'mulai_utc' => gmdate('Y-m-d H:i:s', $t0), 'akhir_utc' => gmdate('Y-m-d H:i:s', $t0 + (count($z) - 1) * 3600),
        ]];
    }, pasut_stamp($tahun));
}

/* Nilai pada satu timestamp (interpolasi linier antar jam). NULL bila di luar rentang data. */
function pasut_at(int $ts): ?float {
    foreach (pasut_tahun_tersedia() as $y) {
        $d = pasut_baca($y);
        if (!$d) continue;
        $n = count($d['z']); $akhir = $d['t0'] + ($n - 1) * 3600;
        if ($ts < $d['t0'] || $ts > $akhir) continue;
        $x = ($ts - $d['t0']) / 3600.0; $i = (int) floor($x); $fr = $x - $i;
        $a = $d['z'][$i] ?? null;
        if ($fr < 1e-9) return $a;
        $b = $d['z'][$i + 1] ?? null;
        if ($a === null || $b === null) return $a ?? $b;
        return $a + ($b - $a) * $fr;
    }
    return null;
}
/* Deret [[timestamp, z], ...] pada rentang waktu, langkah $stepJam jam. */
function pasut_deret(int $dariTs, int $keTs, int $stepJam = 1): array {
    $out = [];
    $dariTs = (int) (floor($dariTs / 3600) * 3600);
    for ($t = $dariTs; $t <= $keTs; $t += $stepJam * 3600) {
        $v = pasut_at($t);
        if ($v !== null) $out[] = [$t, $v];
    }
    return $out;
}
function pasut_rentang_data(): ?array {
    $y = pasut_tahun_tersedia(); if (!$y) return null;
    $a = pasut_baca($y[0]); $b = pasut_baca($y[count($y) - 1]);
    if (!$a || !$b) return null;
    return [$a['t0'], $b['t0'] + (count($b['z']) - 1) * 3600];
}
function pasut_ada(): bool { return pasut_tahun_tersedia() !== [] && pasut_baca(pasut_tahun_tersedia()[0]) !== null; }

/* ---------------------------------------------------------------- analisis harmonik */
/* Penyelesaian sistem linier (eliminasi Gauss dengan pivot sebagian). */
function pasut_solve(array $A, array $b): ?array {
    $n = count($b);
    for ($i = 0; $i < $n; $i++) $A[$i][] = $b[$i];
    for ($c = 0; $c < $n; $c++) {
        $p = $c; for ($r = $c + 1; $r < $n; $r++) if (abs($A[$r][$c]) > abs($A[$p][$c])) $p = $r;
        if (abs($A[$p][$c]) < 1e-12) return null;
        if ($p !== $c) { $t = $A[$p]; $A[$p] = $A[$c]; $A[$c] = $t; }
        $piv = $A[$c][$c];
        for ($r = $c + 1; $r < $n; $r++) {
            $f = $A[$r][$c] / $piv; if ($f === 0.0) continue;
            for ($k = $c; $k <= $n; $k++) $A[$r][$k] -= $f * $A[$c][$k];
        }
    }
    $x = array_fill(0, $n, 0.0);
    for ($r = $n - 1; $r >= 0; $r--) {
        $s = $A[$r][$n]; for ($k = $r + 1; $k < $n; $k++) $s -= $A[$r][$k] * $x[$k];
        $x[$r] = $s / $A[$r][$r];
    }
    return $x;
}
/*
 * Analisis harmonik least-squares:  z(t) = Z0 + Σ A_i cos(ω_i t - g_i)
 * $z        : nilai per jam (boleh memuat null; baris null dilewati)
 * $comps    : daftar nama komponen
 * Keluaran  : ['Z0'=>float, 'amp'=>[nama=>['A'=>..,'g'=>..]], 'rms'=>.., 'r2'=>.., 'n'=>..]
 */
function pasut_harmonik(array $z, array $comps): ?array {
    $k = count($comps); $n = 2 * $k + 1;
    $w = []; foreach ($comps as $c) $w[] = deg2rad(PASUT_SPEED[$c]);
    $N = []; for ($i = 0; $i < $n; $i++) $N[$i] = array_fill(0, $n, 0.0);
    $b = array_fill(0, $n, 0.0); $m = 0; $sy = 0.0; $syy = 0.0;
    foreach ($z as $i => $v) {
        if ($v === null) continue;
        $t = (float) $i; $row = [1.0];
        foreach ($w as $wi) { $a = $wi * $t; $row[] = cos($a); $row[] = sin($a); }
        for ($p = 0; $p < $n; $p++) {
            $rp = $row[$p];
            for ($q = $p; $q < $n; $q++) $N[$p][$q] += $rp * $row[$q];
            $b[$p] += $rp * $v;
        }
        $m++; $sy += $v; $syy += $v * $v;
    }
    if ($m < $n * 3) return null;
    for ($p = 0; $p < $n; $p++) for ($q = 0; $q < $p; $q++) $N[$p][$q] = $N[$q][$p];
    $sol = pasut_solve($N, $b);
    if ($sol === null) return null;
    $amp = [];
    foreach ($comps as $j => $c) {
        $a = $sol[1 + 2 * $j]; $s = $sol[2 + 2 * $j];
        /* Model z = Z0 + Σ A cos(σt − g), sehingga A cos g = a dan A sin g = s → g = atan2(s, a). */
        $amp[$c] = ['A' => sqrt($a * $a + $s * $s), 'g' => fmod(rad2deg(atan2($s, $a)) + 360.0, 360.0)];
    }
    /* sisa (residual) */
    $ss = 0.0;
    foreach ($z as $i => $v) {
        if ($v === null) continue;
        $t = (float) $i; $f = $sol[0];
        foreach ($comps as $j => $c) $f += $amp[$c]['A'] * cos($w[$j] * $t - deg2rad($amp[$c]['g']));
        $ss += ($v - $f) ** 2;
    }
    $varY = $syy / $m - ($sy / $m) ** 2;
    return ['Z0' => $sol[0], 'amp' => $amp, 'rms' => sqrt($ss / $m),
            'r2' => $varY > 0 ? 1.0 - ($ss / $m) / $varY : 0.0, 'n' => $m];
}
/* Melengkapi K2 dan P1 dengan rasio inferensi (dipakai pada analisis bulanan). */
function pasut_infer(array $amp): array {
    if (!isset($amp['K2']) && isset($amp['S2'])) $amp['K2'] = ['A' => $amp['S2']['A'] * PASUT_INFER_K2, 'g' => $amp['S2']['g'], 'inferensi' => true];
    if (!isset($amp['P1']) && isset($amp['K1'])) $amp['P1'] = ['A' => $amp['K1']['A'] * PASUT_INFER_P1, 'g' => $amp['K1']['g'], 'inferensi' => true];
    return $amp;
}
/* Bilangan Formzahl F = (AK1 + AO1) / (AM2 + AS2) */
function pasut_formzahl(array $amp): ?float {
    foreach (['K1', 'O1', 'M2', 'S2'] as $c) if (!isset($amp[$c])) return null;
    $p = $amp['M2']['A'] + $amp['S2']['A'];
    return $p > 1e-9 ? ($amp['K1']['A'] + $amp['O1']['A']) / $p : null;
}
function pasut_tipe(?float $F): ?array {
    if ($F === null) return null;
    foreach (PASUT_TIPE as $t) if ($F <= $t['maks']) return $t + ['F' => $F];
    return end(PASUT_TIPE) + ['F' => $F];
}

/* ---------------------------------------------------------------- statistik muka air */
function pasut_stat(array $z, float $z0, array $amp): array {
    $v = array_values(array_filter($z, fn($x) => $x !== null));
    if (!$v) return [];
    sort($v);
    $n = count($v);
    $pct = function (float $p) use ($v, $n) {
        $x = ($n - 1) * $p / 100.0; $i = (int) floor($x); $f = $x - $i;
        return $i + 1 < $n ? $v[$i] + ($v[$i + 1] - $v[$i]) * $f : $v[$i];
    };
    $A = fn(string $c) => $amp[$c]['A'] ?? 0.0;
    $utama = $A('M2') + $A('S2') + $A('N2') + $A('K2') + $A('K1') + $A('O1') + $A('P1');
    /* puncak dan lembah lokal pada deret asli (bukan terurut) */
    $raw = array_values($z); $hi = []; $lo = [];
    for ($i = 1; $i < count($raw) - 1; $i++) {
        $a = $raw[$i - 1]; $b = $raw[$i]; $c = $raw[$i + 1];
        if ($a === null || $b === null || $c === null) continue;
        if ($b > $a && $b > $c) $hi[] = $b;
        if ($b < $a && $b < $c) $lo[] = $b;
    }
    return [
        'n' => $n,
        'Z0_MSL' => $z0,
        'z_maks' => $v[$n - 1], 'z_min' => $v[0],
        'tunggang_maks' => $v[$n - 1] - $v[0],
        'MHWL' => $hi ? array_sum($hi) / count($hi) : null,
        'MLWL' => $lo ? array_sum($lo) / count($lo) : null,
        'HHWL_formula' => $z0 + $utama,
        'LLWL_formula' => $z0 - $utama,
        'LWS' => $z0 - ($A('M2') + $A('S2') + $A('K1') + $A('O1')),
        'p50' => $pct(50), 'p95' => $pct(95), 'p99' => $pct(99), 'p999' => $pct(99.9),
    ];
}
/* Waktu (timestamp) muka air tertinggi dan terendah pada satu blok. */
function pasut_ekstrem(array $z, int $t0): array {
    $iMax = $iMin = null; $vMax = -INF; $vMin = INF;
    foreach ($z as $i => $v) {
        if ($v === null) continue;
        if ($v > $vMax) { $vMax = $v; $iMax = $i; }
        if ($v < $vMin) { $vMin = $v; $iMin = $i; }
    }
    return ['maks_ts' => $iMax === null ? null : $t0 + $iMax * 3600, 'maks' => $iMax === null ? null : $vMax,
            'min_ts' => $iMin === null ? null : $t0 + $iMin * 3600, 'min' => $iMin === null ? null : $vMin];
}

/* ---------------------------------------------------------------- deret menyatu & pemotongan kalender */
/*
 * Seluruh berkas tahun disatukan menjadi SATU deret menerus (jeja antar berkas diisi null),
 * lalu dipotong menurut KALENDER WIB. Alasan: aplikasi ini menampilkan waktu WIB dan pengguna
 * berpikir dalam bulan kalender setempat, sehingga blok "Januari" harus berarti Januari WIB.
 * Konsekuensi yang dilaporkan terbuka di panel QC: berkas BIG bermula 00.00 UTC = 07.00 WIB,
 * maka tahun WIB pertama kekurangan 7 jam (perlu berkas tahun sebelumnya) dan 7 jam terakhir
 * berkas jatuh ke tahun WIB berikutnya.
 */
function pasut_semua_stamp(): string {
    return implode(',', array_map('pasut_stamp', pasut_tahun_tersedia()));
}
function pasut_semua(): ?array {
    static $m = null;
    if ($m !== null) return $m ?: null;
    $m = pasut_cache('semua', function () {
        $z = []; $t0 = null;
        foreach (pasut_tahun_tersedia() as $y) {
            $d = pasut_baca($y); if (!$d) continue;
            if ($t0 === null) { $t0 = $d['t0']; }
            else {
                $harus = $t0 + count($z) * 3600;
                if ($d['t0'] > $harus) { $n = (int) round(($d['t0'] - $harus) / 3600); for ($i = 0; $i < $n; $i++) $z[] = null; }
            }
            foreach ($d['z'] as $v) $z[] = $v;
        }
        return $t0 === null ? false : ['t0' => $t0, 'z' => $z];
    }, pasut_semua_stamp());
    return $m ?: null;
}
/* Blok satu tahun WIB: ['t0'=>ts jam pertama, 'z'=>[...]]. */
function pasut_blok_tahun(int $tahun): ?array {
    $m = pasut_semua(); if (!$m) return null;
    $t0 = null; $z = [];
    foreach ($m['z'] as $i => $v) {
        $ts = $m['t0'] + $i * 3600;
        if ((int) date('Y', $ts) !== $tahun) continue;   // date() memakai Asia/Jakarta -> WIB
        if ($t0 === null) $t0 = $ts;
        $z[] = $v;
    }
    return $t0 === null ? null : ['t0' => $t0, 'z' => $z, 'tahun' => $tahun];
}
/* 12 blok bulan WIB pada satu tahun WIB. */
function pasut_blok_bulan(int $tahun): array {
    $m = pasut_semua(); $out = array_fill(1, 12, ['t0' => null, 'z' => []]);
    if (!$m) return $out;
    foreach ($m['z'] as $i => $v) {
        $ts = $m['t0'] + $i * 3600;
        if ((int) date('Y', $ts) !== $tahun) continue;
        $b = (int) date('n', $ts);
        if ($out[$b]['t0'] === null) $out[$b]['t0'] = $ts;
        $out[$b]['z'][] = $v;
    }
    return $out;
}
/* Jumlah jam yang semestinya ada pada satu bulan kalender. */
function pasut_jam_bulan(int $tahun, int $bulan): int { return 24 * (int) date('t', mktime(0, 0, 0, $bulan, 1, $tahun)); }

/* ---------------------------------------------------------------- analisis per tahun & bulan */
function pasut_analisis(int $tahun): ?array {
    return pasut_cache("an$tahun", function () use ($tahun) {
        $d = pasut_blok_tahun($tahun);
        if (!$d) return null;
        $baca = pasut_baca($tahun);
        $h = pasut_harmonik($d['z'], PASUT_SET_TAHUN);
        if (!$h) return null;
        $F = pasut_formzahl($h['amp']);
        $jamHarus = 24 * ((int) date('L', mktime(0, 0, 0, 6, 1, $tahun)) ? 366 : 365);
        $out = [
            'tahun' => $tahun, 't0' => $d['t0'],
            'qc' => ($baca['qc'] ?? []) + [
                'jam_tahun_wib' => count($d['z']),
                'jam_tahun_wib_diharapkan' => $jamHarus,
                'tahun_wib_lengkap' => count($d['z']) === $jamHarus,
            ],
            'Z0' => $h['Z0'], 'amp' => $h['amp'], 'rms' => $h['rms'], 'r2' => $h['r2'],
            'F' => $F, 'tipe' => pasut_tipe($F),
            'stat' => pasut_stat($d['z'], $h['Z0'], $h['amp']),
            'ekstrem' => pasut_ekstrem($d['z'], $d['t0']),
            'bulan' => [],
        ];
        foreach (pasut_blok_bulan($tahun) as $b => $blk) {
            if ($blk['t0'] === null || count($blk['z']) < 200) { $out['bulan'][$b] = null; continue; }
            $hb = pasut_harmonik($blk['z'], PASUT_SET_BULAN);
            if (!$hb) { $out['bulan'][$b] = null; continue; }
            $ab = pasut_infer($hb['amp']);
            $Fb = pasut_formzahl($ab);
            $out['bulan'][$b] = [
                'bulan' => $b, 'tahun' => $tahun, 't0' => $blk['t0'],
                'Z0' => $hb['Z0'], 'amp' => $ab, 'rms' => $hb['rms'], 'r2' => $hb['r2'],
                'F' => $Fb, 'tipe' => pasut_tipe($Fb),
                'stat' => pasut_stat($blk['z'], $hb['Z0'], $ab),
                'ekstrem' => pasut_ekstrem($blk['z'], $blk['t0']),
                'harian' => pasut_harian($blk['z'], $blk['t0']),
                'jam' => count($blk['z']),
                'jam_diharapkan' => pasut_jam_bulan($tahun, $b),
                'lengkap' => count($blk['z']) === pasut_jam_bulan($tahun, $b),
            ];
        }
        return $out;
    }, pasut_semua_stamp());
}
/* Ringkasan harian (maks, min, rata) untuk grafik bulanan. */
function pasut_harian(array $z, int $t0): array {
    $g = [];
    foreach ($z as $i => $v) {
        if ($v === null) continue;
        $ts = $t0 + $i * 3600; $k = (int) date('j', $ts);
        $g[$k][] = $v;
    }
    $out = [];
    foreach ($g as $k => $vs) $out[] = ['h' => $k, 'maks' => max($vs), 'min' => min($vs), 'rata' => array_sum($vs) / count($vs)];
    usort($out, fn($a, $b) => $a['h'] <=> $b['h']);
    return $out;
}
/* Analisis gabungan seluruh tahun yang tersedia (rujukan utama tipe pasut). */
function pasut_gabungan(): ?array {
    $ys = pasut_tahun_tersedia(); if (!$ys) return null;
    return pasut_cache('gab', function () use ($ys) {
        $m = pasut_semua(); if (!$m) return null;
        $t0 = $m['t0']; $z = $m['z'];
        $h = pasut_harmonik($z, PASUT_SET_TAHUN);
        if (!$h) return null;
        $F = pasut_formzahl($h['amp']);
        return ['tahun_awal' => $ys[0], 'tahun_akhir' => $ys[count($ys) - 1], 'jumlah_tahun' => count($ys),
                't0' => $t0, 'Z0' => $h['Z0'], 'amp' => $h['amp'], 'rms' => $h['rms'], 'r2' => $h['r2'],
                'F' => $F, 'tipe' => pasut_tipe($F),
                'stat' => pasut_stat($z, $h['Z0'], $h['amp']), 'ekstrem' => pasut_ekstrem($z, $t0),
                'jam' => count($z)];
    }, pasut_semua_stamp());
}

/* ---------------------------------------------------------------- ambang banjir rob */
/*
 * Ambang disusun dari persentil empiris SELURUH periode data (bukan angka pilihan).
 * PENTING: ini ambang pasang ASTRONOMIS. Banjir rob di lapangan juga dipengaruhi
 * tinggi gelombang, angin, dan anomali muka laut yang TIDAK ada dalam prediksi BIG.
 */
const PASUT_AMBANG_DEF = [
    ['key' => 'normal',  'nama' => 'Normal',   'p' => 90.0,  'warna' => '#2E9E6B'],
    ['key' => 'waspada', 'nama' => 'Waspada',  'p' => 98.0,  'warna' => '#E6A100'],
    ['key' => 'siaga',   'nama' => 'Siaga',    'p' => 99.5,  'warna' => '#D64933'],
    ['key' => 'bahaya',  'nama' => 'Bahaya',   'p' => 99.9,  'warna' => '#8E2B20'],
];
function pasut_ambang(): array {
    return pasut_cache('ambang', function () {
        $m = pasut_semua(); if (!$m) return [];
        $v = [];
        foreach ($m['z'] as $x) if ($x !== null) $v[] = $x;
        if (!$v) return [];
        sort($v); $n = count($v);
        $pct = function (float $p) use ($v, $n) {
            $x = ($n - 1) * $p / 100.0; $i = (int) floor($x); $f = $x - $i;
            return $i + 1 < $n ? $v[$i] + ($v[$i + 1] - $v[$i]) * $f : $v[$i];
        };
        $out = ['n' => $n, 'MSL' => array_sum($v) / $n, 'maks' => $v[$n - 1], 'min' => $v[0], 'tingkat' => []];
        foreach (PASUT_AMBANG_DEF as $a) $out['tingkat'][$a['key']] = $a + ['batas' => $pct($a['p'])];
        return $out;
    }, pasut_semua_stamp());
}
/* Tingkat ambang untuk satu nilai muka air. */
function pasut_status_ambang(float $z): ?array {
    $a = pasut_ambang(); if (!$a) return null;
    $hit = null;
    foreach ($a['tingkat'] as $t) if ($z >= $t['batas']) $hit = $t;
    return $hit ?? $a['tingkat']['normal'] + ['batas' => $a['tingkat']['normal']['batas']];
}
/* Jumlah jam dan hari yang melewati tiap ambang, pada satu rentang indeks deret. */
function pasut_kejadian(array $z, int $t0): array {
    $a = pasut_ambang(); if (!$a) return [];
    $out = [];
    foreach (['waspada', 'siaga', 'bahaya'] as $k) {
        $thr = $a['tingkat'][$k]['batas']; $jam = 0; $hari = [];
        foreach ($z as $i => $v) {
            if ($v === null || $v < $thr) continue;
            $jam++; $hari[date('Y-m-d', $t0 + $i * 3600)] = 1;
        }
        $out[$k] = ['batas' => $thr, 'jam' => $jam, 'hari' => count($hari)];
    }
    return $out;
}
function pasut_kejadian_tahun(int $tahun): array {
    return pasut_cache("ev$tahun", function () use ($tahun) {
        $d = pasut_blok_tahun($tahun); if (!$d) return [];
        $out = ['tahun' => pasut_kejadian($d['z'], $d['t0']), 'bulan' => []];
        foreach (pasut_blok_bulan($tahun) as $b => $blk) {
            $out['bulan'][$b] = ($blk['t0'] === null) ? [] : pasut_kejadian($blk['z'], $blk['t0']);
        }
        return $out;
    }, pasut_semua_stamp());
}

/* ---------------------------------------------------------------- hari tertinggi */
/* N hari dengan muka air tertinggi pada seluruh periode (untuk daftar waspada). */
function pasut_hari_tertinggi(int $n = 20): array {
    return pasut_cache("top$n", function () use ($n) {
        $m = pasut_semua(); if (!$m) return [];
        $hari = [];
        foreach ($m['z'] as $i => $v) {
            if ($v === null) continue;
            $ts = $m['t0'] + $i * 3600; $k = date('Y-m-d', $ts);
            if (!isset($hari[$k]) || $v > $hari[$k]['z']) $hari[$k] = ['tgl' => $k, 'z' => $v, 'ts' => $ts];
        }
        usort($hari, fn($a, $b) => $b['z'] <=> $a['z']);
        return array_slice(array_values($hari), 0, $n);
    }, pasut_semua_stamp() . "|$n");
}
/* Ringkasan maks/min harian seluruh periode (grafik lima tahun). */
function pasut_seri_harian(): array {
    return pasut_cache('harian', function () {
        $m = pasut_semua(); if (!$m) return [];
        $g = [];
        foreach ($m['z'] as $i => $v) {
            if ($v === null) continue;
            $ts = $m['t0'] + $i * 3600; $k = date('Y-m-d', $ts);
            if (!isset($g[$k])) $g[$k] = ['tgl' => $k, 'ts' => (int) strtotime($k . ' 12:00'), 'maks' => $v, 'min' => $v, 'sum' => $v, 'n' => 1];
            else { $g[$k]['maks'] = max($g[$k]['maks'], $v); $g[$k]['min'] = min($g[$k]['min'], $v); $g[$k]['sum'] += $v; $g[$k]['n']++; }
        }
        ksort($g);
        return array_values(array_map(fn($r) => ['tgl' => $r['tgl'], 'ts' => $r['ts'], 'maks' => $r['maks'], 'min' => $r['min'], 'rata' => $r['sum'] / $r['n']], $g));
    }, pasut_semua_stamp());
}

/* ---------------------------------------------------------------- penyelarasan parameter */
/*
 * Menyelaraskan parameter "Pasang surut" pada model skoring dengan data BIG yang nyata.
 *
 * Mengapa ini perlu. Kelas skor bawaan prototipe (<0,8 m skor 1; 0,8-1,2 m skor 2;
 * >1,2 m skor 3) disusun untuk data SIMULASI yang datumnya berbeda, dengan puncak
 * sekitar 1,3 m. Pada data BIG yang relatif MSL, muka air tertinggi dalam lima tahun
 * hanya sekitar 0,5 m, sehingga seluruh wilayah akan selalu memperoleh skor 1 dan
 * parameter ini menjadi tidak berarti. Batas kelas karena itu diturunkan dari
 * PERSENTIL data BIG itu sendiri (P90 dan P98), bukan dari angka pilihan.
 *
 * Nilai per wilayah disetel SAMA untuk seluruh kampung, memakai HHWL hasil analisis
 * gabungan. Itu memang yang benar: prediksi BIG berasal dari satu titik dan pasang
 * surut praktis tidak berbeda antar kampung pada pulau seluas 6,8 km2. Yang
 * membedakan kerawanan antar kampung adalah elevasi, kemiringan, jarak ke pantai,
 * mangrove, dan perubahan garis pantai, bukan pasang surutnya.
 */
function pasut_selaraskan_parameter(?int $uid = null): array {
    $p = param_by_name('/pasang\s*surut/i');
    $am = pasut_ambang();
    $g = pasut_gabungan();
    if (!$p || !$am || !$g) return ['kelas' => 0, 'nilai' => 0, 'batas' => []];
    $idP = (int) $p['id_parameter'];
    $b1 = round($am['tingkat']['normal']['batas'], 3);     // P90
    $b2 = round($am['tingkat']['waspada']['batas'], 3);    // P98

    /* ganti kelas skor parameter ini */
    foreach (kelas_of($idP) as $k) del('kelas_skor', (int) $k['id_kelas']);
    ins('kelas_skor', ['id_parameter' => $idP, 'batas_bawah' => -999, 'batas_atas' => $b1, 'skor' => 1]);
    ins('kelas_skor', ['id_parameter' => $idP, 'batas_bawah' => $b1, 'batas_atas' => $b2, 'skor' => 2]);
    ins('kelas_skor', ['id_parameter' => $idP, 'batas_bawah' => $b2, 'batas_atas' => INF_BATAS, 'skor' => 3]);
    tbl_flush('kelas_skor');

    /* nilai sama untuk seluruh wilayah: HHWL hasil analisis gabungan */
    $nilai = round((float) $g['stat']['HHWL_formula'], 3);
    $idS = sumber_pasut_big();
    $n = 0;
    foreach (tbl('wilayah') as $w) {
        $lama = latest_data((int) $w['id_wilayah'])[$idP] ?? null;
        $baris = ['id_wilayah' => (int) $w['id_wilayah'], 'id_parameter' => $idP, 'id_sumber' => $idS,
                  'id_pengguna' => $uid, 'nilai' => $nilai, 'skor' => score_for($idP, $nilai),
                  'tanggal_data' => date('Y-m-d')];
        if ($lama) upd('data_parameter', (int) $lama['id_data'], $baris); else ins('data_parameter', $baris);
        $n++;
    }
    tbl_flush('data_parameter');
    return ['kelas' => 3, 'nilai' => $n, 'batas' => [$b1, $b2], 'hhwl' => $nilai];
}

/* ---------------------------------------------------------------- bantu tampilan */
function pasut_tipe_tag(?array $t, bool $lg = false): string {
    if (!$t) return '<span class="tag plain">Belum dapat ditentukan</span>';
    return '<span class="tag ' . ($lg ? 'lg' : '') . '" style="--c:' . e($t['warna']) . '">' . ic('waves', $lg ? 20 : 16) . e($t['nama']) . '</span>';
}
function pasut_sumber_note(): string {
    $m = pasut_rentang_data();
    return 'Sumber: <b>' . e(PASUT_SUMBER) . '</b>, titik ' . nf(PASUT_LAT, 6) . '; ' . nf(PASUT_LON, 6)
        . '. Datum: tinggi muka air relatif <b>MSL</b> setempat. Resolusi 1 jam, waktu berkas UTC dan ditampilkan dalam WIB.'
        . ($m ? ' Periode data ' . fmt_d($m[0]) . ' sampai ' . fmt_d($m[1]) . '.' : '');
}
/* Daftar tahun yang punya analisis siap (dipakai pemilih tahun). */
function pasut_tahun_analisis(): array {
    $o = [];
    foreach (pasut_tahun_tersedia() as $y) if (pasut_baca($y)) $o[] = $y;
    return $o;
}
