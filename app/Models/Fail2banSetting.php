<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Fail2banSetting extends Model
{
    protected $table = 'fail2ban_settings';

    protected $fillable = [
        'auto_perma_threshold',
    ];

    protected function casts(): array
    {
        return [
            'auto_perma_threshold' => 'integer',
        ];
    }

    /**
     * Single-row settings; create default if missing.
     */
    public static function current(): self
    {
        return Cache::remember('fail2ban_settings_row', 30, function () {
            $row = static::query()->first();
            if ($row) {
                return $row;
            }

            return static::query()->create([
                'auto_perma_threshold' => 10,
            ]);
        });
    }

    public static function autoPermaThreshold(): int
    {
        return max(0, (int) static::current()->auto_perma_threshold);
    }

    public static function forgetCache(): void
    {
        Cache::forget('fail2ban_settings_row');
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::forgetCache());
        static::deleted(fn () => static::forgetCache());
    }
}
