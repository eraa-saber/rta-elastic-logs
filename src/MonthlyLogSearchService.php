<?php

namespace Rta\ElasticLogs;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Illuminate\Support\Carbon;

/**
 * Shared behaviour for log stores kept in one index per month —
 * "{prefix}-2026.08", "{prefix}-2026.09", ... — so a whole month can be dropped
 * with a single index delete instead of a delete_by_query over the full history.
 * Reads always span the "{prefix}-*" wildcard, so search behaves as if it were
 * still a single index.
 *
 * A subclass supplies the config key holding its prefix, its mapping, and the
 * fields each document carries.
 */
abstract class MonthlyLogSearchService
{
    protected Client $client;

    /** Base name; the real indices are "{prefix}-YYYY.MM". */
    protected string $indexPrefix;

    /** Config key under elasticsearch.* holding this store's index prefix. */
    abstract protected function indexConfigKey(): string;

    /** Field mapping applied to every monthly index through the template. */
    abstract public function mapping(): array;

    /** Field carrying the document's own timestamp — decides which month it lands in. */
    protected function timestampField(): string
    {
        return 'created_at';
    }

    public function __construct()
    {
        $builder = ClientBuilder::create()->setHosts(config('elasticsearch.hosts'));

        if (config('elasticsearch.username')) {
            $builder->setBasicAuthentication(config('elasticsearch.username'), config('elasticsearch.password'));
        }

        if ($caCert = config('elasticsearch.ca_cert')) {
            $builder->setCABundle($caCert);
        } elseif (! config('elasticsearch.ssl_verify')) {
            $builder->setSSLVerification(false);
        }

        $this->client = $builder->build();
        $this->indexPrefix = rtrim((string) config('elasticsearch.' . $this->indexConfigKey()), '-');

        if ($this->indexPrefix === '') {
            throw new \RuntimeException(
                "Missing index prefix: add '" . $this->indexConfigKey() . "' to config/elasticsearch.php"
            );
        }
    }

    /**
     * Index holding the logs of the month $date falls in. Null means the current month.
     */
    public function indexFor($date = null): string
    {
        return $this->indexPrefix . '-' . $this->toCarbon($date)->format('Y.m');
    }

    /**
     * Wildcard every read spans — all monthly indices at once.
     */
    public function searchIndex(): string
    {
        return $this->indexPrefix . '-*';
    }

    public function templateName(): string
    {
        return $this->indexPrefix . '-template';
    }

    /**
     * Registers the template that gives every "{prefix}-YYYY.MM" index the mapping.
     * With it in place a new month's index is auto-created, correctly mapped, on the
     * first write of that month — nothing has to run at the turn of the month.
     */
    public function putIndexTemplate(): void
    {
        $this->client->indices()->putIndexTemplate([
            'name' => $this->templateName(),
            'body' => [
                'index_patterns' => [$this->searchIndex()],
                'template' => ['mappings' => $this->mapping()],
            ],
        ]);
    }

    /**
     * Ensures the template exists and creates the monthly index for $date up front.
     *
     * @return bool true when the monthly index was created by this call.
     */
    public function createIndexIfMissing($date = null): bool
    {
        $this->putIndexTemplate();

        $index = $this->indexFor($date);

        if ($this->client->indices()->exists(['index' => $index])->asBool()) {
            return false;
        }

        $this->client->indices()->create([
            'index' => $index,
            'body' => ['mappings' => $this->mapping()],
        ]);

        return true;
    }

    /**
     * Existing monthly indices, oldest first.
     *
     * @return array<int, string>
     */
    public function monthlyIndices(): array
    {
        $indices = $this->client->indices()->get([
            'index' => $this->searchIndex(),
            'ignore_unavailable' => true,
        ])->asArray();

        $names = array_keys($indices);
        sort($names);

        return $names;
    }

