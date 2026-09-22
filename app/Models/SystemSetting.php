<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SystemSetting extends Model
{
    use HasFactory;

    protected $table = 'system_settings';

    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Retrieve a setting value by key, or return the default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            return Cache::rememberForever("system_setting.{$key}", function () use ($key, $default) {
                $setting = static::query()->where('key', $key)->first();

                return $setting?->value ?? $default;
            });
        } catch (Throwable) {
            return $default;
        }
    }

    /**
     * Store or update a setting value by key.
     */
    public static function set(string $key, ?string $value): void
    {
        try {
            static::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $value]
            );

            Cache::forget("system_setting.{$key}");
        } catch (Throwable) {
            // Silently continue if setting cannot be saved
        }
    }

    /**
     * Get the resolved public URL of the uploaded system logo, or null.
     */
    public static function getLogoUrl(): ?string
    {
        $logo = static::get('system_logo');

        if (! $logo) {
            return null;
        }

        if (filter_var($logo, FILTER_VALIDATE_URL)) {
            return $logo;
        }

        return asset('storage/'.$logo);
    }

    /**
     * Get the resolved public URL of the uploaded system favicon, or default.
     */
    public static function getFaviconUrl(): ?string
    {
        $favicon = static::get('system_favicon');

        if (! $favicon) {
            return null;
        }

        if (filter_var($favicon, FILTER_VALIDATE_URL)) {
            return $favicon;
        }

        return asset('storage/'.$favicon);
    }
}
