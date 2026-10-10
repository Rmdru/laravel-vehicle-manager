<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\HasIcon;
use App\Contracts\HasLabel;
use App\Enums\AccidentType;
use App\Enums\MaintenanceTypeMaintenance;
use App\Enums\ParkingType;
use App\Enums\ReconditioningExecutor;
use App\Enums\ReconditioningType;
use App\Enums\ServiceType;
use App\Models\Insurance;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Support\Roads;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Everything that happened to (and is expected for) a vehicle, grouped by month.
 */
class TimelineService
{
    private const MAINTENANCE_TYPES = ['small_maintenance', 'maintenance', 'big_maintenance'];

    /**
     * @return list<array{month: string, items: list<array<string, mixed>>}>
     */
    public function history(Vehicle $vehicle): array
    {
        $insuranceTypes = config('insurances.types');
        $logos = config('refuelings.gas_station_logos');
        $fuelTypes = trans('fuel_types');

        $vehicle->load([
            'maintenances' => fn ($query) => $query->whereIn('type_maintenance', self::MAINTENANCE_TYPES),
            'refuelings', 'insurances', 'taxes', 'parkings', 'tolls', 'fines', 'reconditionings',
            'vignettes', 'environmentalStickers', 'ferries', 'products', 'services', 'accidents',
        ]);

        $items = collect();

        foreach ($vehicle->maintenances as $maintenance) {
            $items->push($this->item($maintenance, __('Maintenance'), empty($maintenance->type_maintenance) && $maintenance->apk ? 'gmdi-security' : 'mdi-car-wrench', 'maintenances', $maintenance->date, [
                'price' => $maintenance->total_price,
                'location' => $maintenance->garage,
                'badges' => array_values(array_filter([
                    $this->badge(MaintenanceTypeMaintenance::tryFrom((string) $maintenance->type_maintenance)?->getLabel(), icon: 'mdi-car-wrench'),
                    $maintenance->apk ? $this->badge(__('MOT'), icon: 'gmdi-security') : null,
                ])),
            ]));
        }

        foreach ($vehicle->refuelings as $refueling) {
            $brand = (string) str($refueling->gas_station)->lower()->explode(' ')->first();

            $items->push($this->item($refueling, __('Refueling'), 'gmdi-local-gas-station-r', 'refuelings', $refueling->date, [
                'logo' => $logos[$brand] ?? $logos['default'],
                'price' => round((float) $refueling->total_price, 2),
                'mileage' => $refueling->mileage_end && $refueling->mileage_begin ? $refueling->mileage_end - $refueling->mileage_begin : null,
                'location' => $refueling->gas_station,
                'badges' => [$this->badge($fuelTypes[$refueling->fuel_type] ?? $refueling->fuel_type)],
            ]));
        }

        // Insurances can overlap: every month counts once, for the insurance that was active.
        $vehicle->insurances
            ->flatMap(fn (Insurance $insurance) => $insurance->months)
            ->unique()
            ->each(function (string $month) use ($vehicle, $items, $insuranceTypes) {
                $insurance = Insurance::getActiveMonthlyRecordForMonth($vehicle->insurances, Carbon::parse($month.'-01'));

                if (! $insurance) {
                    return;
                }

                $items->push($this->item($insurance, __('Insurance'), 'mdi-shield-car', 'insurances', $this->invoiceDate($month, $insurance->invoice_day), [
                    'price' => $insurance->price,
                    'badges' => [$this->badge($insuranceTypes[$insurance->type]['name'] ?? null, icon: $insuranceTypes[$insurance->type]['icon'] ?? null)],
                ]));
            });

        foreach ($vehicle->taxes as $tax) {
            foreach ($tax->months as $month) {
                $items->push($this->item($tax, __('Road tax'), 'mdi-highway', 'taxes', $this->invoiceDate($month, $tax->invoice_day), [
                    'price' => $tax->price,
                ]));
            }
        }

        foreach ($vehicle->parkings as $parking) {
            $items->push($this->item($parking, __('Parking'), 'fas-parking', 'parking', $parking->end_time, [
                'price' => $parking->price,
                'location' => $parking->location,
                'badges' => [$this->enumBadge(ParkingType::tryFrom((string) $parking->type))],
            ]));
        }

        foreach ($vehicle->tolls as $toll) {
            $hasRoad = filled($toll->road_type) && filled($toll->road) && filled($toll->country);

            $items->push($this->item($toll, __('Toll'), 'maki-toll', 'toll', $toll->date, [
                'price' => $toll->price,
                'location' => filled($toll->end_location) ? $toll->start_location.' - '.$toll->end_location : $toll->start_location,
                'country' => $hasRoad ? $toll->country : null,
                'roads' => $hasRoad ? Roads::badges($toll->country, $toll->road_type, $toll->road) : [],
            ]));
        }

        foreach ($vehicle->fines as $fine) {
            $items->push($this->item($fine, __('Fine'), 'maki-police', 'fines', $fine->date, [
                'price' => $fine->price,
                'location' => $fine->location,
                'badges' => [$fine->payed ? $this->badge(__('Payed'), 'success', 'gmdi-check-r') : $this->badge(__('Pending payment'), 'danger', 'gmdi-timer-s')],
            ]));
        }

        foreach ($vehicle->reconditionings as $reconditioning) {
            $heading = ReconditioningType::tryFrom((string) $reconditioning->type)?->getLabel() ?? __('Reconditioning');

            $items->push($this->item($reconditioning, $heading, 'mdi-car-wash', 'reconditionings', $reconditioning->date, [
                'price' => $reconditioning->price,
                'location' => $reconditioning->location,
                'badges' => [$this->badge(ReconditioningExecutor::tryFrom((string) $reconditioning->executor)?->getLabel())],
            ]));
        }

        foreach ($vehicle->vignettes as $vignette) {
            $items->push($this->item($vignette, __('Vignette'), 'mdi-sticker-text', 'vignettes', $vignette->start_date, [
                'price' => $vignette->price,
                'country' => $vignette->country,
            ]));
        }

        foreach ($vehicle->environmentalStickers as $sticker) {
            $items->push($this->item($sticker, __('Environmental sticker'), 'fas-leaf', 'environmental-stickers', $sticker->start_date, [
                'price' => $sticker->price,
                'country' => $sticker->country,
            ]));
        }

        foreach ($vehicle->ferries as $ferry) {
            $items->push($this->item($ferry, __('Transport train & ferry service'), 'mdi-ferry', 'ferries', $ferry->start_date, [
                'price' => $ferry->price,
                'location' => $ferry->start_location.' - '.$ferry->end_location,
            ]));
        }

        foreach ($vehicle->products as $product) {
            $items->push($this->item($product, (string) $product->name, 'mdi-oil', 'products', $product->date, [
                'price' => $product->price,
            ]));
        }

        foreach ($vehicle->services as $service) {
            $heading = ServiceType::tryFrom((string) $service->type)?->getLabel() ?? __('Service');

            $items->push($this->item($service, $heading, ServiceType::tryFrom((string) $service->type)?->getIcon() ?? 'mdi-tow-truck', 'services', $service->date, [
                'price' => $service->price,
                'location' => $service->name,
            ]));
        }

        foreach ($vehicle->accidents as $accident) {
            $items->push($this->item($accident, __('Accident'), 'fas-car-crash', 'accidents', $accident->datetime, [
                'price' => $accident->total_price,
                'location' => $accident->location,
                'badges' => [$this->enumBadge(AccidentType::tryFrom((string) $accident->type))],
            ]));
        }

        return $this->groupByMonth($items);
    }

