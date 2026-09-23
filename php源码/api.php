<?php
/**
 * AJAX 接口
 *  - toggle_register：登记 / 取消登记（支持多班级项目）
 *  - batch_register：批量登记（student_ids 数组）
 *  - scan_register：扫码登记（答题等模式按编号，支持 djxh 码内容或原始编号；举牌模式按学生序号；多班级项目同号跨班消歧；已禁用学生拒绝登记）
 *  - evaluate：评价 / 删除评价
 *  - batch_evaluate：批量设置相同评价
 */
session_start();
require_once 'includes/db_config.php';
require_once 'includes/functions.php';

$type = $_POST['type'] ?? '';
// 仅图片读取（omr_img_get）与积分导出（CSV 下载链接）支持 GET：直链/下载用
if ($type === '' && strval($_GET['type'] ?? '') === 'omr_img_get') $type = 'omr_img_get';
if ($type === '' && strval($_GET['type'] ?? '') === 'points_export') $type = 'points_export';
if ($type === '' && strval($_GET['type'] ?? '') === 'round_export') $type = 'round_export';
$conn = getConnection();

// ===== 喊话客户端轮询（公开接口：令牌即凭证，免登录；班级大屏 announce_client.php 定时取指令） =====
if ($type === 'announce_poll') {
    $token = trim(strval($_POST['token'] ?? ''));
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) json_response(['success' => false, 'message' => '令牌无效']);
    $res = mysqli_query($conn, "SELECT id FROM classes WHERE announce_token = '" . $token . "' AND deleted_at IS NULL");
    $cls = mysqli_fetch_assoc($res);
    if (!$cls) json_response(['success' => false, 'message' => '班级不存在或令牌已失效，请联系教师重新复制链接']);
    $cid = intval($cls['id']);
    // 设备心跳：客户端网页打开即在线（did=浏览器随机设备ID，dtype=pc/phone 由前端 UA 判定）
    $did = trim(strval($_POST['did'] ?? ''));
    if (preg_match('/^[0-9a-f]{32}$/', $did)) {
        $dtype = ($_POST['dtype'] ?? '') === 'phone' ? 'phone' : 'pc';
        $vsup = intval($_POST['vsup'] ?? 1) === 1 ? 1 : 0;   // 设备语音播报支持状态（客户端检测 TTS 后上报）
        mysqli_query($conn, "INSERT INTO announce_devices (class_id, device_id, device_type, voice_ok, last_seen)
                             VALUES ({$cid}, '{$did}', '{$dtype}', {$vsup}, NOW())
                             ON DUPLICATE KEY UPDATE device_type = VALUES(device_type), voice_ok = VALUES(voice_ok), last_seen = NOW()");
        mysqli_query($conn, "DELETE FROM announce_devices WHERE last_seen < DATE_SUB(NOW(), INTERVAL 1 HOUR)");   // 顺带清理陈旧心跳
    }
    // 多端同步：不做「取走即标记」——所有客户端（多屏/PC/手机）都会收到同一条指令，各自按 id 去重；
    // 仅近 5 分钟内的指令有效（防止长期离线后重放陈旧队列），重复轮询会重复下发属预期（客户端 ann_seen 去重）
    $msgs = [];
    $res = mysqli_query($conn, "SELECT m.id, m.mtype, m.content, m.font_size, m.marquee, m.sub_big, m.force_show, m.auto_min, m.cache_min, m.need_ack, m.speed, m.pitch, m.times, m.voice_name,
                                        IFNULL(NULLIF(t.realname, ''), t.username) AS sender_name
                                FROM announce_msgs m LEFT JOIN teachers t ON t.id = m.sender_id
                                WHERE m.class_id = {$cid} AND m.status = 0 AND m.created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                                ORDER BY m.id ASC LIMIT 20");
    while ($row = mysqli_fetch_assoc($res)) {
        $msgs[] = [
            'id' => intval($row['id']), 'mtype' => $row['mtype'], 'content' => $row['content'],
            'font_size' => intval($row['font_size']), 'marquee' => intval($row['marquee']) === 1,
            'sub_big' => intval($row['sub_big']) === 1, 'force_show' => intval($row['force_show']) === 1,
            'auto_min' => intval($row['auto_min']), 'cache_min' => intval($row['cache_min']),
            'need_ack' => intval($row['need_ack']) === 1,
            'speed' => floatval($row['speed']), 'pitch' => floatval($row['pitch']), 'times' => intval($row['times']),
            'voice_name' => strval($row['voice_name']),
            'sender_name' => strval($row['sender_name']),
        ];
    }
    // 教师在线状态（教师页每 10 秒 announce_tping 心跳，15 秒内有心跳视为在线）
    $tonline = false;
    $tr = mysqli_query($conn, "SELECT ann_teacher_seen FROM classes WHERE id = {$cid}");
    if ($tr && ($trow = mysqli_fetch_assoc($tr)) && !empty($trow['ann_teacher_seen'])) {
        $tonline = (time() - strtotime(strval($trow['ann_teacher_seen']))) <= 15;
    }
    json_response(['success' => true, 'msgs' => $msgs, 'teacher_online' => $tonline]);
}

// ===== 喊话客户端反馈上报（公开接口：令牌即凭证；need_ack 指令的「√确认收到/X无法处理」，客户端点「关闭」视同无法处理） =====
if ($type === 'announce_ack') {
    $token = trim(strval($_POST['token'] ?? ''));
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) json_response(['success' => false, 'message' => '令牌无效']);
    $res = mysqli_query($conn, "SELECT id FROM classes WHERE announce_token = '" . $token . "' AND deleted_at IS NULL");
    $cls = mysqli_fetch_assoc($res);
    if (!$cls) json_response(['success' => false, 'message' => '班级不存在或令牌已失效']);
    $id = intval($_POST['id'] ?? 0);
    $st = intval($_POST['st'] ?? 0) === 1 ? 1 : 2;   // 1=确认收到 2=无法处理
    $stmt = mysqli_prepare($conn, "UPDATE announce_msgs SET ack_status = ?, ack_at = NOW() WHERE id = ? AND class_id = ? AND need_ack = 1");
    mysqli_stmt_bind_param($stmt, "iii", $st, $id, $cls['id']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    json_response(['success' => true]);
}

// ===== 反向喊话发送（公开接口：令牌即凭证；班级客户端 → 教师端，需教师端开启开关且勾选对应模式） =====
if ($type === 'announce_rev_send') {
    $token = trim(strval($_POST['token'] ?? ''));
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) json_response(['success' => false, 'message' => '令牌无效']);
    $res = mysqli_query($conn, "SELECT id, rev_announce, rev_ann_modes FROM classes WHERE announce_token = '" . $token . "' AND deleted_at IS NULL");
    $cls = mysqli_fetch_assoc($res);
    if (!$cls) json_response(['success' => false, 'message' => '班级不存在或令牌已失效，请联系教师重新复制链接']);
    if (intval($cls['rev_announce']) !== 1) json_response(['success' => false, 'message' => '教师未开启班级反向喊话']);
    $modes = array_values(array_filter(explode(',', strval($cls['rev_ann_modes']))));
    $mtype = ($_POST['mtype'] ?? 'text') === 'voice' ? 'voice' : 'text';
    if (!in_array($mtype, $modes, true)) json_response(['success' => false, 'message' => $mtype === 'voice' ? '教师未允许语音播报方式' : '教师未允许文字字幕方式']);
    $content = trim(strval($_POST['content'] ?? ''));
    if ($content === '') json_response(['success' => false, 'message' => '请输入喊话内容']);
    if (mb_strlen($content) > 200) json_response(['success' => false, 'message' => '喊话内容请控制在 200 字以内']);
    // 防刷保护：该班级待取走指令超过 20 条时拒绝继续发送
    $res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM announce_rev_msgs WHERE class_id = " . intval($cls['id']) . " AND status = 0");
    if (intval(mysqli_fetch_assoc($res)['c']) >= 20) {
        json_response(['success' => false, 'message' => '教师端尚未接收太多消息，请稍后再试']);
    }
    $font_size = max(24, min(120, intval($_POST['font_size'] ?? 48)));
    $marquee = intval($_POST['marquee'] ?? 0) === 1 ? 1 : 0;   // 按值判断（前端始终携带 marquee=0/1，不能用 isset）
    $times = max(1, min(3, intval($_POST['times'] ?? 1)));
    $stmt = mysqli_prepare($conn, "INSERT INTO announce_rev_msgs (class_id, mtype, content, font_size, marquee, speed, pitch, times, created_at)
                                   VALUES (?, ?, ?, ?, ?, 1, 1, ?, NOW())");
    mysqli_stmt_bind_param($stmt, "issiii", $cls['id'], $mtype, $content, $font_size, $marquee, $times);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    json_response(['success' => true, 'message' => '已发送，等待教师端接收']);
}

// 统一登录校验（AJAX 返回 JSON 而非跳转）
if (!isset($_SESSION['teacher_id']) || empty($_SESSION['teacher_id'])) {
    json_response(['success' => false, 'message' => '请先登录']);
}
$teacher_id = intval($_SESSION['teacher_id']);

// ===== 公共：取项目并校验操作权限，返回 [project, class_ids] =====
if (!function_exists('get_operate_project')) {
    function get_operate_project($conn, $teacher_id, $project_id) {
        $project = get_project_for($conn, $project_id, $teacher_id, 'operate');
        if (!$project) return [null, []];
        $class_ids = get_project_class_ids($conn, $project);
        return [$project, $class_ids];
    }
}

// 项目覆盖班级的 IN 子句
if (!function_exists('class_ids_in')) {
    function class_ids_in($class_ids) {
        return implode(',', array_map('intval', $class_ids));
    }
}

// 锁定开关状态：auto=按登记时间自动锁定 all=临时全部锁定 none=临时全部解锁
$lock_state = $_POST['lock'] ?? 'auto';
if (!in_array($lock_state, ['auto', 'all', 'none'], true)) $lock_state = 'auto';
if ($lock_state === 'all') {
    json_response(['success' => false, 'message' => '已临时全部锁定，不能进行登记操作（可点击「锁定」开关临时解锁）']);
}

// 判断登记记录是否已超过自动锁定时间（lock_seconds<=0 = 不启用）
if (!function_exists('record_is_locked')) {
    function record_is_locked($project, $registered, $registered_at) {
        $ls = intval($project['lock_seconds'] ?? 0);
        if ($ls <= 0) return false;
        if (!$registered || empty($registered_at)) return false;
        return (time() - strtotime($registered_at)) > $ls;
    }
}

// 锁定未超时学生的 SQL 条件（batch_evaluate 用，lock_state=auto 时排除已锁定学生）
if (!function_exists('locked_exclude_sql')) {
    function locked_exclude_sql($project) {
        $ls = intval($project['lock_seconds'] ?? 0);
        if ($ls <= 0) return '';
        return " AND NOT (r.registered = 1 AND r.registered_at IS NOT NULL AND r.registered_at < DATE_SUB(NOW(), INTERVAL {$ls} SECOND))";
    }
}

// ===== 历史日期编辑（打卡模式补登/取消/补评）：date=YYYY-MM-DD（须早于今天） =====
// 返回 ''=非历史编辑；非空=合法历史日期。权限闸门：单用户版恒可编辑（can_edit_expired 恒 true）
if (!function_exists('hist_date_from_post')) {
    function hist_date_from_post($conn, $project, $teacher_id) {
        if (!isset($_POST['date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['date'])) return '';
        if ((($project['mode'] ?? 'count') !== 'daily')) return '';   // 仅打卡模式支持历史编辑
        $hd = $_POST['date'];
        $dt = DateTime::createFromFormat('Y-m-d', $hd);
        if (!$dt || $dt->format('Y-m-d') !== $hd || $hd >= date('Y-m-d')) return '';
        $sid = intval($project['school_id'] ?? 0);
        if (!can_edit_expired($conn, $teacher_id, $sid)) {
            json_response(['success' => false, 'message' => '过期编辑未开放']);
        }
        return $hd;
    }
}

// ===== 页面数据版本号（多端自动刷新用）：仅返回一个轻量指纹，不含明细 =====
// 指纹覆盖：登记/评价/清除留痕的时间与计数 + 打卡日历变动 + 名单人数；任一端发生相关变动即变化
if (!function_exists('view_version_fingerprint')) {
    function view_version_fingerprint($conn, $project, $class_ids) {
        $pid = intval($project['id']);
        $v = [];
        $res = mysqli_query($conn
        , "SELECT COUNT(*) c, COALESCE(SUM(registered = 1), 0) reg,
                                    COALESCE(SUM(COALESCE(eval_value, '') <> ''), 0) ev,
                                    COALESCE(MAX(GREATEST(COALESCE(registered_at, '2000-01-01'), COALESCE(eval_at, '2000-01-01'), COALESCE(eval_cleared_at, '2000-01-01'))), '') ts
                                    FROM records WHERE project_id = {$pid}");
        $row = mysqli_fetch_assoc($res);
        $v[] = intval($row['c']) . ':' . intval($row['reg']) . ':' . intval($row['ev']) . ':' . $row['ts'];
        $res = mysqli_query($conn, "SELECT COUNT(*) c, COALESCE(MAX(created_at), '') ts,
                                    COALESCE(SUM(COALESCE(eval_value, '') <> ''), 0) ev,
                                    COALESCE(MAX(COALESCE(eval_at, '2000-01-01')), '') ets,
                                    COALESCE(SUM(CRC32(COALESCE(eval_value, ''))), 0) ec
                                    FROM record_days WHERE project_id = {$pid}");
        $row = mysqli_fetch_assoc($res);
        $v[] = intval($row['c']) . ':' . $row['ts'] . ':' . intval($row['ev']) . ':' . $row['ets'] . ':' . intval($row['ec']);
        // 次项/题次模式：轮次定义（含自定义标题）与轮次登记/评价也纳入指纹（扫码端答题后大屏自动刷新）
        if (project_is_rounded($project)) {
            $res = mysqli_query($conn, "SELECT COUNT(*) c, COALESCE(MAX(created_at), '') ts, COALESCE(SUM(title IS NOT NULL AND title <> ''), 0) tt FROM project_rounds WHERE project_id = {$pid}");
            $row = mysqli_fetch_assoc($res);
            $v[] = intval($row['c']) . ':' . $row['ts'] . ':' . intval($row['tt']);
            $res = mysqli_query($conn, "SELECT COUNT(*) c, COALESCE(SUM(registered_at IS NOT NULL), 0) reg,
                                        COALESCE(SUM(COALESCE(eval_value, '') <> ''), 0) ev,
                                        COALESCE(MAX(GREATEST(COALESCE(registered_at, '2000-01-01'), COALESCE(eval_at, '2000-01-01'), COALESCE(eval_cleared_at, '2000-01-01'), COALESCE(created_at, '2000-01-01'))), '') ts
                                        FROM record_rounds WHERE project_id = {$pid}");
            $row = mysqli_fetch_assoc($res);
            $v[] = intval($row['c']) . ':' . intval($row['reg']) . ':' . intval($row['ev']) . ':' . $row['ts'];
        }
        $res = mysqli_query($conn, "SELECT COUNT(*) c FROM students WHERE class_id IN (" . class_ids_in($class_ids) . ")");
        $v[] = intval(mysqli_fetch_assoc($res)['c']);
        return implode('|', $v);
    }
}
if ($type === 'view_version') {
    $project_id = intval($_POST['project_id'] ?? 0);
    list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
    if (!$project) {
        // 只读授权成员（view 级）也在刷新范围内
        $project = get_project_for($conn, $project_id, $teacher_id, 'view');
        $class_ids = $project ? get_project_class_ids($conn, $project) : [];
    }
    if (!$project || !$class_ids) json_response(['success' => false, 'message' => '项目不存在或无权限']);
    json_response(['success' => true, 'version' => view_version_fingerprint($conn, $project, $class_ids)]);
}

// ===== 大屏页面完整状态（多端变化无刷新同步用）：指纹+明细一并返回，前端原地更新瓦片/统计/日历，不打断全屏 =====
if ($type === 'view_state') {
    $project_id = intval($_POST['project_id'] ?? 0);
    list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
    if (!$project) {
        $project = get_project_for($conn, $project_id, $teacher_id, 'view');
        $class_ids = $project ? get_project_class_ids($conn, $project) : [];
    }
    if (!$project || !$class_ids) json_response(['success' => false, 'message' => '项目不存在或无权限']);
    $pid = intval($project['id']);
    $mode = ($project['mode'] ?? 'count');
    $rounded = project_is_rounded($project);

    // 轮次：与页面渲染同口径（round 参数须为有效轮次，否则回落最新一轮）
    $rounds = $rounded ? project_rounds_list($conn, $pid) : [];
    $round = 0;
    if ($rounded) {
        $round = intval($_POST['round'] ?? 0);
        if (!in_array($round, $rounds, true)) $round = $rounds[count($rounds) - 1];
    }
    // 当前班级：cls_id 参数须在项目覆盖范围内（多班级项目）
    $cls = intval($_POST['cls_id'] ?? 0);
    if (!in_array($cls, $class_ids, true)) $cls = $class_ids[0];

    $out = ['success' => true, 'mode' => $mode, 'rounded' => $rounded, 'rounds' => $rounds, 'round' => $round];
    if ($rounded) $out['titles'] = project_rounds_titles($conn, $pid);   // 轮次自定义标题（胶囊无刷新同步用）
    $first = 0;

    if ($rounded) {
        $res = mysqli_query($conn, "SELECT rr.student_id AS id, (rr.registered_at IS NOT NULL) AS reg,
                                    UNIX_TIMESTAMP(rr.registered_at) AS regts, COALESCE(rr.eval_value, '') AS eval
                                    FROM record_rounds rr INNER JOIN students s ON rr.student_id = s.id
                                    WHERE rr.project_id = {$pid} AND rr.round_no = {$round} AND s.disabled = 0 AND s.class_id = {$cls}");
        $students = [];
        $abcd = (($project['eval_mode'] ?? '') === 'abcd');
        $nb = [];       // [round_no][student_id] = 'A'~'D'（相邻题次选项：卡片角点无刷新同步用）
        $correct = [];  // 本题次正确答案
        if ($abcd) {
            $rlist = implode(',', array_map('intval', $rounds));
            $res2 = mysqli_query($conn, "SELECT rr.round_no, rr.student_id, rr.eval_value
                                         FROM record_rounds rr INNER JOIN students s ON rr.student_id = s.id
                                         WHERE rr.project_id = {$pid} AND rr.round_no IN ({$rlist})
                                           AND rr.registered_at IS NOT NULL AND rr.eval_value IS NOT NULL AND rr.eval_value <> ''
                                           AND s.disabled = 0 AND s.class_id = {$cls}");
            while ($row = mysqli_fetch_assoc($res2)) {
                $opt = strtoupper(trim($row['eval_value']));
                if (in_array($opt, ['A', 'B', 'C', 'D'], true)) $nb[intval($row['round_no'])][intval($row['student_id'])] = $opt;
            }
            $res2 = mysqli_query($conn, "SELECT correct_opts FROM project_rounds WHERE project_id = {$pid} AND round_no = {$round}");
            $crow = mysqli_fetch_assoc($res2);
            foreach (explode(',', strtoupper($crow['correct_opts'] ?? '')) as $o) {
                $o = trim($o);
                if (in_array($o, ['A', 'B', 'C', 'D'], true) && !in_array($o, $correct, true)) $correct[] = $o;
            }
        }
        while ($row = mysqli_fetch_assoc($res)) {
            $sid = intval($row['id']);
            $stu = ['id' => $sid, 'reg' => intval($row['reg']), 'regts' => intval($row['regts']), 'eval' => $row['eval']];
            if ($abcd) {
                $stu['prev'] = isset($nb[$round - 1][$sid]) ? $nb[$round - 1][$sid] : '';
                $stu['next'] = isset($nb[$round + 1][$sid]) ? $nb[$round + 1][$sid] : '';
            }
            $students[] = $stu;
        }
        $out['students'] = $students;
        if ($abcd) $out['correct'] = $correct;
        list($reg, $ev) = round_counts($conn, $pid, $round);
        $out['stats'] = ['total' => total_students_in($conn, $class_ids), 'reg' => $reg, 'ev' => $ev];
    } elseif ($mode === 'daily') {
        // 打卡模式：今天/历史日期打卡明细 + 日历每日人数（与页面渲染同口径）
        $date = date('Y-m-d');
        if (isset($_POST['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['date']) && $_POST['date'] !== date('Y-m-d')) $date = $_POST['date'];
        $res = mysqli_query($conn, "SELECT rd.student_id AS id, UNIX_TIMESTAMP(rd.created_at) AS regts, COALESCE(rd.eval_value, '') AS eval
                                    FROM record_days rd INNER JOIN students s ON rd.student_id = s.id
                                    WHERE rd.project_id = {$pid} AND rd.reg_date = '{$date}' AND s.disabled = 0 AND s.class_id = {$cls}");
        $checks = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $checks[] = ['id' => intval($row['id']), 'regts' => intval($row['regts']), 'eval' => $row['eval']];
            $t = intval($row['regts']);
            if ($t && ($first === 0 || $t < $first)) $first = $t;
        }
        $out['checks'] = $checks;
        $out['view_date'] = $date;
        $cal = [];
        $res = mysqli_query($conn, "SELECT DATE_FORMAT(rd.reg_date, '%Y-%m-%d') AS d, COUNT(*) AS c
                                    FROM record_days rd INNER JOIN students s ON rd.student_id = s.id
                                    WHERE rd.project_id = {$pid} AND s.class_id = {$cls} GROUP BY rd.reg_date");
        while ($row = mysqli_fetch_assoc($res)) $cal[$row['d']] = intval($row['c']);
        $out['cal'] = $cal;
    } else {
        $res = mysqli_query($conn, "SELECT r.student_id AS id, r.registered AS reg, UNIX_TIMESTAMP(r.registered_at) AS regts, COALESCE(r.eval_value, '') AS eval
                                    FROM records r INNER JOIN students s ON r.student_id = s.id
                                    WHERE r.project_id = {$pid} AND s.disabled = 0 AND s.class_id = {$cls}");
        $students = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $students[] = ['id' => intval($row['id']), 'reg' => intval($row['reg']), 'regts' => intval($row['regts']), 'eval' => $row['eval']];
            $t = intval($row['regts']);
            if ($t && ($first === 0 || $t < $first)) $first = $t;
        }
        $out['students'] = $students;
        $out['stats'] = ['total' => total_students_in($conn, $class_ids), 'reg' => registered_count($conn, $pid), 'ev' => evaluated_count($conn, $pid)];
    }
    if (!$rounded && $mode !== 'daily') $out['first_ts'] = $first;   // 补登记判定基准（本班首位登记时间）
    elseif ($mode === 'daily') $out['first_ts'] = $first;
    $out['version'] = view_version_fingerprint($conn, $project, $class_ids);
    json_response($out);
}

// ===== 轮次（项次/题次）管理：多次登记 multi / 举牌模式 raise 共用 =====
if (!function_exists('quiz_stats_payload')) {
    function quiz_stats_payload($conn, $project_id, $round, $class_ids) {
        $project_id = intval($project_id);
        $round = intval($round);
        $in_classes = class_ids_in($class_ids);
        $counts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
        $lists = ['A' => [], 'B' => [], 'C' => [], 'D' => []];
        $rows = [];   // 结构化名单（id/选项/姓名/序号/班级/相邻题次选项）：大屏标签式名单与个人变化图用
        $answered = 0;
        // 全部题次各生选项（供名单标签「与上/下次选择不同」角标；轮次增删后重排连续，按 round±1 取相邻题次）
        $nb = [];   // [round_no][student_id] = 'A'~'D'
        $nres = mysqli_query($conn, "SELECT rr.round_no, rr.student_id, COALESCE(rr.eval_value, '') AS opt
                                     FROM record_rounds rr
                                     INNER JOIN students s ON rr.student_id = s.id
                                     WHERE rr.project_id = {$project_id} AND rr.registered_at IS NOT NULL AND s.disabled = 0 AND s.class_id IN ({$in_classes})");
        while ($nrow = mysqli_fetch_assoc($nres)) {
            $nopt = strtoupper(trim($nrow['opt']));
            if (!isset($counts[$nopt])) continue;
            $nb[intval($nrow['round_no'])][intval($nrow['student_id'])] = $nopt;
        }
        $res = mysqli_query($conn, "SELECT rr.student_id, COALESCE(rr.eval_value, '') AS opt, s.name, s.seat_no, c.name AS class_name
                                    FROM record_rounds rr
                                    INNER JOIN students s ON rr.student_id = s.id
                                    INNER JOIN classes c ON s.class_id = c.id
                                    WHERE rr.project_id = {$project_id} AND rr.round_no = {$round}
                                      AND rr.registered_at IS NOT NULL AND s.disabled = 0 AND s.class_id IN ({$in_classes})");
        while ($row = mysqli_fetch_assoc($res)) {
            $opt = strtoupper(trim($row['opt']));
            if (!isset($counts[$opt])) continue;
            $counts[$opt]++;
            $answered++;
            $lists[$opt][] = ($row['class_name'] !== '' ? $row['class_name'] . ' ' : '') . $row['name']
                . ($row['seat_no'] !== '' ? '（' . $row['seat_no'] . '）' : '');
            $sid = intval($row['student_id']);
            $rows[] = ['opt' => $opt, 'id' => $sid, 'name' => $row['name'],
                       'seat' => $row['seat_no'], 'cls' => $row['class_name'],
                       'prev' => isset($nb[$round - 1][$sid]) ? $nb[$round - 1][$sid] : '',
                       'next' => isset($nb[$round + 1][$sid]) ? $nb[$round + 1][$sid] : ''];
        }
        $total = total_students_in($conn, $class_ids);
        // 该题次正确答案（答题模式：统计弹窗勾选保存，逗号分隔存 project_rounds.correct_opts）
        $correct = [];
        $cres = mysqli_query($conn, "SELECT correct_opts FROM project_rounds WHERE project_id = {$project_id} AND round_no = {$round}");
        $crow = mysqli_fetch_assoc($cres);
        if ($crow && !empty($crow['correct_opts'])) {
            foreach (explode(',', strtoupper($crow['correct_opts'])) as $co) {
                $co = trim($co);
                if (isset($counts[$co]) && !in_array($co, $correct, true)) $correct[] = $co;
            }
            sort($correct);
        }
        return ['round' => $round, 'total' => $total, 'answered' => $answered,
                'unanswered' => max(0, $total - $answered), 'counts' => $counts, 'lists' => $lists, 'rows' => $rows,
                'correct' => $correct];
    }
}

if ($type === 'round_list' || $type === 'round_add' || $type === 'round_delete' || $type === 'round_rename'
    || $type === 'round_reorder' || $type === 'round_import' || $type === 'round_export') {
    $project_id = intval($_POST['project_id'] ?? $_GET['project_id'] ?? 0);
    list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
    if (!$project || !project_is_rounded($project)) json_response(['success' => false, 'message' => '项目不存在或非次项/题次模式']);
    // 题次管理类操作（新增/删除/重命名/排序/导入）需「管理员/可建立」级别；仅登记/仅查看只可切换与查看
    if (in_array($type, ['round_add', 'round_delete', 'round_rename', 'round_reorder', 'round_import'], true)
        && !get_project_for($conn, $project_id, $teacher_id, 'manage')) {
        json_response(['success' => false, 'message' => '题次管理需管理员/可建立权限']);
    }
    $pid = intval($project['id']);
    $rounds = project_rounds_list($conn, $pid);

    if ($type === 'round_add') {
        $next = $rounds ? max($rounds) + 1 : 1;
        $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO project_rounds (project_id, round_no, created_at) VALUES (?, ?, NOW())");
        mysqli_stmt_bind_param($stmt, "ii", $pid, $next);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        json_response(['success' => true, 'current' => $next, 'rounds' => project_rounds_list($conn, $pid), 'titles' => project_rounds_titles($conn, $pid)]);
    }
    if ($type === 'round_delete') {
        $del = intval($_POST['round'] ?? 0);
        if (count($rounds) <= 1) json_response(['success' => false, 'message' => '至少保留一次，不能删除']);
        if (!in_array($del, $rounds, true)) json_response(['success' => false, 'message' => '次项不存在']);
        // 有登记/成绩记录的题次：删除需输入当前账号登录密码确认（防误删数据）；无记录可直接删
        $has_omr = mysqli_query($conn, "SHOW TABLES LIKE 'omr_results'");
        $has_omr = ($has_omr && mysqli_num_rows($has_omr) > 0);
        $del_cnt = 0;
        $dcres = mysqli_query($conn, "SELECT COUNT(*) AS c FROM record_rounds WHERE project_id = {$pid} AND round_no = {$del}");
        $del_cnt += intval(mysqli_fetch_assoc($dcres)['c']);
        if ($has_omr) {
            $dcres = mysqli_query($conn, "SELECT COUNT(*) AS c FROM omr_results WHERE project_id = {$pid} AND round_no = {$del}");
            $del_cnt += intval(mysqli_fetch_assoc($dcres)['c']);
        }
        if ($del_cnt > 0) {
            $pwd = strval($_POST['password'] ?? '');
            if ($pwd === '') json_response(['success' => false, 'need_pwd' => true, 'message' => "该次有 {$del_cnt} 条登记记录，删除需输入当前账号登录密码确认"]);
            verify_teacher_password($conn, $teacher_id, $pwd);   // 验证失败自动 JSON 退出
        }
        // 删除该次全部登记/评价数据，其后轮次整体前移重排（轮次按位置管理）
        $stmt = mysqli_prepare($conn, "DELETE FROM record_rounds WHERE project_id = ? AND round_no = ?");
        mysqli_stmt_bind_param($stmt, "ii", $pid, $del);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        // omr 项目：该题次识别结果一并删除并前移（表可能尚未安装：先探测）
        if ($has_omr) {
            $stmt = mysqli_prepare($conn, "DELETE FROM omr_results WHERE project_id = ? AND round_no = ?");
            mysqli_stmt_bind_param($stmt, "ii", $pid, $del);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            mysqli_query($conn, "UPDATE omr_results SET round_no = round_no - 1 WHERE project_id = {$pid} AND round_no > {$del}");
        }
        $stmt = mysqli_prepare($conn, "DELETE FROM project_rounds WHERE project_id = ? AND round_no = ?");
        mysqli_stmt_bind_param($stmt, "ii", $pid, $del);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        mysqli_query($conn, "UPDATE project_rounds SET round_no = round_no - 1 WHERE project_id = {$pid} AND round_no > {$del}");
        mysqli_query($conn, "UPDATE record_rounds SET round_no = round_no - 1 WHERE project_id = {$pid} AND round_no > {$del}");
        $rounds = project_rounds_list($conn, $pid);
        json_response(['success' => true, 'current' => min($del, $rounds[count($rounds) - 1]), 'rounds' => $rounds, 'titles' => project_rounds_titles($conn, $pid)]);
    }
    if ($type === 'round_rename') {
        // 轮次重命名（⚙ 管理模式）：title 空=清除标题（胶囊回退显示序号）；其后轮次删除/前移不追改标题
        $rn = intval($_POST['round'] ?? 0);
        if (!in_array($rn, $rounds, true)) json_response(['success' => false, 'message' => '次项不存在']);
        $title = trim(strval($_POST['title'] ?? ''));
        if (mb_strlen($title) > 30) $title = mb_substr($title, 0, 30);
        $val = $title === '' ? null : $title;
        $stmt = mysqli_prepare($conn, "UPDATE project_rounds SET title = ? WHERE project_id = ? AND round_no = ?");
        mysqli_stmt_bind_param($stmt, "sii", $val, $pid, $rn);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        json_response(['success' => true, 'round' => $rn, 'titles' => project_rounds_titles($conn, $pid)]);
    }
    if ($type === 'round_reorder') {
        // 相邻题次交换顺序（⚙ 管理弹窗 ↑↓）：project_rounds 行整体换位（title/correct_opts 跟随行），
        // record_rounds / omr_results 登记数据同位交换——登记数据始终跟着题次内容走
        $rn = intval($_POST['round'] ?? 0);
        $dir = (strval($_POST['dir'] ?? '') === 'up') ? 'up' : 'down';
        $idx = array_search($rn, $rounds, true);
        if ($idx === false) json_response(['success' => false, 'message' => '次项不存在']);
        if ($dir === 'up' && $idx === 0) json_response(['success' => false, 'message' => '已经是第一个']);
        if ($dir === 'down' && $idx === count($rounds) - 1) json_response(['success' => false, 'message' => '已经是最后一个']);
        $other = $dir === 'up' ? intval($rounds[$idx - 1]) : intval($rounds[$idx + 1]);
        // 唯一键 uk_pr(project_id, round_no) 防冲突：三步换位（10000 临时占位，题次序号不可能达到）
        mysqli_query($conn, "UPDATE project_rounds SET round_no = 10000 WHERE project_id = {$pid} AND round_no = {$other}");
        mysqli_query($conn, "UPDATE project_rounds SET round_no = {$other} WHERE project_id = {$pid} AND round_no = {$rn}");
        mysqli_query($conn, "UPDATE project_rounds SET round_no = {$rn} WHERE project_id = {$pid} AND round_no = 10000");
        mysqli_query($conn, "UPDATE record_rounds SET round_no = 10000 WHERE project_id = {$pid} AND round_no = {$other}");
        mysqli_query($conn, "UPDATE record_rounds SET round_no = {$other} WHERE project_id = {$pid} AND round_no = {$rn}");
        mysqli_query($conn, "UPDATE record_rounds SET round_no = {$rn} WHERE project_id = {$pid} AND round_no = 10000");
        if ($has_omr_probe = mysqli_query($conn, "SHOW TABLES LIKE 'omr_results'")) {
            if (mysqli_num_rows($has_omr_probe) > 0) {
                mysqli_query($conn, "UPDATE omr_results SET round_no = 10000 WHERE project_id = {$pid} AND round_no = {$other}");
                mysqli_query($conn, "UPDATE omr_results SET round_no = {$other} WHERE project_id = {$pid} AND round_no = {$rn}");
                mysqli_query($conn, "UPDATE omr_results SET round_no = {$rn} WHERE project_id = {$pid} AND round_no = 10000");
            }
        }
        $rounds = project_rounds_list($conn, $pid);
        json_response(['success' => true, 'rounds' => $rounds, 'titles' => project_rounds_titles($conn, $pid)]);
    }
    if ($type === 'round_import') {
        // 题次标题批量导入：一行一个标题，逐行应用到第 1、2、3…次；超出现有次数自动新增
        // （只更新标题 / 追加新次，永不删除题次与数据，故无需密码）
        $raw = strval($_POST['titles'] ?? '');
        if (strlen($raw) > 102400) json_response(['success' => false, 'message' => '内容过大（限 100KB）']);
        $imp = [];
        foreach (preg_split('/\r\n|\n|\r/', $raw) as $ln) {
            $ln = trim($ln);
            if ($ln === '') continue;
            if (mb_strlen($ln) > 30) $ln = mb_substr($ln, 0, 30);
            $imp[] = $ln;
        }
        if (!$imp) json_response(['success' => false, 'message' => '未解析到有效标题（每行一个，可从导出的 CSV 复制标题列）']);
        $applied = 0; $added = 0;
        foreach ($imp as $i2 => $t2) {
            $rn2 = $i2 + 1;
            if (in_array($rn2, $rounds, true)) {
                $stmt = mysqli_prepare($conn, "UPDATE project_rounds SET title = ? WHERE project_id = ? AND round_no = ?");
                mysqli_stmt_bind_param($stmt, "sii", $t2, $pid, $rn2);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $applied++;
            } else {
                $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO project_rounds (project_id, round_no, title, created_at) VALUES (?, ?, ?, NOW())");
                mysqli_stmt_bind_param($stmt, "iis", $pid, $rn2, $t2);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $added++;
            }
        }
        json_response(['success' => true, 'applied' => $applied, 'added' => $added,
                       'rounds' => project_rounds_list($conn, $pid), 'titles' => project_rounds_titles($conn, $pid),
                       'current' => current_round_no($conn, $project)]);
    }
    if ($type === 'round_export') {
        // 题次清单导出（CSV 带 BOM）：序号,标题——备份 / 其他项目导入复用
        $dlname = '题次清单_' . $project['name'] . '_' . date('Ymd') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="rounds_' . $pid . '_' . date('Ymd_Hi') . '.csv"; filename*=UTF-8\'\'' . rawurlencode($dlname));
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['序号', '标题']);
        $tmap = project_rounds_titles($conn, $pid);
        foreach ($rounds as $rn2) fputcsv($out, [$rn2, strval($tmap[$rn2] ?? '')]);
        fclose($out);
        exit;
    }
    // round_list 默认响应：附各题次记录数 {round_no: 条数}（管理弹窗判断「有无登记记录」用）
    $rcnt = [];
    $rres = mysqli_query($conn, "SELECT round_no, COUNT(*) AS c FROM record_rounds WHERE project_id = {$pid} GROUP BY round_no");
    while ($r = mysqli_fetch_assoc($rres)) $rcnt[intval($r['round_no'])] = intval($r['c']);
    $omr_probe = mysqli_query($conn, "SHOW TABLES LIKE 'omr_results'");
    if ($omr_probe && mysqli_num_rows($omr_probe) > 0) {
        $rres = mysqli_query($conn, "SELECT round_no, COUNT(*) AS c FROM omr_results WHERE project_id = {$pid} GROUP BY round_no");
        while ($r = mysqli_fetch_assoc($rres)) $rcnt[intval($r['round_no'])] = ($rcnt[intval($r['round_no'])] ?? 0) + intval($r['c']);
    }
    json_response(['success' => true, 'rounds' => $rounds, 'current' => current_round_no($conn, $project), 'titles' => project_rounds_titles($conn, $pid), 'counts' => $rcnt]);
}

// ===== 答题模式统计（题次可由前端切换：round 合法即按该次统计；附全部题次列表供上一题/下一题） =====
if ($type === 'quiz_stats') {
    $project_id = intval($_POST['project_id'] ?? 0);
    list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
    if (!$project || !in_array(($project['mode'] ?? ''), ['quiz', 'raise'], true)) json_response(['success' => false, 'message' => '项目不存在或非答题/举牌模式']);
    json_response(['success' => true] + quiz_stats_payload($conn, intval($project['id']), current_round_no($conn, $project), $class_ids)
        + ['rounds' => project_rounds_list($conn, intval($project['id']))]);
}

// ===== 打卡/普通登记统计弹层：按轮次/日期/时间段切换取数（与 project_view PV_STAT 同口径，供统计弹层右上角范围切换） =====
if ($type === 'view_stats') {
    $project_id = intval($_POST['project_id'] ?? 0);
    list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
    if (!$project) json_response(['success' => false, 'message' => '项目不存在']);
    $pmode = strval($project['mode'] ?? '');
    if (in_array($pmode, ['quiz', 'raise', 'omr'], true)) json_response(['success' => false, 'message' => '答题/举牌/答题卡模式请在对应统计弹层查看']);
    $cls = intval($_POST['cls_id'] ?? 0);
    if ($cls <= 0 || !in_array($cls, array_map('intval', $class_ids), true)) json_response(['success' => false, 'message' => '班级不在项目范围内']);
    $pid = intval($project['id']);
    $daily = ($pmode === 'daily');
    // 本班在册学生（已禁用不计，与登记页口径一致）
    $students = [];
    $stmt = mysqli_prepare($conn, "SELECT id, name, seat_no, group_id FROM students WHERE class_id = ? AND disabled = 0
                                   ORDER BY CAST(seat_no AS UNSIGNED) ASC, id ASC");
    mysqli_stmt_bind_param($stmt, "i", $cls);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $students[intval($row['id'])] = ['id' => intval($row['id']), 'name' => strval($row['name']),
                                         'seat' => intval($row['seat_no']), 'gid' => intval($row['group_id']),
                                         'on' => false, 'ev' => false, 'val' => ''];
    }
    mysqli_stmt_close($stmt);
    $groups = [];
    $res = mysqli_query($conn, "SELECT id, name FROM stu_groups WHERE class_id = {$cls} ORDER BY sort ASC, id ASC");
    while ($row = mysqli_fetch_assoc($res)) $groups[] = ['id' => intval($row['id']), 'name' => strval($row['name'])];
    $on_word = $daily ? '打卡' : '登记';
    $date_label = ''; $eval_dist = []; $rounds = []; $matrix = [];
    if ($daily) {
        // 打卡：date=统计截止日（默认今天）；from=起始日（选填，填了=统计 from~date 累计）
        $date = trim(strval($_POST['date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
        $from = trim(strval($_POST['from'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || $from > $date) $from = '';
        if ($from !== '') {
            $stmt = mysqli_prepare($conn, "SELECT student_id, eval_value FROM record_days
                                           WHERE project_id = ? AND reg_date BETWEEN ? AND ? ORDER BY reg_date ASC, id ASC");
            mysqli_stmt_bind_param($stmt, "iss", $pid, $from, $date);
        } else {
            $stmt = mysqli_prepare($conn, "SELECT student_id, eval_value FROM record_days
                                           WHERE project_id = ? AND reg_date = ? ORDER BY id ASC");
            mysqli_stmt_bind_param($stmt, "iss", $pid, $date);
        }
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
        $on_set = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $sid = intval($row['student_id']);
            if (!isset($students[$sid])) continue;
            $on_set[$sid] = true;
            $evv = trim(strval($row['eval_value']));
            if ($evv !== '') {   // 区间累计：ev=有任意评价；val=最近一次评价（按日期升序覆盖）；分布=逐条聚合
                $students[$sid]['ev'] = true;
                $students[$sid]['val'] = $evv;
                $eval_dist[$evv] = ($eval_dist[$evv] ?? 0) + 1;
            }
        }
        foreach ($students as $sid => $s) $students[$sid]['on'] = isset($on_set[$sid]);
        $date_label = ($from !== '' ? $from . ' ~ ' : '') . $date;
    } else {
        // 普通：round=统计轮次（0=不限定，仅返回轮次清单与矩阵）
        $round = intval($_POST['round'] ?? 0);
        $rlist = project_rounds_list($conn, $pid);
        $titles = project_rounds_titles($conn, $pid);
        foreach ($rlist as $rn) $rounds[] = ['no' => intval($rn), 'title' => strval($titles[$rn] ?? '')];
        if ($round > 0 && in_array($round, $rlist, true)) {
            $stmt = mysqli_prepare($conn, "SELECT rr.student_id, rr.eval_value FROM record_rounds rr
                                           INNER JOIN students s ON rr.student_id = s.id
                                           WHERE rr.project_id = ? AND s.class_id = ? AND rr.round_no = ? AND rr.registered_at IS NOT NULL");
            mysqli_stmt_bind_param($stmt, "iii", $pid, $cls, $round);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            mysqli_stmt_close($stmt);
            while ($row = mysqli_fetch_assoc($res)) {
                $sid = intval($row['student_id']);
                if (!isset($students[$sid])) continue;
                $students[$sid]['on'] = true;
                $evv = trim(strval($row['eval_value']));
                if ($evv !== '') {
                    $students[$sid]['ev'] = true;
                    $students[$sid]['val'] = $evv;
                    $eval_dist[$evv] = ($eval_dist[$evv] ?? 0) + 1;
                }
            }
        }
        // 轮次×学生登记矩阵（个人页签「历次变化」；评价内容一并带出）
        $res = mysqli_query($conn, "SELECT rr.round_no, rr.student_id, (rr.registered_at IS NOT NULL) AS reg, rr.eval_value
                                    FROM record_rounds rr
                                    INNER JOIN students s ON rr.student_id = s.id
                                    WHERE rr.project_id = {$pid} AND s.class_id = {$cls}
                                    ORDER BY rr.round_no ASC, rr.student_id ASC");
        if ($res) while ($r = mysqli_fetch_assoc($res)) {
            $matrix[] = [intval($r['round_no']), intval($r['student_id']), intval($r['reg']) === 1 ? 1 : 0, strval($r['eval_value'])];
        }
    }
    // 评价分布排序：预设等级优先（与页面同口径）；自由输入取 Top5+其他
    $_em_all = get_enabled_eval_modes($conn, $teacher_id);
    $_eval_opts = ($_em_all[$project['eval_mode']]['options'] ?? null);
    if (is_array($_eval_opts) && count($_eval_opts)) {
        $ordered = [];
        foreach (array_keys($_eval_opts) as $k) if (!empty($eval_dist[$k])) { $ordered[$k] = $eval_dist[$k]; unset($eval_dist[$k]); }
        foreach ($eval_dist as $k => $c) $ordered[$k] = $c;
        $eval_dist = $ordered;
    } else {
        arsort($eval_dist);
        if (count($eval_dist) > 5) { $tail = array_splice($eval_dist, 5); $eval_dist['其他'] = array_sum($tail); }
    }
    $ed = [];
    foreach ($eval_dist as $k => $v) $ed[] = ['k' => strval($k), 'v' => intval($v)];
    $on_cnt = 0; $ev_cnt = 0;
    foreach ($students as $s) { if ($s['on']) $on_cnt++; if ($s['ev']) $ev_cnt++; }
    json_response(['success' => true,
        'mode' => $daily ? 'daily' : 'plain', 'onWord' => $on_word, 'dateLabel' => $date_label,
        'roundLabel' => '项次', 'evalOn' => ((count($ed) > 0) || (is_array($_eval_opts) && count($_eval_opts) > 0)),
        'total' => count($students), 'on' => $on_cnt, 'ev' => $ev_cnt, 'evalDist' => $ed,
        'students' => array_values($students), 'groups' => $groups, 'rounds' => $rounds, 'matrix' => $matrix]);
}

// ===== 答题模式：设置题次正确答案（可多选；供「正确率」统计，opts 传空=清除） =====
if ($type === 'round_correct') {
    $project_id = intval($_POST['project_id'] ?? 0);
    list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
    if (!$project || !in_array(($project['mode'] ?? ''), ['quiz', 'raise'], true)) json_response(['success' => false, 'message' => '项目不存在或非答题/举牌模式']);
    $pid = intval($project['id']);
    $round = intval($_POST['round'] ?? 0);
    if (!in_array($round, project_rounds_list($conn, $pid), true)) json_response(['success' => false, 'message' => '题次不存在']);
    $opts = [];
    foreach (explode(',', strtoupper(trim($_POST['opts'] ?? ''))) as $o) {
        $o = trim($o);
        if (in_array($o, ['A', 'B', 'C', 'D'], true)) $opts[$o] = $o;   // 去重
    }
    ksort($opts);
    $val = $opts ? implode(',', $opts) : null;
    $stmt = mysqli_prepare($conn, "UPDATE project_rounds SET correct_opts = ? WHERE project_id = ? AND round_no = ?");
    mysqli_stmt_bind_param($stmt, "sii", $val, $pid, $round);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    json_response(['success' => true, 'round' => $round, 'correct' => array_values($opts)]);
}

// ===== 答题模式一键作答：未登记先登记再写选项（弹窗点选项才算登记成功；取消弹窗=不留任何记录） =====
if ($type === 'quiz_answer') {
    $project_id = intval($_POST['project_id'] ?? 0);
    $student_id = intval($_POST['student_id'] ?? 0);
    $value = strtoupper(trim($_POST['value'] ?? ''));
    list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
    if (!$project || !in_array(($project['mode'] ?? ''), ['quiz', 'raise'], true)) json_response(['success' => false, 'message' => '项目不存在或非答题/举牌模式']);
    if (!in_array($value, ['A', 'B', 'C', 'D'], true)) json_response(['success' => false, 'message' => '答题/举牌模式仅支持 A/B/C/D']);
    // 校验学生属于项目覆盖班级
    $stmt = mysqli_prepare($conn, "SELECT s.id FROM students s
                                   WHERE s.id = ? AND s.class_id IN (" . class_ids_in($class_ids) . ")");
    mysqli_stmt_bind_param($stmt, "i", $student_id);
    mysqli_stmt_execute($stmt);
    $stu = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$stu) json_response(['success' => false, 'message' => '学生不存在']);
    $round = current_round_no($conn, $project);
    $stmt = mysqli_prepare($conn, "SELECT registered_at FROM record_rounds
                                   WHERE project_id = ? AND student_id = ? AND round_no = ?");
    mysqli_stmt_bind_param($stmt, "iii", $project_id, $student_id, $round);
    mysqli_stmt_execute($stmt);
    $rr = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    // 自动锁定：已登记且超时 → 不能改答案；新登记不受影响（lock_state=all 已在全局拒绝）
    if ($rr && !empty($rr['registered_at']) && $lock_state !== 'none' && record_is_locked($project, 1, $rr['registered_at'])) {
        json_response(['success' => false, 'message' => '该生本次登记已超过锁定时间，答案已锁定（可用「锁定」开关临时解锁）']);
    }
    if (!$rr || empty($rr['registered_at'])) {
        $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO record_rounds (project_id, student_id, round_no, registered_at, registered_by, created_at)
                                       VALUES (?, ?, ?, NOW(), 'page', NOW())");
        mysqli_stmt_bind_param($stmt, "iii", $project_id, $student_id, $round);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    $stmt = mysqli_prepare($conn, "UPDATE record_rounds SET eval_value = ?, eval_at = NOW(), eval_cleared_at = NULL
                                   WHERE project_id = ? AND student_id = ? AND round_no = ?");
    mysqli_stmt_bind_param($stmt, "siii", $value, $project_id, $student_id, $round);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $stmt = mysqli_prepare($conn, "SELECT UNIX_TIMESTAMP(registered_at) ts FROM record_rounds
                                   WHERE project_id = ? AND student_id = ? AND round_no = ?");
    mysqli_stmt_bind_param($stmt, "iii", $project_id, $student_id, $round);
    mysqli_stmt_execute($stmt);
    $regts = intval(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['ts'] ?? 0);
    mysqli_stmt_close($stmt);
    list($reg, $ev) = round_counts($conn, $project_id, $round);
    json_response([
        'success' => true,
        'round' => $round,
        'eval_value' => $value,
        'regts' => $regts,
        'total' => total_students_in($conn, $class_ids),
        'registered_count' => $reg,
        'evaluated_count' => $ev,
    ]);
}

// ===== 答题模式多次对比：全部题次统计 + 每生历次选项（复式条形/折线统计图与个人变化图数据源） =====
if ($type === 'quiz_compare') {
    $project_id = intval($_POST['project_id'] ?? 0);
    list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
    if (!$project || !in_array(($project['mode'] ?? ''), ['quiz', 'raise'], true)) json_response(['success' => false, 'message' => '项目不存在或非答题/举牌模式']);
    $pid = intval($project['id']);
    $rounds = project_rounds_list($conn, $pid);
    $data = [];
    foreach ($rounds as $rn) {
        $data[] = ['round' => intval($rn)] + quiz_stats_payload($conn, $pid, $rn, $class_ids);
    }
    // 每生历次选项（未答不写入 opts）
    $stu = [];
    if ($rounds) {
        $in_classes = class_ids_in($class_ids);
        $rlist = implode(',', array_map('intval', $rounds));
        $res = mysqli_query($conn, "SELECT rr.student_id, rr.round_no, COALESCE(rr.eval_value, '') opt, s.name, s.seat_no, s.class_id, c.name AS cls
                                    FROM record_rounds rr
                                    INNER JOIN students s ON rr.student_id = s.id
                                    INNER JOIN classes c ON s.class_id = c.id
                                    WHERE rr.project_id = {$pid} AND rr.round_no IN ({$rlist})
                                      AND rr.registered_at IS NOT NULL AND s.class_id IN ({$in_classes})
                                    ORDER BY rr.student_id, rr.round_no");
        while ($row = mysqli_fetch_assoc($res)) {
            $sid = intval($row['student_id']);
            if (!isset($stu[$sid])) {
                $stu[$sid] = ['name' => $row['name'], 'seat' => $row['seat_no'], 'cls' => $row['cls'],
                              'class_id' => intval($row['class_id']), 'group_id' => 0, 'opts' => []];
            }
            $opt = strtoupper(trim($row['opt']));
            if ($opt !== '') $stu[$sid]['opts'][intval($row['round_no'])] = $opt;
        }
        // 分组信息（分组统计/雷达用）：学生 group_id + 各覆盖班级分组列表（多班级时前端按 class_id 归属）
        if (count($stu)) {
            $res = mysqli_query($conn, "SELECT id, group_id FROM students WHERE id IN (" . implode(',', array_map('intval', array_keys($stu))) . ")");
            while ($row = mysqli_fetch_assoc($res)) {
                $sid = intval($row['id']);
                if (isset($stu[$sid])) $stu[$sid]['group_id'] = intval($row['group_id']);
            }
        }
    }
    $groups = [];
    $in_classes = class_ids_in($class_ids);
    $res = mysqli_query($conn, "SELECT g.id, g.name, g.class_id, c.name AS cls FROM stu_groups g
                                INNER JOIN classes c ON g.class_id = c.id
                                WHERE g.class_id IN ({$in_classes}) ORDER BY g.class_id ASC, g.sort ASC, g.id ASC");
    while ($row = mysqli_fetch_assoc($res)) {
        $groups[] = ['id' => intval($row['id']), 'name' => strval($row['name']),
                     'class_id' => intval($row['class_id']), 'cls' => strval($row['cls'])];
    }
    json_response(['success' => true, 'rounds' => $data, 'groups' => $groups, 'students' => $stu,
                   'titles' => project_rounds_titles($conn, $pid)]);   // 题次名称（分组/雷达视图轴标签用）
}

// ===== 登记 / 取消登记 =====
if ($type === 'toggle_register') {
    $project_id = intval($_POST['project_id'] ?? 0);
    $student_id = intval($_POST['student_id'] ?? 0);

    list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
    if (!$project || !$class_ids) json_response(['success' => false, 'message' => '项目不存在或无权限']);

    // 历史日期编辑（打卡模式补登/取消）：date=YYYY-MM-DD（须早于今天，权限闸门见 helper）
    $hist_date = hist_date_from_post($conn, $project, $teacher_id);
    // 实际操作的打卡日期（历史编辑=指定日期，日常=今天）
    $reg_date = $hist_date !== '' ? $hist_date : date('Y-m-d');

    // 校验学生属于项目覆盖班级（登记模式为项目维度）
    $stmt = mysqli_prepare($conn, "SELECT s.id, s.class_id FROM students s
                                   WHERE s.id = ? AND s.class_id IN (" . class_ids_in($class_ids) . ")");
    mysqli_stmt_bind_param($stmt, "i", $student_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $student_row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    if (!$student_row) json_response(['success' => false, 'message' => '学生不存在']);
    $is_daily = (($project['mode'] ?? 'count') === 'daily');

    if ($is_daily) {
        // 打卡模式：每天独立登记/取消（日常=今天；历史编辑=指定日期），避免跨天残留
        $stmt = mysqli_prepare($conn, "SELECT created_at FROM record_days WHERE project_id = ? AND student_id = ? AND reg_date = ?");
        mysqli_stmt_bind_param($stmt, "iis", $project_id, $student_id, $reg_date);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $day_row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
        $checking_in = !$day_row;

        // 取消打卡：按登记时间校验自动锁定（历史编辑不受 records 锁定计时约束，闸门在 date 校验处）
        if (!$checking_in && $hist_date === '' && $lock_state !== 'none') {
            // 取消今日打卡：按登记时间校验自动锁定
            $stmt = mysqli_prepare($conn, "SELECT registered, registered_at FROM records WHERE project_id = ? AND student_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $project_id, $student_id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $rec = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);
            if (record_is_locked($project, $rec['registered'] ?? 0, $rec['registered_at'] ?? null)) {
                json_response(['success' => false, 'message' => '该学生登记已超过锁定时间，已自动锁定（可用「锁定」开关临时解锁）']);
            }
        }

        if ($checking_in) {
            $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO record_days (project_id, student_id, reg_date, created_at)
                                           VALUES (?, ?, ?, NOW())");
            mysqli_stmt_bind_param($stmt, "iis", $project_id, $student_id, $reg_date);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // 历史编辑只改 record_days（records 为当前/汇总状态，不回写历史）
            if ($hist_date === '') {
                $stmt = mysqli_prepare($conn, "INSERT INTO records (project_id, student_id, registered, registered_at, registered_by)
                                               VALUES (?, ?, 1, NOW(), 'click')
                                               ON DUPLICATE KEY UPDATE registered = 1, registered_at = NOW(), registered_by = 'click'");
                mysqli_stmt_bind_param($stmt, "ii", $project_id, $student_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        } else {
            $stmt = mysqli_prepare($conn, "DELETE FROM record_days WHERE project_id = ? AND student_id = ? AND reg_date = ?");
            mysqli_stmt_bind_param($stmt, "iis", $project_id, $student_id, $reg_date);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            if ($hist_date === '') {
                // 取消打卡：同时清除已有评价并记录清除时间（后续可导出）
                $stmt = mysqli_prepare($conn, "UPDATE records SET registered = 0, registered_at = NULL, registered_by = '',
                                               eval_cleared_at = IF(COALESCE(eval_value, '') <> '', NOW(), eval_cleared_at),
                                               eval_value = NULL, eval_at = NULL WHERE project_id = ? AND student_id = ?");
                mysqli_stmt_bind_param($stmt, "ii", $project_id, $student_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }

        // 该日期打卡人数（该生所在班级）
        $cid = intval($student_row['class_id']);
        $res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM record_days rd INNER JOIN students s ON rd.student_id = s.id
                                    WHERE rd.project_id = {$project_id} AND rd.reg_date = '{$reg_date}' AND s.class_id = {$cid}");
        $day_count = intval(mysqli_fetch_assoc($res)['c']);

        json_response([
            'success' => true,
            'registered' => $checking_in,
            'regts' => $checking_in ? time() : 0,
            'daily_today_count' => $day_count,
            'daily_day_count' => $day_count,
            'total' => total_students_in($conn, $class_ids),
            'registered_count' => registered_count($conn, $project_id),
            'evaluated_count' => evaluated_count($conn, $project_id),
        ]);
    }

    // 次项/题次模式：按当前轮次登记/取消（轮次内一人一条；取消=删除该次记录并清除评价）
    if (project_is_rounded($project)) {
        $round = current_round_no($conn, $project);
        $stmt = mysqli_prepare($conn, "SELECT registered_at FROM record_rounds WHERE project_id = ? AND student_id = ? AND round_no = ?");
        mysqli_stmt_bind_param($stmt, "iii", $project_id, $student_id, $round);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $rr = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
        $checking = !$rr;

        if (!$checking && $lock_state !== 'none' && record_is_locked($project, 1, $rr['registered_at'])) {
            json_response(['success' => false, 'message' => '该生本次登记已超过锁定时间，已自动锁定（可用「锁定」开关临时解锁）']);
        }
        if ($checking) {
            $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO record_rounds (project_id, student_id, round_no, registered_at, registered_by, created_at)
                                           VALUES (?, ?, ?, NOW(), 'click', NOW())");
            mysqli_stmt_bind_param($stmt, "iii", $project_id, $student_id, $round);
        } else {
            $stmt = mysqli_prepare($conn, "DELETE FROM record_rounds WHERE project_id = ? AND student_id = ? AND round_no = ?");
            mysqli_stmt_bind_param($stmt, "iii", $project_id, $student_id, $round);
        }
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        list($reg, $ev) = round_counts($conn, $project_id, $round);
        json_response([
            'success' => true,
            'registered' => $checking,
            'regts' => $checking ? time() : 0,
            'round' => $round,
            'total' => total_students_in($conn, $class_ids),
            'registered_count' => $reg,
            'evaluated_count' => $ev,
        ]);
    }

    // 普通模式：查询当前状态
    $stmt = mysqli_prepare($conn, "SELECT registered, registered_at FROM records WHERE project_id = ? AND student_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $project_id, $student_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    $new_state = ($row && $row['registered'] == 1) ? 0 : 1;

    // 自动锁定：取消登记（及后续修改点评）须在锁定时间内，或使用临时解锁开关
    if ($new_state === 0 && $lock_state !== 'none'
        && record_is_locked($project, $row['registered'] ?? 0, $row['registered_at'] ?? null)) {
        json_response(['success' => false, 'message' => '该学生登记已超过锁定时间，已自动锁定（可用「锁定」开关临时解锁）']);
    }

    if ($row) {
        if ($new_state) {
            $stmt = mysqli_prepare($conn, "UPDATE records SET registered = 1, registered_at = ?, registered_by = ? WHERE project_id = ? AND student_id = ?");
            $now = date('Y-m-d H:i:s');
            $by = 'click';
            mysqli_stmt_bind_param($stmt, "ssii", $now, $by, $project_id, $student_id);
        } else {
            // 取消登记：同时清除已有评价并记录清除时间（后续可导出）
            $stmt = mysqli_prepare($conn, "UPDATE records SET registered = 0, registered_at = NULL, registered_by = '',
                                           eval_cleared_at = IF(COALESCE(eval_value, '') <> '', NOW(), eval_cleared_at),
                                           eval_value = NULL, eval_at = NULL WHERE project_id = ? AND student_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $project_id, $student_id);
        }
    } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO records (project_id, student_id, registered, registered_at, registered_by) VALUES (?, ?, ?, ?, 'click')");
        $now = $new_state ? date('Y-m-d H:i:s') : null;
        mysqli_stmt_bind_param($stmt, "iiis", $project_id, $student_id, $new_state, $now);
    }
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    json_response([
        'success' => true,
        'registered' => $new_state == 1,
        'regts' => $new_state == 1 ? time() : 0,
        'total' => total_students_in($conn, $class_ids),
        'registered_count' => registered_count($conn, $project_id),
        'evaluated_count' => evaluated_count($conn, $project_id),
    ]);
}

// ===== 批量登记 / 取消登记 =====
if ($type === 'batch_register') {
    $project_id = intval($_POST['project_id'] ?? 0);
    $ids_raw = $_POST['student_ids'] ?? '';
    $ids = array_filter(array_map('intval', explode(',', $ids_raw)));

    list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
    if (!$project || !$class_ids) json_response(['success' => false, 'message' => '项目不存在或无权限']);
    if (!$ids) json_response(['success' => false, 'message' => '未选择学生']);

    $in_classes = class_ids_in($class_ids);
    $in_ids = implode(',', $ids);
    // 打卡模式项目：已打今日卡的学生不再刷新登记时间（避免重置锁定计时）
    $is_daily = (($project['mode'] ?? 'count') === 'daily');
    // 历史补登记（打卡模式）：date=指定日期，只写 record_days，不回写 records
    $hist_date = hist_date_from_post($conn, $project, $teacher_id);
    if ($is_daily && $hist_date !== '') {
        $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO record_days (project_id, student_id, reg_date, created_at)
                                       SELECT ?, s.id, ?, NOW() FROM students s
                                       WHERE s.id IN ({$in_ids}) AND s.class_id IN ({$in_classes})");
        mysqli_stmt_bind_param($stmt, "is", $project_id, $hist_date);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        // 该日期打卡人数（所选学生所在班级）
        $res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM record_days rd INNER JOIN students s ON rd.student_id = s.id
                                    WHERE rd.project_id = {$project_id} AND rd.reg_date = '{$hist_date}' AND s.class_id IN ({$in_classes})");
        json_response([
            'success' => true,
            'regts' => time(),
            'daily_day_count' => intval(mysqli_fetch_assoc($res)['c']),
            'registered_count' => registered_count($conn, $project_id),
            'evaluated_count' => evaluated_count($conn, $project_id),
            'total' => total_students_in($conn, $class_ids),
        ]);
    }
    // 次项/题次模式：按当前轮次批量登记（已登记的不再刷新登记时间）
    if (project_is_rounded($project)) {
        $round = current_round_no($conn, $project);
        $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO record_rounds (project_id, student_id, round_no, registered_at, registered_by, created_at)
                                       SELECT ?, s.id, ?, NOW(), 'batch', NOW() FROM students s
                                       WHERE s.id IN ({$in_ids}) AND s.class_id IN ({$in_classes})");
        mysqli_stmt_bind_param($stmt, "ii", $project_id, $round);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        list($reg, $ev) = round_counts($conn, $project_id, $round);
        json_response([
            'success' => true,
            'regts' => time(),
            'round' => $round,
            'registered_count' => $reg,
            'evaluated_count' => $ev,
            'total' => total_students_in($conn, $class_ids),
        ]);
    }
    $skip_restamp = $is_daily
        ? " AND NOT EXISTS (SELECT 1 FROM record_days rd WHERE rd.project_id = ? AND rd.student_id = s.id AND rd.reg_date = CURDATE())"
        : '';
    $stmt = mysqli_prepare($conn, "INSERT INTO records (project_id, student_id, registered, registered_at, registered_by)
                                   SELECT ?, s.id, 1, NOW(), 'batch' FROM students s
                                   WHERE s.id IN ({$in_ids}) AND s.class_id IN ({$in_classes})" . $skip_restamp . "
                                   ON DUPLICATE KEY UPDATE registered = 1, registered_at = NOW(), registered_by = 'batch'");
    if ($is_daily) {
        mysqli_stmt_bind_param($stmt, "ii", $project_id, $project_id);
    } else {
        mysqli_stmt_bind_param($stmt, "i", $project_id);
    }
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // 打卡模式项目：写入当日打卡记录
    if ($is_daily) {
        mysqli_query($conn, "INSERT IGNORE INTO record_days (project_id, student_id, reg_date, created_at)
                             SELECT {$project_id}, s.id, CURDATE(), NOW()
                             FROM students s
                             WHERE s.id IN ({$in_ids}) AND s.class_id IN ({$in_classes})");
    }

    json_response([
        'success' => true,
        'regts' => time(),
        'registered_count' => registered_count($conn, $project_id),
        'evaluated_count' => evaluated_count($conn, $project_id),
        'total' => total_students_in($conn, $class_ids),
    ]);
}

// ===== 批量设置相同评价 =====
if ($type === 'batch_evaluate') {
    $project_id = intval($_POST['project_id'] ?? 0);
    $value = trim($_POST['value'] ?? '');
    $remove = intval($_POST['remove'] ?? 0);
    $ids_raw = $_POST['student_ids'] ?? '';
    $ids = array_filter(array_map('intval', explode(',', $ids_raw)));

    list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
    if (!$project || !$class_ids) json_response(['success' => false, 'message' => '项目不存在或无权限']);
    if (!$ids) json_response(['success' => false, 'message' => '未选择学生']);
    if (!$remove && !in_array(($project['mode'] ?? ''), ['quiz', 'raise'], true) && !validate_eval_value($conn, $project['eval_mode'], $value)) {
        json_response(['success' => false, 'message' => '评价内容不符合当前评价模式要求']);   // 答题/举牌项目的 A-D 由下方分支校验
    }

    $in_classes = class_ids_in($class_ids);
    $in_ids = implode(',', $ids);
    // 历史日期批量评价（打卡模式）：不要求当日已登记、不受锁定限制；无 records 行时补建（registered=0 仅承载评价）
    $hist_date = hist_date_from_post($conn, $project, $teacher_id);
    if ($hist_date !== '') {
        record_days_ensure_charset($conn);
        if ($remove) {
            $stmt = mysqli_prepare($conn, "INSERT INTO records (project_id, student_id, registered, registered_at, registered_by, eval_value, eval_at)
                                           SELECT ?, s.id, 0, NULL, '', NULL, NULL FROM students s
                                           WHERE s.id IN ({$in_ids}) AND s.class_id IN ({$in_classes})
                                           ON DUPLICATE KEY UPDATE eval_cleared_at = IF(COALESCE(eval_value, '') <> '', NOW(), eval_cleared_at), eval_value = NULL, eval_at = NULL");
            mysqli_stmt_bind_param($stmt, "i", $project_id);
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO records (project_id, student_id, registered, registered_at, registered_by, eval_value, eval_at)
                                           SELECT ?, s.id, 0, NULL, '', ?, NOW() FROM students s
                                           WHERE s.id IN ({$in_ids}) AND s.class_id IN ({$in_classes})
                                           ON DUPLICATE KEY UPDATE eval_value = VALUES(eval_value), eval_at = NOW(), eval_cleared_at = NULL");
            mysqli_stmt_bind_param($stmt, "si", $project_id, $value);
        }
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        // 打卡模式历史补评：评价按天写入 record_days（仅该日已打卡者生效，不能凭空补评）
        if ($remove) {
            $stmt = mysqli_prepare($conn, "UPDATE record_days SET eval_value = NULL, eval_at = NULL
                                           WHERE project_id = ? AND reg_date = ? AND student_id IN ({$in_ids})");
            mysqli_stmt_bind_param($stmt, "is", $project_id, $hist_date);
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE record_days SET eval_value = ?, eval_at = NOW()
                                           WHERE project_id = ? AND reg_date = ? AND student_id IN ({$in_ids})");
            mysqli_stmt_bind_param($stmt, "sis", $value, $project_id, $hist_date);
        }
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        json_response([
            'success' => true,
            'registered_count' => registered_count($conn, $project_id),
            'evaluated_count' => evaluated_count($conn, $project_id),
            'total' => total_students_in($conn, $class_ids),
        ]);
    }
    // 打卡模式（今天）：评价按天写入 record_days（仅当日已打卡者生效），records 同步为最近汇总
    if ((($project['mode'] ?? 'count') === 'daily') && $hist_date === '') {
        record_days_ensure_charset($conn);
        if (!$remove && !validate_eval_value($conn, $project['eval_mode'], $value)) {
            json_response(['success' => false, 'message' => '评价内容不符合当前评价模式要求']);
        }
        $today = date('Y-m-d');
        if ($remove) {
            $stmt = mysqli_prepare($conn, "UPDATE record_days SET eval_value = NULL, eval_at = NULL
                                           WHERE project_id = ? AND reg_date = ? AND student_id IN ({$in_ids})");
            mysqli_stmt_bind_param($stmt, "is", $project_id, $today);
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE record_days SET eval_value = ?, eval_at = NOW()
                                           WHERE project_id = ? AND reg_date = ? AND student_id IN ({$in_ids})");
            mysqli_stmt_bind_param($stmt, "sis", $value, $project_id, $today);
        }
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        // records 汇总同步（仅当日已打卡者；自动锁定者排除）
        $lockex_d = ($lock_state === 'auto') ? locked_exclude_sql($project) : '';
        if ($remove) {
            $stmt = mysqli_prepare($conn, "UPDATE records r INNER JOIN record_days rd
                                           ON rd.project_id = r.project_id AND rd.student_id = r.student_id AND rd.reg_date = ?
                                           SET r.eval_cleared_at = IF(COALESCE(r.eval_value, '') <> '', NOW(), r.eval_cleared_at),
                                               r.eval_value = NULL, r.eval_at = NULL
                                           WHERE r.project_id = ? AND r.student_id IN ({$in_ids})" . $lockex_d);
            mysqli_stmt_bind_param($stmt, "si", $today, $project_id);
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE records r INNER JOIN record_days rd
                                           ON rd.project_id = r.project_id AND rd.student_id = r.student_id AND rd.reg_date = ?
                                           SET r.eval_value = ?, r.eval_at = NOW(), r.eval_cleared_at = NULL
                                           WHERE r.project_id = ? AND r.student_id IN ({$in_ids})" . $lockex_d);
            mysqli_stmt_bind_param($stmt, "ssi", $today, $value, $project_id);
        }
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        json_response([
            'success' => true,
            'registered_count' => registered_count($conn, $project_id),
            'evaluated_count' => evaluated_count($conn, $project_id),
            'total' => total_students_in($conn, $class_ids),
        ]);
    }
    // 次项/题次模式：按当前轮次批量评价（仅对该次已登记的学生；auto 锁定时跳过已锁定学生）
    if (project_is_rounded($project)) {
        $round = current_round_no($conn, $project);
        $is_quiz = in_array(($project['mode'] ?? ''), ['quiz', 'raise'], true);
        if ($is_quiz) {
            $value = strtoupper($value);
            if (!$remove && !in_array($value, ['A', 'B', 'C', 'D'], true)) {
                json_response(['success' => false, 'message' => '答题/举牌模式仅支持 A/B/C/D']);
            }
        }
        $ls = intval($project['lock_seconds'] ?? 0);
        $lockex_r = ($lock_state === 'auto' && $ls > 0)
            ? " AND NOT (rr.registered_at IS NOT NULL AND rr.registered_at < DATE_SUB(NOW(), INTERVAL {$ls} SECOND))" : '';
        if ($remove) {
            $stmt = mysqli_prepare($conn, "UPDATE record_rounds rr INNER JOIN students s ON rr.student_id = s.id
                                           SET rr.eval_cleared_at = IF(COALESCE(rr.eval_value, '') <> '', NOW(), rr.eval_cleared_at),
                                               rr.eval_value = NULL, rr.eval_at = NULL
                                           WHERE rr.project_id = ? AND rr.round_no = ? AND rr.registered_at IS NOT NULL
                                             AND rr.student_id IN ({$in_ids}) AND s.class_id IN ({$in_classes})" . $lockex_r);
            mysqli_stmt_bind_param($stmt, "ii", $project_id, $round);
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE record_rounds rr INNER JOIN students s ON rr.student_id = s.id
                                           SET rr.eval_value = ?, rr.eval_at = NOW(), rr.eval_cleared_at = NULL
                                           WHERE rr.project_id = ? AND rr.round_no = ? AND rr.registered_at IS NOT NULL
                                             AND rr.student_id IN ({$in_ids}) AND s.class_id IN ({$in_classes})" . $lockex_r);
            mysqli_stmt_bind_param($stmt, "sii", $value, $project_id, $round);
        }
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        list($reg, $ev) = round_counts($conn, $project_id, $round);
        json_response([
            'success' => true,
            'round' => $round,
            'registered_count' => $reg,
            'evaluated_count' => $ev,
            'total' => total_students_in($conn, $class_ids),
        ]);
    }
    // 仅对已登记的所选学生设置评价（lock_state=auto 时跳过已自动锁定的学生）
    $lock_exclude = ($lock_state === 'auto') ? locked_exclude_sql($project) : '';
    if ($remove) {
        $stmt = mysqli_prepare($conn, "UPDATE records r INNER JOIN students s ON r.student_id = s.id
                                       SET r.eval_cleared_at = IF(COALESCE(r.eval_value, '') <> '', NOW(), r.eval_cleared_at),
                                           r.eval_value = NULL, r.eval_at = NULL
                                       WHERE r.project_id = ? AND r.registered = 1
                                         AND r.student_id IN ({$in_ids}) AND s.class_id IN ({$in_classes})" . $lock_exclude);
        mysqli_stmt_bind_param($stmt, "i", $project_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } else {
        $stmt = mysqli_prepare($conn, "UPDATE records r INNER JOIN students s ON r.student_id = s.id
                                       SET r.eval_value = ?, r.eval_at = NOW(), r.eval_cleared_at = NULL
                                       WHERE r.project_id = ? AND r.registered = 1
                                         AND r.student_id IN ({$in_ids}) AND s.class_id IN ({$in_classes})" . $lock_exclude);
        mysqli_stmt_bind_param($stmt, "si", $value, $project_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    json_response([
        'success' => true,
        'registered_count' => registered_count($conn, $project_id),
        'evaluated_count' => evaluated_count($conn, $project_id),
        'total' => total_students_in($conn, $class_ids),
    ]);
}

// ===== 扫码登记 =====
// 扫码登记单码核心（scan_register / scan_register_multi 共用）：成功返回 payload，失败返回 ['_err' => 提示]
// $with_stats=false（批量用）：省略 registered_count / total / quiz 等全班统计，由批量接口算一次，避免 N 码 N 次聚合查询
function scan_register_one($conn, $teacher_id, $project, $class_ids, $code, $opt, $with_stats) {
    $project_id = intval($project['id']);
    $code = trim(strval($code));

    // 举牌模式：识别号码=学生序号（座号，1-255），按序号在项目覆盖班级内定位（举牌卡全班通用，A 班卡片可在 B 班使用）
    // 其余模式：兼容 djxh 码 / 原始编号（也允许直接输入编号）
    $is_raise_mode = (($project['mode'] ?? '') === 'raise');
    if ($is_raise_mode) {
        $lookup_no = $code;
        $lookup_sql = 's.seat_no = ?';
        if ($lookup_no === '') return ['_err' => '举牌号码为空'];
    } else {
        $lookup_no = parse_student_qr_content($code);
        if ($lookup_no === null) {
            $lookup_no = $code; // 允许直接输入编号
        }
        $lookup_sql = 's.student_no = ?';
        if ($lookup_no === '') return ['_err' => '二维码内容为空'];
    }

    // 在项目覆盖班级内查找学生（多班级项目同号可能重名需消歧；含已禁用学生以便给出明确提示）
    $stmt = mysqli_prepare($conn, "SELECT s.id, s.name, s.seat_no, s.student_no, s.disabled, s.class_id, c.name AS class_name
                                   FROM students s INNER JOIN classes c ON s.class_id = c.id
                                   WHERE {$lookup_sql} AND s.class_id IN (" . class_ids_in($class_ids) . ")");
    mysqli_stmt_bind_param($stmt, "s", $lookup_no);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $matches = [];
    while ($row = mysqli_fetch_assoc($res)) $matches[] = $row;
    mysqli_stmt_close($stmt);

    $no_label = $is_raise_mode ? '序号' : '编号';
    if (!$matches) {
        return ['_err' => '未找到' . $no_label . '为 "' . $lookup_no . '" 的学生（须为项目覆盖班级的学生' . ($is_raise_mode ? '举牌序号' : '二维码') . '）'];
    }
    if (count($matches) > 1) {
        $names = [];
        foreach ($matches as $m) $names[] = $m['class_name'] . ' ' . $m['name'];
        return ['_err' => '该' . $no_label . '在多个班级存在（' . implode('、', $names) . '），无法唯一识别'];
    }
    $student = $matches[0];
    // 已禁用学生：不参与登记（卡片已不打印；手动输入/旧卡扫到也拒绝）
    if (!empty($student['disabled'])) {
        return ['_err' => $student['class_name'] . ' ' . $student['name'] . ' 已禁用，不参与登记'];
    }

    // 查询现有记录（判断是否已登记）
    $stmt = mysqli_prepare($conn, "SELECT registered, eval_value FROM records WHERE project_id = ? AND student_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $project_id, $student['id']);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rec = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    $already = $rec && $rec['registered'] == 1;

    // 历史补登记（打卡模式）：date=指定日期，只写 record_days，不回写 records
    $hist_date = hist_date_from_post($conn, $project, $teacher_id);
    if (($project['mode'] ?? 'count') === 'daily' && $hist_date !== '') {
        $stmt = mysqli_prepare($conn, "SELECT COALESCE(eval_value, '') AS ev FROM record_days WHERE project_id = ? AND student_id = ? AND reg_date = ?");
        mysqli_stmt_bind_param($stmt, "iis", $project_id, $student['id'], $hist_date);
        mysqli_stmt_execute($stmt);
        $day_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $already_day = $day_row ? true : false;
        if (!$already_day) {
            $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO record_days (project_id, student_id, reg_date, created_at)
                                           VALUES (?, ?, ?, NOW())");
            mysqli_stmt_bind_param($stmt, "iis", $project_id, $student['id'], $hist_date);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        $payload = [
            'success' => true,
            'student' => [
                'id' => intval($student['id']),
                'name' => $student['name'],
                'seat_no' => $student['seat_no'],
                'student_no' => $student['student_no'],
                'class_name' => $student['class_name'],
            ],
            'already' => $already_day,
            'hist_date' => $hist_date,
            'eval_value' => $day_row['ev'] ?? '',
        ];
        if ($with_stats) {
            $payload['registered_count'] = registered_count($conn, $project_id);
            $payload['total'] = total_students_in($conn, $class_ids);
        }
        return $payload;
    }

    // 次项/题次模式：按当前轮次登记（同一码允许重复识别，每次都刷新最新结果）
    if (project_is_rounded($project)) {
        $round = current_round_no($conn, $project);
        $stmt = mysqli_prepare($conn, "SELECT registered_at, eval_value FROM record_rounds
                                       WHERE project_id = ? AND student_id = ? AND round_no = ?");
        mysqli_stmt_bind_param($stmt, "iii", $project_id, $student['id'], $round);
        mysqli_stmt_execute($stmt);
        $rr = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $already = $rr && !empty($rr['registered_at']);
        if (!$already) {
            $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO record_rounds (project_id, student_id, round_no, registered_at, registered_by, created_at)
                                           VALUES (?, ?, ?, NOW(), 'scan', NOW())");
            mysqli_stmt_bind_param($stmt, "iii", $project_id, $student['id'], $round);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        // 答题/举牌模式：本次识别携带的选项（A/B/C/D）作为该生本题最新答案（重复识别即改答案）
        $opt = strtoupper(trim(strval($opt)));
        $quiz = in_array(($project['mode'] ?? ''), ['quiz', 'raise'], true);
        $eval_value = $rr['eval_value'] ?? '';
        if ($quiz && in_array($opt, ['A', 'B', 'C', 'D'], true)) {
            $stmt = mysqli_prepare($conn, "UPDATE record_rounds SET eval_value = ?, eval_at = NOW(), eval_cleared_at = NULL
                                           WHERE project_id = ? AND student_id = ? AND round_no = ?");
            mysqli_stmt_bind_param($stmt, "siii", $opt, $project_id, $student['id'], $round);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $eval_value = $opt;
        }
        $payload = [
            'success' => true,
            'round' => $round,
            'already' => $already,
            'eval_value' => $eval_value,
            'student' => [
                'id' => intval($student['id']),
                'name' => $student['name'],
                'seat_no' => $student['seat_no'],
                'student_no' => $student['student_no'],
                'class_name' => $student['class_name'],
            ],
        ];
        if ($with_stats) {
            list($reg, $ev) = round_counts($conn, $project_id, $round);
            $payload['registered_count'] = $reg;
            $payload['evaluated_count'] = $ev;
            $payload['total'] = total_students_in($conn, $class_ids);
            if ($quiz) $payload['quiz'] = quiz_stats_payload($conn, $project_id, $round, $class_ids);
        }
        return $payload;
    }

    // 登记（已登记则保持）
    if (!$already) {
        $stmt = mysqli_prepare($conn, "INSERT INTO records (project_id, student_id, registered, registered_at, registered_by)
                                       VALUES (?, ?, 1, NOW(), 'scan')
                                       ON DUPLICATE KEY UPDATE registered = 1, registered_at = NOW(), registered_by = 'scan'");
        mysqli_stmt_bind_param($stmt, "ii", $project_id, $student['id']);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    // 打卡模式项目：扫码即记当日打卡（即使此前已登记过，也补记今天）
    // already 按「今日已打卡」判定而非 records 项目级：昨天登记过今天再扫应视为今日首次，
    // 前端正常播提示音 + 触发弹窗评价（否则会误报「已登记过」且评价弹窗永不出现，评价写不进去）
    $day_already = false;
    $day_eval = '';
    if (($project['mode'] ?? 'count') === 'daily') {
        $dres = mysqli_query($conn, "SELECT COALESCE(eval_value, '') FROM record_days
                                     WHERE project_id = {$project_id} AND student_id = {$student['id']} AND reg_date = CURDATE()");
        if ($drow = mysqli_fetch_row($dres)) {
            $day_already = true;   // 今日已打过卡：重复扫码忽略
            $day_eval = strval($drow[0]);
        } else {
            mysqli_query($conn, "INSERT IGNORE INTO record_days (project_id, student_id, reg_date, created_at)
                                 VALUES ({$project_id}, {$student['id']}, CURDATE(), NOW())");
        }
    }

    $payload = [
        'success' => true,
        'student' => [
            'id' => intval($student['id']),
            'name' => $student['name'],
            'seat_no' => $student['seat_no'],
            'student_no' => $student['student_no'],
            'class_name' => $student['class_name'],
        ],
        'already' => (($project['mode'] ?? 'count') === 'daily') ? $day_already : $already,
        'eval_value' => (($project['mode'] ?? 'count') === 'daily') ? $day_eval : ($rec['eval_value'] ?? ''),
    ];
    if ($with_stats) {
        $payload['registered_count'] = registered_count($conn, $project_id);
        $payload['total'] = total_students_in($conn, $class_ids);
    }
    return $payload;
}

if ($type === 'scan_register') {
    $project_id = intval($_POST['project_id'] ?? 0);
    $code = trim($_POST['code'] ?? '');

    list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
    if (!$project || !$class_ids) json_response(['success' => false, 'message' => '项目不存在或无权限']);

    $res = scan_register_one($conn, $teacher_id, $project, $class_ids, $code, $_POST['opt'] ?? '', true);
    if (isset($res['_err'])) json_response(['success' => false, 'message' => $res['_err']]);
    json_response($res);
}

// 多码批量登记（多码同识 / 图片批量识别）：一次请求完成 N 个码的登记，全班统计只算一次，响应远快于逐码请求
if ($type === 'scan_register_multi') {
    $project_id = intval($_POST['project_id'] ?? 0);
    $raw = trim($_POST['codes'] ?? '');

    list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
    if (!$project || !$class_ids) json_response(['success' => false, 'message' => '项目不存在或无权限']);

    $arr = json_decode($raw, true);
    if (!is_array($arr) || !$arr) json_response(['success' => false, 'message' => 'codes 参数格式错误']);
    if (count($arr) > 60) json_response(['success' => false, 'message' => '单次批量登记最多 60 个码']);

    $results = [];
    foreach ($arr as $item) {
        $code = trim(strval(is_array($item) ? ($item['code'] ?? '') : $item));
        $opt = strtoupper(trim(strval(is_array($item) ? ($item['opt'] ?? '') : '')));
        $r = scan_register_one($conn, $teacher_id, $project, $class_ids, $code, $opt, false);
        if (isset($r['_err'])) $results[] = ['success' => false, 'message' => $r['_err']];
        else $results[] = $r;
    }

    // 全班统计：循环外只算一次
    $payload = ['success' => true, 'results' => $results];
    if (project_is_rounded($project)) {
        $round = current_round_no($conn, $project);
        $payload['round'] = $round;
        list($reg, $ev) = round_counts($conn, $project_id, $round);
        $payload['registered_count'] = $reg;
        $payload['evaluated_count'] = $ev;
        if (in_array(($project['mode'] ?? ''), ['quiz', 'raise'], true)) {
            $payload['quiz'] = quiz_stats_payload($conn, $project_id, $round, $class_ids);
        }
    } else {
        $payload['registered_count'] = registered_count($conn, $project_id);
    }
    $payload['total'] = total_students_in($conn, $class_ids);
    json_response($payload);
}

// ===== 评价 =====
if ($type === 'evaluate') {
    $project_id = intval($_POST['project_id'] ?? 0);
    $student_id = intval($_POST['student_id'] ?? 0);
    $value = trim($_POST['value'] ?? '');
    $remove = intval($_POST['remove'] ?? 0);

    list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
    if (!$project || !$class_ids) json_response(['success' => false, 'message' => '项目不存在或无权限']);

    // 校验学生属于项目覆盖班级
    $stmt = mysqli_prepare($conn, "SELECT s.id, r.registered, r.registered_at FROM students s
                                   LEFT JOIN records r ON r.student_id = s.id AND r.project_id = ?
                                   WHERE s.id = ? AND s.class_id IN (" . class_ids_in($class_ids) . ")");
    mysqli_stmt_bind_param($stmt, "ii", $project_id, $student_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rec = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if (!$rec) json_response(['success' => false, 'message' => '学生不存在']);
    // 历史日期评价（打卡模式）：按天写入 record_days；records 仅同步汇总留痕
    $hist_date = hist_date_from_post($conn, $project, $teacher_id);
    if ($hist_date !== '') {
        record_days_ensure_charset($conn);
        $in_classes = class_ids_in($class_ids);
        $stmt = mysqli_prepare($conn, "SELECT created_at FROM record_days WHERE project_id = ? AND student_id = ? AND reg_date = ?");
        mysqli_stmt_bind_param($stmt, "iis", $project_id, $student_id, $hist_date);
        mysqli_stmt_execute($stmt);
        $day_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $auto_reg = false;
        if (!$day_row) {
            if ($remove) json_response(['success' => false, 'message' => '该生当日未打卡，无评价可删除']);
            $auto_reg = true;   // 长按未打卡学生评价 = 补登+评价一步完成
        }
        // 先校验评价值合法性（无效值不得触发自动补登，避免误把未打卡学生登记上）
        if (!$remove && !validate_eval_value($conn, $project['eval_mode'], $value)) {
            json_response(['success' => false, 'message' => '评价内容不符合当前评价模式要求']);
        }
        if ($auto_reg) {
            // 长按未打卡学生选择评价：先补登该日打卡（历史编辑只改 record_days，不回写 records）
            $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO record_days (project_id, student_id, reg_date, created_at)
                                           VALUES (?, ?, ?, NOW())");
            mysqli_stmt_bind_param($stmt, "iis", $project_id, $student_id, $hist_date);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        if ($remove) {
            $stmt = mysqli_prepare($conn, "INSERT INTO records (project_id, student_id, registered, registered_at, registered_by, eval_value, eval_at)
                                           SELECT ?, s.id, 0, NULL, '', NULL, NULL FROM students s
                                           WHERE s.id = ? AND s.class_id IN ({$in_classes})
                                           ON DUPLICATE KEY UPDATE eval_cleared_at = IF(COALESCE(eval_value, '') <> '', NOW(), eval_cleared_at), eval_value = NULL, eval_at = NULL");
            mysqli_stmt_bind_param($stmt, "ii", $project_id, $student_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $eval_value = '';
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO records (project_id, student_id, registered, registered_at, registered_by, eval_value, eval_at)
                                           SELECT ?, s.id, 0, NULL, '', ?, NOW() FROM students s
                                           WHERE s.id = ? AND s.class_id IN ({$in_classes})
                                           ON DUPLICATE KEY UPDATE eval_value = VALUES(eval_value), eval_at = NOW(), eval_cleared_at = NULL");
            mysqli_stmt_bind_param($stmt, "isi", $project_id, $value, $student_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $eval_value = $value;
        }
        // 评价按天写入 record_days
        if ($remove) {
            $stmt = mysqli_prepare($conn, "UPDATE record_days SET eval_value = NULL, eval_at = NULL
                                           WHERE project_id = ? AND student_id = ? AND reg_date = ?");
            mysqli_stmt_bind_param($stmt, "iis", $project_id, $student_id, $hist_date);
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE record_days SET eval_value = ?, eval_at = NOW()
                                           WHERE project_id = ? AND student_id = ? AND reg_date = ?");
            mysqli_stmt_bind_param($stmt, "siis", $value, $project_id, $student_id, $hist_date);
        }
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        json_response([
            'success' => true,
            'eval_value' => $eval_value,
            'registered' => 1,
            'regts' => $auto_reg ? time() : intval(strtotime($day_row['created_at'])),
            'total' => total_students_in($conn, $class_ids),
            'registered_count' => registered_count($conn, $project_id),
            'evaluated_count' => evaluated_count($conn, $project_id),
        ]);
    }
    // 打卡模式（今天）：评价按天写入 record_days，records 同步为最近汇总
    if (($project['mode'] ?? 'count') === 'daily') {
        record_days_ensure_charset($conn);
        $today = date('Y-m-d');
        $stmt = mysqli_prepare($conn, "SELECT created_at FROM record_days WHERE project_id = ? AND student_id = ? AND reg_date = ?");
        mysqli_stmt_bind_param($stmt, "iis", $project_id, $student_id, $today);
        mysqli_stmt_execute($stmt);
        $day_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $auto_reg = false;
        if (!$day_row) {
            if ($remove) json_response(['success' => false, 'message' => '该生今天尚未打卡，无评价可删除']);
            $auto_reg = true;   // 长按未打卡学生评价 = 打卡+评价一步完成
        } elseif ($lock_state !== 'none' && record_is_locked($project, 1, $day_row['created_at'])) {
            // 自动锁定：按当日打卡时间校验（临时解锁开关除外）；自动打卡路径无既有记录，不涉及锁定
            json_response(['success' => false, 'message' => '该学生登记已超过锁定时间，点评已锁定（可用「锁定」开关临时解锁）']);
        }
        // 先校验评价值合法性（无效值不得触发自动登记，避免误把未打卡学生登记上）
        if (!$remove && !validate_eval_value($conn, $project['eval_mode'], $value)) {
            json_response(['success' => false, 'message' => '评价内容不符合当前评价模式要求']);
        }
        if ($auto_reg) {
            // 长按未打卡学生选择评价：自动完成今日打卡，records 同步登记状态（与页面点击打卡同构）
            $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO record_days (project_id, student_id, reg_date, created_at)
                                           VALUES (?, ?, ?, NOW())");
            mysqli_stmt_bind_param($stmt, "iis", $project_id, $student_id, $today);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $stmt = mysqli_prepare($conn, "INSERT INTO records (project_id, student_id, registered, registered_at, registered_by)
                                           VALUES (?, ?, 1, NOW(), 'page')
                                           ON DUPLICATE KEY UPDATE registered = 1, registered_at = NOW(), registered_by = 'page'");
            mysqli_stmt_bind_param($stmt, "ii", $project_id, $student_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        if ($remove) {
            $stmt = mysqli_prepare($conn, "UPDATE record_days SET eval_value = NULL, eval_at = NULL
                                           WHERE project_id = ? AND student_id = ? AND reg_date = ?");
            mysqli_stmt_bind_param($stmt, "iis", $project_id, $student_id, $today);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $stmt = mysqli_prepare($conn, "UPDATE records SET eval_cleared_at = IF(COALESCE(eval_value, '') <> '', NOW(), eval_cleared_at),
                                           eval_value = NULL, eval_at = NULL WHERE project_id = ? AND student_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $project_id, $student_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $eval_value = '';
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE record_days SET eval_value = ?, eval_at = NOW()
                                           WHERE project_id = ? AND student_id = ? AND reg_date = ?");
            mysqli_stmt_bind_param($stmt, "siis", $value, $project_id, $student_id, $today);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $stmt = mysqli_prepare($conn, "UPDATE records SET eval_value = ?, eval_at = NOW(), eval_cleared_at = NULL
                                           WHERE project_id = ? AND student_id = ?");
            mysqli_stmt_bind_param($stmt, "sii", $value, $project_id, $student_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $eval_value = $value;
        }
        json_response([
            'success' => true,
            'eval_value' => $eval_value,
            'registered' => 1,
            'regts' => $auto_reg ? time() : intval(strtotime($day_row['created_at'])),
            'total' => total_students_in($conn, $class_ids),
            'registered_count' => registered_count($conn, $project_id),
            'evaluated_count' => evaluated_count($conn, $project_id),
        ]);
    }
    // 次项/题次模式：按当前轮次评价（须该次已登记；答题模式仅 A/B/C/D）
    if (project_is_rounded($project)) {
        $round = current_round_no($conn, $project);
        $stmt = mysqli_prepare($conn, "SELECT registered_at, eval_value FROM record_rounds
                                       WHERE project_id = ? AND student_id = ? AND round_no = ?");
        mysqli_stmt_bind_param($stmt, "iii", $project_id, $student_id, $round);
        mysqli_stmt_execute($stmt);
        $rr = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $auto_reg = false;
        if (!$rr || empty($rr['registered_at'])) {
            if ($remove) json_response(['success' => false, 'message' => '该生本次尚未登记，无评价可删除']);
            $auto_reg = true;
        } elseif ($lock_state !== 'none' && record_is_locked($project, 1, $rr['registered_at'])) {
            json_response(['success' => false, 'message' => '该生本次登记已超过锁定时间，点评已锁定（可用「锁定」开关临时解锁）']);
        }
        // 校验评价内容（无效值不得触发自动登记，避免误把未登记学生登记上）
        if (in_array(($project['mode'] ?? ''), ['quiz', 'raise'], true)) {
            $value = strtoupper($value);
            if (!$remove && !in_array($value, ['A', 'B', 'C', 'D'], true)) {
                json_response(['success' => false, 'message' => '答题/举牌模式仅支持 A/B/C/D']);
            }
        } elseif (!$remove && !validate_eval_value($conn, $project['eval_mode'], $value)) {
            json_response(['success' => false, 'message' => '评价内容不符合当前评价模式要求']);
        }
        if ($auto_reg) {
            // 长按未登记学生选择评价：自动完成本次登记（登记+评价一步完成）
            $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO record_rounds (project_id, student_id, round_no, registered_at, registered_by, created_at)
                                           VALUES (?, ?, ?, NOW(), 'page', NOW())");
            mysqli_stmt_bind_param($stmt, "iii", $project_id, $student_id, $round);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        if ($remove) {
            $stmt = mysqli_prepare($conn, "UPDATE record_rounds SET eval_cleared_at = IF(COALESCE(eval_value, '') <> '', NOW(), eval_cleared_at),
                                           eval_value = NULL, eval_at = NULL WHERE project_id = ? AND student_id = ? AND round_no = ?");
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE record_rounds SET eval_value = ?, eval_at = NOW(), eval_cleared_at = NULL
                                           WHERE project_id = ? AND student_id = ? AND round_no = ?");
        }
        if ($remove) {
            mysqli_stmt_bind_param($stmt, "iii", $project_id, $student_id, $round);
        } else {
            mysqli_stmt_bind_param($stmt, "siii", $value, $project_id, $student_id, $round);
        }
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        list($reg, $ev) = round_counts($conn, $project_id, $round);
        json_response([
            'success' => true,
            'round' => $round,
            'eval_value' => $remove ? '' : $value,
            'registered' => 1,
            'regts' => $auto_reg ? time() : intval(strtotime($rr['registered_at'])),
            'total' => total_students_in($conn, $class_ids),
            'registered_count' => $reg,
            'evaluated_count' => $ev,
        ]);
    }

    $auto_reg = false;
    if ($rec['registered'] != 1) {
        if ($remove) json_response(['success' => false, 'message' => '该学生尚未登记，无评价可删除']);
        $auto_reg = true;
    } else {
        // 自动锁定：锁定后禁止修改/删除点评（临时解锁开关除外）
        if ($lock_state !== 'none' && record_is_locked($project, $rec['registered'], $rec['registered_at'])) {
            json_response(['success' => false, 'message' => '该学生登记已超过锁定时间，点评已锁定（可用「锁定」开关临时解锁）']);
        }
        $regts = intval(strtotime($rec['registered_at']));
    }
    // 先校验评价值合法性（无效值不得触发自动登记，避免误把未登记学生登记上）
    if (!$remove && !validate_eval_value($conn, $project['eval_mode'], $value)) {
        json_response(['success' => false, 'message' => '评价内容不符合当前评价模式要求']);
    }
    if ($auto_reg) {
        // 长按未登记学生选择评价：自动完成登记（登记+评价一步完成）
        if ($rec['registered_at'] !== null) {   // 曾登记后取消：复用原行重新登记
            $stmt = mysqli_prepare($conn, "UPDATE records SET registered = 1, registered_at = NOW(), registered_by = 'page'
                                           WHERE project_id = ? AND student_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $project_id, $student_id);
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO records (project_id, student_id, registered, registered_at, registered_by)
                                           VALUES (?, ?, 1, NOW(), 'page')");
            mysqli_stmt_bind_param($stmt, "ii", $project_id, $student_id);
        }
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $regts = time();
    }

    if ($remove) {
        $stmt = mysqli_prepare($conn, "UPDATE records SET eval_cleared_at = IF(COALESCE(eval_value, '') <> '', NOW(), eval_cleared_at),
                                       eval_value = NULL, eval_at = NULL WHERE project_id = ? AND student_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $project_id, $student_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $eval_value = '';
    } else {
        $stmt = mysqli_prepare($conn, "UPDATE records SET eval_value = ?, eval_at = NOW(), eval_cleared_at = NULL WHERE project_id = ? AND student_id = ?");
        mysqli_stmt_bind_param($stmt, "sii", $value, $project_id, $student_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $eval_value = $value;
    }

    json_response([
        'success' => true,
        'eval_value' => $eval_value,
        'registered' => 1,
        'regts' => $regts,
        'total' => total_students_in($conn, $class_ids),
        'registered_count' => registered_count($conn, $project_id),
        'evaluated_count' => evaluated_count($conn, $project_id),
    ]);
}

// ===== 教师点评：单条保存（comment_save）/ 批量保存（comments_batch，items=JSON[{student_id,content}]） =====
// 项目维度一学生一条（覆盖式）；content 传空串 = 清除该生点评；家长公开查询「综合报告」中展示
if ($type === 'comment_save' || $type === 'comments_batch') {
    $project_id = intval($_POST['project_id'] ?? 0);
    list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
    if (!$project || !$class_ids) json_response(['success' => false, 'message' => '项目不存在或无权限']);
    $in_classes = class_ids_in($class_ids);

    $items = [];
    if ($type === 'comment_save') {
        $items[intval($_POST['student_id'] ?? 0)] = trim($_POST['content'] ?? '');
    } else {
        $dec = json_decode(strval($_POST['items'] ?? ''), true);
        if (!is_array($dec)) json_response(['success' => false, 'message' => '参数格式错误']);
        foreach ($dec as $it) {
            if (!is_array($it)) continue;
            $sid = intval($it['student_id'] ?? 0);
            if ($sid > 0) $items[$sid] = trim(strval($it['content'] ?? ''));
        }
    }
    if (!$items) json_response(['success' => false, 'message' => '没有可保存的点评内容']);

    $saved = 0; $skipped = 0;
    foreach ($items as $sid => $content) {
        if ($sid <= 0 || mb_strlen($content) > 500) { $skipped++; continue; }
        // 校验学生属于项目覆盖班级
        $stmt = mysqli_prepare($conn, "SELECT s.id FROM students s WHERE s.id = ? AND s.class_id IN ({$in_classes})");
        mysqli_stmt_bind_param($stmt, "i", $sid);
        mysqli_stmt_execute($stmt);
        $ok = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$ok) { $skipped++; continue; }
        if ($content === '') {
            $stmt = mysqli_prepare($conn, "DELETE FROM student_comments WHERE project_id = ? AND student_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $project_id, $sid);
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO student_comments (project_id, student_id, content, created_by, created_at, updated_at)
                                           VALUES (?, ?, ?, ?, NOW(), NOW())
                                           ON DUPLICATE KEY UPDATE content = VALUES(content), created_by = VALUES(created_by), updated_at = NOW()");
            mysqli_stmt_bind_param($stmt, "iisi", $project_id, $sid, $content, $teacher_id);
        }
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $saved++;
    }
    json_response(['success' => true, 'saved' => $saved, 'skipped' => $skipped]);
}

// ===== 班级喊话：教师端取接入数据（令牌+客户端链接+学生名单）/ 下发指令 =====
if ($type === 'announce_data' || $type === 'announce_send') {
    $class_id = intval($_POST['class_id'] ?? 0);
    if (!$class_id || !can_announce_class($conn, $teacher_id, $class_id)) {
        json_response(['success' => false, 'message' => '无该班级喊话权限']);
    }
    // 确保接入令牌存在（首次使用时生成）
    $res = mysqli_query($conn, "SELECT announce_token FROM classes WHERE id = {$class_id} AND deleted_at IS NULL");
    $crow = mysqli_fetch_assoc($res);
    if (!$crow) json_response(['success' => false, 'message' => '班级不存在']);
    $token = strval($crow['announce_token']);
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
        $token = bin2hex(random_bytes(16));
        mysqli_query($conn, "UPDATE classes SET announce_token = '{$token}' WHERE id = {$class_id}");
    }

    if ($type === 'announce_data') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
        $link = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim($dir, '/') . '/announce_client.php?t=' . $token;
        $students = [];
        $res = mysqli_query($conn, "SELECT id, name, seat_no, student_no, group_id FROM students WHERE class_id = {$class_id} AND disabled = 0 ORDER BY CAST(seat_no AS UNSIGNED) ASC, id ASC");
        while ($row = mysqli_fetch_assoc($res)) {
            $students[] = ['id' => intval($row['id']), 'name' => $row['name'], 'seat' => $row['seat_no'],
                           'no' => strval($row['student_no']), 'gid' => intval($row['group_id'])];
        }
        // 班级分组（{分组} 标签替换用）：分组id => 名称
        $groups = [];
        $res = mysqli_query($conn, "SELECT id, name FROM stu_groups WHERE class_id = {$class_id} ORDER BY sort ASC, id ASC");
        while ($row = mysqli_fetch_assoc($res)) $groups[intval($row['id'])] = $row['name'];
        json_response(['success' => true, 'token' => $token, 'link' => $link, 'students' => $students,
                       'groups' => $groups, 'devices' => ann_devices_summary($conn, $class_id)]);
    }

    // announce_send：下发指令。text=文字字幕（content 留空=清除字幕）；voice=语音播报（texts 逐条下发，未选学生时允许纯文字）
    $mtype = ($_POST['mtype'] ?? 'text') === 'voice' ? 'voice' : 'text';
    $content = trim(strval($_POST['content'] ?? ''));
    $font_size = max(16, min(200, intval($_POST['font_size'] ?? 64)));
    $marquee = intval($_POST['marquee'] ?? 0) === 1 ? 1 : 0;   // 按值判断（弹窗始终携带 marquee=0/1，不能用 isset）
    $sub_big = isset($_POST['sub_big']) ? (intval($_POST['sub_big']) === 1 ? 1 : 0) : 1;   // 全屏字幕：跑马灯优先模式下单次跑完后再显示
    $force_show = isset($_POST['force_show']) ? 1 : 0;
    $auto_min = max(0, min(3600, intval($_POST['auto_min'] ?? 0)));
    $cache_min = max(0, min(1440, intval($_POST['cache_min'] ?? 60)));   // 客户端缓存保留分钟（收起后可恢复，默认 60，0=不缓存）
    $need_ack = intval($_POST['need_ack'] ?? 0) === 1 ? 1 : 0;           // 需班级端反馈（确认收到/无法处理）；未勾选=收到即开始自动最小化倒计时
    $speed = max(0.5, min(2, floatval($_POST['speed'] ?? 1)));
    $pitch = max(0.5, min(2, floatval($_POST['pitch'] ?? 1)));
    $times = max(1, min(5, intval($_POST['times'] ?? 1)));

    if ($mtype === 'text') {
        $stmt = mysqli_prepare($conn, "INSERT INTO announce_msgs (class_id, sender_id, mtype, content, font_size, marquee, sub_big, force_show, auto_min, cache_min, need_ack, created_at)
                                       VALUES (?, ?, 'text', ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $sid = intval($teacher_id);
        mysqli_stmt_bind_param($stmt, "iisiiiiiii", $class_id, $sid, $content, $font_size, $marquee, $sub_big, $force_show, $auto_min, $cache_min, $need_ack);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        json_response(['success' => true, 'message' => $content === '' ? '已清除字幕' : '字幕已发送到大屏']);
    }

    // 语音播报：texts（客户端已按标签逐生替换）逐条生成指令；兼容旧端 names（仅 {name} 替换）；无队列时允许纯文字播报
    $list = [];
    $texts = json_decode(strval($_POST['texts'] ?? '[]'), true);
    if (is_array($texts) && count($texts)) {
        $list = array_values(array_filter(array_slice(array_map('strval', $texts), 0, 50), function ($t) { return trim($t) !== ''; }));
    } else {
        $names = json_decode(strval($_POST['names'] ?? '[]'), true);
        if (is_array($names) && count($names)) {
            foreach (array_slice(array_map('strval', $names), 0, 50) as $nm) {
                $t = str_replace('{name}', $nm, $content);
                if (trim($t) !== '') $list[] = $t;
            }
        }
    }
    if (!count($list)) {
        // 未选学生兜底：带学生标签的纯文字拒绝（客户端已拦截，API 层防直接调用漏播字面标签）
        if (preg_match('/\{姓名\}|\{座号\}|\{分组\}|\{编号\}|\{座号\+姓名\}|\{座号\+未登记上一名\}|\{座号\+未登记下一名\}|\{未登记上一名\}|\{未登记下一名\}|\{name\}/', $content)) {
            json_response(['success' => false, 'message' => '内容包含学生标签，请先选择学生或移除标签']);
        }
        $list[] = $content;   // 未选择学生：仅播报文字内容（允许空内容：客户端播报前先响「叮咚」，空内容=仅提醒）
    }
    $stmt = mysqli_prepare($conn, "INSERT INTO announce_msgs (class_id, sender_id, mtype, content, font_size, marquee, sub_big, force_show, auto_min, cache_min, need_ack, speed, pitch, times, voice_name, created_at)
                                   VALUES (?, ?, 'voice', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $sid = intval($teacher_id);
    $voice_name = trim(substr(strval($_POST['voice'] ?? ''), 0, 120));   // 音色角色（浏览器 TTS voice name，客户端无此音色时回退默认）
    $sent = 0;
    foreach ($list as $txt) {
        mysqli_stmt_bind_param($stmt, "iisiiiiiiiddis", $class_id, $sid, $txt, $font_size, $marquee, $sub_big, $force_show, $auto_min, $cache_min, $need_ack, $speed, $pitch, $times, $voice_name);
        if (mysqli_stmt_execute($stmt)) $sent++;
    }
    mysqli_stmt_close($stmt);
    json_response(['success' => true, 'message' => '已发送 ' . $sent . ' 条语音喊话到大屏']);
}

// ===== 喊话：图片发送（教师端 → 客户端大屏；多图存服务器文件，指令 content=URL JSON 列表） =====
if ($type === 'announce_send_img') {
    $class_id = intval($_POST['class_id'] ?? 0);
    if (!$class_id || !can_announce_class($conn, $teacher_id, $class_id)) {
        json_response(['success' => false, 'message' => '无该班级喊话权限']);
    }
    $force_show = isset($_POST['force_show']) ? 1 : 0;
    $auto_min = max(0, min(3600, intval($_POST['auto_min'] ?? 0)));
    $cache_min = max(0, min(1440, intval($_POST['cache_min'] ?? 60)));
    $urls = [];
    $reurls = json_decode(strval($_POST['reurls'] ?? '[]'), true);
    if (is_array($reurls) && count($reurls)) {
        // 重发历史图片：仅允许本班 uploads/announce/<class_id>/ 下真实存在的文件（防路径穿越）
        $baseReal = realpath(__DIR__ . '/uploads/announce/' . $class_id);
        foreach (array_slice(array_map('strval', $reurls), 0, 9) as $u) {
            $u = ltrim(str_replace('\\', '/', $u), '/');
            if (strpos($u, 'uploads/announce/' . $class_id . '/') !== 0 || strpos($u, '..') !== false) continue;
            $real = realpath(__DIR__ . '/' . $u);
            if (!$baseReal || !$real || strpos($real, $baseReal) !== 0 || !is_file($real)) continue;
            $urls[] = $u;
        }
        if (!count($urls)) {
            json_response(['success' => false, 'message' => '历史图片文件已不存在，无法重发']);
        }
    } else {
        if (empty($_FILES['imgs']) || !is_array($_FILES['imgs']['name'])) {
            json_response(['success' => false, 'message' => '请选择要发送的图片']);
        }
        // 保存目录：uploads/announce/<class_id>/（随机文件名防猜测），仅允许真实图片格式
        $dir = __DIR__ . '/uploads/announce/' . $class_id;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            json_response(['success' => false, 'message' => '创建图片目录失败，请检查服务器写权限']);
        }
        $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $names = $_FILES['imgs']['name']; $tmps = $_FILES['imgs']['tmp_name'];
        $errs = $_FILES['imgs']['error']; $sizes = $_FILES['imgs']['size'];
        $cnt = min(count($names), 9);   // 单次最多 9 张，单张 ≤ 10MB
        for ($i = 0; $i < $cnt; $i++) {
            if (intval($errs[$i]) !== UPLOAD_ERR_OK || !is_uploaded_file($tmps[$i])) continue;
            if (intval($sizes[$i]) > 10 * 1024 * 1024) continue;
            $info = @getimagesize($tmps[$i]);
            if (!$info || !isset($exts[$info['mime']])) continue;
            $fname = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $exts[$info['mime']];
            if (!@move_uploaded_file($tmps[$i], $dir . '/' . $fname)) continue;
            $urls[] = 'uploads/announce/' . $class_id . '/' . $fname;
        }
        if (!count($urls)) {
            json_response(['success' => false, 'message' => '没有有效的图片（支持 jpg/png/gif/webp，单张 ≤ 10MB）']);
        }
        // 顺带清理 7 天前的旧图片文件（磁盘防膨胀；对应已播报指令不再被重放）
        foreach (glob($dir . '/*.{jpg,png,gif,webp}', GLOB_BRACE) ?: [] as $old) {
            if (@filemtime($old) < time() - 7 * 86400) @unlink($old);
        }
    }
    $content = json_encode($urls, JSON_UNESCAPED_SLASHES);
    $stmt = mysqli_prepare($conn, "INSERT INTO announce_msgs (class_id, sender_id, mtype, content, font_size, marquee, sub_big, force_show, auto_min, cache_min, speed, pitch, times, created_at)
                                   VALUES (?, ?, 'img', ?, 0, 0, 0, ?, ?, ?, 1, 1, 1, NOW())");
    $sid = intval($teacher_id);
    mysqli_stmt_bind_param($stmt, "iisiii", $class_id, $sid, $content, $force_show, $auto_min, $cache_min);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    json_response(['success' => true, 'message' => '已发送 ' . count($urls) . ' 张图片到大屏', 'urls' => $urls]);
}

// ===== 喊话：语音录音发送（教师端 MediaRecorder 录音 ≤1 分钟 → 存服务器 → 客户端大屏自动播放） =====
if ($type === 'announce_send_audio') {
    $class_id = intval($_POST['class_id'] ?? 0);
    if (!$class_id || !can_announce_class($conn, $teacher_id, $class_id)) {
        json_response(['success' => false, 'message' => '无该班级喊话权限']);
    }
    $force_show = isset($_POST['force_show']) ? 1 : 0;
    $auto_min = max(0, min(3600, intval($_POST['auto_min'] ?? 0)));
    $cache_min = max(0, min(1440, intval($_POST['cache_min'] ?? 60)));
    $need_ack = intval($_POST['need_ack'] ?? 0) === 1 ? 1 : 0;
    $url = '';
    $reurl = trim(strval($_POST['reurl'] ?? ''));
    if ($reurl !== '') {
        // 重发历史录音：仅允许本班 uploads/announce/<class_id>/ 下真实存在的音频文件（防路径穿越）
        $reurl = ltrim(str_replace('\\', '/', $reurl), '/');
        $baseReal = realpath(__DIR__ . '/uploads/announce/' . $class_id);
        if (strpos($reurl, 'uploads/announce/' . $class_id . '/') === 0 && strpos($reurl, '..') === false
            && preg_match('/\.(webm|ogg|m4a|mp3|wav)$/i', $reurl)) {
            $real = realpath(__DIR__ . '/' . $reurl);
            if ($baseReal && $real && strpos($real, $baseReal) === 0 && is_file($real)) $url = $reurl;
        }
        if ($url === '') json_response(['success' => false, 'message' => '历史录音文件已不存在，无法重发']);
    } else {
        if (empty($_FILES['audio']) || intval($_FILES['audio']['error']) !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['audio']['tmp_name'])) {
            json_response(['success' => false, 'message' => '请先录制一段语音']);
        }
        if (intval($_FILES['audio']['size']) > 10 * 1024 * 1024) {
            json_response(['success' => false, 'message' => '录音文件过大（限 10MB）']);
        }
        // 魔数嗅验真实格式（MediaRecorder 常见：webm/opus；Safari：mp4；Firefox：ogg/opus），防伪造扩展名
        $fh = @fopen($_FILES['audio']['tmp_name'], 'rb');
        $head = $fh ? (string)@fread($fh, 16) : '';
        if ($fh) @fclose($fh);
        if (substr($head, 0, 4) === "\x1A\x45\xDF\xA3") $ext = 'webm';
        elseif (substr($head, 0, 4) === 'OggS') $ext = 'ogg';
        elseif (substr($head, 4, 4) === 'ftyp') $ext = 'm4a';
        elseif (substr($head, 0, 3) === 'ID3' || (strlen($head) >= 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0)) $ext = 'mp3';
        elseif (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WAVE') $ext = 'wav';
        else json_response(['success' => false, 'message' => '不支持的录音格式']);
        $dir = __DIR__ . '/uploads/announce/' . $class_id;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            json_response(['success' => false, 'message' => '创建录音目录失败，请检查服务器写权限']);
        }
        $fname = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!@move_uploaded_file($_FILES['audio']['tmp_name'], $dir . '/' . $fname)) {
            json_response(['success' => false, 'message' => '录音保存失败，请检查服务器写权限']);
        }
        $url = 'uploads/announce/' . $class_id . '/' . $fname;
        // 顺带清理 7 天前的旧录音文件（磁盘防膨胀；与图片清理互不影响）
        $olds = [];
        foreach (['webm', 'ogg', 'm4a', 'mp3', 'wav'] as $e) { $olds = array_merge($olds, glob($dir . '/*.' . $e) ?: []); }
        foreach ($olds as $old) {
            if (@filemtime($old) < time() - 7 * 86400) @unlink($old);
        }
    }
    $stmt = mysqli_prepare($conn, "INSERT INTO announce_msgs (class_id, sender_id, mtype, content, font_size, marquee, sub_big, force_show, auto_min, cache_min, need_ack, speed, pitch, times, created_at)
                                   VALUES (?, ?, 'audio', ?, 0, 0, 0, ?, ?, ?, ?, 1, 1, 1, NOW())");
    $sid = intval($teacher_id);
    mysqli_stmt_bind_param($stmt, "iisiiii", $class_id, $sid, $url, $force_show, $auto_min, $cache_min, $need_ack);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    json_response(['success' => true, 'message' => '录音已发送到大屏，客户端将自动播放', 'url' => $url]);
}

// ===== 喊话：大屏客户端设备在线状态（教师端弹窗顶部状态条，每 5 秒刷新） =====
if ($type === 'announce_status') {
    $class_id = intval($_POST['class_id'] ?? 0);
    if (!$class_id || !can_announce_class($conn, $teacher_id, $class_id)) {
        json_response(['success' => false, 'message' => '无该班级喊话权限']);
    }
    // 最近 30 分钟需反馈指令的反馈统计（弹窗页头反馈状态条：确认收到/无法处理/待反馈）
    $ack = ['ok' => 0, 'fail' => 0, 'wait' => 0];
    $ares = mysqli_query($conn, "SELECT ack_status, COUNT(*) AS c FROM announce_msgs
                                 WHERE class_id = {$class_id} AND need_ack = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                                 GROUP BY ack_status");
    while ($arow = mysqli_fetch_assoc($ares)) {
        if ($arow['ack_status'] === null) $ack['wait'] += intval($arow['c']);
        elseif (intval($arow['ack_status']) === 1) $ack['ok'] += intval($arow['c']);
        else $ack['fail'] += intval($arow['c']);
    }
    // 最后一条班级反馈（学生反向喊话；喊话弹窗页头「班级喊话」提示条，已阅后隐藏）
    $last_rev = null;
    $lres = mysqli_query($conn, "SELECT id, mtype, content, created_at FROM announce_rev_msgs
                                 WHERE class_id = {$class_id} AND content <> '' ORDER BY id DESC LIMIT 1");
    if ($lres && ($lrow = mysqli_fetch_assoc($lres))) {
        $last_rev = ['id' => intval($lrow['id']), 'mtype' => strval($lrow['mtype']),
                     'content' => strval($lrow['content']), 'ts' => strval($lrow['created_at'])];
    }
    // 最近一条需反馈喊话的反馈结果（页头「反馈信息」提示条：内容 + 确认收到/无法处理/待反馈 + 时间，已阅后隐藏；mtype=audio 时前端渲染为播放器）
    $last_ack = null;
    $akres = mysqli_query($conn, "SELECT id, mtype, content, ack_status, ack_at, created_at FROM announce_msgs
                                  WHERE class_id = {$class_id} AND need_ack = 1 ORDER BY id DESC LIMIT 1");
    if ($akres && ($akrow = mysqli_fetch_assoc($akres))) {
        $last_ack = ['id' => intval($akrow['id']), 'mtype' => strval($akrow['mtype']), 'content' => strval($akrow['content']),
                     'st' => $akrow['ack_status'] === null ? 0 : intval($akrow['ack_status']),
                     'at' => $akrow['ack_at'] ? strval($akrow['ack_at']) : '',
                     'sent' => strval($akrow['created_at'])];
    }
    json_response(['success' => true, 'devices' => ann_devices_summary($conn, $class_id), 'ack' => $ack, 'last_rev' => $last_rev, 'last_ack' => $last_ack]);
}

// ===== 喊话：历史播报内容（弹窗「历史内容」选择用：本班语音播报去重最近 20 条） =====
if ($type === 'announce_history') {
    $class_id = intval($_POST['class_id'] ?? 0);
    if (!$class_id || !can_announce_class($conn, $teacher_id, $class_id)) {
        json_response(['success' => false, 'message' => '无该班级喊话权限']);
    }
    $items = [];
    // 近 30 条历史（voice/text/img 逐条含 id，供回填/重发/删除；img 的 content 为 URL JSON 列表；need_ack/ack 反馈结果随条目返回）
    $res = mysqli_query($conn, "SELECT id, mtype, content, need_ack, ack_status, ack_at, created_at AS ts FROM announce_msgs
                                WHERE class_id = {$class_id} AND content <> '' ORDER BY id DESC LIMIT 30");
    while ($row = mysqli_fetch_assoc($res)) {
        $items[] = ['id' => intval($row['id']), 'mtype' => strval($row['mtype']),
                    'content' => strval($row['content']), 'ts' => strval($row['ts']),
                    'need_ack' => intval($row['need_ack']) === 1 ? 1 : 0,
                    'ack_st' => $row['ack_status'] === null ? 0 : intval($row['ack_status']),
                    'ack_at' => $row['ack_at'] ? strval($row['ack_at']) : ''];
    }
    // 班级反馈历史（学生反向喊话，最近 20 条；历史弹窗内「班级反馈」区段方便回看）
    $revs = [];
    $rres = mysqli_query($conn, "SELECT id, mtype, content, created_at AS ts FROM announce_rev_msgs
                                 WHERE class_id = {$class_id} AND content <> '' ORDER BY id DESC LIMIT 20");
    while ($rrow = mysqli_fetch_assoc($rres)) {
        $revs[] = ['id' => intval($rrow['id']), 'mtype' => strval($rrow['mtype']),
                   'content' => strval($rrow['content']), 'ts' => strval($rrow['ts'])];
    }
    json_response(['success' => true, 'items' => $items, 'revs' => $revs]);
}

// ===== 喊话：删除历史指令（教师端历史弹窗「删除」按钮；仅删本班记录；kind=rev 删班级反馈记录） =====
if ($type === 'announce_del') {
    $class_id = intval($_POST['class_id'] ?? 0);
    if (!$class_id || !can_announce_class($conn, $teacher_id, $class_id)) {
        json_response(['success' => false, 'message' => '无该班级喊话权限']);
    }
    $id = intval($_POST['id'] ?? 0);
    if (strval($_POST['kind'] ?? '') === 'rev') {
        $stmt = mysqli_prepare($conn, "DELETE FROM announce_rev_msgs WHERE id = ? AND class_id = ?");
    } else {
        $stmt = mysqli_prepare($conn, "DELETE FROM announce_msgs WHERE id = ? AND class_id = ?");
    }
    mysqli_stmt_bind_param($stmt, "ii", $id, $class_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    json_response(['success' => true, 'message' => '已删除']);
}

// ===== 喊话：教师端在线心跳（教师页每 10 秒调用；大屏客户端据 ann_teacher_seen 显示「教师在线/离线」） =====
if ($type === 'announce_tping') {
    $class_id = intval($_POST['class_id'] ?? 0);
    $project_id = intval($_POST['project_id'] ?? 0);
    // scan.php / omr_scan.php 打开页面的教师可能仅有项目查看/登记权限：按项目鉴权并换算到班级
    if ($project_id && !can_announce_class($conn, $teacher_id, $class_id)) {
        $pj = get_project_for($conn, $project_id, $teacher_id, 'view');
        if ($pj) $class_id = intval($pj['class_id']);
    }
    if (!$class_id || !can_announce_class($conn, $teacher_id, $class_id)) {
        json_response(['success' => false, 'message' => '无该班级喊话权限']);
    }
    mysqli_query($conn, "UPDATE classes SET ann_teacher_seen = NOW() WHERE id = {$class_id}");
    json_response(['success' => true]);
}

// ===== 反向喊话（教师端）：保存班级开关与允许模式 / 轮询取走班级发来的消息 =====
if ($type === 'announce_rev_cfg' || $type === 'announce_rev_poll') {
    $class_id = intval($_POST['class_id'] ?? 0);
    if (!$class_id || !can_announce_class($conn, $teacher_id, $class_id)) {
        json_response(['success' => false, 'message' => '无该班级喊话权限']);
    }

    if ($type === 'announce_rev_cfg') {
        $enabled = intval($_POST['enabled'] ?? 0) === 1 ? 1 : 0;
        $modes = array_values(array_filter(array_map('trim', explode(',', strval($_POST['modes'] ?? ''))), function ($m) {
            return $m === 'voice' || $m === 'text';
        }));
        $modes = implode(',', $modes);
        $stmt = mysqli_prepare($conn, "UPDATE classes SET rev_announce = ?, rev_ann_modes = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "isi", $enabled, $modes, $class_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        json_response(['success' => true, 'enabled' => $enabled, 'modes' => $modes,
                       'message' => $enabled ? '班级端反向喊话已开启' : '班级端反向喊话已关闭']);
    }

    // announce_rev_poll：取走待播消息（教师端页面每 5 秒轮询）；顺带刷新教师在线心跳
    // scan.php / omr_scan.php 也轮询反馈（浮窗提示）：同 tping 的项目权限放宽
    $project_id = intval($_POST['project_id'] ?? 0);
    if ($project_id && !can_announce_class($conn, $teacher_id, $class_id)) {
        $pj = get_project_for($conn, $project_id, $teacher_id, 'view');
        if ($pj) $class_id = intval($pj['class_id']);
        if (!$class_id || !can_announce_class($conn, $teacher_id, $class_id)) {
            json_response(['success' => false, 'message' => '无该班级喊话权限']);
        }
    }
    mysqli_query($conn, "UPDATE classes SET ann_teacher_seen = NOW() WHERE id = {$class_id}");
    $msgs = [];
    $ids = [];
    $res = mysqli_query($conn, "SELECT id, mtype, content, font_size, marquee, speed, pitch, times
                                FROM announce_rev_msgs
                                WHERE class_id = {$class_id} AND status = 0
                                ORDER BY id ASC LIMIT 20");
    while ($row = mysqli_fetch_assoc($res)) {
        $ids[] = intval($row['id']);
        $msgs[] = [
            'id' => intval($row['id']), 'mtype' => $row['mtype'], 'content' => $row['content'],
            'font_size' => intval($row['font_size']), 'marquee' => intval($row['marquee']) === 1,
            'speed' => floatval($row['speed']), 'pitch' => floatval($row['pitch']), 'times' => intval($row['times']),
        ];
    }
    if ($ids) {
        mysqli_query($conn, "UPDATE announce_rev_msgs SET status = 1, picked_at = NOW() WHERE id IN (" . implode(',', $ids) . ")");
    }
    json_response(['success' => true, 'msgs' => $msgs]);
}

// ===== 项目自动积分规则（⚙设置：project_view 传 project_id 仅管本项目；projects.php 拉可管理项目列表选择） =====
if ($type === 'points_rules_get' || $type === 'points_rules_save' || $type === 'points_rule_projects') {
    auto_points_ensure_schema($conn);
    $mode_names = ['count' => '仅登记一次', 'daily' => '打卡', 'multi' => '多次登记', 'quiz' => '答题', 'raise' => '举牌', 'omr' => '答题卡'];

    if ($type === 'points_rule_projects') {
        // 当前教师可管理（manage：创建人/班级 manage+create 授权成员）的项目列表
        $my_school = intval(get_teacher_school_id($conn, $teacher_id));
        $conds = [];
        $conds[] = "(p.created_by = " . intval($teacher_id) . " AND (p.school_id = 0 OR p.school_id = {$my_school}))";
        $mcls = [];
        foreach (get_teacher_class_member_map($conn, $teacher_id) as $cid => $perm) {
            if (in_array($perm, ['manage', 'create'], true)) $mcls[] = intval($cid);
        }
        if (count($mcls)) $conds[] = "p.class_id IN (" . implode(',', $mcls) . ")";
        $list = [];
        $res = mysqli_query($conn, "SELECT p.id, p.name, p.mode, p.late_seconds, p.scope, p.class_id, c.name AS class_name
                                    FROM projects p LEFT JOIN classes c ON c.id = p.class_id
                                    WHERE p.deleted_at IS NULL AND (" . implode(' OR ', $conds) . ")
                                    ORDER BY p.id DESC");
        while ($r = mysqli_fetch_assoc($res)) {
            $list[] = ['id' => intval($r['id']), 'name' => strval($r['name']),
                       'mode' => strval($r['mode']), 'mode_name' => strval($mode_names[$r['mode']] ?? $r['mode']),
                       'late_seconds' => intval($r['late_seconds']), 'scope' => strval($r['scope']),
                       'class_name' => strval($r['class_name'])];
        }
        json_response(['success' => true, 'projects' => $list]);
    }

    // 以下两型均需项目管理权限
    $project_id = intval($_POST['project_id'] ?? 0);
    $project = get_project_for($conn, $project_id, $teacher_id, 'manage');
    if (!$project) json_response(['success' => false, 'message' => '无该项目管理权限或项目不存在']);
    $pmode = strval($project['mode'] ?? 'count');
    $meta = points_rule_types_meta($pmode);   // 仅该登记模式可用的规则类型

    if ($type === 'points_rules_get') {
        // 评价内容候选（eval_opt 下拉）：评价模式预设选项 + 该项目实际用到的评价值
        $eval_opts = [];
        $mi = eval_mode_info($conn, strval($project['eval_mode'] ?? ''));
        foreach ((is_array($mi['options'] ?? null) ? $mi['options'] : []) as $po) {
            $po = trim(strval(is_array($po) ? ($po['label'] ?? ($po['value'] ?? '')) : $po));
            if ($po !== '' && !in_array($po, $eval_opts, true)) $eval_opts[] = $po;
        }
        $ev_tbl = $pmode === 'daily' ? 'record_days' : ($pmode === 'count' ? 'records' : ($pmode === 'omr' ? '' : 'record_rounds'));
        if ($ev_tbl !== '') {
            $res = mysqli_query($conn, "SELECT DISTINCT eval_value FROM {$ev_tbl} WHERE project_id = {$project_id} AND eval_value IS NOT NULL AND eval_value <> ''");
            while ($r = mysqli_fetch_assoc($res)) {
                $ev = trim(strval($r['eval_value']));
                if ($ev !== '' && !in_array($ev, $eval_opts, true)) $eval_opts[] = $ev;
            }
        }
        $rules = [];
        $res = mysqli_query($conn, "SELECT id, rtype, opt, value, enabled, sort FROM points_rules WHERE project_id = {$project_id} ORDER BY sort ASC, id ASC");
        while ($r = mysqli_fetch_assoc($res)) {
            $rules[] = ['id' => intval($r['id']), 'rtype' => strval($r['rtype']), 'opt' => strval($r['opt']),
                        'value' => intval($r['value']), 'enabled' => intval($r['enabled']) === 1 ? 1 : 0,
                        'display' => points_rule_display_name(strval($r['rtype']), strval($r['opt']))];
        }
        json_response(['success' => true,
                       'project' => ['id' => $project_id, 'name' => strval($project['name']), 'mode' => $pmode,
                                     'mode_name' => strval($mode_names[$pmode] ?? $pmode), 'late_seconds' => intval($project['late_seconds'] ?? 0)],
                       'meta' => $meta, 'rules' => $rules, 'eval_opts' => $eval_opts]);
    }

    // points_rules_save：全量替换规则 → 强制重算该项目自动积分（旧规则已加的回档、未应用的补足）
    $rules_in = json_decode(strval($_POST['rules'] ?? '[]'), true);
    if (!is_array($rules_in)) json_response(['success' => false, 'message' => '参数错误']);
    if (count($rules_in) > 50) json_response(['success' => false, 'message' => '规则最多 50 条']);
    $clean = [];
    foreach ($rules_in as $i => $r) {
        if (!is_array($r)) continue;
        $rtype = strval($r['rtype'] ?? '');
        $m = isset($meta[$rtype]) ? $meta[$rtype] : null;
        if (!$m) continue;   // 类型不存在或不适用于该项目登记模式
        $opt = trim(strval($r['opt'] ?? ''));
        $value = intval($r['value'] ?? 0);
        $enabled = (intval($r['enabled'] ?? 1) === 0) ? 0 : 1;
        if (isset($m['need_late']) && $m['need_late'] && intval($project['late_seconds'] ?? 0) <= 0) continue;   // 超时规则需补登记阈值>0
        $param = isset($m['param']) ? $m['param'] : 'none';
        if ($param === 'opt') {
            if ($opt === '') continue;
            $opt = mb_substr($opt, 0, 50);
        } elseif ($param === 'num') {
            if ($opt === '' || !is_numeric($opt)) continue;
            $opt = strval(0 + floatval($opt));
        } else {
            $opt = '';
        }
        if ($value === 0) continue;
        $clean[] = [$rtype, $opt, $value, $enabled, intval($i)];
    }
    mysqli_query($conn, "START TRANSACTION");
    mysqli_query($conn, "DELETE FROM points_rules WHERE project_id = {$project_id}");
    $ok = true;
    $stmt = mysqli_prepare($conn, "INSERT INTO points_rules (project_id, rtype, opt, value, enabled, sort) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($clean as $c) {
        $crtype = $c[0]; $copt = $c[1]; $cvalue = $c[2]; $cenabled = $c[3]; $csort = $c[4];
        mysqli_stmt_bind_param($stmt, "issiii", $project_id, $crtype, $copt, $cvalue, $cenabled, $csort);   // opt 为 varchar，须绑 s（旧代码误绑 i 导致评价内容入库变 0）
        if (!mysqli_stmt_execute($stmt)) $ok = false;
    }
    mysqli_stmt_close($stmt);
    if (!$ok) {
        mysqli_query($conn, "ROLLBACK");
        json_response(['success' => false, 'message' => '规则保存失败，请重试']);
    }
    mysqli_query($conn, "COMMIT");
    auto_points_sync($conn, $project, true);
    json_response(['success' => true, 'message' => '已保存规则并重算本项目自动积分', 'count' => count($clean)]);
}

// ===== 课堂表现积分（多维度快速加减分：选学生→点积分项即完成；每条流水自动记录时间/操作教师/备注；权限同喊话） =====
if ($type === 'points_data' || $type === 'points_add' || $type === 'points_log_del' || $type === 'points_items_save'
    || $type === 'points_lot_add' || $type === 'points_lot_clear') {
    $class_id = intval($_POST['class_id'] ?? 0);
    // points_data=积分数据查询（流水/汇总/项/规则）：所有班级授权成员（含仅查看）可看；操作类需「仅登记」及以上（仅查看不可加减/设置）
    if (!$class_id) json_response(['success' => false, 'message' => '缺少班级参数']);
    if ($type === 'points_data') {
        if (!can_view_class($conn, $teacher_id, $class_id)) json_response(['success' => false, 'message' => '无本班积分查看权限']);
    } else if ($type === 'points_items_save') {
        // 积分项为全局共享设置：仅「管理员 / 可建立」级别可保存（仅登记/仅查看不可设置积分规则）
        $pt_lvl = get_class_perm_level($conn, $teacher_id, $class_id);
        if (!in_array($pt_lvl, ['manage', 'create'], true)) json_response(['success' => false, 'message' => '积分项需管理员/可建立级别']);
    } else if (!can_points_class($conn, $teacher_id, $class_id)) {
        json_response(['success' => false, 'message' => '无本班积分操作权限（仅查看级别不可加减积分/设置规则）']);
    }
    $crow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM classes WHERE id = {$class_id} AND deleted_at IS NULL"));
    if (!$crow) json_response(['success' => false, 'message' => '班级不存在']);
    $school_id = intval(get_teacher_school_id($conn, $teacher_id));

    // 懒迁移：积分项增加类型列（1=个人 2=小组）与显示开关（抽选/一键加分中是否显示）
    if (!function_exists('points_items_ensure_cols')) {
        function points_items_ensure_cols($conn) {
            static $done = false;
            if ($done) return;
            $done = true;
            $cols = [
                'type'       => "ALTER TABLE points_items ADD COLUMN type TINYINT NOT NULL DEFAULT 1 COMMENT '积分类型（1=个人 2=小组）' AFTER color",
                'show_lot'   => "ALTER TABLE points_items ADD COLUMN show_lot TINYINT NOT NULL DEFAULT 1 COMMENT '是否显示在随机抽选按钮中（1=显示 0=隐藏）' AFTER type",
                'show_quick' => "ALTER TABLE points_items ADD COLUMN show_quick TINYINT NOT NULL DEFAULT 1 COMMENT '是否显示在一键加分按钮中（1=显示 0=隐藏）' AFTER show_lot",
            ];
            foreach ($cols as $col => $ddl) {
                $r = mysqli_query($conn, "SHOW COLUMNS FROM points_items LIKE '" . $col . "'");
                if ($r && mysqli_num_rows($r) === 0) mysqli_query($conn, $ddl);
            }
        }
    }
    points_items_ensure_cols($conn);

    // 积分项读取（该校为空时播种默认 6 项：课堂纪律/互动/任务完成/创新/合作 + 课堂违纪扣分）
    if (!function_exists('points_items_load')) {
        function points_items_load($conn, $school_id) {
            $cnt = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM points_items WHERE school_id = " . intval($school_id)));
            if ($cnt && intval($cnt['c']) === 0) {
                $defaults = [
                    ['课堂纪律', 2, '#27ae60'], ['课堂互动', 2, '#3498db'], ['任务完成', 3, '#9b59b6'],
                    ['创新表现', 3, '#e67e22'], ['合作协作', 2, '#16a085'], ['课堂违纪', -1, '#e74c3c'],
                ];
                $i = 0;
                $stmt = mysqli_prepare($conn, "INSERT INTO points_items (school_id, name, value, color, sort) VALUES (?, ?, ?, ?, ?)");
                foreach ($defaults as $d) {
                    $dname = $d[0]; $dval = $d[1]; $dcol = $d[2];
                    mysqli_stmt_bind_param($stmt, "isisi", $school_id, $dname, $dval, $dcol, $i);
                    mysqli_stmt_execute($stmt);
                    $i++;
                }
                mysqli_stmt_close($stmt);
            }
            $items = [];
            $res = mysqli_query($conn, "SELECT id, name, value, color, type, show_lot, show_quick FROM points_items WHERE school_id = " . intval($school_id) . " ORDER BY sort ASC, id ASC");
            while ($row = mysqli_fetch_assoc($res)) {
                $items[] = ['id' => intval($row['id']), 'name' => strval($row['name']),
                            'value' => intval($row['value']), 'color' => strval($row['color']),
                            'type' => intval($row['type']) === 2 ? 2 : 1,
                            'show_lot' => intval($row['show_lot']) === 1 ? 1 : 0,
                            'show_quick' => intval($row['show_quick']) === 1 ? 1 : 0];
            }
            return $items;
        }
    }

    if ($type === 'points_data') {
        // 项目上下文（可选，宿主 project_view.php 传入）：懒触发该项目自动积分同步（规则/登记数据变化后首次打开即重算）
        $sync_pid = intval($_POST['project_id'] ?? 0);
        if ($sync_pid > 0) {
            $sync_proj = get_project_for($conn, $sync_pid, $teacher_id, 'view');
            if ($sync_proj) auto_points_sync($conn, $sync_proj);
        }
        // 删除学生后同编号新增自动找回积分（懒触发，幂等；只认编号快照）
        points_recover_orphan($conn, $class_id);
        $items = points_items_load($conn, $school_id);
        // 学生名单（含累计总积分 + 座位/分组；口径与喊话一致：未禁用按座号排序）
        $students = [];
        $res = mysqli_query($conn, "SELECT s.id, s.name, s.seat_no, s.seat_pos, s.group_id,
                       IFNULL((SELECT SUM(l.value) FROM points_log l WHERE l.student_id = s.id), 0) AS total
                       FROM students s WHERE s.class_id = {$class_id} AND s.disabled = 0
                       ORDER BY CAST(s.seat_no AS UNSIGNED) ASC, s.id ASC");
        while ($row = mysqli_fetch_assoc($res)) {
            $students[] = ['id' => intval($row['id']), 'name' => strval($row['name']), 'seat' => strval($row['seat_no']),
                           'seat_pos' => intval($row['seat_pos']), 'group_id' => intval($row['group_id']),
                           'total' => intval($row['total'])];
        }
        // 座位布局（座位视图：讲台 + 分组数×每组列数网格）与分组列表 / 座位组名（分组视图呈现用）
        $seat_cfg = ['cols' => 2, 'groups' => 4];
        $scrow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT seat_cols, seat_groups FROM classes WHERE id = {$class_id}"));
        if ($scrow) $seat_cfg = ['cols' => max(1, intval($scrow['seat_cols'])), 'groups' => max(1, intval($scrow['seat_groups']))];
        $groups = [];
        $seat_names = [];
        $res = mysqli_query($conn, "SELECT id, name, sort FROM stu_groups WHERE class_id = {$class_id} ORDER BY sort ASC, id ASC");
        while ($row = mysqli_fetch_assoc($res)) {
            $groups[] = ['id' => intval($row['id']), 'name' => strval($row['name']), 'sort' => intval($row['sort'])];
            // 座位组名（组名跟组走）：sort(1起) => 「第N组」组名（与 project_view.php 座位模式同口径）
            if (intval($row['sort']) >= 1 && preg_match('/^第\s*(\d+)\s*组$/u', strval($row['name']), $m) && intval($m[1]) >= 1) {
                $seat_names[intval($row['sort'])] = strval($row['name']);
            }
        }
        // 抽中记录（随机抽选已抽中的学生 sid=>时间；清空前不再被抽中，存 settings 跨刷新/设备一致）
        $lot_picked = [];
        $lk = $school_id . '_pt_lottery_' . $class_id;
        $stmt = mysqli_prepare($conn, "SELECT svalue FROM settings WHERE skey = ?");
        mysqli_stmt_bind_param($stmt, "s", $lk);
        mysqli_stmt_execute($stmt);
        $lrow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if ($lrow && $lrow['svalue'] !== '') {
            $ldec = json_decode($lrow['svalue'], true);
            if (is_array($ldec)) $lot_picked = $ldec;
        }
        // 最近流水（本班 50 条，新在前；src=2 为项目自动规则生成，前端加🤖标且不可撤销）
        $logs = [];
        $res = mysqli_query($conn, "SELECT l.id, l.student_id, l.item_name, l.value, l.remark, l.created_by, l.created_at, l.source, l.project_id,
                       s.name AS sname, s.seat_no, IFNULL(NULLIF(t.realname, ''), t.username) AS by_name
                       FROM points_log l
                       LEFT JOIN students s ON s.id = l.student_id
                       LEFT JOIN teachers t ON t.id = l.created_by
                       WHERE l.class_id = {$class_id} ORDER BY l.id DESC LIMIT 50");
        while ($row = mysqli_fetch_assoc($res)) {
            $src = intval($row['source']);
            $logs[] = ['id' => intval($row['id']), 'sid' => intval($row['student_id']),
                       'sname' => strval($row['sname']), 'seat' => strval($row['seat_no']),
                       'item' => strval($row['item_name']), 'value' => intval($row['value']),
                       'remark' => strval($row['remark']),
                       'mine' => ($src === 1 && intval($row['created_by']) === $teacher_id),
                       'src' => $src, 'pid' => intval($row['project_id']),
                       'by' => strval($row['by_name']), 'at' => strval($row['created_at'])];
        }
        // 汇总分析（range=week/month/all 时间筛选）：学生积分排行 + 各维度得分占比
        $range = strval($_POST['range'] ?? 'all');
        $days = $range === 'week' ? 7 : ($range === 'month' ? 30 : 0);
        $cond = $days > 0 ? ' AND l.created_at >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)' : '';
        $rank = [];
        $res = mysqli_query($conn, "SELECT l.student_id, s.name, s.seat_no, SUM(l.value) AS total
                       FROM points_log l JOIN students s ON s.id = l.student_id
                       WHERE l.class_id = {$class_id} AND s.disabled = 0{$cond}
                       GROUP BY l.student_id, s.name, s.seat_no HAVING total <> 0
                       ORDER BY total DESC LIMIT 20");
        while ($row = mysqli_fetch_assoc($res)) {
            $rank[] = ['sid' => intval($row['student_id']), 'name' => strval($row['name']),
                       'seat' => strval($row['seat_no']), 'total' => intval($row['total'])];
        }
        $dims = [];
        $res = mysqli_query($conn, "SELECT IF(l.item_name = '', '自定义', l.item_name) AS dn, SUM(l.value) AS v
                       FROM points_log l WHERE l.class_id = {$class_id}{$cond}
                       GROUP BY dn ORDER BY SUM(ABS(l.value)) DESC LIMIT 20");
        while ($row = mysqli_fetch_assoc($res)) {
            $dims[] = ['name' => strval($row['dn']), 'value' => intval($row['v'])];
        }
        // 个人统计（汇总分析页下拉选中某学生）：该生各维度占比 + 逐日得分/累计趋势 + 流水（受 range 时间段筛选）
        $stu_stat = null;
        $stu_id = intval($_POST['student_id'] ?? 0);
        if ($stu_id > 0) {
            $srow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, name, seat_no FROM students WHERE id = {$stu_id} AND class_id = {$class_id} AND disabled = 0"));
            if ($srow) {
                $sdims = []; $sdaily = []; $slogs = []; $stotal = 0;
                $res = mysqli_query($conn, "SELECT l.id, l.item_name, l.value, l.remark, l.created_by, l.created_at, l.source, l.project_id,
                               IFNULL(NULLIF(t.realname, ''), t.username) AS by_name
                               FROM points_log l LEFT JOIN teachers t ON t.id = l.created_by
                               WHERE l.class_id = {$class_id} AND l.student_id = {$stu_id}{$cond}
                               ORDER BY l.created_at ASC, l.id ASC LIMIT 2000");
                while ($row = mysqli_fetch_assoc($res)) {
                    $dn = strval($row['item_name']) !== '' ? strval($row['item_name']) : '自定义';
                    $v = intval($row['value']);
                    $stotal += $v;
                    if (!isset($sdims[$dn])) $sdims[$dn] = 0;
                    $sdims[$dn] += $v;
                    $day = substr(strval($row['created_at']), 0, 10);
                    if (!isset($sdaily[$day])) $sdaily[$day] = 0;
                    $sdaily[$day] += $v;
                    $src = intval($row['source']);
                    $slogs[] = ['id' => intval($row['id']), 'sid' => $stu_id,
                                'sname' => strval($srow['name']), 'seat' => strval($srow['seat_no']),
                                'item' => strval($row['item_name']), 'value' => $v,
                                'remark' => strval($row['remark']),
                                'mine' => ($src === 1 && intval($row['created_by']) === $teacher_id),
                                'src' => $src, 'pid' => intval($row['project_id']),
                                'by' => strval($row['by_name']), 'at' => strval($row['created_at'])];
                }
                $dims_out = [];
                foreach ($sdims as $dn => $v) $dims_out[] = ['name' => $dn, 'value' => intval($v)];
                usort($dims_out, function ($a, $b) { return abs($b['value']) - abs($a['value']); });
                $daily_out = []; $cum = 0;
                foreach ($sdaily as $d => $v) { $cum += intval($v); $daily_out[] = ['d' => $d, 'v' => intval($v), 'c' => $cum]; }
                $slogs = array_reverse($slogs);   // 新在前，与全班流水一致
                $stu_stat = ['id' => $stu_id, 'name' => strval($srow['name']), 'seat' => strval($srow['seat_no']),
                             'total' => $stotal, 'dims' => $dims_out, 'daily' => $daily_out, 'logs' => $slogs];
            }
        }
        json_response(['success' => true, 'items' => $items, 'students' => $students, 'logs' => $logs,
                       'rank' => $rank, 'dims' => $dims, 'stu_stat' => $stu_stat, 'lot_picked' => $lot_picked,
                       'seat_cfg' => $seat_cfg, 'groups' => $groups, 'seat_names' => $seat_names,
                       'gifts' => points_gifts_load($conn, $class_id)]);
    }

    if ($type === 'points_add') {
        // student_ids JSON 数组（1..50 个，均须为本班未禁用学生）；item_id=积分项 / custom=自定义分值；remark 可选
        $ids = json_decode(strval($_POST['student_ids'] ?? '[]'), true);
        if (!is_array($ids)) json_response(['success' => false, 'message' => '参数错误']);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!count($ids) || count($ids) > 50) json_response(['success' => false, 'message' => '请选择学生（一次最多 50 人）']);
        $in = implode(',', $ids);
        $cnt = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM students WHERE id IN ({$in}) AND class_id = {$class_id} AND disabled = 0"));
        if (intval($cnt['c']) !== count($ids)) json_response(['success' => false, 'message' => '部分学生不在本班或已禁用，请刷新后重试']);
        $remark = trim(mb_substr(strval($_POST['remark'] ?? ''), 0, 200));
        $item_id = intval($_POST['item_id'] ?? 0);
        $custom = intval($_POST['custom'] ?? 0);
        if ($item_id > 0) {
            $irow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name, value FROM points_items WHERE id = {$item_id} AND school_id = {$school_id}"));
            if (!$irow) json_response(['success' => false, 'message' => '积分项不存在']);
            $item_name = strval($irow['name']);
            $value = intval($irow['value']);
        } else {
            if ($custom === 0 || abs($custom) > 100) json_response(['success' => false, 'message' => '自定义分值需为 ±1~100']);
            $item_id = 0;
            $item_name = '';
            $value = $custom;
        }
        // 编号快照（删除学生后同编号新增学生可自动找回积分）
        $sno_map = [];
        $r2 = mysqli_query($conn, "SELECT id, student_no FROM students WHERE id IN ({$in})");
        while ($r2 && ($s2 = mysqli_fetch_assoc($r2))) $sno_map[intval($s2['id'])] = strval($s2['student_no']);
        $stmt = mysqli_prepare($conn, "INSERT INTO points_log (school_id, class_id, student_id, student_no, item_id, item_name, value, remark, created_by, created_at)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $added = 0;
        foreach ($ids as $sid) {
            $sno = isset($sno_map[$sid]) ? $sno_map[$sid] : '';
            mysqli_stmt_bind_param($stmt, "iiisisisi", $school_id, $class_id, $sid, $sno, $item_id, $item_name, $value, $remark, $teacher_id);
            if (mysqli_stmt_execute($stmt)) $added++;
        }
        mysqli_stmt_close($stmt);
        $sign = $value > 0 ? '+' : '';
        json_response(['success' => true, 'added' => $added, 'value' => $value,
                       'message' => ($value > 0 ? '加分' : '扣分') . " {$sign}{$value} × {$added} 人"]);
    }

    if ($type === 'points_log_del') {
        // 撤销一条流水（仅记录人本人或学校管理员；自动规则生成的流水不可单独撤销）
        $log_id = intval($_POST['log_id'] ?? 0);
        $lrow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, created_by, source FROM points_log WHERE id = {$log_id} AND class_id = {$class_id}"));
        if (!$lrow) json_response(['success' => false, 'message' => '记录不存在']);
        if (intval($lrow['source']) === 2) json_response(['success' => false, 'message' => '🤖 自动规则流水不可单独撤销，请在积分 ⚙ 设置中调整规则后重算']);
        if (intval($lrow['created_by']) !== $teacher_id && !is_school_admin($conn, $teacher_id)) {
            json_response(['success' => false, 'message' => '仅记录人本人可撤销']);
        }
        mysqli_query($conn, "DELETE FROM points_log WHERE id = {$log_id}");
        json_response(['success' => true, 'message' => '已撤销该条积分']);
    }

    if ($type === 'points_lot_add') {
        // 随机抽选中签登记（sid=>时间 存 settings；记录未清空时该生不再被抽中）
        $sid = intval($_POST['sid'] ?? 0);
        $scnt = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM students WHERE id = {$sid} AND class_id = {$class_id} AND disabled = 0"));
        if (!$scnt || intval($scnt['c']) === 0) json_response(['success' => false, 'message' => '学生不存在']);
        $lk = $school_id . '_pt_lottery_' . $class_id;
        $stmt = mysqli_prepare($conn, "SELECT svalue FROM settings WHERE skey = ?");
        mysqli_stmt_bind_param($stmt, "s", $lk);
        mysqli_stmt_execute($stmt);
        $lrow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $picked = [];
        if ($lrow && $lrow['svalue'] !== '') {
            $ldec = json_decode($lrow['svalue'], true);
            if (is_array($ldec)) $picked = $ldec;
        }
        $picked[(string)$sid] = date('Y-m-d H:i:s');
        $json = json_encode($picked, JSON_UNESCAPED_UNICODE);
        $stmt = mysqli_prepare($conn, "INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)");
        mysqli_stmt_bind_param($stmt, "ss", $lk, $json);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        json_response(['success' => true]);
    }

    if ($type === 'points_lot_clear') {
        // 清空抽中记录：全班学生重新可被抽中
        $lk = $school_id . '_pt_lottery_' . $class_id;
        $stmt = mysqli_prepare($conn, "DELETE FROM settings WHERE skey = ?");
        mysqli_stmt_bind_param($stmt, "s", $lk);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        json_response(['success' => true, 'message' => '已清空抽中记录，全班学生重新可被抽中']);
    }

    // points_items_save：全量保存积分项（流水存名称快照，项删除不影响历史流水可读性）
    $items_raw = json_decode(strval($_POST['items'] ?? '[]'), true);
    if (!is_array($items_raw) || !count($items_raw)) json_response(['success' => false, 'message' => '请至少保留一个积分项']);
    $items_raw = array_slice($items_raw, 0, 20);
    $clean = [];
    foreach ($items_raw as $it) {
        $name = trim(mb_substr(strval(is_array($it) ? ($it['name'] ?? '') : ''), 0, 20));
        $val = intval(is_array($it) ? ($it['value'] ?? 0) : 0);
        $color = strval(is_array($it) ? ($it['color'] ?? '') : '');
        $itype = intval(is_array($it) ? ($it['type'] ?? 1) : 1) === 2 ? 2 : 1;   // 1=个人 2=小组
        $sl = intval(is_array($it) ? ($it['show_lot'] ?? 1) : 1) === 1 ? 1 : 0;     // 是否显示在随机抽选
        $sq = intval(is_array($it) ? ($it['show_quick'] ?? 1) : 1) === 1 ? 1 : 0;   // 是否显示在一键加分
        if ($name === '') json_response(['success' => false, 'message' => '积分项名称不能为空']);
        if ($val === 0 || abs($val) > 100) json_response(['success' => false, 'message' => '「' . $name . '」分值需为 ±1~100 且不为 0']);
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#27ae60';
        $clean[] = [$name, $val, strtolower($color), $itype, $sl, $sq];
    }
    mysqli_query($conn, "DELETE FROM points_items WHERE school_id = {$school_id}");
    $i = 0;
    $stmt = mysqli_prepare($conn, "INSERT INTO points_items (school_id, name, value, color, type, show_lot, show_quick, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($clean as $c) {
        $cname = $c[0]; $cval = $c[1]; $ccol = $c[2]; $ctype = $c[3]; $csl = $c[4]; $csq = $c[5];
        mysqli_stmt_bind_param($stmt, "isisiiii", $school_id, $cname, $cval, $ccol, $ctype, $csl, $csq, $i);
        mysqli_stmt_execute($stmt);
        $i++;
    }
    mysqli_stmt_close($stmt);
    json_response(['success' => true, 'items' => points_items_load($conn, $school_id), 'message' => '积分项已保存（共 ' . count($clean) . ' 项）']);
}

// ===== 积分导入 / 导出 / 清空 / 兑换礼品（导入与清空需当前账号登录密码防误触；导出为 CSV 直链下载） =====
if ($type === 'points_export' || $type === 'points_import' || $type === 'points_clear_all'
    || $type === 'points_gift_save' || $type === 'points_gift_del' || $type === 'points_redeem') {
    $class_id = intval($_REQUEST['class_id'] ?? 0);
    $cls = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM classes WHERE id = {$class_id} AND deleted_at IS NULL"));
    if (!$cls) json_response(['success' => false, 'message' => '班级不存在']);
    if (!can_points_class($conn, $teacher_id, $class_id)) json_response(['success' => false, 'message' => '无本班积分操作权限']);
    $school_id = intval($cls['school_id']);

    // —— CSV 导出（kind=balance 最终积分 / log 过程流水；文件 UTF-8 BOM，Excel 直接打开）——
    if ($type === 'points_export') {
        $kind = (strval($_REQUEST['kind'] ?? 'balance') === 'log') ? 'log' : 'balance';
        $dlname = '积分' . ($kind === 'log' ? '流水' : '最终') . '_' . $cls['name'] . '_' . date('Ymd') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="points_' . $kind . '_' . $class_id . '_' . date('Ymd_Hi') . '.csv"; filename*=UTF-8\'\'' . rawurlencode($dlname));
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        if ($kind === 'balance') {
            fputcsv($out, ['编号', '姓名', '座号', '当前积分']);
            $res = mysqli_query($conn, "SELECT s.student_no, s.name, s.seat_no, IFNULL(t.total, 0) AS total
                                        FROM students s
                                        LEFT JOIN (SELECT student_id, SUM(value) AS total FROM points_log WHERE class_id = {$class_id} GROUP BY student_id) t ON t.student_id = s.id
                                        WHERE s.class_id = {$class_id} AND s.disabled = 0
                                        ORDER BY s.seat_no + 0 ASC, s.id ASC");
            while ($r = mysqli_fetch_assoc($res)) {
                fputcsv($out, [strval($r['student_no']), strval($r['name']), strval($r['seat_no']), intval($r['total'])]);
            }
        } else {
            fputcsv($out, ['时间', '姓名', '编号', '积分项', '分值', '备注', '来源']);
            $res = mysqli_query($conn, "SELECT l.created_at, s.name, l.student_no, l.item_name, l.value, l.remark,
                           IF(l.source = 2, '🤖 自动规则', IF(l.source = 3, '📥 导入', IFNULL(NULLIF(t.realname, ''), t.username))) AS src
                           FROM points_log l
                           LEFT JOIN students s ON s.id = l.student_id
                           LEFT JOIN teachers t ON t.id = l.created_by
                           WHERE l.class_id = {$class_id}
                           ORDER BY l.created_at ASC, l.id ASC");
            while ($r = mysqli_fetch_assoc($res)) {
                fputcsv($out, [strval($r['created_at']), strval($r['name']), strval($r['student_no']), strval($r['item_name']), intval($r['value']), strval($r['remark']), strval($r['src'])]);
            }
        }
        fclose($out);
        exit;
    }

    // —— 导入 / 清空：密码验证（当前教师登录密码）——
    if ($type === 'points_import' || $type === 'points_clear_all') {
        verify_teacher_password($conn, $teacher_id, strval($_POST['password'] ?? ''));
    }

    if ($type === 'points_clear_all') {
        // 清空本班全部积分流水，并留一条 0 分审计行（谁在什么时候清空了积分）
        $cnt = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM points_log WHERE class_id = {$class_id}"));
        $n = intval($cnt['c']);
        mysqli_query($conn, "DELETE FROM points_log WHERE class_id = {$class_id}");
        $stmt = mysqli_prepare($conn, "INSERT INTO points_log (school_id, class_id, student_id, student_no, item_id, item_name, value, remark, created_by, created_at, project_id, source)
                                       VALUES (?, ?, 0, '', 0, '🧹 清空积分', 0, ?, ?, NOW(), 0, 3)");
        $rk = '清空本班全部积分（原 ' . $n . ' 条流水）';
        mysqli_stmt_bind_param($stmt, "iisi", $school_id, $class_id, $rk, $teacher_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        json_response(['success' => true, 'message' => '已清空本班全部积分（原 ' . $n . ' 条流水），流水保留一条清空记录']);
    }

    if ($type === 'points_import') {
        // kind=balance 最终积分（每生一条「积分导入」起始分）/ log 过程流水（按原时间回放）；粘贴内容或上传 CSV 均可
        $kind = (strval($_POST['kind'] ?? 'balance') === 'log') ? 'log' : 'balance';
        $content = strval($_POST['content'] ?? '');
        if (isset($_FILES['file']) && isset($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $fc = file_get_contents($_FILES['file']['tmp_name']);
            if ($fc !== false && trim($fc) !== '') $content = $fc;
        }
        if (mb_strlen($content) > 500000) json_response(['success' => false, 'message' => '导入内容过大，请分批导入']);
        $r = points_import_apply($conn, $class_id, $kind, $content, intval($_POST['clear_first'] ?? 0) === 1, $teacher_id);
        $skips = $r['skips'];
        $msg = ($kind === 'balance' ? '最终积分' : '过程流水') . "导入完成：成功 {$r['added']} 条" . (count($skips) ? "，跳过 " . count($skips) . " 条" : '');
        json_response(['success' => true, 'message' => $msg, 'added' => $r['added'], 'skips' => array_slice($skips, 0, 30)]);
    }

    // —— 兑换礼品管理（按班级配置）——
    if ($type === 'points_gift_save') {
        $gid = intval($_POST['id'] ?? 0);
        $gname = trim(mb_substr(strval($_POST['name'] ?? ''), 0, 50));
        $gcost = intval($_POST['cost'] ?? 0);
        if ($gname === '') json_response(['success' => false, 'message' => '请填写礼品名称']);
        if ($gcost <= 0 || $gcost > 100000) json_response(['success' => false, 'message' => '兑换所需积分需为 1~100000']);
        if ($gid > 0) {
            $stmt = mysqli_prepare($conn, "UPDATE points_gifts SET name = ?, cost = ? WHERE id = ? AND class_id = ?");
            mysqli_stmt_bind_param($stmt, "siii", $gname, $gcost, $gid, $class_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = '礼品已更新';
        } else {
            $mx = mysqli_fetch_assoc(mysqli_query($conn, "SELECT IFNULL(MAX(sort), 0) + 1 AS ns FROM points_gifts WHERE class_id = {$class_id}"));
            $ns = intval($mx['ns']);
            $stmt = mysqli_prepare($conn, "INSERT INTO points_gifts (class_id, school_id, name, cost, sort) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "iisii", $class_id, $school_id, $gname, $gcost, $ns);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = '礼品已添加';
        }
        json_response(['success' => true, 'message' => $msg, 'gifts' => points_gifts_load($conn, $class_id)]);
    }

    if ($type === 'points_gift_del') {
        $gid = intval($_POST['id'] ?? 0);
        $stmt = mysqli_prepare($conn, "DELETE FROM points_gifts WHERE id = ? AND class_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $gid, $class_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        json_response(['success' => true, 'message' => '礼品已删除（不影响已兑换记录）', 'gifts' => points_gifts_load($conn, $class_id)]);
    }

    if ($type === 'points_redeem') {
        // 点选礼品 + 勾选学生 → 余额足够者自动扣分并记「🎁 兑换」流水；余额不足者跳过并报告
        $gid = intval($_POST['gift_id'] ?? 0);
        $gift = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, name, cost FROM points_gifts WHERE id = {$gid} AND class_id = {$class_id} AND enabled = 1"));
        if (!$gift) json_response(['success' => false, 'message' => '礼品不存在或已下架']);
        $ids = json_decode(strval($_POST['student_ids'] ?? '[]'), true);
        if (!is_array($ids) || !count($ids)) json_response(['success' => false, 'message' => '请先勾选要兑换的学生']);
        if (count($ids) > 50) json_response(['success' => false, 'message' => '一次最多兑换 50 人']);
        $r = points_redeem_apply($conn, $class_id, $gift, $ids, $teacher_id);
        $msg = '';
        if (count($r['ok'])) $msg .= '兑换成功 ' . count($r['ok']) . ' 人（每人 −' . intval($gift['cost']) . ' 分）：' . implode('、', array_slice($r['ok'], 0, 10)) . (count($r['ok']) > 10 ? ' 等' : '');
        if (count($r['poor'])) $msg .= (count($r['ok']) ? '；' : '') . '余额不足跳过 ' . count($r['poor']) . ' 人：' . implode('、', array_slice($r['poor'], 0, 10)) . (count($r['poor']) > 10 ? ' 等' : '');
        if (!count($r['ok'])) json_response(['success' => false, 'message' => $msg !== '' ? $msg : '没有人完成兑换']);
        json_response(['success' => true, 'message' => $msg]);
    }
}

// ===== 答题卡（OMR 涂卡识别）模式：模板保存/加载/删除 + 识别结果提交/查询/编辑/绑定/删除 =====
// 布局 JSON 由设计器（omr_designer.php）生成并预计算 bubbles（每格 mm 坐标），识别端（omr_scan.php）按坐标采样涂黑率；
// 图片不上传服务器，仅提交识别出的身份与答案文本。
if ($type === 'omr_template_save' || $type === 'omr_template_list' || $type === 'omr_template_get'
    || $type === 'omr_template_del' || $type === 'omr_submit' || $type === 'omr_ident_preview' || $type === 'omr_results'
    || $type === 'omr_result_del' || $type === 'omr_bind' || $type === 'omr_edit' || $type === 'omr_manual_save'
    || $type === 'omr_answer_get' || $type === 'omr_answer_save' || $type === 'omr_answer_clear' || $type === 'omr_force_set'
    || $type === 'omr_stats' || $type === 'omr_questions'
    || $type === 'omr_tpl_bind_get' || $type === 'omr_tpl_bind_set'
    || $type === 'omr_lib_options' || $type === 'omr_lib_save' || $type === 'omr_lib_list'
    || $type === 'omr_lib_get' || $type === 'omr_lib_del'
    || $type === 'omr_img_save' || $type === 'omr_img_list' || $type === 'omr_img_get' || $type === 'omr_img_del') {

    // 答题卡得分计算（服务端唯一口径）：生效答案键=模板布局答案键最优先，「录入答案」独立答案仅补模板未设的题；
    // 答案完整→自动批改；不完整且开启强制批改→按已有答案的题批改（分母=有答案题数），其余题只记录选择不判分；
    // 单选=全等，多选=无涂错即对（所涂字母均为答案子集，允许漏选）；任一题设分值→得分制 nn/xx（分值和），否则对题数/题数
    if (!function_exists('omr_layout_ctx')) {
        // 布局 → 题目上下文：kind={题号: single/multi}（全部题目）+ 模板自带答案键 tk={题号: 字母} + 每题分值 tp={题号: 分值}
        function omr_layout_ctx($layout) {
            $kind = []; $tk = []; $tp = [];
            foreach ((is_array($layout['sections'] ?? null) ? $layout['sections'] : []) as $sec) {
                if (!is_array($sec)) continue;
                $k = strval($sec['kind'] ?? 'single');
                if ($k === 'blank' || $k === 'short') continue;   // 填空/简答为手写题：无涂框无答案键，不参与判分与覆盖统计
                $start = intval($sec['start'] ?? 1); $count = min(500, max(1, intval($sec['count'] ?? 0)));
                $multi = ($k === 'multi');
                $key = is_array($sec['key'] ?? null) ? $sec['key'] : [];
                $pts = is_array($sec['points'] ?? null) ? $sec['points'] : [];
                for ($q = $start; $q < $start + $count; $q++) {
                    $qk = strval($q);
                    $kind[$qk] = $multi ? 'multi' : 'single';
                    $v = strtoupper(preg_replace('/[^A-Ea-e]/', '', strval($key[$qk] ?? '')));
                    if ($v !== '') $tk[$qk] = $v;
                    $pv = floatval(strval($pts[$qk] ?? 0));
                    if ($pv > 0 && $pv <= 100) $tp[$qk] = $pv;
                }
            }
            return [$kind, $tk, $tp];
        }
    }
    if (!function_exists('omr_indep_answers')) {
        // 独立录入答案读取（跟模板走）：保存时记录当时绑定的模板 id（omr_answers_tid_{pid}）；检测到换绑模板 → 立即失效清除（改用模板答案键）。
        // 兼容旧数据：未记录过 tid 的历史答案视为有效（首次新保存起才固化 tid）。
        function omr_indep_answers($conn, $project_id) {
            $pid = intval($project_id);
            if ($pid <= 0) return [];
            $res = @mysqli_query($conn, "SELECT answers FROM omr_answers WHERE project_id = " . $pid);
            if (!$res || !($row = mysqli_fetch_assoc($res))) return [];
            $saved_tid = get_setting($conn, 'omr_answers_tid_' . $pid, '');
            if ($saved_tid !== '' && intval($saved_tid) !== intval(get_setting($conn, 'omr_tpl_bind_' . $pid, '0'))) {
                @mysqli_query($conn, "DELETE FROM omr_answers WHERE project_id = " . $pid);   // 模板已更换：独立答案随之失效
                return [];
            }
            $dec = json_decode(strval($row['answers']), true);
            return is_array($dec) ? $dec : [];
        }
    }
    if (!function_exists('omr_answer_ctx')) {
        // 项目生效答案上下文：模板答案键最优先，独立录入答案仅补模板未设的题；complete=覆盖全部题目（才自动批改）
        function omr_answer_ctx($conn, $project_id, $layout) {
            list($kind, $tk, $tp) = omr_layout_ctx($layout);
            $key = $tk; $src = 'template'; $filled = 0;
            if ($project_id > 0) {
                $pa = omr_indep_answers($conn, $project_id);
                if (count($pa)) {
                    $clean = [];
                    foreach ($pa as $q => $v) {
                        $qk = preg_replace('/[^0-9]/', '', strval($q));
                        $vv = strtoupper(preg_replace('/[^A-E]/', '', strval($v)));
                        if ($qk !== '' && $vv !== '') $clean[$qk] = $vv;
                    }
                    foreach ($clean as $qk => $vv) {
                        if (!empty($key[$qk])) continue;   // 模板已设答案：以模板为准，独立录入不生效
                        $key[$qk] = $vv; $filled++;
                    }
                    if ($filled) $src = count($tk) ? 'mixed' : 'project';
                }
            }
            // 强制批改（项目级开关）：答案不完整时也按「已有答案的题」批改，其余题只记录选择不判分
            $force = ($project_id > 0 && get_setting($conn, 'omr_force_grade_' . intval($project_id), '0') === '1');
            $total = count($kind); $answered = 0;
            foreach ($kind as $qk => $kd) if (!empty($key[$qk])) $answered++;
            $has_pts_ctx = (count($tp) > 0);
            if ($has_pts_ctx) {
                // 分值制：仅「设了分值」的题参与计分 —— 这些题全部有答案键即视为答案完整（未设分值的题忽略，不阻塞批改，总分随之减少）
                $need = 0; $have = 0;
                foreach ($tp as $qk => $pv) { $need++; if (!empty($key[$qk])) $have++; }
                $complete = ($need > 0 && $have === $need);
            } else {
                $complete = ($total > 0 && $answered === $total);
            }
            return ['kind' => $kind, 'key' => $key, 'total' => $total, 'answered' => $answered,
                    'complete' => $complete, 'source' => $src,
                    'points' => $tp, 'has_points' => $has_pts_ctx, 'force' => $force];
        }
    }
    if (!function_exists('omr_num_fmt')) {
        // 数值格式化：最多 1 位小数，去尾零（7.50→7.5、8.0→8）
        function omr_num_fmt($v) {
            $s = rtrim(rtrim(number_format(floatval($v), 1, '.', ''), '0'), '.');
            return ($s === '' || $s === '-0') ? '0' : $s;
        }
    }
    if (!function_exists('omr_grade_flat')) {
        // 按生效键判分：返回 "nn/xx"（默认=对题数/题数；任一题设分值=得分/分值和，最多 1 位小数）
        // 答案不完整且未开强制批改 → 不批改返回 ''；强制批改时仅按「有答案的题」批改（无答案题只记录选择不参与）
        function omr_grade_flat($ctx, $answers) {
            if (empty($ctx['complete']) && empty($ctx['force'])) return '';
            $hasPts = !empty($ctx['has_points']) && is_array($ctx['points'] ?? null);
            $got = 0; $den = 0; $nn = 0.0; $xx = 0.0;
            foreach ($ctx['kind'] as $q => $kd) {
                if ($hasPts && floatval($ctx['points'][$q] ?? 0) <= 0) continue;   // 分值制：未设分值的题不参与计分（忽略该题，总分随之减少）
                $k2 = strtoupper(preg_replace('/[^A-Ea-e]/', '', strval($ctx['key'][$q] ?? '')));
                if ($k2 === '') continue;   // 该题无生效答案：不参与批改（强制批改时分母自然=有答案题数）
                $den++;
                if ($hasPts) $xx += floatval($ctx['points'][$q] ?? 0);
                $sel = strtoupper(preg_replace('/[^A-Ea-e]/', '', strval($answers[strval($q)] ?? '')));
                if ($sel === '') continue;   // 未涂：记错
                if ($kd === 'multi') {
                    $ok = count(array_diff(str_split($sel), str_split($k2))) === 0;   // 所涂字母都在答案内（不含涂错）
                } else {
                    $ok = ($sel === $k2);
                }
                if ($ok) { $got++; if ($hasPts) $nn += floatval($ctx['points'][$q] ?? 0); }
            }
            if (!$den) return '';
            return $hasPts ? (omr_num_fmt($nn) . '/' . omr_num_fmt($xx)) : ($got . '/' . $den);
        }
    }
    if (!function_exists('omr_eval_num')) {
        // 「8/10」→「8」、「7.5/10」→「7.5」（数值评价模式同步用，支持分值制小数）
        function omr_eval_num($score) {
            if ($score === '') return '';
            return omr_num_fmt(substr(strval($score), 0, strcspn(strval($score), '/')));
        }
    }
    if (!function_exists('omr_resync_scores')) {
        // 「录入答案」变更后按当前生效键实时重算该项目全部识别结果（各行按其模板布局），
        // 同步 omr_results.score 与题次/汇总评价值：新得分非空→覆盖评价值；原得分非空而新得分为空→清回仅登记
        function omr_resync_scores($conn, $project_id) {
            $pid = intval($project_id);
            $tpls = [];
            $res = mysqli_query($conn, "SELECT id, layout FROM omr_templates WHERE project_id = {$pid}");
            while ($row = mysqli_fetch_assoc($res)) $tpls[intval($row['id'])] = strval($row['layout']);
            $ctx_cache = [];
            $ctx_of = function ($tid) use ($conn, $pid, $tpls, &$ctx_cache) {
                if (!isset($ctx_cache[$tid])) {
                    $layout = json_decode($tpls[intval($tid)] ?? '', true);
                    if (!is_array($layout) || empty($layout['sections'])) {   // 识别行模板无效/不存在：回退项目绑定模板（口径同 omr_edit/omr_manual_save）
                        $bind_tid = intval(get_setting($conn, 'omr_tpl_bind_' . $pid, '0'));
                        if ($bind_tid > 0 && isset($tpls[$bind_tid])) $layout = json_decode($tpls[$bind_tid], true);
                    }
                    $ctx_cache[$tid] = omr_answer_ctx($conn, $pid, is_array($layout) ? $layout : []);
                }
                return $ctx_cache[$tid];
            };
            $latest = [];   // student_id => [round => eval_num]（含空串）
            $res = mysqli_query($conn, "SELECT id, round_no, template_id, student_id, answers, score FROM omr_results WHERE project_id = {$pid}");
            while ($row = mysqli_fetch_assoc($res)) {
                $ans = json_decode(strval($row['answers']), true);
                $new = omr_grade_flat($ctx_of(intval($row['template_id'])), is_array($ans) ? $ans : []);
                $old = strval($row['score']);
                if ($new !== $old) {
                    $stmt = mysqli_prepare($conn, "UPDATE omr_results SET score = ? WHERE id = ?");
                    mysqli_stmt_bind_param($stmt, "si", $new, intval($row['id']));
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
                $sid = intval($row['student_id']); $rnd = intval($row['round_no']);
                if ($sid > 0) $latest[$sid][$rnd] = omr_eval_num($new);   // records.eval_value 为数值口径（如 3），非 "3/3"
                if ($sid > 0 && ($new !== $old)) {
                    $enew = omr_eval_num($new); $eold = omr_eval_num($old);
                    if ($enew !== '') {
                        $stmt = mysqli_prepare($conn, "UPDATE record_rounds SET eval_value = ?, eval_at = NOW(), eval_cleared_at = NULL
                                                       WHERE project_id = ? AND student_id = ? AND round_no = ?");
                        mysqli_stmt_bind_param($stmt, "siii", $enew, $pid, $sid, $rnd);
                    } elseif ($eold !== '') {
                        $stmt = mysqli_prepare($conn, "UPDATE record_rounds SET eval_value = '', eval_cleared_at = NOW()
                                                       WHERE project_id = ? AND student_id = ? AND round_no = ?");
                        mysqli_stmt_bind_param($stmt, "iii", $pid, $sid, $rnd);
                    } else {
                        $stmt = null;
                    }
                    if ($stmt) { mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt); }
                }
            }
            // records 汇总口径：每生取最大题次的非空评价值覆盖（与实时提交「仅写非空」一致，不主动清空）
            foreach ($latest as $sid => $rv) {
                krsort($rv);
                $ev = '';
                foreach ($rv as $v) { if ($v !== '') { $ev = $v; break; } }
                if ($ev === '') continue;
                $stmt = mysqli_prepare($conn, "INSERT INTO records (project_id, student_id, registered, registered_at, registered_by, eval_value, eval_at)
                                               VALUES (?, ?, 1, NOW(), 'scan', ?, NOW())
                                               ON DUPLICATE KEY UPDATE registered = 1, registered_at = IF(registered = 1, registered_at, NOW()),
                                                   eval_value = VALUES(eval_value), eval_at = NOW(), eval_cleared_at = NULL");
                mysqli_stmt_bind_param($stmt, "iis", $pid, $sid, $ev);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
    }

    if ($type === 'omr_template_save') {
        $project_id = intval($_POST['project_id'] ?? 0);
        list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
        if (!$project || (($project['mode'] ?? '') !== 'omr')) json_response(['success' => false, 'message' => '项目不存在或非答题卡模式项目']);
        $name = check_input($_POST['name'] ?? '');
        if ($name === '') json_response(['success' => false, 'message' => '请先输入模板名称（保存模板必须填写名称）']);
        if (mb_strlen($name) > 50) json_response(['success' => false, 'message' => '模板名称过长']);
        $layout = json_decode(strval($_POST['layout'] ?? ''), true);
        if (!is_array($layout)) json_response(['success' => false, 'message' => '布局数据无效']);
        if (strlen(strval($_POST['layout'])) > 300000) json_response(['success' => false, 'message' => '布局数据过大']);
        $pw = floatval($layout['paper']['w'] ?? 0); $ph = floatval($layout['paper']['h'] ?? 0);
        $bubbles = $layout['bubbles'] ?? null;
        if ($pw < 100 || $pw > 400 || $ph < 100 || $ph > 500 || !is_array($bubbles) || !count($bubbles) || count($bubbles) > 3000) {
            json_response(['success' => false, 'message' => '布局缺少纸面尺寸或涂框坐标（bubbles）']);
        }
        foreach ($bubbles as $b) {
            if (!is_array($b) || !isset($b['x'], $b['y'], $b['w'], $b['h'])) json_response(['success' => false, 'message' => '涂框坐标缺失']);
        }
        // 保存模板必须有身份识别区域（涂号区）与答题区域（至少一个题组），否则识别端无从匹配身份与读题
        $id_digits = intval($layout['id']['digits'] ?? 0);
        if ($id_digits < 1) json_response(['success' => false, 'message' => '模板必须包含身份识别区域（座号/编号涂号区）']);
        if (!isset($layout['sections']) || !is_array($layout['sections']) || !count($layout['sections'])) {
            json_response(['success' => false, 'message' => '模板必须包含答题区域（请至少添加一个题组）']);
        }
        $layout_json = json_encode($layout, JSON_UNESCAPED_UNICODE);
        $tid = intval($_POST['id'] ?? 0);
        if ($tid > 0) {
            $stmt = mysqli_prepare($conn, "SELECT id FROM omr_templates WHERE id = ? AND project_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $tid, $project_id);
            mysqli_stmt_execute($stmt);
            $exists = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
            mysqli_stmt_close($stmt);
            if (!$exists) $tid = 0;
        }
        if ($tid > 0) {
            $stmt = mysqli_prepare($conn, "UPDATE omr_templates SET name = ?, layout = ?, updated_at = NOW() WHERE id = ? AND project_id = ?");
            mysqli_stmt_bind_param($stmt, "ssii", $name, $layout_json, $tid, $project_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO omr_templates (project_id, name, layout, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
            mysqli_stmt_bind_param($stmt, "iss", $project_id, $name, $layout_json);
            mysqli_stmt_execute($stmt);
            $tid = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);
        }
        json_response(['success' => true, 'id' => $tid, 'message' => '模板已保存']);
    }

    if ($type === 'omr_template_list' || $type === 'omr_template_get') {
        $project_id = intval($_POST['project_id'] ?? 0);
        list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
        if (!$project) json_response(['success' => false, 'message' => '项目不存在或无权限']);
        if ($type === 'omr_template_list') {
            $res = mysqli_query($conn, "SELECT id, name, updated_at FROM omr_templates WHERE project_id = {$project_id} ORDER BY updated_at DESC, id DESC");
            $items = [];
            while ($row = mysqli_fetch_assoc($res)) $items[] = ['id' => intval($row['id']), 'name' => $row['name'], 'updated_at' => $row['updated_at']];
            json_response(['success' => true, 'items' => $items]);
        }
        $tid = intval($_POST['id'] ?? 0);
        $stmt = mysqli_prepare($conn, "SELECT id, name, layout FROM omr_templates WHERE id = ? AND project_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $tid, $project_id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$row) json_response(['success' => false, 'message' => '模板不存在']);
        json_response(['success' => true, 'id' => intval($row['id']), 'name' => $row['name'], 'layout' => json_decode($row['layout'], true)]);
    }

    // ===== 项目 ↔ 模板绑定：设计器里选定/保存模板即绑定；识别页不再下拉选模板，固定用绑定模板（防止扫错模板） =====
    if ($type === 'omr_tpl_bind_get' || $type === 'omr_tpl_bind_set') {
        $project_id = intval($_POST['project_id'] ?? 0);
        list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
        if (!$project || (($project['mode'] ?? '') !== 'omr')) json_response(['success' => false, 'message' => '项目不存在或非答题卡项目']);
        if ($type === 'omr_tpl_bind_get') {
            json_response(['success' => true, 'template_id' => intval(get_setting($conn, 'omr_tpl_bind_' . $project_id, '0'))]);
        }
        $bind_tid = intval($_POST['template_id'] ?? 0);
        if ($bind_tid > 0) {
            $stmt = mysqli_prepare($conn, "SELECT id FROM omr_templates WHERE id = ? AND project_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $bind_tid, $project_id);
            mysqli_stmt_execute($stmt);
            $ok = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
            mysqli_stmt_close($stmt);
            if (!$ok) json_response(['success' => false, 'message' => '模板不存在']);
        }
        set_setting($conn, 'omr_tpl_bind_' . $project_id, strval($bind_tid));
        json_response(['success' => true, 'template_id' => $bind_tid]);
    }

    if ($type === 'omr_template_del') {
        $project_id = intval($_POST['project_id'] ?? 0);
        list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
        if (!$project) json_response(['success' => false, 'message' => '项目不存在或无权限']);
        // 敏感操作：需输入本人登录密码二次确认（误删模板会导致已打印答题卡无法继续识别）
        if (!verify_login_password($conn, $teacher_id, $_POST['pwd'] ?? '')) {
            json_response(['success' => false, 'message' => '登录密码错误，模板未删除']);
        }
        $tid = intval($_POST['id'] ?? 0);
        $stmt = mysqli_prepare($conn, "DELETE FROM omr_templates WHERE id = ? AND project_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $tid, $project_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        json_response(['success' => true, 'message' => '模板已删除']);
    }

    // ===== 答题卡共享模板库（学校级共享；建立/加载/管理按身份，权限助手见 functions.php omr_lib_*） =====
    if (!function_exists('omr_lib_class_map')) {
        // 本校班级名称映射（选项构建、范围校验、展示标签共用）
        function omr_lib_class_map($conn, $school_id) {
            $classes = [];
            $res = mysqli_query($conn, "SELECT id, name FROM classes WHERE school_id = " . intval($school_id) . " AND deleted_at IS NULL ORDER BY id");
            while ($r = mysqli_fetch_assoc($res)) $classes[intval($r['id'])] = $r['name'];
            return $classes;
        }
    }
    if (!function_exists('omr_lib_build_options')) {
        // 「可保存范围」选项（单用户版仅班级范围），逐项过 can_create_project 校验
        function omr_lib_build_options($conn, $teacher_id, $school_id) {
            $cmap = omr_lib_class_map($conn, $school_id);
            $opts = [];
            foreach ($cmap as $cid2 => $cname) {
                if (can_create_project($conn, $teacher_id, 'class', intval($cid2)))
                    $opts[] = ['value' => 'class:' . intval($cid2), 'label' => '班级：' . $cname];
            }
            return $opts;
        }
    }
    if (!function_exists('omr_lib_parse_scope')) {
        // 解析并校验范围选项（单用户版仅 class:班级id），附加班级必须属于本校
        function omr_lib_parse_scope($conn, $school_id, $raw) {
            $parts = explode(':', trim(strval($raw)));
            $scope = strval($parts[0] ?? '');
            $cid = intval($parts[1] ?? 0);
            if ($scope !== 'class' || $cid <= 0) return null;
            $cmap = omr_lib_class_map($conn, $school_id);
            if (!isset($cmap[$cid])) return null;
            return ['scope' => 'class', 'class_id' => $cid];
        }
    }

    if ($type === 'omr_lib_options' || $type === 'omr_lib_list') {
        if (!omr_lib_enabled($conn)) json_response(['success' => false, 'message' => '共享模板库未开启']);
        $lib_school = current_school_id($conn);
        if ($lib_school <= 0) json_response(['success' => false, 'message' => '未加入学校，共享模板库不可用']);
        $opts = omr_lib_build_options($conn, $teacher_id, $lib_school);
        if ($type === 'omr_lib_options') json_response(['success' => true, 'options' => $opts]);
        list($cmap) = [omr_lib_class_map($conn, $lib_school)];
        $res = mysqli_query($conn, "SELECT o.*, t.realname, t.username FROM omr_lib o
                                    LEFT JOIN teachers t ON t.id = o.created_by
                                    WHERE o.school_id = " . intval($lib_school) . "
                                    ORDER BY o.updated_at DESC, o.id DESC");
        $items = [];
        while ($row = mysqli_fetch_assoc($res)) {
            if (!can_load_omr_lib($conn, $teacher_id, $row)) continue;   // 按身份过滤可见（可加载）范围
            $so = strval($row['scope']);
            if ($so === 'class') { $so .= ':' . intval($row['class_id']); $sc_label = '班级·' . ($cmap[intval($row['class_id'])] ?? '?'); }
            else { $sc_label = '全校'; }
            $items[] = ['id' => intval($row['id']), 'name' => $row['name'], 'scope' => strval($row['scope']),
                        'scope_option' => $so, 'scope_label' => $sc_label,
                        'creator' => (strval($row['realname']) !== '' ? $row['realname'] : strval($row['username'])),
                        'updated_at' => strval($row['updated_at']),
                        'can_edit' => can_manage_omr_lib($conn, $teacher_id, $row) ? 1 : 0];
        }
        json_response(['success' => true, 'items' => $items, 'options' => $opts]);
    }

    if ($type === 'omr_lib_save') {
        if (!omr_lib_enabled($conn)) json_response(['success' => false, 'message' => '共享模板库未开启']);
        $lib_school = current_school_id($conn);
        if ($lib_school <= 0) json_response(['success' => false, 'message' => '未加入学校，共享模板库不可用']);
        $lib_id = intval($_POST['id'] ?? 0);
        $name = check_input($_POST['name'] ?? '');
        if ($name === '') $name = '共享模板';
        if (mb_strlen($name) > 50) json_response(['success' => false, 'message' => '模板名称过长']);
        $sc = omr_lib_parse_scope($conn, $lib_school, $_POST['scope_option'] ?? '');
        if (!$sc) json_response(['success' => false, 'message' => '适用范围无效或不存在']);
        // 布局校验（与项目模板同规则）；更新时 layout 可省略 = 仅改名/改范围
        $raw_layout = strval($_POST['layout'] ?? '');
        $layout_json = '';
        if ($raw_layout !== '') {
            $layout = json_decode($raw_layout, true);
            if (!is_array($layout)) json_response(['success' => false, 'message' => '布局数据无效']);
            if (strlen($raw_layout) > 300000) json_response(['success' => false, 'message' => '布局数据过大']);
            $pw = floatval($layout['paper']['w'] ?? 0); $ph = floatval($layout['paper']['h'] ?? 0);
            $bubbles = $layout['bubbles'] ?? null;
            if ($pw < 100 || $pw > 400 || $ph < 100 || $ph > 500 || !is_array($bubbles) || !count($bubbles) || count($bubbles) > 3000) {
                json_response(['success' => false, 'message' => '布局缺少纸面尺寸或涂框坐标（bubbles）']);
            }
            foreach ($bubbles as $b) {
                if (!is_array($b) || !isset($b['x'], $b['y'], $b['w'], $b['h'])) json_response(['success' => false, 'message' => '涂框坐标缺失']);
            }
            $layout_json = json_encode($layout, JSON_UNESCAPED_UNICODE);
        }
        if ($lib_id > 0) {
            $stmt = mysqli_prepare($conn, "SELECT * FROM omr_lib WHERE id = ? AND school_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $lib_id, $lib_school);
            mysqli_stmt_execute($stmt);
            $old = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$old) json_response(['success' => false, 'message' => '模板库中不存在该模板']);
            if (!can_manage_omr_lib($conn, $teacher_id, $old)) json_response(['success' => false, 'message' => '仅创建者可修改该模板']);
            $scope_changed = strval($old['scope']) !== $sc['scope'] || intval($old['class_id']) !== $sc['class_id'];
            if ($scope_changed && !can_create_omr_lib($conn, $teacher_id, $sc['scope'], $sc['class_id'])) {
                json_response(['success' => false, 'message' => '您没有在新范围建立模板的权限']);
            }
            if ($layout_json !== '') {
                $stmt = mysqli_prepare($conn, "UPDATE omr_lib SET name = ?, scope = ?, class_id = ?, layout = ?, updated_at = NOW() WHERE id = ?");
                mysqli_stmt_bind_param($stmt, "ssisi", $name, $sc['scope'], $sc['class_id'], $layout_json, $lib_id);
            } else {
                $stmt = mysqli_prepare($conn, "UPDATE omr_lib SET name = ?, scope = ?, class_id = ?, updated_at = NOW() WHERE id = ?");
                mysqli_stmt_bind_param($stmt, "ssii", $name, $sc['scope'], $sc['class_id'], $lib_id);
            }
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            json_response(['success' => true, 'id' => $lib_id, 'message' => '模板库模板已更新']);
        }
        if ($raw_layout === '') json_response(['success' => false, 'message' => '布局数据无效']);
        if (!can_create_omr_lib($conn, $teacher_id, $sc['scope'], $sc['class_id'])) {
            json_response(['success' => false, 'message' => '无该范围建模板权限']);
        }
        $stmt = mysqli_prepare($conn, "INSERT INTO omr_lib (school_id, name, scope, class_id, layout, created_by, created_at, updated_at)
                                       VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
        mysqli_stmt_bind_param($stmt, "issisi", $lib_school, $name, $sc['scope'], $sc['class_id'], $layout_json, $teacher_id);
        mysqli_stmt_execute($stmt);
        $lib_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        json_response(['success' => true, 'id' => $lib_id, 'message' => '已存入模板库']);
    }

    if ($type === 'omr_lib_get' || $type === 'omr_lib_del') {
        if (!omr_lib_enabled($conn)) json_response(['success' => false, 'message' => '共享模板库未开启']);
        $lib_school = current_school_id($conn);
        $lib_id = intval($_POST['id'] ?? 0);
        $stmt = mysqli_prepare($conn, "SELECT * FROM omr_lib WHERE id = ? AND school_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $lib_id, $lib_school);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$row) json_response(['success' => false, 'message' => '模板库中不存在该模板']);
        if ($type === 'omr_lib_get') {
            if (!can_load_omr_lib($conn, $teacher_id, $row)) json_response(['success' => false, 'message' => '您没有加载该模板的权限']);
            json_response(['success' => true, 'id' => intval($row['id']), 'name' => $row['name'], 'layout' => json_decode($row['layout'], true)]);
        }
        if (!can_manage_omr_lib($conn, $teacher_id, $row)) json_response(['success' => false, 'message' => '仅创建者可删除该模板']);
        // 敏感操作：需输入本人登录密码二次确认
        if (!verify_login_password($conn, $teacher_id, $_POST['pwd'] ?? '')) {
            json_response(['success' => false, 'message' => '登录密码错误，模板未删除']);
        }
        $stmt = mysqli_prepare($conn, "DELETE FROM omr_lib WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $lib_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        json_response(['success' => true, 'message' => '模板已从模板库删除']);
    }

    if ($type === 'omr_submit') {
        $project_id = intval($_POST['project_id'] ?? 0);
        list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
        if (!$project || (($project['mode'] ?? '') !== 'omr')) json_response(['success' => false, 'message' => '项目不存在或非答题卡模式项目']);
        $tid = intval($_POST['template_id'] ?? 0);
        $idtype = (($_POST['idtype'] ?? '') === 'no') ? 'no' : 'seat';
        $raw = trim(strval($_POST['ident'] ?? ''));
        if ($raw === '' || mb_strlen($raw) > 30) json_response(['success' => false, 'message' => '识别身份为空或过长']);
        $answers_raw = json_decode(strval($_POST['answers'] ?? ''), true);
        $answers = [];
        if (is_array($answers_raw)) {
            foreach ($answers_raw as $q => $v) {
                $qk = preg_replace('/[^0-9A-Za-z_]/', '', strval($q));
                $vv = strtoupper(preg_replace('/[^A-Ea-e]/', '', strval($v)));
                if ($qk !== '' && $vv !== '') $answers[$qk] = $vv;
            }
        }
        $flags = check_input($_POST['flags'] ?? '');
        if (mb_strlen($flags) > 200) $flags = mb_substr($flags, 0, 200);

        // 身份匹配：精确相等 + 数值等价（前导零兼容：raw=7 可命中座号 007，raw=007 也可命中座号 7）
        $cands = [$raw];
        $lookup_col = ($idtype === 'no') ? 's.student_no' : 's.seat_no';
        $numeric_eq = '';
        if (ctype_digit($raw)) {
            $numeric_eq = " OR ({$lookup_col} REGEXP '^[0-9]+$' AND {$lookup_col} + 0 = " . intval($raw) . ")";
        }
        $in = implode(',', array_fill(0, count($cands), '?'));
        $types = str_repeat('s', count($cands));
        $stmt = mysqli_prepare($conn, "SELECT s.id, s.name, s.seat_no, s.student_no, s.disabled, s.class_id, c.name AS class_name
                                       FROM students s INNER JOIN classes c ON s.class_id = c.id
                                       WHERE ({$lookup_col} IN ({$in}){$numeric_eq}) AND s.class_id IN (" . class_ids_in($class_ids) . ")");
        mysqli_stmt_bind_param($stmt, $types, ...$cands);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $matches = [];
        while ($row = mysqli_fetch_assoc($res)) $matches[] = $row;
        mysqli_stmt_close($stmt);
        $no_label = ($idtype === 'no') ? '编号' : '座号';
        if (!$matches) json_response(['success' => false, 'message' => '未找到' . $no_label . '为 "' . $raw . '" 的学生（须为项目覆盖班级学生）']);
        if (count($matches) > 1) {
            $names = [];
            foreach ($matches as $m) $names[] = $m['class_name'] . ' ' . $m['name'];
            json_response(['success' => false, 'message' => '该' . $no_label . '对应多名学生（' . implode('、', $names) . '），无法唯一识别']);
        }
        $student = $matches[0];
        if (!empty($student['disabled'])) json_response(['success' => false, 'message' => $student['class_name'] . ' ' . $student['name'] . ' 已禁用，不参与登记']);

        // 得分：生效答案键完整时批改（score="对/总"）；不完整仅登记（识别不受影响）；评价值取数值（项目评价模式=「数值」）。
        // 多页答题卡：pg=本次扫描页码（1 起）。同生同题次各页合并到同一行——模板布局给出「题号→页」归属，
        // 本页重扫只覆盖本页题号，其余页已扫答案保留；pages=已扫页码集合；合并后按完整键重新判分
        ensure_omr_round($conn);
        $round = current_round_no($conn, $project);
        $pg = max(1, intval($_POST['pg'] ?? 1));
        $qpg = [];          // 题号 → 页码（默认全部属第 1 页，兼容旧模板）
        $tpl_pages = 1;
        if ($tid > 0) {
            $stmt = mysqli_prepare($conn, "SELECT layout FROM omr_templates WHERE id = ? AND project_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $tid, $project_id);
            mysqli_stmt_execute($stmt);
            $trow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if ($trow) {
                $lay = json_decode($trow['layout'], true);
                if (is_array($lay)) {
                    $tpl_pages = min(5, max(1, intval($lay['pages'] ?? 1)));
                    foreach ((is_array($lay['sections'] ?? null) ? $lay['sections'] : []) as $sec) {
                        if (!is_array($sec)) continue;
                        $spg = intval($sec['pg'] ?? 0) + 1;
                        $st2 = intval($sec['start'] ?? 1); $ct2 = min(500, max(1, intval($sec['count'] ?? 0)));
                        for ($q2 = $st2; $q2 < $st2 + $ct2; $q2++) $qpg[strval($q2)] = $spg;
                    }
                }
            }
        }
        $pg = min($pg, max(1, $tpl_pages));
        // 与既有行合并（同生同题次唯一键 project+round+ident）：本页旧答案剔除（重扫覆盖）→ 并入本页新答案
        $ident = $idtype . ':' . $raw;
        $stmt = mysqli_prepare($conn, "SELECT answers, pages FROM omr_results WHERE project_id = ? AND round_no = ? AND ident = ?");
        mysqli_stmt_bind_param($stmt, "iis", $project_id, $round, $ident);
        mysqli_stmt_execute($stmt);
        $prev = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $merged = [];
        if ($prev) {
            $pans = json_decode(strval($prev['answers']), true);
            if (is_array($pans)) $merged = $pans;
        }
        foreach ($merged as $qk2 => $vv2) {
            if (intval($qpg[strval($qk2)] ?? 1) === $pg) unset($merged[$qk2]);   // 本页旧答案：以本次识别为准
        }
        foreach ($answers as $qk3 => $vv3) $merged[$qk3] = $vv3;
        $pagesArr = $prev ? array_filter(array_map('intval', explode(',', strval($prev['pages'])))) : [];
        if (!in_array($pg, $pagesArr)) $pagesArr[] = $pg;
        sort($pagesArr);
        $pages_csv = implode(',', $pagesArr);

        $score = '';
        if ($tid > 0 && isset($lay)) $score = omr_grade_flat(omr_answer_ctx($conn, $project_id, $lay), $merged);
        $eval_num = omr_eval_num($score);

        $answers_json = json_encode($merged, JSON_UNESCAPED_UNICODE);
        // 结果落库（同生同题次重扫覆盖：更新答案/得分/警告/页码集合并刷新时间；不同题次各留一条）
        $stmt = mysqli_prepare($conn, "INSERT INTO omr_results (project_id, round_no, template_id, student_id, ident, answers, score, flags, pages, created_at, updated_at)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                                       ON DUPLICATE KEY UPDATE template_id = VALUES(template_id), student_id = VALUES(student_id),
                                           answers = VALUES(answers), score = VALUES(score), flags = VALUES(flags), pages = VALUES(pages), updated_at = NOW()");
        mysqli_stmt_bind_param($stmt, "iiiisssss", $project_id, $round, $tid, $student['id'], $ident, $answers_json, $score, $flags, $pages_csv);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        // 题次维度登记+评价（评价=登记+评价：识别成功即登记该生；有得分同步评价值，重扫按最后一次覆盖）
        $stmt = mysqli_prepare($conn, "SELECT registered_at FROM record_rounds WHERE project_id = ? AND student_id = ? AND round_no = ?");
        mysqli_stmt_bind_param($stmt, "iii", $project_id, $student['id'], $round);
        mysqli_stmt_execute($stmt);
        $rr = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $already = $rr && !empty($rr['registered_at']);
        $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO record_rounds (project_id, student_id, round_no, registered_at, registered_by, created_at)
                                       VALUES (?, ?, ?, NOW(), 'scan', NOW())");
        mysqli_stmt_bind_param($stmt, "iii", $project_id, $student['id'], $round);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        if ($eval_num !== '') {
            $stmt = mysqli_prepare($conn, "UPDATE record_rounds SET eval_value = ?, eval_at = NOW(), eval_cleared_at = NULL
                                           WHERE project_id = ? AND student_id = ? AND round_no = ?");
            mysqli_stmt_bind_param($stmt, "siii", $eval_num, $project_id, $student['id'], $round);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

        // records 表同步（汇总/导出口径）：登记保持；评价值=数值得分（重扫覆盖为最新）
        if ($eval_num !== '') {
            $stmt = mysqli_prepare($conn, "INSERT INTO records (project_id, student_id, registered, registered_at, registered_by, eval_value, eval_at)
                                           VALUES (?, ?, 1, NOW(), 'scan', ?, NOW())
                                           ON DUPLICATE KEY UPDATE registered = 1, registered_at = IF(registered = 1, registered_at, NOW()),
                                               eval_value = VALUES(eval_value), eval_at = NOW(), eval_cleared_at = NULL");
            mysqli_stmt_bind_param($stmt, "iis", $project_id, $student['id'], $eval_num);
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO records (project_id, student_id, registered, registered_at, registered_by, eval_value)
                                           VALUES (?, ?, 1, NOW(), 'scan', '')
                                           ON DUPLICATE KEY UPDATE registered = 1");
            mysqli_stmt_bind_param($stmt, "ii", $project_id, $student['id']);
        }
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        list($reg_cnt) = round_counts($conn, $project_id, $round);
        json_response([
            'success' => true,
            'round' => $round,
            'student' => [
                'id' => intval($student['id']),
                'name' => $student['name'],
                'seat_no' => $student['seat_no'],
                'student_no' => $student['student_no'],
                'class_name' => $student['class_name'],
            ],
            'already' => $already,
            'score' => $score,
            'answers' => $answers,
            'registered_count' => $reg_cnt,
            'total' => total_students_in($conn, $class_ids),
        ]);
    }

    // ===== 身份预匹配（只读，不落库）：识别完成后即时回显「座号/编号 + 姓名」到预览图身份区上方；
    // 与 omr_submit 完全同口径（精确相等 + 数值等价、项目覆盖班级范围、禁用不参与、多名同值拒绝） =====
    if ($type === 'omr_ident_preview') {
        $project_id = intval($_POST['project_id'] ?? 0);
        list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
        if (!$project || (($project['mode'] ?? '') !== 'omr')) json_response(['success' => false, 'message' => '项目不存在或非答题卡模式项目']);
        $idtype = (($_POST['idtype'] ?? '') === 'no') ? 'no' : 'seat';
        $raw = trim(strval($_POST['ident'] ?? ''));
        if ($raw === '' || mb_strlen($raw) > 30) json_response(['success' => true, 'matched' => false]);
        $lookup_col = ($idtype === 'no') ? 's.student_no' : 's.seat_no';
        $numeric_eq = '';
        if (ctype_digit($raw)) {
            $numeric_eq = " OR ({$lookup_col} REGEXP '^[0-9]+$' AND {$lookup_col} + 0 = " . intval($raw) . ")";
        }
        $stmt = mysqli_prepare($conn, "SELECT s.id, s.name, s.seat_no, s.student_no, s.disabled, c.name AS class_name
                                       FROM students s INNER JOIN classes c ON s.class_id = c.id
                                       WHERE ({$lookup_col} = ?{$numeric_eq}) AND s.class_id IN (" . class_ids_in($class_ids) . ")");
        mysqli_stmt_bind_param($stmt, "s", $raw);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $matches = [];
        while ($row = mysqli_fetch_assoc($res)) $matches[] = $row;
        mysqli_stmt_close($stmt);
        if (count($matches) !== 1 || !empty($matches[0]['disabled'])) json_response(['success' => true, 'matched' => false]);
        $m = $matches[0];
        json_response(['success' => true, 'matched' => true, 'student' => [
            'id' => intval($m['id']), 'name' => $m['name'], 'seat_no' => $m['seat_no'],
            'student_no' => $m['student_no'], 'class_name' => $m['class_name'],
        ]]);
    }

    // ===== 「录入答案」独立答案键（与模板解耦：改答案不动模板，避免已打印答题卡坐标失效） =====
    if ($type === 'omr_answer_get' || $type === 'omr_answer_save' || $type === 'omr_answer_clear' || $type === 'omr_force_set') {
        $project_id = intval($_POST['project_id'] ?? 0);
        list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
        if (!$project || (($project['mode'] ?? '') !== 'omr')) json_response(['success' => false, 'message' => '项目不存在或非答题卡模式项目']);
        if ($type === 'omr_force_set') {
            // 强制批改开关（项目级）：开=答案不完整时按「已有答案的题」批改，其余题只记录选择；关=不完整仅登记
            $on = (intval($_POST['on'] ?? 0) === 1);
            set_setting($conn, 'omr_force_grade_' . $project_id, $on ? '1' : '0');
            omr_resync_scores($conn, $project_id);   // 批改口径变化：按新口径实时重算已识别结果
            json_response(['success' => true, 'force' => $on,
                           'message' => $on ? '已开启强制批改：答案不完整时按已有答案的题批改，其余题只记录选择'
                                            : '已关闭强制批改：答案不完整时仅登记不批改']);
        }
        if ($type === 'omr_answer_get') {
            $pa = omr_indep_answers($conn, $project_id);
            json_response(['success' => true, 'source' => count($pa) ? 'project' : 'template', 'answers' => $pa,
                           'force' => get_setting($conn, 'omr_force_grade_' . $project_id, '0') === '1']);
        }
        if ($type === 'omr_answer_clear') {
            $stmt = mysqli_prepare($conn, "DELETE FROM omr_answers WHERE project_id = ?");
            mysqli_stmt_bind_param($stmt, "i", $project_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            omr_resync_scores($conn, $project_id);   // 回退到模板答案键后实时重算
            json_response(['success' => true, 'message' => '已清除独立答案（改用模板答案键），识别结果已重算']);
        }
        // omr_answer_save：清洗 {题号: 答案}（题号纯数字，答案 A-E 字母集合去重升序）；空数组=清除（回退模板答案键）
        $raw = json_decode(strval($_POST['answers'] ?? ''), true);
        if (!is_array($raw)) json_response(['success' => false, 'message' => '答案数据无效']);
        $clean = [];
        foreach ($raw as $q => $v) {
            $qk = preg_replace('/[^0-9]/', '', strval($q));
            $vv = strtoupper(preg_replace('/[^A-E]/', '', strval($v)));
            if ($qk === '' || $vv === '') continue;
            $letters = array_unique(str_split($vv));
            sort($letters);
            $clean[$qk] = implode('', $letters);
            if (count($clean) >= 500) break;
        }
        // 模板答案最优先：剔除与模板答案键重复的题号（独立录入仅可补模板未设答案的题），客户端 UI 同口径锁定
        $bind_tid = intval(get_setting($conn, 'omr_tpl_bind_' . $project_id, '0'));
        if ($bind_tid > 0) {
            $stmt = mysqli_prepare($conn, "SELECT layout FROM omr_templates WHERE id = ? AND project_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $bind_tid, $project_id);
            mysqli_stmt_execute($stmt);
            $trow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if ($trow) {
                list($_k, $tk) = omr_layout_ctx(json_decode(strval($trow['layout']), true));
                foreach ($clean as $qk => $vv) if (!empty($tk[$qk])) unset($clean[$qk]);
            }
        }
        if (!count($clean)) {
            $stmt = mysqli_prepare($conn, "DELETE FROM omr_answers WHERE project_id = ?");
            mysqli_stmt_bind_param($stmt, "i", $project_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        } else {
            $answers_json = json_encode($clean, JSON_UNESCAPED_UNICODE);
            $stmt = mysqli_prepare($conn, "INSERT INTO omr_answers (project_id, answers, updated_at, updated_by) VALUES (?, ?, NOW(), ?)
                                           ON DUPLICATE KEY UPDATE answers = VALUES(answers), updated_at = NOW(), updated_by = VALUES(updated_by)");
            mysqli_stmt_bind_param($stmt, "isi", $project_id, $answers_json, $teacher_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            set_setting($conn, 'omr_answers_tid_' . $project_id, strval($bind_tid));   // 独立答案跟模板走：固化保存时的绑定模板 id，换绑即失效
        }
        omr_resync_scores($conn, $project_id);   // 按录入覆盖情况实时重算已识别结果
        json_response(['success' => true, 'answers' => $clean,
                       'message' => count($clean) ? '答案已保存，已识别结果已按当前答案重算' : '已清除独立答案']);

    }

    // ===== 🖼 批改图留存（登记成功后保存批注预览图；有权限传服务器，无权限存本机浏览器缓存，均支持导出） =====
    if ($type === 'omr_img_save' || $type === 'omr_img_list' || $type === 'omr_img_get' || $type === 'omr_img_del') {
        $project_id = intval($_POST['project_id'] ?? 0);
        if ($project_id <= 0 && $type === 'omr_img_get') $project_id = intval($_GET['project_id'] ?? 0);   // GET 直链取图（查看题卡）
        list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
        if (!$project || (($project['mode'] ?? '') !== 'omr')) json_response(['success' => false, 'message' => '项目不存在或非答题卡模式项目']);

        if ($type === 'omr_img_save') {
            if (!omrimg_upload_ok($conn, $teacher_id)) {
                json_response(['success' => false, 'message' => '当前账号无批改图上传服务器权限（仅存本机浏览器缓存）']);
            }
            $dataUrl = strval($_POST['data'] ?? '');
            if (!preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=]+)$/', $dataUrl, $m)) {
                json_response(['success' => false, 'message' => '图片数据无效（仅支持 PNG）']);
            }
            $bin = base64_decode($m[1], true);
            if ($bin === false || strlen($bin) < 100 || strlen($bin) > 10 * 1024 * 1024) {
                json_response(['success' => false, 'message' => '图片数据无效或超过 10MB']);
            }
            if (substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") {
                json_response(['success' => false, 'message' => '图片数据无效（PNG 魔数校验失败）']);
            }
            $dir = __DIR__ . '/uploads/omr/' . intval($project['school_id']) . '/' . intval($project_id);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                json_response(['success' => false, 'message' => '创建图片目录失败，请检查服务器写权限']);
            }
            $rel = 'uploads/omr/' . intval($project['school_id']) . '/' . intval($project_id) . '/'
                 . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.png';
            if (!@file_put_contents(__DIR__ . '/' . $rel, $bin)) {
                json_response(['success' => false, 'message' => '图片写入服务器失败']);
            }
            $round = intval($_POST['round'] ?? 1);
            $pg = max(1, intval($_POST['pg'] ?? 1));
            $ident = mb_substr(trim(strval($_POST['ident'] ?? '')), 0, 32);
            $stu_id = intval($_POST['student_id'] ?? 0);
            $stu_name = mb_substr(trim(strval($_POST['name'] ?? '')), 0, 50);
            $seat = mb_substr(trim(strval($_POST['seat'] ?? '')), 0, 20);
            $score = mb_substr(trim(strval($_POST['score'] ?? '')), 0, 32);
            $stmt = mysqli_prepare($conn, "INSERT INTO omr_images (school_id, project_id, round_no, pg, ident, student_id, student_name, seat_no, score, teacher_id, file, created_at)
                                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            mysqli_stmt_bind_param($stmt, "iiiisisssis", intval($project['school_id']), $project_id, $round, $pg, $ident,
                                   $stu_id, $stu_name, $seat, $score, $teacher_id, $rel);
            mysqli_stmt_execute($stmt);
            $img_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);
            json_response(['success' => true, 'id' => $img_id, 'message' => '批改图已保存到服务器']);
        }

        if ($type === 'omr_img_list') {
            $round = intval($_POST['round'] ?? 0);
            $where = "o.project_id = {$project_id}";
            if ($round > 0) $where .= " AND o.round_no = {$round}";
            $res = mysqli_query($conn, "SELECT o.id, o.round_no, o.pg, o.student_id, o.student_name, o.seat_no, o.score,
                                               o.teacher_id, o.file, o.created_at, IFNULL(NULLIF(t.realname, ''), t.username) AS teacher_name
                                        FROM omr_images o LEFT JOIN teachers t ON t.id = o.teacher_id
                                        WHERE {$where} ORDER BY o.id DESC LIMIT 2000");
            $items = [];
            while ($row = mysqli_fetch_assoc($res)) {
                $items[] = [
                    'id' => intval($row['id']), 'round_no' => intval($row['round_no']), 'pg' => intval($row['pg']),
                    'student_id' => intval($row['student_id']), 'name' => strval($row['student_name']),
                    'seat' => strval($row['seat_no']), 'score' => strval($row['score']),
                    'teacher_id' => intval($row['teacher_id']), 'teacher' => strval($row['teacher_name']),
                    'created_at' => strval($row['created_at']),
                ];
            }
            json_response(['success' => true, 'items' => $items, 'can_upload' => omrimg_upload_ok($conn, $teacher_id)]);
        }

        if ($type === 'omr_img_get') {
            $img_id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
            $stmt = mysqli_prepare($conn, "SELECT file FROM omr_images WHERE id = ? AND project_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $img_id, $project_id);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$row || strpos(strval($row['file']), 'uploads/omr/') !== 0 || strpos(strval($row['file']), '..') !== false) {
                @header('Content-Type: application/json');
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => '图片不存在']);
                exit;
            }
            $abs = __DIR__ . '/' . strval($row['file']);
            clearstatcache(true, $abs);   // 同进程新 mkdir 目录的负缓存会让 realpath 假失败（120s 窗口）
            $real = realpath($abs) ?: $abs;
            if (!is_file($real)) {
                @header('Content-Type: application/json');
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => '图片文件已不存在']);
                exit;
            }
            @header('Content-Type: image/png');
            @header('Content-Length: ' . filesize($real));
            @header('Cache-Control: max-age=86400');
            readfile($real);
            exit;
        }

        // omr_img_del：本人（teacher_id）或管理员可删；删文件 + 删行
        $img_id = intval($_POST['id'] ?? 0);
        $stmt = mysqli_prepare($conn, "SELECT id, file, teacher_id FROM omr_images WHERE id = ? AND project_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $img_id, $project_id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$row) json_response(['success' => false, 'message' => '记录不存在']);
        if (intval($row['teacher_id']) !== intval($teacher_id) && !is_school_admin($conn, $teacher_id)) {
            json_response(['success' => false, 'message' => '仅记录本人可删除']);
        }
        $f = strval($row['file']);
        if (strpos($f, 'uploads/omr/') === 0 && strpos($f, '..') === false) {
            $abs = __DIR__ . '/' . $f;
            clearstatcache(true, $abs);   // 同进程 mkdir 前的 is_dir 检查会留目录负缓存（120s），新目录内文件 realpath 会假失败
            $real = realpath($abs) ?: $abs;   // 字符串路径已校验前缀+无 '..'，可安全兜底
            $baseReal = realpath(__DIR__ . '/uploads/omr');
            if ($baseReal && strpos(str_replace('\\', '/', $real), str_replace('\\', '/', $baseReal) . '/') === 0) {
                // Windows 下杀软（如火绒）扫描窗口内持有句柄会致 unlink 共享冲突，小退避重试
                for ($try = 0; $try < 5; $try++) {
                    if (@unlink($real)) break;
                    usleep(300000);
                }
            }
        }
        $stmt = mysqli_prepare($conn, "DELETE FROM omr_images WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $img_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        json_response(['success' => true, 'message' => '已删除']);
    }

    // ===== 📝 答题统计（按题）：该题次每题选项分布 + 正确率（判分口径同批改：单选全等、多选子集；正确率=答对人数÷已答人数） =====
    if ($type === 'omr_questions') {
        $project_id = intval($_POST['project_id'] ?? 0);
        list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
        if (!$project || (($project['mode'] ?? '') !== 'omr')) json_response(['success' => false, 'message' => '项目不存在或非答题卡模式项目']);
        $cls = intval($_POST['cls_id'] ?? 0);
        if ($cls <= 0 || !in_array($cls, array_map('intval', $class_ids), true)) $cls = intval($class_ids[0] ?? 0);
        if ($cls <= 0) json_response(['success' => false, 'message' => '项目未覆盖任何班级']);
        $round = intval($_POST['round'] ?? 0);
        if ($round <= 0) $round = intval(current_round_no($conn, $project));
        // 绑定模板 + 生效答案键（与 with_sections 同口径：模板最优先，独立录入仅补未设题）
        $bind_tid = intval(get_setting($conn, 'omr_tpl_bind_' . $project_id, '0'));
        $bind_lay = null;
        if ($bind_tid > 0) {
            $stmt = mysqli_prepare($conn, "SELECT layout FROM omr_templates WHERE id = ? AND project_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $bind_tid, $project_id);
            mysqli_stmt_execute($stmt);
            $trow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if ($trow) $bind_lay = json_decode(strval($trow['layout']), true);
        }
        $kind = []; $key = [];
        if (is_array($bind_lay)) {
            list($kind, $tk, ) = omr_layout_ctx($bind_lay);
            $key = $tk;
            foreach (omr_indep_answers($conn, $project_id) as $q => $v) {
                $qk = preg_replace('/[^0-9]/', '', strval($q));
                $vv = strtoupper(preg_replace('/[^A-E]/', '', strval($v)));
                if ($qk !== '' && $vv !== '' && empty($key[$qk])) $key[$qk] = $vv;
            }
        }
        // 学生名单（名单下钻用）
        $stu = [];
        $stmt = mysqli_prepare($conn, "SELECT id, name, seat_no FROM students WHERE class_id = ? AND disabled = 0");
        mysqli_stmt_bind_param($stmt, "i", $cls);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) $stu[intval($row['id'])] = ['id' => intval($row['id']), 'name' => strval($row['name']), 'seat_no' => strval($row['seat_no'])];
        mysqli_stmt_close($stmt);
        // 该题次已批改作答聚合（omr_results.answers=JSON {题号: 选项字母串}）
        $perq = [];   // 题号 => [学生id => 选项字母串]
        $graded = 0;
        $stmt = mysqli_prepare($conn, "SELECT o.student_id, o.answers FROM omr_results o
                                       INNER JOIN students s ON o.student_id = s.id
                                       WHERE o.project_id = ? AND s.class_id = ? AND o.round_no = ? AND o.answers <> ''");
        mysqli_stmt_bind_param($stmt, "iii", $project_id, $cls, $round);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $dec = json_decode(strval($row['answers']), true);
            if (!is_array($dec)) continue;
            $graded++;
            $sid = intval($row['student_id']);
            foreach ($dec as $q => $v) {
                $qk = preg_replace('/[^0-9]/', '', strval($q));
                if ($qk === '' || !isset($kind[$qk])) continue;
                $sel = strtoupper(preg_replace('/[^A-Ea-e]/', '', strval($v)));
                if ($sel === '') continue;
                $perq[$qk][$sid] = $sel;
            }
        }
        mysqli_stmt_close($stmt);
        $qs = [];
        foreach (array_keys($kind) as $qk) {
            $sel_map = isset($perq[$qk]) ? $perq[$qk] : [];
            $counts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];
            $lists = ['A' => [], 'B' => [], 'C' => [], 'D' => [], 'E' => []];
            $ok = []; $wrong = [];
            $ck = strtoupper(preg_replace('/[^A-Ea-e]/', '', strval($key[$qk] ?? '')));
            foreach ($sel_map as $sid => $sel) {
                if (!isset($stu[$sid])) continue;
                $p = $stu[$sid];
                for ($ci = 0; $ci < strlen($sel); $ci++) {
                    $ch = $sel[$ci];
                    if (!isset($counts[$ch])) continue;
                    $counts[$ch]++;
                    $lists[$ch][] = $p;
                }
                $is_ok = ($kind[$qk] === 'multi') ? (count(array_diff(str_split($sel), str_split($ck))) === 0) : ($sel === $ck);
                if ($ck !== '') { if ($is_ok) $ok[] = $p; else $wrong[] = $p; }
            }
            $answered = count($sel_map);
            $qs[] = ['qno' => intval($qk), 'kind' => strval($kind[$qk]), 'correct' => $ck, 'counts' => $counts,
                     'answered' => $answered, 'okCnt' => count($ok),
                     'rate' => ($answered > 0 && $ck !== '') ? round(count($ok) / $answered * 1000) / 10 : null,
                     'lists' => $lists, 'ok' => $ok, 'wrong' => $wrong];
        }
        usort($qs, function ($a, $b) { return $a['qno'] - $b['qno']; });
        json_response(['success' => true, 'round' => $round, 'total_class' => count($stu), 'graded' => $graded, 'questions' => $qs]);
    }

    // ===== 📊 成绩统计（按题次汇总：平均分/优秀率/良好率/及格率/低分率/分段统计，名单下钻与导出由前端完成） =====
    if ($type === 'omr_stats') {
        $project_id = intval($_POST['project_id'] ?? 0);
        list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
        if (!$project || (($project['mode'] ?? '') !== 'omr')) json_response(['success' => false, 'message' => '项目不存在或非答题卡模式项目']);
        // 班级范围：默认项目首个班级；显式传 cls_id 时须在项目覆盖班级内（多班级项目按页内当前班级查看）
        $cls = intval($_POST['cls_id'] ?? 0);
        if ($cls <= 0 || !in_array($cls, array_map('intval', $class_ids), true)) $cls = intval($class_ids[0] ?? 0);
        if ($cls <= 0) json_response(['success' => false, 'message' => '项目未覆盖任何班级']);
        $stmt = mysqli_prepare($conn, "SELECT id, name FROM classes WHERE id = ? AND deleted_at IS NULL");
        mysqli_stmt_bind_param($stmt, "i", $cls);
        mysqli_stmt_execute($stmt);
        $crow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$crow) json_response(['success' => false, 'message' => '班级不存在']);
        // 全班学生（已禁用不参与，与页面口径一致）
        $students = [];
        $stmt = mysqli_prepare($conn, "SELECT id, name, seat_no, group_id FROM students WHERE class_id = ? AND disabled = 0
                                       ORDER BY CAST(seat_no AS UNSIGNED) ASC, id ASC");
        mysqli_stmt_bind_param($stmt, "i", $cls);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $students[intval($row['id'])] = ['id' => intval($row['id']), 'name' => $row['name'], 'seat_no' => strval($row['seat_no']),
                                             'group_id' => intval($row['group_id']), 'scores' => []];
        }
        mysqli_stmt_close($stmt);
        // 分组列表（分组统计视图用；未分组 group_id=0 不入列表，前端归「未分组」）
        $groups = [];
        $res = mysqli_query($conn, "SELECT id, name FROM stu_groups WHERE class_id = {$cls} ORDER BY sort ASC, id ASC");
        while ($row = mysqli_fetch_assoc($res)) $groups[] = ['id' => intval($row['id']), 'name' => strval($row['name'])];
        // 题次与各题次得分（score="对/总" → g/t；仅取已批改行）
        $rounds = project_rounds_list($conn, $project_id);
        $titles = project_rounds_titles($conn, $project_id);
        $rlist = [];
        foreach ($rounds as $rn) $rlist[] = ['no' => intval($rn), 'title' => strval($titles[$rn] ?? '')];
        $res = mysqli_query($conn, "SELECT o.round_no, o.student_id, o.score FROM omr_results o
                                    INNER JOIN students s ON o.student_id = s.id
                                    WHERE o.project_id = {$project_id} AND s.class_id = {$cls} AND o.score <> ''");
        while ($row = mysqli_fetch_assoc($res)) {
            $sid = intval($row['student_id']);
            if (!isset($students[$sid])) continue;
            $parts = explode('/', strval($row['score']));
            $g = intval($parts[0]); $t = count($parts) > 1 ? intval($parts[1]) : 0;
            if ($t <= 0) continue;
            $students[$sid]['scores'][intval($row['round_no'])] = ['g' => $g, 't' => $t];
        }
        $out = ['success' => true, 'class' => ['id' => intval($crow['id']), 'name' => $crow['name']],
                'rounds' => $rlist, 'groups' => $groups, 'students' => array_values($students), 'total_class' => count($students)];
        // 绑定模板布局读取（scored 标记与 with_sections 题组共用一次）：scored=任一题设分值 → 前端按「分数」口径呈现，否则按「正确题数」
        $bind_lay = null;
        $bind_tid = intval(get_setting($conn, 'omr_tpl_bind_' . $project_id, '0'));
        if ($bind_tid > 0) {
            $stmt = mysqli_prepare($conn, "SELECT layout FROM omr_templates WHERE id = ? AND project_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $bind_tid, $project_id);
            mysqli_stmt_execute($stmt);
            $trow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if ($trow) $bind_lay = json_decode(strval($trow['layout']), true);
        }
        list(, , $tp0) = omr_layout_ctx(is_array($bind_lay) ? $bind_lay : []);
        $out['scored'] = count($tp0) > 0;
        // with_sections=1&round=N：按绑定模板题组聚合该题次每生 {c,n}（判分口径同 omr_calc_score：单选全等、多选子集）——单次题组雷达用
        if (intval($_POST['with_sections'] ?? 0) === 1) {
            $round = intval($_POST['round'] ?? current_round_no($conn, $project));
            $sections = [];
            $ctx = null;
            $lay = $bind_lay;
            if (is_array($lay)) {
                list($kind, $tk, $tp) = omr_layout_ctx($lay);
                // 生效答案键：模板最优先，独立录入仅补未设题（与 omr_answer_ctx 同口径；强制批改按有答案题批改）
                $force = (get_setting($conn, 'omr_force_grade_' . $project_id, '0') === '1');
                $key = $tk;
                $pa = omr_indep_answers($conn, $project_id);
                if (count($pa)) {
                    foreach ($pa as $q => $v) {
                        $qk = preg_replace('/[^0-9]/', '', strval($q));
                        $vv = strtoupper(preg_replace('/[^A-E]/', '', strval($v)));
                        if ($qk !== '' && $vv !== '' && empty($key[$qk])) $key[$qk] = $vv;
                    }
                }
                foreach ((is_array($lay['sections'] ?? null) ? $lay['sections'] : []) as $sec) {
                    if (!is_array($sec)) continue;
                    $k2 = strval($sec['kind'] ?? 'single');
                    if ($k2 === 'blank' || $k2 === 'short') continue;   // 手写题不参与判分
                    $st2 = intval($sec['start'] ?? 1); $ct2 = min(500, max(1, intval($sec['count'] ?? 0)));
                    $sections[] = ['title' => strval($sec['title'] ?? '题组'), 'kind' => $k2,
                                   'start' => $st2, 'count' => $ct2, 'opts' => max(2, min(6, intval($sec['opts'] ?? 4))),
                                   'qs' => range($st2, $st2 + $ct2 - 1)];
                }
                $ctx = ['kind' => $kind, 'key' => $key, 'force' => $force];
            }
            $sec_rates = [];
            if ($ctx && count($sections)) {
                $stmt = mysqli_prepare($conn, "SELECT student_id, answers FROM omr_results WHERE project_id = ? AND round_no = ? AND student_id > 0");
                mysqli_stmt_bind_param($stmt, "ii", $project_id, $round);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                while ($row = mysqli_fetch_assoc($res)) {
                    $sid = intval($row['student_id']);
                    if (!isset($students[$sid])) continue;
                    $ans = json_decode(strval($row['answers']), true);
                    if (!is_array($ans)) $ans = [];
                    $per = [];
                    foreach ($sections as $si => $sec) {
                        $c = 0; $n = 0;
                        foreach ($sec['qs'] as $q) {
                            $qk = strval($q);
                            $k2 = strtoupper(preg_replace('/[^A-Ea-e]/', '', strval($ctx['key'][$qk] ?? '')));
                            if ($k2 === '') continue;   // 无生效答案的题不参与
                            $n++;
                            $sel = strtoupper(preg_replace('/[^A-Ea-e]/', '', strval($ans[$qk] ?? '')));
                            if ($sel === '') continue;
                            $ok = ($sec['kind'] === 'multi') ? (count(array_diff(str_split($sel), str_split($k2))) === 0) : ($sel === $k2);
                            if ($ok) $c++;
                        }
                        if ($n > 0) $per[$si] = ['c' => $c, 'n' => $n];
                    }
                    if (count($per)) $sec_rates[strval($sid)] = $per;
                }
                mysqli_stmt_close($stmt);
            }
            $out['sec_round'] = $round;
            $out['sections'] = array_map(function ($s) { unset($s['qs']); return $s; }, $sections);
            $out['sec_rates'] = $sec_rates;
        }
        json_response($out);
    }

    if ($type === 'omr_results') {
        $project_id = intval($_POST['project_id'] ?? 0);
        list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
        if (!$project) json_response(['success' => false, 'message' => '项目不存在或无权限']);
        $res = mysqli_query($conn, "SELECT o.id, o.round_no, o.ident, o.template_id, o.student_id, o.answers, o.score, o.flags, o.updated_at,
                                           s.name AS student_name, s.seat_no, s.student_no, c.name AS class_name
                                    FROM omr_results o
                                    LEFT JOIN students s ON o.student_id = s.id
                                    LEFT JOIN classes c ON s.class_id = c.id
                                    WHERE o.project_id = {$project_id}
                                    ORDER BY o.round_no DESC, o.updated_at DESC, o.id DESC LIMIT 1000");
        $items = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $items[] = [
                'id' => intval($row['id']), 'round_no' => intval($row['round_no']), 'ident' => $row['ident'], 'template_id' => intval($row['template_id']),
                'student_id' => $row['student_id'] === null ? 0 : intval($row['student_id']),
                'student_name' => strval($row['student_name']), 'seat_no' => strval($row['seat_no']), 'student_no' => strval($row['student_no']),
                'class_name' => strval($row['class_name']), 'answers' => strval($row['answers']),
                'score' => strval($row['score']), 'flags' => strval($row['flags']), 'updated_at' => strval($row['updated_at']),
            ];
        }
        json_response(['success' => true, 'items' => $items]);
    }

    if ($type === 'omr_result_del' || $type === 'omr_bind' || $type === 'omr_edit' || $type === 'omr_manual_save') {
        $project_id = intval($_POST['project_id'] ?? 0);
        list($project, $class_ids) = get_operate_project($conn, $teacher_id, $project_id);
        if (!$project) json_response(['success' => false, 'message' => '项目不存在或无权限']);

        // ===== 录入答案（无识别记录学生手工录入每题选择，参与判分与统计；口径同识别登记） =====
        if ($type === 'omr_manual_save') {
            if (($project['mode'] ?? '') !== 'omr') json_response(['success' => false, 'message' => '非答题卡模式项目']);
            $sid = intval($_POST['student_id'] ?? 0);
            $stmt = mysqli_prepare($conn, "SELECT s.id, s.name, s.disabled FROM students s
                                           WHERE s.id = ? AND s.class_id IN (" . class_ids_in($class_ids) . ")");
            mysqli_stmt_bind_param($stmt, "i", $sid);
            mysqli_stmt_execute($stmt);
            $stu = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$stu) json_response(['success' => false, 'message' => '学生不在项目覆盖班级内']);
            if (!empty($stu['disabled'])) json_response(['success' => false, 'message' => '该学生已禁用']);
            ensure_omr_round($conn);
            $rounds = project_rounds_list($conn, $project_id);
            $round = intval($_POST['round'] ?? 0);
            if (!in_array($round, $rounds, true)) $round = $rounds[count($rounds) - 1] ?? 1;
            $answers_raw = json_decode(strval($_POST['answers'] ?? ''), true);
            $answers = [];
            if (is_array($answers_raw)) {
                foreach ($answers_raw as $q => $v) {
                    $qk = preg_replace('/[^0-9A-Za-z_]/', '', strval($q));
                    $vv = strtoupper(preg_replace('/[^A-Ea-e]/', '', strval($v)));
                    if ($qk !== '' && $vv !== '') $answers[$qk] = $vv;
                }
            }
            // 生效答案批改用项目绑定模板（识别行缺模板时同样补挂绑定模板）
            $bind_tid = intval(get_setting($conn, 'omr_tpl_bind_' . $project_id, '0'));
            $lay = null;
            if ($bind_tid > 0) {
                $stmt = mysqli_prepare($conn, "SELECT layout FROM omr_templates WHERE id = ? AND project_id = ?");
                mysqli_stmt_bind_param($stmt, "ii", $bind_tid, $project_id);
                mysqli_stmt_execute($stmt);
                $trow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);
                if ($trow) $lay = json_decode(strval($trow['layout']), true);
            }
            $score = is_array($lay) ? omr_grade_flat(omr_answer_ctx($conn, $project_id, $lay), $answers) : '';
            $eval_num = omr_eval_num($score);
            $ident = 'manual:' . $sid;
            $answers_json = json_encode($answers, JSON_UNESCAPED_UNICODE);
            // 同生同题次已有识别行（任意 ident）→ 覆盖该行；否则新建（ident=manual:学生id 唯一键防重）
            $stmt = mysqli_prepare($conn, "SELECT id FROM omr_results WHERE project_id = ? AND round_no = ? AND student_id = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt, "iii", $project_id, $round, $sid);
            mysqli_stmt_execute($stmt);
            $ex = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if ($ex) {
                $stmt = mysqli_prepare($conn, "UPDATE omr_results SET template_id = ?, answers = ?, score = ?, flags = '', updated_at = NOW() WHERE id = ?");
                mysqli_stmt_bind_param($stmt, "issi", $bind_tid, $answers_json, $score, intval($ex['id']));
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $rid = intval($ex['id']);
            } else {
                $stmt = mysqli_prepare($conn, "INSERT INTO omr_results (project_id, round_no, template_id, student_id, ident, answers, score, flags, pages, created_at, updated_at)
                                               VALUES (?, ?, ?, ?, ?, ?, ?, '', '1', NOW(), NOW())");
                mysqli_stmt_bind_param($stmt, "iiiisss", $project_id, $round, $bind_tid, $sid, $ident, $answers_json, $score);
                mysqli_stmt_execute($stmt);
                $rid = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
            }
            // 题次维度登记 + 评价（同 omr_edit：有得分同步评价值；无得分仅登记）
            $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO record_rounds (project_id, student_id, round_no, registered_at, registered_by, created_at)
                                           VALUES (?, ?, ?, NOW(), 'manual', NOW())");
            mysqli_stmt_bind_param($stmt, "iii", $project_id, $sid, $round);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            if ($eval_num !== '') {
                $stmt = mysqli_prepare($conn, "UPDATE record_rounds SET eval_value = ?, eval_at = NOW(), eval_cleared_at = NULL
                                               WHERE project_id = ? AND student_id = ? AND round_no = ?");
                mysqli_stmt_bind_param($stmt, "siii", $eval_num, $project_id, $sid, $round);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            $stmt = mysqli_prepare($conn, "INSERT INTO records (project_id, student_id, registered, registered_at, registered_by, eval_value, eval_at)
                                           VALUES (?, ?, 1, NOW(), 'manual', NULL, NULL)
                                           ON DUPLICATE KEY UPDATE registered = 1, registered_at = IF(registered = 1, registered_at, NOW())");
            mysqli_stmt_bind_param($stmt, "ii", $project_id, $sid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            if ($eval_num !== '') {
                $stmt = mysqli_prepare($conn, "UPDATE records SET eval_value = ?, eval_at = NOW(), eval_cleared_at = NULL WHERE project_id = ? AND student_id = ?");
                mysqli_stmt_bind_param($stmt, "sii", $eval_num, $project_id, $sid);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            json_response(['success' => true, 'id' => $rid, 'round' => $round, 'score' => $score, 'message' => '已录入' . ($score !== '' ? '，得分 ' . $score : '')]);
        }

        $rid = intval($_POST['id'] ?? 0);
        $stmt = mysqli_prepare($conn, "SELECT o.*, s.name AS student_name FROM omr_results o
                                       LEFT JOIN students s ON o.student_id = s.id
                                       WHERE o.id = ? AND o.project_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $rid, $project_id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$row) json_response(['success' => false, 'message' => '结果不存在']);

        if ($type === 'omr_result_del') {
            $stmt = mysqli_prepare($conn, "DELETE FROM omr_results WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $rid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            json_response(['success' => true, 'message' => '已删除该条识别结果']);
        }

        if ($type === 'omr_bind') {
            $sid = intval($_POST['student_id'] ?? 0);
            $stmt = mysqli_prepare($conn, "SELECT s.id, s.name, s.disabled, c.name AS class_name FROM students s INNER JOIN classes c ON s.class_id = c.id
                                           WHERE s.id = ? AND s.class_id IN (" . class_ids_in($class_ids) . ")");
            mysqli_stmt_bind_param($stmt, "i", $sid);
            mysqli_stmt_execute($stmt);
            $stu = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$stu) json_response(['success' => false, 'message' => '学生不在项目覆盖班级内']);
            if (!empty($stu['disabled'])) json_response(['success' => false, 'message' => '该学生已禁用']);
            // 防重复绑定：同题次内同一学生已有其他结果行则合并拒绝（提示先删除旧行；不同题次各留一条）
            $oround = intval($row['round_no'] ?? 1);
            $stmt = mysqli_prepare($conn, "SELECT id FROM omr_results WHERE project_id = ? AND round_no = ? AND student_id = ? AND id <> ?");
            mysqli_stmt_bind_param($stmt, "iiii", $project_id, $oround, $sid, $rid);
            mysqli_stmt_execute($stmt);
            $dup = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
            mysqli_stmt_close($stmt);
            if ($dup) json_response(['success' => false, 'message' => $stu['class_name'] . ' ' . $stu['name'] . ' 本题次已有识别结果，请先删除旧行再绑定']);
            $stmt = mysqli_prepare($conn, "UPDATE omr_results SET student_id = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $sid, $rid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            // 绑定后按该题次补登记并同步数值得分
            $score = strval($row['score']);
            $eval_num = omr_eval_num($score);
            $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO record_rounds (project_id, student_id, round_no, registered_at, registered_by, created_at)
                                           VALUES (?, ?, ?, NOW(), 'scan', NOW())");
            mysqli_stmt_bind_param($stmt, "iii", $project_id, $sid, $oround);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            if ($eval_num !== '') {
                $stmt = mysqli_prepare($conn, "UPDATE record_rounds SET eval_value = ?, eval_at = NOW(), eval_cleared_at = NULL
                                               WHERE project_id = ? AND student_id = ? AND round_no = ?");
                mysqli_stmt_bind_param($stmt, "siii", $eval_num, $project_id, $sid, $oround);
            } else {
                $stmt = mysqli_prepare($conn, "UPDATE records SET registered = 1 WHERE project_id = ? AND student_id = ?");
                mysqli_stmt_bind_param($stmt, "ii", $project_id, $sid);
            }
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            json_response(['success' => true, 'message' => '已绑定：' . $stu['class_name'] . ' ' . $stu['name']]);
        }

        // omr_edit：手工修正答案（服务端按模板答案键重算得分并同步评价值；识别行未挂模板时回退项目绑定模板）
        $answers_raw = json_decode(strval($_POST['answers'] ?? ''), true);
        $answers = [];
        if (is_array($answers_raw)) {
            foreach ($answers_raw as $q => $v) {
                $qk = preg_replace('/[^0-9A-Za-z_]/', '', strval($q));
                $vv = strtoupper(preg_replace('/[^A-Ea-e]/', '', strval($v)));
                if ($qk !== '' && $vv !== '') $answers[$qk] = $vv;
            }
        }
        $score = '';
        $edit_tid = intval($row['template_id']);
        if ($edit_tid <= 0) $edit_tid = intval(get_setting($conn, 'omr_tpl_bind_' . $project_id, '0'));   // 回退项目绑定模板
        $trow = null;
        if ($edit_tid > 0) {
            $stmt = mysqli_prepare($conn, "SELECT layout FROM omr_templates WHERE id = ? AND project_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $edit_tid, $project_id);
            mysqli_stmt_execute($stmt);
            $trow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$trow) {   // 模板已删除/无效（template_id>0 但查不到）：同样回退项目绑定模板
                $edit_tid = intval(get_setting($conn, 'omr_tpl_bind_' . $project_id, '0'));
                if ($edit_tid > 0) {
                    $stmt = mysqli_prepare($conn, "SELECT layout FROM omr_templates WHERE id = ? AND project_id = ?");
                    mysqli_stmt_bind_param($stmt, "ii", $edit_tid, $project_id);
                    mysqli_stmt_execute($stmt);
                    $trow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                    mysqli_stmt_close($stmt);
                }
            }
        }
        if ($trow) $score = omr_grade_flat(omr_answer_ctx($conn, $project_id, json_decode(strval($trow['layout']), true)), $answers);
        $answers_json = json_encode($answers, JSON_UNESCAPED_UNICODE);
        $stmt = mysqli_prepare($conn, "UPDATE omr_results SET answers = ?, score = ?, flags = '', template_id = ?, updated_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "ssii", $answers_json, $score, $edit_tid, $rid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        if (intval($row['student_id']) > 0 && $score !== '') {
            $sid = intval($row['student_id']);
            $eround = intval($row['round_no'] ?? 1);
            $eval_num = omr_eval_num($score);
            $stmt = mysqli_prepare($conn, "UPDATE record_rounds SET eval_value = ?, eval_at = NOW(), eval_cleared_at = NULL
                                           WHERE project_id = ? AND student_id = ? AND round_no = ?");
            mysqli_stmt_bind_param($stmt, "siii", $eval_num, $project_id, $sid, $eround);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            // records 汇总口径同步（与 omr_submit 一致：评价值=数值得分，重扫/修正覆盖为最新）
            $stmt = mysqli_prepare($conn, "INSERT INTO records (project_id, student_id, registered, registered_at, registered_by, eval_value, eval_at)
                                           VALUES (?, ?, 1, NOW(), 'scan', ?, NOW())
                                           ON DUPLICATE KEY UPDATE registered = 1, registered_at = IF(registered = 1, registered_at, NOW()),
                                               eval_value = VALUES(eval_value), eval_at = NOW(), eval_cleared_at = NULL");
            mysqli_stmt_bind_param($stmt, "iis", $project_id, $sid, $eval_num);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        json_response(['success' => true, 'score' => $score, 'message' => '答案已修正']);
    }
}

json_response(['success' => false, 'message' => '未知操作']);

// ===== 喊话辅助：客户端设备在线汇总（心跳 10 秒内视为在线；返回 {pc:n, phone:m}） =====
function ann_devices_summary($conn, $class_id) {
    $class_id = intval($class_id);
    $res = mysqli_query($conn, "SELECT device_type, COUNT(*) AS c FROM announce_devices
                                WHERE class_id = {$class_id} AND last_seen >= DATE_SUB(NOW(), INTERVAL 10 SECOND)
                                GROUP BY device_type");
    $pc = 0; $phone = 0;
    while ($row = mysqli_fetch_assoc($res)) {
        if ($row['device_type'] === 'phone') $phone = intval($row['c']);
        else $pc = intval($row['c']);
    }
    // 语音播报不支持的在线设备数（客户端 TTS 检测后心跳上报）
    $res2 = mysqli_query($conn, "SELECT COUNT(*) AS c FROM announce_devices
                                 WHERE class_id = {$class_id} AND voice_ok = 0 AND last_seen >= DATE_SUB(NOW(), INTERVAL 10 SECOND)");
    $novoice = intval(mysqli_fetch_assoc($res2)['c']);
    return ['pc' => $pc, 'phone' => $phone, 'novoice' => $novoice];
}

// ===== 统计辅助 =====
function total_students_in($conn, $class_ids) {
    if (!$class_ids) return 0;
    // 已禁用学生不参与登记，不计入分母（总数=应登记/应答人数）
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM students WHERE disabled = 0 AND class_id IN (" . implode(',', array_map('intval', $class_ids)) . ")");
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return intval($row['c']);
}
function registered_count($conn, $project_id) {
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM records WHERE project_id = ? AND registered = 1");
    mysqli_stmt_bind_param($stmt, "i", $project_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return intval($row['c']);
}
function evaluated_count($conn, $project_id) {
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM records WHERE project_id = ? AND eval_value IS NOT NULL AND eval_value != ''");
    mysqli_stmt_bind_param($stmt, "i", $project_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return intval($row['c']);
}
?>
