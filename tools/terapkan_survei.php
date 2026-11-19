<?php
declare(strict_types=1);
/* Menyelaraskan nilai parameter contoh dengan hasil survei lapangan (diverifikasi RT):
 * Penengahan = Rendah (hijau); Pasir Putih, Peres, Sukadiri = Tinggi (merah); Sukarela = Sedang (kuning).
 * Hanya mengubah nilai kampung Penengahan, Pasir Putih dan Sukarela (parameter selain pasang surut, riwayat, permukiman),
 * lalu menghitung ulang hasil kerawanan. Aplikasi sudah menjalankannya otomatis sekali (terapkan_survei_sekali);
 * berkas ini untuk menerapkan ulang secara manual.
 * Pasang baru (install.php) sudah memakai nilai ini. Cadangkan basis data dulu. */
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/includes/bootstrap.php';
$cli = PHP_SAPI === 'cli'; if (!$cli) header('Content-Type: text/plain; charset=utf-8');
$V = SURVEI_KAMPUNG;   // includes/database.php
$pdo = db(); $pdo->beginTransaction();
try {
    $par = []; foreach (tbl('parameter') as $p) $par[$p['nama_parameter']] = (int) $p['id_parameter'];
    $n = 0;
    foreach (tbl('wilayah') as $w) {
        if (!isset($V[$w['nama_wilayah']])) continue;
        foreach ($V[$w['nama_wilayah']] as $nm => $nilai) {
            if (!isset($par[$nm])) continue;
            $rows = where('data_parameter', fn($r) => (int) $r['id_wilayah'] === (int) $w['id_wilayah'] && (int) $r['id_parameter'] === $par[$nm]);
            foreach ($rows as $r) { upd('data_parameter', (int) $r['id_data'], ['nilai' => $nilai, 'skor' => score_for($par[$nm], (float) $nilai)]); $n++; }
        }
    }
    tbl_flush();
    foreach (tbl('wilayah') as $w) { $c = compute_skor((int) $w['id_wilayah']); if ($c) { ins('hasil_kerawanan', ['id_wilayah' => $w['id_wilayah'], 'id_tingkat' => $c['tingkat']['id_tingkat'], 'id_pengguna' => 1, 'skor_total' => $c['skor'], 'tanggal_analisis' => date('Y-m-d'), 'metode' => 'Skoring berbobot (weighted overlay), nilai kampung diselaraskan dengan survei lapangan']); echo $w['nama_wilayah'], ': ', $c['skor'], ' ', $c['tingkat']['nama_tingkat'], "\n"; } }
    $pdo->commit(); tbl_flush(); echo "Selesai: $n nilai parameter diperbarui.\n";
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); echo 'Gagal: ', $e->getMessage(), "\n"; }
