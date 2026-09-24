import type { PoolClient } from "pg";
import { query, withTransaction } from "../db";
import { getMasterKonversi } from "./master";
import { getPegawai } from "./pegawai";
import { kinerjaPeriodik } from "../formulas/calculate-ak";
import { setahunkan, statusKenaikan } from "../formulas/kelayakan";
import { roundDecimal } from "../utils/number";
import { parseISODate } from "../utils/date";
import type {
  BulanPerTW,
  ContextKinerja,
  DataKinerjaRow,
  DataKinerjaRowDb,
  Golongan,
  HasilSimpanKinerja,
  HasilSimulasi,
  InputKinerja,
  Jenjang,
  KelayakanAk,
  MasterKonversi,
  Pegawai,
  PegawaiRingkas,
  PenetapanAKRow,
  PenetapanRowDb,
  PerubahanKinerja,
  Predikat,
  StatusKelayakan,
  Triwulan,
} from "@/types";

const toNumber = (v: string | null): number => Number(v ?? 0);
const r3 = roundDecimal;

const KOLOM_DATA_KINERJA =
  "id, pegawai_id, tahun, triwulan, jumlah_bulan, predikat, angka_kredit, sumber";

const KOLOM_PENETAPAN =
  "id, pegawai_id, tahun, ak_dasar, ak_pak_pelantikan, ak_historis, " +
  "ak_baru, ak_booster, sisa_ak_deposit, ak_kumulatif, status_kelayakan, " +
  "catatan_kelayakan, is_final, is_locked";

function selectDataKinerja(row: DataKinerjaRowDb): DataKinerjaRow {
  return {
    id: row.id,
    pegawaiId: row.pegawai_id,
    tahun: row.tahun,
    triwulan: row.triwulan as Triwulan,
    jumlahBulan: row.jumlah_bulan,
    predikat: row.predikat,
    angkaKredit: Number(row.angka_kredit),
    sumber: row.sumber,
  };
}

function selectPenetapan(row: PenetapanRowDb): PenetapanAKRow {
  return {
    id: row.id,
    pegawaiId: row.pegawai_id,
    tahun: row.tahun,
    akDasar: toNumber(row.ak_dasar),
    akPAKPelantikan: toNumber(row.ak_pak_pelantikan),
    akHistoris: toNumber(row.ak_historis),
    akBaru: toNumber(row.ak_baru),
    akBooster: toNumber(row.ak_booster),
    sisaAkDeposit: toNumber(row.sisa_ak_deposit),
    akKumulatif: toNumber(row.ak_kumulatif),
    statusKelayakan: row.status_kelayakan,
    catatanKelayakan: row.catatan_kelayakan,
    isFinal: row.is_final,
    isLocked: row.is_locked,
  };
}

interface KonteksOperasi {
  pegawai: Pegawai;
  m: MasterKonversi;
  jenjang: Jenjang;
}

async function muatKonteks(pegawaiId: string): Promise<KonteksOperasi> {
  const [pegawai, m] = await Promise.all([getPegawai(pegawaiId), getMasterKonversi()]);
  const jenjang = pegawai.jenjangEfektif ?? m.golonganJenjang[pegawai.golongan];
  return { pegawai, m, jenjang };
}

// =============================================================
// Validasi
// =============================================================

function validasiTahun(tahun: number): void {
  if (!Number.isInteger(tahun) || tahun < 2000 || tahun > 2100) {
    throw new Error(`Tahun tidak valid: ${tahun}`);
  }
}

function validasiPredikat(predikat: Predikat, m: MasterKonversi): void {
  if (!(predikat in m.persentase)) throw new Error(`Predikat tidak dikenal: ${predikat}`);
}

function validasiJumlahBulan(jumlahBulan: number): void {
  if (!Number.isInteger(jumlahBulan) || jumlahBulan < 0 || jumlahBulan > 12) {
    throw new Error(`Jumlah bulan harus 0–12, didapat ${jumlahBulan}`);
  }
}

// =============================================================
// Hitung distribusi bulan aktif per TW (auto-cut dari TMT)
// =============================================================

