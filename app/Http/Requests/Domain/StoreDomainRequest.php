<?php

namespace App\Http\Requests\Domain;

use App\Services\DomainGuard;
use Illuminate\Foundation\Http\FormRequest;

class StoreDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'domain' => [
                'required',
                'string',
                'max:253',
                function ($attribute, $value, $fail) {
                    if (!DomainGuard::isValidFormat((string) $value)) {
                        $fail('Enter a valid domain like example.com or www.example.com (no http://, no paths).');
                        return;
                    }
                    if (!DomainGuard::isResolvable((string) $value)) {
                        $fail("We couldn't find that domain online — double-check it's correct and already live before adding it.");
                    }
                },
            ],
            'settings' => ['sometimes', 'array'],
        ];
    }

    /**
     * People paste "https://example.com/pricing?x=1" — accept it and reduce to the
     * hostname rather than rejecting them over formatting.
     */
    protected function prepareForValidation(): void
    {
        if (!$this->has('domain')) {
            return;
        }

        $this->merge(['domain' => DomainGuard::normalize((string) $this->input('domain'))]);
    }
}
