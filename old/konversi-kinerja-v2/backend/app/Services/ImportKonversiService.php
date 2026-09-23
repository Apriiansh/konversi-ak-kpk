<?php

namespace App\Services;

use App\Models\EvaluasiKinerja;
use App\Models\MasterJenjangJabatan;
use App\Models\MasterPangkatGolongan;
use App\Models\MasterPredikatKinerja;
use App\Models\Notifikasi;
use App\Models\Pegawai;
use App\Models\PenetapanAK;
use App\Models\User;
use App\Support\XlsxWriter;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ImportKonversiService
{
    protected HitungKonversiService $hitungKonversi;
    protected CarryOverService $carryOverService;
    protected AuditTrailService $auditTrail;

    public function __construct(
        HitungKonversiService $hitungKonversi,
        CarryOverService $carryOverService,
        AuditTrailService $auditTrail
    ) {
        $this->hitungKonversi = $hitungKonversi;
        $this->carryOverService = $carryOverService;
        $this->auditTrail = $auditTrail;
    }

    /**
     * Bangun berkas template XLSX resmi untuk import data konversi kinerja massal.
     * Dibangun secara native (tanpa dependensi pihak ketiga) memakai ZIP + XML OpenXML.
     *
     * @return string berisi biner file .xlsx
     */
    public function getXlsxTemplate(): string
    {
        $rows = [
            [
                'nip', 'nama_lengkap', 'email', 'golongan', 'asal_jabatan', 'jenjang_jabatan',
                'pendidikan_terakhir', 'tmt_jabatan', 'masa_kerja_tahun', 'masa_kerja_bulan',
                'saldo_historis', 'Predikat Kinerja Pegawai (PKP)',
            ],
            [
                '199503012025031001', 'Budi Santoso, S.T', 'budi.santoso@kpk.go.id',
                'III/a', 'PELAKSANA', 'Ahli Pertama', 'S1', '2025-03-01', '3', '5',
                '10.00', 'Baik',
            ],
        ];

        return XlsxWriter::build($rows, 'Template');
    }

    /**
     * Parse file (CSV atau XLSX) menjadi array of associative array baris data.
     *
     * @param UploadedFile|string $file
     * @return array
     */
    public function parseFile($file): array
    {
        $filePath = is_string($file) ? $file : $file->getRealPath();
        $originalName = $file instanceof UploadedFile ? $file->getClientOriginalName() : (is_string($file) ? $file : '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension === 'xlsx' || $this->isZipFile($filePath)) {
            return $this->parseXlsxFile($filePath);
        }

        return $this->parseCsvFile($filePath);
    }

    /**
     * Cek apakah file merupakan ZIP archive (format OpenXML .xlsx).
     */
    protected function isZipFile(string $filePath): bool
    {
        $handle = @fopen($filePath, 'r');
        if (!$handle) {
            return false;
        }
        $header = fread($handle, 4);
        fclose($handle);
        return $header === "PK\x03\x04";
    }

    /**
     * Peta alias header: key = nama kolom canonical, value = array alias yang diterima.
     */
    protected static array $headerAliases = [
        'nip'                 => ['nip', 'no_nip', 'nomor_nip', 'no nip', 'nomor nip'],
        'nama_lengkap'        => ['nama_lengkap', 'nama', 'name', 'nama_pegawai', 'fullname', 'nama lengkap', 'nama pegawai'],
        'email'               => ['email', 'e-mail', 'e_mail'],
        'golongan'            => ['golongan', 'gol', 'pangkat', 'pangkat_golongan', 'pangkat_gol', 'pangkat golongan', 'pangkat gol'],
        'asal_jabatan'        => ['asal_jabatan', 'asal', 'jabatan_asal', 'asal jabatan', 'jabatan asal'],
        'jenjang_jabatan'     => ['jenjang_jabatan', 'jenjang', 'jabatan_jenjang', 'target_jenjang', 'jenjang jabatan', 'jabatan jenjang', 'target jenjang'],
        'pendidikan_terakhir' => ['pendidikan_terakhir', 'pendidikan', 'pendidikan_last', 'pendidikan terakhir'],
        'tmt_jabatan'         => ['tmt_jabatan', 'tmt', 'tmt jabatan'],
        'masa_kerja_tahun'    => ['masa_kerja_tahun', 'mk_tahun', 'masa kerja tahun', 'mk tahun'],
        'masa_kerja_bulan'    => ['masa_kerja_bulan', 'mk_bulan', 'masa kerja bulan', 'mk bulan'],
        'saldo_historis'      => ['saldo_historis', 'saldo', 'historis', 'saldo historis'],
        // Kolom tunggal Predikat Kinerja Pegawai (PKP). Di mode Triwulan = PKP triwulan
        // terpilih; di mode Tahunan = predikat acuan tahunan. Alias lawas tw4_* tetap
        // dipetakan ke sini demi kompatibilitas file lama.
        'pkp'                 => ['pkp', 'predikat', 'predikat_kinerja', 'predikat_kinerja_pegawai', 'predikat kinerja pegawai',
                                  'tw4_predikat', 'tw_4_predikat', 'predikat_tw4', 'predikat tw4', 'tw4 predikat',
                                  'pkp_tahunan', 'predikat_tahunan', 'predikat tahunan'],
        // Rincian opsional TW1-TW3 untuk mode Tahunan (file lawas twX_predikat tetap terbaca).
        'pkp_tw1'             => ['pkp_tw1', 'tw1_predikat', 'tw_1_predikat', 'predikat_tw1', 'predikat tw1', 'tw1 predikat'],
        'pkp_tw2'             => ['pkp_tw2', 'tw2_predikat', 'tw_2_predikat', 'predikat_tw2', 'predikat tw2', 'tw2 predikat'],
        'pkp_tw3'             => ['pkp_tw3', 'tw3_predikat', 'tw_3_predikat', 'predikat_tw3', 'predikat tw3', 'tw3 predikat'],
    ];

    /**
     * Normalisasi header mentah menjadi nama kolom canonical.
     * Langkah: trim → lowercase → hapus karakter non-printable → spasi/dash → underscore → alias resolve.
     *
     * @param  array $rawHeaders
     * @return array
     */
    protected function normalizeHeaders(array $rawHeaders): array
    {
        $aliasMap = [];
        foreach (self::$headerAliases as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $aliasMap[$alias] = $canonical;
            }
        }

        return array_map(function ($raw) use ($aliasMap) {
            $clean = trim(preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strtolower($raw)));
            // Buang grup kurung agar "Predikat Kinerja Pegawai (PKP)" -> predikat_kinerja_pegawai
            $clean = trim(preg_replace('/\([^)]*\)/', '', $clean));
            $underscored = preg_replace('/[\s\-]+/', '_', $clean);
            $underscored = trim($underscored, '_');
            return $aliasMap[$underscored] ?? $aliasMap[$clean] ?? $underscored;
        }, $rawHeaders);
    }

    /**
     * Tentukan skor "keheader-an" sebuah baris: berapa banyak sel yang berhasil
     * dinormalisasi menjadi kolom canonical yang dikenali.
     *
     * @param  array $rawRow
     * @return int
     */
    protected function headerScore(array $rawRow): int
    {
        $aliasMap = [];
        foreach (self::$headerAliases as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $aliasMap[$alias] = $canonical;
            }
        }

        $score = 0;
        foreach ($rawRow as $raw) {
            $clean = trim(preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strtolower($raw)));
            $clean = trim(preg_replace('/\([^)]*\)/', '', $clean));
            $underscored = preg_replace('/[\s\-]+/', '_', $clean);
            $underscored = trim($underscored, '_');
            if (isset($aliasMap[$underscored]) || isset($aliasMap[$clean])) {
                $score++;
            }
        }
        return $score;
    }

    /**
     * Kurasi daftar baris mentah dengan menemukan baris header yang paling mungkin
     * (memuat kolom wajib `nip` dan/atau `golongan` dengan skor tertinggi),
     * lalu ubah seluruh baris menjadi associative array data.
     *
     * @param  array $allRowValues Baris-baris mentah (array of arrays).
     * @param  int   $maxScan      Jumlah baris maksimal yang dipindai untuk menemukan header.
     * @return array
     */
    protected function buildRowsFromHeads(array $allRowValues, int $maxScan = 30): array
    {
        $aliasMap = [];
        foreach (self::$headerAliases as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $aliasMap[$alias] = $canonical;
            }
        }

        $bestIdx = -1;
        $bestScore = -1;
        $bestHeaders = null;

        $scanLimit = min(count($allRowValues), $maxScan);
        for ($i = 0; $i < $scanLimit; $i++) {
            $normalized = $this->normalizeHeaders($allRowValues[$i]);
            $score = $this->headerScore($allRowValues[$i]);

            $hasNip = in_array('nip', $normalized, true);
            $hasGolongan = in_array('golongan', $normalized, true);

            // Baris header wajib setidaknya memuat nip atau golongan,
            // dan skornya harus di atas ambang minimum.
            if (($hasNip || $hasGolongan) && $score >= 3 && $score > $bestScore) {
                $bestIdx = $i;
                $bestScore = $score;
                $bestHeaders = $normalized;
            }
        }

        if ($bestIdx < 0) {
            // Tidak ada header valid ditemukan: coba pakai baris pertama saja.
            $bestIdx = 0;
            $bestHeaders = $this->normalizeHeaders($allRowValues[0] ?? []);
        }

        $rows = [];
        foreach ($allRowValues as $index => $rowValues) {
            if ($index <= $bestIdx) {
                continue; // Lewati header dan baris-baris di atasnya
            }
            if (empty(array_filter($rowValues, fn ($v) => trim((string) $v) !== ''))) {
                continue; // Baris kosong
            }

            $row = [];
            foreach ($bestHeaders as $pos => $header) {
                $row[$header] = isset($rowValues[$pos]) ? trim((string) $rowValues[$pos]) : null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Konversi tanggal dalam berbagai format (Y-m-d, d/m/Y, d-m-Y) menjadi Y-m-d.
     *
     * @param  string|null $value
     * @return string|null
     */
    protected function normalizeTanggal(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        // Sudah Y-m-d (sebagian tool OpenXML menyimpan t="d" dengan ISO 8601 ber-waktu)
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return substr($value, 0, 10);
        }

        // d/m/Y atau d-m-Y (juga menangani 1 angka d/m)
        if (preg_match('#^(\d{1,2})[/\-](\d{1,2})[/\-](\d{4})$#', $value, $m)) {
            $d = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $mo = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            return "{$m[3]}-{$mo}-{$d}";
        }

        // Serial date Excel: sel berformat Date menyimpan angka (mis. 46052 = 2026-01-01),
        // bukan teks tanggal. Rentang 10000..80000 ≈ tahun 1927..2119.
        if (is_numeric($value)) {
            $serial = (float) $value;
            if ($serial >= 10000 && $serial <= 80000) {
                return date('Y-m-d', (int) round(($serial - 25569) * 86400));
            }
        }

        return $value;
    }

    /**
     * Kembalikan NIP ke bentuk string penuh dari kemungkinan scientific notation
     * (mis. "1.99609E+17") sesuai panjang digit semula.
     *
     * Catatan: Excel hanya menyimpan ~6 digit signifikan pada mode scientific,
     * sehingga rekonstruksi ini bersifat best-effort. Untuk hasil sempurna, format
     * kolom NIP sebagai Text di Excel.
     *
     * @param  string|null $value
     * @return string|null
     */
    protected function normalizeNip(?string $value): ?string
    {
        $value = trim((string) $value, " \t\n\r\0\x0B'\"");
        if ($value === '') {
            return null;
        }

        // Bersihkan pemisah ribuan dan koma ribuan
        $clean = str_replace([',', "'", '"'], '', $value);

        // Scientific notation: "1.99609E+17" / "1,99609E+17"
        if (preg_match('/^(-?[\d.]+)[eE]\+?(\d+)$/', $clean, $m)) {
            $mantissa = $m[1];
            $exp = (int) $m[2];
            $isNegative = str_starts_with($mantissa, '-');
            $mantissa = ltrim($mantissa, '-');

            [$intPart, $fracPart] = array_pad(explode('.', $mantissa, 2), 2, '');
            $sigDigits = $intPart . $fracPart;           // "199609"
            $digitsAfterDecimal = strlen($fracPart);     // 5
            $zerosToAppend = $exp - $digitsAfterDecimal;

            $result = $sigDigits;
            if ($zerosToAppend > 0) {
                $result .= str_repeat('0', $zerosToAppend);
            }
            if ($isNegative) {
                $result = '-' . $result;
            }
            return $result;
        }

        return $value;
    }

    /**
     * Generate email dari nama lengkap dengan menghapus gelar.
     * Contoh: "Budi Antono, S.Kom., M.Kom." → "budi.antono@kpk.go.id"
     *
     * @param string $namaLengkap
     * @param string $domain
     * @return string
     */
    protected function generateEmailDariNama(string $namaLengkap, ?string $currentNip = null, string $domain = 'kpk.go.id'): string
    {
        $nama = trim($namaLengkap);

        // Hapus gelar setelah koma
        $nama = trim(explode(',', $nama, 2)[0]);

        // Hapus suffix seperti S.T., M.Kom., S.Pd. dll
        $nama = preg_replace('/\s+[A-Z]\.[A-Za-z]+\.?\s*$/', '', $nama);

        $words = array_filter(explode(' ', trim($nama)));

        // Ambil maksimal 2 kata pertama (nama depan + nama belakang)
        $words = array_slice($words, 0, 2);

        $slug = Str::lower(implode('.', $words));

        // Bersihkan karakter non-alfanumerik kecuali titik
        $slug = preg_replace('/[^a-z0-9.]/', '', $slug);

        $baseEmail = "{$slug}@{$domain}";
        $email = $baseEmail;

        $existingUser = \App\Models\User::with('pegawai')->where('email', $email)->first();
        if ($existingUser) {
            // Jika email ini milik pegawai ini sendiri atau user orphan tanpa pegawai, pakai saja
            if (($currentNip && $existingUser->pegawai?->nip === $currentNip) || !$existingUser->pegawai) {
                return $email;
            }

            // Jika memang milik pegawai lain, cari suffix numerik yang bebas
            $counter = 2;
            while (true) {
                $candidate = "{$slug}{$counter}@{$domain}";
                $u = \App\Models\User::with('pegawai')->where('email', $candidate)->first();
                if (!$u || ($currentNip && $u->pegawai?->nip === $currentNip) || !$u->pegawai) {
                    return $candidate;
                }
                $counter++;
            }
        }

        return $email;
    }

    /**
     * Check apakah email sudah ada di tabel users.
     */
    protected function emailExists(string $email): bool
    {
        return \App\Models\User::where('email', $email)->exists();
    }

    /**
     * Generate password default: NIP + 5 huruf pertama nama depan (lowercase).
     * Contoh: NIP "199503012025031001", nama "Budi Antono" → "199503012025031001budi"
     *
     * @param string      $nip
     * @param string      $namaLengkap
     * @return string
     */
    protected function generatePasswordDefault(string $nip, string $namaLengkap): string
    {
        $words = array_filter(explode(' ', trim($namaLengkap)));
        $namaDepan = strtolower($words[0] ?? 'user');
        $limaHurufDepan = substr($namaDepan, 0, 5);

        return $nip . $limaHurufDepan;
    }

    /**
     * Tentukan nama jenjang berikutnya berdasarkan progresi BKN.
     *
     * @param string $currentJenjang
     * @return string|null
     */
    protected function getNextJenjangName(string $currentJenjang): ?string
    {
        return match ($currentJenjang) {
            'Ahli Pertama' => 'Ahli Muda',
            'Ahli Muda'    => 'Ahli Madya',
            'Ahli Madya'   => 'Ahli Utama',
            default        => null,
        };
    }

    /**
     * Parse file OpenXML .xlsx secara native tanpa dependensi pihak ketiga.
     *
     * @param string $filePath
     * @return array
     */
    public function parseXlsxFile(string $filePath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            return $this->parseCsvFile($filePath);
        }

        // 1. Baca shared strings
        $sharedStrings = [];
        $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedStringsXml) {
            $xml = @simplexml_load_string($sharedStringsXml);
            if ($xml) {
                foreach ($xml->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string) $si->t;
                    } elseif (isset($si->r)) {
                        $text = '';
                        foreach ($si->r as $r) {
                            $text .= (string) $r->t;
                        }
                        $sharedStrings[] = $text;
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }
        }

        // 2. Baca sheet pertama
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$sheetXml) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (str_starts_with($stat['name'], 'xl/worksheets/sheet') && str_ends_with($stat['name'], '.xml')) {
                    $sheetXml = $zip->getFromIndex($i);
                    break;
                }
            }
        }
        $zip->close();

        if (!$sheetXml) {
            throw new \InvalidArgumentException('Lembar kerja (worksheet) tidak ditemukan di dalam file Excel.');
        }

        $xml = @simplexml_load_string($sheetXml);
        if (!$xml || !isset($xml->sheetData->row)) {
            return [];
        }

        $allRowValues = [];
        foreach ($xml->sheetData->row as $rowNode) {
            $rowValues = [];
            foreach ($rowNode->c as $c) {
                $cellType = (string) $c['t'];
                $val = (string) $c->v;

                if ($cellType === 's') {
                    $idx = (int) $val;
                    $rowValues[] = $sharedStrings[$idx] ?? '';
                } elseif ($cellType === 'inlineStr' && isset($c->is->t)) {
                    $rowValues[] = (string) $c->is->t;
                } else {
                    $rowValues[] = $val;
                }
            }
            $allRowValues[] = $rowValues;
        }

        $rows = $this->buildRowsFromHeads($allRowValues);

        // Normalisasi nilai khusus (NIP, tanggal) agar sesuai format DB
        foreach ($rows as &$row) {
            if (isset($row['nip']) && $row['nip'] !== null) {
                $row['nip'] = $this->normalizeNip($row['nip']);
            }
            if (isset($row['tmt_jabatan']) && $row['tmt_jabatan'] !== null) {
                $row['tmt_jabatan'] = $this->normalizeTanggal($row['tmt_jabatan']);
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Parse file CSV/text menjadi array of associative array baris data.
     *
     * @param UploadedFile|string $file
     * @return array
     */
    public function parseCsvFile($file): array
    {
        $filePath = is_string($file) ? $file : $file->getRealPath();
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new \InvalidArgumentException('File tidak dapat dibaca.');
        }

        $delimiter = ',';

        // Deteksi delimiter (koma atau titik koma)
        $firstLine = fgets($handle);
        if ($firstLine !== false) {
            if (substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
                $delimiter = ';';
            }
            rewind($handle);
        }

        $allRows = [];
        while (($data = fgetcsv($handle, 4096, $delimiter)) !== false) {
            if (empty(array_filter($data, fn ($val) => trim((string) $val) !== ''))) {
                continue;
            }
            $allRows[] = $data;
        }

        fclose($handle);

        $rows = $this->buildRowsFromHeads($allRows);

        // Normalisasi nilai khusus (NIP, tanggal) agar sesuai format DB
        foreach ($rows as &$row) {
            if (isset($row['nip']) && $row['nip'] !== null) {
                $row['nip'] = $this->normalizeNip($row['nip']);
            }
            if (isset($row['tmt_jabatan']) && $row['tmt_jabatan'] !== null) {
                $row['tmt_jabatan'] = $this->normalizeTanggal($row['tmt_jabatan']);
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Hitung distribusi bulan aktif per triwulan berdasarkan TMT jabatan.
     *
     * Auto-cut hanya dalam tahun evaluasi: TMT tahun < tahun evaluasi => full 12
     * bulan (3 per triwulan); TMT tahun == tahun evaluasi => cut dari bulan TMT;
     * TMT tahun masa depan => tidak valid (null).
     *
     * @param string|null $tmt  tanggal TMT (Y-m-d).
     * @param int         $tahun tahun evaluasi.
     * @return array{0:int,1:int,2:int,3:int}|null [tw1, tw2, tw3, tw4] atau null bila TMT absen/tidak valid.
     */
    /**
     * True bila TMT jabatan berada pada tahun setelah tahun evaluasi
     * (pegawai belum aktif sama sekali di tahun tersebut).
     */
    protected function isTmtSetelahTahun(?string $tmt, int $tahun): bool
    {
        $tmt = trim((string) $tmt);
        if ($tmt === '') {
            return false;
        }
        try {
            return (int) Carbon::parse($tmt)->year > $tahun;
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function hitungBulanAktifDariTmt(?string $tmt, int $tahun): ?array
    {
        if (empty($tmt)) {
            return null;
        }

        try {
            $date = \Carbon\Carbon::parse($tmt);
        } catch (\Throwable $e) {
            return null;
        }

        $tmtTahun = (int) $date->year;

        if ($tmtTahun < $tahun) {
            return [3, 3, 3, 3];
        }
        if ($tmtTahun > $tahun) {
            return null;
        }

        $startMonth = (int) $date->month;
        $bulan = [];
        foreach (range(1, 4) as $q) {
            $from = (($q - 1) * 3) + 1; // 1, 4, 7, 10
            $to = $q * 3;               // 3, 6, 9, 12
            if ($startMonth > $to) {
                $bulan[] = 0;
            } elseif ($startMonth <= $from) {
                $bulan[] = 3;
            } else {
                $bulan[] = $to - $startMonth + 1;
            }
        }

        return $bulan;
    }

    /**
     * Lakukan simulasi / preview hasil kalkulasi tanpa menyimpan ke database (Dry Run).
     *
     * @param UploadedFile|string $file
     * @param bool $buatAkun
     * @param int $triwulan 1-3 = import triwulan terpisah (satu kolom PKP),
     *                       4 = mode Tahunan (TW4 sebagai acuan tahunan).
     * @return array
     */
    public function previewImport($file, bool $buatAkun = true, int $triwulan = 4, int $tahun = 0): array
    {
        $triwulan = in_array($triwulan, [1, 2, 3, 4], true) ? $triwulan : 4;
        $tahun = $tahun >= 2020 ? $tahun : (int) date('Y');
        $rawRows = $this->parseFile($file);
        $pangkatMap = MasterPangkatGolongan::with('jenjangJabatan')->get()->keyBy(fn ($item) => strtolower($item->golongan));
        $predikatMap = MasterPredikatKinerja::all()->keyBy(fn ($item) => strtolower($item->nama));
        $jenjangMap = MasterJenjangJabatan::all()->keyBy(fn ($item) => strtolower($item->nama));

        // Pastikan kolom wajib ada di header sebelum diolah lebih lanjut.
        if (empty($rawRows)) {
            throw new \InvalidArgumentException('Tidak ada baris data yang terbaca dari file. Pastikan header berada di baris pertama (atau di baris awal) dan mengikuti template.');
        }
        $first = $rawRows[0];
        $missingRequired = [];
        foreach (['nip', 'golongan'] as $req) {
            if (!array_key_exists($req, $first)) {
                $missingRequired[] = $req;
            }
        }
        if ($missingRequired) {
            throw new \InvalidArgumentException(
                'Kolom wajib ' . implode(', ', $missingRequired) .
                ' tidak ditemukan di header. Pastikan header file mengikuti template (nip, nama_lengkap, golongan, dst) dan berada di baris awal.'
            );
        }

        $previewData = [];
        $totalValid = 0;
        $totalError = 0;
        $countLayakKp = 0;
        $countLayakJenjang = 0;
        $countBelumCukup = 0;

        foreach ($rawRows as $index => $row) {
            $rowNumber = $index + 2; // +1 header, +1 1-based index
            $errors = [];

            // 1. Validasi Kolom Wajib
            if (empty($row['nip'])) {
                $errors[] = 'NIP wajib diisi.';
            }
            if (empty($row['nama_lengkap'])) {
                $errors[] = 'Nama lengkap wajib diisi.';
            }

            $golonganKey = strtolower($row['golongan'] ?? '');
            $pangkat = $pangkatMap->get($golonganKey);
            if (!$pangkat) {
                $errors[] = "Golongan '" . ($row['golongan'] ?? '') . "' tidak valid.";
            }

            // Tahun evaluasi diambil dari selector UI (param), bukan kolom file
            if ($tahun < 2020) {
                $errors[] = 'Tahun evaluasi minimal 2020.';
            }

            if (!empty($errors)) {
                $totalError++;
                $previewData[] = [
                    'baris'     => $rowNumber,
                    'is_valid'  => false,
                    'errors'    => $errors,
                    'raw_data'  => $row,
                ];
                continue;
            }

            // 2. Resolusi Asal Jabatan & Jenjang Tujuan (Jalur Jabatan — PerBKN No. 3/2023)
            $asalJabatan = strtoupper(trim($row['asal_jabatan'] ?? '') ?: 'JABATAN_FUNGSIONAL');

            $jenjangTujuanNama = trim($row['jenjang_jabatan'] ?? '');
            $targetJenjang = null;
            if ($jenjangTujuanNama !== '') {
                $targetJenjang = $jenjangMap->get(strtolower($jenjangTujuanNama));
                if (!$targetJenjang) {
                    $errors[] = "Jenjang jabatan '{$jenjangTujuanNama}' tidak valid.";
                }
            }

            if (!empty($errors)) {
                $totalError++;
                $previewData[] = [
                    'baris'     => $rowNumber,
                    'is_valid'  => false,
                    'errors'    => $errors,
                    'raw_data'  => $row,
                ];
                continue;
            }

            // Jenjang efektif = jenjang tujuan bila diisi, selain itu jenjang golongan.
            $jenjang = $targetJenjang ?: $pangkat->jenjangJabatan;
            $jenjangAsal = $pangkat->jenjangJabatan;
            $koefisienTahunan = (float) $jenjang->koefisien_tahunan;

            // A crossing grade boundary is a promotion to the next functional level.
            $golonganBerikutnya = [
                'iii/a' => 'III/b',
                'iii/b' => 'III/c',
                'iii/c' => 'III/d',
                'iii/d' => 'IV/a',
                'iv/a' => 'IV/b',
                'iv/b' => 'IV/c',
                'iv/c' => 'IV/d',
                'iv/d' => 'IV/e',
            ];
            $nextGolongan = $golonganBerikutnya[strtolower($pangkat->golongan)] ?? null;
            $nextPangkat = $nextGolongan
                ? $pangkatMap->get(strtolower($nextGolongan))
                : null;
            $targetEvaluasiJenjang = $targetJenjang;
            if (
                $nextPangkat
                && $jenjangAsal
                && $nextPangkat->jenjangJabatan
                && $nextPangkat->jenjangJabatan->id !== $jenjangAsal->id
                && (!$targetJenjang || $targetJenjang->id === $jenjangAsal->id)
            ) {
                $targetEvaluasiJenjang = $nextPangkat->jenjangJabatan;
            }

            // Penyesuaian Perpindahan Jabatan (PerBKN No. 3/2023 Lampiran II Angka 3)
            $penyesuaian = $this->hitungKonversi->resolveMismatchPenyesuaian($asalJabatan, $pangkat->golongan, $jenjang);

            // Kasus A: lompatan terlarang (Pelaksana -> selain Ahli Pertama) => baris ditolak.
            if ($penyesuaian['blocked']) {
                $totalError++;
                $previewData[] = [
                    'baris'     => $rowNumber,
                    'is_valid'  => false,
                    'errors'    => [$penyesuaian['block_message']],
                    'raw_data'  => $row,
                ];
                continue;
            }

            // Modal Awal: AK Dasar
            $akDasar = (float) $pangkat->ak_dasar;

            // PAK Pelantikan — Kasus B (penyesuaian perpindahan) => 100 AK, masa kerja diabaikan.
            $mkTahun = (int) ($row['masa_kerja_tahun'] ?? 0);
            $mkBulan = (int) ($row['masa_kerja_bulan'] ?? 0);
            $akPakPelantikan = 0.0;
            if ($penyesuaian['flat']) {
                $akPakPelantikan = 100.00;
            } elseif ($mkTahun > 0 || $mkBulan > 0) {
                $calcPak = $this->hitungKonversi->hitungPakPelantikan($mkTahun, $mkBulan, 1.0, $koefisienTahunan);
                $akPakPelantikan = $calcPak['total_ak_pak'];
            }

            // Saldo Historis
            $akHistoris = (float) ($row['saldo_historis'] ?? 0);

            // TMT jabatan pada tahun setelah tahun evaluasi => baris ditolak secara tegas.
            if ($this->isTmtSetelahTahun($row['tmt_jabatan'] ?? null, $tahun)) {
                $totalError++;
                $previewData[] = [
                    'baris'     => $rowNumber,
                    'is_valid'  => false,
                    'errors'    => ["TMT jabatan ({$row['tmt_jabatan']}) berada setelah tahun evaluasi {$tahun}."],
                    'raw_data'  => $row,
                ];
                continue;
            }

            // Auto-cut bulan aktif murni dari TMT jabatan (kolom twX_bulan dihapus).
            // Tanpa TMT yang valid: tiap triwulan dihitung penuh 3 bulan.
            $bulanAuto = $this->hitungBulanAktifDariTmt($row['tmt_jabatan'] ?? null, $tahun);
            $bulanQuarter = static fn (int $q): int => $bulanAuto[$q - 1] ?? 3;

            // ── Mode Triwulan 1–3: satu kolom PKP untuk quarter terpilih (Formula A) ──
            if ($triwulan !== 4) {
                $q = $triwulan;
                $pName = trim((string) ($row['pkp'] ?? ''));
                if ($pName === '') {
                    $totalError++;
                    $previewData[] = [
                        'baris'     => $rowNumber,
                        'is_valid'  => false,
                        'errors'    => ["Kolom PKP wajib diisi untuk import Triwulan {$q}."],
                        'raw_data'  => $row,
                    ];
                    continue;
                }
                $predikatObj = $predikatMap->get(strtolower($pName));
                if (!$predikatObj) {
                    $totalError++;
                    $previewData[] = [
                        'baris'     => $rowNumber,
                        'is_valid'  => false,
                        'errors'    => ["Predikat '{$pName}' tidak valid. Gunakan: " . implode(', ', $predikatMap->pluck('nama')->all()) . '.'],
                        'raw_data'  => $row,
                    ];
                    continue;
                }

                $persen = (float) $predikatObj->persentase_konversi;
                $qBulan = $bulanQuarter($q);
                $akQ = $qBulan > 0 ? round(($qBulan / 12) * $persen * $koefisienTahunan, 3) : 0.0;

                // Proyeksi disetahunkan (Formula B dengan PKP ini sebagai acuan tahunan)
                $totalBulanSetahun = array_sum($bulanAuto ?? [3, 3, 3, 3]);
                $proyeksi = round(($totalBulanSetahun / 12) * $persen * $koefisienTahunan, 3);

                // Kumulatif parsial: saldo + quarter yang sudah tersimpan di DB
                // (kecuali quarter ini) + quarter baru, lalu cek kelayakan.
                $triwulanTersimpan = [];
                $sumTersimpan = 0.0;
                $sudahTercatat = false;
                $dataSebelumnya = null;
                $pegawaiExisting = Pegawai::where('nip', $row['nip'])->first();
                if ($pegawaiExisting) {
                    $rowsDb = EvaluasiKinerja::with('predikat')
                        ->where('pegawai_id', $pegawaiExisting->id)
                        ->where('tahun', $tahun)->get();
                    foreach ($rowsDb as $ev) {
                        $tq = (int) $ev->triwulan;
                        if ($tq < 1 || $tq > 4) {
                            continue;
                        }
                        $triwulanTersimpan["tw{$tq}"] = [
                            'predikat'     => $ev->predikat?->nama ?? '-',
                            'jumlah_bulan' => (int) $ev->jumlah_bulan,
                            'angka_kredit' => (float) $ev->angka_kredit,
                        ];
                        if ($tq === $q) {
                            // Quarter yang SAMA sudah pernah di-upload: simpan
                            // nilai lamanya untuk info & deteksi perubahan.
                            $sudahTercatat = true;
                            $dataSebelumnya = $triwulanTersimpan["tw{$tq}"];
                        } else {
                            $sumTersimpan += (float) $ev->angka_kredit;
                        }
                    }
                }

                // Apakah data lama pada quarter ini berbeda dari file baru.
                $berbeda = $sudahTercatat && (
                    strcasecmp($dataSebelumnya['predikat'] ?? '', $predikatObj->nama) !== 0
                    || (int) ($dataSebelumnya['jumlah_bulan'] ?? 0) !== $qBulan
                    || abs((float) ($dataSebelumnya['angka_kredit'] ?? 0) - $akQ) > 0.0005
                );

                $akParsial = round($akDasar + $akPakPelantikan + $akHistoris + $sumTersimpan + $akQ, 3);
                $kelayakanParsial = $this->carryOverService->evaluasiKelayakan(
                    new Pegawai(['pangkat_golongan_id' => $pangkat->id]),
                    $akParsial,
                    $targetEvaluasiJenjang
                );

                if ($kelayakanParsial['status'] === 'LAYAK_PANGKAT') {
                    $countLayakKp++;
                } elseif ($kelayakanParsial['status'] === 'LAYAK_JENJANG') {
                    $countLayakJenjang++;
                } else {
                    $countBelumCukup++;
                }

                $triwulanTersimpan["tw{$q}"] = [
                    'predikat'     => $predikatObj->nama,
                    'jumlah_bulan' => $qBulan,
                    'angka_kredit' => $akQ,
                ];

                $totalValid++;
                $previewData[] = [
                    'baris'                  => $rowNumber,
                    'is_valid'               => true,
                    'triwulan_mode'          => true,
                    'triwulan_ke'            => $q,
                    'nip'                    => $row['nip'],
                    'nama_lengkap'           => $row['nama_lengkap'],
                    'golongan'               => $pangkat->golongan,
                    'asal_jabatan'           => $asalJabatan,
                    'jenjang'                => $jenjang->nama,
                    'tahun'                  => $tahun,
                    'ak_dasar'               => $akDasar,
                    'ak_pak_pelantikan'      => $akPakPelantikan,
                    'ak_historis'            => $akHistoris,
                    'pkp'                    => $predikatObj->nama,
                    'jumlah_bulan'           => $qBulan,
                    'ak_triwulan'            => $akQ,
                    'sudah_ada'              => $sudahTercatat,
                    'berbeda'                => $berbeda,
                    'data_sebelumnya'        => $dataSebelumnya,
                    'total_bulan_aktif'      => $totalBulanSetahun,
                    'proyeksi_disetahunkan'  => $proyeksi,
                    'ak_parsial'             => $akParsial,
                    'metode_kalkulasi'       => 'FORMULA_A_PERIODIK',
                    'kelayakan'              => [
                        'status'         => $kelayakanParsial['status'],
                        'badge_label'    => $kelayakanParsial['badge_label'],
                        'badge_color'    => $kelayakanParsial['badge_color'],
                        'carry_over'     => $kelayakanParsial['carry_over'],
                        'kurang_ak'      => $kelayakanParsial['kurang_ak'],
                        'catatan'        => $kelayakanParsial['catatan'],
                        'target_kp'      => $kelayakanParsial['target_kp'],
                        'target_jenjang' => $kelayakanParsial['target_jenjang'],
                        'jenis_target'   => $kelayakanParsial['jenis_target'],
                        'next_jenjang'   => $this->getNextJenjangName($jenjang->nama),
                    ],
                    'triwulan'               => $triwulanTersimpan,
                    'raw_data'               => $row,
                ];
                continue;
            }

            // ── Mode Tahunan (TW4 acuan): TW1–TW3 dihitung per triwulan, TW4 acuan tahunan ──
            $triwulanData = [];
            $totalBulanAktif = 0;
            $predikatTw4Persen = 1.0;
            $predikatTw4Nama = 'Baik';

            // Predikat tahunan (kolom tunggal PKP) menjadi acuan TW1–TW3 saat rincian
            // opsional pkp_twX kosong/tidak terisi → total AK ikut disetahunkan penuh.
            $predikatTahunanNama = $row['pkp'] ?? $row['pkp_tw4'] ?? null;
            $predikatTahunanObj = $predikatTahunanNama
                ? $predikatMap->get(strtolower(trim((string) $predikatTahunanNama)))
                : null;

            foreach (range(1, 4) as $q) {
                // TW4 memakai kolom tunggal PKP; TW1–TW3 dari rincian opsional pkp_twX,
                // dengan fallback ke predikat tahunan (PKP).
                $pName = $q === 4
                    ? ($row['pkp'] ?? $row['pkp_tw4'] ?? null)
                    : ($row["pkp_tw{$q}"] ?? null);
                $predikatObj = $pName ? $predikatMap->get(strtolower(trim((string) $pName))) : null;
                if (!$predikatObj && $q !== 4) {
                    $predikatObj = $predikatTahunanObj;
                }
                $pBulan = $predikatObj ? $bulanQuarter($q) : 0;

                $akQ = 0.0;
                if ($predikatObj && $pBulan > 0) {
                    $persen = (float) $predikatObj->persentase_konversi;
                    $akQ = round(($pBulan / 12) * $persen * $koefisienTahunan, 3);
                    $totalBulanAktif += $pBulan;

                    if ($q === 4) {
                        $predikatTw4Persen = $persen;
                        $predikatTw4Nama = $predikatObj->nama;
                    }
                }

                $triwulanData["tw{$q}"] = [
                    'predikat'     => $predikatObj?->nama ?? '-',
                    'jumlah_bulan' => $pBulan,
                    'angka_kredit' => $akQ,
                ];
            }

            // Booster ijazah TIDAK diproses lewat import.
            // Klaim pendidikan hanya melalui alur verifikasi Pengajuan Pendidikan.
            $akBooster = 0.0;

            // Jika tanpa TMT dan tw4 tidak diisi, ambil default 12 bulan
            if ($bulanAuto === null && $totalBulanAktif === 0) {
                $totalBulanAktif = 12;
            }

            // ── Evaluasi Kelayakan TW1 s.d. TW3 (Sebelum Tutup Tahun) ────────
            $sumAkPeriodikTw1_3 = (float) $triwulanData['tw1']['angka_kredit'] +
                                  (float) $triwulanData['tw2']['angka_kredit'] +
                                  (float) $triwulanData['tw3']['angka_kredit'];
            $sumAkPeriodikFull  = $sumAkPeriodikTw1_3 + (float) $triwulanData['tw4']['angka_kredit'];

            $akKumulatifTw3 = round($akDasar + $akPakPelantikan + $akHistoris + $sumAkPeriodikTw1_3 + $akBooster, 3);
            $kelayakanTw3 = $this->carryOverService->evaluasiKelayakan(
                new Pegawai(['pangkat_golongan_id' => $pangkat->id]),
                $akKumulatifTw3,
                $targetEvaluasiJenjang
            );

            // ── Penentuan Metode Kalkulasi AK Baru Akhir Tahun ────────────────
            // Aturan Regulasi (PerBKN No. 3/2023):
            // 1. Jika pada TW1-TW3 pegawai SUDAH MENCAPAI STATUS LAYAK (KP / KJ),
            //    maka AK Baru dihitung murni Akumulasi Periodik Riil (Formula A) agar
            //    tidak meratakan mundur / mendegradasi capaian kenaikan pangkat TW3.
            // 2. Jika BELUM LAYAK pada TW3, gunakan Formula B (Penyetahunan / Acuan Tahunan via TW4).
            $metodeKalkulasi = 'FORMULA_B_TAHUNAN';
            if ($kelayakanTw3['status'] === 'LAYAK_PANGKAT' || $kelayakanTw3['status'] === 'LAYAK_JENJANG') {
                $akBaru = round($sumAkPeriodikFull, 3);
                $metodeKalkulasi = 'FORMULA_A_PERIODIK';
            } else {
                // Formula B (Predikat Tahunan): penyetahunan penuh tahun berjalan.
                $akBaru = round(($totalBulanAktif / 12) * $predikatTw4Persen * $koefisienTahunan, 3);

                // Rekonsiliasi: bagi $akBaru proporsional per triwulan (round 3, TW4
                // penyerap sisa) agar jumlah TW1–TW4 selalu sama persis dengan Total AK.
                $akTerbagi = 0.0;
                foreach (range(1, 3) as $q) {
                    $b = (int) $triwulanData["tw{$q}"]['jumlah_bulan'];
                    if ($b > 0) {
                        $triwulanData["tw{$q}"]['angka_kredit'] = round($akBaru * ($b / $totalBulanAktif), 3);
                        $akTerbagi += $triwulanData["tw{$q}"]['angka_kredit'];
                    } else {
                        $triwulanData["tw{$q}"]['angka_kredit'] = 0.0;
                    }
                }
                $triwulanData['tw4']['angka_kredit'] = (int) $triwulanData['tw4']['jumlah_bulan'] > 0
                    ? round($akBaru - $akTerbagi, 3)
                    : 0.0;
            }

            // Total AK Kumulatif Akhir
            $akKumulatif = round($akDasar + $akPakPelantikan + $akHistoris + $akBaru + $akBooster, 3);

            // Evaluasi Badge Kelayakan
            $kelayakan = $this->carryOverService->evaluasiKelayakan(
                new Pegawai(['pangkat_golongan_id' => $pangkat->id]),
                $akKumulatif,
                $targetEvaluasiJenjang
            );

            if ($kelayakan['status'] === 'LAYAK_PANGKAT') {
                $countLayakKp++;
            } elseif ($kelayakan['status'] === 'LAYAK_JENJANG') {
                $countLayakJenjang++;
            } else {
                $countBelumCukup++;
            }

            // Deteksi re-upload Tahunan: sudah ada data evaluasi / penetapan tahun ini.
            $sudahAdaGanda = false;
            $pegawaiLama = Pegawai::where('nip', $row['nip'])->first();
            if ($pegawaiLama) {
                $sudahAdaGanda = EvaluasiKinerja::where('pegawai_id', $pegawaiLama->id)
                        ->where('tahun', $tahun)->exists()
                    || PenetapanAK::where('pegawai_id', $pegawaiLama->id)
                        ->where('tahun', $tahun)->exists();
            }

            $totalValid++;
            $previewData[] = [
                'baris'             => $rowNumber,
                'is_valid'          => true,
                'nip'               => $row['nip'],
                'nama_lengkap'      => $row['nama_lengkap'],
                'golongan'          => $pangkat->golongan,
                'asal_jabatan'      => $asalJabatan,
                'jenjang'           => $jenjang->nama,
                'jenjang_jabatan_target_id' => $targetJenjang?->id ?? null,
                'penyesuaian_khusus'=> $penyesuaian['flat'] ? $penyesuaian['note'] : null,
                'tahun'             => $tahun,
                'ak_dasar'          => $akDasar,
                'ak_pak_pelantikan' => $akPakPelantikan,
                'ak_historis'       => $akHistoris,
                'sudah_ada'         => $sudahAdaGanda,
                'berbeda'           => $sudahAdaGanda,
                'total_bulan_aktif' => $totalBulanAktif,
                'predikat_tw4'      => $predikatTw4Nama,
                'metode_kalkulasi'  => $metodeKalkulasi,
                'ak_baru_tahunan'   => $akBaru,
                'ak_booster'        => $akBooster,
                'ak_kumulatif'      => $akKumulatif,
                'kelayakan'         => [
                    'status'         => $kelayakan['status'],
                    'badge_label'    => $kelayakan['badge_label'],
                    'badge_color'    => $kelayakan['badge_color'],
                    'carry_over'     => $kelayakan['carry_over'],
                    'kurang_ak'      => $kelayakan['kurang_ak'],
                    'catatan'        => $kelayakan['catatan'],
                    'target_kp'      => $kelayakan['target_kp'],
                    'target_jenjang' => $kelayakan['target_jenjang'],
                    'jenis_target'  => $kelayakan['jenis_target'],
                    'next_jenjang'   => $this->getNextJenjangName($jenjang->nama),
                ],
                'triwulan'          => $triwulanData,
                'raw_data'          => $row,
            ];
        }

        return [
            'total_baris'      => count($rawRows),
            'total_valid'      => $totalValid,
            'total_error'      => $totalError,
            'ringkasan_badge'  => [
                'layak_pangkat' => $countLayakKp,
                'layak_jenjang' => $countLayakJenjang,
                'belum_cukup'   => $countBelumCukup,
            ],
            'data'             => $previewData,
        ];
    }

    /**
     * Eksekusi import massal & auto-konversi ke database secara atomik.
     *
     * Mode Triwulan 1–3 hanya menyimpan baris evaluasi quarter terpilih
     * (tanpa finalisasi PenetapanAK / carry-over / booster / penguncian).
     * Mode Tahunan (4) menjalankan alur finalisasi penuh seperti sebelumnya.
     *
     * @param UploadedFile|string $file
     * @param User $admin
     * @param int $triwulan
     * @return array
     */
    public function executeImport($file, User $admin, bool $buatAkun = true, int $triwulan = 4, int $tahun = 0): array
    {
        $triwulan = in_array($triwulan, [1, 2, 3, 4], true) ? $triwulan : 4;
        $tahun = $tahun >= 2020 ? $tahun : (int) date('Y');
        $preview = $this->previewImport($file, $buatAkun, $triwulan, $tahun);
        if ($preview['total_error'] > 0 && $preview['total_valid'] === 0) {
            throw new \InvalidArgumentException('Seluruh baris file tidak valid. Periksa format data Anda.');
        }

        $pangkatMap = MasterPangkatGolongan::with('jenjangJabatan')->get()->keyBy(fn ($item) => strtolower($item->golongan));
        $predikatMap = MasterPredikatKinerja::all()->keyBy(fn ($item) => strtolower($item->nama));

        $importedPegawai = [];

        $labelTriwulan = $triwulan === 4 ? 'Tahunan (TW4)' : "Triwulan {$triwulan}";

        DB::transaction(function () use ($preview, $pangkatMap, $predikatMap, $admin, $buatAkun, $labelTriwulan, &$importedPegawai) {
            foreach ($preview['data'] as $item) {
                if (!$item['is_valid']) {
                    continue;
                }

                $golonganKey = strtolower($item['golongan']);
                $pangkat = $pangkatMap->get($golonganKey);

                // 1. Buat / Update User Login
                $existingPegawai = Pegawai::with('user')->where('nip', $item['nip'])->first();
                if ($existingPegawai && $existingPegawai->user) {
                    $user = $existingPegawai->user;
                } else {
                    if ($buatAkun) {
                        // Prioritaskan email dari file; fallback generate dari nama
                        $email = $item['raw_data']['email'] ?? $this->generateEmailDariNama($item['nama_lengkap'], $item['nip']);
                        $password = $this->generatePasswordDefault($item['nip'], $item['nama_lengkap']);
                    } else {
                        // Mode manual: pakai email dari file (wajib), password default
                        $email = $item['raw_data']['email'] ?? (Str::slug($item['nama_lengkap'], '.') . '@kpk.go.id');
                        $password = 'password123';
                    }

                    $user = User::firstOrCreate(
                        ['email' => $email],
                        [
                            'name'     => $item['nama_lengkap'],
                            'password' => Hash::make($password),
                            'role'     => 'PEGAWAI',
                        ]
                    );
                }

                // 2. Buat / Update Data Pegawai
                $pegawai = Pegawai::updateOrCreate(
                    ['nip' => $item['nip']],
                    [
                        'user_id'             => $user->id,
                        'nama_lengkap'        => $item['nama_lengkap'],
                        'pangkat_golongan_id' => $pangkat->id,
                        'asal_jabatan'        => $item['asal_jabatan'] ?? 'JABATAN_FUNGSIONAL',
                        'jenjang_jabatan_id'  => $item['jenjang_jabatan_target_id'] ?? null,
                        'pendidikan_terakhir' => $item['raw_data']['pendidikan_terakhir'] ?? 'S1',
                        'tmt_jabatan'         => !empty($item['raw_data']['tmt_jabatan']) ? $item['raw_data']['tmt_jabatan'] : null,
                    ]
                );

                $tahun = $item['tahun'];

                // Year-Lock guard: jika tahun penetapan AK pegawai ini terkunci,
                // lewati baris (data tidak boleh diubah lewat import).
                $tahunTerkunci = (bool) PenetapanAK::where('pegawai_id', $pegawai->id)
                    ->where('tahun', $tahun)
                    ->value('is_locked');
                if ($tahunTerkunci) {
                    $importedPegawai[] = [
                        'nip'          => $pegawai->nip,
                        'nama_lengkap' => $pegawai->nama_lengkap,
                        'tahun'        => $tahun,
                        'ak_triwulan'  => 0,
                        'badge_label'  => 'SKIPPED_TERKUNCI',
                        'catatan'      => "Tahun {$tahun} sudah terkunci.",
                    ];
                    continue;
                }

                // Mode Triwulan: simpan hanya baris quarter terpilih (tanpa penguncian,
                // tanpa finalisasi PenetapanAK / carry-over / booster / notifikasi).
                if (($item['triwulan_mode'] ?? false) === true) {
                    $q = (int) ($item['triwulan_ke'] ?? $triwulan);
                    $predObj = $predikatMap->get(strtolower($item['pkp'] ?? ''));
                    if ($predObj) {
                        EvaluasiKinerja::updateOrCreate(
                            [
                                'pegawai_id' => $pegawai->id,
                                'tahun'      => $tahun,
                                'triwulan'   => $q,
                            ],
                            [
                                'periode_bulan' => $q * 3,
                                'jumlah_bulan'  => $item['jumlah_bulan'],
                                'predikat_id'   => $predObj->id,
                                'angka_kredit'  => $item['ak_triwulan'],
                            ]
                        );
                    }

                    $importedPegawai[] = [
                        'nip'          => $pegawai->nip,
                        'nama_lengkap' => $pegawai->nama_lengkap,
                        'triwulan'     => $q,
                        'ak_triwulan'  => $item['ak_triwulan'],
                        'badge_label'  => $item['kelayakan']['badge_label'],
                    ];
                    continue;
                }

                // 3. Simpan Evaluasi Kinerja TW1 – TW4 (mode Tahunan)
                foreach (range(1, 4) as $q) {
                    $twInfo = $item['triwulan']["tw{$q}"];
                    $predName = $twInfo['predikat'];
                    $pBulan = $twInfo['jumlah_bulan'];

                    if ($predName !== '-' && $pBulan > 0) {
                        $predObj = $predikatMap->get(strtolower($predName));
                        if ($predObj) {
                            EvaluasiKinerja::updateOrCreate(
                                [
                                    'pegawai_id' => $pegawai->id,
                                    'tahun'      => $tahun,
                                    'triwulan'   => $q,
                                ],
                                [
                                    'periode_bulan' => $q * 3,
                                    'jumlah_bulan'  => $pBulan,
                                    'predikat_id'   => $predObj->id,
                                    'angka_kredit'  => $twInfo['angka_kredit'],
                                ]
                            );
                        }
                    }
                }

                // 4. Booster ijazah tidak diproses lewat import
                // (hanya via verifikasi Pengajuan Pendidikan).

                // 5. Simpan & Finalisasi PenetapanAK Tahun Ini
                $kelayakan = $item['kelayakan'];
                $penetapan = PenetapanAK::updateOrCreate(
                    [
                        'pegawai_id' => $pegawai->id,
                        'tahun'      => $tahun,
                    ],
                    [
                        'ak_dasar'          => $item['ak_dasar'],
                        'ak_pak_pelantikan' => $item['ak_pak_pelantikan'],
                        'ak_historis'       => $item['ak_historis'],
                        'ak_lama'           => 0,
                        'ak_baru'           => $item['ak_baru_tahunan'],
                        'ak_booster'        => $item['ak_booster'],
                        'ak_carry_over'     => 0,
                        'ak_kumulatif'      => $item['ak_kumulatif'],
                        'status_kelayakan'  => $kelayakan['status'],
                        'catatan_kelayakan' => $kelayakan['catatan'],
                        'is_final'          => true,
                    ]
                );

                // 6. Siapkan Deposit Carry-Over ke Tahun Berikutnya (Tahun + 1)
                $tahunBerikutnya = $tahun + 1;
                PenetapanAK::updateOrCreate(
                    [
                        'pegawai_id' => $pegawai->id,
                        'tahun'      => $tahunBerikutnya,
                    ],
                    [
                        'ak_dasar'          => $item['ak_dasar'],
                        'ak_pak_pelantikan' => 0,
                        'ak_historis'       => 0,
                        'ak_lama'           => $kelayakan['carry_over'],
                        'ak_carry_over'     => $kelayakan['carry_over'],
                        'ak_baru'           => 0,
                        'ak_booster'        => 0,
                        'ak_kumulatif'      => $kelayakan['carry_over'],
                        'status_kelayakan'  => 'BELUM_CUKUP',
                        'is_final'          => false,
                    ]
                );

                // 7. Kirim Notifikasi ke Pegawai
                $tipeNotif = $kelayakan['status'] === 'BELUM_CUKUP' ? 'INFO' : 'SUCCESS';
                Notifikasi::create([
                    'user_id' => $user->id,
                    'judul'   => "Konversi Angka Kredit Tahun {$tahun} Selesai - [{$kelayakan['badge_label']}]",
                    'pesan'   => "Data kinerja dan penetapan AK Anda tahun {$tahun} telah berhasil dikonversi. Total AK Kumulatif: {$item['ak_kumulatif']} AK. Status: {$kelayakan['badge_label']}.",
                    'tipe'    => $tipeNotif,
                ]);

                $importedPegawai[] = [
                    'nip'          => $pegawai->nip,
                    'nama_lengkap' => $pegawai->nama_lengkap,
                    'ak_kumulatif' => $item['ak_kumulatif'],
                    'badge_label'  => $kelayakan['badge_label'],
                    'carry_over'   => $kelayakan['carry_over'],
                ];
            }

            // 8. Audit Trail
            $this->auditTrail->log(
                'IMPORT_KONVERSI',
                'BULK_IMPORT',
                "Admin {$admin->name} berhasil mengimpor & mengonversi " . count($importedPegawai) . " data pegawai ({$labelTriwulan})."
            );
        });

        return [
            'message'          => "Import dan konversi kinerja massal ({$labelTriwulan}) berhasil dieksekusi.",
            'triwulan'         => $triwulan,
            'total_diproses'   => count($importedPegawai),
            'ringkasan_badge'  => $preview['ringkasan_badge'],
            'data_pegawai'     => $importedPegawai,
        ];
    }
}
