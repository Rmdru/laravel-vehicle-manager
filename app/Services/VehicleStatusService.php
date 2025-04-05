<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatusNotificationOk;
use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use App\Support\StatusNotification;

class VehicleStatusService
{
    private array $types = [];

    public function __construct()
    {
        $this->types = StatusNotification::types();
    }

    public function getNotifications(Vehicle $vehicle): array
    {
        $notifications = [];

        if (in_array($vehicle->status, [VehicleStatus::Suspended->value, VehicleStatus::Sold->value, VehicleStatus::Destroyed->value])) {
            $notifications[] = $this->createNotification(
                type: 'success',
                text: __('No information available'),
                icon: 'fas-smile'
            );
            
            return $notifications;
        }

        $notificationMappings = StatusNotification::configuration();

        foreach ($notificationMappings as $mapping) {
            $status = $vehicle->{$mapping['statusKey']} ?? null;

            if (empty($status)) {
                continue;
            }

            $this->processNotification($notifications, $status['time'] ?? null, $mapping);
        }

        if (empty($notifications)) {
            $notifications[] = $this->createNotification(
                type: 'success',
                text: collect(StatusNotificationOk::cases())->map(fn ($case) => $case->getLabel())->random(),
                icon: 'fas-smile'
            );
        }

        return $notifications;
    }

    public function getBadge(Vehicle $vehicle): array
    {
        $notifications = $this->getNotifications($vehicle);

        if (empty($notifications)) {
            return [];
        }

        $notification = collect($notifications)->sortBy('priority')->first();

        if (! isset($this->types[$notification['type']])) {
            return [];
        }

        return $this->types[$notification['type']];
    }

    private function processNotification(array &$notifications, ?float $timeTill, array $mapping): void
    {
        if (! isset($timeTill)) {
            return;
        }

        $thresholds = $mapping['thresholds'];
        $messages = $mapping['messages'];
        $icon = $mapping['icon'];

        if (isset($thresholds['critical']) && $timeTill < $thresholds['critical']) {
            $notifications[] = $this->createNotification('critical', $messages['critical'], $icon);
            return;
        }

        if (isset($thresholds['warning']) && $timeTill < $thresholds['warning']) {
            $notifications[] = $this->createNotification('warning', $messages['warning'], $icon);
            return;
        }

        if (isset($thresholds['info']) && $timeTill < $thresholds['info']) {
            $notifications[] = $this->createNotification('info', $messages['info'], $icon);
        }
    }

    private function createNotification(string $type, string $text, string $icon = ''): array
    {
        return array_merge($this->types[$type], [
            'type' => $type,
            'text' => $text,
            'icon' => $icon,
            'typeIcon' => $this->types[$type]['icon'],
            'priority' => $this->types[$type]['priority'],
        ]);
    }
}