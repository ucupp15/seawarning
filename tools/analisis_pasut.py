#!/usr/bin/env python3
"""
Analisis pasang surut 2024-2028 dari data prediksi BIG / SRGI.
- Parsing data mentah (hourly, UTC)
- Analisis harmonik least-squares (konstanta pasut)
- Bilangan Formzahl -> klasifikasi tipe pasut (tahunan & bulanan)
- Statistik muka air (MSL, HHWL, LLWL, MHWL, MLWL, tunggang)
- Ambang banjir rob berbasis exceedance
Output: data_pasut.json
"""
import json, re, math
from pathlib import Path
import numpy as np
import pandas as pd

BASE = Path(__file__).resolve().parent.parent
UP = BASE / "data" / "pasut_big"
YEARS = [2024, 2025, 2026, 2027, 2028]

# ---------- 1. PARSING ----------
row_re = re.compile(
    r"^\s*(-?\d+\.\d+)\s+(-?\d+\.\d+)\s+(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})\s+(-?\d+\.\d+)\s*$"
)
recs, meta = [], {}
titik_tahun = {}          # koordinat yang benar-benar tertulis pada tiap berkas
for y in YEARS:
    f = UP / f"data_pasut{y}.txt"
    txt = f.read_text(encoding="utf-8", errors="replace")
    m = re.search(r"Lat:\s*(-?\d+\.\d+)\s+Lon:\s*(-?\d+\.\d+)", txt)
    if m:
        titik_tahun[y] = {"lat": float(m.group(1)), "lon": float(m.group(2))}
    n_ok = 0
    for line in txt.splitlines():
        mm = row_re.match(line)
        if mm:
            recs.append((f"{mm.group(3)} {mm.group(4)}", float(mm.group(5)), y))
            n_ok += 1
    print(f"{y}: {n_ok} baris, titik {titik_tahun[y]['lat']:.8f} / {titik_tahun[y]['lon']:.8f}")

# jarak antar titik tahunan: kelima berkas TIDAK diambil pada koordinat yang sama
def _jarak_m(a, b):
    R = 6371008.8
    p1, p2 = math.radians(a["lat"]), math.radians(b["lat"])
    dp = p2 - p1; dl = math.radians(b["lon"] - a["lon"])
    h = math.sin(dp/2)**2 + math.cos(p1)*math.cos(p2)*math.sin(dl/2)**2
    return 2 * R * math.asin(math.sqrt(h))
pasangan = [(a, b, _jarak_m(titik_tahun[a], titik_tahun[b])) for i, a in enumerate(YEARS) for b in YEARS[i+1:]]
jarak_maks = max(pasangan, key=lambda x: x[2])
lat_c = sum(t["lat"] for t in titik_tahun.values()) / len(titik_tahun)
lon_c = sum(t["lon"] for t in titik_tahun.values()) / len(titik_tahun)
meta["lat"], meta["lon"] = titik_tahun[2024]["lat"], titik_tahun[2024]["lon"]
meta["lat_pusat"], meta["lon_pusat"] = round(lat_c, 8), round(lon_c, 8)
meta["titik_sama"] = len({(t["lat"], t["lon"]) for t in titik_tahun.values()}) == 1
meta["jarak_maks_m"] = round(jarak_maks[2], 1)
meta["jarak_maks_pasangan"] = f"{jarak_maks[0]}–{jarak_maks[1]}"
meta["titik_tahun"] = {str(k): v for k, v in titik_tahun.items()}
print(f"TITIK: {'sama semua' if meta['titik_sama'] else 'BERBEDA'}; "
      f"jarak terjauh {meta['jarak_maks_m']} m ({meta['jarak_maks_pasangan']})")

