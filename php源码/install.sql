-- 潜记二维码作业登记系统（单用户版） 数据库初始化脚本
-- 数据库：MySQL（utf8 / utf8mb4 字符集）
-- 也可以直接访问 install.php 自动完成建表与种子数据

CREATE DATABASE IF NOT EXISTS qj_registration_single DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
USE qj_registration_single;

-- 教师账号表（单用户版：is_admin=1 为管理员，仅安装时生成；其余为普通教师）
CREATE TABLE IF NOT EXISTS teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE COMMENT '登录账号（与 phone 一致）',
    password VARCHAR(255) NOT NULL COMMENT '密码（bcrypt哈希）',
    disabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT '账号禁用：1=已禁用（禁止登录）',
    realname VARCHAR(50) NOT NULL DEFAULT '' COMMENT '教师姓名',
    school_id INT NOT NULL DEFAULT 1 COMMENT '所属学校（单用户版恒为 1）',
    phone VARCHAR(50) NOT NULL DEFAULT '' COMMENT '手机号（登录账号，username=phone）',
    is_admin TINYINT(1) NOT NULL DEFAULT 0 COMMENT '管理员：1=管理员（仅安装时生成的账号），0=普通教师',
    created_at DATETIME NOT NULL COMMENT '注册时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='教师账号（单用户版：is_admin=1 为管理员）';

