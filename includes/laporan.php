<?php
declare(strict_types=1);
/*
 * LAPORAN WARGA (v12): warga mengirim foto dan lokasi genangan; admin/perangkat desa memverifikasi.
 * Laporan TERVERIFIKASI ikut menentukan status peringatan otomatis (includes/status.php).
 *
 * Keamanan unggahan:
 *  - hanya JPEG/PNG/WebP, diperiksa dari ISI berkas (getimagesize), bukan dari nama atau tipe kiriman peramban;
 *  - maksimal LAPORAN_FOTO_MB MB dan 40 megapiksel;
 *  - bila ekstensi GD aktif (bawaan XAMPP), foto DIGAMBAR ULANG menjadi JPEG baru (maks 1600 px). Ini membuang
 *    metadata EXIF (termasuk lokasi GPS di foto) dan isi tersembunyi apa pun;
 *  - nama berkas acak, disimpan di storage/laporan/ (ditolak .htaccess), dan hanya dikirim lewat index.php?p=foto
 *    setelah pemeriksaan hak akses: staf melihat semua, warga hanya fotonya sendiri;
 *  - token CSRF, dan pembatasan jumlah: LAPORAN_BATAS_30MNT per 30 menit dan LAPORAN_BATAS_HARI per 24 jam per akun warga.
 */
const LAPORAN_FOTO_MB = 8;
const LAPORAN_BATAS_30MNT = 3;
const LAPORAN_BATAS_HARI = 10;
const LAPORAN_SISI_MAKS = 1600;
const LAPORAN_BBOX = [[-5.9481, 106.1357], [-5.9161, 106.1726]];   // batas peta Pulo Panjang ditambah ±330 m perairan
const TINGGI_AIR = [10 => 'Semata kaki (±10 cm)', 30 => 'Setinggi betis (±30 cm)', 50 => 'Setinggi lutut (±50 cm)', 80 => 'Setinggi paha (±80 cm)', 100 => 'Sepinggang atau lebih (±100 cm)'];
const LAPORAN_STATUS_C = ['Menunggu' => '#E6A100', 'Terverifikasi' => '#D64933', 'Ditolak' => '#8493A0'];
const PERAN_STAF_LAPORAN = ['admin', 'perangkat_desa'];

function laporan_dir(): string { $d = storage_dir() . '/laporan'; if (!is_dir($d)) @mkdir($d, 0775, true); return $d; }
function laporan_tabel_ada(): bool { static $ok = null; if ($ok === null) { try { dbq('SELECT 1 FROM laporan_warga LIMIT 1'); $ok = true; } catch (Throwable $e) { $ok = false; } } return $ok; }
function laporan_semua(): array { if (!laporan_tabel_ada()) return []; $r = tbl('laporan_warga'); usort($r, fn($a, $b) => strcmp((string) $b['waktu_lapor'], (string) $a['waktu_lapor'])); return $r; }
function laporan_menunggu(): int { return count(array_filter(laporan_semua(), fn($r) => $r['status'] === 'Menunggu')); }
function laporan_dalam_pulau(float $lat, float $lng): bool { [[$a, $b], [$c, $d]] = LAPORAN_BBOX; return $lat >= $a && $lat <= $c && $lng >= $b && $lng <= $d; }
function laporan_tinggi_label(int $cm): string { return TINGGI_AIR[$cm] ?? ($cm . ' cm'); }

/* Kampung terdekat dari titik (pembagian pulau di aplikasi ini juga memakai titik kampung terdekat). */
function laporan_kampung_terdekat(float $lat, float $lng): ?int {
    $best = null; $bd = INF; $kx = cos(deg2rad($lat));
    foreach (tbl('wilayah') as $w) { $t = wil_titik($w); if (!$t) continue; $d = (($t[0] - $lat) ** 2) + ((($t[1] - $lng) * $kx) ** 2); if ($d < $bd) { $bd = $d; $best = (int) $w['id_wilayah']; } }
    return $best;
}

