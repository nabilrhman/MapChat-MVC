// Map of where everyone in the chat is.
//
// Drawn with MapLibre on OpenFreeMap's vector tiles (OpenStreetMap data, no
// API key). Their light "positron" style is recoloured below to match the
// lavender theme MapChat used on Google Maps: grey land, lavender water and
// parks, purple water labels, and yellow pins

const STYLE_URL = 'https://tiles.openfreemap.org/styles/positron';
const MAPLIBRE_URL = 'https://unpkg.com/maplibre-gl@4.7.1/dist/';
// Phones, where the map can start closed (matches chat.css)
const PHONE_SCREEN = '(max-width: 700px)';

// Where the map starts (Boise), until the pins are in
const START = {lat: 43.4599, lng: -116.244, zoom: 3};
// Fitting the pins never zooms in closer than a city
const MAX_PIN_ZOOM = 9;
// Same pace as the people list in people.js
const PINS_INTERVAL = 15000;
// Someone coming online or going moves the pins; a few at once take one fetch
const PINS_REFRESH_DELAY = 1000;
// The map fades in even if a slow tile never arrives
const SHOW_MAP_TIMEOUT = 4000;
// Without the style by then, the tile map takes over
const STYLE_TIMEOUT = 10000;

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

// Their local time in a pin's label moves on while it's open
const PIN_TIME_INTERVAL = 30000;

// Shown when hovering a pin: the name, the flag and city, then their local
// time with a sun or moon, as a message shows its sender's
function createPinLabel(location) {
    const label = createElement('div', 'pin-label');
    label.append(createElement('strong', 'name', location.shown_name || ''));

    if (location.city || location.country) {
        label.append(createPlace(location.city, location.country));
    }

    const time = createElement('span', 'time');
    time.dataset.part = 'pin-time';
    time.dataset.timezone = location.timezone || '';
    time.dataset.lng = location.lng;
    time.append(createIcon('sun', 'daylight'), createElement('span'));
    showPinTime(time);
    label.append(time);

    return label;
}

// Without their time zone, localTimeOf (chat.js) estimates it from the longitude
function showPinTime(time) {
    const localTime = localTimeOf(new Date(), time.dataset.timezone, parseFloat(time.dataset.lng));
    showDaylight(time.querySelector('svg'), localTime.hour);
    time.querySelector('span').textContent = localTime.text;
}

setInterval(() => document.querySelectorAll('[data-part="pin-time"]').forEach(showPinTime), PIN_TIME_INTERVAL);

// A yellow map pin like the original marker icon. userId lets the people
// list, and a message's city, highlight this pin
function createPinElement(userId) {
    const pin = createElement('div', 'map-pin');
    pin.dataset.part = 'map-pin';
    pin.dataset.userId = userId;
    // The map library labels each marker; a label needs a role to be allowed
    pin.setAttribute('role', 'img');
    // One line, so no whitespace text sits beside the icon
    pin.innerHTML = '<svg class="shape" width="20" height="27" viewBox="0 0 28 38" aria-hidden="true">'
        + `<path d="M14 0C6.3 0 0 6.2 0 13.9 0 24.3 14 38 14 38s14-13.7 14-24.1C28 6.2 21.7 0 14 0z" fill="${THEME.pin}"/>`
        + '<circle cx="14" cy="14" r="5" fill="#ffffff"/></svg>';
    return pin;
}

// Fades a pin out, then takes it off the map
function fadeOutPin(pin, removePin) {
    pin.classList.add('is-leaving');
    pin.addEventListener('animationend', removePin, {once: true});
}

const lngLatOf = (location) => [parseFloat(location.lng), parseFloat(location.lat)];
const latLngOf = (location) => [parseFloat(location.lat), parseFloat(location.lng)];

/* Fitting the map */

