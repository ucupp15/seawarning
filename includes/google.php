<?php
declare(strict_types=1);
/* Daftar dan masuk warga dengan akun Google (OAuth 2.0 / OpenID Connect).
 * Pengaturan di config.php bagian 'google'. Alamat kembali (redirect URI) = alamat google.php di server ini,
 * misalnya http://localhost/sea_warning/google.php, dan harus didaftarkan sama persis di Google Cloud Console. */

function google_cfg(): array { $g = cfg('google', []); return is_array($g) ? $g : []; }
function google_siap(): bool { $g = google_cfg(); return trim((string) ($g['client_id'] ?? '')) !== '' && trim((string) ($g['client_secret'] ?? '')) !== ''; }
function google_redirect_uri(): string {
    $u = trim((string) (google_cfg()['redirect_uri'] ?? '')); if ($u !== '') return $u;
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/google.php';
}
function google_url_masuk(): string {
    $st = bin2hex(random_bytes(16)); $_SESSION['g_state'] = $st;
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(['client_id' => trim((string) google_cfg()['client_id']), 'redirect_uri' => google_redirect_uri(), 'response_type' => 'code', 'scope' => 'openid email profile', 'state' => $st, 'prompt' => 'select_account']);
}
/* Permintaan HTTPS ke Google. Pada XAMPP Windows, curl sering tidak menemukan sertifikat CA; dicoba juga berkas bawaan XAMPP. */
function google_http(string $url, ?array $post = null, array $hdr = []): array {
    if (!function_exists('curl_init')) return [0, '', 'Ekstensi curl PHP belum aktif. Aktifkan extension=curl di php.ini.'];
    $ca = array_filter([google_cfg()['cainfo'] ?? '', 'C:/xampp/apache/bin/curl-ca-bundle.crt', 'C:/xampp/php/extras/ssl/cacert.pem'], fn($f) => $f && is_file($f));
    foreach ([null, ...$ca] as $c) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_HTTPHEADER => $hdr]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        if ($c) curl_setopt($ch, CURLOPT_CAINFO, $c);
        $b = curl_exec($ch); $no = curl_errno($ch); $er = curl_error($ch); $st = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($no === 60 || $no === 77) continue;   // masalah sertifikat: coba berkas CA berikutnya
        return [$st, (string) $b, $no ? $er : ''];
    }
    return [0, '', 'Sertifikat SSL tidak dapat diperiksa. Isi google.cainfo di config.php dengan lokasi berkas cacert.pem.'];
}
/* Tukar kode dari Google dengan profil pengguna: ['sub', 'email', 'email_verified', 'name']. */
function google_profil(string $code): array {
    $g = google_cfg();
    [$st, $b, $er] = google_http('https://oauth2.googleapis.com/token', ['code' => $code, 'client_id' => trim((string) $g['client_id']), 'client_secret' => trim((string) $g['client_secret']), 'redirect_uri' => google_redirect_uri(), 'grant_type' => 'authorization_code']);
    $t = json_decode($b, true);
    if ($er || $st !== 200 || empty($t['access_token'])) throw new RuntimeException($er ?: 'Google menolak permintaan (' . ($t['error_description'] ?? $t['error'] ?? 'HTTP ' . $st) . ').');
    [$st, $b, $er] = google_http('https://openidconnect.googleapis.com/v1/userinfo', null, ['Authorization: Bearer ' . $t['access_token']]);
    $p = json_decode($b, true);
    if ($er || $st !== 200 || empty($p['sub'])) throw new RuntimeException($er ?: 'Profil Google tidak dapat dibaca.');
    return $p;
}
/* Cari akun yang terhubung ke Google; bila belum ada, hubungkan lewat email yang sama atau buat akun warga baru. */
function google_akun(array $p): array {
    $sub = (string) $p['sub']; $email = mb_strtolower(trim((string) ($p['email'] ?? ''))); $ok = !empty($p['email_verified']);
    foreach (tbl('pengguna') as $r) if ((string) ($r['google_id'] ?? '') === $sub) return [$r, false];
    if ($email !== '' && $ok) foreach (tbl('pengguna') as $r) if (mb_strtolower((string) ($r['email'] ?? '')) === $email) { upd('pengguna', $r['id_pengguna'], ['google_id' => $sub]); return [row('pengguna', $r['id_pengguna']), false]; }
    $dasar = preg_replace('/[^A-Za-z0-9._-]/', '', explode('@', $email ?: 'warga')[0]) ?: 'warga'; $dasar = mb_substr(strlen($dasar) < 3 ? $dasar . 'warga' : $dasar, 0, 40);
    $pakai = array_map(fn($r) => mb_strtolower($r['username']), tbl('pengguna')); $user = $dasar; $i = 2;
    while (in_array(mb_strtolower($user), $pakai, true)) $user = $dasar . $i++;
    $nama = trim((string) ($p['name'] ?? '')) ?: $user;
    $id = ins('pengguna', ['nama' => mb_substr($nama, 0, 100), 'username' => $user, 'kata_sandi_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), 'peran' => 'warga', 'aktif' => true, 'email' => $ok ? mb_substr($email, 0, 150) : null, 'google_id' => $sub]);
    return [row('pengguna', $id), true];
}
function google_tombol(string $label): string {
    $svg = '<svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.8 2.4 30.3 0 24 0 14.6 0 6.6 5.4 2.7 13.2l7.9 6.2C12.5 13.6 17.8 9.5 24 9.5z"/><path fill="#4285F4" d="M46.1 24.6c0-1.6-.1-3.1-.4-4.6H24v9h12.4c-.5 2.9-2.2 5.3-4.6 6.9l7.4 5.7c4.3-4 6.9-9.9 6.9-17z"/><path fill="#FBBC05" d="M10.6 28.6c-.5-1.4-.8-3-.8-4.6s.3-3.2.8-4.6l-7.9-6.2C1 16.6 0 20.2 0 24s1 7.4 2.7 10.8l7.9-6.2z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.4-5.7c-2.1 1.4-4.8 2.3-8.5 2.3-6.2 0-11.5-4.2-13.4-9.9l-7.9 6.2C6.6 42.6 14.6 48 24 48z"/></svg>';
    return '<a class="btn btn-block btn-google" href="google.php">' . $svg . '<span>' . e($label) . '</span></a>';
}
