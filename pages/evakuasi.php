<?php
declare(strict_types=1);
/*
 * Halaman Titik Aman dan Evakuasi.
 * Menyajikan: profil pulau hasil hitungan geometri, prioritas evakuasi per kampung,
 * daftar titik aman beserta asal datanya, peta, rekomendasi, dan daftar asumsi.
 * Administrator memperoleh pengelolaan data (CRUD) titik dan jalur evakuasi.
 */

/* ---------------------------------------------------------------- bantu ---------- */
function evak_fmt_koor(float $lat, float $lon): string {
    return nf($lat, 6) . '° ' . ($lat < 0 ? 'LS' : 'LU') . ', ' . nf($lon, 6) . '° BT';
}
function evak_warna_urutan(int $urut, int $n): string {
    $ts = tingkat_list();
    $r = $urut <= max(1, (int) ceil($n / 3)) ? 2 : ($urut <= (int) ceil($n * 2 / 3) ? 1 : 0);
    return $ts[min($r, count($ts) - 1)]['warna'] ?? 'var(--primary)';
}

/* ---------------------------------------------------------------- halaman ---------- */
function pg_evakuasi(): array {
    if (!evak_ada()) {
        return ['title' => 'Titik Aman dan Evakuasi', 'html' => page_head('Titik Aman dan Evakuasi', 'Titik aman, jalur, dan rekomendasi evakuasi Pulo Panjang.')
            . note_box('Data titik evakuasi belum ada di basis data. Jalankan ulang pemasangan, atau impor '
                . '<span class="mono">database/database.sql</span> lewat phpMyAdmin. '
                . 'Administrator juga dapat menambahkan titik satu per satu setelah data tersedia.', 'warn', 'alert')];
    }
    if (user_role() === 'warga') return evak_view_warga();
    $tab = (string) gv('tab', 'peta');
    if (user_role() === 'admin' && $tab === 'titik') return evak_crud_titik();
    if (user_role() === 'admin' && $tab === 'jalur') return evak_crud_jalur();
    if (gv('unduh') === 'csv') return evak_unduh();
    return evak_view_ringkas($tab);
}

function evak_tabs(string $tab): string {
    $items = [['peta', 'Peta evakuasi', 'map'], ['ringkas', 'Ringkasan dan prioritas', 'flag'], ['rekomendasi', 'Rekomendasi', 'shield']];
    if (user_role() === 'admin') { $items[] = ['titik', 'Kelola titik', 'pin']; $items[] = ['jalur', 'Kelola jalur', 'compass']; }
    return seg($items, $tab, fn($v) => url('evakuasi', ['tab' => $v]), 'Tampilan');
}

