<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 40); // nse_universe, bse_mtf, screener_in, businessquant, yahoo, scores
            $table->string('market', 5)->nullable(); // IN, US, ALL
            $table->string('status', 20)->default('success'); // success, partial, failed
            $table->unsignedInteger('records_processed')->default(0);
            $table->unsignedInteger('records_succeeded')->default(0);
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['source', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