    /**
     * Upcoming maintenance and invoices.
     *
     * @return list<array{month: string, items: list<array<string, mixed>>}>
     */
    public function predictions(Vehicle $vehicle): array
    {
        $insuranceTypes = config('insurances.types');
        $items = collect();

        $lastMaintenance = Maintenance::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('type_maintenance', self::MAINTENANCE_TYPES)
            ->orderByDesc('date')
            ->first();

        if ($lastMaintenance) {
            $date = Carbon::parse($lastMaintenance->date);

            $next = match ($lastMaintenance->type_maintenance) {
                MaintenanceTypeMaintenance::SmallMaintenance->value => $date->addMonths(6),
                MaintenanceTypeMaintenance::Maintenance->value => $date->addMonths((int) ($vehicle->maintenance_interval_time ?: 12)),
                MaintenanceTypeMaintenance::BigMaintenance->value => $date->addYears(2),
                default => null,
            };

            if ($next?->isFuture()) {
                $items->push($this->item($lastMaintenance, __('Maintenance'), 'mdi-car-wrench', null, $next, [
                    'location' => $lastMaintenance->garage,
                    'badges' => [$this->badge(MaintenanceTypeMaintenance::from($lastMaintenance->type_maintenance)->getLabel(), icon: 'mdi-car-wrench')],
                ]));
            }
        }

        $vehicle->insurances
            ->filter(fn (Insurance $insurance) => $insurance->getNextInvoiceDate($insurance->start_date, $insurance->end_date, $insurance->invoice_day))
            ->groupBy(fn (Insurance $insurance) => $insurance->getNextInvoiceDate($insurance->start_date, $insurance->end_date, $insurance->invoice_day)->format('Y-m'))
            ->each(function (Collection $insurances, string $month) use ($items, $insuranceTypes) {
                $insurance = Insurance::getActiveMonthlyRecordForMonth($insurances, Carbon::createFromFormat('Y-m-d', $month.'-01'));
                $date = $insurance?->getNextInvoiceDate($insurance->start_date, $insurance->end_date, $insurance->invoice_day);

                if ($date) {
                    $items->push($this->item($insurance, __('Insurance'), 'mdi-shield-car', null, $date, [
                        'price' => $insurance->price,
                        'badges' => [$this->badge($insuranceTypes[$insurance->type]['name'] ?? null, icon: $insuranceTypes[$insurance->type]['icon'] ?? null)],
                    ]));
                }
            });

        foreach ($vehicle->taxes as $tax) {
            $date = $tax->getNextInvoiceDate($tax->start_date, $tax->end_date, $tax->invoice_day);

            if ($date) {
                $items->push($this->item($tax, __('Road tax'), 'mdi-highway', null, $date, ['price' => $tax->price]));
            }
        }

        return $this->groupByMonth($items);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function item(Model $record, string $heading, string $icon, ?string $routeName, DateTimeInterface|string|null $date, array $extra = []): array
    {
        $date = $date ? Carbon::parse($date) : null;
        $country = config('countries')[$extra['country'] ?? null] ?? null;

        return [
            'key' => $record->getKey().'-'.($date?->format('Ymd') ?? ''),
            'heading' => $heading,
            'icon' => $icon,
            'logo' => $extra['logo'] ?? null,
            'flag' => $country ? ['isoCode' => $country['iso_code'], 'name' => $country['name']] : null,
            'date' => $date?->isoFormat('ll'),
            'sort' => $date?->timestamp ?? 0,
            'month' => $date ? (string) str($date->isoFormat('MMMM Y'))->ucfirst() : '',
            'price' => is_numeric($extra['price'] ?? null) && (float) $extra['price'] > 0
                ? '€ '.number_format((float) $extra['price'], 2, ',', '.')
                : null,
            'mileage' => ! empty($extra['mileage']) ? $extra['mileage'].' km' : null,
            'location' => filled($extra['location'] ?? null) ? (string) $extra['location'] : null,
            'badges' => array_values(array_filter($extra['badges'] ?? [])),
            'roads' => $extra['roads'] ?? [],
            'url' => $routeName ? route($routeName.'.edit', $record->getKey()) : null,
        ];
    }

    /**
     * @return array{title: string, color: string, icon: string|null}|null
     */
    private function badge(?string $title, string $color = 'gray', ?string $icon = null): ?array
    {
        return filled($title) ? ['title' => $title, 'color' => $color, 'icon' => $icon] : null;
    }

    /**
     * @return array{title: string, color: string, icon: string|null}|null
     */
    private function enumBadge((HasLabel&HasIcon)|null $case): ?array
    {
        return $this->badge($case?->getLabel(), icon: $case?->getIcon());
    }

    private function invoiceDate(string $month, mixed $invoiceDay): Carbon
    {
        $date = Carbon::parse($month.'-01');

        // Clamp to the length of the month: an invoice day of 31 does not exist in February.
        return $date->day(min(max((int) $invoiceDay, 1), $date->daysInMonth));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return list<array{month: string, items: list<array<string, mixed>>}>
     */
    private function groupByMonth(Collection $items): array
    {
        return $items
            ->sortByDesc('sort')
            ->groupBy('month')
            ->map(fn (Collection $items, string $month) => ['month' => $month, 'items' => $items->values()->all()])
            ->values()
            ->all();
    }
}
