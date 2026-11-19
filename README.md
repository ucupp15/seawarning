# SEA WARNING: Sistem Peringatan Dini Banjir Rob Pulo Panjang (versi PHP)

Aplikasi web **PHP + HTML + CSS** dengan basis data **MySQL/MariaDB** (XAMPP). Seluruh halaman dirender di server oleh PHP.
Satu-satunya JavaScript adalah skrip kecil `assets/js/satmap.js` untuk **peta satelit Esri** (peta interaktif memang
memerlukannya). Semua halaman lain, termasuk grafik dan peta skematik (SVG), tidak memakai JavaScript.

> **Status data.** Pasang surut kini memakai **data resmi BIG/SRGI** (lihat di bawah). Nilai parameter lain, riwayat rob,
> serta gelombang/angin/arus masih contoh atau simulasi dan ditandai demikian di antarmuka.
> Titik evakuasi berstatus **usulan**, belum ditetapkan BPBD.

## Yang baru di v13: panduan penggunaan (`includes/panduan.php`, `pages/panduan.php`)
* Menu **Panduan Penggunaan** untuk semua peran. Isi disesuaikan: warga paling lengkap (arti status berwarna, 8 langkah
  bernomor dengan tombol pintas, "Bila air mulai naik", kontak perangkat desa, dan pertanyaan yang sering muncul);
  perangkat desa, mahasiswa, dan admin lebih ringkas. Teksnya ada di fungsi `panduan_langkah()`; ubah di sana.
* **Kartu catatan** (seperti sticky note) tampil di beranda setelah login. Tanda silang menutupnya; pilihan tersimpan **di server
  per pengguna** (`storage/panduan.json`), jadi tetap tertutup di perangkat lain. Panduan tetap ada di menu, dan halaman
  Panduan punya tombol "Tampilkan lagi catatan di beranda". Naikkan `PANDUAN_VERSI` bila panduan diperbarui agar catatan muncul lagi sekali.
* Tanpa JavaScript, ukuran sentuh tombol silang 44 px, nyaman di HP, dan ikut skin Kontras tinggi.
* **Kontak darurat** tidak dikarang: isi `'kontak_darurat' => ['Ketua RT 01' => '0812...']` di `config.php` agar tampil (dengan tombol telepon) di panduan warga.

## Yang baru di v12

### 1. Status peringatan otomatis (`includes/status.php`)
Status **Normal / Waspada / Siaga / Awas** yang dilihat semua pengguna kini **dihitung server sendiri** pada setiap
permintaan, dari tiga sumber, lalu diambil yang tertinggi:

| Sumber | Data yang dipakai | Aturan |
|---|---|---|
| **BIG/SRGI** (pemerintah) | Prediksi pasang surut per jam di `data/pasut_big/`, puncak 24 jam ke depan | ≥ P98 data BIG (0,373 m) → Waspada; ≥ P99,5 → Siaga |
| **BMKG** (pemerintah) | Peringatan dini cuaca resmi (CAP nowcast) yang aktif dan menyebut Puloampel | Ringan/Sedang → Waspada; Parah/Ekstrem → Siaga |
| **Laporan warga** | Laporan genangan yang **sudah diverifikasi** admin/perangkat desa, 6 jam terakhir | 1 laporan → Waspada; ≥ 2 laporan atau air ≥ 30 cm → Siaga; air ≥ 50 cm → Awas |

Pasut BIG sudah Siaga **dan** ada laporan terverifikasi atau BMKG Parah/Ekstrem → **Awas**. Pasang astronomis saja
tidak pernah menaikkan status ke Awas (lihat kasus 7 Okt 2025 di bawah: rob dapat terjadi saat pasut biasa, karena itu
laporan warga dipakai sebagai sumber ketiga). Ambang dan jendela waktu ada sebagai konstanta di bagian atas `includes/status.php`.

* Peringatan yang diterbitkan administrator tetap ada dan **hanya dapat menaikkan** status.
* Untuk **menurunkan** status otomatis (misalnya laporan ternyata keliru) administrator memakai *Timpa status otomatis*
  di menu Kelola Peringatan: wajib alasan, paling lama 24 jam, dan tercatat.
