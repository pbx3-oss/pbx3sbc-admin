<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">
                Auto-promote to permanent blacklist
            </x-slot>
            <x-slot name="description">
                After this many lifetime Fail2ban short-term bans for the same IP, cron adds it to the permanent list and syncs iptables DROP. Set 0 to disable.
            </x-slot>

            <div class="flex flex-wrap items-end gap-4">
                <div class="space-y-1">
                    <label for="autoPermaThreshold" class="text-sm font-medium text-gray-700 dark:text-gray-200">
                        Ban count threshold
                    </label>
                    <x-filament::input.wrapper class="max-w-[10rem]">
                        <x-filament::input
                            id="autoPermaThreshold"
                            type="number"
                            min="0"
                            step="1"
                            wire:model.live="autoPermaThreshold"
                        />
                    </x-filament::input.wrapper>
                </div>
                <x-filament::button type="button" color="primary" wire:click="saveThreshold">
                    Save threshold
                </x-filament::button>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    @if ($autoPermaThreshold === 0)
                        Currently: <strong>off</strong>
                    @else
                        Currently: permanent after <strong>{{ $autoPermaThreshold }}</strong> temp bans
                    @endif
                </p>
            </div>
        </x-filament::section>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
