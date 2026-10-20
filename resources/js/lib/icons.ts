import { parseSvg } from '@/lib/svg';
import type { SvgIcon } from '@/lib/svg';

// The icons in resources/icons as markup, for places Vue does not render, such
// as the markers and images of a map. Only the sets the map uses.
const files = import.meta.glob<string>(
    ['../../icons/mdi/*.svg', '../../icons/ic/*.svg', '../../icons/maki/*.svg'],
    { query: '?raw', import: 'default', eager: true },
);

const icons: Record<string, SvgIcon> = {};

for (const [path, svg] of Object.entries(files)) {
    const icon = parseSvg(svg);

    if (icon) {
        icons[path.split('/').slice(-2).join(':').replace('.svg', '')] = icon;
    }
}

/**
 * An icon as an <svg> element in a string, in a color and a size.
 */
export function iconMarkup(name: string, color: string, size: number): string {
    const icon = icons[name];

    if (!icon) {
        return '';
    }

    const attributes = Object.entries(icon.attributes)
        .map(([key, value]) => `${key}="${value}"`)
        .join(' ');

    return `<svg xmlns="http://www.w3.org/2000/svg" ${attributes} width="${size}" height="${size}" style="color:${color}">${icon.content.replaceAll('currentColor', color)}</svg>`;
}

/**
 * A round badge with a white icon on a color, as an image a map can show.
 */
export function iconImage(
    name: string,
    color: string,
    size = 26,
    ratio = 2,
): Promise<ImageData> {
    const pixels = size * ratio;
    const canvas = document.createElement('canvas');
    canvas.width = pixels;
    canvas.height = pixels;
    const context = canvas.getContext('2d')!;

    context.beginPath();
    context.arc(pixels / 2, pixels / 2, pixels / 2 - ratio, 0, Math.PI * 2);
    context.fillStyle = color;
    context.fill();
    context.lineWidth = 2 * ratio;
    context.strokeStyle = '#ffffff';
    context.stroke();

    const image = new Image();
    const icon = Math.round(pixels * 0.58);

    return new Promise((resolve) => {
        image.onload = () => {
            context.drawImage(
                image,
                (pixels - icon) / 2,
                (pixels - icon) / 2,
                icon,
                icon,
            );
            resolve(context.getImageData(0, 0, pixels, pixels));
        };
        image.onerror = () =>
            resolve(context.getImageData(0, 0, pixels, pixels));
        image.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(iconMarkup(name, '#ffffff', icon))}`;
    });
}
