// One tint per navigation group (maintenance, road or fixed), so the kind of
// event reads at a glance: on the timeline and in the latest costs alike.
export const timelineTints: Record<string, string> = {
    maintenance: 'bg-warning-muted text-warning',
    road: 'bg-info-muted text-info',
    fixed: 'bg-success-muted text-success',
};

export const timelineTint = (group: string | null | undefined): string =>
    timelineTints[group ?? 'road'] ?? timelineTints.road;
