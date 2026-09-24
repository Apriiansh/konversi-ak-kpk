import { listPegawai } from "@/lib/services/pegawai";
import { fail, ok } from "../respond";

export async function GET() {
    try {
        const pegawai = await listPegawai()
        return ok({pegawai}, 200)
    } catch (error) {
        return fail(error)
    }
}