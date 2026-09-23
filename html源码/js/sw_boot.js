/**
 * 潜记单机版 - 启动引导（每页在业务 JS 之前引入）
 * 1) 注册 Service Worker（http/https 场景，接管 api.php/localfile.php 全部请求——含 <a> 导航与 img/audio src）
 * 2) monkey-patch window.fetch（覆盖页面 JS 的 fetch('api.php') 调用；file:// 场景的唯一切换通道）
 * 3) file:// 场景对 api.php 的 <a href> 导航做点击委托（下载类接口）
 */
(function () {
'use strict';

var IS_FILE = location.protocol === 'file:';

// ---- 1. SW 注册 ----
var SW_OK = !IS_FILE && !!navigator.serviceWorker &&
    (location.hostname === 'localhost' || /^127\./.test(location.hostname) || location.protocol === 'https:');
if (SW_OK) {
    try { navigator.serviceWorker.register('sw.js').catch(function () {}); } catch (e) {}
}

// ---- 2. fetch 补丁 ----
var _origFetch = null;
try { _origFetch = window.fetch.bind(window); } catch (e) {}
function isLocalApi(u) {
    var s = String(u || '');
    return /(^|\/|\\)api\.php(\?|#|$)/.test(s) || /(^|\/|\\)localfile\.php(\?|#|$)/.test(s);
}
function wrapResult(r) {
    // ApiLocal.handleRequest 返回 {status, body, headers}；SW 返回 Response
    if (r && typeof r.status === 'number' && !(r instanceof Response) && typeof Response !== 'undefined') {
        return new Response(r.body, { status: r.status, headers: r.headers || {} });
    }
    return r;
}
if (_origFetch) {
    window.fetch = function (input, init) {
        try {
            var u = typeof input === 'string' ? input : (input && input.url) || '';
            if (isLocalApi(u)) {
                return ApiLocal.handleRequest(u, init).then(wrapResult);
            }
        } catch (e) { /* 落回原生 */ }
        return _origFetch(input, init);
    };
}

// ---- 3. file:// 下 <a href="api.php?..."> 下载链接兜底 ----
if (IS_FILE) {
    document.addEventListener('click', function (ev) {
        var a = ev.target && ev.target.closest ? ev.target.closest('a[href*="api.php"]') : null;
        if (!a) return;
        var href = a.getAttribute('href') || '';
        if (!isLocalApi(href)) return;
        ev.preventDefault();
        ApiLocal.handleRequest(href, {}).then(function (r) {
            var blob = r.body instanceof Blob ? r.body : new Blob([r.body], { type: (r.headers && r.headers['Content-Type']) || 'application/octet-stream' });
            var cd = (r.headers && r.headers['Content-Disposition']) || '';
            var m = /filename="?([^";]+)"?/.exec(cd);
            var url = URL.createObjectURL(blob);
            var dl = document.createElement('a');
            dl.href = url; dl.download = m ? m[1] : 'export';
            document.body.appendChild(dl); dl.click(); dl.remove();
            setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
        }).catch(function () { showToast('导出失败', 'error'); });
    }, true);
}
})();
