<?php
declare(strict_types=1);
/*
 * STATUS PERINGATAN OTOMATIS (v12).
 *
 * Status Normal / Waspada / Siaga / Awas tidak lagi bergantung pada administrator. Status dihitung di server
 * pada setiap permintaan dari tiga sumber, lalu diambil yang TERTINGGI:
 *
 *   1. Prediksi pasang surut BIG/SRGI (data/pasut_big/), puncak muka air dari 1 jam lalu sampai STATUS_HORIZON_JAM ke depan,
 *      dibandingkan dengan ambang persentil data BIG sendiri (pasut_ambang()):
 *        puncak >= P98   (ambang "Waspada" BIG) -> Waspada
 *        puncak >= P99,5 (ambang "Siaga"  BIG)  -> Siaga
 *      Pasang astronomis SAJA tidak pernah menaikkan status ke Awas (lihat kasus 7 Okt 2025 di README).
 *   2. Peringatan dini cuaca resmi BMKG (CAP nowcast) yang aktif dan menyebut Puloampel:
 *        Minor/Moderate -> Waspada, Severe/Extreme -> Siaga.
 *   3. Laporan warga yang SUDAH DIVERIFIKASI admin/perangkat desa dalam STATUS_LAPORAN_JAM terakhir:
 *        1 laporan -> Waspada; >= 2 laporan atau air >= 30 cm -> Siaga; air >= 50 cm -> Awas.
 *   Aturan tambahan: Awas juga bila pasut sudah Siaga DAN ada laporan terverifikasi atau BMKG Severe/Extreme.
 *
 * Peringatan yang diterbitkan administrator (menu Kelola Peringatan) tetap berlaku dan hanya dapat MENAIKKAN status.
 * Untuk menurunkan status otomatis (mis. laporan ternyata keliru) administrator memakai "Timpa status otomatis":
 * wajib alasan, berlaku paling lama 24 jam, dan tercatat di storage/status_log.json bersama nama administratornya.
 */
const STATUS_URUT = ['Normal', 'Waspada', 'Siaga', 'Awas'];
const STATUS_HORIZON_JAM = 24;
const STATUS_LAPORAN_JAM = 6;
const STATUS_LAPORAN_SIAGA_CM = 30;
const STATUS_LAPORAN_AWAS_CM = 50;
const SUMBER_LAPORAN = ['Laporan warga terverifikasi (aplikasi SEA WARNING)', 'Laporan masyarakat', ''];

function status_rank(string $s): int { $i = array_search($s, STATUS_URUT, true); return $i === false ? 0 : (int) $i; }

