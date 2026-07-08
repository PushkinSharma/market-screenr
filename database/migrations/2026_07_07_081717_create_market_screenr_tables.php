<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 30);
            $table->string('exchange', 20); // NSE, BSE, NYSE, NASDAQ
            $table->string('market', 5); // IN, US
            $table->string('name');
            $table->string('sector')->nullable();
            $table->string('industry')->nullable();
            $table->string('yahoo_symbol')->nullable(); // RELIANCE.NS, AAPL
            $table->string('isin', 20)->nullable();
            $table->string('bse_code', 20)->nullable();
            $table->boolean('is_mtf_eligible')->default(false);
            $table->string('mtf_group', 10)->nullable(); // Group I, II, III
            $table->date('mtf_effective_from')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('fundamentals_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'exchange']);
            $table->index(['market', 'is_active']);
            $table->index('is_mtf_eligible');
        });

        Schema::create('company_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('as_of_date');

            // Section 1 — Valuation
            $table->decimal('current_pe', 12, 2)->nullable();
            $table->decimal('pe_avg_5y', 12, 2)->nullable();
            $table->decimal('pe_avg_10y', 12, 2)->nullable();
            $table->decimal('current_ev_ebitda', 12, 2)->nullable();
            $table->decimal('ev_ebitda_avg_5y', 12, 2)->nullable();
            $table->decimal('current_pb', 12, 2)->nullable();
            $table->decimal('valuation_percentile', 5, 2)->nullable(); // 0-100, lower = cheaper

            // Section 2 — Drawdown Engine
            $table->decimal('current_price', 16, 4)->nullable();
            $table->decimal('week_52_high', 16, 4)->nullable();
            $table->decimal('week_52_low', 16, 4)->nullable();
            $table->decimal('ath_price', 16, 4)->nullable();
            $table->decimal('atl_price', 16, 4)->nullable();
            $table->decimal('pct_below_ath', 8, 2)->nullable();
            $table->decimal('pct_above_atl', 8, 2)->nullable();
            $table->decimal('drawdown_percentile_10y', 5, 2)->nullable(); // price percentile in 10y range

            // Section 3 — Fundamentals
            $table->decimal('revenue_cagr_3y', 8, 2)->nullable();
            $table->decimal('revenue_cagr_5y', 8, 2)->nullable();
            $table->decimal('profit_cagr_3y', 8, 2)->nullable();
            $table->decimal('profit_cagr_5y', 8, 2)->nullable();
            $table->decimal('roe', 8, 2)->nullable();
            $table->decimal('roce', 8, 2)->nullable();
            $table->decimal('debt_to_equity', 8, 2)->nullable();
            $table->decimal('interest_coverage', 12, 2)->nullable();
            $table->decimal('fcf', 20, 2)->nullable();
            $table->decimal('promoter_holding', 8, 2)->nullable();
            $table->decimal('fii_holding', 8, 2)->nullable();
            $table->decimal('dii_holding', 8, 2)->nullable();
            $table->decimal('fii_holding_change_qoq', 8, 2)->nullable();
            $table->decimal('dii_holding_change_qoq', 8, 2)->nullable();
            $table->decimal('market_cap', 20, 2)->nullable();

            // Section 4 — Momentum (confirmation, not trading)
            $table->decimal('dma_50', 16, 4)->nullable();
            $table->decimal('dma_100', 16, 4)->nullable();
            $table->decimal('dma_200', 16, 4)->nullable();
            $table->decimal('distance_from_dma_200_pct', 8, 2)->nullable();
            $table->decimal('rs_52w', 8, 2)->nullable(); // vs index or sector
            $table->decimal('volume_spike_ratio', 8, 2)->nullable(); // vs 20d avg
            $table->decimal('delivery_pct', 8, 2)->nullable();

            // Component percentile ranks (0-100) for MTF scoring
            $table->decimal('rank_business_quality', 5, 2)->nullable();
            $table->decimal('rank_sector_tailwind', 5, 2)->nullable();
            $table->decimal('rank_valuation', 5, 2)->nullable();
            $table->decimal('rank_correction', 5, 2)->nullable();
            $table->decimal('rank_momentum', 5, 2)->nullable();
            $table->decimal('rank_results_quality', 5, 2)->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'as_of_date']);
            $table->index('as_of_date');
        });

        Schema::create('metric_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('metric_key', 50); // pe, roce, roe, revenue, profit, etc.
            $table->date('period_date');
            $table->string('period_type', 20)->default('annual'); // annual, quarter, daily
            $table->decimal('value', 20, 4)->nullable();
            $table->string('source', 30)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'metric_key', 'period_date', 'period_type'], 'metric_history_unique');
            $table->index(['company_id', 'metric_key']);
        });

        Schema::create('price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('trade_date');
            $table->decimal('open', 16, 4)->nullable();
            $table->decimal('high', 16, 4)->nullable();
            $table->decimal('low', 16, 4)->nullable();
            $table->decimal('close', 16, 4)->nullable();
            $table->unsignedBigInteger('volume')->nullable();
            $table->decimal('delivery_pct', 8, 2)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'trade_date']);
            $table->index('trade_date');
        });

        Schema::create('screener_presets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('market', 5)->default('IN'); // IN, US, ALL
            $table->boolean('mtf_only')->default(true);
            $table->json('weights'); // component weights summing to 100
            $table->json('filters')->nullable(); // min/max hard filters
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('screener_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('screener_preset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('computed_at');
            $table->decimal('final_score', 5, 2); // 0-100
            $table->decimal('business_quality_score', 5, 2)->nullable();
            $table->decimal('sector_tailwind_score', 5, 2)->nullable();
            $table->decimal('valuation_score', 5, 2)->nullable();
            $table->decimal('correction_score', 5, 2)->nullable();
            $table->decimal('momentum_score', 5, 2)->nullable();
            $table->decimal('results_quality_score', 5, 2)->nullable();
            $table->unsignedInteger('rank')->nullable();
            $table->timestamps();

            $table->unique(['screener_preset_id', 'company_id', 'computed_at'], 'screener_score_unique');
            $table->index(['screener_preset_id', 'final_score']);
        });

        Schema::create('company_intels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('intel_type', 30); // results, concall, news, broker, announcement
            $table->date('published_at')->nullable();
            $table->string('title')->nullable();
            $table->text('raw_content')->nullable();
            $table->text('ai_summary')->nullable();
            $table->string('sentiment', 20)->nullable(); // positive, negative, neutral
            $table->boolean('is_temporary')->nullable(); // LLM assessment
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'intel_type']);
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_intels');
        Schema::dropIfExists('screener_scores');
        Schema::dropIfExists('screener_presets');
        Schema::dropIfExists('price_histories');
        Schema::dropIfExists('metric_histories');
        Schema::dropIfExists('company_metrics');
        Schema::dropIfExists('companies');
    }
};