-- 平台设置表
CREATE TABLE IF NOT EXISTS settings (
    skey VARCHAR(50) PRIMARY KEY,
    svalue VARCHAR(255) NOT NULL DEFAULT '' COMMENT '设置值'
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='平台设置';

-- 班级表
CREATE TABLE IF NOT EXISTS classes (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='班级';

-- 学生表
CREATE TABLE IF NOT EXISTS students (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='学生名单';

-- 作业项目表
CREATE TABLE IF NOT EXISTS projects (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='作业项目';

-- 登记记录表
CREATE TABLE IF NOT EXISTS records (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='作业登记记录';

-- 每日打卡记录表
CREATE TABLE IF NOT EXISTS record_days (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL COMMENT '所属项目',
    student_id INT NOT NULL COMMENT '学生',
    reg_date DATE NOT NULL COMMENT '打卡日期',
    created_at DATETIME NOT NULL COMMENT '打卡时间',
    eval_value VARCHAR(255) DEFAULT NULL COMMENT '评价内容（按天，打卡模式当日等级）',
    eval_at DATETIME DEFAULT NULL COMMENT '评价时间',
    UNIQUE KEY uk_psd (project_id, student_id, reg_date),
    INDEX idx_pd (project_id, reg_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='每日打卡记录（打卡模式日历用）';

-- 项目次项/题次表
CREATE TABLE IF NOT EXISTS project_rounds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL COMMENT '所属项目',
    round_no INT NOT NULL COMMENT '次项序号（1起，删除后自动重排）',
    title VARCHAR(30) DEFAULT NULL COMMENT '轮次自定义标题（空=显示序号）',
    correct_opts VARCHAR(8) DEFAULT NULL COMMENT '正确选项（答题模式，逗号分隔可多选如 A,C；NULL=未设置）',
    created_at DATETIME NOT NULL COMMENT '创建时间',
    UNIQUE KEY uk_pr (project_id, round_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='项目次项/题次（多次登记与答题模式的轮次定义）';

-- 按次项/题次的登记与评价记录表
CREATE TABLE IF NOT EXISTS record_rounds (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='按次项/题次的登记与评价记录（多次登记/答题模式用）';

-- 教师点评表（项目维度，家长公开查询「综合报告」展示）
CREATE TABLE IF NOT EXISTS student_comments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='教师点评（项目维度）';

-- 登录活跃统计表
CREATE TABLE IF NOT EXISTS login_days (
    teacher_id INT NOT NULL COMMENT '教师',
    day DATE NOT NULL COMMENT '登录日期',
    cnt INT NOT NULL DEFAULT 1 COMMENT '当日登录次数',
    last_at DATETIME NOT NULL COMMENT '当日最后登录时间',
    PRIMARY KEY (teacher_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='登录活跃统计（个人中心使用频次卡片）';

-- 班级授权成员表（凭班级授权码申请，创建人审核）
CREATE TABLE IF NOT EXISTS class_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL COMMENT '班级',
    teacher_id INT NOT NULL COMMENT '被授权教师',
    perm ENUM('manage','create','register','view') NOT NULL DEFAULT 'view' COMMENT '授权级别：manage=管理班级 create=可建立 register=仅登记 view=仅查看',
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' COMMENT '审核状态',
    created_at DATETIME NOT NULL COMMENT '申请时间',
    handled_at DATETIME DEFAULT NULL COMMENT '处理时间',
    UNIQUE KEY uk_ct (class_id, teacher_id),
    INDEX idx_teacher (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='班级授权成员';

-- 班级转移申请表
CREATE TABLE IF NOT EXISTS class_transfers (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='班级转移申请';

-- 保持登录令牌表
CREATE TABLE IF NOT EXISTS login_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL COMMENT 'sha256(cookie token)',
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uk_token (token_hash),
    INDEX idx_teacher (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='保持登录令牌';

-- 个人置顶表（班级/作业项目）
CREATE TABLE IF NOT EXISTS pinned_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL COMMENT '教师',
    item_type ENUM('class','project') NOT NULL COMMENT '置顶对象类型',
    item_id INT NOT NULL COMMENT '对象id',
    created_at DATETIME NOT NULL COMMENT '置顶时间',
    UNIQUE KEY uk_tti (teacher_id, item_type, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='个人置顶';

-- 自定义评价模式表
CREATE TABLE IF NOT EXISTS eval_modes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL COMMENT '所属教师（自定义模式归个人所有）',
    name VARCHAR(50) NOT NULL COMMENT '模式名称',
    options TEXT COMMENT '选项内容，每行一个；留空=自由输入',
    sort INT NOT NULL DEFAULT 0 COMMENT '排序权重（小者在前）',
    created_at DATETIME NOT NULL,
    INDEX idx_teacher (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='自定义评价模式';

-- 班级导入二维码载荷表
CREATE TABLE IF NOT EXISTS import_payloads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL COMMENT '来源班级',
    code VARCHAR(16) NOT NULL COMMENT '8位提取码（二维码链接与手动提取共用）',
    content MEDIUMTEXT NOT NULL COMMENT 'bjdl 学生名单内容（潜记格式）',
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT NULL,
    UNIQUE KEY uk_class (class_id),
    UNIQUE KEY uk_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='班级导入二维码载荷';

-- 班级学生分组表
CREATE TABLE IF NOT EXISTS stu_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL COMMENT '所属班级',
    name VARCHAR(50) NOT NULL COMMENT '分组名称',
    sort INT NOT NULL DEFAULT 0 COMMENT '排序（小在前）',
    created_at DATETIME NOT NULL COMMENT '创建时间',
    INDEX idx_class (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='班级学生分组';

-- 班级喊话指令队列表
CREATE TABLE IF NOT EXISTS announce_msgs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='班级喊话指令队列';

-- 班级反向喊话队列表
CREATE TABLE IF NOT EXISTS announce_rev_msgs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='班级反向喊话队列';

-- 喊话客户端设备在线状态表
CREATE TABLE IF NOT EXISTS announce_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL COMMENT '班级',
    device_id CHAR(32) NOT NULL COMMENT '客户端设备标识（浏览器生成随机ID，localStorage 持久）',
    device_type VARCHAR(10) NOT NULL DEFAULT 'pc' COMMENT '设备类型：pc=电脑 phone=手机',
    voice_ok TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否支持语音播报（客户端TTS检测上报，0=仅字幕）',
    last_seen DATETIME NOT NULL COMMENT '最近心跳时间（announce_poll 每次轮询刷新）',
    UNIQUE KEY uk_cls_dev (class_id, device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='喊话客户端设备在线状态';

-- 答题卡模板表
CREATE TABLE IF NOT EXISTS omr_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL COMMENT '所属项目（答题卡模式项目）',
    name VARCHAR(100) NOT NULL DEFAULT '' COMMENT '模板名称',
    layout MEDIUMTEXT NOT NULL COMMENT '答题卡布局 JSON（mm 坐标 + 预计算 bubbles 涂框坐标，识别端按此采样）',
    created_at DATETIME NOT NULL COMMENT '创建时间',
    updated_at DATETIME NOT NULL COMMENT '更新时间',
    INDEX idx_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='答题卡模板';

-- 答题卡识别结果表
CREATE TABLE IF NOT EXISTS omr_results (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='答题卡识别结果';

-- 答题卡项目独立答案键表
CREATE TABLE IF NOT EXISTS omr_answers (
    project_id INT PRIMARY KEY,
    answers MEDIUMTEXT NOT NULL COMMENT '答案键 JSON（{\"1\":\"A\",\"2\":\"BD\"...}，按题号；优先于模板答案键）',
    updated_at DATETIME NOT NULL COMMENT '最近录入时间',
    updated_by INT NOT NULL DEFAULT 0 COMMENT '录入教师'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='答题卡项目独立答案键';

-- 答题卡共享模板库（单用户版：本人模板 + 本班模板共享）
CREATE TABLE IF NOT EXISTS omr_lib (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='答题卡共享模板库';

-- 答题卡批改图留存表
CREATE TABLE IF NOT EXISTS omr_images (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='答题卡批改图留存';

-- 课堂表现积分项配置表
CREATE TABLE IF NOT EXISTS points_items (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='课堂表现积分项配置';

-- 课堂表现积分流水表
CREATE TABLE IF NOT EXISTS points_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='课堂表现积分流水';

-- 项目自动积分规则表
CREATE TABLE IF NOT EXISTS points_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL COMMENT '所属项目',
    rtype VARCHAR(30) NOT NULL COMMENT '规则类型',
    opt VARCHAR(50) NOT NULL DEFAULT '' COMMENT '规则参数（eval_opt=匹配评价内容；阈值类=数字阈值）',
    value INT NOT NULL DEFAULT 0 COMMENT '分值（正=加分 负=扣分）',
    enabled TINYINT NOT NULL DEFAULT 1 COMMENT '是否启用（1=启用 0=停用）',
    sort INT NOT NULL DEFAULT 0 COMMENT '排序（小在前）',
    INDEX idx_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='项目自动积分规则';

-- 积分兑换礼品表
CREATE TABLE IF NOT EXISTS points_gifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL COMMENT '所属班级（按班配置）',
    school_id INT NOT NULL DEFAULT 1 COMMENT '所属学校（冗余快照，单用户版恒为 1）',
    name VARCHAR(50) NOT NULL COMMENT '礼品名称',
    cost INT NOT NULL DEFAULT 1 COMMENT '兑换所需积分',
    enabled TINYINT NOT NULL DEFAULT 1 COMMENT '是否启用（1=启用 0=下架）',
    sort INT NOT NULL DEFAULT 0 COMMENT '排序（小在前）',
    INDEX idx_class (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='积分兑换礼品';

-- ===== 种子数据 =====

-- 平台设置：允许自主注册
INSERT IGNORE INTO settings (skey, svalue) VALUES ('0_allow_register', '1');

-- 管理员账号（密码为 123456 的 bcrypt 哈希，请登录后立即修改）
-- username 与 phone 均为 admin（登录按 phone 匹配）
INSERT INTO teachers (username, password, realname, school_id, phone, is_admin, created_at)
VALUES ('admin', '$2y$10$a3gzLNtiA1JzPy/zjxOAf.P7QVdlfD74ausJywuMgmBJ9zidbgcqa', '管理员', 1, 'admin', 1, NOW());