export function hitungBulanPerTw(tmt: string | null, tahun: number): BulanPerTW | null {
  if (!tmt) return null;

  const tmtDate = parseISODate(tmt);
  if (tmtDate.getFullYear() > tahun) return null;
  if (tmtDate.getFullYear() < tahun) return { 1: 3, 2: 3, 3: 3, 4: 3 };

  const bulanTmt = tmtDate.getMonth() + 1;
  const twTmt = (Math.floor((bulanTmt - 1) / 3) + 1) as Triwulan;
  const sisaBulanDiTwTmt = Math.ceil(bulanTmt / 3) * 3 - bulanTmt + 1;

  return { 1: 3, 2: 3, 3: 3, 4: 3, [twTmt]: sisaBulanDiTwTmt } as BulanPerTW;
}

// =============================================================
// Evaluasi kelayakan + carry-over (aturan SDLC "Ak & Status Kenaikan")
// =============================================================

export function evaluasiKelayakanAk(
  jenjang: Jenjang,
  golongan: Golongan,
  akKumulatif: number,
  m: MasterKonversi,
): KelayakanAk {
  const targetKp = m.kebutuhanPangkat[golongan] || m.kebutuhanJenjang[jenjang];
  const targetJenjang = m.kebutuhanJenjang[jenjang];
  const kenaikan = statusKenaikan(jenjang, golongan, akKumulatif, m);

  let status: StatusKelayakan;
  let carryOver: number;
  let kurangAk: number;
  let catatan: string;

  if (kenaikan.jenis === "MAKSIMAL") {
    status = "LAYAK_JENJANG";
    carryOver = 0;
    kurangAk = 0;
    catatan = "Pangkat tertinggi (MAKSIMAL) — seluruh AK hangus.";
  } else if (kenaikan.sisa_ak <= 0) {
    status = kenaikan.jenis === "PANGKAT_DAN_JENJANG" ? "LAYAK_JENJANG" : "LAYAK_PANGKAT";
    carryOver =
      kenaikan.jenis === "PANGKAT_DAN_JENJANG" ? 0 : r3(akKumulatif - kenaikan.kebutuhan_ak);
    kurangAk = 0;
    catatan =
      kenaikan.jenis === "PANGKAT_DAN_JENJANG"
        ? `Cukup untuk naik jenjang (target ${targetJenjang}). Seluruh AK diserap.`
        : `Cukup untuk naik pangkat (kebutuhan ${kenaikan.kebutuhan_ak}). Kelebihan ${r3(akKumulatif - kenaikan.kebutuhan_ak)} ditabung.`;
  } else {
    status = "BELUM_CUKUP";
    carryOver = akKumulatif;
    kurangAk = kenaikan.sisa_ak;
    catatan = `Masih kurang ${r3(kenaikan.sisa_ak)}. Seluruh AK dibawa ke tahun berikutnya.`;
  }

  return {
    status,
    targetKp,
    targetJenjang,
    akKumulatif: r3(akKumulatif),
    carryOver: r3(carryOver),
    kurangAk: r3(kurangAk),
    catatan,
  };
}

// =============================================================
// Akses baris database (dalam transaksi)
// =============================================================

async function findDataKinerja(
  client: PoolClient,
  pegawaiId: string,
  tahun: number,
  triwulan: number,
): Promise<DataKinerjaRowDb | null> {
  const { rows } = await client.query<DataKinerjaRowDb>(
    `SELECT ${KOLOM_DATA_KINERJA} FROM data_kinerja
     WHERE pegawai_id=$1 AND tahun=$2 AND triwulan=$3`,
    [pegawaiId, tahun, triwulan],
  );
  return rows[0] ?? null;
}

async function findPenetapan(
  client: PoolClient,
  pegawaiId: string,
  tahun: number,
): Promise<PenetapanRowDb | null> {
  const { rows } = await client.query<PenetapanRowDb>(
    `SELECT ${KOLOM_PENETAPAN} FROM akumulasi_angka_kredit
     WHERE pegawai_id=$1 AND tahun=$2`,
    [pegawaiId, tahun],
  );
  return rows[0] ?? null;
}

