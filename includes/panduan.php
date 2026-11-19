<?php
declare(strict_types=1);
/*
 * PANDUAN PENGGUNAAN (v13).
 *  - Menu "Panduan" untuk semua peran (isi disesuaikan per peran; warga paling lengkap, bahasa sehari-hari).
 *  - Kartu catatan (seperti sticky note) di beranda setelah login. Bisa ditutup dengan tanda silang; pilihan tutup
 *    disimpan DI SERVER per pengguna (storage/panduan.json), jadi tetap tertutup di ponsel maupun komputer lain.
 *    Bila PANDUAN_VERSI dinaikkan (panduan diperbarui), catatan muncul lagi satu kali.
 *  - Panduan lengkap selalu dapat dibuka lagi dari menu, dan catatan dapat ditampilkan lagi dari halaman Panduan.
 * Kontak darurat tidak dikarang: isi 'kontak_darurat' di config.php (lihat config.sample.php) agar tampil di panduan.
 */
const PANDUAN_VERSI = 1;

function panduan_file(): string { return storage_dir() . '/panduan.json'; }
function panduan_baca(): array { $f = panduan_file(); $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null; return is_array($j) ? $j : []; }
function panduan_ditutup(int $uid): bool { return (int) (panduan_baca()[(string) $uid]['versi'] ?? 0) >= PANDUAN_VERSI; }
function panduan_set(int $uid, bool $tutup): void {
    $fh = @fopen(panduan_file(), 'c+'); if (!$fh) return;
    flock($fh, LOCK_EX); $d = json_decode((string) stream_get_contents($fh), true); $d = is_array($d) ? $d : [];
    if ($tutup) $d[(string) $uid] = ['versi' => PANDUAN_VERSI, 't' => time()]; else unset($d[(string) $uid]);
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($d)); fflush($fh); flock($fh, LOCK_UN); fclose($fh);
}

