#!/usr/bin/env python3
"""
Menyusun data/evakuasi.json dari:
  - geometri_evakuasi.json        (hasil tools/geometri_evakuasi.py: titik aman & jarak pantai terhitung)
  - includes/data.json            (WIL: KK per kampung; NILAI: elevasi DEMNAS & jarak pantai lapangan)
Tidak ada angka yang dikarang: setiap kolom mencantumkan asal datanya.
"""
import json, math
from pathlib import Path

BASE = Path(__file__).resolve().parent.parent
geo = json.loads((BASE / "data" / "geometri_evakuasi.json").read_text())
D = json.loads((BASE / "includes/data.json").read_text())

JIWA_PER_KK = 4          # ASUMSI perencanaan; belum ada data desa. Ditandai di UI.
SPHERE_M2 = 3.5          # Sphere Handbook: luas terlindung minimum per jiwa di tempat penampungan
LAJU_JALAN_MS = 0.75     # ASUMSI: laju jalan populasi campuran (anak, lansia) saat evakuasi
FAKTOR_LIKU = 1.3        # ASUMSI: faktor liku jalur nyata terhadap jarak lurus

# indeks parameter pada NILAI (urutan sama dengan PARAM)
I_ELEV, I_SLOPE, I_JARAK = 0, 1, 2

titik, jalur = [], []
for k in geo["kampung"]:
    i = k["id"] - 1
    wil = D["WIL"][i]
    nilai = D["NILAI"][i]
    kk = wil[2]
    jiwa = kk * JIWA_PER_KK
    d_lurus = k["jarak_permukiman_ke_kandidat_m"]
    d_jalur = d_lurus * FAKTOR_LIKU
    menit = d_jalur / LAJU_JALAN_MS / 60

    titik.append({
        "id_titik": k["id"],
        "id_wilayah": k["id"],
        "nama_titik": "Titik Aman " + k["nama"].replace("Kampung ", ""),
        "jenis": "Dataran dalam (usulan geometris)",
        "lintang": k["kandidat_titik_aman"]["lat"],
        "bujur": k["kandidat_titik_aman"]["lon"],
        # elevasi: nilai DEMNAS tingkat KAMPUNG dari data parameter pengguna, bukan ekstraksi per titik
        "elevasi_kampung_m": nilai[I_ELEV],
        "elevasi_titik_m": None,
        "kemiringan_kampung_persen": nilai[I_SLOPE],
        "jarak_pantai_m": k["jarak_kandidat_ke_pantai_m"],
        "jarak_pantai_terjauh_kampung_m": k["jarak_pantai_maks_m"],
        "kapasitas_orang": None,
        "status_validasi": "usulan",
        "keterangan": (
            f"Titik interior dengan jarak maksimum ke garis pantai di {k['nama']} "
            f"({k['jarak_kandidat_ke_pantai_m']:.0f} m). Dihitung dari garis pantai digitasi. "
            "Elevasi per titik dan akses jalan BELUM diverifikasi."
        ),
    })
    jalur.append({
        "id_jalur": k["id"],
        "id_wilayah": k["id"],
        "id_titik": k["id"],
        "panjang_lurus_m": round(d_lurus, 1),
        "panjang_jalur_estimasi_m": round(d_jalur, 1),
        "waktu_tempuh_menit": round(menit, 1),
        "keterangan": (
            f"Jarak lurus titik permukiman ke titik aman {d_lurus:.0f} m; estimasi panjang jalur "
            f"{d_jalur:.0f} m (faktor liku {FAKTOR_LIKU}); waktu tempuh {menit:.0f} menit "
            f"pada laju {LAJU_JALAN_MS} m/s. Jalur nyata belum disurvei."
        ),
    })

