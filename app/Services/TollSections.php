<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TollPaymentCircumstances;
use App\Enums\TollPaymentMethod;
use App\Enums\VehicleStatus;
use App\Models\Toll;
use App\Models\Vehicle;
use App\Support\Roads;
use App\Support\StatusNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The toll of a vehicle by section: the passages of the same location or
 * section of road, as they were recorded. What a section usually costs comes
 * from the passages themselves, so it holds for any toll road, and changes
 * when its price does.
 */
class TollSections
{
    /** Recent passages what a section usually costs is the median of. */
    private const USUAL_OF = 5;

    /** Days the pace of the passages is told by. */
    private const PACE_DAYS = 90;

    /** Days a passage that cost more than usual makes a notification. */
    private const SURCHARGE_DAYS = 60;

    /** How much more than usual a passage costs at least to have cost extra: in euros, and as a share. */
    private const SURCHARGE_EUROS = 2.0;

    private const SURCHARGE_SHARE = 0.5;

    /** Days with a passage in the pace days for a pace to tell anything: a single trip, there and back, does not. */
    private const PACE_AT_LEAST = 3;

    /** Camera passages paid by hand in the pace days that make a notification. */
    private const MANUAL_AT_LEAST = 3;

    /**
     * The sections passed in the period, the most expensive first, with what
     * they usually cost and what they come to at the pace of the last months.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(Vehicle $vehicle, ?string $start = null, ?string $end = null): array
    {
        $countries = config('countries');
        $since = today()->subDays(self::PACE_DAYS - 1);

        return $this->sections($vehicle->tolls()->get())
            ->map(function (Collection $passages) use ($start, $end, $countries, $since) {
                $inPeriod = $passages
                    ->when($start, fn (Collection $passages) => $passages->filter(fn (Toll $toll) => $toll->start_date->gte(CarbonImmutable::parse($start))))
                    ->when($end, fn (Collection $passages) => $passages->filter(fn (Toll $toll) => $toll->start_date->lte(CarbonImmutable::parse($end)->endOfDay())));

                if ($inPeriod->isEmpty()) {
                    return null;
                }

                // As the section was recorded the last time.
                $latest = $passages->last();
                $usual = $this->usual($passages);
                $recent = $passages->filter(fn (Toll $toll) => $toll->start_date->gte($since));
                $regular = $recent->unique(fn (Toll $toll) => $toll->start_date->toDateString())->count() >= self::PACE_AT_LEAST;
                $perMonth = $regular ? $recent->count() / (self::PACE_DAYS / 30.44) : 0;

                return [
                    'key' => $this->key($latest),
                    'label' => $this->label($latest),
                    'company' => $latest->toll_company,
                    'isoCode' => $countries[$latest->country]['iso_code'] ?? null,
                    'roads' => Roads::badges($latest->country, $latest->road_type, $latest->road),
                    'passages' => $inPeriod->count(),
                    'total' => $this->money($inPeriod->sum('price')),
                    'sum' => (float) $inPeriod->sum('price'),
                    'usual' => $this->money($usual),
                    'last' => $latest->start_date->isoFormat('ll'),
                    // At the pace of the last months: what it comes to when it goes on like this.
                    'perMonth' => number_format($perMonth, 1, ',', '.'),
                    'perYear' => $perMonth > 0 ? $this->money($perMonth * 12 * $usual) : null,
                ];
            })
            ->filter()
            ->sortByDesc('sum')
            ->map(fn (array $section) => collect($section)->except('sum')->all())
            ->values()
            ->all();
    }

    /**
     * What a toll road on a route costs, by the passages of the vehicle on a
     * section of a road the route takes there, in the same country: the
     * section passed most. Null when the vehicle never recorded one.
     *
     * @param  string  $isoCode  The ISO 2 code of the country the toll road is in.
     * @param  list<string>  $roads  The numbers of the roads the route takes on the toll road.
     * @return array{label: string, price: float, passages: int}|null
     */
    public function priceOn(Vehicle $vehicle, string $isoCode, array $roads): ?array
    {
        $roads = array_map($this->road(...), $roads);
        $countries = config('countries');

        $passages = $this->sections($vehicle->tolls)
            ->filter(function (Collection $passages) use ($roads, $countries, $isoCode) {
                $toll = $passages->first();

                return strtoupper((string) ($countries[$toll->country]['iso_code'] ?? '')) === strtoupper($isoCode)
                    && array_intersect($this->roads($toll), $roads) !== [];
            })
            ->sortByDesc(fn (Collection $passages) => $passages->count())
            ->first();

        return $passages === null ? null : [
            'label' => $this->label($passages->last()),
            'price' => round($this->usual($passages), 2),
            'passages' => $passages->count(),
        ];
    }

