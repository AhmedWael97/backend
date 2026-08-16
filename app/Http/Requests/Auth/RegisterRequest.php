<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'domain' => ['required', 'string', 'max:255'],
            'locale' => ['sometimes', 'string', 'in:en,ar'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'referral_code' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }
}
