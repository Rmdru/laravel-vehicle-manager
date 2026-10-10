import { usePage } from '@inertiajs/vue3';

/**
 * Translate an interface string with the translations shared by the server.
 * Falls back to the (English) key itself.
 */
export function useT() {
    const page = usePage();

    function t(key: string, replace: Record<string, string | number> = {}): string {
        const translations = (page.props.t ?? {}) as Record<string, string>;

        return Object.entries(replace).reduce(
            (text, [name, value]) => text.replace(`:${name}`, String(value)),
            translations[key] ?? key,
        );
    }

    return { t };
}