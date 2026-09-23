import { describe, it, expect } from "vitest";
import {
  akumulasiTriwulan,
  akumulasiTahun,
  setahunkan,
  statusKenaikan,
  kelayakanKenaikan,
  perkiraanBulanMemenuhi,
} from "./kelayakan";
import { hitungBulanAktif } from "../utils/number";
import { MASTER_FIXTURE } from "./master.fixture";

describe("akumulasiTriwulan", () => {
  it("TW = 3 bulan periodik", () => {
    expect(akumulasiTriwulan("AHLI_PERTAMA", "BAIK", MASTER_FIXTURE)).toBe(3.125);
    expect(akumulasiTriwulan("AHLI_MUDA", "SANGAT_BAIK", MASTER_FIXTURE)).toBe(9.375);
  });
});

describe("setahunkan", () => {
  it("TW4 = 12 bulan pakai predikat TW4", () => {
    expect(setahunkan("AHLI_PERTAMA", "SANGAT_BAIK", MASTER_FIXTURE)).toBe(18.75);
  });
});

describe("akumulasiTahun", () => {
  it("TW1-3 dijumlahkan periodik", () => {
    expect(
      akumulasiTahun("AHLI_PERTAMA", { tw1: "BAIK", tw2: "BAIK", tw3: "BAIK" }, MASTER_FIXTURE),
    ).toBe(9.375);
  });

  it("TW4 disetahunkan, TW1-3 diabaikan", () => {
    expect(
      akumulasiTahun("AHLI_PERTAMA", {
        tw1: "BAIK",
        tw2: "BAIK",
        tw3: "BAIK",
        tw4: "SANGAT_BAIK",
      }, MASTER_FIXTURE),
    ).toBe(18.75);
  });
});

describe("Formula B tahunan dengan bulan aktif (SDLC section 3)", () => {
  it("setahunkan dengan 10 bulan aktif", () => {
    expect(setahunkan("AHLI_PERTAMA", "BAIK", MASTER_FIXTURE, 10)).toBe(10.417);
  });

  it("contoh Budi: TMT 1 Maret → bulan aktif 10 → 10,42 AK", () => {
    expect(hitungBulanAktif("2023-03-01", 2023)).toBe(10);
    expect(
      akumulasiTahun("AHLI_PERTAMA", { tw4: "BAIK" }, MASTER_FIXTURE, "2023-03-01", 2023),
    ).toBe(10.417);
  });

  it("TMT 21 Februari → bulan aktif 11", () => {
    expect(hitungBulanAktif("2023-02-21", 2023)).toBe(11);
    expect(setahunkan("AHLI_PERTAMA", "BAIK", MASTER_FIXTURE, 11)).toBe(11.458);
  });
});

