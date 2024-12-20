<x-filament::page>
    <div class="w-fit flex gap-4 items-center">
        <div class="flex gap-2 items-center">
            @svg('si-' . $vehicle->brand, ['class' => 'w-8 h-8'])
            {{ $vehicle->brand . ' ' . $vehicle->model }}
        </div>
        <livewire:license-plate :vehicleId="$vehicle->id" />
    </div>
    <x-filament::section icon="gmdi-notifications-r" collapsible>
        <x-slot name="heading">
            <span class="flex gap-2">
                {{ __('Notifications') }}
            </span>
        </x-slot>
        <livewire:status-notification :vehicleId="$vehicle->id" />
    </x-filament::section>
    <x-filament::section icon="mdi-list-status" collapsible>
        <x-slot name="heading">
            <span class="flex gap-2">
                {{ __('Status') }}
            </span>
        </x-slot>
        @livewire(\App\Filament\Widgets\DashboardStatusOverview::class)
    </x-filament::section>
    <x-filament::section icon="gmdi-show-chart-r" collapsible>
        <x-slot name="heading">
            <span class="flex gap-2">
                {{ __('Statistics') }}
            </span>
        </x-slot>
        @livewire(\App\Filament\Widgets\DashboardStatsOverview::class)
    </x-filament::section>
</x-filament::page>
