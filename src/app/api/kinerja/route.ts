import { NextRequest } from "next/server";
import { inputKinerjaSchema, tahunParamsSchema, uuidSchema } from "../schemas";
import { fail, ok } from "../respond";
import { getContextKinerja, simpanKinerja } from "@/lib/services/kinerja";

export async function GET(req: NextRequest) {
    try {
        const pegawaiId = uuidSchema.safeParse(req.nextUrl.searchParams.get("pegawaiId") ?? "");
        if (!pegawaiId.success) return fail(pegawaiId.error);

        const tahun = tahunParamsSchema.safeParse(req.nextUrl.searchParams.get("tahun") ?? "");
        if (!tahun.success) return fail(tahun.error);

        const ctx = await getContextKinerja(pegawaiId.data, tahun.data);
        return ok(ctx);
    } catch (error) {
        return fail(error);
    }
}

export async function POST(req: NextRequest) {
    try {
        const parsed = inputKinerjaSchema.safeParse(await req.json());
        if (!parsed.success) return fail(parsed.error);
        const hasil = await simpanKinerja(parsed.data);
        return ok(hasil, 201);
    } catch (error) {
        return fail(error);
    }
}