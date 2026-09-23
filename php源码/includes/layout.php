<?php
/**
 * 公共页面布局（登录后的页面顶部导航）
 */
if (!function_exists('page_header')) {
    function page_header($title, $active = '') {
        // 弹窗内嵌模式（URL 带 embed=1）：隐藏导航/抽屉/底部栏/帮助按钮，仅保留页面主体（供弹窗 iframe 加载）
        $embed = intval($_GET['embed'] ?? 0) === 1;
        $teacher_name = isset($_SESSION['teacher_name']) ? $_SESSION['teacher_name'] : '';
        $teacher_id = isset($_SESSION['teacher_id']) ? intval($_SESSION['teacher_id']) : 0;
        $conn = function_exists('getConnection') ? getConnection() : null;
        $is_admin = false;
        $can_export = false;
        if ($conn && $teacher_id > 0) {
            require_once __DIR__ . '/functions.php';
            $is_admin = is_super_admin($conn, $teacher_id); // 单用户版：管理员=安装时生成的账号（teachers.is_admin=1）
            // 报表入口：管理员或任一有可见班级者（个人账号的自建/加入班级均可查看自己的统计报表）
            $can_export = is_super_admin($conn, $teacher_id) || count(get_visible_class_ids($conn, $teacher_id)) > 0;
        }
        // 单用户版：无学校概念，导航不显示学校徽章与切换
        $school_label = '';
        // ===== 导航上下文检测：默认 / 班级上下文 / 项目上下文 =====
        $nav_script = basename($_SERVER['PHP_SELF']);
        $nav_class_id = intval($_GET['class_id'] ?? 0);
        $nav_project_id = intval($_GET['project_id'] ?? 0);
        $nav_ctx = 'default';
        if ($nav_project_id > 0 && in_array($nav_script, ['project_view.php', 'scan.php', 'stats.php', 'omr_scan.php', 'omr_designer.php'], true)) {
            $nav_ctx = 'project';
        } elseif ($nav_class_id > 0 && in_array($nav_script, ['projects.php', 'students.php', 'stats.php'], true)) {
            $nav_ctx = 'class';
        }
        // 导航项：[链接, 脚本名（高亮匹配 page_header 的 $active）, 图标, 标签]
        if ($nav_ctx === 'class') {
            $nav_items = [
                ["projects.php?class_id={$nav_class_id}", 'projects.php', '📘', '班级情况'],
                ["students.php?class_id={$nav_class_id}", 'students.php', '👥', '学生列表'],
                ["stats.php?class_id={$nav_class_id}", 'stats.php', '📊', '班级统计'],
            ];
        } elseif ($nav_ctx === 'project') {
            // 项目统计：多班级项目带 cls_id 时锁定对应班级，否则由 stats.php 按 project_id 推导
            $nav_stats_url = 'stats.php?project_id=' . $nav_project_id;
            $nav_cls_id = intval($_GET['cls_id'] ?? 0);
            if ($nav_cls_id > 0) $nav_stats_url .= '&class_id=' . $nav_cls_id;
            // 当前页 URL 带 round 时（project_view / scan / omr_scan），项目情况与扫码/扫描识别入口同步锁定该题次，避免登记/查看串题次
            $nav_round_q = (isset($_GET['round']) && preg_match('/^\d+$/', strval($_GET['round']))) ? '&round=' . intval($_GET['round']) : '';
            $nav_items = [
                ["project_view.php?project_id={$nav_project_id}{$nav_round_q}", 'project_view.php', '📙', '项目情况'],
                [$nav_stats_url, 'stats.php', '📊', '项目统计'],
            ];
            // 答题卡模式项目：「扫码登记」入口替换为「扫描识别」（插在项目情况之后，页面同 project_view 顶底导航）
            // 仅查看/无登记操作权限成员不显示扫码/扫描识别入口（顶部导航、移动端抽屉与底部栏共用本数组，同步隐藏）
            // 注意：can_operate_project 第二参需传项目数组（取 scope/class_id 等判定），传项目 ID 会恒为 false
            $nav_proj_row = null;
            $nav_scan_item = ["scan.php?project_id={$nav_project_id}{$nav_round_q}", 'scan.php', '📷', '扫码登记'];
            $mres = mysqli_query($conn, "SELECT * FROM projects WHERE id = {$nav_project_id} AND deleted_at IS NULL");
            if ($mres && ($nav_proj_row = mysqli_fetch_assoc($mres)) && ($nav_proj_row['mode'] ?? '') === 'omr') {
                $nav_scan_item = ["omr_scan.php?project_id={$nav_project_id}{$nav_round_q}", 'omr_scan.php', '📄', '扫描识别'];
            }
            if ($nav_proj_row && $conn && $teacher_id > 0 && can_operate_project($conn, $teacher_id, $nav_proj_row)) {
                array_splice($nav_items, 1, 0, [$nav_scan_item]);
            }
        } else {
            $nav_items = [
                ['classes.php', 'classes.php', '📘', '班级列表'],
            ];
            if ($can_export) $nav_items[] = ['export.php', 'export.php', '📊', '统计报表'];
        }
        $menus = $nav_items;
        if ($is_admin) $menus[] = ['admin.php', 'admin.php', '⚙️', '后台管理'];
        $menus[] = ['profile.php', 'profile.php', '👤', '个人中心'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> - 潜记二维码作业登记系统</title>
    <link rel="stylesheet" href="assets/css/style.css?v=20260915">
    <link rel="icon" href="favicon.ico" type="image/x-icon" />
    <link rel="shortcut icon" href="favicon.ico"/>
</head>
<body>
<?php if (!$embed): ?>
<nav class="navbar">
    <?php if (!empty($_SESSION['impersonator_id'])): ?>
    <div style="background:#e67e22;color:#fff;font-size:13px;padding:5px 15px;display:flex;align-items:center;justify-content:center;gap:10px;">
        <span>🎭 模拟登录中：当前以 <b><?php echo htmlspecialchars($_SESSION['teacher_name'] ?? ''); ?></b> 的身份操作，所有操作视为其本人行为</span>
        <a href="impersonate.php?action=exit" onclick="return confirm('退出模拟并返回管理员身份？')" style="color:#fff;background:rgba(0,0,0,0.25);padding:2px 10px;border-radius:4px;text-decoration:none;">退出模拟</a>
    </div>
    <?php endif; ?>
    <div class="navbar-inner">
        <a class="navbar-brand" href="classes.php">潜记 · 作业登记</a>
        <div class="navbar-menu">
            <?php foreach ($menus as $it): ?>
            <a href="<?php echo $it[0]; ?>"<?php echo $active === $it[1] ? ' class="active"' : ''; ?>><?php echo $it[3]; ?></a>
            <?php endforeach; ?>
            <span style="color:rgba(255,255,255,0.6);font-size:13px;padding:0 5px;"><?php echo htmlspecialchars($teacher_name); ?></span>
            <a href="logout.php">退出</a>
        </div>
        <!-- 移动端：汉堡按钮 → 左侧抽屉菜单 -->
        <button type="button" class="nav-burger" onclick="toggleDrawer(true)" aria-label="打开菜单">☰</button>
    </div>
</nav>
<!-- 移动端左侧抽屉菜单 -->
<div class="drawer-mask" id="drawerMask" onclick="toggleDrawer(false)"></div>
<aside class="side-drawer" id="sideDrawer" aria-label="导航菜单">
    <div class="drawer-head">
        <b>潜记 · 作业登记</b>
        <button type="button" class="drawer-close" onclick="toggleDrawer(false)" aria-label="关闭菜单">✕</button>
    </div>
    <nav class="drawer-nav">
        <?php foreach ($menus as $it): ?>
        <a href="<?php echo $it[0]; ?>"<?php echo $active === $it[1] ? ' class="active"' : ''; ?>><?php echo $it[3]; ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="drawer-foot">
        <span>👤 <?php echo htmlspecialchars($teacher_name); ?></span>
        <a href="logout.php">退出登录</a>
    </div>
</aside>
<script>
function toggleDrawer(open) {
    var d = document.getElementById('sideDrawer');
    var m = document.getElementById('drawerMask');
    if (!d || !m) return;
    d.classList.toggle('open', open);
    m.classList.toggle('show', open);
}
</script>
<!-- 移动端底部导航栏（PC 隐藏，≤768px 显示）：随上下文变化（默认：班级列表/统计报表/个人中心；班级：班级情况/学生列表/班级统计/个人中心；项目：项目情况/扫码登记/项目统计/个人中心）。加入学校/后台管理仅 PC 显示 -->
<nav class="bottom-nav">
    <?php foreach ($menus as $it):
        if ($it[1] === 'join_school.php' || $it[1] === 'admin.php') continue; ?>
    <a class="bottom-nav-item<?php echo $active === $it[1] ? ' active' : ''; ?>" href="<?php echo $it[0]; ?>">
        <span class="bottom-nav-icon"><?php echo $it[2]; ?></span>
        <span class="bottom-nav-text"><?php echo $it[3]; ?></span>
    </a>
    <?php endforeach; ?>
</nav>
<?php endif; // !embed：导航/抽屉/底部栏结束 ?>
<div class="app-content">
<?php
    }
}

if (!function_exists('page_help')) {
    /**
     * 注册当前页面的操作帮助内容（page_footer 渲染右上角浮动 ? 按钮与帮助弹窗）
     * $sections: [['h' => '小节标题', 'items' => ['条目（可含 HTML）', ...]], ...]
     */
    function page_help($title, $sections) {
        $GLOBALS['PAGE_HELP'] = ['title' => $title, 'sections' => $sections];
    }
}

if (!function_exists('page_footer')) {
    function page_footer() {
        $help = (isset($GLOBALS['PAGE_HELP']) && intval($_GET['embed'] ?? 0) !== 1) ? $GLOBALS['PAGE_HELP'] : null; // 内嵌模式不放帮助按钮
?>
</div>
<?php if ($help): ?>
<style>
.help-fab { position: fixed; top: 70px; right: 16px; z-index: 1100; width: 38px; height: 38px;
    border-radius: 50%; border: none; background: #667eea; color: #fff; font-size: 20px; font-weight: bold;
    cursor: pointer; box-shadow: 0 2px 10px rgba(102,126,234,0.45); }
.help-fab:hover { background: #5568d3; }
.help-body h4 { margin: 14px 0 6px; font-size: 14px; color: #667eea; }
.help-body ul { margin: 0 0 8px; padding-left: 20px; }
.help-body li { font-size: 13px; color: #555; line-height: 1.9; }
</style>
<button type="button" class="help-fab" onclick="togglePageHelp(true)" title="操作帮助" aria-label="操作帮助">?</button>
<div class="modal-mask" id="pageHelpModal" onclick="if(event.target===this)togglePageHelp(false)">
    <div class="modal" style="max-width:640px;">
        <h3>❓ <?php echo htmlspecialchars($help['title']); ?> · 操作帮助</h3>
        <div class="help-body">
            <?php foreach ($help['sections'] as $sec): ?>
            <h4><?php echo htmlspecialchars($sec['h']); ?></h4>
            <ul>
                <?php foreach ($sec['items'] as $item): ?><li><?php echo $item; ?></li><?php endforeach; ?>
            </ul>
            <?php endforeach; ?>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn" onclick="togglePageHelp(false)">我知道了</button>
        </div>
    </div>
</div>
<?php endif; ?>
<style>
/* ===== 全局操作反馈 toast：页面下方中央、黑色（同 omr_scan.php）；3 秒自动消失，sticky=重要提示需点击才消失 ===== */
.toast-wrap { position:fixed; bottom:20px; left:50%; transform:translateX(-50%); z-index:3000; display:flex; flex-direction:column-reverse; gap:8px; align-items:center; max-width:92vw; pointer-events:none; }
.toast { pointer-events:auto; background:rgba(40,44,60,0.92); color:#fff; box-shadow:0 8px 24px rgba(0,0,0,0.28); border-radius:10px; padding:10px 16px; font-size:14px; line-height:1.6; max-width:92vw; word-break:break-all; cursor:pointer; animation:toastIn .22s ease; }
@keyframes toastIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
@media (max-width: 768px) { .toast-wrap { bottom:76px; } }
/* ===== 行内小问号按钮（点击在问号旁弹出说明浮层；与右上角大 ? 操作帮助并存） ===== */
.hint-q { display:inline-flex; align-items:center; justify-content:center; width:16px; height:16px; min-width:16px; border-radius:50%; background:#667eea; color:#fff; font-size:11px; font-weight:bold; line-height:1; border:none; cursor:pointer; vertical-align:middle; margin:0 0 2px 6px; padding:0; box-shadow:0 1px 4px rgba(102,126,234,0.4); font-family:inherit; }
.hint-q:hover { background:#5568d3; }
/* 小问号说明浮层：紧贴问号按钮（优先下方，放不下翻转到上方），点浮层外/再点同一个小问号收起 */
.hint-pop { position:fixed; z-index:4000; background:#fff; color:#333; border:1px solid #e3e6f0; border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,0.18); padding:12px 14px; font-size:13px; line-height:1.8; text-align:left; max-width:340px; max-height:70vh; overflow-y:auto; word-break:break-all; animation:toastIn .18s ease; }
.hint-pop b { color:#667eea; }
@media (max-width: 768px) { .hint-pop { max-width:92vw; } }
</style>
<script>
// ===== 全局操作反馈 toast：showToast(msg, type, sticky) 页面下方中央黑色（同 omr_scan.php）；sticky=true 重要提示需点击才消失 =====
function showToast(msg, type, sticky) {
    var wrap = document.querySelector('.toast-wrap');
    if (!wrap) { wrap = document.createElement('div'); wrap.className = 'toast-wrap'; document.body.appendChild(wrap); }
    var t = document.createElement('div');
    t.className = 'toast' + (type ? ' toast-' + type : '');
    t.innerHTML = msg;
    t.addEventListener('click', function () { dismiss(); });
    wrap.appendChild(t);
    var tm = null;
    function dismiss() { if (tm) clearTimeout(tm); if (t.parentNode) t.parentNode.removeChild(t); }
    if (!sticky) tm = setTimeout(dismiss, 3000);
    return t;
}
// 页面内 PHP 渲染的 .alert-success/.alert-error/.alert-danger 横幅自动转浮窗（error 需点击关闭；info/warning 常驻说明保留横幅）
(function () {
    document.querySelectorAll('.alert.alert-success, .alert.alert-error, .alert.alert-danger').forEach(function (el) {
        var type = el.classList.contains('alert-success') ? 'success' : 'error';
        showToast(el.innerHTML, type, type === 'error');
        el.style.display = 'none';
    });
})();
// ===== 行内小问号说明：toggleHint(event, 'HTML 说明文字') → 问号旁浮层（优先下方，放不下翻转到上方；点浮层外/再点同一个小问号收起） =====
function hintPopPos(p) {
    var b = p.__src; if (!b) return;
    var r = b.getBoundingClientRect();
    var pw = p.offsetWidth, ph = p.offsetHeight;
    var left = Math.max(8, Math.min(r.left + r.width / 2 - pw / 2, window.innerWidth - pw - 8));
    var top = r.bottom + 8;
    if (top + ph > window.innerHeight - 8) top = r.top - ph - 8;   // 下方放不下：翻转到上方
    p.style.left = left + 'px';
    p.style.top = Math.max(8, top) + 'px';
}
function hintPopClose() {
    var p = document.getElementById('hintPop');
    if (p && p.parentNode) p.parentNode.removeChild(p);
}
function toggleHint(ev, html) {
    ev.stopPropagation();
    var b = ev.currentTarget;
    var old = document.getElementById('hintPop');
    if (old && old.__src === b) { hintPopClose(); return; }   // 再点同一个小问号：收起
    hintPopClose();
    var p = document.createElement('div');
    p.id = 'hintPop';
    p.className = 'hint-pop';
    p.innerHTML = html;
    p.__src = b;
    p.addEventListener('click', function (e) { e.stopPropagation(); });
    document.body.appendChild(p);
    hintPopPos(p);
    window.addEventListener('scroll', hintPopSync, true);
    window.addEventListener('resize', hintPopSync);
}
// 滚动/缩放时跟随问号按钮重新定位；按钮已从 DOM 移除（如弹窗关闭）则连带收起
function hintPopSync() {
    var p = document.getElementById('hintPop');
    if (!p) return;
    if (!p.__src || !p.__src.isConnected) { hintPopClose(); return; }
    hintPopPos(p);
}
document.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('.hint-pop')) return;
    hintPopClose();
});
// ===== 全局「⚙ 管理 ▾」二级菜单开关（classes/projects/project_view 卡片与工具栏共用；展开时抬升所在卡片层级避免遮挡） =====
function toggleDD(e, btn) {
    e.stopPropagation();
    var dd = btn.closest('.admin-dd');
    if (!dd) return;
    var wasOpen = dd.classList.contains('open');
    document.querySelectorAll('.admin-dd.open').forEach(function (d) {
        d.classList.remove('open');
        var card = d.closest('.item-card');
        if (card) card.classList.remove('dd-top');
    });
    if (!wasOpen) {
        dd.classList.add('open');
        var card = dd.closest('.item-card');
        if (card) card.classList.add('dd-top');
    }
    btn.classList.toggle('active', dd.classList.contains('open'));   // project_view ⚙ 按钮高亮态
}
document.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('.pv-dd')) return;   // project_view ⚙ 视图工具条自管（内部点击不收起）
    document.querySelectorAll('.admin-dd.open').forEach(function (d) {
        d.classList.remove('open');
        var card = d.closest('.item-card');
        if (card) card.classList.remove('dd-top');
    });
});
</script>
<script>
function togglePageHelp(open) {
    var m = document.getElementById('pageHelpModal');
    if (m) m.classList.toggle('show', open);
}
// 三组选项卡切换（班级管理/项目列表：我的 / 授权 / 其他）
function switchCtxTab(btn, group, key) {
    btn.parentElement.querySelectorAll('.ctx-tab').forEach(function (b) { b.classList.remove('active'); });
    btn.classList.add('active');
    document.querySelectorAll('.ctx-tabpane[data-group="' + group + '"]').forEach(function (p) {
        p.style.display = (p.getAttribute('data-key') === key) ? '' : 'none';
    });
}
// 所有弹窗统一注入「最大化 / 还原」+「✕ 关闭」按钮（顺序：最大化 关闭；PC 自动宽高、手机 90%，均可一键撑满）
(function () {
    function initModalMaxBtns() {
        document.querySelectorAll('.modal-mask .modal').forEach(function (m) {
            if (m.querySelector('.modal-max-btn')) return;
            var max = document.createElement('button');
            max.type = 'button';
            max.className = 'modal-max-btn';
            max.title = '最大化 / 还原';
            max.innerHTML = '&#x2922;';
            max.addEventListener('click', function (ev) {
                ev.stopPropagation();
                var maxed = m.classList.toggle('modal-max');
                max.innerHTML = maxed ? '&#x2923;' : '&#x2922;';
            });
            m.appendChild(max);
            // ✕ 关闭按钮（位于最大化右侧）：关闭所属弹窗，还原最大化状态并广播 modalclosed
            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'modal-close-btn';
            close.title = '关闭';
            close.setAttribute('aria-label', '关闭');
            close.innerHTML = '&#x2715;';
            close.addEventListener('click', function (ev) {
                ev.stopPropagation();
                var mask = m.closest('.modal-mask');
                m.classList.remove('modal-max');
                max.innerHTML = '&#x2922;';
                if (mask) {
                    mask.classList.remove('show');
                    mask.dispatchEvent(new CustomEvent('modalclosed', { bubbles: false }));
                }
            });
            m.appendChild(close);
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initModalMaxBtns);
    } else {
        initModalMaxBtns();
    }
})();
</script>
</body>
</html>
<?php
    }
}
?>
