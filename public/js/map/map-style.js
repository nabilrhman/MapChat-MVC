// MapChat's map look, shared by the chat's map (map.js) and the one behind
// the login pages (login-map.js): OpenFreeMap's positron style recoloured
// lavender, with a game map's tinted coasts and bold region names, and a
// purple shade wherever the sun has set

const STYLE_URL = 'https://tiles.openfreemap.org/styles/positron';
const MAPLIBRE_URL = 'https://unpkg.com/maplibre-gl@4.7.1/dist/';
// The tile map's, for browsers without WebGL (map.js)
const LEAFLET_URL = 'https://unpkg.com/leaflet@1.9.4/dist/';
// Each CDN file's SHA-384, so the browser refuses a copy that's been changed.
// The preloads in base_login.html and base_chat.html carry the same, or the
// preloaded copy goes unused
const CDN_INTEGRITY = {
    [`${MAPLIBRE_URL}maplibre-gl.js`]: 'sha384-SYKAG6cglRMN0RVvhNeBY0r3FYKNOJtznwA0v7B5Vp9tr31xAHsZC0DqkQ/pZDmj',
    [`${MAPLIBRE_URL}maplibre-gl.css`]: 'sha384-MinO0mNliZ3vwppuPOUnGa+iq619pfMhLVUXfC4LHwSCvF9H+6P/KO4Q7qBOYV5V',
    [`${LEAFLET_URL}leaflet.js`]: 'sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH',
    [`${LEAFLET_URL}leaflet.css`]: 'sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H',
};
// Without the style by then, the tile map takes over
const STYLE_TIMEOUT = 10000;
// The map's credit, in place of the tiles' longer "OpenFreeMap © OpenMapTiles
// Data from OpenStreetMap": the two the licences ask for, under one ©, each
// still linked. OpenFreeMap's own mention is a courtesy, not a term
const MAP_CREDIT = '&copy; <a href="https://www.openmaptiles.org/" target="_blank">OpenMapTiles</a>'
    + ', <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a>';

// MapChat's purple map, by map layer: near-white land, lavender water (the
// open sea a shade deeper than lakes), pink-lilac coasts, and text in purples
// from deep (countries) to light (small places)
const THEME = {
    land: '#fcfaff',
    residential: '#f8f4ff',
    park: '#ece3fe',
    building: '#f1eafd',
    buildingEdge: '#e0d4f9',
    deepSea: '#c3b2fb',
    sea: '#d2c5fd',
    shallowSea: '#e4dcfe',
    lake: '#ddd3fe',
    shore: '#e8c8f0',
    road: '#ffffff',
    roadCasing: '#d9cbf7',
    path: '#e8dffb',
    highway: '#e2d8fb',
    highwayCasing: '#c8b8ef',
    rail: '#d9cbf7',
    boundary: '#9d84e6',
    stateBoundary: '#c9b8f2',
    regionText: '#ffffff',
    regionTextHalo: 'rgba(58, 37, 128, 0.8)',
    cityText: '#3d2f6b',
    townText: '#5c4b8f',
    otherText: '#7a68b0',
    roadText: '#6a58a3',
    waterText: '#6a4fd6',
    textHalo: '#fcfaff',
    waterTextHalo: 'rgba(252, 250, 255, 0.6)',
    pin: '#ffc107',
    night: '#4c28be',
};

/* The vector map's colours */

// The open sea a shade deeper than lakes and rivers, as on a game's map
const waterColourOf = (seaColour) => ['match', ['get', 'class'], 'ocean', seaColour, THEME.lake];
// And deeper still zoomed out, easing to the usual sea by zoom 10, still
// deeper than lakes and the shallow water's glow. MapLibre only takes zoom
// at the top of an expression, so the match sits inside
const WATER_COLOUR = ['interpolate', ['linear'], ['zoom'],
    2, waterColourOf(THEME.deepSea),
    10, waterColourOf(THEME.sea),
];

