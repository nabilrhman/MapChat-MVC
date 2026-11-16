// Map of where everyone in the chat is.
//
// Drawn with MapLibre on OpenFreeMap's vector tiles (OpenStreetMap data, no
// API key). Their light "positron" style is recoloured below to match the
// lavender theme MapChat used on Google Maps: grey land, lavender water and
// parks, purple water labels, and yellow pins.

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

// The old Google Maps style, by map layer
const THEME = {
    land: '#f5f5f5',
    park: '#daccff',
    building: '#ececec',
    water: '#c6b9ff',
    road: '#ffffff',
    roadCasing: '#e8e8e8',
    highway: '#dadada',
    highwayCasing: '#cfcfcf',
    rail: '#e5e5e5',
    boundary: '#c8c8c8',
    text: '#616161',
    textHalo: '#f5f5f5',
    roadText: '#757575',
    minorRoadText: '#9e9e9e',
    waterText: '#8746ff',
    pin: '#ffc107',
};

/* The vector map's colours */

// The positron style's layers that change colour, by layer id; the first
// match wins
const LAYER_COLOURS = [
    {isLayer: (id) => id === 'background', paint: {'background-color': THEME.land}},
    {isLayer: (id) => id === 'landuse_residential', paint: {'fill-color': THEME.land}},
    {isLayer: (id) => id === 'park' || id === 'landcover_wood', paint: {'fill-color': THEME.park}},
    {isLayer: (id) => id === 'water', paint: {'fill-color': THEME.water}},
    {isLayer: (id) => id === 'waterway', paint: {'line-color': THEME.water}},
    {isLayer: (id) => id === 'building', paint: {'fill-color': THEME.building, 'fill-outline-color': THEME.building}},
    {
        isLayer: (id) => /^(tunnel_|highway_)?motorway.*casing$|^highway_motorway_bridge_casing$/.test(id),
        paint: {'line-color': THEME.highwayCasing},
    },
    {isLayer: (id) => /motorway_(inner|subtle)$/.test(id), paint: {'line-color': THEME.highway}},
    {isLayer: (id) => id === 'highway_major_casing', paint: {'line-color': THEME.roadCasing}},
    {isLayer: (id) => /^highway_(major_inner|major_subtle|minor|path)$/.test(id), paint: {'line-color': THEME.road}},
    {isLayer: (id) => /^railway/.test(id) && !/dashline$/.test(id), paint: {'line-color': THEME.rail}},
    {isLayer: (id) => /^boundary/.test(id), paint: {'line-color': THEME.boundary}},
];

const hasText = (layer) => layer.type === 'symbol' && Boolean(layer.layout?.['text-field']);

function getLabelColour(layerId) {
    if (/^water/.test(layerId)) return THEME.waterText;
    if (layerId === 'highway-name-major') return THEME.roadText;
    if (/^highway-name/.test(layerId)) return THEME.minorRoadText;
    return THEME.text;
}

function themeLayer(layer) {
    layer.paint = layer.paint || {};
    const layerColours = LAYER_COLOURS.find(({isLayer}) => isLayer(layer.id));

    if (layerColours) {
        Object.assign(layer.paint, layerColours.paint);
    } else if (hasText(layer)) {
        layer.paint['text-color'] = getLabelColour(layer.id);
        layer.paint['text-halo-color'] = THEME.textHalo;
    }
}

/* Pins */

// Shown when hovering a pin: the name, then the flag and city
function createPinLabel(location) {
    const label = createElement('div', 'pin-label');
    label.append(createElement('strong', 'pin-label__name', location.shown_name || ''));

    if (location.city) {
        const place = createElement('span', 'pin-label__place');
        if (location.country) {
            place.append(createElement('span', `fi fi-${location.country.toLowerCase()}`));
        }
        place.append(location.city);
        label.append(place);
    }

    return label;
}

// A yellow map pin like the original marker icon. userId lets the people
// list highlight this pin
function createPinElement(userId) {
    const pin = createElement('div', 'map-pin');
    pin.dataset.userId = userId;
    // One line, so no whitespace text sits beside the icon
    pin.innerHTML = '<svg class="map-pin__icon" width="20" height="27" viewBox="0 0 28 38" aria-hidden="true">'
        + `<path d="M14 0C6.3 0 0 6.2 0 13.9 0 24.3 14 38 14 38s14-13.7 14-24.1C28 6.2 21.7 0 14 0z" fill="${THEME.pin}"/>`
        + '<circle cx="14" cy="14" r="5" fill="#ffffff"/></svg>';
    return pin;
}

// Fades a pin out, then takes it off the map
function fadeOutPin(pin, removePin) {
    pin.classList.add('is-leaving');
    pin.addEventListener('animationend', removePin, {once: true});
}

