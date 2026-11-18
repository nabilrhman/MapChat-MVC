// The world map behind the login, sign-up and password pages: MapChat's
// map in dark purples with no names, zoomed out to the whole world as an atlas
// lays it out, with night's wave sliding across it, a day in a minute and a
// half. Asking for less motion keeps the wave where it really is.
// Without WebGL, or if the map can't load, the page keeps its plain colour

// The Americas on the left and Asia on the right, with Europe and Africa
// in the middle, a little north of the equator, where most of the land is
const LOGIN_MAP_CENTER = {lng: 10, lat: 15};
// The whole world at zoom 0 is this many pixels across
const WORLD_SIZE_AT_ZOOM_0 = 512;
// The furthest out MapLibre draws, for a screen narrower than that
const LOWEST_LOGIN_MAP_ZOOM = -2;
// A little taller than the screen, as the map sits north of the equator.
// login.css sizes the picture shown before the map by the same 1.1
const LOGIN_MAP_HEIGHT_MARGIN = 1.1;
// One day of night going round the world, in the animated wave
const DAY_ANIMATION_SECONDS = 90;
// The login map is the chat's map gone dark, in the deep purples of the
// old street-map picture the login pages had
const DARK_MAP = {
    water: '#1e0839',
    land: '#331467',
    border: '#5b3aa6',
    road: '#45208a',
};
// Night on the dark map: near black, and stronger, so it still shows
const DARK_NIGHT_LOOK = {colour: '#0a0219', opacity: 0.55};
// The dark map's colour for each layer, by id or kind; the first match wins
const DARK_LAYER_COLOURS = [
    {isLayer: ({id}) => id === 'background', paint: {'background-color': DARK_MAP.land}},
    {isLayer: ({id}) => id === 'water', paint: {'fill-color': DARK_MAP.water}},
    {isLayer: ({id}) => id === 'waterway', paint: {'line-color': DARK_MAP.water}},
    {isLayer: ({id}) => /^boundary/.test(id), paint: {'line-color': DARK_MAP.border}},
    {isLayer: ({type}) => type === 'fill', paint: {'fill-color': DARK_MAP.land, 'fill-outline-color': DARK_MAP.land}},
    {isLayer: ({type}) => type === 'line', paint: {'line-color': DARK_MAP.road}},
];
// What a pin says, by language: short hellos, picked at random
const GREETINGS_BY_LANGUAGE = {
    'en': ['Hello!', 'Hi, all', 'Hey there', "How's it going?", 'Morning!'],
    'en-AU': ["G'day!", 'Hiya!', 'How ya going?'],
    'en-SG': ['Hello lah!', 'Hi, all', 'Makan already?'],
    'es': ['¡Hola!', '¿Qué tal?', '¡Buenas!', '¿Cómo están?'],
    'pt-BR': ['Olá!', 'Oi, gente!', 'Tudo bem?'],
    'zh': ['你好', '大家好', '你好吗?'],
    'ko': ['안녕하세요', '반가워요', '잘 지내요?'],
    'ja': ['こんにちは', 'はじめまして', '元気?'],
    'hi': ['नमस्ते', 'कैसे हो?', 'सब ठीक?'],
    'bn': ['হ্যালো', 'কেমন আছো?', 'কী খবর?'],
    'th': ['สวัสดี', 'สบายดีไหม?'],
    'fil': ['Kumusta!', 'Magandang araw!'],
    'id': ['Halo!', 'Apa kabar?', 'Selamat pagi!'],
    'mi': ['Kia ora!', 'Tēnā koutou'],
};
// Big cities where a pin pops up and says hello in the language spoken
// there, to show what the map is for. Nobody's real place or words. Each
// belongs to a zone: four down each side of the form, as Europe and Africa
// sit behind it
const CHATTING_PLACES = [
    {zone: 'americas-west', lng: -123.1, lat: 49.3, language: 'en'},
    {zone: 'americas-west', lng: -122.4, lat: 37.8, language: 'en'},
    {zone: 'americas-west', lng: -118.2, lat: 34.05, language: 'en'},
    {zone: 'americas-east', lng: -87.6, lat: 41.9, language: 'en'},
    {zone: 'americas-east', lng: -79.4, lat: 43.7, language: 'en'},
    {zone: 'americas-east', lng: -74.0, lat: 40.7, language: 'en'},
    {zone: 'americas-middle', lng: -99.1, lat: 19.4, language: 'es'},
    {zone: 'americas-middle', lng: -82.4, lat: 23.1, language: 'es'},
    {zone: 'americas-middle', lng: -79.5, lat: 9.0, language: 'es'},
    {zone: 'americas-south', lng: -77.0, lat: -12.0, language: 'es'},
    {zone: 'americas-south', lng: -70.7, lat: -33.4, language: 'es'},
    {zone: 'americas-south', lng: -58.4, lat: -34.6, language: 'es'},
    {zone: 'americas-south', lng: -46.6, lat: -23.5, language: 'pt-BR'},
    {zone: 'asia-north', lng: 116.4, lat: 39.9, language: 'zh'},
    {zone: 'asia-north', lng: 127.0, lat: 37.6, language: 'ko'},
    {zone: 'asia-north', lng: 139.7, lat: 35.7, language: 'ja'},
    {zone: 'asia-south', lng: 77.2, lat: 28.6, language: 'hi'},
    {zone: 'asia-south', lng: 90.4, lat: 23.8, language: 'bn'},
    {zone: 'asia-south', lng: 100.5, lat: 13.75, language: 'th'},
    {zone: 'asia-islands', lng: 121.0, lat: 14.6, language: 'fil'},
    {zone: 'asia-islands', lng: 103.8, lat: 1.35, language: 'en-SG'},
    {zone: 'asia-islands', lng: 106.8, lat: -6.2, language: 'id'},
    {zone: 'oceania', lng: 115.9, lat: -31.95, language: 'en-AU'},
    {zone: 'oceania', lng: 151.2, lat: -33.9, language: 'en-AU'},
    {zone: 'oceania', lng: 174.8, lat: -36.8, language: 'mi'},
];
// Each side's zones, in the order a wider screen adds them: top and bottom
// first, so even two pins a side look balanced
const ZONES_BY_SIDE = {
    left: ['americas-west', 'americas-south', 'americas-middle', 'americas-east'],
    right: ['asia-north', 'oceania', 'asia-south', 'asia-islands'],
};
// How many pins the map keeps, by the screen's width in px. Always odd, so
// the two sides never mirror each other exactly
const PIN_COUNT_BY_WIDTH = [
    {narrowerThan: 1000, pinCount: 3},
    {narrowerThan: 1400, pinCount: 5},
    {narrowerThan: Infinity, pinCount: 7},
];
// How the pins change: one zone's pin leaves, and a new one in the same
// zone arrives and says hello. The next comes only once that bubble is gone
const PIN_CHAT_TIMING = {
    helloDelayMs: 400,
    bubbleShownMs: 4000,
    betweenHellosMs: 1000,
    fadeOutMs: 300, // a little over login.css's bubble fade, --dur-fast
    hiddenTabCheckMs: 1000,
};
// Where a pin may stand, in px, so it and its bubble stay clear of the form,
// the screen's edges and each other
const PIN_ROOM = {
    formHalfWidth: 200, // half the card's max-width in login.css
    formGap: 32,
    bubbleHalfWidth: 64,
    bubbleAndPinHeight: 80,
    screenGap: 12,
    betweenPins: 140,
};

