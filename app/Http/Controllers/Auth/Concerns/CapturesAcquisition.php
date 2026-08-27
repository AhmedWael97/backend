<?php

namespace App\Http\Controllers\Auth\Concerns;

/**
 * First-touch ad attribution, shared by every signup path.
 *
 * The email/password register endpoint reads utm_source/medium/campaign +
 * click_id straight off the request body (the form spreads the eye_acq cookie
 * the Next.js middleware set on first landing). OAuth cannot: the provider
 * callback is a fresh server-to-server hop, so neither the cookie nor the
 * original query string reaches us. Instead the frontend appends the same
 * values to the callback URL it passes as `?redirect=`, which rides through
 * Google's / Facebook's `state` param untouched — the trick already used for
 * `ref`. Without this, OAuth signups (the majority) land with a NULL
 * signup_utm_source and no campaign can ever be scored.
 */
trait CapturesAcquisition
{
    /**
     * Map an arbitrary key/value bag — a parsed redirect query string, or a
     * request body — onto the users table's signup_* columns.
     *
     * Values are truncated to each column's width: an over-long utm_campaign
     * from an ad platform would otherwise raise a 22001 and fail the signup
     * outright.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string|null>
     */
    protected function acquisitionAttributes(array $params): array
    {
        $clean = static function (mixed $value, int $max): ?string {
            $value = trim((string) (is_scalar($value) ? $value : ''));

            return $value === '' ? null : mb_substr($value, 0, $max);
        };

        return [
            'signup_utm_source' => $clean($params['utm_source'] ?? null, 100),
            'signup_utm_medium' => $clean($params['utm_medium'] ?? null, 100),
            'signup_utm_campaign' => $clean($params['utm_campaign'] ?? null, 255),
            'signup_click_id' => $clean($params['click_id'] ?? null, 255),
        ];
    }
}