df = pd.DataFrame(recs, columns=["ts", "z", "berkas"])
df["ts"] = pd.to_datetime(df["ts"], format="%Y-%m-%d %H:%M:%S", utc=True)
df = df.drop_duplicates(subset="ts").sort_values("ts").reset_index(drop=True)
# Seluruh pengelompokan dan penyajian memakai WIB (UTC+7) agar tahun dan bulan yang ditampilkan
# konsisten dengan jam yang ditampilkan. Data mentah BIG bertanda waktu UTC.
df["ts_wib"] = df["ts"] + pd.Timedelta(hours=7)
df["tahun"] = df["ts_wib"].dt.year
df["bulan"] = df["ts_wib"].dt.month
luber = df[~df["tahun"].isin(YEARS)]          # 1 Jan 2029 WIB 00.00-06.00, sisa konversi zona waktu
df_th = df[df["tahun"].isin(YEARS)].reset_index(drop=True)
print(f"TOTAL: {len(df)} baris, {df.ts_wib.min()} s/d {df.ts_wib.max()} WIB")
print(f"Di luar tahun 2024-2028 WIB: {len(luber)} baris "
      f"({luber.ts_wib.min()} s/d {luber.ts_wib.max()} WIB)" if len(luber) else "Tidak ada baris di luar rentang")

# validasi kelengkapan
expected = 0
for y in YEARS:
    expected += 8784 if (y % 4 == 0 and (y % 100 != 0 or y % 400 == 0)) else 8760
gaps = df["ts"].diff().dropna()
qc = {
    "baris_terbaca": int(len(df)),
    "baris_diharapkan": int(expected),
    "lengkap": bool(len(df) == expected),
    "duplikat_dihapus": int(len(recs) - len(df)),
    "interval_tidak_1jam": int((gaps != pd.Timedelta(hours=1)).sum()),
    "nilai_kosong": int(df["z"].isna().sum()),
    "z_min_global": float(df["z"].min()),
    "z_max_global": float(df["z"].max()),
    "baris_statistik_tahunan": int(len(df_th)),
    "baris_luar_rentang_wib": int(len(luber)),
    "catatan_zona_waktu": (
        "Data mentah bertanda waktu UTC dan dikonversi ke WIB (UTC+7) sebelum dikelompokkan, sehingga "
        "tahun dan bulan yang ditampilkan konsisten dengan jam yang ditampilkan. Akibat pergeseran 7 jam, "
        f"Januari 2024 WIB memuat {int(((df_th.tahun==2024)&(df_th.bulan==1)).sum())} jam (bukan 744) dan "
        f"{int(len(luber))} jam terakhir berkas jatuh pada 1 Januari 2029 WIB sehingga tidak masuk statistik "
        "tahunan, tetapi tetap ikut pada analisis harmonik gabungan lima tahun dan tetap tersimpan di basis data."),
    "titik_sama_semua_tahun": bool(meta["titik_sama"]),
    "jarak_maks_antartitik_m": meta["jarak_maks_m"],
}
print("QC:", qc)

# ---------- 2. ANALISIS HARMONIK ----------
# kecepatan sudut (derajat/jam)
SPEED = {
    "M2": 28.9841042, "S2": 30.0000000, "N2": 28.4397295, "K2": 30.0821373,
    "K1": 15.0410686, "O1": 13.9430356, "P1": 14.9589314, "Q1": 13.3986609,
    "M4": 57.9682084, "MS4": 58.9841042, "M6": 86.9523127,
    "Mf": 1.0980331, "Mm": 0.5443747, "Sa": 0.0410686, "Ssa": 0.0821373,
}
# Set tahunan: 8 komponen utama. Sa TIDAK dipakai (8760 x 0.0410686 = 359.8 deg < 360,
# tidak terpisah dari Z0 -> matriks normal ill-conditioned). M4/MS4/M6/Mf/Mm/Ssa juga tidak
# dipakai: amplitudo < 1e-5 m pada data BIG (produk BIG hanya komponen astronomi utama).
SET_TAHUN = ["M2","S2","N2","K2","K1","O1","P1","Q1"]
# set bulanan: K2 & P1 tidak terpisah dari S2 & K1 dalam 1 bulan -> dipakai inferensi
SET_BULAN = ["M2","S2","N2","K1","O1","Q1"]
INFER = {"K2": ("S2", 0.27215), "P1": ("K1", 0.33093)}  # rasio inferensi standar

