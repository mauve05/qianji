/** [组A] 登记评价接口（IndexedDB 实现）
 *  覆盖：toggle_register / batch_register / evaluate / batch_evaluate /
 *        scan_register / scan_register_multi / quiz_answer / quiz_compare / quiz_stats /
 *        view_state / view_stats / view_version
 *  语义对照源版 qj/api.php（L182-334 / L338-392 / L556-816 / L819-1250 / L1253-1493 / L1496-1769）；
 *  登记评价主体移植自旧版 project_view.html pvFetch/pvFetch2（剥离页面级依赖，lockState/round 等改由 prm 传入）。
 *  数据层：全局 DB（js/db.js）；单机版无权限校验，teacher/created_by 类归属字段不写。
 */
(function (g) {
'use strict';
var AL = g.ApiLocal; var I = AL.I, S = AL.S, ok = AL.ok, fail = AL.fail;

// ===== 基础工具 =====
function tsOf(str) {   // 'YYYY-MM-DD HH:MM:SS' → 秒级时间戳（0=无效）
    if (!str) return 0;
    var t = new Date(String(str).replace(' ', 'T')).getTime();
    return isNaN(t) ? 0 : Math.floor(t / 1000);
}
function nowSec() { return Math.floor(Date.now() / 1000); }
function maxTsPart(arr) {   // 行内多字段取 GREATEST(COALESCE(x,'2000-01-01'))
    var m = '';
    for (var i = 0; i < arr.length; i++) {
        var t = S(arr[i]) || '2000-01-01';
        if (t > m) m = t;
    }
    return m;
}
// CRC32（IEEE 802.3，与 PHP crc32 同多项式；view_version 指纹的 eval_value 校验和）
var CRC_TAB = (function () {
    var t = [], c, k, n;
    for (n = 0; n < 256; n++) {
        c = n;
        for (k = 0; k < 8; k++) c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
        t[n] = c >>> 0;
    }
    return t;
})();
function crc32(str) {
    str = S(str);
    var bytes = [], c = 0xFFFFFFFF, i, code;
    for (i = 0; i < str.length; i++) {   // UTF-8 编码后逐字节
        code = str.charCodeAt(i);
        if (code < 0x80) bytes.push(code);
        else if (code < 0x800) bytes.push(0xC0 | (code >> 6), 0x80 | (code & 63));
        else if (code >= 0xD800 && code <= 0xDBFF && i + 1 < str.length && str.charCodeAt(i + 1) >= 0xDC00 && str.charCodeAt(i + 1) <= 0xDFFF) {
            var cp = 0x10000 + ((code - 0xD800) << 10) + (str.charCodeAt(i + 1) - 0xDC00);
            bytes.push(0xF0 | (cp >> 18), 0x80 | ((cp >> 12) & 63), 0x80 | ((cp >> 6) & 63), 0x80 | (cp & 63));
            i++;
        } else bytes.push(0xE0 | (code >> 12), 0x80 | ((code >> 6) & 63), 0x80 | (code & 63));
    }
    for (i = 0; i < bytes.length; i++) c = CRC_TAB[(c ^ bytes[i]) & 0xFF] ^ (c >>> 8);
    return (c ^ 0xFFFFFFFF) >>> 0;
}
function parseQr(content) {   // 原 parse_student_qr_content：djxh+编号 → 编号；非个人码返回 null
    content = S(content).trim();
    return content.indexOf('djxh') === 0 ? content.slice(4) : null;
}

// ===== 评价模式（原 get_eval_modes / eval_mode_info / validate_eval_value，自含实现） =====
var SYS_EVAL_MODES = {
    smile:   { name: '笑脸', options: { '😊': '笑脸', '😐': '一般', '😞': '加油' } },
    points:  { name: '十分', options: { '0': '0', '1': '1', '2': '2', '3': '3', '4': '4', '5': '5', '6': '6', '7': '7', '8': '8', '9': '9', '10': '10' } },
    score:   { name: '数值', options: null },
    grade:   { name: '优良', options: { '优': '优', '良': '良', '合格': '合格', '不合格': '不合格', '待定': '待定', '特殊': '特殊' } },
    tf:      { name: '对错', options: { '√': '√', '×': '×', '待定': '待定' } },
    star:    { name: '星级', options: { '★': '一星', '★★': '二星', '★★★': '三星', '★★★★': '四星', '★★★★★': '五星' } },
    comment: { name: '评语', options: null },
    abcd:    { name: '答题', options: { 'A': 'A', 'B': 'B', 'C': 'C', 'D': 'D' } }
};
async function evalModeInfo(mode) {
    mode = S(mode);
    if (mode.length > 1 && mode[0] === 'c' && /^\d+$/.test(mode.slice(1))) {
        var cid = I(mode.slice(1));
        var row = await DB.get('eval_modes', cid);
        if (!row) return null;
        var opts = S(row.options).replace(/\r/g, '').split('\n').map(function (v) { return v.trim(); })
            .filter(function (v, i, a) { return v !== '' && a.indexOf(v) === i; });
        var o = null;
        if (opts.length) { o = {}; opts.forEach(function (v) { o[v] = v; }); }
        return { name: S(row.name) + '（自定义）', options: o };
    }
    return SYS_EVAL_MODES[mode] || null;
}
async function evalOk(mode, value) {   // 原 validate_eval_value
    var info = await evalModeInfo(mode);
    if (!info) return false;
    var opts = info.options;
    if (opts == null) {
        if (mode === 'score') {
            var v = S(value).trim();
            return v !== '' && isFinite(Number(v)) && Number(v) >= 0 && Number(v) <= 100;
        }
        return value !== '' && S(value).length <= 200;
    }
    return Object.prototype.hasOwnProperty.call(opts, value);
}

// ===== 项目/轮次辅助 =====
function isRounded(p) {   // 原 project_is_rounded
    return ['multi', 'quiz', 'raise', 'omr'].indexOf(S(p && p.mode || 'count')) >= 0;
}
function classIdsOf(p) {   // 项目覆盖班级（多班级项目优先 class_ids）
    return (Array.isArray(p.class_ids) && p.class_ids.length)
        ? p.class_ids.map(I).filter(function (v) { return v > 0; }) : [I(p.class_id)];
}
async function roundsList(pid) {   // 原 project_rounds_list：无轮次自动补第 1 次
    var rows = await DB.by('project_rounds', 'project_id', pid);
    rows.sort(function (a, b) { return I(a.round_no) - I(b.round_no); });
    if (rows.length) return rows.map(function (r) { return I(r.round_no); });
    await DB.insert('project_rounds', { project_id: pid, round_no: 1, title: null, correct_opts: null, created_at: AL.nowStr() });
    return [1];
}
async function roundsTitles(pid) {   // 原 project_rounds_titles：仅含已命名轮次 {round_no: title}
    var rows = await DB.by('project_rounds', 'project_id', pid), t = {};
    rows.forEach(function (r) { if (S(r.title) !== '') t[I(r.round_no)] = S(r.title); });
    return t;
}
async function curRound(pid, prm) {   // 原 current_round_no：round 无效取最后一次
    var rounds = await roundsList(pid);
    var r = I(prm.round);
    return { round: (r > 0 && rounds.indexOf(r) >= 0) ? r : (rounds[rounds.length - 1] || 0), rounds: rounds };
}
function lockStateOf(prm) {   // 原 $lock_state：auto=自动锁定 all=临时全锁 none=临时全解锁
    var st = S(prm.lock || 'auto');
    if (['auto', 'all', 'none'].indexOf(st) < 0) st = 'auto';
    return st;
}
/** 公共上下文：项目行 + 覆盖班级 + 锁定态（对照 api.php 全局闸门） */
async function ctx(prm) {
    var pid = I(prm.project_id);
    var p = await DB.get('projects', pid);
    if (!p || p.deleted_at) return { err: fail('项目不存在或无权限') };
    var classIds = classIdsOf(p);
    if (!classIds.length) return { err: fail('项目不存在或无权限') };
    var lock = lockStateOf(prm);
    if (lock === 'all') return { err: fail('已临时全部锁定，不能进行登记操作（可点击「锁定」开关临时解锁）') };
    return { p: p, pid: I(p.id), classIds: classIds, lock: lock };
}
function locked(p, registered, registeredAt) {   // 原 record_is_locked
    var ls = I(p && p.lock_seconds);
    if (ls <= 0) return false;
    if (!registered || !registeredAt) return false;
    return (nowSec() - tsOf(registeredAt)) > ls;
}
function histDate(p, prm) {   // 原 hist_date_from_post（单机版无权限闸门：仅打卡模式 + 合法历史日期）
    var d = S(prm.date);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(d)) return '';
    if (S(p.mode || 'count') !== 'daily') return '';
    if (d >= AL.dateStr()) return '';
    return d;
}
function idsOf(prm) {   // student_ids 逗号串 → 去重正整数数组
    var seen = {}, out = [];
    S(prm.student_ids).split(',').forEach(function (v) {
        var n = I(v);
        if (n > 0 && !seen[n]) { seen[n] = 1; out.push(n); }
    });
    return out;
}
function clrEval(row) {   // 清除评价并按原值保留清除留痕
    if (S(row.eval_value) !== '') row.eval_cleared_at = AL.nowStr();
    row.eval_value = '';
    row.eval_at = '';
}

// ===== 记录行辅助（原 pvRecRow/pvRecUpd/pvDayRow/pvDayInsert/pvRRRow/pvRRInsert） =====
async function stuRow(sid, classIds) {   // 在册（未禁用）且属于覆盖班级；否则 null
    var s = await DB.get('students', sid);
    if (!s || s.disabled) return null;
    if (classIds && classIds.indexOf(I(s.class_id)) < 0) return null;
    return s;
}
async function classNameOf(cid) {
    var c = await DB.get('classes', I(cid));
    return c ? S(c.name) : '';
}
async function totalIn(classIds) {   // 原 total_students_in（已禁用不计）
    var n = 0;
    for (var i = 0; i < classIds.length; i++) {
        var rows = await DB.by('students', 'class_id', classIds[i]);
        rows.forEach(function (s) { if (!s.disabled) n++; });
    }
    return n;
}
async function recRow(pid, sid) {
    var rows = await DB.by('records', 'project_id', pid);
    for (var i = 0; i < rows.length; i++) if (I(rows[i].student_id) === sid) return rows[i];
    return null;
}
async function recUpd(pid, sid, f) {   // 有行改行，无行建行（原 INSERT ... ON DUPLICATE KEY UPDATE）
    var row = await recRow(pid, sid);
    if (row) {
        for (var k in f) row[k] = f[k];
        await DB.update('records', row);
        return row;
    }
    var nr = { project_id: pid, student_id: sid, registered: 0, registered_at: '', registered_by: '', eval_value: '', eval_at: '', eval_cleared_at: '' };
    for (var k2 in f) nr[k2] = f[k2];
    return await DB.insert('records', nr);
}
async function dayRow(pid, sid, date) {
    var rows = await DB.by('record_days', 'project_id', pid);
    for (var i = 0; i < rows.length; i++) if (I(rows[i].student_id) === sid && rows[i].reg_date === date) return rows[i];
    return null;
}
async function dayInsert(pid, sid, date) {   // 原 INSERT IGNORE INTO record_days
    var row = await dayRow(pid, sid, date);
    if (row) return row;
    return await DB.insert('record_days', { project_id: pid, student_id: sid, reg_date: date, created_at: AL.nowStr(), eval_value: '', eval_at: '' });
}
async function rrRow(pid, sid, round) {
    var rows = await DB.by('record_rounds', 'project_id', pid);
    for (var i = 0; i < rows.length; i++) {
        if (I(rows[i].student_id) === sid && I(rows[i].round_no) === round) return rows[i];
    }
    return null;
}
async function rrInsert(pid, sid, round, by) {   // 原 INSERT IGNORE INTO record_rounds
    var row = await rrRow(pid, sid, round);
    if (row) return row;
    var now = AL.nowStr();
    return await DB.insert('record_rounds', { project_id: pid, student_id: sid, round_no: round, registered_at: now, registered_by: by || 'page', eval_value: '', eval_at: '', eval_cleared_at: '', created_at: now });
}
async function regCnt(pid) {   // 原 registered_count
    var rows = await DB.by('records', 'project_id', pid), n = 0;
    rows.forEach(function (r) { if (parseInt(r.registered, 10) === 1) n++; });
    return n;
}
async function evCnt(pid) {   // 原 evaluated_count
    var rows = await DB.by('records', 'project_id', pid), n = 0;
    rows.forEach(function (r) { if (S(r.eval_value) !== '') n++; });
    return n;
}
async function roundCnts(pid, round) {   // 原 round_counts → [reg, ev]
    var rows = await DB.by('record_rounds', 'project_id', pid), reg = 0, ev = 0;
    rows.forEach(function (r) {
        if (I(r.round_no) !== round || !r.registered_at) return;
        reg++;
        if (S(r.eval_value) !== '') ev++;
    });
    return [reg, ev];
}

// ===== 原 quiz_stats_payload（api.php L338-392） =====
async function quizPayload(pid, round, classIds) {
    var counts = { A: 0, B: 0, C: 0, D: 0 }, lists = { A: [], B: [], C: [], D: [] }, rows = [], answered = 0;
    var nb = {};
    var rrs = await DB.by('record_rounds', 'project_id', pid);
    for (var i = 0; i < rrs.length; i++) {   // 全部题次各生选项（相邻题次角标用）
        var rr = rrs[i];
        if (!rr.registered_at) continue;
        var sid0 = I(rr.student_id);
        if (!(await stuRow(sid0, classIds))) continue;
        var opt0 = S(rr.eval_value).toUpperCase().trim();
        if (!counts.hasOwnProperty(opt0)) continue;
        nb[I(rr.round_no)] = nb[I(rr.round_no)] || {};
        nb[I(rr.round_no)][sid0] = opt0;
    }
    for (var j = 0; j < rrs.length; j++) {   // 本题次统计与结构化名单
        var r2 = rrs[j];
        if (I(r2.round_no) !== round || !r2.registered_at) continue;
        var sid = I(r2.student_id);
        var stu = await stuRow(sid, classIds);
        if (!stu) continue;
        var opt = S(r2.eval_value).toUpperCase().trim();
        if (!counts.hasOwnProperty(opt)) continue;
        counts[opt]++;
        answered++;
        var clsName = await classNameOf(stu.class_id);
        lists[opt].push((clsName !== '' ? clsName + ' ' : '') + S(stu.name) + (S(stu.seat_no) !== '' ? '（' + S(stu.seat_no) + '）' : ''));
        rows.push({ opt: opt, id: sid, name: S(stu.name), seat: S(stu.seat_no), cls: clsName,
                    prev: (nb[round - 1] || {})[sid] || '', next: (nb[round + 1] || {})[sid] || '' });
    }
    var total = await totalIn(classIds);
    var correct = [];   // 该题次正确答案（project_rounds.correct_opts）
    var prows = await DB.by('project_rounds', 'project_id', pid);
    for (var k = 0; k < prows.length; k++) {
        if (I(prows[k].round_no) !== round) continue;
        S(prows[k].correct_opts).toUpperCase().split(',').forEach(function (co) {
            co = co.trim();
            if (counts.hasOwnProperty(co) && correct.indexOf(co) < 0) correct.push(co);
        });
        break;
    }
    correct.sort();
    return { round: round, total: total, answered: answered, unanswered: Math.max(0, total - answered),
             counts: counts, lists: lists, rows: rows, correct: correct };
}

// ===== 页面数据版本指纹（原 view_version_fingerprint，api.php L182-215） =====
async function versionFingerprint(p, classIds) {
    var pid = I(p.id), v = [];
    var recs = await DB.by('records', 'project_id', pid);
    var reg = 0, ev = 0, ts = recs.length ? '2000-01-01' : '';
    recs.forEach(function (r) {
        if (parseInt(r.registered, 10) === 1) reg++;
        if (S(r.eval_value) !== '') ev++;
        var t = maxTsPart([r.registered_at, r.eval_at, r.eval_cleared_at]);
        if (t > ts) ts = t;
    });
    v.push(recs.length + ':' + reg + ':' + ev + ':' + ts);
    var days = await DB.by('record_days', 'project_id', pid);
    var dts = '', dev = 0, dets = days.length ? '2000-01-01' : '', ec = 0;
    days.forEach(function (d) {
        if (S(d.created_at) > dts) dts = S(d.created_at);
        if (S(d.eval_value) !== '') {
            dev++;
            ec += crc32(d.eval_value);
        }
        if (S(d.eval_at) > dets) dets = S(d.eval_at);
    });
    v.push(days.length + ':' + dts + ':' + dev + ':' + dets + ':' + ec);
    if (isRounded(p)) {   // 次项/题次模式：轮次定义与轮次登记/评价纳入指纹
        var prows = await DB.by('project_rounds', 'project_id', pid);
        var pts = '', ptt = 0;
        prows.forEach(function (r) {
            if (S(r.created_at) > pts) pts = S(r.created_at);
            if (S(r.title) !== '') ptt++;
        });
        v.push(prows.length + ':' + pts + ':' + ptt);
        var rrs = await DB.by('record_rounds', 'project_id', pid);
        var rreg = 0, rev = 0, rts = rrs.length ? '2000-01-01' : '';
        rrs.forEach(function (r) {
            if (r.registered_at) rreg++;
            if (S(r.eval_value) !== '') rev++;
            var t = maxTsPart([r.registered_at, r.eval_at, r.eval_cleared_at, r.created_at]);
            if (t > rts) rts = t;
        });
        v.push(rrs.length + ':' + rreg + ':' + rev + ':' + rts);
    }
    var sc = 0;   // 名单人数（含禁用，与原 SQL 同口径）
    for (var i = 0; i < classIds.length; i++) sc += (await DB.by('students', 'class_id', classIds[i])).length;
    v.push(sc);
    return v.join('|');
}

// ===== 扫码登记单码核心（原 scan_register_one，api.php L1255-1442） =====
// 返回 payload；失败返回 {_err: 提示}。withStats=false（批量用）省略全班统计。
async function scanOne(p, pid, classIds, code, opt, withStats, prm) {
    code = S(code).trim();
    var isRaise = S(p.mode) === 'raise';
    var lookupNo, lookupField;
    if (isRaise) {   // 举牌模式：识别号码=学生序号（座号）
        lookupNo = code;
        lookupField = 'seat_no';
        if (lookupNo === '') return { _err: '举牌号码为空' };
    } else {         // 其余模式：兼容 djxh 码 / 原始编号
        lookupNo = parseQr(code);
        if (lookupNo === null) lookupNo = code;
        lookupField = 'student_no';
        if (lookupNo === '') return { _err: '二维码内容为空' };
    }
    var matches = [];   // 在项目覆盖班级内查找（含已禁用以便给出明确提示）
    for (var i = 0; i < classIds.length; i++) {
        var srows = await DB.by('students', 'class_id', classIds[i]);
        srows.forEach(function (s) { if (S(s[lookupField]) === lookupNo) matches.push(s); });
    }
    var noLabel = isRaise ? '序号' : '编号';
    if (!matches.length) return { _err: '未找到' + noLabel + '为 "' + lookupNo + '" 的学生（须为项目覆盖班级的学生' + (isRaise ? '举牌序号' : '二维码') + '）' };
    if (matches.length > 1) {
        var names = [];
        for (var m = 0; m < matches.length; m++) names.push(await classNameOf(matches[m].class_id) + ' ' + S(matches[m].name));
        return { _err: '该' + noLabel + '在多个班级存在（' + names.join('、') + '），无法唯一识别' };
    }
    var student = matches[0];
    if (student.disabled) return { _err: await classNameOf(student.class_id) + ' ' + S(student.name) + ' 已禁用，不参与登记' };
    var sid = I(student.id);
    var stuInfo = { id: sid, name: S(student.name), seat_no: S(student.seat_no), student_no: S(student.student_no), class_name: await classNameOf(student.class_id) };

    var rec = await recRow(pid, sid);
    var already = rec && parseInt(rec.registered, 10) === 1;

    // 历史补登记（打卡模式）：date=指定日期，只写 record_days，不回写 records
    var hist = histDate(p, prm);
    if (S(p.mode || 'count') === 'daily' && hist !== '') {
        var dayRowH = await dayRow(pid, sid, hist);
        var alreadyDay = !!dayRowH;
        if (!alreadyDay) await dayInsert(pid, sid, hist);
        var payloadH = { success: true, student: stuInfo, already: alreadyDay, hist_date: hist, eval_value: dayRowH ? S(dayRowH.eval_value) : '' };
        if (withStats) { payloadH.registered_count = await regCnt(pid); payloadH.total = await totalIn(classIds); }
        return payloadH;
    }

    // 次项/题次模式：按当前轮次登记（同一码允许重复识别，每次都刷新最新结果）
    if (isRounded(p)) {
        var cr = await curRound(pid, prm);
        var round = cr.round;
        var rr = await rrRow(pid, sid, round);
        var alreadyR = !!(rr && rr.registered_at);
        if (!alreadyR) await rrInsert(pid, sid, round, 'scan');
        // 答题/举牌模式：本次识别携带的选项作为该生本题最新答案（重复识别即改答案）
        var optU = S(opt).toUpperCase().trim();
        var quiz = ['quiz', 'raise'].indexOf(S(p.mode)) >= 0;
        var evalValue = rr ? S(rr.eval_value) : '';
        if (quiz && ['A', 'B', 'C', 'D'].indexOf(optU) >= 0) {
            var rrU = await rrRow(pid, sid, round);
            rrU.eval_value = optU; rrU.eval_at = AL.nowStr(); rrU.eval_cleared_at = '';
            await DB.update('record_rounds', rrU);
            evalValue = optU;
        }
        var payloadR = { success: true, round: round, already: alreadyR, eval_value: evalValue, student: stuInfo };
        if (withStats) {
            var rc = await roundCnts(pid, round);
            payloadR.registered_count = rc[0];
            payloadR.evaluated_count = rc[1];
            payloadR.total = await totalIn(classIds);
            if (quiz) payloadR.quiz = await quizPayload(pid, round, classIds);
        }
        return payloadR;
    }

    // 普通模式：登记（已登记则保持）
    if (!already) await recUpd(pid, sid, { registered: 1, registered_at: AL.nowStr(), registered_by: 'scan' });

    // 打卡模式项目：扫码即记当日打卡（即使此前已登记过，也补记今天）
    var isDaily = S(p.mode || 'count') === 'daily';
    var dayAlready = false, dayEval = '';
    if (isDaily) {
        var dayD = await dayRow(pid, sid, AL.dateStr());
        if (dayD) { dayAlready = true; dayEval = S(dayD.eval_value); }
        else await dayInsert(pid, sid, AL.dateStr());
    }
    var payload = { success: true, student: stuInfo,
                    already: isDaily ? dayAlready : already,
                    eval_value: isDaily ? dayEval : (rec ? S(rec.eval_value) : '') };
    if (withStats) { payload.registered_count = await regCnt(pid); payload.total = await totalIn(classIds); }
    return payload;
}

// ================================================================
// 接口处理器
// ================================================================
var H = {};

/** toggle_register：登记 / 取消登记（原 api.php L819-999） */
H.toggle_register = async function (prm) {
    var c = await ctx(prm);
    if (c.err) return c.err;
    var pid = c.pid, p = c.p, classIds = c.classIds, lock = c.lock;
    var sid = I(prm.student_id);
    var hist = histDate(p, prm);
    var regDate = hist !== '' ? hist : AL.dateStr();
    var stu = await stuRow(sid, classIds);
    if (!stu) return fail('学生不存在');
    var isDaily = S(p.mode || 'count') === 'daily';

    if (isDaily) {   // 打卡模式：每天独立登记/取消（历史编辑=指定日期）
        var dayRowD = await dayRow(pid, sid, regDate);
        var checkingIn = !dayRowD;
        if (!checkingIn && hist === '' && lock !== 'none') {   // 取消今日打卡：按登记时间校验自动锁定
            var recT = await recRow(pid, sid);
            if (locked(p, recT ? parseInt(recT.registered, 10) : 0, recT ? recT.registered_at : '')) {
                return fail('该学生登记已超过锁定时间，已自动锁定（可用「锁定」开关临时解锁）');
            }
        }
        if (checkingIn) {
            await dayInsert(pid, sid, regDate);
            if (hist === '') await recUpd(pid, sid, { registered: 1, registered_at: AL.nowStr(), registered_by: 'click' });
        } else {
            await DB.del('record_days', dayRowD.id);
            if (hist === '') {   // 取消打卡：同时清除已有评价并记录清除时间
                var r2 = await recRow(pid, sid);
                if (r2) { clrEval(r2); r2.registered = 0; r2.registered_at = ''; r2.registered_by = ''; await DB.update('records', r2); }
            }
        }
        var dayCnt = 0;   // 该日期打卡人数（该生所在班级）
        var dAll = await DB.by('record_days', 'project_id', pid);
        for (var i = 0; i < dAll.length; i++) {
            if (dAll[i].reg_date !== regDate) continue;
            var ss = await DB.get('students', I(dAll[i].student_id));
            if (ss && I(ss.class_id) === I(stu.class_id)) dayCnt++;
        }
        return ok({ registered: checkingIn, regts: checkingIn ? nowSec() : 0,
            daily_today_count: dayCnt, daily_day_count: dayCnt,
            total: await totalIn(classIds), registered_count: await regCnt(pid), evaluated_count: await evCnt(pid) });
    }

    if (isRounded(p)) {   // 次项/题次模式：按当前轮次登记/取消（取消=删除该次记录）
        var crT = await curRound(pid, prm);
        var roundT = crT.round;
        var rrT = await rrRow(pid, sid, roundT);
        var checking = !rrT;
        if (!checking && lock !== 'none' && locked(p, 1, rrT.registered_at)) {
            return fail('该生本次登记已超过锁定时间，已自动锁定（可用「锁定」开关临时解锁）');
        }
        if (checking) await rrInsert(pid, sid, roundT, 'click');
        else await DB.del('record_rounds', rrT.id);
        var rcT = await roundCnts(pid, roundT);
        return ok({ registered: checking, regts: checking ? nowSec() : 0, round: roundT,
            total: await totalIn(classIds), registered_count: rcT[0], evaluated_count: rcT[1] });
    }

    // 普通模式：查询当前状态后切换
    var row = await recRow(pid, sid);
    var newState = (row && parseInt(row.registered, 10) === 1) ? 0 : 1;
    if (newState === 0 && lock !== 'none' && locked(p, row ? parseInt(row.registered, 10) : 0, row ? row.registered_at : '')) {
        return fail('该学生登记已超过锁定时间，已自动锁定（可用「锁定」开关临时解锁）');
    }
    if (newState === 1) await recUpd(pid, sid, { registered: 1, registered_at: AL.nowStr(), registered_by: 'click' });
    else {   // 取消登记：同时清除已有评价并记录清除时间
        var r3 = await recRow(pid, sid);
        if (r3) { clrEval(r3); r3.registered = 0; r3.registered_at = ''; r3.registered_by = ''; await DB.update('records', r3); }
    }
    return ok({ registered: newState === 1, regts: newState === 1 ? nowSec() : 0,
        total: await totalIn(classIds), registered_count: await regCnt(pid), evaluated_count: await evCnt(pid) });
};

/** batch_register：批量登记（原 api.php L1002-1085） */
H.batch_register = async function (prm) {
    var c = await ctx(prm);
    if (c.err) return c.err;
    var pid = c.pid, p = c.p, classIds = c.classIds;
    var ids = idsOf(prm);
    if (!ids.length) return fail('未选择学生');
    var isDailyB = S(p.mode || 'count') === 'daily';
    var histB = histDate(p, prm);

    if (isDailyB && histB !== '') {   // 历史补登记：只写 record_days，不回写 records
        for (var bi = 0; bi < ids.length; bi++) {
            if (await stuRow(ids[bi], classIds)) await dayInsert(pid, ids[bi], histB);
        }
        var dayCntB = 0;
        var dAllB = await DB.by('record_days', 'project_id', pid);
        for (var bj = 0; bj < dAllB.length; bj++) {
            if (dAllB[bj].reg_date !== histB) continue;
            var ssB = await DB.get('students', I(dAllB[bj].student_id));
            if (ssB && classIds.indexOf(I(ssB.class_id)) >= 0) dayCntB++;
        }
        return ok({ regts: nowSec(), daily_day_count: dayCntB,
            registered_count: await regCnt(pid), evaluated_count: await evCnt(pid), total: await totalIn(classIds) });
    }

    if (isRounded(p)) {   // 次项/题次模式：按当前轮次批量登记（已登记的不再刷新登记时间）
        var crB = await curRound(pid, prm);
        var roundB = crB.round;
        for (var bk = 0; bk < ids.length; bk++) {
            if (await stuRow(ids[bk], classIds)) await rrInsert(pid, ids[bk], roundB, 'batch');
        }
        var rcB = await roundCnts(pid, roundB);
        return ok({ regts: nowSec(), round: roundB,
            registered_count: rcB[0], evaluated_count: rcB[1], total: await totalIn(classIds) });
    }

    for (var bl = 0; bl < ids.length; bl++) {   // 普通模式；打卡模式已打今日卡的不刷新登记时间
        var sidB = ids[bl];
        if (!(await stuRow(sidB, classIds))) continue;
        if (isDailyB) {
            var hasToday = await dayRow(pid, sidB, AL.dateStr());
            if (!hasToday) await recUpd(pid, sidB, { registered: 1, registered_at: AL.nowStr(), registered_by: 'batch' });
            await dayInsert(pid, sidB, AL.dateStr());
        } else {
            await recUpd(pid, sidB, { registered: 1, registered_at: AL.nowStr(), registered_by: 'batch' });
        }
    }
    return ok({ regts: nowSec(),
        registered_count: await regCnt(pid), evaluated_count: await evCnt(pid), total: await totalIn(classIds) });
};

/** batch_evaluate：批量设置相同评价（原 api.php L1088-1250） */
H.batch_evaluate = async function (prm) {
    var c = await ctx(prm);
    if (c.err) return c.err;
    var pid = c.pid, p = c.p, classIds = c.classIds, lock = c.lock;
    var value = S(prm.value).trim();
    var remove = I(prm.remove);
    var ids2 = idsOf(prm);
    if (!ids2.length) return fail('未选择学生');
    var isQuizE = ['quiz', 'raise'].indexOf(S(p.mode)) >= 0;
    var isDailyE = S(p.mode || 'count') === 'daily';
    if (!remove && !isQuizE && !(await evalOk(p.eval_mode, value))) {
        return fail('评价内容不符合当前评价模式要求');   // quiz 项目的 A-D 由下方答题分支校验
    }
    var histE = histDate(p, prm);

    if (histE !== '') {   // 历史日期批量评价（打卡模式）：不要求当日已登记；无 records 行时补建
        for (var ei = 0; ei < ids2.length; ei++) {
            var sidE0 = ids2[ei];
            if (!(await stuRow(sidE0, classIds))) continue;
            var rowE0 = await recUpd(pid, sidE0, {});
            if (remove) clrEval(rowE0);
            else { rowE0.eval_value = value; rowE0.eval_at = AL.nowStr(); rowE0.eval_cleared_at = ''; }
            await DB.update('records', rowE0);
            var drE0 = await dayRow(pid, sidE0, histE);   // 按天评价仅该日已打卡者生效
            if (drE0) {
                if (remove) { drE0.eval_value = ''; drE0.eval_at = ''; }
                else { drE0.eval_value = value; drE0.eval_at = AL.nowStr(); }
                await DB.update('record_days', drE0);
            }
        }
        return ok({ registered_count: await regCnt(pid), evaluated_count: await evCnt(pid), total: await totalIn(classIds) });
    }

    if (isDailyE) {   // 打卡模式（今天）：评价按天写入 record_days（仅当日已打卡者），records 同步
        if (!remove && !(await evalOk(p.eval_mode, value))) return fail('评价内容不符合当前评价模式要求');
        var todayE = AL.dateStr();
        for (var ei2 = 0; ei2 < ids2.length; ei2++) {
            var sidE1 = ids2[ei2];
            if (!(await stuRow(sidE1, classIds))) continue;
            var drE1 = await dayRow(pid, sidE1, todayE);
            if (!drE1) continue;
            if (remove) { drE1.eval_value = ''; drE1.eval_at = ''; }
            else { drE1.eval_value = value; drE1.eval_at = AL.nowStr(); }
            await DB.update('record_days', drE1);
            var recE1 = await recRow(pid, sidE1);   // records 汇总同步（自动锁定者排除）
            if (recE1 && !(lock === 'auto' && locked(p, parseInt(recE1.registered, 10), recE1.registered_at))) {
                if (remove) clrEval(recE1);
                else { recE1.eval_value = value; recE1.eval_at = AL.nowStr(); recE1.eval_cleared_at = ''; }
                await DB.update('records', recE1);
            }
        }
        return ok({ registered_count: await regCnt(pid), evaluated_count: await evCnt(pid), total: await totalIn(classIds) });
    }

    if (isRounded(p)) {   // 次项/题次模式：按当前轮次批量评价（仅该次已登记者；auto 锁定跳过已锁定）
        var crE = await curRound(pid, prm);
        var roundE = crE.round;
        if (isQuizE) {
            value = value.toUpperCase();
            if (!remove && ['A', 'B', 'C', 'D'].indexOf(value) < 0) return fail('答题/举牌模式仅支持 A/B/C/D');
        }
        for (var ei3 = 0; ei3 < ids2.length; ei3++) {
            var sidE2 = ids2[ei3];
            if (!(await stuRow(sidE2, classIds))) continue;
            var rrE0 = await rrRow(pid, sidE2, roundE);
            if (!rrE0 || !rrE0.registered_at) continue;
            if (lock === 'auto' && locked(p, 1, rrE0.registered_at)) continue;
            if (remove) clrEval(rrE0);
            else { rrE0.eval_value = value; rrE0.eval_at = AL.nowStr(); rrE0.eval_cleared_at = ''; }
            await DB.update('record_rounds', rrE0);
        }
        var rcE = await roundCnts(pid, roundE);
        return ok({ round: roundE, registered_count: rcE[0], evaluated_count: rcE[1], total: await totalIn(classIds) });
    }

    // 普通模式：仅对已登记的所选学生设置评价（auto 锁定时跳过已锁定学生）
    for (var ei4 = 0; ei4 < ids2.length; ei4++) {
        var sidE3 = ids2[ei4];
        if (!(await stuRow(sidE3, classIds))) continue;
        var recE2 = await recRow(pid, sidE3);
        if (!recE2 || parseInt(recE2.registered, 10) !== 1) continue;
        if (lock === 'auto' && locked(p, 1, recE2.registered_at)) continue;
        if (remove) clrEval(recE2);
        else { recE2.eval_value = value; recE2.eval_at = AL.nowStr(); recE2.eval_cleared_at = ''; }
        await DB.update('records', recE2);
    }
    return ok({ registered_count: await regCnt(pid), evaluated_count: await evCnt(pid), total: await totalIn(classIds) });
};

/** evaluate：评价 / 删除评价（原 api.php L1496-1769） */
H.evaluate = async function (prm) {
    var c = await ctx(prm);
    if (c.err) return c.err;
    var pid = c.pid, p = c.p, classIds = c.classIds, lock = c.lock;
    var sidV = I(prm.student_id);
    var valV = S(prm.value).trim();
    var remV = I(prm.remove);
    if (!(await stuRow(sidV, classIds))) return fail('学生不存在');
    var histV = histDate(p, prm);
    var isDailyV = S(p.mode || 'count') === 'daily';
    var roundedV = isRounded(p);

    if (histV !== '') {   // 历史日期评价（打卡）：record_days 按天 + records 汇总留痕
        var dayV = await dayRow(pid, sidV, histV);
        var autoV = false;
        if (!dayV) {
            if (remV) return fail('该生当日未打卡，无评价可删除');
            autoV = true;   // 长按未打卡学生评价 = 补登+评价一步完成
        }
        if (!remV && !(await evalOk(p.eval_mode, valV))) return fail('评价内容不符合当前评价模式要求');
        if (autoV) await dayInsert(pid, sidV, histV);   // 历史编辑只改 record_days，不回写 records
        var recV0 = await recUpd(pid, sidV, {});
        if (remV) clrEval(recV0);
        else { recV0.eval_value = valV; recV0.eval_at = AL.nowStr(); recV0.eval_cleared_at = ''; }
        await DB.update('records', recV0);
        var dayV2 = await dayRow(pid, sidV, histV);
        if (remV) { dayV2.eval_value = ''; dayV2.eval_at = ''; }
        else { dayV2.eval_value = valV; dayV2.eval_at = AL.nowStr(); }
        await DB.update('record_days', dayV2);
        return ok({ eval_value: remV ? '' : valV, registered: 1,
            regts: autoV ? nowSec() : tsOf(dayV2.created_at), total: await totalIn(classIds),
            registered_count: await regCnt(pid), evaluated_count: await evCnt(pid) });
    }

    if (isDailyV) {   // 今天打卡评价：未打卡长按评价=打卡+评价一步完成
        var todayV = AL.dateStr();
        var dayV3 = await dayRow(pid, sidV, todayV);
        var autoV3 = false;
        if (!dayV3) {
            if (remV) return fail('该生今天尚未打卡，无评价可删除');
            autoV3 = true;
        } else if (lock !== 'none' && locked(p, 1, dayV3.created_at)) {   // 按当日打卡时间校验自动锁定
            return fail('该学生登记已超过锁定时间，点评已锁定（可用「锁定」开关临时解锁）');
        }
        if (!remV && !(await evalOk(p.eval_mode, valV))) return fail('评价内容不符合当前评价模式要求');
        if (autoV3) {   // 自动完成今日打卡，records 同步登记状态（与页面点击打卡同构）
            await dayInsert(pid, sidV, todayV);
            await recUpd(pid, sidV, { registered: 1, registered_at: AL.nowStr(), registered_by: 'page' });
        }
        var dayV4 = await dayRow(pid, sidV, todayV);
        if (remV) { dayV4.eval_value = ''; dayV4.eval_at = ''; }
        else { dayV4.eval_value = valV; dayV4.eval_at = AL.nowStr(); }
        await DB.update('record_days', dayV4);
        var recV1 = await recUpd(pid, sidV, {});
        if (remV) clrEval(recV1);
        else { recV1.eval_value = valV; recV1.eval_at = AL.nowStr(); recV1.eval_cleared_at = ''; }
        await DB.update('records', recV1);
        return ok({ eval_value: remV ? '' : valV, registered: 1,
            regts: autoV3 ? nowSec() : tsOf(dayV4.created_at), total: await totalIn(classIds),
            registered_count: await regCnt(pid), evaluated_count: await evCnt(pid) });
    }

    if (roundedV) {   // 次项/题次：须该次已登记；答题模式仅 A-D
        var crV = await curRound(pid, prm);
        var roundV = crV.round;
        var rrV = await rrRow(pid, sidV, roundV);
        var autoV4 = false;
        if (!rrV || !rrV.registered_at) {
            if (remV) return fail('该生本次尚未登记，无评价可删除');
            autoV4 = true;
        } else if (lock !== 'none' && locked(p, 1, rrV.registered_at)) {
            return fail('该生本次登记已超过锁定时间，点评已锁定（可用「锁定」开关临时解锁）');
        }
        if (['quiz', 'raise'].indexOf(S(p.mode)) >= 0) {
            valV = valV.toUpperCase();
            if (!remV && ['A', 'B', 'C', 'D'].indexOf(valV) < 0) return fail('答题/举牌模式仅支持 A/B/C/D');
        } else if (!remV && !(await evalOk(p.eval_mode, valV))) {
            return fail('评价内容不符合当前评价模式要求');
        }
        if (autoV4) await rrInsert(pid, sidV, roundV, 'page');   // 长按未登记学生评价=登记+评价一步完成
        var rrV2 = await rrRow(pid, sidV, roundV);
        if (remV) clrEval(rrV2);
        else { rrV2.eval_value = valV; rrV2.eval_at = AL.nowStr(); rrV2.eval_cleared_at = ''; }
        await DB.update('record_rounds', rrV2);
        var rcV = await roundCnts(pid, roundV);
        return ok({ round: roundV, eval_value: remV ? '' : valV, registered: 1,
            regts: autoV4 ? nowSec() : tsOf(rrV2.registered_at), total: await totalIn(classIds),
            registered_count: rcV[0], evaluated_count: rcV[1] });
    }

    // 普通模式
    var recV2 = await recRow(pid, sidV);
    var autoV5 = false, regtsV = 0;
    if (!recV2 || parseInt(recV2.registered, 10) !== 1) {
        if (remV) return fail('该学生尚未登记，无评价可删除');
        autoV5 = true;
    } else {
        if (lock !== 'none' && locked(p, parseInt(recV2.registered, 10), recV2.registered_at)) {
            return fail('该学生登记已超过锁定时间，点评已锁定（可用「锁定」开关临时解锁）');
        }
        regtsV = tsOf(recV2.registered_at);
    }
    if (!remV && !(await evalOk(p.eval_mode, valV))) return fail('评价内容不符合当前评价模式要求');
    if (autoV5) {   // 长按未登记学生选择评价：自动完成登记（登记+评价一步完成）
        await recUpd(pid, sidV, { registered: 1, registered_at: AL.nowStr(), registered_by: 'page' });
        regtsV = nowSec();
    }
    var recV3 = await recRow(pid, sidV);
    if (remV) clrEval(recV3);
    else { recV3.eval_value = valV; recV3.eval_at = AL.nowStr(); recV3.eval_cleared_at = ''; }
    await DB.update('records', recV3);
    return ok({ eval_value: remV ? '' : valV, registered: 1, regts: regtsV,
        total: await totalIn(classIds), registered_count: await regCnt(pid), evaluated_count: await evCnt(pid) });
};

/** scan_register：扫码登记（原 api.php L1444-1454） */
H.scan_register = async function (prm) {
    var c = await ctx(prm);
    if (c.err) return c.err;
    var res = await scanOne(c.p, c.pid, c.classIds, prm.code, prm.opt, true, prm);
    if (res._err) return fail(res._err);
    return res;
};

/** scan_register_multi：多码批量登记（原 api.php L1457-1493） */
H.scan_register_multi = async function (prm) {
    var c = await ctx(prm);
    if (c.err) return c.err;
    var arr = null;
    try { arr = JSON.parse(S(prm.codes).trim()); } catch (e) { arr = null; }
    if (!Array.isArray(arr) || !arr.length) return fail('codes 参数格式错误');
    if (arr.length > 60) return fail('单次批量登记最多 60 个码');
    var results = [];
    for (var i = 0; i < arr.length; i++) {
        var item = arr[i];
        var code = S((item && typeof item === 'object') ? item.code : item).trim();
        var opt = S((item && typeof item === 'object') ? item.opt : '').toUpperCase().trim();
        var r = await scanOne(c.p, c.pid, c.classIds, code, opt, false, prm);
        results.push(r._err ? { success: false, message: r._err } : r);
    }
    // 全班统计：循环外只算一次
    var payload = { success: true, results: results };
    if (isRounded(c.p)) {
        var crM = await curRound(c.pid, prm);
        var rcM = await roundCnts(c.pid, crM.round);
        payload.round = crM.round;
        payload.registered_count = rcM[0];
        payload.evaluated_count = rcM[1];
        if (['quiz', 'raise'].indexOf(S(c.p.mode)) >= 0) payload.quiz = await quizPayload(c.pid, crM.round, c.classIds);
    } else {
        payload.registered_count = await regCnt(c.pid);
    }
    payload.total = await totalIn(c.classIds);
    return payload;
};

/** quiz_answer：答题模式一键作答（原 api.php L708-762） */
H.quiz_answer = async function (prm) {
    var c = await ctx(prm);
    if (c.err) return c.err;
    var pid = c.pid, p = c.p, classIds = c.classIds, lock = c.lock;
    if (['quiz', 'raise'].indexOf(S(p.mode)) < 0) return fail('项目不存在或非答题/举牌模式');
    var val2 = S(prm.value).toUpperCase().trim();
    if (['A', 'B', 'C', 'D'].indexOf(val2) < 0) return fail('答题/举牌模式仅支持 A/B/C/D');
    var sid2 = I(prm.student_id);
    if (!(await stuRow(sid2, classIds))) return fail('学生不存在');
    var cr2 = await curRound(pid, prm);
    var rnd2 = cr2.round;
    var rr2 = await rrRow(pid, sid2, rnd2);
    // 自动锁定：已登记且超时 → 不能改答案；新登记不受影响
    if (rr2 && rr2.registered_at && lock !== 'none' && locked(p, 1, rr2.registered_at)) {
        return fail('该生本次登记已超过锁定时间，答案已锁定（可用「锁定」开关临时解锁）');
    }
    if (!rr2 || !rr2.registered_at) await rrInsert(pid, sid2, rnd2, 'page');
    rr2 = await rrRow(pid, sid2, rnd2);
    rr2.eval_value = val2; rr2.eval_at = AL.nowStr(); rr2.eval_cleared_at = '';
    await DB.update('record_rounds', rr2);
    var rc2 = await roundCnts(pid, rnd2);
    return ok({ round: rnd2, eval_value: val2, regts: rr2.registered_at ? tsOf(rr2.registered_at) : 0,
        total: await totalIn(classIds), registered_count: rc2[0], evaluated_count: rc2[1] });
};

/** quiz_stats：答题模式统计（原 api.php L556-562） */
H.quiz_stats = async function (prm) {
    var c = await ctx(prm);
    if (c.err) return c.err;
    if (['quiz', 'raise'].indexOf(S(c.p.mode)) < 0) return fail('项目不存在或非答题/举牌模式');
    var cr3 = await curRound(c.pid, prm);
    var pay = await quizPayload(c.pid, cr3.round, c.classIds);
    pay.success = true;
    pay.rounds = cr3.rounds;
    return pay;
};

/** quiz_compare：答题模式多次对比（原 api.php L765-816） */
H.quiz_compare = async function (prm) {
    var c = await ctx(prm);
    if (c.err) return c.err;
    var pid = c.pid, p = c.p, classIds = c.classIds;
    if (['quiz', 'raise'].indexOf(S(p.mode)) < 0) return fail('项目不存在或非答题/举牌模式');
    var roundsL4 = await roundsList(pid);
    var data = [];
    for (var di = 0; di < roundsL4.length; di++) {
        var dp = await quizPayload(pid, roundsL4[di], classIds);
        dp.round = roundsL4[di];
        data.push(dp);
    }
    // 每生历次选项（未答不写入 opts）
    var stu = {};
    var rrs4 = await DB.by('record_rounds', 'project_id', pid);
    rrs4.sort(function (a, b) { return (I(a.student_id) - I(b.student_id)) || (I(a.round_no) - I(b.round_no)); });
    for (var si = 0; si < rrs4.length; si++) {
        var r4 = rrs4[si];
        if (roundsL4.indexOf(I(r4.round_no)) < 0 || !r4.registered_at) continue;
        var sid4 = I(r4.student_id);
        var s4 = await stuRow(sid4, classIds);
        if (!s4) continue;
        if (!stu[sid4]) {
            stu[sid4] = { name: S(s4.name), seat: S(s4.seat_no), cls: await classNameOf(s4.class_id),
                          class_id: I(s4.class_id), group_id: I(s4.group_id), opts: {} };
        }
        var op4 = S(r4.eval_value).toUpperCase().trim();
        if (op4 !== '') stu[sid4].opts[I(r4.round_no)] = op4;
    }
    // 分组信息（分组统计/雷达用）
    var gAll = [], gSeen = {};
    for (var gi = 0; gi < classIds.length; gi++) {
        var gs4 = await DB.by('stu_groups', 'class_id', classIds[gi]);
        gs4.sort(function (a, b) { return (I(a.sort) - I(b.sort)) || (I(a.id) - I(b.id)); });
        var clsName4 = await classNameOf(classIds[gi]);
        gs4.forEach(function (gp) {
            if (gSeen[gp.id]) return;
            gSeen[gp.id] = 1;
            gAll.push({ id: I(gp.id), name: S(gp.name), class_id: classIds[gi], cls: clsName4 });
        });
    }
    return ok({ rounds: data, groups: gAll, students: stu, titles: await roundsTitles(pid) });
};

/** view_state：大屏页面完整状态（原 api.php L230-334） */
H.view_state = async function (prm) {
    var pid = I(prm.project_id);
    var p = await DB.get('projects', pid);
    if (!p || p.deleted_at) return fail('项目不存在或无权限');
    var classIds = classIdsOf(p);
    if (!classIds.length) return fail('项目不存在或无权限');
    var mode = S(p.mode || 'count');
    var rounded = isRounded(p);
    var out = { success: true, mode: mode, rounded: rounded, rounds: [], round: 0 };
    if (rounded) {   // 轮次：与页面渲染同口径（round 无效回落最新一轮）
        var roundsS = await roundsList(pid);
        out.rounds = roundsS;
        var roundS = I(prm.round);
        if (roundsS.indexOf(roundS) < 0) roundS = roundsS[roundsS.length - 1] || 0;
        out.round = roundS;
        out.titles = await roundsTitles(pid);
    }
    // 当前班级：cls_id 须在项目覆盖范围内（多班级项目）
    var cls = I(prm.cls_id);
    if (classIds.indexOf(cls) < 0) cls = classIds[0] || 0;
    var sRows = await DB.by('students', 'class_id', cls);
    var okIds = {}, allIds = {};
    sRows.forEach(function (s) {
        allIds[I(s.id)] = 1;
        if (!s.disabled) okIds[I(s.id)] = 1;
    });
    var first = 0;

    if (rounded) {
        var rround = out.round;
        var abcd = S(p.eval_mode) === 'abcd';
        var nb = {}, correct = [];
        var rrsS = await DB.by('record_rounds', 'project_id', pid);
        rrsS.sort(function (a, b) { return I(a.id) - I(b.id); });
        if (abcd) {   // 相邻题次选项（卡片角点无刷新同步用）+ 本题次正确答案
            rrsS.forEach(function (rr) {
                if (!rr.registered_at) return;
                var sidN = I(rr.student_id);
                if (!okIds[sidN]) return;
                var optN = S(rr.eval_value).toUpperCase().trim();
                if (['A', 'B', 'C', 'D'].indexOf(optN) < 0) return;
                nb[I(rr.round_no)] = nb[I(rr.round_no)] || {};
                nb[I(rr.round_no)][sidN] = optN;
            });
            var prowS = null;
            var pAllS = await DB.by('project_rounds', 'project_id', pid);
            for (var pi = 0; pi < pAllS.length; pi++) if (I(pAllS[pi].round_no) === rround) { prowS = pAllS[pi]; break; }
            S(prowS && prowS.correct_opts).toUpperCase().split(',').forEach(function (o) {
                o = o.trim();
                if (['A', 'B', 'C', 'D'].indexOf(o) >= 0 && correct.indexOf(o) < 0) correct.push(o);
            });
        }
        var studentsS = [];
        rrsS.forEach(function (rr2) {
            if (I(rr2.round_no) !== rround) return;
            var sid2 = I(rr2.student_id);
            if (!okIds[sid2]) return;
            var stu2 = { id: sid2, reg: rr2.registered_at ? 1 : 0, regts: rr2.registered_at ? tsOf(rr2.registered_at) : 0, eval: S(rr2.eval_value) };
            if (abcd) {
                stu2.prev = (nb[rround - 1] || {})[sid2] || '';
                stu2.next = (nb[rround + 1] || {})[sid2] || '';
            }
            studentsS.push(stu2);
        });
        studentsS.sort(function (a, b) { return a.id - b.id; });
        out.students = studentsS;
        if (abcd) out.correct = correct;
        var rcS = await roundCnts(pid, rround);
        out.stats = { total: await totalIn(classIds), reg: rcS[0], ev: rcS[1] };
    } else if (mode === 'daily') {
        // 打卡模式：今天/历史日期打卡明细 + 日历每日人数（与页面渲染同口径）
        var date = AL.dateStr();
        if (/^\d{4}-\d{2}-\d{2}$/.test(S(prm.date)) && S(prm.date) !== AL.dateStr()) date = S(prm.date);
        var checks = [], cal = {};
        var dRowsS = await DB.by('record_days', 'project_id', pid);
        dRowsS.sort(function (a, b) { return I(a.id) - I(b.id); });
        dRowsS.forEach(function (rd) {
            var sidD = I(rd.student_id);
            if (rd.reg_date === date && okIds[sidD]) {
                var t = tsOf(rd.created_at);
                checks.push({ id: sidD, regts: t, eval: S(rd.eval_value) });
                if (t && (first === 0 || t < first)) first = t;
            }
            if (allIds[sidD]) cal[rd.reg_date] = (cal[rd.reg_date] || 0) + 1;
        });
        out.checks = checks;
        out.view_date = date;
        out.cal = cal;
    } else {
        var recsS = await DB.by('records', 'project_id', pid);
        var studentsP = [];
        recsS.forEach(function (r) {
            var sidP = I(r.student_id);
            if (!okIds[sidP]) return;
            var t = r.registered_at ? tsOf(r.registered_at) : 0;
            studentsP.push({ id: sidP, reg: parseInt(r.registered, 10) === 1 ? 1 : 0, regts: t, eval: S(r.eval_value) });
            if (t && (first === 0 || t < first)) first = t;
        });
        studentsP.sort(function (a, b) { return a.id - b.id; });
        out.students = studentsP;
        out.stats = { total: await totalIn(classIds), reg: await regCnt(pid), ev: await evCnt(pid) };
    }
    if (!rounded && mode !== 'daily') out.first_ts = first;   // 补登记判定基准（本班首位登记时间）
    else if (mode === 'daily') out.first_ts = first;
    out.version = await versionFingerprint(p, classIds);
    return out;
};

/** view_stats：打卡/普通登记统计弹层（原 api.php L566-682，移植旧版 pvFetch 同名分支） */
H.view_stats = async function (prm) {
    var c = await ctx(prm);
    if (c.err) return c.err;
    var pid = c.pid, p = c.p, classIds = c.classIds;
    var pm = S(p.mode);
    if (['quiz', 'raise', 'omr'].indexOf(pm) >= 0) return fail('答题/举牌/答题卡模式请在对应统计弹层查看');
    var cls = I(prm.cls_id);
    if (cls <= 0 || classIds.indexOf(cls) < 0) return fail('班级不在项目范围内');
    var daily = (pm === 'daily');
    // 本班在册学生（已禁用不计，与登记页口径一致）
    var stuRows = await DB.by('students', 'class_id', cls);
    stuRows = stuRows.filter(function (s) { return !s.disabled; });
    stuRows.sort(function (a, b) { return (I(a.seat_no) - I(b.seat_no)) || (I(a.id) - I(b.id)); });
    var students = {}, stuOrder = [];
    stuRows.forEach(function (s) {
        students[I(s.id)] = { id: I(s.id), name: S(s.name), seat: I(s.seat_no), gid: I(s.group_id), on: false, ev: false, val: '' };
        stuOrder.push(I(s.id));
    });
    var gRows = await DB.by('stu_groups', 'class_id', cls);
    gRows.sort(function (a, b) { return (I(a.sort) - I(b.sort)) || (I(a.id) - I(b.id)); });
    var groups = gRows.map(function (gp) { return { id: I(gp.id), name: S(gp.name) }; });
    var onWord = daily ? '打卡' : '登记', dateLabel = '', evalDist = {}, rounds = [], matrix = [];

    if (daily) {
        // 打卡：date=统计截止日（默认今天）；from=起始日（填了=统计 from~date 累计）
        var date = S(prm.date || AL.dateStr());
        if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) date = AL.dateStr();
        var from = S(prm.from);
        if (!/^\d{4}-\d{2}-\d{2}$/.test(from) || from > date) from = '';
        var dRows = await DB.by('record_days', 'project_id', pid);
        dRows.sort(function (a, b) { return (a.reg_date < b.reg_date ? -1 : a.reg_date > b.reg_date ? 1 : (I(a.id) - I(b.id))); });
        dRows.forEach(function (rd) {
            var sid = I(rd.student_id);
            if (!students[sid]) return;
            if (from !== '') { if (rd.reg_date < from || rd.reg_date > date) return; }
            else if (rd.reg_date !== date) return;
            students[sid].on = true;
            var evv = S(rd.eval_value).trim();
            if (evv !== '') { students[sid].ev = true; students[sid].val = evv; evalDist[evv] = (evalDist[evv] || 0) + 1; }
        });
        dateLabel = (from !== '' ? from + ' ~ ' : '') + date;
    } else {
        // 普通：round=统计轮次（0=不限定，仅返回轮次清单与矩阵）
        var round = I(prm.round);
        var rlist = await roundsList(pid);
        var titles = await roundsTitles(pid);
        rlist.forEach(function (rn) { rounds.push({ no: rn, title: S(titles[rn] || '') }); });
        if (round > 0 && rlist.indexOf(round) >= 0) {
            var rrAll = await DB.by('record_rounds', 'project_id', pid);
            for (var ri = 0; ri < rrAll.length; ri++) {
                var r3 = rrAll[ri];
                if (I(r3.round_no) !== round || !r3.registered_at) continue;
                var sid3 = I(r3.student_id);
                if (!students[sid3]) continue;
                students[sid3].on = true;
                var evv3 = S(r3.eval_value).trim();
                if (evv3 !== '') { students[sid3].ev = true; students[sid3].val = evv3; evalDist[evv3] = (evalDist[evv3] || 0) + 1; }
            }
        }
        // 轮次×学生登记矩阵（个人页签「历次变化」；评价内容一并带出）
        var rrAll2 = await DB.by('record_rounds', 'project_id', pid);
        rrAll2.sort(function (a, b) { return (I(a.round_no) - I(b.round_no)) || (I(a.student_id) - I(b.student_id)); });
        for (var mi = 0; mi < rrAll2.length; mi++) {
            var m = rrAll2[mi];
            var sidm = I(m.student_id);
            if (!students[sidm]) continue;
            matrix.push([I(m.round_no), sidm, m.registered_at ? 1 : 0, S(m.eval_value)]);
        }
    }
    // 评价分布排序：预设等级优先（与页面同口径）；自由输入取 Top5+其他
    var mInfo = await evalModeInfo(p.eval_mode);
    var eo = mInfo ? mInfo.options : null;
    var ed = [];
    if (eo && typeof eo === 'object' && Object.keys(eo).length) {
        Object.keys(eo).forEach(function (k) { if (evalDist[k]) { ed.push({ k: k, v: evalDist[k] }); delete evalDist[k]; } });
        Object.keys(evalDist).forEach(function (k) { ed.push({ k: k, v: evalDist[k] }); });
    } else {
        var arr = Object.keys(evalDist).map(function (k) { return [k, evalDist[k]]; }).sort(function (a, b) { return b[1] - a[1]; });
        arr.slice(0, 5).forEach(function (it) { ed.push({ k: it[0], v: it[1] }); });
        if (arr.length > 5) {
            var rest = 0;
            arr.slice(5).forEach(function (it) { rest += it[1]; });
            ed.push({ k: '其他', v: rest });
        }
    }
    var onCnt = 0, evCnt2 = 0;
    stuOrder.forEach(function (sid) { if (students[sid].on) onCnt++; if (students[sid].ev) evCnt2++; });
    return ok({ mode: daily ? 'daily' : 'plain', onWord: onWord, dateLabel: dateLabel,
        roundLabel: '项次', evalOn: (ed.length > 0) || !!(eo && typeof eo === 'object' && Object.keys(eo).length),
        total: stuOrder.length, on: onCnt, ev: evCnt2, evalDist: ed,
        students: stuOrder.map(function (sid) { return students[sid]; }), groups: groups, rounds: rounds, matrix: matrix });
};

/** view_version：页面数据版本号（原 api.php L217-227，指纹见 versionFingerprint） */
H.view_version = async function (prm) {
    var pid = I(prm.project_id);
    var p = await DB.get('projects', pid);
    if (!p || p.deleted_at) return fail('项目不存在或无权限');
    var classIds = classIdsOf(p);
    if (!classIds.length) return fail('项目不存在或无权限');
    return ok({ version: await versionFingerprint(p, classIds) });
};

AL.reg(H);
})(typeof self !== 'undefined' ? self : this);
