<?php

use App\Models\ScreenerPreset;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ScreenerPreset::query()
            ->where('is_default', true)
            ->update(['mtf_only' => false]);
    }

    public function down(): void
    {
        ScreenerPreset::query()
            ->where('is_default', true)
            ->update(['mtf_only' => true]);
    }
};
