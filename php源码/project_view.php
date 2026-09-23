<?php
/**
 * 项目登记操作页（数字网格版）
 *  - 一行个数可选（默认 5 个），序号点击直接亮起登记 / 再点取消
 *  - 开关"登记+评价"：点击序号直接登记并弹窗评价
 *  - 开关"批量评价"：先设置好评价内容，之后点击的序号全部登记并打相同评价
 *  - 批量操作：全选/全不选、批量登记已选、批量评价已选
 *  - 多班级项目（全校/年段/学科）支持班级切换
 */
require_once 'includes/db_config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/layout.php';

$conn = getConnection();

$project_id = intval($_GET['project_id'] ?? 0);
$project = get_project_for($conn, $project_id, $current_teacher_id, 'operate');
$view_only = false;
if (!$project) {
    // 无操作权限时，班级授权 view 级成员（或可只读查看者）进入只读视图
    $project = get_project_for($conn, $project_id, $current_teacher_id, 'view');
    if (!$project) {
        header("Location: projects.php");
        exit();
    }
    $view_only = true;
}
$class_ids = get_project_class_ids($conn, $project);
// 题次管理权限（管理员/可建立级别）：仅登记与仅查看成员只可切换与查看题次，不可新增/删除/重命名/排序/导入
$pv_round_admin = !$view_only && (bool)get_project_for($conn, $project_id, $current_teacher_id, 'manage');
// 喊话/积分入口：喊话需仅登记及以上（view_only=false 即等价）；积分弹窗全部班级授权成员可看（仅查看只读，操作由后端拒绝）
$pv_can_ann = !$view_only;

// 多班级项目：选择当前班级
$multi_class = count($class_ids) > 1;
$current_class_id = intval($_GET['cls_id'] ?? 0);
if (!in_array($current_class_id, $class_ids, true)) {
    $current_class_id = $class_ids[0];
}
$pv_can_pts = can_view_class($conn, $current_teacher_id, $current_class_id);

// 当前班级信息（含座位模式布局配置）
$class_info = null;
$stmt = mysqli_prepare($conn, "SELECT id, name, seat_cols, seat_groups, seat_auto_group FROM classes WHERE id = ? AND deleted_at IS NULL");
mysqli_stmt_bind_param($stmt, "i", $current_class_id);
mysqli_stmt_execute($stmt);
$class_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

// 学生列表 + 本项目登记状态
// 次项/题次模式（multi=多次登记 quiz=答题四选一 raise=举牌四选一）：按当前轮次从 record_rounds 取登记与评价
$is_multi = (($project['mode'] ?? 'count') === 'multi');
$is_quiz = (($project['mode'] ?? 'count') === 'quiz');      // 答题模式（答题码四选一）
$is_raise = (($project['mode'] ?? 'count') === 'raise');    // 举牌模式（黑白图案卡四选一，规则同答题）
$is_abcd = $is_quiz || $is_raise;                           // 四选一（A/B/C/D）模式统称
$is_omr = (($project['mode'] ?? 'count') === 'omr');        // 答题卡模式（涂卡拍照识别）
$rounded_mode = $is_multi || $is_abcd || $is_omr;           // 答题卡模式同样按题次登记/评价
$round_label = ($is_abcd || $is_omr) ? '题次' : '项次';
$rounds = $rounded_mode ? project_rounds_list($conn, $project_id) : [];
$current_round = 0;
if ($rounded_mode) {
    $current_round = intval($_GET['round'] ?? 0);
    if (!in_array($current_round, $rounds, true)) $current_round = $rounds[count($rounds) - 1];
}
$check_map = [];   // 登记时间映射（student_id => 'Y-m-d H:i:s'，补登记/锁定计算用）
if ($rounded_mode) {
    $stmt = mysqli_prepare($conn, "SELECT s.*, (rr.registered_at IS NOT NULL) AS registered, rr.registered_at, rr.registered_by, rr.eval_value
                                   FROM students s
                                   LEFT JOIN record_rounds rr ON rr.student_id = s.id AND rr.project_id = ? AND rr.round_no = ?
                                   WHERE s.class_id = ? AND s.disabled = 0
                                   ORDER BY CAST(s.seat_no AS UNSIGNED) ASC, s.id ASC");
    mysqli_stmt_bind_param($stmt, "iii", $project_id, $current_round, $current_class_id);
} else {
    $stmt = mysqli_prepare($conn, "SELECT s.*, r.registered, r.registered_at, r.registered_by, r.eval_value
                                   FROM students s
                                   LEFT JOIN records r ON r.student_id = s.id AND r.project_id = ?
                                   WHERE s.class_id = ? AND s.disabled = 0
                                   ORDER BY CAST(s.seat_no AS UNSIGNED) ASC, s.id ASC");
    mysqli_stmt_bind_param($stmt, "ii", $project_id, $current_class_id);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$students = [];
$registered_count = 0;
$evaluated_count = 0;
while ($row = mysqli_fetch_assoc($result)) {
    $students[] = $row;
    if ($row['registered'] == 1) {
        $registered_count++;
        if (!empty($row['registered_at'])) $check_map[intval($row['id'])] = $row['registered_at'];
    }
    if ($row['eval_value'] !== null && $row['eval_value'] !== '') $evaluated_count++;
}
mysqli_stmt_close($stmt);

// 答题/举牌模式：学生卡片角点数据（右上绿点=本题答对，左下/右下白点=与上/下一题次选择不同；口径与统计名单一致）
$tie_evals = [];     // [round_no][student_id] = 'A'~'D'
$tie_correct = [];   // [round_no] => ['A','C'] 正确答案
if ($is_abcd && $rounds) {
    $rlist = implode(',', array_map('intval', $rounds));
    $res = mysqli_query($conn, "SELECT rr.round_no, rr.student_id, rr.eval_value
                                FROM record_rounds rr
                                INNER JOIN students s ON rr.student_id = s.id
                                WHERE rr.project_id = {$project_id} AND rr.round_no IN ({$rlist})
                                  AND rr.registered_at IS NOT NULL AND rr.eval_value IS NOT NULL AND rr.eval_value <> ''
                                  AND s.disabled = 0 AND s.class_id = " . intval($current_class_id));
    while ($row = mysqli_fetch_assoc($res)) {
        $opt = strtoupper(trim($row['eval_value']));
        if (in_array($opt, ['A', 'B', 'C', 'D'], true)) $tie_evals[intval($row['round_no'])][intval($row['student_id'])] = $opt;
    }
    $res = mysqli_query($conn, "SELECT round_no, correct_opts FROM project_rounds WHERE project_id = {$project_id}");
    while ($row = mysqli_fetch_assoc($res)) {
        $co = [];
        foreach (explode(',', strtoupper($row['correct_opts'] ?? '')) as $o) {
            $o = trim($o);
            if (in_array($o, ['A', 'B', 'C', 'D'], true) && !in_array($o, $co, true)) $co[] = $o;
        }
        if ($co) $tie_correct[intval($row['round_no'])] = $co;
    }
}
$tie_dots = [];      // student_id => {cur, prev, next}（全量学生：cur 为空=本题未答不显示角点，但保留相邻次信息供答题后即时渲染）
foreach ($students as $s) {
    $sid = intval($s['id']);
    $tie_dots[$sid] = ['cur' => $tie_evals[$current_round][$sid] ?? '',
                       'prev' => $tie_evals[$current_round - 1][$sid] ?? '',
                       'next' => $tie_evals[$current_round + 1][$sid] ?? ''];
}
// 班级分组（分组显示用）：分组id => 名称
$groups = [];
$res = mysqli_query($conn, "SELECT id, name FROM stu_groups WHERE class_id = " . intval($current_class_id) . " ORDER BY sort ASC, id ASC");
while ($row = mysqli_fetch_assoc($res)) $groups[intval($row['id'])] = $row['name'];

// 座位组名（座位模式 / 分组显示按座位列分组用）：sort(1起) => 「第N组」组名（组名跟组走：设置分组弹窗拖拽整组调序后按 sort 呈现）
$seat_group_names = [];
$res = mysqli_query($conn, "SELECT name, sort FROM stu_groups WHERE class_id = " . intval($current_class_id) . " AND sort >= 1 ORDER BY sort ASC, id ASC");
while ($row = mysqli_fetch_assoc($res)) {
    if (preg_match('/^第\s*(\d+)\s*组$/u', $row['name'], $m) && $m[1] >= 1) $seat_group_names[intval($row['sort'])] = $row['name'];
}

// 项目覆盖班级的学生总数（用于多班级统计；已禁用学生不参与登记，不计入）
$total_students = 0;
if (count($class_ids) > 0) {
    $in = implode(',', array_map('intval', $class_ids));
    $res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM students WHERE disabled = 0 AND class_id IN ({$in})");
    $total_students = intval(mysqli_fetch_assoc($res)['c']);
}

// 打卡模式（项目 daily）：支持日历查看/切换历史日期打卡情况
$daily_mode = (($project['mode'] ?? 'count') === 'daily');
$view_date = '';
if ($daily_mode && isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) {
    $vd = $_GET['date'];
    $dt = DateTime::createFromFormat('Y-m-d', $vd);
    if ($dt && $dt->format('Y-m-d') === $vd && $vd !== date('Y-m-d')) {
        $view_date = $vd; // 历史日期视图（只读）；今天用默认视图（实时数据）
    }
}
$is_history = $view_date !== '';

// 阈值设置（秒）
$late_seconds = intval($project['late_seconds'] ?? 1800);
$lock_seconds = intval($project['lock_seconds'] ?? 1800);

// 历史日期/过期编辑权限：学校管理员/总管理员恒可；普通教师按后台「过期作业项目可编辑」开关与角色勾选判断
$can_edit_history = !$view_only && can_edit_expired($conn, $current_teacher_id, intval($project['school_id'] ?? 0));

// ===== 大屏视图设置工具条（⚙ 项目设置：点击向左侧展开浮窗；点 ✕ / ⚙ / 页面其他区域收起；项目管理功能统一在项目列表页「管理」菜单） =====
$view_menu = '';
if (!$view_only) {
    ob_start(); ?>
    <div class="admin-dd pv-dd" style="display:inline-flex;align-items:center;">
        <div class="admin-dd-menu pv-dd-menu">
            <?php if (!$is_history): // 大屏视图控制（历史只读视图不可切换） ?>
            <button type="button" class="dd-item" id="grpDirBtn" style="display:none;" onclick="toggleGroupDir()" title="切换分组排布：横向=各组并排（受一行N个控制）／纵向=各组堆叠名单式（不受控制）">⬌ 横向</button>
            <button type="button" class="dd-item" id="grpBtn" onclick="toggleGroupView()" title="按学生分组列出名单（亮灯/消消乐/搬搬乐均可用；有座位数据时按座位列分组）">📋 分组显示</button>
            <button type="button" class="dd-item" id="shapeBtn" onclick="cycleShape()" title="切换格子形状：方形 → 圆形 → 水晶气泡（循环切换，按班级记忆）">⬜ 方形</button>
                        <button type="button" id="modeBtn" class="btn btn-sm btn-outline" onclick="cycleScreenMode()" title="大屏模式切换：亮灯 → 消消乐 → 搬搬乐 → 座位（点击循环切换）">🎮 亮灯模式</button>
            <?php endif; ?>
            <button type="button" class="dd-item dd-danger" onclick="closePvDD()" style="text-align:center;" title="收起设置菜单">✕ 关闭设置</button>
        </div>
        <button type="button" class="btn btn-sm btn-outline" onclick="toggleDD(event,this)">⚙ 设置 ◂</button>
    </div>
    <?php $view_menu = trim(ob_get_clean());
}

// 打卡模式今日打卡名单：student_id => 打卡时间（今日视图格子状态以此为准，避免跨天残留）
$today_map = [];
$day_evals = [];   // 打卡模式按天评价：student_id => 评价内容（今天/历史日期各自独立，避免跨天残留）
if ($daily_mode && !$is_history) {
    $stmt = mysqli_prepare($conn, "SELECT rd.student_id, DATE_FORMAT(rd.created_at, '%Y-%m-%d %H:%i:%s') AS ts, COALESCE(rd.eval_value, '') AS ev
                                   FROM record_days rd INNER JOIN students s ON rd.student_id = s.id
                                   WHERE rd.project_id = ? AND rd.reg_date = CURDATE() AND s.class_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $project_id, $current_class_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $today_map[intval($row['student_id'])] = $row['ts'];
        $day_evals[intval($row['student_id'])] = $row['ev'];
    }
    mysqli_stmt_close($stmt);
    $day_eval_count = count(array_filter($day_evals, 'strlen'));   // 今日已评价数（按天，与瓦片同口径）
}

// 历史日期视图：从每日打卡记录取当天打卡名单
$history_ids = [];
if ($is_history) {
    $stmt = mysqli_prepare($conn, "SELECT rd.student_id, DATE_FORMAT(rd.created_at, '%Y-%m-%d %H:%i:%s') AS ts, COALESCE(rd.eval_value, '') AS ev
                                   FROM record_days rd INNER JOIN students s ON rd.student_id = s.id
                                   WHERE rd.project_id = ? AND rd.reg_date = ? AND s.class_id = ?");
    mysqli_stmt_bind_param($stmt, "isi", $project_id, $view_date, $current_class_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $history_ids[intval($row['student_id'])] = $row['ts'];
        $day_evals[intval($row['student_id'])] = $row['ev'];
    }
    mysqli_stmt_close($stmt);
}

// 登记时间映射（补登记/锁定计算用）：student_id => 'Y-m-d H:i:s'
// 打卡模式取当日打卡记录（历史日期/今天）；普通模式已在学生循环中填充
if ($daily_mode) {
    $check_map = $is_history ? $history_ids : $today_map;
}

// 首位登记时间（本班）——补登记判定基准
$first_ts = 0;
foreach ($check_map as $tsv) {
    $t = strtotime($tsv);
    if ($t && ($first_ts === 0 || $t < $first_ts)) $first_ts = $t;
}

// 日历数据：本项目本班每日打卡人数（日期 => 人数）
$cal_counts = [];
if ($daily_mode) {
    $res = mysqli_query($conn, "SELECT DATE_FORMAT(rd.reg_date, '%Y-%m-%d') AS d, COUNT(*) AS c
                                FROM record_days rd INNER JOIN students s ON rd.student_id = s.id
                                WHERE rd.project_id = {$project_id} AND s.class_id = {$current_class_id}
                                GROUP BY rd.reg_date");
    while ($row = mysqli_fetch_assoc($res)) $cal_counts[$row['d']] = intval($row['c']);
}

// 秒数 → 中文时长
if (!function_exists('seconds_to_txt')) {
    function seconds_to_txt($sec) {
        $sec = intval($sec);
        if ($sec <= 0) return '不启用';
        $d = intdiv($sec, 86400);
        $h = intdiv($sec % 86400, 3600);
        $m = intdiv($sec % 3600, 60);
        $parts = [];
        if ($d) $parts[] = $d . '天';
        if ($h) $parts[] = $h . '小时';
        if ($m || !$parts) $parts[] = $m . '分钟';
        return implode('', $parts);
    }
}

$eval_modes = get_eval_modes();
// 当前评价模式：系统模板或自定义模式（c+编号）统一解析；模式被删除时回退笑脸，避免页面报错
$current_mode = eval_mode_info($conn, $project['eval_mode']) ?: $eval_modes['smile'];

page_header('作业登记 - ' . $project['name'], 'project_view.php');
?>
<?php if (intval($_GET['embed'] ?? 0) !== 1): // 弹窗内嵌模式不显示返回链接 ?>
<a href="projects.php?class_id=<?php echo $current_class_id; ?>" style="display:inline-block;margin-bottom:15px;color:#667eea;text-decoration:none;">← 返回项目列表</a>
<?php endif; ?>
<?php $pv_flash = flash_take(); // 项目设置菜单操作（编辑/置顶/查询开关）经 projects.php PRG 回跳后的一次性提示 ?>
<?php if ($pv_flash): ?><div class="alert alert-<?php echo $pv_flash['type'] === 'error' ? 'error' : 'success'; ?>"><?php echo htmlspecialchars($pv_flash['msg']); ?></div><?php endif; ?>

<?php if ($multi_class): ?>
<div class="panel" style="padding:10px 15px;">
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <span style="color:#666;font-size:13px;">项目班级：</span>
        <?php
        $names_map = [];
        if ($class_ids) {
            $in = implode(',', array_map('intval', $class_ids));
            $res = mysqli_query($conn, "SELECT c.id, c.name FROM classes c WHERE c.id IN ({$in}) ORDER BY c.id");
            while ($row = mysqli_fetch_assoc($res)) $names_map[intval($row['id'])] = $row['name'];
        }
        foreach ($class_ids as $cid): ?>
        <a href="project_view.php?project_id=<?php echo $project_id; ?>&cls_id=<?php echo $cid; ?>"
           class="btn btn-sm<?php echo $cid === $current_class_id ? '' : ' btn-outline'; ?>" style="text-decoration:none;">
            <?php echo htmlspecialchars($names_map[$cid] ?? ('班级' . $cid)); ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
　
<?php if ($is_omr && !$is_history && !$view_only): ?><a href="omr_scan.php?project_id=<?php echo $project_id; ?><?php echo $current_round ? '&round=' . $current_round : ''; ?>" class="btn btn-sm elim-hide" title="摄像头拍照 / 上传照片识别涂卡答题卡">📄 答题卡识别</a>
<a class="btn btn-sm btn-outline" style="text-decoration:none;" href="omr_designer.php?project_id=<?php echo $project_id; ?>" title="可视化设计答题卡布局，导出打印 PDF 并保存为识别模板">🧇 设计模板</a>
<?php elseif (!$is_history && !$view_only): ?><a href="scan.php?project_id=<?php echo $project_id; ?><?php echo $current_round ? '&round=' . $current_round : ''; ?>" class="btn btn-sm elim-hide">📷 扫码登记</a><?php endif; ?>
        <?php if ($is_history && $can_edit_history && !$view_only && !$is_omr): ?><button type="button" class="btn btn-sm elim-hide" onclick="goHistScan()">📷 扫码补登</button><?php endif; ?>
        
<?php if ($daily_mode): ?>
<?php $cal_open = intval($_GET['cal'] ?? 0) === 1; // 从日历点选日期跳转而来：日历保持展开 ?>
<!-- 打卡日历（默认折叠；经日历切换日期后保持展开） -->
<div class="panel" style="padding:10px 15px;">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <button type="button" class="btn btn-sm<?php echo $is_history ? '' : ' btn-outline'; ?>" onclick="toggleCal()">📅 日历<?php echo $is_history ? '：正在查看 ' . htmlspecialchars($view_date) : '（今天 ' . date('Y-m-d') . '）'; ?></button><button type="button" class="hint-q" onclick="toggleHint(event, '<b>打卡日历说明</b><br>· 绿色日期=当天已有打卡记录（数字为当天打卡人数）<br>· 点日期可查看历史日期打卡情况，再点「返回今天」回到当天<br>· 历史日期补登需先在项目设置开启并满足编辑权限<br>· ← 上月 / 下月 → 翻月')">?</button>
    <?php if ($is_abcd): ?><button type="button" id="statToggleBtn" class="btn btn-sm" onclick="openQuizStats()" title="答题统计：本题分布 / 累计对比 / 正确率；举牌模式含累计 / 今日 / 人均举牌次数与选项构成">📊 统计</button><?php elseif ($is_omr): ?><button type="button" id="statToggleBtn" class="btn btn-sm" onclick="openOmrStats()" title="成绩统计：平均分 / 优秀率 / 及格率 / 分段统计">📊 统计</button><?php else: ?><button type="button" id="statToggleBtn" class="btn btn-sm" onclick="openStatsModal()" title="查看统计">📊 统计</button><?php endif; ?>
        <?php if (!$view_only && !$is_history): ?><button type="button" class="btn btn-sm btn-outline" onclick="annOpen()">📣 喊话</button><?php endif; ?><?php if (!$view_only && !$is_history || $pv_can_pts && !$is_history): ?><button type="button" class="btn btn-sm btn-outline" onclick="ptOpen()" title="课堂表现积分：快速给学生加减分">⭐ 积分</button><?php endif; ?>
        <?php if ($is_history): ?>
        <a class="btn btn-sm" style="text-decoration:none;" href="project_view.php?project_id=<?php echo $project_id; ?>&cls_id=<?php echo $current_class_id; ?>">返回今天</a>
        <?php if (!$can_edit_history): ?>
        <span style="color:#e67e22;font-size:13px;">历史日期仅支持查看，不能操作</span>
        <?php endif; ?>
        <?php endif; ?>
        <span id="calSummary" style="color:#666;font-size:13px;"><?php echo $is_history ? '当天打卡 ' . count($history_ids) . ' 人' : ''; ?></span>
    </div>
    <div id="calBox" style="display:<?php echo $cal_open ? '' : 'none'; ?>;margin-top:12px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
            <button type="button" class="btn btn-sm btn-outline" onclick="calMove(-1)">← 上月</button>
            <b id="calTitle" style="font-size:15px;"></b>
            <button type="button" class="btn btn-sm btn-outline" onclick="calMove(1)">下月 →</button>
        </div>
        <div id="calGrid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px;"></div>
    </div>
</div>
<?php endif; ?>

<!-- 统计看板：📊 统计按钮显示/隐藏（默认收起；答题/举牌/答题卡模式不渲染，顶部按钮直达统计弹层） -->

<?php if ($rounded_mode): // 次项/题次切换：胶囊默认显示 3 个（◀▶ 平移显示窗口，点胶囊才切换）；⚙ 管理弹窗可改名/调序/删除/导入导出；「＋」增项；⏳ 展开全部 ?>
<?php $pv_round_base = 'project_view.php?project_id=' . $project_id . '&cls_id=' . $current_class_id . '&round='; ?>
<?php $round_titles = project_rounds_titles($conn, $project_id); // {轮次:自定义标题}，空=胶囊显示序号 ?>
<div class="panel" style="padding:10px 15px;">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <?php if ($is_abcd): ?><button type="button" id="statToggleBtn" class="btn btn-sm" onclick="openQuizStats()" title="答题统计：本题分布 / 累计对比 / 正确率；举牌模式含累计 / 今日 / 人均举牌次数与选项构成">📊 统计</button><?php elseif ($is_omr): ?><button type="button" id="statToggleBtn" class="btn btn-sm" onclick="openOmrStats()" title="成绩统计：平均分 / 优秀率 / 及格率 / 分段统计">📊 统计</button><?php else: ?><button type="button" id="statToggleBtn" class="btn btn-sm" onclick="openStatsModal()" title="查看统计">📊 统计</button><?php endif; ?>
        <?php if ($pv_can_ann): ?><button type="button" class="btn btn-sm btn-outline" onclick="annOpen()">📣 喊话</button><?php endif; ?><?php if ($pv_can_pts): ?><button type="button" class="btn btn-sm btn-outline" onclick="ptOpen()" title="课堂表现积分：快速给学生加减分">⭐ 积分</button><?php endif; ?>
        <span style="color:#666;font-size:13px;"><?php echo $round_label; ?>切换：</span><button type="button" class="hint-q" onclick="toggleHint(event, '<b><?php echo $round_label; ?>说明</b><br>· 多<?php echo $round_label; ?>项目按<?php echo $round_label; ?>分别登记（如第一课、第二课…）<br>· 默认显示 3 个，◀ ▶ 平移显示窗口（仅显示，点胶囊才切换）<br>· ⏳ 展开全部面板点选切换；「＋」新增<?php echo $round_label; ?><br>· ⚙ 管理弹窗：重命名 / ↑↓ 调整顺序 / 删除（有记录需密码）/ 导入导出<br>· 「＋」新增与 ⚙ 管理需「管理员 / 可建立」权限（仅登记 / 仅查看成员只可切换与查看）')">?</button>
        <span id="roundChips" style="display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap;"></span>
        <?php if ($pv_round_admin): ?>
        <button type="button" id="roundManageBtn" class="btn btn-sm btn-outline" onclick="openRoundAdmin()" title="管理<?php echo $round_label; ?>：重命名 / 调整顺序 / 删除（有记录需密码）/ 导入导出">⚙ 管理</button>
        <?php endif; ?>
        <span id="roundCur" style="color:#999;font-size:12px;">当前：第 <?php echo $current_round; ?> <?php echo $round_label; ?><?php $ct = strval($round_titles[$current_round] ?? ''); echo $ct !== '' ? '：' . htmlspecialchars($ct) : ''; ?></span>
    </div>
    <div id="roundPanel" style="display:none;margin-top:10px;padding:10px 12px;border:1px solid #d0d4e8;border-radius:8px;background:#f8f9ff;"></div>
</div>
<div class="modal-mask" id="roundAdminModal" onclick="if(event.target===this)closeRoundAdmin();">
<div class="modal" style="max-width:760px;">
<h3>⚙ <?php echo $round_label; ?>管理</h3>
<div style="max-height:50vh;overflow:auto;">
<table style="width:100%;border-collapse:collapse;font-size:13px;">
    <thead><tr style="color:#888;font-size:12px;border-bottom:1px solid #e5e8f5;">
        <th style="padding:6px 4px;text-align:left;white-space:nowrap;">序号</th>
        <th style="padding:6px 4px;text-align:left;">名称（改后失焦自动保存）</th>
        <th style="padding:6px 4px;text-align:left;white-space:nowrap;">操作</th>
        <th style="padding:6px 4px;text-align:left;white-space:nowrap;">记录数</th>
    </tr></thead>
    <tbody id="roundAdminList"></tbody>
</table>
</div>
<div style="margin-top:12px;padding-top:10px;border-top:1px dashed #e0e4f0;display:flex;flex-direction:column;gap:8px;">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <button type="button" class="btn btn-sm btn-success" onclick="raAdd()">＋ 新增<?php echo $round_label; ?></button>
        <a class="btn btn-sm btn-outline" href="api.php?type=round_export&project_id=<?php echo $project_id; ?>" title="导出全部<?php echo $round_label; ?>名称为 CSV（每行一个）">📤 导出</a>
        <span style="color:#999;font-size:12px;">导入：每行一个名称，第 1 行=第 1 <?php echo $round_label; ?>…仅替换名称/新增，不删除数据</span>
    </div>
    <div style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;">
        <textarea id="roundImportText" placeholder="在此粘贴名称清单（每行一个），或点右侧选择 txt/csv 文件…" style="flex:1;min-width:220px;height:64px;border:1px solid #d0d4e8;border-radius:6px;padding:6px 8px;font-size:12px;resize:vertical;"></textarea>
        <div style="display:flex;flex-direction:column;gap:6px;">
            <label class="btn btn-sm btn-outline" style="cursor:pointer;">📂 选择文件<input type="file" accept=".txt,.csv,text/plain,text/csv" style="display:none;" onchange="roundImportFile(this)" autocomplete="off"></label>
            <button type="button" class="btn btn-sm" onclick="roundImportDo()">📥 导入</button>
        </div>
    </div>
</div>
<div class="modal-actions">
    <button type="button" class="btn" onclick="closeRoundAdmin()">关闭</button>
</div>
</div>
</div>
<?php elseif (!$daily_mode): // 普通项目：与打卡项目同款顶部操作行（📊 统计看板开关；⚙ 项目设置在标题行「⛶ 全屏」左侧） ?>
<div class="panel" style="padding:10px 15px;">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <?php if ($is_abcd): ?><button type="button" id="statToggleBtn" class="btn btn-sm" onclick="openQuizStats()" title="答题统计：本题分布 / 累计对比 / 正确率；举牌模式含累计 / 今日 / 人均举牌次数与选项构成">📊 统计</button><?php elseif ($is_omr): ?><button type="button" id="statToggleBtn" class="btn btn-sm" onclick="openOmrStats()" title="成绩统计：平均分 / 优秀率 / 及格率 / 分段统计">📊 统计</button><?php else: ?><button type="button" id="statToggleBtn" class="btn btn-sm" onclick="openStatsModal()" title="查看统计">📊 统计</button><?php endif; ?>
        <?php if ($pv_can_ann): ?><button type="button" class="btn btn-sm btn-outline" onclick="annOpen()">📣 喊话</button><?php endif; ?><?php if ($pv_can_pts): ?><button type="button" class="btn btn-sm btn-outline" onclick="ptOpen()" title="课堂表现积分：快速给学生加减分">⭐ 积分</button><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!$is_abcd && !$is_omr): // 答题/举牌/答题卡模式：顶部「📊 统计」直接打开对应统计弹层（答题分布/成绩统计），人数看板无实际价值不渲染；打卡/历史/普通模式渲染统一统计弹层（ECharts 单图区 + 维度/图型切换 + 全班/分组/个人页签） ?>
<?php
// 评价等级占比数据（conic-gradient 环形图）：按评价模式等级聚合；自由输入（评语/数值）取 Top5+其他
$eval_dist = [];
foreach ($students as $s) {
    $ev = trim(strval($daily_mode ? ($day_evals[intval($s['id'])] ?? '') : ($s['eval_value'] ?? '')));
    if ($ev !== '') $eval_dist[$ev] = ($eval_dist[$ev] ?? 0) + 1;
}
$eval_total = array_sum($eval_dist);
$_em_all = get_enabled_eval_modes($conn, $current_teacher_id);
$_eval_opts = ($_em_all[$project['eval_mode']]['options'] ?? null);
if (is_array($_eval_opts) && count($_eval_opts)) {
    $ordered = [];
    foreach (array_keys($_eval_opts) as $k) if (!empty($eval_dist[$k])) { $ordered[$k] = $eval_dist[$k]; unset($eval_dist[$k]); }
    foreach ($eval_dist as $k => $c) $ordered[$k] = $c;   // 自定义等级之外的值兜底
    $eval_dist = $ordered;
} else {
    arsort($eval_dist);
    if (count($eval_dist) > 5) { $tail = array_splice($eval_dist, 5); $eval_dist['其他'] = array_sum($tail); }
}
$_dist_pal = ['#4f7cff', '#27ae60', '#f39c12', '#e7598b', '#8e44ad', '#00bcd4', '#e74c3c', '#7f8c8d'];
if ($daily_mode) $day_eval_count = count(array_filter($day_evals, 'strlen'));   // 今天/历史日期已评价数（打卡口径，与瓦片一致；历史视图未走 L202，需在此定义）

// ===== 统一统计弹层数据（ECharts 单图区 + 维度/图型切换 + 全班/分组/个人页签）：构建 PV_STAT JSON 供前端渲染 =====
$pv_on_word = $daily_mode ? '打卡' : '登记';
$pv_cls_total = count($students);                                   // 本班人数（口径与登记页格子一致）
$pv_on = $daily_mode ? ($is_history ? count($history_ids) : count($today_map)) : $registered_count;
$pv_ev = $daily_mode ? ($day_eval_count ?? 0) : $evaluated_count;   // 已评价数
$pv_eval_on = ($eval_total > 0) || (is_array($_eval_opts) && count($_eval_opts) > 0);   // 是否开启评价（自由输入/评语=开启但无预设项）
$pv_students = [];
foreach ($students as $s) {
    $sid = intval($s['id']);
    $pv_students[] = [
        'id'   => $sid,
        'name' => strval($s['name']),
        'seat' => intval($s['seat_no']),
        'gid'  => intval($s['group_id'] ?? 0),
        'on'   => $daily_mode ? ($is_history ? isset($history_ids[$sid]) : isset($today_map[$sid])) : (intval($s['registered'] ?? 0) === 1),
        'ev'   => $daily_mode ? (trim(strval($day_evals[$sid] ?? '')) !== '') : (strval($s['eval_value'] ?? '') !== ''),
        'val'  => $daily_mode ? strval($day_evals[$sid] ?? '') : strval($s['eval_value'] ?? ''),   // 评价内容（个人页签展示）
    ];
}
$pv_groups = [];
foreach ($groups as $gid => $gname) $pv_groups[] = ['id' => intval($gid), 'name' => strval($gname)];

// 普通多轮次项目：轮次清单 + 轮次×学生登记矩阵（个人页签「历次变化」；评价内容一并带出）
$pv_rounds = [];
$pv_matrix = [];
if ($rounded_mode && !$daily_mode) {
    foreach ($rounds as $rn) $pv_rounds[] = ['no' => intval($rn), 'title' => strval($round_titles[intval($rn)] ?? '')];
    $res = mysqli_query($conn, "SELECT rr.round_no, rr.student_id, (rr.registered_at IS NOT NULL) AS reg, rr.eval_value
                                FROM record_rounds rr
                                INNER JOIN students s ON rr.student_id = s.id
                                WHERE rr.project_id = {$project_id} AND s.class_id = {$current_class_id}
                                ORDER BY rr.round_no ASC, rr.student_id ASC");
    if ($res) while ($r = mysqli_fetch_assoc($res)) {
        $pv_matrix[] = [intval($r['round_no']), intval($r['student_id']), intval($r['reg']) === 1 ? 1 : 0, strval($r['eval_value'])];
    }
}
$PV_STAT = [
    'mode'       => $daily_mode ? 'daily' : 'plain',   // daily=打卡（含历史日期）；plain=普通登记
    'onWord'     => $pv_on_word,
    'dateLabel'  => $is_history ? $view_date : '',
    'roundLabel' => $round_label,
    'evalOn'     => $pv_eval_on,
    'total'      => $pv_cls_total,
    'on'         => $pv_on,
    'ev'         => $pv_ev,
    'evalDist'   => [],
    'students'   => $pv_students,
    'groups'     => $pv_groups,
    'rounds'     => $pv_rounds,
    'matrix'     => $pv_matrix,
];
foreach ($eval_dist as $k => $v) $PV_STAT['evalDist'][] = ['k' => strval($k), 'v' => intval($v)];
$PV_STAT = json_encode($PV_STAT, JSON_UNESCAPED_UNICODE);
?>
<?php // 统一统计弹层（历史打卡/今日打卡/普通登记 共用）：ECharts 单图区 + 维度/图型切换 + 全班/分组/个人页签（答题卡式风格，一页一图便于大屏讲解） ?>
<div class="modal-mask" id="statsModal" onclick="if(event.target===this)this.classList.remove('show')">
<div class="modal" style="max-width:780px;">
<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
<h3 style="margin:0;">📊 <span id="stTitleTxt"><?php echo $is_history ? '历史打卡统计（' . $view_date . '）' : ($daily_mode ? '今日打卡统计' : '登记统计'); ?></span><button type="button" class="hint-q" onclick="toggleHint(event, '<b>统计弹层说明</b><br>· <b>本班人数</b>=该班在册学生（已禁用学生不计入）；<b>已<?php echo $pv_on_word; ?></b>=所选范围已<?php echo $pv_on_word; ?>人数<br>· <b>模式按钮</b>（亮起=当前统计，点击切换）：📋 登记统计=构成占比（已X已评价 / 已X未评价 / 未X）；⭐ 评价统计=评价内容分布（未开启评价时不显示）<br>· <b>图型</b>：柱形 / 饼图 切换（登记统计默认饼图、评价统计默认柱形）<br>· <b>右上角范围</b>：普通项目=切换项次；打卡=选日期 + 起始日（选填，填了=该日起至所选日期累计）<br>· 一页一图：点击柱子 / 扇区查看对应学生名单；「分组」「个人」页签分别看各组与每个学生状态<br>· 普通多轮次项目在「个人」页签点学生可看历次<?php echo $round_label; ?>登记变化')">?</button></h3>
<span style="display:inline-flex;gap:6px;flex-wrap:wrap;align-items:center;margin-right:80px;"><?php if ($daily_mode): ?><input type="date" id="stDateSel" class="form-control" style="width:auto;padding:5px 8px;font-size:13px;" value="<?php echo $is_history ? $view_date : date('Y-m-d'); ?>" onchange="stScopeChange()" title="统计哪一天（打卡）" autocomplete="off"><input type="date" id="stFromSel" class="form-control" style="width:auto;padding:5px 8px;font-size:13px;" onchange="stScopeChange()" title="统计起始日（选填）：填了则统计该日起至所选日期的累计" autocomplete="off"><?php elseif ($rounded_mode && !$daily_mode): ?><select id="stRoundSel" class="form-control" style="width:auto;padding:5px 8px;font-size:13px;" onchange="stScopeChange()" title="切换统计的项次"></select><?php endif; ?></span>
</div>
<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
    <?php if ($pv_eval_on): ?><button type="button" id="stModeBtn" class="os-tab" onclick="stToggleMode()" title="切换：登记统计（构成占比）/ 评价统计（评价内容分布）">⭐ 评价统计</button><?php endif; ?><button type="button" id="stTypeBtn" class="os-tab" onclick="stToggleType()">📊 柱形</button>
    <span style="flex:1;"></span>
    <button type="button" id="stTabClass" class="os-tab on" onclick="stSetView('class')">👥 全班</button>
    <button type="button" id="stTabGroup" class="os-tab" onclick="stSetView('group')">👪 分组</button>
    <button type="button" id="stTabPerson" class="os-tab" onclick="stSetView('person')">😀 个人</button>
</div>
<div id="stClassCards" class="stat-bar" style="margin-bottom:10px;">
    <div class="stat-box"><div class="num"><?php echo $pv_cls_total; ?></div><div class="label">本班人数</div></div>
    <div class="stat-box"><div class="num green"><?php echo $pv_on; ?></div><div class="label">已<?php echo $pv_on_word; ?>（<?php echo $pv_cls_total ? round($pv_on / $pv_cls_total * 100) : 0; ?>%）</div></div>
    <div class="stat-box"><div class="num orange"><?php echo max(0, $pv_cls_total - $pv_on); ?></div><div class="label">未<?php echo $pv_on_word; ?></div></div>
    <div class="stat-box"><div class="num" style="color:#27ae60;"><?php echo $pv_ev; ?></div><div class="label">已评价</div></div>
    <div class="stat-box"><div class="num" style="color:#f39c12;"><?php echo max(0, $pv_on - $pv_ev); ?></div><div class="label">待评价（已<?php echo $pv_on_word; ?>未评价）</div></div>
</div>
<div id="stGroupView" style="display:none;"></div>
<div id="stPersonView" style="display:none;"></div>
<div id="stCanvas" style="height:360px;"></div>
<div id="stList" style="display:none;margin-top:8px;border:1px dashed #d0d4e8;border-radius:10px;padding:8px 12px;">
    <div id="stListTitle" style="font-weight:bold;font-size:13px;margin-bottom:4px;"></div>
    <div id="stListBody"></div>
</div>
    <div class="modal-actions">
        <button type="button" class="btn" onclick="document.getElementById('statsModal').classList.remove('show')">我知道了</button>
    </div>
</div>
</div>
<script>
var PV_STAT = <?php echo $PV_STAT; ?>;
var ST_PAL = ['#4f7cff', '#27ae60', '#f39c12', '#e7598b', '#8e44ad', '#00bcd4', '#e74c3c', '#7f8c8d'];
var stState = {view: 'class', dim: (PV_STAT.evalOn && PV_STAT.evalDist.length) ? 'eval' : 'comp', type: 'bar'};
if (stState.dim === 'comp') stState.type = 'pie';   // 构成占比默认饼图；评价分布默认柱形

function stStatusColor(st) { return st.ev ? '#27ae60' : (st.on ? '#f39c12' : ''); }
function stStatusWord(st) { return st.ev ? '已评价' : (st.on ? '已' + PV_STAT.onWord + '未评价' : '未' + PV_STAT.onWord); }
function stTags(items) {   // items=[{n,s,c}] → 标签云
    return items.map(function (it) {
        return '<span class="stu-tag" style="cursor:default;' + (it.c ? 'background:' + it.c + ';color:#fff;' : '') + '">' + it.n + (it.s ? ' <span style="font-size:11px;opacity:.85;">' + it.s + '</span>' : '') + '</span>';
    }).join('');
}
function stSidsTags(arr) {
    return arr.map(function (st) { return {n: (st.seat ? st.seat + '. ' : '') + st.name, s: st.ev ? st.val : '', c: stStatusColor(st)}; });
}
function stCompItems() {
    var onNe = Math.max(0, PV_STAT.on - PV_STAT.ev), off = Math.max(0, PV_STAT.total - PV_STAT.on);
    return [
        {name: PV_STAT.onWord + '·已评价', value: PV_STAT.ev, color: '#27ae60'},
        {name: PV_STAT.onWord + '·未评价', value: onNe, color: '#f39c12'},
        {name: '未' + PV_STAT.onWord, value: off, color: '#e74c3c'}
    ];
}
function stCompSids(name) {
    return PV_STAT.students.filter(function (st) {
        if (name === PV_STAT.onWord + '·已评价') return st.on && st.ev;
        if (name === PV_STAT.onWord + '·未评价') return st.on && !st.ev;
        return !st.on;
    });
}
function stEvalSids(k) { return PV_STAT.students.filter(function (st) { return st.ev && st.val === k; }); }
function stListHide() { var l = document.getElementById('stList'); if (l) l.style.display = 'none'; }
function stListShow(title, items) {
    document.getElementById('stListTitle').innerHTML = title;
    document.getElementById('stListBody').innerHTML = stTags(items) || '<span style="color:#999;font-size:13px;">（无）</span>';
    document.getElementById('stList').style.display = '';
}
function stSyncTypeBtn() { document.getElementById('stTypeBtn').textContent = stState.type === 'bar' ? '🥧 饼图' : '📊 柱形'; }
// ===== 模式切换（登记统计 / 评价统计）+ 右上角统计范围（普通=项次；打卡=日期+起始日） =====
var stScope = {round: '', date: '', from: '', defRound: '', defDate: ''};
var stScopeInited = false;
var stScopeDirty = false;   // 范围非页面默认时置 true：refreshStats 轮询跳过，避免覆盖弹层卡片
function stInitScope() {    // 填充右上角范围控件默认值（首次打开弹层时调用，确保 CURRENT_ROUND 已定义）
    if (stScopeInited) return;
    stScopeInited = true;
    var rs = document.getElementById('stRoundSel');
    if (rs) {
        var cr = (typeof CURRENT_ROUND !== 'undefined') ? String(CURRENT_ROUND) : '';
        rs.innerHTML = '';
        (PV_STAT.rounds || []).forEach(function (r) {
            var o = document.createElement('option');
            o.value = String(r.no);
            o.textContent = '第' + r.no + (PV_STAT.roundLabel || '项次') + (r.title ? '：' + r.title : '');
            rs.appendChild(o);
        });
        if ((PV_STAT.rounds || []).some(function (r) { return String(r.no) === cr; })) rs.value = cr;
        stScope.round = rs.value || '';
        stScope.defRound = stScope.round;
    }
    var ds = document.getElementById('stDateSel');
    if (ds) { stScope.date = ds.value || ''; stScope.defDate = stScope.date; }
    var fs = document.getElementById('stFromSel');
    if (fs) stScope.from = fs.value || '';
}
function stModeBtnSync() {
    var mb = document.getElementById('stModeBtn');
    if (mb) {
        mb.textContent = stState.dim === 'comp' ? '📋 登记统计' : '⭐ 评价统计';   // 文案=当前模式（亮起=正在显示的统计），点击切换
        mb.classList.add('on');
        mb.title = stState.dim === 'comp' ? '当前统计：登记统计（构成占比）；点击切换到评价统计（评价内容分布）' : '当前统计：评价统计（评价内容分布）；点击切换到登记统计（构成占比）';
    }
}
function stToggleMode() {
    stState.dim = stState.dim === 'comp' ? 'eval' : 'comp';
    stState.type = stState.dim === 'eval' ? 'bar' : 'pie';   // 登记统计默认饼图、评价统计默认柱形
    stModeBtnSync();
    stSyncTypeBtn();
    stListHide();
    stRender();
}
function stRenderCards() {   // 5 卡按 PV_STAT 重建（范围切换后）
    var box = document.getElementById('stClassCards');
    if (!box) return;
    var t = PV_STAT.total || 0, on = PV_STAT.on || 0, ev = PV_STAT.ev || 0;
    box.innerHTML =
        '<div class="stat-box"><div class="num">' + t + '</div><div class="label">本班人数</div></div>'
      + '<div class="stat-box"><div class="num green">' + on + '</div><div class="label">已' + PV_STAT.onWord + '（' + (t ? Math.round(on / t * 100) : 0) + '%）</div></div>'
      + '<div class="stat-box"><div class="num orange">' + Math.max(0, t - on) + '</div><div class="label">未' + PV_STAT.onWord + '</div></div>'
      + '<div class="stat-box"><div class="num" style="color:#27ae60;">' + ev + '</div><div class="label">已评价</div></div>'
      + '<div class="stat-box"><div class="num" style="color:#f39c12;">' + Math.max(0, on - ev) + '</div><div class="label">待评价（已' + PV_STAT.onWord + '未评价）</div></div>';
}
function stScopeChange() {   // 右上角范围变化：拉取该范围统计数据并整页重渲染
    var rs = document.getElementById('stRoundSel'), ds = document.getElementById('stDateSel'), fs = document.getElementById('stFromSel');
    stScope.round = rs ? (rs.value || '') : '';
    stScope.date = ds ? (ds.value || '') : '';
    stScope.from = fs ? (fs.value || '') : '';
    var body = 'type=view_stats&project_id=' + PROJECT_ID + '&cls_id=' + (PV_CLASS_ID || 0);
    if (PV_STAT.mode === 'daily') body += '&date=' + encodeURIComponent(stScope.date) + '&from=' + encodeURIComponent(stScope.from);
    else body += '&round=' + encodeURIComponent(stScope.round || String(CURRENT_ROUND || 1));
    fetch('api.php', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body})
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (!d || !d.success) { showToast((d && d.message) || '统计读取失败', 'error'); return; }
        ['onWord', 'dateLabel', 'roundLabel', 'evalOn', 'total', 'on', 'ev', 'evalDist', 'students', 'groups', 'rounds', 'matrix'].forEach(function (k) { if (d[k] !== undefined) PV_STAT[k] = d[k]; });
        if (PV_STAT.evalOn === false && stState.dim === 'eval') {   // 该范围未开启评价：回退登记统计
            stState.dim = 'comp';
            stState.type = 'pie';
            stModeBtnSync();
            stSyncTypeBtn();
        }
        var tt = document.getElementById('stTitleTxt');
        if (tt) {
            if (PV_STAT.mode === 'daily') tt.textContent = '打卡统计（' + (d.dateLabel || stScope.date) + '）';
            else {
                var r = (PV_STAT.rounds || []).filter(function (x) { return String(x.no) === String(stScope.round); })[0];
                tt.textContent = '登记统计 · 第' + (stScope.round || '1') + (PV_STAT.roundLabel || '项次') + (r && r.title ? '：' + r.title : '');
            }
        }
        stScopeDirty = (rs && String(stScope.round) !== stScope.defRound) || (ds && stScope.date !== stScope.defDate) || !!(fs && stScope.from);
        stRenderCards();
        stListHide();
        stRender();
    })
    .catch(function () { showToast('网络错误', 'error'); });
}
function stToggleType() {
    stState.type = stState.type === 'bar' ? 'pie' : 'bar';
    stSyncTypeBtn();
    stListHide();
    stRender();
}
function stSetView(v) {
    stState.view = v;
    ['Class', 'Group', 'Person'].forEach(function (k) {
        var b = document.getElementById('stTab' + k);
        if (b) b.classList.toggle('on', k.toLowerCase() === v);
    });
    document.getElementById('stClassCards').style.display = v === 'class' ? '' : 'none';
    document.getElementById('stGroupView').style.display = v === 'group' ? '' : 'none';
    document.getElementById('stPersonView').style.display = v === 'person' ? '' : 'none';
    document.getElementById('stCanvas').style.display = v === 'person' ? 'none' : '';
    stListHide();
    stRender();
}
function stRender() {
    if (stState.view === 'group') return stRenderGroup();
    if (stState.view === 'person') return stRenderPerson();
    stRenderClass();
}
function stRenderClass() {
    var ch;
    if (stState.dim === 'eval') {
        var ks = PV_STAT.evalDist.map(function (it) { return it.k; });
        var vs = PV_STAT.evalDist.map(function (it) { return it.v; });
        if (stState.type === 'pie') {
            var items = PV_STAT.evalDist.map(function (it, i) { return {name: it.k, value: it.v, color: ST_PAL[i % ST_PAL.length]}; });
            ch = ecSet('stCanvas', ecPieOpt(items, {unit: '人', centerMain: String(vs.reduce(function (a, b) { return a + b; }, 0)), centerSub: '已评价'}));
        } else {
            ch = ecSet('stCanvas', ecBarOpt(ks, vs, {colors: ST_PAL, unit: '人'}));
        }
        if (ch) ch.on('click', function (p) { var arr = stEvalSids(p.name); stListShow('「' + p.name + '」共 ' + arr.length + ' 人', stSidsTags(arr)); });
    } else {
        var comp = stCompItems();
        if (stState.type === 'bar') {
            ch = ecSet('stCanvas', ecBarOpt(comp.map(function (it) { return it.name; }), comp.map(function (it) { return it.value; }), {colors: ['#27ae60', '#f39c12', '#e74c3c'], unit: '人'}));
        } else {
            ch = ecSet('stCanvas', ecPieOpt(comp, {unit: '人', centerMain: PV_STAT.on + ' / ' + PV_STAT.total, centerSub: '已' + PV_STAT.onWord + ' / 本班'}));
        }
        if (ch) ch.on('click', function (p) { var arr = stCompSids(p.name); stListShow('「' + p.name + '」共 ' + arr.length + ' 人', stSidsTags(arr)); });
    }
}
function stRenderGroup() {
    var gmap = {};
    PV_STAT.groups.forEach(function (g) { gmap[g.id] = g.name; });
    var rows = {};
    PV_STAT.students.forEach(function (st) {
        var g = rows[st.gid] || (rows[st.gid] = {name: gmap[st.gid] || '未分组', total: 0, on: 0, ev: 0, ids: []});
        g.total++; g.ids.push(st);
        if (st.on) { g.on++; if (st.ev) g.ev++; }
    });
    PV_STAT.groups.forEach(function (g) { if (!rows[g.id]) rows[g.id] = {name: g.name, total: 0, on: 0, ev: 0, ids: []}; });
    var list = Object.keys(rows).map(function (k) { return rows[k]; });
    document.getElementById('stGroupView').innerHTML = '<div style="margin-bottom:8px;display:flex;gap:6px;flex-wrap:wrap;">' + list.map(function (g) {
        return '<span class="stu-tag" style="cursor:default;">' + g.name + '：<b style="color:#27ae60;">' + g.on + '</b>/' + g.total + ' 已' + PV_STAT.onWord + (PV_STAT.evalOn ? ' · 评价 ' + g.ev : '') + '</span>';
    }).join('') + '</div>';
    if (!list.length) return;
    var ch = ecSet('stCanvas', ecBarOpt(list.map(function (g) { return g.name; }), list.map(function (g) { return g.on; }), {colors: ['#667eea'], unit: '人'}));
    if (ch) ch.on('click', function (p) {
        var g = list[p.dataIndex];
        stListShow('「' + g.name + '」' + g.on + ' / ' + g.total + ' 已' + PV_STAT.onWord, stSidsTags(g.ids));
    });
}
function stRenderPerson() {
    var pv = document.getElementById('stPersonView');
    pv.innerHTML = '<div style="margin-bottom:8px;">' + PV_STAT.students.map(function (st) {
        var c = stStatusColor(st);
        return '<span class="stu-tag" style="' + (c ? 'background:' + c + ';color:#fff;' : '') + '" onclick="stShowStu(' + st.id + ')">' + (st.seat ? st.seat + '. ' : '') + st.name + '</span>';
    }).join('') + '</div><div id="stPersonDetail" style="display:none;border:1px solid #e3e6f2;border-radius:10px;padding:10px 14px;background:#f8f9ff;"></div>';
}
function stShowStu(id) {
    var st = PV_STAT.students.filter(function (s) { return s.id === id; })[0];
    if (!st) return;
    var d = document.getElementById('stPersonDetail');
    if (!d) return;
    d.style.display = '';
    var html = '<b>' + (st.seat ? st.seat + '. ' : '') + st.name + '</b>　<span style="color:' + (stStatusColor(st) || '#999') + ';font-weight:bold;">' + stStatusWord(st) + '</span>'
             + (st.ev ? '　评价内容：<b>' + st.val + '</b>' : '');
    if (PV_STAT.mode === 'plain' && PV_STAT.rounds.length) {
        var byRound = {};
        PV_STAT.matrix.forEach(function (m) { if (m[1] === id) byRound[m[0]] = m; });
        html += '<div style="margin-top:8px;">历次' + PV_STAT.roundLabel + '：' + (PV_STAT.rounds.map(function (r) {
            var m = byRound[r.no], on = !!(m && m[2]);
            var t = r.title || ('第 ' + r.no + ' ' + PV_STAT.roundLabel);
            return '<span class="stu-tag" style="cursor:default;' + (on ? 'background:#27ae60;color:#fff;' : '') + '">' + t + (on && m[3] ? '：' + m[3] : '') + (on ? ' ✓' : '') + '</span>';
        }).join('') || '<span style="color:#999;">（无）</span>') + '</div>';
    }
    d.innerHTML = html;
}
(function () {   // 初始按钮态（弹层尚未打开，图区与范围控件延迟到 openStatsModal 再初始化）
    stModeBtnSync();
    stSyncTypeBtn();
})();
</script>
<?php endif; // if (!$is_abcd && !$is_omr) 统计弹窗包裹层 ?>

<div class="panel" id="mainPanel">
    <h3><?php echo htmlspecialchars($project['name']); ?>
        <span class="badge <?php echo eval_badge_class($project['eval_mode']); ?>" style="vertical-align:3px;margin-left:8px;"><?php echo $current_mode['name']; ?></span>
        <?php if (!$is_history && !$view_only && $lock_seconds > 0): ?>
        
        <label style="display:inline-flex;align-items:center;gap:5px;margin-left:12px;font-size:13px;font-weight:normal;cursor:pointer;vertical-align:2px;"
               title="锁定临时开关：勾选=全部临时锁定（不能登记/取消/修改点评）；取消勾选=临时解锁全部。学生登记超过 <?php echo htmlspecialchars(seconds_to_txt($lock_seconds)); ?> 后自动锁定。">
            <input type="checkbox" id="swLock" onchange="onLockToggle()" style="accent-color:#27ae60;width:15px;height:15px;vertical-align:-2px;" autocomplete="off">
            <span id="lockLabel" style="color:#27ae60;">🔒 锁定</span>
        </label>
        <?php endif; ?>
        <?php if ($is_history && $can_edit_history): ?>
        <label style="display:inline-flex;align-items:center;gap:5px;margin-left:12px;font-size:13px;font-weight:normal;cursor:pointer;vertical-align:2px;"
               title="临时解锁历史日期：勾选后本次浏览内可补登/取消该日打卡、评价、扫码补登，工具栏与批量操作同今日（需「过期作业项目可编辑」权限）">
            <input type="checkbox" id="swHist" onchange="onHistToggle()" style="accent-color:#e67e22;width:15px;height:15px;vertical-align:-2px;" autocomplete="off">
            <span id="histLabel" style="color:#999;">🔓 临时解锁编辑</span>
        </label>
        <?php endif; ?>
        <span style="flex:1 1 20px;min-width:10px;"></span>
        <?php if (!$view_only && !$is_history): // 视图设置菜单（与标题同行，靠右） ?>
        <?php echo $view_menu; ?>
        <?php endif; ?>
        <button type="button" id="fsBtn" class="btn btn-sm btn-outline" onclick="toggleFullscreen()">⛶ 全屏</button>
    </h3>
    <?php if (!$is_history && ($late_seconds > 0 || $lock_seconds > 0)): // 倒计时提示：按要求隐藏（代码保留） ?>
    <div style="display:none;margin:-2px 0 10px;font-size:13px;color:#8a6d3b;">
        <?php if ($late_seconds > 0): ?><span id="lateCd"></span><?php endif; ?>
        <?php if ($late_seconds > 0 && $lock_seconds > 0): ?>　·　<?php endif; ?>
        <?php if ($lock_seconds > 0): ?><span id="lockCd"></span><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- 工具栏 -->
    <?php if ($view_only): ?>
    <div class="alert alert-error" style="background:#fff7e6;border-color:#f0c36d;color:#8a6d3b;margin-bottom:10px;">
        👁 您在该班级的授权权限为<b>仅查看</b>，可查看登记与评价情况，不能进行登记操作。
    </div>
    <?php endif; ?>
    <div class="toolbar" id="toolBar1" style="flex-wrap:wrap;">
     
        <?php if (!$view_only && (!$is_history || $can_edit_history)): // 网格控制（历史日期视图需先「临时解锁编辑」）；座位模式：隐藏「一行N个」，显示「查看未分组 / 调整分组」 ?>
        <label class="per-only" style="display:flex;align-items:center;gap:6px;font-size:13px;">
            一行
            <select id="perRow" class="form-control" style="width:70px;padding:4px 6px;" onchange="applyPerRow()">
                <?php for ($i = 1; $i <= 10; $i++): ?>
                <option value="<?php echo $i; ?>"<?php echo $i === 5 ? ' selected' : ''; ?>><?php echo $i; ?></option>
                <?php endfor; ?>
            </select>
            个序号
        </label>
        <button type="button" id="seatUnBtn" class="btn btn-sm btn-outline seat-only" onclick="toggleSeatUn()" title="展开 / 收起未入座学生（未分组学生可正常登记 / 评价 / 批量勾选）">未分组（0）人</button>
        <button type="button" id="seatFillBtn" class="btn btn-sm btn-warning seat-only" onclick="openSeatGroupModal()" title="在弹窗中拖拽 / 点选为学生入座，保存后按座位自动生成分组（未入座学生为未分组）">进行分组</button>
        <?php endif; ?>
        <input type="text" id="stuKwBox" name="stu_search" autocomplete="nope" readonly onfocus="this.removeAttribute('readonly')" class="form-control elim-hide" style="max-width:140px;padding:4px 8px;" placeholder="搜索姓名 / 编号 / 座号" oninput="filterTiles()" title="Chrome 不会向只读输入框自动填充；点击即可正常输入">
        <select id="filterSel" class="form-control elim-hide" style="width:auto;min-width:92px;padding:4px 6px;" onchange="showAll(this.value)" title="按登记/评价状态筛选学生">
            <option value="all">全部</option>
            <option value="no">未登记</option>
            <option value="yes">已登记</option>
            <?php if ($late_seconds > 0): ?><option value="late">补登记</option><?php endif; ?>
            <option value="unev">未评价</option>
            <option value="ev">已评价</option>
        </select>
        <button type="button" class="hint-q elim-hide" onclick="toggleHint(event, '<b>筛选说明</b><br>· <b>补登记</b>=本班首位登记超过设定阈值后的登记（橙色标记）<br>· <b>已评价/未评价</b>按当前评价记录筛选<br>· 与顶部搜索框可叠加使用')">?</button>
        <?php if (!$view_only && (!$is_history || $can_edit_history) && !$is_abcd && !$is_omr): // 答题/举牌/答题卡模式禁用批量：隐藏全选与批量登记/评价按钮 ?>
        <span class="batch-ctl" style="display:contents;">
            <button type="button" id="selAllBtn" class="btn btn-sm btn-outline batch-btn" onclick="toggleSelectAll()" title="合一开关：未全选时=全选可见项；已全选时=全不选">全选</button>
            <button type="button" class="btn btn-sm btn-success batch-btn" onclick="batchRegisterSelected()">登记已选</button>
            <button type="button" class="btn btn-sm btn-warning batch-btn" onclick="batchEvalSelected()">评价已选</button>
            <label class="switch-label sw-only" style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;margin-left:8px;">
                <input type="checkbox" id="swBatchEval" onchange="onBatchEvalChange()" autocomplete="off"> 批量评价
            </label>
            <button type="button" class="hint-q sw-only" onclick="toggleHint(event, '<b>批量评价说明</b><br>· 勾选后先设置评价内容，之后点击的序号全部登记并打相同评价<br>· 弹窗内「清除设置」可随时取消当前设置<br>· 配合「全选 + 登记已选 / 评价已选」可整组统一操作<br>· 如果勾选但没有选择评价内容，每次点击会自动弹窗选择评价')">?</button>
            <span id="batchEvalHint" class="sw-only" style="display:none;color:#27ae60;font-size:13px;">评价内容：<b id="batchEvalText"></b> <a href="javascript:void(0)" onclick="openBatchEvalDialog(true)" style="color:#667eea;">重设</a></span>
        </span>
        <?php endif; ?>
        <?php // ⚙ 设置 + ⛶ 全屏 已上移至标题行（h3）右侧 ?>

    </div>

    <!-- 消消乐视图切换（仅消消乐模式显示）：未登记 / 已登记 -->
    <div id="elimBar" style="display:none;margin-top:12px;text-align:center;">
        <button type="button" id="elimViewBtn" class="btn btn-sm" onclick="toggleElimView()"></button>
    </div>

    <!-- 数字网格 -->
    <div id="tileGrid" style="display:grid;gap:10px;margin-top:15px;">
        <?php foreach ($students as $s):
            $sid = intval($s['id']);
            $check_ts = $check_map[$sid] ?? '';
            $is_reg = $daily_mode ? ($check_ts !== '') : ($s['registered'] == 1);
            // 评价按天取值：打卡模式用当日评价（record_days），避免跨天残留；普通模式用项目汇总（records）
            $eval_now = $daily_mode ? trim(strval($day_evals[$sid] ?? '')) : trim(strval($s['eval_value'] ?? ''));
            $has_eval = $eval_now !== '';
            $regts = $check_ts ? strtotime($check_ts) : 0;
            $is_late = ($regts && $first_ts && $regts > $first_ts + $late_seconds) ? 1 : 0;
            $has_seat = trim(strval($s['seat_no'])) !== '';
        ?>
        <?php $qa = ($is_abcd && $has_eval) ? strtoupper($eval_now) : ''; ?>
        <div class="tile<?php echo $is_reg ? ' on' : ''; ?><?php echo $has_eval ? ' evaled' : ''; ?><?php echo in_array($qa, ['A', 'B', 'C', 'D'], true) ? ' qa qa-' . $qa : ''; ?>"
             id="tile_<?php echo $sid; ?>"
             data-id="<?php echo $sid; ?>"
             data-name="<?php echo htmlspecialchars($s['name']); ?>"
             data-no="<?php echo htmlspecialchars($s['student_no']); ?>"
             data-sno="<?php echo htmlspecialchars($s['seat_no']); ?>"
             data-state="<?php echo $is_reg ? 'yes' : 'no'; ?>"
             data-late="<?php echo $is_late; ?>"
             data-regts="<?php echo $regts; ?>"
             data-eval="<?php echo htmlspecialchars($eval_now); ?>"
             data-gid="<?php echo intval($s['group_id']); ?>"
             data-seat="<?php echo intval($s['seat_pos'] ?? 0); ?>"
             onclick="onTileClick(<?php echo $sid; ?>)" title="<?php echo htmlspecialchars($s['name']); ?>">
            <?php if (!$is_omr && !$is_raise): // 答题卡/举牌模式：卡片不出现勾选框（举牌点击=查看答题变化，勾选无批量用途） ?><input type="checkbox" class="tile-check" onclick="event.stopPropagation();" onchange="onCheckChange(this)" title="选择" autocomplete="off"><?php endif; ?>
            <div class="tile-no"><?php echo htmlspecialchars($s['seat_no'] !== '' ? $s['seat_no'] : $s['student_no']); ?></div>
            <div class="tile-name"><?php echo htmlspecialchars($s['name']); ?></div>
            <?php if ($has_seat): ?><div class="tile-sno" title="编号 <?php echo htmlspecialchars($s['student_no']); ?>">编号 <?php echo htmlspecialchars($s['student_no']); ?></div><?php endif; ?>
            <div class="tile-eval" id="tile_eval_<?php echo $sid; ?>"><?php echo $has_eval ? htmlspecialchars($eval_now) : ''; ?></div>
            <div class="off-confirm">
                <div class="off-q">是否取消</div>
                <div class="off-btns">
                    <button type="button" class="off-yes" onclick="confirmOffYes(event)" title="取消登记（同时清除评价）">是</button>
                    <button type="button" class="off-no" onclick="confirmOffNo(event)" title="不取消">否</button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($students)): ?>
        <div style="grid-column:1/-1;text-align:center;color:#999;padding:20px;">该班级暂无学生，请先<a href="students.php?class_id=<?php echo $current_class_id; ?>" style="color:#667eea;">导入名单</a></div>
        <?php endif; ?>
    </div>

    <!-- 消消乐：全部完成提示（仅消消乐模式且无人未登记时显示） -->
    <div id="elimDone" style="display:none;text-align:center;padding:50px 0;color:#27ae60;font-size:22px;font-weight:bold;">🎉 全部完成！</div>

    <!-- 搬搬乐：左列=未登记，右列=已登记（点未登记→登记移到右边；点已登记→取消移回左边） -->
    <div id="moveBoard" style="display:none;gap:20px;margin-top:15px;align-items:flex-start;">
        <div style="flex:1;min-width:0;">
            <div style="font-size:13px;color:#e67e22;font-weight:bold;margin-bottom:8px;">📌 未登记（<span id="moveLeftCnt">0</span>）</div>
            <div id="moveLeft" class="move-col"></div>
        </div>
        <div style="flex:1;min-width:0;">
            <div style="font-size:13px;color:#27ae60;font-weight:bold;margin-bottom:8px;">✅ 已登记（<span id="moveRightCnt">0</span>）</div>
            <div id="moveRight" class="move-col"></div>
        </div>
    </div>

    <!-- 分组显示容器（亮灯模式：按学生分组重排格子，横向=组并排 / 纵向=组堆叠名单式） -->
    <div id="groupBoard" style="display:none;margin-top:15px;"></div>

    <!-- 座位模式容器（讲台在上方，下方按「分组数 × 每组列数」呈现座位，空位占位；含未分组查看 / 进行分组按钮） -->
    <div id="seatBoard" style="display:none;margin-top:15px;"></div>
</div>

<!-- 评价弹层（单个 / 批量共用） -->
<div class="modal-mask" id="evalModal">
    <div class="modal">
        <h3><span id="eval_title">评价</span> - <span id="eval_student_name"></span></h3>
        <div id="eval_options" class="eval-options"></div>
        <div class="form-group" id="eval_input_group" style="display:none;">
            <input type="text" id="eval_input" class="form-control" placeholder="<?php echo $project['eval_mode'] === 'score' ? '输入0-100的分数' : (strval($project['eval_mode'])[0] === 'c' ? '输入评价内容' : '输入评语'); ?>" autocomplete="nope" readonly onfocus="this.removeAttribute('readonly')" title="点击即可输入（浏览器不会自动填充只读输入框）">
        </div>
        <div class="form-group" id="eval_comment_group" style="display:none;margin-bottom:0;">
            <label style="font-size:12px;color:#8a93a3;">💬 教师点评（可选，随评价一同保存，家长查询「综合报告」中展示）</label>
            <textarea id="eval_comment" class="form-control" rows="3" maxlength="500" placeholder="填写针对该生本项目的点评（清空保存 = 删除点评）" style="resize:vertical;"></textarea>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-danger" id="eval_remove_btn" onclick="removeEval()">删除评价</button>
            <button type="button" class="btn btn-outline" onclick="closeEval()">取消</button>
            <button type="button" class="btn" onclick="submitEval()">确定</button>
        </div>
    </div>
</div>

<!-- 座位分组弹层（iframe 加载 seat_modal.php，与学生名单页「设置分组」共用同一弹窗）；弹窗内自带取消按钮，保存后整页刷新同步座位与分组 -->
<div class="modal-mask" id="seatGroupModal">
    <div class="modal" style="width:1020px;max-width:94vw;height:88vh;display:flex;flex-direction:column;">
        <iframe id="seatGroupFrame" src="about:blank" style="flex:1;width:100%;border:0;border-radius:8px;background:#fff;min-height:0;"></iframe>
    </div>
</div>

<?php
// 举牌模式专属统计卡片（凸显「反复举牌、按次累计」特点）：累计举牌总次数 / 今日举牌次数 / 人均举牌次数（不用 .stat-box 类，避免被 refreshStats 实时刷新覆盖错值）
$qs_raise_cards = '';
if ($is_raise) {
    $_ra = 0; $_rt = 0;
    $res = mysqli_query($conn, "SELECT COUNT(*) AS c, COALESCE(SUM(DATE(registered_at) = CURDATE()), 0) AS t
                                FROM record_rounds WHERE project_id = {$project_id} AND registered_at IS NOT NULL AND eval_value IS NOT NULL AND eval_value <> ''");
    if ($res && ($_rr = mysqli_fetch_assoc($res))) { $_ra = intval($_rr['c']); $_rt = intval($_rr['t']); }
    $qs_raise_cards = '<div style="display:flex;gap:10px;margin:12px 0 2px;flex-wrap:wrap;">'
        . '<div style="flex:1;min-width:150px;border:1px solid #e3e6f2;border-radius:10px;padding:10px;text-align:center;background:#fff;"><div style="font-size:24px;font-weight:bold;color:#667eea;">' . $_ra . '</div><div style="font-size:12px;color:#888;">🏆 累计举牌总次数</div></div>'
        . '<div style="flex:1;min-width:150px;border:1px solid #e3e6f2;border-radius:10px;padding:10px;text-align:center;background:#fff;"><div style="font-size:24px;font-weight:bold;color:#27ae60;">' . $_rt . '</div><div style="font-size:12px;color:#888;">今日举牌次数</div></div>'
        . '<div style="flex:1;min-width:150px;border:1px solid #e3e6f2;border-radius:10px;padding:10px;text-align:center;background:#fff;"><div style="font-size:24px;font-weight:bold;color:#f39c12;">' . ($total_students ? round($_ra / $total_students, 1) : 0) . '</div><div style="font-size:12px;color:#888;">人均举牌次数（项目 ' . $total_students . ' 人）</div></div>'
        . '</div>';
}
?>
<!-- 答题模式统计弹层：本题 ABCD 条形统计（可勾选正确答案，可切饼图）/ 累计（复式条形 · 折线，ECharts）/ 正确率，点柱（扇区 / 折点）看该次名单标签，点学生标签看个人变化 -->
<div class="modal-mask" id="quizStatsModal">
    <div class="modal" style="width:720px;max-width:94vw;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
            <h3 style="margin:0;">📊 答题统计 - <span id="qsRound"></span>
                <button type="button" class="hint-q" onclick="toggleHint(event, '<b>答题统计说明</b><br>· <b>右上角下拉</b>=切换查看的题次（仅切换统计，不改变登记页当前题次）<br>· <b>本题</b>：各选项人数条形图，点柱子=查看该选项学生名单；「🥧 饼图」切换为数量及占比饼图；勾选选项后的「正确答案」（可多选）保存后用于正确率计算<br>· 「🔀 累计」=全部题次对比（📈 可切换复式条形 / 折线统计图）<br>· 「✅ 正确率」=每题答对人数（📈 切换后纵轴=正确率=答对人数÷答题人数；未设置正确答案的题次不参与）<br>· <b>举牌模式</b>：点击「统计」默认显示本题选项分析（各选项人数横条，可切饼图、勾选正确答案）+ 顶部累计/今日/人均卡片；「🥧 构成」=已举牌 / 未举牌占比；右上角按钮亮起显示当前统计（🚩 举牌统计 / ✅ 正确统计=每题答对人数），点击切换；「🔀 累计对比」看全部题次走势<br>· 「👥 分组」=各小组各题次正确率与平均正确率<br>· 「🕸 雷达」=正确率雷达图：维度=总正确率 + 各已设答案题次，点学生标签可多选叠加（最多 8 人），叠加全班平均<br>· 「◀ 上一题 / 下一题 ▶」仅切换查看的题次，不改变登记页当前题次<br>· 点柱子 / 扇区 / 折点 = 查看该次答题名单；点名单里的学生标签 = 查看该生历次答题变化')">?</button>
            </h3>
            <span style="display:inline-flex;gap:6px;flex-wrap:wrap;margin-right:80px;">
                <select id="qsRoundSel" class="form-control" style="width:auto;padding:5px 8px;font-size:13px;" onchange="qsScopeRound(this.value)" title="切换查看的题次统计（不改变登记页当前题次）"></select>
                <?php if ($is_raise): ?><button type="button" id="qsRaiseBtn" class="os-tab on" onclick="qsToggleRaiseMode()" title="当前统计：举牌统计（本题选项分析 + 顶部累计卡片）；点击切换到正确统计（每题答对人数）">🚩 举牌统计</button>
                <button type="button" id="qsTrendBtn" class="os-tab" onclick="qsRaiseTrend()" style="display:none;" title="全部题次累计对比（复式条形 / 折线可切换）">🔀 累计对比</button><?php endif; ?>
                <button type="button" id="qsPrevBtn" class="btn btn-sm btn-outline" onclick="qsSwitchRound(-1)" title="查看上一题次统计">◀ 上一题</button>
                <button type="button" id="qsNextBtn" class="btn btn-sm btn-outline" onclick="qsSwitchRound(1)" title="查看下一题次统计">下一题 ▶</button>
                <button type="button" id="qsModeBtn" class="os-tab" onclick="qsToggleMode()" title="切换：本题统计 / 累计（全部题次对比）">🔀 累计</button>
                <button type="button" id="qsCorrectBtn" class="os-tab" onclick="qsToggleCorrect()" title="每题正确人数统计（先在本题统计中勾选正确答案）">✅ 正确率</button>
                <button type="button" id="qsPieBtn" class="os-tab" onclick="qsTogglePie()" title="本题统计图型切换：柱形（横条）/ 饼图（数量及占比）">🥧 饼图</button>
                <?php if ($is_raise): ?><button type="button" id="qsCompBtn" class="os-tab" onclick="qsToggleExtra('comp')" title="举牌构成：当前题次已举牌 / 未举牌占比（部分与整体）">🥧 构成</button><?php endif; ?>
                <button type="button" id="qsGroupBtn" class="os-tab" onclick="qsToggleExtra('group')" title="按学生分组统计各题次正确率与平均正确率">👥 分组</button>
                <button type="button" id="qsRadarBtn" class="os-tab" onclick="qsToggleExtra('radar')" title="个人正确率雷达图：点学生标签可多选叠加多名学生（最多 8 人），叠加全班平均；维度=总正确率 + 各已设答案题次">🕸 雷达</button>
                <button type="button" id="qsChartBtn" class="os-tab" onclick="qsToggleChart()" style="display:none;" title="切换统计图类型：复式条形统计图 / 折线统计图">📈 切换折线图</button>
            </span>
        </div>
        <div id="qsRaiseCards"><?php echo $qs_raise_cards; ?></div>
        <div id="qsSummary" style="color:#666;font-size:13px;margin:10px 0 12px;"></div>
        <div id="qsBars"></div>
        <div id="qsCanvas" style="height:320px;display:none;"></div>
        <div id="qsList" style="display:none;margin-top:14px;border-top:1px solid #eef1f8;padding-top:10px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                <b id="qsListTitle" style="color:#333;"></b>
                <span style="display:inline-flex;align-items:center;gap:8px;">
                    <button type="button" class="hint-q" onclick="toggleHint(event, '<b>名单标签说明</b><br>· 标签=座号 + 姓名，<b>点击标签</b>查看该生历次答题变化<br>· 角点（仅白点）：<b>左下</b>=与上一题次选择不同；<b>右下</b>=与下一题次选择不同（相同或相邻次未答不显示）<br>· 悬停标签可查看上一题 / 下一题的具体选择')">?</button>
                    <a href="javascript:void(0)" onclick="document.getElementById('qsList').style.display='none'" style="color:#999;font-size:13px;">收起</a>
                </span>
            </div>
            <div id="qsListBody" style="max-height:38vh;overflow:auto;line-height:2;color:#444;"></div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-outline" onclick="closeQuizStats()">关闭</button>
        </div>
    </div>
</div>

<!-- 学生多次答题变化弹层（统计名单标签点击打开；叠于统计弹层之上） -->
<div class="modal-mask" id="stuTrendModal" style="z-index:1250;">
    <div class="modal" style="width:540px;max-width:94vw;">
        <h3 id="stTitle" style="margin:0 0 8px;"></h3>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px;">
            <button type="button" class="btn btn-sm" style="background:#8e44ad;color:#fff;" onclick="openStuRadar()" title="切换到答题统计的雷达视图，叠加显示该生正确率雷达（可再叠加其他学生）">🕸 雷达图</button>
            <span class="form-hint" style="margin:0;">点题次标签隐藏/还原数据
                <button type="button" class="hint-q" onclick="toggleHint(event, '<b>个人答题变化说明</b><br>· 折线图：纵轴=选项 A/B/C/D，圆点=该题次所选选项（悬停圆点看题次与选择）<br>· <b>点题次标签</b>：隐藏该次数据（标签变灰、上方圆点与连线同步隐藏），再点还原；正在查看的当前题次不能隐藏<br>· <b>🕸 雷达图</b>：跳转答题统计雷达视图并叠加该生正确率雷达（可再点其他学生姓名继续叠加）<br>· 摘要行=共几个题次、已答几个；隐藏不计入已答统计')">?</button>
            </span>
        </div>
        <div id="stSummary" style="color:#666;font-size:13px;margin-bottom:8px;"></div>
        <div id="stChart" style="overflow-x:auto;"></div>
        <div id="stSeq" style="margin-top:10px;line-height:2;"></div>
        <div class="modal-actions">
            <button type="button" class="btn btn-outline" onclick="document.getElementById('stuTrendModal').classList.remove('show')">关闭</button>
        </div>
    </div>
</div>

<!-- 答题卡成绩统计弹层（📊 汇总：平均分/优秀率/良好率/及格率/低分率/分段统计 + ECharts 分数段柱图/趋势折线/四象限散点 + 名单下钻 + 成绩报表导出；三视图：全班/分组/个人多人对比） -->
<script src="assets/js/echarts.min.js"></script><script src="assets/js/chart_common.js?v=3"></script>
<style>
.os-tab, .qs-xbtn { padding:4px 14px; border-radius:16px; border:1px solid #d0d4e8; background:#fff; cursor:pointer; font-size:13px; color:#555; }
.os-tab.on, .qs-xbtn.on { background:#667eea; border-color:#667eea; color:#fff; font-weight:bold; }
.grp-card { border:1px solid #e3e6f2; border-radius:10px; padding:10px 12px; cursor:pointer; background:#fff; }
.grp-card:hover { border-color:#667eea; box-shadow:0 2px 10px rgba(102,126,234,.15); }
.stu-tag { display:inline-block; margin:3px 6px 3px 0; padding:3px 12px; border-radius:14px; background:#f0f2f5; color:#555; font-size:13px; cursor:pointer; }
.stu-tag.on { background:#667eea; color:#fff; font-weight:bold; }
</style>
<div class="modal-mask" id="omrStatsModal" style="z-index:1200;">
    <div class="modal" style="width:780px;max-width:96vw;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
            <h3 style="margin:0;">📊 <span id="osTitleTxt">成绩统计</span> - <span id="osClass"></span>
                <button type="button" class="hint-q" onclick="toggleHint(event, '<b>成绩统计 / 答题统计说明</b><br>· <b>模式按钮</b>（亮起=当前统计，点击切换）：💯 成绩统计（分数段/等级构成/走势/四象限/对比，导出成绩报表）/ 📝 答题统计（按题：各题正确率柱图、单题选项分布饼图，点图查看名单）<br>· 顶部下拉=统计范围：全部题次汇总（Σ得分÷Σ满分）或单个题次（默认当前题次），两种模式下切换会重新取数<br>· 成绩统计·全班视图按钮排=统计维度切换（一页一图，便于大屏讲解）：📊 分数段（柱/饼可切换）/ 🥧 等级构成 / 📈 走势 / 🎯 四象限 / ☑ 对比<br>· 「🎯 四象限」=横轴平均得分率、纵轴首末次提升幅度，每个点=一名学生；「☑ 对比」=勾选多个题次对比各次全班平均得分率（柱 + 折线）<br>· 三视图：🏫 全班 / 👥 分组（各组均值卡片 + 组间对比图）/ 🧑 个人（可多选对比，含雷达叠加全班平均）——仅成绩统计模式<br>· 点柱子 / 扇区 / 拐点 / 气泡 = 查看对应名单；点名单中姓名 = 该生历次得分变化<br>· 「⬇ 导出」=成绩报表 Excel（学生成绩 / 一均三率及排名 / 学生等级 / 分数段统计）<br>· 登记页长按学生卡片可逐题修改答案；个人统计弹窗可查看题卡原图')">?</button>
            </h3>
            <span style="display:inline-flex;gap:6px;flex-wrap:wrap;margin-right:80px;">
                <button type="button" id="osModeBtn" class="os-tab on" onclick="osSetMode()" title="当前统计：成绩统计；点击切换到答题统计（按题正确率与选项分布）">💯 成绩统计</button>
                <select id="osRoundSel" class="form-control" style="width:auto;padding:5px 8px;font-size:13px;" onchange="osSwitchRound(this.value)" title="切换统计范围：全部题次汇总（Σ得分÷Σ满分）或单个题次"></select>
                <button type="button" class="btn btn-sm btn-outline" onclick="osExport()" title="导出成绩报表 Excel（学生成绩 / 一均三率及排名 / 学生等级 / 分数段统计）">⬇ 导出</button>
            </span>
        </div>
        <div id="osViewBar" style="display:flex;gap:6px;margin:10px 0 0;flex-wrap:wrap;">
            <button type="button" class="os-tab" id="osTabClass" onclick="osSetView('class')">🏫 全班</button>
            <button type="button" class="os-tab" id="osTabGroup" onclick="osSetView('group')">👥 分组</button>
            <button type="button" class="os-tab" id="osTabPerson" onclick="osSetView('person')">🧑 个人</button>
        </div>
        <div id="osDimBar" style="display:flex;gap:6px;margin:8px 0 0;flex-wrap:wrap;">
            <button type="button" class="os-tab" id="osDimSeg" onclick="osSetDim('seg')" title="每 10% 一段的人数分布（柱/饼可切换）">📊 分数段</button>
            <button type="button" class="os-tab" id="osDimGrade" onclick="osSetDim('grade')" title="优秀 / 良好 / 及格 / 不及格 / 未批改 占比">🥧 等级构成</button>
            <button type="button" class="os-tab" id="osDimTrend" onclick="osSetDim('trend')" title="各题次全班平均得分率">📈 走势</button>
            <button type="button" class="os-tab" id="osDimScatter" onclick="osSetDim('scatter')" title="横轴=平均得分率，纵轴=首末次提升幅度，每点=一名学生">🎯 四象限</button>
            <button type="button" class="os-tab" id="osDimCmp" onclick="osSetDim('cmp')" title="勾选多个题次，对比各次全班平均得分率（柱+折线）">☑ 对比</button>
            <button type="button" class="os-tab" id="osTypeBtn" onclick="osToggleType()" style="margin-left:auto;" title="切换柱形图 / 饼图">🥧 饼图</button>
        </div>
        <div id="osQBar" style="display:none;gap:6px;margin:8px 0 0;flex-wrap:wrap;align-items:center;">
            <button type="button" class="os-tab" id="osQRate" onclick="osSetQView('rate')" title="每题答对人数 ÷ 已答人数（点柱子看答错名单）">📊 各题正确率</button>
            <button type="button" class="os-tab" id="osQDist" onclick="osSetQView('dist')" title="单题各选项人数分布（点扇区看选该选项的名单）">🥧 选项分布</button>
            <select id="osQSel" class="form-control" style="display:none;width:auto;padding:5px 8px;font-size:13px;" onchange="osSetQNo(this.value)" title="选择要查看选项分布的题号"></select>
            <span style="flex:1;"></span>
            <button type="button" class="os-tab" id="osQTypeBtn" onclick="osToggleType()" title="切换柱形图 / 饼图">🥧 饼图</button>
        </div>
        <div id="osClassView">
            <div id="osSummary" style="color:#666;font-size:13px;margin:10px 0 8px;"></div>
            <div id="osRates" style="display:flex;gap:8px;flex-wrap:wrap;"></div>
            <div id="osCmpBox" style="display:none;"></div>
            <div id="osCanvas" style="height:360px;margin-top:8px;"></div>
        </div>
        <div id="osGroupView" style="display:none;"></div>
        <div id="osPersonView" style="display:none;"></div>
        <div id="osList" style="display:none;margin-top:14px;border-top:1px solid #eef1f8;padding-top:10px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                <b id="osListTitle" style="color:#333;"></b>
                <a href="javascript:void(0)" onclick="document.getElementById('osList').style.display='none'" style="color:#999;font-size:13px;">收起</a>
            </div>
            <div id="osListBody" style="max-height:34vh;overflow:auto;line-height:2;color:#444;"></div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-outline" onclick="closeOmrStats()">关闭</button>
        </div>
    </div>
</div>

<!-- 学生历次得分变化弹层（成绩统计名单点击打开；叠于统计弹层之上） -->
<div class="modal-mask" id="osStuModal" style="z-index:1250;">
    <div class="modal" style="width:560px;max-width:94vw;">
        <h3 id="osStuTitle" style="margin-bottom:6px;"></h3>
        <div id="osStuSummary" style="color:#666;font-size:13px;margin-bottom:8px;"></div>
        <div id="osStuChart" style="overflow-x:auto;"></div>
        <div id="osStuSeq" style="margin-top:10px;line-height:2;"></div>
        <div class="modal-actions">
            <button type="button" class="btn btn-outline" onclick="document.getElementById('osStuModal').classList.remove('show')">关闭</button>
        </div>
    </div>
</div>

<!-- 答题卡个人统计弹层（点击学生卡片打开）：得分卡 + 历次得分率折线（全班平均对比）+ 题组正确率雷达；底部 扫描/修改（录入）/查看题卡 -->
<div class="modal-mask" id="omrStuModal" style="z-index:1220;">
    <div class="modal" style="width:720px;max-width:95vw;">
        <h3 style="margin:0 0 8px;">🧑 <span id="osmName"></span> <span id="osmSub" style="font-size:13px;color:#888;font-weight:normal;"></span></h3>
        <div id="osmCards" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:6px;"></div>
        <div id="osmTrend" style="height:230px;"></div>
        <div id="osmRadar2Wrap" style="display:none;">
            <div style="margin:10px 0 2px;font-size:13px;font-weight:bold;color:#555;">🕸 得分率雷达 <span style="font-weight:normal;color:#999;">（个人 vs 全班平均，轴=总+各题次）</span></div>
            <div id="osmRadar2" style="height:260px;"></div>
        </div>
        <div id="osmRadarWrap" style="display:none;">
            <div style="margin:10px 0 2px;font-size:13px;font-weight:bold;color:#555;">🕸 题组正确率（<span id="osmSecRound"></span>）</div>
            <div id="osmRadar" style="height:260px;"></div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-warning" id="osmScanBtn" onclick="osmGoScan()">📷 扫描</button>
            <button type="button" class="btn" id="osmEditBtn" onclick="osmEditClick()">✏️ 修改</button>
            <button type="button" class="btn btn-outline" id="osmCardBtn" onclick="openOmrCard(OSM_SID)">🖼 查看题卡</button>
            <button type="button" class="btn btn-outline" onclick="osmClose()">关闭</button>
        </div>
    </div>
</div>

<!-- 答题卡逐题答案修改/录入弹层（点击弹窗「修改/录入」或长按学生卡片打开；长按默认锁定，点解锁后才能改并提交） -->
<div class="modal-mask" id="omrEditModal" style="z-index:1240;">
    <div class="modal" style="width:660px;max-width:95vw;">
        <h3 style="margin:0 0 6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            ✏️ <span id="oeTitle">修改答案</span> - <span id="oeName"></span>
            <span id="oeSub" style="font-size:13px;color:#888;font-weight:normal;"></span>
            <button type="button" id="oeLockBtn" class="btn btn-sm btn-outline" style="margin-left:auto;padding:2px 10px;" onclick="oeToggleLock()" title="锁定=仅查看答案；解锁后可修改并提交">🔒 已锁定</button>
        </h3>
        <div id="oeLockTip" style="display:none;font-size:12px;color:#e67e22;margin-bottom:4px;">已锁定：答案仅供查看，点击右上角「解锁」后才能修改并提交</div>
        <div id="oeBody" style="max-height:54vh;overflow:auto;"></div>
        <div id="oeScore" style="font-size:13px;color:#666;margin-top:8px;"></div>
        <div class="modal-actions">
            <button type="button" class="btn" id="oeSubmitBtn" onclick="oeSubmit()">提交修改</button>
            <button type="button" class="btn btn-outline" onclick="document.getElementById('omrEditModal').classList.remove('show')">取消</button>
        </div>
    </div>
</div>

<!-- 答题卡题卡图片弹层（查看该生批注题卡：服务器留存图 + 本机浏览器缓存图） -->
<div class="modal-mask" id="omrCardModal" style="z-index:1260;">
    <div class="modal" style="width:680px;max-width:94vw;">
        <h3 style="margin:0 0 8px;">🖼 查看题卡 - <span id="ocName"></span></h3>
        <div id="ocBody" style="max-height:62vh;overflow:auto;"></div>
        <div class="modal-actions">
            <button type="button" class="btn btn-outline" onclick="document.getElementById('omrCardModal').classList.remove('show')">关闭</button>
        </div>
    </div>
</div>

<?php if ($pv_can_ann): ?>
<?php
// 班级端反向喊话开关（教师端弹窗可改）：开启后本页轮询班级发来的语音/文字并播报
$rev_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT rev_announce FROM classes WHERE id = " . intval($current_class_id) . " AND deleted_at IS NULL"));
$rev_ann_on = $rev_row && intval($rev_row['rev_announce']) === 1;
// 呼叫队列项目上下文：登记状态映射（未登记/已登记分组、「未登记上一名/下一名」标签用），与格子状态同口径
$ann_reg_map = [];
foreach ($students as $as) {
    $asid = intval($as['id']);
    $areg = $daily_mode ? isset($today_map[$asid]) : ($as['registered'] == 1);
    if ($areg) $ann_reg_map[$asid] = 1;
}
$ann_is_project = true;
?>
<!-- 班级喊话弹窗（includes/announce_modal.php 共用：projects.php / project_view.php） -->
<?php $ann_class_id = $current_class_id; include __DIR__ . '/includes/announce_modal.php'; ?>
<?php endif; ?>
<?php if ($pv_can_pts): ?>
<!-- 课堂积分弹窗（includes/points_modal.php 共用：projects.php / project_view.php；仅查看成员只读，加减分/设规则操作由后端拒绝） -->
<?php
$pt_class_id = $current_class_id;
$pt_cls_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name FROM classes WHERE id = " . intval($current_class_id)));
$pt_class_name = $pt_cls_row ? strval($pt_cls_row['name']) : '';
// 积分弹窗筛选数据：按当前作业的登记/评价状态筛选学生（口径与瓦片格子一致：打卡模式=今日打卡/按天评价，其余=registered/eval_value）
$pt_reg_map = [];    // sid => 1 已登记
$pt_eval_map = [];   // sid => 评价内容（答题/举牌=所选选项 A/B/C/D）
foreach ($students as $ps) {
    $psid = intval($ps['id']);
    if ($daily_mode ? isset($today_map[$psid]) : ($ps['registered'] == 1)) $pt_reg_map[$psid] = 1;
    $pev = trim(strval($daily_mode ? ($day_evals[$psid] ?? '') : ($ps['eval_value'] ?? '')));
    if ($pev !== '') $pt_eval_map[$psid] = $pev;
}
$pt_eval_opts = [];  // 评价内容候选：评价模式预设选项 + 实际用到的值（筛框「评价为 ××」自动罗列）
foreach ((is_array($current_mode['options'] ?? null) ? $current_mode['options'] : []) as $po) {
    $po = trim(strval(is_array($po) ? ($po['label'] ?? ($po['value'] ?? '')) : $po));
    if ($po !== '' && !in_array($po, $pt_eval_opts, true)) $pt_eval_opts[] = $po;
}
foreach ($pt_eval_map as $pev) if (!in_array($pev, $pt_eval_opts, true)) $pt_eval_opts[] = $pev;
// 项目自动积分规则：页面加载即懒同步（指纹一致零开销跳过；规则/登记数据变化后自动重算），并把项目 id 传给积分弹窗（⚙设置本项目规则 + 流水🤖标注）
$pt_project_id = intval($project['id'] ?? 0);
if ($pt_project_id > 0 && !$view_only) auto_points_sync($conn, $project);   // 自动规则同步为写操作，仅查看成员跳过
$pt_readonly = $view_only;   // 仅查看成员：积分弹窗只读（仅流水/汇总可看，加减分/兑换/设置/记录不渲染）
$pt_cfg = $pv_round_admin;   // ⚙ 积分项/自动规则设置权限：仅管理员/可建立级别（题次管理同级判定）
include __DIR__ . '/includes/points_modal.php';
?>
<?php endif; ?>

<!-- 反向喊话横幅（班级客户端 → 教师端）：文字/语音播报文本，顶部展示，可手动关闭 -->
<style>
@keyframes revmarq { 0% { transform:translateX(0); } 100% { transform:translateX(-100%); } }
#revBanner { position:fixed; top:0; left:0; right:0; z-index:1400; display:none; align-items:center; background:#101418; color:#ffe600; border-bottom:2px solid #000; padding:10px 46px 10px 14px; overflow:hidden; }
#revBannerTxt { display:inline-block; white-space:pre-wrap; word-break:break-all; font-weight:bold; line-height:1.4; }
#revBannerTxt.marq { white-space:nowrap; padding-left:100vw; animation:revmarq 30s linear infinite; }
#revBannerClose { position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:0; color:#9aa7b5; font-size:18px; cursor:pointer; }
</style>
<div id="revBanner">
    <span id="revBannerTxt"></span>
    <button type="button" id="revBannerClose" onclick="revHideBanner()" title="关闭">✕</button>
</div>

<?php
// 教师点评（项目维度）：评价弹窗预填 / 保存后本地同步；家长公开查询「综合报告」中展示
$pv_comments = [];
$pv_cres = mysqli_query($conn, "SELECT student_id, content FROM student_comments WHERE project_id = " . intval($project_id));
if ($pv_cres) {
    while ($pv_crow = mysqli_fetch_assoc($pv_cres)) $pv_comments[intval($pv_crow['student_id'])] = strval($pv_crow['content']);
}
?>

<script>
var PROJECT_ID = <?php echo $project_id; ?>;
var EVAL_MODE = '<?php echo $project['eval_mode']; ?>';
var EVAL_OPTIONS = <?php echo $current_mode['options'] ? json_encode($current_mode['options'], JSON_UNESCAPED_UNICODE) : 'null'; ?>;
var currentEvalStudent = 0;
var evalTarget = 'single';          // single=单个学生  batch=批量评价内容设置
var COMMENTS = <?php echo json_encode($pv_comments ?: new stdClass(), JSON_UNESCAPED_UNICODE); ?>;   // 教师点评 map：学生id => 内容
var batchEvalValue = '';
var pendingRegStudent = 0;          // 登记+评价流程中待评价的学生
var VIEW_DATE = '<?php echo $view_date; ?>';   // 非空=历史日期只读视图
var VIEW_ONLY = <?php echo $view_only ? 'true' : 'false'; ?>; // 班级授权 view 级只读
var CAL_COUNTS = <?php echo json_encode($cal_counts ?: [], JSON_UNESCAPED_UNICODE); ?>; // 日期 => 打卡人数
var LOCK_SECONDS = <?php echo $lock_seconds; ?>;   // 登记后自动锁定秒数（0=不启用）
var LATE_SECONDS = <?php echo $late_seconds; ?>;   // 补登记阈值秒数
var FIRST_TS = <?php echo $first_ts ?: 0; ?>;      // 本班首位登记时间戳（补登记基准）
var DAILY_MODE = <?php echo $daily_mode ? 'true' : 'false'; ?>;
var CLASS_SIZE = <?php echo count($students); ?>;
var CAN_EDIT_HISTORY = <?php echo $can_edit_history ? 'true' : 'false'; ?>; // 历史/过期编辑权限（后台「过期作业项目可编辑」或管理员）
var GROUPS = <?php echo json_encode($groups ?: new stdClass(), JSON_UNESCAPED_UNICODE); ?>;   // 分组id => 名称（分组显示用）
var HAS_GROUPS = <?php echo !empty($groups) ? 'true' : 'false'; ?>;   // 班级是否已有分组信息（决定「进行分组 / 调整分组」按钮文案）
var IS_QUIZ = <?php echo $is_abcd ? 'true' : 'false'; ?>;        // 答题/举牌模式（四选一 A/B/C/D 规则共用）
var IS_RAISE = <?php echo $is_raise ? 'true' : 'false'; ?>;      // 举牌模式（点击卡片=查看答题变化；长按=修改/登记答题）
var IS_OMR = <?php echo $is_omr ? 'true' : 'false'; ?>;          // 答题卡模式（点击=个人统计弹窗；长按=逐题答案修改）
var ROUNDED = <?php echo $rounded_mode ? 'true' : 'false'; ?>;   // 轮次模式（多次登记/答题，apiCall 自动带 round）
var CURRENT_ROUND = <?php echo intval($current_round); ?>;       // 当前轮次序号（项次/题次）
var ROUND_LABEL = '<?php echo $round_label; ?>';                 // 轮次称谓：项次 / 题次
var TILE_DOTS = <?php echo json_encode($tie_dots ?? [], JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT); ?>;   // 卡片角点数据：sid => {cur,prev,next}（本题未答不出现；答题/举牌模式专用）
var TILE_CORRECT = <?php echo json_encode($tie_correct[$current_round] ?? [], JSON_UNESCAPED_UNICODE); ?>; // 本题（当前题次）正确答案：绿点判定用
var ROUNDS = <?php echo json_encode($rounds, JSON_UNESCAPED_UNICODE); ?>;   // 全部轮次序号（多端增删轮次时无刷新重建胶囊）
var PV_ROUND_ADMIN = <?php echo $pv_round_admin ? 'true' : 'false'; ?>;   // 题次管理权限（管理员/可建立）：控制「＋」新增按钮
var ROUND_TITLES = <?php echo json_encode($round_titles ?? [], JSON_UNESCAPED_UNICODE); ?>;   // 轮次自定义标题 {序号:标题}（空=胶囊显示序号）
var PV_CLASS_ID = <?php echo intval($current_class_id); ?>;      // 当前查看的班级（无刷新同步按此取数）
// ===== 座位模式（座位布局在「进行分组 / 调整分组」弹窗或学生名单页「设置分组」中配置） =====
var SEAT_COLS = Math.max(1, <?php echo intval($class_info['seat_cols'] ?? 2); ?>);        // 每组列数
var SEAT_GROUPS = Math.max(1, <?php echo intval($class_info['seat_groups'] ?? 4); ?>);    // 分组数
var SEAT_GROUP_NAMES = <?php echo json_encode($seat_group_names ?: new stdClass(), JSON_UNESCAPED_UNICODE); ?>;   // sort(1起) => 座位组名（组名跟组走：拖拽整组调序后按此呈现）
var SEAT_AUTO = <?php echo intval($class_info['seat_auto_group'] ?? 0) ? 'true' : 'false'; ?>; // 自动按座位分组（分组显示按座位列分组）
var CLASS_KEY = <?php echo intval($current_class_id); ?>;   // 视图设置按班级记忆（缓存键 qj_pv_c{班级id}）
var histEdit = false;               // 历史日期临时解锁（本次浏览内有效）
var lockMode = 'auto';              // auto=按登记时间自动锁定 all=临时全部锁定 none=临时全部解锁

// ===== 锁定 =====
function autoLocked(tile) {
    if (LOCK_SECONDS <= 0) return false;
    var ts = parseInt(tile.dataset.regts || '0', 10);
    return ts > 0 && (Date.now() / 1000 - ts) > LOCK_SECONDS;
}
function effectiveLocked(tile) {
    if (VIEW_DATE || VIEW_ONLY) return false;
    if (lockMode === 'all') return true;
    if (lockMode === 'none') return false;
    return autoLocked(tile);
}
function allLockedNow() {
    if (LOCK_SECONDS <= 0) return false;
    var tiles = document.querySelectorAll('.tile');
    if (!tiles.length) return false;
    for (var i = 0; i < tiles.length; i++) {
        if (tiles[i].dataset.state !== 'yes' || !autoLocked(tiles[i])) return false;
    }
    return true;
}
function updateLockLabel() {
    var sw = document.getElementById('swLock');
    var lb = document.getElementById('lockLabel');
    if (!sw || !lb) return;
    if (sw.checked) { lb.textContent = '🔒 已锁定'; lb.style.color = '#27ae60'; }
    else { lb.textContent = '🔓 未锁定'; lb.style.color = '#999'; }
}
function onLockToggle() {
    var sw = document.getElementById('swLock');
    lockMode = sw.checked ? 'all' : 'none';
    updateLockLabel();
    refreshLockVisual();
}
function refreshLockVisual() {
    document.querySelectorAll('.tile').forEach(function (tile) {
        tile.classList.toggle('locked', effectiveLocked(tile));
    });
    // auto 模式下随时间推移同步开关显示
    var sw = document.getElementById('swLock');
    if (sw && lockMode === 'auto') { sw.checked = allLockedNow(); updateLockLabel(); }
}
setInterval(refreshLockVisual, 30000);

// ===== 全屏 =====
var fsFallback = false;
function setFsBtn(on) {
    var btn = document.getElementById('fsBtn');
    if (btn) btn.textContent = on ? '⛶ 退出全屏' : '⛶ 全屏';
}
function toggleFullscreen() {
    var el = document.getElementById('mainPanel');
    if (!el) return;
    var fsEl = document.fullscreenElement || document.webkitFullscreenElement;
    if (fsEl) { (document.exitFullscreen || document.webkitExitFullscreen).call(document); return; }
    if (fsFallback) { el.classList.remove('fs-fallback'); fsFallback = false; setFsBtn(false); return; }
    if (el.requestFullscreen) { el.requestFullscreen(); }
    else if (el.webkitRequestFullscreen) { el.webkitRequestFullscreen(); }
    else { el.classList.add('fs-fallback'); fsFallback = true; setFsBtn(true); }
}
function onFsChange() {
    var on = !!(document.fullscreenElement || document.webkitFullscreenElement);
    if (!on) {
        var el = document.getElementById('mainPanel');
        if (el) el.classList.remove('fs-fallback');
        fsFallback = false;
    }
    // 全屏（Fullscreen API 只渲染全屏元素子树）：进入时把弹层移入全屏元素，退出时移回 body，
    // 否则评价等弹窗在 DOM 上位于全屏元素之外，会被整个"挡掉"不显示
    var fsEl = document.fullscreenElement || document.webkitFullscreenElement;
    var host = fsEl || document.body;
    document.querySelectorAll('.modal-mask').forEach(function (m) {
        if (m.parentElement !== host) host.appendChild(m);
    });
    // 反向喊话横幅同样移入全屏元素，保证全屏时可见
    var rb = document.getElementById('revBanner');
    if (rb && rb.parentElement !== host) host.appendChild(rb);
    setFsBtn(on);
    setTimeout(applyPerRow, 100);
}
document.addEventListener('fullscreenchange', onFsChange);
document.addEventListener('webkitfullscreenchange', onFsChange);

// ===== 班级端反向喊话接收（班级客户端 → 教师端）：轮询取消息，语音播报 / 顶部横幅展示 =====
var REV_ANN = <?php echo !empty($rev_ann_on) ? 'true' : 'false'; ?>;
var revPollT = null, revHideT = null;
var revSpeakQ = [], revSpeaking = false;
var revBanner = document.getElementById('revBanner');
var revBannerTxt = document.getElementById('revBannerTxt');

function revShowBanner(content, fontSize, marquee, autoHideMs) {
    revBannerTxt.textContent = content;
    revBannerTxt.style.fontSize = fontSize + 'px';
    revBannerTxt.className = marquee ? 'marq' : '';
    if (marquee) revBannerTxt.style.animationDuration = Math.max(10, content.length * 0.5) + 's';
    revBanner.style.display = 'flex';
    clearTimeout(revHideT);
    if (autoHideMs > 0) revHideT = setTimeout(revHideBanner, autoHideMs);
}
function revHideBanner() { revBanner.style.display = 'none'; clearTimeout(revHideT); }

// 语音顺序播报（不重叠）；播报中横幅同步显示文本，播完数秒后收起
function revPump() {
    if (revSpeaking || !revSpeakQ.length) return;
    if (!('speechSynthesis' in window)) { revSpeakQ = []; return; }
    revSpeaking = true;
    var m = revSpeakQ.shift();
    revShowBanner(m.content, Math.min(m.font_size, 36), false, 0);
    var n = 0;
    function once() {
        var u = new SpeechSynthesisUtterance(m.content);
        u.rate = m.speed; u.pitch = m.pitch; u.lang = 'zh-CN';
        u.onend = function () {
            n++;
            if (n < m.times) setTimeout(once, 500);
            else { revSpeaking = false; revHideT = setTimeout(function () { if (!revSpeaking) revHideBanner(); }, 6000); revPump(); }
        };
        u.onerror = function () { revSpeaking = false; revHideBanner(); revPump(); };
        speechSynthesis.speak(u);
    }
    once();
}

function revPlay(m) {
    if (window.revNotifyShow) revNotifyShow(m, 'dialog');   // 弹窗提醒：点「✓ 确认」关闭，不点击 5 秒自动关闭
    if (m.mtype === 'voice') { revSpeakQ.push(m); revPump(); }
    else revShowBanner(m.content, m.font_size, m.marquee, m.marquee ? 45000 : 20000);
}
function revPoll() {
    fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'type=announce_rev_poll&class_id=' + PV_CLASS_ID
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (!d || !d.success) { REV_ANN = false; revPollT = null; return; }   // 无权限等：停止轮询
        (d.msgs || []).forEach(revPlay);
    })
    .catch(function () {})
    .then(function () { if (REV_ANN) revPollT = setTimeout(revPoll, 5000); });
}
if (REV_ANN) revPoll();
// 喊话弹窗保存反向喊话设置后启停轮询（includes/announce_modal.php 回调）
function annRevChanged(on) {
    REV_ANN = on;
    if (on && !revPollT) revPoll();
    if (!on && revPollT) { clearTimeout(revPollT); revPollT = null; revHideBanner(); }
}

// ===== 教师在线心跳（每 10 秒）：大屏客户端待机屏显示「🟢 教师在线 / ⚪ 教师不在线」 =====
<?php if (!$view_only): ?>
function annTping() {
    fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'type=announce_tping&class_id=' + PV_CLASS_ID }).catch(function () {});
}
annTping();
setInterval(annTping, 10000);
<?php endif; ?>

// ===== 大屏模式：亮灯（默认）/ 消消乐 / 搬搬乐 / 座位 =====
var SCREEN_MODES = ['light', 'elim', 'move', 'seat'];
var SCREEN_MODE_NAMES = { light: '亮灯模式', elim: '消消乐', move: '搬搬乐', seat: '座位模式' };
var screenMode = 'light';
// 记录格子原始顺序（搬搬乐移回左列时恢复座号顺序）
(function () {
    document.querySelectorAll('#tileGrid > .tile').forEach(function (t, i) { t.dataset.origIdx = i; });
})();
// ===== 按班级记忆的本机视图设置（一行N个 / 大屏模式 / 格子形状）：qj_pv_c{班级id} = {per, mode, shape} =====
function loadClassPrefs() {
    try { return JSON.parse(localStorage.getItem('qj_pv_c' + CLASS_KEY) || '{}') || {}; } catch (e) { return {}; }
}
function saveClassPrefs() {
    try {
        var el = document.getElementById('perRow');
        localStorage.setItem('qj_pv_c' + CLASS_KEY, JSON.stringify({
            per: el ? el.value : (savedPerRow() || defaultPerRow()),
            mode: screenMode,
            shape: tileShape
        }));
    } catch (e) {}
}
// ===== 格子形状：方形（默认）/ 圆形 / 气泡（静态）/ 动气泡（带浮动动效） =====
var SHAPES = ['square', 'round', 'bubble', 'bubbleanim'];
var SHAPE_NAMES = { square: '⬜ 方形', round: '⚪ 圆形', bubble: '💬 气泡', bubbleanim: '🫧 动气泡' };
var tileShape = 'square';
function applyShape(s, save) {
    tileShape = SHAPES.indexOf(s) > -1 ? s : 'square';
    document.body.classList.remove('shape-round', 'shape-bubble', 'shape-bubble-anim');
    if (tileShape === 'round') document.body.classList.add('shape-round');
    if (tileShape === 'bubble') document.body.classList.add('shape-bubble');
    if (tileShape === 'bubbleanim') { document.body.classList.add('shape-bubble'); document.body.classList.add('shape-bubble-anim'); }
    var b = document.getElementById('shapeBtn');
    if (b) {
        b.textContent = SHAPE_NAMES[tileShape];
        b.classList.toggle('btn-outline', tileShape === 'square');
    }
    if (save) saveClassPrefs();
}
function cycleShape() { applyShape(SHAPES[(SHAPES.indexOf(tileShape) + 1) % SHAPES.length], true); }
function savedScreenMode() {
    var p = loadClassPrefs();
    if (p.mode && SCREEN_MODES.indexOf(p.mode) > -1) return p.mode;
    try { return localStorage.getItem('qj_screen_mode'); } catch (e) { return null; }   // 兼容旧的全局设置
}
function updateModeBtn() {
    var btn = document.getElementById('modeBtn');
    if (btn) btn.textContent = '🎮 ' + SCREEN_MODE_NAMES[screenMode];
}
function updateModeVisuals() {
    if (screenMode === 'elim') { applyElimView(); return; }
    updateElimDone();
    updateMoveCounts();
}
// 消消乐视图：no=只看未登记（默认） yes=只看已登记（登记瞬间播放消失动画，显隐统一由 applyElimView 控制）
var elimView = 'no';
function toggleElimView() {
    elimView = (elimView === 'no') ? 'yes' : 'no';
    applyElimView();
}
function applyElimView() {
    if (screenMode !== 'elim') return;
    var regCnt = 0, noCnt = 0;
    document.querySelectorAll('.tile').forEach(function (t) {
        var reg = t.dataset.state === 'yes';
        if (reg) regCnt++; else noCnt++;
        if (t.classList.contains('bye')) return;   // 消失动画中不动
        t.style.display = (elimView === 'no' ? !reg : reg) ? '' : 'none';
    });
    var btn = document.getElementById('elimViewBtn');
    if (btn) btn.textContent = elimView === 'no'
        ? '👁 未登记 ' + noCnt + ' 人（点击切换→已登记）'
        : '👁 已登记 ' + regCnt + ' 人（点击切换→未登记）';
    updateElimDone();
}
// 消消乐：登记成功后播放消失动画再按当前视图显隐；搬搬乐：按登记状态归入左/右列（instant=进入模式时直接就位）
function applyModeToTile(tile, instant) {
    if (!tile) return;
    var reg = tile.dataset.state === 'yes';
    if (screenMode === 'elim') {
        if (reg && !instant && !tile.classList.contains('bye')) {
            tile.classList.add('bye');
            setTimeout(function () {
                tile.classList.remove('bye');
                if (screenMode !== 'elim') return;   // 动画期间切换了模式：交给新模式处理
                applyElimView();
            }, 380);
        } else if (!reg) {
            applyElimView();   // 取消后：已登记视图立即隐藏 / 未登记视图重新出现
        }
    } else if (screenMode === 'move') {
        if (groupView) { updateMoveCounts(); return; }   // 分组显示中：不搬列（格子留在分组面板内），仅刷新计数
        var right = document.getElementById('moveRight');
        var left = document.getElementById('moveLeft');
        if (!right || !left) return;
        var target = reg ? right : left;
        if (tile.parentNode !== target) {
            if (!instant && reg) {
                tile.classList.add('just-moved');
                setTimeout(function () { tile.classList.remove('just-moved'); }, 900);
            }
            target.appendChild(tile);
            if (!reg) sortMoveLeft();
        }
    }
}
// 搬搬乐左列按原始顺序（座号）排列
function sortMoveLeft() {
    var left = document.getElementById('moveLeft');
    if (!left) return;
    Array.prototype.slice.call(left.querySelectorAll('.tile'))
        .sort(function (a, b) { return parseInt(a.dataset.origIdx || 0, 10) - parseInt(b.dataset.origIdx || 0, 10); })
        .forEach(function (t) { left.appendChild(t); });
}
// 退出搬搬乐：全部格子搬回主网格并恢复原顺序
function restoreMoveLayout() {
    var grid = document.getElementById('tileGrid');
    var board = document.getElementById('moveBoard');
    if (!grid || !board) return;
    board.style.display = 'none';
    grid.style.display = 'grid';
    Array.prototype.slice.call(document.querySelectorAll('.tile'))
        .sort(function (a, b) { return parseInt(a.dataset.origIdx || 0, 10) - parseInt(b.dataset.origIdx || 0, 10); })
        .forEach(function (t) {
            t.classList.remove('just-moved');
            grid.appendChild(t);
        });
}
function updateElimDone() {
    var done = document.getElementById('elimDone');
    if (!done) return;
    var tiles = document.querySelectorAll('.tile');
    var total = tiles.length, left = 0;
    tiles.forEach(function (t) {
        if (t.classList.contains('bye') || t.dataset.state !== 'yes') left++;
    });
    done.style.display = (screenMode === 'elim' && total > 0 && left === 0) ? '' : 'none';
}
function updateMoveCounts() {
    if (screenMode !== 'move') return;
    var l = 0, r = 0;
    document.querySelectorAll('.tile').forEach(function (t) {
        if (t.dataset.state === 'yes') r++; else l++;
    });
    var lc = document.getElementById('moveLeftCnt'), rc = document.getElementById('moveRightCnt');
    if (lc) lc.textContent = l;
    if (rc) rc.textContent = r;
}
function setScreenMode(m, keepSaved) {
    screenMode = SCREEN_MODES.indexOf(m) > -1 ? m : 'light';
    if (!keepSaved) saveClassPrefs();
    var grid = document.getElementById('tileGrid');
    var board = document.getElementById('moveBoard');
    var seatB = document.getElementById('seatBoard');
    var gboard = document.getElementById('groupBoard');
    if (!grid) return;
    restoreMoveLayout();               // 若在搬搬乐：先全部搬回主网格（document 范围收集，含分组/座位容器内的格子）
    // 座位模式子视图复位：清空座位容器（格子已在上方搬回主网格，剩余仅为空位占位壳）
    if (seatB) { seatB.style.display = 'none'; seatB.innerHTML = ''; }
    seatUnShown = false;
    // 清空筛选（消消乐/搬搬乐由模式控制显隐，避免筛选残留）
    currentShow = 'all';
    var sb = document.getElementById('stuKwBox');
    if (sb) sb.value = '';
    document.body.classList.remove('mode-elim', 'mode-move', 'mode-seat');
    if (screenMode === 'elim') document.body.classList.add('mode-elim');
    if (screenMode === 'move') document.body.classList.add('mode-move');
    if (screenMode === 'seat') document.body.classList.add('mode-seat');
    if (screenMode === 'move') {
        grid.style.display = 'none';
        board.style.display = 'flex';
    }
    if (screenMode === 'seat') {
        // 座位模式自成一派：退出「分组显示」（座位分组由「进行分组」承担），隐藏主网格
        grid.style.display = 'none';
        if (gboard) gboard.style.display = 'none';
        if (groupView) { groupView = false; updateGrpBtns(); }
    }
    document.querySelectorAll('.tile').forEach(function (t) {
        t.classList.remove('bye');
        t.style.display = '';
        if (screenMode === 'move') applyModeToTile(t, true);
    });
    var elimBar = document.getElementById('elimBar');
    if (elimBar) elimBar.style.display = (screenMode === 'elim') ? '' : 'none';
    if (screenMode === 'elim') applyElimView();
    if (screenMode === 'seat') {
        if (seatB) { seatB.style.display = ''; renderSeatBoard(); }
    }
    if (groupView) applyGroupView();   // 分组显示跨模式：切模式后按新模式重排分组
    updateModeBtn();
    updateModeVisuals();
}
function cycleScreenMode() {
    var i = SCREEN_MODES.indexOf(screenMode);
    setScreenMode(SCREEN_MODES[(i + 1) % SCREEN_MODES.length]);
}
// 载入上次使用的视图设置（仅页面提供切换按钮时：今日可操作视图）
// 答题卡/举牌项目：无论缓存是什么大屏模式，进入页面一律强制亮灯模式（忽略恢复，仍可手动切换）
var FORCE_LIGHT = <?php echo ($is_omr || $is_raise) ? 'true' : 'false'; ?>;
(function () {
    var btn = document.getElementById('modeBtn');
    var sm = savedScreenMode();
    if (!FORCE_LIGHT && btn && sm && SCREEN_MODES.indexOf(sm) > -1) setScreenMode(sm, true);
    var sb = document.getElementById('shapeBtn');
    if (sb) applyShape(loadClassPrefs().shape || 'square', false);   // 恢复格子形状（方形不视为用户自选）
})();

// ===== 分组显示（亮灯/消消乐/搬搬乐均可用）：横向=各组并排（内部受「一行N个」控制）／纵向=各组堆叠名单式（不受控制） =====
var groupView = false;
var groupDir = 'h';
try { groupDir = localStorage.getItem('qj_group_dir') === 'v' ? 'v' : 'h'; } catch (e) {}
function escHtml(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function updateGrpBtns() {
    var b = document.getElementById('grpBtn');
    var d = document.getElementById('grpDirBtn');
    if (b) b.classList.toggle('btn-outline', !groupView);
    if (d) {
        d.style.display = groupView ? '' : 'none';
        d.textContent = (groupDir === 'h') ? '⬌ 横向' : '⬍ 纵向';
    }
}
function toggleGroupView() {
    if (VIEW_DATE || VIEW_ONLY) { showToast('当前视图不支持分组显示', 'warn'); return; }
    groupView = !groupView;
    applyGroupView();
}
function toggleGroupDir() {
    groupDir = (groupDir === 'h') ? 'v' : 'h';
    try { localStorage.setItem('qj_group_dir', groupDir); } catch (e) {}
    updateGrpBtns();
    if (groupView) renderGroupBoard();
}
function applyGroupView() {
    var grid = document.getElementById('tileGrid');
    var board = document.getElementById('groupBoard');
    var mboard = document.getElementById('moveBoard');
    var seatB = document.getElementById('seatBoard');
    if (!grid || !board) return;
    updateGrpBtns();
    if (!groupView) { restoreGroupLayout(); return; }
    grid.style.display = 'none';
    if (mboard) mboard.style.display = 'none';   // 搬搬乐：连同左右两列一起隐藏
    board.style.display = '';
    renderGroupBoard();   // 收集含座位容器在内的全部格子
    if (seatB) { seatB.style.display = 'none'; seatB.innerHTML = ''; }   // 清理座位容器剩余的空位占位壳
}
// 退出分组显示：格子按原始顺序（座号）搬回主网格，再按当前模式恢复布局（搬搬乐回左右列 / 消消乐重算显隐 / 座位模式重排座位）
// 关键：先把格子全部搬回主网格，再清空分组容器（若先 innerHTML='' 会连同格子一起销毁，导致"该班级暂无学生"）
function restoreGroupLayout() {
    var grid = document.getElementById('tileGrid');
    var board = document.getElementById('groupBoard');
    var mboard = document.getElementById('moveBoard');
    var seatB = document.getElementById('seatBoard');
    if (!grid || !board) return;
    var tiles = collectTiles();
    tiles.sort(function (a, b) { return parseInt(a.dataset.origIdx || 0, 10) - parseInt(b.dataset.origIdx || 0, 10); });
    tiles.forEach(function (t) {
        t.classList.remove('just-moved');
        grid.appendChild(t);
    });
    board.style.display = 'none';
    board.innerHTML = '';
    if (seatB) { seatB.style.display = 'none'; seatB.innerHTML = ''; }
    if (screenMode === 'seat') { seatB.style.display = ''; renderSeatBoard(); return; }   // 座位模式中退出分组显示：重排座位
    if (screenMode === 'move') {
        grid.style.display = 'none';
        if (mboard) mboard.style.display = 'flex';
        tiles.forEach(function (t) { applyModeToTile(t, true); });   // 按登记状态归入左/右列
        sortMoveLeft();
        updateMoveCounts();
    } else {
        grid.style.display = 'grid';
        if (screenMode === 'elim') applyElimView();   // 消消乐：按当前视图（未登记/已登记）重算显隐
    }
}
function currentPerRow() {
    var v = parseInt(document.getElementById('perRow') ? document.getElementById('perRow').value : (savedPerRow() || defaultPerRow()), 10);
    return (v >= 1 && v <= 10) ? v : 5;
}
// 收集各容器的格子：主网格 / 搬搬乐左右两列 / 分组容器 / 座位容器（避免误抓其他容器元素）
function collectTiles() {
    var pool = [];
    var grid = document.getElementById('tileGrid');
    if (grid) grid.querySelectorAll(':scope > .tile').forEach(function (t) { pool.push(t); });
    ['moveBoard', 'groupBoard', 'seatBoard'].forEach(function (id) {
        var c = document.getElementById(id);
        if (c) c.querySelectorAll('.tile').forEach(function (t) { pool.push(t); });
    });
    return pool;
}
function renderGroupBoard() {
    var board = document.getElementById('groupBoard');
    var grid = document.getElementById('tileGrid');
    if (!board) return;
    var buckets = {}, order = [];
    var pool = collectTiles();
    // 「自动按座位分组」开启且已有学生入座：按座位列自动分组（未入座学生排最后），不使用手动分组
    var span = SEAT_GROUPS * SEAT_COLS;
    var seatMode = SEAT_AUTO && pool.some(function (t) { return parseInt(t.dataset.seat || '0', 10) > 0; });
    pool.forEach(function (t) {
        var g;
        if (seatMode) {
            var p = parseInt(t.dataset.seat || '0', 10);
            g = p > 0 ? 's' + (Math.floor(((p - 1) % span) / SEAT_COLS) + 1) : '0';
        } else {
            g = t.dataset.gid || '0';
        }
        if (!buckets[g]) { buckets[g] = []; order.push(g); }
        buckets[g].push(t);
    });
    // 分组顺序：自动分组=第1..N组、未分组排最后；手动分组=按分组定义顺序，未分组（0）排最后
    var gids = [];
    if (seatMode) {
        for (var i = 1; i <= SEAT_GROUPS; i++) { if (buckets['s' + i]) gids.push('s' + i); }
        if (buckets['0']) gids.push('0');
    } else {
        gids = Object.keys(GROUPS).filter(function (gid) { return buckets[gid]; });
        if (buckets['0']) gids.push('0');
    }
    order.forEach(function (g) { if (gids.indexOf(g) === -1) gids.push(g); });
    board.className = (groupDir === 'v') ? 'grp-board-v' : 'grp-board-h';
    board.innerHTML = '';
    if (!gids.length) {
        board.innerHTML = '<div style="text-align:center;color:#999;padding:20px;">该班级暂无学生</div>';
        return;
    }
    gids.forEach(function (gid) {
        var si = parseInt(gid.slice(1), 10);
        var name = seatMode
            ? (gid === '0' ? '未分组' : ((SEAT_GROUP_NAMES && SEAT_GROUP_NAMES[si]) || ('第' + si + '组')))
            : (GROUPS[gid] || (gid === '0' ? '未分组' : '分组#' + gid));
        var panel = document.createElement('div');
        panel.className = 'grp-panel';
        panel.dataset.gid = gid;
        var head = document.createElement('div');
        head.className = 'grp-head';
        head.innerHTML = '<span class="grp-name">' + escHtml(name) + '（' + buckets[gid].length + '人）</span>'
            + '<span class="grp-ops"><a href="javascript:void(0)" title="合一开关：未全选=全选该组；已全选=全不选" onclick="toggleGroupSel(\'' + gid + '\')">' + (tilesAllSelected(buckets[gid]) ? '全不选' : '全选该组') + '</a></span>';
        var box = document.createElement('div');
        box.className = 'grp-tiles';
        if (groupDir === 'h') box.style.gridTemplateColumns = 'repeat(' + currentPerRow() + ', minmax(0,1fr))';
        buckets[gid].forEach(function (t) { box.appendChild(t); });
        panel.appendChild(head);
        panel.appendChild(box);
        board.appendChild(panel);
    });
}
// 全选 / 全不选该组（配合「登记已选 / 评价已选」批量操作）
function selectGroup(gid, on) {
    var panel = document.querySelector('#groupBoard .grp-panel[data-gid="' + gid + '"]');
    if (!panel) return;
    panel.querySelectorAll('.tile').forEach(function (t) {
        var cb = t.querySelector('.tile-check');
        if (cb) { cb.checked = on; t.classList.toggle('checked', on); }
    });
    updateGroupSelBtns();
    updateSelAllBtn();
}
// 一组格子的勾选状态：全部已勾选（且至少 1 个）= true
function tilesAllSelected(list) {
    var n = 0, sel = 0;
    list.forEach(function (t) {
        var cb = t.querySelector('.tile-check');
        if (!cb) return;
        n++;
        if (cb.checked) sel++;
    });
    return n > 0 && sel === n;
}
// 分组显示每组标题旁「全选该组 / 全不选」合一开关
function toggleGroupSel(gid) {
    var panel = document.querySelector('#groupBoard .grp-panel[data-gid="' + gid + '"]');
    if (!panel) return;
    selectGroup(gid, !tilesAllSelected(Array.prototype.slice.call(panel.querySelectorAll('.tile'))));
}
// 按当前勾选状态刷新各组「全选该组 / 全不选」文案
function updateGroupSelBtns() {
    document.querySelectorAll('#groupBoard .grp-panel').forEach(function (panel) {
        var a = panel.querySelector('.grp-ops a');
        if (a) a.textContent = tilesAllSelected(Array.prototype.slice.call(panel.querySelectorAll('.tile'))) ? '全不选' : '全选该组';
    });
}

// ===== 座位模式（「进行分组 / 调整分组」弹窗配置布局；讲台在上方，下方分组 × 列呈现座位，空位占位） =====
var seatUnShown = false;   // 「查看未分组(n)人」展开状态（未分组=未入座学生）
function renderSeatBoard() {
    var board = document.getElementById('seatBoard');
    if (!board) return;
    var tiles = collectTiles();
    var seatMap = {}, unseated = [];
    tiles.forEach(function (t) {
        var p = parseInt(t.dataset.seat || '0', 10);
        if (p > 0 && !seatMap[p]) seatMap[p] = t; else unseated.push(t);
    });
    var G = SEAT_GROUPS, C = SEAT_COLS, span = G * C;
    var maxSeat = 0;
    Object.keys(seatMap).forEach(function (p) { maxSeat = Math.max(maxSeat, parseInt(p, 10)); });
    var R = Math.max(1, Math.ceil(maxSeat / span));   // 行数随入座情况自动扩展；至少 1 行（含全部空位）
    board.innerHTML = '';

    // 「查看未分组(n)人 ／ 进行分组 / 调整分组」：与「一行N个序号」同行（toolBar1）；只读视图无工具栏时退化为面板内小按钮行
    var unBtn = document.getElementById('seatUnBtn');
    var fillBtn = document.getElementById('seatFillBtn');
    if (unBtn && fillBtn) {
        unBtn.textContent = '查看未分组（' + unseated.length + '）人';
        unBtn.classList.toggle('btn-outline', !seatUnShown);
        fillBtn.textContent = HAS_GROUPS ? '调整分组' : '进行分组';
    } else {
        var bar = document.createElement('div');
        bar.className = 'seat-bar';
        var ub = document.createElement('button');
        ub.type = 'button';
        ub.className = 'btn btn-sm' + (seatUnShown ? '' : ' btn-outline');
        ub.textContent = '查看未分组（' + unseated.length + '）人';
        ub.onclick = toggleSeatUn;
        bar.appendChild(ub);
        var fb = document.createElement('button');
        fb.type = 'button';
        fb.className = 'btn btn-sm btn-warning';
        fb.textContent = HAS_GROUPS ? '调整分组' : '进行分组';
        fb.title = '在弹窗中拖拽 / 点选为学生入座，保存后按座位自动生成分组（未入座学生为未分组）';
        fb.onclick = openSeatGroupModal;
        bar.appendChild(fb);
        board.appendChild(bar);
    }

    // 讲台
    var pod = document.createElement('div');
    pod.className = 'seat-podium';
    pod.textContent = '讲 台';
    board.appendChild(pod);

    // 座位网格：每组一面板（C 列 × R 行）；位置 p = 行*组数*列数 + 组号*列数 + 列号 + 1（一排排向右）
    var wrap = document.createElement('div');
    wrap.className = 'seat-groups';
    for (var g = 0; g < G; g++) {
        var panel = document.createElement('div');
        panel.className = 'seat-panel';
        panel.dataset.g = g;   // 「全选该组」合一开关按 data-g 定位本组面板
        panel.appendChild(panelHead(g, seatMap, R, C, span));
        var box = document.createElement('div');
        box.className = 'seat-grid';
        box.style.gridTemplateColumns = 'repeat(' + C + ', minmax(0,1fr))';
        for (var r = 0; r < R; r++) {
            for (var c = 0; c < C; c++) {
                var pos = r * span + g * C + c + 1;
                var t = seatMap[pos];
                if (t) box.appendChild(t);
                else {
                    var ph = document.createElement('div');
                    ph.className = 'seat-empty';
                    ph.innerHTML = '<span>空座</span>';
                    ph.title = '空座位 #' + pos;
                    box.appendChild(ph);
                }
            }
        }
        panel.appendChild(box);
        wrap.appendChild(panel);
    }
    board.appendChild(wrap);

    // 未分组（未入座）学生池：默认收起，「查看未分组(n)人」展开；格子可正常登记 / 评价 / 批量勾选
    var up = document.createElement('div');
    up.className = 'seat-unpool';
    up.style.display = (seatUnShown && unseated.length) ? '' : 'none';
    var uh = document.createElement('div');
    uh.className = 'seat-unpool-head';
    uh.textContent = '未分组学生（' + unseated.length + '人）— 点「' + (HAS_GROUPS ? '调整分组' : '进行分组') + '」在弹窗中安排座位，保存后分组随座位生成';
    up.appendChild(uh);
    var ubox = document.createElement('div');
    ubox.className = 'seat-grid';
    ubox.style.gridTemplateColumns = 'repeat(' + currentPerRow() + ', minmax(0,1fr))';
    unseated.forEach(function (t) { ubox.appendChild(t); });
    up.appendChild(ubox);
    board.appendChild(up);
}
// 组面板标题（含该组实际入座人数 + 「全选该组 / 全不选」合一开关）
function panelHead(g, seatMap, R, C, span) {
    var cnt = 0;
    for (var r = 0; r < R; r++) {
        for (var c = 0; c < C; c++) {
            if (seatMap[r * span + g * C + c + 1]) cnt++;
        }
    }
    var tiles = [];
    for (var r2 = 0; r2 < R; r2++) {
        for (var c2 = 0; c2 < C; c2++) {
            var t = seatMap[r2 * span + g * C + c2 + 1];
            if (t) tiles.push(t);
        }
    }
    var head = document.createElement('div');
    head.className = 'seat-panel-head';
    var gname = (SEAT_GROUP_NAMES && SEAT_GROUP_NAMES[g + 1]) ? SEAT_GROUP_NAMES[g + 1] : '第' + (g + 1) + '组';   // 组名跟组走（设置分组弹窗拖拽调序后按 sort 呈现）
    head.innerHTML = '<span class="grp-name">' + escHtml(gname) + '（' + cnt + '人）</span>'
        + '<span class="grp-ops"><a href="javascript:void(0)" title="合一开关：未全选=全选该组；已全选=全不选" onclick="toggleSeatGroupSel(' + g + ')">' + (tilesAllSelected(tiles) ? '全不选' : '全选该组') + '</a></span>';
    return head;
}
// 座位模式：全选该组 / 全不选 合一开关（配合「登记已选 / 评价已选」整组统一操作）
function toggleSeatGroupSel(g) {
    var panel = document.querySelector('#seatBoard .seat-panel[data-g="' + g + '"]');
    if (!panel) return;
    var on = !tilesAllSelected(Array.prototype.slice.call(panel.querySelectorAll('.tile')));
    panel.querySelectorAll('.tile').forEach(function (t) {
        var cb = t.querySelector('.tile-check');
        if (cb) { cb.checked = on; t.classList.toggle('checked', on); }
    });
    updateSeatGroupBtns();
    updateSelAllBtn();
}
// 按当前勾选状态刷新座位各组「全选该组 / 全不选」文案
function updateSeatGroupBtns() {
    document.querySelectorAll('#seatBoard .seat-panel').forEach(function (panel) {
        var a = panel.querySelector('.seat-panel-head a');
        if (a) a.textContent = tilesAllSelected(Array.prototype.slice.call(panel.querySelectorAll('.tile'))) ? '全不选' : '全选该组';
    });
}
// 座位模式：展开 / 收起未分组（未入座）学生池
function toggleSeatUn() { seatUnShown = !seatUnShown; renderSeatBoard(); }

// 进行分组 / 调整分组：打开座位分组弹窗（iframe 加载 seat_modal.php，与学生名单页共用）；关闭走弹窗内「取消」，保存后 seat_modal 自动刷新整页
function openSeatGroupModal() {
    if (VIEW_DATE || VIEW_ONLY) { showToast('当前视图不支持调整分组', 'warn'); return; }
    document.getElementById('seatGroupFrame').src = 'seat_modal.php?class_id=' + CLASS_KEY;
    document.getElementById('seatGroupModal').classList.add('show');
}

// ===== 项目设置按钮（⚙ 点击向左侧展开视图工具条；点 ✕、点 ⚙ 或点页面其他区域收起） =====
// ⚙ 视图工具条开关用全局 toggleDD（includes/layout.php，含 .active 高亮）；本页自管 .pv-dd 的收起逻辑
function closePvDD() {
    document.querySelectorAll('.pv-dd.open').forEach(function (d) { d.classList.remove('open'); });
    var b = document.querySelector('.pv-dd > button'); if (b) b.classList.remove('active');
}
// 点击菜单外任意区域自动收起（菜单/⚙ 按钮点击不受影响）
document.addEventListener('click', function (e) { if (!e.target.closest('.pv-dd')) closePvDD(); });
// 确认类操作（办结/恢复/复制/删除）已迁移至项目列表页「管理」菜单：本页仅保留大屏视图设置

// ===== 网格：一行个数（按班级记忆，历史/只读视图加载时同样应用） =====
function defaultPerRow() { return (window.innerWidth <= 768) ? '3' : '5'; }   // 手机端默认一行3个
function savedPerRow() {
    var p = loadClassPrefs();
    if (p.per) return String(p.per);
    try { return localStorage.getItem('qj_per_row'); } catch (e) { return null; }   // 兼容旧的全局设置
}
function applyPerRow(v) {
    if (v === undefined || v === null) {
        var el0 = document.getElementById('perRow');
        v = el0 ? el0.value : (savedPerRow() || defaultPerRow());
    }
    v = parseInt(v, 10);
    if (!(v >= 1 && v <= 10)) v = parseInt(defaultPerRow(), 10);
    v = String(v);
    var el = document.getElementById('perRow');
    if (el && el.value !== v) el.value = v;
    var grid = document.getElementById('tileGrid');
    if (grid) grid.style.gridTemplateColumns = 'repeat(' + v + ', minmax(0, 1fr))';
    // 搬搬乐左右两列与主网格同行数
    ['moveLeft', 'moveRight'].forEach(function (id) {
        var col = document.getElementById(id);
        if (col) col.style.gridTemplateColumns = 'repeat(' + v + ', minmax(0, 1fr))';
    });
    // 分组显示（横向）跟随一行个数变化
    if (typeof groupView !== 'undefined' && groupView && groupDir === 'h') renderGroupBoard();
    saveClassPrefs();
}
(function () {
    var el = document.getElementById('perRow');
    var saved = savedPerRow();
    // 有缓存优先用缓存；否则按设备给默认值（手机3个 / 电脑5个）并写入缓存
    if (el) el.value = saved || defaultPerRow();
    applyPerRow(el ? el.value : undefined);
})();

// ===== 轻提示：统一用 layout.php 全局 showToast（页面下方中央黑色 toast，3 秒自动消失；小问号说明走 toggleHint 问号旁浮层） =====

// ===== 历史日期临时解锁 =====
function onHistToggle() {
    var sw = document.getElementById('swHist');
    if (!sw) return;
    histEdit = sw.checked;
    var lb = document.getElementById('histLabel');
    if (lb) {
        lb.textContent = histEdit ? '🔓 已临时解锁（可编辑该日打卡）' : '🔓 临时解锁编辑';
        lb.style.color = histEdit ? '#e67e22' : '#999';
    }
    document.querySelectorAll('.tile').forEach(function (t) { t.classList.toggle('hist-edit', histEdit); });
}
// 历史日期操作闸门：默认锁定只读，须先勾选「临时解锁编辑」
function requireHistEdit() {
    if (!VIEW_DATE) return true;
    if (!CAN_EDIT_HISTORY) { showToast('正在查看历史打卡记录（只读），不能操作', 'warn'); return false; }
    if (!histEdit) { showToast('历史日期默认锁定：请先勾选「🔓 临时解锁编辑」后再操作', 'warn'); return false; }
    return true;
}
// ===== 历史日期扫码补登（跳转扫码页并携带补登日期） =====
function goHistScan() {
    if (!requireHistEdit()) return;
    location.href = 'scan.php?project_id=' + PROJECT_ID + '&date=' + VIEW_DATE;
}

// ===== 次项/题次管理：⚙管理模式（铅笔重命名 / ×删除）+「＋」增项（删除弹窗确认，该次数据一并删除，其后项次前移） =====
function pvRoundUrl(r) {
    var u = new URL(location.href);
    u.searchParams.set('round', r);
    return u.toString();
}
function pvEsc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}
function pvRoundTitle(rn) { return ROUND_TITLES[rn] || ''; }   // 轮次标题（空=显示序号）

// ===== ⚙ <?php echo $round_label; ?>管理弹窗 + 胶囊分页 =====
var ROUND_COUNTS = {};   // {轮次: 登记记录数}（round_list 返回；删除时判断是否需要密码）
var CHIP_SIZE = 3;       // 胶囊默认显示个数
var CHIP_WIN = <?php $ci = array_search($current_round, $rounds, true); echo ($ci === false || $ci <= 2) ? 0 : (intval($ci) - 2 > count($rounds) - 3 ? max(0, count($rounds) - 3) : intval($ci) - 2); ?>;   // 显示窗口起点（默认前 3 个；当前题次不在前 3 时定位到可见窗口）
var ROUND_PANEL = false; // ⏳ 展开全部面板

function chipMove(d) {   // ◀▶ 平移显示窗口（仅显示，不切换题次）
    var maxWin = Math.max(0, ROUNDS.length - CHIP_SIZE);
    CHIP_WIN = Math.min(maxWin, Math.max(0, CHIP_WIN + d));
    renderRoundChips();
}
function toggleRoundPanel() { ROUND_PANEL = !ROUND_PANEL; renderRoundChips(); }
function openRoundAdmin() {
    var m = document.getElementById('roundAdminModal');
    if (m) m.classList.add('show');
    raReload();
}
function closeRoundAdmin() {
    var m = document.getElementById('roundAdminModal');
    if (m) m.classList.remove('show');
}
function raReload() {   // 拉取题次与各次记录数后渲染管理列表
    apiCall('round_list', '', function (d) {
        if (!d.success) { showToast(d.message || '获取失败', 'warn'); return; }
        ROUNDS = d.rounds || ROUNDS;
        ROUND_TITLES = d.titles || {};
        ROUND_COUNTS = d.counts || {};
        raRender();
        renderRoundChips();
    });
}
function raRender() {
    var box = document.getElementById('roundAdminList');
    if (!box) return;
    var n = ROUNDS.length;
    box.innerHTML = ROUNDS.map(function (rn, i) {
        var t = pvRoundTitle(rn);
        var cnt = ROUND_COUNTS[rn] || 0;
        return '<tr>'
            + '<td style="white-space:nowrap;font-weight:bold;color:#667eea;">第 ' + rn + ' ' + pvEsc(ROUND_LABEL) + '</td>'
            + '<td><input type="text" class="form-control" value="' + pvEsc(t) + '" placeholder="未命名（显示序号）" maxlength="30" style="width:100%;" onchange="raSave(' + rn + ', this.value)" onkeydown="if(event.key===\'Enter\'){this.blur();}" autocomplete="nope" readonly onfocus="this.removeAttribute(\'readonly\')"></td>'
            + '<td style="white-space:nowrap;">'
            + '<button type="button" class="btn btn-sm btn-outline"' + (i === 0 ? ' disabled' : '') + ' onclick="raMove(' + rn + ',\'up\')" title="与上一位交换顺序">↑</button> '
            + '<button type="button" class="btn btn-sm btn-outline"' + (i === n - 1 ? ' disabled' : '') + ' onclick="raMove(' + rn + ',\'down\')" title="与下一位交换顺序">↓</button> '
            + '<button type="button" class="btn btn-sm btn-outline"' + (n <= 1 ? ' disabled' : '') + ' onclick="raDel(' + rn + ')" title="删除该' + pvEsc(ROUND_LABEL) + '（有登记记录需登录密码）" style="color:#e74c3c;">×</button>'
            + '</td>'
            + '<td style="white-space:nowrap;color:' + (cnt > 0 ? '#e67e22' : '#999') + ';font-size:12px;">' + (cnt > 0 ? cnt + ' 条记录' : '无记录') + '</td>'
            + '</tr>';
    }).join('');
}
function raSave(n, title) {
    title = String(title || '').trim();
    apiCall('round_rename', 'round=' + n + '&title=' + encodeURIComponent(title), function (d) {
        if (!d.success) { showToast(d.message || '保存失败', 'warn'); return; }
        ROUND_TITLES = d.titles || {};
        raRender();
        renderRoundChips();
        showToast(title ? '已命名第 ' + n + ' ' + ROUND_LABEL + '：' + title : '已清除第 ' + n + ' ' + ROUND_LABEL + '标题');
    });
}
function raMove(n, dir) {
    apiCall('round_reorder', 'round=' + n + '&dir=' + dir, function (d) {
        if (!d.success) { showToast(d.message || '调整失败', 'warn'); return; }
        ROUNDS = d.rounds || ROUNDS;
        ROUND_TITLES = d.titles || {};
        raRender();
        renderRoundChips();
    });
}
function raAdd() {   // 管理弹窗内新增：只刷新列表与胶囊（不跳页）
    apiCall('round_add', '', function (d) {
        ROUNDS = d.rounds || ROUNDS;
        ROUND_TITLES = d.titles || {};
        raRender();
        renderRoundChips();
        showToast('已新增第 ' + (d.current || ROUNDS[ROUNDS.length - 1]) + ' ' + ROUND_LABEL);
    });
}
function raDel(n) {
    if (ROUNDS.length <= 1) { showToast('至少保留一次，不能删除', 'warn'); return; }
    var cnt = ROUND_COUNTS[n] || 0;
    if (!confirm('删除第 ' + n + ' ' + ROUND_LABEL + (cnt > 0 ? '？该次有 ' + cnt + ' 条登记记录将一并删除，其后' + ROUND_LABEL + '自动前移！' : '？其后' + ROUND_LABEL + '自动前移。'))) return;
    var pwd = '';
    if (cnt > 0) {
        pwd = prompt('该次有 ' + cnt + ' 条登记记录，请输入当前账号登录密码确认删除：');
        if (pwd === null) return;
    }
    raDelReq(n, pwd, 0);
}
function raDelReq(n, pwd, retried) {
    apiCall('round_delete', 'round=' + n + (pwd ? '&password=' + encodeURIComponent(pwd) : ''), function (d) {
        if (!d.success) {
            if (d.need_pwd && !retried) {   // 服务端检测到有记录（如打开弹窗后新登记）：补输密码重试一次
                var p2 = prompt(d.message || '请输入当前账号登录密码：');
                if (p2 === null) return;
                raDelReq(n, p2, 1);
                return;
            }
            showToast(d.message || '删除失败', 'warn');
            return;
        }
        ROUNDS = d.rounds || ROUNDS;
        ROUND_TITLES = d.titles || {};
        raRender();
        renderRoundChips();
        showToast('已删除第 ' + n + ' ' + ROUND_LABEL);
        if (n === CURRENT_ROUND) location.href = pvRoundUrl(d.current || 1);   // 删除的是当前题次：跳到剩余题次
    });
}
function roundImportDo() {
    var ta = document.getElementById('roundImportText');
    if (!ta) return;
    if (!ta.value.trim()) { showToast('请粘贴标题清单（每行一个，第一行=第 1 次）', 'warn'); return; }
    if (!confirm('导入将把第 1、2、3…' + ROUND_LABEL + '的标题替换为清单内容，行数超出现有' + ROUND_LABEL + '时自动新增（不删除任何现有数据）。确认导入？')) return;
    apiCall('round_import', 'titles=' + encodeURIComponent(ta.value), function (d) {
        if (!d.success) { showToast(d.message || '导入失败', 'warn'); return; }
        ROUNDS = d.rounds || ROUNDS;
        ROUND_TITLES = d.titles || {};
        raRender();
        renderRoundChips();
        ta.value = '';
        showToast('导入完成：更新 ' + d.applied + ' 个、新增 ' + d.added + ' 个' + ROUND_LABEL);
    });
}
function roundImportFile(input) {   // 选择 txt/csv 文件读入文本框（与粘贴等效）
    var f = input.files && input.files[0];
    if (!f) return;
    if (f.size > 102400) { showToast('文件过大（限 100KB）', 'warn'); input.value = ''; return; }
    var r = new FileReader();
    r.onload = function () { var ta = document.getElementById('roundImportText'); if (ta) ta.value = String(r.result || ''); };
    r.readAsText(f, 'UTF-8');
    input.value = '';
}
function roundAdd() {
    apiCall('round_add', '', function (d) {
        location.href = pvRoundUrl(d.current || (CURRENT_ROUND + 1));
    });
}

// ===== 点击序号（三模式共用逻辑：批量评价 / 登记+评价 / 默认登记与「是否取消」确认） =====
function onTileClick(studentId) {
    if (IS_RAISE) {
        // 举牌模式：点击卡片 = 弹该生答题变化（与统计名单点姓名一致，纯查看不受锁定/权限拦截）；修改或登记走长按
        openStuTrend(studentId);
        return;
    }
    if (VIEW_DATE && !requireHistEdit()) return;   // 历史日期：解锁后与今日逻辑一致（API 自动携带 date）
    if (VIEW_ONLY) { showToast('您在该班级的授权权限为「仅查看」，无法登记操作', 'warn'); return; }
    var swBatchEl = document.getElementById('swBatchEval');   // 答题模式隐藏批量评价：元素不存在
    var swBatch = swBatchEl ? swBatchEl.checked : false;
    var tile = document.getElementById('tile_' + studentId);

    if (effectiveLocked(tile)) {
        showToast(lockMode === 'all'
            ? '已临时全部锁定，不能进行登记操作。可点击「锁定」开关临时解锁。'
            : '该学生登记已超过锁定时间，已自动锁定。可点击「锁定」开关临时解锁。', 'warn');
        return;
    }

    if (IS_QUIZ) {
        // 答题模式：点击弹窗四选一（A/B/C/D）；只有点选项才算登记成功（取消弹窗 = 不留任何记录）
        openQuizEval(studentId);
        return;
    }

    if (IS_OMR) {
        // 答题卡模式：点击 = 弹该生统计图表（历次得分率 / 题组正确率 / 全班对比）；补登记、取消登记、修改答案入口都在弹窗内
        openOmrStu(studentId);
        return;
    }

    if (swBatch) {
        // 批量评价模式：先确保已设置评价内容
        if (batchEvalValue === '') { openBatchEvalDialog(false); return; }
        // 登记（若未登记）+ 相同评价
        apiCall('batch_register', 'student_ids=' + studentId, function (d) {
            setTileState(studentId, true, d.regts);
            refreshStats(d.total, d.registered_count, d.evaluated_count);
            apiCall('batch_evaluate', 'student_ids=' + studentId + '&value=' + encodeURIComponent(batchEvalValue), function (d2) {
                setTileEval(studentId, batchEvalValue);
                refreshStats(d2.total, d2.registered_count, d2.evaluated_count);
            });
        });
        return;
    }
    // 默认（三模式一致）：未登记→直接登记；已登记→格子内弹出「是否取消」确认层（取消=同时清除评价）。
    // 评价统一走长按弹窗（未登记提交评价后自动登记+评价；已登记长按=修改评价）；答题/举牌模式已在上方 IS_QUIZ 分支弹窗四选一
    if (tile.dataset.state === 'yes') { showOffConfirm(studentId); return; }
    apiCall('toggle_register', 'student_id=' + studentId, function (d) {
        setTileState(studentId, !!d.registered, d.regts);
        if (!d.registered) setTileEval(studentId, '');
        refreshStats(d.total, d.registered_count, d.evaluated_count);
    });
}

// ===== 长按学生卡片：弹窗评价（触屏 + 鼠标一致；长按后拦截随后的 click，避免误触发登记 / 取消确认层） =====
(function () {
    var timer = null, lpTile = null, lpFired = false, sx = 0, sy = 0, lastTouch = 0;
    function fire() {
        lpFired = true; timer = null;
        var sid = parseInt(lpTile.dataset.id, 10);
        if (VIEW_DATE && !requireHistEdit()) return;              // 历史日期：需先临时解锁
        if (VIEW_ONLY) { showToast('您在该班级的授权权限为「仅查看」，无法评价操作', 'warn'); return; }
        if (IS_OMR) {
            // 答题卡模式：长按 = 弹逐题答案查看/修改（默认锁定防误改，弹窗内点「解锁」后才能修改并提交）
            openOmrEdit(sid, { locked: true });
            return;
        }
        if (effectiveLocked(lpTile)) {
            showToast(lockMode === 'all'
                ? '已临时全部锁定，不能进行评价操作。可点击「锁定」开关临时解锁。'
                : '该学生登记已超过锁定时间，已自动锁定。可点击「锁定」开关临时解锁。', 'warn');
            return;
        }
        if (navigator.vibrate) navigator.vibrate(30);             // 轻震动反馈
        if (IS_RAISE) {
            // 举牌模式：长按 = 修改或登记（四选一答题弹窗；未登记点选项即登记，已登记可改选/取消登记）
            openQuizEval(sid);
            return;
        }
        openEvalDialog(sid, lpTile.dataset.state === 'yes' ? '修改评价' : '登记+评价');
        // 长按拦截旗标自动复位：旗标只用于吞掉长按松开时紧随的合成 click（<100ms）。
        // 触屏长按不派发 click，若等下一次点击复位，用户在弹窗内的第一次点击会被误吞——故 500ms 后放行。
        setTimeout(function () { lpFired = false; }, 500);
    }
    function startLP(e) {
        if (e.type === 'mousedown') {
            if (e.button !== 0) return;                           // 仅左键长按
            if (Date.now() - lastTouch < 800) return;             // 触屏后的合成 mousedown 不重复计时
            lastTouch = 0;
        } else {
            lastTouch = Date.now();
        }
        var tile = e.target.closest ? e.target.closest('.tile[id^="tile_"]') : null;
        if (!tile) return;
        lpFired = false; lpTile = tile;
        var p = e.touches ? e.touches[0] : e;
        sx = p.clientX; sy = p.clientY;
        timer = setTimeout(fire, 550);
    }
    function moveLP(e) {
        if (!lpTile || !timer) return;
        var p = e.touches ? e.touches[0] : e;
        var dx = p.clientX - sx, dy = p.clientY - sy;
        if (dx * dx + dy * dy > 100) { clearTimeout(timer); timer = null; }   // 移动>10px视为滚动，取消长按
    }
    function endLP(e) {
        if (!lpTile) return;
        if (timer) { clearTimeout(timer); timer = null; }
        if (lpFired) { e.preventDefault(); e.stopPropagation(); }     // 长按已触发：拦截合成 click
        lpTile = null;
    }
    document.addEventListener('touchstart', startLP, { passive: true });
    document.addEventListener('touchmove', moveLP, { passive: true });
    document.addEventListener('touchend', endLP, { passive: false });
    document.addEventListener('touchcancel', function () { if (timer) clearTimeout(timer); timer = null; lpTile = null; });
    // 鼠标长按（与触屏同参数）：左键按住 550ms 弹评价；按住移动>10px 取消；窗口失焦清理
    document.addEventListener('mousedown', startLP);
    document.addEventListener('mousemove', moveLP);
    document.addEventListener('mouseup', function () {
        if (timer) { clearTimeout(timer); timer = null; }
        lpTile = null;                                                // lpFired 交由 click 捕获层复位
    });
    window.addEventListener('blur', function () { if (timer) { clearTimeout(timer); timer = null; } lpTile = null; });
    document.addEventListener('click', function (e) {                 // 长按后的 click（真实/合成）统一在此拦截
        if (lpFired) { e.preventDefault(); e.stopPropagation(); lpFired = false; }
    }, true);
})();

// ===== 取消登记确认层（格子内「是否取消」+ 是/否圆钮；取消=同时清除已有评价，后台记录清除时间） =====
var confirmingTile = null;
function showOffConfirm(studentId) {
    var tile = document.getElementById('tile_' + studentId);
    if (!tile) return;
    if (confirmingTile && confirmingTile !== tile) confirmingTile.classList.remove('confirming');
    tile.classList.add('confirming');
    confirmingTile = tile;
}
function hideOffConfirm() {
    if (confirmingTile) confirmingTile.classList.remove('confirming');
    confirmingTile = null;
}
function confirmOffYes(ev) {
    ev.stopPropagation();
    var tile = ev.target.closest('.tile');
    if (tile === confirmingTile) hideOffConfirm();
    else if (tile) tile.classList.remove('confirming');
    if (!tile) return;
    var studentId = parseInt(tile.dataset.id, 10);
    apiCall('toggle_register', 'student_id=' + studentId, function (d) {
        setTileState(studentId, !!d.registered, d.regts);
        if (!d.registered) setTileEval(studentId, '');   // 取消登记：评价一并清除
        refreshStats(d.total, d.registered_count, d.evaluated_count);
    });
}
function confirmOffNo(ev) {
    ev.stopPropagation();
    var tile = ev.target.closest('.tile');
    if (tile === confirmingTile) hideOffConfirm();
    else if (tile) tile.classList.remove('confirming');
}

// ===== 开关联动 =====
function onBatchEvalChange() {
    var be = document.getElementById('swBatchEval');
    if (be && be.checked) {
        openBatchEvalDialog(false);
    }
}

// ===== 批量评价内容设置弹窗 =====
function openBatchEvalDialog(isReset) {
    evalTarget = 'batch';
    document.getElementById('eval_title').textContent = '批量评价' + (isReset ? '（重设）' : '');
    document.getElementById('eval_student_name').textContent = '之后点击的序号将全部设为相同评价';
    var rb = document.getElementById('eval_remove_btn');
    rb.style.display = '';
    rb.textContent = '清除设置';
    rb.title = '清除当前批量评价设置：之后点击序号不再自动应用评价';
    rb.onclick = clearBatchEval;
    buildEvalOptions(batchEvalValue);
    document.getElementById('eval_comment_group').style.display = 'none';   // 批量评价不涉及点评
    document.getElementById('evalModal').classList.add('show');
}
// 批量评价：清除当前设置（关闭弹窗并隐藏提示，之后点击序号不再自动评价；需重新设置才会继续）
function clearBatchEval() {
    batchEvalValue = '';
    document.getElementById('batchEvalHint').style.display = 'none';
    closeEval();
    showToast('已清除批量评价设置，之后点击序号不再自动评价', 'success');
}

// ===== 答题模式：四选一弹窗（点选项即登记成功；「取消」不留记录；已登记者可「取消登记」移除本次记录） =====
function openQuizEval(studentId) {
    evalTarget = 'single';
    currentEvalStudent = studentId;
    pendingRegStudent = 0;
    document.getElementById('eval_title').textContent = '答题 - 第' + CURRENT_ROUND + ROUND_LABEL;
    document.getElementById('eval_student_name').textContent = document.getElementById('tile_' + studentId).dataset.name;
    var rb = document.getElementById('eval_remove_btn');
    var already = document.getElementById('tile_' + studentId).dataset.state === 'yes';
    rb.style.display = already ? '' : 'none';   // 未登记者点「取消」= 关闭弹窗不登记；已登记者可取消本次登记
    rb.textContent = '取消登记';
    rb.title = '取消该生本次登记（连同答案一并清除）';
    rb.onclick = quizUnregister;
    var optionsBox = document.getElementById('eval_options');
    document.getElementById('eval_input_group').style.display = 'none';
    document.getElementById('eval_comment_group').style.display = 'none';   // 答题弹窗不涉及点评
    optionsBox.innerHTML = '';
    var cur = document.getElementById('tile_' + studentId).dataset.eval || '';
    var colors = { A: '#667eea', B: '#27ae60', C: '#f39c12', D: '#e74c3c' };
    ['A', 'B', 'C', 'D'].forEach(function (k) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = k;
        btn.style.cssText = 'min-width:64px;padding:14px 0;font-size:20px;font-weight:bold;color:#fff;background:' + colors[k] + ';border:0;border-radius:10px;cursor:pointer;' + (cur === k ? 'outline:3px solid #333;outline-offset:2px;' : '');
        btn.onclick = function () { submitQuizAnswer(k); };
        optionsBox.appendChild(btn);
    });
    document.getElementById('evalModal').classList.add('show');
}
function submitQuizAnswer(opt) {
    var name = document.getElementById('tile_' + currentEvalStudent) ? document.getElementById('tile_' + currentEvalStudent).dataset.name : '';
    apiCall('quiz_answer', 'student_id=' + currentEvalStudent + '&value=' + opt, function (d) {
        setTileState(currentEvalStudent, true, d.regts);
        setTileEval(currentEvalStudent, d.eval_value || opt);
        if (!TILE_DOTS[currentEvalStudent]) TILE_DOTS[currentEvalStudent] = { cur: '', prev: '', next: '' };
        TILE_DOTS[currentEvalStudent].cur = d.eval_value || opt;   // 卡片角点跟随新答案
        tileDotsRender(currentEvalStudent);
        refreshStats(d.total, d.registered_count, d.evaluated_count);
        closeEval();
        showToast(name + ' 已选 ' + (d.eval_value || opt) + '，登记成功', 'success');
    });
}
function quizUnregister() {
    apiCall('toggle_register', 'student_id=' + currentEvalStudent, function (d) {
        setTileState(currentEvalStudent, !!d.registered, d.regts);
        if (!d.registered) setTileEval(currentEvalStudent, '');
        if (!d.registered && TILE_DOTS[currentEvalStudent]) TILE_DOTS[currentEvalStudent].cur = '';   // 取消登记：本题未答，隐藏角点（保留 prev/next 供再答后即时恢复）
        tileDotsRender(currentEvalStudent);
        refreshStats(d.total, d.registered_count, d.evaluated_count);
        closeEval();
    });
}

// ===== 答题模式统计：本题 ABCD 条形统计（勾选正确答案）+ 累计（复式条形 / 折线）+ 正确率 + 名单标签 + 个人变化图 =====
var quizStatsData = null;
var QUIZ_COLORS = { A: '#667eea', B: '#27ae60', C: '#f39c12', D: '#e74c3c' };
var CORRECT_COLOR = '#27ae60';
var qsMode = 'single';   // single=本题统计  compare=累计（全部题次）  correct=正确率（每题答对人数）
var qsChart = 'bar';     // bar=复式条形统计图  line=折线统计图
var qsCompare = null;    // 累计/正确率数据缓存 {rounds:[], students:{}}
var qsRound = 0;         // 本题统计当前查看的题次（仅切换查看，不影响主页登记轮次）
var qsRounds = [];       // 全部题次列表（上一题/下一题边界）
var qsExtra = '';        // 附加视图：''=无  'group'=分组  'radar'=雷达  'comp'=举牌构成（举牌专属）
var qsRadarSel = {};     // 雷达视图选中的学生（sid => true，可多选叠加，最多 8 人）
var qsRaiseMode = 'raise';   // 举牌专属统计模式：raise=举牌统计（本题选项分析+累计卡片） correct=正确统计（每题答对人数）
function qsLoadRound(rn, reopen) {   // 拉取某题次统计（single 模式）
    fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'type=quiz_stats&project_id=' + PROJECT_ID + '&round=' + rn
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (!d || !d.success) { showToast((d && d.message) || '加载失败', 'error'); return; }
        quizStatsData = d;
        qsRound = intval0(d.round);
        qsRounds = (d.rounds && d.rounds.length) ? d.rounds.map(intval0) : [qsRound];
        renderQuizStats();
        updateQsBtns();
        if (reopen) document.getElementById('quizStatsModal').classList.add('show');
    })
    .catch(function () { showToast('网络错误', 'error'); });
}
function qsRefreshCompare(reopen) {  // 拉取全部题次数据（累计 / 正确率模式）
    fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'type=quiz_compare&project_id=' + PROJECT_ID
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (!d || !d.success) { showToast((d && d.message) || '加载失败', 'error'); return; }
        qsCompare = d;
        qsRounds = (d.rounds || []).map(function (r) { return intval0(r.round); });
        if (qsExtra === 'group') renderQuizGroup();
        else if (qsExtra === 'radar') renderQuizRadar();
        else if (qsMode === 'correct') renderQuizCorrect(); else renderQuizCompare();
        updateQsBtns();
        if (reopen) document.getElementById('quizStatsModal').classList.add('show');
    })
    .catch(function () { showToast('网络错误', 'error'); });
}
function openQuizStats() {
    document.getElementById('quizStatsModal').classList.add('show');   // 先显示弹层：ECharts 需容器可见有尺寸，否则 ecSet 守卫拒绝导致图区空白
    var isRaise = !!document.getElementById('qsRaiseBtn');
    if (qsExtra === 'group' || qsExtra === 'radar') { qsRefreshCompare(true); return; }   // 分组 / 雷达：基于累计数据
    if (isRaise && qsRaiseMode === 'raise') { qsMode = 'single'; qsExtra = ''; qsLoadRound(CURRENT_ROUND, true); return; }   // 举牌统计：默认本题选项分析（横条/饼图，可勾正确答案）+ 顶部累计卡片
    if (isRaise && qsRaiseMode === 'correct') { qsMode = 'correct'; qsExtra = ''; qsRefreshCompare(true); return; }             // 正确统计：每题答对人数
    if (qsExtra === 'comp' || qsMode === 'single') qsLoadRound(CURRENT_ROUND, true);      // 本题 / 构成：按当前题次刷新
    else qsRefreshCompare(true);          // 累计 / 正确率：刷新数据并保持视图
}
var qsPieOn = false;   // 本题统计图型：false=柱形横条（默认）/ true=饼图（数量及占比）
function qsTogglePie() {
    qsPieOn = !qsPieOn;
    updateQsBtns();
    if (qsMode === 'single' && !qsExtra) renderQuizStats();
}
function renderQuizStats() {
    var d = quizStatsData;
    if (!d) return;
    if (qsExtra === 'comp') return renderRaiseComp();
    qsRound = intval0(d.round);
    var correct = d.correct || [];
    var corrCnt = 0;
    correct.forEach(function (k) { corrCnt += (d.counts[k] || 0); });
    document.getElementById('qsRound').textContent = '第' + qsRound + ROUND_LABEL;
    document.getElementById('qsSummary').textContent = '已答 ' + d.answered + ' / ' + d.total + ' 人 · 未答 ' + d.unanswered + ' 人'
        + (correct.length ? ' · 正确（' + correct.join('、') + '）' + corrCnt + ' 人' : '')
        + (qsPieOn ? '（点击扇区查看名单）' : '（点击柱子查看名单；勾选「正确答案」可多选，用于正确率统计）');
    var box = document.getElementById('qsBars');
    var cv = document.getElementById('qsCanvas');
    if (qsPieOn) {   // 饼图视图：一页一图（隐藏横条区）
        box.style.display = 'none';
        cv.style.display = '';
        var items = ['A', 'B', 'C', 'D'].map(function (k) {
            return { name: k + (correct.indexOf(k) >= 0 ? ' ✓' : ''), value: d.counts[k] || 0, color: QUIZ_COLORS[k] };
        });
        var chp = ecSet('qsCanvas', ecPieOpt(items, {unit: '人', centerMain: String(d.answered), centerSub: '已答/共' + d.total + '人'}));
        if (chp) chp.on('click', function (p) {
            var k = String(p.name).charAt(0);
            if ('ABCD'.indexOf(k) >= 0) showQuizList(k);
        });
    } else {   // 柱形视图：图4 式横条（点击看名单 + 勾选正确答案）
        cv.style.display = 'none';
        box.style.display = '';
        var max = 1;
        ['A', 'B', 'C', 'D'].forEach(function (k) { max = Math.max(max, d.counts[k] || 0); });
        box.innerHTML = '';
        ['A', 'B', 'C', 'D'].forEach(function (k) {
            var cnt = d.counts[k] || 0;
            var isC = correct.indexOf(k) >= 0;
            var row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;gap:10px;margin:10px 0;cursor:pointer;';
            row.title = '查看选项 ' + k + ' 的学生名单';
            row.innerHTML = '<span style="width:22px;font-weight:bold;font-size:16px;color:' + QUIZ_COLORS[k] + ';">' + k + '</span>' +
                '<div style="flex:1;background:#eef1f8;border-radius:8px;overflow:hidden;">' +
                '<div style="width:' + Math.max(cnt * 100 / max, cnt ? 8 : 0) + '%;background:' + QUIZ_COLORS[k] + ';color:#fff;padding:8px 10px;border-radius:8px;font-weight:bold;white-space:nowrap;">' + (isC ? '✓ ' : '') + cnt + ' 人</div></div>' +
                '<label onclick="event.stopPropagation()" title="勾选表示该选项是本题正确答案（可多选），保存后用于「正确率」统计" style="display:inline-flex;align-items:center;gap:4px;font-size:13px;color:#555;white-space:nowrap;cursor:pointer;">' +
                '<input type="checkbox" data-opt="' + k + '"' + (isC ? ' checked' : '') + ' onchange="qsSaveCorrect()" autocomplete="off"> 正确答案</label>';
            row.onclick = function () { showQuizList(k); };
            box.appendChild(row);
        });
    }
    document.getElementById('qsList').style.display = 'none';
}
// 🥧 构成（举牌专属）：当前题次已举牌 / 未举牌占比（部分与整体）
function renderRaiseComp() {
    var d = quizStatsData;
    if (!d) return;
    qsRound = intval0(d.round);
    document.getElementById('qsRound').textContent = '构成 · 第' + qsRound + ROUND_LABEL;
    var off = Math.max(0, d.total - d.answered);
    document.getElementById('qsSummary').textContent = '已举牌 ' + d.answered + ' / ' + d.total + ' 人 · 未举牌 ' + off + ' 人（点击扇区查看名单；🏆 累计 / 今日 / 人均举牌次数见顶部卡片）';
    document.getElementById('qsBars').style.display = 'none';
    var cv = document.getElementById('qsCanvas');
    cv.style.display = '';
    var ch = ecSet('qsCanvas', ecPieOpt([
        {name: '已举牌', value: d.answered, color: '#27ae60'},
        {name: '未举牌', value: off, color: '#e74c3c'}
    ], {unit: '人', centerMain: d.answered + ' / ' + d.total, centerSub: '已举牌 / 本班'}));
    if (ch) ch.on('click', function (p) {
        if (p.name === '已举牌') qsRenderListColor('第' + qsRound + ROUND_LABEL + ' 已举牌', '#27ae60', d.rows || [], '暂无学生');
    });
    document.getElementById('qsList').style.display = 'none';
}
function qsSaveCorrect() {   // 保存本题正确答案勾选（可多选）
    var opts = [];
    document.querySelectorAll('#qsBars input[type="checkbox"]:checked').forEach(function (cb) { opts.push(cb.dataset.opt); });
    apiCall('round_correct', 'round=' + qsRound + '&opts=' + opts.join(','), function (d) {
        if (quizStatsData && intval0(d.round) === qsRound) quizStatsData.correct = d.correct || [];
        qsCompare = null;   // 正确率数据已变化：下次进入重新拉取
        showToast(d.correct && d.correct.length ? '正确答案已保存：' + d.correct.join('、') : '正确答案已清除', 'success');
    });
}
function qsSwitchRound(dir) {   // 上一题 / 下一题（仅切换查看的题次）
    var idx = qsRounds.indexOf(qsRound);
    var ni = idx + dir;
    if (idx < 0 || ni < 0 || ni >= qsRounds.length) return;
    qsLoadRound(qsRounds[ni], false);
}
function showQuizList(opt) {
    var d = quizStatsData;
    if (!d) return;
    qsRenderList('选项 ' + opt, opt, d.rows || []);
}
function closeQuizStats() {
    document.getElementById('quizStatsModal').classList.remove('show');
    document.getElementById('stuTrendModal').classList.remove('show');
    ecDel('qsCanvas');
}

// ===== 累计 / 正确率：模式 / 图表切换 + 点柱（点）看名单标签 + 点学生标签看个人变化 =====
function qsToggleMode() {
    qsExtra = '';   // 切回基础视图
    qsMode = qsMode === 'single' ? 'compare' : 'single';
    updateQsBtns();
    if (qsMode === 'compare') qsRefreshCompare(false);   // 每次进入累计都取最新数据
    else renderQuizStats();
}
function qsToggleCorrect() {
    qsExtra = '';   // 切回基础视图
    qsMode = qsMode === 'correct' ? 'single' : 'correct';
    updateQsBtns();
    if (qsMode === 'correct') qsRefreshCompare(false);
    else renderQuizStats();
}
function qsToggleChart() {
    qsChart = qsChart === 'bar' ? 'line' : 'bar';
    updateQsBtns();
    if (qsMode === 'compare') renderQuizCompare();
    else if (qsMode === 'correct') renderQuizCorrect();
}
function updateQsBtns() {
    var mb = document.getElementById('qsModeBtn');
    var cb = document.getElementById('qsChartBtn');
    var pb = document.getElementById('qsPrevBtn');
    var nb = document.getElementById('qsNextBtn');
    var cr = document.getElementById('qsCorrectBtn');
    var gb = document.getElementById('qsGroupBtn');
    var rb = document.getElementById('qsRadarBtn');
    var ib = document.getElementById('qsPieBtn');
    var cp = document.getElementById('qsCompBtn');
    var rm = document.getElementById('qsRaiseBtn');
    var tb = document.getElementById('qsTrendBtn');
    var rc = document.getElementById('qsRaiseCards');
    var comp = qsExtra === 'comp';
    var raise = !!rm;   // 举牌项目：按钮规整为 举牌统计/正确统计 + 累计对比（右上角题次下拉通用）
    if (raise) {
        if (rm) {   // 文案=当前模式（亮起=正在显示的统计），点击切换
            rm.textContent = qsRaiseMode === 'raise' ? '🚩 举牌统计' : '✅ 正确统计';
            rm.classList.add('on');
            rm.title = qsRaiseMode === 'raise' ? '当前统计：举牌统计（本题选项分析 + 顶部累计卡片）；点击切换到正确统计（每题答对人数）' : '当前统计：正确统计（每题答对人数）；点击切换到举牌统计（本题选项分析 + 顶部累计卡片）';
        }
        if (rc) rc.style.display = qsRaiseMode === 'raise' ? '' : 'none';   // 累计/今日/人均卡片仅举牌统计显示
        if (tb) { tb.style.display = (qsRaiseMode === 'raise' && !comp) ? '' : 'none'; tb.classList.toggle('on', qsMode === 'compare' && !comp); }
        if (mb) mb.style.display = 'none';
        if (cr) cr.style.display = 'none';
        if (gb) gb.style.display = 'none';
        if (rb) rb.style.display = 'none';
        var single = qsMode === 'single';
        if (cp) { cp.style.display = (qsRaiseMode === 'raise' && single) ? '' : 'none'; cp.classList.toggle('on', comp); }   // 🥧 构成：本题统计下可手动切入已举牌/未举牌占比
        if (ib) { ib.textContent = qsPieOn ? '📊 柱形' : '🥧 饼图'; ib.style.display = (qsRaiseMode === 'raise' && single && !qsExtra) ? '' : 'none'; }   // 选项分析横条/饼图切换
        if (cb) {
            cb.style.display = (qsMode !== 'single' && !qsExtra) ? '' : 'none';
            cb.textContent = qsChart === 'bar' ? '📈 切换折线图' : '📊 切换条形图';
        }
        if (pb) pb.style.display = (single && !comp) ? '' : 'none';
        if (nb) nb.style.display = (single && !comp) ? '' : 'none';
        if (single) {
            var idx = qsRounds.indexOf(qsRound);
            if (pb) pb.disabled = idx <= 0;
            if (nb) nb.disabled = idx < 0 || idx >= qsRounds.length - 1;
        }
        qsSyncRoundSel();
        return;
    }
    if (rc) rc.style.display = 'none';
    if (mb) { mb.textContent = qsMode === 'single' ? '🔀 累计' : '📊 本题统计'; mb.classList.toggle('on', qsMode !== 'single'); mb.style.display = comp ? 'none' : ''; }
    if (cr) { cr.textContent = qsMode === 'correct' ? '📊 答题分布' : '✅ 正确率'; cr.classList.toggle('on', qsMode === 'correct'); cr.style.display = comp ? 'none' : ''; }
    if (gb) { gb.classList.toggle('on', qsExtra === 'group'); gb.style.display = comp ? 'none' : ''; }
    if (rb) { rb.classList.toggle('on', qsExtra === 'radar'); rb.style.display = comp ? 'none' : ''; }
    if (cp) cp.classList.toggle('on', comp);
    if (ib) { ib.textContent = qsPieOn ? '📊 柱形' : '🥧 饼图'; ib.style.display = (qsMode === 'single' && !qsExtra) ? '' : 'none'; }
    if (cb) {
        cb.style.display = (qsMode !== 'single' && !qsExtra) ? '' : 'none';   // 图表切换仅累计/正确率用，附加视图固定图型
        cb.textContent = qsChart === 'bar' ? '📈 切换折线图' : '📊 切换条形图';
    }
    var single2 = qsMode === 'single';
    if (pb) pb.style.display = (single2 && !qsExtra) ? '' : 'none';
    if (nb) nb.style.display = (single2 && !qsExtra) ? '' : 'none';
    if (single2) {
        var idx2 = qsRounds.indexOf(qsRound);
        if (pb) pb.disabled = idx2 <= 0;
        if (nb) nb.disabled = idx2 < 0 || idx2 >= qsRounds.length - 1;
    }
    qsSyncRoundSel();
}
function qsSyncRoundSel() {   // 右上角题次下拉与当前查看题次同步（题次清单变化才重建选项）
    var rs = document.getElementById('qsRoundSel');
    if (!rs) return;
    var sig = qsRounds.join(',');
    if (rs.dataset.sig !== sig) {
        rs.dataset.sig = sig;
        rs.innerHTML = qsRounds.map(function (r) { return '<option value="' + r + '">第' + r + ROUND_LABEL + '</option>'; }).join('');
    }
    if (qsRounds.indexOf(qsRound) >= 0) rs.value = String(qsRound);
}
function qsScopeRound(v) {   // 右上角题次下拉：切换查看该题次统计（不改变登记页当前题次）
    var rn = intval0(v);
    if (!rn) return;
    qsMode = 'single';
    if (qsExtra !== 'comp') qsExtra = '';   // 保持手动切入的构成视图，其余回到本题选项分析
    updateQsBtns();
    qsLoadRound(rn, false);
}
function qsToggleRaiseMode() {   // 举牌专属：举牌统计（本题选项分析 + 累计卡片）/ 正确统计（每题答对人数）
    qsRaiseMode = (qsRaiseMode === 'raise') ? 'correct' : 'raise';
    if (qsRaiseMode === 'raise') { qsMode = 'single'; qsExtra = ''; qsLoadRound(qsRound || CURRENT_ROUND, false); }
    else { qsMode = 'correct'; qsExtra = ''; qsRefreshCompare(false); }
}
function qsRaiseTrend() {   // 举牌专属：全部题次累计对比（📈 可切换复式条形 / 折线）
    qsMode = 'compare'; qsExtra = '';
    updateQsBtns();
    qsRefreshCompare(false);
}
function renderQuizCompare() {
    var d = qsCompare;
    if (!d) return;
    var rounds = d.rounds || [];
    document.getElementById('qsRound').textContent = '累计 · ' + rounds.length + '个' + ROUND_LABEL;
    document.getElementById('qsSummary').textContent = rounds.length
        ? ('共 ' + rounds.length + ' 个' + ROUND_LABEL + ' · ✓ 为正确答案 · 点击柱子 / 拐点查看该' + ROUND_LABEL + '各选项答题名单（点击学生标签看个人变化）')
        : '暂无' + ROUND_LABEL + '数据';
    document.getElementById('qsBars').style.display = 'none';
    var cv = document.getElementById('qsCanvas');
    cv.style.display = '';
    var ch = ecSet('qsCanvas', qsCompareOpt(rounds));
    if (ch) ch.on('click', function (p) {
        var r = rounds[p.dataIndex];
        if (r) qsShowRoundList(r.round, 'ABCD'.charAt(p.seriesIndex));
    });
    document.getElementById('qsList').style.display = 'none';
}
// 累计对比图（ECharts）：横轴=题次，A/B/C/D 各一系列（bar=复式柱 / line=折线）
function qsCompareOpt(rounds) {
    var isLine = qsChart === 'line';
    return {
        grid: { left: 44, right: 16, top: 40, bottom: rounds.length > 8 ? 64 : 36 },
        tooltip: { trigger: 'axis' },
        legend: { top: 2, itemWidth: 12, itemHeight: 8, textStyle: { fontSize: 11 } },
        xAxis: { type: 'category', data: rounds.map(function (r) { return '第' + r.round + ROUND_LABEL; }),
                 axisLabel: { color: '#888', fontSize: 11, interval: 0, rotate: rounds.length > 8 ? 32 : 0 },
                 axisLine: { lineStyle: { color: '#dfe4f3' } }, axisTick: { show: false } },
        yAxis: { type: 'value', minInterval: 1, axisLabel: { color: '#888' }, splitLine: { lineStyle: { color: '#eef1f8' } } },
        series: ['A', 'B', 'C', 'D'].map(function (k) {
            return { name: k, type: isLine ? 'line' : 'bar',
                     data: rounds.map(function (r) { return r.counts[k] || 0; }),
                     itemStyle: { color: QUIZ_COLORS[k], borderRadius: isLine ? 0 : [3, 3, 0, 0] },
                     lineStyle: { width: 2.5 }, symbolSize: 7, barGap: '20%' };
        })
    };
}
// 点柱 / 拐点：显示该次该选项的答题人员（选项色标签：座号 + 姓名，点标签看个人变化）
function qsShowRoundList(rn, opt) {
    var d = qsCompare;
    if (!d) return;
    var rd = null;
    (d.rounds || []).forEach(function (r) { if (intval0(r.round) === intval0(rn)) rd = r; });
    if (!rd) return;
    qsRenderList('第' + rn + ROUND_LABEL + ' · 选项 ' + opt, opt, rd.rows || []);
}
function qsRenderList(title, opt, rows) {
    var list = (rows || []).filter(function (r) { return r.opt === opt; });
    qsRenderListColor(title, QUIZ_COLORS[opt], list, '暂无学生选择该选项');
}
// 名单标签渲染（color=标签底色）：座号 + 姓名，点标签看个人变化；
// 角点仅保留白点：左下白点=与上一题次选择不同，右下白点=与下一题次不同（相同或相邻次未答不显示；prev/next 由 quiz_stats_payload 提供）
function qsRenderListColor(title, color, list, emptyText) {
    list = list || [];
    document.getElementById('qsListTitle').textContent = title + '（' + list.length + ' 人）';
    document.getElementById('qsListTitle').style.color = color;
    var box = document.getElementById('qsListBody');
    box.innerHTML = list.length ? list.map(function (r) {
        var dots = '';
        if (r.prev && r.prev !== r.opt) dots += '<i class="qs-dot qs-dot-l" title="上一题选 ' + r.prev + '，与本题不同"></i>';
        if (r.next && r.next !== r.opt) dots += '<i class="qs-dot qs-dot-r" title="下一题选 ' + r.next + '，与本题不同"></i>';
        var tips = [];
        if (r.cls) tips.push(qsEsc(r.cls));
        if (r.prev) tips.push('上一题选 ' + r.prev + (r.prev !== r.opt ? '（与本题不同）' : ''));
        if (r.next) tips.push('下一题选 ' + r.next + (r.next !== r.opt ? '（与本题不同）' : ''));
        return '<span class="qs-tag" style="background:' + color + ';" title="' + tips.join('；') + '" onclick="openStuTrend(' + intval0(r.id) + ')">' + dots + qsEsc(r.seat !== '' ? r.seat + ' ' : '') + qsEsc(r.name) + '</span>';
    }).join('') : '<span style="color:#999;">' + (emptyText || '暂无学生') + '</span>';
    document.getElementById('qsList').style.display = '';
}

// ===== 正确率视图：每题答对情况（正确答案在本题统计中勾选；条形 Y 轴=答对人数，折线 Y 轴=正确率=答对人数÷答题人数） =====
function qsCorrectData() {
    return ((qsCompare && qsCompare.rounds) || []).map(function (r) {
        var opts = r.correct || [];
        var cnt = 0;
        opts.forEach(function (k) { cnt += (r.counts[k] || 0); });
        return { round: intval0(r.round), cnt: cnt, answered: r.answered || 0, hasAns: opts.length > 0, opts: opts, rows: r.rows || [] };
    });
}
function renderQuizCorrect() {
    var rounds = qsCorrectData();
    var isLine = qsChart === 'line';
    document.getElementById('qsRound').textContent = isLine ? '正确率 · 累计' : '正确人数 · 累计';
    var unset = rounds.filter(function (r) { return !r.hasAns; }).length;
    document.getElementById('qsSummary').textContent = rounds.length
        ? ('共 ' + rounds.length + ' 个' + ROUND_LABEL + ' · 纵轴=' + (isLine ? '正确率（答对人数÷答题人数）' : '答对人数')
            + (unset ? ' · ' + unset + ' 个' + ROUND_LABEL + '未设置正确答案（回「本题统计」在选项后勾选）' : '')
            + ' · 点击柱子 / 拐点查看答对名单')
        : '暂无' + ROUND_LABEL + '数据';
    document.getElementById('qsBars').style.display = 'none';
    var cv = document.getElementById('qsCanvas');
    cv.style.display = '';
    var labels = rounds.map(function (r) { return '第' + r.round + ROUND_LABEL; });
    var opt;
    if (isLine) {   // 正确率折线：纵轴=正确率（%）
        opt = { grid: { left: 48, right: 16, top: 30, bottom: rounds.length > 8 ? 64 : 36 },
                tooltip: { trigger: 'axis' },
                xAxis: { type: 'category', data: labels,
                         axisLabel: { color: '#888', fontSize: 11, interval: 0, rotate: rounds.length > 8 ? 32 : 0 },
                         axisLine: { lineStyle: { color: '#dfe4f3' } }, axisTick: { show: false } },
                yAxis: { type: 'value', max: 100, axisLabel: { color: '#888', formatter: '{value}%' }, splitLine: { lineStyle: { color: '#eef1f8' } } },
                series: [{ name: '正确率', type: 'line', data: rounds.map(function (r) { return r.answered > 0 ? Math.round(r.cnt / r.answered * 1000) / 10 : 0; }),
                           itemStyle: { color: CORRECT_COLOR }, lineStyle: { width: 2.5 }, symbolSize: 7,
                           label: { show: true, position: 'top', color: '#666', fontSize: 11, formatter: function (p) { return p.value + '%'; } } }] };
    } else {   // 答对人数柱形
        opt = ecBarOpt(labels, rounds.map(function (r) { return r.cnt; }), {colors: [CORRECT_COLOR], unit: ' 人'});
    }
    var ch = ecSet('qsCanvas', opt);
    if (ch) ch.on('click', function (p) {
        var r = rounds[p.dataIndex];
        if (r) qsShowCorrectList(r.round);
    });
    document.getElementById('qsList').style.display = 'none';
}
// 点正确率柱 / 拐点：该题答对名单（答案为正确选项之一的学生，绿色标签）
function qsShowCorrectList(rn) {
    var hit = null;
    qsCorrectData().forEach(function (r) { if (r.round === intval0(rn)) hit = r; });
    if (!hit) return;
    if (!hit.hasAns) { showToast('该' + ROUND_LABEL + '未设置正确答案，请回「本题统计」在选项后勾选', 'warn'); return; }
    var list = hit.rows.filter(function (row) { return hit.opts.indexOf(row.opt) >= 0; });
    qsRenderListColor('第' + rn + ROUND_LABEL + ' 答对（' + hit.opts.join('、') + '）', CORRECT_COLOR, list, '暂无学生答对');
}

// ===== 👥 分组 / 🕸 雷达 附加视图（正确口径同「正确率」视图：选项 ∈ 该次正确答案；仅统计已设置正确答案的题次） =====
function qsRoundTitle(rn) {
    var t = (qsCompare && qsCompare.titles) ? qsCompare.titles[rn] : null;
    return t ? qsEsc(String(t)) : ('第' + rn + ROUND_LABEL);
}
function qsToggleExtra(mode) {
    qsExtra = (qsExtra === mode) ? '' : mode;
    updateQsBtns();
    if (qsExtra === 'group') { if (qsCompare) renderQuizGroup(); else qsRefreshCompare(false); }
    else if (qsExtra === 'radar') { if (qsCompare) renderQuizRadar(); else qsRefreshCompare(false); }
    else if (qsExtra === 'comp') { if (quizStatsData) renderQuizStats(); else qsLoadRound(CURRENT_ROUND, false); }
    else if (qsMode === 'single') renderQuizStats();
    else if (qsMode === 'compare') renderQuizCompare();
    else renderQuizCorrect();
}
// 分组视图：按组聚合各题次答对/答题人数 → 正确率；组间平均正确率条形对比 + 分组卡片（点卡片/柱看组员名单）
function renderQuizGroup() {
    var d = qsCompare;
    var box = document.getElementById('qsBars');
    box.style.display = '';
    document.getElementById('qsCanvas').style.display = 'none';
    var rounds = (d.rounds || []).filter(function (r) { return (r.correct || []).length > 0; });
    document.getElementById('qsRound').textContent = '👥 分组 · 正确率';
    document.getElementById('qsList').style.display = 'none';
    if (!rounds.length) {
        document.getElementById('qsSummary').textContent = '暂无已设置正确答案的' + ROUND_LABEL + '，无法按分组统计（回「本题统计」在选项后勾选正确答案）';
        box.innerHTML = '';
        return;
    }
    var buckets = {};
    Object.keys(d.students || {}).forEach(function (sid) {
        var s = d.students[sid];
        (buckets[intval0(s.group_id) || 0] = buckets[intval0(s.group_id) || 0] || []).push(sid);
    });
    var order = (d.groups || []).map(function (g) { return { id: intval0(g.id), name: g.name }; });
    order.push({ id: 0, name: '未分组' });
    var cards = [];
    order.forEach(function (g) {
        var sids = buckets[g.id];
        if (!sids || !sids.length) return;
        var per = [];
        rounds.forEach(function (r) {
            var c = 0, a = 0;
            sids.forEach(function (sid) {
                var opt = ((d.students[sid].opts || {})[r.round]) || '';
                if (opt) { a++; if (r.correct.indexOf(opt) >= 0) c++; }
            });
            per.push({ round: intval0(r.round), c: c, a: a, rate: a ? Math.round(c / a * 1000) / 10 : null });
        });
        var rated = per.filter(function (p) { return p.rate !== null; });
        var avg = rated.length ? Math.round(rated.reduce(function (s, p) { return s + p.rate; }, 0) / rated.length * 10) / 10 : 0;
        cards.push({ id: g.id, name: g.name, sids: sids, per: per, avg: avg });
    });
    if (!cards.length) {
        document.getElementById('qsSummary').textContent = '暂无分组数据（学生名单中未设置分组）';
        box.innerHTML = '';
        return;
    }
    var sum = 0, n = 0;
    cards.forEach(function (c) { sum += c.avg; n++; });
    document.getElementById('qsSummary').textContent = '共 ' + cards.length + ' 个分组 · ' + n + ' 名已分组/未分组学生 · '
        + rounds.length + ' 个已设答案' + ROUND_LABEL + ' · 全班平均正确率 ' + (n ? Math.round(sum / n * 10) / 10 : 0) + '%（点卡片/柱查看组员名单）';
    var colors = ['#667eea', '#27ae60', '#f39c12', '#e74c3c', '#2980b9', '#8e44ad', '#16a085', '#d35400'];
    var h = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px;">';
    cards.forEach(function (c, i) {
        var col = colors[i % colors.length];
        h += '<div class="grp-card" onclick="qsShowGroupList(' + i + ')" title="点击查看组员名单">'
            + '<div style="display:flex;justify-content:space-between;align-items:center;"><b style="color:' + col + ';font-size:14px;">' + qsEsc(c.name) + '</b><span style="font-size:12px;color:#999;">' + c.sids.length + ' 人</span></div>'
            + '<div style="font-size:24px;font-weight:bold;color:#333;margin:2px 0;">' + c.avg + '%</div>'
            + '<div style="font-size:12px;color:#666;line-height:1.7;">' + c.per.map(function (p) {
                return '第' + p.round + ROUND_LABEL + ' ' + (p.rate === null ? '—' : p.c + '/' + p.a + '（' + p.rate + '%）');
            }).join('<br>') + '</div></div>';
    });
    h += '</div>';
    box.innerHTML = h;
    // 各组平均正确率对比（ECharts 单图区，点柱看组员名单）
    var cv = document.getElementById('qsCanvas');
    cv.style.display = '';
    var ch = ecSet('qsCanvas', ecBarOpt(cards.map(function (c) { return c.name; }), cards.map(function (c) { return c.avg; }),
        {colors: cards.map(function (c, i) { return colors[i % colors.length]; }), unit: '%'}));
    if (ch) ch.on('click', function (p) { qsShowGroupList(p.dataIndex); });
    qsGroupCards = cards;
}
var qsGroupCards = [];
function qsShowGroupList(i) {
    var c = qsGroupCards[i];
    if (!c) return;
    var d = qsCompare;
    var list = c.sids.map(function (sid) {
        var s = d.students[sid];
        return { id: intval0(sid), name: s.name, seat: s.seat, cls: s.cls };
    });
    list.sort(function (a, b) { return (parseInt(a.seat, 10) || 9999) - (parseInt(b.seat, 10) || 9999); });
    qsRenderListColor(qsEsc(c.name) + '（平均正确率 ' + c.avg + '%）', '#667eea', list, '暂无组员');
}
// 雷达视图：学生标签点选 → 各题次正确率雷达（个人 100/0 + 全班平均答对率叠加；仅已设答案题次，轴数<3 提示）
function renderQuizRadar() {
    var d = qsCompare;
    var box = document.getElementById('qsBars');
    box.style.display = '';
    document.getElementById('qsCanvas').style.display = 'none';
    var rounds = (d.rounds || []).filter(function (r) { return (r.correct || []).length > 0; });
    document.getElementById('qsRound').textContent = '🕸 雷达 · 正确率';
    document.getElementById('qsList').style.display = 'none';
    if (rounds.length < 2) {
        document.getElementById('qsSummary').textContent = '已设正确答案的' + ROUND_LABEL + '不足 2 个，无法绘制正确率雷达（维度=总正确率 + 各题次，需 ≥3 维；回「本题统计」在选项后勾选正确答案）';
        box.innerHTML = '';
        return;
    }
    document.getElementById('qsSummary').textContent = '点学生标签叠加个人雷达（可多选，再点取消，最多 8 人）· 共 ' + (rounds.length + 1) + ' 个维度（总正确率 + 已设答案' + ROUND_LABEL + '）';
    var tags = Object.keys(d.students || {}).map(function (sid) {
        var s = d.students[sid];
        return '<span class="stu-tag' + (qsRadarSel[sid] ? ' on' : '') + '" onclick="qsPickRadarStu(' + intval0(sid) + ')">'
            + qsEsc(String(s.seat) !== '' ? s.seat + ' ' : '') + qsEsc(s.name) + '</span>';
    }).join('');
    var selCnt = Object.keys(qsRadarSel).length;
    box.innerHTML = '<div style="line-height:2;max-height:132px;overflow:auto;">' + (tags || '<span style="color:#999;">暂无学生</span>') + '</div>'
        + '<div style="line-height:2;font-size:13px;">已选 ' + selCnt + ' 人'
        + (selCnt ? ' <a href="javascript:void(0)" style="color:#e74c3c;font-size:13px;margin-left:8px;" onclick="qsRadarSel={};renderQuizRadar()">清空选择</a>' : '')
        + '</div>'
        + '<div id="qsRadarBody" style="margin-top:8px;"></div>';
    renderQsRadarBody();
}
function qsPickRadarStu(sid) {
    if (qsRadarSel[sid]) { delete qsRadarSel[sid]; }
    else {
        if (Object.keys(qsRadarSel).length >= 8) { showToast('最多叠加 8 名学生的雷达图', 'warn'); return; }
        qsRadarSel[sid] = true;
    }
    renderQuizRadar();
}
function renderQsRadarBody() {
    var body = document.getElementById('qsRadarBody');
    if (!body) return;
    var d = qsCompare;
    var rounds = (d.rounds || []).filter(function (r) { return (r.correct || []).length > 0; });
    if (rounds.length < 2) return;
    // 维度：总正确率 + 各已设答案题次（保证 ≥3 维：2 个题次也能绘制）
    var dims = [{ label: '总正确率' }].concat(rounds.map(function (r) { return { label: qsRoundTitle(r.round) }; }));
    var cv = rounds.map(function (r) {
        var cnt = 0;
        r.correct.forEach(function (k) { cnt += (r.counts[k] || 0); });
        return r.answered > 0 ? Math.round(cnt / r.answered * 1000) / 10 : null;
    });
    var gcnt = 0, gans = 0;
    rounds.forEach(function (r, i) { if (cv[i] !== null) { var cnt = 0; r.correct.forEach(function (k) { cnt += (r.counts[k] || 0); }); gcnt += cnt; gans += r.answered; } });
    var series = [{ name: '全班平均', color: '#f39c12', values: [gans > 0 ? Math.round(gcnt / gans * 1000) / 10 : null].concat(cv) }];
    Object.keys(qsRadarSel).forEach(function (sid, i) {
        var stu = (d.students || {})[sid];
        if (!stu) return;
        var ans = 0, ok = 0;
        var sv0 = rounds.map(function (r) {
            var opt = (stu.opts || {})[r.round];
            if (!opt) return null;
            ans++;
            if (r.correct.indexOf(opt) >= 0) { ok++; return 100; }
            return 0;
        });
        var sv = [ans > 0 ? Math.round(ok / ans * 1000) / 10 : null].concat(sv0);
        series.push({ name: stu.name + (String(stu.seat) !== '' ? '（' + stu.seat + '）' : ''), color: P_COLORS[i % P_COLORS.length], values: sv });
    });
    body.innerHTML = radarLegend(series)
        + radarSvg(dims, series)
        + '<div style="text-align:center;font-size:12px;color:#999;margin-top:4px;">轴值：个人=该' + ROUND_LABEL + '是否答对（对 100 / 错 0），总正确率=个人答对次数÷已答次数；全班平均=答对人数÷答题人数（总轴=加权汇总）</div>'
        + (Object.keys(qsRadarSel).length ? '' : '<div style="text-align:center;font-size:12px;color:#999;">点上方学生姓名可叠加个人雷达图（可多选）</div>');
}

function qsEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

// 点学生标签：该生多次答题变化统计图（折线：横轴=题次，纵轴=选项）；stHide=被隐藏的题次（点标签切换显隐）
var stHide = {};   // round_no => true（已隐藏）
var stSid = 0;     // 当前查看的学生 id
function openStuTrend(sid) {
    var open = function () { stHide = {}; stSid = sid; renderStuTrend(sid); document.getElementById('stuTrendModal').classList.add('show'); };
    if (qsCompare) { open(); return; }
    fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'type=quiz_compare&project_id=' + PROJECT_ID
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (!d || !d.success) { showToast((d && d.message) || '加载失败', 'error'); return; }
        qsCompare = d;
        open();
    })
    .catch(function () { showToast('网络错误', 'error'); });
}
// 🕸 跳转答题统计雷达视图并叠加该生（可再叠加其他学生）
function openStuRadar() {
    if (!qsCompare) { showToast('数据尚未加载完成，请稍后再试', 'warn'); return; }
    document.getElementById('stuTrendModal').classList.remove('show');
    qsRadarSel = {};
    qsRadarSel[stSid] = true;
    qsExtra = 'radar';
    if (qsMode === 'single') qsMode = 'compare';   // 雷达依赖累计数据
    document.getElementById('quizStatsModal').classList.add('show');
    updateQsBtns();
    renderQuizRadar();
}
// 点题次标签：隐藏 / 还原该次数据（上方圆点与连线同步显隐）；当前查看的题次不可隐藏
function stToggleRound(rn) {
    if (intval0(rn) === intval0(qsRound)) { showToast('当前正在查看的题次不能隐藏', 'warn'); return; }
    if (stHide[rn]) delete stHide[rn]; else stHide[rn] = true;
    renderStuTrend(stSid);
}
function renderStuTrend(sid) {
    var d = qsCompare;
    if (!d) return;
    stSid = sid;
    var stu = (d.students || {})[sid];
    if (!stu) { showToast('未找到该学生的答题记录', 'warn'); return; }
    var rounds = d.rounds || [];
    document.getElementById('stTitle').textContent = '📈 ' + stu.name + ' 答题变化' + (stu.seat !== '' ? '（' + stu.seat + '）' : '');
    var answered = 0;
    var seq = rounds.map(function (r) {
        var opt = (stu.opts && stu.opts[r.round]) || '';
        if (opt) answered++;
        var cur = intval0(r.round) === intval0(qsRound);   // 当前查看的题次：不可隐藏
        var off = !!stHide[r.round];
        var style = off ? 'background:#e4e4e4;color:#aaa;text-decoration:line-through;' : (opt ? 'background:' + QUIZ_COLORS[opt] + ';' : 'background:#f0f2f5;color:#999;');
        var tag = '<span class="qs-tag" style="' + style + (cur ? 'box-shadow:0 0 0 2px #667eea inset;' : '')
            + 'cursor:' + (cur ? 'not-allowed' : 'pointer') + ';"'
            + (cur ? ' title="当前查看的题次（不能隐藏）"'
                   : ' title="' + (off ? '已隐藏，点击还原该次数据' : '点击隐藏该次数据') + '" onclick="stToggleRound(' + intval0(r.round) + ')')
            + '>第' + r.round + ROUND_LABEL + '：' + (opt || '未答') + '</span>';
        return tag;
    }).join('');
    var hidCnt = Object.keys(stHide).length;
    document.getElementById('stSummary').textContent = rounds.length
        ? ('共 ' + rounds.length + ' 个' + ROUND_LABEL + '，已答 ' + answered + ' 个' + (hidCnt ? '，已隐藏 ' + hidCnt + ' 次' : ''))
        : '暂无' + ROUND_LABEL + '数据';
    document.getElementById('stSeq').innerHTML = seq || '<span style="color:#999;">暂无答题记录</span>';
    document.getElementById('stChart').innerHTML = stuTrendSvg(stu, rounds);
}
function stuTrendSvg(stu, rounds) {
    rounds = (rounds || []).filter(function (r) { return !stHide[r.round]; });   // 隐藏的题次不参与绘制
    if (!rounds.length) return '<div style="color:#999;font-size:13px;padding:18px 0;text-align:center;">题次数据均已隐藏，点击下方标签还原</div>';
    var W = Math.max(320, rounds.length * 70 + 50), H = 230, L = 36, R = 16, T = 18, B = 34;
    var iw = W - L - R, ih = H - T - B;
    var lv = { A: 3, B: 2, C: 1, D: 0 };
    function X(i) { return rounds.length === 1 ? L + iw / 2 : L + iw * i / (rounds.length - 1); }
    function Yp(k) { return T + ih * (3 - lv[k]) / 3; }
    var s = '<svg width="' + W + '" height="' + H + '" style="display:block;">';
    s += '<line x1="' + L + '" y1="' + (T + ih) + '" x2="' + (W - R) + '" y2="' + (T + ih) + '" stroke="#dfe4f3" stroke-width="2"/>';
    ['A', 'B', 'C', 'D'].forEach(function (k) {
        s += '<line x1="' + L + '" y1="' + Yp(k) + '" x2="' + (W - R) + '" y2="' + Yp(k) + '" stroke="#eef1f8" stroke-width="1"/>';
        s += '<text x="' + (L - 10) + '" y="' + (Yp(k) + 4) + '" font-size="12" font-weight="bold" fill="' + QUIZ_COLORS[k] + '" text-anchor="end">' + k + '</text>';
    });
    rounds.forEach(function (r, i) {
        s += '<text x="' + X(i) + '" y="' + (H - 12) + '" font-size="11" fill="#888" text-anchor="middle">第' + r.round + ROUND_LABEL + '</text>';
    });
    var pts = [];
    rounds.forEach(function (r, i) {
        var opt = (stu.opts && stu.opts[r.round]) || '';
        if (opt) pts.push([X(i), Yp(opt), opt, r]);
    });
    if (pts.length > 1) s += '<polyline points="' + pts.map(function (p) { return p[0] + ',' + p[1]; }).join(' ') + '" fill="none" stroke="#9aa5d0" stroke-width="2" stroke-dasharray="5 3"/>';
    pts.forEach(function (p) {
        s += '<circle cx="' + p[0] + '" cy="' + p[1] + '" r="7" fill="' + QUIZ_COLORS[p[2]] + '" stroke="#fff" stroke-width="2"><title>第' + p[3].round + ROUND_LABEL + ' 选 ' + p[2] + '</title></circle>' +
             '<text x="' + p[0] + '" y="' + (p[1] - 11) + '" font-size="12" font-weight="bold" fill="' + QUIZ_COLORS[p[2]] + '" text-anchor="middle">' + p[2] + '</text>';
    });
    s += '</svg>';
    return s;
}
function intval0(v) { return parseInt(v, 10) || 0; }

// ===== 答题卡成绩统计（📊 omr_stats）：平均分 / 优秀率 / 良好率 / 及格率 / 低分率 / 分段统计（每10分一段）
//       均含人数与占全班比例；ECharts 分数段柱图 + 平均得分率走势折线 + 四象限散点；点图元→名单，点姓名→历次增减；导出成绩报表 Excel =====
var OSD = null;        // omr_stats 数据缓存 {class, rounds, students, total_class, scored}
var osRound = 'all';   // 'all'=全部题次汇总（Σ得分÷Σ满分）或题次号字符串（打开时默认当前题次）
var osDim = 'seg';     // 全班视图统计维度：seg=分数段 grade=等级构成 trend=走势 scatter=四象限 cmp=对比（一页一图）
var osType = 'bar';    // 分数段维度图形：bar=柱形 pie=饼图（答题统计共用）
var osMode = 'score';  // 统计模式：score=成绩统计 quest=答题统计（按题）
var osQView = 'rate';  // 答题统计子维度：rate=各题正确率 dist=单题选项分布
var osQNo = 1;         // 答题统计·选项分布当前题号
var OQD = null;        // omr_questions 数据缓存 {round, total_class, graded, questions}
var SEG_COLORS = ['#667eea', '#27ae60', '#f39c12', '#e74c3c', '#2980b9', '#8e44ad', '#16a085', '#d35400', '#2ecc71', '#e67e22'];
// ===== ECharts 实例管理（弹层关闭/重渲染时释放，窗口缩放自适应） =====
var OSC_CH = {};       // omrStatsModal 图表 {cmpstu（个人视图多人对比）}
var OSM_CH = {};       // omrStuModal 图表 {trend, radar}
function osDisposeCharts() {
    ecDel('osCanvas'); ecDel('osGrpCanvas');
    Object.keys(OSC_CH).forEach(function (k) { if (OSC_CH[k]) { OSC_CH[k].dispose(); OSC_CH[k] = null; } });
    Object.keys(OSM_CH).forEach(function (k) { if (OSM_CH[k]) { OSM_CH[k].dispose(); OSM_CH[k] = null; } });
}
function ecInit(id, store, key) {
    var el = document.getElementById(id);
    if (!el || typeof echarts === 'undefined') return null;
    if (store[key]) store[key].dispose();
    store[key] = echarts.init(el);
    return store[key];
}
window.addEventListener('resize', function () {
    Object.keys(OSC_CH).forEach(function (k) { if (OSC_CH[k]) OSC_CH[k].resize(); });
    Object.keys(OSM_CH).forEach(function (k) { if (OSM_CH[k]) OSM_CH[k].resize(); });
});
function osLoadData(cb) {
    fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'type=omr_stats&project_id=' + PROJECT_ID + '&cls_id=' + intval0(PV_CLASS_ID) })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.success) { showToast((d && d.message) || '加载失败', 'error'); return; }
            OSD = d;
            osView = 'class'; osCmpSel = {}; osPersons = {};   // 重开回到全班视图
            osDim = 'seg'; osType = 'bar';
            // 默认统计范围=当前题次（单次）；当前题次不存在时回退全部题次汇总
            var hasCur = (d.rounds || []).some(function (r) { return String(r.no) === String(CURRENT_ROUND); });
            osRound = hasCur ? String(CURRENT_ROUND) : 'all';
            var sel = document.getElementById('osRoundSel');
            sel.innerHTML = '<option value="all">全部题次汇总</option>' + (d.rounds || []).map(function (r) {
                return '<option value="' + r.no + '">' + (r.title ? '第' + r.no + ROUND_LABEL + '：' + qsEsc(r.title) : '第' + r.no + ROUND_LABEL) + '</option>';
            }).join('');
            sel.value = osRound;
            if (cb) cb();
        })
        .catch(function () { showToast('网络错误', 'error'); });
}
function openOmrStats() {
    osMode = 'score'; osQView = 'rate'; osQNo = 1; OQD = null;   // 重开回到成绩统计
    osLoadData(function () {
        document.getElementById('osStuModal').classList.remove('show');
        document.getElementById('omrStatsModal').classList.add('show');
        osRender();
    });
}
function closeOmrStats() {
    document.getElementById('omrStatsModal').classList.remove('show');
    document.getElementById('osStuModal').classList.remove('show');
    osDisposeCharts();   // 关弹层释放 ECharts 实例
}
function osSwitchRound(v) {
    osRound = (v === 'all') ? 'all' : String(intval0(v));
    if (osMode === 'quest') { OQD = null; osLoadQuestions(); } else osRender();   // 答题统计按题取数：切范围重新拉取
}
// 当前统计范围下的已批改学生行 [{stu, g, t, rate}]（rate=得分率%；全部题次=Σg÷Σt；未批改学生不在此列，但计入全班分母）
function osRows() {
    var rows = [];
    (OSD.students || []).forEach(function (s) {
        if (osRound === 'all') {
            var g = 0, t = 0;
            Object.keys(s.scores || {}).forEach(function (rn) { g += s.scores[rn].g; t += s.scores[rn].t; });
            if (t > 0) rows.push({ stu: s, g: g, t: t, rate: Math.round(g / t * 1000) / 10 });
        } else {
            var sc = (s.scores || {})[osRound];
            if (sc && sc.t > 0) rows.push({ stu: s, g: sc.g, t: sc.t, rate: Math.round(sc.g / sc.t * 1000) / 10 });
        }
    });
    return rows;
}
function osPct(cnt) { return OSD.total_class > 0 ? (Math.round(cnt / OSD.total_class * 1000) / 10) + '%' : '-'; }
function osRender() {
    if (!OSD) return;
    document.getElementById('osClass').textContent = OSD.class.name;
    var _omb = document.getElementById('osModeBtn');   // 文案=当前模式（亮起=正在显示的统计），点击切换
    if (_omb) {
        _omb.textContent = (osMode === 'quest') ? '📝 答题统计' : '💯 成绩统计';
        _omb.classList.add('on');
        _omb.title = (osMode === 'quest') ? '当前统计：答题统计（按题正确率与选项分布）；点击切换到成绩统计' : '当前统计：成绩统计；点击切换到答题统计（按题正确率与选项分布）';
    }
    if (osMode === 'quest') {   // 答题统计：隐藏视图页签/成绩维度排，显示按题维度排（一页一图）
        osView = 'class';
        document.getElementById('osViewBar').style.display = 'none';
        document.getElementById('osQBar').style.display = 'flex';
        document.getElementById('osDimBar').style.display = 'none';
        document.getElementById('osTabClass').classList.toggle('on', true);
        document.getElementById('osTabGroup').classList.toggle('on', false);
        document.getElementById('osTabPerson').classList.toggle('on', false);
        document.getElementById('osClassView').style.display = '';
        document.getElementById('osGroupView').style.display = 'none';
        document.getElementById('osPersonView').style.display = 'none';
        document.getElementById('osCmpBox').style.display = 'none';
        document.getElementById('osRates').style.display = 'none';
        document.getElementById('osSummary').textContent = OQD
            ? '全班 ' + OQD.total_class + ' 人 · 已批改 ' + OQD.graded + ' 人 · 共 ' + (OQD.questions || []).length + ' 题（' + (osRound === 'all' ? '全部题次汇总' : '第' + osRound + ROUND_LABEL) + ' · 正确率=答对÷已答；点柱子 / 扇区查看名单）'
            : '答题统计加载中…';
        ['Rate', 'Dist'].forEach(function (k) {
            var b = document.getElementById('osQ' + k);
            if (b) b.classList.toggle('on', osQView === k.toLowerCase());
        });
        var qsel = document.getElementById('osQSel');
        qsel.style.display = osQView === 'dist' ? '' : 'none';
        if (osQView === 'dist') {
            qsel.innerHTML = (OQD ? OQD.questions : []).map(function (q) { return '<option value="' + q.qno + '">第' + q.qno + '题</option>'; }).join('');
            qsel.value = String(osQNo);
        }
        document.getElementById('osQTypeBtn').textContent = osType === 'bar' ? '🥧 饼图' : '📊 柱形图';
        if (OQD) osRenderQ(); else document.getElementById('osCanvas').innerHTML = '';
        return;
    }
    // 三视图切换：页签态 + 容器显隐；维度按钮排仅全班视图显示（一页一图）
    document.getElementById('osTabClass').classList.toggle('on', osView === 'class');
    document.getElementById('osTabGroup').classList.toggle('on', osView === 'group');
    document.getElementById('osTabPerson').classList.toggle('on', osView === 'person');
    document.getElementById('osClassView').style.display = osView === 'class' ? '' : 'none';
    document.getElementById('osGroupView').style.display = osView === 'group' ? '' : 'none';
    document.getElementById('osPersonView').style.display = osView === 'person' ? '' : 'none';
    document.getElementById('osDimBar').style.display = osView === 'class' ? 'flex' : 'none';
    if (osView === 'group') { osRenderGroup(); return; }
    if (osView === 'person') { osRenderPerson(); return; }
    // 维度按钮态 + 柱/饼切换按钮（仅分数段维度可切换图形）
    ['Seg', 'Grade', 'Trend', 'Scatter', 'Cmp'].forEach(function (k) {
        document.getElementById('osDim' + k).classList.toggle('on', osDim === k.toLowerCase());
    });
    var typeBtn = document.getElementById('osTypeBtn');
    typeBtn.style.display = osDim === 'seg' ? '' : 'none';
    typeBtn.textContent = osType === 'bar' ? '🥧 饼图' : '📊 柱形图';
    document.getElementById('osClass').textContent = OSD.class.name;
    var rows = osRows();
    var graded = rows.length, sumRate = 0, sumG = 0;
    rows.forEach(function (r) { sumRate += r.rate; sumG += r.g; });
    var avgRate = graded ? Math.round(sumRate / graded * 10) / 10 : 0;
    var avgScore = graded ? Math.round(sumG / graded * 10) / 10 : 0;
    // 口径自适应：scored=绑定模板任一题设分值 → 按「分数」呈现；否则按「正确题数」（正确率=对题数比例）
    var rateName = OSD.scored ? '得分率' : '正确率';
    var avgTxt;
    if (osRound === 'all') {
        avgTxt = '平均' + rateName + ' ' + avgRate + '%';
    } else {
        var full = rows.length ? rows[0].t : 0;
        avgTxt = (OSD.scored ? '平均分 ' : '平均正确 ') + avgScore + (full ? ' / ' + full : '') + '（平均' + rateName + ' ' + avgRate + '%）';
    }
    document.getElementById('osSummary').textContent =
        '全班 ' + OSD.total_class + ' 人 · 已批改 ' + graded + ' 人 · ' + avgTxt
        + '（统计口径：' + rateName + '=得分÷满分×100；点柱子 / 扇区 / 拐点看名单，点姓名看历次变化）';
    // 分段统计（每 10 分一段：0-9 … 90-100）
    var segs = [];
    for (var i = 0; i < 10; i++) {
        var lo = i * 10;
        segs.push({ label: i === 9 ? '90-100' : lo + '-' + (lo + 9),
                    list: rows.filter(function (r) { return r.rate >= lo && (i === 9 || r.rate < lo + 10); }) });
    }
    // 级别统计（人数 + 占全班比例，点击呈现名单）
    var rateDefs = [
        { name: '优秀率（≥80%）', color: '#27ae60', list: rows.filter(function (r) { return r.rate >= 80; }) },
        { name: '良好率（70%-80%）', color: '#2980b9', list: rows.filter(function (r) { return r.rate >= 70 && r.rate < 80; }) },
        { name: '及格率（≥60%）', color: '#f39c12', list: rows.filter(function (r) { return r.rate >= 60; }) },
        { name: '低分率（≤30%）', color: '#e74c3c', list: rows.filter(function (r) { return r.rate <= 30; }) }
    ];
    document.getElementById('osRates').innerHTML = rateDefs.map(function (rd, idx) {
        return '<div title="点击查看名单" onclick="osShowListIdx(' + idx + ')" style="flex:1;min-width:150px;background:' + rd.color + ';color:#fff;border-radius:10px;padding:10px 12px;cursor:pointer;">'
            + '<div style="font-size:13px;opacity:.9;">' + rd.name + '</div>'
            + '<div style="font-size:22px;font-weight:bold;">' + rd.list.length + ' 人</div>'
            + '<div style="font-size:12px;opacity:.9;">占全班 ' + osPct(rd.list.length) + '</div></div>';
    }).join('');
    document.getElementById('osRates').style.display = (osDim === 'seg' || osDim === 'grade') ? 'flex' : 'none';
    // 等级构成名单（互斥口径：优秀≥80 / 良好70-80 / 及格60-70 / 不及格<60 / 未批改），供扇区点击下钻
    var gradedIds = {};
    rows.forEach(function (r) { gradedIds[r.stu.id] = true; });
    OSD._gradeLists = [
        { name: '优秀 ≥80%', color: '#27ae60', list: rows.filter(function (r) { return r.rate >= 80; }) },
        { name: '良好 70-80%', color: '#2980b9', list: rows.filter(function (r) { return r.rate >= 70 && r.rate < 80; }) },
        { name: '及格 60-70%', color: '#f39c12', list: rows.filter(function (r) { return r.rate >= 60 && r.rate < 70; }) },
        { name: '不及格 <60%', color: '#e74c3c', list: rows.filter(function (r) { return r.rate < 60; }) },
        { name: '未批改', color: '#95a5a6', list: (OSD.students || []).filter(function (s) { return !gradedIds[s.id]; }) }
    ];
    // 走势数据（各题次全班平均率）
    var trend = (OSD.rounds || []).map(function (r) {
        var g = 0, t = 0, n = 0;
        (OSD.students || []).forEach(function (s) {
            var sc = (s.scores || {})[r.no];
            if (sc && sc.t > 0) { g += sc.g; t += sc.t; n++; }
        });
        return { no: r.no, title: r.title, rate: t > 0 ? Math.round(g / t * 1000) / 10 : null, n: n };
    });
    // ☑ 对比勾选区（仅对比维度显示；图形渲染进单图区）
    var cmpBox = document.getElementById('osCmpBox');
    cmpBox.style.display = osDim === 'cmp' ? '' : 'none';
    if (osDim === 'cmp') cmpBox.innerHTML = osCompareHtml();
    // 一页一图：按维度派发到单图区 osCanvas
    if (osDim === 'grade') osChartGrade(graded);
    else if (osDim === 'trend') osChartTrend(trend);
    else if (osDim === 'scatter') osChartScatter();
    else if (osDim === 'cmp') osChartCmp();
    else osChartSeg(segs);
    document.getElementById('osList').style.display = 'none';
    OSD._rateDefs = rateDefs; OSD._segs = segs; OSD._trend = trend; OSD._rows = rows;   // 名单下钻与导出复用
}
// 分数段分布（ECharts 单图区）：默认柱形（每10%一段，柱=人数），可切饼图（各段占比）；点图元看名单
function osChartSeg(segs) {
    var labels = segs.map(function (s) { return s.label; });
    var values = segs.map(function (s) { return s.list.length; });
    var ch;
    if (osType === 'pie') {
        var items = segs.map(function (s, i) { return { name: s.label, value: s.list.length, color: SEG_COLORS[i % SEG_COLORS.length] }; });
        ch = ecSet('osCanvas', ecPieOpt(items, { unit: '人', centerMain: String((OSD._rows || []).length), centerSub: '已批改' }));
    } else {
        ch = ecSet('osCanvas', ecBarOpt(labels, values, { unit: ' 人' }));
    }
    if (!ch) { document.getElementById('osCanvas').innerHTML = ''; return; }
    ch.off('click');
    ch.on('click', function (p) { if (p.dataIndex != null) osShowSeg(p.dataIndex); });
}
// 等级构成环形饼图（ECharts 单图区）：优秀/良好/及格/不及格/未批改，中心=已批改/全班；点扇区看名单
function osChartGrade(graded) {
    var items = (OSD._gradeLists || []).map(function (g) { return { name: g.name, value: g.list.length, color: g.color }; });
    var ch = ecSet('osCanvas', ecPieOpt(items, { unit: '人', centerMain: graded + '/' + OSD.total_class, centerSub: '已批改/全班' }));
    if (!ch) return;
    ch.off('click');
    ch.on('click', function (p) {
        var g = (OSD._gradeLists || [])[p.dataIndex];
        if (g) osShowList(g.name, g.color, g.list);
    });
}
// 各题次平均率走势折线（ECharts 单图区）：点拐点看该题次已批改名单；题次多时滑块缩放
function osChartTrend(trend) {
    var labels = trend.map(function (r) { return r.title ? r.title : '第' + r.no + ROUND_LABEL; });
    var ch = ecSet('osCanvas', {
        grid: { left: 44, right: 20, top: 26, bottom: trend.length > 8 ? 52 : 36 },
        tooltip: { trigger: 'axis', formatter: function (ps) { var r = trend[ps[0].dataIndex]; return labels[ps[0].dataIndex] + '<br>平均' + (OSD.scored ? '得分率' : '正确率') + '：' + (r.rate === null ? '暂无' : r.rate + '%') + '（已批改 ' + r.n + ' 人）'; } },
        xAxis: { type: 'category', data: labels, axisLabel: { color: '#888', fontSize: 11, rotate: trend.length > 6 ? 22 : 0, interval: 0 }, boundaryGap: true },
        yAxis: { type: 'value', max: 100, axisLabel: { color: '#888', formatter: '{value}%' }, splitLine: { lineStyle: { color: '#eef1f8' } } },
        dataZoom: trend.length > 8 ? [{ type: 'slider', height: 16, bottom: 6 }] : [],
        series: [{ type: 'line', data: trend.map(function (r) { return r.rate; }), connectNulls: true,
                   lineStyle: { color: '#667eea', width: 2.5 }, itemStyle: { color: '#667eea', borderColor: '#fff', borderWidth: 2 },
                   symbolSize: 9, label: { show: true, position: 'top', color: '#666', fontSize: 11, formatter: function (p) { return p.value === null ? '' : p.value + '%'; } } }]
    });
    if (!ch) return;
    ch.off('click');
    ch.on('click', function (p) {
        if (p.dataIndex != null && trend[p.dataIndex] && trend[p.dataIndex].rate !== null) osShowTrendList(trend[p.dataIndex].no);
    });
}
// 四象限散点（ECharts 单图区）：X=平均得分率（竖虚线=全班均值），Y=首末次提升幅度（横线=0）；点气泡看该生历次变化
function osChartScatter() {
    var rows = OSD._rows || [];
    var cSum = 0;
    rows.forEach(function (r) { cSum += r.rate; });
    var clsAvg = rows.length ? Math.round(cSum / rows.length * 10) / 10 : 0;
    var pts = [];
    rows.forEach(function (r) {
        var hist = (OSD.rounds || []).map(function (rd) {
            var sc = r.stu.scores[rd.no];
            return sc && sc.t > 0 ? Math.round(sc.g / sc.t * 1000) / 10 : null;
        }).filter(function (v) { return v !== null; });
        var gain = hist.length > 1 ? Math.round((hist[hist.length - 1] - hist[0]) * 10) / 10 : 0;
        pts.push({ name: (String(r.stu.seat_no) !== '' ? r.stu.seat_no + ' ' : '') + r.stu.name, value: [r.rate, gain], sid: r.stu.id });
    });
    var opt;
    if (!pts.length) {
        opt = { title: { text: '暂无已批改成绩', left: 'center', top: 'middle', textStyle: { color: '#999', fontSize: 13 } } };
    } else {
        opt = {
            grid: { left: 48, right: 24, top: 24, bottom: 40 },
            tooltip: { formatter: function (p) { return p.name + '<br>平均' + (OSD.scored ? '得分率' : '正确率') + '：' + p.value[0] + '%<br>首末提升：' + (p.value[1] > 0 ? '+' : '') + p.value[1] + '%'; } },
            xAxis: { type: 'value', name: (OSD.scored ? '得分率' : '正确率') + '(%)', nameTextStyle: { color: '#888' }, max: 100, splitLine: { lineStyle: { color: '#eef1f8' } }, axisLabel: { color: '#888' } },
            yAxis: { type: 'value', name: '提升(%)', nameTextStyle: { color: '#888' }, splitLine: { lineStyle: { color: '#eef1f8' } }, axisLabel: { color: '#888' } },
            series: [{ type: 'scatter', symbolSize: 14, data: pts, itemStyle: { color: '#667eea', opacity: .75 },
                       markLine: { silent: true, symbol: 'none', lineStyle: { color: '#f39c12', type: 'dashed' },
                                   data: [{ xAxis: clsAvg, label: { formatter: '全班 ' + clsAvg + '%', color: '#f39c12' } }, { yAxis: 0, label: { show: false } }] } }]
        };
    }
    var ch = ecSet('osCanvas', opt);
    if (!ch) return;
    ch.off('click');
    ch.on('click', function (p) { if (p.data && p.data.sid) openOsStu(p.data.sid); });
}
// 名单下钻：标签=座号+姓名+得分率（点标签看历次变化）
function osShowList(title, color, list) {
    list = list || [];
    document.getElementById('osListTitle').textContent = title + '（' + list.length + ' 人）';
    document.getElementById('osListTitle').style.color = color;
    document.getElementById('osListBody').innerHTML = list.length ? list.map(function (r) {
        return '<span class="qs-tag" style="background:' + color + ';" title="得分率 ' + r.rate + '%（' + r.g + '/' + r.t + '），点击查看历次变化" onclick="openOsStu(' + intval0(r.stu.id) + ')">'
            + qsEsc(String(r.stu.seat_no) !== '' ? r.stu.seat_no + ' ' : '') + qsEsc(r.stu.name) + ' ' + r.rate + '%</span>';
    }).join('') : '<span style="color:#999;">暂无学生</span>';
    document.getElementById('osList').style.display = '';
}
function osShowListIdx(idx) {
    var rd = OSD._rateDefs[idx];
    if (rd) osShowList(rd.name, rd.color, rd.list);
}
function osShowSeg(i) {
    var sg = OSD._segs[i];
    if (sg) osShowList('得分率 ' + sg.label, '#667eea', sg.list);
}
function osShowTrendList(rno) {
    var rows = (OSD._rows || []).filter(function (r) { return r.stu.scores && r.stu.scores[rno]; });
    var rd = (OSD.rounds || []).filter(function (r) { return intval0(r.no) === intval0(rno); })[0];
    osShowList((rd && rd.title ? qsEsc(rd.title) : '第' + rno + ROUND_LABEL) + ' 已批改名单', '#667eea', rows);
}
// 点姓名：该生历次得分变化（折线 + 序列，含与上一次的增减）
function openOsStu(sid) {
    if (!OSD) return;
    var stu = null;
    (OSD.students || []).forEach(function (s) { if (intval0(s.id) === sid) stu = s; });
    if (!stu) return;
    var hist = (OSD.rounds || []).map(function (r) {
        var sc = (stu.scores || {})[r.no];
        return (sc && sc.t > 0) ? { no: r.no, title: r.title, g: sc.g, t: sc.t, rate: Math.round(sc.g / sc.t * 1000) / 10 } : null;
    }).filter(Boolean);
    document.getElementById('osStuTitle').textContent = '📈 ' + stu.name + ' 得分变化' + (String(stu.seat_no) !== '' ? '（' + stu.seat_no + '）' : '');
    document.getElementById('osStuSummary').textContent = hist.length
        ? ('已批改 ' + hist.length + ' / ' + (OSD.rounds || []).length + ' 个' + ROUND_LABEL + ' · 最新得分率 ' + hist[hist.length - 1].rate + '%')
        : '暂无已批改成绩记录';
    document.getElementById('osStuChart').innerHTML = osStuTrendSvg(hist);
    var prev = null;
    document.getElementById('osStuSeq').innerHTML = hist.map(function (h) {
        var delta = '';
        if (prev !== null) delta = h.rate > prev ? ' <span style="color:#27ae60;">↑ +' + (Math.round((h.rate - prev) * 10) / 10) + '</span>'
            : (h.rate < prev ? ' <span style="color:#e74c3c;">↓ ' + (Math.round((h.rate - prev) * 10) / 10) + '</span>' : ' <span style="color:#999;">—</span>');
        prev = h.rate;
        return '<span class="qs-tag" style="background:#667eea;cursor:default;">' + (h.title ? qsEsc(h.title) : '第' + h.no + ROUND_LABEL) + '：' + h.g + '/' + h.t + '（' + h.rate + '%）' + delta + '</span>';
    }).join('') || '<span style="color:#999;">暂无得分记录</span>';
    document.getElementById('osStuModal').classList.add('show');
}
function osStuTrendSvg(hist) {
    if (!hist.length) return '';
    var W = Math.max(320, hist.length * 80 + 50), H = 230, L = 46, R = 16, T = 18, B = 34;
    var iw = W - L - R, ih = H - T - B;
    function X(i) { return hist.length === 1 ? L + iw / 2 : L + iw * i / (hist.length - 1); }
    function Yp(v) { return T + ih * (1 - v / 100); }
    var s = '<svg width="' + W + '" height="' + H + '" style="display:block;">';
    for (var v = 0; v <= 100; v += 25) {
        s += '<line x1="' + L + '" y1="' + Yp(v) + '" x2="' + (W - R) + '" y2="' + Yp(v) + '" stroke="' + (v === 0 ? '#dfe4f3' : '#eef1f8') + '" stroke-width="' + (v === 0 ? 2 : 1) + '"/>';
        s += '<text x="' + (L - 8) + '" y="' + (Yp(v) + 4) + '" font-size="11" fill="#999" text-anchor="end">' + v + '%</text>';
    }
    hist.forEach(function (h, i) {
        s += '<text x="' + X(i) + '" y="' + (H - 12) + '" font-size="11" fill="#888" text-anchor="middle">' + (h.title ? qsEsc(h.title) : '第' + h.no + ROUND_LABEL) + '</text>';
    });
    if (hist.length > 1) s += '<polyline points="' + hist.map(function (h, i) { return X(i) + ',' + Yp(h.rate); }).join(' ') + '" fill="none" stroke="#667eea" stroke-width="2.5" stroke-linejoin="round"/>';
    hist.forEach(function (h, i) {
        s += '<circle cx="' + X(i) + '" cy="' + Yp(h.rate) + '" r="6" fill="#667eea" stroke="#fff" stroke-width="2"><title>' + (h.title ? qsEsc(h.title) : '第' + h.no + ROUND_LABEL) + '：' + h.g + '/' + h.t + '（' + h.rate + '%）</title></circle>'
            + '<text x="' + X(i) + '" y="' + (Yp(h.rate) - 10) + '" font-size="11" font-weight="bold" fill="#667eea" text-anchor="middle">' + h.rate + '%</text>';
    });
    s += '</svg>';
    return s;
}
// 导出成绩报表（服务端 Excel，参考校级统计报表结构：学生成绩 / 一均三率及排名 / 学生等级 / 分数段统计）
function osExport() {
    window.location.href = 'export.php?action=download&dtype=project&id=' + PROJECT_ID;
    showToast('已开始导出成绩报表（Excel）', 'success');
}

// ===== 🕸 雷达图（手写 SVG）：dims=[{label}]，series=[{name,color,values:[0-100|null]}]；轴数<3 提示维度不足 =====
function radarSvg(dims, series) {
    if (!dims || dims.length < 3) return '<div style="color:#999;font-size:13px;padding:18px 0;text-align:center;">雷达图至少需要 3 个维度' + (dims && dims.length ? '（当前 ' + dims.length + ' 个）' : '') + '</div>';
    var size = 320, cx = size / 2, cy = size / 2 + 6, R = size / 2 - 48;
    function pt(i, v) {
        var ang = -Math.PI / 2 + 2 * Math.PI * i / dims.length;
        var r = R * Math.max(0, Math.min(100, v)) / 100;
        return [cx + Math.cos(ang) * r, cy + Math.sin(ang) * r];
    }
    var s = '<svg width="' + size + '" height="' + size + '" style="display:block;margin:0 auto;">';
    [25, 50, 75, 100].forEach(function (v) {
        s += '<polygon points="' + dims.map(function (_, i) { var p = pt(i, v); return p[0] + ',' + p[1]; }).join(' ') + '" fill="none" stroke="#e5e9f5" stroke-width="1"/>';
        s += '<text x="' + (cx + 4) + '" y="' + (cy - R * v / 100 + 3) + '" font-size="10" fill="#c3c9dd">' + v + '</text>';
    });
    dims.forEach(function (d, i) {
        var p = pt(i, 100);
        s += '<line x1="' + cx + '" y1="' + cy + '" x2="' + p[0] + '" y2="' + p[1] + '" stroke="#e5e9f5" stroke-width="1"/>';
        var lp = pt(i, 121);
        s += '<text x="' + lp[0] + '" y="' + lp[1] + '" font-size="11" fill="#556" text-anchor="middle">' + qsEsc(d.label) + '</text>';
    });
    series.forEach(function (se) {
        var pts = [];
        se.values.forEach(function (v, i) {
            if (v === null || v === undefined) return;
            pts.push(pt(i, v));
        });
        if (pts.length < 3) return;
        s += '<polygon points="' + pts.map(function (p) { return p[0] + ',' + p[1]; }).join(' ') + '" fill="' + se.color + '26" stroke="' + se.color + '" stroke-width="2" stroke-linejoin="round"/>';
        se.values.forEach(function (v, i) {
            if (v === null || v === undefined) return;
            var p = pt(i, v);
            s += '<circle cx="' + p[0] + '" cy="' + p[1] + '" r="3.2" fill="' + se.color + '"><title>' + qsEsc(se.name) + ' · ' + qsEsc(dims[i].label) + '：' + v + '%</title></circle>';
        });
    });
    s += '</svg>';
    return s;
}
function radarLegend(series) {
    return '<div style="display:flex;gap:14px;justify-content:center;font-size:12px;color:#666;margin-bottom:4px;">' + series.map(function (se) {
        return '<span style="display:inline-flex;align-items:center;gap:4px;"><i style="width:14px;height:3px;border-radius:2px;background:' + se.color + ';display:inline-block;"></i>' + qsEsc(se.name) + '</span>';
    }).join('') + '</div>';
}

// ===== 成绩统计三视图（全班 / 分组 / 个人+雷达）与统计维度 / 题次对比 =====
var osView = 'class';    // class=全班 group=分组 person=个人
var osCmpSel = {};       // 对比勾选的题次集合（round no → true）
var osPersons = {};      // 个人对比视图勾选的学生 id → true（可多选：柱=得分率，线=超均率；雷达叠加全班平均）
var P_COLORS = ['#667eea', '#27ae60', '#e74c3c', '#2980b9', '#8e44ad', '#16a085', '#d35400', '#f39c12'];
function osSetView(v) { osView = v; osRender(); }
function osSetDim(v) { osDim = v; osRender(); }
function osToggleType() { osType = (osType === 'bar') ? 'pie' : 'bar'; osRender(); }
// ===== 答题统计（按题）：模式切换 / 取数 / 渲染 =====
function osSetMode() {
    osMode = (osMode === 'quest') ? 'score' : 'quest';
    if (osMode === 'quest') { osView = 'class'; osLoadQuestions(); }   // 每次切入重新拉取，保证看到最新批改数据
    else osRender();
}
function osLoadQuestions() {   // 拉取当前统计范围的按题作答统计（round=0 表示全部题次汇总）
    ecDel('osCanvas'); document.getElementById('osCanvas').innerHTML = '';
    fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'type=omr_questions&project_id=' + PROJECT_ID + '&cls_id=' + intval0(PV_CLASS_ID) + '&round=' + (osRound === 'all' ? 0 : intval0(osRound)) })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.success) { showToast((d && d.message) || '加载失败', 'error'); return; }
            OQD = d;
            if (!(OQD.questions || []).some(function (q) { return q.qno === osQNo; })) osQNo = OQD.questions.length ? OQD.questions[0].qno : 1;
            osRender();
        })
        .catch(function () { showToast('网络错误', 'error'); });
}
function osSetQView(v) { osQView = v; osRender(); }
function osSetQNo(v) { osQNo = intval0(v) || 1; osRender(); }
function osRenderQ() {   // 一页一图：rate=各题正确率柱图（点柱=答错名单）；dist=单题选项分布环形饼（点扇区=选该选项名单）
    var qs = (OQD && OQD.questions) || [];
    if (!qs.length) { document.getElementById('osCanvas').innerHTML = '<div style="color:#999;padding:40px;text-align:center;">该范围暂无批改数据</div>'; return; }
    if (osQView === 'rate') {
        var labels = qs.map(function (q) { return 'Q' + q.qno; });
        var values = qs.map(function (q) { return (q.rate == null) ? 0 : Math.round(q.rate * 10) / 10; });
        var ch = ecSet('osCanvas', ecBarOpt(labels, values, { unit: '%', pctMax100: true }));
        if (!ch) { document.getElementById('osCanvas').innerHTML = ''; return; }
        ch.off('click');
        ch.on('click', function (p) {
            var q = qs[p.dataIndex];
            if (q) osShowQList('第' + q.qno + '题 答错名单（正确答案 ' + (q.correct || '-') + '）', '#e74c3c', q.wrong || []);
        });
    } else {
        var q = qs.filter(function (x) { return x.qno === osQNo; })[0] || qs[0];
        var opts = ['A', 'B', 'C', 'D', 'E'];
        var items = opts.filter(function (k) { return (q.counts[k] || 0) > 0; }).map(function (k, i) {
            return { name: k + (k === q.correct ? '（✓）' : ''), value: q.counts[k] || 0,
                     color: (k === q.correct) ? '#27ae60' : SEG_COLORS[i % SEG_COLORS.length] };
        });
        var rateTxt = (q.rate == null) ? '-' : (Math.round(q.rate * 10) / 10) + '%';
        var ch = ecSet('osCanvas', ecPieOpt(items, { unit: '人', centerMain: '第' + q.qno + '题', centerSub: '正确率 ' + rateTxt }));
        if (!ch) { document.getElementById('osCanvas').innerHTML = ''; return; }
        ch.off('click');
        ch.on('click', function (p) {
            var letter = String(p.name || '').charAt(0);
            osShowQList('第' + q.qno + '题 选 ' + letter + ' 名单', (letter === q.correct) ? '#27ae60' : '#e74c3c', (q.lists || {})[letter] || []);
        });
    }
}
function osShowQList(title, color, list) {   // 答题统计名单：{id, name, seat_no} → 点击姓名打开该生个人统计
    list = list || [];
    document.getElementById('osListTitle').textContent = title + '（' + list.length + ' 人）';
    document.getElementById('osListTitle').style.color = color;
    document.getElementById('osListBody').innerHTML = list.length ? list.map(function (s) {
        return '<span class="qs-tag" style="background:' + color + ';" title="点击查看个人统计" onclick="openOsStu(' + intval0(s.id) + ')">'
            + qsEsc(String(s.seat_no) !== '' ? s.seat_no + ' ' : '') + qsEsc(s.name) + '</span>';
    }).join('') : '<span style="color:#999;">暂无学生</span>';
    document.getElementById('osList').style.display = '';
}
// 分组视图：按 group_id 聚合当前范围已批改行 → 各组卡片（平均得分率/优秀/及格/低分）+ 组间平均得分率条形图；点卡片看组员名单
function osRenderGroup() {
    var box = document.getElementById('osGroupView');
    var rows = osRows();
    if (!rows.length) { box.innerHTML = '<p class="tip" style="text-align:center;padding:24px 0;">当前范围暂无已批改成绩</p>'; return; }
    var buckets = {};
    rows.forEach(function (r) { var gid = intval0(r.stu.group_id) || 0; (buckets[gid] = buckets[gid] || []).push(r); });
    var order = (OSD.groups || []).map(function (g) { return { id: intval0(g.id), name: g.name }; });
    order.push({ id: 0, name: '未分组' });
    var cards = [];
    order.forEach(function (g) {
        var rs = buckets[g.id];
        if (!rs || !rs.length) return;
        var sum = 0, exc = 0, pass = 0, low = 0;
        rs.forEach(function (r) { sum += r.rate; if (r.rate >= 80) exc++; if (r.rate >= 60) pass++; if (r.rate <= 30) low++; });
        cards.push({ name: g.name, rows: rs, n: rs.length, avg: Math.round(sum / rs.length * 10) / 10, exc: exc, pass: pass, low: low });
    });
    if (!cards.length) { box.innerHTML = '<p class="tip" style="text-align:center;padding:24px 0;">暂无分组数据</p>'; return; }
    var colors = ['#667eea', '#27ae60', '#f39c12', '#e74c3c', '#2980b9', '#8e44ad', '#16a085', '#d35400'];
    var scope = osRound === 'all' ? '全部题次汇总' : '第' + osRound + ROUND_LABEL;
    var h = '<div style="color:#666;font-size:13px;margin:10px 0 8px;">分组统计 · ' + qsEsc(scope) + ' · 已批改 ' + rows.length + ' 人（点卡片查看组员名单，按各组成员平均得分率排序无关，按分组定义排序）</div>';
    h += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px;">';
    cards.forEach(function (c, i) {
        var col = colors[i % colors.length];
        h += '<div class="grp-card" onclick="osShowGroupList(' + i + ')" title="点击查看组员名单">'
            + '<div style="display:flex;justify-content:space-between;align-items:center;"><b style="color:' + col + ';font-size:14px;">' + qsEsc(c.name) + '</b><span style="font-size:12px;color:#999;">' + c.n + ' 人</span></div>'
            + '<div style="font-size:24px;font-weight:bold;color:#333;margin:2px 0;">' + c.avg + '%</div>'
            + '<div style="font-size:12px;color:#666;line-height:1.7;">优秀 ' + c.exc + ' 人 · 及格 ' + c.pass + ' 人<br>低分 ' + c.low + ' 人</div>'
            + '</div>';
    });
    h += '</div>';
    // 组间平均得分率对比（ECharts 单图区：柱=各组平均得分率 %，点柱看名单）
    h += '<div style="margin:14px 0 4px;font-size:13px;font-weight:bold;color:#555;">📈 各组平均得分率对比（横轴=分组，柱=平均得分率 %，点柱看名单）</div>';
    h += '<div id="osGrpCanvas" style="height:280px;"></div>';
    box.innerHTML = h;
    var gch = ecSet('osGrpCanvas', ecBarOpt(cards.map(function (c) { return c.name; }), cards.map(function (c) { return c.avg; }),
        { colors: colors, unit: '%', pctMax100: true }));
    if (gch) {
        gch.off('click');
        gch.on('click', function (p) { if (p.dataIndex != null) osShowGroupList(p.dataIndex); });
    }
    OSD._grpCards = cards;
}
function osShowGroupList(i) {
    var c = (OSD._grpCards || [])[i];
    if (c) osShowList(c.name + '（平均 ' + c.avg + '%）', '#667eea', c.rows);
}
// 个人视图：学生标签云（可多选）→ 多人对比柱线图（柱=得分率，线=超均率双轴）+ 雷达图叠加全班平均
function osRenderPerson() {
    var box = document.getElementById('osPersonView');
    var tags = (OSD.students || []).map(function (s) {
        return '<span class="stu-tag' + (osPersons[intval0(s.id)] ? ' on' : '') + '" onclick="osPickPerson(' + intval0(s.id) + ')">'
            + qsEsc(String(s.seat_no) !== '' ? s.seat_no + ' ' : '') + qsEsc(s.name) + '</span>';
    }).join('');
    box.innerHTML = '<div style="color:#666;font-size:13px;margin:10px 0 4px;">🧑 选择学生（可勾选多人对比：柱=得分率、线=超均率；雷达图叠加全班平均）</div>'
        + '<div style="line-height:2;max-height:132px;overflow:auto;">' + (tags || '<span style="color:#999;">暂无学生</span>') + '</div>'
        + '<div id="osPersonBody" style="margin-top:8px;"></div>';
    renderOsPersonRadar();
}
function osPickPerson(sid) { if (osPersons[intval0(sid)]) delete osPersons[intval0(sid)]; else osPersons[intval0(sid)] = true; osRenderPerson(); }

// ===== 答题卡个人统计弹层（点击学生卡片：得分卡 + 历次得分率折线（对比全班平均）+ 单次题组雷达；底部 补登记/修改答案） =====
var OSM_SID = 0;   // 当前弹层学生 id
function osmFmt1(v) { return String(Math.round(parseFloat(v) * 10) / 10); }
function osmDispose() {
    Object.keys(OSM_CH).forEach(function (k) { if (OSM_CH[k]) { OSM_CH[k].dispose(); OSM_CH[k] = null; } });
}
function osmClose() {
    document.getElementById('omrStuModal').classList.remove('show');
    osmDispose();
}
function openOmrStu(sid) {
    sid = intval0(sid);
    if (!OSD) { osLoadData(function () { openOmrStu(sid); }); return; }
    OSM_SID = sid;
    var stu = null;
    (OSD.students || []).forEach(function (s) { if (intval0(s.id) === sid) stu = s; });
    if (!stu) { showToast('学生不在当前班级统计范围内', 'warn'); return; }
    document.getElementById('osmName').textContent = (String(stu.seat_no) !== '' ? stu.seat_no + ' ' : '') + stu.name;
    document.getElementById('osmSub').textContent = OSD.class && OSD.class.name ? OSD.class.name : '';
    // 先显示弹层再初始化图表：容器 display:none 时 echarts.init 得到 0×0 画布导致折线空白
    document.getElementById('omrStuModal').classList.add('show');
    // 得分卡：总得分率（口径同统计弹窗 Σ得分÷Σ满分）+ 全班平均 + 各题次得分率
    var g = 0, t = 0;
    Object.keys(stu.scores || {}).forEach(function (k) { g += stu.scores[k].g; t += stu.scores[k].t; });
    var rowsAll = osRows(), cSum = 0;
    rowsAll.forEach(function (r) { cSum += r.rate; });
    var clsAvg = rowsAll.length ? Math.round(cSum / rowsAll.length * 10) / 10 : 0;
    var rate = t > 0 ? Math.round(g / t * 1000) / 10 : null;
    var rnName = OSD.scored ? '得分率' : '正确率';
    var cards = '<span class="stu-tag on" style="font-size:13px;">总' + rnName + '：'
        + (rate === null ? '未批改' : rate + '%（' + osmFmt1(g) + '/' + osmFmt1(t) + '）') + '</span>'
        + '<span class="stu-tag" style="font-size:13px;">全班平均：' + clsAvg + '%</span>'
        + ((rate !== null && clsAvg > 0) ? '<span class="stu-tag" style="font-size:13px;">超均率：' + (rate >= clsAvg ? '+' : '') + Math.round((rate - clsAvg) / clsAvg * 1000) / 10 + '%</span>' : '');
    (OSD.rounds || []).forEach(function (r) {
        var sc = (stu.scores || {})[r.no];
        cards += '<span class="stu-tag" style="font-size:12px;">' + (r.title ? '第' + r.no + ROUND_LABEL + '·' + qsEsc(r.title) : '第' + r.no + ROUND_LABEL)
            + '：' + ((sc && sc.t > 0) ? Math.round(sc.g / sc.t * 1000) / 10 + '%' : '未批改') + '</span>';
    });
    document.getElementById('osmCards').innerHTML = cards;
    // 底部按钮态：修改/录入 按该生「当前题次」是否有答题数据切换（异步取识别结果，避免首开时缓存未建误判）；仅查看隐藏操作按钮
    var curRound = intval0(CURRENT_ROUND);
    var editBtn = document.getElementById('osmEditBtn');
    var setEditBtn = function (has) {
        editBtn.textContent = has ? '✏️ 修改' : '✏️ 录入';
        editBtn.title = has ? '逐题修改该生本题次答案（提交后自动重判）' : '该生本题次暂无答题数据：手工录入每题选择，参与判分统计';
    };
    setEditBtn(false);
    osLoadResults(function (items) {
        setEditBtn(items.some(function (it) { return intval0(it.student_id) === sid && intval0(it.round_no) === curRound; }));   // 只看当前题次：其他题次有数据不影响本题次按钮
    });
    document.getElementById('osmScanBtn').style.display = VIEW_ONLY ? 'none' : '';
    document.getElementById('osmEditBtn').style.display = VIEW_ONLY ? 'none' : '';
    // 历次得分率折线（该生 实线 vs 全班平均 虚线；无已批改数据时占位提示）
    var labels = [], mine = [], avg = [];
    (OSD.rounds || []).forEach(function (r) {
        labels.push('第' + r.no + ROUND_LABEL);
        var sc = (stu.scores || {})[r.no];
        mine.push(sc && sc.t > 0 ? Math.round(sc.g / sc.t * 1000) / 10 : null);
        var s2 = 0, c2 = 0;
        (OSD.students || []).forEach(function (o) {
            var oc = (o.scores || {})[r.no];
            if (oc && oc.t > 0) { s2 += oc.g / oc.t * 100; c2++; }
        });
        avg.push(c2 ? Math.round(s2 / c2 * 10) / 10 : null);
    });
    var hasData = mine.some(function (v) { return v !== null; });
    if (!hasData) {
        osmDispose();
        document.getElementById('osmTrend').innerHTML = '<div style="height:230px;display:flex;align-items:center;justify-content:center;color:#999;font-size:13px;">该生暂无已批改成绩</div>';
    } else {
        var ch = ecInit('osmTrend', OSM_CH, 'trend');
        if (!ch) {
            document.getElementById('osmTrend').innerHTML = '<div style="height:230px;display:flex;align-items:center;justify-content:center;color:#999;font-size:13px;">图表组件（echarts）加载失败，请检查 assets/js/echarts.min.js</div>';
        } else ch.setOption({
            title: { text: rnName + '走势（对比全班平均）', left: 'center', top: 4, textStyle: { fontSize: 13, color: '#666' } },
            tooltip: { trigger: 'axis', valueFormatter: function (v) { return (v === null || v === undefined || v === '-') ? '-' : v + '%'; } },
            legend: { top: 26, textStyle: { fontSize: 12 } },
            grid: { left: 44, right: 20, top: 60, bottom: 30 },
            xAxis: { type: 'category', data: labels },
            yAxis: { type: 'value', max: 100, axisLabel: { formatter: '{value}%' } },
            series: [
                { name: stu.name, type: 'line', data: mine, smooth: true, connectNulls: true, itemStyle: { color: '#667eea' },
                  label: { show: true, fontSize: 11, formatter: function (p) { return (p.value === null || p.value === undefined) ? '' : p.value; } } },
                { name: '全班平均', type: 'line', data: avg, smooth: true, connectNulls: true, itemStyle: { color: '#f39c12' }, lineStyle: { type: 'dashed' } }
            ]
        });
    }
    // 单次题组雷达（默认当前题次；该生 vs 全班平均；无题组或维度<3 自动隐藏）
    var wrap = document.getElementById('osmRadarWrap');
    wrap.style.display = 'none';
    var secRound = CURRENT_ROUND || ((OSD.rounds || []).length ? OSD.rounds[OSD.rounds.length - 1].no : 0);
    if (secRound > 0) osLoadSections(secRound, function (d) {
        var secs = d.sections || [];
        if (secs.length < 3) return;   // radarSvg 至少 3 维
        var per = (d.sec_rates || {})[String(sid)] || {};
        var dims = [], mv = [], cv = [];
        secs.forEach(function (sec, si) {
            dims.push({ label: sec.title || ('题组' + (si + 1)) });
            var p = per[si];
            mv.push(p && p.n > 0 ? Math.round(p.c / p.n * 1000) / 10 : null);
            var sum = 0, cnt = 0;
            Object.keys(d.sec_rates || {}).forEach(function (k) {
                var qq = d.sec_rates[k][si];
                if (qq && qq.n > 0) { sum += qq.c / qq.n * 100; cnt++; }
            });
            cv.push(cnt ? Math.round(sum / cnt * 10) / 10 : null);
        });
        wrap.style.display = '';
        document.getElementById('osmSecRound').textContent = '第' + secRound + ROUND_LABEL;
        document.getElementById('osmRadar').innerHTML = radarSvg(dims, [
            { name: '该生', color: '#667eea', values: mv },
            { name: '全班平均', color: '#f39c12', values: cv }
        ]) + radarLegend([{ name: '该生', color: '#667eea' }, { name: '全班平均', color: '#f39c12' }]);
    });
    // 得分率雷达（轴=总+各题次，2 个题次即可成图；该生 vs 全班平均，口径同统计弹窗「个人」视图雷达；无已批改成绩时隐藏）
    var wrap2 = document.getElementById('osmRadar2Wrap');
    if (wrap2) {
        wrap2.style.display = 'none';
        if (labels.length >= 2 && hasData) {
            var dims2 = [{ label: '总' + rnName }].concat((OSD.rounds || []).map(function (r) {
                return { label: r.title ? r.title : ('第' + r.no + ROUND_LABEL) };
            }));
            var cG2 = 0, cT2 = 0;
            (OSD.students || []).forEach(function (o) {
                Object.keys(o.scores || {}).forEach(function (k) { cG2 += o.scores[k].g; cT2 += o.scores[k].t; });
            });
            var clsPool = cT2 > 0 ? Math.round(cG2 / cT2 * 1000) / 10 : 0;   // 全班总得分率（Σg÷Σt，与各次走势同口径）
            wrap2.style.display = '';
            document.getElementById('osmRadar2').innerHTML = radarSvg(dims2, [
                { name: stu.name + (String(stu.seat_no) !== '' ? '（' + stu.seat_no + '）' : ''), color: '#667eea', values: [rate].concat(mine) },
                { name: '全班平均', color: '#f39c12', values: [clsPool].concat(osClassTrend().map(function (tr) { return tr.rate; })) }
            ]) + radarLegend([{ name: stu.name, color: '#667eea' }, { name: '全班平均', color: '#f39c12' }]);
        }
    }
}
function osmGoScan() {
    // 跳转答题卡识别页（携带当前题次）：摄像头拍照 / 上传照片识别涂卡
    location.href = 'omr_scan.php?project_id=' + PROJECT_ID + '&round=' + CURRENT_ROUND;
}
function osmEditClick() {
    // 当前题次有答题数据 → 修改模式；无数据 → 录入模式（手工点选每题答案，默认当前题次；下拉中标注其他题次已有数据）
    osLoadResults(function (items) {
        var cur = intval0(CURRENT_ROUND);
        var mine = items.filter(function (it) { return intval0(it.student_id) === OSM_SID; });
        var hasCur = mine.some(function (it) { return intval0(it.round_no) === cur; });
        openOmrEdit(OSM_SID, hasCur ? { locked: false } : { manual: true, existing: mine });
    });
}
// 识别结果缓存（omr_results 全量，含 student_id/answers/score）：弹窗按钮态与逐题修改共用
function osLoadResults(cb) {
    if (OSD._res) { cb(OSD._res); return; }
    fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'type=omr_results&project_id=' + PROJECT_ID })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            OSD._res = (d && d.success) ? (d.items || []) : [];
            cb(OSD._res);
        })
        .catch(function () { cb([]); });
}

// ===== 答题卡逐题答案 修改/录入 弹层 =====
// 长按学生卡片打开默认「锁定」（仅查看，点解锁后才能修改并提交）；个人统计弹窗「修改」= 直接可编辑、「录入」= 无数据手工录入每题选择
var OE = { rows: [], round: 0, rid: 0, answers: {}, sections: [], locked: true, manual: false, manualRound: -1, existing: [] };
function openOmrEdit(sid, opts) {
    opts = opts || {};
    if (VIEW_ONLY) { showToast('您在该班级的授权权限为「仅查看」，无法修改答案', 'warn'); return; }
    if (VIEW_DATE && !requireHistEdit()) return;   // 历史日期需先临时解锁
    sid = intval0(sid);
    if (!OSD) { osLoadData(function () { openOmrEdit(sid, opts); }); return; }
    OSM_SID = sid;
    var openWith = function (rows) {
        var stu = null;
        (OSD.students || []).forEach(function (s) { if (intval0(s.id) === sid) stu = s; });
        var stuName = stu ? ((String(stu.seat_no) !== '' ? stu.seat_no + ' ' : '') + stu.name) : ('学生#' + sid);
        if (opts.manual || !(rows || []).length) {
            // 录入模式：该生该题次无识别/录入数据 → 手工点选每题答案（提交走 omr_manual_save，参与判分统计）
            if (!opts.manual && !(rows || []).length) { showToast('该生暂无答题卡识别记录，请先识别或补登', 'warn'); return; }
            OE.manual = true; OE.rows = []; OE.rid = 0; OE.answers = {}; OE.manualRound = -1;
            OE.existing = opts.existing || [];
            OE.round = CURRENT_ROUND || ((OSD.rounds || []).length ? OSD.rounds[OSD.rounds.length - 1].no : 1);
            var existR = {};
            (opts.existing || []).forEach(function (it) { existR[intval0(it.round_no)] = true; });   // 该生已有数据的题次（切换过去=查看/覆盖录入）
            document.getElementById('oeTitle').textContent = '录入答案';
            document.getElementById('oeName').textContent = stuName;
            document.getElementById('oeSub').innerHTML = '题次：<select id="oeRoundSel" onchange="oeSwitchRound(this.value)">'
                + (OSD.rounds || []).map(function (r) {
                    return '<option value="' + r.no + '"' + (intval0(r.no) === OE.round ? ' selected' : '') + '>'
                        + (r.title ? '第' + r.no + ROUND_LABEL + '·' + qsEsc(r.title) : '第' + r.no + ROUND_LABEL)
                        + (existR[intval0(r.no)] ? '（已有数据，提交覆盖）' : '') + '</option>';
                }).join('') + '</select>（录入提交后自动判分并登记）';
            document.getElementById('omrEditModal').classList.add('show');
            oeSetLock(false);
            oeLoadRound();
            return;
        }
        // 修改模式：按已识别数据逐题修改（提交走 omr_edit，服务端按生效答案键重判分并同步评价值）
        OE.manual = false; OE.rows = rows; OE.manualRound = -1; OE.existing = [];
        OE.round = intval0(rows[0].round_no);
        rows.forEach(function (it) { if (intval0(it.round_no) === CURRENT_ROUND) OE.round = CURRENT_ROUND; });
        document.getElementById('oeTitle').textContent = '修改答案';
        document.getElementById('oeName').textContent = stuName;
        if (rows.length > 1) {
            document.getElementById('oeSub').innerHTML = '题次：<select id="oeRoundSel" onchange="oeSwitchRound(this.value)">'
                + rows.map(function (it) {
                    var rn = intval0(it.round_no);
                    return '<option value="' + rn + '"' + (rn === OE.round ? ' selected' : '') + '>第' + rn + ROUND_LABEL + '</option>';
                }).join('') + '</select>';
        } else {
            document.getElementById('oeSub').textContent = '第' + OE.round + ROUND_LABEL;
        }
        document.getElementById('omrEditModal').classList.add('show');
        oeSetLock(!!opts.locked);   // 长按打开默认锁定；点「修改」按钮打开直接可编辑
        oeLoadRound();
    };
    if (opts.manual) { openWith([]); return; }
    osLoadResults(function (items) {
        openWith(items.filter(function (it) { return intval0(it.student_id) === sid; }));
    });
}
// 锁定/解锁：锁定=答案仅供查看（隐藏提交按钮、选项置灰）；录入模式无锁定概念
function oeSetLock(on) {
    OE.locked = !!on && !OE.manual;
    var b = document.getElementById('oeLockBtn');
    b.style.display = OE.manual ? 'none' : '';
    b.textContent = OE.locked ? '🔒 解锁' : '🔓 已解锁';
    b.title = OE.locked ? '当前已锁定：答案仅供查看，点击解锁后可修改并提交' : '已解锁：可修改答案并提交（点此重新锁定）';
    b.className = 'btn btn-sm ' + (OE.locked ? 'btn-outline' : 'btn-warning');
    b.style.marginLeft = 'auto'; b.style.padding = '2px 10px';
    document.getElementById('oeLockTip').style.display = OE.locked ? '' : 'none';
    document.getElementById('oeSubmitBtn').style.display = OE.locked ? 'none' : '';
    document.getElementById('oeSubmitBtn').textContent = OE.manual ? '提交录入' : '提交修改';
    oeRender();
}
function oeToggleLock() { oeSetLock(!OE.locked); }
function oeSwitchRound(v) { OE.round = intval0(v); oeLoadRound(); }
function oeLoadRound() {
    if (OE.manual) {
        // 录入模式：从绑定模板题组渲染作答；该生该题次已有数据则预填（切换题次=查看/覆盖录入，提交 upsert 覆盖）
        OE.rid = 0;
        if (OE.manualRound !== OE.round) {
            OE.answers = {};
            var exRow = null, scoreTxt = '';
            (OE.existing || []).forEach(function (it) { if (intval0(it.round_no) === OE.round) exRow = it; });
            if (exRow) {
                try {
                    var xd = JSON.parse(exRow.answers || '{}');
                    Object.keys(xd).forEach(function (k) {
                        var xk = String(intval0(k));
                        var xv = String(xd[k]).toUpperCase().replace(/[^A-E]/g, '');
                        if (xk !== '' && xv !== '') OE.answers[xk] = xv;
                    });
                } catch (e) {}
                scoreTxt = '该题次已有数据：当前得分 ' + (exRow.score || '未批改') + '（提交将覆盖）';
            }
            document.getElementById('oeScore').textContent = scoreTxt;
            OE.manualRound = OE.round;
        }
        osLoadSections(OE.round, function (d) {
            OE.sections = d.sections || [];
            oeRender();
        });
        return;
    }
    var row = null;
    OE.rows.forEach(function (it) { if (intval0(it.round_no) === OE.round) row = it; });
    if (!row) return;
    OE.rid = intval0(row.id);
    var ans = {};
    try {
        var dec = JSON.parse(row.answers || '{}');
        Object.keys(dec).forEach(function (k) {
            var qk = String(intval0(k));
            var vv = String(dec[k]).toUpperCase().replace(/[^A-E]/g, '');
            if (qk !== '' && vv !== '') ans[qk] = vv;
        });
    } catch (e) {}
    OE.answers = ans;
    document.getElementById('oeScore').textContent = '当前得分：' + (row.score || '未批改') + '（提交后按生效答案重判）';
    osLoadSections(OE.round, function (d) {
        OE.sections = d.sections || [];
        oeRender();
    });
}
function oeRender() {
    var html = '';
    if (!OE.sections.length) {
        html = OE.manual
            ? '<p class="tip" style="text-align:center;padding:18px 0;">未绑定答题卡模板，无法逐题录入</p>'
            : '<p class="tip" style="text-align:center;padding:18px 0;">模板未配置题组，无法逐题修改</p>';
    }
    var dis = OE.locked;   // 锁定：选项仅供查看（置灰不可点）
    OE.sections.forEach(function (sec, si) {
        var opts = Math.max(2, Math.min(6, intval0(sec.opts) || 4));
        html += '<div style="margin:10px 0 4px;font-size:13px;font-weight:bold;color:#555;">' + qsEsc(sec.title || '题组' + (si + 1))
            + '（' + (sec.kind === 'multi' ? '多选' : '单选') + '·第' + intval0(sec.start) + '~' + (intval0(sec.start) + intval0(sec.count) - 1) + '题）</div>';
        html += '<div style="display:flex;flex-wrap:wrap;gap:2px 12px;">';
        for (var q = intval0(sec.start); q < intval0(sec.start) + intval0(sec.count); q++) {
            var cur = OE.answers[String(q)] || '';
            html += '<span style="display:inline-flex;align-items:center;gap:2px;margin:3px 0;">';
            html += '<b style="font-size:12px;color:#888;width:30px;">' + q + '.</b>';
            for (var li = 0; li < opts; li++) {
                var ch = 'ABCDE'.charAt(li);
                var on = cur.indexOf(ch) >= 0;
                html += '<button type="button"' + (dis ? ' disabled' : '') + ' onclick="oePick(' + q + ',\'' + ch + '\',' + (sec.kind === 'multi' ? 1 : 0) + ')"'
                    + ' style="width:26px;height:24px;border-radius:6px;border:1px solid ' + (on ? '#667eea' : '#dde3f0')
                    + ';background:' + (on ? '#667eea' : (dis ? '#f2f3f8' : '#fff')) + ';color:' + (on ? '#fff' : (dis ? '#b8bdcc' : '#666'))
                    + ';font-size:12px;cursor:' + (dis ? 'not-allowed' : 'pointer') + ';padding:0;">' + ch + '</button>';
            }
            html += '</span>';
        }
        html += '</div>';
    });
    if (OE.locked && OE.sections.length) {
        html = '<div style="font-size:12px;color:#999;margin-bottom:6px;">🔒 锁定状态：以下答案仅供查看核对</div>' + html;
    }
    document.getElementById('oeBody').innerHTML = html;
}
function oePick(q, ch, isMulti) {
    var qk = String(q), cur = OE.answers[qk] || '';
    if (isMulti) {
        cur = cur.indexOf(ch) >= 0 ? cur.split('').filter(function (c) { return c !== ch; }).join('')
                                   : (cur + ch).split('').sort().join('');
    } else {
        cur = (cur === ch) ? '' : ch;
    }
    if (cur) OE.answers[qk] = cur; else delete OE.answers[qk];
    oeRender();
}
function oeSubmit() {
    if (OE.locked) { showToast('已锁定：请先点击右上角「解锁」再提交', 'warn'); return; }
    if (OE.manual) {
        // 录入提交：omr_manual_save（学生+题次+每题答案），服务端按绑定模板判分并登记
        if (!Object.keys(OE.answers).length) { showToast('请先点选每题的作答选项', 'warn'); return; }
        apiCall('omr_manual_save', 'student_id=' + OSM_SID + '&round=' + OE.round + '&answers=' + encodeURIComponent(JSON.stringify(OE.answers)), function (d) {
            showToast('已录入' + (d.score ? '，得分 ' + d.score : ''), 'success');
            var num = String(d.score || '').split('/')[0];
            document.getElementById('omrEditModal').classList.remove('show');
            if (num !== '') setTileEval(OSM_SID, num);
            var tile = document.getElementById('tile_' + OSM_SID);
            if (tile && tile.dataset.state !== 'yes') setTileState(OSM_SID, true, Date.now() / 1000);
            OSD._res = null;
            osLoadData(function () {   // 成绩已变：刷新统计缓存；统计/个人弹层开着则同步重渲染
                if (document.getElementById('omrStatsModal').classList.contains('show')) osRender();
                if (document.getElementById('omrStuModal').classList.contains('show')) openOmrStu(OSM_SID);
            });
        });
        return;
    }
    if (!OE.rid) return;
    apiCall('omr_edit', 'id=' + OE.rid + '&answers=' + encodeURIComponent(JSON.stringify(OE.answers)), function (d) {
        showToast('答案已修正' + (d.score ? '，得分 ' + d.score : ''), 'success');
        var num = String(d.score || '').split('/')[0];
        document.getElementById('omrEditModal').classList.remove('show');
        if (num !== '') setTileEval(OSM_SID, num);
        OSD._res = null;
        osLoadData(function () {   // 成绩已变：刷新统计缓存；统计/个人弹层开着则同步重渲染
            if (document.getElementById('omrStatsModal').classList.contains('show')) osRender();
            if (document.getElementById('omrStuModal').classList.contains('show')) openOmrStu(OSM_SID);
        });
    });
}
// ===== 查看题卡：该生批注题卡图片（服务器留存 + 本机浏览器缓存） =====
function openOmrCard(sid) {
    sid = intval0(sid);
    if (!OSD) { osLoadData(function () { openOmrCard(sid); }); return; }
    var stu = null;
    (OSD.students || []).forEach(function (s) { if (intval0(s.id) === sid) stu = s; });
    var stuName = stu ? ((String(stu.seat_no) !== '' ? stu.seat_no + ' ' : '') + stu.name) : ('学生#' + sid);
    document.getElementById('ocName').textContent = stuName;
    document.getElementById('ocBody').innerHTML = '<p class="tip" style="text-align:center;padding:24px 0;">加载中…</p>';
    document.getElementById('omrCardModal').classList.add('show');
    fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'type=omr_img_list&project_id=' + PROJECT_ID })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            var items = (d && d.success) ? (d.items || []).filter(function (it) { return intval0(it.student_id) === sid; }) : [];
            omrIdbImgs(stu, function (locals) { renderOmrCard(items, locals); });
        })
        .catch(function () { omrIdbImgs(stu, function (locals) { renderOmrCard([], locals); }); });
}
// 本机浏览器缓存图（omr_scan 上传服务器失败时留 IndexedDB）：按项目+识别号/座号/姓名匹配
function omrIdbImgs(stu, cb) {
    if (!window.indexedDB) { cb([]); return; }
    var rq = indexedDB.open('qj_omr', 1);
    rq.onsuccess = function () {
        var db = rq.result, out = [];
        try {
            var tx = db.transaction('imgs', 'readonly');
            tx.objectStore('imgs').openCursor().onsuccess = function (e) {
                var cur = e.target.result;
                if (cur) {
                    var v = cur.value || {};
                    var ident = String(stu ? (stu.student_no || '') : '');
                    if (intval0(v.pid) === PROJECT_ID
                        && ((ident !== '' && String(v.ident || '') === ident)
                            || (String(v.seat || '') !== '' && stu && String(v.seat) === String(stu.seat_no || ''))
                            || (String(v.name || '') !== '' && stu && String(v.name) === String(stu.name || '')))) {
                        out.push(v);
                    }
                    cur.continue();
                }
            };
            tx.oncomplete = function () { db.close(); cb(out); };
            tx.onerror = function () { db.close(); cb(out); };
        } catch (e) { try { db.close(); } catch (e2) {} cb(out); }
    };
    rq.onerror = function () { cb([]); };   // 本机未扫过/库不存在则无缓存
}
function renderOmrCard(srvItems, idbItems) {
    var fmtTs = function (t) {
        var n = intval0(t); if (!n) return '';
        var d = new Date(n < 1e12 ? n * 1000 : n);
        return isNaN(d.getTime()) ? '' : '（' + d.toLocaleString('zh-CN', { hour12: false }) + '）';
    };
    var html = '';
    if (srvItems.length) {
        srvItems.sort(function (a, b) { return a.round_no - b.round_no || a.pg - b.pg || b.id - a.id; });
        html += '<div style="font-size:13px;font-weight:bold;color:#555;margin:4px 0 8px;">🖥 服务器留存（' + srvItems.length + ' 张）</div>';
        srvItems.forEach(function (it) {
            html += '<div style="margin-bottom:14px;">'
                + '<div style="font-size:12px;color:#888;margin-bottom:4px;">第' + it.round_no + ROUND_LABEL + ' · 第' + it.pg + '页'
                + (it.score ? ' · 得分 ' + qsEsc(it.score) : '') + (it.teacher ? ' · ' + qsEsc(it.teacher) : '') + ' · ' + qsEsc(it.created_at || '')
                + '</div>'
                + '<img src="api.php?type=omr_img_get&project_id=' + PROJECT_ID + '&id=' + intval0(it.id) + '" alt="题卡"'
                + ' style="width:100%;border:1px solid #dde3f0;border-radius:8px;background:#fff;">'
                + '</div>';
        });
    }
    if (idbItems.length) {
        idbItems.sort(function (a, b) { return (a.round || 0) - (b.round || 0) || (a.pg || 0) - (b.pg || 0) || (b.ts || 0) - (a.ts || 0); });
        html += '<div style="font-size:13px;font-weight:bold;color:#555;margin:10px 0 8px;">💾 本机浏览器缓存（' + idbItems.length + ' 张，仅本机可见）</div>';
        idbItems.forEach(function (it) {
            html += '<div style="margin-bottom:14px;">'
                + '<div style="font-size:12px;color:#888;margin-bottom:4px;">第' + (it.round || '?') + ROUND_LABEL + ' · 第' + (it.pg || '?') + '页'
                + (it.score ? ' · 得分 ' + qsEsc(it.score) : '') + fmtTs(it.ts)
                + '</div>'
                + '<img src="' + it.dataUrl + '" alt="题卡"'
                + ' style="width:100%;border:1px solid #dde3f0;border-radius:8px;background:#fff;">'
                + '</div>';
        });
    }
    if (!html) html = '<p class="tip" style="text-align:center;padding:24px 0;">暂无该生的题卡图片</p>';
    document.getElementById('ocBody').innerHTML = html;
}
function osLoadSections(round, cb) {
    OSD._sec = OSD._sec || {};
    if (OSD._sec[round]) { cb(OSD._sec[round]); return; }
    fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'type=omr_stats&project_id=' + PROJECT_ID + '&cls_id=' + intval0(PV_CLASS_ID) + '&with_sections=1&round=' + intval0(round) })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.success) { showToast((d && d.message) || '题组数据加载失败', 'error'); return; }
            OSD._sec[round] = d;
            cb(d);
        })
        .catch(function () { showToast('网络错误', 'error'); });
}
function renderOsPersonRadar() {
    var body = document.getElementById('osPersonBody');
    if (!body) return;
    var picked = (OSD.students || []).filter(function (s) { return osPersons[intval0(s.id)]; });
    if (!picked.length) { body.innerHTML = '<p class="tip" style="text-align:center;padding:16px 0;">点击上方姓名勾选可对比</p>'; return; }
    picked = picked.slice(0, 8);   // 最多 8 人，避免图例拥挤
    var rateName = OSD.scored ? '得分率' : '正确率';
    var rows = osRows(), cSum = 0;
    rows.forEach(function (r) { cSum += r.rate; });
    var clsAvg = rows.length ? Math.round(cSum / rows.length * 10) / 10 : 0;
    // —— 多人对比柱+线（参考托底看板：柱=得分率，线=超均率（个体−全班均值）÷全班均值，双 Y 轴） ——
    var cmp = picked.map(function (stu) {
        var g = 0, t = 0;
        Object.keys(stu.scores || {}).forEach(function (rn) { g += stu.scores[rn].g; t += stu.scores[rn].t; });
        var rate = t > 0 ? Math.round(g / t * 1000) / 10 : null;
        return { name: (String(stu.seat_no) !== '' ? stu.seat_no + ' ' : '') + stu.name, rate: rate,
                 over: (rate !== null && clsAvg > 0) ? Math.round((rate - clsAvg) / clsAvg * 1000) / 10 : null };
    }).filter(function (c) { return c.rate !== null; }).sort(function (a, b) { return b.rate - a.rate; });
    var h = '<div style="margin:4px 0 2px;font-size:13px;font-weight:bold;color:#555;">📊 多人' + rateName + '对比 <span style="font-weight:normal;color:#999;">（柱=' + rateName + '%，线=超均率；全班均值 ' + clsAvg + '%）</span></div>'
        + '<div id="osCmpStuChart" style="height:250px;"></div>';
    body.innerHTML = h;
    var ch = ecInit('osCmpStuChart', OSC_CH, 'cmpstu');
    if (ch) {
        if (!cmp.length) {
            ch.setOption({ title: { text: '勾选的学生暂无已批改成绩', left: 'center', top: 'middle', textStyle: { color: '#999', fontSize: 13 } } });
        } else {
            ch.setOption({
                grid: { left: 44, right: 52, top: 34, bottom: 46 },
                tooltip: { trigger: 'axis', formatter: function (ps) {
                    var c = cmp[ps[0].dataIndex];
                    var extra = ps.filter(function (p) { return p.value !== null && p.value !== undefined; })
                                  .map(function (p) { return p.marker + p.seriesName + '：' + p.value + '%'; }).join('<br>');
                    return c.name + '<br>' + extra;
                } },
                legend: { top: 0, textStyle: { color: '#666', fontSize: 11 } },
                xAxis: { type: 'category', data: cmp.map(function (c) { return c.name; }), axisLabel: { color: '#888', fontSize: 11, rotate: cmp.length > 6 ? 20 : 0, interval: 0 } },
                yAxis: [{ type: 'value', max: 100, axisLabel: { color: '#888', formatter: '{value}%' }, splitLine: { lineStyle: { color: '#eef1f8' } } },
                        { type: 'value', name: '超均率', nameTextStyle: { color: '#888' }, axisLabel: { color: '#888', formatter: '{value}%' }, splitLine: { show: false } }],
                series: [
                    { name: rateName, type: 'bar', barWidth: '45%', data: cmp.map(function (c) { return c.rate; }),
                      itemStyle: { color: '#667eea', borderRadius: [4, 4, 0, 0] }, label: { show: true, position: 'top', color: '#666', fontSize: 11 } },
                    { name: '超均率', type: 'line', yAxisIndex: 1, data: cmp.map(function (c) { return c.over; }),
                      lineStyle: { color: '#e67e22', width: 2 }, itemStyle: { color: '#e67e22' }, symbolSize: 8,
                      label: { show: true, position: 'bottom', color: '#e67e22', fontSize: 10, formatter: function (p) { return p.value === null ? '' : (p.value > 0 ? '+' : '') + p.value + '%'; } } }
                ]
            });
        }
    }
    // —— 雷达：维度=各题次（汇总）或题组（单次）；系列=勾选学生（最多6）+ 全班平均 ——
    body.insertAdjacentHTML('beforeend', '<div id="osPersonRadarBox" style="margin-top:10px;"><p class="tip" style="text-align:center;padding:8px 0;">雷达图加载中…</p></div>');
    if (osRound === 'all') {
        var trend = osClassTrend();
        // 轴=总+各题次（2 个题次即可成图），与点击学生卡片的个人统计弹窗雷达同口径
        var dims = [{ label: '总' + rateName }].concat((OSD.rounds || []).map(function (r) { return { label: r.title ? r.title : '第' + r.no + ROUND_LABEL }; }));
        var cG3 = 0, cT3 = 0;
        (OSD.students || []).forEach(function (o) {
            Object.keys(o.scores || {}).forEach(function (rn) { cG3 += o.scores[rn].g; cT3 += o.scores[rn].t; });
        });
        var series = picked.slice(0, 6).map(function (stu, i) {
            var og = 0, ot = 0;
            Object.keys(stu.scores || {}).forEach(function (rn) { og += stu.scores[rn].g; ot += stu.scores[rn].t; });
            return { name: (String(stu.seat_no) !== '' ? stu.seat_no + ' ' : '') + stu.name, color: P_COLORS[i % P_COLORS.length],
                     values: [ot > 0 ? Math.round(og / ot * 1000) / 10 : null].concat((OSD.rounds || []).map(function (r) { var sc = (stu.scores || {})[r.no]; return sc && sc.t > 0 ? Math.round(sc.g / sc.t * 1000) / 10 : null; })) };
        });
        series.push({ name: '全班平均', color: '#f39c12', values: [cT3 > 0 ? Math.round(cG3 / cT3 * 1000) / 10 : null].concat(trend.map(function (tr) { return tr.rate; })) });
        var wrap = document.getElementById('osPersonRadarBox');
        if (wrap) wrap.innerHTML = radarLegend(series) + radarSvg(dims, series);
    } else {
        osLoadSections(osRound, function (d) {
            if (intval0(d.sec_round) !== intval0(osRound)) return;
            var wrap = document.getElementById('osPersonRadarBox');
            if (!wrap) return;
            var secs = d.sections || [];
            if (!secs.length) { wrap.innerHTML = '<p class="tip" style="text-align:center;padding:12px 0;">该' + ROUND_LABEL + '的绑定模板没有可判分的题组，无法绘制题组雷达</p>'; return; }
            var dims = secs.map(function (s) { return { label: s.title || '题组' }; });
            var series = picked.slice(0, 6).map(function (stu, i) {
                var myPer = (d.sec_rates || {})[String(intval0(stu.id))] || {};
                return { name: (String(stu.seat_no) !== '' ? stu.seat_no + ' ' : '') + stu.name, color: P_COLORS[i % P_COLORS.length],
                         values: secs.map(function (s, si) { var mine = myPer[si]; return mine && mine.n > 0 ? Math.round(mine.c / mine.n * 1000) / 10 : null; }) };
            });
            series.push({ name: '全班平均', color: '#f39c12', values: secs.map(function (s, si) {
                var sum = 0, n = 0;
                Object.keys(d.sec_rates || {}).forEach(function (sid) { var e = d.sec_rates[sid][si]; if (e && e.n > 0) { sum += e.c / e.n * 100; n++; } });
                return n ? Math.round(sum / n * 10) / 10 : null;
            }) });
            wrap.innerHTML = radarLegend(series) + radarSvg(dims, series);
        });
    }
}
// 全班各题次平均率走势（缓存到 OSD._trend；供对比图/雷达/个人弹窗共用）
function osClassTrend() {
    if (OSD._trend) return OSD._trend;
    var trend = (OSD.rounds || []).map(function (r) {
        var g = 0, t = 0, n = 0;
        (OSD.students || []).forEach(function (s) {
            var sc = (s.scores || {})[r.no];
            if (sc && sc.t > 0) { g += sc.g; t += sc.t; n++; }
        });
        return { no: r.no, title: r.title, rate: t > 0 ? Math.round(g / t * 1000) / 10 : null, n: n };
    });
    OSD._trend = trend;
    return trend;
}
// 「☑ 对比」维度：勾选多个题次 → 柱=各次平均率 + 线=较上次增减（ECharts 双轴，渲染进单图区 osCanvas）
function osCompareHtml() {
    return '<div style="margin:10px 0 2px;font-size:13px;font-weight:bold;color:#555;">☑ 题次对比 <span style="font-weight:normal;color:#999;">（柱=各' + ROUND_LABEL + '全班平均' + (OSD.scored ? '得分率' : '正确率') + '，线=较上次增减）</span></div>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;">' + (OSD.rounds || []).map(function (r) {
            return '<label style="display:inline-flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;"><input type="checkbox" ' + (osCmpSel[r.no] ? 'checked' : '')
                + ' onchange="osCmpSelToggle(' + intval0(r.no) + ', this.checked)" autocomplete="off"> ' + qsEsc(r.title ? r.title : '第' + r.no + ROUND_LABEL) + '</label>';
        }).join('') + '</div>';
}
function osCmpSelToggle(no, on) {
    if (on) osCmpSel[no] = true; else delete osCmpSel[no];
    if (osDim === 'cmp') osChartCmp();
}
// ☑ 对比图（ECharts 单图区）：柱=勾选题次的全班平均率，折线（副轴）=相邻题次增减百分点
function osChartCmp() {
    var rounds = (OSD.rounds || []).filter(function (r) { return osCmpSel[r.no]; })
        .map(function (r) {
            var t = null;
            (osClassTrend() || []).forEach(function (tr) { if (intval0(tr.no) === intval0(r.no)) t = tr; });
            return t || { no: r.no, title: r.title, rate: null, n: 0 };
        })
        .filter(function (r) { return r.rate !== null; });
    var opt;
    if (!rounds.length) {
        opt = { title: { text: '勾选的题次暂无已批改成绩', left: 'center', top: 'middle', textStyle: { color: '#999', fontSize: 13 } } };
    } else {
        var labels = rounds.map(function (r) { return r.title ? r.title : '第' + r.no + ROUND_LABEL; });
        var deltas = rounds.map(function (r, i) { return i === 0 ? null : Math.round((r.rate - rounds[i - 1].rate) * 10) / 10; });
        opt = {
            grid: { left: 44, right: 48, top: 34, bottom: 34 },
            tooltip: { trigger: 'axis' },
            legend: { top: 0, textStyle: { color: '#666', fontSize: 11 } },
            xAxis: { type: 'category', data: labels, axisLabel: { color: '#888', fontSize: 11, interval: 0 } },
            yAxis: [{ type: 'value', max: 100, axisLabel: { color: '#888', formatter: '{value}%' }, splitLine: { lineStyle: { color: '#eef1f8' } } },
                    { type: 'value', axisLabel: { color: '#888', formatter: '{value}%' }, splitLine: { show: false } }],
            series: [
                { name: '平均' + (OSD.scored ? '得分率' : '正确率'), type: 'bar', barWidth: '45%', data: rounds.map(function (r) { return r.rate; }),
                  itemStyle: { color: '#667eea', borderRadius: [4, 4, 0, 0] }, label: { show: true, position: 'top', color: '#666', fontSize: 11, formatter: function (p) { return p.value + '%'; } } },
                { name: '较上次', type: 'line', yAxisIndex: 1, data: deltas, lineStyle: { color: '#f39c12', width: 2 }, itemStyle: { color: '#f39c12' }, symbolSize: 8 }
            ]
        };
    }
    ecSet('osCanvas', opt);
}

// ===== 单个评价弹窗 =====
function openEvalDialog(studentId, title) {
    evalTarget = 'single';
    currentEvalStudent = studentId;
    pendingRegStudent = (title === '登记评价') ? studentId : 0;
    document.getElementById('eval_title').textContent = title || '评价';
    document.getElementById('eval_student_name').textContent = document.getElementById('tile_' + studentId).dataset.name;
    var rb = document.getElementById('eval_remove_btn');
    rb.style.display = '';
    rb.textContent = '删除评价';
    rb.title = '删除该学生的评价';
    rb.onclick = removeEval;
    var cur = document.getElementById('tile_' + studentId).dataset.eval;
    buildEvalOptions(cur);
    // 教师点评：仅单个评价弹窗显示（历史/只读视图隐藏），预填已有点评
    var cgroup = document.getElementById('eval_comment_group');
    cgroup.style.display = (VIEW_DATE || VIEW_ONLY) ? 'none' : 'block';
    document.getElementById('eval_comment').value = COMMENTS[studentId] || '';
    document.getElementById('evalModal').classList.add('show');
}
function buildEvalOptions(currentValue) {
    var optionsBox = document.getElementById('eval_options');
    var inputGroup = document.getElementById('eval_input_group');
    optionsBox.innerHTML = '';
    if (EVAL_OPTIONS) {
        inputGroup.style.display = 'none';
        Object.keys(EVAL_OPTIONS).forEach(function (key) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = EVAL_OPTIONS[key];
            btn.onclick = function () {
                optionsBox.querySelectorAll('button').forEach(function (b) { b.classList.remove('sel'); });
                btn.classList.add('sel');
                btn.dataset.selected = key;
            };
            if (currentValue && currentValue === EVAL_OPTIONS[key]) {
                btn.classList.add('sel');
                btn.dataset.selected = key;
            }
            optionsBox.appendChild(btn);
        });
    } else {
        inputGroup.style.display = 'block';
        document.getElementById('eval_input').value = currentValue || '';
    }
}
function closeEval() {
    document.getElementById('evalModal').classList.remove('show');
}
function getSelectedEval() {
    if (EVAL_OPTIONS) {
        var sel = document.querySelector('#eval_options button.sel');
        return sel ? sel.dataset.selected : '';
    }
    return document.getElementById('eval_input').value.trim();
}
function submitEval() {
    var value = getSelectedEval();
    if (value === '') { showToast('请先选择或输入评价内容', 'warn'); return; }
    if (evalTarget === 'batch') {
        batchEvalValue = value;
        document.getElementById('batchEvalHint').style.display = '';
        document.getElementById('batchEvalText').textContent = value;
        closeEval();
        return;
    }
    // 单个评价（未登记学生长按评价：后端自动登记，返回 registered/regts 同步格子状态）
    apiCall('evaluate', 'student_id=' + currentEvalStudent + '&value=' + encodeURIComponent(value) + '&remove=0', function (d) {
        if (d.registered) setTileState(currentEvalStudent, true, d.regts);
        setTileEval(currentEvalStudent, d.eval_value || value);
        refreshStats(d.total, d.registered_count, d.evaluated_count);
        // 教师点评：内容变化时随评价一同保存（空串=清除）
        var cgroup = document.getElementById('eval_comment_group');
        var cval = document.getElementById('eval_comment').value.trim();
        if (cgroup.style.display !== 'none' && cval !== (COMMENTS[currentEvalStudent] || '')) {
            apiCall('comment_save', 'student_id=' + currentEvalStudent + '&content=' + encodeURIComponent(cval), function (cd) {
                if (cd.success) {
                    if (cval !== '') COMMENTS[currentEvalStudent] = cval; else delete COMMENTS[currentEvalStudent];
                }
            });
        }
        closeEval();
    });
}
function removeEval() {
    if (evalTarget !== 'single') return;
    apiCall('evaluate', 'student_id=' + currentEvalStudent + '&value=&remove=1', function (d) {
        setTileEval(currentEvalStudent, '');
        refreshStats(d.total, d.registered_count, d.evaluated_count);
        closeEval();
    });
}

// ===== 批量（已选）操作 =====
function getSelectedIds() {
    var ids = [];
    document.querySelectorAll('.tile-check:checked').forEach(function (cb) {
        ids.push(cb.closest('.tile').dataset.id);
    });
    return ids;
}
function onCheckChange(cb) {
    cb.closest('.tile').classList.toggle('checked', cb.checked);
}
function selectAll(on) {
    document.querySelectorAll('.tile').forEach(function (tile) {
        if (tile.style.display === 'none') return; // 仅操作可见项
        var cb = tile.querySelector('.tile-check');
        cb.checked = on;
        tile.classList.toggle('checked', on);
    });
    updateSelAllBtn();
}
// 工具栏「全选 / 全不选」合一开关（未全选=全选可见项；已全选=全不选）
function allVisibleSelected() {
    var states = [];
    document.querySelectorAll('.tile').forEach(function (tile) {
        if (tile.style.display === 'none') return;
        var cb = tile.querySelector('.tile-check');
        if (cb) states.push(cb.checked);
    });
    return states.length > 0 && states.every(function (v) { return v; });
}
function toggleSelectAll() {
    selectAll(!allVisibleSelected());
}
function updateSelAllBtn() {
    var b = document.getElementById('selAllBtn');
    if (b) b.textContent = allVisibleSelected() ? '全不选' : '全选';
}
// 手动勾选单个格子时同步「全选」按钮与座位组「全选该组」文案（事件委托覆盖所有 .tile-check）
document.addEventListener('change', function (e) {
    if (e.target && e.target.classList && e.target.classList.contains('tile-check')) {
        updateSelAllBtn();
        updateSeatGroupBtns();   // 座位模式下同步本组开关文案（非座位模式无 .seat-panel，空操作）
    }
});
function batchRegisterSelected() {
    if (VIEW_DATE && !requireHistEdit()) return;
    var ids = getSelectedIds();
    if (!ids.length) { showToast('请先勾选学生（可使用全选）', 'warn'); return; }
    apiCall('batch_register', 'student_ids=' + ids.join(','), function (d) {
        ids.forEach(function (id) { setTileState(id, true, d.regts); });
        refreshStats(d.total, d.registered_count, d.evaluated_count);
        // 历史日期补登记：刷新日历摘要人数
        if (VIEW_DATE && typeof d.daily_day_count !== 'undefined') {
            var cs = document.getElementById('calSummary');
            if (cs) cs.textContent = '当天打卡 ' + d.daily_day_count + ' 人';
        }
    });
}
function batchEvalSelected() {
    if (VIEW_DATE && !requireHistEdit()) return;
    var ids = getSelectedIds();
    if (!ids.length) { showToast('请先勾选学生（可使用全选）', 'warn'); return; }
    if (batchEvalValue === '') { openBatchEvalDialog(false); return; }
    // 锁定校验：auto 模式跳过已锁定学生；all 模式禁止操作
    var skipped = 0;
    if (lockMode !== 'none') {
        ids = ids.filter(function (id) {
            var tile = document.getElementById('tile_' + id);
            if (tile && effectiveLocked(tile)) { skipped++; return false; }
            return true;
        });
        if (!ids.length) { showToast('所选学生均已锁定，不能修改点评。可点击「锁定」开关临时解锁。', 'warn'); return; }
    }
    apiCall('batch_evaluate', 'student_ids=' + ids.join(',') + '&value=' + encodeURIComponent(batchEvalValue), function (d) {
        ids.forEach(function (id) { setTileEval(id, batchEvalValue); });
        refreshStats(d.total, d.registered_count, d.evaluated_count);
        if (skipped) showToast('已跳过 ' + skipped + ' 个已锁定的学生（未修改其点评）', 'warn');
    });
}

// ===== API 调用 =====
function apiCall(type, params, cb) {
    fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'type=' + type + '&project_id=' + PROJECT_ID + '&lock=' + lockMode + (ROUNDED ? '&round=' + CURRENT_ROUND : '') + (VIEW_DATE ? '&date=' + VIEW_DATE : '') + '&' + params
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (!data.success) { showToast(data.message || '操作失败', 'error'); return; }
        selfDirty = true;   // 本端自己的操作引起的版本变化：下次版本比对不对齐为外部刷新
        cb(data);
    })
    .catch(function () { showToast('网络错误', 'error'); });
}

// ===== 多端数据变化自动刷新：轮询轻量版本号（页面隐藏时暂停；回前台立即检测一次） =====
// 说明：SSE/WebSocket 长连接会占死单线程开发服务器，故用 ~100 字节的版本指纹轮询实现「触发式」效果：
// 只有数据真正变化时才 reload 整页，平时每次仅收发一个极小 JSON，流量开销可忽略。
var dataVersion = null;
var selfDirty = false;   // 本端刚做过登记/评价操作：下次版本比对按自己的变化处理，不整页刷新
function fetchVersion(initial) {
    if (document.hidden) return;   // 页面不可见时不检测，节约流量
    fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'type=view_version&project_id=' + PROJECT_ID
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (!d || !d.success || !d.version) return;
        if (initial || dataVersion === null) { dataVersion = d.version; return; }   // 基线
        if (d.version === dataVersion) return;
        if (selfDirty) { selfDirty = false; dataVersion = d.version; return; }      // 自己的操作
        // 弹窗或「是否取消」确认层打开时暂不打断，仅对齐基线（下次变化再刷）
        if (document.querySelector('.modal-mask.show, .tile.confirming')) { dataVersion = d.version; return; }
        // 其他端修改了数据：拉取完整状态原地更新（不整页刷新，保留全屏/视图状态）
        fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'type=view_state&project_id=' + PROJECT_ID + '&cls_id=' + PV_CLASS_ID
                + (ROUNDED ? '&round=' + CURRENT_ROUND : '') + (VIEW_DATE ? '&date=' + VIEW_DATE : '')
        })
        .then(function (r) { return r.json(); })
        .then(function (st) {
            if (!st || !st.success) { location.reload(); return; }   // 状态拉取失败（如项目被删）才回退整页刷新
            dataVersion = st.version || d.version;
            applyViewState(st);
        })
        .catch(function () {});
    })
    .catch(function () {});
}
setInterval(fetchVersion, 10000);           // 每 10 秒检测一次（仅页面可见时实际请求）
window.addEventListener('focus', function () { fetchVersion(); });   // 回到本页立即检测
fetchVersion(true);

// ===== 无刷新原地更新：外部端数据变化后按状态同步瓦片/统计/日历/轮次（保留全屏与视图状态） =====
function applyViewState(st) {
    if (st.first_ts !== undefined && parseInt(st.first_ts, 10) > 0) FIRST_TS = parseInt(st.first_ts, 10);
    if (DAILY_MODE) {
        // 打卡模式：同步今天/历史日期打卡状态 + 按天评价 + 日历每日人数
        var map = {}, emap = {};
        (st.checks || []).forEach(function (c) { map[c.id] = parseInt(c.regts, 10); emap[c.id] = c.eval || ''; });
        document.querySelectorAll('.tile').forEach(function (tile) {
            var id = parseInt(tile.dataset.id, 10);
            var ts = map[id] || 0;
            var ev = emap[id] || '';
            if ((tile.dataset.state === 'yes') !== !!ts) setTileState(id, !!ts, ts);
            else if (ts) tile.dataset.regts = String(ts);   // 重打卡时间变化
            if ((tile.dataset.eval || '') !== ev) setTileEval(id, ev);   // 按天评价同步（清除/更新跨天残留）
        });
        if (st.cal) { CAL_COUNTS = st.cal; if (calOpened) renderCal(); }
        // 统计框：历史视图 3 格重算；今天视图按格子重算
        var yes = document.querySelectorAll('.tile[data-state="yes"]').length;
        var _sm = document.getElementById('statsModal');
        if (!(_sm && _sm.classList.contains('show') && window.stScopeDirty)) {   // 弹层内已切换统计范围：轮询不覆盖弹层卡片
            var boxes = document.querySelectorAll('.stat-box .num');
            if (VIEW_DATE) {
                if (boxes.length >= 3) {
                    boxes[0].textContent = CLASS_SIZE;
                    boxes[1].textContent = yes;
                    boxes[2].textContent = CLASS_SIZE - yes;
                }
            } else {
                refreshStats();
            }
        }
    } else {
        if (IS_QUIZ && st.correct && JSON.stringify(st.correct) !== JSON.stringify(TILE_CORRECT)) {
            TILE_CORRECT = st.correct;
            Object.keys(TILE_DOTS).forEach(tileDotsRender);   // 预设正确答案被修改：全部卡片绿点重判
        }
        (st.students || []).forEach(function (s) {
            var tile = document.getElementById('tile_' + s.id);
            if (!tile) return;
            var reg = parseInt(s.reg, 10) === 1;
            var curReg = tile.dataset.state === 'yes';
            var curEval = tile.dataset.eval || '';
            var newEval = s.eval || '';
            if (curReg !== reg) setTileState(s.id, reg, parseInt(s.regts || 0, 10));
            else if (reg && s.regts && parseInt(tile.dataset.regts || '0', 10) !== parseInt(s.regts, 10)) tile.dataset.regts = String(s.regts);   // 重扫覆盖时间
            if (curEval !== newEval) setTileEval(s.id, newEval);
            if (IS_QUIZ) {   // 卡片角点无刷新同步：外部端登记/答题成功后立即显示（绿点=答对；白点=与上/下题不同）
                var d = TILE_DOTS[s.id];
                if (!d) d = TILE_DOTS[s.id] = { cur: '', prev: '', next: '' };
                var before = d.cur + '|' + d.prev + '|' + d.next;
                d.cur = newEval;
                if (s.prev !== undefined) d.prev = s.prev;
                if (s.next !== undefined) d.next = s.next;
                if ((d.cur + '|' + d.prev + '|' + d.next) !== before) tileDotsRender(s.id);
            }
        });
        if (st.stats) refreshStats(parseInt(st.stats.total, 10), parseInt(st.stats.reg, 10), parseInt(st.stats.ev, 10));
    }
    // 项次/题次：轮次列表/标题变化时原地重建轮次胶囊（当前轮次被删才回退整页刷新）
    if (ROUNDED && st.rounds) {
        if (st.titles && JSON.stringify(st.titles) !== JSON.stringify(ROUND_TITLES)) {
            ROUND_TITLES = st.titles;
            renderRoundChips();
        }
        var same = st.rounds.length === ROUNDS.length && st.rounds.every(function (v, i) { return v === ROUNDS[i]; });
        if (!same) {
            if (st.rounds.indexOf(CURRENT_ROUND) === -1) { location.reload(); return; }
            ROUNDS = st.rounds;
            renderRoundChips();
        }
    }
    // 答题统计弹窗打开中：原地刷新统计
    if (IS_QUIZ && document.getElementById('quizStatsModal').classList.contains('show')) {
        if (qsMode === 'single') qsLoadRound(qsRound, false); else qsRefreshCompare(false);
    }
    applyFilter(document.getElementById('stuKwBox').value.trim().toLowerCase(), currentShow);   // 保持当前筛选
}
function renderRoundChips() {   // 重建轮次切换胶囊：默认显示前 3 个，◀▶ 平移显示窗口（仅显示，点胶囊才切换）；＋ 增项；⏳ 展开全部面板点选
    var box = document.getElementById('roundChips');
    if (!box) return;
    var maxWin = Math.max(0, ROUNDS.length - CHIP_SIZE);
    if (CHIP_WIN > maxWin) CHIP_WIN = maxWin;
    var html = [];
    if (ROUNDS.length > CHIP_SIZE) {
        html.push('<a class="btn btn-sm btn-outline" href="javascript:void(0)" onclick="chipMove(-1)" title="向左平移显示"' + (CHIP_WIN <= 0 ? ' style="visibility:hidden;"' : '') + '>◀</a>');
    }
    ROUNDS.slice(CHIP_WIN, CHIP_WIN + CHIP_SIZE).forEach(function (rn) {
        var t = pvRoundTitle(rn);
        html.push('<a class="btn btn-sm' + (rn === CURRENT_ROUND ? '' : ' btn-outline') + '" href="' + pvRoundUrl(rn) + '" title="第' + rn + ROUND_LABEL + (t ? '：' + pvEsc(t) : '') + '">' + (t ? pvEsc(t) : rn) + '</a>');
    });
    if (ROUNDS.length > CHIP_SIZE) {
        html.push('<a class="btn btn-sm btn-outline" href="javascript:void(0)" onclick="chipMove(1)" title="向右平移显示"' + (CHIP_WIN >= maxWin ? ' style="visibility:hidden;"' : '') + '>▶</a>');
    }
    if (PV_ROUND_ADMIN) {
        html.push('<a class="btn btn-sm btn-success" href="javascript:void(0)" onclick="roundAdd()" title="增加一个' + ROUND_LABEL + '">＋</a>');
    }
    if (ROUNDS.length > CHIP_SIZE) {
        html.push('<a class="btn btn-sm btn-outline" href="javascript:void(0)" onclick="toggleRoundPanel()" title="' + (ROUND_PANEL ? '收起全部' + ROUND_LABEL + '面板' : '展开全部' + ROUND_LABEL + '，方便点选切换') + '">' + (ROUND_PANEL ? '⏵ 收起' : '⏳ 展开') + '</a>');
    }
    box.innerHTML = html.join('');
    // ⏳ 展开面板：全部胶囊网格（仿打卡日历展开效果），当前题次高亮
    var panel = document.getElementById('roundPanel');
    if (panel) {
        if (ROUND_PANEL) {
            var ph = ['<div style="display:flex;flex-wrap:wrap;gap:8px;">'];
            ROUNDS.forEach(function (rn, i) {
                var t = pvRoundTitle(rn);
                ph.push('<a class="btn btn-sm' + (rn === CURRENT_ROUND ? '' : ' btn-outline') + '" href="' + pvRoundUrl(rn) + '" title="第' + (i + 1) + '个：第' + rn + ROUND_LABEL + (t ? '：' + pvEsc(t) : '') + '" style="' + (rn === CURRENT_ROUND ? 'background:#667eea;border-color:#667eea;' : '') + '">' + (t ? pvEsc(t) : rn) + '</a>');
            });
            ph.push('</div>');
            panel.innerHTML = ph.join('');
            panel.style.display = '';
        } else {
            panel.style.display = 'none';
            panel.innerHTML = '';
        }
    }
    var cur = document.getElementById('roundCur');
    if (cur) {
        var ct = pvRoundTitle(CURRENT_ROUND);
        cur.textContent = '当前：第 ' + CURRENT_ROUND + ' ' + ROUND_LABEL + (ct ? '：' + ct : '');
    }
}
renderRoundChips();   // 初始渲染（胶囊区 HTML 为空容器，全靠 JS 填充）

// ===== 状态更新 =====
function setTileState(studentId, registered, regts) {
    var tile = document.getElementById('tile_' + studentId);
    if (!tile) return;
    tile.classList.toggle('on', !!registered);
    tile.dataset.state = registered ? 'yes' : 'no';
    if (registered) {
        if (regts) tile.dataset.regts = regts;
        // 补登记：相对首位登记时间判定
        var ts = parseInt(tile.dataset.regts || '0', 10);
        if (ts && !FIRST_TS) { FIRST_TS = ts; }
        tile.dataset.late = (ts && FIRST_TS && ts > FIRST_TS + LATE_SECONDS) ? '1' : '0';
    } else {
        tile.dataset.regts = '0';
        tile.dataset.late = '0';
        tile.classList.remove('evaled');
        ['qa', 'qa-A', 'qa-B', 'qa-C', 'qa-D'].forEach(function (c) { tile.classList.remove(c); });   // 答题模式选项背景一并清除
        tile.dataset.eval = '';
        var ev = document.getElementById('tile_eval_' + studentId);
        if (ev) ev.textContent = '';
    }
    tile.classList.toggle('locked', effectiveLocked(tile));
    applyModeToTile(tile);      // 大屏模式联动：消消乐消失 / 搬搬乐移列
    updateModeVisuals();
}
function setTileEval(studentId, value) {
    var tile = document.getElementById('tile_' + studentId);
    if (!tile) return;
    tile.classList.toggle('evaled', !!value);
    tile.dataset.eval = value || '';
    var ev = document.getElementById('tile_eval_' + studentId);
    if (ev) ev.textContent = value || '';
    if (IS_QUIZ) {   // 答题模式：卡片背景 = 选项颜色
        var v = (value || '').toUpperCase();
        ['A', 'B', 'C', 'D'].forEach(function (k) { tile.classList.toggle('qa-' + k, v === k); });
        tile.classList.toggle('qa', ['A', 'B', 'C', 'D'].indexOf(v) > -1);
    }
}
// ===== 学生卡片角点（与统计名单同规则）：右上绿点=本题选项为预设正确答案；左下白点=与上一题次不同；右下白点=与下一题次不同（相同或相邻次未答不显示） =====
function tileDotsRender(studentId) {
    var tile = document.getElementById('tile_' + studentId);
    if (!tile) return;
    tile.querySelectorAll('.qs-dot').forEach(function (d) { d.remove(); });
    var d = TILE_DOTS[studentId];
    if (!d || !d.cur) return;
    var dots = '';
    if (TILE_CORRECT.indexOf(d.cur) >= 0) dots += '<i class="qs-dot qs-dot-ok" title="选 ' + d.cur + '，答对（正确答案 ' + TILE_CORRECT.join('、') + '）"></i>';
    if (d.prev && d.prev !== d.cur) dots += '<i class="qs-dot qs-dot-l" title="上一题选 ' + d.prev + '，与本题不同"></i>';
    if (d.next && d.next !== d.cur) dots += '<i class="qs-dot qs-dot-r" title="下一题选 ' + d.next + '，与本题不同"></i>';
    if (dots) tile.insertAdjacentHTML('beforeend', dots);
}
Object.keys(TILE_DOTS).forEach(tileDotsRender);   // 初始渲染（答题/举牌模式专用；无数据时 TILE_DOTS 为空对象，无操作）
function refreshStats(total, regCount, evalCount) {
    var _sm = document.getElementById('statsModal');
    if (_sm && _sm.classList.contains('show') && window.stScopeDirty) return;   // 弹层内已切换统计范围：轮询不覆盖弹层卡片
    var boxes = document.querySelectorAll('.stat-box .num');
    if (DAILY_MODE) {
        // 打卡模式：今日打卡/已评价直接按格子状态重算
        if (boxes.length >= 4) {
            var yes = document.querySelectorAll('.tile[data-state="yes"]').length;
            var ev = document.querySelectorAll('.tile.evaled').length;
            boxes[0].textContent = CLASS_SIZE;
            boxes[1].textContent = yes;
            boxes[2].textContent = CLASS_SIZE - yes;
            boxes[3].textContent = ev;
        }
        return;
    }
    if (boxes.length >= 4) {
        boxes[0].textContent = total;
        boxes[1].textContent = regCount;
        boxes[2].textContent = <?php echo count($students); ?> - regCount;
        boxes[3].textContent = evalCount;
    }
}

// ===== 过滤 =====
function filterTiles() {
    var kw = document.getElementById('stuKwBox').value.trim().toLowerCase();
    applyFilter(kw, currentShow);
}
// 兜底：Chrome 对已保存联系方式类填充无视 autocomplete/id/readonly 之外的最后一道防线——
// 搜索框在【未被聚焦】时被塞入纯数字长串（手机号特征）即清空；用户手动输入编号时框必聚焦，不受影响
(function () {
    var sb = document.getElementById('stuKwBox');
    if (!sb) return;
    sb.addEventListener('input', function () {
        if (document.activeElement !== sb && /^\d{7,}$/.test(sb.value)) {
            sb.value = '';
            filterTiles();
        }
    });
    window.addEventListener('load', function () {
        setTimeout(function () {
            if (/^\d{7,}$/.test(sb.value)) { sb.value = ''; filterTiles(); }
        }, 600);
    });
})();
var currentShow = 'all';
function showAll(state) {
    currentShow = state;
    applyFilter(document.getElementById('stuKwBox').value.trim().toLowerCase(), state);
}
function applyFilter(kw, state) {
    document.querySelectorAll('.tile').forEach(function (tile) {
        var name = (tile.dataset.name || '').toLowerCase();
        var no = (tile.dataset.no || '').toLowerCase();
        var sno = (tile.dataset.sno || '').toLowerCase();   // 座号（seat_no）也参与搜索
        var matchKw = kw === '' || name.indexOf(kw) > -1 || no.indexOf(kw) > -1 || sno.indexOf(kw) > -1;
        var matchState = state === 'all'
            || (state === 'late' ? tile.dataset.late === '1'
            : (state === 'ev' || state === 'unev') ? (tile.dataset.state === 'yes' && ((tile.dataset.eval || '') !== '') === (state === 'ev'))
            : tile.dataset.state === state);
        tile.style.display = (matchKw && matchState) ? '' : 'none';
    });
}

// ===== 打卡日历（默认折叠） =====
var calOpened = false;
var CAL_OPEN = <?php echo (intval($_GET['cal'] ?? 0) === 1) ? 'true' : 'false'; ?>;   // 经日历点选日期跳转而来：日历保持展开
var calY = <?php echo $is_history ? intval(substr(strval($view_date), 0, 4)) : intval(date('Y')); ?>;
var calM = <?php echo $is_history ? intval(substr(strval($view_date), 5, 2)) : intval(date('n')); ?>;   // 1-12（历史日期定位到该月）
var TODAY = '<?php echo date('Y-m-d'); ?>';
if (CAL_OPEN && !calOpened) { calOpened = true; renderCal(); }   // 保持展开：加载即渲染当前查看月

function toggleCal() {
    var box = document.getElementById('calBox');
    if (!box) return;
    var show = box.style.display === 'none';
    box.style.display = show ? '' : 'none';
    if (show && !calOpened) { calOpened = true; renderCal(); }
}
function calMove(delta) {
    calM += delta;
    if (calM < 1) { calM = 12; calY--; }
    if (calM > 12) { calM = 1; calY++; }
    renderCal();
}
function pad2(n) { return (n < 10 ? '0' : '') + n; }
function renderCal() {
    var title = document.getElementById('calTitle');
    var grid = document.getElementById('calGrid');
    if (!title || !grid) return;
    title.textContent = calY + ' 年 ' + calM + ' 月';

    var html = ['日', '一', '二', '三', '四', '五', '六'].map(function (w) {
        return '<div style="text-align:center;font-size:12px;color:#999;padding:2px 0;">' + w + '</div>';
    });

    var first = new Date(calY, calM - 1, 1);
    var daysInMonth = new Date(calY, calM, 0).getDate();
    var startW = first.getDay(); // 0=周日
    for (var i = 0; i < startW; i++) html.push('<div></div>');

    for (var d = 1; d <= daysInMonth; d++) {
        var ds = calY + '-' + pad2(calM) + '-' + pad2(d);
        var cnt = CAL_COUNTS[ds] || 0;
        var isToday = ds === TODAY;
        var isViewing = ds === VIEW_DATE;
        var isPast = ds < TODAY;   // 今天之前：均可点击查看（默认只读，解锁后可补登）
        var style = 'text-align:center;padding:6px 0;border-radius:6px;font-size:13px;';
        var cell;
        if (isViewing) {
            style += 'background:#667eea;color:#fff;font-weight:bold;cursor:default;';
            cell = '<div style="' + style + '" title="正在查看">' + d + '</div>';
        } else if (cnt > 0) {
            style += 'background:#27ae60;color:#fff;cursor:pointer;';
            cell = '<div style="' + style + '" onclick="goDate(\'' + ds + '\')" title="打卡 ' + cnt + ' 人">' + d + '<br><span style="font-size:11px;">' + cnt + '人</span></div>';
        } else if (isToday) {
            style += 'border:1px solid #667eea;color:#667eea;';
            cell = '<div style="' + style + '" onclick="goDate(\'\')" title="返回今天">' + d + '</div>';
        } else if (isPast) {
            style += 'background:#eef0fd;color:#667eea;cursor:pointer;';
            cell = '<div style="' + style + '" onclick="goDate(\'' + ds + '\')" title="无打卡记录，点击查看该日（默认只读，勾选「临时解锁编辑」后可补登）">' + d + '</div>';
        } else {
            style += 'color:#999;';
            cell = '<div style="' + style + '">' + d + '</div>';
        }
        html.push(cell);
    }
    grid.innerHTML = html.join('');
}
function goDate(ds) {
    location.href = 'project_view.php?project_id=' + PROJECT_ID + '&cls_id=' + <?php echo $current_class_id; ?> + (ds ? '&date=' + ds : '') + '&cal=1';
}

// ===== 统计弹窗（📊 统计按钮打开；答题/举牌/答题卡模式顶部「📊 统计」直接打开对应统计弹层，不走这里） =====
function openStatsModal() {
    var m = document.getElementById('statsModal');
    if (m) {
        if (typeof stInitScope === 'function') stInitScope();   // 首次打开时初始化右上角范围控件（确保 CURRENT_ROUND 已定义）
        m.classList.add('show');
        if (typeof stRender === 'function') setTimeout(stRender, 0);   // 先按快照渲染兜底（避免隐藏容器 0×0 / 网络慢白屏）
        if (typeof stScopeChange === 'function') stScopeChange();      // 再拉取最新统计覆盖（登记/评价实时数据，不用页面快照）
    }
}

// ===== 倒计时：补登记 / 自动锁定（仅今天视图） =====
function fmtRemain(sec) {
    sec = Math.max(0, Math.floor(sec));
    var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    var t = '';
    if (h) t += h + '时';
    if (h || m) t += m + '分';
    return t + s + '秒';
}
function updateCountdown() {
    if (VIEW_DATE) return;
    var now = Date.now() / 1000;
    var lateEl = document.getElementById('lateCd');
    if (lateEl) {
        if (FIRST_TS > 0) {
            var rem = Math.floor(FIRST_TS + LATE_SECONDS - now);
            lateEl.textContent = rem > 0
                ? '⏳ ' + fmtRemain(rem) + '后登记记为「补登记」'
                : '⏹ 已超过补登记阈值，新登记均记为「补登记」';
        } else {
            lateEl.textContent = '⏳ 首位登记后 ' + fmtRemain(LATE_SECONDS) + ' 内登记正常，其后记为「补登记」';
        }
    }
    var lockEl = document.getElementById('lockCd');
    if (lockEl) {
        var minRem = Infinity;
        document.querySelectorAll('.tile[data-state="yes"]').forEach(function (t) {
            var ts = parseInt(t.dataset.regts || '0', 10);
            if (ts > 0) { var r = ts + LOCK_SECONDS - now; if (r < minRem) minRem = r; }
        });
        if (minRem === Infinity) lockEl.textContent = '🔒 首位登记后 ' + fmtRemain(LOCK_SECONDS) + ' 自动锁定';
        else if (minRem > 0) lockEl.textContent = '🔒 最快 ' + fmtRemain(minRem) + '后自动锁定';
        else lockEl.textContent = '🔒 已有登记自动锁定（可点「锁定」开关临时解锁）';
    }
}
setInterval(updateCountdown, 1000);

// ===== 初始化锁定状态 =====
(function () {
    var sw = document.getElementById('swLock');
    if (sw) {
        // 全部登记完毕且都超过锁定时间 → 默认开启（绿色）；否则默认关闭
        sw.checked = allLockedNow();
        updateLockLabel();
    }
    refreshLockVisual();
    updateCountdown();
})();
</script>
<style>
.tile {
    position: relative;
    background: #f0f2f5;
    border: 2px solid transparent;
    border-radius: 10px;
    padding: 10px 6px 8px;
    text-align: center;
    cursor: pointer;
    user-select: none;
    transition: all .15s;
    min-width: 0;          /* 防止格子内容撑破网格 */
    overflow: hidden;
}
.tile:hover { border-color: #667eea; }
.tile.on { background: #27ae60; color: #fff; }
.tile.on .tile-name, .tile.on .tile-eval { color: rgba(255,255,255,0.9); }
.tile.on .tile-sno { color: rgba(255,255,255,0.85); }
.tile.evaled { border-color: #f39c12; }
<?php if ($is_abcd): /* 答题/举牌模式：隐藏 style.css 旧版「已评价」绿点（.tile.evaled::after），避免与右上角答对角标（.qs-dot-ok）叠成双重绿点；计数/多项等模式保留原样 */ ?>
.tile.evaled::after { display: none; }
<?php endif; ?>
.tile.checked { border-color: #667eea; box-shadow: 0 0 0 2px rgba(102,126,234,.3); }
.tile.locked::after {
    content: '🔒';
    position: absolute;
    top: 5px;
    right: 6px;
    font-size: 11px;
    opacity: .9;
    pointer-events: none;
}
.tile.hist-edit { border-color: #e67e22; }   /* 历史日期临时解锁：可编辑状态 */
/* ===== 答题模式：卡片背景 = 选项颜色（点选项即登记成功，取消弹窗不留记录） ===== */
.tile.qa-A { background: #667eea; }
.tile.qa-B { background: #27ae60; }
.tile.qa-C { background: #f39c12; }
.tile.qa-D { background: #e74c3c; }
body.shape-bubble .tile.qa-A { background: #667eea; }
body.shape-bubble .tile.qa-B { background: #27ae60; }
body.shape-bubble .tile.qa-C { background: #f39c12; }
body.shape-bubble .tile.qa-D { background: #e74c3c; }
body.shape-bubble .tile.qa { color: #fff; }
body.shape-bubble .tile.qa .tile-no { color: #fff; }
body.shape-bubble .tile.qa .tile-name { color: rgba(255,255,255,.92); }
body.shape-bubble .tile.qa .tile-sno { color: rgba(255,255,255,.85); }
body.shape-bubble .tile.qa .tile-eval { color: #fff; }
/* ===== 答题统计：名单标签（选项颜色底 + 座号姓名，点击看个人变化） ===== */
.qs-tag {
    display: inline-block;
    margin: 3px 6px 3px 0;
    padding: 3px 12px;
    border-radius: 14px;
    color: #fff;
    font-size: 13px;
    line-height: 1.6;
    cursor: pointer;
    position: relative;
}
.qs-tag:hover { filter: brightness(1.1); }
/* 名单标签角点：左下白点=与上一题次选择不同；右下白点=与下一题次选择不同（相同或相邻次未答不显示） */
.qs-dot {
    position: absolute; bottom: 2px;
    width: 7px; height: 7px; border-radius: 50%;
    background: #fff; box-shadow: 0 0 0 1px rgba(0, 0, 0, .18);
}
.qs-dot-l { left: 3px; }
.qs-dot-r { right: 3px; }
/* 学生卡片专用：右上绿点=本题选项为预设正确答案（本题未设置正确答案则不显示）；描边与白点同款深色，避免白描边被误看成第二个点 */
.qs-dot-ok { top: 2px; right: 3px; background: #27ae60; box-shadow: 0 0 0 1px rgba(0, 0, 0, .18); }
/* ===== 次项/题次切换：序号/标题胶囊 + ⚙管理模式下的 铅笔（左上重命名）/「×」（右上删除）钮 ===== */
.round-chip { position: relative; display: inline-flex; margin-top: 6px; }
.round-chip .btn { min-width: 40px; text-align: center; }
.round-del {
    position: absolute; top: -9px; right: -7px; z-index: 2;
    width: 18px; height: 18px; line-height: 16px; text-align: center;
    background: #e74c3c; color: #fff; border-radius: 50%;
    font-size: 13px; font-weight: bold; text-decoration: none;
    box-shadow: 0 1px 3px rgba(0,0,0,.25);
}
.round-del:hover { background: #c0392b; }
.round-ren {
    position: absolute; top: -9px; left: -7px; z-index: 2;
    width: 18px; height: 18px; line-height: 16px; text-align: center;
    background: #f39c12; color: #fff; border-radius: 50%;
    font-size: 11px; text-decoration: none;
    box-shadow: 0 1px 3px rgba(0,0,0,.25);
}
.round-ren:hover { background: #e67e22; }
.tile-no {
    font-size: 22px; font-weight: bold; line-height: 1.2;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.tile-name { font-size: 12px; color: #666; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tile-sno { font-size: 11px; color: #999; margin-top: 1px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tile-eval { font-size: 11px; color: #f39c12; min-height: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tile-check {
    position: absolute; top: 4px; left: 4px;
    width: 15px; height: 15px; cursor: pointer;
    accent-color: #667eea;
}
/* ===== 格子形状：圆形 / 气泡（方形为默认，无附加样式） ===== */
body.shape-round .tile {
    border-radius: 50%;
    padding-top: 14px;
}
body.shape-round .tile .off-confirm { border-radius: 50%; }
body.shape-round .tile .tile-check { top: 7px; left: 13px; }
body.shape-round .tile.locked::after { top: 4px; right: 15px; }
/* 气泡：水晶气泡（半透明径向渐变 + 左上旋弧高光 + 底部反光弧 + Q弹缩放动效，CSS 逐条对齐 paopao 参考实现） */
body.shape-bubble .tile {
    overflow: hidden;
    border-radius: 50%;
    padding-top: 14px;
    background: radial-gradient(circle at 50% 55%, rgba(240,245,255,.9), rgba(240,245,255,.9) 40%, rgba(225,238,255,.8) 60%, rgba(43,130,255,.4));
}
body.shape-bubble .tile.on {
    background: radial-gradient(circle at 50% 55%, rgba(235,255,243,.95), rgba(205,246,222,.9) 40%, rgba(125,216,165,.85) 62%, rgba(39,174,96,.55) 100%);
}
/* 动气泡：仅在「动气泡」形状下播放 Q弹动效（静态气泡不动），各格错峰不整齐划一 */
body.shape-bubble-anim .tile { animation: bubbleAnim 2s ease-out infinite; }
/* 气泡底色为浅色水晶面：已登记(.on)文字由白改深色，避免不可见 */
body.shape-bubble .tile.on { color: #14532d; }
body.shape-bubble .tile.on .tile-no { color: #14532d; }
body.shape-bubble .tile.on .tile-name { color: #1e5631; }
body.shape-bubble .tile.on .tile-sno { color: #2f7d4f; }
body.shape-bubble .tile.on .tile-eval { color: #a05a00; }
/* 左上旋弧高光：环形渐变经位移旋转后与球体相交形成月牙高光（paopao .ball.bubble:before） */
body.shape-bubble .tile::before {
    content: '';
    position: absolute; top: 1%; left: 5%;
    width: 40%; height: 80%;
    border-radius: 50%;
    background: radial-gradient(circle at 130% 130%, rgba(255,255,255,0) 0, rgba(255,255,255,0) 46%, rgba(255,255,255,.8) 50%, rgba(255,255,255,.8) 58%, rgba(255,255,255,0) 60%, rgba(255,255,255,0) 100%);
    transform: translateX(131%) translateY(58%) rotateZ(168deg) rotateX(10deg);
    filter: blur(0);
    pointer-events: none;
    z-index: 2;
}
/* 底部反光弧：大圆环渐变只露出底部弧线（paopao .ball.bubble:after） */
body.shape-bubble .tile::after {
    content: '';
    position: absolute; top: 5%; left: 10%;
    width: 80%; height: 80%;
    border-radius: 50%;
    background: radial-gradient(circle at 50% 80%, rgba(255,255,255,0), rgba(255,255,255,0) 74%, rgba(255,255,255,.9) 80%, rgba(255,255,255,.9) 84%, rgba(255,255,255,0) 100%);
    transform: rotateZ(-30deg);
    filter: blur(1px);
    pointer-events: none;
    z-index: 2;
}
/* 锁定图标仍显示在右上（覆盖反光弧） */
body.shape-bubble .tile.locked::after {
    content: '🔒';
    position: absolute; top: 5px; right: 12%; left: auto; bottom: auto;
    width: auto; height: auto; border-radius: 0; background: none;
    font-size: 11px; opacity: .9;
    transform: none; filter: none;
}
/* Q弹动效（动气泡）：缩放挤压形变（paopao bubble-anim），各格错峰不整齐划一 */
@keyframes bubbleAnim {
    0%   { transform: scale(1); }
    20%  { transform: scaleY(0.95) scaleX(1.05); }
    48%  { transform: scaleY(1.1) scaleX(0.9); }
    68%  { transform: scaleY(0.98) scaleX(1.02); }
    80%  { transform: scaleY(1.02) scaleX(0.98); }
    97%, 100% { transform: scale(1); }
}
body.shape-bubble-anim .tile:nth-child(2n) { animation-delay: -0.8s; }
body.shape-bubble-anim .tile:nth-child(3n) { animation-delay: -1.6s; }
body.shape-bubble-anim .tile:nth-child(5n) { animation-delay: -2.4s; }
body.shape-bubble .tile .tile-check { top: 7px; left: 15%; }
body.shape-bubble .tile .off-confirm { border-radius: 50%; overflow: hidden; }
/* 分组纵向名单模式下气泡呈胶囊形（避免宽行变成椭圆） */
body.shape-bubble #groupBoard.grp-board-v .tile { border-radius: 999px; padding-top: 8px; }
body.shape-bubble #groupBoard.grp-board-v .tile .tile-check { top: 50%; left: 8px; margin-top: -8px; }
/* 全屏显示（仅卡片内容） */
#mainPanel:fullscreen { overflow: auto; padding: 20px; }
#mainPanel:-webkit-full-screen { overflow: auto; padding: 20px; }
#mainPanel.fs-fallback {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    z-index: 9999; margin: 0; border-radius: 0; overflow: auto;
}
/* ===== 取消登记确认层（三模式共用：「是否取消」+ 是/否圆钮） ===== */
.off-confirm {
    position: absolute; inset: 0; z-index: 6;
    background: rgba(44,48,60,.88); color: #fff;
    border-radius: 8px;
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 7px;
    opacity: 0; pointer-events: none; transition: opacity .15s;
}
.tile.confirming .off-confirm { opacity: 1; pointer-events: auto; }
.off-q { font-size: 12px; font-weight: bold; }
.off-btns { display: flex; gap: 16px; }
.off-yes, .off-no {
    width: 36px; height: 36px; border-radius: 50%;
    font-size: 14px; line-height: 1; cursor: pointer; padding: 0;
    border: 2px solid rgba(255,255,255,.9); background: transparent; color: #fff;
}
.off-yes { background: #e74c3c; border-color: #e74c3c; }
.off-yes:hover { background: #c0392b; border-color: #c0392b; }
.off-no:hover { background: rgba(255,255,255,.25); }
/* ===== 大屏模式：消消乐 / 搬搬乐（亮灯为默认，无附加样式） ===== */
.move-col { display: grid; gap: 10px; min-height: 60px; }
/* 大屏四模式（亮灯/消消乐/搬搬乐/座位）工具栏控件一致：一行N / 搜索 / 筛选 / 全选 / 登记已选 / 评价已选 / 批量评价 全部显示
   （答题卡项目在 PHP 端即不渲染批量组；座位模式额外显示「未分组 / 进行分组」按钮） */
#toolBar1 > .seat-only { display: none; }
body.mode-seat #toolBar1 > .seat-only { display: inline-block; }
/* 消消乐：消失动画（显隐由 applyElimView 按当前视图统一控制） */
.tile.bye, body.shape-bubble .tile.bye { animation: tileBye .38s ease forwards; pointer-events: none; }
@keyframes tileBye { to { transform: scale(0); opacity: 0; } }
/* 搬搬乐：移入右列时的闪烁强调（气泡模式下同样生效，期间临时接管动画） */
.tile.just-moved, body.shape-bubble .tile.just-moved { animation: tileMoved .9s ease; }
@keyframes tileMoved { 0% { box-shadow: 0 0 0 5px rgba(39,174,96,.55); } 100% { box-shadow: 0 0 0 0 rgba(39,174,96,0); } }
/* ===== 分组显示（亮灯模式）：横向=各组并排 / 纵向=各组堆叠名单式 ===== */
#groupBoard.grp-board-h { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-start; }
#groupBoard.grp-board-h .grp-panel { flex: 1 1 340px; min-width: 280px; }
#groupBoard.grp-board-v { display: block; }
#groupBoard.grp-board-v .grp-panel { margin-bottom: 14px; }
.grp-panel { border: 1px solid #e3e6f0; border-radius: 10px; padding: 10px 12px; background: #fafbff; }
.grp-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 4px; }
.grp-name { font-weight: bold; font-size: 14px; color: #444; }
.grp-ops a { color: #667eea; font-size: 13px; text-decoration: none; margin-left: 10px; }
.grp-ops a:hover { text-decoration: underline; }
.grp-tiles { display: grid; gap: 10px; }
/* 纵向：名单式一行一人，不受「一行N个序号」控制 */
#groupBoard.grp-board-v .grp-tiles { display: block; }
#groupBoard.grp-board-v .grp-tiles .tile {
    display: flex; align-items: center; text-align: left;
    padding: 7px 12px; gap: 10px; margin-bottom: 6px;
}
#groupBoard.grp-board-v .tile .tile-check { position: static; flex: none; }
#groupBoard.grp-board-v .tile .tile-no { font-size: 15px; flex: none; min-width: 30px; }
#groupBoard.grp-board-v .tile .tile-name { font-size: 14px; margin: 0; flex: none; }
#groupBoard.grp-board-v .tile .tile-sno { margin: 0 0 0 auto; flex: none; }
#groupBoard.grp-board-v .tile .tile-eval { margin: 0 0 0 8px; flex: 0 1 auto; max-width: 45%; }
/* ===== 座位模式：讲台在上方，下方「分组数 × 每组列数」网格，空位虚线占位 ===== */
#seatBoard .seat-bar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 10px; }
#seatBoard .seat-podium {
    background: linear-gradient(180deg, #8d9bd8, #667eea);
    color: #fff; text-align: center; border-radius: 8px;
    padding: 8px 0; font-weight: bold; letter-spacing: 14px; text-indent: 14px;
    font-size: 15px; margin-bottom: 12px;
    box-shadow: 0 2px 8px rgba(102,126,234,.35);
    user-select: none;
}
#seatBoard .seat-groups { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-start; }
#seatBoard .seat-panel { flex: 1 1 220px; min-width: 190px; border: 1px solid #e3e6f0; border-radius: 10px; padding: 10px; background: #fafbff; }
#seatBoard .seat-panel-head { display: flex; justify-content: center; align-items: center; flex-wrap: wrap; gap: 4px; font-size: 14px; font-weight: bold; color: #5568d3; margin-bottom: 8px; }
#seatBoard .seat-panel-head a { color: #667eea; font-size: 12px; font-weight: normal; text-decoration: none; }
#seatBoard .seat-panel-head a:hover { text-decoration: underline; }
#seatBoard .seat-grid { display: grid; gap: 10px; }
#seatBoard .seat-grid .tile { min-height: 58px; }
#seatBoard .seat-empty {
    min-height: 58px; border: 2px dashed #d6daea; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    background: repeating-linear-gradient(45deg, #fbfcff, #fbfcff 8px, #f4f6fc 8px, #f4f6fc 16px);
    color: #c3c9de; font-size: 12px; user-select: none;
}
/* 未分组（未入座）学生池 */
#seatBoard .seat-unpool { margin-top: 12px; border: 1px dashed #e0c48c; border-radius: 10px; padding: 10px 12px; background: #fffdf5; }
#seatBoard .seat-unpool-head { font-size: 13px; color: #b8860b; font-weight: bold; margin-bottom: 8px; }
/* 气泡形状下空座位同样呈圆形 */
body.shape-round #seatBoard .seat-empty, body.shape-bubble #seatBoard .seat-empty { border-radius: 50%; }
/* ===== 项目设置按钮：点击向左侧弹出浮窗（不挤压页面布局），保持展开状态，再次点击收起 ===== */
.pv-dd { position: relative; }
.pv-dd .pv-dd-menu {
    position: absolute;
    right: calc(100% + 10px);
    top: 50%;
    transform: translateY(-50%);
    display: none;
    flex-direction: column;
    align-items: stretch;
    gap: 2px;
    min-width: 170px;
    padding: 6px;
    background: white;
    border: 1px solid #e3e6f0;
    border-radius: 10px;
    box-shadow: 0 10px 28px rgba(0,0,0,0.14);
    z-index: 300;
}
.pv-dd.open .pv-dd-menu { display: flex; }
.pv-dd .pv-dd-menu .dd-item:hover { background: #eef0ff; color: #4a5bc4; }
.pv-dd > button.active { background: #f0f2ff; }
/* ===== 标题行：⚙ 设置 + ⛶ 全屏 与标题同行（h3 右侧），窄屏自动换行 ===== */
#mainPanel h3 { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 8px; }
/* ===== 手机端：工具栏紧凑排列（搜索框与按钮同行挤放，筛选按钮不再一列堆叠） ===== */
@media (max-width: 768px) {
    #toolBar1 { gap: 6px; margin-bottom: 10px; }
    #toolBar1 #stuKwBox { flex: 0 1 130px; width: auto; min-width: 0; max-width: none; padding: 4px 8px; }
    #toolBar1 .btn-sm { padding: 6px 9px; font-size: 13px; white-space: nowrap; }
    /* 标题行：项目名称 / 模式角标 / 锁定开关 / 设置 / 全屏 自动换行 */
    #mainPanel h3 { font-size: 16px; }
    #mainPanel h3 label[for="swLock"], #mainPanel h3 label[for="swHist"] { margin-left: 0 !important; }
    /* 「⚙ 项目设置」展开的浮窗在手机端改为向下展开，避免超出屏幕左侧 */
    .pv-dd .pv-dd-menu { right: auto; left: 0; top: calc(100% + 8px); transform: none; }
}
</style>
<?php page_help('项目登记操作', [
    ['h' => '基本操作', 'items' => [
        '点击学生序号直接<b>登记</b>（亮起 = 已登记）；有评价的序号会显示评价角标',
        '<b>取消登记</b>：点击已登记的序号，格子内弹出「是否取消」确认（是 = 取消登记并<b>同时清除该生已有评价</b>，后台记录评价与清除时间；否 = 不取消）。亮灯 / 消消乐 / 搬搬乐三模式逻辑一致（已锁定或历史只读视图除外）',
        '<b>长按评价</b>：触屏设备上<b>长按</b>某学生卡片（约半秒）弹出该生的评价窗口（普通点击登记不受影响；已锁定 / 仅查看 / 历史未解锁时同样不可用）。<b>未登记的学生长按选择评价内容后即自动完成登记 + 评价</b>（一步到位）',
        '顶部搜索框可按姓名 / 编号快速过滤；「显示全部 / 只看未登记 / 只看已登记 / 只看补登记 / 只看未评价 / 只看已评价」筛选学生',
        '<b>【📊 统计】</b>：非答题 / 答题卡模式点击顶部「📊 统计」展开统计面板（登记率与评价等级占比，默认收起）；答题 / 答题卡模式点击「📊 统计」直接打开统计弹层（本题分布 / 成绩统计）',
        '「一行 N 个序号」可调整网格密度（亮灯 / 消消乐 / 搬搬乐均可用；座位模式按教室布局呈现，无需此设置）；【⛶ 全屏】便于投屏或大屏操作',
        '【⬜ 形状】在标题行<b>「⚙ 设置」</b>展开的工具条中，切换格子外观：<b>方形</b> / <b>圆形</b> / <b>水晶气泡</b>（半透明高光质感，带呼吸浮动动效），循环点击切换',
        '本机记忆：「一行 N 个 / 大屏模式 / 格子形状」<b>按班级分别记忆</b>在本机浏览器中，之后打开该班级的任意项目都保持这套设置，直到再次切换（不同班级互不影响；多班级项目按所选班级记忆）',
        '多端自动刷新：允许多设备同时登录，其他设备发生登记 / 取消 / 评价 / 名单变动后，本页约 10 秒内自动整页刷新（回到本页面立即检测；页面隐藏时暂停检测省流量；弹窗操作中不打断，操作结束后下轮刷新）',
        '【🎮 模式按钮】（「⚙ 设置」展开的工具条中）切换大屏模式（循环：<b>亮灯</b> → <b>消消乐</b> → <b>搬搬乐</b> → <b>座位</b>，自动记忆上次选择）：<b>消消乐</b>只显示未登记学生，点击名字即登记并消失，全部消失 = 完成，可点顶部按钮<b>切换显示未登记 / 已登记</b>（在已登记视图点击可取消，取消后回到未登记视图）；<b>搬搬乐</b>未登记在左列、已登记在右列，点击名字即搬移（点右列弹「是否取消」，确认后搬回左列）；两模式下搜索 / 批量按钮自动隐藏，保留「一行 N 个序号」与「批量评价」开关',
        '【🪑 座位模式】按教室座位呈现：<b>上方讲台</b>、下方按「分组数 × 每组列数」排列座位，<b>空座位以虚线占位</b>（如只有 1 人入座，其余组全部显示空位）。座位与学生分组在<b>「进行分组 / 调整分组」</b>弹窗中配置（与大屏共用学生名单页同一弹窗，拖拽姓名 / 点选入座，可选每组列数、分组数；<b>拖拽「第N组」标题可整组调换顺序，组名跟组走</b>；保存后分组自动按座位生成）。每组标题旁带<b>「全选该组」合一开关</b>（未全选=全选该组，已全选=全不选）。工具栏<b>「查看未分组(n)人」</b>（与「调整分组」按钮同行）展开未入座学生（可正常登记 / 评价）',
        '【📋 分组显示】（在标题行<b>「⚙ 设置」</b>展开的工具条中）按学生分组列出名单（<b>亮灯 / 消消乐 / 搬搬乐三模式均可用</b>）：横向 = 各组并排（格内仍受「一行 N 个」控制）；纵向 = 各组堆叠、名单式一行一人（不受「一行 N 个」控制）。每组带<b>「全选该组」合一开关</b>（未全选=全选该组，已全选=全不选），配合「登记已选 / 评价已选」可整组统一操作（消消乐 / 搬搬乐下批量勾选框自动隐藏，全选按钮同样生效）。班级已有座位数据时，分组显示按座位列自动分组，无需手动建组',
    ]],
    ['h' => '批量操作与开关', 'items' => $is_abcd ? [
        ($is_raise
            ? '<b>举牌</b>：<b>点击</b>学生卡片弹出该生<b>答题变化</b>（历次选择折线图，与统计名单点姓名一致）；<b>长按</b>卡片 = <b>修改或登记</b>（弹窗四选一 A/B/C/D：未登记点选项即登记，已登记可改选，弹窗内「取消登记」可连同答案一并清除）；选中后<b>卡片背景 = 选项颜色</b>'
            : '<b>答题</b>：点击学生卡片弹窗四选一（A/B/C/D），<b>只有点选项才算登记成功</b>（点「取消」= 不留任何记录）；已登记学生弹窗内保留「取消登记」可连同答案一并清除；选中后<b>卡片背景 = 选项颜色</b>，重复点击可改答案'),
        '<b>📊 统计</b>（全屏左侧）：弹窗顶部按钮依次为<b>「◀ 上一题 / 下一题▶」</b>（切换查看各题次统计，不影响当前登记题次）、<b>「🔀 累计」</b>（全部题次对比：复式条形 / 折线统计图，可点「图表切换」)、<b>「✅ 正确率」</b>（每题答对人数统计，条形 / 折线纵轴均为人数）。本题统计中每个选项柱后带<b>「正确答案」勾选框（可多选）</b>，勾选即时保存（柱上标 ✓），正确率视图据此计算；点击柱子 / 拐点显示该次该选项答题人员（选项色标签：座号+姓名），<b>点击学生标签</b>弹出该生多次答题变化折线图；答题项目扫码按答题码朝向识别选项、举牌项目按黑白图案卡朝向识别（均 A上/B右/C下/D左，允许重复识别改答案）',
        '<b>🔒 锁定</b>：勾选 = 全部临时锁定（不能登记 / 取消 / 修改点评）；取消勾选 = 临时解锁全部',
    ] : [
        '<b>全选（合一开关）</b>：未全选时一键全选可见序号，已全选时一键全不选；<b>登记已选</b>：一键登记所有选中项；<b>评价已选</b>：为选中项统一评价',
        '<b>点击</b>：未登记序号点击即登记；已登记序号点击弹出「是否取消」确认（是 = 取消登记并清除该生评价）。<b>评价统一走长按弹窗</b>（未登记确定评价后自动登记；已登记长按 = 修改评价）',
        '<b>批量评价</b>：先设置好评价内容，之后点击的序号全部登记并打相同评价（三模式一致）；弹窗内<b>「清除设置」</b>可随时取消当前批量评价内容（之后点击序号不再自动评价，需重新设置）；如果勾选但没有选择评价内容，每次点击会自动弹窗选择评价。）',
        '<b>🔒 锁定</b>：勾选 = 全部临时锁定（不能登记 / 取消 / 修改点评）；取消勾选 = 临时解锁全部',
    ]],
    ['h' => '补登记与自动锁定', 'items' => [
        '首位学生登记后开始计时，超过设定时长的登记自动记为「补登记」（橙色标记，可用「只看补登记」筛选）',
        '登记时间超过设定时长后序号自动锁定（🔒），需解锁后才能操作',
    ]],
    ['h' => '打卡（日历与历史日期）', 'items' => [
        '打卡项目显示【📅 打卡日历】按钮：绿色日期为已产生打卡记录的日期（数字 = 当天打卡人数）',
        '今天之前的日期（含无记录）均可点击查看，默认只读；有「过期作业项目可编辑」权限（或管理员）可勾选「🔓 临时解锁编辑」后补登 / 取消 / 评价 / 扫码补登',
        '历史日期也可从【📷 扫码补登】进入扫码页补登，仅记该日打卡',
    ]],
    ['h' => '权限说明', 'items' => [
        '「仅查看」授权成员只能查看登记与评价情况，不能操作（页面顶部有橙色提示）',
        '顶部统计栏实时显示登记 / 打卡 / 评价数量',
    ]],
    ['h' => '视图设置（⚙ 设置）', 'items' => [
        '标题行右侧依次为<b>「⚙ 设置」</b>与<b>「⛶ 全屏」</b>：点击「⚙ 设置」向<b>左侧展开</b>视图工具条（⬜ 形状 / 📋 分组显示 / 🎮 亮灯模式 / ⬌ 横向），<b>保持展开状态</b>（点击其他区域不会关闭），再次点击按钮即收起；「🎮 亮灯模式」点击循环切换大屏模式',
        '项目的编辑 / 置顶 / 公开查询 / 办结 / 复制 / 删除等管理功能统一在项目列表页卡片<b>「管理」菜单</b>中操作',
    ]],
]);
?>
<!-- 反向喊话反馈弹窗组件（收到学生回复：弹窗点「✓ 确认」关闭，5 秒未确认自动关闭；轮询/心跳用本页已有实现） -->
<script src="assets/js/rev_notify.js?v=1"></script>
<?php page_footer(); ?>
