<?php
/**
 * 公开查询端（无需登录）：
 *  - 输入 班级码 + 学生编号 + 姓名，查询该班该学生的全部登记信息
 *  - 仅显示 allow_query=1 的项目（项目级开关，默认允许；班级项目列表上方「⚙ 查询设置」可勾选）
 *  - 教师视图（tview=1&sid=学生id）：已登录教师且对该班级有查看权限时，不受「公开查询设置」限制，
 *    呈现全部项目（跳过 query_enabled 总开关与 allow_query 项目开关）；学生名单页「查阅记录」弹窗内嵌使用
 *  - 三个选项卡：本日情况（默认，仅本日有登记/评价的项目）/ 历史情况（全部项目：统计矩阵+图表+折叠卡）/ 综合报告（个人总评价汇总+教师点评）
 *  - 统计矩阵：行=每次（打卡项目=日期，按次/答题/举牌/答题卡=第N次/第N题），列=项目，末列=评价内容汇总
 *  - 每个项目独立折叠卡（默认折叠，本日有更新在标题加「有更新」徽章；多项次项目折中折），按类型呈现：
 *    单次=登记/评价；打卡=日期chips+每日评价；多次=逐次；答题/举牌=逐题对错+正确率；答题卡=逐题次得分+每题作答表格
 *  - 图表：各项目次数（柱）+ 评价分布（饼）+ 近30天登记趋势（折线）+ 答题卡得分率走势（折线），ECharts 懒初始化
 *  - 三项信息全部匹配才显示记录，防止凭单项信息猜测
 */
require_once 'includes/db_config.php';
require_once 'includes/functions.php';

$conn = getConnection();

$class_code = trim(strval($_GET['c'] ?? $_POST['c'] ?? ''));
$student_no = trim(strval($_GET['no'] ?? ''));
$student_name = trim(strval($_GET['name'] ?? ''));
$tview_sid = intval($_GET['sid'] ?? 0);   // 教师视图：按学生 id 直查（限本班）
// 教师视图判定（tview=1）：须已登录教师；班级查看权限在取得班级后再校验（与学生名单页 students.php 同一道门）
if (session_status() === PHP_SESSION_NONE) session_start();
$sess_tid = intval($_SESSION['teacher_id'] ?? 0);
$tview_req = (intval($_GET['tview'] ?? 0) === 1 && $sess_tid > 0);
$tview = false;
$queried = ($student_no !== '' && $student_name !== '') || ($tview_req && $tview_sid > 0);

$class = null;
$student = null;
$projects = [];     // 每项目完整数据（base/days/rounds/omr/evals/events/comment/...）
$mx_rows = [];      // 统计矩阵行：['key','date','sub','rno','cells'=>[pid=>值],'evals'=>[pid=>值]]
$pie_counts = [];   // 评价分布
$trend_map = [];    // 近30天登记趋势 date=>次数
$omr_lines = [];    // 答题卡得分率折线：[name, [rate...]]（与 rounds 对齐）
$error = '';
$query_closed = false; // 班级存在但总开关未开启

$TODAY = date('Y-m-d');

if ($class_code === '') {
    $error = '请提供班级码';
} else {
    $stmt = mysqli_prepare($conn, "SELECT id, name, school_id, query_enabled FROM classes WHERE class_code = ? AND deleted_at IS NULL");
    mysqli_stmt_bind_param($stmt, "s", $class_code);
    mysqli_stmt_execute($stmt);
    $class = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$class) {
        $error = '班级码不存在，请核对后重试';
    } else {
        // 教师视图权限：与学生名单页同一道查看校验；未登录 / 无权限 / 账号禁用均回退家长视图口径
        if ($tview_req && !is_teacher_disabled($conn, $sess_tid) && can_view_class($conn, $sess_tid, intval($class['id']))) {
            $tview = true;
        }
        if (!$tview && !intval($class['query_enabled'])) {
            $query_closed = true; // 总开关关闭：不提供查询，提示未开启
            $error = '未开启公开查询：该班级暂未开放线上查询功能，请联系班级教师开启。';
        }
    }
}

