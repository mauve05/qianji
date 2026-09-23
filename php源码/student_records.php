<?php
/**
 * 学生个人登记情况：详细记录该学生每次登记和评价情况
 */
require_once 'includes/db_config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/layout.php';

$conn = getConnection();

$student_id = intval($_GET['student_id'] ?? 0);

// 校验学生归属（查看权限：学生所在班级对当前教师可见）
$stmt = mysqli_prepare($conn, "SELECT s.*, c.name AS class_name, c.id AS class_id, c.teacher_id
                               FROM students s INNER JOIN classes c ON s.class_id = c.id
                               WHERE s.id = ?");
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$student || !can_view_class($conn, $current_teacher_id, intval($student['class_id']))) {
    header("Location: classes.php");
    exit();
}

// 该学生所有项目的登记记录（含覆盖该班级的全校/年段/学科项目）
$records = [];
{
    $stmt = mysqli_prepare($conn, "SELECT p.id, p.name, p.eval_mode, p.class_id, p.scope,
                                           r.registered, r.registered_at, r.registered_by, r.eval_value, r.eval_at
                                   FROM projects p
                                   LEFT JOIN records r ON r.project_id = p.id AND r.student_id = ?
                                   WHERE p.deleted_at IS NULL
                                   ORDER BY p.id DESC");
    mysqli_stmt_bind_param($stmt, "i", $student_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $student_class_id = intval($student['class_id']);
    while ($row = mysqli_fetch_assoc($res)) {
        if (in_array($student_class_id, get_project_class_ids($conn, $row), true)) {
            $records[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// 简单统计
$stat = ['registered' => 0, 'evaluated' => 0, 'total' => 0];
foreach ($records as $r) {
    $stat['total']++;
    if ($r['registered'] == 1) $stat['registered']++;
    if ($r['eval_value'] !== null && $r['eval_value'] !== '') $stat['evaluated']++;
}

page_header('学生登记情况 - ' . $student['name'], 'classes.php');
?>
<a href="javascript:history.back();" style="display:inline-block;margin-bottom:15px;color:#667eea;text-decoration:none;">← 返回</a>

<div class="stat-bar">
    <div class="stat-box"><div class="num"><?php echo $stat['total']; ?></div><div class="label">项目总数</div></div>
    <div class="stat-box"><div class="num green"><?php echo $stat['registered']; ?></div><div class="label">已登记次数</div></div>
    <div class="stat-box"><div class="num orange"><?php echo $stat['evaluated']; ?></div><div class="label">已评价次数</div></div>
</div>

<div class="panel">
    <h3><?php echo htmlspecialchars($student['name']); ?>
        <span style="font-size:13px;color:#999;font-weight:normal;margin-left:10px;">
            <?php echo htmlspecialchars($student['class_name']); ?> · 座号 <?php echo htmlspecialchars($student['seat_no']); ?> · 编号 <?php echo htmlspecialchars($student['student_no']); ?>
            <?php if ($student['remark']): ?> · 备注：<?php echo htmlspecialchars($student['remark']); endif; ?>
        </span>
    </h3>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>项目</th><th>登记状态</th><th>登记时间</th><th>登记方式</th><th>评价</th><th>评价时间</th></tr>
            </thead>
            <tbody>
                <?php $has = false; foreach ($records as $r): $has = true; ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['name']); ?></td>
                    <td><?php echo $r['registered'] == 1 ? '<span style="color:#27ae60;">✓ 已登记</span>' : '<span style="color:#999;">未登记</span>'; ?></td>
                    <td><?php echo $r['registered_at'] ?: '—'; ?></td>
                    <td><?php echo $r['registered_by'] === 'scan' ? '扫码' : ($r['registered_by'] === 'click' ? '点击' : ($r['registered_by'] === 'batch' ? '批量' : '—')); ?></td>
                    <td><?php echo ($r['eval_value'] !== null && $r['eval_value'] !== '') ? '<span style="color:#e67e22;">' . htmlspecialchars($r['eval_value']) . '</span>' : '—'; ?></td>
                    <td><?php echo $r['eval_at'] ?: '—'; ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$has): ?>
                <tr><td colspan="6" style="text-align:center;color:#999;">暂无项目</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php page_footer(); ?>
