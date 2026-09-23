<?php
/**
 * 公共函数库
 * 兼容【潜记】APP 二维码格式：
 *  - 班级导入码：bjdl + 逐人 "编号-姓名-座号-备注" 用 | 连接（座号为本系统扩展，潜记原版无座号）
 *    示例：bjdl2024001-张三-1-|2024002-李四-2-|2024003-王五-3-备注1
 *    编号/姓名/备注中含 - 或 | 时转为全角 ／ ｜ 存储，避免分割出错
 *  - 个人码：djxh + 编号
 *    示例：djxh2024001
 */

// ===== 评价模式定义 =====
if (!function_exists('get_eval_modes')) {
    function get_eval_modes() {
        return [
            'smile'   => ['name' => '笑脸', 'options' => ['😊' => '笑脸', '😐' => '一般', '😞' => '加油']],
            'points'  => ['name' => '十分', 'options' => ['0' => '0', '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6', '7' => '7', '8' => '8', '9' => '9', '10' => '10']],
            'score'   => ['name' => '数值', 'options' => null],   // 自定义输入数值（0-100，允许小数，不允许非数值）
            'grade'   => ['name' => '优良', 'options' => ['优' => '优', '良' => '良', '合格' => '合格', '不合格' => '不合格', '待定' => '待定', '特殊' => '特殊']],
            'tf'      => ['name' => '对错', 'options' => ['√' => '√', '×' => '×', '待定' => '待定']],
            'star'    => ['name' => '星级', 'options' => ['★' => '一星', '★★' => '二星', '★★★' => '三星', '★★★★' => '四星', '★★★★★' => '五星']],
            'comment' => ['name' => '评语', 'options' => null],
            'abcd'    => ['name' => '答题', 'options' => ['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D']],   // 答题/举牌登记模式专用（四选一）
        ];
    }
}

