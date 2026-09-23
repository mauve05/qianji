<?php
/**
 * 学生名单管理
 * 支持：手动添加（座号自动续号）、EXCEL粘贴导入（含模板下载）、扫码导入班级码（潜记 bjdl 格式：编号-姓名-座号-备注；旧格式缺座号自动顺序补充）
 * 座号、姓名、编号必填（座号可重复、须为 1 开始的自然数；编号唯一）
 */
require_once 'includes/db_config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/layout.php';

$conn = getConnection();

// 班级校验（学科教师/课代表/班长等可查看名单；名单增删改按「修改班级名单权限」开关：管理员/创建人/年段长/班主任/授权管理成员）
$class_id = intval($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$stmt = mysqli_prepare($conn, "SELECT id, name, seat_cols, seat_groups, seat_auto_group, class_code FROM classes WHERE id = ? AND deleted_at IS NULL");
mysqli_stmt_bind_param($stmt, "i", $class_id);
mysqli_stmt_execute($stmt);
$class = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$class || !can_view_class($conn, $current_teacher_id, $class_id)) {
    header("Location: classes.php");
    exit();
}
$can_manage = can_manage_roster($conn, $current_teacher_id, $class_id);

// ===== 导出名单（CSV，UTF-8 BOM；列：座号,姓名,编号,备注 —— 与「替换导入」粘贴格式一致，可编辑后整列粘回） =====
if (isset($_GET['export']) && $can_manage) {
    $res = mysqli_query($conn, "SELECT seat_no, name, student_no, remark FROM students WHERE class_id = " . intval($class_id) . " ORDER BY CAST(seat_no AS UNSIGNED) ASC, id ASC");
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . rawurlencode('学生名单-' . $class['name']) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['座号', '姓名', '编号', '备注']);
    while ($r = mysqli_fetch_row($res)) fputcsv($out, $r);
    fclose($out);
    exit();
}

// ===== 下载导入模板（CSV，UTF-8 BOM；格式与「EXCEL粘贴导入 / 替换导入」一致：座号,姓名,编号,备注） =====
if (isset($_GET['template']) && $can_manage) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . rawurlencode('学生名单导入模板') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['座号', '姓名', '编号', '备注']);
    fputcsv($out, ['1', '张三', '2024001', '']);
    fputcsv($out, ['2', '李四', '2024002', '组长']);
    fclose($out);
    exit();
}

$msg = '';
$msg_type = 'success';

// 座号校验：允许留空；填写时必须是 1 开始的自然数（正整数，如 1、2、10），0/负数/小数/非数字均不通过
if (!function_exists('is_seat_no_valid')) {
    function is_seat_no_valid($v) {
        $v = trim(strval($v));
        return $v === '' || preg_match('/^[1-9][0-9]*$/', $v) === 1;
    }
}

// 批量插入学生（座号/姓名/编号必填；座号须为 1 开始的自然数、可重复；编号唯一，重复自动跳过），返回 [成功数, 跳过数, 座号非法数]
if (!function_exists('insert_students')) {
    function insert_students($conn, $class_id, $students) {
        $ok = 0; $skip = 0; $bad_seat = 0;
        $stmt = mysqli_prepare($conn, "INSERT INTO students (class_id, seat_no, name, student_no, remark, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        foreach ($students as $s) {
            $name = trim($s['name']);
            $no = trim($s['student_no']);
            $seat = trim($s['seat_no']);
            if ($name === '' || $no === '' || $seat === '') { $skip++; continue; }   // 座号、姓名、编号必填
            if (!is_seat_no_valid($seat)) { $bad_seat++; $skip++; continue; }        // 座号须为 1 开始的自然数
            $remark = trim($s['remark']);
            mysqli_stmt_bind_param($stmt, "issss", $class_id, $seat, $name, $no, $remark);
            if (mysqli_stmt_execute($stmt)) {
                $ok++;
            } else {
                $skip++; // 编号重复（唯一键冲突）
            }
        }
        mysqli_stmt_close($stmt);
        return [$ok, $skip, $bad_seat];
    }
}

