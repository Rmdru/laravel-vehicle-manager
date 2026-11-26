<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Refueling;
use App\Models\Vehicle;
use App\Models\VehicleNews;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What changed about a vehicle since a moment: what was added or changed in
 * every record the facts of the advice are made of, a component of the plan
 * that became due, the consumption, the tires, the weather to come and the
 * news. Taken from the records themselves, by their dates, rather than from
 * a copy of the facts: a tip the owner handled can come back for one of
 * these, when the model finds it relevant again (see VehicleAdvisor).
 */
class VehicleAdviceChanges
{
    /** Records of a kind the model gets to see, at most: the most recent. */
    private const PER_KIND = 5;

    /** Characters of a text of a record, at most. */
    private const TEXT = 120;

    /** Days of the weather forecast: an event further off was not known when the tip was handled. */
    private const FORECAST_DAYS = 14;

    /** Difference in consumption that counts, as a part. */
    private const CONSUMPTION = 0.1;

    /** Refuelings the consumption since and before is the average of. */
    private const REFUELINGS = 5;

    /**
     * The records the facts are made of, with what tells about one.
     *
     * @return array<string, array{0: string, 1: callable(Model): string}>
     */
    private function records(): array
    {
        $text = fn (?string $value) => Str::limit(trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value))), self::TEXT);
        $date = fn ($value) => $value ? Carbon::parse($value)->toDateString() : null;

        return [
            'maintenances' => ['maintenance', fn ($m) => trim('Maintenance of '.$date($m->date).': '.implode(', ', (array) $m->type_maintenance).' '.$text($m->description))],
            'findings' => ['finding', fn ($f) => trim('Finding ('.self::scalar($f->status).', '.self::scalar($f->source).'): '.$f->title.' '.implode(', ', (array) $f->components))],
            'products' => ['product', fn ($p) => trim('Product of '.$date($p->date).': '.self::scalar($p->category).' '.$p->name.' '.($p->component ?? ''))],
            'reconditionings' => ['reconditioning', fn ($r) => 'Reconditioning of '.$date($r->date).': '.implode(', ', (array) self::scalar($r->type))],
            'insurances' => ['insurance', fn ($i) => trim('Insurance: '.$i->insurance_company.' '.self::scalar($i->type).' from '.$date($i->start_date))],
            'taxes' => ['road_tax', fn ($t) => 'Road tax from '.$date($t->start_date).' to '.$date($t->end_date)],
            'accidents' => ['accident', fn ($a) => trim('Accident of '.$date($a->datetime).': '.self::scalar($a->type).' '.$text($a->description))],
            'vignettes' => ['vignette', fn ($v) => 'Vignette for '.$v->country.' until '.$date($v->end_date)],
            'environmentalStickers' => ['environmental_sticker', fn ($s) => 'Environmental sticker for '.$s->country.' until '.$date($s->end_date)],
            'services' => ['service', fn ($s) => trim('Service of '.$date($s->date).': '.self::scalar($s->type).' '.$s->name)],
            'tolls' => ['toll', fn ($t) => trim('Toll of '.$date($t->start_date).': '.implode(' ', (array) $t->road).' '.$t->country)],
            'parkings' => ['parking', fn ($p) => trim('Parking of '.$date($p->start_time).': '.$p->location)],
            'ferries' => ['ferry', fn ($f) => 'Ferry of '.$date($f->start_date).': '.$f->start_location.' – '.$f->end_location],
            'fines' => ['fine', fn ($f) => trim('Fine of '.$date($f->date).': '.self::scalar($f->type).' '.$f->fact.' '.$f->country)],
            'garageListItems' => ['garage_list', fn ($g) => 'On the list for the garage: '.$text($g->text)],
            'components' => ['component', fn ($c) => 'Maintenance plan changed: '.$c->key],
        ];
    }

    /**
     * The changes since the moment, the most recent first. A change of the
     * weather is one the owner could not have seen coming then.
     *
     * @param  array<string, mixed>  $facts  The facts of the advice: their plan and forecast.
     * @return list<array{id: string, at: CarbonInterface, date: string, what: string, weather: bool}>
     */
    public function since(Vehicle $vehicle, CarbonInterface $since, array $facts): array
    {
        $changes = collect();

        foreach ($this->records() as $relation => [$kind, $describe]) {
            /** @var HasMany<Model, Vehicle> $records */
            $records = $vehicle->{$relation}();

            $records->where(fn ($query) => $query->where('created_at', '>', $since)->orWhere('updated_at', '>', $since))
                ->latest('updated_at')
                ->limit(self::PER_KIND)
                ->get()
                ->each(fn (Model $record) => $changes->push($this->change(
                    $kind.':'.($kind === 'component' ? $record->getAttribute('key') : $record->getKey()),
                    $record->getAttribute('updated_at'),
                    $describe($record),
                )));
        }

        $this->inspection($vehicle, $since, $changes);
        $this->plan($facts, $since, $changes);
        $this->refuelings($vehicle, $since, $changes);
        $this->weather($facts, $since, $changes);
        $this->news($facts, $since, $changes);

        return $changes
            ->sortByDesc(fn (array $change) => $change['at']->getTimestamp())
            ->unique('id')
            ->take(config('vehicle_advice.changes'))
            ->values()
            ->all();
    }

    /**
     * @return array{id: string, at: CarbonInterface, date: string, what: string, weather: bool}
     */
    private function change(string $id, CarbonInterface $at, string $what, bool $weather = false): array
    {
        return ['id' => $id, 'at' => $at, 'date' => $at->toDateString(), 'what' => $what, 'weather' => $weather];
    }

    /**
     * A new MOT, with its defects.
     *
     * @param  Collection<int, array<string, mixed>>  $changes
     */
    private function inspection(Vehicle $vehicle, CarbonInterface $since, Collection $changes): void
    {
        $inspection = ((array) $vehicle->rdw_data)['inspection'] ?? null;

        if (empty($inspection['date'])) {
            return;
        }

        $date = CarbonImmutable::parse($inspection['date'])->endOfDay();

        if ($date->gt($since)) {
            $defects = collect((array) ($inspection['defects'] ?? []))->pluck('description')->filter()->take(3)->implode('; ');
            $changes->push($this->change('mot:'.$date->toDateString(), $date, trim('MOT of '.$date->toDateString().'. '.$defects)));
        }
    }

    /**
     * A component of the plan that became due soon, due or overdue since: by
     * the date it crossed that threshold, which follows from its due date.
     *
     * @param  array<string, mixed>  $facts
     * @param  Collection<int, array<string, mixed>>  $changes
     */
    private function plan(array $facts, CarbonInterface $since, Collection $changes): void
    {
        foreach ((array) ($facts['maintenance_plan'] ?? []) as $item) {
            $state = $item['state'] ?? null;

            if (! in_array($state, [MaintenancePlanService::SOON, MaintenancePlanService::DUE, MaintenancePlanService::OVERDUE], true) || empty($item['next_due'])) {
                continue;
            }

            $days = match ($state) {
                MaintenancePlanService::OVERDUE => 0,
                default => config("maintenance_plan.components.{$item['component']}.{$state}_days") ?? config("maintenance_plan.{$state}_days"),
            };
            $from = CarbonImmutable::parse($item['next_due'])->subDays((int) $days);

            if ($from->gt($since) && $from->lte(now())) {
                $changes->push($this->change("plan:{$item['component']}:{$state}", $from, "Maintenance plan: {$item['component']} became {$state}"));
            }
        }
    }

    /**
     * Since the moment: a clearly different consumption, or other tires.
     *
     * @param  Collection<int, array<string, mixed>>  $changes
     */
    private function refuelings(Vehicle $vehicle, CarbonInterface $since, Collection $changes): void
    {
        $refuelings = $vehicle->refuelings->sortByDesc(fn (Refueling $refueling) => $refueling->date->toDateString().' '.$refueling->created_at)->values();
        $after = $refuelings->filter(fn (Refueling $refueling) => $refueling->created_at?->gt($since))->values();
        $before = $refuelings->reject(fn (Refueling $refueling) => $refueling->created_at?->gt($since))->values();

        if ($after->isEmpty()) {
            return;
        }

        $average = fn (Collection $refuelings) => $refuelings->take(self::REFUELINGS)->pluck('fuel_consumption')->filter(fn ($value) => (float) $value > 0)->avg();
        [$now, $then] = [$average($after), $average($before)];

        if ($after->count() >= 2 && $now && $then && abs($now - $then) / $then >= self::CONSUMPTION) {
            $changes->push($this->change('consumption', $after->first()->created_at, sprintf('Consumption %s from %.2f to %.2f', $now > $then ? 'up' : 'down', $then, $now)));
        }

        $tires = self::scalar($after->first()->tires);

        if ($tires && $before->isNotEmpty() && self::scalar($before->first()->tires) && $tires !== self::scalar($before->first()->tires)) {
            $changes->push($this->change('tires:'.$tires, $after->first()->created_at, "Driving on {$tires} tires now"));
        }
    }

    /**
     * Frost, snow or heat on its way that was not in the forecast yet.
     *
     * @param  array<string, mixed>  $facts
     * @param  Collection<int, array<string, mixed>>  $changes
     */
    private function weather(array $facts, CarbonInterface $since, Collection $changes): void
    {
        foreach (['first_frost_date' => 'frost', 'first_snow_date' => 'snow', 'first_hot_day_date' => 'heat'] as $field => $event) {
            $date = $facts['weather_forecast'][$field] ?? null;

            if ($date !== null && CarbonImmutable::parse($date)->gt(CarbonImmutable::parse($since)->addDays(self::FORECAST_DAYS))) {
                $changes->push($this->change("weather:{$event}:{$date}", now()->startOfDay(), ucfirst($event)." expected from {$date}", true));
            }
        }
    }

    /**
     * News about the vehicle that came out since.
     *
     * @param  array<string, mixed>  $facts
     * @param  Collection<int, array<string, mixed>>  $changes
     */
    private function news(array $facts, CarbonInterface $since, Collection $changes): void
    {
        $ids = array_map(fn (array $news) => (int) Str::after($news['id'], 'news:'), (array) ($facts['news'] ?? []));

        if ($ids === []) {
            return;
        }

        VehicleNews::query()->whereIn('id', $ids)->where('created_at', '>', $since)->get()
            ->each(fn (VehicleNews $news) => $changes->push($this->change('news:'.$news->id, $news->created_at, 'News: '.$news->title)));
    }

    private static function scalar(mixed $value): mixed
    {
        if (is_array($value)) {
            return implode(', ', array_map(fn ($item) => self::scalar($item), $value));
        }

        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
