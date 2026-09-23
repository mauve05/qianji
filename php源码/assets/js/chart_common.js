/**
 * 公共统计图助手（ECharts / chartCanvas 形式，不再自绘 SVG）
 * 统一约定：弹层内「一页一图」单图区 + 维度按钮 / 柱饼切换按钮（样式 .os-tab，参考 omrStatsModal）。
 * 本文件提供：
 *   ecGet/ecSet/ecDel —— ECharts 实例管理（隐藏容器不初始化：先显示弹层再渲染，避免 0×0 空白；窗口缩放自适应）
 *   ecBarOpt          —— 柱形图 option（labels/values，逐柱配色，柱顶数量标签）
 *   ecPieOpt          —— 环形饼图 option（items=[{name,value,color}]，引线标签=名称：数量（占比），中心两行文本）
 */
'use strict';
var EC_REG = {};   // 容器id → echarts 实例
function ecGet(id) {
    if (typeof echarts === 'undefined') return null;
    var el = document.getElementById(id);
    if (!el) return null;
    if (el.offsetWidth < 10 || el.offsetHeight < 10) return null;   // 隐藏容器：暂不初始化
    if (!EC_REG[id]) EC_REG[id] = echarts.init(el);
    return EC_REG[id];
}
function ecSet(id, opt) {
    var ch = ecGet(id);
    if (ch) { ch.clear(); ch.setOption(opt); }
    return ch;
}
function ecDel(id) { if (EC_REG[id]) { EC_REG[id].dispose(); delete EC_REG[id]; } }
function ecResizeAll() { Object.keys(EC_REG).forEach(function (k) { if (EC_REG[k]) EC_REG[k].resize(); }); }
window.addEventListener('resize', ecResizeAll);

// 柱形图 option：labels=横轴文本 values=数量；opts={colors[], unit（数量后缀）, pctMax100（纵轴 0-100%）}
function ecBarOpt(labels, values, opts) {
    opts = opts || {};
    var colors = opts.colors || ['#667eea'];
    var y = { type: 'value', minInterval: 1, axisLabel: { color: '#888' }, splitLine: { lineStyle: { color: '#eef1f8' } } };
    if (opts.pctMax100) { y.max = 100; y.axisLabel = { color: '#888', formatter: '{value}%' }; }
    return {
        grid: { left: 48, right: 20, top: 30, bottom: labels.length > 8 ? 66 : 40 },
        tooltip: { trigger: 'axis' },
        xAxis: { type: 'category', data: labels.map(String),
                 axisLabel: { color: '#888', fontSize: 11, interval: 0, rotate: labels.length > 8 ? 32 : 0 },
                 axisLine: { lineStyle: { color: '#dfe4f3' } }, axisTick: { show: false } },
        yAxis: y,
        series: [{ type: 'bar', barWidth: '55%',
                   data: values.map(function (v, i) { return { value: v, itemStyle: { color: colors[i % colors.length], borderRadius: [4, 4, 0, 0] } }; }),
                   label: { show: true, position: 'top', color: '#666', fontSize: 11,
                            formatter: function (p) { return p.value || p.value === 0 ? String(p.value) + (opts.unit || '') : ''; } } }]
    };
}

// 环形饼图 option：items=[{name,value,color}]；opts={unit, centerMain, centerSub, labelVal(标签是否带数量，默认带), fmtLabel(自定义标签 function(p))}
// 引线标签（chartCanvas 式）：名称：数量（占比）；点击扇区由调用方 chart.on('click', ...) 接管
function ecPieOpt(items, opts) {
    opts = opts || {};
    var unit = opts.unit || '';
    return {
        tooltip: { trigger: 'item', formatter: function (p) { return p.name + '：' + p.value + unit + '（' + p.percent + '%）'; } },
        legend: { type: 'scroll', bottom: 4, textStyle: { fontSize: 11 }, itemWidth: 12, itemHeight: 8 },
        title: { text: opts.centerMain || '', subtext: opts.centerSub || '', left: 'center', top: '40%',
                 textStyle: { fontSize: 22, fontWeight: 'bold', color: '#333' }, subtextStyle: { fontSize: 12, color: '#98a0b3' } },
        series: [{ type: 'pie', radius: ['40%', '64%'], center: ['50%', '45%'],
                   data: items.map(function (it) {
                       return { name: it.name, value: it.value,
                                itemStyle: { color: it.color, borderColor: it.sel ? '#333' : '#fff', borderWidth: it.sel ? 2.4 : 1 } };
                   }),
                   label: { fontSize: 12, formatter: opts.fmtLabel || function (p) { return p.name + '：' + p.value + unit + '（' + p.percent + '%）'; } },
                   labelLine: { length: 16, length2: 12 },
                   emphasis: { scaleSize: 4 } }]
    };
}
