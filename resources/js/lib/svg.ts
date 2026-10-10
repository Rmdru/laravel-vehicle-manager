export type SvgIcon = { attributes: Record<string, string>; content: string };

// Sizing and styling are left to whoever renders the icon.
const ignored = ['width', 'height', 'class', 'id', 'xmlns', 'version', 'role'];

/**
 * Split an SVG file into the attributes of its root element and its content,
 * so it can be rendered as a real <svg> element that takes classes.
 */
export function parseSvg(svg: string): SvgIcon | null {
    const match = svg.match(/<svg([^>]*)>([\s\S]*)<\/svg>/);

    if (!match) {
        return null;
    }

    const attributes: Record<string, string> = {};

    for (const [, key, value] of match[1].matchAll(/([\w:-]+)="([^"]*)"/g)) {
        if (!ignored.includes(key)) {
            attributes[key] = value;
        }
    }

    return {
        attributes,
        // A <title> would show up as a tooltip; the icons are decorative.
        content: match[2].replace(/<title>[\s\S]*?<\/title>/, ''),
    };
}

export function iconName(path: string): string {
    return path.split('/').pop()!.replace('.svg', '');
}
