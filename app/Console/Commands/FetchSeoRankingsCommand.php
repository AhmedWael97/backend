<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\SeoKeyword;
use App\Models\SeoRanking;
use App\Services\SerperService;
use Illuminate\Console\Command;

class FetchSeoRankingsCommand extends Command
{
    protected $signature = 'eye:fetch-seo-rankings {--domain=}';

    protected $description = 'Query real Google positions (via Serper.dev) for every tracked SEO keyword and record today\'s ranking.';

    public function handle(SerperService $serper): int
    {
        if (!$serper->configured()) {
            $this->error('SERPER_API_KEY is not set — nothing to do.');
            return self::FAILURE;
        }

        $domainIds = SeoKeyword::query()
            ->when($this->option('domain'), fn ($q) => $q->where('domain_id', (int) $this->option('domain')))
            ->distinct()
            ->pluck('domain_id');

        $ok = 0;
        $failed = 0;

        foreach ($domainIds as $domainId) {
            $domain = Domain::find($domainId);
            if (!$domain) {
                continue;
            }
            $host = preg_replace('#^https?://#', '', $domain->domain);

            $keywords = SeoKeyword::where('domain_id', $domainId)->get();
            foreach ($keywords as $kw) {
                try {
                    $organic = $serper->organicResults($kw->keyword);
                    $position = $serper->findPosition($organic, $host);

                    SeoRanking::updateOrCreate(
                        ['domain_id' => $domainId, 'keyword' => $kw->keyword, 'date' => now()->toDateString()],
                        ['position' => $position, 'url' => $position ? ($organic[$position - 1]['link'] ?? null) : null]
                    );
                    $ok++;
                } catch (\Throwable $e) {
                    report($e);
                    $failed++;
                }
                // Be polite to the API — small gap between calls.
                usleep(300_000);
            }
        }

        $this->info("Fetched rankings for {$ok} keyword(s), {$failed} failed.");
        return self::SUCCESS;
    }
}
