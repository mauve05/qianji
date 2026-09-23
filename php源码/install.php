<?php
/**
 * 安装向导（单用户版）：网页表单填写数据库连接与管理员账号，自动完成——
 *   ① 环境检测（PHP 版本 / mysqli / mbstring / 配置目录可写）
 *   ② 测试数据库连接（ AJAX，无需提交整表）
 *   ③ 建库建表 + 写入初始数据（幂等，可重复执行）
 *   ④ 创建管理员账号（账号密码由安装者自定义）
 *   ⑤ 自动生成 includes/db_config.php（免去手工复制模板）
 * 已安装状态下显示提示，可强制重新运行向导（不删除现有数据）。
 * 单用户版：无学校/角色体系，安装时创建的管理员账号 is_admin=1，
 * 其余注册账号为普通教师（个人账号模式：自己创建的班级 + 授权成员协作）。
 */

$CFG_FILE = __DIR__ . '/includes/db_config.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** 读取现有 db_config.php 的连接参数用于预填（解析失败返回空数组） */
function parse_existing_config($file) {
    if (!is_file($file)) return [];
    $src = @file_get_contents($file);
    if ($src === false) return [];
    $out = [];
    foreach (['db_host', 'db_user', 'db_pass', 'db_name'] as $var) {
        if (preg_match('/\$' . $var . '\s*=\s*([\'"])(.*?)\1\s*;/', $src, $m)) {
            $out[$var] = $m[2];
        }
    }
    return $out;
}

/** 生成 db_config.php 内容（与 db_config.example.php 同构，参数值经 var_export 安全转义） */
function build_config_content($host, $user, $pass, $name) {
    $h = var_export($host, true);
    $u = var_export($user, true);
    $p = var_export($pass, true);
    $n = var_export($name, true);
    return <<<PHPEOF
<?php
/**
 * 潜记二维码作业登记系统（单用户版）- 数据库配置（由 install.php 安装向导自动生成）
 *
 * 注意：本文件含数据库密码，已被 .gitignore 排除，请勿提交到代码仓库。
 * 如需手工修改连接参数，直接编辑本文件保存即可。
 */

// 防缓存：代码更新/数据变更后浏览器立即取新页面（统计弹层等实时内容不被磁盘缓存）
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// 数据库连接参数
\$db_host = {$h};
\$db_user = {$u};
\$db_pass = {$p};
\$db_name = {$n};

// 创建数据库连接
function getConnection() {
    global \$db_host, \$db_user, \$db_pass, \$db_name;
    \$conn = mysqli_connect(\$db_host, \$db_user, \$db_pass, \$db_name);

    if (!\$conn) {
        die("数据库连接失败: " . mysqli_connect_error());
    }

    mysqli_set_charset(\$conn, "utf8mb4");   // utf8mb4：支持 emoji 等四字节字符（评价选项/评语/喊话内容）
    return \$conn;
}

// 安全过滤函数
function check_input(\$data) {
    \$data = trim(\$data);
    \$data = stripslashes(\$data);
    \$data = htmlspecialchars(\$data);
    return \$data;
}

// 账号是否被禁用（auth.php 在 functions.php 之前加载，故定义于此；禁用后无法登录，已有会话在下次请求时强制退出）
if (!function_exists('is_teacher_disabled')) {
    function is_teacher_disabled(\$conn, \$teacher_id) {
        static \$disabled_cache = [];
        \$tid = intval(\$teacher_id);
        if (isset(\$disabled_cache[\$tid])) return \$disabled_cache[\$tid];
        \$stmt = mysqli_prepare(\$conn, "SELECT disabled FROM teachers WHERE id = ?");
        mysqli_stmt_bind_param(\$stmt, "i", \$tid);
        mysqli_stmt_execute(\$stmt);
        \$row = mysqli_fetch_assoc(mysqli_stmt_get_result(\$stmt));
        mysqli_stmt_close(\$stmt);
        return \$disabled_cache[\$tid] = \$row && intval(\$row['disabled']) === 1;
    }
}

// 获取客户端真实IP
if (!function_exists('get_client_ip')) {
    function get_client_ip() {
        \$keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        foreach (\$keys as \$k) {
            if (!empty(\$_SERVER[\$k])) {
                \$ip = trim(explode(',', \$_SERVER[\$k])[0]);
                if (filter_var(\$ip, FILTER_VALIDATE_IP)) {
                    return \$ip;
                }
            }
        }
        return '';
    }
}
PHPEOF;
}

