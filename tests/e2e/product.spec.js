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
        transport: {
            mode: 'car', mode_ru: 'авто', confidence: 91, model: 'mlp',
            model_version: 'mlp-e2e1234', margin: 67, certainty: 'stable',
            probabilities: { walk: 2, car: 91, bus: 7 },
        },
        cost: baseOption.cost,
        emissions: baseOption.emissions,
        skipped: [],
        maps: { google: 'https://maps.example/google', yandex: 'https://maps.example/yandex' },
        calculated_at: '2026-08-30T12:00:00Z',
        request_id: 'e2e-test',
    };
}

function insightFixture(model = 'mlp', distance = 382.4, stops = 3) {
    const mlp = { mode: 'car', confidence: 91, model: 'mlp', model_version: 'mlp-e2e1234', margin: 67, certainty: 'stable', probabilities: { walk: 2, car: 91, bus: 7 } };
    const softmax = { mode: 'bus', confidence: 56, model: 'softmax', model_version: 'softmax-e2e', margin: 18, certainty: 'moderate', probabilities: { walk: 5, car: 39, bus: 56 } };
    const prediction = model === 'softmax' ? softmax : mlp;
    return {
        ok: true,
        insight: {
            input: { distance_km: Number(distance), stops: Number(stops), normalized: { distance: 0.72, stops: 0.1 } },
            active_model: model,
            prediction,
            comparison: { agreement: false, models: { mlp, softmax } },
            feature_influence: [
                { feature: 'distance', current: Number(distance), lower: { value: 300, probability: 86 }, upper: { value: 460, probability: 94 }, impact_pp: 8, direction: 'higher_supports', method: 'local_perturbation' },
                { feature: 'stops', current: Number(stops), lower: { value: 2, probability: 91 }, upper: { value: 4, probability: 89 }, impact_pp: -2, direction: 'lower_supports', method: 'local_perturbation' },
            ],
            counterfactuals: [{ feature: 'distance', value: 52, delta: -330.4, mode: 'bus', probability: 58 }],
            nearest_examples: Array.from({ length: 5 }, (_, index) => ({ distance_km: 370 + index, stops: 3, label: index % 2 ? 'bus' : 'car', similarity: 96 - index })),
            ranking: { method: 'hybrid_probability_utility', priority: 'balanced', options: [
                { rank: 1, mode: 'car', model_probability: 91, duration_min: 328, cost_rub: 1836, co2_kg: 45.9, viable: true, score: 76 },
                { rank: 2, mode: 'bus', model_probability: 7, duration_min: 417, cost_rub: 1147, co2_kg: 26, viable: true, score: 48 },
                { rank: 3, mode: 'walk', model_probability: 2, duration_min: 4589, cost_rub: 0, co2_kg: 0, viable: false, score: 9 },
            ] },
            network: { architecture: '2 → 8 tanh → 3 softmax', hidden_activations: [0.9, -0.4, 0.6, 0.2, -0.7, 0.5, 0.1, -0.2], hidden_contributions: { neuron_0: 0.8, neuron_1: -0.3, neuron_2: 0.5, neuron_3: 0.1, neuron_4: -0.6, neuron_5: 0.4, neuron_6: 0.08, neuron_7: -0.12 } },
            privacy: { anonymous_features_only: true, addresses_processed: false },
        },
    };
}

