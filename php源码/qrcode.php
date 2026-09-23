<?php
/**
 * 二维码生成页
 *  - 班级导入二维码：名单内容入库（import_payloads），二维码只编码提取链接（支持大名单），并显示 8 位提取码
 *    （兼容潜记APP"扫码导入名单"：扫码打开链接即提取 bjdl 明文；本系统"学生名单→扫码导入班级码"可扫码或输入提取码）
 *  - 个人二维码：一人一码 djxh+编号，可打印裁剪后贴在作业本上
 */
require_once 'includes/db_config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/layout.php';

$conn = getConnection();

$class_id = intval($_GET['class_id'] ?? 0);
$stmt = mysqli_prepare($conn, "SELECT id, name FROM classes WHERE id = ? AND deleted_at IS NULL");
mysqli_stmt_bind_param($stmt, "i", $class_id);
mysqli_stmt_execute($stmt);
$class = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$class || !can_view_class($conn, $current_teacher_id, $class_id)) {
    header("Location: classes.php");
    exit();
}

// 学生列表
$stmt = mysqli_prepare($conn, "SELECT * FROM students WHERE class_id = ? ORDER BY CAST(seat_no AS UNSIGNED) ASC, id ASC");
mysqli_stmt_bind_param($stmt, "i", $class_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$students = [];
while ($row = mysqli_fetch_assoc($result)) {
    $students[] = $row;
}
mysqli_stmt_close($stmt);

// 名单入库，生成提取链接（每班一个稳定提取码，内容随名单刷新）
$payload = null;
$import_url = '';
if (!empty($students)) {
    $payload = get_class_import_payload($conn, $class_id, $students);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    $import_url = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim($dir, '/') . '/qr_fetch.php?c=' . $payload['code'];
}

page_header('二维码生成 - ' . $class['name'], 'classes.php');
?>
<a href="students.php?class_id=<?php echo $class_id; ?>" style="display:inline-block;margin-bottom:15px;color:#667eea;text-decoration:none;">← 返回学生管理</a>

<div class="panel">
    <h3 style="cursor:pointer;user-select:none;display:flex;align-items:center;gap:6px;" onclick="toggleImportQr()" title="点击展开/收起">
        <span id="impqr_arrow" style="font-size:12px;color:#999;">▶</span> 班级导入二维码<span class="form-hint" style="font-weight:normal;">（默认收起，点击展开）</span>
    </h3>
    <div id="impqr_body" style="display:none;">
    <p class="tip">班级码导入说明<button type="button" class="hint-q" onclick="toggleHint(event, '<b>班级导入二维码说明</b><br>· 名单内容存入数据库，二维码只编码提取链接（学生再多也能清晰扫码）<br>· 也可凭下方<b>提取码</b>在「学生名单 → 扫码导入班级码」中直接导入')">?</button></p>
    <?php if (empty($students)): ?>
        <div class="alert alert-info">该班级暂无学生，请先<a href="students.php?class_id=<?php echo $class_id; ?>" style="color:#667eea;">导入学生名单</a>。</div>
    <?php else: ?>
    <div class="qr-center">
        <div id="qrcode_class" class="qrcode"></div>
        <div class="qr-text" id="class_qr_text"><?php echo htmlspecialchars($import_url); ?></div>
        <div style="margin:10px 0;display:flex;gap:10px;justify-content:center;align-items:center;flex-wrap:wrap;">
            <span style="font-size:15px;color:#666;">提取码</span>
            <b id="import_code" style="font-size:26px;letter-spacing:4px;color:#667eea;font-family:Consolas,monospace;"><?php echo htmlspecialchars($payload['code']); ?></b>
            <button type="button" class="btn btn-sm btn-outline" onclick="copyImportCode()">复制提取码</button>
            <a href="<?php echo htmlspecialchars($import_url); ?>" target="_blank" class="btn btn-sm btn-outline">预览提取链接</a>
        </div>
        <p class="tip">导入方式说明<button type="button" class="hint-q" onclick="toggleHint(event, '<b>两种导入方式</b><br>· ①【潜记】APP：进入「班级」-「名单」后，点击右上方图标，选择「扫码导入名单」后扫描上方二维码即可导入<br>· ② 本系统：在目标班级的「学生名单 → 扫码导入班级码」中扫描上方二维码，或直接输入 8 位提取码，选择「附加导入」或「覆盖导入」')">?</button></p>
    </div>
    <?php endif; ?>
    </div>
</div>

<div class="panel">
    <h3>学生个人二维码（一人一码）</h3>
    <p class="tip">个人码使用说明<button type="button" class="hint-q" onclick="toggleHint(event, '<b>学生个人二维码说明</b><br>· 打印后裁剪，贴在每个学生的作业本上<br>· 作业批改后在「扫码登记」中扫描即可完成登记（也可用【潜记】APP扫描，编码格式 djxh+编号 兼容）<br>· <b>「个人单页」</b>列可对单个学生直接打印 / 导出 PDF / 导出 Word 其整页重复二维码（新窗口打开，自动执行）')">?</button></p>
    <div class="toolbar">
        <a href="print.php?class_id=<?php echo $class_id; ?>&type=1" target="_blank" class="btn btn-sm">打印全班二维码（表格版）</a>
        <a href="print.php?class_id=<?php echo $class_id; ?>&type=2" target="_blank" class="btn btn-sm">打印个人单页二维码</a>
        <?php if (get_quizraise_feature($conn, current_school_id($conn), 'raise')): // 举牌模式开关（管理员后台）：关闭即隐藏举牌卡打印入口
        // 举牌卡可行性预检（与 quiz_cards.php 生成端同口径）：座号需 1-255 自然数且不为空、不重复；禁用学生不打印
        $raise_bad = [];
        $raise_seen = [];
        foreach ($students as $rs) {
            if (!empty($rs['disabled'])) continue;
            $rsn = trim((string)$rs['seat_no']);
            if ($rsn === '' || (string)intval($rsn) !== $rsn || intval($rsn) < 1 || intval($rsn) > 255) {
                $raise_bad[] = $rs['name'] . '（座号：' . ($rsn === '' ? '空' : $rsn) . '）';
            } elseif (isset($raise_seen[$rsn])) {
                $raise_bad[] = $rs['name'] . '（座号 ' . $rsn . ' 与「' . $raise_seen[$rsn] . '」重复）';
            } else {
                $raise_seen[$rsn] = $rs['name'];
            }
        }
        ?>
        <a href="quiz_cards.php?class_id=<?php echo $class_id; ?>&mode=raise" target="_blank" class="btn btn-sm btn-success" onclick="return checkRaiseSeats()">🖨 打印全班举牌卡</a>
        <?php endif; ?>
    </div>
    <?php if (empty($students)): ?>
        <div class="alert alert-info">该班级暂无学生。</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>座号</th><th>姓名</th><th>编号</th><th>二维码内容</th><th>预览</th><th>个人单页</th></tr></thead>
            <tbody>
                <?php foreach ($students as $s): $sid = intval($s['id']); ?>
                <tr>
                    <td><?php echo htmlspecialchars($s['seat_no']); ?></td>
                    <td><?php echo htmlspecialchars($s['name']); ?></td>
                    <td><?php echo htmlspecialchars($s['student_no']); ?></td>
                    <td style="font-family:Consolas,monospace;"><?php echo htmlspecialchars(build_student_qr_content($s['student_no'])); ?></td>
                    <td><div class="qr-mini" data-content="<?php echo htmlspecialchars(build_student_qr_content($s['student_no'])); ?>"></div></td>
                    <td>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <a href="print.php?class_id=<?php echo $class_id; ?>&type=2&student_id=<?php echo $sid; ?>&act=print" target="_blank" class="btn btn-sm btn-outline" style="text-decoration:none;">🖨 打印</a>
                            <a href="print.php?class_id=<?php echo $class_id; ?>&type=2&student_id=<?php echo $sid; ?>&act=pdf" target="_blank" class="btn btn-sm btn-outline" style="text-decoration:none;">⬇ PDF</a>
                            <a href="print.php?class_id=<?php echo $class_id; ?>&type=2&student_id=<?php echo $sid; ?>&act=word" target="_blank" class="btn btn-sm btn-outline" style="text-decoration:none;">⬇ Word</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script src="assets/js/qrcode.min.js"></script>
<script>
// 举牌卡打印前校验：座号非 1-255 自然数 / 为空 / 重复的学生无法生成有效举牌卡（数据由服务端预检生成）
var RAISE_BAD = <?php echo json_encode($raise_bad ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
function checkRaiseSeats() {
    if (!RAISE_BAD.length) return true;
    var show = RAISE_BAD.slice(0, 8).join('、') + (RAISE_BAD.length > 8 ? ' 等' : '');
    showToast('有 ' + RAISE_BAD.length + ' 名学生的座号不是 1-255 的自然数、为空或重复，无法生成举牌卡：\n' + show + '\n请先在「学生管理」中修正座号后再打印。', 'error');
    return false;
}

// 班级导入二维码：默认折叠，首次展开时才生成（隐藏容器内生成尺寸不可靠）
var impqrGenerated = false;
function toggleImportQr() {
    var body = document.getElementById('impqr_body');
    var arrow = document.getElementById('impqr_arrow');
    var show = body.style.display === 'none';
    body.style.display = show ? '' : 'none';
    arrow.textContent = show ? '▼' : '▶';
    if (show && !impqrGenerated) {
        genClassQr();
        impqrGenerated = true;
    }
}
// 生成班级导入二维码（内容为提取链接，名单多也不会编码失败）
function genClassQr() {
    var textEl = document.getElementById('class_qr_text');
    if (textEl && textEl.textContent) {
        new QRCode(document.getElementById('qrcode_class'), {
            text: textEl.textContent,
            width: 300,
            height: 300,
            correctLevel: QRCode.CorrectLevel.M
        });
    }
}
// 复制提取码
function copyImportCode() {
    var code = document.getElementById('import_code').textContent;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(code).then(function () { showToast('提取码已复制：' + code, 'success', true); });
    } else {
        var inp = document.createElement('textarea');
        inp.value = code;
        document.body.appendChild(inp);
        inp.select();
        document.execCommand('copy');
        document.body.removeChild(inp);
        showToast('提取码已复制：' + code, 'success', true);
    }
}
// 生成学生个人二维码预览
document.querySelectorAll('.qr-mini').forEach(function(el) {
    new QRCode(el, {
        text: el.dataset.content,
        width: 80,
        height: 80,
        correctLevel: QRCode.CorrectLevel.L
    });
});
</script>
<?php page_footer(); ?>
