import { iconMarkup } from '@/lib/icons';

// The color of a gas station on the station map by its price of the primary
// fuel, compared with the other gas stations in view: cheap, in between or
// expensive; grey without a price.
export const PRICE_TIERS = {
    cheap: { color: '#16a34a', label: 'Cheap' },
    mid: { color: '#d97706', label: 'In between' },
    expensive: { color: '#dc2626', label: 'Expensive' },
    none: { color: '#94a3b8', label: 'No price' },
} as const;

export type PriceTier = keyof typeof PRICE_TIERS;

/**
 * An image of a URL, or null when there is none (such as a logo that is not
 * known). Only of the app itself: one of elsewhere would taint the canvas.
 */
export function loadImage(url: string): Promise<HTMLImageElement | null> {
    return new Promise((resolve) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () => resolve(null);
        image.src = url;
    });
}

/**
 * A badge of a gas station on the map, as one image: a rounded shape in the
 * color of its price with a white edge, a white icon, the logo of its brand
 * where that is known, and the price. One image rather than an icon with a
 * text over it: the map draws all icons before all texts, so the text of one
 * badge would come over another that lies on top of it.
 */
export async function badgeImage(
    color: string,
    icon: string,
    logo: HTMLImageElement | null,
    text: string,
    ratio = 2,
): Promise<{ data: ImageData; pixelRatio: number }> {
    const height = 26;
    const iconSize = 15;
    const logoSize = 20;
    const font = '700 13px ui-sans-serif, system-ui, sans-serif';
    const iconAt = 6;
    const logoAt = iconAt + iconSize + 3;
    const textAt = (logo ? logoAt + logoSize : iconAt + iconSize) + 5;

    const measure = document.createElement('canvas').getContext('2d')!;
    measure.font = font;
    const width = Math.ceil(textAt + measure.measureText(text).width + 9);

    const canvas = document.createElement('canvas');
    canvas.width = width * ratio;
    canvas.height = height * ratio;
    const context = canvas.getContext('2d')!;

    context.scale(ratio, ratio);
    context.beginPath();
    context.roundRect(1, 1, width - 2, height - 2, height / 2 - 1);
    context.fillStyle = color;
    context.fill();
    context.lineWidth = 1.5;
    context.strokeStyle = '#ffffff';
    context.stroke();

    const symbol = await loadImage(
        `data:image/svg+xml;charset=utf-8,${encodeURIComponent(iconMarkup(icon, '#ffffff', iconSize * ratio))}`,
    );

    if (symbol) {
        context.drawImage(
            symbol,
            iconAt,
            (height - iconSize) / 2,
            iconSize,
            iconSize,
        );
    }

    // The logo on a white tile, so that a dark one shows on any color.
    if (logo) {
        const y = (height - logoSize) / 2;
        const inner = logoSize - 2;
        // In proportion, as large as fits; an SVG without a size of its own fills the tile.
        const sized = logo.width > 0 && logo.height > 0;
        const scale = sized
            ? Math.min(inner / logo.width, inner / logo.height)
            : 1;
        const logoWidth = sized ? logo.width * scale : inner;
        const logoHeight = sized ? logo.height * scale : inner;

        context.beginPath();
        context.roundRect(logoAt, y, logoSize, logoSize, 4);
        context.fillStyle = '#ffffff';
        context.fill();
        context.drawImage(
            logo,
            logoAt + (logoSize - logoWidth) / 2,
            y + (logoSize - logoHeight) / 2,
            logoWidth,
            logoHeight,
        );
    }

    context.font = font;
    context.fillStyle = '#ffffff';
    context.textBaseline = 'middle';
    context.fillText(text, textAt, height / 2 + 0.5);

    return {
        data: context.getImageData(0, 0, width * ratio, height * ratio),
        pixelRatio: ratio,
    };
}