function qualityFixture() {
    const metrics = {
        accuracy: 0.9, macro_f1: 0.907, log_loss: 0.31, brier_score: 0.15, expected_calibration_error: 0.06,
        confusion_matrix: { walk: { walk: 25, car: 0, bus: 1 }, car: { walk: 1, car: 49, bus: 2 }, bus: { walk: 0, car: 8, bus: 34 } },
        per_class: { walk: { precision: 0.96, recall: 0.96, f1: 0.96, support: 26 }, car: { precision: 0.86, recall: 0.94, f1: 0.9, support: 52 }, bus: { precision: 0.92, recall: 0.81, f1: 0.86, support: 42 } },
        reliability: [{ predicted: 0.62, observed: 0.58, count: 20 }, { predicted: 0.87, observed: 0.9, count: 100 }],
        calibration_by_class: { walk: [], car: [], bus: [] },
    };
    const training = {
        schema_version: 1,
        generated_at: '2026-08-29T15:45:00Z',
        dataset_seed: 42,
        grid: { distances_km: [0.2, 3, 1500], stops: [2, 12], encoding: { w: 'walk', c: 'car', b: 'bus' } },
        models: {
            mlp: { epochs: 1500, learning_rate: 0.5, loss_history: [{ epoch: 0, loss: 1.15 }, { epoch: 1499, loss: 0.29 }], snapshots: [
                { epoch: 0, loss: 1.15, validation_accuracy: 0.65, classes: 'cccwbb' },
                { epoch: 1499, loss: 0.29, validation_accuracy: 0.9, classes: 'wcbbcb' },
            ] },
            softmax: { epochs: 2000, learning_rate: 0.5, loss_history: [{ epoch: 0, loss: 1.09 }, { epoch: 1999, loss: 0.44 }] },
        },
    };
    return { ok: true, report: {
        dataset: { seed: 42, total_samples: 600, train_samples: 480, holdout_samples: 120, validation_samples: 60, test_samples: 60, contains_personal_data: false },
        models: { mlp: { version: 'mlp-e2e1234', architecture: '2 → 8 tanh → 3 softmax', parameters: 51, metrics }, softmax: { version: 'softmax-e2e', architecture: '2 → 3 softmax', parameters: 9, metrics: { ...metrics, accuracy: 0.87, macro_f1: 0.86 } } },
        training,
        model_card: { name: 'Smart Route Transport Classifier', version: 'mlp-e2e1234', trained_at: '2026-08-29T15:45:00Z', purpose: 'Educational recommendation.', intended_uses: ['Explainable ML demonstration'], out_of_scope: ['Safety-critical navigation'], limitations: ['Synthetic training data'], privacy: 'Anonymous numeric features only.' },
        feedback: { queued_corrections: 4, contains_addresses: false, deduplicated_by_event_id: true },
        release_policy: { feedback_is_queued: true, single_feedback_mutates_production: false, candidate_requires_holdout_improvement: true, rollback_supported: true },
    } };
}