// ===== 操作处理（仅管理权限：管理员/创建人/班主任/年段长） =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    $action = $_POST['action'] ?? '';

    // 手动单个添加（座号必填；打开弹窗时自动预填「最后座号+1」，可修改；座号可重复，编号唯一）
    if ($action === 'add') {
        $s = [
            'seat_no'    => check_input($_POST['seat_no'] ?? ''),
            'name'       => check_input($_POST['name'] ?? ''),
            'student_no' => check_input($_POST['student_no'] ?? ''),
            'remark'     => check_input($_POST['remark'] ?? ''),
        ];
        if ($s['seat_no'] === '' || $s['name'] === '' || $s['student_no'] === '') {
            $msg = '添加失败：座号、姓名、编号必填';
            $msg_type = 'error';
        } elseif (!is_seat_no_valid($s['seat_no'])) {
            $msg = '添加失败：座号须为 1 开始的自然数（如 1、2、3）';
            $msg_type = 'error';
        } else {
            list($ok, $skip) = insert_students($conn, $class_id, [$s]);
            $msg = $ok ? '学生添加成功' : '添加失败：编号已存在';
            if (!$ok) $msg_type = 'error';
        }
    }
    // EXCEL粘贴导入：每行 "座号 姓名 编号 备注"，TAB 或空格分隔
    elseif ($action === 'import_paste') {
        $lines = preg_split('/\r\n|\r|\n/', $_POST['paste_area'] ?? '');
        $students = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $f = preg_split('/[\t,，]+|\s+/u', $line);
            $students[] = [
                'seat_no'    => isset($f[0]) ? check_input($f[0]) : '',
                'name'       => isset($f[1]) ? check_input($f[1]) : '',
                'student_no' => isset($f[2]) ? check_input($f[2]) : '',
                'remark'     => isset($f[3]) ? check_input($f[3]) : '',
            ];
        }
        list($ok, $skip, $bad) = insert_students($conn, $class_id, $students);
        $msg = "导入完成：成功 {$ok} 人" . ($skip ? "，跳过 {$skip} 行（座号、姓名、编号必填、编号重复" . ($bad ? "、座号须为 1 开始的自然数" : '') . '）' : '');
        $msg_type = $ok ? 'success' : 'error';
    }
    // 扫码导入（bjdl 内容 / 提取链接 / 8位提取码均可；import_mode=append 附加 | replace 覆盖）
    elseif ($action === 'import_qr') {
        $raw = trim($_POST['qr_content'] ?? '');
        $mode = ($_POST['import_mode'] ?? 'append') === 'replace' ? 'replace' : 'append';
        $content = $raw;
        $qr_students = null;
        if (strpos($raw, 'bjdl') !== 0) {
            // 尝试按提取链接 / 提取码从数据库提取名单内容
            $resolved = resolve_import_payload($conn, $raw);
            if ($resolved === null) {
                $msg = '无法识别内容：请扫描班级导入二维码、粘贴提取链接，或输入 8 位提取码 / 班级码';
                $msg_type = 'error';
            } else {
                $content = $resolved;
            }
        }
        if ($msg === '') {
            $qr_students = parse_class_import_content($content);
            if ($qr_students === null) {
                $msg = '二维码内容无法识别，请扫描【班级导入二维码】或输入 8 位提取码';
                $msg_type = 'error';
            } else {
                foreach ($qr_students as &$s) {
                    foreach ($s as $k => $v) { $s[$k] = check_input($v); }
                }
                unset($s);
                // 旧格式名单（编号-姓名-备注）或缺座号的学生：自动按顺序补充座号
                // 附加导入=接续本班现有最大座号；覆盖导入=从 1 开始；同时不低于名单内已有最大座号（座号可重复，编号唯一）
                $base_seat = 0;
                if ($mode !== 'replace') {
                    $res = mysqli_query($conn, "SELECT COALESCE(MAX(CAST(seat_no AS UNSIGNED)), 0) AS ms FROM students WHERE class_id = " . intval($class_id));
                    $base_seat = $res ? intval(mysqli_fetch_assoc($res)['ms']) : 0;
                }
                foreach ($qr_students as $s2) {
                    $s2seat = trim($s2['seat_no']);
                    if ($s2seat !== '' && is_seat_no_valid($s2seat)) $base_seat = max($base_seat, intval($s2seat));
                }
                $auto = 0;
                foreach ($qr_students as &$s) {
                    if (trim($s['seat_no']) === '') { $s['seat_no'] = strval(++$base_seat); $auto++; }
                }
                unset($s);
                $auto_note = $auto ? "；其中 {$auto} 人缺座号，已按顺序自动补充" : '';
                if ($mode === 'replace') {
                    // 覆盖导入：清空本班现有学生名单，再用提取的名单替换
                    $stmt = mysqli_prepare($conn, "DELETE FROM students WHERE class_id = ?");
                    mysqli_stmt_bind_param($stmt, "i", $class_id);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                    list($ok, $skip) = insert_students($conn, $class_id, $qr_students);
                    $msg = "覆盖导入完成：成功 {$ok} 人（原名单已删除，用提取名单替换）{$auto_note}" . ($skip ? "，跳过 {$skip} 人（编号重复、座号须为 1 开始的自然数）" : '');
                } else {
                    list($ok, $skip) = insert_students($conn, $class_id, $qr_students);
                    $msg = "附加导入完成：成功 {$ok} 人{$auto_note}" . ($skip ? "，跳过 {$skip} 人（编号重复、座号须为 1 开始的自然数）" : '');
                }
                $msg_type = $ok ? 'success' : 'error';
            }
        }
    }
    // 编辑学生
    elseif ($action === 'edit') {
        $sid = intval($_POST['student_id'] ?? 0);
        $seat = check_input($_POST['seat_no'] ?? '');
        $name = check_input($_POST['name'] ?? '');
        $no = check_input($_POST['student_no'] ?? '');
        $remark = check_input($_POST['remark'] ?? '');
        if ($seat === '' || $name === '' || $no === '') {
            $msg = '更新失败：座号、姓名、编号必填';
            $msg_type = 'error';
        } elseif (!is_seat_no_valid($seat)) {
            $msg = '更新失败：座号须为 1 开始的自然数（如 1、2、3）';
            $msg_type = 'error';
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE students SET seat_no = ?, name = ?, student_no = ?, remark = ? WHERE id = ? AND class_id = ?");
            mysqli_stmt_bind_param($stmt, "ssssii", $seat, $name, $no, $remark, $sid, $class_id);
            if (mysqli_stmt_execute($stmt)) {
                $msg = '学生信息已更新';
            } else {
                $msg = '更新失败：编号可能与其他学生重复';
                $msg_type = 'error';
            }
            mysqli_stmt_close($stmt);
        }
    }
    // 删除单个学生
    elseif ($action === 'delete') {
        $sid = intval($_POST['student_id'] ?? 0);
        $stmt = mysqli_prepare($conn, "DELETE FROM students WHERE id = ? AND class_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $sid, $class_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $msg = '学生已删除';
    }
    // 禁用 / 启用学生（禁用后不打印卡片、扫码不登记；报表仍包含该生及其登记信息）
    elseif ($action === 'toggle_disable') {
        $sid = intval($_POST['student_id'] ?? 0);
        $stmt = mysqli_prepare($conn, "UPDATE students SET disabled = 1 - disabled WHERE id = ? AND class_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $sid, $class_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $msg = '学生状态已更新';
    }
    // 替换导入：按编号匹配批量更新现有学生的座号/姓名/备注（编号不允许修改，仅作匹配依据；不新增、不删除）
    elseif ($action === 'replace_import') {
        $lines = preg_split('/\r\n|\r|\n/', $_POST['replace_area'] ?? '');
        $by_no = [];
        $res = mysqli_query($conn, "SELECT id, student_no FROM students WHERE class_id = " . intval($class_id));
        while ($r = mysqli_fetch_assoc($res)) $by_no[trim($r['student_no'])] = intval($r['id']);
        $upd = 0; $skip = 0; $bad = 0;
        $stmt = mysqli_prepare($conn, "UPDATE students SET seat_no = ?, name = ?, remark = ? WHERE id = ? AND class_id = ?");
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $f = preg_split('/[\t,，]+|\s+/u', $line);
            if (isset($f[0]) && $f[0] === '座号') continue; // 跳过「⬇ 导出」文件的表头行
            $seat = isset($f[0]) ? check_input($f[0]) : '';
            $name = isset($f[1]) ? check_input($f[1]) : '';
            $no = isset($f[2]) ? check_input($f[2]) : '';
            $remark = isset($f[3]) ? check_input($f[3]) : '';
            $sid = ($no !== '' && isset($by_no[$no])) ? $by_no[$no] : 0;
            if ($sid <= 0 || $name === '' || $seat === '') { $skip++; continue; } // 编号不存在或座号/姓名/编号必填缺失：跳过
            if (!is_seat_no_valid($seat)) { $bad++; $skip++; continue; }   // 座号须为 1 开始的自然数
            mysqli_stmt_bind_param($stmt, "sssii", $seat, $name, $remark, $sid, $class_id);
            if (mysqli_stmt_execute($stmt)) $upd++; else $skip++;
        }
        mysqli_stmt_close($stmt);
        $msg = "替换导入完成：更新 {$upd} 人" . ($skip ? "，跳过 {$skip} 行（编号不存在、座号/姓名/编号必填" . ($bad ? "、座号须为 1 开始的自然数" : '') . '）' : '');
        $msg_type = $upd ? 'success' : 'error';
    }
    // 清空名单（敏感操作：需本人登录密码二次确认）
    elseif ($action === 'clear') {
        if (!verify_login_password($conn, $current_teacher_id, $_POST['pwd'] ?? '')) {
            $msg = '登录密码错误，名单未清空';
            $msg_type = 'error';
        } else {
            $stmt = mysqli_prepare($conn, "DELETE FROM students WHERE class_id = ?");
            mysqli_stmt_bind_param($stmt, "i", $class_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = '学生名单已清空';
        }
    }
    // ===== 学生分组管理（stu_groups） =====
    // 建立分组
    elseif ($action === 'group_add') {
        $gname = trim(check_input($_POST['gname'] ?? ''));
        $len = function_exists('mb_strlen') ? mb_strlen($gname, 'UTF-8') : strlen($gname);
        if ($gname === '') {
            $msg = '分组名称不能为空';
            $msg_type = 'error';
        } elseif ($len > 50) {
            $msg = '分组名称不能超过 50 字';
            $msg_type = 'error';
        } else {
            $res = mysqli_query($conn, "SELECT COALESCE(MAX(sort), 0) + 1 AS ns FROM stu_groups WHERE class_id = " . intval($class_id));
            $ns = intval(mysqli_fetch_assoc($res)['ns']);
            $stmt = mysqli_prepare($conn, "INSERT INTO stu_groups (class_id, name, sort, created_at) VALUES (?, ?, ?, NOW())");
            mysqli_stmt_bind_param($stmt, "isi", $class_id, $gname, $ns);
            $ok_g = mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);
            $msg = $ok_g ? '分组「' . $gname . '」已建立' : '建立分组失败';
            if (!$ok_g) $msg_type = 'error';
        }
    }
    // 修改分组名称
    elseif ($action === 'group_edit') {
        $gid = intval($_POST['group_id'] ?? 0);
        $gname = trim(check_input($_POST['gname'] ?? ''));
        if ($gid <= 0 || $gname === '') {
            $msg = '参数不完整';
            $msg_type = 'error';
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE stu_groups SET name = ? WHERE id = ? AND class_id = ?");
            mysqli_stmt_bind_param($stmt, "sii", $gname, $gid, $class_id);
            mysqli_stmt_execute($stmt);
            $ok_g = mysqli_stmt_affected_rows($stmt) > 0;
            mysqli_stmt_close($stmt);
            $msg = $ok_g ? '分组名称已更新' : '分组不存在或名称未变化';
            if (!$ok_g) $msg_type = 'error';
        }
    }
    // 删除分组（组内学生回到未分组）
    elseif ($action === 'group_del') {
        $gid = intval($_POST['group_id'] ?? 0);
        if ($gid <= 0) {
            $msg = '参数不完整';
            $msg_type = 'error';
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE students SET group_id = 0 WHERE group_id = ? AND class_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $gid, $class_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $stmt = mysqli_prepare($conn, "DELETE FROM stu_groups WHERE id = ? AND class_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $gid, $class_id);
            mysqli_stmt_execute($stmt);
            $ok_g = mysqli_stmt_affected_rows($stmt) > 0;
            mysqli_stmt_close($stmt);
            $msg = $ok_g ? '分组已删除（组内学生已变为未分组）' : '分组不存在';
            if (!$ok_g) $msg_type = 'error';
        }
    }
    // 座位 / 分组设置已迁移至 seat_modal.php（students.php「设置分组」与大屏共用弹窗；学生归属分组统一由座位保存时生成）
}

// ===== 学生列表 =====
$stmt = mysqli_prepare($conn, "SELECT * FROM students WHERE class_id = ? ORDER BY CAST(seat_no AS UNSIGNED) ASC, id ASC");
mysqli_stmt_bind_param($stmt, "i", $class_id);
mysqli_stmt_execute($stmt);
$students = mysqli_stmt_get_result($stmt);
mysqli_stmt_close($stmt);

// ===== 学生分组（分组管理面板与名单「分组」列）：id => ['name'=>名称, 'cnt'=>人数] =====
$groups = [];
$res = mysqli_query($conn, "SELECT g.id, g.name, COUNT(s.id) AS cnt
                            FROM stu_groups g
                            LEFT JOIN students s ON s.group_id = g.id AND s.class_id = g.class_id
                            WHERE g.class_id = " . intval($class_id) . "
                            GROUP BY g.id, g.name, g.sort
                            ORDER BY g.sort ASC, g.id ASC");
while ($row = mysqli_fetch_assoc($res)) $groups[intval($row['id'])] = ['name' => $row['name'], 'cnt' => intval($row['cnt'])];

page_header('学生名单 - ' . $class['name'], 'students.php');
?>
<a href="projects.php?class_id=<?php echo $class_id; ?>" style="display:inline-block;margin-bottom:15px;color:#667eea;text-decoration:none;">← 返回班级情况</a>
<?php if ($msg): ?><div class="alert alert-<?php echo $msg_type; ?>"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
<?php if (!$can_manage): ?>
<div class="alert alert-info">您对该名单只有<b>查看权限</b><button type="button" class="hint-q" onclick="toggleHint(event, '名单修改权限属于班级创建者与授权管理成员；如需修改请联系其处理')">?</button></div>
<?php endif; ?>

<div class="stat-bar">
    <div class="stat-box"><div class="num"><?php echo mysqli_num_rows($students); ?></div><div class="label">学生总数</div></div>
</div>

<?php if ($can_manage): ?>
<!-- 添加学生弹窗（手动添加 / EXCEL粘贴 / 扫码导入 子标签）；原顶部选项卡已改弹窗，名单为主内容常显 -->
<div class="modal-mask" id="addModal">
  <div class="modal" style="max-width:780px;max-height:88vh;overflow-y:auto;">
    <h3>➕ 添加学生</h3>
    <div class="tabs" style="margin-bottom:12px;">
        <a href="#subtab-manual" data-subtab="manual" class="subtab-link active">手动添加</a>
        <a href="#subtab-paste" data-subtab="paste" class="subtab-link">EXCEL粘贴导入</a>
        <a href="#subtab-scan" data-subtab="scan" class="subtab-link">扫码导入班级码</a>
    </div>

    <!-- 手动添加 -->
    <div class="subtab-panel" id="subtab-manual">
        <h3>添加学生</h3>
        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="add" autocomplete="off">
            <input type="hidden" name="class_id" value="<?php echo $class_id; ?>" autocomplete="off">
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <div class="form-group" style="flex:1;min-width:120px;"><label>座号 *</label><input type="text" name="seat_no" id="add_seat" class="form-control" placeholder="自动续号：最后座号+1，可修改" inputmode="numeric" pattern="[1-9][0-9]*" title="座号必填，须为 1 开始的自然数（如 1、2、3）；座号可重复，编号唯一" required autocomplete="off"></div>
                <div class="form-group" style="flex:1;min-width:120px;"><label>姓名 *</label><input type="text" name="name" class="form-control" placeholder="学生姓名" required autocomplete="off"></div>
                <div class="form-group" style="flex:1;min-width:140px;"><label>编号（唯一识别码）*</label><input type="text" name="student_no" class="form-control" placeholder="如：2024001" required autocomplete="off"></div>
                <div class="form-group" style="flex:1;min-width:120px;"><label>备注</label><input type="text" name="remark" class="form-control" placeholder="可留空" autocomplete="off"></div>
            </div>
            <button type="submit" class="btn">添加学生</button>
        </form>
    </div>

    <!-- EXCEL粘贴导入 -->
    <div class="subtab-panel" id="subtab-paste" style="display:none;">
        <h3>EXCEL粘贴导入</h3>
        <p class="tip" style="margin-bottom:15px;">
            可直接复制 EXCEL 内容粘贴，一行一人<button type="button" class="hint-q" onclick="toggleHint(event, '可直接复制 EXCEL 内容区域后粘贴，对应一行一人。<br>格式说明：&quot;座号 + 姓名 + 编号 + 备注&quot;，每个元素在 EXCEL 中是一列，可用 TAB、空格或逗号隔开。<br><b>座号、姓名、编号必填</b>，座号须为 <b>1 开始的自然数</b>（如 1、2、3，可重复）；编号唯一（重复跳过）。<br>不确定格式时可先「⬇ 下载模板」，在模板中填写后整表复制粘贴进来。')">?</button>
            <a href="students.php?class_id=<?php echo $class_id; ?>&template=1" class="btn btn-sm btn-outline" style="margin-left:10px;text-decoration:none;">⬇ 下载模板</a>
        </p>
        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="import_paste" autocomplete="off">
            <input type="hidden" name="class_id" value="<?php echo $class_id; ?>" autocomplete="off">
            <div class="form-group">
                <label>粘贴区域</label>
                <textarea name="paste_area" class="form-control" style="min-height:160px;" placeholder="请直接复制EXCEL内容区域后粘贴，对应一行一人" required></textarea>
            </div>
            <button type="submit" class="btn">立即导入</button>
        </form>
    </div>

    <!-- 扫码导入 -->
    <div class="subtab-panel" id="subtab-scan" style="display:none;">
        <h3>扫码导入班级码</h3>
        <p class="tip" style="margin-bottom:15px;">
            扫码或输入提取码 / 班级码即可导入整班学生<button type="button" class="hint-q" onclick="toggleHint(event, '扫描&quot;班级导入二维码&quot;，或直接输入二维码下方的 <b>8 位提取码</b>、源班级的 <b>8 位班级码</b>（或粘贴提取链接），即可快速导入整班学生；<br>点击「导入名单」后可选择：<b>附加导入</b>（保留现有学生，编号重复跳过）或 <b>覆盖导入</b>（删除本班现有学生，用提取名单替换）。<br><b>旧格式名单（编号-姓名-备注）或缺座号的学生，将按顺序自动补充座号</b>：附加导入接续本班现有最大座号，覆盖导入从 1 开始。')">?</button>
        </p>
        <div id="qr-reader" style="max-width:420px;"></div>
        <div class="form-group" style="max-width:420px;">
            <label>或手动粘贴二维码内容 / 提取链接 / 提取码 / 班级码</label>
            <textarea id="qr_content" class="form-control" style="min-height:80px;" placeholder="bjdl2024001-张三-1-|2024002-李四-2-|...（编号-姓名-座号-备注） 或 8位提取码/班级码（如 12345678）"></textarea>
        </div>
        <button type="button" class="btn" onclick="submitQrImport()">导入名单</button>
        <form method="post" id="qrForm" autocomplete="off">
            <input type="hidden" name="action" value="import_qr" autocomplete="off">
            <input type="hidden" name="class_id" value="<?php echo $class_id; ?>" autocomplete="off">
            <input type="hidden" name="qr_content" id="qr_content_hidden" autocomplete="off">
            <input type="hidden" name="import_mode" id="qr_mode" value="append" autocomplete="off">
        </form>
    </div>
    </div>
</div>

<!-- 导入方式选择弹层（附加 / 覆盖） -->
<div class="modal-mask" id="importModeModal">
    <div class="modal" style="max-width:480px;">
        <h3>选择导入方式<button type="button" class="hint-q" onclick="toggleHint(event, '<b>导入方式说明</b><br>· 提取到的名单将导入到当前班级：<b><?php echo htmlspecialchars($class["name"], ENT_QUOTES); ?></b><br>· <b>附加导入</b>=保留现有学生，编号重复的跳过<br>· <b>覆盖导入</b>=删除本班现有学生，用提取名单替换')">?</button></h3>
        <div style="display:flex;flex-direction:column;gap:10px;margin:15px 0;">
            <button type="button" class="btn btn-outline" style="width:100%;" onclick="doQrImport('append')">➕ 附加导入<span style="color:#999;font-size:12px;">（保留现有学生，编号重复的跳过）</span></button>
            <button type="button" class="btn btn-danger" style="width:100%;" onclick="doQrImport('replace')">♻️ 覆盖导入<span style="font-size:12px;opacity:0.85;">（删除本班现有学生，用提取名单替换）</span></button>
            <button type="button" class="btn btn-outline" style="width:100%;background:#f0f0f0;border-color:#f0f0f0;color:#666;" onclick="closeModeModal()">取消</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($can_manage): ?>
<!-- 学生分组管理弹窗 -->
<div class="modal-mask" id="groupsModal">
  <div class="modal" style="max-width:680px;max-height:88vh;overflow-y:auto;">
    <h3>🪑 学生分组 <span style="font-size:12px;color:#999;font-weight:normal;">分组用于项目页「分组显示」与整组批量操作，不影响登记</span></h3>
    <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px;" autocomplete="off">
        <input type="hidden" name="action" value="group_add" autocomplete="off">
        <input type="hidden" name="class_id" value="<?php echo $class_id; ?>" autocomplete="off">
        <input type="text" name="gname" class="form-control" style="max-width:220px;" placeholder="新分组名称（如：第一组）" required autocomplete="off">
        <button type="submit" class="btn btn-sm">＋ 建立分组</button>
        <button type="button" class="btn btn-sm btn-outline" onclick="openSeatGroupModal()" title="在座位弹窗中拖拽 / 点选为学生入座，保存后按座位自动生成分组；大屏「座位模式」按此呈现">🪑 设置分组</button>
    </form>
    <?php if ($groups): ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>分组名称</th><th style="width:90px;">人数</th><th style="width:180px;">操作</th></tr></thead>
            <tbody>
            <?php foreach ($groups as $gid4 => $g4): ?>
                <tr>
                    <td><?php echo htmlspecialchars($g4['name']); ?></td>
                    <td><?php echo $g4['cnt']; ?> 人</td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline" onclick='groupEdit(<?php echo $gid4; ?>, <?php echo json_encode($g4['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>重命名</button>
                        <button type="button" class="btn btn-sm btn-danger" onclick="groupDel(<?php echo $gid4; ?>, '<?php echo htmlspecialchars(addslashes($g4['name'])); ?>', <?php echo $g4['cnt']; ?>)">删除</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p class="tip" style="margin:0;">暂无分组<button type="button" class="hint-q" onclick="toggleHint(event, '<b>学生分组</b><br>· 可在上方输入名称建立分组<br>· 或点「🪑 设置分组」按座位自动生成（第1组、第2组…）')">?</button></p>
    <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- 名单表格（默认主内容） -->
<div class="panel" id="rosterPanel">
    <h3>学生名单 <span style="font-size:12px;color:#999;font-weight:normal;">点击姓名或「查阅记录」弹窗查看该生登记情况（与家长查询一致 · 全部项目可查）</span></h3>
    <div class="toolbar">
        <input type="text" id="searchBox" class="form-control" style="max-width:240px;" placeholder="搜索姓名 / 编号" oninput="filterTable()" autocomplete="off">
        <div class="spacer"></div>
        <?php if ($can_manage): ?>
        <button type="button" class="btn btn-sm" onclick="openAddModal()">➕ 添加学生</button>
        <button type="button" class="btn btn-sm btn-outline" onclick="openGroupsModal()" title="建立 / 重命名分组，或按座位图自动生成">🪑 分组</button>
        <div class="admin-dd">
            <button type="button" class="btn btn-sm btn-outline" onclick="toggleDD(event,this)">⚙ 管理 ▾</button>
            <div class="admin-dd-menu">
                <a href="qrcode.php?class_id=<?php echo $class_id; ?>">🎲 生成二维码</a>
                <?php if (mysqli_num_rows($students) > 0): ?>
                <a href="students.php?class_id=<?php echo $class_id; ?>&export=1">⬇ 导出</a>
                <button type="button" class="dd-item" onclick="openReplaceImport()">⬆ 替换导入</button>
                <?php endif; ?>
                <button type="button" class="dd-item dd-danger" onclick="clearRoster()">⚠️ 清空名单</button>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="table-wrap">
        <table class="data-table" id="studentTable">
            <thead>
                <tr>
                    <?php if ($can_manage): ?><th style="width:34px;"><input type="checkbox" onchange="selAllStu(this.checked)" title="全选 / 全不选" autocomplete="off"></th><?php endif; ?>
                    <th>座号</th><th>姓名</th><th>编号</th><th>分组</th><th>备注</th><th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($s = mysqli_fetch_assoc($students)): $sid5 = intval($s['id']); $sgid = intval($s['group_id'] ?? 0); $sdis = !empty($s['disabled']); ?>
                <tr data-name="<?php echo htmlspecialchars($s['name']); ?>" data-no="<?php echo htmlspecialchars($s['student_no']); ?>"<?php echo $sdis ? ' style="opacity:.55;"' : ''; ?>>
                    <?php if ($can_manage): ?><td><input type="checkbox" class="stu-sel" value="<?php echo $sid5; ?>" title="选择该生" autocomplete="off"></td><?php endif; ?>
                    <td><?php echo htmlspecialchars($s['seat_no']); ?></td>
                    <td><a href="query.php?c=<?php echo rawurlencode(strval($class['class_code'] ?? '')); ?>&sid=<?php echo $sid5; ?>&tview=1" style="color:#667eea;text-decoration:none;font-weight:bold;" onclick="openStudentStats(<?php echo $sid5; ?>, this.href); return false;"><?php echo htmlspecialchars($s['name']); ?></a><?php if ($sdis): ?> <span title="已禁用：不打印卡片、扫码不登记（报表仍包含）" style="font-size:12px;color:#fff;background:#e74c3c;border-radius:4px;padding:1px 6px;">🚫 已禁用</span><?php endif; ?></td>
                    <td><?php echo htmlspecialchars($s['student_no']); ?></td>
                    <td>
                        <span style="font-size:12px;color:#888;"><?php echo ($sgid && isset($groups[$sgid])) ? htmlspecialchars($groups[$sgid]['name']) : '未分组'; ?></span>
                    </td>
                    <td><?php echo htmlspecialchars($s['remark']); ?></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline" onclick="openStudentStats(<?php echo $sid5; ?>)">查阅记录</button>
                        <?php if ($can_manage): ?>
                        <button type="button" class="btn btn-sm btn-outline" onclick='openEdit(<?php echo json_encode($s, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>编辑</button>
                        <button type="button" class="btn btn-sm <?php echo $sdis ? 'btn-success' : 'btn-warning'; ?>" onclick="doAction('toggle_disable', <?php echo $sid5; ?>, '<?php echo $sdis ? '确认启用该学生？启用后恢复卡片打印与扫码登记。' : '确认禁用该学生？禁用后不打印其卡片、扫码不登记（报表仍包含）。'; ?>')"><?php echo $sdis ? '启用' : '禁用'; ?></button>
                        <button type="button" class="btn btn-sm btn-danger" onclick="doAction('delete', <?php echo $s['id']; ?>, '确认删除学生 <?php echo htmlspecialchars(addslashes($s['name'])); ?>？')">删除</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if (mysqli_num_rows($students) === 0): ?>
                <tr><td colspan="<?php echo $can_manage ? 7 : 6; ?>" style="text-align:center;color:#999;">暂无学生，请先导入名单</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 查阅记录弹层：iframe 加载该生统计页（query.php 教师视图，与家长查询一致：三选项卡 / 矩阵 / 图表 / 综合报告，全部项目可查） -->
<div class="modal-mask" id="studentStatsModal">
    <div class="modal" style="width:960px;max-width:92vw;height:86vh;display:flex;flex-direction:column;">
        <h3 id="studentStatsTitle">查阅学生登记记录</h3>
        <iframe id="studentStatsFrame" src="about:blank" style="flex:1;width:100%;border:1px solid #e0e3ef;border-radius:8px;background:#fff;min-height:0;"></iframe>
    </div>
</div>

<?php if ($can_manage): ?>
<!-- 编辑学生弹层 -->
<div class="modal-mask" id="editModal">
    <div class="modal">
        <h3>编辑学生</h3>
        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="edit" autocomplete="off">
            <input type="hidden" name="class_id" value="<?php echo $class_id; ?>" autocomplete="off">
            <input type="hidden" name="student_id" id="edit_id" autocomplete="off">
            <div class="form-group"><label>座号 *</label><input type="text" name="seat_no" id="edit_seat" class="form-control" inputmode="numeric" pattern="[1-9][0-9]*" title="座号必填，须为 1 开始的自然数（如 1、2、3）" required autocomplete="off"></div>
            <div class="form-group"><label>姓名 *</label><input type="text" name="name" id="edit_name" class="form-control" required autocomplete="off"></div>
            <div class="form-group"><label>编号 *</label><input type="text" name="student_no" id="edit_no" class="form-control" required autocomplete="off"></div>
            <div class="form-group"><label>备注</label><input type="text" name="remark" id="edit_remark" class="form-control" autocomplete="off"></div>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeEdit()">取消</button>
                <button type="submit" class="btn">保存</button>
            </div>
        </form>
    </div>
</div>

<!-- 替换导入弹层：按编号匹配批量更新现有学生（座号/姓名/备注），编号不可改、不新增不删除 -->
<div class="modal-mask" id="replaceModal">
    <div class="modal" style="max-width:600px;">
        <h3>⬆ 替换导入 <span style="font-size:12px;color:#999;font-weight:normal;">批量更新现有学生信息</span><button type="button" class="hint-q" onclick="toggleHint(event, '<b>替换导入说明</b><br>· 每行一人：<b>座号 姓名 编号 备注</b>（TAB / 空格 / 逗号分隔）<br>· 与「⬇ 导出」的文件格式一致，可导出后编辑再整表粘贴回来<br>· 按<b>编号</b>匹配本班现有学生，更新其<b>座号 / 姓名 / 备注</b><br>· 座号须为 <b>1 开始的自然数</b>（可留空）；编号随意设置<br>· <b>编号不允许替换修改</b>（仅作匹配依据）；不会新增或删除学生')">?</button></h3>
        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="replace_import" autocomplete="off">
            <input type="hidden" name="class_id" value="<?php echo $class_id; ?>" autocomplete="off">
            <div class="form-group">
                <textarea name="replace_area" class="form-control" style="min-height:200px;" placeholder="1 张三 2024001&#10;2 李四 2024002 组长&#10;（或直接粘贴导出的 CSV 文件内容）" required></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeReplaceImport()">取消</button>
                <button type="submit" class="btn">开始替换导入</button>
            </div>
        </form>
    </div>
</div>

<!-- 清空名单弹层：二次确认 + 登录密码（敏感操作） -->
<div class="modal-mask" id="clearModal">
    <div class="modal" style="max-width:430px;">
        <h3>⚠️ 清空名单<button type="button" class="hint-q" onclick="toggleHint(event, '<b>清空名单说明</b><br>· 删除本班<b>全部学生名单</b>，该操作<b>不可恢复</b><br>· 登记记录仍保留但不再关联学生<br>· 需输入本人登录密码二次确认')">?</button></h3>
        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="clear" autocomplete="off">
            <input type="hidden" name="class_id" value="<?php echo $class_id; ?>" autocomplete="off">
            <div class="form-group"><label>登录密码</label><input type="password" name="pwd" class="form-control" required autofocus autocomplete="off"></div>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeClearModal()">取消</button>
                <button type="submit" class="btn btn-danger">确认清空</button>
            </div>
        </form>
    </div>
</div>

<form method="post" id="actionForm" autocomplete="off">
    <input type="hidden" name="action" id="act_action" autocomplete="off">
    <input type="hidden" name="class_id" value="<?php echo $class_id; ?>" autocomplete="off">
    <input type="hidden" name="student_id" id="act_id" autocomplete="off">
</form>

<form method="post" id="groupForm" autocomplete="off">
    <input type="hidden" name="action" id="g_action" autocomplete="off">
    <input type="hidden" name="class_id" value="<?php echo $class_id; ?>" autocomplete="off">
    <input type="hidden" name="group_id" id="g_gid" autocomplete="off">
    <input type="hidden" name="gname" id="g_name" autocomplete="off">
</form>

<!-- 设置分组弹层：iframe 加载 seat_modal.php（与大屏「进行分组 / 调整分组」共用同一弹窗）；保存后整页刷新同步座位与分组 -->
<div class="modal-mask" id="seatGroupModal">
    <div class="modal" style="width:1020px;max-width:94vw;height:88vh;display:flex;flex-direction:column;">
        <!-- 弹窗内容（标题/取消/保存按钮）由 seat_modal.php 自带，此处仅承载 iframe -->
        <iframe id="seatGroupFrame" src="about:blank" style="flex:1;width:100%;border:0;border-radius:8px;background:#fff;min-height:0;"></iframe>
    </div>
</div>
<?php endif; ?>

<script src="assets/js/html5-qrcode.min.js"></script>
<script>
<?php if ($can_manage): ?>
// 添加 / 分组弹窗（原顶部选项卡改为弹窗；名单为主内容常显）
// 座号自动续号：取名单中最大座号 +1（每次点开「添加学生」自动预填；座号可重复，编号唯一）
function nextSeatNo() {
    var max = 0;
    document.querySelectorAll('#studentTable tbody tr').forEach(function(tr) {
        var cell = tr.querySelector('input.stu-sel') ? tr.children[1] : tr.children[0];
        if (!cell) return;
        var n = parseInt((cell.textContent || '').trim(), 10);
        if (!isNaN(n) && n > max) max = n;
    });
    return max + 1;
}
function openAddModal() {
    var seat = document.querySelector('#subtab-manual input[name="seat_no"]');
    if (seat) seat.value = nextSeatNo();
    document.getElementById('addModal').classList.add('show');
}
function closeAddModal() { document.getElementById('addModal').classList.remove('show'); }
function openGroupsModal() { document.getElementById('groupsModal').classList.add('show'); }
function closeGroupsModal() { document.getElementById('groupsModal').classList.remove('show'); }

// 「添加」内子标签（手动添加 / EXCEL粘贴 / 扫码导入）
document.querySelectorAll('.subtab-link').forEach(function(link) {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelectorAll('.subtab-link').forEach(function(l) { l.classList.remove('active'); });
        document.querySelectorAll('.subtab-panel').forEach(function(p) { p.style.display = 'none'; });
        link.classList.add('active');
        document.getElementById('subtab-' + link.dataset.subtab).style.display = 'block';
    });
});

