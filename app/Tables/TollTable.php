<?php

declare(strict_types=1);

namespace App\Tables;

use App\Enums\TollPaymentCircumstances;
use App\Enums\TollPaymentMethod;
use App\Enums\TollType;
use App\Models\Toll;
use App\Support\Html;
use Illuminate\Support\HtmlString;
use TranquilTools\TableBuilder\TableBuilder;

class TollTable extends VehicleRecordTable
{
    protected string $model = Toll::class;

    public function configure(TableBuilder $table): void
    {
        $table
            ->defaultSort('-date')
            ->withGlobalSearch(columns: ['start_location', 'end_location', 'toll_company'])
            ->column('country', __('Country'), sortable: true, as: fn ($value) => new HtmlString(Html::flag($value)))
            ->column('road', __('Road'), as: fn ($value, Toll $toll) => new HtmlString(Html::roadBadges($toll->country, $toll->road_type, $toll->road)))
            ->column('date', __('Date'), sortable: true, as: fn ($value, Toll $toll) => $this->text($this->date($toll->date)))
            ->column('start_location', __('Location/section'), sortable: true, as: fn ($value, Toll $toll) => filled($toll->end_location)
                ? $this->text($toll->start_location.' - '.$toll->end_location)
                : $this->text($toll->start_location))
            ->column('price', __('Price'), sortable: true, as: fn ($value) => $this->amount($this->money($value)))
            ->column('toll_company', __('Toll company'), sortable: true, as: fn ($value) => $this->text($value))
            ->column('payment_circumstances', __('Payment circumstances'), as: fn ($value) => $this->enumBadge(TollPaymentCircumstances::class, $value))
            ->column('payment_method', __('Payment method'), as: fn ($value) => $this->enumBadge(TollPaymentMethod::class, $value))
            ->paginate(25);

        $this->countryFilter($table);
        $this->enumFilter($table, 'type', __('Type'), TollType::class);
        $this->enumFilter($table, 'payment_circumstances', __('Payment circumstances'), TollPaymentCircumstances::class);
        $this->enumFilter($table, 'payment_method', __('Payment method'), TollPaymentMethod::class);
    }
}