/* ---------- komponen ---------- */
function status_komponen_pasut(int $now): array {
    $k = ['kunci' => 'pasut', 'nama' => 'Prediksi pasang surut BIG/SRGI', 'rank' => 0, 'teks' => '', 'puncak' => null, 'sampai' => null, 'ada' => false];
    if (!function_exists('pasut_ada') || !pasut_ada()) { $k['teks'] = 'Berkas prediksi BIG tidak tersedia di server.'; return $k; }
    $deret = pasut_deret($now - 3600, $now + STATUS_HORIZON_JAM * 3600);
    $amb = pasut_ambang()['tingkat'] ?? [];
    if (!$deret || !$amb) { $k['teks'] = 'Prediksi BIG tidak mencakup waktu sekarang (berkas berakhir ' . (($r = pasut_rentang_data()) ? fmt_d(date('Y-m-d', $r[1])) : '–') . ').'; return $k; }
    $best = $deret[0]; foreach ($deret as $x) if ($x[1] > $best[1]) $best = $x;
    $k['ada'] = true; $k['puncak'] = $best;
    $w = $amb['waspada']['batas']; $s = $amb['siaga']['batas'];
    $k['rank'] = $best[1] >= $s ? 2 : ($best[1] >= $w ? 1 : 0);
    // berlaku sampai air turun lagi di bawah ambang Waspada setelah puncak (paling lama akhir jendela)
    $sampai = $best[0] + 3 * 3600; foreach ($deret as $x) if ($x[0] > $best[0] && $x[1] < $w) { $sampai = $x[0]; break; }
    $k['sampai'] = $sampai;
    $k['teks'] = 'Puncak pasang ' . STATUS_HORIZON_JAM . ' jam ke depan ' . nf($best[1], 2) . ' m pukul ' . date('H.i', $best[0]) . ' WIB (' . fmt_day(date('Y-m-d', $best[0])) . '). Ambang Waspada ' . nf($w, 2) . ' m, Siaga ' . nf($s, 2) . ' m (persentil P98 dan P99,5 data BIG 2024–2028).';
    return $k;
}
function status_komponen_bmkg(): array {
    $k = ['kunci' => 'bmkg', 'nama' => 'Peringatan dini cuaca BMKG', 'rank' => 0, 'teks' => 'Tidak ada peringatan dini BMKG aktif yang menyebut Puloampel.', 'sampai' => null, 'ada' => false];
    $a = bmkg_state()['alerts'] ?? null; if ($a === null) { $k['teks'] = 'Data peringatan BMKG belum pernah berhasil diambil.'; return $k; }
    $k['ada'] = true; $now = time();
    foreach ($a as $x) {
        if (empty($x['lokal'])) continue; $exp = $x['expires'] ? strtotime($x['expires']) : null; if ($exp && $exp < $now) continue;
        $r = in_array($x['severity'], ['Severe', 'Extreme'], true) ? 2 : 1;
        if ($r > $k['rank']) { $k['rank'] = $r; $k['sampai'] = $exp; $k['teks'] = 'BMKG: ' . ($x['event'] ?: $x['headline']) . ' (tingkat ' . (SEV[$x['severity']][0] ?? $x['severity']) . ')' . ($exp ? ', berlaku sampai ' . fmt_dt(date('Y-m-d H:i:s', $exp)) : '') . '.'; }
    }
    return $k;
}
function status_komponen_laporan(int $now): array {
    $k = ['kunci' => 'laporan', 'nama' => 'Laporan warga terverifikasi', 'rank' => 0, 'teks' => 'Tidak ada laporan genangan terverifikasi dalam ' . STATUS_LAPORAN_JAM . ' jam terakhir.', 'sampai' => null, 'ada' => true, 'n' => 0, 'maks' => 0];
    try { $rows = where('laporan_warga', fn($r) => $r['status'] === 'Terverifikasi' && ts($r['waktu_lapor']) >= $now - STATUS_LAPORAN_JAM * 3600); } catch (Throwable $e) { $k['ada'] = false; $k['teks'] = 'Tabel laporan warga belum tersedia.'; return $k; }
    if (!$rows) return $k;
    $mx = max(array_map(fn($r) => (int) $r['tinggi_air_cm'], $rows)); $n = count($rows); $last = max(array_map(fn($r) => ts($r['waktu_lapor']), $rows));
    $k['n'] = $n; $k['maks'] = $mx; $k['sampai'] = $last + STATUS_LAPORAN_JAM * 3600;
    $k['rank'] = $mx >= STATUS_LAPORAN_AWAS_CM ? 3 : (($n >= 2 || $mx >= STATUS_LAPORAN_SIAGA_CM) ? 2 : 1);
    $kp = uniq_list(array_filter(array_map(fn($r) => $r['id_wilayah'] ? short_name(label_of('wilayah', $r['id_wilayah'])) : null, $rows)));
    $k['teks'] = $n . ' laporan genangan terverifikasi dalam ' . STATUS_LAPORAN_JAM . ' jam terakhir' . ($kp ? ' (' . implode(', ', $kp) . ')' : '') . ', air tertinggi sekitar ' . $mx . ' cm.';
    return $k;
}