def harmonik(t_hours, z, comps):
    """Least-squares harmonic fit. Returns dict comp -> (amplitude, phase_deg), Z0."""
    A = [np.ones_like(t_hours)]
    for c in comps:
        w = math.radians(SPEED[c])
        A.append(np.cos(w * t_hours)); A.append(np.sin(w * t_hours))
    A = np.column_stack(A)
    coef, *_ = np.linalg.lstsq(A, z, rcond=None)
    fit = A @ coef
    out = {}
    for i, c in enumerate(comps):
        a, b = coef[1 + 2*i], coef[2 + 2*i]
        # Model z = Z0 + Σ A cos(σt − g): A cos g = a, A sin g = b  ->  g = atan2(b, a)
        out[c] = (float(math.hypot(a, b)), float(math.degrees(math.atan2(b, a)) % 360))
    rms = float(np.sqrt(np.mean((z - fit) ** 2)))
    var = 1.0 - float(np.var(z - fit) / np.var(z))
    return out, float(coef[0]), rms, var

def formzahl(amp):
    K1 = amp["K1"][0]; O1 = amp["O1"][0]; M2 = amp["M2"][0]; S2 = amp["S2"][0]
    F = (K1 + O1) / (M2 + S2)
    if F <= 0.25:
        t, k, d = "Semidiurnal (Harian Ganda)", "semidiurnal", "Dua kali pasang & dua kali surut tiap hari dengan tinggi hampir sama."
    elif F <= 1.5:
        t, k, d = "Campuran Condong Harian Ganda", "campuran-ganda", "Dua kali pasang & dua kali surut tiap hari, tetapi tinggi dan intervalnya berbeda."
    elif F <= 3.0:
        t, k, d = "Campuran Condong Harian Tunggal", "campuran-tunggal", "Umumnya satu kali pasang & satu kali surut, kadang dua kali dengan tinggi sangat berbeda."
    else:
        t, k, d = "Diurnal (Harian Tunggal)", "diurnal", "Satu kali pasang dan satu kali surut dalam satu hari."
    return float(F), t, k, d

t0 = df["ts"].iloc[0]
df["th"] = (df["ts"] - t0).dt.total_seconds() / 3600.0

# --- harmonik seluruh periode (5 tahun) ---
amp_all, z0_all, rms_all, var_all = harmonik(df["th"].values, df["z"].values, SET_TAHUN)
F_all, tipe_all, key_all, desc_all = formzahl(amp_all)
print(f"\n5 TAHUN: Z0={z0_all:.4f} F={F_all:.4f} -> {tipe_all} (RMS={rms_all:.4f} R2={var_all:.4f})")
for c in SET_TAHUN:
    print(f"   {c:4s} A={amp_all[c][0]:.4f} m  g={amp_all[c][1]:7.2f}")

def stat_blok(sub, amp, z0):
    """Statistik muka air untuk satu blok waktu."""
    z = sub["z"].values
    M2,S2,N2,K1,O1 = (amp[c][0] for c in ("M2","S2","N2","K1","O1"))
    K2 = amp["K2"][0] if "K2" in amp else S2 * INFER["K2"][1]
    P1 = amp["P1"][0] if "P1" in amp else K1 * INFER["P1"][1]
    sum_major = M2+S2+N2+K2+K1+O1+P1
    # puncak (pasang tinggi) & lembah (surut rendah) lokal
    hi = z[1:-1][(z[1:-1] > z[:-2]) & (z[1:-1] > z[2:])]
    lo = z[1:-1][(z[1:-1] < z[:-2]) & (z[1:-1] < z[2:])]
    i_max = int(np.argmax(z)); i_min = int(np.argmin(z))
    return {
        "n": int(len(z)),
        "Z0_MSL": round(float(z0), 4),
        "z_maks": round(float(z.max()), 4),
        "z_min": round(float(z.min()), 4),
        "waktu_z_maks": sub["ts_wib"].iloc[i_max].strftime("%Y-%m-%d %H:%M"),
        "waktu_z_min": sub["ts_wib"].iloc[i_min].strftime("%Y-%m-%d %H:%M"),
        "tunggang_maks": round(float(z.max() - z.min()), 4),
        "MHWL": round(float(hi.mean()), 4) if len(hi) else None,
        "MLWL": round(float(lo.mean()), 4) if len(lo) else None,
        "HHWL_formula": round(float(z0 + sum_major), 4),
        "LLWL_formula": round(float(z0 - sum_major), 4),
        "LWS_chart_datum": round(float(z0 - (M2+S2+K1+O1)), 4),
        "p50": round(float(np.percentile(z, 50)), 4),
        "p95": round(float(np.percentile(z, 95)), 4),
        "p99": round(float(np.percentile(z, 99)), 4),
        "p999": round(float(np.percentile(z, 99.9)), 4),
    }

