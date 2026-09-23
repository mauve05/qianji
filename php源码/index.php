<?php
/**
 * 首页 = 登录页（未登录访问任何页面均跳转至此）
 *  - 未安装（配置文件未生成 / 连不上数据库 / 无数据表）自动跳转 install.php 安装向导
 *  - 「使用说明」按钮弹窗展示详细使用说明
 *  - 「保存密码」勾选后本机记住账号密码，下次自动填入（localStorage）
 *  - 「保持登录」勾选后签发30天免登录令牌（HttpOnly cookie + login_tokens 表）
 */
session_start();

// 配置文件未生成（尚未运行安装向导）：直接进入安装页
if (!file_exists(__DIR__ . '/includes/db_config.php')) {
    header("Location: install.php");
    exit();
}

require_once 'includes/db_config.php';
require_once 'includes/functions.php';

$error = '';
$conn = @mysqli_connect($db_host, $db_user, $db_pass);
$installed = false;
if ($conn) {
    mysqli_set_charset($conn, 'utf8mb4');
    $res = @mysqli_query($conn, "SHOW TABLES FROM `{$db_name}` LIKE 'teachers'");
    $installed = $res && mysqli_num_rows($res) > 0;
}
// 未安装：自动跳转安装向导
if (!$installed) {
    header("Location: install.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = check_input($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($phone === '' || $password === '') {
        $error = '请输入手机号和密码';
    } else {
        $conn = getConnection();
        // 手机号即登录账号（存量账号已回填 phone = 原登录账号）
        $stmt = mysqli_prepare($conn, "SELECT id, username, password, realname, disabled FROM teachers WHERE phone = ?");
        mysqli_stmt_bind_param($stmt, "s", $phone);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $teacher = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if ($teacher && intval($teacher['disabled']) === 1) {
            $error = '该账号已被禁用，请联系管理员';
        } elseif ($teacher && password_verify($password, $teacher['password'])) {
            // 保持登录（30天）：签发免登录令牌
            if (!empty($_POST['remember'])) {
                rememberme_issue($conn, intval($teacher['id']));
            }
            session_regenerate_id(true);
            $_SESSION['teacher_id'] = $teacher['id'];
            $_SESSION['teacher_name'] = $teacher['realname'] !== '' ? $teacher['realname'] : $teacher['username'];
            login_track($conn, intval($teacher['id']));   // 登录活跃统计（个人中心「登录天数/累计登录」）
            header("Location: classes.php");
            exit();
        } else {
            $error = '手机号或密码错误';
        }
    }
} else {
    // 已有30天免登录令牌：自动登录
    if (!isset($_SESSION['teacher_id']) || empty($_SESSION['teacher_id'])) {
        if (rememberme_try_login($conn ?: getConnection())) {
            login_track($conn ?: getConnection(), intval($_SESSION['teacher_id'] ?? 0));   // 免登录自动登录也计入活跃
            header("Location: classes.php");
            exit();
        }
    }
}

if (!isset($conn) || !$conn) {
    $conn = getConnection();
}