/* Batas kiriman per akun. NULL bila boleh, atau pesan penolakan. */
function laporan_cek_batas(array $u): ?string {
    if (in_array($u['peran'], PERAN_STAF_LAPORAN, true)) return null;
    $now = time(); $mine = array_filter(laporan_semua(), fn($r) => (int) $r['id_pengguna'] === (int) $u['id_pengguna']);
    $n30 = count(array_filter($mine, fn($r) => ts($r['waktu_lapor']) >= $now - 1800)); $n24 = count(array_filter($mine, fn($r) => ts($r['waktu_lapor']) >= $now - 86400));
    if ($n30 >= LAPORAN_BATAS_30MNT) return 'Anda sudah mengirim ' . $n30 . ' laporan dalam 30 menit terakhir. Tunggu sebentar sebelum mengirim lagi, atau hubungi perangkat desa bila keadaan mendesak.';
    if ($n24 >= LAPORAN_BATAS_HARI) return 'Batas ' . LAPORAN_BATAS_HARI . ' laporan per hari sudah tercapai. Hubungi perangkat desa bila keadaan mendesak.';
    return null;
}

/* ---------- penyimpanan foto ---------- */
function laporan_gd(): bool { return function_exists('imagecreatetruecolor') && function_exists('imagejpeg'); }
/** @return array{0:bool,1:string} [berhasil, nama berkas relatif atau pesan galat] */
function laporan_simpan_foto(?array $f): array {
    if (!$f || !isset($f['error']) || is_array($f['error'])) return [false, 'Foto wajib dilampirkan.'];
    if ($f['error'] === UPLOAD_ERR_NO_FILE) return [false, 'Foto wajib dilampirkan.'];
    if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) return [false, 'Ukuran foto terlalu besar. Maksimal ' . LAPORAN_FOTO_MB . ' MB.'];
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) return [false, 'Foto gagal diunggah. Coba lagi.'];
    if ((int) $f['size'] > LAPORAN_FOTO_MB * 1048576) return [false, 'Ukuran foto terlalu besar. Maksimal ' . LAPORAN_FOTO_MB . ' MB.'];
    $tmp = (string) $f['tmp_name']; $info = @getimagesize($tmp);
    $ok = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'] + (defined('IMAGETYPE_WEBP') ? [IMAGETYPE_WEBP => 'webp'] : []);
    if (!$info || !isset($ok[$info[2]])) return [false, 'Berkas bukan foto JPEG, PNG, atau WebP.'];
    [$w, $h] = $info; if ($w < 50 || $h < 50 || $w * $h > 40_000_000) return [false, 'Ukuran gambar tidak wajar.'];
    $sub = date('Ym'); $dir = laporan_dir() . '/' . $sub; if (!is_dir($dir)) @mkdir($dir, 0775, true); $id = bin2hex(random_bytes(12));
    if (laporan_gd()) {
        $mem = ini_get('memory_limit'); if ($mem !== '-1' && (int) $mem < 256) @ini_set('memory_limit', '256M');
        $src = match ($info[2]) { IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp), IMAGETYPE_PNG => @imagecreatefrompng($tmp), default => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false };
        if (!$src) return [false, 'Foto tidak dapat dibaca. Coba foto lain.'];
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {   // putar sesuai orientasi kamera sebelum EXIF dibuang
            $o = (int) (@exif_read_data($tmp)['Orientation'] ?? 1); $deg = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
            if ($deg && ($r = imagerotate($src, $deg, 0))) { imagedestroy($src); $src = $r; }
        }
        foreach (['' => LAPORAN_SISI_MAKS, '_k' => 420] as $suf => $max) {
            $sw = imagesx($src); $sh = imagesy($src); $k = min(1, $max / max($sw, $sh)); $dw = max(1, (int) round($sw * $k)); $dh = max(1, (int) round($sh * $k));
            $dst = imagecreatetruecolor($dw, $dh); imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
            $ok2 = imagejpeg($dst, "$dir/$id$suf.jpg", $suf ? 75 : 82); imagedestroy($dst);
            if (!$ok2) { imagedestroy($src); @unlink("$dir/$id.jpg"); return [false, 'Foto gagal disimpan di server (periksa izin folder storage).']; }
        }
        imagedestroy($src); return [true, "$sub/$id.jpg"];
    }
    $ext = $ok[$info[2]]; if (!@move_uploaded_file($tmp, "$dir/$id.$ext")) return [false, 'Foto gagal disimpan di server (periksa izin folder storage).'];
    return [true, "$sub/$id.$ext"];
}
function laporan_path(string $nama, bool $kecil = false): ?string {
    if (!preg_match('~^\d{6}/[a-f0-9]{24}\.(jpg|png|webp)$~', $nama)) return null;
    $p = laporan_dir() . '/' . $nama; if ($kecil) { $k = preg_replace('~\.jpg$~', '_k.jpg', $p); if ($k !== $p && is_file($k)) return $k; }
    return is_file($p) ? $p : null;
}
function laporan_hapus_berkas(string $nama): void { foreach ([laporan_path($nama), laporan_path($nama, true)] as $p) if ($p) @unlink($p); }

