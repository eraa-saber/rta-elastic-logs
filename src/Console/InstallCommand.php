<?php

namespace Rta\ElasticLogs\Console;

use Illuminate\Console\Command;

/**
 * One-time setup in a host app: publishes the config and an example service,
 * and appends the connection keys to .env when they are missing.
 */
class InstallCommand extends Command
{
    protected $signature = 'elastic-logs:install
                            {--force : Overwrite files that were already published}';

    protected $description = 'Publish the Elasticsearch logs config and an example log service';

    public function handle()
    {
        $this->callSilent('vendor:publish', [
            '--tag' => 'elastic-logs-config',
            '--force' => $this->option('force'),
        ]);
        $this->info('Published config/elasticsearch.php');

        $this->callSilent('vendor:publish', [
            '--tag' => 'elastic-logs-stubs',
            '--force' => $this->option('force'),
        ]);
        $this->info('Published app/Services/Elasticsearch/ExampleLogsSearchService.php');

        $this->appendEnvKeys();

        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Set ELASTIC_PASSWORD (and ELASTIC_CA_CERT if verifying SSL) in .env');
        $this->line('  2. Rename the example service, fill its mapping() and toDocument()');
        $this->line('  3. Register it under log_types in config/elasticsearch.php');
        $this->line('  4. php artisan elasticsearch:create-logs-index --type=<your-type>');

        return self::SUCCESS;
    }

    protected function appendEnvKeys(): void
    {
        $path = base_path('.env');

        if (! file_exists($path)) {
            $this->warn('.env not found — add the ELASTIC_* keys yourself.');
            return;
        }

        $env = file_get_contents($path);
        $keys = [
            'ELASTIC_HOST' => 'https://localhost:9200',
            'ELASTIC_USERNAME' => 'elastic',
            'ELASTIC_PASSWORD' => '',
            'ELASTIC_SSL_VERIFY' => 'false',
            'ELASTIC_CA_CERT' => '',
        ];

        $missing = array_filter($keys, fn ($key) => ! preg_match('/^' . $key . '=/m', $env), ARRAY_FILTER_USE_KEY);

        if (empty($missing)) {
            return;
        }

        $lines = PHP_EOL;
        foreach ($missing as $key => $value) {
            $lines .= "{$key}={$value}" . PHP_EOL;
        }

        file_put_contents($path, rtrim($env) . PHP_EOL . $lines);
        $this->info('Added ' . implode(', ', array_keys($missing)) . ' to .env');
    }
}
