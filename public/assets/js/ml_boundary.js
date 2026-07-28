/**
 * Визуализация decision boundary классификатора транспорта.
 *
 * Сервер (api/decision_boundary.php) уже посчитал предсказание модели на
 * регулярной сетке [дистанция x число_точек] — здесь только рендер:
 * фоновые точки сетки, закрашенные по предсказанному классу (имитация
 * heatmap через плотную сетку маленьких квадратов), и поверх — реальные
 * примеры из обучающего датасета (то, на чём модель училась).
 *
 * Намеренно не дублируем forward pass модели на JS: одна реализация
 * (App\ML\MLPClassifier/SoftmaxClassifier) — один источник истины.
 */

const BOUNDARY_COLORS = {
    walk: { fill: 'rgba(82, 215, 136, 0.35)', solid: '#52d788' },
    car: { fill: 'rgba(245, 166, 35, 0.35)', solid: '#f5a623' },
    bus: { fill: 'rgba(79, 209, 197, 0.35)', solid: '#4fd1c5' },
};

const CHART_MUTED = '#8b93a3';
const CHART_GRID = 'rgba(69, 78, 95, 0.35)';

let boundaryChart = null;
let boundaryLoaded = false;

document.addEventListener('DOMContentLoaded', () => {
    const toggleButton = document.getElementById('boundary-toggle');
    const panel = document.getElementById('boundary-panel');
    const modelSelect = document.getElementById('boundary-model-select');

    if (!toggleButton || !panel) {
        return;
    }

    const updateToggleLabel = () => {
        toggleButton.textContent = panel.classList.contains('hidden')
            ? t('boundaryToggle')
            : t('boundaryToggleHide');
    };
    updateToggleLabel();

    // Кнопка не помечена data-i18n (её текст зависит от состояния панели, а не
    // только от языка), поэтому на переключение языка обновляем текст сами.
    document.querySelectorAll('.lang-switch button').forEach((btn) => {
        btn.addEventListener('click', () => setTimeout(updateToggleLabel, 0));
    });

    toggleButton.addEventListener('click', () => {
        const isHidden = panel.classList.contains('hidden');

        if (isHidden) {
            panel.classList.remove('hidden');
            if (!boundaryLoaded) {
                loadBoundaryChart(modelSelect.value);
            }
            if (typeof loadAbStats === 'function') {
                loadAbStats();
            }
        } else {
            panel.classList.add('hidden');
        }
        updateToggleLabel();
    });

    modelSelect.addEventListener('change', () => {
        loadBoundaryChart(modelSelect.value);
    });

    const resetButton = document.getElementById('reset-model-button');
    const resetToast = document.getElementById('reset-model-toast');
    resetButton?.addEventListener('click', async () => {
        resetButton.disabled = true;
        try {
            const response = await fetch('api/reset_model.php', { method: 'POST' });
            const data = await response.json();

            resetToast.textContent = data.ok ? t('resetModelDone') : t('resetModelError');
            resetToast.classList.remove('hidden');
            setTimeout(() => resetToast.classList.add('hidden'), 4000);

            // После сброса весов старая карта решений неактуальна.
            boundaryLoaded = false;
            if (!panel.classList.contains('hidden')) {
                loadBoundaryChart(modelSelect.value);
            }
        } catch (e) {
            resetToast.textContent = t('resetModelError');
            resetToast.classList.remove('hidden');
        } finally {
            resetButton.disabled = false;
        }
    });
});

async function loadBoundaryChart(model) {
    const wrap = document.querySelector('.boundary-chart-wrap');

    try {
        wrap.classList.add('loading');

        const response = await fetch('api/decision_boundary.php?model=' + encodeURIComponent(model));
        const data = await response.json();

        if (!data.ok) {
            throw new Error(data.error || 'boundary error');
        }

        renderBoundaryChart(data);
        boundaryLoaded = true;
    } catch (e) {
        wrap.innerHTML = '<p class="boundary-error">' + t('boundaryError') + '</p>';
    } finally {
        wrap.classList.remove('loading');
    }
}

function renderBoundaryChart(data) {
    const canvas = document.getElementById('boundary-chart');
    if (!canvas) {
        return; // canvas был заменён сообщением об ошибке при предыдущей попытке
    }

    if (boundaryChart) {
        boundaryChart.destroy();
    }

    const datasets = [];

    // --- фоновая "карта решений": по датасету на класс, много мелких точек сетки ---
    data.classes.forEach((cls) => {
        const points = data.grid
            .filter((g) => g.mode === cls)
            .map((g) => ({ x: g.distance_km, y: g.stops }));

        datasets.push({
            label: modeLabel(cls) + ' (' + t('boundaryRegionSuffix') + ')',
            isRegion: true,
            data: points,
            backgroundColor: BOUNDARY_COLORS[cls]?.fill || 'rgba(107,114,128,0.3)',
            pointStyle: 'rect',
            pointRadius: 11,
            pointHoverRadius: 11,
            order: 2,
        });
    });

    // --- реальные примеры из обучающего датасета, поверх фона ---
    data.classes.forEach((cls) => {
        const points = data.samples
            .filter((s) => s.label === cls)
            .map((s) => ({ x: s.distance_km, y: s.stops }));

        datasets.push({
            label: modeLabel(cls) + ' (' + t('boundarySampleSuffix') + ')',
            isRegion: false,
            data: points,
            backgroundColor: BOUNDARY_COLORS[cls]?.solid || '#8b93a3',
            borderColor: '#14171c',
            borderWidth: 1,
            pointStyle: 'circle',
            pointRadius: 4,
            pointHoverRadius: 5,
            order: 1,
        });
    });

    boundaryChart = new Chart(canvas, {
        type: 'scatter',
        data: { datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            scales: {
                x: {
                    type: 'logarithmic',
                    min: 0.2,
                    max: 1200,
                    title: { display: true, text: t('boundaryAxisX'), color: CHART_MUTED },
                    ticks: { color: CHART_MUTED },
                    grid: { color: CHART_GRID },
                },
                y: {
                    type: 'linear',
                    min: 0,
                    max: 14,
                    title: { display: true, text: t('boundaryAxisY'), color: CHART_MUTED },
                    ticks: { stepSize: 2, color: CHART_MUTED },
                    grid: { color: CHART_GRID },
                },
            },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        filter: (item, chartData) => !chartData.datasets[item.datasetIndex].isRegion,
                        boxWidth: 12,
                        font: { size: 11 },
                        color: CHART_MUTED,
                    },
                },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const p = ctx.raw;
                            return `${ctx.dataset.label}: ${p.x} км, ${p.y} точек`;
                        },
                    },
                },
            },
        },
    });
}

function modeLabel(mode) {
    const labels = t('transportModes');
    return labels[mode] || mode;
}
