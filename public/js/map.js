// Map of where everyone in the chat is.
//
// Drawn with MapLibre on OpenFreeMap's vector tiles (OpenStreetMap data, no
// API key). Their light "positron" style is recoloured below to match the
// lavender theme MapChat used on Google Maps: grey land, lavender water and
// parks, purple water labels, and yellow pins.

var STYLE_URL = 'https://tiles.openfreemap.org/styles/positron';

// The old Google Maps style, by map layer
var THEME = {
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
    pin: '#ffc107'
};

// Colour changes per layer id of the positron style
function themeLayer(layer) {
    var id = layer.id;
    var paint = layer.paint || (layer.paint = {});

    if (id === 'background') paint['background-color'] = THEME.land;
    else if (id === 'landuse_residential') paint['fill-color'] = THEME.land;
    else if (id === 'park' || id === 'landcover_wood') paint['fill-color'] = THEME.park;
    else if (id === 'water') paint['fill-color'] = THEME.water;
    else if (id === 'waterway') paint['line-color'] = THEME.water;
    else if (id === 'building') {
        paint['fill-color'] = THEME.building;
        paint['fill-outline-color'] = THEME.building;
    }
    else if (/^(tunnel_|highway_)?motorway.*casing$|^highway_motorway_bridge_casing$/.test(id)) paint['line-color'] = THEME.highwayCasing;
    else if (/motorway_(inner|subtle)$/.test(id)) paint['line-color'] = THEME.highway;
    else if (/^highway_major_casing$/.test(id)) paint['line-color'] = THEME.roadCasing;
    else if (/^highway_(major_inner|major_subtle|minor|path)$/.test(id)) paint['line-color'] = THEME.road;
    else if (/^railway/.test(id) && !/dashline$/.test(id)) paint['line-color'] = THEME.rail;
    else if (/^boundary/.test(id)) paint['line-color'] = THEME.boundary;
    else if (layer.type === 'symbol' && layer.layout && layer.layout['text-field']) {
        var color = THEME.text;
        if (/^water/.test(id)) color = THEME.waterText;
        else if (id === 'highway-name-major') color = THEME.roadText;
        else if (/^highway-name/.test(id)) color = THEME.minorRoadText;

        paint['text-color'] = color;
        paint['text-halo-color'] = THEME.textHalo;
    }
}

// A yellow map pin like the original marker icon
// Shown when hovering a pin: the name, then the flag and city
function pinLabel(location) {
    var label = document.createElement('div');
    label.className = 'pin-label';

    var name = document.createElement('strong');
    name.textContent = location.name || '';
    label.appendChild(name);

    if (location.city) {
        var place = document.createElement('span');
        if (location.country) {
            var flag = document.createElement('span');
            flag.className = 'fi fi-' + location.country.toLowerCase();
            place.appendChild(flag);
        }
        place.appendChild(document.createTextNode(location.city));
        label.appendChild(place);
    }

    return label;
}

// userId lets the people list highlight this pin
function pinElement(userId) {
    var el = document.createElement('div');
    el.className = 'map-pin';
    el.dataset.userId = userId;
    el.innerHTML =
        '<svg width="20" height="27" viewBox="0 0 28 38" aria-hidden="true">' +
        '<path d="M14 0C6.3 0 0 6.2 0 13.9 0 24.3 14 38 14 38s14-13.7 14-24.1C28 6.2 21.7 0 14 0z" fill="' + THEME.pin + '"/>' +
        '<circle cx="14" cy="14" r="5" fill="#ffffff"/>' +
        '</svg>';
    return el;
}

// The furthest out you can zoom: the world (a square tileSize pixels tall at
// zoom 0) must be at least as tall as the map, so it never ends mid-screen
function minZoomToFill(tileSize) {
    var height = document.getElementById('map').clientHeight;
    return Math.max(0, Math.log2(height / tileSize));
}

// Room around the pins when zooming to fit them, so no pin ends up behind the
// chat panel or the people list
function mapPadding() {
    var panel = document.querySelector('.chat-container').getBoundingClientRect();
    // Zero while the people list is a closed drawer
    var peopleWidth = document.querySelector('.people').getBoundingClientRect().width;

    // On phones the chat sits below the map instead of beside it
    if (panel.width >= window.innerWidth * 0.9) {
        return {top: 40, bottom: window.innerHeight - panel.top + 40, left: 40, right: 40};
    }
    return {top: 60, bottom: 60, left: panel.width + 60, right: peopleWidth + 60};
}

function addPins(map, locations) {
    var bounds = new maplibregl.LngLatBounds();

    locations.forEach(function (location) {
        var lngLat = [parseFloat(location.lng), parseFloat(location.lat)];
        var popup = new maplibregl.Popup({closeButton: false, offset: 26})
            .setDOMContent(pinLabel(location));
        var marker = new maplibregl.Marker({element: pinElement(location.user_id), anchor: 'bottom'})
            .setLngLat(lngLat)
            .addTo(map);

        var el = marker.getElement();
        el.addEventListener('mouseenter', function () { popup.setLngLat(lngLat).addTo(map); });
        el.addEventListener('mouseleave', function () { popup.remove(); });

        bounds.extend(lngLat);
    });

    var padding = mapPadding();
    if (locations.length === 1) {
        map.jumpTo({center: bounds.getCenter(), zoom: 9, padding: padding});
    } else if (locations.length > 1) {
        map.fitBounds(bounds, {padding: padding, maxZoom: 9, animate: false});
    }
}

function loadLocations(callback) {
    postForm('/app/get-locations').then(function (response) {
        callback(response.data);
    });
}