async function dapatkanPenetapan(
  client: PoolClient,
  pegawaiId: string,
  tahun: number,
  akDasar: number,
): Promise<PenetapanRowDb> {
  const ada = await findPenetapan(client, pegawaiId, tahun);
  if (ada) return ada;

  await client.query(
    `INSERT INTO akumulasi_angka_kredit (pegawai_id, tahun, ak_dasar)
     VALUES ($1, $2, $3) ON CONFLICT (pegawai_id, tahun) DO NOTHING`,
    [pegawaiId, tahun, akDasar],
  );
  const { rows } = await client.query<PenetapanRowDb>(
    `SELECT ${KOLOM_PENETAPAN} FROM akumulasi_angka_kredit
     WHERE pegawai_id=$1 AND tahun=$2`,
    [pegawaiId, tahun],
  );
  return rows[0];
}

function periksaTidakTerkunci(penetapan: PenetapanRowDb): void {
  if (penetapan.is_locked) {
    throw new Error(`Periode tahun ${penetapan.tahun} sudah dikunci dan tidak dapat diubah.`);
  }
}

async function totalAngkaKredit(
  client: PoolClient,
  pegawaiId: string,
  tahun: number,
  triwulanMaks = 4,
): Promise<number> {
  const { rows } = await client.query<{ total: string }>(
    `SELECT COALESCE(SUM(angka_kredit), 0) AS total FROM data_kinerja
     WHERE pegawai_id=$1 AND tahun=$2 AND triwulan <= $3`,
    [pegawaiId, tahun, triwulanMaks],
  );
  return toNumber(rows[0]?.total);
}

async function saldoAwalEfektif(
  client: PoolClient,
  pegawaiId: string,
  tahun: number,
  akDasar: number,
  akPAKPelantikan: number,
  akHistoris: number,
): Promise<number> {
  const prev = await findPenetapan(client, pegawaiId, tahun - 1);
  const deposit = prev ? toNumber(prev.sisa_ak_deposit) : 0;
  return deposit > 0 ? deposit : akDasar + akPAKPelantikan + akHistoris;
}

// =============================================================
// Finalisasi tahunan (TW4 one-pass) & sinkron draft (TW1–3)
// =============================================================

type HasilSinkron = { penetapan: PenetapanAKRow; kelayakan: KelayakanAk };

async function finalisasiTahunan(
  client: PoolClient,
  pegawaiId: string,
  ctx: KonteksOperasi,
  tahun: number,
  akBooster = 0,
): Promise<HasilSinkron> {
  const tw4 = await findDataKinerja(client, pegawaiId, tahun, 4);
  if (!tw4) throw new Error("TW4 belum diisi — tahun belum bisa difinalisasi.");

  const penetapan = await dapatkanPenetapan(client, pegawaiId, tahun, ctx.m.akDasar[ctx.pegawai.golongan] ?? 0);
  periksaTidakTerkunci(penetapan);

  const saldoAwal = await saldoAwalEfektif(
    client,
    pegawaiId,
    tahun,
    toNumber(penetapan.ak_dasar),
    toNumber(penetapan.ak_pak_pelantikan),
    toNumber(penetapan.ak_historis),
  );

  const akTw1_3 = await totalAngkaKredit(client, pegawaiId, tahun, 3);
  const masihLayakTw3 =
    evaluasiKelayakanAk(
      ctx.jenjang,
      ctx.pegawai.golongan,
      r3(saldoAwal + akTw1_3 + akBooster),
      ctx.m,
    ).status !== "BELUM_CUKUP";

  // Sudah layak TW3 -> formula A (jumlahkan semua TW); belum layak -> setahunkan TW4
  const akBaru = masihLayakTw3
    ? await totalAngkaKredit(client, pegawaiId, tahun)
    : setahunkan(ctx.jenjang, tw4.predikat, ctx.m);

  const akKumulatif = r3(saldoAwal + akBaru + akBooster);
  const kelayakan = evaluasiKelayakanAk(ctx.jenjang, ctx.pegawai.golongan, akKumulatif, ctx.m);

  const { rows } = await client.query<PenetapanRowDb>(
    `UPDATE akumulasi_angka_kredit
     SET ak_baru=$3, ak_booster=$4, ak_kumulatif=$5, sisa_ak_deposit=$6,
         status_kelayakan=$7, catatan_kelayakan=$8, is_final=true, updated_at=now()
     WHERE pegawai_id=$1 AND tahun=$2
     RETURNING ${KOLOM_PENETAPAN}`,
    [
      pegawaiId,
      tahun,
      r3(akBaru),
      akBooster,
      akKumulatif,
      kelayakan.carryOver,
      kelayakan.status,
      kelayakan.catatan,
    ],
  );

  return { penetapan: selectPenetapan(rows[0]), kelayakan };
}