/* ---- ringkasan dan prioritas ---- */
function evak_view_ringkas(string $tab): array {
    if ($tab === 'rekomendasi') return evak_view_rekomendasi();
    if ($tab === 'peta') return evak_view_peta();
    $p = evak_pulau(); $tot = evak_kebutuhan_total(); $as = evak_asumsi(); $utama = evak_titik_utama();
    $acts = evak_tabs('ringkas') . '<a class="btn" href="' . e(url('evakuasi', ['unduh' => 'csv'])) . '">' . ic('download', 18) . 'Unduh CSV</a>';
    $h = page_head('Titik Aman dan Evakuasi', 'Prioritas evakuasi tiap kampung dan usulan titik aman di Pulo Panjang, beserta asal setiap angka.', $acts);
    $h .= evak_catatan_status() . '<div style="height:18px"></div>';

    /* profil pulau */
    $h .= '<div class="grid g4 keep2" style="margin-bottom:18px">'
        . stat_tile(nf($p['luas_km2'] ?? 0, 3), 'Luas pulau', 'Setara ' . nf($p['luas_ha'] ?? 0, 0) . ' ha', 'var(--primary)', 'km²')
        . stat_tile(nf($p['panjang_garis_pantai_km'] ?? 0, 2), 'Panjang garis pantai', 'Hasil digitasi', '#2E9E9B', 'km')
        . stat_tile(nf($p['titik_terjauh_dari_pantai']['jarak_ke_pantai_m'] ?? 0, 0), 'Titik terdalam pulau', 'Jarak maksimum ke pantai', '#5E7480', 'm')
        . stat_tile(nf($tot['kk']), 'Kepala keluarga', nf($tot['jiwa']) . ' jiwa (' . e($tot['sumber']) . ')', '#8B6F47', 'KK')
        . '</div>';

    /* tabel prioritas */
    $pr = evak_prioritas(); $n = count($pr); $sel = (int) gv('sel', 0); $tr = '';
    foreach ($pr as $r) {
        $w = $r['w']; $t = $r['titik']; $j = $r['jalur']; $k = $r['kebutuhan'];
        $tr .= '<tr' . ($sel === $w['id_wilayah'] ? ' style="outline:2px solid var(--primary);outline-offset:-2px"' : '') . '>'
            . '<td class="c"><b class="num" style="color:' . evak_warna_urutan($r['urutan'], $n) . '">' . $r['urutan'] . '</b></td>'
            . '<td><b>' . e($w['nama_wilayah']) . '</b><span class="sub">' . e(wil_desc($w) ?: $w['tipe']) . '</span></td>'
            . '<td>' . tingkat_tag($r['inf']['tingkat'] ?? null) . ($r['skor'] !== null ? '<span class="sub num">skor ' . nf($r['skor'], 2) . '</span>' : '') . '</td>'
            . '<td class="r num">' . ($r['elev'] === null ? '–' : nfa($r['elev']) . ' m') . '</td>'
            . '<td class="r num">' . ($r['jarak_param'] === null ? '–' : nf($r['jarak_param'], 0) . ' m') . '</td>'
            . '<td class="r num">' . nf($k['kk']) . '<span class="sub">≈ ' . nf($k['jiwa']) . ' jiwa</span></td>'
            . '<td class="r num">' . nf($k['luas_m2'], 0) . ' m²</td>'
            . '<td>' . ($t ? '<b>' . e($t['nama_titik']) . '</b><span class="sub num">' . nf($t['jarak_pantai_m'], 0) . ' m dari pantai</span>' : '<span class="faint">belum ada</span>') . '</td>'
            . '<td class="r num">' . ($j ? nf($j['panjang_jalur_m'], 0) . ' m<span class="sub">≈ ' . nf($j['waktu_tempuh_menit'], 0) . ' menit</span>' : '–') . '</td>'
            . '</tr>';
    }
    $h .= '<div class="panel" style="margin-bottom:18px"><div class="panel-h"><div><h2>Prioritas evakuasi per kampung</h2><p>Diurutkan menurut skor kerawanan, lalu elevasi terendah, lalu jumlah KK</p></div></div>'
        . '<div class="tbl-wrap"><table class="tbl"><thead><tr><th class="c">Urutan</th><th>Kampung</th><th>Kerawanan</th><th class="r">Elevasi DEMNAS</th><th class="r">Jarak pantai</th><th class="r">Terdampak</th><th class="r">Luas tampung minimum</th><th>Usulan titik aman</th><th class="r">Jalur</th></tr></thead><tbody>' . $tr . '</tbody></table></div>'
        . '<p class="faint" style="font-size:12.5px;margin-top:10px">Elevasi dan jarak pantai berasal dari tabel data parameter (sumber DEMNAS/BIG dan survei). '
        . 'Luas tampung minimum memakai standar Sphere ' . nfa($as['luas_per_jiwa_m2'] ?? 3.5) . ' m² per jiwa. '
        . 'Jumlah KK dan jiwa per kampung berasal dari ' . e(penduduk_sumber()) . (isset($tot['kk_kampung']) ? ' (jumlah ketujuh kampung ' . nf($tot['kk_kampung']) . ' KK, ' . nf($tot['jiwa_kampung']) . ' jiwa); total desa ' . nf($tot['kk']) . ' KK, ' . nf($tot['jiwa']) . ' jiwa dari ' . e($tot['sumber']) : '') . '.</p></div>';

    /* daftar titik aman */
    $tr2 = '';
    $pElev = param_by_name('/elevasi|dem/i');
    foreach (evak_titik() as $t) {
        $w = row('wilayah', $t['id_wilayah']);
        $ek = $pElev ? (latest_data($t['id_wilayah'])[$pElev['id_parameter']]['nilai'] ?? null) : null;
        $tr2 .= '<tr><td><b>' . e($t['nama_titik']) . '</b><span class="sub">' . e($t['jenis']) . '</span></td>'
            . '<td>' . e($w['nama_wilayah'] ?? '–') . '</td>'
            . '<td class="mono" style="font-size:12.5px">' . e(evak_fmt_koor((float) $t['lintang'], (float) $t['bujur'])) . '</td>'
            . '<td class="r num">' . nf($t['jarak_pantai_m'], 0) . ' m</td>'
            . '<td class="r">' . evak_elev_cell($t['elevasi_m'] === null ? null : (float) $t['elevasi_m'], $ek === null ? null : (float) $ek) . '</td>'
            . '<td class="r num">' . ($t['kapasitas_orang'] === null ? '<span class="faint">belum disurvei</span>' : nf($t['kapasitas_orang'])) . '</td>'
            . '<td>' . evak_status_tag((string) $t['status_validasi']) . '</td></tr>';
    }
    $h .= '<div class="panel" style="margin-bottom:18px"><div class="panel-h"><div><h2>Usulan titik aman</h2><p>' . nf(count(evak_titik())) . ' titik, satu untuk setiap kampung</p></div>'
        . (user_role() === 'admin' ? '<a class="btn btn-sm" href="' . e(url('evakuasi', ['tab' => 'titik'])) . '">' . ic('edit', 16) . 'Kelola</a>' : '') . '</div>'
        . '<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Titik</th><th>Kampung</th><th>Koordinat (WGS84)</th><th class="r">Jarak pantai</th><th class="r">Elevasi</th><th class="r">Kapasitas</th><th>Status</th></tr></thead><tbody>' . $tr2 . '</tbody></table></div>'
        . note_box('Kolom <b>elevasi</b> sengaja dibiarkan kosong pada tingkat titik. Angka yang tampil dengan keterangan '
            . '"nilai kampung" adalah elevasi DEMNAS untuk seluruh kampung, <b>bukan</b> elevasi titik itu. Mengisinya dengan perkiraan '
            . 'akan membuat usulan ini tampak lebih pasti daripada kenyataannya, karena itu dibiarkan kosong sampai '
            . 'diekstraksi dari DEMNAS pada koordinat tersebut atau diukur dengan GNSS di lapangan.', 'warn', 'info') . '</div>';

    if ($utama) {
        $h .= '<div class="panel" style="margin-bottom:18px"><div class="panel-h"><div><h2>Usulan titik kumpul utama</h2><p>Titik berjarak terjauh dari garis pantai di seluruh pulau</p></div>' . evak_status_tag((string) $utama['status_validasi'], true) . '</div>'
            . '<div class="lvl-card" style="--c:var(--primary)">' . ic('target', 22) . '<b>' . e($utama['nama_titik']) . ' — ' . nf($utama['jarak_pantai_m'], 0) . ' m dari garis pantai</b>'
            . '<p>Koordinat ' . e(evak_fmt_koor((float) $utama['lintang'], (float) $utama['bujur'])) . '. Terletak di '
            . e(row('wilayah', $utama['id_wilayah'])['nama_wilayah'] ?? '-') . ', yang menurut data wilayah merupakan pusat administrasi desa dan fasilitas umum. '
            . 'Kebetulan yang menguntungkan ini membuatnya calon paling masuk akal sebagai titik kumpul bersama, dengan syarat elevasi dan akses jalannya diverifikasi lebih dulu.</p></div></div>';
    }

    $h .= evak_panel_sumber();
    return ['title' => 'Titik Aman dan Evakuasi', 'html' => $h];
}

