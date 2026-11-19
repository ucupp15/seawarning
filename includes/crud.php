<?php
declare(strict_types=1);
/* Mesin CRUD generik: daftar (cari, filter, urut, halaman), formulir tambah/ubah, dan konfirmasi hapus. Semua divalidasi di server. */

function crud_keep_get(string $id): array { $k = []; foreach ($_GET as $n => $v) if (!is_array($v) && !str_starts_with($n, $id . '_') && !in_array($n, ['aksi', 'c', 'id', 'bmkg'], true)) $k[$n] = $v; return $k; }
function crud_url(array $cfg, array $over = []): string { $q = array_merge($_GET, $over); foreach ($q as $k => $v) if ($v === null) unset($q[$k]); return 'index.php?' . http_build_query($q); }
function crud_list_url(array $cfg): string { return 'index.php?' . http_build_query(crud_keep_get($cfg['id']) + array_filter($_GET, fn($v, $k) => str_starts_with((string) $k, $cfg['id'] . '_'), ARRAY_FILTER_USE_BOTH)); }

/* ---------- formulir ---------- */
function field_html(array $f, $val, ?string $err = null): string {
    $id = 'f_' . $f['k']; $v = $val ?? ''; $req = !empty($f['req']) ? ' <span class="faint" aria-hidden="true">*</span>' : ''; $a = fn($x = '') => 'id="' . $id . '" name="' . $id . '" ' . (!empty($f['req']) ? 'required' : '') . ' ' . (!empty($f['max']) ? 'maxlength="' . $f['max'] . '"' : '') . ' ' . $x . ($err ? ' aria-invalid="true"' : '');
    $bad = $err ? ' bad' : ''; $t = $f['t'];
    if ($t === 'bool') return '<div class="field"><label class="switch"><input type="checkbox" ' . $a($v ? 'checked' : '') . ' value="1"><span>' . e($f['l']) . '</span></label>' . (!empty($f['hint']) ? '<div class="hint">' . e($f['hint']) . '</div>' : '') . '</div>';
    switch ($t) {
        case 'longtext': $ctl = '<textarea class="textarea' . $bad . '" ' . $a() . '>' . e($v) . '</textarea>'; break;
        case 'geojson': $ctl = '<textarea class="textarea mono' . $bad . '" ' . $a('spellcheck="false"') . ' placeholder=\'{"type":"Feature","geometry":{"type":"Polygon","coordinates":[[[bujur,lintang],...]]}}\'>' . e($v) . '</textarea>'; break;
        case 'number': case 'int': $ctl = '<input class="input num' . $bad . '" type="number" ' . $a('step="' . ($t === 'int' ? 1 : ($f['step'] ?? 'any')) . '" ' . (isset($f['min']) ? 'min="' . $f['min'] . '"' : '') . ' ' . (isset($f['max']) && $t !== 'text' ? 'max="' . $f['max'] . '"' : '')) . ' value="' . e($v) . '">'; break;
        case 'date': $ctl = '<input class="input' . $bad . '" type="date" ' . $a() . ' value="' . e($v) . '">'; break;
        case 'datetime': $ctl = '<input class="input' . $bad . '" type="datetime-local" ' . $a() . ' value="' . e(to_input_dt($v)) . '">'; break;
        case 'color': $ctl = '<input class="input" type="color" ' . $a('style="padding:4px;height:42px"') . ' value="' . e($v ?: '#2E9E6B') . '">'; break;
        case 'pass': $ctl = '<input class="input' . $bad . '" type="password" ' . $a('autocomplete="new-password"') . ' value="" placeholder="' . e($f['ph'] ?? '') . '">'; break;
        case 'enum': case 'fk':
            if ($t === 'fk') { $opts = []; foreach (tbl($f['fk']) as $r) $opts[] = [$r[pk($f['fk'])], LABEL_FIELD[$f['fk']] ?? null ? $r[LABEL_FIELD[$f['fk']]] : '#' . $r[pk($f['fk'])]]; }
            else $opts = array_map(fn($o) => is_array($o) ? $o : [$o, $o], $f['opts'] ?? []);
            $ctl = '<select class="select' . $bad . '" ' . $a() . '>' . (!empty($f['req']) && $v !== '' ? '' : '<option value="">Pilih…</option>'); foreach ($opts as [$ov, $ol]) $ctl .= '<option value="' . e($ov) . '"' . ((string) $ov === (string) $v ? ' selected' : '') . '>' . e($ol) . '</option>'; $ctl .= '</select>'; break;
        default: $ctl = '<input class="input' . $bad . '" type="text" ' . $a() . ' value="' . e($v) . '"' . (!empty($f['ro']) ? ' readonly' : '') . '>';
    }
    return '<div class="field"><label for="' . $id . '">' . e($f['l']) . $req . '</label>' . $ctl . (!empty($f['hint']) ? '<div class="hint">' . e($f['hint']) . '</div>' : '') . ($err ? '<div class="err" role="alert">' . e($err) . '</div>' : '') . '</div>';
}
function read_form(array $fields, array $src): array { // -> [nilai, galat]
    $vals = []; $errs = [];
    foreach ($fields as $f) {
        $k = $f['k']; $raw = $src['f_' . $k] ?? null; if ($f['t'] === 'bool') { $vals[$k] = isset($src['f_' . $k]); continue; }
        $v = is_string($raw) ? trim($raw) : ''; if ($f['t'] === 'pass') $v = (string) $raw;
        if ($v === '') { if (!empty($f['req'])) $errs[$k] = $f['l'] . ' wajib diisi.'; $vals[$k] = in_array($f['t'], ['text', 'longtext', 'geojson', 'pass'], true) ? '' : null; continue; }
        switch ($f['t']) {
            case 'number': case 'int':
                if (!is_numeric($v)) { $errs[$k] = 'Isi dengan angka.'; break; } $n = $f['t'] === 'int' ? (int) $v : (float) $v;
                if (isset($f['min']) && $n < $f['min']) { $errs[$k] = 'Nilai minimal ' . $f['min'] . '.'; break; } if (isset($f['max']) && $f['t'] !== 'text' && $n > $f['max']) { $errs[$k] = 'Nilai maksimal ' . $f['max'] . '.'; break; } $vals[$k] = $n; break;
            case 'fk': $vals[$k] = (int) $v; if (!row($f['fk'], (int) $v)) $errs[$k] = 'Pilihan tidak valid.'; break;
            case 'enum': $ok = array_map(fn($o) => (string) (is_array($o) ? $o[0] : $o), $f['opts'] ?? []); if (!in_array($v, $ok, true)) $errs[$k] = 'Pilihan tidak valid.'; $vals[$k] = $v; break;
            case 'date': if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v)) $errs[$k] = 'Tanggal tidak valid.'; $vals[$k] = $v; break;
            case 'datetime': $t = strtotime(str_replace('T', ' ', $v)); if (!$t) $errs[$k] = 'Waktu tidak valid.'; $vals[$k] = $t ? db_dt($t) : null; break;
            case 'color': if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $v)) $errs[$k] = 'Warna tidak valid.'; $vals[$k] = $v; break;
            case 'geojson': $g = json_decode($v, true); $titikSaja = is_array($g) && ($g['type'] ?? '') === 'Feature' && ($g['geometry'] ?? null) === null; if (!is_array($g) || (!$titikSaja && !geo_rings($g))) { $errs[$k] = 'GeoJSON tidak valid atau bukan Polygon/MultiPolygon.'; break; } $vals[$k] = json_encode($g, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); break;
            default: if (!empty($f['max']) && mb_strlen($v) > $f['max']) { $errs[$k] = 'Maksimal ' . $f['max'] . ' karakter.'; break; } $vals[$k] = $v;
        }
    }
    return [$vals, $errs];
}
function crud_fields(array $cfg): array { $out = []; foreach ($cfg['form'] as $f) { $base = is_array($f) ? $f : []; $k = is_array($f) ? $f['k'] : $f; $out[] = array_merge(fdef($cfg['table'], $k) ?? ['k' => $k, 'l' => $k, 't' => 'text', 'db' => 'text'], $base); } return $out; }