if ($class && $queried && !$query_closed) {
    if ($tview && $tview_sid > 0) {
        // 教师视图：按学生 id 直查（限本班学生）
        $stmt = mysqli_prepare($conn, "SELECT id, seat_no, name, student_no FROM students WHERE class_id = ? AND id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, "ii", $class['id'], $tview_sid);
    } else {
        $stmt = mysqli_prepare($conn, "SELECT id, seat_no, name, student_no FROM students
                                       WHERE class_id = ? AND student_no = ? AND name = ?
                                       LIMIT 1");
        mysqli_stmt_bind_param($stmt, "iss", $class['id'], $student_no, $student_name);
    }
    mysqli_stmt_execute($stmt);
    $student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$student) {
        $error = '未找到该学生，请核对编号和姓名';
    } else {
        if ($tview && $tview_sid > 0) {
            // 按 id 直查：回填编号 / 姓名（表单预填 + 信息条展示）
            $student_no = strval($student['student_no']);
            $student_name = strval($student['name']);
        }
        $sid = intval($student['id']);
        $class_id = intval($class['id']);

        // 教师点评（项目维度）：综合报告展示
        $cm_all = [];
        $res = mysqli_query($conn, "SELECT project_id, content FROM student_comments WHERE student_id = {$sid}");
        while ($r = mysqli_fetch_assoc($res)) $cm_all[intval($r['project_id'])] = strval($r['content']);

        // 覆盖该班级的项目（本班项目）
        // ⚠ 不再 INNER JOIN records 过滤 registered=1：历史日期评价写 records.registered=0、答题卡等按次流程可能不写 records 基表，
        //    改为取全量项目后按「基表 / 按次 / 打卡 / 答题卡」四源活动判定，避免漏项目
        // 教师视图（$tview）：不受 allow_query 项目开关限制，呈现全部项目
        $allow_sql = $tview ? '' : 'AND p.allow_query = 1 ';
        $stmt = mysqli_prepare($conn, "SELECT p.id, p.name, p.scope, p.mode FROM projects p
                                       WHERE p.deleted_at IS NULL {$allow_sql} AND p.class_id = {$class_id}");
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $plist = [];
        while ($row = mysqli_fetch_assoc($res)) $plist[] = $row;
        mysqli_stmt_close($stmt);

        foreach ($plist as $prow) {
            $pid = intval($prow['id']);
            $mode = strval($prow['mode']);
            $proj = [
                'id' => $pid, 'name' => $prow['name'], 'scope' => $prow['scope'], 'mode' => $mode,
                'base' => null, 'days' => [], 'rounds' => [], 'round_nos' => [], 'round_titles' => [],
                'correct' => [], 'omr' => [], 'omr_key' => [], 'omr_kind' => [], 'omr_q_list' => [],
                'evals' => [], 'events' => [], 'cnt' => 0, 'today' => false,
                'comment' => strval($cm_all[$pid] ?? ''),
            ];

            // 基表记录（可能 registered=0 但留有历史评价汇总）
            $stmt2 = mysqli_prepare($conn, "SELECT registered, registered_at, registered_by, eval_value, eval_at FROM records WHERE project_id = ? AND student_id = ?");
            mysqli_stmt_bind_param($stmt2, "ii", $pid, $sid);
            mysqli_stmt_execute($stmt2);
            $proj['base'] = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt2));
            mysqli_stmt_close($stmt2);

            if ($mode === 'daily') {
                // 打卡项目：record_days 每天一条（含当日评价）
                $stmt2 = mysqli_prepare($conn, "SELECT reg_date, eval_value, eval_at FROM record_days WHERE project_id = ? AND student_id = ? ORDER BY reg_date DESC");
                mysqli_stmt_bind_param($stmt2, "ii", $pid, $sid);
                mysqli_stmt_execute($stmt2);
                $res2 = mysqli_stmt_get_result($stmt2);
                while ($d = mysqli_fetch_assoc($res2)) {
                    $d['reg_date'] = strval($d['reg_date']);
                    $proj['days'][] = $d;
                    $ev = strval($d['eval_value']);
                    $proj['events'][] = ['date' => $d['reg_date'], 'kind' => '打卡', 'round' => null, 'eval' => $ev, 'eval_at' => strval($d['eval_at']), 'by' => ''];
                    if ($ev !== '') {
                        $proj['evals'][] = ['label' => $d['reg_date'], 'eval' => $ev];
                        $pie_counts[$ev] = ($pie_counts[$ev] ?? 0) + 1;
                    }
                    if ($d['reg_date'] === $TODAY) $proj['today'] = true;
                    $trend_map[$d['reg_date']] = ($trend_map[$d['reg_date']] ?? 0) + 1;
                }
                mysqli_stmt_close($stmt2);
                $proj['cnt'] = count($proj['days']);
            } else {
                // 按次/答题/举牌/答题卡：record_rounds 每次/每题次一条
                $stmt2 = mysqli_prepare($conn, "SELECT round_no, registered_at, registered_by, eval_value, eval_at FROM record_rounds WHERE project_id = ? AND student_id = ? ORDER BY round_no ASC");
                mysqli_stmt_bind_param($stmt2, "ii", $pid, $sid);
                mysqli_stmt_execute($stmt2);
                $res2 = mysqli_stmt_get_result($stmt2);
                while ($r2 = mysqli_fetch_assoc($res2)) {
                    $rno = intval($r2['round_no']);
                    $r2['round_no'] = $rno;
                    $proj['rounds'][$rno] = $r2;
                    $d = strval($r2['registered_at']) !== '' ? substr(strval($r2['registered_at']), 0, 10) : '';
                    $ev = strval($r2['eval_value']);
                    $proj['events'][] = ['date' => $d, 'kind' => '登记', 'round' => $rno, 'eval' => $ev, 'eval_at' => strval($r2['eval_at']), 'by' => strval($r2['registered_by'])];
                    if ($ev !== '') {
                        $proj['evals'][] = ['label' => '第' . $rno . '次', 'eval' => $ev];
                        $pie_counts[$ev] = ($pie_counts[$ev] ?? 0) + 1;
                    }
                    if ($d === $TODAY) $proj['today'] = true;
                    if ($d !== '') $trend_map[$d] = ($trend_map[$d] ?? 0) + 1;
                }
                mysqli_stmt_close($stmt2);

                // 轮次定义（只读查询，不用 project_rounds_list 避免其自动建轮次的写副作用）
                $res2 = mysqli_query($conn, "SELECT round_no, correct_opts, title FROM project_rounds WHERE project_id = {$pid} ORDER BY round_no ASC");
                while ($r2 = mysqli_fetch_assoc($res2)) {
                    $rno = intval($r2['round_no']);
                    $proj['round_nos'][] = $rno;
                    if (strval($r2['title']) !== '') $proj['round_titles'][$rno] = strval($r2['title']);
                    $co = strtoupper(preg_replace('/[^A-E]/', '', strval($r2['correct_opts'])));
                    if ($co !== '') $proj['correct'][$rno] = $co;
                }

                if ($mode === 'omr') {
                    // 答题卡：每题次识别结果（answers JSON + score "g/t"）
                    $stmt2 = mysqli_prepare($conn, "SELECT round_no, answers, score FROM omr_results WHERE project_id = ? AND student_id = ? AND student_id IS NOT NULL");
                    mysqli_stmt_bind_param($stmt2, "ii", $pid, $sid);
                    mysqli_stmt_execute($stmt2);
                    $res2 = mysqli_stmt_get_result($stmt2);
                    while ($r2 = mysqli_fetch_assoc($res2)) {
                        $rno = intval($r2['round_no']);
                        $ans = json_decode(strval($r2['answers']), true);
                        $g = 0.0; $t = 0.0;
                        $parts = explode('/', strval($r2['score']));
                        if (count($parts) === 2 && floatval($parts[1]) > 0) { $g = floatval($parts[0]); $t = floatval($parts[1]); }
                        $proj['omr'][$rno] = ['answers' => (is_array($ans) ? $ans : []), 'g' => $g, 't' => $t];
                        if (!isset($proj['rounds'][$rno])) $proj['rounds'][$rno] = ['round_no' => $rno, 'registered_at' => null, 'registered_by' => 'scan', 'eval_value' => null, 'eval_at' => null];
                        $proj['events'][] = ['date' => '', 'kind' => '批改', 'round' => $rno, 'eval' => $t > 0 ? (floatval($parts[0]) . '/' . floatval($parts[1])) : '', 'eval_at' => '', 'by' => 'scan'];
                    }
                    mysqli_stmt_close($stmt2);
                    // 答案键（与识别/统计同口径）：绑定模板题组 + omr_answers 独立录入补未设题
                    $omr_key = []; $omr_kind = [];
                    $bind_tid = intval(get_setting($conn, 'omr_tpl_bind_' . $pid, '0'));
                    if ($bind_tid > 0) {
                        $tres = mysqli_query($conn, "SELECT layout FROM omr_templates WHERE id = {$bind_tid} AND project_id = {$pid}");
                        $trow = $tres ? mysqli_fetch_assoc($tres) : null;
                        $lay = $trow ? json_decode(strval($trow['layout']), true) : null;
                        foreach ((is_array($lay) && is_array($lay['sections'] ?? null) ? $lay['sections'] : []) as $sec) {
                            if (!is_array($sec)) continue;
                            $k = strval($sec['kind'] ?? 'single');
                            if ($k === 'blank' || $k === 'short') continue;
                            $start = intval($sec['start'] ?? 1); $cnt2 = min(500, max(1, intval($sec['count'] ?? 0)));
                            $key = is_array($sec['key'] ?? null) ? $sec['key'] : [];
                            for ($q = $start; $q < $start + $cnt2; $q++) {
                                $qk = strval($q);
                                $omr_kind[$qk] = ($k === 'multi') ? 'multi' : 'single';
                                $v = strtoupper(preg_replace('/[^A-Ea-e]/', '', strval($key[$qk] ?? '')));
                                if ($v !== '') $omr_key[$qk] = $v;
                            }
                        }
                    }
                    $ares = @mysqli_query($conn, "SELECT answers FROM omr_answers WHERE project_id = {$pid}");
                    if ($ares && ($arow = mysqli_fetch_assoc($ares))) {
                        $saved_tid = get_setting($conn, 'omr_answers_tid_' . $pid, '');
                        if ($saved_tid === '' || intval($saved_tid) === $bind_tid) {
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
                    $proj['omr_key'] = $omr_key;
                    $proj['omr_kind'] = $omr_kind;
                    $qlist = array_keys($omr_key);
                    foreach ($proj['omr'] as $orow) {
                        foreach (array_keys($orow['answers']) as $q) {
                            $qk = preg_replace('/[^0-9]/', '', strval($q));
                            if ($qk !== '' && !in_array($qk, $qlist, true)) $qlist[] = $qk;
                        }
                    }
                    sort($qlist, SORT_NUMERIC);
                    $proj['omr_q_list'] = $qlist;
                }

                $proj['cnt'] = count($proj['rounds']);
                if (!$proj['rounds'] && $proj['base']) {
                    // 无按次记录：base 记录即一次事件
                    $bd = strval($proj['base']['registered_at']) !== '' ? substr(strval($proj['base']['registered_at']), 0, 10) : '';
                    $bev = strval($proj['base']['eval_value']);
                    $proj['events'][] = ['date' => $bd, 'kind' => '登记', 'round' => null, 'eval' => $bev, 'eval_at' => strval($proj['base']['eval_at']), 'by' => strval($proj['base']['registered_by'])];
                    if ($bev !== '') {
                        $proj['evals'][] = ['label' => $bd, 'eval' => $bev];
                        $pie_counts[$bev] = ($pie_counts[$bev] ?? 0) + 1;
                    }
                    if ($bd === $TODAY) $proj['today'] = true;
                    if ($bd !== '') $trend_map[$bd] = ($trend_map[$bd] ?? 0) + 1;
                    $proj['cnt'] = 1;
                }
            }

            // 活动判定：基表已登记或有评价 / 有打卡 / 有按次 / 有答题卡识别 —— 全无则不呈现
            $has_activity = ($proj['base'] && (intval($proj['base']['registered']) === 1 || strval($proj['base']['eval_value']) !== ''))
                || count($proj['days']) > 0 || count($proj['rounds']) > 0 || count($proj['omr']) > 0;
            if (!$has_activity) continue;

            // 事件与评价历史最新在前
            usort($proj['events'], function ($a, $b) { return strcmp($b['date'], $a['date']); });
            usort($proj['evals'], function ($a, $b) { return strcmp($b['label'], $a['label']); });
            $projects[] = $proj;
        }
        // 项目卡：本日有更新的在前，其余按最近活动时间倒序
        usort($projects, function ($a, $b) {
            if ($a['today'] !== $b['today']) return $a['today'] ? -1 : 1;
            $ta = strval($a['base']['registered_at'] ?? '');
            $tb = strval($b['base']['registered_at'] ?? '');
            return strcmp($tb, $ta);
        });

        // ===== 统计矩阵：行=每次（打卡=日期 / 按次类=第N次·第N题 / 单次=登记），列=项目，末列=评价内容 =====
        foreach ($projects as $p) {
            $pid = $p['id'];
            if ($p['mode'] === 'daily') {
                foreach ($p['days'] as $d) {
                    $k = 'd:' . $d['reg_date'];
                    if (!isset($mx_rows[$k])) $mx_rows[$k] = ['key' => $k, 'date' => $d['reg_date'], 'sub' => '', 'rno' => 0, 'cells' => [], 'evals' => []];
                    $ev = strval($d['eval_value']);
                    $mx_rows[$k]['cells'][$pid] = $ev !== '' ? $ev : '✓';
                    if ($ev !== '') $mx_rows[$k]['evals'][$pid] = $ev;
                }
            } elseif ($p['rounds']) {
                $is_omr = $p['mode'] === 'omr';
                $round_label = ($p['mode'] === 'multi') ? '次' : '题';
                foreach ($p['rounds'] as $rno => $r2) {
                    $k = 'r:' . $rno;
                    if (!isset($mx_rows[$k])) $mx_rows[$k] = ['key' => $k, 'date' => '', 'sub' => '第' . $rno . $round_label, 'rno' => $rno, 'cells' => [], 'evals' => []];
                    $d = strval($r2['registered_at']) !== '' ? substr(strval($r2['registered_at']), 0, 10) : '';
                    if ($d !== '' && $d > $mx_rows[$k]['date']) $mx_rows[$k]['date'] = $d;
                    $cell = '✓';
                    if ($is_omr && isset($p['omr'][$rno]) && $p['omr'][$rno]['t'] > 0) {
                        $cell = rtrim(rtrim(number_format($p['omr'][$rno]['g'], 1, '.', ''), '0'), '.') . '/' . rtrim(rtrim(number_format($p['omr'][$rno]['t'], 1, '.', ''), '0'), '.');
                    } elseif (strval($r2['eval_value']) !== '') {
                        $cell = strval($r2['eval_value']);
                    }
                    $mx_rows[$k]['cells'][$pid] = $cell;
                    if ($cell !== '✓') $mx_rows[$k]['evals'][$pid] = $cell;
                }
            } elseif ($p['base']) {
                $bd = strval($p['base']['registered_at']) !== '' ? substr(strval($p['base']['registered_at']), 0, 10) : '';
                $k = 'b:' . $pid;
                if (!isset($mx_rows[$k])) $mx_rows[$k] = ['key' => $k, 'date' => $bd, 'sub' => '登记', 'rno' => 0, 'cells' => [], 'evals' => []];
                $bev = strval($p['base']['eval_value']);
                $mx_rows[$k]['cells'][$pid] = $bev !== '' ? $bev : '✓';
                if ($bev !== '') $mx_rows[$k]['evals'][$pid] = $bev;
            }
        }
        // 行排序：日期倒序 → 次号倒序
        usort($mx_rows, function ($a, $b) {
            $c = strcmp($b['date'], $a['date']);
            if ($c !== 0) return $c;
            if ($a['rno'] !== $b['rno']) return $b['rno'] - $a['rno'];
            return strcmp($a['key'], $b['key']);
        });

        // ===== 答题卡得分率折线（每项目一条线，x=第N题） =====
        foreach ($projects as $p) {
            if ($p['mode'] !== 'omr' || !$p['round_nos']) continue;
            $rates = []; $has = false;
            foreach ($p['round_nos'] as $rno) {
                $o = $p['omr'][$rno] ?? null;
                if ($o && $o['t'] > 0) { $rates[] = round($o['g'] / $o['t'] * 100, 1); $has = true; }
                else $rates[] = null;
            }
            if ($has) $omr_lines[] = ['name' => $p['name'], 'data' => $rates, 'rounds' => $p['round_nos']];
        }
    }
}

$scope_names = get_scope_names();
$by_map = ['scan' => '扫码', 'click' => '点击', 'batch' => '批量', 'page' => '页面', 'api' => '接口'];
$mode_names = ['count' => '单次登记', 'daily' => '每日打卡', 'multi' => '多次登记', 'quiz' => '答题', 'raise' => '举牌', 'omr' => '答题卡'];
$mode_colors = ['count' => '#667eea', 'daily' => '#3fb896', 'multi' => '#8f5fd6', 'quiz' => '#e6a23c', 'raise' => '#e67e22', 'omr' => '#e74c3c'];

// 近30天趋势（缺数日期补 0）
$trend_labels = []; $trend_values = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} day"));
    $trend_labels[] = substr($d, 5);   // MM-DD
    $trend_values[] = intval($trend_map[$d] ?? 0);
}

