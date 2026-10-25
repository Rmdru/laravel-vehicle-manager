<?php

declare(strict_types=1);

namespace App\Tables;

use App\Enums\FindingSeverity;
use App\Enums\FindingSource;
use App\Enums\FindingStatus;
use App\Models\Finding;
use App\Support\Html;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use TranquilTools\TableBuilder\TableBuilder;

class FindingsTable extends VehicleRecordTable
{
    protected string $model = Finding::class;

    /**
     * The open ones first: what is resolved or dropped is history.
     */
    public function for(): Builder
    {
        return parent::for()->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [FindingStatus::Open->value]);
    }

    public function configure(TableBuilder $table): void
    {
        $table
            ->defaultSort('-date')
            ->withGlobalSearch(columns: ['title', 'description', 'garage'])
            ->column('date', __('Date'), sortable: true, as: fn ($value, Finding $finding) => $this->text($this->date($finding->date)))
            ->column('title', __('What'), sortable: true, as: fn ($value, Finding $finding) => new HtmlString(e($value).($finding->for_garage ? ' '.Html::badge(__('For the garage'), icon: 'mdi:garage-alert') : '')))
            ->column('source', __('Source'), sortable: true, as: fn ($value, Finding $finding) => $this->enumBadge(FindingSource::class, $finding->source?->value))
            ->column('severity', __('Urgency'), sortable: true, as: fn ($value, Finding $finding) => $this->enumBadge(FindingSeverity::class, $finding->severity?->value))
            ->column('status', __('Status'), sortable: true, as: fn ($value, Finding $finding) => $this->enumBadge(FindingStatus::class, $finding->status?->value))
            ->column('estimated_cost', __('Estimated costs'), sortable: true, as: fn ($value) => filled($value) ? $this->amount($this->money($value)) : '')
            ->paginate(25);

        $this->enumFilter($table, 'status', __('Status'), FindingStatus::class);
        $this->enumFilter($table, 'source', __('Source'), FindingSource::class);
        $this->enumFilter($table, 'severity', __('Urgency'), FindingSeverity::class);
        $this->booleanFilter($table, 'for_garage', __('For the garage'));
    }
}
