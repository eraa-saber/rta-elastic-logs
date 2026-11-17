<?php

namespace Rta\ElasticLogs;

use Illuminate\Support\ServiceProvider;
use Rta\ElasticLogs\Console\BackfillLogs;
use Rta\ElasticLogs\Console\CreateLogsIndex;
use Rta\ElasticLogs\Console\DeleteLogsMonth;
use Rta\ElasticLogs\Console\InstallCommand;

class ElasticLogsServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Package defaults; the app's config/elasticsearch.php overrides them.
        $this->mergeConfigFrom(__DIR__ . '/../config/elasticsearch.php', 'elasticsearch');
    }

    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/elasticsearch.php' => config_path('elasticsearch.php'),
        ], 'elastic-logs-config');

        $this->publishes([
            __DIR__ . '/../stubs/LogsSearchService.stub' => app_path('Services/Elasticsearch/ExampleLogsSearchService.php'),
        ], 'elastic-logs-stubs');

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                CreateLogsIndex::class,
                BackfillLogs::class,
                DeleteLogsMonth::class,
            ]);
        }
    }
}
