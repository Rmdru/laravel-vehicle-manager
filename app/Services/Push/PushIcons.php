<?php

declare(strict_types=1);

namespace App\Services\Push;

/**
 * The images of a push notification. Android shows no SVG in a notification,
 * so `npm run build` turns the icons of resources/icons into PNGs in
 * public/push-icons (see scripts/push-icons.mjs): one per urgency, white on a
 * circle in its color, as under "Needs attention".
 *
 * Paths rather than full URLs: messages are sent from the command line, which
 * only knows APP_URL. The service worker loads them from the address the
 * device opened the app at.
 */
class PushIcons
{
    /** For a subject without an icon of its own, or one that is not there. */
    public const FALLBACK = 'mdi:bell-ring-outline';

    public const DIRECTORY = 'push-icons';

    /**
     * The large image beside the text: the icon of the subject, in the color of the urgency.
     */
    public function icon(?string $icon, string $urgency): string
    {
        foreach ([$icon, self::FALLBACK] as $name) {
            $path = $this->path((string) $name, $urgency);

            if ($path !== null && is_file(public_path($path))) {
                return '/'.$path;
            }
        }

        return '/icons/icon-192.png';
    }

    /**
     * The favicon in white in the status bar, that tells the app the notification is from.
     */
    public function badge(): string
    {
        return '/'.self::DIRECTORY.'/badge.png';
    }

    private function path(string $icon, string $urgency): ?string
    {
        if (! preg_match('/^([a-z0-9-]+):([a-z0-9-]+)$/', $icon, $match)) {
            return null;
        }

        return self::DIRECTORY."/{$match[1]}/{$match[2]}-{$urgency}.png";
    }
}
