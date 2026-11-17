<?php

namespace Rta\ElasticLogs\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-off migration of rows still sitting in SQL into the monthly Elasticsearch
 * indices. Document ids are "{source}-{row id}", so re-running it overwrites the
 * same documents instead of duplicating them.
 */
class BackfillLogs extends Command
{
    use ResolvesLogType;

    protected $signature = 'elasticsearch:backfill-logs
                            {--type= : Log type key registered under log_types in config/elasticsearch.php}
                            {--chunk=500 : Rows per bulk request}';

    protected $description = 'Bulk index existing SQL log rows (live + archive tables) into Elasticsearch';

    public function handle()
    {
        if (! ($service = $this->resolveService()) || ! ($config = $this->logTypeConfig())) {
            return self::FAILURE;
        }

        $service->createIndexIfMissing();

        $chunkSize = max(1, (int) $this->option('chunk'));

        foreach ($config['tables'] as $source => $table) {
            $this->backfillTable($service, $table, $source, $chunkSize);
        }

        $this->info('Backfill complete.');

        return self::SUCCESS;
    }

    protected function backfillTable($service, string $table, string $source, int $chunkSize): void
    {
        if (! Schema::hasTable($table)) {
            $this->warn("Table {$table} does not exist — skipped.");
            return;
        }

        $this->info("Backfilling {$table} ({$source})...");

        $total = 0;

        DB::table($table)->orderBy('id')->chunk($chunkSize, function ($rows) use ($service, $source, &$total) {
            $service->bulkIndex($rows, $source);
            $total += $rows->count();
            $this->line("  indexed {$total} rows so far");
        });

        $this->info("Done: {$total} rows from {$table}.");
    }
}