/* ---------- tindakan tambah / ubah / hapus ---------- */
function crud_action(array $cfg): ?string {
    if (($_GET['c'] ?? '') !== $cfg['id']) return null; $aksi = $_GET['aksi'] ?? ''; $t = $cfg['table']; $pk = pk($t); $noun = $cfg['noun'] ?? mb_strtolower(tdef($t)['title']); $row = null; $back = crud_list_url($cfg);
    if (in_array($aksi, ['ubah', 'hapus'], true)) { $row = row($t, (int) ($_GET['id'] ?? 0)); if (!$row) { flash('Data tidak ditemukan.', 'warn'); redirect($back); } }
    if ($aksi === 'hapus') {
        if (is_post()) { csrf_check(); if (!empty($cfg['can_delete']) && ($m = ($cfg['can_delete'])($row))) { flash($m, 'warn'); redirect($back); } [$ok, $msg] = del($t, (int) $row[$pk]); flash($ok ? 'Data dihapus.' : $msg, $ok ? 'ok' : 'warn'); if ($ok && !empty($cfg['after_change'])) ($cfg['after_change'])(); redirect($back); }
        $refs = refs($t, (int) $row[$pk]); $blk = !empty($cfg['can_delete']) ? ($cfg['can_delete'])($row) : null;
        $label = $cfg['row_label'] ?? fn($r) => (string) ($r[LABEL_FIELD[$t] ?? $pk] ?? '#' . $r[$pk]);
        return page_head('Hapus ' . $noun, '') . '<div class="panel" style="max-width:640px">' . ($refs || $blk ? note_box(e($blk ?: refs_msg($refs)), 'warn', 'alert') . '<div style="margin-top:16px"><a class="btn" href="' . e($back) . '">Kembali</a></div>'
            : '<p>Hapus ' . e($noun) . ' <b>' . e($label($row)) . '</b>? Tindakan ini tidak dapat dibatalkan.</p><form method="post" action="' . e(crud_url($cfg)) . '" style="display:flex;gap:10px;margin-top:18px">' . csrf_field() . '<a class="btn" href="' . e($back) . '">Batal</a><button class="btn btn-danger" type="submit">' . ic('trash', 18) . 'Hapus</button></form>') . '</div>';
    }
    if (!in_array($aksi, ['tambah', 'ubah'], true)) return null;
    $fields = crud_fields($cfg); $errs = []; $errForm = '';
    $vals = $row ?: (!empty($cfg['defaults']) ? ($cfg['defaults'])() : []);
    if (is_post()) {
        csrf_check(); [$vals, $errs] = read_form($fields, $_POST);
        if (!$errs && !empty($cfg['before_save'])) { $m = ($cfg['before_save'])($vals, $row); if ($m) $errForm = $m; }
        if (!$errs && !$errForm) {
            if ($row) upd($t, (int) $row[$pk], $vals); else ins($t, $vals);
            if (!empty($cfg['after_change'])) ($cfg['after_change'])(); flash($row ? 'Perubahan disimpan.' : 'Data ditambahkan.'); redirect($back);
        }
        if ($errs) $errForm = 'Periksa kembali isian yang ditandai.';
        $vals = array_merge($row ?: [], $vals);
    }
    $h = page_head(($row ? 'Ubah ' : 'Tambah ') . $noun, $cfg['form_desc'] ?? '') . '<div class="panel" style="max-width:' . (!empty($cfg['wide']) ? '860' : '640') . 'px"><form method="post" action="' . e(crud_url($cfg)) . '" novalidate>' . csrf_field() . (!empty($cfg['form_top']) ? ($cfg['form_top'])($row) : '');
    foreach ($fields as $f) $h .= field_html($f, $f['t'] === 'pass' ? '' : ($vals[$f['k']] ?? null), $errs[$f['k']] ?? null);
    if (!empty($cfg['form_bottom'])) $h .= ($cfg['form_bottom'])($row, $vals);
    return $h . ($errForm ? '<div class="err" role="alert" style="margin-bottom:12px">' . e($errForm) . '</div>' : '') . '<div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap"><a class="btn" href="' . e($back) . '">Batal</a><button class="btn btn-primary" type="submit">' . ic('check', 18) . 'Simpan</button></div></form></div>';
}

