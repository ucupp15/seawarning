<?php
declare(strict_types=1);
/* Beranda untuk empat peran: Status Banjir Rob (warga), Pantauan Desa, Ringkasan Riset, Dashboard Administrator. */

const KEL_COLOR = ['Topografi' => '#8B6F47', 'Oseanografi' => '#0D84A8', 'Ekologi Perairan' => '#2E9E6B', 'Pendukung' => '#5E7480'];
const PIPELINE = [['Data topografi, oseanografi, dan ekologi perairan', 'Dikumpulkan dari sumber sekunder dan survei, disimpan pada tabel data parameter dan data oseanografi.'], ['Validasi dan standardisasi data', 'Setiap data memiliki sumber, tanggal, dan satuan yang jelas.'], ['Pengolahan parameter', 'Nilai parameter diubah menjadi skor memakai kelas skor tiap parameter.'], ['Pembobotan dan klasifikasi kerawanan', 'Skor dikalikan bobot lalu dirata-ratakan menjadi skor total wilayah.'], ['Integrasi data spasial', 'Skor wilayah digabungkan dengan geometri wilayah (GeoJSON).'], ['Penentuan zona kerawanan', 'Skor total dipetakan ke tingkat rendah, sedang, atau tinggi.'], ['Peta zona dan informasi peringatan', 'Hasil ditampilkan sebagai peta, status, dan rekomendasi tindakan untuk masyarakat.']];
function pipeline_html(): string { return '<ol class="steps">' . implode('', array_map(fn($p) => '<li><div><b>' . e($p[0]) . '</b><div class="muted" style="font-size:14px">' . e($p[1]) . '</div></div></li>', PIPELINE)) . '</ol>'; }
function wil_rows(): array { return array_map(fn($w) => ['w' => $w, 'inf' => info_wilayah($w['id_wilayah'])], tbl('wilayah')); }
function distribusi(): array { $rows = wil_rows(); return array_map(fn($t) => ['t' => $t, 'rows' => array_values(array_filter($rows, fn($r) => $r['inf'] && $r['inf']['tingkat']['id_tingkat'] === $t['id_tingkat']))], tingkat_list()); }
function donut_block(): string {
    $d = distribusi(); $tot = array_sum(array_map(fn($x) => count($x['rows']), $d)); $h = '<div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap">' . donut(array_map(fn($x) => ['value' => count($x['rows']), 'color' => $x['t']['warna']], $d), (string) $tot, 'wilayah') . '<div class="stack" style="gap:8px;flex:1;min-width:150px">';
    foreach ($d as $i => $x) $h .= '<div style="display:flex;align-items:center;gap:10px"><span class="sw" style="width:18px;height:14px;border-radius:4px;background:' . e($x['t']['warna']) . ';border:1px solid rgba(0,0,0,.25)"></span>' . lvl_icon($i, 16) . '<span style="flex:1">' . e($x['t']['nama_tingkat']) . '</span><b class="num">' . count($x['rows']) . '</b></div>';
    return $h . '</div></div>';
}
function coverage(): array { $ps = tbl('parameter'); $need = array_filter($ps, fn($p) => $p['bobot'] > 0); $o = []; foreach (tbl('wilayah') as $w) { $l = latest_data($w['id_wilayah']); $o[] = ['w' => $w, 'n' => count(array_filter($ps, fn($p) => isset($l[$p['id_parameter']]))), 'need' => count(array_filter($need, fn($p) => !isset($l[$p['id_parameter']]))), 'total' => count($ps)]; } return $o; }
function data_checks(): array {
    $ps = tbl('parameter'); $out = []; $tb = sum(array_column($ps, 'bobot')); $kelas = tbl('kelas_skor');
    $out[] = ['ok' => abs($tb - 1) < 0.005, 't' => 'Total bobot parameter sama dengan 1,00', 'd' => 'Total bobot saat ini ' . nf($tb, 2) . '.', 'go' => 'parameter'];
    $noK = array_filter($ps, fn($p) => !array_filter($kelas, fn($k) => $k['id_parameter'] === $p['id_parameter']));
    $out[] = ['ok' => !$noK, 't' => 'Setiap parameter memiliki kelas skor', 'd' => $noK ? 'Belum ada kelas skor: ' . implode(', ', array_column($noK, 'nama_parameter')) . '.' : 'Semua parameter memiliki kelas skor.', 'go' => 'parameter'];
    $miss = array_filter(coverage(), fn($c) => $c['need']);
    $out[] = ['ok' => !$miss, 't' => 'Data parameter berbobot lengkap di semua wilayah', 'd' => $miss ? implode(', ', array_map(fn($c) => $c['w']['nama_wilayah'] . ' (kurang ' . $c['need'] . ')', $miss)) . '.' : 'Semua wilayah memiliki parameter berbobot.', 'go' => 'data-parameter'];
    $mm = count(array_filter(tbl('data_parameter'), fn($d) => score_for($d['id_parameter'], (float) $d['nilai']) !== $d['skor']));
    $out[] = ['ok' => !$mm, 't' => 'Skor data sesuai kelas skor', 'd' => $mm ? "$mm data memiliki skor yang tidak sesuai kelas skor." : 'Semua skor sesuai kelas skor.', 'fix' => $mm ? 'rescore' : '', 'fixL' => 'Hitung ulang skor'];
    $dd = max([''] + array_column(tbl('data_parameter'), 'tanggal_data')); $hh = max([''] + array_column(tbl('hasil_kerawanan'), 'tanggal_analisis'));
    $out[] = ['ok' => $hh !== '' && ($dd === '' || $hh >= $dd), 't' => 'Hasil analisis lebih baru dari data terakhir', 'd' => $hh === '' ? 'Analisis belum pernah dijalankan.' : ($dd !== '' && $hh < $dd ? 'Data terbaru ' . fmt_d($dd) . ', analisis terakhir ' . fmt_d($hh) . '.' : 'Analisis terakhir ' . fmt_d($hh) . '.'), 'go' => 'analisis'];
    $cur = nearest_osea(); $out[] = ['ok' => $cur && $cur['gap_h'] <= 6, 't' => 'Data oseanografi mencakup waktu sekarang', 'd' => $cur ? ($cur['gap_h'] <= 6 ? 'Data terdekat berjarak kurang dari 6 jam.' : 'Data terdekat berjarak ' . round($cur['gap_h']) . ' jam dari sekarang.') : 'Belum ada data oseanografi.', 'go' => 'oseanografi'];
    $ts = tingkat_list(); $gap = false; foreach ($ts as $i => $t) if ($i && ($t['skor_min'] - $ts[$i - 1]['skor_maks'] > 0.011 || $t['skor_min'] <= $ts[$i - 1]['skor_maks'] - 0.0001)) $gap = true;
    $out[] = ['ok' => $ts && !$gap, 't' => 'Rentang skor tingkat kerawanan utuh', 'd' => $gap ? 'Ada celah atau tumpang tindih antar rentang skor.' : 'Rentang skor saling menyambung tanpa tumpang tindih.', 'go' => 'tingkat'];
    $demo = $_SESSION['cek_demo'] ?? null;   // password_verify cukup berat; hasil disimpan 10 menit per sesi
    if (!is_array($demo) || $demo['t'] < time() - 600) { $n = []; foreach (DEMO_PW as $un => $pw) foreach (tbl('pengguna') as $r) if (mb_strtolower($r['username']) === $un && $r['aktif'] && password_verify($pw, (string) $r['kata_sandi_hash'])) $n[] = $un; $_SESSION['cek_demo'] = $demo = ['t' => time(), 'n' => $n]; }
    $out[] = ['ok' => cfg('mode_presentasi', false) || (!$demo['n'] && !cfg('demo_mode', false)), 't' => 'Mode demo mati dan kata sandi akun demo sudah diganti', 'd' => cfg('demo_mode', false) ? 'demo_mode masih true di config.php: siapa pun dapat masuk sebagai Administrator.' : ($demo['n'] ? 'Masih memakai kata sandi bawaan: ' . implode(', ', $demo['n']) . '. Ganti di menu Pengguna atau nonaktifkan akunnya.' : 'Tidak ada akun dengan kata sandi bawaan.'), 'go' => 'pengguna'];
    return $out;
}