BULAN_ID = ["Januari","Februari","Maret","April","Mei","Juni","Juli",
            "Agustus","September","Oktober","November","Desember"]

hasil_tahun = {}
for y in YEARS:
    sub = df_th[df_th["tahun"] == y].reset_index(drop=True)
    th = (sub["ts"] - sub["ts"].iloc[0]).dt.total_seconds().values / 3600.0
    amp, z0, rms, var = harmonik(th, sub["z"].values, SET_TAHUN)
    F, tipe, key, desc = formzahl(amp)
    st = stat_blok(sub, amp, z0)
    bulanan = {}
    for b in range(1, 13):
        sb = sub[sub["bulan"] == b].reset_index(drop=True)
        thb = (sb["ts"] - sb["ts"].iloc[0]).dt.total_seconds().values / 3600.0
        ampb, z0b, rmsb, varb = harmonik(thb, sb["z"].values, SET_BULAN)
        # inferensi K2 & P1 (tak terpisah dalam rekaman 1 bulan)
        ampb["K2"] = (ampb["S2"][0] * INFER["K2"][1], ampb["S2"][1])
        ampb["P1"] = (ampb["K1"][0] * INFER["P1"][1], ampb["K1"][1])
        Fb, tipeb, keyb, descb = formzahl(ampb)
        stb = stat_blok(sb, ampb, z0b)
        bulanan[b] = {
            "bulan": b, "nama": BULAN_ID[b-1],
            "formzahl": round(Fb, 6), "tipe": tipeb, "tipe_key": keyb, "tipe_desc": descb,
            "konstanta": {c: {"A": round(ampb[c][0], 7), "g": round(ampb[c][1], 3)}
                          for c in SET_BULAN + ["K2","P1"]},
            "rms_residual": round(rmsb, 6), "r2": round(varb, 6),
            "statistik": stb,
            "seri_harian": [
                {"h": int(d), "maks": round(float(g["z"].max()), 3), "min": round(float(g["z"].min()), 3),
                 "rata": round(float(g["z"].mean()), 3)}
                for d, g in sb.groupby(sb["ts_wib"].dt.day)
            ],
        }
    hasil_tahun[y] = {
        "tahun": y,
        "formzahl": round(F, 6), "tipe": tipe, "tipe_key": key, "tipe_desc": desc,
        "konstanta": {c: {"A": round(amp[c][0], 7), "g": round(amp[c][1], 3)} for c in SET_TAHUN},
        "rms_residual": round(rms, 6), "r2": round(var, 6),
        "statistik": st,
        "titik": titik_tahun[y],
        "bulanan": bulanan,
    }
    print(f"{y}: F={F:.4f} {tipe:38s} maks={st['z_maks']:+.3f} min={st['z_min']:+.3f} R2={var:.4f}")

# ---------- 3. AMBANG BANJIR ROB (berbasis exceedance seluruh 5 tahun) ----------
z_all = df["z"].values
amb = {
    "normal": round(float(np.percentile(z_all, 90)), 3),
    "waspada": round(float(np.percentile(z_all, 98)), 3),
    "siaga": round(float(np.percentile(z_all, 99.5)), 3),
    "bahaya": round(float(np.percentile(z_all, 99.9)), 3),
    "maksimum": round(float(z_all.max()), 3),
    "MSL": round(float(z_all.mean()), 4),
}
print("\nAmbang:", amb)

