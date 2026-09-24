import z from "zod";

const PREDIKAT = [
  "SANGAT_BAIK",
  "BAIK",
  "BUTUH_PERBAIKAN",
  "KURANG",
  "SANGAT_KURANG",
] as const;
export const predikatSchema = z.enum(PREDIKAT);

export const inputKinerjaSchema = z.object({
  pegawaiId: z.uuid(),
  tahun: z.number().int().min(2000).max(2100),
  triwulan: z.union([z.literal(1), z.literal(2), z.literal(3), z.literal(4)]),
  predikat: predikatSchema,
  jumlahBulan: z.number().int().min(0).max(12).optional(),
  sumber: z.enum(["MANUAL", "IMPORT"]).optional()
});

export const perubahanKinerjaSchema = z.object({
    predikat: predikatSchema,
    jumlahBulan: z.number().int().min(0).max(12).optional(),
})

export const uuidSchema = z.uuid()
export const tahunParamsSchema = z.coerce.number().int().min(2000).max(2100)

export type InputKinerjaAPI = z.infer<typeof inputKinerjaSchema>