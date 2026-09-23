import { kinerjaPeriodik, kinerjaTahunan } from "./calculate-ak";
import type { MasterKonversi, Golongan, Jenjang, Predikat } from "@/types/master";
import { akPeningkatanPendidikan } from "./ak-ijazah";
import { hitungBulanAktif } from "../utils/number";

const BULAN_PER_TRIWULAN = 3;
const MIN_TAHUN_DALAM_PANGKAT = 2;

export function akumulasiTriwulan(
  jenjang: Jenjang,
  predikat: Predikat,
  m: MasterKonversi,
): number {
  return kinerjaPeriodik(jenjang, predikat, BULAN_PER_TRIWULAN, m);
}

export function setahunkan(
  jenjang: Jenjang,
  predikat_tw4: Predikat,
  m: MasterKonversi,
  bulanAktif = 12,
): number {
  return kinerjaTahunan(jenjang, predikat_tw4, bulanAktif, m);
}

interface PredikatTriwulan {
  tw1?: Predikat;
  tw2?: Predikat;
  tw3?: Predikat;
  tw4?: Predikat;
}

export function akumulasiTahun(
  jenjang: Jenjang,
  triwulan: PredikatTriwulan,
  m: MasterKonversi,
  tmtJabatan?: string,
  tahunEvaluasi?: number,
): number {
  const bulanAktif =
    tmtJabatan && tahunEvaluasi
      ? hitungBulanAktif(tmtJabatan, tahunEvaluasi)
      : 12;

  if (triwulan.tw4) return setahunkan(jenjang, triwulan.tw4, m, bulanAktif);

  return [triwulan.tw1, triwulan.tw2, triwulan.tw3].reduce(
    (total, predikat) =>
      total + (predikat ? akumulasiTriwulan(jenjang, predikat, m) : 0),
    0,
  );
}

export type Kenaikan =
  | {
      jenis: "PANGKAT";
      golonganTujuan: Golongan;
      kebutuhan_ak: number;
      sisa_ak: number;
    }
  | {
      jenis: "PANGKAT_DAN_JENJANG";
      golonganTujuan: Golongan;
      jenjangTujuan: Jenjang;
      kebutuhan_ak: number;
      sisa_ak: number;
    }
  | { jenis: "MAKSIMAL" };

export function statusKenaikan(
  jenjang: Jenjang,
  golongan: Golongan,
  ak_kumulatif: number,
  m: MasterKonversi,
): Kenaikan {
  const urutan = Object.values(m.golonganPerJenjang).flat();
  const golonganTujuan = urutan[urutan.indexOf(golongan) + 1] as
    | Golongan
    | undefined;

  if (!golonganTujuan) return { jenis: "MAKSIMAL" };

  const daftarGolongan = m.golonganPerJenjang[jenjang];
  const puncakJenjang = daftarGolongan[daftarGolongan.length - 1] === golongan;

  if (puncakJenjang) {
    const kebutuhan_ak = m.kebutuhanJenjang[jenjang];
    const jenjangTujuan = (Object.keys(m.golonganPerJenjang) as Jenjang[])[
      (Object.keys(m.golonganPerJenjang) as Jenjang[]).indexOf(jenjang) + 1
    ];
    return {
      jenis: "PANGKAT_DAN_JENJANG",
      golonganTujuan,
      jenjangTujuan,
      kebutuhan_ak,
      sisa_ak: Math.max(0, kebutuhan_ak - ak_kumulatif),
    };
  }

  const kebutuhan_ak = m.kebutuhanPangkat[golongan];

  return {
    jenis: "PANGKAT",
    golonganTujuan,
    kebutuhan_ak,
    sisa_ak: Math.max(0, kebutuhan_ak - ak_kumulatif),
  };
}

interface SyaratKenaikan {
  ak_terpenuhi: boolean;
  masaTerpenuhi: boolean;
  predikatTerpenuhi: boolean;
}

interface InputKelayakan {
  jenjang: Jenjang;
  golongan: Golongan;
  ak_kumulatif: number;
  masaKerjaTahun: number;
  predikatTerakhir: Predikat;
  perolehPendidikan?: boolean;
  m: MasterKonversi;
}

export function kelayakanKenaikan({
  jenjang,
  golongan,
  ak_kumulatif,
  masaKerjaTahun,
  predikatTerakhir,
  perolehPendidikan,
  m
}: InputKelayakan): {
  kenaikan: Kenaikan;
  ak_ijazah: number;
  carry_over: number;
  syarat: SyaratKenaikan;
  siap: boolean;
} {
  const ak_ijazah = akPeningkatanPendidikan(golongan, m, perolehPendidikan);
  const ak_efektif = ak_kumulatif + ak_ijazah;
  const kenaikan = statusKenaikan(jenjang, golongan, ak_efektif, m);

  if (kenaikan.jenis === "MAKSIMAL") {
    return {
      kenaikan,
      ak_ijazah,
      carry_over: 0,
      syarat: {
        ak_terpenuhi: true,
        masaTerpenuhi: true,
        predikatTerpenuhi: true,
      },
      siap: false,
    };
  }

  const syarat: SyaratKenaikan = {
    ak_terpenuhi: kenaikan.sisa_ak <= 0,
    masaTerpenuhi: masaKerjaTahun >= MIN_TAHUN_DALAM_PANGKAT,
    predikatTerpenuhi: m.persentase[predikatTerakhir] >= m.persentase.BAIK,
  };

  const carry_over =
    kenaikan.jenis === "PANGKAT_DAN_JENJANG"
      ? 0
      : kenaikan.sisa_ak <= 0
        ? ak_efektif - kenaikan.kebutuhan_ak
        : ak_efektif;

  return {
    kenaikan,
    ak_ijazah,
    carry_over,
    syarat,
    siap: Object.values(syarat).every(Boolean),
  };
}

export function perkiraanBulanMemenuhi(
  sisa_ak: number,
  jenjang: Jenjang,
  predikat: Predikat,
  m: MasterKonversi,
): number {
  if (sisa_ak <= 0) return 0;

  const ak_perBulan = (m.persentase[predikat] * m.koefisien[jenjang]) / 12;

  return Math.ceil(sisa_ak / ak_perBulan);
}
