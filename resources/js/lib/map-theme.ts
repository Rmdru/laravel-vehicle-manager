import type { MultiPolygon } from 'geojson';
import type {
    ExpressionSpecification,
    LayerSpecification,
    StyleSpecification,
} from 'maplibre-gl';
import shapes from '@/lib/country-shapes.json';

// The look of the trip map, in the light and the dark appearance: applied to
// the base style (OpenFreeMap "liberty") when it loads. A road gets the color
// of its class, with a darker casing; the main roads are drawn wider, so that
// the network stands out already when zoomed out.
type RoadColors = { line: string; casing: string };

export type MapTheme = {
    background: string;
    water: string;
    residential: string;
    building: string;
    boundary: string;
    label: string;
    labelHalo: string;
    roads: Record<
        | 'motorway'
        | 'trunk'
        | 'primary'
        | 'secondary'
        | 'tertiary'
        | 'minor'
        | 'service',
        RoadColors
    >;
};

export const MAP_THEMES: Record<'light' | 'dark', MapTheme> = {
    light: {
        background: '#f5f5f4',
        water: '#a5c8f0',
        residential: '#ebe9e6',
        building: '#e2ddd8',
        boundary: '#9b8fb5',
        label: '#374151',
        labelHalo: '#ffffff',
        roads: {
            motorway: { line: '#e8473e', casing: '#a8261f' },
            trunk: { line: '#f2852c', casing: '#b85a12' },
            primary: { line: '#f7c948', casing: '#c49518' },
            secondary: { line: '#fbe29a', casing: '#c9ad5c' },
            tertiary: { line: '#ffffff', casing: '#c4bfb8' },
            minor: { line: '#ffffff', casing: '#d6d3d1' },
            service: { line: '#ffffff', casing: '#dedbd8' },
        },
    },
    dark: {
        background: '#151a23',
        water: '#1d3352',
        residential: '#1c222c',
        building: '#252c38',
        boundary: '#6b6290',
        label: '#cbd5e1',
        labelHalo: '#0f141c',
        roads: {
            motorway: { line: '#d9443b', casing: '#7a1d18' },
            trunk: { line: '#d9792c', casing: '#7a4113' },
            primary: { line: '#c9a43a', casing: '#6e5715' },
            secondary: { line: '#8a7a4a', casing: '#4a4126' },
            tertiary: { line: '#4b5563', casing: '#2b313b' },
            minor: { line: '#3a414d', casing: '#262b33' },
            service: { line: '#323843', casing: '#232830' },
        },
    },
};

// The width of a road of a class at zoom 5, 8, 11, 14 and 18, in pixels: the
// main roads are drawn wider than in the base style, but thin when zoomed
// out, so that the map does not fill up with roads.
const ZOOMS = [5, 8, 11, 14, 18];
const WIDTHS: Record<string, number[]> = {
    motorway: [0.7, 1.8, 3.4, 7, 24],
    trunk: [0.5, 1.4, 2.8, 6, 20],
    primary: [0.3, 1, 2.2, 5, 18],
    secondary: [0, 0.6, 1.6, 4.5, 16],
    tertiary: [0, 0.3, 1.2, 3.5, 14],
};
// What the casing adds to the width, at the same zooms.
const CASING = [0, 0.8, 1.6, 2, 3];

const ROAD_CLASSES = Object.keys(MAP_THEMES.light.roads);

export type ShieldStyles = {
    // The road numbers by country (ISO 2 code), the longest prefixes first.
    countries: Record<
        string,
        { prefix: string; image: string; text: string }[]
    >;
    european: { image: string; text: string };
    plain: { image: string; text: string };
    // The URL of the sprite with the shields, without extension.
    sprite: string;
};

// A color by the class of a road.
function byClass(
    roads: MapTheme['roads'],
    colors: (road: RoadColors) => string,
    fallback: string,
): ExpressionSpecification {
    return [
        'match',
        ['get', 'class'],
        ...Object.entries(roads).flatMap(([kind, road]) =>
            kind === 'minor'
                ? [['minor', 'street', 'street_limited'], colors(road)]
                : kind === 'service'
                  ? [['service', 'track'], colors(road)]
                  : [kind, colors(road)],
        ),
        fallback,
    ] as unknown as ExpressionSpecification;
}

function width(casing: boolean): ExpressionSpecification {
    return [
        'interpolate',
        ['exponential', 1.4],
        ['zoom'],
        ...ZOOMS.flatMap((zoom, index) => [
            zoom,
            [
                'match',
                ['get', 'class'],
                ...Object.entries(WIDTHS).flatMap(([kind, widths]) => [
                    kind,
                    widths[index] > 0
                        ? widths[index] + (casing ? CASING[index] : 0)
                        : 0,
                ]),
                0,
            ],
        ]),
    ] as unknown as ExpressionSpecification;
}

// The classes of roads a layer of the base style draws, by its filter.
function classesOf(layer: LayerSpecification): string[] {
    const filter = JSON.stringify('filter' in layer ? layer.filter : []);

    return ROAD_CLASSES.concat(['street', 'street_limited', 'track']).filter(
        (kind) => filter.includes(`"${kind}"`),
    );
}

function isRoad(layer: LayerSpecification): boolean {
    return (
        layer.type === 'line' &&
        'source-layer' in layer &&
        layer['source-layer'] === 'transportation' &&
        !/rail|path|pedestrian|pier|ferry|aeroway/.test(layer.id) &&
        classesOf(layer).length > 0
    );
}

function paint(layer: LayerSpecification, properties: Record<string, unknown>) {
    (layer as { paint?: Record<string, unknown> }).paint = {
        ...(layer as { paint?: Record<string, unknown> }).paint,
        ...properties,
    };
}