/* ---------- daftar ---------- */
function crud_list(array $cfg): string {
    $id = $cfg['id']; $ro = !empty($cfg['readonly']); $t = $cfg['table'] ?? ''; $pk = $t ? pk($t) : 'id'; $rows = isset($cfg['rows']) ? ($cfg['rows'])() : tbl($t); $q = mb_strtolower(trim((string) ($_GET[$id . '_q'] ?? '')));
    if ($q !== '' && !empty($cfg['search'])) $rows = array_values(array_filter($rows, fn($r) => str_contains(mb_strtolower(($cfg['search'])($r)), $q)));
    foreach ($cfg['filters'] ?? [] as $f) { $v = $_GET[$id . '_f_' . $f['k']] ?? ''; if ($v !== '') $rows = array_values(array_filter($rows, fn($r) => ($f['test'])($r, (string) $v))); }
    $sk = $_GET[$id . '_s'] ?? ($cfg['sort']['k'] ?? null); $sd = (int) ($_GET[$id . '_d'] ?? ($cfg['sort']['dir'] ?? 1)) >= 0 ? 1 : -1;
    if ($sk) foreach ($cfg['columns'] as $c) if ($c['k'] === $sk && !empty($c['v'])) { usort($rows, function ($a, $b) use ($c, $sd) { $x = ($c['v'])($a); $y = ($c['v'])($b); return (is_numeric($x) && is_numeric($y) ? $x <=> $y : strnatcasecmp((string) $x, (string) $y)) * $sd; }); break; }
    $ps = $cfg['pageSize'] ?? 10; $total = count($rows); $pages = max(1, (int) ceil($total / $ps)); $pg = (int) clamp((int) ($_GET[$id . '_pg'] ?? 1), 1, $pages); $slice = array_slice($rows, ($pg - 1) * $ps, $ps);
    $keep = crud_keep_get($id); $hid = ''; foreach ($keep as $k => $v) $hid .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    $own = fn(array $over) => crud_url($cfg, $over);
    $tools = '<form class="tools no-print" method="get" action="index.php" role="search">' . $hid;
    if (!empty($cfg['search'])) $tools .= '<label class="search"><span class="sr">Cari</span>' . ic('search', 18) . '<input class="input" type="search" name="' . e($id) . '_q" value="' . e($_GET[$id . '_q'] ?? '') . '" placeholder="' . e($cfg['search_ph'] ?? 'Cari…') . '"></label>';
    foreach ($cfg['filters'] ?? [] as $f) { $cur = $_GET[$id . '_f_' . $f['k']] ?? ''; $tools .= '<select class="select" name="' . e($id) . '_f_' . e($f['k']) . '" aria-label="' . e($f['l']) . '"><option value="">' . e($f['l']) . ': semua</option>'; foreach ($f['opts'] as [$ov, $ol]) $tools .= '<option value="' . e($ov) . '"' . ((string) $ov === (string) $cur ? ' selected' : '') . '>' . e($ol) . '</option>'; $tools .= '</select>'; }
    if (!empty($cfg['search']) || !empty($cfg['filters'])) $tools .= '<button class="btn" type="submit">' . ic('search', 18) . 'Terapkan</button>';
    $tools .= '<span style="flex:1"></span>' . ($cfg['toolbar'] ?? '') . ((!$ro && ($cfg['can_add'] ?? true)) ? '<a class="btn btn-primary" href="' . e('index.php?' . http_build_query($keep + ['c' => $id, 'aksi' => 'tambah'])) . '">' . ic('plus', 18) . e($cfg['add_label'] ?? 'Tambah data') . '</a>' : '') . '</form>';
    $th = '<tr>'; foreach ($cfg['columns'] as $c) { if (!empty($c['v'])) { $isCur = $sk === $c['k']; $nd = $isCur ? -$sd : 1; $th .= '<th class="sortable ' . ($c['cls'] ?? '') . '" ' . ($isCur ? 'aria-sort="' . ($sd > 0 ? 'ascending' : 'descending') . '"' : '') . '><a href="' . e($own([$id . '_s' => $c['k'], $id . '_d' => $nd, $id . '_pg' => 1])) . '" style="color:inherit;text-decoration:none">' . e($c['l']) . '<span class="arr">' . ($isCur ? ($sd > 0 ? '▲' : '▼') : '↕') . '</span></a></th>'; } else $th .= '<th class="' . ($c['cls'] ?? '') . '">' . e($c['l']) . '</th>'; }
    if (!$ro || !empty($cfg['row_actions'])) $th .= '<th class="no-print"><span class="sr">Aksi</span></th>'; $th .= '</tr>'; $tb = '';
    foreach ($slice as $r) { $tb .= '<tr>'; foreach ($cfg['columns'] as $c) $tb .= '<td class="' . ($c['cls'] ?? '') . '">' . ($c['f'])($r) . '</td>';
        if (!$ro || !empty($cfg['row_actions'])) $tb .= '<td class="act no-print">' . (!empty($cfg['row_actions']) ? ($cfg['row_actions'])($r) : '') . (!$ro ? (empty($cfg['no_edit']) ? '<a class="icon-btn sm" href="' . e($own(['c' => $id, 'aksi' => 'ubah', 'id' => $r[$pk]])) . '" aria-label="Ubah" title="Ubah">' . ic('edit', 17) . '</a>' : '') . '<a class="icon-btn sm danger" href="' . e($own(['c' => $id, 'aksi' => 'hapus', 'id' => $r[$pk]])) . '" aria-label="Hapus" title="Hapus">' . ic('trash', 17) . '</a>' : '') . '</td>'; $tb .= '</tr>'; }
    if (!$slice) $tb = '<tr><td colspan="' . (count($cfg['columns']) + 1) . '">' . empty_state($cfg['empty_title'] ?? 'Tidak ada data', $cfg['empty_msg'] ?? 'Tidak ada baris yang cocok dengan pencarian atau filter.', 'search') . '</td></tr>';
    $from = $total ? ($pg - 1) * $ps + 1 : 0; $to = min($total, $pg * $ps); $pager = '';
    if ($pages > 1) { $show = array_unique(array_filter([1, $pg - 1, $pg, $pg + 1, $pages], fn($p) => $p >= 1 && $p <= $pages)); sort($show); $last = 0; $pager = '<a class="pgb" href="' . e($own([$id . '_pg' => max(1, $pg - 1)])) . '" aria-label="Sebelumnya">‹</a>'; foreach ($show as $p) { if ($p - $last > 1) $pager .= '<span style="padding:6px 2px">…</span>'; $last = $p; $pager .= '<a class="pgb" href="' . e($own([$id . '_pg' => $p])) . '"' . ($p === $pg ? ' aria-current="true"' : '') . '>' . $p . '</a>'; } $pager .= '<a class="pgb" href="' . e($own([$id . '_pg' => min($pages, $pg + 1)])) . '" aria-label="Berikutnya">›</a>'; }
    return '<div class="panel tight">' . $tools . '<div class="tbl-wrap"><table class="tbl"><thead>' . $th . '</thead><tbody>' . $tb . '</tbody></table></div><div class="pager"><span class="num">Menampilkan ' . $from . '–' . $to . ' dari ' . $total . ' baris</span><div class="pg">' . $pager . '</div></div></div>';
}

/* Tabel baca-saja (tanpa tombol tambah/ubah/hapus) dengan cari, filter, urut, halaman yang sama */
function table_list(array $cfg): string { $cfg['readonly'] = true; return crud_list($cfg); }
