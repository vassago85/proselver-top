<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleModel extends Model
{
    use SoftDeletes;

    protected $fillable = ['brand_id', 'name', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Normalised catalogue used by the make-inference resolver.
     * Each entry: ['key' => alnum-lowercased name, 'len' => length,
     * 'brand_id' => owning brand].  Pull it once and pass it into
     * brandIdForModelName() when resolving many rows (e.g. an import).
     *
     * @return array<int, array{key: string, len: int, brand_id: int}>
     */
    public static function catalogue(): array
    {
        return static::query()
            ->where('is_active', true)
            ->whereNotNull('brand_id')
            ->get(['name', 'brand_id'])
            ->map(fn (self $m) => [
                'key' => static::normaliseName($m->name),
                'len' => strlen(static::normaliseName($m->name)),
                'brand_id' => (int) $m->brand_id,
            ])
            ->filter(fn (array $m) => $m['key'] !== '')
            ->values()
            ->all();
    }

    /**
     * Resolve the make (brand_id) for a free-text model name by matching
     * it against the seeded catalogue.  E.g. "Mokka" -> Opel.
     *
     * Match order: exact normalised name, then prefix (so "Mokka GS"
     * and "Corsa-e" still resolve).  Only returns a brand when exactly
     * one make matches -- ambiguous or unknown names return null so we
     * never guess.  Prefix matches are restricted to catalogue names of
     * 4+ characters to avoid short truck-code collisions.
     */
    public static function brandIdForModelName(?string $modelName, ?array $catalogue = null): ?int
    {
        $needle = static::normaliseName($modelName);
        if ($needle === '') {
            return null;
        }

        $catalogue ??= static::catalogue();

        $exact = collect($catalogue)
            ->filter(fn (array $m) => $m['key'] === $needle)
            ->pluck('brand_id')
            ->unique();
        if ($exact->count() === 1) {
            return (int) $exact->first();
        }
        if ($exact->count() > 1) {
            return null;
        }

        $prefix = collect($catalogue)
            ->filter(fn (array $m) => $m['len'] >= 4 && str_starts_with($needle, $m['key']))
            ->pluck('brand_id')
            ->unique();

        return $prefix->count() === 1 ? (int) $prefix->first() : null;
    }

    public static function normaliseName(?string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower((string) $name));
    }
}