// 编辑弹层
function openEdit(s) {
    document.getElementById('edit_id').value = s.id;
    document.getElementById('edit_seat').value = s.seat_no;
    document.getElementById('edit_name').value = s.name;
    document.getElementById('edit_no').value = s.student_no;
    document.getElementById('edit_remark').value = s.remark;
    document.getElementById('editModal').classList.add('show');
}
function closeEdit() {
    document.getElementById('editModal').classList.remove('show');
}
// 替换导入弹层
function openReplaceImport() {
    document.getElementById('replaceModal').classList.add('show');
}
function closeReplaceImport() {
    document.getElementById('replaceModal').classList.remove('show');
}
// 清空名单：弹窗确认 → 登录密码二次确认（服务端再校验密码，错误则不动名单）
function clearRoster() {
    if (!confirm('确认清空本班全部学生名单？该操作不可恢复！')) return;
    var inp = document.querySelector('#clearModal input[name="pwd"]');
    if (inp) inp.value = '';
    document.getElementById('clearModal').classList.add('show');
}
function closeClearModal() {
    document.getElementById('clearModal').classList.remove('show');
}
function doAction(action, id, confirmText) {
    if (!confirm(confirmText)) return;
    document.getElementById('act_action').value = action;
    document.getElementById('act_id').value = id;
    document.getElementById('actionForm').submit();
}

