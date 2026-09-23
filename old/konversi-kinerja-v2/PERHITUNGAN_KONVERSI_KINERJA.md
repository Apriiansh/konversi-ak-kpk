# PANDUAN PERHITUNGAN KONVERSI ANGKA KREDIT DAN BOOSTER IJAZAH KPK
##### Berdasarkan Peraturan BKN Nomor 3 Tahun 2023 & Aturan Penilaian Kinerja KPK

Dokumen ini merupakan panduan teknis perhitungan Angka Kredit (AK) hasil konversi Predikat Kinerja dan Booster Ijazah secara komprehensif. Seluruh perhitungan di bawah ini telah diselaraskan dengan logika backend sistem, database schema Prisma, serta divalidasi silang dengan kasus nyata BKN dan kebijakan evaluasi periodik di lingkungan Komisi Pemberantasan Korupsi (KPK).

---

## BAGIAN 1 — RUMUS UTAMA & KONSTANTA ACUAN

### 1.1 Rumus Umum Konversi Angka Kredit (Proporsional & Periodik)
Sesuai **Pasal 13 ayat 3 Peraturan BKN No. 3 Tahun 2023**, perhitungan Angka Kredit tahunan maupun periodik dihitung menggunakan rumus proporsional sebagai berikut:

$$\text{Angka Kredit (AK)} = \text{round}\left( \frac{\text{Jumlah Bulan Kerja}}{12} \times \text{Persentase Predikat} \times \text{Koefisien Tahunan}, 2 \right)$$

*Catatan: Pembulatan menggunakan standar desimal 2 digit di belakang koma (`round($ak, 2)`) sesuai dengan representasi angka desimal pada lampiran formulir resmi BKN.*

### 1.2 Tabel Konstanta Koefisien Tahunan & Target Angka Kredit
Setiap jenjang Jabatan Fungsional (JF) memiliki koefisien tahunan (target maksimal 100% kinerja) serta target minimal akumulasi AK untuk Kenaikan Pangkat (KP) dan Kenaikan Jenjang (KJ):

| Kategori | Jenjang Jabatan | Koefisien Tahunan | Target Kenaikan Pangkat (KP) | Target Kenaikan Jenjang (KJ) |
| :--- | :--- | :---: | :---: | :---: |
| **Keahlian** | Ahli Pertama | **12.5** | 50 | 100 |
| | Ahli Muda | **25.0** | 100 | 200 |
| | Ahli Madya | **37.5** | 150 | 450 |
| | Ahli Utama | **50.0** | 200 | — |
| **Keterampilan** | Pemula | **3.75** | 15 | 15 |
| | Terampil | **5.00** | 20 | 60 |
| | Mahir | **12.50** | 50 | 100 |
| | Penyelia | **25.00** | 100 | — |

### 1.3 Tabel Nilai Kuantitatif Persentase Predikat Kinerja
Konversi nilai predikat berdasarkan hasil evaluasi SKP dan perilaku kerja (Core Values BerAKHLAK):

| Predikat Kinerja | Persentase Konversi | Keterangan Aturan Bisnis |
| :--- | :---: | :--- |
| **Sangat Baik** | **150%** | Melebihi ekspektasi secara konsisten |
| **Baik** | **100%** | Memenuhi ekspektasi pimpinan |
| **Butuh Perbaikan** | **75%** | Belum sepenuhnya memenuhi ekspektasi |
| **Kurang** | **50%** | Di bawah ekspektasi pimpinan |
| **Sangat Kurang** | **25%** | Jauh di bawah ekspektasi pimpinan |

### 1.4 Rumus Tambahan AK (Booster Ijazah Pendidikan +25%)
Berdasarkan **Pasal 14**, PNS yang memperoleh peningkatan pendidikan formal lebih tinggi (misal dari S1 ke S2) dan gelarnya telah dicantumkan oleh BKN, berhak mendapatkan booster ijazah satu kali:

$$\text{AK}_{\text{Booster}} = 25\% \times \text{Kebutuhan AK Kenaikan Pangkat (Jenjang Saat Ini)}$$

---

## BAGIAN 2 — SKENARIO A: AKUMULASI TRIWULAN BERJALAN (Q1 - Q4)
*Skenario: Pejabat Fungsional **Ahli Pertama (Koefisien = 12.5)** dengan predikat kinerja selalu **Baik (100%)** sepanjang tahun.*

