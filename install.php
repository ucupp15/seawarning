<?php
declare(strict_types=1);
/* Pemasang: membuat basis data (bila belum ada), seluruh tabel sesuai ERD, dan data contoh. Jalankan sekali, lalu hapus atau biarkan terkunci. */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/database.php';

$lock = storage_dir() . '/installed.lock'; $msg = ''; $err = ''; $done = false; $c = cfg('db'); $ready = tables_ready();
if (is_post() && !($ready && is_file($lock))) {
    csrf_check();
    try {
        if (($c['driver'] ?? 'mysql') === 'mysql') { $pdo = new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $c['name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); }
        create_schema(false); $n = (int) dbq('SELECT COUNT(*) FROM pengguna')->fetchColumn();
        if ($n === 0) { seed_database(); $msg = 'Basis data dibuat dan diisi data contoh.'; } else $msg = 'Tabel sudah ada dan berisi data; data tidak diubah.';
        file_put_contents($lock, date('c')); $done = true; $ready = true;
    } catch (Throwable $e) { $err = $e->getMessage(); }
}
echo html_head('Pemasangan | SEA WARNING'), '<body><main style="max-width:720px;margin:0 auto;padding:40px 20px"><div class="panel">';
echo '<div class="brand wordmark" style="margin-bottom:18px"><img class="logo" src="assets/media/logo.png" alt="" width="46" height="41" style="height:44px;width:auto"><div><b style="color:var(--ink)">SEA WARNING</b><span style="color:var(--ink-3)">Pemasangan aplikasi (PHP + MySQL)</span></div></div>';
if ($done || ($ready && is_file($lock))) {
    echo ($msg ? note_box(e($msg), '', 'checkc') : note_box('Aplikasi sudah terpasang.', '', 'checkc')), '<p class="muted" style="margin:16px 0">Untuk keamanan, <b>hapus berkas <span class="mono">install.php</span></b> dari server setelah selesai, atau biarkan terkunci (berkas <span class="mono">storage/installed.lock</span>).</p><a class="btn btn-primary" href="index.php">' . ic('home', 18) . 'Buka aplikasi</a>';
} else {
    echo '<h1 style="font-size:26px;margin-bottom:8px">Pasang basis data</h1><p class="muted" style="margin-bottom:16px">Langkah ini membuat basis data dan seluruh tabel sesuai ERD, lalu mengisi data contoh (7 kampung Pulo Panjang, parameter, dan akun demo).</p>';
    echo '<dl class="kv" style="margin-bottom:16px"><dt>Jenis basis data</dt><dd>' . e(($c['driver'] ?? 'mysql') === 'mysql' ? 'MySQL / MariaDB' : 'SQLite') . '</dd>' . (($c['driver'] ?? 'mysql') === 'mysql' ? '<dt>Server</dt><dd>' . e($c['host'] . ':' . $c['port']) . '</dd><dt>Nama basis data</dt><dd class="mono">' . e($c['name']) . '</dd><dt>Pengguna</dt><dd class="mono">' . e($c['user']) . '</dd>' : '<dt>Berkas</dt><dd class="mono">' . e($c['sqlite']) . '</dd>') . '</dl>';
    if ($err) echo note_box('Gagal: ' . e($err) . '<br>Periksa pengaturan di <span class="mono">config.php</span>, pastikan MySQL/Apache di XAMPP berjalan.', 'bad', 'octagon'), '<div style="height:12px"></div>';
    echo '<form method="post" action="install.php">' . csrf_field() . '<button class="btn btn-primary" type="submit">' . ic('db', 18) . 'Pasang sekarang</button></form>';
    echo '<p class="faint" style="font-size:13px;margin-top:16px">Pengaturan koneksi ada di <span class="mono">config.php</span>. Pada XAMPP bawaan (pengguna root tanpa kata sandi) tidak perlu diubah.</p>';
}
echo '</div></main></body></html>';
