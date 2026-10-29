/*
 * Turns the icons of resources/icons into the PNGs of the push notifications
 * in public/push-icons: Android shows no SVG in a notification. Per icon one
 * per urgency, white on a circle in its color (see App\Services\Push\PushIcons),
 * and the badge: the favicon in the status bar. Runs before `npm run build`;
 * an image that is newer than its icon is left as it is.
 */
import {
    existsSync,
    mkdirSync,
    readdirSync,
    readFileSync,
    statSync,
    writeFileSync,
} from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Resvg } from '@resvg/resvg-js';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const source = join(root, 'resources/icons');
const target = join(root, 'public/push-icons');

// As under "Needs attention", dark enough for white on top.
const URGENCIES = { critical: '#dc2626', warning: '#ea580c', info: '#2563eb' };

// Logos and flags have colors of their own: never a subject of a notification.
const SKIP = new Set(['simple-icons', 'thesvg-color', 'circle-flags']);

const SIZE = 192;
const ICON = 112;

function render(svg, size, path) {
    const png = new Resvg(svg, {
        fitTo: { mode: 'width', value: size },
        font: { loadSystemFonts: false },
    })
        .render()
        .asPng();

    mkdirSync(dirname(path), { recursive: true });
    writeFileSync(path, png);
}

// The icon in white, scaled into the middle of the circle.
function onCircle(icon, color) {
    const offset = (SIZE - ICON) / 2;
    const inner = icon
        .replace(/<\?xml[^>]*>/, '')
        .replace(/<svg\b([^>]*)>/, (match, attributes) => {
            const kept = attributes.replace(
                /\s(width|height|x|y|color)="[^"]*"/g,
                '',
            );

            return `<svg${kept} x="${offset}" y="${offset}" width="${ICON}" height="${ICON}" color="#ffffff">`;
        });

    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${SIZE} ${SIZE}"><circle cx="${SIZE / 2}" cy="${SIZE / 2}" r="${SIZE / 2}" fill="${color}"/>${inner}</svg>`;
}

let made = 0;

for (const set of readdirSync(source)) {
    if (SKIP.has(set) || !statSync(join(source, set)).isDirectory()) {
        continue;
    }

    for (const file of readdirSync(join(source, set))) {
        if (!file.endsWith('.svg')) {
            continue;
        }

        const path = join(source, set, file);
        const changed = statSync(path).mtimeMs;
        const icon = readFileSync(path, 'utf8');

        for (const [urgency, color] of Object.entries(URGENCIES)) {
            const png = join(
                target,
                set,
                file.replace(/\.svg$/, `-${urgency}.png`),
            );

            if (existsSync(png) && statSync(png).mtimeMs >= changed) {
                continue;
            }

            render(onCircle(icon, color), SIZE, png);
            made++;
        }
    }
}

// The badge: the favicon. Android only uses its shape, so the square in white
// with the car cut out of it.
const favicon = readFileSync(join(root, 'public/favicon.svg'), 'utf8');
const [, viewBox] = favicon.match(/viewBox="([^"]*)"/);
const shape = favicon
    .replace(/<\?xml[^>]*>/, '')
    .replace(/<svg\b[^>]*>|<\/svg>/g, '');
const badge = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${viewBox}"><mask id="cut">${shape
    .replace(/<rect\b([^>]*?)fill="[^"]*"/, '<rect$1fill="#ffffff"')
    .replace(
        /<path\b([^>]*?)fill="[^"]*"/,
        '<path$1fill="#000000"',
    )}</mask><rect width="100%" height="100%" fill="#ffffff" mask="url(#cut)"/></svg>`;
render(badge, 96, join(target, 'badge.png'));

console.log(`Push icons: ${made} made, in public/push-icons`);