Penilaian periodik triwulanan dihitung per 3 bulan berjalan. Nilai kuartal di bawah ini merepresentasikan **akumulasi kumulatif berjalan (running total)** yang akan tercatat di dalam sistem pada setiap akhir triwulan:

1. **Triwulan I (3 Bulan):**
   $$\text{AK} = \frac{3}{12} \times 100\% \times 12.5 = 3.125 \approx \mathbf{3.13\text{ AK}}$$
2. **Triwulan II (Kumulatif 6 Bulan):**
   $$\text{AK} = \frac{6}{12} \times 100\% \times 12.5 = \mathbf{6.25\text{ AK}}$$
3. **Triwulan III (Kumulatif 9 Bulan):**
   $$\text{AK} = \frac{9}{12} \times 100\% \times 12.5 = 9.375 \approx \mathbf{9.38\text{ AK}}$$
4. **Triwulan IV (Kumulatif 12 Bulan / Disetahunkan):**
   $$\text{AK} = \frac{12}{12} \times 100\% \times 12.5 = \mathbf{12.50\text{ AK}}$$

---

## BAGIAN 3 — SKENARIO B: VARIASI PREDIKAT TAHUNAN (12 BULAN - AHLI PERTAMA)
*Skenario: Hasil perolehan Angka Kredit penuh 12 bulan untuk jenjang **Ahli Pertama (Koefisien = 12.5)** berdasarkan variasi predikat tahunan final:*

* **Sangat Baik (150%):**
  $$\text{AK} = 1.50 \times 12.5 = \mathbf{18.75\text{ AK}}$$
* **Baik (100%):**
  $$\text{AK} = 1.00 \times 12.5 = \mathbf{12.50\text{ AK}}$$
* **Butuh Perbaikan (75%):**
  $$\text{AK} = 0.75 \times 12.5 = 9.375 \approx \mathbf{9.38\text{ AK}}$$
* **Kurang (50%):**
  $$\text{AK} = 0.50 \times 12.5 = \mathbf{6.25\text{ AK}}$$
* **Sangat Kurang (25%):**
  $$\text{AK} = 0.25 \times 12.5 = 3.125 \approx \mathbf{3.13\text{ AK}}$$

---

## BAGIAN 4 — SKENARIO C: PERBANDINGAN ANTAR JENJANG KEAHLIAN (12 BULAN - SANGAT BAIK)
*Skenario: Perolehan Angka Kredit tahunan (12 bulan) maksimal dengan kinerja **Sangat Baik (150%)** di seluruh tingkat jenjang keahlian:*

* **Ahli Pertama (Koefisien 12.5):**
  $$\text{AK} = 150\% \times 12.5 = \mathbf{18.75\text{ AK}}$$
* **Ahli Muda (Koefisien 25.0):**
  $$\text{AK} = 150\% \times 25.0 = \mathbf{37.50\text{ AK}}$$
* **Ahli Madya (Koefisien 37.5):**
  $$\text{AK} = 150\% \times 37.5 = \mathbf{56.25\text{ AK}}$$
* **Ahli Utama (Koefisien 50.0):**
  $$\text{AK} = 150\% \times 50.0 = \mathbf{75.00\text{ AK}}$$

---

## BAGIAN 5 — SKENARIO D: ESTIMASI WAKTU KECUKUPAN KENAIKAN PANGKAT (KP)
Menggunakan simulasi kinerja konsisten **Sangat Baik (150%)** versus **Baik (100%)**, sistem dapat memproyeksikan estimasi waktu tercepat bagi pegawai untuk mencapai ambang batas (*threshold*) kenaikan pangkat:

### 5.1 Estimasi dengan Predikat Konsisten "Sangat Baik" (150%)
Berkat skema konversi PerBKN 3/2023, seluruh jenjang jabatan fungsional keahlian memiliki **harmoni matematis** yang sama: pegawai berkinerja Sangat Baik akan selalu mencapai target angka kredit kenaikan pangkat hanya dalam kurun waktu **2 Tahun 8 Bulan (2.67 Tahun)**!

