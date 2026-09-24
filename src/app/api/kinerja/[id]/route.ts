import { NextRequest } from "next/server";
import { perubahanKinerjaSchema, uuidSchema } from "../../schemas";
import { fail, ok } from "../../respond";
import { hapusKinerja, perbaruiKinerja } from "@/lib/services/kinerja";

export async function PUT(
  req: NextRequest,
  { params }: { params: Promise<{ id: string }> },
) {
    try {
        const { id } = await params;
        const idParsed = uuidSchema.safeParse(id);
        if (!idParsed.success) return fail(idParsed.error);

        const parsed = perubahanKinerjaSchema.safeParse(await req.json());
        if (!parsed.success) return fail(parsed.error);

        const hasil = await perbaruiKinerja(id, parsed.data);
        return ok(hasil);
    } catch (error) {
        return fail(error);
    }
}

export async function DELETE(
  _req: NextRequest,
  { params }: { params: Promise<{ id: string }> },
) {
    try {
        const { id } = await params;
        const idParsed = uuidSchema.safeParse(id);
        if (!idParsed.success) return fail(idParsed.error);

        const hasil = await hapusKinerja(id);
        return ok(hasil);
    } catch (error) {
        return fail(error);
    }
}