<?php
/**
 * 座位 / 分组设置弹窗（students.php「设置分组」与大屏 project_view.php「进行分组 / 调整分组」共用）
 * 以 iframe 方式嵌入弹窗；保存后整页刷新父页面，座位与分组即时同步
 * 布局：上方讲台，下方「分组数 × 每组列数」网格；拖拽 / 点选调整；保存时按座位分组同步 stu_groups
 */
require_once 'includes/db_config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

$conn = getConnection();

$class_id = intval($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$stmt = mysqli_prepare($conn, "SELECT id, name, seat_cols, seat_groups, seat_auto_group FROM classes WHERE id = ? AND deleted_at IS NULL");
mysqli_stmt_bind_param($stmt, "i", $class_id);
mysqli_stmt_execute($stmt);
$class = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$class || !can_view_class($conn, $current_teacher_id, $class_id) || !can_manage_roster($conn, $current_teacher_id, $class_id)) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:inherit;padding:30px;color:#999;">无权管理该班级的分组 / 座位</body>';
    exit();
}

// ===== 保存座位：seat_cfg = {cols:每组列数, groups:分组数, auto:自动按座位分组}；seat_map = {学生id: 座位位置(1起)} =====
// 保存后同步分组：每个座位分组 → 「第N组」（不存在则建立），入座该座位组的学生归入；未入座 → 未分组
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'seat_save') {
    $cfg = json_decode($_POST['seat_cfg'] ?? '', true);
    $map = json_decode($_POST['seat_map'] ?? '', true);
    $cols = is_array($cfg) && isset($cfg['cols']) ? intval($cfg['cols']) : 2;
    $groups_n = is_array($cfg) && isset($cfg['groups']) ? intval($cfg['groups']) : 4;
    $auto = (is_array($cfg) && !empty($cfg['auto'])) ? 1 : 0;
    if ($cols < 1 || $cols > 8) $cols = 2;
    if ($groups_n < 1 || $groups_n > 12) $groups_n = 4;
    $stmt = mysqli_prepare($conn, "UPDATE classes SET seat_cols = ?, seat_groups = ?, seat_auto_group = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "iiii", $cols, $groups_n, $auto, $class_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    // 先全部清空再写入本次分配
    mysqli_query($conn, "UPDATE students SET seat_pos = 0 WHERE class_id = " . intval($class_id));
    $seated = 0;
    $map_norm = []; // 学生id => 座位位置（过滤非法）
    if (is_array($map)) {
        $stmt = mysqli_prepare($conn, "UPDATE students SET seat_pos = ? WHERE id = ? AND class_id = ?");
        foreach ($map as $sid7 => $pos7) {
            $sid7 = intval($sid7); $pos7 = intval($pos7);
            if ($sid7 <= 0 || $pos7 <= 0) continue;
            mysqli_stmt_bind_param($stmt, "iii", $pos7, $sid7, $class_id);
            mysqli_stmt_execute($stmt);
            $seated++;
            $map_norm[$sid7] = $pos7;
        }
        mysqli_stmt_close($stmt);
    }
    // ===== 分组同步：座位槽位 j → 组「第(order[j]+1)组」（组名跟组走：拖拽整组调序后各组成员名称不变）；
    //       sort = 槽位号（1起），大屏「分组显示 / 座位模式」按此顺序呈现；未入座 → 未分组 =====
    $span = $cols * $groups_n;
    $order = (is_array($cfg) && isset($cfg['order']) && is_array($cfg['order']) && count($cfg['order']) === $groups_n)
        ? array_map('intval', $cfg['order']) : range(0, $groups_n - 1);
    $chk = $order; sort($chk);
    if ($chk !== range(0, $groups_n - 1)) $order = range(0, $groups_n - 1);   // 非合法排列 → 回退为顺序
    $slot_group = []; // 座位槽位 j(1起) => stu_groups.id
    for ($j = 1; $j <= $groups_n; $j++) {
        $gname = '第' . ($order[$j - 1] + 1) . '组';
        $stmt = mysqli_prepare($conn, "SELECT id FROM stu_groups WHERE class_id = ? AND name = ?");
        mysqli_stmt_bind_param($stmt, "is", $class_id, $gname);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if ($row) {
            $gid7 = intval($row['id']);
            $stmt = mysqli_prepare($conn, "UPDATE stu_groups SET sort = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $j, $gid7);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO stu_groups (class_id, name, sort, created_at) VALUES (?, ?, ?, NOW())");
            mysqli_stmt_bind_param($stmt, "isi", $class_id, $gname, $j);
            mysqli_stmt_execute($stmt);
            $gid7 = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);
        }
        $slot_group[$j] = $gid7;
    }
    mysqli_query($conn, "UPDATE students SET group_id = 0 WHERE class_id = " . intval($class_id));
    if ($slot_group) {
        $stmt = mysqli_prepare($conn, "UPDATE students SET group_id = ? WHERE id = ? AND class_id = ?");
        foreach ($map_norm as $sid8 => $pos8) {
            $j8 = intdiv(($pos8 - 1) % $span, $cols) + 1;   // 座位槽位：组内列偏移 / 每组列数
            if (!isset($slot_group[$j8])) continue;
            $gid8 = $slot_group[$j8];
            mysqli_stmt_bind_param($stmt, "iii", $gid8, $sid8, $class_id);
            mysqli_stmt_execute($stmt);
        }
        mysqli_stmt_close($stmt);
    }
    // 保存成功：刷新父页面（弹窗随之关闭，座位 / 分组即时生效）；非 iframe 直接访问则回名单页
    echo '<!doctype html><meta charset="utf-8"><body><script>try { parent.location.reload(); } catch (e) { location.href = "students.php?class_id=' . intval($class_id) . '"; }</script></body>';
    exit();
}