/* ---------- status otomatis (tanpa campur tangan admin) ---------- */
function status_otomatis(): array {
    static $c = null; if ($c !== null) return $c;
    $now = time(); $kp = [status_komponen_pasut((int) (floor($now / 3600) * 3600)), status_komponen_bmkg(), status_komponen_laporan($now)];
    $rank = max(array_column($kp, 'rank'));
    if ($kp[0]['rank'] >= 2 && ($kp[2]['rank'] >= 1 || $kp[1]['rank'] >= 2)) $rank = 3;
    $pemicu = array_values(array_filter($kp, fn($k) => $k['rank'] > 0));
    $sampai = $pemicu ? max(array_map(fn($k) => (int) ($k['sampai'] ?? $now + 3600), $pemicu)) : null;
    return $c = ['status' => STATUS_URUT[$rank], 'rank' => $rank, 'komponen' => $kp, 'pemicu' => $pemicu, 'sampai' => $sampai, 'dihitung' => $now];
}

/* ---------- penimpaan oleh administrator (tercatat) ---------- */
function status_override(): ?array {
    $f = storage_dir() . '/status_override.json'; $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    if (!is_array($j) || (int) ($j['berakhir'] ?? 0) < time()) return null; return $j;
}
function status_set_override(?array $o, array $u): void {
    $f = storage_dir() . '/status_override.json';
    if ($o === null) { $lama = status_override(); @unlink($f); if ($lama) status_log_tulis(['jenis' => 'penimpaan_dicabut', 'status' => $lama['status'], 'oleh' => $u['nama'], 'alasan' => 'Penimpaan dicabut']); return; }
    $o += ['uid' => $u['id_pengguna'], 'oleh' => $u['nama'], 'mulai' => time()];
    @file_put_contents($f, json_encode($o, JSON_UNESCAPED_UNICODE), LOCK_EX);
    status_log_tulis(['jenis' => 'penimpaan', 'status' => $o['status'], 'oleh' => $u['nama'], 'alasan' => $o['alasan'], 'sampai' => $o['berakhir']]);
}

/* ---------- catatan perubahan status ---------- */
function status_log(): array { $f = storage_dir() . '/status_log.json'; $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null; return is_array($j) ? $j : []; }
function status_log_tulis(array $e): void {
    $f = storage_dir() . '/status_log.json'; $fh = @fopen($f, 'c+'); if (!$fh) return;
    flock($fh, LOCK_EX); $j = json_decode((string) stream_get_contents($fh), true); $j = is_array($j) ? $j : [];
    $j[] = ['t' => time()] + $e; $j = array_slice($j, -300);
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($j, JSON_UNESCAPED_UNICODE)); fflush($fh); flock($fh, LOCK_UN); fclose($fh);
}

/*
 * Status yang DITAMPILKAN kepada semua pengguna. Bentuknya sama dengan baris tabel peringatan
 * (status, judul, isi, mulai, berakhir, id_sumber) ditambah: asal ('otomatis'|'manual'|'penimpaan'), alasan[], otomatis[].
 * NULL bila semuanya Normal dan tidak ada peringatan manual, sehingga tampilan lama "Tidak ada peringatan aktif" tetap berlaku.
 */
