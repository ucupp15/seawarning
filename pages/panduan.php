<?php
declare(strict_types=1);
/* Halaman Panduan Penggunaan (semua peran) dan aksi kartu catatan. */

function pg_panduan_post(): void {
    $u = current_user(); $aksi = (string) pv('aksi');
    if ($aksi === 'tampilkan') { panduan_set((int) $u['id_pengguna'], false); flash('Catatan panduan akan tampil lagi di beranda.'); }
    redirect(url('panduan'));
}
function pg_panduan(): array {
    $u = current_user(); $role = $u['peran']; $warga = $role === 'warga'; $ditutup = panduan_ditutup((int) $u['id_pengguna']);
    $h = page_head('Panduan Penggunaan', $warga ? 'Cara memakai web ini, langkah demi langkah. Tidak perlu hafal; buka halaman ini kapan saja.' : 'Ringkasan cara memakai web ini sesuai peran ' . mb_strtolower(roles()[$role]['label']) . '.');
    if ($warga) $h .= '<div class="panel" style="margin-bottom:18px"><div class="panel-h"><div><h2>Arti status</h2><p>Warna dan kata ini tampil di halaman Status Banjir Rob.</p></div></div>' . panduan_arti_status() . '</div>';
    $h .= '<div class="pandu-list">'; $i = 0;
    foreach (panduan_langkah($role) as [$ik, $judul, $isi, $link]) { $i++;
        $h .= '<article class="pandu-step"><div class="pandu-no" aria-hidden="true">' . $i . '</div><div class="pandu-body"><h3><span class="pandu-ic" aria-hidden="true">' . ic($ik, 22) . '</span>' . e($judul) . '</h3><p>' . e($isi) . '</p>' . ($link && page_allowed($role, $link[0]) ? '<a class="btn btn-sm" href="' . e(url($link[0])) . '">' . e($link[1]) . ic('chevr', 14) . '</a>' : '') . '</div></article>';
    }
    $h .= '</div>';
    if ($warga) {
        $h .= '<div class="grid g2" style="margin-top:18px;align-items:start"><div class="panel"><div class="panel-h"><h2>Bila air mulai naik</h2></div><ol class="pandu-ol"><li>Tetap tenang. Jangan berjalan atau berkendara melewati air yang mengalir atau dalam.</li><li>Matikan listrik di rumah bila air akan masuk. Angkat barang penting ke tempat tinggi.</li><li>Bawa tas darurat: dokumen, obat, senter, air minum, ponsel dan pengisi daya.</li><li>Ajak anak, orang tua, dan tetangga yang sulit berjalan.</li><li>Menuju titik kumpul kampung Anda dan lapor kepada perangkat kampung.</li><li>Bila aman, kirim laporan foto lewat menu Laporkan Genangan.</li></ol></div>'
            . '<div class="panel"><div class="panel-h"><h2>Menghubungi perangkat desa</h2></div><p class="muted" style="font-size:14px;margin-bottom:10px">Dalam keadaan mendesak, hubungi langsung Ketua RT, perangkat kampung, atau petugas desa. Web ini tidak menggantikan peringatan resmi pemerintah.</p>' . panduan_kontak_html() . '</div></div>';
        $h .= '<div class="panel" style="margin-top:18px"><div class="panel-h"><h2>Pertanyaan yang sering muncul</h2></div><div class="list">'
            . '<details><summary><b>Saya lupa kata sandi.</b></summary><p class="muted">Minta perangkat desa atau administrator mengatur ulang kata sandi Anda di menu Pengguna. Setelah masuk, ganti di Akun dan Tampilan.</p></details>'
            . '<details><summary><b>Tombol "Pakai lokasi saya" tidak ada atau tidak bisa.</b></summary><p class="muted">Lokasi ponsel hanya bisa dipakai bila web dibuka lewat alamat https:// dan Anda mengizinkan lokasi. Bila tidak bisa, cukup ketuk peta di tempat genangan atau pilih nama kampung.</p></details>'
            . '<details><summary><b>Peta satelit abu-abu.</b></summary><p class="muted">Peta satelit memerlukan internet. Bila sinyal lemah, garis kampung tetap tampil dan Anda tetap bisa memilih kampung.</p></details>'
            . '<details><summary><b>Status di web berbeda dengan kabar dari perangkat desa.</b></summary><p class="muted">Ikuti arahan perangkat desa dan pihak berwenang. Status di web dihitung otomatis dari data dan bisa terlambat atau meleset.</p></details>'
            . '<details><summary><b>Laporan saya belum berubah statusnya.</b></summary><p class="muted">Laporan perlu diperiksa dulu oleh perangkat desa. Status "Menunggu" berarti belum diperiksa. Anda boleh membatalkannya selama masih menunggu.</p></details></div></div>';
    }
    if ($ditutup) $h .= '<form method="post" action="' . e(url('panduan')) . '" style="margin-top:18px">' . csrf_field() . '<input type="hidden" name="aksi" value="tampilkan"><button class="btn" type="submit">' . ic('book', 16) . 'Tampilkan lagi catatan di beranda</button></form>';
    return ['title' => 'Panduan Penggunaan', 'html' => $h];
}