async function sinkronkanPenetapan(
  client: PoolClient,
  pegawaiId: string,
  ctx: KonteksOperasi,
  tahun: number,
  akBooster = 0,
): Promise<HasilSinkron> {
  const penetapan = await dapatkanPenetapan(client, pegawaiId, tahun, ctx.m.akDasar[ctx.pegawai.golongan] ?? 0);
  periksaTidakTerkunci(penetapan);

  const saldoAwal = await saldoAwalEfektif(
    client,
    pegawaiId,
    tahun,
    toNumber(penetapan.ak_dasar),
    toNumber(penetapan.ak_pak_pelantikan),
    toNumber(penetapan.ak_historis),
  );

  const akBaru = await totalAngkaKredit(client, pegawaiId, tahun);
  const akKumulatif = r3(saldoAwal + akBaru + akBooster);
  const kelayakan = evaluasiKelayakanAk(ctx.jenjang, ctx.pegawai.golongan, akKumulatif, ctx.m);

  const { rows } = await client.query<PenetapanRowDb>(
    `UPDATE akumulasi_angka_kredit
     SET ak_baru=$3, ak_booster=$4, ak_kumulatif=$5, sisa_ak_deposit=0,
         status_kelayakan=$6, catatan_kelayakan=$7, is_final=false, updated_at=now()
     WHERE pegawai_id=$1 AND tahun=$2
     RETURNING ${KOLOM_PENETAPAN}`,
    [pegawaiId, tahun, r3(akBaru), akBooster, akKumulatif, kelayakan.status, kelayakan.catatan],
  );

  return { penetapan: selectPenetapan(rows[0]), kelayakan };
}

function siapkanJumlahBulan(ctx: KonteksOperasi, tahun: number, triwulan: Triwulan, jumlahBulan?: number): number {
  const bulanPerTw = hitungBulanPerTw(ctx.pegawai.tmtJabatan, tahun);
  const jumlah = jumlahBulan ?? bulanPerTw?.[triwulan] ?? 3;
  validasiJumlahBulan(jumlah);
  return jumlah;
}

// =============================================================
// API publik
// =============================================================

export async function simpanKinerja(input: InputKinerja): Promise<HasilSimpanKinerja> {
  const { pegawaiId, tahun, triwulan, predikat, sumber = "MANUAL" } = input;
  validasiTahun(tahun);

  const ctx = await muatKonteks(pegawaiId);
  validasiPredikat(predikat, ctx.m);
  const jumlahBulan = siapkanJumlahBulan(ctx, tahun, triwulan, input.jumlahBulan);
  const angka = kinerjaPeriodik(ctx.jenjang, predikat, jumlahBulan, ctx.m);

  return withTransaction(async (client) => {
    const penetapan = await findPenetapan(client, pegawaiId, tahun);
    if (penetapan) periksaTidakTerkunci(penetapan);

    const { rows } = await client.query<DataKinerjaRowDb>(
      `INSERT INTO data_kinerja
         (pegawai_id, tahun, triwulan, jumlah_bulan, predikat, angka_kredit, sumber)
       VALUES ($1, $2, $3, $4, $5, $6, $7)
       ON CONFLICT (pegawai_id, tahun, triwulan) DO UPDATE
         SET jumlah_bulan=$4, predikat=$5, angka_kredit=$6, sumber=$7, updated_at=now()
       RETURNING ${KOLOM_DATA_KINERJA}`,
      [pegawaiId, tahun, triwulan, jumlahBulan, predikat, angka, sumber],
    );
    const dataKinerja = selectDataKinerja(rows[0]);

    const hasil =
      triwulan === 4
        ? await finalisasiTahunan(client, pegawaiId, ctx, tahun)
        : await sinkronkanPenetapan(client, pegawaiId, ctx, tahun);

    return { dataKinerja, ...hasil };
  });
}

