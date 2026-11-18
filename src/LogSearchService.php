<?php

namespace Rta\ElasticLogs;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Illuminate\Support\Carbon;

/**
 * Shared behaviour for a log store whose indices are split by time, as set by
 * the "rotation" config:
 *
 *   daily   -> "{prefix}-2026.11.18"
 *   monthly -> "{prefix}-2026.11"   (default)
 *   yearly  -> "{prefix}-2026"
 *   single  -> "{prefix}"
 *
 * Reads and the index template span both "{prefix}" and "{prefix}-*", so logs
 * written under an earlier rotation stay searchable after the setting changes.
 *
 * A subclass supplies the config key holding its prefix, its mapping, and the
 * fields each document carries.
 */
abstract class LogSearchService
{
    public const ROTATIONS = ['daily', 'monthly', 'yearly', 'single'];

    /** Date suffix appended to the prefix, per rotation. */
    protected const SUFFIX_FORMATS = [
        'daily' => 'Y.m.d',
        'monthly' => 'Y.m',
        'yearly' => 'Y',
    ];

    protected Client $client;

    /** Base name every index of this store starts with. */
    protected string $indexPrefix;

    protected string $rotation;

    /** Config key under elasticsearch.* holding this store's index prefix. */
    abstract protected function indexConfigKey(): string;

    /** Field mapping applied to every index of this store through the template. */
    abstract public function mapping(): array;

    /** Fields a free-text query is matched against. */
    abstract protected function searchableFields(): array;

    /** Turns a model row into the document stored in Elasticsearch. */
    abstract public function toDocument(object $log, string $source): array;

    /** Field carrying the document's own timestamp — decides which index it lands in. */
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

