<?php

namespace Rta\ElasticLogs\Console;

use Illuminate\Console\Command;

class DeleteLogsMonth extends Command
{
    use ResolvesLogType;

    protected $signature = 'elasticsearch:delete-logs-month
                            {month? : Period to drop, matching the rotation: 2026-11-18, 2026-11 or 2026}
                            {--type= : Log type key registered under log_types in config/elasticsearch.php}
                            {--older-than= : Instead drop every log older than this many months}
                            {--list : Only list the existing indices}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Drop logs one whole period at a time, or every log older than N months';

    public function handle()
    {
        if (! ($service = $this->resolveService())) {
            return self::FAILURE;
        }

        if ($this->option('list')) {
            $existing = $service->indices();

            $this->line("Rotation: {$service->rotation()}");

            if (empty($existing)) {
                $this->info('No indices found.');
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
            $this->error('Pass either a period argument or --older-than, not both.');
            return self::FAILURE;
        }

        if ($olderThan !== null) {
            return $this->dropOlderThan((int) $olderThan);
        }

        if ($service->isSingleIndex()) {
            $this->error("Rotation is 'single': dropping a period would delete every log. Use --older-than instead.");
            return self::FAILURE;
        }

        $index = $service->indexFor($month);

        if (! $this->confirmed("Delete {$index} and every log in it?")) {
            return self::FAILURE;
        }

        if (! $service->deletePeriod($month)) {
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

        if (! $this->confirmed("Delete every log older than {$months} month(s)?")) {
            return self::FAILURE;
        }

        $removed = $this->resolvedService->deleteOlderThan($months);

        if (empty($removed)) {
            $this->info('Nothing older than the cutoff — nothing deleted.');
            return self::SUCCESS;
        }

        foreach ($removed as $line) {
            $this->line("  deleted {$line}");
        }

        $this->info(count($removed) . ' index(es) affected.');

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
