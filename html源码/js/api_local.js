/**
 * 潜记单机版 - api.php 本地实现（IndexedDB）
 * 由 sw.js（Service Worker，http 服务场景）与 sw_boot.js（fetch 补丁，file:// 场景）共同调用。
 * 接口语义对照源版 qj/api.php（4008 行），数据读写依赖 js/db.js 的全局 DB。
 *
 * 结构约定：
 *   ApiLocal.handle(request)            —— SW 入口（Request 对象）
 *   ApiLocal.handleRequest(url, init)   —— fetch 补丁入口
 *   ApiLocal.dispatch(type, prm, files) —— 接口分发（prm=普通参数对象, files=FormData 文件项）
 *   ApiLocal.json(obj) / ApiLocal.file(blob, name)
 *
 * 各接口实现分区：[组A] 登记评价 / [组B] OMR / [组C] 积分 / [组D] 喊话 / [组E] 公共
 */
(function (global) {
'use strict';

// ===== 基础工具 =====
function pad2(n) { return n < 10 ? '0' + n : '' + n; }
function nowStr(d) {
    d = d || new Date();
    return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()) +
        ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
}
function dateStr(d) {
    d = d || new Date();
    return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
}
function I(v) { var n = parseInt(v, 10); return isNaN(n) ? 0 : n; }
function S(v) { return v == null ? '' : String(v); }
function ok(extra) { return Object.assign({ success: true }, extra || {}); }
function fail(msg, extra) { return Object.assign({ success: false, message: msg }, extra || {}); }

// ===== 请求解析 =====
/** FormData 迭代入参：普通字段并入 prm，文件进 files（同名多文件如 imgs[] 归数组） */
function absorbFormData(fd, prm, files) {
    var it = fd.entries ? fd.entries() : [];
    var e;
    while (!(e = it.next()).done) {
        var k = e.value[0], v = e.value[1];
        if ((typeof File !== 'undefined' && v instanceof File) || (typeof Blob !== 'undefined' && v instanceof Blob)) {
            var item = { name: v.name || ('blob_' + k), blob: v };
            if (files[k] === undefined) files[k] = item;
            else if (Array.isArray(files[k])) files[k].push(item);
            else files[k] = [files[k], item];
        } else {
            prm[k] = S(v);
        }
    }
}
/** 解析 URL + init（fetch 补丁）或 Request（SW）→ {type, prm, files} */
function parseInput(input, init) {
    var url = '', method = 'GET', body = null, contentType = '';
    if (typeof Request !== 'undefined' && input instanceof Request) {
        var u = new URL(input.url);
        url = u.search.substring(1);
        method = input.method.toUpperCase();
        if (method === 'POST') body = input;
    } else {
        var raw = S(input);
        url = raw.indexOf('?') >= 0 ? raw.substring(raw.indexOf('?') + 1) : (init && init._query) || raw;
        method = ((init && init.method) || 'GET').toUpperCase();
        if (method === 'POST' && init) body = init.body;
    }
    var prm = {};
    url.split('&').forEach(function (kv) {
        if (!kv) return;
        var p = kv.split('=');
        prm[decodeURIComponent(p[0])] = decodeURIComponent((p[1] || '').replace(/\+/g, ' '));
    });
    var files = {};
    if (body) {
        if (typeof FormData !== 'undefined' && body instanceof FormData) {
            // FormData：普通字段并入 prm，文件进 files
            absorbFormData(body, prm, files);
        } else if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) {
            body.forEach(function (v, k) { prm[k] = S(v); });
        } else if (typeof body === 'string') {
            // x-www-form-urlencoded
            var ct = (init && init.headers && (init.headers['Content-Type'] || init.headers['content-type'])) || '';
            if (ct.indexOf('json') >= 0) { try { prm = Object.assign(prm, JSON.parse(body)); } catch (e2) {} }
            else body.split('&').forEach(function (kv) {
                if (!kv) return;
                var p2 = kv.split('=');
                prm[decodeURIComponent(p2[0])] = decodeURIComponent((p2[1] || '').replace(/\+/g, ' '));
            });
        }
    }
    return { prm: prm, files: files, method: method };
}

// ===== 文件存储与虚拟文件路由 =====
var MIME_MAP = {
    png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', webp: 'image/webp',
    webm: 'audio/webm', ogg: 'audio/ogg', m4a: 'audio/mp4', mp3: 'audio/mpeg', wav: 'audio/wav',
    csv: 'text/csv', json: 'application/json', zip: 'application/zip', pdf: 'application/pdf',
    xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    doc: 'application/msword'
};
function extMime(name) {
    var m = /\.([a-z0-9]+)$/i.exec(S(name));
    return m ? (MIME_MAP[m[1].toLowerCase()] || 'application/octet-stream') : 'application/octet-stream';
}
/** 上传文件：存 files store，返回可访问的本地路径（localfile.php?name=xxx） */
async function fileStore(fileObj, prefix) {
    if (!fileObj) return '';
    var ext = (/\.([a-z0-9]+)$/i.exec(S(fileObj.name)) || [, 'bin'])[1].toLowerCase();
    var fname = (prefix || 'f') + '_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8) + '.' + ext;
    await DB.fileSave(fname, fileObj.blob);
    return 'localfile.php?name=' + encodeURIComponent(fname);
}
/** SW 拦截 localfile.php?name=xxx → 返回 Blob */
async function handleLocalFile(prm, download) {
    var name = S(prm.name);
    var blob = await DB.fileGet(name);
    if (!blob) return { status: 404, body: 'file not found' };
    var headers = { 'Content-Type': extMime(name), 'Cache-Control': 'no-store' };
    if (download) headers['Content-Disposition'] = 'attachment; filename="' + String(name).replace(/[^\w.\-]/g, '_') + '"';
    return { status: 200, body: blob, headers: headers };
}

