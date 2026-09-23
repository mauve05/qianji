<?php
/**
 * 潜记二维码作业登记系统（单用户版）- 数据库配置（由 install.php 安装向导自动生成）
 *
 * 注意：本文件含数据库密码，已被 .gitignore 排除，请勿提交到代码仓库。
 * 如需手工修改连接参数，直接编辑本文件保存即可。
 */

// 防缓存：代码更新/数据变更后浏览器立即取新页面（统计弹层等实时内容不被磁盘缓存）
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// 数据库连接参数
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '000000';
$db_name = 'qj_registration_single';

// 创建数据库连接
function getConnection() {
    global $db_host, $db_user, $db_pass, $db_name;
    $conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);

    if (!$conn) {
        die("数据库连接失败: " . mysqli_connect_error());
    }

    mysqli_set_charset($conn, "utf8mb4");   // utf8mb4：支持 emoji 等四字节字符（评价选项/评语/喊话内容）
    return $conn;
}

// 安全过滤函数
function check_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// 账号是否被禁用（auth.php 在 functions.php 之前加载，故定义于此；禁用后无法登录，已有会话在下次请求时强制退出）
if (!function_exists('is_teacher_disabled')) {
    function is_teacher_disabled($conn, $teacher_id) {
        static $disabled_cache = [];
        $tid = intval($teacher_id);
        if (isset($disabled_cache[$tid])) return $disabled_cache[$tid];
        $stmt = mysqli_prepare($conn, "SELECT disabled FROM teachers WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $tid);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        return $disabled_cache[$tid] = $row && intval($row['disabled']) === 1;
    }
}

// 获取客户端真实IP
if (!function_exists('get_client_ip')) {
    function get_client_ip() {
        $keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        foreach ($keys as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '';
    }
}