export function tetapkanKinerjaTahunan(
  input: Omit<InputKinerja, "triwulan">,
): Promise<HasilSimpanKinerja> {
  return simpanKinerja({ ...input, triwulan: 4 });
}

export async function perbaruiKinerja(
  id: string,
  perubahan: PerubahanKinerja,
): Promise<HasilSimpanKinerja> {
  return withTransaction(async (client) => {
    const { rows } = await client.query<DataKinerjaRowDb>(
      `SELECT ${KOLOM_DATA_KINERJA} FROM data_kinerja WHERE id=$1`,
      [id],
    );
    const row = rows[0];
    if (!row) throw new Error(`Data kinerja tidak ditemukan: ${id}`);

    const ctx = await muatKonteks(row.pegawai_id);
    validasiPredikat(perubahan.predikat, ctx.m);

    const penetapan = await findPenetapan(client, row.pegawai_id, row.tahun);
    if (penetapan) periksaTidakTerkunci(penetapan);

    const jumlahBulan = perubahan.jumlahBulan ?? row.jumlah_bulan;
    validasiJumlahBulan(jumlahBulan);
    const angka = kinerjaPeriodik(ctx.jenjang, perubahan.predikat, jumlahBulan, ctx.m);

    const { rows: updated } = await client.query<DataKinerjaRowDb>(
      `UPDATE data_kinerja
       SET predikat=$2, jumlah_bulan=$3, angka_kredit=$4, updated_at=now()
       WHERE id=$1
       RETURNING ${KOLOM_DATA_KINERJA}`,
      [id, perubahan.predikat, jumlahBulan, angka],
    );
    const dataKinerja = selectDataKinerja(updated[0]);

    const hasil =
      row.triwulan === 4
        ? await finalisasiTahunan(client, row.pegawai_id, ctx, row.tahun)
        : await sinkronkanPenetapan(client, row.pegawai_id, ctx, row.tahun);

    return { dataKinerja, ...hasil };
  });
}

export async function hapusKinerja(id: string): Promise<HasilSimpanKinerja> {
  return withTransaction(async (client) => {
    const { rows } = await client.query<DataKinerjaRowDb>(
      `SELECT ${KOLOM_DATA_KINERJA} FROM data_kinerja WHERE id=$1`,
      [id],
    );
    const row = rows[0];
    if (!row) throw new Error(`Data kinerja tidak ditemukan: ${id}`);

    const ctx = await muatKonteks(row.pegawai_id);
    const penetapan = await findPenetapan(client, row.pegawai_id, row.tahun);
    if (penetapan) periksaTidakTerkunci(penetapan);

    await client.query("DELETE FROM data_kinerja WHERE id=$1", [id]);

    const tw4MasihAda =
      row.triwulan !== 4 && (await findDataKinerja(client, row.pegawai_id, row.tahun, 4)) !== null;
    const hasil = tw4MasihAda
      ? await finalisasiTahunan(client, row.pegawai_id, ctx, row.tahun)
      : await sinkronkanPenetapan(client, row.pegawai_id, ctx, row.tahun);

    return { dataKinerja: selectDataKinerja(row), ...hasil };
  });
}

