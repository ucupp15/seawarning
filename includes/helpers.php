<?php
declare(strict_types=1);
/* Fungsi bantu: escape, format Indonesia, URL, sesi/pesan, CSRF, ikon. */

function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function nf($n, int $d = 0): string { return ($n === null || $n === '' || !is_numeric($n)) ? '–' : number_format((float) $n, $d, ',', '.'); }
function nfa($v): string { // maksimal 2 desimal, tanpa nol di belakang
    if ($v === null || $v === '' || !is_numeric($v)) return '–';
    $s = rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ','); return $s === '' ? '0' : $s;
}
const BLN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const BLN_PANJANG = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const HARI = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
function ts($s): ?int { if ($s === null || $s === '') return null; if ($s instanceof DateTimeInterface) return $s->getTimestamp(); if (is_int($s)) return $s; $t = strtotime((string) $s); return $t === false ? null : $t; }
function fmt_d($s): string { $t = ts($s); return $t ? date('j', $t) . ' ' . BLN[(int) date('n', $t) - 1] . ' ' . date('Y', $t) : '–'; }
function fmt_dt($s): string { $t = ts($s); return $t ? date('j', $t) . ' ' . BLN[(int) date('n', $t) - 1] . ' ' . date('Y', $t) . ', ' . date('H.i', $t) : '–'; }
function fmt_t($s): string { $t = ts($s); return $t ? date('H.i', $t) : '–'; }
function fmt_day($s): string { $t = ts($s); return $t ? HARI[(int) date('w', $t)] . ', ' . date('j', $t) . ' ' . BLN[(int) date('n', $t) - 1] : '–'; }
function fmt_dayt($s): string { $t = ts($s); return $t ? HARI[(int) date('w', $t)] . ', ' . date('j', $t) . ' ' . BLN[(int) date('n', $t) - 1] . ', ' . date('H.i', $t) : '–'; }
function to_input_dt($s): string { $t = ts($s); return $t ? date('Y-m-d\TH:i', $t) : ''; }
function db_dt(?int $t = null): string { return date('Y-m-d H:i:s', $t ?? time()); }
function clamp($v, $a, $b) { return min($b, max($a, $v)); }
function sum(array $a): float { return (float) array_sum($a); }
const INF_BATAS = 99999;
function bound($n): string { return $n <= -999 ? '−∞' : ($n >= INF_BATAS ? '∞' : nf($n, fmod((float) $n, 1.0) ? 2 : 0)); }

/* ---------- URL dan alihan ---------- */
function url(string $p = 'beranda', array $q = []): string { return 'index.php?' . http_build_query(['p' => $p] + $q); }
function redirect(string $to): never { header('Location: ' . $to); exit; }
function safe_back(string $b, string $d = 'index.php'): string { return ($b === '' || preg_match('~[\\\\\s]|^[a-z][a-z0-9+.-]*:|^//~i', $b)) ? $d : $b; } // hanya alamat di aplikasi ini (menolak //, \\, dan skema)
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }
function gv(string $k, $d = null) { return $_GET[$k] ?? $d; }
function pv(string $k, $d = null) { return $_POST[$k] ?? $d; }

/* ---------- pesan sekali tampil ---------- */
function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'][] = [$type, $msg]; }
function flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

/* ---------- CSRF ---------- */
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
    $t = (string) ($_POST['csrf'] ?? '');
    if ($t === '' || !hash_equals(csrf_token(), $t)) { http_response_code(419); exit('Sesi formulir tidak valid atau kedaluwarsa. Kembali, muat ulang halaman, lalu coba lagi.'); }
}

/* ---------- ikon (SVG) ---------- */
function ic(string $n, int $s = 20, string $cls = ''): string {
    static $IC = null; if ($IC === null) $IC = D()['IC'];
    return '<svg class="ic ' . e($cls) . '" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($IC[$n] ?? '') . '</svg>';
}

function uniq_list(array $a): array { return array_values(array_unique($a)); }
function pick_best(array $rows, callable $f) { $best = null; $bv = null; foreach ($rows as $r) { $v = $f($r); if ($best === null || $v > $bv) { $best = $r; $bv = $v; } } return $best; }


/* Nama warna dalam huruf untuk kode heksadesimal (#RRGGBB), supaya pengguna tidak perlu membaca kodenya. */
function warna_nama(string $hex): string {
    $hex = ltrim(trim($hex), '#'); if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) return trim($hex) !== '' ? 'Warna khusus' : '–';
    [$r, $g, $b] = [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255];
    $mx = max($r, $g, $b); $mn = min($r, $g, $b); $l = ($mx + $mn) / 2; $d = $mx - $mn;
    if ($l < 0.12) return 'Hitam'; if ($l > 0.94) return 'Putih';
    $s = $d == 0 ? 0 : $d / (1 - abs(2 * $l - 1)); if ($s < 0.14) return $l < 0.4 ? 'Abu-abu tua' : 'Abu-abu';
    if ($mx == $r) $hh = fmod(($g - $b) / $d, 6); elseif ($mx == $g) $hh = ($b - $r) / $d + 2; else $hh = ($r - $g) / $d + 4; $hh = fmod($hh * 60 + 360, 360);
    $n = match (true) { $hh < 15 || $hh >= 345 => 'Merah', $hh < 38 => 'Oranye', $hh < 68 => 'Kuning', $hh < 165 => 'Hijau', $hh < 200 => 'Toska', $hh < 255 => 'Biru', $hh < 290 => 'Ungu', default => 'Merah muda' };
    return ($l < 0.28 && $n !== 'Hitam' ? $n . ' tua' : $n);
}