        $this->rotation = $this->resolveRotation();
    }

    /**
     * Per-store override from elasticsearch.rotations.{key}, else the global
     * elasticsearch.rotation, else monthly.
     */
    protected function resolveRotation(): string
    {
        $rotation = config('elasticsearch.rotations.' . $this->indexConfigKey())
            ?: config('elasticsearch.rotation')
            ?: 'monthly';

        $rotation = strtolower(trim((string) $rotation));

        if (! in_array($rotation, self::ROTATIONS, true)) {
            throw new \RuntimeException(
                "Invalid rotation '{$rotation}' for " . $this->indexConfigKey()
                . '. Use one of: ' . implode(', ', self::ROTATIONS)
            );
        }

        return $rotation;
    }

    public function rotation(): string
    {
        return $this->rotation;
    }

    public function isSingleIndex(): bool
    {
        return $this->rotation === 'single';
    }

    /**
     * Index holding the logs of the period $date falls in. Null means now.
     */
    public function indexFor($date = null): string
    {
        if ($this->isSingleIndex()) {
            return $this->indexPrefix;
        }

        return $this->indexPrefix . '-' . $this->toCarbon($date)->format(self::SUFFIX_FORMATS[$this->rotation]);
    }

    /**
     * Every read spans the bare index and the dated ones, whatever the rotation.
     */
    public function searchIndex(): string
    {
        return implode(',', $this->indexPatterns());
    }

    /** @return array<int, string> */
    public function indexPatterns(): array
    {
        return [$this->indexPrefix, $this->indexPrefix . '-*'];
    }

    public function templateName(): string
    {
        return $this->indexPrefix . '-template';
    }

    /**
     * Registers the template that gives every index of this store the mapping.
     * With it in place a new period's index is auto-created, correctly mapped,
     * on its first write — nothing has to run when the period turns over.
     */
    public function putIndexTemplate(): void
    {
        $this->client->indices()->putIndexTemplate([
            'name' => $this->templateName(),
            'body' => [
                'index_patterns' => $this->indexPatterns(),
                'template' => ['mappings' => $this->mapping()],
            ],
        ]);
    }

    /**
     * Ensures the template exists and creates the index for $date up front.
     *
     * @return bool true when the index was created by this call.
     */
    public function createIndexIfMissing($date = null): bool
    {
        $this->putIndexTemplate();

        $index = $this->indexFor($date);

        if ($this->client->indices()->exists(['index' => $index])->asBool()) {
            return false;
        }

        $this->client->indices()->create(['index' => $index]);

        return true;
    }

    /**
     * Existing indices of this store, oldest first; the bare "{prefix}" index,
     * if present, comes first.
     *
     * @return array<int, string>
     */
    public function indices(): array
    {
        $indices = $this->client->indices()->get([
            'index' => $this->searchIndex(),
            'ignore_unavailable' => true,
            'allow_no_indices' => true,
        ])->asArray();

        $names = array_keys($indices);
        sort($names);

        return $names;
    }

    /**
     * Drops one whole period in one shot. $period matches the rotation:
     * '2026-11-18' (daily), '2026-11' (monthly), '2026' (yearly).
     * Refused for the single rotation, where it would drop every log.
     *
     * @return bool false when that period has no index.
     */
    public function deletePeriod($period): bool
    {
        if ($this->isSingleIndex()) {
            throw new \LogicException(
                "Rotation is 'single': deleting a period would drop every log. Use deleteOlderThan() instead."
            );
        }

        $index = $this->indexFor($period);

        if (! $this->client->indices()->exists(['index' => $index])->asBool()) {
            return false;
        }

        $this->client->indices()->delete(['index' => $index]);

        return true;
    }

    /**
     * Retention sweep: removes every log older than $months whole months back.
     * A dated index is deleted only when its entire period is before the cutoff;
     * the bare "{prefix}" index is trimmed with a delete_by_query on the timestamp.
     * Works across mixed layouts left behind by a rotation change.
     *
     * @return array<int, string> what was removed, one line per index.
     */
    public function deleteOlderThan(int $months): array
    {
        $cutoff = Carbon::now()->subMonthsNoOverflow($months)->startOfMonth();
        $removed = [];

        foreach ($this->indices() as $index) {
            if ($index === $this->indexPrefix) {
                $result = $this->client->deleteByQuery([
                    'index' => $index,
                    'refresh' => true,
                    'body' => ['query' => ['range' => [
                        $this->timestampField() => ['lt' => $cutoff->toIso8601String()],
                    ]]],
                ])->asArray();

                if (! empty($result['deleted'])) {
                    $removed[] = "{$index} ({$result['deleted']} docs)";
                }

                continue;
            }

            $end = $this->periodEnd($index);

            if ($end !== null && $end->lt($cutoff)) {
                $this->client->indices()->delete(['index' => $index]);
                $removed[] = $index;
            }
        }

        return $removed;
    }

    /**
     * Last moment covered by a dated index, read from its suffix. Null when the
     * name carries no recognised date.
     */
    protected function periodEnd(string $index): ?Carbon
    {
        $suffix = substr($index, strlen($this->indexPrefix) + 1);

        if (preg_match('/^(\d{4})\.(\d{2})\.(\d{2})$/', $suffix, $m)) {
            return Carbon::create((int) $m[1], (int) $m[2], (int) $m[3])->endOfDay();
        }

        if (preg_match('/^(\d{4})\.(\d{2})$/', $suffix, $m)) {
            return Carbon::create((int) $m[1], (int) $m[2], 1)->endOfMonth();
        }

        if (preg_match('/^(\d{4})$/', $suffix, $m)) {
            return Carbon::create((int) $m[1], 1, 1)->endOfYear();
        }

        return null;
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
     * $timestamp tells us which index holds the document. Without it, or when it
     * is not there (written under another rotation), the id is hunted down across
     * every index with a delete_by_query.
     */
    public function delete(string $source, int $logId, $timestamp = null): void
    {
        $id = $source . '-' . $logId;

        if (! empty($timestamp)) {
            try {
                $this->client->delete([
                    'index' => $this->indexFor($timestamp),
                    'id' => $id,
                ]);

                return;
            } catch (ClientResponseException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }
            }
        }

        $this->client->deleteByQuery([
            'index' => $this->searchIndex(),
            'ignore_unavailable' => true,
            'allow_no_indices' => true,
            'body' => ['query' => ['ids' => ['values' => [$id]]]],
        ]);
    }

    /**
     * Free-text search across every index of this store.
     */
    public function search(string $query, int $size = 50): array
    {
        $result = $this->client->search([
            'index' => $this->searchIndex(),
            'ignore_unavailable' => true,
            'allow_no_indices' => true,
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

    /**
     * '2026', '2026-11', '2026.11', '2026-11-18', a Carbon/DateTime, or null for now.
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

        // Bare "YYYY" and "YYYY-MM" are spelled out rather than left to the date parser.
        if (preg_match('/^(\d{4})$/', $value, $m)) {
            return Carbon::create((int) $m[1], 1, 1);
        }

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
