import type { MasterKonversi } from "@/types";

export const MASTER_FIXTURE: MasterKonversi = {
  koefisien: {
    AHLI_PERTAMA: 12.5,
    AHLI_MUDA: 25,
    AHLI_MADYA: 37.5,
    AHLI_UTAMA: 50,
  },
  targetPangkat: {
    AHLI_PERTAMA: 50,
    AHLI_MUDA: 100,
    AHLI_MADYA: 150,
    AHLI_UTAMA: 200,
  },
  kebutuhanJenjang: {
    AHLI_PERTAMA: 100,
    AHLI_MUDA: 200,
    AHLI_MADYA: 450,
    AHLI_UTAMA: 9999,
  },
  golonganPerJenjang: {
    AHLI_PERTAMA: ["III/a", "III/b"],
    AHLI_MUDA: ["III/c", "III/d"],
    AHLI_MADYA: ["IV/a", "IV/b", "IV/c"],
    AHLI_UTAMA: ["IV/d", "IV/e"],
  },
  golonganJenjang: {
    "III/a": "AHLI_PERTAMA",
    "III/b": "AHLI_PERTAMA",
    "III/c": "AHLI_MUDA",
    "III/d": "AHLI_MUDA",
    "IV/a": "AHLI_MADYA",
    "IV/b": "AHLI_MADYA",
    "IV/c": "AHLI_MADYA",
    "IV/d": "AHLI_UTAMA",
    "IV/e": "AHLI_UTAMA",
  },
  kebutuhanPangkat: {
    "III/a": 50,
    "III/b": 0,
    "III/c": 100,
    "III/d": 0,
    "IV/a": 150,
    "IV/b": 150,
    "IV/c": 0,
    "IV/d": 200,
    "IV/e": 0,
  },
  persentase: {
    SANGAT_BAIK: 1.5,
    BAIK: 1,
    BUTUH_PERBAIKAN: 0.75,
    KURANG: 0.5,
    SANGAT_KURANG: 0.25,
  },
  jenjangPerJabatan: {
    JABATAN_FUNGSIONAL: null,
    PELAKSANA: "AHLI_PERTAMA",
    PENGAWAS: "AHLI_MUDA",
    ADMINISTRATOR: "AHLI_MADYA",
    PENGANGKATAN_PERTAMA: null,
  },
};
