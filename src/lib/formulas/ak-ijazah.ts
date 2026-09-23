import { roundDecimal } from "../utils/number";
import type { Golongan, MasterKonversi } from "@/types";

const PERSENTASE_AK_IJAZAH = 0.25;

// PerBKN 3/2023: AK perolehan peningkatan pendidikan formal
// = 25% dari angka kredit kumulatif kebutuhan kenaikan pangkat
export function akPeningkatanPendidikan(
  golongan: Golongan,
  m: MasterKonversi,
  perolehPendidikan?: boolean,
): number {
  if (!perolehPendidikan) return 0;

  const kebutuhan = m.kebutuhanPangkat[golongan];
  const dasar =
    kebutuhan > 0
      ? kebutuhan
      : m.kebutuhanJenjang[m.golonganJenjang[golongan]];

  return roundDecimal(PERSENTASE_AK_IJAZAH * dasar);
}