// Map of where everyone in the chat is.
//
// Drawn with MapLibre on OpenFreeMap's vector tiles (OpenStreetMap data, no
// API key), in the lavender look map-style.js gives them, with yellow pins.
// Without WebGL, Leaflet draws OpenStreetMap's tiles recoloured to match

// The furthest out each map can go: MapLibre draws the world smaller than
// its tiles, while Leaflet has no tiles below zoom 0
const LOWEST_VECTOR_ZOOM = -2;
const LOWEST_TILE_ZOOM = 0;
// The vector map's tiles, so its world is this many pixels tall at zoom 0
const VECTOR_TILE_SIZE = 512;
// The top and bottom of the world, as far as the map draws it
const WORLD_BOUNDS = [[-85, -Infinity], [85, Infinity]];

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

/* Pins */

// Their local time in a pin's label moves on while it's open
const PIN_TIME_INTERVAL = 30000;

// Shown when hovering a pin, like a hello on the login map: their face
// beside the name, and under it the flag and city, then their local time
// with a sun or moon
function createPinLabel(personLocation) {
    const label = createElement('div', 'pin-label');

    if (personLocation.avatar_src) {
        const face = createElement('img', 'face');
        face.src = personLocation.avatar_src;
        face.alt = '';
        face.width = 32;
        face.height = 32;
        // A pin means they're online, so the face has the list's green dot
        const avatar = createElement('span', 'avatar');
        avatar.append(face);
        label.append(avatar);
    }

    const details = createElement('div', 'details');
    if (personLocation.city || personLocation.country) {
        details.append(createPlace(personLocation.city, personLocation.country));
    }

    const time = createElement('span', 'time');
    time.dataset.part = 'pin-time';
    time.dataset.timezone = personLocation.timezone ?? '';
    time.dataset.lng = personLocation.lng;
    showPinTime(time);
    details.append(time);

    const text = createElement('div', 'text');
    text.append(createElement('strong', 'name', personLocation.shown_name ?? ''), details);
    label.append(text);

    return label;
}

// Without their time zone, localTimeOf (dom.js) estimates it from the longitude
function showPinTime(time) {
    showLocalTime(time, localTimeOf(new Date(), time.dataset.timezone, parseFloat(time.dataset.lng)));
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
    pin.innerHTML = pinMarkup();
    return pin;
}

// Fades a pin out, then takes it off the map
function fadeOutPin(pin, removePin) {
    pin.classList.add('is-leaving');
    pin.addEventListener('animationend', removePin, {once: true});
}

const lngLatOf = (personLocation) => [parseFloat(personLocation.lng), parseFloat(personLocation.lat)];
const latLngOf = (personLocation) => [parseFloat(personLocation.lat), parseFloat(personLocation.lng)];

/* Fitting the map */

// The part of the map you can see: on phones, the room the chat sheet
// leaves above it (chat.js)
function visibleMapHeight() {
    if (phoneScreen.matches) return sheetTopOf(sheetStop);
    return document.getElementById('map').clientHeight;
}

// The furthest out you can zoom: the world (a square tileSize pixels tall at
// zoom 0) must be at least as tall as the part of the map you can see, so it
// never ends mid-screen. Never further out than lowestZoom
function minZoomToFill(tileSize, lowestZoom) {
    return Math.max(lowestZoom, Math.log2(visibleMapHeight() / tileSize));
}

// Keeps that furthest zoom right as the window or the chat sheet moves
function keepMinZoomFilled(map, tileSize, lowestZoom) {
    const fillVisibleMap = () => map.setMinZoom(minZoomToFill(tileSize, lowestZoom));
    window.addEventListener('resize', fillVisibleMap);
    document.addEventListener('sheet-moved', fillVisibleMap);
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
    const response = await axios.get('/people/pins');
    return response.data;
}