/* ---- rekomendasi ---- */
function evak_view_rekomendasi(): array {
    $g = pasut_ada() ? pasut_gabungan() : null;
    $am = pasut_ada() ? pasut_ambang() : [];
    $pr = evak_prioritas(); $tot = evak_kebutuhan_total(); $p = evak_pulau(); $utama = evak_titik_utama();
    $maks = $g['stat']['z_maks'] ?? null;
    $rendah = array_slice(array_values(array_filter($pr, fn($r) => $r['elev'] !== null)), 0);
    usort($rendah, fn($a, $b) => $a['elev'] <=> $b['elev']);
    $dua = array_slice($rendah, 0, 2);
    $top = pasut_ada() ? pasut_hari_tertinggi(6) : [];

    $h = page_head('Rekomendasi Evakuasi', 'Kesimpulan dan anjuran berdasarkan data pasang surut BIG/SRGI, elevasi DEMNAS, dan geometri pulau.', evak_tabs('rekomendasi'));
    $h .= evak_catatan_status() . '<div style="height:18px"></div>';

    $rek = [
        ['Rob di Pulo Panjang bukan bahaya yang menuntut evakuasi jauh ke pedalaman',
         'Muka air tertinggi pada seluruh prediksi BIG 2024–2028 hanya <b>' . ($maks === null ? '–' : (($maks >= 0 ? '+' : '') . nf($maks, 3))) . ' m</b> di atas MSL. '
         . 'Genangan yang mungkin terjadi karena itu dangkal dan datang perlahan, bukan gelombang yang menerjang. '
         . 'Memindahkan warga sejauh ' . nf(max(array_map(fn($r) => (float) ($r['jalur']['panjang_jalur_m'] ?? 0), $pr)), 0) . ' m ke pedalaman tidak sebanding dengan risikonya '
         . 'dan justru menambah bahaya bagi lansia, anak, dan orang sakit. '
         . '<b>Strategi utama yang dianjurkan: evakuasi vertikal dan setempat</b> — naik ke lantai dua bangunan kokoh terdekat atau ke tanah yang lebih tinggi di dalam kampung itu sendiri. '
         . 'Titik kumpul di pedalaman disiapkan hanya untuk kejadian yang berkepanjangan atau bila rumah rusak.'],

        ['Dahulukan Kampung ' . implode(' dan Kampung ', array_map(fn($r) => str_replace('Kampung ', '', $r['w']['nama_wilayah']), $dua)),
         'Dua kampung dengan elevasi DEMNAS terendah adalah '
         . implode(', ', array_map(fn($r) => '<b>' . e($r['w']['nama_wilayah']) . '</b> (' . nfa($r['elev']) . ' m)', $dua)) . '. '
         . 'Pada elevasi sekitar satu meter, selisih beberapa sentimeter muka air menentukan terendam atau tidak, '
         . 'sehingga kedua kampung ini perlu peringatan paling dini, jalur paling jelas, dan latihan paling sering. '
         . 'Urutan prioritas lengkap ada pada tab Ringkasan dan dihitung dari skor kerawanan, elevasi, lalu jumlah KK.'],

        ['Jadwalkan kesiapsiagaan pada November–Januari dan Mei–Juni, bukan pada bulan purnama',
         'Tipe pasut di titik ini <b>' . e($g['tipe']['nama'] ?? '-') . '</b> (F = ' . nf($g['F'] ?? 0, 3) . '), artinya komponen harian tunggal K1 dan O1 '
         . 'lebih besar daripada komponen harian ganda M2 dan S2. Akibatnya muka air tertinggi mengikuti <b>deklinasi bulan</b> (pasang tropik) '
         . 'dan bukan fase bulan baru atau purnama. Terlihat jelas pada enam hari tertinggi lima tahun ke depan: '
         . ($top ? implode('; ', array_map(fn($r) => '<b>' . fmt_d($r['tgl']) . '</b> (' . (($r['z'] >= 0 ? '+' : '') . nf($r['z'], 3)) . ' m)', $top)) : '–') . '. '
         . 'Seluruhnya jatuh di bulan November–Januari dan Mei–Juni. Menamai kejadian ini "rob purnama" atau "rob supermoon" menyesatkan perencanaan.'],

        ['Peringatan dini tidak boleh bergantung pada pasang surut saja',
         'Pengujian terhadap kejadian nyata menunjukkan ini dengan tegas: pada <b>7 Oktober 2025</b>, ketika ratusan rumah di Puloampel dilaporkan terendam rob, '
         . 'prediksi pasang surut BIG untuk hari itu hanya <b>+0,223 m</b> — persentil 28, tergolong rendah, dan jauh di bawah ambang Waspada '
         . ($am ? nf($am['tingkat']['waspada']['batas'], 3) . ' m' : '') . '. '
         . 'Sistem yang hanya memantau pasang astronomis akan <b>melewatkan kejadian itu sepenuhnya</b>. '
         . 'Karena itu ambang pada aplikasi ini wajib dipakai bersama angin dan gelombang BMKG, '
         . 'dan skor kerawanan berbobot yang sudah ada (angin, gelombang, arus) tetap diperlukan, bukan diganti oleh data pasut.'],

        ['Rencanakan penampungan tersebar di tiap kampung, bukan satu lokasi terpusat',
         'Bila seluruh ' . nf($tot['kk']) . ' KK (≈ ' . nf($tot['jiwa']) . ' jiwa) harus ditampung sekaligus, kebutuhan luas terlindung mencapai '
         . '<b>' . nf($tot['luas_m2'], 0) . ' m²</b> menurut standar Sphere 3,5 m² per jiwa. Luas sebesar itu hampir pasti tidak tersedia pada satu titik di pulau ini. '
         . 'Yang realistis adalah penampungan tersebar: satu atau dua bangunan kokoh per kampung, didata kapasitasnya, '
         . 'dengan ' . ($utama ? e($utama['nama_titik']) : 'titik kumpul utama') . ' sebagai cadangan bersama.'],

        ['Lengkapi enam data ini sebelum rencana dipakai sungguhan',
         'Usulan di aplikasi ini berhenti pada apa yang dapat dihitung dari data yang ada. Yang masih harus dikerjakan di lapangan: '
         . '<ol style="margin:8px 0 0 18px">'
         . '<li>Ekstraksi elevasi DEMNAS pada koordinat tiap titik aman, bukan hanya rata-rata kampung.</li>'
         . '<li>Pendataan bangunan bertingkat beserta kapasitas tampungnya di setiap kampung.</li>'
         . '<li>Survei jalur: lebar jalan, perkerasan, titik yang ikut tergenang, dan penerangan malam.</li>'
         . '<li>Jumlah jiwa dan kelompok rentan per KK dari kantor desa, menggantikan asumsi 4 jiwa/KK.</li>'
         . '<li>Ketersediaan air bersih, sanitasi, dan listrik di calon titik penampungan.</li>'
         . '<li>Penetapan resmi oleh BPBD Kabupaten Serang dan Pemerintah Desa Pulo Panjang.</li>'
         . '</ol>'],
    ];
    $h .= '<div class="stack" style="gap:14px;margin-bottom:18px">';
    foreach ($rek as $i => [$t, $b]) {
        $h .= '<article class="panel"><div style="display:flex;gap:14px;align-items:flex-start">'
            . '<span class="avatar" style="flex:none">' . ($i + 1) . '</span>'
            . '<div><h2 style="font-size:19px;margin-bottom:8px">' . $t . '</h2><p style="max-width:80ch">' . $b . '</p></div></div></article>';
    }
    $h .= '</div>';

    $h .= note_box('Rekomendasi ini disusun dari data yang ada di aplikasi dan bersifat <b>masukan teknis</b>. '
        . 'Keputusan evakuasi tetap berada pada BPBD, Pemerintah Desa, dan pihak berwenang. ' . e(DISCLAIMER), '', 'info');
    return ['title' => 'Rekomendasi Evakuasi', 'html' => $h];
}

