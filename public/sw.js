/*
 * The service worker of the vehicle manager: it shows the push notifications,
 * also when the app and the browser are closed, and handles a tap on one. It
 * does not cache anything: the app is used online.
 *
 * A message is the JSON of App\Services\Push\PushSender: title, body, icon,
 * badge, tag, url, actions (each with the signed url to post to) and timestamp.
 */

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) =>
    event.waitUntil(self.clients.claim()),
);

self.addEventListener('push', (event) => {
    let message = {};

    try {
        message = event.data ? event.data.json() : {};
    } catch {
        message = { body: event.data ? event.data.text() : '' };
    }

    const actions = Array.isArray(message.actions) ? message.actions : [];

    event.waitUntil(
        self.registration.showNotification(message.title || 'Vehicle manager', {
            body: message.body || '',
            icon: message.icon,
            badge: message.badge,
            tag: message.tag,
            // A newer version of the same subject is announced again.
            renotify: Boolean(message.tag),
            timestamp: message.timestamp,
            actions: actions.map(({ action, title }) => ({ action, title })),
            data: {
                url: message.url || '/',
                actions: Object.fromEntries(
                    actions.map(({ action, url }) => [action, url]),
                ),
            },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    const notification = event.notification;
    const data = notification.data || {};

    notification.close();

    // A button: done in the background, the app stays closed.
    if (event.action && data.actions && data.actions[event.action]) {
        event.waitUntil(
            fetch(data.actions[event.action], {
                method: 'POST',
                credentials: 'omit',
                keepalive: true,
            }),
        );

        return;
    }

    event.waitUntil(open(new URL(data.url || '/', self.location.origin).href));
});

/*
 * Opens the url in a window of the app that is open already, or in a new one.
 */
async function open(url) {
    const windows = await self.clients.matchAll({
        type: 'window',
        includeUncontrolled: true,
    });
    const existing = windows.find(
        (client) => new URL(client.url).origin === self.location.origin,
    );

    if (existing) {
        try {
            await existing.focus();

            return await existing.navigate(url);
        } catch {
            // Not a window of this service worker: a new one then.
        }
    }

    return self.clients.openWindow(url);
}

// When the browser renews the subscription of the device, it is stored again
// the next time the app is opened (see resources/js/lib/push.ts).
