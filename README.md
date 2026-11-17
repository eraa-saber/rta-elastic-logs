# rta/elastic-logs

Monthly Elasticsearch log indices for Laravel. Each log store is written to one index per month (`{prefix}-YYYY.MM`) and read through the wildcard `{prefix}-*`. An index template gives every new month the right mapping, so a whole month can be dropped by deleting its index.

## Install

1. Add the repository to the app's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/eraa-saber/rta-elastic-logs" }
]
```

   For local development, point it at the folder instead:

```json
"repositories": [
    { "type": "path", "url": "../packages/rta-elastic-logs" }
]
```

2. Require it and run the installer:

```bash
composer require rta/elastic-logs
php artisan elastic-logs:install
```

   The installer publishes `config/elasticsearch.php` and `app/Services/Elasticsearch/ExampleLogsSearchService.php`, and adds any missing `ELASTIC_*` keys to `.env`.

3. Set the connection in `.env`:

```env
ELASTIC_HOST=https://localhost:9200
ELASTIC_USERNAME=elastic
ELASTIC_PASSWORD=your-password
ELASTIC_SSL_VERIFY=false
ELASTIC_CA_CERT=
```

## Add a log store

1. Rename the example service. Then fill in `indexConfigKey()`, `mapping()`, `searchableFields()` and `toDocument()`.
2. Add its prefix and type to `config/elasticsearch.php`:

```php
'payment_logs_index' => env('ELASTIC_PAYMENT_LOGS_INDEX', 'payment_logs'),

'log_types' => [
    'payment' => [
        'service' => \App\Services\Elasticsearch\PaymentLogsSearchService::class,
        'tables' => ['live' => 'payment_logs'],
    ],
],
```

3. Register the template and create the current month's index:

```bash
php artisan elasticsearch:create-logs-index --type=payment
```

4. Write logs from the app:

```php
try {
    app(PaymentLogsSearchService::class)->indexNew([...]);
} catch (\Throwable $e) {
    // Logging must never break the request
}
```

## Commands

| Command | What it does |
|---|---|
| `elastic-logs:install` | Publishes the config and the example service, and adds the `.env` keys |
| `elasticsearch:create-logs-index --type=X [--month=2026-09]` | Registers the template and creates the month's index |
| `elasticsearch:backfill-logs --type=X [--chunk=500]` | Copies rows from the SQL tables in `log_types.X.tables` into Elasticsearch |
| `elasticsearch:delete-logs-month 2026-07 --type=X` | Deletes one month |
| `elasticsearch:delete-logs-month --older-than=6 --type=X` | Deletes every month older than N months |
| `elasticsearch:delete-logs-month --list --type=X` | Lists the existing monthly indices |

## Releasing

Tag a version (`git tag v1.0.0 && git push --tags`). Apps then pick it up with `composer update rta/elastic-logs`.
