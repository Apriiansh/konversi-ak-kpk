import { roundDecimal } from "../utils/number";
import { MasterKonversi, AsalJabatan, Golongan, Predikat } from "@/types";

const AK_TABEL_GOLONGAN_TIDAK_SESUAI = 100;
const MAKS_TAHUN_PANGKAT_PUNCAK = 3;

export interface PelantikanJF {
  jabatanAsal: AsalJabatan;
  golongan: Golongan;
  masaKerjaTahun: number;
  masaKerjaBulan: number;
  predikat: Predikat;
  akDasar?: number;
  pangkatPuncak?: boolean;
}

// PerBKN 3/2023 Pasal 7
// AK = % predikat × koefisien × masa kepangkatan + AK Dasar
// Golongan tidak sesuai jenjang → tabel AK (flat). Pangkat puncak > 3 tahun → maksimal 3 tahun.
export function pakPelantikanJF(
  {
    jabatanAsal,
    golongan,
    masaKerjaTahun,
    masaKerjaBulan,
    predikat,
    akDasar = 0,
    pangkatPuncak = false,
  }: PelantikanJF,
  m: MasterKonversi,
): number {
  const jenjang = m.jenjangPerJabatan[jabatanAsal];
  if (!jenjang)
    throw new Error(
      `Peta jenjang blm tersedia buat jabatan asal ${jabatanAsal}`,
    );

  const golonganTinggi = m.golonganJenjang[golongan] !== "AHLI_PERTAMA";
  if (jabatanAsal === "PELAKSANA" && golonganTinggi) {
    return AK_TABEL_GOLONGAN_TIDAK_SESUAI;
  }

  const masa =
    pangkatPuncak && masaKerjaTahun > MAKS_TAHUN_PANGKAT_PUNCAK
      ? MAKS_TAHUN_PANGKAT_PUNCAK
      : masaKerjaTahun + masaKerjaBulan / 12;

  const ak = m.persentase[predikat] * m.koefisien[jenjang] * masa + akDasar;

  return roundDecimal(ak);
}
