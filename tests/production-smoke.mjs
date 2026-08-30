import assert from 'node:assert/strict';

const baseUrl = (process.env.PRODUCTION_URL || 'https://smart-route-planner-violettancls-projects.vercel.app').replace(/\/$/, '');
const expectedCommit = String(process.env.EXPECTED_COMMIT_SHA || '').trim().slice(0, 12);
const waitMs = Math.max(0, Number(process.env.SMOKE_WAIT_MS || 0));
const requireIndexable = process.env.REQUIRE_INDEXABLE === '1';

function sleep(milliseconds) {
    return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

async function fetchChecked(path, options = {}, timeoutMs = 45_000) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    try {
        const response = await fetch(baseUrl + path, {
            redirect: 'follow',
            cache: 'no-store',
            signal: controller.signal,
            ...options,
        });
        const body = await response.text();
        assert.equal(response.ok, true, `${path} returned ${response.status}: ${body.slice(0, 200)}`);
        return { response, body };
    } finally {
        clearTimeout(timer);
    }
}

async function waitForExpectedDeployment() {
    const deadline = Date.now() + waitMs;
    let lastObserved = 'unavailable';
    do {
        try {
            const result = await fetchChecked('/api/health.php?smoke=' + Date.now(), {}, 20_000);
            const health = JSON.parse(result.body);
            lastObserved = String(health.version || 'unknown');
            if (health.ok === true && (!expectedCommit || lastObserved === expectedCommit)) {
                return health;
            }
        } catch (error) {
            lastObserved = error instanceof Error ? error.message : String(error);
        }
        if (Date.now() < deadline) await sleep(10_000);
    } while (Date.now() < deadline);

    throw new Error(`Expected production commit ${expectedCommit || '(any healthy)'}; last observed ${lastObserved}`);
}

const health = await waitForExpectedDeployment();
assert.equal(health.capabilities?.structured_stops, true);
assert.equal(health.capabilities?.route_alternatives, true);
assert.equal(health.capabilities?.navigation_steps, true);

const home = await fetchChecked('/?smoke=' + Date.now());
assert.match(home.body, /assets\/js\/product\.js\?v=11/);
assert.match(home.body, /id="route-stop-list"/);
assert.match(home.body, /rel="canonical"/);
assert.match(home.body, /<meta name="robots" content="index,follow/);
const robotsHeader = String(home.response.headers.get('x-robots-tag') || '');
const indexable = !/noindex/i.test(robotsHeader);
if (requireIndexable) {
    assert.equal(indexable, true, `Production returned X-Robots-Tag: ${robotsHeader || '(empty)'}`);
}

const robots = await fetchChecked('/robots.txt');
assert.match(robots.body, /Allow:\s*\//);
assert.match(robots.body, /Sitemap:/);

const sitemap = await fetchChecked('/sitemap.xml');
assert.match(sitemap.body, /<urlset/);

const serviceWorker = await fetchChecked('/service-worker.js?smoke=' + Date.now());
assert.match(serviceWorker.body, /srp-shell-v11/);

const stops = [
    { label: 'Berlin', lat: 52.520008, lon: 13.404954 },
    { label: 'Praha', lat: 50.075539, lon: 14.4378 },
];
const routeBody = new URLSearchParams({ stops_json: JSON.stringify(stops), points: 'Berlin;Praha', optimize_order: '1' });
const routeResponse = await fetchChecked('/api/route.php', {
    method: 'POST',
    headers: { 'content-type': 'application/x-www-form-urlencoded' },
    body: routeBody,
});
const route = JSON.parse(routeResponse.body);
assert.equal(route.ok, true);
assert.equal(route.stops, 2);
assert.ok(Array.isArray(route.route_geometry) && route.route_geometry.length >= 2);
assert.ok(Array.isArray(route.route_options) && route.route_options.length >= 1);

const report = {
    ok: true,
    url: baseUrl,
    version: health.version,
    environment: health.environment,
    providers: health.providers,
    indexable,
    robotsHeader: robotsHeader || null,
    routeOptions: route.route_options.length,
    navigation: route.route_options.some((option) => option.navigation_available),
    checkedAt: new Date().toISOString(),
};
console.log(JSON.stringify(report, null, 2));
