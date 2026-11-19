#!/usr/bin/env python3
"""
Menyusun data/peta_evakuasi.json untuk peta evakuasi interaktif.
Masukan : data/garis_pantai_digitasi.geojson (garis pantai digitasi pengguna)
Keluaran: - sabuk pesisir <= SABUK_M dari garis pantai (poligon dengan lubang), dihitung dari medan jarak
          - lima titik pesisir bernama (arah mata angin dari pusat pulau; POSISI PERKIRAAN)
Tidak ada data jalan, elevasi, atau model genangan di sini; sabuk pesisir adalah zona jarak, bukan hasil model rob.
"""
import json, math
from pathlib import Path
import numpy as np
import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
from matplotlib.path import Path as MPath

BASE = Path(__file__).resolve().parent.parent
SABUK_M = 100.0     # sama dengan ambang "lahan < 100 m dari pantai" yang dipakai di analisis geometri
GRID_M = 4.0

ring = np.array(json.loads((BASE / "data/garis_pantai_digitasi.geojson").read_text())["geometry"]["coordinates"][0], float)
lat0, lon0 = float(ring[:, 1].mean()), float(ring[:, 0].mean())
phi = math.radians(lat0)
M_LAT = 111132.92 - 559.82*math.cos(2*phi) + 1.175*math.cos(4*phi) - 0.0023*math.cos(6*phi)
M_LON = 111412.84*math.cos(phi) - 93.5*math.cos(3*phi) + 0.118*math.cos(5*phi)
to_m = lambda a: np.column_stack([(np.asarray(a)[:, 0]-lon0)*M_LON, (np.asarray(a)[:, 1]-lat0)*M_LAT])
to_deg = lambda a: np.column_stack([np.asarray(a)[:, 0]/M_LON+lon0, np.asarray(a)[:, 1]/M_LAT+lat0])
coast = to_m(ring)

# medan jarak ke garis pantai, hanya di dalam pulau
xmin, ymin = coast.min(0) - 20; xmax, ymax = coast.max(0) + 20
gx = np.arange(xmin, xmax, GRID_M); gy = np.arange(ymin, ymax, GRID_M)
GX, GY = np.meshgrid(gx, gy)
P = np.column_stack([GX.ravel(), GY.ravel()])
ins = MPath(coast).contains_points(P)
a = coast[:-1]; b = coast[1:]; ab = b - a; den = np.einsum("ij,ij->i", ab, ab); den[den == 0] = 1e-12
D = np.full(len(P), -1.0)
idx = np.where(ins)[0]
for s in range(0, len(idx), 40000):
    ii = idx[s:s+40000]; p = P[ii][:, None, :]
    t = np.clip(np.einsum("nij,ij->ni", p - a[None], ab) / den, 0, 1)
    proj = a[None] + t[..., None] * ab[None]
    D[ii] = np.sqrt(((p - proj) ** 2).sum(-1)).min(1)
D = D.reshape(GX.shape)

cs = plt.contour(GX, GY, D, levels=[SABUK_M])
holes = []
for seg in cs.allsegs[0]:
    if len(seg) < 12: continue
    seg = seg[::2] if len(seg) > 400 else seg
    r = np.vstack([seg, seg[:1]])
    holes.append(to_deg(r).round(6).tolist())
outer = ring.round(6).tolist()
sabuk = {"type": "Feature", "properties": {"nama": "Sabuk pesisir", "lebar_m": SABUK_M},
         "geometry": {"type": "Polygon", "coordinates": [outer] + holes}}

# titik pesisir bernama: titik garis pantai terjauh dari pusat pada arah tertentu (POSISI PERKIRAAN)
c = coast[:-1].mean(0)
def terjauh(bearing, tol=12):
    best = None
    for pt in coast[:-1]:
        v = pt - c; ang = (math.degrees(math.atan2(v[0], v[1])) + 360) % 360
        if abs((ang - bearing + 180) % 360 - 180) <= tol:
            r = float(np.hypot(*v))
            if best is None or r > best[0]: best = (r, pt)
    return best[1]
tempat = []
for nama, jenis, brg in [("Pantai Munir", "pantai", 315), ("Tanjung Saga", "tanjung", 40), ("Pantai Ferry", "pantai", 68),
                         ("Pantai Kelapa Enom", "pantai", 232), ("Jetty", "dermaga", 178)]:
    lon, lat = to_deg([terjauh(brg)])[0]
    tempat.append({"nama": nama, "jenis": jenis, "lat": round(float(lat), 6), "lon": round(float(lon), 6), "arah_derajat": brg})

out = {"catatan": "Sabuk pesisir = zona <= %d m dari garis pantai digitasi (zona jarak, bukan hasil model genangan). "
                  "Posisi tempat bernama adalah PERKIRAAN dari arah mata angin terhadap pusat pulau, belum disurvei." % SABUK_M,
       "sabuk_pesisir": sabuk, "tempat": tempat, "garis_pantai": outer}
(BASE / "data/peta_evakuasi.json").write_text(json.dumps(out, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
print("lubang sabuk:", len(holes), "titik:", [len(h) for h in holes], "tempat:", [t["nama"] for t in tempat])
print("luas sabuk (ha) ~", round(float(((D >= 0) & (D < SABUK_M)).sum()) * GRID_M**2 / 1e4, 1))
