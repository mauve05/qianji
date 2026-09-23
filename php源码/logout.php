<?php
/**
 * 退出登录（会话销毁 + 清除「保持登录」令牌与 cookie）
 */
session_start();
require_once __DIR__ . '/includes/db_config.php';
require_once __DIR__ . '/includes/functions.php';
rememberme_clear(getConnection());
$_SESSION = [];
session_destroy();
header("Location: index.php");
exit();
?>
