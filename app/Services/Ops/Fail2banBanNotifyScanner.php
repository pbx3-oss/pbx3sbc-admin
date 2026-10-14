<?php

namespace App\Services\Ops;

use App\Models\Fail2banBanStat;
use App\Models\Fail2banBlacklist;
use App\Models\Fail2banSetting;
use App\Services\Fail2banService;
use App\Services\PermanentBlacklistService;
use Illuminate\Support\Facades\Log;

/**
 * Diff Fail2ban banned IPs vs last poll:
 * - always increment lifetime ban_stats + auto-promote to permanent blacklist
 * - optionally emit Gatekeeper fail2ban_ban events when notify is enabled
 *
 * First run seeds state without counting/mailing (avoids flooding existing bans).
 */
final class Fail2banBanNotifyScanner
{
    public function __construct(
        private readonly Fail2banService $fail2ban,
        private readonly GatekeeperOpsClient $gatekeeper,
        private readonly PermanentBlacklistService $permanentBlacklist,
    ) {
    }

    /**
     * @return array{
     *   notify_enabled: bool,
     *   seeded: bool,
     *   current: int,
     *   new: int,
     *   emitted: int,
     *   promoted: int,
     *   stats_incremented: int,
     *   skipped_cap: int,
     *   errors: list<string>,
     *   enabled: bool
     * }
     */
    public function run(): array
    {
        $result = [
            'notify_enabled' => false,
            'enabled' => true,
            'seeded' => false,
            'current' => 0,
            'new' => 0,
            'emitted' => 0,
            'promoted' => 0,
            'stats_incremented' => 0,
            'skipped_cap' => 0,
            'errors' => [],
        ];

        $notifyEnabled = filter_var(config('pbx3_ops.fail2ban_ban_notify_enabled', false), FILTER_VALIDATE_BOOL);
        $result['notify_enabled'] = $notifyEnabled;

        try {
            $status = $this->fail2ban->getStatus();
        } catch (\Throwable $e) {
            $result['errors'][] = 'fail2ban status: '.$e->getMessage();

            return $result;
        }

        if (! empty($status['error']) || empty($status['service_running'])) {
            $result['errors'][] = (string) ($status['error'] ?? 'Fail2ban not running');

            return $result;
        }

        $jail = (string) ($status['jail_name'] ?? 'opensips-brute-force');
        $current = array_values(array_unique(array_filter(
            array_map('strval', $status['banned_ips'] ?? []),
            static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false
        )));
        sort($current);
        $result['current'] = count($current);

        $statePath = (string) config('pbx3_ops.state_path', storage_path('app/ops-fail2ban-ban.json'));
        $prev = $this->readState($statePath);
        $known = $prev['banned'] ?? null;

        if (! is_array($known)) {
            $this->writeState($statePath, $current);
            $result['seeded'] = true;

            return $result;
        }

        $known = array_values(array_unique(array_map('strval', $known)));
        $new = array_values(array_diff($current, $known));
        $result['new'] = count($new);

        $threshold = Fail2banSetting::autoPermaThreshold();
        $maxEmits = max(1, (int) config('pbx3_ops.max_emits_per_run', 10));
        $fqdn = $this->sbcFqdn();
        $toEmit = $notifyEnabled ? array_slice($new, 0, $maxEmits) : [];
        $result['skipped_cap'] = $notifyEnabled ? max(0, count($new) - count($toEmit)) : 0;

        if ($notifyEnabled && ! $this->gatekeeper->isConfigured()) {
            $result['errors'][] = 'Gatekeeper URL/token not configured (notify skipped; stats still applied)';
            $toEmit = [];
        }

        foreach ($new as $ip) {
            $count = $this->incrementBanStat($ip);
            $result['stats_incremented']++;

            if ($threshold > 0 && $count >= $threshold) {
                if ($this->permanentBlacklist->isWhitelisted($ip)) {
                    continue;
                }
                if (Fail2banBlacklist::query()->where('ip_or_cidr', $ip)->exists()) {
                    continue;
                }
                try {
                    $add = $this->permanentBlacklist->add(
                        $ip,
                        "Auto-promoted after {$count} temp bans",
                        Fail2banBlacklist::SOURCE_AUTO,
                        $count,
                        null,
                    );
                    if ($add['created']) {
                        $result['promoted']++;
                    }
                    if (! $add['synced']) {
                        $result['errors'][] = "{$ip}: permanent blacklist sync failed";
                    }
                } catch (\Throwable $e) {
                    $result['errors'][] = "{$ip} promote: ".$e->getMessage();
                    Log::warning('fail2ban auto-promote failed', [
                        'ip' => $ip,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        foreach ($toEmit as $ip) {
            try {
                $this->gatekeeper->postEvent([
                    'type' => 'fail2ban_ban',
                    'source_ip' => $ip,
                    'jail' => $jail,
                    'sbc_fqdn' => $fqdn,
                    'currently_banned' => (int) ($status['currently_banned'] ?? count($current)),
                ]);
                $result['emitted']++;
            } catch (\Throwable $e) {
                $result['errors'][] = "{$ip}: ".$e->getMessage();
                Log::warning('fail2ban ban notify emit failed', [
                    'ip' => $ip,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->writeState($statePath, $current);

        return $result;
    }

    private function incrementBanStat(string $ip): int
    {
        $now = now();
        $stat = Fail2banBanStat::query()->firstOrNew(['ip' => $ip]);
        if (! $stat->exists) {
            $stat->ban_count = 0;
            $stat->first_banned_at = $now;
        }
        $stat->ban_count = (int) $stat->ban_count + 1;
        $stat->last_banned_at = $now;
        $stat->save();

        return (int) $stat->ban_count;
    }

    private function sbcFqdn(): string
    {
        $raw = trim((string) config('pbx3_ops.sbc_fqdn', ''));
        if ($raw === '') {
            return '';
        }
        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            $host = parse_url($raw, PHP_URL_HOST);

            return is_string($host) ? $host : $raw;
        }

        return $raw;
    }

    /**
     * @return array{banned?: list<string>}
     */
    private function readState(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $json = json_decode($raw, true);

        return is_array($json) ? $json : [];
    }

    /**
     * @param  list<string>  $banned
     */
    private function writeState(string $path, array $banned): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $payload = json_encode([
            'banned' => array_values($banned),
            'updated_at' => gmdate('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $payload === false ? "{}\n" : $payload."\n");
    }
}
