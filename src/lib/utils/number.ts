import { parseISODate } from "./date";

export function hitungBulanAktif(
  tmtJabatan: string,
  tahunEvaluasi: number,
): number {
  const tmt = parseISODate(tmtJabatan);
  const tahun_tmt = tmt.getFullYear();
  const bulan_tmt = tmt.getMonth() + 1;

  if (tahun_tmt > tahunEvaluasi) return 0;
  if (tahun_tmt < tahunEvaluasi) return 12;

  return 12 - bulan_tmt + 1;
}

export function roundDecimal(value: number): number {
  return Math.round(value * 1000) / 1000;
}
