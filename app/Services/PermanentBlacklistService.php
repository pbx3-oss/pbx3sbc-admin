<?php

namespace App\Services;

use App\Models\Fail2banBlacklist;
use App\Models\Fail2banWhitelist;
use Illuminate\Support\Facades\Log;

/**
 * Create permanent blacklist rows + firewall sync.
 */
class PermanentBlacklistService
{
    public function __construct(
        private readonly BlacklistSyncService $sync,
    ) {
    }

    /**
     * @return array{ok: bool, created: bool, synced: bool, message: string, record?: Fail2banBlacklist}
     */
    public function add(
        string $ipOrCidr,
        string $reason = '',
        string $source = Fail2banBlacklist::SOURCE_MANUAL,
        ?int $banCountAtPromote = null,
        ?int $createdBy = null,
    ): array {
        $ipOrCidr = trim($ipOrCidr);
        if ($ipOrCidr === '') {
            return ['ok' => false, 'created' => false, 'synced' => false, 'message' => 'IP required'];
        }

        $existing = Fail2banBlacklist::query()->where('ip_or_cidr', $ipOrCidr)->first();
        if ($existing) {
            return [
                'ok' => true,
                'created' => false,
                'synced' => true,
                'message' => "{$ipOrCidr} is already on the permanent blacklist",
                'record' => $existing,
            ];
        }

        $record = Fail2banBlacklist::query()->create([
            'ip_or_cidr' => $ipOrCidr,
            'reason' => $reason !== '' ? $reason : null,
            'source' => $source,
            'ban_count_at_promote' => $banCountAtPromote,
            'created_by' => $createdBy,
        ]);

        $synced = $this->sync->sync();
        if (! $synced) {
            Log::warning('Permanent blacklist row created but firewall sync failed', [
                'ip' => $ipOrCidr,
            ]);
        }

        return [
            'ok' => true,
            'created' => true,
            'synced' => $synced,
            'message' => $synced
                ? "{$ipOrCidr} permanently banned (firewall synced)"
                : "{$ipOrCidr} saved to blacklist but firewall sync failed",
            'record' => $record,
        ];
    }

    /**
     * Remove a permanent ban and sync firewall (DROP rule dropped on sync).
     *
     * @return array{ok: bool, synced: bool, message: string}
     */
    public function remove(string $ipOrCidr): array
    {
        $ipOrCidr = trim($ipOrCidr);
        $record = Fail2banBlacklist::query()->where('ip_or_cidr', $ipOrCidr)->first();
        if (! $record) {
            return [
                'ok' => false,
                'synced' => false,
                'message' => "{$ipOrCidr} is not on the permanent blacklist",
            ];
        }

        $record->delete();
        $synced = $this->sync->sync();

        return [
            'ok' => true,
            'synced' => $synced,
            'message' => $synced
                ? "{$ipOrCidr} removed from permanent blacklist (firewall synced)"
                : "{$ipOrCidr} removed from DB but firewall sync failed — run Sync to firewall",
        ];
    }

    public function isWhitelisted(string $ip): bool
    {
        $ip = trim($ip);
        foreach (Fail2banWhitelist::query()->pluck('ip_or_cidr') as $entry) {
            $entry = (string) $entry;
            if ($entry === $ip) {
                return true;
            }
            if (str_contains($entry, '/') && $this->ipInCidr($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    protected function ipInCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$subnet, $mask] = explode('/', $cidr, 2);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $mask = (int) $mask;
        if ($mask < 0 || $mask > 32) {
            return false;
        }
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $maskLong = $mask === 0 ? 0 : (~((1 << (32 - $mask)) - 1) & 0xFFFFFFFF);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}