// The positron style's layers that change colour, by layer id; the first
// match wins
const LAYER_COLOURS = [
    {isLayer: (id) => id === 'background', paint: {'background-color': THEME.land}},
    {isLayer: (id) => id === 'landuse_residential', paint: {'fill-color': THEME.residential}},
    {isLayer: (id) => id === 'park' || id === 'landcover_wood', paint: {'fill-color': THEME.park}},
    {isLayer: (id) => id === 'water', paint: {'fill-color': WATER_COLOUR}},
    {isLayer: (id) => id === 'waterway', paint: {'line-color': THEME.lake}},
    {isLayer: (id) => id === 'building', paint: {'fill-color': THEME.building, 'fill-outline-color': THEME.buildingEdge}},
    {isLayer: (id) => id === 'road_area_pier', paint: {'fill-color': THEME.land}},
    {isLayer: (id) => id === 'road_pier', paint: {'line-color': THEME.land}},
    {
        isLayer: (id) => /^(tunnel_|highway_)?motorway.*casing$|^highway_motorway_bridge_casing$/.test(id),
        paint: {'line-color': THEME.highwayCasing},
    },
    {isLayer: (id) => /motorway_(inner|subtle)$/.test(id), paint: {'line-color': THEME.highway}},
    {isLayer: (id) => /^highway_major_(casing|subtle)$/.test(id), paint: {'line-color': THEME.roadCasing}},
    {isLayer: (id) => /^highway_(major_inner|minor)$/.test(id), paint: {'line-color': THEME.road}},
    {isLayer: (id) => id === 'highway_path', paint: {'line-color': THEME.path}},
    {isLayer: (id) => /^railway/.test(id) && !/dashline$/.test(id), paint: {'line-color': THEME.rail}},
    {isLayer: (id) => id === 'boundary_3', paint: {'line-color': THEME.stateBoundary}},
    {isLayer: (id) => id === 'boundary_2', paint: {'line-color': THEME.boundary, 'line-dasharray': [3, 2]}},
    {isLayer: (id) => /^boundary/.test(id), paint: {'line-color': THEME.boundary}},
];

/* The coast's bands: a shore on land and shallow water out at sea */

// The sea's shapes are cut at the date line, and a line drawn along a coast
// traces the cut too, from pole to pole. So the coast's bands only fade in
// from COAST_BANDS_FROM_ZOOM, where the date line is rarely on screen. Over
// two zoom levels, so one scroll doesn't pop them in
const COAST_BANDS_FROM_ZOOM = 4;
const COAST_BANDS_OPACITY = ['interpolate', ['linear'], ['zoom'], COAST_BANDS_FROM_ZOOM, 0, COAST_BANDS_FROM_ZOOM + 2, 1];

// A pink-lilac band along the coast, as a game's map draws its beaches: under
// the sea, so only its landward half shows
const COAST_LAYER = {
    id: 'coast',
    minzoom: COAST_BANDS_FROM_ZOOM,
    type: 'line',
    source: 'openmaptiles',
    'source-layer': 'water',
    filter: ['==', ['get', 'class'], 'ocean'],
    layout: {'line-join': 'round'},
    paint: {
        'line-color': THEME.shore,
        'line-width': ['interpolate', ['linear'], ['zoom'], 4, 1, 8, 3, 12, 6, 16, 12],
        'line-opacity': COAST_BANDS_OPACITY,
    },
};

// Shallow water: a wide blurred band of pale lavender just off the coast, so
// the sea fades from light at the shore to its full colour further out. On
// the sea's own edge, pushed out to sea by half its width
const SHALLOW_SEA_WIDTH = ['interpolate', ['exponential', 1.5], ['zoom'], 4, 6, 8, 16, 12, 40, 16, 90];
const SHALLOW_SEA_LAYER = {
    id: 'shallow_sea',
    minzoom: COAST_BANDS_FROM_ZOOM,
    type: 'line',
    source: 'openmaptiles',
    'source-layer': 'water',
    filter: ['==', ['get', 'class'], 'ocean'],
    layout: {'line-join': 'round'},
    paint: {
        'line-color': THEME.shallowSea,
        'line-width': SHALLOW_SEA_WIDTH,
        // Half SHALLOW_SEA_WIDTH's stops: MapLibre can't halve a zoom expression
        'line-offset': ['interpolate', ['exponential', 1.5], ['zoom'], 4, 3, 8, 8, 12, 20, 16, 45],
        'line-blur': SHALLOW_SEA_WIDTH,
        'line-opacity': COAST_BANDS_OPACITY,
    },
};

