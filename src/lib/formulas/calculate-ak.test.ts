import { describe, it, expect } from "vitest";
import {
  kinerjaPeriodik,
} from "./calculate-ak";
import { MASTER_FIXTURE } from "./master.fixture";

describe("kinerjaPeriodik", () => {
  it("bulan penuh 12, predikat BAIK", () => {
    expect(kinerjaPeriodik("AHLI_PERTAMA", "BAIK", 12, MASTER_FIXTURE)).toBe(12.5);
  });

  it("setengah tahun, predikat SANGAT_BAIK", () => {
    expect(kinerjaPeriodik("AHLI_UTAMA", "SANGAT_BAIK", 6, MASTER_FIXTURE)).toBe(37.5);
  });

  it("kuartal, predikat KURANG", () => {
    expect(kinerjaPeriodik("AHLI_MUDA", "KURANG", 3, MASTER_FIXTURE)).toBe(3.125);
  });

  it("bulan aktif 0 = 0", () => {
    expect(kinerjaPeriodik("AHLI_MADYA", "SANGAT_BAIK", 0, MASTER_FIXTURE)).toBe(0);
  });

  it("rounding ke 3 desimal", () => {
    expect(kinerjaPeriodik("AHLI_MADYA", "BUTUH_PERBAIKAN", 5, MASTER_FIXTURE)).toBe(11.719);
  });

  it("predikat SANGAT_KURANG tahun penuh (12 bulan)", () => {
    expect(kinerjaPeriodik("AHLI_UTAMA", "SANGAT_KURANG", 12, MASTER_FIXTURE)).toBe(12.5);
  });
});