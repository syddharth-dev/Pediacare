'use strict';

(() => {
    const source = document.getElementById('growth-chart-data');
    if (!source || typeof Chart === 'undefined') return;
    const wrapper = document.getElementById('growth-chart-wrap');
    const fallback = document.getElementById('growth-chart-fallback');
    try {
        const data = JSON.parse(source.dataset.chart);
        const colors = { '-3': '#8a3235', '-2': '#856313', '0': '#396746', '1': '#595957', '2': '#856313', '3': '#8a3235' };
        const label = z => z === 0 ? 'Median' : `${z > 0 ? '+' : ''}${z} SD`;
        const datasets = data.curves.map(curve => ({
            label: `WHO ${label(curve.z)}`, data: curve.data,
            borderColor: colors[curve.z], borderWidth: 1.5,
            borderDash: curve.z < 0 ? [6, 4] : curve.z === 1 ? [2, 4] : [],
            pointRadius: 0, pointHitRadius: 0, tension: 0, fill: false
        }));
        datasets.unshift({ label: 'Saved BMI', data: data.points, showLine: false,
            borderColor: '#1f6c9f', backgroundColor: '#1f6c9f', pointRadius: 5,
            pointStyle: data.points.map(point => point.confirmed ? 'triangle' : 'circle') });
        wrapper.hidden = false;
        new Chart(document.getElementById('growth-chart'), {
            type: 'line', data: { datasets },
            options: {
                responsive: true, maintainAspectRatio: false, animation: false, parsing: false,
                scales: {
                    x: { type: 'linear', min: 0, max: data.maximumMonths,
                        title: { display: true, text: 'WHO age (months = days / 30.4375)' } },
                    y: { title: { display: true, text: 'BMI (kg/m²)' } }
                },
                plugins: {
                    legend: { onClick: () => {}, labels: { filter: (item, chartData) =>
                        chartData.datasets.findIndex(dataset => dataset.label === item.text) === item.datasetIndex } },
                    tooltip: { filter: item => item.datasetIndex === 0,
                        callbacks: { title: items => items[0]?.raw.date || '',
                            label: item => `BMI: ${new Intl.NumberFormat('en', { maximumFractionDigits: 2 }).format(item.parsed.y)}${item.raw.confirmed ? ' (implausible values confirmed)' : ''}` } }
                }
            }
        });
        fallback.hidden = true;
    } catch {
        wrapper.hidden = true;
        fallback.textContent = 'The chart could not be displayed. Use the measurement history below.';
    }
})();