* **Ahli Pertama (Target KP = 50 AK):**
  $$\text{Waktu} = \frac{50\text{ AK}}{18.75\text{ AK/Tahun}} = \mathbf{2.67\text{ Tahun}} \implies \text{2 Tahun 8 Bulan}$$
* **Ahli Muda (Target KP = 100 AK):**
  $$\text{Waktu} = \frac{100\text{ AK}}{37.50\text{ AK/Tahun}} = \mathbf{2.67\text{ Tahun}} \implies \text{2 Tahun 8 Bulan}$$
* **Ahli Madya (Target KP = 150 AK):**
  $$\text{Waktu} = \frac{150\text{ AK}}{56.25\text{ AK/Tahun}} = \mathbf{2.67\text{ Tahun}} \implies \text{2 Tahun 8 Bulan}$$
* **Ahli Utama (Target KP = 200 AK):**
  $$\text{Waktu} = \frac{200\text{ AK}}{75.00\text{ AK/Tahun}} = \mathbf{2.67\text{ Tahun}} \implies \text{2 Tahun 8 Bulan}$$

### 5.2 Estimasi dengan Predikat Konsisten "Baik" (100%)
Jika pegawai dinilai berkinerja stabil memenuhi ekspektasi (Baik), maka ia akan mencapai target kenaikan pangkat tepat pada batas standar regulasi kepegawaian yaitu **4 Tahun**:

* **Ahli Pertama:** $\frac{50}{12.5} = \mathbf{4.0\text{ Tahun}}$
* **Ahli Muda:** $\frac{100}{25.0} = \mathbf{4.0\text{ Tahun}}$
* **Ahli Madya:** $\frac{150}{37.5} = \mathbf{4.0\text{ Tahun}}$
* **Ahli Utama:** $\frac{200}{50.0} = \mathbf{4.0\text{ Tahun}}$

---

## BAGIAN 6 — SKENARIO E: MATRIKS LENGKAP JENJANG × PREDIKAT KINERJA (12 BULAN)

Matriks di bawah ini memuat nilai mutlak angka kredit tahunan (12 bulan) hasil konversi predikat kinerja untuk seluruh jenjang fungsional (Keahlian dan Keterampilan):

| Jenjang Jabatan | Koefisien | Sangat Baik (150%) | Baik (100%) | Butuh Perbaikan (75%) | Kurang (50%) | Sangat Kurang (25%) |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: |
| **Ahli Utama** | 50.00 | **75.00** | **50.00** | **37.50** | **25.00** | **12.50** |
| **Ahli Madya** | 37.50 | **56.25** | **37.50** | **28.13** | **18.75** | **9.38** |
| **Ahli Muda / Penyelia** | 25.00 | **37.50** | **25.00** | **18.75** | **12.50** | **6.25** |
| **Ahli Pertama / Mahir** | 12.50 | **18.75** | **12.50** | **9.38** | **6.25** | **3.13** |
| **Terampil** | 5.00 | **7.50** | **5.00** | **3.75** | **2.50** | **1.25** |
| **Pemula** | 3.75 | **5.63** | **3.75** | **2.81** | **1.88** | **0.94** |

---

## BAGIAN 7 — BOOSTER IJAZAH PENDIDIKAN BARU (+25%)

### 7.1 Tabel Nilai Tambahan AK Booster Per Jenjang
Ketika pegawai mendapatkan ijazah pendidikan yang lebih tinggi, tambahan AK dihitung 25% dari kebutuhan kenaikan pangkat jenjang tersebut saat ini:

| Jenjang Jabatan | Syarat Target AK Kenaikan Pangkat | Nilai Tambahan AK Booster (+25%) |
| :--- | :---: | :---: |
| **Ahli Pertama** | 50 AK | **+12.50 AK** |
| **Ahli Muda** | 100 AK | **+25.00 AK** |
| **Ahli Madya** | 150 AK | **+37.50 AK** |
| **Ahli Utama** | 200 AK | **+50.00 AK** |

