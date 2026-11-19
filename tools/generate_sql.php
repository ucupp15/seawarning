<?php
declare(strict_types=1);
/* Membuat berkas SQL (CREATE TABLE + INSERT) dari basis data yang sedang aktif, untuk diimpor lewat phpMyAdmin.
   Pemakaian (dari folder proyek):  php tools/generate_sql.php > database/database.sql */
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/includes/bootstrap.php';
$only = in_array('--schema', $argv ?? [], true); $pdo = db();
echo "-- SEA WARNING: skema " . count(schema()) . " tabel sesuai ERD" . ($only ? '' : ' + data contoh') . "\n-- Dibuat: " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
foreach (array_reverse(array_keys(schema())) as $t) echo "DROP TABLE IF EXISTS `$t`;\n"; echo "\n";
foreach (ddl('mysql') as $t => $sql) echo $sql . ";\n\n";
if (!$only) foreach (array_keys(schema()) as $t) { $rows = $pdo->query("SELECT * FROM `$t` ORDER BY `" . pk($t) . '`')->fetchAll(PDO::FETCH_ASSOC); if (!$rows) continue; $cols = array_keys($rows[0]); echo "-- Data: $t\nINSERT INTO `$t` (" . implode(', ', array_map(fn($c) => "`$c`", $cols)) . ") VALUES\n"; $lines = [];
    foreach ($rows as $r) $lines[] = '(' . implode(', ', array_map(fn($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), array_values($r))) . ')'; echo implode(",\n", $lines) . ";\n\n"; }
echo "SET FOREIGN_KEY_CHECKS=1;\n";
