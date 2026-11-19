// MapChat's map look, shared by the chat's map (map.js) and the one behind
// the login pages (login-map.js): OpenFreeMap's positron style recoloured
// lavender, and a purple shade wherever the sun has set

const STYLE_URL = 'https://tiles.openfreemap.org/styles/positron';
const MAPLIBRE_URL = 'https://unpkg.com/maplibre-gl@4.7.1/dist/';
// Without the style by then, the tile map takes over
const STYLE_TIMEOUT = 10000;
// The map's credit, in place of the tiles' longer "… Data from OpenStreetMap":
// the same three, each still linked
const MAP_CREDIT = '<a href="https://openfreemap.org" target="_blank">OpenFreeMap</a>'
    + ' <a href="https://www.openmaptiles.org/" target="_blank">&copy; OpenMapTiles</a>'
    + ' <a href="https://www.openstreetmap.org/copyright" target="_blank">&copy; OpenStreetMap</a>';

// MapChat's purple map, by map layer: near-white land, lavender water, and
// text in purples from deep (countries) to light (small places)
const THEME = {
    land: '#fcfaff',
    residential: '#f8f4ff',
    park: '#ece3fe',
    building: '#f1eafd',
    buildingEdge: '#e0d4f9',
    water: '#d2c5fd',
    road: '#ffffff',
    roadCasing: '#d9cbf7',
    path: '#e8dffb',
    highway: '#e2d8fb',
    highwayCasing: '#c8b8ef',
    rail: '#d9cbf7',
    boundary: '#9d84e6',
    stateBoundary: '#c9b8f2',
    countryText: '#3a2580',
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

// The positron style's layers that change colour, by layer id; the first
// match wins
const LAYER_COLOURS = [
    {isLayer: (id) => id === 'background', paint: {'background-color': THEME.land}},
    {isLayer: (id) => id === 'landuse_residential', paint: {'fill-color': THEME.residential}},
    {isLayer: (id) => id === 'park' || id === 'landcover_wood', paint: {'fill-color': THEME.park}},
    {isLayer: (id) => id === 'water', paint: {'fill-color': THEME.water}},
    {isLayer: (id) => id === 'waterway', paint: {'line-color': THEME.water}},
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

// The colour of each kind of place name, by layer id; the first match wins
const LABEL_COLOURS = [
    {isLayer: (id) => /^label_country/.test(id), colour: THEME.countryText},
    {isLayer: (id) => /^label_city/.test(id), colour: THEME.cityText},
    {isLayer: (id) => /^label_(town|village)$/.test(id), colour: THEME.townText},
    {isLayer: (id) => /^water/.test(id), colour: THEME.waterText},
    {isLayer: (id) => /^highway-name/.test(id), colour: THEME.roadText},
];

const hasText = (layer) => layer.type === 'symbol' && Boolean(layer.layout?.['text-field']);

function labelColourOf(layerId) {
    return LABEL_COLOURS.find(({isLayer}) => isLayer(layerId))?.colour ?? THEME.otherText;
}

function themeLabel(layer) {
    layer.paint['text-color'] = labelColourOf(layer.id);
    layer.paint['text-halo-color'] = /^water/.test(layer.id) ? THEME.waterTextHalo : THEME.textHalo;
    layer.paint['text-halo-width'] = 1.2;

    // Spaced capitals, so country names read as a level above cities
    if (/^label_country/.test(layer.id)) {
        layer.layout['text-transform'] = 'uppercase';
        layer.layout['text-letter-spacing'] = 0.12;
        layer.layout['text-size'] = ['interpolate', ['linear'], ['zoom'], 1, 8, 4, 13, 7, 15];
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
const NIGHT_SHADE_SIZE = 512;
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
        for (let x = 0; x < NIGHT_SHADE_SIZE; x++) {
            const lng = -180 + (360 * (x + 0.5)) / NIGHT_SHADE_SIZE;
            const sinAltitude = Math.sin(lat) * sinSunLat + Math.cos(lat) * cosSunLat * Math.cos((lng - sun.lng) * DEGREES);
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

    setInterval(() => {
        map.getSource('night').updateImage({url: drawNightShade(new Date(), look), coordinates: NIGHT_SHADE_CORNERS});
    }, NIGHT_INTERVAL);
}

async function loadThemedStyle() {
    const response = await fetch(STYLE_URL, {signal: AbortSignal.timeout(STYLE_TIMEOUT)});
    if (!response.ok) throw new Error(`Map style ${response.status}`);

    const style = await response.json();
    style.layers.forEach(themeLayer);
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
        Object.assign(asset, attributes);
        asset.onload = resolve;
        asset.onerror = reject;
        document.head.append(asset);
    });
}

function hexToRgb(hex) {
    const value = parseInt(hex.slice(1), 16);
    return [(value >> 16) & 255, (value >> 8) & 255, value & 255];
}
