import Chart from 'chart.js/auto';
import barChart from './bar-chart';

window.Chart = Chart;

document.addEventListener('alpine:init', () => {
    window.Alpine.data('barChart', barChart);
});