/* ---- panel sumber dan asumsi ---- */
function evak_panel_sumber(): string {
    $as = evak_asumsi(); $p = evak_pulau(); $srgi = evak_meta()['titik_srgi_big'] ?? [];
    $li = fn(array $a) => '<ul style="margin:6px 0 0 18px">' . implode('', array_map(fn($x) => '<li>' . e($x) . '</li>', $a)) . '</ul>';
    return '<div class="panel"><div class="panel-h"><div><h2>Asal data, metode, dan asumsi</h2><p>Agar setiap angka pada halaman ini dapat ditelusuri dan diperiksa</p></div></div>'
        . '<dl class="kv">'
        . '<dt>Dasar data</dt><dd>' . $li(evak_dasar()) . '</dd>'
        . '<dt>Cara menentukan titik aman</dt><dd>Titik di dalam kampung yang jarak terdekatnya ke garis pantai paling besar '
        . '(<i>pole of inaccessibility</i>), dicari dengan grid bertingkat 25 m hingga ketelitian sekitar 0,1 m. '
        . 'Jarak dihitung eksak terhadap seluruh segmen garis pantai, pada proyeksi lokal meter.</dd>'
        . '<dt>Luas dan garis pantai</dt><dd>Luas ' . nf($p['luas_km2'] ?? 0, 4) . ' km² dan panjang garis pantai ' . nf($p['panjang_garis_pantai_km'] ?? 0, 3) . ' km, '
        . 'dihitung dengan rumus shoelace atas garis pantai digitasi. Angka ini mengikuti ketelitian digitasi, bukan pengukuran resmi.</dd>'
        . '<dt>Titik prediksi pasut BIG</dt><dd>' . (($srgi['di_dalam_pulau'] ?? false)
            ? 'Koordinat ' . nf($srgi['lat'] ?? 0, 6) . '; ' . nf($srgi['lon'] ?? 0, 6) . ' ternyata berada <b>di daratan pulau</b>, sekitar '
              . nf($srgi['jarak_ke_garis_pantai_m'] ?? 0, 0) . ' m dari garis pantai. Prediksi BIG tetap sah dipakai sebagai gambaran pasut perairan sekitar pulau '
              . 'karena amplitudo pasut hampir tidak berubah pada jarak beberapa kilometer, namun untuk keperluan yang menuntut ketelitian tinggi '
              . 'sebaiknya data diambil ulang pada titik di perairan atau tepat di garis pantai.'
            : 'Koordinat ' . nf($srgi['lat'] ?? 0, 6) . '; ' . nf($srgi['lon'] ?? 0, 6) . '.') . '</dd>'
        . '<dt>Data penduduk</dt><dd>KK dan jiwa per kampung dari ' . e(penduduk_sumber()) . '. ' . e(penduduk_data()['catatan'] ?? '') . '</dd>'
        . '<dt>Asumsi luas penampungan</dt><dd>' . nfa($as['luas_per_jiwa_m2'] ?? 3.5) . ' m² per jiwa. ' . e($as['luas_per_jiwa_sumber'] ?? '') . '</dd>'
        . '<dt>Asumsi waktu tempuh</dt><dd>Laju jalan ' . nfa($as['laju_jalan_ms'] ?? 0.75) . ' m/s dan faktor liku jalur ' . nfa($as['faktor_liku'] ?? 1.3) . '. '
        . e($as['laju_jalan_catatan'] ?? '') . ' ' . e($as['faktor_liku_catatan'] ?? '') . '</dd>'
        . '<dt>Elevasi</dt><dd>Tidak dihitung oleh analisis ini. Nilai yang tampil berasal dari parameter Elevasi/DEM tingkat kampung pada tabel data parameter.</dd>'
        . '</dl></div>';
}

