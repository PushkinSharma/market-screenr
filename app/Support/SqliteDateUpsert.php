<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * SQLite often stores date columns as "Y-m-d H:i:s". Eloquent updateOrCreate
 * with a plain "Y-m-d" key then misses the row and hits the unique index.
 */
class SqliteDateUpsert
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $keys  must include the date column
     * @param  array<string, mixed>  $values
     */
    public static function updateOrCreate(string $model, array $keys, array $values, string $dateColumn): Model
    {
        $dateValue = $keys[$dateColumn] ?? null;
        unset($keys[$dateColumn]);

        $date = Carbon::parse((string) $dateValue)->toDateString();

        $query = $model::query();
        foreach ($keys as $column => $value) {
            $query->where($column, $value);
        }
        $query->whereDate($dateColumn, $date);

        $existing = $query->first();

        if ($existing) {
            $existing->update($values);

            return $existing->fresh();
        }

        return $model::query()->create(array_merge($keys, [$dateColumn => $date], $values));
    }
}
