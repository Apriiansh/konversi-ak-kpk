export type Jenjang = "AHLI_PERTAMA" | "AHLI_MUDA" | "AHLI_MADYA" | "AHLI_UTAMA";
export type Golongan =
  | "III/a" | "III/b" | "III/c" | "III/d"
  | "IV/a" | "IV/b" | "IV/c" | "IV/d" | "IV/e";
export type Predikat =
  | "SANGAT_BAIK" | "BAIK" | "BUTUH_PERBAIKAN" | "KURANG" | "SANGAT_KURANG";
export type AsalJabatan =
  | "JABATAN_FUNGSIONAL" | "PELAKSANA" | "PENGAWAS" | "ADMINISTRATOR" | "PENGANGKATAN_PERTAMA";

export interface MasterKonversi {
  koefisien: Record<Jenjang, number>;
  kebutuhanJenjang: Record<Jenjang, number>;
  golonganPerJenjang: Record<Jenjang, Golongan[]>;
  golonganJenjang: Record<Golongan, Jenjang>;
  kebutuhanPangkat: Record<Golongan, number>;   // 0 => pakai kebutuhanJenjang
  akDasar: Record<Golongan, number>;            // saldo dasar saat golongan diduduki (golongan.ak_dasar)
  persentase: Record<Predikat, number>;
  jenjangPerJabatan: Record<AsalJabatan, Jenjang | null>;
}

