<?php
/**
 * 后台管理（单用户版，仅管理员）
 *  - 用户管理：添加 / 编辑（手机号、姓名、密码）/ 禁用启用 / 删除 / 模拟登录
 *  - 注册开关：允许教师自主注册
 */
require_once 'includes/db_config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/layout.php';

$conn = getConnection();

if (!is_super_admin($conn, $current_teacher_id)) {
    page_header('无权限');
    echo '<div class="panel"><div class="alert alert-error">仅管理员可访问后台管理</div></div>';
    page_footer();
    exit();
}

$msg = '';
$msg_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ===== 注册开关 =====
    if ($action === 'save_register') {
        $allow = isset($_POST['allow_register']) ? '1' : '0';
        set_setting($conn, 'allow_register', $allow, 0);
        $msg = $allow === '1' ? '已允许教师自主注册' : '已关闭教师自主注册';
    }
    // ===== 添加用户 =====
    elseif ($action === 'add_user') {
        $phone = check_input($_POST['phone'] ?? '');
        $realname = check_input($_POST['realname'] ?? '');
        $password = $_POST['password'] ?? '';
        if (!preg_match('/^[0-9+\-]{5,20}$/', $phone)) {
            $msg = '请输入正确的手机号（5-20位数字）';
            $msg_type = 'error';
        } elseif (mb_strlen($realname) > 50) {
            $msg = '姓名过长（不超过 50 字）';
            $msg_type = 'error';
        } elseif (mb_strlen($password) < 6) {
            $msg = '密码长度不能少于 6 位';
            $msg_type = 'error';
        } else {
            $stmt = mysqli_prepare($conn, "SELECT id FROM teachers WHERE phone = ? AND phone != ''");
            mysqli_stmt_bind_param($stmt, "s", $phone);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
            $dup = mysqli_stmt_num_rows($stmt) > 0;
            mysqli_stmt_close($stmt);
            if ($dup) {
                $msg = '该手机号已被其他账号使用';
                $msg_type = 'error';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                // 手机号即登录账号（username 与 phone 保持一致）
                $stmt = mysqli_prepare($conn, "INSERT INTO teachers (username, password, realname, school_id, phone, is_admin, created_at) VALUES (?, ?, ?, 1, ?, 0, NOW())");
                mysqli_stmt_bind_param($stmt, "ssss", $phone, $hash, $realname, $phone);
                if (mysqli_stmt_execute($stmt)) {
                    $msg = '用户「' . ($realname !== '' ? $realname : $phone) . '」已添加';
                } else {
                    $msg = '添加失败，请稍后重试';
                    $msg_type = 'error';
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
    // ===== 编辑用户（手机号/姓名/密码） =====
    elseif ($action === 'edit_teacher') {
        $tid = intval($_POST['teacher_id'] ?? 0);
        $stmt = mysqli_prepare($conn, "SELECT * FROM teachers WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $tid);
        mysqli_stmt_execute($stmt);
        $target = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        // 管理员不可编辑其他管理员账号
        if (!$target || (intval($target['is_admin']) === 1 && $tid !== $current_teacher_id)) {
            $msg = '账号不存在或无权限编辑';
            $msg_type = 'error';
        } else {
            $new_phone = check_input($_POST['phone'] ?? '');
            $new_name = check_input($_POST['realname'] ?? '');
            $new_pass = $_POST['password'] ?? '';
            if (!preg_match('/^[0-9+\-]{5,20}$/', $new_phone)) {
                $msg = '请输入正确的手机号（5-20位数字）';
                $msg_type = 'error';
            } elseif (mb_strlen($new_name) > 50) {
                $msg = '姓名过长（不超过 50 字）';
                $msg_type = 'error';
            } elseif ($new_pass !== '' && mb_strlen($new_pass) < 6) {
                $msg = '密码长度不能少于 6 位（留空表示不修改密码）';
                $msg_type = 'error';
            } else {
                // 手机号唯一性校验（排除本人）
                $stmt = mysqli_prepare($conn, "SELECT id FROM teachers WHERE phone = ? AND id != ?");
                mysqli_stmt_bind_param($stmt, "si", $new_phone, $tid);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_store_result($stmt);
                $dup = mysqli_stmt_num_rows($stmt) > 0;
                mysqli_stmt_close($stmt);
                if ($dup) {
                    $msg = '该手机号已被其他账号使用';
                    $msg_type = 'error';
                } else {
                    if ($new_pass !== '') {
                        $hash = password_hash($new_pass, PASSWORD_DEFAULT);
                        $stmt = mysqli_prepare($conn, "UPDATE teachers SET username = ?, phone = ?, realname = ?, password = ? WHERE id = ?");
                        mysqli_stmt_bind_param($stmt, "ssssi", $new_phone, $new_phone, $new_name, $hash, $tid);
                    } else {
                        $stmt = mysqli_prepare($conn, "UPDATE teachers SET username = ?, phone = ?, realname = ? WHERE id = ?");
                        mysqli_stmt_bind_param($stmt, "sssi", $new_phone, $new_phone, $new_name, $tid);
                    }
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                    // 编辑自身时同步会话显示名
                    if ($tid === $current_teacher_id) $_SESSION['teacher_name'] = $new_name;
                    $msg = '账号信息已更新';
                }
            }
        }
    }
    // ===== 禁用/启用账号 =====
    elseif ($action === 'user_disable') {
        $tid = intval($_POST['user_id'] ?? 0);
        $stmt = mysqli_prepare($conn, "SELECT is_admin FROM teachers WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $tid);
        mysqli_stmt_execute($stmt);
        $target = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$target || $tid === $current_teacher_id || intval($target['is_admin']) === 1) {
            $msg = '不能禁用自己或其他管理员账号';
            $msg_type = 'error';
        } else {
            mysqli_query($conn, "UPDATE teachers SET disabled = 1 WHERE id = {$tid}");
            $msg = '账号已禁用，该教师将无法登录';
        }
    }
    elseif ($action === 'user_enable') {
        $tid = intval($_POST['user_id'] ?? 0);
        mysqli_query($conn, "UPDATE teachers SET disabled = 0 WHERE id = {$tid}");
        $msg = '账号已启用';
    }
    // ===== 删除账号（连同其班级/学生/项目/登记等全部数据） =====
    elseif ($action === 'user_delete') {
        $tid = intval($_POST['user_id'] ?? 0);
        $stmt = mysqli_prepare($conn, "SELECT is_admin FROM teachers WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $tid);
        mysqli_stmt_execute($stmt);
        $target = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$target || $tid === $current_teacher_id || intval($target['is_admin']) === 1) {
            $msg = '不能删除自己或其他管理员账号';
            $msg_type = 'error';
        } else {
            // 其名下班级及全部数据
            $r = mysqli_query($conn, "SELECT id FROM classes WHERE teacher_id = {$tid}");
            $del_class_ids = [];
            while ($row = mysqli_fetch_assoc($r)) $del_class_ids[] = intval($row['id']);
            // 其创建的跨班项目（一并清理）
            $r2 = mysqli_query($conn, "SELECT id FROM projects WHERE created_by = {$tid}");
            $del_project_ids = [];
            while ($row = mysqli_fetch_assoc($r2)) $del_project_ids[] = intval($row['id']);
            $del_project_all = array_merge($del_project_ids);
            foreach ($del_class_ids as $cid) {
                $pid_res = mysqli_query($conn, "SELECT id FROM projects WHERE class_id = {$cid}");
                while ($row = mysqli_fetch_assoc($pid_res)) $del_project_all[] = intval($row['id']);
            }
            $del_project_all = array_values(array_unique($del_project_all));
            // 项目级数据
            if ($del_project_all) {
                $pid_in = implode(',', $del_project_all);
                mysqli_query($conn, "DELETE FROM records WHERE project_id IN ({$pid_in})");
                mysqli_query($conn, "DELETE FROM record_rounds WHERE project_id IN ({$pid_in})");
                mysqli_query($conn, "DELETE FROM record_days WHERE project_id IN ({$pid_in})");
                mysqli_query($conn, "DELETE FROM student_comments WHERE project_id IN ({$pid_in})");
                mysqli_query($conn, "DELETE FROM project_rounds WHERE project_id IN ({$pid_in})");
                mysqli_query($conn, "DELETE FROM omr_results WHERE project_id IN ({$pid_in})");
                mysqli_query($conn, "DELETE FROM omr_templates WHERE project_id IN ({$pid_in})");
                mysqli_query($conn, "DELETE FROM omr_answers WHERE project_id IN ({$pid_in})");
                mysqli_query($conn, "DELETE FROM omr_images WHERE project_id IN ({$pid_in})");
                mysqli_query($conn, "DELETE FROM points_rules WHERE project_id IN ({$pid_in})");
                mysqli_query($conn, "DELETE FROM projects WHERE id IN ({$pid_in})");
            }
            foreach ($del_class_ids as $cid) {
                mysqli_query($conn, "DELETE rd FROM record_days rd INNER JOIN students s ON rd.student_id = s.id WHERE s.class_id = {$cid}");
                mysqli_query($conn, "DELETE FROM points_log WHERE class_id = {$cid}");
                mysqli_query($conn, "DELETE FROM points_gifts WHERE class_id = {$cid}");
                mysqli_query($conn, "DELETE FROM students WHERE class_id = {$cid}");
                mysqli_query($conn, "DELETE FROM stu_groups WHERE class_id = {$cid}");
                mysqli_query($conn, "DELETE FROM import_payloads WHERE class_id = {$cid}");
                mysqli_query($conn, "DELETE FROM class_members WHERE class_id = {$cid}");
                mysqli_query($conn, "DELETE FROM class_transfers WHERE class_id = {$cid}");
                mysqli_query($conn, "DELETE FROM announce_msgs WHERE class_id = {$cid}");
                mysqli_query($conn, "DELETE FROM announce_rev_msgs WHERE class_id = {$cid}");
                mysqli_query($conn, "DELETE FROM announce_devices WHERE class_id = {$cid}");
                mysqli_query($conn, "DELETE FROM pinned_items WHERE item_type = 'class' AND item_id = {$cid}");
                mysqli_query($conn, "DELETE FROM classes WHERE id = {$cid}");
            }
            // 用户级数据
            mysqli_query($conn, "DELETE FROM eval_modes WHERE teacher_id = {$tid}");
            mysqli_query($conn, "DELETE FROM login_days WHERE teacher_id = {$tid}");
            mysqli_query($conn, "DELETE FROM login_tokens WHERE teacher_id = {$tid}");
            mysqli_query($conn, "DELETE FROM pinned_items WHERE teacher_id = {$tid}");
            mysqli_query($conn, "DELETE FROM class_members WHERE teacher_id = {$tid}");
            mysqli_query($conn, "DELETE FROM class_transfers WHERE from_teacher_id = {$tid} OR to_teacher_id = {$tid}");
            mysqli_query($conn, "DELETE FROM omr_lib WHERE created_by = {$tid}");
            mysqli_query($conn, "DELETE FROM omr_images WHERE teacher_id = {$tid}");
            mysqli_query($conn, "DELETE FROM teachers WHERE id = {$tid}");
            $msg = '账号及其全部数据已删除';
        }
    }
    // ===== 模拟登录（以该用户身份操作，可随时退出） =====
    elseif ($action === 'user_impersonate') {
        $tid = intval($_POST['user_id'] ?? 0);
        $stmt = mysqli_prepare($conn, "SELECT id, realname, username, disabled, is_admin FROM teachers WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $tid);
        mysqli_stmt_execute($stmt);
        $target = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$target) {
            $msg = '账号不存在';
            $msg_type = 'error';
        } elseif ($tid === $current_teacher_id) {
            $msg = '不能模拟登录自己';
            $msg_type = 'error';
        } elseif (intval($target['is_admin']) === 1) {
            $msg = '不能模拟登录其他管理员账号';
            $msg_type = 'error';
        } elseif (intval($target['disabled']) === 1) {
            $msg = '该账号已被禁用，请先启用再模拟登录';
            $msg_type = 'error';
        } else {
            session_regenerate_id(true);
            $_SESSION['impersonator_id'] = $current_teacher_id;
            $_SESSION['impersonator_name'] = $_SESSION['teacher_name'] ?? '';
            $_SESSION['teacher_id'] = intval($target['id']);
            $_SESSION['teacher_name'] = $target['realname'] !== '' ? $target['realname'] : $target['username'];
            header("Location: classes.php");
            exit();
        }
    }
}

// ===== 用户列表：搜索 + 分页 =====
$q = trim(strval($_GET['q'] ?? ''));
$users_per = intval($_GET['uper'] ?? 20);
if ($users_per <= 0) $users_per = 20;
$users_page = max(1, intval($_GET['upage'] ?? 1));
$all_users = [];
$u_like = '%' . $q . '%';
$u_search = " AND (phone LIKE ? OR realname LIKE ? OR username LIKE ?)";

$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM teachers WHERE 1=1{$u_search}");
mysqli_stmt_bind_param($stmt, "sss", $u_like, $u_like, $u_like);
mysqli_stmt_execute($stmt);
$users_total = intval(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c']);
mysqli_stmt_close($stmt);
$users_pages = max(1, (int)ceil($users_total / $users_per));
if ($users_page > $users_pages) $users_page = $users_pages;
$u_limit = " LIMIT {$users_per} OFFSET " . (($users_page - 1) * $users_per);
$stmt = mysqli_prepare($conn, "SELECT * FROM teachers WHERE 1=1{$u_search} ORDER BY id ASC{$u_limit}");
mysqli_stmt_bind_param($stmt, "sss", $u_like, $u_like, $u_like);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) $all_users[] = $row;
mysqli_stmt_close($stmt);

$allow_register = get_setting($conn, 'allow_register', '1', 0) === '1';

page_header('后台管理', 'admin.php');
?>
<?php if ($msg): ?><div class="alert alert-<?php echo $msg_type; ?>"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

<!-- 注册开关 + 添加用户 -->
<div class="panel" id="settings" style="display:flex;gap:30px;flex-wrap:wrap;align-items:flex-start;">
    <form method="post" autocomplete="off">
        <input type="hidden" name="action" value="save_register" autocomplete="off">
        <h3>注册开关</h3>
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:12px;">
            <input type="checkbox" name="allow_register" class="switch-input"<?php echo $allow_register ? ' checked' : ''; ?> autocomplete="off">
            <span>允许教师自主注册</span>
        </label>
        <button type="submit" class="btn">保存</button>
    </form>
    <form method="post" style="border-left:1px solid #eee;padding-left:30px;" autocomplete="off">
        <input type="hidden" name="action" value="add_user" autocomplete="off">
        <h3>添加用户</h3>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
            <div class="form-group" style="margin-bottom:0;">
                <label>手机号（登录账号）</label>
                <input type="text" name="phone" class="form-control" required style="max-width:160px;" autocomplete="off">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label>姓名（选填）</label>
                <input type="text" name="realname" class="form-control" style="max-width:120px;" autocomplete="off">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label>初始密码</label>
                <input type="text" name="password" class="form-control" required style="max-width:140px;" autocomplete="off">
            </div>
            <button type="submit" class="btn">＋ 添加</button>
        </div>
    </form>
</div>

<!-- 用户管理 -->
<div class="panel">
    <h3 id="users">用户管理 <span style="font-size:13px;color:#999;">（手机号即登录账号）</span>
        <button type="button" class="hint-q" onclick="toggleHint(event, '<b>用户管理说明</b><br>· 编辑可修改手机号、姓名与密码<br>· 「模拟」将以该用户身份登录操作（页面顶部可退出模拟）<br>· 删除会连同其全部班级与登记数据一并移除')">?</button>
    </h3>
    <form method="get" style="display:flex;gap:10px;align-items:center;margin-bottom:10px;" autocomplete="off">
        <input type="text" name="q" class="form-control" placeholder="按手机号 / 姓名 / 账号搜索" value="<?php echo htmlspecialchars($q); ?>" style="max-width:260px;" autocomplete="off">
        <button type="submit" class="btn btn-sm">搜索</button>
        <?php if ($q !== ''): ?><a href="admin.php#users" class="btn btn-sm btn-outline" style="text-decoration:none;">清除</a><?php endif; ?>
    </form>
    <div class="table-wrap" id="users">
        <table class="data-table">
            <thead>
                <tr><th style="width:60px;">ID</th><th style="width:140px;">手机号</th><th style="width:120px;">姓名</th><th style="width:90px;">角色</th><th style="width:70px;">状态</th><th style="width:280px;">操作</th></tr>
            </thead>
            <tbody>
                <?php foreach ($all_users as $u): $uid = intval($u['id']); $u_is_admin = intval($u['is_admin']) === 1; ?>
                <tr<?php echo intval($u['disabled']) === 1 ? ' style="background:#faf0f0;"' : ''; ?>>
                    <td><?php echo $uid; ?></td>
                    <td><?php echo htmlspecialchars(strval($u['phone'])); ?></td>
                    <td><?php echo htmlspecialchars($u['realname'] !== '' ? $u['realname'] : '-'); ?></td>
                    <td><?php echo $u_is_admin ? '<span style="color:#e67e22;font-weight:bold;">管理员</span>' : '<span style="color:#999;">教师</span>'; ?></td>
                    <td><?php echo intval($u['disabled']) === 1 ? '<span style="color:#c0392b;">已禁用</span>' : '<span style="color:#27ae60;">正常</span>'; ?></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline" onclick="openEdit(<?php echo $uid; ?>, '<?php echo htmlspecialchars(addslashes(strval($u['phone']))); ?>', '<?php echo htmlspecialchars(addslashes(strval($u['realname']))); ?>')">✎ 编辑</button>
                        <?php if (!$u_is_admin || $uid !== $current_teacher_id): ?>
                        <?php if (!$u_is_admin): ?>
                        <form method="post" style="display:inline;" autocomplete="off">
                            <input type="hidden" name="action" value="user_impersonate" autocomplete="off">
                            <input type="hidden" name="user_id" value="<?php echo $uid; ?>" autocomplete="off">
                            <button type="submit" class="btn btn-sm" onclick="return confirm('将以「<?php echo htmlspecialchars($u['realname'] !== '' ? $u['realname'] : $u['username']); ?>」身份模拟登录，操作均视为其本人行为。确认？')">🎭 模拟</button>
                        </form>
                        <?php endif; ?>
                        <form method="post" style="display:inline;" autocomplete="off">
                            <input type="hidden" name="action" value="<?php echo intval($u['disabled']) === 1 ? 'user_enable' : 'user_disable'; ?>" autocomplete="off">
                            <input type="hidden" name="user_id" value="<?php echo $uid; ?>" autocomplete="off">
                            <button type="submit" class="btn btn-sm btn-outline" onclick="return confirm('<?php echo intval($u['disabled']) === 1 ? '确认启用该账号？' : '确认禁用该账号？禁用后其将无法登录。'; ?>')"><?php echo intval($u['disabled']) === 1 ? '启用' : '禁用'; ?></button>
                        </form>
                        <form method="post" style="display:inline;" autocomplete="off">
                            <input type="hidden" name="action" value="user_delete" autocomplete="off">
                            <input type="hidden" name="user_id" value="<?php echo $uid; ?>" autocomplete="off">
                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('确认删除账号「<?php echo htmlspecialchars(strval($u['phone'])); ?>」？\n将同时删除其班级、学生、作业项目、登记记录等全部数据，不可恢复！')">删除</button>
                        </form>
                        <?php else: ?>
                        <span style="color:#bbb;font-size:12px;">当前登录账号</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$all_users): ?>
                <tr><td colspan="6" style="text-align:center;color:#999;">暂无用户</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:12px;font-size:13px;color:#666;">
        <span>共 <b><?php echo $users_total; ?></b> 条</span>
        <?php if ($users_pages > 1): ?>
        <span style="display:flex;gap:8px;align-items:center;">
            <?php if ($users_page > 1): ?><a class="btn btn-sm btn-outline" href="admin.php?q=<?php echo urlencode($q); ?>&upage=<?php echo $users_page - 1; ?>&uper=<?php echo $users_per; ?>#users" style="text-decoration:none;">‹ 上一页</a><?php endif; ?>
            <span>第 <b><?php echo $users_page; ?></b> / <?php echo $users_pages; ?> 页</span>
            <?php if ($users_page < $users_pages): ?><a class="btn btn-sm btn-outline" href="admin.php?q=<?php echo urlencode($q); ?>&upage=<?php echo $users_page + 1; ?>&uper=<?php echo $users_per; ?>#users" style="text-decoration:none;">下一页 ›</a><?php endif; ?>
        </span>
        <?php endif; ?>
    </div>
</div>

<!-- 编辑用户弹窗 -->
<div id="editMask" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:1000;align-items:center;justify-content:center;" onclick="if(event.target===this)closeEdit()">
    <div style="background:#fff;border-radius:10px;padding:25px;width:340px;max-width:92vw;">
        <h3 style="margin-top:0;">编辑用户</h3>
        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="edit_teacher" autocomplete="off">
            <input type="hidden" name="teacher_id" id="edit_id" value="" autocomplete="off">
            <div class="form-group">
                <label>手机号（登录账号）</label>
                <input type="text" name="phone" id="edit_phone" class="form-control" required autocomplete="off">
            </div>
            <div class="form-group">
                <label>姓名</label>
                <input type="text" name="realname" id="edit_name" class="form-control" autocomplete="off">
            </div>
            <div class="form-group">
                <label>新密码（留空=不修改）</label>
                <input type="text" name="password" class="form-control" autocomplete="off">
            </div>
            <div style="display:flex;gap:10px;">
                <button type="submit" class="btn" style="flex:1;">保存</button>
                <button type="button" class="btn btn-outline" style="flex:1;" onclick="closeEdit()">取消</button>
            </div>
        </form>
    </div>
</div>
<script>
function openEdit(id, phone, name) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_phone').value = phone;
    document.getElementById('edit_name').value = name;
    document.getElementById('editMask').style.display = 'flex';
}
function closeEdit() { document.getElementById('editMask').style.display = 'none'; }
</script>
<?php page_footer(); ?>
