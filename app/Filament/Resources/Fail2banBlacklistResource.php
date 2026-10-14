<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Fail2banBlacklistResource\Pages;
use App\Models\Fail2banBlacklist;
use App\Services\BlacklistSyncService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class Fail2banBlacklistResource extends Resource
{
    protected static ?string $model = Fail2banBlacklist::class;

    protected static ?string $navigationIcon = 'lucide-shield-ban';

    protected static ?string $navigationLabel = 'Blacklist';

    protected static ?string $navigationGroup = 'Fail2Ban';

    protected static ?string $modelLabel = 'Blacklist entry';

    protected static ?string $pluralModelLabel = 'Blacklist entries';

    protected static ?int $navigationSort = 25;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('ip_or_cidr')
                    ->label('IP Address or CIDR Range')
                    ->placeholder('192.168.1.100 or 192.168.1.0/24')
                    ->required()
                    ->maxLength(45)
                    ->unique(ignoreRecord: true)
                    ->rules([
                        function () {
                            return function (string $attribute, $value, \Closure $fail) {
                                if (! preg_match('/^([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})(\/([0-9]|[1-2][0-9]|3[0-2]))?$/', $value)) {
                                    if (! preg_match('/^([0-9a-fA-F]{0,4}:){1,7}[0-9a-fA-F]{0,4}(\/[0-9]{1,3})?$/', $value) && $value !== '::1') {
                                        $fail('The :attribute must be a valid IPv4/IPv6 address or CIDR range.');
                                    }
                                }
                            };
                        },
                    ])
                    ->helperText('DROP all traffic from this IP/CIDR via iptables chain PBX3SBC-BLACKLIST.'),
                Forms\Components\Textarea::make('reason')
                    ->label('Reason')
                    ->placeholder('Persistent INVITE scanner')
                    ->maxLength(255)
                    ->rows(3)
                    ->columnSpanFull(),
                Forms\Components\Hidden::make('source')
                    ->default(Fail2banBlacklist::SOURCE_MANUAL),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ip_or_cidr')
                    ->label('IP/CIDR')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->badge()
                    ->color('danger'),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->searchable()
                    ->wrap()
                    ->limit(50),
                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->color(fn (string $state): string => $state === Fail2banBlacklist::SOURCE_AUTO ? 'warning' : 'gray'),
                TextColumn::make('ban_count_at_promote')
                    ->label('Ban count @ promote')
                    ->placeholder('—'),
                TextColumn::make('creator.name')
                    ->label('Created By')
                    ->sortable()
                    ->default('System'),
                TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->label('Remove permanent ban')
                    ->modalHeading('Remove permanent ban?')
                    ->modalDescription('Deletes this row and syncs iptables so the DROP rule is removed. Accidental bans can be undone this way.')
                    ->successNotificationTitle('Permanent ban removed')
                    ->after(function () {
                        app(BlacklistSyncService::class)->sync();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Remove permanent bans')
                        ->modalHeading('Remove selected permanent bans?')
                        ->after(function () {
                            app(BlacklistSyncService::class)->sync();
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFail2banBlacklists::route('/'),
            'create' => Pages\CreateFail2banBlacklist::route('/create'),
            'edit' => Pages\EditFail2banBlacklist::route('/{record}/edit'),
        ];
    }
}
