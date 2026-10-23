<?php

declare(strict_types=1);

namespace App\Ai;

use Closure;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

/**
 * The API keys of a provider, which the free tier limits one by one: the
 * configured key may hold several, separated by commas.
 */
class ApiKeys
{
    /**
     * The keys in random order, by the number they have in the configuration.
     * [null] for the key as configured, when there is none or a single one.
     *
     * @return array<int, string|null>
     */
    public static function shuffled(string $provider): array
    {
        $keys = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('ai.providers.'.$provider.'.key')),
        )));

        if (count($keys) < 2) {
            return [null];
        }

        $numbers = array_keys($keys);
        shuffle($numbers);

        return array_combine($numbers, array_map(fn (int $number) => $keys[$number], $numbers));
    }

    /**
     * The provider with the given key; the configured one without.
     */
    public static function provider(string $provider, ?string $key): Provider|string
    {
        return $key === null
            ? $provider
            : Ai::build([...config('ai.providers.'.$provider, []), 'key' => $key]);
    }

    /**
     * Asks an agent with every key, in random order, until one gives a
     * structured answer.
     *
     * @param  Closure(string|null): Agent  $agent  The agent with a key.
     *
     * @throws Throwable The last failure, when no key gave an answer.
     */
    public static function ask(string $provider, Closure $agent, string $question): StructuredAgentResponse
    {
        $failure = null;

        foreach (self::shuffled($provider) as $number => $key) {
            try {
                $response = $agent($key)->prompt($question);

                if (! $response instanceof StructuredAgentResponse) {
                    throw new RuntimeException('The agent gave no structured answer.');
                }

                return $response;
            } catch (Throwable $exception) {
                $failure = $exception;

                Log::warning('An AI agent failed, trying the next key', [
                    'agent' => $agent(null)::class,
                    'key' => $key === null ? null : $number + 1,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        throw $failure;
    }

    /**
     * Whether the provider refused the key itself, rather than the request.
     */
    public static function refused(Throwable $exception): bool
    {
        $request = $exception instanceof RequestException ? $exception : $exception->getPrevious();

        return $exception instanceof InsufficientCreditsException
            || ($request instanceof RequestException && in_array($request->response->status(), [401, 403], true));
    }
}