/* Langkah-langkah per peran: [ikon, judul, isi, [halaman, label tombol]?] — isi memakai teks biasa (di-escape saat tampil). */
function panduan_langkah(string $role): array {
    $w = [
        ['home', 'Membaca status: Aman, Waspada, Siaga, Awas', 'Buka menu "Peringatan". Kotak status memakai warna dan kata yang sama dengan arti di bawah ini. Tulisan "Otomatis" berarti status dihitung sendiri oleh sistem dari data pasang surut resmi (BIG), peringatan BMKG, dan laporan warga yang sudah diperiksa. Bila status naik, tanda peringatan juga muncul di bagian atas semua halaman.', ['peringatan', 'Buka Peringatan']],
        ['pin', 'Cek kampung Anda', 'Buka menu "Cek Wilayah", pilih nama kampung tempat tinggal Anda pada kotak "Wilayah saya", lalu tekan simpan. Setelah itu tingkat kerawanan kampung Anda langsung tampil setiap kali Anda membuka web.', ['cek-wilayah', 'Buka Cek Wilayah']],
        ['map', 'Melihat peta', 'Buka "Peta Zona Kerawanan". Ketuk nama kampung untuk melihat warnanya. Hijau berarti relatif aman, kuning perlu waspada, merah rawan rob. Dua jari untuk memperbesar peta; peta satelit butuh internet, peta skematik tidak.', ['peta', 'Buka Peta']],
        ['flag', 'Mencari titik aman dan arah evakuasi', 'Buka "Titik Aman dan Evakuasi". Di peta ada titik kumpul tiap kampung dan angka 1 sampai 7: angka kecil berarti lebih aman. Ingat: titik dan arah ini masih usulan dan jalannya belum disurvei, jadi selalu ikuti arahan perangkat desa.', ['evakuasi', 'Buka Titik Aman']],
        ['camera', 'Melaporkan genangan', 'Lihat air masuk jalan atau rumah? Buka "Laporkan Genangan": (1) ambil atau pilih foto, (2) tekan "Pakai lokasi saya" atau ketuk peta di tempat genangan, atau pilih kampung, (3) pilih tinggi air, (4) tekan "Kirim laporan". Jangan memotret dari tempat berbahaya. Perangkat desa akan memeriksa laporan Anda; laporan yang sudah diperiksa membantu menaikkan peringatan untuk semua orang.', ['lapor', 'Buka Laporkan Genangan']],
        ['waves', 'Kapan air paling tinggi?', 'Buka "Jadwal Pasang Surut". Di sana ada jam air paling tinggi hari ini dan beberapa hari ke depan. Hindari berada di tepi pantai dan jalan rendah pada jam-jam itu.', ['pasut', 'Buka Jadwal Pasang Surut']],
        ['shield', 'Apa yang harus dilakukan?', 'Halaman "Apa yang Harus Dilakukan?" berisi langkah sederhana sesuai tingkat kerawanan kampung Anda: barang apa yang diamankan, kapan berangkat, dan ke mana.', ['tindakan', 'Buka Langkah Kesiapsiagaan']],
        ['user', 'Mengubah tampilan dan kata sandi', 'Di "Akun dan Tampilan" Anda dapat membuat huruf dan warna lebih nyaman (misalnya Kontras tinggi) dan mengganti kata sandi. Ketuk tombol Menu di pojok bawah (ponsel) untuk melihat semua halaman.', ['profil', 'Buka Akun dan Tampilan']],
    ];
    $pd = [
        ['home', 'Pantauan Desa', 'Status otomatis dan dasar penentuannya ada di menu Peringatan. Kondisi tiap kampung ada di Wilayah Rawan dan Peta Zona Kerawanan. Dashboard memperkenalkan Pulo Panjang dan tujuan web.', ['peringatan', 'Buka Peringatan']],
        ['camera', 'Memeriksa laporan warga', 'Buka "Laporan Warga". Periksa foto, lokasi, dan tinggi air; bila perlu hubungi RT setempat. Tekan "Verifikasi" bila benar, atau "Tolak" dengan alasan. Hanya laporan terverifikasi yang ikut menentukan status. Jumlah laporan yang menunggu tampil di bilah atas.', ['laporan', 'Buka Laporan Warga']],
        ['bell', 'Menyampaikan peringatan ke warga', 'Di "Peringatan Dini" tiap peringatan punya tombol "Pesan untuk warga (salin)". Salin lalu tempel ke grup WhatsApp kampung.', ['peringatan', 'Buka Peringatan Dini']],
        ['flag', 'Wilayah rawan dan evakuasi', 'Lihat kampung berisiko tinggi di "Wilayah Rawan" dan titik kumpul di "Titik Aman dan Evakuasi". Gunakan "Kesiapsiagaan dan Mitigasi" sebagai daftar periksa.', ['wilayah-rawan', 'Buka Wilayah Rawan']],
        ['waves', 'Pasang surut', 'Di "Pasang Surut dan Prediksi" ada jam puncak air dan hari-hari tertinggi, dari prediksi resmi BIG.', ['pasut', 'Buka Pasang Surut']],
    ];
    $mh = [
        ['home', 'Ringkasan riset', 'Bobot parameter, kelengkapan data, dan alur pengolahan ada di menu Parameter dan Pembobotan serta Matriks Data.', ['parameter', 'Buka Parameter']],
        ['sliders', 'Parameter, matriks, dan hasil', 'Telusuri "Parameter dan Pembobotan", "Matriks Data", dan "Hasil Kerawanan". "Simulasi Skor" untuk mencoba nilai lain tanpa mengubah data.', ['parameter', 'Buka Parameter']],
        ['waves', 'Pasang surut BIG/SRGI', 'Analisis harmonik dari prediksi BIG 2024–2028, ambang persentil, dan unduhan CSV.', ['pasut', 'Buka Pasang Surut']],
        ['camera', 'Laporan warga', 'Dapat dilihat tanpa nama pelapor untuk riset kejadian nyata.', ['laporan', 'Buka Laporan Warga']],
    ];
    $ad = [
        ['bell', 'Status otomatis dan peringatan', 'Menu "Kelola Peringatan" menampilkan dasar status otomatis. Peringatan yang Anda terbitkan hanya dapat menaikkan status. Untuk menurunkan, pakai "Timpa status otomatis" (alasan wajib, maksimal 24 jam, tercatat).', ['peringatan', 'Buka Kelola Peringatan']],
        ['camera', 'Laporan warga', 'Verifikasi atau tolak laporan di "Laporan Warga". Admin juga dapat menghapus laporan beserta fotonya.', ['laporan', 'Buka Laporan Warga']],
        ['db', 'Data dan analisis', 'Kelola Wilayah, Data Parameter, Oseanografi, dan jalankan analisis dari menu terkait. Pemeriksaan kualitas data ada di menu Analisis Kerawanan.', ['beranda', 'Buka Dashboard']],
        ['users', 'Pengguna dan keamanan', 'Tambah akun perangkat desa dan nonaktifkan akun demo di "Pengguna". Matikan demo_mode di config.php untuk server nyata.', ['pengguna', 'Buka Pengguna']],
    ];
    return match ($role) { 'warga' => $w, 'perangkat_desa' => $pd, 'mahasiswa' => $mh, default => $ad };
}

