<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Fail2banBlacklistResource;
use App\Models\Fail2banBlacklist;
use App\Models\Fail2banWhitelist;
use App\Services\Fail2banService;
use App\Services\PermanentBlacklistService;
use App\Services\WhitelistSyncService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

class Fail2banStatus extends Page
{
    protected static ?string $navigationIcon = 'lucide-shield-alert';

    protected static string $view = 'filament.pages.fail2ban-status';

    protected static ?string $navigationLabel = 'Status';

    protected static ?string $navigationGroup = 'Fail2Ban';

    protected static ?int $navigationSort = 10;

    public array $status = [];

    public array $bannedIPs = [];

    public int $permanentBanCount = 0;

    public string $permanentBlacklistUrl = '';

    public string $quickUnbanIP = '';

    public bool $addToWhitelist = false;

    public string $manualBanIP = '';

    public bool $alsoPermanent = false;

    /** On-page feedback (Filament toasts are unreliable on this page in Safari). */
    public string $actionFlash = '';

    /** @var 'success'|'warning'|'danger'|'' */
    public string $actionFlashType = '';

    /**
     * Resolve per request — Livewire rehydration does not re-run mount(), so a
     * typed property set only in mount() is uninitialized on later actions.
     */
    protected function fail2ban(): Fail2banService
    {
        return app(Fail2banService::class);
    }

    /**
     * @param  'success'|'warning'|'danger'  $type
     */
    protected function flash(string $type, string $title, ?string $body = null): void
    {
        $this->actionFlashType = $type;
        $this->actionFlash = $body !== null && $body !== '' ? "{$title} — {$body}" : $title;

        $n = Notification::make()->title($title);
        if ($body !== null && $body !== '') {
            $n->body($body);
        }
        match ($type) {
            'danger' => $n->danger(),
            'warning' => $n->warning(),
            default => $n->success(),
        };
        // Persistent so a missed toast still shows in the notification tray until dismissed.
        $n->persistent()->send();
    }

    public function mount(): void
    {
        $this->loadStatus();
    }

    public function loadStatus(bool $forceRefresh = false): void
    {
        try {
            if ($forceRefresh) {
                Cache::forget('fail2ban_status');
            }

            // Cache status for 5 seconds to avoid too many calls
            $this->status = Cache::remember('fail2ban_status', 5, function () {
                return $this->fail2ban()->getStatus();
            });
            $this->bannedIPs = $this->status['banned_ips'] ?? [];
            $this->refreshPermanentBanSummary();

            // Show notification if service is not running
            if (isset($this->status['error'])) {
                Notification::make()
                    ->title('Fail2ban Service Not Running')
                    ->body($this->status['error'])
                    ->warning()
                    ->persistent()
                    ->send();
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Failed to load Fail2Ban status')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->status = [
                'enabled' => false,
                'service_running' => false,
                'currently_banned' => 0,
                'banned_ips' => [],
                'error' => $e->getMessage(),
            ];
            $this->bannedIPs = [];
            $this->refreshPermanentBanSummary();
        }
    }

    protected function refreshPermanentBanSummary(): void
    {
        $this->permanentBanCount = Fail2banBlacklist::query()->count();
        // Prefer named Filament URL; fall back if route cache is stale (common after tip).
        try {
            $this->permanentBlacklistUrl = Fail2banBlacklistResource::getUrl('index');
        } catch (\Throwable) {
            $this->permanentBlacklistUrl = url('/admin/fail2ban-blacklists');
        }
    }

    public function unbanIP(string $ip): void
    {
        try {
            if ($this->fail2ban()->unbanIP($ip)) {
                $this->flash('success', 'IP Unbanned', "{$ip} has been unbanned.");

                // Optionally add to whitelist
                if ($this->addToWhitelist) {
                    $this->whitelistIp($ip, 'Auto-whitelisted after unban');
                }

                // Clear cache and reload
                Cache::forget('fail2ban_status');
                $this->loadStatus(true);
            } else {
                $this->flash('danger', 'Failed to Unban IP', "Could not unban {$ip}. Check logs for details.");
            }
        } catch (\Exception $e) {
            $this->flash('danger', 'Error', $e->getMessage());
        }
    }