$qp_total_cnt = 0; $qp_total_evals = 0; $qp_last_date = ''; $qp_today_cnt = 0; $qp_has_comment = false;
foreach ($projects as $p) {
    $qp_total_cnt += $p['cnt'];
    $qp_total_evals += count($p['evals']);
    if ($p['today']) $qp_today_cnt++;
    if ($p['comment'] !== '') $qp_has_comment = true;
    foreach ($p['events'] as $e) { if ($e['date'] !== '' && $e['date'] > $qp_last_date) $qp_last_date = $e['date']; }
}

/**
 * 渲染单个项目折叠卡（本日/历史两 tab 共用；$today_only=true 时只呈现本日相关内容）
 */
function qj_render_card(array $rec, bool $today_only, string $tab, array $ctx): string {
    $pid = $rec['id'];
    $mode = $rec['mode'];
    $TODAY = $ctx['today'];
    $by_map = $ctx['by_map'];
    $mode_names = $ctx['mode_names'];
    $mode_colors = $ctx['mode_colors'];
    $cid = 'pc_' . $tab . '_' . $pid;
    $is_omr = $mode === 'omr';

    // —— 标题 mini 摘要 ——
    $mini = '';
    $last = $rec['evals'][0] ?? null;
    if ($mode === 'daily') {
        $mini = '打卡 ' . count($rec['days']) . ' 天' . (in_array($TODAY, array_column($rec['days'], 'reg_date'), true) ? ' · 本日已打卡' : ' · 本日未打卡');
    } elseif ($is_omr) {
        $g = 0.0; $t = 0.0;
        foreach ($rec['omr'] as $o) { $g += $o['g']; $t += $o['t']; }
        $mini = '已批改 ' . count($rec['omr']) . ' 题' . ($t > 0 ? ' · 总得分率 ' . round($g / $t * 100, 1) . '%' : '');
    } elseif (in_array($mode, ['quiz', 'raise'], true)) {
        $ans = 0; $ok = 0;
        foreach ($rec['rounds'] as $rno => $r2) {
            $ev = strval($r2['eval_value']);
            if ($ev === '') continue;
            if (isset($rec['correct'][$rno])) {
                $ans++;
                if (in_array($ev, str_split($rec['correct'][$rno]), true)) $ok++;
            }
        }
        $mini = '答题 ' . count($rec['rounds']) . ' 题' . ($ans > 0 ? ' · 答对 ' . $ok . ' 题（正确率 ' . round($ok / $ans * 100) . '%）' : '');
    } elseif ($mode === 'multi') {
        $mini = '已登记 ' . count($rec['rounds']) . '/' . max(count($rec['round_nos']), count($rec['rounds'])) . ' 次';
    } else {
        $mini = intval($rec['base']['registered'] ?? 0) === 1 ? '已登记' : '未登记';
    }
    if ($last) $mini .= ' · 最新评价 ' . $last['eval'];

    ob_start();
    ?>
    <div class="pcard" id="<?php echo $cid; ?>">
        <div class="pc-head" onclick="tgPc('<?php echo $cid; ?>', this)">
            <span class="pc-arrow">▸</span>
            <span class="pc-name"><?php echo htmlspecialchars($rec['name']); ?></span>
            <span class="pc-mode" style="color:<?php echo $mode_colors[$mode] ?? '#667eea'; ?>;background:<?php echo ($mode_colors[$mode] ?? '#667eea') . '1a'; ?>;"><?php echo $mode_names[$mode] ?? $mode; ?></span>
            <?php if ($rec['today']): ?><span class="pc-new">有更新</span><?php endif; ?>
            <span class="pc-mini"><?php echo htmlspecialchars($mini); ?></span>
        </div>
        <div class="pc-body">
    <?php
    // —— 类型化内容 ——
    if ($mode === 'daily') {
        $today_row = null;
        foreach ($rec['days'] as $d) if ($d['reg_date'] === $TODAY) { $today_row = $d; break; }
        if ($today_only) {
            if ($today_row) {
                echo '<div class="pc-line">本日（' . htmlspecialchars($TODAY) . '）已打卡' . (strval($today_row['eval_value']) !== '' ? ' · 评价：<b style="color:#e67e22;">' . htmlspecialchars(strval($today_row['eval_value'])) . '</b>' : '') . '</div>';
            } else {
                echo '<div class="pc-line" style="color:#98a0b3;">本日暂无打卡记录</div>';
            }
        } else {
            echo '<div class="pc-line"><b style="color:#3fb896;">累计打卡 ' . count($rec['days']) . ' 天</b></div>';
            if ($rec['days']) {
                echo '<div class="pc-chips">';
                foreach (array_slice($rec['days'], 0, 30) as $d) {
                    $cls = strval($d['eval_value']) !== '' ? ' ev' : '';
                    echo '<span class="chip' . $cls . '" title="' . htmlspecialchars(strval($d['eval_value'])) . '">' . htmlspecialchars(substr($d['reg_date'], 5)) . '</span>';
                }
                if (count($rec['days']) > 30) echo '<span class="chip" style="color:#98a0b3;">…共' . count($rec['days']) . '天</span>';
                echo '</div>';
                // 每日评价表（有评价的日期）
                $evd = array_values(array_filter($rec['days'], function ($d) { return strval($d['eval_value']) !== ''; }));
                if ($evd) {
                    echo '<table class="qa-tb"><tr><th>日期</th><th>评价内容</th></tr>';
                    foreach ($evd as $d) echo '<tr><td>' . htmlspecialchars($d['reg_date']) . '</td><td class="evc">' . htmlspecialchars(strval($d['eval_value'])) . '</td></tr>';
                    echo '</table>';
                }
            }
        }
    } elseif ($is_omr) {
        if ($today_only) {
            $todays = [];
            foreach ($rec['rounds'] as $rno => $r2) {
                if (strval($r2['registered_at']) !== '' && substr(strval($r2['registered_at']), 0, 10) === $TODAY) $todays[] = $rno;
            }
            if ($todays) {
                echo '<div class="pc-line">本日新增批改 ' . count($todays) . ' 题：</div>';
                foreach ($todays as $rno) {
                    $o = $rec['omr'][$rno] ?? null;
                    echo '<div class="pc-line">· ' . htmlspecialchars(qj_round_label($rec, $rno)) . ($o && $o['t'] > 0 ? '：得分 <b style="color:#e74c3c;">' . htmlspecialchars(qj_num($o['g'])) . '/' . htmlspecialchars(qj_num($o['t'])) . '</b>' : '：已识别待批改') . '</div>';
                }
            } else {
                echo '<div class="pc-line" style="color:#98a0b3;">本日暂无新的答题卡记录</div>';
            }
        } else {
            $g = 0.0; $t = 0.0;
            foreach ($rec['omr'] as $o) { $g += $o['g']; $t += $o['t']; }
            echo '<div class="pc-line"><b style="color:#e74c3c;">累计 ' . count($rec['omr']) . ' 题次</b>' . ($t > 0 ? ' · 总得分 ' . htmlspecialchars(qj_num($g)) . '/' . htmlspecialchars(qj_num($t)) . '（得分率 ' . round($g / $t * 100, 1) . '%）' : '') . '</div>';
            // 折中折：每题次一个子折叠（含每题作答表格）
            foreach ($rec['round_nos'] as $rno) {
                if (!isset($rec['rounds'][$rno]) && !isset($rec['omr'][$rno])) continue;
                $o = $rec['omr'][$rno] ?? null;
                $sub = $cid . '_r' . $rno;
                $st = strval($rec['rounds'][$rno]['registered_at'] ?? '');
                echo '<div class="sub"><div class="sub-h" onclick="tgSub(\'' . $sub . '\', this)"><span class="pc-arrow">▸</span>' . htmlspecialchars(qj_round_label($rec, $rno));
                if ($o && $o['t'] > 0) {
                    echo ' · 得分 <b style="color:#e74c3c;">' . htmlspecialchars(qj_num($o['g'])) . '/' . htmlspecialchars(qj_num($o['t'])) . '</b>（' . round($o['g'] / $o['t'] * 100, 1) . '%）';
                } elseif ($o) {
                    echo ' · <span style="color:#98a0b3;">待批改</span>';
                }
                if ($st !== '') echo ' <span class="sub-t">' . htmlspecialchars($st) . '</span>';
                echo '</div><div class="sub-b" id="' . $sub . '">';
                if ($o) echo qj_omr_table($rec, $o);
                else echo '<div class="pc-line" style="color:#98a0b3;">暂无识别结果</div>';
                echo '</div></div>';
            }
        }
    } elseif (in_array($mode, ['quiz', 'raise'], true)) {
        $ans = 0; $ok = 0;
        foreach ($rec['rounds'] as $rno => $r2) {
            $ev = strval($r2['eval_value']);
            if ($ev === '' || !isset($rec['correct'][$rno])) continue;
            $ans++;
            if (in_array($ev, str_split($rec['correct'][$rno]), true)) $ok++;
        }
        if ($today_only) {
            $todays = [];
            foreach ($rec['rounds'] as $rno => $r2) {
                if (strval($r2['registered_at']) !== '' && substr(strval($r2['registered_at']), 0, 10) === $TODAY) $todays[$rno] = $r2;
            }
            if ($todays) {
                echo '<div class="pc-line">本日答题 ' . count($todays) . ' 题：</div>';
                foreach ($todays as $rno => $r2) echo '<div class="pc-line">· ' . htmlspecialchars(qj_round_label($rec, $rno)) . '：' . qj_quiz_mark($rec, $rno, strval($r2['eval_value'])) . '</div>';
            } else {
                echo '<div class="pc-line" style="color:#98a0b3;">本日暂无答题记录</div>';
            }
        } else {
            echo '<div class="pc-line"><b style="color:#e6a23c;">累计答题 ' . count($rec['rounds']) . ' 题</b>' . ($ans > 0 ? ' · 答对 ' . $ok . ' 题 · 正确率 <b style="color:#27ae60;">' . round($ok / $ans * 100) . '%</b>' : '') . '</div>';
            // 折中折：逐题对错
            foreach ($rec['round_nos'] as $rno) {
                if (!isset($rec['rounds'][$rno])) continue;
                $r2 = $rec['rounds'][$rno];
                $sub = $cid . '_r' . $rno;
                echo '<div class="sub"><div class="sub-h" onclick="tgSub(\'' . $sub . '\', this)"><span class="pc-arrow">▸</span>' . htmlspecialchars(qj_round_label($rec, $rno)) . '：' . qj_quiz_mark($rec, $rno, strval($r2['eval_value']));
                if (strval($r2['registered_at']) !== '') echo ' <span class="sub-t">' . htmlspecialchars(strval($r2['registered_at'])) . '</span>';
                echo '</div><div class="sub-b" id="' . $sub . '"><div class="pc-line">作答时间：' . htmlspecialchars(strval($r2['registered_at']) ?: '—') . '（' . ($by_map[strval($r2['registered_by'])] ?? '—') . '）</div></div></div>';
            }
        }
    } elseif ($mode === 'multi') {
        if ($today_only) {
            $todays = [];
            foreach ($rec['rounds'] as $rno => $r2) {
                if (strval($r2['registered_at']) !== '' && substr(strval($r2['registered_at']), 0, 10) === $TODAY) $todays[$rno] = $r2;
            }
            if ($todays) {
                echo '<div class="pc-line">本日登记 ' . count($todays) . ' 次：</div>';
                foreach ($todays as $rno => $r2) echo '<div class="pc-line">· ' . htmlspecialchars(qj_round_label($rec, $rno)) . '：' . htmlspecialchars(strval($r2['registered_at'])) . (strval($r2['eval_value']) !== '' ? ' · 评价 <b style="color:#e67e22;">' . htmlspecialchars(strval($r2['eval_value'])) . '</b>' : '') . '</div>';
            } else {
                echo '<div class="pc-line" style="color:#98a0b3;">本日暂无登记记录</div>';
            }
        } else {
            $total_r = max(count($rec['round_nos']), count($rec['rounds']));
            echo '<div class="pc-line"><b style="color:#8f5fd6;">已登记 ' . count($rec['rounds']) . '/' . $total_r . ' 次</b></div>';
            foreach ($rec['round_nos'] ?: array_keys($rec['rounds']) as $rno) {
                $r2 = $rec['rounds'][$rno] ?? null;
                $sub = $cid . '_r' . $rno;
                echo '<div class="sub"><div class="sub-h' . ($r2 ? '' : ' dim') . '" onclick="tgSub(\'' . $sub . '\', this)"><span class="pc-arrow">▸</span>' . htmlspecialchars(qj_round_label($rec, $rno)) . '：';
                if ($r2) {
                    echo (strval($r2['registered_at']) !== '' ? '✓ ' . htmlspecialchars(strval($r2['registered_at'])) : '未登记') . (strval($r2['eval_value']) !== '' ? ' · <span class="evc">' . htmlspecialchars(strval($r2['eval_value'])) . '</span>' : '');
                } else {
                    echo '<span style="color:#98a0b3;">未登记</span>';
                }
                echo '</div><div class="sub-b" id="' . $sub . '">';
                if ($r2) echo '<div class="pc-line">登记时间：' . htmlspecialchars(strval($r2['registered_at']) ?: '—') . '（' . ($by_map[strval($r2['registered_by'])] ?? '—') . '）' . (strval($r2['eval_value']) !== '' ? ' · 评价：<b style="color:#e67e22;">' . htmlspecialchars(strval($r2['eval_value'])) . '</b>' . (strval($r2['eval_at']) !== '' ? ' <span class="sub-t">' . htmlspecialchars(strval($r2['eval_at'])) . '</span>' : '') : '') . '</div>';
                else echo '<div class="pc-line" style="color:#98a0b3;">该次暂无登记记录</div>';
                echo '</div></div>';
            }
        }
    } else {
        // 单次登记
        $b = $rec['base'] ?: ['registered' => 0, 'registered_at' => '', 'registered_by' => '', 'eval_value' => '', 'eval_at' => ''];
        if ($today_only) {
            $reg_today = strval($b['registered_at']) !== '' && substr(strval($b['registered_at']), 0, 10) === $TODAY;
            $ev_today = strval($b['eval_at']) !== '' && substr(strval($b['eval_at']), 0, 10) === $TODAY;
            if ($reg_today || $ev_today) {
                if ($reg_today) echo '<div class="pc-line">本日登记：✓ ' . htmlspecialchars(strval($b['registered_at'])) . '</div>';
                if ($ev_today) echo '<div class="pc-line">本日评价：<b style="color:#e67e22;">' . htmlspecialchars(strval($b['eval_value'])) . '</b></div>';
            } else {
                echo '<div class="pc-line" style="color:#98a0b3;">本日暂无登记/评价记录</div>';
            }
        } else {
            echo '<div class="pc-line">登记状态：<b style="color:' . (intval($b['registered']) === 1 ? '#27ae60;">已登记' : '#98a0b3;">未登记') . '</b>' . (strval($b['registered_at']) !== '' ? ' · ' . htmlspecialchars(strval($b['registered_at'])) . '（' . ($by_map[strval($b['registered_by'])] ?? '—') . '）' : '') . '</div>';
            if (strval($b['eval_value']) !== '') {
                echo '<div class="pc-line">当前评价：<b style="color:#e67e22;">' . htmlspecialchars(strval($b['eval_value'])) . '</b>' . (strval($b['eval_at']) !== '' ? ' <span class="sub-t">' . htmlspecialchars(strval($b['eval_at'])) . '</span>' : '') . '</div>';
            }
        }
    }
    // —— 评价历史 chips（历史视图） ——
    if (!$today_only && count($rec['evals']) > 0) {
        echo '<div class="pc-chips" style="margin-top:6px;"><span style="font-size:12px;color:#98a0b3;">评价历史（' . count($rec['evals']) . ' 次）：</span>';
        foreach (array_slice($rec['evals'], 0, 20) as $eh) echo '<span class="chip ev" style="white-space:nowrap;">' . htmlspecialchars($eh['label'] . ' ' . $eh['eval']) . '</span>';
        echo '</div>';
    }
    // —— 流水明细 ——
    if (!$today_only && count($rec['events'])) {
        $fid = 'flow_' . $tab . '_' . $pid;
        echo '<button type="button" class="flowbtn" onclick="tgFlow(\'' . $fid . '\', this, ' . count($rec['events']) . ')">📄 流水明细（' . count($rec['events']) . ' 条）</button>';
        echo '<div class="pc-flow" id="' . $fid . '">';
        foreach ($rec['events'] as $e) {
            echo '<div><span class="ft">' . htmlspecialchars($e['date'] !== '' ? $e['date'] : '—') . '</span><b>' . htmlspecialchars($e['kind']) . '</b>' . ($e['round'] !== null ? '（' . htmlspecialchars(qj_round_label($rec, intval($e['round']))) . '）' : '') . ($e['by'] !== '' && isset($by_map[$e['by']]) ? ' · ' . $by_map[$e['by']] : '') . ($e['eval'] !== '' ? ' · <b style="color:#e67e22;">' . htmlspecialchars($e['eval']) . '</b>' . ($e['eval_at'] !== '' ? ' <span class="ft">' . htmlspecialchars($e['eval_at']) . '</span>' : '') : '') . '</div>';
        }
        echo '</div>';
    }
    // —— 教师点评（历史视图卡片底部展示） ——
    if (!$today_only && $rec['comment'] !== '') {
        echo '<div class="pc-cm">💬 教师点评：' . htmlspecialchars($rec['comment']) . '</div>';
    }
    ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// 助手：轮次显示名（自定义标题优先）
if (!function_exists('qj_round_label')) {
    function qj_round_label(array $rec, int $rno): string {
        $unit = $rec['mode'] === 'multi' ? '次' : '题';
        $t = strval($rec['round_titles'][$rno] ?? '');
        return '第' . $rno . $unit . ($t !== '' ? '「' . $t . '」' : '');
    }
}
// 助手：数字去尾零
if (!function_exists('qj_num')) {
    function qj_num($v): string {
        $s = rtrim(rtrim(number_format(floatval($v), 1, '.', ''), '0'), '.');
        return ($s === '' || $s === '-0') ? '0' : $s;
    }
}
// 助手：答题/举牌 对错标记
if (!function_exists('qj_quiz_mark')) {
    function qj_quiz_mark(array $rec, int $rno, string $ev): string {
        if ($ev === '') return '<span style="color:#98a0b3;">未答</span>';
        $key = strval($rec['correct'][$rno] ?? '');
        $good = $key !== '' && in_array($ev, str_split($key), true);
        $color = $good ? '#27ae60' : ($key !== '' ? '#e74c3c' : '#667eea');
        $mark = $key !== '' ? ($good ? ' ✓' : ' ✗') : '';
        return '<b style="color:' . $color . ';">' . htmlspecialchars($ev) . $mark . '</b>' . ($key !== '' ? ' <span style="color:#98a0b3;font-size:11px;">（正确 ' . htmlspecialchars($key) . '）</span>' : '');
    }
}
// 助手：答题卡每题作答表格
if (!function_exists('qj_omr_table')) {
    function qj_omr_table(array $rec, array $o): string {
        $html = '<table class="qa-tb"><tr><th>题号</th><th>我的答案</th><th>正确答案</th><th>判定</th></tr>';
        $shown = 0;
        foreach ($rec['omr_q_list'] as $q) {
            $qk = strval($q);
            $sel = strtoupper(preg_replace('/[^A-E]/', '', strval($o['answers'][$qk] ?? $o['answers'][intval($qk)] ?? '')));
            $key = strval($rec['omr_key'][$qk] ?? '');
            if ($sel === '' && $key === '') continue;
            $kind = strval($rec['omr_kind'][$qk] ?? 'single');
            $good = false;
            if ($key !== '' && $sel !== '') {
                $good = $kind === 'multi' ? count(array_diff(str_split($sel), str_split($key))) === 0 : $sel === $key;
            }
            $html .= '<tr><td>' . htmlspecialchars($qk) . '</td><td>' . ($sel !== '' ? '<b>' . htmlspecialchars($sel) . '</b>' : '<span style="color:#98a0b3;">未涂</span>') . '</td><td>' . ($key !== '' ? htmlspecialchars($key) : '—') . '</td><td>' . ($key === '' ? '—' : ($good ? '<span class="okc">✓</span>' : '<span class="ngc">✗</span>')) . '</td></tr>';
            $shown++;
        }
        if (!$shown) return '<div class="pc-line" style="color:#98a0b3;">该题次暂无作答明细</div>';
        return $html . '</table>';
    }
}

$ctx = ['today' => $TODAY, 'by_map' => $by_map, 'mode_names' => $mode_names, 'mode_colors' => $mode_colors];
$cards_hist = ''; $cards_today = '';
foreach ($projects as $rec) $cards_hist .= qj_render_card($rec, false, 'h', $ctx);
foreach ($projects as $rec) { if ($rec['today']) $cards_today .= qj_render_card($rec, true, 't', $ctx); }
$bar_labels = []; $bar_values = [];
foreach ($projects as $p) { $bar_labels[] = $p['name']; $bar_values[] = $p['cnt']; }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登记信息查询 - 潜记二维码作业登记系统</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background: #f0f2f5; }
        .query-wrap { max-width: 720px; margin: 30px auto; padding: 0 16px; }
        .query-title { text-align: center; font-size: 22px; font-weight: bold; color: #333; margin-bottom: 6px; }
        .query-sub { text-align: center; color: #888; font-size: 13px; margin-bottom: 20px; }
        /* 选项卡 */
        .qtabs { display: flex; background: #fff; border-radius: 12px 12px 0 0; padding: 6px 6px 0; gap: 4px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
        .qt { flex: 1; border: 0; background: none; padding: 10px 0; font-size: 14.5px; color: #8891a5; cursor: pointer; border-bottom: 2.5px solid transparent; font-weight: bold; }
        .qt.active { color: #667eea; border-bottom-color: #667eea; }
        .qtab-body { background: #fff; border-radius: 0 0 12px 12px; padding: 12px 14px; margin-bottom: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
        /* 折叠卡 */
        .pcard { background: #fff; border: 1px solid #eef1f8; border-radius: 10px; margin-bottom: 8px; overflow: hidden; }
        .pc-head { display: flex; align-items: center; gap: 8px; padding: 11px 12px; cursor: pointer; user-select: none; flex-wrap: wrap; }
        .pc-head:hover { background: #fafbff; }
        .pc-arrow { color: #b6bdcc; font-size: 12px; transition: transform .15s; display: inline-block; }
        .pcard.open .pc-arrow { transform: rotate(90deg); }
        .pc-name { font-weight: bold; font-size: 15px; color: #333; }
        .pc-mode { font-size: 11px; border-radius: 9px; padding: 2px 9px; font-weight: bold; }
        .pc-new { font-size: 11px; color: #fff; background: #e74c3c; border-radius: 9px; padding: 2px 8px; font-weight: bold; animation: pcblink 1.6s infinite; }
        @keyframes pcblink { 0%,100% { opacity: 1; } 50% { opacity: .55; } }
        .pc-mini { font-size: 12px; color: #98a0b3; margin-left: auto; text-align: right; }
        .pc-body { display: none; padding: 4px 14px 12px; border-top: 1px dashed #eef1f8; }
        .pcard.open .pc-body { display: block; }
        .pc-line { font-size: 13px; color: #555; line-height: 1.9; }
        .pc-chips { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; align-items: center; }
        .chip { font-size: 11px; color: #555; background: #f4f5f7; border-radius: 6px; padding: 2px 7px; }
        .chip.ev { color: #e67e22; background: #fef5ec; border: 1px solid #f8e3cc; }
        .evc { color: #e67e22; font-weight: bold; }
        /* 折中折 */
        .sub { border: 1px solid #f0f2f8; border-radius: 8px; margin-top: 6px; overflow: hidden; }
        .sub-h { display: flex; align-items: center; gap: 6px; padding: 8px 10px; font-size: 12.5px; color: #444; cursor: pointer; user-select: none; background: #fbfbfe; flex-wrap: wrap; }
        .sub-h:hover { background: #f4f6fe; }
        .sub-h.dim { color: #b6bdcc; }
        .sub-h .pc-arrow { font-size: 10px; }
        .sub.open .pc-arrow { transform: rotate(90deg); }
        .sub-t { color: #b6bdcc; font-size: 11px; margin-left: auto; font-family: Consolas, Menlo, monospace; }
        .sub-b { display: none; padding: 4px 10px 8px; }
        .sub.open .sub-b { display: block; }
        /* 表格（每日评价 / 每题作答） */
        .qa-tb { border-collapse: collapse; width: 100%; margin-top: 8px; font-size: 12.5px; }
        .qa-tb th, .qa-tb td { border: 1px solid #e8eaf2; padding: 5px 8px; text-align: center; }
        .qa-tb th { background: #f6f7fd; color: #555; }
        .qa-tb td.evc { color: #e67e22; font-weight: bold; }
        .qa-tb .okc { color: #27ae60; font-weight: bold; }
        .qa-tb .ngc { color: #e74c3c; font-weight: bold; }
        .flowbtn { margin-top: 8px; font-size: 12px; color: #667eea; background: none; border: 1px solid #dfe4fb; border-radius: 999px; padding: 3px 12px; cursor: pointer; }
        .flowbtn:hover { background: #eef1fd; }
        .pc-flow { display: none; margin-top: 8px; border-top: 1px dashed #e8eaf2; padding-top: 8px; }
        .pc-flow div { font-size: 12px; color: #555; line-height: 1.9; }
        .pc-flow div b { color: #333; }
        .pc-flow .ft { color: #999; font-family: Consolas, Menlo, monospace; margin-right: 6px; }
        .pc-cm { margin-top: 8px; font-size: 12.5px; color: #4a6fa5; background: #f2f7ff; border: 1px solid #d9e6fb; border-radius: 8px; padding: 7px 10px; line-height: 1.7; }
        /* 汇总卡片 */
        .qsum { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 8px; margin-bottom: 12px; }
        .qsum .qs { background: #fff; border-radius: 12px; padding: 10px 12px; text-align: center; box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
        .qsum .qs b { display: block; font-size: 20px; color: #667eea; line-height: 1.3; }
        .qsum .qs span { font-size: 11.5px; color: #98a0b3; }
        @media (max-width: 480px) { .qsum { grid-template-columns: repeat(2, minmax(0,1fr)); } }
        /* 统计矩阵 */
        .mx-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .mx-table { border-collapse: collapse; width: 100%; min-width: 480px; font-size: 12.5px; background: #fff; }
        .mx-table th, .mx-table td { border: 1px solid #e8eaf2; padding: 6px 8px; text-align: center; white-space: nowrap; }
        .mx-table th { background: #f6f7fd; color: #555; font-weight: bold; }
        .mx-table td.mx-date { background: #fbfbfe; color: #333; font-weight: bold; }
        .mx-table td.mx-date small { display: block; color: #98a0b3; font-weight: normal; font-size: 11px; }
        .mx-ok { color: #27ae60; font-weight: bold; }
        .mx-eval { color: #e67e22; font-weight: bold; }
        .mx-ecol { color: #e67e22; font-size: 12px; max-width: 240px; white-space: normal; text-align: left; }
        /* 综合报告 */
        .rp-tb { border-collapse: collapse; width: 100%; font-size: 12.5px; }
        .rp-tb th, .rp-tb td { border-bottom: 1px solid #eef1f8; padding: 8px 6px; text-align: left; }
        .rp-tb th { color: #8891a5; font-size: 12px; background: #fbfbfe; }
        .rp-tb td b.rpn { color: #333; }
        .cm-box { margin-top: 12px; }
        .cm-box .cm-item { border-left: 3px solid #667eea; background: #f6f8ff; border-radius: 0 8px 8px 0; padding: 8px 12px; margin-top: 8px; }
        .cm-box .cm-item b { font-size: 13px; color: #333; }
        .cm-box .cm-item p { margin: 4px 0 0; font-size: 13px; color: #555; line-height: 1.7; }
        .sec-h { margin: 16px 0 8px; display: flex; align-items: center; gap: 8px; }
        .sec-h b { font-size: 15px; color: #333; }
        .sec-h small { color: #98a0b3; font-size: 11.5px; }
        .chart-card { background: #fff; border: 1px solid #eef1f8; border-radius: 10px; padding: 12px; margin-bottom: 10px; }
        .chart-card h4 { margin: 0 0 6px; font-size: 13.5px; color: #555; }
        .chart-box { width: 100%; height: 260px; }
        .chart-empty { color: #98a0b3; font-size: 12.5px; text-align: center; padding: 30px 0; }
    </style>
</head>
<body>
<div class="query-wrap">
    <div class="query-title">📋 登记信息查询</div>
    <div class="query-sub">输入班级码、学生编号与姓名，查询该生的全部登记信息（<?php echo htmlspecialchars($class ? $class['name'] : '未识别班级'); ?>）</div>

    <?php if ($query_closed): ?>
    <div class="alert alert-error">🔒 <?php echo htmlspecialchars($error); ?></div>
    <?php else: ?>
    <div class="panel">
        <form method="get" style="display:flex;flex-direction:column;gap:12px;" autocomplete="off">
            <input type="hidden" name="c" value="<?php echo htmlspecialchars($class_code); ?>" autocomplete="off">
            <?php if ($tview): ?><input type="hidden" name="tview" value="1" autocomplete="off"><?php endif; ?>
            <div class="form-group" style="margin-bottom:0;">
                <label>学生编号</label>
                <input type="text" name="no" class="form-control" value="<?php echo htmlspecialchars($student_no); ?>" placeholder="如：2024001" required autocomplete="off">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label>学生姓名</label>
                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($student_name); ?>" placeholder="如：张三" required autocomplete="off">
            </div>
            <button type="submit" class="btn">查 询</button>
        </form>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($student && !$error): ?>
    <div class="panel">
        <b><?php echo htmlspecialchars($student['name']); ?></b>
        <?php if ($tview): ?><span style="font-size:11px;color:#fff;background:#667eea;border-radius:9px;padding:2px 9px;font-weight:bold;">👨‍🏫 教师视图 · 全部项目</span><?php endif; ?>
        <span class="form-hint">　<?php echo htmlspecialchars($class['name']); ?> · 序号 <?php echo htmlspecialchars($student['seat_no']); ?> · 编号 <?php echo htmlspecialchars($student['student_no']); ?></span>
    </div>
    <?php if (empty($projects)): ?>
    <div class="alert alert-info"><?php echo $tview ? '该学生暂无登记信息' : '该学生暂无公开可查询的登记信息'; ?></div>
    <?php else: ?>

    <!-- 汇总卡片 -->
    <div class="qsum">
        <div class="qs"><b><?php echo count($projects); ?></b><span>登记项目数</span></div>
        <div class="qs"><b><?php echo $qp_today_cnt; ?></b><span>本日有记录项目</span></div>
        <div class="qs"><b><?php echo $qp_total_cnt; ?></b><span>打卡/登记次数</span></div>
        <div class="qs"><b><?php echo $qp_total_evals; ?></b><span>评价次数</span></div>
    </div>

    <!-- 三个选项卡 -->
    <div class="qtabs">
        <button type="button" class="qt active" data-tab="today" onclick="qTab('today')">☀ 本日情况</button>
        <button type="button" class="qt" data-tab="hist" onclick="qTab('hist')">🗓 历史情况</button>
        <button type="button" class="qt" data-tab="report" onclick="qTab('report')">📊 综合报告</button>
    </div>

    <!-- ===== 选项卡：本日情况（默认，仅呈现本日有登记/评价的项目） ===== -->
    <div class="qtab-body" id="tab_today">
        <?php if ($cards_today === ''): ?>
        <div class="chart-empty" style="padding:26px 0;">本日（<?php echo htmlspecialchars($TODAY); ?>）暂无登记记录</div>
        <?php else: ?>
        <div class="sec-h" style="margin-top:4px;"><b>本日登记概况</b><small><?php echo htmlspecialchars($TODAY); ?> · 共 <?php echo $qp_today_cnt; ?> 个项目有更新</small></div>
        <?php echo $cards_today; ?>
        <?php endif; ?>
    </div>

    <!-- ===== 选项卡：历史情况（全部项目：矩阵 + 图表 + 折叠卡） ===== -->
    <div class="qtab-body" id="tab_hist" style="display:none;">
        <?php if (count($mx_rows)): ?>
        <div class="sec-h" style="margin-top:4px;"><b>📊 统计矩阵</b><small>行=每次（打卡项目按日期），列=项目；橙=评价/得分，绿✓=已登记未评价</small></div>
        <div style="border:1px solid #eef1f8;border-radius:10px;padding:10px;">
            <div class="mx-wrap">
                <table class="mx-table">
                    <tr>
                        <th style="min-width:96px;">每次</th>
                        <?php foreach ($projects as $p): ?><th><?php echo htmlspecialchars($p['name']); ?></th><?php endforeach; ?>
                        <th>评价内容</th>
                    </tr>
                    <?php foreach ($mx_rows as $row): ?>
                    <tr>
                        <td class="mx-date"><?php echo $row['date'] !== '' ? htmlspecialchars($row['date']) : '<span style="color:#d5d8e2;">—</span>'; ?><?php echo $row['sub'] !== '' ? '<small>' . htmlspecialchars($row['sub']) . '</small>' : ''; ?></td>
                        <?php foreach ($projects as $p): $cell = $row['cells'][$p['id']] ?? null; ?>
                        <td><?php if ($cell === null): ?><span style="color:#d5d8e2;">—</span><?php elseif ($cell === '✓'): ?><span class="mx-ok">✓</span><?php else: ?><span class="mx-eval"><?php echo htmlspecialchars($cell); ?></span><?php endif; ?></td>
                        <?php endforeach; ?>
                        <td class="mx-ecol"><?php
                            $parts = [];
                            foreach ($projects as $p) {
                                if (isset($row['evals'][$p['id']])) $parts[] = htmlspecialchars($p['name']) . '：' . htmlspecialchars($row['evals'][$p['id']]);
                            }
                            echo $parts ? implode('；', $parts) : '<span style="color:#d5d8e2;">—</span>';
                        ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="sec-h"><b>📈 统计图表</b><small>次数 / 评价分布 / 趋势</small></div>
        <div class="chart-card">
            <h4>各项目登记/打卡次数</h4>
            <div id="qbar" class="chart-box"></div>
        </div>
        <div class="chart-card">
            <h4>评价分布（全部项目累计）</h4>
            <?php if (count($pie_counts)): ?>
            <div id="qpie" class="chart-box"></div>
            <?php else: ?>
            <div class="chart-empty">暂无评价记录</div>
            <?php endif; ?>
        </div>
        <div class="chart-card">
            <h4>近 30 天登记趋势</h4>
            <div id="qline" class="chart-box"></div>
        </div>
        <?php if (count($omr_lines)): ?>
        <div class="chart-card">
            <h4>答题卡得分率走势</h4>
            <div id="qomr" class="chart-box"></div>
        </div>
        <?php endif; ?>

        <div class="sec-h"><b>📚 各项目明细</b><small>点击展开 · 多项次项目可逐次展开</small></div>
        <?php echo $cards_hist; ?>
    </div>

    <!-- ===== 选项卡：综合报告（个人总评价汇总 + 教师点评） ===== -->
    <div class="qtab-body" id="tab_report" style="display:none;">
        <div class="sec-h" style="margin-top:4px;"><b>📋 个人总评价汇总</b><small>全部项目最新评价与次数一览</small></div>
        <div style="overflow-x:auto;">
            <table class="rp-tb">
                <tr><th>项目</th><th>类型</th><th>次数</th><th>评价次数</th><th>最新评价</th></tr>
                <?php foreach ($projects as $p): $lv = $p['evals'][0] ?? null; ?>
                <tr>
                    <td><b class="rpn"><?php echo htmlspecialchars($p['name']); ?></b></td>
                    <td style="color:<?php echo $mode_colors[$p['mode']] ?? '#667eea'; ?>;font-weight:bold;"><?php echo $mode_names[$p['mode']] ?? $p['mode']; ?></td>
                    <td><?php echo intval($p['cnt']); ?></td>
                    <td><?php echo count($p['evals']); ?></td>
                    <td><?php if ($lv): ?><span class="mx-eval"><?php echo htmlspecialchars($lv['eval']); ?></span> <span style="color:#98a0b3;font-size:11px;">（<?php echo htmlspecialchars($lv['label']); ?>）</span><?php else: ?><span style="color:#d5d8e2;">—</span><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php if ($qp_has_comment): ?>
        <div class="cm-box">
            <div class="sec-h" style="margin:0 0 4px;"><b>💬 教师点评</b><small>教师针对各项目的个性化点评</small></div>
            <?php foreach ($projects as $p): if ($p['comment'] === '') continue; ?>
            <div class="cm-item">
                <b><?php echo htmlspecialchars($p['name']); ?></b>
                <p><?php echo htmlspecialchars($p['comment']); ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <script src="assets/js/echarts.min.js"></script>
    <script src="assets/js/chart_common.js"></script>
    <script>
    var Q_HIST_INITED = false;
    // 选项卡切换（历史 tab 图表懒初始化：ecGet 对隐藏容器拿不到尺寸，须先显示再渲染）
    function qTab(name) {
        ['today', 'hist', 'report'].forEach(function (t) {
            document.getElementById('tab_' + t).style.display = (t === name) ? '' : 'none';
        });
        document.querySelectorAll('.qt').forEach(function (b) { b.classList.toggle('active', b.dataset.tab === name); });
        if (name === 'hist' && !Q_HIST_INITED) { initQCharts(); Q_HIST_INITED = true; }
    }
    // 项目折叠卡
    function tgPc(id) {
        document.getElementById(id).classList.toggle('open');
    }
    // 折中折（子折叠）
    function tgSub(id, head) {
        var el = document.getElementById(id);
        el.classList.toggle('open');
        var ar = head.querySelector('.pc-arrow');
        if (ar) ar.textContent = el.classList.contains('open') ? '▾' : '▸';
    }
    // 流水明细
    function tgFlow(id, btn, n) {
        var el = document.getElementById(id);
        var show = el.style.display !== 'block';
        el.style.display = show ? 'block' : 'none';
        btn.textContent = show ? '📄 收起流水' : '📄 流水明细（' + n + ' 条）';
    }
    function initQCharts() {
        if (typeof echarts === 'undefined' || typeof ecSet === 'undefined') return;
        var PAL = ['#667eea', '#3fb896', '#e6a23c', '#e74c3c', '#8f5fd6', '#5aa9e6', '#f39c9c', '#95d475'];
        // 柱状图：各项目登记/打卡次数
        var labels = <?php echo json_encode($bar_labels, JSON_UNESCAPED_UNICODE); ?>, values = <?php echo json_encode($bar_values); ?>;
        if (labels.length) ecSet('qbar', ecBarOpt(labels, values, { colors: PAL, unit: ' 次' }));
        // 饼图：评价分布
        var pie = <?php echo json_encode(array_map(function ($v, $k) { return ['name' => strval($k), 'value' => intval($v)]; }, array_values($pie_counts), array_keys($pie_counts))); ?>;
        if (pie.length) {
            pie.forEach(function (it, i) { it.color = PAL[i % PAL.length]; });
            var total = pie.reduce(function (s, it) { return s + it.value; }, 0);
            ecSet('qpie', ecPieOpt(pie, { unit: ' 次', centerMain: String(total), centerSub: '评价总数' }));
        }
        // 折线图：近30天登记趋势
        var tl = <?php echo json_encode($trend_labels); ?>, tv = <?php echo json_encode($trend_values); ?>;
        ecSet('qline', {
            grid: { left: 40, right: 20, top: 30, bottom: 30 },
            tooltip: { trigger: 'axis' },
            xAxis: { type: 'category', data: tl, axisLabel: { color: '#888', fontSize: 11 } },
            yAxis: { type: 'value', minInterval: 1, axisLabel: { color: '#888' }, splitLine: { lineStyle: { color: '#eef1f8' } } },
            series: [{ name: '登记次数', type: 'line', data: tv, smooth: true, symbolSize: 6, itemStyle: { color: '#667eea' },
                       areaStyle: { opacity: 0.12, color: '#667eea' }, label: { show: false } }]
        });
        <?php if (count($omr_lines)): ?>
        // 折线图：答题卡得分率走势（每项目一条线，null 断点跳过）
        var omrLines = <?php echo json_encode($omr_lines, JSON_UNESCAPED_UNICODE); ?>;
        var omrLabels = omrLines[0].rounds.map(function (n) { return '第' + n + '题'; });
        var series = omrLines.map(function (ln, i) {
            return { name: ln.name, type: 'line', data: ln.data, connectNulls: true, symbolSize: 6,
                     itemStyle: { color: PAL[i % PAL.length] }, lineStyle: { color: PAL[i % PAL.length], width: 2 },
                     label: { show: true, position: 'top', color: '#888', fontSize: 10, formatter: function (p) { return p.value == null ? '' : p.value + '%'; } } };
        });
        ecSet('qomr', {
            grid: { left: 44, right: 20, top: 40, bottom: 30 },
            tooltip: { trigger: 'axis', valueFormatter: function (v) { return (v == null ? '—' : v + '%'); } },
            legend: { top: 0, textStyle: { color: '#666', fontSize: 11 } },
            xAxis: { type: 'category', data: omrLabels, axisLabel: { color: '#888', fontSize: 11 } },
            yAxis: { type: 'value', max: 100, axisLabel: { color: '#888', formatter: '{value}%' }, splitLine: { lineStyle: { color: '#eef1f8' } } },
            series: series
        });
        <?php endif; ?>
    }
    </script>
    <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