// ===== 喊话广播（同机同源多标签实时通知） =====
function annChannel(classId) {
    try { return new BroadcastChannel('qj_announce_' + I(classId)); } catch (e) { return null; }
}
function annBroadcast(classId, kind, payload) {
    var ch = annChannel(classId);
    if (ch) { try { ch.postMessage({ kind: kind, payload: payload }); } catch (e2) {} try { ch.close(); } catch (e3) {} }
}

// ================================================================
// [组D] 喊话（对照 api.php announce_* 与 announce_client.php 语义）
// ================================================================
/** 学生标签（纯文字含标签时拒绝直接下发，防漏替换字面标签） */
var ANN_TAG_RE = /\{姓名\}|\{座号\}|\{分组\}|\{编号\}|\{座号\+姓名\}|\{座号\+未登记上一名\}|\{座号\+未登记下一名\}|\{未登记上一名\}|\{未登记下一名\}|\{name\}/;
function clampNum(v, lo, hi, def) {
    var n = parseFloat(v);
    if (isNaN(n)) n = def;
    return Math.max(lo, Math.min(hi, n));
}
/** 下发指令公共参数（对照 api.php announce_send 的取值/钳制/默认） */
function annCommonParams(prm) {
    return {
        font_size: Math.round(clampNum(prm.font_size, 16, 200, 64)),
        marquee: I(prm.marquee) === 1 ? 1 : 0,
        sub_big: prm.sub_big !== undefined ? (I(prm.sub_big) === 1 ? 1 : 0) : 1,
        force_show: I(prm.force_show) === 1 ? 1 : 0,
        auto_min: Math.round(clampNum(prm.auto_min, 0, 3600, 0)),
        cache_min: Math.round(clampNum(prm.cache_min, 0, 1440, 60)),
        need_ack: I(prm.need_ack) === 1 ? 1 : 0,
        speed: clampNum(prm.speed, 0.5, 2, 1),
        pitch: clampNum(prm.pitch, 0.5, 2, 1),
        times: Math.round(clampNum(prm.times, 1, 5, 1))
    };
}
/** 落库一条下发指令（announce_msgs；status=0，poll 取 5 分钟窗口） */
async function annInsert(cid, p, mtype, content) {
    var now = nowStr();
    var row = await DB.insert('announce_msgs', {
        class_id: cid, sender_id: 0, mtype: mtype, content: content, file: '',
        font_size: p.font_size, marquee: p.marquee, sub_big: p.sub_big, force_show: p.force_show,
        auto_min: p.auto_min, cache_min: p.cache_min, need_ack: p.need_ack,
        speed: p.speed, pitch: p.pitch, times: p.times, voice_name: p.voice_name || '',
        status: 0, created_at: now, ack_status: null, ack_at: ''
    });
    annBroadcast(cid, 'new', { msg_id: row.id });
    return row;
}
/** 在线设备汇总（10 秒内有心跳；{pc,phone,novoice}） */
async function annDevicesSummary(cid) {
    var lim = new Date();
    lim.setSeconds(lim.getSeconds() - 10);
    var limS = nowStr(lim);
    var devs = await DB.by('announce_devices', 'class_id', cid);
    var pc = 0, phone = 0, novoice = 0;
    devs.forEach(function (d) {
        if (!d.last_seen || S(d.last_seen) < limS) return;
        if (S(d.device_type) === 'phone') phone++; else pc++;
        if (I(d.voice_ok) !== 1) novoice++;
    });
    return { pc: pc, phone: phone, novoice: novoice };
}
/** localfile.php?name=xxx → 文件名（历史重发校验用） */
function localFileName(u) {
    var m = /[?&]name=([^&]+)/.exec(S(u));
    return m ? decodeURIComponent(m[1]) : '';
}
/** 音频魔数嗅验 → 扩展名（防 MediaRecorder blob 无扩展名导致 MIME 丢失） */
async function sniffAudioExt(blob) {
    try {
        var buf = new Uint8Array(await blob.slice(0, 16).arrayBuffer());
        function s(a, b) { return String.fromCharCode.apply(null, buf.subarray(a, b)); }
        if (s(0, 4) === '\x1A\x45\xDF\xA3') return 'webm';
        if (s(0, 4) === 'OggS') return 'ogg';
        if (s(4, 8) === 'ftyp') return 'm4a';
        if (s(0, 3) === 'ID3' || (buf.length >= 2 && buf[0] === 0xFF && (buf[1] & 0xE0) === 0xE0)) return 'mp3';
        if (s(0, 4) === 'RIFF' && s(8, 12) === 'WAVE') return 'wav';
    } catch (e) {}
    return '';
}
/** ack_status 归一（null/'' = 0 待反馈） */
function annAckSt(v) { return (v === null || v === undefined || v === '') ? 0 : I(v); }

