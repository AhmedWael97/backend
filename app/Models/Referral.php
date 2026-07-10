<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Referral extends Model
{
    protected $fillable = ['referrer_user_id', 'referred_user_id', 'status', 'rewarded_at'];

    protected function casts(): array
    {
        return ['rewarded_at' => 'datetime'];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    /** Credit whoever referred this signup, if the code resolves to a real, different user. */
    public static function maybeCreate(?string $code, User $newUser): void
    {
        $code = trim((string) $code);
        if ($code === '') {
            return;
        }
        $referrer = User::where('referral_code', $code)->first();
        if (!$referrer || $referrer->id === $newUser->id) {
            return;
        }

        self::create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $newUser->id,
            'status' => 'pending',
        ]);
    }
}
