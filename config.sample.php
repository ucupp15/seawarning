<?php
/*
 * Konfigurasi aplikasi SEA WARNING (PHP).
 * Salin berkas ini menjadi config.php lalu sesuaikan. Pada XAMPP bawaan, pengaturan di bawah sudah cocok
 * (pengguna root tanpa kata sandi). Untuk server sungguhan, buat pengguna MySQL khusus dan beri kata sandi.
 */
return [
    'db' => [
        'driver' => 'mysql',          // 'mysql' (XAMPP/MariaDB) atau 'sqlite' (tanpa server basis data)
        'host'   => '127.0.0.1',
        'port'   => 3306,
        'name'   => 'sea_warning',
        'user'   => 'root',
        'pass'   => '',
        'sqlite' => __DIR__ . '/storage/sea_warning.sqlite',
    ],
    'timezone'     => 'Asia/Jakarta',
    'session_name' => 'seawarning',
    // Tombol "Coba langsung sebagai" pada halaman login (masuk TANPA kata sandi, termasuk sebagai Administrator).
    // Bawaan v12: false. Isi true HANYA untuk presentasi/uji di komputer sendiri, jangan di server yang dapat diakses orang lain.
    'demo_mode'    => true,
    // Mode presentasi/lomba: akun demo tetap aktif tanpa peringatan merah di dashboard. Isi false (dan demo_mode false) untuk server nyata.
    'mode_presentasi' => true,
    // Daftar/masuk warga dengan Google. Buat OAuth Client ID (jenis Web application) di https://console.cloud.google.com/apis/credentials
    // lalu daftarkan Authorized redirect URI: alamat google.php, contoh http://localhost/sea_warning/google.php
    // redirect_uri boleh dikosongkan (dibuat otomatis). cainfo: lokasi cacert.pem bila muncul galat sertifikat SSL.
    'google' => [
        'client_id'     => '',
        'client_secret' => '',
        'redirect_uri'  => '',
        'cainfo'        => '',
    ],
    // Data BMKG (cuaca per desa). Kode adm4 desa Pulo Panjang: 36.04.08.2009
    // Nomor darurat yang tampil di halaman Panduan warga. Contoh: ['Ketua RT 01' => '0812-0000-0000', 'Kantor Desa' => '021-000000']. Kosongkan bila belum ada.
    'kontak_darurat' => [],
    'bmkg' => ['adm4' => '36.04.08.2009', 'prov' => 'banten'],
];
