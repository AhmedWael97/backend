<?php

namespace App\Http\Requests\Domain;

use Illuminate\Foundation\Http\FormRequest;

class StoreExclusionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', 'in:ip,cookie,user_agent'],
            'value' => ['nullable', 'string', 'max:500'],
            'pattern' => ['nullable', 'string', 'max:500'], // frontend alias for value
        ];
    }
}
