<?php
declare(strict_types=1);
/*
 * BASIS DATA SEA WARNING: seluruh logika basis data ada di satu berkas ini.
 * Bagian yang sering diubah: fungsi seed_users() dan seed_database() (data awal),
 * serta jalankan_migrasi() dan konstanta SURVEI_KAMPUNG (pemutakhiran data).
 * Struktur tabel ada di includes/data.json (sumber kebenaran skema).
 */

/* ==================== SKEMA: definisi tabel (dibaca dari includes/data.json), relasi, dan pembuat DDL ==================== */
/* Skema tabel sesuai ERD (satu sumber kebenaran: includes/data.json), relasi, dan pembuat DDL. */

function D(): array { static $d = null; if ($d === null) $d = json_decode((string) file_get_contents(__DIR__ . '/data.json'), true); return $d; }
function schema(): array { return D()['SCHEMA']; }
function tdef(string $t): array { return D()['SCHEMA'][$t]; }
function pk(string $t): string { return tdef($t)['pk']; }
function fdefs(string $t): array { return tdef($t)['fields']; }
function fdef(string $t, string $k): ?array { foreach (fdefs($t) as $f) if ($f['k'] === $k) return $f; return null; }
function rels(): array { return D()['REL']; }
const LABEL_FIELD = ['pengguna' => 'nama', 'wilayah' => 'nama_wilayah', 'sumber_data' => 'nama_sumber', 'parameter' => 'nama_parameter', 'tingkat_kerawanan' => 'nama_tingkat', 'titik_evakuasi' => 'nama_titik'];
function label_of(string $t, $id): string { $r = row($t, (int) $id); return $r ? (string) ($r[LABEL_FIELD[$t] ?? pk($t)] ?? '#' . $id) : '–'; }

const ROLE_ORDER = ['admin', 'mahasiswa', 'perangkat_desa', 'warga'];
function roles(): array { return D()['ROLES']; }
function role_options(): array { $o = []; foreach (ROLE_ORDER as $r) $o[$r] = roles()[$r]['label']; return $o; }
function status_meta(): array { return D()['STATUS']; }

/* ---------- DDL ---------- */
function coltype(array $f, string $driver): string {
    $db = strtolower($f['db']);
    if ($driver === 'sqlite') return match (true) { $db === 'int' || $db === 'boolean' => 'INTEGER', $db === 'float' => 'REAL', default => 'TEXT' };
    if ($db === 'int') return 'INT'; if ($db === 'float') return 'DOUBLE'; if ($db === 'boolean') return 'TINYINT(1)';
    if ($db === 'date') return 'DATE'; if ($db === 'datetime') return 'DATETIME';
    if ($db === 'text') return ($f['t'] ?? '') === 'geojson' ? 'MEDIUMTEXT' : 'TEXT';
    return strtoupper($db); // varchar(n)
}
function ddl(string $driver): array {
    $out = []; $fks = [];
    foreach (rels() as [$parent, $child, $col]) $fks[$child][] = [$col, $parent];
    foreach (schema() as $t => $T) {
        $cols = []; $q = $driver === 'sqlite' ? '"' : '`';
        foreach ($T['fields'] as $f) {
            $k = $f['k'];
            if ($f['t'] === 'pk') { $cols[] = $driver === 'sqlite' ? "$q$k$q INTEGER PRIMARY KEY AUTOINCREMENT" : "$q$k$q INT NOT NULL AUTO_INCREMENT"; continue; }
            $c = "$q$k$q " . coltype($f, $driver) . (!empty($f['req']) ? ' NOT NULL' : ' NULL');
            if ($k === 'aktif') $c = "$q$k$q " . coltype($f, $driver) . ' NOT NULL DEFAULT 1';
            if ($k === 'username') $c .= $driver === 'sqlite' ? ' UNIQUE' : '';
            $cols[] = $c;
        }
        if ($driver === 'mysql') { $cols[] = 'PRIMARY KEY (`' . $T['pk'] . '`)'; if ($t === 'pengguna') $cols[] = 'UNIQUE KEY `uq_pengguna_username` (`username`)'; }
        foreach ($fks[$t] ?? [] as [$col, $parent]) {
            $cols[] = $driver === 'sqlite' ? "FOREIGN KEY ($q$col$q) REFERENCES $q$parent$q($q" . pk($parent) . "$q)"
                : "CONSTRAINT `fk_{$t}_{$col}` FOREIGN KEY (`$col`) REFERENCES `$parent`(`" . pk($parent) . '`) ON UPDATE CASCADE ON DELETE RESTRICT';
        }
        $out[$t] = "CREATE TABLE IF NOT EXISTS $q$t$q (\n  " . implode(",\n  ", $cols) . "\n)" . ($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '');
    }
    return $out;
}

