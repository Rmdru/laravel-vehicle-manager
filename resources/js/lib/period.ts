export type DateRange = { start: string; end: string };

export type PeriodPreset = 'month' | 'month_ago' | 'year' | 'year_ago';

// The labels are translation keys.
export const periodPresets: { name: PeriodPreset; label: string }[] = [
    { name: 'month', label: 'Month' },
    { name: 'month_ago', label: 'Month ago' },
    { name: 'year', label: 'Year' },
    { name: 'year_ago', label: 'Year ago' },
];

const iso = (date: Date) =>
    `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

export function presetRange(name: PeriodPreset): DateRange {
    const now = new Date();
    const from = new Date(now);
    let until = now;

    if (name === 'month') {
        from.setDate(1);
        until = new Date(now.getFullYear(), now.getMonth() + 1, 0);
    } else if (name === 'month_ago') {
        from.setMonth(from.getMonth() - 1);
    } else if (name === 'year') {
        from.setMonth(0, 1);
        until = new Date(now.getFullYear(), 11, 31);
    } else {
        from.setFullYear(from.getFullYear() - 1);
    }

    return { start: iso(from), end: iso(until) };
}

export function activePreset(
    start?: string | null,
    end?: string | null,
): PeriodPreset | undefined {
    return periodPresets.find(({ name }) => {
        const range = presetRange(name);

        return range.start === start && range.end === end;
    })?.name;
}

/**
 * A date range as text, or nothing when neither end is set.
 */
export function periodLabel(
    locale: string,
    start?: string | null,
    end?: string | null,
): string | undefined {
    if (!start && !end) {
        return undefined;
    }

    const format = (date: string) =>
        new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(
            new Date(`${date}T00:00:00`),
        );

    return `${start ? format(start) : '…'} – ${end ? format(end) : '…'}`;
}