/* Place names */

// Countries and states, named in bold white capitals with a soft purple halo
const isRegionLabel = (id) => /^label_(country|state)/.test(id);

// The colour of each kind of place name, by layer id; the first match wins
const LABEL_COLOURS = [
    {isLayer: isRegionLabel, colour: THEME.regionText},
    {isLayer: (id) => /^label_city/.test(id), colour: THEME.cityText},
    {isLayer: (id) => /^label_(town|village)$/.test(id), colour: THEME.townText},
    {isLayer: (id) => /^water/.test(id), colour: THEME.waterText},
    {isLayer: (id) => /^highway-name/.test(id), colour: THEME.roadText},
];

const hasText = (layer) => layer.type === 'symbol' && Boolean(layer.layout?.['text-field']);

function labelColourOf(layerId) {
    return LABEL_COLOURS.find(({isLayer}) => isLayer(layerId))?.colour ?? THEME.otherText;
}

// The style names a place in Latin, then again in its own script. MapLibre
// can't shape Bengali, Hindi, Myanmar and the like, so their letters come out
// broken: those places keep only the Latin name
const HAS_LOCAL_NAME = ['has', 'name:nonlatin'];
const CAN_DRAW_LOCAL_NAME = ['all', HAS_LOCAL_NAME, ['is-supported-script', ['get', 'name:nonlatin']]];
// The same Latin name the two-line label shows
const LATIN_NAME = ['coalesce', ['get', 'name:latin'], ['get', 'name_en']];

// Compared as JSON, as the style's condition is its own array, not ours
const isTwoScriptName = (textField) => Array.isArray(textField)
    && textField[0] === 'case'
    && JSON.stringify(textField[1]) === JSON.stringify(HAS_LOCAL_NAME);

function dropUndrawableLocalName(layer) {
    const textField = layer.layout['text-field'];
    if (!isTwoScriptName(textField)) return;

    const bothNames = textField[2];
    const nameWithoutLocalScript = textField[3];
    layer.layout['text-field'] = [
        'case',
        CAN_DRAW_LOCAL_NAME, bothNames,
        HAS_LOCAL_NAME, LATIN_NAME,
        nameWithoutLocalScript,
    ];
}

function themeLabel(layer) {
    dropUndrawableLocalName(layer);
    layer.paint['text-color'] = labelColourOf(layer.id);
    layer.paint['text-halo-color'] = /^water/.test(layer.id) ? THEME.waterTextHalo : THEME.textHalo;
    layer.paint['text-halo-width'] = 1.2;

    // Bold capitals, so countries and states read as a level above cities
    if (isRegionLabel(layer.id)) {
        layer.layout['text-font'] = ['Noto Sans Bold'];
        layer.layout['text-transform'] = 'uppercase';
        layer.layout['text-letter-spacing'] = 0.04;
        layer.paint['text-halo-color'] = THEME.regionTextHalo;
        layer.paint['text-halo-width'] = 1.4;
        layer.paint['text-halo-blur'] = 0.5;
    }
    if (/^label_country/.test(layer.id)) {
        layer.layout['text-size'] = ['interpolate', ['linear'], ['zoom'], 1, 9, 4, 14, 7, 16];
    }
}

function themeLayer(layer) {
    layer.paint = layer.paint || {};
    const layerColours = LAYER_COLOURS.find(({isLayer}) => isLayer(layer.id));

    if (layerColours) {
        Object.assign(layer.paint, layerColours.paint);
    } else if (hasText(layer)) {
        themeLabel(layer);
    }
}

