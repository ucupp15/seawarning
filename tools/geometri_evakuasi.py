#!/usr/bin/env python3
"""
Analisis geometri Pulo Panjang untuk kandidat titik evakuasi.
Masukan  : data/garis_pantai_digitasi.geojson (garis pantai digitasi) + data/geoms.json (7 kampung)
Keluaran : geometri_evakuasi.json
Metode   : proyeksi lokal equirectangular (meter), point-in-polygon ray casting,
           jarak titik-ke-segmen eksak, pencarian titik interior berjarak maksimum
           dari garis pantai (pole of inaccessibility) per kampung.
Catatan  : ELEVASI TIDAK DIHITUNG di sini. Tidak ada data DEM pada masukan,
           sehingga kolom elevasi dibiarkan kosong untuk diisi dari DEMNAS/survei.
"""
import json, math
from pathlib import Path
import numpy as np

BASE = Path(__file__).resolve().parent.parent
coast = json.loads((BASE / "data/garis_pantai_digitasi.geojson").read_text())
kamp = json.loads((BASE / "data/geoms.json").read_text())

ring = np.array(coast["geometry"]["coordinates"][0], dtype=float)  # lon, lat
lat0 = float(ring[:, 1].mean())
lon0 = float(ring[:, 0].mean())

phi = math.radians(lat0)
M_LAT = (111132.92 - 559.82*math.cos(2*phi) + 1.175*math.cos(4*phi)
         - 0.0023*math.cos(6*phi))
M_LON = (111412.84*math.cos(phi) - 93.5*math.cos(3*phi)
         + 0.118*math.cos(5*phi))
print(f"Pusat proyeksi lat {lat0:.6f} lon {lon0:.6f}")
print(f"1 derajat lintang = {M_LAT:.2f} m ; 1 derajat bujur = {M_LON:.2f} m")

def to_m(lonlat):
    a = np.asarray(lonlat, dtype=float)
    return np.column_stack([(a[:, 0] - lon0) * M_LON, (a[:, 1] - lat0) * M_LAT])

def to_deg(xy):
    a = np.asarray(xy, dtype=float).reshape(-1, 2)
    return np.column_stack([a[:, 0] / M_LON + lon0, a[:, 1] / M_LAT + lat0])

coast_m = to_m(ring)

# ---------- luas & panjang garis pantai (shoelace pada proyeksi meter) ----------
x, y = coast_m[:, 0], coast_m[:, 1]
area_m2 = abs(0.5 * np.sum(x[:-1]*y[1:] - x[1:]*y[:-1]))
perim_m = float(np.sum(np.hypot(np.diff(x), np.diff(y))))
print(f"Luas pulau    : {area_m2/1e6:.4f} km2  ({area_m2/1e4:.2f} ha)")
print(f"Panjang pantai: {perim_m/1000:.3f} km")

# ---------- utilitas geometri ----------
def seg_dist(pts, poly):
    """Jarak terdekat tiap titik pts ke poligon tertutup poly (meter)."""
    pts = np.asarray(pts, dtype=float).reshape(-1, 2)
    a = poly[:-1]; b = poly[1:]
    ab = b - a
    denom = np.einsum('ij,ij->i', ab, ab)
    denom[denom == 0] = 1e-12
    out = np.empty(len(pts))
    for i, p in enumerate(pts):
        ap = p - a
        t = np.clip(np.einsum('ij,ij->i', ap, ab) / denom, 0.0, 1.0)
        proj = a + t[:, None] * ab
        out[i] = np.min(np.hypot(*(p - proj).T))
    return out

def inside(pts, poly):
    """Ray casting; poly tertutup (titik pertama == terakhir)."""
    pts = np.asarray(pts, dtype=float).reshape(-1, 2)
    px, py = pts[:, 0], pts[:, 1]
    res = np.zeros(len(pts), dtype=bool)
    x1, y1 = poly[:-1, 0], poly[:-1, 1]
    x2, y2 = poly[1:, 0], poly[1:, 1]
    for j in range(len(x1)):
        cond = ((y1[j] > py) != (y2[j] > py))
        if not cond.any():
            continue
        xint = (x2[j]-x1[j]) * (py - y1[j]) / (y2[j]-y1[j] + 1e-300) + x1[j]
        res ^= cond & (px < xint)
    return res