function status_peringatan(): ?array {
    static $c = false; if ($c !== false) return $c;
    $auto = status_otomatis(); $man = active_warnings()[0] ?? null; $ov = status_override(); $now = time();
    $alasan = array_map(fn($k) => $k['teks'], $auto['pemicu']);
    if ($ov) {
        $w = ['status' => $ov['status'], 'judul' => 'Status ditetapkan administrator: ' . $ov['status'], 'isi' => $ov['alasan'], 'mulai' => db_dt((int) $ov['mulai']), 'berakhir' => db_dt((int) $ov['berakhir']), 'id_sumber' => null, 'sumber_label' => 'Administrator (' . $ov['oleh'] . ')', 'asal' => 'penimpaan', 'alasan' => [$ov['alasan']]];
    } elseif ($man && status_rank($man['status']) >= $auto['rank']) {
        $w = $man + ['asal' => 'manual', 'alasan' => [], 'sumber_label' => label_of('sumber_data', $man['id_sumber'])];
    } elseif ($auto['rank'] > 0) {
        $top = $auto['pemicu']; usort($top, fn($a, $b) => $b['rank'] <=> $a['rank']); $utama = $top[0];
        $src = match ($utama['kunci']) { 'pasut' => sumber_pasut_big(), 'bmkg' => ensure_sumber(...BMKG_SRC['cap']), default => ensure_sumber(...SUMBER_LAPORAN) };
        $judul = match ($utama['kunci']) {
            'pasut' => 'Pasang tinggi diperkirakan pukul ' . date('H.i', $utama['puncak'][0]) . ' WIB (' . nf($utama['puncak'][1], 2) . ' m)',
            'bmkg' => 'Peringatan dini cuaca BMKG untuk Puloampel',
            default => 'Genangan dilaporkan warga dan sudah diverifikasi',
        };
        $w = ['status' => $auto['status'], 'judul' => $judul, 'isi' => implode(' ', $alasan) . ' ' . (status_meta()[$auto['status']]['say'] ?? ''), 'mulai' => db_dt($now), 'berakhir' => db_dt((int) $auto['sampai']), 'id_sumber' => $src, 'sumber_label' => implode(', ', array_map(fn($k) => $k['nama'], $top)), 'asal' => 'otomatis', 'alasan' => $alasan];
        if ($man) $w['alasan'][] = 'Peringatan manual "' . $man['judul'] . '" (' . $man['status'] . ') juga berlaku.';
    } else $w = null;
    $c = $w;
    // catat bila status yang tampil berubah
    $st = $w['status'] ?? 'Normal'; $log = status_log(); $last = null; for ($i = count($log) - 1; $i >= 0; $i--) if (($log[$i]['jenis'] ?? '') === 'status') { $last = $log[$i]; break; }
    if (!$last || $last['status'] !== $st) status_log_tulis(['jenis' => 'status', 'status' => $st, 'asal' => $w['asal'] ?? 'otomatis', 'alasan' => $w ? (implode(' ', $w['alasan']) ?: $w['judul']) : 'Semua sumber dalam batas normal.']);
    return $c;
}
function status_sumber_label(array $w): string { return (string) ($w['sumber_label'] ?? label_of('sumber_data', $w['id_sumber'])); }
function status_asal_tag(array $w): string {
    return match ($w['asal'] ?? 'manual') {
        'otomatis' => '<span class="tag" style="--c:var(--primary)">' . ic('zap', 14) . 'Otomatis</span>',
        'penimpaan' => '<span class="tag" style="--c:#5E7480">' . ic('lock', 14) . 'Ditetapkan admin</span>',
        default => '<span class="tag plain">' . ic('user', 14) . 'Diterbitkan admin</span>' };
}

/* Panel rincian: dasar penentuan status (dipakai di Peringatan Dini dan dashboard admin). */
function status_rincian_html(bool $admin = false): string {
    $a = status_otomatis(); $w = status_peringatan(); $ov = status_override();
    $h = '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">' . status_tag($w['status'] ?? 'Normal', true) . ($w ? status_asal_tag($w) : '<span class="tag" style="--c:var(--primary)">' . ic('zap', 14) . 'Otomatis</span>') . '<span class="faint" style="font-size:13px">Dihitung ' . fmt_t(date('Y-m-d H:i:s', $a['dihitung'])) . ' WIB. Status otomatis: <b>' . e($a['status']) . '</b>.</span></div><div class="list">';
    foreach ($a['komponen'] as $k) $h .= '<div style="display:grid;grid-template-columns:auto 1fr;gap:10px;align-items:start"><span style="margin-top:2px">' . status_tag(STATUS_URUT[$k['rank']]) . '</span><div><b>' . e($k['nama']) . '</b><div class="muted" style="font-size:13.5px">' . e($k['teks']) . '</div></div></div>';
    $h .= '</div>';
    if ($ov) $h .= '<div style="margin-top:12px">' . note_box('Status otomatis sedang <b>ditimpa</b> menjadi <b>' . e($ov['status']) . '</b> oleh ' . e($ov['oleh']) . ' sampai ' . fmt_dt(date('Y-m-d H:i:s', (int) $ov['berakhir'])) . '. Alasan: ' . e($ov['alasan']), 'warn', 'lock') . '</div>';
    if ($admin && $w && ($w['asal'] ?? '') !== 'otomatis' && status_rank($w['status']) < $a['rank']) $h .= '<div style="margin-top:12px">' . note_box('Status yang tampil lebih rendah daripada hasil otomatis (' . e($a['status']) . ').', 'bad', 'alert') . '</div>';
    return $h . '<p class="faint" style="font-size:12.5px;margin-top:12px">Aturan: pasut BIG ≥ P98 → Waspada, ≥ P99,5 → Siaga. BMKG menyebut Puloampel → Waspada (Parah/Ekstrem → Siaga). Laporan terverifikasi ' . STATUS_LAPORAN_JAM . ' jam terakhir: 1 → Waspada, ≥ 2 atau ≥ ' . STATUS_LAPORAN_SIAGA_CM . ' cm → Siaga, ≥ ' . STATUS_LAPORAN_AWAS_CM . ' cm → Awas. Pasut Siaga ditambah laporan atau BMKG parah → Awas. Peringatan admin hanya dapat menaikkan status.</p>';
}

