import { describe, it, expect } from "vitest";
import { akPeningkatanPendidikan } from "./ak-ijazah";
import { MASTER_FIXTURE } from "./master.fixture";

describe("akPeningkatanPendidikan", () => {
  it("III/a (kebutuhan 50) → 25% = 12,5", () => {
    expect(akPeningkatanPendidikan("III/a", MASTER_FIXTURE, true)).toBe(12.5);
  });

  it("III/c (kebutuhan 100) → 25", () => {
    expect(akPeningkatanPendidikan("III/c", MASTER_FIXTURE, true)).toBe(25);
  });

  it("IV/d (kebutuhan 200) → 50", () => {
    expect(akPeningkatanPendidikan("IV/d", MASTER_FIXTURE, true)).toBe(50);
  });

  it("golongan puncak jenjang pakai kebutuhan kenaikan jenjang", () => {
    expect(akPeningkatanPendidikan("III/b", MASTER_FIXTURE, true)).toBe(25);
    expect(akPeningkatanPendidikan("III/d", MASTER_FIXTURE, true)).toBe(50);
  });

  it("tanpa perolehan pendidikan → 0", () => {
    expect(akPeningkatanPendidikan("III/a", MASTER_FIXTURE, false)).toBe(0);
    expect(akPeningkatanPendidikan("III/a", MASTER_FIXTURE)).toBe(0);
  });
});