// ===== 学生分组（建/改/删走分组面板表单；学生归属分组统一由「🪑 设置分组」按座位生成） =====
// 设置分组弹窗：iframe 加载 seat_modal.php（与大屏共用），保存后整页刷新
function openSeatGroupModal() {
    document.getElementById('seatGroupFrame').src = 'seat_modal.php?class_id=' + <?php echo $class_id; ?>;
    document.getElementById('seatGroupModal').classList.add('show');
}
function selAllStu(on) {
    document.querySelectorAll('.stu-sel').forEach(function (cb) { cb.checked = on; });
}
function groupEdit(gid, oldName) {
    var name = prompt('修改分组名称：', oldName);
    if (name === null) return;
    name = name.trim();
    if (name === '' || name === oldName) return;
    document.getElementById('g_action').value = 'group_edit';
    document.getElementById('g_gid').value = gid;
    document.getElementById('g_name').value = name;
    document.getElementById('groupForm').submit();
}
function groupDel(gid, name, cnt) {
    if (!confirm('确认删除分组「' + name + '」？组内 ' + cnt + ' 名学生将变为未分组。')) return;
    document.getElementById('g_action').value = 'group_del';
    document.getElementById('g_gid').value = gid;
    document.getElementById('groupForm').submit();
}

// ===== 座位设置已迁移至 seat_modal.php（「🪑 设置分组」以 iframe 加载，见 openSeatGroupModal） =====

