<?php

namespace App\Console\Commands;

use App\Services\ClickHouseService;
use Illuminate\Console\Command;

class ClickHouseMigrateCommand extends Command
{
    protected $signature = 'eye:clickhouse-migrate {--fresh : Drop and recreate all tables}';

    protected $description = 'Create ClickHouse tables for EYE analytics (events, sessions, pipeline_events, ux_events, custom_events, replay_events).';

    public function handle(ClickHouseService $ch): int
    {
        if ($this->option('fresh')) {
            $this->warn('Dropping all ClickHouse tables...');
            foreach (['replay_events', 'custom_events', 'ux_events', 'pipeline_events', 'sessions', 'events'] as $table) {
                $ch->statement("DROP TABLE IF EXISTS {$table}");
                $this->line("  Dropped {$table}");
            }
        }

        $this->info('Running ClickHouse migrations...');

        $tables = $this->definitions();

        foreach ($tables as $name => $ddl) {
            $ch->statement($ddl);
            $this->line("  ✓ {$name}");
        }

        $this->info('ClickHouse migration complete.');

        return self::SUCCESS;
    }

    private function definitions(): array
    {
        return [
            'events' => "
                CREATE TABLE IF NOT EXISTS events (
                    domain_id       UInt32,
                    session_id      String,
                    visitor_id      String,
                    type            LowCardinality(String),
                    url             String,
                    referrer        String,
                    title           String,
                    props           String,
                    screen_w        UInt16,
                    screen_h        UInt16,
                    duration        UInt32,
                    country         LowCardinality(String),
                    region          LowCardinality(String),
                    city            String,
                    os              LowCardinality(String),
                    browser         LowCardinality(String),
                    device_type     LowCardinality(String),
                    ip_hash         String,
                    utm_source      String,
                    utm_medium      String,
                    utm_campaign    String,
                    utm_term        String,
                    utm_content     String,
                    ts              DateTime DEFAULT now()
                ) ENGINE = MergeTree()
                PARTITION BY toYYYYMM(ts)
                ORDER BY (domain_id, ts, session_id)
                TTL ts + INTERVAL 365 DAY
                SETTINGS index_granularity = 8192
            ",

            'sessions' => "
                CREATE TABLE IF NOT EXISTS sessions (
                    domain_id           UInt32,
                    session_id          String,
                    visitor_id          String,
                    duration_seconds    UInt32,
                    page_count          UInt16,
                    country             LowCardinality(String),
                    device              LowCardinality(String),
                    browser             LowCardinality(String),
                    os                  LowCardinality(String),
                    entry_url           String,
                    exit_url            String,
                    utm_source          String,
                    utm_medium          String,
                    utm_campaign        String,
                    company_name        Nullable(String),
                    started_at          DateTime DEFAULT now()
                ) ENGINE = MergeTree()
                PARTITION BY toYYYYMM(started_at)
                ORDER BY (domain_id, started_at, session_id)
                TTL started_at + INTERVAL 365 DAY
                SETTINGS index_granularity = 8192
            ",

            'pipeline_events' => "
                CREATE TABLE IF NOT EXISTS pipeline_events (
                    domain_id       UInt32,
                    session_id      String,
                    pipeline_id     UInt32,
                    step_id         UInt32,
                    status          LowCardinality(String),
                    event_time      DateTime DEFAULT now()
                ) ENGINE = MergeTree()
                PARTITION BY toYYYYMM(event_time)
                ORDER BY (domain_id, pipeline_id, event_time)
                TTL event_time + INTERVAL 365 DAY
                SETTINGS index_granularity = 8192
            ",

            'ux_events' => "
                CREATE TABLE IF NOT EXISTS ux_events (
                    domain_id           UInt32,
                    session_id          String,
                    visitor_id          String,
                    type                LowCardinality(String),
                    url                 String,
                    element_selector    String,
                    details             String,
                    created_at          DateTime DEFAULT now()
                ) ENGINE = MergeTree()
                PARTITION BY toYYYYMM(created_at)
                ORDER BY (domain_id, created_at)
                TTL created_at + INTERVAL 365 DAY
                SETTINGS index_granularity = 8192
            ",

            'custom_events' => "
                CREATE TABLE IF NOT EXISTS custom_events (
                    domain_id   UInt32,
                    session_id  String,
                    visitor_id  String,
                    name        String,
                    props       String,
                    url         String,
                    ts          DateTime DEFAULT now()
                ) ENGINE = MergeTree()
                PARTITION BY toYYYYMM(ts)
                ORDER BY (domain_id, name, ts)
                TTL ts + INTERVAL 365 DAY
                SETTINGS index_granularity = 8192
            ",

            'replay_events' => "
                CREATE TABLE IF NOT EXISTS replay_events (
                    domain_id       UInt32,
                    session_id      String,
                    event_index     UInt32,
                    rrweb_type      UInt8,
                    data            String,
                    timestamp       DateTime DEFAULT now()
                ) ENGINE = MergeTree()
                PARTITION BY toYYYYMM(timestamp)
                ORDER BY (domain_id, session_id, event_index)
                TTL timestamp + INTERVAL 365 DAY
                SETTINGS index_granularity = 8192
            ",
        ];
    }
}
