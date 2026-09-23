/**
 * 潜记单机版 - Service Worker
 * 拦截同源 api.php / localfile.php 请求，路由到 ApiLocal（IndexedDB 实现）。
 * importScripts 环境无 window，db.js/common.js/api_local.js 均兼容 self 挂载。
 */
importScripts('js/db.js', 'js/common.js', 'js/api_local.js',
    'js/api_impl_a.js', 'js/api_impl_b.js', 'js/api_impl_c.js');

self.addEventListener('install', function (e) {
    self.skipWaiting();
});
self.addEventListener('activate', function (e) {
    e.waitUntil(self.clients.claim());
});
self.addEventListener('fetch', function (e) {
    var url = new URL(e.request.url);
    if (url.origin !== location.origin) return;
    var p = url.pathname;
    if (/\/api\.php$/.test(p) || /\/localfile\.php$/.test(p) || /\/localfile\.php$/.test(p)) {
        e.respondWith(self.ApiLocal.handle(e.request));
    }
});
