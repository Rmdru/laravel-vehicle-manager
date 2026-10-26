<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useT } from '@/composables/useT';
import {
    currentSubscription,
    pushSupported,
    subscribe,
    unsubscribe,
} from '@/lib/push';
import { settings } from '@/routes/notifications';
import { test } from '@/routes/push';
import { destroy as destroySubscription } from '@/routes/push-subscriptions';

type Device = {
    id: number;
    endpoint: string;
    name: string;
    since: string;
    lastUsed: string | null;
};

type Vehicle = {
    id: string;
    name: string;
    kinds: string[];
    moment: string;
    // Not chosen: the moment the owner usually uses the app.
    momentIsUsual: boolean;
    quietFrom: string | null;
    quietUntil: string | null;
    editUrl: string;
};

const props = defineProps<{
    publicKey: string | null;
    devices: Device[];
    vehicles: Vehicle[];
}>();

const { t } = useT();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Notification settings',
                href: settings(),
            },
        ],
    },
});

const KINDS: Record<string, string> = {
    critical: 'Critical notifications',
    warning: 'Warnings',
    info: 'Information',
    tips: 'New tips',
    summary: 'Summary of the month',
};

// What this device can do: unknown until it has been looked at.
type State =
    | 'checking'
    | 'unsupported'
    | 'insecure'
    | 'unconfigured'
    | 'blocked'
    | 'off'
    | 'on';

const state = ref<State>('checking');
const endpoint = ref<string | null>(null);
const busy = ref(false);
const failed = ref(false);

async function check() {
    if (!pushSupported()) {
        state.value = 'unsupported';
    } else if (!window.isSecureContext) {
        state.value = 'insecure';
    } else if (!props.publicKey) {
        state.value = 'unconfigured';
    } else if (Notification.permission === 'denied') {
        state.value = 'blocked';
    } else {
        const subscription = await currentSubscription();

        endpoint.value = subscription?.endpoint ?? null;
        state.value =
            subscription &&
            Notification.permission === 'granted' &&
            props.devices.some((device) => device.endpoint === endpoint.value)
                ? 'on'
                : 'off';
    }
}

onMounted(check);

async function turnOn() {
    busy.value = true;
    failed.value = false;

    try {
        await subscribe(props.publicKey!);
        router.reload({ only: ['devices'], onFinish: () => void check() });
    } catch {
        failed.value = true;
    } finally {
        busy.value = false;
    }
}

async function turnOff() {
    busy.value = true;

    try {
        await unsubscribe();
    } finally {
        busy.value = false;
        router.reload({ only: ['devices'], onFinish: () => void check() });
    }
}

function sendTest() {
    router.post(
        test().url,
        {},
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
        },
    );
}

function remove(device: Device) {
    router.delete(destroySubscription().url, {
        data: { endpoint: device.endpoint },
        preserveScroll: true,
        onFinish: () => void check(),
    });
}

const thisDevice = computed(() =>
    props.devices.find((device) => device.endpoint === endpoint.value),
);
</script>

