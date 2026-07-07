<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('companies', 'mtf_group')) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE companies ALTER COLUMN mtf_group TYPE VARCHAR(30)');
            } elseif (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE companies MODIFY mtf_group VARCHAR(30) NULL');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'mtf_group')) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE companies ALTER COLUMN mtf_group TYPE VARCHAR(10)');
            } elseif (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE companies MODIFY mtf_group VARCHAR(10) NULL');
            }
        }
    }
};
