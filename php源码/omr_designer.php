<?php
/**
 * 答题卡设计器（答题卡 / OMR 涂卡识别模式）
 * 可视化拖拽设计答题卡布局：身份涂号区（座号/编号）+ 单选/多选题组（题数、每行题数、选项数、方框大小、间距、位置均可配置）
 * 导出：打印用 PDF（html2canvas 截图 + jsPDF，全程浏览器本地，不上传图片）
 * 保存：布局 JSON 预计算每个涂框的毫米坐标（bubbles），识别端（omr_scan.php）按坐标采样涂黑率判定
 * 模板存于 omr_templates 表（api.php: omr_template_save / list / get / del）
 */
require_once 'includes/db_config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/layout.php';

$conn = getConnection();

$project_id = intval($_GET['project_id'] ?? 0);
$project = null;
if ($project_id > 0) {
    foreach (get_visible_projects($conn, $current_teacher_id) as $vp) {
        if (intval($vp['id']) === $project_id) { $project = $vp; break; }
    }
}
if (!$project || (($project['mode'] ?? '') !== 'omr')) {
    page_header('答题卡设计', 'omr_scan.php');
    echo '<div class="panel" style="text-align:center;padding:50px 20px;">'
        . '<div style="font-size:46px;margin-bottom:8px;">🧇</div>'
        . '<h3 style="margin-bottom:8px;">项目不存在或非「答题卡」模式项目</h3>'
        . '<p class="tip">请从答题卡模式项目页进入设计器，或在项目设置中把登记模式改为「答题卡」。</p>'
        . '<a href="projects.php" class="btn" style="margin-top:14px;">前往项目列表</a></div>';
    page_footer();
    exit;
}

page_header('答题卡设计', 'omr_scan.php');   // 高亮「扫描识别」导航（与 project_view 顶底导航一致）
$omr_lib_on = omr_lib_enabled($conn);        // 学校共享模板库开关（后台 feat_omr_lib；未加入学校不开放）
?>
<style>
.omr-toolbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; padding:12px 14px 6px; }
.omr-toolbar .sep { width:1px; height:22px; background:#d0d4e8; margin:0 2px; }
.omr-main { display:flex; gap:14px; align-items:flex-start; padding:8px 14px 24px; }
#sheetWrap { flex:1; min-width:0; overflow:auto; background:#e8eaf2; border-radius:10px; padding:14px; max-height:78vh; }
#sheet { position:relative; background:#fff; box-shadow:0 4px 18px rgba(0,0,0,0.18); margin:0 auto; }
#sheet .mark { position:absolute; background:#000; }
#sheet .frameguide { position:absolute; border:0.4mm dashed #8fa0c8; box-sizing:border-box; pointer-events:none; }
#sheet.exporting .frameguide { display:none !important; }
#sheet .txt { position:absolute; white-space:nowrap; font-family:"Microsoft YaHei",sans-serif; color:#000; line-height:1; }
#sheet .bub { position:absolute; border:1.2px solid #000; border-radius:2px; box-sizing:border-box; background:#fff; }
#sheet .bub-k { cursor:pointer; }
#sheet .bub-k:hover { background:#eef2ff; }
#sheet .bub-key { background:#16a34a; border-color:#166534; }
#sheet.exporting .bub-key { background:#fff; border-color:#000; }   /* 导出 PDF 时恢复空白涂框外观 */
#sheet .blk { position:absolute; cursor:move; touch-action:none; }
#sheet .blk.blk-hover { outline:1px dashed rgba(102,126,234,0.45); outline-offset:2px; }
#sheet .blk.blk-sel { outline:2px dashed #667eea; outline-offset:2px; }
#sheet.exporting .blk { outline:none !important; }
#sheet .grp { position:absolute; border:0.3mm solid #b3bcd4; border-radius:2px; box-sizing:border-box; pointer-events:none; }
#sheet .dline { position:absolute; border-bottom:0.35mm solid #000; box-sizing:border-box; pointer-events:none; }
#sheet .dbox { position:absolute; border:0.35mm dashed #000; border-radius:1mm; box-sizing:border-box; pointer-events:none; overflow:hidden; }
#sheet .dbox .dbox-txt { padding:0.8mm 1mm; font-family:"Microsoft YaHei",sans-serif; color:#000; line-height:1.35; white-space:normal; word-break:break-all; }
#pageBar { display:flex; gap:6px; align-items:center; padding:8px 14px 0; flex-wrap:wrap; }
#pageBar .pg-tab { padding:5px 14px; border:1px solid #d0d4e8; background:#fff; border-radius:16px; font-size:13px; cursor:pointer; }
#pageBar .pg-tab.on { background:#667eea; border-color:#667eea; color:#fff; font-weight:bold; }
#sheet .txt-el { cursor:move; touch-action:none; }
#sheet .txt-el.txt-sel { outline:1px dashed #667eea; outline-offset:2px; }
#sheet.exporting .txt-el { outline:none !important; }
#props { width:330px; flex-shrink:0; max-height:78vh; overflow:auto; }
#props h3 { font-size:15px; margin-bottom:10px; color:#667eea; }
#props .pgrid { display:grid; grid-template-columns:1fr 1fr; gap:8px 10px; }
#props .pitem label { display:block; font-size:12px; color:#888; margin-bottom:3px; }
#props .pitem input, #props .pitem select { width:100%; padding:6px 8px; border:1px solid #ddd; border-radius:6px; font-size:13px; box-sizing:border-box; font-family:inherit; }
#props .key-row { display:flex; align-items:center; gap:4px; padding:2px 0; }
#props .key-row .kq { width:44px; font-size:12px; color:#555; flex-shrink:0; }
#props .key-btn { min-width:26px; height:24px; border:1px solid #d0d4e8; background:#fff; border-radius:5px; font-size:12px; cursor:pointer; padding:0 4px; }
#props .key-btn.on { background:#667eea; border-color:#667eea; color:#fff; font-weight:bold; }
#props .subttl { font-size:13px; font-weight:bold; color:#555; margin:12px 0 6px; }
#sheetWrap .vwarn { color:#e67e22; font-size:13px; margin-top:8px; }
#toast { position:fixed; left:50%; bottom:80px; transform:translateX(-50%); background:rgba(40,44,60,0.92); color:#fff;
    padding:10px 22px; border-radius:24px; font-size:14px; z-index:2000; display:none; box-shadow:0 4px 14px rgba(0,0,0,0.3); }