    /**
     * Notifications on what the toll costs more than it needs to: a passage
     * that cost clearly more than the section usually does (often a fee for
     * a reminder), and a camera toll paid by hand again and again.
     *
     * @return list<array<string, mixed>>
     */
    public function notifications(Vehicle $vehicle): array
    {
        if ($vehicle->status !== VehicleStatus::Drivable->value) {
            return [];
        }

        $types = StatusNotification::types();
        $notification = fn (string $key, string $text, string $url) => [
            ...$types['info'],
            'key' => $key,
            'type' => 'info',
            'text' => $text,
            'icon' => 'mdi:boom-gate',
            'typeIcon' => $types['info']['icon'],
            'hasModal' => false,
            'data' => [],
            'url' => $url,
            'urlLabel' => __('Toll'),
        ];
        $notifications = [];

        foreach ($this->sections($vehicle->tolls()->get()) as $passages) {
            foreach ($passages->filter(fn (Toll $toll) => $toll->start_date->gte(today()->subDays(self::SURCHARGE_DAYS))) as $toll) {
                $others = $passages->reject(fn (Toll $other) => $other->is($toll));
                $usual = $others->count() >= 2 ? $this->usual($others) : null;
                $extra = $usual === null ? 0 : (float) $toll->price - $usual;

                if ($extra >= max(self::SURCHARGE_EUROS, $usual * self::SURCHARGE_SHARE)) {
                    $notifications[] = $notification('toll_surcharge:'.$toll->id, __('The toll of :section on :date cost :price, :extra more than usual: probably a fee for a reminder. Pay a camera toll in time, or have it paid automatically.', [
                        'section' => $this->label($toll),
                        'date' => $toll->start_date->isoFormat('ll'),
                        'price' => $this->money((float) $toll->price),
                        'extra' => $this->money($extra),
                    ]), route('toll.edit', $toll));
                }
            }

            $manual = $passages->filter(fn (Toll $toll) => $toll->start_date->gte(today()->subDays(self::PACE_DAYS - 1))
                && $toll->payment_circumstances === TollPaymentCircumstances::Camera->value
                && $toll->payment_method === TollPaymentMethod::Online->value);

            if ($manual->count() >= self::MANUAL_AT_LEAST) {
                $notifications[] = $notification('toll_manual:'.md5($this->key($passages->first())), __('You often pay the camera toll of :section online yourself. With an account that pays automatically, a passage cannot be forgotten and no fee for a reminder is due.', [
                    'section' => $this->label($passages->last()),
                ]), route('toll.index'));
            }
        }

        return $notifications;
    }

    /**
     * The passages by section, the oldest first.
     *
     * @param  Collection<int, Toll>  $tolls
     * @return Collection<string, Collection<int, Toll>>
     */
    private function sections(Collection $tolls): Collection
    {
        return $tolls
            ->sortBy(fn (Toll $toll) => $toll->start_date->toDateString().' '.$toll->created_at)
            ->groupBy(fn (Toll $toll) => $this->key($toll))
            ->map(fn (Collection $passages) => $passages->values());
    }

    /**
     * What makes passages the same section: where, and on which road, in
     * either direction.
     */
    private function key(Toll $toll): string
    {
        $normalize = fn (?string $value) => mb_strtolower(trim((string) $value));
        $ends = [$normalize($toll->start_location), $normalize($toll->end_location)];

        if ($ends[1] !== '') {
            sort($ends);
        }

        return implode('|', [
            $normalize($toll->country),
            implode(',', $this->roads($toll)),
            ...$ends,
        ]);
    }

    private function label(Toll $toll): string
    {
        return filled($toll->end_location)
            ? trim((string) $toll->start_location).' – '.trim((string) $toll->end_location)
            : trim((string) $toll->start_location);
    }

    /**
     * The numbers of the roads of a passage, as the route planner gives them:
     * with the prefix of the road type of the country (such as A24).
     *
     * @return list<string>
     */
    private function roads(Toll $toll): array
    {
        return array_values(array_unique(array_map(
            fn (array $badge) => $this->road($badge['label']),
            Roads::badges($toll->country, $toll->road_type, $toll->road),
        )));
    }

    private function road(string $road): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', $road));
    }

    /**
     * What a section usually costs: the median of the recent passages, so
     * that a fee once paid on top does not count.
     *
     * @param  Collection<int, Toll>  $passages  The oldest first.
     */
    private function usual(Collection $passages): float
    {
        return (float) $passages->take(-self::USUAL_OF)->median('price');
    }

    private function money(float $amount): string
    {
        return '€ '.number_format($amount, 2, ',', '.');
    }
}
