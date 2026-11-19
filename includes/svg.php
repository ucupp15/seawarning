<?php
declare(strict_types=1);
/* Grafik dan peta SVG yang dirender di server (tanpa JavaScript). */

function nice_ticks(float $min, float $max, int $n = 4): array {
    if ($max == $min) $max = $min + 1;
    $raw = ($max - $min) / $n; $mag = 10 ** floor(log10($raw)); $f = $raw / $mag;
    $step = ($f < 1.5 ? 1 : ($f < 3 ? 2 : ($f < 7 ? 5 : 10))) * $mag; $out = [];
    for ($v = ceil($min / $step) * $step; $v <= $max + 1e-9; $v += $step) $out[] = round($v, 10);
    return $out;
}
/* $o: series[[name,color,pts[[x,y]],fmt?]], w,h, area, now, split, xTicks[[x,label]], ymin, ymax, yFmt, xFmt, marks[[x,y,label]], label */
function line_chart(array $o): string {
    $W = $o['w'] ?? 680; $H = $o['h'] ?? 240; $m = ['l' => $o['yw'] ?? 44, 'r' => 16, 't' => 16, 'b' => 28]; static $gid = 0; $id = 'lc' . (++$gid);
    $S = $o['series']; $xs = $ys = [];
    foreach ($S as $s) foreach ($s['pts'] as $p) { $xs[] = $p[0]; $ys[] = $p[1]; }
    if (!$xs) return empty_state('Belum ada data', 'Data untuk grafik ini belum tersedia.', 'chart');
    $x0 = min($xs); $x1 = max($xs); $y0 = $o['ymin'] ?? min($ys); $y1 = $o['ymax'] ?? max($ys);
    if (!isset($o['ymin'])) $y0 = min($y0, !empty($o['zero']) ? 0 : $y0) - ($y1 - $y0) * 0.06;
    if (!isset($o['ymax'])) $y1 += ($y1 - $y0) * 0.1;
    $X = fn($x) => $m['l'] + ($x - $x0) / (($x1 - $x0) ?: 1) * ($W - $m['l'] - $m['r']);
    $Y = fn($y) => $H - $m['b'] - ($y - $y0) / (($y1 - $y0) ?: 1) * ($H - $m['t'] - $m['b']);
    $yf = $o['yFmt'] ?? fn($v) => nf($v, 1); $g = '';
    foreach (nice_ticks($y0, $y1, 4) as $v) $g .= '<line class="grid-l" x1="' . $m['l'] . '" x2="' . ($W - $m['r']) . '" y1="' . round($Y($v), 1) . '" y2="' . round($Y($v), 1) . '"/><text x="' . ($m['l'] - 8) . '" y="' . round($Y($v) + 4, 1) . '" text-anchor="end">' . e($yf($v)) . '</text>';
    foreach ($o['xTicks'] ?? [] as [$x, $l]) $g .= '<text x="' . round($X($x), 1) . '" y="' . ($H - 8) . '" text-anchor="middle">' . e($l) . '</text>';
    $paths = ''; $defs = '';
    foreach ($S as $si => $s) {
        $c = $s['color'] ?? 'var(--c-line)'; $pts = $s['pts'];
        $d = fn(array $ps) => implode('', array_map(fn($p, $i) => ($i ? 'L' : 'M') . round($X($p[0]), 1) . ' ' . round($Y($p[1]), 1), $ps, array_keys($ps)));
        if (!empty($o['area']) && $si === 0) {
            $defs .= '<linearGradient id="' . $id . 'g" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' . e($c) . '" stop-opacity=".38"/><stop offset="1" stop-color="' . e($c) . '" stop-opacity="0"/></linearGradient>';
            $last = end($pts); $first = $pts[0];
            $paths .= '<path d="' . $d($pts) . 'L' . round($X($last[0]), 1) . ' ' . round($Y($y0), 1) . 'L' . round($X($first[0]), 1) . ' ' . round($Y($y0), 1) . 'Z" fill="url(#' . $id . 'g)"/>';
        }
        if (isset($o['split'])) {
            $a = array_values(array_filter($pts, fn($p) => $p[0] <= $o['split'])); $b = array_values(array_filter($pts, fn($p) => $p[0] >= $o['split']));
            if (count($a) > 1) $paths .= '<path d="' . $d($a) . '" fill="none" stroke="' . e($c) . '" stroke-width="2.4" stroke-linejoin="round" stroke-linecap="round"/>';
            if (count($b) > 1) $paths .= '<path d="' . $d($b) . '" fill="none" stroke="' . e($c) . '" stroke-width="2.4" stroke-dasharray="2 6" stroke-linecap="round"/>';
        } else $paths .= '<path d="' . $d($pts) . '" fill="none" stroke="' . e($c) . '" stroke-width="2.4" stroke-linejoin="round" stroke-linecap="round"/>';
    }
    $hl = ''; foreach ($o['hlines'] ?? [] as [$hv, $hlb, $hc]) if ($hv >= $y0 && $hv <= $y1) $hl .= '<line x1="' . $m['l'] . '" x2="' . ($W - $m['r']) . '" y1="' . round($Y($hv), 1) . '" y2="' . round($Y($hv), 1) . '" stroke="' . e($hc) . '" stroke-width="1.3" stroke-dasharray="5 4"/><text x="' . ($W - $m['r'] - 4) . '" y="' . round($Y($hv) - 5, 1) . '" text-anchor="end" fill="' . e($hc) . '" style="font-size:11px;font-weight:700">' . e($hlb) . '</text>';
    $now = '';
    if (isset($o['now']) && $o['now'] >= $x0 && $o['now'] <= $x1) $now = '<line class="now-l" x1="' . round($X($o['now']), 1) . '" x2="' . round($X($o['now']), 1) . '" y1="' . ($m['t'] - 4) . '" y2="' . ($H - $m['b']) . '"/><text class="now-t" x="' . round($X($o['now']), 1) . '" y="' . ($m['t'] - 6) . '" text-anchor="middle">Sekarang</text>';
    $marks = ''; foreach ($o['marks'] ?? [] as $k) $marks .= '<circle cx="' . round($X($k[0]), 1) . '" cy="' . round($Y($k[1]), 1) . '" r="4.5" fill="var(--c-now)" stroke="var(--c-line)" stroke-width="2"/><text class="now-t" x="' . round($X($k[0]), 1) . '" y="' . round($Y($k[1]) - 10, 1) . '" text-anchor="middle">' . e($k[2]) . '</text>';
    $base = $S[0]['pts']; $hits = ''; $n = count($base);
    foreach ($base as $i => $p) {
        $xa = $i ? ($X($base[$i - 1][0]) + $X($p[0])) / 2 : $m['l']; $xb = $i < $n - 1 ? ($X($p[0]) + $X($base[$i + 1][0])) / 2 : $W - $m['r'];
        $tip = ($o['xFmt'] ?? fn($v) => (string) $v)($p[0]);
        foreach ($S as $s) if (isset($s['pts'][$i])) $tip .= ' | ' . $s['name'] . ': ' . (($s['fmt'] ?? $yf)($s['pts'][$i][1]));
        $hits .= '<rect class="hit" x="' . round($xa, 1) . '" y="' . $m['t'] . '" width="' . round(max(2, $xb - $xa), 1) . '" height="' . ($H - $m['t'] - $m['b']) . '"><title>' . e($tip) . '</title></rect>';
    }
    return '<div class="chart"><svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($o['label'] ?? 'Grafik') . '"><defs>' . $defs . '</defs>' . $g . $hl . $paths . $now . $marks . $hits . '</svg></div>';
}
function bar_list(array $items, array $o = []): string {
    $max = $o['max'] ?? max(1, ...array_map(fn($i) => $i['value'], $items)); $h = '<div class="bars">';
    foreach ($items as $i) $h .= '<div class="b"><span title="' . e($i['label']) . '">' . e($i['label']) . '</span><div class="prog" style="--c:' . e($i['color'] ?? 'var(--primary)') . '"><i style="width:' . round(clamp($i['value'] / $max * 100, 0, 100), 1) . '%"></i></div><em class="num">' . e($i['text'] ?? nf($i['value'], $o['d'] ?? 0)) . '</em></div>';
    return $h . '</div>';
}
function donut(array $items, string $center = '', string $sub = ''): string {
    $tot = sum(array_column($items, 'value')) ?: 1; $R = 46; $C = 2 * M_PI * $R; $off = 0.0; $arcs = '';
    foreach ($items as $i) { $len = $i['value'] / $tot * $C; $arcs .= '<circle cx="60" cy="60" r="' . $R . '" fill="none" stroke="' . e($i['color']) . '" stroke-width="16" stroke-dasharray="' . round($len, 2) . ' ' . round($C - $len, 2) . '" stroke-dashoffset="' . round(-$off, 2) . '" transform="rotate(-90 60 60)"/>'; $off += $len; }
    return '<svg viewBox="0 0 120 120" width="150" height="150" role="img" aria-label="' . e($sub) . '"><circle cx="60" cy="60" r="' . $R . '" fill="none" stroke="var(--surface-3)" stroke-width="16"/>' . $arcs . '<text x="60" y="62" text-anchor="middle" font-family="var(--font-display)" font-size="26" font-weight="600" fill="var(--ink)">' . e($center) . '</text><text x="60" y="78" text-anchor="middle" font-size="9.5" fill="var(--ink-3)">' . e($sub) . '</text></svg>';
}
function month_bars(array $rows): string {
    if (!$rows) return ''; $cnt = [];
    foreach ($rows as $r) { $k = substr($r['tanggal'], 0, 7); $cnt[$k] = ($cnt[$k] ?? 0) + 1; }
    ksort($cnt); $keys = array_keys($cnt); [$y, $m] = array_map('intval', explode('-', $keys[0])); [$ly, $lm] = array_map('intval', explode('-', end($keys))); $all = [];
    while ($y < $ly || ($y === $ly && $m <= $lm)) { $all[] = sprintf('%d-%02d', $y, $m); if (++$m > 12) { $m = 1; $y++; } }
    $ms = array_slice($all, -14); $mx = max(1, ...array_map(fn($k) => $cnt[$k] ?? 0, $ms));
    $h = '<div style="display:flex;align-items:flex-end;gap:6px;height:150px;padding-top:8px" role="img" aria-label="Jumlah kejadian rob per bulan">';
    foreach ($ms as $k) { $n = $cnt[$k] ?? 0; [$yy, $mm] = explode('-', $k); $nm = BLN_PANJANG[(int) $mm - 1] . ' ' . $yy;
        $h .= '<div title="' . e($nm . ': ' . $n . ' kejadian') . '" style="flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:4px;height:100%"><span class="num" style="font-size:12px;font-weight:600">' . ($n ?: '') . '</span><div style="width:100%;max-width:34px;height:' . round($n / $mx * 100) . 'px;min-height:' . ($n ? 6 : 2) . 'px;border-radius:6px 6px 2px 2px;background:' . ($n ? 'var(--primary)' : 'var(--surface-3)') . '"></div><span class="faint" style="font-size:11px">' . BLN[(int) $mm - 1] . '</span></div>'; }
    return $h . '</div>';
}
function tide_chart(int $from = -12, int $to = 36): array {
    $rows = pasut_series_rows($from, $to) ?: osea_series($from, $to);
    if (count($rows) < 2) return ['html' => empty_state('Data pasang surut belum tersedia', 'Administrator perlu memasukkan data oseanografi.', 'waves'), 'peak' => null];
    $now = time(); $fut = array_values(array_filter($rows, fn($r) => $r['t'] >= $now && $r['t'] <= $now + 86400)); usort($fut, fn($a, $b) => $b['pasut_m'] <=> $a['pasut_m']); $peak = $fut[0] ?? null;
    $xt = []; $t = $rows[0]['t']; $t = (int) (floor($t / 3600) * 3600); while ((int) date('G', $t) % 6) $t += 3600;
    for (; $t <= end($rows)['t']; $t += 6 * 3600) $xt[] = [$t, date('H', $t) . '.00'];
    $amb = function_exists('pasut_ambang') && pasut_ada() ? (pasut_ambang()['tingkat'] ?? []) : []; $hl2 = []; $ymx = max(array_column($rows, 'pasut_m')) + 0.06;
    foreach ([['waspada', 'Waspada', '#B07A00'], ['siaga', 'Siaga', '#C4411F']] as [$kk, $lb, $cc]) if (isset($amb[$kk])) { $hl2[] = [(float) $amb[$kk]['batas'], $lb . ' ' . nf($amb[$kk]['batas'], 2) . ' m', $cc]; if ($amb[$kk]['batas'] + 0.05 > $ymx && $kk === 'waspada') $ymx = $amb[$kk]['batas'] + 0.05; }
    $html = line_chart(['series' => [['name' => 'Pasang surut', 'pts' => array_map(fn($r) => [$r['t'], $r['pasut_m']], $rows), 'fmt' => fn($v) => nf($v, 2) . ' m']], 'area' => true, 'now' => $now, 'split' => $now, 'xTicks' => $xt, 'zero' => true, 'hlines' => $hl2, 'ymax' => $ymx, 'yFmt' => fn($v) => nf($v, 2), 'xFmt' => fn($t) => fmt_dt($t), 'label' => 'Grafik tinggi pasang surut', 'w' => 620, 'h' => 230, 'marks' => $peak ? [[$peak['t'], $peak['pasut_m'], fmt_t($peak['waktu']) . ', ' . nf($peak['pasut_m'], 2) . ' m']] : []]);
    return ['html' => $html, 'peak' => $peak];
}
const OSEA_SER = ['pasut_m' => ['Pasang surut', 'm', '#0D84A8', 2], 'gelombang_m' => ['Gelombang', 'm', '#3B82C4', 2], 'angin_knot' => ['Angin', 'knot', '#5E7480', 1], 'arus_ms' => ['Arus', 'm/s', '#2E9E9B', 2]];
function osea_chart(string $key, int $from, int $to, int $h = 260): string {
    $rows = osea_series($from, $to); if (count($rows) < 2) return empty_state('Data belum tersedia', 'Belum ada data oseanografi pada rentang waktu ini.', 'waves');
    $now = time(); $xt = []; $t = (int) (floor($rows[0]['t'] / 3600) * 3600); $step = ($to - $from) > 60 ? 12 : 6; while ((int) date('G', $t) % $step) $t += 3600;
    for (; $t <= end($rows)['t']; $t += $step * 3600) $xt[] = [$t, date('G', $t) === '0' ? fmt_day(date('Y-m-d H:i:s', $t)) : date('H', $t) . '.00'];
    $s = OSEA_SER[$key];
    return line_chart(['series' => [['name' => $s[0], 'color' => $s[2], 'pts' => array_map(fn($r) => [$r['t'], $r[$key]], $rows), 'fmt' => fn($v) => nf($v, $s[3]) . ' ' . $s[1]]], 'area' => true, 'now' => $now, 'split' => $now, 'xTicks' => $xt, 'zero' => true, 'xFmt' => fn($t) => fmt_dt($t), 'label' => 'Grafik data oseanografi', 'w' => 760, 'h' => $h] + ($key === 'pasut_m' ? [] : []));
}