describe("statusKenaikan", () => {
  it("Ahli Pertama III/a → PANGKAT III/b, kebutuhan 50", () => {
    expect(statusKenaikan("AHLI_PERTAMA", "III/a", 30, MASTER_FIXTURE)).toEqual({
      jenis: "PANGKAT",
      golonganTujuan: "III/b",
      kebutuhan_ak: 50,
      sisa_ak: 20,
    });
  });

  it("puncak Ahli Pertama III/b → PANGKAT_DAN_JENJANG ke Ahli Muda, kebutuhan 100", () => {
    expect(statusKenaikan("AHLI_PERTAMA", "III/b", 80, MASTER_FIXTURE)).toEqual({
      jenis: "PANGKAT_DAN_JENJANG",
      golonganTujuan: "III/c",
      jenjangTujuan: "AHLI_MUDA",
      kebutuhan_ak: 100,
      sisa_ak: 20,
    });
  });

  it("puncak Ahli Muda III/d → PANGKAT_DAN_JENJANG ke Ahli Madya, kebutuhan 200", () => {
    expect(statusKenaikan("AHLI_MUDA", "III/d", 150, MASTER_FIXTURE)).toEqual({
      jenis: "PANGKAT_DAN_JENJANG",
      golonganTujuan: "IV/a",
      jenjangTujuan: "AHLI_MADYA",
      kebutuhan_ak: 200,
      sisa_ak: 50,
    });
  });

  it("puncak Ahli Madya IV/c → PANGKAT_DAN_JENJANG ke Ahli Utama, kebutuhan 450", () => {
    expect(statusKenaikan("AHLI_MADYA", "IV/c", 400, MASTER_FIXTURE)).toEqual({
      jenis: "PANGKAT_DAN_JENJANG",
      golonganTujuan: "IV/d",
      jenjangTujuan: "AHLI_UTAMA",
      kebutuhan_ak: 450,
      sisa_ak: 50,
    });
  });

  it("Ahli Utama IV/d bukan puncak → PANGKAT IV/e, kebutuhan 200", () => {
    expect(statusKenaikan("AHLI_UTAMA", "IV/d", 150, MASTER_FIXTURE)).toEqual({
      jenis: "PANGKAT",
      golonganTujuan: "IV/e",
      kebutuhan_ak: 200,
      sisa_ak: 50,
    });
  });

  it("IV/e puncak skala → MAKSIMAL", () => {
    expect(statusKenaikan("AHLI_UTAMA", "IV/e", 999, MASTER_FIXTURE)).toEqual({
      jenis: "MAKSIMAL",
    });
  });
});

describe("perkiraanBulanMemenuhi (contoh panduan)", () => {
  it("sisa 3,125 di Ahli Pertama predikat BAIK → 3 bulan", () => {
    expect(perkiraanBulanMemenuhi(3.125, "AHLI_PERTAMA", "BAIK", MASTER_FIXTURE)).toBe(3);
  });

  it("sisa <= 0 → 0 bulan", () => {
    expect(perkiraanBulanMemenuhi(0, "AHLI_PERTAMA", "BAIK", MASTER_FIXTURE)).toBe(0);
  });
});