* Setiap perubahan status dan setiap penimpaan dicatat di `storage/status_log.json` dan tampil di menu Kelola Peringatan.
* Dasar penentuan status tampil terbuka di menu Peringatan Dini untuk semua peran.
* Bila berkas BIG tidak mencakup waktu sekarang (setelah 2028) atau BMKG tidak terjangkau, komponen itu tercatat
  "tidak tersedia" dan status hanya dihitung dari sumber yang ada.

### 2. Laporan warga (`includes/laporan.php`, `pages/laporan.php`, `assets/js/lapor.js`)
* Warga: menu **Laporkan Genangan**. Foto (wajib), lokasi (tombol *Pakai lokasi saya* dengan GPS ponsel, ketuk peta,
  atau pilih kampung), perkiraan tinggi air (semata kaki sampai sepinggang), dan keterangan. Warga melihat status laporannya.
* Admin dan perangkat desa: menu **Laporan Warga** berisi peta, foto, dan tombol Verifikasi/Tolak (alasan wajib bila menolak).
  Mahasiswa melihat tanpa nama pelapor. Jumlah laporan yang menunggu tampil di bilah atas.
* Keamanan: hanya JPEG/PNG/WebP yang diperiksa dari isi berkas, maksimal 8 MB; foto digambar ulang dengan GD (maks 1600 px)
  sehingga metadata EXIF dan isi tersembunyi terbuang; berkas disimpan dengan nama acak di `storage/laporan/` (tertutup
  `.htaccess`) dan hanya dikirim lewat `index.php?p=foto` setelah pemeriksaan hak akses; CSRF; batas 3 laporan per
  30 menit dan 10 per hari per akun warga; koordinat di luar Pulo Panjang ditolak.
* Di ponsel, foto besar diperkecil di peramban sebelum diunggah agar hemat kuota.
* **GPS ponsel hanya berfungsi lewat `https://` atau `localhost`** (aturan peramban). Lewat `http://192.168.x.x` di
  jaringan lokal tombol GPS disembunyikan; warga tetap dapat mengetuk peta atau memilih kampung.
* Basis data lama: tabel `laporan_warga` dibuat otomatis pada permintaan pertama. Bila pengguna MySQL tidak berhak
  CREATE TABLE, minta administrator server memberi hak itu. Jangan impor `database/database.sql` pada basis data yang sudah berisi data: berkas itu menghapus tabel lama.

### 3. Perbaikan dari audit v11
* `demo_mode` bawaan kini **false**. Untuk presentasi di komputer sendiri isi `true` di `config.php`; selama menyala,
  dashboard admin menampilkan peringatan merah. Pemeriksaan kualitas data juga mengingatkan bila akun demo masih
  memakai kata sandi bawaan.
* Grafik dan angka pasang surut di dashboard admin, perangkat desa, dan mahasiswa kini dibaca **langsung dari berkas BIG**,
  sehingga tidak lagi hilang dua sampai tiga hari setelah pemasangan.
* Pembatasan percobaan login dicatat di server per nama pengguna dan per IP (`storage/login_gagal.json`), tidak lagi di sesi.
* Parameter `back` tidak lagi dapat dipakai untuk mengalihkan ke situs lain.

## Data pasang surut BIG/SRGI (2024–2028)

Berkas `data/pasut_big/data_pasut<tahun>.txt` adalah **salinan apa adanya** dari produk *Prediksi Pasang Surut*
Badan Informasi Geospasial, Direktorat Sistem Referensi Geospasial (srgi@big.go.id), pada titik
**−5,93014699 ; 106,15350779**. Jangan menyunting berkas ini. Seluruhnya 43.848 baris per jam, 2024–2028, lengkap
tanpa baris kembar maupun lompatan waktu. Waktu di berkas **UTC**; aplikasi menampilkannya dalam **WIB**.
Datum: tinggi muka air **relatif MSL**, karena itu nilainya dapat negatif saat surut.

Menu **Pasang Surut BIG/SRGI** menghitung sendiri dari berkas tersebut, bukan menampilkan angka yang sudah jadi:

