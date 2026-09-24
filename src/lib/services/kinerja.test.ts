import { afterEach, describe, expect, it } from "vitest";
import { query } from "../db";
import { getPegawai } from "./pegawai";
import { MASTER_FIXTURE } from "../formulas/master.fixture";
import {
  getContextKinerja,
  hapusKinerja,
  hitungBulanPerTw,
  perbaruiKinerja,
  simpanKinerja,
  simulasiKinerja,
  tetapkanKinerjaTahunan,
} from "./kinerja";
import type { Golongan, Pegawai, Predikat } from "@/types";

const TAHUN = 2026;

const ids = new Set<string>();

async function buatPegawai(
  golongan: Golongan = "III/a",
  tmtJabatan: string | null = null,
): Promise<Pegawai> {
  const { rows } = await query<{ id: string }>(
    `INSERT INTO pegawai (nip, nama_lengkap, golongan, asal_jabatan, tmt_jabatan)
     VALUES (substring(md5(random()::text || clock_timestamp()::text), 1, 20), 'Pegawai Tes', $1, 'JABATAN_FUNGSIONAL', $2)
     RETURNING id`,
    [golongan, tmtJabatan],
  );
  ids.add(rows[0].id);
  return getPegawai(rows[0].id);
}

afterEach(async () => {
  for (const id of ids) await query("DELETE FROM pegawai WHERE id=$1", [id]);
  ids.clear();
});

function simpan(pegawaiId: string, triwulan: 1 | 2 | 3 | 4, predikat: Predikat, jumlahBulan?: number) {
  return simpanKinerja({
    pegawaiId,
    tahun: TAHUN,
    triwulan,
    predikat,
    jumlahBulan,
  });
}

function simpanPada(pegawaiId: string, tahun: number, triwulan: 1 | 2 | 3 | 4, predikat: Predikat) {
  return simpanKinerja({ pegawaiId, tahun, triwulan, predikat });
}