    /**
     * Drops a whole month in one shot — the point of the monthly layout.
     * $month accepts '2026-07', '2026.07', a Carbon instance, ...
     *
     * @return bool false when that month has no index.
     */
    public function deleteMonth($month): bool
    {
        $index = $this->indexFor($month);

        if (! $this->client->indices()->exists(['index' => $index])->asBool()) {
            return false;
        }

        $this->client->indices()->delete(['index' => $index]);

        return true;
    }

    /**
     * Drops every monthly index older than $months whole months back — the retention
     * sweep. Returns the names of the indices removed.
     *
     * @return array<int, string>
     */
    public function deleteMonthsOlderThan(int $months): array
    {
        $cutoff = $this->indexFor(Carbon::now()->subMonthsNoOverflow($months)->startOfMonth());
        $deleted = [];

        foreach ($this->monthlyIndices() as $index) {
            if (strcmp($index, $cutoff) >= 0) {
                continue;
            }

            $this->client->indices()->delete(['index' => $index]);
            $deleted[] = $index;
        }

        return $deleted;
    }

    /**
     * Indexes a model row under a stable id, so re-running a backfill overwrites
     * rather than duplicates.
     *
     * @param string $source 'live' or 'archive' — the table the row currently lives in.
     */
    public function index(object $log, string $source): void
    {
        $this->client->index([
            'index' => $this->indexFor($log->{$this->timestampField()}),
            'id' => $source . '-' . $log->id,
            'body' => $this->toDocument($log, $source),
        ]);
    }

    public function bulkIndex(iterable $logs, string $source): void
    {
        $body = [];

        foreach ($logs as $log) {
            $body[] = ['index' => [
                '_index' => $this->indexFor($log->{$this->timestampField()}),
                '_id' => $source . '-' . $log->id,
            ]];
            $body[] = $this->toDocument($log, $source);
        }

        if (empty($body)) {
            return;
        }

        $this->client->bulk(['body' => $body]);
    }

    /**
     * $timestamp tells us which month's index holds the document. Without it the id is
     * hunted down across every month, which still works but costs a delete_by_query.
     */
    public function delete(string $source, int $logId, $timestamp = null): void
    {
        $id = $source . '-' . $logId;

        if (empty($timestamp)) {
            $this->client->deleteByQuery([
                'index' => $this->searchIndex(),
                'ignore_unavailable' => true,
                'body' => ['query' => ['ids' => ['values' => [$id]]]],
            ]);

            return;
        }

        try {
            $this->client->delete([
                'index' => $this->indexFor($timestamp),
                'id' => $id,
            ]);
        } catch (ClientResponseException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
    }

    /**
     * Free-text search across every month.
     */
    public function search(string $query, int $size = 50): array
    {
        $result = $this->client->search([
            'index' => $this->searchIndex(),
            'ignore_unavailable' => true,
            'body' => [
                'size' => $size,
                'query' => [
                    'multi_match' => [
                        'query' => $query,
                        'fields' => $this->searchableFields(),
                    ],
                ],
            ],
        ]);

        return $result->asArray();
    }

    /** Fields a free-text query is matched against. */
    abstract protected function searchableFields(): array;

    /** Turns a model row into the document stored in Elasticsearch. */
    abstract public function toDocument(object $log, string $source): array;

    /**
     * '2026-07', '2026.07', a Carbon/DateTime, or null for the current month.
     */
    protected function toCarbon($date): Carbon
    {
        if (empty($date)) {
            return Carbon::now();
        }

        if ($date instanceof \DateTimeInterface) {
            return Carbon::instance($date);
        }

        $value = str_replace('.', '-', (string) $date);

        // A bare "YYYY-MM" is spelled out rather than left to the date parser.
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $value, $m)) {
            return Carbon::create((int) $m[1], (int) $m[2], 1);
        }

        return Carbon::parse($value);
    }

    /**
     * Normalises a value that may be a Carbon, a raw SQL datetime string, or null.
     * Raw strings are parsed rather than passed through: "2026-04-19 15:06:27.000"
     * is not a format Elasticsearch's date type accepts.
     */
    protected function toIso($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        try {
            return Carbon::parse((string) $value)->toIso8601String();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Keeps arrays/objects storable in a text field. */
    protected function toText($value): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
