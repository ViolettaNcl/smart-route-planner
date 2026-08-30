const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const appSource = fs.readFileSync(
    path.join(__dirname, '..', 'public', 'assets', 'js', 'app.js'),
    'utf8'
);
const helperStart = appSource.indexOf('function mapStyleCanAcceptRouteLayers()');
const helperEnd = appSource.indexOf('\nfunction renderMap(', helperStart);

assert.notEqual(helperStart, -1, 'route-layer readiness helper must exist');
assert.notEqual(helperEnd, -1, 'route drawing helper must be extractable');

const routeDrawingSource = appSource.slice(helperStart, helperEnd);

function createContext({ layers }) {
    const calls = {
        animation: 0,
        errors: [],
        fallback: 0,
        layers: 0,
        markers: 0,
        styleLoadedProbe: 0,
    };
    let phase = 'framing';

    const context = {
        Array,
        Boolean,
        ROUTE_DRAWING_RETRY_DELAY_MS: 120,
        ROUTE_DRAWING_RETRY_LIMIT: 25,
        ROUTE_SOURCE_ID: 'route-geometry',
        addRouteLayers: () => { calls.layers += 1; },
        animateRouteLine: () => { calls.animation += 1; },
        clearRouteLayers: () => {},
        clearTimeout: () => {},
        console: { error: (...args) => { calls.errors.push(args.map(String).join(' ')); }, warn: () => {} },
        mapPanel: { classList: { add: () => {}, remove: () => {} } },
        mapStyleFallbackTimer: null,
        renderRouteMarkers: () => { calls.markers += 1; },
        renderStaticRouteMap: () => { calls.fallback += 1; },
        routeCoordinates: () => [[30, 50], [31, 51]],
        routeDrawingRetryTimer: null,
        routeGeoJsonFromCoordinates: (coordinates) => coordinates,
        routeMap: {
            getSource: () => ({ setData: () => {} }),
            getStyle: () => ({ layers }),
            isStyleLoaded: () => {
                calls.styleLoadedProbe += 1;
                throw new Error('beginRouteDrawing must not wait on isStyleLoaded()');
            },
        },
        routeSceneToken: 7,
        setRouteScenePhase: (nextPhase) => { phase = nextPhase; },
        window: { setTimeout: () => 1 },
    };

    vm.createContext(context);
    vm.runInContext(routeDrawingSource, context);
    return { calls, context, getPhase: () => phase };
}

{
    const test = createContext({ layers: [{ id: 'visible-base-map' }] });
    test.context.beginRouteDrawing(
        [{ lat: 50, lon: 30 }, { lat: 51, lon: 31 }],
        ['Start', 'Finish'],
        [[50, 30], [51, 31]],
        7
    );

    assert.equal(test.calls.styleLoadedProbe, 0, 'terrain loading must not block route drawing');
    assert.equal(test.calls.layers, 1, 'route layers should be added');
    assert.equal(test.calls.markers, 1, `route markers should be rendered: ${JSON.stringify(test.calls)}`);
    assert.equal(test.calls.animation, 1, 'route animation should start');
    assert.equal(test.calls.fallback, 0, 'an editable style should not use fallback');
    assert.equal(test.getPhase(), 'drawing');
}

{
    const test = createContext({ layers: [] });
    test.context.beginRouteDrawing([], [], [], 7, 25);

    assert.equal(test.calls.fallback, 1, 'an unavailable style must terminate in static fallback');
    assert.equal(test.calls.animation, 0);
}

console.log('frontend route scene regression: passed');
