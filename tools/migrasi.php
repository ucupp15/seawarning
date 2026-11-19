<?php
declare(strict_types=1);
/*
 * Pemutakhiran basis data yang SUDAH terpasang, ke versi dengan data pasang surut
 * BIG/SRGI dan menu evakuasi. Aman dijalankan berulang kali (idempoten).
 *
 * Pemakaian dari folder proyek:
 *     php tools/migrasi.php
 * Atau lewat peramban (hapus berkas ini setelah selesai):
 *     http://localhost/sea_warning/tools/migrasi.php
 *
 * Yang dikerjakan:
 *   1. Membuat tabel titik_evakuasi dan jalur_evakuasi bila belum ada.
 *   2. Mendaftarkan sumber data BIG/SRGI dan sumber analisis geometri.
 *   3. Mengisi titik dan jalur evakuasi dari data/evakuasi.json bila masih kosong.
 *   4. Menyelaraskan parameter "Pasang surut": batas kelas skor diturunkan dari
 *      persentil data BIG, dan nilainya disetel sama untuk seluruh kampung
 *      (prediksi BIG berasal dari satu titik, jadi tidak berbeda antar kampung).
 *   5. Mengganti data_oseanografi 72 jam dengan pasang surut dari prediksi BIG.
 *   6. Mengosongkan cache analisis pasut.
 *
 * TIDAK menghapus data lain. Cadangkan basis data sebelum menjalankan, seperti biasa.
 */
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/database.php';

$cli = PHP_SAPI === 'cli';
$out = [];
$say = function (string $s) use (&$out, $cli) { $out[] = $s; if ($cli) echo $s, "\n"; };

try {
    if (!tables_ready()) throw new RuntimeException('Basis data belum terpasang. Jalankan install.php lebih dulu.');

    /* 1. tabel baru */
    create_schema(false);
    $say('1. Skema diperiksa; tabel yang belum ada dibuat (' . count(schema()) . ' tabel sesuai ERD).');

    /* 2. sumber data */
    $idBig = pasut_ada() ? sumber_pasut_big() : null;
    $idGeo = ensure_sumber('Analisis geometri garis pantai digitasi (turunan)', 'Turunan / analisis spasial', '');
    $say('2. Sumber data siap: ' . ($idBig ? "BIG/SRGI (id $idBig), " : 'BIG/SRGI DILEWATI (berkas pasut tidak ada), ') . "analisis geometri (id $idGeo).");

    /* 3. titik dan jalur evakuasi */
    if (tbl('titik_evakuasi')) {
        $say('3. Titik evakuasi sudah ada (' . count(tbl('titik_evakuasi')) . ' baris); tidak diubah. '
            . 'Untuk memuat ulang dari hasil analisis, pakai tombol di menu Titik Aman dan Evakuasi > Kelola titik.');
    } else {
        [$nt, $nj] = evak_seed(false);
        $say($nt ? "3. Ditambahkan $nt titik dan $nj jalur evakuasi dari data/evakuasi.json."
                 : '3. data/evakuasi.json tidak ditemukan atau kosong; titik evakuasi belum diisi.');
    }

    /* 4. parameter pasang surut */
    if (pasut_ada()) {
        $r = pasut_selaraskan_parameter(1);
        $say('4. Parameter Pasang surut diselaraskan: batas kelas skor ' . nfa($r['batas'][0] ?? 0) . ' m dan '
            . nfa($r['batas'][1] ?? 0) . ' m (persentil P90 dan P98 data BIG); nilai ' . nfa($r['hhwl'] ?? 0)
            . ' m (HHWL gabungan) disetel untuk ' . ($r['nilai'] ?? 0) . ' wilayah.');
    } else {
        $say('4. DILEWATI: berkas data/pasut_big/data_pasut<tahun>.txt tidak ditemukan.');
    }

    /* 5. data oseanografi 72 jam dengan pasut BIG */
    if (pasut_ada()) {
        /* sumber cadangan untuk baris yang pasang surutnya di luar rentang berkas BIG */
        $idSim = ensure_sumber('Data simulasi prototipe (bukan data resmi)', 'Simulasi', '');
        $now = (int) (floor(time() / 3600) * 3600);
        $baris = gen_osea($now, -24, 48, $idSim, $idBig);   // dibangun dulu, baru data lama dihapus
        db()->exec('DELETE FROM `data_oseanografi`');
        tbl_flush('data_oseanografi');
        $n = 0;
        foreach ($baris as $r) { ins('data_oseanografi', $r); $n++; }
        $say("5. $n baris data oseanografi dibangkitkan ulang; pasang surutnya dari prediksi BIG, "
            . 'gelombang/angin/arus tetap simulasi dan ditandai demikian di antarmuka.');
    } else {
        $say('5. DILEWATI: tidak ada data pasut BIG.');
    }

    /* 6. analisis ulang + bersihkan cache */
    $n = pasut_cache_clear();
    $say("6. $n berkas cache analisis pasut dihapus; akan dihitung ulang saat halaman dibuka.");
    $res = run_analysis(1, 'Skoring berbobot (weighted overlay), pasang surut dari prediksi BIG/SRGI');
    $say('7. Analisis kerawanan dijalankan ulang untuk ' . $res['n'] . ' wilayah'
        . ($res['skip'] ? ', dilewati: ' . implode(', ', $res['skip']) : '') . '.');

    $say('');
    $say('SELESAI. Buka menu "Pasang Surut BIG/SRGI" dan "Titik Aman dan Evakuasi" untuk memeriksa hasilnya.');
    $ok = true;
} catch (Throwable $e) {
    $say('GAGAL: ' . $e->getMessage());
    $ok = false;
}

if (!$cli) {
    echo html_head('Pemutakhiran | SEA WARNING'), '<body><main style="max-width:760px;margin:0 auto;padding:40px 20px"><div class="panel">';
    echo '<h1 style="font-size:24px;margin-bottom:12px">Pemutakhiran basis data</h1>';
    echo note_box(implode('<br>', array_map('e', $out)), $ok ? '' : 'bad', $ok ? 'checkc' : 'octagon');
    echo '<p class="muted" style="margin:16px 0">Untuk keamanan, <b>hapus berkas <span class="mono">tools/migrasi.php</span></b> setelah selesai.</p>';
    echo '<a class="btn btn-primary" href="../index.php">Buka aplikasi</a></div></main></body></html>';
}