// The furthest out you can zoom: the world (a square tileSize pixels tall at
// zoom 0) must be at least as tall as the map, so it never ends mid-screen
function minZoomToFill(tileSize) {
    const mapHeight = document.getElementById('map').clientHeight;
    return Math.max(0, Math.log2(mapHeight / tileSize));
}

// Room around the pins when zooming to fit them, so no pin ends up behind the
// chat panel or the people list
function getMapPadding() {
    const chatPanel = document.getElementById('chat').getBoundingClientRect();
    // Zero while the people list is a closed drawer
    const peopleWidth = document.getElementById('people').getBoundingClientRect().width;

    // On phones the chat sits below the map instead of beside it
    const isChatBelowMap = chatPanel.width >= window.innerWidth * 0.9;
    if (isChatBelowMap) {
        return {top: 40, bottom: window.innerHeight - chatPanel.top + 40, left: 40, right: 40};
    }
    return {top: 60, bottom: 60, left: chatPanel.width + 60, right: peopleWidth + 60};
}

/* Showing the map and keeping its pins up to date */

// The vector map, once made, so showMapOnce can wait for its tiles
let vectorMap = null;
let isMapShown = false;

// Fades the map in and drops the dots over it, once. The vector map first
// waits until it has moved to the pins and drawn the tiles there, so the
// view never jumps or fills in while someone watches
function showMapOnce() {
    if (isMapShown) return;
    isMapShown = true;

    const showMap = () => {
        document.getElementById('map').classList.add('is-shown');
        document.getElementById('map-loading').classList.add('is-done');
    };

    const isVectorMapSettled = vectorMap && vectorMap.areTilesLoaded() && !vectorMap.isMoving();
    if (vectorMap && !isVectorMapSettled) {
        vectorMap.once('idle', showMap);
        setTimeout(showMap, SHOW_MAP_TIMEOUT);
    } else {
        showMap();
    }
}

async function loadLocations() {
    const response = await postForm('/app/get-locations');
    return response.data;
}

// Everything a pin shows; when it changes, the pin is drawn again
const pinContentOf = (location) => [location.lat, location.lng, location.shown_name, location.city, location.country, location.timezone].join('|');

// What the rest of the page asks of the pins (refreshPinsSoon, focusPin)
// and the map's own rounds share
const pins = {
    // Fetches them now; set by keepPinsUpdated, once the map is made
    refresh: () => {},
    nextRefreshTimer: null,
    // Set once the map has its pins; a pin asked for before then is
    // focused once it does
    haveLoaded: false,
    userIdToFocus: null,
};

// Fetches the pins now, and again when someone comes online or goes
// (refreshPinsSoon), or every round on the HTTP fallback. Adds new people,
// drops those gone or hidden, and moves anyone whose place changed. The map only
// zooms to the pins the first time, so it never jumps while someone looks at
// it. Pins after the first round ease in. addPin(location, shouldAnimate)
// returns a function that removes the pin
function keepPinsUpdated(addPin, fitPins) {
    const pinsByUserId = new Map();
    let isFirstRound = true;

    function updatePins(locations) {
        const shownUserIds = new Set();

        locations.forEach((location) => {
            const content = pinContentOf(location);
            const pin = pinsByUserId.get(location.user_id);
            shownUserIds.add(location.user_id);

            if (pin?.content === content) return;
            pin?.removePin();
            pinsByUserId.set(location.user_id, {content, removePin: addPin(location, !isFirstRound)});
        });

        pinsByUserId.forEach((pin, userId) => {
            if (shownUserIds.has(userId)) return;
            pin.removePin();
            pinsByUserId.delete(userId);
        });
    }

    async function refreshPins() {
        try {
            const locations = await loadLocations();
            updatePins(locations);
            if (isFirstRound) {
                fitPins(locations);
                isFirstRound = false;
            }
            pins.haveLoaded = true;
            if (pins.userIdToFocus !== null) {
                focusPin(pins.userIdToFocus);
                pins.userIdToFocus = null;
            }
        } catch {
            // Try again on the next round
        }
        showMapOnce();
        clearTimeout(pins.nextRefreshTimer);
        if (!isChatSocketLive()) pins.nextRefreshTimer = setTimeout(refreshPins, PINS_INTERVAL);
    }

    pins.refresh = refreshPins;
    refreshPins();
}