def farthest_interior(poly_m, clip_m, step0=25.0):
    """Titik di dalam poly_m (dan di dalam clip_m) dengan jarak maksimum ke clip_m."""
    xmin, ymin = poly_m.min(axis=0); xmax, ymax = poly_m.max(axis=0)
    best = None
    step = step0
    cx0, cy0, span = (xmin+xmax)/2, (ymin+ymax)/2, max(xmax-xmin, ymax-ymin)
    for _ in range(6):
        gx = np.arange(cx0 - span/2, cx0 + span/2 + step, step)
        gy = np.arange(cy0 - span/2, cy0 + span/2 + step, step)
        G = np.array(np.meshgrid(gx, gy)).reshape(2, -1).T
        ok = inside(G, poly_m) & inside(G, clip_m)
        if not ok.any():
            step /= 2; continue
        G = G[ok]
        d = seg_dist(G, clip_m)
        k = int(np.argmax(d))
        if best is None or d[k] > best[1]:
            best = (G[k].copy(), float(d[k]))
        cx0, cy0 = G[k]
        span = step * 4
        step /= 3
    return best

# ---------- per kampung ----------
hasil = []
for kid in sorted(kamp, key=int):
    f = kamp[kid]
    nama = f["properties"]["nama"]
    titik = f["properties"]["titik"]            # [lon, lat] titik permukiman (data pengguna)
    poly = np.array(f["geometry"]["coordinates"][0], dtype=float)
    poly_m = to_m(poly)

    # titik permukiman: jarak ke garis pantai
    tm = to_m([titik])
    d_titik = float(seg_dist(tm, coast_m)[0])

    # kandidat titik aman: terjauh dari garis pantai, di dalam kampung & di dalam pulau
    best = farthest_interior(poly_m, coast_m)
    if best is None:
        hasil.append({"id": int(kid), "nama": nama, "gagal": True})
        continue
    bxy, bd = best
    blon, blat = to_deg(bxy)[0]

    # luas kampung (hanya bagian di dalam pulau: perkiraan Monte Carlo pada grid 20 m)
    xmin, ymin = poly_m.min(axis=0); xmax, ymax = poly_m.max(axis=0)
    gx = np.arange(xmin, xmax, 20.0); gy = np.arange(ymin, ymax, 20.0)
    G = np.array(np.meshgrid(gx, gy)).reshape(2, -1).T
    msk = inside(G, poly_m) & inside(G, coast_m)
    luas_darat = float(msk.sum()) * 400.0          # m2 per sel = 20x20
    # sebaran jarak-ke-pantai pada bagian darat kampung
    if msk.any():
        dd = seg_dist(G[msk], coast_m)
        d_med = float(np.median(dd)); d_max = float(dd.max())
        frac_100 = float((dd < 100).mean())        # proporsi lahan < 100 m dari pantai
    else:
        d_med = d_max = frac_100 = float("nan")

    hasil.append({
        "id": int(kid), "nama": nama,
        "deskripsi": f["properties"].get("deskripsi", ""),
        "titik_permukiman": {"lat": round(float(titik[1]), 6), "lon": round(float(titik[0]), 6)},
        "jarak_titik_ke_pantai_m": round(d_titik, 1),
        "kandidat_titik_aman": {"lat": round(float(blat), 6), "lon": round(float(blon), 6)},
        "jarak_kandidat_ke_pantai_m": round(bd, 1),
        "jarak_permukiman_ke_kandidat_m": round(float(np.hypot(*(bxy - tm[0]))), 1),
        "luas_darat_ha": round(luas_darat / 1e4, 2),
        "jarak_pantai_median_m": round(d_med, 1),
        "jarak_pantai_maks_m": round(d_max, 1),
        "porsi_lahan_kurang_100m_dari_pantai": round(frac_100, 3),
        "elevasi_m": None,
        "catatan_elevasi": "Belum terisi: tidak ada data DEM pada masukan. Perlu DEMNAS/BIG atau survei GNSS.",
    })

