<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductCategory;
use App\Enums\ReconditioningExecutor;
use App\Enums\ReconditioningType;
use App\Models\Reconditioning;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What washing the vehicle costs: at the carwash, by the owner and by others.
 * Washing yourself costs the detailing products bought in the period, which
 * last for several washes: they are divided over the washes.
 */
class WashingCosts
{
    public const SELF = 'self';

    public const CARWASH = 'carwash';

    public const OTHERS = 'others';

    /**
     * The washes and their costs in the period, by kind. Without a period,
     * as the other costs on the dashboard: of all time, from the month of the
     * first wash or detailing product on. Null when nothing was washed or
     * bought for it.
     *
     * @return array<string, mixed>|null
     */
    public function overview(Vehicle $vehicle, ?string $start = null, ?string $end = null): ?array
    {
        $from = $start ? CarbonImmutable::parse($start)->startOfDay() : null;
        $until = $end ? CarbonImmutable::parse($end)->endOfDay() : CarbonImmutable::today()->endOfDay();
        $inPeriod = fn ($query) => $query->when($from, fn ($query) => $query->where('date', '>=', $from))->where('date', '<=', $until);

        $washes = $inPeriod($vehicle->reconditionings())
            ->get()
            ->toBase()
            ->filter(fn (Reconditioning $reconditioning) => $this->kind($reconditioning) !== '');

        $purchases = $inPeriod($vehicle->products()->where('category', ProductCategory::Cleaning->value))->get();
        $products = (float) $purchases->sum('price');

        if ($washes->isEmpty() && $products <= 0) {
            return null;
        }

        $from ??= CarbonImmutable::parse($washes->pluck('date')->merge($purchases->pluck('date'))->min())->startOfMonth();
        $kinds = collect([self::SELF, self::CARWASH, self::OTHERS])
            ->mapWithKeys(fn (string $kind) => [$kind => $washes->filter(fn (Reconditioning $wash) => $this->kind($wash) === $kind)]);

        $totals = $kinds->map(fn (Collection $washes, string $kind) => (float) $washes->sum('price') + ($kind === self::SELF ? $products : 0));
        $perWash = $kinds->map(fn (Collection $washes, string $kind) => $washes->isEmpty() ? null : $totals[$kind] / $washes->count());

        $shown = $kinds->keys()->filter(fn (string $kind) => $kinds[$kind]->isNotEmpty() || $totals[$kind] > 0)->values();
        $count = $this->months($from, $until);
        $total = (float) $totals->sum();
        $washCount = $kinds->sum(fn (Collection $washes) => $washes->count());

        return [
            'months' => $count,
            'kinds' => $shown->map(fn (string $kind) => [
                'key' => $kind,
                'label' => $this->label($kind),
                'icon' => $this->icon($kind),
                'washes' => $kinds[$kind]->count(),
                'perMonth' => $this->money($totals[$kind] / $count),
                'perWash' => $perWash[$kind] === null ? null : $this->money($perWash[$kind]),
                'note' => $kind === self::SELF && $products > 0 ? __(':amount in detailing products', ['amount' => $this->money($products)]) : null,
            ])->all(),
            'combined' => [
                'washes' => $washCount,
                'perMonth' => $this->money($total / $count),
                'perWash' => $washCount > 0 ? $this->money($total / $washCount) : null,
            ],
            'insight' => $this->insight($perWash[self::SELF], $perWash[self::CARWASH]),
        ];
    }

    /**
     * The kind of wash a reconditioning is, or nothing when it is no wash of the
     * outside, such as cleaning the interior.
     */
    private function kind(Reconditioning $reconditioning): string
    {
        $type = (string) $reconditioning->type;

        return match (true) {
            str_contains($type, ReconditioningType::Carwash->value) => self::CARWASH,
            ! str_contains($type, ReconditioningType::ExteriorCleaning->value) => '',
            $reconditioning->executor === ReconditioningExecutor::Myself->value => self::SELF,
            default => self::OTHERS,
        };
    }

    /**
     * The months of the period, the first and the last one counted whole.
     */
    private function months(CarbonImmutable $from, CarbonImmutable $until): int
    {
        $months = 0;

        for ($month = $from->startOfMonth(); $month->lte($until); $month = $month->addMonthNoOverflow()) {
            $months++;
        }

        return $months;
    }

    private function insight(?float $self, ?float $carwash): ?string
    {
        if ($self === null || $carwash === null || abs($carwash - $self) < 0.5) {
            return null;
        }

        return __($carwash > $self
            ? 'A wash at the carwash costs you :carwash, washing yourself :self: the carwash is :difference a wash more expensive.'
            : 'A wash at the carwash costs you :carwash, washing yourself :self: washing yourself is :difference a wash more expensive.', [
                'carwash' => $this->money($carwash),
                'self' => $this->money($self),
                'difference' => $this->money(abs($carwash - $self)),
            ]);
    }

    private function label(string $kind): string
    {
        return match ($kind) {
            self::SELF => __('Washing yourself'),
            self::CARWASH => __('Carwash'),
            default => __('Washed by others'),
        };
    }

    private function icon(string $kind): string
    {
        return match ($kind) {
            self::SELF => 'mdi:spray-bottle',
            self::CARWASH => 'mdi:car-wash',
            default => 'mdi:account-group',
        };
    }

    private function money(float $amount): string
    {
        return '€ '.number_format($amount, 2, ',', '.');
    }
}
