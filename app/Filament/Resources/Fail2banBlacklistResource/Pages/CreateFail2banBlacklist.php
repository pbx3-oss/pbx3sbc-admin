<?php

namespace App\Filament\Resources\Fail2banBlacklistResource\Pages;

use App\Filament\Concerns\HasPanelBackLink;
use App\Filament\Resources\Fail2banBlacklistResource;
use App\Models\Fail2banBlacklist;
use App\Services\BlacklistSyncService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateFail2banBlacklist extends CreateRecord
{
    use HasPanelBackLink;

    protected static string $resource = Fail2banBlacklistResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['source'] = $data['source'] ?? Fail2banBlacklist::SOURCE_MANUAL;

        return $data;
    }

    protected function afterCreate(): void
    {
        try {
            if (app(BlacklistSyncService::class)->sync()) {
                Notification::make()
                    ->title('Permanent ban synced')
                    ->body('Entry created and iptables chain updated.')
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Sync failed')
                    ->body('Entry created but firewall sync failed. Use Sync to firewall.')
                    ->warning()
                    ->send();
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Sync error')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
