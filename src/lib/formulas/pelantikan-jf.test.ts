import { describe, it, expect } from "vitest";
import { pakPelantikanJF } from "./pelantikan-jf";
import { MASTER_FIXTURE } from "./master.fixture";

describe("pakPelantikanJF", () => {
  it("Pelaksana golongan tinggi (III/d) = tabel AK flat 100", () => {
    expect(pakPelantikanJF({
      jabatanAsal: "PELAKSANA",
      golongan: "III/d",
      masaKerjaTahun: 5,
      masaKerjaBulan: 6,
      predikat: "BAIK",
    }, MASTER_FIXTURE)).toBe(100);
  });

  it("Pelaksana golongan tinggi (III/c) = flat 100", () => {
    expect(pakPelantikanJF({
      jabatanAsal: "PELAKSANA",
      golongan: "III/c",
      masaKerjaTahun: 3,
      masaKerjaBulan: 0,
      predikat: "BAIK",
    }, MASTER_FIXTURE)).toBe(100);
  });

  it("Pelaksana golongan III/b dihitung dari masa kepangkatan", () => {
    expect(pakPelantikanJF({
      jabatanAsal: "PELAKSANA",
      golongan: "III/b",
      masaKerjaTahun: 5,
      masaKerjaBulan: 6,
      predikat: "BAIK",
    }, MASTER_FIXTURE)).toBe(68.75);
  });

  it("contoh panduan: Pelaksana III/a, 3 tahun 5 bulan, Baik", () => {
    expect(pakPelantikanJF({
      jabatanAsal: "PELAKSANA",
      golongan: "III/a",
      masaKerjaTahun: 3,
      masaKerjaBulan: 5,
      predikat: "BAIK",
    }, MASTER_FIXTURE)).toBe(42.708);
  });

  it("predikat SANGAT_BAIK mempengaruhi hasil (×1,5)", () => {
    expect(pakPelantikanJF({
      jabatanAsal: "PELAKSANA",
      golongan: "III/a",
      masaKerjaTahun: 2,
      masaKerjaBulan: 0,
      predikat: "SANGAT_BAIK",
    }, MASTER_FIXTURE)).toBe(37.5);
  });

  it("contoh panduan: Pengawas III/d, 2 tahun, Baik + AK Dasar", () => {
    expect(pakPelantikanJF({
      jabatanAsal: "PENGAWAS",
      golongan: "III/d",
      masaKerjaTahun: 2,
      masaKerjaBulan: 0,
      predikat: "BAIK",
      akDasar: 100,
    }, MASTER_FIXTURE)).toBe(150);
  });

  it("pangkat puncak & masa > 3 tahun dibatasi maksimal 3 tahun", () => {
    expect(pakPelantikanJF({
      jabatanAsal: "PENGAWAS",
      golongan: "III/d",
      masaKerjaTahun: 6,
      masaKerjaBulan: 0,
      predikat: "BAIK",
      akDasar: 100,
      pangkatPuncak: true,
    }, MASTER_FIXTURE)).toBe(175);
  });

  it("tanpa pangkat puncak memakai masa kepangkatan penuh", () => {
    expect(pakPelantikanJF({
      jabatanAsal: "PENGAWAS",
      golongan: "III/d",
      masaKerjaTahun: 6,
      masaKerjaBulan: 0,
      predikat: "BAIK",
      akDasar: 100,
    }, MASTER_FIXTURE)).toBe(250);
  });

  it("Administrator memakai jenjang Ahli Madya (koef 37,5)", () => {
    expect(pakPelantikanJF({
      jabatanAsal: "ADMINISTRATOR",
      golongan: "IV/a",
      masaKerjaTahun: 4,
      masaKerjaBulan: 0,
      predikat: "BAIK",
    }, MASTER_FIXTURE)).toBe(150);
  });

  it("akDasar default 0", () => {
    expect(pakPelantikanJF({
      jabatanAsal: "PELAKSANA",
      golongan: "III/b",
      masaKerjaTahun: 4,
      masaKerjaBulan: 0,
      predikat: "BAIK",
    }, MASTER_FIXTURE)).toBe(50);
  });
});