- **Analisis harmonik least-squares** pada model z(t) = Z₀ + Σ A·cos(σt − g), diselesaikan dengan eliminasi Gauss berpivot.
- **Komponen tahunan** M2, S2, N2, K2, K1, O1, P1, Q1. Sa sengaja **tidak** dipakai: pada rekaman satu tahun
  8.760 × 0,0410686° = 359,8° < 360°, jadi Sa tidak terpisah dari Z₀ menurut kriteria Rayleigh dan membuat matriks
  normal ill-conditioned. M4, MS4, M6, Mf, Mm, Ssa juga tidak dipakai karena amplitudonya < 0,00001 m pada data ini.
- **Komponen bulanan** M2, S2, N2, K1, O1, Q1; K2 dan P1 diisi dengan rasio inferensi baku (0,27215 × S2 dan
  0,33093 × K1) karena tidak terpisah pada rekaman satu bulan.
- **Bilangan Formzahl** F = (A<sub>K1</sub> + A<sub>O1</sub>) / (A<sub>M2</sub> + A<sub>S2</sub>) untuk tipe pasut,
  dihitung per bulan, per tahun, dan untuk gabungan lima tahun.
- **Ambang rob** dari persentil empiris seluruh periode (Normal P90, Waspada P98, Siaga P99,5, Bahaya P99,9),
  bukan angka pilihan.
- Tahun dan bulan dipotong menurut **WIB**; jumlah jam tiap blok dilaporkan apa adanya pada panel pemeriksaan mutu.

Hasil pokok: **F = 1,6677 → Campuran Condong Harian Tunggal**, R² 98,81%, muka air tertinggi **+0,506 m** (6 Des 2025)
dan terendah **−0,417 m** (8 Nov 2025).

Implementasi PHP di `includes/pasut.php` sudah diuji sama dengan implementasi Python pembanding di
`tools/analisis_pasut.py` sampai selisih 5 × 10⁻⁷ pada seluruh tahun dan seluruh bulan.

### Batas pemakaian yang penting

Angka di menu itu adalah pasang surut **astronomis**. Banjir rob juga dipengaruhi gelombang, angin, tekanan udara,
dan anomali muka laut yang **tidak termuat** dalam prediksi BIG. Pengujian terhadap kejadian nyata menegaskannya:
pada **7 Oktober 2025**, ketika Radar Banten melaporkan ratusan rumah di Puloampel terendam rob, prediksi BIG untuk
hari itu hanya **+0,223 m** (persentil 28, jauh di bawah ambang Waspada 0,373 m). Sistem yang hanya memantau pasang
astronomis akan melewatkan kejadian itu. Karena itu data pasut **melengkapi**, bukan menggantikan, skor kerawanan
berbobot dan data angin/gelombang BMKG.

## Menu Evakuasi

Menu **Titik Aman dan Evakuasi** menyajikan prioritas evakuasi tiap kampung, usulan titik aman, jalur, kebutuhan
penampungan, dan rekomendasi. Seluruh titik berstatus **USULAN** dan belum ditetapkan BPBD Kabupaten Serang maupun
Pemerintah Desa Pulo Panjang.

Titik aman dihitung dari data yang ada, bukan dikarang: titik di dalam tiap kampung yang **jarak terdekatnya ke garis
pantai paling besar** (*pole of inaccessibility*), dicari dengan grid bertingkat 25 m hingga ketelitian ±0,1 m, dengan
jarak eksak ke seluruh segmen garis pantai digitasi pada proyeksi lokal meter. Skrip: `tools/geometri_evakuasi.py`
dan `tools/susun_evakuasi.py`, keluarannya `data/evakuasi.json`.

Hasil hitungan geometri pulau: luas **6,7559 km²** (675,6 ha), panjang garis pantai **11,715 km**, titik terdalam
**1.264 m** dari pantai (berada di Kampung Pasir Putih, yang menurut data wilayah adalah pusat administrasi desa).

**Yang sengaja dibiarkan kosong.** Kolom elevasi pada tiap titik evakuasi **tidak diisi**. Yang tersedia hanya
elevasi DEMNAS tingkat kampung di tabel `data_parameter`; mengisinya dengan perkiraan akan membuat usulan tampak
lebih pasti daripada kenyataannya. Begitu pula kapasitas tampung. Daftar lengkap yang masih harus disurvei ada di
halaman itu sendiri.

