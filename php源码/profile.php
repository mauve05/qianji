<?php
/**
 * 个人中心（登录用户）
 *  - 修改姓名
 *  - 修改密码（需验证原密码）
 *  - 评价模式设置：系统模板仅可禁用/启用（不可修改删除），自定义模式可添加/修改/删除/排序
 */
require_once 'includes/db_config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/layout.php';

$conn = getConnection();
$teacher_id = $current_teacher_id;

// 当前账号信息
$stmt = mysqli_prepare($conn, "SELECT username, realname, phone FROM teachers WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $teacher_id);
mysqli_stmt_execute($stmt);
$me = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$me) {
    header("Location: logout.php");
    exit();
}

$msg = '';
$msg_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ===== 修改姓名 =====
    if ($action === 'change_name') {
        $realname = check_input($_POST['realname'] ?? '');
        if ($realname === '' || mb_strlen($realname) > 50) {
            $msg = '请输入姓名（不超过 50 字）';
            $msg_type = 'error';
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE teachers SET realname = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "si", $realname, $teacher_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION['teacher_name'] = $realname; // 同步导航栏显示
            $me['realname'] = $realname;
            $msg = '姓名已更新';
        }
    }
    // ===== 修改密码 =====
    elseif ($action === 'change_password') {
        $old_pass = $_POST['old_password'] ?? '';
        $new_pass = $_POST['password'] ?? '';
        $new_pass2 = $_POST['password2'] ?? '';
        // 验证原密码
        $stmt = mysqli_prepare($conn, "SELECT password FROM teachers WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $teacher_id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$row || !password_verify($old_pass, $row['password'])) {
            $msg = '原密码不正确';
            $msg_type = 'error';
        } elseif (mb_strlen($new_pass) < 6) {
            $msg = '新密码长度不能少于 6 位';
            $msg_type = 'error';
        } elseif ($new_pass !== $new_pass2) {
            $msg = '两次输入的新密码不一致';
            $msg_type = 'error';
        } else {
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, "UPDATE teachers SET password = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "si", $hash, $teacher_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = '密码已修改，下次登录请使用新密码';
        }
    }
    // ===== 评价模式：禁用/启用系统模板 =====
    elseif ($action === 'toggle_system_mode') {
        $key = strval($_POST['key'] ?? '');
        $disable = intval($_POST['disable'] ?? 1) === 1;
        set_eval_mode_disabled($conn, $teacher_id, $key, $disable);
        $msg = $disable ? '系统模板已禁用（新建项目下拉不再显示，已有项目不受影响）' : '系统模板已启用';
    }
    // ===== 评价模式：禁用/启用自定义模式 =====
    elseif ($action === 'toggle_custom_mode') {
        $cid = intval($_POST['mid'] ?? 0);
        $disable = intval($_POST['disable'] ?? 1) === 1;
        set_custom_mode_disabled($conn, $teacher_id, $cid, $disable);
        $msg = $disable ? '自定义模式已禁用（新建项目下拉不再显示，已有项目不受影响）' : '自定义模式已启用';
    }
    // ===== 评价模式：添加自定义模式 =====
    elseif ($action === 'add_custom_mode') {
        $mname = check_input($_POST['mname'] ?? '');
        $mopts = str_replace("\r\n", "\n", strval($_POST['moptions'] ?? ''));
        if ($mname === '' || mb_strlen($mname) > 20) {
            $msg = '请输入模式名称（不超过 20 字）';
            $msg_type = 'error';
        } else {
            $stmt = mysqli_prepare($conn, "SELECT COALESCE(MAX(sort), -1) + 1 AS ns FROM eval_modes WHERE teacher_id = ?");
            mysqli_stmt_bind_param($stmt, "i", $teacher_id);
            mysqli_stmt_execute($stmt);
            $ns = intval(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['ns']);
            mysqli_stmt_close($stmt);
            $stmt = mysqli_prepare($conn, "INSERT INTO eval_modes (teacher_id, name, options, sort, created_at) VALUES (?, ?, ?, ?, NOW())");
            mysqli_stmt_bind_param($stmt, "issi", $teacher_id, $mname, $mopts, $ns);
            if (mysqli_stmt_execute($stmt)) {
                $msg = '自定义评价模式已添加';
            } else {
                $msg = '自定义评价模式添加失败：' . mysqli_stmt_error($stmt);
                $msg_type = 'error';
            }
            mysqli_stmt_close($stmt);
        }
    }
    // ===== 评价模式：修改自定义模式 =====
    elseif ($action === 'update_custom_mode') {
        $mid = intval($_POST['mid'] ?? 0);
        $mname = check_input($_POST['mname'] ?? '');
        $mopts = str_replace("\r\n", "\n", strval($_POST['moptions'] ?? ''));
        if ($mname === '' || mb_strlen($mname) > 20) {
            $msg = '请输入模式名称（不超过 20 字）';
            $msg_type = 'error';
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE eval_modes SET name = ?, options = ? WHERE id = ? AND teacher_id = ?");
            mysqli_stmt_bind_param($stmt, "ssii", $mname, $mopts, $mid, $teacher_id);
            if (mysqli_stmt_execute($stmt)) {
                $msg = '自定义评价模式已更新';
            } else {
                $msg = '自定义评价模式更新失败：' . mysqli_stmt_error($stmt);
                $msg_type = 'error';
            }
            mysqli_stmt_close($stmt);
        }
    }
    // ===== 评价模式：删除自定义模式 =====
    elseif ($action === 'delete_custom_mode') {
        $mid = intval($_POST['mid'] ?? 0);
        $stmt = mysqli_prepare($conn, "DELETE FROM eval_modes WHERE id = ? AND teacher_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $mid, $teacher_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        set_custom_mode_disabled($conn, $teacher_id, $mid, false); // 顺带清理禁用列表中的残留 cid
        $msg = '自定义评价模式已删除（使用该模式的项目显示不受影响，仅不能再按该模式新增评价）';
    }
    // ===== 评价模式：自定义模式上移/下移 =====
    elseif ($action === 'move_custom_mode') {
        $mid = intval($_POST['mid'] ?? 0);
        $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
        // 归一化排序后与相邻项交换
        $list = [];
        $res = mysqli_query($conn, "SELECT id FROM eval_modes WHERE teacher_id = {$teacher_id} ORDER BY sort ASC, id ASC");
        while ($row = mysqli_fetch_assoc($res)) $list[] = intval($row['id']);
        $idx = array_search($mid, $list, true);
        if ($idx !== false) {
            $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
            if ($swap >= 0 && $swap < count($list)) {
                $tmp = $list[$idx]; $list[$idx] = $list[$swap]; $list[$swap] = $tmp;
            }
            foreach ($list as $i => $id2) {
                mysqli_query($conn, "UPDATE eval_modes SET sort = {$i} WHERE id = " . intval($id2) . " AND teacher_id = {$teacher_id}");
            }
            $msg = '排序已调整';
        }
    }
}

