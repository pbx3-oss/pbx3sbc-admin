<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fail2banBlacklist extends Model
{
    protected $table = 'fail2ban_blacklist';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_AUTO = 'auto';

    protected $fillable = [
        'ip_or_cidr',
        'reason',
        'source',
        'ban_count_at_promote',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'ban_count_at_promote' => 'integer',
        ];
    }

    /**
     * User who created this blacklist entry
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