/* Pins */

// A yellow map pin with a white dot, as markup. The dot's class lets a page
// recolour it. One line, so no whitespace text sits beside the icon
function pinMarkup() {
    return '<svg class="shape" width="20" height="27" viewBox="0 0 28 38" aria-hidden="true">'
        + `<path d="M14 0C6.3 0 0 6.2 0 13.9 0 24.3 14 38 14 38s14-13.7 14-24.1C28 6.2 21.7 0 14 0z" fill="${THEME.pin}"/>`
        + '<circle class="dot" cx="14" cy="14" r="5" fill="#ffffff"/></svg>';
}

/* Night on the vector map: shaded where the sun has set */

const NIGHT_INTERVAL = 60000;
// The shade's colour and how strong it is at full night
const NIGHT_LOOK = {colour: THEME.night, opacity: 0.32};
// Sunset is when the sun's centre is 0.8° below the horizon; twilight ends at 18°
const SUNSET_ALTITUDE = -0.8;
const NIGHT_ALTITUDE = -18;
// The shade is a soft gradient MapLibre stretches anyway, and the sun moves
// about a pixel of it every five minutes: more would only cost drawing time
const NIGHT_SHADE_SIZE = 256;
// Web Mercator stops here, so the shade covers the whole drawn world
const MERCATOR_MAX_LAT = 85.0511;
const NIGHT_SHADE_CORNERS = [
    [-180, MERCATOR_MAX_LAT],
    [180, MERCATOR_MAX_LAT],
    [180, -MERCATOR_MAX_LAT],
    [-180, -MERCATOR_MAX_LAT],
];
const DEGREES = Math.PI / 180;

// Where the sun is straight overhead, from the usual low-precision solar
// formulas (to about 0.1°)
function subsolarPointAt(date) {
    const daysSince2000 = date.getTime() / 86400000 - 10957.5;
    const meanLongitude = 280.46 + 0.9856474 * daysSince2000;
    const meanAnomaly = (357.528 + 0.9856003 * daysSince2000) * DEGREES;
    const eclipticLongitude = (meanLongitude + 1.915 * Math.sin(meanAnomaly) + 0.02 * Math.sin(2 * meanAnomaly)) * DEGREES;
    const axialTilt = (23.439 - 0.0000004 * daysSince2000) * DEGREES;

    const declination = Math.asin(Math.sin(axialTilt) * Math.sin(eclipticLongitude));
    const rightAscension = Math.atan2(Math.cos(axialTilt) * Math.sin(eclipticLongitude), Math.cos(eclipticLongitude));
    const siderealHours = (18.697374558 + 24.06570982441908 * daysSince2000) % 24;
    const lng = rightAscension / DEGREES - siderealHours * 15;

    return {lat: declination / DEGREES, lng: ((lng % 360) + 540) % 360 - 180};
}

// 0 in daylight, 1 at full night, eased through twilight
function nightAmountAt(sunAltitude) {
    const progress = (SUNSET_ALTITUDE - sunAltitude) / (SUNSET_ALTITUDE - NIGHT_ALTITUDE);
    const clamped = Math.min(1, Math.max(0, progress));
    return clamped * clamped * (3 - 2 * clamped);
}