// After a presence change, or when polling starts or stops (chat.js)
function refreshPinsSoon() {
    clearTimeout(pins.nextRefreshTimer);
    pins.nextRefreshTimer = setTimeout(() => pins.refresh(), PINS_REFRESH_DELAY);
}

// How far above a pin's tip its label sits. A highlighted pin grows by
// 1.35 from its tip (.map-pin.is-highlighted), so its label sits higher by
// what it grew, or the pin pokes into it
const PIN_HEIGHT = 27;
const PIN_LABEL_OFFSET = 26;
const HIGHLIGHTED_PIN_LABEL_OFFSET = PIN_LABEL_OFFSET + Math.round(PIN_HEIGHT * 0.35);
const PIN_HALF_WIDTH = 10;

// Where a MapLibre label sits, by the side of the pin it opens on. Above by
// default, but near the map's top edge MapLibre moves it below, and near a
// side beside the pin: one offset for all would leave it floating away
function vectorLabelOffsetsOf(offsetAbove) {
    const above = [0, -offsetAbove];
    const belowTip = [0, 0];
    const besideHeadHeight = -Math.round(offsetAbove / 2);
    return {
        'center': above,
        'bottom': above,
        'bottom-left': above,
        'bottom-right': above,
        'top': belowTip,
        'top-left': belowTip,
        'top-right': belowTip,
        'left': [PIN_HALF_WIDTH, besideHeadHeight],
        'right': [-PIN_HALF_WIDTH, besideHeadHeight],
    };
}

// Each pin's label, by user id: a pin highlighted from the people list or a
// message's city shows its label too
const pinLabelsByUserId = new Map();

// Clicking a message's city zooms in at least this far, to the region around the pin
const FOCUS_PIN_ZOOM = 4;
// ...in this long; the vector map starts gently, speeds up, then settles
const FOCUS_DURATION = 800;
const easeInOut = (progress) => (progress < 0.5 ? 4 * progress ** 3 : 1 - (-2 * progress + 2) ** 3 / 2);

// Brings the person's pin to the middle of the map's visible part, with its
// label open (a click on a message's city). Nothing if they have no pin:
// offline, or hidden from the map
function focusPin(userId) {
    const label = pinLabelsByUserId.get(String(userId));
    if (!label) {
        if (!pins.haveLoaded) pins.userIdToFocus = String(userId);
        return;
    }
    label.focus();
}

const getLabelOffset = (pin) => (pin.classList.contains('is-highlighted') ? HIGHLIGHTED_PIN_LABEL_OFFSET : PIN_LABEL_OFFSET);

function setPinLabelShown(userId, isShown) {
    const label = pinLabelsByUserId.get(String(userId));
    if (isShown) {
        label?.show(HIGHLIGHTED_PIN_LABEL_OFFSET);
    } else {
        label?.hide();
    }
}

/* The vector map (MapLibre) */

// One label at a time, so tapping another pin swaps them
let openPinPopup = null;

