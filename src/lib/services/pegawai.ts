import { query } from "../db";
import { formatDate } from "../utils/date";
import type { Pegawai, PegawaiRow } from "@/types";

const KOLOM_PEGAWAI = `
  id, nip, nama_lengkap, role, unit_kerja, golongan, asal_jabatan,
  jenjang_efektif, pendidikan_terakhir, masa_kerja_bulan, saldo_historis,
  tmt_jabatan, tmt_golongan, aktif
`;

function selectPegawai(row: PegawaiRow): Pegawai {
  return {
    id: row.id,
    nip: row.nip,
    namaLengkap: row.nama_lengkap,
    role: row.role,
    unitKerja: row.unit_kerja,
    golongan: row.golongan,
    asalJabatan: row.asal_jabatan,
    jenjangEfektif: row.jenjang_efektif,
    pendidikanTerakhir: row.pendidikan_terakhir,
    masaKerjaBulan: Number(row.masa_kerja_bulan),
    saldoHistoris: Number(row.saldo_historis),
    tmtJabatan: row.tmt_jabatan ? formatDate(row.tmt_jabatan) : null,
    tmtGolongan: row.tmt_golongan ? formatDate(row.tmt_golongan) : null,
    aktif: row.aktif,
  };
}

export async function listPegawai(): Promise<Pegawai[]> {
  const { rows } = await query<PegawaiRow>(`
        SELECT ${KOLOM_PEGAWAI} FROM pegawai WHERE aktif ORDER BY nama_lengkap
    `);

  return rows.map(selectPegawai);
}

export async function getPegawai(id: string): Promise<Pegawai> {
  const { rows } = await query<PegawaiRow>(
    `
        SELECT ${KOLOM_PEGAWAI} FROM pegawai WHERE id = $1 AND aktif
    `,
    [id],
  );

  const row = rows[0];
  if (!row) throw new Error(`Pegawai tidak ditemukan: ${id}`);

  return selectPegawai(row);
}