// Everything a pin shows; when it changes, the pin is drawn again
const pinContentOf = (personLocation) => [personLocation.lat, personLocation.lng, personLocation.shown_name, personLocation.avatar_src, personLocation.city, personLocation.country, personLocation.timezone].join('|');

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
// it. Pins after the first round ease in. addPin(personLocation, shouldAnimate)
// returns a function that removes the pin
function keepPinsUpdated(addPin, fitPins) {
    const pinsByUserId = new Map();
    let isFirstRound = true;

    function updatePins(locations) {
        const shownUserIds = new Set();

        for (const personLocation of locations) {
            const content = pinContentOf(personLocation);
            const pin = pinsByUserId.get(personLocation.id);
            shownUserIds.add(personLocation.id);

            if (pin?.content === content) continue;
            pin?.removePin();
            pinsByUserId.set(personLocation.id, {content, removePin: addPin(personLocation, !isFirstRound)});
        }

        // Deleting the entry being visited is safe in a Map's for…of
        for (const [userId, pin] of pinsByUserId) {
            if (shownUserIds.has(userId)) continue;
            pin.removePin();
            pinsByUserId.delete(userId);
        }
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
        if (!isSocketLive) pins.nextRefreshTimer = setTimeout(refreshPins, PINS_INTERVAL);
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

// Whose pin is pointed out from the people list, kept in step by setPinLabelShown
const highlightedPinUserIds = new Set();

const getLabelOffset = (pin) => (highlightedPinUserIds.has(pin.dataset.userId) ? HIGHLIGHTED_PIN_LABEL_OFFSET : PIN_LABEL_OFFSET);

function setPinLabelShown(userId, isShown) {
    if (isShown) {
        highlightedPinUserIds.add(String(userId));
    } else {
        highlightedPinUserIds.delete(String(userId));
    }
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
function addVectorPin(map, personLocation, shouldAnimate) {
    const lngLat = lngLatOf(personLocation);
    const label = createPinLabel(personLocation);
    const popupTime = label.querySelector('[data-part="pin-time"]');
    const popup = new maplibregl.Popup({closeButton: false, offset: vectorLabelOffsetsOf(PIN_LABEL_OFFSET)})
        .setDOMContent(label);
    const marker = new maplibregl.Marker({element: createPinElement(personLocation.id), anchor: 'bottom'})
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

    pinLabelsByUserId.set(String(personLocation.id), {
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
        pinLabelsByUserId.delete(String(personLocation.id));
        popup.remove();
        fadeOutPin(pin, () => marker.remove());
    };
}

function fitVectorPins(map, locations) {
    const bounds = new maplibregl.LngLatBounds();
    for (const personLocation of locations) {
        bounds.extend(lngLatOf(personLocation));
    }

    const padding = getMapPadding();
    if (locations.length === 1) {
        map.jumpTo({center: bounds.getCenter(), zoom: MAX_PIN_ZOOM, padding});
    } else if (locations.length > 1) {
        map.fitBounds(bounds, {padding, maxZoom: MAX_PIN_ZOOM, animate: false});
    }
}

// The latitudes MapLibre may let the screen's top and bottom reach at a zoom,
// so the world's edge stops at the middle of the part you can see: pixels
// past each edge, so a different latitude at each zoom. MapLibre takes the
// screen as centred on the map's centre, which padding moves (centreOffsetY,
// up from the screen's middle), so the limits move by as much
function vectorDragLimitsAt(zoom, mapHeight, heightUnderChat, centreOffsetY) {
    const worldHeight = VECTOR_TILE_SIZE * 2 ** zoom;
    const visibleMiddleY = (mapHeight - heightUnderChat) / 2;
    const heightBelowVisibleMiddle = mapHeight - visibleMiddleY;
    const [south] = WORLD_BOUNDS[0];
    const [north] = WORLD_BOUNDS[1];
    const northOverhang = (visibleMiddleY - centreOffsetY) / worldHeight;
    const southOverhang = (heightBelowVisibleMiddle + centreOffsetY) / worldHeight;
    const northLimitY = maplibregl.MercatorCoordinate.fromLngLat([0, north]).y - northOverhang;
    const southLimitY = maplibregl.MercatorCoordinate.fromLngLat([0, south]).y + southOverhang;
    const northLimit = new maplibregl.MercatorCoordinate(0, northLimitY).toLngLat().lat;
    const southLimit = new maplibregl.MercatorCoordinate(0, southLimitY).toLngLat().lat;
    return [southLimit, northLimit];
}

// Dragging goes up and down until the world's edge reaches the middle of the
// part you can see, so any place comes out from behind the chat
function boundVectorMapToWorld(map) {
    // Set by hand, MapLibre's bounds would be a zoom behind while zooming
    // (zooming out at the edge would creep in) and a size behind while
    // resizing. Its own check, MapLibre 4's private getConstrained, is handed
    // the zoom and size it's about to use, so the bounds are worked out there
    const transform = map.transform;
    const constrainWithinBounds = transform.getConstrained.bind(transform);
    let heightUnderChat = 0;
    transform.getConstrained = (center, zoom) => {
        const zoomInRange = Math.min(Math.max(zoom, transform.minZoom), transform.maxZoom);
        transform.latRange = vectorDragLimitsAt(zoomInRange, transform.height, heightUnderChat, transform.centerOffset.y);
        return constrainWithinBounds(center, zoom);
    };

    // The chat sheet covers the map's bottom on phones; setMaxBounds checks
    // the map against the new bounds at once
    const measureChat = () => {
        heightUnderChat = map.getContainer().clientHeight - visibleMapHeight();
        const [south, north] = vectorDragLimitsAt(map.getZoom(), transform.height, heightUnderChat, transform.centerOffset.y);
        map.setMaxBounds([[-Infinity, south], [Infinity, north]]);
    };
    measureChat();
    document.addEventListener('sheet-moved', measureChat);
    window.addEventListener('resize', measureChat);
    phoneScreen.addEventListener('change', measureChat);
}

// Puts the map's centre in the middle of the part you can see, without moving
// the map: dragging brings any place to the centre but no further
function centreMapBesideChat(map) {
    const padding = getMapPadding();
    const currentPadding = map.getPadding();
    const isPaddingSame = ['top', 'bottom', 'left', 'right'].every((side) => currentPadding[side] === padding[side]);
    if (isPaddingSame) return;
    // Jumping would stop a move under way, like focusing a pin
    if (map.isMoving()) {
        map.once('moveend', () => centreMapBesideChat(map));
        return;
    }

    const container = map.getContainer();
    const clearWidth = container.clientWidth - padding.left - padding.right;
    const clearHeight = container.clientHeight - padding.top - padding.bottom;
    const clearMiddle = [padding.left + clearWidth / 2, padding.top + clearHeight / 2];
    map.jumpTo({center: map.unproject(clearMiddle), padding});
}

// Credits as a small (i) beside the people list (see chat-map.css)
function addVectorCredits(map) {
    map.addControl(new maplibregl.AttributionControl({compact: true}), 'bottom-right');
    // No tooltip on the (i): it explains itself. The aria-label stays for screen readers.
    // MapLibre makes the credits' markup, so its own classes are the only way to find them
    map.getContainer().querySelector('.maplibregl-ctrl-attrib-button').removeAttribute('title');

    // MapLibre opens them at first; close them once the map is drawn
    map.once('load', () => {
        const credits = map.getContainer().querySelector('.maplibregl-ctrl-attrib');
        credits.classList.remove('maplibregl-compact-show');
        credits.removeAttribute('open');
    });
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
        minZoom: minZoomToFill(VECTOR_TILE_SIZE, LOWEST_VECTOR_ZOOM),
        attributionControl: false,
    });
    vectorMap = map;
    addVectorCredits(map);
    keepMinZoomFilled(map, VECTOR_TILE_SIZE, LOWEST_VECTOR_ZOOM);
    boundVectorMapToWorld(map);
    const centreBesideChat = () => centreMapBesideChat(map);
    centreBesideChat();
    document.addEventListener('sheet-moved', centreBesideChat);
    window.addEventListener('resize', centreBesideChat);

    map.on('load', () => {
        addNightShade(map);
        keepPinsUpdated(
            (personLocation, shouldAnimate) => addVectorPin(map, personLocation, shouldAnimate),
            (locations) => fitVectorPins(map, locations),
        );
    });
}

/* The tile map (Leaflet), for browsers without WebGL */

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
// sends it back. It runs this file's themeTile and map-style.js's hexToRgb,
// from their source, so there's one copy
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
            tile.onload = async () => {
                if (worker) {
                    const id = nextTileId++;
                    waitingTiles.set(id, {canvas, done});
                    try {
                        const bitmap = await createImageBitmap(tile);
                        worker.postMessage({id, tile: bitmap}, [bitmap]);
                    } catch (error) {
                        waitingTiles.delete(id);
                        done(error, canvas);
                    }
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

// Credits beside the people list (see chat-map.css); the tiles add theirs to it.
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
function addTilePin(map, personLocation, shouldAnimate) {
    const pin = createPinElement(personLocation.id);
    pin.classList.toggle('is-entering', shouldAnimate);
    const icon = L.divIcon({className: '', html: pin.outerHTML, iconSize: [20, PIN_HEIGHT], iconAnchor: [10, PIN_HEIGHT]});
    const marker = L.marker(latLngOf(personLocation), {icon})
        // Made each time it opens, so the time is now's
        .bindTooltip(() => createPinLabel(personLocation), {direction: 'top', offset: [0, -PIN_LABEL_OFFSET]})
        .addTo(map);

    const setTooltipOffset = (offset) => {
        marker.getTooltip().options.offset = L.point(0, -offset);
    };
    const showTooltip = (offset) => {
        setTooltipOffset(offset);
        marker.openTooltip();
    };
    pinLabelsByUserId.set(String(personLocation.id), {
        show: showTooltip,
        // Leaflet's own flight, which keeps its tiles in step; it slows into
        // place but doesn't ease off. Driving it frame by frame for that
        // left tiles unloaded or drawn off to the side. A bounds of one point,
        // as only fitting bounds takes the map's padding here
        focus: () => {
            const latLng = latLngOf(personLocation);
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
        pinLabelsByUserId.delete(String(personLocation.id));
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

// Leaflet's usual map, but places past the world's top or bottom edge are
// drawn there, not on the edge, so the drag bounds can reach past it. Any
// closer to the pole than about two worlds past the edge rounds to infinity
function mapCrsPastWorldEdge() {
    const projection = {...L.Projection.SphericalMercator, MAX_LATITUDE: 89.99999};
    return {...L.CRS.EPSG3857, projection};
}

// Dragging goes up and down until the world's edge reaches the middle of the
// part you can see, so any place comes out from behind the chat. The bounds
// are pixels past each edge, so a different latitude at each zoom
function boundTileMapToWorld(map) {
    const visibleMiddleY = visibleMapHeight() / 2;
    const heightBelowVisibleMiddle = document.getElementById('map').clientHeight - visibleMiddleY;
    const zoom = map.getZoom();
    const [south, west] = WORLD_BOUNDS[0];
    const [north, east] = WORLD_BOUNDS[1];
    const northLimitPoint = map.project([north, 0], zoom).subtract([0, visibleMiddleY]);
    const southLimitPoint = map.project([south, 0], zoom).add([0, heightBelowVisibleMiddle]);
    const northLimit = map.unproject(northLimitPoint, zoom).lat;
    const southLimit = map.unproject(southLimitPoint, zoom).lat;
    map.setMaxBounds([[southLimit, west], [northLimit, east]]);
}

async function initializeTileMap() {
    loadAsset('link', {rel: 'stylesheet', href: `${LEAFLET_URL}leaflet.css`});
    await loadAsset('script', {src: `${LEAFLET_URL}leaflet.js`});

    const map = L.map('map', {
        crs: mapCrsPastWorldEdge(),
        zoomControl: false,
        attributionControl: false,
        worldCopyJump: true,
        zoomSnap: 0.25,
        minZoom: minZoomToFill(256, LOWEST_TILE_ZOOM),
        maxBoundsViscosity: 1,
    }).setView([START.lat, START.lng], START.zoom);

    keepMinZoomFilled(map, 256, LOWEST_TILE_ZOOM);
    const boundToWorld = () => boundTileMapToWorld(map);
    boundToWorld();
    map.on('zoomend', boundToWorld);
    document.addEventListener('sheet-moved', boundToWorld);
    window.addEventListener('resize', boundToWorld);
    phoneScreen.addEventListener('change', boundToWorld);
    addTileCredits(map);
    createThemedTileLayer().addTo(map);

    keepPinsUpdated(
        (personLocation, shouldAnimate) => addTilePin(map, personLocation, shouldAnimate),
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

// Wider screens always show the map; phones unless the chat sheet is all
// the way up (chat.js). The map stays laid out under the chat, so isShown can't tell
function isMapOpen() {
    return !phoneScreen.matches || sheetStop !== 'full';
}

// Phones with the chat all the way up don't load the map until the sheet
// comes down, so the chat gets the connection to itself. Once started, it stays
function initializeMapWhenOpen() {
    function startIfOpen() {
        if (!isMapOpen()) return;
        document.removeEventListener('sheet-moved', startIfOpen);
        phoneScreen.removeEventListener('change', startIfOpen);
        initializeMap();
    }

    document.addEventListener('sheet-moved', startIfOpen);
    // Turning a phone or widening the window past the breakpoint shows it too
    phoneScreen.addEventListener('change', startIfOpen);
    startIfOpen();
}

// The script loads at the end of the page, so the map's box is already there
initializeMapWhenOpen();
