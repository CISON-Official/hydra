<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCertificateRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:pdf|max:10240', // Max 10MB PDF
            'holder_email' => 'required|email|max:255',
            'holder_name' => 'required|string|max:255',
            'certificate_title' => 'required|string|max:255',
            'expires_at' => 'required|date|after:now',
            'regenerate_qr' => 'boolean',
        ];
    }
}
