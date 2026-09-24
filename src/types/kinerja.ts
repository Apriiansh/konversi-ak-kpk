import type { Jenjang, Predikat } from "./master";
import type { Pegawai } from "./pegawai";

export type Triwulan = 1 | 2 | 3 | 4;
export type SumberKinerja = "MANUAL" | "IMPORT";
export type StatusKelayakan = "BELUM_CUKUP" | "LAYAK_PANGKAT" | "LAYAK_JENJANG";

export interface InputKinerja {
  pegawaiId: string;
  tahun: number;
  triwulan: Triwulan;
  predikat: Predikat;
  jumlahBulan?: number;
  sumber?: SumberKinerja;
}

export interface PerubahanKinerja {
  predikat: Predikat;
  jumlahBulan?: number;
}

export interface DataKinerjaRow {
  id: string;
  pegawaiId: string;
  tahun: number;
  triwulan: Triwulan;
  jumlahBulan: number;
  predikat: Predikat;
  angkaKredit: number;
  sumber: SumberKinerja;
}

export type BulanPerTW = Record<Triwulan, number>;

export interface PenetapanAKRow {
  id: string;
  pegawaiId: string;
  tahun: number;
  akDasar: number;
  akPAKPelantikan: number;
  akHistoris: number;
  akBaru: number;
  akBooster: number;
  sisaAkDeposit: number;
  akKumulatif: number;
  statusKelayakan: StatusKelayakan;
  catatanKelayakan: string | null;
  isFinal: boolean;
  isLocked: boolean;
}

export interface KelayakanAk {
  status: StatusKelayakan;
  targetKp: number;
  targetJenjang: number;
  akKumulatif: number;
  carryOver: number;
  kurangAk: number;
  catatan: string;
}

export interface HasilSimpanKinerja {
  dataKinerja: DataKinerjaRow;
  penetapan: PenetapanAKRow;
  kelayakan?: KelayakanAk;
}

export interface HasilSimulasi {
  tipe: "periodik" | "tahunan";
  angkaKredit: number;
  bulan: number;
  rumus: string;
}

export type PegawaiRingkas = Pick<
  Pegawai,
  | "id"
  | "nip"
  | "namaLengkap"
  | "golongan"
  | "asalJabatan"
  | "pendidikanTerakhir"
  | "tmtJabatan"
> & {
  jenjang: Jenjang;
  koefisienTahunan: number;
};

export interface ContextKinerja {
  pegawai: PegawaiRingkas;
  saldoAwal: number;
  akKumulatifDraft: number;
  sudahLayakSebelumTw4: boolean;
  twAktif: Triwulan;
  tahun: number;
  bulanPerTw: BulanPerTW | null;
  evaluasi: DataKinerjaRow[];
  penetapanIsFinal: boolean;
  penetapanIsLocked: boolean;
}

export interface DataKinerjaRowDb {
  id: string;
  pegawai_id: string;
  tahun: number;
  triwulan: number;
  jumlah_bulan: number;
  predikat: Predikat;
  angka_kredit: string;
  sumber: "MANUAL" | "IMPORT";
}

export interface PenetapanRowDb {
  id: string;
  pegawai_id: string;
  tahun: number;
  ak_dasar: string;
  ak_pak_pelantikan: string;
  ak_historis: string;
  ak_baru: string;
  ak_booster: string;
  sisa_ak_deposit: string;
  ak_kumulatif: string;
  status_kelayakan: StatusKelayakan;
  catatan_kelayakan: string | null;
  is_final: boolean;
  is_locked: boolean;
}
