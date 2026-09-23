<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifikasiPengajuanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_valid' => 'required|boolean',
            'catatan'  => 'nullable|string|max:500|required_if:is_valid,false',
        ];
    }

    public function messages(): array
    {
        return [
            'is_valid.required'    => 'Status keabsahan dokumen wajib ditentukan.',
            'catatan.required_if'  => 'Catatan alasan penolakan wajib diisi jika dokumen dinilai tidak valid.',
        ];
    }
}
