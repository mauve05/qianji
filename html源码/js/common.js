/**
 * 潜记单用户版 - 公共工具库
 * 对应原 includes/functions.php 中的通用函数（去除登录/权限/学校部分）
 * 依赖：db.js
 */
(function (global) {
'use strict';

// ===== 基础工具 =====
function h(s) {   // htmlspecialchars 等价
    return String(s === null || s === undefined ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/** URL 查询参数（原 $_GET） */
function qget(name, dft) {
    var v = new URLSearchParams(location.search).get(name);
    return v === null ? (dft === undefined ? '' : dft) : v;
}
function qint(name, dft) {
    var v = parseInt(qget(name, ''), 10);
    return isNaN(v) ? (dft || 0) : v;
}

/** 确认框（Playwright 兼容：优先自定义，回退 confirm） */
function askConfirm(msg) {
    if (typeof window.confirm === 'function') return window.confirm(msg);
    return Promise.resolve(true);
}

// ===== 评价模式定义（原 get_eval_modes）=====
function get_eval_modes() {
    return {
        smile:   { name: '笑脸', options: { '😊': '笑脸', '😐': '一般', '😞': '加油' } },
        points:  { name: '十分', options: { '0': '0', '1': '1', '2': '2', '3': '3', '4': '4', '5': '5', '6': '6', '7': '7', '8': '8', '9': '9', '10': '10' } },
        score:   { name: '数值', options: null },
        grade:   { name: '优良', options: { '优': '优', '良': '良', '合格': '合格', '不合格': '不合格', '待定': '待定', '特殊': '特殊' } },
        tf:      { name: '对错', options: { '√': '√', '×': '×', '待定': '待定' } },
        star:    { name: '星级', options: { '★': '一星', '★★': '二星', '★★★': '三星', '★★★★': '四星', '★★★★★': '五星' } },
        comment: { name: '评语', options: null },
        abcd:    { name: '答题', options: { 'A': 'A', 'B': 'B', 'C': 'C', 'D': 'D' } }
    };
}

function build_custom_mode_info(cid, name, options_raw) {
    var opts = String(options_raw || '').replace(/\r/g, '').split('\n')
        .map(function (v) { return v.trim(); })
        .filter(function (v, i, a) { return v !== '' && a.indexOf(v) === i; });
    return {
        name: name + '（自定义）',
        options: opts.length ? opts.reduce(function (o, v) { o[v] = v; return o; }, {}) : null,
        custom: true,
        cid: cid
    };
}

/** 评价模式键解析（系统键或自定义 c+编号）→ Promise<info|null> */
function eval_mode_info(key) {
    key = String(key === undefined || key === null ? '' : key);
    if (key.length > 1 && key[0] === 'c' && /^\d+$/.test(key.slice(1))) {
        var cid = parseInt(key.slice(1), 10);
        return DB.get('eval_modes', cid).then(function (row) {
            return row ? build_custom_mode_info(cid, row.name, row.options) : null;
        });
    }
    var modes = get_eval_modes();
    return Promise.resolve(modes[key] || null);
}

/** 全部可用评价模式（系统 + 自定义）→ Promise<{key: info}> */
function all_eval_modes() {
    return DB.all('eval_modes').then(function (rows) {
        rows.sort(function (a, b) { return (a.sort || 0) - (b.sort || 0) || a.id - b.id; });
        var modes = get_eval_modes();
        rows.forEach(function (r) { modes['c' + r.id] = build_custom_mode_info(r.id, r.name, r.options); });
        return modes;
    });
}

function eval_badge_class(key) {
    return /^c\d+$/.test(String(key)) ? 'badge-custom' : 'badge-' + h(String(key));
}

// ===== 项目模式 =====
function project_is_rounded(project) {
    return ['multi', 'quiz', 'raise', 'omr'].indexOf(String(project && project.mode || 'count')) >= 0;
}

// ===== 项目轮次列表（无轮次时自动补第 1 次；原 project_rounds_list）=====
function project_rounds_list(project_id) {
    return DB.by('project_rounds', 'project_id', project_id).then(function (rows) {
        rows.sort(function (a, b) { return a.round_no - b.round_no; });
        if (rows.length) return rows.map(function (r) { return r.round_no; });
        return DB.insert('project_rounds', {
            project_id: project_id, round_no: 1, title: null, correct_opts: null, created_at: DB.nowStr()
        }).then(function () { return [1]; });
    });
}

// ===== 班级/个人码编解码（兼容潜记APP）=====
function escBjdl(v) {
    return String(v === undefined || v === null ? '' : v).replace(/-/g, '－').replace(/\|/g, '｜');
}
function build_class_import_content(students) {
    var parts = students.map(function (s) {
        return [escBjdl(s.student_no), escBjdl(s.name), String(s.seat_no || '').trim(), escBjdl(s.remark || '')].join('-');
    });
    return 'bjdl' + parts.join('|');
}
function parse_class_import_content(content) {
    content = String(content || '').trim();
    if (content.indexOf('bjdl') !== 0) return null;
    var body = content.slice(4);
    var students = [];
    body.split('|').forEach(function (item) {
        if (item === '') return;
        var f = item.split('-');
        if (f.length < 2) return;
        if (f.length >= 4 && (f[2] === '' || /^\d+$/.test(f[2]))) {
            students.push({ student_no: f[0], name: f[1], seat_no: f[2], remark: f.slice(3).join('-') });
        } else {
            students.push({ student_no: f[0], name: f[1], remark: f.length > 2 ? f.slice(2).join('-') : '', seat_no: '' });
        }
    });
    return students.length ? students : null;
}
function build_student_qr_content(student_no) { return 'djxh' + student_no; }
function parse_student_qr_content(content) {
    content = String(content || '').trim();
    return content.indexOf('djxh') === 0 ? content.slice(4) : null;
}

/** 8 位班级导入提取码 */
function gen_code8() {
    var s = '';
    for (var i = 0; i < 8; i++) s += Math.floor(Math.random() * 10);
    return s;
}

/** 班级导入载荷（每班一个稳定提取码，内容随名单刷新；原 get_class_import_payload） */
function get_class_import_payload(class_id, students) {
    var content = build_class_import_content(students);
    return DB.first('import_payloads', 'class_id', class_id).then(function (row) {
        if (row) {
            row.content = content;
            row.updated_at = DB.nowStr();
            return DB.update('import_payloads', row).then(function () { return row; });
        }
        var nw = { class_id: class_id, code: gen_code8(), content: content, created_at: DB.nowStr(), updated_at: null };
        return DB.insert('import_payloads', nw);
    });
}

// ===== 举牌模式码本（原 raise_patterns_hex，完整迁移）=====
var RAISE_HEX = '2c2c0101aae20200431ca0010630400090ef0d5043041403061c60cd010082ce388000197c30600044d20a0888c73041400c0904114306580041786d0001294040406d30c14418290608a1059932801230c62080823e792008800005431e3e01888071a2180092e3cd141010eaa00410c00100c79e9220082abc24100a8be202020a7090008e73b290300643412c01c1070c006c35c18314804570004b82610c30259c79200081650610e1141c00c3b400c30d8706820093f072003a782220014dfe1c06102cc418c1c21601831c311d103884390ca00873c74800945f9800023cfd3182000e3c400268821406197c408220bbe009209f0b4003093de78001b0cf804201b3a09840a3dbac200570ee0ac0441a0b058c83ac98609281ed06082cbcba000863de5800a06401509b690516088f2000171f73ac1210c3c00123c737d16c1040e300388a3cf584002e1a01a0c773e3001ce1c602e1c38022c4b8739e6820871ef9841050c60401a3f7c401087b3c3940839feb0110098c30359704c10671e19c480227d0cf921047870c301ae4c09413c78f0d208e10554e5073fcf80024c94d340a396880029fff3d610580a00231df37000c9a6f038e01e3d1db0e0c32c751210d3ff0480aa2bc106414fbcf4400c30c386b06dc24c22c3ebe71c0482019a00aff8694078cba03cc38f8ff8d40281ffe200231ec32804bb063c608bf7c70c39a00e15c447bffb0a08600328451ff043ef9c30c61df01310c01e0afffa08e3a60e31c780d1a80833ef88614017dfbe3050467ef38004b60208b9e7fec3050e3e873c431c78048c79efd718328e37df048075d718e8073203869b3ff610c987c17102ce3f027879f38f398071c3c2d00aabfe71c6323845100f3dfcf008747d3c738094fe7cd50033824828fbffefb80029dc30248ff8e7845c752c9025bef84db019f77c5069ae9cf30079f1cd1069a7bc02c63be7fc71210cfffb642023fc6021b3e3c73e30360c5829cffb21cf0d3cde306307bcf3873055d618063effb00711f72430f9ef9df78c30c3fcf0023af803831fff3cf04b2cfbef87982426b870fbc541a21f7ff31ac15e3f09060f7f9f3072c3d8071f3cf9f680e8b3ec318632fff38f38e30fc3cf3061cf4036d3ede48039fffe3de00cbff70b00d7fb20027ffdc19c05ffff75c005d8778750fbcf8c8a8effd5002efbff2ca0877fdd020de7df3025b3df71f402e7ae387703fef3dd403afe683107fff78a09a6c370d783fe25049ffffdf6c1871df6da05c7701467bff0039ef9ffffbac0157cf78e933cdb0eb0ebed41d64b7fef8243afbffaeb08a9ffff14013bc80b39ffe3bef1e218c09f3df78f304fbcfdf583782f9f18f70cfecb8e3c3ffe3c035f7e3dc388ffcd71b0fbefe7de4865fbeb802fbbe6930df7ff6832fb8df12c17df9cf878f3df7586d34ff2602f3ffde3cf9c3cfbe321d7cffe8a0eb3073df787bffde310f8fb2c26de7fffef2111e3b8eb8effb4c74e7def3e79e437cf3e38f9fff7c0ac3ffbc3492fa20c7dfffefbc7b82efffee3a80fffe74c60fffedb028ffdf30c87fff36883dff1cf3e23de7aeb8673cb836dfffcc07de7b9e38f8eff';
function raise_pattern_code(no) {
    no = parseInt(no, 10);
    if (no < 1 || no > 255) return 0;
    return parseInt(RAISE_HEX.substr((no - 1) * 9, 9), 16);
}
function raise_rot_cw(bits36) {
    var out = 0;
    for (var r = 0; r < 6; r++) for (var c = 0; c < 6; c++) {
        var v = (bits36 >> (c * 6 + r)) & 1;
        out |= v << ((5 - r) * 6 + c);
    }
    return out;
}
/** 编号 → 6x6 位阵列（row-major 36 元素，1=白格 0=黑格） */
function raise_pattern_bits(no) {
    var code = raise_pattern_code(no), bits = [];
    for (var r = 0; r < 6; r++) for (var c = 0; c < 6; c++) bits[r * 6 + c] = (code >> ((5 - r) * 6 + c)) & 1;
    return bits;
}

// ===== 通用 DOM 辅助 =====
function escAttr(s) { return h(s); }
function fmtBytes(n) {
    if (n > 1048576) return (n / 1048576).toFixed(1) + 'MB';
    if (n > 1024) return (n / 1024).toFixed(1) + 'KB';
    return n + 'B';
}
/** 下载文本为文件 */
function downloadText(filename, text, mime) {
    var blob = new Blob(['\ufeff' + text], { type: (mime || 'text/plain') + ';charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}
/** Promise 顺序执行列表 fn(item) → Promise */
function series(list, fn) {
    return list.reduce(function (p, item) {
        return p.then(function () { return fn(item); });
    }, Promise.resolve());
}

global.QJ = {
    h: h, qget: qget, qint: qint, askConfirm: askConfirm,
    get_eval_modes: get_eval_modes, eval_mode_info: eval_mode_info, all_eval_modes: all_eval_modes,
    eval_badge_class: eval_badge_class, build_custom_mode_info: build_custom_mode_info,
    project_is_rounded: project_is_rounded, project_rounds_list: project_rounds_list,
    build_class_import_content: build_class_import_content, parse_class_import_content: parse_class_import_content,
    build_student_qr_content: build_student_qr_content, parse_student_qr_content: parse_student_qr_content,
    get_class_import_payload: get_class_import_payload, gen_code8: gen_code8,
    raise_pattern_code: raise_pattern_code, raise_rot_cw: raise_rot_cw, raise_pattern_bits: raise_pattern_bits,
    escAttr: escAttr, fmtBytes: fmtBytes, downloadText: downloadText, series: series
};
// 同名暴露为全局：页面存在裸调用 qint()/qget() 等（SW importScripts 环境无 window，故挂 self）
for (var _qjk in global.QJ) { if (global[_qjk] === undefined) global[_qjk] = global.QJ[_qjk]; }
})(typeof self !== 'undefined' ? self : window);

// ===== 登录门禁 + 数据备份/恢复（单机版全页通用；SW importScripts 环境自动跳过） =====
(function (global) {
'use strict';
// 仅浏览器页面环境执行（SW 中无 sessionStorage/location.href 导航能力）
var IS_PAGE = typeof window !== 'undefined' && typeof sessionStorage !== 'undefined';
if (!IS_PAGE) return;

/** 登录口令哈希（同步、确定性；本机防误用级，非加密级） */
QJ.hashPass = function (user, pass) {
    var salt = 'qj_local::v1::';
    var s = salt + String(user) + '::' + String(pass);
    var h1 = 0x811c9dc5, h2 = 0x1b873593;
    for (var r = 0; r < 3000; r++) {
        for (var i = 0; i < s.length; i++) {
            var c = s.charCodeAt(i);
            h1 = ((h1 ^ c) * 0x01000193) >>> 0;
            h2 = ((h2 ^ (h1 + c + r)) * 0x85ebca6b) >>> 0;
            h1 = (h1 ^ (h2 >>> 7)) >>> 0;
        }
        s = salt + h1.toString(36) + h2.toString(36);
    }
    return ('0000000' + h1.toString(16)).slice(-8) + ('0000000' + h2.toString(16)).slice(-8);
};

/** 是否已登录：localStorage qj_auth {exp} 未过期（不勾保持登录=12小时，勾选=30天；跨标签页共享） */
QJ.authed = function () {
    try {
        var raw = localStorage.getItem('qj_auth');
        if (!raw) return false;
        var o = JSON.parse(raw);
        return !!(o && o.exp && Date.now() < o.exp);
    } catch (e) { return false; }
};
/** 退出登录（清除会话标记） */
QJ.logout = function () {
    try { localStorage.removeItem('qj_auth'); sessionStorage.removeItem('qj_auth'); } catch (e) {}
};

/** 等待数据层就绪（防御 db.js 加载失败/旧缓存；超时以 err 回调） */
QJ.whenDB = function (cb, timeoutMs) {
    var n = 0, max = Math.ceil((timeoutMs || 8000) / 100);
    (function poll() {
        if (typeof DB !== 'undefined' && DB && typeof DB.find === 'function') { cb(null); return; }
        if (++n > max) { cb(new Error('数据层加载超时')); return; }
        setTimeout(poll, 100);
    })();
};

/** 备份数据：整库（含图片文件）导出为 JSON 文件下载 */
QJ.backupData = function () {
    return DB.exportJSON().then(function (obj) {
        var d = new Date();
        function p2(n) { return n < 10 ? '0' + n : '' + n; }
        var name = '潜记数据备份_' + d.getFullYear() + p2(d.getMonth() + 1) + p2(d.getDate()) + '_' + p2(d.getHours()) + p2(d.getMinutes()) + p2(d.getSeconds()) + '.json';
        QJ.downloadText(name, JSON.stringify(obj), 'application/json');
        return name;
    });
};

/** 恢复数据：选择备份 JSON → 确认覆盖 → 导入（保留当前登录账号）→ 刷新 */
QJ.restoreData = function () {
    var inp = document.createElement('input');
    inp.type = 'file';
    inp.accept = '.json,application/json';
    inp.style.display = 'none';
    document.body.appendChild(inp);
    inp.addEventListener('change', function () {
        var f = inp.files && inp.files[0];
        if (!f) { inp.remove(); return; }
        var fr = new FileReader();
        fr.onload = function () {
            inp.remove();
            var txt = String(fr.result || '');
            if (txt.charCodeAt(0) === 0xFEFF) txt = txt.slice(1);   // 去 BOM
            var obj = null;
            try { obj = JSON.parse(txt); } catch (e) { showToast('备份文件格式不正确', 'error', true); return; }
            if (!obj || obj.app !== 'qj_local' || !obj.tables) { showToast('不是本系统导出的备份文件', 'error', true); return; }
            if (!confirm('导入备份将【覆盖】当前全部数据（未备份的现有数据将丢失，不可恢复）！\n\n确定继续导入吗？')) return;
            Promise.all([DB.settingsGet('auth_user', ''), DB.settingsGet('auth_pass', '')]).then(function (cur) {
                return DB.importJSON(obj).then(function () {
                    var p = Promise.resolve();
                    if (cur[0]) p = p.then(function () { return DB.settingsSet('auth_user', cur[0]); });
                    if (cur[1]) p = p.then(function () { return DB.settingsSet('auth_pass', cur[1]); });
                    return p;
                });
            }).then(function () {
                if (typeof showToast === 'function') showToast('数据已恢复，页面即将刷新', 'success', true);
                setTimeout(function () { location.reload(); }, 900);
            }).catch(function (e) {
                var m = '恢复失败：' + (e && e.message || e);
                if (typeof showToast === 'function') showToast(m, 'error', true); else alert(m);
            });
        };
        fr.onerror = function () { inp.remove(); if (typeof showToast === 'function') showToast('读取文件失败', 'error'); };
        fr.readAsText(f);
    });
    inp.click();
};

// ---- 门禁：未登录访问除首页(index.html)外任何页面 → 跳回首页登录 ----
var PAGE = (location.pathname.split('/').pop() || 'index.html');
if (PAGE !== 'index.html' && !QJ.authed()) {
    location.replace('index.html?needlogin=1');
}

// ---- 顶部菜单注入「💾 备份数据 / 📂 恢复数据」（PC 顶栏 + 移动端抽屉，全页生效） ----
function buildBackupBtns() {
    var wrap = document.createElement('span');
    wrap.className = 'nav-backup-btns';
    var b1 = document.createElement('a');
    b1.href = 'javascript:void(0);';
    b1.textContent = '💾 备份数据';
    b1.title = '导出全部数据为备份文件（建议经常备份；换设备/清缓存前必做）';
    b1.onclick = function (e) {
        e.preventDefault();
        if (typeof DB === 'undefined') { alert('数据层未就绪，请刷新页面重试'); return; }
        QJ.backupData().then(function (n) { if (typeof showToast === 'function') showToast('已导出备份：' + n, 'success', true); });
    };
    var b2 = document.createElement('a');
    b2.href = 'javascript:void(0);';
    b2.textContent = '📂 恢复数据';
    b2.title = '导入此前导出的备份文件（覆盖当前全部数据）';
    b2.onclick = function (e) { e.preventDefault(); if (typeof DB !== 'undefined') QJ.restoreData(); };
    wrap.appendChild(b1); wrap.appendChild(b2);
    return wrap;
}
function injectNavBackup() {
    try {
        var menu = document.querySelector('.navbar-menu');
        if (menu && !menu.querySelector('.nav-backup-btns')) menu.appendChild(buildBackupBtns());
        var drawer = document.querySelector('.side-drawer .drawer-nav');
        if (drawer && !drawer.querySelector('.nav-backup-btns')) {
            var w2 = buildBackupBtns();
            w2.style.cssText = 'display:flex;gap:14px;padding:10px 18px;';
            drawer.appendChild(w2);
        }
    } catch (e) {}
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', injectNavBackup);
} else {
    injectNavBackup();
}
})(typeof self !== 'undefined' ? self : window);
