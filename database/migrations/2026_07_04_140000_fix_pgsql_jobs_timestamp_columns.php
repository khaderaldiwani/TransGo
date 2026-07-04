<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('jobs')) {
            return;
        }

        $this->normalizeQueueTimestampColumn('reserved_at', true);
        $this->normalizeQueueTimestampColumn('available_at');
        $this->normalizeQueueTimestampColumn('created_at');
    }

    public function down(): void
    {
        // Intentionally left empty. Laravel's database queue expects Unix timestamp
        // integers in these columns, so reverting would reintroduce queue failures.
    }

    private function normalizeQueueTimestampColumn(string $column, bool $nullable = false): void
    {
        if (! Schema::hasColumn('jobs', $column)) {
            return;
        }

        $type = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'jobs')
            ->where('column_name', $column)
            ->value('data_type');

        if (in_array($type, ['integer', 'bigint', 'smallint'], true)) {
            return;
        }

        $nullExpression = $nullable ? 'NULL' : '0';

        if (is_string($type) && str_contains($type, 'timestamp')) {
            DB::statement(sprintf(
                'ALTER TABLE jobs ALTER COLUMN %1$s TYPE integer USING CASE WHEN %1$s IS NULL THEN %2$s ELSE EXTRACT(EPOCH FROM %1$s)::integer END',
                $column,
                $nullExpression
            ));
        } else {
            DB::statement(sprintf(
                'ALTER TABLE jobs ALTER COLUMN %1$s TYPE integer USING CASE WHEN %1$s IS NULL THEN %2$s ELSE %1$s::integer END',
                $column,
                $nullExpression
            ));
        }

        if ($nullable) {
            DB::statement(sprintf('ALTER TABLE jobs ALTER COLUMN %s DROP NOT NULL', $column));
        } else {
            DB::statement(sprintf('ALTER TABLE jobs ALTER COLUMN %s SET DEFAULT 0', $column));
            DB::statement(sprintf('ALTER TABLE jobs ALTER COLUMN %s SET NOT NULL', $column));
        }
    }
};