@media (max-width: 900px) { .omr-main { flex-direction:column; } #props { width:100%; max-height:none; } }
</style>

<div class="omr-toolbar">
    <b>🧇 <?php echo htmlspecialchars($project['name']); ?> · 答题卡设计</b>
    <span class="sep"></span>
    <select id="tplSelect" class="form-control" style="width:auto;padding:6px 10px;font-size:13px;" onchange="onTplSelect()"><option value="">— 选择已有模板 —</option></select>
    <button type="button" class="btn btn-sm btn-outline" onclick="newTpl()">新建</button>
    <button type="button" class="btn btn-sm btn-outline" onclick="delTpl()">删除模板</button>
    <span class="sep"></span>
    <input type="text" id="tplName" class="form-control" style="width:160px;padding:6px 10px;font-size:13px;" placeholder="模板名称（留空自动命名）" maxlength="50" autocomplete="off">
    <button type="button" class="btn btn-sm" onclick="saveTpl()">💾 保存模板</button>
    <button type="button" class="btn btn-sm btn-outline" onclick="ansOpen()" title="单独录入每道题的正确答案（独立于模板，改答案不影响已打印答题卡的识别）；答案完整时识别自动批改">🔑 录入答案</button>
    <?php if ($omr_lib_on): ?>
    <button type="button" class="btn btn-sm btn-outline" onclick="libOpen()" title="共享模板库：载入已保存的共享模板（可改存为本项目模板）">📚 模板库</button>
    <button type="button" class="btn btn-sm btn-outline" onclick="libSaveOpen()" title="把当前画布布局存入共享模板库（按所选班级共享）">⬆ 存入模板库</button>
    <?php endif; ?>
    <button type="button" id="dupBtn" class="btn btn-sm btn-outline" onclick="toggleDup()" title="打印/PDF 时把内容复制为每页 2~3 份（每份自带定位标记，裁开可单独识别），预览不受影响；仅单页模板可用">🧻 整页填充</button>
    <button type="button" class="btn btn-sm btn-outline" onclick="exportPDF()">🖨 导出 PDF</button>
    <span class="sep"></span>
    <button type="button" class="btn btn-sm btn-outline" onclick="addSection('single')">＋单选题组</button>
    <button type="button" class="btn btn-sm btn-outline" onclick="addSection('multi')">＋多选题组</button>
    <button type="button" class="btn btn-sm btn-outline" onclick="addSection('judge')" title="判断题：对/错 两项（内部按 A/B 识别判分）">＋判断题组</button>
    <button type="button" class="btn btn-sm btn-outline" onclick="addSection('blank')" title="填空题：每行「题号 ______」，一行几列、横线长度可设置，一次插入 10 题；手写题不自动判分">＋填空题组</button>
    <button type="button" class="btn btn-sm btn-outline" onclick="addSection('short')" title="简答题：题号+题目占一整行，下方虚线作答框（宽高可设，设宽后题目自动换行）；一次插入一题，手写题不自动判分">＋简答题</button>
    <button type="button" class="btn btn-sm btn-outline" onclick="addText()" title="添加自定义文本（如班级/姓名行、考试说明等）">＋文本</button>
    <button type="button" class="btn btn-sm btn-outline" onclick="autoArrange()">自动整理布局</button>
    <span style="margin-left:auto;">
        <a class="btn btn-sm btn-success" style="text-decoration:none;" href="omr_scan.php?project_id=<?php echo $project_id; ?>" onclick="return goScanSave(event);">📷 去扫描识别</a>
    </span>
</div>
<div id="vWarn" class="vwarn" style="padding:0 18px;display:none;"></div>

<div id="pageBar"></div>
<div class="omr-main">
    <div id="sheetWrap"><div id="sheet"></div></div>
    <div id="props" class="panel" style="padding:16px;"><h3>属性设置</h3><div id="propsBody"></div></div>
</div>

<div id="toast"></div>

<?php if ($omr_lib_on): ?>
<!-- 共享模板库弹层：列出可加载模板（载入/改名/删除）+ 存入当前画布布局 -->
<div id="libModal" style="display:none;position:fixed;inset:0;background:rgba(20,24,40,0.45);z-index:1500;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">
    <div style="background:#fff;border-radius:12px;max-width:680px;width:100%;max-height:84vh;overflow:auto;padding:18px 20px;box-shadow:0 10px 40px rgba(0,0,0,0.25);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
            <h3 style="margin:0;color:#667eea;">📚 共享模板库</h3>
            <button type="button" class="btn btn-sm btn-outline" onclick="libClose()">关闭</button>
        </div>
        <p class="tip" style="margin:0 0 10px;">模板库共享范围<button type="button" class="hint-q" onclick="toggleHint(event, '<b>共享模板库</b><br>· 存入的模板按所选班级共享，本班可载入复用<br>· 仅创建者可改名/覆盖/删除<br>· 载入后可再「💾 保存模板」存为本项目模板')">?</button></p>
        <div id="libList" style="max-height:44vh;overflow:auto;"></div>
        <div style="border-top:1px dashed #e3e6f2;margin-top:12px;padding-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <b style="font-size:13px;color:#555;">⬆ 存入模板库：</b>
            <input type="text" id="libName" class="form-control" style="width:170px;padding:6px 10px;font-size:13px;" placeholder="模板名称" maxlength="50" autocomplete="off">
            <select id="libScope" class="form-control" style="width:auto;max-width:300px;padding:6px 10px;font-size:13px;"></select>
            <button type="button" class="btn btn-sm" onclick="libSave()">保存</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 录入答案弹层：按当前画布题组逐题设置正确答案（独立于模板存储，识别端优先使用；改答案不动模板） -->
<div id="ansModal" style="display:none;position:fixed;inset:0;background:rgba(20,24,40,0.45);z-index:1500;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">
    <div style="background:#fff;border-radius:12px;max-width:680px;width:100%;max-height:84vh;display:flex;flex-direction:column;box-shadow:0 10px 40px rgba(0,0,0,0.25);">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px 8px;">
            <h3 style="margin:0;color:#667eea;">🔑 录入答案</h3>
            <button type="button" class="btn btn-sm btn-outline" onclick="ansClose()">关闭</button>
        </div>
        <p class="tip" id="ansHint" style="margin:0 20px 8px;"></p>
        <div id="ansBody" style="flex:1;overflow:auto;padding:0 20px 10px;"></div>
        <div style="border-top:1px dashed #e3e6f2;padding:12px 20px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <button type="button" class="btn btn-sm" onclick="ansSave()">💾 保存答案</button>
            <button type="button" class="btn btn-sm btn-outline" id="ansClearBtn" onclick="ansClear()" style="display:none;" title="删除独立答案，改回使用模板自带答案键">🗑 清除独立答案（改用模板答案键）</button>
            <span class="tip" style="margin-left:auto;">批改口径<button type="button" class="hint-q" onclick="toggleHint(event, '<b>识别批改口径</b><br>· 答案覆盖全部题目时：识别自动批改<br>· 不完整则仅登记')">?</button></span>
        </div>
    </div>
</div>
<style>
#ansModal .ans-sec { font-size:13px; font-weight:bold; color:#555; margin:10px 0 4px; }
#ansModal .key-row { display:flex; align-items:center; gap:4px; padding:2px 0; }
#ansModal .key-row .kq { width:56px; font-size:12px; color:#555; flex-shrink:0; }
#ansModal .key-btn { min-width:26px; height:24px; border:1px solid #d0d4e8; background:#fff; border-radius:5px; font-size:12px; cursor:pointer; padding:0 4px; }
#ansModal .key-btn.on { background:#667eea; border-color:#667eea; color:#fff; font-weight:bold; }
</style>

<script src="assets/js/html2canvas.min.js"></script>
<script src="assets/js/jspdf.umd.min.js"></script>
<script>
var PROJECT_ID = <?php echo $project_id; ?>;
var PJ_NAME = <?php echo json_encode($project['name']) ?>;   // 所属项目名（模板未命名时自动命名用）
var S = 3.6;                    // 预览比例：像素/毫米
var LETTERS = ['A', 'B', 'C', 'D', 'E'];
var ID_LABEL_H = 4.5, COLHDR_H = 4, QW = 7, VAL_H = 3.2;   // 身份区标签高 / 列头高 / 题号列宽 / 身份区数字值头高（毫米）
var BLANK_ROW_H = 8, LINE_H = 4.4, MAXP = 5;                // 填空行高 / 简答题目行高 / 最大页数
var L = null, sel = null, TID = 0, drag = null, dragMoved = false, pendKey = null, TPL_LIST = [], CP = 0;   // CP=当前编辑页（0 基）；dragMoved=本次拖拽是否移动过；pendKey=按下时所在的可点选涂框（松手未拖动=设答案）
var uiKeyOpen = false, uiPtsOpen = false;   // 属性面板「答案键」「分值设置」折叠状态（重渲染保持；默认折叠）

function defaultTitle() { return <?php echo json_encode($project['name']) ?> + ' 答题卡'; }
function isChoiceKind(k) { return k === 'single' || k === 'multi' || k === 'judge'; }
function mkSection(kind, x, y) {
    var base = { kind: kind, start: 1, x: x, y: y, key: {}, points: {}, pg: 0 };
    if (kind === 'blank') return Object.assign(base, { title: '填空题', count: 10, perRow: 4, lineLen: 30 });
    if (kind === 'short') return Object.assign(base, { title: '简答题', count: 1, qtext: '', boxW: 0, boxH: 22 });
    return Object.assign(base, { title: kind === 'single' ? '一、单选题' : (kind === 'multi' ? '二、多选题' : '三、判断题'),
             count: 10, perRow: 5, opts: kind === 'judge' ? 2 : 4, bw: 4.2, bh: 4.2, ogap: 2.0, rgap: 2.6 });
}
function defaultLayout() {
    // 新建/未选择模板：仅标题 + 「班级/姓名/座号/编号」填写行（题组由教师按需添加；保存时校验必须有身份区与题组）
    // 页头定位点为强制项（每页取景框上边 2/3/4…枚黑点，识别端定方向与页码），不再提供开关
    return { ver: 2, dup: 1, hmarks: true, hmarkCluster: true, edgeMidMarks: true, pages: 1, paper: { w: 210, h: 297 }, title: defaultTitle(),
             titlePos: { x: 0, y: 5 },                               // 标题位置（全宽居中块，可拖动）
             pgnum: { on: true, x: 0, y: 12, size: 3.2, fmt: '' },   // 页码行（{p}=页号、{n}=总页数）
             id: { x: 14, y: 24, digits: 2, idmode: 'seat', orient: 'v', bw: 4, bh: 4, vgap: 1.4, hgap: 1.8 },   // 默认横向（0-9 横排、每位一行）
             sections: [],
             texts: [{ text: '班级：____________　　姓名：____________　　座号/编号：____________', x: 0, y: 17.5, size: 3.8, w: 210, center: true, pg: 0 }] };
}
function mkText(raw, paper) {
    var t = { text: String(raw.text || '').slice(0, 120), x: 0, y: 13, size: 3.2, w: 0, center: false, pg: 0 };
    if (!t.text) return null;
    if (isFinite(+raw.x)) t.x = Math.max(0, Math.min(paper.w, +raw.x));
    if (isFinite(+raw.y)) t.y = Math.max(0, Math.min(paper.h - 4, +raw.y));
    if (isFinite(+raw.size)) t.size = Math.min(8, Math.max(2, +raw.size));
    if (isFinite(+raw.w)) t.w = Math.max(0, Math.min(paper.w, +raw.w));
    t.center = !!raw.center;
    if (isFinite(+raw.pg)) t.pg = Math.max(0, Math.min(MAXP - 1, Math.round(+raw.pg)));
    return t;
}
function defaultTexts(paper) {
    return [{ text: '班级：____________　　姓名：____________　　座号/编号：____________', x: 0, y: 13, size: 3.8, w: paper.w, center: true, pg: 0 }];
}
function normalizeLayout(raw) {
    var d = defaultLayout();
    if (!raw || typeof raw !== 'object') return d;
    if (raw.paper && raw.paper.w > 0 && raw.paper.h > 0) d.paper = { w: +raw.paper.w, h: +raw.paper.h };
    if (typeof raw.title === 'string') d.title = raw.title;
    // 多页（1-5 页）：每页重复身份涂号区与页头定位点，内容超页底自动分流到下一页
    d.pages = Math.min(MAXP, Math.max(1, Math.round(+raw.pages || 1)));
    if (raw.id && typeof raw.id === 'object') {
        ['x', 'y', 'bw', 'bh', 'vgap', 'hgap'].forEach(function (k) { if (isFinite(+raw.id[k])) d.id[k] = +raw.id[k]; });
        d.id.digits = Math.min(10, Math.max(1, Math.round(+raw.id.digits || 2)));
        d.id.idmode = (raw.id.idmode === 'no') ? 'no' : 'seat';
        d.id.orient = (raw.id.orient === 'h') ? 'h' : 'v';   // 缺省横向（0-9 横排、每位一行）；仅旧数据显式 'h' 才保留纵向
    }
    if (Array.isArray(raw.sections)) {
        d.sections = [];
        raw.sections.forEach(function (s) {
            if (!s || typeof s !== 'object') return;
            var k = (s.kind === 'multi') ? 'multi' : (s.kind === 'judge') ? 'judge' : (s.kind === 'blank') ? 'blank' : (s.kind === 'short') ? 'short' : 'single';
            var n = mkSection(k, 14, 24);
            if (isChoiceKind(k)) {
                ['start', 'count', 'perRow', 'opts', 'bw', 'bh', 'ogap', 'rgap', 'x', 'y'].forEach(function (kk) { if (isFinite(+s[kk])) n[kk] = +s[kk]; });
                n.key = {};
                if (s.key && typeof s.key === 'object') {
                    Object.keys(s.key).forEach(function (q) {
                        var v = String(s.key[q]).toUpperCase().replace(/[^A-E]/g, '');
                        if (v) n.key[String(parseInt(q, 10) || 0)] = v;
                    });
                }
                // 每题分值（可选，批改用）：0 < 分值 ≤ 100，非法/未设剔除
                n.points = {};
                if (s.points && typeof s.points === 'object') {
                    Object.keys(s.points).forEach(function (q) {
                        var pv = parseFloat(s.points[q]);
                        if (isFinite(pv) && pv > 0 && pv <= 100) n.points[String(parseInt(q, 10) || 0)] = Math.round(pv * 10) / 10;
                    });
                }
                n.start = Math.max(1, Math.round(n.start)); n.count = Math.max(1, Math.round(n.count));
                n.perRow = Math.max(1, Math.round(n.perRow)); n.opts = Math.min(5, Math.max(2, Math.round(n.opts)));
                if (n.kind === 'judge') n.opts = 2;   // 判断题固定 对/错 两项（内部按 A/B 识别判分）
            } else if (k === 'blank') {
                ['start', 'count', 'perRow', 'lineLen', 'x', 'y'].forEach(function (kk) { if (isFinite(+s[kk])) n[kk] = +s[kk]; });
                n.start = Math.max(1, Math.round(n.start)); n.count = Math.min(100, Math.max(1, Math.round(n.count)));
                n.perRow = Math.min(10, Math.max(1, Math.round(n.perRow)));
                n.lineLen = Math.min(120, Math.max(10, +n.lineLen || 30));
                n.key = {};
            } else {   // short：一次一题，题目文字 + 虚线作答框
                ['start', 'boxW', 'boxH', 'x', 'y'].forEach(function (kk) { if (isFinite(+s[kk])) n[kk] = +s[kk]; });
                n.start = Math.max(1, Math.round(n.start));
                n.qtext = String(s.qtext || '').slice(0, 120);
                n.boxW = Math.max(0, Math.min(d.paper.w, +n.boxW || 0));
                n.boxH = Math.min(150, Math.max(8, +n.boxH || 22));
                n.key = {};
            }
            if (typeof s.title === 'string') n.title = s.title.slice(0, 30);
            n.pg = Math.min(d.pages - 1, Math.max(0, Math.round(+s.pg || 0)));
            d.sections.push(n);
        });
    }
    // 允许空题组（默认画布仅标题+班级/姓名行；保存模板时才强制要求身份区+题组）
    // 整页填充份数（仅作用于打印/PDF 导出；1=单份，2/3=每页复制份数；多页模板不适用，强制单份）
    d.dup = (raw.dup === 2 || raw.dup === 3) ? raw.dup : 1;
    if (d.pages > 1) d.dup = 1;
    // 页头定位点：强制项（旧字段忽略，恒为开启）——识别端据页头点定方向与页码
    d.hmarks = true;
    // 页码点排布：仅透传已保存值；旧模板无此字段 → false 保持 22%~78% 对称旧版式（已印卡兼容），
    // 新建模板 defaultLayout 缺省 true（聚簇左上）。与识别端 omr_scan.php normalizeTpl 同源
    d.hmarkCluster = !!raw.hmarkCluster;
    // 边缘中点码点：取景框左/右/下边正中各 1 枚 5mm 黑方块（顶边被页码点占用不补）。
    // 纸张弯曲时为预览分段矫正（Coons patch）提供边中锚点，同时给角标遮挡/出画提供冗余；
    // 旧模板无此字段 → false 不画（已印卡外观不变），新建模板缺省 true。与识别端同源透传
    d.edgeMidMarks = !!raw.edgeMidMarks;
    // 标题位置（可拖动）与页码行：旧模板缺省关闭（保持已印外观与取景框不变）；新布局默认显示
    d.titlePos = { x: 0, y: 5 };
    if (raw.titlePos && typeof raw.titlePos === 'object') {
        if (isFinite(+raw.titlePos.x)) d.titlePos.x = Math.max(0, Math.min(d.paper.w - 20, +raw.titlePos.x));
        if (isFinite(+raw.titlePos.y)) d.titlePos.y = Math.max(2, Math.min(d.paper.h - 12, +raw.titlePos.y));
    }
    d.pgnum = { on: false, x: 0, y: 12, size: 3.2, fmt: '' };
    if (raw.pgnum && typeof raw.pgnum === 'object') {
        d.pgnum.on = !!raw.pgnum.on;
        if (isFinite(+raw.pgnum.x)) d.pgnum.x = Math.max(0, Math.min(d.paper.w - 20, +raw.pgnum.x));
        if (isFinite(+raw.pgnum.y)) d.pgnum.y = Math.max(2, Math.min(d.paper.h - 8, +raw.pgnum.y));
        if (isFinite(+raw.pgnum.size)) d.pgnum.size = Math.min(8, Math.max(2, +raw.pgnum.size));
        d.pgnum.fmt = String(raw.pgnum.fmt || '').slice(0, 60);
    }
    // 自定义文本（含默认班级/姓名行）：旧模板无 texts 时按默认播种，保持外观不变
    d.texts = [];
    if (Array.isArray(raw.texts)) {
        raw.texts.forEach(function (t) { if (d.texts.length < 10) { var n = mkText(t || {}, d.paper); if (n) { n.pg = Math.min(d.pages - 1, n.pg); d.texts.push(n); } } });
    }
    if (!d.texts.length) d.texts = defaultTexts(d.paper);
    if (CP >= d.pages) CP = d.pages - 1;
    return d;
}

// ===== 几何计算（毫米）：设计器渲染 / PDF / 保存的 bubbles 三者共用同一套坐标 =====
// 值头：横向（v，每位一行 0-9 横排）在顶部加一行 0-9 数字；纵向（h，每位一列 0-9 竖排）在左侧加一列 0-9 数字
function idGeom() {
    var id = L.id, headW = 4, colW = id.bw + id.hgap;
    var vert = (id.orient === 'v');
    var g = vert
        ? { x: id.x, y: id.y, w: headW + 10 * colW + 1, h: ID_LABEL_H + VAL_H + id.digits * (id.bh + id.vgap),
            label: (id.idmode === 'no' ? '编号（请涂对应数字）' : '座号（请涂对应数字）'), bubbles: [] }
        : { x: id.x, y: id.y, w: headW + id.digits * colW + 1, h: ID_LABEL_H + 10 * (id.bh + id.vgap),
            label: (id.idmode === 'no' ? '编号（请涂对应数字）' : '座号（请涂对应数字）'), bubbles: [] };
    for (var i = 0; i < id.digits; i++)
        for (var v = 0; v <= 9; v++)
            g.bubbles.push(vert
                ? { t: 'id', i: i, v: String(v),
                    x: g.x + headW + v * colW + (colW - id.bw) / 2,
                    y: g.y + ID_LABEL_H + VAL_H + i * (id.bh + id.vgap), w: id.bw, h: id.bh }
                : { t: 'id', i: i, v: String(v),
                    x: g.x + headW + i * colW + (colW - id.bw) / 2,
                    y: g.y + ID_LABEL_H + v * (id.bh + id.vgap), w: id.bw, h: id.bh });
    return g;
}
function optLabel(sec, o) {
    return sec.kind === 'judge' ? (o === 0 ? '对' : '错') : LETTERS[o];
}
function secGeom(sec, sIdx) {
    if (sec.kind === 'blank') {
        // 填空题：每行「题号 ______」，一行 perRow 列、横线 lineLen mm；无涂框（手写题不自动判分）
        var slotW = QW + sec.lineLen, rows = Math.ceil(sec.count / sec.perRow);
        var g = { x: sec.x, y: sec.y, w: Math.min(sec.count, sec.perRow) * slotW,
                  h: ID_LABEL_H + rows * BLANK_ROW_H, hdrs: [], nums: [], bubbles: [], lins: [] };
        for (var q = 0; q < sec.count; q++) {
            var row = Math.floor(q / sec.perRow), col = q % sec.perRow;
            g.nums.push({ t: (sec.start + q) + '.', row: row, col: col });
            g.lins.push({ x: sec.x + col * slotW + QW, y: sec.y + ID_LABEL_H + row * BLANK_ROW_H + BLANK_ROW_H - 2, w: sec.lineLen });
        }
        return g;
    }
    if (sec.kind === 'short') {
        // 简答题：题号+题目占一整行（设宽后按宽自动换行），下方虚线作答框（boxW 0=自动宽）
        var tw2 = sec.qtext ? textW({ text: sec.qtext, size: 3.2 }) : 0;
        var w2 = sec.boxW > 0 ? sec.boxW : Math.min(L.paper.w - sec.x - 8, Math.max(60, QW + tw2 + 2));
        var lines = sec.qtext ? Math.max(1, Math.ceil(tw2 / Math.max(1, w2 - QW))) : 0;
        var boxY = sec.y + ID_LABEL_H + (lines ? lines * LINE_H + 1 : 0);
        var g2 = { x: sec.x, y: sec.y, w: Math.max(w2, QW + 4), h: (boxY - sec.y) + sec.boxH,
                   hdrs: [], nums: [], bubbles: [], lins: [],
                   qtext: sec.qtext, qw: w2 - QW, qy: sec.y + ID_LABEL_H,
                   box: { x: sec.x, y: boxY, w: w2, h: sec.boxH }, qno: String(sec.start) };
        return g2;
    }
    var slotW = QW + sec.opts * (sec.bw + sec.ogap);
    var rows = Math.ceil(sec.count / sec.perRow);
    var g = { x: sec.x, y: sec.y, w: Math.min(sec.count, sec.perRow) * slotW - sec.ogap + 1,
              h: ID_LABEL_H + COLHDR_H + rows * (sec.bh + sec.rgap) - sec.rgap, hdrs: [], nums: [], bubbles: [], lins: [] };
    // 列头字母按「列」标注：每个题槽（列）的每个选项列顶部各标一次，各行题目按列对齐共享列头，省空间且不易误判
    for (var c = 0; c < Math.min(sec.count, sec.perRow); c++)
        for (var o = 0; o < sec.opts; o++)
            g.hdrs.push({ t: optLabel(sec, o), cx: c * slotW + QW + o * (sec.bw + sec.ogap) + sec.bw / 2 });
    for (var q = 0; q < sec.count; q++) {
        var row = Math.floor(q / sec.perRow), col = q % sec.perRow, qno = String(sec.start + q);
        g.nums.push({ t: qno + '.', row: row, col: col });
        for (var o2 = 0; o2 < sec.opts; o2++)
            g.bubbles.push({ t: 'q', s: sIdx, q: qno, o: LETTERS[o2],
                x: sec.x + col * slotW + QW + o2 * (sec.bw + sec.ogap),
                y: sec.y + ID_LABEL_H + COLHDR_H + row * (sec.bh + sec.rgap),
                w: sec.bw, h: sec.bh });
    }
    return g;
}
function marksGeom(fr) {
    var s = 5;   // 标记 5mm，中心=取景框角
    var arr = [{ x: fr.x - s / 2, y: fr.y - s / 2 }, { x: fr.x + fr.w - s / 2, y: fr.y - s / 2 },
               { x: fr.x - s / 2, y: fr.y + fr.h - s / 2 }, { x: fr.x + fr.w - s / 2, y: fr.y + fr.h - s / 2 }]
        .map(function (p) { return { x: p.x, y: p.y, w: s, h: s }; });
    // 边缘中点码点（edgeMidMarks）：左/右/下边正中各 1 枚（顶边被页码点占用不补，防重叠）。
    // 识别端据此做预览分段拉直（Coons patch）+ 角标缺失冗余；识别端 edgeMidMarkCenters 同源
    if (L.edgeMidMarks) arr.push({ x: fr.x - s / 2, y: fr.y + fr.h / 2 - s / 2, w: s, h: s },
                                 { x: fr.x + fr.w - s / 2, y: fr.y + fr.h / 2 - s / 2, w: s, h: s },
                                 { x: fr.x + fr.w / 2 - s / 2, y: fr.y + fr.h - s / 2, w: s, h: s });
    return arr;
}
// 页头定位点（强制项）：每页取景框上边印 k=页码+1 枚（第1页2枚、第2页3枚…）5mm 黑方块。
// 两种排布（与识别端 omr_scan.php hmarkPosList 同源）：
// ① 聚簇新版式（hmarkCluster=true）：首枚中心距框左缘 10mm（点径5+间隙5），向右每枚 +10mm ——
//    点簇贴近左上角点、偏离中线，镜像采集时点簇落到右上 → 结构性判镜像，比涂块证据更稳；
//    且位置由角点锚定推算，不依赖横向拉伸比例，漏检概率更低
// ② 对称旧版式（false）：自 22% 至 78% 等距分布（k=2 时即 22%/78%，与已印旧卡一致）
// 四角方块全同只能定四边形、不能定阅读方向；页头点数量随页数递增 → 识别端数页头点定方向与页码
function hmarkGeom(fr, pg) {
    var s = 5, k = pg + 2, arr = [];
    for (var j = 0; j < k; j++) {
        var cx = L.hmarkCluster ? fr.x + (j + 1) * 10
                                : fr.x + fr.w * (0.22 + 0.56 * (k > 1 ? j / (k - 1) : 0.5));
        arr.push({ x: cx - s / 2, y: fr.y - s / 2, w: s, h: s });
    }
    return arr;
}
// 按内容实际范围计算取景框（定位标记中心=框角，随模板保存供识别端映射）：
// 题量少时框随内容收紧 → 纸张可裁小、拍摄距离可拉近，单卡像素密度更高、识别率更好。
// 多页模板每页分别计算：仅第 1 页含标题；身份区与底部提示行每页都有
function contentFrame(pg) {
    if (pg === undefined) pg = CP;
    var W = L.paper.w, H = L.paper.h, pad = 8;   // pad = 标记半宽2.5 + 孤立性检查4 + 余量
    var x0 = W / 2, y0 = 5, x1 = W / 2, y1 = 11;
    if (pg === 0) {
        var tw = Math.min(W - 8, textW({ text: L.title || '答题卡', size: 4.6 }) * 1.12);   // 标题墨迹估算（居中）
        var tpx = L.titlePos ? (+L.titlePos.x || 0) : 0, tpy = L.titlePos ? (+L.titlePos.y || 5) : 5;
        x0 = Math.min(x0, tpx + (W - tw) / 2); x1 = Math.max(x1, tpx + (W + tw) / 2);
        y0 = Math.min(y0, tpy); y1 = Math.max(y1, tpy + 6);
    }
    if (L.pgnum && L.pgnum.on) {   // 页码行墨迹（可拖动，与识别端 omr_scan.php 同源）
        var pnT = pgnumText(pg), pnS = +L.pgnum.size || 3.2;
        var pnW = Math.min(W - 8, textW({ text: pnT, size: pnS }) * 1.1);
        var pnX = +L.pgnum.x || 0, pnY = +L.pgnum.y || 12;
        x0 = Math.min(x0, pnX + Math.max(0, (W - pnW) / 2)); x1 = Math.max(x1, pnX + (W + pnW) / 2);
        y0 = Math.min(y0, pnY); y1 = Math.max(y1, pnY + pnS + 1);
    }
    L.texts.forEach(function (t) {
        if ((t.pg || 0) !== pg) return;
        var ink = Math.min(W - 8, textW(t) * 1.1), w = t.w > 0 ? t.w : ink;
        var tx0 = (t.w > 0 && t.center) ? t.x + Math.max(0, (t.w - ink) / 2) : t.x;
        y0 = Math.min(y0, t.y); y1 = Math.max(y1, t.y + t.size + 1);
        x0 = Math.min(x0, tx0); x1 = Math.max(x1, Math.min(W, tx0 + w));
    });
    var idg = idGeom();
    x0 = Math.min(x0, idg.x); y0 = Math.min(y0, idg.y);
    x1 = Math.max(x1, idg.x + idg.w); y1 = Math.max(y1, idg.y + idg.h);
    L.sections.forEach(function (sec, i) {
        if ((sec.pg || 0) !== pg) return;
        var g = secGeom(sec, i);
        x0 = Math.min(x0, g.x); y0 = Math.min(y0, g.y);
        x1 = Math.max(x1, g.x + g.w); y1 = Math.max(y1, g.y + g.h);
    });
    var tipY = Math.min(y1 + 3, H - 7);   // 底部提示行紧贴内容下方（动态），一并纳入取景框
    y1 = tipY + 4;
    var fx = Math.max(6, x0 - pad), fy = Math.max(6, y0 - pad);
    return { x: fx, y: fy,
             w: Math.max(30, Math.min(W - 6, x1 + pad) - fx),
             h: Math.max(30, Math.min(H - 6, y1 + pad) - fy),
             tipY: tipY };
}
// ===== 多页：自动分页（内容超出页底自动切到下一页）+ 页签 =====
// 逐页按 y 序检查题组/文本：底部越过 paper.h-6 的项顺流到下一页（必要时自动加页，上限 MAXP），
// 放到目标页当前内容底部下方 7mm 处（x 不变）。拖拽结束/参数修改/保存/导出时触发
// （渲染入口统一调用，拖拽中不生效以免跳块）
function pageFlowY(p) {
    var y = idGeom().y + idGeom().h;
    L.sections.forEach(function (s) { if ((s.pg || 0) === p) y = Math.max(y, s.y + secGeom(s, 0).h); });
    L.texts.forEach(function (t) { if ((t.pg || 0) === p) y = Math.max(y, t.y + t.size + 1); });
    return y;
}
function autoPaginate() {
    var changed = false;
    for (var p = 0; p < L.pages; p++) {
        var items = [];
        L.sections.forEach(function (s, i) { if ((s.pg || 0) === p) items.push({ it: s, tp: 'sec', idx: i, g: secGeom(s, i) }); });
        L.texts.forEach(function (t, i) { if ((t.pg || 0) === p) items.push({ it: t, tp: 'text', idx: i, g: { x: t.x, y: t.y, w: t.w || textW(t), h: t.size + 1 } }); });
        items.sort(function (a, b) { return a.g.y - b.g.y; });
        for (var k = 0; k < items.length; k++) {
            var m = items[k];
            if (m.g.y + m.g.h <= L.paper.h - 6) continue;   // 本页放得下
            if (L.pages < MAXP) { L.pages++; changed = true; }
            var np = p + 1;
            if (np >= L.pages) continue;   // 已达最大页数仍放不下：保留原地，由校验报「超出纸面」
            m.it.pg = np;
            m.it.y = Math.min(Math.max(20, pageFlowY(np) + 7), L.paper.h - m.g.h - 6);
            m.g.y = m.it.y;
            changed = true;
        }
    }
    if (CP >= L.pages) CP = L.pages - 1;
    return changed;
}
function renderPageBar() {
    var bar = document.getElementById('pageBar');
    var html = '<span style="font-size:12px;color:#888;margin-right:2px;">页面：</span>';
    for (var i = 0; i < L.pages; i++)
        html += '<button type="button" class="pg-tab' + (i === CP ? ' on' : '') + '" onclick="setPage(' + i + ')">第 ' + (i + 1) + ' 页（' + (i + 2) + ' 点）</button>';
    if (L.pages < MAXP) html += '<button type="button" class="pg-tab" onclick="addPage()">＋添加页</button>';
    if (L.pages > 1) html += '<button type="button" class="pg-tab" onclick="delLastPage()" style="color:#e74c3c;">🗑 删除末页</button>';
    html += '<span style="font-size:12px;color:#aaa;margin-left:6px;">每页均重复身份涂号区与页头定位点；内容超出页底自动切到下一页</span>';
    bar.innerHTML = html;
}
function setPage(p) { CP = Math.max(0, Math.min(L.pages - 1, p)); sel = null; render(); renderProps(); }
function addPage() {
    if (L.pages >= MAXP) { toast('最多 ' + MAXP + ' 页'); return; }
    L.pages++; L.dup = 1;   // 多页不支持整页填充，加页时复位
    CP = L.pages - 1; sel = null; render(); renderProps();
}
function delLastPage() {
    if (L.pages <= 1) return;
    var has = L.sections.some(function (s) { return (s.pg || 0) === L.pages - 1; })
        || L.texts.some(function (t) { return (t.pg || 0) === L.pages - 1; });
    if (has && !confirm('末页还有内容，删除将把它们移到上一页，继续？')) return;
    L.sections.forEach(function (s) { if ((s.pg || 0) === L.pages - 1) s.pg = L.pages - 2; });
    L.texts.forEach(function (t) { if ((t.pg || 0) === L.pages - 1) t.pg = L.pages - 2; });
    L.pages--; CP = L.pages - 1; sel = null; render(); renderProps();
}
function computeBubbles() {
    var arr = idGeom().bubbles;
    L.sections.forEach(function (sec, i) { arr = arr.concat(secGeom(sec, i).bubbles); });
    return arr;
}
function geomOf(type, idx) { return (type === 'id') ? idGeom() : secGeom(L.sections[idx], idx); }

// ===== 校验 =====
function bubLabel(b) {
    return b.t === 'id' ? '身份区第' + (b.i + 1) + '位·数字' + b.v : '第' + b.q + '题·选项' + b.o;
}
// 每页的涂框集合（身份区每页重复，按页分组后逐页校验，避免跨页同坐标误报重叠）
function bubblesPerPage() {
    var map = {};
    for (var p = 0; p < L.pages; p++) map[p] = idGeom().bubbles.slice();
    L.sections.forEach(function (sec, i) {
        var arr = map[sec.pg || 0] || (map[sec.pg || 0] = []);
        secGeom(sec, i).bubbles.forEach(function (b) { arr.push(b); });
    });
    return map;
}
function validateLayout() {
    var errs = [];
    if (!L.sections.length) errs.push('必须有答题区域：请至少添加一个题组');
    if (L.id.digits < 1) errs.push('必须有身份识别区域：涂号区位数至少 1 位');
    if (L.id.digits > 10) errs.push('身份涂号区位数最多 10 位');
    var bubbles = computeBubbles();
    if (!bubbles.length) errs.push('没有涂框');
    if (bubbles.length > 2800) errs.push('涂框数量过多（' + bubbles.length + ' > 2800），请减少题量');
    var W = L.paper.w, H = L.paper.h;
    // 超出纸面 / 定位标记净空：按页校验（身份区每页重复，坐标相同属正常）
    for (var pg = 0; pg < L.pages; pg++) {
        var pb = (bubblesPerPage()[pg] || []);
        pb.forEach(function (b) {
            if (b.x < 2 || b.y < 2 || b.x + b.w > W - 2 || b.y + b.h > H - 2)
                errs.push('第' + (pg + 1) + '页涂框超出纸面：' + bubLabel(b));
        });
        // 定位标记净空：四角黑方块（边长 5mm，角上留 8mm）周围 6mm 内不得有涂框，否则扫描时无法隔离定位
        var MK = 8, MS = 5, CL = 6;
        var mkRects = [[MK, MK], [W - MK - MS, MK], [W - MK - MS, H - MK - MS], [MK, H - MK - MS]];
        pb.forEach(function (b) {
            for (var k = 0; k < 4; k++) {
                var mr = mkRects[k];
                if (b.x < mr[0] + MS + CL && b.x + b.w > mr[0] - CL && b.y < mr[1] + MS + CL && b.y + b.h > mr[1] - CL) {
                    errs.push('第' + (pg + 1) + '页涂框距定位标记过近：' + bubLabel(b) + '（四角黑方块周围需留 6mm 净空）');
                    break;
                }
            }
        });
        // 涂框重叠（O(n²)/页，n≤2800 一次性可接受）
        for (var i = 0; i < pb.length; i++)
            for (var j = i + 1; j < pb.length; j++) {
                var p1 = pb[i], r1 = pb[j];
                if (p1.x < r1.x + r1.w - 0.4 && r1.x < p1.x + p1.w - 0.4 && p1.y < r1.y + r1.h - 0.4 && r1.y < p1.y + p1.h - 0.4) {
                    errs.push('第' + (pg + 1) + '页涂框重叠：' + bubLabel(p1) + ' 与 ' + bubLabel(r1)); i = pb.length; break;
                }
            }
    }
    // 题号范围重叠检查
    for (var a = 0; a < L.sections.length; a++)
        for (var b2 = a + 1; b2 < L.sections.length; b2++) {
            var s1 = L.sections[a], s2 = L.sections[b2];
            if (s1.start + s1.count - 1 >= s2.start && s2.start + s2.count - 1 >= s1.start)
                errs.push('题号范围重叠：「' + s1.title + '」与「' + s2.title + '」（' + s1.start + '-' + (s1.start + s1.count - 1) + ' 与 ' + s2.start + '-' + (s2.start + s2.count - 1) + '）');
        }
    // 答案键字母合法性（仅选择类题组；填空/简答为手写题无答案键）
    L.sections.forEach(function (s) {
        if (!isChoiceKind(s.kind)) return;
        Object.keys(s.key).forEach(function (q) {
            var bad = String(s.key[q]).split('').some(function (c) { return LETTERS.indexOf(c) < 0 || LETTERS.indexOf(c) >= s.opts; });
            if (bad) errs.push('「' + s.title + '」第' + q + '题答案含未启用的选项');
        });
    });
    return errs;
}
function refreshWarn() {
    var errs = validateLayout(), el = document.getElementById('vWarn');
    el.style.display = errs.length ? 'block' : 'none';
    el.textContent = '⚠ ' + errs.slice(0, 3).join('；') + (errs.length > 3 ? ' 等 ' + errs.length + ' 个问题' : '');
}

// ===== 渲染（预览画布） =====
function mm(v) { return Math.round(v * S * 100) / 100; }
function mkDiv(cls, x, y, w, h, txt, fsMM, bold, center) {
    var d = document.createElement('div');
    d.className = cls;
    d.style.left = mm(x) + 'px'; d.style.top = mm(y) + 'px';
    if (w !== null) d.style.width = mm(w) + 'px';
    if (h !== null) d.style.height = mm(h) + 'px';
    if (txt !== undefined) {
        d.textContent = txt;
        d.style.fontSize = (fsMM * S) + 'px';
        d.style.fontWeight = bold ? 'bold' : 'normal';
        if (center) { d.style.textAlign = 'center'; }
    }
    return d;
}
function mkFrame(x, y, w, h) {
    var d = document.createElement('div');
    d.className = 'grp';
    d.style.left = mm(x) + 'px'; d.style.top = mm(y) + 'px';
    d.style.width = mm(w) + 'px'; d.style.height = mm(h) + 'px';
    return d;
}
function render() {
    if (!drag) autoPaginate();   // 内容超出页底自动切到下一页（拖拽中不生效，松手/改参后生效）
    renderPageBar();
    var sheet = document.getElementById('sheet');
    sheet.style.width = mm(L.paper.w) + 'px'; sheet.style.height = mm(L.paper.h) + 'px';
    sheet.innerHTML = '';
    // 取景框随内容收紧（每页独立）：标记中心=框角（保存进 layout 供识别端映射），画布上以浅虚线预览（导出时不打印）
    var fr = contentFrame(CP);
    L.frame = fr;
    marksGeom(fr).forEach(function (m) {
        var d = mkDiv('mark', m.x, m.y, m.w, m.h); sheet.appendChild(d);
    });
    // 页头定位点（强制）：本页取景框上边 2/3/4… 枚（第 1 页 2 枚、第 2 页 3 枚…），打印/PDF 同样带上
    hmarkGeom(fr, CP).forEach(function (m) {
        var d = mkDiv('mark', m.x, m.y, m.w, m.h); sheet.appendChild(d);
    });
    sheet.appendChild(mkDiv('frameguide', fr.x, fr.y, fr.w, fr.h));
    // 表头：标题（仅第 1 页，可点选/拖动调位置）+ 页码行（每页，可点选/拖动/编辑格式）；底部提示行动态 y
    if (CP === 0) {
        var titleEl = mkDiv('txt txt-el' + (sel && sel.type === 'title' ? ' txt-sel' : ''),
                            L.titlePos.x, L.titlePos.y, L.paper.w, 6, L.title || '答题卡', 4.6, true, true);
        titleEl.id = 'sheetTitle';
        titleEl.dataset.head = 'title';
        titleEl.addEventListener('pointerdown', function (e) { onHeadDown(e, 'title'); });
        sheet.appendChild(titleEl);
    }
    if (L.pgnum && L.pgnum.on) {
        var pnEl = mkDiv('txt txt-el' + (sel && sel.type === 'pgnum' ? ' txt-sel' : ''),
                         L.pgnum.x, L.pgnum.y, L.paper.w, (+L.pgnum.size || 3.2) + 2, pgnumText(CP), +L.pgnum.size || 3.2, false, true);
        pnEl.dataset.head = 'pgnum';
        pnEl.addEventListener('pointerdown', function (e) { onHeadDown(e, 'pgnum'); });
        sheet.appendChild(pnEl);
    }
    sheet.appendChild(mkDiv('txt', 0, fr.tipY, L.paper.w, 4, '请用 2B 铅笔或黑色签字笔将对应方框涂满涂黑；修改请擦干净', 3.0, false, true));
    // 身份区（每页重复）+ 本页题组块
    renderBlock(sheet, 'id', 0, idGeom());
    L.sections.forEach(function (sec, i) { if ((sec.pg || 0) === CP) renderBlock(sheet, 'sec', i, secGeom(sec, i)); });
    // 自定义文本行（仅本页；含默认「班级/姓名」行：可编辑内容、可拖动移位，见「＋文本」）；置于块层之上便于点击选中
    L.texts.forEach(function (t, ti) {
        if ((t.pg || 0) !== CP) return;
        var d = mkDiv('txt txt-el' + (sel && sel.type === 'text' && sel.idx === ti ? ' txt-sel' : ''),
                      t.x, t.y, t.w > 0 ? t.w : null, null, t.text, t.size, false, t.w > 0 && t.center);
        d.dataset.tidx = String(ti);
        d.addEventListener('pointerdown', function (e) { onTextDown(e, ti); });
        sheet.appendChild(d);
    });
    updateDupBtn();
    refreshWarn();
}
function renderBlock(sheet, type, idx, g) {
    var it = (type === 'id') ? L.id : L.sections[idx];
    var blk = document.createElement('div');
    blk.className = 'blk' + (sel && sel.type === type && sel.idx === idx ? ' blk-sel' : ' blk-hover');
    blk.dataset.btype = type; blk.dataset.bidx = String(idx);
    blk.style.left = mm(g.x) + 'px'; blk.style.top = mm(g.y) + 'px';
    blk.style.width = mm(g.w) + 'px'; blk.style.height = mm(g.h) + 'px';
    blk.appendChild(mkDiv('txt', 0, 0, g.w, ID_LABEL_H, g.label || it.title, 3.4, true));
    if (type === 'id') {
        // 数字值头：横向（v）在顶部加一行 0-9（各行按列对齐共用）；纵向（h）在左侧加一列 0-9（各列按行对齐共用），
        // 配合每位浅色实线分组框，学生一眼可辨填涂方向与每个框对应的数字
        var headW = 4, padR = Math.min(0.8, it.vgap / 2), padC = Math.min(0.8, it.hgap / 2);
        if (it.orient === 'v') {
            for (var v9 = 0; v9 <= 9; v9++)
                blk.appendChild(mkDiv('txt', headW + v9 * (it.bw + it.hgap) + it.bw / 2 - 2.5, ID_LABEL_H, 5, VAL_H, String(v9), 2.8, true, true));
            for (var di = 0; di < it.digits; di++)
                blk.appendChild(mkFrame(headW - 1, ID_LABEL_H + VAL_H + di * (it.bh + it.vgap) - padR,
                                        10 * (it.bw + it.hgap) - it.hgap + 2, it.bh + padR * 2));
        } else {
            for (var vj = 0; vj <= 9; vj++)
                blk.appendChild(mkDiv('txt', 0.2, ID_LABEL_H + vj * (it.bh + it.vgap), headW - 0.4, it.bh, String(vj), 2.8, true, true));
            for (var dj = 0; dj < it.digits; dj++)
                blk.appendChild(mkFrame(headW + dj * (it.bw + it.hgap) - padC, ID_LABEL_H - padC,
                                        it.bw + padC * 2, 10 * (it.bh + it.vgap) - it.vgap + padC * 2));
        }
    }
    if (type === 'sec') {
        if (it.kind === 'multi') blk.appendChild(mkDiv('txt', g.w + 1.5, 0, 20, ID_LABEL_H, '〔多选〕', 2.8, true));
        if (it.kind === 'blank') {
            // 填空题：每行「题号 ______」（横线为实线，题号列宽与选择类一致）
            g.nums.forEach(function (n) {
                var y = ID_LABEL_H + n.row * BLANK_ROW_H;
                blk.appendChild(mkDiv('txt', n.col * (QW + it.lineLen), y, QW, BLANK_ROW_H - 2, n.t, 3.0, false));
            });
            g.lins.forEach(function (ln) {
                var d = mkDiv('dline', ln.x - g.x, ln.y - g.y, ln.w, 0);
                blk.appendChild(d);
            });
        } else if (it.kind === 'short') {
            // 简答题：题号 + 题目（设宽后自动换行）+ 虚线作答框
            blk.appendChild(mkDiv('txt', 0, ID_LABEL_H, QW, LINE_H, g.qno + '.', 3.2, false));
            if (g.qtext) {
                var qd = document.createElement('div');
                qd.className = 'txt';
                qd.style.left = mm(QW) + 'px'; qd.style.top = mm(g.qy - g.y) + 'px';
                qd.style.width = mm(g.qw) + 'px';
                qd.style.fontSize = (3.2 * S) + 'px';
                qd.style.whiteSpace = 'normal'; qd.style.wordBreak = 'break-all'; qd.style.lineHeight = '1.375';
                qd.textContent = g.qtext;
                blk.appendChild(qd);
            }
            var bd = mkDiv('dbox', g.box.x - g.x, g.box.y - g.y, g.box.w, g.box.h);
            blk.appendChild(bd);
        } else {
            g.hdrs.forEach(function (h) {
                blk.appendChild(mkDiv('txt', h.cx - 2.5, ID_LABEL_H, 5, COLHDR_H, h.t, 3.0, true, true));
            });
            g.nums.forEach(function (n) {
                var y = ID_LABEL_H + COLHDR_H + n.row * (it.bh + it.rgap);
                blk.appendChild(mkDiv('txt', n.col * (QW + it.opts * (it.bw + it.ogap)), y, QW, it.bh, n.t, 3.0, false));
            });
        }
    }
    g.bubbles.forEach(function (b) {
        var d = mkDiv('bub', b.x - g.x, b.y - g.y, b.w, b.h);
        if (type === 'sec' && isChoiceKind(it.kind)) {
            // 选择类涂框可点选直接设置/取消正确答案（绿色=已设；导出 PDF 时恢复空白外观）
            if (String(it.key[b.q] || '').indexOf(b.o) >= 0) d.classList.add('bub-key');
            d.classList.add('bub-k');
            d.dataset.s = String(b.s); d.dataset.q = String(b.q); d.dataset.o = String(b.o);
            d.title = '点击设为/取消该题正确答案';
        }
        blk.appendChild(d);
    });
    blk.addEventListener('pointerdown', function (e) { onBlockDown(e, type, idx); });
    sheet.appendChild(blk);
}

// ===== 选中与拖拽（鼠标/触屏 Pointer Events，0.5mm 吸附，方向键微调） =====
function snap(v) { return Math.round(v * 2) / 2; }
function select(type, idx) { sel = { type: type, idx: idx }; renderProps(); render(); }
function onBlockDown(e, type, idx) {
    var it = (type === 'id') ? L.id : L.sections[idx];
    // 记录按下目标：选择类题组的涂框，松手未拖动 = 点选设/取消正确答案
    // （select→render 会重建元素，click 事件派发不可靠，改在 pointerup 判定）
    pendKey = (type === 'sec' && e.target && e.target.classList && e.target.classList.contains('bub-k'))
        ? { s: e.target.dataset.s, q: e.target.dataset.q, o: e.target.dataset.o } : null;
    select(type, idx);
    drag = { type: type, idx: idx, it: it, px: e.clientX, py: e.clientY, ox: it.x, oy: it.y, g: geomOf(type, idx) };
    dragMoved = false;
    e.preventDefault();
}
// ===== 自定义文本元素（含默认班级/姓名行）：选中、拖拽、编辑 =====
function textW(t) {
    var w = 0;
    for (var i = 0; i < t.text.length; i++) w += (t.text.charCodeAt(i) > 255 ? 1 : 0.58) * t.size;
    return w;
}
function textIt() { return (sel && sel.type === 'text') ? L.texts[sel.idx] : null; }
function onTextDown(e, idx) {
    select('text', idx);
    var t = L.texts[idx];
    drag = { type: 'text', idx: idx, it: t, px: e.clientX, py: e.clientY, ox: t.x, oy: t.y,
             g: { x: t.x, y: t.y, w: textW(t) + 2, h: t.size + 2 } };
    dragMoved = false; pendKey = null;
    e.preventDefault();
}
// 标题/页码：点选与拖拽（居中排印，X=整体左右偏移、Y=上下位置；可拖宽度=墨迹宽，与 contentFrame 估算同源）
function onHeadDown(e, kind) {
    sel = { type: kind, idx: 0 };
    renderProps();
    var it = (kind === 'title') ? L.titlePos : L.pgnum;
    var inkW;
    if (kind === 'title') inkW = Math.min(L.paper.w, textW({ text: L.title || '答题卡', size: 4.6 }) * 1.12);
    else inkW = Math.min(L.paper.w, textW({ text: pgnumText(CP), size: +L.pgnum.size || 3.2 }) * 1.1);
    drag = { type: kind, idx: 0, it: it, px: e.clientX, py: e.clientY, ox: it.x, oy: it.y,
             g: { x: it.x, y: it.y, w: Math.max(10, inkW), h: (kind === 'title') ? 6 : ((+L.pgnum.size || 3.2) + 2) } };
    dragMoved = false; pendKey = null;
    e.preventDefault();
}
// 页码行文字：fmt 支持 {p}=页号、{n}=总页数；留空=「第 N 页」（多页加「/ 共 M 页」）（与识别端同源）
function pgnumText(p) {
    var f = String((L.pgnum && L.pgnum.fmt) || '').trim();
    if (f) return f.split('{p}').join(p + 1).split('{n}').join(L.pages);
    return '第 ' + (p + 1) + ' 页' + (L.pages > 1 ? ' / 共 ' + L.pages + ' 页' : '');
}
function addText() {
    if (L.texts.length >= 10) { toast('自定义文本最多 10 条'); return; }
    var maxY = 20;
    L.texts.forEach(function (t) { maxY = Math.max(maxY, t.y + t.size + 2); });
    L.texts.push({ text: '在此输入自定义文本', x: 14, y: Math.min(maxY, L.paper.h - 12), size: 3.2, w: 0, center: false });
    select('text', L.texts.length - 1);
}
function delText() {
    if (!sel || sel.type !== 'text') return;
    if (!confirm('确定删除该文本？')) return;
    L.texts.splice(sel.idx, 1);
    sel = null; renderProps(); render();
}
function chText(k, v) {
    var t = textIt(); if (!t) return;
    if (k === 'text') {
        t.text = String(v).slice(0, 120);
        var el = document.querySelector('#sheet .txt-el[data-tidx="' + sel.idx + '"]');
        if (el) el.textContent = t.text;
        return;
    }
    if (k === 'center') t.center = (v === '1');
    else if (k === 'w') t.w = Math.max(0, Math.min(L.paper.w, parseFloat(v) || 0));
    else if (k === 'size') t.size = Math.min(8, Math.max(2, parseFloat(v) || 3.2));
    render(); renderProps();
}
// ===== 整页填充：打印/PDF 时每页复制 N 份内容块（每份自带定位标记，裁开可单独识别），预览不显示 =====
function toggleDup() {
    if (L.pages > 1) { toast('多页模板每页内容与页头点数不同，不支持整页填充'); return; }
    if ((L.dup || 1) === 1) {
        var fr = L.frame || contentFrame();
        var n = Math.min(3, Math.floor(L.paper.h / (fr.h + 6)));   // 块外留白 3mm×2
        if (n < 2) { toast('内容占页超过一半，无法整页填充'); return; }
        L.dup = n;
    } else L.dup = 1;
    updateDupBtn();
}
function updateDupBtn() {
    var b = document.getElementById('dupBtn');
    if (!b) return;
    var on = (L.dup || 1) > 1;
    b.className = 'btn btn-sm ' + (on ? 'btn-success' : 'btn-outline');
    b.textContent = on ? ('🧻 整页填充 ×' + L.dup) : '🧻 整页填充';
}
document.addEventListener('pointermove', function (e) {
    if (!drag) return;
    if (Math.abs(e.clientX - drag.px) + Math.abs(e.clientY - drag.py) > 3) dragMoved = true;
    var dx = (e.clientX - drag.px) / S, dy = (e.clientY - drag.py) / S;
    var nx = snap(Math.max(0, Math.min(L.paper.w - drag.g.w, drag.ox + dx)));
    var ny = snap(Math.max(0, Math.min(L.paper.h - drag.g.h, drag.oy + dy)));
    drag.it.x = nx; drag.it.y = ny;
    var blk;
    if (drag.type === 'text') blk = document.querySelector('#sheet .txt-el[data-tidx="' + drag.idx + '"]');
    else if (drag.type === 'title' || drag.type === 'pgnum') blk = document.querySelector('#sheet .txt-el[data-head="' + drag.type + '"]');
    else blk = document.querySelector('#sheet .blk[data-btype="' + drag.type + '"][data-bidx="' + drag.idx + '"]');
    if (blk) { blk.style.left = mm(nx) + 'px'; blk.style.top = mm(ny) + 'px'; }
    e.preventDefault();
});
document.addEventListener('pointerup', function () {
    if (!drag) return;
    var pk = pendKey, moved = dragMoved;
    pendKey = null; drag = null; render(); syncXYInputs();
    if (pk && !moved) toggleKeyAbs(parseInt(pk.s, 10), pk.q, pk.o);   // 涂框点选：未拖动才触发
});
document.addEventListener('keydown', function (e) {
    if (!sel || drag) return;
    if (['INPUT', 'SELECT', 'TEXTAREA'].indexOf(document.activeElement.tagName) >= 0) return;
    var it = (sel.type === 'id') ? L.id : (sel.type === 'text') ? L.texts[sel.idx]
           : (sel.type === 'title') ? L.titlePos : (sel.type === 'pgnum') ? L.pgnum
           : L.sections[sel.idx];
    if (!it) return;
    var step = e.shiftKey ? 2 : 0.5, moved = true;
    if (e.key === 'ArrowLeft') it.x -= step; else if (e.key === 'ArrowRight') it.x += step;
    else if (e.key === 'ArrowUp') it.y -= step; else if (e.key === 'ArrowDown') it.y += step;
    else moved = false;
    if (moved) { e.preventDefault(); it.x = Math.round(it.x * 2) / 2; it.y = Math.round(it.y * 2) / 2; render(); syncXYInputs(); }
});

// ===== 属性面板 =====
function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); }
function renderProps() {
    var box = document.getElementById('propsBody');
    var html = '';
    html += '<div class="subttl">📄 纸张与标题</div><div class="pgrid">';
    html += '<div class="pitem"><label>纸张</label><select id="p_paper" onchange="chPaper(this.value)">'
         + '<option value="p"' + (L.paper.w <= L.paper.h ? ' selected' : '') + '>A4 竖版 210×297</option>'
         + '<option value="l"' + (L.paper.w > L.paper.h ? ' selected' : '') + '>A4 横版 297×210</option></select></div>';
    html += '<div class="pitem" style="grid-column:1/3;"><label>答题卡标题</label><input id="p_title" value="' + esc(L.title) + '" oninput="chTitle(this.value)" autocomplete="off"></div>';
    html += '<div class="pitem"><label>页码行</label><select onchange="chPgnum(\'on\',this.value)">'
         + '<option value="1"' + ((L.pgnum && L.pgnum.on) ? ' selected' : '') + '>显示</option>'
         + '<option value="0"' + (!(L.pgnum && L.pgnum.on) ? ' selected' : '') + '>隐藏</option></select></div>';
    html += '<div class="pitem"><label>页码点排布<button type="button" class="hint-q" onclick="toggleHint(event, \'页头定位标记说明<br>· <b>聚簇左上（新版）</b>=定位黑方块集中印在页面顶部左侧，识别更快更稳，推荐<br>· <b>22%~78% 对称（旧版）</b>=兼容旧版打印的答题卡模板<br>· 每页页头黑方块枚数=页码+1（第1页2枚、第2页3枚…），识别端据此定方向与页码<br>· 四角黑方块为定位标记，打印后请勿遮挡或裁掉\')">?</button></label><select onchange="chHmarkCluster(this.value)">'
         + '<option value="1"' + (L.hmarkCluster ? ' selected' : '') + '>聚簇左上（新版）</option>'
         + '<option value="0"' + (!L.hmarkCluster ? ' selected' : '') + '>22%~78% 对称（旧版）</option></select></div>';
    html += '<div class="pitem"><label>标题 Y mm</label><input type="number" step="0.5" value="' + ((L.titlePos && +L.titlePos.y) || 5) + '" onchange="chTitleY(this.value)" autocomplete="off"></div>';
    html += '</div>';
    if (!sel) {
        html += '<p class="tip" style="margin-top:12px;">编辑提示<button type="button" class="hint-q" onclick="toggleHint(event, \'<b>画布编辑</b><br>· 点击左侧画布中的「标题」「页码行」「身份涂号区」「题组」或「文本」进行编辑<br>· 拖动可调整位置（方向键微调，Shift 加速）\')">?</button></p>';
        box.innerHTML = html; return;
    }
    if (sel.type === 'title') {
        var tp = L.titlePos;
        html += '<div class="subttl">📝 答题卡标题（仅第 1 页印出）</div><div class="pgrid">';
        html += '<div class="pitem" style="grid-column:1/3;"><label>标题文字</label><input value="' + esc(L.title) + '" oninput="chTitle(this.value)" autocomplete="off"></div>';
        html += '<div class="pitem"><label>X mm</label><input type="number" step="0.5" id="p_x" value="' + tp.x + '" onchange="chXY(\'x\',this.value)" autocomplete="off"></div>';
        html += '<div class="pitem"><label>Y mm</label><input type="number" step="0.5" id="p_y" value="' + tp.y + '" onchange="chXY(\'y\',this.value)" autocomplete="off"></div>';
        html += '</div>';
        html += '<p class="tip" style="margin-top:10px;">标题排印<button type="button" class="hint-q" onclick="toggleHint(event, \'<b>标题排印</b><br>· 标题在画布上水平居中排印，X 轴微调整体左右偏移<br>· 也可直接拖动标题或用方向键微调位置\')">?</button></p>';
        box.innerHTML = html; return;
    }
    if (sel.type === 'pgnum') {
        var pn = L.pgnum || (L.pgnum = { on: true, x: 0, y: 12, size: 3.2, fmt: '' });
        html += '<div class="subttl">📄 页码行（每页重复印出）</div><div class="pgrid">';
        html += '<div class="pitem"><label>显示页码</label><select onchange="chPgnum(\'on\',this.value)">'
             + '<option value="1"' + (pn.on ? ' selected' : '') + '>显示</option>'
             + '<option value="0"' + (!pn.on ? ' selected' : '') + '>隐藏</option></select></div>';
        html += '<div class="pitem"><label>字号 mm（2-8）</label><input type="number" step="0.2" min="2" max="8" value="' + (+pn.size || 3.2) + '" onchange="chPgnum(\'size\',this.value)" autocomplete="off"></div>';
        html += '<div class="pitem" style="grid-column:1/3;"><label>格式（{p}=页号、{n}=总页数，留空=「第 N 页」）</label><input value="' + esc(pn.fmt) + '" onchange="chPgnum(\'fmt\',this.value)" autocomplete="off"></div>';
        html += '<div class="pitem"><label>X mm</label><input type="number" step="0.5" id="p_x" value="' + pn.x + '" onchange="chXY(\'x\',this.value)" autocomplete="off"></div>';
        html += '<div class="pitem"><label>Y mm</label><input type="number" step="0.5" id="p_y" value="' + pn.y + '" onchange="chXY(\'y\',this.value)" autocomplete="off"></div>';
        html += '</div>';
        html += '<p class="tip" style="margin-top:10px;">页码行<button type="button" class="hint-q" onclick="toggleHint(event, \'<b>页码行</b><br>· 页码行每页重复印出；多页模板建议保留，便于识别端核对页码<br>· 也可直接拖动页码行调整位置\')">?</button></p>';
        box.innerHTML = html; return;
    }
    if (sel.type === 'text') {
        var t = L.texts[sel.idx];
        if (!t) { sel = null; box.innerHTML = html; return; }
        html += '<div class="subttl">🔤 自定义文本</div><div class="pgrid">';
        html += '<div class="pitem" style="grid-column:1/3;"><label>内容（≤120 字）</label><input value="' + esc(t.text) + '" oninput="chText(\'text\',this.value)" autocomplete="off"></div>';
        html += '<div class="pitem"><label>字号 mm（2-8）</label><input type="number" step="0.2" min="2" max="8" value="' + t.size + '" onchange="chText(\'size\',this.value)" autocomplete="off"></div>';
        html += '<div class="pitem"><label>宽度 mm（0=自动）</label><input type="number" step="1" min="0" max="' + L.paper.w + '" value="' + (t.w || 0) + '" onchange="chText(\'w\',this.value)" autocomplete="off"></div>';
        html += '<div class="pitem"><label>对齐</label><select onchange="chText(\'center\',this.value)">'
             + '<option value="0"' + (!t.center ? ' selected' : '') + '>左对齐</option>'
             + '<option value="1"' + (t.center ? ' selected' : '') + '>居中（需设宽度）</option></select></div>';
        html += '<div class="pitem"><label>X mm</label><input type="number" step="0.5" id="p_x" value="' + t.x + '" onchange="chXY(\'x\',this.value)" autocomplete="off"></div>';
        html += '<div class="pitem"><label>Y mm</label><input type="number" step="0.5" id="p_y" value="' + t.y + '" onchange="chXY(\'y\',this.value)" autocomplete="off"></div>';
        html += '</div>';
        html += '<div style="margin-top:10px;"><button type="button" class="btn btn-sm btn-danger" onclick="delText()">🗑 删除该文本</button></div>';
        box.innerHTML = html; return;
    }
    if (sel.type === 'id') {
        var id = L.id;
        html += '<div class="subttl">🔢 身份涂号区（用于识别是谁的答题卡）</div><div class="pgrid">';
        html += '<div class="pitem"><label>身份类型</label><select id="p_idmode" onchange="chId(\'idmode\',this.value)">'
             + '<option value="seat"' + (id.idmode === 'seat' ? ' selected' : '') + '>座号</option>'
             + '<option value="no"' + (id.idmode === 'no' ? ' selected' : '') + '>编号</option></select></div>';
        html += '<div class="pitem"><label>涂号位数（1-10）<button type="button" class="hint-q" onclick="toggleHint(event, \'涂号位数说明<br>· 身份号码的位数：如座号 1-48 用 2 位、学号 20240001 用 8 位<br>· 学生须按自己的号码逐位涂黑对应数字框<br>· 位数越多印的框越多，占用纸面越大\')">?</button></label><input type="number" id="p_digits" min="1" max="10" value="' + id.digits + '" onchange="chId(\'digits\',this.value)" autocomplete="off"></div>';
        html += '<div class="pitem"><label>排列方向<button type="button" class="hint-q" onclick="toggleHint(event, \'身份涂号区排列方向<br>· <b>纵向</b>=0-9 数字竖排、每位一列<br>· <b>横向</b>=0-9 数字横排、每位一行<br>· 新建答题卡默认横向；已印旧卡请勿改方向，否则与印刷版式对不上\')">?</button></label><select onchange="chId(\'orient\',this.value)">'
             + '<option value="h"' + (id.orient !== 'v' ? ' selected' : '') + '>纵向（0-9 竖排、每位一列）</option>'
             + '<option value="v"' + (id.orient === 'v' ? ' selected' : '') + '>横向（0-9 横排、每位一行）</option></select></div>';
        html += '<div class="pitem"><label>方框宽 mm（3-7）</label><input type="number" step="0.2" min="3" max="7" value="' + id.bw + '" onchange="chId(\'bw\',this.value)" autocomplete="off"></div>';
        html += '<div class="pitem"><label>方框高 mm（3-7）</label><input type="number" step="0.2" min="3" max="7" value="' + id.bh + '" onchange="chId(\'bh\',this.value)" autocomplete="off"></div>';
        html += '<div class="pitem"><label>行距 mm（0.8-4）</label><input type="number" step="0.2" min="0.8" max="4" value="' + id.vgap + '" onchange="chId(\'vgap\',this.value)" autocomplete="off"></div>';
        html += '<div class="pitem"><label>X mm</label><input type="number" step="0.5" id="p_x" value="' + id.x + '" onchange="chXY(\'x\',this.value)" autocomplete="off"></div>';
        html += '<div class="pitem"><label>Y mm</label><input type="number" step="0.5" id="p_y" value="' + id.y + '" onchange="chXY(\'y\',this.value)" autocomplete="off"></div>';
        html += '</div>';
    } else {
        var s = L.sections[sel.idx];
        if (!s) { sel = null; box.innerHTML = html; return; }
        var kindTag = s.kind === 'multi' ? '（多选）' : (s.kind === 'judge' ? '（判断）' : (s.kind === 'blank' ? '（填空·手写不判分）' : (s.kind === 'short' ? '（简答·手写不判分）' : '')));
        html += '<div class="subttl">📝 题组：' + esc(s.title) + kindTag + '</div><div class="pgrid">';
        html += '<div class="pitem" style="grid-column:1/3;"><label>题组标题</label><input id="p_stitle" value="' + esc(s.title) + '" onchange="chSec(\'title\',this.value)" autocomplete="off"></div>';
        html += '<div class="pitem"><label>所属页</label><select onchange="chSec(\'pg\',this.value)">';
        for (var pp = 0; pp < L.pages; pp++) html += '<option value="' + pp + '"' + ((s.pg || 0) === pp ? ' selected' : '') + '>第 ' + (pp + 1) + ' 页</option>';
        html += '</select></div>';
        html += '<div class="pitem"><label>起始题号</label><input type="number" min="1" value="' + s.start + '" onchange="chSec(\'start\',this.value)" autocomplete="off"></div>';
        if (s.kind === 'blank') {
            html += '<div class="pitem"><label>题目数量（1-100）</label><input type="number" min="1" max="100" value="' + s.count + '" onchange="chSec(\'count\',this.value)" autocomplete="off"></div>';
            html += '<div class="pitem"><label>每行题数（1-10）</label><input type="number" min="1" max="10" value="' + s.perRow + '" onchange="chSec(\'perRow\',this.value)" autocomplete="off"></div>';
            html += '<div class="pitem" style="grid-column:1/3;"><label>横线长度 mm（10-120）</label><input type="number" step="1" min="10" max="120" value="' + s.lineLen + '" onchange="chSec(\'lineLen\',this.value)" autocomplete="off"></div>';
        } else if (s.kind === 'short') {
            html += '<div class="pitem"><label>题目数量</label><input value="1 题（插入一次一题）" disabled autocomplete="off"></div>';
            html += '<div class="pitem" style="grid-column:1/3;"><label>题目文字（可空，≤120 字，设宽后自动换行）</label><input value="' + esc(s.qtext || '') + '" onchange="chSec(\'qtext\',this.value)" autocomplete="off"></div>';
            html += '<div class="pitem"><label>框宽 mm（0=自动）</label><input type="number" step="1" min="0" max="' + L.paper.w + '" value="' + (s.boxW || 0) + '" onchange="chSec(\'boxW\',this.value)" autocomplete="off"></div>';
            html += '<div class="pitem"><label>框高 mm（8-150）</label><input type="number" step="1" min="8" max="150" value="' + s.boxH + '" onchange="chSec(\'boxH\',this.value)" autocomplete="off"></div>';
        } else {
            html += '<div class="pitem"><label>题目数量（1-100）</label><input type="number" min="1" max="100" value="' + s.count + '" onchange="chSec(\'count\',this.value)" autocomplete="off"></div>';
            html += '<div class="pitem"><label>每行题数（1-10）</label><input type="number" min="1" max="10" value="' + s.perRow + '" onchange="chSec(\'perRow\',this.value)" autocomplete="off"></div>';
            if (s.kind === 'judge') {
                html += '<div class="pitem"><label>选项数量</label><input value="对 / 错（固定 2 项）" disabled autocomplete="off"></div>';
            } else {
                html += '<div class="pitem"><label>选项数量（2-5）</label><select onchange="chSec(\'opts\',this.value)">';
                for (var n = 2; n <= 5; n++) html += '<option value="' + n + '"' + (s.opts === n ? ' selected' : '') + '>' + n + ' 项（' + LETTERS.slice(0, n).join('/') + '）</option>';
                html += '</select></div>';
            }
            html += '<div class="pitem"><label>方框宽 mm（3-7）<button type="button" class="hint-q" onclick="toggleHint(event, \'涂框大小与判定说明<br>· 学生须用 2B 铅笔或黑色签字笔把方框<b>涂满涂黑</b>，识别端按涂框内涂黑率判定是否选中<br>· 框越大越好涂、识别越稳，但更占纸面（建议 4-5mm）<br>· 修改答案请擦干净，浅色或半涂可能识别不出\')">?</button></label><input type="number" step="0.2" min="3" max="7" value="' + s.bw + '" onchange="chSec(\'bw\',this.value)" autocomplete="off"></div>';
            html += '<div class="pitem"><label>方框高 mm（3-7）</label><input type="number" step="0.2" min="3" max="7" value="' + s.bh + '" onchange="chSec(\'bh\',this.value)" autocomplete="off"></div>';
            html += '<div class="pitem"><label>选项横距 mm（0.8-5）</label><input type="number" step="0.2" min="0.8" max="5" value="' + s.ogap + '" onchange="chSec(\'ogap\',this.value)" autocomplete="off"></div>';
            html += '<div class="pitem"><label>题目行距 mm（0.8-8）</label><input type="number" step="0.2" min="0.8" max="8" value="' + s.rgap + '" onchange="chSec(\'rgap\',this.value)" autocomplete="off"></div>';
        }
        html += '<div class="pitem"><label>X mm</label><input type="number" step="0.5" id="p_x" value="' + s.x + '" onchange="chXY(\'x\',this.value)" autocomplete="off"></div>';
        html += '<div class="pitem"><label>Y mm</label><input type="number" step="0.5" id="p_y" value="' + s.y + '" onchange="chXY(\'y\',this.value)" autocomplete="off"></div>';
        html += '</div>';
        // 答案键编辑（仅选择类题组；判断题显示 对/错，内部存储 A/B）——默认折叠
        if (isChoiceKind(s.kind)) {
            var kindHint = s.kind === 'multi' ? '多选可点多个' : (s.kind === 'judge' ? '判断点一个' : '单选点一个');
            html += '<div class="subttl" style="cursor:pointer;user-select:none;" onclick="toggleBox(\'key\')">🔑 答案键（可选，保存后自动批改；' + kindHint + '）<span style="float:right;font-weight:normal;">' + (uiKeyOpen ? '▾' : '▸') + '</span></div>';
            html += '<div id="keybox" style="' + (uiKeyOpen ? '' : 'display:none;') + 'max-height:220px;overflow:auto;border:1px solid #e3e6f2;border-radius:8px;padding:6px 8px;">';
            for (var q = 0; q < s.count; q++) {
                var qno = String(s.start + q), cur = String(s.key[qno] || '');
                html += '<div class="key-row"><span class="kq">第' + qno + '题</span>';
                for (var o = 0; o < s.opts; o++) {
                    var lt = LETTERS[o];
                    html += '<button type="button" class="key-btn' + (cur.indexOf(lt) >= 0 ? ' on' : '') + '" onclick="toggleKey(' + q + ',\'' + lt + '\')">' + optLabel(s, o) + '</button>';
                }
                html += '</div>';
            }
            html += '</div>';
            // 分值设置（默认折叠）：批量一键设全组分值或逐题设置；未设分值批改显示正确率，设了分值显示得分 nn/总分
            var ptsSet = 0, ptsSum = 0;
            for (var q2 = 0; q2 < s.count; q2++) {
                var pv2 = parseFloat(s.points[String(s.start + q2)]);
                if (isFinite(pv2) && pv2 > 0) { ptsSet++; ptsSum += pv2; }
            }
            html += '<div class="subttl" style="cursor:pointer;user-select:none;margin-top:10px;" onclick="toggleBox(\'pts\')">💰 分值设置（可选' + (ptsSet ? '，已设 ' + ptsSet + ' 题' : '') + '）<span style="float:right;font-weight:normal;">' + (uiPtsOpen ? '▾' : '▸') + '</span></div>';
            html += '<div id="ptsbox" style="' + (uiPtsOpen ? '' : 'display:none;') + 'border:1px solid #e3e6f2;border-radius:8px;padding:6px 8px;">';
            html += '<div class="key-row"><span class="kq">批量</span><input type="number" step="0.5" min="0" max="100" placeholder="分值" onchange="chPtsAll(this.value)" autocomplete="off">'
                 + '<span style="font-size:11px;color:#999;margin-left:6px;">输入分值回车/移开即应用到本组全部 ' + s.count + ' 题</span></div>';
            html += '<div style="max-height:220px;overflow:auto;">';
            for (var q3 = 0; q3 < s.count; q3++) {
                var qno3 = String(s.start + q3), pv3 = parseFloat(s.points[qno3]);
                html += '<div class="key-row"><span class="kq">第' + qno3 + '题</span><input type="number" step="0.5" min="0" max="100" style="width:80px;" value="' + (isFinite(pv3) && pv3 > 0 ? pv3 : '') + '" placeholder="未设" onchange="chPts(' + sel.idx + ',\'' + qno3 + '\',this.value)" autocomplete="off"></div>';
            }
            html += '</div>';
            html += '<p class="tip" style="margin:6px 0 0;">计分口径<button type="button" class="hint-q" onclick="toggleHint(event, \'' + (ptsSet
                ? '<b>得分制</b><br>· 本组已设分值，总分 ' + ptsFmt(ptsSum) + '<br>· 任一题组设分值后全卷按得分制显示「得分 nn/总分 xx」（未设分值的题计 0 分）'
                : '<b>正确率制</b><br>· 未设分值：批改显示正确率（对题数/题数）<br>· 任一题设分值后改显得分制「nn/xx」') + '\')">?</button></p>';
            html += '</div>';
        } else {
            html += '<p class="tip" style="margin-top:10px;">✍ 手写题<button type="button" class="hint-q" onclick="toggleHint(event, \'<b>手写题</b><br>· 学生在此区域手写作答，识别端不会自动判分（得分仅统计选择类题组）<br>· 教师可在识别预览图中人工核对\')">?</button></p>';
        }
        html += '<div style="margin-top:10px;"><button type="button" class="btn btn-sm btn-danger" onclick="delSection()">🗑 删除本题组</button></div>';
    }
    box.innerHTML = html;
}
function syncXYInputs() {
    if (!sel) return;
    var it = (sel.type === 'id') ? L.id : (sel.type === 'text') ? L.texts[sel.idx]
           : (sel.type === 'title') ? L.titlePos : (sel.type === 'pgnum') ? L.pgnum
           : L.sections[sel.idx];
    if (!it) return;
    var x = document.getElementById('p_x'), y = document.getElementById('p_y');
    if (it && x && y && document.activeElement !== x && document.activeElement !== y) { x.value = it.x; y.value = it.y; }
}
function num(v, def, min, max) {
    v = parseFloat(v); if (!isFinite(v)) v = def;
    return Math.min(max, Math.max(min, v));
}
function chPaper(v) {
    if (v === 'l') { L.paper = { w: 297, h: 210 }; } else { L.paper = { w: 210, h: 297 }; }
    L.sections.forEach(function (s) { s.x = Math.min(s.x, L.paper.w - 30); s.y = Math.min(s.y, L.paper.h - 20); });
    L.id.x = Math.min(L.id.x, L.paper.w - 30); L.id.y = Math.min(L.id.y, L.paper.h - 20);
    L.texts.forEach(function (t) { t.x = Math.min(t.x, L.paper.w - 2); t.y = Math.min(t.y, L.paper.h - 6); if (t.w > L.paper.w) t.w = L.paper.w; });
    render();
}
function chTitle(v) {
    L.title = v;
    var t = document.getElementById('sheetTitle');
    if (t) t.textContent = v || '答题卡';
}
function chTitleY(v) {
    L.titlePos.y = num(v, 5, 2, L.paper.h - 12);
    render();
}
function chHmarkCluster(v) {
    L.hmarkCluster = (v === '1');
    render(); renderProps();
}
function chId(k, v) {
    if (k === 'idmode') L.id.idmode = (v === 'no') ? 'no' : 'seat';
    else if (k === 'orient') L.id.orient = (v === 'v') ? 'v' : 'h';
    else if (k === 'digits') L.id.digits = Math.round(num(v, 2, 1, 10));
    else if (k === 'vgap') L.id.vgap = num(v, 1.4, 0.8, 4);
    else L.id[k] = num(v, L.id[k], 3, 7);
    render(); renderProps();
}
function chSec(k, v) {
    var s = L.sections[sel.idx]; if (!s) return;
    if (k === 'title') s.title = String(v).slice(0, 30) || '题组';
    else if (k === 'start') s.start = Math.round(num(v, 1, 1, 500));
    else if (k === 'count') s.count = Math.round(num(v, 10, 1, 100));
    else if (k === 'perRow') s.perRow = Math.round(num(v, 5, 1, 10));
    else if (k === 'opts') s.opts = Math.round(num(v, 4, 2, 5));
    else if (k === 'ogap') s.ogap = num(v, 2, 0.8, 5);
    else if (k === 'rgap') s.rgap = num(v, 2.6, 0.8, 8);
    else s[k] = num(v, s[k], 3, 7);
    render(); renderProps();
}
function chXY(k, v) {
    if (!sel) return;
    var it = (sel.type === 'id') ? L.id : (sel.type === 'text') ? L.texts[sel.idx]
           : (sel.type === 'title') ? L.titlePos : (sel.type === 'pgnum') ? L.pgnum
           : L.sections[sel.idx];
    if (!it) return;
    it[k] = num(v, it[k], 0, (k === 'x' ? L.paper.w : L.paper.h) - 10);
    render();
}
function toggleKey(q, lt) {
    var s = L.sections[sel.idx]; if (!s) return;
    var qno = String(s.start + q), cur = String(s.key[qno] || '');
    if (s.kind === 'multi') {
        var arr = cur.split('');
        var p = arr.indexOf(lt);
        if (p >= 0) arr.splice(p, 1); else { arr.push(lt); arr.sort(); }
        if (arr.length) s.key[qno] = arr.join(''); else delete s.key[qno];
    } else {
        if (cur === lt) delete s.key[qno]; else s.key[qno] = lt;
    }
    render(); renderProps();   // 同步画布涂框绿色高亮与答案键面板
}
// 涂框点选设答案：sIdx=题组序号、qno=绝对题号（bubble dataset）、lt=选项字母（判断题为 A/B）
function toggleKeyAbs(sIdx, qno, lt) {
    var s = L.sections[sIdx]; if (!s) return;
    var cur = String(s.key[qno] || '');
    if (s.kind === 'multi') {
        var arr = cur.split('');
        var p = arr.indexOf(lt);
        if (p >= 0) arr.splice(p, 1); else { arr.push(lt); arr.sort(); }
        if (arr.length) s.key[qno] = arr.join(''); else delete s.key[qno];
    } else {
        if (cur === lt) delete s.key[qno]; else s.key[qno] = lt;
    }
    render(); renderProps();   // 刷新涂框绿色高亮与答案键面板
}
function chPgnum(k, v) {
    var pn = L.pgnum || (L.pgnum = { on: true, x: 0, y: 12, size: 3.2, fmt: '' });
    if (k === 'on') pn.on = (v === '1');
    else if (k === 'fmt') pn.fmt = String(v).slice(0, 60);
    else if (k === 'size') pn.size = Math.min(8, Math.max(2, parseFloat(v) || 3.2));
    render(); renderProps();
}
// 属性面板折叠（答案键/分值设置默认收起，状态跨重渲染保持）
function toggleBox(which) {
    if (which === 'key') uiKeyOpen = !uiKeyOpen; else uiPtsOpen = !uiPtsOpen;
    renderProps();
}
function ptsFmt(v) { var s = Math.round(v * 10) / 10; return (s % 1 === 0) ? String(s) : s.toFixed(1); }
// 每题分值：留空=未设（该题计 0 分；全卷未设分值时批改显示正确率）
function chPts(sIdx, qno, v) {
    var s = L.sections[sIdx]; if (!s) return;
    var pv = parseFloat(v);
    if (isFinite(pv) && pv > 0) s.points[qno] = Math.min(100, Math.round(pv * 10) / 10); else delete s.points[qno];
    renderProps();
}
function chPtsAll(v) {
    var s = (sel && sel.type === 'sec') ? L.sections[sel.idx] : null; if (!s) return;
    var pv = parseFloat(v);
    if (!(isFinite(pv) && pv > 0)) { toast('请输入大于 0 的分值'); return; }
    pv = Math.min(100, Math.round(pv * 10) / 10);
    for (var q = 0; q < s.count; q++) s.points[String(s.start + q)] = pv;
    renderProps();
}
function addSection(kind) {
    var maxY = 24;
    var g0 = idGeom(); maxY = g0.y + g0.h;
    L.sections.forEach(function (s) { var g = secGeom(s, 0); maxY = Math.max(maxY, s.y + g.h); });
    var maxEnd = 0;
    L.sections.forEach(function (s) { maxEnd = Math.max(maxEnd, s.start + s.count - 1); });
    var s = mkSection(kind, 14, maxY + 7);
    s.start = maxEnd + 1;
    s.title = kind === 'single' ? '单选题组' : '多选题组';
    L.sections.push(s);
    select('sec', L.sections.length - 1);
}
function delSection() {
    if (sel.type !== 'sec') return;
    if (!confirm('确定删除该题组？')) return;
    L.sections.splice(sel.idx, 1);
    sel = null; renderProps(); render();
}
function autoArrange() {
    L.id.x = 14; L.id.y = 24;
    var g = idGeom(), y = g.y + g.h + 7;
    L.sections.forEach(function (s) { s.x = 14; s.y = y; y += secGeom(s, 0).h + 7; });
    render(); renderProps();
}

