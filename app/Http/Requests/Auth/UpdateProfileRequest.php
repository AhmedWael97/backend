<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'locale' => ['sometimes', 'string', 'in:en,ar'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'appearance' => ['sometimes', 'string', 'in:light,dark,system'],
        ];
    }
}