Asumsi perencanaan yang dipakai, seluruhnya dicantumkan di antarmuka: 4 jiwa/KK (belum ada data kantor desa),
3,5 m²/jiwa (Sphere Handbook), laju jalan 0,75 m/s, faktor liku jalur 1,3.

## Kebutuhan
- XAMPP dengan **PHP 8.1 atau lebih baru** (Apache + MySQL/MariaDB).
- Ekstensi PHP (aktif secara bawaan di XAMPP): `pdo_mysql`, `mbstring`, `dom`, `curl` atau `openssl`.

## Pemasangan di XAMPP (Windows)
1. Salin folder `sea-warning-php` ke `C:\xampp\htdocs\`.
2. Buka XAMPP Control Panel, jalankan **Apache** dan **MySQL**.
3. Buka **http://localhost/sea-warning-php/**. Anda akan dialihkan ke `install.php`; klik **Pasang sekarang**.
   Pemasang membuat basis data `sea_warning`, 15 tabel sesuai ERD, dan data awal: 7 kampung Pulo Panjang,
   7 titik dan 7 jalur evakuasi, serta penyelarasan parameter pasang surut dengan data BIG.
4. Masuk dengan akun demo di bawah. Setelah selesai, **hapus `install.php`** dari server.

Cara lain (tanpa pemasang): buka phpMyAdmin, buat basis data `sea_warning`, lalu impor
`database/database.sql` (skema 15 tabel + data contoh).

### Memutakhirkan basis data yang sudah terpasang

Bila aplikasi **sudah terpasang** pada versi sebelumnya, jangan jalankan ulang `install.php`. Lakukan ini:

1. Salin folder `data/pasut_big/` berisi berkas BIG ke server.
2. Jalankan `php tools/migrasi.php` dari folder proyek, atau buka `tools/migrasi.php` di peramban.
   Skrip ini mengisi titik dan jalur evakuasi, menyelaraskan parameter pasang surut dengan data BIG,
   membangkitkan ulang data oseanografi, lalu menjalankan ulang analisis kerawanan. Aman dijalankan berulang kali.
3. **Hapus `tools/migrasi.php`** setelah selesai.

Sumber berita Radar Banten (banjir rob 7 Okt 2025) dapat ditambahkan manual di menu Sumber Data (administrator).

Pengaturan koneksi ada di `config.php` (bawaan XAMPP: pengguna `root`, tanpa kata sandi). Untuk server sungguhan,
buat pengguna MySQL khusus dan isi kata sandinya. Bila tidak ingin memakai MySQL, ubah `'driver'` menjadi `'sqlite'`.

## Akun demo
| Peran | Nama pengguna | Kata sandi |
|---|---|---|
| Administrator | admin | admin123 |
| Mahasiswa/Peneliti | mahasiswa | mahasiswa123 |
| Perangkat Desa | perangkat | perangkat123 |
| Warga | warga | warga123 |

Tombol "Coba langsung sebagai" di halaman login hanya muncul bila `'demo_mode' => true` di `config.php` (bawaan v12: `false`).
**Sebelum dipakai sungguhan:** biarkan `demo_mode` false, ganti semua kata sandi, dan hapus atau nonaktifkan akun demo di menu Pengguna.

## Struktur
```
index.php            pintu masuk tunggal (router, hak akses, aksi global)   install.php   pemasang
config.php           pengaturan basis data dan aplikasi
includes/            logika: db, auth, calc (skor kerawanan), pasut (analisis harmonik BIG), evakuasi,
                     svg (grafik dan peta), bmkg, crud, layout, seed, data.json
pages/               halaman: login, beranda (4 peran), umum, riset, admin, pasut, evakuasi
assets/              css/style.css, js/satmap.js + leaflet.js, media (video login, musik, logo)
database/            database.sql (skema dan data contoh, satu berkas)
data/                geometri 7 kampung, daftar koordinat, garis pantai hasil digitasi,
                     evakuasi.json (hasil hitungan), pasut_big/ (berkas BIG apa adanya)
