<?php
/**
 * 班级管理：新建（年段+名称）、重命名、复制（带学生）、重置（清空登记数据）、删除
 * 权限：按角色显示可见班级；管理操作仅限创建人/管理员/班主任；删除=管理员/创建人/年段长-本年段/班主任-本班（按「删除班级权限」开关），授权班级提供「退出」（班级码成员随时可退，其他关联按「退出班级权限」开关）
 * 分块显示：三组选项卡 我的班级 / 授权班级 / 其他班级（其他班级仅管理员可见，按创建者账号分组并显示账号+名字），支持班级置顶
 * 上下文：总管理员按后台切换的学校/平台（个人账号）上下文严格过滤；平台上下文额外并入凭班级授权码加入的班级（可跨校）
 * 班级授权：创建者持 8 位班级授权码，他人凭码申请 → 创建人审核通过后加入，可设 管理/仅登记/仅查看 权限，可随时移除
 */
require_once 'includes/db_config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/layout.php';

$conn = getConnection();
ensure_extra_tables($conn);   // 班级转移 / 记住登录（存量安装自动建表）
$msg = '';
$msg_type = 'success';
$flash = flash_take(); // PRG 重定向后的一次性操作提示
if ($flash) { $msg = $flash['msg']; $msg_type = $flash['type']; }
$school_id = current_school_id($conn);
$feat_class_code = get_feature($conn, $school_id, 'feat_class_code');   // 班级授权码功能开关
$can_create_class = can_create_class_gate($conn, $current_teacher_id); // 建班权限（功能开关+角色勾选）
$embed = intval($_GET['embed'] ?? 0) === 1;              // 弹窗内嵌模式（projects.php「班级管理」iframe 加载：隐藏导航/列表级控件）
$focus_class_id = intval($_GET['focus_class'] ?? 0);     // 聚焦单一班级：列表仅呈现该班级（弹窗内嵌时用）

// 下载历史数据导入模板（班级级：项目名称,编号,姓名,日期,评价值）
if (($_GET['dl_template'] ?? '') === 'class') {
    $class_id = intval($_GET['class_id'] ?? 0);
    $class = check_class_owner($conn, $class_id, $current_teacher_id);
    if (!$class) { header('Location: classes.php'); exit(); }
    $sample1 = '数学作业,2024001,张三,2026-09-01 10:00,优';
    $sample2 = '数学作业,2024002,李四,,';
    download_csv('班级历史数据导入模板.csv', [
        '项目名称,编号,姓名,日期,评价值',
        $sample1,
        $sample2,
    ]);
}