// ===== 弹窗数据：学生 id/姓名/座号/当前座位 + 班级布局配置 =====
$seat_students = [];
$res = mysqli_query($conn, "SELECT id, name, seat_no, seat_pos FROM students WHERE class_id = " . intval($class_id) . " ORDER BY CAST(seat_no AS UNSIGNED) ASC, id ASC");
while ($row = mysqli_fetch_assoc($res)) $seat_students[] = $row;
$seat_cfg = [
    'cols'   => max(1, intval($class['seat_cols'] ?? 2)),
    'groups' => max(1, intval($class['seat_groups'] ?? 4)),
    'auto'   => intval($class['seat_auto_group'] ?? 0),
];
// 上次保存的组顺序（槽位 => 稳定组号0起）：从 sort 1..N 的「第N组」组名反解；缺槽 / 越界以未用小组号补齐
$order0 = [];
if ($seat_cfg['groups'] > 0) {
    $res_o = mysqli_query($conn, "SELECT name, sort FROM stu_groups WHERE class_id = " . intval($class_id) . " AND sort >= 1 AND sort <= " . intval($seat_cfg['groups']) . " ORDER BY sort ASC, id ASC");
    while ($row_o = mysqli_fetch_assoc($res_o)) {
        if (preg_match('/^第\s*(\d+)\s*组$/u', $row_o['name'], $m_o)) {
            $st_o = intval($m_o[1]) - 1;
            $j_o = intval($row_o['sort']) - 1;
            if ($st_o >= 0 && $st_o < $seat_cfg['groups'] && !in_array($st_o, $order0, true)) $order0[$j_o] = $st_o;
        }
    }
    ksort($order0);
    $used_o = array_values($order0);
    for ($j = 0; $j < $seat_cfg['groups']; $j++) {
        if (!isset($order0[$j])) {
            $n_o = 0;
            while (in_array($n_o, $used_o, true)) $n_o++;
            $order0[$j] = $n_o;
            $used_o[] = $n_o;
        }
    }
    ksort($order0);
    $order0 = array_values($order0);
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>设置分组 - <?php echo htmlspecialchars($class['name']); ?></title>
<link rel="stylesheet" href="assets/css/style.css?v=20260909a">
<style>
html, body { margin: 0; padding: 14px 16px 16px; background: #fff; font-size: 14px; }
h3.st { margin: 0 0 12px; font-size: 17px; }
h3.st span { font-size: 12px; color: #999; font-weight: normal; }
/* ===== 座位编辑区 ===== */
.seat-stage { border: 1px solid #e3e6f0; border-radius: 10px; padding: 12px; background: #f8f9fd; }
.seat-podium { background: linear-gradient(180deg, #8d9bd8, #667eea); color: #fff; text-align: center; border-radius: 8px; padding: 7px 0; font-weight: bold; letter-spacing: 12px; text-indent: 12px; font-size: 14px; margin-bottom: 12px; box-shadow: 0 2px 6px rgba(102,126,234,.3); }
.seat-groups { display: flex; flex-wrap: wrap; gap: 10px; }
.seat-panel { flex: 1 1 200px; min-width: 180px; border: 1px solid #e0e3ef; border-radius: 10px; background: #fff; padding: 8px; }
.seat-panel-head { font-size: 13px; font-weight: bold; color: #5568d3; text-align: center; margin-bottom: 8px; cursor: grab; user-select: none; }
.seat-panel-head:active { cursor: grabbing; }
.seat-panel-head .seat-drag-handle { color: #aab2e8; margin-right: 5px; font-weight: normal; }
.seat-panel.grp-over { outline: 2px dashed #667eea; outline-offset: 2px; background: #f4f6ff; }
.seat-panel.grp-dragging { opacity: .45; }
.seat-grid { display: grid; gap: 8px; }
.seat-cell { min-height: 46px; border: 2px dashed #d6daea; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: #fbfcff; cursor: pointer; transition: all .15s; }
.seat-cell.empty:hover, .seat-cell.dragover { border-color: #667eea; background: #eef0fd; }
.seat-empty-txt { color: #c3c9de; font-size: 12px; }
.seat-chip { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; border-radius: 8px; padding: 6px 8px; font-size: 13px; cursor: grab; user-select: none; width: 100%; text-align: center; box-shadow: 0 1px 4px rgba(102,126,234,.35); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; box-sizing: border-box; }
.seat-chip.sel { outline: 3px solid #f39c12; background: linear-gradient(135deg, #e67e22, #f39c12); }
.seat-chip .seat-chip-no { opacity: .75; font-size: 11px; margin-right: 4px; }
.seat-pool-wrap { margin-top: 10px; }
.seat-pool { display: flex; flex-wrap: wrap; gap: 8px; min-height: 42px; border: 1px dashed #d6daea; border-radius: 8px; padding: 8px; background: #fff; }
.seat-pool .seat-chip { width: auto; min-width: 64px; }
.modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 14px; }
</style>
</head>
<body>
<h3 class="st">🪑 设置分组（座位） <span><?php echo htmlspecialchars($class['name']); ?> · 拖拽姓名到座位，或点选姓名后再点座位；<b>拖拽「第N组」标题可整组调换顺序（组名跟组走）</b>；保存后大屏「座位模式」按此呈现，分组自动按座位生成</span></h3>
<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:10px;">
    <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;">每组列数
        <select id="seatColsSel" class="form-control" style="width:80px;padding:4px 6px;" onchange="onSeatCfgChange()"></select>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;">分组数
        <select id="seatGroupsSel" class="form-control" style="width:80px;padding:4px 6px;" onchange="onSeatCfgChange()"></select>
    </label>
    <div style="flex:1;"></div>
    <button type="button" class="btn btn-sm btn-danger" onclick="seatClearAll()">清空座位</button>
</div>
<div class="seat-stage">
    <div class="seat-podium">讲 台</div>
    <div id="seatMap"></div>
</div>
<div class="seat-pool-wrap">
    <div style="font-size:12px;color:#999;margin-bottom:6px;">未入座学生（<span id="seatPoolCnt">0</span>）：点击姓名选中后，再点击座位即可入座；也可直接拖拽到座位，或<b>拖到某组面板任意空白处 = 自动入座到该组第一个空座</b>（该组坐满时自动向下延伸一行座位，不怕没地方放）；拖回此处 = 取消入座。保存后按座位自动生成「第1组、第2组…」分组，未入座学生为未分组；<b>拖拽组标题整组调序后，组名跟组走、大屏按新顺序显示</b></div>
    <div id="seatPool" class="seat-pool"></div>
</div>
<div class="modal-actions">
    <button type="button" class="btn btn-outline" onclick="closeSeatModal()">取消</button>
    <button type="button" class="btn" onclick="saveSeats()">保存</button>
</div>

<form method="post" id="seatForm" action="seat_modal.php" autocomplete="off">
    <input type="hidden" name="action" value="seat_save" autocomplete="off">
    <input type="hidden" name="class_id" value="<?php echo $class_id; ?>" autocomplete="off">
    <input type="hidden" name="seat_cfg" id="seat_cfg_input" autocomplete="off">
    <input type="hidden" name="seat_map" id="seat_map_input" autocomplete="off">
</form>

<script>
// ===== 座位设置（讲台上、分组×列下；拖拽 / 点选调整；保存后分组随座位同步） =====
var SEAT_STUDENTS = <?php echo json_encode($seat_students, JSON_UNESCAPED_UNICODE); ?>;
var SEAT_CFG = <?php echo json_encode($seat_cfg); ?>;
var ORDER0 = <?php echo json_encode(!empty($order0) ? $order0 : [], JSON_UNESCAPED_UNICODE); ?>;   // 上次保存的组顺序（槽位 => 稳定组号0起）
var gOrder = [];       // 当前槽位 => 稳定组号（0起）：拖拽整组调序改此处并同步重排座位映射，组名跟组走
var grpDragFrom = -1;  // 正在整组拖拽的槽位（-1 = 无组级拖拽，区分学生芯片拖拽）
var seatAssign = {};   // 学生id => 座位位置（1起；无/0 = 未入座）
var seatSel = 0;       // 当前点选的学生id
function seatCols() { return parseInt(document.getElementById('seatColsSel').value, 10) || 2; }
function seatGroups() { return parseInt(document.getElementById('seatGroupsSel').value, 10) || 4; }
// 组顺序初始化 / 扩缩容：槽位数对齐当前分组数（扩：补未用小组号；缩：截断）
function syncGroupOrder() {
    var G = seatGroups();
    gOrder = (gOrder.length ? gOrder : (ORDER0 || []).slice());
    while (gOrder.length < G) { var n = 0; while (gOrder.indexOf(n) !== -1) n++; gOrder.push(n); }
    if (gOrder.length > G) gOrder.length = G;
}
function seatStudent(sid) {
    for (var i = 0; i < SEAT_STUDENTS.length; i++) if (String(SEAT_STUDENTS[i].id) === String(sid)) return SEAT_STUDENTS[i];
    return null;
}
function escSeat(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
// 初始化：读取当前布局配置与已有座位
(function initSeatModal() {
    seatAssign = {}; seatSel = 0;
    SEAT_STUDENTS.forEach(function (s) {
        var p = parseInt(s.seat_pos || 0, 10);
        if (p > 0) seatAssign[s.id] = p;
    });
    var cs = document.getElementById('seatColsSel'), gs = document.getElementById('seatGroupsSel');
    for (var i = 1; i <= 8; i++) cs.add(new Option(i + ' 列', i));
    for (var j = 1; j <= 12; j++) gs.add(new Option(j + ' 组', j));
    cs.value = SEAT_CFG.cols; gs.value = SEAT_CFG.groups;
    syncGroupOrder();
    renderSeatEditor();
})();
function closeSeatModal() {
    // iframe 场景：关闭父页面弹窗；直接访问时返回名单页
    try {
        var mk = parent.document.getElementById('seatModal');
        if (mk) mk.classList.remove('show');
        var mk2 = parent.document.getElementById('seatGroupModal');
        if (mk2) mk2.classList.remove('show');
    } catch (e) {
        location.href = 'students.php?class_id=' + <?php echo $class_id; ?>;
    }
}
function onSeatCfgChange() { syncGroupOrder(); renderSeatEditor(); }
function makeSeatChip(s) {
    var chip = document.createElement('div');
    chip.className = 'seat-chip' + (seatSel == s.id ? ' sel' : '');
    chip.draggable = true;
    chip.innerHTML = '<span class="seat-chip-no">' + escSeat(s.seat_no || '') + '</span>' + escSeat(s.name);
    chip.title = '点击选中，再点座位入座 / 交换；拖拽可直接移动';
    chip.onclick = function (e) { e.stopPropagation(); seatSel = (seatSel == s.id) ? 0 : s.id; renderSeatEditor(); };
    chip.ondragstart = function (e) {
        e.dataTransfer.setData('text/plain', s.id);
        try { e.dataTransfer.effectAllowed = 'move'; } catch (ex) {}
    };
    return chip;
}
function renderSeatEditor() {
    var G = seatGroups(), C = seatCols(), span = G * C;
    var occ = {};                    // 位置 => 学生id
    var rowMax = {}, cntG = {};      // 各组已用最高行号 / 入座人数
    for (var g0 = 0; g0 < G; g0++) { rowMax[g0] = -1; cntG[g0] = 0; }
    for (var sid in seatAssign) {
        var p = parseInt(seatAssign[sid], 10);
        if (p <= 0) continue;
        occ[p] = sid;
        var gi = Math.floor(((p - 1) % span) / C);
        rowMax[gi] = Math.max(rowMax[gi], Math.floor((p - 1) / span));
        cntG[gi]++;
    }
    // 各组行数独立计算：覆盖本组已用最高行；本组座位已全部占满 → 自动向下延伸一行（新成员有位可拖）
    var rowsG = [];
    for (var g1 = 0; g1 < G; g1++) {
        var rows = Math.max(1, rowMax[g1] + 1);
        if (rows * C - cntG[g1] <= 0) rows++;
        rowsG[g1] = rows;
    }
    var map = document.getElementById('seatMap');
    map.innerHTML = '';
    var wrap = document.createElement('div');
    wrap.className = 'seat-groups';
    for (var g = 0; g < G; g++) {
        var panel = document.createElement('div');
        panel.className = 'seat-panel';
        panel.dataset.g = g;
        var head = document.createElement('div');
        head.className = 'seat-panel-head';
        head.draggable = true;
        head.title = '拖拽我可整组调整顺序（组名跟组走；保存后大屏按新顺序显示）';
        head.innerHTML = '<span class="seat-drag-handle">⠿</span>第' + (gOrder[g] + 1) + '组（' + cntG[g] + '人）';
        head.ondragstart = function (e) {
            grpDragFrom = parseInt(this.parentElement.dataset.g, 10);
            this.parentElement.classList.add('grp-dragging');
            e.dataTransfer.setData('text/plain', 'GRP' + grpDragFrom);
            try { e.dataTransfer.effectAllowed = 'move'; } catch (ex) {}
        };
        head.ondragend = function () {
            grpDragFrom = -1;
            document.querySelectorAll('.seat-panel').forEach(function (p) { p.classList.remove('grp-over', 'grp-dragging'); });
        };
        panel.appendChild(head);
        var box = document.createElement('div');
        box.className = 'seat-grid';
        box.style.gridTemplateColumns = 'repeat(' + C + ', minmax(0,1fr))';
        for (var r = 0; r < rowsG[g]; r++) {
            for (var c = 0; c < C; c++) {
                var pos = r * span + g * C + c + 1;
                var cell = document.createElement('div');
                cell.className = 'seat-cell';
                cell.dataset.pos = pos;
                var sidAt = occ[pos];
                var s = sidAt ? seatStudent(sidAt) : null;
                if (s) { cell.classList.add('filled'); cell.appendChild(makeSeatChip(s)); }
                else {
                    cell.classList.add('empty');
                    cell.innerHTML = '<span class="seat-empty-txt">空座</span>';
                    cell.title = '座位 #' + pos + (seatSel ? '（点击让选中学生入座）' : '');
                }
                cell.onclick = function () { seatPlace(parseInt(this.dataset.pos, 10)); };
                cell.ondragover = function (e) { e.preventDefault(); this.classList.add('dragover'); };
                cell.ondragleave = function () { this.classList.remove('dragover'); };
                cell.ondrop = function (e) {
                    e.preventDefault(); e.stopPropagation(); this.classList.remove('dragover');
                    if (grpDragFrom > -1) {   // 组级拖拽落到座位格：交给所属面板整组调序
                        var pn = this.closest('.seat-panel');
                        var to = pn ? parseInt(pn.dataset.g, 10) : -1;
                        var from = grpDragFrom; grpDragFrom = -1;
                        document.querySelectorAll('.seat-panel').forEach(function (p) { p.classList.remove('grp-over', 'grp-dragging'); });
                        reorderGroupSlots(from, to);
                        return;
                    }
                    var sid = e.dataTransfer.getData('text/plain');
                    if (sid) seatMove(parseInt(sid, 10), parseInt(this.dataset.pos, 10));
                };
                box.appendChild(cell);
            }
        }
        // 拖到本组面板任意空白处 = 自动入座到该组第一个空座（组满时上方已自动延伸一行，始终有位可放）；
        // 整组拖拽时面板高亮为放置目标，松手即整组调序
        panel.ondragover = function (e) {
            e.preventDefault();
            if (grpDragFrom > -1 && parseInt(this.dataset.g, 10) !== grpDragFrom) this.classList.add('grp-over');
        };
        panel.ondragleave = function () { this.classList.remove('grp-over'); };
        panel.ondrop = function (e) {
            e.preventDefault(); e.stopPropagation();
            this.classList.remove('grp-over');
            if (grpDragFrom > -1) {   // 组级拖拽：整组调换顺序
                var from = grpDragFrom, to = parseInt(this.dataset.g, 10);
                grpDragFrom = -1;
                document.querySelectorAll('.seat-panel').forEach(function (p) { p.classList.remove('grp-over', 'grp-dragging'); });
                reorderGroupSlots(from, to);
                return;
            }
            var sid = parseInt(e.dataTransfer.getData('text/plain'), 10);
            if (!sid) return;
            var gg = parseInt(this.dataset.g, 10);
            for (var r2 = 0; r2 < rowsG[gg]; r2++) {
                for (var c2 = 0; c2 < C; c2++) {
                    var p2 = r2 * span + gg * C + c2 + 1;
                    if (!occ[p2]) { seatMove(sid, p2); return; }
                }
            }
        };
        panel.appendChild(box);
        wrap.appendChild(panel);
    }
    map.appendChild(wrap);
    // 未入座池（拖回此处 = 取消入座；点选状态下点击 = 选中者退座）
    var pool = document.getElementById('seatPool');
    pool.innerHTML = '';
    var cnt = 0;
    SEAT_STUDENTS.forEach(function (s) {
        if (seatAssign[s.id]) return;
        cnt++;
        pool.appendChild(makeSeatChip(s));
    });
    document.getElementById('seatPoolCnt').textContent = cnt;
    pool.ondragover = function (e) { e.preventDefault(); };
    pool.ondrop = function (e) {
        e.preventDefault();
        var sid = parseInt(e.dataTransfer.getData('text/plain'), 10);
        if (sid && seatAssign[sid]) { delete seatAssign[sid]; seatSel = 0; renderSeatEditor(); }
    };
    pool.onclick = function () {
        if (seatSel && seatAssign[seatSel]) { delete seatAssign[seatSel]; seatSel = 0; renderSeatEditor(); }
    };
}
function seatMove(sid, pos) {
    if (!sid || !pos) return;
    // 与占座者交换（原占座者换到拖动学生原座位；拖动学生未入座时原占座者回到未入座池）
    for (var k in seatAssign) {
        if (parseInt(seatAssign[k], 10) === pos && String(k) !== String(sid)) {
            var prev = parseInt(seatAssign[sid] || 0, 10);
            if (prev > 0) seatAssign[k] = prev; else delete seatAssign[k];
        }
    }
    seatAssign[sid] = pos;
    seatSel = 0;
    renderSeatEditor();
}
function seatPlace(pos) { if (seatSel) seatMove(seatSel, pos); }
// 整组拖拽调序：槽位 from → to（组名跟组走），组内学生座位位置同步重排（保留组内行列相对位置）
function reorderGroupSlots(from, to) {
    if (from === to || from < 0 || to < 0 || from >= gOrder.length || to >= gOrder.length) return;
    var newOrder = gOrder.slice();
    var moved = newOrder.splice(from, 1)[0];
    newOrder.splice(to, 0, moved);
    var G = seatGroups(), C = seatCols(), span = G * C;
    var na = {};
    for (var sid in seatAssign) {
        var p = parseInt(seatAssign[sid], 10);
        if (p <= 0) continue;
        var r = Math.floor((p - 1) / span), c = (p - 1) % C;
        var ni = newOrder.indexOf(gOrder[Math.floor(((p - 1) % span) / C)]);
        na[sid] = (ni < 0) ? p : r * span + ni * C + c + 1;
    }
    seatAssign = na;
    gOrder = newOrder;
    renderSeatEditor();
}
function seatClearAll() {
    if (!confirm('确认清空全部座位？所有学生将回到未入座（保存后生效，分组同样随座位清空）。')) return;
    seatAssign = {}; seatSel = 0; renderSeatEditor();
}
function saveSeats() {
    // auto 保留班级原值（「自动按照座位分组」勾选项已移除；分组归属本就随座位保存自动同步）
    var cfg = { cols: seatCols(), groups: seatGroups(), auto: SEAT_CFG.auto ? 1 : 0, order: gOrder.slice() };
    document.getElementById('seat_cfg_input').value = JSON.stringify(cfg);
    document.getElementById('seat_map_input').value = JSON.stringify(seatAssign);
    document.getElementById('seatForm').submit();
}
</script>
</body>
</html>
