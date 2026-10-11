export type LicensePlate = {
    label: string;
    prefix: string | null;
    plateClasses: string;
    euBar: boolean;
};

export type VehicleSummary = {
    id: string;
    name: string;
    brand: string;
    // Iconify name of the brand's logo, when the brand has one.
    brandLogo: string | null;
    licensePlate: LicensePlate;
    mileage: number | null;
    powertrain: string;
    imageUrl: string | null;
    // The image in the widths it is stored at, for the browser to pick from.
    imageSrcset: string | null;
    age: number | null;
    constructionDate: string | null;
    status: string;
    statusIcon: string | null;
    statusColor: string;
};

export type VehicleOption = {
    id: string;
    name: string;
    licensePlate: string;
    selectUrl: string;
};

export type DetailItem = {
    label: string;
    value?: string | number | null;
    // Trusted markup rendered by the server (badges, flags).
    html?: string | null;
    // Name of an icon in resources/icons, shown in front of the value.
    icon?: string | null;
    // Name of a brand logo (null: generic car), shown in front of the value.
    logo?: string | null;
};

export type DetailSection = {
    title: string;
    icon?: string | null;
    items: DetailItem[];
};
