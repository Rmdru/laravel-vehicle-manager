<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An article the tips can be about: news, a test or a recall, with the
 * vehicles it applies to (see VehicleNewsService).
 *
 * @property array{brands?: list<string>, models?: list<string>, engines?: list<string>, powertrains?: list<string>, built_from?: int|null, built_until?: int|null} $applies_to
 */
class VehicleNews extends Model
{
    use MassPrunable;

    protected $table = 'vehicle_news';

    protected $fillable = [
        'url_hash',
        'url',
        'source',
        'title',
        'summary',
        'kind',
        'published_on',
        'countries',
        'applies_to',
    ];

    protected $casts = [
        'published_on' => 'date',
        'countries' => 'array',
        'applies_to' => 'array',
    ];

    public static function hash(string $url): string
    {
        return hash('sha256', rtrim(trim($url), '/'));
    }

    /**
     * Whether it applies to the vehicle: its country, and as far as it says
     * so, its make, model, engine, powertrain and year.
     */
    public function appliesTo(Vehicle $vehicle): bool
    {
        if ($this->countries !== [] && ! in_array($vehicle->country_registration, $this->countries, true)) {
            return false;
        }

        $applies = (array) $this->applies_to;
        $brand = Str::lower((string) (config('vehicles.brands')[$vehicle->brand] ?? $vehicle->brand));
        $contains = fn (array $values, ?string $value) => $values === [] || ($value !== null && collect($values)->contains(
            fn ($wanted) => ($wanted = Str::lower(trim((string) $wanted))) !== '' && (str_contains(Str::lower($value), $wanted) || str_contains($wanted, Str::lower($value)))
        ));
        $year = $vehicle->construction_date ? (int) $vehicle->construction_date->format('Y') : null;

        return $contains((array) ($applies['brands'] ?? []), $brand)
            && $contains((array) ($applies['models'] ?? []), $vehicle->model)
            && $contains((array) ($applies['engines'] ?? []), $vehicle->engine)
            // One way only: news about diesels is about one with AdBlue as well, not the other way around.
            && (empty($applies['powertrains']) || collect((array) $applies['powertrains'])->contains(fn ($wanted) => filled($wanted) && str_contains((string) $vehicle->powertrain, (string) $wanted)))
            && ($year === null || empty($applies['built_from']) || $year >= (int) $applies['built_from'])
            && ($year === null || empty($applies['built_until']) || $year <= (int) $applies['built_until']);
    }

    /**
     * Gone a year after: the tips keep a copy of where they come from.
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subYear());
    }
}