# jumlah jam & hari melewati tiap ambang, per tahun & per bulan
for y in YEARS:
    sub = df_th[df_th["tahun"] == y]
    ev = {}
    for k in ("waspada", "siaga", "bahaya"):
        thr = amb[k]
        mask = sub["z"] >= thr
        hari = sub.loc[mask, "ts_wib"].dt.normalize().nunique()
        ev[k] = {"jam": int(mask.sum()), "hari": int(hari)}
    hasil_tahun[y]["kejadian_rob"] = ev
    for b in range(1, 13):
        sb = sub[sub["bulan"] == b]
        evb = {}
        for k in ("waspada", "siaga", "bahaya"):
            mask = sb["z"] >= amb[k]
            evb[k] = {"jam": int(mask.sum()),
                      "hari": int(sb.loc[mask, "ts_wib"].dt.normalize().nunique())}
        hasil_tahun[y]["bulanan"][b]["kejadian_rob"] = evb

# ---------- 4. SERI WAKTU RINGKAS UNTUK GRAFIK ----------
# seri harian (maks/min/rata) seluruh periode -> ~1826 titik, cukup ringan
harian = (df.set_index("ts_wib")["z"].resample("D")
            .agg(["max", "min", "mean"]).dropna().round(3).reset_index())
seri_harian_all = [
    {"t": r["ts_wib"].strftime("%Y-%m-%d"), "x": float(r["max"]),
     "n": float(r["min"]), "a": float(r["mean"])}
    for _, r in harian.iterrows()
]

out = {
    "sumber": {
        "instansi": "Badan Informasi Geospasial (BIG)",
        "unit": "Direktorat Sistem Referensi Geospasial",
        "sistem": "SRGI - Sistem Referensi Geospasial Indonesia",
        "email": "srgi@big.go.id",
        "produk": "Prediksi Pasang Surut",
        "lat": meta["lat"], "lon": meta["lon"],
        "lat_pusat": meta["lat_pusat"], "lon_pusat": meta["lon_pusat"],
        "titik_sama": meta["titik_sama"],
        "jarak_maks_m": meta["jarak_maks_m"],
        "jarak_maks_pasangan": meta["jarak_maks_pasangan"],
        "titik_tahun": meta["titik_tahun"],
        "datum": "Tinggi muka air z (m) relatif terhadap Mean Sea Level (MSL) lokal",
        "zona_waktu_data": "UTC",
        "zona_waktu_tampil": "WIB (UTC+7)",
        "resolusi": "1 jam",
        "periode": "2024-01-01 s.d. 2028-12-31",
    },
    "qc": qc,
    "metode": {
        "harmonik": "Least-squares harmonic analysis terhadap data prediksi per jam",
        "komponen_tahunan": SET_TAHUN,
        "komponen_bulanan": SET_BULAN,
        "inferensi": "K2 = 0.27215 x S2 ; P1 = 0.33093 x K1 (dipakai pada analisis bulanan karena tidak terpisah menurut kriteria Rayleigh untuk rekaman 1 bulan)",
        "formzahl": "F = (AK1 + AO1) / (AM2 + AS2); F<=0.25 semidiurnal, 0.25<F<=1.5 campuran condong ganda, 1.5<F<=3.0 campuran condong tunggal, F>3.0 diurnal",
        "ambang_rob": "Persentil empiris muka air seluruh periode 2024-2028 (P90/P98/P99.5/P99.9)",
        "zona_waktu": "Seluruh pengelompokan tahun dan bulan serta seluruh jam memakai WIB (UTC+7); data mentah BIG bertanda waktu UTC.",
    },
    "gabungan_5tahun": {
        "formzahl": round(F_all, 6), "tipe": tipe_all, "tipe_key": key_all, "tipe_desc": desc_all,
        "konstanta": {c: {"A": round(amp_all[c][0], 7), "g": round(amp_all[c][1], 3)} for c in SET_TAHUN},
        "rms_residual": round(rms_all, 6), "r2": round(var_all, 6),
        "statistik": stat_blok(df, amp_all, z0_all),
    },
    "ambang": amb,
    "tahun": {str(k): v for k, v in hasil_tahun.items()},
    "seri_harian": seri_harian_all,
}
(BASE / "data" / "pasut_analisis.json").write_text(json.dumps(out, ensure_ascii=False), encoding="utf-8")
print("\nJSON:", (BASE / "data" / "pasut_analisis.json").stat().st_size, "bytes")
