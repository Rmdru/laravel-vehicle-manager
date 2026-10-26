<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\WebPush;

/**
 * Delivers a push notification to devices, encrypted, through the push
 * services of their browsers (for Chrome on Android: Firebase Cloud Messaging).
 */
class PushTransport
{
    /**
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @param  array<string, mixed>  $payload  What the service worker shows (see public/sw.js).
     * @return array{delivered: list<int>, expired: list<int>} The ids of the devices it reached, and of the ones that are gone.
     */
    public function send(Collection $subscriptions, array $payload, string $urgency, string $topic): array
    {
        $webPush = new WebPush(['VAPID' => [
            'subject' => config('push.vapid.subject'),
            'publicKey' => config('push.vapid.public_key'),
            'privateKey' => config('push.vapid.private_key'),
        ]]);
        $byEndpoint = $subscriptions->keyBy('endpoint');
        $json = (string) json_encode($payload);

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification($subscription->toSubscription(), $json, [
                'TTL' => config('push.ttl'),
                // Something critical comes through when the phone is idle too.
                'urgency' => $urgency === 'critical' ? 'high' : 'normal',
                // A newer message of the same subject replaces one still waiting at the push service.
                'topic' => $topic,
            ]);
        }

        $result = ['delivered' => [], 'expired' => []];

        foreach ($webPush->flush() as $report) {
            $subscription = $byEndpoint->get($report->getEndpoint());

            if ($subscription === null) {
                continue;
            }

            if ($report->isSuccess()) {
                $result['delivered'][] = $subscription->id;
            } elseif ($report->isSubscriptionExpired()) {
                $result['expired'][] = $subscription->id;
            } else {
                Log::warning('A push notification could not be delivered', [
                    'subscription' => $subscription->id,
                    'reason' => $report->getReason(),
                ]);
            }
        }

        return $result;
    }
}