// ===== 操作处理 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 新建班级
    if ($action === 'add') {
        if (!can_create_class_gate($conn, $current_teacher_id)) {
            $msg = '教师建班功能已关闭或您暂无建班权限，请联系管理员';
            $msg_type = 'error';
        } else {
            $name = check_input($_POST['name'] ?? '');
            if ($name !== '') {
                $class_code = generate_class_code($conn);
                $stmt = mysqli_prepare($conn, "INSERT INTO classes (teacher_id, school_id, name, class_code, created_at) VALUES (?, ?, ?, ?, NOW())");
                mysqli_stmt_bind_param($stmt, "iiss", $current_teacher_id, $school_id, $name, $class_code);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $msg = $feat_class_code ? '班级创建成功（班级授权码：' . $class_code . '）' : '班级创建成功';
            } else {
                $msg = '班级名称不能为空';
                $msg_type = 'error';
            }
        }
    }
    // 重命名班级
    elseif ($action === 'rename') {
        $class_id = intval($_POST['class_id'] ?? 0);
        $name = check_input($_POST['name'] ?? '');
        if (can_manage_class($conn, $current_teacher_id, $class_id) && $name !== '') {
            $stmt = mysqli_prepare($conn, "UPDATE classes SET name = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "si", $name, $class_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = '班级信息已更新';
        }
    }
    // 复制班级（带学生信息，不含信息）
    elseif ($action === 'copy') {
        $class_id = intval($_POST['class_id'] ?? 0);
        $class = check_class_owner($conn, $class_id, $current_teacher_id);
        if ($class) {
            $new_name = $class['name'] . '（副本）';
            $new_school = intval($class['school_id'] ?? $school_id);
            $class_code = generate_class_code($conn);
            $stmt = mysqli_prepare($conn, "INSERT INTO classes (teacher_id, school_id, name, class_code, created_at) VALUES (?, ?, ?, ?, NOW())");
            mysqli_stmt_bind_param($stmt, "iiss", $current_teacher_id, $new_school, $new_name, $class_code);
            mysqli_stmt_execute($stmt);
            $new_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, "INSERT INTO students (class_id, seat_no, name, student_no, remark, created_at)
                                           SELECT ?, seat_no, name, student_no, remark, NOW() FROM students WHERE class_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $new_id, $class_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = '班级已复制（含学生名单，不含信息）';
        }
    }
    // 重置班级（敏感操作：需验证登录密码；仅清空登记数据，保留学生）
    elseif ($action === 'reset') {
        $class_id = intval($_POST['class_id'] ?? 0);
        if (!verify_login_password($conn, $current_teacher_id, $_POST['login_pwd'] ?? '')) {
            $msg = '登录密码验证失败，重置操作未执行';
            $msg_type = 'error';
        } elseif (can_manage_class_strict($conn, $current_teacher_id, $class_id)) {
            $stmt = mysqli_prepare($conn, "DELETE r FROM records r INNER JOIN projects p ON r.project_id = p.id WHERE p.class_id = ?");
            mysqli_stmt_bind_param($stmt, "i", $class_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, "DELETE rd FROM record_days rd INNER JOIN projects p ON rd.project_id = p.id WHERE p.class_id = ?");
            mysqli_stmt_bind_param($stmt, "i", $class_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, "DELETE FROM projects WHERE class_id = ?");
            mysqli_stmt_bind_param($stmt, "i", $class_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = '班级已重置（登记与项目数据已清空，学生名单保留）';
        }
    }
    // 删除班级（敏感操作：需验证登录密码；软删除进回收站：本班项目一并隐藏，可恢复；权限：管理员/创建人/年段长-本年段/班主任-本班，按「删除班级权限」开关）
    elseif ($action === 'delete') {
        $class_id = intval($_POST['class_id'] ?? 0);
        if (!verify_login_password($conn, $current_teacher_id, $_POST['login_pwd'] ?? '')) {
            $msg = '登录密码验证失败，删除操作未执行';
            $msg_type = 'error';
        } elseif (can_delete_class($conn, $current_teacher_id, $class_id)) {
            $stmt = mysqli_prepare($conn, "UPDATE classes SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
            mysqli_stmt_bind_param($stmt, "i", $class_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, "UPDATE projects SET deleted_at = NOW() WHERE class_id = ? AND deleted_at IS NULL");
            mysqli_stmt_bind_param($stmt, "i", $class_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = '班级已移入回收站（其项目一并隐藏），可在「回收站」恢复或彻底删除';
        }
    }
    // 退出授权班级：班级码成员随时可退（不受开关影响）；管理员设置/导入的角色关联按「退出班级权限」开关；自己创建的班级不能退出（可删除）
    elseif ($action === 'exit_class') {
        $class_id = intval($_POST['class_id'] ?? 0);
        $stmt = mysqli_prepare($conn, "SELECT teacher_id FROM classes WHERE id = ? AND deleted_at IS NULL");
        mysqli_stmt_bind_param($stmt, "i", $class_id);
        mysqli_stmt_execute($stmt);
        $exit_owner = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $has_binding = array_key_exists($class_id, get_teacher_class_member_map($conn, $current_teacher_id));
        if (!$exit_owner) {
            $msg = '班级不存在或已删除';
            $msg_type = 'error';
        } elseif (intval($exit_owner['teacher_id']) === $current_teacher_id) {
            $msg = '这是您创建的班级，不能退出（不需要时可删除）';
            $msg_type = 'error';
        } elseif (!$has_binding) {
            $msg = '您与该班级没有可退出的授权关联';
            $msg_type = 'error';
        } elseif (!can_exit_class($conn, $current_teacher_id, $class_id)) {
            $msg = '该班级存在管理员设置的角色关联且未开放退出权限，请联系管理员移除';
            $msg_type = 'error';
        } else {
            $stmt = mysqli_prepare($conn, "DELETE FROM class_members WHERE class_id = ? AND teacher_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $class_id, $current_teacher_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = '已退出该班级（授权成员身份已移除）';
        }
    }
    // 回收站：恢复班级（连同被一并隐藏的项目）
    elseif ($action === 'class_restore') {
        $class_id = intval($_POST['class_id'] ?? 0);
        if (can_recycle_class($conn, $current_teacher_id, $class_id)) {
            $stmt = mysqli_prepare($conn, "UPDATE classes SET deleted_at = NULL WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $class_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, "UPDATE projects SET deleted_at = NULL WHERE class_id = ? AND deleted_at IS NOT NULL");
            mysqli_stmt_bind_param($stmt, "i", $class_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = '班级已恢复（含其项目与登记数据）';
        }
    }
    // 回收站：彻底删除班级（敏感操作：需验证登录密码；学生/项目/登记数据全部清除，不可恢复）
    elseif ($action === 'class_purge') {
        $class_id = intval($_POST['class_id'] ?? 0);
        if (!verify_login_password($conn, $current_teacher_id, $_POST['login_pwd'] ?? '')) {
            $msg = '登录密码验证失败，彻底删除操作未执行';
            $msg_type = 'error';
        } elseif (can_recycle_class($conn, $current_teacher_id, $class_id)) {
            $stmt = mysqli_prepare($conn, "DELETE r FROM records r INNER JOIN projects p ON r.project_id = p.id WHERE p.class_id = ?");
            mysqli_stmt_bind_param($stmt, "i", $class_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, "DELETE rd FROM record_days rd INNER JOIN projects p ON rd.project_id = p.id WHERE p.class_id = ?");
            mysqli_stmt_bind_param($stmt, "i", $class_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            mysqli_query($conn, "DELETE FROM pinned_items WHERE item_type = 'project' AND item_id IN (SELECT id FROM projects WHERE class_id = " . intval($class_id) . ")");

            $stmt = mysqli_prepare($conn, "DELETE FROM projects WHERE class_id = ?");
            mysqli_stmt_bind_param($stmt, "i", $class_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, "DELETE FROM students WHERE class_id = ?");
            mysqli_stmt_bind_param($stmt, "i", $class_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            mysqli_query($conn, "DELETE FROM class_members WHERE class_id = " . intval($class_id));
            mysqli_query($conn, "DELETE FROM pinned_items WHERE item_type = 'class' AND item_id = " . intval($class_id));

            $stmt = mysqli_prepare($conn, "DELETE FROM classes WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $class_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = '班级已彻底删除（学生、项目与登记数据一并清除）';
        }
    }
    // 导入历史登记数据（班级级：模板含「项目名称」列，缺失项目自动创建）
    elseif ($action === 'import_history') {
        $class_id = intval($_POST['class_id'] ?? 0);
        $class = check_class_owner($conn, $class_id, $current_teacher_id);
        $content = read_upload_csv('import_file');
        if (!$class) {
            $msg = '无权操作该班级';
            $msg_type = 'error';
        } elseif ($content === null) {
            $msg = '请选择模板 CSV/TXT 文件上传（可在导入弹窗中下载模板）';
            $msg_type = 'error';
        } else {
            $r = import_history_csv($conn, $current_teacher_id, $class, $content, null);
            $msg = $r['msg'];
            $msg_type = $r['ok'] ? 'success' : 'error';
        }
    }
    // ===== 班级置顶 / 取消置顶 =====
    elseif ($action === 'toggle_pin_class') {
        $class_id = intval($_POST['class_id'] ?? 0);
        if (can_view_class($conn, $current_teacher_id, $class_id)) {
            $pinned = toggle_pinned($conn, $current_teacher_id, 'class', $class_id);
            $msg = $pinned ? '班级已置顶' : '已取消置顶';
        }
    }
    // ===== 凭班级授权码申请加入 =====
    elseif ($action === 'apply_class') {
        $code = trim($_POST['class_code'] ?? '');
        if (!preg_match('/^\d{8}$/', $code)) {
            $msg = '请输入 8 位数字班级授权码';
            $msg_type = 'error';
        } else {
            $stmt = mysqli_prepare($conn, "SELECT id, name, teacher_id, school_id FROM classes WHERE class_code = ?");
            mysqli_stmt_bind_param($stmt, "s", $code);
            mysqli_stmt_execute($stmt);
            $cls = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$cls) {
                $msg = '班级授权码不存在，请向班级创建者确认';
                $msg_type = 'error';
            } elseif (!get_feature($conn, intval($cls['school_id']), 'feat_class_code')) {
                $msg = '该班级未开放「班级授权码」功能，无法申请加入';
                $msg_type = 'error';
            } elseif (intval($cls['teacher_id']) === $current_teacher_id) {
                $msg = '这是您自己创建的班级，无需申请';
                $msg_type = 'error';
            } else {
                $cid = intval($cls['id']);
                $stmt = mysqli_prepare($conn, "SELECT id, status FROM class_members WHERE class_id = ? AND teacher_id = ?");
                mysqli_stmt_bind_param($stmt, "ii", $cid, $current_teacher_id);
                mysqli_stmt_execute($stmt);
                $exist = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);
                if ($exist && $exist['status'] === 'approved') {
                    $msg = '您已在该班级的授权名单中';
                    $msg_type = 'error';
                } elseif ($exist && $exist['status'] === 'pending') {
                    $msg = '您已申请加入「' . $cls['name'] . '」，正在等待班级创建者审核';
                    $msg_type = 'error';
                } else {
                    // 首次申请或被拒后重新申请
                    $stmt = mysqli_prepare($conn, "INSERT INTO class_members (class_id, teacher_id, perm, status, created_at)
                                                   VALUES (?, ?, 'view', 'pending', NOW())
                                                   ON DUPLICATE KEY UPDATE status = 'pending', created_at = NOW(), handled_at = NULL");
                    mysqli_stmt_bind_param($stmt, "ii", $cid, $current_teacher_id);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                    $msg = '申请已提交！已向「' . $cls['name'] . '」发起加入申请，请等待班级创建者审核。';
                }
            }
        }
    }
    // ===== 授权审核：通过（班级创建人） =====
    elseif ($action === 'approve_member') {
        $mid = intval($_POST['member_id'] ?? 0);
        $perm = in_array($_POST['perm'] ?? 'view', ['manage', 'create', 'register', 'view'], true) ? $_POST['perm'] : 'view';
        $stmt = mysqli_prepare($conn, "SELECT cm.*, t.username, t.realname FROM class_members cm
                                       INNER JOIN teachers t ON cm.teacher_id = t.id
                                       WHERE cm.id = ? AND cm.status = 'pending' AND cm.class_id IN
                                       (SELECT id FROM classes WHERE teacher_id = ?)");
        mysqli_stmt_bind_param($stmt, "ii", $mid, $current_teacher_id);
        mysqli_stmt_execute($stmt);
        $app = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$app) {
            $msg = '申请不存在或无权限处理';
            $msg_type = 'error';
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE class_members SET perm = ?, status = 'approved', handled_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "si", $perm, $mid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $dispname = $app['realname'] !== '' ? $app['realname'] : $app['username'];
            $perm_names = ['manage' => '管理班级', 'create' => '可建立', 'register' => '仅登记', 'view' => '仅查看'];
            $msg = "已通过「{$dispname}」的申请，权限：" . $perm_names[$perm];
        }
    }
    // ===== 授权审核：拒绝（班级创建人） =====
    elseif ($action === 'reject_member') {
        $mid = intval($_POST['member_id'] ?? 0);
        $stmt = mysqli_prepare($conn, "UPDATE class_members SET status = 'rejected', handled_at = NOW()
                                       WHERE id = ? AND status = 'pending' AND class_id IN
                                       (SELECT id FROM classes WHERE teacher_id = ?)");
        mysqli_stmt_bind_param($stmt, "ii", $mid, $current_teacher_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $msg = '已拒绝该申请（对方可重新提交）';
    }
    // ===== 修改成员权限（班级创建人） =====
    elseif ($action === 'set_member_perm') {
        $mid = intval($_POST['member_id'] ?? 0);
        $perm = in_array($_POST['perm'] ?? '', ['manage', 'create', 'register', 'view'], true) ? $_POST['perm'] : '';
        if ($perm === '') {
            $msg = '权限级别无效';
            $msg_type = 'error';
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE class_members SET perm = ?, handled_at = NOW()
                                           WHERE id = ? AND status = 'approved' AND class_id IN
                                           (SELECT id FROM classes WHERE teacher_id = ?)");
            mysqli_stmt_bind_param($stmt, "sii", $perm, $mid, $current_teacher_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = '成员权限已更新';
        }
    }
    // ===== 移除成员（班级创建人；学校管理员可移除本校班级成员——管理员视角「他人加入的」卡片「退出」入口） =====
    elseif ($action === 'remove_member') {
        $mid = intval($_POST['member_id'] ?? 0);
        $stmt = mysqli_prepare($conn, "SELECT c.teacher_id, c.school_id FROM class_members cm
                                       INNER JOIN classes c ON cm.class_id = c.id WHERE cm.id = ? AND cm.status = 'approved'");
        mysqli_stmt_bind_param($stmt, "i", $mid);
        mysqli_stmt_execute($stmt);
        $mrow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $allowed = $mrow && intval($mrow['teacher_id']) === $current_teacher_id;
        if ($allowed) {
            $stmt = mysqli_prepare($conn, "DELETE FROM class_members WHERE id = ? AND status = 'approved'");
            mysqli_stmt_bind_param($stmt, "i", $mid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = '成员已移除';
        } else {
            $msg = '无权限移除该成员（仅班级创建人）';
            $msg_type = 'error';
        }
    }
    // ===== 撤销我的加入申请（待审核状态可自行撤销） =====
    elseif ($action === 'cancel_join_app') {
        $mid = intval($_POST['member_id'] ?? 0);
        $stmt = mysqli_prepare($conn, "DELETE FROM class_members WHERE id = ? AND teacher_id = ? AND status = 'pending'");
        mysqli_stmt_bind_param($stmt, "ii", $mid, $current_teacher_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $msg = '加入申请已撤销';
    }
    // ===== 发起班级转移（仅创建人；对方确认后班级易主，自己保留「管理」授权） =====
    elseif ($action === 'transfer_class') {
        $class_id = intval($_POST['class_id'] ?? 0);
        $phone = trim($_POST['phone'] ?? '');
        $class = check_class_owner($conn, $class_id, $current_teacher_id);
        $target = null;
        if ($phone !== '') {
            $stmt = mysqli_prepare($conn, "SELECT id, username, realname, disabled FROM teachers WHERE phone = ?");
            mysqli_stmt_bind_param($stmt, "s", $phone);
            mysqli_stmt_execute($stmt);
            $target = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
        }
        $stmt = mysqli_prepare($conn, "SELECT id FROM class_transfers WHERE class_id = ? AND status = 'pending' LIMIT 1");
        mysqli_stmt_bind_param($stmt, "i", $class_id);
        mysqli_stmt_execute($stmt);
        $has_pending = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$class) {
            $msg = '仅班级创建人可发起转移';
            $msg_type = 'error';
        } elseif ($phone === '' || !$target) {
            $msg = '未找到该手机号对应的账号，请对方确认注册手机号后重试';
            $msg_type = 'error';
        } elseif (intval($target['id']) === $current_teacher_id) {
            $msg = '不能转移给自己';
            $msg_type = 'error';
        } elseif (intval($target['disabled']) === 1) {
            $msg = '对方账号已被禁用，无法转移';
            $msg_type = 'error';
        } elseif ($has_pending) {
            $msg = '该班级已有待确认的转移申请，请先等待对方处理或撤销';
            $msg_type = 'error';
        } else {
            $tname = $target['realname'] !== '' ? $target['realname'] : $target['username'];
            $stmt = mysqli_prepare($conn, "INSERT INTO class_transfers (class_id, from_teacher_id, to_teacher_id, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
            mysqli_stmt_bind_param($stmt, "iii", $class_id, $current_teacher_id, $target['id']);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = '转移申请已发起！等待「' . $tname . '」确认后班级将正式转移（确认前您仍是创建人，可随时撤销）';
        }
    }
    // ===== 撤销我发起的班级转移 =====
    elseif ($action === 'cancel_transfer') {
        $tid = intval($_POST['transfer_id'] ?? 0);
        $stmt = mysqli_prepare($conn, "UPDATE class_transfers SET status = 'cancelled', handled_at = NOW()
                                       WHERE id = ? AND from_teacher_id = ? AND status = 'pending'");
        mysqli_stmt_bind_param($stmt, "ii", $tid, $current_teacher_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $msg = '转移申请已撤销';
    }
    // ===== 确认接收班级转移（对方确认后：班级易主，原创建人成为「管理」授权成员） =====
    elseif ($action === 'approve_transfer') {
        $tid = intval($_POST['transfer_id'] ?? 0);
        $tr = get_transfer_by_id($conn, $tid);
        if (!$tr || intval($tr['to_teacher_id']) !== $current_teacher_id || $tr['status'] !== 'pending' || $tr['class_deleted']) {
            $msg = '转移申请不存在、已处理或班级已删除';
            $msg_type = 'error';
        } else {
            $cid = intval($tr['class_id']);
            $from_id = intval($tr['from_teacher_id']);
            // 班级易主
            $stmt = mysqli_prepare($conn, "UPDATE classes SET teacher_id = ? WHERE id = ? AND deleted_at IS NULL");
            mysqli_stmt_bind_param($stmt, "ii", $current_teacher_id, $cid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            // 新创建人若恰有旧授权成员记录则移除（已成为创建人，无需成员身份）
            $stmt = mysqli_prepare($conn, "DELETE FROM class_members WHERE class_id = ? AND teacher_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $cid, $current_teacher_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            // 原创建人保留「管理」授权成员身份（班级转为授权班级）
            $stmt = mysqli_prepare($conn, "INSERT INTO class_members (class_id, teacher_id, perm, status, created_at, handled_at)
                                           VALUES (?, ?, 'manage', 'approved', NOW(), NOW())
                                           ON DUPLICATE KEY UPDATE perm = 'manage', status = 'approved', handled_at = NOW()");
            mysqli_stmt_bind_param($stmt, "ii", $cid, $from_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            // 转移单完结
            $stmt = mysqli_prepare($conn, "UPDATE class_transfers SET status = 'approved', handled_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $tid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = '已接收班级「' . $tr['class_name'] . '」，您现在是该班级创建人；对方保留「管理班级」授权';
        }
    }
    // ===== 拒绝班级转移 =====
    elseif ($action === 'reject_transfer') {
        $tid = intval($_POST['transfer_id'] ?? 0);
        $stmt = mysqli_prepare($conn, "UPDATE class_transfers SET status = 'rejected', handled_at = NOW()
                                       WHERE id = ? AND to_teacher_id = ? AND status = 'pending'");
        mysqli_stmt_bind_param($stmt, "ii", $tid, $current_teacher_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $msg = '已拒绝该转移申请';
    }
}

// PRG：POST 操作完成后立即重定向（提示经 flash 展示），避免浏览器刷新导致重复执行
// ret=projects：来自 projects.php「班级管理」二级菜单的操作，完成后回跳 projects.php?class_id=X
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $msg !== '') {
    flash_set($msg, $msg_type);
    if (($_POST['ret'] ?? '') === 'projects' && intval($_POST['class_id'] ?? 0) > 0) {
        header('Location: projects.php?class_id=' . intval($_POST['class_id']));
    } else {
        header('Location: ' . $_SERVER['REQUEST_URI']);
    }
    exit();
}

// ===== 班级列表（按上下文学校严格过滤），选项卡分：我的班级 / 授权班级 / 其他班级（仅管理员）；置顶优先 =====
$perm_names = ['manage' => '管理班级', 'create' => '可建立', 'register' => '仅登记', 'view' => '仅查看'];
$member_map = get_teacher_class_member_map($conn, $current_teacher_id);
$visible_ids = get_visible_class_ids($conn, $current_teacher_id);
// 平台（个人账号）上下文（school_id=0）：并入凭班级授权码加入的班级（可属于任意学校）
if (intval($school_id) === 0) {
    foreach (array_keys($member_map) as $cid) $visible_ids[] = intval($cid);
    $visible_ids = array_values(array_unique(array_map('intval', $visible_ids)));
}
$own_classes = [];      // 我创建的（限当前上下文学校）
$pool_classes = [];     // 可见的他人班级（再分授权/其他）
$pinned_class_ids = get_pinned_ids($conn, $current_teacher_id, 'class');
if ($visible_ids) {
    $in = implode(',', array_map('intval', $visible_ids));
    $res = mysqli_query($conn, "SELECT c.*,
            (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id) AS student_count,
            (SELECT COUNT(*) FROM projects p WHERE p.class_id = c.id AND p.deleted_at IS NULL) AS project_count,
            GREATEST(
                c.created_at,
                COALESCE((SELECT MAX(s2.created_at) FROM students s2 WHERE s2.class_id = c.id), c.created_at),
                COALESCE((SELECT MAX(p2.created_at) FROM projects p2 WHERE p2.class_id = c.id), c.created_at),
                COALESCE((SELECT MAX(p2.deleted_at) FROM projects p2 WHERE p2.class_id = c.id), c.created_at),
                COALESCE((SELECT MAX(r.registered_at) FROM records r INNER JOIN projects p3 ON r.project_id = p3.id WHERE p3.class_id = c.id), c.created_at),
                COALESCE((SELECT MAX(r2.eval_at) FROM records r2 INNER JOIN projects p4 ON r2.project_id = p4.id WHERE p4.class_id = c.id), c.created_at)
            ) AS last_updated
            FROM classes c WHERE c.id IN ({$in}) AND c.deleted_at IS NULL");
    while ($row = mysqli_fetch_assoc($res)) {
        // 我创建的班级不按上下文学校过滤（加入学校/切换上下文后，平台期自建的班级仍在「我的班级」，不落入「其他」）
        if (intval($row['teacher_id']) === $current_teacher_id) $own_classes[] = $row;
        else $pool_classes[] = $row;
    }
}
// 置顶排前，其余按 id（注意 id 需 intval：数据库取出的为字符串，严格比较会失效）
$sort_classes = function (&$list) use ($pinned_class_ids) {
    usort($list, function ($a, $b) use ($pinned_class_ids) {
        $pa = in_array(intval($a['id']), $pinned_class_ids, true) ? 0 : 1;
        $pb = in_array(intval($b['id']), $pinned_class_ids, true) ? 0 : 1;
        if ($pa !== $pb) return $pa - $pb;
        return intval($a['id']) - intval($b['id']);
    });
};

// 单用户版：无管理员视角与角色体系，可见的他人班级均按授权班级呈现
$my_role_labels = [];       // class_id => 角色标签数组（角色体系已移除，恒为空）
$authed_classes = [];
foreach ($pool_classes as $c) {
    $authed_classes[] = $c;
}
$sort_classes($own_classes);
$sort_classes($authed_classes);
// 聚焦单一班级（弹窗内嵌）：三个分组列表均只保留该班级，页面仅呈现该班级的管理功能
if ($focus_class_id > 0) {
    $flt = function ($list) use ($focus_class_id) {
        return array_values(array_filter($list, function ($c) use ($focus_class_id) {
            return intval($c['id']) === $focus_class_id;
        }));
    };
    $own_classes = $flt($own_classes);
    $authed_classes = $flt($authed_classes);
}

// 授权管理弹窗覆盖全部自建班级
$my_created = $own_classes;
// 我创建班级的授权数据
$auth_data = []; // class_id => ['pending'=>[], 'members'=>[]]
foreach ($my_created as $c) {
    $cid = intval($c['id']);
    $auth_data[$cid] = [
        'pending' => get_class_members($conn, $cid, 'pending'),
        'members' => get_class_members($conn, $cid, 'approved'),
    ];
}

// 我的待审核班级申请（提示进度）
$my_pending_apps = [];
$stmt = mysqli_prepare($conn, "SELECT cm.*, c.name AS class_name FROM class_members cm
                               INNER JOIN classes c ON cm.class_id = c.id
                               WHERE cm.teacher_id = ? AND cm.status = 'pending'");
mysqli_stmt_bind_param($stmt, "i", $current_teacher_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) $my_pending_apps[] = $row;
mysqli_stmt_close($stmt);

// 我发起的班级转移（待对方确认，可撤销）
$my_sent_transfers = [];
$stmt = mysqli_prepare($conn, "SELECT ct.*, c.name AS class_name, t.username, t.realname, t.phone FROM class_transfers ct
                               INNER JOIN classes c ON ct.class_id = c.id
                               INNER JOIN teachers t ON ct.to_teacher_id = t.id
                               WHERE ct.from_teacher_id = ? AND ct.status = 'pending' AND c.deleted_at IS NULL
                               ORDER BY ct.id DESC");
mysqli_stmt_bind_param($stmt, "i", $current_teacher_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) $my_sent_transfers[] = $row;
mysqli_stmt_close($stmt);

// 我收到的班级转移请求（待我确认）
$recv_transfers = [];
$stmt = mysqli_prepare($conn, "SELECT ct.*, c.name AS class_name, t.username, t.realname, t.phone FROM class_transfers ct
                               INNER JOIN classes c ON ct.class_id = c.id
                               INNER JOIN teachers t ON ct.from_teacher_id = t.id
                               WHERE ct.to_teacher_id = ? AND ct.status = 'pending' AND c.deleted_at IS NULL
                               ORDER BY ct.id DESC");
mysqli_stmt_bind_param($stmt, "i", $current_teacher_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) $recv_transfers[] = $row;
mysqli_stmt_close($stmt);

// 待我审批的加入申请（我创建的班级，含班级名与申请人，供页面直接「审批」入口）
$approve_apps = [];
$stmt = mysqli_prepare($conn, "SELECT cm.*, c.id AS cid, c.name AS class_name, t.realname, t.username, t.phone FROM class_members cm
                               INNER JOIN classes c ON cm.class_id = c.id
                               INNER JOIN teachers t ON cm.teacher_id = t.id
                               WHERE c.teacher_id = ? AND cm.status = 'pending' AND c.deleted_at IS NULL
                               ORDER BY cm.id DESC");
mysqli_stmt_bind_param($stmt, "i", $current_teacher_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) $approve_apps[] = $row;
mysqli_stmt_close($stmt);

// 回收站数据（软删除班级：我创建的）
$recycle_classes = [];
{
    $scope_cond = 'c.teacher_id = ' . intval($current_teacher_id);
    $res = mysqli_query($conn, "SELECT c.*,
            (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id) AS student_count,
            (SELECT COUNT(*) FROM projects p WHERE p.class_id = c.id) AS project_count
            FROM classes c WHERE c.deleted_at IS NOT NULL AND ({$scope_cond}) ORDER BY c.deleted_at DESC");
    while ($row = mysqli_fetch_assoc($res)) $recycle_classes[] = $row;
}

// 班级卡片渲染
// 外显按钮：有学生 → 项目 + 二维码；无学生 → 学生名单 + 编辑；其余操作收进「管理」二级菜单
// $cat：own=我创建的（蓝色）/ authed=授权班级（绿色+「授权的」标签）/ other=他人创建的（蓝色，管理员视角，管理含删除）/ other_member=他人加入的（灰色，管理员视角，管理含退出=移除该教师）
function render_class_card($c, $cat, $pinned, $perm_name = '', $opts = []) {
    global $current_teacher_id, $conn, $feat_class_code, $member_map, $my_role_labels, $school_id, $embed;
    $is_own = $cat === 'own';
    $manageable = can_manage_class($conn, $current_teacher_id, $c['id']);
    // 建立的（自建/管理员视角他人创建的）蓝色；加入的（自己授权的）绿色；管理员视角他人加入的灰色
    $border = $pinned ? '#e67e22' : ($is_own || $cat === 'other' ? '#667eea' : ($cat === 'authed' ? '#27ae60' : '#8e9aaf'));
    $cid = intval($c['id']);
    $has_chip = $feat_class_code; // 右上角班级授权码标识：自建=点击打开授权管理弹窗；他人班级=点击复制授权码
    // 内嵌模式（projects.php「班级管理」弹窗）：导航类链接以 _top 打开（跳出 iframe 到主页面），管理操作留在弹窗内
    $top = $embed ? ' target="_top"' : '';
    // 操作权限：删除=管理员/创建人/年段长-本年段/班主任-本班（按「删除班级权限」开关）；退出=班级码成员随时/管理员/开放角色（按「退出班级权限」开关）
    $can_delete = can_delete_class($conn, $current_teacher_id, $cid);
    $can_exit = can_exit_class($conn, $current_teacher_id, $cid);
    // 管理员视角「他人加入的」卡片：管理菜单显示「退出」= 移除该教师的授权成员身份（remove_member）
    $exit_member_id = $cat === 'other_member' ? intval($opts['exit_member_id'] ?? 0) : 0;
    ?>
    <div class="item-card" style="border-left:4px solid <?php echo $border; ?>;cursor:pointer;position:relative;" onclick="<?php echo $embed ? 'top' : 'window'; ?>.location.href='projects.php?class_id=<?php echo $cid; ?>'">
        <?php if ($has_chip): ?>
        <span class="class-code-chip" title="<?php echo $is_own ? '班级授权码：点击查看授权码并管理授权成员' : '班级授权码：点击复制'; ?>" onclick="event.stopPropagation();<?php echo $is_own ? "openModal('authModal{$cid}')" : "copyCode('" . htmlspecialchars($c['class_code']) . "')"; ?>">🔑 <?php echo $c['class_code'] ? htmlspecialchars($c['class_code']) : '授权码'; ?></span>
        <?php endif; ?>
        <h3<?php echo $has_chip ? ' style="padding-right:96px;"' : ''; ?>>
            <?php if ($pinned): ?><span style="color:#e67e22;" title="已置顶">📌</span><?php endif; ?>
            <?php echo htmlspecialchars($c['name']); ?>
            <?php if ($cat === 'other'): ?><span class="badge badge-grade" style="background:#667eea;color:#fff;">👑 创建者</span><?php endif; ?>
            <?php if (!$is_own && $perm_name): ?><span class="badge badge-grade" style="background:#e67e22;color:#fff;"><?php echo htmlspecialchars($perm_name); ?></span><?php endif; ?>
        </h4>
        <div class="meta">
            <div>学生 <?php echo $c['student_count']; ?> 人，项目 <?php echo $c['project_count']; ?> 个</div>
            <div>建于 <?php echo htmlspecialchars(substr(strval($c['created_at']), 0, 10)); ?></div>
            <div>最后更新时间：<?php echo htmlspecialchars(date('Y-m-d H:i', strtotime(strval($c['last_updated'])))); ?></div>
        </div>
        <div class="item-actions" onclick="event.stopPropagation()">
            <a href="projects.php?class_id=<?php echo $cid; ?>"<?php echo $top; ?> class="btn btn-sm">进入</a>
            <a href="stats.php?class_id=<?php echo $cid; ?>"<?php echo $top; ?> class="btn btn-sm btn-outline">📊 查看报表</a>
            <div class="admin-dd">
                <button type="button" class="btn btn-sm btn-outline" onclick="toggleDD(event,this)">⚙ 管理 ▾</button>
                <div class="admin-dd-menu">
                    <a href="students.php?class_id=<?php echo $cid; ?>"<?php echo $top; ?>>📋 学生名单</a>
                    <a href="qrcode.php?class_id=<?php echo $cid; ?>"<?php echo $top; ?>>🎲 生成二维码</a>
                    <?php if ($manageable): ?>
                    <button type="button" class="dd-item" onclick="openImport(<?php echo $cid; ?>, '<?php echo htmlspecialchars(addslashes($c['name'])); ?>')">📥 导入历史数据</button>
                    <button type="button" class="dd-item" onclick="openEdit(<?php echo $cid; ?>, '<?php echo htmlspecialchars(addslashes($c['name'])); ?>')">✏️ 编辑</button>
                    <?php endif; ?>
                    <?php if ($is_own): ?>
                    <button type="button" class="dd-item" onclick="openTransfer(<?php echo $cid; ?>, '<?php echo htmlspecialchars(addslashes($c['name'])); ?>')">🔀 转移班级</button>
                    <?php endif; ?>
                    <?php if ($is_own && $feat_class_code): ?>
                    <button type="button" class="dd-item" onclick="openModal('authModal<?php echo $cid; ?>')">🔑 授权管理</button>
                    <?php endif; ?>
                    <form method="post" autocomplete="off">
                        <input type="hidden" name="action" value="toggle_pin_class" autocomplete="off">
                        <input type="hidden" name="class_id" value="<?php echo $cid; ?>" autocomplete="off">
                        <button type="submit" class="dd-item" title="<?php echo $pinned ? '取消置顶' : '置顶该班级'; ?>">📌 <?php echo $pinned ? '取消置顶' : '置顶'; ?></button>
                    </form>
                    <?php if ($is_own): ?>
                    <button type="button" class="dd-item" onclick="doAction('copy', <?php echo $cid; ?>, '确认复制该班级（含学生名单）？')">📄 复制</button>
                    <button type="button" class="dd-item" onclick="pwdAction('reset', <?php echo $cid; ?>, '重置将清空该班级所有项目与登记数据（保留学生名单），不可恢复！')">♻️ 重置</button>
                    <?php endif; ?>
                    <?php if ($can_delete): ?>
                    <button type="button" class="dd-item dd-danger" onclick="pwdAction('delete', <?php echo $cid; ?>, '班级将移入回收站（其项目一并隐藏），可在回收站恢复或彻底删除，确认删除？')">🗑 删除</button>
                    <?php endif; ?>
                    <?php if ($exit_member_id > 0): ?>
                    <form method="post" onsubmit="return confirm('退出=移除该教师的授权成员身份，其将失去本班全部权限，确认执行？')" autocomplete="off">
                        <input type="hidden" name="action" value="remove_member" autocomplete="off">
                        <input type="hidden" name="member_id" value="<?php echo $exit_member_id; ?>" autocomplete="off">
                        <button type="submit" class="dd-item dd-danger" title="移除该教师的授权成员身份（等效于其退出本班）">🚪 退出</button>
                    </form>
                    <?php elseif ($can_exit): ?>
                    <button type="button" class="dd-item dd-danger" onclick="doAction('exit_class', <?php echo $cid; ?>, '退出后将失去该班级的访问权限（授权成员身份将一并移除），确认退出？')">🚪 退出</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

page_header('班级管理', 'classes.php');
?>
<?php if ($msg): ?><div class="alert alert-<?php echo $msg_type; ?>"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>


<?php if (!$embed): ?>
<div class="panel" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
    <?php if ($can_create_class): ?>
    <button type="button" class="btn" onclick="openModal('addModal')">＋ 新建班级</button>
    <button type="button" class="btn btn-outline" onclick="openModal('recycleModal')">🗑 回收站<?php echo $recycle_classes ? '（' . count($recycle_classes) . '）' : ''; ?></button>
    <?php endif; ?>
    <?php if ($feat_class_code): ?>
    <button type="button" class="btn btn-success" onclick="openModal('applyModal')">🔑 加入班级</button>
    <button type="button" class="hint-q" onclick="toggleHint(event, '<b>加入班级说明</b><br>· 点「🔑 加入班级」输入 8 位班级授权码提交申请，等待该班创建者审核<br>· 提交后申请显示在下方「⏳ 我的班级加入申请（待审核）」面板，审核前可随时撤销<br>· 审核通过后班级出现在「🤝 授权班级」选项卡，权限级别由创建者审核时指定（管理班级=除删除班级外全部权限 / 可建立=可建立修改删除项目 / 仅登记=只可登记已有项目 / 仅查看=只读，可看积分）')">?</button>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$embed && $my_pending_apps): ?>
<div class="panel" style="border-left:4px solid #e67e22;">
    <h3>⏳ 班级加入申请（待审核）</h3>
    <ul style="margin:5px 0 0 18px;color:#666;font-size:14px;list-style:none;padding-left:0;">
        <?php foreach ($my_pending_apps as $app): ?>
        <li style="margin-bottom:4px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <span>「<?php echo htmlspecialchars($app['class_name']); ?>」— 申请于 <?php echo htmlspecialchars($app['created_at']); ?>，等待班级创建者审核</span>
            <form method="post" style="display:inline;" onsubmit="return confirm('确认撤销该加入申请？撤销后可重新申请。')" autocomplete="off">
                <input type="hidden" name="action" value="cancel_join_app" autocomplete="off">
                <input type="hidden" name="member_id" value="<?php echo intval($app['id']); ?>" autocomplete="off">
                <button type="submit" class="btn btn-sm btn-danger">撤销申请</button>
            </form>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if (!$embed && $my_sent_transfers): ?>
<div class="panel" style="border-left:4px solid #8e44ad;">
    <h3>🔀 我发起的班级转移（待对方确认）</h3>
    <ul style="margin:5px 0 0 18px;color:#666;font-size:14px;list-style:none;padding-left:0;">
        <?php foreach ($my_sent_transfers as $tr): ?>
        <li style="margin-bottom:4px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <span>「<?php echo htmlspecialchars($tr['class_name']); ?>」→ <?php echo htmlspecialchars($tr['realname'] !== '' ? $tr['realname'] : $tr['username']); ?>（<?php echo htmlspecialchars(strval($tr['phone'])); ?>）— 发起于 <?php echo htmlspecialchars($tr['created_at']); ?></span>
            <form method="post" style="display:inline;" onsubmit="return confirm('确认撤销该转移申请？撤销后班级仍归您所有。')" autocomplete="off">
                <input type="hidden" name="action" value="cancel_transfer" autocomplete="off">
                <input type="hidden" name="transfer_id" value="<?php echo intval($tr['id']); ?>" autocomplete="off">
                <button type="submit" class="btn btn-sm btn-danger">撤销转移</button>
            </form>
        </li>
        <?php endforeach; ?>
    </ul>
    <p class="tip" style="margin:8px 0 0;">转移说明<button type="button" class="hint-q" onclick="toggleHint(event, '<b>班级转移说明</b><br>· 对方确认后班级正式转移（对方成为创建人）<br>· 您保留「管理班级」授权成员身份')">?</button></p>
</div>
<?php endif; ?>

<?php if ($recv_transfers): ?>
<div class="panel" style="border-left:4px solid #27ae60;">
    <h3>🔀 收到的班级转移请求（待我确认）</h3>
    <ul style="margin:5px 0 0 18px;color:#666;font-size:14px;list-style:none;padding-left:0;">
        <?php foreach ($recv_transfers as $tr): ?>
        <li style="margin-bottom:4px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <span>「<?php echo htmlspecialchars($tr['class_name']); ?>」— 来自 <?php echo htmlspecialchars($tr['realname'] !== '' ? $tr['realname'] : $tr['username']); ?>（<?php echo htmlspecialchars(strval($tr['phone'])); ?>）— 发起于 <?php echo htmlspecialchars($tr['created_at']); ?></span>
            <button type="button" class="btn btn-sm btn-success" onclick="openModal('transferAppModal<?php echo intval($tr['id']); ?>')">审批</button>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if (!$embed && $approve_apps && $feat_class_code): ?>
<div class="panel" style="border-left:4px solid #667eea;">
    <h3 style="cursor:pointer;user-select:none;" onclick="var l=document.getElementById('approveList');var c=l.style.display==='none';l.style.display=c?'':'none';this.querySelector('.ap-arrow').textContent=c?'▾':'▸';" title="点击展开 / 收起">📥 待我审批的加入申请（<?php echo count($approve_apps); ?>）<span class="ap-arrow" style="font-size:13px;color:#999;margin-left:6px;">▸</span></h3>
    <ul id="approveList" style="display:none;margin:5px 0 0 18px;color:#666;font-size:14px;list-style:none;padding-left:0;">
        <?php foreach ($approve_apps as $app): ?>
        <li style="margin-bottom:4px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <span>「<?php echo htmlspecialchars($app['class_name']); ?>」— <?php echo htmlspecialchars($app['realname'] !== '' ? $app['realname'] : $app['username']); ?>（<?php echo htmlspecialchars(strval($app['phone'])); ?>）— 申请于 <?php echo htmlspecialchars($app['created_at']); ?></span>
            <button type="button" class="btn btn-sm btn-success" onclick="openModal('authModal<?php echo intval($app['cid']); ?>')">审批</button>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php
// 选项卡显隐与默认项：我的=0 且授权>0 → 默认显示授权班级；我的=0 且无建班权限 → 隐藏「我的班级」选项卡
// 内嵌聚焦模式：隐藏选项卡栏，非空分组 pane 全部直接显示（聚焦过滤后至多一个分组非空）
$show_own_tab = ($own_classes || (!$embed && $can_create_class));
$cls_default = 'own';
if (!$show_own_tab) {
    $cls_default = $authed_classes ? 'authed' : '';
} elseif (!$own_classes && $authed_classes) {
    $cls_default = 'authed';
}
?>
<?php if ($embed && !$own_classes && !$authed_classes): ?>
<!-- 内嵌聚焦模式：聚焦班级不存在或无权访问 -->
<div class="panel" style="text-align:center;color:#999;">未找到该班级，或您暂无该班级的管理权限</div>
<?php elseif ($cls_default === '' && !$embed): ?>
<!-- 无任何可见班级且无建班权限：不渲染选项卡，仅提示 -->
<div class="panel" style="text-align:center;color:#999;">暂无班级，也暂无建班权限；可凭班级授权码申请加入他人班级</div>
<?php else: ?>
<!-- 三组选项卡：我的班级 / 授权班级 / 其他班级（仅管理员） -->
<?php if (!$embed): ?>
<div class="panel" style="padding:10px 20px;">
    <div class="ctx-tabs">
        <?php if ($show_own_tab): ?>
        <button type="button" class="ctx-tab<?php echo $cls_default === 'own' ? ' active' : ''; ?>" onclick="switchCtxTab(this,'cls','own')">📘 我的（<?php echo count($own_classes); ?>）</button>
        <?php endif; ?>
        <?php if ($authed_classes): ?>
        <button type="button" class="ctx-tab<?php echo $cls_default === 'authed' ? ' active' : ''; ?>" onclick="switchCtxTab(this,'cls','authed')">🤝 授权（<?php echo count($authed_classes); ?>）</button>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($show_own_tab): ?>
<div class="ctx-tabpane" data-group="cls" data-key="own"<?php echo ($cls_default === 'own' || $embed) ? '' : ' style="display:none;"'; ?>>
<div class="grid">
    <?php foreach ($own_classes as $c): render_class_card($c, 'own', in_array(intval($c['id']), $pinned_class_ids, true)); endforeach; ?>
    <?php if (empty($own_classes)): ?>
    <div class="panel" style="grid-column:1/-1;text-align:center;color:#999;">还没有自己创建的班级<?php echo $can_create_class ? '，点击上方「＋ 新建班级」创建' : ''; ?></div>
    <?php endif; ?>
</div>
</div>
<?php endif; ?>

<?php if ($authed_classes): ?>
<div class="ctx-tabpane" data-group="cls" data-key="authed"<?php echo $cls_default === 'authed' ? '' : ' style="display:none;"'; ?>>
<div class="grid">
    <?php foreach ($authed_classes as $c):
        $perm_name = $member_map[intval($c['id'])] ?? '';
        $tags = array_merge(
            $my_role_labels[intval($c['id'])] ?? [],
            $perm_name ? ['授权：' . $perm_names[$perm_name]] : []
        );
        render_class_card($c, 'authed', in_array(intval($c['id']), $pinned_class_ids, true), implode(' / ', array_unique($tags)));
    endforeach; ?>
</div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php if ($my_created && $feat_class_code): ?>
<?php foreach ($my_created as $c): $cid = intval($c['id']); ?>
<!-- 授权管理弹层（每班一个，从班级卡片右上角授权码标识打开；含授权码展示+复制、申请审核、成员权限） -->
<div class="modal-mask" id="authModal<?php echo $cid; ?>">
    <div class="modal" style="max-width:680px;">
        <h3>🔑 授权管理 - <?php echo htmlspecialchars($c['name']); ?></h3>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;border:1px solid #e3e6f0;border-radius:8px;padding:10px 14px;margin-bottom:14px;">
            授权码 <span class="badge badge-grade" style="background:#667eea;color:#fff;"><b style="font-size:16px;letter-spacing:2px;"><?php echo htmlspecialchars($c['class_code']); ?></b></span>
            <button type="button" class="btn btn-sm btn-outline" onclick="copyCode('<?php echo htmlspecialchars($c['class_code']); ?>')">复制授权码</button>
            <span style="color:#999;font-size:12px;">将授权码告知其他教师，对方凭码申请加入后在此审核</span>
        </div>

        <!-- 成员权限级别说明（小问号）：4 级权限完整说明，供审核选级与调整成员权限时参考 -->
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
            <b style="font-size:13px;">成员权限说明</b>
            <button type="button" class="hint-q" onclick="toggleHint(event, '<b>成员权限级别说明（审核与调整时参考）</b><br>· <b>管理员</b>（下拉中的「管理班级」）：最高权限，与创建者相同得到全部权限（除删除班级）；可使用积分、喊话，可退出班级（适合配班、副班主任）<br>· <b>可建立</b>：拥有建立项目（含题次）的管理权限（建立、修改、删除）；可使用积分、喊话，可退出班级；不可修改学生信息（适合其他学科课任教师）<br>· <b>仅登记</b>：没有建立项目（含题次）的管理权限，可对已有项目进行登记操作；可使用积分、喊话，可退出班级（适合课代表登记信息）<br>· <b>仅查看</b>：没有任何操作权限，只能查看信息和数据；可查看积分（不能加减积分和设置规则）、不能使用喊话、不能使用扫描识别相关功能，可退出班级（适合家委）<br>· 各级别均可使用统计、查看报表等数据查询功能')">?</button>
        </div>

        <?php if ($auth_data[$cid]['pending']): ?>
        <div style="margin-bottom:14px;">
            <b style="font-size:13px;color:#e67e22;">待审核申请（<?php echo count($auth_data[$cid]['pending']); ?>）</b>
            <table class="data-table" style="margin-top:6px;">
                <thead><tr><th>教师</th><th>手机号</th><th>申请时间</th><th style="width:290px;">操作</th></tr></thead>
                <tbody>
                    <?php foreach ($auth_data[$cid]['pending'] as $p): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($p['realname'] !== '' ? $p['realname'] : $p['username']); ?></td>
                        <td><?php echo htmlspecialchars(strval($p['phone'])); ?></td>
                        <td><?php echo htmlspecialchars($p['created_at']); ?></td>
                        <td>
                            <form method="post" style="display:inline-flex;gap:6px;align-items:center;" autocomplete="off">
                                <input type="hidden" name="action" value="approve_member" autocomplete="off">
                                <input type="hidden" name="member_id" value="<?php echo $p['id']; ?>" autocomplete="off">
                                <select name="perm" class="form-control" style="width:auto;padding:4px 8px;">
                                    <option value="manage">管理班级</option>
                                    <option value="create">可建立</option>
                                    <option value="register">仅登记</option>
                                    <option value="view" selected>仅查看</option>
                                </select>
                                <button type="submit" class="btn btn-sm btn-success">通过</button>
                            </form>
                            <form method="post" style="display:inline;" autocomplete="off">
                                <input type="hidden" name="action" value="reject_member" autocomplete="off">
                                <input type="hidden" name="member_id" value="<?php echo $p['id']; ?>" autocomplete="off">
                                <button type="submit" class="btn btn-sm btn-danger">拒绝</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div>
            <b style="font-size:13px;">授权成员名单（<?php echo count($auth_data[$cid]['members']); ?> 人）</b>
            <?php if ($auth_data[$cid]['members']): ?>
            <table class="data-table" style="margin-top:6px;">
                <thead><tr><th>教师</th><th>手机号</th><th>当前权限</th><th>加入时间</th><th style="width:240px;">操作</th></tr></thead>
                <tbody>
                    <?php foreach ($auth_data[$cid]['members'] as $m): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($m['realname'] !== '' ? $m['realname'] : $m['username']); ?></td>
                        <td><?php echo htmlspecialchars(strval($m['phone'])); ?></td>
                        <td>
                            <form method="post" style="display:inline-flex;gap:6px;align-items:center;" autocomplete="off">
                                <input type="hidden" name="action" value="set_member_perm" autocomplete="off">
                                <input type="hidden" name="member_id" value="<?php echo $m['id']; ?>" autocomplete="off">
                                <select name="perm" class="form-control" style="width:auto;padding:4px 8px;" onchange="this.form.submit()">
                                    <option value="manage" <?php echo $m['perm'] === 'manage' ? 'selected' : ''; ?>>管理班级</option>
                                    <option value="create" <?php echo $m['perm'] === 'create' ? 'selected' : ''; ?>>可建立</option>
                                    <option value="register" <?php echo $m['perm'] === 'register' ? 'selected' : ''; ?>>仅登记</option>
                                    <option value="view" <?php echo $m['perm'] === 'view' ? 'selected' : ''; ?>>仅查看</option>
                                </select>
                            </form>
                        </td>
                        <td><?php echo htmlspecialchars(substr(strval($m['handled_at']), 0, 10)); ?></td>
                        <td>
                            <form method="post" style="display:inline;" onsubmit="return confirm('确认移除该教师的授权？移除后其将失去本班全部权限。')" autocomplete="off">
                                <input type="hidden" name="action" value="remove_member" autocomplete="off">
                                <input type="hidden" name="member_id" value="<?php echo $m['id']; ?>" autocomplete="off">
                                <button type="submit" class="btn btn-sm btn-danger">移除</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="tip" style="margin-top:6px;">暂无授权成员</p>
            <?php endif; ?>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn btn-outline" onclick="closeModal('authModal<?php echo $cid; ?>')">关闭</button>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- 编辑班级弹层 -->
<div class="modal-mask" id="editModal">
    <div class="modal">
        <h3>编辑班级</h3>
        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="rename" autocomplete="off">
            <input type="hidden" name="class_id" id="edit_id" autocomplete="off">
            <div class="form-group">
                <label>班级名称</label>
                <input type="text" name="name" id="edit_name" class="form-control" required autocomplete="off">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeEdit()">取消</button>
                <button type="submit" class="btn">保存</button>
            </div>
        </form>
    </div>
</div>

<?php if ($can_create_class): ?>
<!-- 新建班级弹层（90% 分布式） -->
<div class="modal-mask" id="addModal">
    <div class="modal modal-lg">
        <h3>＋ 新建班级</h3>
        <div class="modal-lg-body">
            <form method="post" id="addClassForm" autocomplete="off">
                <input type="hidden" name="action" value="add" autocomplete="off">
                <div class="form-grid">
                    <div class="form-group">
                        <label>班级名称</label>
                        <input type="text" name="name" class="form-control" placeholder="例如：三年级2班" required autocomplete="off">
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-outline" onclick="closeModal('addModal')">取消</button>
            <button type="submit" class="btn" form="addClassForm">创建班级</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 凭授权码申请加入弹层（90% 分布式） -->
<?php if ($feat_class_code): ?>
<div class="modal-mask" id="applyModal">
    <div class="modal modal-lg">
        <h3>🔑 凭授权码申请加入他人班级</h3>
        <div class="modal-lg-body">
            <form method="post" id="applyClassForm" autocomplete="off">
                <input type="hidden" name="action" value="apply_class" autocomplete="off">
                <div class="form-grid">
                    <div class="form-group">
                        <label>班级授权码（8位数字）</label>
                        <input type="text" name="class_code" class="form-control" placeholder="例如：38274916" maxlength="8" pattern="\d{8}" required autocomplete="off">
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-outline" onclick="closeModal('applyModal')">取消</button>
            <button type="submit" class="btn btn-success" form="applyClassForm">提交加入申请</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 班级转移发起弹层（输入对方注册手机号，对方确认后正式转移） -->
<div class="modal-mask" id="transferModal">
    <div class="modal">
        <h3>🔀 转移班级 - <span id="transfer_name"></span><button type="button" class="hint-q" onclick="toggleHint(event, '<b>转移班级说明</b><br>· 输入对方注册手机号发起申请，对方在「班级列表」页确认后班级正式转移<br>· 转移后对方成为班级创建人<br>· 您保留「管理班级」授权成员身份（可在对方授权名单中移除）<br>· 确认前您可随时撤销')">?</button></h3>
        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="transfer_class" autocomplete="off">
            <input type="hidden" name="class_id" id="transfer_id" autocomplete="off">
            <div class="form-group">
                <label>对方注册手机号</label>
                <input type="text" name="phone" class="form-control" placeholder="请输入对方注册时的手机号" required autocomplete="off">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeModal('transferModal')">取消</button>
                <button type="submit" class="btn btn-success">发起转移申请</button>
            </div>
        </form>
    </div>
</div>

<?php foreach ($recv_transfers as $tr): $trid = intval($tr['id']); ?>
<!-- 收到的转移请求审批弹层（每条一个） -->
<div class="modal-mask" id="transferAppModal<?php echo $trid; ?>">
    <div class="modal">
        <h3>🔀 确认接收班级转移</h3>
        <div class="tip" style="line-height:1.9;">
            班级：<b><?php echo htmlspecialchars($tr['class_name']); ?></b><br>
            转出方：<?php echo htmlspecialchars($tr['realname'] !== '' ? $tr['realname'] : $tr['username']); ?>（<?php echo htmlspecialchars(strval($tr['phone'])); ?>）<br>
            发起时间：<?php echo htmlspecialchars($tr['created_at']); ?><br>
            确认后您成为该班级<b>创建人</b>（可管理名单/项目/授权），对方保留<b>「管理班级」</b>授权成员身份。
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-outline" onclick="closeModal('transferAppModal<?php echo $trid; ?>')">取消</button>
            <form method="post" style="display:inline;" onsubmit="return confirm('拒绝后该转移申请作废，对方仍为班级创建人。确认拒绝？')" autocomplete="off">
                <input type="hidden" name="action" value="reject_transfer" autocomplete="off">
                <input type="hidden" name="transfer_id" value="<?php echo $trid; ?>" autocomplete="off">
                <button type="submit" class="btn btn-danger">拒绝</button>
            </form>
            <form method="post" style="display:inline;" autocomplete="off">
                <input type="hidden" name="action" value="approve_transfer" autocomplete="off">
                <input type="hidden" name="transfer_id" value="<?php echo $trid; ?>" autocomplete="off">
                <button type="submit" class="btn btn-success">同意接收</button>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- 导入历史数据弹层（共享：班级级 5 列模板，项目不存在自动创建） -->
<div class="modal-mask" id="importModal">
    <div class="modal">
        <h3>📥 导入历史登记数据 - <span id="import_name"></span><button type="button" class="hint-q" onclick="toggleHint(event, '<b>导入历史登记数据说明</b><br>· 点「⬇ 下载模板」获取 CSV 模板，列顺序：<b>项目名称,编号,姓名,日期,评价值</b><br>· 评价值留空=仅登记不评价；日期留空=按当前时间；打卡项目日期必填（如 2026-09-01）<br>· 选择文件后点「开始导入」；项目不存在自动创建，已有登记自动跳过（可重复导入）')">?</button></h3>
        <div style="margin:12px 0;">
            <a id="import_tpl" class="btn btn-outline" style="text-decoration:none;" href="classes.php">⬇ 下载模板</a>
        </div>
        <form method="post" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="action" value="import_history" autocomplete="off">
            <input type="hidden" name="class_id" id="import_id" autocomplete="off">
            <div class="form-group">
                <label>选择 CSV/TXT 文件（≤5MB，UTF-8 或 GBK 编码）</label>
                <input type="file" name="import_file" accept=".csv,.txt" class="form-control" required autocomplete="off">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeModal('importModal')">取消</button>
                <button type="submit" class="btn btn-success">开始导入</button>
            </div>
        </form>
    </div>
</div>

<!-- 回收站弹层（软删除班级：恢复 / 彻底删除） -->
<div class="modal-mask" id="recycleModal">
    <div class="modal" style="max-width:760px;">
        <h3>🗑 班级回收站（<?php echo count($recycle_classes); ?>）<button type="button" class="hint-q" onclick="toggleHint(event, '<b>回收站说明</b><br>· 软删除的班级在此保留<br>· <b>恢复</b>=连同其项目与登记数据一并还原<br>· <b>彻底删除</b>=学生、项目与登记数据全部清除，<b>不可恢复</b>（需输入登录密码确认）')">?</button></h3>
        <?php if ($recycle_classes): ?>
        <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>班级</th><th>学生</th><th>项目</th><th>删除时间</th><th style="width:220px;">操作</th></tr></thead>
            <tbody>
                <?php foreach ($recycle_classes as $rc): ?>
                <tr>
                    <td><?php echo htmlspecialchars($rc['name']); ?></td>
                    <td><?php echo intval($rc['student_count']); ?></td>
                    <td><?php echo intval($rc['project_count']); ?></td>
                    <td><?php echo htmlspecialchars($rc['deleted_at']); ?></td>
                    <td>
                        <form method="post" style="display:inline;" autocomplete="off">
                            <input type="hidden" name="action" value="class_restore" autocomplete="off">
                            <input type="hidden" name="class_id" value="<?php echo intval($rc['id']); ?>" autocomplete="off">
                            <button type="submit" class="btn btn-sm btn-success">恢复</button>
                        </form>
                        <form method="post" style="display:inline;" autocomplete="off">
                            <input type="hidden" name="action" value="class_purge" autocomplete="off">
                            <input type="hidden" name="class_id" value="<?php echo intval($rc['id']); ?>" autocomplete="off">
                            <button type="button" class="btn btn-sm btn-danger" onclick="recyclePurge('class_purge', <?php echo intval($rc['id']); ?>, '彻底删除该班级？学生、项目与登记数据将全部清除且不可恢复！')">彻底删除</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
        <p class="tip">回收站为空</p>
        <?php endif; ?>
        <div class="modal-actions">
            <button type="button" class="btn btn-outline" onclick="closeModal('recycleModal')">关闭</button>
        </div>
    </div>
</div>

<form method="post" id="actionForm" autocomplete="off">
    <input type="hidden" name="action" id="act_action" autocomplete="off">
    <input type="hidden" name="class_id" id="act_id" autocomplete="off">
    <input type="hidden" name="login_pwd" id="act_pwd" autocomplete="off">
</form>

<!-- 敏感操作密码确认弹层（重置 / 删除 / 回收站彻底删除共用） -->
<div class="modal-mask" id="pwdModal">
    <div class="modal" style="max-width:420px;">
        <h3>🔒 敏感操作确认</h3>
        <p class="tip" style="margin-top:0;" id="pwd_desc">敏感操作确认<button type="button" class="hint-q" onclick="toggleHint(event, '<b>敏感操作确认</b><br>· 该操作为敏感操作，需输入您的登录密码确认后才会执行')">?</button></p>
        <div class="form-group">
            <label>登录密码</label>
            <input type="password" id="pwd_input" class="form-control" placeholder="输入当前账号的登录密码" onkeydown="if(event.key==='Enter'){event.preventDefault();submitPwd();}" autocomplete="off">
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-outline" onclick="closeModal('pwdModal')">取消</button>
            <button type="button" class="btn btn-danger" onclick="submitPwd()">确认执行</button>
        </div>
    </div>
</div>

<script>
var pwdCtx = null;
// 敏感操作：先 confirm 说明风险，再要求输入登录密码（服务端二次验证）
function pwdAction(action, id, confirmText) {
    if (!confirm(confirmText)) return;
    pwdCtx = { action: action, id: id };
    document.getElementById('pwd_input').value = '';
    openModal('pwdModal');
    setTimeout(function () { document.getElementById('pwd_input').focus(); }, 120);
}
// 回收站彻底删除：确认后同样走密码验证
function recyclePurge(action, id, confirmText) {
    pwdAction(action, id, confirmText);
}
function submitPwd() {
    var pwd = document.getElementById('pwd_input').value;
    if (!pwd) { showToast('请输入登录密码', 'error'); return; }
    if (!pwdCtx) return;
    document.getElementById('act_action').value = pwdCtx.action;
    document.getElementById('act_id').value = pwdCtx.id;
    document.getElementById('act_pwd').value = pwd;
    pwdCtx = null;
    closeModal('pwdModal');
    document.getElementById('actionForm').submit();
}
function openModal(id) {
    document.getElementById(id).classList.add('show');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}
// 点击遮罩空白处关闭
document.querySelectorAll('.modal-mask').forEach(function (m) {
    m.addEventListener('click', function (e) { if (e.target === m) m.classList.remove('show'); });
});
function openEdit(id, name) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('editModal').classList.add('show');
}
function closeEdit() {
    document.getElementById('editModal').classList.remove('show');
}
function openTransfer(id, name) {
    document.getElementById('transfer_id').value = id;
    document.getElementById('transfer_name').textContent = name;
    document.getElementById('transferModal').classList.add('show');
}
function doAction(action, id, confirmText) {
    if (!confirm(confirmText)) return;
    document.getElementById('act_action').value = action;
    document.getElementById('act_id').value = id;
    document.getElementById('actionForm').submit();
}
function copyCode(code) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(code).then(function () { showToast('授权码 ' + code + ' 已复制', 'success', true); });
    } else {
        prompt('请手动复制班级授权码：', code);
    }
}
// 卡片「管理」二级菜单开关 toggleDD 已全局化（includes/layout.php）
function openImport(id, name) {
    document.getElementById('import_id').value = id;
    document.getElementById('import_name').textContent = name;
    document.getElementById('import_tpl').href = 'classes.php?dl_template=class&class_id=' + id;
    openModal('importModal');
}
</script>
<?php page_help('班级管理', [
    ['h' => '页面结构', 'items' => [
        '班级分三个选项卡显示：<b>我的班级</b>（我创建的）、<b>授权的班级</b>（我加入的，绿色标识）、<b>其他班级</b>（仅管理员可见，按创建者分组）',
        '班级卡片显示：学生数 / 项目数、建于日期、最后更新时间；点击卡片直接进入该班项目列表',
        '📌 置顶的班级排在最前（置顶状态仅对本人生效）',
    ]],
    ['h' => '新建与加入', 'items' => [
        '<b>＋ 新建班级</b>：填写班级名与年段创建，创建后自动生成 8 位班级授权码（个人账号默认可选年级）',
        '<b>🔑 加入班级</b>：输入他人分享的 8 位班级授权码申请加入，需班级创建者审核通过',
    ]],
    ['h' => '卡片按钮与「⚙ 管理」菜单', 'items' => [
        '卡片按钮：<b>进入</b>（打开该班项目列表）、<b>📊 查看报表</b>（打开班级统计中心）；其余功能收在 <b>⚙ 管理</b> 二级菜单',
        '<b>⚙ 管理</b>菜单：<b>学生名单</b>（弹窗管理学生）、<b>生成二维码</b>、<b>导入历史数据</b>（按模板 CSV 批量导入历史登记）、<b>编辑</b>',
        '<b>转移班级 / 授权管理</b>：转移需对方确认；授权管理可审核加入申请、设置成员权限、分享授权码。成员权限 4 级：<b>管理员</b>=与创建者相同（除删除班级）；<b>可建立</b>=可建立/修改/删除项目（含题次），不可改学生信息；<b>仅登记</b>=只可对已有项目登记；<b>仅查看</b>=只读（可看积分，不能加减/设规则，不能喊话与扫描识别）；各级均可查看统计与报表',
        '<b>置顶 / 复制</b>：置顶仅对本人生效；复制创建含学生名单的副本',
        '<b>♻️ 重置 / 🗑 删除（敏感操作）</b>：需输入登录密码确认后才会执行；重置清空项目与登记数据（学生保留），删除移入回收站',
        '<b>回收站彻底删除</b>：学生、项目与登记数据全部清除且不可恢复，同样需要输入登录密码确认',
    ]],
    ['h' => '回收站', 'items' => [
        '软删除的班级在此保留：<b>恢复</b> = 连同其项目与登记数据一并还原；<b>彻底删除</b> = 学生、项目与登记数据全部清除，不可恢复',
    ]],
]);
page_footer(); ?>