/* ==================== KONEKSI DAN CRUD DASAR (PDO) ==================== */
/* Lapisan basis data (PDO). Semua nilai memakai prepared statement; nama tabel/kolom hanya dari skema. */

function db(): PDO {
    static $pdo = null; if ($pdo) return $pdo;
    $c = cfg('db');
    if (($c['driver'] ?? 'mysql') === 'sqlite') {
        $pdo = new PDO('sqlite:' . $c['sqlite']);
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $pdo = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4", $c['user'], $c['pass']);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}
function db_driver(): string { return (string) (cfg('db')['driver'] ?? 'mysql'); }
function dbq(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function tables_ready(): bool { try { dbq('SELECT 1 FROM pengguna LIMIT 1'); return true; } catch (Throwable $e) { return false; } }

/* Normalisasi tipe sesuai skema (angka menjadi int/float, boolean menjadi bool) */
function norm_row(string $t, array $r): array {
    foreach (fdefs($t) as $f) {
        $k = $f['k']; if (!array_key_exists($k, $r) || $r[$k] === null) continue;
        $db = strtolower($f['db']);
        if ($db === 'int' || $f['t'] === 'pk') $r[$k] = (int) $r[$k];
        elseif ($db === 'float') $r[$k] = (float) $r[$k];
        elseif ($db === 'boolean') $r[$k] = (bool) $r[$k];
    }
    return $r;
}
const WARNA_LAMA = ['#4C7F5B' => '#2E9E6B', '#C28A1E' => '#E6A100', '#B04632' => '#D64933']; // warna redam (palet Pasir/Mangrove) -> warna bawaan; warna buatan admin tidak diubah
function tbl(string $t): array { // seluruh baris tabel, di-cache selama satu permintaan
    static $cache = []; $GLOBALS['__tbl_cache'] = &$cache;
    if (!isset($cache[$t])) { $cache[$t] = array_map(fn($r) => norm_row($t, $r), dbq("SELECT * FROM `$t` ORDER BY `" . pk($t) . '`')->fetchAll()); if ($t === 'tingkat_kerawanan') foreach ($cache[$t] as &$r) $r['warna'] = WARNA_LAMA[strtoupper((string) $r['warna'])] ?? $r['warna']; unset($r); }
    return $cache[$t];
}
function tbl_flush(?string $t = null): void { if (isset($GLOBALS['__tbl_cache'])) { if ($t) unset($GLOBALS['__tbl_cache'][$t]); else $GLOBALS['__tbl_cache'] = []; } }
function row(string $t, int $id): ?array { foreach (tbl($t) as $r) if ($r[pk($t)] === $id) return $r; return null; }
function where(string $t, callable $f): array { return array_values(array_filter(tbl($t), $f)); }

function ins(string $t, array $r): int {
    $pk = pk($t); unset($r[$pk]); $r = prep_values($t, $r);
    $cols = array_keys($r); $sql = "INSERT INTO `$t` (" . implode(',', array_map(fn($c) => "`$c`", $cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
    dbq($sql, array_values($r)); tbl_flush($t); return (int) db()->lastInsertId();
}
function upd(string $t, int $id, array $r): void {
    $pk = pk($t); unset($r[$pk]); if (!$r) return; $r = prep_values($t, $r);
    $sql = "UPDATE `$t` SET " . implode(',', array_map(fn($c) => "`$c` = ?", array_keys($r))) . " WHERE `$pk` = ?";
    dbq($sql, [...array_values($r), $id]); tbl_flush($t);
}
function prep_values(string $t, array $r): array { // boolean -> 0/1, string kosong pada kolom non-teks -> NULL
    foreach ($r as $k => $v) { $f = fdef($t, $k); if (!$f) continue; if (is_bool($v)) $r[$k] = $v ? 1 : 0; elseif ($v === '' && !in_array($f['t'], ['text', 'longtext', 'geojson', 'pass'], true) && in_array(strtolower($f['db']), ['int', 'float', 'date', 'datetime'], true)) $r[$k] = null; }
    return $r;
}
function refs(string $t, int $id): array { // relasi yang masih memakai baris ini
    $out = [];
    foreach (rels() as [$parent, $child, $col]) if ($parent === $t) { $n = (int) dbq("SELECT COUNT(*) FROM `$child` WHERE `$col` = ?", [$id])->fetchColumn(); if ($n) $out[] = ['table' => $child, 'n' => $n]; }
    return $out;
}
function refs_msg(array $refs): string { return 'Tidak dapat dihapus karena masih dipakai oleh ' . implode(', ', array_map(fn($x) => mb_strtolower(tdef($x['table'])['title']) . ' (' . $x['n'] . ')', $refs)) . '.'; }
function del(string $t, int $id): array {
    $refs = refs($t, $id); if ($refs) return [false, refs_msg($refs)];
    dbq('DELETE FROM `' . $t . '` WHERE `' . pk($t) . '` = ?', [$id]); tbl_flush($t); return [true, ''];
}
function ensure_sumber(string $nama, string $jenis, string $url): int {
    foreach (tbl('sumber_data') as $s) if ($s['nama_sumber'] === $nama) return $s['id_sumber'];
    return ins('sumber_data', ['nama_sumber' => $nama, 'jenis' => $jenis, 'url' => $url, 'tanggal_akses' => date('Y-m-d')]);
}

/* ==================== DATA AWAL: pengguna dan data contoh (dijalankan oleh install.php) ==================== */
/* Data awal. Seluruh nilai parameter, riwayat rob, dan data laut adalah CONTOH untuk pengembangan, bukan data resmi. */

function seed_users(): void {
    $U = [['Administrator Sistem', 'admin', 'upiserang2026', 'admin'], ['Mahasiswa Peneliti', 'mahasiswa', 'mahasiswa123', 'mahasiswa'], ['Perangkat Desa Pulo Panjang', 'perangkat', 'perangkat123', 'perangkat_desa'],
        ['Warga Pulo Panjang', 'warga', 'warga123', 'warga'], ['Perangkat Kampung (contoh)', 'kadus2', 'kadus123', 'perangkat_desa'], ['Siti Rahmawati', 'siti', 'warga123', 'warga'], ['Mahasiswa Magang (nonaktif)', 'magang', 'magang123', 'mahasiswa']];
    foreach ($U as [$nama, $user, $pw, $peran]) ins('pengguna', ['nama' => $nama, 'username' => $user, 'kata_sandi_hash' => password_hash($pw, PASSWORD_DEFAULT), 'peran' => $peran, 'aktif' => $user !== 'magang']);
}
function seed_database(): void {
    $D = D(); $now = (int) (floor(time() / 3600) * 3600); $pdo = db(); $pdo->beginTransaction();
    try {
        seed_users();
        foreach ($D['SUMBER'] as $s) ins('sumber_data', ['nama_sumber' => $s[0], 'jenis' => $s[1], 'url' => $s[2], 'tanggal_akses' => $s[3]]);
        foreach ($D['PARAM'] as $p) ins('parameter', ['kelompok' => $p[0], 'nama_parameter' => $p[1], 'satuan' => $p[2], 'bobot' => $p[3]]);
        foreach ($D['TINGKAT'] as $x) ins('tingkat_kerawanan', ['nama_tingkat' => $x[0], 'skor_min' => $x[1], 'skor_maks' => $x[2], 'makna_warga' => $x[3], 'warna' => $x[4]]);
        foreach ($D['PARAM'] as $i => $p) foreach ($p[4] as $k) ins('kelas_skor', ['id_parameter' => $i + 1, 'batas_bawah' => $k[0], 'batas_atas' => $k[1], 'skor' => $k[2]]);
        foreach ($D['WIL'] as $i => $w) ins('wilayah', ['nama_wilayah' => $w[0], 'tipe' => $w[1], 'geometri_geojson' => json_encode($D['GEOM'][(string) ($i + 1)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'jumlah_kk' => $w[2]]);
        foreach ($D['NILAI'] as $wi => $row) foreach ($row as $pi => $nilai) ins('data_parameter', ['id_wilayah' => $wi + 1, 'id_parameter' => $pi + 1, 'id_sumber' => $D['SRC_BY_PARAM'][$pi], 'id_pengguna' => 1, 'nilai' => $nilai, 'skor' => score_for($pi + 1, (float) $nilai), 'tanggal_data' => '2026-09-28']);
        /* Pasang surut pada data oseanografi memakai PREDIKSI BIG/SRGI bila berkasnya tersedia;
           gelombang, angin, dan arus masih simulasi dan ditandai demikian di antarmuka. */
        $idPasut = pasut_ada() ? sumber_pasut_big() : null;
        foreach (gen_osea($now, -24, 48, 2, $idPasut) as $r) ins('data_oseanografi', $r);
        foreach ($D['RIWAYAT'] as $r) ins('riwayat_rob', ['id_wilayah' => $r[1], 'id_sumber' => 4, 'tanggal' => $r[0], 'tinggi_genangan_cm' => $r[2], 'durasi_jam' => $r[3], 'penyebab' => $r[4]]);
        foreach ($D['TINDAKAN'] as [$tk, $list]) foreach ($list as $i => $s) ins('tindakan', ['id_tingkat' => $tk, 'urutan' => $i + 1, 'isi_tindakan' => $s]);
        $h = fn(int $n) => db_dt($now + $n * 3600);
        ins('peringatan', ['id_pengguna' => 1, 'id_sumber' => 2, 'status' => 'Waspada', 'judul' => 'Potensi rob saat pasang tinggi', 'isi' => 'Pasang tertinggi pada periode ini berpotensi menggenangi wilayah pesisir berelevasi rendah. Warga di wilayah dengan kerawanan sedang dan tinggi diimbau meningkatkan kewaspadaan, mengamankan barang di tempat rendah, dan mengikuti informasi dari pihak berwenang.', 'mulai' => $h(-24 * 6 - 3), 'berakhir' => $h(-24 * 4)]); // v12: contoh arsip; status kini otomatis
        ins('peringatan', ['id_pengguna' => 1, 'id_sumber' => 4, 'status' => 'Normal', 'judul' => 'Kondisi normal', 'isi' => 'Tidak ada potensi rob yang perlu diwaspadai. Tetap pantau informasi dari pihak berwenang.', 'mulai' => $h(-24 * 14), 'berakhir' => $h(-4)]);
        ins('peringatan', ['id_pengguna' => 1, 'id_sumber' => 4, 'status' => 'Siaga', 'judul' => 'Rob pasang purnama', 'isi' => 'Pasang purnama menyebabkan genangan di beberapa titik pesisir. Warga diminta mengamankan barang dan menghindari jalur dekat dermaga.', 'mulai' => $h(-24 * 45), 'berakhir' => $h(-24 * 43)]);
        /* Selaraskan parameter Pasang surut dengan data BIG (kelas skor dari persentil data,
           nilai sama untuk seluruh kampung karena prediksi BIG berasal dari satu titik). */
        if (pasut_ada()) pasut_selaraskan_parameter(1);
        /* Titik dan jalur evakuasi dari hasil hitungan geometri (data/evakuasi.json). */
        evak_seed(false);
        foreach (tbl('wilayah') as $w) { $c = compute_skor($w['id_wilayah']); if ($c) ins('hasil_kerawanan', ['id_wilayah' => $w['id_wilayah'], 'id_tingkat' => $c['tingkat']['id_tingkat'], 'id_pengguna' => 1, 'skor_total' => $c['skor'], 'tanggal_analisis' => date('Y-m-d', $now - 3 * 86400), 'metode' => 'Skoring berbobot (weighted overlay), pasang surut dari prediksi BIG/SRGI']); }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function create_schema(bool $drop = false): void {
    $pdo = db(); $drv = db_driver();
    if ($drop) { if ($drv === 'mysql') $pdo->exec('SET FOREIGN_KEY_CHECKS=0'); else $pdo->exec('PRAGMA foreign_keys=OFF'); foreach (array_reverse(array_keys(schema())) as $t) $pdo->exec("DROP TABLE IF EXISTS `$t`"); if ($drv === 'mysql') $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); else $pdo->exec('PRAGMA foreign_keys=ON'); }
    foreach (ddl($drv) as $sql) $pdo->exec($sql);
    tbl_flush();
}

/* ==================== PEMUTAKHIRAN DATA: dijalankan otomatis per permintaan, aman diulang ==================== */
/* Pemutakhiran otomatis basis data yang sudah terpasang. Dipanggil sekali per permintaan dari index.php;
 * setiap langkah memeriksa kondisinya sendiri sehingga aman dijalankan berulang.
 *   1. Ganti nama Kampung Ragas menjadi Kampung Penengahan.
 *   2. Posisi baku: Penengahan di tengah (5,93167 LS), Pasir Putih di bawahnya (5,93350 LS).
 *   3. Nilai survei lapangan (diverifikasi RT), sekali saja: Penengahan rendah, Sukarela sedang, Pasir Putih tinggi. */

function jalankan_migrasi(): void {
    ganti_sandi_admin_sekali();
    pastikan_tabel_laporan();
    pastikan_kolom_google();
    ganti_nama_kampung_lama();
    posisi_kampung_kanonik();
    terapkan_survei_sekali();
}

/* Kampung Ragas berganti nama menjadi Kampung Penengahan. Basis data yang sudah terpasang diperbarui otomatis
 * pada permintaan pertama: nama wilayah, nama di GeoJSON, serta nama dan keterangan titik evakuasi.
 * Idempoten: bila tidak ada lagi teks "Ragas", tidak ada yang diubah. Juga tersedia lewat `php tools/ganti_nama_kampung.php`. */
function ganti_nama_kampung_lama(): int {
    $lama = 'Ragas'; $baru = 'Penengahan'; $n = 0;
    try {
        foreach (tbl('wilayah') as $w) {
            $g = (string) ($w['geometri_geojson'] ?? '');
            if (mb_stripos((string) $w['nama_wilayah'], $lama) === false && mb_strpos($g, $lama) === false) continue;
            upd('wilayah', (int) $w['id_wilayah'], ['nama_wilayah' => str_replace($lama, $baru, (string) $w['nama_wilayah']), 'geometri_geojson' => str_replace($lama, $baru, $g)]); $n++;
        }
        foreach (tbl('titik_evakuasi') as $t) {
            $v = []; foreach (['nama_titik', 'keterangan'] as $k) if (isset($t[$k]) && mb_strpos((string) $t[$k], $lama) !== false) $v[$k] = str_replace($lama, $baru, (string) $t[$k]);
            if ($v) { upd('titik_evakuasi', (int) $t[pk('titik_evakuasi')], $v); $n++; }
        }
    } catch (Throwable $e) { return 0; }   // tabel evakuasi belum ada pada basis data sangat lama: abaikan
    if ($n) tbl_flush();
    return $n;
}

/* Kata sandi Administrator untuk basis data yang sudah terpasang: diganti dari admin123 menjadi upiserang2026.
 * Hanya dilakukan sekali (ditandai storage/sandi_admin.done) dan hanya bila kata sandi lama masih admin123. */
function ganti_sandi_admin_sekali(): void {
    $flag = storage_dir() . '/sandi_admin.done';
    if (is_file($flag)) return;
    try {
        foreach (tbl('pengguna') as $r) {
            if (strtolower((string) $r['username']) !== 'admin') continue;
            if (password_verify('admin123', (string) $r['kata_sandi_hash'])) upd('pengguna', (int) $r['id_pengguna'], ['kata_sandi_hash' => password_hash('upiserang2026', PASSWORD_DEFAULT)]);
        }
        file_put_contents($flag, date('c'));
    } catch (Throwable $e) { /* tabel belum ada: dicoba lagi pada permintaan berikutnya */ }
}

/* Posisi baku: Kampung Penengahan di tengah-atas (titik 5,93167 LS; 106,15306 BT) dan Kampung Pasir Putih di
 * kiri-bawahnya (titik 5,93350 LS; 106,15280 BT), sama dengan data pasang baru (includes/data.json).
 * Basis data lama yang masih memakai posisi kebalikannya ditukar otomatis SEKALI pada permintaan pertama:
 * nama dan deskripsi kedua kampung ditukar (poligon, titik, dan jalur tetap di tempatnya),
 * lalu data yang melekat pada nama ikut diselaraskan: jumlah KK (data/penduduk.json), nilai parameter hasil survei
 * lapangan (sama dengan tools/terapkan_survei.php), dan hasil kerawanan dihitung ulang.
 * Idempoten: bila Penengahan sudah berada di utara Pasir Putih, tidak ada yang diubah. */
const SURVEI_KAMPUNG = [
    'Kampung Penengahan'  => ['Elevasi/DEM' => 3.2, 'Kemiringan lereng' => 5, 'Jarak dari garis pantai' => 420, 'Gelombang' => 0.3, 'Arus' => 0.1, 'Angin' => 8, 'Mangrove' => 70, 'Perubahan garis pantai' => 0.2],
    'Kampung Pasir Putih' => ['Elevasi/DEM' => 0.7, 'Kemiringan lereng' => 1, 'Jarak dari garis pantai' => 60, 'Gelombang' => 0.9, 'Arus' => 0.3, 'Angin' => 14, 'Mangrove' => 20, 'Perubahan garis pantai' => -1.4],
    'Kampung Sukarela'    => ['Elevasi/DEM' => 1.2, 'Kemiringan lereng' => 2, 'Jarak dari garis pantai' => 120, 'Gelombang' => 0.5, 'Arus' => 0.2, 'Angin' => 12, 'Mangrove' => 60, 'Perubahan garis pantai' => -0.2],
];
/* Survei lapangan (diverifikasi RT): Penengahan = Rendah, Sukarela = Sedang, Pasir Putih = Tinggi.
 * Basis data lama yang belum memakai nilai survei diselaraskan SEKALI (penanda storage/survei_v5.done),
 * lalu hasil kerawanan seluruh kampung dihitung ulang. Setelah itu nilai bebas diubah lewat menu Data Parameter. */
function terapkan_survei_sekali(): int {
    $flag = storage_dir() . '/survei_v5.done'; if (is_file($flag)) return 0;
    $pdo = db(); $pdo->beginTransaction(); $n = 0;
    try {
        $par = []; foreach (tbl('parameter') as $p) $par[$p['nama_parameter']] = (int) $p['id_parameter'];
        $uid = (int) (tbl('pengguna')[0]['id_pengguna'] ?? 1);
        foreach (tbl('wilayah') as $w) {
            if (!isset(SURVEI_KAMPUNG[$w['nama_wilayah']])) continue;
            foreach (SURVEI_KAMPUNG[$w['nama_wilayah']] as $nm => $nilai) {
                if (!isset($par[$nm])) continue;
                foreach (where('data_parameter', fn($r) => (int) $r['id_wilayah'] === (int) $w['id_wilayah'] && (int) $r['id_parameter'] === $par[$nm]) as $r)
                    if ((float) $r['nilai'] !== (float) $nilai) { upd('data_parameter', (int) $r['id_data'], ['nilai' => $nilai, 'skor' => score_for($par[$nm], (float) $nilai)]); $n++; }
            }
        }
        tbl_flush();
        foreach (tbl('wilayah') as $w) if ($c = compute_skor((int) $w['id_wilayah']))
            ins('hasil_kerawanan', ['id_wilayah' => $w['id_wilayah'], 'id_tingkat' => $c['tingkat']['id_tingkat'], 'id_pengguna' => $uid, 'skor_total' => $c['skor'], 'tanggal_analisis' => date('Y-m-d'), 'metode' => 'Skoring berbobot (weighted overlay), nilai survei lapangan diselaraskan']);
        $pdo->commit(); tbl_flush(); @file_put_contents($flag, date('c'));
        return $n;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); tbl_flush(); return 0; }
}
function posisi_kampung_kanonik(): int {
    $A = 'Kampung Penengahan'; $B = 'Kampung Pasir Putih';
    try {
        $wa = $wb = null; foreach (tbl('wilayah') as $w) { if ($w['nama_wilayah'] === $A) $wa = $w; elseif ($w['nama_wilayah'] === $B) $wb = $w; }
        if (!$wa || !$wb) return 0;
        $ta = wil_titik($wa); $tb = wil_titik($wb);
        if (!$ta || !$tb || $ta[0] >= $tb[0]) return 0;   // sudah benar: Penengahan di utara Pasir Putih
    } catch (Throwable $e) { return 0; }
    $pdo = db(); $pdo->beginTransaction();
    try {
        $tukar = fn(string $s, string $x, string $y) => str_replace("\0", $y, str_replace($y, $x, str_replace($x, "\0", $s)));
        $ga = json_decode((string) $wa['geometri_geojson'], true); $gb = json_decode((string) $wb['geometri_geojson'], true);
        foreach ([[$wa, $ga, $gb, $B], [$wb, $gb, $ga, $A]] as [$w, $g, $lain, $nama]) {
            if (is_array($g)) { $g['properties']['nama'] = $nama; if (isset($lain['properties']['deskripsi'])) $g['properties']['deskripsi'] = $lain['properties']['deskripsi']; }
            upd('wilayah', (int) $w['id_wilayah'], ['nama_wilayah' => $nama, 'geometri_geojson' => is_array($g) ? json_encode($g, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $w['geometri_geojson']]);
        }
        try { foreach (tbl('titik_evakuasi') as $t) { $v = []; foreach (['nama_titik', 'keterangan'] as $k) if (isset($t[$k])) { $n = $tukar((string) $t[$k], 'Penengahan', 'Pasir Putih'); if ($n !== (string) $t[$k]) $v[$k] = $n; } if ($v) upd('titik_evakuasi', (int) $t[pk('titik_evakuasi')], $v); } } catch (Throwable $e) {}
        tbl_flush();
        $par = []; foreach (tbl('parameter') as $p) $par[$p['nama_parameter']] = (int) $p['id_parameter'];
        $uid = (int) (tbl('pengguna')[0]['id_pengguna'] ?? 1);
        foreach (tbl('wilayah') as $w) {
            if (!isset(SURVEI_KAMPUNG[$w['nama_wilayah']])) continue;
            if ($pd = penduduk_kampung((string) $w['nama_wilayah'])) upd('wilayah', (int) $w['id_wilayah'], ['jumlah_kk' => (int) $pd['kk']]);
            foreach (SURVEI_KAMPUNG[$w['nama_wilayah']] as $nm => $nilai) {
                if (!isset($par[$nm])) continue;
                foreach (where('data_parameter', fn($r) => (int) $r['id_wilayah'] === (int) $w['id_wilayah'] && (int) $r['id_parameter'] === $par[$nm]) as $r)
                    upd('data_parameter', (int) $r['id_data'], ['nilai' => $nilai, 'skor' => score_for($par[$nm], (float) $nilai)]);
            }
        }
        tbl_flush();
        foreach (tbl('wilayah') as $w) if (isset(SURVEI_KAMPUNG[$w['nama_wilayah']]) && ($c = compute_skor((int) $w['id_wilayah'])))
            ins('hasil_kerawanan', ['id_wilayah' => $w['id_wilayah'], 'id_tingkat' => $c['tingkat']['id_tingkat'], 'id_pengguna' => $uid, 'skor_total' => $c['skor'], 'tanggal_analisis' => date('Y-m-d'), 'metode' => 'Skoring berbobot (weighted overlay), posisi Penengahan dan Pasir Putih diselaraskan']);
        $pdo->commit(); tbl_flush(); return 2;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); tbl_flush(); return 0; }
}


/* v12: tabel laporan_warga untuk basis data yang sudah terpasang. Sekali saja (penanda storage/laporan_v12.done). */
/* v22: kolom email dan google_id pada tabel pengguna untuk masuk/daftar dengan Google. */
function pastikan_kolom_google(): void {
    $f = storage_dir() . '/google_v22.done'; if (is_file($f)) return;
    foreach (['email' => 'VARCHAR(150) NULL', 'google_id' => 'VARCHAR(64) NULL'] as $k => $tipe) {
        try { dbq("SELECT `$k` FROM pengguna LIMIT 1"); }
        catch (Throwable $e) { try { db()->exec("ALTER TABLE pengguna ADD COLUMN `$k` " . (db_driver() === 'sqlite' ? 'TEXT NULL' : $tipe)); } catch (Throwable $e2) { return; } }
    }
    @file_put_contents($f, date('c'));
}
function pastikan_tabel_laporan(): void {
    $f = storage_dir() . '/laporan_v12.done'; if (is_file($f)) return;
    try { dbq('SELECT 1 FROM laporan_warga LIMIT 1'); }
    catch (Throwable $e) { try { db()->exec(ddl(db_driver())['laporan_warga']); } catch (Throwable $e2) { return; } }
    @file_put_contents($f, date('c'));
}

