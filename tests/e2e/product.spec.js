const { test, expect } = require('@playwright/test');

function routeFixture() {
    const coords = [
        { lat: 52.52, lon: 13.405 },
        { lat: 51.34, lon: 12.373 },
        { lat: 50.076, lon: 14.438 },
    ];
    const steps = [
        {
            id: 'leg-1-step-1', distance_m: 1200, duration_min: 2,
            name: 'Alexanderplatz', ref: '',
            maneuver: { type: 'depart', modifier: 'straight' },
            geometry: [[52.52, 13.405], [52.4, 13.2]],
        },
        {
            id: 'leg-1-step-2', distance_m: 380000, duration_min: 230,
            name: 'A13', ref: 'A13',
            maneuver: { type: 'turn', modifier: 'right' },
            geometry: [[52.4, 13.2], [50.076, 14.438]],
        },
        {
            id: 'leg-1-step-3', distance_m: 100, duration_min: 1,
            name: '', ref: '',
            maneuver: { type: 'arrive', modifier: 'straight' },
            geometry: [[50.08, 14.43], [50.076, 14.438]],
        },
    ];
    const baseOption = {
        id: 'route-1', rank: 1, distance_km: 382.4,
        driving_duration_min: 233, duration: { minutes: 233, label: '3 ч 53 мин', exact: true },
        geometry: [[52.52, 13.405], [51.34, 12.373], [50.076, 14.438]],
        legs: [{ index: 0, distance_km: 382.4, duration_min: 233, summary: 'A13', steps }],
        navigation_available: true,
        cost: { amount: 1836, currency: 'RUB', mode: 'car', basis: 'fuel' },
        emissions: { mode: 'car', co2_kg: 45.9, comparison: { walk: 0, car: 45.9, bus: 26 } },
        source: 'osrm_road',
    };
    const alternative = {
        ...baseOption,
        id: 'route-2', rank: 2, distance_km: 401.8,
        driving_duration_min: 246, duration: { minutes: 246, label: '4 ч 6 мин', exact: true },
        cost: { ...baseOption.cost, amount: 1929 },
        emissions: { mode: 'car', co2_kg: 48.2, comparison: { walk: 0, car: 48.2, bus: 27.3 } },
    };
    return {
        ok: true,
        requested_points: ['Berlin', 'Leipzig', 'Praha'],
        points: ['Berlin', 'Leipzig', 'Praha'],
        coords,
        route_stops: coords.map((coord, index) => ({ id: `stop-${index + 1}`, label: ['Berlin', 'Leipzig', 'Praha'][index], ...coord, coordinate_source: 'provided' })),
        optimized: false,
        optimize_order: true,
        distance_km: baseOption.distance_km,
        routing_source: 'osrm_road',
        routing_provider: 'osrm_public_demo',
        route_geometry: baseOption.geometry,
        route_options: [baseOption, alternative],
        legs: baseOption.legs,
        duration: baseOption.duration,
        stops: 3,
        transport: { mode: 'car', mode_ru: 'авто', confidence: 91, model: 'mlp' },
        cost: baseOption.cost,
        emissions: baseOption.emissions,
        skipped: [],
        maps: { google: 'https://maps.example/google', yandex: 'https://maps.example/yandex' },
        calculated_at: '2026-08-30T12:00:00Z',
        request_id: 'e2e-test',
    };
}

test.beforeEach(async ({ page }) => {
    await page.route(/^https:\/\//, (route) => route.abort());
    await page.route('**/api/weather.php', (route) => route.fulfill({ json: { ok: true, forecast: [] } }));
    await page.route('**/api/assistant.php', (route) => route.fulfill({ json: { ok: true, narrative: { text: 'Test advice', source: 'fallback' } } }));
    await page.route('**/api/route.php', async (route) => {
        const body = route.request().postData() || '';
        expect(body).toContain('stops_json=');
        expect(body).toContain('optimize_order=1');
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(routeFixture()) });
    });
});

test('structured editor calculates, compares and exports a route', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('.route-stop-row')).toHaveCount(2);
    await page.locator('[data-stop-input]').nth(0).fill('Berlin');
    await page.locator('#add-stop-button').click();
    await page.locator('[data-stop-input]').nth(1).fill('Leipzig');
    await page.locator('[data-stop-input]').nth(2).fill('Praha');
    await page.locator('#submit-button').click();

    await expect(page.locator('#result-section')).toBeVisible();
    await expect(page.locator('.route-option-card')).toHaveCount(2);
    await expect(page.locator('#stat-distance')).toContainText('382.4');

    await page.locator('[data-route-option-id="route-2"]').click();
    await expect(page.locator('#stat-distance')).toContainText('401.8');

    await page.locator('#tab-navigation').click();
    await expect(page.locator('.navigation-step')).toHaveCount(3);
    await expect(page.locator('.navigation-step').nth(1)).toContainText('A13');

    await page.locator('#tab-share').click();
    const downloadPromise = page.waitForEvent('download');
    await page.locator('#export-geojson-button').click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toBe('smart-route.geojson');
});

test('mobile editor behaves as a bottom sheet', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/');
    const panel = page.locator('.panel');
    await expect(page.locator('#panel-sheet-handle')).toBeVisible();
    await page.locator('#map-focus-toggle').click();
    await expect(panel).toHaveClass(/sheet-peek/);
    await page.locator('#panel-sheet-handle').click();
    await expect(panel).toHaveClass(/sheet-half/);
});

test('structured share link restores labels and coordinates', async ({ page }) => {
    const stops = [
        { label: 'Map start', lat: 52.52, lon: 13.405 },
        { label: 'Map finish', lat: 50.076, lon: 14.438 },
    ];
    const encoded = Buffer.from(JSON.stringify(stops), 'utf8').toString('base64');
    await page.goto('/?s=' + encodeURIComponent(encoded));

    await expect(page.locator('[data-stop-input]').nth(0)).toHaveValue('Map start');
    await expect(page.locator('[data-stop-input]').nth(1)).toHaveValue('Map finish');
    await expect(page.locator('.stop-coordinate').nth(0)).toContainText('52.5200');
    await expect(page.locator('#result-section')).toBeVisible();
});
