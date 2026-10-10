<?php

declare(strict_types=1);

namespace App\Support;

class Navigation
{
    /**
     * The sidebar navigation. Icons are file names in resources/icons.
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
            ['label' => __('Statistics'), 'items' => [
                $item(__('Dashboard'), 'dashboard', 'gmdi-bar-chart-r'),
                $item(__('Timeline'), 'timeline', 'gmdi-timeline-r'),
            ]],
            ['label' => __('Garage'), 'items' => [
                $item(__('Maintenance & repairs'), 'maintenances.index', 'mdi-car-wrench'),
                $item(__('Reconditioning & washing'), 'reconditionings.index', 'mdi-car-wash'),
                $item(__('Accidents & damage'), 'accidents.index', 'fas-car-crash'),
                $item(__('Products & accessory'), 'products.index', 'mdi-oil'),
            ]],
            ['label' => __('Fuel'), 'items' => [
                $item(__('Refuelings'), 'refuelings.index', 'gmdi-local-gas-station'),
            ]],
            ['label' => __('Insurance'), 'items' => [
                $item(__('Insurances'), 'insurances.index', 'mdi-shield-car'),
            ]],
            ['label' => __('Tax'), 'items' => [
                $item(__('Road taxes'), 'taxes.index', 'mdi-highway'),
                $item(__('Parking'), 'parking.index', 'fas-parking'),
                $item(__('Toll'), 'toll.index', 'maki-toll'),
                $item(__('Fines'), 'fines.index', 'maki-police'),
                $item(__('Vignettes'), 'vignettes.index', 'mdi-sticker-text'),
                $item(__('Environmental stickers'), 'environmental-stickers.index', 'fas-leaf'),
            ]],
            ['label' => __('Services'), 'items' => [
                $item(__('Services'), 'services.index', 'mdi-tow-truck'),
            ]],
            ['label' => __('Additional transportation methods'), 'items' => [
                $item(__('Transport trains & ferry services'), 'ferries.index', 'mdi-train-car-flatbed-car'),
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
            'Add a vehicle', 'Add small check', 'All', 'Apply', 'Average monthly costs', 'Average per month',
            'Average consumption', 'Average price', 'Average speed', 'Back', 'Cancel',
            'Cheapest gas stations', 'Close', 'Cost types', 'Country', 'Create', 'Dashboard', 'Date', 'Date from',
            'Date range:', 'Date until', 'Delete', 'Duplicate', 'Edit', 'Filters', 'Fuel prices abroad', 'Fuel type',
            'Fuel usage by fuel type', 'Gas station', 'Item', 'Language', 'Latest:', 'Log out', 'Lowest price',
            'Mileage', 'Month', 'Month ago', 'Monthly costs', 'More information', 'Most recent costs',
            'My garage', 'No costs this month', 'No details available', 'No fuel price data available.',
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
