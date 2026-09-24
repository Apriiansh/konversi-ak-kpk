import { NextResponse } from "next/server";
import { getAllJenjang, getJenjangByKode,updateJenjang,createJenjang } from "@/lib/services/master";


// Select

export async function GET(request: Request) {
  try {
    const { searchParams } = new URL(request.url);
    const kode = searchParams.get("kode");

    if (kode) {
      const data = await getJenjangByKode(kode);

      if (!data) {
        return NextResponse.json(
          { message: "Jenjang tidak ditemukan" },
          { status: 404 }
        );
      }

      return NextResponse.json(data);
    }

    const data = await getAllJenjang();

    return NextResponse.json(data);
  } catch (error) {
    return NextResponse.json(
      {
        message: "Gagal mengambil data master jenjang",
        error: String(error),
      },
      { status: 500 }
    );
  }
}


// Edit
export async function PUT(request: Request) {
  try {
    const body = await request.json();

    const {
      kode,
      nama,
      koefisienTahunan,
      targetAkKenaikanPangkat,
      targetAkKenaikanJenjang,
    } = body;

    const data = await updateJenjang(
      kode,
      nama,
      koefisienTahunan,
      targetAkKenaikanPangkat,
      targetAkKenaikanJenjang,
    );

    if (!data) {
      return NextResponse.json(
        {
          message: "Jenjang tidak ditemukan",
        },
        { status: 404 },
      );
    }

    return NextResponse.json({
      message: "Data jenjang berhasil diperbarui",
      data,
    });
  } catch (error) {
    console.error("UPDATE JENJANG ERROR:", error);

    return NextResponse.json(
      {
        message: "Gagal memperbarui data jenjang",
      },
      { status: 500 },
    );
  }
}
// Create
export async function POST(request: Request) {
  try {
    const body = await request.json();

    const {
      kode,
      nama,
      koefisienTahunan,
      targetAkKenaikanPangkat,
      targetAkKenaikanJenjang,
    } = body;

    // Validasi wajib diisi
    if (!kode ||!nama || !koefisienTahunan || !targetAkKenaikanPangkat || !targetAkKenaikanJenjang) {
      return NextResponse.json(
        {
          message: "Semua field wajib diisi",
        },
        { status: 400 },
      );
    }

    // Validasi tipe data
    if (
      typeof kode !== "string" ||
      typeof nama !== "string" ||
      typeof koefisienTahunan !== "number" ||
      typeof targetAkKenaikanPangkat !== "number" ||
      typeof targetAkKenaikanJenjang !== "number"
    ) {
      return NextResponse.json(
        {
          message: "Format data tidak valid",
        },
        { status: 400 },
      );
    }

    const data = await createJenjang(
      kode,
      nama,
      koefisienTahunan,
      targetAkKenaikanPangkat,
      targetAkKenaikanJenjang,
    );

    return NextResponse.json(
      {
        message: "Data jenjang berhasil ditambahkan",
        data,
      },
      { status: 201 },
    );
  } catch (error) {
    console.error("TAMBAH JENJANG ERROR:", error);

    return NextResponse.json(
      {
        message: "Gagal menambahkan data jenjang",
      },
      { status: 500 },
    );
  }
}