// Adds a MapLibre pin; returns a function that removes it
function addVectorPin(map, location, shouldAnimate) {
    const lngLat = lngLatOf(location);
    const label = createPinLabel(location);
    const popupTime = label.querySelector('[data-part="pin-time"]');
    const popup = new maplibregl.Popup({closeButton: false, offset: vectorLabelOffsetsOf(PIN_LABEL_OFFSET)})
        .setDOMContent(label);
    const marker = new maplibregl.Marker({element: createPinElement(location.user_id), anchor: 'bottom'})
        .setLngLat(lngLat)
        .addTo(map);

    function showLabel(offset = PIN_LABEL_OFFSET) {
        if (openPinPopup !== popup) openPinPopup?.remove();
        openPinPopup = popup;
        popup.setOffset(vectorLabelOffsetsOf(offset));
        // Made when the pin was; the time is now's
        showPinTime(popupTime);
        popup.setLngLat(lngLat).addTo(map);
    }

    const pin = marker.getElement();
    pin.classList.toggle('is-entering', shouldAnimate);
    // A mouse shows the label while over the pin; a tap keeps it until the
    // next tap on the map
    pin.addEventListener('pointerenter', (event) => event.pointerType === 'mouse' && showLabel());
    pin.addEventListener('pointerleave', (event) => event.pointerType === 'mouse' && popup.remove());
    pin.addEventListener('click', (event) => {
        // Or the map's own click would close the label straight away
        event.stopPropagation();
        showLabel();
    });

    pinLabelsByUserId.set(String(location.user_id), {
        show: showLabel,
        hide: () => popup.remove(),
        focus: () => {
            // An ease, not a flight: no zooming out and back in, which fetches
            // tiles at in-between zooms and hitches
            map.easeTo({
                center: lngLat,
                zoom: Math.max(map.getZoom(), FOCUS_PIN_ZOOM),
                padding: getMapPadding(),
                duration: FOCUS_DURATION,
                easing: easeInOut,
            });
            showLabel(getLabelOffset(pin));
        },
    });

    return () => {
        pinLabelsByUserId.delete(String(location.user_id));
        popup.remove();
        fadeOutPin(pin, () => marker.remove());
    };
}

function fitVectorPins(map, locations) {
    const bounds = new maplibregl.LngLatBounds();
    locations.forEach((location) => bounds.extend(lngLatOf(location)));

    const padding = getMapPadding();
    if (locations.length === 1) {
        map.jumpTo({center: bounds.getCenter(), zoom: MAX_PIN_ZOOM, padding});
    } else if (locations.length > 1) {
        map.fitBounds(bounds, {padding, maxZoom: MAX_PIN_ZOOM, animate: false});
    }
}

// Credits as a small (i) beside the people list (see chat.css)
function addVectorCredits(map) {
    map.addControl(new maplibregl.AttributionControl({compact: true}), 'bottom-right');
    // No tooltip on the (i): it explains itself. The aria-label stays for screen readers
    map.getContainer().querySelector('.maplibregl-ctrl-attrib-button').removeAttribute('title');

    // MapLibre opens them at first; close them once the map is drawn
    map.once('load', () => {
        const credits = map.getContainer().querySelector('.maplibregl-ctrl-attrib');
        credits.classList.remove('maplibregl-compact-show');
        credits.removeAttribute('open');
    });
}

/* Night on the vector map: shaded where the sun has set */

const NIGHT_INTERVAL = 60000;
const NIGHT_OPACITY = 0.32;
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
function drawNightShade(date) {
    const sun = subsolarPointAt(date);
    const sinSunLat = Math.sin(sun.lat * DEGREES);
    const cosSunLat = Math.cos(sun.lat * DEGREES);
    const [red, green, blue] = hexToRgb(THEME.night);

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
            shade.data[pixel + 3] = 255 * NIGHT_OPACITY * nightAmountAt(Math.asin(sinAltitude) / DEGREES);
        }
    }

    context.putImageData(shade, 0, 0);
    return canvas.toDataURL();
}

function addNightShade(map) {
    map.addSource('night', {type: 'image', url: drawNightShade(new Date()), coordinates: NIGHT_SHADE_CORNERS});
    // Under the place names, so they stay readable at night
    const firstLabel = map.getStyle().layers.find(hasText)?.id;
    map.addLayer({id: 'night', type: 'raster', source: 'night', paint: {'raster-fade-duration': 0}}, firstLabel);

    setInterval(() => {
        map.getSource('night').updateImage({url: drawNightShade(new Date()), coordinates: NIGHT_SHADE_CORNERS});
    }, NIGHT_INTERVAL);
}

