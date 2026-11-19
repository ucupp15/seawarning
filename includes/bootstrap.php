<?php
declare(strict_types=1);
/* Memuat konfigurasi, sesi, dan seluruh modul aplikasi. */
if (!defined('APP_ROOT')) define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '2.3-php');

$CONFIG = is_file(APP_ROOT . '/config.php') ? require APP_ROOT . '/config.php' : require APP_ROOT . '/config.sample.php';
function cfg(string $k, $d = null) { global $CONFIG; return $CONFIG[$k] ?? $d; }

date_default_timezone_set((string) cfg('timezone', 'Asia/Jakarta'));
mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');

if (session_status() === PHP_SESSION_NONE) {
    session_name((string) cfg('session_name', 'seawarning'));
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
    session_start();
}

foreach (['helpers', 'database', 'bmkg', 'pasut', 'calc', 'auth', 'svg', 'evakuasi', 'crud', 'layout', 'dashboard', 'laporan', 'status', 'visual', 'panduan', 'google'] as $m) require_once APP_ROOT . "/includes/$m.php";

// Header keamanan dasar
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
