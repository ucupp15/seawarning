<?php
declare(strict_types=1);
/* Memperbarui kolom jumlah_kk tiap wilayah dari data/penduduk.json (Sensus Registrasi Penduduk
 * Pemerintah Kabupaten Serang). Aman dijalankan berulang. Jumlah jiwa dibaca langsung dari berkas itu.
 * Pasang baru (install.php) sudah memakai angka ini. Cadangkan basis data dulu. */
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/evakuasi.php';
if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');
try {
    $n = 0; $skip = [];
    foreach (penduduk_data()['kampung'] ?? [] as $k) {
        $hit = where('wilayah', fn($w) => strcasecmp($w['nama_wilayah'], $k['kampung']) === 0);
        if (!$hit) { $skip[] = $k['kampung']; continue; }
        upd('wilayah', (int) $hit[0]['id_wilayah'], ['jumlah_kk' => (int) $k['kk']]); $n++;
        echo $k['kampung'], ': ', $k['kk'], " KK, ", $k['jiwa'], " jiwa\n";
    }
    tbl_flush();
    echo "Selesai: $n wilayah diperbarui. Total ", array_sum(array_column(tbl('wilayah'), 'jumlah_kk')), " KK.\n";
    if ($skip) echo 'Tidak ditemukan di tabel wilayah: ', implode(', ', $skip), "\n";
} catch (Throwable $e) { echo 'Gagal: ', $e->getMessage(), "\n"; }