// ===== 建表语句（最终态结构，无需旧库迁移） =====
$tables = [
    "CREATE TABLE IF NOT EXISTS teachers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE COMMENT '登录账号（与 phone 一致）',
        password VARCHAR(255) NOT NULL COMMENT '密码（bcrypt哈希）',
        disabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT '账号禁用：1=已禁用（禁止登录）',
        realname VARCHAR(50) NOT NULL DEFAULT '' COMMENT '教师姓名',
        school_id INT NOT NULL DEFAULT 1 COMMENT '所属学校（单用户版恒为 1）',
        phone VARCHAR(50) NOT NULL DEFAULT '' COMMENT '手机号（登录账号，username=phone）',
        is_admin TINYINT(1) NOT NULL DEFAULT 0 COMMENT '管理员：1=管理员（仅安装时生成的账号），0=普通教师',
        created_at DATETIME NOT NULL COMMENT '注册时间'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='教师账号（单用户版：is_admin=1 为管理员）'",

    "CREATE TABLE IF NOT EXISTS settings (
        skey VARCHAR(50) PRIMARY KEY,
        svalue VARCHAR(255) NOT NULL DEFAULT '' COMMENT '设置值'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='平台设置'",

    "CREATE TABLE IF NOT EXISTS classes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL DEFAULT 0 COMMENT '创建人教师',
        school_id INT NOT NULL DEFAULT 1 COMMENT '所属学校（单用户版恒为 1）',
        name VARCHAR(100) NOT NULL COMMENT '班级名称',
        class_code CHAR(8) NOT NULL DEFAULT '' COMMENT '8位班级授权码（教师凭此申请加入班级管理）',
        query_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT '公开查询总开关：1=开启（query.php 可访问） 0=关闭',
        announce_token VARCHAR(32) NOT NULL DEFAULT '' COMMENT '喊话客户端接入令牌（announce_client.php?t=）',
        rev_announce TINYINT(1) NOT NULL DEFAULT 0 COMMENT '班级端反向喊话开关：1=允许班级客户端向教师端发送喊话',
        rev_ann_modes VARCHAR(20) NOT NULL DEFAULT 'voice,text' COMMENT '允许的反向喊话模式（voice/text 逗号分隔）',
        ann_teacher_seen DATETIME DEFAULT NULL COMMENT '教师端最近在线心跳时间（大屏显示教师在线状态）',
        seat_cols INT NOT NULL DEFAULT 2 COMMENT '座位布局：每组列数',
        seat_groups INT NOT NULL DEFAULT 4 COMMENT '座位布局：分组数',
        seat_auto_group TINYINT(1) NOT NULL DEFAULT 0 COMMENT '座位自动分组：1=大屏分组显示按座位列自动分组',
        created_at DATETIME NOT NULL COMMENT '创建时间',
        deleted_at DATETIME DEFAULT NULL COMMENT '软删除时间（非空=在回收站，可恢复）',
        INDEX idx_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='班级'",

    "CREATE TABLE IF NOT EXISTS students (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL COMMENT '所属班级',
        seat_no VARCHAR(20) NOT NULL DEFAULT '' COMMENT '座号/序号',
        name VARCHAR(50) NOT NULL COMMENT '学生姓名',
        student_no VARCHAR(50) NOT NULL COMMENT '编号（唯一识别码）',
        remark VARCHAR(100) NOT NULL DEFAULT '' COMMENT '备注',
        group_id INT NOT NULL DEFAULT 0 COMMENT '所属分组（stu_groups.id，0=未分组）',
        seat_pos INT NOT NULL DEFAULT 0 COMMENT '座位位置（1起，0=未入座）',
        disabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT '禁用：1=已禁用（不打印卡片、扫码不登记；报表仍包含）',
        created_at DATETIME NOT NULL COMMENT '添加时间',
        UNIQUE KEY uk_class_no (class_id, student_no),
        INDEX idx_class (class_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='学生名单'",

    "CREATE TABLE IF NOT EXISTS projects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        school_id INT NOT NULL DEFAULT 1 COMMENT '所属学校（单用户版恒为 1）',
        class_id INT NULL DEFAULT NULL COMMENT '所属班级',
        scope ENUM('school','class') NOT NULL DEFAULT 'class' COMMENT '范围（单用户版仅 class）',
        created_by INT NOT NULL DEFAULT 0 COMMENT '创建人',
        name VARCHAR(100) NOT NULL COMMENT '项目（作业）名称',
        eval_mode VARCHAR(50) NOT NULL DEFAULT 'smile' COMMENT '评价模式（系统键 smile/score/... 或自定义模式 c+编号）',
        late_seconds INT NOT NULL DEFAULT 1800 COMMENT '补登记阈值：首位登记后多少秒内的登记不算补登记',
        lock_seconds INT NOT NULL DEFAULT 1800 COMMENT '登记后自动锁定秒数（0=不启用自动锁定）',
        mode ENUM('count','daily','multi','quiz','raise','omr') NOT NULL DEFAULT 'count' COMMENT '登记模式：count=仅登记一次 daily=按日期打卡 multi=多次登记（项次） raise=举牌模式（题次+黑白图案卡四选一） omr=答题卡模式（多题涂卡识别）',
        allow_query TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否允许公开查询登记信息：1=允许 0=禁止',
        archived_at DATETIME DEFAULT NULL COMMENT '办结归档时间（非空=已办结）',
        created_at DATETIME NOT NULL COMMENT '创建时间',
        deleted_at DATETIME DEFAULT NULL COMMENT '软删除时间（非空=在回收站，可恢复）',
        INDEX idx_class (class_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='作业项目'",

    "CREATE TABLE IF NOT EXISTS records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL COMMENT '所属项目',
        student_id INT NOT NULL COMMENT '学生',
        registered TINYINT NOT NULL DEFAULT 0 COMMENT '是否已登记 0=未登记 1=已登记',
        registered_at DATETIME DEFAULT NULL COMMENT '登记时间',
        registered_by VARCHAR(20) NOT NULL DEFAULT '' COMMENT '登记方式 scan=扫码 click=点击 batch=批量',
        eval_value VARCHAR(255) DEFAULT NULL COMMENT '评价内容',
        eval_at DATETIME DEFAULT NULL COMMENT '评价时间',
        eval_cleared_at DATETIME DEFAULT NULL COMMENT '评价清除时间（取消登记清除评价 / 删除评价时记录）',
        UNIQUE KEY uk_project_student (project_id, student_id),
        INDEX idx_project (project_id),
        INDEX idx_student (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='作业登记记录'",

    "CREATE TABLE IF NOT EXISTS record_days (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL COMMENT '所属项目',
        student_id INT NOT NULL COMMENT '学生',
        reg_date DATE NOT NULL COMMENT '打卡日期',
        created_at DATETIME NOT NULL COMMENT '打卡时间',
        eval_value VARCHAR(255) DEFAULT NULL COMMENT '评价内容（按天，打卡模式当日等级）',
        eval_at DATETIME DEFAULT NULL COMMENT '评价时间',
        UNIQUE KEY uk_psd (project_id, student_id, reg_date),
        INDEX idx_pd (project_id, reg_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='每日打卡记录（打卡模式日历用）'",

    "CREATE TABLE IF NOT EXISTS project_rounds (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL COMMENT '所属项目',
        round_no INT NOT NULL COMMENT '次项序号（1起，删除后自动重排）',
        title VARCHAR(30) DEFAULT NULL COMMENT '轮次自定义标题（空=显示序号）',
        correct_opts VARCHAR(8) DEFAULT NULL COMMENT '正确选项（答题模式，逗号分隔可多选如 A,C；NULL=未设置）',
        created_at DATETIME NOT NULL COMMENT '创建时间',
        UNIQUE KEY uk_pr (project_id, round_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='项目次项/题次（多次登记与答题模式的轮次定义）'",

    "CREATE TABLE IF NOT EXISTS record_rounds (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL COMMENT '所属项目',
        student_id INT NOT NULL COMMENT '学生',
        round_no INT NOT NULL COMMENT '次项/题次序号',
        registered_at DATETIME DEFAULT NULL COMMENT '登记时间',
        registered_by VARCHAR(20) NOT NULL DEFAULT '' COMMENT '登记方式 scan=扫码 click=点击 batch=批量',
        eval_value VARCHAR(255) DEFAULT NULL COMMENT '评价内容（答题模式存 A/B/C/D）',
        eval_at DATETIME DEFAULT NULL COMMENT '评价时间',
        eval_cleared_at DATETIME DEFAULT NULL COMMENT '评价清除时间',
        created_at DATETIME NOT NULL COMMENT '创建时间',
        UNIQUE KEY uk_psr (project_id, student_id, round_no),
        INDEX idx_pr (project_id, round_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='按次项/题次的登记与评价记录（多次登记/答题模式用；mb4 支持笑脸等 emoji 评价键）'",

    "CREATE TABLE IF NOT EXISTS student_comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL COMMENT '所属项目',
        student_id INT NOT NULL COMMENT '学生',
        content VARCHAR(1000) NOT NULL COMMENT '点评内容',
        created_by INT NOT NULL DEFAULT 0 COMMENT '点评教师',
        created_at DATETIME NOT NULL COMMENT '首次点评时间',
        updated_at DATETIME NOT NULL COMMENT '最近更新时间',
        UNIQUE KEY uk_ps (project_id, student_id),
        INDEX idx_project (project_id),
        INDEX idx_student (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='教师点评（项目维度，家长查询综合报告展示）'",

    "CREATE TABLE IF NOT EXISTS login_days (
        teacher_id INT NOT NULL COMMENT '教师',
        day DATE NOT NULL COMMENT '登录日期',
        cnt INT NOT NULL DEFAULT 1 COMMENT '当日登录次数',
        last_at DATETIME NOT NULL COMMENT '当日最后登录时间',
        PRIMARY KEY (teacher_id, day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='登录活跃统计（个人中心使用频次卡片）'",

    "CREATE TABLE IF NOT EXISTS class_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL COMMENT '班级',
        teacher_id INT NOT NULL COMMENT '被授权教师',
        perm ENUM('manage','create','register','view') NOT NULL DEFAULT 'view' COMMENT '授权级别：manage=管理班级 create=可建立 register=仅登记 view=仅查看',
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' COMMENT '审核状态',
        created_at DATETIME NOT NULL COMMENT '申请时间',
        handled_at DATETIME DEFAULT NULL COMMENT '处理时间',
        UNIQUE KEY uk_ct (class_id, teacher_id),
        INDEX idx_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='班级授权成员（凭班级授权码申请，创建人审核）'",

    "CREATE TABLE IF NOT EXISTS class_transfers (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='班级转移申请（原创建人发起，对方确认后班级易主，原创建人保留管理授权）'",

    "CREATE TABLE IF NOT EXISTS login_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL COMMENT 'sha256(cookie token)',
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uk_token (token_hash),
        INDEX idx_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='保持登录令牌（勾选「保持登录」后30天内免输密码）'",

    "CREATE TABLE IF NOT EXISTS pinned_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL COMMENT '教师',
        item_type ENUM('class','project') NOT NULL COMMENT '置顶对象类型',
        item_id INT NOT NULL COMMENT '对象id',
        created_at DATETIME NOT NULL COMMENT '置顶时间',
        UNIQUE KEY uk_tti (teacher_id, item_type, item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='个人置顶（班级/作业项目）'",

    "CREATE TABLE IF NOT EXISTS eval_modes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL COMMENT '所属教师（自定义模式归个人所有）',
        name VARCHAR(50) NOT NULL COMMENT '模式名称',
        options TEXT COMMENT '选项内容，每行一个；留空=自由输入',
        sort INT NOT NULL DEFAULT 0 COMMENT '排序权重（小者在前）',
        created_at DATETIME NOT NULL,
        INDEX idx_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='自定义评价模式（系统模板为代码内置，仅可禁用）'",

    "CREATE TABLE IF NOT EXISTS import_payloads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL COMMENT '来源班级',
        code VARCHAR(16) NOT NULL COMMENT '8位提取码（二维码链接与手动提取共用）',
        content MEDIUMTEXT NOT NULL COMMENT 'bjdl 学生名单内容（潜记格式）',
        created_at DATETIME NOT NULL,
        updated_at DATETIME DEFAULT NULL,
        UNIQUE KEY uk_class (class_id),
        UNIQUE KEY uk_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='班级导入二维码载荷（大名单入库，链接/提取码提取）'",

    "CREATE TABLE IF NOT EXISTS stu_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL COMMENT '所属班级',
        name VARCHAR(50) NOT NULL COMMENT '分组名称',
        sort INT NOT NULL DEFAULT 0 COMMENT '排序（小在前）',
        created_at DATETIME NOT NULL COMMENT '创建时间',
        INDEX idx_class (class_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='班级学生分组'",

    "CREATE TABLE IF NOT EXISTS announce_msgs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL COMMENT '目标班级',
        sender_id INT NOT NULL DEFAULT 0 COMMENT '发送教师',
        mtype ENUM('text','voice','img','audio') NOT NULL DEFAULT 'text' COMMENT '类型：text=文字字幕 voice=语音播报 img=图片 audio=语音录音',
        direction ENUM('t2c','c2t') NOT NULL DEFAULT 't2c' COMMENT '方向：t2c=教师发给班级 c2t=班级发给教师（预留）',
        content TEXT NOT NULL COMMENT '内容（语音为{TTS}文本，{name}已替换为实际姓名；图片/录音为文件路径）',
        font_size INT NOT NULL DEFAULT 64 COMMENT '字幕字号（px）',
        marquee TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=模拟LED跑马灯滚动播放 0=直接呈现',
        sub_big TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=语音播报同时显示全屏大字 0=仅语音不显示字幕',
        force_show TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=客户端自动最大化并置前显示',
        auto_min INT NOT NULL DEFAULT 0 COMMENT '自动最小化秒数（0=不自动）',
        cache_min INT NOT NULL DEFAULT 60 COMMENT '客户端缓存保留分钟（收起后可点击恢复的时长，0=不缓存）',
        speed FLOAT NOT NULL DEFAULT 1 COMMENT '语音语速（speechSynthesis rate）',
        pitch FLOAT NOT NULL DEFAULT 1 COMMENT '语音音调（speechSynthesis pitch）',
        times INT NOT NULL DEFAULT 1 COMMENT '语音播报次数',
        voice_name VARCHAR(120) NOT NULL DEFAULT '' COMMENT '音色角色（浏览器TTS voice name，空=默认音色）',
        need_ack TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=需班级端反馈（确认收到/无法处理），反馈后才开始自动最小化倒计时',
        ack_status TINYINT DEFAULT NULL COMMENT '班级端反馈：NULL=未反馈 1=确认收到 2=无法处理',
        ack_at DATETIME DEFAULT NULL COMMENT '班级端反馈时间',
        status TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=待客户端取走 1=已取走',
        created_at DATETIME NOT NULL COMMENT '创建时间',
        picked_at DATETIME DEFAULT NULL COMMENT '客户端取走时间',
        INDEX idx_class_status (class_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='班级喊话指令队列（教师端下发，客户端轮询取走播放）'",

    "CREATE TABLE IF NOT EXISTS announce_rev_msgs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL COMMENT '来源班级',
        mtype ENUM('text','voice') NOT NULL DEFAULT 'text' COMMENT '类型：text=文字字幕 voice=语音播报',
        content TEXT NOT NULL COMMENT '内容（班级客户端编辑发送）',
        font_size INT NOT NULL DEFAULT 48 COMMENT '字幕字号（px）',
        marquee TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=教师端横幅LED跑马灯滚动 0=直接呈现',
        speed FLOAT NOT NULL DEFAULT 1 COMMENT '语音语速（speechSynthesis rate）',
        pitch FLOAT NOT NULL DEFAULT 1 COMMENT '语音音调（speechSynthesis pitch）',
        times INT NOT NULL DEFAULT 1 COMMENT '语音播报次数',
        status TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=待教师端取走 1=已取走',
        created_at DATETIME NOT NULL COMMENT '创建时间',
        picked_at DATETIME DEFAULT NULL COMMENT '教师端取走时间',
        INDEX idx_class_status (class_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='班级反向喊话队列（班级客户端发送，教师端轮询取走播放）'",

    "CREATE TABLE IF NOT EXISTS announce_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL COMMENT '班级',
        device_id CHAR(32) NOT NULL COMMENT '客户端设备标识（浏览器生成随机ID，localStorage 持久）',
        device_type VARCHAR(10) NOT NULL DEFAULT 'pc' COMMENT '设备类型：pc=电脑 phone=手机',
        voice_ok TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否支持语音播报（客户端TTS检测上报，0=仅字幕）',
        last_seen DATETIME NOT NULL COMMENT '最近心跳时间（announce_poll 每次轮询刷新）',
        UNIQUE KEY uk_cls_dev (class_id, device_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='喊话客户端设备在线状态（心跳表，网页打开即在线）'",

    "CREATE TABLE IF NOT EXISTS omr_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL COMMENT '所属项目（答题卡模式项目）',
        name VARCHAR(100) NOT NULL DEFAULT '' COMMENT '模板名称',
        layout MEDIUMTEXT NOT NULL COMMENT '答题卡布局 JSON（mm 坐标 + 预计算 bubbles 涂框坐标，识别端按此采样）',
        created_at DATETIME NOT NULL COMMENT '创建时间',
        updated_at DATETIME NOT NULL COMMENT '更新时间',
        INDEX idx_project (project_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='答题卡模板（设计器保存的布局与涂框坐标）'",

    "CREATE TABLE IF NOT EXISTS omr_results (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL COMMENT '所属项目',
        round_no INT NOT NULL DEFAULT 1 COMMENT '题次',
        template_id INT NOT NULL DEFAULT 0 COMMENT '识别所用模板',
        student_id INT DEFAULT NULL COMMENT '匹配学生（NULL=未匹配，可手动绑定）',
        ident VARCHAR(40) NOT NULL DEFAULT '' COMMENT '识别身份（seat:座号 / no:编号）',
        answers TEXT NOT NULL COMMENT '各题答案 JSON（{\"1\":\"A\",\"2\":\"AC\"...}）',
        score VARCHAR(20) NOT NULL DEFAULT '' COMMENT '得分（如 12/15，模板无答案键则为空）',
        flags VARCHAR(255) NOT NULL DEFAULT '' COMMENT '识别警告（ambiguous=存疑 lowfill=涂填过轻）',
        pages VARCHAR(30) NOT NULL DEFAULT '' COMMENT '已扫描页码集合（多页答题卡合并，如 1,2）',
        created_at DATETIME NOT NULL COMMENT '首次识别时间',
        updated_at DATETIME NOT NULL COMMENT '最近识别时间（重扫覆盖）',
        UNIQUE KEY uk_pid_round_ident (project_id, round_no, ident),
        INDEX idx_student (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='答题卡识别结果（每生每题次最新一次，重复识别覆盖）'",

    "CREATE TABLE IF NOT EXISTS omr_answers (
        project_id INT PRIMARY KEY,
        answers MEDIUMTEXT NOT NULL COMMENT '答案键 JSON（{\"1\":\"A\",\"2\":\"BD\"...}，按题号；优先于模板答案键）',
        updated_at DATETIME NOT NULL COMMENT '最近录入时间',
        updated_by INT NOT NULL DEFAULT 0 COMMENT '录入教师'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='答题卡项目独立答案键（「录入答案」单独设置每题答案，改答案不动模板避免识别坐标变化）'",

    "CREATE TABLE IF NOT EXISTS omr_lib (
        id INT AUTO_INCREMENT PRIMARY KEY,
        school_id INT NOT NULL DEFAULT 1 COMMENT '所属学校（单用户版恒为 1）',
        name VARCHAR(100) NOT NULL DEFAULT '' COMMENT '模板名称',
        scope VARCHAR(20) NOT NULL DEFAULT 'school' COMMENT '适用范围：school=全校 class=班级',
        class_id INT NOT NULL DEFAULT 0 COMMENT '班级（class 范围用）',
        layout MEDIUMTEXT NOT NULL COMMENT '答题卡布局 JSON（与 omr_templates.layout 同构，含预计算 bubbles）',
        created_by INT NOT NULL DEFAULT 0 COMMENT '创建教师',
        created_at DATETIME NOT NULL COMMENT '创建时间',
        updated_at DATETIME NOT NULL COMMENT '更新时间',
        INDEX idx_school (school_id),
        INDEX idx_scope (school_id, scope, class_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='答题卡共享模板库（单用户版：本人模板 + 本班模板共享）'",

    "CREATE TABLE IF NOT EXISTS omr_images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        school_id INT NOT NULL DEFAULT 1 COMMENT '所属学校（单用户版恒为 1）',
        project_id INT NOT NULL DEFAULT 0 COMMENT '所属项目',
        round_no INT NOT NULL DEFAULT 1 COMMENT '题次',
        pg INT NOT NULL DEFAULT 1 COMMENT '页码（多页模板）',
        ident VARCHAR(32) NOT NULL DEFAULT '' COMMENT '识别身份（seat:座号 / no:编号）',
        student_id INT NOT NULL DEFAULT 0 COMMENT '匹配学生',
        student_name VARCHAR(50) NOT NULL DEFAULT '' COMMENT '学生姓名（登记时快照）',
        seat_no VARCHAR(20) NOT NULL DEFAULT '' COMMENT '座号（登记时快照）',
        score VARCHAR(32) NOT NULL DEFAULT '' COMMENT '得分（登记时快照）',
        teacher_id INT NOT NULL DEFAULT 0 COMMENT '登记教师（可删本人记录）',
        file VARCHAR(255) NOT NULL DEFAULT '' COMMENT '图片相对路径（uploads/omr/...）',
        created_at DATETIME NOT NULL COMMENT '保存时间',
        INDEX idx_proj (project_id, round_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='答题卡批改图留存（登记成功后保存的批注预览图，作为调用记录）'",

    "CREATE TABLE IF NOT EXISTS points_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        school_id INT NOT NULL DEFAULT 1 COMMENT '所属学校（单用户版恒为 1，共享积分项）',
        name VARCHAR(50) NOT NULL COMMENT '积分项名称（如 课堂互动）',
        value INT NOT NULL DEFAULT 1 COMMENT '分值（正=加分 负=扣分，点一下即按该项加减）',
        color VARCHAR(10) NOT NULL DEFAULT '#27ae60' COMMENT '按钮颜色（hex）',
        type TINYINT NOT NULL DEFAULT 1 COMMENT '积分类型（1=个人 2=小组；小组项点一下给整组每人记一条）',
        show_lot TINYINT NOT NULL DEFAULT 1 COMMENT '是否显示在随机抽选按钮中（1=显示 0=隐藏）',
        show_quick TINYINT NOT NULL DEFAULT 1 COMMENT '是否显示在一键加分按钮中（1=显示 0=隐藏）',
        sort INT NOT NULL DEFAULT 0 COMMENT '排序（小在前）',
        INDEX idx_school (school_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='课堂表现积分项配置（多维度加减分按钮）'",

    "CREATE TABLE IF NOT EXISTS points_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        school_id INT NOT NULL DEFAULT 1 COMMENT '所属学校（单用户版恒为 1）',
        class_id INT NOT NULL COMMENT '班级',
        student_id INT NOT NULL COMMENT '学生',
        item_id INT NOT NULL DEFAULT 0 COMMENT '积分项（0=自定义分值）',
        item_name VARCHAR(50) NOT NULL DEFAULT '' COMMENT '积分项名称快照（项删除后流水仍可读）',
        value INT NOT NULL DEFAULT 0 COMMENT '分值快照（正=加分 负=扣分）',
        remark VARCHAR(200) NOT NULL DEFAULT '' COMMENT '备注',
        created_by INT NOT NULL DEFAULT 0 COMMENT '操作教师',
        created_at DATETIME NOT NULL COMMENT '操作时间',
        project_id INT NOT NULL DEFAULT 0 COMMENT '关联项目（0=手动积分，>0=项目自动规则生成）',
        source TINYINT NOT NULL DEFAULT 1 COMMENT '来源（1=手动 2=项目自动规则）',
        student_no VARCHAR(50) NOT NULL DEFAULT '' COMMENT '学生编号快照（删除学生后同编号新增学生自动找回积分）',
        INDEX idx_cls_stu (class_id, student_id),
        INDEX idx_school_time (school_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='课堂表现积分流水（每条自动记录时间/操作教师/备注）'",

    "CREATE TABLE IF NOT EXISTS points_rules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL COMMENT '所属项目',
        rtype VARCHAR(30) NOT NULL COMMENT '规则类型（见 points_rule_types_meta）',
        opt VARCHAR(50) NOT NULL DEFAULT '' COMMENT '规则参数（eval_opt=匹配评价内容；阈值类=数字阈值）',
        value INT NOT NULL DEFAULT 0 COMMENT '分值（正=加分 负=扣分）',
        enabled TINYINT NOT NULL DEFAULT 1 COMMENT '是否启用（1=启用 0=停用）',
        sort INT NOT NULL DEFAULT 0 COMMENT '排序（小在前）',
        INDEX idx_project (project_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='项目自动积分规则（auto_points_sync 按当前数据全量重建 source=2 流水）'",

    "CREATE TABLE IF NOT EXISTS points_gifts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL COMMENT '所属班级（按班配置）',
        school_id INT NOT NULL DEFAULT 1 COMMENT '所属学校（冗余快照，单用户版恒为 1）',
        name VARCHAR(50) NOT NULL COMMENT '礼品名称',
        cost INT NOT NULL DEFAULT 1 COMMENT '兑换所需积分',
        enabled TINYINT NOT NULL DEFAULT 1 COMMENT '是否启用（1=启用 0=下架）',
        sort INT NOT NULL DEFAULT 0 COMMENT '排序（小在前）',
        INDEX idx_class (class_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='积分兑换礼品（兑换=自动扣分并记流水）'",
];

// ===== 环境检测 =====
$env_checks = [
    ['PHP 版本 ≥ 7.2（当前 ' . PHP_VERSION . '）', version_compare(PHP_VERSION, '7.2.0', '>=')],
    ['mysqli 扩展', extension_loaded('mysqli')],
    ['mbstring 扩展', extension_loaded('mbstring')],
    ['includes/ 目录可写（用于生成 db_config.php）', is_writable(__DIR__ . '/includes')],
];
$hard_fail = !version_compare(PHP_VERSION, '7.2.0', '>=') || !extension_loaded('mysqli');

// ===== 已安装检测（配置文件可解析 + 可连接 + teachers 表存在 + 已有管理员） =====
$existing_cfg = parse_existing_config($CFG_FILE);
$already_installed = false;
$existing_db_name = '';
if ($existing_cfg && extension_loaded('mysqli')) {
    $c = @mysqli_connect($existing_cfg['db_host'] ?? 'localhost', $existing_cfg['db_user'] ?? 'root', $existing_cfg['db_pass'] ?? '');
    if ($c) {
        $name = $existing_cfg['db_name'] ?? '';
        if ($name !== '' && @mysqli_select_db($c, $name)) {
            $r = @mysqli_query($c, "SHOW TABLES LIKE 'teachers'");
            if ($r && mysqli_num_rows($r) > 0) {
                $r2 = @mysqli_query($c, "SELECT id FROM teachers WHERE is_admin = 1 LIMIT 1");
                if ($r2 && mysqli_num_rows($r2) > 0) {
                    $already_installed = true;
                    $existing_db_name = $name;
                }
            }
        }
        mysqli_close($c);
    }
}

// ===== 默认表单值（配置文件可解析则预填，否则用通用默认） =====
$defaults = [
    'db_host'     => $existing_cfg['db_host'] ?? 'localhost',
    'db_user'     => $existing_cfg['db_user'] ?? 'root',
    'db_pass'     => $existing_cfg['db_pass'] ?? '',
    'db_name'     => $existing_cfg['db_name'] ?? 'qj_registration',
    'admin_user'  => 'admin',
    'admin_pass'  => '',
    'admin_pass2' => '',
];

$view = 'wizard';   // wizard | installed | success
$errors = [];
$success_admin = '';
$success_admin_created = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = [
        'db_host'     => trim($_POST['db_host'] ?? ''),
        'db_user'     => trim($_POST['db_user'] ?? ''),
        'db_pass'     => (string)($_POST['db_pass'] ?? ''),
        'db_name'     => trim($_POST['db_name'] ?? ''),
        'admin_user'  => trim($_POST['admin_user'] ?? ''),
        'admin_pass'  => (string)($_POST['admin_pass'] ?? ''),
        'admin_pass2' => (string)($_POST['admin_pass2'] ?? ''),
    ];

    // ---- AJAX：测试数据库连接（不建库、不改任何文件） ----
    if (($_POST['action'] ?? '') === 'testconn') {
        header('Content-Type: application/json; charset=utf-8');
        $msg = '';
        if ($f['db_host'] === '' || $f['db_user'] === '') {
            $msg = '请填写数据库主机和用户名';
        } elseif (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $f['db_name'])) {
            $msg = '数据库名仅限字母、数字、下划线';
        } else {
            $c = @mysqli_connect($f['db_host'], $f['db_user'], $f['db_pass']);
            if (!$c) {
                $msg = '连接失败：' . mysqli_connect_error();
            } else {
                mysqli_set_charset($c, 'utf8mb4');
                $r = mysqli_query($c, "SHOW DATABASES LIKE '" . addslashes($f['db_name']) . "'");
                $exists = $r && mysqli_num_rows($r) > 0;
                mysqli_close($c);
                $msg = '连接成功，数据库「' . $f['db_name'] . '」' . ($exists ? '已存在' : '不存在（安装时将自动创建）');
                echo json_encode(['success' => true, 'message' => $msg]);
                exit();
            }
        }
        echo json_encode(['success' => false, 'message' => $msg]);
        exit();
    }

    // ---- 完整安装 ----
    $errors = [];
    if ($hard_fail) {
        $errors[] = '服务器环境不满足要求（PHP 版本或 mysqli 扩展），无法继续安装';
    }
    // 防误覆盖：系统已安装时重新配置，必须显式勾选确认（避免重复点击 install.php 覆盖 db_config.php）
    $cr = !empty($_POST['confirm_reinstall']);
    if ($already_installed && !$cr) {
        $errors[] = '系统已安装：重新配置将覆盖 includes/db_config.php，请先勾选「我确认要重新配置」再提交';
    }
    if ($f['db_host'] === '' || $f['db_user'] === '') {
        $errors[] = '请填写数据库主机和用户名';
    }
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $f['db_name'])) {
        $errors[] = '数据库名仅限字母、数字、下划线（1-64 位）';
    }
    if (!preg_match('/^[A-Za-z0-9_\-@.]{3,50}$/', $f['admin_user'])) {
        $errors[] = '管理员账号需 3-50 位，仅限字母、数字、下划线、@、.、-';
    }
    if (strlen($f['admin_pass']) < 6) {
        $errors[] = '管理员密码至少 6 位';
    }
    if ($f['admin_pass'] !== $f['admin_pass2']) {
        $errors[] = '两次输入的管理员密码不一致';
    }

    $conn = null;
    if (!$errors) {
        $conn = @mysqli_connect($f['db_host'], $f['db_user'], $f['db_pass']);
        if (!$conn) {
            $errors[] = '数据库服务器连接失败：' . mysqli_connect_error();
        }
    }

    if (!$errors && $conn) {
        mysqli_set_charset($conn, 'utf8mb4');

        // 建库
        if (!mysqli_query($conn, "CREATE DATABASE IF NOT EXISTS `{$f['db_name']}` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci")) {
            $errors[] = '创建数据库失败: ' . mysqli_error($conn);
        } else {
            mysqli_select_db($conn, $f['db_name']);

            // 建表
            foreach ($tables as $sql) {
                if (!mysqli_query($conn, $sql)) {
                    $errors[] = mysqli_error($conn);
                }
            }

            // 平台设置：允许自主注册（后台「允许注册」开关可关闭）
            if (!$errors) {
                $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO settings (skey, svalue) VALUES ('0_allow_register', '1')");
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }

            // 管理员账号：无任何 is_admin=1 账号时才创建（已存在则跳过，不覆盖现有管理员）
            $admin_created = false;
            if (!$errors) {
                $has_admin = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM teachers WHERE is_admin = 1 LIMIT 1")) > 0;
                if ($has_admin) {
                    $success_admin_created = false;
                } else {
                    // 账号占用检查（普通教师注册的 username/phone 不得撞车）
                    $stmt = mysqli_prepare($conn, "SELECT id FROM teachers WHERE username = ? OR phone = ? LIMIT 1");
                    mysqli_stmt_bind_param($stmt, "ss", $f['admin_user'], $f['admin_user']);
                    mysqli_stmt_execute($stmt);
                    $dup = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
                    mysqli_stmt_close($stmt);
                    if ($dup) {
                        $errors[] = '管理员账号「' . $f['admin_user'] . '」已被占用，请换一个';
                    } else {
                        $hash = password_hash($f['admin_pass'], PASSWORD_DEFAULT);
                        $stmt = mysqli_prepare($conn, "INSERT INTO teachers (username, password, realname, school_id, phone, is_admin, created_at) VALUES (?, ?, '管理员', 1, ?, 1, NOW())");
                        mysqli_stmt_bind_param($stmt, "sss", $f['admin_user'], $hash, $f['admin_user']);
                        if (mysqli_stmt_execute($stmt)) {
                            $admin_created = true;
                        } else {
                            $errors[] = '创建管理员账号失败: ' . mysqli_error($conn);
                        }
                        mysqli_stmt_close($stmt);
                    }
                }
            }

            // 自动生成 db_config.php（数据库操作全部成功后才写）
            if (!$errors) {
                $content = build_config_content($f['db_host'], $f['db_user'], $f['db_pass'], $f['db_name']);
                if (@file_put_contents($CFG_FILE, $content) === false) {
                    $errors[] = '写入 includes/db_config.php 失败：请检查 includes 目录写权限（或参照 db_config.example.php 手工创建）';
                } elseif ($admin_created) {
                    $success_admin_created = true;
                }
            }
        }
    }
    if ($conn) {
        mysqli_close($conn);
    }

    if ($errors) {
        $view = 'wizard';
    } else {
        $view = 'success';
        $success_admin = $f['admin_user'];
        $success_db = $f['db_name'];
    }
} elseif ($already_installed && !isset($_GET['force'])) {
    $view = 'installed';
} else {
    // 向导预填：POST 出错时保留用户输入，首次进入用配置/默认值
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $f = $defaults;
    }
    if (isset($_GET['force'])) {
        $f['admin_pass'] = '';
        $f['admin_pass2'] = '';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>安装向导 - 潜记二维码作业登记系统（单用户版）</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="container" style="max-width:560px;">
    <div class="header">
        <h1>潜记二维码作业登记系统</h1>
        <p>安装向导（单用户版）</p>
    </div>

    <?php if ($view === 'installed'): ?>
    <div class="card">
        <div class="alert alert-success">系统已安装，无需重复安装。</div>
        <p style="margin-bottom:15px;color:#666;">当前数据库：<b><?php echo h($existing_db_name); ?></b>，管理员账号已存在。</p>
        <p style="margin-bottom:20px;color:#999;font-size:13px;">
            重复访问本页<b>不会重复安装</b>、不会覆盖任何配置与数据。
            如确需更换数据库或重新配置，可重新运行向导：不会删除现有数据，仅补建缺失数据表、
            更新配置文件；管理员已存在时不会重复创建。</p>
        <a href="index.php" class="btn btn-block">进入首页</a>
        <a href="install.php?force=1" class="btn btn-block" style="background:#fff;color:#667eea;border:1px solid #667eea;margin-top:10px;">重新运行安装向导</a>
    </div>
    <?php endif; ?>

    <?php if ($view === 'success'): ?>
    <div class="card">
        <div class="alert alert-success">安装完成！数据库与数据表已就绪。</div>
        <p style="margin-bottom:12px;color:#666;">数据库名：<b><?php echo h($success_db); ?></b></p>
        <p style="margin-bottom:12px;color:#666;">
            管理员账号：<b><?php echo h($success_admin); ?></b>（密码为你刚设置的密码）<br>
            <span style="font-size:13px;color:#999;">
            <?php if (!$success_admin_created): ?>系统已存在管理员账号，本次未重复创建（仍可用原管理员登录）。<br><?php endif; ?>
            管理员可登录【后台管理】添加用户、重置密码、模拟登录任意账号，以及开关「允许自主注册」。</span></p>
        <p style="margin-bottom:12px;color:#666;">配置文件 <b>includes/db_config.php</b> 已自动生成
            <span style="font-size:13px;color:#999;">（含数据库密码，已被 .gitignore 排除，请勿提交到 Git 仓库）</span>。</p>
        <p style="margin-bottom:20px;color:#c33;">安全提醒：建议安装完成后删除或重命名本文件（install.php）。</p>
        <a href="index.php" class="btn btn-block">进入首页登录</a>
    </div>
    <?php endif; ?>

    <?php if ($view === 'wizard'): ?>
    <div class="card">
        <?php if ($already_installed): ?>
        <div class="alert alert-error"><b>重新配置模式</b>：系统已安装，提交将覆盖 includes/db_config.php 配置文件（数据库现有数据不会被删除）。请谨慎操作。</div>
        <?php endif; ?>
        <?php if ($errors): ?>
        <div class="alert alert-error">安装过程中出现问题：</div>
        <pre style="white-space:pre-wrap;color:#c33;margin:0 0 15px;"><?php echo h(implode("\n", $errors)); ?></pre>
        <?php endif; ?>

        <div style="background:#f6f7fb;border-radius:8px;padding:12px 15px;margin-bottom:18px;font-size:13px;line-height:1.9;">
            <b style="color:#555;">环境检测</b><br>
            <?php foreach ($env_checks as $c): ?>
            <span style="color:<?php echo $c[1] ? '#27ae60' : '#c0392b'; ?>;"><?php echo $c[1] ? '✓' : '✗'; ?></span>
            <?php echo h($c[0]); ?><br>
            <?php endforeach; ?>
        </div>

        <form method="post" id="installForm" autocomplete="off" onsubmit="return wizardSubmit();">
            <div style="font-weight:bold;color:#667eea;margin-bottom:10px;">① 数据库连接</div>
            <div class="form-group">
                <label>数据库主机</label>
                <input type="text" name="db_host" class="form-control" value="<?php echo h($f['db_host']); ?>" placeholder="一般为 localhost 或 127.0.0.1" required>
            </div>
            <div class="form-group">
                <label>数据库用户名</label>
                <input type="text" name="db_user" class="form-control" value="<?php echo h($f['db_user']); ?>" placeholder="如 root" required>
            </div>
            <div class="form-group">
                <label>数据库密码</label>
                <input type="password" name="db_pass" class="form-control" value="<?php echo h($f['db_pass']); ?>" placeholder="无密码可留空">
            </div>
            <div class="form-group">
                <label>数据库名称</label>
                <input type="text" name="db_name" class="form-control" value="<?php echo h($f['db_name']); ?>" placeholder="不存在将自动创建，仅限字母数字下划线" required>
            </div>
            <button type="button" class="btn" style="background:#fff;color:#667eea;border:1px solid #667eea;" onclick="testConn()">测试数据库连接</button>
            <div id="connTip" style="margin:10px 0 0;font-size:13px;min-height:18px;"></div>

            <div style="font-weight:bold;color:#667eea;margin:22px 0 10px;">② 管理员账号</div>
            <div class="form-group">
                <label>管理员用户名（登录账号）</label>
                <input type="text" name="admin_user" class="form-control" value="<?php echo h($f['admin_user']); ?>" placeholder="3-50 位字母数字，登录时使用" required>
            </div>
            <div class="form-group">
                <label>管理员密码</label>
                <input type="password" name="admin_pass" class="form-control" placeholder="至少 6 位" required>
            </div>
            <div class="form-group">
                <label>确认密码</label>
                <input type="password" name="admin_pass2" class="form-control" placeholder="再次输入密码" required>
            </div>
            <p style="font-size:13px;color:#999;margin:0 0 15px;">
                安装完成后即可用该账号登录；注册页面新建的账号为普通教师（个人模式）。</p>

            <?php if ($already_installed): ?>
            <div class="form-group" style="background:#fff4f2;border:1px solid #f5c6cb;border-radius:8px;padding:10px 12px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin:0;font-weight:normal;">
                    <input type="checkbox" name="confirm_reinstall" value="1" <?php echo !empty($cr) ? 'checked' : ''; ?> style="accent-color:#e74c3c;width:15px;height:15px;">
                    我确认要重新配置（覆盖 db_config.php，不删除数据库数据）
                </label>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-block" <?php echo $hard_fail ? 'disabled style="background:#ccc;"' : ''; ?>>开始安装</button>
        </form>
    </div>
    <script>
    function wizardSubmit() {
        <?php if ($already_installed): ?>
        return confirm('系统已安装！重新安装将覆盖 includes/db_config.php 配置文件（不会删除数据库现有数据）。确定继续吗？');
        <?php else: ?>
        return true;
        <?php endif; ?>
    }
    function testConn() {
        var fd = new FormData(document.getElementById('installForm'));
        fd.set('action', 'testconn');
        var tip = document.getElementById('connTip');
        tip.style.color = '#888';
        tip.textContent = '正在测试连接…';
        fetch('install.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                tip.style.color = j.success ? '#27ae60' : '#c0392b';
                tip.textContent = j.message;
            })
            .catch(function () {
                tip.style.color = '#c0392b';
                tip.textContent = '测试请求失败，请重试';
            });
    }
    </script>
    <?php endif; ?>

    <div class="footer">&copy; <?php echo date('Y'); ?> 潜记二维码作业登记系统</div>
</div>
</body>
</html>
