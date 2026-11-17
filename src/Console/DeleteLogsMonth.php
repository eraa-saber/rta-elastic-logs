<?php

namespace Rta\ElasticLogs\Console;

use Illuminate\Console\Command;

class DeleteLogsMonth extends Command
{
    use ResolvesLogType;

    protected $signature = 'elasticsearch:delete-logs-month
                            {month? : Month to drop, e.g. 2026-07}
                            {--type= : Log type key registered under log_types in config/elasticsearch.php}
                            {--older-than= : Instead drop every month older than this many months}
                            {--list : Only list the existing monthly indices}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Drop logs one whole month at a time by deleting that month\'s Elasticsearch index';

    public function handle()
    {
        if (! ($service = $this->resolveService())) {
            return self::FAILURE;
        }

        if ($this->option('list')) {
            $existing = $service->monthlyIndices();

            if (empty($existing)) {
                $this->info('No monthly indices found.');
                return self::SUCCESS;
            }

            foreach ($existing as $index) {
                $this->line($index);
            }

            return self::SUCCESS;
        }

        $olderThan = $this->option('older-than');
        $month = $this->argument('month');

        if (($olderThan === null) === ($month === null)) {
            $this->error('Pass either a month argument or --older-than, not both.');
            return self::FAILURE;
        }

        if ($olderThan !== null) {
            return $this->dropOlderThan((int) $olderThan);
        }

        $index = $service->indexFor($month);

        if (! $this->confirmed("Delete {$index} and every log in it?")) {
            return self::FAILURE;
        }

        if (! $service->deleteMonth($month)) {
            $this->warn("Index {$index} does not exist — nothing to delete.");
            return self::SUCCESS;
        }

        $this->info("Deleted {$index}.");

        return self::SUCCESS;
    }

    protected function dropOlderThan(int $months): int
    {
        if ($months < 1) {
            $this->error('--older-than must be at least 1.');
            return self::FAILURE;
        }

        if (! $this->confirmed("Delete every monthly index older than {$months} month(s)?")) {
            return self::FAILURE;
        }

        $deleted = $this->resolvedService->deleteMonthsOlderThan($months);

        if (empty($deleted)) {
            $this->info('Nothing older than the cutoff — no index deleted.');
            return self::SUCCESS;
        }

        foreach ($deleted as $index) {
            $this->line("  deleted {$index}");
        }

        $this->info(count($deleted) . ' index(es) deleted.');

        return self::SUCCESS;
    }

    protected function confirmed(string $question): bool
    {
        if ($this->option('force') || $this->option('no-interaction')) {
            return true;
        }

        return $this->confirm($question);
    }
}
