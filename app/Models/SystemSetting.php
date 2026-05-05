<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'description'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $resolve = function () use ($key, $default) {
            $setting = static::where('key', $key)->first();

            if (!$setting) {
                return $default;
            }

            return match ($setting->type) {
                'integer' => (int) $setting->value,
                'float' => (float) $setting->value,
                'boolean' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
                'json' => json_decode($setting->value, true),
                default => $setting->value,
            };
        };

        // Cache backend can be temporarily unavailable (e.g. Redis restarting,
        // local dev box without the redis extension). System settings are read
        // on practically every page boot, so any failure here would cascade
        // into a 500 across the whole app — fall back to a direct DB read.
        try {
            return Cache::remember("system_setting.{$key}", 3600, $resolve);
        } catch (\Throwable $e) {
            return $resolve();
        }
    }

    public static function set(string $key, mixed $value, ?string $type = null, ?string $description = null): void
    {
        $storeValue = is_array($value) ? json_encode($value) : (string) $value;

        static::updateOrCreate(
            ['key' => $key],
            [
                'value' => $storeValue,
                'type' => $type ?? (is_array($value) ? 'json' : 'string'),
                'description' => $description,
            ]
        );

        try {
            Cache::forget("system_setting.{$key}");
        } catch (\Throwable $e) {
            // see comment in get() — cache failures must not block writes
        }
    }
}
