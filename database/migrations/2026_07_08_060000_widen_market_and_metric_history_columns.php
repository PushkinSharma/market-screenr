<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'market')) {
            DB::statement('ALTER TABLE companies ALTER COLUMN market TYPE varchar(5)');
        }

        if (Schema::hasTable('screener_presets') && Schema::hasColumn('screener_presets', 'market')) {
            DB::statement('ALTER TABLE screener_presets ALTER COLUMN market TYPE varchar(5)');
        }

        if (Schema::hasTable('sync_runs') && Schema::hasColumn('sync_runs', 'market')) {
            DB::statement('ALTER TABLE sync_runs ALTER COLUMN market TYPE varchar(5)');
        }

        if (Schema::hasTable('metric_histories')) {
            if (Schema::hasColumn('metric_histories', 'source')) {
                DB::statement('ALTER TABLE metric_histories ALTER COLUMN source TYPE varchar(30)');
            }

            if (Schema::hasColumn('metric_histories', 'period_type')) {
                DB::statement('ALTER TABLE metric_histories ALTER COLUMN period_type TYPE varchar(20)');
            }
        }
    }

    public function down(): void
    {
        //
    }
};