// ===== 扩展表确保存在（存量安装免重装：班级转移 / 记住登录） =====
if (!function_exists('ensure_extra_tables')) {
    function ensure_extra_tables($conn) {
        static $done = false;
        if ($done) return;
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS class_transfers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL COMMENT '班级',
            from_teacher_id INT NOT NULL COMMENT '转出方（原创建人）',
            to_teacher_id INT NOT NULL COMMENT '转入方（按手机号查找）',
            status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending' COMMENT 'pending=待对方确认 approved=已转移 rejected=已拒绝 cancelled=已撤销',
            created_at DATETIME NOT NULL,
            handled_at DATETIME DEFAULT NULL,
            INDEX idx_class (class_id),
            INDEX idx_to (to_teacher_id, status),
            INDEX idx_from (from_teacher_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='班级转移申请（原创建人发起，对方确认后班级易主，原创建人保留管理授权）'");
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS login_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            teacher_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL COMMENT 'sha256(cookie token)',
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uk_token (token_hash),
            INDEX idx_teacher (teacher_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='保持登录令牌（勾选「保持登录」后30天内免输密码）'");
        $done = true;
    }
}

// ===== 保持登录（30天）：签发 / 校验自动登录 / 清除 =====
if (!function_exists('rememberme_issue')) {
    function rememberme_issue($conn, $teacher_id) {
        ensure_extra_tables($conn);
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $stmt = mysqli_prepare($conn, "INSERT INTO login_tokens (teacher_id, token_hash, expires_at, created_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())");
        mysqli_stmt_bind_param($stmt, "is", $teacher_id, $hash);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        setcookie('qj_remember', $teacher_id . ':' . $token, [
            'expires' => time() + 30 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
}
if (!function_exists('rememberme_try_login')) {
    /** 凭 cookie 令牌自动登录（会话不存在时调用）；成功返回 true 并已写入会话 */
    function rememberme_try_login($conn) {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (!empty($_SESSION['teacher_id'])) return true;
        $raw = isset($_COOKIE['qj_remember']) ? strval($_COOKIE['qj_remember']) : '';
        if (!preg_match('/^(\d{1,10}):([a-f0-9]{64})$/', $raw, $m)) return false;
        ensure_extra_tables($conn);
        $tid = intval($m[1]);
        $hash = hash('sha256', $m[2]);   // Cookie 存的是原始令牌，库中存 sha256 —— 必须先哈希再查（此前直接用原值查询导致「保持登录」永不生效）
        $stmt = mysqli_prepare($conn, "SELECT lt.id, t.id, t.realname, t.username, t.disabled FROM login_tokens lt
                                       INNER JOIN teachers t ON lt.teacher_id = t.id
                                       WHERE lt.token_hash = ? AND lt.teacher_id = ? AND lt.expires_at > NOW()");
        mysqli_stmt_bind_param($stmt, "si", $hash, $tid);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$row || intval($row['disabled']) === 1) return false;
        session_regenerate_id(true);
        $_SESSION['teacher_id'] = intval($row['id']);
        $_SESSION['teacher_name'] = $row['realname'] !== '' ? $row['realname'] : $row['username'];
        // 令牌轮换：旧令牌作废并换发新 30 天令牌（滑动续期，防重放）
        mysqli_query($conn, "DELETE FROM login_tokens WHERE token_hash = '" . hash('sha256', $m[2]) . "'");
        rememberme_issue($conn, intval($row['id']));
        return true;
    }
}
if (!function_exists('rememberme_clear')) {
    function rememberme_clear($conn) {
        $raw = isset($_COOKIE['qj_remember']) ? strval($_COOKIE['qj_remember']) : '';
        if (preg_match('/^(\d{1,10}):([a-f0-9]{64})$/', $raw, $m)) {
            ensure_extra_tables($conn);
            $hash = $m[2];
            $stmt = mysqli_prepare($conn, "DELETE FROM login_tokens WHERE token_hash = ?");
            mysqli_stmt_bind_param($stmt, "s", $hash);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        setcookie('qj_remember', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    }
}

// ===== 班级转移（原创建人发起 → 对方确认 → 班级易主，原创建人保留「管理」授权成员身份） =====
if (!function_exists('get_transfer_by_id')) {
    function get_transfer_by_id($conn, $transfer_id) {
        $stmt = mysqli_prepare($conn, "SELECT ct.*, c.name AS class_name, c.teacher_id AS owner_id, c.deleted_at AS class_deleted
                                       FROM class_transfers ct INNER JOIN classes c ON ct.class_id = c.id WHERE ct.id = ?");
        mysqli_stmt_bind_param($stmt, "i", $transfer_id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        return $row ?: null;
    }
}

// 生成班级导入码内容（兼容潜记APP"扫码导入名单"）
if (!function_exists('build_class_import_content')) {
    function build_class_import_content($students) {
        // $students: 每人含 seat_no, name, student_no, remark
        // 格式：编号-姓名-座号-备注，人之间用 | 连接（座号为本系统扩展，潜记原版为 编号-姓名-备注）
        // 编号/姓名/备注中若本身含分隔符 - 或 | ，生成时转为全角 － / ｜ 存储，避免解析分割出错
        $parts = [];
        foreach ($students as $s) {
            $esc = function ($v) {
                return strtr(strval($v), ['-' => '－', '|' => '｜']);
            };
            $parts[] = implode('-', [
                $esc($s['student_no']),
                $esc($s['name']),
                trim(strval($s['seat_no'] ?? '')),   // 座号为自然数或空，无需转义
                $esc($s['remark'] ?? ''),
            ]);
        }
        return 'bjdl' . implode('|', $parts);
    }
}

// 生成个人码内容（兼容潜记APP扫码识别）
if (!function_exists('build_student_qr_content')) {
    function build_student_qr_content($student_no) {
        return 'djxh' . $student_no;
    }
}

// 解析班级导入码内容（潜记格式 bjdl...），返回学生数组；解析失败返回 null
if (!function_exists('parse_class_import_content')) {
    function parse_class_import_content($content) {
        $content = trim($content);
        if (strpos($content, 'bjdl') !== 0) {
            return null;
        }
        $body = substr($content, 4);
        $students = [];
        foreach (explode('|', $body) as $item) {
            if ($item === '') continue;
            $f = explode('-', $item);
            if (count($f) < 2) continue;
            if (count($f) >= 4 && ($f[2] === '' || ctype_digit($f[2]))) {
                // 本系统新格式：编号-姓名-座号-备注（座号必为自然数或空；备注可含 - ，取剩余整段拼回）
                $students[] = [
                    'student_no' => $f[0],
                    'name'       => $f[1],
                    'seat_no'    => $f[2],
                    'remark'     => implode('-', array_slice($f, 3)),
                ];
            } else {
                // 潜记原版格式（兼容历史载荷/APP）：编号-姓名-备注，无座号（第3段非数字时整段视为备注）
                $students[] = [
                    'student_no' => $f[0],
                    'name'       => $f[1],
                    'remark'     => count($f) > 2 ? implode('-', array_slice($f, 2)) : '',
                    'seat_no'    => '',
                ];
            }
        }
        return empty($students) ? null : $students;
    }
}

// 解析个人码内容（djxh+编号），返回编号；失败返回 null
if (!function_exists('parse_student_qr_content')) {
    function parse_student_qr_content($content) {
        $content = trim($content);
        if (strpos($content, 'djxh') === 0) {
            return substr($content, 4);
        }
        return null; // 不是个人码，可能是编号原文
    }
}

// ===== 次项/题次（多次登记 multi / 答题模式 quiz 共用轮次机制） =====
if (!function_exists('project_is_rounded')) {
    function project_is_rounded($project) {
        return in_array(($project['mode'] ?? 'count'), ['multi', 'quiz', 'raise', 'omr'], true);
    }
}

// ===== 举牌模式（四选一）：6x6 简易黑白图案（比二维码更大更耐识别） =====
// 每生一张卡：图案唯一编码学生编号（student_no 1..255），答案=卡片哪条边朝上（A上/B右/C下/D左，规则同答题码）
// 码本约束：任两图案（含自身不同旋转）在全部 16 种旋转组合下汉明距离 ≥ 8 —— 编号与方向均抗误读
if (!function_exists('raise_patterns_hex')) {
    function raise_patterns_hex() {
        // 密度梯度码本（_gen_raise.php 生成）：序号靠前黑格多（定位快）、靠后少，26→12 单调不增；
        // 结构约束不变：黑块 4-连通、触及中心 2x2、bbox 跨 4~6、任两图案（含旋转 16 组合）汉明距离 ≥8
        return '2c2c0101aae20200431ca0010630400090ef0d5043041403061c60cd010082ce388000197c30600044d20a0888c73041400c0904114306580041786d0001294040406d30c14418290608a1059932801230c62080823e792008800005431e3e01888071a2180092e3cd141010eaa00410c00100c79e9220082abc24100a8be202020a7090008e73b290300643412c01c1070c006c35c18314804570004b82610c30259c79200081650610e1141c00c3b400c30d8706820093f072003a782220014dfe1c06102cc418c1c21601831c311d103884390ca00873c74800945f9800023cfd3182000e3c400268821406197c408220bbe009209f0b4003093de78001b0cf804201b3a09840a3dbac200570ee0ac0441a0b058c83ac98609281ed06082cbcba000863de5800a06401509b690516088f2000171f73ac1210c3c00123c737d16c1040e300388a3cf584002e1a01a0c773e3001ce1c602e1c38022c4b8739e6820871ef9841050c60401a3f7c401087b3c3940839feb0110098c30359704c10671e19c480227d0cf921047870c301ae4c09413c78f0d208e10554e5073fcf80024c94d340a396880029fff3d610580a00231df37000c9a6f038e01e3d1db0e0c32c751210d3ff0480aa2bc106414fbcf4400c30c386b06dc24c22c3ebe71c0482019a00aff8694078cba03cc38f8ff8d40281ffe200231ec32804bb063c608bf7c70c39a00e15c447bffb0a08600328451ff043ef9c30c61df01310c01e0afffa08e3a60e31c780d1a80833ef88614017dfbe3050467ef38004b60208b9e7fec3050e3e873c431c78048c79efd718328e37df048075d718e8073203869b3ff610c987c17102ce3f027879f38f398071c3c2d00aabfe71c6323845100f3dfcf008747d3c738094fe7cd50033824828fbffefb80029dc30248ff8e7845c752c9025bef84db019f77c5069ae9cf30079f1cd1069a7bc02c63be7fc71210cfffb642023fc6021b3e3c73e30360c5829cffb21cf0d3cde306307bcf3873055d618063effb00711f72430f9ef9df78c30c3fcf0023af803831fff3cf04b2cfbef87982426b870fbc541a21f7ff31ac15e3f09060f7f9f3072c3d8071f3cf9f680e8b3ec318632fff38f38e30fc3cf3061cf4036d3ede48039fffe3de00cbff70b00d7fb20027ffdc19c05ffff75c005d8778750fbcf8c8a8effd5002efbff2ca0877fdd020de7df3025b3df71f402e7ae387703fef3dd403afe683107fff78a09a6c370d783fe25049ffffdf6c1871df6da05c7701467bff0039ef9ffffbac0157cf78e933cdb0eb0ebed41d64b7fef8243afbffaeb08a9ffff14013bc80b39ffe3bef1e218c09f3df78f304fbcfdf583782f9f18f70cfecb8e3c3ffe3c035f7e3dc388ffcd71b0fbefe7de4865fbeb802fbbe6930df7ff6832fb8df12c17df9cf878f3df7586d34ff2602f3ffde3cf9c3cfbe321d7cffe8a0eb3073df787bffde310f8fb2c26de7fffef2111e3b8eb8effb4c74e7def3e79e437cf3e38f9fff7c0ac3ffbc3492fa20c7dfffefbc7b82efffee3a80fffe74c60fffedb028ffdf30c87fff36883dff1cf3e23de7aeb8673cb836dfffcc07de7b9e38f8eff';
    }
}
// 编号 → 36 位图案码（超范围返回 0）
if (!function_exists('raise_pattern_code')) {
    function raise_pattern_code($no) {
        $no = intval($no);
        if ($no < 1 || $no > 255) return 0;
        return hexdec(substr(raise_patterns_hex(), ($no - 1) * 9, 9));
    }
}
// 图案码顺时针旋转 90°（new[r][c] = old[5-c][r]；位索引 = (5-r)*6+c）
if (!function_exists('raise_rot_cw')) {
    function raise_rot_cw($bits36) {
        $out = 0;
        for ($r = 0; $r < 6; $r++) for ($c = 0; $c < 6; $c++) {
            $v = (intval($bits36) >> ($c * 6 + $r)) & 1;
            $out |= $v << ((5 - $r) * 6 + $c);
        }
        return $out;
    }
}
// 编号 → 6x6 位阵列（row-major 36 元素，1=白格 0=黑格）
if (!function_exists('raise_pattern_bits')) {
    function raise_pattern_bits($no) {
        $code = raise_pattern_code($no);
        $bits = [];
        for ($r = 0; $r < 6; $r++) for ($c = 0; $c < 6; $c++) $bits[$r * 6 + $c] = ($code >> ((5 - $r) * 6 + $c)) & 1;
        return $bits;
    }
}

// 项目轮次列表（升序 round_no 数组）；无轮次时自动补第 1 次（幂等）
if (!function_exists('project_rounds_list')) {
    function project_rounds_list($conn, $project_id) {
        $project_id = intval($project_id);
        $rounds = [];
        $res = mysqli_query($conn, "SELECT round_no FROM project_rounds WHERE project_id = {$project_id} ORDER BY round_no ASC");
        while ($row = mysqli_fetch_assoc($res)) $rounds[] = intval($row['round_no']);
        if (!$rounds) {
            mysqli_query($conn, "INSERT IGNORE INTO project_rounds (project_id, round_no, created_at) VALUES ({$project_id}, 1, NOW())");
            $rounds = [1];
        }
        return $rounds;
    }
}

// 轮次自定义标题映射（{round_no: title}，仅含已命名的轮次；教师端题次/项次面板显示标题，空=显示序号）
if (!function_exists('project_rounds_titles')) {
    function project_rounds_titles($conn, $project_id) {
        $project_id = intval($project_id);
        $titles = [];
        $res = mysqli_query($conn, "SELECT round_no, title FROM project_rounds WHERE project_id = {$project_id} AND title IS NOT NULL AND title <> ''");
        while ($row = mysqli_fetch_assoc($res)) $titles[intval($row['round_no'])] = $row['title'];
        return $titles;
    }
}

// POST 中的轮次参数校验（round 无效时取最后一次）；返回合法 round_no
if (!function_exists('current_round_no')) {
    function current_round_no($conn, $project) {
        if (!project_is_rounded($project)) return 0;
        $rounds = project_rounds_list($conn, intval($project['id']));
        $round = intval($_POST['round'] ?? 0);
        return in_array($round, $rounds, true) ? $round : $rounds[count($rounds) - 1];
    }
}

// 答题卡识别结果表补轮次列（存量安装幂等迁移）：omr 模式已纳入题次管理，同生不同题次各自保留最新识别结果
if (!function_exists('ensure_omr_round')) {
    function ensure_omr_round($conn) {
        static $done = false;
        if ($done) return;
        $done = true;
        $r = mysqli_query($conn, "SHOW COLUMNS FROM omr_results LIKE 'round_no'");
        if ($r && mysqli_num_rows($r) === 0) {
            mysqli_query($conn, "ALTER TABLE omr_results ADD COLUMN round_no INT NOT NULL DEFAULT 1 COMMENT '题次' AFTER project_id");
            mysqli_query($conn, "ALTER TABLE omr_results DROP INDEX uk_pid_ident, ADD UNIQUE KEY uk_pid_round_ident (project_id, round_no, ident)");
        }
    }
}

// 轮次维度统计（登记/已评价人数，覆盖项目全部班级）
if (!function_exists('round_counts')) {
    function round_counts($conn, $project_id, $round) {
        $project_id = intval($project_id);
        $round = intval($round);
        $res = mysqli_query($conn, "SELECT COUNT(*) AS reg, COALESCE(SUM(COALESCE(eval_value, '') <> ''), 0) AS ev
                                    FROM record_rounds WHERE project_id = {$project_id} AND round_no = {$round} AND registered_at IS NOT NULL");
        $row = mysqli_fetch_assoc($res);
        return [intval($row['reg']), intval($row['ev'])];
    }
}

// 评价模式徽标 CSS 类（系统模板键有专属 badge-* 颜色；自定义模式键为 c+编号，统一走 badge-custom，避免无样式裸标签）
if (!function_exists('eval_badge_class')) {
    function eval_badge_class($key) {
        return preg_match('/^c\d+$/', strval($key)) ? 'badge-custom' : 'badge-' . htmlspecialchars(strval($key));
    }
}

// 评价模式：系统模板（代码内置，仅可禁用不可改删）+ 用户自定义模式（键 c+编号） =====
if (!function_exists('get_custom_eval_modes')) {
    function get_custom_eval_modes($conn, $teacher_id) {
        $modes = [];
        $stmt = mysqli_prepare($conn, "SELECT id, name, options FROM eval_modes WHERE teacher_id = ? ORDER BY sort ASC, id ASC");
        mysqli_stmt_bind_param($stmt, "i", $teacher_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $modes['c' . intval($row['id'])] = build_custom_mode_info(intval($row['id']), $row['name'], $row['options']);
        }
        mysqli_stmt_close($stmt);
        return $modes;
    }
}
if (!function_exists('build_custom_mode_info')) {
    function build_custom_mode_info($cid, $name, $options_raw) {
        // 选项每行一个文本；键=值=选项文本本身（与系统模板一致，评价值直接存文本而非行号）
        $opts = array_values(array_unique(array_filter(array_map('trim', explode("\n", str_replace("\r", '', strval($options_raw)))), function ($v) { return $v !== ''; })));
        return [
            'name' => $name . '（自定义）',
            'options' => $opts ? array_combine($opts, $opts) : null,   // null=自由输入
            'custom' => true,
            'cid' => $cid,
        ];
    }
}
// 任意评价模式键解析（系统键或自定义 c+编号；找不到返回 null）
if (!function_exists('eval_mode_info')) {
    function eval_mode_info($conn, $key) {
        $key = strval($key);
        if ($key !== '' && $key[0] === 'c' && ctype_digit(substr($key, 1))) {
            $cid = intval(substr($key, 1));
            $stmt = mysqli_prepare($conn, "SELECT id, name, options FROM eval_modes WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $cid);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            return $row ? build_custom_mode_info($cid, $row['name'], $row['options']) : null;
        }
        $modes = get_eval_modes();
        return $modes[$key] ?? null;
    }
}
// 当前教师禁用的系统模板键列表（个人偏好，存 settings：t{tid}_eval_disabled）
if (!function_exists('get_disabled_eval_modes')) {
    function get_disabled_eval_modes($conn, $teacher_id, $refresh = false) {
        static $cache = [];
        $tid = intval($teacher_id);
        // $refresh：写库后强制重建（static 缓存按 teacher_id 存续于整个请求，若不清会导致保存后同请求渲染读到旧状态）
        if ($refresh || !array_key_exists($tid, $cache)) {
            $skey = 't' . $tid . '_eval_disabled';
            $stmt = mysqli_prepare($conn, "SELECT svalue FROM settings WHERE skey = ?");
            mysqli_stmt_bind_param($stmt, "s", $skey);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            $cache[$tid] = ($row && $row['svalue'] !== '') ? array_values(array_filter(explode(',', $row['svalue']))) : [];
        }
        return $cache[$tid];
    }
}
if (!function_exists('set_eval_mode_disabled')) {
    function set_eval_mode_disabled($conn, $teacher_id, $key, $disabled) {
        $modes = get_eval_modes();
        if (!isset($modes[$key])) return; // 仅允许系统键
        $tid = intval($teacher_id);
        $cur = get_disabled_eval_modes($conn, $tid);
        $cur = array_values(array_filter($cur, function ($k) use ($modes) { return isset($modes[$k]); }));
        if ($disabled) {
            if (!in_array($key, $cur, true)) $cur[] = $key;
        } else {
            $cur = array_values(array_filter($cur, function ($k) use ($key) { return $k !== $key; }));
        }
        $skey = 't' . $tid . '_eval_disabled';
        $sval = implode(',', $cur);
        $stmt = mysqli_prepare($conn, "INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)");
        mysqli_stmt_bind_param($stmt, "ss", $skey, $sval);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        get_disabled_eval_modes($conn, $tid, true); // 写后刷新静态缓存，保证同请求内渲染读到最新状态
    }
}
// 当前教师禁用的自定义评价模式 cid 列表（个人偏好，存 settings：t{tid}_eval_custom_disabled）
if (!function_exists('get_disabled_custom_modes')) {
    function get_disabled_custom_modes($conn, $teacher_id, $refresh = false) {
        static $cache = [];
        $tid = intval($teacher_id);
        // $refresh：写库后强制重建（与 get_disabled_eval_modes 同款，防同请求渲染读到旧状态）
        if ($refresh || !array_key_exists($tid, $cache)) {
            $skey = 't' . $tid . '_eval_custom_disabled';
            $stmt = mysqli_prepare($conn, "SELECT svalue FROM settings WHERE skey = ?");
            mysqli_stmt_bind_param($stmt, "s", $skey);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            $cache[$tid] = ($row && $row['svalue'] !== '') ? array_values(array_filter(explode(',', $row['svalue']))) : [];
        }
        return $cache[$tid];
    }
}
if (!function_exists('set_custom_mode_disabled')) {
    function set_custom_mode_disabled($conn, $teacher_id, $cid, $disabled) {
        $cid = strval(intval($cid));
        $tid = intval($teacher_id);
        $cur = get_disabled_custom_modes($conn, $tid);
        if ($disabled) {
            if (!in_array($cid, $cur, true)) $cur[] = $cid;
        } else {
            $cur = array_values(array_filter($cur, function ($k) use ($cid) { return $k !== $cid; }));
        }
        $skey = 't' . $tid . '_eval_custom_disabled';
        $sval = implode(',', $cur);
        $stmt = mysqli_prepare($conn, "INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)");
        mysqli_stmt_bind_param($stmt, "ss", $skey, $sval);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        get_disabled_custom_modes($conn, $tid, true); // 写后刷新静态缓存，保证同请求内渲染读到最新状态
    }
}

// 当前教师可用的评价模式（未禁用系统模板 + 未禁用的自定义模式）：项目创建/编辑下拉与校验用
if (!function_exists('get_enabled_eval_modes')) {
    function get_enabled_eval_modes($conn, $teacher_id) {
        $disabled = get_disabled_eval_modes($conn, $teacher_id);
        $dis_custom = get_disabled_custom_modes($conn, $teacher_id);
        $modes = [];
        foreach (get_eval_modes() as $k => $m) {
            if (!in_array($k, $disabled, true)) $modes[$k] = $m;
        }
        foreach (get_custom_eval_modes($conn, $teacher_id) as $k => $m) {
            if (!in_array(strval($m['cid']), $dis_custom, true)) $modes[$k] = $m;   // 禁用的自定义模式不再出现在新建项目下拉（已有项目引用不受影响）
        }
        return $modes;
    }
}

// 校验评价值是否合法（$mode 为系统键或自定义键 c+编号）
if (!function_exists('validate_eval_value')) {
    function validate_eval_value($conn, $mode, $value) {
        $mode = strval($mode);
        if ($mode !== '' && $mode[0] === 'c' && ctype_digit(substr($mode, 1))) {
            $info = eval_mode_info($conn, $mode);
            if (!$info) return false;
            $options = $info['options'];
            if ($options === null) {
                return $value !== '' && mb_strlen($value) <= 200;
            }
            return array_key_exists($value, $options);
        }
        $modes = get_eval_modes();
        if (!isset($modes[$mode])) return false;
        $options = $modes[$mode]['options'];
        if ($options === null) {
            // 自由输入类
            if ($mode === 'score') {
                return is_numeric($value) && $value >= 0 && $value <= 100;
            }
            return $value !== '' && mb_strlen($value) <= 200;
        }
        return array_key_exists($value, $options);
    }
}

// record_days 字符集懒迁移：旧装该表为 utf8（3字节），笑脸/星级等 emoji 评价值写入会被静默截为空串。
// 在每日评价写入路径调用一次，CONVERT TO 会连同存量数据一并转码（utf8→utf8mb4 无损）。
if (!function_exists('record_days_ensure_charset')) {
    function record_days_ensure_charset($conn) {
        static $done = false;
        if ($done) return;
        $done = true;
        $r = mysqli_query($conn, "SELECT CCSA.character_set_name FROM information_schema.TABLES T
                                  INNER JOIN information_schema.COLLATION_CHARACTER_SET_APPLICABILITY CCSA
                                    ON T.TABLE_COLLATION = CCSA.COLLATION_NAME
                                  WHERE T.TABLE_SCHEMA = DATABASE() AND T.TABLE_NAME = 'record_days'");
        $row = $r ? mysqli_fetch_assoc($r) : null;
        if ($row && strtolower(strval($row['character_set_name'])) !== 'utf8mb4') {
            mysqli_query($conn, "ALTER TABLE record_days CONVERT TO CHARACTER SET utf8mb4");
        }
    }
}

// 校验当前教师是否拥有某班级（创建人/学校管理员/班主任/授权管理成员；软删除的班级视为不存在）
if (!function_exists('check_class_owner')) {
    function check_class_owner($conn, $class_id, $teacher_id) {
        $stmt = mysqli_prepare($conn, "SELECT id, name, teacher_id FROM classes WHERE id = ? AND deleted_at IS NULL");
        mysqli_stmt_bind_param($stmt, "i", $class_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $class = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
        if ($class && can_manage_class($conn, $teacher_id, $class_id)) {
            return $class;
        }
        return null;
    }
}

// 校验当前教师是否拥有某项目（联表校验班级归属；软删除的项目视为不存在）
if (!function_exists('check_project_owner')) {
    function check_project_owner($conn, $project_id, $teacher_id) {
        $stmt = mysqli_prepare($conn, "SELECT p.*, c.name AS class_name, c.teacher_id
                                       FROM projects p INNER JOIN classes c ON p.class_id = c.id
                                       WHERE p.id = ? AND c.teacher_id = ? AND p.deleted_at IS NULL");
        mysqli_stmt_bind_param($stmt, "ii", $project_id, $teacher_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $project = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
        return $project ?: null;
    }
}

// 输出 JSON 并结束（AJAX 接口用）
if (!function_exists('json_response')) {
    function json_response($data) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit();
    }
}

// ============================================================
// ===== 平台设置（后台管理开关，按学校隔离：skey = "{学校id}_{key}"） =====
// ============================================================
if (!function_exists('get_setting')) {
    function get_setting($conn, $key, $default = '', $school_id = null) {
        static $cache = [];
        if ($school_id === null) $school_id = current_school_id($conn);
        $skey = intval($school_id) . '_' . $key;
        if (array_key_exists($skey, $cache)) return $cache[$skey];
        $stmt = mysqli_prepare($conn, "SELECT svalue FROM settings WHERE skey = ?");
        mysqli_stmt_bind_param($stmt, "s", $skey);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
        $cache[$skey] = ($row && $row['svalue'] !== '') ? $row['svalue'] : $default;
        return $cache[$skey];
    }
}
if (!function_exists('set_setting')) {
    function set_setting($conn, $key, $value, $school_id = null) {
        if ($school_id === null) $school_id = current_school_id($conn);
        $skey = intval($school_id) . '_' . $key;
        $stmt = mysqli_prepare($conn, "INSERT INTO settings (skey, svalue) VALUES (?, ?)
                                       ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)");
        mysqli_stmt_bind_param($stmt, "ss", $skey, $value);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

// ============================================================
// ===== 权限模型（单用户版：无角色体系，权限=创建人 + 班级授权成员 + 管理员） =====
// ============================================================
if (!function_exists('get_scope_names')) {
    function get_scope_names() {
        return [
            'class' => '班级项目',
            'school' => '全校项目',
        ];
    }
}

// 管理员（单用户版：仅安装时生成的管理员账号，teachers.is_admin=1）；
// 可进入后台用户管理/模拟登陆，不自动获得他人班级的可见性（需通过模拟登陆）
if (!function_exists('is_super_admin')) {
    function is_super_admin($conn, $teacher_id) {
        static $cache = [];
        $tid = intval($teacher_id);
        if ($tid <= 0) return false;
        if (isset($cache[$tid])) return $cache[$tid];
        $stmt = mysqli_prepare($conn, "SELECT is_admin FROM teachers WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $tid);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        return $cache[$tid] = $row && intval($row['is_admin']) === 1;
    }
}

// 学校管理员（单用户版：无学校管理员概念，恒 false；
// 全体用户均按「个人账号」口径：自己创建的班级 + 班级授权成员）
if (!function_exists('is_school_admin')) {
    function is_school_admin($conn, $teacher_id) {
        return false;
    }
}

if (!function_exists('login_track')) {
    // 登录活跃统计：每次登录（含 30 天免登录自动登录）记一天一档，cnt=当日登录次数（个人中心「登录天数/累计登录」卡片用）
    function login_track($conn, $teacher_id) {
        $tid = intval($teacher_id);
        if ($tid <= 0 || !$conn) return;
        $stmt = mysqli_prepare($conn, "INSERT INTO login_days (teacher_id, day, cnt, last_at) VALUES (?, CURDATE(), 1, NOW()) ON DUPLICATE KEY UPDATE cnt = cnt + 1, last_at = NOW()");
        mysqli_stmt_bind_param($stmt, "i", $tid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

if (!function_exists('omrimg_upload_ok')) {
    // 答题卡批改图上传服务器权限（单用户版：特色开关/分角色授权已移除，所有用户均可上传）
    // api.php omr_img_save 与 omr_scan.php 页面注入共用
    function omrimg_upload_ok($conn, $teacher_id) {
        return true;
    }
}

// 教师所属学校（总管理员 school_id=0）
if (!function_exists('get_teacher_school_id')) {
    function get_teacher_school_id($conn, $teacher_id) {
        static $cache = [];
        $tid = intval($teacher_id);
        if (isset($cache[$tid])) return $cache[$tid];
        $stmt = mysqli_prepare($conn, "SELECT school_id FROM teachers WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $tid);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        return $cache[$tid] = $row ? intval($row['school_id']) : 0;
    }
}

// 当前学校上下文（单用户版：无学校概念，恒为默认单位 1；settings skey 前缀 "1_"）
if (!function_exists('current_school_id')) {
    function current_school_id($conn) {
        return 1;
    }
}

// 学校名称（单用户版：无学校表，恒返回空串）
if (!function_exists('get_school_name')) {
    function get_school_name($conn, $school_id) {
        return '';
    }
}

// ===== 班级授权（class_members）：教师凭班级授权码申请，班级创建人审核 =====
// perm：manage=管理班级（可管理名单/建/改/删本班全部项目/登记） create=可建立（建/改本班全部项目、删自己项目、登记）
//       register=仅登记 view=仅查看
if (!function_exists('get_teacher_class_member_map')) {
    function get_teacher_class_member_map($conn, $teacher_id) {
        static $cache = [];
        $tid = intval($teacher_id);
        if (isset($cache[$tid])) return $cache[$tid];
        $map = [];
        $stmt = mysqli_prepare($conn, "SELECT class_id, perm FROM class_members
                                       WHERE teacher_id = ? AND status = 'approved'");
        mysqli_stmt_bind_param($stmt, "i", $tid);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) $map[intval($row['class_id'])] = $row['perm'];
        mysqli_stmt_close($stmt);
        return $cache[$tid] = $map;
    }
}

// 某班级的授权成员（approved）与待审核申请（pending）
if (!function_exists('get_class_members')) {
    function get_class_members($conn, $class_id, $status = 'approved') {
        $rows = [];
        $stmt = mysqli_prepare($conn, "SELECT cm.*, t.username, t.phone, t.realname FROM class_members cm
                                       INNER JOIN teachers t ON cm.teacher_id = t.id
                                       WHERE cm.class_id = ? " . ($status ? "AND cm.status = '" . mysqli_real_escape_string($conn, $status) . "'" : "") . "
                                       ORDER BY cm.id ASC");
        mysqli_stmt_bind_param($stmt, "i", $class_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
        mysqli_stmt_close($stmt);
        return $rows;
    }
}

// 生成唯一班级授权码（8位数字）
if (!function_exists('generate_class_code')) {
    function generate_class_code($conn) {
        do {
            $code = strval(rand(10000000, 99999999));
            $r2 = mysqli_query($conn, "SELECT id FROM classes WHERE class_code = '{$code}' LIMIT 1");
            $used = $r2 && mysqli_num_rows($r2) > 0;
        } while ($used);
        return $code;
    }
}

// ===== 个人置顶（班级/项目） =====
if (!function_exists('get_pinned_ids')) {
    function get_pinned_ids($conn, $teacher_id, $type) {
        static $cache = [];
        $key = intval($teacher_id) . '_' . $type;
        if (isset($cache[$key])) return $cache[$key];
        $ids = [];
        $stmt = mysqli_prepare($conn, "SELECT item_id FROM pinned_items WHERE teacher_id = ? AND item_type = ?");
        mysqli_stmt_bind_param($stmt, "is", $teacher_id, $type);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) $ids[] = intval($row['item_id']);
        mysqli_stmt_close($stmt);
        return $cache[$key] = $ids;
    }
}
if (!function_exists('toggle_pinned')) {
    function toggle_pinned($conn, $teacher_id, $type, $item_id) {
        $teacher_id = intval($teacher_id); $item_id = intval($item_id);
        $type = $type === 'project' ? 'project' : 'class';
        $stmt = mysqli_prepare($conn, "SELECT id FROM pinned_items WHERE teacher_id = ? AND item_type = ? AND item_id = ?");
        mysqli_stmt_bind_param($stmt, "isi", $teacher_id, $type, $item_id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if ($row) {
            $stmt = mysqli_prepare($conn, "DELETE FROM pinned_items WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $row['id']);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            return false; // 已取消置顶
        }
        $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO pinned_items (teacher_id, item_type, item_id, created_at) VALUES (?, ?, ?, NOW())");
        mysqli_stmt_bind_param($stmt, "isi", $teacher_id, $type, $item_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return true; // 已置顶
    }
}

// 教师可见（可查看统计/名单）的班级 id 列表（单用户版：班级授权成员 + 自己创建的班级）
if (!function_exists('get_visible_class_ids')) {
    function get_visible_class_ids($conn, $teacher_id) {
        $ids = [];
        // 班级授权成员（manage/register/view 任一）均可见该班级
        foreach (array_keys(get_teacher_class_member_map($conn, $teacher_id)) as $c) $ids[] = intval($c);
        // 自己创建的班级也可见
        $stmt = mysqli_prepare($conn, "SELECT id FROM classes WHERE teacher_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $teacher_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) $ids[] = intval($row['id']);
        mysqli_stmt_close($stmt);
        $ids = array_values(array_unique($ids));
        // 统一过滤软删除（回收站）的班级：覆盖角色/授权成员等所有来源
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            $res = mysqli_query($conn, "SELECT id FROM classes WHERE id IN ({$in}) AND deleted_at IS NOT NULL");
            $dead = [];
            while ($row = mysqli_fetch_assoc($res)) $dead[] = intval($row['id']);
            if ($dead) $ids = array_values(array_diff($ids, $dead));
        }
        return $ids;
    }
}

// 教师自己创建的班级（个人账号/班级创建者默认对该班拥有管理权）
if (!function_exists('get_teacher_created_class_ids')) {
    function get_teacher_created_class_ids($conn, $teacher_id) {
        static $cache = [];
        $tid = intval($teacher_id);
        if (isset($cache[$tid])) return $cache[$tid];
        $ids = [];
        $stmt = mysqli_prepare($conn, "SELECT id FROM classes WHERE teacher_id = ? AND deleted_at IS NULL");
        mysqli_stmt_bind_param($stmt, "i", $tid);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) $ids[] = intval($row['id']);
        mysqli_stmt_close($stmt);
        return $cache[$tid] = $ids;
    }
}

// 是否可查看某班级
if (!function_exists('can_view_class')) {
    function can_view_class($conn, $teacher_id, $class_id) {
        return in_array(intval($class_id), get_visible_class_ids($conn, $teacher_id), true);
    }
}

// 是否可管理某班级（编辑班级信息/学生名单等：创建人/班级授权-管理级别）
if (!function_exists('can_manage_class')) {
    function can_manage_class($conn, $teacher_id, $class_id) {
        $class_id = intval($class_id);
        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM classes WHERE id = ? AND teacher_id = ? AND deleted_at IS NULL");
        mysqli_stmt_bind_param($stmt, "ii", $class_id, $teacher_id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (intval($row['c']) > 0) return true;
        // 班级授权成员：manage 级别可管理名单
        return (get_teacher_class_member_map($conn, $teacher_id)[$class_id] ?? '') === 'manage';
    }
}

// 是否可对班级做破坏性操作（复制/重置：仅创建人）
if (!function_exists('can_manage_class_strict')) {
    function can_manage_class_strict($conn, $teacher_id, $class_id) {
        $class_id = intval($class_id);
        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM classes WHERE id = ? AND teacher_id = ? AND deleted_at IS NULL");
        mysqli_stmt_bind_param($stmt, "ii", $class_id, $teacher_id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        return intval($row['c']) > 0;
    }
}

// ===== 班级权限开关（后台「功能开关」按学校配置；未勾选 = 默认仅管理员/创建人等基础权限） =====
// class_exit_roles（退出班级）/ class_delete_roles（删除班级）/ class_roster_roles（修改班级名单）
if (!function_exists('get_class_perm_roles')) {
    function get_class_perm_roles($conn, $school_id, $type) {
        $raw = trim(strval(get_setting($conn, 'class_' . $type . '_roles', '', intval($school_id))));
        if ($raw === '') return [];
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}

// ===== 班级授权成员细分权限（class_members.perm：manage=管理员 create=可建立 register=仅登记 view=仅查看） =====
// 基础身份（班级创建人）视同 manage；授权成员按 perm 判定；无关联返回 ''
if (!function_exists('get_class_perm_level')) {
    function get_class_perm_level($conn, $teacher_id, $class_id) {
        $class_id = intval($class_id);
        if ($class_id <= 0) return '';
        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM classes WHERE id = ? AND teacher_id = ? AND deleted_at IS NULL");
        mysqli_stmt_bind_param($stmt, "ii", $class_id, $teacher_id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (intval($row['c']) > 0) return 'manage';
        return strval(get_teacher_class_member_map($conn, $teacher_id)[$class_id] ?? '');
    }
}
// 可使用喊话（发送/状态/历史/反向喊话/在线心跳）：管理员/可建立/仅登记 可用；仅查看不可
if (!function_exists('can_announce_class')) {
    function can_announce_class($conn, $teacher_id, $class_id) {
        return in_array(get_class_perm_level($conn, $teacher_id, $class_id), ['manage', 'create', 'register'], true);
    }
}
// 可操作积分（加减/规则/项设置/导入导出/清空/兑换）：管理员/可建立/仅登记 可用；仅查看只可查看数据
if (!function_exists('can_points_class')) {
    function can_points_class($conn, $teacher_id, $class_id) {
        return in_array(get_class_perm_level($conn, $teacher_id, $class_id), ['manage', 'create', 'register'], true);
    }
}

// 是否可退出班级：班级码加入的成员随时可退；自己创建的班级不能退出（可删除）
// （单用户版：无管理员设置/导入的角色关联，「退出班级权限」角色开关判定已随之移除）
if (!function_exists('can_exit_class')) {
    function can_exit_class($conn, $teacher_id, $class_id) {
        $class_id = intval($class_id);
        if (in_array($class_id, get_teacher_created_class_ids($conn, $teacher_id), true)) return false;
        // 与班级无授权关联，无需退出；有授权关联（班级码加入）随时可退，不受开关影响
        return array_key_exists($class_id, get_teacher_class_member_map($conn, $teacher_id));
    }
}

// 是否可删除班级：创建人可删除自己建立的
// （单用户版：管理员/年段长/班主任角色判定已移除，「删除班级权限」角色开关不再生效）
if (!function_exists('can_delete_class')) {
    function can_delete_class($conn, $teacher_id, $class_id) {
        $class_id = intval($class_id);
        return in_array($class_id, get_teacher_created_class_ids($conn, $teacher_id), true);
    }
}

// 是否可修改班级名单：创建人可修改自己建立的；班级授权-管理级别成员不受影响
// （单用户版：管理员/年段长/班主任角色判定已移除，「修改班级名单权限」角色开关不再生效）
if (!function_exists('can_manage_roster')) {
    function can_manage_roster($conn, $teacher_id, $class_id) {
        $class_id = intval($class_id);
        if (in_array($class_id, get_teacher_created_class_ids($conn, $teacher_id), true)) return true;
        return (get_teacher_class_member_map($conn, $teacher_id)[$class_id] ?? '') === 'manage';
    }
}

// 项目覆盖的班级 id 列表
if (!function_exists('get_project_class_ids')) {
    function get_project_class_ids($conn, $project) {
        $scope = $project['scope'];
        $school_id = intval($project['school_id'] ?? 0);
        if ($scope === 'school') {
            $ids = [];
            if ($school_id > 0) {
                $stmt = mysqli_prepare($conn, "SELECT id FROM classes WHERE school_id = ? AND deleted_at IS NULL");
                mysqli_stmt_bind_param($stmt, "i", $school_id);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                while ($row = mysqli_fetch_assoc($res)) $ids[] = intval($row['id']);
                mysqli_stmt_close($stmt);
            }
            return $ids;
        }
        return $project['class_id'] ? [intval($project['class_id'])] : [];
    }
}

// 是否可创建指定范围的项目（单用户版：仅班级项目可创建——学校/年段/学科/班级学科范围随角色体系移除）
if (!function_exists('can_create_project')) {
    function can_create_project($conn, $teacher_id, $scope, $class_id = 0) {
        $class_id = intval($class_id);
        if ($scope !== 'class') return false;
        // 班级创建者（含未加入学校的个人账号）可为本班创建班级项目
        if (in_array($class_id, get_teacher_created_class_ids($conn, $teacher_id), true)) return true;
        // 班级授权成员：manage/create 级别可创建班级项目
        return in_array(get_teacher_class_member_map($conn, $teacher_id)[$class_id] ?? '', ['manage', 'create'], true);
    }
}

// ===== 特色功能开关（学校级设置，默认开启；school_id=0 为个人账号平台设置） =====
if (!function_exists('get_feature')) {
    function get_feature($conn, $school_id, $key) {
        return get_setting($conn, $key, '1', intval($school_id)) === '1';
    }
}
// 答题/举牌模式分别开关（feat_quiz / feat_raise；未单独配置时继承旧合并开关 feat_quiz_raise 的值，兼容此前设置）
if (!function_exists('get_quizraise_feature')) {
    function get_quizraise_feature($conn, $school_id, $key) {   // $key: 'quiz' | 'raise'
        $sid = intval($school_id);
        $v = get_setting($conn, 'feat_' . $key, '', $sid);
        if ($v === '') $v = get_setting($conn, 'feat_quiz_raise', '1', $sid);
        return $v === '1';
    }
}
// 建班/建项目允许的角色名单（csv；未配置 = 全部角色均允许，兼容旧行为）
if (!function_exists('get_create_allowed_roles')) {
    function get_create_allowed_roles($conn, $school_id, $type) {
        $key = $type === 'class' ? 'class_create_roles' : 'project_create_roles';
        $raw = trim(strval(get_setting($conn, $key, '', intval($school_id))));
        if ($raw === '') return null; // null = 不限制
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
// 过期项目编辑允许的角色名单：未配置('')=不限制（兼容旧行为：开关开启即全员可用）；'adminsonly'=仅管理员；其余为角色csv
if (!function_exists('get_edit_expired_roles')) {
    function get_edit_expired_roles($conn, $school_id) {
        $raw = trim(strval(get_setting($conn, 'edit_expired_roles', '', intval($school_id))));
        if ($raw === '') return null;              // null = 不限制（未配置）
        if ($raw === 'adminsonly') return [];      // [] = 仅管理员
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
// 过期项目编辑闸门（单用户版：特色开关已移除，所有用户均可编辑过期项目）
if (!function_exists('can_edit_expired')) {
    function can_edit_expired($conn, $teacher_id, $school_id) {
        return true;
    }
}
// 建班闸门：管理员恒可；个人账号（未加入学校）恒可（不受开关限制，平台不提供该设置）
if (!function_exists('can_create_class_gate')) {
    function can_create_class_gate($conn, $teacher_id) {
        if (is_super_admin($conn, $teacher_id)) return true;
        $sid = intval(get_teacher_school_id($conn, $teacher_id));
        if ($sid === 0) return true; // 个人账号：始终允许建班/建项目
        if (!get_feature($conn, $sid, 'feat_class_create')) return false;
        $allowed = get_create_allowed_roles($conn, $sid, 'class');
        if ($allowed === null) return true;
        // 单用户版：无角色体系，教师均按「无角色 = 普通教师」判定
        return in_array('none', $allowed, true);
    }
}
// 建项目闸门（控制「是否允许建项目」；具体范围仍由 can_create_project 判断）；个人账号恒可
if (!function_exists('can_create_project_gate')) {
    function can_create_project_gate($conn, $teacher_id) {
        if (is_super_admin($conn, $teacher_id)) return true;
        $sid = intval(get_teacher_school_id($conn, $teacher_id));
        if ($sid === 0) return true; // 个人账号：始终允许建班/建项目
        if (!get_feature($conn, $sid, 'feat_project_create')) return false;
        $allowed = get_create_allowed_roles($conn, $sid, 'project');
        if ($allowed === null) return true;
        // 单用户版：无角色体系，教师均按「无角色 = 普通教师」判定
        return in_array('none', $allowed, true);
    }
}
// ============================================================
// ===== 答题卡共享模板库（omr_lib 表，后台开关 feat_omr_lib） =====
// 范围（单用户版仅 class）：班级模板=该班授权成员/创建人可用；存量 school 模板人人可用
// 改删=创建人
// ============================================================
// 库功能是否开启：未加入学校（个人账号）不开放；学校账号按后台开关（未配置=默认开放）
if (!function_exists('omr_lib_enabled')) {
    function omr_lib_enabled($conn) {
        $sid = current_school_id($conn);
        return $sid > 0 && get_feature($conn, $sid, 'feat_omr_lib');
    }
}
// 可建立指定范围的库模板（沿用作业项目的身份范围规则 can_create_project，另受库开关约束）
if (!function_exists('can_create_omr_lib')) {
    function can_create_omr_lib($conn, $teacher_id, $scope, $class_id = 0) {
        if (!omr_lib_enabled($conn)) return false;
        return can_create_project($conn, $teacher_id, $scope, $class_id);
    }
}
// 可加载（使用）某库模板：存量全校模板人人可用；班级模板=该班授权成员/班级创建人
if (!function_exists('can_load_omr_lib')) {
    function can_load_omr_lib($conn, $teacher_id, $tpl) {
        $scope = strval($tpl['scope'] ?? '');
        if ($scope === 'school') return true;
        $cid = intval($tpl['class_id'] ?? 0);
        if ($scope === 'class') {
            return isset(get_teacher_class_member_map($conn, $teacher_id)[$cid]) || in_array($cid, get_teacher_created_class_ids($conn, $teacher_id), true);
        }
        return false;
    }
}
// 可管理（改名/覆盖/删除）某库模板：创建人（与作业项目「创建人」口径一致）
if (!function_exists('can_manage_omr_lib')) {
    function can_manage_omr_lib($conn, $teacher_id, $tpl) {
        return intval($tpl['created_by'] ?? 0) === intval($teacher_id);
    }
}

// 是否可操作（登记/评价/查看）某项目
if (!function_exists('can_operate_project')) {
    function can_operate_project($conn, $teacher_id, $project) {
        if (is_super_admin($conn, $teacher_id)) return true;
        // 多校隔离：项目必须属于教师所在学校
        $pschool = intval($project['school_id'] ?? 0);
        if ($pschool > 0 && $pschool !== get_teacher_school_id($conn, $teacher_id)) return false;
        $scope = $project['scope'];
        if ($scope === 'school') {
            // 全校项目：凭班级授权码加入的成员可操作（单用户版：无角色教师判定，角色体系已移除）
            return count(get_teacher_class_member_map($conn, $teacher_id)) > 0;
        }
        $member_map = get_teacher_class_member_map($conn, $teacher_id);
        // 班级授权成员：manage=等同班主任（建/改/删全部项目+登记） create=可建立（建/改全部项目、删自己项目、登记）
        // register=等同班长（仅登记） view=不可登记（走只读查看）
        $member_op_ids = [];
        foreach ($member_map as $c => $perm) {
            if ($perm === 'manage' || $perm === 'create' || $perm === 'register') $member_op_ids[] = intval($c);
        }

        if ($scope === 'class') {
            $cid = intval($project['class_id']);
            if (in_array($cid, $member_op_ids, true)) return true;
            // 班级创建者（含个人账号）可操作本班项目
            return in_array($cid, get_teacher_created_class_ids($conn, $teacher_id), true);
        }
        return false;
    }
}

// 是否可查看某项目（可操作者必可查看；班级授权 view 级别成员可只读查看）
if (!function_exists('can_view_project')) {
    function can_view_project($conn, $teacher_id, $project) {
        if (can_operate_project($conn, $teacher_id, $project)) return true;
        if (is_super_admin($conn, $teacher_id)) return true;
        $pschool = intval($project['school_id'] ?? 0);
        if ($pschool > 0 && $pschool !== get_teacher_school_id($conn, $teacher_id)) return false;
        $member_map = get_teacher_class_member_map($conn, $teacher_id);
        if (!$member_map) return false;
        // 项目覆盖的班级中有任一为我授权可见（view 或以上）的班级即可查看
        foreach (get_project_class_ids($conn, $project) as $cid) {
            if (isset($member_map[$cid])) return true;
        }
        return false;
    }
}

// 取项目并校验权限：$mode = operate（登记/评价）| manage（编辑/删除）| view（查看统计）
if (!function_exists('get_project_for')) {
    function get_project_for($conn, $project_id, $teacher_id, $mode = 'operate') {
        $stmt = mysqli_prepare($conn, "SELECT p.*,
                c.name AS class_name
                FROM projects p
                LEFT JOIN classes c ON p.class_id = c.id
                WHERE p.id = ? AND p.deleted_at IS NULL");
        mysqli_stmt_bind_param($stmt, "i", $project_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $project = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
        if (!$project) return null;
        if ($mode === 'manage') {
            // 多校隔离：项目必须属于当前学校（总管理员除外）
            $pschool = intval($project['school_id'] ?? 0);
            if (!is_super_admin($conn, $teacher_id) && $pschool > 0 && $pschool !== get_teacher_school_id($conn, $teacher_id)) return null;
            if (intval($project['created_by']) === intval($teacher_id)) {
                // 仅登记/仅查看授权成员：按权限矩阵无建立项目的管理权限，名下历史创建的项目亦不放开（班级授权级别优先于创建人身份）
                $cid0 = intval($project['class_id'] ?? 0);
                if ($cid0 > 0 && in_array(get_teacher_class_member_map($conn, $teacher_id)[$cid0] ?? '', ['register', 'view'], true)) return null;
                return $project;
            }
            // 班级授权成员：manage/create 级别可编辑（改名/复制/导入）本班项目；删除范围另行限制（create 仅自己创建的）
            $cid = intval($project['class_id'] ?? 0);
            if ($cid > 0 && in_array(get_teacher_class_member_map($conn, $teacher_id)[$cid] ?? '', ['manage', 'create'], true)) return $project;
            return null;
        }
        if ($mode === 'view') {
            // 只读查看：可操作者或班级授权 view 级别成员
            return can_view_project($conn, $teacher_id, $project) ? $project : null;
        }
        return can_operate_project($conn, $teacher_id, $project) ? $project : null;
    }
}

// 教师可见的项目列表（含统计）
if (!function_exists('get_visible_projects')) {
    function get_visible_projects($conn, $teacher_id) {
        $stmt = mysqli_prepare($conn, "SELECT p.*, c.name AS class_name,
                (SELECT COUNT(*) FROM records r WHERE r.project_id = p.id AND r.registered = 1) AS registered_count
                FROM projects p
                LEFT JOIN classes c ON p.class_id = c.id
                WHERE p.school_id = ? AND p.deleted_at IS NULL
                ORDER BY p.id DESC");
        $school_id = get_teacher_school_id($conn, $teacher_id);
        mysqli_stmt_bind_param($stmt, "i", $school_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $rows = [];
        while ($row = mysqli_fetch_assoc($res)) {
            // 可操作或授权只读查看的项目均列入可见列表
            if (can_operate_project($conn, $teacher_id, $row) || can_view_project($conn, $teacher_id, $row)) $rows[] = $row;
        }
        mysqli_stmt_close($stmt);
        return $rows;
    }
}

// ============================================================
// ===== 回收站（软删除）与历史数据导入 =====
// ============================================================

// 导入用：解析单行 CSV（自定义实现，PHP7.3 str_getcsv 对中文+逗号解析不稳定）
if (!function_exists('parse_csv_line')) {
    function parse_csv_line($line) {
        $cells = [];
        $n = strlen($line); $cur = ''; $in_q = false;
        for ($i = 0; $i < $n; $i++) {
            $ch = $line[$i];
            if ($in_q) {
                if ($ch === '"') {
                    if ($i + 1 < $n && $line[$i + 1] === '"') { $cur .= '"'; $i++; } // 转义引号
                    else $in_q = false;
                } else {
                    $cur .= $ch;
                }
            } elseif ($ch === '"') {
                $in_q = true;
            } elseif ($ch === ',') {
                $cells[] = $cur; $cur = '';
            } else {
                $cur .= $ch;
            }
        }
        $cells[] = $cur;
        return $cells;
    }
}

// 读取上传的 CSV/TXT 文件内容（自动处理编码：非 UTF-8 按 GBK 转换；去 BOM）；失败返回 null
if (!function_exists('read_upload_csv')) {
    function read_upload_csv($file_key) {
        if (empty($_FILES[$file_key]) || !is_uploaded_file($_FILES[$file_key]['tmp_name'] ?? '')) return null;
        if ($_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) return null;
        if ($_FILES[$file_key]['size'] > 5 * 1024 * 1024) return null; // 5MB 上限
        $name = strtolower(strval($_FILES[$file_key]['name'] ?? ''));
        if ($name !== '' && !preg_match('/\.(csv|txt)$/', $name)) return null;
        $content = file_get_contents($_FILES[$file_key]['tmp_name']);
        if ($content === false || $content === '') return null;
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") $content = substr($content, 3);
        if (!mb_check_encoding($content, 'UTF-8')) $content = mb_convert_encoding($content, 'UTF-8', 'GBK');
        return $content;
    }
}

// 输出 CSV 下载（UTF-8 BOM，Excel 直接打开不乱码）；调用方负责权限校验并自行 exit
if (!function_exists('download_csv')) {
    function download_csv($filename, $lines) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        echo "\xEF\xBB\xBF";
        foreach ($lines as $line) echo $line . "\r\n";
        exit();
    }
}

// 回收站权限：软删除班级的恢复/彻底删除（班级创建人）
if (!function_exists('can_recycle_class')) {
    function can_recycle_class($conn, $teacher_id, $class_id) {
        $stmt = mysqli_prepare($conn, "SELECT teacher_id FROM classes WHERE id = ? AND deleted_at IS NOT NULL");
        mysqli_stmt_bind_param($stmt, "i", $class_id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        return $row && intval($row['teacher_id']) === intval($teacher_id);
    }
}

// 回收站权限：软删除项目的恢复/彻底删除（项目创建人，或班级授权-管理级别成员对本班项目）
if (!function_exists('can_recycle_project')) {
    function can_recycle_project($conn, $teacher_id, $project_id) {
        $stmt = mysqli_prepare($conn, "SELECT created_by, class_id FROM projects WHERE id = ? AND deleted_at IS NOT NULL");
        mysqli_stmt_bind_param($stmt, "i", $project_id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$row) return false;
        if (intval($row['created_by']) === intval($teacher_id)) return true;
        $cid = intval($row['class_id']);
        return $cid > 0 && (get_teacher_class_member_map($conn, $teacher_id)[$cid] ?? '') === 'manage';
    }
}

// 敏感操作登录密码校验（重置/删除/彻底删除等：验证当前登录教师本人的登录密码）
if (!function_exists('verify_login_password')) {
    function verify_login_password($conn, $teacher_id, $password) {
        $password = strval($password);
        if ($password === '' || intval($teacher_id) <= 0) return false;
        $stmt = mysqli_prepare($conn, "SELECT password FROM teachers WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", intval($teacher_id));
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        return $row && password_verify($password, $row['password']);
    }
}

// 导入历史登记数据（CSV 文本）。
// $class：班级行（必传）；$fixed_project 非空 = 导入到该指定项目（模板 4 列：编号,姓名,日期,评价值），
//         否则按模板 5 列（项目名称,编号,姓名,日期,评价值）定位项目，项目不存在则自动创建（默认模式）。
// 日期列：打卡(daily)模式项目的每行必填（=某天打卡）；默认(count)模式可选（=登记时间，缺省为现在）。
// 返回 ['ok'=>bool, 'msg'=>string]
if (!function_exists('import_history_csv')) {
    function import_history_csv($conn, $teacher_id, $class, $csv_content, $fixed_project = null) {
        $class_id = intval($class['id']);
        $lines = preg_split('/\r\n|\r|\n/', trim($csv_content));
        $lines = array_values(array_filter($lines, function ($l) { return trim($l) !== ''; }));
        if (!$lines) return ['ok' => false, 'msg' => '文件内容为空'];
        if (count($lines) > 5001) return ['ok' => false, 'msg' => '数据行数超过上限（5000 行）'];

        // 表头行检测（含「项目名称/编号」等列名则跳过）
        $first = parse_csv_line($lines[0]);
        $start = 0;
        if (preg_match('/编号|姓名|项目名称|评价值|日期/', implode('', array_slice($first, 0, 2)))) $start = 1;

        // 目标项目：固定项目 或 按名称定位（缺失自动创建）
        $proj_cache = []; // 项目名 => project row
        if ($fixed_project) {
            $proj_cache['__fixed__'] = $fixed_project;
        }

        // 学生映射（编号/姓名 → 学生id），范围 = 指定项目覆盖班级 或 本班；同键多学生视为歧义
        if ($fixed_project) {
            $covered = get_project_class_ids($conn, $fixed_project);
            if (!$covered) $covered = [$class_id];
        } else {
            $covered = [$class_id];
        }
        $by_no = []; $by_name = [];
        $in = implode(',', array_map('intval', $covered));
        $res = mysqli_query($conn, "SELECT id, name, student_no FROM students WHERE class_id IN ({$in})");
        while ($s = mysqli_fetch_assoc($res)) {
            $sid = intval($s['id']);
            $no = trim(strval($s['student_no'])); $nm = trim(strval($s['name']));
            if ($no !== '' && !isset($by_no[$no])) $by_no[$no] = $sid; elseif ($no !== '') $by_no[$no] = 'AMBIG';
            if ($nm !== '' && !isset($by_name[$nm])) $by_name[$nm] = $sid; elseif ($nm !== '') $by_name[$nm] = 'AMBIG';
        }

        $ok_rows = 0; $skip = 0; $reasons = [];
        $note_skip = function ($why) use (&$skip, &$reasons) {
            $skip++;
            if (count($reasons) < 5 && !in_array($why, $reasons, true)) $reasons[] = $why;
        };
        $now_str = date('Y-m-d H:i:s');

        for ($i = $start; $i < count($lines); $i++) {
            $cells = parse_csv_line($lines[$i]);
            foreach ($cells as &$cv) $cv = trim($cv);
            unset($cv);

            if ($fixed_project) {
                $project = $proj_cache['__fixed__'];
                $no = $cells[0] ?? ''; $nm = $cells[1] ?? ''; $dstr = $cells[2] ?? ''; $eval = $cells[3] ?? '';
            } else {
                $pname = $cells[0] ?? '';
                if ($pname === '') { $note_skip('项目名称为空'); continue; }
                if (!isset($proj_cache[$pname])) {
                    // 定位班级内同名项目（未删除），缺失则自动创建（默认笑脸模式）
                    $stmt = mysqli_prepare($conn, "SELECT * FROM projects WHERE class_id = ? AND name = ? AND deleted_at IS NULL LIMIT 1");
                    mysqli_stmt_bind_param($stmt, "is", $class_id, $pname);
                    mysqli_stmt_execute($stmt);
                    $prow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                    mysqli_stmt_close($stmt);
                    if (!$prow) {
                        // 自动创建：优良模式（与模板示例「优」一致，便于历史数据直接导入）
                        $stmt = mysqli_prepare($conn, "INSERT INTO projects (school_id, class_id, scope, created_by, name, eval_mode, late_seconds, lock_seconds, created_at)
                                                       VALUES (?, ?, 'class', ?, ?, 'grade', 1800, 1800, NOW())");
                        $cschool = intval($class['school_id'] ?? 0);
                        mysqli_stmt_bind_param($stmt, "iiss", $cschool, $class_id, $teacher_id, $pname);
                        mysqli_stmt_execute($stmt);
                        $newid = mysqli_insert_id($conn);
                        mysqli_stmt_close($stmt);
                        $stmt = mysqli_prepare($conn, "SELECT * FROM projects WHERE id = ?");
                        mysqli_stmt_bind_param($stmt, "i", $newid);
                        mysqli_stmt_execute($stmt);
                        $prow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                        mysqli_stmt_close($stmt);
                    }
                    $proj_cache[$pname] = $prow ?: 'FAIL';
                }
                if ($proj_cache[$pname] === 'FAIL') { $note_skip('项目创建失败：' . $pname); continue; }
                $project = $proj_cache[$pname];
                $no = $cells[1] ?? ''; $nm = $cells[2] ?? ''; $dstr = $cells[3] ?? ''; $eval = $cells[4] ?? '';
            }

            // 学生匹配：编号优先，其次姓名
            $entry = null;
            if ($no !== '' && isset($by_no[$no])) $entry = $by_no[$no];
            elseif ($no === '' && $nm !== '' && isset($by_name[$nm])) $entry = $by_name[$nm];
            elseif ($no !== '' && !isset($by_no[$no]) && $nm !== '' && isset($by_name[$nm])) $entry = $by_name[$nm];
            if ($entry === null) { $note_skip('未匹配到学生：' . ($no !== '' ? $no : $nm)); continue; }
            if ($entry === 'AMBIG') { $note_skip('学生信息重复（编号/姓名歧义）：' . ($no !== '' ? $no : $nm)); continue; }

            // 日期：打卡模式项目必填；默认模式可选（登记模式为项目维度）
            $proj_daily = (($project['mode'] ?? 'count') === 'daily');
            $ts = false;
            if ($dstr !== '') {
                $ts = strtotime(str_replace('/', '-', $dstr));
                if ($ts === false || $ts <= 0) { $note_skip('日期无法解析：' . $dstr); continue; }
            }
            if ($proj_daily && $ts === false) { $note_skip('打卡模式缺少日期'); continue; }
            $reg_at = $ts !== false ? date('Y-m-d H:i:s', $ts) : $now_str;

            // 评价值：非空则按项目模式校验
            $eval_value = null;
            if ($eval !== '') {
                if (!validate_eval_value($conn, $project['eval_mode'], $eval)) { $note_skip('评价值不合法（' . ($project['eval_mode']) . '）：' . $eval); continue; }
                $eval_value = $eval;
            }

            // 写入登记记录（已存在则忽略，保证重复导入幂等）
            $pid = intval($project['id']); $sid = intval($entry);
            $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO records (project_id, student_id, registered, registered_at, registered_by, eval_value, eval_at)
                                           VALUES (?, ?, 1, ?, 'import', ?, ?)");
            $ev = $eval_value; $ea = $eval_value !== null ? $reg_at : null;
            mysqli_stmt_bind_param($stmt, "iisss", $pid, $sid, $reg_at, $ev, $ea);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // 打卡模式项目同步维护 record_days
            if ($proj_daily) {
                $reg_date = date('Y-m-d', $ts);
                $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO record_days (project_id, student_id, reg_date, created_at) VALUES (?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt, "iiss", $pid, $sid, $reg_date, $reg_at);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            $ok_rows++;
        }

        $msg = '成功导入 ' . $ok_rows . ' 行';
        if ($skip > 0) $msg .= '，跳过 ' . $skip . ' 行' . ($reasons ? ('（' . implode('；', $reasons) . (count($reasons) >= 5 ? '…' : '') . '）') : '');
        return ['ok' => $ok_rows > 0 || $skip === 0, 'msg' => $msg];
    }
}

// 班级导入二维码载荷：每班一个稳定提取码，名单内容随访问刷新（支持大名单：二维码只编码链接）
if (!function_exists('get_class_import_payload')) {
    function get_class_import_payload($conn, $class_id, $students) {
        $content = build_class_import_content($students);
        $stmt = mysqli_prepare($conn, "SELECT code FROM import_payloads WHERE class_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $class_id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if ($row) {
            $stmt = mysqli_prepare($conn, "UPDATE import_payloads SET content = ?, updated_at = NOW() WHERE class_id = ?");
            mysqli_stmt_bind_param($stmt, "si", $content, $class_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            return ['code' => $row['code'], 'content' => $content];
        }
        // 生成唯一 8 位提取码（与班级码/授权码同格式，数字）
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $cand = strval(rand(10000000, 99999999));
            $stmt = mysqli_prepare($conn, "SELECT id FROM import_payloads WHERE code = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt, "s", $cand);
            mysqli_stmt_execute($stmt);
            $used = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
            mysqli_stmt_close($stmt);
            if (!$used) { $code = $cand; break; }
        }
        if ($code === '') $code = strval(time()); // 兜底（理论上不会发生）
        $stmt = mysqli_prepare($conn, "INSERT INTO import_payloads (class_id, code, content, created_at) VALUES (?, ?, ?, NOW())");
        mysqli_stmt_bind_param($stmt, "iss", $class_id, $code, $content);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return ['code' => $code, 'content' => $content];
    }
}

// 凭提取码或提取链接解析班级导入内容（返回 bjdl 明文；无法识别返回 null）
// 兼容三种输入：提取链接（qr_fetch.php?c=码）、8位提取码（import_payloads）、8位班级码（classes.class_code，按源班级现有名单即时生成）
if (!function_exists('resolve_import_payload')) {
    function resolve_import_payload($conn, $raw) {
        $raw = trim((string)$raw);
        $code = '';
        // 提取链接：qr_fetch.php?c=提取码（兼容粘贴完整 URL）
        if (preg_match('/qr_fetch\.php\?c=([0-9A-Za-z]+)/', $raw, $m)) {
            $code = $m[1];
        } elseif (preg_match('/^[0-9]{8}$/', $raw)) {
            $code = $raw; // 直接输入 8 位提取码 / 班级码
        } else {
            return null;
        }
        $stmt = mysqli_prepare($conn, "SELECT content FROM import_payloads WHERE code = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, "s", $code);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if ($row && $row['content'] !== '') return $row['content'];
        // 提取码未命中：回退「班级码」导入（两者同为 8 位数字易混淆；凭班级码按源班级现有名单即时导出）
        $stmt = mysqli_prepare($conn, "SELECT id FROM classes WHERE class_code = ? AND deleted_at IS NULL LIMIT 1");
        mysqli_stmt_bind_param($stmt, "s", $code);
        mysqli_stmt_execute($stmt);
        $cls = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$cls) return null;
        $src_cid = intval($cls['id']);
        $rows = [];
        $stmt = mysqli_prepare($conn, "SELECT student_no, name, seat_no, remark FROM students WHERE class_id = ? ORDER BY CAST(seat_no AS UNSIGNED) ASC, id ASC");
        mysqli_stmt_bind_param($stmt, "i", $src_cid);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($r = mysqli_fetch_assoc($res)) { $rows[] = $r; }
        mysqli_stmt_close($stmt);
        if (!count($rows)) return null;   // 源班级无学生：无从导入
        return build_class_import_content($rows);
    }
}

if (!function_exists('flash_set')) {
    /** 写入一次性会话提示（配合 PRG 重定向使用，避免刷新重复执行操作） */
    function flash_set($msg, $type = 'success') {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        $_SESSION['flash_msg'] = ['msg' => strval($msg), 'type' => strval($type)];
    }
}

if (!function_exists('flash_take')) {
    /** 读取并清除一次性会话提示（每条提示仅展示一次） */
    function flash_take() {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['flash_msg'])) return null;
        $f = $_SESSION['flash_msg'];
        unset($_SESSION['flash_msg']);
        return $f;
    }
}

// ============================================================
// ===== 项目自动积分规则（points_rules 表 + auto_points_sync 引擎） =====
// 语义：source=2 流水始终 = 「当前启用规则 × 当前登记/评价数据」的全量重算结果 ——
//       修改规则后保存即强制重建（已按旧规则加的分自动回档、未应用的直接补足）；
//       打卡模式按每天、题次模式按每题次，各自独立按规则生成一条加减分。
// ============================================================
if (!function_exists('auto_points_ensure_schema')) {
    /** 幂等建表/补列：points_rules + points_log.project_id/source */
    function auto_points_ensure_schema($conn) {
        static $done = false;
        if ($done) return;
        $done = true;
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS points_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL COMMENT '所属项目',
            rtype VARCHAR(30) NOT NULL COMMENT '规则类型（见 points_rule_types_meta）',
            opt VARCHAR(50) NOT NULL DEFAULT '' COMMENT '规则参数（eval_opt=匹配评价内容；阈值类=数字阈值）',
            value INT NOT NULL DEFAULT 0 COMMENT '分值（正=加分 负=扣分）',
            enabled TINYINT NOT NULL DEFAULT 1 COMMENT '是否启用（1=启用 0=停用）',
            sort INT NOT NULL DEFAULT 0 COMMENT '排序（小在前）',
            INDEX idx_project (project_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='项目自动积分规则（auto_points_sync 按当前数据全量重建 source=2 流水）'");
        $cols = [
            'project_id' => "ALTER TABLE points_log ADD COLUMN project_id INT NOT NULL DEFAULT 0 COMMENT '关联项目（0=手动积分，>0=项目自动规则生成）' AFTER created_at",
            'source'     => "ALTER TABLE points_log ADD COLUMN source TINYINT NOT NULL DEFAULT 1 COMMENT '来源（1=手动 2=项目自动规则 3=导入/批量）' AFTER project_id",
            'student_no' => "ALTER TABLE points_log ADD COLUMN student_no VARCHAR(50) NOT NULL DEFAULT '' COMMENT '学生编号快照（删除学生后同编号新增自动找回积分）' AFTER source",
        ];
        foreach ($cols as $col => $ddl) {
            $r = mysqli_query($conn, "SHOW COLUMNS FROM points_log LIKE '" . $col . "'");
            if ($r && mysqli_num_rows($r) === 0) mysqli_query($conn, $ddl);
        }
        $r = mysqli_query($conn, "SHOW INDEX FROM points_log WHERE Key_name = 'idx_pt_project'");
        if ($r && mysqli_num_rows($r) === 0) mysqli_query($conn, "ALTER TABLE points_log ADD INDEX idx_pt_project (project_id, source)");
        // 🎁 积分兑换礼品（按班级配置）
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS points_gifts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL COMMENT '所属班级（按班配置）',
            school_id INT NOT NULL DEFAULT 0 COMMENT '所属学校（冗余快照）',
            name VARCHAR(50) NOT NULL COMMENT '礼品名称',
            cost INT NOT NULL DEFAULT 1 COMMENT '兑换所需积分',
            enabled TINYINT NOT NULL DEFAULT 1 COMMENT '是否启用（1=启用 0=下架）',
            sort INT NOT NULL DEFAULT 0 COMMENT '排序（小在前）',
            INDEX idx_class (class_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='积分兑换礼品（兑换=自动扣分并记流水）'");
        // 一次性回填存量流水的编号快照（升级后首个请求执行，全局仅一次）
        $r = mysqli_query($conn, "SELECT svalue FROM settings WHERE skey = 'ptlog_snap_backfill'");
        if ($r && mysqli_num_rows($r) === 0) {
            mysqli_query($conn, "UPDATE points_log pl JOIN students s ON s.id = pl.student_id
                                 SET pl.student_no = s.student_no WHERE pl.student_no = '' AND s.student_no <> ''");
            mysqli_query($conn, "INSERT INTO settings (skey, svalue) VALUES ('ptlog_snap_backfill', '1')");
        }
    }
}

if (!function_exists('points_rule_types_meta')) {
    /**
     * 自动规则类型定义（$mode 传入时仅返回该登记模式可用的类型）
     * param：none=无参数 opt=评价内容 num=数字阈值；need_late=1 表示需项目补登记阈值>0
     */
    function points_rule_types_meta($mode = '') {
        $all = [
            'reg_ok'        => ['name' => '登记成功', 'modes' => ['count', 'daily', 'multi', 'quiz', 'raise', 'omr'], 'param' => 'none', 'desc' => '每登记成功 1 次（打卡=每天 / 题次模式=每题次 / 答题卡=每题次识别匹配）按分值加/减分'],
            'timeout_miss'  => ['name' => '超时未登记', 'modes' => ['count', 'daily', 'multi', 'quiz', 'raise', 'omr'], 'param' => 'none', 'need_late' => 1, 'desc' => '该范围首位登记超过补登记阈值后仍未登记的，按分值扣分（需项目补登记阈值>0；该范围无人登记则不判定）'],
            'eval_done'     => ['name' => '已评价', 'modes' => ['count', 'daily', 'multi', 'quiz', 'raise', 'omr'], 'param' => 'none', 'desc' => '每有 1 条评价/批改记录按分值加/减分（答题卡模式=已批改出分）'],
            'eval_opt'      => ['name' => '评价为指定内容', 'modes' => ['count', 'daily', 'multi', 'quiz', 'raise'], 'param' => 'opt', 'desc' => '评价内容等于指定值时按分值加/减分'],
            'quiz_right'    => ['name' => '答题正确', 'modes' => ['quiz', 'raise'], 'param' => 'none', 'desc' => '题次已设正确答案且所选与之相符时按分值加/减分'],
            'quiz_wrong'    => ['name' => '答题错误', 'modes' => ['quiz', 'raise'], 'param' => 'none', 'desc' => '题次已设正确答案且所选不符时按分值加/减分'],
            'omr_per_right' => ['name' => '答对每题加分', 'modes' => ['omr'], 'param' => 'none', 'desc' => '每答对 1 题加/减分（=得分分子×分值，四舍五入；题数制=对题数，分值制=得分）'],
            'omr_right_ge'  => ['name' => '答对达到阈值', 'modes' => ['omr'], 'param' => 'num', 'desc' => '答对题数/得分 ≥ 阈值时按分值加/减分'],
            'omr_wrong_ge'  => ['name' => '答错达到阈值', 'modes' => ['omr'], 'param' => 'num', 'desc' => '答错数（分母-分子）≥ 阈值时按分值加/减分'],
            'omr_score_lt'  => ['name' => '得分低于阈值', 'modes' => ['omr'], 'param' => 'num', 'desc' => '答对题数/得分 < 阈值时按分值加/减分'],
        ];
        if ($mode === '') return $all;
        $out = [];
        foreach ($all as $k => $m) {
            if (in_array($mode, $m['modes'], true)) $out[$k] = $m;
        }
        return $out;
    }
}

if (!function_exists('points_rule_display_name')) {
    /** 规则流水显示名（item_name 快照 + 积分项维度名） */
    function points_rule_display_name($rtype, $opt = '') {
        $meta = points_rule_types_meta();
        $m = isset($meta[$rtype]) ? $meta[$rtype] : null;
        $name = $m ? $m['name'] : strval($rtype);
        $opt = trim(strval($opt));
        if ($rtype === 'eval_opt' && $opt !== '') $name = '评价为「' . mb_substr($opt, 0, 20) . '」';
        elseif ($rtype === 'omr_right_ge' && $opt !== '') $name = '答对≥' . intval($opt) . '题';
        elseif ($rtype === 'omr_wrong_ge' && $opt !== '') $name = '答错≥' . intval($opt) . '题';
        elseif ($rtype === 'omr_score_lt' && $opt !== '') $name = '得分<' . intval($opt);
        return mb_substr($name, 0, 40);
    }
}

if (!function_exists('auto_points_sync')) {
    /**
     * 项目自动积分同步（懒触发 + 指纹比对 + 全量重建）
     * $project：projects 全行（须含 id/mode/school_id/scope/class_id/late_seconds）
     * $force：true = 跳过指纹比对强制重建（保存规则后调用）
     */
    function auto_points_sync($conn, $project, $force = false) {
        if (!$project || intval($project['id']) <= 0) return;
        auto_points_ensure_schema($conn);
        $pid = intval($project['id']);
        $mode = strval($project['mode'] ?? 'count');
        $is_omr = ($mode === 'omr');
        $late = intval($project['late_seconds'] ?? 0);
        $now_ts = time();

        // 1) 启用规则（按当前模式过滤；value=0 / 参数缺失的规则忽略）
        $rules = [];
        $res = mysqli_query($conn, "SELECT id, rtype, opt, value FROM points_rules WHERE project_id = {$pid} AND enabled = 1 ORDER BY sort ASC, id ASC");
        while ($r = mysqli_fetch_assoc($res)) {
            $r['id'] = intval($r['id']);
            $r['value'] = intval($r['value']);
            $rules[] = $r;
        }
        $meta = points_rule_types_meta();
        $rules = array_values(array_filter($rules, function ($r) use ($meta, $mode) {
            $m = isset($meta[$r['rtype']]) ? $meta[$r['rtype']] : null;
            if (!$m || !in_array($mode, $m['modes'], true) || intval($r['value']) === 0) return false;
            $param = isset($m['param']) ? $m['param'] : 'none';
            if ($param === 'opt' && trim(strval($r['opt'])) === '') return false;
            if ($param === 'num' && !is_numeric(trim(strval($r['opt'])))) return false;
            return true;
        }));

        // 2) 无启用规则：清掉该项目自动流水并清指纹
        if (!count($rules)) {
            mysqli_query($conn, "DELETE FROM points_log WHERE source = 2 AND project_id = {$pid}");
            set_setting($conn, 'ptauto_fp_' . $pid, '');
            return;
        }

        // 3) 学生基础集（超时未登记判定范围；class_id/school_id/编号快照进流水）
        $students = [];   // sid => [class_id, school_id, no]
        $class_ids = get_project_class_ids($conn, $project);
        if (count($class_ids)) {
            $in = implode(',', array_map('intval', $class_ids));
            $res = mysqli_query($conn, "SELECT s.id, s.class_id, c.school_id, s.student_no FROM students s
                                        JOIN classes c ON c.id = s.class_id
                                        WHERE s.class_id IN ({$in}) AND s.disabled = 0");
            while ($s = mysqli_fetch_assoc($res)) {
                $students[intval($s['id'])] = ['class_id' => intval($s['class_id']), 'school_id' => intval($s['school_id']), 'no' => strval($s['student_no'])];
            }
        }

        // 4) 按模式收集登记/评价行 + 各范围单位（截止线/已登记名单/备注标签）
        //    $rows：每条登记/评价 → [sid, at, eval, round, score, label]
        //    $units：范围单位 => ['label'=>备注, 'first_ts'=>首位登记时间戳(0=无), 'reg'=>[已登记sid...]]
        $rows = [];
        $units = [];
        $correct = [];   // round_no => [正确选项大写...]（quiz/raise）
        if ($mode === 'count') {
            $res = mysqli_query($conn, "SELECT student_id, registered, registered_at, eval_value FROM records WHERE project_id = {$pid}");
            while ($r = mysqli_fetch_assoc($res)) {
                if (intval($r['registered']) !== 1) continue;
                $sid = intval($r['student_id']);
                $rows[] = ['sid' => $sid, 'at' => strval($r['registered_at']), 'eval' => strval($r['eval_value'] ?? ''), 'round' => 0, 'score' => '', 'label' => '登记'];
                $units[1]['reg'][] = $sid;
            }
            $units[1]['label'] = '登记';
            $fr = mysqli_fetch_assoc(mysqli_query($conn, "SELECT MIN(registered_at) AS f FROM records WHERE project_id = {$pid} AND registered = 1"));
            $units[1]['first_ts'] = ($fr && $fr['f']) ? strtotime($fr['f']) : 0;
        } elseif ($mode === 'daily') {
            $res = mysqli_query($conn, "SELECT student_id, reg_date, created_at, eval_value FROM record_days WHERE project_id = {$pid}");
            while ($r = mysqli_fetch_assoc($res)) {
                $d = strval($r['reg_date']);
                $sid = intval($r['student_id']);
                $rows[] = ['sid' => $sid, 'at' => strval($r['created_at']), 'eval' => strval($r['eval_value'] ?? ''), 'round' => 0, 'score' => '', 'label' => $d];
                $units[$d]['reg'][] = $sid;
                $units[$d]['label'] = $d;
            }
            $res = mysqli_query($conn, "SELECT reg_date, MIN(created_at) AS f FROM record_days WHERE project_id = {$pid} GROUP BY reg_date");
            while ($r = mysqli_fetch_assoc($res)) {
                $d = strval($r['reg_date']);
                if (!isset($units[$d])) $units[$d] = ['label' => $d, 'reg' => []];
                $units[$d]['first_ts'] = $r['f'] ? strtotime($r['f']) : 0;
            }
        } elseif ($is_omr) {
            $res = mysqli_query($conn, "SELECT student_id, round_no, score, created_at, updated_at FROM omr_results WHERE project_id = {$pid} AND student_id IS NOT NULL");
            while ($r = mysqli_fetch_assoc($res)) {
                $rn = intval($r['round_no']);
                $sid = intval($r['student_id']);
                $rows[] = ['sid' => $sid, 'at' => strval($r['updated_at']), 'eval' => '', 'round' => $rn, 'score' => strval($r['score']), 'label' => '第' . $rn . '题次'];
                $units[$rn]['reg'][] = $sid;
                $units[$rn]['label'] = '第' . $rn . '题次';
            }
            $res = mysqli_query($conn, "SELECT round_no, MIN(created_at) AS f FROM omr_results WHERE project_id = {$pid} AND student_id IS NOT NULL GROUP BY round_no");
            while ($r = mysqli_fetch_assoc($res)) {
                $rn = intval($r['round_no']);
                if (!isset($units[$rn])) $units[$rn] = ['label' => '第' . $rn . '题次', 'reg' => []];
                $units[$rn]['first_ts'] = $r['f'] ? strtotime($r['f']) : 0;
            }
        } else { // multi / quiz / raise
            $res = mysqli_query($conn, "SELECT student_id, round_no, registered_at, eval_value FROM record_rounds WHERE project_id = {$pid} AND registered_at IS NOT NULL");
            while ($r = mysqli_fetch_assoc($res)) {
                $rn = intval($r['round_no']);
                $sid = intval($r['student_id']);
                $rows[] = ['sid' => $sid, 'at' => strval($r['registered_at']), 'eval' => strval($r['eval_value'] ?? ''), 'round' => $rn, 'score' => '', 'label' => '第' . $rn . '题次'];
                $units[$rn]['reg'][] = $sid;
                $units[$rn]['label'] = '第' . $rn . '题次';
            }
            $res = mysqli_query($conn, "SELECT round_no, MIN(registered_at) AS f FROM record_rounds WHERE project_id = {$pid} AND registered_at IS NOT NULL GROUP BY round_no");
            while ($r = mysqli_fetch_assoc($res)) {
                $rn = intval($r['round_no']);
                if (!isset($units[$rn])) $units[$rn] = ['label' => '第' . $rn . '题次', 'reg' => []];
                $units[$rn]['first_ts'] = $r['f'] ? strtotime($r['f']) : 0;
            }
        }
        if ($mode === 'quiz' || $mode === 'raise') {
            $res = mysqli_query($conn, "SELECT round_no, correct_opts FROM project_rounds WHERE project_id = {$pid} AND correct_opts IS NOT NULL AND correct_opts <> ''");
            while ($r = mysqli_fetch_assoc($res)) {
                $opts = array_values(array_filter(array_map('strtoupper', array_map('trim', explode(',', strval($r['correct_opts']))))));
                if (count($opts)) $correct[intval($r['round_no'])] = $opts;
            }
        }

        // 5) 超时未登记行（仅当启用 timeout_miss 且补登记阈值>0；该范围无人登记不判定）
        $has_timeout = false;
        foreach ($rules as $r) {
            if ($r['rtype'] === 'timeout_miss') $has_timeout = true;
        }
        $timeout_rows = [];
        $passed_cnt = 0;   // 已过截止线的范围单位数（时间推移→增长→指纹变化→自动重算）
        if ($has_timeout && $late > 0) {
            foreach ($units as $u) {
                if (empty($u['first_ts'])) continue;
                $dts = intval($u['first_ts']) + $late;
                if ($now_ts <= $dts) continue;
                $passed_cnt++;
                $reg = (isset($u['reg']) && is_array($u['reg'])) ? array_flip($u['reg']) : [];
                $at = date('Y-m-d H:i:s', $dts);
                foreach ($students as $sid => $sinfo) {
                    if (!isset($reg[$sid])) $timeout_rows[] = ['sid' => $sid, 'at' => $at, 'label' => $u['label']];
                }
            }
        }

        // 6) 指纹 = 规则 + 学生集 + 数据行 + 正确答案 + 各单位截止线 + 已过线数；比对一致则无需重建
        $fp_rows = [];
        foreach ($rows as $r) $fp_rows[] = [$r['sid'], $r['at'], $r['eval'], $r['score'], $r['round']];
        $fp_src = [
            'rules' => array_map(function ($r) { return [$r['id'], $r['rtype'], $r['opt'], $r['value']]; }, $rules),
            'students' => array_keys($students),
            'rows' => $fp_rows,
            'correct' => $correct,
            'first' => array_map(function ($u) { return intval(isset($u['first_ts']) ? $u['first_ts'] : 0); }, $units),
            'passed' => $passed_cnt,
        ];
        $fp = md5(json_encode($fp_src));
        if (!$force && get_setting($conn, 'ptauto_fp_' . $pid, '') === $fp) return;

        // 7) 全量重建：删旧 source=2 流水 → 按规则重插（created_at=实际登记/评价时间，超时=截止时刻）
        mysqli_query($conn, "DELETE FROM points_log WHERE source = 2 AND project_id = {$pid}");
        $ins = mysqli_prepare($conn, "INSERT INTO points_log (school_id, class_id, student_id, student_no, item_id, item_name, value, remark, created_by, created_at, project_id, source)
                                      VALUES (?, ?, ?, ?, 0, ?, ?, ?, 0, ?, {$pid}, 2)");
        $gen = function ($rtype, $opt, $value, $sid, $remark, $at) use ($ins, $students) {
            if (!isset($students[$sid])) return;   // 学生已不在项目覆盖班级（转出/禁用）：跳过
            $sinfo = $students[$sid];
            $si = $sinfo['school_id'];
            $ci = $sinfo['class_id'];
            $sno = $sinfo['no'];
            $nm = points_rule_display_name($rtype, $opt);
            $rk = mb_substr(strval($remark), 0, 200);
            $ats = strval($at);
            mysqli_stmt_bind_param($ins, "iiississ", $si, $ci, $sid, $sno, $nm, $value, $rk, $ats);
            mysqli_stmt_execute($ins);
        };

        foreach ($rules as $rule) {
            $rtype = $rule['rtype'];
            $opt = trim(strval($rule['opt']));
            $value = intval($rule['value']);
            if ($rtype === 'timeout_miss') {
                foreach ($timeout_rows as $tr) $gen($rtype, $opt, $value, $tr['sid'], $tr['label'], $tr['at']);
                continue;
            }
            foreach ($rows as $r) {
                $hit = false;
                $v = $value;
                switch ($rtype) {
                    case 'reg_ok':
                        $hit = true;
                        break;
                    case 'eval_done':
                        $hit = ($r['eval'] !== '' || ($is_omr && $r['score'] !== ''));
                        break;
                    case 'eval_opt':
                        $hit = ($r['eval'] !== '' && trim($r['eval']) === $opt);
                        break;
                    case 'quiz_right':
                        $hit = (isset($correct[$r['round']]) && $r['eval'] !== '' && in_array(strtoupper(trim($r['eval'])), $correct[$r['round']], true));
                        break;
                    case 'quiz_wrong':
                        $hit = (isset($correct[$r['round']]) && $r['eval'] !== '' && !in_array(strtoupper(trim($r['eval'])), $correct[$r['round']], true));
                        break;
                    case 'omr_per_right':
                        if ($r['score'] === '' || strpos($r['score'], '/') === false) break;
                        $pp = explode('/', $r['score']);
                        $v = intval(round(floatval($pp[0]) * $value));
                        $hit = ($v !== 0);
                        break;
                    case 'omr_right_ge':
                        if ($r['score'] === '' || strpos($r['score'], '/') === false) break;
                        $pp = explode('/', $r['score']);
                        $hit = (floatval($pp[0]) >= floatval($opt));
                        break;
                    case 'omr_wrong_ge':
                        if ($r['score'] === '' || strpos($r['score'], '/') === false) break;
                        $pp = explode('/', $r['score']);
                        $hit = ((floatval($pp[1] ?? 0) - floatval($pp[0])) >= floatval($opt));
                        break;
                    case 'omr_score_lt':
                        if ($r['score'] === '' || strpos($r['score'], '/') === false) break;
                        $pp = explode('/', $r['score']);
                        $hit = (floatval($pp[0]) < floatval($opt));
                        break;
                }
                if ($hit && $v !== 0) $gen($rtype, $opt, $v, $r['sid'], $r['label'], $r['at']);
            }
        }
        mysqli_stmt_close($ins);
        set_setting($conn, 'ptauto_fp_' . $pid, $fp);
    }
}

if (!function_exists('verify_teacher_password')) {
    /** 敏感操作（积分导入/清空等）防误触：校验当前教师登录密码，失败直接终止响应 */
    function verify_teacher_password($conn, $teacher_id, $password) {
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT password FROM teachers WHERE id = " . intval($teacher_id)));
        if (!$row || !password_verify(strval($password), strval($row['password']))) {
            json_response(['success' => false, 'message' => '密码验证失败：请输入当前账号的登录密码']);
        }
    }
}

if (!function_exists('points_gifts_load')) {
    /** 本班可兑换礼品列表（enabled=1，按 sort 升序） */
    function points_gifts_load($conn, $class_id) {
        $gifts = [];
        $res = mysqli_query($conn, "SELECT id, name, cost FROM points_gifts WHERE class_id = " . intval($class_id) . " AND enabled = 1 ORDER BY sort ASC, id ASC");
        while ($g = mysqli_fetch_assoc($res)) {
            $gifts[] = ['id' => intval($g['id']), 'name' => strval($g['name']), 'cost' => intval($g['cost'])];
        }
        return $gifts;
    }
}

if (!function_exists('points_recover_orphan')) {
    /**
     * 删除学生后同编号新增自动找回积分（points_data 懒触发）：
     * 把「编号快照 = 本班某新学生编号 + 原学生已删除/已禁用 + 新学生尚无任何流水」的孤儿流水改挂到新学生名下。
     * 只认编号不认姓名（与手工导入一致）；学生仍存在（如转班）时不并，防误并他人流水。
     */
    function points_recover_orphan($conn, $class_id) {
        auto_points_ensure_schema($conn);
        $class_id = intval($class_id);
        // 本班现有学生：编号 => id（重复编号取先加入者）；已有流水的学生不能作为找回目标
        $by_no = [];
        $res = mysqli_query($conn, "SELECT id, student_no FROM students WHERE class_id = {$class_id} ORDER BY id ASC");
        while ($s = mysqli_fetch_assoc($res)) {
            $no = trim(strval($s['student_no']));
            if ($no !== '' && !isset($by_no[$no])) $by_no[$no] = intval($s['id']);
        }
        if (!count($by_no)) return 0;
        $with = [];   // 已有流水的学生（找回目标须为"新学生"：尚无任何流水）
        $res = mysqli_query($conn, "SELECT DISTINCT student_id FROM points_log WHERE class_id = {$class_id}");
        while ($r = mysqli_fetch_assoc($res)) $with[intval($r['student_id'])] = true;
        $targets = [];
        foreach ($by_no as $no => $sid) {
            if (empty($with[$sid])) $targets[$no] = $sid;
        }
        if (!count($targets)) return 0;
        // 孤儿流水分组：student_id 已不在 students 表（被硬删）或已禁用，且编号快照非空
        $moved = 0;
        $res = mysqli_query($conn, "SELECT pl.student_id, pl.student_no
                                    FROM points_log pl LEFT JOIN students os ON os.id = pl.student_id
                                    WHERE pl.class_id = {$class_id} AND pl.student_no <> '' AND (os.id IS NULL OR os.disabled = 1)
                                    GROUP BY pl.student_id, pl.student_no");
        while ($r = mysqli_fetch_assoc($res)) {
            $no = trim(strval($r['student_no']));
            if (!isset($targets[$no])) continue;
            $old = intval($r['student_id']);
            $new = $targets[$no];
            mysqli_query($conn, "UPDATE points_log SET student_id = {$new} WHERE class_id = {$class_id} AND student_id = {$old}");
            $moved++;
        }
        return $moved;
    }
}

if (!function_exists('points_import_apply')) {
    /**
     * 积分导入核心（供 api 调用；登录密码校验在 api 层做）
     * $kind：balance=最终积分（每生一条「积分导入」起始分，之后可继续增减） / log=过程流水（按原时间逐条回放，最终积分=流水累计）
     * $content：CSV 文本（逗号或制表符分隔，支持引号转义）；返回 ['added'=>成功条数, 'skips'=>['第N行：原因', …]]
     * 学生匹配：编号精确匹配优先 → 姓名匹配（本班重名视为歧义不匹配）
     */
    function points_import_apply($conn, $class_id, $kind, $content, $clear_first = false, $teacher_id = 0) {
        auto_points_ensure_schema($conn);
        $class_id = intval($class_id);
        $kind = ($kind === 'log') ? 'log' : 'balance';
        $content = str_replace("\r\n", "\n", strval($content));
        $content = str_replace("\r", "\n", $content);
        if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) $content = substr($content, 3);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $content)), function ($l) { return $l !== ''; }));
        // 自动跳过表头行
        if (count($lines) && mb_strpos($lines[0], '姓名') !== false && (mb_strpos($lines[0], '积分') !== false || mb_strpos($lines[0], '分值') !== false)) {
            array_shift($lines);
        }
        if (!count($lines)) return ['added' => 0, 'skips' => ['没有可导入的数据行']];

        // 本班未禁用学生匹配映射 + id=>编号快照
        $by_no = [];
        $by_name = [];
        $sno = [];
        $name_cnt = [];
        $res = mysqli_query($conn, "SELECT id, student_no, name FROM students WHERE class_id = {$class_id} AND disabled = 0 ORDER BY id ASC");
        while ($s = mysqli_fetch_assoc($res)) {
            $sid = intval($s['id']);
            $no = trim(strval($s['student_no']));
            $nm = trim(strval($s['name']));
            $sno[$sid] = $no;
            if ($no !== '' && !isset($by_no[$no])) $by_no[$no] = $sid;
            $name_cnt[$nm] = (isset($name_cnt[$nm]) ? $name_cnt[$nm] : 0) + 1;
            if ($name_cnt[$nm] === 1) $by_name[$nm] = $sid; else unset($by_name[$nm]);
        }
        if ($clear_first) mysqli_query($conn, "DELETE FROM points_log WHERE class_id = {$class_id}");

        // 逐行解析（先收集后插入，任一行格式错不影响其余行）
        $rows = [];   // [sid, item_name, value, remark, created_at, lineno]
        $skips = [];
        foreach ($lines as $i => $line) {
            $lineno = $i + 1;
            $f = (strpos($line, "\t") !== false)
                ? array_map('trim', explode("\t", $line))
                : array_map('trim', str_getcsv($line));
            $f = array_values(array_filter($f, function ($v) { return $v !== null; }));
            if ($kind === 'balance') {
                $val = array_pop($f);
                if ($val === null || !is_numeric($val)) { $skips[] = "第{$lineno}行：分值「{$val}」不是数字"; continue; }
                $value = intval($val);
                if ($value === 0) { $skips[] = "第{$lineno}行：分值为 0 已跳过"; continue; }
                if (count($f) >= 2) { $no = $f[0]; $nm = $f[1]; }   // 编号,姓名[,座号]
                elseif (count($f) === 1) { $no = ''; $nm = $f[0]; } // 姓名,积分
                else { $skips[] = "第{$lineno}行：缺少学生信息"; continue; }
            } else {
                if (count($f) < 5) { $skips[] = "第{$lineno}行：列数不足（时间,姓名,编号,积分项,分值[,备注]）"; continue; }
                $ts = strtotime($f[0]);
                $created = $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
                $nm = $f[1];
                $no = $f[2];
                $item = mb_substr($f[3], 0, 50);
                if (!is_numeric($f[4])) { $skips[] = "第{$lineno}行：分值「{$f[4]}」不是数字"; continue; }
                $value = intval($f[4]);
                if ($value === 0) { $skips[] = "第{$lineno}行：分值为 0 已跳过"; continue; }
                $remark = mb_substr(isset($f[5]) ? $f[5] : '', 0, 200);
                $item_name = ($item !== '') ? $item : '导入积分';
            }
            // 匹配学生
            $sid = 0;
            if ($no !== '' && isset($by_no[$no])) $sid = $by_no[$no];
            elseif ($nm !== '' && isset($by_name[$nm])) $sid = $by_name[$nm];
            if (!$sid) { $skips[] = "第{$lineno}行：未匹配到学生（编号「{$no}」姓名「{$nm}」）"; continue; }
            if ($kind === 'balance') {
                $rows[] = [$sid, '积分导入', $value, '数据迁移导入', date('Y-m-d H:i:s'), $lineno];
            } else {
                $rows[] = [$sid, $item_name, $value, $remark, $created, $lineno];
            }
        }
        $added = 0;
        if (count($rows)) {
            $crow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT school_id FROM classes WHERE id = {$class_id}"));
            $school_v = $crow ? intval($crow['school_id']) : 0;
            $stmt = mysqli_prepare($conn, "INSERT INTO points_log (school_id, class_id, student_id, student_no, item_id, item_name, value, remark, created_by, created_at, project_id, source)
                                           VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, 0, 3)");
            foreach ($rows as $r) {
                $sid = $r[0]; $nm = $r[1]; $val = $r[2]; $rk = $r[3]; $at = $r[4]; $ln = $r[5];
                $sno_v = isset($sno[$sid]) ? $sno[$sid] : '';
                mysqli_stmt_bind_param($stmt, "iiissisis", $school_v, $class_id, $sid, $sno_v, $nm, $val, $rk, $teacher_id, $at);
                if (mysqli_stmt_execute($stmt)) {
                    $added++;
                } else {
                    $skips[] = "第{$ln}行：写入失败（" . mysqli_stmt_error($stmt) . '）';
                }
            }
            mysqli_stmt_close($stmt);
        }
        return ['added' => $added, 'skips' => $skips];
    }
}

if (!function_exists('points_redeem_apply')) {
    /**
     * 积分兑换核心：余额足够者各生成一条「🎁 兑换」扣分流水（余额不足者跳过）
     * $gift：points_gifts 行（含 name/cost）；返回 ['ok'=>[姓名…], 'poor'=>['张三 余额3分'…]]
     */
    function points_redeem_apply($conn, $class_id, $gift, $ids, $teacher_id) {
        auto_points_ensure_schema($conn);
        $class_id = intval($class_id);
        $ids = array_values(array_unique(array_map('intval', (array)$ids)));
        if (!count($ids)) return ['ok' => [], 'poor' => []];
        $in = implode(',', $ids);
        $stus = [];
        $res = mysqli_query($conn, "SELECT id, name, student_no FROM students WHERE id IN ({$in}) AND class_id = {$class_id} AND disabled = 0");
        while ($s = mysqli_fetch_assoc($res)) $stus[intval($s['id'])] = ['name' => strval($s['name']), 'no' => strval($s['student_no'])];
        $bal = [];
        $res = mysqli_query($conn, "SELECT student_id, SUM(value) AS t FROM points_log WHERE class_id = {$class_id} GROUP BY student_id");
        while ($r = mysqli_fetch_assoc($res)) $bal[intval($r['student_id'])] = intval($r['t']);
        $cost = intval($gift['cost']);
        $gname = strval($gift['name']);
        $ok = [];
        $poor = [];
        $rows = [];
        $errs = [];
        foreach ($ids as $sid) {
            if (!isset($stus[$sid])) continue;
            $sname = $stus[$sid]['name'];
            $b = isset($bal[$sid]) ? $bal[$sid] : 0;
            if ($b < $cost) { $poor[] = $sname . '（余额 ' . $b . ' 分）'; continue; }
            $ok[] = $sname;
            $rows[] = $sid;
        }
        if (count($rows)) {
            $crow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT school_id FROM classes WHERE id = {$class_id}"));
            $si = $crow ? intval($crow['school_id']) : 0;
            $stmt = mysqli_prepare($conn, "INSERT INTO points_log (school_id, class_id, student_id, student_no, item_id, item_name, value, remark, created_by, created_at, project_id, source)
                                           VALUES (?, ?, ?, ?, 0, '🎁 兑换', ?, ?, ?, NOW(), 0, 1)");
            foreach ($rows as $sid) {
                $sno_v = $stus[$sid]['no'];
                $rk = '兑换「' . mb_substr($gname, 0, 40) . '」';
                $val = -abs(intval($cost));   // 兑换=扣分：流水记负值
                mysqli_stmt_bind_param($stmt, "iiisisi", $si, $class_id, $sid, $sno_v, $val, $rk, $teacher_id);
                if (!mysqli_stmt_execute($stmt)) $errs[] = 'sid=' . $sid . ': ' . mysqli_stmt_error($stmt);
            }
            mysqli_stmt_close($stmt);
        }
        return ['ok' => $ok, 'poor' => $poor, 'errs' => $errs];
    }
}
?>