// The zoom where the world fills the screen across, and top to bottom
function worldFillingZoomOf(container) {
    const neededSize = Math.max(container.clientWidth, container.clientHeight * LOGIN_MAP_HEIGHT_MARGIN);
    return Math.log2(neededSize / WORLD_SIZE_AT_ZOOM_0);
}

// Just the shapes: the place names and road numbers would crowd the form
async function loadNamelessStyle() {
    const style = await loadThemedStyle();
    style.layers = style.layers.filter((layer) => layer.type !== 'symbol');
    return style;
}

function darkenLayer(layer) {
    const layerColours = DARK_LAYER_COLOURS.find(({isLayer}) => isLayer(layer));
    if (layerColours) Object.assign(layer.paint, layerColours.paint);
}

const waitFor = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

function createLoginPin() {
    const pin = createElement('div', 'login-pin');
    pin.innerHTML = pinMarkup();
    return pin;
}

// A pin on the map, growing in, with its hello bubble hidden for now
function addChattingPin(map, place) {
    const pin = createLoginPin();
    const greetings = GREETINGS_BY_LANGUAGE[place.language];
    const bubble = createElement('span', 'bubble', greetings[Math.floor(Math.random() * greetings.length)]);
    // So the browser picks the right font for its script
    bubble.lang = place.language;
    pin.append(bubble);
    const marker = new maplibregl.Marker({element: pin, anchor: 'bottom'}).setLngLat(place).addTo(map);
    return {place, pin, bubble, marker};
}