    public function unbanAll(): void
    {
        try {
            if ($this->fail2ban()->unbanAll()) {
                $this->flash('warning', 'All IPs Unbanned', 'All banned IPs have been unbanned.');

                Cache::forget('fail2ban_status');
                $this->loadStatus(true);
            }
        } catch (\Exception $e) {
            $this->flash('danger', 'Error', $e->getMessage());
        }
    }

    public function quickUnban(): void
    {
        $ip = trim($this->quickUnbanIP);
        if ($ip === '') {
            $this->flash('warning', 'IP Required', 'Please enter an IP address to unban.');

            return;
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->flash('warning', 'Invalid IP', "{$ip} is not a valid IPv4 or IPv6 address.");

            return;
        }

        $this->unbanIP($ip);
        $this->quickUnbanIP = '';
        $this->addToWhitelist = false;
    }

    public function manualBan(): void
    {
        $ip = trim($this->manualBanIP);
        if ($ip === '') {
            $this->flash('warning', 'IP Required', 'Please enter an IP address to ban.');

            return;
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->flash('warning', 'Invalid IP', "{$ip} is not a valid IPv4 or IPv6 address.");

            return;
        }

        $alreadyBanned = in_array($ip, $this->bannedIPs, true);
        $wantPermanent = $this->alsoPermanent;

        try {
            if ($this->fail2ban()->banIP($ip)) {
                if ($wantPermanent) {
                    $this->banPermanently($ip, 'Manual ban from Status (also permanent)');
                    $this->manualBanIP = '';
                    $this->alsoPermanent = false;
                    Cache::forget('fail2ban_status');
                    $this->loadStatus(true);

                    return;
                }

                if ($alreadyBanned) {
                    $this->flash(
                        'success',
                        'Already banned',
                        "{$ip} was already in the jail; ban command OK."
                    );
                } else {
                    $this->flash('success', 'IP Banned', "{$ip} has been banned (short-term).");
                }

                $this->manualBanIP = '';
                Cache::forget('fail2ban_status');
                $this->loadStatus(true);
            } else {
                $this->flash('danger', 'Failed to Ban IP', "Could not ban {$ip}. Check logs for details.");
            }
        } catch (\Exception $e) {
            $this->flash('danger', 'Error', $e->getMessage());
        }
    }

    public function banPermanently(string $ip, string $reason = 'Banned permanently from Fail2ban Status'): void
    {
        $ip = trim($ip);
        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->flash('warning', 'Invalid IP', 'Cannot permanently ban an invalid address.');

            return;
        }

        try {
            $result = app(PermanentBlacklistService::class)->add(
                $ip,
                $reason,
                Fail2banBlacklist::SOURCE_MANUAL,
                null,
                auth()->id(),
            );
            $type = $result['synced'] ? 'success' : 'warning';
            $this->flash($type, $result['created'] ? 'Permanently banned' : 'Already permanent', $result['message']);
            $this->refreshPermanentBanSummary();
        } catch (\Exception $e) {
            $this->flash('danger', 'Permanent ban failed', $e->getMessage());
        }
    }

    protected function whitelistIp(string $ip, string $comment): void
    {
        try {
            Fail2banWhitelist::create([
                'ip_or_cidr' => $ip,
                'comment' => $comment,
                'created_by' => auth()->id(),
            ]);

            // Sync to Fail2Ban
            app(WhitelistSyncService::class)->sync();

            Notification::make()
                ->title('Added to Whitelist')
                ->body("IP {$ip} has been added to whitelist.")
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Failed to Add to Whitelist')
                ->body($e->getMessage())
                ->warning()
                ->send();
        }
    }
}