// 评价模式数据（系统模板 + 我的自定义）
$system_modes = get_eval_modes();
$disabled_keys = get_disabled_eval_modes($conn, $teacher_id);
$custom_modes = get_custom_eval_modes($conn, $teacher_id);

// 评价模式相关操作提交后：页面重载时自动展开评价模式区（否则折叠状态下看不到状态变化）
$pf_expand = in_array($action ?? '', ['toggle_system_mode', 'toggle_custom_mode', 'add_custom_mode', 'update_custom_mode', 'delete_custom_mode', 'move_custom_mode'], true);

// 我的使用概览（全部用确定存在的表/列，不做额外假设）
$pf_st_cls = intval(mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM classes WHERE teacher_id = {$teacher_id} AND deleted_at IS NULL"))[0]);
$pf_st_prj = intval(mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM projects WHERE created_by = {$teacher_id} AND deleted_at IS NULL"))[0]);
$pf_st_mem = intval(mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM class_members WHERE teacher_id = {$teacher_id} AND status = 'approved'"))[0]);
$pf_joined = mysqli_fetch_row(mysqli_query($conn, "SELECT created_at FROM teachers WHERE id = {$teacher_id}"))[0] ?? '';

// 使用频次统计卡（学生 / 喊话 / 积分发出 / 登录天数 / 累计登录）
$pf_st_stu = intval(mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM students WHERE class_id IN (
    SELECT id FROM classes WHERE teacher_id = {$teacher_id} AND deleted_at IS NULL
    UNION SELECT class_id FROM class_members WHERE teacher_id = {$teacher_id} AND status = 'approved')"))[0]);
$pf_st_ann = intval(mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM announce_msgs WHERE sender_id = {$teacher_id} AND direction = 't2c'"))[0]);
$pf_pts_row = mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(value),0), COUNT(*) FROM points_log WHERE created_by = {$teacher_id} AND value > 0"));
$pf_st_pts = intval($pf_pts_row[0]); $pf_st_ptsn = intval($pf_pts_row[1]);
$pf_ld_row = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*), COALESCE(SUM(cnt),0) FROM login_days WHERE teacher_id = {$teacher_id}"));
$pf_st_ldays = intval($pf_ld_row[0]); $pf_st_lcnt = intval($pf_ld_row[1]);

page_header('个人中心', 'profile.php');
?>
<style>
    .pf-hero { display:flex; align-items:center; gap:16px; flex-wrap:wrap; background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);
        border-radius:16px; padding:18px 22px; color:#fff; margin-bottom:20px; box-shadow:0 8px 24px rgba(102,126,234,.28); }
    .pf-avatar { width:54px; height:54px; border-radius:50%; background:rgba(255,255,255,.22); border:2px solid rgba(255,255,255,.55);
        display:flex; align-items:center; justify-content:center; font-size:24px; font-weight:bold; flex:none; }
    .pf-name { font-size:20px; font-weight:bold; line-height:1.3; }
    .pf-sub { font-size:13px; opacity:.92; margin-top:2px; }
    .pf-btns { margin-left:auto; display:flex; gap:8px; flex-wrap:wrap; }
    .pf-btns button { background:rgba(255,255,255,.18); border:1px solid rgba(255,255,255,.45); color:#fff; border-radius:999px;
        padding:7px 16px; font-size:13px; cursor:pointer; transition:background .15s; }
    .pf-btns button:hover { background:rgba(255,255,255,.3); }
    .pf-sec { display:flex; align-items:center; gap:8px; margin:18px 0 8px; }
    .pf-sec b { font-size:15px; }
    .pf-sec .pf-line { flex:1; height:1px; background:#e8eaf2; }
    .pf-badge { display:inline-block; background:#eef1fd; color:#5a67d8; border-radius:999px; padding:2px 10px;
        font-size:12.5px; margin:2px 3px 2px 0; border:1px solid #dfe4fb; }
    .pf-pill { display:inline-block; border-radius:999px; padding:3px 12px; font-size:12.5px; font-weight:bold; }
    .pf-pill.on { background:#e6f7ee; color:#27ae60; }
    .pf-pill.off { background:#fdecea; color:#e74c3c; }
    .pf-free { display:inline-block; background:#f4f5f9; color:#98a0b3; border-radius:999px; padding:2px 10px; font-size:12.5px; }
    .pf-acts { display:flex; gap:4px; flex-wrap:wrap; }
    .pf-mono { font-family:Consolas,Menlo,monospace; letter-spacing:.5px; }
    .pf-stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:20px; }
    .pf-stat { background:#fff; border:1px solid #eceef6; border-radius:14px; padding:14px 16px; display:flex; flex-direction:column; gap:2px;
        box-shadow:0 2px 8px rgba(102,126,234,.06); }
    .pf-stat b { font-size:24px; color:#667eea; line-height:1.2; }
    .pf-stat span { font-size:12.5px; color:#98a0b3; }
    .pf-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
    .pf-mode-card { border:1px solid #eceef6; border-radius:12px; padding:12px 14px; background:#fbfbfe; }
    .pf-mode-card.off { opacity:.55; background:#f6f6f8; }
    .pf-mc-head { display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:6px; }
    .pf-mc-head b { font-size:14.5px; }
    .pf-mc-order { font-size:12px; color:#98a0b3; }
    .pf-mc-opts { margin-bottom:9px; line-height:1.9; }
    .pf-mc-foot { display:flex; gap:4px; flex-wrap:wrap; align-items:center; }
    .pf-mode-empty { grid-column:1 / -1; text-align:center; color:#98a0b3; padding:22px 10px; border:1px dashed #dfe2ec; border-radius:12px; background:#fbfbfe; }
    .pf-mask { display:none; position:fixed; inset:0; background:rgba(30,34,60,.45); z-index:2000; align-items:center; justify-content:center; padding:16px; }
    .pf-modal { background:#fff; border-radius:14px; width:400px; max-width:100%; max-height:86vh; overflow:auto; padding:20px 22px;
        box-shadow:0 16px 48px rgba(0,0,0,.25); }
    .pf-mhead { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
    .pf-mhead b { font-size:16px; }
    .pf-x { cursor:pointer; color:#98a0b3; font-size:18px; line-height:1; padding:4px 6px; border-radius:8px; }
    .pf-x:hover { background:#f2f3f8; color:#555; }
    @media (max-width:640px) {
        .pf-hero { padding:14px 16px; gap:12px; border-radius:14px; }
        .pf-avatar { width:46px; height:46px; font-size:20px; }
        .pf-name { font-size:18px; }
        .pf-btns { margin-left:0; width:100%; }
        .pf-btns button { flex:1; padding:9px 10px; }
        .pf-modal { padding:16px; border-radius:12px; }
        .pf-sec { margin:16px 0 6px; }
        .pf-stats { grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; margin-bottom:16px; }
        .pf-grid { grid-template-columns:1fr; }
    }
</style>
<a href="classes.php" style="display:inline-block;margin-bottom:15px;color:#667eea;text-decoration:none;">← 返回首页</a>

<?php if ($msg): ?><div class="alert alert-<?php echo $msg_type; ?>"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

<?php
$pf_acct = htmlspecialchars(strval($me['phone'] !== '' ? $me['phone'] : $me['username']));
$pf_name = $me['realname'] !== '' ? $me['realname'] : '未填写姓名';
$pf_initial = function_exists('mb_substr') && $me['realname'] !== '' ? mb_substr($me['realname'], 0, 1) : '师';
?>
<div class="pf-hero">
    <div class="pf-avatar"><?php echo htmlspecialchars($pf_initial); ?></div>
    <div>
        <div class="pf-name"><?php echo htmlspecialchars($pf_name); ?></div>
        <div class="pf-sub">📱 <span class="pf-mono"><?php echo $pf_acct; ?></span> · 手机号即登录账号<?php echo $pf_joined ? ' · 📅 ' . htmlspecialchars(substr(strval($pf_joined), 0, 10)) . ' 加入' : ''; ?></div>
    </div>
    <div class="pf-btns">
        <button type="button" onclick="pfOpen('mdName')">✏️ 修改姓名</button>
        <button type="button" onclick="pfOpen('mdPass')">🔐 修改密码</button>
    </div>
</div>

<!-- 我的使用概览 -->
<div class="pf-stats">
    <div class="pf-stat"><b><?php echo $pf_st_cls; ?></b><span>🏫 我创建的班级</span></div>
    <div class="pf-stat"><b><?php echo $pf_st_prj; ?></b><span>📋 我创建的项目</span></div>
    <div class="pf-stat"><b><?php echo $pf_st_mem; ?></b><span>👥 我加入的班级</span></div>
    <div class="pf-stat"><b><?php echo count($custom_modes); ?></b><span>🎯 自定义评价模式</span></div>
    <div class="pf-stat"><b><?php echo $pf_st_stu; ?></b><span>👨‍🎓 我的学生</span></div>
    <div class="pf-stat"><b><?php echo $pf_st_ann; ?></b><span>📣 喊话次数</span></div>
    <div class="pf-stat"><b><?php echo $pf_st_pts; ?></b><span>💰 积分发出（<?php echo $pf_st_ptsn; ?> 次）</span></div>
    <!--
    <div class="pf-stat"><b><?php echo $pf_st_ldays; ?></b><span>📅 登录天数</span></div>
    -->
    <div class="pf-stat"><b><?php echo $pf_st_lcnt; ?></b><span>🔐 累计登录（次）</span></div>
</div>

<!-- 评价模式设置 -->
<div class="panel">
    <h3 style="display:flex;align-items:center;gap:6px;">
        <span style="cursor:pointer;user-select:none;" onclick="pfToggleEval()">🎯 评价模式设置<span id="pfEvalArrow" style="font-size:13px;color:#999;margin-left:6px;">▸</span></span>
        <span style="margin-left:auto;">
            <button type="button" class="btn btn-sm" onclick="openModeModal()">➕ 新建评价</button>
        </span>
    </h3>
    <div id="pfEvalBody" style="display:none;">

        <div class="pf-sec" style="margin-top:6px;"><b>系统评价模板</b><span class="pf-line"></span><span style="font-size:12.5px;color:#98a0b3;">不可修改删除，可按需禁用</span></div>
        <div class="pf-grid">
            <?php foreach ($system_modes as $key => $m): $dis = in_array($key, $disabled_keys, true); ?>
            <div class="pf-mode-card<?php echo $dis ? ' off' : ''; ?>">
                <div class="pf-mc-head"><b><?php echo htmlspecialchars($m['name']); ?></b><span class="pf-pill <?php echo $dis ? 'off' : 'on'; ?>"><?php echo $dis ? '已禁用' : '启用中'; ?></span></div>
                <div class="pf-mc-opts"><?php
                    if ($m['options'] === null) echo '<span class="pf-free">自由输入</span>';
                    else foreach (array_keys($m['options']) as $opt) echo '<span class="pf-badge">' . htmlspecialchars($opt) . '</span>';
                ?></div>
                <div class="pf-mc-foot">
                    <button type="button" class="btn btn-sm btn-outline" data-name="<?php echo htmlspecialchars($m['name']); ?>" data-opts="<?php echo $m['options'] === null ? '' : htmlspecialchars(json_encode($m['options'], JSON_UNESCAPED_UNICODE)); ?>" data-free="<?php echo $key === 'score' ? '输入0-100的分数' : '输入评语'; ?>" onclick="openPrev(this)">👁 预览</button>
                    <form method="post" style="margin:0;" autocomplete="off">
                        <input type="hidden" name="action" value="toggle_system_mode" autocomplete="off">
                        <input type="hidden" name="key" value="<?php echo htmlspecialchars($key); ?>" autocomplete="off">
                        <input type="hidden" name="disable" value="<?php echo $dis ? '0' : '1'; ?>" autocomplete="off">
                        <button type="submit" class="btn btn-sm btn-outline"><?php echo $dis ? '启用' : '禁用'; ?></button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="pf-sec" style="margin-top:22px;"><b>我的评价模式</b><span class="pf-line"></span><span style="font-size:12.5px;color:#98a0b3;">在新建项目下拉中按此顺序显示</span></div>
        <?php $disabled_cids = get_disabled_custom_modes($conn, $teacher_id); ?>
        <div class="pf-grid">
            <?php if (empty($custom_modes)): ?>
            <div class="pf-mode-empty">✨ 暂无自定义模式，点右上「➕ 新建评价」添加</div>
            <?php else: $pf_order = 0; foreach ($custom_modes as $key => $m): $pf_order++; $cm_name = preg_replace('/（自定义）$/u', '', $m['name']); $cm_opts = $m['options'] === null ? '' : implode("\n", $m['options']); $cdis = in_array(strval($m['cid']), $disabled_cids, true); ?>
            <div class="pf-mode-card<?php echo $cdis ? ' off' : ''; ?>">
                <div class="pf-mc-head"><b><?php echo htmlspecialchars($cm_name); ?></b><span class="pf-mc-order">#<?php echo $pf_order; ?></span><span class="pf-pill <?php echo $cdis ? 'off' : 'on'; ?>"><?php echo $cdis ? '已禁用' : '启用中'; ?></span></div>
                <div class="pf-mc-opts"><?php
                    if ($m['options'] === null) echo '<span class="pf-free">自由输入</span>';
                    else foreach ($m['options'] as $opt) echo '<span class="pf-badge">' . htmlspecialchars($opt) . '</span>';
                ?></div>
                <div class="pf-mc-foot">
                    <button type="button" class="btn btn-sm btn-outline" data-name="<?php echo htmlspecialchars($cm_name); ?>" data-opts="<?php echo $m['options'] === null ? '' : htmlspecialchars(json_encode($m['options'], JSON_UNESCAPED_UNICODE)); ?>" data-free="输入评价内容" onclick="openPrev(this)">👁 预览</button>
                    <form method="post" style="margin:0;" autocomplete="off"><input type="hidden" name="action" value="move_custom_mode" autocomplete="off"><input type="hidden" name="mid" value="<?php echo $m['cid']; ?>" autocomplete="off"><input type="hidden" name="dir" value="up" autocomplete="off"><button type="submit" class="btn btn-sm btn-outline" title="上移">↑</button></form>
                    <form method="post" style="margin:0;" autocomplete="off"><input type="hidden" name="action" value="move_custom_mode" autocomplete="off"><input type="hidden" name="mid" value="<?php echo $m['cid']; ?>" autocomplete="off"><input type="hidden" name="dir" value="down" autocomplete="off"><button type="submit" class="btn btn-sm btn-outline" title="下移">↓</button></form>
                    <button type="button" class="btn btn-sm btn-outline" data-cid="<?php echo $m['cid']; ?>" data-name="<?php echo htmlspecialchars($cm_name); ?>" data-opts="<?php echo htmlspecialchars($cm_opts); ?>" onclick="editMode(this)">✏️ 编辑</button>
                    <form method="post" style="margin:0;" autocomplete="off">
                        <input type="hidden" name="action" value="toggle_custom_mode" autocomplete="off">
                        <input type="hidden" name="mid" value="<?php echo $m['cid']; ?>" autocomplete="off">
                        <input type="hidden" name="disable" value="<?php echo $cdis ? '0' : '1'; ?>" autocomplete="off">
                        <button type="submit" class="btn btn-sm btn-outline"><?php echo $cdis ? '启用' : '禁用'; ?></button>
                    </form>
                    <form method="post" style="margin:0;" onsubmit="return confirm('确定删除该自定义评价模式？')" autocomplete="off"><input type="hidden" name="action" value="delete_custom_mode" autocomplete="off"><input type="hidden" name="mid" value="<?php echo $m['cid']; ?>" autocomplete="off"><button type="submit" class="btn btn-sm btn-outline" style="color:#e74c3c;">删除</button></form>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 弹窗：修改姓名 -->
<div class="pf-mask" id="mdName" onclick="if(event.target===this)pfClose('mdName')">
    <div class="pf-modal">
        <div class="pf-mhead"><b>✏️ 修改姓名</b><span class="pf-x" onclick="pfClose('mdName')">✕</span></div>
        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="change_name" autocomplete="off">
            <div class="form-group">
                <label for="realname">新姓名（显示在顶部导航栏和登记记录中）</label>
                <input type="text" id="realname" name="realname" class="form-control" maxlength="50"
                       placeholder="不超过 50 字" value="<?php echo htmlspecialchars($me['realname']); ?>" required autocomplete="off">
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" class="btn btn-outline" onclick="pfClose('mdName')">取消</button>
                <button type="submit" class="btn">保存姓名</button>
            </div>
        </form>
    </div>
</div>

<!-- 弹窗：修改密码 -->
<div class="pf-mask" id="mdPass" onclick="if(event.target===this)pfClose('mdPass')">
    <div class="pf-modal">
        <div class="pf-mhead"><b>🔐 修改密码</b><span class="pf-x" onclick="pfClose('mdPass')">✕</span></div>
        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="change_password" autocomplete="off">
            <div class="form-group">
                <label for="old_password">原密码</label>
                <input type="password" id="old_password" name="old_password" class="form-control" required autocomplete="off">
            </div>
            <div class="form-group">
                <label for="password">新密码（不少于 6 位）</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="不少于6位" required autocomplete="off">
            </div>
            <div class="form-group">
                <label for="password2">确认新密码</label>
                <input type="password" id="password2" name="password2" class="form-control" placeholder="再次输入新密码" required autocomplete="off">
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" class="btn btn-outline" onclick="pfClose('mdPass')">取消</button>
                <button type="submit" class="btn">修改密码</button>
            </div>
        </form>
    </div>
</div>

<!-- 弹窗：新建 / 编辑自定义评价模式 -->
<div class="pf-mask" id="mdMode" onclick="if(event.target===this)pfClose('mdMode')">
    <div class="pf-modal">
        <div class="pf-mhead"><b id="mode_title">添加自定义模式</b><span class="pf-x" onclick="pfModeCancel()">✕</span></div>
        <form method="post" id="modeForm" autocomplete="off">
            <input type="hidden" name="action" id="mode_action" value="add_custom_mode" autocomplete="off">
            <input type="hidden" name="mid" id="mode_mid" value="0" autocomplete="off">
            <div class="form-group">
                <label for="mode_name">模式名称</label>
                <input type="text" name="mname" id="mode_name" class="form-control" maxlength="20" placeholder="如：课堂表现（不超过 20 字）" required autocomplete="off">
            </div>
            <div class="form-group">
                <label for="mode_opts">评价选项（每行一个；留空 = 自由输入）</label>
                <textarea name="moptions" id="mode_opts" class="form-control" rows="4" placeholder="例如：&#10;👍 好&#10;👌 一般&#10;👎 加油"></textarea>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" class="btn btn-outline" id="mode_reset" onclick="pfModeCancel()">取消</button>
                <button type="submit" class="btn" id="mode_submit">添加模式</button>
            </div>
        </form>
    </div>
</div>

<!-- 弹窗：评价效果预览（复刻 project_view.php evalModal 的结构与交互：同款 modal-mask/modal/eval-options/modal-actions） -->
<div class="modal-mask" id="mdPrev">
    <div class="modal">
        <h3>评价 - <span id="pv_name"></span> <span style="font-size:12px;color:#98a0b3;">（预览，不保存）</span></h3>
        <div id="pv_options" class="eval-options"></div>
        <div class="form-group" id="pv_input_group" style="display:none;">
            <input type="text" id="pv_input" class="form-control" placeholder="输入评价内容" autocomplete="off">
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-outline" onclick="pvClose()">取消</button>
            <button type="button" class="btn" onclick="pvSubmit()">确定</button>
        </div>
    </div>
</div>

<script>
// ===== 弹窗基础开关 =====
function pfOpen(id) { document.getElementById(id).style.display = 'flex'; }
function pfClose(id) { document.getElementById(id).style.display = 'none'; }
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        ['mdName', 'mdPass', 'mdMode'].forEach(function (id) { pfClose(id); });
        pvClose();
    }
});

// ===== 评价效果预览（复刻 project_view.php 的 buildEvalOptions / openEval / submitEval 交互） =====
var PV_OPTS = null;   // 当前预览模式的选项（assoc 键=>值）；null = 自由输入
function openPrev(btn) {
    var name = btn.dataset.name;
    PV_OPTS = btn.dataset.opts ? JSON.parse(btn.dataset.opts) : null;
    document.getElementById('pv_name').textContent = name;
    var box = document.getElementById('pv_options');
    var ig = document.getElementById('pv_input_group');
    box.innerHTML = '';
    if (PV_OPTS) {
        ig.style.display = 'none';
        Object.keys(PV_OPTS).forEach(function (k) {
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = PV_OPTS[k];   // 与登记页一致：按钮文本 = 选项值（键仅作提交值）
            b.onclick = function () {
                box.querySelectorAll('button').forEach(function (x) { x.classList.remove('sel'); });
                b.classList.add('sel');
            };
            box.appendChild(b);
        });
    } else {
        ig.style.display = 'block';
        document.getElementById('pv_input').value = '';
        document.getElementById('pv_input').placeholder = btn.dataset.free || '输入评价内容';
    }
    document.getElementById('mdPrev').classList.add('show');
}
function pvClose() { document.getElementById('mdPrev').classList.remove('show'); }
function pvSubmit() {
    var v = '';
    if (PV_OPTS) {
        var sel = document.querySelector('#pv_options button.sel');
        v = sel ? sel.textContent : '';
    } else {
        v = document.getElementById('pv_input').value.trim();
    }
    if (v === '') { showToast('请先选择或输入评价内容', 'warn'); return; }
    showToast('「' + v + '」预览完成，仅预览不会保存', 'info');
    pvClose();
}

// ===== 评价模式设置折叠 =====
var PF_AUTO_EXPAND = <?php echo !empty($pf_expand) ? 'true' : 'false'; ?>;   // 提交过评价模式相关操作：重载后自动展开，让用户看到状态更新
function pfToggleEval() {
    var b = document.getElementById('pfEvalBody');
    var c = b.style.display === 'none';
    b.style.display = c ? '' : 'none';
    document.getElementById('pfEvalArrow').textContent = c ? '▾' : '▸';
}
if (PF_AUTO_EXPAND) pfToggleEval();

// ===== 新建自定义模式（弹窗）=====
function openModeModal() {
    document.getElementById('mode_action').value = 'add_custom_mode';
    document.getElementById('mode_mid').value = '0';
    document.getElementById('modeForm').reset();
    document.getElementById('mode_title').textContent = '添加自定义模式';
    document.getElementById('mode_submit').textContent = '添加模式';
    pfOpen('mdMode');
    setTimeout(function () { document.getElementById('mode_name').focus(); }, 60);
}

// ===== 编辑自定义模式（弹窗）：数据来自 data-* 属性，避免依赖表格 textContent =====
function editMode(btn) {
    var d = btn.dataset;
    document.getElementById('mode_action').value = 'update_custom_mode';
    document.getElementById('mode_mid').value = d.cid;
    document.getElementById('mode_name').value = d.name;
    document.getElementById('mode_opts').value = d.opts;
    document.getElementById('mode_title').textContent = '编辑自定义模式';
    document.getElementById('mode_submit').textContent = '保存修改';
    pfOpen('mdMode');
    setTimeout(function () { document.getElementById('mode_name').focus(); }, 60);
}

// 取消编辑 / 关闭模式弹窗：重置回添加模式
function pfModeCancel() {
    pfClose('mdMode');
    document.getElementById('mode_action').value = 'add_custom_mode';
    document.getElementById('mode_mid').value = '0';
    document.getElementById('modeForm').reset();
    document.getElementById('mode_title').textContent = '添加自定义模式';
    document.getElementById('mode_submit').textContent = '添加模式';
}
</script>
<?php page_help('个人中心', [
    ['h' => '账号信息与安全', 'items' => [
        '手机号即登录账号，如需修改请联系管理员',
        '「✏️ 修改姓名」「🔐 修改密码」按钮在顶部卡片中，点击弹出窗口填写（Esc 或点遮罩可关闭）',
        '修改姓名后显示在顶部导航栏和登记记录中',
        '修改密码需先验证原密码；新密码不少于 6 位，下次登录请使用新密码',
    ]],
    ['h' => '评价模式设置', 'items' => [
        '本区块默认折叠，点击标题展开 / 收起',
        '「➕ 新建评价」弹出窗口填写；已添加的模式点「✏️ 编辑」同样弹窗修改',
        '每个模式卡上的「👁 预览」弹窗展示该模式在登记页面的呈现（同款弹窗与交互，可点击体验，不保存）',
        '<b>系统评价模板</b>：不可修改删除，可按需「禁用」（禁用后新建项目不再显示该模式，已有项目照常使用）',
        '<b>我的评价模式</b>：可添加、编辑、删除、排序（↑↓ 调整在新建项目下拉中的顺序），也可按需「禁用」/「启用」',
        '评价选项每行一个；留空 = 自由输入（教师手动填写评价内容）',
        '删除自定义模式不影响已使用该模式的项目，仅不能再按该模式新增评价',
    ]],
]);
page_footer(); ?>