/* Ringkasan 3 butir untuk kartu catatan. */
function panduan_catatan_butir(string $role): array {
    return match ($role) {
        'warga' => [['home', 'Lihat kotak besar di Status: warna dan kata Aman, Waspada, Siaga, atau Awas.'], ['pin', 'Pilih kampung Anda di "Wilayah saya" agar infonya langsung tampil.'], ['camera', 'Lihat genangan? Menu "Laporkan Genangan": foto, lokasi, kirim.']],
        'perangkat_desa' => [['camera', 'Periksa laporan warga di menu "Laporan Warga".'], ['bell', 'Salin pesan peringatan ke WhatsApp dari menu "Peringatan Dini".'], ['home', 'Status dihitung otomatis; dasarnya tampil di halaman Peringatan Dini.']],
        'mahasiswa' => [['home', 'Beranda memuat ringkasan data dan metode.'], ['waves', 'Pasut BIG/SRGI lengkap dengan unduhan CSV.'], ['sliders', 'Coba "Simulasi Skor" tanpa mengubah data.']],
        default => [['bell', 'Status otomatis dan penimpaan tercatat ada di "Kelola Peringatan".'], ['camera', 'Laporan warga menunggu verifikasi di menu "Laporan Warga".'], ['users', cfg('mode_presentasi', false) ? 'Akun demo aktif untuk presentasi. Matikan demo_mode di config.php sebelum dipakai sungguhan.' : 'Matikan demo_mode dan ganti kata sandi bawaan sebelum dipakai sungguhan.']],
    };
}

/* Notifikasi panduan setelah login (toast, bukan catatan yang menggantung). Hilang sendiri; tanda silang menyembunyikannya untuk seterusnya. */
function panduan_catatan_html(array $u): string {
    if (panduan_ditutup((int) $u['id_pengguna'])) return '';
    $nama = trim((string) $u['nama']); $nama = $nama !== '' ? mb_strtok($nama) : '';
    $pesan = match ($u['peran']) {
        'warga' => 'Lihat status di menu Peringatan, pilih kampung Anda, dan laporkan genangan lewat menu Lapor.',
        'perangkat_desa' => 'Periksa laporan warga dan salin pesan peringatan dari menu Peringatan.',
        'mahasiswa' => 'Data, metode, dan simulasi skor ada di menu sebelah kiri.',
        default => 'Status otomatis, laporan warga, dan pengelolaan data ada di menu sebelah kiri.' };
    return '<div class="toasts notif" aria-live="polite"><div class="toast info" role="status"><span class="toast-ic" aria-hidden="true">' . ic('book', 20) . '</span><div class="toast-b"><b>Selamat datang' . ($nama !== '' ? ', ' . e($nama) : '') . '</b><span>' . e($pesan) . '</span><a href="' . e(url('panduan')) . '">Buka panduan penggunaan</a></div>'
        . '<form method="post" action="index.php?p=panduan-note" class="toast-x">' . csrf_field() . '<input type="hidden" name="aksi" value="tutup"><input type="hidden" name="back" value="' . e($_SERVER['REQUEST_URI'] ?? '') . '"><button type="submit" aria-label="Tutup notifikasi dan jangan tampilkan lagi">' . ic('x', 16) . '</button></form></div></div>';
}
function mb_strtok(string $s): string { $p = preg_split('/\s+/u', trim($s)); return (string) ($p[0] ?? ''); }

/* Gambar bantu: arti status dengan warna aslinya. */
function panduan_arti_status(): string {
    $arti = ['Normal' => ['Aman', 'Tidak ada ancaman rob. Tetap pantau.'], 'Waspada' => ['Waspada', 'Ada kemungkinan genangan. Amankan barang di tempat rendah.'], 'Siaga' => ['Siaga', 'Rob cukup besar. Siapkan tas darurat dan dokumen, dengarkan perangkat desa.'], 'Awas' => ['Awas', 'Bahaya. Segera menuju titik kumpul bila diminta atau air naik.']];
    $h = '<div class="pandu-status">'; foreach ($arti as $k => [$judul, $isi]) { $m = status_meta()[$k]; $h .= '<div style="--c:' . e($m['c']) . '"><span class="pandu-chip">' . ic($m['icon'], 20) . e($judul) . '</span><p>' . e($isi) . '</p></div>'; } return $h . '</div>';
}

function panduan_kontak_html(): string {
    $k = cfg('kontak_darurat', []); if (!is_array($k) || !$k) return '<p class="faint" style="font-size:13.5px">Nomor perangkat desa belum diisi oleh administrator. Tanyakan kepada Ketua RT atau perangkat desa Anda.</p>';
    $h = '<div class="list">'; foreach ($k as $nama => $no) { $no = (string) $no; $tel = preg_replace('/[^0-9+]/', '', $no); $h .= '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center"><b>' . e((string) $nama) . '</b>' . ($tel !== '' ? '<a class="btn btn-sm" href="tel:' . e($tel) . '">' . ic('link', 15) . e($no) . '</a>' : '<span>' . e($no) . '</span>') . '</div>'; } return $h . '</div>';
}
