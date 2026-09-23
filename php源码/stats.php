<?php
/**
 * 登记统计中心（三视图）
 *  - 班级总览 stats.php?class_id=X：一次性 / 打卡项目分组表格（每项目可进「单项统计」、操作列直接导出详细数据），📊 统计图表弹窗（各项目登记率、评价分布）
 *  - 单项目统计 stats.php?class_id=X&project_id=Y：打卡项目每日打卡情况（每次）+ 总体情况；学生明细表；本项目全部导出入口（详细数据 / 每日打卡明细）
 *  - 学生统计 stats.php?class_id=X&student_id=S：该生各项目登记/评价/打卡天数明细
 * 权限：can_view_class 校验班级可见（无权限跳统计报表）；「查看详情」按 can_view_project 显隐；导出需可操作该项目（get_project_for 'operate'）
 */
require_once 'includes/db_config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/layout.php';

$conn = getConnection();

$class_id = intval($_GET['class_id'] ?? 0);
$project_id = intval($_GET['project_id'] ?? 0);
$student_id = intval($_GET['student_id'] ?? 0);

// 仅带 project_id（导航「项目统计」入口）：按项目推导班级（多班级/年段/全校项目取第一个班级）
if ($class_id <= 0 && $project_id > 0) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM projects WHERE id = ? AND deleted_at IS NULL");
    mysqli_stmt_bind_param($stmt, "i", $project_id);
    mysqli_stmt_execute($stmt);
    $nav_proj = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($nav_proj) {
        $nav_pcls = get_project_class_ids($conn, $nav_proj);
        if ($nav_pcls) $class_id = intval($nav_pcls[0]);
    }
}

if (!can_view_class($conn, $current_teacher_id, $class_id)) {
    header("Location: export.php");
    exit();
}
$stmt = mysqli_prepare($conn, "SELECT id, name FROM classes WHERE id = ? AND deleted_at IS NULL");
mysqli_stmt_bind_param($stmt, "i", $class_id);
mysqli_stmt_execute($stmt);
$class = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$class) {
    header("Location: export.php");
    exit();
}

