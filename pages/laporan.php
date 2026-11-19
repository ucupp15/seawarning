<?php
declare(strict_types=1);
/* Halaman Laporkan Genangan (warga) dan Laporan Warga (admin, perangkat desa, mahasiswa). */

/* ---------------- warga: kirim laporan ---------------- */
function pg_lapor_post(): void {
    $u = current_user(); $aksi = (string) pv('aksi', 'kirim');
    if (!laporan_tabel_ada()) { flash('Tabel laporan belum tersedia. Muat ulang halaman.', 'warn'); redirect(url('lapor')); }
    if ($aksi === 'hapus') {
        $r = row('laporan_warga', (int) pv('id'));
        if ($r && (int) $r['id_pengguna'] === (int) $u['id_pengguna'] && $r['status'] === 'Menunggu') { dbq('DELETE FROM laporan_warga WHERE id_laporan = ?', [$r['id_laporan']]); tbl_flush('laporan_warga'); laporan_hapus_berkas((string) $r['foto']); flash('Laporan dibatalkan.'); }
        else flash('Laporan tidak dapat dibatalkan.', 'warn');
        redirect(url('lapor'));
    }
    $err = []; $old = ['tinggi' => (string) pv('tinggi', ''), 'ket' => (string) pv('keterangan', ''), 'kp' => (string) pv('kampung', '')];
    if ($m = laporan_cek_batas($u)) { flash($m, 'warn'); redirect(url('lapor')); }
    $tinggi = (int) pv('tinggi', -1); if (!isset(TINGGI_AIR[$tinggi])) $err[] = 'Pilih perkiraan tinggi air.';
    $ket = trim((string) pv('keterangan', '')); if (mb_strlen($ket) > 500) $err[] = 'Keterangan maksimal 500 karakter.';
    $lat = pv('lat'); $lng = pv('lng'); $akur = pv('akurasi'); $src = (string) pv('sumber_lokasi', ''); $idW = (int) pv('kampung', 0);
    if (is_numeric($lat) && is_numeric($lng)) {
        $lat = (float) $lat; $lng = (float) $lng; if (!in_array($src, ['gps', 'peta'], true)) $src = 'peta';
        if (!laporan_dalam_pulau($lat, $lng)) $err[] = 'Lokasi berada di luar Pulo Panjang. Geser penanda ke tempat genangan, atau pilih kampung.';
        if (!$idW || !row('wilayah', $idW)) $idW = laporan_kampung_terdekat($lat, $lng) ?? 0;
        $akur = is_numeric($akur) && (float) $akur > 0 ? min(99999, round((float) $akur, 1)) : null;
    } elseif ($idW && ($w = row('wilayah', $idW)) && ($t = wil_titik($w))) { [$lat, $lng] = $t; $src = 'kampung'; $akur = null; }
    else $err[] = 'Tentukan lokasi: tekan "Pakai lokasi saya", ketuk peta, atau pilih kampung.';
    if (!$err) { [$ok, $foto] = laporan_simpan_foto($_FILES['foto'] ?? null); if (!$ok) $err[] = $foto; }
    if ($err) { $_SESSION['lapor_old'] = $old; flash(implode(' ', $err), 'warn'); redirect(url('lapor')); }
    ins('laporan_warga', ['id_pengguna' => $u['id_pengguna'], 'id_wilayah' => $idW ?: null, 'lintang' => round((float) $lat, 6), 'bujur' => round((float) $lng, 6), 'akurasi_m' => $akur, 'sumber_lokasi' => $src, 'tinggi_air_cm' => $tinggi, 'keterangan' => mb_substr($ket, 0, 500), 'foto' => $foto, 'status' => 'Menunggu', 'waktu_lapor' => db_dt()]);
    flash('Terima kasih. Laporan terkirim dan akan diperiksa perangkat desa. Bila air terus naik, segera menuju titik kumpul.');
    redirect(url('lapor'));
}
function pg_lapor(): array {
    $u = current_user(); $old = $_SESSION['lapor_old'] ?? []; unset($_SESSION['lapor_old']);
    if (!laporan_tabel_ada()) return ['title' => 'Laporkan Genangan', 'html' => page_head('Laporkan Genangan') . note_box('Tabel laporan belum tersedia. Muat ulang halaman ini.', 'warn', 'alert')];
    $mine = array_values(array_filter(laporan_semua(), fn($r) => (int) $r['id_pengguna'] === (int) $u['id_pengguna']));
    $kp = '<option value="">Pilih kampung (bila lokasi tidak dapat ditentukan)</option>'; foreach (tbl('wilayah') as $w) $kp .= '<option value="' . $w['id_wilayah'] . '"' . ((string) $w['id_wilayah'] === (string) ($old['kp'] ?? '') ? ' selected' : '') . '>' . e($w['nama_wilayah']) . '</option>';
    $ti = ''; foreach (TINGGI_AIR as $cm => $lb) $ti .= '<label class="chk"><input type="radio" name="tinggi" value="' . $cm . '" required' . ((string) $cm === (string) ($old['tinggi'] ?? '') ? ' checked' : '') . '><span>' . e($lb) . '</span></label>';
    $batas = laporan_cek_batas($u);
    $h = page_head('Laporkan Genangan', 'Kirim foto dan lokasi genangan air laut (rob) yang Anda lihat. Laporan diperiksa perangkat desa; laporan yang sudah diverifikasi ikut menentukan status peringatan desa.');
    $h .= note_box('<b>Utamakan keselamatan.</b> Jangan memotret dari tempat berbahaya. Bila air terus naik, segera menuju titik kumpul dan ikuti arahan perangkat desa.', 'warn', 'shield') . '<div style="height:14px"></div>';
    $form = $batas ? note_box(e($batas), 'warn', 'alert') : '<form method="post" action="' . e(url('lapor')) . '" enctype="multipart/form-data" id="lapor-form">' . csrf_field() . '<input type="hidden" name="aksi" value="kirim"><input type="hidden" name="MAX_FILE_SIZE" value="' . (LAPORAN_FOTO_MB * 1048576) . '">'
        . '<div class="field"><label for="lp-foto">1. Foto genangan <span class="faint">*</span></label><input class="input" id="lp-foto" name="foto" type="file" accept="image/jpeg,image/png,image/webp,image/*" required><div class="hint">JPEG, PNG, atau WebP, maksimal ' . LAPORAN_FOTO_MB . ' MB. Foto besar diperkecil otomatis sebelum dikirim agar hemat kuota.</div><img id="lp-prev" alt="Pratinjau foto" hidden style="margin-top:10px;max-width:100%;max-height:240px;border-radius:12px;border:var(--bw) solid var(--line)"></div>'
        . '<div class="field"><label>2. Lokasi genangan <span class="faint">*</span></label><div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px"><button class="btn btn-sm" type="button" id="lp-gps" hidden>' . ic('pin', 16) . 'Pakai lokasi saya</button><span class="muted" id="lp-loc" style="font-size:13.5px">Ketuk peta di tempat genangan, atau pilih kampung di bawah.</span></div>'
        . laporan_peta_html(laporan_peta_data([], 'pilih', false)) . '<input type="hidden" name="lat" id="lp-lat"><input type="hidden" name="lng" id="lp-lng"><input type="hidden" name="akurasi" id="lp-acc"><input type="hidden" name="sumber_lokasi" id="lp-src">'
        . '<select class="select" name="kampung" id="lp-kp" style="margin-top:10px" aria-label="Kampung">' . $kp . '</select></div>'
        . '<fieldset class="field" style="border:0;padding:0;margin:0 0 16px"><legend style="font-weight:600;margin-bottom:8px">3. Perkiraan tinggi air <span class="faint">*</span></legend><div class="stack" style="gap:6px">' . $ti . '</div></fieldset>'
        . '<div class="field"><label for="lp-ket">4. Keterangan (boleh dikosongkan)</label><textarea class="textarea" id="lp-ket" name="keterangan" maxlength="500" rows="3" placeholder="Contoh: air masuk ke jalan depan musala, sudah 1 jam">' . e($old['ket'] ?? '') . '</textarea></div>'
        . '<button class="btn btn-primary btn-block" style="height:48px" type="submit" id="lp-kirim">' . ic('upload', 18) . 'Kirim laporan</button><p class="faint" style="font-size:12.5px;margin-top:10px">Lokasi tepat dari ponsel hanya tersedia bila web dibuka lewat <b>https://</b> atau di komputer server sendiri (localhost). Bila tidak, ketuk peta atau pilih kampung.</p></form>';
    $h .= '<div class="grid g-main" style="align-items:start"><div class="panel"><div class="panel-h"><h2>Laporan baru</h2></div>' . $form . '</div>';
    $list = ''; foreach (array_slice($mine, 0, 20) as $r) $list .= '<div style="display:grid;grid-template-columns:72px 1fr;gap:12px;align-items:start"><a href="' . e(laporan_foto_url($r)) . '" target="_blank" rel="noopener"><img src="' . e(laporan_foto_url($r, true)) . '" alt="Foto laporan" loading="lazy" style="width:72px;height:72px;object-fit:cover;border-radius:10px"></a><div><div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">' . laporan_status_tag($r['status']) . '<span class="muted num" style="font-size:13px">' . fmt_dt($r['waktu_lapor']) . '</span></div><div style="font-size:14px;margin-top:4px">' . e(laporan_tinggi_label((int) $r['tinggi_air_cm'])) . ($r['id_wilayah'] ? ', ' . e(label_of('wilayah', $r['id_wilayah'])) : '') . '</div>' . ($r['catatan_verifikasi'] ? '<div class="faint" style="font-size:13px">Catatan: ' . e($r['catatan_verifikasi']) . '</div>' : '')
        . ($r['status'] === 'Menunggu' ? '<form method="post" action="' . e(url('lapor')) . '" style="margin-top:6px">' . csrf_field() . '<input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="' . $r['id_laporan'] . '"><button class="btn btn-sm btn-ghost" type="submit">Batalkan</button></form>' : '') . '</div></div>';
    $h .= '<div class="panel"><div class="panel-h"><h2>Laporan saya</h2></div>' . ($list ? '<div class="list">' . $list . '</div>' : empty_state('Belum ada laporan', 'Laporan yang Anda kirim akan tampil di sini beserta statusnya.', 'camera')) . '</div></div>';
    return ['title' => 'Laporkan Genangan', 'html' => $h, 'foot' => laporan_assets()];
}