// Shows the pin's bubble for a while; done once it's hidden again
async function sayHello({bubble}) {
    bubble.classList.add('is-shown');
    await waitFor(PIN_CHAT_TIMING.bubbleShownMs);
    bubble.classList.remove('is-shown');
    // Till its fade out ends, so the next hello starts on an empty map. A
    // fade that never ends, as in a tab out of sight, doesn't hold it up
    await Promise.race([
        new Promise((resolve) => onTransitionEnd(bubble, resolve)),
        waitFor(PIN_CHAT_TIMING.fadeOutMs),
    ]);
}

function removeChattingPin({pin, marker}) {
    pin.classList.add('is-leaving');
    // The pin's shape shrinking out is the only animation left to end
    onAnimationEnd(pin, () => marker.remove());
}

// Which side of the form a place shows on, or null when its pin or bubble
// would sit under the form or off the screen
function pinSideOf(map, place) {
    const {x, y} = map.project(place);
    const {clientWidth, clientHeight} = map.getCanvas();
    const formLeft = clientWidth / 2 - PIN_ROOM.formHalfWidth - PIN_ROOM.formGap;
    const formRight = clientWidth / 2 + PIN_ROOM.formHalfWidth + PIN_ROOM.formGap;
    const isOnScreen = x - PIN_ROOM.bubbleHalfWidth > PIN_ROOM.screenGap
        && x + PIN_ROOM.bubbleHalfWidth < clientWidth - PIN_ROOM.screenGap
        && y - PIN_ROOM.bubbleAndPinHeight > PIN_ROOM.screenGap
        && y < clientHeight - PIN_ROOM.screenGap;

    if (!isOnScreen) return null;
    if (x + PIN_ROOM.bubbleHalfWidth < formLeft) return 'left';
    if (x - PIN_ROOM.bubbleHalfWidth > formRight) return 'right';
    return null;
}

function isFarFromPins(map, place, placesShown) {
    const point = map.project(place);
    return [...placesShown].every((shownPlace) => point.dist(map.project(shownPlace)) > PIN_ROOM.betweenPins);
}

// The next place for a zone's pin: one with room on screen, clear of the
// other pins, and the one that waited longest
function nextPlaceInZoneOf(map, zone, pinsStaying, lastShownTimes) {
    const placesShown = new Set(pinsStaying.map(({place}) => place));
    const freePlaces = CHATTING_PLACES.filter((place) => place.zone === zone
        && !placesShown.has(place)
        && pinSideOf(map, place)
        && isFarFromPins(map, place, placesShown));
    freePlaces.sort((first, second) => (lastShownTimes.get(first) ?? 0) - (lastShownTimes.get(second) ?? 0));
    return freePlaces[0] ?? null;
}

const hasRoomOnScreen = (map, zone) => CHATTING_PLACES.some((place) => place.zone === zone && pinSideOf(map, place));

function pinCountOf(map) {
    const {clientWidth} = map.getCanvas();
    return PIN_COUNT_BY_WIDTH.find(({narrowerThan}) => clientWidth < narrowerThan).pinCount;
}

// The side that gets the odd pin, picked fresh on each visit
const firstSide = Math.random() < 0.5 ? 'left' : 'right';
const secondSide = firstSide === 'left' ? 'right' : 'left';