if (isset($_SESSION['teacher_id']) && !empty($_SESSION['teacher_id'])) {
    header("Location: classes.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - 潜记二维码作业登记系统</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="icon" href="favicon.ico" type="image/x-icon" />
    <link rel="shortcut icon" href="favicon.ico"/>
</head>
<body>
<div class="page-hero">
    <div class="container" style="max-width:420px;">
        <div class="header">
            <h1>潜记二维码作业登记</h1>
            <p>一人一码 · 秒扫码 · 快统计 · 细跟踪</p>
        </div>
        <div class="card">
            <?php if ($error): ?><div class="alert alert-error"><?php echo $error; ?></div><?php endif; ?>
            <form method="post" id="loginForm">
                <div class="form-group">
                    <label for="phone">手机号（登录账号）</label>
                    <input type="text" id="phone" name="phone" class="form-control" placeholder="请输入注册手机号" required autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="password">密码</label>
                    <input type="password" id="password" name="password" class="form-control" placeholder="请输入密码" required autocomplete="current-password">
                </div>
                <div class="form-group" style="display:flex;gap:22px;align-items:center;font-size:14px;">
                    <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;margin:0;" title="勾选后本机浏览器记住账号和密码，下次打开登录页自动填入（仅保存在本机）">
                        <input type="checkbox" id="savepwd" style="accent-color:#667eea;width:15px;height:15px;" autocomplete="off"> 保存密码
                    </label>
                    <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;margin:0;" title="勾选后30天内打开系统无需重新输入密码登录">
                        <input type="checkbox" id="remember" name="remember" value="1" style="accent-color:#667eea;width:15px;height:15px;" autocomplete="off"> 保持登录（30天）
                    </label>
                </div>
                <button type="submit" class="btn btn-block">登 录</button>
            </form>
            <script>
            (function () {
                var form = document.getElementById('loginForm');
                var sp = document.getElementById('savepwd');
                var phone = document.getElementById('phone');
                var pass = document.getElementById('password');
                var KEY = 'qj_save_login';
                // 载入：已保存密码则自动填入并勾选
                try {
                    var raw = localStorage.getItem(KEY);
                    if (raw) {
                        var saved = JSON.parse(raw);
                        if (saved && saved.p) {
                            phone.value = saved.p;
                            if (saved.w) pass.value = decodeURIComponent(escape(atob(saved.w)));
                            sp.checked = true;
                        }
                    }
                } catch (e) {}
                form.addEventListener('submit', function () {
                    try {
                        if (sp.checked) {
                            localStorage.setItem(KEY, JSON.stringify({ p: phone.value, w: btoa(unescape(encodeURIComponent(pass.value))) }));
                        } else {
                            localStorage.removeItem(KEY);
                        }
                    } catch (e) {}
                });
                // 取消勾选即清除已保存密码
                sp.addEventListener('change', function () {
                    if (!sp.checked) { try { localStorage.removeItem(KEY); } catch (e) {} }
                });
            })();
            </script>
            <p class="tip" style="text-align:center;margin-top:15px;">
                <a href="forgot_password.php" style="color:#667eea;">忘记密码？</a>
                &nbsp;|&nbsp;
                还没有账号？<a href="register.php" style="color:#667eea;">立即注册</a>
                &nbsp;|&nbsp;
                <a href="javascript:void(0);" onclick="document.getElementById('helpModal').classList.add('show')" style="color:#667eea;">使用说明</a>
                &nbsp;|&nbsp;
                <a href="https://ziyuan.yunketang100.com/qj/" target="_blank" style="color:#667eea;">访问主站</a>
            </p>
        </div>
        <div class="footer">&copy; <?php echo date('Y'); ?> 潜记二维码作业登记系统</div>
    </div>
</div>

<!-- 使用说明弹窗 -->
<div class="modal-mask" id="helpModal">
    <div class="modal" style="max-width:640px;max-height:80vh;overflow-y:auto;">
        <h3>使用说明</h3>
        <div class="tip" style="line-height:1.9;font-size:14px;">
            <p><b>【作业登记】</b>—— 解决作业登记 + 日常评价采集<br>
            ① 正常批改作业，边改边按 优、良、合格、退回 分堆；<br>
            ② 作业改后打开手机摄像头，点一下当前评价「优」，一本或多本从手机面前扫过（自动记录登记和评价）；<br>
            ③ 看统计表（谁没交、优、良各多少）。</p>

            <p style="margin-top:12px;"><b>【举牌答题】</b>—— 解决常态课堂活动<br>
            ① 教师出四选一选择题（书本、课件、口述均可）；<br>
            ② 发出命令「请举牌」，学生举牌；<br>
            ③ 教师手机摄像头环视一个来回；<br>
            ④ 查看答题情况，进一步教学。</p>

            <p style="margin-top:12px;"><b>【答题卡】</b>—— 解决答题批阅<br>
            ① 设计答题卡，可选设置正确答案，打印答题卡；<br>
            ② 学生做题填涂；<br>
            ③ 高拍仪或手机识别，自动登记答题情况；<br>
            ④ 查看答题情况，进一步教学。</p>

            <p style="margin-top:12px;"><b>【快速上手】</b><br>
            ① 注册登录（个人账号免费）；<br>
            ② 新建班级；<br>
            ③ 添加学生并打印二维码（支持粘贴名单批量导入，一人一码，贴在作业本上）；<br>
            ④ 新建项目，选评价方式（笑脸 / 十分 / 数值 / 优良 / 星级 / 评语）与登记模式（单次 / 多次 / 打卡 / 举牌 / 答题卡）。</p>

            <p style="margin-top:12px;"><b>【更多功能】</b><br>
            · 统计报表：每次登记情况、学生个人历史、打卡日历，班级和项目支持 📌 置顶；<br>
            · 协作管理：班级生成 8 位授权码，同事申请后你审核通过即可共同管理；<br>
            · 喊话 / 积分：大屏喊话、课堂表现积分与礼品兑换；<br>
            · 手机、平板、电脑都能用；扫码时同一个码不会重复登记，摄像头不可用可点【图片识别】上传照片；<br>
            · 手机扫码请用 HTTPS 访问（浏览器安全限制），电脑端不受影响。</p>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn" onclick="document.getElementById('helpModal').classList.remove('show')">我知道了</button>
        </div>
    </div>
</div>
</body>
</html>