test.beforeEach(async ({ page }) => {
    await page.route(/^https:\/\//, (route) => route.abort());
    await page.route('**/api/weather.php', (route) => route.fulfill({ json: { ok: true, forecast: [] } }));
    await page.route('**/api/assistant.php', (route) => route.fulfill({ json: { ok: true, narrative: { text: 'Test advice', source: 'fallback' } } }));
    await page.route('**/api/model_insights.php**', (route) => {
        const url = new URL(route.request().url());
        route.fulfill({ json: insightFixture(url.searchParams.get('model') || 'mlp', url.searchParams.get('distance_km') || 382.4, url.searchParams.get('stops') || 3) });
    });
    await page.route('**/api/model_quality.php**', (route) => route.fulfill({ json: qualityFixture() }));
    await page.route('**/api/decision_boundary.php**', (route) => route.fulfill({ json: {
        ok: true, model: 'mlp', comparison_available: true, model_versions: { mlp: 'mlp-e2e1234', softmax: 'softmax-e2e' }, classes: ['walk', 'car', 'bus'],
        grid: [
            { distance_km: 1, stops: 2, mode: 'walk', confidence: 92, disagreement: false, models: { mlp: { mode: 'walk', confidence: 92 }, softmax: { mode: 'walk', confidence: 88 } } },
            { distance_km: 382, stops: 4, mode: 'car', confidence: 91, disagreement: true, models: { mlp: { mode: 'car', confidence: 91 }, softmax: { mode: 'bus', confidence: 56 } } },
        ],
        samples: [{ distance_km: 1, stops: 2, label: 'walk' }, { distance_km: 382, stops: 4, label: 'car' }],
    } }));
    await page.route('**/api/ab_stats.php**', (route) => route.fulfill({ json: { ok: true, stats: { mlp: { correct: 8, incorrect: 2, total: 10, accuracy: 80, confidence_interval: { low: 49, high: 94 }, result_ready: false }, softmax: { correct: 6, incorrect: 4, total: 10, accuracy: 60, confidence_interval: { low: 31, high: 83 }, result_ready: false } } } }));
    await page.route('**/api/learn.php', (route) => {
        const body = route.request().postData() || '';
        expect(body).toContain('model_variant=mlp');
        expect(body).toContain('event_id=ml%3Acorrection%3Ae2e-test%3A');
        route.fulfill({ json: { ok: true, applied: false, queued: true, duplicate: false, queue_size: 4 } });
    });
    await page.route('**/api/feedback.php', (route) => route.fulfill({ json: { ok: true, accepted: true, duplicate: false, stats: { mlp: { correct: 9, incorrect: 2, total: 11, accuracy: 81.8, confidence_interval: { low: 52, high: 95 }, result_ready: false }, softmax: { correct: 6, incorrect: 4, total: 10, accuracy: 60, confidence_interval: { low: 31, high: 83 }, result_ready: false } } } }));
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

test('ML Lab explains, compares and audits the selected model safely', async ({ page }) => {
    await page.goto('/');
    await page.locator('[data-stop-input]').nth(0).fill('Berlin');
    await page.locator('[data-stop-input]').nth(1).fill('Praha');
    await page.locator('#submit-button').click();

    await page.locator('#tab-model').click();
    await expect(page.locator('#ml-prediction-mode')).toContainText(/авто|car/);
    await expect(page.locator('#ml-probability-bars .probability-row')).toHaveCount(3);
    await expect(page.locator('#ml-feature-influence .feature-influence-card')).toHaveCount(2);
    await expect(page.locator('#ml-counterfactuals .counterfactual-card')).toHaveCount(1);
    await expect(page.locator('#ml-ranking-list .transport-rank-card')).toHaveCount(3);

    await page.locator('.model-correction-btn[data-label="bus"]').click();
    await expect(page.locator('#learn-toast')).toContainText(/очеред|queued/i);
    await expect(page.locator('.model-correction-btn:disabled')).toHaveCount(3);

    await page.locator('#open-ml-lab').click();
    await expect(page.locator('#boundary-panel')).toBeVisible();
    await expect(page.locator('#boundary-data-table tr')).toHaveCount(2);

    await page.locator('[data-ml-view="compare"]').click();
    await expect(page.locator('.model-comparison-card')).toHaveCount(2);
    await expect(page.locator('#model-agreement-badge')).toContainText(/не согласны|disagree/i);

    await page.locator('[data-ml-view="quality"]').click();
    await expect(page.locator('.quality-metric-card')).toHaveCount(6);
    await expect(page.locator('.matrix-table')).toBeVisible();

    await page.locator('[data-ml-view="network"]').click();
    await expect(page.locator('#network-visual svg')).toBeVisible();
    await expect(page.locator('#network-values span')).toHaveCount(8);

    await page.locator('[data-ml-view="training"]').click();
    await expect(page.locator('#training-boundary-canvas')).toBeVisible();
    await expect(page.locator('#training-snapshot-output')).toHaveText('0');
    await page.locator('#training-snapshot-slider').press('End');
    await expect(page.locator('#training-snapshot-output')).toHaveText('1499');

    await page.locator('[data-ml-view="data"]').click();
    await expect(page.locator('#nearest-examples article')).toHaveCount(5);

    await page.locator('[data-ml-view="card"]').click();
    await expect(page.locator('#model-card-content')).toContainText('Smart Route Transport Classifier');
    await expect(page.locator('.release-policy-card')).toBeVisible();
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
