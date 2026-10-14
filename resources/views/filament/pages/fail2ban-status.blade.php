<x-filament-panels::page>
    <div class="space-y-6">
        @if ($actionFlash !== '')
            <div
                @class([
                    'rounded-lg border px-4 py-3 text-sm',
                    'border-green-300 bg-green-50 text-green-900' => $actionFlashType === 'success',
                    'border-amber-300 bg-amber-50 text-amber-950' => $actionFlashType === 'warning',
                    'border-red-300 bg-red-50 text-red-900' => $actionFlashType === 'danger',
                    'border-gray-300 bg-gray-50 text-gray-900' => ! in_array($actionFlashType, ['success', 'warning', 'danger'], true),
                ])
                role="status"
            >
                {{ $actionFlash }}
            </div>
        @endif

        <!-- Status Card -->
        <x-filament::section>
            <x-slot name="heading">
                Fail2Ban Status - {{ $status['jail_name'] ?? 'opensips-brute-force' }}
            </x-slot>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Jail Status</div>
                    <div class="text-2xl font-bold">
                        @if($status['enabled'] ?? false)
                            <span class="text-green-600 dark:text-green-400">✓ Enabled</span>
                        @else
                            <span class="text-red-600 dark:text-red-400">✗ Disabled</span>
                        @endif
                    </div>
                </div>
                
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Currently Banned</div>
                    <div class="text-2xl font-bold">{{ $status['currently_banned'] ?? 0 }} IPs</div>
                </div>
                
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Total Banned</div>
                    <div class="text-2xl font-bold">{{ $status['total_banned'] ?? 0 }}</div>
                </div>
            </div>
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">
                {{ $permanentBanCount }} permanent {{ \Illuminate\Support\Str::plural('ban', $permanentBanCount) }}
                (iptables DROP) —
                <a href="{{ $permanentBlacklistUrl }}" class="font-medium text-primary-600 underline">
                    Manage on Blacklist
                </a>
                (add / remove / auto-promote threshold).
            </p>
        </x-filament::section>

        <!-- Quick Unban Form -->
        <x-filament::section>
            <x-slot name="heading">
                Quick Unban
            </x-slot>
            
            {{-- type=button + wire:click avoids native GET form submit clearing the field without Livewire --}}
            <div class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-center">
                    <x-filament::input.wrapper>
                        <x-filament::input
                            type="text"
                            wire:model.live="quickUnbanIP"
                            placeholder="Enter IP address (e.g., 192.168.1.100)"
                            wire:keydown.enter.prevent="quickUnban"
                        />
                    </x-filament::input.wrapper>

                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200 cursor-pointer">
                        <x-filament::input.checkbox wire:model.live="addToWhitelist" />
                        <span>Also add to whitelist</span>
                    </label>
                    
                    <x-filament::button type="button" color="warning" wire:click="quickUnban">
                        Unban IP
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>

        <!-- Banned IPs List -->
        <x-filament::section>
            <x-slot name="heading">
                Currently Banned IPs
            </x-slot>
            
            @if(empty($bannedIPs))
                <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                    No IPs currently banned
                </div>
            @else
                <div class="space-y-2">
                    @foreach($bannedIPs as $ip)
                        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                            <div class="flex items-center space-x-3">
                                <span class="font-mono text-sm">{{ $ip }}</span>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <x-filament::button
                                    wire:click="unbanIP('{{ $ip }}')"
                                    color="warning"
                                    size="sm"
                                >
                                    Unban
                                </x-filament::button>
                                <x-filament::button
                                    wire:click="unbanIP('{{ $ip }}'); $set('addToWhitelist', true)"
                                    color="success"
                                    size="sm"
                                >
                                    Unban & Whitelist
                                </x-filament::button>
                                <x-filament::button
                                    wire:click="banPermanently('{{ $ip }}')"
                                    color="danger"
                                    size="sm"
                                    wire:confirm="Add {{ $ip }} to the permanent blacklist (iptables DROP all)?"
                                >
                                    Ban permanently
                                </x-filament::button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
            
            @if(!empty($bannedIPs))
                <div class="mt-4 flex justify-end">
                    <x-filament::button
                        wire:click="unbanAll"
                        color="danger"
                        wire:confirm="Are you sure you want to unban ALL IPs?"
                    >
                        Unban All
                    </x-filament::button>
                </div>
            @endif
        </x-filament::section>

        <!-- Manual Ban Form -->
        <x-filament::section>
            <x-slot name="heading">
                Manual Ban
            </x-slot>
            
            {{-- type=button + wire:click avoids native GET form submit clearing the field without Livewire --}}
            <div class="space-y-4">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Short-term jail ban (UDP/5060). Use <strong>Ban permanently</strong> on a listed IP, or check the box below, for iptables DROP-all. Manage/remove entries on the Blacklist page.
                </p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-center">
                    <x-filament::input.wrapper>
                        <x-filament::input
                            type="text"
                            wire:model.live="manualBanIP"
                            placeholder="Enter IP address to ban"
                            wire:keydown.enter.prevent="manualBan"
                        />
                    </x-filament::input.wrapper>

                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200 cursor-pointer">
                        <x-filament::input.checkbox wire:model.live="alsoPermanent" />
                        <span>Also add to blacklist</span>
                    </label>
                    
                    <x-filament::button type="button" color="danger" wire:click="manualBan">
                        Ban IP
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>

        <!-- Refresh Button -->
        <div class="flex justify-end">
            <x-filament::button wire:click="loadStatus(true)" color="gray">
                Refresh Status
            </x-filament::button>
        </div>
    </div>
</x-filament-panels::page>
