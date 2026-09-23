<?php
/**
 * 统计报表中心（单用户版：无年段/学科维度）
 * 页面结构：📅 导出时间范围（联动全部导出与图表）→ 📈 数据总览（KPI + ECharts 图表：班级登记对比/每日动态/评价分布）
 *          → 📤 报表导出（Tab 分区：班级报表[单班表格+批量ZIP] / 全校汇总·教师提交率[管理员]）→ 📊 在线统计（弹窗查看班级/学生明细）
 * 导出类型：
 *  - 时间范围（可选 from/to=YYYY-MM-DD）：登记按 registered_at、打卡按 reg_date 过滤；留空=全部时间
 *  - 单项目导出（dtype=project）：答题卡项目=成绩报表 Excel XML（学生成绩/一均三率及排名/学生等级/分数段统计四张分表）；
 *    打卡项目=Excel XML（是否登记/登记时间/评价内容三张分表，每次打卡一列、列标题=日期）；一次性项目=CSV（可操作该项目者）
 *  - 班级导出（登记矩阵 dtype=class）：Excel XML，一个项目一张表（打卡项目=日期列✓矩阵，一次性=已登记（评价））（可见该班级者）
 *  - 班级导出（全部项目明细 dtype=class_detail）：Excel XML，是否登记/登记时间/评价内容三张分表（打卡项目按日期展开列）
 *  - 班级导出（全部学生 dtype=class_students）：Excel XML，每位学生一张表列出全部项目登记情况（打卡项目按日期展开）
 *  - 批量导出（dtype=batch）：多班级 × 多报表类型（matrix/detail/students）→ ZIP 打包（每个班级一个工作簿，含导出说明）；
 *    仅一个文件时直接下载；无 ZipArchive 扩展时降级为单一合并工作簿（工作表名加班级前缀）
 *  - 学生登记情况导出（dtype=student_detail）：Excel XML 三张分表（stats.php 学生统计页入口）
 *  - 全校导出（dtype=school）：全校各班级汇总（管理员）
 *  - 教师提交率导出（dtype=teacher，scope=school）：按项目创建人（科任老师）×班级聚合提交率（管理员）
 * 数据接口：action=stats_data 返回数据总览 JSON（KPI/班级登记对比/每日动态趋势/评价分布，均按时间范围过滤、可见班级口径）
 */
require_once 'includes/db_config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/layout.php';

$conn = getConnection();
$school_id = current_school_id($conn);

// ===== CSV 输出 =====
if (!function_exists('output_csv')) {
    function output_csv($filename, $rows) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        echo "\xEF\xBB\xBF"; // BOM（Excel 兼容）
        foreach ($rows as $row) {
            $line = [];
            foreach ($row as $cell) {
                $cell = (string)$cell;
                $cell = str_replace('"', '""', $cell);
                $line[] = '"' . $cell . '"';
            }
            echo implode(',', $line) . "\r\n";
        }
        exit();
    }
}