<template>
    <Head :title="t('Notification settings')" />

    <h1 class="sr-only">{{ t('Notification settings') }}</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('Push notifications')"
            :description="
                t(
                    'Get what needs attention on your phone, also when the app is closed.',
                )
            "
        />

        <div class="space-y-4 rounded-lg border p-4">
            <p
                v-if="state === 'checking'"
                class="text-sm text-muted-foreground"
            >
                …
            </p>
            <p v-else-if="state === 'unsupported'" class="text-sm">
                {{
                    t(
                        'This browser cannot receive push notifications. On an iPhone, add the app to the home screen first and open it from there.',
                    )
                }}
            </p>
            <p v-else-if="state === 'insecure'" class="text-sm">
                {{
                    t(
                        'Push notifications only work over a secure (https) connection.',
                    )
                }}
            </p>
            <p v-else-if="state === 'unconfigured'" class="text-sm">
                {{
                    t(
                        'Push notifications are not set up on the server yet (VAPID keys).',
                    )
                }}
            </p>
            <p v-else-if="state === 'blocked'" class="text-sm">
                {{
                    t(
                        'Notifications are blocked for this site. Allow them in the settings of the browser (the icon left of the address), then reload the page.',
                    )
                }}
            </p>
            <template v-else>
                <p class="text-sm">
                    <Badge v-if="state === 'on'" class="mr-2">{{
                        t('On')
                    }}</Badge>
                    <Badge v-else variant="secondary" class="mr-2">{{
                        t('Off')
                    }}</Badge>
                    {{
                        state === 'on'
                            ? t('This device receives push notifications.')
                            : t(
                                  'This device does not receive push notifications.',
                              )
                    }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="state === 'off'"
                        :disabled="busy"
                        @click="turnOn"
                    >
                        {{ t('Turn on for this device') }}
                    </Button>
                    <template v-else>
                        <Button :disabled="busy" @click="sendTest">
                            {{ t('Send a test notification') }}
                        </Button>
                        <Button
                            variant="outline"
                            :disabled="busy"
                            @click="turnOff"
                        >
                            {{ t('Turn off for this device') }}
                        </Button>
                    </template>
                </div>
                <p v-if="failed" class="text-sm text-red-600">
                    {{
                        t(
                            'Push notifications could not be turned on. Try again, or check whether the browser allows notifications.',
                        )
                    }}
                </p>
            </template>
            <p class="text-sm text-muted-foreground">
                {{
                    t(
                        'Tip: install the app on the home screen of your phone (browser menu, Add to home screen). The notifications then carry its name, and open it as an app.',
                    )
                }}
            </p>
        </div>

        <div v-if="devices.length" class="space-y-3">
            <Heading
                variant="small"
                :title="t('Devices')"
                :description="t('The devices that receive push notifications.')"
            />
            <ul class="divide-y rounded-lg border">
                <li
                    v-for="device in devices"
                    :key="device.id"
                    class="flex items-center justify-between gap-3 p-3"
                >
                    <div class="min-w-0 text-sm">
                        <p class="font-medium">
                            {{ device.name }}
                            <span
                                v-if="device.id === thisDevice?.id"
                                class="font-normal text-muted-foreground"
                            >
                                · {{ t('this device') }}
                            </span>
                        </p>
                        <p class="text-muted-foreground">
                            {{ t('Since :date', { date: device.since }) }}
                            <template v-if="device.lastUsed">
                                ·
                                {{
                                    t('Last notification :date', {
                                        date: device.lastUsed,
                                    })
                                }}
                            </template>
                        </p>
                    </div>
                    <Button
                        v-if="device.id !== thisDevice?.id"
                        variant="ghost"
                        size="sm"
                        @click="remove(device)"
                    >
                        {{ t('Remove') }}
                    </Button>
                </li>
            </ul>
        </div>

        <div class="space-y-3">
            <Heading
                variant="small"
                :title="t('Per vehicle')"
                :description="
                    t(
                        'Something critical is sent at once, the rest at the moment of the day, the same day it arises. Never in the quiet hours.',
                    )
                "
            />
            <ul class="divide-y rounded-lg border">
                <li
                    v-for="vehicle in vehicles"
                    :key="vehicle.id"
                    class="space-y-2 p-3 text-sm"
                >
                    <div class="flex items-center justify-between gap-3">
                        <p class="font-medium">{{ vehicle.name }}</p>
                        <Link
                            :href="vehicle.editUrl"
                            class="font-semibold underline"
                        >
                            {{ t('Change') }}
                        </Link>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        <Badge
                            v-for="kind in vehicle.kinds"
                            :key="kind"
                            variant="secondary"
                        >
                            {{ t(KINDS[kind]) }}
                        </Badge>
                        <span
                            v-if="!vehicle.kinds.length"
                            class="text-muted-foreground"
                        >
                            {{ t('No push notifications') }}
                        </span>
                    </div>
                    <p class="text-muted-foreground">
                        {{
                            vehicle.momentIsUsual
                                ? t(
                                      'Moment of the day: :time, when you usually use the app',
                                      { time: vehicle.moment },
                                  )
                                : t('Moment of the day: :time', {
                                      time: vehicle.moment,
                                  })
                        }}
                        <br />
                        {{
                            vehicle.quietFrom && vehicle.quietUntil
                                ? t('Quiet hours: :from to :until', {
                                      from: vehicle.quietFrom,
                                      until: vehicle.quietUntil,
                                  })
                                : t('No quiet hours')
                        }}
                    </p>
                </li>
            </ul>
        </div>
    </div>
</template>
