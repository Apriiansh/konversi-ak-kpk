import type { AsalJabatan, Golongan, Jenjang } from "./master";

export type RolePegawai = "ADMIN" | "PEGAWAI";

export interface Pegawai {
  id: string;
  nip: string;
  namaLengkap: string;
  role: RolePegawai;
  unitKerja: string | null;
  golongan: Golongan;
  asalJabatan: AsalJabatan;
  jenjangEfektif: Jenjang | null;
  pendidikanTerakhir: string | null;
  masaKerjaBulan: number;
  saldoHistoris: number;
  tmtJabatan: string | null;
  tmtGolongan: string | null;
  aktif: boolean;
}

export interface PegawaiRow {
  id: string;
  nip: string;
  nama_lengkap: string;
  role: RolePegawai;
  unit_kerja: string | null;
  golongan: Golongan;
  asal_jabatan: AsalJabatan;
  jenjang_efektif: Jenjang | null;
  pendidikan_terakhir: string | null;
  masa_kerja_bulan: string;
  saldo_historis: string;
  tmt_jabatan: Date | null;
  tmt_golongan: Date | null;
  aktif: boolean;
}