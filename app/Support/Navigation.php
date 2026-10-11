<?php

declare(strict_types=1);

namespace App\Support;

class Navigation
{
    /**
     * The sidebar navigation. Icons are Iconify names of files in resources/icons.
     *
     * @return list<array{label: string, items: list<array{title: string, href: string, icon: string}>}>
     */
    public static function groups(): array
    {
        $item = fn (string $title, string $route, string $icon) => [
            'title' => $title,
            'href' => route($route, absolute: false),
            'icon' => $icon,
        ];

        return [
            ['label' => __('Overview'), 'items' => [
                $item(__('Dashboard'), 'dashboard', 'ic:round-bar-chart'),
                $item(__('Timeline'), 'timeline', 'ic:round-timeline'),
            ]],
            ['label' => __('Maintenance'), 'items' => [
                $item(__('Maintenance & repairs'), 'maintenances.index', 'mdi:car-wrench'),
                $item(__('Reconditioning & washing'), 'reconditionings.index', 'mdi:car-wash'),
                $item(__('Accidents & damage'), 'accidents.index', 'fa7-solid:car-burst'),
                $item(__('Products & accessory'), 'products.index', 'mdi:oil'),
            ]],
            ['label' => __('On the road'), 'items' => [
                $item(__('Refuelings'), 'refuelings.index', 'ic:round-local-gas-station'),
                $item(__('Parking'), 'parking.index', 'fa7-solid:square-parking'),
                $item(__('Toll'), 'toll.index', 'mdi:boom-gate'),
                $item(__('Fines'), 'fines.index', 'maki:police'),
                $item(__('Services'), 'services.index', 'mdi:tow-truck'),
                $item(__('Transport trains & ferry services'), 'ferries.index', 'mdi:train-car-flatbed-car'),
            ]],
            ['label' => __('Fixed costs'), 'items' => [
                $item(__('Insurances'), 'insurances.index', 'mdi:shield-car'),
                $item(__('Road taxes'), 'taxes.index', 'mdi:highway'),
                $item(__('Vignettes'), 'vignettes.index', 'mdi:sticker-text'),
                $item(__('Environmental stickers'), 'environmental-stickers.index', 'fa7-solid:leaf'),
            ]],
        ];
    }

    /**
     * Interface strings the Vue components need, translated for the current locale.
     *
     * @return array<string, string>
     */
    public static function translations(): array
    {
        $keys = [
            'Add a vehicle', 'Add refueling', 'Add small check', 'All', 'Apply', 'Average monthly costs', 'Average per month',
            'Average consumption', 'Average price', 'Average speed', 'Back', 'Cancel',
            'Cheapest gas stations', 'Close', 'Construction date', 'Cost types', 'Country', 'Create', 'Dashboard', 'Date', 'Date from',
            'Date range:', 'Date until', 'Delete', 'Duplicate', 'Edit', 'Filters', 'Fuel prices abroad', 'Fuel type',
            'Fuel usage by fuel type', 'Gas station', 'Item', 'Language', 'Latest:', 'Log out', 'Lowest price',
            'Mileage', 'Month', 'Month ago', 'Monthly costs', 'More information', 'Most recent costs',
            'My garage', 'Needs attention', 'No costs this month', 'No details available', 'No fuel price data available.',
            'No refuelings found', 'No statuses available to show.', 'Nothing to show', 'Notifications',
            'Number of refuelings', 'Period', 'Predictions', 'Price', 'Purchased with:', 'Refresh fuel prices', 'Reset',
            'Save', 'Select', 'Settings', 'Show', 'Statistics', 'Status', 'Timeline', 'Total refueled', 'Type',
            'Vehicles', 'Visit count', 'Year', 'Year ago', 'years old', 'Are you sure you want to delete this?',
            'Break-even fuel: :km km', 'Break-even full: :km km', 'Current vehicle', 'Costs',
            'To make use of the vehicle manager you need to create your first vehicle.',
            'This is an overview of the vehicles that are in your garage.',
        ];

        return collect($keys)->mapWithKeys(fn (string $key) => [$key => __($key)])->all();
    }
}