function pg_beranda(): array {
    return match (user_role()) { 'warga' => beranda_warga(), 'perangkat_desa' => beranda_perangkat(), 'mahasiswa' => beranda_mahasiswa(), default => beranda_admin() };
}
function wilayah_picker_form(?int $sel): string {
    $h = '<form method="post" action="index.php?p=set-my" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">' . csrf_field() . '<input type="hidden" name="back" value="' . e($_SERVER['REQUEST_URI'] ?? '') . '"><select class="select" name="id" aria-label="Pilih wilayah" style="flex:1;min-width:180px">' . ($sel ? '' : '<option value="">Pilih wilayah…</option>');
    foreach (tbl('wilayah') as $w) $h .= '<option value="' . $w['id_wilayah'] . '"' . ($w['id_wilayah'] === $sel ? ' selected' : '') . '>' . e($w['nama_wilayah']) . '</option>';
    return $h . '</select><button class="btn" type="submit">' . ic('pin', 16) . 'Simpan</button></form>';
}
/* ---------- beranda (v19, gaya SRGI): tujuan web, mengenal Pulo Panjang, menu cepat ---------- */
function beranda_data(): array { return ['p' => D()['PROFIL_DESA'], 'wid' => tbl('wilayah')]; }
function sg_art(): string { // ilustrasi tepi laut: rumah panggung di atas gelombang dan penanda muka air
    return '<svg class="sg-art-svg" viewBox="0 0 1000 240" role="img" aria-label="Ilustrasi rumah panggung di tepi laut dengan penanda muka air">'
        . '<path class="sg-w3" d="M0,170 C120,150 240,190 360,172 C480,154 600,194 720,176 C840,158 920,186 1000,170 L1000,240 L0,240 Z"/>'
        . '<path class="sg-w2" d="M0,195 C130,178 260,212 390,196 C520,180 650,214 780,198 C880,186 940,206 1000,196 L1000,240 L0,240 Z"/>'
        . '<path class="sg-w1" d="M0,216 C150,202 300,230 450,218 C600,206 760,232 900,220 L1000,216 L1000,240 L0,240 Z"/>'
        . '<g class="sg-house"><rect x="150" y="104" width="6" height="74"/><rect x="200" y="104" width="6" height="74"/><rect x="250" y="104" width="6" height="74"/><rect x="128" y="88" width="160" height="22" rx="3"/><path d="M118,90 L208,40 L298,90 Z"/><rect x="176" y="112" width="40" height="36"/></g>'
        . '<g class="sg-house sg-house-b"><rect x="450" y="120" width="6" height="58"/><rect x="500" y="120" width="6" height="58"/><rect x="420" y="104" width="120" height="20" rx="3"/><path d="M412,106 L480,62 L548,106 Z"/><rect x="464" y="126" width="32" height="30"/></g>'
        . '<g class="sg-house"><rect x="700" y="112" width="6" height="66"/><rect x="750" y="112" width="6" height="66"/><rect x="678" y="96" width="120" height="20" rx="3"/><path d="M670,98 L738,54 L806,98 Z"/><rect x="718" y="118" width="30" height="30"/></g>'
        . '<g class="sg-gauge"><rect x="905" y="40" width="10" height="150" rx="5"/><rect x="893" y="92" width="34" height="5" rx="2"/><rect x="893" y="122" width="34" height="5" rx="2"/><rect x="893" y="150" width="34" height="5" rx="2"/></g>'
        . '<circle class="sg-sun" cx="860" cy="58" r="20"/></svg>';
}
function beranda_hero_sg(array $b): string {
    $u = current_user(); $nama = trim((string) ($u['nama'] ?? ''));
    return '<section class="sg-hero rv" aria-label="Selamat datang"><div class="sg-hero-vid" aria-hidden="true"><video autoplay muted loop playsinline preload="metadata" poster="assets/media/poster.jpg"><source src="assets/media/login_loop.webm" type="video/webm"><source src="assets/media/login_loop.mp4" type="video/mp4"></video></div><div class="sg-hero-shade"></div>'
        . '<div class="sg-hero-in">'
        . '<p class="sg-kick">Selamat datang' . ($nama !== '' ? ', ' . e(explode(' ', $nama)[0]) : '') . ' di SEA WARNING</p>'
        . '<h1>Pulo Panjang, desa di tepi laut yang belajar membaca pasang</h1>'
        . '<p class="sg-lead">Kecamatan Puloampel, Kabupaten Serang, Banten. Web ini membantu warga mengenali banjir rob sebelum air naik ke permukiman.</p>'
        . '<div class="sg-act"><a class="btn btn-primary" href="' . e(url('peta')) . '">Lihat peta zona kerawanan</a><a class="btn sg-ghost" href="#tujuan">Tujuan web ini</a></div></div></section>';
}
function sg_wave(bool $flip = false): string {
    return '<div class="sg-wave' . ($flip ? ' sg-wave-f' : '') . '" aria-hidden="true"><svg viewBox="0 0 1440 56" preserveAspectRatio="none"><path d="M0,28 C240,56 480,0 720,28 C960,56 1200,6 1440,28 L1440,56 L0,56 Z"/></svg></div>';
}
function sg_head(string $kick, string $judul, string $lead = ''): string {
    return '<div class="sg-head rv"><span class="sg-kick">' . e($kick) . '</span><h2>' . e($judul) . '</h2><span class="sg-bar" aria-hidden="true"></span>' . ($lead !== '' ? '<p>' . e($lead) . '</p>' : '') . '</div>';
}
function beranda_tujuan_sg(): string {
    $tj = [['bell', 'Peringatan lebih awal', 'Status rob dari pasang surut, BMKG, dan laporan warga.'], ['map', 'Peta kampung rawan', 'Zona kerawanan tiap kampung di Pulo Panjang.'], ['camera', 'Laporan warga', 'Foto dan lokasi genangan langsung dari lapangan.'], ['flag', 'Panduan evakuasi', 'Titik aman dan langkah saat air naik.']];
    $li = ''; foreach ($tj as $n => [$ik, $a, $t]) $li .= '<li class="sg-tj"><span class="sg-tj-ic">' . ic($ik, 24) . '</span><span class="sg-tj-no num">' . sprintf('%02d', $n + 1) . '</span><h3>' . e($a) . '</h3><p>' . e($t) . '</p></li>';
    return '<section class="sg-sec sg-tj-bg rv" id="tujuan"><div class="sg-tj-art" aria-hidden="true">' . sg_art() . '</div>' . sg_head('Tujuan', 'Tujuan web ini dibuat', 'Membantu warga dan perangkat desa bersiap menghadapi banjir rob, dari pemantauan pasang sampai langkah evakuasi.') . '<ol class="sg-tj-list">' . $li . '</ol></section>' . sg_wave();
}
function beranda_mengenal_sg(array $b): string {
    $p = $b['p']; $chips = '';
    foreach ($b['wid'] as $w) $chips .= '<a class="sg-chip" href="' . e(url('peta', ['sel' => $w['id_wilayah']])) . '">' . e(kata_kampung((string) $w['nama_wilayah'])) . ' ' . ic('chevr', 13) . '</a>';
    $fact = [[(int) $p['kampung'], 'Kampung', 'map'], [(int) $p['rw'], 'RW', 'pin'], [(int) $p['rt'], 'RT', 'home'], ['8,2 km²', 'Luas wilayah', 'waves'], [(int) (penduduk_data()['desa']['jiwa'] ?? 0), 'Jumlah jiwa', 'users']];
    $fh = ''; foreach ($fact as [$v, $l, $ik]) $fh .= '<div class="sg-fact"><span class="sg-fact-ic">' . ic($ik, 20) . '</span><b class="num">' . (is_int($v) ? nf($v) : e((string) $v)) . '</b><span>' . e($l) . '</span></div>';
    return '<section class="sg-sec rv" id="mengenal">' . sg_head('Mengenal desa', 'Mengenal Pulo Panjang', 'Pulo Panjang adalah desa di wilayah kepulauan di Teluk Banten. Permukimannya berdiri berdekatan dengan garis pantai, sehingga kenaikan air pasang cepat terasa di rumah, jalan, dan tempat warga beraktivitas.')
        . '<div class="sg-facts">' . $fh . '</div>'
        . '<p class="sg-sub">Tujuh kampung dalam satu pulau. Pilih kampung untuk melihat zonanya di peta.</p><div class="sg-chips">' . $chips . '</div></section>' . sg_wave(true);
}
function beranda_pintasan(): string {
    $role = user_role(); $t = '';
    foreach (menu($role) as [$g, $items]) foreach ($items as [$id, $lb, $ik]) { if (!in_array($id, ['peta', 'analisis', 'wilayah', 'data-parameter', 'pasut', 'evakuasi', 'oseanografi', 'riwayat', 'penduduk', 'panduan'], true)) continue;
        $n = ($n ?? 0) + 1; $t .= '<a class="qa" style="--i:' . $n . '" href="' . e(url($id)) . '"><span class="qa-ic">' . ic($ik, 24) . '</span><span class="qa-lb">' . e($lb) . '</span></a>'; }
    return '<section class="sg-sec sg-menu rv" id="menu-cepat">' . sg_head('Menu cepat', 'Mulai dari sini', 'Pilih ikon untuk langsung membuka fitur yang Anda perlukan. Semua menu ini juga ada di sidebar.') . '<div class="qa-grid">' . $t . '</div></section>';
}
function beranda_umum(string $title): array {
    $b = beranda_data();
    $h = '<div class="dash sg">' . beranda_hero_sg($b) . beranda_pintasan() . beranda_mengenal_sg($b) . beranda_tujuan_sg()
        . '<p class="sg-src">Sumber data: BIG/SRGI, BMKG, DEMNAS, Sentinel-2, laporan warga, dan Sensus Penduduk Kabupaten Serang.</p></div>'
        . '<script>(function(){var d=document.querySelector(".dash");if(!d)return;var rm=window.matchMedia&&matchMedia("(prefers-reduced-motion: reduce)").matches;if(!("IntersectionObserver" in window)||rm){return;}d.classList.add("js-on");var io=new IntersectionObserver(function(es){es.forEach(function(x){if(!x.isIntersecting)return;x.target.classList.add("in");io.unobserve(x.target);});},{threshold:.05,rootMargin:"0px 0px -4% 0px"});d.querySelectorAll(".rv").forEach(function(el){io.observe(el);});})();</script>';
    return ['title' => $title, 'html' => $h];
}
function beranda_warga(): array { return beranda_umum('Dashboard'); }
function beranda_perangkat(): array { return beranda_umum('Dashboard'); }
function beranda_mahasiswa(): array { return beranda_umum('Dashboard'); }
function beranda_admin(): array { return beranda_umum('Dashboard'); }

const ROLE_COLOR = ['admin' => '#0D84A8', 'mahasiswa' => '#5E7480', 'perangkat_desa' => '#A0743A', 'warga' => '#2E9E6B'];
