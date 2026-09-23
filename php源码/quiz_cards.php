<?php
/**
 * 全班举牌卡打印（版式学习 print.php）
 *  - 一张 A4 纸只打印一名学生的一个超大举牌卡
 *  - 举牌卡：自定义 6x6 黑白图案（学生序号 1..255 唯一图案，黑白打印即可），
 *    比二维码更大更耐识别；图案=学生序号（座号），举牌卡全班通用（A 班的卡到 B 班同样可用）；
 *    学生把所选选项文字转到正上方出示，扫码端按卡片哪条边朝上识别选项（A上/B右/C下/D左）
 *  - 选项文字：图案上方=选项A（正读）、右侧=选项B（顺时针转90°）、左侧=选项D（逆时针转90°）、
 *    选项C 预转180°印在图案下方——学生把所选字母转到正上方出示，C 转到上方时才能正读，姓名/编号再印在其下方
 *  - 可调：图案大小（滑块 60-190mm）、选项文字大小（滑块 10-200pt）、文字与图案边距离（滑块 0-50mm，
 *    默认 0=文字紧贴图案边，方便把图案尽量放大提高识别距离）、姓名/编号显示（可选）
 *  - 无假卡片边框：白纸直印，虚线仅标示图案范围（与打印效果一致）
 *  - 导出：PDF（jsPDF 每生一页）/ Word（MHTML 内嵌，每生一页）
 *  - 已禁用学生不打印卡片（学生名单可禁用；报表仍包含该生）
 */
require_once 'includes/db_config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

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

