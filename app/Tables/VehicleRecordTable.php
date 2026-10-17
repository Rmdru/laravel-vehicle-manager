<?php

declare(strict_types=1);

namespace App\Tables;

use App\Contracts\HasIcon;
use App\Contracts\HasLabel;
use App\Support\CurrentVehicle;
use App\Support\Html;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use TranquilTools\TableBuilder\AbstractTable;
use TranquilTools\TableBuilder\TableBuilder;

/**
 * Base for the tables that list records of the current vehicle.
 */
abstract class VehicleRecordTable extends AbstractTable
{
    /** @var class-string<Model> */
    protected string $model;

    /** Column the "date from" / "date until" filters apply to. */
    protected string $dateColumn = 'date';

    /** @var Collection<int, \stdClass>|null */
    private ?Collection $records = null;

    public function for(): Builder
    {
        $request = request();

        return $this->model::query()
            ->where('vehicle_id', CurrentVehicle::get()?->id)
            ->when($request->query('date_from'), fn (Builder $query, $date) => $query->whereDate($this->dateColumn, '>=', $date))
            ->when($request->query('date_until'), fn (Builder $query, $date) => $query->whereDate($this->dateColumn, '<=', $date));
    }

    /**
     * The records the table lists, narrowed down by the date range and the
     * chosen filters. For figures that should follow what the table shows.
     */
    public function filteredQuery(): Builder
    {
        $query = $this->for();

        // The table knows which filters are set: it reads them from the request.
        foreach ($this->make()->filters() as $filter) {
            if (filled($filter->value) && is_callable($filter->callback)) {
                ($filter->callback)($query, (string) $filter->value);
            }
        }

        return $query;
    }

    /**
     * @param  array<int|string, string>  $options
     * @param  callable(Builder, string): mixed  $callback
     */
    protected function filter(TableBuilder $table, string $key, string $label, array $options, callable $callback): void
    {
        // With a single option there is nothing to choose between.
        if (count($options) < 2) {
            return;
        }

        $table->callbackFilter($key, $options, $callback, $label);
    }

    /**
     * Filter on a column with a fixed set of values. Only the values that occur
     * in the vehicle's records are offered.
     *
     * @param  array<string, string>  $labels
     */
    protected function optionsFilter(TableBuilder $table, string $column, string $label, array $labels, bool $json = false): void
    {
        $used = $this->usedValues($column);

        $options = collect($labels)
            ->filter(fn (string $name, string|int $value) => $used->contains((string) $value))
            ->all();

        $this->filter($table, $column, $label, $options, fn (Builder $query, string $value) => $json
            ? $query->whereJsonContains($column, $value)
            : $query->where($column, $value));
    }

    /**
     * @param  class-string<\BackedEnum&HasLabel>  $enum
     */
    protected function enumFilter(TableBuilder $table, string $column, string $label, string $enum, bool $json = false): void
    {
        $labels = collect($enum::cases())
            ->mapWithKeys(fn ($case) => [$case->value => (string) $case->getLabel()])
            ->all();

        $this->optionsFilter($table, $column, $label, $labels, $json);
    }

    protected function countryFilter(TableBuilder $table, string $column = 'country'): void
    {
        $countries = collect(config('countries'))->map(fn (array $country) => (string) $country['name'])->all();

        $this->optionsFilter($table, $column, __('Country'), $countries);
    }

    protected function booleanFilter(TableBuilder $table, string $column, string $label): void
    {
        $this->filter($table, $column, $label, ['1' => (string) __('Yes'), '0' => (string) __('No')], fn (Builder $query, string $value) => $value === '1'
            ? $query->where($column, true)
            : $query->where(fn (Builder $query) => $query->where($column, false)->orWhereNull($column)));
    }

    /**
     * Filter on whether the period of a record covers today.
     */
    protected function activeFilter(TableBuilder $table, string $endColumn = 'end_date'): void
    {
        $this->filter($table, 'status', __('Status'), ['active' => __('Active'), 'ended' => __('Ended')], fn (Builder $query, string $value) => $value === 'active'
            ? $query->where(fn (Builder $query) => $query->whereNull($endColumn)->orWhereDate($endColumn, '>=', today()))
            : $query->whereDate($endColumn, '<', today()));
    }

    /**
     * The distinct values of a column in the vehicle's records. Columns that
     * hold a list count for each of their values.
     *
     * @return Collection<int, string>
     */
    protected function usedValues(string $column): Collection
    {
        // One query serves all filters of the table.
        $this->records ??= $this->model::query()
            ->where('vehicle_id', CurrentVehicle::get()?->id)
            ->toBase()
            ->get();

        return $this->records
            ->pluck($column)
            ->flatMap(fn ($value) => is_string($value) && str_starts_with($value, '[') ? (array) json_decode($value, true) : [$value])
            ->filter(fn ($value) => filled($value) || $value === 0)
            ->map(fn ($value) => (string) $value)
            ->unique()
            ->values();
    }

    protected function date(mixed $value, string $format = 'll'): string
    {
        return $value ? Carbon::parse($value)->isoFormat($format) : '';
    }

    protected function period(?CarbonInterface $start, ?CarbonInterface $end, string $format = 'll', ?string $openEnd = null): string
    {
        return $this->date($start, $format).' - '.($end ? $this->date($end, $format) : ($openEnd ?? __('Unknown')));
    }

    protected function money(mixed $value, int $decimals = 2): string
    {
        return is_numeric($value) ? '€ '.number_format((float) $value, $decimals, ',', '.') : '';
    }

    /**
     * A plain cell value. Cells are rendered as markup, so the text is escaped here.
     */
    protected function text(mixed $text): HtmlString
    {
        return new HtmlString(e((string) $text));
    }

    /**
     * A cell with an amount of money, which stands out from the other values.
     */
    protected function amount(mixed $text): HtmlString
    {
        return new HtmlString('<span class="font-semibold whitespace-nowrap tabular-nums">'.e((string) $text).'</span>');
    }

    /**
     * @param  class-string<\BackedEnum&HasLabel>  $enum
     */
    protected function enumBadge(string $enum, mixed $value): HtmlString
    {
        $badges = collect((array) $value)
            ->filter()
            ->map(function ($item) use ($enum) {
                $case = $enum::tryFrom((string) $item);

                return Html::badge(
                    $case?->getLabel() ?? (string) $item,
                    icon: $case instanceof HasIcon ? $case->getIcon() : null,
                );
            });

        return new HtmlString($badges->implode(' '));
    }
}
