<?php
declare(strict_types=1);
/* Menjalankan pemutakhiran nama dan posisi kampung secara manual (aplikasi juga menjalankannya otomatis):
 *     php tools/ganti_nama_kampung.php */
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/includes/bootstrap.php';
$n = ganti_nama_kampung_lama();
$p = posisi_kampung_kanonik();
echo $n ? "Nama: $n baris diganti dari Ragas menjadi Penengahan.\n" : "Nama: sudah Penengahan.\n";
echo $p ? "Posisi: Penengahan dan Pasir Putih diselaraskan.\n" : "Posisi: sudah benar.\n";
