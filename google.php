<?php
declare(strict_types=1);
/* Alamat kembali (redirect URI) untuk masuk/daftar dengan Google. Daftarkan alamat berkas ini di Google Cloud Console. */
require __DIR__ . '/includes/bootstrap.php';
if (!tables_ready()) redirect('install.php');
jalankan_migrasi();
if (current_user()) redirect(url('beranda'));
if (!google_siap()) { flash('Masuk dengan Google belum diatur. Administrator perlu mengisi client_id dan client_secret di config.php.', 'warn'); redirect('index.php?p=login'); }

$code = (string) ($_GET['code'] ?? '');
if ($code === '') {
    if (isset($_GET['error'])) { flash('Masuk dengan Google dibatalkan.', 'warn'); redirect('index.php?p=login'); }
    redirect(google_url_masuk());
}
$st = (string) ($_GET['state'] ?? ''); $harap = (string) ($_SESSION['g_state'] ?? ''); unset($_SESSION['g_state']);
if ($harap === '' || !hash_equals($harap, $st)) { flash('Sesi masuk dengan Google kedaluwarsa. Silakan coba lagi.', 'warn'); redirect('index.php?p=login'); }
try {
    [$u, $baru] = google_akun(google_profil($code));
    if (!$u['aktif']) { flash('Akun ini dinonaktifkan. Hubungi administrator.', 'warn'); redirect('index.php?p=login'); }
    login_user($u, false);
    flash($baru ? 'Akun warga dibuat dengan Google. Selamat datang.' : 'Selamat datang kembali.'); redirect(url('beranda'));
} catch (Throwable $e) { flash('Gagal masuk dengan Google. ' . $e->getMessage(), 'warn'); redirect('index.php?p=login'); }
