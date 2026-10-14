<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fail2banBanStat extends Model
{
    protected $table = 'fail2ban_ban_stats';

    protected $fillable = [
        'ip',
        'ban_count',
        'first_banned_at',
        'last_banned_at',
    ];

    protected function casts(): array
    {
        return [
            'ban_count' => 'integer',
            'first_banned_at' => 'datetime',
            'last_banned_at' => 'datetime',
        ];
    }
}