describe.skipIf(!process.env.DATABASE_URL)(
  "kinerja service (integrasi DB — butuh DATABASE_URL)",
  () => {
    it("hitungBulanPerTw: TMT di tengah tahun memotong TW, TMT tahun lalu = penuh, null = null", () => {
      expect(hitungBulanPerTw("2024-03-01", 2024)).toEqual({ 1: 1, 2: 3, 3: 3, 4: 3 });
      expect(hitungBulanPerTw("2020-01-01", 2024)).toEqual({ 1: 3, 2: 3, 3: 3, 4: 3 });
      expect(hitungBulanPerTw("2024-07-15", 2024)).toEqual({ 1: 3, 2: 3, 3: 3, 4: 3 });
      expect(hitungBulanPerTw(null, 2024)).toBeNull();
    });

    it("auto-cut TMT: TW1 default jumlah_bulan = sisa bulan di TW (TMT 2024-03-01 -> 1)", async () => {
      const pegawai = await buatPegawai("III/a", "2024-03-01");
      const hasil = await simpanPada(pegawai.id, 2024, 1, "BAIK");

      expect(hasil.dataKinerja.jumlahBulan).toBe(1);
      expect(hasil.dataKinerja.angkaKredit).toBeCloseTo(1.042, 3);
    });

    it("draft TW1-3 (golongan III/b): akKumulatif = ak_dasar + sum, is_final false", async () => {
      const pegawai = await buatPegawai("III/b");

      const tw1 = await simpan(pegawai.id, 1, "BAIK");
      expect(tw1.penetapan.akKumulatif).toBeCloseTo(53.125, 3);
      expect(tw1.penetapan.statusKelayakan).toBe("BELUM_CUKUP");
      expect(tw1.penetapan.isFinal).toBe(false);

      await simpan(pegawai.id, 2, "BAIK");
      const tw3 = await simpan(pegawai.id, 3, "BAIK");

      expect(tw3.penetapan.akBaru).toBeCloseTo(9.375, 3);
      expect(tw3.penetapan.akKumulatif).toBeCloseTo(59.375, 3);
      expect(tw3.penetapan.sisaAkDeposit).toBe(0);
      expect(tw3.penetapan.isFinal).toBe(false);
    });

    it("re-save idempoten: TW yang sama di-simpan ulang, bukan baris ganda", async () => {
      const pegawai = await buatPegawai("III/b");

      await simpan(pegawai.id, 1, "BAIK");
      const ulang = await simpan(pegawai.id, 1, "SANGAT_BAIK");

      expect(ulang.dataKinerja.angkaKredit).toBeCloseTo(4.688, 3);
      expect(ulang.penetapan.akKumulatif).toBeCloseTo(54.688, 3);

      const { rows } = await query<{ jml: string }>(
        "SELECT count(*)::text AS jml FROM data_kinerja WHERE pegawai_id=$1 AND tahun=$2 AND triwulan=1",
        [pegawai.id, TAHUN],
      );
      expect(Number(rows[0].jml)).toBe(1);
    });

    it("sudah layak TW3 -> Formula A: akBaru = jumlah semua TW, status final layak jenjang", async () => {
      const pegawai = await buatPegawai("III/b");
      await query(
        "INSERT INTO akumulasi_angka_kredit (pegawai_id, tahun, ak_dasar, sisa_ak_deposit) VALUES ($1, $2, $3, $4)",
        [pegawai.id, TAHUN - 1, 0, 90],
      );

      for (const tw of [1, 2, 3] as const) {
        await simpan(pegawai.id, tw, "SANGAT_BAIK", 3);
      }

      const final = await tetapkanKinerjaTahunan({
        pegawaiId: pegawai.id,
        tahun: TAHUN,
        predikat: "SANGAT_BAIK",
        jumlahBulan: 12,
      });

      expect(final.dataKinerja.angkaKredit).toBeCloseTo(18.75, 3);
      expect(final.penetapan.akBaru).toBeCloseTo(32.814, 3);
      expect(final.penetapan.akKumulatif).toBeCloseTo(122.814, 3);
      expect(final.penetapan.statusKelayakan).toBe("LAYAK_JENJANG");
      expect(final.penetapan.sisaAkDeposit).toBe(0);
      expect(final.penetapan.isFinal).toBe(true);
      expect(final.kelayakan?.carryOver).toBe(0);
    });

    it("belum layak TW3 -> Formula B (setahunkan): nilai TW1-3 tidak ikut, deposit = ak", async () => {
      const pegawai = await buatPegawai("III/a");

      for (const tw of [1, 2, 3] as const) {
        await simpan(pegawai.id, tw, "BAIK", 3);
      }
      const final = await tetapkanKinerjaTahunan({
        pegawaiId: pegawai.id,
        tahun: TAHUN,
        predikat: "BAIK",
        jumlahBulan: 12,
      });

      expect(final.penetapan.akBaru).toBeCloseTo(12.5, 3);
      expect(final.penetapan.akKumulatif).toBeCloseTo(12.5, 3);
      expect(final.penetapan.statusKelayakan).toBe("BELUM_CUKUP");
      expect(final.penetapan.sisaAkDeposit).toBeCloseTo(12.5, 3);
      expect(final.penetapan.isFinal).toBe(true);
      expect(final.kelayakan?.kurangAk).toBeCloseTo(50 - 12.5, 3);
    });

    it("guard: periode terkunci menolak simpan/perbarui berikutnya", async () => {
      const pegawai = await buatPegawai("III/a");
      await simpan(pegawai.id, 1, "BAIK");
      await query("UPDATE akumulasi_angka_kredit SET is_locked=true WHERE pegawai_id=$1 AND tahun=$2", [
        pegawai.id,
        TAHUN,
      ]);

      await expect(simpan(pegawai.id, 2, "BAIK")).rejects.toThrow(/dikunci/);
    });

    it("perbaruiKinerja: ganti predikat/jumlah_bulan menghitung ulang angka & kumulatif", async () => {
      const pegawai = await buatPegawai("III/a");
      const awal = await simpan(pegawai.id, 1, "BAIK", 3);
      expect(awal.penetapan.akKumulatif).toBeCloseTo(3.125, 3);

      const ubah = await perbaruiKinerja(awal.dataKinerja.id, {
        predikat: "SANGAT_BAIK",
        jumlahBulan: 6,
      });

      expect(ubah.dataKinerja.angkaKredit).toBeCloseTo(9.375, 3);
      expect(ubah.penetapan.akKumulatif).toBeCloseTo(9.375, 3);
      expect(ubah.penetapan.statusKelayakan).toBe("BELUM_CUKUP");
    });

    it("hapus TW4: tahun kembali draft (is_final false) dan kumulatif = hanya TW1-3", async () => {
      const pegawai = await buatPegawai("III/a");
      await simpan(pegawai.id, 1, "BAIK", 3);
      const final = await tetapkanKinerjaTahunan({
        pegawaiId: pegawai.id,
        tahun: TAHUN,
        predikat: "BAIK",
        jumlahBulan: 12,
      });
      expect(final.penetapan.isFinal).toBe(true);

      const hasil = await hapusKinerja(final.dataKinerja.id);

      expect(hasil.penetapan.isFinal).toBe(false);
      expect(hasil.penetapan.akKumulatif).toBeCloseTo(3.125, 3);
      expect(hasil.penetapan.isLocked).toBe(false);
    });

    it("simulasi preview & context (saldo awal, tw aktif, bulanPerTw, koefisien)", async () => {
      const pegawai = await buatPegawai("III/a", "2024-03-01");

      const preview = await simulasiKinerja({
        pegawaiId: pegawai.id,
        tahun: 2024,
        triwulan: 1,
        predikat: "BAIK",
      });
      expect(preview.tipe).toBe("periodik");
      expect(preview.bulan).toBe(1);
      expect(preview.angkaKredit).toBeCloseTo(1.042, 3);

      const simpanan = await simpanKinerja({
        pegawaiId: pegawai.id,
        tahun: 2024,
        triwulan: 1,
        predikat: "BAIK",
      });

      const ctx = await getContextKinerja(pegawai.id, 2024);
      expect(ctx.saldoAwal).toBe(0);
      expect(ctx.twAktif).toBe(2);
      expect(ctx.bulanPerTw).toEqual({ 1: 1, 2: 3, 3: 3, 4: 3 });
      expect(ctx.evaluasi).toHaveLength(1);
      expect(ctx.evaluasi[0].angkaKredit).toBeCloseTo(simpanan.dataKinerja.angkaKredit, 3);
      expect(ctx.pegawai.jenjang).toBe("AHLI_PERTAMA");
      expect(ctx.pegawai.koefisienTahunan).toBe(MASTER_FIXTURE.koefisien.AHLI_PERTAMA);
      expect(ctx.penetapanIsFinal).toBe(false);
    });
  },
);