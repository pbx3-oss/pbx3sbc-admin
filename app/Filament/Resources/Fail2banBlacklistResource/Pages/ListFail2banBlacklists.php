<?php

namespace App\Filament\Resources\Fail2banBlacklistResource\Pages;

use App\Filament\Resources\Fail2banBlacklistResource;
use App\Models\Fail2banSetting;
use App\Services\BlacklistSyncService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListFail2banBlacklists extends ListRecords
{
    protected static string $resource = Fail2banBlacklistResource::class;

    protected static string $view = 'filament.resources.fail2ban-blacklist.list';

    public int $autoPermaThreshold = 10;

    public function mount(): void
    {
        parent::mount();
        $this->autoPermaThreshold = Fail2banSetting::autoPermaThreshold();
    }

    public function saveThreshold(): void
    {
        $value = max(0, (int) $this->autoPermaThreshold);
        $this->autoPermaThreshold = $value;

        $row = Fail2banSetting::current();
        $row->auto_perma_threshold = $value;
        $row->save();
        Fail2banSetting::forgetCache();

        Notification::make()
            ->title('Threshold saved')
            ->body(
                $value === 0
                    ? 'Auto-promote disabled.'
                    : "Auto-promote after {$value} lifetime temp bans."
            )
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Add permanent ban'),
            Actions\Action::make('sync')
                ->label('Sync to firewall')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Sync permanent blacklist to iptables')
                ->modalDescription('Rebuilds chain PBX3SBC-BLACKLIST from the database (DROP all from listed IPs).')
                ->action(function () {
                    try {
                        if (app(BlacklistSyncService::class)->sync()) {
                            Notification::make()
                                ->title('Sync successful')
                                ->body('Permanent blacklist synced to iptables.')
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Sync failed')
                                ->body('Check logs and sudoers for sync-fail2ban-blacklist.sh.')
                                ->danger()
                                ->send();
                        }
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Sync error')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Like Whitelist: durable. Short-term Fail2ban jail bans are on Status. This list is iptables DROP-all until you remove the row.';
    }
}