export async function getContextKinerja(
  pegawaiId: string,
  tahun: number,
): Promise<ContextKinerja> {
  validasiTahun(tahun);
  const ctx = await muatKonteks(pegawaiId);

  const [resKinerja, resPenetapan, resPrev] = await Promise.all([
    query<DataKinerjaRowDb>(
      `SELECT ${KOLOM_DATA_KINERJA} FROM data_kinerja
       WHERE pegawai_id=$1 AND tahun=$2 ORDER BY triwulan`,
      [pegawaiId, tahun],
    ),
    query<PenetapanRowDb>(
      `SELECT ${KOLOM_PENETAPAN} FROM akumulasi_angka_kredit
       WHERE pegawai_id=$1 AND tahun=$2`,
      [pegawaiId, tahun],
    ),
    query<PenetapanRowDb>(
      `SELECT ${KOLOM_PENETAPAN} FROM akumulasi_angka_kredit
       WHERE pegawai_id=$1 AND tahun=$2`,
      [pegawaiId, tahun - 1],
    ),
  ]);

  const penetapan = resPenetapan.rows[0] ?? null;
  const prev = resPrev.rows[0] ?? null;
  const depositPrev = prev ? toNumber(prev.sisa_ak_deposit) : 0;
  const base = penetapan
    ? toNumber(penetapan.ak_dasar) +
      toNumber(penetapan.ak_pak_pelantikan) +
      toNumber(penetapan.ak_historis)
    : (ctx.m.akDasar[ctx.pegawai.golongan] ?? 0);
  const saldoAwal = depositPrev > 0 ? depositPrev : base;

  const evaluasi = resKinerja.rows.map(selectDataKinerja);
  const tahunTersimpan = evaluasi.map((d) => d.triwulan);
  const twAktif: Triwulan = tahunTersimpan.includes(4)
    ? 4
    : ((Math.max(0, ...tahunTersimpan) + 1) as Triwulan);

  const sumTw1_3 = evaluasi
    .filter((d) => d.triwulan !== 4)
    .reduce((t, d) => t + d.angkaKredit, 0);
  const akKumulatifDraft = r3(saldoAwal + sumTw1_3);
  const sudahLayakSebelumTw4 =
    evaluasiKelayakanAk(ctx.jenjang, ctx.pegawai.golongan, akKumulatifDraft, ctx.m).status !==
    "BELUM_CUKUP";

  const { pegawai } = ctx;
  const ringkas: PegawaiRingkas = {
    id: pegawai.id,
    nip: pegawai.nip,
    namaLengkap: pegawai.namaLengkap,
    golongan: pegawai.golongan,
    asalJabatan: pegawai.asalJabatan,
    pendidikanTerakhir: pegawai.pendidikanTerakhir,
    tmtJabatan: pegawai.tmtJabatan,
    jenjang: ctx.jenjang,
    koefisienTahunan: ctx.m.koefisien[ctx.jenjang],
  };

  return {
    pegawai: ringkas,
    saldoAwal,
    akKumulatifDraft,
    sudahLayakSebelumTw4,
    twAktif,
    tahun,
    bulanPerTw: hitungBulanPerTw(pegawai.tmtJabatan, tahun),
    evaluasi,
    penetapanIsFinal: penetapan?.is_final ?? false,
    penetapanIsLocked: penetapan?.is_locked ?? false,
  };
}

export async function simulasiKinerja(input: InputKinerja): Promise<HasilSimulasi> {
  const { pegawaiId, tahun, triwulan, predikat } = input;
  validasiTahun(tahun);
  validasiTriwulan(triwulan);

  const ctx = await muatKonteks(pegawaiId);
  validasiPredikat(predikat, ctx.m);
  const jumlahBulan = siapkanJumlahBulan(ctx, tahun, triwulan, input.jumlahBulan);
  const angka = kinerjaPeriodik(ctx.jenjang, predikat, jumlahBulan, ctx.m);

  return {
    tipe: "periodik",
    angkaKredit: angka,
    bulan: jumlahBulan,
    rumus: `${predikat} × koefisien ${ctx.m.koefisien[ctx.jenjang]} × (${jumlahBulan}/12) → ${angka}`,
  };
}

function validasiTriwulan(triwulan: number): void {
  if (![1, 2, 3, 4].includes(triwulan)) throw new Error(`Triwulan tidak valid: ${triwulan}`);
}