// The zones with room at this width, one side then the other in turn, so
// the first side gets the odd pin. The pins go in the first ones that have
// a city clear of the others
function zonesInOrderOf(map) {
    const firstZones = ZONES_BY_SIDE[firstSide].filter((zone) => hasRoomOnScreen(map, zone));
    const secondZones = ZONES_BY_SIDE[secondSide].filter((zone) => hasRoomOnScreen(map, zone));

    const zones = [];
    for (let index = 0; index < Math.max(firstZones.length, secondZones.length); index++) {
        if (firstZones[index]) zones.push(firstZones[index]);
        if (secondZones[index]) zones.push(secondZones[index]);
    }
    return zones;
}

// Keeps an odd number of pins on the map, by the screen's width, one per
// zone, so they sit evenly round the form. Every step one changes for a
// new one in its zone that says hello. A screen with no room beside the
// form (a phone) shows none
async function keepPinsChatting(map) {
    const pinsByZone = new Map();
    const lastShownTimes = new Map();
    const pinsBesides = (zone) => [...pinsByZone].filter(([pinZone]) => pinZone !== zone).map(([, chattingPin]) => chattingPin);

    function changePinIn(zone) {
        const leavingPin = pinsByZone.get(zone);
        // Counts as just shown, so it isn't the one picked to arrive
        if (leavingPin) lastShownTimes.set(leavingPin.place, performance.now());
        const place = nextPlaceInZoneOf(map, zone, pinsBesides(zone), lastShownTimes);
        if (!place) return null;

        lastShownTimes.set(place, performance.now());
        const arrivingPin = addChattingPin(map, place);
        pinsByZone.set(zone, arrivingPin);
        if (leavingPin) removeChattingPin(leavingPin);
        return arrivingPin;
    }

    function clearZone(zone) {
        removeChattingPin(pinsByZone.get(zone));
        pinsByZone.delete(zone);
    }

    // Brings the pins to the count for this width: empty zones in order get
    // one till there are enough, and the last ones in order go if too many
    function keepPinCount() {
        const zonesInOrder = zonesInOrderOf(map);
        const pinCount = pinCountOf(map);
        for (const zone of [...pinsByZone.keys()].filter((zone) => !zonesInOrder.includes(zone))) clearZone(zone);

        for (const zone of zonesInOrder) {
            if (pinsByZone.size >= pinCount) break;
            if (!pinsByZone.has(zone)) changePinIn(zone);
        }
        const zonesFromLast = zonesInOrder.toReversed().filter((zone) => pinsByZone.has(zone));
        while (pinsByZone.size > pinCount) clearZone(zonesFromLast.shift());
    }

    keepPinCount();
    // One of the first pins says hello as soon as they're in, so the map
    // greets you straight away
    const firstPins = [...pinsByZone.values()];
    if (firstPins.length > 0) {
        await waitFor(PIN_CHAT_TIMING.helloDelayMs);
        await sayHello(firstPins[Math.floor(Math.random() * firstPins.length)]);
    }

    // Then one hello at a time: each new pin speaks only once the last
    // bubble has gone, so two never show together
    let stepCount = 0;
    while (true) {
        await waitFor(PIN_CHAT_TIMING.betweenHellosMs);
        while (document.hidden) await waitFor(PIN_CHAT_TIMING.hiddenTabCheckMs);
        keepPinCount();

        const zonesWithPins = zonesInOrderOf(map).filter((zone) => pinsByZone.has(zone));
        const zone = zonesWithPins[stepCount % zonesWithPins.length];
        stepCount++;
        const arrivingPin = zone ? changePinIn(zone) : null;
        if (!arrivingPin) continue;

        await waitFor(PIN_CHAT_TIMING.helloDelayMs);
        await sayHello(arrivingPin);
    }
}

// With less motion asked for, the pins just stand there, as many as the
// moving ones and placed the same way
function addStillPins(map) {
    const stillPins = [];
    for (const zone of zonesInOrderOf(map)) {
        if (stillPins.length >= pinCountOf(map)) break;
        const place = nextPlaceInZoneOf(map, zone, stillPins, new Map());
        if (!place) continue;
        stillPins.push({place});
        new maplibregl.Marker({element: createLoginPin(), anchor: 'bottom'}).setLngLat(place).addTo(map);
    }
}

function fitTheWorld(map) {
    map.jumpTo({center: LOGIN_MAP_CENTER, zoom: worldFillingZoomOf(map.getContainer())});
}

