<?php
declare(strict_types=1);
/* Autentikasi berbasis sesi server, hak akses per peran, dan menu. Hak akses DIPERIKSA DI SERVER pada setiap permintaan. */

const DEMO_PW = ['admin' => 'upiserang2026', 'mahasiswa' => 'mahasiswa123', 'perangkat' => 'perangkat123', 'warga' => 'warga123'];
const SESI_BIASA = 8 * 3600, SESI_INGAT = 30 * 86400;

function menu(string $role): array {
    $m = [
        'warga' => [['Informasi', [['beranda', 'Dashboard', 'home'], ['panduan', 'Panduan Penggunaan', 'book'], ['peta', 'Peta Zona Kerawanan', 'map'], ['cek-wilayah', 'Cek Wilayah', 'pin'], ['kondisi', 'Informasi Kondisi', 'waves'], ['penduduk', 'Data Penduduk', 'users']]], ['Kesiapsiagaan', [['lapor', 'Laporkan Genangan', 'camera'], ['peringatan', 'Peringatan Dini', 'bell'], ['tindakan', 'Apa yang Harus Dilakukan?', 'shield'], ['evakuasi', 'Titik Aman dan Evakuasi', 'flag'], ['pasut', 'Jadwal Pasang Surut', 'waves'], ['riwayat', 'Riwayat Banjir Rob', 'history']]], ['Lainnya', [['sumber', 'Sumber Data', 'book'], ['profil', 'Akun dan Tampilan', 'user']]]],
        'perangkat_desa' => [['Pemantauan', [['beranda', 'Dashboard', 'home'], ['peta', 'Peta Zona Kerawanan', 'map'], ['wilayah-rawan', 'Wilayah Rawan', 'flag'], ['laporan', 'Laporan Warga', 'camera'], ['kondisi', 'Informasi Kondisi', 'waves'], ['penduduk', 'Data Penduduk', 'users']]], ['Kesiapsiagaan', [['evakuasi', 'Titik Aman dan Evakuasi', 'flag'], ['pasut', 'Pasang Surut dan Prediksi', 'waves']]], ['Penyampaian informasi', [['peringatan', 'Peringatan Dini', 'bell'], ['tindakan', 'Kesiapsiagaan dan Mitigasi', 'shield'], ['cek-wilayah', 'Cek Wilayah', 'pin'], ['riwayat', 'Riwayat Banjir Rob', 'history']]], ['Lainnya', [['sumber', 'Sumber Data', 'book'], ['panduan', 'Panduan Penggunaan', 'book'], ['profil', 'Akun dan Tampilan', 'user']]]],
        'mahasiswa' => [['Ringkasan', [['beranda', 'Dashboard', 'home'], ['peta', 'Peta Zona Kerawanan', 'map'], ['peringatan', 'Peringatan Dini', 'bell']]], ['Data dan metode', [['parameter', 'Parameter dan Pembobotan', 'sliders'], ['matriks', 'Matriks Data', 'grid'], ['pasut', 'Pasang Surut BIG/SRGI', 'waves'], ['oseanografi', 'Data Oseanografi', 'droplet'], ['riwayat', 'Riwayat Banjir Rob', 'history'], ['laporan', 'Laporan Warga', 'camera'], ['penduduk', 'Data Penduduk', 'users']]], ['Evakuasi', [['evakuasi', 'Titik Aman dan Evakuasi', 'flag']]], ['Analisis', [['hasil', 'Hasil Kerawanan', 'target'], ['simulasi', 'Simulasi Skor', 'flask']]], ['Lainnya', [['sumber', 'Sumber Data', 'book'], ['panduan', 'Panduan Penggunaan', 'book'], ['profil', 'Akun dan Tampilan', 'user']]]],
        'admin' => [['Ringkasan', [['beranda', 'Dashboard', 'home'], ['peta', 'Peta Zona Kerawanan', 'map'], ['peringatan', 'Kelola Peringatan', 'bell'], ['laporan', 'Laporan Warga', 'camera'], ['analisis', 'Analisis Kerawanan', 'target']]], ['Data', [['wilayah', 'Wilayah', 'pin'], ['data-parameter', 'Data Parameter', 'db'], ['pasut', 'Pasang Surut BIG/SRGI', 'waves'], ['evakuasi', 'Titik Aman dan Evakuasi', 'flag'], ['oseanografi', 'Data Oseanografi', 'droplet'], ['riwayat', 'Riwayat Banjir Rob', 'history'], ['penduduk', 'Data Penduduk', 'users']]], ['Referensi', [['parameter', 'Parameter dan Kelas Skor', 'sliders'], ['tingkat', 'Tingkat dan Tindakan', 'layers'], ['sumber', 'Sumber Data', 'book']]], ['Sistem', [['pengguna', 'Pengguna', 'users'], ['erd', 'Skema Database', 'clip'], ['panduan', 'Panduan Penggunaan', 'book'], ['profil', 'Akun dan Tampilan', 'user']]]],
    ];
    return $m[$role] ?? [];
}
function menu_items(string $role): array { $o = []; foreach (menu($role) as [$g, $items]) foreach ($items as $i) $o[$i[0]] = $i; return $o; }
const BNAV = ['warga' => [['beranda', 'Beranda'], ['peta', 'Peta'], ['lapor', 'Lapor'], ['peringatan', 'Peringatan']], 'perangkat_desa' => [['beranda', 'Beranda'], ['peta', 'Peta'], ['laporan', 'Laporan'], ['peringatan', 'Peringatan']], 'mahasiswa' => [['beranda', 'Beranda'], ['peta', 'Peta'], ['hasil', 'Hasil'], ['matriks', 'Matriks']], 'admin' => [['beranda', 'Beranda'], ['peringatan', 'Peringatan'], ['wilayah', 'Wilayah'], ['analisis', 'Analisis']]];
const HALAMAN_TERSEMBUNYI = ['admin' => ['hasil']]; // dapat dibuka (mis. dari tautan Rincian di Analisis) walau tidak ada di menu
function page_allowed(string $role, string $id): bool { return isset(menu_items($role)[$id]) || in_array($id, HALAMAN_TERSEMBUNYI[$role] ?? [], true); }

