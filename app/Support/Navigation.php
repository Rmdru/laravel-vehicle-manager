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
                $item(__('Tips'), 'tips', 'ic:round-auto-awesome'),
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
            'Cheapest', 'Close', 'Construction date', 'Cost types', 'Country', 'Create', 'Dashboard', 'Date', 'Date from',
            'Date range:', 'Date until', 'Delete', 'Duplicate', 'Edit', 'Filters', 'Fuel prices', 'Fuel prices abroad', 'Fuel type',
            'Fuel usage by fuel type', 'Gas station', 'Highest price', 'Item', 'Language', 'Latest:', 'Log out', 'Lowest price',
            'Mileage', 'Most expensive', 'Month', 'Month ago', 'Monthly costs', 'More information', 'Most recent costs',
            'My garage', 'Needs attention', 'No costs this month', 'No details available', 'No fuel price data available.',
            'No refuelings found', 'No statuses available to show.', 'Nothing to show', 'Notifications',
            'Number of refuelings', 'Period', 'Predictions', 'Price', 'Purchased with:', 'Refresh fuel prices', 'Reset',
            'Save', 'Select', 'Settings', 'Show', 'Statistics', 'Status', 'Timeline', 'Top gas stations', 'Total refueled', 'Type',
            'Vehicles', 'Visit count', 'Year', 'Year ago', 'years old', 'Are you sure you want to delete this?',
            'Break-even fuel: :km km', 'Break-even full: :km km', 'Current vehicle', 'Costs',
            'To make use of the vehicle manager you need to create your first vehicle.',
            'This is an overview of the vehicles that are in your garage.',
            'No costs in this period', 'Previous: € :price/l', 'Price decrease', 'Price increase',
            // The fuel advice.
            'Fuel advice', 'Generate advice', 'Generate again', 'Generating…', 'Low confidence', 'Medium confidence',
            'High confidence', 'Recommended day', 'Expected refueling', 'Estimated tank level', 'Price in :country',
            'Net savings per full tank', 'Distance from home', 'Super Plus turn (1 in 4 times)', 'Reasons', 'Sources', 'Generated on :date by :model.',
            'Indicative: check the price at the pump.', 'The fuel advice has just been generated.',
            'Get an advice on when and where to refuel, based on the fuel prices and your refuelings.',
            'Your cheapest gas station',
            ':difference below average',
            'Previous advice (:date)',
            'Followed',
            'Not followed',
            'Refueled on :date in :country for :price.',
            ':difference below the average price at the time of the advice (:average): :savings saved.',
            ':difference above the average price at the time of the advice (:average): :savings more.',
            // The tips.
            'Tips', 'Generate tips', 'The tips have just been generated.',
            'Get tips on maintenance, costs and the season, based on everything that is known about this vehicle.',
            'Important', 'Before :date', 'Based on your data', 'General knowledge', 'Done', 'Not relevant',
            'No tips right now: nothing stands out for this vehicle.', 'Indicative: check a tip before acting on it.',
            // The categories of the tips.
            'Maintenance', 'Season', 'Known issue', 'Costs and savings', 'Resale value', 'Rules and obligations', 'Use',
            // The overview of the tips, and what can be done with a tip.
            'All tips', 'Current tips', 'Kept', 'Keep', 'Remove', 'Kept on :date', 'Done on :date',
            'Keep a tip to look it up here later, without it showing on the dashboard.',
            'The tips you marked as done are listed here.',
        ];

        return collect($keys)->mapWithKeys(fn (string $key) => [$key => __($key)])->all();
    }
}
