import { query } from "../db";
import type {
  AsalJabatan,
  Golongan,
  Jenjang,
  MasterKonversi,
  Predikat,
} from "@/types";

interface JenjangRow {
  kode: Jenjang;
  koefisien_tahunan: string;
  target_ak_kenaikan_jenjang: string;
}

interface GolonganRow {
  kode: Golongan;
  jenjang_kode: Jenjang;
  ak_dasar: string;
  kebutuhan_ak_kenaikan: string;
}

interface PredikatRow {
  kode: Predikat;
  persentase_konversi: string;
}

interface AsalJabatanRow {
  kode: AsalJabatan;
  jenjang_tujuan: Jenjang | null;
}

export async function getMasterKonversi(): Promise<MasterKonversi> {
  const [masterJenjang, masterGolongan, masterPredikat, masterAsalJabatan] =
    await Promise.all([
      query<JenjangRow>(
        "SELECT kode, koefisien_tahunan, target_ak_kenaikan_jenjang FROM jenjang ORDER BY kode",
      ),
      query<GolonganRow>(
        "SELECT kode, jenjang_kode, ak_dasar, kebutuhan_ak_kenaikan FROM golongan ORDER BY kode",
      ),
      query<PredikatRow>("SELECT kode, persentase_konversi FROM predikat"),
      query<AsalJabatanRow>("SELECT kode, jenjang_tujuan FROM asal_jabatan"),
    ]);

  const koefisien = {} as Record<Jenjang, number>;
  const kebutuhanJenjang = {} as Record<Jenjang, number>;
  for (const j of masterJenjang.rows) {
    koefisien[j.kode] = Number(j.koefisien_tahunan);
    kebutuhanJenjang[j.kode] = Number(j.target_ak_kenaikan_jenjang);
  }

  const golonganPerJenjang = {} as Record<Jenjang, Golongan[]>;
  const golonganJenjang = {} as Record<Golongan, Jenjang>;
  const kebutuhanPangkat = {} as Record<Golongan, number>;
  const akDasar = {} as Record<Golongan, number>;
  for (const g of masterGolongan.rows) {
    (golonganPerJenjang[g.jenjang_kode] ??= []).push(g.kode);
    golonganJenjang[g.kode] = g.jenjang_kode;
    kebutuhanPangkat[g.kode] = Number(g.kebutuhan_ak_kenaikan);
    akDasar[g.kode] = Number(g.ak_dasar);
  }

  const persentase = {} as Record<Predikat, number>;
  for (const p of masterPredikat.rows)
    persentase[p.kode] = Number(p.persentase_konversi);

  const jenjangPerJabatan = {} as Record<AsalJabatan, Jenjang | null>;
  for (const a of masterAsalJabatan.rows)
    jenjangPerJabatan[a.kode] = a.jenjang_tujuan;

  return {
    koefisien,
    kebutuhanJenjang,
    golonganPerJenjang,
    golonganJenjang,
    kebutuhanPangkat,
    akDasar,
    persentase,
    jenjangPerJabatan,
  };
}