# ---------- titik terjauh dari pantai di seluruh pulau ----------
glob = farthest_interior(coast_m, coast_m, step0=25.0)
gxy, gd = glob
glon, glat = to_deg(gxy)[0]

# ---------- titik SRGI relatif pulau ----------
SRGI = (106.15350779, -5.93014699)
srgi_m = to_m([SRGI])
srgi_in = bool(inside(srgi_m, coast_m)[0])
srgi_d = float(seg_dist(srgi_m, coast_m)[0])

out = {
    "metode": {
        "proyeksi": f"Equirectangular lokal, pusat lat {lat0:.6f} lon {lon0:.6f}; "
                    f"1 deg lintang = {M_LAT:.2f} m, 1 deg bujur = {M_LON:.2f} m (WGS84)",
        "jarak": "Jarak titik-ke-segmen eksak terhadap seluruh 154 segmen garis pantai digitasi",
        "titik_aman": "Titik interior dengan jarak maksimum ke garis pantai (pole of inaccessibility), "
                      "pencarian grid bertingkat 25 m -> ~0,1 m",
        "luas": "Shoelace pada proyeksi meter; luas darat kampung dari grid 20 m",
        "elevasi": "TIDAK dihitung. Tidak ada DEM pada data masukan.",
    },
    "pulau": {
        "nama": "Pulo Panjang",
        "administrasi": "Desa Pulo Panjang, Kec. Puloampel, Kab. Serang, Banten",
        "luas_km2": round(area_m2 / 1e6, 4),
        "luas_ha": round(area_m2 / 1e4, 2),
        "panjang_garis_pantai_km": round(perim_m / 1000, 3),
        "bbox": {
            "lat_min": round(float(ring[:, 1].min()), 6), "lat_maks": round(float(ring[:, 1].max()), 6),
            "lon_min": round(float(ring[:, 0].min()), 6), "lon_maks": round(float(ring[:, 0].max()), 6),
        },
        "titik_terjauh_dari_pantai": {
            "lat": round(float(glat), 6), "lon": round(float(glon), 6),
            "jarak_ke_pantai_m": round(gd, 1),
        },
        "sumber_geometri": "Digitasi perkiraan dari citra Esri oleh pengguna (data/garis_pantai_digitasi.geojson)",
    },
    "titik_srgi_big": {
        "lat": SRGI[1], "lon": SRGI[0],
        "di_dalam_pulau": srgi_in,
        "jarak_ke_garis_pantai_m": round(srgi_d, 1),
    },
    "kampung": hasil,
}
(BASE / "data" / "geometri_evakuasi.json").write_text(
    json.dumps(out, ensure_ascii=False, indent=1), encoding="utf-8")

print(f"\nTitik terjauh dari pantai (seluruh pulau): {glat:.6f}, {glon:.6f}  -> {gd:.1f} m")
print(f"Titik SRGI BIG: di dalam pulau = {srgi_in}, jarak ke garis pantai = {srgi_d:.1f} m\n")
print(f"{'Kampung':24s} {'d.titik':>8s} {'kandidat d':>11s} {'luas ha':>8s} {'<100m':>7s}")
for h in hasil:
    if h.get("gagal"):
        print(f"{h['nama']:24s}  GAGAL"); continue
    print(f"{h['nama']:24s} {h['jarak_titik_ke_pantai_m']:8.1f} "
          f"{h['jarak_kandidat_ke_pantai_m']:11.1f} {h['luas_darat_ha']:8.2f} "
          f"{h['porsi_lahan_kurang_100m_dari_pantai']*100:6.1f}%")
