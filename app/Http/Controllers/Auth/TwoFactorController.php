<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TotpVerifyRequest;
use App\Models\TotpBackupCode;
use App\Models\User;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorController extends Controller
{
    private Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    /**
     * Verify a TOTP challenge received during login (pre-auth).
     * The challenge token was issued by LoginController.
     */
    public function verifyChallenge(TotpVerifyRequest $request): JsonResponse
    {
        $challenge = $request->input('challenge');
        $cacheKey = "totp_challenge:{$challenge}";
        $userId = cache()->get($cacheKey);

        if (!$userId) {
            return $this->error('Invalid or expired challenge.', 422);
        }

        $user = User::find($userId);
        if (!$user) {
            return $this->error('User not found.', 404);
        }

        $code = $request->input('code');

        // Try TOTP code first
        $valid = $this->google2fa->verifyKey($user->totp_secret, $code, 1);

        // Try backup codes if TOTP fails
        if (!$valid) {
            $backupCode = TotpBackupCode::where('user_id', $user->id)
                ->whereNull('used_at')
                ->get()
                ->first(fn($bc) => Hash::check($code, $bc->code_hash));

            if ($backupCode) {
                $backupCode->update(['used_at' => now()]);
                $valid = true;
            }
        }

        if (!$valid) {
            return $this->error('Invalid verification code.', 422);
        }

        $user->update(['totp_last_used_at' => now()]);
        cache()->forget($cacheKey);

        $token = $user->createToken('api')->plainTextToken;

        return $this->success([
            'user' => $user->only(['id', 'name', 'email', 'locale', 'timezone', 'appearance', 'role', 'status', 'totp_enabled']),
            'token' => $token,
        ]);
    }

    /**
     * Generate a new TOTP secret and QR code for the authenticated user.
     */
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();
        $secret = $this->google2fa->generateSecretKey();

        // Store secret temporarily in cache until confirmed
        cache()->put("totp_setup:{$user->id}", $secret, now()->addMinutes(15));

        $otpauthUrl = $this->google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret,
        );

        $options = new QROptions(['outputType' => QRCode::OUTPUT_IMAGE_PNG, 'eccLevel' => QRCode::ECC_H]);
        $qrCode = (new QRCode($options))->render($otpauthUrl);

        return $this->success([
            'secret' => $secret,
            'qr_code_url' => $qrCode,
        ]);
    }

    /**
     * Confirm and enable TOTP after the user has scanned the QR code.
     */
    public function enable(TotpVerifyRequest $request): JsonResponse
    {
        $user = $request->user();
        $secret = cache()->get("totp_setup:{$user->id}");

        if (!$secret) {
            return $this->error('Setup session expired. Please restart.', 422);
        }

        $valid = $this->google2fa->verifyKey($secret, $request->input('code'), 1);
        if (!$valid) {
            return $this->error('Invalid verification code.', 422);
        }

        // Generate backup codes
        $backupCodes = [];
        TotpBackupCode::where('user_id', $user->id)->delete();
        for ($i = 0; $i < 8; $i++) {
            $plain = strtoupper(implode('-', str_split(bin2hex(random_bytes(3)), 3)));
            $backupCodes[] = $plain;
            TotpBackupCode::create([
                'user_id' => $user->id,
                'code_hash' => Hash::make($plain),
                'created_at' => now(),
            ]);
        }

        $user->update([
            'totp_secret' => $secret,
            'totp_enabled' => true,
            'totp_last_used_at' => now(),
        ]);

        cache()->forget("totp_setup:{$user->id}");

        return $this->success([
            'message' => '2FA enabled successfully.',
            'backup_codes' => $backupCodes,
        ]);
    }

    /**
     * GET /api/profile/two-factor/status
     */
    public function status(Request $request): JsonResponse
    {
        return $this->success(['enabled' => (bool) $request->user()->totp_enabled]);
    }

    /**
     * POST /api/profile/two-factor/enable  (password-gated setup init)
     * Verifies the user's password then returns the QR code + secret.
     */
    public function profileEnable(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string']);
        $user = $request->user();

        if (!\Illuminate\Support\Facades\Hash::check($request->input('password'), $user->password)) {
            return $this->error('Incorrect password.', 422);
        }

        $secret = $this->google2fa->generateSecretKey();
        cache()->put("totp_setup:{$user->id}", $secret, now()->addMinutes(15));

        $otpauthUrl = $this->google2fa->getQRCodeUrl(config('app.name'), $user->email, $secret);

        $options = new \chillerlan\QRCode\QROptions(['outputType' => QRCode::OUTPUT_IMAGE_PNG, 'eccLevel' => QRCode::ECC_H]);
        $qrCode = (new QRCode($options))->render($otpauthUrl);

        return $this->success(['qr_code' => $qrCode, 'secret' => $secret]);
    }

    /**
     * POST /api/profile/two-factor/confirm  (alias: confirms TOTP + enables)
     */
    public function profileConfirm(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string']);
        // Delegate to existing enable logic
        return $this->enable($request);
    }

    /**
     * POST /api/profile/two-factor/disable  (password-gated disable)
     */
    public function profileDisable(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string']);
        $user = $request->user();

        if (!\Illuminate\Support\Facades\Hash::check($request->input('password'), $user->password)) {
            return $this->error('Incorrect password.', 422);
        }

        if (!$user->totp_enabled) {
            return $this->error('2FA is not enabled.', 422);
        }

        $user->update(['totp_secret' => null, 'totp_enabled' => false, 'totp_last_used_at' => null]);
        TotpBackupCode::where('user_id', $user->id)->delete();

        return $this->success(['message' => '2FA disabled.']);
    }

    /**
     * Disable TOTP for the authenticated user (requires current TOTP code).
     */
    public function disable(TotpVerifyRequest $request): JsonResponse
    {
        $user = $request->user();
        $valid = $this->google2fa->verifyKey($user->totp_secret, $request->input('code'), 1);

        if (!$valid) {
            return $this->error('Invalid verification code.', 422);
        }

        $user->update([
            'totp_secret' => null,
            'totp_enabled' => false,
            'totp_last_used_at' => null,
        ]);
        TotpBackupCode::where('user_id', $user->id)->delete();

        return $this->success(['message' => '2FA disabled.']);
    }
}
