<?php

namespace App\Http\Requests\Domain;

use Illuminate\Foundation\Http\FormRequest;

class StoreDomainRequest extends FormRequest
{
    /**
     * A hostname: one or more dot-separated labels ending in an alphabetic TLD.
     * Labels are 1–63 chars, alphanumeric, may contain inner hyphens.
     * Matches example.com / www.example.com / sub.example.co.uk — not IPs,
     * not localhost, not a bare word.
     */
    private const HOSTNAME = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:253', 'regex:' . self::HOSTNAME],
            'settings' => ['sometimes', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'domain.regex' => 'Enter a valid domain like example.com or www.example.com (no http://, no paths).',
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

        $domain = strtolower(trim((string) $this->input('domain')));
        $domain = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $domain); // scheme
        $domain = preg_replace('#^[^/@]*@#', '', $domain);              // userinfo
        $domain = explode('/', $domain, 2)[0];                          // path
        $domain = explode('?', $domain, 2)[0];                          // query
        $domain = explode('#', $domain, 2)[0];                          // fragment
        $domain = explode(':', $domain, 2)[0];                          // port
        $domain = rtrim($domain, '.');                                  // trailing dot

        $this->merge(['domain' => $domain]);
    }
}