/* ---- CRUD administrator ---- */
function evak_titik_cfg(): array {
    return ['id' => 'tev', 'table' => 'titik_evakuasi', 'page' => 'evakuasi', 'noun' => 'titik evakuasi', 'add_label' => 'Tambah titik', 'wide' => true, 'pageSize' => 12, 'sort' => ['k' => 'n', 'dir' => 1],
        'search' => fn($r) => $r['nama_titik'] . ' ' . $r['jenis'] . ' ' . label_of('wilayah', $r['id_wilayah']), 'search_ph' => 'Cari titik atau kampung',
        'filters' => [['k' => 'sv', 'l' => 'Status', 'opts' => [['usulan', 'Usulan'], ['survei', 'Sudah disurvei'], ['ditetapkan', 'Ditetapkan resmi']], 'test' => fn($r, $v) => $r['status_validasi'] === $v]],
        'columns' => [
            ['k' => 'n', 'l' => 'Nama titik', 'v' => fn($r) => $r['nama_titik'], 'f' => fn($r) => '<b>' . e($r['nama_titik']) . '</b><span class="sub">' . e($r['jenis']) . '</span>'],
            ['k' => 'w', 'l' => 'Kampung', 'v' => fn($r) => label_of('wilayah', $r['id_wilayah']), 'f' => fn($r) => e(label_of('wilayah', $r['id_wilayah']))],
            ['k' => 'c', 'l' => 'Koordinat', 'cls' => 'wrap', 'f' => fn($r) => '<span class="mono" style="font-size:12.5px">' . e(evak_fmt_koor((float) $r['lintang'], (float) $r['bujur'])) . '</span>'],
            ['k' => 'd', 'l' => 'Jarak pantai (m)', 'cls' => 'r', 'v' => fn($r) => $r['jarak_pantai_m'], 'f' => fn($r) => '<span class="num">' . nf($r['jarak_pantai_m'], 0) . '</span>'],
            ['k' => 'e', 'l' => 'Elevasi (m)', 'cls' => 'r', 'v' => fn($r) => $r['elevasi_m'] ?? -1, 'f' => fn($r) => $r['elevasi_m'] === null ? '<span class="faint">belum ada</span>' : '<span class="num">' . nf($r['elevasi_m'], 2) . '</span>'],
            ['k' => 'kap', 'l' => 'Kapasitas', 'cls' => 'r', 'v' => fn($r) => $r['kapasitas_orang'] ?? -1, 'f' => fn($r) => $r['kapasitas_orang'] === null ? '<span class="faint">–</span>' : '<span class="num">' . nf($r['kapasitas_orang']) . '</span>'],
            ['k' => 'sv', 'l' => 'Status', 'v' => fn($r) => $r['status_validasi'], 'f' => fn($r) => evak_status_tag((string) $r['status_validasi'])],
            ['k' => 's', 'l' => 'Sumber', 'cls' => 'wrap', 'f' => fn($r) => '<span class="muted">' . e(label_of('sumber_data', $r['id_sumber'])) . '</span>'],
        ],
        'form' => ['id_wilayah', 'nama_titik', 'jenis', 'lintang', 'bujur', 'elevasi_m', 'jarak_pantai_m', 'kapasitas_orang', 'status_validasi', 'id_sumber', 'keterangan'],
        'defaults' => fn() => ['status_validasi' => 'usulan', 'jenis' => 'Fasilitas umum', 'id_sumber' => first_sumber()]];
}
function evak_jalur_cfg(): array {
    return ['id' => 'jev', 'table' => 'jalur_evakuasi', 'page' => 'evakuasi', 'noun' => 'jalur evakuasi', 'add_label' => 'Tambah jalur', 'wide' => true, 'pageSize' => 12,
        'search' => fn($r) => label_of('wilayah', $r['id_wilayah']) . ' ' . label_of('titik_evakuasi', $r['id_titik']), 'search_ph' => 'Cari kampung atau titik',
        'columns' => [
            ['k' => 'w', 'l' => 'Kampung asal', 'v' => fn($r) => label_of('wilayah', $r['id_wilayah']), 'f' => fn($r) => '<b>' . e(label_of('wilayah', $r['id_wilayah'])) . '</b>'],
            ['k' => 't', 'l' => 'Titik tujuan', 'v' => fn($r) => label_of('titik_evakuasi', $r['id_titik']), 'f' => fn($r) => e(label_of('titik_evakuasi', $r['id_titik']))],
            ['k' => 'pl', 'l' => 'Jarak lurus (m)', 'cls' => 'r', 'v' => fn($r) => $r['panjang_lurus_m'], 'f' => fn($r) => '<span class="num">' . nf($r['panjang_lurus_m'], 0) . '</span>'],
            ['k' => 'pj', 'l' => 'Panjang jalur (m)', 'cls' => 'r', 'v' => fn($r) => $r['panjang_jalur_m'] ?? 0, 'f' => fn($r) => '<span class="num">' . nf($r['panjang_jalur_m'], 0) . '</span>'],
            ['k' => 'wt', 'l' => 'Waktu tempuh', 'cls' => 'r', 'v' => fn($r) => $r['waktu_tempuh_menit'] ?? 0, 'f' => fn($r) => '<span class="num">' . nf($r['waktu_tempuh_menit'], 0) . ' menit</span>'],
            ['k' => 'ket', 'l' => 'Keterangan', 'cls' => 'wrap', 'f' => fn($r) => '<span class="muted" style="font-size:13px">' . e(mb_strimwidth((string) $r['keterangan'], 0, 160, '…')) . '</span>'],
        ],
        'form' => ['id_wilayah', 'id_titik', 'panjang_lurus_m', 'panjang_jalur_m', 'waktu_tempuh_menit', 'keterangan']];
}
function evak_crud_titik(): array {
    guard_admin();
    $cfg = evak_titik_cfg();
    $cfg['toolbar'] = '<details><summary class="btn" style="cursor:pointer">' . ic('refresh', 18) . 'Muat ulang dari hasil analisis</summary>'
        . '<form method="post" action="' . e(url('evakuasi', ['tab' => 'titik'])) . '" class="panel" style="position:absolute;z-index:20;margin-top:8px;max-width:380px">' . csrf_field()
        . '<input type="hidden" name="aksi" value="reimport"><p style="font-size:14px;margin-bottom:10px">Seluruh titik dan jalur evakuasi akan diganti dengan hasil hitungan pada '
        . '<span class="mono">data/evakuasi.json</span>. Perubahan yang Anda buat sendiri, termasuk elevasi dan kapasitas hasil survei, akan hilang.</p>'
        . '<label class="chk" style="margin-bottom:12px"><input type="checkbox" name="konfirmasi" value="1"><span>Saya mengerti dan ingin mengganti data</span></label>'
        . '<button class="btn btn-danger btn-sm" type="submit">Muat ulang</button></form></details>';
    if (($x = crud_action($cfg)) !== null) return ['title' => 'Kelola Titik Evakuasi', 'html' => $x];
    return ['title' => 'Kelola Titik Evakuasi', 'html' => page_head('Kelola Titik Evakuasi', 'Tambah, ubah, atau hapus titik aman. Biarkan elevasi dan kapasitas kosong bila belum diverifikasi.', evak_tabs('titik'))
        . evak_catatan_status() . '<div style="height:18px"></div>' . crud_list($cfg)];
}
function evak_crud_jalur(): array {
    guard_admin();
    $cfg = evak_jalur_cfg();
    if (($x = crud_action($cfg)) !== null) return ['title' => 'Kelola Jalur Evakuasi', 'html' => $x];
    return ['title' => 'Kelola Jalur Evakuasi', 'html' => page_head('Kelola Jalur Evakuasi', 'Jalur dari tiap kampung menuju titik aman. Panjang dan waktu tempuh masih estimasi sampai jalur disurvei.', evak_tabs('jalur'))
        . crud_list($cfg)];
}
/* Hanya menangani aksi khusus; penyimpanan dan penghapusan ditangani crud_action(). */
function pg_evakuasi_post(): void {
    if (pv('aksi') !== 'reimport') return;
    guard_admin();
    if (!pv('konfirmasi')) { flash('Centang konfirmasi untuk mengganti seluruh data.', 'warn'); redirect(url('evakuasi', ['tab' => 'titik'])); }
    [$nt, $nj] = evak_seed(true);
    flash($nt ? "Dimuat ulang: $nt titik dan $nj jalur evakuasi." : 'Berkas data/evakuasi.json tidak ditemukan atau kosong.', $nt ? 'ok' : 'warn');
    redirect(url('evakuasi', ['tab' => 'titik']));
}

