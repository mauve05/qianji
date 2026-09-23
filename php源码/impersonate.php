<?php
/**
 * 模拟登录（仅总管理员）
 *  - start: 以指定用户身份登录（后台「全部用户管理」的 🎭 模拟按钮发起）
 *  - exit:  退出模拟，恢复总管理员身份
 * 模拟期间页面顶部显示橙色横幅，可随时退出；所有操作均以被模拟用户身份执行
 */
session_start();
require_once 'includes/db_config.php';
require_once 'includes/functions.php';

$conn = getConnection();
$teacher_id = intval($_SESSION['teacher_id'] ?? 0);

// 退出模拟：恢复原总管理员身份（无需再校验权限，凭会话中的 impersonator_id）
$action = $_GET['action'] ?? '';
if ($action === 'exit') {
    if (!empty($_SESSION['impersonator_id'])) {
        $orig_id = intval($_SESSION['impersonator_id']);
        $stmt = mysqli_prepare($conn, "SELECT realname, username FROM teachers WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $orig_id);
        mysqli_stmt_execute($stmt);
        $orig = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        session_regenerate_id(true);
        unset($_SESSION['impersonator_id'], $_SESSION['impersonator_name']);
        $_SESSION['teacher_id'] = $orig_id;
        $_SESSION['teacher_name'] = $orig ? ($orig['realname'] !== '' ? $orig['realname'] : $orig['username']) : '';
    }
    header("Location: admin.php");
    exit();
}

// 发起模拟：必须是总管理员本人（非模拟状态）
if (!is_super_admin($conn, $teacher_id) || !empty($_SESSION['impersonator_id'])) {
    header("Location: index.php");
    exit();
}

$tid = intval($_GET['user_id'] ?? 0);
$stmt = mysqli_prepare($conn, "SELECT id, realname, username, disabled, is_admin FROM teachers WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $tid);
mysqli_stmt_execute($stmt);
$target = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$target || $tid === $teacher_id || intval($target['is_admin']) === 1 || intval($target['disabled']) === 1) {
    header("Location: admin.php");
    exit();
}

session_regenerate_id(true);
$_SESSION['impersonator_id'] = $teacher_id;
$_SESSION['impersonator_name'] = $_SESSION['teacher_name'] ?? '';
$_SESSION['teacher_id'] = intval($target['id']);
$_SESSION['teacher_name'] = $target['realname'] !== '' ? $target['realname'] : $target['username'];
header("Location: classes.php");
exit();