async function loadThemedStyle() {
    const response = await fetch(STYLE_URL, {signal: AbortSignal.timeout(STYLE_TIMEOUT)});
    if (!response.ok) throw new Error(`Map style ${response.status}`);

    const style = await response.json();
    style.layers.forEach(themeLayer);
    return style;
}

async function initializeVectorMap() {
    const [style] = await Promise.all([
        loadThemedStyle(),
        loadAsset('script', {src: `${MAPLIBRE_URL}maplibre-gl.js`}),
        loadAsset('link', {rel: 'stylesheet', href: `${MAPLIBRE_URL}maplibre-gl.css`}),
    ]);
    const map = new maplibregl.Map({
        container: 'map',
        style,
        center: [START.lng, START.lat],
        zoom: START.zoom,
        minZoom: minZoomToFill(512),
        attributionControl: false,
    });
    vectorMap = map;
    addVectorCredits(map);

    window.addEventListener('resize', () => map.setMinZoom(minZoomToFill(512)));

    map.on('load', () => {
        addNightShade(map);
        keepPinsUpdated(
            (location, shouldAnimate) => addVectorPin(map, location, shouldAnimate),
            (locations) => fitVectorPins(map, locations),
        );
    });
}

/* The tile map (Leaflet), for browsers without WebGL */

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

// Recolour a standard OpenStreetMap tile into the MapChat theme, pixel by
// pixel: blue water becomes lavender (its labels purple), green parks and
// woods a faint lavender tint, and everything else light grey. Runs for every
// pixel of every tile, so it reads and writes the channels directly instead
// of making an array per pixel
function themeTile(imageData) {
    const water = hexToRgb(THEME.water);
    const waterText = hexToRgb(THEME.waterText);
    const park = [236, 231, 250]; // much paler than THEME.park: OSM has far more green
    const pixels = imageData.data;

    for (let i = 0; i < pixels.length; i += 4) {
        const red = pixels[i];
        const green = pixels[i + 1];
        const blue = pixels[i + 2];
        const brightest = Math.max(red, green, blue);
        const darkest = Math.min(red, green, blue);
        const lightness = (brightest + darkest) / 510;
        const isColourful = brightest - darkest > 28;
        let colour;

        if (isColourful && blue >= green && blue > red + 20) {
            colour = lightness > 0.55 ? water : waterText; // water, water labels
        } else if (green - red > 25 && green > blue && lightness > 0.55) {
            colour = park; // parks, woods
        } else {
            const grey = 0.299 * red + 0.587 * green + 0.114 * blue;
            const softGrey = 255 - (255 - grey) * 0.75; // lighter, softer grey
            colour = [softGrey, softGrey, softGrey];
        }

        pixels[i] = colour[0];
        pixels[i + 1] = colour[1];
        pixels[i + 2] = colour[2];
    }

    return imageData;
}

// Decoding and recolouring a tile takes about 2 ms; a zoom brings a few
// dozen at once, and on the page's own thread they made the map hitch. The
// page still loads each tile as an image, so OpenStreetMap gets the request
// it expects (its servers refuse ones without the site's address); the
// browser decodes it off the page's thread, and a worker recolours it and
// sends it back. It runs this file's own themeTile and hexToRgb, from their
// source, so there's one copy
function createTileWorker() {
    if (!window.Worker || !window.OffscreenCanvas || !window.createImageBitmap) return null;

    const source = `
        const THEME = ${JSON.stringify({water: THEME.water, waterText: THEME.waterText})};
        ${hexToRgb}
        ${themeTile}
        self.onmessage = ({data: {id, tile}}) => {
            try {
                const canvas = new OffscreenCanvas(256, 256);
                const context = canvas.getContext('2d', {willReadFrequently: true});
                context.drawImage(tile, 0, 0);
                tile.close();
                context.putImageData(themeTile(context.getImageData(0, 0, 256, 256)), 0, 0);
                const image = canvas.transferToImageBitmap();
                self.postMessage({id, image}, [image]);
            } catch (error) {
                self.postMessage({id, error: String(error)});
            }
        };`;
    return new Worker(URL.createObjectURL(new Blob([source], {type: 'text/javascript'})));
}