/* ---- unduhan CSV ---- */
function evak_unduh(): array {
    $as = evak_asumsi();
    $rows = [['Urutan prioritas', 'Kampung', 'Skor kerawanan', 'Tingkat', 'Elevasi DEMNAS (m)', 'Jarak pantai parameter (m)',
        'KK (sensus)', 'Jiwa (sensus)', 'Luas tampung minimum (m2)',
        'Titik aman', 'Lintang', 'Bujur', 'Jarak titik ke pantai (m)', 'Elevasi titik (m)', 'Kapasitas (orang)',
        'Status penetapan', 'Jarak lurus (m)', 'Panjang jalur estimasi (m)', 'Waktu tempuh estimasi (menit)']];
    foreach (evak_prioritas() as $r) {
        $t = $r['titik']; $j = $r['jalur']; $k = $r['kebutuhan'];
        $rows[] = [$r['urutan'], $r['w']['nama_wilayah'], $r['skor'] ?? '', $r['inf']['tingkat']['nama_tingkat'] ?? '',
            $r['elev'] ?? '', $r['jarak_param'] ?? '', $k['kk'], $k['jiwa'], round($k['luas_m2'], 0),
            $t['nama_titik'] ?? '', $t['lintang'] ?? '', $t['bujur'] ?? '', $t ? round((float) $t['jarak_pantai_m'], 1) : '',
            $t['elevasi_m'] ?? '', $t['kapasitas_orang'] ?? '', $t['status_validasi'] ?? '',
            $j ? round((float) $j['panjang_lurus_m'], 1) : '', $j ? round((float) $j['panjang_jalur_m'], 1) : '',
            $j ? round((float) $j['waktu_tempuh_menit'], 1) : ''];
    }
    send_csv('evakuasi-pulo-panjang-usulan.csv', $rows);
}