const getLngLat = (location) => [parseFloat(location.lng), parseFloat(location.lat)];
const getLatLng = (location) => [parseFloat(location.lat), parseFloat(location.lng)];

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
    const chatPanel = document.querySelector('.chat-panel').getBoundingClientRect();
    // Zero while the people list is a closed drawer
    const peopleWidth = document.querySelector('.people').getBoundingClientRect().width;

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
const getPinContent = (location) => [location.lat, location.lng, location.shown_name, location.city, location.country].join('|');

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
            const content = getPinContent(location);
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

    function refreshPins() {
        loadLocations()
            .then((locations) => {
                updatePins(locations);
                if (isFirstRound) {
                    fitPins(locations);
                    isFirstRound = false;
                }
            })
            .catch(() => {
                // Try again on the next round
            })
            .finally(() => {
                showMapOnce();
                clearTimeout(nextPinsRefresh);
                if (!isChatSocketLive()) nextPinsRefresh = setTimeout(refreshPins, PINS_INTERVAL);
            });
    }

    refreshPinsNow = refreshPins;
    refreshPins();
}

// Set by keepPinsUpdated, once the map is made
let refreshPinsNow = () => {};
let nextPinsRefresh = null;

// After a presence change, or when polling starts or stops (chat.js)
function refreshPinsSoon() {
    clearTimeout(nextPinsRefresh);
    nextPinsRefresh = setTimeout(() => refreshPinsNow(), PINS_REFRESH_DELAY);
}

/* The vector map (MapLibre) */

// One label at a time, so tapping another pin swaps them
let openPinPopup = null;

// Adds a MapLibre pin; returns a function that removes it
function addVectorPin(map, location, shouldAnimate) {
    const lngLat = getLngLat(location);
    const popup = new maplibregl.Popup({closeButton: false, offset: 26})
        .setDOMContent(createPinLabel(location));
    const marker = new maplibregl.Marker({element: createPinElement(location.user_id), anchor: 'bottom'})
        .setLngLat(lngLat)
        .addTo(map);

    function showLabel() {
        if (openPinPopup !== popup) openPinPopup?.remove();
        openPinPopup = popup;
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

    return () => {
        popup.remove();
        fadeOutPin(pin, () => marker.remove());
    };
}

function fitVectorPins(map, locations) {
    const bounds = new maplibregl.LngLatBounds();
    locations.forEach((location) => bounds.extend(getLngLat(location)));

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

async function loadThemedStyle() {
    const response = await fetch(STYLE_URL);
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

// Each OpenStreetMap tile, recoloured on a canvas before it's shown
function createThemedTileLayer() {
    const ThemedTiles = L.GridLayer.extend({
        createTile(coords, done) {
            const canvas = document.createElement('canvas');
            canvas.width = 256;
            canvas.height = 256;

            const tile = new Image();
            tile.crossOrigin = 'anonymous';
            tile.onload = () => {
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
// On phones it's an "i" that opens on tap, like MapLibre's
function addTileCredits(map) {
    map.attributionControl = L.control.attribution({position: 'bottomright', prefix: false}).addTo(map);

    const credits = map.attributionControl.getContainer();
    L.DomEvent.on(credits, 'click', (event) => {
        if (event.target.tagName !== 'A') {
            credits.classList.toggle('is-open');
        }
    });
}

// Adds a Leaflet pin; returns a function that removes it
function addTilePin(map, location, shouldAnimate) {
    const pin = createPinElement(location.user_id);
    pin.classList.toggle('is-entering', shouldAnimate);
    const icon = L.divIcon({className: '', html: pin.outerHTML, iconSize: [20, 27], iconAnchor: [10, 27]});
    const marker = L.marker(getLatLng(location), {icon})
        .bindTooltip(createPinLabel(location).outerHTML, {direction: 'top', offset: [0, -26]})
        .addTo(map);

    return () => {
        marker.closeTooltip();
        fadeOutPin(marker.getElement().querySelector('.map-pin'), () => marker.remove());
    };
}

function fitTilePins(map, locations) {
    if (locations.length === 0) return;

    const padding = getMapPadding();
    map.fitBounds(locations.map(getLatLng), {
        paddingTopLeft: [padding.left, padding.top],
        paddingBottomRight: [padding.right, padding.bottom],
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

// Phones with the map closed don't load it until the map button opens it,
// so the chat gets the connection to itself. Once started, it stays
function initializeMapWhenOpen() {
    const phoneScreen = window.matchMedia(PHONE_SCREEN);
    const mapToggle = document.getElementById('map-toggle');
    const isMapOpen = () => !phoneScreen.matches || document.body.classList.contains('is-map-open');

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