// Each OpenStreetMap tile, recoloured before it's shown: by the worker, or
// on the page where there's none
function createThemedTileLayer() {
    const worker = createTileWorker();
    const waitingTiles = new Map();
    let nextTileId = 1;

    worker?.addEventListener('message', ({data: {id, image, error}}) => {
        const tile = waitingTiles.get(id);
        if (!tile) return;
        waitingTiles.delete(id);
        if (image) {
            tile.canvas.getContext('2d').drawImage(image, 0, 0);
            image.close();
        }
        tile.done(error ?? null, tile.canvas);
    });

    const ThemedTiles = L.GridLayer.extend({
        createTile(coords, done) {
            const canvas = document.createElement('canvas');
            canvas.width = 256;
            canvas.height = 256;
            const tile = new Image();
            tile.crossOrigin = 'anonymous';
            tile.onload = () => {
                if (worker) {
                    const id = nextTileId++;
                    waitingTiles.set(id, {canvas, done});
                    createImageBitmap(tile)
                        .then((bitmap) => worker.postMessage({id, tile: bitmap}, [bitmap]))
                        .catch((error) => {
                            waitingTiles.delete(id);
                            done(error, canvas);
                        });
                    return;
                }
                const context = canvas.getContext('2d', {willReadFrequently: true});
                context.drawImage(tile, 0, 0);
                context.putImageData(themeTile(context.getImageData(0, 0, 256, 256)), 0, 0);
                done(null, canvas);
            };
            tile.onerror = (error) => done(error, canvas);
            tile.src = `https://tile.openstreetmap.org/${coords.z}/${coords.x}/${coords.y}.png`;

            return canvas;
        },
    });

    return new ThemedTiles({
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    });
}

// Credits beside the people list (see chat.css); the tiles add theirs to it.
// It's an "i" that opens on a click, like MapLibre's
function addTileCredits(map) {
    map.attributionControl = L.control.attribution({position: 'bottomright', prefix: false}).addTo(map);

    const credits = map.attributionControl.getContainer();
    L.DomEvent.on(credits, 'click', (event) => {
        if (event.target.tagName !== 'A') {
            credits.classList.toggle('is-open');
        }
    });
}

// The room around the pins (getMapPadding) as Leaflet takes it
function leafletPaddingOf(padding) {
    return {
        paddingTopLeft: [padding.left, padding.top],
        paddingBottomRight: [padding.right, padding.bottom],
    };
}

