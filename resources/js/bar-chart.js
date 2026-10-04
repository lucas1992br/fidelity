/**
 * Alpine component behind <x-ui.bar-chart>.
 *
 * The chart config lives in the root element's `data-chart` attribute, so a
 * Livewire re-render morphs only that attribute: the observer updates the
 * existing Chart.js instance instead of drawing a second canvas. Alpine calls
 * destroy() when the element leaves the page (including wire:navigate).
 */
const TONES = {
    guardian: ['--color-guardian-500', '#2e78f2'],
    embrace: ['--color-embrace-300', '#fba079'],
    success: ['--color-success-500', '#29a871'],
    danger: ['--color-danger-500', '#e55656'],
    ink: ['--color-ink-500', '#6a7385'],
};

const money = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
const compactMoney = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', notation: 'compact', maximumFractionDigits: 1 });

function color(tone) {
    const [variable, fallback] = TONES[tone] ?? TONES.guardian;

    return getComputedStyle(document.documentElement).getPropertyValue(variable).trim() || fallback;
}

function buildDatasets({ datasets = [], line = null }) {
    const bars = datasets.map((dataset) => ({
        type: 'bar',
        label: dataset.label,
        data: dataset.data.map(Number),
        backgroundColor: color(dataset.tone),
        borderRadius: { topLeft: 4, topRight: 4 },
        maxBarThickness: 14,
        order: 2,
    }));

    if (!line) {
        return bars;
    }

    return [
        ...bars,
        {
            type: 'line',
            label: line.label,
            data: line.data.map(Number),
            borderColor: color(line.tone ?? 'ink'),
            backgroundColor: color(line.tone ?? 'ink'),
            borderDash: [6, 4],
            borderWidth: 2,
            pointRadius: 2,
            tension: 0.3,
            order: 1,
        },
    ];
}

export default function barChart() {
    let chart = null;
    let observer = null;

    return {
        init() {
            const canvas = this.$refs.canvas;
            const config = this.read();

            window.Chart.getChart(canvas)?.destroy();

            chart = new window.Chart(canvas, {
                data: { labels: config.labels, datasets: buildDatasets(config) },
                options: {
                    locale: 'pt-BR',
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    font: { family: "'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif" },
                    plugins: {
                        legend: {
                            align: 'end',
                            labels: { usePointStyle: true, pointStyle: 'rectRounded', boxWidth: 10, boxHeight: 10, color: color('ink') },
                        },
                        tooltip: {
                            callbacks: {
                                label: (context) => `${context.dataset.label}: ${money.format(context.parsed.y ?? 0)}`,
                            },
                        },
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: color('ink') } },
                        y: {
                            beginAtZero: true,
                            border: { display: false },
                            ticks: { color: color('ink'), callback: (value) => compactMoney.format(value) },
                        },
                    },
                },
            });

            observer = new MutationObserver(() => this.update());
            observer.observe(this.$el, { attributes: true, attributeFilter: ['data-chart'] });
        },

        read() {
            return JSON.parse(this.$el.dataset.chart);
        },

        update() {
            const config = this.read();

            chart.data.labels = config.labels;
            chart.data.datasets = buildDatasets(config);
            chart.update();
        },

        destroy() {
            observer?.disconnect();
            chart?.destroy();
            observer = null;
            chart = null;
        },
    };
}
