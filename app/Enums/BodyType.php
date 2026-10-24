<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\HasLabel;

/**
 * The body of a vehicle: many models come in more than one (such as a Seat
 * Leon hatchback and Sportstourer), and the alternative powertrains are
 * compared in the same one.
 */
enum BodyType: string implements HasLabel
{
    case Hatchback = 'hatchback';
    case Sedan = 'sedan';
    case StationWagon = 'station_wagon';
    case Suv = 'suv';
    case Mpv = 'mpv';
    case Coupe = 'coupe';
    case Convertible = 'convertible';
    case Pickup = 'pickup';
    case Van = 'van';

    public function getLabel(): string
    {
        return match ($this) {
            self::Hatchback => __('Hatchback'),
            self::Sedan => __('Sedan'),
            self::StationWagon => __('Station wagon'),
            self::Suv => __('SUV'),
            self::Mpv => __('MPV'),
            self::Coupe => __('Coupé'),
            self::Convertible => __('Convertible'),
            self::Pickup => __('Pick-up'),
            self::Van => __('Van'),
        };
    }

    /** The name the AI model gets. */
    public function englishName(): string
    {
        return match ($this) {
            self::StationWagon => 'station wagon (estate)',
            self::Suv => 'SUV',
            self::Mpv => 'MPV',
            self::Coupe => 'coupé',
            self::Pickup => 'pick-up',
            default => $this->value,
        };
    }
}
