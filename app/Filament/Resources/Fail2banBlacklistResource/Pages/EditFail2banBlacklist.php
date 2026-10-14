<?php

namespace App\Filament\Resources\Fail2banBlacklistResource\Pages;

use App\Filament\Concerns\HasPanelBackLink;
use App\Filament\Resources\Fail2banBlacklistResource;
use App\Services\BlacklistSyncService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditFail2banBlacklist extends EditRecord
{
    use HasPanelBackLink;

    protected static string $resource = Fail2banBlacklistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Remove permanent ban')
                ->modalHeading('Remove permanent ban?')
                ->modalDescription('Deletes this row and syncs iptables so the DROP rule is removed.')
                ->after(function () {
                    app(BlacklistSyncService::class)->sync();
                }),
        ];
    }

    protected function afterSave(): void
    {
        try {
            if (app(BlacklistSyncService::class)->sync()) {
                Notification::make()
                    ->title('Permanent ban synced')
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Sync failed')
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