// ===== Excel XML（SpreadsheetML 2003，.xls）输出：支持多工作表 =====
if (!function_exists('excel_xml_sheet_name')) {
    // 工作表名清洗：去除非法字符、限长（预留去重后缀）、去重
    function excel_xml_sheet_name($name, &$used) {
        $name = trim(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', strval($name)));
        if ($name === '') $name = 'Sheet';
        if (preg_match('/^(.{1,20})/us', $name, $m)) $name = $m[1];
        $base = $name;
        $i = 2;
        while (isset($used[$name])) $name = $base . '(' . ($i++) . ')';
        $used[$name] = true;
        return $name;
    }
}
if (!function_exists('excel_xml_string')) {
    // $sheets: [['name' => 工作表名, 'rows' => [[单元格,...],...]], ...] → 返回工作簿 XML 字符串（供单文件下载与批量打包共用）
    function excel_xml_string($sheets) {
        $out = "\xEF\xBB\xBF";
        $out .= '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
        $out .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
            . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
            . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
        $used = [];
        foreach ($sheets as $sheet) {
            $maxcols = 0;
            foreach ($sheet['rows'] as $row) $maxcols = max($maxcols, count($row));
            $out .= '<Worksheet ss:Name="' . htmlspecialchars(excel_xml_sheet_name($sheet['name'], $used), ENT_QUOTES, 'UTF-8') . '">';
            $out .= '<Table>';
            for ($i = 0; $i < $maxcols; $i++) $out .= '<Column ss:Width="95"/>';
            foreach ($sheet['rows'] as $row) {
                $out .= '<Row>';
                foreach ($row as $cell) {
                    $out .= '<Cell><Data ss:Type="String">' . htmlspecialchars((string)$cell, ENT_QUOTES, 'UTF-8') . '</Data></Cell>';
                }
                $out .= '</Row>';
            }
            $out .= '</Table></Worksheet>' . "\n";
        }
        $out .= '</Workbook>';
        return $out;
    }
}
if (!function_exists('output_excel_xml')) {
    function output_excel_xml($filename, $sheets) {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        echo excel_xml_string($sheets);
        exit();
    }
}

// ===== 打卡数据辅助 =====
if (!function_exists('get_project_daily_data')) {
    // 单项目（可跨班级）：打卡日期列表（升序）+ [student_id][reg_date]=打卡时间；$from/$to 可选日期范围过滤
    function get_project_daily_data($conn, $project_id, $class_ids, $from = '', $to = '') {
        $dates = []; $map = [];
        if (!$class_ids) return [$dates, $map];
        $in = implode(',', array_map('intval', $class_ids));
        $cond = '';
        if ($from !== '') $cond .= " AND rd.reg_date >= '" . mysqli_real_escape_string($conn, $from) . "'";
        if ($to !== '') $cond .= " AND rd.reg_date <= '" . mysqli_real_escape_string($conn, $to) . "'";
        $res = mysqli_query($conn, "SELECT rd.student_id, rd.reg_date, rd.created_at FROM record_days rd
                    INNER JOIN students s ON rd.student_id = s.id
                    WHERE rd.project_id = " . intval($project_id) . " AND s.class_id IN ({$in}) {$cond} ORDER BY rd.reg_date");
        $last = '';
        while ($row = mysqli_fetch_assoc($res)) {
            if ($row['reg_date'] !== $last) { $dates[] = $row['reg_date']; $last = $row['reg_date']; }
            $map[intval($row['student_id'])][$row['reg_date']] = (string)$row['created_at'];
        }
        return [$dates, $map];
    }
}
if (!function_exists('build_omr_project_export')) {
    /**
     * 答题卡项目成绩报表构建（仿校级统计报表结构：学生成绩 / 一均三率及排名 / 学生等级 / 分数段统计）
     * 口径与项目页统计弹窗一致：得分率 = Σ得分 ÷ Σ满分（omr_results.score="对/总"，分值制=得分/分值和，题数制=对题数/题数）；
     * 等级与四率阈值：优秀≥80%、良好70%~80%、及格≥60%、低分≤30%；分数段=每 10% 一段；
     * 排名 = 得分率降序同率并列（竞赛排名）；未批改学生保留行、单元格留空、不参与排名。
     */
    function build_omr_project_export($conn, $project) {
        $pid = intval($project['id']);
        $nf = function ($v) {   // 数值格式化：最多 1 位小数去尾零（7.50→7.5、8.0→8）
            $s = rtrim(rtrim(number_format(floatval($v), 1, '.', ''), '0'), '.');
            return ($s === '' || $s === '-0') ? '0' : $s;
        };
        // 分值口径：绑定模板任一题设分值 → 得分制（否则对题数制）
        $scored = false;
        $bind_tid = intval(get_setting($conn, 'omr_tpl_bind_' . $pid, '0'));
        if ($bind_tid > 0) {
            $stmt = mysqli_prepare($conn, "SELECT layout FROM omr_templates WHERE id = ? AND project_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $bind_tid, $pid);
            mysqli_stmt_execute($stmt);
            $trow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if ($trow) {
                $lay = json_decode(strval($trow['layout']), true);
                foreach ((is_array($lay['sections'] ?? null) ? $lay['sections'] : []) as $sec) {
                    if (!is_array($sec)) continue;
                    foreach ((is_array($sec['points'] ?? null) ? $sec['points'] : []) as $pv) {
                        if (floatval(strval($pv)) > 0) { $scored = true; break 2; }
                    }
                }
            }
        }
        $s_lbl = $scored ? '得分' : '对题数';
        $r_lbl = $scored ? '得分率' : '正确率';

        // 题次列表（升序）与标题
        $rounds = project_rounds_list($conn, $pid);
        $titles = project_rounds_titles($conn, $pid);
        $rname = [];
        foreach ($rounds as $rn) {
            $t = trim(strval($titles[$rn] ?? ''));
            $rname[intval($rn)] = $t !== '' ? $t : '第' . intval($rn) . '次';
        }

        // 学生（项目覆盖班级、未禁用，与统计弹窗口径一致）
        $class_ids = get_project_class_ids($conn, $project);
        $students = [];
        if ($class_ids) {
            $in = implode(',', array_map('intval', $class_ids));
            $res = mysqli_query($conn, "SELECT c.name AS class_name, s.id AS sid, s.seat_no, s.name, s.student_no
                        FROM students s INNER JOIN classes c ON s.class_id = c.id
                        WHERE s.class_id IN ({$in}) AND s.disabled = 0
                        ORDER BY c.id, CAST(s.seat_no AS UNSIGNED), s.id");
            while ($row = mysqli_fetch_assoc($res)) {
                $students[intval($row['sid'])] = ['class_name' => $row['class_name'], 'seat_no' => strval($row['seat_no']),
                    'name' => $row['name'], 'student_no' => strval($row['student_no']), 'per' => []];
            }
        }
        // 各题次成绩（同一学生同题次多条识别结果以最新一条为准；answers 留存供客观题统计与作答明细）
        $res = mysqli_query($conn, "SELECT o.round_no, o.student_id, o.answers, o.score FROM omr_results o
                    INNER JOIN students s ON o.student_id = s.id
                    WHERE o.project_id = {$pid} AND o.score <> '' AND s.disabled = 0
                    ORDER BY o.id ASC");
        while ($row = mysqli_fetch_assoc($res)) {
            $sid = intval($row['student_id']);
            if (!isset($students[$sid])) continue;
            $parts = explode('/', strval($row['score']));
            $g = floatval($parts[0]); $t = count($parts) > 1 ? floatval($parts[1]) : 0;
            if ($t <= 0) continue;
            $ans = json_decode(strval($row['answers']), true);
            $students[$sid]['per'][intval($row['round_no'])] = ['g' => $g, 't' => $t, 'ans' => is_array($ans) ? $ans : []];
        }

        // 每生汇总（Σ得分÷Σ满分）与得分率
        $tot = [];
        foreach ($students as $sid => $st) {
            $sg = 0; $stt = 0;
            foreach ($st['per'] as $p) { $sg += $p['g']; $stt += $p['t']; }
            $tot[$sid] = ['g' => $sg, 't' => $stt, 'rate' => $stt > 0 ? $sg / $stt * 100 : null];
        }
        // 等级 / 分数段（10% 一段）/ 排名辅助
        $lv = function ($rate) {
            if ($rate === null) return '';
            if ($rate >= 80) return '优秀';
            if ($rate >= 70) return '良好';
            if ($rate >= 60) return '及格';
            return '不及格';
        };
        $band_idx = function ($rate) {
            if ($rate === null) return null;
            return min(9, max(0, intval(floor(floatval($rate) / 10))));
        };
        $band_lbl = [];
        for ($b = 0; $b < 10; $b++) $band_lbl[$b] = ($b * 10) . '%~' . (($b + 1) * 10) . '%';
        $rank_of = function ($vals) {   // [sid => rate] → [sid => 名次]（降序同率并列）
            arsort($vals);
            $rank = []; $pos = 0; $prev = null; $i = 0;
            foreach ($vals as $sid => $v) {
                $i++;
                if ($prev === null || floatval($v) < floatval($prev)) { $pos = $i; $prev = $v; }
                $rank[$sid] = $pos;
            }
            return $rank;
        };
        $agg = function (array $pairs) use ($nf) {   // pairs=[['g'=>..,'rate'=>..],..] → 一均三率（率制 %，1 位小数字符串）
            $n = count($pairs);
            if (!$n) return null;
            $sg = 0; $exc = 0; $good = 0; $pass = 0; $low = 0;
            foreach ($pairs as $p) {
                $sg += $p['g'];
                if ($p['rate'] >= 80) $exc++;
                if ($p['rate'] >= 70 && $p['rate'] < 80) $good++;
                if ($p['rate'] >= 60) $pass++;
                if ($p['rate'] < 30) $low++;
            }
            return ['n' => $n, 'avg_g' => $nf($sg / $n), 'avg_r' => $nf(array_sum(array_map(function ($p) { return $p['rate']; }, $pairs)) / $n),
                    'exc' => $nf($exc / $n * 100), 'good' => $nf($good / $n * 100),
                    'pass' => $nf($pass / $n * 100), 'low' => $nf($low / $n * 100)];
        };
        $cls_names = [];
        foreach ($students as $st) $cls_names[$st['class_name']] = true;
        $cls_names = array_keys($cls_names);

        // ===== 表1：学生成绩（每生每题次 得分/得分率 + 总分与总排名） =====
        $head = ['班级', '座号', '姓名', '编号'];
        foreach ($rounds as $rn) { $head[] = $rname[intval($rn)] . '·' . $s_lbl; $head[] = $rname[intval($rn)] . '·' . $r_lbl . '(%)'; }
        $head[] = '总' . $s_lbl; $head[] = $scored ? '总分值' : '总题数'; $head[] = '总' . $r_lbl . '(%)'; $head[] = '总排名';
        $all_rank = $rank_of(array_filter(array_map(function ($t) { return $t['rate']; }, $tot), function ($v) { return $v !== null; }));
        $rows1 = [$head];
        foreach ($students as $sid => $st) {
            $row = [$st['class_name'], $st['seat_no'], $st['name'], $st['student_no']];
            foreach ($rounds as $rn) {
                $p = $st['per'][intval($rn)] ?? null;
                $row[] = $p ? $nf($p['g']) : '';
                $row[] = $p ? $nf($p['g'] / $p['t'] * 100) : '';
            }
            $t0 = $tot[$sid];
            $row[] = $t0['t'] > 0 ? $nf($t0['g']) : '';
            $row[] = $t0['t'] > 0 ? $nf($t0['t']) : '';
            $row[] = $t0['rate'] !== null ? $nf($t0['rate']) : '';
            $row[] = isset($all_rank[$sid]) ? strval($all_rank[$sid]) : '';
            $rows1[] = $row;
        }

        // ===== 表2：一均三率及排名（各题次一均三率 + 班级汇总 + 每生各题次名次） =====
        $rows2 = [['范围', '实考人数', '平均' . $s_lbl, '平均' . $r_lbl . '(%)', '优秀率(≥80%)', '良好率(70%~80%)', '及格率(≥60%)', '低分率(<30%)']];
        foreach ($rounds as $rn) {
            $pairs = [];
            foreach ($students as $sid => $st) {
                $p = $st['per'][intval($rn)] ?? null;
                if ($p) $pairs[] = ['g' => $p['g'], 'rate' => $p['g'] / $p['t'] * 100];
            }
            $a = $agg($pairs);
            $rows2[] = $a ? [$rname[intval($rn)], strval($a['n']), $a['avg_g'], $a['avg_r'], $a['exc'], $a['good'], $a['pass'], $a['low']]
                          : [$rname[intval($rn)], '0', '', '', '', '', '', ''];
        }
        $rows2[] = ['— 按班级汇总（全部题次） —'];
        foreach ($cls_names as $cn) {
            $pairs = [];
            foreach ($students as $sid => $st) {
                if ($st['class_name'] !== $cn) continue;
                if ($tot[$sid]['rate'] !== null) $pairs[] = ['g' => $tot[$sid]['g'], 'rate' => $tot[$sid]['rate']];
            }
            $a = $agg($pairs);
            $rows2[] = $a ? [$cn, strval($a['n']), $a['avg_g'], $a['avg_r'], $a['exc'], $a['good'], $a['pass'], $a['low']]
                          : [$cn, '0', '', '', '', '', '', ''];
        }
        $pairs = [];
        foreach ($tot as $t0) { if ($t0['rate'] !== null) $pairs[] = ['g' => $t0['g'], 'rate' => $t0['rate']]; }
        $a = $agg($pairs);
        $rows2[] = $a ? ['全部', strval($a['n']), $a['avg_g'], $a['avg_r'], $a['exc'], $a['good'], $a['pass'], $a['low']]
                      : ['全部', '0', '', '', '', '', '', ''];
        $rows2[] = [];
        $head2 = ['班级', '座号', '姓名'];
        foreach ($rounds as $rn) $head2[] = $rname[intval($rn)] . '名次';
        $head2[] = '总名次';
        $rows2[] = $head2;
        $ranks = [];
        foreach ($rounds as $rn) {
            $vals = [];
            foreach ($students as $sid => $st) {
                $p = $st['per'][intval($rn)] ?? null;
                if ($p) $vals[$sid] = $p['g'] / $p['t'] * 100;
            }
            $ranks[intval($rn)] = $rank_of($vals);
        }
        foreach ($students as $sid => $st) {
            $row = [$st['class_name'], $st['seat_no'], $st['name']];
            foreach ($rounds as $rn) $row[] = isset($ranks[intval($rn)][$sid]) ? strval($ranks[intval($rn)][$sid]) : '';
            $row[] = isset($all_rank[$sid]) ? strval($all_rank[$sid]) : '';
            $rows2[] = $row;
        }

        // ===== 表3：学生等级（每生每题次等级 + 总评等级；优秀≥80% / 良好70~80% / 及格≥60% / 不及格<60%） =====
        $head3 = ['班级', '座号', '姓名', '编号'];
        foreach ($rounds as $rn) $head3[] = $rname[intval($rn)];
        $head3[] = '总评等级';
        $rows3 = [$head3];
        foreach ($students as $sid => $st) {
            $row = [$st['class_name'], $st['seat_no'], $st['name'], $st['student_no']];
            foreach ($rounds as $rn) {
                $p = $st['per'][intval($rn)] ?? null;
                $row[] = $p ? $lv($p['g'] / $p['t'] * 100) : '';
            }
            $row[] = $lv($tot[$sid]['rate']);
            $rows3[] = $row;
        }

        // ===== 表4：分数段统计（每 10% 一段，各题次人数 + 汇总人数/占比 + 未批改） =====
        $head4 = ['分数段'];
        foreach ($rounds as $rn) $head4[] = $rname[intval($rn)] . '人数';
        $head4[] = '汇总人数'; $head4[] = '汇总占比(%)';
        $rows4 = [$head4];
        $n_cls = count($students);
        for ($b = 9; $b >= 0; $b--) {
            $row = [$band_lbl[$b]];
            foreach ($rounds as $rn) {
                $c = 0;
                foreach ($students as $st) {
                    $p = $st['per'][intval($rn)] ?? null;
                    if ($p && $band_idx($p['g'] / $p['t'] * 100) === $b) $c++;
                }
                $row[] = strval($c);
            }
            $c = 0;
            foreach ($tot as $t0) { if ($t0['rate'] !== null && $band_idx($t0['rate']) === $b) $c++; }
            $n_scored = 0;
            foreach ($tot as $t0) { if ($t0['rate'] !== null) $n_scored++; }
            $row[] = strval($c);
            $row[] = $n_scored > 0 ? $nf($c / $n_scored * 100) : '';
            $rows4[] = $row;
        }
        $row = ['未批改'];
        foreach ($rounds as $rn) {
            $c = 0;
            foreach ($students as $st) if (!isset($st['per'][intval($rn)])) $c++;
            $row[] = strval($c);
        }
        $row[] = strval($n_cls - $n_scored); $row[] = $n_cls > 0 ? $nf(($n_cls - $n_scored) / $n_cls * 100) : '';
        $rows4[] = $row;

        // ===== 生效答案上下文（与识别端同口径）：绑定模板题组 + 模板答案键，独立录入答案补未设题 =====
        $omr_secs = []; $omr_kind = []; $omr_key = []; $omr_opts = []; $omr_pts = [];
        if ($bind_tid > 0) {
            $stmt = mysqli_prepare($conn, "SELECT layout FROM omr_templates WHERE id = ? AND project_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $bind_tid, $pid);
            mysqli_stmt_execute($stmt);
            $trow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            $lay = $trow ? json_decode(strval($trow['layout']), true) : null;
            foreach ((is_array($lay) && is_array($lay['sections'] ?? null) ? $lay['sections'] : []) as $sec) {
                if (!is_array($sec)) continue;
                $k = strval($sec['kind'] ?? 'single');
                if ($k === 'blank' || $k === 'short') continue;
                $start = intval($sec['start'] ?? 1); $cnt = min(500, max(1, intval($sec['count'] ?? 0)));
                $omr_secs[] = ['title' => strval($sec['title'] ?? ''), 'start' => $start, 'count' => $cnt,
                               'kind' => ($k === 'multi' ? 'multi' : 'single')];
                $key = is_array($sec['key'] ?? null) ? $sec['key'] : [];
                $pts = is_array($sec['points'] ?? null) ? $sec['points'] : [];
                for ($q = $start; $q < $start + $cnt; $q++) {
                    $qk = strval($q);
                    $omr_kind[$qk] = ($k === 'multi') ? 'multi' : 'single';
                    $omr_opts[$qk] = max(2, min(6, intval($sec['opts'] ?? 4) ?: 4));
                    $v = strtoupper(preg_replace('/[^A-Ea-e]/', '', strval($key[$qk] ?? '')));
                    if ($v !== '') $omr_key[$qk] = $v;
                    $pv = floatval(strval($pts[$qk] ?? 0));
                    if ($pv > 0 && $pv <= 100) $omr_pts[$qk] = $pv;
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
        // 全部题号（升序）：模板题组优先；无模板但有作答数据时按作答中出现过的题号
        $all_qs = [];
        foreach ($omr_secs as $sec) {
            for ($q = $sec['start']; $q < $sec['start'] + $sec['count']; $q++) $all_qs[intval($q)] = intval($q);
        }
        if (!$all_qs) {
            foreach ($students as $st) foreach ($st['per'] as $p) {
                foreach (array_keys($p['ans']) as $qk) { $q = intval(preg_replace('/[^0-9]/', '', strval($qk))); if ($q > 0) $all_qs[$q] = $q; }
            }
            ksort($all_qs);
            foreach (array_keys($all_qs) as $q) { $omr_kind[strval($q)] = 'single'; if (!isset($omr_opts[strval($q)])) $omr_opts[strval($q)] = 4; }
        } else {
            ksort($all_qs);
        }
        $omr_q_list = array_values($all_qs);
        $omr_sel = function ($ans, $q) {   // 该生该题所选字母（大写去杂，多选已排序）
            return strtoupper(preg_replace('/[^A-E]/', '', strval($ans[strval($q)] ?? '')));
        };
        $omr_correct = function ($sel, $q) use ($omr_key, $omr_kind) {   // 判对口径同识别端：单选全等、多选子集
            $key = strval($omr_key[strval($q)] ?? '');
            if ($key === '' || $sel === '') return false;
            if (($omr_kind[strval($q)] ?? 'single') === 'multi') return count(array_diff(str_split($sel), str_split($key))) === 0;
            return $sel === $key;
        };

        // ===== 表5：客观题统计（每次×每题：选A~E人数/率、答对人数/率、赋分得分率、难度、未涂率；列结构参考学情分析「客观题统计」） =====
        $rows5 = [];
        $has_key_q = false;
        foreach ($omr_q_list as $q) { if (isset($omr_key[strval($q)])) { $has_key_q = true; break; } }
        if ($has_key_q) {
            $head5 = ['次数', '题号', '题型', '题组', '参考人数', '作答人数', '正确答案'];
            $max_opts = 2;
            foreach ($omr_q_list as $q) $max_opts = max($max_opts, intval($omr_opts[strval($q)] ?? 4));
            for ($li = 0; $li < $max_opts; $li++) { $ch = 'ABCDE'[$li]; $head5[] = '选' . $ch . '人数'; $head5[] = '选' . $ch . '率(%)'; }
            $head5[] = '答对人数'; $head5[] = '答对率(%)'; $head5[] = '得分率(%)'; $head5[] = '难度'; $head5[] = '未涂率(%)';
            $rows5[] = $head5;
            foreach ($rounds as $rn) {
                $rno = intval($rn);
                foreach ($omr_q_list as $q) {
                    $qk = strval($q);
                    if (!isset($omr_key[$qk])) continue;
                    $dist = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];
                    $answered = 0; $correct = 0; $n_stu = 0; $sg = 0.0; $sfull = 0.0;
                    foreach ($students as $st) {
                        $p = $st['per'][$rno] ?? null;
                        if (!$p) continue;
                        $n_stu++;
                        $sel = $omr_sel($p['ans'], $q);
                        if ($sel === '') continue;
                        $answered++;
                        foreach (str_split($sel) as $ch) if (isset($dist[$ch])) $dist[$ch]++;
                        if ($omr_correct($sel, $q)) {
                            $correct++;
                            $sg += floatval($omr_pts[$qk] ?? 1);
                        }
                        if (isset($omr_pts[$qk])) $sfull += floatval($omr_pts[$qk]);
                    }
                    if (!$n_stu) continue;
                    $secTitle = '—';
                    foreach ($omr_secs as $sec) { if ($q >= $sec['start'] && $q < $sec['start'] + $sec['count']) { $secTitle = ($sec['title'] !== '' ? $sec['title'] : '题组'); break; } }
                    $opts = intval($omr_opts[$qk] ?? 4);
                    $row5 = [$rname[$rno], $q, ($omr_kind[$qk] ?? 'single') === 'multi' ? '多选' : '单选',
                             $secTitle, $n_stu, $answered, $omr_key[$qk]];
                    for ($li = 0; $li < $max_opts; $li++) {
                        $ch = 'ABCDE'[$li];
                        if ($li < $opts) {
                            $row5[] = $dist[$ch];
                            $row5[] = $n_stu > 0 ? $nf($dist[$ch] / $n_stu * 100) : '';
                        } else { $row5[] = ''; $row5[] = ''; }
                    }
                    $rateQ = $n_stu > 0 ? $correct / $n_stu * 100 : 0;   // 对题数制：答对率即得分率，难度=答对率/100
                    $row5[] = $correct;
                    $row5[] = $nf($rateQ);
                    $row5[] = ($scored && $sfull > 0) ? $nf($sg / $sfull * 100) : $nf($rateQ);
                    $row5[] = ($scored && $sfull > 0) ? $nf($sg / $sfull) : $nf($rateQ / 100);
                    $row5[] = $n_stu > 0 ? $nf(($n_stu - $answered) / $n_stu * 100) : '';
                    $rows5[] = $row5;
                }
            }
        }

        // ===== 表6：每生每题作答明细（每生×每次 × 每题所选选项；答对加 ✓，答错加 ✗，未涂留空） =====
        $rows6 = [];
        if ($omr_q_list) {
            $head6 = ['班级', '座号', '姓名', '编号', '次数', '总' . $s_lbl];
            foreach ($omr_q_list as $q) $head6[] = '第' . $q . '题';
            $rows6[] = $head6;
            foreach ($students as $st) {
                foreach ($rounds as $rn) {
                    $p = $st['per'][intval($rn)] ?? null;
                    if (!$p) continue;
                    $row6 = [$st['class_name'], $st['seat_no'], $st['name'], $st['student_no'], $rname[intval($rn)], $nf($p['g']) . '/' . $nf($p['t'])];
                    foreach ($omr_q_list as $q) {
                        $sel = $omr_sel($p['ans'], $q);
                        if ($sel === '') { $row6[] = ''; continue; }
                        $row6[] = $sel . ($omr_correct($sel, $q) ? '✓' : ((($omr_key[strval($q)] ?? '') !== '') ? '✗' : ''));
                    }
                    $rows6[] = $row6;
                }
            }
        }

        return ['kind' => 'omr', 'sheets' => [
            ['name' => '学生成绩', 'rows' => $rows1],
            ['name' => '一均三率及排名', 'rows' => $rows2],
            ['name' => '学生等级', 'rows' => $rows3],
            ['name' => '分数段统计', 'rows' => $rows4],
            ['name' => '客观题统计', 'rows' => $rows5],
            ['name' => '每生每题作答明细', 'rows' => $rows6],
        ]];
    }
}
if (!function_exists('build_omr_answers_export')) {
    /**
     * 单答题卡项目「每生每题作答明细」导出内容（dtype=omr_answers 专用）
     * 与成绩报表同名分表的差异：不要求已批改 —— score 为空但已作答的行同样导出（总得分列显示「未批改」），
     * 便于模板尚未配置答案键/分值时也能回查每个学生每道题的选择情况。
     * 行 = 每生×每次（同生同题次多条识别以最新一条为准）；列 = 每题所选选项（答对 ✓、答错 ✗、未涂留空、无答案键不标对错）
     */
    function build_omr_answers_export($conn, $project) {
        $pid = intval($project['id']);
        $nf = function ($v) {
            $s = rtrim(rtrim(number_format(floatval($v), 1, '.', ''), '0'), '.');
            return ($s === '' || $s === '-0') ? '0' : $s;
        };
        // 生效答案键上下文：绑定模板题组（题型/选项数/答案键）+ 项目独立录入答案补缺（模板优先）
        $omr_secs = []; $omr_kind = []; $omr_key = []; $omr_opts = [];
        $bind_tid = intval(get_setting($conn, 'omr_tpl_bind_' . $pid, '0'));
        if ($bind_tid > 0) {
            $stmt = mysqli_prepare($conn, "SELECT layout FROM omr_templates WHERE id = ? AND project_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $bind_tid, $pid);
            mysqli_stmt_execute($stmt);
            $trow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            $lay = $trow ? json_decode(strval($trow['layout']), true) : null;
            foreach ((is_array($lay) && is_array($lay['sections'] ?? null) ? $lay['sections'] : []) as $sec) {
                if (!is_array($sec)) continue;
                $k = strval($sec['kind'] ?? 'single');
                if ($k === 'blank' || $k === 'short') continue;
                $start = intval($sec['start'] ?? 1); $cnt = min(500, max(1, intval($sec['count'] ?? 0)));
                $omr_secs[] = ['title' => strval($sec['title'] ?? ''), 'start' => $start, 'count' => $cnt,
                               'kind' => ($k === 'multi' ? 'multi' : 'single')];
                $key = is_array($sec['key'] ?? null) ? $sec['key'] : [];
                for ($q = $start; $q < $start + $cnt; $q++) {
                    $qk = strval($q);
                    $omr_kind[$qk] = ($k === 'multi') ? 'multi' : 'single';
                    $omr_opts[$qk] = max(2, min(6, intval($sec['opts'] ?? 4) ?: 4));
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
        // 题号列表（升序）：模板题组优先；无模板但有作答数据时按作答中出现过的题号
        $all_qs = [];
        foreach ($omr_secs as $sec) {
            for ($q = $sec['start']; $q < $sec['start'] + $sec['count']; $q++) $all_qs[intval($q)] = intval($q);
        }
        $omr_sel = function ($ans, $q) {
            return strtoupper(preg_replace('/[^A-E]/', '', strval($ans[strval($q)] ?? '')));
        };
        $omr_correct = function ($sel, $q) use ($omr_key, $omr_kind) {
            $key = strval($omr_key[strval($q)] ?? '');
            if ($key === '' || $sel === '') return false;
            if (($omr_kind[strval($q)] ?? 'single') === 'multi') return count(array_diff(str_split($sel), str_split($key))) === 0;
            return $sel === $key;
        };
        // 学生（项目覆盖班级、未禁用，与成绩报表口径一致）
        $class_ids = get_project_class_ids($conn, $project);
        $students = [];
        if ($class_ids) {
            $in = implode(',', array_map('intval', $class_ids));
            $res = mysqli_query($conn, "SELECT c.name AS class_name, s.id AS sid, s.seat_no, s.name, s.student_no
                        FROM students s INNER JOIN classes c ON s.class_id = c.id
                        WHERE s.class_id IN ({$in}) AND s.disabled = 0
                        ORDER BY c.id, CAST(s.seat_no AS UNSIGNED), s.id");
            while ($row = mysqli_fetch_assoc($res)) {
                $students[intval($row['sid'])] = ['class_name' => $row['class_name'], 'seat_no' => strval($row['seat_no']),
                    'name' => $row['name'], 'student_no' => strval($row['student_no']), 'per' => []];
            }
        }
        // 识别/录入行（同生同题次最新一条为准；不要求已批改 —— score 为空的行同样导出）
        $res = mysqli_query($conn, "SELECT o.round_no, o.student_id, o.answers, o.score FROM omr_results o
                    INNER JOIN students s ON o.student_id = s.id
                    WHERE o.project_id = {$pid} AND o.answers IS NOT NULL AND o.answers <> '' AND o.answers <> 'null' AND s.disabled = 0
                    ORDER BY o.id ASC");
        while ($row = mysqli_fetch_assoc($res)) {
            $sid = intval($row['student_id']);
            if (!isset($students[$sid])) continue;
            $ans = json_decode(strval($row['answers']), true);
            $parts = explode('/', strval($row['score']));
            $students[$sid]['per'][intval($row['round_no'])] = [
                'score' => trim(strval($row['score'])) !== '' ? $nf(floatval($parts[0])) . (count($parts) > 1 ? '/' . $nf(floatval($parts[1])) : '') : '',
                'ans' => is_array($ans) ? $ans : [],
            ];
        }
        // 组表：题次列标题
        $rounds = project_rounds_list($conn, $pid);
        $titles = project_rounds_titles($conn, $pid);
        $rname = [];
        foreach ($rounds as $rn) {
            $t = trim(strval($titles[$rn] ?? ''));
            $rname[intval($rn)] = $t !== '' ? $t : '第' . intval($rn) . '次';
        }
        $rows = [];
        $head = ['班级', '座号', '姓名', '编号', '次数', '总得分'];
        foreach ($all_qs as $q) $head[] = '第' . $q . '题';
        $rows[] = $head;
        foreach ($students as $st) {
            foreach ($rounds as $rn) {
                $p = $st['per'][intval($rn)] ?? null;
                if (!$p) continue;
                $row = [$st['class_name'], $st['seat_no'], $st['name'], $st['student_no'], $rname[intval($rn)],
                        $p['score'] !== '' ? $p['score'] : '未批改'];
                foreach ($all_qs as $q) {
                    $sel = $omr_sel($p['ans'], $q);
                    if ($sel === '') { $row[] = ''; continue; }
                    $row[] = $sel . ($omr_correct($sel, $q) ? '✓' : ((($omr_key[strval($q)] ?? '') !== '') ? '✗' : ''));
                }
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
if (!function_exists('build_omr_report_export')) {
    /**
     * 单答题卡项目「统计报表」导出内容（dtype=omr_report 专用），四张分表与 stats.php 页面同口径：
     * 1) 多次汇总 —— 全项目每次一行：人数/平均分/平均得分率/最高最低/三率/满分零分（学校表·多次）
     * 2) 班级对比 —— 全部题次 × 全部班级（含全体行）：人数/平均分/得分率/三率/超均率（班级表·单次）
     * 3) 学生个人 —— 每生各次得分与得分率 + 总体得分率 + 等级(A≥80/B≥70/C≥60/D) + 班名(同分并列) + 超均率（学生个人表）
     * 4) 逐题分析 —— 全部题次 × 全部题号：题型/题组/正确答案/参与/作答/答对/正确率/各选项人数
     * 未批改行（score 为空）不参与统计，与页面口径一致。
     */
    function build_omr_report_export($conn, $project) {
        $pid = intval($project['id']);
        $num = function ($v) {
            $s = rtrim(rtrim(number_format(floatval($v), 1, '.', ''), '0'), '.');
            return ($s === '' || $s === '-0') ? '0' : $s;
        };
        // —— 题次 ——
        $rounds = [];
        $rtitles = project_rounds_titles($conn, $pid);
        foreach (project_rounds_list($conn, $pid) as $rn) $rounds[] = ['no' => intval($rn), 'title' => strval($rtitles[$rn] ?? '')];
        if (!$rounds) return null;
        $rname = [];
        foreach ($rounds as $r) $rname[$r['no']] = $r['title'] !== '' ? $r['title'] : ('第' . $r['no'] . '次');
        // —— 项目覆盖班级与学生 ——
        $classes = []; $students = [];
        $cls_ids = get_project_class_ids($conn, $project);
        $cls_ids = array_values(array_unique(array_map('intval', $cls_ids)));
        if ($cls_ids) {
            $idlist = implode(',', $cls_ids);
            $res = mysqli_query($conn, "SELECT id, name FROM classes WHERE id IN ({$idlist}) AND deleted_at IS NULL");
            while ($row = mysqli_fetch_assoc($res)) $classes[intval($row['id'])] = strval($row['name']);
            $res = mysqli_query($conn, "SELECT s.id, s.name, s.seat_no, s.student_no, s.class_id FROM students s
                                        WHERE s.class_id IN ({$idlist}) AND s.disabled = 0
                                        ORDER BY s.class_id ASC, CAST(s.seat_no AS UNSIGNED) ASC, s.id ASC");
            while ($row = mysqli_fetch_assoc($res)) {
                $cid = intval($row['class_id']);
                $students[intval($row['id'])] = ['name' => strval($row['name']), 'seat_no' => strval($row['seat_no']),
                    'student_no' => strval($row['student_no']), 'class_id' => $cid, 'class_name' => $classes[$cid] ?? ('班级#' . $cid)];
            }
        }
        // —— 识别结果（每生每题次最新一条，仅已批改行参与统计） ——
        $rows = [];   // [round][sid] = ['answers'=>[], 'g'=>float, 't'=>float]
        $res = mysqli_query($conn, "SELECT o.round_no, o.student_id, o.answers, o.score FROM omr_results o
                                    WHERE o.project_id = {$pid} AND o.student_id IS NOT NULL");
        while ($row = mysqli_fetch_assoc($res)) {
            $sid = intval($row['student_id']);
            if (!isset($students[$sid])) continue;
            $ans = json_decode(strval($row['answers']), true);
            if (!is_array($ans)) $ans = [];
            $g = 0.0; $t = 0.0;
            $parts = explode('/', strval($row['score']));
            if (count($parts) === 2 && floatval($parts[1]) > 0) { $g = floatval($parts[0]); $t = floatval($parts[1]); }
            $rows[intval($row['round_no'])][$sid] = ['answers' => $ans, 'g' => $g, 't' => $t];
        }
        // —— 生效答案上下文（与 stats.php/识别端同口径）：绑定模板题组 + 独立录入答案补缺 ——
        $secs = []; $kind = []; $key = [];
        $bind_tid = intval(get_setting($conn, 'omr_tpl_bind_' . $pid, '0'));
        if ($bind_tid > 0) {
            $stmt = mysqli_prepare($conn, "SELECT layout FROM omr_templates WHERE id = ? AND project_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $bind_tid, $pid);
            mysqli_stmt_execute($stmt);
            $trow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            $lay = $trow ? json_decode(strval($trow['layout']), true) : null;
            foreach ((is_array($lay) && is_array($lay['sections'] ?? null) ? $lay['sections'] : []) as $sec) {
                if (!is_array($sec)) continue;
                $k = strval($sec['kind'] ?? 'single');
                if ($k === 'blank' || $k === 'short') continue;
                $start = intval($sec['start'] ?? 1); $cnt = min(500, max(1, intval($sec['count'] ?? 0)));
                $secs[] = ['title' => strval($sec['title'] ?? ''), 'start' => $start, 'count' => $cnt,
                           'kind' => ($k === 'multi' ? 'multi' : 'single'),
                           'opts' => max(2, min(6, intval($sec['opts'] ?? 4) ?: 4))];
                $skey = is_array($sec['key'] ?? null) ? $sec['key'] : [];
                for ($q = $start; $q < $start + $cnt; $q++) {
                    $qk = strval($q);
                    $kind[$qk] = ($k === 'multi') ? 'multi' : 'single';
                    $v = strtoupper(preg_replace('/[^A-Ea-e]/', '', strval($skey[$qk] ?? '')));
                    if ($v !== '') $key[$qk] = $v;
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
                        if ($qk !== '' && $vv !== '' && empty($key[$qk])) $key[$qk] = $vv;
                    }
                }
            }
        }
        // —— 汇总函数（与 stats.php 同口径：优秀≥80 / 及格≥60 / 低分<30 / 满分≥99.95 / 零分≤0.05） ——
        $agg = function (array $set) use ($num) {
            $n = 0; $sg = 0.0; $st = 0.0; $maxg = null; $ming = null; $ex = 0; $pa = 0; $low = 0; $full = 0; $zero = 0;
            foreach ($set as $r) {
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
            return ['n' => $n, 'avg' => $num($sg / $n), 'rate' => ($st > 0 ? round($sg / $st * 100, 1) : 0),
                    'max' => $num($maxg), 'min' => $num($ming),
                    'ex' => round($ex / $n * 100, 1), 'pa' => round($pa / $n * 100, 1), 'low' => round($low / $n * 100, 1),
                    'full' => $full, 'zero' => $zero];
        };
        $sheets = [];
        // —— Sheet1 多次汇总 ——
        $r1 = [['次数', '标题', '参考人数', '平均分', '平均得分率(%)', '最高分', '最低分', '优秀率≥80%(%)', '及格率≥60%(%)', '低分率<30%(%)', '满分人数', '零分人数']];
        foreach ($rounds as $r) {
            $a = $agg($rows[$r['no']] ?? []);
            if (!$a) continue;
            $r1[] = ['第' . $r['no'] . '次', $r['title'], $a['n'], $a['avg'], $a['rate'], $a['max'], $a['min'], $a['ex'], $a['pa'], $a['low'], $a['full'], $a['zero']];
        }
        if (count($r1) > 1) $sheets[] = ['name' => '多次汇总', 'rows' => $r1];
        // —— Sheet2 班级对比（全部题次，各次含全体行 + 超均率） ——
        $r2 = [];
        $hdr2 = ['次数', '范围', '人数', '平均分', '平均得分率(%)', '优秀率≥80%(%)', '及格率≥60%(%)', '低分率<30%(%)', '超均率(%)'];
        foreach ($rounds as $r) {
            $set = $rows[$r['no']] ?? [];
            $all = $agg($set);
            if (!$all) continue;
            if (!count($r2)) $r2[] = $hdr2;
            $r2[] = ['第' . $r['no'] . '次', '全体', $all['n'], $all['avg'], $all['rate'], $all['ex'], $all['pa'], $all['low'], ''];
            foreach ($cls_ids as $cid) {
                if (!isset($classes[$cid])) continue;
                $subset = [];
                foreach ($set as $sid => $rr) if ($students[$sid]['class_id'] == $cid) $subset[] = $rr;
                $a = $agg($subset);
                if (!$a) continue;
                $over = ($all['rate'] > 0) ? round(($a['rate'] - $all['rate']) / $all['rate'] * 100, 1) : null;
                $r2[] = ['第' . $r['no'] . '次', $classes[$cid], $a['n'], $a['avg'], $a['rate'], $a['ex'], $a['pa'], $a['low'], $over];
            }
        }
        if (count($r2) > 1) $sheets[] = ['name' => '班级对比', 'rows' => $r2];
        // —— Sheet3 学生个人（各次得分/得分率 + 总体 + 等级 + 班名 + 超均率） ——
        $stu_rate = []; $cls_avg = []; $cls_rank = [];
        foreach ($students as $sid => $s) {
            $g = 0.0; $t = 0.0;
            foreach ($rounds as $r) {
                $rr = $rows[$r['no']][$sid] ?? null;
                if ($rr && $rr['t'] > 0) { $g += $rr['g']; $t += $rr['t']; }
            }
            if ($t > 0) $stu_rate[$sid] = round($g / $t * 100, 1);
        }
        foreach ($stu_rate as $sid => $rate) $cls_avg[$students[$sid]['class_id']][] = $rate;
        foreach ($cls_avg as $cid => $arr) $cls_avg[$cid] = count($arr) ? round(array_sum($arr) / count($arr), 1) : 0;
        {
            $bycls = [];
            foreach ($stu_rate as $sid => $rate) $bycls[$students[$sid]['class_id']][$sid] = $rate;
            foreach ($bycls as $cid => $m) {
                arsort($m);
                $cls_rank[$cid] = [];
                $i = 0; $prevRate = null; $prevRank = 0;
                foreach ($m as $sid => $rate) {
                    $i++;
                    $rk = ($rate === $prevRate && $prevRank > 0) ? $prevRank : $i;   // 同分并列
                    $cls_rank[$cid][$sid] = $rk;
                    $prevRate = $rate; $prevRank = $rk;
                }
            }
        }
        $r3 = [['班级', '座号', '姓名', '编号']];
        foreach ($rounds as $r) { $r3[0][] = $rname[$r['no']] . '得分'; $r3[0][] = $rname[$r['no']] . '得分率(%)'; }
        $r3[0][] = '总体得分率(%)'; $r3[0][] = '等级'; $r3[0][] = '班名'; $r3[0][] = '超均率(%)';
        foreach ($students as $sid => $s) {
            $rate = $stu_rate[$sid] ?? null;
            if ($rate === null) continue;
            $row = [$s['class_name'], $s['seat_no'], $s['name'], $s['student_no']];
            foreach ($rounds as $r) {
                $rr = $rows[$r['no']][$sid] ?? null;
                if ($rr && $rr['t'] > 0) { $row[] = $num($rr['g']) . '/' . $num($rr['t']); $row[] = round($rr['g'] / $rr['t'] * 100, 1); }
                else { $row[] = ''; $row[] = ''; }
            }
            $cavg = $cls_avg[$s['class_id']] ?? 0;
            $row[] = $rate;
            $row[] = ($rate >= 80 ? 'A' : ($rate >= 70 ? 'B' : ($rate >= 60 ? 'C' : 'D')));
            $row[] = $cls_rank[$s['class_id']][$sid] ?? '';
            $row[] = ($cavg > 0 ? round(($rate - $cavg) / $cavg * 100, 1) : '');
            $r3[] = $row;
        }
        if (count($r3) > 1) $sheets[] = ['name' => '学生个人', 'rows' => $r3];
        // —— Sheet4 逐题分析（全部题次 × 全部题号：选项分布/正确率） ——
        $r4 = [['次数', '题号', '题型', '题组', '参与人数', '作答人数', '正确答案', '答对人数', '正确率(%)',
                '选A人数', '选B人数', '选C人数', '选D人数', '选E人数']];
        foreach ($rounds as $r) {
            $set = $rows[$r['no']] ?? [];
            if (!count($set)) continue;
            foreach ($secs as $sec) {
                for ($q = $sec['start']; $q < $sec['start'] + $sec['count']; $q++) {
                    $qk = strval($q);
                    $dist = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];
                    $correct = 0; $answered = 0;
                    $kk = $key[$qk] ?? '';
                    foreach ($set as $rr) {
                        $sel = strtoupper(preg_replace('/[^A-E]/', '', strval($rr['answers'][$qk] ?? '')));
                        if ($sel === '') continue;
                        $answered++;
                        foreach (str_split($sel) as $ch) if (isset($dist[$ch])) $dist[$ch]++;
                        if ($kk === '') continue;
                        $ok = (($kind[$qk] ?? 'single') === 'multi') ? (count(array_diff(str_split($sel), str_split($kk))) === 0) : ($sel === $kk);
                        if ($ok) $correct++;
                    }
                    $r4[] = ['第' . $r['no'] . '次', $q, (($kind[$qk] ?? $sec['kind']) === 'multi' ? '多选' : '单选'), $sec['title'],
                             count($set), $answered, $kk, $correct, ($correct > 0 ? round($correct / count($set) * 100, 1) : 0),
                             $dist['A'], $dist['B'], $dist['C'], $dist['D'], $dist['E']];
                }
            }
        }
        if (count($r4) > 1) $sheets[] = ['name' => '逐题分析', 'rows' => $r4];
        return $sheets ? $sheets : null;
    }
}
if (!function_exists('build_project_export')) {
    /**
     * 单项目导出内容构建（dtype=project 单导出与 dtype=projects 批量共用）
     * 答题卡项目 → kind=omr，sheets=成绩报表四张分表（学生成绩/一均三率及排名/学生等级/分数段统计）
     * 打卡项目 → kind=daily，sheets=三张分表（是否登记/登记时间/评价内容，每次打卡一列，列标题=日期）
     * 一次性项目 → kind=count，rows=CSV 行（班级/座号/姓名/编号/是否登记/登记时间/评价内容，范围外登记视为未登记）
     */
    function build_project_export($conn, $project, $dl_from, $dl_to, $reg_in_range) {
        // 答题卡项目：仿校级成绩报表结构导出（成绩按识别结果全量统计，时间范围不适用）
        if (($project['mode'] ?? '') === 'omr') return build_omr_project_export($conn, $project);
        $class_ids = get_project_class_ids($conn, $project);
        $students = [];
        if ($class_ids) {
            $in = implode(',', array_map('intval', $class_ids));
            $res = mysqli_query($conn, "SELECT c.name AS class_name, s.id AS sid, s.seat_no, s.name, s.student_no,
                        IFNULL(r.registered, 0) AS registered, r.registered_at, r.eval_value
                    FROM students s
                    INNER JOIN classes c ON s.class_id = c.id
                    LEFT JOIN records r ON r.student_id = s.id AND r.project_id = " . intval($project['id']) . "
                    WHERE s.class_id IN ({$in})
                    ORDER BY c.id, CAST(s.seat_no AS UNSIGNED), s.id");
            while ($row = mysqli_fetch_assoc($res)) $students[] = $row;
        }
        if (($project['mode'] ?? 'count') === 'daily') {
            list($dates, $dmap) = get_project_daily_data($conn, $project['id'], $class_ids, $dl_from, $dl_to);
            $head = ['班级', '座号', '姓名', '编号'];
            $reg_head = $head; $time_head = $head;
            foreach ($dates as $d) { $reg_head[] = $d; $time_head[] = $d; }
            $reg_rows = [$reg_head]; $time_rows = [$time_head]; $eval_rows = [$head];
            foreach ($students as $s) {
                $base = [$s['class_name'], $s['seat_no'], $s['name'], $s['student_no']];
                $reg = $base; $time = $base;
                foreach ($dates as $d) {
                    $reg[] = isset($dmap[intval($s['sid'])][$d]) ? '✓' : '';
                    $time[] = $dmap[intval($s['sid'])][$d] ?? '';
                }
                $reg_rows[] = $reg;
                $time_rows[] = $time;
                $eval_rows[] = array_merge($base, [$s['eval_value'] ?: '']);
            }
            return ['kind' => 'daily', 'sheets' => [
                ['name' => '是否登记', 'rows' => $reg_rows],
                ['name' => '登记时间', 'rows' => $time_rows],
                ['name' => '评价内容', 'rows' => $eval_rows],
            ]];
        }
        $rows = [['班级', '座号', '姓名', '编号', '是否登记', '登记时间', '评价内容']];
        foreach ($students as $s) {
            $reg_ok = $s['registered'] == 1 && $reg_in_range($s['registered_at']);
            $rows[] = [
                $s['class_name'],
                $s['seat_no'],
                $s['name'],
                $s['student_no'],
                $reg_ok ? '已登记' : '未登记',
                $reg_ok ? $s['registered_at'] : '',
                $s['eval_value'] ?: '',
            ];
        }
        return ['kind' => 'count', 'rows' => $rows];
    }
}
if (!function_exists('get_class_daily_data')) {
    // 多项目 × 单班级：[pid]=[日期列表] + [pid][student_id][reg_date]=打卡时间；$from/$to 可选日期范围过滤
    function get_class_daily_data($conn, $pids, $class_id, $from = '', $to = '') {
        $dates_map = []; $map = [];
        if (!$pids) return [$dates_map, $map];
        $in = implode(',', array_map('intval', $pids));
        $cond = '';
        if ($from !== '') $cond .= " AND rd.reg_date >= '" . mysqli_real_escape_string($conn, $from) . "'";
        if ($to !== '') $cond .= " AND rd.reg_date <= '" . mysqli_real_escape_string($conn, $to) . "'";
        $res = mysqli_query($conn, "SELECT rd.project_id, rd.student_id, rd.reg_date, rd.created_at FROM record_days rd
                    INNER JOIN students s ON rd.student_id = s.id
                    WHERE rd.project_id IN ({$in}) AND s.class_id = " . intval($class_id) . " {$cond} ORDER BY rd.reg_date");
        $last = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $pid = intval($row['project_id']);
            if (!isset($last[$pid]) || $last[$pid] !== $row['reg_date']) { $dates_map[$pid][] = $row['reg_date']; $last[$pid] = $row['reg_date']; }
            $map[$pid][intval($row['student_id'])][$row['reg_date']] = (string)$row['created_at'];
        }
        return [$dates_map, $map];
    }
}
if (!function_exists('get_class_records_map')) {
    // 多项目 × 单班级：[pid][student_id] = ['registered','registered_at','eval_value']
    // $from/$to 可选：范围外登记视为「未登记」（登记时间清空；评价内容不受范围影响）
    function get_class_records_map($conn, $pids, $class_id, $from = '', $to = '') {
        $map = [];
        if (!$pids) return $map;
        $in = implode(',', array_map('intval', $pids));
        $res = mysqli_query($conn, "SELECT r.project_id, r.student_id, r.registered, r.registered_at, r.eval_value
                    FROM records r INNER JOIN students s ON r.student_id = s.id
                    WHERE r.project_id IN ({$in}) AND s.class_id = " . intval($class_id));
        $ranged = ($from !== '' || $to !== '');
        $from_ts = $from !== '' ? $from : '';
        $to_excl = $to !== '' ? date('Y-m-d', strtotime($to) + 86400) : '';
        while ($row = mysqli_fetch_assoc($res)) {
            if ($ranged && $row['registered'] == 1) {
                $at = (string)$row['registered_at'];
                $in_range = $at !== '' && ($from_ts === '' || $at >= $from_ts) && ($to_excl === '' || $at < $to_excl);
                if (!$in_range) { $row['registered'] = 0; $row['registered_at'] = ''; }
            }
            $map[intval($row['project_id'])][intval($row['student_id'])] = $row;
        }
        return $map;
    }
}

// ===== 班级报表构建（单班导出与批量导出共用） =====
if (!function_exists('class_export_ctx')) {
    // 班级导出上下文：班级 + 可见项目 + 学生 + 打卡/登记数据映射；$visible_projects 可传已取好的列表（批量导出避免重复查询）
    // $from/$to 时间范围（YYYY-MM-DD，空=全部）；返回 ['error' => 提示] 或 ['class', 'projects', 'students', 'pids', 'dates_map', 'dmap', 'rec_map']
    function class_export_ctx($conn, $class_id, $teacher_id, $from = '', $to = '', $visible_projects = null) {
        if (!can_view_class($conn, $teacher_id, $class_id)) return ['error' => '无权限导出该班级'];
        $stmt = mysqli_prepare($conn, "SELECT id, name FROM classes WHERE id = ? AND deleted_at IS NULL");
        mysqli_stmt_bind_param($stmt, "i", $class_id);
        mysqli_stmt_execute($stmt);
        $class = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$class) return ['error' => '班级不存在'];

        // 覆盖该班级的可见项目（与单班导出一致的权限口径）
        $projects_for_class = [];
        foreach ($visible_projects !== null ? $visible_projects : get_visible_projects($conn, $teacher_id) as $p) {
            if (in_array(intval($class_id), get_project_class_ids($conn, $p), true)) $projects_for_class[] = $p;
        }
        if (!$projects_for_class) return ['error' => '该班级暂无可见项目'];

        $students = [];
        $res = mysqli_query($conn, "SELECT id, seat_no, name, student_no FROM students WHERE class_id = " . intval($class_id) . " ORDER BY CAST(seat_no AS UNSIGNED), id");
        while ($row = mysqli_fetch_assoc($res)) $students[] = $row;

        $pids = array_map('intval', array_column($projects_for_class, 'id'));
        list($dates_map, $dmap) = get_class_daily_data($conn, $pids, $class_id, $from, $to);
        $rec_map = get_class_records_map($conn, $pids, $class_id, $from, $to);
        return ['class' => $class, 'projects' => $projects_for_class, 'students' => $students,
                'pids' => $pids, 'dates_map' => $dates_map, 'dmap' => $dmap, 'rec_map' => $rec_map];
    }
}
if (!function_exists('build_class_matrix_sheets')) {
    // 登记矩阵：一个项目一张表（打卡项目=日期列✓矩阵，一次性=已登记（评价））
    function build_class_matrix_sheets($ctx) {
        $sheets = [];
        foreach ($ctx['projects'] as $p) {
            $pid = intval($p['id']);
            $head = ['座号', '姓名', '编号'];
            if (($p['mode'] ?? 'count') === 'daily') {
                $dates = $ctx['dates_map'][$pid] ?? [];
                foreach ($dates as $d) $head[] = $d;
                $rows = [$head];
                foreach ($ctx['students'] as $s) {
                    $line = [$s['seat_no'], $s['name'], $s['student_no']];
                    foreach ($dates as $d) $line[] = isset($ctx['dmap'][$pid][intval($s['id'])][$d]) ? '✓' : '';
                    $rows[] = $line;
                }
            } else {
                $head[] = $p['name'];
                $rows = [$head];
                foreach ($ctx['students'] as $s) {
                    $rec = $ctx['rec_map'][$pid][intval($s['id'])] ?? null;
                    $rows[] = [$s['seat_no'], $s['name'], $s['student_no'],
                        ($rec && $rec['registered'] == 1) ? ($rec['eval_value'] ? '已登记（' . $rec['eval_value'] . '）' : '已登记') : '未登记'];
                }
            }
            $sheets[] = ['name' => $p['name'], 'rows' => $rows];
        }
        return $sheets;
    }
}
if (!function_exists('build_class_detail_sheets')) {
    // 全部项目明细：是否登记/登记时间/评价内容三张分表（打卡项目按日期展开列）
    function build_class_detail_sheets($ctx) {
        $col_meta = [];
        foreach ($ctx['projects'] as $p) {
            $pid = intval($p['id']);
            if (($p['mode'] ?? 'count') === 'daily') {
                foreach ($ctx['dates_map'][$pid] ?? [] as $d) $col_meta[] = ['type' => 'date', 'pid' => $pid, 'date' => $d, 'pname' => $p['name']];
            } else {
                $col_meta[] = ['type' => 'count', 'pid' => $pid, 'pname' => $p['name']];
            }
        }
        $reg_head = ['座号', '姓名', '编号']; $time_head = ['座号', '姓名', '编号'];
        foreach ($col_meta as $cm) {
            $label = $cm['type'] === 'date' ? ($cm['pname'] . ' ' . $cm['date']) : $cm['pname'];
            $reg_head[] = $label;
            $time_head[] = $label;
        }
        $eval_head = ['座号', '姓名', '编号'];
        foreach ($ctx['projects'] as $p) $eval_head[] = $p['name'];

        $reg_rows = [$reg_head]; $time_rows = [$time_head]; $eval_rows = [$eval_head];
        foreach ($ctx['students'] as $s) {
            $base = [$s['seat_no'], $s['name'], $s['student_no']];
            $reg = $base; $time = $base;
            foreach ($col_meta as $cm) {
                if ($cm['type'] === 'date') {
                    $reg[] = isset($ctx['dmap'][$cm['pid']][intval($s['id'])][$cm['date']]) ? '✓' : '';
                    $time[] = $ctx['dmap'][$cm['pid']][intval($s['id'])][$cm['date']] ?? '';
                } else {
                    $rec = $ctx['rec_map'][$cm['pid']][intval($s['id'])] ?? null;
                    $reg[] = ($rec && $rec['registered'] == 1) ? '✓' : '';
                    $time[] = ($rec && $rec['registered'] == 1) ? (string)$rec['registered_at'] : '';
                }
            }
            $reg_rows[] = $reg;
            $time_rows[] = $time;
            $eval = $base;
            foreach ($ctx['projects'] as $p) {
                $rec = $ctx['rec_map'][intval($p['id'])][intval($s['id'])] ?? null;
                $eval[] = ($rec && $rec['eval_value'] !== null) ? $rec['eval_value'] : '';
            }
            $eval_rows[] = $eval;
        }
        return [
            ['name' => '是否登记', 'rows' => $reg_rows],
            ['name' => '登记时间', 'rows' => $time_rows],
            ['name' => '评价内容', 'rows' => $eval_rows],
        ];
    }
}
if (!function_exists('build_class_students_sheets')) {
    // 全部学生：每位学生一张表，列出全部项目登记情况（打卡项目按日期展开）
    function build_class_students_sheets($ctx) {
        $sheets = [];
        foreach ($ctx['students'] as $s) {
            $rows = [['项目', '日期', '是否登记']];
            foreach ($ctx['projects'] as $p) {
                $pid = intval($p['id']);
                if (($p['mode'] ?? 'count') === 'daily') {
                    foreach ($ctx['dates_map'][$pid] ?? [] as $d) {
                        $rows[] = [$p['name'], $d, isset($ctx['dmap'][$pid][intval($s['id'])][$d]) ? '✓' : ''];
                    }
                } else {
                    $rec = $ctx['rec_map'][$pid][intval($s['id'])] ?? null;
                    $rows[] = [$p['name'], '', ($rec && $rec['registered'] == 1) ? '已登记' : '未登记'];
                }
            }
            $sname = trim($s['seat_no'] . ' ' . $s['name']);
            if ($sname === '') $sname = $s['name'] !== '' ? $s['name'] : ('学生' . $s['id']);
            $sheets[] = ['name' => $sname, 'rows' => $rows];
        }
        return $sheets;
    }
}

// 各班级汇总行（班级, 学生数, 登记人次, 评价人次, 登记率）
// $from/$to 可选：设置后登记人次=一次性项目登记数 + 打卡项目范围内打卡人次（避免与原口径混淆）
if (!function_exists('build_class_summary')) {
    function build_class_summary($conn, $class_ids, $from = '', $to = '') {
        $rows = [];
        if (!$class_ids) return $rows;
        $in = implode(',', array_map('intval', $class_ids));
        if ($from !== '' || $to !== '') {
            // 时间范围口径：登记/打卡/评价均按时间过滤（派生表在 MySQL SELECT 列表内不受支持，改为分组查询后合并）
            $regc = '';
            if ($from !== '') $regc .= " AND r.registered_at >= '" . mysqli_real_escape_string($conn, $from) . " 00:00:00'";
            if ($to !== '') $regc .= " AND r.registered_at < '" . date('Y-m-d', strtotime($to) + 86400) . " 00:00:00'";
            $dayc = '';
            if ($from !== '') $dayc .= " AND rd.reg_date >= '" . mysqli_real_escape_string($conn, $from) . "'";
            if ($to !== '') $dayc .= " AND rd.reg_date <= '" . mysqli_real_escape_string($conn, $to) . "'";
            $evc = '';
            if ($from !== '') $evc .= " AND r.eval_at >= '" . mysqli_real_escape_string($conn, $from) . " 00:00:00'";
            if ($to !== '') $evc .= " AND r.eval_at < '" . date('Y-m-d', strtotime($to) + 86400) . " 00:00:00'";
            $stu = []; $regc_map = []; $dayc_map = []; $eval_map = [];
            $res2 = mysqli_query($conn, "SELECT class_id, COUNT(*) AS cnt FROM students WHERE class_id IN ({$in}) GROUP BY class_id");
            while ($row = mysqli_fetch_assoc($res2)) $stu[intval($row['class_id'])] = intval($row['cnt']);
            $res2 = mysqli_query($conn, "SELECT p.class_id AS cid, COUNT(*) AS cnt FROM records r
                        INNER JOIN projects p ON r.project_id = p.id
                        WHERE r.registered = 1 AND p.deleted_at IS NULL AND p.mode <> 'daily' AND p.class_id IN ({$in}) {$regc}
                        GROUP BY p.class_id");
            while ($row = mysqli_fetch_assoc($res2)) $regc_map[intval($row['cid'])] = intval($row['cnt']);
            $res2 = mysqli_query($conn, "SELECT p.class_id AS cid, COUNT(*) AS cnt FROM record_days rd
                        INNER JOIN projects p ON rd.project_id = p.id
                        INNER JOIN students s2 ON rd.student_id = s2.id AND s2.class_id = p.class_id
                        WHERE p.deleted_at IS NULL AND p.mode = 'daily' AND p.class_id IN ({$in}) {$dayc}
                        GROUP BY p.class_id");
            while ($row = mysqli_fetch_assoc($res2)) $dayc_map[intval($row['cid'])] = intval($row['cnt']);
            $res2 = mysqli_query($conn, "SELECT p.class_id AS cid, COUNT(*) AS cnt FROM records r
                        INNER JOIN projects p ON r.project_id = p.id
                        WHERE r.registered = 1 AND p.deleted_at IS NULL AND r.eval_value IS NOT NULL AND r.eval_value != '' AND p.class_id IN ({$in}) {$evc}
                        GROUP BY p.class_id");
            while ($row = mysqli_fetch_assoc($res2)) $eval_map[intval($row['cid'])] = intval($row['cnt']);
            $res2 = mysqli_query($conn, "SELECT c.id, c.name AS class_name FROM classes c
                        WHERE c.id IN ({$in}) ORDER BY c.id");
            while ($crow = mysqli_fetch_assoc($res2)) {
                $cid_x = intval($crow['id']);
                $rc_x = ($regc_map[$cid_x] ?? 0) + ($dayc_map[$cid_x] ?? 0);
                $sc_x = $stu[$cid_x] ?? 0;
                $rate = $sc_x > 0 ? round($rc_x / $sc_x * 100) : 0;
                $rows[] = [
                    $crow['class_name'],
                    $sc_x,
                    $rc_x,
                    $eval_map[$cid_x] ?? 0,
                    $rate . '%',
                ];
            }
            return $rows;
        }
        $res = mysqli_query($conn, "SELECT c.id, c.name AS class_name,
                (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id) AS student_count,
                (SELECT COUNT(*) FROM records r INNER JOIN projects p ON r.project_id = p.id
                    WHERE r.registered = 1 AND p.deleted_at IS NULL AND p.class_id = c.id) AS registered_count,
                (SELECT COUNT(*) FROM records r INNER JOIN projects p ON r.project_id = p.id
                    WHERE r.registered = 1 AND p.deleted_at IS NULL AND r.eval_value IS NOT NULL AND r.eval_value != '' AND p.class_id = c.id) AS evaluated_count
                FROM classes c
                WHERE c.id IN ({$in}) ORDER BY c.id");
        while ($row = mysqli_fetch_assoc($res)) {
            $rate = $row['student_count'] > 0 ? round($row['registered_count'] / $row['student_count'] * 100) : 0;
            $rows[] = [
                $row['class_name'],
                $row['student_count'],
                $row['registered_count'],
                $row['evaluated_count'],
                $rate . '%',
            ];
        }
        return $rows;
    }
}

// ===== 数据总览接口（页面仪表盘图表 JSON；口径与导出一致：一次性按 registered_at、打卡按 reg_date 过滤） =====
if (($_GET['action'] ?? '') === 'stats_data') {
    header('Content-Type: application/json; charset=utf-8');
    $st_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
    $st_to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '';
    if ($st_from !== '' && $st_to !== '' && $st_from > $st_to) { $t = $st_from; $st_from = $st_to; $st_to = $t; }

    $out = ['kpi' => ['classes' => 0, 'students' => 0, 'projects' => 0, 'regs' => 0, 'evals' => 0, 'rate' => 0],
            'class_rates' => [], 'trend' => [], 'eval_dist' => []];
    $vis_ids = get_visible_class_ids($conn, $current_teacher_id);
    if ($vis_ids) {
        // 班级汇总（复用班级汇总口径）
        $summary = build_class_summary($conn, $vis_ids, $st_from, $st_to);
        $stu_total = 0; $reg_total = 0; $eval_total = 0;
        foreach ($summary as $row) {
            $cname = strval($row[0]);
            $stu = intval($row[1]); $regs = intval($row[2]); $evals = intval($row[3]);
            $rate = $stu > 0 ? round($regs / $stu * 100, 1) : 0;
            $out['class_rates'][] = ['name' => $cname, 'students' => $stu, 'regs' => $regs, 'evals' => $evals, 'rate' => $rate];
            $stu_total += $stu; $reg_total += $regs; $eval_total += $evals;
        }
        // 项目总数（覆盖可见班级的项目）
        $proj_cnt = 0;
        foreach (get_visible_projects($conn, $current_teacher_id) as $p) {
            foreach (get_project_class_ids($conn, $p) as $cid) {
                if (in_array(intval($cid), $vis_ids, true)) { $proj_cnt++; break; }
            }
        }
        $out['kpi'] = ['classes' => count($summary), 'students' => $stu_total, 'projects' => $proj_cnt,
                       'regs' => $reg_total, 'evals' => $eval_total,
                       'rate' => $stu_total > 0 ? round($reg_total / $stu_total * 100, 1) : 0];

        // 每日动态趋势：无范围=近30天；有范围=范围内（超120天截取最近120天）
        if ($st_from === '' && $st_to === '') {
            $t_start = date('Y-m-d', strtotime('-29 days')); $t_end = date('Y-m-d');
        } else {
            $t_end = $st_to !== '' ? $st_to : date('Y-m-d');
            $t_start = $st_from !== '' ? $st_from : date('Y-m-d', strtotime($t_end) - 29 * 86400);
            if (strtotime($t_end) - strtotime($t_start) > 119 * 86400) $t_start = date('Y-m-d', strtotime($t_end) - 119 * 86400);
        }
        $in = implode(',', array_map('intval', $vis_ids));
        $day_map = [];
        $res = mysqli_query($conn, "SELECT rd.reg_date AS d, COUNT(*) AS c FROM record_days rd
                    INNER JOIN projects p ON rd.project_id = p.id AND p.deleted_at IS NULL AND p.mode = 'daily'
                    INNER JOIN students s ON rd.student_id = s.id
                    WHERE s.class_id IN ({$in}) AND rd.reg_date >= '" . mysqli_real_escape_string($conn, $t_start) . "'
                      AND rd.reg_date <= '" . mysqli_real_escape_string($conn, $t_end) . "' GROUP BY rd.reg_date");
        while ($row = mysqli_fetch_assoc($res)) $day_map[$row['d']] = ['check' => intval($row['c']), 'reg' => 0];
        $res = mysqli_query($conn, "SELECT DATE(r.registered_at) AS d, COUNT(*) AS c FROM records r
                    INNER JOIN projects p ON r.project_id = p.id AND p.deleted_at IS NULL AND p.mode != 'daily'
                    INNER JOIN students s ON r.student_id = s.id
                    WHERE s.class_id IN ({$in}) AND r.registered = 1 AND r.registered_at >= '" . mysqli_real_escape_string($conn, $t_start) . " 00:00:00'
                      AND r.registered_at < '" . date('Y-m-d', strtotime($t_end) + 86400) . " 00:00:00' GROUP BY DATE(r.registered_at)");
        while ($row = mysqli_fetch_assoc($res)) {
            $d = $row['d'];
            if (!isset($day_map[$d])) $day_map[$d] = ['check' => 0, 'reg' => 0];
            $day_map[$d]['reg'] = intval($row['c']);
        }
        for ($ts = strtotime($t_start); $ts <= strtotime($t_end); $ts += 86400) {
            $d = date('Y-m-d', $ts);
            $out['trend'][] = ['date' => $d, 'check' => $day_map[$d]['check'] ?? 0, 'reg' => $day_map[$d]['reg'] ?? 0];
        }

        // 评价内容分布（Top 10，按 eval_at 落范围过滤；留空=全部）
        $evc = '';
        if ($st_from !== '') $evc .= " AND r.eval_at >= '" . mysqli_real_escape_string($conn, $st_from) . " 00:00:00'";
        if ($st_to !== '') $evc .= " AND r.eval_at < '" . date('Y-m-d', strtotime($st_to) + 86400) . " 00:00:00'";
        $res = mysqli_query($conn, "SELECT r.eval_value AS v, COUNT(*) AS c FROM records r
                    INNER JOIN projects p ON r.project_id = p.id AND p.deleted_at IS NULL
                    INNER JOIN students s ON r.student_id = s.id
                    WHERE s.class_id IN ({$in}) AND r.eval_value IS NOT NULL AND r.eval_value != '' {$evc}
                    GROUP BY r.eval_value ORDER BY c DESC LIMIT 10");
        while ($row = mysqli_fetch_assoc($res)) $out['eval_dist'][] = ['v' => $row['v'], 'c' => intval($row['c'])];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit();
}

// ===== 下载处理 =====
if (($_GET['action'] ?? '') === 'download') {
    $dtype = $_GET['dtype'] ?? '';
    $id = intval($_GET['id'] ?? 0);
    $date = date('Ymd_Hi');

    // ---- 时间范围（可选 from/to=YYYY-MM-DD）：登记按 registered_at、打卡按 reg_date 过滤；留空=全部时间 ----
    $dl_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
    $dl_to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '';
    if ($dl_from !== '' && $dl_to !== '' && $dl_from > $dl_to) { $t = $dl_from; $dl_from = $dl_to; $dl_to = $t; }
    $dl_label = ($dl_from !== '' || $dl_to !== '') ? ($dl_from . ' ~ ' . ($dl_to !== '' ? $dl_to : '今天')) : '全部时间';
    $dl_suffix = ($dl_from !== '' || $dl_to !== '') ? ('_' . str_replace('-', '', $dl_from) . '-' . str_replace('-', '', $dl_to)) : '';
    // 一次性项目登记是否落在范围内（含端点；$to 含当天）
    $reg_in_range = function ($at) use ($dl_from, $dl_to) {
        if ($dl_from === '' && $dl_to === '') return true;
        $at = (string)$at;
        if ($at === '') return false;
        return ($dl_from === '' || $at >= $dl_from) && ($dl_to === '' || $at < date('Y-m-d', strtotime($dl_to) + 86400));
    };

    // ---- 单项目导出（答题卡项目=成绩报表 Excel 四张分表；打卡项目=Excel XML 三张分表，每次打卡一列；一次性项目=CSV） ----
    if ($dtype === 'project') {
        $project = get_project_for($conn, $id, $current_teacher_id, 'operate');
        if (!$project) exit('无权限导出该项目');
        $exp = build_project_export($conn, $project, $dl_from, $dl_to, $reg_in_range);
        if ($exp['kind'] === 'omr') {
            output_excel_xml('成绩报表_' . $project['name'] . '_' . $date . $dl_suffix . '.xls', $exp['sheets']);
        }
        if ($exp['kind'] === 'daily') {
            output_excel_xml('项目导出_' . $project['name'] . '_' . $date . $dl_suffix . '.xls', $exp['sheets']);
        }
        output_csv('项目导出_' . $project['name'] . '_' . $date . $dl_suffix . '.csv', $exp['rows']);
    }

    // ---- 单项目统计报表导出（答题卡项目：多次汇总/班级对比/学生个人/逐题分析，四张分表与 stats.php 页面同口径） ----
    if ($dtype === 'omr_report') {
        $project = get_project_for($conn, $id, $current_teacher_id, 'operate');
        if (!$project) exit('无权限导出该项目');
        if (($project['mode'] ?? '') !== 'omr') exit('非答题卡模式项目，无统计报表');
        $sheets = build_omr_report_export($conn, $project);
        if (!$sheets) exit('暂无统计数据，请先识别或录入并配置答案');
        output_excel_xml('统计报表_' . $project['name'] . '_' . $date . '.xls', $sheets);
    }

    // ---- 单项目每生每题作答明细导出（答题卡项目：每生×每次×每题所选选项，答对✓答错✗未涂留空；Excel 单分表；未批改的作答行同样导出） ----
    if ($dtype === 'omr_answers') {
        $project = get_project_for($conn, $id, $current_teacher_id, 'operate');
        if (!$project) exit('无权限导出该项目');
        if (($project['mode'] ?? '') !== 'omr') exit('非答题卡模式项目，无作答明细');
        $rows = build_omr_answers_export($conn, $project);
        if (count($rows) <= 1) exit('暂无作答数据，请先识别或录入');
        output_excel_xml('作答明细_' . $project['name'] . '_' . $date . '.xls', [['name' => '每生每题作答明细', 'rows' => $rows]]);
    }

    // ---- 打卡项目每日打卡明细导出（每生每日期一行，仅打卡模式项目） ----
    if ($dtype === 'project_daily') {
        $project = get_project_for($conn, $id, $current_teacher_id, 'operate');
        if (!$project) exit('无权限导出该项目');
        if (($project['mode'] ?? 'count') !== 'daily') exit('仅打卡模式项目支持每日打卡明细导出');
        $class_ids = get_project_class_ids($conn, $project);
        $rows = [['班级', '座号', '姓名', '编号', '项目', '打卡日期', '打卡时间']];
        if ($class_ids) {
            $in = implode(',', array_map('intval', $class_ids));
            $pid = intval($project['id']);
            $cond = '';
            if ($dl_from !== '') $cond .= " AND rd.reg_date >= '" . mysqli_real_escape_string($conn, $dl_from) . "'";
            if ($dl_to !== '') $cond .= " AND rd.reg_date <= '" . mysqli_real_escape_string($conn, $dl_to) . "'";
            $res = mysqli_query($conn, "SELECT c.name AS class_name, s.seat_no, s.name, s.student_no, rd.reg_date, rd.created_at
                        FROM record_days rd
                        INNER JOIN students s ON rd.student_id = s.id
                        INNER JOIN classes c ON s.class_id = c.id
                        WHERE rd.project_id = {$pid} AND s.class_id IN ({$in}) {$cond}
                        ORDER BY rd.reg_date, c.id, CAST(s.seat_no AS UNSIGNED), s.id");
            while ($row = mysqli_fetch_assoc($res)) {
                $rows[] = [
                    $row['class_name'],
                    $row['seat_no'],
                    $row['name'],
                    $row['student_no'],
                    $project['name'],
                    $row['reg_date'],
                    $row['created_at'] ?: '',
                ];
            }
        }
        output_csv('每日打卡明细_' . $project['name'] . '_' . $date . $dl_suffix . '.csv', $rows);
    }

    // ---- 班级导出（登记矩阵：Excel XML，一个项目一张表；打卡项目=日期列✓矩阵） ----
    if ($dtype === 'class') {
        $ctx = class_export_ctx($conn, $id, $current_teacher_id, $dl_from, $dl_to);
        if (isset($ctx['error'])) exit($ctx['error']);
        output_excel_xml('班级导出_' . $ctx['class']['name'] . '_' . $date . $dl_suffix . '.xls', build_class_matrix_sheets($ctx));
    }

    // ---- 班级导出（全部项目明细：Excel XML，是否登记/登记时间/评价内容三张分表，打卡项目按日期展开列） ----
    if ($dtype === 'class_detail') {
        $ctx = class_export_ctx($conn, $id, $current_teacher_id, $dl_from, $dl_to);
        if (isset($ctx['error'])) exit($ctx['error']);
        output_excel_xml('班级全部项目明细_' . $ctx['class']['name'] . '_' . $date . $dl_suffix . '.xls', build_class_detail_sheets($ctx));
    }

    // ---- 班级导出（全部学生：Excel XML，每位学生一张表，列出全部项目登记情况，打卡项目按日期展开） ----
    if ($dtype === 'class_students') {
        $ctx = class_export_ctx($conn, $id, $current_teacher_id, $dl_from, $dl_to);
        if (isset($ctx['error'])) exit($ctx['error']);
        output_excel_xml('班级全部学生_' . $ctx['class']['name'] . '_' . $date . $dl_suffix . '.xls', build_class_students_sheets($ctx));
    }

    // ---- 学生登记情况导出（Excel XML 三张分表，打卡项目按日期展开；stats.php 学生统计页入口） ----
    if ($dtype === 'student_detail') {
        $class_id = intval($_GET['class_id'] ?? 0);
        if (!can_view_class($conn, $current_teacher_id, $class_id)) exit('无权限导出该学生统计');
        $stmt = mysqli_prepare($conn, "SELECT id, name, seat_no, student_no FROM students WHERE id = ? AND class_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $id, $class_id);
        mysqli_stmt_execute($stmt);
        $student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$student) exit('学生不存在');

        // 与 stats.php 学生统计视图同口径：本班的班级项目
        $projects = [];
        $res = mysqli_query($conn, "SELECT * FROM projects WHERE class_id = " . intval($class_id) . " AND deleted_at IS NULL ORDER BY id DESC");
        while ($row = mysqli_fetch_assoc($res)) $projects[] = $row;

        $pids = array_map('intval', array_column($projects, 'id'));
        list($dates_map, $dmap) = get_class_daily_data($conn, $pids, $class_id, $dl_from, $dl_to);
        $rec_map = get_class_records_map($conn, $pids, $class_id, $dl_from, $dl_to);
        $sid = intval($student['id']);

        $reg_rows = [['项目', '日期', '是否登记']];
        $time_rows = [['项目', '日期', '登记时间']];
        $eval_rows = [['项目', '评价内容']];
        foreach ($projects as $p) {
            $pid = intval($p['id']);
            $rec = $rec_map[$pid][$sid] ?? null;
            if (($p['mode'] ?? 'count') === 'daily') {
                foreach ($dates_map[$pid] ?? [] as $d) {
                    $reg_rows[] = [$p['name'], $d, isset($dmap[$pid][$sid][$d]) ? '✓' : ''];
                    $time_rows[] = [$p['name'], $d, $dmap[$pid][$sid][$d] ?? ''];
                }
            } else {
                $reg_rows[] = [$p['name'], '', ($rec && $rec['registered'] == 1) ? '已登记' : '未登记'];
                $time_rows[] = [$p['name'], '', ($rec && $rec['registered'] == 1 && $rec['registered_at']) ? $rec['registered_at'] : ''];
            }
            $eval_rows[] = [$p['name'], ($rec && $rec['eval_value'] !== null) ? $rec['eval_value'] : ''];
        }
        output_excel_xml('学生登记情况_' . $student['name'] . '_' . $date . $dl_suffix . '.xls', [
            ['name' => '是否登记', 'rows' => $reg_rows],
            ['name' => '登记时间', 'rows' => $time_rows],
            ['name' => '评价内容', 'rows' => $eval_rows],
        ]);
    }

    // ---- 全校导出 ----
    if ($dtype === 'school') {
        if (!is_super_admin($conn, $current_teacher_id)) exit('仅管理员可导出全校报表');
        $class_ids = [];
        $stmt = mysqli_prepare($conn, "SELECT id FROM classes WHERE school_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $school_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) $class_ids[] = intval($row['id']);
        mysqli_stmt_close($stmt);
        $rows = [
            ['全校汇总'],
            ['统计范围：' . $dl_label],
            ['班级', '学生数', '登记人次', '评价人次', '登记率'],
        ];
        $rows = array_merge($rows, build_class_summary($conn, $class_ids, $dl_from, $dl_to));
        output_csv('全校导出_' . $date . $dl_suffix . '.csv', $rows);
    }

    // ---- 教师提交率导出（全校：按项目创建人（科任老师）×班级聚合） ----
    if ($dtype === 'teacher') {
        $scope = 'school';
        $is_admin = is_super_admin($conn, $current_teacher_id);
        $scope_label = '全校';
        if (!$is_admin) exit('仅管理员可导出教师提交率');
        $proj_res = mysqli_query($conn, "SELECT p.id, p.name, p.mode, p.scope, p.class_id, p.created_by,
                    c.name AS class_name
                    FROM projects p LEFT JOIN classes c ON p.class_id = c.id
                    WHERE p.school_id = " . intval($school_id) . " AND p.deleted_at IS NULL ORDER BY p.id DESC");
        $projects_t = [];
        while ($row = mysqli_fetch_assoc($proj_res)) $projects_t[] = $row;
        if (!$projects_t) exit('该范围内暂无项目');

        // 覆盖班级 × 学生数（不含停用学生）
        $all_cls = []; $proj_cls = [];
        foreach ($projects_t as $p) {
            $cids = get_project_class_ids($conn, $p);
            $proj_cls[intval($p['id'])] = array_map('intval', $cids);
            foreach ($cids as $cid) $all_cls[intval($cid)] = 1;
        }
        $stu_cnt = [];
        if ($all_cls) {
            $in_c = implode(',', array_keys($all_cls));
            $res = mysqli_query($conn, "SELECT class_id, COUNT(*) AS cnt FROM students WHERE class_id IN ({$in_c}) AND disabled = 0 GROUP BY class_id");
            while ($row = mysqli_fetch_assoc($res)) $stu_cnt[intval($row['class_id'])] = intval($row['cnt']);
        }
        $pids_t = array_map('intval', array_column($projects_t, 'id'));
        $in_p = implode(',', $pids_t);
        // 一次性项目：已交人数（范围内登记）
        $regcnt = [];
        $rc = '';
        if ($dl_from !== '') $rc .= " AND registered_at >= '" . mysqli_real_escape_string($conn, $dl_from) . " 00:00:00'";
        if ($dl_to !== '') $rc .= " AND registered_at < '" . date('Y-m-d', strtotime($dl_to) + 86400) . " 00:00:00'";
        $res = mysqli_query($conn, "SELECT project_id, COUNT(*) AS cnt FROM records
                    WHERE project_id IN ({$in_p}) AND registered = 1 {$rc} GROUP BY project_id");
        while ($row = mysqli_fetch_assoc($res)) $regcnt[intval($row['project_id'])] = intval($row['cnt']);
        // 打卡项目：范围内打卡人次 + 发生打卡天数
        $daycnt = []; $dayset = [];
        $dc = '';
        if ($dl_from !== '') $dc .= " AND rd.reg_date >= '" . mysqli_real_escape_string($conn, $dl_from) . "'";
        if ($dl_to !== '') $dc .= " AND rd.reg_date <= '" . mysqli_real_escape_string($conn, $dl_to) . "'";
        $res = mysqli_query($conn, "SELECT rd.project_id, COUNT(*) AS cnt, COUNT(DISTINCT rd.reg_date) AS dcnt
                    FROM record_days rd INNER JOIN students s ON rd.student_id = s.id AND s.disabled = 0
                    WHERE rd.project_id IN ({$in_p}) {$dc} GROUP BY rd.project_id");
        while ($row = mysqli_fetch_assoc($res)) {
            $daycnt[intval($row['project_id'])] = intval($row['cnt']);
            $dayset[intval($row['project_id'])] = intval($row['dcnt']);
        }
        // 教师姓名
        $tids = [];
        foreach ($projects_t as $p) if (intval($p['created_by']) > 0) $tids[intval($p['created_by'])] = 1;
        $tname = [];
        if ($tids) {
            $in_tid = implode(',', array_keys($tids));
            $res = mysqli_query($conn, "SELECT id, realname, username FROM teachers WHERE id IN ({$in_tid})");
            while ($row = mysqli_fetch_assoc($res)) $tname[intval($row['id'])] = $row['realname'] ?: $row['username'];
        }

        // 聚合：教师 → 班级（班级列：班级项目=班名；多班项目=「共N班」）
        $agg = []; // tid => ['name', 'cls' => [label => ['subj'=>[], 'projs'=>[], 'n','due','sub']], 'T'=>['n','due','sub']]
        foreach ($projects_t as $p) {
            $pid = intval($p['id']);
            $tid = intval($p['created_by']);
            $tlabel = $tname[$tid] ?? ('教师' . $tid);
            $cids = $proj_cls[$pid];
            $stu_sum = 0;
            foreach ($cids as $cid) $stu_sum += ($stu_cnt[$cid] ?? 0);
            if ($stu_sum <= 0) continue; // 覆盖班级无在册学生，跳过
            if (($p['mode'] ?? 'count') === 'daily') {
                $days = max(1, $dayset[$pid] ?? 0);
                $due = $stu_sum * $days;
                $sub = $daycnt[$pid] ?? 0;
            } else {
                $due = $stu_sum;
                $sub = $regcnt[$pid] ?? 0;
            }
            $cls_label = intval($p['class_id']) > 0 ? (string)$p['class_name'] : ('多班项目·' . strval($p['scope']));
            if (!isset($agg[$tid])) $agg[$tid] = ['name' => $tlabel, 'cls' => [], 'T' => ['n' => 0, 'due' => 0, 'sub' => 0]];
            if (!isset($agg[$tid]['cls'][$cls_label])) $agg[$tid]['cls'][$cls_label] = ['projs' => [], 'n' => 0, 'due' => 0, 'sub' => 0];
            $agg[$tid]['cls'][$cls_label]['projs'][] = $p['name'];
            $agg[$tid]['cls'][$cls_label]['n']++;
            $agg[$tid]['cls'][$cls_label]['due'] += $due;
            $agg[$tid]['cls'][$cls_label]['sub'] += $sub;
            $agg[$tid]['T']['n']++;
            $agg[$tid]['T']['due'] += $due;
            $agg[$tid]['T']['sub'] += $sub;
        }
        uasort($agg, function ($a, $b) { return strcmp($a['name'], $b['name']); });

        $rate = function ($sub, $due) { return $due > 0 ? round($sub / $due * 100, 1) . '%' : '—'; };
        $rows = [
            ['教师提交率统计（' . $scope_label . '）'],
            ['统计范围：' . $dl_label . '　·　生成于 ' . date('Y-m-d H:i')],
            ['说明：按项目创建人（科任老师）归属；打卡项目应交=学生数×打卡天数，一次性项目应交=班级学生数'],
            ['教师', '班级', '项目', '项目数', '应交', '已交', '未交', '提交率'],
        ];
        foreach ($agg as $tid => $tinfo) {
            foreach ($tinfo['cls'] as $clabel => $cinfo) {
                $rows[] = [
                    $tinfo['name'],
                    $clabel,
                    implode('、', $cinfo['projs']),
                    $cinfo['n'],
                    $cinfo['due'],
                    $cinfo['sub'],
                    max(0, $cinfo['due'] - $cinfo['sub']),
                    $rate($cinfo['sub'], $cinfo['due']),
                ];
            }
            $T = $tinfo['T'];
            $rows[] = [
                '★ ' . $tinfo['name'] . ' 小计', '', '', $T['n'], $T['due'], $T['sub'], max(0, $T['due'] - $T['sub']), $rate($T['sub'], $T['due']),
            ];
        }
        output_csv('教师提交率_' . $scope_label . '_' . $date . $dl_suffix . '.csv', $rows);
    }

    // ---- 批量导出（多班级 × 多报表类型 → ZIP 打包；仅一个文件直接下载；无 ZipArchive 时合并为单一工作簿） ----
    if ($dtype === 'batch') {
        $type_labels = ['matrix' => '登记矩阵', 'detail' => '全部项目明细', 'students' => '全部学生'];
        $types = array_values(array_intersect(
            preg_split('/[\s,]+/', strval($_GET['types'] ?? '')) ?: [],
            array_keys($type_labels)
        ));
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', strval($_GET['ids'] ?? ''))), function ($v) { return $v > 0; })));
        if (!$ids || !$types) exit('请先选择班级与报表类型');

        $vis_projects = get_visible_projects($conn, $current_teacher_id);
        $files = [];        // 文件名 => 工作簿 XML 字符串
        $merged_sheets = []; // 无 ZipArchive 兜底：单一工作簿的带班级前缀分表
        $ok_classes = []; $no_data_classes = [];
        foreach ($ids as $cid) {
            $ctx = class_export_ctx($conn, $cid, $current_teacher_id, $dl_from, $dl_to, $vis_projects);
            if (isset($ctx['error'])) { $no_data_classes[] = $ctx['error'] . '（ID ' . $cid . '）'; continue; }
            $sheets = [];
            if (in_array('matrix', $types, true)) $sheets = array_merge($sheets, build_class_matrix_sheets($ctx));
            if (in_array('detail', $types, true)) $sheets = array_merge($sheets, build_class_detail_sheets($ctx));
            if (in_array('students', $types, true)) $sheets = array_merge($sheets, build_class_students_sheets($ctx));
            if (!$sheets) { $no_data_classes[] = $ctx['class']['name'] . '（无报表数据）'; continue; }
            $files['班级导出_' . $ctx['class']['name'] . '_' . $date . $dl_suffix . '.xls'] = excel_xml_string($sheets);
            foreach ($sheets as $sh) $merged_sheets[] = ['name' => $ctx['class']['name'] . '·' . $sh['name'], 'rows' => $sh['rows']];
            $ok_classes[] = $ctx['class']['name'] . '（' . count($sheets) . '张表）';
        }
        if (!$files) exit('选中的班级均无可导出数据' . ($no_data_classes ? ('：' . implode('；', $no_data_classes)) : ''));

        // 仅一个文件：直接下载该工作簿
        if (count($files) === 1) {
            $name = array_key_first($files);
            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
            echo $files[$name];
            exit();
        }

        // ZIP 打包（含导出说明）
        if (class_exists('ZipArchive')) {
            $tmp = tempnam(sys_get_temp_dir(), 'qjexp');
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::OVERWRITE) === true) {
                $readme = "统计报表批量导出\r\n"
                    . "生成时间：" . date('Y-m-d H:i') . "\r\n"
                    . "统计范围：" . $dl_label . "\r\n"
                    . "报表类型：" . implode('、', array_map(function ($t) use ($type_labels) { return $type_labels[$t]; }, $types)) . "\r\n"
                    . "包含班级（" . count($ok_classes) . "）：" . implode('；', $ok_classes) . "\r\n"
                    . ($no_data_classes ? ("未包含：" . implode('；', $no_data_classes) . "\r\n") : '');
                $zip->addFromString('导出说明.txt', "\xEF\xBB\xBF" . $readme);
                foreach ($files as $name => $content) $zip->addFromString($name, $content);
                $zip->close();
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . rawurlencode('统计报表批量导出_' . $date . $dl_suffix . '.zip') . '"');
                header('Content-Length: ' . filesize($tmp));
                readfile($tmp);
                unlink($tmp);
                exit();
            }
            @unlink($tmp);
        }

        // 兜底：合并为单一工作簿（工作表名加「班级·」前缀，自动去重限长）
        output_excel_xml('统计报表合并导出_' . $date . $dl_suffix . '.xls', $merged_sheets);
    }

    // ---- 批量导出（多项目 → ZIP 打包，每项目一个工作簿；仅一个文件直接下载；无 ZipArchive 时合并为单一工作簿；stats.php 班级总览勾选入口） ----
    if ($dtype === 'projects') {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', strval($_GET['ids'] ?? ''))), function ($v) { return $v > 0; })));
        if (!$ids) exit('请先勾选要导出的项目');

        $files = [];          // 文件名 => 工作簿 XML 字符串
        $merged_sheets = [];  // 无 ZipArchive 兜底：单一工作簿的带项目前缀分表
        $ok_list = []; $no_list = []; $used_names = [];
        foreach ($ids as $pid) {
            $project = get_project_for($conn, $pid, $current_teacher_id, 'operate');
            if (!$project) { $no_list[] = '项目 ' . $pid . '（不存在或无导出权限）'; continue; }
            $exp = build_project_export($conn, $project, $dl_from, $dl_to, $reg_in_range);
            $sheets = $exp['kind'] === 'count' ? [['name' => '登记明细', 'rows' => $exp['rows']]] : $exp['sheets'];   // count=CSV行；daily/omr=分表
            $name = '项目导出_' . $project['name'] . '_' . $date . $dl_suffix . '.xls';
            if (isset($used_names[$name])) $name = preg_replace('/\.xls$/', '_' . $pid . '.xls', $name);   // 重名项目文件名去重
            $used_names[$name] = true;
            $files[$name] = excel_xml_string($sheets);
            foreach ($sheets as $sh) $merged_sheets[] = ['name' => $project['name'] . '·' . $sh['name'], 'rows' => $sh['rows']];
            $ok_list[] = $project['name'] . '（' . count($sheets) . '张表）';
        }
        if (!$files) exit('选中的项目均无可导出数据' . ($no_list ? ('：' . implode('；', $no_list)) : ''));

        // 仅一个文件：直接下载该工作簿
        if (count($files) === 1) {
            $name = array_key_first($files);
            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
            echo $files[$name];
            exit();
        }

        // ZIP 打包（含导出说明）
        if (class_exists('ZipArchive')) {
            $tmp = tempnam(sys_get_temp_dir(), 'qjexp');
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::OVERWRITE) === true) {
                $readme = "项目批量导出\r\n"
                    . "生成时间：" . date('Y-m-d H:i') . "\r\n"
                    . "统计范围：" . $dl_label . "\r\n"
                    . "包含项目（" . count($ok_list) . "）：" . implode('；', $ok_list) . "\r\n"
                    . ($no_list ? ("未包含：" . implode('；', $no_list) . "\r\n") : '');
                $zip->addFromString('导出说明.txt', "\xEF\xBB\xBF" . $readme);
                foreach ($files as $name => $content) $zip->addFromString($name, $content);
                $zip->close();
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . rawurlencode('项目批量导出_' . $date . $dl_suffix . '.zip') . '"');
                header('Content-Length: ' . filesize($tmp));
                readfile($tmp);
                unlink($tmp);
                exit();
            }
            @unlink($tmp);
        }

        // 兜底：合并为单一工作簿（工作表名加「项目·」前缀）
        output_excel_xml('项目批量合并导出_' . $date . $dl_suffix . '.xls', $merged_sheets);
    }

    exit('未知导出类型');
}

// ===== 报表中心页面 =====

$visible_class_ids = get_visible_class_ids($conn, $current_teacher_id);
$classes_list = [];
if ($visible_class_ids) {
    $in = implode(',', array_map('intval', $visible_class_ids));
    $res = mysqli_query($conn, "SELECT c.* FROM classes c
                                WHERE c.id IN ({$in}) ORDER BY c.id");
    while ($row = mysqli_fetch_assoc($res)) $classes_list[] = $row;
}

$is_admin = is_super_admin($conn, $current_teacher_id);

page_header('统计报表', 'export.php');

// 快捷时间范围（默认选中本周：周一～周日，贴合按周统计习惯）
$wk_mon = date('Y-m-d', strtotime('monday this week'));
$wk_sun = date('Y-m-d', strtotime('sunday this week'));
$last_mon = date('Y-m-d', strtotime('monday last week'));
$last_sun = date('Y-m-d', strtotime('sunday last week'));
$mon_first = date('Y-m-01');
$mon_last = date('Y-m-t');
?>
<div class="panel">
    <h3>📅 导出时间范围 <span style="font-size:12px;color:#999;font-weight:normal;">登记/打卡按所选日期过滤；留空 = 全部时间；下方图表与全部导出按钮联动</span><button type="button" class="hint-q" onclick="toggleHint(event, '<b>时间范围说明</b><br>· 登记按登记时间、打卡按打卡日期过滤<br>· 留空开始/结束 = 全部时间<br>· 快捷按钮：本周（周一～周日）/ 上周 / 本月 / 全部时间<br>· 下方数据总览图表与所有导出文件自动携带所选范围')">?</button></h3>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <label style="font-size:13px;color:#555;">开始</label>
        <input type="date" id="dlFrom" value="<?php echo $wk_mon; ?>" onchange="fetchDash()" style="padding:6px 8px;border:1px solid #d7dce8;border-radius:6px;font-size:13px;" autocomplete="off">
        <label style="font-size:13px;color:#555;">结束</label>
        <input type="date" id="dlTo" value="<?php echo $wk_sun; ?>" onchange="fetchDash()" style="padding:6px 8px;border:1px solid #d7dce8;border-radius:6px;font-size:13px;" autocomplete="off">
        <button type="button" class="btn btn-sm btn-outline" onclick="setRange('<?php echo $wk_mon; ?>','<?php echo $wk_sun; ?>')">本周</button>
        <button type="button" class="btn btn-sm btn-outline" onclick="setRange('<?php echo $last_mon; ?>','<?php echo $last_sun; ?>')">上周</button>
        <button type="button" class="btn btn-sm btn-outline" onclick="setRange('<?php echo $mon_first; ?>','<?php echo $mon_last; ?>')">本月</button>
        <button type="button" class="btn btn-sm btn-outline" onclick="setRange('','')">全部时间</button>
        <span style="font-size:12px;color:#999;">所有导出与图表自动携带所选范围（例：本周一 <?php echo $wk_mon; ?> ～ 周日 <?php echo $wk_sun; ?>）</span>
    </div>
</div>

<!-- 📈 数据总览：KPI + ECharts 图表（随导出时间范围联动） -->
<div class="panel">
    <h3>📈 数据总览 <span style="font-size:12px;color:#999;font-weight:normal;">图表随上方时间范围联动；每张图右上角可存为图片</span></h3>
    <div class="stat-bar" style="flex-wrap:wrap;">
        <div class="stat-box"><div class="num" id="kpiClasses">–</div><div class="label">可见班级</div></div>
        <div class="stat-box"><div class="num" id="kpiStudents">–</div><div class="label">学生总数</div></div>
        <div class="stat-box"><div class="num" id="kpiProjects">–</div><div class="label">项目总数</div></div>
        <div class="stat-box"><div class="num" id="kpiRegs">–</div><div class="label">登记/打卡人次</div></div>
        <div class="stat-box"><div class="num" id="kpiEvals">–</div><div class="label">评价人次</div></div>
        <div class="stat-box"><div class="num" id="kpiRate">–</div><div class="label">登记人次/学生数</div></div>
    </div>
    <div id="dashMsg" style="display:none;color:#999;font-size:13px;padding:8px 0;"></div>
    <div class="dash-grid">
        <div><div id="chartRate" style="height:300px;"></div></div>
        <div><div id="chartTrend" style="height:300px;"></div></div>
        <div><div id="chartEval" style="height:300px;"></div></div>
    </div>
</div>

<!-- 📤 报表导出：Tab 分区导航 -->
<div class="panel">
    <h3>📤 报表导出 <span style="font-size:12px;color:#999;font-weight:normal;">单表点对应按钮，批量走「班级报表 → 批量导出」；均自动携带时间范围</span></h3>
    <div style="display:flex;gap:6px;flex-wrap:wrap;" id="expTabs">
        <button type="button" class="btn btn-sm btn-outline exp-tab active" onclick="switchExpTab(this,'tabClass')">🏫 班级报表</button><button type="button" class="hint-q" onclick="toggleHint(event, '<b>班级报表说明</b><br>· 按班级导出登记矩阵 / 明细 / 学生名单，还可批量导出多个班级（ZIP 打包）<br>· 单班导出为 Excel 工作簿，批量导出为 ZIP 压缩包<br>· 均按上方时间范围过滤')">?</button>
        <?php if ($is_admin): ?>
        <button type="button" class="btn btn-sm btn-outline exp-tab" onclick="switchExpTab(this,'tabScope')">🏛 全校汇总</button><button type="button" class="hint-q" onclick="toggleHint(event, '<b>全校汇总说明</b><br>· 汇总导出全校各班级的登记情况（学生数 / 登记人次 / 评价人次 / 登记率）<br>· 仅管理员可见<br>· 均按上方时间范围过滤')">?</button>
        <button type="button" class="btn btn-sm btn-outline exp-tab" onclick="switchExpTab(this,'tabTeacher')">👨‍🏫 教师提交率</button><button type="button" class="hint-q" onclick="toggleHint(event, '<b>教师提交率说明</b><br>· 按教师统计其登记操作次数与覆盖班级，衡量登记提交情况<br>· 导出 CSV 文件，均按上方时间范围过滤')">?</button>
        <?php endif; ?>
    </div>
</div>

<!-- Tab 1：班级报表（单班导出表格 + 批量导出） -->
<div id="tabClass" class="exp-tab-content panel">
    <?php
    // 各班级学生数（导出表格展示用）
    $cls_stu_cnt = [];
    if ($classes_list) {
        $in_cnt = implode(',', array_map('intval', array_column($classes_list, 'id')));
        $res_cnt = mysqli_query($conn, "SELECT class_id, COUNT(*) AS c FROM students WHERE class_id IN ({$in_cnt}) GROUP BY class_id");
        while ($row_cnt = mysqli_fetch_assoc($res_cnt)) $cls_stu_cnt[intval($row_cnt['class_id'])] = intval($row_cnt['c']);
    }
    ?>
    <table style="width:100%;border-collapse:collapse;font-size:13px;" class="exp-table">
        <thead>
            <tr style="background:#f5f7fb;color:#555;text-align:left;">
                <th style="padding:8px 10px;border-bottom:1px solid #e0e3ef;">班级</th>
                <th style="padding:8px 10px;border-bottom:1px solid #e0e3ef;width:70px;">学生数</th>
                <th style="padding:8px 10px;border-bottom:1px solid #e0e3ef;">导出</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($classes_list as $c): $cls_label = htmlspecialchars($c['name']); ?>
            <tr>
                <td style="padding:7px 10px;border-bottom:1px solid #eef0f6;"><?php echo $cls_label; ?></td>
                <td style="padding:7px 10px;border-bottom:1px solid #eef0f6;color:#888;"><?php echo $cls_stu_cnt[intval($c['id'])] ?? 0; ?></td>
                <td style="padding:7px 10px;border-bottom:1px solid #eef0f6;display:flex;gap:6px;flex-wrap:wrap;">
                    <a class="btn btn-sm btn-outline dl-link" style="text-decoration:none;"
                       href="export.php?action=download&dtype=class&id=<?php echo $c['id']; ?>"
                       title="学生 × 项目登记矩阵（每个项目一张表）">📋 登记矩阵</a>
                    <a class="btn btn-sm btn-outline dl-link" style="text-decoration:none;"
                       href="export.php?action=download&dtype=class_detail&id=<?php echo $c['id']; ?>"
                       title="是否登记 / 登记时间 / 评价内容 三张分表">📑 全部项目明细</a>
                    <a class="btn btn-sm btn-outline dl-link" style="text-decoration:none;"
                       href="export.php?action=download&dtype=class_students&id=<?php echo $c['id']; ?>"
                       title="每位学生一张表列出全部项目登记情况">👥 全部学生</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($classes_list)): ?><tr><td colspan="3" style="padding:12px;color:#999;">暂无可见班级</td></tr><?php endif; ?>
        </tbody>
    </table>

    <?php if ($classes_list): ?>
    <div style="border:1px dashed #c9d2ea;border-radius:8px;padding:12px 14px;margin-top:14px;background:#fafbff;">
        <div style="font-weight:600;margin-bottom:8px;">📦 批量导出 <span style="font-size:12px;color:#999;font-weight:normal;">勾选班级与报表类型 → 打包 ZIP 下载（每个班级一个 Excel 工作簿 + 导出说明）</span></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
            <label class="chipbox"><input type="checkbox" id="batClsAll" onchange="toggleBatAll(this)" autocomplete="off"> 全选班级</label>
            <?php foreach ($classes_list as $c): ?>
            <label class="chipbox"><input type="checkbox" class="bat-cls" value="<?php echo $c['id']; ?>" onchange="this.parentNode.classList.toggle('on',this.checked)" autocomplete="off"><?php echo htmlspecialchars($c['name']); ?></label>
            <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <span style="font-size:13px;color:#555;">报表类型：</span>
            <label class="chipbox on"><input type="checkbox" class="bat-type" value="matrix" checked onchange="this.parentNode.classList.toggle('on',this.checked)" autocomplete="off"> 登记矩阵</label>
            <label class="chipbox on"><input type="checkbox" class="bat-type" value="detail" checked onchange="this.parentNode.classList.toggle('on',this.checked)" autocomplete="off"> 全部项目明细</label>
            <label class="chipbox"><input type="checkbox" class="bat-type" value="students" onchange="this.parentNode.classList.toggle('on',this.checked)" autocomplete="off"> 全部学生</label>
            <button type="button" class="btn btn-sm" style="background:#667eea;color:#fff;border:none;" onclick="doBatchExport()">⬇ 导出选中班级（ZIP）</button>
            <span id="batTip" style="font-size:12px;color:#999;">已选 0 个班级</span>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($is_admin): ?>
<div id="tabScope" class="exp-tab-content panel" style="display:none;">
    <h3>🏛 全校汇总导出</h3>
    <p style="font-weight:500;margin:6px 0 8px;color:#555;">全校导出 <span style="font-size:12px;color:#999;">全部班级登记汇总（学生数 / 登记人次 / 评价人次 / 登记率，仅管理员）</span></p>
    <a class="btn btn-sm dl-link" style="text-decoration:none;" href="export.php?action=download&dtype=school&id=0">⬇ 导出全校汇总</a>
</div>

<div id="tabTeacher" class="exp-tab-content panel" style="display:none;">
    <h3>👨‍🏫 教师提交率导出 <span style="font-size:12px;color:#999;font-weight:normal;">按项目创建人（科任老师）×班级聚合，一眼看每位老师的作业提交率</span></h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a class="btn btn-sm dl-link" style="text-decoration:none;"
           href="export.php?action=download&dtype=teacher&scope=school&id=0"
           title="全部班级各老师所教班级的提交率">⬇ 全校·教师提交率</a>
    </div>
</div>
<?php endif; ?>

<!-- 📊 在线统计：弹窗查看班级/学生明细（默认折叠） -->
<div class="panel">
    <h3 style="cursor:pointer;user-select:none;display:flex;align-items:center;gap:6px;" onclick="toggleSec('onlinePanel','onlineArrow')" title="点击展开/收起">
        📊 在线统计（班级 / 学生明细查看） <span id="onlineArrow" style="color:#999;font-size:14px;">▸</span>
    </h3>
    <div id="onlinePanel" style="display:none;">
        <p style="font-weight:500;margin:6px 0 8px;color:#555;">班级登记统计 <span style="font-size:12px;color:#999;">点击弹窗打开，可最大化 / 关闭</span></p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php foreach ($classes_list as $c): ?>
            <a class="btn btn-sm btn-outline" style="text-decoration:none;" href="stats.php?class_id=<?php echo $c['id']; ?>"
               onclick="openReport(this.href, '📊 <?php echo htmlspecialchars(addslashes($c['name'])); ?> · 登记统计'); return false;">
                📊 <?php echo htmlspecialchars($c['name']); ?>
            </a>
            <?php endforeach; ?>
            <?php if (empty($classes_list)): ?><span style="color:#999;font-size:13px;">暂无可见班级</span><?php endif; ?>
        </div>

        <p style="font-weight:500;margin:16px 0 6px;color:#555;cursor:pointer;user-select:none;display:flex;align-items:center;gap:6px;" onclick="toggleSec('stdPanel','stdArrow')" title="点击展开/收起">
            👥 学生登记统计 <span id="stdArrow" style="color:#999;font-size:14px;">▸</span>
        </p>
        <div id="stdPanel" style="display:none;">
            <?php
            // 各可见班级的学生名单（批量查询）
            $std_map = [];
            if ($classes_list) {
                $in_s = implode(',', array_map('intval', array_column($classes_list, 'id')));
                $res = mysqli_query($conn, "SELECT id, class_id, seat_no, name FROM students WHERE class_id IN ({$in_s})
                                            ORDER BY class_id, CAST(seat_no AS UNSIGNED), id");
                while ($row = mysqli_fetch_assoc($res)) $std_map[intval($row['class_id'])][] = $row;
            }
            ?>
            <?php foreach ($classes_list as $c): $cid3 = intval($c['id']); ?>
            <p style="font-weight:500;margin:12px 0 6px;color:#555;">📊 <?php echo htmlspecialchars($c['name']); ?>（<?php echo count($std_map[$cid3] ?? []); ?> 人）</p>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <?php foreach ($std_map[$cid3] ?? [] as $s): ?>
                <a class="btn btn-sm btn-outline" style="text-decoration:none;"
                   href="stats.php?class_id=<?php echo $cid3; ?>&student_id=<?php echo intval($s['id']); ?>"
                   title="查看该生各项目登记/评价统计"
                   onclick="openReport(this.href, '👤 <?php echo htmlspecialchars(addslashes(($s['seat_no'] ? $s['seat_no'] . ' · ' : '') . $s['name'])); ?> · 登记记录'); return false;"><?php echo htmlspecialchars($s['seat_no']); ?> · <?php echo htmlspecialchars($s['name']); ?></a>
                <?php endforeach; ?>
                <?php if (empty($std_map[$cid3])): ?><span style="color:#999;font-size:13px;">暂无学生</span><?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if (empty($classes_list)): ?><span style="color:#999;font-size:13px;">暂无可见班级</span><?php endif; ?>
        </div>
    </div>
</div>

<!-- 报表弹窗：iframe 加载统计页（班级登记统计 / 学生登记统计），支持最大化 / ✕ 关闭 -->
<div class="modal-mask" id="reportModal">
    <div class="modal" style="width:960px;max-width:92vw;height:86vh;display:flex;flex-direction:column;">
        <h3 id="reportTitle">统计报表</h3>
        <iframe id="reportFrame" src="about:blank" style="flex:1;width:100%;border:1px solid #e0e3ef;border-radius:8px;background:#fff;min-height:0;"></iframe>
    </div>
</div>

<style>
.dash-grid { display:grid; grid-template-columns:1fr; gap:12px; }
@media (min-width:980px) { .dash-grid { grid-template-columns:1fr 1fr; } }
.dash-grid > div { border:1px solid #eef0f6; border-radius:8px; overflow:hidden; }
.chipbox { display:inline-flex; align-items:center; gap:5px; border:1px solid #d7dce8; border-radius:16px; padding:4px 11px; cursor:pointer; font-size:13px; color:#444; user-select:none; background:#fff; }
.chipbox.on { border-color:#667eea; background:#eef0fd; color:#4a4ac4; }
.chipbox input { accent-color:#667eea; margin:0; }
.exp-tab.active { background:#667eea; color:#fff; border-color:#667eea; }
.exp-table tbody tr:hover { background:#fafbff; }
</style>
<script src="assets/js/echarts.min.js"></script>
<script>
// ===== 导出时间范围：快捷填充 + 联动图表 + 导出链接自动携带 from/to（重复点击不叠加参数） =====
function setRange(f, t) {
    var fi = document.getElementById('dlFrom'), ti = document.getElementById('dlTo');
    if (fi) fi.value = f || '';
    if (ti) ti.value = t || '';
    if (typeof fetchDash === 'function') fetchDash();
}
document.addEventListener('click', function (e) {
    var node = e.target;
    while (node && node !== document) {
        if (node.tagName === 'A' && node.classList.contains('dl-link')) break;
        node = node.parentNode;
    }
    if (!node || node === document) return;
    var f = (document.getElementById('dlFrom') || {}).value || '';
    var t = (document.getElementById('dlTo') || {}).value || '';
    var base = node.getAttribute('href');
    var qi = base.indexOf('?');
    var path = qi === -1 ? base : base.slice(0, qi);
    var params = [];
    if (qi !== -1) {
        base.slice(qi + 1).split('&').forEach(function (kv) {
            if (kv && kv.indexOf('from=') !== 0 && kv.indexOf('to=') !== 0) params.push(kv);
        });
    }
    if (f) params.push('from=' + encodeURIComponent(f));
    if (t) params.push('to=' + encodeURIComponent(t));
    node.href = path + (params.length ? '?' + params.join('&') : '');
});

// ===== 报表导出 Tab 切换 =====
function switchExpTab(btn, id) {
    document.querySelectorAll('.exp-tab-content').forEach(function (el) { el.style.display = 'none'; });
    var target = document.getElementById(id);
    if (target) target.style.display = '';
    document.querySelectorAll('.exp-tab').forEach(function (b) { b.classList.remove('active'); });
    btn.classList.add('active');
}

// ===== 通用折叠区 =====
function toggleSec(panelId, arrowId) {
    var p = document.getElementById(panelId), a = document.getElementById(arrowId);
    if (!p) return;
    var show = p.style.display === 'none';
    p.style.display = show ? '' : 'none';
    if (a) a.textContent = show ? '▾' : '▸';
}
function toggleStdStats() { toggleSec('stdPanel', 'stdArrow'); }

// ===== 批量导出 =====
function toggleBatAll(master) {
    document.querySelectorAll('.bat-cls').forEach(function (c) {
        c.checked = master.checked;
        c.parentNode.classList.toggle('on', c.checked);
    });
    updateBatTip();
}
function batCount() { return document.querySelectorAll('.bat-cls:checked').length; }
function updateBatTip() {
    var tip = document.getElementById('batTip');
    if (tip) tip.textContent = '已选 ' + batCount() + ' 个班级';
}
document.addEventListener('change', function (e) {
    if (e.target && e.target.classList && e.target.classList.contains('bat-cls')) updateBatTip();
});
function doBatchExport() {
    var ids = Array.prototype.map.call(document.querySelectorAll('.bat-cls:checked'), function (c) { return c.value; });
    var types = Array.prototype.map.call(document.querySelectorAll('.bat-type:checked'), function (c) { return c.value; });
    if (!ids.length) { showToast('请先勾选要导出的班级', 'error'); return; }
    if (!types.length) { showToast('请至少勾选一种报表类型', 'error'); return; }
    var f = (document.getElementById('dlFrom') || {}).value || '';
    var t = (document.getElementById('dlTo') || {}).value || '';
    var url = 'export.php?action=download&dtype=batch&ids=' + ids.join(',') + '&types=' + types.join(',');
    if (f) url += '&from=' + encodeURIComponent(f);
    if (t) url += '&to=' + encodeURIComponent(t);
    window.location.href = url;
}

// ===== 报表弹窗（iframe） =====
function openReport(url, title) {
    if (url.indexOf('embed=1') === -1) url += (url.indexOf('?') === -1 ? '?' : '&') + 'embed=1'; // 内嵌模式：无导航无返回
    document.getElementById('reportFrame').src = url;
    document.getElementById('reportTitle').textContent = title || '统计报表';
    document.getElementById('reportModal').classList.add('show');
}
(function () {
    var mask = document.getElementById('reportModal');
    if (!mask) return;
    var frame = document.getElementById('reportFrame');
    // 关闭时卸载 iframe（遮罩空白点击 / layout 注入的 ✕ 按钮均生效）
    mask.addEventListener('modalclosed', function () { frame.src = 'about:blank'; });
    mask.addEventListener('click', function (e) {
        if (e.target === mask) { mask.classList.remove('show'); frame.src = 'about:blank'; }
    });
})();

// ===== 数据总览（ECharts：班级登记对比 / 每日动态 / 评价分布） =====
var dashCharts = {};
function dashText(id, v) { var el = document.getElementById(id); if (el) el.textContent = v; }
function fetchDash() {
    var f = (document.getElementById('dlFrom') || {}).value || '';
    var t = (document.getElementById('dlTo') || {}).value || '';
    fetch('export.php?action=stats_data&from=' + encodeURIComponent(f) + '&to=' + encodeURIComponent(t))
        .then(function (r) { return r.json(); })
        .then(function (d) { renderDash(d || {}); })
        .catch(function () {
            var m = document.getElementById('dashMsg');
            if (m) { m.style.display = ''; m.textContent = '数据总览加载失败，请刷新重试'; }
        });
}
function dashBase(title) {
    return {
        title: { text: title, left: 'center', top: 6, textStyle: { fontSize: 13, color: '#555' } },
        tooltip: { trigger: 'axis' },
        toolbox: { right: 6, top: 2, feature: { saveAsImage: { title: '存图' } } }
    };
}
function dashEmpty(id, title) {
    if (dashCharts[id]) dashCharts[id].setOption({
        title: { text: title, left: 'center', top: 'middle', textStyle: { fontSize: 13, color: '#aaa' } }
    }, true);
}
function renderDash(d) {
    if (typeof echarts === 'undefined') {
        var m = document.getElementById('dashMsg');
        if (m) { m.style.display = ''; m.textContent = '图表库（assets/js/echarts.min.js）未找到，图表不可用'; }
        return;
    }
    ['chartRate', 'chartTrend', 'chartEval'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el && !dashCharts[id]) dashCharts[id] = echarts.init(el);
    });
    var k = d.kpi || {};
    dashText('kpiClasses', k.classes || 0);
    dashText('kpiStudents', k.students || 0);
    dashText('kpiProjects', k.projects || 0);
    dashText('kpiRegs', k.regs || 0);
    dashText('kpiEvals', k.evals || 0);
    dashText('kpiRate', (k.rate || 0) + '%');

    // 1. 各班级登记人次 + 登记率
    var cls = d.class_rates || [];
    if (cls.length && dashCharts.chartRate) {
        var names = cls.map(function (r) { return r.name; });
        dashCharts.chartRate.setOption(Object.assign(dashBase('各班级登记人次与登记率'), {
            grid: { left: 50, right: 52, top: 44, bottom: names.length > 8 ? 74 : 50 },
            xAxis: { type: 'category', data: names, axisLabel: { rotate: names.length > 6 ? 30 : 0, interval: 0, fontSize: 11 } },
            yAxis: [
                { type: 'value', name: '人次' },
                { type: 'value', name: '登记率%', position: 'right', axisLabel: { formatter: '{value}%' }, splitLine: { show: false } }
            ],
            series: [
                { name: '登记人次', type: 'bar', data: cls.map(function (r) { return r.regs; }), itemStyle: { color: '#667eea' }, barMaxWidth: 34 },
                { name: '登记率%', type: 'line', yAxisIndex: 1, data: cls.map(function (r) { return r.rate; }), itemStyle: { color: '#27ae60' },
                  label: { show: names.length <= 15, formatter: '{c}%', fontSize: 10 } }
            ]
        }), true);
    } else dashEmpty('chartRate', '暂无班级数据');

    // 2. 每日动态（打卡 + 一次性登记，堆叠）
    var trend = d.trend || [];
    if (trend.length && dashCharts.chartTrend) {
        dashCharts.chartTrend.setOption(Object.assign(dashBase('每日动态（人次）'), {
            legend: { top: 28 },
            grid: { left: 50, right: 24, top: 58, bottom: trend.length > 40 ? 76 : 50 },
            dataZoom: trend.length > 40 ? [{ type: 'inside' }, { type: 'slider', height: 16, bottom: 10 }] : [],
            xAxis: { type: 'category', data: trend.map(function (r) { return r.date.slice(5); }), axisLabel: { fontSize: 11 } },
            yAxis: { type: 'value', name: '人次' },
            series: [
                { name: '打卡人次', type: 'bar', stack: 't', data: trend.map(function (r) { return r.check; }), itemStyle: { color: '#00bcd4' } },
                { name: '一次性登记', type: 'bar', stack: 't', data: trend.map(function (r) { return r.reg; }), itemStyle: { color: '#f39c12' } }
            ]
        }), true);
    } else dashEmpty('chartTrend', '暂无动态数据');

    // 3. 评价内容分布（环形）
    var ev = d.eval_dist || [];
    if (ev.length && dashCharts.chartEval) {
        dashCharts.chartEval.setOption({
            title: { text: '评价内容分布（Top 10）', left: 'center', top: 6, textStyle: { fontSize: 13, color: '#555' } },
            tooltip: { trigger: 'item', formatter: '{b}: {c}（{d}%）' },
            toolbox: { right: 6, top: 2, feature: { saveAsImage: { title: '存图' } } },
            legend: { type: 'scroll', orient: 'vertical', right: 4, top: 44, bottom: 16, textStyle: { fontSize: 11 } },
            series: [{
                type: 'pie', radius: ['36%', '62%'], center: ['36%', '58%'],
                data: ev.map(function (x) { return { name: x.v, value: x.c }; }),
                label: { show: false }, emphasis: { label: { show: true, formatter: '{b}: {c}' } }
            }]
        }, true);
    } else dashEmpty('chartEval', '暂无评价数据');
}
window.addEventListener('resize', function () {
    Object.keys(dashCharts).forEach(function (id) { if (dashCharts[id]) dashCharts[id].resize(); });
});
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fetchDash);
else fetchDash();
</script>
<?php page_help('统计报表中心', [
    ['h' => '📈 数据总览', 'items' => [
        '<b>KPI 卡片</b>：可见班级 / 学生总数 / 项目总数 / 登记·打卡人次 / 评价人次 / 登记人次÷学生数',
        '<b>三张图表</b>（ECharts）：各班级登记人次与登记率（柱+线）、每日动态（打卡/一次性登记堆叠柱，超40天可缩放拖动）、评价内容分布（环形 Top 10）',
        '图表随顶部「导出时间范围」实时联动；每张图右上角 📷 可保存为图片；口径与导出一致（一次性按登记时间、打卡按打卡日期过滤）',
    ]],
    ['h' => '📤 报表导出（Tab 分区）', 'items' => [
        '<b>🏫 班级报表</b>：单班三按钮 —— <b>登记矩阵</b>（学生 × 项目矩阵 Excel，每项目一张表，打卡项目按日期展开 ✓）/ <b>全部项目明细</b>（是否登记 / 登记时间 / 评价内容 三张分表）/ <b>全部学生</b>（每位学生一张表）',
        '<b>📦 批量导出</b>：勾选多个班级 + 报表类型 → 打包 ZIP 下载（每个班级一个独立 Excel 工作簿 + 导出说明.txt）；仅一个文件时直接下载工作簿',
    ]],
    ['h' => '📅 导出时间范围', 'items' => [
        '选择开始 / 结束日期后，图表与所有导出按钮自动按该范围统计；留空 = 全部时间',
        '快捷按钮：<b>本周</b>（周一～周日）/ <b>上周</b> / <b>本月</b> / <b>全部时间</b>；文件名自动附带范围后缀',
        '范围口径：一次性项目按「登记时间」落范围；打卡项目按「打卡日期」落范围；范围外的登记在该导出中按未登记计',
    ]],
    ['h' => '📊 在线统计（默认折叠）', 'items' => [
        '点击班级按钮在<b>弹窗</b>中打开该班登记统计页（各项目登记情况、每日打卡、学生明细与图表，支持条形 / 折线 / 饼图切换）',
        '展开「学生登记统计」后可按学生查看各项目登记 / 评价 / 打卡天数明细，并支持导出该生登记情况（stats.php 学生统计页）',
    ]],
]);
page_footer(); ?>
