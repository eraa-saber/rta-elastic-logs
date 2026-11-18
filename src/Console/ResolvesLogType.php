<?php

namespace Rta\ElasticLogs\Console;

use Rta\ElasticLogs\LogSearchService;

/**
 * Shared --type handling: maps a --type key onto the matching search service,
 * as registered in config/elasticsearch.php.
 */
trait ResolvesLogType
{
    protected ?LogSearchService $resolvedService = null;

    protected function resolveService(): ?LogSearchService
    {
        if (! ($config = $this->logTypeConfig())) {
            return null;
        }

        return $this->resolvedService = app($config['service']);
    }

    /**
     * @return array{service: class-string, tables: array<string, string>}|null
     */
    protected function logTypeConfig(): ?array
    {
        $type = $this->option('type');
        $types = config('elasticsearch.log_types', []);

        if (empty($types)) {
            $this->error('No log types registered in config/elasticsearch.php (log_types).');
            return null;
        }

        if (! isset($types[$type])) {
            $this->error("Unknown --type={$type}. Available: " . implode(', ', array_keys($types)));
            return null;
        }

        return $types[$type];
    }
}
