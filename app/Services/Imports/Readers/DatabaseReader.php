<?php

declare(strict_types=1);

namespace App\Services\Imports\Readers;

use App\Enums\ListFieldType;
use App\Models\EmailList;
use App\Models\ImportJob;
use App\Models\ListField;
use App\Services\Imports\CsvAnalyzer;
use App\Services\Imports\ImportFailedException;
use App\Services\Settings\SettingsReader;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Rows from an external SQL database (F-2.2.7-005): host/db/user/pass +
 * table or custom SELECT. The connection is configured at runtime under the
 * `import_source` name and never persisted to config files; the password is
 * stored encrypted in import_jobs.options. Toggle-gated by §3.25.2.
 *
 * Production targets MySQL (options.database.driver = "mysql"); the feature
 * tests exercise the identical code path through a SQLite file connection
 * posing as the source, since no MySQL server exists in CI/dev (D-03).
 * Columns are mapped by name: an "email" column is required, the rest match
 * list field tags/labels via the same heuristic as CSV headers, with
 * auto-create (F-2.2.7-007) when enabled.
 */
class DatabaseReader implements ImportReader
{
    private const CONNECTION = 'import_source';

    public function __construct(private readonly CsvAnalyzer $analyzer) {}

    public function prepare(ImportJob $job): void
    {
        if (! SettingsReader::importExport()->importFromDatabaseEnabled()) {
            throw new ImportFailedException(__('imports.errors.database_disabled'));
        }

        $this->configureConnection($job);

        $probe = $this->connection()->select($this->pagedQuery($job, 1, 0));

        if ($probe === []) {
            $job->options = array_merge((array) $job->options, ['database_column_map' => []]);
            $job->save();

            return;
        }

        $columns = array_keys((array) $probe[0]);

        /** @var EmailList $list */
        $list = EmailList::withoutTenancy()->findOrFail($job->list_id);
        $suggested = $this->analyzer->suggestMapping($columns, $list);
        $autoCreate = (bool) (((array) $job->options)['auto_create_fields'] ?? false);

        $map = [];
        $hasEmail = false;

        foreach ($columns as $i => $column) {
            $target = $suggested[$i] ?? CsvAnalyzer::TARGET_SKIP;

            if ($target === CsvAnalyzer::TARGET_EMAIL) {
                $hasEmail = true;
            }

            if (str_starts_with($target, CsvAnalyzer::TARGET_NEW_PREFIX)) {
                if (! $autoCreate) {
                    $target = CsvAnalyzer::TARGET_SKIP;
                } else {
                    $label = trim(substr($target, strlen(CsvAnalyzer::TARGET_NEW_PREFIX)));
                    $tag = $this->analyzer->tagFromHeader($label, $list);

                    ListField::create([
                        'customer_id' => $list->customer_id,
                        'list_id' => $list->id,
                        'type' => ListFieldType::Text,
                        'label' => $label,
                        'tag' => $tag,
                        'sort_order' => (int) $list->fields()->max('sort_order') + 1,
                    ]);

                    $target = CsvAnalyzer::TARGET_FIELD_PREFIX.$tag;
                }
            }

            $map[$column] = $target;
        }

        if (! $hasEmail) {
            throw new ImportFailedException(__('imports.errors.no_email_column'));
        }

        $job->options = array_merge((array) $job->options, ['database_column_map' => $map]);
        $job->save();
    }

    public function totalRows(ImportJob $job): int
    {
        $this->configureConnection($job);

        $row = $this->connection()->selectOne(
            'select count(*) as aggregate from ('.$this->baseQuery($job).') as import_src',
        );

        return (int) ($row->aggregate ?? 0);
    }

    public function rows(ImportJob $job, int $offset): \Generator
    {
        $this->configureConnection($job);

        /** @var array<string, string> $map */
        $map = (array) (((array) $job->options)['database_column_map'] ?? []);
        $batch = max(1, SettingsReader::importExport()->importBatchSize());
        $line = $offset;

        while (true) {
            $rows = $this->connection()->select($this->pagedQuery($job, $batch, $offset));

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                $line++;
                $row = (array) $row;

                $email = '';
                $fields = [];

                foreach ($map as $column => $target) {
                    $value = $row[$column] ?? null;
                    $value = $value === null ? null : trim((string) $value);

                    if ($target === CsvAnalyzer::TARGET_EMAIL) {
                        $email = (string) $value;
                    } elseif (str_starts_with($target, CsvAnalyzer::TARGET_FIELD_PREFIX) && $value !== null && $value !== '') {
                        $fields[substr($target, strlen(CsvAnalyzer::TARGET_FIELD_PREFIX))] = $value;
                    }
                }

                yield ['line' => $line, 'email' => $email, 'fields' => $fields];
            }

            $offset += count($rows);
        }
    }

    /** Register the dynamic connection from the job's stored credentials. */
    private function configureConnection(ImportJob $job): void
    {
        $config = (array) (((array) $job->options)['database'] ?? []);
        $driver = (string) ($config['driver'] ?? 'mysql');

        $connection = $driver === 'sqlite'
            ? [
                // Test double: a SQLite file posing as the external source (D-03).
                'driver' => 'sqlite',
                'database' => (string) ($config['database'] ?? ''),
                'foreign_key_constraints' => false,
            ]
            : [
                'driver' => 'mysql',
                'host' => (string) ($config['host'] ?? ''),
                'port' => (int) ($config['port'] ?? 3306),
                'database' => (string) ($config['database'] ?? ''),
                'username' => (string) ($config['username'] ?? ''),
                'password' => isset($config['password_encrypted'])
                    ? Crypt::decryptString((string) $config['password_encrypted'])
                    : '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ];

        config(['database.connections.'.self::CONNECTION => $connection]);
        DB::purge(self::CONNECTION);
    }

    private function connection(): \Illuminate\Database\ConnectionInterface
    {
        return DB::connection(self::CONNECTION);
    }

    /** The user-provided SELECT, or SELECT * from the validated table name. */
    private function baseQuery(ImportJob $job): string
    {
        $config = (array) (((array) $job->options)['database'] ?? []);
        $query = trim((string) ($config['query'] ?? ''));

        if ($query !== '') {
            if (! preg_match('/^select\s/i', $query)) {
                throw new ImportFailedException(__('imports.errors.query_not_select'));
            }

            return rtrim($query, "; \t\n\r");
        }

        $table = trim((string) ($config['table'] ?? ''));

        if (! preg_match('/^[A-Za-z0-9_.]+$/', $table)) {
            throw new ImportFailedException(__('imports.errors.table_invalid'));
        }

        return 'select * from '.$table;
    }

    private function pagedQuery(ImportJob $job, int $limit, int $offset): string
    {
        return 'select * from ('.$this->baseQuery($job).') as import_src limit '.$limit.' offset '.$offset;
    }
}
