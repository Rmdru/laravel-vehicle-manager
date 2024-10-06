<x-filament::page>
    @if ($predictions->count())
        <x-filament::section icon="gmdi-auto-awesome-r" class="my-3" collapsible collapsed>
            <x-slot name="heading">
                {{ __('Predictions') }}
            </x-slot>
            @foreach($predictions as $month => $itemsInMonth)
                    <h1 class="font-bold text-xl">{{ str($month)->ucfirst() }}</h1>
                    @foreach ($itemsInMonth as $item)
                        <x-filament::section icon="mdi-car-wrench" class="my-4">
                            <x-slot name="heading">
                                {{ __('Maintenance') }}
                            </x-slot>
                            <div class="flex gap-8 items-center">
                                <div class="p-2 rounded-full bg-white w-5/12 max-w-12 flex items-center text-black">@svg($item->icon)</div>
                                <div class="flex gap-2 flex-col">
                                    <div class="flex gap-2 items-center">
                                        <x-gmdi-calendar-month-r class="w-6 text-gray-400 dark:text-gray-500" />
                                        {{ $item->date->isoFormat('MMM D, Y') }}
                                    </div>
                                    <div class="flex gap-2 items-center">
                                        <x-gmdi-notifications-r class="w-6 text-gray-400 dark:text-gray-500" />
                                        {{ str($item->date->diffForHumans())->ucfirst() }}
                                    </div>
                                </div>
                                <div class="flex gap-2 flex-col">
                                    @if ($item->apk)
                                        <x-filament::badge color="gray" icon="gmdi-security">
                                            {{ __('MOT') }}
                                        </x-filament::badge>
                                    @endif
                                    @if ($item->type_maintenance)
                                        <x-filament::badge color="gray" icon="mdi-car-wrench">
                                            {{ __('Maintenance') }}
                                        </x-filament::badge>
                                    @endif
                                </div>
                            </div>
                        </x-filament::section>
                    @endforeach
            @endforeach
        </x-filament::section>
    @endif
    @foreach ($historyItems as $month => $itemsInMonth)
        <x-filament::fieldset>
            <h1 class="font-bold text-xl">{{ str($month)->ucfirst() }}</h1>
            @foreach ($itemsInMonth as $item)
                @if($item instanceof App\Models\Maintenance)
                    <x-filament::section icon="mdi-car-wrench" class="mt-6" collapsible>
                        <x-slot name="heading">
                            {{ __('Maintenance') }}
                        </x-slot>
                        <div class="flex gap-8 items-center">
                            <div class="p-2 rounded-full bg-white w-5/12 max-w-12 flex items-center text-black">@svg($item->icon)</div>
                            <div class="flex gap-2 flex-col">
                                <div class="flex gap-2 items-center">
                                    <x-gmdi-calendar-month-r class="w-6 text-gray-400 dark:text-gray-500" />
                                    {{ $item->date->isoFormat('MMM D, Y') }}
                                </div>
                                <div class="flex gap-2 items-center">
                                    <x-mdi-hand-coin-outline class="w-6 text-gray-400 dark:text-gray-500" />
                                    € {{ $item->total_price }}
                                </div>
                            </div>
                            <div class="flex gap-2 flex-col">
                                <div class="flex gap-2 items-center">
                                    <x-gmdi-route-r class="w-6 text-gray-400 dark:text-gray-500" />
                                    {{ $item->mileage_begin }} km
                                </div>
                                <div class="flex gap-2 items-center">
                                    <x-gmdi-location-on-r class="w-6 text-gray-400 dark:text-gray-500" />
                                    {{ $item->garage }}
                                </div>
                            </div>
                            <div class="flex items-start gap-2 flex-col">
                                @if ($item->apk)
                                    <x-filament::badge color="gray" icon="gmdi-security">
                                        {{ __('MOT') }}
                                    </x-filament::badge>
                                @endif
                                @if ($item->type_maintenance)
                                    <x-filament::badge color="gray" icon="mdi-car-wrench">
                                        {{ __('Maintenance') }}
                                    </x-filament::badge>
                                @endif
                            </div>
                            <x-filament::link href="/account/maintenances/{{ $item->id }}" color="white" icon="gmdi-remove-red-eye-r" class="last-of-type:ml-auto">
                                {{ __('Show') }}
                            </x-filament::link>
                        </div>
                    </x-filament::section>
                @elseif($item instanceof App\Models\Refueling)
                    <x-filament::section icon="gmdi-local-gas-station-r" class="mt-6" collapsible>
                        <x-slot name="heading">
                            {{ __('Refueling') }}
                        </x-slot>
                        <div class="flex gap-8 items-center">
                            <div class="p-2 rounded-full bg-white w-5/12 max-w-12 h-12 flex items-center"><img src="{{ $item->icon }}" /></div>
                            <div class="flex gap-2 flex-col">
                                <div class="flex gap-2 items-center">
                                    <x-gmdi-calendar-month-r class="w-6 text-gray-400 dark:text-gray-500" />
                                    {{ $item->date->isoFormat('MMM D, Y') }}
                                </div>
                                <div class="flex gap-2 items-center">
                                    <x-mdi-hand-coin-outline class="w-6 text-gray-400 dark:text-gray-500" />
                                    € {{ $item->total_price }}
                                </div>
                            </div>
                            <div class="flex gap-2 flex-col">
                                <div class="flex gap-2 items-center">
                                    <x-gmdi-route-r class="w-6 text-gray-400 dark:text-gray-500" />
                                    {{ $item->mileage_end }} km
                                </div>
                                <div class="flex gap-2 items-center">
                                    <x-gmdi-location-on-r class="w-6 text-gray-400 dark:text-gray-500" />
                                    {{ $item->gas_station }}
                                </div>
                            </div>
                            <div class="flex gap-2 flex-col">
                                <x-filament::badge color="gray" icon="gmdi-local-gas-station">
                                    {{ $item->fuel_type }}
                                </x-filament::badge>
                            </div>
                            <x-filament::link href="/account/refuelings/{{ $item->id }}" color="white" icon="gmdi-remove-red-eye-r" class="last-of-type:ml-auto">
                                {{ __('Show') }}
                            </x-filament::link>
                        </div>
                    </x-filament::section>
                @endif
            @endforeach
        </x-filament::fieldset>
    @endforeach
    @if (! $historyItems->count())
        <x-filament::fieldset>
            <h1 class="font-bold text-xl">{{ __('Nothing to show') }}</h1>
        </x-filament::fieldset>
    @endif
</x-filament::page>