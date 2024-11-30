<x-filament::page>
    @if ($predictions->count())
        <x-filament::section icon="gmdi-auto-awesome-r" class="my-3" collapsible collapsed>
            <x-slot name="heading">
                {{ __('Predictions') }}
            </x-slot>
            @foreach($predictions as $month => $itemsInMonth)
                <h1 class="font-bold text-xl">{{ str($month)->ucfirst() }}</h1>
                @foreach ($itemsInMonth as $item)
                    <x-filament::section :icon="$item->categoryIcon" class="my-4">
                        <x-slot name="heading">
                            {{ $item->title }}
                        </x-slot>
                        <div class="flex gap-8 items-center">
                            <div
                                class="p-2 rounded-full bg-white w-5/12 max-w-12 flex items-center text-black [&>svg]:max-h-8 [&>svg]:mx-auto">
                                @svg($item->icon ?? $item->categoryIcon)
                            </div>
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
                                @if (! empty($item->badges))
                                    @foreach($item->badges as $badge)
                                        <x-filament::badge :color="$badge['color']" :icon="$badge['icon']">
                                            {{ $badge['title'] }}
                                        </x-filament::badge>
                                    @endforeach
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
                            <div
                                class="p-2 rounded-full bg-white w-5/12 max-w-12 flex items-center text-black [&>svg]:max-h-8 [&>svg]:mx-auto">
                                @svg($item->icon)
                            </div>
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
                            <x-filament::link href="/account/maintenances/{{ $item->id }}" color="white"
                                              icon="gmdi-remove-red-eye-r" class="last-of-type:ml-auto">
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
                            <div class="p-2 rounded-full bg-white w-5/12 max-w-12 h-12 flex items-center"><img
                                    src="{{ $item->icon }}" /></div>
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
                            <x-filament::link href="/account/refuelings/{{ $item->id }}" color="white"
                                              icon="gmdi-remove-red-eye-r" class="last-of-type:ml-auto">
                                {{ __('Show') }}
                            </x-filament::link>
                        </div>
                    </x-filament::section>
                @elseif($item instanceof App\Models\Insurance)
                    <x-filament::section icon="mdi-shield-car" class="mt-6" collapsible>
                        <x-slot name="heading">
                            {{ __('Insurance') }}
                        </x-slot>
                        <div class="flex gap-8 items-center">
                            <div
                                class="p-2 rounded-full bg-white w-5/12 max-w-12 flex items-center text-black [&>svg]:max-h-8 [&>svg]:mx-auto">
                                @svg($item->icon)
                            </div>
                            <div class="flex gap-2 flex-col">
                                <div class="flex gap-2 items-center">
                                    <x-gmdi-calendar-month-r class="w-6 text-gray-400 dark:text-gray-500" />
                                    {{ $item->date->isoFormat('MMM D, Y') }}
                                </div>
                                <div class="flex gap-2 items-center">
                                    <x-mdi-hand-coin-outline class="w-6 text-gray-400 dark:text-gray-500" />
                                    € {{ $item->price }}
                                </div>
                            </div>
                            <div class="flex gap-2 flex-col">
                                <div class="flex gap-2 items-center">
                                    <x-mdi-office-building class="w-6 text-gray-400 dark:text-gray-500" />
                                    {{ $item->insurance_company }}
                                </div>
                            </div>
                            <div class="flex gap-2 flex-col">
                                <x-filament::badge color="gray" :icon="$item->typeIcon">
                                    {{ $item->type }}
                                </x-filament::badge>
                            </div>
                            <x-filament::link href="/account/insurances/{{ $item->id }}/edit" color="white"
                                              icon="gmdi-edit-r" class="last-of-type:ml-auto">
                                {{ __('Edit') }}
                            </x-filament::link>
                        </div>
                    </x-filament::section>
                @elseif($item instanceof App\Models\Tax)
                    <x-filament::section icon="mdi-highway" class="mt-6" collapsible>
                        <x-slot name="heading">
                            {{ __('Road tax') }}
                        </x-slot>
                        <div class="flex gap-8 items-center">
                            <div
                                class="p-2 rounded-full bg-white w-5/12 max-w-12 flex items-center text-black [&>svg]:max-h-8 [&>svg]:mx-auto">
                                @svg($item->icon)
                            </div>
                            <div class="flex gap-2 flex-col">
                                <div class="flex gap-2 items-center">
                                    <x-gmdi-calendar-month-r class="w-6 text-gray-400 dark:text-gray-500" />
                                    {{ $item->date->isoFormat('MMM D, Y') }}
                                </div>
                                <div class="flex gap-2 items-center">
                                    <x-mdi-hand-coin-outline class="w-6 text-gray-400 dark:text-gray-500" />
                                    € {{ $item->price }}
                                </div>
                            </div>
                            <x-filament::link href="/account/taxes/{{ $item->id }}/edit" color="white"
                                              icon="gmdi-edit-r" class="last-of-type:ml-auto">
                                {{ __('Edit') }}
                            </x-filament::link>
                        </div>
                    </x-filament::section>
                @elseif($item instanceof App\Models\Parking)
                    <x-filament::section icon="fas-parking" class="mt-6" collapsible>
                        <x-slot name="heading">
                            {{ __('Parking') }}
                        </x-slot>
                        <div class="flex gap-8 items-center">
                            <div
                                class="p-2 rounded-full bg-white w-5/12 max-w-12 flex items-center text-black [&>svg]:max-h-8 [&>svg]:mx-auto">
                                @svg($item->icon)
                            </div>
                            <div class="flex gap-2 flex-col">
                                <div class="flex gap-2 items-center">
                                    <x-gmdi-calendar-month-r class="w-6 text-gray-400 dark:text-gray-500" />
                                    {{ $item->start_time->isoFormat('MMM D, Y  H:mm') . ' t/m ' . $item->end_time->isoFormat('MMM D, Y H:mm') }}
                                </div>
                                <div class="flex gap-2 items-center">
                                    <x-mdi-hand-coin-outline class="w-6 text-gray-400 dark:text-gray-500" />
                                    € {{ $item->price }}
                                </div>
                            </div>
                            <div class="flex gap-2 flex-col">
                                <x-filament::badge color="gray" :icon="$item->typeIcon">
                                    {{ $item->type }}
                                </x-filament::badge>
                            </div>
                            <x-filament::link href="/account/parkings/{{ $item->id }}/edit" color="white"
                                              icon="gmdi-edit-r" class="last-of-type:ml-auto">
                                {{ __('Edit') }}
                            </x-filament::link>
                        </div>
                    </x-filament::section>
                @elseif($item instanceof App\Models\Toll)
                    <x-filament::section icon="maki-toll" class="mt-6" collapsible>
                        <x-slot name="heading">
                            {{ __('Toll') }}
                        </x-slot>
                        <div class="flex gap-8 items-center">
                            <div class="flex gap-2 flex-col">
                                <livewire:country-flag :country="$item->country" />
                                <livewire:road-badge :roadType="$item->road_type" :road="$item->road" :country="$item->country" />
                            </div>
                            <div class="flex gap-2 flex-col">
                                <div class="flex gap-2 items-center">
                                    <x-gmdi-calendar-month-r class="w-6 text-gray-400 dark:text-gray-500" />
                                    {{ $item->date->isoFormat('MMM D, Y') }}
                                </div>
                                <div class="flex gap-2 items-center">
                                    <x-mdi-hand-coin-outline class="w-6 text-gray-400 dark:text-gray-500" />
                                    € {{ $item->price }}
                                </div>
                            </div>
                            <div class="flex gap-2 flex-col">
                                <div class="flex gap-2 items-center">
                                    <x-gmdi-location-on-r class="w-6 text-gray-400 dark:text-gray-500" />
                                    {{ $item->start_location }}
                                </div>
                            </div>
                            <div class="flex gap-2 flex-col">
                                <x-filament::badge color="gray" :icon="$item->typeIcon">
                                    {{ $item->type }}
                                </x-filament::badge>
                            </div>
                            <x-filament::link href="/account/parkings/{{ $item->id }}/edit" color="white"
                                              icon="gmdi-edit-r" class="last-of-type:ml-auto">
                                {{ __('Edit') }}
                            </x-filament::link>
                        </div>
                    </x-filament::section>
                @elseif($item instanceof App\Models\Fine)
                    <x-filament::section icon="maki-police" class="mt-6" collapsible>
                        <x-slot name="heading">
                            {{ __('Fine') }}
                        </x-slot>
                        <div class="flex gap-8 items-center">
                            <div
                                class="p-2 rounded-full bg-white w-5/12 max-w-12 flex items-center text-black [&>svg]:max-h-8 [&>svg]:mx-auto">
                                @svg($item->icon)
                            </div>
                            <div class="flex gap-2 flex-col">
                                <div class="flex gap-2 items-center">
                                    <x-gmdi-gavel-r class="w-6 text-gray-400 dark:text-gray-500" />
                                    {{ $item->fact }}
                                </div>
                                <div class="flex gap-2 items-center">
                                    <x-gmdi-calendar-month-r class="w-6 text-gray-400 dark:text-gray-500" />
                                    {{ $item->date->isoFormat('MMM D, Y') }}
                                </div>
                                <div class="flex gap-2 items-center">
                                    <x-mdi-hand-coin-outline class="w-6 text-gray-400 dark:text-gray-500" />
                                    € {{ $item->price }}
                                </div>
                            </div>
                            <div class="flex gap-2 flex-col">
                                <livewire:country-flag :country="$item->country" />
                                <livewire:road-badge :roadType="$item->road_type" :road="$item->road" :country="$item->country" />
                            </div>
                            <div class="flex gap-2 flex-col">
                                <div class="flex gap-2 items-center">
                                    <x-gmdi-location-on-r class="w-6 text-gray-400 dark:text-gray-500" />
                                    {{ $item->location }}
                                </div>
                            </div>
                            <div class="flex gap-2 flex-col">
                                <x-filament::badge :color="$item->typeColor" :icon="$item->typeIcon">
                                    {{ $item->type }}
                                </x-filament::badge>
                            </div>
                            <x-filament::link href="/account/parkings/{{ $item->id }}/edit" color="white"
                                              icon="gmdi-edit-r" class="last-of-type:ml-auto">
                                {{ __('Edit') }}
                            </x-filament::link>
                        </div>
                    </x-filament::section>
                @elseif($item instanceof App\Models\Reconditioning)
                    <x-filament::section icon="mdi-car-wash" class="mt-6" collapsible>
                        <x-slot name="heading">
                            {{ __('Reconditioning') }}
                        </x-slot>
                        <div class="flex gap-8 items-center">
                            <div
                                class="p-2 rounded-full bg-white w-5/12 max-w-12 flex items-center text-black [&>svg]:max-h-8 [&>svg]:mx-auto">
                                @svg($item->icon)
                            </div>
                            <div class="flex gap-2 flex-col">
                                <div class="flex gap-2 items-center">
                                    <x-gmdi-calendar-month-r class="w-6 text-gray-400 dark:text-gray-500" />
                                    {{ $item->date->isoFormat('MMM D, Y') }}
                                </div>
                                <div class="flex gap-2 items-center">
                                    <x-mdi-hand-coin-outline class="w-6 text-gray-400 dark:text-gray-500" />
                                    € {{ $item->price }}
                                </div>
                                <div class="flex gap-2 items-center">
                                    <x-gmdi-location-on-r class="w-6 text-gray-400 dark:text-gray-500" />
                                    {{ $item->location }}
                                </div>
                            </div>
                            <div class="flex gap-2 flex-col">
                                @foreach($item->type as $key => $type)
                                    <x-filament::badge :icon="$item->typeIcon[$key]">
                                        {{ $item->type[$key] }}
                                    </x-filament::badge>
                                @endforeach
                            </div>
                            <div class="flex gap-2 flex-col">
                                <x-filament::badge>
                                    {{ $item->executor }}
                                </x-filament::badge>
                            </div>
                            <x-filament::link href="/account/parkings/{{ $item->id }}/edit" color="white"
                                              icon="gmdi-edit-r" class="last-of-type:ml-auto">
                                {{ __('Edit') }}
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