storage/             cache BMKG, cache analisis pasut, dan pengaturan (dilindungi .htaccess)
tools/generate_sql.php      membuat ulang berkas SQL dari basis data aktif
tools/migrasi.php           memutakhirkan basis data yang sudah terpasang
tools/analisis_pasut.py     analisis harmonik pembanding (Python), untuk memverifikasi hasil PHP
tools/geometri_evakuasi.py  hitungan geometri pulau dan kandidat titik aman
tools/susun_evakuasi.py     menyusun data/evakuasi.json dari dua masukan di atas
```

## Fitur
- **4 peran** dengan menu berbeda: Administrator (16 menu), Mahasiswa (13), Perangkat Desa (12), Warga (11).
- **15 tabel dan 21 relasi** sesuai ERD (lihat menu Skema Database). CRUD lengkap untuk administrator: cari, urut, filter, halaman,
  validasi, dan penolakan hapus bila data masih dipakai.
- **Pasang surut BIG/SRGI 2024–2028**: analisis harmonik, tipe pasut per bulan dan per tahun, matriks perbandingan
  tahun × bulan, pembanding bulan sebelumnya/sesudahnya dan bulan yang sama antar tahun, daftar hari tertinggi, unduh CSV.
- **Titik aman dan evakuasi**: prioritas per kampung, usulan titik aman hasil hitungan geometri, jalur, kebutuhan
  penampungan, peta prioritas, rekomendasi, dan pengelolaan data oleh administrator.
- **Skoring kerawanan berbobot**: skor total = Σ(bobot × skor parameter) ÷ Σ bobot; tingkat Rendah/Sedang/Tinggi dari rentang skor.
- **Peta skematik** (SVG dari GeoJSON, 7 kampung, bentuk pulau nyata) dan **peta satelit Esri** (butuh internet) dengan titik kampung.
- **Cuaca BMKG untuk Pulo Panjang** (kartu cuaca di beranda dan chip suhu di bilah atas semua halaman): kondisi, suhu, kelembapan, angin,
  tutupan awan, dan tiga slot berikutnya, memakai slot prakiraan terdekat dengan waktu sekarang. Halaman beranda dan tab Cuaca BMKG
  memuat ulang otomatis tiap 15 menit (HTML murni). Status cuaca berasal dari BMKG saja (peringatan dini CAP, hujan lebat/petir) dan
  **bukan** status banjir rob: rob ditentukan pasang surut dan fase bulan (mis. supermoon), bukan kecepatan angin.
- **Data BMKG** (cuaca per desa dan peringatan dini CAP) diambil langsung oleh server, dengan cache. Admin dapat menerapkan angin BMKG ke
  analisis, mengaktifkan penerapan otomatis (berjalan di server), dan menjadikan peringatan BMKG sebagai draf.
- **Lapisan peta**: peta dapat diwarnai menurut tingkat kerawanan total atau menurut skor satu parameter (11 parameter). Satu klik pada tombol
  lapisan langsung mengganti peta; tiap kampung menampilkan nilai datanya, dengan legenda kelas skor dan tabel nilai per kampung.
- 5 tampilan (Otomatis, Terang, Gelap, Samudra, Kontras tinggi), diatur lewat cookie. Menu ponsel dan pemilih tampilan tanpa JavaScript.
- Unduh CSV (wilayah rawan, matriks, hasil, oseanografi) dan cadangan JSON basis data.

## Tampilan
- Skin **Otomatis**, **Terang**, **Gelap**, **Samudra**, dan **Kontras tinggi** (menu ikon di pojok kanan atas atau menu Akun dan Tampilan).
- Semua warna ada sebagai variabel CSS di bagian atas `assets/css/style.css`; ubah di sana untuk menyesuaikan.
- Warna tingkat kerawanan (Rendah/Sedang/Tinggi) tersimpan di tabel `tingkat_kerawanan` dan dapat diubah administrator di menu Tingkat dan Tindakan.

## Keamanan
Hak akses diperiksa **di server** pada setiap permintaan; kata sandi disimpan dengan `password_hash`; semua query memakai
prepared statement; keluaran di-escape; formulir dilindungi token CSRF; login dibatasi 5 percobaan lalu dikunci 30 detik (berlipat sampai 15 menit);
sesi diregenerasi saat login. Sejak v12 pembatasan login dicatat di server per nama pengguna dan per IP. Folder `includes/`, `pages/`, `storage/`, `data/`, `database/`, `tools/` dilindungi `.htaccess`.
Untuk server publik gunakan HTTPS dan matikan `display_errors` di `php.ini`.

## Catatan data dan keterbatasan
- BMKG menyediakan **prakiraan 3-jam-an yang dimutakhirkan 2 kali sehari**, bukan pengamatan langsung. API-nya memerlukan **kode desa
  tingkat IV (adm4)**: Pulo Panjang `36.04.08.2009` (kode kecamatan `36.04.08` tidak berlaku). Aplikasi mencocokkan nama desa dari respons
  BMKG dan memberi peringatan bila kode mengarah ke desa lain. Batas akses BMKG: 60 permintaan/menit/IP (cache 15 menit jauh di bawahnya).
- **Pasang surut sudah memakai data resmi BIG/SRGI.** Tinggi gelombang, angin, dan arus **tidak tersedia** lewat API
  terbuka BMKG sehingga masih simulasi; setiap besaran diberi penanda asal datanya sendiri di kartu kondisi.
- Batas kelas skor parameter **Pasang surut** diturunkan dari persentil data BIG (P90 = 0,248 m dan P98 = 0,373 m),
  menggantikan batas bawaan prototipe yang disusun untuk data simulasi berdatum lain. Nilainya **sama untuk seluruh
  kampung** (0,569 m, HHWL gabungan), karena prediksi BIG berasal dari satu titik dan pasang surut praktis tidak
  berbeda antar kampung pada pulau seluas 6,8 km². Yang membedakan kerawanan antar kampung adalah elevasi,
  kemiringan, jarak ke pantai, mangrove, dan perubahan garis pantai.
- Titik prediksi BIG (−5,930147 ; 106,153508) ternyata berada **di daratan pulau**, sekitar 1.144 m dari garis pantai.
  Nilainya tetap sah sebagai gambaran pasut perairan sekitar karena amplitudo hampir tidak berubah pada jarak beberapa
  kilometer, tetapi untuk keperluan berketelitian tinggi sebaiknya data diambil ulang pada titik di perairan.
- Titik kampung dari daftar koordinat pengguna. Garis pantai hasil digitasi perkiraan; batas antar kampung perkiraan
  (pembagian menurut titik terdekat). KK dan jiwa per kampung memakai Sensus Registrasi Penduduk Pemerintah Kabupaten Serang (`data/penduduk.json`; total 1.100 KK, 3.500 jiwa).
- Sambungan ke BMKG sungguhan perlu internet di server. Tanpa itu, panel BMKG menampilkan pesan "Belum tersambung".
- Pelatihan lebih lanjut: menu Wilayah > Impor GeoJSON untuk batas resmi; menu Pengguna untuk akun nyata.

## Pemecahan masalah
- *"SQLSTATE... Connection refused"*: MySQL di XAMPP belum dijalankan.
- *"Access denied"*: sesuaikan `user`/`pass` di `config.php`.
- Halaman kosong: aktifkan `display_errors` sementara di `php.ini` untuk melihat pesan galat.
- Peta satelit abu-abu: komputer tidak terhubung ke internet atau diblokir; peta Skematik tetap berfungsi.


## Peta evakuasi (menu Titik Aman dan Evakuasi, tab Peta evakuasi)

Peta interaktif di atas citra Esri: zonasi keselamatan 7 peringkat (mengikuti legenda peta rujukan: hijau, hijau terang, kuning-oranye, merah muda, merah tua),
sabuk rob pesisir sampai 100 m dari garis pantai digitasi, arah evakuasi dari lima titik pesisir ke zona aman utama, dan titik kumpul usulan tiap kampung.
Pengguna warga melihat peta yang sama dengan rincian yang disederhanakan.

* Berkas: `assets/js/evakmap.js`, `data/peta_evakuasi.json` (dibuat oleh `tools/peta_evakuasi.py`), fungsi `evak_peringkat()` dan `evak_peta_html()` di `includes/evakuasi.php`.
* **Peringkat 1 (zona aman utama)** diatur oleh konstanta `EVAK_ZONA_UTAMA` di `includes/evakuasi.php` (bawaan `'Penengahan'`, mengikuti peta rujukan penelitian). Isi `''` agar peringkat 1 murni mengikuti hasil analisis. Peringkat 2 sampai 7 selalu dari urutan prioritas hasil analisis (skor kerawanan, elevasi, jumlah KK).
* Sabuk pesisir adalah zona jarak, bukan model genangan. Arah evakuasi bersifat indikatif karena data jalan belum ada. Posisi Pantai Munir, Tanjung Saga, Pantai Ferry, Pantai Kelapa Enom, dan Jetty adalah perkiraan dari arah mata angin dan perlu diverifikasi lapangan.

* **Zona hasil survei lapangan** diatur oleh konstanta `EVAK_ZONA_SURVEI` di `includes/evakuasi.php` (bawaan: Peres, Pasir Putih, Sukadiri = merah; Sukarela = kuning; diverifikasi RT setempat). Isian ini menimpa zona hasil hitungan pada peta evakuasi saja; skor di menu Analisis Kerawanan tidak berubah. Kosongkan array agar kembali ke zonasi murni dari data.

## Pemutakhiran basis data lama (otomatis)
`includes/database.php` (bagian pemutakhiran) dijalankan pada setiap permintaan oleh `index.php` dan hanya mengubah data bila perlu:
1. Nama Kampung Ragas menjadi Kampung Penengahan.
2. Posisi baku: Penengahan di tengah (5,93167° LS · 106,15306° BT), Pasir Putih di bawahnya (5,93350° LS · 106,15280° BT).
3. Nilai survei lapangan, sekali saja (penanda `storage/survei_v5.done`): Penengahan = Rendah; Sukarela = Sedang;
   Pasir Putih, Peres, Sukadiri = Tinggi. Setelah itu nilai bebas diubah lewat menu Data Parameter.

Untuk menjalankan manual: `php tools/ganti_nama_kampung.php` (nama dan posisi) dan `php tools/terapkan_survei.php` (nilai survei).

## Data KK dan jiwa (Sensus Registrasi Penduduk Kab. Serang)
Berkas `data/penduduk.json` memuat KK, jiwa, dan kepadatan tiap kampung (total 1.100 KK, 3.025 jiwa; jiwa per kampung disesuaikan proporsional ke total desa). Pasang baru langsung memakainya. Untuk basis data yang sudah terpasang, jalankan sekali `tools/terapkan_kk.php`. Jumlah jiwa di menu Titik Aman dan Evakuasi dibaca dari berkas ini, bukan lagi asumsi 4 jiwa/KK.

## Data penduduk desa
`data/penduduk.json` juga memuat total desa **1.113 KK** (Rekap DTSEN) dan **3.025 jiwa** (total penduduk desa, sama dengan
tabel **kelompok usia** per jenis kelamin dari papan data desa). Sejak v22 angka jiwa DTSEN 3.589 tidak dipakai lagi. Rincian per kampung tetap dari Sensus Registrasi Penduduk
karena DTSEN tidak memuat rincian per kampung.

## Halaman login
Label kampung pada `assets/media/login_loop.mp4` dan `poster.jpg` sudah memakai nama dan posisi terbaru.

## Peta evakuasi
Warna kampung pada peta Titik Aman dan Evakuasi mengikuti tingkat kerawanan, sama dengan Peta Zona Kerawanan.
Angka 1–7 adalah peringkat keselamatan evakuasi.

## Struktur kode
| Folder / berkas | Isi |
|---|---|
| `index.php` | Pintu masuk dan pemeriksaan hak akses |
| `config.php` | Koneksi basis data dan pengaturan |
| `includes/` | Logika: basis data (`database.php`: skema, koneksi, data awal, pemutakhiran), perhitungan (`calc.php`), tampilan bersama (`layout.php`, `svg.php`), beranda (`dashboard.php`) |
| `pages/` | Satu berkas per kelompok halaman |
| `assets/` | CSS, JavaScript peta, media |
| `data/` | Data rujukan (JSON, pasang surut BIG) |
| `tools/` | Skrip baris perintah untuk pemeliharaan |

## Versi 14: tampilan baru

- Identitas visual baru: kertas hangat + biru laut tua, judul berhuruf serif, tanpa font dari internet (tetap rapi saat offline).
- Beranda: kata status besar, tiga dasar penentuan status, alat ukur pasang surut animasi, papan kondisi kampung (siluet + batang skor), pita "Siapa yang terdampak".
- Grafik pasang surut memakai garis ambang Waspada/Siaga. Tabel Hasil Kerawanan, Wilayah Rawan, dan Riwayat memakai batang skor/tinggi.
- Halaman Oseanografi menampilkan tiga grafik mini (gelombang, angin, arus), bertanda Simulasi.
- Akun demo: `config.php` berisi `demo_mode => true` dan `mode_presentasi => true` (tombol demo tetap ada, spanduk merah disembunyikan). Untuk pemakaian sungguhan, ubah keduanya menjadi `false`.

## Versi 15: tampilan umum dan lebih ringkas

- Tampilan dikembalikan ke gaya web pada umumnya: sidebar gelap, kartu putih, tabel standar, huruf sistem.
- Beranda: kartu status (status, tiga dasar penentuan, tinggi air laut sekarang), ringkasan penduduk terdampak, tabel kondisi kampung dengan batang skor, peta, grafik pasang surut.
- Dihapus: papan duga pasang surut animasi, papan kampung bersiluet, pita dampak, piramida penduduk, grafik sebaran (donat) dan "Pengguna per peran" di beranda, grafik mini Oseanografi, kartu penjelasan tingkat di beranda warga, tabel kampung di beranda warga dan mahasiswa, profil desa di beranda perangkat desa.
- Akun demo tetap aktif (`demo_mode` dan `mode_presentasi` true). Ubah keduanya menjadi `false` untuk pemakaian sungguhan.

## Versi 16: beranda ringkas

Beranda semua peran kini hanya berisi: kartu status, zona kerawanan (peta dan daftar kampung per zona), Menu cepat (ikon yang dapat diklik ke menu lain sesuai peran), Mengenal Pulo Panjang, dan Tujuan web ini. Grafik pasang surut, tabel kampung, ringkasan penduduk, pemeriksaan data (hanya tampil bila ada yang bermasalah, untuk admin), dan sinkronisasi BMKG tidak lagi di beranda; BMKG tetap ada di Kelola Peringatan.

## Versi 17: dashboard Pulo Panjang

Dashboard (sama untuk semua peran) hanya berisi informasi tentang Pulo Panjang dan tujuan web: hero dengan video latar yang sudah ada di project, angka ringkas yang menghitung naik, penduduk tiap kampung dan menurut usia, linimasa tujuan, serta sumber data. Bagian muncul saat digulir (dimatikan otomatis bila pengguna memilih gerak dikurangi). Tidak ada font atau sumber dari internet. Status banjir rob ada di menu Peringatan, dan tanda peringatan tetap muncul di bagian atas semua halaman saat status naik.

## Versi 18

Dashboard mendapat bagian "Menu pendukung": ikon pintasan ke semua menu sesuai peran, tepat di bawah angka ringkas.

## Versi 19

- Panduan setelah login kini berupa notifikasi (toast) di pojok kanan atas, bukan catatan yang menggantung. Hilang sendiri; tanda silang menyembunyikannya untuk seterusnya (bisa ditampilkan lagi dari menu Panduan).
- Kolom Warna di Tingkat dan Tindakan menampilkan nama warna (Hijau, Kuning, Merah) dengan kotak warna, tanpa kode heksadesimal.

## Versi 20

Peta Evakuasi: daftar Legenda di panel kanan dihapus (sudah ada di peta, tombol Legenda). "Cara membaca peta" kini tepat di bawah peta dan "Batasan data" di bawah daftar peringkat, sehingga tidak ada ruang kosong.

## Versi 21

Dihapus: kartu "Pemeriksaan mutu data" (Pasang Surut per tahun), kartu "Batasan data" (Peta Evakuasi), kartu "Konstanta harmonik gabungan" dan "Muka air acuan" serta "Sumber data, metode, dan pemeriksaan mutu" (Pasang Surut gabungan). "Cara membaca peta" kini tiga kolom di bawah peta dan daftar peringkat. Kartu Jiwa di Dashboard memakai total papan penduduk menurut usia (3.025).
