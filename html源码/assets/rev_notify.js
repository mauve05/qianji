/**
 * 教师端反向喊话提示 + 教师在线心跳（班级喊话配套；projects.php / project_view.php / scan.php / omr_scan.php 共用）
 *
 * revNotify(classId, mode, opts)：
 *   - 每 10 秒发送教师在线心跳（announce_tping）：教师打开页面即算在线，大屏客户端显示「🟢 教师在线」
 *   - 每 5 秒轮询反向喊话反馈（announce_rev_poll，取走式），收到后按 mode 提示：
 *       mode='dialog'：居中弹窗——点「✓ 确认」关闭，不点击 5 秒自动关闭（projects.php / project_view.php）
 *       mode='toast' ：右上浮窗——3 秒自动消失（scan.php / omr_scan.php 扫码场景，不打断操作）
 *   - opts.project_id：扫码页教师可能仅有项目查看/登记权限，服务端按项目鉴权并换算班级
 * revNotifyShow(m, mode)：单条反馈提示入口（project_view.php 已有自己的轮询/语音/横幅，仅在收到时调用）
 */
var revNtfTimer = null;   // dialog 5 秒自动关闭计时

function revNotify(classId, mode, opts) {
    var cid = parseInt(classId, 10) || 0;
    if (!cid) return;
    opts = opts || {};
    var extra = opts.project_id ? '&project_id=' + parseInt(opts.project_id, 10) : '';
    function post(body) {
        return fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(function (r) { return r.json(); });
    }
    // 教师在线心跳：打开页面即算在线（每 10 秒）
    function hb() { post('type=announce_tping&class_id=' + cid + extra).catch(function () {}); }
    hb();
    setInterval(hb, 10000);
    // 反馈轮询：取走 status=0 的反向喊话消息（每 5 秒；无权限自动停止）
    var stopped = false;
    function poll() {
        if (stopped) return;
        post('type=announce_rev_poll&class_id=' + cid + extra)
        .then(function (d) {
            if (!d || !d.success) { stopped = true; return; }
            (d.msgs || []).forEach(function (m) { revNotifyShow(m, mode); });
        })
        .catch(function () {})
        .then(function () { if (!stopped) setTimeout(poll, 5000); });
    }
    poll();
}

function revNotifyNow() {
    var t = new Date();
    function p(n) { return (n < 10 ? '0' : '') + n; }
    return p(t.getMonth() + 1) + '-' + p(t.getDate()) + ' ' + p(t.getHours()) + ':' + p(t.getMinutes());
}

function revNotifyClose() {
    var mask = document.getElementById('revNtfMask');
    if (mask) mask.style.display = 'none';
    clearTimeout(revNtfTimer);
    revNtfTimer = null;
}

function revNotifyShow(m, mode) {
    var txt = String((m && m.content) || '').trim();
    if (!txt) return;
    var tag = m.mtype === 'voice' ? '🎤 语音回复' : '📝 文字回复';
    var ts = revNotifyNow();
    if (mode === 'toast') {
        // 右上浮窗：3 秒自动消失（叠加显示，各自计时）
        var el = document.createElement('div');
        el.style.cssText = 'position:fixed;top:14px;right:14px;z-index:99999;background:#fff;'
            + 'border-left:4px solid #4a7de0;box-shadow:0 4px 16px rgba(0,0,0,.18);border-radius:8px;'
            + 'padding:10px 14px;max-width:320px;font-size:14px;color:#333;';
        el.innerHTML = '<b style="color:#4a7de0;">📥 学生回复</b> <span style="color:#999;font-size:12px;">' + ts + '</span><br>'
            + '<span style="color:#888;font-size:12px;">' + tag + '</span><br>'
            + '<span style="word-break:break-all;"></span>';
        el.lastChild.textContent = txt;
        document.body.appendChild(el);
        setTimeout(function () { el.remove(); }, 3000);
        return;
    }
    // 居中弹窗：点「✓ 确认」关闭；不点击 5 秒自动关闭；新消息覆盖内容并重置计时
    var mask = document.getElementById('revNtfMask');
    if (!mask) {
        mask = document.createElement('div');
        mask.id = 'revNtfMask';
        mask.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.35);'
            + 'display:none;align-items:center;justify-content:center;';
        mask.innerHTML = '<div style="background:#fff;border-radius:12px;max-width:420px;width:88%;'
            + 'box-shadow:0 8px 30px rgba(0,0,0,.25);overflow:hidden;">'
            + '<div style="background:#4a7de0;color:#fff;padding:10px 16px;font-weight:bold;font-size:15px;">📥 收到学生回复</div>'
            + '<div style="padding:14px 16px;">'
            + '<div id="revNtfTag" style="color:#888;font-size:12px;margin-bottom:6px;"></div>'
            + '<div id="revNtfTxt" style="font-size:16px;color:#222;word-break:break-all;white-space:pre-wrap;"></div>'
            + '<div id="revNtfTs" style="margin-top:8px;color:#999;font-size:12px;"></div>'
            + '</div>'
            + '<div style="padding:0 16px 14px;text-align:right;">'
            + '<button type="button" id="revNtfOk" style="background:#27ae60;color:#fff;border:0;border-radius:8px;padding:8px 22px;font-size:14px;font-weight:bold;cursor:pointer;">✓ 确认</button>'
            + '</div></div>';
        document.body.appendChild(mask);
        mask.querySelector('#revNtfOk').onclick = revNotifyClose;
    }
    mask.querySelector('#revNtfTag').textContent = tag;
    mask.querySelector('#revNtfTxt').textContent = txt;
    mask.querySelector('#revNtfTs').textContent = ts + '（5 秒后自动关闭）';
    mask.style.display = 'flex';
    clearTimeout(revNtfTimer);
    revNtfTimer = setTimeout(revNotifyClose, 5000);
}
