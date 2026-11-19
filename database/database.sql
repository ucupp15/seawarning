-- SEA WARNING: skema 15 tabel sesuai ERD + data contoh
-- Dibuat: 2026-11-18T20:20:40+07:00
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `laporan_warga`;
DROP TABLE IF EXISTS `jalur_evakuasi`;
DROP TABLE IF EXISTS `titik_evakuasi`;
DROP TABLE IF EXISTS `tindakan`;
DROP TABLE IF EXISTS `peringatan`;
DROP TABLE IF EXISTS `hasil_kerawanan`;
DROP TABLE IF EXISTS `riwayat_rob`;
DROP TABLE IF EXISTS `data_oseanografi`;
DROP TABLE IF EXISTS `data_parameter`;
DROP TABLE IF EXISTS `kelas_skor`;
DROP TABLE IF EXISTS `tingkat_kerawanan`;
DROP TABLE IF EXISTS `parameter`;
DROP TABLE IF EXISTS `sumber_data`;
DROP TABLE IF EXISTS `wilayah`;
DROP TABLE IF EXISTS `pengguna`;

CREATE TABLE IF NOT EXISTS `pengguna` (
  `id_pengguna` INT NOT NULL AUTO_INCREMENT,
  `nama` VARCHAR(100) NOT NULL,
  `username` VARCHAR(50) NOT NULL,
  `kata_sandi_hash` VARCHAR(255) NULL,
  `peran` VARCHAR(20) NOT NULL,
  `aktif` TINYINT(1) NOT NULL DEFAULT 1,
  `email` VARCHAR(150) NULL,
  `google_id` VARCHAR(64) NULL,
  PRIMARY KEY (`id_pengguna`),
  UNIQUE KEY `uq_pengguna_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wilayah` (
  `id_wilayah` INT NOT NULL AUTO_INCREMENT,
  `nama_wilayah` VARCHAR(100) NOT NULL,
  `tipe` VARCHAR(30) NOT NULL,
  `geometri_geojson` MEDIUMTEXT NULL,
  `jumlah_kk` INT NULL,
  PRIMARY KEY (`id_wilayah`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sumber_data` (
  `id_sumber` INT NOT NULL AUTO_INCREMENT,
  `nama_sumber` VARCHAR(100) NOT NULL,
  `jenis` VARCHAR(50) NOT NULL,
  `url` VARCHAR(255) NULL,
  `tanggal_akses` DATE NOT NULL,
  PRIMARY KEY (`id_sumber`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `parameter` (
  `id_parameter` INT NOT NULL AUTO_INCREMENT,
  `kelompok` VARCHAR(30) NOT NULL,
  `nama_parameter` VARCHAR(100) NOT NULL,
  `satuan` VARCHAR(20) NOT NULL,
  `bobot` DOUBLE NOT NULL,
  PRIMARY KEY (`id_parameter`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tingkat_kerawanan` (
  `id_tingkat` INT NOT NULL AUTO_INCREMENT,
  `nama_tingkat` VARCHAR(20) NOT NULL,
  `skor_min` DOUBLE NOT NULL,
  `skor_maks` DOUBLE NOT NULL,
  `makna_warga` TEXT NOT NULL,
  `warna` VARCHAR(10) NOT NULL,
  PRIMARY KEY (`id_tingkat`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kelas_skor` (
  `id_kelas` INT NOT NULL AUTO_INCREMENT,
  `id_parameter` INT NOT NULL,
  `batas_bawah` DOUBLE NOT NULL,
  `batas_atas` DOUBLE NOT NULL,
  `skor` INT NOT NULL,
  PRIMARY KEY (`id_kelas`),
  CONSTRAINT `fk_kelas_skor_id_parameter` FOREIGN KEY (`id_parameter`) REFERENCES `parameter`(`id_parameter`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `data_parameter` (
  `id_data` INT NOT NULL AUTO_INCREMENT,
  `id_wilayah` INT NOT NULL,
  `id_parameter` INT NOT NULL,
  `id_sumber` INT NOT NULL,
  `id_pengguna` INT NULL,
  `nilai` DOUBLE NOT NULL,
  `skor` INT NULL,
  `tanggal_data` DATE NOT NULL,
  PRIMARY KEY (`id_data`),
  CONSTRAINT `fk_data_parameter_id_pengguna` FOREIGN KEY (`id_pengguna`) REFERENCES `pengguna`(`id_pengguna`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_data_parameter_id_wilayah` FOREIGN KEY (`id_wilayah`) REFERENCES `wilayah`(`id_wilayah`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_data_parameter_id_parameter` FOREIGN KEY (`id_parameter`) REFERENCES `parameter`(`id_parameter`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_data_parameter_id_sumber` FOREIGN KEY (`id_sumber`) REFERENCES `sumber_data`(`id_sumber`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `data_oseanografi` (
  `id_osea` INT NOT NULL AUTO_INCREMENT,
  `id_sumber` INT NOT NULL,
  `waktu` DATETIME NOT NULL,
  `pasut_m` DOUBLE NOT NULL,
  `gelombang_m` DOUBLE NOT NULL,
  `angin_knot` DOUBLE NOT NULL,
  `arus_ms` DOUBLE NOT NULL,
  `jenis_data` VARCHAR(20) NOT NULL,
  PRIMARY KEY (`id_osea`),
  CONSTRAINT `fk_data_oseanografi_id_sumber` FOREIGN KEY (`id_sumber`) REFERENCES `sumber_data`(`id_sumber`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `riwayat_rob` (
  `id_kejadian` INT NOT NULL AUTO_INCREMENT,
  `id_wilayah` INT NOT NULL,
  `id_sumber` INT NOT NULL,
  `tanggal` DATE NOT NULL,
  `tinggi_genangan_cm` DOUBLE NOT NULL,
  `durasi_jam` DOUBLE NOT NULL,
  `penyebab` VARCHAR(100) NOT NULL,
  PRIMARY KEY (`id_kejadian`),
  CONSTRAINT `fk_riwayat_rob_id_wilayah` FOREIGN KEY (`id_wilayah`) REFERENCES `wilayah`(`id_wilayah`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_riwayat_rob_id_sumber` FOREIGN KEY (`id_sumber`) REFERENCES `sumber_data`(`id_sumber`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hasil_kerawanan` (
  `id_hasil` INT NOT NULL AUTO_INCREMENT,
  `id_wilayah` INT NOT NULL,
  `id_tingkat` INT NOT NULL,
  `id_pengguna` INT NULL,
  `skor_total` DOUBLE NOT NULL,
  `tanggal_analisis` DATE NOT NULL,
  `metode` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id_hasil`),
  CONSTRAINT `fk_hasil_kerawanan_id_pengguna` FOREIGN KEY (`id_pengguna`) REFERENCES `pengguna`(`id_pengguna`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_hasil_kerawanan_id_wilayah` FOREIGN KEY (`id_wilayah`) REFERENCES `wilayah`(`id_wilayah`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_hasil_kerawanan_id_tingkat` FOREIGN KEY (`id_tingkat`) REFERENCES `tingkat_kerawanan`(`id_tingkat`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `peringatan` (
  `id_peringatan` INT NOT NULL AUTO_INCREMENT,
  `id_pengguna` INT NULL,
  `id_sumber` INT NOT NULL,
  `status` VARCHAR(20) NOT NULL,
  `judul` VARCHAR(150) NOT NULL,
  `isi` TEXT NOT NULL,
  `mulai` DATETIME NOT NULL,
  `berakhir` DATETIME NOT NULL,
  PRIMARY KEY (`id_peringatan`),
  CONSTRAINT `fk_peringatan_id_pengguna` FOREIGN KEY (`id_pengguna`) REFERENCES `pengguna`(`id_pengguna`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_peringatan_id_sumber` FOREIGN KEY (`id_sumber`) REFERENCES `sumber_data`(`id_sumber`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tindakan` (
  `id_tindakan` INT NOT NULL AUTO_INCREMENT,
  `id_tingkat` INT NOT NULL,
  `urutan` INT NOT NULL,
  `isi_tindakan` TEXT NOT NULL,
  PRIMARY KEY (`id_tindakan`),
  CONSTRAINT `fk_tindakan_id_tingkat` FOREIGN KEY (`id_tingkat`) REFERENCES `tingkat_kerawanan`(`id_tingkat`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `titik_evakuasi` (
  `id_titik` INT NOT NULL AUTO_INCREMENT,
  `id_wilayah` INT NOT NULL,
  `id_sumber` INT NOT NULL,
  `nama_titik` VARCHAR(100) NOT NULL,
  `jenis` VARCHAR(40) NOT NULL,
  `lintang` DOUBLE NOT NULL,
  `bujur` DOUBLE NOT NULL,
  `elevasi_m` DOUBLE NULL,
  `jarak_pantai_m` DOUBLE NULL,
  `kapasitas_orang` INT NULL,
  `status_validasi` VARCHAR(20) NOT NULL,
  `keterangan` TEXT NULL,
  PRIMARY KEY (`id_titik`),
  CONSTRAINT `fk_titik_evakuasi_id_wilayah` FOREIGN KEY (`id_wilayah`) REFERENCES `wilayah`(`id_wilayah`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_titik_evakuasi_id_sumber` FOREIGN KEY (`id_sumber`) REFERENCES `sumber_data`(`id_sumber`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jalur_evakuasi` (
  `id_jalur` INT NOT NULL AUTO_INCREMENT,
  `id_wilayah` INT NOT NULL,
  `id_titik` INT NOT NULL,
  `panjang_lurus_m` DOUBLE NOT NULL,
  `panjang_jalur_m` DOUBLE NULL,
  `waktu_tempuh_menit` DOUBLE NULL,
  `keterangan` TEXT NULL,
  PRIMARY KEY (`id_jalur`),
  CONSTRAINT `fk_jalur_evakuasi_id_wilayah` FOREIGN KEY (`id_wilayah`) REFERENCES `wilayah`(`id_wilayah`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_jalur_evakuasi_id_titik` FOREIGN KEY (`id_titik`) REFERENCES `titik_evakuasi`(`id_titik`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `laporan_warga` (
  `id_laporan` INT NOT NULL AUTO_INCREMENT,
  `id_pengguna` INT NOT NULL,
  `id_wilayah` INT NULL,
  `lintang` DOUBLE NOT NULL,
  `bujur` DOUBLE NOT NULL,
  `akurasi_m` DOUBLE NULL,
  `sumber_lokasi` VARCHAR(20) NOT NULL,
  `tinggi_air_cm` INT NOT NULL,
  `keterangan` TEXT NULL,
  `foto` VARCHAR(100) NOT NULL,
  `status` VARCHAR(20) NOT NULL,
  `id_verifikator` INT NULL,
  `catatan_verifikasi` VARCHAR(255) NULL,
  `waktu_lapor` DATETIME NOT NULL,
  `waktu_verifikasi` DATETIME NULL,
  PRIMARY KEY (`id_laporan`),
  CONSTRAINT `fk_laporan_warga_id_pengguna` FOREIGN KEY (`id_pengguna`) REFERENCES `pengguna`(`id_pengguna`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_laporan_warga_id_wilayah` FOREIGN KEY (`id_wilayah`) REFERENCES `wilayah`(`id_wilayah`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_laporan_warga_id_verifikator` FOREIGN KEY (`id_verifikator`) REFERENCES `pengguna`(`id_pengguna`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data: pengguna
INSERT INTO `pengguna` (`id_pengguna`, `nama`, `username`, `kata_sandi_hash`, `peran`, `aktif`, `email`, `google_id`) VALUES
(1, 'Administrator Sistem', 'admin', '$2y$10$FmYL/h4kFesd/oFCgd2t2es07wIDJdHZUUtGNjk.rpiVulWKWrX/a', 'admin', 1, NULL, NULL),
(2, 'Mahasiswa Peneliti', 'mahasiswa', '$2y$10$1/eu5546gMMszpo9STzQnO5frf.QRcHvnbBSSOuQwLRs3bdPDzNb6', 'mahasiswa', 1, NULL, NULL),
(3, 'Perangkat Desa Pulo Panjang', 'perangkat', '$2y$10$XLXJv3vFwjkl92o3ZKB2x.Sj/CdJSGGjUUueJRUHRjKYM7T9EgjtK', 'perangkat_desa', 1, NULL, NULL),
(4, 'Warga Pulo Panjang', 'warga', '$2y$10$BVsghDnds/Veu8hBva20KuWhCYE.IYVNb7OSOl1l3FHfvvLtfl0dC', 'warga', 1, NULL, NULL),
(5, 'Perangkat Kampung (contoh)', 'kadus2', '$2y$10$9F3DXfKTcUNNxAXLaIIDCOIM953hXncG5cA43Oh1jFMZJsKTOBTHG', 'perangkat_desa', 1, NULL, NULL),
(6, 'Siti Rahmawati', 'siti', '$2y$10$HOhtuoCxQzSzf4FYkTE/EOnMbU63ZEvhO52411dbDh3y0c0XfQsdW', 'warga', 1, NULL, NULL),
(7, 'Mahasiswa Magang (nonaktif)', 'magang', '$2y$10$ctmGYIS1Ar0kidmEctLtSOaqL2pDX.dgjCmz.yB5RBy2aJIq066kO', 'mahasiswa', 0, NULL, NULL);

-- Data: wilayah
INSERT INTO `wilayah` (`id_wilayah`, `nama_wilayah`, `tipe`, `geometri_geojson`, `jumlah_kk`) VALUES
(1, 'Kampung Penengahan', 'Permukiman', '{"type":"Feature","properties":{"nama":"Kampung Penengahan","deskripsi":"Area historis bagian tengah pulau","titik":[106.15306,-5.93167]},"geometry":{"type":"Polygon","coordinates":[[[106.153543,-5.927954],[106.157865,-5.931132],[106.157091,-5.933178],[106.150086,-5.93218],[106.153543,-5.927954]]]}}', 30),
(2, 'Kampung Peres', 'Permukiman', '{"type":"Feature","properties":{"nama":"Kampung Peres","deskripsi":"Pemukiman utama nelayan terpadat di selatan","titik":[106.15543,-5.94376]},"geometry":{"type":"Polygon","coordinates":[[[106.154432,-5.938549],[106.158372,-5.942029],[106.158028,-5.942222],[106.157129,-5.942487],[106.157021,-5.942905],[106.157045,-5.943705],[106.156668,-5.944183],[106.156653,-5.944653],[106.15536,-5.944983],[106.154098,-5.944984],[106.153481,-5.945095],[106.153279,-5.945037],[106.152714,-5.944596],[106.152576,-5.94411],[106.15216,-5.943871],[106.151869,-5.94343],[106.151099,-5.943312],[106.15076,-5.943148],[106.150602,-5.943017],[106.1505,-5.942716],[106.150286,-5.942594],[106.14999,-5.942585],[106.149282,-5.942862],[106.148696,-5.942796],[106.14829,-5.94258],[106.147383,-5.942571],[106.147268,-5.942469],[106.147179,-5.941935],[106.146925,-5.941685],[106.145729,-5.941208],[106.144353,-5.941139],[106.154432,-5.938549]]]}}', 380),
(3, 'Kampung Baru', 'Permukiman', '{"type":"Feature","properties":{"nama":"Kampung Baru","deskripsi":"Zona perluasan perumahan barat laut","titik":[106.15,-5.92916]},"geometry":{"type":"Polygon","coordinates":[[[106.153543,-5.927954],[106.150086,-5.93218],[106.141129,-5.937974],[106.140631,-5.937003],[106.140637,-5.936529],[106.140196,-5.93613],[106.140144,-5.935868],[106.139941,-5.935616],[106.140054,-5.933162],[106.14032,-5.932808],[106.140281,-5.932111],[106.140164,-5.93191],[106.139863,-5.931768],[106.139981,-5.931398],[106.139657,-5.930538],[106.139587,-5.929837],[106.138968,-5.928292],[106.138909,-5.927547],[106.139023,-5.926489],[106.13901,-5.925056],[106.1388,-5.924637],[106.138756,-5.924152],[106.138972,-5.92208],[106.13917,-5.92184],[106.139254,-5.92144],[106.139532,-5.921107],[106.141775,-5.920236],[106.142804,-5.920111],[106.143275,-5.919946],[106.14473,-5.919885],[106.145917,-5.919543],[106.147247,-5.919541],[106.148666,-5.919142],[106.150034,-5.919169],[106.153543,-5.927954]]]}}', 160),
(4, 'Kampung Sukarela', 'Permukiman', '{"type":"Feature","properties":{"nama":"Kampung Sukarela","deskripsi":"Area pemukiman utara dekat batas vegetasi mangrove","titik":[106.15694,-5.92638]},"geometry":{"type":"Polygon","coordinates":[[[106.157865,-5.931132],[106.153543,-5.927954],[106.150034,-5.919169],[106.151512,-5.919199],[106.15281,-5.919652],[106.153814,-5.919658],[106.154247,-5.919825],[106.156001,-5.920003],[106.157844,-5.920569],[106.159213,-5.920753],[106.159602,-5.920599],[106.159961,-5.920637],[106.160209,-5.920761],[106.160457,-5.921058],[106.161286,-5.921441],[106.16161,-5.921933],[106.162056,-5.922152],[106.162293,-5.922767],[106.162869,-5.923138],[106.163268,-5.92399],[106.163904,-5.92469],[106.164236,-5.924882],[106.164465,-5.925358],[106.164879,-5.925784],[106.165323,-5.926619],[106.165744,-5.927012],[106.165807,-5.92715],[106.157865,-5.931132]]]}}', 90),
(5, 'Kampung Sukadiri', 'Permukiman', '{"type":"Feature","properties":{"nama":"Kampung Sukadiri","deskripsi":"Pemukiman pesisir tenggara pulau","titik":[106.15972,-5.93889]},"geometry":{"type":"Polygon","coordinates":[[[106.154432,-5.938549],[106.15674,-5.935577],[106.162397,-5.937468],[106.162283,-5.937497],[106.162214,-5.937718],[106.162421,-5.938082],[106.162417,-5.938355],[106.161913,-5.93866],[106.161687,-5.939016],[106.16143,-5.939001],[106.16129,-5.938577],[106.161021,-5.938404],[106.16065,-5.938399],[106.160188,-5.938594],[106.160082,-5.938811],[106.160138,-5.939612],[106.160036,-5.939928],[106.160336,-5.940479],[106.159549,-5.940912],[106.159446,-5.941428],[106.158372,-5.942029],[106.154432,-5.938549]]]}}', 140),
(6, 'Kampung Kebalen', 'Permukiman', '{"type":"Feature","properties":{"nama":"Kampung Kebalen","deskripsi":"Kluster pemukiman kecil di sayap timur pulau","titik":[106.16111,-5.93472]},"geometry":{"type":"Polygon","coordinates":[[[106.15674,-5.935577],[106.157091,-5.933178],[106.157865,-5.931132],[106.165807,-5.92715],[106.165955,-5.92747],[106.166243,-5.927748],[106.166407,-5.92816],[106.166708,-5.928532],[106.166865,-5.929077],[106.167289,-5.929576],[106.167365,-5.930018],[106.167623,-5.930349],[106.16777,-5.930835],[106.168033,-5.931066],[106.168514,-5.932382],[106.168803,-5.932877],[106.169112,-5.933173],[106.169137,-5.933451],[106.1694,-5.933873],[106.169634,-5.934572],[106.169575,-5.935062],[106.169447,-5.935246],[106.169223,-5.935228],[106.169027,-5.934442],[106.168535,-5.934196],[106.168271,-5.933847],[106.168029,-5.933797],[106.167797,-5.933935],[106.16765,-5.934337],[106.167005,-5.934273],[106.166575,-5.934517],[106.166437,-5.934837],[106.166375,-5.935516],[106.166379,-5.935933],[106.166513,-5.936125],[106.167864,-5.936056],[106.168032,-5.936234],[106.167961,-5.936506],[106.166531,-5.936497],[106.166156,-5.936608],[106.164984,-5.936056],[106.16473,-5.935772],[106.164335,-5.935702],[106.163886,-5.935852],[106.16369,-5.936099],[106.163664,-5.937034],[106.163013,-5.937576],[106.162578,-5.937421],[106.162397,-5.937468],[106.15674,-5.935577]]]}}', 50),
(7, 'Kampung Pasir Putih', 'Permukiman', '{"type":"Feature","properties":{"nama":"Kampung Pasir Putih","deskripsi":"Pusat administrasi desa dan fasilitas umum","titik":[106.1528,-5.9335]},"geometry":{"type":"Polygon","coordinates":[[[106.15674,-5.935577],[106.154432,-5.938549],[106.144353,-5.941139],[106.143883,-5.941022],[106.143628,-5.94076],[106.144074,-5.94043],[106.144065,-5.940231],[106.143893,-5.94008],[106.143363,-5.940028],[106.143164,-5.940108],[106.143082,-5.940338],[106.143263,-5.94063],[106.143133,-5.940756],[106.142785,-5.940672],[106.142347,-5.940307],[106.141708,-5.940083],[106.141588,-5.939343],[106.141145,-5.938273],[106.141129,-5.937974],[106.150086,-5.93218],[106.157091,-5.933178],[106.15674,-5.935577]]]}}', 250);

-- Data: sumber_data
INSERT INTO `sumber_data` (`id_sumber`, `nama_sumber`, `jenis`, `url`, `tanggal_akses`) VALUES
(1, 'DEMNAS - Badan Informasi Geospasial (BIG)', 'Data sekunder (raster DEM)', 'https://tanahair.indonesia.go.id/demnas/', '2026-09-15'),
(2, 'Data simulasi prototipe (bukan data resmi)', 'Simulasi', '', '2026-10-20'),
(3, 'Citra satelit Sentinel-2 (Copernicus)', 'Penginderaan jauh', 'https://dataspace.copernicus.eu', '2026-09-18'),
(4, 'Catatan kejadian rob - Pemerintah Desa', 'Dokumen desa', '', '2026-09-25'),
(5, 'Survei lapangan dan peta permukiman', 'Survei lapangan', '', '2026-09-28'),
(6, 'Pernyataan Kepala Desa Pulo Panjang (Ratu Bulkis), dimuat SRSentra 22 Mei 2026', 'Berita (pernyataan pejabat)', 'https://srsentra.com/kdkmp-desa-pulo-panjang-serang-jadi-asa-baru-motor-ekonomi-warga-kepulauan/', '2026-11-02'),
(7, 'Radar Banten, 7 Okt 2025: Banjir rob akibat supermoon di Puloampel', 'Berita (media daring)', 'https://www.radarbanten.co.id/2025/10/07/fenomena-supermoon-sebabkan-banjir-rob-di-puloampel-ratusan-rumah-terendam/', '2026-11-03'),
(8, 'Prediksi Pasang Surut BIG - Direktorat Sistem Referensi Geospasial (SRGI)', 'Data sekunder (prediksi pasut nasional)', 'https://srgi.big.go.id/', '2026-11-05'),
(9, 'Analisis geometri garis pantai digitasi (turunan)', 'Turunan / analisis spasial', '', '2026-11-05');

-- Data: parameter
INSERT INTO `parameter` (`id_parameter`, `kelompok`, `nama_parameter`, `satuan`, `bobot`) VALUES
(1, 'Topografi', 'Elevasi/DEM', 'm', 0.25),
(2, 'Topografi', 'Kemiringan lereng', '%', 0.05),
(3, 'Topografi', 'Jarak dari garis pantai', 'm', 0.15),
(4, 'Oseanografi', 'Pasang surut', 'm', 0.2),
(5, 'Oseanografi', 'Gelombang', 'm', 0.08),
(6, 'Oseanografi', 'Arus', 'm/s', 0.02),
(7, 'Oseanografi', 'Angin', 'knot', 0.03),
(8, 'Ekologi Perairan', 'Mangrove', '% tutupan', 0.12),
(9, 'Ekologi Perairan', 'Perubahan garis pantai', 'm/tahun', 0.1),
(10, 'Pendukung', 'Riwayat kejadian rob', 'kejadian/tahun', 0),
(11, 'Pendukung', 'Permukiman', '% luas', 0);

-- Data: tingkat_kerawanan
INSERT INTO `tingkat_kerawanan` (`id_tingkat`, `nama_tingkat`, `skor_min`, `skor_maks`, `makna_warga`, `warna`) VALUES
(1, 'Rendah', 1, 1.66, 'Potensi terdampak relatif rendah berdasarkan parameter yang digunakan.', '#2E9E6B'),
(2, 'Sedang', 1.67, 2.33, 'Wilayah perlu meningkatkan kewaspadaan terhadap kemungkinan rob.', '#E6A100'),
(3, 'Tinggi', 2.34, 3, 'Wilayah memiliki tingkat kerawanan tinggi dan memerlukan kesiapsiagaan lebih besar.', '#D64933');

-- Data: kelas_skor
INSERT INTO `kelas_skor` (`id_kelas`, `id_parameter`, `batas_bawah`, `batas_atas`, `skor`) VALUES
(1, 1, -999, 1, 3),
(2, 1, 1, 2, 2),
(3, 1, 2, 99999, 1),
(4, 2, -999, 2, 3),
(5, 2, 2, 8, 2),
(6, 2, 8, 99999, 1),
(7, 3, -999, 100, 3),
(8, 3, 100, 300, 2),
(9, 3, 300, 99999, 1),
(13, 5, -999, 0.5, 1),
(14, 5, 0.5, 1, 2),
(15, 5, 1, 99999, 3),
(16, 6, -999, 0.2, 1),
(17, 6, 0.2, 0.5, 2),
(18, 6, 0.5, 99999, 3),
(19, 7, -999, 10, 1),
(20, 7, 10, 20, 2),
(21, 7, 20, 99999, 3),
(22, 8, -999, 30, 3),
(23, 8, 30, 60, 2),
(24, 8, 60, 99999, 1),
(25, 9, -999, -1, 3),
(26, 9, -1, 0, 2),
(27, 9, 0, 99999, 1),
(28, 10, -999, 2, 1),
(29, 10, 2, 5, 2),
(30, 10, 5, 99999, 3),
(31, 11, -999, 20, 1),
(32, 11, 20, 50, 2),
(33, 11, 50, 99999, 3),
(34, 4, -999, 0.248, 1),
(35, 4, 0.248, 0.373, 2),
(36, 4, 0.373, 99999, 3);

-- Data: data_parameter
INSERT INTO `data_parameter` (`id_data`, `id_wilayah`, `id_parameter`, `id_sumber`, `id_pengguna`, `nilai`, `skor`, `tanggal_data`) VALUES
(1, 1, 1, 1, 1, 3.2, 1, '2026-09-28'),
(2, 1, 2, 1, 1, 5, 2, '2026-09-28'),
(3, 1, 3, 1, 1, 420, 1, '2026-09-28'),
(4, 1, 4, 8, 1, 0.569, 3, '2026-11-18'),
(5, 1, 5, 2, 1, 0.3, 1, '2026-09-28'),
(6, 1, 6, 2, 1, 0.1, 1, '2026-09-28'),
(7, 1, 7, 2, 1, 8, 1, '2026-09-28'),
(8, 1, 8, 3, 1, 70, 1, '2026-09-28'),
(9, 1, 9, 3, 1, 0.2, 1, '2026-09-28'),
(10, 1, 10, 4, 1, 1, 1, '2026-09-28'),
(11, 1, 11, 5, 1, 45, 2, '2026-09-28'),
(12, 2, 1, 1, 1, 0.9, 3, '2026-09-28'),
(13, 2, 2, 1, 1, 1.5, 3, '2026-09-28'),
(14, 2, 3, 1, 1, 90, 3, '2026-09-28'),
(15, 2, 4, 8, 1, 0.569, 3, '2026-11-18'),
(16, 2, 5, 2, 1, 1.1, 3, '2026-09-28'),
(17, 2, 6, 2, 1, 0.45, 2, '2026-09-28'),
(18, 2, 7, 2, 1, 18, 2, '2026-09-28'),
(19, 2, 8, 3, 1, 25, 3, '2026-09-28'),
(20, 2, 9, 3, 1, -1.1, 3, '2026-09-28'),
(21, 2, 10, 4, 1, 5, 3, '2026-09-28'),
(22, 2, 11, 5, 1, 65, 3, '2026-09-28'),
(23, 3, 1, 1, 1, 1.4, 2, '2026-09-28'),
(24, 3, 2, 1, 1, 3, 2, '2026-09-28'),
(25, 3, 3, 1, 1, 150, 2, '2026-09-28'),
(26, 3, 4, 8, 1, 0.569, 3, '2026-11-18'),
(27, 3, 5, 2, 1, 0.5, 2, '2026-09-28'),
(28, 3, 6, 2, 1, 0.25, 2, '2026-09-28'),
(29, 3, 7, 2, 1, 12, 2, '2026-09-28'),
(30, 3, 8, 3, 1, 45, 2, '2026-09-28'),
(31, 3, 9, 3, 1, -0.3, 2, '2026-09-28'),
(32, 3, 10, 4, 1, 2, 2, '2026-09-28'),
(33, 3, 11, 5, 1, 55, 3, '2026-09-28'),
(34, 4, 1, 1, 1, 1.2, 2, '2026-09-28'),
(35, 4, 2, 1, 1, 2, 2, '2026-09-28'),
(36, 4, 3, 1, 1, 120, 2, '2026-09-28'),
(37, 4, 4, 8, 1, 0.569, 3, '2026-11-18'),
(38, 4, 5, 2, 1, 0.5, 2, '2026-09-28'),
(39, 4, 6, 2, 1, 0.2, 2, '2026-09-28'),
(40, 4, 7, 2, 1, 12, 2, '2026-09-28'),
(41, 4, 8, 3, 1, 60, 1, '2026-09-28'),
(42, 4, 9, 3, 1, -0.2, 2, '2026-09-28'),
(43, 4, 10, 4, 1, 3, 2, '2026-09-28'),
(44, 4, 11, 5, 1, 5, 1, '2026-09-28'),
(45, 5, 1, 1, 1, 0.7, 3, '2026-09-28'),
(46, 5, 2, 1, 1, 1, 3, '2026-09-28'),
(47, 5, 3, 1, 1, 60, 3, '2026-09-28'),
(48, 5, 4, 8, 1, 0.569, 3, '2026-11-18'),
(49, 5, 5, 2, 1, 0.9, 2, '2026-09-28'),
(50, 5, 6, 2, 1, 0.3, 2, '2026-09-28'),
(51, 5, 7, 2, 1, 14, 2, '2026-09-28'),
(52, 5, 8, 3, 1, 20, 3, '2026-09-28'),
(53, 5, 9, 3, 1, -1.4, 3, '2026-09-28'),
(54, 5, 10, 4, 1, 6, 3, '2026-09-28'),
(55, 5, 11, 5, 1, 60, 3, '2026-09-28'),
(56, 6, 1, 1, 1, 1.8, 2, '2026-09-28'),
(57, 6, 2, 1, 1, 2.5, 2, '2026-09-28'),
(58, 6, 3, 1, 1, 210, 2, '2026-09-28'),
(59, 6, 4, 8, 1, 0.569, 3, '2026-11-18'),
(60, 6, 5, 2, 1, 0.4, 1, '2026-09-28'),
(61, 6, 6, 2, 1, 0.15, 1, '2026-09-28'),
(62, 6, 7, 2, 1, 10, 2, '2026-09-28'),
(63, 6, 8, 3, 1, 35, 2, '2026-09-28'),
(64, 6, 9, 3, 1, -0.5, 2, '2026-09-28'),
(65, 6, 10, 4, 1, 1, 1, '2026-09-28'),
(66, 6, 11, 5, 1, 50, 3, '2026-09-28'),
(67, 7, 1, 1, 1, 0.7, 3, '2026-09-28'),
(68, 7, 2, 1, 1, 1, 3, '2026-09-28'),
(69, 7, 3, 1, 1, 60, 3, '2026-09-28'),
(70, 7, 4, 8, 1, 0.569, 3, '2026-11-18'),
(71, 7, 5, 2, 1, 0.9, 2, '2026-09-28'),
(72, 7, 6, 2, 1, 0.3, 2, '2026-09-28'),
(73, 7, 7, 2, 1, 14, 2, '2026-09-28'),
(74, 7, 8, 3, 1, 20, 3, '2026-09-28'),
(75, 7, 9, 3, 1, -1.4, 3, '2026-09-28'),
(76, 7, 10, 4, 1, 0, 1, '2026-09-28'),
(77, 7, 11, 5, 1, 30, 2, '2026-09-28');

-- Data: data_oseanografi
INSERT INTO `data_oseanografi` (`id_osea`, `id_sumber`, `waktu`, `pasut_m`, `gelombang_m`, `angin_knot`, `arus_ms`, `jenis_data`) VALUES
(1, 8, '2026-11-17 19:00:00', -0.011, 0.29, 14.6, 0.12, 'prediksi'),
(2, 8, '2026-11-17 20:00:00', -0.031, 0.3, 14.4, 0.13, 'prediksi'),
(3, 8, '2026-11-17 21:00:00', -0.052, 0.32, 15.3, 0.12, 'prediksi'),
(4, 8, '2026-11-17 22:00:00', -0.077, 0.42, 14.8, 0.13, 'prediksi'),
(5, 8, '2026-11-17 23:00:00', -0.104, 0.43, 14.4, 0.13, 'prediksi'),
(6, 8, '2026-11-18 00:00:00', -0.133, 0.49, 13.9, 0.13, 'prediksi'),
(7, 8, '2026-11-18 01:00:00', -0.157, 0.52, 14.2, 0.12, 'prediksi'),
(8, 8, '2026-11-18 02:00:00', -0.173, 0.58, 13.6, 0.12, 'prediksi'),
(9, 8, '2026-11-18 03:00:00', -0.173, 0.57, 12.4, 0.11, 'prediksi'),
(10, 8, '2026-11-18 04:00:00', -0.156, 0.62, 12.5, 0.13, 'prediksi'),
(11, 8, '2026-11-18 05:00:00', -0.119, 0.64, 11.1, 0.14, 'prediksi'),
(12, 8, '2026-11-18 06:00:00', -0.068, 0.62, 10.5, 0.16, 'prediksi'),
(13, 8, '2026-11-18 07:00:00', -0.007, 0.59, 10.3, 0.15, 'prediksi'),
(14, 8, '2026-11-18 08:00:00', 0.055, 0.61, 10.2, 0.16, 'prediksi'),
(15, 8, '2026-11-18 09:00:00', 0.11, 0.6, 9.3, 0.13, 'prediksi'),
(16, 8, '2026-11-18 10:00:00', 0.151, 0.56, 9, 0.11, 'prediksi'),
(17, 8, '2026-11-18 11:00:00', 0.175, 0.55, 8.8, 0.13, 'prediksi'),
(18, 8, '2026-11-18 12:00:00', 0.18, 0.5, 9.2, 0.09, 'prediksi'),
(19, 8, '2026-11-18 13:00:00', 0.167, 0.48, 9, 0.12, 'prediksi'),
(20, 8, '2026-11-18 14:00:00', 0.142, 0.43, 9, 0.12, 'prediksi'),
(21, 8, '2026-11-18 15:00:00', 0.109, 0.44, 8.6, 0.12, 'prediksi'),
(22, 8, '2026-11-18 16:00:00', 0.073, 0.44, 9.2, 0.12, 'prediksi'),
(23, 8, '2026-11-18 17:00:00', 0.038, 0.41, 8.8, 0.14, 'prediksi'),
(24, 8, '2026-11-18 18:00:00', 0.007, 0.41, 8.9, 0.13, 'prediksi'),
(25, 8, '2026-11-18 19:00:00', -0.022, 0.41, 9.6, 0.14, 'prediksi'),
(26, 8, '2026-11-18 20:00:00', -0.048, 0.4, 9.4, 0.13, 'prediksi'),
(27, 8, '2026-11-18 21:00:00', -0.072, 0.4, 8.5, 0.11, 'prediksi'),
(28, 8, '2026-11-18 22:00:00', -0.095, 0.44, 8.7, 0.1, 'prediksi'),
(29, 8, '2026-11-18 23:00:00', -0.115, 0.48, 8.2, 0.11, 'prediksi'),
(30, 8, '2026-11-19 00:00:00', -0.131, 0.48, 7.4, 0.1, 'prediksi'),
(31, 8, '2026-11-19 01:00:00', -0.14, 0.44, 7.3, 0.11, 'prediksi'),
(32, 8, '2026-11-19 02:00:00', -0.139, 0.42, 7, 0.11, 'prediksi'),
(33, 8, '2026-11-19 03:00:00', -0.126, 0.42, 6.6, 0.13, 'prediksi'),
(34, 8, '2026-11-19 04:00:00', -0.1, 0.42, 5.9, 0.13, 'prediksi'),
(35, 8, '2026-11-19 05:00:00', -0.063, 0.38, 4.9, 0.14, 'prediksi'),
(36, 8, '2026-11-19 06:00:00', -0.02, 0.39, 4.2, 0.14, 'prediksi'),
(37, 8, '2026-11-19 07:00:00', 0.026, 0.31, 3.3, 0.15, 'prediksi'),
(38, 8, '2026-11-19 08:00:00', 0.07, 0.31, 3.1, 0.15, 'prediksi'),
(39, 8, '2026-11-19 09:00:00', 0.105, 0.27, 3.7, 0.11, 'prediksi'),
(40, 8, '2026-11-19 10:00:00', 0.13, 0.19, 2.9, 0.12, 'prediksi'),
(41, 8, '2026-11-19 11:00:00', 0.143, 0.19, 3.1, 0.1, 'prediksi'),
(42, 8, '2026-11-19 12:00:00', 0.142, 0.16, 3.1, 0.12, 'prediksi'),
(43, 8, '2026-11-19 13:00:00', 0.132, 0.12, 4.1, 0.11, 'prediksi'),
(44, 8, '2026-11-19 14:00:00', 0.112, 0.12, 4.6, 0.11, 'prediksi'),
(45, 8, '2026-11-19 15:00:00', 0.087, 0.12, 5, 0.14, 'prediksi'),
(46, 8, '2026-11-19 16:00:00', 0.058, 0.12, 6, 0.12, 'prediksi'),
(47, 8, '2026-11-19 17:00:00', 0.026, 0.1, 6.3, 0.13, 'prediksi'),
(48, 8, '2026-11-19 18:00:00', -0.006, 0.12, 6.5, 0.12, 'prediksi'),
(49, 8, '2026-11-19 19:00:00', -0.039, 0.19, 7.9, 0.12, 'prediksi'),
(50, 8, '2026-11-19 20:00:00', -0.071, 0.18, 9, 0.13, 'prediksi'),
(51, 8, '2026-11-19 21:00:00', -0.099, 0.22, 9.3, 0.14, 'prediksi'),
(52, 8, '2026-11-19 22:00:00', -0.123, 0.28, 9.6, 0.11, 'prediksi'),
(53, 8, '2026-11-19 23:00:00', -0.138, 0.3, 9.6, 0.12, 'prediksi'),
(54, 8, '2026-11-20 00:00:00', -0.142, 0.36, 9.9, 0.1, 'prediksi'),
(55, 8, '2026-11-20 01:00:00', -0.134, 0.41, 10.6, 0.11, 'prediksi'),
(56, 8, '2026-11-20 02:00:00', -0.112, 0.43, 10.9, 0.13, 'prediksi'),
(57, 8, '2026-11-20 03:00:00', -0.08, 0.44, 10.9, 0.14, 'prediksi'),
(58, 8, '2026-11-20 04:00:00', -0.039, 0.45, 10.5, 0.15, 'prediksi'),
(59, 8, '2026-11-20 05:00:00', 0.004, 0.48, 10.1, 0.12, 'prediksi'),
(60, 8, '2026-11-20 06:00:00', 0.046, 0.47, 10.1, 0.13, 'prediksi'),
(61, 8, '2026-11-20 07:00:00', 0.081, 0.48, 10, 0.14, 'prediksi'),
(62, 8, '2026-11-20 08:00:00', 0.106, 0.48, 9.8, 0.12, 'prediksi'),
(63, 8, '2026-11-20 09:00:00', 0.12, 0.47, 10.1, 0.12, 'prediksi'),
(64, 8, '2026-11-20 10:00:00', 0.124, 0.41, 10.2, 0.11, 'prediksi'),
(65, 8, '2026-11-20 11:00:00', 0.118, 0.46, 10.1, 0.1, 'prediksi'),
(66, 8, '2026-11-20 12:00:00', 0.107, 0.44, 10.4, 0.1, 'prediksi'),
(67, 8, '2026-11-20 13:00:00', 0.092, 0.41, 10.2, 0.12, 'prediksi'),
(68, 8, '2026-11-20 14:00:00', 0.075, 0.4, 11.3, 0.12, 'prediksi'),
(69, 8, '2026-11-20 15:00:00', 0.056, 0.45, 11.9, 0.13, 'prediksi'),
(70, 8, '2026-11-20 16:00:00', 0.034, 0.43, 12.1, 0.13, 'prediksi'),
(71, 8, '2026-11-20 17:00:00', 0.008, 0.48, 12.3, 0.13, 'prediksi'),
(72, 8, '2026-11-20 18:00:00', -0.023, 0.52, 13.6, 0.13, 'prediksi'),
(73, 8, '2026-11-20 19:00:00', -0.058, 0.5, 13.7, 0.13, 'prediksi');

-- Data: riwayat_rob
INSERT INTO `riwayat_rob` (`id_kejadian`, `id_wilayah`, `id_sumber`, `tanggal`, `tinggi_genangan_cm`, `durasi_jam`, `penyebab`) VALUES
(1, 5, 4, '2025-11-24', 35, 3.5, 'Pasang purnama'),
(2, 2, 4, '2025-12-23', 28, 2.5, 'Pasang tinggi disertai gelombang tinggi'),
(3, 2, 4, '2026-01-12', 42, 4, 'Pasang tinggi disertai angin kencang'),
(4, 5, 4, '2026-01-13', 38, 3, 'Pasang tinggi disertai angin kencang'),
(5, 2, 4, '2026-02-10', 25, 2, 'Pasang purnama'),
(6, 2, 4, '2026-03-12', 30, 3, 'Pasang purnama'),
(7, 5, 4, '2026-04-27', 22, 2, 'Pasang tinggi'),
(8, 3, 4, '2026-05-26', 15, 1.5, 'Pasang tinggi'),
(9, 5, 4, '2026-06-24', 33, 3, 'Pasang purnama'),
(10, 2, 4, '2026-06-25', 29, 2.5, 'Pasang purnama'),
(11, 2, 4, '2026-07-23', 40, 4.5, 'Pasang tinggi disertai gelombang tinggi'),
(12, 4, 4, '2026-08-22', 12, 1, 'Pasang tinggi'),
(13, 5, 4, '2026-09-21', 31, 3, 'Pasang purnama'),
(14, 2, 4, '2026-10-21', 36, 3.5, 'Pasang tinggi disertai gelombang tinggi');

-- Data: hasil_kerawanan
INSERT INTO `hasil_kerawanan` (`id_hasil`, `id_wilayah`, `id_tingkat`, `id_pengguna`, `skor_total`, `tanggal_analisis`, `metode`) VALUES
(1, 1, 1, 1, 1.45, '2026-11-15', 'Skoring berbobot (weighted overlay), pasang surut dari prediksi BIG/SRGI'),
(2, 2, 3, 1, 2.95, '2026-11-15', 'Skoring berbobot (weighted overlay), pasang surut dari prediksi BIG/SRGI'),
(3, 3, 2, 1, 2.2, '2026-11-15', 'Skoring berbobot (weighted overlay), pasang surut dari prediksi BIG/SRGI'),
(4, 4, 2, 1, 2.08, '2026-11-15', 'Skoring berbobot (weighted overlay), pasang surut dari prediksi BIG/SRGI'),
(5, 5, 3, 1, 2.87, '2026-11-15', 'Skoring berbobot (weighted overlay), pasang surut dari prediksi BIG/SRGI'),
(6, 6, 2, 1, 2.1, '2026-11-15', 'Skoring berbobot (weighted overlay), pasang surut dari prediksi BIG/SRGI'),
(7, 7, 3, 1, 2.87, '2026-11-15', 'Skoring berbobot (weighted overlay), pasang surut dari prediksi BIG/SRGI');

-- Data: peringatan
INSERT INTO `peringatan` (`id_peringatan`, `id_pengguna`, `id_sumber`, `status`, `judul`, `isi`, `mulai`, `berakhir`) VALUES
(1, 1, 2, 'Waspada', 'Potensi rob saat pasang tinggi', 'Pasang tertinggi pada periode ini berpotensi menggenangi wilayah pesisir berelevasi rendah. Warga di wilayah dengan kerawanan sedang dan tinggi diimbau meningkatkan kewaspadaan, mengamankan barang di tempat rendah, dan mengikuti informasi dari pihak berwenang.', '2026-11-12 16:00:00', '2026-11-14 19:00:00'),
(2, 1, 4, 'Normal', 'Kondisi normal', 'Tidak ada potensi rob yang perlu diwaspadai. Tetap pantau informasi dari pihak berwenang.', '2026-11-04 19:00:00', '2026-11-18 15:00:00'),
(3, 1, 4, 'Siaga', 'Rob pasang purnama', 'Pasang purnama menyebabkan genangan di beberapa titik pesisir. Warga diminta mengamankan barang dan menghindari jalur dekat dermaga.', '2026-10-04 19:00:00', '2026-10-06 19:00:00');

-- Data: tindakan
INSERT INTO `tindakan` (`id_tindakan`, `id_tingkat`, `urutan`, `isi_tindakan`) VALUES
(1, 1, 1, 'Tetap pantau status banjir rob melalui aplikasi dan pengumuman desa.'),
(2, 1, 2, 'Kenali jalur evakuasi dan titik kumpul terdekat dari tempat tinggal Anda.'),
(3, 1, 3, 'Jaga saluran air dan parit di sekitar rumah agar tetap lancar.'),
(4, 1, 4, 'Simpan nomor kontak perangkat desa dan layanan darurat di ponsel Anda.'),
(5, 2, 1, 'Tingkatkan kewaspadaan, terutama saat jadwal pasang tinggi dan cuaca buruk.'),
(6, 2, 2, 'Pindahkan barang yang mudah rusak terkena air ke tempat yang lebih tinggi.'),
(7, 2, 3, 'Siapkan tas siaga berisi dokumen penting, obat, senter, dan air minum.'),
(8, 2, 4, 'Periksa kondisi perahu, tambatan, dan perlengkapan melaut sebelum pasang.'),
(9, 2, 5, 'Ikuti informasi dan arahan dari perangkat desa serta pihak berwenang.'),
(10, 3, 1, 'Segera amankan dokumen penting, perangkat elektronik, dan barang berharga ke tempat tinggi.'),
(11, 3, 2, 'Matikan aliran listrik di rumah bila air mulai masuk untuk menghindari korsleting.'),
(12, 3, 3, 'Siapkan tas siaga dan kumpulkan anggota keluarga, termasuk anak, lansia, dan penyandang disabilitas.'),
(13, 3, 4, 'Bersiap menuju titik kumpul atau lokasi evakuasi bila diarahkan perangkat desa.'),
(14, 3, 5, 'Hindari berjalan atau berkendara melewati genangan dan jalur dekat garis pantai.'),
(15, 3, 6, 'Pantau terus informasi peringatan dan ikuti arahan pihak berwenang.');

-- Data: titik_evakuasi
INSERT INTO `titik_evakuasi` (`id_titik`, `id_wilayah`, `id_sumber`, `nama_titik`, `jenis`, `lintang`, `bujur`, `elevasi_m`, `jarak_pantai_m`, `kapasitas_orang`, `status_validasi`, `keterangan`) VALUES
(1, 1, 9, 'Titik Aman Penengahan', 'Dataran dalam (usulan geometris)', -5.931019, 106.151642, NULL, 1263.7, NULL, 'usulan', 'Titik interior dengan jarak maksimum ke garis pantai di Kampung Penengahan (1264 m). Dihitung dari garis pantai digitasi. Elevasi per titik dan akses jalan BELUM diverifikasi.'),
(2, 2, 9, 'Titik Aman Peres', 'Dataran dalam (usulan geometris)', -5.938727, 106.153742, NULL, 559.9, NULL, 'usulan', 'Titik interior dengan jarak maksimum ke garis pantai di Kampung Peres (560 m). Dihitung dari garis pantai digitasi. Elevasi per titik dan akses jalan BELUM diverifikasi.'),
(3, 3, 9, 'Titik Aman Baru', 'Dataran dalam (usulan geometris)', -5.930684, 106.15131, NULL, 1231.2, NULL, 'usulan', 'Titik interior dengan jarak maksimum ke garis pantai di Kampung Baru (1231 m). Dihitung dari garis pantai digitasi. Elevasi per titik dan akses jalan BELUM diverifikasi.'),
(4, 4, 9, 'Titik Aman Sukarela', 'Dataran dalam (usulan geometris)', -5.929685, 106.155898, NULL, 1030.8, NULL, 'usulan', 'Titik interior dengan jarak maksimum ke garis pantai di Kampung Sukarela (1031 m). Dihitung dari garis pantai digitasi. Elevasi per titik dan akses jalan BELUM diverifikasi.'),
(5, 5, 9, 'Titik Aman Sukadiri', 'Dataran dalam (usulan geometris)', -5.937773, 106.155035, NULL, 570.5, NULL, 'usulan', 'Titik interior dengan jarak maksimum ke garis pantai di Kampung Sukadiri (570 m). Dihitung dari garis pantai digitasi. Elevasi per titik dan akses jalan BELUM diverifikasi.'),
(6, 6, 9, 'Titik Aman Kebalen', 'Dataran dalam (usulan geometris)', -5.931133, 106.157865, NULL, 846.6, NULL, 'usulan', 'Titik interior dengan jarak maksimum ke garis pantai di Kampung Kebalen (847 m). Dihitung dari garis pantai digitasi. Elevasi per titik dan akses jalan BELUM diverifikasi.'),
(7, 7, 9, 'Titik Aman Pasir Putih', 'Dataran dalam (usulan geometris)', -5.932356, 106.151318, NULL, 1138, NULL, 'usulan', 'Titik interior dengan jarak maksimum ke garis pantai di Kampung Pasir Putih (1138 m). Dihitung dari garis pantai digitasi. Elevasi per titik dan akses jalan BELUM diverifikasi.');

-- Data: jalur_evakuasi
INSERT INTO `jalur_evakuasi` (`id_jalur`, `id_wilayah`, `id_titik`, `panjang_lurus_m`, `panjang_jalur_m`, `waktu_tempuh_menit`, `keterangan`) VALUES
(1, 1, 1, 172.7, 224.5, 5, 'Jarak lurus titik permukiman ke titik aman 173 m; estimasi panjang jalur 225 m (faktor liku 1.3); waktu tempuh 5 menit pada laju 0.75 m/s. Jalur nyata belum disurvei.'),
(2, 2, 2, 587.1, 763.2, 17, 'Jarak lurus titik permukiman ke titik aman 587 m; estimasi panjang jalur 763 m (faktor liku 1.3); waktu tempuh 17 menit pada laju 0.75 m/s. Jalur nyata belum disurvei.'),
(3, 3, 3, 222.3, 289, 6.4, 'Jarak lurus titik permukiman ke titik aman 222 m; estimasi panjang jalur 289 m (faktor liku 1.3); waktu tempuh 6 menit pada laju 0.75 m/s. Jalur nyata belum disurvei.'),
(4, 4, 4, 383.3, 498.3, 11.1, 'Jarak lurus titik permukiman ke titik aman 383 m; estimasi panjang jalur 498 m (faktor liku 1.3); waktu tempuh 11 menit pada laju 0.75 m/s. Jalur nyata belum disurvei.'),
(5, 5, 5, 533.3, 693.3, 15.4, 'Jarak lurus titik permukiman ke titik aman 533 m; estimasi panjang jalur 693 m (faktor liku 1.3); waktu tempuh 15 menit pada laju 0.75 m/s. Jalur nyata belum disurvei.'),
(6, 6, 6, 535.2, 695.8, 15.5, 'Jarak lurus titik permukiman ke titik aman 535 m; estimasi panjang jalur 696 m (faktor liku 1.3); waktu tempuh 15 menit pada laju 0.75 m/s. Jalur nyata belum disurvei.'),
(7, 7, 7, 207.2, 269.4, 6, 'Jarak lurus titik permukiman ke titik aman 207 m; estimasi panjang jalur 269 m (faktor liku 1.3); waktu tempuh 6 menit pada laju 0.75 m/s. Jalur nyata belum disurvei.');

SET FOREIGN_KEY_CHECKS=1;
