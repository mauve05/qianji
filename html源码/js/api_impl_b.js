/** [组B] 答题卡 OMR 接口（IndexedDB 实现） */
(function (g) {
'use strict';
var AL = g.ApiLocal; var I = AL.I, S = AL.S, ok = AL.ok, fail = AL.fail;
var H = {};

// ================================================================
// 公共辅助（对照源版 qj/api.php L2768-2956 与 includes/functions.php）
// ================================================================
function nowStr() { return AL.nowStr(); }

/** 项目行（get_operate_project 等价：存在且未入回收站；单机版无权限校验） */
async function getProject(prm) {
    var p = await DB.get('projects', I(prm.project_id));
    return (p && !p.deleted_at) ? p : null;
}
/** 项目覆盖班级（单机版单班级） */
function classIdsOf(prj) { return [I(prj.class_id)]; }
/** 项目存在且 mode=omr */
async function requireOmr(prm, msg) {
    var p = await getProject(prm);
    if (!p) return { p: null, err: fail(msg || '项目不存在或非答题卡模式项目') };
    if (S(p.mode) !== 'omr') return { p: null, err: fail(msg || '项目不存在或非答题卡模式项目') };
    return { p: p, err: null };
}
/** 轮次列表（project_rounds_list：无轮次自动建第 1 轮） */
async function roundsList(pid) {
    var rows = (await DB.by('project_rounds', 'project_id', pid))
        .map(function (r) { return I(r.round_no); }).filter(function (n) { return n > 0; })
        .sort(function (a, b) { return a - b; });
    if (!rows.length) {
        await DB.insert('project_rounds', { project_id: pid, round_no: 1, title: null, correct_opts: null, created_at: nowStr() });
        rows = [1];
    }
    return rows;
}
/** 轮次标题映射（project_rounds_titles：仅含已命名轮次） */
async function roundsTitles(pid) {
    var titles = {};
    (await DB.by('project_rounds', 'project_id', pid)).forEach(function (r) {
        var t = S(r.title);
        if (t !== '') titles[I(r.round_no)] = t;
    });
    return titles;
}
/** current_round_no（omr 恒为多轮次项目：round 在列表内用之，否则最后一轮） */
async function currentRound(prj, req) {
    var list = await roundsList(I(prj.id));
    var n = I(req && req.round);
    return list.indexOf(n) >= 0 ? n : list[list.length - 1];
}
/** 覆盖班级未禁用学生数（total_students_in） */
async function totalStudentsIn(classIds) {
    var c = 0;
    for (var i = 0; i < classIds.length; i++) {
        (await DB.by('students', 'class_id', classIds[i])).forEach(function (s) { if (!I(s.disabled)) c++; });
    }
    return c;
}
/** 题次维度登记数（round_counts[0]：record_rounds 已登记行数） */
async function roundRegCount(pid, round) {
    var n = 0;
    (await DB.by('record_rounds', 'project_id', pid)).forEach(function (r) {
        if (I(r.round_no) === round && r.registered_at) n++;
    });
    return n;
}
async function rrRow(pid, sid, round) {
    return (await DB.find('record_rounds', function (r) {
        return I(r.project_id) === pid && I(r.student_id) === sid && I(r.round_no) === round;
    }))[0] || null;
}
async function recRow(pid, sid) {
    return (await DB.find('records', function (r) {
        return I(r.project_id) === pid && I(r.student_id) === sid;
    }))[0] || null;
}
/** records 汇总 upsert（registered=1；原 registered=1 保持原 registered_at；eval 非空才覆盖评价值） */
async function recUpsert(pid, sid, byWho, evalNum, now) {
    now = now || nowStr();
    var rec = await recRow(pid, sid);
    if (!rec) {
        await DB.insert('records', { project_id: pid, student_id: sid, registered: 1, registered_at: now, registered_by: byWho,
            eval_value: evalNum || '', eval_at: evalNum ? now : '', eval_cleared_at: '' });
        return;
    }
    var wasReg = I(rec.registered) === 1;
    rec.registered = 1;
    if (!wasReg || !rec.registered_at) rec.registered_at = now;
    if (evalNum !== '' && evalNum != null) { rec.eval_value = evalNum; rec.eval_at = now; rec.eval_cleared_at = ''; }
    await DB.update('records', rec);
}

// ===== 答案清洗与判分（omr_layout_ctx / omr_answer_ctx / omr_grade_flat / omr_eval_num 等价） =====
function cleanAE(v) { return S(v == null ? '' : v).toUpperCase().replace(/[^A-E]/g, ''); }
function layoutCtx(layout) {
    var kind = {}, tk = {}, tp = {};
    var secs = (layout && Array.isArray(layout.sections)) ? layout.sections : [];
    secs.forEach(function (sec) {
        if (!sec || typeof sec !== 'object') return;
        var k = S(sec.kind || 'single');
        if (k === 'blank' || k === 'short') return;   // 填空/简答：手写题不参与判分
        var start = (sec.start == null || sec.start === '') ? 1 : I(sec.start);
        var count = Math.min(500, Math.max(1, I(sec.count)));
        var multi = (k === 'multi');
        var key = (sec.key && typeof sec.key === 'object') ? sec.key : {};
        var pts = (sec.points && typeof sec.points === 'object') ? sec.points : {};
        for (var q = start; q < start + count; q++) {
            var qk = String(q);
            kind[qk] = multi ? 'multi' : 'single';
            var v = cleanAE(key[qk]);
            if (v !== '') tk[qk] = v;
            var pv = parseFloat(S(pts[qk] == null ? 0 : pts[qk])) || 0;
            if (pv > 0 && pv <= 100) tp[qk] = pv;
        }
    });
    return { kind: kind, tk: tk, tp: tp };
}
/** 数值格式化：最多 1 位小数去尾零（7.50→7.5、8.0→8） */
function numFmt(v) {
    var s = (Math.round((parseFloat(v) || 0) * 10) / 10).toFixed(1);
    s = s.replace(/0$/, '').replace(/\.$/, '');
    return (s === '' || s === '-0') ? '0' : s;
}
/** 按生效键判分：分值制 "nn/xx" 或 "对/总"；答案不完整且未强制批改 → '' */
function gradeFlat(ctx, answers) {
    if (!ctx.complete && !ctx.force) return '';
    var hasPts = ctx.has_points && ctx.points, got = 0, den = 0, nn = 0, xx = 0;
    Object.keys(ctx.kind).forEach(function (q) {
        var kd = ctx.kind[q];
        if (hasPts && !(parseFloat(S(ctx.points[q] == null ? 0 : ctx.points[q])) > 0)) return;   // 分值制：未设分值的题不参与
        var k2 = cleanAE(ctx.key[q]);
        if (k2 === '') return;   // 该题无生效答案：不参与批改
        den++;
        if (hasPts) xx += parseFloat(S(ctx.points[q] == null ? 0 : ctx.points[q]));
        var sel = cleanAE(answers[String(q)]);
        if (sel === '') return;   // 未涂：记错
        var good = (kd === 'multi')
            ? sel.split('').every(function (ch) { return k2.indexOf(ch) >= 0; })   // 多选：所涂字母均为答案子集
            : (sel === k2);
        if (good) { got++; if (hasPts) nn += parseFloat(S(ctx.points[q] == null ? 0 : ctx.points[q])); }
    });
    if (!den) return '';
    return hasPts ? (numFmt(nn) + '/' + numFmt(xx)) : (got + '/' + den);
}
/** 「8/10」→「8」、「7.5/10」→「7.5」（records/record_rounds 数值评价值口径） */
function evalNum(score) {
    var s = S(score == null ? '' : score);
    if (s === '') return '';
    var i = s.indexOf('/');
    return numFmt(i >= 0 ? s.slice(0, i) : s);
}
/** 独立录入答案读取（omr_indep_answers：跟模板走——固化 tid 与当前绑定不一致即失效清除） */
async function indepGet(pid) {
    if (!pid || pid <= 0) return {};
    var rows = await DB.by('omr_answers', 'project_id', pid);
    if (!rows.length) return {};
    var savedTid = S(await DB.settingsGet('omr_answers_tid_' + pid, ''));
    var bindTid = S(I(await DB.settingsGet('omr_tpl_bind_' + pid, '0')));
    if (savedTid !== '' && I(savedTid) !== I(bindTid)) {
        await DB.delWhere('omr_answers', function (r) { return I(r.project_id) === pid; });
        return {};
    }
    try { var d = JSON.parse(S(rows[0].answers)); return (d && typeof d === 'object') ? d : {}; } catch (e) { return {}; }
}
/** 项目生效答案上下文（omr_answer_ctx：模板最优先，独立答案仅补未设题；含强制批改开关） */
async function answerCtxOf(pid, layout) {
    var lc = layoutCtx(layout), kind = lc.kind, key = lc.tk, tp = lc.tp;
    var src = 'template', filled = 0;
    var pa = await indepGet(pid);
    Object.keys(pa).forEach(function (q) {
        var qk = S(q).replace(/[^0-9]/g, ''), vv = cleanAE(pa[q]);
        if (qk !== '' && vv !== '' && !key[qk]) { key[qk] = vv; filled++; }
    });
    if (filled) src = Object.keys(lc.tk).length ? 'mixed' : 'project';
    var total = Object.keys(kind).length, answered = 0;
    Object.keys(kind).forEach(function (qk) { if (key[qk]) answered++; });
    var hasPts = Object.keys(tp).length > 0, need = 0, have = 0;
    if (hasPts) Object.keys(tp).forEach(function (qk) { need++; if (key[qk]) have++; });   // 分值制：仅设分值的题需有答案
    var complete = hasPts ? (need > 0 && have === need) : (total > 0 && answered === total);
    var force = pid > 0 && (await DB.settingsGet('omr_force_grade_' + pid, '0')) === '1';
    return { kind: kind, key: key, total: total, answered: answered, complete: complete, source: src,
             points: tp, has_points: hasPts, force: force };
}
/** 项目绑定模板 layout（无绑定/模板缺失返回 null） */
async function bindLayout(pid) {
    var bindTid = I(await DB.settingsGet('omr_tpl_bind_' + pid, '0'));
    if (bindTid <= 0) return null;
    var t = await DB.get('omr_templates', bindTid);
    if (!t || I(t.project_id) !== pid) return null;
    try { var d = JSON.parse(S(t.layout)); return (d && typeof d === 'object') ? d : null; } catch (e) { return null; }
}
/** 「录入答案/强制批改」变更后实时重算全部识别结果（omr_resync_scores：同步 omr_results.score 与题次/汇总评价值） */
async function resyncScores(pid) {
    var tpls = {};
    (await DB.by('omr_templates', 'project_id', pid)).forEach(function (t) { tpls[I(t.id)] = S(t.layout); });
    var bindTid = I(await DB.settingsGet('omr_tpl_bind_' + pid, '0'));
    var cache = {};
    async function ctxOf(tid) {
        if (!cache[tid]) {
            var layout = null;
            try { layout = JSON.parse(tpls[tid] || 'null'); } catch (e) { layout = null; }
            if (!layout || !layout.sections || !layout.sections.length) {   // 识别行模板无效/不存在：回退项目绑定模板
                if (bindTid > 0 && tpls[bindTid]) { try { layout = JSON.parse(tpls[bindTid]); } catch (e2) { layout = null; } }
            }
            cache[tid] = await answerCtxOf(pid, layout || {});
        }
        return cache[tid];
    }
    var rows = await DB.by('omr_results', 'project_id', pid);
    var latest = {};   // student_id => {round: eval_num}
    for (var i = 0; i < rows.length; i++) {
        var row = rows[i];
        var ans = {};
        try { var d = JSON.parse(S(row.answers)); if (d && typeof d === 'object') ans = d; } catch (e) {}
        var nw = gradeFlat(await ctxOf(I(row.template_id)), ans);
        var old = S(row.score);
        var sid = I(row.student_id), rnd = I(row.round_no);
        if (sid > 0) { if (!latest[sid]) latest[sid] = {}; latest[sid][rnd] = evalNum(nw); }
        if (nw === old) continue;
        row.score = nw;
        await DB.update('omr_results', row);
        if (sid > 0) {
            var enew = evalNum(nw), eold = evalNum(old);
            var rr = await rrRow(pid, sid, rnd);
            if (enew !== '') {   // 新得分非空 → 覆盖评价值
                if (rr) { rr.eval_value = enew; rr.eval_at = nowStr(); rr.eval_cleared_at = ''; await DB.update('record_rounds', rr); }
            } else if (eold !== '') {   // 原得分非空而新得分为空 → 清回仅登记
                if (rr) { rr.eval_value = ''; rr.eval_cleared_at = nowStr(); await DB.update('record_rounds', rr); }
            }
        }
    }
    // records 汇总口径：每生取最大题次的非空评价值覆盖（仅写非空，不主动清空）
    var now = nowStr();
    Object.keys(latest).forEach(function (sidKey) {
        var rv = latest[sidKey], ev = '';
        Object.keys(rv).map(Number).sort(function (a, b) { return b - a; }).some(function (rnd2) {
            if (rv[rnd2] !== '') { ev = rv[rnd2]; return true; }
            return false;
        });
        if (ev === '') return;
        return recUpsert(pid, I(sidKey), 'scan', ev, now);
    });
    return true;
}
/** 身份匹配（omr_submit / omr_ident_preview 同口径：精确相等 + 数值等价（前导零）、限项目班级、多名拒绝、禁用拒绝） */
async function matchStudent(prj, idtype, raw) {
    var field = (idtype === 'no') ? 'student_no' : 'seat_no';
    var label = (idtype === 'no') ? '编号' : '座号';
    var cid = I(prj.class_id);
    var cls = await DB.get('classes', cid);
    var cname = cls ? S(cls.name) : '';
    var stus = (await DB.by('students', 'class_id', cid)).filter(function (s) {
        var v = S(s[field]).trim();
        if (v === '') return false;
        if (v === raw) return true;
        return /^[0-9]+$/.test(raw) && /^[0-9]+$/.test(v) && parseInt(v, 10) === parseInt(raw, 10);
    });
    if (!stus.length) return { ok: false, message: '未找到' + label + '为 "' + raw + '" 的学生（须为项目覆盖班级学生）' };
    if (stus.length > 1) {
        var names = stus.map(function (m) { return cname + ' ' + S(m.name); });
        return { ok: false, message: '该' + label + '对应多名学生（' + names.join('、') + '），无法唯一识别' };
    }
    var st = stus[0];
    if (I(st.disabled)) return { ok: false, message: cname + ' ' + S(st.name) + ' 已禁用，不参与登记' };
    return { ok: true, student: { id: I(st.id), name: S(st.name), seat_no: S(st.seat_no),
        student_no: S(st.student_no), class_name: cname } };
}
/** 模板/库布局公共校验（纸面尺寸 + bubbles）；通过返回 null，否则错误消息 */
function checkLayoutBase(layout) {
    var paper = (layout.paper && typeof layout.paper === 'object') ? layout.paper : {};
    var pw = parseFloat(S(paper.w == null ? 0 : paper.w)) || 0;
    var ph = parseFloat(S(paper.h == null ? 0 : paper.h)) || 0;
    var bubbles = layout.bubbles;
    if (pw < 100 || pw > 400 || ph < 100 || ph > 500 || !Array.isArray(bubbles) || !bubbles.length || bubbles.length > 3000) {
        return '布局缺少纸面尺寸或涂框坐标（bubbles）';
    }
    for (var i = 0; i < bubbles.length; i++) {
        var b = bubbles[i];
        if (!b || typeof b !== 'object' || b.x == null || b.y == null || b.w == null || b.h == null) return '涂框坐标缺失';
    }
    return null;
}

// ================================================================
// 模板（omr_templates）
// ================================================================
H.omr_template_save = async function (prm) {
    var p = await getProject(prm);
    if (!p || S(p.mode) !== 'omr') return fail('项目不存在或非答题卡模式项目');
    var pid = I(p.id);
    var name = S(prm.name).trim();
    if (name === '') return fail('请先输入模板名称（保存模板必须填写名称）');
    if (name.length > 50) return fail('模板名称过长');
    var raw = S(prm.layout);
    var layout = null;
    try { layout = JSON.parse(raw); } catch (e) { layout = null; }
    if (!layout || typeof layout !== 'object' || Array.isArray(layout)) return fail('布局数据无效');
    if (raw.length > 300000) return fail('布局数据过大');
    var baseErr = checkLayoutBase(layout);
    if (baseErr) return fail(baseErr);
    var digits = I(layout.id && layout.id.digits);
    if (digits < 1) return fail('模板必须包含身份识别区域（座号/编号涂号区）');
    if (!Array.isArray(layout.sections) || !layout.sections.length) return fail('模板必须包含答题区域（请至少添加一个题组）');
    var layoutJson = JSON.stringify(layout);
    var tid = I(prm.id);
    if (tid > 0) {
        var ex = await DB.get('omr_templates', tid);
        if (!ex || I(ex.project_id) !== pid) tid = 0;
    }
    var now = nowStr();
    if (tid > 0) {
        await DB.patch('omr_templates', tid, { name: name, layout: layoutJson, updated_at: now });
    } else {
        var row = await DB.insert('omr_templates', { project_id: pid, name: name, layout: layoutJson, created_at: now, updated_at: now });
        tid = row.id;
    }
    return ok({ id: tid, message: '模板已保存' });
};
H.omr_template_list = async function (prm) {
    var p = await getProject(prm);
    if (!p) return fail('项目不存在或无权限');
    var pid = I(p.id);
    var items = (await DB.by('omr_templates', 'project_id', pid)).map(function (t) {
        return { id: I(t.id), name: S(t.name), updated_at: S(t.updated_at) };
    });
    items.sort(function (a, b) { return S(b.updated_at).localeCompare(S(a.updated_at)) || (b.id - a.id); });
    return ok({ items: items });
};
H.omr_template_get = async function (prm) {
    var p = await getProject(prm);
    if (!p) return fail('项目不存在或无权限');
    var pid = I(p.id);
    var t = await DB.get('omr_templates', I(prm.id));
    if (!t || I(t.project_id) !== pid) return fail('模板不存在');
    var layout = null;
    try { layout = JSON.parse(S(t.layout)); } catch (e) { layout = null; }
    return ok({ id: I(t.id), name: S(t.name), layout: layout });
};
H.omr_tpl_bind_get = async function (prm) {
    var p = await getProject(prm);
    if (!p || S(p.mode) !== 'omr') return fail('项目不存在或非答题卡项目');
    return ok({ template_id: I(await DB.settingsGet('omr_tpl_bind_' + I(p.id), '0')) });
};
H.omr_tpl_bind_set = async function (prm) {
    var p = await getProject(prm);
    if (!p || S(p.mode) !== 'omr') return fail('项目不存在或非答题卡项目');
    var pid = I(p.id);
    var bindTid = I(prm.template_id);
    if (bindTid > 0) {
        var t = await DB.get('omr_templates', bindTid);
        if (!t || I(t.project_id) !== pid) return fail('模板不存在');
    }
    await DB.settingsSet('omr_tpl_bind_' + pid, String(bindTid));
    return ok({ template_id: bindTid });
};
H.omr_template_del = async function (prm) {
    var p = await getProject(prm);
    if (!p) return fail('项目不存在或无权限');
    var pid = I(p.id);
    // 单机版无权限体系：原版登录密码二次确认（verify_login_password）在此省略
    await DB.delWhere('omr_templates', function (t) { return I(t.id) === I(prm.id) && I(t.project_id) === pid; });
    return ok({ message: '模板已删除' });
};

// ================================================================
// 「录入答案」独立答案键 + 强制批改开关（omr_answers / settings）
// ================================================================
H.omr_answer_get = async function (prm) {
    var r = await requireOmr(prm);
    if (r.err) return r.err;
    var pid = I(r.p.id);
    var pa = await indepGet(pid);
    return ok({ source: Object.keys(pa).length ? 'project' : 'template', answers: pa,
                force: (await DB.settingsGet('omr_force_grade_' + pid, '0')) === '1' });
};
H.omr_answer_clear = async function (prm) {
    var r = await requireOmr(prm);
    if (r.err) return r.err;
    var pid = I(r.p.id);
    await DB.delWhere('omr_answers', function (a) { return I(a.project_id) === pid; });
    await resyncScores(pid);   // 回退到模板答案键后实时重算
    return ok({ message: '已清除独立答案（改用模板答案键），识别结果已重算' });
};
H.omr_answer_save = async function (prm) {
    var r = await requireOmr(prm);
    if (r.err) return r.err;
    var pid = I(r.p.id);
    var raw = null;
    try { raw = JSON.parse(S(prm.answers)); } catch (e) { raw = null; }
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return fail('答案数据无效');
    // 清洗 {题号: 答案}（题号纯数字，答案 A-E 字母去重升序）；空数组=清除（回退模板答案键）
    var clean = {};
    Object.keys(raw).forEach(function (q) {
        var qk = S(q).replace(/[^0-9]/g, '');
        var vv = cleanAE(raw[q]);
        if (qk === '' || vv === '') return;
        clean[qk] = vv.split('').filter(function (c, i, a) { return a.indexOf(c) === i; }).sort().join('');
        if (Object.keys(clean).length >= 500) return;
    });
    // 模板答案最优先：剔除与绑定模板答案键重复的题号（独立录入仅可补模板未设答案的题）
    var bindTid = I(await DB.settingsGet('omr_tpl_bind_' + pid, '0'));
    if (bindTid > 0) {
        var t = await DB.get('omr_templates', bindTid);
        if (t && I(t.project_id) === pid) {
            var tk = layoutCtx((function () { try { return JSON.parse(S(t.layout)); } catch (e) { return {}; } })()).tk;
            Object.keys(clean).forEach(function (qk) { if (tk[qk]) delete clean[qk]; });
        }
    }
    var n = Object.keys(clean).length;
    await DB.delWhere('omr_answers', function (a) { return I(a.project_id) === pid; });
    if (n) {
        await DB.insert('omr_answers', { project_id: pid, answers: JSON.stringify(clean), updated_at: nowStr(), updated_by: 0 });
        await DB.settingsSet('omr_answers_tid_' + pid, String(bindTid));   // 独立答案跟模板走：固化保存时的绑定模板 id，换绑即失效
    }
    await resyncScores(pid);   // 按录入覆盖情况实时重算已识别结果
    return ok({ answers: clean, message: n ? '答案已保存，已识别结果已按当前答案重算' : '已清除独立答案' });
};
H.omr_force_set = async function (prm) {
    var r = await requireOmr(prm);
    if (r.err) return r.err;
    var pid = I(r.p.id);
    var on = (I(prm.on) === 1);
    await DB.settingsSet('omr_force_grade_' + pid, on ? '1' : '0');
    await resyncScores(pid);   // 批改口径变化：按新口径实时重算已识别结果
    return ok({ force: on,
                message: on ? '已开启强制批改：答案不完整时按已有答案的题批改，其余题只记录选择'
                             : '已关闭强制批改：答案不完整时仅登记不批改' });
};

// ================================================================
// 共享模板库（omr_lib；单机版无学校/年段/学科体系：范围=全校/班级，可编辑性恒 true）
// ================================================================
async function libOptions() {
    var opts = [{ value: 'school', label: '全校模板（全校各年段各班级可用）' }];
    var cs = (await DB.all('classes')).filter(function (c) { return !c.deleted_at; })
        .sort(function (a, b) { return I(a.id) - I(b.id); });
    cs.forEach(function (c) { opts.push({ value: 'class:' + I(c.id), label: '班级：' + S(c.name) }); });
    return opts;
}
/** 解析范围选项（omr_lib_parse_scope：school / grade:x / subject:x / class:x / class_subject:x:y）；无效返回 null */
async function libParseScope(raw) {
    var parts = S(raw).trim().split(':');
    var scope = S(parts[0]);
    var p1 = I(parts[1]), p2 = I(parts[2]);
    if (['school', 'grade', 'subject', 'class', 'class_subject'].indexOf(scope) < 0) return null;
    var gid = 0, sid2 = 0, cid = 0;
    if (scope === 'grade') { gid = p1; if (gid <= 0) return null; }
    else if (scope === 'subject') { sid2 = p1; if (sid2 <= 0) return null; }
    else if (scope === 'class') { cid = p1; if (cid <= 0) return null; }
    else if (scope === 'class_subject') { cid = p1; sid2 = p2; if (cid <= 0 || sid2 <= 0) return null; }
    if (cid > 0 && !(await DB.get('classes', cid))) return null;   // 班级须存在（单机版无年段/学科表，二者免校验）
    return { scope: scope, grade_id: gid, subject_id: sid2, class_id: cid };
}
H.omr_lib_options = async function () {
    return ok({ options: await libOptions() });
};
H.omr_lib_list = async function () {
    var cmap = {};
    (await DB.all('classes')).forEach(function (c) { cmap[I(c.id)] = S(c.name); });
    var rows = (await DB.all('omr_lib')).sort(function (a, b) {
        return S(b.updated_at).localeCompare(S(a.updated_at)) || (I(b.id) - I(a.id));
    });
    var items = rows.map(function (o) {
        var so = S(o.scope), scLabel = '全校';
        if (so === 'grade') { so += ':' + I(o.grade_id); scLabel = '年段·?'; }
        else if (so === 'subject') { so += ':' + I(o.subject_id); scLabel = '学科·?'; }
        else if (so === 'class') { so += ':' + I(o.class_id); scLabel = '班级·' + (cmap[I(o.class_id)] || '?'); }
        else if (so === 'class_subject') {
            so += ':' + I(o.class_id) + ':' + I(o.subject_id);
            scLabel = '班级学科·' + (cmap[I(o.class_id)] || '?') + '·?';
        }
        return { id: I(o.id), name: S(o.name), scope: S(o.scope), scope_option: so, scope_label: scLabel,
                 creator: '', updated_at: S(o.updated_at), can_edit: 1 };
    });
    return ok({ items: items, options: await libOptions() });
};
H.omr_lib_save = async function (prm) {
    var libId = I(prm.id);
    var name = S(prm.name).trim();
    if (name === '') name = '共享模板';
    if (name.length > 50) return fail('模板名称过长');
    var sc = await libParseScope(prm.scope_option);
    if (!sc) return fail('适用范围无效或不存在');
    var raw = S(prm.layout);
    var layoutJson = '';
    if (raw !== '') {   // 布局校验（与项目模板同规则）；更新时 layout 可省略 = 仅改名/改范围
        var layout = null;
        try { layout = JSON.parse(raw); } catch (e) { layout = null; }
        if (!layout || typeof layout !== 'object' || Array.isArray(layout)) return fail('布局数据无效');
        if (raw.length > 300000) return fail('布局数据过大');
        var baseErr = checkLayoutBase(layout);
        if (baseErr) return fail(baseErr);
        layoutJson = JSON.stringify(layout);
    }
    var now = nowStr();
    if (libId > 0) {
        var old = await DB.get('omr_lib', libId);
        if (!old) return fail('模板库中不存在该模板');
        old.name = name; old.scope = sc.scope; old.grade_id = sc.grade_id; old.subject_id = sc.subject_id;
        old.class_id = sc.class_id; old.updated_at = now;
        if (layoutJson !== '') old.layout = layoutJson;
        await DB.update('omr_lib', old);
        return ok({ id: libId, message: '模板库模板已更新' });
    }
    if (raw === '') return fail('布局数据无效');
    var row = await DB.insert('omr_lib', { school_id: 0, name: name, scope: sc.scope, grade_id: sc.grade_id,
        subject_id: sc.subject_id, class_id: sc.class_id, layout: layoutJson, created_by: 0, created_at: now, updated_at: now });
    return ok({ id: row.id, message: '已存入模板库' });
};
H.omr_lib_get = async function (prm) {
    var o = await DB.get('omr_lib', I(prm.id));
    if (!o) return fail('模板库中不存在该模板');
    var layout = null;
    try { layout = JSON.parse(S(o.layout)); } catch (e) { layout = null; }
    return ok({ id: I(o.id), name: S(o.name), layout: layout });
};
H.omr_lib_del = async function (prm) {
    // 单机版无权限体系：原版登录密码二次确认在此省略
    var o = await DB.get('omr_lib', I(prm.id));
    if (!o) return fail('模板库中不存在该模板');
    await DB.del('omr_lib', I(o.id));
    return ok({ message: '模板已从模板库删除' });
};

// ================================================================
// 识别登记（omr_submit）与身份预匹配（omr_ident_preview）
// ================================================================
H.omr_ident_preview = async function (prm) {
    var r = await requireOmr(prm);
    if (r.err) return r.err;
    var idtype = (S(prm.idtype) === 'no') ? 'no' : 'seat';
    var raw = S(prm.ident).trim();
    if (raw === '' || raw.length > 30) return ok({ matched: false });
    var mt = await matchStudent(r.p, idtype, raw);
    if (!mt.ok) return ok({ matched: false });
    return ok({ matched: true, student: mt.student });
};
H.omr_submit = async function (prm) {
    var r = await requireOmr(prm);
    if (r.err) return r.err;
    var prj = r.p, pid = I(prj.id);
    var tid = I(prm.template_id);
    var idtype = (S(prm.idtype) === 'no') ? 'no' : 'seat';
    var raw = S(prm.ident).trim();
    if (raw === '' || raw.length > 30) return fail('识别身份为空或过长');
    var answers = {};
    var ansRaw = null;
    try { ansRaw = JSON.parse(S(prm.answers)); } catch (e) { ansRaw = null; }
    if (ansRaw && typeof ansRaw === 'object') {
        Object.keys(ansRaw).forEach(function (q) {
            var qk = S(q).replace(/[^0-9A-Za-z_]/g, '');
            var vv = S(ansRaw[q] == null ? '' : ansRaw[q]).toUpperCase().replace(/[^A-Ea-e]/g, '');
            if (qk !== '' && vv !== '') answers[qk] = vv;
        });
    }
    var flags = S(prm.flags).trim();
    if (flags.length > 200) flags = flags.slice(0, 200);
    // 身份匹配（多名拒绝 / 禁用拒绝）
    var mt = await matchStudent(prj, idtype, raw);
    if (!mt.ok) return fail(mt.message);
    var student = mt.student;
    // 轮次（current_round_no：传入轮次有效则用，否则最后一轮）
    var round = await currentRound(prj, prm);
    // 多页答题卡：题号 → 页归属（默认全部属第 1 页）
    var pg = Math.max(1, I(prm.pg) || 1);
    var qpg = {}, tplPages = 1, lay = null;
    if (tid > 0) {
        var t = await DB.get('omr_templates', tid);
        if (t && I(t.project_id) === pid) {
            try { lay = JSON.parse(S(t.layout)); } catch (e) { lay = null; }
            if (lay && typeof lay === 'object') {
                tplPages = Math.min(5, Math.max(1, I(lay.pages) || 1));
                (Array.isArray(lay.sections) ? lay.sections : []).forEach(function (sec) {
                    if (!sec || typeof sec !== 'object') return;
                    var spg = I(sec.pg) + 1;
                    var st2 = (sec.start == null || sec.start === '') ? 1 : I(sec.start);
                    var ct2 = Math.min(500, Math.max(1, I(sec.count)));
                    for (var q2 = st2; q2 < st2 + ct2; q2++) qpg[String(q2)] = spg;
                });
            }
        }
    }
    pg = Math.min(pg, Math.max(1, tplPages));
    // 与既有行合并（同生同题次唯一键 project+round+ident）：本页旧答案剔除（重扫覆盖）→ 并入本页新答案
    var ident = idtype + ':' + raw;
    var prev = (await DB.find('omr_results', function (x) {
        return I(x.project_id) === pid && I(x.round_no) === round && S(x.ident) === ident;
    }))[0] || null;
    var merged = {};
    if (prev) {
        try { var pa = JSON.parse(S(prev.answers)); if (pa && typeof pa === 'object') merged = pa; } catch (e) {}
    }
    Object.keys(merged).forEach(function (qk) { if ((I(qpg[String(qk)]) || 1) === pg) delete merged[qk]; });
    Object.keys(answers).forEach(function (qk) { merged[qk] = answers[qk]; });
    var pagesArr = [];
    if (prev) S(prev.pages).split(',').forEach(function (x) {
        var n = I(x);
        if (n > 0 && pagesArr.indexOf(n) < 0) pagesArr.push(n);
    });
    if (pagesArr.indexOf(pg) < 0) pagesArr.push(pg);
    pagesArr.sort(function (a, b) { return a - b; });
    var pagesCsv = pagesArr.join(',');
    // 判分（生效答案键完整 / 强制批改）
    var score = '';
    if (tid > 0 && lay) score = gradeFlat(await answerCtxOf(pid, lay), merged);
    var evNum = evalNum(score);
    var now = nowStr();
    // 结果落库（同生同题次重扫覆盖：更新答案/得分/警告/页码集合并刷新时间；不同题次各留一条）
    if (prev) {
        prev.template_id = tid; prev.student_id = student.id; prev.answers = JSON.stringify(merged);
        prev.score = score; prev.flags = flags; prev.pages = pagesCsv; prev.updated_at = now;
        await DB.update('omr_results', prev);
    } else {
        await DB.insert('omr_results', { project_id: pid, round_no: round, template_id: tid, student_id: student.id,
            ident: ident, answers: JSON.stringify(merged), score: score, flags: flags, pages: pagesCsv,
            created_at: now, updated_at: now });
    }
    // 题次维度登记+评价（识别成功即登记该生；有得分同步评价值，重扫按最后一次覆盖）
    var rr = await rrRow(pid, student.id, round);
    var already = !!(rr && rr.registered_at);
    if (!rr) {
        await DB.insert('record_rounds', { project_id: pid, student_id: student.id, round_no: round,
            registered_at: now, registered_by: 'scan', eval_value: '', eval_at: '', eval_cleared_at: '', created_at: now });
    }
    if (evNum !== '') {
        rr = await rrRow(pid, student.id, round);
        if (rr) { rr.eval_value = evNum; rr.eval_at = now; rr.eval_cleared_at = ''; await DB.update('record_rounds', rr); }
    }
    // records 表同步（汇总/导出口径）：登记保持；评价值=数值得分（重扫覆盖为最新）
    await recUpsert(pid, student.id, 'scan', evNum, now);
    var regCnt = await roundRegCount(pid, round);
    return ok({ round: round, student: student, already: already, score: score, answers: answers,
                pages_merged: pagesCsv, registered_count: regCnt, total: await totalStudentsIn(classIdsOf(prj)) });
};

// ================================================================
// 结果管理（omr_results / omr_result_del / omr_bind / omr_edit / omr_manual_save）
// ================================================================
H.omr_results = async function (prm) {
    var p = await getProject(prm);
    if (!p) return fail('项目不存在或无权限');
    var pid = I(p.id);
    var rows = (await DB.by('omr_results', 'project_id', pid)).sort(function (a, b) {
        return (I(b.round_no) - I(a.round_no)) || S(b.updated_at).localeCompare(S(a.updated_at)) || (I(b.id) - I(a.id));
    });
    var stuCache = {}, clsCache = {};
    var items = [];
    for (var i = 0; i < rows.length && items.length < 1000; i++) {
        var o = rows[i];
        var sid = I(o.student_id), stu = null;
        if (sid > 0) {
            if (!(sid in stuCache)) stuCache[sid] = await DB.get('students', sid);
            stu = stuCache[sid];
        }
        var clsName = '';
        if (stu) {
            var cid = I(stu.class_id);
            if (!(cid in clsCache)) {
                var c = await DB.get('classes', cid);
                clsCache[cid] = c ? S(c.name) : '';
            }
            clsName = clsCache[cid];
        }
        items.push({ id: I(o.id), round_no: I(o.round_no), ident: S(o.ident), template_id: I(o.template_id),
            student_id: sid, student_name: stu ? S(stu.name) : '', seat_no: stu ? S(stu.seat_no) : '',
            student_no: stu ? S(stu.student_no) : '', class_name: clsName,
            answers: S(o.answers), score: S(o.score), flags: S(o.flags), updated_at: S(o.updated_at) });
    }
    return ok({ items: items });
};
H.omr_result_del = async function (prm) {
    var p = await getProject(prm);
    if (!p) return fail('项目不存在或无权限');
    var row = await DB.get('omr_results', I(prm.id));
    if (!row || I(row.project_id) !== I(p.id)) return fail('结果不存在');
    await DB.del('omr_results', row.id);
    return ok({ message: '已删除该条识别结果' });
};
H.omr_bind = async function (prm) {
    var p = await getProject(prm);
    if (!p) return fail('项目不存在或无权限');
    var pid = I(p.id);
    var row = await DB.get('omr_results', I(prm.id));
    if (!row || I(row.project_id) !== pid) return fail('结果不存在');
    var sid = I(prm.student_id);
    var stu = await DB.get('students', sid);
    if (!stu || classIdsOf(p).indexOf(I(stu.class_id)) < 0) return fail('学生不在项目覆盖班级内');
    if (I(stu.disabled)) return fail('该学生已禁用');
    var cls = await DB.get('classes', I(stu.class_id));
    var cname = cls ? S(cls.name) : '';
    // 防重复绑定：同题次内同一学生已有其他结果行则拒绝（提示先删除旧行；不同题次各留一条）
    var oround = I(row.round_no) || 1;
    var dup = (await DB.find('omr_results', function (x) {
        return I(x.project_id) === pid && I(x.round_no) === oround && I(x.student_id) === sid && I(x.id) !== I(row.id);
    }))[0];
    if (dup) return fail(cname + ' ' + S(stu.name) + ' 本题次已有识别结果，请先删除旧行再绑定');
    row.student_id = sid;
    await DB.update('omr_results', row);
    // 绑定后按该题次补登记并同步数值得分
    var evNum = evalNum(S(row.score));
    var now = nowStr();
    var rr = await rrRow(pid, sid, oround);
    if (!rr) {
        await DB.insert('record_rounds', { project_id: pid, student_id: sid, round_no: oround,
            registered_at: now, registered_by: 'scan', eval_value: '', eval_at: '', eval_cleared_at: '', created_at: now });
    }
    if (evNum !== '') {
        rr = await rrRow(pid, sid, oround);
        if (rr) { rr.eval_value = evNum; rr.eval_at = now; rr.eval_cleared_at = ''; await DB.update('record_rounds', rr); }
    } else {
        var rec = await recRow(pid, sid);
        if (rec) { rec.registered = 1; await DB.update('records', rec); }
    }
    return ok({ message: '已绑定：' + cname + ' ' + S(stu.name) });
};
H.omr_manual_save = async function (prm) {
    var p = await getProject(prm);
    if (!p) return fail('项目不存在或无权限');
    if (S(p.mode) !== 'omr') return fail('非答题卡模式项目');
    var pid = I(p.id);
    var sid = I(prm.student_id);
    var stu = await DB.get('students', sid);
    if (!stu || classIdsOf(p).indexOf(I(stu.class_id)) < 0) return fail('学生不在项目覆盖班级内');
    if (I(stu.disabled)) return fail('该学生已禁用');
    var rounds = await roundsList(pid);
    var round = I(prm.round);
    if (rounds.indexOf(round) < 0) round = rounds[rounds.length - 1] || 1;
    var answers = {};
    var ansRaw = null;
    try { ansRaw = JSON.parse(S(prm.answers)); } catch (e) { ansRaw = null; }
    if (ansRaw && typeof ansRaw === 'object') {
        Object.keys(ansRaw).forEach(function (q) {
            var qk = S(q).replace(/[^0-9A-Za-z_]/g, '');
            var vv = S(ansRaw[q] == null ? '' : ansRaw[q]).toUpperCase().replace(/[^A-Ea-e]/g, '');
            if (qk !== '' && vv !== '') answers[qk] = vv;
        });
    }
    // 生效答案批改用项目绑定模板
    var bindTid = I(await DB.settingsGet('omr_tpl_bind_' + pid, '0'));
    var lay = await bindLayout(pid);
    var score = lay ? gradeFlat(await answerCtxOf(pid, lay), answers) : '';
    var evNum = evalNum(score);
    var now = nowStr();
    // 同生同题次已有识别行（任意 ident）→ 覆盖该行；否则新建（ident=manual:学生id）
    var ex = (await DB.find('omr_results', function (x) {
        return I(x.project_id) === pid && I(x.round_no) === round && I(x.student_id) === sid;
    }))[0] || null;
    var rid;
    if (ex) {
        ex.template_id = bindTid; ex.answers = JSON.stringify(answers); ex.score = score;
        ex.flags = ''; ex.updated_at = now;
        await DB.update('omr_results', ex);
        rid = I(ex.id);
    } else {
        var nr = await DB.insert('omr_results', { project_id: pid, round_no: round, template_id: bindTid,
            student_id: sid, ident: 'manual:' + sid, answers: JSON.stringify(answers), score: score,
            flags: '', pages: '1', created_at: now, updated_at: now });
        rid = nr.id;
    }
    // 题次维度登记 + 评价（有得分同步评价值；无得分仅登记）
    var rr = await rrRow(pid, sid, round);
    if (!rr) {
        await DB.insert('record_rounds', { project_id: pid, student_id: sid, round_no: round,
            registered_at: now, registered_by: 'manual', eval_value: '', eval_at: '', eval_cleared_at: '', created_at: now });
    }
    if (evNum !== '') {
        rr = await rrRow(pid, sid, round);
        if (rr) { rr.eval_value = evNum; rr.eval_at = now; rr.eval_cleared_at = ''; await DB.update('record_rounds', rr); }
    }
    await recUpsert(pid, sid, 'manual', evNum, now);
    return ok({ id: rid, round: round, score: score, message: '已录入' + (score !== '' ? '，得分 ' + score : '') });
};
H.omr_edit = async function (prm) {
    var p = await getProject(prm);
    if (!p) return fail('项目不存在或无权限');
    var pid = I(p.id);
    var row = await DB.get('omr_results', I(prm.id));
    if (!row || I(row.project_id) !== pid) return fail('结果不存在');
    var answers = {};
    var ansRaw = null;
    try { ansRaw = JSON.parse(S(prm.answers)); } catch (e) { ansRaw = null; }
    if (ansRaw && typeof ansRaw === 'object') {
        Object.keys(ansRaw).forEach(function (q) {
            var qk = S(q).replace(/[^0-9A-Za-z_]/g, '');
            var vv = S(ansRaw[q] == null ? '' : ansRaw[q]).toUpperCase().replace(/[^A-Ea-e]/g, '');
            if (qk !== '' && vv !== '') answers[qk] = vv;
        });
    }
    // 按模板答案键重算得分；识别行未挂模板 / 模板已删除 → 回退项目绑定模板
    var editTid = I(row.template_id);
    var bindTid = I(await DB.settingsGet('omr_tpl_bind_' + pid, '0'));
    if (editTid <= 0) editTid = bindTid;
    var trow = editTid > 0 ? await DB.get('omr_templates', editTid) : null;
    if (!trow || I(trow.project_id) !== pid) {
        editTid = bindTid;
        trow = editTid > 0 ? await DB.get('omr_templates', editTid) : null;
        if (trow && I(trow.project_id) !== pid) trow = null;
    }
    var score = '';
    if (trow) {
        var lay = null;
        try { lay = JSON.parse(S(trow.layout)); } catch (e) { lay = null; }
        if (lay) score = gradeFlat(await answerCtxOf(pid, lay), answers);
    }
    row.answers = JSON.stringify(answers);
    row.score = score;
    row.flags = '';
    row.template_id = editTid;
    row.updated_at = nowStr();
    await DB.update('omr_results', row);
    if (I(row.student_id) > 0 && score !== '') {
        var sid = I(row.student_id), eround = I(row.round_no) || 1;
        var evNum = evalNum(score);
        var rr = await rrRow(pid, sid, eround);
        if (rr) { rr.eval_value = evNum; rr.eval_at = nowStr(); rr.eval_cleared_at = ''; await DB.update('record_rounds', rr); }
        // records 汇总口径同步（与 omr_submit 一致：评价值=数值得分，修正覆盖为最新）
        await recUpsert(pid, sid, 'scan', evNum, nowStr());
    }
    return ok({ score: score, message: '答案已修正' });
};

// ================================================================
// 统计（omr_questions 按题 / omr_stats 按题次汇总）
// ================================================================
H.omr_questions = async function (prm) {
    var r = await requireOmr(prm);
    if (r.err) return r.err;
    var pid = I(r.p.id);
    var classIds = classIdsOf(r.p);
    var cls = I(prm.cls_id);
    if (cls <= 0 || classIds.indexOf(cls) < 0) cls = classIds[0] || 0;
    if (cls <= 0) return fail('项目未覆盖任何班级');
    var round = I(prm.round);
    if (round <= 0) {
        var rl = await roundsList(pid);
        round = rl[rl.length - 1] || 1;
    }
    // 绑定模板 + 生效答案键（模板最优先，独立录入仅补未设题）
    var kind = {}, key = {};
    var bindLay = await bindLayout(pid);
    if (bindLay) {
        var lc = layoutCtx(bindLay);
        kind = lc.kind; key = lc.tk;
        var pa = await indepGet(pid);
        Object.keys(pa).forEach(function (q) {
            var qk = S(q).replace(/[^0-9]/g, ''), vv = cleanAE(pa[q]);
            if (qk !== '' && vv !== '' && !key[qk]) key[qk] = vv;
        });
    }
    // 学生名单（名单下钻用）
    var stu = {};
    (await DB.by('students', 'class_id', cls)).forEach(function (s) {
        if (!I(s.disabled)) stu[I(s.id)] = { id: I(s.id), name: S(s.name), seat_no: S(s.seat_no) };
    });
    // 该题次已批改作答聚合
    var perq = {}, graded = 0;
    var oRows = await DB.by('omr_results', 'project_id', pid);
    for (var i = 0; i < oRows.length; i++) {
        var o = oRows[i];
        if (I(o.round_no) !== round || S(o.answers) === '') continue;
        var sid = I(o.student_id);
        var srow = sid > 0 ? await DB.get('students', sid) : null;
        if (!srow || I(srow.class_id) !== cls) continue;
        var dec = null;
        try { dec = JSON.parse(S(o.answers)); } catch (e) { dec = null; }
        if (!dec || typeof dec !== 'object') continue;
        graded++;
        Object.keys(dec).forEach(function (q) {
            var qk = S(q).replace(/[^0-9]/g, '');
            if (qk === '' || !kind[qk]) return;
            var sel = cleanAE(dec[q]);
            if (sel === '') return;
            if (!perq[qk]) perq[qk] = {};
            perq[qk][sid] = sel;
        });
    }
    var qs = [];
    Object.keys(kind).forEach(function (qk) {
        var selMap = perq[qk] || {};
        var counts = { A: 0, B: 0, C: 0, D: 0, E: 0 }, lists = { A: [], B: [], C: [], D: [], E: [] };
        var okArr = [], wrong = [];
        var ck = cleanAE(key[qk]);
        Object.keys(selMap).forEach(function (sidKey) {
            var pp = stu[I(sidKey)];
            if (!pp) return;
            var sel = selMap[sidKey];
            for (var ci = 0; ci < sel.length; ci++) {
                var ch = sel[ci];
                if (!(ch in counts)) continue;
                counts[ch]++;
                lists[ch].push(pp);
            }
            var isOk = (kind[qk] === 'multi')
                ? sel.split('').every(function (ch2) { return ck.indexOf(ch2) >= 0; })
                : (sel === ck);
            if (ck !== '') { if (isOk) okArr.push(pp); else wrong.push(pp); }
        });
        var answered = Object.keys(selMap).length;
        qs.push({ qno: I(qk), kind: S(kind[qk]), correct: ck, counts: counts, answered: answered,
                  okCnt: okArr.length,
                  rate: (answered > 0 && ck !== '') ? Math.round(okArr.length / answered * 1000) / 10 : null,
                  lists: lists, ok: okArr, wrong: wrong });
    });
    qs.sort(function (a, b) { return a.qno - b.qno; });
    return ok({ round: round, total_class: Object.keys(stu).length, graded: graded, questions: qs });
};
H.omr_stats = async function (prm) {
    var r = await requireOmr(prm);
    if (r.err) return r.err;
    var pid = I(r.p.id);
    var classIds = classIdsOf(r.p);
    var cls = I(prm.cls_id);
    if (cls <= 0 || classIds.indexOf(cls) < 0) cls = classIds[0] || 0;
    if (cls <= 0) return fail('项目未覆盖任何班级');
    var crow = await DB.get('classes', cls);
    if (!crow || crow.deleted_at) return fail('班级不存在');
    // 全班学生（已禁用不参与；按座号数值升序）
    var sRows = (await DB.by('students', 'class_id', cls)).filter(function (s) { return !I(s.disabled); });
    sRows.sort(function (a, b) {
        return ((parseInt(S(a.seat_no), 10) || 0) - (parseInt(S(b.seat_no), 10) || 0)) || (I(a.id) - I(b.id));
    });
    var students = {}, order = [];
    sRows.forEach(function (s) {
        students[I(s.id)] = { id: I(s.id), name: S(s.name), seat_no: S(s.seat_no), group_id: I(s.group_id), scores: {} };
        order.push(I(s.id));
    });
    // 分组列表（分组统计视图用）
    var groups = (await DB.by('stu_groups', 'class_id', cls))
        .sort(function (a, b) { return (I(a.sort) - I(b.sort)) || (I(a.id) - I(b.id)); })
        .map(function (gg) { return { id: I(gg.id), name: S(gg.name) }; });
    // 题次与各题次得分（score="对/总" → g/t；仅取已批改行）
    var rl = await roundsList(pid), titles = await roundsTitles(pid);
    var rlist = rl.map(function (rn) { return { no: rn, title: S(titles[rn] || '') }; });
    var oRows = await DB.by('omr_results', 'project_id', pid);
    for (var i = 0; i < oRows.length; i++) {
        var o = oRows[i];
        if (S(o.score) === '') continue;
        var sid = I(o.student_id);
        if (!students[sid]) continue;
        var srow2 = await DB.get('students', sid);
        if (!srow2 || I(srow2.class_id) !== cls) continue;
        var parts = S(o.score).split('/');
        var gNum = I(parts[0]), tNum = parts.length > 1 ? I(parts[1]) : 0;
        if (tNum <= 0) continue;
        students[sid].scores[I(o.round_no)] = { g: gNum, t: tNum };
    }
    var out = ok({ class: { id: I(crow.id), name: S(crow.name) }, rounds: rlist, groups: groups,
                   students: order.map(function (id) { return students[id]; }), total_class: order.length });
    // scored=任一题设分值 → 前端按「分数」口径呈现，否则按「正确题数」
    var bindLay = await bindLayout(pid);
    out.scored = bindLay ? Object.keys(layoutCtx(bindLay).tp).length > 0 : false;
    // with_sections=1&round=N：按绑定模板题组聚合该题次每生 {c,n}——单次题组雷达用
    if (I(prm.with_sections) === 1) {
        var secRound = (prm.round == null) ? await currentRound(r.p, prm) : I(prm.round);
        var sections = [], ctx = null;
        if (bindLay) {
            var lc = layoutCtx(bindLay);
            var key = lc.tk;
            var pa = await indepGet(pid);
            Object.keys(pa).forEach(function (q) {
                var qk = S(q).replace(/[^0-9]/g, ''), vv = cleanAE(pa[q]);
                if (qk !== '' && vv !== '' && !key[qk]) key[qk] = vv;
            });
            var force = (await DB.settingsGet('omr_force_grade_' + pid, '0')) === '1';
            (Array.isArray(bindLay.sections) ? bindLay.sections : []).forEach(function (sec) {
                if (!sec || typeof sec !== 'object') return;
                var k2 = S(sec.kind || 'single');
                if (k2 === 'blank' || k2 === 'short') return;   // 手写题不参与判分
                var st2 = (sec.start == null || sec.start === '') ? 1 : I(sec.start);
                var ct2 = Math.min(500, Math.max(1, I(sec.count)));
                var qs2 = [];
                for (var q2 = st2; q2 < st2 + ct2; q2++) qs2.push(q2);
                sections.push({ title: S(sec.title || '题组'), kind: k2, start: st2, count: ct2,
                                opts: Math.max(2, Math.min(6, I(sec.opts == null ? 4 : sec.opts))), qs: qs2 });
            });
            ctx = { kind: lc.kind, key: key, force: force };
        }
        var secRates = {};
        if (ctx && sections.length) {
            for (var j = 0; j < oRows.length; j++) {
                var o2 = oRows[j];
                var sid2 = I(o2.student_id);
                if (sid2 <= 0 || !students[sid2] || I(o2.round_no) !== secRound) continue;
                var ans = {};
                try { var d = JSON.parse(S(o2.answers)); if (d && typeof d === 'object') ans = d; } catch (e) { ans = {}; }
                var per = {};
                sections.forEach(function (sec, si) {
                    var c = 0, n = 0;
                    sec.qs.forEach(function (q) {
                        var qk2 = String(q);
                        var k3 = cleanAE(ctx.key[qk2]);
                        if (k3 === '') return;   // 无生效答案的题不参与
                        n++;
                        var sel = cleanAE(ans[qk2]);
                        if (sel === '') return;
                        var isOk = (sec.kind === 'multi')
                            ? sel.split('').every(function (ch) { return k3.indexOf(ch) >= 0; })
                            : (sel === k3);
                        if (isOk) c++;
                    });
                    if (n > 0) per[si] = { c: c, n: n };
                });
                if (Object.keys(per).length) secRates[String(sid2)] = per;
            }
        }
        out.sec_round = secRound;
        out.sections = sections.map(function (s) {
            var cp = {};
            Object.keys(s).forEach(function (k4) { if (k4 !== 'qs') cp[k4] = s[k4]; });
            return cp;
        });
        out.sec_rates = secRates;
    }
    return out;
};

// ================================================================
// 🖼 批改图留存（omr_img_save/list/get/del；对照 api.php L3526-3654）
// 图片本体存 files store（PNG Blob），omr_images 存索引；get 为图片二进制直出（GET 直链可看图）
// ================================================================
H.omr_img_save = async function (prm) {
    var req = await requireOmr(prm);
    if (req.err) return req.err;
    var m = /^data:image\/png;base64,([A-Za-z0-9+\/=]+)$/.exec(S(prm.data));
    if (!m) return fail('图片数据无效（仅支持 PNG）');
    var bin = '';
    try { bin = atob(m[1]); } catch (e) { return fail('图片数据无效（仅支持 PNG）'); }
    if (bin.length < 100 || bin.length > 10 * 1024 * 1024) return fail('图片数据无效或超过 10MB');
    var head = bin.substring(0, 8);
    if (head !== '\x89PNG\r\n\x1a\n') return fail('图片数据无效（PNG 魔数校验失败）');
    var u8 = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) u8[i] = bin.charCodeAt(i);
    var fname = 'omr_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6) + '.png';
    await DB.fileSave(fname, new Blob([u8], { type: 'image/png' }));
    var row = await DB.insert('omr_images', {
        school_id: 0, project_id: I(prm.project_id),
        round_no: Math.max(1, I(prm.round) || 1), pg: Math.max(1, I(prm.pg) || 1),
        ident: S(prm.ident).trim().substring(0, 32), student_id: I(prm.student_id),
        student_name: S(prm.name).trim().substring(0, 50), seat_no: S(prm.seat).trim().substring(0, 20),
        score: S(prm.score).trim().substring(0, 32), teacher_id: 0,
        file: 'localfile.php?name=' + encodeURIComponent(fname), created_at: nowStr()
    });
    return ok({ id: row.id, message: '批改图已保存到服务器' });
};
H.omr_img_list = async function (prm) {
    var req = await requireOmr(prm);
    if (req.err) return req.err;
    var pid = I(prm.project_id), round = I(prm.round);
    var rows = await DB.find('omr_images', function (r) {
        return I(r.project_id) === pid && (round <= 0 || I(r.round_no) === round);
    });
    rows.sort(function (a, b) { return I(b.id) - I(a.id); });
    var items = rows.slice(0, 2000).map(function (r) {
        return { id: I(r.id), round_no: I(r.round_no), pg: I(r.pg), student_id: I(r.student_id),
            name: S(r.student_name), seat: S(r.seat_no), score: S(r.score),
            teacher_id: I(r.teacher_id), teacher: '教师', created_at: S(r.created_at) };
    });
    return ok({ items: items, can_upload: 1 });
};
H.omr_img_get = async function (prm) {
    var pid = I(prm.project_id), imgId = I(prm.id);
    var row = (await DB.find('omr_images', function (r) { return I(r.id) === imgId && I(r.project_id) === pid; }))[0];
    var fname = row ? localFileName2(S(row.file)) : '';
    var blob = fname ? await DB.fileGet(fname) : null;
    if (!blob) return { status: 404, body: JSON.stringify({ success: false, message: '图片不存在' }),
        headers: { 'Content-Type': 'application/json; charset=utf-8' } };
    return { status: 200, body: blob, headers: { 'Content-Type': 'image/png', 'Cache-Control': 'max-age=86400' } };
};
H.omr_img_del = async function (prm) {
    var req = await requireOmr(prm);
    if (req.err) return req.err;
    var pid = I(prm.project_id), imgId = I(prm.id);
    var row = (await DB.find('omr_images', function (r) { return I(r.id) === imgId && I(r.project_id) === pid; }))[0];
    if (!row) return fail('记录不存在');
    var fname = localFileName2(S(row.file));
    if (fname) await DB.del('files', fname);
    await DB.del('omr_images', row.id);
    return ok({ message: '已删除' });
};
/** localfile.php?name=xxx → 文件名 */
function localFileName2(u) {
    var m = /[?&]name=([^&]+)/.exec(S(u));
    return m ? decodeURIComponent(m[1]) : '';
}

AL.reg(H);
})(typeof self !== 'undefined' ? self : this);