function current_user(): ?array {
    static $u = false; if ($u !== false) return $u;
    $u = null; $id = $_SESSION['uid'] ?? null; if (!$id) return null;
    if (($_SESSION['exp'] ?? 0) < time()) { unset($_SESSION['uid'], $_SESSION['exp']); return null; }
    $r = row('pengguna', (int) $id); if ($r && $r['aktif']) $u = $r; else unset($_SESSION['uid']);
    return $u;
}
function user_role(): string { return current_user()['peran'] ?? ''; }
function require_login(): array { $u = current_user(); if (!$u) redirect('index.php?p=login'); return $u; }
function require_role(string ...$roles): array { $u = require_login(); if (!in_array($u['peran'], $roles, true)) { http_response_code(403); flash('Anda tidak berwenang membuka halaman itu.', 'warn'); redirect(url('beranda')); } return $u; }

function login_user(array $u, bool $remember): void {
    session_regenerate_id(true); $_SESSION['uid'] = $u['id_pengguna']; $_SESSION['exp'] = time() + ($remember ? SESI_INGAT : SESI_BIASA); unset($_SESSION['fails'], $_SESSION['lock_until']);
    if ($remember) setcookie(session_name(), session_id(), ['expires' => time() + SESI_INGAT, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
}
function do_login(string $username, string $pw, bool $remember): array {
    $lock = login_kunci_sisa($username); if ($lock > 0) return [false, 'Terlalu banyak percobaan. Coba lagi dalam ' . ($lock >= 120 ? ceil($lock / 60) . ' menit' : $lock . ' detik') . '.'];
    $found = null; foreach (tbl('pengguna') as $r) if (mb_strtolower($r['username']) === mb_strtolower(trim($username))) { $found = $r; break; }
    $hash = $found['kata_sandi_hash'] ?? password_hash('dummy', PASSWORD_DEFAULT); $ok = password_verify($pw, $hash) && $found;
    if (!$ok) { login_gagal_catat($username); usleep(400000); return [false, 'Nama pengguna atau kata sandi tidak cocok.']; }
    if (!$found['aktif']) return [false, 'Akun ini dinonaktifkan. Hubungi administrator.'];
    if (password_needs_rehash($found['kata_sandi_hash'], PASSWORD_DEFAULT)) upd('pengguna', $found['id_pengguna'], ['kata_sandi_hash' => password_hash($pw, PASSWORD_DEFAULT)]);
    login_gagal_hapus($username); login_user($found, $remember); return [true, ''];
}

/* ---------- pembatasan percobaan login (v12) ----------
 * Dicatat di SERVER (storage/login_gagal.json) per nama pengguna DAN per alamat IP, bukan di sesi, sehingga tidak
 * dapat dilewati dengan membuang cookie. Per nama pengguna: 5 kali gagal -> kunci 30 dtk, lalu berlipat dua tiap
 * 5 kegagalan berikutnya sampai 15 menit. Per IP: 20 kali gagal dalam 15 menit -> kunci 15 menit. */
const LOGIN_GAGAL_USER = 5, LOGIN_GAGAL_IP = 20, LOGIN_KUNCI_MAKS = 900;
function login_kunci_kunci(string $u): array { return ['u:' . sha1(mb_strtolower(trim($u))), 'i:' . (string) ($_SERVER['REMOTE_ADDR'] ?? '-')]; }
function login_catatan(?callable $ubah = null): array {
    $f = storage_dir() . '/login_gagal.json'; $fh = @fopen($f, 'c+'); if (!$fh) return [];
    flock($fh, $ubah ? LOCK_EX : LOCK_SH); $d = json_decode((string) stream_get_contents($fh), true); $d = is_array($d) ? $d : [];
    if ($ubah) { $now = time(); $d = array_filter($d, fn($x) => max((int) ($x['until'] ?? 0), (int) ($x['last'] ?? 0) + 86400) > $now); $d = $ubah($d); ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($d)); fflush($fh); }
    flock($fh, LOCK_UN); fclose($fh); return $d;
}
function login_kunci_sisa(string $u): int { $d = login_catatan(); $m = 0; foreach (login_kunci_kunci($u) as $k) $m = max($m, (int) ($d[$k]['until'] ?? 0) - time()); return $m; }
function login_gagal_catat(string $u): void {
    [$ku, $ki] = login_kunci_kunci($u);
    login_catatan(function (array $d) use ($ku, $ki) {
        $now = time();
        foreach ([$ku, $ki] as $k) { $x = $d[$k] ?? ['n' => 0, 'first' => $now]; if ($now - (int) $x['first'] > ($k === $ki ? 900 : 3600)) $x = ['n' => 0, 'first' => $now]; $x['n']++; $x['last'] = $now; $d[$k] = $x; }
        if ($d[$ku]['n'] % LOGIN_GAGAL_USER === 0) $d[$ku]['until'] = $now + min(LOGIN_KUNCI_MAKS, 30 * 2 ** (intdiv($d[$ku]['n'], LOGIN_GAGAL_USER) - 1));
        if ($d[$ki]['n'] >= LOGIN_GAGAL_IP) { $d[$ki]['until'] = $now + LOGIN_KUNCI_MAKS; $d[$ki]['n'] = 0; $d[$ki]['first'] = $now; }
        return $d;
    });
}
function login_gagal_hapus(string $u): void { [$ku] = login_kunci_kunci($u); login_catatan(function (array $d) use ($ku) { unset($d[$ku]); return $d; }); }
function do_logout(): void { $_SESSION = []; if (ini_get('session.use_cookies')) setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/']); session_destroy(); }
function do_register(string $nama, string $user, string $pw, string $pw2): array {
    $nama = trim($nama); $user = trim($user);
    if ($nama === '') return [false, 'Nama lengkap wajib diisi.'];
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $user)) return [false, 'Nama pengguna minimal 3 karakter: huruf, angka, titik, garis bawah, atau strip.'];
    foreach (tbl('pengguna') as $r) if (mb_strtolower($r['username']) === mb_strtolower($user)) return [false, 'Nama pengguna sudah dipakai.'];
    if (mb_strlen($pw) < 6) return [false, 'Kata sandi minimal 6 karakter.']; if ($pw !== $pw2) return [false, 'Kata sandi dan pengulangannya tidak sama.'];
    $id = ins('pengguna', ['nama' => mb_substr($nama, 0, 100), 'username' => $user, 'kata_sandi_hash' => password_hash($pw, PASSWORD_DEFAULT), 'peran' => 'warga', 'aktif' => true]);
    login_user(row('pengguna', $id), false); return [true, ''];
}
function skin(): string { $s = $_COOKIE['skin'] ?? 'auto'; return in_array($s, ['auto', 'light', 'dark', 'ocean', 'contrast'], true) ? $s : 'auto'; }
const SKINS = [['auto', 'Otomatis', 'auto', 'Mengikuti pengaturan perangkat'], ['light', 'Terang', 'sun', 'Latar cerah untuk siang hari'], ['dark', 'Gelap', 'moon', 'Nyaman dipakai di malam hari'], ['ocean', 'Samudra', 'waves', 'Biru laut dalam dengan aksen aqua'], ['contrast', 'Kontras tinggi', 'contrast', 'Teks dan batas lebih tegas']];
