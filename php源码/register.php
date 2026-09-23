<?php
/**
 * 教师注册（单用户版）
 * 注册为普通教师账号（个人账号模式：自己创建班级与项目，也可凭班级授权码协作）
 * 是否允许注册由后台「注册开关」控制（settings: 0_allow_register）
 */
session_start();
require_once 'includes/db_config.php';
require_once 'includes/functions.php';

$error = '';
$success = '';

$conn = getConnection();

$allow_register = get_setting($conn, 'allow_register', '1', 0) === '1';
$reg_closed_tip = '平台已关闭教师自主注册，请联系管理员开通账号';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$allow_register) {
        $error = $reg_closed_tip;
    } else {
    $phone = check_input($_POST['phone'] ?? '');
    $realname = check_input($_POST['realname'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    if (!preg_match('/^[0-9+\-]{5,20}$/', $phone)) {
        $error = '请输入正确的手机号（5-20位数字）';
    } elseif (mb_strlen($password) < 6) {
        $error = '密码长度不能少于 6 位';
    } elseif ($password !== $password2) {
        $error = '两次输入的密码不一致';
    } else {
        $conn = getConnection();
        $stmt = mysqli_prepare($conn, "SELECT id FROM teachers WHERE phone = ? AND phone != ''");
        mysqli_stmt_bind_param($stmt, "s", $phone);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        if (mysqli_stmt_num_rows($stmt) > 0) {
            $error = '该手机号已被其他账号使用';
        } else {
            mysqli_stmt_close($stmt);
            $hash = password_hash($password, PASSWORD_DEFAULT);
            // 手机号即登录账号（username 与 phone 保持一致）；注册账号均为普通教师
            $stmt = mysqli_prepare($conn, "INSERT INTO teachers (username, password, realname, school_id, phone, is_admin, created_at) VALUES (?, ?, ?, 1, ?, 0, NOW())");
            mysqli_stmt_bind_param($stmt, "ssss", $phone, $hash, $realname, $phone);
            if (mysqli_stmt_execute($stmt)) {
                $success = '注册成功，请登录';
            } else {
                $error = '注册失败，请稍后重试';
            }
        }
        mysqli_stmt_close($stmt);
    }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>注册 - 潜记二维码作业登记系统</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="page-hero">
    <div class="container" style="max-width:420px;">
        <div class="header">
            <h1>注册新账号</h1>
            <p>潜记二维码作业登记系统</p>
        </div>
        <div class="card">
            <?php if (!$allow_register): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($reg_closed_tip); ?>。</div>
                <a href="index.php" class="btn btn-block">返回登录</a>
            <?php else: ?>
            <?php if ($error): ?><div class="alert alert-error"><?php echo $error; ?></div><?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <a href="index.php" class="btn btn-block">前往登录</a>
            <?php else: ?>
                <form method="post" autocomplete="off">
                    <div class="form-group">
                        <label for="phone">手机号（登录账号）</label>
                        <input type="text" id="phone" name="phone" class="form-control" placeholder="手机号将作为登录账号" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="realname">教师姓名（选填）</label>
                        <input type="text" id="realname" name="realname" class="form-control" placeholder="将显示在顶部导航栏" value="<?php echo htmlspecialchars($_POST['realname'] ?? ''); ?>" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="password">密码</label>
                        <input type="password" id="password" name="password" class="form-control" placeholder="不少于6位" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="password2">确认密码</label>
                        <input type="password" id="password2" name="password2" class="form-control" placeholder="再次输入密码" required autocomplete="off">
                    </div>
                    <button type="submit" class="btn btn-block">注 册</button>
                </form>
                <p class="tip" style="text-align:center;margin-top:15px;">
                    已有账号？<a href="index.php" style="color:#667eea;">直接登录</a>
                </p>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="footer">&copy; <?php echo date('Y'); ?> 潜记二维码作业登记系统</div>
    </div>
</div>
</body>
</html>
