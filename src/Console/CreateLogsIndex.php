<?php

namespace Rta\ElasticLogs\Console;

use Illuminate\Console\Command;

class CreateLogsIndex extends Command
{
    use ResolvesLogType;

    protected $signature = 'elasticsearch:create-logs-index
                            {--type= : Log type key registered under log_types in config/elasticsearch.php}
                            {--month= : Period to create, matching the rotation: 2026-11-18, 2026-11 or 2026 (defaults to now; ignored for single)}';

    protected $description = 'Register the index template for a log type and create its current index';

    public function handle()
    {
        if (! ($service = $this->resolveService())) {
            return self::FAILURE;
        }

        $month = $this->option('month');
        $index = $service->indexFor($month);

        $this->line("Rotation: {$service->rotation()}");

        if ($service->createIndexIfMissing($month)) {
            $this->info("Index {$index} created.");
            return self::SUCCESS;
        }

        $this->info("Index {$index} already exists.");

        return self::SUCCESS;
    }
}