// The road numbers as the road signs of their country show them: one layer
// per country, limited to its borders, above one for all other roads.
function shieldLayers(shields: ShieldStyles): LayerSpecification[] {
    const hasRef: ExpressionSpecification = [
        'all',
        ['has', 'ref'],
        ['<=', ['get', 'ref_length'], 6],
        [
            'match',
            ['geometry-type'],
            ['LineString', 'MultiLineString'],
            true,
            false,
        ],
    ];
    const startsWith = (prefix: string): ExpressionSpecification => [
        '==',
        ['slice', ['upcase', ['to-string', ['get', 'ref']]], 0, prefix.length],
        prefix,
    ];
    const layer = (
        id: string,
        filter: ExpressionSpecification,
        image: ExpressionSpecification | string,
        text: ExpressionSpecification | string,
    ): LayerSpecification => ({
        id,
        type: 'symbol',
        source: 'openmaptiles',
        'source-layer': 'transportation_name',
        filter,
        minzoom: 6,
        layout: {
            'icon-image': image,
            'icon-text-fit': 'both',
            'icon-text-fit-padding': [1, 2, 1, 2],
            'icon-rotation-alignment': 'viewport',
            'symbol-placement': ['step', ['zoom'], 'point', 10, 'line'],
            'symbol-spacing': 250,
            'text-field': ['to-string', ['get', 'ref']],
            'text-font': ['Noto Sans Bold'],
            'text-size': 11,
            'text-rotation-alignment': 'viewport',
        },
        paint: { 'text-color': text },
    });
    const sprite = (image: string) => `shields:${image}`;

    // A European road first, then the prefixes of the country.
    const choose = (
        rules: { prefix: string; image: string; text: string }[],
        pick: (rule: { image: string; text: string }) => string,
    ): ExpressionSpecification =>
        [
            'case',
            startsWith('E'),
            pick({
                ...shields.european,
                image: sprite(shields.european.image),
            }),
            ...rules.flatMap((rule) => [
                startsWith(rule.prefix),
                pick({ ...rule, image: sprite(rule.image) }),
            ]),
            pick({ ...shields.plain, image: sprite(shields.plain.image) }),
        ] as unknown as ExpressionSpecification;

    // Placed first, so that the shield of a country takes its place.
    const layers = [
        layer(
            'road-shields',
            hasRef,
            choose([], (rule) => rule.image),
            choose([], (rule) => rule.text),
        ),
    ];

    for (const [country, rules] of Object.entries(shields.countries)) {
        const shape = (shapes as Record<string, MultiPolygon>)[country];

        if (shape) {
            layers.push(
                layer(
                    `road-shields-${country}`,
                    [
                        'all',
                        hasRef,
                        [
                            'within',
                            {
                                type: 'Feature',
                                properties: {},
                                geometry: shape,
                            },
                        ],
                    ] as unknown as ExpressionSpecification,
                    choose(rules, (rule) => rule.image),
                    choose(rules, (rule) => rule.text),
                ),
            );
        }
    }

    return layers;
}

/**
 * The base style in a theme, with the road number shields: for
 * transformStyle, when a style is set.
 */
export function themedStyle(
    style: StyleSpecification,
    theme: MapTheme,
    shields: ShieldStyles,
): StyleSpecification {
    const layers = style.layers.flatMap((layer): LayerSpecification[] => {
        const sourceLayer =
            'source-layer' in layer ? layer['source-layer'] : null;
        const id = layer.id;

        // The shields of the base style make way for ours; nature, the relief
        // and the land cover are left out: the roads are what matters.
        if (
            id.includes('shield') ||
            layer.type === 'raster' ||
            sourceLayer === 'park' ||
            sourceLayer === 'landcover'
        ) {
            return [];
        }

        if (layer.type === 'background') {
            paint(layer, { 'background-color': theme.background });
        } else if (layer.type === 'fill' && sourceLayer === 'water') {
            paint(layer, { 'fill-color': theme.water });
        } else if (layer.type === 'line' && sourceLayer === 'waterway') {
            paint(layer, { 'line-color': theme.water });
        } else if (layer.type === 'fill' && sourceLayer === 'landuse') {
            paint(layer, { 'fill-color': theme.residential });
        } else if (
            layer.type.startsWith('fill') &&
            sourceLayer === 'building'
        ) {
            paint(
                layer,
                layer.type === 'fill'
                    ? {
                          'fill-color': theme.building,
                          'fill-outline-color': theme.building,
                      }
                    : { 'fill-extrusion-color': theme.building },
            );
        } else if (layer.type === 'line' && sourceLayer === 'boundary') {
            paint(layer, { 'line-color': theme.boundary });
        } else if (
            layer.type === 'symbol' &&
            layer.layout?.['text-field'] &&
            sourceLayer !== 'water_name'
        ) {
            paint(layer, {
                'text-color': theme.label,
                'text-halo-color': theme.labelHalo,
            });
        } else if (isRoad(layer)) {
            const casing = id.includes('casing');
            const major = classesOf(layer).every((kind) => kind in WIDTHS);

            paint(layer, {
                'line-color': byClass(
                    theme.roads,
                    (road) => (casing ? road.casing : road.line),
                    theme.roads.minor.line,
                ),
                ...(major ? { 'line-width': width(casing) } : {}),
            });
        }

        return [layer];
    });

    return {
        ...style,
        sprite: [
            ...(Array.isArray(style.sprite)
                ? style.sprite
                : style.sprite
                  ? [{ id: 'default', url: style.sprite }]
                  : []),
            { id: 'shields', url: shields.sprite },
        ],
        layers: [...layers, ...shieldLayers(shields)],
    };
}
