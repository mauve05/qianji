<?php
/**
 * 打印页
 *  - type=1：全班二维码表格版（每人一格），可设置一行几列（1-10 列，默认 2 列）
 *  - type=2：个人单页二维码（默认一人一页），可设置一行几个（1-10 个；多个即一页排版打印多张）
 *  - 文字项（姓名/序号/编号/备注）可勾选显示，并可单独设置在二维码上方或下方与字号
 *  - 支持 student_id 参数：只输出指定学生（qrcode.php 单人打印入口）
 *  - 支持 act=print/pdf/word：页面加载后自动打印或导出（qrcode.php 单人快捷按钮）
 *  - 导出：PDF（jsPDF 分页）/ Word（MHTML 格式 .doc，图片以 cid 内嵌，Word 完整支持）
 */
require_once 'includes/db_config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

$conn = getConnection();

$class_id = intval($_GET['class_id'] ?? 0);
$type = intval($_GET['type'] ?? 1) === 2 ? 2 : 1;
$act = in_array($_GET['act'] ?? '', ['print', 'pdf', 'word'], true) ? $_GET['act'] : '';
$stmt = mysqli_prepare($conn, "SELECT id, name FROM classes WHERE id = ? AND deleted_at IS NULL");
mysqli_stmt_bind_param($stmt, "i", $class_id);
mysqli_stmt_execute($stmt);
$class = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$class || !can_view_class($conn, $current_teacher_id, $class_id)) {
    header("Location: classes.php");
    exit();
}