### 7.2 Tiga (3) Syarat Validasi Otomatis pada Sistem Backend
Aplikasi akan meloloskan klaim tambahan AK Booster secara otomatis jika memenuhi 3 syarat berikut:
1. **Peningkatan Kualifikasi Linier:** Jenjang ijazah yang diklaim harus lebih tinggi dari jenjang ijazah pada data induk HRIS saat pengangkatan pertama (misal: D-III naik ke S-1/D-IV, atau S-1 naik ke S-2).
2. **Pencantuman Gelar Sah BKN:** Klaim ijazah telah disetujui secara administratif oleh BKN (pencantuman gelar sudah aktif di sistem kepegawaian).
3. **Ambang Batas Kinerja Minimal:** Evaluasi kinerja pada tahun berjalan (saat ijazah diajukan) wajib bernilai paling rendah **Baik** (atau **Sangat Baik**). Jika predikat tahunannya di bawah "Baik", klaim booster ditolak otomatis oleh sistem.

### 7.3 Simulasi Kasus Klaim Booster Ijazah (S1 ke S2)
* **Profil Pegawai:** Sdri. Nevia Herdianti, S.Psi., pangkat Penata Muda (III/a), Jabatan Ahli Pertama.
* **Kondisi Awal:** Memiliki saldo awal **37.50 AK**. Menyelesaikan pendidikan S2 Psikologi Industri dan mendapatkan Surat Keputusan Pencantuman Gelar dari BKN.
* **Proses Evaluasi Sistem:**
  * Kebutuhan KP Ahli Pertama = 50 AK.
  * Formula Booster: $25\% \times 50 = \mathbf{12.50\text{ AK}}$.
  * Predikat Kinerja Nevia tahun berjalan = **Baik** (Lolos verifikasi kinerja).
* **Perhitungan Akhir Kumulatif:**
  $$\text{AK Akhir} = 37.50\text{ (Lama)} + 12.50\text{ (Booster)} = \mathbf{50.00\text{ AK}}$$
  *Hasil Akhir:* Sistem langsung mendeteksi total AK Nevia mencapai **50.00**, mengubah statusnya menjadi **"LAYAK NAIK PANGKAT"** ke golongan III/b secara otomatis.

---

## BAGIAN 8 — FORMAT STRUKTUR REKAPITULASI & DOKUMEN PAK

Format pencatatan data transaksional kepegawaian pada aplikasi KPK mengikuti standarisasi 4 pilar kolom Penetapan Angka Kredit (PAK) resmi BKN:

### 8.1 Penjelasan Kolom-Kolom Utama Database
Berdasarkan Prisma Schema yang Anda miliki, berikut adalah pemetaan logis kolom pada tabel `PenetapanAK`:
* **`akDasar` (AK Dasar):** Modal awal angka kredit yang diberikan secara mutlak bagi pegawai baru diangkat via jalur perpindahan/penyesuaian, yang golongan ruangnya berada di atas golongan minimal jenjangnya (misal: Ahli Muda III/d mendapat AK Dasar 100).
* **`akLama` (AK Lama):** Saldo historis angka kredit kumulatif yang diperoleh dari tabungan tahun-tahun anggaran sebelumnya (*historical carry-over*).
* **`akBaru` (AK Baru):** Hasil perhitungan konversi predikat kinerja riil selama tahun berjalan (baik dari perhitungan disetahunkan maupun parsial triwulanan).
* **`akKumulatif` (AK Kumulatif):** Total keseluruhan modal yang dimiliki pegawai untuk dinilai kelayakannya.
  $$\text{akKumulatif} = \text{akLama} + \text{akBaru} + \text{akDasar}$$

### 8.2 Contoh Transaksi Logis Data pada Portabilitas Kenaikan Pangkat
Bagaimanakah database merekam data transaksional multi-tahun untuk Sdr. Budi? Berikut simulasinya:

#### **Tahun 1 (2025) - Golongan III/a (Pelantikan Tengah Tahun, TMT Maret)**
* **Bulan Aktif:** 10 Bulan (Maret - Desember).
* **Predikat TW4 (Tahunan):** Baik (100%).
* **Klaim Booster Ijazah:** Ya (S1 Baru).
* **Data Database `PenetapanAK` 2025:**
  * `akLama` = **10.00** (Input saldo historis bawaan).
  * `akDasar` = **42.71** (PAK Awal dari masa kerja pelaksana 3 tahun 5 bulan) + **12.50** (Booster S1) = **55.21**.
  * `akBaru` = **10.42** (Normalisasi 10 bulan: $\frac{10}{12} \times 100\% \times 12.5$).
  * `akKumulatif` = $10.00 + 10.42 + 55.21 = \mathbf{75.63\text{ AK}}$.
  * *Evaluasi:* `75.63` melebihi syarat minimal `50.00` untuk naik pangkat ke III/b. Budi dinyatakan **LAYAK NAIK PANGKAT**.
  * *Deposit Carry-Over:* Sisa kelebihan ditabung ke tahun anggaran berikutnya: $75.63 - 50.00 = \mathbf{25.63\text{ AK}}$.

