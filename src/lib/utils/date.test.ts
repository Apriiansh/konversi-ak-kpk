import { describe, it, expect } from "vitest";
import {
  formatDate,
  dateDifference,
  addDateDifference,
  parseMasaKerja,
  getTerhitungMulai,
  getTerhitungMulaiReverse,
} from "./date";

describe("formatDate", () => {
  it("format ke YYYY-MM-DD", () => {
    expect(formatDate(new Date(2024, 0, 5))).toBe("2024-01-05");
  });
});

describe("dateDifference", () => {
  it("tanggal sama = 0", () => {
    expect(dateDifference(new Date(2024, 0, 1), new Date(2024, 0, 1)))
      .toEqual({ years: 0, months: 0, days: 0 });
  });

  it("borrow hari saat end date lebih kecil", () => {
    // start 31 Jan, end 1 Mar -> harus tetep valid (days >= 0)
    const result = dateDifference(new Date(2024, 0, 31), new Date(2024, 2, 1));
    expect(result.days).toBeGreaterThanOrEqual(0);
    expect(result.months).toBeGreaterThanOrEqual(0);
  });

  it("1 tahun persis", () => {
    expect(dateDifference(new Date(2020, 5, 15), new Date(2021, 5, 15)))
      .toEqual({ years: 1, months: 0, days: 0 });
  });
});

describe("addDateDifference", () => {
  it("tambah 1 bulan dari 31 Jan -> clamp ke akhir Feb", () => {
    const result = addDateDifference(new Date(2024, 0, 31), 0, 1, 0);
    expect(formatDate(result)).toBe("2024-02-29"); // 2024 kabisat
  });

  it("inverse dari dateDifference", () => {
    const start = new Date(2004, 1, 27);
    const end = new Date(2026, 8, 23);
    const diff = dateDifference(start, end);
    const rebuilt = addDateDifference(start, diff.years, diff.months, diff.days);
    expect(formatDate(rebuilt)).toBe(formatDate(end));
  });
});

describe("parseMasaKerja", () => {
  it("parse string format Indonesia", () => {
    expect(parseMasaKerja("22 Tahun 6 Bulan 27 hari"))
      .toEqual({ years: 22, months: 6, days: 27 });
  });

  it("throw kalau format salah", () => {
    expect(() => parseMasaKerja("invalid")).toThrow();
  });
});

describe("getTerhitungMulai", () => {
  it("pakai today yg di-fix (bisa diprediksi)", () => {
    const start = new Date(2004, 1, 27);
    const today = new Date(2026, 8, 23);
    const result = getTerhitungMulai(start, today);
    expect(result.years).toBe(22);
  });
});

describe("getTerhitungMulaiReverse", () => {
  it("round-trip dengan getTerhitungMulai", () => {
    const start = new Date(2004, 1, 27);
    const today = new Date(2026, 8, 23);
    const diff = getTerhitungMulai(start, today);
    const str = `${diff.years} Tahun ${diff.months} Bulan ${diff.days} hari`;
    const result = getTerhitungMulaiReverse(start, str);
    expect(formatDate(result)).toBe(formatDate(today));
  });
});