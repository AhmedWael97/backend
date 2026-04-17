<?php

namespace App\Console\Commands;

use App\Models\Domain;
use Illuminate\Console\Command;

class CleanupExpiredTokensCommand extends Command
{
    protected $signature = 'eye:cleanup-tokens';
    protected $description = 'Nullify previous_script_token on domains where rotation grace period has expired.';

    public function handle(): void
    {
        $count = Domain::whereNotNull('previous_script_token')
            ->whereNotNull('token_rotated_at')
            ->where('token_rotated_at', '<', now()->subHours(1))
            ->update([
                'previous_script_token' => null,
                'token_rotated_at' => null,
            ]);

        $this->line("Cleared {$count} expired grace-period tokens.");
    }
}
