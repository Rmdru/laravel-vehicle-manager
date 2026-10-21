<?php

declare(strict_types=1);

namespace App\Services\TripPlanner;

use Closure;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * The calls to the APIs of the trip planner, counted per day: TomTom gives a
 * number of calls a day for free, and the public Overpass servers come and go.
 */
class ApiUsage
{
    public const TOMTOM = 'tomtom';

    public const OVERPASS = 'overpass';

    /**
     * A middleware for the HTTP client that counts every call to TomTom and
     * to the Overpass servers, also the ones sent in a pool.
     */
    public static function middleware(): Closure
    {
        return fn (callable $handler) => function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $service = self::serviceOf($request);
            $started = microtime(true);
            $promise = $handler($request, $options);

            if ($service === null) {
                return $promise;
            }

            $record = fn (bool $succeeded) => self::record($service, self::kindOf($service, $request), $succeeded, microtime(true) - $started);

            return $promise->then(
                function (ResponseInterface $response) use ($record) {
                    $record($response->getStatusCode() < 400);

                    return $response;
                },
                function ($reason) use ($record) {
                    $record(false);

                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    /**
     * The calls to TomTom made today: its day starts at midnight UTC.
     */
    public static function tomTomToday(): int
    {
        return (int) DB::table('trip_planner_api_calls')
            ->where('date', Carbon::now('UTC')->toDateString())
            ->where('service', self::TOMTOM)
            ->sum(DB::raw('succeeded + failed'));
    }

    /**
     * Whether the calls that are nice to have, such as the traffic and the
     * congestion, are left out today, to keep the routes going within the
     * daily limit.
     */
    public static function tomTomSaving(): bool
    {
        $limit = (int) config('trip_planner.tomtom.daily_limit');

        try {
            return $limit > 0 && self::tomTomToday() >= $limit * (float) config('trip_planner.tomtom.saving_from');
        } catch (Throwable $exception) {
            // Without the count nothing is left out.
            Log::warning('The calls to TomTom could not be counted.', ['message' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * The calls of the last days, the latest first.
     *
     * @return list<array{date: string, service: string, kind: string, succeeded: int, failed: int, milliseconds: int}>
     */
    public static function since(Carbon $from): array
    {
        return DB::table('trip_planner_api_calls')
            ->where('date', '>=', $from->toDateString())
            ->orderByDesc('date')
            ->orderBy('service')
            ->orderBy('kind')
            ->get(['date', 'service', 'kind', 'succeeded', 'failed', 'milliseconds'])
            ->map(fn (object $row) => [
                'date' => Carbon::parse($row->date)->toDateString(),
                'service' => (string) $row->service,
                'kind' => (string) $row->kind,
                'succeeded' => (int) $row->succeeded,
                'failed' => (int) $row->failed,
                'milliseconds' => (int) $row->milliseconds,
            ])
            ->all();
    }

    public static function record(string $service, string $kind, bool $succeeded, float $seconds): void
    {
        $date = Carbon::now('UTC')->toDateString();

        try {
            DB::table('trip_planner_api_calls')->insertOrIgnore([
                'date' => $date, 'service' => $service, 'kind' => $kind, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('trip_planner_api_calls')
                ->where(['date' => $date, 'service' => $service, 'kind' => $kind])
                ->update([
                    $succeeded ? 'succeeded' : 'failed' => DB::raw(($succeeded ? 'succeeded' : 'failed').' + 1'),
                    'milliseconds' => DB::raw('milliseconds + '.(int) round($seconds * 1000)),
                    'updated_at' => now(),
                ]);
        } catch (Throwable $exception) {
            // Counting never stands in the way of the call itself.
            Log::warning('The API call could not be counted.', ['message' => $exception->getMessage()]);
        }
    }

    private static function serviceOf(RequestInterface $request): ?string
    {
        $url = (string) $request->getUri();

        if (str_starts_with($url, (string) config('trip_planner.tomtom.base_url'))) {
            return self::TOMTOM;
        }

        foreach ((array) config('trip_planner.overpass.servers') as $server) {
            if (str_starts_with($url, (string) $server)) {
                return self::OVERPASS;
            }
        }

        return null;
    }

    /**
     * What a call is for: the API of TomTom, or the Overpass server.
     */
    private static function kindOf(string $service, RequestInterface $request): string
    {
        if ($service === self::OVERPASS) {
            return $request->getUri()->getHost();
        }

        $path = $request->getUri()->getPath();

        return match (true) {
            str_contains($path, '/routing/') => 'routing',
            str_contains($path, '/search/') => 'search',
            str_contains($path, '/traffic/') => 'traffic',
            default => 'other',
        };
    }
}