// The shade for now, drawn twice side by side, so a window one world wide
// can slide along it for a whole day
async function drawDoubleNightShade(look) {
    const shade = new Image();
    shade.src = drawNightShade(new Date(), look);
    await shade.decode();

    const canvas = document.createElement('canvas');
    canvas.width = shade.width * 2;
    canvas.height = shade.height;
    const context = canvas.getContext('2d');
    context.drawImage(shade, 0, 0);
    context.drawImage(shade, shade.width, 0);
    return canvas;
}

// Draws one frame of the sliding night over the map: the window of the
// double shade for this moment, stretched over the world where the map
// shows it, and over the world's copies either side
function drawNightFrame(map, nightCanvas, doubleShade, turnedPart) {
    const pixelRatio = window.devicePixelRatio;
    const {clientWidth, clientHeight} = map.getCanvas();
    if (nightCanvas.width !== clientWidth * pixelRatio || nightCanvas.height !== clientHeight * pixelRatio) {
        nightCanvas.width = clientWidth * pixelRatio;
        nightCanvas.height = clientHeight * pixelRatio;
    }

    const context = nightCanvas.getContext('2d');
    context.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
    context.clearRect(0, 0, clientWidth, clientHeight);

    const northWest = map.project([-180, MERCATOR_MAX_LAT]);
    const southEast = map.project([180, -MERCATOR_MAX_LAT]);
    const worldWidthOnScreen = southEast.x - northWest.x;
    const shadeWidth = doubleShade.width / 2;
    // The sun going west means each place gets the night from east of it
    const windowStart = turnedPart * shadeWidth;

    for (const worldCopy of [-1, 0, 1]) {
        context.drawImage(
            doubleShade, windowStart, 0, shadeWidth, doubleShade.height,
            northWest.x + worldCopy * worldWidthOnScreen, northWest.y, worldWidthOnScreen, southEast.y - northWest.y,
        );
    }
}

// Night as it is now, then sliding west as the sun does, round and round.
// Drawn on a canvas of its own over the map, under the pins: MapLibre can't
// move an image past the edge of the world, and its canvas source loses the
// shade's see-through parts. Each frame copies a window of the shade drawn
// once, as working it out again every frame would be too slow
async function addMovingNightShade(map, look) {
    const doubleShade = await drawDoubleNightShade(look);
    const nightCanvas = createElement('canvas', 'night-shade');
    map.getCanvas().after(nightCanvas);

    const startTime = performance.now();
    function slideNight(now) {
        const turnedPart = ((now - startTime) / 1000 / DAY_ANIMATION_SECONDS) % 1;
        drawNightFrame(map, nightCanvas, doubleShade, turnedPart);
        requestAnimationFrame(slideNight);
    }
    requestAnimationFrame(slideNight);
}

async function showLoginMap() {
    const container = document.getElementById('login-map');
    if (!container) return;
    if (!isWebGLSupported()) return;

    const [style] = await Promise.all([
        loadNamelessStyle(),
        loadAsset('script', {src: `${MAPLIBRE_URL}maplibre-gl.js`}),
        loadAsset('link', {rel: 'stylesheet', href: `${MAPLIBRE_URL}maplibre-gl.css`}),
    ]);
    style.layers.forEach(darkenLayer);

    // MapLibre makes its box position: relative, so it draws in one inside the fixed backdrop
    const mapBox = createElement('div', 'map-box');
    container.append(mapBox);
    const map = new maplibregl.Map({
        container: mapBox,
        style,
        center: LOGIN_MAP_CENTER,
        zoom: worldFillingZoomOf(container),
        minZoom: LOWEST_LOGIN_MAP_ZOOM,
        interactive: false,
        attributionControl: false,
    });

    map.on('load', async () => {
        if (prefersLessMotion.matches) {
            addNightShade(map, DARK_NIGHT_LOOK);
            addStillPins(map);
        } else {
            await addMovingNightShade(map, DARK_NIGHT_LOOK);
            keepPinsChatting(map);
        }
        container.classList.add('is-shown');
    });
    map.on('resize', () => fitTheWorld(map));
}

// A map that can't load leaves the page's plain colour, which is fine as it is
showLoginMap().catch(() => {});
