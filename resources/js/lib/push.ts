import {
    destroy as destroySubscription,
    store as storeSubscription,
} from '@/routes/push-subscriptions';

/**
 * Push notifications on this device: the service worker in public/sw.js shows
 * them, also when the app is closed. The server keeps the subscription of
 * every device (see App\Http\Controllers\Settings\PushController).
 */

// When this device last told the server its subscription.
const REFRESHED_KEY = 'push-subscription-refreshed';

export function pushSupported(): boolean {
    return (
        typeof window !== 'undefined' &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window
    );
}

export function registerServiceWorker(): void {
    if (!pushSupported()) {
        return;
    }

    navigator.serviceWorker.register('/sw.js').catch(() => {
        // Not over HTTPS, or blocked by the browser: no push notifications then.
    });
}

export async function currentSubscription(): Promise<PushSubscription | null> {
    if (!pushSupported()) {
        return null;
    }

    const registration = await navigator.serviceWorker.getRegistration('/');

    return (await registration?.pushManager.getSubscription()) ?? null;
}

/**
 * Asks permission, subscribes the device and tells the server.
 */
export async function subscribe(
    publicKey: string,
): Promise<NotificationPermission> {
    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
        return permission;
    }

    const registration = await navigator.serviceWorker.register('/sw.js');
    await navigator.serviceWorker.ready;

    const subscription =
        (await registration.pushManager.getSubscription()) ??
        (await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: fromBase64Url(publicKey),
        }));

    await send('POST', storeSubscription().url, body(subscription));
    remember();

    return permission;
}

export async function unsubscribe(): Promise<void> {
    const subscription = await currentSubscription();

    if (!subscription) {
        return;
    }

    await send('DELETE', destroySubscription().url, {
        endpoint: subscription.endpoint,
    });
    await subscription.unsubscribe();
}

/**
 * The browser can renew the subscription of the device: the server gets it
 * again once a day the app is opened, so that it keeps reaching the device.
 */
export async function refreshSubscription(): Promise<void> {
    try {
        if (
            !pushSupported() ||
            Notification.permission !== 'granted' ||
            Number(localStorage.getItem(REFRESHED_KEY) ?? 0) >
                Date.now() - 24 * 60 * 60 * 1000
        ) {
            return;
        }

        const subscription = await currentSubscription();

        if (subscription) {
            await send('POST', storeSubscription().url, body(subscription));
            remember();
        }
    } catch {
        // Tried again the next time.
    }
}

function remember(): void {
    try {
        localStorage.setItem(REFRESHED_KEY, String(Date.now()));
    } catch {
        // Then it is sent again the next time.
    }
}

function body(subscription: PushSubscription): Record<string, unknown> {
    return {
        ...subscription.toJSON(),
        contentEncoding: (PushManager.supportedContentEncodings ?? []).includes(
            'aes128gcm',
        )
            ? 'aes128gcm'
            : 'aesgcm',
    };
}

async function send(
    method: 'POST' | 'DELETE',
    url: string,
    data: Record<string, unknown>,
): Promise<void> {
    const token = decodeURIComponent(
        document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '',
    );
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': token,
        },
        body: JSON.stringify(data),
    });

    if (!response.ok) {
        throw new Error(`The server answered ${response.status}`);
    }
}

function fromBase64Url(value: string): Uint8Array<ArrayBuffer> {
    const base64 = (value + '='.repeat((4 - (value.length % 4)) % 4))
        .replace(/-/g, '+')
        .replace(/_/g, '/');
    const raw = atob(base64);
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));

    for (let i = 0; i < raw.length; i++) {
        bytes[i] = raw.charCodeAt(i);
    }

    return bytes;
}
