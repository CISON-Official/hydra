<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EmergencyAdminCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email|max:255',
            'name' => 'required|string|max:255',
            'emergency_code' => 'required|string',
            'system_secret' => 'required|string',
        ];
    }
}