import { roundDecimal } from "../utils/number";
import { MasterKonversi, Jenjang, Predikat } from "@/types";

// AK kinerja = % predikat × koefisien × (bulan aktif / 12)
const hitungKinerja = (
  jenjang: Jenjang,
  predikat: Predikat,
  bulanAktif: number,
  m: MasterKonversi
) =>
  roundDecimal((bulanAktif / 12) * m.persentase[predikat] * m.koefisien[jenjang]);

// Kinerja Periodik / per Triwulan
export const kinerjaPeriodik = hitungKinerja;
export const kinerjaTahunan = hitungKinerja;
