<?php

declare(strict_types=1);

namespace App\Services\TripPlanner;

class Geo
{
    private const EARTH_RADIUS = 6371000;

    /**
     * The distance between two points, in meters.
     */
    public static function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * self::EARTH_RADIUS * asin(min(1, sqrt($a)));
    }

    /**
     * The key of the tile a point lies in.
     */
    public static function tile(float $lat, float $lon): string
    {
        $size = (float) config('trip_planner.tile_size');

        return (int) floor($lat / $size).':'.(int) floor($lon / $size);
    }

    /**
     * The bounding box of a tile: south, west, north, east.
     *
     * @return array{float, float, float, float}
     */
    public static function tileBounds(string $tile): array
    {
        $size = (float) config('trip_planner.tile_size');
        [$y, $x] = array_map('intval', explode(':', $tile));

        return [$y * $size, $x * $size, ($y + 1) * $size, ($x + 1) * $size];
    }

    /**
     * The tiles within a margin of the points of a route, in meters.
     *
     * @param  list<array{float, float}>  $points
     * @return list<string>
     */
    public static function tilesAlong(array $points, float $margin): array
    {
        $dLat = $margin / 111320;
        $tiles = [];

        foreach ($points as [$lat, $lon]) {
            $dLon = $margin / (111320 * max(0.1, cos(deg2rad($lat))));

            foreach ([-$dLat, 0, $dLat] as $offsetLat) {
                foreach ([-$dLon, 0, $dLon] as $offsetLon) {
                    $tiles[self::tile($lat + $offsetLat, $lon + $offsetLon)] = true;
                }
            }
        }

        return array_keys($tiles);
    }

    /**
     * The points of a line, simplified (Ramer-Douglas-Peucker) to within a
     * tolerance in meters: the indexes of the points that are kept.
     *
     * @param  list<array{float, float}>  $points
     * @return list<int>
     */
    public static function simplify(array $points, float $tolerance): array
    {
        $count = count($points);

        if ($count < 3) {
            return array_keys($points);
        }

        $keep = [0 => true, $count - 1 => true];
        $stack = [[0, $count - 1]];

        while ($stack) {
            [$first, $last] = array_pop($stack);
            $maxDistance = 0;
            $index = null;

            for ($i = $first + 1; $i < $last; $i++) {
                $distance = self::distanceToSegment($points[$i], $points[$first], $points[$last]);

                if ($distance > $maxDistance) {
                    $maxDistance = $distance;
                    $index = $i;
                }
            }

            if ($index !== null && $maxDistance > $tolerance) {
                $keep[$index] = true;
                $stack[] = [$first, $index];
                $stack[] = [$index, $last];
            }
        }

        ksort($keep);

        return array_keys($keep);
    }

    /**
     * The distance of a point to a segment in meters, on a local flat projection.
     *
     * @param  array{float, float}  $point
     * @param  array{float, float}  $start
     * @param  array{float, float}  $end
     */
    public static function distanceToSegment(array $point, array $start, array $end): float
    {
        [$x, $y] = self::project($point, $start[0]);
        [$x1, $y1] = self::project($start, $start[0]);
        [$x2, $y2] = self::project($end, $start[0]);

        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $lengthSquared = $dx * $dx + $dy * $dy;
        $t = $lengthSquared > 0 ? max(0, min(1, (($x - $x1) * $dx + ($y - $y1) * $dy) / $lengthSquared)) : 0;

        return hypot($x - ($x1 + $t * $dx), $y - ($y1 + $t * $dy));
    }

    /**
     * A point in meters on a flat projection around a latitude.
     *
     * @param  array{float, float}  $point
     * @return array{float, float}
     */
    public static function project(array $point, float $aroundLat): array
    {
        return [
            deg2rad($point[1]) * self::EARTH_RADIUS * cos(deg2rad($aroundLat)),
            deg2rad($point[0]) * self::EARTH_RADIUS,
        ];
    }

    /**
     * A line in the encoded polyline format (precision 5).
     *
     * @param  list<array{float, float}>  $points
     */
    public static function encodePolyline(array $points): string
    {
        $encoded = '';
        $previous = [0, 0];

        foreach ($points as $point) {
            foreach ([0, 1] as $axis) {
                $value = (int) round($point[$axis] * 1e5);
                $delta = $value - $previous[$axis];
                $previous[$axis] = $value;
                $delta = $delta < 0 ? ~($delta << 1) : $delta << 1;

                while ($delta >= 0x20) {
                    $encoded .= chr((0x20 | ($delta & 0x1F)) + 63);
                    $delta >>= 5;
                }

                $encoded .= chr($delta + 63);
            }
        }

        return $encoded;
    }
}
