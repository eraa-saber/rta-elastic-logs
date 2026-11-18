# rta/elastic-logs

Elasticsearch log stores for Laravel, with indices split by day, month, year, or not at all. An index template gives every new index the right mapping, and reads cover every index of a store. A whole period can be dropped by deleting its index.

## Rotation

Set how indices are split in `.env`:

```env
ELASTIC_LOGS_ROTATION=monthly
```

| Value | Index name | Use when |
|---|---|---|
| `daily` | `external_api_logs-2026.11.18` | Very high volume only. Many small indices weigh on the cluster |
| `monthly` (default) | `external_api_logs-2026.11` | Most cases |
| `yearly` | `external_api_logs-2026` | Moderate volume |
| `single` | `external_api_logs` | Low volume; no period deletes |

To override the rotation for one store, add its entry to `rotations` in `config/elasticsearch.php`:

```php
'rotations' => [
    'sms_logs_index' => env('ELASTIC_SMS_LOGS_ROTATION'),   // e.g. single
],
```

Changing the rotation is safe. Search, the template and `--older-than` cover both `{prefix}` and `{prefix}-*`, so logs written under the old rotation stay searchable. In Kibana, use the data view pattern `{prefix}*`.

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
| `elasticsearch:create-logs-index --type=X [--month=2026-09]` | Registers the template and creates the current period's index |
| `elasticsearch:backfill-logs --type=X [--chunk=500]` | Copies rows from the SQL tables in `log_types.X.tables` into Elasticsearch |
| `elasticsearch:delete-logs-month <period> --type=X` | Deletes one period: `2026-11-18`, `2026-11` or `2026`, depending on the rotation. Refused for `single` |
| `elasticsearch:delete-logs-month --older-than=6 --type=X` | Deletes every log older than N months. A dated index is dropped only when its whole period is past the cutoff; the `single` index is trimmed by `created_at` |
| `elasticsearch:delete-logs-month --list --type=X` | Lists the existing indices |

## Upgrading from v1.0

Nothing breaks. `MonthlyLogSearchService` still works and behaves as before with the default `monthly` rotation. New code should extend `Rta\ElasticLogs\LogSearchService`, which uses `indices()`, `deletePeriod()` and `deleteOlderThan()` in place of `monthlyIndices()`, `deleteMonth()` and `deleteMonthsOlderThan()`.

## Releasing

Tag a version (`git tag v1.0.0 && git push --tags`). Apps then pick it up with `composer update rta/elastic-logs`.
