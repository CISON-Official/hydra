<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FirstAdminCreateRequest extends FormRequest
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
            'admin_secret' => 'required|string',
        ];
    }
}