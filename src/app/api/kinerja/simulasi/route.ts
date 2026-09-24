import { NextRequest } from "next/server";
import { inputKinerjaSchema } from "../../schemas";
import { fail, ok } from "../../respond";
import { simulasiKinerja } from "@/lib/services/kinerja";

export async function POST(req: NextRequest) {
    try {
        const parsed = inputKinerjaSchema.safeParse(await req.json());
        if (!parsed.success) return fail(parsed.error);
        const hasil = await simulasiKinerja(parsed.data);
        return ok(hasil, 200);
    } catch (error) {
        return fail(error);
    }
}