<?php
/**
 * 密码找回（两步）
 *  1. 手机号 + 姓名 验证身份
 *  2. 设置新密码
 */
session_start();
require_once 'includes/db_config.php';

$error = '';
$success = '';
$step = 1; // 1=验证身份 2=设置新密码
$conn = getConnection();

// 取消找回，清理会话状态
if (isset($_GET['cancel'])) {
    unset($_SESSION['fp_uid']);
    header("Location: forgot_password.php");
    exit();
}

if (isset($_SESSION['fp_uid']) && intval($_SESSION['fp_uid']) > 0) {
    $uid = intval($_SESSION['fp_uid']);
    $stmt = mysqli_prepare($conn, "SELECT id FROM teachers WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $uid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    if (mysqli_stmt_num_rows($stmt) > 0) {
        $step = 2;
    } else {
        unset($_SESSION['fp_uid']);
    }
    mysqli_stmt_close($stmt);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fp_action = $_POST['fp_action'] ?? '';

    // ===== 第一步：手机号 + 姓名 =====
    if ($fp_action === 'verify') {
        $phone = check_input($_POST['phone'] ?? '');
        $realname = check_input($_POST['realname'] ?? '');
        if ($phone === '' || $realname === '') {
            $error = '请输入手机号和姓名';
        } else {
            $stmt = mysqli_prepare($conn, "SELECT id, realname FROM teachers WHERE phone = ? AND phone != ''");
            mysqli_stmt_bind_param($stmt, "s", $phone);
            mysqli_stmt_execute($stmt);
            $t = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$t || $t['realname'] === '' || $t['realname'] !== $realname) {
                $error = '手机号或姓名不匹配（姓名需与注册时填写的一致）';
            } else {
                $_SESSION['fp_uid'] = intval($t['id']);
                $step = 2;
            }
        }
    }
    // ===== 第二步：设置新密码 =====
    elseif ($fp_action === 'reset' && isset($_SESSION['fp_uid'])) {
        $uid = intval($_SESSION['fp_uid']);
        $password = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';
        if (mb_strlen($password) < 6) {
            $error = '密码长度不能少于 6 位';
            $step = 2;
        } elseif ($password !== $password2) {
            $error = '两次输入的密码不一致';
            $step = 2;
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, "UPDATE teachers SET password = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "si", $hash, $uid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            unset($_SESSION['fp_uid']);
            $success = '密码已重置成功，请使用手机号和新密码登录';
            $step = 0; // 完成
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>找回密码 - 潜记二维码作业登记系统</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="page-hero">
    <div class="container" style="max-width:420px;">
        <div class="header">
            <h1>找回密码</h1>
            <p>潜记二维码作业登记系统</p>
        </div>
        <div class="card">
            <?php if ($error): ?><div class="alert alert-error"><?php echo $error; ?></div><?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <a href="index.php" class="btn btn-block">前往登录</a>
            <?php elseif ($step === 1): ?>
                <p class="tip" style="margin-bottom:15px;">请输入注册时填写的手机号和姓名验证身份。</p>
                <form method="post" autocomplete="off">
                    <input type="hidden" name="fp_action" value="verify" autocomplete="off">
                    <div class="form-group">
                        <label for="phone">手机号</label>
                        <input type="text" id="phone" name="phone" class="form-control" placeholder="注册手机号" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="realname">姓名</label>
                        <input type="text" id="realname" name="realname" class="form-control" placeholder="注册/后台登记的姓名" required autocomplete="off">
                    </div>
                    <button type="submit" class="btn btn-block">下一步</button>
                </form>
            <?php elseif ($step === 2): ?>
                <p class="tip" style="margin-bottom:15px;">验证通过，请设置新密码。</p>
                <form method="post" autocomplete="off">
                    <input type="hidden" name="fp_action" value="reset" autocomplete="off">
                    <div class="form-group">
                        <label for="password">新密码</label>
                        <input type="password" id="password" name="password" class="form-control" placeholder="不少于6位" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="password2">确认新密码</label>
                        <input type="password" id="password2" name="password2" class="form-control" placeholder="再次输入新密码" required autocomplete="off">
                    </div>
                    <button type="submit" class="btn btn-block">重置密码</button>
                </form>
                <p class="tip" style="text-align:center;margin-top:15px;">
                    <a href="forgot_password.php?cancel=1" style="color:#667eea;">取消找回</a>
                </p>
            <?php endif; ?>
            <p class="tip" style="text-align:center;margin-top:15px;">
                <a href="index.php" style="color:#667eea;">返回登录</a>
            </p>
        </div>
        <div class="footer">&copy; <?php echo date('Y'); ?> 潜记二维码作业登记系统</div>
    </div>
</div>
</body>
</html>
