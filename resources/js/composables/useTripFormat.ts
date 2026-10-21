import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useT } from '@/composables/useT';

/**
 * The times, distances and amounts of the trip planner, in the language of
 * the user and in the time zone the times are entered in.
 */
export function useTripFormat(timezone: () => string) {
    const { t } = useT();
    const page = usePage();
    const locale = computed(() => String(page.props.locale ?? 'en'));

    function clock(iso: string): string {
        return new Intl.DateTimeFormat(locale.value, {
            timeZone: timezone(),
            hour: '2-digit',
            minute: '2-digit',
        }).format(new Date(iso));
    }

    function day(iso: string): string {
        return new Intl.DateTimeFormat(locale.value, {
            timeZone: timezone(),
            weekday: 'short',
            day: 'numeric',
            month: 'short',
        }).format(new Date(iso));
    }

    // A time, with the day when it is not the day of departure.
    function moment(iso: string, departure: string | undefined): string {
        return departure && day(iso) !== day(departure)
            ? `${day(iso)} ${clock(iso)}`
            : clock(iso);
    }

    function duration(seconds: number): string {
        const minutes = Math.round(seconds / 60);

        return t(':hours h :minutes min', {
            hours: Math.floor(minutes / 60),
            minutes: String(minutes % 60).padStart(2, '0'),
        });
    }

    function kilometers(meters: number): string {
        return `${new Intl.NumberFormat(locale.value).format(Math.round(meters / 1000))} km`;
    }

    // A price per liter, to the tenth of a cent.
    function perLiter(value: number): string {
        return new Intl.NumberFormat(locale.value, {
            style: 'currency',
            currency: 'EUR',
            minimumFractionDigits: 3,
        }).format(value);
    }

    // An amount, in whole euros when it is large.
    function money(value: number): string {
        return new Intl.NumberFormat(locale.value, {
            style: 'currency',
            currency: 'EUR',
            maximumFractionDigits: Math.abs(value) >= 100 ? 0 : 2,
        }).format(value);
    }

    // A country by its name in the language of the user, from its ISO 2 code.
    function countryName(code: string): string {
        try {
            return (
                new Intl.DisplayNames([locale.value], { type: 'region' }).of(
                    code,
                ) ?? code
            );
        } catch {
            return code;
        }
    }

    return {
        locale,
        clock,
        day,
        moment,
        duration,
        kilometers,
        perLiter,
        money,
        countryName,
    };
}
