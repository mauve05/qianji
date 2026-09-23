/**
 * 题次切换条 + 题次管理弹窗（scan.php / omr_scan.php 共用，与 project_view 胶囊条同款）
 *
 * 页面需在题次项目（multi/raise/omr）下注入：
 *   var RS_DATA = { pid: 项目ID, page: 'scan.php'|'omr_scan.php', label: '题次'|'项次',
 *                   rounds: [..], current: N, titles: {round_no: title}, admin: true|false };
 * 并放置容器：<span id="rsSwitch"></span>（胶囊条/展开面板/管理弹窗均由本模块动态创建渲染）
 *
 * 功能：◀▶ 平移显示窗口（仅显示，点击胶囊才切换并携带页面现有参数）+ ＋ 新增并切换
 *      + ⏳ 展开全部面板点选 + ⚙ 管理弹窗（改名/↑↓调序/删除（有记录需登录密码）/导入导出）
 * 权限：admin=false（无 operate 权限）时仅显示胶囊切换，隐藏 ＋/⏳/⚙/❓ 与管理弹窗
 */
(function () {
    'use strict';
    var D = window.RS_DATA;
    if (!D || !D.rounds || !D.rounds.length) return;
    var pid = D.pid, page = D.page || 'scan.php', label = D.label || '题次';
    var ADMIN = !!D.admin;
    var rounds = D.rounds.map(function (v) { return parseInt(v, 10); });
    var titles = D.titles || {};
    var counts = {};            // {round_no: 登记记录数}（round_list 返回，删除时判断是否需要密码）
    var cur = parseInt(D.current, 10) || 0;
    var CHIP_SIZE = 3;          // 胶囊默认显示个数
    var CHIP_WIN = 0;           // 显示窗口起点
    var PANEL = false;          // ⏳ 展开全部面板

    // 初始窗口定位：当前题次不在前 3 个时，定位到包含它的窗口
    (function locate() {
        var ci = rounds.indexOf(cur);
        if (ci > CHIP_SIZE - 1) {
            CHIP_WIN = ci - 2;
            var mw = rounds.length - CHIP_SIZE;
            if (CHIP_WIN > mw) CHIP_WIN = mw;
            if (CHIP_WIN < 0) CHIP_WIN = 0;
        }
    })();

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function rTitle(rn) { return titles[rn] || ''; }
    function roundUrl(rn) {   // 保留页面现有参数（如补登日期），仅替换 round
        var u = new URL(location.href);
        u.searchParams.set('round', rn);
        return u.toString();
    }
    function toast(msg, kind) {
        if (typeof showToast === 'function') { showToast(msg, kind || ''); return; }
        if (typeof window.toast === 'function') { window.toast(msg); return; }
        alert(msg);
    }
    function api(type, params, cb) {
        fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'type=' + type + '&project_id=' + pid + (params ? '&' + params : '')
        }).then(function (r) { return r.json(); }).then(cb).catch(function () { toast('网络错误，请重试', 'warn'); });
    }

    // ---- DOM 构建 ----
    var host = null, panelEl = null;
    function buildDom() {
        host = document.getElementById('rsSwitch');
        if (!host) return false;
        host.style.display = 'flex';
        host.style.gap = '8px';
        host.style.alignItems = 'center';
        host.style.flexWrap = 'wrap';
        panelEl = document.createElement('div');
        panelEl.id = 'rsPanel';
        panelEl.style.cssText = 'display:none;flex-basis:100%;margin-top:10px;padding:10px 12px;border:1px solid #d0d4e8;border-radius:8px;background:#f8f9ff;';
        host.appendChild(panelEl);
        if (ADMIN) buildModal();
        return true;
    }
    function buildModal() {   // ⚙ 管理弹窗（仿 project_view #roundAdminModal）
        var mask = document.createElement('div');
        mask.className = 'modal-mask';
        mask.id = 'rsAdminModal';
        mask.onclick = function (e) { if (e.target === mask) mask.classList.remove('show'); };
        mask.innerHTML = '<div class="modal" style="max-width:760px;">'
            + '<h3>⚙ ' + esc(label) + '管理</h3>'
            + '<div style="max-height:50vh;overflow:auto;">'
            + '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
            + '<thead><tr style="color:#888;font-size:12px;border-bottom:1px solid #e5e8f5;">'
            + '<th style="padding:6px 4px;text-align:left;white-space:nowrap;">序号</th>'
            + '<th style="padding:6px 4px;text-align:left;">名称（改后失焦自动保存）</th>'
            + '<th style="padding:6px 4px;text-align:left;white-space:nowrap;">操作</th>'
            + '<th style="padding:6px 4px;text-align:left;white-space:nowrap;">记录数</th>'
            + '</tr></thead><tbody id="rsAdminList"></tbody></table></div>'
            + '<div style="margin-top:12px;padding-top:10px;border-top:1px dashed #e0e4f0;display:flex;flex-direction:column;gap:8px;">'
            + '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">'
            + '<button type="button" class="btn btn-sm btn-success" onclick="rsAdd()">＋ 新增' + esc(label) + '</button>'
            + '<a class="btn btn-sm btn-outline" href="api.php?type=round_export&project_id=' + pid + '" title="导出全部' + esc(label) + '名称为 CSV（每行一个）">📤 导出</a>'
            + '<span style="color:#999;font-size:12px;">导入：每行一个名称，第 1 行=第 1 ' + esc(label) + '…仅替换名称/新增，不删除数据</span>'
            + '</div>'
            + '<div style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;">'
            + '<textarea id="rsImportText" placeholder="在此粘贴名称清单（每行一个），或点右侧选择 txt/csv 文件…" style="flex:1;min-width:220px;height:64px;border:1px solid #d0d4e8;border-radius:6px;padding:6px 8px;font-size:12px;resize:vertical;"></textarea>'
            + '<div style="display:flex;flex-direction:column;gap:6px;">'
            + '<label class="btn btn-sm btn-outline" style="cursor:pointer;">📂 选择文件<input type="file" accept=".txt,.csv,text/plain,text/csv" style="display:none;" onchange="rsImportFile(this)"></label>'
            + '<button type="button" class="btn btn-sm" onclick="rsImportDo()">📥 导入</button>'
            + '</div></div></div>'
            + '<div class="modal-actions"><button type="button" class="btn" onclick="rsCloseAdmin()">关闭</button></div>'
            + '</div>';
        document.body.appendChild(mask);
    }

    // ---- 渲染 ----
    function render() {
        if (!host) return;
        var maxWin = Math.max(0, rounds.length - CHIP_SIZE);
        if (CHIP_WIN > maxWin) CHIP_WIN = maxWin;
        var h = [];
        h.push('<span style="color:#555;font-weight:bold;">' + esc(label) + '切换：</span>');
        if (ADMIN && typeof toggleHint === 'function') {
            h.push('<button type="button" class="hint-q" onclick="rsHint(event)" title="使用说明">?</button>');
        }
        if (rounds.length > CHIP_SIZE) {
            h.push('<a class="btn btn-sm btn-outline" href="javascript:void(0)" onclick="rsChipMove(-1)" title="向左平移显示"' + (CHIP_WIN <= 0 ? ' style="visibility:hidden;"' : '') + '>◀</a>');
        }
        rounds.slice(CHIP_WIN, CHIP_WIN + CHIP_SIZE).forEach(function (rn) {
            var t = rTitle(rn);
            h.push('<a class="btn btn-sm' + (rn === cur ? '' : ' btn-outline') + '" href="' + esc(roundUrl(rn)) + '" title="第' + rn + ' ' + esc(label) + (t ? '：' + esc(t) : '') + '">' + (t ? esc(t) : rn) + '</a>');
        });
        if (rounds.length > CHIP_SIZE) {
            h.push('<a class="btn btn-sm btn-outline" href="javascript:void(0)" onclick="rsChipMove(1)" title="向右平移显示"' + (CHIP_WIN >= maxWin ? ' style="visibility:hidden;"' : '') + '>▶</a>');
        }
        if (ADMIN) {
            h.push('<a class="btn btn-sm btn-success" href="javascript:void(0)" onclick="rsAddGo()" title="新增一' + esc(label) + '并切换过去">＋</a>');
            if (rounds.length > CHIP_SIZE) {
                h.push('<a class="btn btn-sm btn-outline" href="javascript:void(0)" onclick="rsTogglePanel()" title="' + (PANEL ? '收起全部' + esc(label) + '面板' : '展开全部' + esc(label) + '，方便点选切换') + '">' + (PANEL ? '⏵ 收起' : '⏳ 展开') + '</a>');
            }
            h.push('<a class="btn btn-sm btn-outline" href="javascript:void(0)" onclick="rsOpenAdmin()" title="重命名 / 调整顺序 / 删除（有记录需密码）/ 导入导出">⚙ 管理</a>');
        }
        var ct = rTitle(cur);
        h.push('<span style="color:#667eea;font-weight:bold;">当前：第 ' + cur + ' ' + esc(label) + (ct ? '：' + esc(ct) : '') + '</span>');
        // 展开面板：全部胶囊网格（仿打卡日历展开效果），当前题次高亮
        var p = [];
        if (PANEL) {
            p.push('<div style="display:flex;flex-wrap:wrap;gap:8px;">');
            rounds.forEach(function (rn) {
                var t = rTitle(rn);
                p.push('<a class="btn btn-sm' + (rn === cur ? '' : ' btn-outline') + '" href="' + esc(roundUrl(rn)) + '" title="第' + rn + ' ' + esc(label) + (t ? '：' + esc(t) : '') + '"' + (rn === cur ? ' style="background:#667eea;border-color:#667eea;"' : '') + '>' + (t ? esc(t) : rn) + '</a>');
            });
            p.push('</div>');
        }
        if (panelEl) {
            panelEl.innerHTML = p.join('');
            panelEl.style.display = PANEL ? '' : 'none';
        }
        host.innerHTML = h.join('');
        host.appendChild(panelEl);   // innerHTML 会清掉 panelEl，重新挂回
    }

    // ---- 交互（挂 window 供内联 onclick 调用） ----
    window.rsChipMove = function (d) {
        var mw = Math.max(0, rounds.length - CHIP_SIZE);
        CHIP_WIN = Math.min(mw, Math.max(0, CHIP_WIN + d));
        render();
    };
    window.rsTogglePanel = function () { PANEL = !PANEL; render(); };
    window.rsHint = function (ev) {
        if (typeof toggleHint === 'function') {
            toggleHint(ev, '<b>' + esc(label) + '切换说明</b><br>· ◀▶ 平移显示窗口（仅显示，点击胶囊才切换到该' + esc(label) + '）<br>· ＋ 新增一' + esc(label) + '并自动切换；⏳ 展开全部点选<br>· ⚙ 管理弹窗：改名 / ↑↓调序 / 删除（有记录需登录密码）/ 导入导出名称<br>· 各' + esc(label) + '数据分' + esc(label) + '保存互不影响；无管理权限时仅显示胶囊切换');
        }
    };
    window.rsOpenAdmin = function () {
        var m = document.getElementById('rsAdminModal');
        if (m) m.classList.add('show');
        rsReload();
    };
    window.rsCloseAdmin = function () {
        var m = document.getElementById('rsAdminModal');
        if (m) m.classList.remove('show');
    };
    function rsReload() {   // 拉取题次与各次记录数后渲染管理列表
        api('round_list', '', function (d) {
            if (!d.success) { toast(d.message || '获取失败', 'warn'); return; }
            rounds = (d.rounds || []).map(function (v) { return parseInt(v, 10); });
            titles = d.titles || {};
            counts = d.counts || {};
            rsRenderList();
            render();
        });
    }
    function rsRenderList() {
        var box = document.getElementById('rsAdminList');
        if (!box) return;
        var n = rounds.length;
        box.innerHTML = rounds.map(function (rn, i) {
            var t = rTitle(rn), cnt = counts[rn] || 0;
            return '<tr>'
                + '<td style="white-space:nowrap;font-weight:bold;color:#667eea;">第 ' + rn + ' ' + esc(label) + '</td>'
                + '<td><input type="text" class="form-control" value="' + esc(t) + '" placeholder="未命名（显示序号）" maxlength="30" style="width:100%;" onchange="rsSave(' + rn + ', this.value)" onkeydown="if(event.key===\'Enter\'){this.blur();}"></td>'
                + '<td style="white-space:nowrap;">'
                + '<button type="button" class="btn btn-sm btn-outline"' + (i === 0 ? ' disabled' : '') + ' onclick="rsMove(' + rn + ',\'up\')" title="与上一位交换顺序">↑</button> '
                + '<button type="button" class="btn btn-sm btn-outline"' + (i === n - 1 ? ' disabled' : '') + ' onclick="rsMove(' + rn + ',\'down\')" title="与下一位交换顺序">↓</button> '
                + '<button type="button" class="btn btn-sm btn-outline"' + (n <= 1 ? ' disabled' : '') + ' onclick="rsDel(' + rn + ')" title="删除该' + esc(label) + '（有登记记录需登录密码）" style="color:#e74c3c;">×</button>'
                + '</td>'
                + '<td style="white-space:nowrap;color:' + (cnt > 0 ? '#e67e22' : '#999') + ';font-size:12px;">' + (cnt > 0 ? cnt + ' 条记录' : '无记录') + '</td>'
                + '</tr>';
        }).join('');
    }
    window.rsSave = function (n, title) {
        title = String(title || '').trim();
        api('round_rename', 'round=' + n + '&title=' + encodeURIComponent(title), function (d) {
            if (!d.success) { toast(d.message || '保存失败', 'warn'); return; }
            titles = d.titles || {};
            rsRenderList(); render();
            toast(title ? '已命名第 ' + n + ' ' + label + '：' + title : '已清除第 ' + n + ' ' + label + '标题');
        });
    };
    window.rsMove = function (n, dir) {
        api('round_reorder', 'round=' + n + '&dir=' + dir, function (d) {
            if (!d.success) { toast(d.message || '调整失败', 'warn'); return; }
            rounds = (d.rounds || []).map(function (v) { return parseInt(v, 10); });
            titles = d.titles || {};
            rsRenderList(); render();
        });
    };
    window.rsAdd = function () {   // 弹窗内新增：不跳页
        api('round_add', '', function (d) {
            rounds = (d.rounds || []).map(function (v) { return parseInt(v, 10); });
            titles = d.titles || {};
            rsRenderList(); render();
            toast('已新增第 ' + (d.current || rounds[rounds.length - 1]) + ' ' + label);
        });
    };
    window.rsAddGo = function () {   // 胶囊条 ＋：新增后切换到新题次
        api('round_add', '', function (d) {
            location.href = roundUrl(d.current || (rounds[rounds.length - 1] + 1));
        });
    };
    window.rsDel = function (n) {
        if (rounds.length <= 1) { toast('至少保留一次，不能删除', 'warn'); return; }
        var cnt = counts[n] || 0;
        if (!confirm('删除第 ' + n + ' ' + label + (cnt > 0 ? '？该次有 ' + cnt + ' 条登记记录将一并删除，其后' + label + '自动前移！' : '？其后' + label + '自动前移。'))) return;
        var pwd = '';
        if (cnt > 0) {
            pwd = prompt('该次有 ' + cnt + ' 条登记记录，请输入当前账号登录密码确认删除：');
            if (pwd === null) return;
        }
        rsDelReq(n, pwd, 0);
    };
    function rsDelReq(n, pwd, retried) {
        api('round_delete', 'round=' + n + (pwd ? '&password=' + encodeURIComponent(pwd) : ''), function (d) {
            if (!d.success) {
                if (d.need_pwd && !retried) {   // 服务端检测到有记录（如打开弹窗后新登记）：补输密码重试一次
                    var p2 = prompt(d.message || '请输入当前账号登录密码：');
                    if (p2 === null) return;
                    rsDelReq(n, p2, 1);
                    return;
                }
                toast(d.message || '删除失败', 'warn');
                return;
            }
            rounds = (d.rounds || []).map(function (v) { return parseInt(v, 10); });
            titles = d.titles || {};
            rsRenderList(); render();
            toast('已删除第 ' + n + ' ' + label);
            if (n === cur) {   // 删除的是当前题次：跳到剩余题次（保留页面其他参数）
                location.href = roundUrl(d.current || 1);
            }
        });
    }
    window.rsImportDo = function () {
        var ta = document.getElementById('rsImportText');
        var txt = ta ? String(ta.value || '').trim() : '';
        if (!txt) { toast('请先粘贴名称清单（每行一个）或选择文件', 'warn'); return; }
        if (!confirm('按行导入名称：仅替换同名' + label + '的名称/追加新' + label + '，不删除任何数据。继续？')) return;
        api('round_import', 'titles=' + encodeURIComponent(txt), function (d) {
            if (!d.success) { toast(d.message || '导入失败', 'warn'); return; }
            rounds = (d.rounds || []).map(function (v) { return parseInt(v, 10); });
            titles = d.titles || {};
            rsRenderList(); render();
            toast('导入完成：更新 ' + (d.applied || 0) + ' 个、新增 ' + (d.added || 0) + ' 个');
        });
    };
    window.rsImportFile = function (input) {
        var f = input.files && input.files[0];
        if (!f) return;
        if (f.size > 100 * 1024) { toast('文件过大（限 100KB）', 'warn'); input.value = ''; return; }
        var fr = new FileReader();
        fr.onload = function () {
            var ta = document.getElementById('rsImportText');
            if (ta) ta.value = String(fr.result || '');
        };
        fr.readAsText(f, 'UTF-8');
        input.value = '';
    };

    // ---- 初始化 ----
    function init() { if (buildDom()) render(); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
