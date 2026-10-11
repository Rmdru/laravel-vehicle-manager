<?php

declare(strict_types=1);

namespace App\Tables;

use App\Enums\FinePaymentMethod;
use App\Enums\FineProvider;
use App\Enums\FineSanction;
use App\Enums\FineType;
use App\Models\Fine;
use App\Support\Html;
use Illuminate\Support\HtmlString;
use TranquilTools\TableBuilder\TableBuilder;

class FinesTable extends VehicleRecordTable
{
    protected string $model = Fine::class;

    public function configure(TableBuilder $table): void
    {
        $table
            ->defaultSort('-date')
            ->withGlobalSearch(columns: ['fact', 'description', 'location', 'road'])
            ->column('icon', '', canBeHidden: false, as: fn ($value, Fine $fine) => new HtmlString(Html::rowIcon($fine->icon, 'maki:police')))
            ->column('fact', __('Fact'), sortable: true, as: fn ($value, Fine $fine) => new HtmlString(
                '<div class="font-medium">'.Html::iconText('ic:round-gavel', $fine->fact).'</div>'
                .(filled($fine->description) ? '<div class="text-xs text-muted-foreground">'.e(str($fine->description)->limit(80)).'</div>' : '')
            ))
            ->column('country', __('Country'), sortable: true, as: fn ($value) => new HtmlString(Html::flag($value)))
            ->column('road', __('Road'), sortable: true, as: fn ($value, Fine $fine) => new HtmlString(
                Html::roadBadges($fine->country, $fine->road_type, $fine->road)
                .(filled($fine->road_distance_marker) ? ' <span class="text-xs text-muted-foreground">@ '.e($fine->road_distance_marker).' km</span>' : '')
            ))
            ->column('location', __('Location'), sortable: true, as: fn ($value) => $this->text($value))
            ->column('date', __('Date'), sortable: true, as: fn ($value, Fine $fine) => $this->text($this->date($fine->date)))
            ->column('price', __('Price'), sortable: true, as: fn ($value) => $this->amount($this->money($value)))
            ->column('payed', __('Payed'), sortable: true, as: fn ($value, Fine $fine) => new HtmlString(
                $fine->payed ? Html::badge(__('Payed'), 'success', 'ic:round-check') : Html::badge(__('Pending payment'), 'danger', 'ic:sharp-timer')
            ))
            ->column('payment_method', __('Payment method'), sortable: true, hidden: true, as: fn ($value) => $this->enumBadge(FinePaymentMethod::class, $value))
            ->column('type', __('Type'), sortable: true, as: fn ($value) => $this->enumBadge(FineType::class, $value))
            ->column('provider', __('Provider'), sortable: true, as: fn ($value) => $this->enumBadge(FineProvider::class, $value))
            ->column('sanctions', __('Sanctions'), hidden: true, as: fn ($value, Fine $fine) => $this->enumBadge(FineSanction::class, $fine->sanctions))
            ->paginate(25);

        $this->booleanFilter($table, 'payed', __('Payed'));
        $this->enumFilter($table, 'type', __('Type'), FineType::class);
        $this->enumFilter($table, 'provider', __('Provider'), FineProvider::class);
        $this->countryFilter($table);
        $this->enumFilter($table, 'sanctions', __('Sanctions'), FineSanction::class, json: true);
    }
}