var AnnHandlers = {
    /** 下发指令（api.php announce_send）：text=字幕（content 空=清除）；voice=texts 逐条（names 旧参兼容） */
    announce_send: async function (prm) {
        var cid = I(prm.class_id);
        if (!cid) return fail('缺少班级');
        var p = annCommonParams(prm);
        var content = S(prm.content).trim();
        if (S(prm.mtype) !== 'voice') {
            await annInsert(cid, p, 'text', content);
            return ok({ message: content === '' ? '已清除字幕' : '字幕已发送到大屏' });
        }
        var list = [];
        var texts = null;
        try { texts = JSON.parse(S(prm.texts) || '[]'); } catch (e) {}
        if (Array.isArray(texts) && texts.length) {
            list = texts.slice(0, 50).map(function (t) { return S(t); }).filter(function (t) { return t.trim() !== ''; });
        } else {
            var names = null;
            try { names = JSON.parse(S(prm.names) || '[]'); } catch (e2) {}
            if (Array.isArray(names) && names.length) {
                names.slice(0, 50).forEach(function (nm) {
                    var t = content.split('{name}').join(S(nm));
                    if (t.trim() !== '') list.push(t);
                });
            }
        }
        if (!list.length) {
            if (ANN_TAG_RE.test(content)) return fail('内容包含学生标签，请先选择学生或移除标签');
            list.push(content);   // 未选学生兜底：仅播报文字（空内容=客户端先响叮咚）
        }
        p.voice_name = S(prm.voice).trim().substring(0, 120);
        for (var i = 0; i < list.length; i++) await annInsert(cid, p, 'voice', list[i]);
        return ok({ message: '已发送 ' + list.length + ' 条语音喊话到大屏' });
    },
    /** 语音录音下发（announce_send_audio）：≤10MB 魔数嗅验，或 reurl 重发历史录音 */
    announce_send_audio: async function (prm, files) {
        var cid = I(prm.class_id);
        if (!cid) return fail('缺少班级');
        var p = annCommonParams(prm);
        p.font_size = 0; p.marquee = 0; p.sub_big = 0; p.speed = 1; p.pitch = 1; p.times = 1;
        var url = '';
        var reurl = S(prm.reurl).trim();
        if (reurl !== '') {
            var nm = localFileName(reurl);
            if (!nm || !(await DB.fileGet(nm))) return fail('历史录音文件已不存在，无法重发');
            url = reurl;
        } else {
            var f = files.audio || files.file;
            if (!f) return fail('请先录制一段语音');
            if (f.blob.size > 10 * 1024 * 1024) return fail('录音文件过大（限 10MB）');
            var ext = await sniffAudioExt(f.blob);
            if (!ext) return fail('不支持的录音格式');
            url = await fileStore({ name: 'voice.' + ext, blob: f.blob }, 'ann_audio');
        }
        await annInsert(cid, p, 'audio', url);
        return ok({ message: '录音已发送到大屏，客户端将自动播放', url: url });
    },
    /** 图片下发（announce_send_img）：imgs[] 多图 ≤9 张 ≤10MB，或 reurls 重发历史；content=URL JSON 列表 */
    announce_send_img: async function (prm, files) {
        var cid = I(prm.class_id);
        if (!cid) return fail('缺少班级');
        var p = annCommonParams(prm);
        p.font_size = 0; p.marquee = 0; p.sub_big = 0; p.speed = 1; p.pitch = 1; p.times = 1;
        var urls = [];
        var reurls = null;
        try { reurls = JSON.parse(S(prm.reurls) || '[]'); } catch (e) {}
        if (Array.isArray(reurls) && reurls.length) {
            for (var i = 0; i < reurls.length && urls.length < 9; i++) {
                var nm = localFileName(S(reurls[i]));
                if (nm && (await DB.fileGet(nm))) urls.push(S(reurls[i]));
            }
            if (!urls.length) return fail('历史图片文件已不存在，无法重发');
        } else {
            var imgs = files['imgs[]'] || files.imgs || files.file;
            if (!imgs) return fail('请选择要发送的图片');
            if (!Array.isArray(imgs)) imgs = [imgs];
            for (var j = 0; j < imgs.length && urls.length < 9; j++) {
                var f = imgs[j];
                if (!f || f.blob.size > 10 * 1024 * 1024) continue;
                if (!/^image\/(jpeg|png|gif|webp)$/i.test(S(f.blob.type))) continue;
                urls.push(await fileStore(f, 'ann_img'));
            }
            if (!urls.length) return fail('没有有效的图片（支持 jpg/png/gif/webp，单张 ≤ 10MB）');
        }
        await annInsert(cid, p, 'img', JSON.stringify(urls));
        return ok({ message: '已发送 ' + urls.length + ' 张图片到大屏', urls: urls });
    },
    /** 客户端轮询（公开，令牌即凭证）：设备心跳 + 5 分钟内待播指令 + 教师在线（15 秒心跳） */
    announce_poll: async function (prm) {
        var cid = await classIdByToken(prm.token || prm.t);
        if (!cid) return fail('班级不存在或令牌已失效，请联系教师重新复制链接');
        var now = nowStr();
        // 设备心跳：did/dtype/vsup（10 秒内视为在线）
        var did = S(prm.did).trim();
        if (/^[0-9a-f]{32}$/.test(did)) {
            var devs = await DB.by('announce_devices', 'class_id', cid);
            var dev = null;
            for (var i = 0; i < devs.length; i++) if (S(devs[i].device_id) === did) { dev = devs[i]; break; }
            var dtype = S(prm.dtype) === 'phone' ? 'phone' : 'pc';
            var vsup = I(prm.vsup) === 1 ? 1 : 0;
            if (dev) { dev.device_type = dtype; dev.voice_ok = vsup; dev.last_seen = now; await DB.update('announce_devices', dev); }
            else await DB.insert('announce_devices', { class_id: cid, device_id: did, device_type: dtype, voice_ok: vsup, last_seen: now });
            var hourAgo = addMinutes(now, -60);   // 顺带清理 1 小时前陈旧心跳
            for (var j = 0; j < devs.length; j++) {
                if (devs[j] !== dev && devs[j].last_seen && S(devs[j].last_seen) < hourAgo) await DB.del('announce_devices', devs[j].id);
            }
        }
        // 待播指令：status=0 且 5 分钟内（防离线重放陈旧队列；多端同收，客户端按 id 去重）
        var msgs = (await DB.by('announce_msgs', 'class_id', cid)).filter(function (m) {
            return (m.status === undefined ? 0 : I(m.status)) === 0 && S(m.created_at) >= addMinutes(now, -5);
        }).sort(function (a, b) { return I(a.id) - I(b.id); }).slice(0, 20).map(function (m) {
            return { id: I(m.id), mtype: S(m.mtype), content: S(m.content), file: S(m.file),
                font_size: I(m.font_size), marquee: I(m.marquee) === 1 ? 1 : 0, sub_big: I(m.sub_big) === 1 ? 1 : 0,
                force_show: I(m.force_show) === 1 ? 1 : 0, auto_min: I(m.auto_min), cache_min: I(m.cache_min),
                need_ack: I(m.need_ack) === 1 ? 1 : 0, speed: parseFloat(m.speed) || 1, pitch: parseFloat(m.pitch) || 1,
                times: I(m.times) || 1, voice_name: S(m.voice_name), sender_name: S(m.sender_name) };
        });
        // 教师在线：教师端 announce_tping 心跳 15 秒内有效
        var cls = await DB.get('classes', cid);
        var seen = S(cls && cls.ann_teacher_seen);
        var teacherOnline = !!seen && (new Date(now.replace(/-/g, '/')) - new Date(seen.replace(/-/g, '/'))) <= 15000;
        return ok({ msgs: msgs, teacher_online: teacherOnline, now: now });
    },
    /** 客户端回执（公开）：need_ack 指令的 1=确认收到 / 2=无法处理（原版 ack_status 字段） */
    announce_ack: async function (prm) {
        var cid = await classIdByToken(prm.token || prm.t);
        if (!cid) return fail('班级不存在或令牌已失效');
        var m = await DB.get('announce_msgs', I(prm.id || prm.msg_id));
        if (!m || I(m.class_id) !== cid || I(m.need_ack) !== 1) return ok();
        m.ack_status = I(prm.st) === 1 ? 1 : 2;
        m.ack_at = nowStr();
        await DB.update('announce_msgs', m);
        annBroadcast(cid, 'ack', { msg_id: m.id });
        return ok();
    },
    /** 教师端状态（announce_status）：设备在线汇总 + 30 分钟反馈统计 + 最新班级反馈 + 最新需反馈结果 */
    announce_status: async function (prm) {
        var cid = I(prm.class_id);
        var msgs = await DB.by('announce_msgs', 'class_id', cid);
        var in30 = addMinutes(nowStr(), -30);
        var ack = { ok: 0, fail: 0, wait: 0 };
        msgs.forEach(function (m) {
            if (I(m.need_ack) !== 1 || S(m.created_at) < in30) return;
            var st = annAckSt(m.ack_status);
            if (st === 0) ack.wait++;
            else if (st === 1) ack.ok++;
            else ack.fail++;
        });
        var byId = msgs.slice().sort(function (a, b) { return I(b.id) - I(a.id); });
        var last_ack = null;
        for (var k = 0; k < byId.length; k++) {
            if (I(byId[k].need_ack) === 1) {
                last_ack = { id: I(byId[k].id), mtype: S(byId[k].mtype), content: S(byId[k].content),
                    st: annAckSt(byId[k].ack_status), at: S(byId[k].ack_at), sent: S(byId[k].created_at) };
                break;
            }
        }
        var revs = await DB.by('announce_rev_msgs', 'class_id', cid);
        revs.sort(function (a, b) { return I(b.id) - I(a.id); });
        var last_rev = null;
        for (var i = 0; i < revs.length; i++) {
            if (S(revs[i].content) !== '') {
                last_rev = { id: I(revs[i].id), mtype: S(revs[i].mtype), content: S(revs[i].content), ts: S(revs[i].created_at) };
                break;
            }
        }
        return ok({ devices: await annDevicesSummary(cid), ack: ack, last_rev: last_rev, last_ack: last_ack });
    },
    /** 历史播报（announce_history）：近 30 条指令（content 非空，含 ack 结果）+ 近 20 条班级反馈 */
    announce_history: async function (prm) {
        var cid = I(prm.class_id);
        var msgs = await DB.by('announce_msgs', 'class_id', cid);
        msgs.sort(function (a, b) { return I(b.id) - I(a.id); });
        var items = [];
        for (var i = 0; i < msgs.length && items.length < 30; i++) {
            var m = msgs[i];
            if (S(m.content) === '') continue;
            items.push({ id: I(m.id), mtype: S(m.mtype), content: S(m.content), ts: S(m.created_at),
                need_ack: I(m.need_ack) === 1 ? 1 : 0, ack_st: annAckSt(m.ack_status), ack_at: S(m.ack_at) });
        }
        var revs = await DB.by('announce_rev_msgs', 'class_id', cid);
        revs.sort(function (a, b) { return I(b.id) - I(a.id); });
        var rl = [];
        for (var j = 0; j < revs.length && rl.length < 20; j++) {
            if (S(revs[j].content) === '') continue;
            rl.push({ id: I(revs[j].id), mtype: S(revs[j].mtype), content: S(revs[j].content), ts: S(revs[j].created_at) });
        }
        return ok({ items: items, revs: rl });
    },
    /** 删除历史（announce_del）：kind=rev 删班级反馈，否则删下发指令（均限本班） */
    announce_del: async function (prm) {
        var cid = I(prm.class_id), id = I(prm.id);
        if (S(prm.kind) === 'rev') {
            var r = await DB.get('announce_rev_msgs', id);
            if (r && I(r.class_id) === cid) await DB.del('announce_rev_msgs', id);
        } else {
            var m = await DB.get('announce_msgs', id);
            if (m && I(m.class_id) === cid) await DB.del('announce_msgs', id);
        }
        return ok({ message: '已删除' });
    },
    /** 客户端接入数据（announce_data）：令牌/链接 + 学生名单 + 分组 + 在线设备汇总（首次自动生成令牌） */
    announce_data: async function (prm) {
        var cid = I(prm.class_id);
        var cls = await DB.get('classes', cid);
        if (!cls || cls.deleted_at) return fail('班级不存在');
        var token = S(cls.announce_token);
        if (!/^[0-9a-f]{32}$/.test(token)) {
            token = '';
            for (var i = 0; i < 32; i++) token += '0123456789abcdef'.charAt(Math.floor(Math.random() * 16));
            await DB.patch('classes', cid, { announce_token: token });
        }
        var stus = await DB.find('students', function (s) { return I(s.class_id) === cid && !s.disabled; });
        stus.sort(function (a, b) { return (parseInt(a.seat_no, 10) || 0) - (parseInt(b.seat_no, 10) || 0) || I(a.id) - I(b.id); });
        var students = stus.map(function (s) {
            return { id: I(s.id), name: S(s.name), seat: S(s.seat_no), no: S(s.student_no), gid: I(s.group_id) };
        });
        var gps = await DB.find('stu_groups', function (g) { return I(g.class_id) === cid; });
        gps.sort(function (a, b) { return I(a.sort) - I(b.sort) || I(a.id) - I(b.id); });
        var groups = {};
        gps.forEach(function (g) { groups[I(g.id)] = S(g.name); });
        return ok({ token: token, link: 'announce_client.html?t=' + token, students: students, groups: groups, devices: await annDevicesSummary(cid) });
    },
    /** 反向喊话配置（教师端 announce_rev_cfg）：class_id 保存开关/允许模式；兼容令牌查询 */
    announce_rev_cfg: async function (prm) {
        if (prm.class_id !== undefined && prm.class_id !== '') {
            var cid0 = I(prm.class_id);
            var enabled = I(prm.enabled) === 1 ? 1 : 0;
            var modes = S(prm.modes).split(',').map(function (s) { return s.trim(); })
                .filter(function (m) { return m === 'voice' || m === 'text'; }).join(',');
            await DB.patch('classes', cid0, { rev_announce: enabled, rev_ann_modes: modes });
            return ok({ enabled: enabled, modes: modes, message: enabled ? '班级端反向喊话已开启' : '班级端反向喊话已关闭' });
        }
        var tid = await classIdByToken(prm.token || prm.t);
        if (!tid) return fail('无效令牌');
        var cls0 = await DB.get('classes', tid);
        return ok({ rev_announce: I(cls0 && cls0.rev_announce), rev_ann_modes: S(cls0 && cls0.rev_ann_modes) });
    },
    /** 反向喊话上送（公开）：需教师开启开关且允许该方式；≤200 字；待取走 ≥20 条防刷 */
    announce_rev_send: async function (prm) {
        var cid = await classIdByToken(prm.token || prm.t);
        if (!cid) return fail('班级不存在或令牌已失效，请联系教师重新复制链接');
        var cls = await DB.get('classes', cid);
        if (I(cls && cls.rev_announce) !== 1) return fail('教师未开启班级反向喊话');
        var modes = S(cls.rev_ann_modes).split(',').filter(Boolean);
        var mtype = S(prm.mtype) === 'voice' ? 'voice' : 'text';
        if (modes.indexOf(mtype) < 0) return fail(mtype === 'voice' ? '教师未允许语音播报方式' : '教师未允许文字字幕方式');
        var content = S(prm.content).trim();
        if (!content) return fail('请输入喊话内容');
        if (content.length > 200) return fail('喊话内容请控制在 200 字以内');
        var pending = await DB.find('announce_rev_msgs', function (r) {
            return I(r.class_id) === cid && (r.status === undefined ? 0 : I(r.status)) === 0;
        });
        if (pending.length >= 20) return fail('教师端尚未接收太多消息，请稍后再试');
        await DB.insert('announce_rev_msgs', {
            class_id: cid, mtype: mtype, content: content,
            font_size: Math.round(clampNum(prm.font_size, 24, 120, 48)),
            marquee: I(prm.marquee) === 1 ? 1 : 0,
            speed: 1, pitch: 1,
            times: Math.round(clampNum(prm.times, 1, 3, 1)),
            status: 0, created_at: nowStr(), picked_at: ''
        });
        return ok({ message: '已发送，等待教师端接收' });
    },
    /** 教师端在线心跳（announce_tping）：classes.ann_teacher_seen，大屏 15 秒内算在线 */
    announce_tping: async function (prm) {
        var cid = I(prm.class_id);
        if (!cid) return fail('无该班级喊话权限');
        await DB.patch('classes', cid, { ann_teacher_seen: nowStr() });
        return ok();
    },
    /** 教师端取走反向喊话（announce_rev_poll）：status=0 取走置 1（picked_at），顺带刷新教师心跳 */
    announce_rev_poll: async function (prm) {
        var cid = I(prm.class_id);
        if (!cid) return fail('无该班级喊话权限');
        await DB.patch('classes', cid, { ann_teacher_seen: nowStr() });
        var revs = await DB.by('announce_rev_msgs', 'class_id', cid);
        revs.sort(function (a, b) { return I(a.id) - I(b.id); });
        var msgs = [];
        for (var i = 0; i < revs.length && msgs.length < 20; i++) {
            if ((revs[i].status === undefined ? 0 : I(revs[i].status)) !== 0) continue;
            msgs.push({ id: I(revs[i].id), mtype: S(revs[i].mtype), content: S(revs[i].content),
                font_size: I(revs[i].font_size), marquee: I(revs[i].marquee) === 1 ? 1 : 0,
                speed: parseFloat(revs[i].speed) || 1, pitch: parseFloat(revs[i].pitch) || 1, times: I(revs[i].times) || 1 });
            revs[i].status = 1; revs[i].picked_at = nowStr();
            await DB.update('announce_rev_msgs', revs[i]);
        }
        return ok({ msgs: msgs });
    }
};

