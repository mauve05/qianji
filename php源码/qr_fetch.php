<?php
/**
 * 班级导入二维码内容提取端点（公开访问，无需登录——扫码设备可能未登录）
 * 用法：qr_fetch.php?c={8位提取码}
 * 返回：bjdl 明文（潜记格式：编号-姓名-备注|...），供扫码方直接提取；
 *       浏览器打开则展示名单内容（可复制后到"学生名单→扫码导入班级码"粘贴导入）。
 */
require_once 'includes/db_config.php';
require_once 'includes/functions.php';

$conn = getConnection();
$code = preg_replace('/[^0-9A-Za-z]/', '', $_GET['c'] ?? '');

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

if ($code === '') {
    echo '缺少提取码：qr_fetch.php?c={提取码}';
    exit();
}

$stmt = mysqli_prepare($conn, "SELECT content FROM import_payloads WHERE code = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "s", $code);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$row || $row['content'] === '') {
    echo '提取码无效或名单为空';
    exit();
}

echo $row['content'];