describe("kelayakanKenaikan", () => {
  it("semua syarat terpenuhi → siap", () => {
    const hasil = kelayakanKenaikan({
      jenjang: "AHLI_PERTAMA",
      golongan: "III/a",
      ak_kumulatif: 50,
      masaKerjaTahun: 2,
      predikatTerakhir: "BAIK",
      m: MASTER_FIXTURE,
    });
    expect(hasil.siap).toBe(true);
    expect(hasil.syarat).toEqual({
      ak_terpenuhi: true,
      masaTerpenuhi: true,
      predikatTerpenuhi: true,
    });
  });

  it("AK kurang → tidak siap", () => {
    const hasil = kelayakanKenaikan({
      jenjang: "AHLI_PERTAMA",
      golongan: "III/a",
      ak_kumulatif: 10,
      masaKerjaTahun: 2,
      predikatTerakhir: "BAIK",
      m: MASTER_FIXTURE,
    });
    expect(hasil.syarat.ak_terpenuhi).toBe(false);
    expect(hasil.siap).toBe(false);
  });

  it("masa < 2 tahun → tidak siap", () => {
    const hasil = kelayakanKenaikan({
      jenjang: "AHLI_PERTAMA",
      golongan: "III/a",
      ak_kumulatif: 50,
      masaKerjaTahun: 1,
      predikatTerakhir: "BAIK",
      m: MASTER_FIXTURE,
    });
    expect(hasil.syarat.masaTerpenuhi).toBe(false);
    expect(hasil.siap).toBe(false);
  });

  it("predikat di bawah Baik → tidak siap", () => {
    const hasil = kelayakanKenaikan({
      jenjang: "AHLI_PERTAMA",
      golongan: "III/a",
      ak_kumulatif: 50,
      masaKerjaTahun: 2,
      predikatTerakhir: "BUTUH_PERBAIKAN",
      m: MASTER_FIXTURE,
    });
    expect(hasil.syarat.predikatTerpenuhi).toBe(false);
    expect(hasil.siap).toBe(false);
  });

  it("AK ijazah 25% menambah kumulatif → sisa_ak terpenuhi", () => {
    const hasil = kelayakanKenaikan({
      jenjang: "AHLI_PERTAMA",
      golongan: "III/a",
      ak_kumulatif: 40,
      masaKerjaTahun: 2,
      predikatTerakhir: "BAIK",
      perolehPendidikan: true,
      m: MASTER_FIXTURE,
    });
    expect(hasil.ak_ijazah).toBe(12.5);
    expect(hasil.kenaikan).toEqual({
      jenis: "PANGKAT",
      golonganTujuan: "III/b",
      kebutuhan_ak: 50,
      sisa_ak: 0,
    });
    expect(hasil.siap).toBe(true);
  });

 it("tanpa ijazah, AK 40 di III/a belum terpenuhi", () => {
    const hasil = kelayakanKenaikan({
      jenjang: "AHLI_PERTAMA",
      golongan: "III/a",
      ak_kumulatif: 40,
      masaKerjaTahun: 2,
      predikatTerakhir: "BAIK",
      m: MASTER_FIXTURE,
    });
    expect(hasil.ak_ijazah).toBe(0);

    if (hasil.kenaikan.jenis !== "PANGKAT") {
      throw new Error("seharusnya PANGKAT: " + hasil.kenaikan.jenis);
    }
    expect(hasil.kenaikan.sisa_ak).toBe(10);
    expect(hasil.kenaikan.kebutuhan_ak).toBe(50);

    expect(hasil.siap).toBe(false);
  });

  it("MAKSIMAL → tidak siap", () => {
    const hasil = kelayakanKenaikan({
      jenjang: "AHLI_UTAMA",
      golongan: "IV/e",
      ak_kumulatif: 999,
      masaKerjaTahun: 10,
      predikatTerakhir: "SANGAT_BAIK",
      m: MASTER_FIXTURE,
    });
    expect(hasil.kenaikan).toEqual({ jenis: "MAKSIMAL" });
    expect(hasil.siap).toBe(false);
  });
});

describe("carry_over (SDLC section 7)", () => {
  it("layak naik pangkat → surplus ditabung", () => {
    const hasil = kelayakanKenaikan({
      jenjang: "AHLI_PERTAMA",
      golongan: "III/a",
      ak_kumulatif: 60,
      masaKerjaTahun: 2,
      predikatTerakhir: "BAIK",
      m: MASTER_FIXTURE,
    });
    expect(hasil.kenaikan.jenis).toBe("PANGKAT");
    expect(hasil.carry_over).toBe(10);
  });

  it("layak naik jenjang → hangus 0", () => {
    const hasil = kelayakanKenaikan({
      jenjang: "AHLI_PERTAMA",
      golongan: "III/b",
      ak_kumulatif: 120,
      masaKerjaTahun: 2,
      predikatTerakhir: "BAIK",
      m: MASTER_FIXTURE,
    });
    expect(hasil.kenaikan.jenis).toBe("PANGKAT_DAN_JENJANG");
    expect(hasil.carry_over).toBe(0);
  });

  it("belum cukup → seluruh saldo dibawa utuh", () => {
    const hasil = kelayakanKenaikan({
      jenjang: "AHLI_PERTAMA",
      golongan: "III/a",
      ak_kumulatif: 30,
      masaKerjaTahun: 2,
      predikatTerakhir: "BAIK",
      m: MASTER_FIXTURE,
    });
    expect(hasil.carry_over).toBe(30);
  });

  it("MAKSIMAL → 0", () => {
    const hasil = kelayakanKenaikan({
      jenjang: "AHLI_UTAMA",
      golongan: "IV/e",
      ak_kumulatif: 999,
      masaKerjaTahun: 10,
      predikatTerakhir: "SANGAT_BAIK",
      m: MASTER_FIXTURE,
    });
    expect(hasil.carry_over).toBe(0);
  });
});