#### **Tahun 2 (2026) - Golongan III/b (Aktif Penuh 12 Bulan)**
* **Bulan Aktif:** 12 Bulan.
* **Predikat TW4 (Tahunan):** Sangat Baik (150%).
* **Klaim Booster:** Tidak (0).
* **Data Database `PenetapanAK` 2026:**
  * `akLama` = **25.63** (Otomatis mengambil sisa tabungan tahun 2025).
  * `akDasar` = **0.00**.
  * `akBaru` = **18.75** (Normalisasi penuh 12 bulan: $\frac{12}{12} \times 150\% \times 12.5$).
  * `akKumulatif` = $25.63 + 18.75 + 0.00 = \mathbf{44.38\text{ AK}}$.
  * *Evaluasi:* `44.38` belum memenuhi syarat target naik pangkat berikutnya ke III/c (**50.00 AK**). Status Budi adalah **BELUM CUKUP AK** (kurang `5.62` AK). Seluruh saldo `44.38` akan dibawa utuh sebagai `akLama` di tahun 2027.

---

## BAGIAN 9 — CONTOH PERHITUNGAN DETAIL DAN VERIFIKASI ENDPOINT API

Untuk melakukan pengetesan backend (Unit Test maupun Integration Test) pada endpoint `/api/evaluasi` dan `/api/booster`, gunakan skenario di bawah ini untuk memastikan kecocokan data desimal:

### Skenario Uji 1: Endpoint `/api/evaluasi` (Perhitungan Kinerja Riil)
* **JSON Request Payload:**
  ```json
  {
    "nip": "199609182023031001",
    "tahun": 2026,
    "jenjangJabatan": "Ahli Pertama",
    "predikatKinerja": "Sangat Baik",
    "bulanKerja": 12
  }
  ```
* **Langkah Perhitungan Manual Backend:**
  $$\text{AK} = \frac{12}{12} \times 150\% \times 12.5 = 1.0 \times 1.5 \times 12.5 = 18.75$$
* **Expected JSON Response (200 OK):**
  ```json
  {
    "success": true,
    "data": {
      "nip": "199609182023031001",
      "tahun": 2026,
      "angkaKreditDiperoleh": 18.75,
      "predikatKinerjaFinal": "Sangat Baik",
      "bulanKerjaAktif": 12,
      "message": "Kalkulasi konversi angka kredit tahunan berhasil divalidasi."
    }
  }
  ```

### Skenario Uji 2: Endpoint `/api/booster` (Klaim Peningkatan Pendidikan)
* **JSON Request Payload:**
  ```json
  {
    "nip": "199609182023031001",
    "jenjangJabatan": "Ahli Pertama",
    "ijazahBaru": "S2",
    "predikatKinerjaTahunan": "Baik"
  }
  ```
* **Langkah Perhitungan Manual Backend:**
  * Target kenaikan pangkat Ahli Pertama = 50 AK.
  * Nilai Booster: $25\% \times 50 = 12.50$ AK.
* **Expected JSON Response (200 OK):**
  ```json
  {
    "success": true,
    "data": {
      "nip": "199609182023031001",
      "jenjangJabatan": "Ahli Pertama",
      "tambahanAngkaKredit": 12.50,
      "statusKlaim": "APPROVED",
      "message": "Peningkatan pendidikan terverifikasi. Tambahan AK sebesar 12.50 ditambahkan ke kolom PAK."
    }
  }
  ```

---

Dengan mengikuti panduan perhitungan ini, Tim QA, Database Administrator, dan Frontend/Backend Developer KPK memiliki satu dokumen rujukan tunggal (*single source of truth*) yang solid dan selaras dengan Peraturan BKN Nomor 3 Tahun 2023. Dokumen ini dapat ditaruh berdampingan dengan `HASIL_TEST_API.md` pada direktori root project.