/* Formulir penimpaan dan catatan perubahan (khusus administrator, menu Kelola Peringatan). */
function status_admin_html(): string {
    $ov = status_override(); $opts = ''; foreach (STATUS_URUT as $s) $opts .= '<option value="' . $s . '">' . $s . '</option>';
    $h = '<form method="post" action="' . e(url('peringatan')) . '" class="row2" style="align-items:end">' . csrf_field() . '<input type="hidden" name="aksi" value="timpa">'
        . '<div class="field"><label for="ov-st">Tetapkan status</label><select class="select" id="ov-st" name="status">' . $opts . '</select></div>'
        . '<div class="field"><label for="ov-jam">Berlaku selama</label><select class="select" id="ov-jam" name="jam">' . implode('', array_map(fn($j) => '<option value="' . $j . '">' . $j . ' jam</option>', [1, 3, 6, 12, 24])) . '</select></div>'
        . '<div class="field" style="grid-column:1/-1"><label for="ov-al">Alasan (wajib, tercatat)</label><input class="input" id="ov-al" name="alasan" maxlength="250" required placeholder="Contoh: laporan warga di Peres ternyata genangan hujan, sudah dicek RT"></div>'
        . '<div style="grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap"><button class="btn" type="submit">' . ic('lock', 16) . 'Timpa status otomatis</button></div></form>';
    if ($ov) $h .= '<form method="post" action="' . e(url('peringatan')) . '" style="margin-top:8px">' . csrf_field() . '<input type="hidden" name="aksi" value="cabut"><button class="btn btn-sm btn-danger" type="submit">' . ic('x', 16) . 'Cabut penimpaan, kembali ke status otomatis</button></form>';
    $log = array_reverse(array_slice(status_log(), -15));
    if ($log) { $h .= '<div class="hr"></div><h3 style="font-size:15px;margin-bottom:8px">Catatan perubahan status</h3><div class="tbl-wrap"><table class="tbl" style="font-size:13px"><thead><tr><th>Waktu</th><th>Status</th><th>Jenis</th><th>Keterangan</th></tr></thead><tbody>';
        foreach ($log as $e) $h .= '<tr><td class="num">' . fmt_dt(date('Y-m-d H:i:s', (int) $e['t'])) . '</td><td>' . status_tag((string) ($e['status'] ?? 'Normal')) . '</td><td>' . e(['status' => 'Perubahan (' . ($e['asal'] ?? '') . ')', 'penimpaan' => 'Ditimpa ' . ($e['oleh'] ?? ''), 'penimpaan_dicabut' => 'Dicabut ' . ($e['oleh'] ?? '')][$e['jenis']] ?? $e['jenis']) . '</td><td class="wrap">' . e((string) ($e['alasan'] ?? '')) . '</td></tr>';
        $h .= '</tbody></table></div>'; }
    return $h;
}