function supportsWebGL() {
    try {
        var canvas = document.createElement('canvas');
        return !!(canvas.getContext('webgl2') || canvas.getContext('webgl'));
    } catch (e) {
        return false;
    }
}

function loadAsset(tag, attrs) {
    return new Promise(function (resolve, reject) {
        var el = document.createElement(tag);
        Object.keys(attrs).forEach(function (key) { el[key] = attrs[key]; });
        el.onload = resolve;
        el.onerror = reject;
        document.head.appendChild(el);
    });
}

function hexToRgb(hex) {
    var n = parseInt(hex.slice(1), 16);
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
}

// Recolour a standard OpenStreetMap tile into the MapChat theme, pixel by
// pixel: blue water becomes lavender (its labels purple), green parks and
// woods a faint lavender tint, and everything else light grey.
function themeTile(imageData) {
    var water = hexToRgb(THEME.water);
    var waterText = hexToRgb(THEME.waterText);
    var park = [236, 231, 250];  // much paler than THEME.park: OSM has far more green
    var d = imageData.data;

    for (var i = 0; i < d.length; i += 4) {
        var r = d[i], g = d[i + 1], b = d[i + 2];
        var max = Math.max(r, g, b), min = Math.min(r, g, b);
        var light = (max + min) / 510;
        var colourful = max - min > 28;
        var out;

        if (colourful && b >= g && b > r + 20) {
            out = light > 0.55 ? water : waterText;            // water, water labels
        } else if (g - r > 25 && g > b && light > 0.55) {
            out = park;                                        // parks, woods
        } else {
            var grey = 0.299 * r + 0.587 * g + 0.114 * b;
            var soft = 255 - (255 - grey) * 0.75;              // lighter, softer grey
            out = [soft, soft, soft];
        }

        d[i] = out[0];
        d[i + 1] = out[1];
        d[i + 2] = out[2];
    }

    return imageData;
}

// Browsers without WebGL can't draw the vector map, so show OpenStreetMap
// tiles with Leaflet instead, recoloured to the same theme
function initializeFallback() {
    var version = 'https://unpkg.com/leaflet@1.9.4/dist/';
    loadAsset('link', {rel: 'stylesheet', href: version + 'leaflet.css'});
    loadAsset('script', {src: version + 'leaflet.js'}).then(function () {
        var map = L.map('map', {
            zoomControl: false,
            attributionControl: false,
            worldCopyJump: true,
            zoomSnap: 0.25,
            minZoom: minZoomToFill(256),
            // Stop dragging past the top and bottom of the world
            maxBounds: [[-85, -Infinity], [85, Infinity]],
            maxBoundsViscosity: 1
        }).setView([43.4599, -116.244], 3);

        window.addEventListener('resize', function () { map.setMinZoom(minZoomToFill(256)); });

        // Credits beside the people list (see chat.css); the tiles add theirs to it
        map.attributionControl = L.control.attribution({position: 'bottomright', prefix: false}).addTo(map);

        var ThemedTiles = L.GridLayer.extend({
            createTile: function (coords, done) {
                var canvas = document.createElement('canvas');
                canvas.width = canvas.height = 256;

                var img = new Image();
                img.crossOrigin = 'anonymous';
                img.onload = function () {
                    var ctx = canvas.getContext('2d', {willReadFrequently: true});
                    ctx.drawImage(img, 0, 0);
                    ctx.putImageData(themeTile(ctx.getImageData(0, 0, 256, 256)), 0, 0);
                    done(null, canvas);
                };
                img.onerror = function (e) { done(e, canvas); };
                img.src = 'https://tile.openstreetmap.org/' + coords.z + '/' + coords.x + '/' + coords.y + '.png';

                return canvas;
            }
        });

        new ThemedTiles({
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        loadLocations(function (locations) {
            var bounds = [];
            locations.forEach(function (location) {
                var latLng = [parseFloat(location.lat), parseFloat(location.lng)];
                var icon = L.divIcon({className: '', html: pinElement(location.user_id).outerHTML, iconSize: [20, 27], iconAnchor: [10, 27]});
                L.marker(latLng, {icon: icon})
                    .bindTooltip(pinLabel(location).outerHTML, {direction: 'top', offset: [0, -26]})
                    .addTo(map);
                bounds.push(latLng);
            });

            var padding = mapPadding();
            var fit = {
                paddingTopLeft: [padding.left, padding.top],
                paddingBottomRight: [padding.right, padding.bottom],
                maxZoom: 9
            };
            if (bounds.length) map.fitBounds(bounds, fit);
        });
    });
}

function initialize() {
    if (!supportsWebGL()) {
        initializeFallback();
        return;
    }

    fetch(STYLE_URL)
        .then(function (response) { return response.json(); })
        .then(function (style) {
            style.layers.forEach(themeLayer);

            var map = new maplibregl.Map({
                container: 'map',
                style: style,
                center: [-116.244, 43.4599],
                zoom: 3,
                minZoom: minZoomToFill(512),
                attributionControl: false
            });

            // Credits as a small (i) beside the people list (see chat.css). MapLibre
            // opens them at first; close them once the map is drawn
            map.addControl(new maplibregl.AttributionControl({compact: true}), 'bottom-right');
            map.once('load', function () {
                var credits = map.getContainer().querySelector('.maplibregl-ctrl-attrib');
                credits.classList.remove('maplibregl-compact-show');
                credits.removeAttribute('open');
            });

            window.addEventListener('resize', function () { map.setMinZoom(minZoomToFill(512)); });

            map.on('load', function () {
                loadLocations(function (locations) { addPins(map, locations); });
            });
        });
}

$(initialize);
