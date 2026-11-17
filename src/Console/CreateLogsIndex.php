<?php

namespace Rta\ElasticLogs\Console;

use Illuminate\Console\Command;

class CreateLogsIndex extends Command
{
    use ResolvesLogType;

    protected $signature = 'elasticsearch:create-logs-index
                            {--type= : Log type key registered under log_types in config/elasticsearch.php}
                            {--month= : Month to create, e.g. 2026-09 (defaults to the current month)}';

    protected $description = 'Register the index template for a log type and create its monthly index';

    public function handle()
    {
        if (! ($service = $this->resolveService())) {
            return self::FAILURE;
        }

        $month = $this->option('month');
        $index = $service->indexFor($month);

        if ($service->createIndexIfMissing($month)) {
            $this->info("Index {$index} created.");
            return self::SUCCESS;
        }

        $this->info("Index {$index} already exists.");

        return self::SUCCESS;
    }
}
