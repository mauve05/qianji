/** [组C] 积分接口（IndexedDB 实现）
 * 语义对照源版 qj/api.php points_* 各段 + includes/functions.php 积分辅助函数。
 * 单机版适配：无账号体系——跳过登录密码校验（import/clear_all 直接执行）、created_by=0、
 * 无权限过滤；抽中记录 settings key 用 pt_lottery_<cid>、自动积分指纹 ptauto_fp_<pid>
 * （与各页积分弹窗直读层共用同一状态）。
 */
(function (g) {
'use strict';
var AL = g.ApiLocal; var I = AL.I, S = AL.S, ok = AL.ok, fail = AL.fail;
function nowStr(d) { return AL.nowStr(d); }
function dateStr(d) { return AL.dateStr(d); }

// ===== 通用小工具 =====
function jsonParse(v, dft) { try { var o = JSON.parse(S(v)); return (o && typeof o === 'object') ? o : dft; } catch (e) { return dft; } }
function tsOf(s) { var t = new Date(S(s).replace(/-/g, '/')).getTime(); return isNaN(t) ? 0 : Math.floor(t / 1000); }
function tsStr(sec) { return nowStr(new Date(sec * 1000)); }
function isNumStr(v) { return /^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/i.test(S(v)); }
function intval(v) { var n = parseInt(v, 10); return isNaN(n) ? 0 : Math.trunc(n); }
function seatNum(v) { var n = parseInt(v, 10); return isNaN(n) ? 0 : n; }   // ≈ MySQL CAST(seat_no AS UNSIGNED)

// ===== 规则类型定义（原 points_rule_types_meta / points_rule_display_name） =====
var MODE_NAMES = { count: '仅登记一次', daily: '打卡', multi: '多次登记', quiz: '答题', raise: '举牌', omr: '答题卡' };
var RULE_META_ALL = {
    reg_ok:        { name: '登记成功', modes: ['count', 'daily', 'multi', 'quiz', 'raise', 'omr'], param: 'none', desc: '每登记成功 1 次（打卡=每天 / 题次模式=每题次 / 答题卡=每题次识别匹配）按分值加/减分' },
    timeout_miss:  { name: '超时未登记', modes: ['count', 'daily', 'multi', 'quiz', 'raise', 'omr'], param: 'none', need_late: 1, desc: '该范围首位登记超过补登记阈值后仍未登记的，按分值扣分（需项目补登记阈值>0；该范围无人登记则不判定）' },
    eval_done:     { name: '已评价', modes: ['count', 'daily', 'multi', 'quiz', 'raise', 'omr'], param: 'none', desc: '每有 1 条评价/批改记录按分值加/减分（答题卡模式=已批改出分）' },
    eval_opt:      { name: '评价为指定内容', modes: ['count', 'daily', 'multi', 'quiz', 'raise'], param: 'opt', desc: '评价内容等于指定值时按分值加/减分' },
    quiz_right:    { name: '答题正确', modes: ['quiz', 'raise'], param: 'none', desc: '题次已设正确答案且所选与之相符时按分值加/减分' },
    quiz_wrong:    { name: '答题错误', modes: ['quiz', 'raise'], param: 'none', desc: '题次已设正确答案且所选不符时按分值加/减分' },
    omr_per_right: { name: '答对每题加分', modes: ['omr'], param: 'none', desc: '每答对 1 题加/减分（=得分分子×分值，四舍五入；题数制=对题数，分值制=得分）' },
    omr_right_ge:  { name: '答对达到阈值', modes: ['omr'], param: 'num', desc: '答对题数/得分 ≥ 阈值时按分值加/减分' },
    omr_wrong_ge:  { name: '答错达到阈值', modes: ['omr'], param: 'num', desc: '答错数（分母-分子）≥ 阈值时按分值加/减分' },
    omr_score_lt:  { name: '得分低于阈值', modes: ['omr'], param: 'num', desc: '答对题数/得分 < 阈值时按分值加/减分' }
};
function ruleMeta(mode) {
    var out = {};
    Object.keys(RULE_META_ALL).forEach(function (k) {
        if (!mode || RULE_META_ALL[k].modes.indexOf(mode) >= 0) out[k] = RULE_META_ALL[k];
    });
    return out;
}
function ruleDisplayName(rtype, opt) {
    var m = RULE_META_ALL[rtype] || null;
    var name = m ? m.name : S(rtype);
    opt = S(opt).trim();
    if (rtype === 'eval_opt' && opt !== '') name = '评价为「' + opt.slice(0, 20) + '」';
    else if (rtype === 'omr_right_ge' && opt !== '') name = '答对≥' + intval(opt) + '题';
    else if (rtype === 'omr_wrong_ge' && opt !== '') name = '答错≥' + intval(opt) + '题';
    else if (rtype === 'omr_score_lt' && opt !== '') name = '得分<' + intval(opt);
    return name.slice(0, 40);
}
/** 评价模式解析（优先 common.js 全局；缺失时内联兜底） */
function evalModeInfo(key) {
    if (typeof g.eval_mode_info === 'function') return g.eval_mode_info(key);
    var SYS = {
        smile: { name: '笑脸', options: { '😊': '笑脸', '😐': '一般', '😞': '加油' } },
        points: { name: '十分', options: { '0': '0', '1': '1', '2': '2', '3': '3', '4': '4', '5': '5', '6': '6', '7': '7', '8': '8', '9': '9', '10': '10' } },
        score: { name: '数值', options: null },
        grade: { name: '优良', options: { '优': '优', '良': '良', '合格': '合格', '不合格': '不合格', '待定': '待定', '特殊': '特殊' } },
        tf: { name: '对错', options: { '√': '√', '×': '×', '待定': '待定' } },
        star: { name: '星级', options: { '★': '一星', '★★': '二星', '★★★': '三星', '★★★★': '四星', '★★★★★': '五星' } },
        comment: { name: '评语', options: null },
        abcd: { name: '答题', options: { 'A': 'A', 'B': 'B', 'C': 'C', 'D': 'D' } }
    };
    key = S(key);
    if (key.length > 1 && key[0] === 'c' && /^\d+$/.test(key.slice(1))) {
        return DB.get('eval_modes', parseInt(key.slice(1), 10)).then(function (row) {
            if (!row) return null;
            var opts = S(row.options).replace(/\r/g, '').split('\n').map(function (v) { return v.trim(); })
                .filter(function (v, i, a) { return v !== '' && a.indexOf(v) === i; });
            return { name: S(row.name) + '（自定义）', options: opts.length ? opts.reduce(function (o, v) { o[v] = v; return o; }, {}) : null, custom: true };
        });
    }
    return Promise.resolve(SYS[key] || null);
}

// ===== 数据装配辅助 =====
/** 本班可兑换礼品（enabled=1，sort 升序）→ [{id,name,cost}] */
async function giftsLoad(cid) {
    var rows = await DB.find('points_gifts', function (x) { return I(x.class_id) === cid && I(x.enabled) === 1; });
    rows.sort(function (a, b) { return (I(a.sort) - I(b.sort)) || (I(a.id) - I(b.id)); });
    return rows.map(function (x) { return { id: I(x.id), name: S(x.name), cost: I(x.cost) }; });
}
/** 积分项（空则播种默认 6 项）→ [{id,name,value,color,type,show_lot,show_quick}] */
async function itemsLoad() {
    var items = await DB.all('points_items');
    if (!items.length) {
        var defaults = [
            ['课堂纪律', 2, '#27ae60'], ['课堂互动', 2, '#3498db'], ['任务完成', 3, '#9b59b6'],
            ['创新表现', 3, '#e67e22'], ['合作协作', 2, '#16a085'], ['课堂违纪', -1, '#e74c3c']
        ];
        for (var i = 0; i < defaults.length; i++) {
            await DB.insert('points_items', { name: defaults[i][0], value: defaults[i][1], color: defaults[i][2], type: 1, show_lot: 1, show_quick: 1, sort: i });
        }
        items = await DB.all('points_items');
    }
    items.sort(function (a, b) { return (I(a.sort) - I(b.sort)) || (I(a.id) - I(b.id)); });
    return items.map(function (x) {
        return { id: I(x.id), name: S(x.name), value: I(x.value), color: S(x.color),
                 type: I(x.type) === 2 ? 2 : 1, show_lot: I(x.show_lot) === 1 ? 1 : 0, show_quick: I(x.show_quick) === 1 ? 1 : 0 };
    });
}
/** 删除学生后同编号新增自动找回积分（原 points_recover_orphan：只认编号快照不认姓名） */
async function recoverOrphan(cid) {
    var stus = (await DB.find('students', function (s) { return I(s.class_id) === cid; }))
        .sort(function (a, b) { return I(a.id) - I(b.id); });
    var byNo = {}, stuMap = {};
    stus.forEach(function (s) {
        stuMap[I(s.id)] = s;
        var no = S(s.student_no).trim();
        if (no !== '' && !byNo[no]) byNo[no] = I(s.id);
    });
    if (!stus.length) return;
    var logs = await DB.find('points_log', function (l) { return I(l.class_id) === cid; });
    var withLog = {};
    logs.forEach(function (l) { if (l.student_id) withLog[l.student_id] = true; });
    var targets = {};
    Object.keys(byNo).forEach(function (no) {
        var sid = byNo[no];
        if (!withLog[sid]) targets[no] = sid;   // 找回目标须为「新学生」：尚无任何流水
    });
    var moves = {};
    logs.forEach(function (l) {
        var no = S(l.student_no).trim();
        if (no === '' || !targets[no]) return;
        var old = I(l.student_id);
        var st = stuMap[old];
        if (st && !I(st.disabled)) return;      // 学生仍在（如转班）不并，防误并
        if (old === targets[no] || moves[old]) return;
        moves[old] = targets[no];
    });
    var olds = Object.keys(moves);
    for (var i = 0; i < olds.length; i++) {
        var rows = await DB.find('points_log', function (l) { return I(l.class_id) === cid && I(l.student_id) === I(olds[i]); });
        for (var j = 0; j < rows.length; j++) await DB.patch('points_log', rows[j].id, { student_id: moves[olds[i]] });
    }
}
/** 项目自动积分同步（指纹比对 + 全量重建 source=2 流水；原 auto_points_sync） */
async function autoSync(project, force) {
    if (!project || I(project.id) <= 0) return;
    var pid = I(project.id);
    var mode = S(project.mode) || 'count';
    var isOmr = mode === 'omr';
    var late = I(project.late_seconds);
    var nowTs = Math.floor(Date.now() / 1000);
    // 1) 启用规则（按当前模式过滤；value=0 / 参数缺失的忽略）
    var all = (await DB.find('points_rules', function (r) { return I(r.project_id) === pid; }));
    var meta = RULE_META_ALL;
    var rules = all.filter(function (r) {
        if (I(r.enabled) !== 1) return false;
        var m = meta[S(r.rtype)];
        if (!m || m.modes.indexOf(mode) < 0 || I(r.value) === 0) return false;
        var param = m.param || 'none';
        if (param === 'opt' && S(r.opt).trim() === '') return false;
        if (param === 'num' && !isNumStr(S(r.opt).trim())) return false;
        return true;
    }).sort(function (a, b) { return (I(a.sort) - I(b.sort)) || (I(a.id) - I(b.id)); })
      .map(function (r) { return { id: I(r.id), rtype: S(r.rtype), opt: S(r.opt).trim(), value: I(r.value) }; });
    // 2) 无启用规则：清掉该项目自动流水并清指纹
    if (!rules.length) {
        await DB.delWhere('points_log', function (l) { return I(l.source) === 2 && I(l.project_id) === pid; });
        await DB.settingsSet('ptauto_fp_' + pid, '');
        return;
    }
    // 3) 学生基础集（超时未登记判定范围）
    var stus = await DB.find('students', function (s) { return I(s.class_id) === I(project.class_id) && !I(s.disabled); });
    var students = {};
    stus.forEach(function (s) { students[I(s.id)] = { class_id: I(s.class_id), no: S(s.student_no) }; });
    // 4) 按模式收集登记/评价行 + 各范围单位（rows=[sid,at,eval,round,score,label]; units=范围=>{label,reg[],first_ts}）
    var rows = [], units = {}, correct = {};
    if (mode === 'count') {
        (await DB.by('records', 'project_id', pid)).forEach(function (r) {
            if (I(r.registered) !== 1) return;
            var sid = I(r.student_id);
            rows.push({ sid: sid, at: S(r.registered_at), eval: S(r.eval_value), round: 0, score: '', label: '登记' });
            if (!units[1]) units[1] = { label: '登记', reg: [] };
            units[1].reg.push(sid);
        });
        var f = 0;
        rows.forEach(function (r) { var t = tsOf(r.at); if (t && (f === 0 || t < f)) f = t; });
        if (!units[1]) units[1] = { label: '登记', reg: [] };
        units[1].first_ts = f;
    } else if (mode === 'daily') {
        (await DB.by('record_days', 'project_id', pid)).forEach(function (r) {
            var d = S(r.reg_date);
            var sid = I(r.student_id);
            rows.push({ sid: sid, at: S(r.created_at), eval: S(r.eval_value), round: 0, score: '', label: d });
            if (!units[d]) units[d] = { label: d, reg: [] };
            units[d].reg.push(sid);
            var t = tsOf(r.created_at);
            if (t && (!units[d].first_ts || t < units[d].first_ts)) units[d].first_ts = t;
        });
    } else if (isOmr) {
        (await DB.by('omr_results', 'project_id', pid)).forEach(function (r) {
            if (r.student_id === null || r.student_id === undefined || r.student_id === '') return;
            var rn = I(r.round_no) || 1;
            var sid = I(r.student_id);
            rows.push({ sid: sid, at: S(r.updated_at || r.created_at), eval: '', round: rn, score: S(r.score), label: '第' + rn + '题次' });
            if (!units[rn]) units[rn] = { label: '第' + rn + '题次', reg: [] };
            units[rn].reg.push(sid);
            var t = tsOf(r.created_at);
            if (t && (!units[rn].first_ts || t < units[rn].first_ts)) units[rn].first_ts = t;
        });
    } else { // multi / quiz / raise
        (await DB.by('record_rounds', 'project_id', pid)).forEach(function (r) {
            if (!r.registered_at) return;
            var rn = I(r.round_no) || 1;
            var sid = I(r.student_id);
            rows.push({ sid: sid, at: S(r.registered_at), eval: S(r.eval_value), round: rn, score: '', label: '第' + rn + '题次' });
            if (!units[rn]) units[rn] = { label: '第' + rn + '题次', reg: [] };
            units[rn].reg.push(sid);
            var t = tsOf(r.registered_at);
            if (t && (!units[rn].first_ts || t < units[rn].first_ts)) units[rn].first_ts = t;
        });
    }
    if (mode === 'quiz' || mode === 'raise') {
        (await DB.by('project_rounds', 'project_id', pid)).forEach(function (r) {
            var opts = S(r.correct_opts).split(',').map(function (v) { return v.trim().toUpperCase(); }).filter(function (v) { return v !== ''; });
            if (opts.length) correct[I(r.round_no)] = opts;
        });
    }
    // 5) 超时未登记行（需补登记阈值>0；该范围无人登记不判定）
    var hasTimeout = rules.some(function (r) { return r.rtype === 'timeout_miss'; });
    var timeoutRows = [], passedCnt = 0;
    if (hasTimeout && late > 0) {
        Object.keys(units).forEach(function (uk) {
            var u = units[uk];
            if (!u.first_ts) return;
            var dts = u.first_ts + late;
            if (nowTs <= dts) return;
            passedCnt++;
            var reg = {};
            (u.reg || []).forEach(function (sid) { reg[sid] = 1; });
            var at = tsStr(dts);
            Object.keys(students).forEach(function (sid) {
                if (!reg[sid]) timeoutRows.push({ sid: parseInt(sid, 10), at: at, label: u.label });
            });
        });
    }
    // 6) 指纹比对（一致则无需重建；与各页积分弹窗直读层共用指纹）
    var fpSrc = {
        rules: rules.map(function (r) { return [r.id, r.rtype, r.opt, r.value]; }),
        students: Object.keys(students),
        rows: rows.map(function (r) { return [r.sid, r.at, r.eval, r.score, r.round]; }),
        correct: correct,
        first: Object.keys(units).map(function (k) { return [k, units[k].first_ts || 0]; }),
        passed: passedCnt
    };
    var fp = JSON.stringify(fpSrc);
    if (!force) {
        var old = await DB.settingsGet('ptauto_fp_' + pid, '');
        if (old === fp) return;
    }
    // 7) 全量重建：删旧 source=2 流水 → 按规则重插（created_at=实际登记/评价时间，超时=截止时刻）
    await DB.delWhere('points_log', function (l) { return I(l.source) === 2 && I(l.project_id) === pid; });
    async function gen(rtype, opt, value, sid, remark, at) {
        if (!students[sid]) return;   // 学生已不在项目覆盖班级（转出/禁用）：跳过
        var si = students[sid];
        await DB.insert('points_log', {
            school_id: 0, class_id: si.class_id, student_id: sid, student_no: si.no, item_id: 0,
            item_name: ruleDisplayName(rtype, opt), value: value, remark: S(remark).slice(0, 200),
            created_by: 0, created_at: S(at), project_id: pid, source: 2
        });
    }
    for (var ri = 0; ri < rules.length; ri++) {
        var rule = rules[ri], rtype = rule.rtype, opt = rule.opt, value = rule.value;
        if (rtype === 'timeout_miss') {
            for (var ti = 0; ti < timeoutRows.length; ti++) {
                var tr = timeoutRows[ti];
                await gen(rtype, opt, value, tr.sid, tr.label, tr.at);
            }
            continue;
        }
        for (var xi = 0; xi < rows.length; xi++) {
            var r = rows[xi], hit = false, v = value;
            switch (rtype) {
                case 'reg_ok': hit = true; break;
                case 'eval_done': hit = (r.eval !== '' || (isOmr && r.score !== '')); break;
                case 'eval_opt': hit = (r.eval !== '' && r.eval.trim() === opt); break;
                case 'quiz_right': hit = (!!correct[r.round] && r.eval !== '' && correct[r.round].indexOf(r.eval.trim().toUpperCase()) >= 0); break;
                case 'quiz_wrong': hit = (!!correct[r.round] && r.eval !== '' && correct[r.round].indexOf(r.eval.trim().toUpperCase()) < 0); break;
                case 'omr_per_right':
                    if (r.score === '' || r.score.indexOf('/') < 0) break;
                    v = Math.round(parseFloat(r.score.split('/')[0]) * value);
                    hit = (v !== 0);
                    break;
                case 'omr_right_ge':
                    if (r.score === '' || r.score.indexOf('/') < 0) break;
                    hit = (parseFloat(r.score.split('/')[0]) >= parseFloat(opt));
                    break;
                case 'omr_wrong_ge':
                    if (r.score === '' || r.score.indexOf('/') < 0) break;
                    var pp = r.score.split('/');
                    hit = ((parseFloat(pp[1] || 0) - parseFloat(pp[0])) >= parseFloat(opt));
                    break;
                case 'omr_score_lt':
                    if (r.score === '' || r.score.indexOf('/') < 0) break;
                    hit = (parseFloat(r.score.split('/')[0]) < parseFloat(opt));
                    break;
            }
            if (hit && v !== 0) await gen(rtype, opt, v, r.sid, r.label, r.at);
        }
    }
    await DB.settingsSet('ptauto_fp_' + pid, fp);
}
/** 班级校验（存在且未删除）→ classes 行 / null */
async function classRow(cid) {
    var c = await DB.get('classes', cid);
    return (c && !c.deleted_at) ? c : null;
}

// ================================================================
// [组C] 积分接口
// ================================================================
var H = {};

/** points_data：流水/汇总/项/规则/礼品 全量数据 */
H.points_data = async function (prm) {
    var cid = I(prm.class_id);
    if (!cid) return fail('缺少班级参数');
    if (!(await classRow(cid))) return fail('班级不存在');
    // 项目上下文（宿主 project_view 传入）：懒触发该项目自动积分同步
    var syncPid = I(prm.project_id);
    if (syncPid > 0) {
        var pj = await DB.get('projects', syncPid);
        if (pj && !pj.deleted_at) await autoSync(pj, false);
    }
    await recoverOrphan(cid);
    var items = await itemsLoad();
    // 学生名单（含累计总积分；未禁用，座号数字升序）
    var stuAll = await DB.find('students', function (s) { return I(s.class_id) === cid && !I(s.disabled); });
    var logsAll = await DB.find('points_log', function (l) { return I(l.class_id) === cid; });
    var totals = {};
    logsAll.forEach(function (l) { var k = I(l.student_id); totals[k] = (totals[k] || 0) + I(l.value); });
    stuAll.sort(function (a, b) { return seatNum(a.seat_no) - seatNum(b.seat_no) || I(a.id) - I(b.id); });
    var stuMap = {};
    var students = stuAll.map(function (s) {
        stuMap[I(s.id)] = s;
        return { id: I(s.id), name: S(s.name), seat: S(s.seat_no), seat_pos: I(s.seat_pos), group_id: I(s.group_id), total: totals[I(s.id)] || 0 };
    });
    // 座位布局与分组列表 / 座位组名
    var cls = await DB.get('classes', cid);
    var seat_cfg = { cols: Math.max(1, I(cls && cls.seat_cols) || 1), groups: Math.max(1, I(cls && cls.seat_groups) || 1) };
    if (!cls) seat_cfg = { cols: 2, groups: 4 };
    var groups = (await DB.find('stu_groups', function (x) { return I(x.class_id) === cid; }))
        .sort(function (a, b) { return (I(a.sort) - I(b.sort)) || (I(a.id) - I(b.id)); })
        .map(function (x) { return { id: I(x.id), name: S(x.name), sort: I(x.sort) }; });
    var seat_names = {};
    groups.forEach(function (x) {
        var m = /^第\s*(\d+)\s*组$/.exec(x.name);
        if (I(x.sort) >= 1 && m && I(m[1]) >= 1) seat_names[I(m[1])] = x.name;
    });
    // 抽中记录（settings sid=>时间）
    var lot_picked = {};
    try { lot_picked = jsonParse(await DB.settingsGet('pt_lottery_' + cid, '{}'), {}) || {}; } catch (e) { lot_picked = {}; }
    // 流水行装配（字段名与原版一致：id/sid/sname/seat/item/value/remark/mine/src/pid/by/at）
    function rowOf(l) {
        var s = stuMap[I(l.student_id)];
        var src = I(l.source) || 1;
        return { id: I(l.id), sid: I(l.student_id), sname: s ? S(s.name) : '', seat: s ? S(s.seat_no) : '',
                 item: S(l.item_name), value: I(l.value), remark: S(l.remark), mine: src === 1,
                 src: src, pid: I(l.project_id), by: '', at: S(l.created_at) };
    }
    var logs = logsAll.slice().sort(function (a, b) { return I(b.id) - I(a.id); }).slice(0, 50).map(rowOf);
    // 汇总分析（range=week/month/all）：排行 + 维度占比
    var range = S(prm.range) || 'all';
    var days = range === 'week' ? 7 : (range === 'month' ? 30 : 0);
    var cutoff = '';
    if (days > 0) {
        var d = new Date(Date.now() - days * 86400000);
        cutoff = dateStr(d) + ' ' + S(nowStr(d)).slice(11);
    }
    var logsR = cutoff === '' ? logsAll : logsAll.filter(function (l) { return S(l.created_at) >= cutoff; });
    var rankMap = {};
    logsR.forEach(function (l) { var k = I(l.student_id); rankMap[k] = (rankMap[k] || 0) + I(l.value); });
    var rank = Object.keys(rankMap).map(function (k) {
        var s = stuMap[I(k)];
        if (!s || I(s.disabled) || rankMap[k] === 0) return null;
        return { sid: I(k), name: S(s.name), seat: S(s.seat_no), total: rankMap[k] };
    }).filter(function (r) { return r; });
    rank.sort(function (a, b) { return b.total - a.total || a.sid - b.sid; });
    rank = rank.slice(0, 20);
    var dimMap = {};
    logsR.forEach(function (l) {
        var k = S(l.item_name) !== '' ? S(l.item_name) : '自定义';
        dimMap[k] = (dimMap[k] || 0) + I(l.value);
    });
    var dims = Object.keys(dimMap).map(function (k) { return { name: k, value: dimMap[k] }; });
    dims.sort(function (a, b) { return Math.abs(b.value) - Math.abs(a.value); });
    dims = dims.slice(0, 20);
    // 个人统计（student_id 选中某学生）
    var stu_stat = null;
    var stuId = I(prm.student_id);
    if (stuId > 0) {
        var sw = stuMap[stuId];
        if (sw) {
            var myLogs = logsR.filter(function (l) { return I(l.student_id) === stuId; })
                .sort(function (a, b) { return S(a.created_at).localeCompare(S(b.created_at)) || I(a.id) - I(b.id); })
                .slice(0, 2000);
            var sdimsM = {}, sdailyM = {}, stotal = 0;
            myLogs.forEach(function (l) {
                var dn = S(l.item_name) !== '' ? S(l.item_name) : '自定义';
                var v = I(l.value);
                stotal += v;
                sdimsM[dn] = (sdimsM[dn] || 0) + v;
                var day = S(l.created_at).slice(0, 10);
                sdailyM[day] = (sdailyM[day] || 0) + v;
            });
            var dims_out = Object.keys(sdimsM).map(function (k) { return { name: k, value: sdimsM[k] }; });
            dims_out.sort(function (a, b) { return Math.abs(b.value) - Math.abs(a.value); });
            var cum = 0;
            var daily_out = Object.keys(sdailyM).sort().map(function (d) { cum += sdailyM[d]; return { d: d, v: sdailyM[d], c: cum }; });
            stu_stat = { id: stuId, name: S(sw.name), seat: S(sw.seat_no), total: stotal,
                         dims: dims_out, daily: daily_out, logs: myLogs.slice().reverse().map(rowOf) };
        }
    }
    return ok({ items: items, students: students, logs: logs, rank: rank, dims: dims, stu_stat: stu_stat,
                lot_picked: lot_picked, seat_cfg: seat_cfg, groups: groups, seat_names: seat_names,
                gifts: await giftsLoad(cid) });
};

/** points_add：批量加减分（student_ids JSON，1..50 人；item_id=积分项 / custom=自定义 ±1~100） */
H.points_add = async function (prm) {
    var cid = I(prm.class_id);
    if (!cid) return fail('缺少班级参数');
    if (!(await classRow(cid))) return fail('班级不存在');
    var idsRaw = jsonParse(prm.student_ids, null);
    if (!Array.isArray(idsRaw)) return fail('参数错误');
    var ids = [];
    idsRaw.forEach(function (v) { var n = I(v); if (ids.indexOf(n) < 0) ids.push(n); });
    if (!ids.length || ids.length > 50) return fail('请选择学生（一次最多 50 人）');
    var matched = [];
    for (var i = 0; i < ids.length; i++) {
        var s = await DB.get('students', ids[i]);
        if (!s || I(s.class_id) !== cid || I(s.disabled)) return fail('部分学生不在本班或已禁用，请刷新后重试');
        matched.push(s);
    }
    var remark = S(prm.remark).trim().slice(0, 200);
    var itemId = I(prm.item_id), custom = I(prm.custom);
    var itemName, value;
    if (itemId > 0) {
        var it = await DB.get('points_items', itemId);
        if (!it) return fail('积分项不存在');
        itemName = S(it.name);
        value = I(it.value);
    } else {
        if (custom === 0 || Math.abs(custom) > 100) return fail('自定义分值需为 ±1~100');
        itemId = 0;
        itemName = '';
        value = custom;
    }
    var now = nowStr();
    for (var j = 0; j < matched.length; j++) {
        await DB.insert('points_log', {
            school_id: 0, class_id: cid, student_id: I(matched[j].id), student_no: S(matched[j].student_no),
            item_id: itemId, item_name: itemName, value: value, remark: remark,
            created_by: 0, created_at: now, project_id: 0, source: 1
        });
    }
    var sign = value > 0 ? '+' : '';
    return ok({ added: matched.length, value: value, message: (value > 0 ? '加分' : '扣分') + ' ' + sign + value + ' × ' + matched.length + ' 人' });
};

/** points_log_del：撤销一条手动流水（自动规则流水不可撤销） */
H.points_log_del = async function (prm) {
    var cid = I(prm.class_id);
    var lrow = await DB.get('points_log', I(prm.log_id));
    if (!lrow || I(lrow.class_id) !== cid) return fail('记录不存在');
    if (I(lrow.source) === 2) return fail('🤖 自动规则流水不可单独撤销，请在积分 ⚙ 设置中调整规则后重算');
    await DB.del('points_log', I(prm.log_id));
    return ok({ message: '已撤销该条积分' });
};

/** points_items_save：全量保存积分项（≤20 项；流水存名称快照不受影响） */
H.points_items_save = async function (prm) {
    var itemsRaw = jsonParse(prm.items, null);
    if (!Array.isArray(itemsRaw) || !itemsRaw.length) return fail('请至少保留一个积分项');
    itemsRaw = itemsRaw.slice(0, 20);
    var clean = [];
    for (var i = 0; i < itemsRaw.length; i++) {
        var it = itemsRaw[i] || {};
        var name = S(it.name).trim().slice(0, 20);
        var val = I(it.value);
        var color = S(it.color);
        if (name === '') return fail('积分项名称不能为空');
        if (val === 0 || Math.abs(val) > 100) return fail('「' + name + '」分值需为 ±1~100 且不为 0');
        if (!/^#[0-9a-fA-F]{6}$/.test(color)) color = '#27ae60';
        clean.push({ name: name, value: val, color: color.toLowerCase(),
                     type: I(it.type) === 2 ? 2 : 1, show_lot: I(it.show_lot) === 0 ? 0 : 1, show_quick: I(it.show_quick) === 0 ? 0 : 1 });
    }
    await DB.delWhere('points_items', function () { return true; });
    for (var j = 0; j < clean.length; j++) {
        clean[j].sort = j;
        await DB.insert('points_items', clean[j]);
    }
    return ok({ items: await itemsLoad(), message: '积分项已保存（共 ' + clean.length + ' 项）' });
};

/** points_lot_add：随机抽选中签登记（sid=>时间 存 settings） */
H.points_lot_add = async function (prm) {
    var cid = I(prm.class_id);
    if (!cid) return fail('缺少班级参数');
    var sid = I(prm.sid);
    var s = await DB.get('students', sid);
    if (!s || I(s.class_id) !== cid || I(s.disabled)) return fail('学生不存在');
    var picked = jsonParse(await DB.settingsGet('pt_lottery_' + cid, '{}'), {}) || {};
    picked[String(sid)] = nowStr();
    await DB.settingsSet('pt_lottery_' + cid, JSON.stringify(picked));
    return ok();
};

/** points_lot_clear：清空抽中记录（全班重新可被抽中） */
H.points_lot_clear = async function (prm) {
    var cid = I(prm.class_id);
    if (!cid) return fail('缺少班级参数');
    await DB.settingsSet('pt_lottery_' + cid, '');
    return ok({ message: '已清空抽中记录，全班学生重新可被抽中' });
};

/** points_rule_projects：可配置自动规则的项目列表（单机版=全部未删除项目） */
H.points_rule_projects = async function (prm) {
    var projs = (await DB.find('projects', function (p) { return !p.deleted_at; }))
        .sort(function (a, b) { return I(b.id) - I(a.id); });
    var list = [];
    for (var i = 0; i < projs.length; i++) {
        var p = projs[i];
        var c = await DB.get('classes', I(p.class_id));
        var mode = S(p.mode) || 'count';
        list.push({ id: I(p.id), name: S(p.name), mode: mode, mode_name: MODE_NAMES[mode] || mode,
                    late_seconds: I(p.late_seconds), scope: S(p.scope) || 'class', class_name: c ? S(c.name) : '' });
    }
    return ok({ projects: list });
};

/** points_rules_get：规则类型 meta + 现有规则 + 评价内容候选 */
H.points_rules_get = async function (prm) {
    var pid = I(prm.project_id);
    var pj = await DB.get('projects', pid);
    if (!pj || pj.deleted_at) return fail('无该项目管理权限或项目不存在');
    var mode = S(pj.mode) || 'count';
    var meta = ruleMeta(mode);
    // 评价内容候选（eval_opt 下拉）：评价模式预设选项 + 该项目实际用到的评价值
    var eval_opts = [];
    var mi = await evalModeInfo(S(pj.eval_mode));
    var preset = (mi && mi.options) ? Object.keys(mi.options) : [];
    preset.forEach(function (po) { po = S(po).trim(); if (po !== '' && eval_opts.indexOf(po) < 0) eval_opts.push(po); });
    var evTbl = mode === 'daily' ? 'record_days' : (mode === 'count' ? 'records' : (mode === 'omr' ? '' : 'record_rounds'));
    if (evTbl !== '') {
        (await DB.by(evTbl, 'project_id', pid)).forEach(function (r) {
            var ev = S(r.eval_value).trim();
            if (ev !== '' && eval_opts.indexOf(ev) < 0) eval_opts.push(ev);
        });
    }
    var rules = (await DB.find('points_rules', function (r) { return I(r.project_id) === pid; }))
        .sort(function (a, b) { return (I(a.sort) - I(b.sort)) || (I(a.id) - I(b.id)); })
        .map(function (r) {
            return { id: I(r.id), rtype: S(r.rtype), opt: S(r.opt), value: I(r.value),
                     enabled: I(r.enabled) === 1 ? 1 : 0, display: ruleDisplayName(S(r.rtype), S(r.opt)) };
        });
    return ok({ project: { id: pid, name: S(pj.name), mode: mode, mode_name: MODE_NAMES[mode] || mode, late_seconds: I(pj.late_seconds) },
                meta: meta, rules: rules, eval_opts: eval_opts });
};

/** points_rules_save：全量替换规则 → 强制重算该项目自动积分 */
H.points_rules_save = async function (prm) {
    var pid = I(prm.project_id);
    var pj = await DB.get('projects', pid);
    if (!pj || pj.deleted_at) return fail('无该项目管理权限或项目不存在');
    var mode = S(pj.mode) || 'count';
    var meta = ruleMeta(mode);
    var rulesIn = jsonParse(prm.rules, null);
    if (!rulesIn) return fail('参数错误');
    if (rulesIn.length > 50) return fail('规则最多 50 条');
    var clean = [];
    for (var i = 0; i < rulesIn.length; i++) {
        var r = rulesIn[i];
        if (!r || typeof r !== 'object') continue;
        var rtype = S(r.rtype);
        var m = meta[rtype];
        if (!m) continue;   // 类型不存在或不适用于该项目登记模式
        var opt = S(r.opt).trim();
        var value = I(r.value);
        var enabled = I(r.enabled) === 0 ? 0 : 1;
        if (m.need_late && I(pj.late_seconds) <= 0) continue;   // 超时规则需补登记阈值>0
        var param = m.param || 'none';
        if (param === 'opt') {
            if (opt === '') continue;
            opt = opt.slice(0, 50);
        } else if (param === 'num') {
            if (opt === '' || !isNumStr(opt)) continue;
            opt = String(0 + parseFloat(opt));
        } else {
            opt = '';
        }
        if (value === 0) continue;
        clean.push({ project_id: pid, rtype: rtype, opt: opt, value: value, enabled: enabled, sort: i });
    }
    await DB.delWhere('points_rules', function (r) { return I(r.project_id) === pid; });
    if (clean.length) await DB.insertMany('points_rules', clean);
    await autoSync(pj, true);
    return ok({ message: '已保存规则并重算本项目自动积分', count: clean.length });
};

/** points_export：CSV 下载（kind=balance 最终积分 / log 过程流水；UTF-8 BOM） */
H.points_export = async function (prm) {
    var cid = I(prm.class_id);
    var cls = await classRow(cid);
    if (!cls) return fail('班级不存在');
    var kind = S(prm.kind) === 'log' ? 'log' : 'balance';
    var dlname = '积分' + (kind === 'log' ? '流水' : '最终') + '_' + S(cls.name) + '_' + dateStr().replace(/-/g, '') + '.csv';
    var nowD = new Date();
    var fparts = dateStr(nowD).replace(/-/g, '') + '_' + S(nowStr(nowD)).slice(11, 16).replace(':', '');   // Ymd_Hi
    var fname = 'points_' + kind + '_' + cid + '_' + fparts + '.csv';
    var lines = [];
    function cell(v) {
        v = S(v === null || v === undefined ? '' : v);
        return /[",\\\n\r\t]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
    }
    var stuAll = await DB.find('students', function (s) { return I(s.class_id) === cid && !I(s.disabled); });
    var stuMap = {};
    stuAll.forEach(function (s) { stuMap[I(s.id)] = s; });
    var logsAll = await DB.find('points_log', function (l) { return I(l.class_id) === cid; });
    if (kind === 'balance') {
        lines.push(['编号', '姓名', '座号', '当前积分'].map(cell).join(','));
        var totals = {};
        logsAll.forEach(function (l) { var k = I(l.student_id); totals[k] = (totals[k] || 0) + I(l.value); });
        stuAll.sort(function (a, b) { return seatNum(a.seat_no) - seatNum(b.seat_no) || I(a.id) - I(b.id); });
        stuAll.forEach(function (s) {
            lines.push([S(s.student_no), S(s.name), S(s.seat_no), totals[I(s.id)] || 0].map(cell).join(','));
        });
    } else {
        lines.push(['时间', '姓名', '编号', '积分项', '分值', '备注', '来源'].map(cell).join(','));
        logsAll.sort(function (a, b) { return S(a.created_at).localeCompare(S(b.created_at)) || I(a.id) - I(b.id); });
        logsAll.forEach(function (l) {
            var src = I(l.source);
            var srcName = src === 2 ? '🤖 自动规则' : (src === 3 ? '📥 导入' : '手动');
            var s = stuMap[I(l.student_id)];
            lines.push([S(l.created_at), s ? S(s.name) : '', S(l.student_no), S(l.item_name), I(l.value), S(l.remark), srcName].map(cell).join(','));
        });
    }
    var body = '\uFEFF' + lines.join('\n');
    var blob = new Blob([body], { type: 'text/csv; charset=utf-8' });
    return { status: 200, body: blob, headers: {
        'Content-Type': 'text/csv; charset=utf-8',
        'Content-Disposition': 'attachment; filename="' + fname + '"; filename*=UTF-8\'\'' + encodeURIComponent(dlname)
    } };
};

/** points_import：导入（kind=balance 每生一条「积分导入」/ log 按原时间回放；source=3；跳过密码校验） */
H.points_import = async function (prm, files) {
    var cid = I(prm.class_id);
    var cls = await classRow(cid);
    if (!cls) return fail('班级不存在');
    var kind = S(prm.kind) === 'log' ? 'log' : 'balance';
    var content = S(prm.content);
    var f = files && (files.file || files.csv);
    if (f && f.blob) {
        var fc = await f.blob.text();
        if (fc && fc.trim() !== '') content = fc;
    }
    if (content.length > 500000) return fail('导入内容过大，请分批导入');
    // 文本预处理：统一换行、去 BOM、去空行
    content = S(content).replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    if (content.indexOf('\uFEFF') === 0) content = content.slice(1);
    var lines = content.split('\n').map(function (l) { return l.trim(); }).filter(function (l) { return l !== ''; });
    // 自动跳过表头行
    if (lines.length && lines[0].indexOf('姓名') >= 0 && (lines[0].indexOf('积分') >= 0 || lines[0].indexOf('分值') >= 0)) lines.shift();
    if (!lines.length) return ok({ message: (kind === 'balance' ? '最终积分' : '过程流水') + '导入完成：成功 0 条', added: 0, skips: ['没有可导入的数据行'] });
    // 行切分：制表符直分；否则 CSV 引号解析（逗号分隔，"" 转义）
    function splitLine(line) {
        if (line.indexOf('\t') >= 0) return line.split('\t').map(function (v) { return v.trim(); });
        var out = [], cur = '', inq = false;
        for (var i = 0; i < line.length; i++) {
            var ch = line.charAt(i);
            if (inq) {
                if (ch === '"') { if (line.charAt(i + 1) === '"') { cur += '"'; i++; } else inq = false; }
                else cur += ch;
            } else if (ch === '"') inq = true;
            else if (ch === ',') { out.push(cur.trim()); cur = ''; }
            else cur += ch;
        }
        out.push(cur.trim());
        return out.filter(function (v) { return v !== null; });
    }
    // 本班未禁用学生匹配映射（编号优先；姓名唯一才兜底）
    var stus = (await DB.find('students', function (s) { return I(s.class_id) === cid && !I(s.disabled); }))
        .sort(function (a, b) { return I(a.id) - I(b.id); });
    var byNo = {}, byName = {}, nameCnt = {}, stuById = {};
    stus.forEach(function (s) {
        var no = S(s.student_no).trim(), nm = S(s.name).trim();
        stuById[I(s.id)] = s;
        if (no !== '' && !byNo[no]) byNo[no] = I(s.id);
        nameCnt[nm] = (nameCnt[nm] || 0) + 1;
        if (nameCnt[nm] === 1) byName[nm] = I(s.id); else delete byName[nm];
    });
    if (S(prm.clear_first) === '1') await DB.delWhere('points_log', function (l) { return I(l.class_id) === cid; });
    // 逐行解析（先收集后插入，任一行格式错不影响其余行）
    var rows = [], skips = [];
    for (var i = 0; i < lines.length; i++) {
        var lineno = i + 1;
        var flds = splitLine(lines[i]);
        var no, nm, value, itemName, remark, created;
        if (kind === 'balance') {
            var val = flds.pop();
            if (val === undefined || val === null || !isNumStr(val)) { skips.push('第' + lineno + '行：分值「' + val + '」不是数字'); continue; }
            value = intval(val);
            if (value === 0) { skips.push('第' + lineno + '行：分值为 0 已跳过'); continue; }
            if (flds.length >= 2) { no = flds[0]; nm = flds[1]; }
            else if (flds.length === 1) { no = ''; nm = flds[0]; }
            else { skips.push('第' + lineno + '行：缺少学生信息'); continue; }
            itemName = '积分导入'; remark = '数据迁移导入'; created = nowStr();
        } else {
            if (flds.length < 5) { skips.push('第' + lineno + '行：列数不足（时间,姓名,编号,积分项,分值[,备注]）'); continue; }
            var t = new Date(S(flds[0]).replace(/-/g, '/'));
            created = isNaN(t.getTime()) ? nowStr() : nowStr(t);
            nm = flds[1];
            no = flds[2];
            itemName = S(flds[3]).slice(0, 50);
            if (!isNumStr(flds[4])) { skips.push('第' + lineno + '行：分值「' + flds[4] + '」不是数字'); continue; }
            value = intval(flds[4]);
            if (value === 0) { skips.push('第' + lineno + '行：分值为 0 已跳过'); continue; }
            remark = S(flds[5]).slice(0, 200);
            if (itemName === '') itemName = '导入积分';
        }
        // 匹配学生：编号精确 → 姓名唯一
        var sid = 0;
        if (no !== '' && byNo[no]) sid = byNo[no];
        else if (nm !== '' && byName[nm]) sid = byName[nm];
        if (!sid) { skips.push('第' + lineno + '行：未匹配到学生（编号「' + no + '」姓名「' + nm + '」）'); continue; }
        var stu = stuById[sid];
        rows.push({ sid: sid, sno: S(stu && stu.student_no), item: itemName, value: value, remark: remark, at: created });
    }
    var added = 0;
    for (var j = 0; j < rows.length; j++) {
        var r = rows[j];
        await DB.insert('points_log', {
            school_id: 0, class_id: cid, student_id: r.sid, student_no: r.sno, item_id: 0,
            item_name: r.item, value: r.value, remark: r.remark, created_by: 0,
            created_at: r.at, project_id: 0, source: 3
        });
        added++;
    }
    var msg = (kind === 'balance' ? '最终积分' : '过程流水') + '导入完成：成功 ' + added + ' 条' + (skips.length ? '，跳过 ' + skips.length + ' 条' : '');
    return ok({ message: msg, added: added, skips: skips.slice(0, 30) });
};

/** points_clear_all：清空本班全部流水并留一条 0 分审计行（跳过密码校验） */
H.points_clear_all = async function (prm) {
    var cid = I(prm.class_id);
    var cls = await classRow(cid);
    if (!cls) return fail('班级不存在');
    var n = await DB.delWhere('points_log', function (l) { return I(l.class_id) === cid; });
    await DB.insert('points_log', {
        school_id: 0, class_id: cid, student_id: 0, student_no: '', item_id: 0,
        item_name: '🧹 清空积分', value: 0, remark: '清空本班全部积分（原 ' + n + ' 条流水）',
        created_by: 0, created_at: nowStr(), project_id: 0, source: 3
    });
    return ok({ message: '已清空本班全部积分（原 ' + n + ' 条流水），流水保留一条清空记录' });
};

/** points_gift_save：新增/编辑礼品（cost 1~100000） */
H.points_gift_save = async function (prm) {
    var cid = I(prm.class_id);
    var cls = await classRow(cid);
    if (!cls) return fail('班级不存在');
    var gid = I(prm.id);
    var gname = S(prm.name).trim().slice(0, 50);
    var gcost = I(prm.cost);
    if (gname === '') return fail('请填写礼品名称');
    if (gcost <= 0 || gcost > 100000) return fail('兑换所需积分需为 1~100000');
    var msg;
    if (gid > 0) {
        var row = await DB.get('points_gifts', gid);
        if (row && I(row.class_id) === cid) { row.name = gname; row.cost = gcost; await DB.update('points_gifts', row); }
        msg = '礼品已更新';
    } else {
        var all = await DB.find('points_gifts', function (x) { return I(x.class_id) === cid; });
        var mx = 0;
        all.forEach(function (x) { mx = Math.max(mx, I(x.sort)); });
        await DB.insert('points_gifts', { class_id: cid, school_id: 0, name: gname, cost: gcost, enabled: 1, sort: mx + 1 });
        msg = '礼品已添加';
    }
    return ok({ message: msg, gifts: await giftsLoad(cid) });
};

/** points_gift_del：删除礼品（不影响已兑换流水） */
H.points_gift_del = async function (prm) {
    var cid = I(prm.class_id);
    var cls = await classRow(cid);
    if (!cls) return fail('班级不存在');
    var gid = I(prm.id);
    var row = await DB.get('points_gifts', gid);
    if (row && I(row.class_id) === cid) await DB.del('points_gifts', gid);
    return ok({ message: '礼品已删除（不影响已兑换记录）', gifts: await giftsLoad(cid) });
};

/** points_redeem：兑换礼品（余额足够者扣分记「🎁 兑换」流水 value=-abs(cost)；不足跳过并提示） */
H.points_redeem = async function (prm) {
    var cid = I(prm.class_id);
    var cls = await classRow(cid);
    if (!cls) return fail('班级不存在');
    var gift = await DB.get('points_gifts', I(prm.gift_id));
    if (!gift || I(gift.class_id) !== cid || I(gift.enabled) !== 1) return fail('礼品不存在或已下架');
    var idsRaw = jsonParse(prm.student_ids, null);
    if (!Array.isArray(idsRaw) || !idsRaw.length) return fail('请先勾选要兑换的学生');
    if (idsRaw.length > 50) return fail('一次最多兑换 50 人');
    var ids = [];
    idsRaw.forEach(function (v) { var n = I(v); if (ids.indexOf(n) < 0) ids.push(n); });
    // 余额 = 本班流水按学生合计
    var logs = await DB.find('points_log', function (l) { return I(l.class_id) === cid; });
    var bal = {};
    logs.forEach(function (l) { var k = I(l.student_id); bal[k] = (bal[k] || 0) + I(l.value); });
    var cost = I(gift.cost), gname = S(gift.name);
    var okNames = [], poorNames = [], targets = [];
    for (var i = 0; i < ids.length; i++) {
        var s = await DB.get('students', ids[i]);
        if (!s || I(s.class_id) !== cid || I(s.disabled)) continue;
        var b = bal[ids[i]] || 0;
        if (b < cost) { poorNames.push(S(s.name) + '（余额 ' + b + ' 分）'); continue; }
        okNames.push(S(s.name));
        targets.push({ id: ids[i], no: S(s.student_no) });
    }
    var now = nowStr();
    for (var j = 0; j < targets.length; j++) {
        await DB.insert('points_log', {
            school_id: 0, class_id: cid, student_id: targets[j].id, student_no: targets[j].no, item_id: 0,
            item_name: '🎁 兑换', value: -Math.abs(cost), remark: ('兑换「' + gname.slice(0, 40) + '」'),
            created_by: 0, created_at: now, project_id: 0, source: 1
        });
    }
    var msg = '';
    if (okNames.length) msg += '兑换成功 ' + okNames.length + ' 人（每人 −' + cost + ' 分）：' + okNames.slice(0, 10).join('、') + (okNames.length > 10 ? ' 等' : '');
    if (poorNames.length) msg += (okNames.length ? '；' : '') + '余额不足跳过 ' + poorNames.length + ' 人：' + poorNames.slice(0, 10).join('、') + (poorNames.length > 10 ? ' 等' : '');
    if (!okNames.length) return fail(msg !== '' ? msg : '没有人完成兑换');
    return ok({ message: msg });
};

AL.reg(H);
})(typeof self !== 'undefined' ? self : this);