// ===== API：模板列表 / 保存 / 载入 / 删除 =====
function toast(msg) {
    var t = document.getElementById('toast');
    t.textContent = msg; t.style.display = 'block';
    clearTimeout(t._h); t._h = setTimeout(function () { t.style.display = 'none'; }, 2200);
}
function apiPost(params, cb) {
    var fd = new FormData();
    Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
    fetch('api.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) { cb(d || { success: false, message: '响应为空' }); })
        .catch(function () { cb({ success: false, message: '网络错误，请重试' }); });
}
function tplName() { return document.getElementById('tplName').value.trim() || '默认模板'; }
function renderTplSelect() {
    var sel2 = document.getElementById('tplSelect');
    sel2.innerHTML = '<option value="">— 选择已有模板 —</option>' + TPL_LIST.map(function (t) {
        return '<option value="' + t.id + '">' + esc(t.name) + '（' + t.updated_at + '）</option>';
    }).join('');
    if (TID > 0) sel2.value = String(TID);
}
function onTplSelect() {
    var v = parseInt(document.getElementById('tplSelect').value, 10) || 0;
    if (v <= 0) return;
    apiPost({ type: 'omr_template_get', project_id: PROJECT_ID, id: v }, function (d) {
        if (!d.success) { toast(d.message || '载入失败', 'error'); return; }
        TID = d.id; LIB_TID = 0; L = normalizeLayout(d.layout);
        L.hmarkCluster = true;   // 旧模板载入即升级聚簇版式（页码点聚左上、手性可判镜像）；下次保存固化到模板
        L.edgeMidMarks = true;   // 同步升级边缘中点码点（左/右/下边中点锚，预览分段拉直 + 角标冗余）；已印旧卡识别端自动回退直线边不受影响
        document.getElementById('tplName').value = d.name || '';
        sel = null; render(); renderProps();
        bindTpl(TID);   // 下拉选定即绑定：识别页固定使用该模板
        toast('已载入并绑定模板：' + d.name + '（页码点聚簇 + 边缘中点码点已升级）');
    });
}
// 绑定/解绑模板（识别端 omr_scan.php 只用绑定模板，避免扫错模板）
function bindTpl(tid) {
    apiPost({ type: 'omr_tpl_bind_set', project_id: PROJECT_ID, template_id: tid }, function () {});
}
// 页面加载：自动恢复上次绑定（选定）的模板并载入画布
function bindRestore() {
    apiPost({ type: 'omr_tpl_bind_get', project_id: PROJECT_ID }, function (d) {
        var tid = (d.success && d.template_id > 0) ? parseInt(d.template_id, 10) : 0;
        if (tid <= 0 || !TPL_LIST.some(function (t) { return t.id === tid; })) return;   // 未绑定或模板已删除
        var sel2 = document.getElementById('tplSelect');
        sel2.value = String(tid);
        onTplSelect();
    });
}
function newTpl() {
    if (!confirm('新建模板将清空当前画布（未保存的修改会丢失），继续？')) return;
    TID = 0; LIB_TID = 0; L = defaultLayout(); sel = null;
    document.getElementById('tplName').value = '';
    renderTplSelect();   // 清空 tplSelect 选中项（体现「新建」状态，避免误以为仍在编辑所选模板）
    render(); renderProps();
}
function delTpl() {
    if (TID <= 0) { toast('当前尚未选择已保存的模板'); return; }
    if (!confirm('确定删除模板「' + tplName() + '」？已保存的识别结果不受影响。')) return;
    var pwd = prompt('删除模板为敏感操作（删除后已打印的答题卡将无法继续按此模板识别），请输入您的登录密码确认：');
    if (pwd === null) return;
    if (!pwd) { toast('请输入登录密码'); return; }
    apiPost({ type: 'omr_template_del', project_id: PROJECT_ID, id: TID, pwd: pwd }, function (d) {
        if (!d.success) { toast(d.message); return; }
        var delId = TID;
        TID = 0; loadList(); toast('模板已删除');
        apiPost({ type: 'omr_tpl_bind_get', project_id: PROJECT_ID }, function (b) {
            if (b.success && parseInt(b.template_id, 10) === delId) bindTpl(0);   // 删除的是绑定模板 → 解绑
        });
    });
}
function tplAutoName() { return PJ_NAME + '自动模板'; }   // 未填名称时的自动命名：项目名称+自动模板
function doSaveTpl(name, silent, done) {   // 保存模板核心；silent=自动保存（校验不过静默跳过、无覆盖确认），done 保存完成后必达
    var errs = validateLayout();
    if (errs.length) {
        if (silent) { if (done) done(); return; }
        toast('无法保存，请先修正：\n· ' + errs.slice(0, 6).join('\n· ')); return;
    }
    // 覆盖已保存模板需二次确认（仅手动保存）：改布局会使已按旧模板打印的答题卡识别坐标失效（已识别的结果不受影响）
    if (!silent && TID > 0 && !confirm('保存模板会影响已打印的答题卡识别，已识别的结果不受影响，确认继续保存？')) return;
    L.bubbles = computeBubbles();   // 预计算涂框毫米坐标，识别端直接采样
    apiPost({ type: 'omr_template_save', project_id: PROJECT_ID, id: TID, name: name, layout: JSON.stringify(L) }, function (d) {
        if (!d.success) { toast(d.message); if (done) done(); return; }
        TID = d.id;
        document.getElementById('tplName').value = name;   // 自动命名时回填，让教师看到实际保存名
        loadList();               // 重新拉取列表（新模板入库/旧模板改名与时间刷新）→ renderTplSelect 按 TID 自动选中
        bindTpl(TID);             // 保存即绑定：识别页固定使用刚保存的模板
        toast(silent ? '已自动保存并绑定到项目' : ('模板已保存并绑定（' + name + '）'));
        if (done) done();
    });
}
function saveTpl() {
    var name = document.getElementById('tplName').value.trim();
    if (!name) { name = tplAutoName(); }   // 不再强制输入名称：留空自动命名
    doSaveTpl(name, false);
}
function autoSaveTpl(done) {   // 导出PDF / 去扫描识别 / 录入答案 前自动保存（防止模板改完忘记保存丢失）
    doSaveTpl(document.getElementById('tplName').value.trim() || tplAutoName(), true, done);
}
function goScanSave() {   // 「去扫描识别」：先等自动保存完成再跳转（防导航中断保存请求）；校验不过也照常跳转不阻断
    autoSaveTpl(function () { location.href = 'omr_scan.php?project_id=' + PROJECT_ID; });
    return false;
}
function loadList() {
    apiPost({ type: 'omr_template_list', project_id: PROJECT_ID }, function (d) {
        if (d.success) { TPL_LIST = d.items || []; renderTplSelect(); }
    });
}

// ===== 录入答案（独立答案键：与模板解耦，识别端优先使用；完整覆盖才自动批改） =====
var ANS = { source: 'template', answers: {} };
function ansOpen() {
    if (!L.sections.length) { toast('请先添加题组，再录入答案'); return; }
    autoSaveTpl();   // 打开答案录入前自动保存模板（防忘记保存）
    apiPost({ type: 'omr_answer_get', project_id: PROJECT_ID }, function (d) {
        ANS.source = (d.success && d.source === 'project') ? 'project' : 'template';
        var pre = {};
        if (ANS.source === 'project') {
            pre = d.answers || {};
        } else {
            // 无独立答案：预填当前画布模板的答案键，教师在此基础上微调后另存为独立答案
            L.sections.forEach(function (s) {
                Object.keys(s.key || {}).forEach(function (q) { if (s.key[q]) pre[q] = s.key[q]; });
            });
        }
        ANS.answers = pre;
        ansRender();
        document.getElementById('ansModal').style.display = 'flex';
    });
}
function ansClose() { document.getElementById('ansModal').style.display = 'none'; }
function ansCount() {
    var n = 0, total = 0;
    L.sections.forEach(function (s) {
        for (var q = 0; q < s.count; q++) { total++; if (ANS.answers[String(s.start + q)]) n++; }
    });
    return [n, total];
}
function ansRender() {
    var box = document.getElementById('ansBody'), html = '';
    L.sections.forEach(function (s, si) {
        html += '<div class="ans-sec">' + esc(s.title) + (s.kind === 'multi' ? '（多选）' : '') + '</div>';
        for (var q = 0; q < s.count; q++) {
            var qno = String(s.start + q), cur = String(ANS.answers[qno] || '');
            html += '<div class="key-row"><span class="kq">第' + qno + '题</span>';
            for (var o = 0; o < s.opts; o++) {
                var lt = LETTERS[o];
                html += '<button type="button" class="key-btn' + (cur.indexOf(lt) >= 0 ? ' on' : '') + '" onclick="ansToggle(' + si + ',' + q + ',\'' + lt + '\')">' + optLabel(s, o) + '</button>';
            }
            html += '</div>';
        }
    });
    box.innerHTML = html;
    var c = ansCount();
    document.getElementById('ansHint').innerHTML = (ANS.source === 'project'
        ? '使用<b>独立答案</b><button type="button" class="hint-q" onclick="toggleHint(event, \'<b>独立录入的答案</b><br>· 优先于模板答案键<br>· 改答案不影响模板与已打印的答题卡\')">?</button>'
        : '使用<b>模板答案键</b><button type="button" class="hint-q" onclick="toggleHint(event, \'<b>模板自带答案键</b><br>· 可在下方逐题另设独立答案<br>· 保存后独立答案优先于模板\')">?</button>')
        + '　已设置 <b>' + c[0] + '</b> / ' + c[1] + ' 题';
    document.getElementById('ansClearBtn').style.display = ANS.source === 'project' ? '' : 'none';
}
function ansToggle(si, q, lt) {
    var s = L.sections[si]; if (!s) return;
    var qno = String(s.start + q), cur = String(ANS.answers[qno] || '');
    if (s.kind === 'multi') {
        var arr = cur.split(''), p = arr.indexOf(lt);
        if (p >= 0) arr.splice(p, 1); else { arr.push(lt); arr.sort(); }
        if (arr.length) ANS.answers[qno] = arr.join(''); else delete ANS.answers[qno];
    } else {
        if (cur === lt) delete ANS.answers[qno]; else ANS.answers[qno] = lt;
    }
    ansRender();
}
function ansSave() {
    var c = ansCount();
    if (!c[0] && !confirm('尚未设置任何答案，保存将清除独立答案（改用模板答案键），继续？')) return;
    apiPost({ type: 'omr_answer_save', project_id: PROJECT_ID, answers: JSON.stringify(ANS.answers) }, function (d) {
        if (!d.success) { toast(d.message); return; }
        ANS.source = c[0] ? 'project' : 'template';
        ansRender();
        toast(d.message);
    });
}
function ansClear() {
    if (!confirm('确定清除独立答案？清除后改用模板自带的答案键（识别结果将按模板答案键重算）。')) return;
    apiPost({ type: 'omr_answer_clear', project_id: PROJECT_ID }, function (d) {
        if (!d.success) { toast(d.message); return; }
        ANS.source = 'template'; ANS.answers = {};
        ansRender(); toast(d.message);
    });
}

<?php if ($omr_lib_on): ?>
// ===== 学校共享模板库（feat_omr_lib；建立/加载/管理按身份，服务端二次校验） =====
var LIB_TID = 0, LIB_LIST = [];   // LIB_TID：当前画布绑定的库模板（载入后「存入模板库」= 覆盖更新）
function libOpen(focusSave) {
    document.getElementById('libModal').style.display = 'flex';
    libReload();
    if (focusSave) setTimeout(function () { document.getElementById('libName').focus(); }, 60);
}
function libSaveOpen() { libOpen(true); }
function libClose() { document.getElementById('libModal').style.display = 'none'; }
function libFind(id) { var it = null; LIB_LIST.forEach(function (t) { if (t.id === id) it = t; }); return it; }
function libReload() {
    apiPost({ type: 'omr_lib_list' }, function (d) {
        var box = document.getElementById('libList');
        if (!d.success) { box.innerHTML = '<div class="tip" style="text-align:center;padding:14px 0;">' + esc(d.message || '加载失败') + '</div>'; return; }
        LIB_LIST = d.items || [];
        box.innerHTML = LIB_LIST.length ? LIB_LIST.map(function (t) {
            var acts = '<button type="button" class="btn btn-sm btn-outline" onclick="libLoad(' + t.id + ')">载入</button>';
            if (t.can_edit) acts += ' <button type="button" class="btn btn-sm btn-outline" onclick="libRename(' + t.id + ')">✎ 改名</button>'
                + ' <button type="button" class="btn btn-sm btn-outline" style="color:#e74c3c;" onclick="libDel(' + t.id + ')">🗑 删除</button>';
            return '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:7px 2px;border-bottom:1px dashed #e3e6f2;font-size:13px;">'
                + '<div style="min-width:0;"><b>' + esc(t.name) + '</b> <span style="background:#eef1fb;color:#667eea;border-radius:5px;padding:1px 7px;font-size:12px;white-space:nowrap;">' + esc(t.scope_label) + '</span>'
                + '<span style="color:#999;font-size:12px;margin-left:6px;">' + esc(t.creator || '') + ' · ' + esc(t.updated_at) + '</span></div>'
                + '<div style="flex-shrink:0;">' + acts + '</div></div>';
        }).join('') : '<div class="tip" style="text-align:center;padding:14px 0;">模板库暂无您可用的模板（可把当前布局「存入模板库」共享）</div>';
        var opts = d.options || [], sv = document.getElementById('libScope'), cur = sv.value;
        sv.innerHTML = opts.length ? opts.map(function (o) { return '<option value="' + esc(o.value) + '">' + esc(o.label) + '</option>'; }).join('')
            : '<option value="">（您暂无可建立的范围）</option>';
        if (cur && opts.some(function (o) { return o.value === cur; })) sv.value = cur;
    });
}
function libLoad(id) {
    if (!confirm('载入将替换当前画布内容（未保存的修改会丢失），继续？')) return;
    apiPost({ type: 'omr_lib_get', id: id }, function (d) {
        if (!d.success) { toast(d.message || '载入失败', 'error'); return; }
        LIB_TID = d.id; TID = 0; L = normalizeLayout(d.layout);
        L.hmarkCluster = true;   // 旧模板载入即升级聚簇版式（下次保存固化）
        L.edgeMidMarks = true;   // 同步升级边缘中点码点（下次保存固化）
        document.getElementById('tplName').value = d.name || '';
        document.getElementById('libName').value = d.name || '';
        var it = libFind(id);
        if (it) document.getElementById('libScope').value = it.scope_option;
        sel = null; render(); renderProps();
        libClose();
        toast('已从模板库载入：' + (d.name || ''));
    });
}
function libRename(id) {
    var it = libFind(id);
    var nn = prompt('新的模板名称：', it ? it.name : '');
    if (nn === null) return;
    nn = nn.trim();
    if (!nn) { toast('名称不能为空'); return; }
    apiPost({ type: 'omr_lib_save', id: id, name: nn, scope_option: it ? it.scope_option : '' }, function (d) {
        if (!d.success) { toast(d.message); return; }
        if (LIB_TID === id) document.getElementById('tplName').value = nn;
        libReload(); toast('已改名：' + nn);
    });
}
function libDel(id) {
    var it = libFind(id);
    if (!confirm('确定从模板库删除「' + (it ? it.name : '') + '」？')) return;
    var pwd = prompt('删除模板库模板为敏感操作，请输入您的登录密码确认：');
    if (pwd === null) return;
    if (!pwd) { toast('请输入登录密码'); return; }
    apiPost({ type: 'omr_lib_del', id: id, pwd: pwd }, function (d) {
        if (!d.success) { toast(d.message); return; }
        if (LIB_TID === id) LIB_TID = 0;
        libReload(); toast('已从模板库删除');
    });
}
function libSave() {
    var errs = validateLayout();
    if (errs.length) { toast('无法保存，请先修正：\n· ' + errs.slice(0, 6).join('\n· ')); return; }
    var name = document.getElementById('libName').value.trim();
    if (!name) { toast('请先输入模板名称（存入模板库必须填写名称）'); document.getElementById('libName').focus(); return; }
    var scopeOpt = document.getElementById('libScope').value;
    if (!scopeOpt) { toast('暂无可建立模板的范围'); return; }
    if (LIB_TID > 0 && !confirm('将覆盖更新模板库中当前载入的模板，继续？')) return;
    L.bubbles = computeBubbles();   // 预计算涂框毫米坐标（与项目模板同构）
    apiPost({ type: 'omr_lib_save', id: LIB_TID, name: name, scope_option: scopeOpt, layout: JSON.stringify(L) }, function (d) {
        if (!d.success) { toast(d.message); return; }
        LIB_TID = d.id;
        libReload(); toast('已存入模板库（' + (name || '共享模板') + '）');
    });
}
<?php endif; ?>

// ===== PDF 导出（html2canvas 截图 + jsPDF，全部浏览器本地完成） =====
// 整页填充（L.dup≥2）：按取景框裁出内容块（含定位标记，块外留白 3mm），每页复制 N 份并画裁切虚线；
// 每份独立可识别——识别端按四标记单应映射回纸面坐标，裁块单独拍摄天然支持，无需改识别端
function exportPDF() {
    var errs = validateLayout();
    if (errs.length) { toast('无法导出，请先修正：\n· ' + errs.slice(0, 6).join('\n· ')); return; }
    if (typeof html2canvas === 'undefined' || !window.jspdf) { toast('导出组件未加载，请刷新页面重试'); return; }
    autoSaveTpl();   // 导出 PDF 前自动保存模板（防忘记保存；校验已在上方通过）
    var multi = L.pages > 1;   // 多页模板：每页单独一页 PDF（整页填充不适用）
    var sheet = document.getElementById('sheet');
    var fr = L.frame || contentFrame();
    var dup = multi ? 1 : (L.dup || 1), padM = 3;
    while (dup >= 2 && (fr.h + padM * 2) * dup > L.paper.h) dup--;   // 内容变大后自动降份
    if (!multi && L.dup >= 2 && dup < 2) { toast('内容已占页超过一半，整页填充不可用，请先关闭填充或缩减内容'); return; }
    var prevSel = sel, prevPg = CP; sel = null; render();          // 暂时去掉选中虚线，保证 PDF 干净
    sheet.classList.add('exporting');
    toast(multi ? ('正在生成 PDF（共 ' + L.pages + ' 页）…') : '正在生成 PDF…');
    var shots = [], pi = 0;
    function captureNext() {   // 多页：逐页切换渲染后截图（render 同步更新 DOM，可立即截图）
        if (!multi) {   // 单页：仅截当前画布一次
            return html2canvas(sheet, { scale: 3, backgroundColor: '#ffffff' }).then(function (c) { shots.push(c); });
        }
        if (pi >= L.pages) return Promise.resolve();   // 多页：全部页已截完，结束
        CP = pi; sel = null; render();
        pi++;
        return html2canvas(sheet, { scale: 3, backgroundColor: '#ffffff' }).then(function (c) { shots.push(c); return captureNext(); });
    }
    captureNext().then(function () {
        sheet.classList.remove('exporting');
        CP = prevPg; sel = prevSel; render();
        var pdf = new window.jspdf.jsPDF({ orientation: L.paper.w > L.paper.h ? 'landscape' : 'portrait', unit: 'mm', format: [L.paper.w, L.paper.h] });
        shots.forEach(function (c, i) {
            if (i > 0) pdf.addPage();
            if (dup === 1) {
                pdf.addImage(c, 'JPEG', 0, 0, L.paper.w, L.paper.h);
            } else {
                var pxmm = S * 3;   // 截图 scale=3，S=预览像素/毫米
                var cx = Math.max(0, Math.round((fr.x - padM) * pxmm)), cy = Math.max(0, Math.round((fr.y - padM) * pxmm));
                var cw = Math.min(c.width - cx, Math.round((fr.w + padM * 2) * pxmm)), ch = Math.min(c.height - cy, Math.round((fr.h + padM * 2) * pxmm));
                var blk = document.createElement('canvas');
                blk.width = cw; blk.height = ch;
                blk.getContext('2d').drawImage(c, cx, cy, cw, ch, 0, 0, cw, ch);
                var bimg = blk.toDataURL('image/jpeg', 0.92);
                var bw = fr.w + padM * 2, bh = fr.h + padM * 2, strip = L.paper.h / dup;
                for (var k = 0; k < dup; k++)
                    pdf.addImage(bimg, 'JPEG', (L.paper.w - bw) / 2, k * strip + (strip - bh) / 2, bw, bh);
                pdf.setDrawColor(160); pdf.setLineWidth(0.3); pdf.setLineDashPattern([2, 2], 0);
                for (var j = 1; j < dup; j++) pdf.line(3, j * strip, L.paper.w - 3, j * strip);   // 裁切虚线
                pdf.setLineDashPattern([], 0);
            }
        });
        pdf.save(tplName() + '.pdf');
        toast(dup > 1 ? ('PDF 已导出（每页 ' + dup + ' 份，沿虚线裁开即可）')
                     : (multi ? ('PDF 已导出（共 ' + L.pages + ' 页，每页页头点数不同）') : 'PDF 已导出'));
    }).catch(function (e) {
        console.error('exportPDF failed:', e);
        sheet.classList.remove('exporting');
        CP = prevPg; sel = prevSel; render();
        toast('导出失败，请重试' + (e && e.message ? ('：' + e.message) : ''));
    });
}

// ===== 初始化 =====
L = defaultLayout();
render();
renderProps();
loadList();
bindRestore();   // 自动恢复上次绑定（选定）的模板：下拉选中并载入画布
</script>
<?php
$help_flow = [
    '① 在画布上配置身份涂号区（座号/编号）与各题组，拖动调整位置',
    '② 点击右上角「导出 PDF」打印答题卡发给学生填涂',
    '③ 「保存模板」后到「去扫描识别」用摄像头或照片识别涂卡',
];
if ($omr_lib_on) $help_flow[] = '④ 「⬆ 存入模板库」可把布局按所选班级共享；「📚 模板库」可载入已共享的模板（仅创建者可改名/删除）';
page_help('答题卡设计', [
    ['h' => '基本流程', 'items' => $help_flow],
    ['h' => '设计要点', 'items' => [
        '身份涂号区可切换「排列方向」（0-9 竖排/横排），位数支持 1-10 位；每位自动画出浅色分组框（横向圈整行、纵向圈整列），并标注 0-9 数字值头（横向在顶部、纵向在左侧），防止学生填错方向或看错数字',
        '选项列头字母按「列」标注：每个题槽的 A/B/C/D 列头各标一次，各行题目按列对齐共享列头，省空间且不易误判',
        '「＋判断题组」：对/错 两项（内部按 A/B 识别与判分，答案键界面显示 对/错）',
        '「班级/姓名」行及「＋文本」添加的自定义文本均可编辑内容、改字号、拖动移位（最多 10 条）',
        '四角的黑色方块是定位标记（中心=浅虚线取景框角），识别时用于透视矫正，请勿遮挡或裁掉',
        '取景框随内容自动收紧：题量少时纸张可沿虚线外裁小使用，拍摄距离可更近、识别率更高',
        '「🧻 整页填充」仅作用于导出 PDF：内容占页 ≤1/2 复制 2 份、≤1/3 复制 3 份，每份自带定位标记，沿裁切虚线剪开即可单独识别',
        '题号范围不能重叠；涂框不能超出纸面或互相重叠（保存时会校验）',
        '「答案键」选填：配置后识别结果会自动判分并同步到项目评价值',
        '同一模板打印后应保持 100% 缩放（勿选「适合页面」），以保证识别坐标准确',
    ]],
    ['h' => '填涂要求', 'items' => [
        '学生须用 2B 铅笔或黑色签字笔把方框涂满涂黑，浅色标记无法识别',
        '修改答案务必擦干净，避免残留导致多选误判',
    ]],
]);
page_footer();
