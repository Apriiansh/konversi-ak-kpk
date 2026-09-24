import { NextResponse } from "next/server";
import { ZodError } from "zod";
import { isAppError } from "@/lib/errors";

export function ok<T>(data: T, status= 200) {
    return NextResponse.json({ data }, { status })
}

export function fail(error: unknown) {
    if (error instanceof ZodError) {
        return NextResponse.json({ error: "Validasi gagal", issues: error.flatten() }, { status: 400 })
    }

    if (isAppError(error)) {
        return NextResponse.json({ error: error.message }, { status: error.status })
    }

    return NextResponse.json({ error: "Terjadi kesalahan pada server" }, { status: 500 } )
}