// Adds a Leaflet pin; returns a function that removes it
function addTilePin(map, location, shouldAnimate) {
    const pin = createPinElement(location.user_id);
    pin.classList.toggle('is-entering', shouldAnimate);
    const icon = L.divIcon({className: '', html: pin.outerHTML, iconSize: [20, PIN_HEIGHT], iconAnchor: [10, PIN_HEIGHT]});
    const marker = L.marker(latLngOf(location), {icon})
        // Made each time it opens, so the time is now's
        .bindTooltip(() => createPinLabel(location), {direction: 'top', offset: [0, -PIN_LABEL_OFFSET]})
        .addTo(map);

    const setTooltipOffset = (offset) => {
        marker.getTooltip().options.offset = L.point(0, -offset);
    };
    const showTooltip = (offset) => {
        setTooltipOffset(offset);
        marker.openTooltip();
    };
    pinLabelsByUserId.set(String(location.user_id), {
        show: showTooltip,
        // Leaflet's own flight, which keeps its tiles in step; it slows into
        // place but doesn't ease off. Driving it frame by frame for that
        // left tiles unloaded or drawn off to the side. A bounds of one point,
        // as only fitting bounds takes the map's padding here
        focus: () => {
            const latLng = latLngOf(location);
            map.flyToBounds([latLng, latLng], {
                ...leafletPaddingOf(getMapPadding()),
                maxZoom: Math.max(map.getZoom(), FOCUS_PIN_ZOOM),
                duration: FOCUS_DURATION / 1000,
            });
            showTooltip(getLabelOffset(marker.getElement().querySelector('[data-part="map-pin"]')));
        },
        // Back to the usual height, for a plain hover on the pin
        hide: () => {
            marker.closeTooltip();
            setTooltipOffset(PIN_LABEL_OFFSET);
        },
    });

    return () => {
        pinLabelsByUserId.delete(String(location.user_id));
        marker.closeTooltip();
        fadeOutPin(marker.getElement().querySelector('[data-part="map-pin"]'), () => marker.remove());
    };
}

function fitTilePins(map, locations) {
    if (locations.length === 0) return;

    map.fitBounds(locations.map(latLngOf), {
        ...leafletPaddingOf(getMapPadding()),
        maxZoom: MAX_PIN_ZOOM,
    });
}

async function initializeTileMap() {
    const leafletUrl = 'https://unpkg.com/leaflet@1.9.4/dist/';
    loadAsset('link', {rel: 'stylesheet', href: `${leafletUrl}leaflet.css`});
    await loadAsset('script', {src: `${leafletUrl}leaflet.js`});

    const map = L.map('map', {
        zoomControl: false,
        attributionControl: false,
        worldCopyJump: true,
        zoomSnap: 0.25,
        minZoom: minZoomToFill(256),
        // Stop dragging past the top and bottom of the world
        maxBounds: [[-85, -Infinity], [85, Infinity]],
        maxBoundsViscosity: 1,
    }).setView([START.lat, START.lng], START.zoom);

    window.addEventListener('resize', () => map.setMinZoom(minZoomToFill(256)));
    addTileCredits(map);
    createThemedTileLayer().addTo(map);

    keepPinsUpdated(
        (location, shouldAnimate) => addTilePin(map, location, shouldAnimate),
        (locations) => fitTilePins(map, locations),
    );
}

// Browsers without WebGL can't draw the vector map, so they get OpenStreetMap
// tiles with Leaflet, recoloured to the same theme. If even that fails, the
// map's box still fades in
function initializeFallback() {
    initializeTileMap().catch(showMapOnce);
}

function initializeMap() {
    if (!isWebGLSupported()) {
        initializeFallback();
        return;
    }
    // Without the vector style, the tile map still shows the pins
    initializeVectorMap().catch(initializeFallback);
}

// Wider screens always show the map; phones only once the map button opens it.
// The map stays laid out under the chat while closed, so isShown can't tell
function isMapOpen() {
    return !window.matchMedia(PHONE_SCREEN).matches || document.body.classList.contains('is-map-open');
}

// Phones with the map closed don't load it until the map button opens it,
// so the chat gets the connection to itself. Once started, it stays
function initializeMapWhenOpen() {
    const phoneScreen = window.matchMedia(PHONE_SCREEN);
    const mapToggle = document.getElementById('map-toggle');

    function startIfOpen() {
        if (!isMapOpen()) return;
        mapToggle.removeEventListener('click', startIfOpen);
        phoneScreen.removeEventListener('change', startIfOpen);
        initializeMap();
    }

    // chat.js loads first, so its click handler has already opened the map
    mapToggle.addEventListener('click', startIfOpen);
    // Turning a phone or widening the window past the breakpoint shows it too
    phoneScreen.addEventListener('change', startIfOpen);
    startIfOpen();
}

// The script loads at the end of the page, so the map's box is already there
initializeMapWhenOpen();