/* ---- tampilan warga: ke mana harus pergi, dengan bahasa sehari-hari ---- */
function evak_view_warga(): array {
    $my = my_wil_id(); $rows = array_reverse(evak_peringkat());
    $h = page_head('Titik Aman dan Evakuasi', 'Ke mana harus pergi dan apa yang perlu disiapkan bila air laut naik.')
        . note_box('Titik aman di halaman ini <b>masih usulan dari penelitian</b> dan belum ditetapkan resmi oleh BPBD atau Pemerintah Desa. Bila ada perintah evakuasi, ikuti arahan perangkat desa lebih dulu.', 'warn', 'alert')
        . '<div style="height:18px"></div>' . evak_peta_html(true) . '<div style="height:22px"></div>';
    $cards = '';
    foreach ($rows as $r) {
        $w = $r['w']; $t = null; $j = $r['jalur']; $mine = $my === $w['id_wilayah'];
        $mnt = $j ? max(1, (int) round((float) $j['waktu_tempuh_menit'])) : null;
        $cards .= '<div class="panel" style="' . ($mine ? 'border:3px solid var(--primary)' : '') . '"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center"><h3 style="font-size:19px">' . e($w['nama_wilayah']) . ($mine ? ' <span class="tag" style="--c:var(--primary)">Kampung saya</span>' : '') . '</h3><span class="tag" style="--c:' . e($r['zona']['warna']) . '">' . e($r['zona']['label']) . '</span></div><p style="margin-top:8px;font-size:16px;line-height:1.5">'
            . ($mnt ? 'Titik kumpul usulan berjarak sekitar <b>' . $mnt . ' menit</b> berjalan kaki dari permukiman.' : 'Titik kumpul belum ditentukan.') . '</p></div>';
    }
    $h .= '<div class="lbl" style="margin-bottom:10px">Titik kumpul tiap kampung (yang paling perlu didahulukan di atas)</div><div class="grid g2" style="margin-bottom:22px">' . $cards . '</div>';
    $h .= '<div class="panel" style="margin-bottom:18px"><div class="panel-h"><h2>Yang perlu dilakukan saat air naik</h2></div><ol class="steps">'
        . '<li><div>Tetap tenang dan pantau informasi dari perangkat desa.</div></li>'
        . '<li><div>Bila air masuk ke permukiman, pindah ke tempat yang lebih tinggi atau ke lantai dua bangunan yang kokoh di dekat Anda.</div></li>'
        . '<li><div>Dahulukan lansia, anak-anak, ibu hamil, dan penyandang disabilitas.</div></li>'
        . '<li><div>Matikan listrik di rumah bila air sudah masuk. Jangan berjalan di air yang dekat kabel listrik.</div></li>'
        . '<li><div>Berkumpul di titik kumpul dan laporkan jumlah anggota keluarga kepada perangkat kampung.</div></li></ol></div>';
    $h .= '<div class="panel"><div class="panel-h"><h2>Siapkan tas siaga</h2></div><ul class="list" style="padding-left:20px;list-style:disc"><li>Surat penting (KK, KTP, ijazah) dalam plastik tertutup</li><li>Obat pribadi dan perlengkapan bayi bila ada</li><li>Senter, baterai cadangan, dan pengisi daya telepon</li><li>Air minum dan makanan untuk satu hari</li><li>Pakaian ganti dan jas hujan</li></ul></div>';
    return ['title' => 'Titik Aman dan Evakuasi', 'html' => $h, 'foot' => evak_peta_foot()];
}


function evak_peta_foot(): string { return '<script src="assets/js/leaflet.js"></script><script src="assets/js/evakmap.js?v=' . (int) @filemtime(APP_ROOT . '/assets/js/evakmap.js') . '"></script>'; }
function evak_view_peta(): array {
    $h = page_head('Peta Evakuasi', 'Zonasi keselamatan tujuh kampung, sabuk rob pesisir, dan arah evakuasi menuju zona aman utama di Pulo Panjang.', evak_tabs('peta'))
        . evak_peta_html(false);
    return ['title' => 'Peta Evakuasi', 'html' => $h, 'foot' => evak_peta_foot()];
}
