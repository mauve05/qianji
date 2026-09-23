<?php
/**
 * 登录鉴权文件（教师端）
 * 未登录则跳转到首页（登录页）；账号被禁用则强制退出
 * 支持「保持登录（30天）」：会话不存在时凭免登录令牌自动恢复会话
 */
session_start();

if (!isset($_SESSION['teacher_id']) || empty($_SESSION['teacher_id'])) {
    require_once __DIR__ . '/db_config.php';
    require_once __DIR__ . '/functions.php';
    $remember_ok = false;
    if (($_COOKIE['qj_remember'] ?? '') !== '') {
        $conn_rm = getConnection();
        $remember_ok = rememberme_try_login($conn_rm);
    }
    if (!$remember_ok) {
        header("Location: index.php");
        exit();
    }
}

require_once __DIR__ . '/db_config.php';
$conn_auth = getConnection();
if (is_teacher_disabled($conn_auth, intval($_SESSION['teacher_id']))) {
    require_once __DIR__ . '/functions.php';
    rememberme_clear($conn_auth);   // 账号被禁用：连带清除免登录令牌
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit();
}

$current_teacher_id = intval($_SESSION['teacher_id']);
?>
