<?php
/**
 * 项目管理：五种范围（全校/年段/学科/班级/班级学科）项目创建（按角色权限）、登记模式（默认/打卡，项目维度）、
 * 重命名、复制（不含登记数据）、导入历史数据（模板下载+CSV 上传）、软删除回收站（恢复/彻底删除）、查看登记情况；项目列表按角色可见
 * 班级上下文：?class_id=X（从班级卡片进入）= 该班级的项目列表（新建项目固定本班，附「返回班级列表」按钮）；通用列表才需选择班级
 * 删除仅限项目创建人（自己建立的项目）；卡片配色区分：自己建立=蓝色，授权的（他人创建）=绿色+「授权的」标签，与班级卡片一致
 * 分组标题排序：全校 → 年段 → 班级（学科全校随全校层，班级学科随班级层）；班级分组标题显示建立者（非本人创建的班级）
 * 分块显示：三组选项卡 我的项目 / 授权项目 / 其他项目（其他项目仅管理员可见，按班级分组、班级名后附所有者账号+名字）
 * 上下文：严格按当前上下文学校（总管理员按后台切换）；平台（个人账号）上下文额外并入凭授权码加入班级的项目（可跨校）
 */
require_once 'includes/db_config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/layout.php';

$conn = getConnection();
header('Cache-Control: no-store'); // 权限级别变化后必须拉取最新页面（微信内置浏览器等激进缓存会展示旧权限渲染）

$eval_modes_enabled = get_enabled_eval_modes($conn, $current_teacher_id); // 可选用（未禁用系统模板 + 我的自定义）：下拉与校验
if (!isset($eval_modes_enabled['abcd'])) $eval_modes_enabled['abcd'] = get_eval_modes()['abcd'];   // 「答题」模板始终可选（举牌登记模式强制使用）
$eval_modes = get_eval_modes(); // 展示兜底基础（卡片徽标解析，下方按需补入引用键）
$scope_names = get_scope_names();
$msg = '';
$msg_type = 'success';
$flash = flash_take(); // PRG 重定向后的一次性操作提示
if ($flash) { $msg = $flash['msg']; $msg_type = $flash['type']; }
$school_id = current_school_id($conn);
// 举牌开关（管理员后台「特色功能开关」，未配置=默认开放）：关闭后不能新建/改为对应模式项目，二维码页入口同步隐藏（已建项目不受影响）
$raise_enabled = get_quizraise_feature($conn, $school_id, 'raise');
$omr_enabled = get_feature($conn, $school_id, 'feat_omr');   // 答题卡（涂卡识别）模式开关（未配置=默认开放）
// 答题卡项目评价模式统一为「数值」（存量项目幂等迁移；识别得分自动写入，不可改其他评价模式）
mysqli_query($conn, "UPDATE projects SET eval_mode = 'score' WHERE mode = 'omr' AND eval_mode <> 'score' AND deleted_at IS NULL");

// 基础数据（本校）

// 下载历史数据导入模板（项目级：编号,姓名,日期,评价值）
if (($_GET['dl_template'] ?? '') === 'project') {
    $pid = intval($_GET['project_id'] ?? 0);
    $project = get_project_for($conn, $pid, $current_teacher_id, 'manage');
    if (!$project || intval($project['class_id']) <= 0) { header('Location: projects.php'); exit(); }
    $daily = (($project['mode'] ?? 'count') === 'daily');
    $sample1 = $daily ? '2024001,张三,2026-09-01,优' : '2024001,张三,2026-09-01 10:00,优';
    $sample2 = '2024002,李四,,';
    download_csv('项目历史数据导入模板.csv', [
        '编号,姓名,日期,评价值',
        $sample1,
        $sample2,
    ]);
}