# ---------- kebutuhan penampungan per kampung ----------
kebutuhan = []
for k in geo["kampung"]:
    i = k["id"] - 1
    kk = D["WIL"][i][2]
    jiwa = kk * JIWA_PER_KK
    kebutuhan.append({
        "id_wilayah": k["id"], "nama": k["nama"],
        "kk_estimasi": kk,
        "jiwa_estimasi": jiwa,
        "luas_terlindung_minimum_m2": round(jiwa * SPHERE_M2, 0),
        "elevasi_demnas_m": D["NILAI"][i][I_ELEV],
        "jarak_pantai_parameter_m": D["NILAI"][i][I_JARAK],
        "jarak_pantai_titik_terhitung_m": k["jarak_titik_ke_pantai_m"],
        "selisih_jarak_m": round(k["jarak_titik_ke_pantai_m"] - D["NILAI"][i][I_JARAK], 1),
        "porsi_lahan_kurang_100m_dari_pantai": k["porsi_lahan_kurang_100m_dari_pantai"],
        "luas_darat_ha": k["luas_darat_ha"],
    })

out = {
    "asumsi": {
        "jiwa_per_kk": JIWA_PER_KK,
        "jiwa_per_kk_catatan": "ASUMSI perencanaan. Belum ada data jiwa per KK dari kantor desa.",
        "luas_per_jiwa_m2": SPHERE_M2,
        "luas_per_jiwa_sumber": "Sphere Handbook: luas terlindung minimum 3,5 m2 per orang",
        "laju_jalan_ms": LAJU_JALAN_MS,
        "laju_jalan_catatan": "ASUMSI: populasi campuran termasuk anak dan lansia.",
        "faktor_liku": FAKTOR_LIKU,
        "faktor_liku_catatan": "ASUMSI: panjang jalur nyata = 1,3 x jarak lurus. Jalur belum disurvei.",
    },
    "status_global": "USULAN - belum ditetapkan BPBD Kabupaten Serang atau Pemerintah Desa Pulo Panjang",
    "dasar_data": [
        "Garis pantai digitasi pengguna (data/garis_pantai_digitasi.geojson)",
        "Geometri 7 kampung (data/geoms.json)",
        "Elevasi DEMNAS/BIG dan jarak garis pantai: tabel data_parameter",
        "Jumlah KK: pernyataan Kepala Desa Pulo Panjang (SRSentra, 22 Mei 2026), dibagi rata 7 kampung",
    ],
    "belum_tersedia": [
        "Elevasi DEMNAS pada koordinat tiap titik evakuasi (baru tersedia di tingkat kampung)",
        "Jalur jalan nyata, lebar jalan, dan hambatan di lapangan",
        "Bangunan bertingkat, kapasitas tampung, dan ketersediaan air bersih di titik aman",
        "Kepemilikan dan tutupan lahan di titik aman",
        "Penetapan resmi titik evakuasi oleh BPBD / Pemerintah Desa",
    ],
    "pulau": geo["pulau"],
    "titik_srgi_big": geo["titik_srgi_big"],
    "titik_evakuasi": titik,
    "jalur_evakuasi": jalur,
    "kebutuhan_penampungan": kebutuhan,
}
(BASE / "data/evakuasi.json").write_text(json.dumps(out, ensure_ascii=False, indent=1), encoding="utf-8")

tot_kk = sum(x["kk_estimasi"] for x in kebutuhan)
tot_jiwa = sum(x["jiwa_estimasi"] for x in kebutuhan)
print(f"Total {tot_kk} KK, estimasi {tot_jiwa} jiwa, "
      f"kebutuhan luas terlindung {tot_jiwa*SPHERE_M2:,.0f} m2\n")
print(f"{'Kampung':24s} {'elev':>5s} {'d.par':>6s} {'d.hitung':>9s} {'selisih':>8s} {'jalur m':>8s} {'menit':>6s}")
for kb, jl in zip(kebutuhan, jalur):
    print(f"{kb['nama']:24s} {kb['elevasi_demnas_m']:5.1f} {kb['jarak_pantai_parameter_m']:6.0f} "
          f"{kb['jarak_pantai_titik_terhitung_m']:9.0f} {kb['selisih_jarak_m']:8.0f} "
          f"{jl['panjang_jalur_estimasi_m']:8.0f} {jl['waktu_tempuh_menit']:6.0f}")
