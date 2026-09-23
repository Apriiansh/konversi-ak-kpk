<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePengajuanPendidikanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'jenjang_pendidikan' => 'required|in:D3,S1,S2,S3',
            'program_studi'      => 'required|string|max:150',
            'jurusan'            => 'required|string|max:150',
            'nama_institusi'     => 'required|string|max:150',
            'tahun_lulus'        => 'required|integer|min:1980|max:' . (date('Y') + 1),
            'file_ijazah'        => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120', // Maks 5MB
            'file_bukti_bkn'     => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120', // Wajib bukti pengajuan BKN
        ];
    }

    public function messages(): array
    {
        return [
            'file_ijazah.required'    => 'Dokumen Ijazah / SKL wajib diunggah.',
            'file_bukti_bkn.required' => 'Surat bukti pengajuan / persetujuan BKN wajib diunggah sesuai regulasi.',
            'jenjang_pendidikan.in'   => 'Jenjang pendidikan yang dapat diajukan adalah D3, S1, S2, atau S3.',
        ];
    }
}