/* index.php?p=foto&id=..&k=1 : mengirim foto setelah pemeriksaan hak akses */
function laporan_foto_kirim(array $u, int $id, bool $kecil): never {
    $r = laporan_tabel_ada() ? row('laporan_warga', $id) : null;
    $boleh = $r && ($u['peran'] !== 'warga' || (int) $r['id_pengguna'] === (int) $u['id_pengguna']);
    $p = $boleh ? laporan_path((string) $r['foto'], $kecil) : null;
    if (!$p) { http_response_code(404); exit('Foto tidak ditemukan.'); }
    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
    header('Content-Type: ' . (['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($p)); header('Cache-Control: private, max-age=86400'); header("Content-Security-Policy: default-src 'none'; sandbox"); header('Content-Disposition: inline; filename="laporan-' . $id . ($kecil ? '-kecil' : '') . '.' . $ext . '"');
    readfile($p); exit;
}
function laporan_foto_url(array $r, bool $kecil = false): string { return 'index.php?' . http_build_query(['p' => 'foto', 'id' => $r['id_laporan']] + ($kecil ? ['k' => 1] : [])); }

/* ---------- data peta (Leaflet) ---------- */
function laporan_peta_data(array $rows, string $mode, bool $staf): array {
    $polys = []; foreach (tbl('wilayah') as $w) if ($g = geo_real($w)) $polys[] = ['geo' => $g, 'nama' => $w['nama_wilayah']];
    $pts = []; foreach ($rows as $r) $pts[] = ['id' => $r['id_laporan'], 'lat' => (float) $r['lintang'], 'lng' => (float) $r['bujur'], 'st' => $r['status'], 'c' => LAPORAN_STATUS_C[$r['status']] ?? '#888', 'cm' => (int) $r['tinggi_air_cm'], 'w' => fmt_dt($r['waktu_lapor']), 'kp' => $r['id_wilayah'] ? short_name(label_of('wilayah', $r['id_wilayah'])) : '', 'img' => laporan_foto_url($r, true)];
    $kampung = []; foreach (tbl('wilayah') as $w) if ($t = wil_titik($w)) $kampung[] = ['id' => $w['id_wilayah'], 'lat' => $t[0], 'lng' => $t[1], 'nama' => $w['nama_wilayah']];
    return ['mode' => $mode, 'sat' => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', 'lab' => 'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', 'attr' => 'Tiles &copy; Esri &mdash; Source: Esri, Maxar, Earthstar Geographics', 'bounds' => [[-5.9451, 106.13876], [-5.91914, 106.16963]], 'bbox' => LAPORAN_BBOX, 'polys' => $polys, 'kampung' => $kampung, 'points' => $pts];
}
function laporan_peta_html(array $data, string $id = 'lap-map'): string {
    return '<div id="' . e($id) . '" class="leaf" style="height:340px" role="application" aria-label="Peta lokasi laporan genangan"></div><div class="sat-msg" id="' . e($id) . '-msg"></div>'
        . '<script type="application/json" id="' . e($id) . '-data">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
}
function laporan_assets(): string { return '<script src="assets/js/leaflet.js"></script><script src="assets/js/lapor.js"></script>'; }
function laporan_status_tag(string $s): string { return '<span class="tag" style="--c:' . (LAPORAN_STATUS_C[$s] ?? '#888') . '">' . ic(['Menunggu' => 'history', 'Terverifikasi' => 'checkc', 'Ditolak' => 'x'][$s] ?? 'info', 14) . e($s) . '</span>'; }