/* ===================== Peta SVG dari GeoJSON wilayah ===================== */
function geo_rings($geo): array { // -> daftar poligon; tiap poligon = [cincin luar, lubang...]
    if (!is_array($geo)) return [];
    if (($geo['type'] ?? '') === 'FeatureCollection') $geo = $geo['features'][0] ?? null;
    if (is_array($geo) && ($geo['type'] ?? '') === 'Feature') $geo = $geo['geometry'] ?? null;
    if (!is_array($geo)) return [];
    if (($geo['type'] ?? '') === 'Polygon') return [$geo['coordinates']];
    if (($geo['type'] ?? '') === 'MultiPolygon') return $geo['coordinates'];
    return [];
}
function geo_of(array $w): ?array { $g = json_decode((string) $w['geometri_geojson'], true); return is_array($g) ? $g : null; }
function wil_props(array $w): array { $g = geo_of($w); if (!$g) return []; $f = ($g['type'] ?? '') === 'FeatureCollection' ? ($g['features'][0] ?? []) : $g; return $f['properties'] ?? []; }
function wil_desc(array $w): string { return (string) (wil_props($w)['deskripsi'] ?? ''); }
function wil_titik(array $w): ?array { $t = wil_props($w)['titik'] ?? null; return (is_array($t) && count($t) === 2 && $t[0] >= 90 && $t[0] <= 145 && $t[1] >= -12 && $t[1] <= 8) ? [(float) $t[1], (float) $t[0]] : null; } // [lintang, bujur]
function geo_real(array $w): ?array { $g = geo_of($w); $p = geo_rings($g); if (!$p) return null; [$x, $y] = $p[0][0][0]; return ($x >= 90 && $x <= 145 && $y >= -12 && $y <= 8) ? (($g['type'] ?? '') === 'FeatureCollection' ? $g['features'][0] : $g) : null; }
function poly_area(array $r): float { $a = 0.0; for ($i = 0; $i < count($r) - 1; $i++) $a += $r[$i][0] * $r[$i + 1][1] - $r[$i + 1][0] * $r[$i][1]; return $a / 2; }
function poly_centroid(array $r): array { $a = 0.0; $cx = $cy = 0.0; for ($i = 0; $i < count($r) - 1; $i++) { $f = $r[$i][0] * $r[$i + 1][1] - $r[$i + 1][0] * $r[$i][1]; $a += $f; $cx += ($r[$i][0] + $r[$i + 1][0]) * $f; $cy += ($r[$i][1] + $r[$i + 1][1]) * $f; } $a *= 3; return $a ? [$cx / $a, $cy / $a] : $r[0]; }
function map_model(): ?array {
    $items = []; $minX = $minY = INF; $maxX = $maxY = -INF;
    foreach (tbl('wilayah') as $w) { $polys = geo_rings(geo_of($w)); if (!$polys) continue; $items[] = ['w' => $w, 'polys' => $polys]; foreach ($polys as $p) foreach ($p[0] as [$x, $y]) { $minX = min($minX, $x); $maxX = max($maxX, $x); $minY = min($minY, $y); $maxY = max($maxY, $y); } }
    if (!$items) return null;
    $mid = ($minY + $maxY) / 2; $k = abs($mid) <= 90 ? cos(deg2rad($mid)) : 1; $pad = 46; $W = 1000; $s = $W / ((($maxX - $minX) * $k) ?: 1); $H = (($maxY - $minY) * $s) ?: 300;
    $P = fn($pt) => [($pt[0] - $minX) * $k * $s + $pad, ($maxY - $pt[1]) * $s + $pad];
    foreach ($items as &$i) {
        $d = ''; $best = null;
        foreach ($i['polys'] as $p) { foreach ($p as $ring) { $d .= 'M' . implode('L', array_map(fn($pt) => implode(' ', array_map(fn($v) => round($v, 1), $P($pt))), $ring)) . 'Z'; }
            $pr = array_map($P, $p[0]); $a = abs(poly_area($pr)); if (!$best || $a > $best['a']) $best = ['a' => $a, 'ring' => $pr]; }
        $i['d'] = $d; $i['area'] = $best['a']; [$i['cx'], $i['cy']] = poly_centroid($best['ring']);
        $xs = array_column($best['ring'], 0); $i['bw'] = max($xs) - min($xs);
    } unset($i);
    return ['vw' => $W + $pad * 2, 'vh' => $H + $pad * 2, 'items' => $items];
}
function map_labels(string $n): array {
    $base = trim(explode(' - ', preg_replace('/\s*\([^)]*\)/', '', $n))[0]); $stop = ['Kawasan', 'dan', 'di', 'dari'];
    $t = array_values(array_filter(explode(' ', $base), fn($x) => $x !== '' && !in_array($x, $stop, true))); $noKp = array_values(array_filter($t, fn($x) => $x !== 'Kampung'));
    $byLen = $t; usort($byLen, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    return uniq_list(array_values(array_filter([implode(' ', $t), implode(' ', $noKp), implode(' ', array_slice($t, 0, 2)), ...$byLen])));
}
function short_name(string $n): string { return map_labels($n)[0] ?? $n; }
function map_marker(int $rank, float $x, float $y, float $r): string {
    $st = 'fill="#fff" stroke="#0A2233" stroke-width="2.4" stroke-linejoin="round"'; $f = fn($v) => round($v, 1);
    if ($rank === 0) return '<circle cx="' . $f($x) . '" cy="' . $f($y) . '" r="' . $f($r) . '" ' . $st . '/>';
    if ($rank === 1) return '<polygon points="' . $f($x) . ',' . $f($y - $r * 1.15) . ' ' . $f($x + $r * 1.1) . ',' . $f($y + $r * .8) . ' ' . $f($x - $r * 1.1) . ',' . $f($y + $r * .8) . '" ' . $st . '/>';
    $pts = []; for ($i = 0; $i < 8; $i++) { $a = M_PI / 8 + $i * M_PI / 4; $pts[] = $f($x + cos($a) * $r * 1.15) . ',' . $f($y + sin($a) * $r * 1.15); }
    return '<polygon points="' . implode(' ', $pts) . '" ' . $st . '/>';
}
/* $o: id, color(callable w -> [fill, rank, text]), selected, href(callable w -> url) */
function map_html(array $o): string {
    $M = map_model(); if (!$M) return '<div class="panel flat">' . empty_state('Peta belum tersedia', 'Belum ada geometri wilayah yang valid. Administrator dapat menambahkan GeoJSON pada menu Wilayah.', 'map') . '</div>';
    $id = $o['id'] ?? 'm'; $fs = $M['vw'] / 52; $real = false; foreach (tbl('wilayah') as $w) if (geo_real($w)) { $real = true; break; }
    $pats = '<pattern id="' . $id . '-p1" width="14" height="14" patternUnits="userSpaceOnUse"><circle cx="4" cy="4" r="2.2" fill="rgba(0,0,0,.34)"/><circle cx="11" cy="11" r="2.2" fill="rgba(0,0,0,.34)"/></pattern><pattern id="' . $id . '-p2" width="14" height="14" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="5" height="14" fill="rgba(0,0,0,.30)"/></pattern>';
    $grid = ''; for ($i = 1; $i < 10; $i++) $grid .= '<line x1="' . round($M['vw'] / 10 * $i, 1) . '" x2="' . round($M['vw'] / 10 * $i, 1) . '" y1="0" y2="' . round($M['vh']) . '" stroke="var(--sea-line)"/>';
    for ($i = 1; $i < 6; $i++) $grid .= '<line y1="' . round($M['vh'] / 6 * $i, 1) . '" y2="' . round($M['vh'] / 6 * $i, 1) . '" x1="0" x2="' . $M['vw'] . '" stroke="var(--sea-line)"/>';
    $regs = $labs = '';
    foreach ($M['items'] as $i) {
        $w = $i['w']; $col = ($o['color'])($w); [$fill, $rank, $text] = $col; $sub = (string) ($col[3] ?? ''); $big = $i['area'] > $M['vw'] * $M['vh'] * 0.008; $sel = ($o['selected'] ?? null) === $w['id_wilayah'];
        /* mode polos: hanya kampung yang dipilih berwarna dan berstatus; yang lain abu-abu bernama saja */
        $plain = !empty($o['neutral']) && !$sel; if ($plain) { $fill = 'var(--surface-3)'; $rank = 0; $text = 'klik untuk melihat kerawanan'; $sub = ''; }
        $cand = map_labels($w['nama_wilayah']); $cand = array_values(array_filter($cand, fn($t, $k) => !$k || mb_strlen($t) >= 6, ARRAY_FILTER_USE_BOTH)); $lab = $lab2 = null;
        foreach ($cand as $t) if (mb_strlen($t) * $fs * 0.62 < $i['bw'] * 0.9) { $lab = $t; break; }
        if (!$lab) foreach ($cand as $t) if (mb_strlen($t) * $fs * 0.78 * 0.62 < $i['bw'] * 0.9) { $lab2 = $t; break; }
        $txt = $lab ?? $lab2; $fsl = $lab ? $fs : $fs * 0.78;
        if ($big) $labs .= '<g>' . ($plain ? '' : map_marker($rank, $i['cx'], $i['cy'] - ($txt ? $fs * 1.15 : 0), $fs * .55)) . ($txt ? '<text x="' . round($i['cx'], 1) . '" y="' . round($i['cy'] + $fs * .55, 1) . '" text-anchor="middle" font-size="' . round($fsl, 1) . '">' . e($txt) . '</text>' : '') . ($sub !== '' ? '<text class="val" x="' . round($i['cx'], 1) . '" y="' . round($i['cy'] + $fs * 1.62, 1) . '" text-anchor="middle" font-size="' . round($fs * 0.86, 1) . '">' . e($sub) . '</text>' : '') . '</g>';
        $aria = $w['nama_wilayah'] . ': ' . $text;
        $regs .= '<a href="' . e(($o['href'])($w)) . '" aria-label="' . e($aria) . '"><g class="region ' . ($sel ? 'sel' : '') . '"><path class="fill" d="' . $i['d'] . '" fill="' . e($fill) . '" fill-rule="evenodd"/>' . ($rank > 0 ? '<path d="' . $i['d'] . '" fill="url(#' . $id . '-p' . min($rank, 2) . ')" fill-rule="evenodd" pointer-events="none"/>' : '') . '<title>' . e($aria) . '</title></g></a>';
    }
    $compass = '<g transform="translate(' . ($M['vw'] - 62) . ',62)" fill="none" stroke="var(--ink-3)" stroke-width="2"><circle r="28"/><path d="M0 -21 8 8 0 2 -8 8z" fill="var(--ink-3)" stroke="none"/><text y="-34" text-anchor="middle" font-size="19" font-weight="700" fill="var(--ink-2)" stroke="none">U</text></g>';
    return '<div class="map"><svg viewBox="0 0 ' . $M['vw'] . ' ' . round($M['vh']) . '" role="group" aria-label="Peta zona kerawanan Pulo Panjang"><defs>' . $pats . '</defs><rect width="' . $M['vw'] . '" height="' . round($M['vh']) . '" fill="var(--sea)"/>' . $grid . $compass . '<g>' . $regs . '</g><g class="map-lab" pointer-events="none">' . $labs . '</g></svg><span class="map-cap">' . ($real ? 'Garis pantai digitasi dari citra satelit, batas antar kampung perkiraan' : 'Peta skematik, bukan skala sebenarnya') . '</span></div>';
}
function color_by_tingkat(): callable {
    return function (array $w): array { $inf = info_wilayah($w['id_wilayah']); if (!$inf) return ['var(--surface-3)', 0, 'belum dianalisis']; return [$inf['tingkat']['warna'], rank_of($inf['tingkat']['id_tingkat']), 'kerawanan ' . mb_strtolower($inf['tingkat']['nama_tingkat'])]; };
}
function color_by_param(int $idParam): callable { // warna zona menurut SKOR parameter; teks kecil di peta = NILAI terukur beserta satuannya
    $ts = tingkat_list(); $p = row('parameter', $idParam); $sat = $p['satuan'] ?? '';
    return function (array $w) use ($idParam, $ts, $sat): array { $d = latest_data($w['id_wilayah'])[$idParam] ?? null; if (!$d) return ['var(--surface-3)', 0, 'tidak ada data', 'tanpa data']; $r = (int) clamp(($d['skor'] ?? 1) - 1, 0, count($ts) - 1); return [$ts[$r]['warna'], $r, 'skor ' . $d['skor'] . ', nilai ' . nfa($d['nilai']) . ' ' . $sat, trim(nfa($d['nilai']) . ' ' . $sat)]; };
}
function legend_param(int $idParam): string { // legenda: tiap kelas skor beserta rentang nilainya
    $p = row('parameter', $idParam); $ts = tingkat_list(); $h = '<div class="legend" style="flex-direction:column;align-items:flex-start;gap:6px">';
    foreach (kelas_of($idParam) as $k) { $r = (int) clamp($k['skor'] - 1, 0, count($ts) - 1); $rng = $k['batas_bawah'] <= -999 ? 'kurang dari ' . bound($k['batas_atas']) : ($k['batas_atas'] >= INF_BATAS ? bound($k['batas_bawah']) . ' atau lebih' : bound($k['batas_bawah']) . ' sampai kurang dari ' . bound($k['batas_atas']));
        $h .= '<span class="it"><svg class="sw" width="22" height="16" viewBox="0 0 22 16"><rect width="22" height="16" rx="4" fill="' . e($ts[$r]['warna']) . '"/></svg>' . lvl_icon($r, 15) . 'Skor ' . $k['skor'] . ': <b class="num" style="margin-left:4px">' . e($rng) . ' ' . e($p['satuan'] ?? '') . '</b></span>'; }
    return $h . '</div>';
}
function legend_tingkat(): string {
    $h = '<div class="legend">'; foreach (tingkat_list() as $i => $t) $h .= '<span class="it"><svg class="sw" width="22" height="16" viewBox="0 0 22 16"><defs><pattern id="lgp' . $i . '" width="7" height="7" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="2.5" height="7" fill="rgba(0,0,0,.3)"/></pattern></defs><rect width="22" height="16" rx="4" fill="' . e($t['warna']) . '"/>' . ($i > 0 ? '<rect width="22" height="16" rx="4" fill="url(#lgp' . $i . ')"/>' : '') . '</svg>' . lvl_icon($i, 15) . e($t['nama_tingkat']) . '</span>';
    return $h . '</div>';
}