// （旧版 annInsert(prm, mtype, filePath) 已废弃；新版见本分区头部公共辅助区）
function addMinutes(dt, min) {
    var d = new Date(dt.replace(/-/g, '/'));
    d.setMinutes(d.getMinutes() + min);
    return nowStr(d);
}
/** 客户端 token → class_id（classes.announce_token） */
async function classIdByToken(token) {
    token = S(token).replace(/[^0-9a-f]/g, '');
    if (!/^[0-9a-f]{32}$/.test(token)) return 0;
    var rows = await DB.find('classes', function (c) { return S(c.announce_token) === token && !c.deleted_at; });
    return rows.length ? I(rows[0].id) : 0;
}

// ================================================================
// [组E] 公共：轮次/视图/点评（轻量接口先行；[组A] 登记评价另由迁移填充）
// ================================================================
var CommonHandlers = {
    /** 轮次列表（round_list）：原版形状 {rounds:[no..], titles:{no:title}, counts:{no:登记数}, current} */
    round_list: async function (prm) {
        var pid = I(prm.project_id);
        var rounds = (await DB.by('project_rounds', 'project_id', pid)).sort(function (a, b) { return I(a.round_no) - I(b.round_no); });
        var rrs = await DB.find('record_rounds', function (r) { return I(r.project_id) === pid; });
        var counts = {};
        rounds.forEach(function (r) { counts[r.round_no] = 0; });
        rrs.forEach(function (r) {
            var k = I(r.round_no);
            if (counts[k] === undefined) counts[k] = 0;
            counts[k]++;
        });
        var titles = {};
        rounds.forEach(function (r) { if (r.title) titles[r.round_no] = r.title; });
        return ok({
            list: rounds,
            rounds: rounds.map(function (r) { return I(r.round_no); }),
            titles: titles,
            counts: counts,
            current: rounds.length ? I(rounds[rounds.length - 1].round_no) : 0
        });
    },
    /** 新增轮次（round_add） */
    round_add: async function (prm) {
        var pid = I(prm.project_id);
        var existing = await DB.by('project_rounds', 'project_id', pid);
        var mx = 0;
        existing.forEach(function (r) { mx = Math.max(mx, I(r.round_no)); });
        var row = await DB.insert('project_rounds', { project_id: pid, round_no: mx + 1, title: S(prm.title) || null, correct_opts: null, created_at: nowStr() });
        return ok({ round: row.round_no, id: row.id });
    },
    /** 删除轮次（round_delete）：连带 record_rounds/omr_results */
    round_delete: async function (prm) {
        var pid = I(prm.project_id), n = I(prm.round);
        var prow = (await DB.find('project_rounds', function (r) { return I(r.project_id) === pid && I(r.round_no) === n; }))[0];
        if (prow) await DB.del('project_rounds', prow.id);
        await DB.delWhere('record_rounds', function (r) { return I(r.project_id) === pid && I(r.round_no) === n; });
        await DB.delWhere('omr_results', function (r) { return I(r.project_id) === pid && I(r.round_no) === n; });
        return ok();
    },
    /** 重命名轮次（round_rename） */
    round_rename: async function (prm) {
        var pid = I(prm.project_id), n = I(prm.round);
        var prow = (await DB.find('project_rounds', function (r) { return I(r.project_id) === pid && I(r.round_no) === n; }))[0];
        if (!prow) return fail('轮次不存在');
        prow.title = S(prm.title);
        await DB.update('project_rounds', prow);
        return ok();
    },
    /** 答案键保存（round_correct） */
    round_correct: async function (prm) {
        var pid = I(prm.project_id), n = I(prm.round);
        var prow = (await DB.find('project_rounds', function (r) { return I(r.project_id) === pid && I(r.round_no) === n; }))[0];
        if (!prow) return fail('轮次不存在');
        prow.correct_opts = S(prm.correct);
        await DB.update('project_rounds', prow);
        return ok();
    },
    /** 轮次排序（round_reorder） */
    round_reorder: async function (prm) {
        var pid = I(prm.project_id);
        var order = (S(prm.order) || '').split(',').filter(Boolean).map(function (x) { return I(x); });
        var rounds = await DB.by('project_rounds', 'project_id', pid);
        for (var i = 0; i < order.length; i++) {
            for (var j = 0; j < rounds.length; j++) {
                if (I(rounds[j].id) === order[i]) { rounds[j].round_no = i + 1; await DB.update('project_rounds', rounds[j]); }
            }
        }
        return ok();
    },
    /** 点评保存（comment_save，单条 upsert） */
    comment_save: async function (prm) {
        var pid = I(prm.project_id), sid = I(prm.student_id);
        var content = S(prm.content);
        if (content.length > 500) return fail('点评最多500字');
        var ex = (await DB.find('student_comments', function (r) { return I(r.project_id) === pid && I(r.student_id) === sid; }))[0];
        if (!content) { if (ex) await DB.del('student_comments', ex.id); return ok({ deleted: true }); }
        if (ex) { ex.content = content; ex.updated_at = nowStr(); await DB.update('student_comments', ex); }
        else await DB.insert('student_comments', { project_id: pid, student_id: sid, content: content, created_at: nowStr(), updated_at: nowStr() });
        return ok();
    },
    /** 点评批量导入（comments_batch：多行「编号/姓名,点评」） */
    comments_batch: async function (prm) {
        var pid = I(prm.project_id);
        var lines = S(prm.content).split(/\r?\n/);
        var stus = await DB.find('students', function (s) { return !s.disabled; });
        var proj = await DB.get('projects', pid);
        var okCnt = 0, skip = [];
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i].trim();
            if (!line) continue;
            var idx = line.indexOf(',');
            if (idx < 0) { skip.push(line); continue; }
            var who = line.substring(0, idx).trim(), comment = line.substring(idx + 1).trim();
            if (!comment) { skip.push(line); continue; }
            // 优先编号精确匹配，姓名兜底（重名跳过）
            var matched = stus.filter(function (s) { return I(s.class_id) === I(proj.class_id) && S(s.student_no) === who; });
            if (!matched.length) {
                var byName = stus.filter(function (s) { return I(s.class_id) === I(proj.class_id) && S(s.name) === who; });
                if (byName.length === 1) matched = byName;
                else if (byName.length > 1) { skip.push(line); continue; }
            }
            if (!matched.length) { skip.push(line); continue; }
            var sid = I(matched[0].id);
            var ex = (await DB.find('student_comments', function (r) { return I(r.project_id) === pid && I(r.student_id) === sid; }))[0];
            if (ex) { ex.content = comment; ex.updated_at = nowStr(); await DB.update('student_comments', ex); }
            else await DB.insert('student_comments', { project_id: pid, student_id: sid, content: comment, created_at: nowStr(), updated_at: nowStr() });
            okCnt++;
        }
        return ok({ ok: okCnt, skip: skip });
    }
};

