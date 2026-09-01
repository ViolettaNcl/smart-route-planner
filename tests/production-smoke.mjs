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
assert.equal(health.capabilities?.routing_provider_failover, true);
assert.equal(health.capabilities?.routing_result_cache, true);
assert.equal(health.capabilities?.model_insights, true);
assert.equal(health.capabilities?.model_quality_report, true);
assert.equal(health.capabilities?.model_training_snapshots, true);
assert.equal(health.capabilities?.safe_feedback_queue, true);

const home = await fetchChecked('/?smoke=' + Date.now());
assert.match(home.body, /assets\/js\/product\.js\?v=15/);
assert.match(home.body, /id="route-stop-list"/);
assert.match(home.body, /id="ml-prediction-title"/);
assert.match(home.body, /id="ml-view-quality"/);
assert.match(home.body, /id="ml-view-training"/);
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
assert.match(serviceWorker.body, /srp-shell-v15/);

// ML smoke requests contain anonymous numeric features only — no route labels,
// addresses or coordinates are sent to model diagnostics.
const insightResponse = await fetchChecked('/api/model_insights.php?distance_km=382.4&stops=3&model=mlp&priority=balanced');
const insight = JSON.parse(insightResponse.body);
assert.equal(insight.ok, true);
assert.equal(insight.insight?.privacy?.anonymous_features_only, true);
assert.equal(insight.insight?.privacy?.addresses_processed, false);
assert.equal(insight.insight?.comparison?.models?.mlp?.model, 'mlp');

const qualityResponse = await fetchChecked('/api/model_quality.php');
const quality = JSON.parse(qualityResponse.body);
assert.equal(quality.ok, true);
assert.equal(quality.report?.dataset?.holdout_samples, 120);
assert.equal(quality.report?.dataset?.test_samples, 60);
assert.equal(quality.report?.training?.models?.mlp?.snapshots?.length, 6);
assert.equal(quality.report?.training?.matches_active_model, true);
assert.equal(quality.report?.cross_validation?.folds, 5);
assert.equal(typeof quality.report?.models?.mlp?.metrics?.macro_f1, 'number');
assert.equal(quality.report?.release_policy?.single_feedback_mutates_production, false);

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
assert.equal(typeof route.routing_cached, 'boolean');
assert.equal(typeof route.routing_failover_used, 'boolean');
assert.equal(typeof route.routing_cache_status, 'string');

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