// 学生列表（与个人二维码同序；已禁用学生不打印卡片——名单可禁用，报表仍包含）
$stmt = mysqli_prepare($conn, "SELECT * FROM students WHERE class_id = ? AND disabled = 0 ORDER BY CAST(seat_no AS UNSIGNED) ASC, id ASC");
mysqli_stmt_bind_param($stmt, "i", $class_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$students = [];
while ($row = mysqli_fetch_assoc($result)) $students[] = $row;
mysqli_stmt_close($stmt);

// ===== 设置（GET 参数即时生效） =====
// 图案边长（mm）：默认 138，滑块 60-190（190=撑满 A4 可打印宽，识别距离最远）
$qs = intval($_GET['qs'] ?? 0);
$qs = ($qs >= 60 && $qs <= 190) ? $qs : 138;
// 选项文字大小（pt）：默认 20，滑块 10-200
$label_size = intval($_GET['size'] ?? 0);
$label_size = ($label_size >= 10 && $label_size <= 200) ? $label_size : 20;
// 文字与图案边距离（mm）：默认 0（文字紧贴图案边，图案面可尽量放大、打印后识别距离更远），滑块 0-50
$mg = intval($_GET['mg'] ?? 0);
$mg = ($mg >= 0 && $mg <= 50) ? $mg : 0;
// 文字项（带设置提交过：未勾选的复选框不在 GET 中，视为 0）
$submitted = intval($_GET['set'] ?? 0) === 1;
$f_name = $submitted ? (isset($_GET['f_name']) && intval($_GET['f_name']) === 1) : true;
$f_no   = $submitted ? (isset($_GET['f_no']) && intval($_GET['f_no']) === 1) : true;
// 文字字号（px）：默认 22
$tsize = intval($_GET['ts'] ?? 0);
$tsize = ($tsize >= 10 && $tsize <= 72) ? $tsize : 22;
// 文字条带宽 = 文字行高 + 两侧边距（文字居中于条带，距图案边 = 边距；边距 0 即紧贴）
$lab_h = round($label_size * 0.352777 * 1.25, 2);   // pt → mm（行高 1.25）
$edge = round(2 * $mg + $lab_h, 2);
// 纸卡宽：不小于 A4 可打印宽 190mm；图案+文字条带更宽时随之加宽（导出 PDF 自动缩放，浏览器直印可能裁切）
$inner_w = round($qs + 2 * $edge, 2);
$card_w = max(190, $inner_w);
$card_over = $card_w > 190;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>全班举牌卡 - <?php echo htmlspecialchars($class['name']); ?></title>
<style>
:root { --label-size: <?php echo $label_size; ?>pt; }
* { box-sizing: border-box; }
body { margin: 0; background: white; font-family: "Microsoft YaHei", Arial, sans-serif; }
.print-toolbar {
    position: fixed; top: 0; left: 0; right: 0; background: white;
    padding: 8px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); z-index: 10;
    display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
}
.print-toolbar form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font-size: 13px; color: #555; }
.print-toolbar label { display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
.print-toolbar input[type=range] { width: 120px; vertical-align: middle; }
.rangenum { width: 56px; padding: 2px 4px; }
.print-body { padding: 92px 20px 40px; max-width: 900px; margin: 0 auto; }
.print-title { text-align: center; margin-bottom: 8px; font-size: 20px; font-weight: bold; }
.print-sub { text-align: center; color: #666; font-size: 13px; margin-bottom: 20px; }
/* 举牌卡：无底色无边框阴影；虚线仅标示图案范围（与打印一致）；
   qwrap 紧贴包裹图案（含四周文字条带），条带宽=文字行高+2×边距，文字居中条带内、距图案边=边距（0=紧贴）；
   选项A/B/D 定位在图案的上/右/左侧条带（B、D 随边旋转），选项C 与姓名/编号按阅读顺序排在图案下方
   （C 在 qwrap 内部随流排布，距图案边=同一文字边距，与 A/B/D 一致贴边） */
.qcard { position: relative; width: <?php echo $card_w; ?>mm; min-height: 277mm; margin: 0 auto 24px; padding: 0 0 8mm;
    background: #fff; text-align: center; page-break-inside: avoid; }
.qwrap { position: relative; display: inline-block; padding: <?php echo $edge; ?>mm; }
.qbox { display: inline-block; line-height: 0; padding: 0; border: 1px dashed #bbb; }
.qbox img, .qbox canvas { display: block; }
.qedge-c { margin-top: <?php echo $mg; ?>mm; font-weight: bold; font-size: var(--label-size); color: #111; line-height: 1.25; font-family: Arial, "Microsoft YaHei", sans-serif; }
/* 举牌卡：学生把所选字母转到正上方出示，C 印在下方需预转 180° 转到上方时才正读 */
.qedge-c { transform: rotate(180deg); }
.qtexts { margin-top: 2mm; line-height: 1.5; }
.qtexts .p-name { font-weight: bold; font-size: <?php echo $tsize; ?>px; }
.qtexts .p-sub { font-size: <?php echo max(12, $tsize - 8); ?>px; color: #333; }
.edge { position: absolute; display: flex; align-items: center; justify-content: center; overflow: hidden; }
.edge span { font-weight: bold; font-size: var(--label-size); color: #111; white-space: nowrap; line-height: 1.25; font-family: Arial, "Microsoft YaHei", sans-serif; }
.edge-a { top: 0; left: <?php echo $edge; ?>mm; right: <?php echo $edge; ?>mm; height: <?php echo $edge; ?>mm; }
.edge-b { top: <?php echo $edge; ?>mm; right: 0; width: <?php echo $edge; ?>mm; height: <?php echo $qs; ?>mm; }
.edge-d { top: <?php echo $edge; ?>mm; left: 0; width: <?php echo $edge; ?>mm; height: <?php echo $qs; ?>mm; }
.edge-b span { transform: rotate(90deg); }
.edge-d span { transform: rotate(-90deg); }
@media print {
    .print-toolbar { display: none; }
    .print-body { padding: 0; }
    .qcard { page-break-after: always; margin: 0 auto; }
    .qcard:last-child { page-break-after: auto; }
}
@page { size: A4 portrait; margin: 10mm; }
</style>
</head>
<body>
<div class="print-toolbar">
    <b style="font-size:14px;color:#333;">🖨 全班举牌卡 - <?php echo htmlspecialchars($class['name']); ?>（<?php echo count($students); ?> 人，每生一张 A4）</b>
    <form method="get" autocomplete="off">
        <input type="hidden" name="class_id" value="<?php echo $class_id; ?>" autocomplete="off">
        <input type="hidden" name="set" value="1" autocomplete="off">
        <label title="图案边长（毫米），最大 190 可撑满 A4 可打印宽，识别距离最远">图案大小
            <input type="range" name="qs" min="60" max="190" value="<?php echo $qs; ?>" oninput="qsNum.value=this.value" onchange="this.form.submit()" autocomplete="off">
            <input type="number" id="qsNum" class="rangenum" min="60" max="190" value="<?php echo $qs; ?>" onchange="this.form.qs.value=this.value;this.form.submit()" autocomplete="off"> mm
        </label>
        <label title="四边「选项A/B/C/D」文字大小">选项文字
            <input type="range" name="size" min="10" max="200" value="<?php echo $label_size; ?>" oninput="sizeNum.value=this.value" onchange="this.form.submit()" autocomplete="off">
            <input type="number" id="sizeNum" class="rangenum" min="10" max="200" value="<?php echo $label_size; ?>" onchange="this.form.size.value=this.value;this.form.submit()" autocomplete="off"> pt
        </label>
        <label title="「选项A/B/C/D」文字与图案边的距离，0=紧贴图案边（图案面可尽量放大，打印后识别距离更远）">文字边距
            <input type="range" name="mg" min="0" max="50" value="<?php echo $mg; ?>" oninput="mgNum.value=this.value" onchange="this.form.submit()" autocomplete="off">
            <input type="number" id="mgNum" class="rangenum" min="0" max="50" value="<?php echo $mg; ?>" onchange="this.form.mg.value=this.value;this.form.submit()" autocomplete="off"> mm
        </label>
        <label><input type="checkbox" name="f_name" value="1"<?php echo $f_name ? ' checked' : ''; ?> onchange="this.form.submit()" autocomplete="off"> 姓名</label>
        <label><input type="checkbox" name="f_no" value="1"<?php echo $f_no ? ' checked' : ''; ?> onchange="this.form.submit()" autocomplete="off"> 编号</label>
        <label title="姓名/编号文字大小">文字字号
            <input type="number" name="ts" class="rangenum" min="10" max="72" value="<?php echo $tsize; ?>" onchange="this.form.submit()" autocomplete="off"> px
        </label>
        <noscript><button type="submit" class="btn btn-sm">应用</button></noscript>
    </form>
    <div style="display:flex;gap:8px;">
        <button type="button" class="btn btn-sm" onclick="window.print()">🖨 打印</button>
        <button type="button" class="btn btn-sm" id="pdfBtn" onclick="exportPDF()">导出PDF</button>
        <button type="button" class="btn btn-sm" id="wordBtn" onclick="exportWord()">导出Word</button>
        <a class="btn btn-sm btn-outline" style="text-decoration:none;" href="qrcode.php?class_id=<?php echo $class_id; ?>">返回</a>
    </div>
    <span style="flex:1 100%;font-size:12.5px;color:#888;">用法：A4 纸仅印一张超大举牌卡（6x6 黑白图案，黑白打印即可），图案=学生序号（全班通用），图案上方=选项A、右侧=选项B、左侧=选项D，选项C（预转 180°）与姓名/序号印在图案下方；学生把所选选项字母转到正上方出示，扫码端按卡片哪条边朝上识别选项（允许重复识别改答案）。不要裁掉图案外的白边，也不要折叠、遮挡或覆膜反光。虚线框仅为屏幕上标示图案范围，打印为浅灰细虚线。「文字边距」设 0 可让文字紧贴图案，从而把图案尽量放大（最大 190mm）以提高识别距离。已禁用学生不打印。
    <?php if ($card_over): ?><span style="color:#e67e22;font-weight:bold;">当前纸卡宽 <?php echo $card_w; ?>mm 超出 A4 可打印宽 190mm：浏览器直接打印可能裁切四边文字，建议用「导出PDF」（自动缩放到一页）或减小图案大小/文字边距。</span><?php endif; ?></span>
</div>

<div class="print-body">
    <div class="print-title-block">
        <div class="print-title"><?php echo htmlspecialchars($class['name']); ?> - 全班举牌卡</div>
        <div class="print-sub">把所选答案 A / B / C / D 中所选字母转到正上方出示（潜记举牌模式）</div>
    </div>

    <?php if (empty($students)): ?>
    <div class="qcard" style="min-height:60mm;line-height:60mm;color:#999;">该班级暂无学生（或已全部禁用），请先在学生名单中导入 / 启用</div>
    <?php else: ?>
    <?php
    // 举牌卡序号重复检测（同班重复序号会导致扫码端无法唯一识别，卡片上红字提醒）
    $seat_dup = [];
    foreach ($students as $sd) {
        $k = trim((string)$sd['seat_no']);
        if ($k !== '') $seat_dup[$k] = ($seat_dup[$k] ?? 0) + 1;
    }
    ?>
    <?php foreach ($students as $s):
        // 举牌卡：图案编码学生序号（座号，需 1-255 的整数；扫码端按序号在项目班级内定位，举牌卡全班通用）
        $pbits = null; $praw = trim((string)$s['seat_no']);
        if ($praw !== '' && (string)intval($praw) === $praw && intval($praw) >= 1 && intval($praw) <= 255) {
            $pbits = raise_pattern_bits(intval($praw));
        }
        $pdup = $praw !== '' && intval($seat_dup[$praw] ?? 0) > 1;
    ?>
    <div class="qcard">
        <div class="qwrap">
            <div class="edge edge-a"><span>选项A</span></div>
            <div class="edge edge-b"><span>选项B</span></div>
            <div class="edge edge-d"><span>选项D</span></div>
            <div class="qbox" data-qs="<?php echo $qs; ?>" data-bits="<?php echo $pbits ? htmlspecialchars(implode('', $pbits)) : ''; ?>"></div>
            <?php if (!$pbits): ?>
            <div style="color:#c0392b;font-weight:bold;margin-top:4mm;">序号「<?php echo htmlspecialchars($praw !== '' ? $praw : '空'); ?>」无法生成举牌卡（需 1-255 的整数序号）</div>
            <?php elseif ($pdup): ?>
            <div style="color:#c0392b;font-weight:bold;margin-top:4mm;">序号「<?php echo htmlspecialchars($praw); ?>」在班级内重复，扫码无法唯一识别，请先修改座号</div>
            <?php endif; ?>
            <div class="qedge-c">选项C</div>
            <?php if ($f_name || $f_no): ?>
            <div class="qtexts">
                <?php if ($f_name && $s['name'] !== ''): ?><div class="p-name"><?php echo htmlspecialchars($s['name']); ?></div><?php endif; ?>
                <?php if ($f_no && $praw !== ''): ?><div class="p-sub">序号 <?php echo htmlspecialchars($praw); ?></div><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<script src="assets/js/html2canvas.min.js"></script>
<script src="assets/js/jspdf.umd.min.js"></script>
<script>
// 生成超大举牌卡（1px≈0.2646mm；按 mm 精确换算，屏幕与打印 1:1）
// 举牌卡=6x6 黑白图案（bit=1 白格 0 黑格，行序与扫码端码本一致）
var MM2PX = 96 / 25.4;   // 96dpi 基准
var CARD_W_MM = <?php echo $card_w; ?>;   // 纸卡设计宽（mm）：PDF/Word 按此换算实际尺寸（保持比例、不拉伸）
document.querySelectorAll('.qbox').forEach(function (el) {
    var px = Math.round(parseInt(el.dataset.qs || '138', 10) * MM2PX);
    if (!el.dataset.bits) return;   // 无效序号：图案留空，卡片上已显示红色提示
    var bits = el.dataset.bits;
    var cell = Math.max(6, Math.floor(px / 6));
    var size = cell * 6;
    var cv = document.createElement('canvas');
    cv.width = size; cv.height = size;
    var ctx = cv.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, size, size);
    ctx.fillStyle = '#000000';
    for (var r = 0; r < 6; r++) {
        for (var c = 0; c < 6; c++) {
            if (bits.charAt(r * 6 + c) === '0') ctx.fillRect(c * cell, r * cell, cell, cell);
        }
    }
    el.appendChild(cv);
});

// ===== 导出 PDF / Word（共享截图管线，每生一页） =====
var exporting = false;
function beginExport(btnId) {
    if (exporting) return null;
    if (typeof html2canvas === 'undefined') { showToast('导出组件加载失败，请刷新页面重试', 'error'); return null; }
    exporting = true;
    var btn = document.getElementById(btnId);
    var oldText = btn.textContent;
    btn.disabled = true;
    return {
        progress: function (t) { btn.textContent = t; },
        done: function () { btn.disabled = false; btn.textContent = oldText; exporting = false; }
    };
}
async function captureCards(setProgress) {
    var els = document.querySelectorAll('.qcard');
    var out = [];
    for (var i = 0; i < els.length; i++) {
        setProgress('生成中 ' + (i + 1) + '/' + els.length + '…');
        var c = await html2canvas(els[i], { scale: 2, backgroundColor: '#ffffff' });
        out.push({ img: c.toDataURL('image/jpeg', 0.9), w: c.width, h: c.height });   // JPEG 压缩，避免 jsPDF "Invalid string length"
    }
    return out;
}
function fileBase() { return '举牌卡_' + <?php echo json_encode($class['name']); ?>; }

async function exportPDF() {
    var ctx = beginExport('pdfBtn');
    if (!ctx) return;
    try {
        var J = window.jspdf;
        if (!J || !J.jsPDF) { showToast('PDF 组件加载失败，请刷新页面重试', 'error'); return; }
        ctx.progress('准备中…');
        var pdf = new J.jsPDF('p', 'mm', 'a4');
        var cards = await captureCards(ctx.progress);
        if (!cards.length) { showToast('没有可导出的内容', 'error'); return; }
        cards.forEach(function (u, i) {
            if (i > 0) pdf.addPage();
            // 按设计宽换算 mm 尺寸，等比缩放放入 A4 可打印区（190×277）并水平居中；超出自动缩到一页内
            var mmpp = CARD_W_MM / u.w;
            var wmm = u.w * mmpp, hmm = u.h * mmpp;
            var s = Math.min(190 / wmm, 277 / hmm, 1);
            var w = wmm * s, h = hmm * s;
            pdf.addImage(u.img, 'JPEG', (210 - w) / 2, (297 - h) / 2, w, h);
        });
        pdf.save(fileBase() + '.pdf');
    } catch (e) {
        showToast('PDF 生成失败：' + (e && e.message ? e.message : e), 'error');
    } finally {
        ctx.done();
    }
}

function b64ut8(str) { return btoa(unescape(encodeURIComponent(str))); }
function b64wrap(b64) { return b64.replace(/(.{76})/g, '$1\r\n'); }
async function exportWord() {
    var ctx = beginExport('wordBtn');
    if (!ctx) return;
    try {
        ctx.progress('准备中…');
        var cards = await captureCards(ctx.progress);
        if (!cards.length) { showToast('没有可导出的内容', 'error'); return; }
        var images = [];
        var body = '';
        cards.forEach(function (u, idx) {
            var cid = 'qcard' + idx;
            images.push({ cid: cid, b64: u.img.split(',')[1] });
            // 按设计宽换算 mm → px(96dpi)，保持比例不拉伸
            var mmpp = CARD_W_MM / u.w;
            var w = Math.round(u.w * mmpp / 25.4 * 96), h = Math.round(u.h * mmpp / 25.4 * 96);
            body += (idx > 0 ? '<br clear="all" style="page-break-before:always">' : '')
                + '<img src="cid:' + cid + '" width="' + w + '" height="' + h + '" style="display:block;margin:0 auto;">';
        });
        var html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word">'
            + '<head><meta charset="utf-8"><title>' + fileBase() + '</title>'
            + '<style>@page Section1 { size: 595.3pt 841.9pt; margin: 28pt; } div.Section1 { page: Section1; }</style></head>'
            + '<body><div class="Section1">' + body + '</div></body></html>';
        var B = '----=_QR_MHTML_BOUNDARY_';
        var mime = 'MIME-Version: 1.0\r\n'
            + 'Content-Type: multipart/related; type="text/html"; boundary="' + B + '"\r\n\r\n'
            + '--' + B + '\r\n'
            + 'Content-Type: text/html; charset="utf-8"\r\n'
            + 'Content-Transfer-Encoding: base64\r\n'
            + 'Content-Location: file:///C:/qr/qcard.html\r\n\r\n'
            + b64wrap(b64ut8(html)) + '\r\n';
        images.forEach(function (im) {
            mime += '--' + B + '\r\n'
                + 'Content-Type: image/jpeg\r\n'
                + 'Content-Transfer-Encoding: base64\r\n'
                + 'Content-Location: file:///C:/qr/' + im.cid + '.jpg\r\n'
                + 'Content-ID: <' + im.cid + '>\r\n\r\n'
                + b64wrap(im.b64) + '\r\n';
        });
        mime += '--' + B + '--\r\n';
        var bytes = new Uint8Array(mime.length);
        for (var b = 0; b < mime.length; b++) bytes[b] = mime.charCodeAt(b) & 0xFF;
        var blob = new Blob([bytes], { type: 'application/msword' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = fileBase() + '.doc';
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 3000);
    } catch (e) {
        showToast('Word 生成失败：' + (e && e.message ? e.message : e), 'error');
    } finally {
        ctx.done();
    }
}
</script>
</body>
</html>