/* ---------------- staf: tinjau dan verifikasi ---------------- */
function pg_laporan_post(): void {
    $u = require_role(...PERAN_STAF_LAPORAN); $r = row('laporan_warga', (int) pv('id')); $aksi = (string) pv('aksi'); $back = (string) pv('back', url('laporan'));
    $back = preg_match('~^index\.php\?~', $back) ? $back : url('laporan');
    if (!$r) { flash('Laporan tidak ditemukan.', 'warn'); redirect($back); }
    $cat = mb_substr(trim((string) pv('catatan', '')), 0, 255);
    if ($aksi === 'verifikasi' || $aksi === 'tolak') {
        if ($aksi === 'tolak' && $cat === '') { flash('Tuliskan alasan penolakan agar pelapor memahaminya.', 'warn'); redirect($back); }
        upd('laporan_warga', (int) $r['id_laporan'], ['status' => $aksi === 'verifikasi' ? 'Terverifikasi' : 'Ditolak', 'id_verifikator' => $u['id_pengguna'], 'catatan_verifikasi' => $cat, 'waktu_verifikasi' => db_dt()]);
        flash($aksi === 'verifikasi' ? 'Laporan diverifikasi. Laporan ini kini ikut menentukan status peringatan otomatis.' : 'Laporan ditolak.');
    } elseif ($aksi === 'kembalikan') { upd('laporan_warga', (int) $r['id_laporan'], ['status' => 'Menunggu', 'id_verifikator' => null, 'catatan_verifikasi' => '', 'waktu_verifikasi' => null]); flash('Laporan dikembalikan ke antrean.'); }
    elseif ($aksi === 'hapus' && $u['peran'] === 'admin') { dbq('DELETE FROM laporan_warga WHERE id_laporan = ?', [$r['id_laporan']]); tbl_flush('laporan_warga'); laporan_hapus_berkas((string) $r['foto']); flash('Laporan dan fotonya dihapus.'); }
    redirect($back);
}
function pg_laporan(): array {
    $u = current_user(); $staf = in_array($u['peran'], PERAN_STAF_LAPORAN, true); $anon = $u['peran'] === 'mahasiswa';
    if (!laporan_tabel_ada()) return ['title' => 'Laporan Warga', 'html' => page_head('Laporan Warga') . note_box('Tabel laporan belum tersedia. Muat ulang halaman ini.', 'warn', 'alert')];
    $all = laporan_semua(); $f = (string) gv('st', 'Menunggu'); if (!in_array($f, ['Menunggu', 'Terverifikasi', 'Ditolak', 'semua'], true)) $f = 'Menunggu';
    $hari = (int) gv('hari', 7); if (!in_array($hari, [1, 7, 30, 365], true)) $hari = 7;
    $rows = array_values(array_filter($all, fn($r) => ($f === 'semua' || $r['status'] === $f) && ts($r['waktu_lapor']) >= time() - $hari * 86400));
    $cnt = fn($s) => count(array_filter($all, fn($r) => $r['status'] === $s));
    $tabs = seg([['Menunggu', 'Menunggu (' . $cnt('Menunggu') . ')', 'history'], ['Terverifikasi', 'Terverifikasi', 'checkc'], ['Ditolak', 'Ditolak', 'x'], ['semua', 'Semua', 'layers']], $f, fn($v) => url('laporan', ['st' => $v, 'hari' => $hari]), 'Status laporan');
    $hs = seg([['1', '24 jam'], ['7', '7 hari'], ['30', '30 hari'], ['365', '1 tahun']], (string) $hari, fn($v) => url('laporan', ['st' => $f, 'hari' => $v]), 'Rentang waktu');
    $kl = status_komponen_laporan(time());
    $h = page_head('Laporan Warga', 'Foto dan lokasi genangan kiriman warga. ' . ($staf ? 'Periksa setiap laporan (bila perlu hubungi RT setempat), lalu verifikasi atau tolak.' : 'Tampilan baca saja.'), $tabs);
    $h .= '<div class="grid g4 keep2" style="margin-bottom:16px">' . stat_tile((string) $cnt('Menunggu'), 'Menunggu verifikasi', '', '#E6A100') . stat_tile((string) $kl['n'], 'Terverifikasi ' . STATUS_LAPORAN_JAM . ' jam terakhir', $kl['n'] ? 'Air tertinggi ±' . $kl['maks'] . ' cm' : 'Tidak memengaruhi status', '#D64933') . stat_tile(STATUS_URUT[$kl['rank']], 'Pengaruh ke status', 'Dari laporan saja', status_meta()[STATUS_URUT[$kl['rank']]]['c']) . stat_tile((string) count($all), 'Total laporan', 'Sejak awal', '#8493A0') . '</div>';
    $h .= '<div style="margin-bottom:12px">' . $hs . '</div><div class="panel" style="margin-bottom:16px">' . laporan_peta_html(laporan_peta_data($rows, 'daftar', $staf)) . '<div class="chart-leg" style="margin-top:10px">' . implode('', array_map(fn($s, $c) => '<span class="it"><i style="--c:' . $c . '"></i>' . e($s) . '</span>', array_keys(LAPORAN_STATUS_C), LAPORAN_STATUS_C)) . '</div></div>';
    $back = url('laporan', ['st' => $f, 'hari' => $hari]); $cards = '';
    foreach ($rows as $r) {
        $pel = $anon ? 'Warga' : label_of('pengguna', (int) $r['id_pengguna']); $ver = $r['id_verifikator'] ? label_of('pengguna', (int) $r['id_verifikator']) : '';
        $lok = nf($r['lintang'], 5) . '; ' . nf($r['bujur'], 5) . ' (' . e(['gps' => 'GPS ponsel' . ($r['akurasi_m'] ? ', ±' . nf($r['akurasi_m'], 0) . ' m' : ''), 'peta' => 'titik di peta', 'kampung' => 'titik kampung, perkiraan'][$r['sumber_lokasi']] ?? $r['sumber_lokasi']) . ')';
        $act = '';
        if ($staf) {
            $fm = fn($aksi, $lbl, $cls, $note = false) => '<form method="post" action="' . e(url('laporan')) . '" style="display:flex;gap:6px;flex-wrap:wrap;margin:0">' . csrf_field() . '<input type="hidden" name="aksi" value="' . $aksi . '"><input type="hidden" name="id" value="' . $r['id_laporan'] . '"><input type="hidden" name="back" value="' . e($back) . '">' . ($note ? '<input class="input" name="catatan" maxlength="255" placeholder="Catatan (wajib bila menolak)" style="height:34px;min-width:200px;flex:1">' : '') . '<button class="btn btn-sm ' . $cls . '" type="submit">' . $lbl . '</button></form>';
            if ($r['status'] === 'Menunggu') $act = '<form method="post" action="' . e(url('laporan')) . '" style="display:flex;gap:6px;flex-wrap:wrap;margin:0">' . csrf_field() . '<input type="hidden" name="id" value="' . $r['id_laporan'] . '"><input type="hidden" name="back" value="' . e($back) . '"><input class="input" name="catatan" maxlength="255" placeholder="Catatan (wajib bila menolak)" style="height:34px;min-width:200px;flex:1"><button class="btn btn-sm btn-primary" name="aksi" value="verifikasi" type="submit">' . ic('check', 15) . 'Verifikasi</button><button class="btn btn-sm" name="aksi" value="tolak" type="submit">' . ic('x', 15) . 'Tolak</button></form>';
            else $act = $fm('kembalikan', 'Kembalikan ke antrean', 'btn-ghost');
            if ($u['peran'] === 'admin') $act .= $fm('hapus', ic('trash', 15) . 'Hapus', 'btn-ghost danger');
        }
        $cards .= '<article class="panel" id="lap-' . $r['id_laporan'] . '" style="border-left:6px solid ' . (LAPORAN_STATUS_C[$r['status']] ?? '#888') . '"><div style="display:grid;grid-template-columns:minmax(0,160px) 1fr;gap:16px;align-items:start"><a href="' . e(laporan_foto_url($r)) . '" target="_blank" rel="noopener"><img src="' . e(laporan_foto_url($r, true)) . '" alt="Foto genangan" loading="lazy" style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:12px"></a><div>'
            . '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:6px">' . laporan_status_tag($r['status']) . '<b>' . e(laporan_tinggi_label((int) $r['tinggi_air_cm'])) . '</b></div>'
            . '<dl class="kv" style="font-size:13.5px"><dt>Waktu</dt><dd class="num">' . fmt_dt($r['waktu_lapor']) . '</dd><dt>Kampung</dt><dd>' . e($r['id_wilayah'] ? label_of('wilayah', $r['id_wilayah']) : '–') . '</dd><dt>Lokasi</dt><dd class="num">' . $lok . ' <a href="https://www.google.com/maps?q=' . rawurlencode($r['lintang'] . ',' . $r['bujur']) . '" target="_blank" rel="noopener noreferrer">peta</a></dd><dt>Pelapor</dt><dd>' . e($pel) . '</dd>' . ($r['keterangan'] ? '<dt>Keterangan</dt><dd>' . e($r['keterangan']) . '</dd>' : '') . ($ver ? '<dt>Diperiksa</dt><dd>' . e($ver) . ', ' . fmt_dt($r['waktu_verifikasi']) . ($r['catatan_verifikasi'] ? '. ' . e($r['catatan_verifikasi']) : '') . '</dd>' : '') . '</dl>'
            . ($act ? '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">' . $act . '</div>' : '') . '</div></div></article>';
    }
    $h .= $cards ? '<div class="stack">' . $cards . '</div>' : '<div class="panel">' . empty_state('Tidak ada laporan', 'Tidak ada laporan dengan status dan rentang waktu ini.', 'camera') . '</div>';
    return ['title' => 'Laporan Warga', 'html' => $h, 'foot' => laporan_assets()];
}
