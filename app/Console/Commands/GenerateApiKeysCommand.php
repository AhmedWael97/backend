<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateApiKeysCommand extends Command
{
    protected $signature = 'app:generate-api-keys {--show : Print keys without writing to .env}';
    protected $description = 'Generate the APP_PUBLIC_KEY and APP_SECRET_KEY used by the frontend.';

    public function handle(): int
    {
        $publicKey = 'pk_' . Str::random(32);
        $secretKey = 'sk_' . Str::random(48);

        if ($this->option('show')) {
            $this->table(['Key', 'Value'], [
                ['APP_PUBLIC_KEY', $publicKey],
                ['APP_SECRET_KEY', $secretKey],
            ]);
            $this->newLine();
            $this->line('<comment>These were NOT written to .env. Copy them manually.</comment>');
            return self::SUCCESS;
        }

        $envPath = base_path('.env');

        if (!file_exists($envPath)) {
            $this->error('.env file not found. Run: cp .env.example .env');
            return self::FAILURE;
        }

        $env = file_get_contents($envPath);

        $env = $this->setOrAppend($env, 'APP_PUBLIC_KEY', $publicKey);
        $env = $this->setOrAppend($env, 'APP_SECRET_KEY', $secretKey);

        file_put_contents($envPath, $env);

        $this->info('Keys written to .env:');
        $this->table(['Key', 'Value'], [
            ['APP_PUBLIC_KEY', $publicKey],
            ['APP_SECRET_KEY', $secretKey],
        ]);
        $this->newLine();
        $this->warn('Copy both values to your frontend .env as API_PUBLIC_KEY and API_SECRET_KEY.');

        return self::SUCCESS;
    }

    private function setOrAppend(string $env, string $key, string $value): string
    {
        if (preg_match("/^{$key}=.*/m", $env)) {
            return preg_replace("/^{$key}=.*/m", "{$key}={$value}", $env);
        }

        return rtrim($env) . "\n{$key}={$value}\n";
    }
}