// 可见班级（用于班级项目创建与展示）
$visible_class_ids = get_visible_class_ids($conn, $current_teacher_id);
$classes_list = [];
if ($visible_class_ids) {
    $in = implode(',', array_map('intval', $visible_class_ids));
    $res = mysqli_query($conn, "SELECT c.* FROM classes c
                                WHERE c.id IN ({$in}) ORDER BY c.id");
    while ($row = mysqli_fetch_assoc($res)) $classes_list[] = $row;
}

// 班级选项：全部可见班级
$class_options = $classes_list;

// 当前角色可创建的范围（受「特色功能开关-开放教师建立项目」与角色勾选限制）
$can_create_project = can_create_project_gate($conn, $current_teacher_id);
$member_map_pre = get_teacher_class_member_map($conn, $current_teacher_id); // 授权班级（manage/create 可建项目）
$member_create_ids = [];
foreach ($member_map_pre as $c => $pm) {
    if (in_array($pm, ['manage', 'create'], true)) $member_create_ids[] = intval($c);
}
$creatable_scopes = [];
if ($can_create_project) {
    // 单用户版：仅班级项目可创建（学校/年段/学科/班级学科范围随角色体系移除）
    if (get_teacher_created_class_ids($conn, $current_teacher_id) || $member_create_ids) $creatable_scopes[] = 'class';
}

// 天/时/分/秒 阈值输入 → 秒（上限30天）；勾选开关（{prefix}_on=1）开启才生效，未勾选=关闭；天/时/分/秒全为 0 同样视为关闭
if (!function_exists('parse_threshold_seconds')) {
    function parse_threshold_seconds($prefix) {
        if (intval($_POST[$prefix . '_on'] ?? 0) !== 1) return 0;   // 开关未勾选：关闭
        $d = max(0, intval($_POST[$prefix . '_d'] ?? 0));
        $h = max(0, intval($_POST[$prefix . '_h'] ?? 0));
        $m = max(0, intval($_POST[$prefix . '_m'] ?? 0));
        $s = max(0, intval($_POST[$prefix . '_s'] ?? 0));
        return min($d * 86400 + $h * 3600 + $m * 60 + $s, 2592000);
    }
}

// 登记模式选项（项目维度：count=仅一次 multi=多次项次 daily=按日期打卡 raise=举牌四选一 omr=答题卡涂卡；多次排在打卡前）
$reg_modes = [
    'count' => '单次（仅登记一次）',
    'multi' => '多次（可多次登记）',
    'daily' => '打卡（按日期打卡）',
    'raise' => '举牌（四选一）',
    'omr'   => '答题卡（多题涂卡识别）',
];
// 按开关隐藏对应选项（编辑表单保留全量选项，由 openEdit 按项目现状禁用，见 openEdit）
$reg_modes_add = $reg_modes;
if (!$raise_enabled) unset($reg_modes_add['raise']);
if (!$omr_enabled) unset($reg_modes_add['omr']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = check_input($_POST['name'] ?? '');
        $mode = $_POST['eval_mode'] ?? 'smile';
        if (!isset($eval_modes_enabled[$mode])) $mode = 'smile';
        $reg_mode = $_POST['reg_mode'] ?? 'count';
        if (!isset($reg_modes[$reg_mode])) $reg_mode = 'count';
        if ((!$raise_enabled && $reg_mode === 'raise') || (!$omr_enabled && $reg_mode === 'omr')) $reg_mode = 'count';   // 开关关闭：不能新建对应模式项目
        if ($reg_mode === 'raise') $mode = 'abcd';    // 举牌登记模式：评价模式强制为「答题」（A/B/C/D）
        if ($reg_mode === 'omr') $mode = 'score';                            // 答题卡登记模式：评价模式固定为「数值」（识别得分自动写入）
        $scope = 'class'; // 单用户版：仅班级项目（范围固定，不接收 scope 参数）
        $class_id = intval($_POST['class_id'] ?? 0);

        if (!$can_create_project) {
            $msg = '教师建项目功能已关闭或您暂无建项目权限，请联系管理员';
            $msg_type = 'error';
        } elseif ($name === '') {
            $msg = '项目名称不能为空';
            $msg_type = 'error';
        } elseif (!can_create_project($conn, $current_teacher_id, $scope, $class_id)) {
            $msg = '您没有创建该范围项目的权限';
            $msg_type = 'error';
        } elseif ($class_id <= 0) {
            $msg = '请选择班级';
            $msg_type = 'error';
        } else {
            $late_seconds = parse_threshold_seconds('late');
            $lock_seconds = parse_threshold_seconds('lock');
            $stmt = mysqli_prepare($conn, "INSERT INTO projects (school_id, class_id, scope, created_by, name, eval_mode, mode, late_seconds, lock_seconds, created_at)
                                           VALUES (?, ?, 'class', ?, ?, ?, ?, ?, ?, NOW())");
            mysqli_stmt_bind_param($stmt, "iissssii", $school_id, $class_id, $current_teacher_id, $name, $mode, $reg_mode, $late_seconds, $lock_seconds);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = '项目创建成功';
        }
    }
    elseif ($action === 'rename') {
        $pid = intval($_POST['project_id'] ?? 0);
        $name = check_input($_POST['name'] ?? '');
        $mode = $_POST['eval_mode'] ?? 'smile';
        if (!isset($eval_modes_enabled[$mode])) $mode = 'smile';
        $reg_mode = ($_POST['reg_mode'] ?? 'count');
        if (!isset($reg_modes[$reg_mode])) $reg_mode = 'count'; // 非法值回落（multi 亦可改）
        $project = get_project_for($conn, $pid, $current_teacher_id, 'manage');
        // 开关关闭：仅原本就是举牌的项目可保持原模式，其余不能改为对应模式
        if (!$raise_enabled && $reg_mode === 'raise' && ($project['mode'] ?? '') !== 'raise') $reg_mode = 'count';
        if (!$omr_enabled && $reg_mode === 'omr' && ($project['mode'] ?? '') !== 'omr') $reg_mode = 'count';
        if ($reg_mode === 'raise') $mode = 'abcd';   // 举牌登记模式：评价模式强制为「答题」（A/B/C/D）
        if ($reg_mode === 'omr') $mode = 'score';                           // 答题卡登记模式：评价模式固定为「数值」不可改
        if ($project && $name !== '') {
            $late_seconds = parse_threshold_seconds('late');
            $lock_seconds = parse_threshold_seconds('lock');
            $stmt = mysqli_prepare($conn, "UPDATE projects SET name = ?, eval_mode = ?, mode = ?, late_seconds = ?, lock_seconds = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "sssiii", $name, $mode, $reg_mode, $late_seconds, $lock_seconds, $pid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = '项目信息已更新';
        }
    }
    // 复制项目（同一班级/范围，不含登记数据）
    elseif ($action === 'copy') {
        $pid = intval($_POST['project_id'] ?? 0);
        $project = get_project_for($conn, $pid, $current_teacher_id, 'manage');
        if ($project) {
            $new_name = $project['name'] . '（副本）';
            $copy_mode = ($project['mode'] ?? 'count');
            $stmt = mysqli_prepare($conn, "INSERT INTO projects (school_id, class_id, scope, created_by, name, eval_mode, mode, late_seconds, lock_seconds, created_at)
                                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            mysqli_stmt_bind_param($stmt, "iisisssii",
                $project['school_id'], $project['class_id'], $project['scope'], $current_teacher_id,
                $new_name, $project['eval_mode'], $copy_mode,
                $project['late_seconds'], $project['lock_seconds']);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = '项目已复制（不含登记数据）';
        }
    }
    // 删除项目（敏感操作：需验证登录密码；软删除进回收站：登记数据保留，可恢复；项目创建人可删自己建立的项目，班级授权-管理级别可删除本班全部项目）
    elseif ($action === 'delete') {
        $pid = intval($_POST['project_id'] ?? 0);
        if (!verify_login_password($conn, $current_teacher_id, $_POST['login_pwd'] ?? '')) {
            $msg = '登录密码验证失败，删除操作未执行';
            $msg_type = 'error';
        } else {
            $project = get_project_for($conn, $pid, $current_teacher_id, 'manage');
            $can_del = $project && (intval($project['created_by']) === $current_teacher_id
                || (intval($project['class_id']) > 0
                    && (get_teacher_class_member_map($conn, $current_teacher_id)[intval($project['class_id'])] ?? '') === 'manage'));
            if ($can_del) {
                $stmt = mysqli_prepare($conn, "UPDATE projects SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
                mysqli_stmt_bind_param($stmt, "i", $pid);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $msg = '项目已移入回收站（登记数据保留），可在「回收站」恢复或彻底删除';
            }
        }
    }
    // ===== 保存公开查询设置（勾选哪些项目允许查询；仅可操作的项目生效） =====
    elseif ($action === 'save_query_settings') {
        $cls_id = intval($_POST['class_id'] ?? 0);
        $stmt = mysqli_prepare($conn, "SELECT id, teacher_id, school_id FROM classes WHERE id = ? AND deleted_at IS NULL");
        mysqli_stmt_bind_param($stmt, "i", $cls_id);
        mysqli_stmt_execute($stmt);
        $cls = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $can_toggle = $cls && (intval($cls['teacher_id']) === $current_teacher_id
            || in_array(get_teacher_class_member_map($conn, $current_teacher_id)[$cls_id] ?? '', ['manage'], true));
        if (!$can_toggle) {
            $msg = '无权限修改该班级的查询设置';
            $msg_type = 'error';
        } else {
            $checked = array_map('intval', (array)($_POST['qproj'] ?? []));
            // 候选项目与页面展示一致（覆盖该班级的全部项目）
            $pids = [];
            $res = mysqli_query($conn, "SELECT p.id FROM projects p
                                        WHERE p.deleted_at IS NULL AND p.class_id = {$cls_id}");
            while ($row = mysqli_fetch_assoc($res)) $pids[] = intval($row['id']);
            $changed = 0;
            foreach ($pids as $pid2) {
                $project = get_project_for($conn, $pid2, $current_teacher_id, 'operate');
                if (!$project) continue; // 无操作权限的项目不动（如他人创建的全校项目）
                $allow = in_array($pid2, $checked, true) ? 1 : 0;
                if (intval($project['allow_query'] ?? 1) !== $allow) $changed++;
                $stmt = mysqli_prepare($conn, "UPDATE projects SET allow_query = ? WHERE id = ?");
                mysqli_stmt_bind_param($stmt, "ii", $allow, $pid2);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            $msg = '查询设置已保存' . ($changed > 0 ? '（更新 ' . $changed . ' 个项目）' : '');
        }
    }
    // ===== 公开查询总开关（班级级，默认关闭；关闭时 query.php 提示未开启） =====
    elseif ($action === 'toggle_class_query') {
        $cls_id = intval($_POST['class_id'] ?? 0);
        $stmt = mysqli_prepare($conn, "SELECT id, teacher_id FROM classes WHERE id = ? AND deleted_at IS NULL");
        mysqli_stmt_bind_param($stmt, "i", $cls_id);
        mysqli_stmt_execute($stmt);
        $cls = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $can_toggle = $cls && (intval($cls['teacher_id']) === $current_teacher_id
            || in_array(get_teacher_class_member_map($conn, $current_teacher_id)[$cls_id] ?? '', ['manage'], true));
        if (!$can_toggle) {
            $msg = '无权限修改该班级的查询开关';
            $msg_type = 'error';
        } else {
            $enable = intval($_POST['enable'] ?? 0) === 1 ? 1 : 0;
            $stmt = mysqli_prepare($conn, "UPDATE classes SET query_enabled = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $enable, $cls_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = $enable ? '公开查询已开启，学生可通过查询链接查询登记信息' : '公开查询已关闭，学生访问查询页将提示未开启';
        }
    }
    // 回收站：恢复项目（含其登记数据）
    elseif ($action === 'project_restore') {
        $pid = intval($_POST['project_id'] ?? 0);
        if (can_recycle_project($conn, $current_teacher_id, $pid)) {
            $stmt = mysqli_prepare($conn, "UPDATE projects SET deleted_at = NULL WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $pid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = '项目已恢复（含其登记数据）';
        }
    }
    // 回收站：彻底删除项目（敏感操作：需验证登录密码；登记数据全部清除，不可恢复）
    elseif ($action === 'project_purge') {
        $pid = intval($_POST['project_id'] ?? 0);
        if (!verify_login_password($conn, $current_teacher_id, $_POST['login_pwd'] ?? '')) {
            $msg = '登录密码验证失败，彻底删除操作未执行';
            $msg_type = 'error';
        } elseif (can_recycle_project($conn, $current_teacher_id, $pid)) {
            $stmt = mysqli_prepare($conn, "DELETE FROM records WHERE project_id = ?");
            mysqli_stmt_bind_param($stmt, "i", $pid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, "DELETE FROM record_days WHERE project_id = ?");
            mysqli_stmt_bind_param($stmt, "i", $pid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            mysqli_query($conn, "DELETE FROM pinned_items WHERE item_type = 'project' AND item_id = " . intval($pid));

            $stmt = mysqli_prepare($conn, "DELETE FROM projects WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $pid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = '项目已彻底删除（登记数据一并清除）';
        }
    }
    // 导入历史登记数据（项目级：模板 4 列 编号,姓名,日期,评价值，仅限绑定了班级的项目）
    elseif ($action === 'import_history') {
        $pid = intval($_POST['project_id'] ?? 0);
        $project = get_project_for($conn, $pid, $current_teacher_id, 'manage');
        $content = read_upload_csv('import_file');
        $class = null;
        if ($project && intval($project['class_id']) > 0) {
            $stmt = mysqli_prepare($conn, "SELECT * FROM classes WHERE id = ? AND deleted_at IS NULL");
            mysqli_stmt_bind_param($stmt, "i", intval($project['class_id']));
            mysqli_stmt_execute($stmt);
            $class = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
        }
        if (!$project || intval($project['class_id']) <= 0) {
            $msg = '该项目未绑定班级，不支持导入历史数据（仅班级/班级学科项目支持）';
            $msg_type = 'error';
        } elseif (!$class) {
            $msg = '所属班级不存在或已删除，无法导入';
            $msg_type = 'error';
        } elseif ($content === null) {
            $msg = '请选择模板 CSV/TXT 文件上传（可在导入弹窗中下载模板）';
            $msg_type = 'error';
        } else {
            $r = import_history_csv($conn, $current_teacher_id, $class, $content, $project);
            $msg = $r['msg'];
            $msg_type = $r['ok'] ? 'success' : 'error';
        }
    }
    // 已办结：归档（项目管理级：创建人/管理员/本班 manage+create 成员；仅登记/仅查看无项目管理权；归档后隐藏于常规列表，在「已办结」选项卡可恢复）
    elseif ($action === 'project_archive') {
        $pid = intval($_POST['project_id'] ?? 0);
        $project = get_project_for($conn, $pid, $current_teacher_id, 'manage');
        if (!$project) {
            $msg = '无权限操作该项目或项目不存在';
            $msg_type = 'error';
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE projects SET archived_at = NOW() WHERE id = ? AND archived_at IS NULL");
            mysqli_stmt_bind_param($stmt, "i", $pid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = '项目已办结，已归档至「已办结」选项卡';
        }
    }
    // 已办结：恢复到常规列表
    elseif ($action === 'project_unarchive') {
        $pid = intval($_POST['project_id'] ?? 0);
        $project = get_project_for($conn, $pid, $current_teacher_id, 'manage'); // 恢复归档同为项目管理级操作
        if (!$project) {
            $msg = '无权限操作该项目或项目不存在';
            $msg_type = 'error';
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE projects SET archived_at = NULL WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $pid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = '项目已恢复到常规项目列表';
        }
    }
    // 项目置顶 / 取消置顶
    elseif ($action === 'toggle_pin_project') {
        $pid = intval($_POST['project_id'] ?? 0);
        $project = get_project_for($conn, $pid, $current_teacher_id, 'view');
        if ($project) {
            $pinned = toggle_pinned($conn, $current_teacher_id, 'project', $pid);
            $msg = $pinned ? '项目已置顶' : '已取消置顶';
        }
    }
    // 单项目公开查询开关（项目设置菜单：仅班级项目；manage 权限）
    elseif ($action === 'toggle_project_query') {
        $pid = intval($_POST['project_id'] ?? 0);
        $project = get_project_for($conn, $pid, $current_teacher_id, 'manage');
        if ($project && intval($project['class_id']) > 0) {
            $enable = intval($_POST['enable'] ?? 0) === 1 ? 1 : 0;
            $stmt = mysqli_prepare($conn, "UPDATE projects SET allow_query = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $enable, $pid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = $enable ? '该项目已允许公开查询' : '该项目已禁止公开查询';
        } else {
            $msg = '无权限修改该项目查询设置（仅班级项目且需管理权限）';
            $msg_type = 'error';
        }
    }
}

// PRG：POST 操作完成后立即重定向回当前页（提示经 flash 展示），避免浏览器刷新导致重复执行
// back 参数白名单：仅接受 project_view.php 的项目页地址（项目设置菜单编辑/置顶/查询开关后回到原项目页）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $msg !== '') {
    flash_set($msg, $msg_type);
    $back = strval($_POST['back'] ?? '');
    if ($back !== '' && preg_match('/^project_view\.php\?project_id=\d+(&cls_id=\d+)?(&date=\d{4}-\d{2}-\d{2})?$/', $back)) {
        header('Location: ' . $back);
    } else {
        header('Location: ' . $_SERVER['REQUEST_URI']);
    }
    exit();
}

// 项目列表（按角色可见，含登记统计）
$visible_projects = get_visible_projects($conn, $current_teacher_id);
// 拆分已办结项目（archived_at 非空）：常规列表不含，归入「已办结」选项卡
$archived_projects = [];
{
    $keep = [];
    foreach ($visible_projects as $p) {
        if (!empty($p['archived_at'])) $archived_projects[] = $p;
        else $keep[] = $p;
    }
    $visible_projects = $keep;
}
// 已办结项目首条登记时间（用于年/月嵌套分组）
$arch_first = [];
if ($archived_projects) {
    $ids = implode(',', array_map('intval', array_column($archived_projects, 'id')));
    $res = mysqli_query($conn, "SELECT project_id, MIN(registered_at) AS fr FROM records WHERE project_id IN ({$ids}) AND registered = 1 GROUP BY project_id");
    while ($row = mysqli_fetch_assoc($res)) $arch_first[intval($row['project_id'])] = strtotime(strval($row['fr']));
}
$pinned_project_ids = get_pinned_ids($conn, $current_teacher_id, 'project');
$member_map = get_teacher_class_member_map($conn, $current_teacher_id); // 班级授权成员权限（manage/create 可编辑卡片按钮用）

// 徽标显示兜底：项目引用了已禁用的系统模板或他人自定义模式时，按需补入展示映射
foreach ($visible_projects as $p) {
    $ek = strval($p['eval_mode']);
    if (!isset($eval_modes[$ek])) {
        $info = eval_mode_info($conn, $ek);
        if ($info) $eval_modes[$ek] = $info;
    }
}

// ===== 项目列表上下文：严格按当前上下文学校；平台（个人账号）上下文并入凭班级授权码加入班级的项目（可跨校） =====
$sid0 = intval(current_school_id($conn));
if ($sid0 === 0) {
    $have_ids = [];
    foreach ($visible_projects as $vp) $have_ids[intval($vp['id'])] = true;
    $mcls = array_map('intval', array_keys($member_map));
    if ($mcls) {
        $in = implode(',', $mcls);
        $res = mysqli_query($conn, "SELECT p.*, c.name AS class_name,
                (SELECT COUNT(*) FROM records r WHERE r.project_id = p.id AND r.registered = 1) AS registered_count
                FROM projects p
                LEFT JOIN classes c ON p.class_id = c.id
                WHERE p.class_id IN ({$in}) AND p.deleted_at IS NULL ORDER BY p.id DESC");
        while ($row = mysqli_fetch_assoc($res)) {
            if (!isset($have_ids[intval($row['id'])])) $visible_projects[] = $row;
        }
    }
}
// 归一到当前上下文（单用户版：无学校概念，名称留空）
$sname0 = '';
foreach ($visible_projects as &$vp) { $vp['school_id'] = $sid0; $vp['school_name'] = $sname0; }
unset($vp);

// ===== 筛选条件（未选择=显示全部；仅当涉及多个班级/学科时才显示对应下拉） =====
// class_id 上下文：从班级卡片进入 = 该班级的项目列表（新建项目固定本班，仅通用列表才需要选班级）
$f_class = intval($_GET['class_id'] ?? 0);
$f_class_name = '';
$f_class_row = null;
if ($f_class > 0) {
    $stmt = mysqli_prepare($conn, "SELECT id, name, class_code, teacher_id, school_id, query_enabled FROM classes WHERE id = ? AND deleted_at IS NULL");
    mysqli_stmt_bind_param($stmt, "i", $f_class);
    mysqli_stmt_execute($stmt);
    $fcrow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    // 越权防护：仅可见班级（本班/本校管理员/年段长等）才允许进入班级上下文，否则退回通用列表（不泄露他班名称与查询链接）
    if ($fcrow && can_view_class($conn, $current_teacher_id, $f_class)) {
        $f_class_row = $fcrow;
        $f_class_name = $fcrow['name'];
    } else {
        $f_class = 0; // 班级不存在（已删除或无权查看）：退回通用列表
    }
}
// ===== 「管理」二级菜单（学生名单 / 二维码 / 查看报表 / 导入历史数据；班级编辑、复制、重置、删除等在 classes.php 卡片「管理」菜单） =====
$cls_is_owner = false;      // 创建人：导入历史数据
$cls_can_manage = false;    // 编辑（重命名 / 年段；编辑弹窗已迁移，保留判断用于菜单显隐）
$cls_can_ann = false;       // 喊话权限（管理员 / 可建立 / 仅登记；仅查看不可喊话）
$cls_can_pts = false;       // 积分弹窗入口（全部班级授权成员均可查看积分数据；加减分/设规则等操作由后端按级别拒绝）
if ($f_class > 0 && $f_class_row) {
    $cls_is_owner = intval($f_class_row['teacher_id']) === $current_teacher_id;
    $cls_can_manage = can_manage_class($conn, $current_teacher_id, $f_class);
    $cls_can_ann = can_announce_class($conn, $current_teacher_id, $f_class);
    $cls_can_pts = can_view_class($conn, $current_teacher_id, $f_class);
}
// 班级上下文：学生数（0=尚无学生名单，项目列表上方提示先添加学生再登记）
$f_class_stu_cnt = 0;
if ($f_class > 0 && $f_class_row) {
    $res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM students WHERE class_id = " . intval($f_class));
    $f_class_stu_cnt = $res ? intval(mysqli_fetch_assoc($res)['c']) : 0;
}
// 当前上下文能否新建项目：班级上下文按「本班」判定（管理员/班主任/班级创建人/本班 manage+create 成员；仅登记/仅查看不可），
// 通用列表按全局 $creatable_scopes（名下任一可建范围）；用于「＋ 新建项目」「🗑 回收站」按钮与空态提示
$can_build_here = $f_class > 0
    ? ($f_class_row && can_create_project($conn, $current_teacher_id, 'class', $f_class))
    : !empty($creatable_scopes);

// 回收站数据（软删除项目：我创建的；班级上下文仅显示该班，按钮亦按本班建项目权限显隐）
$recycle_projects = [];
{
    $scope_cond = 'p.created_by = ' . intval($current_teacher_id);
    $class_cond = $f_class > 0 ? ' AND p.class_id = ' . intval($f_class) : '';
    $res = mysqli_query($conn, "SELECT p.*, c.name AS class_name,
            (SELECT COUNT(*) FROM records r WHERE r.project_id = p.id AND r.registered = 1) AS registered_count
            FROM projects p LEFT JOIN classes c ON p.class_id = c.id
            WHERE p.deleted_at IS NOT NULL AND ({$scope_cond}){$class_cond} ORDER BY p.deleted_at DESC");
    while ($row = mysqli_fetch_assoc($res)) $recycle_projects[] = $row;
}

// ===== 公开查询（需求：输入班级码+编号+姓名即可查询该生登记信息） =====
// 链接固定携带班级码，学生/家长打开后只需输入编号+姓名；「查询设置」控制哪些项目允许被查询（默认全部允许）
$query_url = '';
$can_toggle_query = false;
$query_projects = [];
$q_on = false; // 公开查询总开关（classes.query_enabled，默认关闭）
if ($f_class > 0 && $f_class_row) {
    $q_on = intval($f_class_row['query_enabled']) === 1;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    $query_url = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim($dir, '/') . '/query.php?c=' . rawurlencode(strval($f_class_row['class_code']));
    $can_toggle_query = intval($f_class_row['teacher_id']) === $current_teacher_id
        || ($member_map[$f_class] ?? '') === 'manage';   // 查询设置仅限班级所有者（学校管理员判定已移除）
    if ($can_toggle_query) {
        // 覆盖该班级的全部项目（本班项目），供勾选允许查询
        $res = mysqli_query($conn, "SELECT p.id, p.name, p.scope, p.allow_query, p.created_by FROM projects p
                                    WHERE p.deleted_at IS NULL AND p.class_id = {$f_class} ORDER BY p.id DESC");
        while ($row = mysqli_fetch_assoc($res)) {
            $row['operable'] = (bool)get_project_for($conn, intval($row['id']), $current_teacher_id, 'operate');
            $query_projects[] = $row;
        }
    }
}

$opt_classes = [];   // class_id => label
foreach ($visible_projects as $p) {
    if (intval($p['class_id']) > 0) {
        $label = ($p['class_name'] ?: ('班级#' . $p['class_id']));
        $opt_classes[intval($p['class_id'])] = $label;
    }
}
$show_class_dd = count($opt_classes) > 1;

$filtered_projects = array_values(array_filter($visible_projects, function ($p) use ($f_class) {
    if ($f_class > 0 && intval($p['class_id']) !== $f_class) return false;
    return true;
}));

// ===== 班级元数据（名称/创建者）：班级分组标题显示建立者 =====
$class_meta = []; // class_id => ['name'=>, 'teacher_id'=>, 'creator'=>, 'account'=>]
{
    $cids = [];
    foreach ($visible_projects as $p) if (intval($p['class_id']) > 0) $cids[intval($p['class_id'])] = true;
    if ($cids) {
        $in = implode(',', array_map('intval', array_keys($cids)));
        $res = mysqli_query($conn, "SELECT c.id, c.name, c.teacher_id, t.username, t.realname
                                    FROM classes c LEFT JOIN teachers t ON c.teacher_id = t.id WHERE c.id IN ({$in})");
        while ($row = mysqli_fetch_assoc($res)) {
            $class_meta[intval($row['id'])] = [
                'name' => $row['name'],
                'teacher_id' => intval($row['teacher_id']),
                'creator' => ($row['realname'] !== '' && $row['realname'] !== null) ? $row['realname'] : ($row['username'] ?: ('教师#' . intval($row['teacher_id']))),
                'account' => strval($row['username']),
            ];
        }
        mysqli_free_result($res);
    }
}

// ===== 二分：我的项目（我创建）/ 授权项目（其余可见项目；单用户版无管理员视角的「其他项目」） =====
$own_projects = [];
$authed_projects = [];
foreach ($filtered_projects as $p) {
    if (intval($p['created_by']) === $current_teacher_id) $own_projects[] = $p;
    else $authed_projects[] = $p;
}

// ===== 分组（每个选项卡独立分组）：全校 / 年段 / 班级顺序，组内置顶优先、其余按创建时间倒序 =====
// $with_owner=true 时班级分组标题带所有者账号+名字（其他项目选项卡用）
$group_project_cats = function ($list, $with_owner = false) use ($pinned_project_ids, $class_meta, $current_teacher_id) {
    $cats = []; // cat_key => ['label'=>, 'items'=>[], 'tier'=>, 'ord'=>]
    foreach ($list as $p) {
        if (intval($p['class_id']) > 0) {
            $cid0 = intval($p['class_id']);
            $cm = $class_meta[$cid0] ?? null;
            $cat_key = 'c' . $cid0;
            $cat_label = '班级：' . (($cm['name'] ?? '') ?: ($p['class_name'] ?: ('#' . $cid0)));
            $creator = $cm['creator'] ?? '';
            if ($with_owner) {
                // 其他项目：班级名后附所有者账号+名字
                if ($cm) {
                    $acct = $cm['account'];
                    $cat_label .= '（建立者：' . $creator . ($acct !== '' && $acct !== $creator ? ' ' . $acct : '') . '）';
                }
            } elseif ($creator && $cm && $cm['teacher_id'] !== $current_teacher_id) {
                $cat_label .= '（建立者：' . $creator . '）';
            }
            $tier = 2;
            $ord = $cid0;
        } else {
            $cat_key = 'all';
            $cat_label = '全校';
            $tier = 0;
            $ord = 0;
        }
        if (!isset($cats[$cat_key])) $cats[$cat_key] = ['label' => $cat_label, 'items' => [], 'tier' => $tier, 'ord' => $ord];
        $cats[$cat_key]['items'][] = $p;
    }
    uasort($cats, function ($a, $b) {
        if ($a['tier'] !== $b['tier']) return $a['tier'] - $b['tier'];
        return $a['ord'] - $b['ord'];
    });
    foreach ($cats as &$cat) {
        usort($cat['items'], function ($a, $b) use ($pinned_project_ids) {
            $pa = in_array(intval($a['id']), $pinned_project_ids, true) ? 0 : 1;
            $pb = in_array(intval($b['id']), $pinned_project_ids, true) ? 0 : 1;
            if ($pa !== $pb) return $pa - $pb;
            return $b['id'] - $a['id']; // 其余按创建时间倒序
        });
    }
    unset($cat);
    return $cats;
};
$cats_own = $group_project_cats($own_projects);
$cats_authed = $group_project_cats($authed_projects);

// 项目卡片渲染
// $cat：own=我创建的（蓝色）/ authed=授权项目（绿色+「授权的」标签）/ other=其他项目（灰色+「他人的」标签，仅管理员视角）
function render_project_card($p, $cat) {
    global $current_teacher_id, $conn, $member_map, $pinned_project_ids, $scope_names, $eval_modes, $reg_modes;
    $own_project = $cat === 'own';
    $m_perm = $member_map[intval($p['class_id'])] ?? ''; // 我在该项目所属班级的授权级别
    // 编辑/复制/导入：创建人、管理员，或班级授权 manage/create 级别成员（限本班项目）；创建人在本班仅为仅登记/仅查看授权时按矩阵无项目管理权（名下历史项目同样收紧）
    $m_low = intval($p['class_id']) > 0 && in_array($m_perm, ['register', 'view'], true);
    $is_creator = !$m_low && ($own_project
        || (intval($p['class_id']) > 0 && in_array($m_perm, ['manage', 'create'], true)));
    $can_delete = !$m_low && ($own_project || ($m_perm === 'manage' && intval($p['class_id']) > 0)); // 删除：创建人可删自己建立的；管理级别可删本班全部
    $pinned = in_array(intval($p['id']), $pinned_project_ids, true);
    $border = $pinned ? '#e67e22' : ($own_project ? '#667eea' : ($cat === 'authed' ? '#27ae60' : '#8e9aaf'));
    ?>
    <div class="item-card" style="border-left:4px solid <?php echo $border; ?>;cursor:pointer;" onclick="window.location.href='project_view.php?project_id=<?php echo $p['id']; ?>'">
        <h3>
            <?php if ($pinned): ?><span style="color:#e67e22;" title="已置顶">📌</span><?php endif; ?>
            <?php echo htmlspecialchars($p['name']); ?>
            <?php if ($cat === 'authed'): ?><span class="badge badge-grade" style="background:#27ae60;color:#fff;">授权的</span>
            <?php elseif ($cat === 'other'): ?><span class="badge badge-grade" style="background:#8e9aaf;color:#fff;">他人的</span><?php endif; ?>
        </h3>
        <span class="badge badge-scope" style="background:#667eea;"><?php echo $scope_names[$p['scope']] ?? $p['scope']; ?></span>
        <span class="badge <?php echo eval_badge_class($p['eval_mode']); ?>"><?php echo htmlspecialchars($eval_modes[$p['eval_mode']]['name'] ?? strval($p['eval_mode'])); ?></span>
        <?php $pmode = $p['mode'] ?? 'count'; ?><span class="badge badge-<?php echo isset($reg_modes[$pmode]) ? htmlspecialchars($pmode) : 'count'; ?>"><?php echo isset($reg_modes[$pmode]) ? htmlspecialchars(explode('（', $reg_modes[$pmode])[0]) : '单次'; ?></span>
        <div class="meta">
            <?php
            $scope_desc = '';
            if ($p['scope'] === 'school') $scope_desc = '全校';
            else $scope_desc = '班级：' . ($p['class_name'] ?: $p['class_id']);
            echo htmlspecialchars($scope_desc);
            ?>
        </div>
        <div class="meta">
            已登记 <?php echo $p['registered_count']; ?> 人 · 建于 <?php echo $p['created_at']; ?>
        </div>
        <div class="item-actions" onclick="event.stopPropagation()">
            <a href="project_view.php?project_id=<?php echo $p['id']; ?>" class="btn btn-sm btn-success">登记 / 查看</a>
            <?php
            // 仅查看成员：卡片不显示扫码/扫描入口（无扫描识别相关权限），「查看报表」提升为外显按钮
            $is_view_perm = intval($p['class_id']) > 0 && $m_perm === 'view';
            $rpt_cid = intval($p['class_id']);
            if ($rpt_cid <= 0) {
                $rcids = get_project_class_ids($conn, $p);
                $rpt_cid = intval($rcids[0] ?? 0);
            }
            ?>
            <?php if (!$is_view_perm): ?>
            <?php if (($p['mode'] ?? '') === 'omr'): ?>
            <a href="omr_scan.php?project_id=<?php echo $p['id']; ?>" class="btn btn-sm">扫描识别</a>
            <?php else: ?>
            <a href="scan.php?project_id=<?php echo $p['id']; ?>" class="btn btn-sm">扫码登记</a>
            <?php endif; ?>
            <?php elseif ($rpt_cid > 0): ?>
            <button type="button" class="btn btn-sm btn-outline" onclick="location.href='stats.php?class_id=<?php echo $rpt_cid; ?>&project_id=<?php echo $p['id']; ?>'">📊 查看报表</button>
            <?php endif; ?>
            <div class="admin-dd">
                <button type="button" class="btn btn-sm btn-outline" onclick="toggleDD(event,this)">⚙ 管理 ▾</button>
                <div class="admin-dd-menu">
                    <?php if ($is_creator): ?>
                    <?php if (($p['mode'] ?? '') === 'omr'): ?>
                    <a class="dd-item" href="omr_designer.php?project_id=<?php echo $p['id']; ?>" title="可视化设计答题卡布局，导出打印 PDF 并保存为识别模板">🎨 设计答题卡</a>
                    <?php endif; ?>
                    <button type="button" class="dd-item" onclick="doAction('project_archive', <?php echo $p['id']; ?>, '办结后项目将归档至「已办结」选项卡，不再显示在常规列表（可随时恢复），确认办结？')">🏁 办结</button>
                    <?php endif; ?>
                    <?php if ($rpt_cid > 0): ?>
                    <button type="button" class="dd-item" onclick="location.href='stats.php?class_id=<?php echo $rpt_cid; ?>&project_id=<?php echo $p['id']; ?>'">📊 查看报表</button>
                    <?php endif; ?>
                    <form method="post" autocomplete="off">
                        <input type="hidden" name="action" value="toggle_pin_project" autocomplete="off">
                        <input type="hidden" name="project_id" value="<?php echo $p['id']; ?>" autocomplete="off">
                        <button type="submit" class="dd-item" title="<?php echo $pinned ? '取消置顶' : '置顶该项目'; ?>">📌 <?php echo $pinned ? '取消置顶' : '置顶'; ?></button>
                    </form>
                    <?php if ($is_creator): ?>
                    <button type="button" class="dd-item" onclick="openEdit(<?php echo $p['id']; ?>, '<?php echo htmlspecialchars(addslashes($p['name'])); ?>', '<?php echo $p['eval_mode']; ?>', '<?php echo $p['mode'] ?? 'count'; ?>', <?php echo intval($p['late_seconds'] ?? 1800); ?>, <?php echo intval($p['lock_seconds'] ?? 1800); ?>)">✏️ 编辑</button>
                    <button type="button" class="dd-item" onclick="doAction('copy', <?php echo $p['id']; ?>, '确认复制该项目（同一班级/范围，不含登记数据）？')">📄 复制</button>
                    <?php if (intval($p['class_id']) > 0): ?>
                    <button type="button" class="dd-item" onclick="openImport(<?php echo $p['id']; ?>, '<?php echo htmlspecialchars(addslashes($p['name'])); ?>')">📥 导入历史数据</button>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($can_delete): ?>
                    <button type="button" class="dd-item dd-danger" onclick="pwdAction('delete', <?php echo $p['id']; ?>, '项目将移入回收站（登记数据保留），可在回收站恢复或彻底删除，确认？')">🗑 删除</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// 已办结选项卡的简化卡片：查看 + 恢复（管理菜单常规操作不适用；恢复=项目管理级，仅登记/仅查看成员不显示）
function render_archived_card($p, $first_ts) {
    global $current_teacher_id, $conn, $member_map;
    $m_perm = $member_map[intval($p['class_id'])] ?? ''; // 我在该项目所属班级的授权级别
    $m_low = intval($p['class_id']) > 0 && in_array($m_perm, ['register', 'view'], true);
    $can_unarchive = !$m_low && ((intval($p['created_by']) === intval($current_teacher_id) && !$m_low)
        || in_array($m_perm, ['manage', 'create'], true));
    $scope_desc = '';
    if ($p['scope'] === 'school') $scope_desc = '全校';
    else $scope_desc = '班级：' . ($p['class_name'] ?: $p['class_id']);
    ?>
    <div class="item-card" style="border-left:4px solid #27ae60;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
            <h4 style="margin:0;"><?php echo htmlspecialchars($p['name']); ?>
                <span class="badge badge-grade" style="background:#27ae60;color:#fff;">已办结</span>
            </h4>
            <div style="flex-shrink:0;">
                <a href="project_view.php?project_id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline" style="text-decoration:none;">查看</a>
                <?php if ($can_unarchive): ?>
                <button type="button" class="btn btn-sm" onclick="doAction('project_unarchive', <?php echo $p['id']; ?>, '确认恢复该项目到常规项目列表？')">↩ 恢复</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="meta"><?php echo htmlspecialchars($scope_desc); ?> · 已登记 <?php echo $p['registered_count']; ?> 人</div>
        <div class="meta">
            首次登记：<?php echo $first_ts > 0 ? htmlspecialchars(date('Y-m-d H:i', $first_ts)) : '无登记记录'; ?>
            　·　办结于 <?php echo htmlspecialchars(substr(strval($p['archived_at']), 0, 16)); ?>
        </div>
    </div>
    <?php
}

page_header('项目', 'projects.php');
?>
<?php if ($msg): ?><div class="alert alert-<?php echo $msg_type; ?>"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

<?php if ($f_class > 0): ?>
<!-- 班级上下文：返回按钮与 project_view.php「← 返回项目列表」一致（位置/样式） -->
<a href="classes.php" style="display:inline-block;margin-bottom:15px;color:#667eea;text-decoration:none;">← 返回班级列表</a>
<?php endif; ?>
　
    <?php if ($can_build_here): ?> <span class="form-hint" style="margin:0;">
    <button type="button" class="btn" onclick="openModal('addModal')">＋ 新建项目</button></span>
    <?php endif; ?>
<div class="panel" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
    <?php if ($f_class > 0 && $query_url && $can_toggle_query): ?>
    <!-- 查询链接入口按钮：点击弹窗呈现总开关、链接（可复制）与查询设置（仅管理员/所有者可见可设置） -->
    <button type="button" class="btn btn-outline" onclick="openModal('queryModal')">🔗 查询<?php //echo ($can_toggle_query && $query_projects) ? '／设置' : ''; ?><?php //echo $q_on ? '' : '（未开启）'; ?></button>
    <?php endif; ?>
    <?php if ($f_class > 0 && $cls_can_ann): ?>
    <!-- 班级喊话：弹窗含大屏客户端链接复制 / 语音播报 / 文字字幕（LED 跑马灯、强制显示、自动最小化） -->
    <button type="button" class="btn btn-outline" onclick="annOpen()">📣 喊话</button>
    <?php endif; ?>
    <?php if ($f_class > 0 && $cls_can_pts): ?>
    <!-- 课堂积分：弹窗含快速加减分 / 积分流水 / 汇总分析 / 积分项设置（仅查看成员只读，操作由后端拒绝） -->
    <button type="button" class="btn btn-outline" onclick="ptOpen()" title="课堂表现积分：快速给学生加减分">⭐ 积分</button>
    <?php endif; ?>
    <?php if ($f_class > 0 && ($cls_can_manage || $cls_is_owner)): ?>
    <!-- 管理：二级菜单（学生名单 / 二维码 / 查看报表 / 导入历史数据；班级编辑、复制、重置、删除等管理功能在班级管理页卡片「管理」菜单） -->
    <div class="admin-dd" style="display:inline-block;">
        <button type="button" class="btn btn-outline" onclick="toggleDD(event, this)">🏫 管理 ▾</button>
        <div class="admin-dd-menu">
            <a href="students.php?class_id=<?php echo $f_class; ?>">📋 学生名单</a>
            <a href="qrcode.php?class_id=<?php echo $f_class; ?>">🔗 二维码</a>
            <a href="stats.php?class_id=<?php echo $f_class; ?>">📊 查看报表</a>
            <?php if ($cls_is_owner): ?>
            <button type="button" class="dd-item" onclick="openModal('clsImportModal');">📥 导入历史数据</button>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($can_build_here): ?> <span class="form-hint" style="margin:0;">
    <button type="button" class="btn btn-outline" onclick="openModal('recycleModal')">🗑 回收站<?php echo $recycle_projects ? '（' . count($recycle_projects) . '）' : ''; ?></button></span>
    <?php endif; ?>
    <?php if (!$can_build_here): ?>
    <span class="form-hint" style="margin:0;"> <button type="button" class="hint-q" onclick="toggleHint(event, '<?php echo htmlspecialchars($f_class > 0
        ? '<b>无建项目权限</b><br>您在本班的授权级别为仅登记 / 仅查看：仅登记只可对已有项目进行登记操作，均无建立项目（含题次）的管理权限；统计、查看报表等数据查询不受影响。如需调整请联系班级创建者。'
        : ($can_create_project
        ? '<b>暂无可创建的项目</b><br>您可先创建班级，成为班主任后即可建立班级项目。'
        : '<b>暂无建立作业项目权限</b><br>「教师建立作业项目」功能已关闭，或您未被允许建项目，请联系管理员。')); ?>')">?</button></span>
    <?php endif; ?>
</div>

<?php if ($f_class === 0 && $show_class_dd): ?>
<div class="panel" style="padding:12px 20px;">
    <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin:0;" autocomplete="off">
        <div class="form-group" style="margin-bottom:0;min-width:170px;">
            <label>班级</label>
            <select name="class_id" class="form-control" onchange="this.form.submit()">
                <option value="0">全部班级</option>
                <?php foreach ($opt_classes as $cid => $clabel): ?>
                <option value="<?php echo $cid; ?>"<?php echo $f_class === $cid ? ' selected' : ''; ?>><?php echo htmlspecialchars($clabel); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin-bottom:0;">
            <a href="projects.php" class="btn btn-sm btn-outline" style="text-decoration:none;">清除筛选</a>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($f_class > 0): ?>
<!-- 班级上下文（从班级卡片/登记查看进入）：不显示选项卡与筛选，直接呈现该班级的项目 -->
<?php if ($f_class_stu_cnt === 0): ?>
<!-- 无学生名单提醒：有项目时也常驻项目卡片上方（「学生名单」为直达链接） -->
<div class="alert alert-info">该班级没有学生信息，请先点击上方「管理」-「<a href="students.php?class_id=<?php echo $f_class; ?>" style="color:#667eea;font-weight:bold;text-decoration:none;">学生名单</a>」添加学生信息！</div>
<?php endif; ?>
<?php if (empty($filtered_projects)): ?>
<div class="panel" style="text-align:center;color:#999;">该班级暂无项目<?php echo $can_build_here ? '，点击上方「＋ 新建项目」创建' : ''; ?></div>
<?php else: ?>
<?php
$cats_ctx = $group_project_cats($filtered_projects);
foreach ($cats_ctx as $cat) { ?>
<div class="scan-group-title scan-cat-title" style="border-left-color:#667eea;"><?php echo htmlspecialchars($cat['label']); ?>（<?php echo count($cat['items']); ?>）</div>
<div class="grid">
    <?php foreach ($cat['items'] as $p) render_project_card($p, intval($p['created_by']) === $current_teacher_id ? 'own' : 'authed'); ?>
</div>
<?php } ?>
<?php endif; ?>
<?php elseif (empty($visible_projects)): ?>
<div class="panel" style="text-align:center;color:#999;">暂无可见项目<?php echo $can_build_here ? '，点击上方「＋ 新建项目」创建' : '，可先创建班级后再建立项目'; ?></div>
<?php else: ?>
<?php
// 渲染一个选项卡面板内的分组列表（$accent：分组标题色；$empty_hint：空列表提示；$card_cat：卡片类别 own/authed/other）
$filter_active = false;
$render_project_cats = function ($cats, $accent, $empty_hint, $card_cat) use ($creatable_scopes) {
    if (empty($cats)) {
        $hint = $empty_hint;
        echo '<div class="panel" style="text-align:center;color:#999;">' . htmlspecialchars($hint) . '</div>';
        return;
    }
    foreach ($cats as $cat) { ?>
<div class="scan-group-title scan-cat-title" style="border-left-color:<?php echo $accent; ?>;"><?php echo htmlspecialchars($cat['label']); ?>（<?php echo count($cat['items']); ?>）</div>
<div class="grid">
    <?php foreach ($cat['items'] as $p) render_project_card($p, $card_cat); ?>
</div>
<?php
    }
};
?>
<?php
// 选项卡显隐与默认项：我的=0 且授权>0 → 默认显示授权项目；我的=0 且无建项目权限 → 隐藏「我的项目」选项卡
$can_create_project = !empty($creatable_scopes);
$show_own_tab = ($own_projects || $can_create_project);
$prj_default = 'own';
if (!$show_own_tab) {
    $prj_default = $authed_projects ? 'authed' : '';
} elseif (!$own_projects && $authed_projects) {
    $prj_default = 'authed';
}
?>
<?php if ($prj_default === ''): ?>
<!-- 无任何可见项目且无建项目权限：不渲染选项卡，仅提示 -->
<div class="panel" style="text-align:center;color:#999;">暂无可见项目，也暂无建项目权限</div>
<?php else: ?>
<!-- 两组选项卡：我的项目 / 授权项目 -->
<div class="panel" style="padding:10px 20px;">
    <div class="ctx-tabs">
        <?php if ($show_own_tab): ?>
        <button type="button" class="ctx-tab<?php echo $prj_default === 'own' ? ' active' : ''; ?>" onclick="switchCtxTab(this,'prj','own')">📙 我的项目（<?php echo count($own_projects); ?>）</button>
        <?php endif; ?>
        <?php if ($authed_projects): ?>
        <button type="button" class="ctx-tab<?php echo $prj_default === 'authed' ? ' active' : ''; ?>" onclick="switchCtxTab(this,'prj','authed')">🤝 授权项目（<?php echo count($authed_projects); ?>）</button>
        <?php endif; ?>
        <button type="button" class="ctx-tab" onclick="switchCtxTab(this,'prj','archived')">✅ 已办结（<?php echo count($archived_projects); ?>）</button>
    </div>
</div>

<?php if ($show_own_tab): ?>
<div class="ctx-tabpane" data-group="prj" data-key="own"<?php echo $prj_default === 'own' ? '' : ' style="display:none;"'; ?>>
    <?php $render_project_cats($cats_own, '#667eea', '还没有自己创建的项目' . ($creatable_scopes ? '，点击上方「＋ 新建项目」创建' : ''), 'own'); ?>
</div>
<?php endif; ?>

<?php if ($authed_projects): ?>
<div class="ctx-tabpane" data-group="prj" data-key="authed"<?php echo $prj_default === 'authed' ? '' : ' style="display:none;"'; ?>>
    <?php $render_project_cats($cats_authed, '#27ae60', '暂无授权项目', 'authed'); ?>
</div>
<?php endif; ?>

<?php if (true): ?>
<!-- 已办结选项卡：按班级分组；班级组内按首条登记时间的 年 → 月 嵌套分组逆序呈现 -->
<div class="ctx-tabpane" data-group="prj" data-key="archived" style="display:none;">
<?php if (empty($archived_projects)): ?>
    <div class="panel" style="text-align:center;color:#999;">暂无已办结项目；在项目的「⚙ 管理 → 🏁 办结」中可将项目归档至此</div>
<?php else: ?>
    <?php
    // 已办结项目涉及的班级名（批量查，含跨校场景）
    $arch_class_ids = array_values(array_unique(array_filter(array_map(function ($p) { return intval($p['class_id']); }, $archived_projects))));
    $arch_class_names = [];
    if ($arch_class_ids) {
        $in2 = implode(',', $arch_class_ids);
        $res = mysqli_query($conn, "SELECT id, name FROM classes WHERE id IN ({$in2})");
        while ($row = mysqli_fetch_assoc($res)) $arch_class_names[intval($row['id'])] = $row['name'];
    }
    // 按班级分组（class_id=0 的全校/年段/学科项目归入「未绑定班级」组，标题用范围名）
    $arch_groups = [];
    foreach ($archived_projects as $p) {
        $cid2 = intval($p['class_id']);
        if ($cid2 > 0) {
            $gkey = 'c' . $cid2;
            $gtitle = $arch_class_names[$cid2] ?? ('班级 #' . $cid2);
        } else {
            $gkey = 's' . strval($p['scope']);
            $gtitle = strval($scope_names[$p['scope']] ?? strval($p['scope'])) . '项目（未绑定班级）';
        }
        $fts = $arch_first[intval($p['id'])] ?? 0;
        $y = $fts > 0 ? intval(date('Y', $fts)) : 0;   // 0 = 无登记记录
        $m = $fts > 0 ? intval(date('n', $fts)) : 0;
        $arch_groups[$gkey]['title'] = $gtitle;
        $arch_groups[$gkey]['items'][$y][$m][] = $p;
    }
    foreach ($arch_groups as $g): ?>
    <div class="scan-group-title scan-cat-title" style="border-left-color:#27ae60;">🏁 <?php echo htmlspecialchars($g['title']); ?>（<?php echo array_sum(array_map('count', $g['items'])); ?>）</div>
        <?php
        $years = array_keys($g['items']);
        rsort($years); // 年逆序
        foreach ($years as $y):
            $months = array_keys($g['items'][$y]);
            rsort($months); // 月逆序
            if ($y === 0) { ?>
            <div style="margin:8px 0 4px;font-size:13px;color:#999;font-weight:bold;">未登记</div>
            <div class="grid">
                <?php foreach ($g['items'][0][0] as $p) render_archived_card($p, 0); ?>
            </div>
            <?php continue; }
            foreach ($months as $m): ?>
            <div style="margin:8px 0 4px;font-size:13px;color:#667eea;font-weight:bold;"><?php echo $y; ?> 年 <?php echo $m; ?> 月（<?php echo count($g['items'][$y][$m]); ?>）</div>
            <div class="grid">
                <?php foreach ($g['items'][$y][$m] as $p) render_archived_card($p, $arch_first[intval($p['id'])] ?? 0); ?>
            </div>
        <?php endforeach; endforeach; endforeach; // 月 → 年 → 班级组 ?>
<?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<!-- 新建项目弹层（90% 分布式） -->
<?php if ($can_build_here): ?>
<div class="modal-mask" id="addModal">
    <div class="modal modal-lg">
        <h3>＋ 新建项目</h3>
        <div class="modal-lg-body">
            <form method="post" id="addProjectForm" autocomplete="off">
                <input type="hidden" name="action" value="add" autocomplete="off">
                <div class="form-grid">
                    <input type="hidden" name="scope" value="class" autocomplete="off">
                    <div class="form-group" id="add_class_group">
                        <label>班级</label>
                        <?php if ($f_class > 0): ?>
                        <!-- 班级上下文：固定本班，不再下拉选择 -->
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($f_class_name); ?>" disabled autocomplete="off">
                        <input type="hidden" name="class_id" value="<?php echo $f_class; ?>" autocomplete="off">
                        <?php else: ?>
                        <select name="class_id" class="form-control">
                            <option value="0">请选择班级</option>
                            <?php foreach ($class_options as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                    <div class="form-group" style="grid-column:1/-1;">
                        <label>项目名称</label>
                        <input type="text" name="name" class="form-control" placeholder="例如：第3课生字抄写" required autocomplete="nope" readonly onfocus="this.removeAttribute('readonly')" title="点击即可输入（浏览器不会自动填充只读输入框）">
                    </div>
                    <div class="form-group">
                        <label>登记模式<button type="button" class="hint-q" onclick="toggleHint(event, '<b>登记模式说明</b><br>· <b>单次</b>=整个项目登记一次；<b>多次</b>=多个题次分别登记（如第一课、第二课…）<br>· <b>打卡</b>=每日打卡，按天记录与统计<br>· <b>举牌</b>=四选一（A/B/C/D）：学生举牌识别，评价模式固定为「答题」<br>· <b>答题卡</b>=多题涂卡拍照识别、自动批改得分，评价模式固定为「数值」<br>· 评价模式决定登记时可给的评价内容（笑脸/优良/星级/数值等）')">?</button></label>
                        <select name="reg_mode" id="add_reg_mode" class="form-control" onchange="syncEvalLock('add')">
                            <?php foreach ($reg_modes_add as $key => $label): ?>
                            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>评价模式<button type="button" class="hint-q" onclick="toggleHint(event, '<b>评价模式说明</b><br>· 决定登记时可给的评价内容（笑脸 / 十分 / 数值 / 优良 / 星级 / 评语 / 答题等）<br>· 登记「举牌」时固定为「答题」（A/B/C/D）不可改<br>· 登记「答题卡」时固定为「数值」（识别得分自动写入）')">?</button></label>
                        <select name="eval_mode" id="add_eval_mode" class="form-control">
                            <?php foreach ($eval_modes_enabled as $key => $mode): ?>
                            <option value="<?php echo $key; ?>"><?php echo $mode['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span id="add_eval_hint" style="display:none;font-size:12px;color:#e67e22;">登记为举牌时，评价模式固定为「答题」（A/B/C/D）</span>
                    </div>
                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                            <input type="checkbox" name="late_on" value="1" id="add_late_on" onchange="toggleThr('add', 'late')" autocomplete="off">
                            补登记阈值（首位登记后）<button type="button" class="hint-q" onclick="toggleHint(event, '<b>补登记阈值说明</b><br>· 默认关闭，勾选后可设置时长（天/时/分/秒）<br>· 本班首位登记超过阈值后的登记记为「补登记」（橙色标记，可筛选查看）<br>· 时长全为 0 视为关闭')">?</button>
                        </label>
                        <div id="add_late_box" style="display:none;">
                            <div style="display:flex;gap:4px;align-items:center;font-size:13px;margin-top:6px;">
                                <input type="number" name="late_d" class="form-control" style="width:56px;padding:4px 6px;" min="0" max="365" value="0" autocomplete="off">天
                                <input type="number" name="late_h" class="form-control" style="width:56px;padding:4px 6px;" min="0" max="23" value="0" autocomplete="off">时
                                <input type="number" name="late_m" class="form-control" style="width:56px;padding:4px 6px;" min="0" max="59" value="30" autocomplete="off">分
                                <input type="number" name="late_s" class="form-control" style="width:56px;padding:4px 6px;" min="0" max="59" value="0" autocomplete="off">秒
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                            <input type="checkbox" name="lock_on" value="1" id="add_lock_on" onchange="toggleThr('add', 'lock')" autocomplete="off">
                            登记后自动锁定<button type="button" class="hint-q" onclick="toggleHint(event, '<b>登记后自动锁定说明</b><br>· 默认关闭，勾选后可设置时长（天/时/分/秒）<br>· 学生登记超过锁定时长后其记录被锁定（禁止取消/修改点评）<br>· 时长全为 0 视为关闭')">?</button>
                        </label>
                        <div id="add_lock_box" style="display:none;">
                            <div style="display:flex;gap:4px;align-items:center;font-size:13px;margin-top:6px;">
                                <input type="number" name="lock_d" class="form-control" style="width:56px;padding:4px 6px;" min="0" max="365" value="0" autocomplete="off">天
                                <input type="number" name="lock_h" class="form-control" style="width:56px;padding:4px 6px;" min="0" max="23" value="0" autocomplete="off">时
                                <input type="number" name="lock_m" class="form-control" style="width:56px;padding:4px 6px;" min="0" max="59" value="30" autocomplete="off">分
                                <input type="number" name="lock_s" class="form-control" style="width:56px;padding:4px 6px;" min="0" max="59" value="0" autocomplete="off">秒
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-outline" onclick="closeModal('addModal')">取消</button>
            <button type="submit" class="btn" form="addProjectForm">创建项目</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 编辑项目弹层 -->
<div class="modal-mask" id="editModal">
    <div class="modal">
        <h3>编辑项目</h3>
        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="rename" autocomplete="off">
            <input type="hidden" name="project_id" id="edit_id" autocomplete="off">
            <div class="form-group">
                <label>项目名称</label>
                <input type="text" name="name" id="edit_name" class="form-control" required autocomplete="nope" readonly onfocus="this.removeAttribute('readonly')" title="点击即可输入（浏览器不会自动填充只读输入框）">
            </div>
            <div class="form-group">
                <label>登记模式</label>
                <select name="reg_mode" id="edit_reg_mode" class="form-control" onchange="syncEvalLock('edit')">
                    <?php foreach ($reg_modes as $key => $label): ?>
                    <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>评价模式</label>
                <select name="eval_mode" id="edit_mode" class="form-control">
                    <?php foreach ($eval_modes_enabled as $key => $mode): ?>
                    <option value="<?php echo $key; ?>"><?php echo $mode['name']; ?></option>
                    <?php endforeach; ?>
                </select>
                <span id="edit_eval_hint" style="display:none;font-size:12px;color:#e67e22;">登记为举牌时，评价模式固定为「答题」（A/B/C/D）</span>
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                    <input type="checkbox" name="late_on" value="1" id="edit_late_on" onchange="toggleThr('edit', 'late')" autocomplete="off">
                    补登记阈值（本班首位登记后多久算补登记）
                </label>
                <div id="edit_late_box" style="display:none;">
                    <div style="display:flex;gap:6px;align-items:center;font-size:13px;margin-top:6px;">
                        <input type="number" name="late_d" id="edit_late_d" class="form-control" style="width:60px;" min="0" max="365" autocomplete="off">天
                        <input type="number" name="late_h" id="edit_late_h" class="form-control" style="width:60px;" min="0" max="23" autocomplete="off">时
                        <input type="number" name="late_m" id="edit_late_m" class="form-control" style="width:60px;" min="0" max="59" autocomplete="off">分
                        <input type="number" name="late_s" id="edit_late_s" class="form-control" style="width:60px;" min="0" max="59" autocomplete="off">秒
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                    <input type="checkbox" name="lock_on" value="1" id="edit_lock_on" onchange="toggleThr('edit', 'lock')" autocomplete="off">
                    登记后自动锁定（超时禁止取消/修改点评）
                </label>
                <div id="edit_lock_box" style="display:none;">
                    <div style="display:flex;gap:6px;align-items:center;font-size:13px;margin-top:6px;">
                        <input type="number" name="lock_d" id="edit_lock_d" class="form-control" style="width:60px;" min="0" max="365" autocomplete="off">天
                        <input type="number" name="lock_h" id="edit_lock_h" class="form-control" style="width:60px;" min="0" max="23" autocomplete="off">时
                        <input type="number" name="lock_m" id="edit_lock_m" class="form-control" style="width:60px;" min="0" max="59" autocomplete="off">分
                        <input type="number" name="lock_s" id="edit_lock_s" class="form-control" style="width:60px;" min="0" max="59" autocomplete="off">秒
                    </div>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeEdit()">取消</button>
                <button type="submit" class="btn">保存</button>
            </div>
        </form>
    </div>
</div>

<!-- 导入历史数据弹层（共享：项目级 4 列模板，仅登记到本项目） -->
<div class="modal-mask" id="importModal">
    <div class="modal">
        <h3>📥 导入历史登记数据 - <span id="import_name"></span><button type="button" class="hint-q" onclick="toggleHint(event, '<b>导入历史登记数据说明</b><br>· 点「⬇ 下载模板」获取 CSV 模板，列顺序：<b>编号,姓名,日期,评价值</b><br>· 评价值须符合本项目评价模式（留空=仅登记不评价）；日期留空=按当前时间；打卡项目日期必填<br>· 按编号/姓名匹配学生（须在本项目覆盖班级名单中）；已有登记自动跳过（可重复导入）')">?</button></h3>
        <div style="margin:12px 0;">
            <a id="import_tpl" class="btn btn-outline" style="text-decoration:none;" href="projects.php">⬇ 下载模板</a>
        </div>
        <form method="post" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="action" value="import_history" autocomplete="off">
            <input type="hidden" name="project_id" id="import_id" autocomplete="off">
            <div class="form-group">
                <label>选择 CSV/TXT 文件（≤5MB，UTF-8 或 GBK 编码）</label>
                <input type="file" name="import_file" accept=".csv,.txt" class="form-control" required autocomplete="off">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeModal('importModal')">取消</button>
                <button type="submit" class="btn btn-success">开始导入</button>
            </div>
        </form>
    </div>
</div>

<!-- 回收站弹层（软删除项目：恢复 / 彻底删除） -->
<div class="modal-mask" id="recycleModal">
    <div class="modal" style="max-width:760px;">
        <h3>🗑 项目回收站（<?php echo count($recycle_projects); ?>）<button type="button" class="hint-q" onclick="toggleHint(event, '<b>项目回收站说明</b><br>· 软删除的项目及其登记数据在此保留<br>· <b>恢复</b>=还原项目与登记数据<br>· <b>彻底删除</b>=登记数据全部清除，<b>不可恢复</b>（需输入登录密码确认）')">?</button></h3>
        <?php if ($recycle_projects): ?>
        <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>项目</th><th>班级</th><th>已登记</th><th>删除时间</th><th style="width:220px;">操作</th></tr></thead>
            <tbody>
                <?php foreach ($recycle_projects as $rp): ?>
                <tr>
                    <td><?php echo htmlspecialchars($rp['name']); ?></td>
                    <td><?php echo htmlspecialchars($rp['class_name'] ?: '—'); ?></td>
                    <td><?php echo intval($rp['registered_count']); ?></td>
                    <td><?php echo htmlspecialchars($rp['deleted_at']); ?></td>
                    <td>
                        <form method="post" style="display:inline;" autocomplete="off">
                            <input type="hidden" name="action" value="project_restore" autocomplete="off">
                            <input type="hidden" name="project_id" value="<?php echo intval($rp['id']); ?>" autocomplete="off">
                            <button type="submit" class="btn btn-sm btn-success">恢复</button>
                        </form>
                        <form method="post" style="display:inline;" autocomplete="off">
                            <input type="hidden" name="action" value="project_purge" autocomplete="off">
                            <input type="hidden" name="project_id" value="<?php echo intval($rp['id']); ?>" autocomplete="off">
                            <button type="button" class="btn btn-sm btn-danger" onclick="recyclePurge('project_purge', <?php echo intval($rp['id']); ?>, '彻底删除该项目？其全部登记数据将清除且不可恢复！')">彻底删除</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
        <p class="tip">回收站为空</p>
        <?php endif; ?>
        <div class="modal-actions">
            <button type="button" class="btn btn-outline" onclick="closeModal('recycleModal')">关闭</button>
        </div>
    </div>
</div>

<?php if ($f_class > 0 && $query_url): ?>
<!-- 公开查询弹窗：链接复制 + 查询设置（哪些项目允许被查询） -->
<div class="modal-mask" id="queryModal">
    <div class="modal" style="max-width:640px;">
        <h3>🔗 公开查询</h3>
        <?php if ($can_toggle_query): ?>
        <!-- 总开关（班级级，默认关闭）：关闭时 query.php 提示未开启 -->
        <form method="post" style="margin:0 0 10px;" autocomplete="off">
            <input type="hidden" name="action" value="toggle_class_query" autocomplete="off">
            <input type="hidden" name="class_id" value="<?php echo $f_class; ?>" autocomplete="off">
            <label style="display:inline-flex;align-items:center;gap:8px;font-size:14px;cursor:pointer;">
                <input type="checkbox" name="enable" value="1"<?php echo $q_on ? ' checked' : ''; ?> onchange="this.form.submit()" autocomplete="off">
                <b>开启公开查询</b><button type="button" class="hint-q" onclick="toggleHint(event, '<b>公开查询说明</b><br>· 班级级总开关，默认关闭<br>· 学生/家长打开链接后输入「编号+姓名」查询登记信息<br>· 关闭后学生访问查询页会提示「未开启公开查询」<br>· 下方勾选控制哪些项目允许被查询（默认全部允许）')">?</button>
            </label>
        </form>
        <?php endif; ?>
        <?php if ($q_on): ?>
        <p class="tip">学生/家长打开链接后输入<b>编号+姓名</b>即可查询该生的全部登记信息；下方勾选控制哪些项目允许被查询（默认全部允许）。</p>
        <div class="form-group">
            <label>查询链接（点击输入框可全选）</label>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="text" class="form-control" id="query_link" value="<?php echo htmlspecialchars($query_url); ?>" readonly onclick="this.select()" style="flex:1;min-width:220px;font-family:Consolas,monospace;font-size:13px;" autocomplete="nope">
                <button type="button" class="btn btn-sm" onclick="copyQueryLink()">📋 复制链接</button>
                <a href="<?php echo htmlspecialchars($query_url); ?>" target="_blank" class="btn btn-sm btn-outline" style="text-decoration:none;">预览查询页</a>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-info">未开启公开查询：开启后可在此复制查询链接，学生才能访问查询页查询登记信息。</div>
        <?php endif; ?>
        <?php if ($can_toggle_query && $query_projects): ?>
        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="save_query_settings" autocomplete="off">
            <input type="hidden" name="class_id" value="<?php echo $f_class; ?>" autocomplete="off">
            <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th style="width:80px;">允许查询</th><th>项目</th><th>范围</th></tr></thead>
                <tbody>
                    <?php foreach ($query_projects as $qp): $op = !empty($qp['operable']); ?>
                    <tr>
                        <td><input type="checkbox" name="qproj[]" value="<?php echo intval($qp['id']); ?>"<?php echo intval($qp['allow_query']) === 1 ? ' checked' : ''; ?><?php echo $op ? '' : ' disabled'; ?> autocomplete="off"></td>
                        <td><?php echo htmlspecialchars($qp['name']); ?><?php echo $op ? '' : ' <span class="form-hint">（无操作权限，不可修改）</span>'; ?></td>
                        <td><span class="form-hint"><?php echo htmlspecialchars($scope_names[$qp['scope']] ?? strval($qp['scope'])); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div class="modal-actions">
                <button type="submit" class="btn">保存设置</button>
                <button type="button" class="btn btn-outline" onclick="closeModal('queryModal')">关闭</button>
            </div>
        </form>
        <?php else: ?>
        <div class="modal-actions">
            <button type="button" class="btn btn-outline" onclick="closeModal('queryModal')">关闭</button>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($f_class > 0 && $cls_can_ann): ?>
<!-- 班级喊话弹窗（includes/announce_modal.php 共用：projects.php / project_view.php） -->
<?php $ann_class_id = $f_class; include __DIR__ . '/includes/announce_modal.php'; ?>
<?php endif; ?>
<?php if ($f_class > 0 && $cls_can_pts): ?>
<!-- 课堂积分弹窗（includes/points_modal.php 共用：projects.php / project_view.php） -->
<?php $pt_class_id = $f_class; $pt_class_name = $f_class_name; $pt_readonly = (get_class_perm_level($conn, $current_teacher_id, $f_class) === 'view'); $pt_cfg = in_array(get_class_perm_level($conn, $current_teacher_id, $f_class), ['manage', 'create'], true); include __DIR__ . '/includes/points_modal.php'; ?>
<?php endif; ?>
<script src="assets/js/echarts.min.js"></script><script src="assets/js/chart_common.js?v=3"></script>
<?php if ($f_class > 0 && $cls_can_ann): ?>
<!-- 教师在线心跳 + 反向喊话反馈弹窗：打开本页即算教师在线；收到学生回复弹窗提示（点确认关闭，5 秒未确认自动关闭） -->
<script src="assets/js/rev_notify.js?v=1"></script>
<script>revNotify(<?php echo intval($f_class); ?>, 'dialog');</script>
<?php endif; ?>

<form method="post" id="actionForm" autocomplete="off">
    <input type="hidden" name="action" id="act_action" autocomplete="off">
    <input type="hidden" name="project_id" id="act_id" autocomplete="off">
    <input type="hidden" name="login_pwd" id="act_pwd" autocomplete="off">
</form>

<!-- 敏感操作密码确认弹层（项目删除 / 回收站彻底删除共用） -->
<div class="modal-mask" id="pwdModal">
    <div class="modal" style="max-width:420px;">
        <h3>🔒 敏感操作确认</h3>
        <p class="tip" style="margin-top:0;">敏感操作确认<button type="button" class="hint-q" onclick="toggleHint(event, '<b>敏感操作确认</b><br>· 该操作为敏感操作，需输入您的登录密码确认后才会执行')">?</button></p>
        <div class="form-group">
            <label>登录密码</label>
            <input type="password" id="pwd_input" class="form-control" placeholder="输入当前账号的登录密码" onkeydown="if(event.key==='Enter'){event.preventDefault();submitPwd();}" autocomplete="off">
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-outline" onclick="closeModal('pwdModal')">取消</button>
            <button type="button" class="btn btn-danger" onclick="submitPwd()">确认执行</button>
        </div>
    </div>
</div>

<?php if ($cls_is_owner): ?>
<!-- 导入历史登记数据弹层（POST classes.php import_history；模板缺失项目自动创建） -->
<div class="modal-mask" id="clsImportModal">
    <div class="modal">
        <h3>📥 导入历史登记数据</h3>
        <form method="post" action="classes.php" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="ret" value="projects" autocomplete="off">
            <input type="hidden" name="action" value="import_history" autocomplete="off">
            <input type="hidden" name="class_id" value="<?php echo $f_class; ?>" autocomplete="off">
            <p class="tip" style="margin-top:0;">先下载模板填写，再上传文件<button type="button" class="hint-q" onclick="toggleHint(event, '<b>导入步骤</b><br>· 先下载模板并按格式填写<br>· 模板含「项目名称」列，缺失项目自动创建<br>· 再上传 CSV/TXT 文件')">?</button></p>
            <p><a href="classes.php?dl_template=class&class_id=<?php echo $f_class; ?>">⬇️ 下载导入模板</a></p>
            <div class="form-group">
                <label>模板数据文件</label>
                <input type="file" name="import_file" class="form-control" accept=".csv,.txt" required autocomplete="off">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeModal('clsImportModal')">取消</button>
                <button type="submit" class="btn">开始导入</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function openModal(id) {
    document.getElementById(id).classList.add('show');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}
<?php if ($f_class > 0 && ($cls_can_manage || $cls_is_owner)): ?>
// 「管理」二级菜单开关 toggleDD 已全局化（includes/layout.php）
<?php endif; ?>
// 敏感操作（项目删除 / 回收站彻底删除）：先 confirm 说明风险，再要求输入登录密码（服务端二次验证）
var pwdCtx = null;
function pwdAction(action, id, confirmText) {
    if (!confirm(confirmText)) return;
    pwdCtx = { action: action, id: id };
    document.getElementById('pwd_input').value = '';
    openModal('pwdModal');
    setTimeout(function () { document.getElementById('pwd_input').focus(); }, 120);
}
function recyclePurge(action, id, confirmText) {
    pwdAction(action, id, confirmText);
}
function submitPwd() {
    var pwd = document.getElementById('pwd_input').value;
    if (!pwd) { showToast('请输入登录密码', 'error'); return; }
    if (!pwdCtx) return;
    document.getElementById('act_action').value = pwdCtx.action;
    document.getElementById('act_id').value = pwdCtx.id;
    document.getElementById('act_pwd').value = pwd;
    pwdCtx = null;
    closeModal('pwdModal');
    document.getElementById('actionForm').submit();
}
// 复制公开查询链接（优先剪贴板 API，失败回退 execCommand）
function copyQueryLink() {
    var inp = document.getElementById('query_link');
    var url = inp.value;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function () { showToast('查询链接已复制', 'success', true); });
    } else {
        inp.select();
        document.execCommand('copy');
        showToast('查询链接已复制', 'success', true);
    }
}
// 点击遮罩空白处关闭（喊话弹窗除外：内容多、避免误点丢失已编辑内容；课堂积分弹窗除外：操作密集，避免误点丢失面板）
document.querySelectorAll('.modal-mask').forEach(function (m) {
    m.addEventListener('click', function (e) { if (e.target === m && m.id !== 'annModal' && m.id !== 'ptModal') m.classList.remove('show'); });
});
function onAddScopeChange() {
    // 个人模式无范围选择（add_scope 不存在，固定班级项目：只显示班级组）
    var sel = document.getElementById('add_scope');
    var scope = sel ? sel.value : 'class';
    var c = document.getElementById('add_class_group');
    if (c) c.style.display = scope === 'class' ? '' : 'none';
}
onAddScopeChange();

// 补登记阈值 / 自动锁定：勾选开关切换时长设置显隐（默认关闭；未勾选=该功能不启用）
function toggleThr(ctx, prefix) {
    var on = document.getElementById(ctx + '_' + prefix + '_on').checked;
    document.getElementById(ctx + '_' + prefix + '_box').style.display = on ? '' : 'none';
}
// 登记模式联动评价模式：选「举牌」强制评价模式=「答题」、选「答题卡」强制=「数值」并锁定；其他登记模式释放可随意调整
function syncEvalLock(ctx) {
    var reg = document.getElementById(ctx + '_reg_mode');
    var ev = document.getElementById(ctx + (ctx === 'edit' ? '_mode' : '_eval_mode'));
    if (!reg || !ev) return;
    var locked = reg.value === 'raise' || reg.value === 'omr';
    if (reg.value === 'omr') ev.value = 'score';
    else if (locked) ev.value = 'abcd';
    ev.disabled = locked;
    var hint = document.getElementById(ctx + '_eval_hint');
    if (hint) {
        hint.textContent = reg.value === 'omr'
            ? '登记为答题卡时，评价模式固定为「数值」（识别得分自动写入，不可更改）'
            : '登记为举牌时，评价模式固定为「答题」（A/B/C/D）';
        hint.style.display = locked ? '' : 'none';
    }
}
syncEvalLock('add');   // 新增弹层初始状态同步（默认单次：评价模式可自由调整）
function openEdit(id, name, mode, regMode, lateSec, lockSec) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_mode').value = mode;
    document.getElementById('edit_reg_mode').value = regMode || 'count';
    // 举牌/答题卡分别开关：对应登记模式选项禁用（原本就是该模式的项目保持可选，避免显示为空）
    var raiseOff = <?php echo $raise_enabled ? 'false' : 'true'; ?>;
    var omrOff = <?php echo $omr_enabled ? 'false' : 'true'; ?>;
    [['raise', raiseOff], ['omr', omrOff]].forEach(function (it) {
        var opt = document.querySelector('#edit_reg_mode option[value="' + it[0] + '"]');
        if (opt) opt.disabled = it[1] && regMode !== it[0];
    });
    syncEvalLock('edit');   // 举牌项目：评价模式锁定为「答题」
    function setHMS(prefix, sec) {
        sec = Math.max(0, parseInt(sec || 0, 10));
        var on = sec > 0;   // 已设置时长（>0）自动勾选并展开；0=关闭
        document.getElementById('edit_' + prefix + '_on').checked = on;
        document.getElementById('edit_' + prefix + '_box').style.display = on ? '' : 'none';
        document.getElementById('edit_' + prefix + '_d').value = Math.floor(sec / 86400);
        document.getElementById('edit_' + prefix + '_h').value = Math.floor((sec % 86400) / 3600);
        document.getElementById('edit_' + prefix + '_m').value = Math.floor((sec % 3600) / 60);
        document.getElementById('edit_' + prefix + '_s').value = sec % 60;
    }
    setHMS('late', lateSec);
    setHMS('lock', lockSec);
    document.getElementById('editModal').classList.add('show');
}
function closeEdit() {
    document.getElementById('editModal').classList.remove('show');
}
function doAction(action, id, confirmText) {
    if (!confirm(confirmText)) return;
    document.getElementById('act_action').value = action;
    document.getElementById('act_id').value = id;
    document.getElementById('actionForm').submit();
}
// 卡片「管理」二级菜单开关 toggleDD 与外点收起已全局化（includes/layout.php）
function openImport(id, name) {
    document.getElementById('import_id').value = id;
    document.getElementById('import_name').textContent = name;
    document.getElementById('import_tpl').href = 'projects.php?dl_template=project&project_id=' + id;
    openModal('importModal');
}
</script>
<?php page_help('项目列表', [
    ['h' => '页面结构', 'items' => [
        '项目分三个选项卡显示：<b>我的项目</b>（我创建的）、<b>授权项目</b>（我被授权的，绿色标识）、<b>其他项目</b>（仅管理员可见，按班级分组）',
        '项目按 全校 → 年段 → 班级 分组；📌 置顶的排在最前（仅对本人生效）',
        '从班级进入时（班级上下文）直接显示该班项目，新建项目自动归属该班级',
    ]],
    ['h' => '新建项目', 'items' => [
        '<b>＋ 新建项目</b>：选择范围（班级 / 年段 / 全校 / 学科，按权限显示）、登记模式、评价模式（笑脸 / 十分 / 数值 / 优良 / 星级 / 评语 / 答题 / 自定义模式）；<b>登记模式在上</b>，选「举牌」时评价模式自动固定为「答题」（A/B/C/D）不可改，选「答题卡」时评价模式固定为「数值」不可改，选其他登记模式则释放',
        '<b>登记模式</b>：<b>单次（仅登记一次）</b>登记一次即完成；<b>多次</b>按「项次」登记，可自行增减项次；<b>打卡</b>每天可打卡一次（记录打卡日期）；<b>举牌</b>每生一张黑白图案举牌卡，把所选答案边（A/B/C/D）转到正上方即可识别；<b>答题卡</b>按「题次」多题涂卡识别（先设计答题卡模板再拍照/上传识别，得分自动写入）',
        '<b>补登记阈值 / 登记后自动锁定</b>：默认关闭，勾选后设置时长（天/时/分/秒）；本班首位登记超过阈值后的登记记为「补登记」，学生登记超过锁定时长后记录被锁定（禁止取消/修改点评）；勾选后时长全为 0 同样视为关闭',
    ]],
    ['h' => '工具栏弹窗（班级上下文）', 'items' => [
        '<b>📣 喊话</b>：班级喊话弹窗——快捷播报（全班）/ 选人播报 / 图片发送 / 功能设置选项卡，客户端链接在右上「🔗 客户端链接」弹窗内复制，可勾选「需反馈」让班级端确认收到后才开始自动最小化，底部可开启班级端反向喊话教师端',
        '<b>⭐ 积分</b>：课堂积分弹窗——快速加减分、抽选学生、积分流水与汇总分析、积分项设置',
        '<b>🔗 查询</b>：公开查询弹窗——班级级总开关 + 查询链接复制 + 按项目允许查询',
    ]],
    ['h' => '卡片操作（卡片右上角 ▾）', 'items' => [
        '<b>进入登记</b>：打开项目操作页点序号登记；<b>扫码登记</b>：用摄像头扫学生二维码登记',
        '<b>答题卡类型项目</b>在列表上的按钮是「扫描识别」（进 omr_scan.php 拍照/上传识别涂卡），其他类型是「扫码登记」（进 scan.php 扫学生二维码）',
        '<b>查看报表</b>：进入该项目的登记统计；<b>查询链接</b>：设置学生 / 家长公开查询（班级级总开关 + 按项目允许）',
        '<b>编辑 / 置顶 / 办结</b>：编辑项目设置；办结 = 归档隐藏到「已办结」选项卡，可随时恢复',
        '<b>删除</b>：软删除进回收站（登记数据保留可恢复）；彻底删除在回收站中操作，不可恢复',
    ]],
]);
page_footer(); ?>