// ===== 分发路由表（各组 handler 合并；[组A][组B][组C] 由迁移任务填充到 HandlersExtra） =====
var Handlers = {};
function reg(map) { Object.keys(map).forEach(function (k) { Handlers[k] = map[k]; }); }
reg(AnnHandlers);
reg(CommonHandlers);
if (typeof global.ApiExtraHandlers === 'object' && global.ApiExtraHandlers) reg(global.ApiExtraHandlers);

// ===== 导出入口 =====
async function dispatch(type, prm, files) {
    type = S(type);
    if (!type) return fail('缺少 type');
    var h = Handlers[type];
    if (!h) {
        // 未实现接口：结构化报错，便于开发期发现
        return fail('本地版未实现接口: ' + type);
    }
    try {
        var res = await h(prm, files || {}, prm);
        // 下载/文件类接口直接返回 {status, body, headers} → 原样透传（调用方包装为 Response）
        if (res && typeof res.status === 'number' && res.headers) return res;
        return res;
    } catch (e) {
        return fail('本地处理异常: ' + (e && e.message || e));
    }
}

var ApiLocal = {
    nowStr: nowStr, dateStr: dateStr, I: I, S: S, ok: ok, fail: fail,
    reg: reg,
    fileStore: fileStore, handleLocalFile: handleLocalFile,
    dispatch: dispatch,
    /** SW 入口：Request 手工解析（query + POST body；Request 对象不落入 parseInput 的 FormData/string 分支） */
    handle: async function (request) {
        var u = new URL(request.url);
        var path = u.pathname;
        var prm = {}, files = {};
        u.search.substring(1).split('&').forEach(function (kv) {
            if (!kv) return;
            var p = kv.split('=');
            prm[decodeURIComponent(p[0])] = decodeURIComponent((p[1] || '').replace(/\+/g, ' '));
        });
        if (request.method === 'POST') {
            try {
                var ct = request.headers.get('Content-Type') || '';
                if (/multipart\/form-data|application\/x-www-form-urlencoded/i.test(ct)) {
                    absorbFormData(await request.formData(), prm, files);
                } else {
                    var txt = await request.text();
                    if (/json/i.test(ct)) { try { var jo = JSON.parse(txt); Object.keys(jo).forEach(function (k) { prm[k] = S(jo[k]); }); } catch (e2) {} }
                    else txt.split('&').forEach(function (kv) {
                        if (!kv) return;
                        var p2 = kv.split('=');
                        prm[decodeURIComponent(p2[0])] = decodeURIComponent((p2[1] || '').replace(/\+/g, ' '));
                    });
                }
            } catch (e) { /* body 解析失败按无 body 处理 */ }
        }
        if (/localfile\.php$/.test(path)) return toResponse(await handleLocalFile(prm, prm.download === '1'));
        if (!/api\.php$/.test(path)) return new Response('not found', { status: 404 });
        var res = await dispatch(prm.type, prm, files);
        if (res && typeof res.status === 'number' && res.headers) return toResponse(res);   // 下载/图片类接口原样透传
        return jsonRes(res);
    },
    /** fetch 补丁入口：url=完整相对地址, init=fetch init */
    handleRequest: async function (url, init) {
        init = init || {};
        var parsed = parseInput(url, { method: init.method || (init.body ? 'POST' : 'GET'), body: init.body, headers: init.headers });
        var prm = parsed.prm;
        if (/localfile\.php/.test(url)) {
            var r = await handleLocalFile(prm, prm.download === '1');
            return r;   // {status, body, headers}
        }
        var res = await dispatch(prm.type, prm, parsed.files);
        if (res && typeof res.status === 'number' && res.headers) return res;   // 下载/文件类透传
        return { status: 200, body: JSON.stringify(res), headers: { 'Content-Type': 'application/json; charset=utf-8' } };
    },
    json: jsonRes
};
function jsonRes(obj) {
    return new Response(JSON.stringify(obj), { status: 200, headers: { 'Content-Type': 'application/json; charset=utf-8' } });
}
function toResponse(r) {
    return new Response(r.body, { status: r.status, headers: r.headers || {} });
}

global.ApiLocal = ApiLocal;
// SW 中 self 即 global；浏览器中 window
if (typeof self !== 'undefined' && self !== global) self.ApiLocal = ApiLocal;
})(typeof self !== 'undefined' ? self : this);