// 学生总数
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM students WHERE class_id = ?");
mysqli_stmt_bind_param($stmt, "i", $class_id);
mysqli_stmt_execute($stmt);
$total_students = intval(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c']);
mysqli_stmt_close($stmt);

// 班级可见项目（含评价模式名、登记/评价计数）
$projects = [];
$stmt = mysqli_prepare($conn, "SELECT p.*, e.eval_name,
        COALESCE(r.registered_count, 0) AS registered_count,
        COALESCE(r.evaluated_count, 0) AS evaluated_count
        FROM projects p
        LEFT JOIN (
            SELECT 'smile' AS eval_key, '笑脸' AS eval_name UNION ALL
            SELECT 'score', '数值' UNION ALL
            SELECT 'grade', '优良' UNION ALL
            SELECT 'star', '星级' UNION ALL
            SELECT 'comment', '评语' UNION ALL
            SELECT 'points', '十分'
        ) e ON e.eval_key = p.eval_mode
        LEFT JOIN (
            SELECT project_id,
                   SUM(registered = 1) AS registered_count,
                   SUM(eval_value IS NOT NULL AND eval_value != '') AS evaluated_count
            FROM records GROUP BY project_id
        ) r ON r.project_id = p.id
        WHERE p.class_id = ? AND p.deleted_at IS NULL ORDER BY p.id DESC");
mysqli_stmt_bind_param($stmt, "i", $class_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) $projects[] = $row;
mysqli_stmt_close($stmt);

// 打卡项目汇总（打卡天数 / 累计打卡人次 / 打卡人数）
$daily_agg = [];
$pids_all = array_map('intval', array_column($projects, 'id'));
if ($pids_all) {
    $res = mysqli_query($conn, "SELECT project_id, COUNT(DISTINCT reg_date) AS days, COUNT(*) AS times, COUNT(DISTINCT student_id) AS studs
                                FROM record_days WHERE project_id IN (" . implode(',', $pids_all) . ") GROUP BY project_id");
    while ($row = mysqli_fetch_assoc($res)) $daily_agg[intval($row['project_id'])] = $row;
}

// 答题卡项目（mode=omr，含年段/全校范围覆盖本班的项目）：题次数 / 识别人次 / 参与人数
$omr_projects = [];
$omr_proj_agg = [];
$res = mysqli_query($conn, "SELECT * FROM projects WHERE mode = 'omr' AND deleted_at IS NULL ORDER BY id DESC");
while ($row = mysqli_fetch_assoc($res)) {
    if (in_array($class_id, get_project_class_ids($conn, $row), true)) $omr_projects[] = $row;
}
if ($omr_projects) {
    $opids = implode(',', array_map('intval', array_column($omr_projects, 'id')));
    $res = mysqli_query($conn, "SELECT project_id, COUNT(*) AS times, COUNT(DISTINCT student_id) AS studs
                                FROM omr_results WHERE project_id IN ({$opids}) GROUP BY project_id");
    while ($row = mysqli_fetch_assoc($res)) $omr_proj_agg[intval($row['project_id'])] = ['times' => intval($row['times']), 'studs' => intval($row['studs']), 'rounds' => 0];
    $res = mysqli_query($conn, "SELECT project_id, COUNT(*) AS c FROM project_rounds WHERE project_id IN ({$opids}) GROUP BY project_id");
    while ($row = mysqli_fetch_assoc($res)) {
        $pid = intval($row['project_id']);
        if (!isset($omr_proj_agg[$pid])) $omr_proj_agg[$pid] = ['times' => 0, 'studs' => 0, 'rounds' => 0];
        $omr_proj_agg[$pid]['rounds'] = intval($row['c']);
    }
}

// 视图路由
$view = 'class';
if ($student_id > 0) $view = 'student';
elseif ($project_id > 0) $view = 'project';

// ===== 图表数据构建（JSON 注入前端） =====
$chart_sets = [];   // 数据集 key => 标签（顺序即下拉顺序）
$chart_data = [];   // key => {labels, values}
$chart_eval = [];   // 评价分布二级项目选择（仅班级总览使用）
$scope_names = get_scope_names();

if ($view === 'class') {
    // 各项目登记率（一次性项目） / 打卡人数（打卡项目）
    $chart_sets['rate'] = '各项目登记率（%）';
    $labels = []; $values = [];
    foreach ($projects as $p) {
        $labels[] = $p['name'];
        if (($p['mode'] ?? 'count') === 'daily') {
            $studs = intval($daily_agg[intval($p['id'])]['studs'] ?? 0);
            $values[] = $total_students > 0 ? round($studs / $total_students * 100, 1) : 0;
        } else {
            $values[] = $total_students > 0 ? round(intval($p['registered_count']) / $total_students * 100, 1) : 0;
        }
    }
    $chart_data['rate'] = ['labels' => $labels, 'values' => $values];
    $chart_sets['eval'] = '评价分布（人次）';   // eval 走二级项目选择
    // 评价分布（班级内各评价内容出现次数）
    if ($total_students > 0) {
        $stmt = mysqli_prepare($conn, "SELECT p.id, p.name AS project_name, r.eval_value, COUNT(*) AS cnt
                                       FROM records r
                                       INNER JOIN projects p ON r.project_id = p.id
                                       WHERE p.class_id = ? AND p.deleted_at IS NULL AND r.eval_value IS NOT NULL AND r.eval_value != ''
                                       GROUP BY p.id, r.eval_value ORDER BY p.id DESC, cnt DESC");
        mysqli_stmt_bind_param($stmt, "i", $class_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $chart_eval[intval($row['id'])]['name'] = $row['project_name'];
            $chart_eval[intval($row['id'])]['items'][] = ['v' => $row['eval_value'], 'cnt' => intval($row['cnt'])];
        }
        mysqli_stmt_close($stmt);
    }
}

// ===== 单项目统计视图数据 =====
$project = null;
if ($view === 'project') {
    foreach ($projects as $p) {
        if (intval($p['id']) === $project_id) { $project = $p; break; }
    }
    // 项目不存在 / 不可见 / 不覆盖本班 → 回总览
    if (!$project || !can_view_project($conn, $current_teacher_id, $project)
        || !in_array($class_id, get_project_class_ids($conn, $project), true)) {
        header("Location: stats.php?class_id={$class_id}");
        exit();
    }
    $is_daily = (($project['mode'] ?? 'count') === 'daily');
    $is_omr = (($project['mode'] ?? 'count') === 'omr');
    $is_quiz = in_array(($project['mode'] ?? ''), ['quiz', 'raise'], true);   // 举牌/答题模式（ABCD 四选一）

    // ===== 答题卡模式统计（多次汇总 / 班级单次对比 / 学生个人成绩 / 逐题分析），口径参考学情分析看板 =====
    // 得分率=Σ得分÷Σ满分；优秀率=个体得分率≥80%、及格率=≥60%、低分率=<30%；超均率=(单位得分率−全体得分率)÷全体得分率
    $omr = null;
    if ($is_omr) {
        if (!function_exists('omr_stat_num')) {
            function omr_stat_num($v) {
                $s = rtrim(rtrim(number_format(floatval($v), 1, '.', ''), '0'), '.');
                return ($s === '' || $s === '-0') ? '0' : $s;
            }
        }
        // —— 题次与标题 ——
        $omr_rounds = [];
        $rtitles = project_rounds_titles($conn, $project_id);
        foreach (project_rounds_list($conn, $project_id) as $rn) {
            $omr_rounds[] = ['no' => intval($rn), 'title' => strval($rtitles[$rn] ?? '')];
        }
        $omr_round_nos = array_map(function ($r) { return $r['no']; }, $omr_rounds);
        // —— 项目覆盖班级与学生（全校/年段项目跨班汇总） ——
        $omr_classes = []; $omr_students = [];
        $omr_cls_ids = get_project_class_ids($conn, $project);
        if (!in_array($class_id, $omr_cls_ids, true)) $omr_cls_ids[] = $class_id;
        $omr_cls_ids = array_values(array_unique(array_map('intval', $omr_cls_ids)));
        if ($omr_cls_ids) {
            $idlist = implode(',', $omr_cls_ids);
            $res = mysqli_query($conn, "SELECT id, name FROM classes WHERE id IN ({$idlist}) AND deleted_at IS NULL");
            while ($row = mysqli_fetch_assoc($res)) $omr_classes[intval($row['id'])] = strval($row['name']);
            $res = mysqli_query($conn, "SELECT s.id, s.name, s.seat_no, s.class_id FROM students s
                                        WHERE s.class_id IN ({$idlist}) AND s.disabled = 0
                                        ORDER BY s.class_id ASC, CAST(s.seat_no AS UNSIGNED) ASC, s.id ASC");
            while ($row = mysqli_fetch_assoc($res)) {
                $cid = intval($row['class_id']);
                $omr_students[intval($row['id'])] = ['id' => intval($row['id']), 'name' => strval($row['name']),
                                                     'seat_no' => strval($row['seat_no']), 'class_id' => $cid,
                                                     'class_name' => $omr_classes[$cid] ?? ('班级#' . $cid)];
            }
        }
        // —— 识别结果（每生每题次最新一条：answers JSON + score "g/t"） ——
        $omr_rows = [];   // [round_no][student_id] = ['answers'=>[], 'g'=>float, 't'=>float]
        $res = mysqli_query($conn, "SELECT o.round_no, o.student_id, o.answers, o.score FROM omr_results o
                                    WHERE o.project_id = {$project_id} AND o.student_id IS NOT NULL");
        while ($row = mysqli_fetch_assoc($res)) {
            $sid = intval($row['student_id']);
            if (!isset($omr_students[$sid])) continue;
            $ans = json_decode(strval($row['answers']), true);
            if (!is_array($ans)) $ans = [];
            $g = 0.0; $t = 0.0;
            $parts = explode('/', strval($row['score']));
            if (count($parts) === 2 && floatval($parts[1]) > 0) { $g = floatval($parts[0]); $t = floatval($parts[1]); }
            $omr_rows[intval($row['round_no'])][$sid] = ['answers' => $ans, 'g' => $g, 't' => $t];
        }
        // —— 生效答案上下文（与识别端同口径）：绑定模板题组 + 模板答案键，独立录入答案补未设题 ——
        $omr_secs = []; $omr_kind = []; $omr_key = [];
        $bind_tid = intval(get_setting($conn, 'omr_tpl_bind_' . $project_id, '0'));
        if ($bind_tid > 0) {
            $stmt = mysqli_prepare($conn, "SELECT layout FROM omr_templates WHERE id = ? AND project_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $bind_tid, $project_id);
            mysqli_stmt_execute($stmt);
            $trow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            $lay = $trow ? json_decode(strval($trow['layout']), true) : null;
            foreach ((is_array($lay) && is_array($lay['sections'] ?? null) ? $lay['sections'] : []) as $sec) {
                if (!is_array($sec)) continue;
                $k = strval($sec['kind'] ?? 'single');
                if ($k === 'blank' || $k === 'short') continue;   // 填空/简答无答案键不参与判分统计
                $start = intval($sec['start'] ?? 1); $count = min(500, max(1, intval($sec['count'] ?? 0)));
                $omr_secs[] = ['title' => strval($sec['title'] ?? ''), 'start' => $start, 'count' => $count,
                               'kind' => ($k === 'multi' ? 'multi' : 'single'),
                               'opts' => max(2, min(6, intval($sec['opts'] ?? 4) ?: 4))];
                $key = is_array($sec['key'] ?? null) ? $sec['key'] : [];
                for ($q = $start; $q < $start + $count; $q++) {
                    $qk = strval($q);
                    $omr_kind[$qk] = ($k === 'multi') ? 'multi' : 'single';
                    $v = strtoupper(preg_replace('/[^A-Ea-e]/', '', strval($key[$qk] ?? '')));
                    if ($v !== '') $omr_key[$qk] = $v;
                }
            }
        }
        $ares = @mysqli_query($conn, "SELECT answers FROM omr_answers WHERE project_id = {$project_id}");
        if ($ares && ($arow = mysqli_fetch_assoc($ares))) {
            $saved_tid = get_setting($conn, 'omr_answers_tid_' . $project_id, '');
            if ($saved_tid === '' || intval($saved_tid) === $bind_tid) {   // 换绑模板后独立答案失效（与 api.php 同口径）
                $dec = json_decode(strval($arow['answers']), true);
                if (is_array($dec)) {
                    foreach ($dec as $q => $v) {
                        $qk = preg_replace('/[^0-9]/', '', strval($q));
                        $vv = strtoupper(preg_replace('/[^A-E]/', '', strval($v)));
                        if ($qk !== '' && $vv !== '' && empty($omr_key[$qk])) $omr_key[$qk] = $vv;
                    }
                }
            }
        }
        // —— 汇总函数：一组 ['g','t'] → 指标 ——
        $omr_agg = function (array $rows) {
            $n = 0; $sg = 0.0; $st = 0.0; $maxg = null; $ming = null; $ex = 0; $pa = 0; $low = 0; $full = 0; $zero = 0;
            foreach ($rows as $r) {
                if (!is_array($r) || $r['t'] <= 0) continue;
                $n++; $sg += $r['g']; $st += $r['t'];
                $rate = $r['g'] / $r['t'] * 100;
                if ($maxg === null || $r['g'] > $maxg) $maxg = $r['g'];
                if ($ming === null || $r['g'] < $ming) $ming = $r['g'];
                if ($rate >= 80) $ex++;
                if ($rate >= 60) $pa++;
                if ($rate < 30) $low++;
                if ($rate >= 99.95) $full++;
                if ($rate <= 0.05) $zero++;
            }
            if (!$n) return null;
            return ['n' => $n, 'avg' => omr_stat_num($sg / $n), 'rate' => ($st > 0 ? round($sg / $st * 100, 1) : 0),
                    'max' => omr_stat_num($maxg), 'min' => omr_stat_num($ming),
                    'ex' => round($ex / $n * 100, 1), 'pa' => round($pa / $n * 100, 1), 'low' => round($low / $n * 100, 1),
                    'full' => $full, 'zero' => $zero];
        };
        // —— 多次汇总（全项目跨班，每次一行） ——
        $omr_round_stats = [];
        foreach ($omr_rounds as $r) {
            $omr_round_stats[] = ['no' => $r['no'], 'title' => $r['title'], 'agg' => $omr_agg($omr_rows[$r['no']] ?? [])];
        }
        // —— 单次选择（GET round / omr_cls；默认当前题次 + 本班） ——
        $omr_sel_round = intval($_GET['round'] ?? 0);
        if (!in_array($omr_sel_round, $omr_round_nos, true)) {
            $cur = current_round_no($conn, $project);
            $omr_sel_round = in_array($cur, $omr_round_nos, true) ? $cur : ($omr_round_nos ? $omr_round_nos[count($omr_round_nos) - 1] : 0);
        }
        $omr_sel_cls = intval($_GET['omr_cls'] ?? 0);
        if (!in_array($omr_sel_cls, $omr_cls_ids, true) || !isset($omr_classes[$omr_sel_cls])) {
            $omr_sel_cls = in_array($class_id, $omr_cls_ids, true) ? $class_id : intval($omr_cls_ids[0]);
        }
        // —— 班级单次对比（含全体行与超均率） ——
        $omr_sel_rows_all = $omr_sel_round > 0 ? ($omr_rows[$omr_sel_round] ?? []) : [];
        $omr_all_agg = $omr_agg($omr_sel_rows_all);
        $omr_all_rate = $omr_all_agg ? $omr_all_agg['rate'] : 0;
        $omr_cls_stats = [];
        foreach ($omr_cls_ids as $cid) {
            if (!isset($omr_classes[$cid])) continue;
            $rows = [];
            foreach ($omr_sel_rows_all as $sid => $r) if ($omr_students[$sid]['class_id'] == $cid) $rows[] = $r;
            $a = $omr_agg($rows);
            if (!$a) continue;
            $a['name'] = $omr_classes[$cid];
            $a['over'] = ($omr_all_rate > 0) ? round(($a['rate'] - $omr_all_rate) / $omr_all_rate * 100, 1) : null;
            $omr_cls_stats[] = $a;
        }
        // —— 学生个人成绩（各次得分率 + 总体 + 等级 A≥80/B≥70/C≥60/D + 班名 + 超均率） ——
        $omr_stu_rate = []; $omr_cls_avg = []; $omr_cls_rank = [];
        foreach ($omr_students as $sid => $s) {
            $g = 0.0; $t = 0.0;
            foreach ($omr_rounds as $r) {
                $row = $omr_rows[$r['no']][$sid] ?? null;
                if ($row && $row['t'] > 0) { $g += $row['g']; $t += $row['t']; }
            }
            if ($t > 0) $omr_stu_rate[$sid] = round($g / $t * 100, 1);
        }
        foreach ($omr_stu_rate as $sid => $rate) {
            $cid = $omr_students[$sid]['class_id'];
            $omr_cls_avg[$cid][] = $rate;
        }
        foreach ($omr_cls_avg as $cid => $arr) $omr_cls_avg[$cid] = count($arr) ? round(array_sum($arr) / count($arr), 1) : 0;
        {
            $bycls = [];
            foreach ($omr_stu_rate as $sid => $rate) $bycls[$omr_students[$sid]['class_id']][$sid] = $rate;
            foreach ($bycls as $cid => $m) {
                arsort($m);
                $omr_cls_rank[$cid] = [];
                $i = 0; $prevRate = null; $prevRank = 0;
                foreach ($m as $sid => $rate) {
                    $i++;
                    $rk = ($rate === $prevRate && $prevRank > 0) ? $prevRank : $i;   // 同分并列
                    $omr_cls_rank[$cid][$sid] = $rk;
                    $prevRate = $rate; $prevRank = $rk;
                }
            }
        }
        $omr_stu_stats = [];
        foreach ($omr_students as $sid => $s) {
            $rate = $omr_stu_rate[$sid] ?? null;
            if ($rate === null) continue;   // 无任何已批改成绩的学生不进个人表
            $cells = [];
            foreach ($omr_rounds as $r) {
                $row = $omr_rows[$r['no']][$sid] ?? null;
                $cells[] = ($row && $row['t'] > 0) ? ['rate' => round($row['g'] / $row['t'] * 100, 1),
                                                      'score' => omr_stat_num($row['g']) . '/' . omr_stat_num($row['t'])] : null;
            }
            $cavg = $omr_cls_avg[$s['class_id']] ?? 0;
            $omr_stu_stats[] = ['s' => $s, 'cells' => $cells, 'rate' => $rate,
                                'grade' => ($rate >= 80 ? 'A' : ($rate >= 70 ? 'B' : ($rate >= 60 ? 'C' : 'D'))),
                                'rank' => $omr_cls_rank[$s['class_id']][$sid] ?? null,
                                'over' => ($cavg > 0 ? round(($rate - $cavg) / $cavg * 100, 1) : null)];
        }
        // —— 逐题分析（单次·选定班级：题号/题型/答案/答对/正确率/各选项人数分布） ——
        $omr_qs = [];
        if ($omr_sel_round > 0) {
            $roundRows = [];
            foreach ($omr_sel_rows_all as $sid => $r) {
                if ($omr_students[$sid]['class_id'] == $omr_sel_cls) $roundRows[$sid] = $r;
            }
            $nStu = count($roundRows);
            foreach ($omr_secs as $sec) {
                for ($q = $sec['start']; $q < $sec['start'] + $sec['count']; $q++) {
                    $qk = strval($q);
                    $dist = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];
                    $correct = 0; $answered = 0;
                    $key = $omr_key[$qk] ?? '';
                    foreach ($roundRows as $r) {
                        $sel = strtoupper(preg_replace('/[^A-E]/', '', strval($r['answers'][$qk] ?? '')));
                        if ($sel === '') continue;
                        $answered++;
                        foreach (str_split($sel) as $ch) if (isset($dist[$ch])) $dist[$ch]++;
                        if ($key === '') continue;
                        $ok = ($omr_kind[$qk] === 'multi') ? (count(array_diff(str_split($sel), str_split($key))) === 0) : ($sel === $key);
                        if ($ok) $correct++;
                    }
                    $omr_qs[] = ['no' => $q, 'kind' => $omr_kind[$qk] ?? $sec['kind'], 'sec' => $sec['title'],
                                 'key' => $key, 'opts' => $sec['opts'], 'n' => $nStu,
                                 'answered' => $answered, 'correct' => $correct, 'dist' => $dist];
                }
            }
        }
        // —— 图表数据（各次得分率 / 三率 / 单次逐题正确率） ——
        $cl = []; $cr = []; $ce = []; $cp = []; $cw = [];
        foreach ($omr_round_stats as $rs) {
            if (!$rs['agg']) continue;
            $cl[] = '第' . $rs['no'] . '次' . ($rs['title'] !== '' ? '·' . $rs['title'] : '');
            $cr[] = $rs['agg']['rate']; $ce[] = $rs['agg']['ex']; $cp[] = $rs['agg']['pa']; $cw[] = $rs['agg']['low'];
        }
        $chart_sets['orate'] = '各次平均得分率（%）';
        $chart_data['orate'] = ['labels' => $cl, 'values' => $cr];
        $chart_sets['oex'] = '各次优秀率（≥80%，%）';
        $chart_data['oex'] = ['labels' => $cl, 'values' => $ce];
        $chart_sets['opass'] = '各次及格率（≥60%，%）';
        $chart_data['opass'] = ['labels' => $cl, 'values' => $cp];
        $chart_sets['olow'] = '各次低分率（<30%，%）';
        $chart_data['olow'] = ['labels' => $cl, 'values' => $cw];
        $chart_sets['oq'] = '单次逐题正确率（%）';
        $chart_data['oq'] = ['labels' => array_map(function ($q) { return strval($q['no']); }, $omr_qs),
                             'values' => array_map(function ($q) { return $q['n'] > 0 ? round($q['correct'] / $q['n'] * 100, 1) : 0; }, $omr_qs)];
        // 汇总卡：参与人数（有任一次已批改成绩）/ 识别人次 / 全项目平均得分率
        $omr_times = 0;
        foreach ($omr_rows as $m) $omr_times += count($m);
        $omr_parts = count($omr_stu_rate);
        $omr_all_rows = [];
        foreach ($omr_rows as $m) foreach ($m as $r) $omr_all_rows[] = $r;
        $omr_total_agg = $omr_agg($omr_all_rows);
    } elseif (in_array(($project['mode'] ?? ''), ['quiz', 'raise'], true)) {
        // ===== 举牌/答题模式统计（ABCD）：各次选项分布 / 班级对比 / 学生个人，正确率=答对÷答题（正确答案存 project_rounds.correct_opts） =====
        $is_quiz = true;
        $qz_num = function ($v) {
            $s = rtrim(rtrim(number_format(floatval($v), 1, '.', ''), '0'), '.');
            return ($s === '' || $s === '-0') ? '0' : $s;
        };
        // —— 题次 ——
        $qz_rounds = [];
        $rtitles = project_rounds_titles($conn, $project_id);
        foreach (project_rounds_list($conn, $project_id) as $rn) {
            $qz_rounds[] = ['no' => intval($rn), 'title' => strval($rtitles[$rn] ?? '')];
        }
        // —— 项目覆盖班级与学生（跨班项目按全部覆盖班级汇总） ——
        $qz_classes = []; $qz_students = [];
        $qz_cls_ids = get_project_class_ids($conn, $project);
        if (!in_array($class_id, $qz_cls_ids, true)) $qz_cls_ids[] = $class_id;
        $qz_cls_ids = array_values(array_unique(array_map('intval', $qz_cls_ids)));
        if ($qz_cls_ids) {
            $idlist = implode(',', $qz_cls_ids);
            $res = mysqli_query($conn, "SELECT id, name FROM classes WHERE id IN ({$idlist}) AND deleted_at IS NULL");
            while ($row = mysqli_fetch_assoc($res)) $qz_classes[intval($row['id'])] = strval($row['name']);
            $res = mysqli_query($conn, "SELECT s.id, s.name, s.seat_no, s.student_no, s.class_id FROM students s
                                        WHERE s.class_id IN ({$idlist}) AND s.disabled = 0
                                        ORDER BY s.class_id ASC, CAST(s.seat_no AS UNSIGNED) ASC, s.id ASC");
            while ($row = mysqli_fetch_assoc($res)) {
                $cid = intval($row['class_id']);
                $qz_students[intval($row['id'])] = ['id' => intval($row['id']), 'name' => strval($row['name']),
                    'seat_no' => strval($row['seat_no']), 'student_no' => strval($row['student_no']),
                    'class_id' => $cid, 'class_name' => $qz_classes[$cid] ?? ('班级#' . $cid)];
            }
        }
        // —— 举牌登记（每生每次选项 A~D；record_rounds.eval_value） ——
        $qz_opts = [];   // [round][sid] = 'A'~'D'
        $res = mysqli_query($conn, "SELECT rr.round_no, rr.student_id, COALESCE(rr.eval_value, '') AS opt FROM record_rounds rr
                                    INNER JOIN students s ON rr.student_id = s.id
                                    WHERE rr.project_id = {$project_id} AND rr.registered_at IS NOT NULL AND s.disabled = 0");
        while ($row = mysqli_fetch_assoc($res)) {
            $sid = intval($row['student_id']);
            if (!isset($qz_students[$sid])) continue;
            $opt = strtoupper(trim($row['opt']));
            if (!in_array($opt, ['A', 'B', 'C', 'D'], true)) continue;
            $qz_opts[intval($row['round_no'])][$sid] = $opt;
        }
        // —— 各次正确答案（统计弹窗勾选保存，逗号分隔存 project_rounds.correct_opts） ——
        $qz_correct = [];
        foreach ($qz_rounds as $r) {
            $arr = [];
            $cres = mysqli_query($conn, "SELECT correct_opts FROM project_rounds WHERE project_id = {$project_id} AND round_no = " . intval($r['no']));
            $crow = mysqli_fetch_assoc($cres);
            if ($crow && !empty($crow['correct_opts'])) {
                foreach (explode(',', strtoupper($crow['correct_opts'])) as $co) {
                    $co = trim($co);
                    if (in_array($co, ['A', 'B', 'C', 'D'], true) && !in_array($co, $arr, true)) $arr[] = $co;
                }
                sort($arr);
            }
            $qz_correct[$r['no']] = $arr;
        }
        // —— 各次统计（全体：分布/答题人数/答对/正确率） ——
        $qz_round_stats = [];
        foreach ($qz_rounds as $r) {
            $rn = $r['no'];
            $opts = $qz_opts[$rn] ?? [];
            $dist = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
            foreach ($opts as $opt) $dist[$opt]++;
            $answered = count($opts);
            $crt = 0;
            if ($qz_correct[$rn]) foreach ($opts as $opt) if (in_array($opt, $qz_correct[$rn], true)) $crt++;
            $qz_round_stats[] = ['no' => $rn, 'title' => $r['title'], 'dist' => $dist, 'answered' => $answered,
                                 'crt' => $crt, 'has_key' => (bool)$qz_correct[$rn],
                                 'rate' => ($answered > 0 ? round($crt / $answered * 100, 1) : 0)];
        }
        // —— 班级对比（每次：全体行 + 各班行，超均率=(班正确率−全体正确率)÷全体正确率） ——
        $qz_cls_stats = [];
        foreach ($qz_rounds as $r) {
            $rn = $r['no'];
            $opts = $qz_opts[$rn] ?? [];
            $hasKey = $qz_correct[$rn] ? true : false;
            $allN = count($opts); $allCrt = 0;
            if ($hasKey) foreach ($opts as $opt) if (in_array($opt, $qz_correct[$rn], true)) $allCrt++;
            $allRate = ($allN > 0 ? round($allCrt / $allN * 100, 1) : 0);
            $rows = [];
            foreach ($qz_cls_ids as $cid) {
                if (!isset($qz_classes[$cid])) continue;
                $n = 0; $c = 0;
                foreach ($opts as $sid => $opt) {
                    if ($qz_students[$sid]['class_id'] != $cid) continue;
                    $n++;
                    if ($hasKey && in_array($opt, $qz_correct[$rn], true)) $c++;
                }
                if (!$n) continue;
                $rate = round($c / $n * 100, 1);
                $rows[] = ['name' => $qz_classes[$cid], 'n' => $n, 'crt' => $c, 'rate' => $rate,
                           'over' => ($hasKey && $allRate > 0 ? round(($rate - $allRate) / $allRate * 100, 1) : null)];
            }
            if ($rows) $qz_cls_stats[] = ['no' => $rn, 'title' => $r['title'], 'has_key' => $hasKey,
                                          'all_n' => $allN, 'all_crt' => $allCrt, 'all_rate' => $allRate, 'rows' => $rows];
        }
        // —— 学生个人（各次选项/对错 + 总体正确率 + 班名（同分并列）+ 超均率） ——
        $qz_stu_stats = []; $qz_stu_rate = []; $qz_cls_avg = []; $qz_cls_rank = [];
        foreach ($qz_students as $sid => $s) {
            $cells = []; $ans = 0; $crt = 0;
            foreach ($qz_rounds as $r) {
                $rn = $r['no'];
                $opt = ($qz_opts[$rn] ?? [])[$sid] ?? null;
                $hasKey = (bool)$qz_correct[$rn];
                $ok = ($opt !== null && $hasKey && in_array($opt, $qz_correct[$rn], true)) ? true : (($opt !== null && $hasKey) ? false : null);
                if ($opt !== null) { $ans++; if ($ok) $crt++; }
                $cells[] = ['opt' => $opt, 'ok' => $ok, 'has_key' => $hasKey];
            }
            if (!$ans) continue;
            $rate = round($crt / $ans * 100, 1);
            $qz_stu_rate[$sid] = $rate;
            $qz_stu_stats[$sid] = ['s' => $s, 'cells' => $cells, 'ans' => $ans, 'crt' => $crt, 'rate' => $rate];
        }
        foreach ($qz_stu_rate as $sid => $rate) $qz_cls_avg[$qz_students[$sid]['class_id']][] = $rate;
        foreach ($qz_cls_avg as $cid => $arr) $qz_cls_avg[$cid] = count($arr) ? round(array_sum($arr) / count($arr), 1) : 0;
        {
            $bycls = [];
            foreach ($qz_stu_rate as $sid => $rate) $bycls[$qz_students[$sid]['class_id']][$sid] = $rate;
            foreach ($bycls as $cid => $m) {
                arsort($m);
                $qz_cls_rank[$cid] = [];
                $i = 0; $prevRate = null; $prevRank = 0;
                foreach ($m as $sid => $rate) {
                    $i++;
                    $rk = ($rate === $prevRate && $prevRank > 0) ? $prevRank : $i;   // 同分并列
                    $qz_cls_rank[$cid][$sid] = $rk;
                    $prevRate = $rate; $prevRank = $rk;
                }
            }
        }
        $qz_parts = count($qz_stu_rate);   // 参与人数（有答题记录）
        // —— 汇总卡：Σ答对÷Σ答题 + 已设答案题次数 ——
        $qz_sum_ans = 0; $qz_sum_crt = 0; $qz_keys_set = 0;
        foreach ($qz_round_stats as $rs) {
            $qz_sum_ans += $rs['answered'];
            $qz_sum_crt += $rs['crt'];
            if ($rs['has_key']) $qz_keys_set++;
        }
        $qz_all_rate = $qz_sum_ans > 0 ? round($qz_sum_crt / $qz_sum_ans * 100, 1) : 0;
        // —— 单次选择（GET round / qcls；默认当前题次 + 本班），交互同答题卡「班级单次分析」 ——
        $qz_round_nos = array_map(function ($r) { return $r['no']; }, $qz_rounds);
        $qz_sel_round = intval($_GET['round'] ?? 0);
        if (!in_array($qz_sel_round, $qz_round_nos, true)) {
            $cur = current_round_no($conn, $project);
            $qz_sel_round = in_array($cur, $qz_round_nos, true) ? $cur : ($qz_round_nos ? $qz_round_nos[count($qz_round_nos) - 1] : 0);
        }
        $qz_sel_cls = intval($_GET['qcls'] ?? 0);
        if (!in_array($qz_sel_cls, $qz_cls_ids, true) || !isset($qz_classes[$qz_sel_cls])) {
            $qz_sel_cls = in_array($class_id, $qz_cls_ids, true) ? $class_id : intval($qz_cls_ids[0]);
        }
        // —— 所选题次 × 所选班级的选项分布（单班自动=本班；多班用下拉切换） ——
        $qz_sel_dist = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
        $qz_sel_n = 0; $qz_sel_crt = 0;
        $qz_sel_key = $qz_correct[$qz_sel_round] ?? [];
        foreach (($qz_opts[$qz_sel_round] ?? []) as $sid => $opt) {
            if ($qz_students[$sid]['class_id'] != $qz_sel_cls) continue;
            $qz_sel_dist[$opt]++;
            $qz_sel_n++;
            if ($qz_sel_key && in_array($opt, $qz_sel_key, true)) $qz_sel_crt++;
        }
    }

    // 本班学生 + 本项目状态（含打卡天数 / 最近打卡）
    $stu_rows = [];
    $stmt = mysqli_prepare($conn, "SELECT s.*, r.registered, r.registered_at, r.eval_value,
                COALESCE(rd.days, 0) AS rdays, rd.last_day
                FROM students s
                LEFT JOIN records r ON r.student_id = s.id AND r.project_id = ?
                LEFT JOIN (SELECT student_id, COUNT(*) AS days, MAX(reg_date) AS last_day
                           FROM record_days WHERE project_id = ? GROUP BY student_id) rd ON rd.student_id = s.id
                WHERE s.class_id = ?
                ORDER BY CAST(s.seat_no AS UNSIGNED) ASC, s.id ASC");
    mysqli_stmt_bind_param($stmt, "iii", $project_id, $project_id, $class_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) $stu_rows[] = $row;
    mysqli_stmt_close($stmt);

    // 每日打卡统计（打卡项目）
    $daily_rows = [];
    $dsum = ['days' => 0, 'times' => 0, 'studs' => 0];
    if ($is_daily) {
        $stmt = mysqli_prepare($conn, "SELECT reg_date, COUNT(*) AS c FROM record_days WHERE project_id = ? GROUP BY reg_date ORDER BY reg_date");
        mysqli_stmt_bind_param($stmt, "i", $project_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) $daily_rows[] = ['date' => $row['reg_date'], 'cnt' => intval($row['c'])];
        mysqli_stmt_close($stmt);
        $stmt = mysqli_prepare($conn, "SELECT COUNT(DISTINCT reg_date) AS days, COUNT(*) AS times, COUNT(DISTINCT student_id) AS studs
                                       FROM record_days WHERE project_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $project_id);
        mysqli_stmt_execute($stmt);
        $dsum = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $dsum['days'] = intval($dsum['days']); $dsum['times'] = intval($dsum['times']); $dsum['studs'] = intval($dsum['studs']);

        // 每生打卡天数（图表数据，按天数降序）
        $labels = []; $values = [];
        foreach ($stu_rows as $s) {
            if (intval($s['rdays']) > 0) {
                $labels[] = $s['name'];
                $values[] = intval($s['rdays']);
            }
        }
        array_multisort($values, SORT_DESC, $labels);
        $chart_sets['daily'] = '每日打卡人数（人）';
        $chart_data['daily'] = ['labels' => array_map(function ($r) { return $r['date']; }, $daily_rows),
                                'values' => array_map(function ($r) { return $r['cnt']; }, $daily_rows)];
        $chart_sets['sdays'] = '每生打卡天数（天）';
        $chart_data['sdays'] = ['labels' => $labels, 'values' => $values];
    } else {
        $chart_sets['reg'] = '登记状态分布（人）';
        $regc = 0;
        foreach ($stu_rows as $s) if ($s['registered'] == 1) $regc++;
        $chart_data['reg'] = ['labels' => ['已登记', '未登记'], 'values' => [$regc, count($stu_rows) - $regc]];
    }
    $chart_sets['eval'] = '评价分布（人次）';

    // 本项目评价分布
    $stmt = mysqli_prepare($conn, "SELECT eval_value, COUNT(*) AS c FROM records
                                   WHERE project_id = ? AND eval_value IS NOT NULL AND eval_value != ''
                                   GROUP BY eval_value ORDER BY c DESC");
    mysqli_stmt_bind_param($stmt, "i", $project_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $proj_eval = [];
    while ($row = mysqli_fetch_assoc($res)) $proj_eval[] = ['v' => $row['eval_value'], 'cnt' => intval($row['c'])];
    mysqli_stmt_close($stmt);
    $chart_data['eval'] = ['labels' => array_map(function ($it) { return $it['v']; }, $proj_eval),
                           'values' => array_map(function ($it) { return $it['cnt']; }, $proj_eval)];

    // ===== 登记矩阵（每次×学生，作业登记表样式）：行=每次（打卡=日期），列=本班学生（竖排姓名），格=状态；点姓名列弹该生每次明细 =====
    // count 单次项目只有一次登记无矩阵意义跳过；$MX_JSON 同时供面板渲染与前端弹窗（students[sid].cells 与 rows 同序）
    $MX_JSON = null;
    $pmode = strval($project['mode'] ?? 'count');
    if ($is_daily || in_array($pmode, ['multi', 'quiz', 'raise', 'omr'], true)) {
        $mx_round_label = $is_daily ? '日期' : ($pmode === 'multi' ? '项次' : '题次');
        $mx_rows = [];        // [ ['label'=>行标签, 'sub'=>该次人数说明] ]
        $mx_cells = [];       // [sid][rowIdx] = null(未登记) | ['t'=>格文本, 'c'=>'ok'|'bad'|'mid']
        $mx_info = [];        // [sid][rowIdx] = 明细补充文本（时间/评价/对错说明）
        if ($is_daily) {
            $md = [];
            $res = mysqli_query($conn, "SELECT student_id, reg_date, created_at, eval_value FROM record_days WHERE project_id = {$project_id}");
            while ($row = mysqli_fetch_assoc($res)) {
                $md[strval($row['reg_date'])][intval($row['student_id'])] = ['at' => strval($row['created_at']), 'ev' => strval($row['eval_value'] ?? '')];
            }
            foreach ($daily_rows as $r) {
                $d = strval($r['date']);
                $mx_rows[] = ['label' => $d, 'sub' => $r['cnt'] . ' 人打卡'];
                foreach ($stu_rows as $s) {
                    $sid = intval($s['id']);
                    $hit = isset($md[$d][$sid]);
                    $mx_cells[$sid][] = $hit ? ['t' => '√', 'c' => 'ok'] : null;
                    $mx_info[$sid][] = $hit ? ('打卡时间 ' . $md[$d][$sid]['at'] . ($md[$d][$sid]['ev'] !== '' ? '；评价 ' . $md[$d][$sid]['ev'] : '')) : '';
                }
            }
        } elseif ($pmode === 'multi') {
            $md = [];
            $res = mysqli_query($conn, "SELECT student_id, round_no, registered_at, eval_value FROM record_rounds WHERE project_id = {$project_id} AND registered_at IS NOT NULL");
            while ($row = mysqli_fetch_assoc($res)) {
                $md[intval($row['round_no'])][intval($row['student_id'])] = ['at' => strval($row['registered_at']), 'ev' => strval($row['eval_value'] ?? '')];
            }
            $mrt = project_rounds_titles($conn, $project_id);
            foreach (project_rounds_list($conn, $project_id) as $rn) {
                $rn = intval($rn);
                $n = 0;
                foreach ($stu_rows as $s) if (isset($md[$rn][intval($s['id'])])) $n++;
                $t = strval($mrt[$rn] ?? '');
                $mx_rows[] = ['label' => '第 ' . $rn . ' ' . $mx_round_label . ($t !== '' ? '：' . $t : ''), 'sub' => $n . ' 人登记'];
                foreach ($stu_rows as $s) {
                    $sid = intval($s['id']);
                    $hit = isset($md[$rn][$sid]);
                    $mx_cells[$sid][] = $hit ? ['t' => '√', 'c' => 'ok'] : null;
                    $mx_info[$sid][] = $hit ? ('登记时间 ' . $md[$rn][$sid]['at'] . ($md[$rn][$sid]['ev'] !== '' ? '；评价 ' . $md[$rn][$sid]['ev'] : '')) : '';
                }
            }
        } elseif ($is_quiz) {
            foreach ($qz_rounds as $r) {
                $rn = $r['no'];
                $key = $qz_correct[$rn];
                $n = 0;
                foreach ($stu_rows as $s) if (isset($qz_opts[$rn][intval($s['id'])])) $n++;
                $mx_rows[] = ['label' => '第 ' . $rn . ' ' . $mx_round_label . ($r['title'] !== '' ? '：' . $r['title'] : ''),
                              'sub' => $n . ' 人答题' . ($key ? '（答案 ' . implode('', $key) . '）' : '')];
                foreach ($stu_rows as $s) {
                    $sid = intval($s['id']);
                    $opt = ($qz_opts[$rn] ?? [])[$sid] ?? null;
                    if ($opt === null) { $mx_cells[$sid][] = null; $mx_info[$sid][] = ''; }
                    else {
                        $ok = $key ? (in_array($opt, $key, true) ? 1 : 0) : -1;
                        $mx_cells[$sid][] = ['t' => $opt, 'c' => ($ok === 1 ? 'ok' : ($ok === 0 ? 'bad' : 'mid'))];
                        $mx_info[$sid][] = '选 ' . $opt . ($ok === 1 ? '（答对）' : ($ok === 0 ? '（答错，答案 ' . implode('', $key) . '）' : '（未设答案）'));
                    }
                }
            }
        } elseif ($is_omr) {
            foreach ($omr_rounds as $r) {
                $rn = $r['no'];
                $n = 0;
                foreach ($stu_rows as $s) if (isset($omr_rows[$rn][intval($s['id'])]) && $omr_rows[$rn][intval($s['id'])]['t'] > 0) $n++;
                $mx_rows[] = ['label' => '第 ' . $rn . ' ' . $mx_round_label . ($r['title'] !== '' ? '：' . $r['title'] : ''), 'sub' => $n . ' 人有成绩'];
                foreach ($stu_rows as $s) {
                    $sid = intval($s['id']);
                    $o = $omr_rows[$rn][$sid] ?? null;
                    if (!$o || $o['t'] <= 0) { $mx_cells[$sid][] = null; $mx_info[$sid][] = $o ? '已识别但无批改成绩' : ''; }
                    else {
                        $rate = $o['g'] / $o['t'] * 100;
                        $mx_cells[$sid][] = ['t' => omr_stat_num($o['g']) . '/' . omr_stat_num($o['t']), 'c' => ($rate >= 60 ? 'ok' : 'bad')];
                        $mx_info[$sid][] = '得分 ' . omr_stat_num($o['g']) . ' / 满分 ' . omr_stat_num($o['t']) . '（' . round($rate, 1) . '%）';
                    }
                }
            }
        }
        // 组装 JSON：cells 合计 cnt（完成次数）+ 学生信息（弹窗明细）
        $mx_students = [];
        foreach ($stu_rows as $s) {
            $sid = intval($s['id']);
            $cells = $mx_cells[$sid] ?? [];
            $cnt = 0;
            foreach ($cells as $c) if ($c !== null) $cnt++;
            $mx_students[$sid] = ['name' => strval($s['name']), 'seat' => strval($s['seat_no']), 'no' => strval($s['student_no']),
                                  'cells' => $cells, 'info' => $mx_info[$sid] ?? [], 'cnt' => $cnt];
        }
        $MX_JSON = ['label' => $mx_round_label, 'rows' => $mx_rows, 'students' => $mx_students];
    }

    // 导出权限（可操作该项目者可导出）
    $can_op = get_project_for($conn, $project_id, $current_teacher_id, 'operate') ? true : false;
}

// ===== 学生统计视图数据 =====
$student = null;
if ($view === 'student') {
    $stmt = mysqli_prepare($conn, "SELECT * FROM students WHERE id = ? AND class_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $student_id, $class_id);
    mysqli_stmt_execute($stmt);
    $student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$student) {
        header("Location: stats.php?class_id={$class_id}");
        exit();
    }
    // 各项目明细（登记状态 / 打卡天数 / 评价）
    $per = [];
    $sum = ['reg' => 0, 'eval' => 0, 'days' => 0];
    foreach ($projects as $p) {
        $pid = intval($p['id']);
        // 答题卡项目：登记/评价走 record_rounds+omr_results，此处展示各题次得分汇总
        if (($p['mode'] ?? 'count') === 'omr') {
            $oscores = [];
            $ores = mysqli_query($conn, "SELECT round_no, score FROM omr_results WHERE project_id = {$pid} AND student_id = {$student_id}");
            while ($or = mysqli_fetch_assoc($ores)) $oscores[intval($or['round_no'])] = strval($or['score']);
            $parts = [];
            foreach (project_rounds_list($conn, $pid) as $rn) {
                if (isset($oscores[intval($rn)]) && $oscores[intval($rn)] !== '') $parts[] = '第' . intval($rn) . '次 ' . $oscores[intval($rn)];
            }
            $per[] = ['p' => $p, 'rec' => null, 'days' => 0, 'last' => '', 'is_reg' => false, 'evaled' => false,
                      'omr' => true, 'omr_text' => $parts ? implode('；', $parts) : ''];
            continue;
        }
        $stmt = mysqli_prepare($conn, "SELECT registered, registered_at, eval_value FROM records WHERE project_id = ? AND student_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $pid, $student_id);
        mysqli_stmt_execute($stmt);
        $rec = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $days = 0; $last = '';
        if (($p['mode'] ?? 'count') === 'daily') {
            $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c, MAX(reg_date) AS last_day FROM record_days WHERE project_id = ? AND student_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $pid, $student_id);
            mysqli_stmt_execute($stmt);
            $dr = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            $days = intval($dr['c']); $last = strval($dr['last_day']);
            $sum['days'] += $days;
        }
        $is_reg = $rec && $rec['registered'] == 1;
        if ($is_reg) $sum['reg']++;
        $evaled = $rec && $rec['eval_value'] !== null && $rec['eval_value'] !== '';
        if ($evaled) $sum['eval']++;
        $per[] = ['p' => $p, 'rec' => $rec ?: null, 'days' => $days, 'last' => $last, 'is_reg' => $is_reg, 'evaled' => $evaled];
    }
    // 学生视图图表：各项目打卡天数（打卡项目）+ 登记状态分布
    $labels = []; $values = [];
    foreach ($per as $row) {
        if ($row['days'] > 0) { $labels[] = $row['p']['name']; $values[] = intval($row['days']); }
    }
    $chart_sets['sdays'] = '各项目打卡天数（天）';
    $chart_data['sdays'] = ['labels' => $labels, 'values' => $values];
    $chart_sets['sreg'] = '登记状态分布（项目）';
    $chart_data['sreg'] = ['labels' => ['已登记', '未登记'], 'values' => [$sum['reg'], count($projects) - $sum['reg']]];
}

$page_title = $view === 'project' ? ('单项统计 - ' . $project['name'])
            : ($view === 'student' ? ('学生统计 - ' . $student['name']) : ('登记统计 - ' . $class['name']));
page_header($page_title, 'stats.php');
?>
<?php $embed = intval($_GET['embed'] ?? 0) === 1; // 弹窗内嵌模式：无返回链接，内部跳转保持内嵌 ?>
<?php $nav_only_proj = ($view !== 'class' && intval($_GET['class_id'] ?? 0) <= 0 && $project_id > 0); // 仅带 project_id 进入：导航「项目统计」入口（返回项目情况）；带 class_id 进的单项目统计仍返回班级统计 ?>
<?php if (!$embed): ?>
<a href="<?php echo $view === 'class' ? ('projects.php?class_id=' . $class_id) : ($nav_only_proj ? ('project_view.php?project_id=' . $project_id) : ('stats.php?class_id=' . $class_id)); ?>"
   style="display:inline-block;margin-bottom:15px;color:#667eea;text-decoration:none;">
    <?php echo $view === 'class' ? '← 返回班级情况' : ($nav_only_proj ? '← 返回项目情况' : '← 返回班级统计'); ?></a>
<?php endif; ?>

<?php if ($view === 'class'): ?>
<div class="stat-bar">
    <div class="stat-box"><div class="num"><?php echo $total_students; ?></div><div class="label">全班人数</div></div>
    <div class="stat-box"><div class="num"><?php echo count($projects); ?></div><div class="label">项目总数</div></div>
</div>

<div class="panel">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <h3 style="margin:0;">各项目登记情况</h3>
        <div style="flex:1;"></div>
        <span id="projSelTip" style="font-size:12px;color:#999;">未勾选项目</span>
        <button type="button" class="btn btn-sm btn-outline" onclick="exportProjects()">⬇ 批量导出所选项目</button>
        <button type="button" class="btn btn-sm" onclick="openModal('chartModal');renderChart()">📊 统计图表</button>
    </div>

    <?php
    // 打卡项目 / 一次性项目 分组展示
    $daily_projects = []; $count_projects = [];
    foreach ($projects as $p) {
        if (($p['mode'] ?? 'count') === 'omr') continue;   // 答题卡项目单独分组展示
        if (($p['mode'] ?? 'count') === 'daily') $daily_projects[] = $p;
        else $count_projects[] = $p;
    }
    ?>
    <p style="font-weight:bold;margin:14px 0 6px;color:#667eea;">📅 打卡项目（<?php echo count($daily_projects); ?>）—— 一个项目多次使用，逐次打卡</p>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th><input type="checkbox" onchange="projAll(this)" title="全选/全不选" autocomplete="off"></th><th>项目名称</th><th>评价模式</th><th>打卡天数</th><th>打卡人次</th><th>打卡人数</th><th>已评价</th><th>操作</th><th>导出</th></tr>
            </thead>
            <tbody>
                <?php foreach ($daily_projects as $p): $agg = $daily_agg[intval($p['id'])] ?? null; ?>
                <tr>
                    <td><input type="checkbox" class="proj-ck" value="<?php echo $p['id']; ?>" onchange="updateProjSel()" autocomplete="off"></td>
                    <td><?php echo htmlspecialchars($p['name']); ?></td>
                    <td><?php echo htmlspecialchars($p['eval_name'] ?? $p['eval_mode']); ?></td>
                    <td style="color:#667eea;font-weight:bold;"><?php echo intval($agg['days'] ?? 0); ?></td>
                    <td><?php echo intval($agg['times'] ?? 0); ?></td>
                    <td style="color:#27ae60;font-weight:bold;"><?php echo intval($agg['studs'] ?? 0); ?></td>
                    <td><?php echo $p['evaluated_count']; ?></td>
                    <td>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <a href="stats.php?class_id=<?php echo $class_id; ?>&project_id=<?php echo $p['id']; ?><?php echo $embed ? '&embed=1' : ''; ?>" class="btn btn-sm btn-outline" style="text-decoration:none;">📊 单项统计</a>
                            <?php if (can_view_project($conn, $current_teacher_id, $p)): ?>
                            <a href="project_view.php?project_id=<?php echo $p['id']; ?><?php echo $embed ? '&embed=1' : ''; ?>" class="btn btn-sm btn-outline" style="text-decoration:none;">查看详情</a>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <?php if (get_project_for($conn, intval($p['id']), $current_teacher_id, 'operate')): ?>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <a href="export.php?action=download&dtype=project&id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline" style="text-decoration:none;">⬇ 详细数据</a>
                            <a href="export.php?action=download&dtype=project_daily&id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline" style="text-decoration:none;">⬇ 每日明细</a>
                        </div>
                        <?php else: ?><span style="color:#bbb;font-size:12px;">—</span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$daily_projects): ?>
                <tr><td colspan="9" style="text-align:center;color:#999;">暂无打卡项目</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <p style="font-weight:bold;margin:18px 0 6px;color:#27ae60;">📌 一次性项目（<?php echo count($count_projects); ?>）—— 一次登记即完成</p>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th><input type="checkbox" onchange="projAll(this)" title="全选/全不选" autocomplete="off"></th><th>项目名称</th><th>评价模式</th><th>已登记</th><th>未登记</th><th>登记率</th><th>已评价</th><th>操作</th><th>导出</th></tr>
            </thead>
            <tbody>
                <?php foreach ($count_projects as $p): $rate = $total_students > 0 ? round($p['registered_count'] / $total_students * 100) : 0; ?>
                <tr>
                    <td><input type="checkbox" class="proj-ck" value="<?php echo $p['id']; ?>" onchange="updateProjSel()" autocomplete="off"></td>
                    <td><?php echo htmlspecialchars($p['name']); ?></td>
                    <td><?php echo htmlspecialchars($p['eval_name'] ?? $p['eval_mode']); ?></td>
                    <td style="color:#27ae60;font-weight:bold;"><?php echo $p['registered_count']; ?></td>
                    <td style="color:#f39c12;"><?php echo $total_students - $p['registered_count']; ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="flex:1;max-width:120px;height:8px;background:#eee;border-radius:4px;overflow:hidden;">
                                <div style="width:<?php echo $rate; ?>%;height:100%;background:linear-gradient(90deg,#667eea,#764ba2);"></div>
                            </div>
                            <span style="font-size:12px;color:#888;"><?php echo $rate; ?>%</span>
                        </div>
                    </td>
                    <td><?php echo $p['evaluated_count']; ?></td>
                    <td>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <a href="stats.php?class_id=<?php echo $class_id; ?>&project_id=<?php echo $p['id']; ?><?php echo $embed ? '&embed=1' : ''; ?>" class="btn btn-sm btn-outline" style="text-decoration:none;">📊 单项统计</a>
                            <?php if (can_view_project($conn, $current_teacher_id, $p)): ?>
                            <a href="project_view.php?project_id=<?php echo $p['id']; ?><?php echo $embed ? '&embed=1' : ''; ?>" class="btn btn-sm btn-outline" style="text-decoration:none;">查看详情</a>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <?php if (get_project_for($conn, intval($p['id']), $current_teacher_id, 'operate')): ?>
                        <a href="export.php?action=download&dtype=project&id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline" style="text-decoration:none;">⬇ 详细数据</a>
                        <?php else: ?><span style="color:#bbb;font-size:12px;">—</span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$count_projects): ?>
                <tr><td colspan="9" style="text-align:center;color:#999;">暂无一次性项目</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($omr_projects): ?>
    <p style="font-weight:bold;margin:18px 0 6px;color:#8e44ad;">🖼 答题卡项目（<?php echo count($omr_projects); ?>）—— 涂卡识别判分，多题次统计</p>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>项目名称</th><th>范围</th><th>题次数</th><th>识别人次</th><th>参与人数</th><th>操作</th><th>导出</th></tr>
            </thead>
            <tbody>
                <?php foreach ($omr_projects as $p): $oagg = $omr_proj_agg[intval($p['id'])] ?? ['times' => 0, 'studs' => 0, 'rounds' => 0]; ?>
                <tr>
                    <td><?php echo htmlspecialchars($p['name']); ?></td>
                    <td><span class="badge badge-scope" style="background:#667eea;"><?php echo $scope_names[$p['scope']] ?? $p['scope']; ?></span></td>
                    <td style="color:#667eea;font-weight:bold;"><?php echo $oagg['rounds']; ?></td>
                    <td><?php echo $oagg['times']; ?></td>
                    <td style="color:#27ae60;font-weight:bold;"><?php echo $oagg['studs']; ?></td>
                    <td>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <a href="stats.php?class_id=<?php echo $class_id; ?>&project_id=<?php echo $p['id']; ?><?php echo $embed ? '&embed=1' : ''; ?>" class="btn btn-sm btn-outline" style="text-decoration:none;">📊 统计报表</a>
                            <?php if (can_view_project($conn, $current_teacher_id, $p)): ?>
                            <a href="project_view.php?project_id=<?php echo $p['id']; ?><?php echo $embed ? '&embed=1' : ''; ?>" class="btn btn-sm btn-outline" style="text-decoration:none;">查看详情</a>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <?php if (get_project_for($conn, intval($p['id']), $current_teacher_id, 'operate')): ?>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <a href="export.php?action=download&dtype=omr_report&id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline" style="text-decoration:none;">⬇ 统计报表</a>
                            <a href="export.php?action=download&dtype=omr_answers&id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline" style="text-decoration:none;">⬇ 作答明细</a>
                        </div>
                        <?php else: ?><span style="color:#bbb;font-size:12px;">—</span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php if (!$projects && !$omr_projects): ?>
    <p style="text-align:center;color:#999;">暂无项目</p>
    <?php endif; ?>
</div>

<div class="panel">
    <h3>评价分布统计</h3>
    <?php if (empty($chart_eval)): ?>
    <p style="color:#999;font-size:14px;">暂无评价数据</p>
    <?php else: ?>
        <?php foreach ($chart_eval as $d): ?>
        <p style="font-weight:500;margin:12px 0 8px;color:#555;"><?php echo htmlspecialchars($d['name']); ?></p>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <?php foreach ($d['items'] as $it): ?>
            <div class="stat-box" style="min-width:100px;padding:12px;">
                <div class="num" style="font-size:22px;"><?php echo htmlspecialchars($it['v']); ?></div>
                <div class="label"><?php echo $it['cnt']; ?> 人次</div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($view === 'project'): ?>
<div class="stat-bar">
    <div class="stat-box"><div class="num"><?php echo $total_students; ?></div><div class="label">全班人数<button type="button" class="hint-q" onclick="toggleHint(event, '<b>统计口径说明</b><br>· <b>打卡项目</b>：打卡天数=有记录的天数、人次=记录条数、人数=去重学生数<br>· <b>其他项目</b>：已登记/未登记按登记记录统计<br>· <b>已评价</b>=附有评价内容的记录数<br>· 已禁用学生不计入')">?</button></div></div>
    <?php if ($is_omr): ?>
    <div class="stat-box"><div class="num"><?php echo $omr_parts; ?></div><div class="label">参与人数（有成绩）<button type="button" class="hint-q" onclick="toggleHint(event, '<b>答题卡统计口径说明</b><br>· 得分率=Σ得分÷Σ满分（逐次/总体同口径）<br>· 优秀率=个体得分率≥80%；及格率=≥60%；低分率=&lt;30%<br>· 超均率=(单位得分率−全体得分率)÷全体得分率×100%<br>· 参考人数=该次有已批改成绩的学生数<br>· 已禁用学生不计入')">?</button></div></div>
    <div class="stat-box"><div class="num green"><?php echo $omr_times; ?></div><div class="label">累计识别人次</div></div>
    <div class="stat-box"><div class="num"><?php echo count($omr_rounds); ?></div><div class="label">题次数</div></div>
    <div class="stat-box"><div class="num orange"><?php echo $omr_total_agg ? $omr_total_agg['rate'] : 0; ?>%</div><div class="label">全项目平均得分率</div></div>
    <?php elseif ($is_quiz): ?>
    <div class="stat-box"><div class="num"><?php echo $qz_parts; ?></div><div class="label">参与人数（有答题）<button type="button" class="hint-q" onclick="toggleHint(event, '<b>举牌/答题统计口径说明</b><br>· 正确率=答对人数÷答题人数（逐次/总体同口径）<br>· 正确答案在项目页「统计」弹窗勾选保存，未设答案的题次不判对错<br>· 超均率=(单位正确率−全体正确率)÷全体正确率×100%<br>· 参与人数=有任一次答题记录的学生数；已禁用学生不计入')">?</button></div></div>
    <div class="stat-box"><div class="num green"><?php echo $qz_sum_ans; ?></div><div class="label">累计答题人次</div></div>
    <div class="stat-box"><div class="num"><?php echo count($qz_rounds); ?></div><div class="label">题次数</div></div>
    <div class="stat-box"><div class="num orange"><?php echo $qz_all_rate; ?>%</div><div class="label">全项目平均正确率</div></div>
    <?php elseif ($is_daily): ?>
    <div class="stat-box"><div class="num"><?php echo $dsum['days']; ?></div><div class="label">打卡天数</div></div>
    <div class="stat-box"><div class="num green"><?php echo $dsum['times']; ?></div><div class="label">累计打卡人次</div></div>
    <div class="stat-box"><div class="num"><?php echo $dsum['studs']; ?></div><div class="label">打卡人数（去重）</div></div>
    <?php else: ?>
    <div class="stat-box"><div class="num green"><?php echo intval($project['registered_count']); ?></div><div class="label">已登记</div></div>
    <div class="stat-box"><div class="num orange"><?php echo $total_students - intval($project['registered_count']); ?></div><div class="label">未登记</div></div>
    <?php endif; ?>
    <?php if (!$is_omr && !$is_quiz): ?>
    <div class="stat-box"><div class="num"><?php echo $project['evaluated_count']; ?></div><div class="label">已评价</div></div>
    <?php endif; ?>
</div>

<div class="panel">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <h3 style="margin:0;">📊 <?php echo htmlspecialchars($project['name']); ?>
            <span class="badge badge-count" style="<?php echo $is_omr ? 'background:#667eea;' : ($is_quiz ? 'background:#e67e22;' : ''); ?>"><?php echo $is_omr ? '答题卡' : ($is_daily ? '打卡' : ($is_quiz ? ($project['mode'] === 'raise' ? '举牌' : '答题') : '单次')); ?></span>
        </h3>
        <span class="badge badge-scope" style="background:#667eea;"><?php echo $scope_names[$project['scope']] ?? $project['scope']; ?></span>
        <?php if (!$is_omr): ?>
        <span style="color:#888;font-size:13px;"><?php echo htmlspecialchars($project['eval_name'] ?? $project['eval_mode']); ?></span>
        <?php endif; ?>
        <div style="flex:1;"></div>
        <button type="button" class="btn btn-sm" onclick="openModal('chartModal');renderChart()">📊 统计图表</button>
    </div>
</div>

<?php if ($MX_JSON && $MX_JSON['rows']): ?>
<style>
.mx-table th, .mx-table td { border: 1px solid #e5e8f5; }
.mx-table thead th { position: sticky; top: 0; background: #f7f8fc; z-index: 3; }
.mx-table th:first-child, .mx-table td:first-child { position: sticky; left: 0; background: #fafbff; z-index: 2; text-align: left; }
.mx-table thead th:first-child { z-index: 4; }
.mx-name { display: block; padding: 8px 0; writing-mode: vertical-rl; text-orientation: upright; letter-spacing: 3px; font-size: 12px; color: #4f7cff; text-decoration: none; font-weight: bold; max-height: 110px; overflow: hidden; }
.mx-name:hover { color: #27ae60; }
</style>
<div class="panel">
    <h3>📋 <?php echo $is_daily ? '每日打卡矩阵' : '登记矩阵'; ?>（每次 × 学生）<button type="button" class="hint-q" onclick="toggleHint(event, '<b>登记矩阵说明</b><br>· 行=每次（打卡模式=日期），列=本班学生（按座号排序）<br>· √=已登记/已打卡；字母=该次选项（绿=答对 红=答错 灰=未设答案）；得分/满分（绿=≥60% 红=&lt;60%）<br>· ·=未登记；底部合计=该生完成次数<br>· 点击列头姓名查看该生每次明细')">?</button></h3>
    <div class="table-wrap" style="max-height:520px;overflow:auto;">
        <table class="data-table mx-table" style="border-collapse:collapse;font-size:12px;">
            <thead>
                <tr>
                    <th style="min-width:170px;"><?php echo htmlspecialchars($MX_JSON['label']); ?> / 学生</th>
                    <?php foreach ($stu_rows as $s): ?>
                    <th style="padding:0;min-width:32px;width:32px;">
                        <a class="mx-name" href="javascript:void(0)" onclick="openStuMatrix(<?php echo intval($s['id']); ?>)" title="<?php echo htmlspecialchars($s['name']); ?>（座号 <?php echo htmlspecialchars($s['seat_no']); ?>），点击查看每次明细"><?php echo htmlspecialchars($s['name']); ?></a>
                    </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($MX_JSON['rows'] as $ri => $mrow): ?>
                <tr>
                    <td style="white-space:nowrap;font-size:12px;"><?php echo htmlspecialchars($mrow['label']); ?><span style="color:#999;">（<?php echo htmlspecialchars($mrow['sub']); ?>）</span></td>
                    <?php foreach ($stu_rows as $s): $mc = $MX_JSON['students'][intval($s['id'])]['cells'][$ri] ?? null; ?>
                    <td style="text-align:center;padding:4px 2px;font-weight:bold;<?php echo $mc ? 'color:' . ($mc['c'] === 'ok' ? '#27ae60' : ($mc['c'] === 'bad' ? '#e74c3c' : '#888')) . ';' : 'color:#d5d9e8;'; ?>"><?php echo $mc ? htmlspecialchars($mc['t']) : '·'; ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                <tr style="background:#f7f8fc;font-weight:bold;">
                    <td>合计（完成<?php echo htmlspecialchars($MX_JSON['label'] === '日期' ? '天数' : '次数'); ?>）</td>
                    <?php foreach ($stu_rows as $s): $mcnt = intval($MX_JSON['students'][intval($s['id'])]['cnt']); ?>
                    <td style="text-align:center;padding:4px 2px;color:<?php echo $mcnt > 0 ? '#4f7cff' : '#bbb'; ?>;"><?php echo $mcnt; ?></td>
                    <?php endforeach; ?>
                </tr>
            </tbody>
        </table>
    </div>
    <p style="color:#999;font-size:12px;margin:8px 0 0;">备注：√=已登记/已打卡，字母=答题选项（绿=答对 / 红=答错 / 灰=未设答案），得分/满分（绿=≥60% / 红=&lt;60%），·=未登记；点击列头姓名查看该生每次明细。</p>
</div>
<?php endif; ?>

<?php if ($is_omr): ?>
<!-- ===== 答题卡模式统计报表：多次汇总（学校表） / 班级单次（班级表） / 学生个人表 / 逐题分析 ===== -->
<div class="panel">
    <h3>📈 多次汇总（各次成绩总览<button type="button" class="hint-q" onclick="toggleHint(event, '<b>多次汇总表</b><br>全项目跨班按次汇总（参考学情看板「一均三率」）：<br>· 平均分=该次Σ得分÷人数；得分率=Σ得分÷Σ满分<br>· 优秀率≥80%、及格率≥60%、低分率&lt;30%（按个体得分率）<br>· 点击表头「查看题卡」入口见扫描页')">?</button>）</h3>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>次数</th><th>标题</th><th>参考人数</th><th>平均分</th><th>平均得分率</th><th>最高分</th><th>最低分</th><th>优秀率≥80%</th><th>及格率≥60%</th><th>低分率&lt;30%</th><th>满分</th><th>零分</th></tr>
            </thead>
            <tbody>
                <?php foreach ($omr_round_stats as $rs): $a = $rs['agg']; ?>
                <tr>
                    <td style="font-weight:bold;color:#667eea;">第 <?php echo $rs['no']; ?> 次</td>
                    <td><?php echo $rs['title'] !== '' ? htmlspecialchars($rs['title']) : '—'; ?></td>
                    <?php if (!$a): ?>
                    <td colspan="10" style="text-align:center;color:#999;">该次暂无已批改成绩</td>
                    <?php else: ?>
                    <td><?php echo $a['n']; ?></td>
                    <td style="font-weight:bold;"><?php echo $a['avg']; ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="flex:1;max-width:120px;height:8px;background:#eee;border-radius:4px;overflow:hidden;">
                                <div style="width:<?php echo min(100, $a['rate']); ?>%;height:100%;background:linear-gradient(90deg,#667eea,#764ba2);"></div>
                            </div>
                            <span style="font-size:12px;color:#888;"><?php echo $a['rate']; ?>%</span>
                        </div>
                    </td>
                    <td style="color:#27ae60;font-weight:bold;"><?php echo $a['max']; ?></td>
                    <td style="color:#e74c3c;font-weight:bold;"><?php echo $a['min']; ?></td>
                    <td style="color:#27ae60;font-weight:bold;"><?php echo $a['ex']; ?>%</td>
                    <td style="color:#2980b9;font-weight:bold;"><?php echo $a['pa']; ?>%</td>
                    <td style="color:#e74c3c;font-weight:bold;"><?php echo $a['low']; ?>%</td>
                    <td><?php echo $a['full']; ?> 人</td>
                    <td><?php echo $a['zero']; ?> 人</td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php if (!$omr_round_stats): ?>
                <tr><td colspan="12" style="text-align:center;color:#999;">暂无题次（在项目页顶部可新增题次）</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <h3 style="margin:0;">🏫 班级单次分析</h3>
        <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;">次数
            <select class="form-control" style="width:auto;padding:4px 8px;" onchange="omrJump('round', this.value)">
                <?php foreach ($omr_rounds as $r): ?>
                <option value="<?php echo $r['no']; ?>" <?php echo $r['no'] === $omr_sel_round ? 'selected' : ''; ?>>第 <?php echo $r['no']; ?> 次<?php echo $r['title'] !== '' ? '·' . htmlspecialchars($r['title']) : ''; ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if (count($omr_cls_ids) > 1): ?>
        <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;">班级（逐题分析）
            <select class="form-control" style="width:auto;padding:4px 8px;" onchange="omrJump('omr_cls', this.value)">
                <?php foreach ($omr_cls_ids as $cid): if (!isset($omr_classes[$cid])) continue; ?>
                <option value="<?php echo $cid; ?>" <?php echo $cid === $omr_sel_cls ? 'selected' : ''; ?>><?php echo htmlspecialchars($omr_classes[$cid]); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
    </div>

    <p style="font-weight:bold;margin:14px 0 6px;color:#555;">各班对比（第 <?php echo $omr_sel_round; ?> 次）</p>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>班级</th><th>参考人数</th><th>平均分</th><th>得分率</th><th>优秀率≥80%</th><th>及格率≥60%</th><th>低分率&lt;30%</th><th>超均率</th></tr>
            </thead>
            <tbody>
                <?php foreach ($omr_cls_stats as $a): $overColor = $a['over'] === null ? '#999' : ($a['over'] >= 0 ? '#27ae60' : '#e74c3c'); ?>
                <tr>
                    <td style="font-weight:bold;"><?php echo htmlspecialchars($a['name']); ?></td>
                    <td><?php echo $a['n']; ?></td>
                    <td style="font-weight:bold;"><?php echo $a['avg']; ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="flex:1;max-width:120px;height:8px;background:#eee;border-radius:4px;overflow:hidden;">
                                <div style="width:<?php echo min(100, $a['rate']); ?>%;height:100%;background:linear-gradient(90deg,#667eea,#764ba2);"></div>
                            </div>
                            <span style="font-size:12px;color:#888;"><?php echo $a['rate']; ?>%</span>
                        </div>
                    </td>
                    <td style="color:#27ae60;font-weight:bold;"><?php echo $a['ex']; ?>%</td>
                    <td style="color:#2980b9;font-weight:bold;"><?php echo $a['pa']; ?>%</td>
                    <td style="color:#e74c3c;font-weight:bold;"><?php echo $a['low']; ?>%</td>
                    <td style="color:<?php echo $overColor; ?>;font-weight:bold;"><?php echo $a['over'] === null ? '—' : ($a['over'] >= 0 ? '+' : '') . $a['over'] . '%'; ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($omr_all_agg): ?>
                <tr style="background:#f6f7fd;">
                    <td style="font-weight:bold;color:#667eea;">全体合计</td>
                    <td><?php echo $omr_all_agg['n']; ?></td>
                    <td style="font-weight:bold;"><?php echo $omr_all_agg['avg']; ?></td>
                    <td style="font-weight:bold;"><?php echo $omr_all_agg['rate']; ?>%</td>
                    <td style="color:#27ae60;font-weight:bold;"><?php echo $omr_all_agg['ex']; ?>%</td>
                    <td style="color:#2980b9;font-weight:bold;"><?php echo $omr_all_agg['pa']; ?>%</td>
                    <td style="color:#e74c3c;font-weight:bold;"><?php echo $omr_all_agg['low']; ?>%</td>
                    <td>—</td>
                </tr>
                <?php endif; ?>
                <?php if (!$omr_cls_stats && !$omr_all_agg): ?>
                <tr><td colspan="8" style="text-align:center;color:#999;">该次暂无已批改成绩</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <p style="font-weight:bold;margin:16px 0 6px;color:#555;">逐题分析（第 <?php echo $omr_sel_round; ?> 次<?php echo count($omr_cls_ids) > 1 ? ' · ' . htmlspecialchars($omr_classes[$omr_sel_cls] ?? '') : ''; ?>）</p>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>题号</th><th>题组</th><th>题型</th><th>正确答案</th><th>作答/参考</th><th>答对</th><th>正确率</th><th>选项分布（人数·占比）</th></tr>
            </thead>
            <tbody>
                <?php foreach ($omr_qs as $q):
                    $rate = $q['n'] > 0 ? round($q['correct'] / $q['n'] * 100, 1) : 0;
                    $rateColor = $rate >= 80 ? '#27ae60' : ($rate >= 60 ? '#2980b9' : ($rate >= 40 ? '#f39c12' : '#e74c3c'));
                    $keyLetters = str_split(strval($q['key']));
                ?>
                <tr>
                    <td style="font-weight:bold;color:#667eea;"><?php echo $q['no']; ?></td>
                    <td><?php echo $q['sec'] !== '' ? htmlspecialchars($q['sec']) : '—'; ?></td>
                    <td><?php echo $q['kind'] === 'multi' ? '多选' : '单选'; ?></td>
                    <td style="font-weight:bold;color:#27ae60;"><?php echo $q['key'] !== '' ? htmlspecialchars($q['key']) : '未设'; ?></td>
                    <td><?php echo $q['answered']; ?> / <?php echo $q['n']; ?></td>
                    <td style="font-weight:bold;"><?php echo $q['key'] !== '' ? $q['correct'] : '—'; ?></td>
                    <td>
                        <?php if ($q['key'] === ''): ?><span style="color:#999;">—</span>
                        <?php else: ?>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="flex:1;max-width:120px;height:8px;background:#eee;border-radius:4px;overflow:hidden;">
                                <div style="width:<?php echo min(100, $rate); ?>%;height:100%;background:<?php echo $rateColor; ?>;"></div>
                            </div>
                            <span style="font-size:12px;font-weight:bold;color:<?php echo $rateColor; ?>;"><?php echo $rate; ?>%</span>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <?php for ($li = 0; $li < $q['opts']; $li++):
                                $ch = 'ABCDE'[$li];
                                $cnt = $q['dist'][$ch];
                                $pct = $q['n'] > 0 ? round($cnt / $q['n'] * 100) : 0;
                                $isKey = in_array($ch, $keyLetters, true);
                            ?>
                            <span style="display:inline-block;min-width:52px;text-align:center;padding:2px 6px;border-radius:6px;font-size:12px;<?php
                                echo $isKey ? 'background:#e8f8ef;color:#27ae60;font-weight:bold;border:1px solid #27ae60;'
                                            : 'background:#f2f3f8;color:#666;border:1px solid #dde3f0;'; ?>"><?php echo $ch; ?> <?php echo $cnt; ?>（<?php echo $pct; ?>%）</span>
                            <?php endfor; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$omr_qs): ?>
                <tr><td colspan="8" style="text-align:center;color:#999;">未绑定答题卡模板或该次无识别数据（模板在项目页「设计模板」绑定）</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <h3>👤 学生个人成绩（全部题次汇总<button type="button" class="hint-q" onclick="toggleHint(event, '<b>学生个人成绩表</b><br>· 各次=该次得分率（括号内为 得分/满分）<br>· 总得分率=Σ得分÷Σ满分；等级：A≥80% B≥70% C≥60% D&lt;60%<br>· 班名=本班按总得分率排名（同分并列）<br>· 超均率=(个人得分率−本班均分)÷本班均分×100%')">?</button>）</h3>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>座号</th><th>姓名</th>
                    <?php if (count($omr_cls_ids) > 1): ?><th>班级</th><?php endif; ?>
                    <?php foreach ($omr_rounds as $r): ?><th>第 <?php echo $r['no']; ?> 次<?php echo $r['title'] !== '' ? '<div style="font-size:11px;font-weight:normal;color:#999;">' . htmlspecialchars($r['title']) . '</div>' : ''; ?></th><?php endforeach; ?>
                    <th>总得分率</th><th>等级</th><th>班名</th><th>超均率</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($omr_stu_stats as $st): $s = $st['s'];
                    $gColor = $st['grade'] === 'A' ? '#27ae60' : ($st['grade'] === 'B' ? '#2980b9' : ($st['grade'] === 'C' ? '#f39c12' : '#e74c3c'));
                    $overColor = $st['over'] === null ? '#999' : ($st['over'] >= 0 ? '#27ae60' : '#e74c3c');
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($s['seat_no']); ?></td>
                    <td style="font-weight:bold;"><?php echo htmlspecialchars($s['name']); ?></td>
                    <?php if (count($omr_cls_ids) > 1): ?><td><?php echo htmlspecialchars($s['class_name']); ?></td><?php endif; ?>
                    <?php foreach ($st['cells'] as $c): ?>
                    <td><?php if ($c): ?><span style="font-weight:bold;color:<?php echo $c['rate'] >= 80 ? '#27ae60' : ($c['rate'] >= 60 ? '#2980b9' : '#e74c3c'); ?>;"><?php echo $c['rate']; ?>%</span><div style="font-size:11px;color:#999;"><?php echo $c['score']; ?></div><?php else: ?><span style="color:#ccc;">—</span><?php endif; ?></td>
                    <?php endforeach; ?>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="flex:1;max-width:100px;height:8px;background:#eee;border-radius:4px;overflow:hidden;">
                                <div style="width:<?php echo min(100, $st['rate']); ?>%;height:100%;background:linear-gradient(90deg,#667eea,#764ba2);"></div>
                            </div>
                            <span style="font-size:12px;font-weight:bold;color:#667eea;"><?php echo $st['rate']; ?>%</span>
                        </div>
                    </td>
                    <td><span style="display:inline-block;width:24px;height:24px;line-height:24px;text-align:center;border-radius:50%;color:#fff;font-weight:bold;background:<?php echo $gColor; ?>;"><?php echo $st['grade']; ?></span></td>
                    <td style="font-weight:bold;"><?php echo $st['rank'] !== null ? $st['rank'] : '—'; ?></td>
                    <td style="color:<?php echo $overColor; ?>;font-weight:bold;"><?php echo $st['over'] === null ? '—' : ($st['over'] >= 0 ? '+' : '') . $st['over'] . '%'; ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$omr_stu_stats): ?>
                <tr><td colspan="<?php echo 5 + count($omr_rounds) + (count($omr_cls_ids) > 1 ? 1 : 0); ?>" style="text-align:center;color:#999;">暂无已批改成绩（请先在「扫描」页识别答题卡并录入答案）</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($is_quiz): ?>
<!-- ===== 举牌/答题模式统计报表（ABCD）：多次汇总 / 班级单次（选项分布） / 学生个人，口径同答题卡（正确率=答对÷答题） ===== -->
<div class="panel">
    <h3>📈 多次汇总（各次答题总览<button type="button" class="hint-q" onclick="toggleHint(event, '<b>多次汇总表</b><br>全项目跨班按次汇总：<br>· 正确率=该次答对人数÷答题人数（未设答案不判对错）<br>· 选项分布=该次 A/B/C/D 各选项人数与占比，绿框=正确答案<br>· 正确答案在项目页「统计」弹窗勾选保存')">?</button>）</h3>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>次数</th><th>标题</th><th>正确答案</th><th>答题人数</th><th>答对</th><th>正确率</th><th>选项分布（人数·占比）</th></tr>
            </thead>
            <tbody>
                <?php foreach ($qz_round_stats as $rs):
                    $rateColor = $rs['rate'] >= 80 ? '#27ae60' : ($rs['rate'] >= 60 ? '#2980b9' : ($rs['rate'] >= 40 ? '#f39c12' : '#e74c3c'));
                    $keyTxt = implode(' / ', $qz_correct[$rs['no']]);
                ?>
                <tr>
                    <td style="font-weight:bold;color:#667eea;">第 <?php echo $rs['no']; ?> 次</td>
                    <td><?php echo $rs['title'] !== '' ? htmlspecialchars($rs['title']) : '—'; ?></td>
                    <td style="font-weight:bold;color:#27ae60;"><?php echo $keyTxt !== '' ? htmlspecialchars($keyTxt) : '未设'; ?></td>
                    <?php if ($rs['answered'] <= 0): ?>
                    <td colspan="4" style="text-align:center;color:#999;">该次暂无答题记录</td>
                    <?php else: ?>
                    <td><?php echo $rs['answered']; ?></td>
                    <td style="font-weight:bold;"><?php echo $rs['has_key'] ? $rs['crt'] : '—'; ?></td>
                    <td>
                        <?php if (!$rs['has_key']): ?><span style="color:#999;">—</span>
                        <?php else: ?>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="flex:1;max-width:120px;height:8px;background:#eee;border-radius:4px;overflow:hidden;">
                                <div style="width:<?php echo min(100, $rs['rate']); ?>%;height:100%;background:<?php echo $rateColor; ?>;"></div>
                            </div>
                            <span style="font-size:12px;font-weight:bold;color:<?php echo $rateColor; ?>;"><?php echo $qz_num($rs['rate']); ?>%</span>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <?php foreach (['A', 'B', 'C', 'D'] as $L):
                                $cnt = $rs['dist'][$L];
                                $pct = $rs['answered'] > 0 ? round($cnt / $rs['answered'] * 100) : 0;
                                $isKey = in_array($L, $qz_correct[$rs['no']], true);
                            ?>
                            <span style="display:inline-block;min-width:52px;text-align:center;padding:2px 6px;border-radius:6px;font-size:12px;<?php
                                echo $isKey ? 'background:#e8f8ef;color:#27ae60;font-weight:bold;border:1px solid #27ae60;'
                                            : 'background:#f2f3f8;color:#666;border:1px solid #dde3f0;'; ?>"><?php echo $L; ?> <?php echo $cnt; ?>（<?php echo $pct; ?>%）</span>
                            <?php endforeach; ?>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php if (!$qz_round_stats): ?>
                <tr><td colspan="7" style="text-align:center;color:#999;">暂无题次（在项目页顶部可新增题次）</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <h3 style="margin:0;">🏫 班级单次分析</h3>
        <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;">次数
            <select class="form-control" style="width:auto;padding:4px 8px;" onchange="omrJump('round', this.value)">
                <?php foreach ($qz_rounds as $r): ?>
                <option value="<?php echo $r['no']; ?>" <?php echo $r['no'] === $qz_sel_round ? 'selected' : ''; ?>>第 <?php echo $r['no']; ?> 次<?php echo $r['title'] !== '' ? '·' . htmlspecialchars($r['title']) : ''; ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if (count($qz_cls_ids) > 1): ?>
        <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;">班级（选项分布）
            <select class="form-control" style="width:auto;padding:4px 8px;" onchange="omrJump('qcls', this.value)">
                <?php foreach ($qz_cls_ids as $cid): if (!isset($qz_classes[$cid])) continue; ?>
                <option value="<?php echo $cid; ?>" <?php echo $cid === $qz_sel_cls ? 'selected' : ''; ?>><?php echo htmlspecialchars($qz_classes[$cid]); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
    </div>

    <?php $qz_sel_cls_stats = array_values(array_filter($qz_cls_stats, function ($cs) use ($qz_sel_round) { return $cs['no'] == $qz_sel_round; })); ?>
    <p style="font-weight:bold;margin:14px 0 6px;color:#555;">各班对比（第 <?php echo $qz_sel_round; ?> 次）</p>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>班级</th><th>答题人数</th><th>答对</th><th>正确率</th><th>超均率</th></tr>
            </thead>
            <tbody>
                <?php foreach ($qz_sel_cls_stats as $cs): ?>
                <?php foreach ($cs['rows'] as $a): $overColor = $a['over'] === null ? '#999' : ($a['over'] >= 0 ? '#27ae60' : '#e74c3c'); ?>
                <tr>
                    <td style="font-weight:bold;"><?php echo htmlspecialchars($a['name']); ?></td>
                    <td><?php echo $a['n']; ?></td>
                    <td style="font-weight:bold;"><?php echo $cs['has_key'] ? $a['crt'] : '—'; ?></td>
                    <td>
                        <?php if (!$cs['has_key']): ?><span style="color:#999;">—</span>
                        <?php else: ?>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="flex:1;max-width:120px;height:8px;background:#eee;border-radius:4px;overflow:hidden;">
                                <div style="width:<?php echo min(100, $a['rate']); ?>%;height:100%;background:linear-gradient(90deg,#667eea,#764ba2);"></div>
                            </div>
                            <span style="font-size:12px;color:#888;"><?php echo $qz_num($a['rate']); ?>%</span>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td style="color:<?php echo $overColor; ?>;font-weight:bold;"><?php echo $a['over'] === null ? '—' : ($a['over'] >= 0 ? '+' : '') . $qz_num($a['over']) . '%'; ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($cs['has_key']): ?>
                <tr style="background:#f6f7fd;">
                    <td style="font-weight:bold;color:#667eea;">全体合计</td>
                    <td><?php echo $cs['all_n']; ?></td>
                    <td style="font-weight:bold;"><?php echo $cs['all_crt']; ?></td>
                    <td style="font-weight:bold;"><?php echo $qz_num($cs['all_rate']); ?>%</td>
                    <td>—</td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
                <?php if (!$qz_sel_cls_stats): ?>
                <tr><td colspan="5" style="text-align:center;color:#999;">该次暂无答题记录</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <p style="font-weight:bold;margin:16px 0 6px;color:#555;">选项分布（第 <?php echo $qz_sel_round; ?> 次 · <?php echo htmlspecialchars($qz_classes[$qz_sel_cls] ?? ''); ?>）</p>
    <?php if (!$qz_sel_n): ?>
    <p style="color:#999;font-size:13px;">该班本次暂无答题记录</p>
    <?php else: ?>
    <p style="color:#666;font-size:13px;margin:0 0 8px;">答题 <?php echo $qz_sel_n; ?> 人<?php echo $qz_sel_key ? ' · 答对 ' . $qz_sel_crt . ' 人 · 正确率 ' . $qz_num(round($qz_sel_crt / $qz_sel_n * 100, 1)) . '%（正确答案：' . htmlspecialchars(implode(' / ', $qz_sel_key)) . '）' : ' · 未设正确答案，不判对错'; ?></p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <?php foreach (['A', 'B', 'C', 'D'] as $L):
            $cnt = $qz_sel_dist[$L];
            $pct = $qz_sel_n > 0 ? round($cnt / $qz_sel_n * 100) : 0;
            $isKey = in_array($L, $qz_sel_key, true);
        ?>
        <div style="flex:1;min-width:150px;background:<?php echo $isKey ? '#e8f8ef' : '#f6f7fd'; ?>;border:1px solid <?php echo $isKey ? '#27ae60' : '#dde3f0'; ?>;border-radius:10px;padding:10px 12px;">
            <div style="font-size:13px;color:<?php echo $isKey ? '#27ae60' : '#666'; ?>;font-weight:bold;"><?php echo $L; ?> <?php echo $isKey ? '✓ 正确答案' : ''; ?></div>
            <div style="font-size:22px;font-weight:bold;color:#555;"><?php echo $cnt; ?> 人</div>
            <div style="margin-top:4px;height:8px;background:#eee;border-radius:4px;overflow:hidden;">
                <div style="width:<?php echo $pct; ?>%;height:100%;background:<?php echo $isKey ? '#27ae60' : '#667eea'; ?>;"></div>
            </div>
            <div style="font-size:12px;color:#888;margin-top:3px;">占答题 <?php echo $pct; ?>%</div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<div class="panel">
    <h3>👤 学生个人答题情况（全部题次汇总<button type="button" class="hint-q" onclick="toggleHint(event, '<b>学生个人答题表</b><br>· 各次=该生所举/所选选项：<span style=&quot;color:#27ae60;&quot;>绿=对</span>、<span style=&quot;color:#e74c3c;&quot;>红=错</span>、灰=未答、蓝=未设答案不判<br>· 总正确率=Σ答对÷Σ答题；等级：A≥80% B≥70% C≥60% D&lt;60%<br>· 班名=本班按总正确率排名（同分并列）<br>· 超均率=(个人正确率−本班平均正确率)÷本班平均正确率×100%">?</button>）</h3>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>座号</th><th>姓名</th>
                    <?php if (count($qz_cls_ids) > 1): ?><th>班级</th><?php endif; ?>
                    <?php foreach ($qz_rounds as $r): ?><th>第 <?php echo $r['no']; ?> 次<?php echo $r['title'] !== '' ? '<div style="font-size:11px;font-weight:normal;color:#999;">' . htmlspecialchars($r['title']) . '</div>' : ''; ?></th><?php endforeach; ?>
                    <th>总正确率</th><th>等级</th><th>班名</th><th>超均率</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($qz_stu_stats as $sid => $st): $s = $st['s'];
                    $cavg = $qz_cls_avg[$s['class_id']] ?? 0;
                    $over = $cavg > 0 ? round(($st['rate'] - $cavg) / $cavg * 100, 1) : null;
                    $overColor = $over === null ? '#999' : ($over >= 0 ? '#27ae60' : '#e74c3c');
                    $rank = $qz_cls_rank[$s['class_id']][$sid] ?? null;
                    $grade = $st['rate'] >= 80 ? 'A' : ($st['rate'] >= 70 ? 'B' : ($st['rate'] >= 60 ? 'C' : 'D'));
                    $gColor = $grade === 'A' ? '#27ae60' : ($grade === 'B' ? '#2980b9' : ($grade === 'C' ? '#f39c12' : '#e74c3c'));
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($s['seat_no']); ?></td>
                    <td style="font-weight:bold;"><?php echo htmlspecialchars($s['name']); ?></td>
                    <?php if (count($qz_cls_ids) > 1): ?><td><?php echo htmlspecialchars($s['class_name']); ?></td><?php endif; ?>
                    <?php foreach ($st['cells'] as $ci => $c): ?>
                    <td><?php if ($c['opt'] === null): ?><span style="color:#ccc;">—</span>
                        <?php elseif (!$c['has_key']): ?><span style="color:#2980b9;font-weight:bold;"><?php echo $c['opt']; ?></span>
                        <?php elseif ($c['ok']): ?><span style="color:#27ae60;font-weight:bold;"><?php echo $c['opt']; ?> ✓</span>
                        <?php else: ?><span style="color:#e74c3c;font-weight:bold;"><?php echo $c['opt']; ?> ✗</span><?php endif; ?></td>
                    <?php endforeach; ?>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="flex:1;max-width:100px;height:8px;background:#eee;border-radius:4px;overflow:hidden;">
                                <div style="width:<?php echo min(100, $st['rate']); ?>%;height:100%;background:linear-gradient(90deg,#667eea,#764ba2);"></div>
                            </div>
                            <span style="font-size:12px;font-weight:bold;color:#667eea;"><?php echo $qz_num($st['rate']); ?>%</span>
                        </div>
                    </td>
                    <td><span style="display:inline-block;width:24px;height:24px;line-height:24px;text-align:center;border-radius:50%;color:#fff;font-weight:bold;background:<?php echo $gColor; ?>;"><?php echo $grade; ?></span></td>
                    <td style="font-weight:bold;"><?php echo $rank !== null ? $rank : '—'; ?></td>
                    <td style="color:<?php echo $overColor; ?>;font-weight:bold;"><?php echo $over === null ? '—' : ($over >= 0 ? '+' : '') . $qz_num($over) . '%'; ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$qz_stu_stats): ?>
                <tr><td colspan="<?php echo 6 + count($qz_rounds) + (count($qz_cls_ids) > 1 ? 1 : 0); ?>" style="text-align:center;color:#999;">暂无答题记录（请先在项目页进行举牌/答题登记）</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: /* 非答题卡项目沿用原统计面板 */ ?>
<?php if ($is_daily && $daily_rows): ?>
<div class="panel">
    <h3>📅 每日打卡情况（每次）</h3>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>日期</th><th>打卡人数</th><th>占全班比例</th></tr></thead>
            <tbody>
                <?php foreach ($daily_rows as $r): $rr = $total_students > 0 ? round($r['cnt'] / $total_students * 100) : 0; ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['date']); ?></td>
                    <td style="color:#27ae60;font-weight:bold;"><?php echo $r['cnt']; ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="flex:1;max-width:160px;height:8px;background:#eee;border-radius:4px;overflow:hidden;">
                                <div style="width:<?php echo $rr; ?>%;height:100%;background:linear-gradient(90deg,#27ae60,#66bb6a);"></div>
                            </div>
                            <span style="font-size:12px;color:#888;"><?php echo $rr; ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="panel">
    <h3>👥 学生登记情况（总体）</h3>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <?php if ($is_daily): ?>
                <tr><th>座号</th><th>姓名</th><th>编号</th><th>打卡天数</th><th>最近打卡</th><th>评价内容</th></tr>
                <?php else: ?>
                <tr><th>座号</th><th>姓名</th><th>编号</th><th>状态</th><th>登记时间</th><th>评价内容</th></tr>
                <?php endif; ?>
            </thead>
            <tbody>
                <?php foreach ($stu_rows as $s): ?>
                <tr>
                    <td><?php echo htmlspecialchars($s['seat_no']); ?></td>
                    <td><?php echo htmlspecialchars($s['name']); ?></td>
                    <td><?php echo htmlspecialchars($s['student_no']); ?></td>
                    <?php if ($is_daily): ?>
                    <td style="color:<?php echo intval($s['rdays']) > 0 ? '#27ae60' : '#999'; ?>;font-weight:bold;"><?php echo intval($s['rdays']); ?> 天</td>
                    <td><?php echo $s['last_day'] ? htmlspecialchars($s['last_day']) : '—'; ?></td>
                    <?php else: ?>
                    <td><?php echo $s['registered'] == 1 ? '<span style="color:#27ae60;font-weight:bold;">已登记</span>' : '<span style="color:#999;">未登记</span>'; ?></td>
                    <td><?php echo $s['registered_at'] ? htmlspecialchars($s['registered_at']) : '—'; ?></td>
                    <?php endif; ?>
                    <td><?php echo ($s['eval_value'] !== null && $s['eval_value'] !== '') ? htmlspecialchars($s['eval_value']) : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$stu_rows): ?>
                <tr><td colspan="6" style="text-align:center;color:#999;">暂无学生</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <h3>评价分布统计（本项目）</h3>
    <?php if (empty($proj_eval)): ?>
    <p style="color:#999;font-size:14px;">暂无评价数据</p>
    <?php else: ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <?php foreach ($proj_eval as $it): ?>
        <div class="stat-box" style="min-width:100px;padding:12px;">
            <div class="num" style="font-size:22px;"><?php echo htmlspecialchars($it['v']); ?></div>
            <div class="label"><?php echo $it['cnt']; ?> 人次</div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; /* 非答题卡面板结束 */ ?>

<div class="panel">
    <h3>⬇ 导出</h3>
    <?php if ($is_omr): ?>
    <?php if ($can_op): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a class="btn btn-sm btn-outline" style="text-decoration:none;"
           href="export.php?action=download&dtype=omr_report&id=<?php echo $project_id; ?>">⬇ 导出统计报表（Excel：多次汇总/班级对比/学生个人/逐题分析）</a>
        <a class="btn btn-sm btn-outline" style="text-decoration:none;"
           href="export.php?action=download&dtype=omr_answers&id=<?php echo $project_id; ?>">⬇ 导出每生每题作答明细（Excel：每生每题选择情况）</a>
    </div>
    <?php else: ?>
    <p style="color:#999;font-size:13px;">该项目为只读查看，导出需具有该项目的操作（登记/评价）权限。</p>
    <?php endif; ?>
    <?php elseif ($can_op): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a class="btn btn-sm btn-outline" style="text-decoration:none;"
           href="export.php?action=download&dtype=project&id=<?php echo $project_id; ?>">⬇ 下载导出详细数据<?php echo $is_daily ? '（Excel：是否登记/登记时间/评价内容）' : '（学生登记/评价明细）'; ?></a>
        <?php if ($is_daily): ?>
        <a class="btn btn-sm btn-outline" style="text-decoration:none;"
           href="export.php?action=download&dtype=project_daily&id=<?php echo $project_id; ?>">⬇ 导出每日打卡明细（每生每日期）</a>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <p style="color:#999;font-size:13px;">该项目为只读查看，导出需具有该项目的操作（登记/评价）权限。</p>
    <?php endif; ?>
</div>

<?php
// ===== 教师点评（项目维度）：面板计数 + 批量点评弹窗；家长公开查询「综合报告」中展示 =====
$cm_map = [];
$cm_res = mysqli_query($conn, "SELECT student_id, content FROM student_comments WHERE project_id = {$project_id}");
if ($cm_res) {
    while ($cm_row = mysqli_fetch_assoc($cm_res)) $cm_map[intval($cm_row['student_id'])] = strval($cm_row['content']);
}
?>
<div class="panel">
    <h3>💬 教师点评</h3>
    <p style="color:#999;font-size:13px;margin-top:0;">针对本项目每个学生的个性化点评，家长在公开查询页「综合报告」中可见；项目视图长按学生卡片 → 评价弹窗内也可单条填写。</p>
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <span style="font-size:14px;">已点评 <b style="color:#667eea;font-size:18px;"><?php echo count($cm_map); ?></b> / <?php echo count($stu_rows); ?> 人</span>
        <?php if ($can_op): ?>
        <button type="button" class="btn btn-sm" onclick="openModal('cmModal')">✍ 批量点评</button>
        <?php else: ?>
        <span style="color:#999;font-size:12px;">点评维护需具有该项目的操作（登记/评价）权限</span>
        <?php endif; ?>
    </div>
</div>

<style>
#cmList { overflow-y: auto; min-height: 0; flex: 1; border: 1px solid #eef1f8; border-radius: 8px; }
.cm-row { display: flex; align-items: center; gap: 10px; padding: 7px 10px; border-bottom: 1px solid #f2f4fa; }
.cm-row:last-child { border-bottom: 0; }
.cm-row.cm-touched { background: #f6f8ff; }
.cm-info { width: 200px; min-width: 150px; line-height: 1.4; }
.cm-info b { font-size: 13.5px; color: #333; }
.cm-info small { display: block; color: #98a0b3; font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cm-input { flex: 1; }
</style>
<div class="modal-mask" id="cmModal">
    <div class="modal" style="width:680px;max-width:94vw;height:82vh;display:flex;flex-direction:column;">
        <h3>✍ 批量点评 - <?php echo htmlspecialchars($project['name']); ?></h3>
        <div style="display:flex;flex-direction:column;gap:8px;min-height:0;flex:1;">
            <div style="border:1px dashed #dfe4fb;border-radius:8px;padding:8px 10px;background:#fbfbfe;">
                <div style="font-size:12px;color:#8a93a3;margin-bottom:6px;">📋 批量导入：每行一条「<b>编号或姓名,点评内容</b>」（逗号/Tab 分隔），解析后填充到下方输入框；只填编号或姓名不加内容 = 清除该生点评</div>
                <textarea id="cmPaste" class="form-control" rows="3" placeholder="2024001,本学期进步明显，继续加油！&#10;张三,课堂发言积极" style="resize:vertical;font-size:12.5px;"></textarea>
                <div style="display:flex;gap:8px;margin-top:6px;align-items:center;">
                    <button type="button" class="btn btn-sm btn-outline" onclick="cmParsePaste()">解析填充</button>
                    <span id="cmParseMsg" style="font-size:12px;color:#98a0b3;"></span>
                </div>
            </div>
            <div id="cmList">
                <?php foreach ($stu_rows as $s): $cmo = strval($cm_map[intval($s['id'])] ?? ''); ?>
                <div class="cm-row" data-sid="<?php echo intval($s['id']); ?>" data-no="<?php echo htmlspecialchars($s['student_no']); ?>" data-name="<?php echo htmlspecialchars($s['name']); ?>" data-orig="<?php echo htmlspecialchars($cmo); ?>">
                    <div class="cm-info">
                        <b><?php echo htmlspecialchars($s['name']); ?></b>
                        <small>座号 <?php echo htmlspecialchars($s['seat_no']); ?> · <?php echo htmlspecialchars($s['student_no'] ?: '无编号'); ?></small>
                        <?php if ($cmo !== ''): ?><small style="color:#e67e22;" class="cm-cur">现：<?php echo htmlspecialchars($cmo); ?></small><?php endif; ?>
                    </div>
                    <input type="text" class="form-control cm-input" value="<?php echo htmlspecialchars($cmo); ?>" placeholder="点评内容（留空保存 = 删除已有点评）" maxlength="500">
                </div>
                <?php endforeach; ?>
                <?php if (!$stu_rows): ?>
                <div style="padding:24px;text-align:center;color:#98a0b3;font-size:13px;">该班级暂无学生</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="modal-actions" style="margin-top:10px;">
            <span id="cmStat" style="font-size:12px;color:#98a0b3;margin-right:auto;"></span>
            <button type="button" class="btn btn-outline" onclick="closeModal('cmModal')">取消</button>
            <button type="button" class="btn" onclick="cmSave()">保存点评</button>
        </div>
    </div>
</div>
<script>
var CM_PROJECT_ID = <?php echo $project_id; ?>;
// 粘贴批量导入解析：每行「编号或姓名,点评内容」（全角逗号/Tab 同样支持）；编号精确匹配，姓名唯一才匹配，重名/未匹配跳过并提示
function cmParsePaste() {
    var txt = document.getElementById('cmPaste').value.trim();
    var msg = document.getElementById('cmParseMsg');
    if (!txt) { msg.style.color = '#e67e22'; msg.textContent = '请先粘贴内容'; return; }
    var noMap = {}, nameMap = {}, nameDup = {};
    document.querySelectorAll('#cmList .cm-row').forEach(function (row) {
        noMap[row.dataset.no] = row;
        var nm = row.dataset.name;
        if (nameMap[nm]) nameDup[nm] = true; else nameMap[nm] = row;
    });
    var ok = 0, fail = [];
    txt.split(/\r?\n/).forEach(function (line) {
        line = line.trim();
        if (!line) return;
        var idx = -1;
        for (var i = 0; i < line.length; i++) { if (line[i] === ',' || line[i] === '，' || line[i] === '\t') { idx = i; break; } }
        var key = idx === -1 ? line : line.slice(0, idx).trim();
        var content = idx === -1 ? '' : line.slice(idx + 1).trim();
        var row = noMap[key] || (nameDup[key] ? null : nameMap[key]);
        if (!row) { fail.push(key); return; }
        row.querySelector('.cm-input').value = content;
        row.classList.add('cm-touched');
        ok++;
    });
    msg.style.color = fail.length ? '#e74c3c' : '#27ae60';
    msg.textContent = '解析填充 ' + ok + ' 条' + (fail.length ? '，未匹配：' + fail.slice(0, 5).join('、') + (fail.length > 5 ? ' 等' + fail.length + '项' : '') : '');
}
// 保存：仅提交「输入内容 ≠ 原值」的行（后端覆盖式保存，空串=删除）
function cmSave() {
    var items = [];
    document.querySelectorAll('#cmList .cm-row').forEach(function (row) {
        var v = row.querySelector('.cm-input').value.trim();
        if (v !== (row.dataset.orig || '')) items.push({ student_id: parseInt(row.dataset.sid, 10), content: v });
    });
    if (!items.length) { showToast('没有需要保存的修改', 'error'); return; }
    document.getElementById('cmStat').textContent = '正在保存 ' + items.length + ' 条…';
    var fd = new FormData();
    fd.append('type', 'comments_batch');
    fd.append('project_id', CM_PROJECT_ID);
    fd.append('items', JSON.stringify(items));
    fetch('api.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
        if (d.success) {
            showToast('已保存 ' + d.saved + ' 条点评' + (d.skipped ? '，跳过 ' + d.skipped + ' 条' : ''), 'success');
            closeModal('cmModal');
            setTimeout(function () { location.reload(); }, 700);   // 刷新面板计数与「现」值
        } else {
            document.getElementById('cmStat').textContent = '';
            showToast(d.message || '保存失败', 'error');
        }
    }).catch(function () {
        document.getElementById('cmStat').textContent = '';
        showToast('网络错误，保存失败', 'error');
    });
}
</script>
<?php endif; ?>

<?php if ($view === 'student'): ?>
<div class="stat-bar">
    <div class="stat-box"><div class="num"><?php echo count($projects); ?></div><div class="label">项目总数</div></div>
    <div class="stat-box"><div class="num green"><?php echo $sum['reg']; ?></div><div class="label">已登记项目</div></div>
    <div class="stat-box"><div class="num orange"><?php echo $sum['days']; ?></div><div class="label">累计打卡天数</div></div>
    <div class="stat-box"><div class="num"><?php echo $sum['eval']; ?></div><div class="label">已评价项目</div></div>
</div>

<div class="panel">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <h3 style="margin:0;">👤 <?php echo htmlspecialchars($student['name']); ?></h3>
        <span style="color:#888;font-size:13px;">座号 <?php echo htmlspecialchars($student['seat_no']); ?>
            · 编号 <?php echo htmlspecialchars($student['student_no'] ?: '—'); ?></span>
        <div style="flex:1;"></div>
        <button type="button" class="btn btn-sm" onclick="openModal('chartModal');renderChart()">📊 统计图表</button>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>项目</th><th>模式</th><th>状态</th><th>登记时间</th><th>评价内容</th></tr></thead>
            <tbody>
                <?php foreach ($per as $row): $p = $row['p']; $is_daily = ($p['mode'] ?? 'count') === 'daily'; $is_omr = ($p['mode'] ?? 'count') === 'omr'; ?>
                <tr>
                    <td><?php echo htmlspecialchars($p['name']); ?></td>
                    <td><span class="badge badge-count" <?php echo $is_omr ? 'style="background:#667eea;"' : ''; ?>><?php echo $is_omr ? '答题卡' : ($is_daily ? '打卡' : '一次性'); ?></span></td>
                    <td>
                        <?php if ($is_omr): ?>
                            <?php echo $row['omr_text'] !== '' ? '<span style="color:#27ae60;font-weight:bold;">√ ' . htmlspecialchars($row['omr_text']) . '</span>' : '<span style="color:#999;">× 未识别</span>'; ?>
                        <?php elseif ($is_daily): ?>
                            <?php if ($row['days'] > 0): ?>
                            <span style="color:#27ae60;font-weight:bold;">√ 打卡 <?php echo $row['days']; ?> 天</span>
                            <span style="color:#999;font-size:12px;">（最近 <?php echo htmlspecialchars($row['last']); ?>）</span>
                            <?php else: ?><span style="color:#999;">× 未打卡</span><?php endif; ?>
                        <?php else: ?>
                            <?php echo $row['is_reg'] ? '<span style="color:#27ae60;font-weight:bold;">√ 已登记</span>' : '<span style="color:#999;">× 未登记</span>'; ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo ($row['rec'] && $row['rec']['registered_at']) ? htmlspecialchars($row['rec']['registered_at']) : '—'; ?></td>
                    <td><?php echo ($row['rec'] && $row['rec']['eval_value'] !== null && $row['rec']['eval_value'] !== '') ? htmlspecialchars($row['rec']['eval_value']) : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$per): ?>
                <tr><td colspan="5" style="text-align:center;color:#999;">暂无项目</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <h3>⬇ 导出</h3>
    <a class="btn btn-sm btn-outline" style="text-decoration:none;"
       href="export.php?action=download&dtype=student_detail&id=<?php echo intval($student['id']); ?>&class_id=<?php echo $class_id; ?>">⬇ 导出登记情况（Excel：是否登记/登记时间/评价内容）</a>
</div>
<?php endif; ?>

<!-- 学生每次明细弹窗（登记矩阵点姓名列）：该生每次状态/说明 -->
<div class="modal-mask" id="stuMatrixModal" onclick="if(event.target===this)this.classList.remove('show')">
<div class="modal" style="max-width:600px;">
<h3 id="smx_title">👤 学生明细</h3>
<div class="table-wrap" style="max-height:52vh;overflow:auto;">
<table class="data-table">
    <thead><tr><th style="min-width:150px;"><?php // 行标签由 JS 按模式填充（日期/次） ?></th><th style="width:90px;">状态</th><th>说明</th></tr></thead>
    <tbody id="smx_body"><tr><td colspan="3" style="text-align:center;color:#999;">—</td></tr></tbody>
</table>
</div>
<div class="modal-actions">
    <button type="button" class="btn" onclick="document.getElementById('stuMatrixModal').classList.remove('show')">关闭</button>
</div>
</div>
</div>

<!-- 统计图表弹窗：数据 × 图型自由切换（班级总览 / 单项目 / 学生视图） -->
<div class="modal-mask" id="chartModal">
    <div class="modal" style="width:760px;max-width:92vw;">
        <h3>📊 统计图表<button type="button" class="hint-q" onclick="toggleHint(event, '<b>统计图表说明</b><br>· 各项目登记率：一次性=已登记人数 ÷ 全班人数；打卡项目=有打卡记录人数 ÷ 全班人数<br>· 评价分布=各评价内容出现的人次（先选项目再查看）<br>· 单项目视图另有每日打卡人数 / 每生打卡天数 / 登记状态分布<br>· 图表右上角可保存为图片')">?</button></h3>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
            <label style="display:inline-flex;align-items:center;gap:6px;font-size:14px;">
                数据
                <select id="chart_ds" class="form-control" style="width:auto;padding:4px 8px;" onchange="onChartDsChange()">
                    <?php foreach ($chart_sets as $ck => $cl): ?>
                    <option value="<?php echo $ck; ?>"><?php echo htmlspecialchars($cl); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($view === 'class'): ?>
            <label id="chart_proj_wrap" style="display:none;align-items:center;gap:6px;font-size:14px;">
                项目
                <select id="chart_proj" class="form-control" style="width:auto;max-width:220px;padding:4px 8px;" onchange="renderChart()">
                    <?php foreach (array_keys($chart_eval) as $cpid): ?>
                    <option value="<?php echo $cpid; ?>"><?php echo htmlspecialchars($chart_eval[$cpid]['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <div style="flex:1;"></div>
            <div style="display:flex;gap:6px;">
                <button type="button" class="btn btn-sm btn-outline chart-type" data-t="bar" onclick="setChartType(this)">条形图</button>
                <button type="button" class="btn btn-sm btn-outline chart-type" data-t="line" onclick="setChartType(this)">折线图</button>
                <button type="button" class="btn btn-sm btn-outline chart-type" data-t="pie" onclick="setChartType(this)">饼图</button>
            </div>
        </div>
        <div id="chartCanvas" style="height:360px;width:100%;"></div>
        <div id="chart_empty" style="display:none;text-align:center;color:#999;padding:30px 0;">暂无可绘图的数据</div>
    </div>
</div>

<script src="assets/js/echarts.min.js"></script>
<script>
function openModal(id) {
    document.getElementById(id).classList.add('show');
    if (id === 'chartModal') renderChart();
}
function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}
// 点击遮罩空白处关闭
document.querySelectorAll('.modal-mask').forEach(function (m) {
    m.addEventListener('click', function (e) { if (e.target === m) m.classList.remove('show'); });
});

// ===== 学生每次明细弹窗（登记矩阵点姓名列）：该生每次状态/说明 =====
var MX_DATA = <?php echo $MX_JSON ? json_encode($MX_JSON, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'null'; ?>;
function smxEsc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}
function openStuMatrix(sid) {
    if (!MX_DATA || !MX_DATA.students) return;
    var st = MX_DATA.students[sid];
    if (!st) return;
    document.getElementById('smx_title').textContent = '👤 ' + st.name + '（座号 ' + (st.seat || '—') + '）· 完成 ' + st.cnt + ' / ' + MX_DATA.rows.length;
    var head = document.querySelector('#stuMatrixModal thead th');
    if (head) head.textContent = MX_DATA.label === '日期' ? '日期' : MX_DATA.label;
    var missTxt = MX_DATA.label === '日期' ? '未打卡' : '未登记';
    var h = [];
    MX_DATA.rows.forEach(function (r, i) {
        var c = st.cells[i];
        var info = st.info[i] || '';
        h.push('<tr><td style="white-space:nowrap;">' + smxEsc(r.label) + '<span style="color:#999;">（' + smxEsc(r.sub) + '）</span></td>'
            + '<td style="font-weight:bold;color:' + (c ? (c.c === 'ok' ? '#27ae60' : (c.c === 'bad' ? '#e74c3c' : '#888')) : '#bbb') + ';">' + (c ? smxEsc(c.t) : '×') + '</td>'
            + '<td style="font-size:12px;color:#666;">' + (info ? smxEsc(info) : missTxt) + '</td></tr>');
    });
    document.getElementById('smx_body').innerHTML = h.join('');
    openModal('stuMatrixModal');
}

// ===== 批量导出所选项目（班级总览） =====
function projAll(master) {
    document.querySelectorAll('.proj-ck').forEach(function (c) { c.checked = master.checked; });
    updateProjSel();
}
function projSelCount() { return document.querySelectorAll('.proj-ck:checked').length; }
function updateProjSel() {
    var tip = document.getElementById('projSelTip');
    if (tip) tip.textContent = projSelCount() > 0 ? ('已选 ' + projSelCount() + ' 个项目') : '未勾选项目';
}
function exportProjects() {
    var ids = Array.prototype.map.call(document.querySelectorAll('.proj-ck:checked'), function (c) { return c.value; });
    if (!ids.length) { showToast('请先在表格中勾选要导出的项目', 'error'); return; }
    window.location = 'export.php?action=download&dtype=projects&ids=' + ids.join(',');
}

// ===== 答题卡单次分析切换（次数/班级），保持内嵌模式 =====
function omrJump(key, val) {
    var p = new URLSearchParams(location.search);
    p.set(key, val);
    location.search = p.toString();
}

// ===== 统计图表（ECharts：条形 / 折线 / 饼图，数据 × 图型自由切换，右上角可存图） =====
var DATA = <?php echo json_encode($chart_data, JSON_UNESCAPED_UNICODE); ?>;
var EVAL = <?php echo json_encode($chart_eval, JSON_UNESCAPED_UNICODE); ?>;
var COLORS = ['#667eea', '#27ae60', '#f39c12', '#e74c3c', '#9b59b6', '#00bcd4', '#ff7043', '#8d6e63', '#5c6bc0', '#66bb6a'];
var chartType = 'bar';
var chartInst = null;

function setChartType(btn) {
    chartType = btn.getAttribute('data-t');
    document.querySelectorAll('.chart-type').forEach(function (b) { b.classList.remove('active'); });
    btn.classList.add('active');
    renderChart();
}
function onChartDsChange() {
    var ds = document.getElementById('chart_ds').value;
    var wrap = document.getElementById('chart_proj_wrap');
    if (wrap) wrap.style.display = ds === 'eval' ? 'inline-flex' : 'none';
    renderChart();
}
function chartData() {
    var ds = document.getElementById('chart_ds').value;
    if (ds === 'eval') {
        var sel = document.getElementById('chart_proj');
        if (sel) {
            var e = EVAL[sel.value];
            if (!e) return null;
            var ls = [], vs = [];
            (e.items || []).forEach(function (it) { ls.push(it.v); vs.push(it.cnt); });
            return { labels: ls, values: vs };
        }
    }
    var d = DATA[ds];
    return d ? { labels: d.labels, values: d.values } : null;
}
function getChart() {
    var el = document.getElementById('chartCanvas');
    if (!chartInst) {
        chartInst = echarts.init(el);
        window.addEventListener('resize', function () { if (chartInst) chartInst.resize(); });
    }
    return chartInst;
}
function chartBase() {
    return {
        tooltip: { trigger: 'axis' },
        toolbox: { right: 6, top: 2, feature: { saveAsImage: { title: '存图' } } },
        grid: { left: 54, right: 26, top: 34, bottom: 76 }
    };
}
function chartAxis(d) {
    var n = d.labels.length;
    return {
        type: 'category',
        data: d.labels.map(String),
        axisLabel: {
            interval: 0, fontSize: 11,
            rotate: n > 8 ? 38 : 0,
            formatter: function (v) { return trunc(v, 8); }
        }
    };
}
function renderChart() {
    var d = chartData();
    var empty = !d || !d.labels.length || d.values.every(function (v) { return v === 0; });
    document.getElementById('chart_empty').style.display = empty ? '' : 'none';
    document.getElementById('chartCanvas').style.display = empty ? 'none' : '';
    if (empty) { if (chartInst) chartInst.clear(); return; }
    if (typeof echarts === 'undefined') {
        document.getElementById('chart_empty').style.display = '';
        document.getElementById('chart_empty').textContent = '图表库（assets/js/echarts.min.js）未找到，图表不可用';
        return;
    }
    var inst = getChart();
    var opt;
    if (chartType === 'pie') {
        opt = Object.assign(chartBase(), {
            tooltip: { trigger: 'item', formatter: '{b}：{c}（{d}%）' },
            legend: { type: 'scroll', bottom: 6, textStyle: { fontSize: 11 } },
            grid: { left: 10, right: 10, top: 10, bottom: 10 },
            series: [{
                type: 'pie', radius: ['36%', '64%'], center: ['50%', '44%'],
                label: { formatter: '{b}\n{c}', fontSize: 11 },
                data: d.labels.map(function (l, i) {
                    return { name: String(l), value: d.values[i], itemStyle: { color: COLORS[i % COLORS.length] } };
                })
            }]
        });
    } else if (chartType === 'line') {
        opt = Object.assign(chartBase(), {
            xAxis: chartAxis(d),
            yAxis: { type: 'value' },
            dataZoom: d.labels.length > 30 ? [{ type: 'inside' }] : [],
            series: [{
                name: '数值', type: 'line', data: d.values, smooth: true, symbolSize: 7,
                itemStyle: { color: '#667eea' }, lineStyle: { width: 2.5, color: '#667eea' },
                areaStyle: { color: '#667eea', opacity: 0.12 },
                label: { show: d.labels.length <= 15, position: 'top', fontSize: 10, color: '#666' }
            }]
        });
    } else {
        opt = Object.assign(chartBase(), {
            xAxis: chartAxis(d),
            yAxis: { type: 'value' },
            dataZoom: d.labels.length > 30 ? [{ type: 'inside' }] : [],
            series: [{
                name: '数值', type: 'bar', barMaxWidth: 42,
                data: d.values.map(function (v, i) {
                    return { value: v, itemStyle: { color: COLORS[i % COLORS.length] } };
                }),
                label: { show: d.labels.length <= 20, position: 'top', fontSize: 10, color: '#666' }
            }]
        });
    }
    inst.setOption(opt, true);
    setTimeout(function () { if (chartInst) chartInst.resize(); }, 60);   // 弹窗展开动画后校正尺寸
}
function trunc(s, n) {
    s = String(s);
    return s.length > n ? s.slice(0, n - 1) + '…' : s;
}
// 默认选中第一个图型按钮
(function () {
    var first = document.querySelector('.chart-type');
    if (first) first.classList.add('active');
})();
</script>
<?php page_help('登记统计', [
    ['h' => '三种视图', 'items' => [
        '<b>班级总览</b>（stats.php?class_id=班级）：「各项目登记情况」按 📅 打卡项目 / 📌 一次性项目 分组列表，展示打卡天数 / 人次 / 人数或已登记 / 未登记 / 登记率',
        '<b>单项目统计</b>（点击项目行的「📊 单项统计」）：打卡项目显示每日打卡情况表与打卡天数 / 人次 / 去重人数汇总和每生打卡天数图表；一次性项目显示登记状态分布与学生明细',
        '<b>学生统计</b>（点击学生姓名或在统计报表中心进入）：该生各项目登记 / 评价 / 累计打卡天数明细',
    ]],
    ['h' => '导出', 'items' => [
        '班级总览：表格首列勾选项目后点「⬇ 批量导出所选项目」，多项目打包 ZIP（每项目一个 Excel 工作簿，仅选 1 个直接下载）；打卡项目行内还可单独导出「⬇ 每日明细」',
        '单项目视图：下方「⬇ 导出」面板提供详细数据（Excel 三分表）与每日打卡明细',
        '学生视图：「⬇ 导出登记情况」导出该生全部项目登记情况（Excel 三分表，打卡项目按日期展开）',
        '导出需具备该项目的操作权限；无权限时仅可查看',
    ]],
    ['h' => '统计图表', 'items' => [
        '三种视图均提供【📊 统计图表】弹窗（ECharts）：数据集 × 条形 / 折线 / 饼图自由切换，标签多时自动倾斜并可缩放，右上角可存为图片',
        '班级总览弹窗内「评价分布」可再选具体项目；打卡项目的「每日打卡人数」超过 30 个日期时支持框选缩放',
    ]],
]);
page_footer(); ?>
