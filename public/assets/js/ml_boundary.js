/**
 * ML Lab 2.0 — explainable, read-only model diagnostics.
 *
 * Only anonymous numeric features (distance and stop count) are sent to the
 * model endpoints. Public feedback is queued for reviewed batch training;
 * it can never mutate production weights inside a web request.
 */
(function () {
    'use strict';

    const MODES = ['walk', 'car', 'bus'];
    const MODE_COLORS = {
        walk: { solid: '#52d788', fill: 'rgba(82, 215, 136, 0.35)', icon: '🚶' },
        car: { solid: '#ffb547', fill: 'rgba(255, 181, 71, 0.35)', icon: '🚗' },
        bus: { solid: '#48dbe3', fill: 'rgba(72, 219, 227, 0.35)', icon: '🚌' },
    };
    const MODE_POINT_STYLES = { walk: 'circle', car: 'rect', bus: 'triangle' };
    const state = {
        route: null,
        insight: null,
        boundary: null,
        quality: null,
        boundaryLoadedFor: null,
        insightRequest: 0,
        whatIfRequest: 0,
    };

    let boundaryChart = null;
    let calibrationChart = null;
    let trainingChart = null;
    let whatIfTimer = null;
    let trainingTimer = null;

    const byId = (id) => document.getElementById(id);
    const language = () => (typeof getLang === 'function' ? getLang() : document.documentElement.lang || 'ru');
    const copy = (ru, en) => language() === 'en' ? en : ru;
    const tr = (key, fallback) => {
        const value = typeof t === 'function' ? t(key) : undefined;
        return value === undefined ? fallback : value;
    };
    const escapeText = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    const format = (value, digits = 1) => Number(value).toLocaleString(language() === 'en' ? 'en-US' : 'ru-RU', {
        maximumFractionDigits: digits,
    });
    const modeLabel = (mode) => tr('transportModes', {})[mode] || mode;
    const modelLabel = (model) => model === 'softmax' ? tr('boundaryModelSoftmax', 'Softmax') : tr('boundaryModelMlp', 'MLP');
    const reducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;

    function setHidden(element, hidden) {
        if (element) element.classList.toggle('hidden', hidden);
    }

    async function requestJson(path, params = {}) {
        const url = new URL(path, window.location.href);
        Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, String(value)));
        const response = await fetch(url.pathname + url.search, { headers: { Accept: 'application/json' } });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || `HTTP ${response.status}`);
        return data;
    }

    function safeEventId(scope, suffix = '') {
        const routeId = state.route?.request_id || `${state.route?.distance_km || 0}-${state.route?.stops || 0}`;
        return `ml:${scope}:${routeId}:${suffix}`.replace(/[^a-zA-Z0-9._:-]/g, '-').slice(0, 128);
    }

    function certaintyLabel(value) {
        return tr('modelCertaintyLabels', {})[value] || value;
    }

    function probabilityBars(probabilities, compact = false) {
        return MODES.map((mode) => {
            const probability = Number(probabilities?.[mode] || 0);
            return `<div class="probability-row${compact ? ' compact' : ''}">
                <span>${MODE_COLORS[mode].icon} ${escapeText(modeLabel(mode))}</span>
                <span class="probability-track"><span style="width:${Math.max(0, Math.min(100, probability))}%;background:${MODE_COLORS[mode].solid}"></span></span>
                <strong>${format(probability)}%</strong>
            </div>`;
        }).join('');
    }

    function setInsightStatus(message, error = false) {
        const status = byId('ml-insight-loading');
        if (!status) return;
        status.textContent = message;
        status.classList.toggle('is-error', error);
        setHidden(status, false);
        setHidden(byId('ml-insight-content'), true);
    }

    async function loadRouteInsight(priority = 'balanced') {
        if (!state.route) return;
        const requestNumber = ++state.insightRequest;
        setInsightStatus(tr('modelInsightLoading', 'Loading model explanation…'));

        try {
            const data = await requestJson('api/model_insights.php', {
                distance_km: state.route.distance_km,
                stops: state.route.stops,
                priority,
                model: state.route.transport?.model || 'mlp',
            });
            if (requestNumber !== state.insightRequest) return;
            state.insight = data.insight;
            renderRouteInsight();
            renderComparison();
            renderNetwork();
            renderNearestExamples();
            renderBoundaryChart();
        } catch (error) {
            if (requestNumber === state.insightRequest) {
                setInsightStatus(tr('modelInsightError', 'Could not load model explanation.'), true);
            }
        }
    }

    function renderRouteInsight() {
        const insight = state.insight;
        if (!insight) return;
        const prediction = insight.prediction;
        setHidden(byId('ml-insight-loading'), true);
        setHidden(byId('ml-insight-content'), false);

        byId('ml-model-version').textContent = prediction.model_version || modelLabel(insight.active_model);
        byId('ml-certainty-chip').textContent = certaintyLabel(prediction.certainty);
        byId('ml-prediction-mode').textContent = `${MODE_COLORS[prediction.mode]?.icon || ''} ${modeLabel(prediction.mode)}`;
        byId('ml-prediction-score').textContent = `${format(prediction.confidence)}%`;
        byId('ml-probability-bars').innerHTML = probabilityBars(prediction.probabilities);
        byId('ml-input-distance').textContent = `${format(insight.input.distance_km)} ${copy('км', 'km')}`;
        byId('ml-input-stops').textContent = String(insight.input.stops);
        byId('ml-input-margin').textContent = `${format(prediction.margin)} ${copy('п.п.', 'pp')}`;
        byId('ml-input-boundary').textContent = boundarySummary(insight.counterfactuals);

        renderFeatureInfluence(insight.feature_influence, prediction.mode);
        renderCounterfactuals(insight.counterfactuals);
        renderRanking(byId('ml-ranking-list'), insight.ranking?.options || []);

        const variant = state.route?.transport?.model || insight.active_model;
        const prompt = tr('abFeedbackPrompt', (value) => `Is ${value} correct?`);
        byId('ab-feedback-prompt').textContent = prompt(modelLabel(variant));
        document.querySelectorAll('.model-correction-btn').forEach((button) => {
            button.disabled = button.dataset.label === prediction.mode;
            button.setAttribute('aria-pressed', button.dataset.label === prediction.mode ? 'true' : 'false');
        });
        ['ab-feedback-yes', 'ab-feedback-no'].forEach((id) => {
            const button = byId(id);
            if (button) button.disabled = false;
        });
        setHidden(byId('ab-feedback-toast'), true);
    }

    function renderFeatureInfluence(rows, mode) {
        const container = byId('ml-feature-influence');
        if (!container) return;
        container.innerHTML = (rows || []).map((row) => {
            const feature = row.feature === 'distance' ? tr('modelFeatureDistance', 'Distance') : tr('modelFeatureStops', 'Stops');
            const message = row.direction === 'higher_supports'
                ? tr('influenceHigherSupports', (a, b) => `${a}: higher supports ${b}`)(feature, modeLabel(mode))
                : (row.direction === 'lower_supports'
                    ? tr('influenceLowerSupports', (a, b) => `${a}: lower supports ${b}`)(feature, modeLabel(mode))
                    : tr('influenceNeutral', (a) => `${a}: neutral nearby`)(feature));
            const impact = Number(row.impact_pp || 0);
            return `<article class="feature-influence-card">
                <span class="influence-icon" aria-hidden="true">${row.feature === 'distance' ? '↔' : '⋮'}</span>
                <div><strong>${escapeText(feature)}</strong><p>${escapeText(message)}</p>
                <small>${copy('локальная чувствительность', 'local sensitivity')}: ${impact >= 0 ? '+' : ''}${format(impact)} ${copy('п.п.', 'pp')}</small></div>
            </article>`;
        }).join('');
    }

    function renderCounterfactuals(rows) {
        const container = byId('ml-counterfactuals');
        if (!container) return;
        if (!Array.isArray(rows) || rows.length === 0) {
            container.innerHTML = `<p class="model-empty">${escapeText(tr('counterfactualNone', 'No nearby decision change.'))}</p>`;
            return;
        }
        container.innerHTML = rows.map((row) => {
            const message = counterfactualSentence(row);
            return `<article class="counterfactual-card">
                <span aria-hidden="true">${row.delta > 0 ? '↑' : '↓'}</span>
                <div><strong>${escapeText(message)}</strong><small>${format(row.probability)}% · ${copy('ближайшая найденная граница', 'nearest detected boundary')}</small></div>
            </article>`;
        }).join('');
    }

    function counterfactualSentence(row) {
        return row.feature === 'distance'
            ? tr('counterfactualDistance', (value, mode) => `${value} km → ${mode}`)(format(row.value), modeLabel(row.mode))
            : tr('counterfactualStops', (value, mode) => `${value} stops → ${mode}`)(row.value, modeLabel(row.mode));
    }

    function boundarySummary(rows) {
        const row = Array.isArray(rows) ? rows[0] : null;
        if (!row) return copy('не найдена', 'not found');
        const sign = Number(row.delta) > 0 ? '+' : '−';
        const delta = format(Math.abs(Number(row.delta)));
        return row.feature === 'distance'
            ? `${sign}${delta} ${copy('км', 'km')} → ${modeLabel(row.mode)}`
            : `${sign}${delta} ${copy('ост.', 'stops')} → ${modeLabel(row.mode)}`;
    }

    function renderRanking(container, options) {
        if (!container) return;
        const meta = tr('rankingOptionMeta', (minutes, cost, co2) => `${minutes} min · ${cost} ₽ · ${co2} kg CO₂`);
        container.innerHTML = options.map((option) => `<article class="transport-rank-card${option.viable ? '' : ' is-muted'}">
            <span class="rank-number">${option.rank}</span>
            <div class="transport-rank-copy"><strong>${MODE_COLORS[option.mode]?.icon || ''} ${escapeText(modeLabel(option.mode))}</strong>
                <small>${escapeText(meta(option.duration_min, option.cost_rub, option.co2_kg))}</small>
                ${option.viable ? '' : `<em>${escapeText(tr('rankingNotViable', 'Not practical'))}</em>`}
            </div>
            <div class="transport-rank-score"><strong>${format(option.score)}</strong><small>${copy('итог', 'score')}</small></div>
        </article>`).join('');
    }

    function renderComparison() {
        const container = byId('model-comparison-cards');
        const badge = byId('model-agreement-badge');
        if (!container) return;
        const comparison = state.insight?.comparison;
        if (!comparison) {
            container.innerHTML = `<p class="model-empty">${copy('Сначала рассчитайте маршрут.', 'Calculate a route first.')}</p>`;
            if (badge) badge.textContent = '—';
            return;
        }
        badge.textContent = comparison.agreement ? tr('modelAgreement', 'Models agree') : tr('modelDisagreement', 'Models disagree');
        badge.classList.toggle('is-disagreement', !comparison.agreement);
        container.innerHTML = ['mlp', 'softmax'].map((model) => {
            const prediction = comparison.models[model];
            return `<article class="model-comparison-card ${model}">
                <div class="comparison-card-head"><div><span class="section-kicker">${model.toUpperCase()}</span><strong>${escapeText(modelLabel(model))}</strong></div><span>${escapeText(prediction.model_version || '')}</span></div>
                <div class="comparison-prediction"><span>${MODE_COLORS[prediction.mode]?.icon || ''}</span><div><strong>${escapeText(modeLabel(prediction.mode))}</strong><small>${format(prediction.confidence)}% · ${escapeText(certaintyLabel(prediction.certainty))}</small></div></div>
                <div class="comparison-mini-bars">${probabilityBars(prediction.probabilities, true)}</div>
            </article>`;
        }).join('');
    }

    function sliderToDistance(value) {
        const min = Math.log(0.2);
        const max = Math.log(1500);
        return Math.exp(min + (max - min) * Number(value) / 100);
    }

    function distanceToSlider(distance) {
        const min = Math.log(0.2);
        const max = Math.log(1500);
        return Math.max(0, Math.min(100, (Math.log(Math.max(0.2, Number(distance))) - min) / (max - min) * 100));
    }

    function updateWhatIfOutputs() {
        const distance = sliderToDistance(byId('what-if-distance')?.value || 0);
        const stops = Number(byId('what-if-stops')?.value || 2);
        if (byId('what-if-distance-output')) byId('what-if-distance-output').textContent = `${format(distance)} ${copy('км', 'km')}`;
        if (byId('what-if-stops-output')) byId('what-if-stops-output').textContent = String(stops);
    }

    function scheduleWhatIf() {
        window.clearTimeout(whatIfTimer);
        updateWhatIfOutputs();
        whatIfTimer = window.setTimeout(loadWhatIf, 220);
    }

    async function loadWhatIf() {
        const container = byId('what-if-result');
        if (!container) return;
        const requestNumber = ++state.whatIfRequest;
        const distance = sliderToDistance(byId('what-if-distance')?.value || 0);
        const stops = Number(byId('what-if-stops')?.value || 2);
        const priority = byId('what-if-priority')?.value || 'balanced';
        container.innerHTML = `<p class="model-empty">${escapeText(tr('modelInsightLoading', 'Analysing…'))}</p>`;
        try {
            const data = await requestJson('api/model_insights.php', {
                distance_km: distance.toFixed(2),
                stops,
                priority,
                model: byId('boundary-model-select')?.value || 'mlp',
            });
            if (requestNumber !== state.whatIfRequest) return;
            const insight = data.insight;
            const nearestChange = insight.counterfactuals?.[0];
            container.innerHTML = `<div class="what-if-prediction"><span>${MODE_COLORS[insight.prediction.mode]?.icon || ''}</span><div><small>${escapeText(tr('modelRecommendationLabel', 'Recommendation'))}</small><strong>${escapeText(modeLabel(insight.prediction.mode))} · ${format(insight.prediction.confidence)}%</strong></div></div>
                <div class="what-if-probabilities">${probabilityBars(insight.prediction.probabilities, true)}</div>
                <div class="what-if-ranking"><strong>${escapeText(tr('modelRankingTitle', 'Transport ranking'))}</strong>${(insight.ranking?.options || []).map((option) => `<span><b>${option.rank}. ${escapeText(modeLabel(option.mode))}</b><em>${format(option.score)}</em></span>`).join('')}</div>
                <div class="what-if-counterfactual"><small>${escapeText(tr('modelCounterfactualTitle', 'What changes the decision'))}</small><strong>${nearestChange ? escapeText(counterfactualSentence(nearestChange)) : escapeText(tr('counterfactualNone', 'No nearby decision change.'))}</strong></div>`;
        } catch (error) {
            if (requestNumber === state.whatIfRequest) {
                container.innerHTML = `<p class="boundary-error">${escapeText(tr('modelInsightError', 'Could not analyse this scenario.'))}</p>`;
            }
        }
    }

    async function loadBoundary(force = false) {
        const model = byId('boundary-model-select')?.value || 'mlp';
        if (!force && state.boundary && state.boundaryLoadedFor === model) {
            renderBoundaryChart();
            return;
        }
        byId('boundary-chart-description').textContent = tr('boundaryLoading', 'Building decision map…');
        try {
            state.boundary = await requestJson('api/decision_boundary.php', { model });
            state.boundaryLoadedFor = model;
            renderBoundaryTable();
            renderBoundaryChart();
        } catch (error) {
            byId('boundary-chart-description').textContent = tr('boundaryError', 'Could not load decision map.');
        }
    }

    function chartTextColor() {
        return document.documentElement.getAttribute('data-theme') === 'light' ? '#625e78' : '#9aa6b8';
    }

    function chartGridColor() {
        return document.documentElement.getAttribute('data-theme') === 'light' ? 'rgba(200,193,230,.55)' : 'rgba(59,73,96,.45)';
    }

    function renderBoundaryChart() {
        const data = state.boundary;
        const canvas = byId('boundary-chart');
        if (!data || !canvas) return;
        if (typeof Chart === 'undefined') {
            byId('boundary-chart-description').textContent = copy('График недоступен, используйте таблицу значений ниже.', 'Chart unavailable; use the values table below.');
            return;
        }
        boundaryChart?.destroy();
        const enabledClasses = new Set(Array.from(document.querySelectorAll('[data-boundary-class]:checked')).map((input) => input.dataset.boundaryClass));
        const showRegions = byId('boundary-show-regions')?.checked !== false;
        const showSamples = byId('boundary-show-samples')?.checked !== false;
        const showDisagreement = byId('boundary-show-disagreement')?.checked !== false;
        const showCurrent = byId('boundary-show-current')?.checked !== false;
        const selectedModel = data.model || byId('boundary-model-select')?.value || 'mlp';
        const datasets = [];

        if (showRegions) {
            data.classes.filter((mode) => enabledClasses.has(mode)).forEach((mode) => {
                const points = data.grid.filter((point) => point.mode === mode).map((point) => ({
                    x: point.distance_km,
                    y: point.stops,
                    mode,
                    confidence: point.confidence,
                    probabilities: point.models?.[selectedModel]?.probabilities,
                }));
                datasets.push({
                    label: `${modeLabel(mode)} · ${tr('boundaryRegionSuffix', 'region')} (${points.length})`,
                    isRegion: true,
                    data: points,
                    backgroundColor: MODE_COLORS[mode]?.fill,
                    borderColor: MODE_COLORS[mode]?.solid,
                    borderWidth: 0.45,
                    pointStyle: MODE_POINT_STYLES[mode],
                    pointRadius: 10,
                    pointHoverRadius: 11,
                    order: 6,
                });
            });
        }
        if (showDisagreement) {
            const disagreements = data.grid.filter((point) => point.disagreement && enabledClasses.has(point.mode));
            datasets.push({
                label: `${tr('boundaryShowDisagreement', 'Disagreements')} (${disagreements.length})`,
                data: disagreements.map((point) => ({
                    x: point.distance_km,
                    y: point.stops,
                    mode: point.mode,
                    models: point.models,
                })),
                backgroundColor: 'rgba(157,123,255,.28)', borderColor: '#9d7bff', borderWidth: 1,
                pointStyle: 'rectRot', pointRadius: 6, order: 5,
            });
        }
        if (showSamples) {
            data.classes.filter((mode) => enabledClasses.has(mode)).forEach((mode) => {
                const samples = data.samples.filter((point) => point.label === mode);
                datasets.push({
                    label: `${modeLabel(mode)} · ${tr('boundarySampleSuffix', 'samples')} (${samples.length})`,
                    data: samples.map((point) => ({ x: point.distance_km, y: point.stops, mode })),
                    backgroundColor: MODE_COLORS[mode]?.solid,
                    borderColor: document.documentElement.dataset.theme === 'light' ? '#fff' : '#0d1118',
                    borderWidth: 1.5,
                    pointStyle: MODE_POINT_STYLES[mode],
                    pointRadius: 4,
                    pointHoverRadius: 7,
                    order: 3,
                });
            });
        }
        if (state.route && showCurrent) {
            const currentX = Math.max(0.2, Math.min(1500, Number(state.route.distance_km)));
            const currentY = Math.max(2, Math.min(12, Number(state.route.stops)));
            datasets.push({
                label: copy('Ось текущей дистанции', 'Current-distance guide'),
                isGuide: true,
                data: [{ x: currentX, y: 1.5 }, { x: currentX, y: 12.5 }],
                showLine: true,
                pointRadius: 0,
                borderColor: 'rgba(157,123,255,.48)',
                borderDash: [5, 5],
                borderWidth: 1,
                order: 2,
            });
            datasets.push({
                label: copy('Ось числа остановок', 'Stop-count guide'),
                isGuide: true,
                data: [{ x: 0.2, y: currentY }, { x: 1500, y: currentY }],
                showLine: true,
                pointRadius: 0,
                borderColor: 'rgba(157,123,255,.48)',
                borderDash: [5, 5],
                borderWidth: 1,
                order: 2,
            });
            datasets.push({
                label: copy('Текущий маршрут', 'Current route'),
                data: [{
                    x: currentX,
                    y: currentY,
                    mode: state.insight?.prediction?.mode || state.route.transport?.mode,
                    confidence: state.insight?.prediction?.confidence || state.route.transport?.confidence,
                    probabilities: state.insight?.prediction?.probabilities || state.route.transport?.probabilities,
                    isCurrent: true,
                }],
                backgroundColor: '#ffffff', borderColor: '#9d7bff', borderWidth: 3,
                pointStyle: 'star', pointRadius: 11, pointHoverRadius: 13, order: 1,
            });
        }

        boundaryChart = new Chart(canvas, {
            type: 'scatter',
            data: { datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: reducedMotion() ? false : { duration: 420 },
                scales: {
                    x: { type: 'logarithmic', min: 0.2, max: 1500, title: { display: true, text: tr('boundaryAxisX', 'Distance'), color: chartTextColor() }, ticks: { color: chartTextColor() }, grid: { color: chartGridColor() } },
                    y: { min: 1.5, max: 12.5, title: { display: true, text: tr('boundaryAxisY', 'Stops'), color: chartTextColor() }, ticks: { stepSize: 2, color: chartTextColor() }, grid: { color: chartGridColor() } },
                },
                plugins: {
                    legend: { position: 'bottom', labels: { filter: (item, chartData) => !chartData.datasets[item.datasetIndex].isRegion && !chartData.datasets[item.datasetIndex].isGuide, color: chartTextColor(), boxWidth: 10, padding: 14 } },
                    tooltip: {
                        filter: (context) => !context.dataset.isGuide,
                        callbacks: {
                            label: (context) => {
                                const raw = context.raw || {};
                                const lines = [`${context.dataset.label}: ${format(raw.x)} ${copy('км', 'km')}, ${raw.y}`];
                                if (raw.mode) lines.push(`${copy('Прогноз', 'Prediction')}: ${modeLabel(raw.mode)}${raw.confidence ? ` · ${format(raw.confidence)}%` : ''}`);
                                if (raw.probabilities) MODES.forEach((mode) => lines.push(`${MODE_COLORS[mode].icon} ${modeLabel(mode)}: ${format(raw.probabilities[mode] || 0)}%`));
                                if (raw.models) lines.push(`MLP: ${modeLabel(raw.models.mlp.mode)} · Softmax: ${modeLabel(raw.models.softmax.mode)}`);
                                return lines;
                            },
                        },
                    },
                    zoom: { pan: { enabled: true, mode: 'xy' }, zoom: { wheel: { enabled: true }, pinch: { enabled: true }, mode: 'xy' }, limits: { x: { min: 0.2, max: 1500 }, y: { min: 1.5, max: 12.5 } } },
                },
            },
        });
        byId('boundary-chart-description').textContent = enabledClasses.size > 0
            ? tr('boundaryChartDescription', 'Model decision chart.')
            : copy('Выберите хотя бы один класс в фильтре.', 'Select at least one class in the filter.');
    }

    function renderBoundaryTable() {
        const body = byId('boundary-data-table');
        const grid = state.boundary?.grid || [];
        if (!body) return;
        const step = Math.max(1, Math.ceil(grid.length / 72));
        body.innerHTML = grid.filter((_, index) => index % step === 0).map((point) => `<tr${point.disagreement ? ' class="is-disagreement"' : ''}><td>${format(point.distance_km)}</td><td>${point.stops}</td><td>${escapeText(modeLabel(point.models.mlp.mode))} · ${format(point.models.mlp.confidence)}%</td><td>${escapeText(modeLabel(point.models.softmax.mode))} · ${format(point.models.softmax.confidence)}%</td></tr>`).join('');
    }

    async function loadQuality() {
        if (state.quality) {
            renderQuality();
            renderDatasetSummary();
            renderModelCard();
            renderTraining();
            return;
        }
        byId('quality-summary').innerHTML = `<p class="model-empty">${escapeText(copy('Считаю метрики на holdout-выборке…', 'Computing holdout metrics…'))}</p>`;
        try {
            const data = await requestJson('api/model_quality.php');
            state.quality = data.report;
            renderQuality();
            renderDatasetSummary();
            renderModelCard();
            renderTraining();
        } catch (error) {
            byId('quality-summary').innerHTML = `<p class="boundary-error">${escapeText(copy('Не удалось загрузить отчёт качества.', 'Could not load the quality report.'))}</p>`;
        }
    }

    function metricCard(label, value, note = '') {
        return `<article class="quality-metric-card"><small>${escapeText(label)}</small><strong>${escapeText(value)}</strong>${note ? `<span>${escapeText(note)}</span>` : ''}</article>`;
    }

    function renderQuality() {
        const report = state.quality;
        if (!report) return;
        const mlp = report.models.mlp.metrics;
        const softmax = report.models.softmax.metrics;
        byId('quality-sample-count').textContent = `${report.dataset.test_samples || report.dataset.validation_samples} test`;
        byId('quality-summary').innerHTML = [
            metricCard(tr('metricAccuracy', 'Accuracy'), `${format(mlp.accuracy * 100)}%`, `Softmax ${format(softmax.accuracy * 100)}%`),
            metricCard(tr('metricMacroF1', 'Macro-F1'), format(mlp.macro_f1, 3), `Softmax ${format(softmax.macro_f1, 3)}`),
            metricCard(tr('metricLogLoss', 'Log loss'), format(mlp.log_loss, 4), copy('меньше — лучше', 'lower is better')),
            metricCard(tr('metricBrier', 'Brier score'), format(mlp.brier_score, 4), copy('ошибка вероятностей', 'probability error')),
            metricCard(tr('metricEce', 'Calibration error'), format(mlp.expected_calibration_error, 4), 'ECE'),
            metricCard(copy('Исправления', 'Corrections'), format(report.feedback?.queued_corrections || 0, 0), copy('в безопасной очереди', 'in the safe queue')),
        ].join('');
        renderConfusionMatrix(mlp.confusion_matrix);
        renderPerClassMetrics(mlp.per_class);
        renderCalibration(mlp.reliability);
    }

    function renderConfusionMatrix(matrix) {
        const container = byId('confusion-matrix');
        if (!container) return;
        container.innerHTML = `<table class="matrix-table"><caption>${copy('Строки — факт, столбцы — прогноз', 'Rows are actual, columns are predicted')}</caption><thead><tr><th></th>${MODES.map((mode) => `<th>${escapeText(modeLabel(mode))}</th>`).join('')}</tr></thead><tbody>${MODES.map((actual) => `<tr><th>${escapeText(modeLabel(actual))}</th>${MODES.map((predicted) => `<td class="${actual === predicted ? 'matrix-hit' : 'matrix-miss'}">${matrix?.[actual]?.[predicted] ?? 0}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
    }

    function renderPerClassMetrics(metrics) {
        const container = byId('per-class-metrics');
        if (!container) return;
        container.innerHTML = `<table><caption>${copy('Метрики по каждому классу', 'Metrics by class')}</caption><thead><tr><th>${copy('Класс', 'Class')}</th><th>Precision</th><th>Recall</th><th>F1</th><th>Support</th></tr></thead><tbody>${MODES.map((mode) => `<tr><th>${MODE_COLORS[mode].icon} ${escapeText(modeLabel(mode))}</th><td>${format(metrics?.[mode]?.precision, 3)}</td><td>${format(metrics?.[mode]?.recall, 3)}</td><td>${format(metrics?.[mode]?.f1, 3)}</td><td>${metrics?.[mode]?.support ?? 0}</td></tr>`).join('')}</tbody></table>`;
    }

    function renderCalibration(reliability) {
        const canvas = byId('calibration-chart');
        if (!canvas || typeof Chart === 'undefined') return;
        calibrationChart?.destroy();
        const points = (reliability || []).map((row) => ({ x: Number(row.predicted) * 100, y: Number(row.observed) * 100, count: row.count }));
        calibrationChart = new Chart(canvas, {
            type: 'scatter',
            data: { datasets: [
                { label: copy('Идеальная калибровка', 'Perfect calibration'), data: [{ x: 0, y: 0 }, { x: 100, y: 100 }], showLine: true, pointRadius: 0, borderDash: [6, 6], borderColor: chartTextColor() },
                { label: 'MLP', data: points, showLine: true, borderColor: '#9d7bff', backgroundColor: '#9d7bff', pointRadius: points.map((point) => Math.max(4, Math.min(11, Math.sqrt(point.count) + 2))), tension: 0.22 },
            ] },
            options: { responsive: true, maintainAspectRatio: false, animation: reducedMotion() ? false : { duration: 350 }, scales: { x: { min: 0, max: 100, title: { display: true, text: copy('Средняя оценка, %', 'Mean score, %'), color: chartTextColor() }, ticks: { color: chartTextColor() }, grid: { color: chartGridColor() } }, y: { min: 0, max: 100, title: { display: true, text: copy('Фактическая точность, %', 'Observed accuracy, %'), color: chartTextColor() }, ticks: { color: chartTextColor() }, grid: { color: chartGridColor() } } }, plugins: { legend: { labels: { color: chartTextColor() } } } },
        });
    }

    function renderTraining() {
        const training = state.quality?.training;
        const mlp = training?.models?.mlp;
        const snapshots = mlp?.snapshots || [];
        if (!training || !mlp || snapshots.length === 0) return;

        const date = training.generated_at ? new Date(training.generated_at) : null;
        const runLabel = training.matches_active_model === false
            ? tr('trainingReferenceRun', 'reference run')
            : copy('обучено', 'trained');
        byId('training-run-date').textContent = date && !Number.isNaN(date.getTime())
            ? `${runLabel} ${date.toLocaleDateString(language() === 'en' ? 'en-US' : 'ru-RU')}`
            : `seed ${training.dataset_seed}`;
        const slider = byId('training-snapshot-slider');
        slider.max = String(snapshots.length - 1);
        slider.value = String(Math.min(Number(slider.value || 0), snapshots.length - 1));
        renderTrainingCurve(training.models);
        renderTrainingSnapshot(Number(slider.value || 0));
    }

    function renderTrainingCurve(models) {
        const canvas = byId('training-curve-chart');
        if (!canvas || typeof Chart === 'undefined') return;
        trainingChart?.destroy();
        const toPoints = (rows) => (rows || []).map((row) => ({ x: Number(row.epoch), y: Number(row.loss) }));
        trainingChart = new Chart(canvas, {
            type: 'line',
            data: { datasets: [
                { label: 'MLP', data: toPoints(models.mlp?.loss_history), borderColor: '#9d7bff', backgroundColor: 'rgba(157,123,255,.16)', pointRadius: 2.5, pointHoverRadius: 6, borderWidth: 2.5, tension: 0.2 },
                { label: 'Softmax', data: toPoints(models.softmax?.loss_history), borderColor: '#48dbe3', backgroundColor: 'rgba(72,219,227,.12)', pointRadius: 2, pointHoverRadius: 5, borderWidth: 2, tension: 0.2 },
            ] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: reducedMotion() ? false : { duration: 350 },
                parsing: false,
                scales: {
                    x: { type: 'linear', title: { display: true, text: tr('trainingEpoch', 'Epoch'), color: chartTextColor() }, ticks: { color: chartTextColor() }, grid: { color: chartGridColor() } },
                    y: { min: 0, title: { display: true, text: 'Cross-entropy loss', color: chartTextColor() }, ticks: { color: chartTextColor() }, grid: { color: chartGridColor() } },
                },
                plugins: { legend: { labels: { color: chartTextColor() } } },
            },
        });
    }

    function renderTrainingSnapshot(index) {
        const training = state.quality?.training;
        const snapshots = training?.models?.mlp?.snapshots || [];
        const snapshot = snapshots[index];
        const canvas = byId('training-boundary-canvas');
        if (!snapshot || !canvas) return;
        const context = canvas.getContext('2d');
        if (!context) return;

        const distances = training.grid?.distances_km || [];
        const stops = training.grid?.stops || [];
        const encoding = training.grid?.encoding || { w: 'walk', c: 'car', b: 'bus' };
        const width = canvas.width;
        const height = canvas.height;
        const padding = { left: 44, right: 14, top: 16, bottom: 34 };
        const plotWidth = width - padding.left - padding.right;
        const plotHeight = height - padding.top - padding.bottom;
        const cellWidth = plotWidth / Math.max(distances.length, 1);
        const cellHeight = plotHeight / Math.max(stops.length, 1);
        context.clearRect(0, 0, width, height);
        context.fillStyle = document.documentElement.dataset.theme === 'light' ? '#f7f5ff' : '#111824';
        context.fillRect(0, 0, width, height);

        Array.from(snapshot.classes || '').forEach((code, cellIndex) => {
            const row = Math.floor(cellIndex / distances.length);
            const column = cellIndex % distances.length;
            const mode = encoding[code] || 'car';
            const x = padding.left + column * cellWidth;
            const y = padding.top + row * cellHeight;
            context.fillStyle = MODE_COLORS[mode].fill.replace('0.35', '0.76');
            context.fillRect(x, y, Math.ceil(cellWidth + 0.4), Math.ceil(cellHeight + 0.4));
            context.strokeStyle = MODE_COLORS[mode].solid;
            context.globalAlpha = 0.42;
            if (mode === 'walk') {
                context.beginPath();
                context.arc(x + cellWidth / 2, y + cellHeight / 2, Math.min(2.2, cellHeight / 5), 0, Math.PI * 2);
                context.stroke();
            } else if (mode === 'car') {
                context.strokeRect(x + cellWidth * 0.28, y + cellHeight * 0.32, cellWidth * 0.44, cellHeight * 0.36);
            } else {
                context.beginPath();
                context.moveTo(x + cellWidth / 2, y + cellHeight * 0.25);
                context.lineTo(x + cellWidth * 0.72, y + cellHeight * 0.72);
                context.lineTo(x + cellWidth * 0.28, y + cellHeight * 0.72);
                context.closePath();
                context.stroke();
            }
            context.globalAlpha = 1;
        });

        context.fillStyle = chartTextColor();
        context.font = '11px JetBrains Mono, monospace';
        context.textAlign = 'right';
        stops.forEach((value, row) => context.fillText(String(value), padding.left - 8, padding.top + (row + 0.58) * cellHeight));
        context.textAlign = 'center';
        [0, Math.floor(distances.length / 2), distances.length - 1].forEach((column) => {
            context.fillText(`${format(distances[column])} ${copy('км', 'km')}`, padding.left + (column + 0.5) * cellWidth, height - 12);
        });

        if (state.route) {
            const minLog = Math.log(Math.max(0.2, distances[0] || 0.2));
            const maxLog = Math.log(Math.max(0.2, distances[distances.length - 1] || 1200));
            const routeLog = Math.log(Math.max(0.2, Math.min(Number(state.route.distance_km), Math.exp(maxLog))));
            const routeX = padding.left + (routeLog - minLog) / Math.max(maxLog - minLog, 0.001) * plotWidth;
            const routeY = padding.top + (Math.max(2, Math.min(12, Number(state.route.stops))) - 2) / 10 * plotHeight;
            context.strokeStyle = '#ffffff';
            context.lineWidth = 2;
            context.beginPath();
            context.arc(routeX, routeY, 7, 0, Math.PI * 2);
            context.stroke();
            context.strokeStyle = '#9d7bff';
            context.lineWidth = 2;
            context.beginPath();
            context.moveTo(routeX - 11, routeY);
            context.lineTo(routeX + 11, routeY);
            context.moveTo(routeX, routeY - 11);
            context.lineTo(routeX, routeY + 11);
            context.stroke();
        }

        byId('training-snapshot-output').textContent = String(snapshot.epoch);
        const metrics = tr('trainingSnapshotMetrics', (loss, accuracy) => `Loss ${loss} · validation accuracy ${accuracy}%`);
        byId('training-snapshot-metrics').textContent = metrics(format(snapshot.loss, 4), format(snapshot.validation_accuracy * 100));
    }

    function stopTrainingPlayback() {
        window.clearInterval(trainingTimer);
        trainingTimer = null;
        const button = byId('training-play');
        if (button) button.textContent = tr('trainingPlay', '▶ Play');
    }

    function toggleTrainingPlayback() {
        const slider = byId('training-snapshot-slider');
        if (!slider) return;
        if (trainingTimer !== null) {
            stopTrainingPlayback();
            return;
        }
        const advance = () => {
            let next = Number(slider.value) + 1;
            if (next > Number(slider.max)) next = 0;
            slider.value = String(next);
            renderTrainingSnapshot(next);
        };
        if (reducedMotion()) {
            advance();
            return;
        }
        byId('training-play').textContent = tr('trainingPause', '❚❚ Pause');
        advance();
        trainingTimer = window.setInterval(advance, 900);
    }

    function renderDatasetSummary() {
        const report = state.quality;
        const container = byId('dataset-summary');
        if (!report || !container) return;
        const dataset = report.dataset;
        const summary = tr('datasetSummary', (total, holdout) => `${total} examples · ${holdout} holdout`);
        container.innerHTML = `<div class="dataset-summary-hero"><strong>${format(dataset.total_samples, 0)}</strong><span>${copy('синтетических примеров', 'synthetic examples')}</span></div>
            <div class="dataset-summary-grid"><span><small>Train</small><strong>${dataset.train_samples}</strong></span><span><small>Validation</small><strong>${dataset.validation_samples}</strong></span><span><small>Test</small><strong>${dataset.test_samples || '—'}</strong></span><span><small>Seed</small><strong>${dataset.seed}</strong></span><span><small>${copy('Шум меток', 'Label noise')}</small><strong>8%</strong></span><span><small>${copy('Исправления', 'Corrections')}</small><strong>${report.feedback?.queued_corrections || 0}</strong></span><span><small>${copy('Персональные данные', 'Personal data')}</small><strong>${dataset.contains_personal_data ? copy('есть', 'yes') : copy('нет', 'none')}</strong></span></div>
            <p>${escapeText(summary(dataset.total_samples, dataset.holdout_samples || dataset.validation_samples))}</p>`;
    }

    function renderNearestExamples() {
        const container = byId('nearest-examples');
        if (!container) return;
        const rows = state.insight?.nearest_examples;
        if (!rows) {
            container.innerHTML = `<p class="model-empty">${copy('Рассчитайте маршрут, чтобы найти похожие примеры.', 'Calculate a route to find similar examples.')}</p>`;
            return;
        }
        container.innerHTML = rows.map((row) => `<article><span>${MODE_COLORS[row.label]?.icon || ''}</span><div><strong>${format(row.distance_km)} ${copy('км', 'km')} · ${row.stops} ${copy('точки', 'stops')}</strong><small>${escapeText(modeLabel(row.label))}</small></div><em>${format(row.similarity)}%</em></article>`).join('');
    }

    function renderNetwork() {
        const container = byId('network-visual');
        const values = byId('network-values');
        if (!container || !values) return;
        const network = state.insight?.network;
        if (!network) {
            container.innerHTML = `<p class="model-empty">${copy('Рассчитайте маршрут для визуализации forward pass.', 'Calculate a route to visualise the forward pass.')}</p>`;
            values.innerHTML = '';
            return;
        }
        const activations = Array.from(network.hidden_activations || []);
        const contributions = activations.map((_, index) => Number(network.hidden_contributions?.[`neuron_${index}`] || 0));
        const inputYs = [100, 250];
        const hiddenYs = activations.map((_, index) => 35 + index * 40);
        const outputYs = [80, 175, 270];
        const lines = [];
        inputYs.forEach((y1) => hiddenYs.forEach((y2, index) => lines.push(`<line x1="70" y1="${y1}" x2="225" y2="${y2}" stroke="#48dbe3" stroke-opacity="${0.12 + Math.min(0.45, Math.abs(activations[index]) * 0.35)}" stroke-width="${1 + Math.abs(activations[index]) * 2}"/>`)));
        hiddenYs.forEach((y1, index) => outputYs.forEach((y2) => lines.push(`<line x1="245" y1="${y1}" x2="410" y2="${y2}" stroke="${contributions[index] >= 0 ? '#9d7bff' : '#ff7b72'}" stroke-opacity="${0.1 + Math.min(0.6, Math.abs(contributions[index]) * 0.35)}" stroke-width="${1 + Math.min(4, Math.abs(contributions[index]) * 2)}"/>`)));
        const nodes = inputYs.map((y, index) => `<g><circle cx="60" cy="${y}" r="18" class="network-node input"/><text x="60" y="${y + 4}">${index === 0 ? 'km' : '#'}</text></g>`).join('')
            + hiddenYs.map((y, index) => `<g><circle cx="235" cy="${y}" r="${10 + Math.abs(activations[index]) * 6}" class="network-node hidden ${activations[index] >= 0 ? 'positive' : 'negative'}"/><text x="235" y="${y + 4}">${index + 1}</text></g>`).join('')
            + outputYs.map((y, index) => `<g><circle cx="420" cy="${y}" r="20" class="network-node output ${state.insight.prediction.mode === MODES[index] ? 'winner' : ''}"/><text x="420" y="${y + 4}">${MODE_COLORS[MODES[index]].icon}</text></g>`).join('');
        container.innerHTML = `<svg viewBox="0 0 480 330" aria-hidden="true">${lines.join('')}${nodes}</svg>`;
        values.innerHTML = activations.map((activation, index) => `<span><b>H${index + 1}</b><em>${format(activation, 3)}</em><small>${contributions[index] >= 0 ? '+' : ''}${format(contributions[index], 3)}</small></span>`).join('');
    }

    function renderModelCard() {
        const container = byId('model-card-content');
        const report = state.quality;
        if (!container || !report) return;
        const card = report.model_card;
        const localized = (key) => language() === 'ru' && card[`${key}_ru`] ? card[`${key}_ru`] : card[key];
        const trainedAt = card.trained_at ? new Date(card.trained_at).toLocaleDateString(language() === 'en' ? 'en-US' : 'ru-RU') : '—';
        container.innerHTML = `<div class="model-card-hero"><div><span class="section-kicker">${escapeText(card.version)} · ${escapeText(trainedAt)}</span><h4>${escapeText(card.name)}</h4><p>${escapeText(localized('purpose'))}</p></div><span class="model-card-privacy">🔒 ${escapeText(localized('privacy'))}</span></div>
            <div class="model-card-grid"><section><h4>${escapeText(tr('modelCardUses', 'Intended uses'))}</h4><ul>${localized('intended_uses').map((item) => `<li>${escapeText(item)}</li>`).join('')}</ul></section><section><h4>${escapeText(tr('modelCardOutOfScope', 'Out of scope'))}</h4><ul>${localized('out_of_scope').map((item) => `<li>${escapeText(item)}</li>`).join('')}</ul></section></div>
            <section class="model-card-limitations"><h4>${escapeText(tr('modelCardLimitations', 'Known limitations'))}</h4><ul>${localized('limitations').map((item) => `<li>${escapeText(item)}</li>`).join('')}</ul></section>`;
    }

    function exportModelCard() {
        if (!state.quality) return;
        const blob = new Blob([JSON.stringify(state.quality, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `smart-route-model-card-${state.quality.model_card.version}.json`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }

    async function queueCorrection(button) {
        if (!state.route || !state.insight) return;
        const buttons = Array.from(document.querySelectorAll('.model-correction-btn'));
        buttons.forEach((item) => { item.disabled = true; });
        const toast = byId('learn-toast');
        try {
            const body = new URLSearchParams({
                distance_km: String(state.route.distance_km),
                stops: String(state.route.stops),
                correct_label: button.dataset.label,
                model_variant: state.insight.active_model,
                event_id: safeEventId('correction'),
            });
            const response = await fetch('api/learn.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || `HTTP ${response.status}`);
            toast.textContent = data.duplicate ? tr('feedbackDuplicate', 'Already counted.') : tr('feedbackQueued', (n) => `Queued (${n}).`)(data.queue_size);
            setHidden(toast, false);
        } catch (error) {
            toast.textContent = tr('learnError', 'Could not save feedback.');
            setHidden(toast, false);
            buttons.forEach((item) => { item.disabled = false; });
        }
    }

    async function sendAbFeedback(isCorrect) {
        if (!state.route) return;
        const yes = byId('ab-feedback-yes');
        const no = byId('ab-feedback-no');
        yes.disabled = true;
        no.disabled = true;
        const toast = byId('ab-feedback-toast');
        try {
            const variant = state.route.transport?.model || 'mlp';
            const body = new URLSearchParams({ variant, is_correct: isCorrect ? '1' : '0', event_id: safeEventId('ab', variant) });
            const response = await fetch('api/feedback.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || `HTTP ${response.status}`);
            toast.textContent = data.accepted === false ? tr('feedbackDuplicate', 'Already counted.') : tr('abFeedbackThanks', 'Thank you.');
            setHidden(toast, false);
            renderAbStats(data.stats);
        } catch (error) {
            toast.textContent = tr('abFeedbackError', 'Could not save feedback.');
            setHidden(toast, false);
            yes.disabled = false;
            no.disabled = false;
        }
    }

    async function loadAbStats() {
        const container = byId('ab-stats-content');
        if (!container) return;
        container.textContent = tr('abStatsLoading', 'Loading statistics…');
        try {
            const data = await requestJson('api/ab_stats.php');
            renderAbStats(data.stats);
        } catch (error) {
            container.textContent = tr('abStatsError', 'Could not load A/B statistics.');
        }
    }

    function renderAbStats(stats) {
        const container = byId('ab-stats-content');
        if (!container) return;
        const total = (stats?.mlp?.total || 0) + (stats?.softmax?.total || 0);
        if (total === 0) {
            container.textContent = tr('abStatsEmpty', (n) => `${n} responses`)(0);
            return;
        }
        container.innerHTML = ['mlp', 'softmax'].map((variant) => {
            const row = stats[variant] || {};
            const interval = row.confidence_interval || {};
            const confidenceInterval = interval.low === null || interval.low === undefined
                ? '95% CI: —'
                : tr('abStatsInterval', (low, high) => `${low}–${high}%`)(interval.low, interval.high);
            const intervalLabel = row.result_ready
                ? confidenceInterval
                : `${confidenceInterval} · ${tr('abStatsNotReady', 'not enough data')}`;
            return `<article class="ab-stats-row"><div><strong>${escapeText(modelLabel(variant))}</strong><small>${row.correct || 0} / ${row.total || 0}</small></div><span>${row.accuracy === null ? '—' : `${format(row.accuracy)}%`}</span><em>${escapeText(intervalLabel)}</em></article>`;
        }).join('');
    }

    function activateLabView(name, focus = false) {
        if (name !== 'training') stopTrainingPlayback();
        const tabs = Array.from(document.querySelectorAll('.ml-lab-tab'));
        tabs.forEach((tab) => {
            const active = tab.dataset.mlView === name;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            tab.tabIndex = active ? 0 : -1;
            if (active && focus) tab.focus();
        });
        document.querySelectorAll('.ml-lab-view').forEach((view) => setHidden(view, view.id !== `ml-view-${name}`));
        if (name === 'boundary') loadBoundary();
        if (name === 'compare') { renderComparison(); loadAbStats(); }
        if (name === 'quality') loadQuality();
        if (name === 'network') renderNetwork();
        if (name === 'training') loadQuality();
        if (name === 'data') { renderNearestExamples(); loadQuality(); }
        if (name === 'card') loadQuality();
    }

    function openLab(view = 'boundary') {
        const panel = byId('boundary-panel');
        const toggle = byId('boundary-toggle');
        setHidden(panel, false);
        toggle?.setAttribute('aria-expanded', 'true');
        if (toggle) toggle.textContent = tr('boundaryToggleHide', 'Hide ML Lab');
        activateLabView(view);
        byId('boundary-section')?.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' });
    }

    function bindLab() {
        const toggle = byId('boundary-toggle');
        toggle?.addEventListener('click', () => {
            const panel = byId('boundary-panel');
            const opening = panel.classList.contains('hidden');
            setHidden(panel, !opening);
            toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
            toggle.textContent = opening ? tr('boundaryToggleHide', 'Hide ML Lab') : tr('boundaryToggle', 'Show ML Lab');
            if (opening) activateLabView(document.querySelector('.ml-lab-tab.active')?.dataset.mlView || 'boundary');
            if (!opening) stopTrainingPlayback();
        });
        byId('open-ml-lab')?.addEventListener('click', () => openLab('boundary'));

        const tabs = Array.from(document.querySelectorAll('.ml-lab-tab'));
        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => activateLabView(tab.dataset.mlView));
            tab.addEventListener('keydown', (event) => {
                let next = null;
                if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
                if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
                if (event.key === 'Home') next = 0;
                if (event.key === 'End') next = tabs.length - 1;
                if (next === null) return;
                event.preventDefault();
                activateLabView(tabs[next].dataset.mlView, true);
            });
        });

        byId('boundary-model-select')?.addEventListener('change', () => {
            loadBoundary(true);
            scheduleWhatIf();
        });
        ['boundary-show-regions', 'boundary-show-samples', 'boundary-show-disagreement', 'boundary-show-current'].forEach((id) => byId(id)?.addEventListener('change', renderBoundaryChart));
        document.querySelectorAll('[data-boundary-class]').forEach((input) => input.addEventListener('change', renderBoundaryChart));
        byId('boundary-reset-zoom')?.addEventListener('click', () => boundaryChart?.resetZoom?.());
        ['what-if-distance', 'what-if-stops'].forEach((id) => byId(id)?.addEventListener('input', scheduleWhatIf));
        byId('what-if-priority')?.addEventListener('change', scheduleWhatIf);
        document.querySelectorAll('.model-correction-btn').forEach((button) => button.addEventListener('click', () => queueCorrection(button)));
        byId('ab-feedback-yes')?.addEventListener('click', () => sendAbFeedback(true));
        byId('ab-feedback-no')?.addEventListener('click', () => sendAbFeedback(false));
        byId('export-model-card')?.addEventListener('click', exportModelCard);
        byId('training-snapshot-slider')?.addEventListener('input', (event) => {
            stopTrainingPlayback();
            renderTrainingSnapshot(Number(event.target.value));
        });
        byId('training-play')?.addEventListener('click', toggleTrainingPlayback);
        updateWhatIfOutputs();
        renderComparison();
        renderNetwork();
        renderNearestExamples();
    }

    window.updateMlLabForRoute = function (routeData) {
        state.route = routeData;
        state.insight = null;
        const modelSelect = byId('boundary-model-select');
        if (modelSelect && ['mlp', 'softmax'].includes(routeData.transport?.model)) {
            modelSelect.value = routeData.transport.model;
            if (state.boundary && state.boundaryLoadedFor !== modelSelect.value) {
                loadBoundary(true);
            }
        }
        const slider = byId('what-if-distance');
        if (slider) slider.value = String(distanceToSlider(routeData.distance_km));
        const stopsSlider = byId('what-if-stops');
        if (stopsSlider) stopsSlider.value = String(Math.max(2, Math.min(12, Number(routeData.stops))));
        updateWhatIfOutputs();
        loadRouteInsight(byId('what-if-priority')?.value || 'balanced');
        if (state.quality) renderTrainingSnapshot(Number(byId('training-snapshot-slider')?.value || 0));
    };

    window.refreshBoundaryChartTheme = function () {
        renderBoundaryChart();
        if (state.quality) renderCalibration(state.quality.models.mlp.metrics.reliability);
        if (state.quality) renderTraining();
    };

    window.refreshMlLabLanguage = function () {
        const panelHidden = byId('boundary-panel')?.classList.contains('hidden') !== false;
        if (byId('boundary-toggle')) byId('boundary-toggle').textContent = panelHidden ? tr('boundaryToggle', 'Show ML Lab') : tr('boundaryToggleHide', 'Hide ML Lab');
        updateWhatIfOutputs();
        if (state.insight) {
            renderRouteInsight();
            renderComparison();
            renderNetwork();
            renderNearestExamples();
        }
        if (state.boundary) { renderBoundaryTable(); renderBoundaryChart(); }
        if (state.quality) { renderQuality(); renderDatasetSummary(); renderModelCard(); renderTraining(); }
        loadAbStats();
    };

    document.addEventListener('DOMContentLoaded', bindLab);
}());
