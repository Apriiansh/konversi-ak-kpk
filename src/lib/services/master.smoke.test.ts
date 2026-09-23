import { describe, it, expect } from "vitest";
import { getMasterKonversi } from "./master";
import { MASTER_FIXTURE } from "../formulas/master.fixture";

describe.skipIf(!process.env.DATABASE_URL)(
  "getMasterKonversi (integrasi DB — butuh DATABASE_URL)",
  () => {
    it("menghasilkan master yang identik dengan fixture (seed schema)", async () => {
      const master = await getMasterKonversi();
      expect(master).toEqual(MASTER_FIXTURE);
    });
  },
);