// 已禁用学生不打印（名单可禁用；报表仍包含该生）
$stmt = mysqli_prepare($conn, "SELECT * FROM students WHERE class_id = ? AND disabled = 0 ORDER BY CAST(seat_no AS UNSIGNED) ASC, id ASC");
mysqli_stmt_bind_param($stmt, "i", $class_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$students = [];
while ($row = mysqli_fetch_assoc($result)) {
    $students[] = $row;
}
mysqli_stmt_close($stmt);

// 单人模式：只输出指定学生（qrcode.php「个人单页」快捷按钮）
$student_id = intval($_GET['student_id'] ?? 0);
if ($student_id > 0) {
    $students = array_values(array_filter($students, function ($s) use ($student_id) {
        return intval($s['id']) === $student_id;
    }));
}

// ===== 排版设置（GET 参数；默认：表格版一行2列、个人版一行1个；文字项全显，姓名/序号/编号在上方、备注在下方） =====
$raw_cols = intval($_GET['cols'] ?? 0);
$cols = ($raw_cols >= 1 && $raw_cols <= 10) ? $raw_cols : (($type === 1) ? 2 : 1);
$submitted = $raw_cols > 0; // 带设置提交过：未勾选的复选框不会出现在 GET 中，视为 0
// 个人版数量：0=整页自动（按行高估算铺满一页），1-99=指定份数（同一学生的二维码重复打印）
$count_raw = intval($_GET['count'] ?? 0);
if ($count_raw < 0 || $count_raw > 99) $count_raw = 0;
// 文字项定义：key => [label, 默认显示, 默认位置]
$FDEF = [
    'name'   => ['姓名', 1, 'top'],
    'seat'   => ['序号', 1, 'top'],
    'no'     => ['编号', 1, 'top'],
    'remark' => ['备注', 1, 'bottom'],
];
$fields = []; // key => ['show'=>0/1, 'pos'=>'top'/'bottom']
foreach ($FDEF as $k => $def) {
    $show = $submitted ? (isset($_GET['f_' . $k]) && intval($_GET['f_' . $k]) === 1) : ($def[1] === 1);
    $pos = (($_GET['p_' . $k] ?? $def[2]) === 'bottom') ? 'bottom' : 'top';
    $fields[$k] = ['show' => $show, 'pos' => $pos];
}
// 字号设置（px）：每类文字可单独设置；0/缺省 = 使用默认值
$FSDEF = [
    'title'  => ['标题', 20],
    'sub'    => ['副标题', 13],
    'name'   => ['姓名', 22],
    'seat'   => ['序号', 13],
    'no'     => ['编号', 13],
    'remark' => ['备注', 12],
];
$fs = [];
foreach ($FSDEF as $k => $def) {
    $v = intval($_GET['s_' . $k] ?? 0);
    $fs[$k] = ($v >= 6 && $v <= 96) ? $v : $def[1];
}

// 组装单个格子的文字项（上方组 / 下方组，固定顺序：姓名→序号→编号→备注；字号按设置内联注入）
function render_qr_texts(array $s, array $fields, array $fs, string $group): string
{
    $html = '';
    foreach (['name', 'seat', 'no', 'remark'] as $k) {
        $f = $fields[$k];
        if (!$f['show'] || $f['pos'] !== $group) continue;
        $style = 'font-size:' . $fs[$k] . 'px;' . ($k === 'name' ? 'font-weight:bold;' : '');
        if ($k === 'name') {
            if ($s['name'] === '') continue;
            $html .= '<div class="p-name" style="' . $style . '">' . htmlspecialchars($s['name']) . '</div>';
        } elseif ($k === 'seat') {
            if ($s['seat_no'] === '') continue;
            $html .= '<div class="p-sub" style="' . $style . '">序号 ' . htmlspecialchars($s['seat_no']) . '</div>';
        } elseif ($k === 'no') {
            if ($s['student_no'] === '') continue;
            $html .= '<div class="p-sub" style="' . $style . '">编号 ' . htmlspecialchars($s['student_no']) . '</div>';
        } elseif ($k === 'remark') {
            if ($s['remark'] === '') continue;
            $html .= '<div class="p-remark" style="' . $style . '">' . htmlspecialchars($s['remark']) . '</div>';
        }
    }
    return $html;
}

$qr_base = $type === 2
    ? [1 => 360, 2 => 200, 3 => 150, 4 => 120, 5 => 100, 6 => 88, 7 => 78, 8 => 70, 9 => 64, 10 => 58][$cols]
    : [1 => 180, 2 => 140, 3 => 110, 4 => 90, 5 => 76, 6 => 66, 7 => 58, 8 => 52, 9 => 47, 10 => 43][$cols];
// 二维码大小（px）：滑块自定义覆盖（40-600；未设=跟随列数默认）——网页与打印同尺寸
$qs_raw = intval($_GET['qs'] ?? 0);
$qr_size = ($qs_raw >= 40 && $qs_raw <= 600) ? $qs_raw : $qr_base;

// 个人版整页自动份数：按真实行高估算铺满一页 A4（@page 12mm 页边距 → 可打印高约 1032px）
$count = $count_raw;
if ($type === 2 && $count === 0) {
    $texts_h = 0; // 单格文字区总高（可见文字项：字号×1.5 行高 + 间距）
    foreach ($FDEF as $k => $def) {
        if ($fields[$k]['show']) $texts_h += $fs[$k] * 1.5 + 8;
    }
    $cell_h = $qr_size + 36 + $texts_h; // 二维码 + 上下留白/内边距(36) + 文字区
    $budget = 1032 - ($fs['title'] * 1.4 + 8 + $fs['sub'] * 1.4 + 25) - 12; // 标题块与安全余量
    $rows = max(1, intval(floor(($budget + 14) / ($cell_h + 14)))); // +14：行间距补偿
    $count = $rows * $cols;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $type === 2 ? '个人单页二维码' : '全班二维码表格'; ?> - <?php echo htmlspecialchars($class['name']); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background: white; }
        .print-toolbar {
            position: fixed;
            top: 0; left: 0; right: 0;
            background: white;
            padding: 8px 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            z-index: 10;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .print-toolbar form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font-size: 13px; color: #555; }
        .print-toolbar label { display: inline-flex; align-items: center; gap: 3px; white-space: nowrap; }
        .print-toolbar select { padding: 2px 4px; }
        .print-body { padding: 110px 20px 40px; max-width: 900px; margin: 0 auto; }
        .print-title { text-align: center; margin-bottom: 8px; font-size: 20px; font-weight: bold; }
        .print-sub { text-align: center; color: #666; font-size: 13px; margin-bottom: 25px; }
        .page-break { page-break-after: always; padding-bottom: 40px; }
        .qr-top { margin-bottom: 8px; }
        .qr-bottom { margin-top: 8px; }

        @media print {
            .print-toolbar { display: none; }
            .print-body { padding: 0; }
        }
        @page { size: A4 portrait; margin: 12mm; }
        /* 字号设置下拉面板 */
        .fs-panel { position: absolute; top: 100%; right: 0; margin-top: 4px; background: #fff; border: 1px solid #e0e3ef;
            border-radius: 8px; padding: 10px 12px; display: flex; gap: 8px; flex-wrap: wrap; z-index: 30;
            box-shadow: 0 4px 14px rgba(0,0,0,0.12); width: max-content; }
        .fs-panel label { display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
        .fs-panel input { width: 52px; padding: 2px 4px; }
        details.fs-dd > summary { list-style: none; cursor: pointer; }
        details.fs-dd > summary::-webkit-details-marker { display: none; }
        details.fs-dd[open] .fs-panel { display: flex; }
    </style>
</head>
<body>
<div class="print-toolbar">
    <div style="flex:1;"></div>
    <!-- 排版设置：一行几列 + 文字项显隐/上/下位置 + 每类文字字号（GET 提交即时生效） -->
    <form method="get" autocomplete="off">
        <input type="hidden" name="class_id" value="<?php echo $class_id; ?>" autocomplete="off">
        <input type="hidden" name="type" value="<?php echo $type; ?>" autocomplete="off">
        <?php if ($student_id > 0): ?><input type="hidden" name="student_id" value="<?php echo $student_id; ?>" autocomplete="off"><?php endif; ?>
        <label>一行<?php echo $type === 2 ? '几' : '几列'; ?>
            <select name="cols" onchange="this.form.submit()">
                <?php foreach (range(1, 10) as $n): ?>
                <option value="<?php echo $n; ?>"<?php echo $cols === $n ? ' selected' : ''; ?>><?php echo $n; ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label title="二维码边长（像素，网页与打印一致）；改动后可覆盖按列数的默认大小">码大小
            <input type="range" name="qs" min="40" max="600" value="<?php echo $qr_size; ?>" oninput="qsNum.value=this.value" onchange="this.form.submit()" autocomplete="off">
            <input type="number" id="qsNum" style="width:60px;" min="40" max="600" value="<?php echo $qr_size; ?>" onchange="this.form.qs.value=this.value;this.form.submit()" autocomplete="off"> px
        </label>
        <?php if ($type === 2): ?>
        <label title="同一学生二维码在一页内重复的份数">数量
            <input type="number" name="count" min="1" max="99" value="<?php echo $count_raw > 0 ? $count_raw : ''; ?>" placeholder="整页" style="width:64px;" onchange="this.form.submit()" autocomplete="off">
        </label>
        <?php endif; ?>
        <?php foreach ($FDEF as $k => $def): ?>
        <label><input type="checkbox" name="f_<?php echo $k; ?>" value="1"<?php echo $fields[$k]['show'] ? ' checked' : ''; ?> onchange="this.form.submit()" autocomplete="off"> <?php echo $def[0]; ?>
            <select name="p_<?php echo $k; ?>" onchange="this.form.submit()">
                <option value="top"<?php echo $fields[$k]['pos'] === 'top' ? ' selected' : ''; ?>>上方</option>
                <option value="bottom"<?php echo $fields[$k]['pos'] === 'bottom' ? ' selected' : ''; ?>>下方</option>
            </select>
        </label>
        <?php endforeach; ?>
        <details class="fs-dd" style="position:relative;">
            <summary class="btn btn-sm btn-outline">字号设置</summary>
            <div class="fs-panel">
                <?php foreach ($FSDEF as $k => $def): ?>
                <label><?php echo $def[0]; ?>
                    <input type="number" name="s_<?php echo $k; ?>" min="6" max="96" value="<?php echo $fs[$k]; ?>" onchange="this.form.submit()" autocomplete="off">
                </label>
                <?php endforeach; ?>
            </div>
        </details>
        <noscript><button type="submit" class="btn btn-sm">应用</button></noscript>
    </form>
    <div style="display:flex;gap:8px;">
        <button class="btn btn-sm" onclick="window.print()">打印</button>
        <button class="btn btn-sm" id="pdfBtn" onclick="exportPDF()">导出PDF</button>
        <button class="btn btn-sm" id="wordBtn" onclick="exportWord()">导出Word</button>
        <a href="qrcode.php?class_id=<?php echo $class_id; ?>" class="btn btn-sm btn-outline" style="text-decoration:none;">返回</a>
    </div>
</div>

<div class="print-body">
    <div class="print-title-block">
        <div class="print-title" style="font-size:<?php echo $fs['title']; ?>px;"><?php echo htmlspecialchars($class['name']); ?> - <?php echo $type === 2 ? '个人单页二维码' : '全班二维码表格'; ?></div>
        <div class="print-sub" style="font-size:<?php echo $fs['sub']; ?>px;">扫码识别学生编号（潜记二维码作业登记系统）</div>
    </div>

    <?php if ($type === 1): ?>
        <!-- 全班表格版：一行 cols 列 -->
        <div class="print-grid" style="grid-template-columns:repeat(<?php echo $cols; ?>,1fr);">
            <?php foreach ($students as $s): ?>
            <div class="print-cell">
                <div class="qr-top"><?php echo render_qr_texts($s, $fields, $fs, 'top'); ?></div>
                <div class="qr-print" data-content="<?php echo htmlspecialchars(build_student_qr_content($s['student_no'])); ?>" data-size="<?php echo $qr_size; ?>"></div>
                <div class="qr-bottom"><?php echo render_qr_texts($s, $fields, $fs, 'bottom'); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <!-- 个人单页版：每名学生一页；同一学生的二维码按「一行几个 × 数量」重复铺排（数量留空=整页自动铺满） -->
        <?php foreach ($students as $i => $s): ?>
        <div class="<?php echo $i < count($students) - 1 ? 'page-break' : ''; ?>">
            <div class="print-grid" style="grid-template-columns:repeat(<?php echo $cols; ?>,1fr);gap:14px;">
                <?php for ($c = 0; $c < $count; $c++): ?>
                <div class="print-cell" style="page-break-inside:avoid;">
                    <div class="qr-top"><?php echo render_qr_texts($s, $fields, $fs, 'top'); ?></div>
                    <div class="qr-print" data-content="<?php echo htmlspecialchars(build_student_qr_content($s['student_no'])); ?>" data-size="<?php echo $qr_size; ?>"></div>
                    <div class="qr-bottom"><?php echo render_qr_texts($s, $fields, $fs, 'bottom'); ?></div>
                </div>
                <?php endfor; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script src="assets/js/qrcode.min.js"></script>
<script src="assets/js/html2canvas.min.js"></script>
<script src="assets/js/jspdf.umd.min.js"></script>
<script>
document.querySelectorAll('.qr-print').forEach(function(el) {
    new QRCode(el, {
        text: el.dataset.content,
        width: parseInt(el.dataset.size || '140'),
        height: parseInt(el.dataset.size || '140'),
        correctLevel: QRCode.CorrectLevel.L
    });
});

// ===== 导出（PDF / Word）：共享截图管线 =====
var PDF_COLS = <?php echo $cols; ?>;
var PDF_TYPE = <?php echo $type; ?>;              // 1=全班表格 2=个人单页（每生一页）
var PDF_COUNT = <?php echo $type === 2 ? $count : 1; ?>; // 个人版每生份数
var exporting = false;

// 逐格截图（个人版同一学生重复格只截一次）→ [{img(base64), w, h}]
// 用 JPEG 压缩：PNG 大图经 jsPDF 解码再编码后体积暴涨，多生导出会触发 "Invalid string length"
async function captureCells(setProgress) {
    var cellEls = document.querySelectorAll('.print-cell');
    var step = PDF_TYPE === 2 ? Math.max(1, PDF_COUNT) : 1;
    var uniq = [];
    for (var i = 0; i < cellEls.length; i += step) {
        setProgress('生成中 ' + (uniq.length + 1) + '/' + Math.ceil(cellEls.length / step) + '…');
        var c = await html2canvas(cellEls[i], { scale: 2, backgroundColor: '#ffffff' });
        uniq.push({ img: c.toDataURL('image/jpeg', 0.9), w: c.width, h: c.height });
    }
    return uniq;
}
// 标题截图（仅 PDF 首页用）
async function captureTitle() {
    var titleEl = document.querySelector('.print-title-block');
    if (!titleEl) return null;
    var tc = await html2canvas(titleEl, { scale: 2, backgroundColor: '#ffffff' });
    return { img: tc.toDataURL('image/jpeg', 0.9), w: tc.width, h: tc.height };
}
// 占用导出按钮（进度显示）
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

// ===== 导出 PDF（A4 分页） =====
async function exportPDF() {
    var ctx = beginExport('pdfBtn');
    if (!ctx) return;
    try {
        var J = window.jspdf;
        if (!J || !J.jsPDF) { showToast('PDF 组件加载失败，请刷新页面重试', 'error'); return; }
        ctx.progress('准备中…');
        var pdf = new J.jsPDF('p', 'mm', 'a4');
        var PAGE_W = 210, PAGE_H = 297, MARGIN = 10, COL_GAP = 4, ROW_GAP = 3;
        var contentW = PAGE_W - MARGIN * 2;
        var cellW = (contentW - (PDF_COLS - 1) * COL_GAP) / PDF_COLS;
        var bottom = PAGE_H - MARGIN;

        var firstTop = MARGIN;
        var title = await captureTitle();
        if (title) {
            var th = Math.min(contentW * (title.h / title.w), 50);
            pdf.addImage(title.img, 'JPEG', MARGIN, MARGIN, contentW, th);
            firstTop = MARGIN + th + 6;
        }

        var uniq = await captureCells(ctx.progress);
        if (!uniq.length) { showToast('没有可导出的内容', 'error'); return; }

        var maxAspect = 0;
        uniq.forEach(function (u) { maxAspect = Math.max(maxAspect, u.h / u.w); });
        var rowH = cellW * maxAspect + ROW_GAP;

        var y = firstTop, col = 0, pageDirty = false;
        uniq.forEach(function (u) {
            var reps = PDF_TYPE === 2 ? PDF_COUNT : 1;
            for (var r = 0; r < reps; r++) {
                if (PDF_TYPE === 2 && r === 0 && pageDirty) {
                    pdf.addPage(); y = MARGIN; col = 0; pageDirty = false;
                }
                if (y + rowH > bottom) {
                    pdf.addPage(); y = MARGIN; col = 0; pageDirty = false;
                }
                var ch = cellW * (u.h / u.w);
                pdf.addImage(u.img, 'JPEG', MARGIN + col * (cellW + COL_GAP), y, cellW, ch);
                pageDirty = true;
                col++;
                if (col >= PDF_COLS) { col = 0; y += rowH; }
            }
        });

        pdf.save(wordPdfName() + '.pdf');
    } catch (e) {
        showToast('PDF 生成失败：' + (e && e.message ? e.message : e), 'error');
    } finally {
        ctx.done();
    }
}

// ===== 导出 Word（MHTML 格式 .doc：HTML 部分 + JPEG 内嵌，Word 完整渲染二维码与文字） =====
function b64ut8(str) { return btoa(unescape(encodeURIComponent(str))); }
function b64wrap(b64) { return b64.replace(/(.{76})/g, '$1\r\n'); }
function wordPdfName() {
    return '二维码_' + <?php echo json_encode($class['name']); ?> + '_' + (PDF_TYPE === 2 ? '个人单页' : '全班表格');
}
async function exportWord() {
    var ctx = beginExport('wordBtn');
    if (!ctx) return;
    try {
        ctx.progress('准备中…');
        var uniq = await captureCells(ctx.progress);
        if (!uniq.length) { showToast('没有可导出的内容', 'error'); return; }

        // Word HTML 版面（px，A4≈794px 宽；12mm≈45px 页边距）
        var PAGE_W = 794, MARGIN = 45, COL_GAP = 15, ROW_GAP = 10;
        var contentW = PAGE_W - MARGIN * 2;
        var cellW = Math.floor((contentW - (PDF_COLS - 1) * COL_GAP) / PDF_COLS);

        // 每格图片按目标宽度等比缩放；base64 JPEG 转 MHTML 内嵌 part
        var images = [];
        var imgTag = function (u, idx) {
            var cid = 'qrcell' + idx;
            images.push({ cid: cid, b64: u.img.split(',')[1] });
            var h = Math.round(cellW * (u.h / u.w));
            return '<img src="cid:' + cid + '" width="' + cellW + '" height="' + h + '" style="display:block;margin:0 auto;">';
        };

        var titleHtml = '<p style="text-align:center;font-size:<?php echo $fs['title']; ?>pt;font-weight:bold;margin:0 0 6pt;">'
            + <?php echo json_encode(htmlspecialchars($class['name']) . ' - ' . ($type === 2 ? '个人单页二维码' : '全班二维码表格')); ?>
            + '</p>'
            + '<p style="text-align:center;font-size:<?php echo $fs['sub']; ?>pt;color:#666;margin:0 0 14pt;">扫码识别学生编号（潜记二维码作业登记系统）</p>';

        var body = '';
        if (PDF_TYPE === 1) {
            // 全班表格：一张表按 cols 排布，行满换行，Word 自动分页
            var rows = '';
            for (var i = 0; i < uniq.length; i += PDF_COLS) {
                rows += '<tr>';
                for (var j = 0; j < PDF_COLS; j++) {
                    rows += '<td class="qrcell">' + (uniq[i + j] ? imgTag(uniq[i + j], i + j) : '') + '</td>';
                }
                rows += '</tr>';
            }
            body = titleHtml + '<table class="qrt"><tr>' + rows.slice(4) + '</table>';
        } else {
            // 个人单页版：每名学生一页（page-break-before）；重复格复用同一次截图
            uniq.forEach(function (u, idx) {
                var cells = '';
                for (var r = 0; r < PDF_COUNT; r++) {
                    cells += '<td class="qrcell">' + imgTag(u, idx * 1000 + r) + '</td>';
                    if ((r + 1) % PDF_COLS === 0 && r + 1 < PDF_COUNT) cells += '</tr><tr>';
                }
                body += (idx > 0 ? '<br clear="all" style="page-break-before:always">' : '')
                    + '<table class="qrt"><tr>' + cells + '</tr></table>';
            });
            body = titleHtml + body;
        }

        var html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word">'
            + '<head><meta charset="utf-8"><title>' + wordPdfName() + '</title>'
            + '<style>@page Section1 { size: 595.3pt 841.9pt; margin: 34pt; } div.Section1 { page: Section1; }'
            + ' table.qrt { border-collapse: collapse; } td.qrcell { text-align: center; padding: 6pt; }'
            + ' img { border: 1px dashed #bbb; }</style></head>'
            + '<body><div class="Section1">' + body + '</div></body></html>';

        // 组装 MHTML（multipart/related：HTML + JPEG parts）
        var B = '----=_QR_MHTML_BOUNDARY_';
        var mime = 'MIME-Version: 1.0\r\n'
            + 'Content-Type: multipart/related; type="text/html"; boundary="' + B + '"\r\n\r\n'
            + '--' + B + '\r\n'
            + 'Content-Type: text/html; charset="utf-8"\r\n'
            + 'Content-Transfer-Encoding: base64\r\n'
            + 'Content-Location: file:///C:/qr/qr.html\r\n\r\n'
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
        a.download = wordPdfName() + '.doc';
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 3000);
    } catch (e) {
        showToast('Word 生成失败：' + (e && e.message ? e.message : e), 'error');
    } finally {
        ctx.done();
    }
}

// ===== 自动触发（qrcode.php 单人快捷按钮：act=print/pdf/word） =====
var AUTO_ACT = <?php echo json_encode($act); ?>;
if (AUTO_ACT) {
    window.addEventListener('load', function () {
        setTimeout(function () {
            if (AUTO_ACT === 'print') window.print();
            else if (AUTO_ACT === 'pdf') exportPDF();
            else if (AUTO_ACT === 'word') exportWord();
        }, 600);
    });
}
</script>
</body>
</html>
