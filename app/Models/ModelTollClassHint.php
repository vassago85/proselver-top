<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Global model -> SANRAL toll class memory.
 *
 * Populated on Issue Advance whenever ops sets the toll-class override
 * dropdown to something other than Auto and the order has a model_name.
 * Read on Open Advance Panel to pre-fill the override with the
 * remembered value so the same correction doesn't have to be repeated
 * for every trip of the same model.
 *
 * See migration 2026_05_26_160000_create_model_toll_class_hints for
 * the schema notes; this model just provides the lookup/upsert helpers.
 */
class ModelTollClassHint extends Model
{
    protected $fillable = [
        'model_key',
        'toll_class',
        'learned_by_user_id',
        'learned_at',
        'last_used_at',
        'use_count',
    ];

    protected function casts(): array
    {
        return [
            'toll_class' => 'integer',
            'use_count' => 'integer',
            'learned_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Normalise a model string to the lookup key.  Matches the rule the
     * bulk importer uses (uppercase + trimmed, internal whitespace
     * preserved -- "NPS300 CREW CAB" vs "NPS300SWA" must stay distinct).
     */
    public static function keyFor(?string $model): ?string
    {
        if ($model === null) {
            return null;
        }
        $key = strtoupper(trim($model));
        return $key === '' ? null : $key;
    }

    /**
     * Look up the remembered toll class for a model name.  Returns null
     * if we have no opinion yet (the caller falls back to the vehicle-
     * class default).  Doesn't touch use_count -- only the actual *use*
     * of the hint should bump that, which happens in noteHintApplied().
     */
    public static function classFor(?string $model): ?int
    {
        $key = self::keyFor($model);
        if (!$key) return null;
        $row = self::query()->where('model_key', $key)->first();
        return $row ? (int) $row->toll_class : null;
    }

    /**
     * Persist a correction.  Called from saveAdvance() when ops set
     * the override dropdown.  Upsert: if we already have a hint for
     * this model the new value wins, the learned timestamp stays put,
     * but the use_count and learned_by are updated.
     */
    public static function remember(?string $model, int $tollClass, ?int $userId): ?self
    {
        $key = self::keyFor($model);
        if (!$key || $tollClass < 1 || $tollClass > 4) return null;

        $row = self::query()->where('model_key', $key)->first();
        if ($row) {
            // Same value -- just reinforce; different value -- overwrite
            // and reset the use count so the audit trail makes sense.
            $changed = $row->toll_class !== $tollClass;
            $row->toll_class = $tollClass;
            $row->learned_by_user_id = $userId ?: $row->learned_by_user_id;
            $row->last_used_at = Carbon::now();
            $row->use_count = $changed ? 1 : ($row->use_count + 1);
            $row->save();
            return $row;
        }

        return self::create([
            'model_key' => $key,
            'toll_class' => $tollClass,
            'learned_by_user_id' => $userId,
            'learned_at' => Carbon::now(),
            'last_used_at' => Carbon::now(),
            'use_count' => 1,
        ]);
    }
}
