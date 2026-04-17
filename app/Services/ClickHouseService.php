<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Thin ClickHouse HTTP interface.
 *
 * Uses ClickHouse's native HTTP endpoint (port 8123).
 * All queries and inserts go through this service.
 */
class ClickHouseService
{
    private Client $http;
    private string $base;
    private string $db;

    public function __construct()
    {
        $this->base = sprintf(
            'http://%s:%s/',
            config('services.clickhouse.host', 'clickhouse'),
            config('services.clickhouse.port', 8123),
        );
        $this->db = config('services.clickhouse.database', 'eye_analytics');

        $this->http = new Client([
            'base_uri' => $this->base,
            'timeout' => 10,
            'auth' => [
                config('services.clickhouse.user', 'eye'),
                config('services.clickhouse.password', ''),
            ],
        ]);
    }

    /**
     * Execute a SELECT query and return rows as array of associative arrays.
     */
    public function select(string $sql, array $params = []): array
    {
        $sql = $this->bindParams($sql, $params) . ' FORMAT JSONEachRow';

        try {
            $response = $this->http->post('', [
                'query' => ['database' => $this->db],
                'body' => $sql,
                'http_errors' => true,
            ]);

            $lines = array_filter(explode("\n", (string) $response->getBody()));

            return array_map(fn($line) => json_decode($line, true), $lines);
        } catch (GuzzleException $e) {
            Log::error('ClickHouse select error', ['sql' => $sql, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Insert rows into a table using VALUES format.
     *
     * @param  array<array<string, mixed>>  $rows
     */
    public function insert(string $table, array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $columns = implode(', ', array_keys($rows[0]));
        $values = implode(', ', array_map(
            fn($row) => '(' . implode(', ', array_map([$this, 'quoteValue'], $row)) . ')',
            $rows
        ));

        $sql = "INSERT INTO {$table} ({$columns}) VALUES {$values}";

        try {
            $this->http->post('', [
                'query' => ['database' => $this->db],
                'body' => $sql,
                'http_errors' => true,
            ]);
        } catch (GuzzleException $e) {
            Log::error('ClickHouse insert error', ['table' => $table, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Insert rows using JSONEachRow format (more reliable for nested types).
     *
     * @param  array<array<string, mixed>>  $rows
     */
    public function insertJson(string $table, array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $body = implode("\n", array_map('json_encode', $rows));

        try {
            $this->http->post('', [
                'query' => [
                    'database' => $this->db,
                    'query' => "INSERT INTO {$table} FORMAT JSONEachRow",
                ],
                'body' => $body,
                'http_errors' => true,
            ]);
        } catch (GuzzleException $e) {
            Log::error('ClickHouse insertJson error', ['table' => $table, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Execute a DDL or non-SELECT statement.
     */
    public function execute(string $sql): void
    {
        try {
            $this->http->post('', [
                'query' => ['database' => $this->db],
                'body' => $sql,
                'http_errors' => true,
            ]);
        } catch (GuzzleException $e) {
            Log::error('ClickHouse execute error', ['sql' => $sql, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /** Alias for execute() — use for ALTER TABLE / TRUNCATE / DDL statements. */
    public function statement(string $sql): void
    {
        $this->execute($sql);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function bindParams(string $sql, array $params): string
    {
        foreach ($params as $key => $value) {
            $sql = str_replace(':' . $key, $this->quoteValue($value), $sql);
        }
        return $sql;
    }

    private function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            return "map(" . implode(', ', array_map(
                fn($k, $v) => $this->quoteValue((string) $k) . ', ' . $this->quoteValue((string) $v),
                array_keys($value),
                $value
            )) . ")";
        }
        // Escape single quotes by doubling them
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }
}
