import { NextResponse } from "next/server";
import { getMasterKonversi } from "@/lib/services/master";

export async function GET() {
  try {
    console.log("1. Memanggil getMasterKonversi...");

    const data = await getMasterKonversi();

    console.log("2. Data berhasil diambil:", data);

    return NextResponse.json(data);
  } catch (error) {
    console.error("ERROR MASTER KONVERSI:", error);

    return NextResponse.json(
      {
        message: "Gagal mengambil data master konversi",
        error: String(error),
      },
      { status: 500 }
    );
  }
}