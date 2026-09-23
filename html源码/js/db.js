/**
 * 潜记单用户版 - IndexedDB 数据层
 * 模拟原 MySQL 表结构；所有页面共享此文件。
 * 表清单与字段与原 install.php 保持一致（去除多用户/学校/权限/喊话相关字段）。
 *
 * 约定：
 *  - 时间字符串格式 'YYYY-MM-DD HH:MM:SS'（与原 PHP NOW() 一致，本地时间）
 *  - 自增 id 由本层维护（settings.__seq_<table>），insert 自动分配
 *  - 图片二进制存 files 表（key=文件名），omr_images.file 等字段存其 key
 */
(function (global) {
'use strict';

var DB_NAME = 'qj_local';

// 表清单（keyPath=id 自增；settings 以 skey 为主键；files 以 name 为主键）
var STORES = [
    'settings',      // {skey, svalue} 平台/页面偏好 + 自增序号 __seq_*
    'classes', 'students', 'projects', 'records', 'record_days',
    'project_rounds', 'record_rounds', 'student_comments', 'eval_modes',
    'stu_groups', 'pinned_items', 'import_payloads',
    'omr_templates', 'omr_results', 'omr_answers', 'omr_lib', 'omr_images',
    'points_items', 'points_log', 'points_rules', 'points_gifts',
    'announce_msgs', 'announce_devices', 'announce_rev_msgs',
    'files'          // {name: 文件名, blob: Blob}（答题卡批改图/喊话音图等二进制）
];
var PK_TABLES = { settings: 'skey', files: 'name' };   // 非自增 id 主键表
var AUTO_TABLES = STORES.filter(function (t) { return !PK_TABLES[t]; });

var _db = null;
var _openPromise = null;

/** 打开数据库（动态版本：缺 store 时才 +1 升级补建，避免多标签页版本冲突阻塞） */
function open() {
    if (_db) return Promise.resolve(_db);
    if (_openPromise) return _openPromise;
    _openPromise = new Promise(function (resolve, reject) {
        // 第一次不带版本号打开（任意现有版本均可成功）
        var req = indexedDB.open(DB_NAME);
        req.onupgradeneeded = function (e) {
            var db = e.target.result;
            STORES.forEach(function (t) {
                if (!db.objectStoreNames.contains(t)) {
                    db.createObjectStore(t, { keyPath: PK_TABLES[t] || 'id' });
                }
            });
        };
        req.onsuccess = function (e) {
            var db = e.target.result;
            var missing = STORES.filter(function (t) { return !db.objectStoreNames.contains(t); });
            if (!missing.length) { _db = db; resolve(db); return; }
            // 缺 store：关闭后按当前版本+1 升级补建（幂等）
            var ver = db.version + 1;
            db.close();
            var req2 = indexedDB.open(DB_NAME, ver);
            req2.onupgradeneeded = function (e2) {
                var db2 = e2.target.result;
                STORES.forEach(function (t) {
                    if (!db2.objectStoreNames.contains(t)) {
                        db2.createObjectStore(t, { keyPath: PK_TABLES[t] || 'id' });
                    }
                });
            };
            req2.onsuccess = function (e2) { _db = e2.target.result; resolve(_db); };
            req2.onerror = function () { _openPromise = null; reject(req2.error); };
            req2.onblocked = function () {
                _openPromise = null;
                reject(new Error('数据库升级被其他标签页占用，请关闭本页其他标签后刷新'));
            };
        };
        req.onerror = function () { _openPromise = null; reject(req.error); };
    });
    return _openPromise;
}

function tx(store, mode) {
    return open().then(function (db) { return db.transaction(store, mode).objectStore(store); });
}

function req2p(req) {
    return new Promise(function (resolve, reject) {
        req.onsuccess = function () { resolve(req.result); };
        req.onerror = function () { reject(req.error); };
    });
}

/** 等待事务完成（IDBTransaction 用 oncomplete 而非 onsuccess） */
function txDone(t) {
    return new Promise(function (resolve, reject) {
        t.oncomplete = function () { resolve(); };
        t.onerror = function () { reject(t.error); };
        t.onabort = function () { reject(t.error || new Error('事务中止')); };
    });
}

// ===== 时间工具 =====
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

// ===== 自增 id =====
function nextId(table) {
    return tx('settings', 'readonly').then(function (st) {
        return req2p(st.get('__seq_' + table));
    }).then(function (row) {
        var id = row ? (parseInt(row.svalue, 10) || 0) + 1 : 1;
        return tx('settings', 'readwrite').then(function (st) {
            st.put({ skey: '__seq_' + table, svalue: String(id) });
            return txDone(st.transaction);   // 等待写入完成
        }).then(function () { return id; });
    });
}

// ===== 对外 API（全部返回 Promise）=====
var DB = {
    open: open,
    nowStr: nowStr,
    dateStr: dateStr,

    /** 全部行（数组） */
    all: function (table) {
        return tx(table, 'readonly').then(function (st) { return req2p(st.getAll()); });
    },

    /** 主键取一行；不存在返回 null */
    get: function (table, key) {
        return tx(table, 'readonly').then(function (st) { return req2p(st.get(key)); })
            .then(function (r) { return r || null; });
    },

    /** 等值字段查询（数组）；field 为 'id' 时走主键 */
    by: function (table, field, value) {
        if (field === 'id') return DB.get(table, value).then(function (r) { return r ? [r] : []; });
        return DB.all(table).then(function (rows) {
            return rows.filter(function (r) { return r[field] == value; });
        });
    },

    /** 等值查询第一条；不存在返回 null */
    first: function (table, field, value) {
        return DB.by(table, field, value).then(function (rows) { return rows.length ? rows[0] : null; });
    },

    /** 谓词过滤查询（数组） */
    find: function (table, fn) {
        return DB.all(table).then(function (rows) { return rows.filter(fn); });
    },

    /** 插入（自动分配自增 id）；返回插入后的完整行 */
    insert: function (table, row) {
        if (PK_TABLES[table]) {
            return tx(table, 'readwrite').then(function (st) { return req2p(st.put(row)); }).then(function () { return row; });
        }
        return nextId(table).then(function (id) {
            row.id = id;
            return tx(table, 'readwrite').then(function (st) { return req2p(st.put(row)); }).then(function () { return row; });
        });
    },

    /** 批量插入（保留原 id；不推进 seq 时自动校正） */
    insertMany: function (table, rows) {
        if (!rows.length) return Promise.resolve();
        return tx(table, 'readwrite').then(function (st) {
            rows.forEach(function (r) { st.put(r); });
            return txDone(st.transaction);
        }).then(function () {
            if (PK_TABLES[table]) return;
            var maxId = rows.reduce(function (m, r) { return Math.max(m, parseInt(r.id, 10) || 0); }, 0);
            return DB.seqAtLeast(table, maxId);
        });
    },

    /** 保证自增 seq ≥ id（导入备份后校正） */
    seqAtLeast: function (table, id) {
        return tx('settings', 'readonly').then(function (st) { return req2p(st.get('__seq_' + table)); })
            .then(function (row) {
                var cur = row ? (parseInt(row.svalue, 10) || 0) : 0;
                if (cur >= id) return;
                return tx('settings', 'readwrite').then(function (st) {
                    st.put({ skey: '__seq_' + table, svalue: String(id) });
                    return txDone(st.transaction);
                });
            });
    },

    /** 更新整行（必须含 id） */
    update: function (table, row) {
        return tx(table, 'readwrite').then(function (st) { return req2p(st.put(row)); }).then(function () { return row; });
    },

    /** 局部更新：取出→合并 fields→写回；行不存在返回 null */
    patch: function (table, id, fields) {
        return DB.get(table, id).then(function (row) {
            if (!row) return null;
            Object.keys(fields).forEach(function (k) { row[k] = fields[k]; });
            return DB.update(table, row);
        });
    },

    /** 删除（主键） */
    del: function (table, key) {
        return tx(table, 'readwrite').then(function (st) { return req2p(st.delete(key)); });
    },

    /** 谓词批量删除 */
    delWhere: function (table, fn) {
        return DB.find(table, fn).then(function (rows) {
            return tx(table, 'readwrite').then(function (st) {
                rows.forEach(function (r) { st.delete(PK_TABLES[table] ? r[PK_TABLES[table]] : r.id); });
                return txDone(st.transaction);
            }).then(function () { return rows.length; });
        });
    },

    // ===== settings 快捷 =====
    settingsGet: function (key, dft) {
        return tx('settings', 'readonly').then(function (st) { return req2p(st.get(key)); })
            .then(function (r) { return r ? r.svalue : (dft === undefined ? '' : dft); });
    },
    settingsSet: function (key, value) {
        return tx('settings', 'readwrite').then(function (st) {
            st.put({ skey: key, svalue: String(value) });
            return txDone(st.transaction);
        });
    },

    // ===== 二进制文件 =====
    fileSave: function (name, blob) {
        return tx('files', 'readwrite').then(function (st) {
            st.put({ name: name, blob: blob });
            return txDone(st.transaction);
        }).then(function () { return name; });
    },
    fileGet: function (name) {
        return tx('files', 'readonly').then(function (st) { return req2p(st.get(name)); })
            .then(function (r) { return r ? r.blob : null; });
    },
    fileDel: function (name) {
        return tx('files', 'readwrite').then(function (st) { return req2p(st.delete(name)); });
    },

    // ===== 备份 / 恢复 =====
    exportJSON: function () {
        var out = { app: 'qj_local', version: 1, exported_at: nowStr(), tables: {}, files: [] };
        var chain = Promise.resolve();
        STORES.forEach(function (t) {
            chain = chain.then(function () {
                return DB.all(t).then(function (rows) {
                    if (t === 'files') {
                        rows.forEach(function (r) {
                            out.files.push({ name: r.name, data: null, _pending: r.blob });
                        });
                    } else {
                        out.tables[t] = rows;
                    }
                });
            });
        });
        return chain.then(function () {
            // blob → base64 内嵌
            var pend = out.files.filter(function (f) { return f._pending; });
            var pchain = Promise.resolve();
            pend.forEach(function (f) {
                pchain = pchain.then(function () {
                    return blobToDataURL(f._pending).then(function (durl) {
                        f.data = durl;
                        delete f._pending;
                    });
                });
            });
            return pchain.then(function () { return out; });
        });
    },

    /** 导入备份：清空现有数据后整库重建；keepSeq 自动校正自增 */
    importJSON: function (obj) {
        if (!obj || obj.app !== 'qj_local' || !obj.tables) {
            return Promise.reject(new Error('备份文件格式不正确'));
        }
        return open().then(function (db) {
            return new Promise(function (resolve, reject) {
                var t = db.transaction(STORES, 'readwrite');
                STORES.forEach(function (s) { t.objectStore(s).clear(); });
                t.objectStore('settings').clear();
                Object.keys(obj.tables).forEach(function (s) {
                    if (!t.objectStoreNames.contains(s)) return;
                    (obj.tables[s] || []).forEach(function (row) { t.objectStore(s).put(row); });
                });
                (obj.files || []).forEach(function (f) {
                    if (!f.data) return;
                    t.objectStore('files').put({ name: f.name, blob: dataURLtoBlob(f.data) });
                });
                // 自增 seq 校正
                AUTO_TABLES.forEach(function (s) {
                    var rows = obj.tables[s] || [];
                    var maxId = rows.reduce(function (m, r) { return Math.max(m, parseInt(r.id, 10) || 0); }, 0);
                    if (maxId > 0) t.objectStore('settings').put({ skey: '__seq_' + s, svalue: String(maxId) });
                });
                t.oncomplete = function () { resolve(true); };
                t.onerror = function () { reject(t.error); };
            });
        });
    },

    /** 清空全部数据（含 seq） */
    clearAll: function () {
        return open().then(function (db) {
            return new Promise(function (resolve, reject) {
                var t = db.transaction(STORES, 'readwrite');
                STORES.forEach(function (s) { t.objectStore(s).clear(); });
                t.objectStore('settings').clear();
                t.oncomplete = function () { resolve(true); };
                t.onerror = function () { reject(t.error); };
            });
        });
    },

    /** 数据量概览（备份页展示用） */
    stats: function () {
        var out = {};
        var chain = Promise.resolve();
        STORES.forEach(function (t) {
            chain = chain.then(function () {
                return tx(t, 'readonly').then(function (st) { return req2p(st.count()); })
                    .then(function (c) { out[t] = c; });
            });
        });
        return chain.then(function () { return out; });
    }
};

function blobToDataURL(blob) {
    return new Promise(function (resolve, reject) {
        var fr = new FileReader();
        fr.onload = function () { resolve(fr.result); };
        fr.onerror = function () { reject(fr.error); };
        fr.readAsDataURL(blob);
    });
}
function dataURLtoBlob(durl) {
    var parts = durl.split(',');
    var mime = (parts[0].match(/data:(.*?);/) || [])[1] || 'application/octet-stream';
    var bin = atob(parts[1]);
    var arr = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    return new Blob([arr], { type: mime });
}

global.DB = DB;
})(typeof self !== 'undefined' ? self : window);