// Drawn pixel by pixel in Web Mercator rows, so MapLibre can stretch it
// over the map without bending the edge of night
function drawNightShade(date, look) {
    const sun = subsolarPointAt(date);
    const sinSunLat = Math.sin(sun.lat * DEGREES);
    const cosSunLat = Math.cos(sun.lat * DEGREES);
    const [red, green, blue] = hexToRgb(look.colour);

    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = NIGHT_SHADE_SIZE;
    const context = canvas.getContext('2d');
    const shade = context.createImageData(NIGHT_SHADE_SIZE, NIGHT_SHADE_SIZE);

    for (let y = 0; y < NIGHT_SHADE_SIZE; y++) {
        const lat = Math.atan(Math.sinh(Math.PI * (1 - (2 * (y + 0.5)) / NIGHT_SHADE_SIZE)));
        // The row's two terms of the sun's altitude, worked out once a row for speed
        const rowSinTerm = Math.sin(lat) * sinSunLat;
        const rowCosTerm = Math.cos(lat) * cosSunLat;
        for (let x = 0; x < NIGHT_SHADE_SIZE; x++) {
            const lng = -180 + (360 * (x + 0.5)) / NIGHT_SHADE_SIZE;
            const sinAltitude = rowSinTerm + rowCosTerm * Math.cos((lng - sun.lng) * DEGREES);
            const pixel = (y * NIGHT_SHADE_SIZE + x) * 4;
            shade.data[pixel] = red;
            shade.data[pixel + 1] = green;
            shade.data[pixel + 2] = blue;
            shade.data[pixel + 3] = 255 * look.opacity * nightAmountAt(Math.asin(sinAltitude) / DEGREES);
        }
    }

    context.putImageData(shade, 0, 0);
    return canvas.toDataURL();
}

function addNightShade(map, look = NIGHT_LOOK) {
    map.addSource('night', {type: 'image', url: drawNightShade(new Date(), look), coordinates: NIGHT_SHADE_CORNERS});
    // Under the place names, so they stay readable at night
    const firstLabel = map.getStyle().layers.find(hasText)?.id;
    map.addLayer({id: 'night', type: 'raster', source: 'night', paint: {'raster-fade-duration': 0}}, firstLabel);

    const redrawNightShade = () => {
        map.getSource('night').updateImage({url: drawNightShade(new Date(), look), coordinates: NIGHT_SHADE_CORNERS});
    };
    // Never while the map moves: drawing it would cost that frame. One wait
    // at a time, so a move longer than the interval still redraws once
    let isWaitingForMapToStop = false;
    setInterval(() => {
        if (!map.isMoving()) {
            redrawNightShade();
            return;
        }
        if (isWaitingForMapToStop) return;
        isWaitingForMapToStop = true;
        map.once('moveend', () => {
            isWaitingForMapToStop = false;
            redrawNightShade();
        });
    }, NIGHT_INTERVAL);
}

// The shore under the sea, so only its landward half shows, and the shallow
// water over it
function addCoastBands(style) {
    const seaLayerIndex = style.layers.findIndex(({id}) => id === 'water');
    if (seaLayerIndex === -1) return;
    const seaLayer = style.layers[seaLayerIndex];
    style.layers.splice(seaLayerIndex, 1, COAST_LAYER, seaLayer, SHALLOW_SEA_LAYER);
}

async function loadThemedStyle() {
    const response = await fetch(STYLE_URL, {signal: AbortSignal.timeout(STYLE_TIMEOUT)});
    if (!response.ok) throw new Error(`Map style ${response.status}`);

    const style = await response.json();
    style.layers.forEach(themeLayer);
    addCoastBands(style);
    // A source's own credit takes the place of the one its tiles describe
    style.sources.openmaptiles.attribution = MAP_CREDIT;
    return style;
}

/* Helpers */

function isWebGLSupported() {
    try {
        const canvas = document.createElement('canvas');
        return Boolean(canvas.getContext('webgl2') || canvas.getContext('webgl'));
    } catch {
        return false;
    }
}

function loadAsset(tagName, attributes) {
    return new Promise((resolve, reject) => {
        const asset = document.createElement(tagName);
        const assetUrl = attributes.src ?? attributes.href;
        Object.assign(asset, attributes, {
            integrity: CDN_INTEGRITY[assetUrl] ?? '',
            crossOrigin: 'anonymous',
            referrerPolicy: 'no-referrer',
        });
        asset.onload = resolve;
        asset.onerror = reject;
        document.head.append(asset);
    });
}

function hexToRgb(hex) {
    const value = parseInt(hex.slice(1), 16);
    return [(value >> 16) & 255, (value >> 8) & 255, value & 255];
}