// 名单搜索过滤
function filterTable() {
    var kw = document.getElementById('searchBox').value.trim().toLowerCase();
    document.querySelectorAll('#studentTable tbody tr').forEach(function(tr) {
        var name = (tr.dataset.name || '').toLowerCase();
        var no = (tr.dataset.no || '').toLowerCase();
        tr.style.display = (kw === '' || name.indexOf(kw) > -1 || no.indexOf(kw) > -1) ? '' : 'none';
    });
}

// 扫码导入
var qrScanner = null;
function startQrScan() {
    if (typeof Html5Qrcode === 'undefined') return;
    qrScanner = new Html5Qrcode('qr-reader');
    qrScanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 240, height: 240 } },
        function(decodedText) {
            document.getElementById('qr_content').value = decodedText;
            submitQrImport();
        },
        function() {}
    ).catch(function(err) {
        document.getElementById('qr-reader').innerHTML = '<p style="padding:20px;color:#999;font-size:13px;">摄像头启动失败（需 HTTPS 或 localhost 环境），可直接在下方手动粘贴二维码内容。</p>';
    });
}
function submitQrImport() {
    var content = document.getElementById('qr_content').value.trim();
    if (!content) { showToast('请先扫码、粘贴二维码内容/提取链接，或输入 8 位提取码', 'error'); return; }
    // 弹窗选择导入方式：附加 / 覆盖
    document.getElementById('importModeModal').classList.add('show');
}
function closeModeModal() {
    document.getElementById('importModeModal').classList.remove('show');
}
function doQrImport(mode) {
    document.getElementById('qr_mode').value = mode;
    document.getElementById('qr_content_hidden').value = document.getElementById('qr_content').value.trim();
    closeModeModal();
    document.getElementById('qrForm').submit();
}
// 仅在「扫码导入」子标签可见时启动摄像头
var scanTab = document.querySelector('.subtab-link[data-subtab="scan"]');
if (scanTab) scanTab.addEventListener('click', function() {
    if (!qrScanner) startQrScan();
});
<?php endif; ?>
</script>
<script>
// ===== 查阅记录弹窗（iframe 加载 query.php 教师视图：与家长查询一致的三选项卡呈现，不受公开查询设置限制、全部项目可查） =====
function openStudentStats(sid, url) {
    var frame = document.getElementById('studentStatsFrame');
    var u = url || ('query.php?c=<?php echo rawurlencode(strval($class['class_code'] ?? '')); ?>&sid=' + sid + '&tview=1');
    if (u.indexOf('embed=1') === -1) u += (u.indexOf('?') === -1 ? '?' : '&') + 'embed=1'; // 内嵌模式：无导航无返回
    frame.src = u;
    document.getElementById('studentStatsModal').classList.add('show');
}
(function () {
    var mask = document.getElementById('studentStatsModal');
    if (!mask) return;
    var frame = document.getElementById('studentStatsFrame');
    // 关闭时卸载 iframe（遮罩空白点击 / layout 注入的 ✕ 按钮 / Esc 均生效）
    mask.addEventListener('modalclosed', function () { frame.src = 'about:blank'; });
    mask.addEventListener('click', function (e) {
        if (e.target === mask) { mask.classList.remove('show'); frame.src = 'about:blank'; }
    });
})();
</script>
<?php page_footer(); ?>
