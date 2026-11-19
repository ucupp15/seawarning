<?php
declare(strict_types=1);
/* Pintu masuk tunggal aplikasi: ?p=<halaman>. Semua hak akses diperiksa di sini, di server. */
require __DIR__ . '/includes/bootstrap.php';

if (!tables_ready()) redirect('install.php');
jalankan_migrasi();
$p = (string) ($_GET['p'] ?? 'beranda');
if (!preg_match('/^[a-z0-9-]+$/', $p)) $p = 'beranda';

/* ---- halaman tanpa login ---- */
if ($p === 'login') { require __DIR__ . '/pages/login.php'; exit; }
if ($p === 'skin' && is_post()) { csrf_check(); $s = (string) pv('skin', 'auto'); if (in_array($s, ['auto', 'light', 'dark', 'ocean', 'contrast'], true)) setcookie('skin', $s, ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax']); $b = (string) pv('back', 'index.php'); redirect(safe_back($b)); }
if ($p === 'logout' && is_post()) { csrf_check(); do_logout(); session_start(); flash('Anda sudah keluar.'); redirect('index.php?p=login'); }

/* ---- wajib login ---- */
$user = require_login(); $role = $user['peran'];
if ($p === 'foto') laporan_foto_kirim($user, (int) gv('id'), (bool) gv('k'));   // foto laporan warga, dengan pemeriksaan hak akses

/* ---- tindakan global (POST) ---- */
if ($p === 'panduan-note' && is_post()) { csrf_check(); if ((string) pv('aksi') === 'tutup') panduan_set((int) $user['id_pengguna'], true); redirect(safe_back((string) pv('back', url('beranda')), url('beranda'))); }
if ($p === 'set-my' && is_post()) { csrf_check(); $id = (int) pv('id'); if (row('wilayah', $id)) { setcookie('my_wil', (string) $id, ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax']); flash('Wilayah saya disimpan.'); } $b = (string) pv('back', 'index.php'); redirect(safe_back($b)); }
if ($p === 'bmkg' && is_post()) {
    csrf_check(); $aksi = (string) pv('aksi'); $b = (string) pv('back', url('beranda')); $b = safe_back($b, url('beranda'));
    if ($aksi === 'refresh') { if (time() - (bmkg_state()['tried'] ?? 0) < 20) flash('Data BMKG baru saja diperiksa. Coba lagi sebentar lagi.', 'warn'); else { $ok = bmkg_refresh(true); flash($ok ? 'Data BMKG diperbarui.' : 'Gagal mengambil data BMKG. ' . bmkg_state()['err'], $ok ? 'ok' : 'warn'); } }
    elseif ($user['peran'] !== 'admin') flash('Hanya administrator yang dapat mengubah sinkronisasi BMKG.', 'warn');
    elseif ($aksi === 'apply') { $r = bmkg_apply($user['id_pengguna']); flash($r ? 'Angin BMKG ' . nf($r['nilai'], 1) . ' knot diterapkan. ' . ($r['n'] ? $r['n'] . ' wilayah berubah skor atau tingkatnya.' : 'Tidak ada perubahan tingkat kerawanan.') : 'Belum ada data angin BMKG atau parameter Angin tidak ditemukan.', $r ? 'ok' : 'warn'); }
    elseif ($aksi === 'auto') { $s = bmkg_settings(); if (pv('auto')) { $s['auto_uid'] = $user['id_pengguna']; bmkg_save_settings($s); bmkg_apply($user['id_pengguna'], true); flash('Penerapan otomatis diaktifkan.'); } else { unset($s['auto_uid']); bmkg_save_settings($s); flash('Penerapan otomatis dimatikan.'); } }
    elseif ($aksi === 'adm4') { $v = trim((string) pv('adm4')); if (!preg_match('/^\d{2}\.\d{2}\.\d{2}\.\d{4}$/', $v)) flash('Format kode adm4 harus seperti 36.04.08.2009.', 'warn'); else { $s = bmkg_settings(); $s['adm4'] = $v; bmkg_save_settings($s); $st = bmkg_state(); $st['at'] = 0; $st['tried'] = 0; bmkg_save_state($st); flash('Kode wilayah disimpan.'); bmkg_refresh(true); } }
    redirect($b);
}

/* ---- halaman ---- */
if (!page_allowed($role, $p)) { flash('Halaman itu tidak tersedia untuk peran Anda.', 'warn'); redirect(url('beranda')); }
foreach (['beranda', 'umum', 'riset', 'admin', 'pasut', 'evakuasi', 'laporan', 'panduan', 'penduduk'] as $f) require_once __DIR__ . "/pages/$f.php";
$fn = 'pg_' . str_replace('-', '_', $p);
if (!function_exists($fn)) { http_response_code(404); exit('Halaman tidak ditemukan.'); }
if (is_post() && !$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) { flash('Kiriman terlalu besar untuk server (batas post_max_size PHP). Gunakan foto yang lebih kecil.', 'warn'); redirect(url($p)); }
if (is_post() && function_exists($fn . '_post')) { csrf_check(); ($fn . '_post')(); }
$page = $fn();
render_app($page['title'], $page['html'], $p, $page['head'] ?? '', $page['foot'] ?? '');
