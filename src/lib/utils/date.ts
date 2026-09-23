export function formatDate(date: Date) {
  const years = date.getFullYear();
  const months = String(date.getMonth() + 1).padStart(2, "0");
  const days = String(date.getDate()).padStart(2, "0");

  return `${years}-${months}-${days}`;
}

export function parseISODate(value: string) {
  const [year, month, day] = value.split("-").map(Number);
  return new Date(year, month - 1, day);
}

export function dateDifference(start: Date, end: Date) {
  let years = end.getFullYear() - start.getFullYear();
  let months = end.getMonth() - start.getMonth();
  let days = end.getDate() - start.getDate();

  let borrowYear = end.getFullYear();
  let borrowMonth = end.getMonth();

  while (days < 0) {
    months--;
    borrowMonth--;

    if (borrowMonth < 0) {
      borrowMonth = 11;
      borrowYear--;
    }

    const daysInMonth = new Date(borrowYear, borrowMonth + 1, 0).getDate();

    days += daysInMonth;
  }

  if (months < 0) {
    years--;
    months += 12;
  }

  return {
    years,
    months,
    days,
  };
}

export function addDateDifference(
  start: Date,
  years: number,
  months: number,
  days: number,
) {
  const result = new Date(start);

  result.setFullYear(result.getFullYear() + years);

  const originalDate = result.getDate();

  result.setDate(1);

  result.setMonth(result.getMonth() + months);

  const daysInMonth = new Date(
    result.getFullYear(),
    result.getMonth() + 1,
    0,
  ).getDate();

  result.setDate(Math.min(originalDate, daysInMonth));

  result.setDate(result.getDate() + days);

  return result;
}

export function getTerhitungMulai(startDate: Date, today: Date = new Date()) {
  return dateDifference(startDate, today);
}

export function parseMasaKerja(value: string) {
  const match = value.match(/(\d+)\s*Tahun\s+(\d+)\s*Bulan\s+(\d+)\s*hari/i);

  if (!match) throw new Error("Format masa kerja tidak valid");

  return {
    years: Number(match[1]),
    months: Number(match[2]),
    days: Number(match[3]),
  };
}

export function getTerhitungMulaiReverse(startDate: Date, masaKerja: string) {
  const { years, months, days } = parseMasaKerja(masaKerja);

  return addDateDifference(startDate, years, months, days);
}

// const mulai = new Date(2004, 1, 27);
// const masaKerja = getTerhitungMulai(mulai);

// const strMasaKerja =
//   `${masaKerja.years} Tahun ` +
//   `${masaKerja.months} Bulan ` +
//   `${masaKerja.days} hari`;

// const strMasaKerjaReverse = getTerhitungMulaiReverse(mulai, strMasaKerja);

// console.log(strMasaKerja);
// console.log(formatDate(strMasaKerjaReverse));
