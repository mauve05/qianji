<?php
/**
 * 答题卡扫描识别页（答题卡 / OMR 涂卡识别模式）
 * 摄像头拍照 / 照片上传 → 浏览器本地图像处理（不传原图到服务器）：
 *   灰度 → 自适应二值化 → 定位四角黑方块（连通域+孤立性校验；凸四边形合理性按模板横纵比自适应放宽，
 *     横长卡不被误杀；细长暗线不干扰；漏检时腐蚀重检）
 *   → 多候选四边形 × 4 朝向粗矫正（单应透视；评分=印刷框对齐 + 填涂 + 页头定位点×1.2 + 模板横纵比一致度，
 *     方向由几何/内容共同确定） → 印刷框网格吸附精调（refineDst：以涂框印刷线为基准，
 *     平移/旋转/缩放五参数多起点下降，根治标记漏检/纸边误判导致的批注错位与裁切不正）
 *   → 按模板 bubbles（毫米坐标）采样每个涂框的黑像素占比 → 相对判别（阈值仅作噪声底线）：
 *     单选取最明显一项（唯一取值）、多选取「达阈值且 ≥ 最深项 45%」集合、身份位需比次高明显 1.3×
 *   → 身份涂号区解析学生（座号/编号，兼容前导零） → api.php omr_submit 登记学生、按模板答案键判分并同步评价值
 * 识别记录可在页面底部删除重扫；结果亦可在项目页修正/导出
 */
require_once 'includes/db_config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/layout.php';

// 识别算法/前端脚本更新频繁，禁止缓存：微信内置浏览器等激进缓存场景必须每次取最新页面
header('Cache-Control: no-store, no-cache, must-revalidate');

$conn = getConnection();

$project_id = intval($_GET['project_id'] ?? 0);
$project = null;
if ($project_id > 0) {
    foreach (get_visible_projects($conn, $current_teacher_id) as $vp) {
        if (intval($vp['id']) === $project_id) { $project = $vp; break; }
    }
}
if (!$project || (($project['mode'] ?? '') !== 'omr')) {
    page_header('答题卡识别', 'omr_scan.php');
    echo '<div class="panel" style="text-align:center;padding:50px 20px;">'
        . '<div style="font-size:46px;margin-bottom:8px;">📷</div>'
        . '<h3 style="margin-bottom:8px;">项目不存在或非「答题卡」模式项目</h3>'
        . '<a href="projects.php" class="btn" style="margin-top:14px;">前往项目列表</a></div>';
    page_footer();
    exit;
}

page_header('答题卡识别', 'omr_scan.php');

// 题次（与 project_view 答题/举牌模式同机制）：?round= 切换；round_add / round_rename / round_delete 管理
$rounds = project_rounds_list($conn, $project_id);
$current_round = intval($_GET['round'] ?? 0);
if (!in_array($current_round, $rounds, true)) $current_round = $rounds[count($rounds) - 1];
$round_titles = project_rounds_titles($conn, $project_id);
// 批改图上传权限（后台「批改图上传服务器（分角色）」勾选；管理员/总管理员始终可）：
// 决定登记成功后批注图走服务器留存（omr_images 表）还是本机浏览器缓存（IndexedDB），两种均支持导出
$img_upload_ok = omrimg_upload_ok($conn, $current_teacher_id);
?>
<style>
.omr-toolbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; padding:12px 14px 6px; }
.omr-grid { display:flex; gap:14px; align-items:flex-start; padding:8px 14px 24px; flex-wrap:wrap; }
.omr-col { flex:1; min-width:300px; }
.omr-col.side { flex:0 0 340px; }
#camVideo { width:100%; max-width:520px; border-radius:10px; background:#000; display:block; }
#rectCanvas { width:100%; border:1px solid #e3e6f2; border-radius:8px; background:#fff; }
.res-chip { display:inline-block; min-width:26px; text-align:center; padding:2px 6px; margin:2px 3px 2px 0;
    border-radius:6px; border:1px solid #d0d4e8; background:#fff; font-size:13px; cursor:pointer; user-select:none; }
.res-chip.filled { background:#27ae60; border-color:#27ae60; color:#fff; font-weight:bold; }
.res-chip.amb { background:#f39c12; border-color:#f39c12; color:#fff; font-weight:bold; }
.res-chip.editing { outline:2px dashed #667eea; }
.res-title { font-size:13px; font-weight:bold; color:#555; margin:8px 0 4px; }
.flag-badge { display:inline-block; background:#fdecea; color:#c0392b; border:1px solid #f5c6cb;
    border-radius:6px; padding:2px 8px; font-size:12px; margin:2px 4px 2px 0; }
.ok-badge { display:inline-block; background:#d4edda; color:#155724; border:1px solid #c3e6cb;
    border-radius:6px; padding:2px 8px; font-size:12px; margin:2px 4px 2px 0; }
.rlist-item { display:flex; gap:8px; align-items:center; padding:7px 4px; border-bottom:1px dashed #e3e6f2; font-size:13px; flex-wrap:wrap; }
#toast { position:fixed; left:50%; bottom:80px; transform:translateX(-50%); background:rgba(40,44,60,0.92); color:#fff;
    padding:10px 22px; border-radius:24px; font-size:14px; z-index:2000; display:none; box-shadow:0 4px 14px rgba(0,0,0,0.3); max-width:80vw; }
input[type=range] { vertical-align:middle; }
.ans-chip { display:inline-block; min-width:34px; text-align:center; padding:1px 5px; margin:2px 3px 2px 0; border-radius:6px; font-size:12px; border:1px solid #d0d4e8; background:#fff; cursor:pointer; }
.ans-chip.has { background:#d4edda; border-color:#c3e6cb; color:#155724; font-weight:bold; }
.ans-chip.miss { background:#fdecea; border-color:#f5c6cb; color:#c0392b; font-weight:bold; }
#ansModal .key-row { display:flex; align-items:center; gap:4px; padding:2px 0; }
#ansModal .key-row .kq { width:56px; font-size:12px; color:#555; flex-shrink:0; }
#ansModal .key-btn { min-width:26px; height:24px; border:1px solid #d0d4e8; background:#fff; border-radius:5px; font-size:12px; cursor:pointer; padding:0 4px; }
#ansModal .key-btn.on { background:#667eea; border-color:#667eea; color:#fff; font-weight:bold; }
#ansModal .ans-sec { font-size:13px; font-weight:bold; color:#555; margin:10px 0 4px; }
/* 批改图库弹层 */
#imgModal .img-tab { padding:4px 14px; border-radius:16px; border:1px solid #d0d4e8; background:#fff; cursor:pointer; font-size:13px; }
#imgModal .img-tab.on { background:#667eea; border-color:#667eea; color:#fff; font-weight:bold; }
#imgGrid { display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:10px; }
.img-card { border:1px solid #e3e6f2; border-radius:8px; padding:6px; font-size:12px; display:flex; flex-direction:column; gap:4px; background:#fff; }
.img-card img { width:100%; height:110px; object-fit:contain; background:#f5f6fb; border-radius:4px; }
.img-card .img-meta { color:#666; line-height:1.5; word-break:break-all; }
.img-card .img-ops { display:flex; gap:4px; flex-wrap:wrap; align-items:center; }
@media (max-width: 900px) { .omr-col.side { flex:1 1 100%; } }
</style>

<div class="omr-toolbar">
    <?php
    // 项目上下文标识（与 scan.php 一致）：题次项目显示当前题次及自定义名称（project_view 改名），便于区分当前登的是哪一次
    $os_ctx = '第 ' . $current_round . ' 题次';
    if (isset($round_titles[$current_round]) && strval($round_titles[$current_round]) !== '') {
        $os_ctx .= '「' . $round_titles[$current_round] . '」';
    }
    ?>
    <b>📷 <?php echo htmlspecialchars($project['name']); ?><span style="color:#667eea;">（<?php echo htmlspecialchars($os_ctx); ?>）</span> · 答题卡识别</b>
    <span style="color:#d0d4e8;">|</span>
    <span style="font-size:13px;color:#555;">🧇 模板：<b id="tplBound" style="color:#667eea;">加载中…</b></span>
    <a class="btn btn-sm btn-outline" style="text-decoration:none;" href="omr_designer.php?project_id=<?php echo $project_id; ?>">🧇 去设计模板</a>
    <span style="margin-left:auto;display:flex;align-items:center;gap:8px;font-size:13px;color:#666;">
        <button type="button" class="btn btn-sm btn-outline" id="autoRegBtn" onclick="toggleAutoReg()">⚡ 自动登记</button>
        <button type="button" class="btn btn-sm btn-outline" onclick="openImgGallery()">🖼 批改图库</button>
        <label>填涂判定阈值 <b id="thrVal">0.10</b></label>
        <input type="range" id="thrSlider" min="5" max="70" value="10" oninput="onThrChange(this.value)" style="width:110px;" autocomplete="off">
    </span>
    <?php
    // 题次切换条（与 project_view 胶囊条同款，scan/omr_scan 共用 assets/js/round_switch.js）：
    // ◀▶ 平移显示窗口 + ＋新增 + ⏳展开面板 + ⚙管理弹窗；仅 operate 权限显示管理功能，登记权限仅切换
    $round_admin_ok = (bool)get_project_for($conn, $project_id, $current_teacher_id, 'operate');
    ?>
    <span id="rsSwitch" style="flex-basis:100%;"></span>
</div>
<script>
var RS_DATA = { pid: <?php echo intval($project_id); ?>, page: 'omr_scan.php', label: '题次',
    rounds: <?php echo json_encode(array_map('intval', $rounds)); ?>, current: <?php echo intval($current_round); ?>,
    titles: <?php echo json_encode($round_titles, JSON_UNESCAPED_UNICODE); ?>, admin: <?php echo $round_admin_ok ? 'true' : 'false'; ?> };
</script>
<script src="assets/js/round_switch.js?v=1"></script>

<div class="omr-grid">
    <div class="omr-col">
        <div class="panel">
            <h3>📸 拍照识别 <button type="button" class="hint-q" onclick="toggleHint(event, '<b>拍照识别说明</b><br>· 进入页面自动开启摄像头（未检测到设备会提示，插入设备后自动重开）<br>· 每次进入页面会弹出「📷 拍摄指引」动画演示：定位点全入镜 / 取景≤卡面2倍 / 自动识别等结果出现再移开<br>· 对准整张答题卡（<b>四角黑色定位方块完整入镜</b>），系统自动定位并拍照识别，识别完毕即可换下一张<br>· 也可点「拍照」手动拍摄或「上传照片识别」<br>· 多个摄像头时可用下拉框或「🔄 切换摄像头」按钮切换，均按设备最高分辨率采集<br>· 照片仅在本浏览器内处理，<b>不会上传</b>')">?</button></h3>
            <div id="camBox">
                <video id="camVideo" autoplay playsinline muted></video>
                <div class="toolbar" style="justify-content:center;flex-wrap:wrap;">
                    <button type="button" class="btn btn-sm" id="camStartBtn" onclick="startCam()">开始识别</button>
                    <button type="button" class="btn btn-sm btn-success" id="snapBtn" onclick="snapCam()" style="display:none;">拍照</button>
                    <button type="button" class="btn btn-sm btn-outline" id="camStopBtn" onclick="stopCam()" style="display:none;">关闭摄像头</button>
                    <select id="camSelect" style="display:none;width:auto;max-width:170px;min-width:110px;padding:3px 6px;font-size:12px;" onchange="switchCam(this.value)" title="选择摄像头（选中立即切换）"></select>
                    <button type="button" class="btn btn-sm btn-outline" id="camCycleBtn" onclick="cycleCam()" style="display:none;" title="在多个摄像头之间轮流切换">🔄 切换摄像头</button>
                    <button type="button" class="btn btn-sm btn-outline" id="autoBtn" onclick="toggleAuto()" style="display:none;">🤖 自动识别</button>
                    <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('omrFile').click()">🖼 上传照片识别</button>
                    <input type="file" id="omrFile" accept="image/*" multiple style="display:none;" onchange="fileRecognize(this)" autocomplete="off">
                </div>
            </div>
        </div>

        <div class="panel" id="rectPanel" style="display:none;">
            <h3>🔍 矫正与识别预览 <button type="button" class="hint-q" onclick="toggleHint(event, '<b>识别预览批注说明</b><br>· <b>绿√</b>=所涂正确；<b>红✗</b>=涂错；<b>橙?</b>=未涂/无法识别<br>· <b>绿框</b>=已填涂，<b>黄框</b>=疑似重涂<br>· 按生效答案键自动判对错：<b>模板答案最优先</b>，独立录入仅补缺<br>· 登记成功后本图自动留存并<b>强制上传服务器</b>（失败自动回退本浏览器并提示）。「🖼 批改图库」的本机缓存/服务器标签仅切换查看位置（默认显示服务器），不影响保存方式') ">?</button> <?php if (is_super_admin($conn, $current_teacher_id)): ?><button type="button" class="btn btn-sm btn-outline" id="dbgBtn" style="float:right;padding:1px 8px;font-size:12px;font-weight:normal;" onclick="toggleDbg()" title="管理员调试：在彩色原图批注与黑白二值矫正图之间切换">🐞 调试图</button><?php endif; ?></h3>
            <canvas id="rectCanvas"></canvas>
        </div>
    </div>

    <div class="omr-col side">
        <div class="panel" id="resPanel" style="display:none;">
            <h3>🧾 识别结果 <span id="resState" style="font-size:12px;color:#888;"></span></h3>
            <div id="resIdentity" style="font-size:15px;margin-bottom:6px;"></div>
            <div id="resLow" style="display:none;margin:6px 0;padding:7px 10px;border-radius:8px;background:#fff7ed;border:1px solid #fdba74;color:#c2410c;font-size:13px;font-weight:bold;">⚠ 无法识别/未涂的题超过 30%：请降低「填涂判定阈值」后重新判定，或更换为「上传照片识别」</div>
            <div id="resFlags"></div>
            <div id="resAnswers"></div>
            <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" class="btn btn-sm" id="submitBtn" onclick="submitResult()">✅ 登记该生</button>
                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('manualIdent').focus()">改身份</button>
            </div>
            <div id="manualIdentBox" style="display:none;margin-top:8px;">
                <input type="text" id="manualIdent" class="form-control" style="max-width:200px;display:inline-block;padding:7px 10px;font-size:14px;" placeholder="输入识别出的<?php echo '座号/编号'; ?>" autocomplete="nope" readonly onfocus="this.removeAttribute('readonly')" title="点击即可输入（浏览器不会自动填充只读输入框）">
                <button type="button" class="btn btn-sm" onclick="submitResult()">登记</button>
            </div>
            <div id="resStudent" style="margin-top:8px;font-size:14px;"></div>
        </div>

        <div class="panel" id="ansPanel" style="display:none;">
            <h3>🔑 答案覆盖检查 <span id="ansState" style="font-size:12px;color:#888;"></span></h3>
            <div id="ansSummary" style="margin-bottom:6px;"></div>
            <div id="ansGrid" style="max-height:240px;overflow:auto;"></div>
            <div style="margin-top:8px;">
                <button type="button" class="btn btn-sm" onclick="ansOpenModal()">🔑 录入答案</button>
                <span class="tip" style="margin-left:6px;">点 ❌ 题号可直接补录该题</span>
            </div>
        </div>

        <div class="panel">
            <h3>📋 识别记录 <button type="button" class="btn btn-sm btn-outline" style="float:right;" onclick="loadRecords()">刷新</button></h3>
            <div id="recordList"><p class="tip">暂无记录</p></div>
        </div>
    </div>
</div>

<div id="toast"></div>

<!-- 录入答案弹层（识别页补完答案：独立于模板存储，改答案不影响已打印答题卡识别） -->
<div id="ansModal" style="display:none;position:fixed;inset:0;background:rgba(20,24,40,0.45);z-index:1500;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">
    <div style="background:#fff;border-radius:12px;max-width:640px;width:100%;max-height:84vh;display:flex;flex-direction:column;box-shadow:0 10px 40px rgba(0,0,0,0.25);">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px 8px;">
            <h3 style="margin:0;color:#667eea;">🔑 录入答案</h3>
            <button type="button" class="btn btn-sm btn-outline" onclick="ansModalClose()">关闭</button>
        </div>
        <p class="tip" style="margin:0 20px 8px;">🔑 答案补录说明<button type="button" class="hint-q" onclick="toggleHint(event, '<b>🔑 答案补录说明</b><br>· 模板答案最优先，此处仅可补录模板未设答案的题（模板已设的题锁定不可改）<br>· 合并后覆盖全部题目时：识别自动批改并重算已登记结果<br>· 不完整时：仅登记，或按强制批改口径批改')">?</button></p>
        <div id="amBody" style="flex:1;overflow:auto;padding:0 20px 10px;"></div>
        <div style="border-top:1px dashed #e3e6f2;padding:12px 20px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <button type="button" class="btn btn-sm" id="amSaveBtn" onclick="ansModalSave()">💾 保存答案</button>
            <button type="button" class="btn btn-sm btn-outline" id="amClearBtn" onclick="ansModalClear()" style="display:none;" title="删除独立答案，改回使用模板自带答案键">🗑 清除独立答案（改用模板答案键）</button>
        </div>
    </div>
</div>

<!-- 批改图库弹层（登记成功保存的批注预览图：本机缓存 / 服务器两标签仅切换查看来源，保存路径按权限自动决定；默认显示服务器） -->
<div id="imgModal" style="display:none;position:fixed;inset:0;background:rgba(20,24,40,0.45);z-index:1500;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">
    <div style="background:#fff;border-radius:12px;max-width:860px;width:100%;max-height:86vh;display:flex;flex-direction:column;box-shadow:0 10px 40px rgba(0,0,0,0.25);">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 20px 8px;gap:10px;flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:10px;">
                <h3 style="margin:0;color:#667eea;">🖼 批改图库</h3>
                <button type="button" class="img-tab" id="imgTabLocal" onclick="switchImgTab('local')" title="查看本机缓存（IndexedDB）中的批改图（仅切换查看位置，不影响保存方式）">💾 本机缓存</button>
                <?php if ($img_upload_ok): ?><button type="button" class="img-tab" id="imgTabServer" onclick="switchImgTab('server')" title="查看已上传服务器的批改图（登记成功时自动强制上传；仅切换查看位置，不影响保存方式）">☁ 服务器</button><?php endif; ?>
            </div>
            <button type="button" class="btn btn-sm btn-outline" onclick="closeImgGallery()">关闭</button>
        </div>
        <p class="tip" style="margin:0 20px 8px;"><span id="imgTipServer" style="display:none;">☁ 查看服务器批改图<button type="button" class="hint-q" onclick="toggleHint(event, '<b>☁ 服务器批改图</b><br>· 开启权限后，登记成功即自动强制上传到服务器<br>· 按项目留存，换设备也能查看和导出<br>· 本机缓存 / 服务器 标签仅切换查看位置，不影响保存方式')">?</button></span><span id="imgTipLocal" style="display:none;">💾 查看本机缓存批改图<button type="button" class="hint-q" onclick="toggleHint(event, '<b>💾 本机缓存批改图</b><br>· 仅存于当前浏览器（IndexedDB）：开启服务器权限前登记、或上传失败回退的图<br>· 清浏览器数据会丢失<br>· 本机缓存 / 服务器 标签仅切换查看位置，不影响保存方式')">?</button></span></p>
        <div id="imgGrid" style="flex:1;overflow:auto;padding:4px 20px 10px;"></div>
        <div style="border-top:1px dashed #e3e6f2;padding:12px 20px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <label style="display:inline-flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;"><input type="checkbox" id="imgSelAll" onchange="imgSelToggleAll(this.checked)" autocomplete="off"> 全选</label>
            <button type="button" class="btn btn-sm" onclick="imgExportZip()">⬇ 批量导出（zip）</button>
            <span class="tip" style="margin-left:6px;">导出说明<button type="button" class="hint-q" onclick="toggleHint(event, '<b>批量导出说明</b><br>· 勾选后：仅导出勾选的批改图<br>· 不勾选：默认导出当前标签的全部批改图')">?</button></span>
        </div>
    </div>
</div>

<!-- 拍摄指引弹层（每次进入页面显示：动画演示取景要点，提高识别率） -->
<div id="shotGuideMask" style="display:none;position:fixed;inset:0;background:rgba(20,24,40,0.62);z-index:1600;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">
    <div style="background:#fff;border-radius:14px;max-width:400px;width:100%;max-height:92vh;overflow:auto;box-shadow:0 10px 40px rgba(0,0,0,0.3);padding:18px 20px 16px;box-sizing:border-box;">
        <h3 style="margin:0 0 4px;color:#667eea;font-size:17px;">📷 答题卡拍摄指引</h3>
        <p class="tip" style="margin:0 0 10px;">按下方动画取景更易识别成功</p>
        <div id="sgStage" style="position:relative;height:200px;border-radius:10px;background:#eef1f7;overflow:hidden;margin-bottom:8px;">
            <div id="sgCard" style="position:absolute;left:calc(50% - 65px);top:15px;width:130px;height:170px;background:#fff;border:1px solid #d8dce8;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,0.12);transition:all 0.9s ease;z-index:2;overflow:hidden;"></div>
            <div id="sgFrame" style="position:absolute;left:calc(50% - 78px);top:6px;width:156px;height:96px;border:2.5px dashed #e53e3e;border-radius:6px;box-sizing:border-box;transition:all 0.9s ease;z-index:3;"></div>
        </div>
        <div id="sgCaption" style="text-align:center;font-size:13.5px;font-weight:bold;min-height:22px;margin-bottom:8px;color:#e53e3e;">✗ 只拍到半张卡——底部定位点在镜外，识别失败</div>
        <div style="font-size:13px;line-height:1.9;color:#444;background:#f7f8fc;border-radius:8px;padding:8px 12px;margin-bottom:12px;">
            <b style="color:#c2410c;">① 定位点全部入镜：</b>四角与页头黑色方块一个都不能少（被裁切/遮挡必失败）<br>
            <b style="color:#1a56db;">② 取景别太远：</b>拍摄范围不要超过答题卡区域的 2 倍，卡占画面越大越清晰<br>
            <b style="color:#276749;">③ 自动识别模式：</b>务必等屏幕出现识别结果后，再移开答题卡换下一张
        </div>
        <button type="button" class="btn" style="width:100%;padding:10px 0;font-size:15px;" onclick="closeShotGuide()">我知道了，开始拍摄</button>
    </div>
</div>
<script>
/* 拍摄指引动画：每次进入页面弹出，四阶段循环演示「✗半张→✓全卡→⏳识别中→✅完成移开」 */
var sgTimer = null, sgPhase = 0;
function sgBuild() {
    var c = document.getElementById('sgCard'); if (!c) return;
    var h = '', i, j, x, y;
    // 定位黑块：四角 + 页头两枚
    var marks = [[5, 5], [117, 5], [5, 157], [117, 157], [45, 6], [85, 6]];
    for (i = 0; i < marks.length; i++) h += '<div style="position:absolute;left:' + marks[i][0] + 'px;top:' + marks[i][1] + 'px;width:8px;height:8px;background:#111;border-radius:1px;"></div>';
    // 标题条
    h += '<div style="position:absolute;left:25px;top:22px;width:80px;height:8px;background:#c9cede;border-radius:2px;"></div>';
    // 涂卡行×3（第2行第3格演示已涂黑）
    for (i = 0; i < 3; i++) {
        y = 48 + i * 30;
        for (j = 0; j < 6; j++) {
            x = 26 + j * 14;
            var fill = (i === 1 && j === 2) ? '#333' : '#fff';
            h += '<div style="position:absolute;left:' + x + 'px;top:' + y + 'px;width:9px;height:11px;border:1px solid #7a8194;border-radius:2px;background:' + fill + ';box-sizing:border-box;"></div>';
        }
    }
    c.innerHTML = h;
}
function sgSet(p) {
    var f = document.getElementById('sgFrame'), c = document.getElementById('sgCard'), t = document.getElementById('sgCaption');
    if (!f || !c || !t) return;
    if (p === 0) {
        f.style.left = 'calc(50% - 78px)'; f.style.top = '6px'; f.style.width = '156px'; f.style.height = '96px'; f.style.borderColor = '#e53e3e';
        c.style.transform = 'none'; c.style.opacity = '1';
        t.textContent = '✗ 只拍到半张卡——底部定位点在镜外，识别失败'; t.style.color = '#e53e3e';
    } else if (p === 1) {
        f.style.left = 'calc(50% - 80px)'; f.style.top = '6px'; f.style.width = '160px'; f.style.height = '188px'; f.style.borderColor = '#38a169';
        c.style.transform = 'none'; c.style.opacity = '1';
        t.textContent = '✓ 定位点全部入镜，取景不超过卡面 2 倍，识别成功'; t.style.color = '#38a169';
    } else if (p === 2) {
        f.style.borderColor = '#d69e2e';
        t.textContent = '⏳ 自动识别中——出现结果前别移开答题卡'; t.style.color = '#b7791f';
    } else {
        c.style.transform = 'translateX(150px) rotate(12deg)'; c.style.opacity = '0';
        f.style.borderColor = '#38a169';
        t.textContent = '✅ 识别完毕！等结果出现再移开，换下一张'; t.style.color = '#38a169';
    }
}
function sgShow() {
    var m = document.getElementById('shotGuideMask'); if (!m) return;
    m.style.display = 'flex';
    sgBuild(); sgPhase = 0; sgSet(0);
    if (sgTimer) clearInterval(sgTimer);
    sgTimer = setInterval(function () { sgPhase = (sgPhase + 1) % 4; sgSet(sgPhase); }, 2600);
}
function closeShotGuide() {
    var m = document.getElementById('shotGuideMask');
    if (m) m.style.display = 'none';
    if (sgTimer) { clearInterval(sgTimer); sgTimer = null; }
}
sgShow();
</script>
<script>
var PROJECT_ID = <?php echo $project_id; ?>;
var IS_ADMIN = <?php echo is_super_admin($conn, $current_teacher_id) ? 'true' : 'false'; ?>;   // 管理员：可切换黑白二值调试图
var CURRENT_ROUND = <?php echo $current_round; ?>;   // 当前题次（omr 项目与举手/答题模式共用轮次机制）
var LETTERS = ['A', 'B', 'C', 'D', 'E'];
var ID_LABEL_H = 4.5, COLHDR_H = 4, QW = 7, VAL_H = 3.2;   // 与设计器保持一致的几何常量
var BLANK_ROW_H = 8, LINE_H = 4.4, MAXP = 5;                // 填空行高 / 简答题目行高 / 最大页数（与设计器同源）
var TPL = null, TID = 0;
var THR = 0.10;   // 填涂判定阈值（灰度对比分绝对下限，可调 0.05-0.70）。判定信号 = 框内均值灰度
                  // 相对框周纸面环带的「变暗程度」（computeGrayScores）：空白框≈0-0.03、铅笔轻涂≈0.08-0.25、深涂≥0.4，
                  // 环带与框同处局部光照、阴影/过曝自消——比二值占比灵敏（二值化会把浅铅笔画成背景）。
                  // 阈值只作噪声底线，判别主要靠相对差距：单选取最明显项（达阈值且比次高明显 1.35×，或深涂 ≥0.45）；
                  // 多选取达阈值且 ≥ 最深项 45% 的集合；身份位需比次高明显 1.3×。
var CUR = null;          // 最近一次识别：{ident, identOk, answers, flags, ratios, qmap, qstate, unkCnt, lowRate}
var stream = null;
var AUTO = false, autoBusy = false, autoStable = 0, autoTimer = null, autoHold = false, autoRetryN = 0;   // 自动定位识别（默认关，按需手动开启；autoHold=当前卡已拍待处理；autoRetryN=拒识后连续自动重试次数）
var IMG_UPLOAD_OK = <?php echo $img_upload_ok ? 'true' : 'false'; ?>;   // 批改图上传服务器权限（管理员 / 学校分角色授权 / 个人账号由总管理员后台「个人账号功能开关」开启）；true=登记成功强制上传服务器（失败回退本机缓存），false=仅存本机缓存
var AUTOREG = localStorage.getItem('qj_omr_autoreg') !== '0';           // ⚡ 自动登记（默认开；仅上次显式关闭才关，本机记忆）

// ===== ⚡ 自动登记开关（开启后：识别匹配到学生即自动登记，浮窗提示「x号xxx 登记成功」） =====
function renderAutoReg() {
    var b = document.getElementById('autoRegBtn');
    b.textContent = '⚡ 自动登记';
    b.style.background = AUTOREG ? '#667eea' : '';
    b.style.borderColor = AUTOREG ? '#667eea' : '';
    b.style.color = AUTOREG ? '#fff' : '';
}
function toggleAutoReg() {
    AUTOREG = !AUTOREG;
    try { localStorage.setItem('qj_omr_autoreg', AUTOREG ? '1' : '0'); } catch (e) {}
    renderAutoReg();
    toast(AUTOREG ? '自动登记已开启：识别匹配到学生后自动登记（无需手动点击）'
                  : '自动登记已关闭：识别后需手动点「✅ 登记该生」');
}

// ===== 基础 =====
function toast(msg, ms) {
    var t = document.getElementById('toast');
    t.textContent = msg; t.style.display = 'block';
    clearTimeout(t._h); t._h = setTimeout(function () { t.style.display = 'none'; }, ms || 2600);
}
function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); }
function apiPost(params, cb) {
    var fd = new FormData();
    Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
    fetch('api.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) { cb(d || { success: false, message: '响应为空' }); })
        .catch(function () { cb({ success: false, message: '网络错误，请重试' }); });
}

// ===== 题次的增/改/删已移至 project_view.php 题次胶囊管理（本页仅按 ?round= 只读使用当前题次） =====

// ===== 自动定位识别：摄像头画面连续检测到四角定位标记 → 自动拍照识别 =====
function renderAutoBtn() {
    var b = document.getElementById('autoBtn');
    b.textContent = '🤖 自动识别';
    b.style.background = AUTO ? '#667eea' : '';
    b.style.borderColor = AUTO ? '#667eea' : '';
    b.style.color = AUTO ? '#fff' : '';
}
function toggleAuto() {
    AUTO = !AUTO;
    renderAutoBtn();
    autoStable = 0;
    toast(AUTO ? '自动识别已开启：对准答题卡将自动拍摄识别' : '自动识别已关闭（请用「拍照识别」手动拍摄）');
    if (AUTO && stream && !autoTimer) autoTick();
}
function grabFrame() {
    var v = document.getElementById('camVideo');
    if (!v || !v.videoWidth) return null;
    var c = document.createElement('canvas');
    var sc = Math.min(1, 640 / v.videoWidth);
    c.width = Math.max(64, Math.round(v.videoWidth * sc));
    c.height = Math.max(64, Math.round(v.videoHeight * sc));
    c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
    return c;
}
function marksFound(canvas) {
    if (!canvas) return false;
    try {
        var ctx = canvas.getContext('2d');
        var data = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
        var w = canvas.width, h = canvas.height, gray = new Uint8Array(w * h);
        for (var i = 0, p = 0; i < w * h; i++, p += 4)
            gray[i] = (data[p] * 299 + data[p + 1] * 587 + data[p + 2] * 114) / 1000;
        GRAY = gray;
        var byQ = findMarks(findComponents(binarize(gray, w, h), w, h), w, h);
        return byQ.every(function (a) { return a.length > 0; });
    } catch (e) { return false; }
}
function autoTick() {
    autoTimer = null;
    if (!stream || !AUTO) return;
    var visible = marksFound(grabFrame());
    if (autoHold) {
        if (!visible) { autoHold = false; autoRetryN = 0; }   // 上一张已移开，恢复自动拍摄（重试计数一并清零）
    } else if (visible && !autoBusy && TPL) {
        autoStable++;
        if (autoStable >= 4) {   // 连续 4 帧（约 1.3s）稳定检测到四角标记 → 自动拍照
            autoStable = 0; autoHold = true; autoBusy = true;
            toast('检测到答题卡，自动识别中…', 6000);
            var v = document.getElementById('camVideo');
            var c = document.createElement('canvas');
            c.width = v.videoWidth; c.height = v.videoHeight;
            c.getContext('2d').drawImage(v, 0, 0);
            setTimeout(function () {
                try { recognize(c); } catch (e) { toast('识别异常：' + e.message); }
                autoBusy = false;
                // 拒识后自动重拍（无需移开卡）：手持微抖/对焦逐帧不同，重拍一帧常即通过；
                // 连续 3 次仍拒则等待移卡重呈，避免坏卡无限循环拒识
                if (!CUR && autoRetryN < 3) {
                    autoRetryN++;
                    setTimeout(function () { autoHold = false; }, 1200);
                }
            }, 60);
        }
    } else {
        autoStable = 0;
    }
    autoTimer = setTimeout(autoTick, 320);
}
function onThrChange(v) {
    THR = parseInt(v, 10) / 100;
    document.getElementById('thrVal').textContent = THR.toFixed(2);
    if (CUR && CUR.rbin) extractAnswers();   // 仅重新判定，不重新矫正
}

// ===== 几何（与设计器同源；模板缺少 bubbles 时可现场重算） =====
// 值头：横向（v，每位一行 0-9 横排）顶部一行 0-9；纵向（h，每位一列 0-9 竖排）左侧一列 0-9
function idGeom(L) {
    var id = L.id, headW = 4, colW = id.bw + id.hgap;
    var vert = (id.orient === 'v');
    var g = vert
        ? { x: id.x, y: id.y, w: headW + 10 * colW + 1, h: ID_LABEL_H + VAL_H + id.digits * (id.bh + id.vgap), bubbles: [] }
        : { x: id.x, y: id.y, w: headW + id.digits * colW + 1, h: ID_LABEL_H + 10 * (id.bh + id.vgap), bubbles: [] };
    for (var i = 0; i < id.digits; i++)
        for (var v = 0; v <= 9; v++)
            g.bubbles.push(vert
                ? { t: 'id', i: i, v: String(v),
                    x: g.x + headW + v * colW + (colW - id.bw) / 2,
                    y: g.y + ID_LABEL_H + VAL_H + i * (id.bh + id.vgap), w: id.bw, h: id.bh }
                : { t: 'id', i: i, v: String(v),
                    x: g.x + headW + i * colW + (colW - id.bw) / 2,
                    y: g.y + ID_LABEL_H + v * (id.bh + id.vgap), w: id.bw, h: id.bh });
    return g;
}
function secGeom(L, sec, sIdx) {
    if (sec.kind === 'blank') {
        // 填空题（与设计器 secGeom 同源）：每行「题号 ______」，一行 perRow 列、横线 lineLen mm；无涂框（手写题不判分）
        var slotB = QW + sec.lineLen, rowsB = Math.ceil(sec.count / sec.perRow);
        return { x: sec.x, y: sec.y, w: Math.min(sec.count, sec.perRow) * slotB,
                 h: ID_LABEL_H + rowsB * BLANK_ROW_H, bubbles: [] };
    }
    if (sec.kind === 'short') {
        // 简答题（与设计器 secGeom 同源）：题号+题目占一整行（设宽后按宽自动换行），下方虚线作答框
        var tw2 = sec.qtext ? textW({ text: sec.qtext, size: 3.2 }) : 0;
        var w2 = sec.boxW > 0 ? sec.boxW : Math.min(L.paper.w - sec.x - 8, Math.max(60, QW + tw2 + 2));
        var lines = sec.qtext ? Math.max(1, Math.ceil(tw2 / Math.max(1, w2 - QW))) : 0;
        var boxY = sec.y + ID_LABEL_H + (lines ? lines * LINE_H + 1 : 0);
        return { x: sec.x, y: sec.y, w: Math.max(w2, QW + 4), h: (boxY - sec.y) + sec.boxH, bubbles: [] };
    }
    var slotW = QW + sec.opts * (sec.bw + sec.ogap);
    var rows = Math.ceil(sec.count / sec.perRow);
    // 与设计器同源：列头带单条（字母按列标注），涂框自列头带下方按行排布；x/y/w/h 为块 bbox，供 contentFrame 重算取景框
    var g = { x: sec.x, y: sec.y,
              w: Math.min(sec.count, sec.perRow) * slotW - sec.ogap + 1,
              h: ID_LABEL_H + COLHDR_H + rows * (sec.bh + sec.rgap) - sec.rgap,
              bubbles: [] };
    for (var q = 0; q < sec.count; q++) {
        var row = Math.floor(q / sec.perRow), col = q % sec.perRow, qno = String(sec.start + q);
        for (var o = 0; o < sec.opts; o++)
            g.bubbles.push({ t: 'q', s: sIdx, q: qno, o: LETTERS[o],
                x: sec.x + col * slotW + QW + o * (sec.bw + sec.ogap),
                y: sec.y + ID_LABEL_H + COLHDR_H + row * (sec.bh + sec.rgap),
                w: sec.bw, h: sec.bh });
    }
    return g;
}
// 文本墨迹宽度估算（与设计器 textW 同源：全角=1×size，半角=0.58×size）
function textW(t) {
    var w = 0;
    for (var i = 0; i < t.text.length; i++) w += (t.text.charCodeAt(i) > 255 ? 1 : 0.58) * t.size;
    return w;
}
// 旧模板无 texts 时按默认播种（与设计器 defaultTexts 同源，保证取景框重算结果一致）
function defaultTexts(paper) {
    return [{ text: '班级：____________　　姓名：____________　　座号/编号：____________', x: 0, y: 13, size: 3.8, w: paper.w, center: true }];
}
// 取景框按模板内容重算（与设计器 contentFrame 完全同源，多页模板按页计算：仅第 1 页含标题，
// 文本/题组按 pg 过滤，身份区每页都有）。设计器渲染/打印每次都按「当前内容」把定位标记中心画在
// 框角上——库里的旧 frame 可能过期，识别端必须按当前内容重算，不能信旧值
// 页码行文字：fmt 支持 {p}=页号、{n}=总页数；留空=「第 N 页」（多页加「/ 共 M 页」）（与设计器同源）
function pgnumText(L2, p) {
    var f = String((L2.pgnum && L2.pgnum.fmt) || '').trim();
    if (f) return f.split('{p}').join(p + 1).split('{n}').join(L2.pages);
    return '第 ' + (p + 1) + ' 页' + (L2.pages > 1 ? ' / 共 ' + L2.pages + ' 页' : '');
}
function contentFrame(L, pg) {
    if (pg === undefined) pg = 0;
    var W = L.paper.w, H = L.paper.h, pad = 8;   // pad = 标记半宽2.5 + 孤立性检查4 + 余量
    var x0 = W / 2, y0 = 5, x1 = W / 2, y1 = 11;
    if (pg === 0) {
        var tw = Math.min(W - 8, textW({ text: L.title || '答题卡', size: 4.6 }) * 1.12);   // 标题墨迹估算（居中）
        var tpx = L.titlePos ? (+L.titlePos.x || 0) : 0, tpy = L.titlePos ? (+L.titlePos.y || 5) : 5;
        x0 = Math.min(x0, tpx + (W - tw) / 2); x1 = Math.max(x1, tpx + (W + tw) / 2);
        y0 = Math.min(y0, tpy); y1 = Math.max(y1, tpy + 6);
    }
    if (L.pgnum && L.pgnum.on) {   // 页码行墨迹（可拖动，与设计器同源）
        var pnT = pgnumText(L, pg), pnS = +L.pgnum.size || 3.2;
        var pnW = Math.min(W - 8, textW({ text: pnT, size: pnS }) * 1.1);
        var pnX = +L.pgnum.x || 0, pnY = +L.pgnum.y || 12;
        x0 = Math.min(x0, pnX + Math.max(0, (W - pnW) / 2)); x1 = Math.max(x1, pnX + (W + pnW) / 2);
        y0 = Math.min(y0, pnY); y1 = Math.max(y1, pnY + pnS + 1);
    }
    (L.texts || []).forEach(function (t) {
        if ((t.pg || 0) !== pg) return;
        var ink = Math.min(W - 8, textW(t) * 1.1), w = t.w > 0 ? t.w : ink;
        var tx0 = (t.w > 0 && t.center) ? t.x + Math.max(0, (t.w - ink) / 2) : t.x;
        y0 = Math.min(y0, t.y); y1 = Math.max(y1, t.y + t.size + 1);
        x0 = Math.min(x0, tx0); x1 = Math.max(x1, Math.min(W, tx0 + w));
    });
    var idg = idGeom(L);
    x0 = Math.min(x0, idg.x); y0 = Math.min(y0, idg.y);
    x1 = Math.max(x1, idg.x + idg.w); y1 = Math.max(y1, idg.y + idg.h);
    (L.sections || []).forEach(function (sec, i) {
        if ((sec.pg || 0) !== pg) return;
        var g = secGeom(L, sec, i);
        x0 = Math.min(x0, g.x); y0 = Math.min(y0, g.y);
        x1 = Math.max(x1, g.x + g.w); y1 = Math.max(y1, g.y + g.h);
    });
    var tipY = Math.min(y1 + 3, H - 7);   // 底部提示行紧贴内容下方（动态），一并纳入取景框
    y1 = tipY + 4;
    var fx = Math.max(6, x0 - pad), fy = Math.max(6, y0 - pad);
    return { x: fx, y: fy,
             w: Math.max(30, Math.min(W - 6, x1 + pad) - fx),
             h: Math.max(30, Math.min(H - 6, y1 + pad) - fy),
             tipY: tipY };
}
function tplBubbles(L) {
    // 一律按当前几何现场重算（与设计器渲染/打印同源）；库里的旧 bubbles 是老版本几何存出的，
    // 可能与实印纸面错位，不再采用。返回全模板涂框（含各页；填空/简答题组无涂框自然为空）
    var arr = idGeom(L).bubbles;
    (L.sections || []).forEach(function (sec, i) { arr = arr.concat(secGeom(L, sec, i).bubbles); });
    return arr;
}
// ===== 多页：按页取涂框/取景框 + 当前识别页上下文 =====
// 每页涂框 = 身份区（每页重复、坐标相同）+ 该页题组涂框；CURPG 为当前识别页（0 起）
var CURPG = 0;
function pageBubbles(L, pg) {
    var arr = idGeom(L).bubbles;
    (L.sections || []).forEach(function (sec, i) {
        if ((sec.pg || 0) !== pg) return;
        arr = arr.concat(secGeom(L, sec, i).bubbles);
    });
    return arr;
}
// 切换当前识别页：后续 ringMatch/computeRatios/hmarkHit 等都读 TPL.frame / TPL._bubbles，
// 按页置换即可让整条管线在同一页几何上运行（单页模板行为不变）
function setActivePage(pg) {
    CURPG = Math.max(0, Math.min(TPL.pages - 1, pg));
    TPL.frame = TPL.frames[CURPG] || TPL.frames[0];
    TPL._bubbles = TPL._pageBubbles[CURPG] || TPL._pageBubbles[0];
    TPL._bubbleMap = {};
    TPL._bubbles.forEach(function (b) { if (b.t === 'q') TPL._bubbleMap['q_' + b.s + '_' + b.q + '_' + b.o] = b; });
}

// ===== 模板 =====
// 兼容/加固：确保模板字段齐全（老模板或手工数据缺字段时给默认值）
function normalizeTpl(raw) {
    var L = raw && typeof raw === 'object' ? raw : {};
    L.paper = (L.paper && +L.paper.w > 0 && +L.paper.h > 0) ? { w: +L.paper.w, h: +L.paper.h } : { w: 210, h: 297 };
    L.id = Object.assign({ x: 14, y: 24, digits: 2, idmode: 'seat', orient: 'h', bw: 4, bh: 4, vgap: 1.4, hgap: 1.8 }, L.id || {});
    L.id.digits = Math.min(10, Math.max(1, Math.round(+L.id.digits || 2)));
    L.id.idmode = (L.id.idmode === 'no') ? 'no' : 'seat';
    L.id.orient = (L.id.orient === 'v') ? 'v' : 'h';
    ['x', 'y', 'bw', 'bh', 'vgap', 'hgap'].forEach(function (k) { if (!isFinite(+L.id[k])) L.id[k] = 4; });
    L.sections = Array.isArray(L.sections) ? L.sections : [];
    L.pages = Math.min(MAXP, Math.max(1, Math.round(+raw.pages || 1)));
    L.sections.forEach(function (s) {
        s.kind = (s.kind === 'multi') ? 'multi' : (s.kind === 'judge') ? 'judge'
               : (s.kind === 'blank') ? 'blank' : (s.kind === 'short') ? 'short' : 'single';
        s.start = Math.max(1, Math.round(+s.start || 1));
        s.count = Math.max(1, Math.round(+s.count || 1));
        s.perRow = Math.max(1, Math.round(+s.perRow || 5));
        s.opts = Math.min(5, Math.max(2, Math.round(+s.opts || 4)));
        if (s.kind === 'judge') s.opts = 2;   // 判断题固定 对/错（内部 A/B）
        s.bw = +s.bw || 4.2; s.bh = +s.bh || 4.2; s.ogap = +s.ogap || 2; s.rgap = +s.rgap || 2.6;
        s.x = +s.x || 14; s.y = +s.y || 24;
        s.key = (s.key && typeof s.key === 'object') ? s.key : {};
        if (s.kind === 'blank') {   // 填空：一行几列 + 横线长度（手写题无涂框不判分）
            s.count = Math.min(100, s.count);
            s.perRow = Math.min(10, s.perRow);
            s.lineLen = Math.min(120, Math.max(10, +s.lineLen || 30));
        } else if (s.kind === 'short') {   // 简答：一次一题，题目占一整行 + 虚线作答框
            s.count = 1;
            s.qtext = String(s.qtext || '').slice(0, 120);
            s.boxW = Math.max(0, Math.min(L.paper.w, +s.boxW || 0));
            s.boxH = Math.min(150, Math.max(8, +s.boxH || 22));
        }
        s.pg = Math.min(L.pages - 1, Math.max(0, Math.round(+s.pg || 0)));
    });
    // 自定义文本：旧模板无 texts 时按默认播种（与设计器 normalizeLayout 同源，保证取景框重算一致）
    L.texts = [];
    if (Array.isArray(raw.texts)) {
        raw.texts.forEach(function (t) {
            if (L.texts.length >= 10 || !t || typeof t !== 'object') return;
            var n = { text: String(t.text || '').slice(0, 120), x: 0, y: 13, size: 3.2, w: 0, center: false, pg: 0 };
            if (!n.text) return;
            if (isFinite(+t.x)) n.x = Math.max(0, Math.min(L.paper.w, +t.x));
            if (isFinite(+t.y)) n.y = Math.max(0, Math.min(L.paper.h - 4, +t.y));
            if (isFinite(+t.size)) n.size = Math.min(8, Math.max(2, +t.size));
            if (isFinite(+t.w)) n.w = Math.max(0, Math.min(L.paper.w, +t.w));
            n.center = !!t.center;
            n.pg = Math.min(L.pages - 1, Math.max(0, Math.round(+t.pg || 0)));
            L.texts.push(n);
        });
    }
    if (!L.texts.length) L.texts = defaultTexts(L.paper);
    // 页头定位点：现为强制项（设计器已取消开关、保存恒带）。语义：layout 显式含 hmarks 字段 → 生效
    // （数量随页数递增：第 1 页 2 枚、第 2 页 3 枚…，识别端据此定方向与页码，并作为「真答题卡」验收依据）；
    // 旧模板（字段缺失，强制化之前印制）→ 视为无页头点，保持已印卡可识别（验收退化为仅印刷框对齐度）
    L.hmarks = raw.hmarks !== undefined ? !!raw.hmarks : false;
    // 页码点排布：hmarkCluster=true 新版式聚簇左上（点间隙=1 点径，手性布局可判镜像采集）；
    // 缺省 false = 旧版式 22%~78% 对称分布（已印卡兼容，与设计器 normalizeLayout 同源透传）
    L.hmarkCluster = !!raw.hmarkCluster;
    // 边缘中点码点（设计器 marksGeom 同源：左/右/下边正中各 1 枚 5mm 黑方块）：实测位置供预览
    // 分段拉直（Coons patch）与角标缺失冗余。缺省 false = 无中点（旧模板/已印卡，预览走原单应路径）
    L.edgeMidMarks = !!raw.edgeMidMarks;
    // 标题位置（可拖动）与页码行（每页重复；旧模板缺省关闭——与设计器 normalizeLayout 同源，保证取景框重算一致）
    L.titlePos = { x: 0, y: 5 };
    if (raw.titlePos && typeof raw.titlePos === 'object') {
        if (isFinite(+raw.titlePos.x)) L.titlePos.x = Math.max(0, Math.min(L.paper.w - 20, +raw.titlePos.x));
        if (isFinite(+raw.titlePos.y)) L.titlePos.y = Math.max(2, Math.min(L.paper.h - 12, +raw.titlePos.y));
    }
    L.pgnum = { on: false, x: 0, y: 12, size: 3.2, fmt: '' };
    if (raw.pgnum && typeof raw.pgnum === 'object') {
        L.pgnum.on = !!raw.pgnum.on;
        if (isFinite(+raw.pgnum.x)) L.pgnum.x = Math.max(0, Math.min(L.paper.w - 20, +raw.pgnum.x));
        if (isFinite(+raw.pgnum.y)) L.pgnum.y = Math.max(2, Math.min(L.paper.h - 8, +raw.pgnum.y));
        if (isFinite(+raw.pgnum.size)) L.pgnum.size = Math.min(8, Math.max(2, +raw.pgnum.size));
        L.pgnum.fmt = String(raw.pgnum.fmt || '').slice(0, 60);
    }
    // 每页取景框：按模板内容逐页重算（与设计器 contentFrame 同源）；异常时回退旧值/纸角兜底
    L.frames = [];
    for (var fp = 0; fp < L.pages; fp++) L.frames.push(contentFrame(L, fp));
    L.frame = L.frames[0];
    if (!(L.frame.w >= 30 && L.frame.h >= 30)) {
        var of = raw.frame;
        L.frame = (of && [of.x, of.y, of.w, of.h].every(function (v) { return isFinite(+v); }) && +of.w >= 30)
            ? { x: +of.x, y: +of.y, w: +of.w, h: +of.h }
            : { x: 10.5, y: 10.5, w: L.paper.w - 21, h: L.paper.h - 21 };
        L.frames[0] = L.frame;
    }
    // 每页涂框（身份区每页重复 + 该页题组）；初始活动页=第 1 页
    L._pageBubbles = [];
    for (var bp = 0; bp < L.pages; bp++) L._pageBubbles.push(pageBubbles(L, bp));
    L._bubbles = L._pageBubbles[0];
    L._bubbleMap = {};
    L._bubbles.forEach(function (b) { if (b.t === 'q') L._bubbleMap['q_' + b.s + '_' + b.q + '_' + b.o] = b; });
    return L;
}
function setTplBound(name, warn) {
    var el = document.getElementById('tplBound');
    if (el) {
        el.textContent = name || '未绑定';
        el.style.color = name ? '#667eea' : '#e74c3c';
    }
}
// 识别页固定使用「绑定模板」（设计器里选定/保存模板即绑定），不再下拉选择，避免扫错模板
function loadTplList() {
    apiPost({ type: 'omr_tpl_bind_get', project_id: PROJECT_ID }, function (b) {
        var tid = (b.success && b.template_id > 0) ? parseInt(b.template_id, 10) : 0;
        if (tid <= 0) {
            setTplBound('', true);
            toast('该项目尚未绑定模板：请先到「设计模板」选择或保存模板（选定即绑定）', 6000);
            return;
        }
        apiPost({ type: 'omr_template_get', project_id: PROJECT_ID, id: tid }, function (d) {
            if (!d.success) {
                setTplBound('', true);
                toast('绑定模板加载失败（' + d.message + '）。请到「设计模板」重新选择模板。');
                return;
            }
            TID = d.id; TPL = normalizeTpl(d.layout);
            // 涂框/取景框已在 normalizeTpl 内按页重算（身份区每页重复；活动页初始=第 1 页）——
            // 库里的旧 bubbles 可能是老版本几何存出的过期坐标，一律不采用
            TPL._bubbleMap = {};
            TPL._bubbles.forEach(function (b) { if (b.t === 'q') TPL._bubbleMap['q_' + b.s + '_' + b.q + '_' + b.o] = b; });
            if (!TPL.sections.length) {
                setTplBound(d.name, true);
                toast('绑定的模板没有任何题组，请先到设计器补充');
                return;
            }
            setTplBound(d.name);
            CUR = null; showResult(null);
            ansLoad();   // 答案覆盖检查：独立答案优先、模板答案键兜底，缺失时提示仅登记
            toast('已使用绑定模板：' + d.name + '（' + (TPL.sections || []).length + ' 个题组，' + (TPL.pages > 1 ? TPL.pages + ' 页，' : '') + TPL._bubbles.length + ' 个涂框）');
        });
    });
}

// ===== 答案覆盖检查（生效键=模板答案键最优先，独立录入答案仅补模板未设的题；不完整→仅登记或按强制批改口径，补全/切换后服务端自动重算已登记结果） =====
var ANSCTX = { source: 'template', key: {}, tk: {}, total: 0, answered: 0, missing: [], complete: false, force: false };
var AEM = { answers: {} };   // 录入答案弹层编辑副本
function ansBuild(pa) {
    var key = {}, tk = {}, total = 0;
    (TPL.sections || []).forEach(function (sec) {
        if (sec.kind === 'blank' || sec.kind === 'short') return;   // 填空/简答为手写题：无答案键、不参与批改覆盖统计
        for (var q = 0; q < sec.count; q++) {
            var qno = String(sec.start + q);
            total++;
            var v = String((sec.key || {})[qno] || '').toUpperCase().replace(/[^A-E]/g, '');
            if (v) { tk[qno] = v; key[qno] = v; }   // 模板答案最优先
        }
    });
    var src = 'template', filled = 0;
    if (pa && Object.keys(pa).length) {   // 与服务端口径一致：独立答案仅补模板未设答案的题（模板已设的题不生效）
        Object.keys(pa).forEach(function (q) {
            var qk = String(parseInt(q, 10) || 0), vv = String(pa[q]).toUpperCase().replace(/[^A-E]/g, '');
            if (qk === '0' || !vv || tk[qk]) return;
            key[qk] = vv; filled++;
        });
        if (filled) src = Object.keys(tk).length ? 'mixed' : 'project';
    }
    ANSCTX.source = src; ANSCTX.key = key; ANSCTX.tk = tk; ANSCTX.total = total; ANSCTX.answered = 0; ANSCTX.missing = [];
    (TPL.sections || []).forEach(function (sec) {
        if (sec.kind === 'blank' || sec.kind === 'short') return;
        for (var q = 0; q < sec.count; q++) {
            var qno = String(sec.start + q);
            if (key[qno]) ANSCTX.answered++; else ANSCTX.missing.push(qno);
        }
    });
    ANSCTX.complete = total > 0 && ANSCTX.answered === total;
}
function ansLoad() {
    apiPost({ type: 'omr_answer_get', project_id: PROJECT_ID }, function (d) {
        ANSCTX.force = !!(d.success && d.force);
        ansBuild((d.success && d.source === 'project') ? (d.answers || {}) : null);
        ansCheckRender();
    });
}
function ansCheckRender() {
    var panel = document.getElementById('ansPanel');
    if (!TPL || !TPL.sections || !TPL.sections.length) { panel.style.display = 'none'; return; }
    panel.style.display = '';
    var srcTxt = ANSCTX.source === 'project' ? '独立录入答案' : (ANSCTX.source === 'mixed' ? '模板答案+独立补录' : '模板答案键');
    var sumHtml = ANSCTX.complete
        ? '<span class="ok-badge">✅ 答案已完整（' + ANSCTX.answered + '/' + ANSCTX.total + '，' + srcTxt + '）· 识别自动批改</span>'
        : '<span class="flag-badge">❌ 缺 ' + (ANSCTX.total - ANSCTX.answered) + ' / ' + ANSCTX.total + ' 题答案（' + srcTxt + '）· '
          + (ANSCTX.force ? '强制批改中：按已有 ' + ANSCTX.answered + ' 题批改，缺答案题只记录选择' : '仅登记不批改，补全或开启强制批改后已识别结果自动重算') + '</span>';
    sumHtml += '<div style="margin-top:6px;"><label style="font-size:12px;cursor:pointer;user-select:none;">'
        + '<input type="checkbox" id="forceChk"' + (ANSCTX.force ? ' checked' : '') + ' onchange="omrForceSet(this.checked)" autocomplete="off"> '
        + '⚡ 强制批改（答案不完整时按已有答案的题批改，其余题只记录选择）</label></div>';
    document.getElementById('ansSummary').innerHTML = sumHtml;
    var html = '';
    (TPL.sections || []).forEach(function (sec, si) {
        if (sec.kind === 'blank' || sec.kind === 'short') {   // 手写题：无答案键，仅提示不判分
            html += '<div class="res-title">' + esc(sec.title || '题组') + '</div><div><span style="font-size:12px;color:#999;">手写题（填空/简答）不自动判分</span></div>';
            return;
        }
        html += '<div class="res-title">' + esc(sec.title || '题组') + (sec.kind === 'multi' ? '（多选）' : '') + '</div><div>';
        for (var q = 0; q < sec.count; q++) {
            var qno = String(sec.start + q);
            var has = !!ANSCTX.key[qno];
            var tip = has ? (ANSCTX.tk[qno] ? '📖 模板答案 ' + esc(ANSCTX.key[qno]) + '（模板最优先，不可改）'
                                            : '答案 ' + esc(ANSCTX.key[qno]) + '（独立补录，可修改）')
                          : '缺答案，点击补录';
            html += '<span class="ans-chip ' + (has ? 'has' : 'miss') + '" title="' + tip
                + '" onclick="ansOpenModal(' + qno + ')">' + qno + (has ? ' ✅' : ' ❌') + '</span>';
        }
        html += '</div>';
    });
    document.getElementById('ansGrid').innerHTML = html;
}
function ansOpenModal(focusQ) {
    if (!TPL || !TPL.sections || !TPL.sections.length) { toast('请先载入答题卡模板'); return; }
    AEM.answers = {};
    Object.keys(ANSCTX.key).forEach(function (q) { AEM.answers[q] = ANSCTX.key[q]; });
    ansModalRender();
    document.getElementById('ansModal').style.display = 'flex';
    if (focusQ) {
        var el = document.getElementById('amq_' + focusQ);
        if (el) el.scrollIntoView({ block: 'center' });
    }
}
function ansModalClose() { document.getElementById('ansModal').style.display = 'none'; }
function ansModalRender() {
    var html = '';
    (TPL.sections || []).forEach(function (sec, si) {
        if (sec.kind === 'blank' || sec.kind === 'short') return;   // 手写题无答案键，录入界面跳过
        html += '<div class="ans-sec">' + esc(sec.title || '题组') + (sec.kind === 'multi' ? '（多选）' : '') + '</div>';
        for (var q = 0; q < sec.count; q++) {
            var qno = String(sec.start + q);
            var cur = AEM.answers[qno] || '';
            var locked = !!ANSCTX.tk[qno];   // 模板已设答案：锁定展示（模板最优先，独立录入不可覆盖）
            var btns = '';
            for (var o = 0; o < sec.opts; o++) {
                var lt = LETTERS[o];
                btns += '<button type="button" class="key-btn' + (cur.indexOf(lt) >= 0 ? ' on' : '') + '" data-lt="' + lt + '"'
                    + (locked ? ' disabled style="opacity:.55;cursor:not-allowed;"' : '')
                    + ' onclick="ansModalPick(' + si + ',\'' + qno + '\',\'' + lt + '\')">' + (sec.kind === 'judge' ? (lt === 'A' ? '对' : '错') : lt) + '</button>';
            }
            var tag = locked ? ' <span style="color:#667eea;font-size:11px;">📖 模板答案</span>'
                     : (ANSCTX.key[qno] ? ' <span style="color:#16a34a;font-size:11px;">独立补录</span>'
                                        : ' <span style="color:#c0392b;font-size:11px;">❌原缺</span>');
            html += '<div class="key-row" id="amq_' + qno + '"><span class="kq">第' + qno + '题</span>' + btns + tag + '</div>';
        }
    });
    document.getElementById('amBody').innerHTML = html;
    document.getElementById('amClearBtn').style.display = (ANSCTX.source !== 'template') ? '' : 'none';
}
function ansModalPick(si, qno, lt) {
    if (ANSCTX.tk && ANSCTX.tk[qno]) return;   // 模板已设答案的题锁定：模板最优先，独立录入不可覆盖
    var sec = TPL.sections[si]; if (!sec) return;
    var cur = AEM.answers[qno] || '';
    if (sec.kind === 'multi') {
        var arr = cur.split(''), p = arr.indexOf(lt);
        if (p >= 0) arr.splice(p, 1); else { arr.push(lt); arr.sort(); }
        if (arr.length) AEM.answers[qno] = arr.join(''); else delete AEM.answers[qno];
    } else {
        if (cur === lt) delete AEM.answers[qno]; else AEM.answers[qno] = lt;
    }
    var row = document.getElementById('amq_' + qno);   // 仅更新本行按钮态，避免整表重绘丢滚动位置
    if (row) {
        var btns = row.querySelectorAll('.key-btn');
        for (var i = 0; i < btns.length; i++)
            btns[i].className = 'key-btn' + ((AEM.answers[qno] || '').indexOf(btns[i].dataset.lt) >= 0 ? ' on' : '');
    }
}
function ansModalSave() {
    var btn = document.getElementById('amSaveBtn');
    btn.disabled = true;
    apiPost({ type: 'omr_answer_save', project_id: PROJECT_ID, answers: JSON.stringify(AEM.answers) }, function (d) {
        btn.disabled = false;
        if (!d.success) { toast(d.message); return; }
        ansModalClose();
        ansLoad(); loadRecords();   // 刷新覆盖状态与识别记录得分（服务端已实时重算）
        toast(d.message, 4000);
    });
}
function ansModalClear() {
    if (!confirm('清除独立补录的答案后仅使用模板自带答案键（模板缺的题恢复为缺答案，不完整时按强制批改口径或仅登记），确定？')) return;
    apiPost({ type: 'omr_answer_clear', project_id: PROJECT_ID }, function (d) {
        if (!d.success) { toast(d.message); return; }
        ansModalClose();
        ansLoad(); loadRecords();
        toast(d.message, 4000);
    });
}
function omrForceSet(on) {
    apiPost({ type: 'omr_force_set', project_id: PROJECT_ID, on: on ? 1 : 0 }, function (d) {
        if (!d.success) { toast(d.message); ansLoad(); return; }
        ANSCTX.force = on;
        ansCheckRender(); loadRecords();   // 服务端已按新口径重算已识别结果
        toast(d.message, 4000);
    });
}

// ===== 摄像头 =====
var camDevices = [], camCurId = null, camBusy = false, userStoppedCam = false;
// 设备清单（权限授予后 label 可见）：≥2 个摄像头时显示下拉框（选中立即切换）+ 轮切按钮
function refreshCamList(showUi) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) return Promise.resolve([]);
    return navigator.mediaDevices.enumerateDevices().then(function (ds) {
        // 屏蔽 iOS 多摄合成虚拟设备（"后置三镜头/双广角镜头/双镜头"等）：系统会在多个物理镜头间自动切换，
        // 切换瞬间焦段跳变、对焦/曝光重调，答题卡识别易突然模糊跑焦；只保留单物理镜头（主摄/超广角/长焦/前置）
        camDevices = ds.filter(function (d) { return d.kind === 'videoinput' && !/三镜头|双镜头|双广角|三摄|双摄/.test(d.label || ''); });
        var sel = document.getElementById('camSelect'), btn = document.getElementById('camCycleBtn');
        if (showUi && camDevices.length > 1 && sel && btn) {
            var html = '';
            for (var i = 0; i < camDevices.length; i++) {
                var d = camDevices[i];
                html += '<option value="' + d.deviceId + '">' + (d.label || ('摄像头 ' + (i + 1))) + '</option>';
            }
            sel.innerHTML = html;
            if (camCurId) sel.value = camCurId;
            sel.style.display = ''; btn.style.display = '';
        }
        return camDevices;
    }).catch(function () { return []; });
}
// 取流后按设备能力升到最高支持分辨率（每次启动/切换都会执行；getCapabilities 不可用时退回 4K ideal）
function boostCamResolution() {
    try {
        var v = document.getElementById('camVideo');
        var track = (v && v.srcObject) ? v.srcObject.getVideoTracks()[0] : null;
        if (!track || !track.applyConstraints) return;
        var caps = track.getCapabilities ? track.getCapabilities() : null;
        var w = (caps && caps.width && caps.width.max) || 4096;
        var h = (caps && caps.height && caps.height.max) || 2160;
        track.applyConstraints({ width: { ideal: w }, height: { ideal: h } }).catch(function () {});
    } catch (e) {}
}
function startCam(deviceId) {
    if (camBusy) return;
    userStoppedCam = false;
    if (window.isSecureContext === false) { toast('摄像头仅限 HTTPS 或 localhost 访问，当前地址被浏览器禁用，请改用 https:// 或 http://localhost'); return; }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { toast('当前浏览器不支持摄像头（需 HTTPS 或 localhost）'); return; }
    // ideal 拉到 4K：浏览器自动选最接近档位，启动成功后 boostCamResolution 按设备能力精确升顶——切换任一摄像头均以该设备最高分辨率采集
    var vc = deviceId ? { deviceId: { exact: deviceId }, width: { ideal: 4096 }, height: { ideal: 2160 } }
                      : { facingMode: 'environment', width: { ideal: 4096 }, height: { ideal: 2160 } };
    camBusy = true;
    navigator.mediaDevices.getUserMedia({ video: vc, audio: false })
        .then(function (s) {
            if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
            stream = s;
            var v = document.getElementById('camVideo');
            v.srcObject = s;
            var track = s.getVideoTracks()[0];
            var st = track && track.getSettings ? track.getSettings() : null;
            camCurId = (st && st.deviceId) || deviceId || camCurId;
            boostCamResolution();
            refreshCamList(true);
            camBusy = false;
            document.getElementById('snapBtn').style.display = '';
            document.getElementById('camStopBtn').style.display = '';
            document.getElementById('camStartBtn').style.display = 'none';
            document.getElementById('autoBtn').style.display = '';
            renderAutoBtn();
            if (AUTO && !autoTimer) autoTick();
        })
        .catch(function (e) {
            camBusy = false;
            refreshCamList(true);
            if (e && (e.name === 'NotFoundError' || e.name === 'DevicesNotFoundError')) { toast('未检测到摄像设备，请确认后重试', 5000); return; }
            toast('摄像头打开失败：' + e.message + '（请检查权限，或改用「上传照片识别」）');
        });
}
// 切换摄像头：停当前流 → 以指定设备重启（选择最高分辨率）
function switchCam(id) {
    if (!id || id === camCurId || camBusy) return;
    if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
    startCam(id);
}
// 多摄像头轮切：当前设备的下一个
function cycleCam() {
    if (camDevices.length < 2) return;
    var idx = 0;
    for (var i = 0; i < camDevices.length; i++) if (camDevices[i].deviceId === camCurId) { idx = i; break; }
    var next = camDevices[(idx + 1) % camDevices.length];
    var sel = document.getElementById('camSelect');
    if (sel) sel.value = next.deviceId;
    switchCam(next.deviceId);
}
function stopCam() {
    userStoppedCam = true;   // 用户主动关闭：插入设备不再自动重开（点「开始扫码 图片识别」可重开）
    if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
    var v = document.getElementById('camVideo');
    v.srcObject = null;
    document.getElementById('snapBtn').style.display = 'none';
    document.getElementById('camStopBtn').style.display = 'none';
    document.getElementById('autoBtn').style.display = 'none';
    document.getElementById('camSelect').style.display = 'none';
    document.getElementById('camCycleBtn').style.display = 'none';
    document.getElementById('camStartBtn').style.display = '';
    if (autoTimer) { clearTimeout(autoTimer); autoTimer = null; }
    autoStable = 0; autoBusy = false; autoHold = false;
}
window.addEventListener('pagehide', stopCam);
function snapCam() {
    var v = document.getElementById('camVideo');
    if (!v.videoWidth) { toast('摄像头画面未就绪'); return; }
    var c = document.createElement('canvas');
    c.width = v.videoWidth; c.height = v.videoHeight;
    c.getContext('2d').drawImage(v, 0, 0);
    toast('识别中…', 8000);
    setTimeout(function () { recognize(c); }, 30);
}
function fileRecognize(input) {
    var files = Array.prototype.slice.call(input.files || []);
    input.value = '';
    if (!files.length) return;
    var seq = function (i) {
        if (i >= files.length) return;
        var img = new Image();
        img.onload = function () {
            var c = document.createElement('canvas');
            c.width = img.naturalWidth; c.height = img.naturalHeight;
            c.getContext('2d').drawImage(img, 0, 0);
            recognize(c);
            setTimeout(function () { seq(i + 1); }, 700);
        };
        img.onerror = function () { toast('第 ' + (i + 1) + ' 张图片读取失败'); seq(i + 1); };
        img.src = URL.createObjectURL(files[i]);
    };
    toast('识别中…', 8000);
    seq(0);
}

// ===== 图像处理核心 =====
// 自适应均值二值化（积分图），返回 Uint8Array：1=暗(笔迹/印刷) 0=亮(纸面)
function binarize(gray, w, h) {
    var win = Math.max(15, Math.round(w / 45)) | 1, C = 10;
    var ii = new Float64Array((w + 1) * (h + 1));
    for (var y = 0; y < h; y++) {
        var rs = 0;
        for (var x = 0; x < w; x++) {
            rs += gray[y * w + x];
            ii[(y + 1) * (w + 1) + (x + 1)] = ii[y * (w + 1) + (x + 1)] + rs;
        }
    }
    var bin = new Uint8Array(w * h), r = win >> 1;
    for (var y2 = 0; y2 < h; y2++) {
        var y0 = Math.max(0, y2 - r), y1 = Math.min(h - 1, y2 + r);
        for (var x2 = 0; x2 < w; x2++) {
            var x0 = Math.max(0, x2 - r), x1 = Math.min(w - 1, x2 + r);
            var cnt = (x1 - x0 + 1) * (y1 - y0 + 1);
            var sum = ii[(y1 + 1) * (w + 1) + (x1 + 1)] - ii[y0 * (w + 1) + (x1 + 1)]
                    - ii[(y1 + 1) * (w + 1) + x0] + ii[y0 * (w + 1) + x0];
            bin[y2 * w + x2] = (gray[y2 * w + x2] < sum / cnt - C) ? 1 : 0;
        }
    }
    return bin;
}
// 连通域（4邻接，迭代泛洪），返回面积/外接框
function findComponents(bin, w, h) {
    var visited = new Uint8Array(w * h), stack = new Int32Array(w * h), comps = [];
    for (var start = 0; start < w * h; start++) {
        if (!bin[start] || visited[start]) continue;
        var sp = 0; stack[sp++] = start; visited[start] = 1;
        var area = 0, minx = w, miny = h, maxx = 0, maxy = 0;
        while (sp > 0) {
            var p = stack[--sp], px = p % w, py = (p - px) / w;
            area++;
            if (px < minx) minx = px; if (px > maxx) maxx = px;
            if (py < miny) miny = py; if (py > maxy) maxy = py;
            if (px > 0 && bin[p - 1] && !visited[p - 1]) { visited[p - 1] = 1; stack[sp++] = p - 1; }
            if (px < w - 1 && bin[p + 1] && !visited[p + 1]) { visited[p + 1] = 1; stack[sp++] = p + 1; }
            if (py > 0 && bin[p - w] && !visited[p - w]) { visited[p - w] = 1; stack[sp++] = p - w; }
            if (py < h - 1 && bin[p + w] && !visited[p + w]) { visited[p + w] = 1; stack[sp++] = p + w; }
        }
        comps.push({ area: area, x0: minx, y0: miny, x1: maxx, y1: maxy });
    }
    return comps;
}
// 定位四角黑方块候选：实心、方形、孤立（周边无其他较大连通域）；按象限返回候选数组（距图角近者在前）
function findMarks(comps, w, h) {
    var refMin = w * 0.006, refMax = w * 0.075;
    // 墨色深浅曾用于剔除铅笔涂块（dq>130 / 90<dq<220 两版），实测均不可行：
    // 阴影下印刷标记 dq 会升到 90~130（杀掉后 904_8 从 9/10 崩到 0/10），过曝图印刷标记整块
    // gray≥150（dq=255，杀掉后 175736 标记全空回归 1/10），与铅笔深涂带完全重叠，绝对阈值无法区分。
    // 改为不做墨色过滤，铅笔伪四边形由 recognize 的「包含度」评分压制（真四边形包住纸面全部候选）
    var cands = comps.filter(function (c) {
        var bw = c.x1 - c.x0 + 1, bh = c.y1 - c.y0 + 1;
        if (bw < refMin || bh < refMin || bw > refMax || bh > refMax) return false;
        var ar = bw / bh;
        if (ar < 0.3 || ar > 3.3) return false;   // 宽放：90°横放+透视会让方块的局部宽高比达到 0.4~2.5
        if (c.area / (bw * bh) < 0.55) return false;   // 实心方块（阴影/光线不均可致内部小空洞，放宽防漏检）
        return true;
    });
    // 孤立性：扩展框内无其他较大连通域。按轴各取 0.8×该轴边长（90°横放/透视会各向异性压缩某一轴，
    // 用 max 边长会在压缩轴上误伤；设计器取景框 pad=8mm 保证涂框距标记 ≥5.5mm 净空 > 0.8×5mm=4mm 检查距）；
    // 忽略远大于候选的巨型连通域（如深色桌面背景）
    cands = cands.filter(function (c) {
        var bw = c.x1 - c.x0 + 1, bh = c.y1 - c.y0 + 1;
        var ex = { x0: c.x0 - bw * 0.8, y0: c.y0 - bh * 0.8, x1: c.x1 + bw * 0.8, y1: c.y1 + bh * 0.8 };
        var near = comps.filter(function (o) {
            if (o === c || o.area < 30 || o.area > c.area * 20) return false;
            var ow = o.x1 - o.x0 + 1, oh = o.y1 - o.y0 + 1;
            if (ow > oh * 6 || oh > ow * 6) return false;   // 细长暗线（纸边/阴影边）不算近邻干扰——阴影下纸边线常贴着角落标记，会把标记误杀
            if (o.area / (ow * oh) < 0.04) return false;   // 稀疏框架连通域（纸边框线连成的大框/桌面边界）不算近邻干扰——实拍中会贴角吞掉真标记（实测 526x1137 a8892 误杀真标记）
            var ix = Math.min(o.x1, ex.x1) - Math.max(o.x0, ex.x0);
            var iy = Math.min(o.y1, ex.y1) - Math.max(o.y0, ex.y0);
            if (ix <= 0 || iy <= 0) return false;
            // bbox 仅「扫过」扩展框的不算干扰：背景过渡域（纸边→桌面/门）的连通域 bbox 常横跨半幅画面、
            // 只是边角掠过标记邻域（实测 204338：144x166 稀疏域 bbox 掠过右下真标记扩展框仅 3.4% 面积，
            // 按 bbox 相交被误判为近邻干扰 → 真标记被杀 → 四角推算跑飞拒识）。改为 o.bbox∩扩展框 占
            // o.bbox 自身 ≥15% 才算真压近——真杂物块 bbox 大部落于扩展框内比例高，横扫边界域比例极低
            return ix * iy / (ow * oh) >= 0.15;
        });
        return near.length === 0;
    });
    var cornerPts = [[0, 0], [w - 1, 0], [w - 1, h - 1], [0, h - 1]], byQ = [[], [], [], []];
    cands.forEach(function (c) {
        var cx = (c.x0 + c.x1) / 2, cy = (c.y0 + c.y1) / 2;
        var right = cx >= w / 2, bottom = cy >= h / 2;
        var qi = bottom ? (right ? 2 : 3) : (right ? 1 : 0);   // 0=图左上 1=右上 2=右下 3=左下（与 ROT_MAP 同约定）
        var d = (cx - cornerPts[qi][0]) * (cx - cornerPts[qi][0]) + (cy - cornerPts[qi][1]) * (cy - cornerPts[qi][1]);
        byQ[qi].push({ c: c, d: d });
    });
    byQ.forEach(function (arr) { arr.sort(function (a, b) { return a.d - b.d; }); });
    return byQ;
}
// 单应矩阵：dst → src（用于矫正后逐像素逆向采样），高斯消元解 8 参数
// 点格式兼容 [x,y] 数组与 {x,y} 对象
function homography(dst, src) {
    var A = [], b = [];
    for (var i = 0; i < 4; i++) {
        var d = dst[i], s = src[i];
        var x = d.length ? d[0] : d.x, y = d.length ? d[1] : d.y;
        var u = s.length ? s[0] : s.x, v = s.length ? s[1] : s.y;
        A.push([x, y, 1, 0, 0, 0, -u * x, -u * y]); b.push(u);
        A.push([0, 0, 0, x, y, 1, -v * x, -v * y]); b.push(v);
    }
    for (var c = 0; c < 8; c++) {
        var piv = c;
        for (var r = c + 1; r < 8; r++) if (Math.abs(A[r][c]) > Math.abs(A[piv][c])) piv = r;
        if (Math.abs(A[piv][c]) < 1e-10) return null;
        var t = A[c]; A[c] = A[piv]; A[piv] = t;
        t = b[c]; b[c] = b[piv]; b[piv] = t;
        for (var r2 = c + 1; r2 < 8; r2++) {
            var f = A[r2][c] / A[c][c];
            for (var k = c; k < 8; k++) A[r2][k] -= f * A[c][k];
            b[r2] -= f * b[c];
        }
    }
    var hh = new Array(8);
    for (var r3 = 7; r3 >= 0; r3--) {
        var s2 = b[r3];
        for (var k2 = r3 + 1; k2 < 8; k2++) s2 -= A[r3][k2] * hh[k2];
        hh[r3] = s2 / A[r3][r3];
    }
    return hh;
}
var RW = 0, RH = 0, RK = 0;   // 矫正图尺寸与 px/mm
var GRAY = null;   // 当前工作图灰度（findMarks 墨色过滤用；由 recognize/marksFound 在二值化前设置）
// 采样每个涂框黑像素占比（12% 内缩避开印刷边框；idShift 仅平移座号位采样窗，题框不动）。
// 座号小框（4mm）印刷框线粗（0.35mm+矫正重采样扩散 ≈0.6mm），12% 内缩（0.48mm）挡不住，
// 空框占比被框线抬高到 0.24+ 与浅涂拉不开——座号框改在腐蚀图 rbinE（细线被蚀掉、涂块保留）
// 上采样，与不满涂块/涂偏压线的兼容性远好于加大内缩（后者会砍掉偏位涂块的主体信号，实测
// 205039 涂块偏左下，0.9mm 内缩后灰度分从 0.048 掉到 0.013 反而判不出）
function computeRatios(rbin, idShift, rbinE) {
    var ratios = {};
    TPL._bubbles.forEach(function (b) {
        var isId = b.t === 'id';
        var src = (isId && rbinE) ? rbinE : rbin;
        var sh = isId && idShift ? idShift[b.i + '_' + b.v] : null;
        var ox = sh ? sh[0] : 0, oy = sh ? sh[1] : 0;
        var x0 = Math.round((b.x + ox + b.w * 0.12) * RK), x1 = Math.round((b.x + ox + b.w * 0.88) * RK);
        var y0 = Math.round((b.y + oy + b.h * 0.12) * RK), y1 = Math.round((b.y + oy + b.h * 0.88) * RK);
        x0 = Math.max(0, x0); y0 = Math.max(0, y0); x1 = Math.min(RW - 1, x1); y1 = Math.min(RH - 1, y1);
        var dark = 0, tot = 0;
        for (var yy = y0; yy <= y1; yy++)
            for (var xx = x0; xx <= x1; xx++) { tot++; if (src[yy * RW + xx]) dark++; }
        ratios[isId ? 'id_' + b.i + '_' + b.v : 'q_' + b.s + '_' + b.q + '_' + b.o] = tot ? dark / tot : 0;
    });
    return ratios;
}
// 身份区局部对齐估计（整行网格拟合 v2）：整体矫正（涂框配准/纸边拟合）的对应点集中在题目区，
// 座号区位于页面外沿属外推区域，透视/纸张非线性残差可达 ±8mm 且呈渐进分布（实测 204414 座号行
// 左端偏 -7mm、行内框距被拉伸 10%；205039 反向压缩 +8→+5mm——旧整体平移模型无峰，逐框独立吸附
// 又会把空框窗口吸到涂块/邻框上产生假峰，实测曾致「重涂不清」）。改为按行拟合仿射网格：
// ① 主轴：行带暗像素投影对 (a,b) 两级穷举（a=列0位移 ±10mm，b=列距增量 ±1.2mm/列），采样点=
//    每框左右印刷竖线（峰在框边缘；涂块位于真列位上只增强峰不改峰位，空框全靠框线锚定）；
// ② 副轴：主轴锚定后逐列 ±3mm 搜框线上/下边覆盖峰，2-pass 最小二乘（残差>1mm 剔除）拟合成
//    dy(v)=dy0+c·v 直线——纸张副轴倾斜/弯曲与单列噪声均被直线模型滤除；
// ③ 验收：全框 24 点框线环覆盖均值 ≥35% 且拟合确有改善才采纳，否则该行不修正。
// 返回 { 'i_v': [dx,dy] }；orient='h'（数字横排）主副轴互换同理
function idShiftEstimate(rbin) {
    var ids = [], i;
    for (i = 0; i < TPL._bubbles.length; i++) if (TPL._bubbles[i].t === 'id') ids.push(TPL._bubbles[i]);
    if (ids.length < 6) return null;
    var groups = {};
    for (i = 0; i < ids.length; i++) (groups[ids[i].i] = groups[ids[i].i] || []).push(ids[i]);
    var out = null;
    var DBG = (typeof window !== 'undefined' && window.__OMR_DBG) ? (window.__OMR_DBG.idShiftFit = {}) : null;
    for (var gi in groups) {
        var g = groups[gi].sort(function (p, q) { return p.v - q.v; });
        var n = g.length;
        if (n < 4) continue;
        var alongX = TPL.id.orient !== 'h';
        var cMain = [], crossC = 0;
        for (i = 0; i < n; i++) {
            cMain.push(alongX ? g[i].x + g[i].w / 2 : g[i].y + g[i].h / 2);
            crossC += alongX ? g[i].y + g[i].h / 2 : g[i].x + g[i].w / 2;
        }
        crossC /= n;
        var halfMain = (alongX ? g[0].w : g[0].h) / 2;
        var halfCross = (alongX ? g[0].h : g[0].w) / 2;
        // ① 主轴投影 + (a,b) 穷举（粗 0.5mm/0.1mm → 细 0.125mm/0.02mm）
        var loM = cMain[0] - 12, hiM = cMain[n - 1] + 12;
        var px0 = Math.max(0, Math.round((alongX ? loM : crossC - halfCross - 2) * RK));
        var px1 = Math.min(RW - 1, Math.round((alongX ? hiM : crossC + halfCross + 2) * RK));
        var py0 = Math.max(0, Math.round((alongX ? crossC - halfCross - 2 : loM) * RK));
        var py1 = Math.min(RH - 1, Math.round((alongX ? crossC + halfCross + 2 : hiM) * RK));
        var npj = px1 - px0 + 1;
        if (npj < 20 || py1 < py0) continue;
        var proj = new Float64Array(npj);
        for (var yy = py0; yy <= py1; yy++) {
            var rowO = yy * RW;
            for (var xx = px0; xx <= px1; xx++) if (rbin[rowO + xx]) proj[xx - px0]++;
        }
        function gridScore(a, b) {
            // 采样点=每框左右两条印刷竖线（投影峰在框边缘而非中心，框中心只是横线基线谷）：
            // 真相位下全部样本命中竖线、偏移半格的相邻相位样本落进框间空隙——峰唯一且尖锐，
            // 根治中心采样的半格相位误锁（实测 204414 真值 -7mm 被锁到 -10.5、205039 +8 锁到
            // +3.4，读分靠墨块面积侥幸存活但画框整体错位、浅涂误判「未涂」）。
            // 单列贡献：±1px 窗取峰（抗亚像素相位抖动）+ 封顶≈框线电平——粗墨团（粘连涂块/
            // 污渍）在投影里是宽平台，封顶后墨团至多与一条框线等价，全列命中框线的真相位才得满分
            var s = 0, cap = (py1 - py0 + 1) * 0.55;
            for (var v = 0; v < n; v++) {
                var c0 = (cMain[v] + a + b * v) * RK - px0;
                for (var ed = -1; ed <= 1; ed += 2) {
                    var i0 = Math.round(c0 + ed * halfMain * RK), bpk = 0;
                    for (var wv = i0 - 1; wv <= i0 + 1; wv++) {
                        var idx = wv < 0 ? 0 : (wv >= npj ? npj - 1 : wv);
                        if (proj[idx] > bpk) bpk = proj[idx];
                    }
                    s += bpk > cap ? cap : bpk;
                }
            }
            return s;
        }
        var bestA = 0, bestB = 0, bestS = gridScore(0, 0), s0S = bestS;
        for (var a = -10; a <= 10.001; a += 0.5)
            for (var b = -1.2; b <= 1.201; b += 0.1) {
                var s = gridScore(a, b);
                if (s > bestS) { bestS = s; bestA = a; bestB = b; }
            }
        for (var a2 = Math.max(-10.5, bestA - 0.5); a2 <= bestA + 0.501; a2 += 0.125)
            for (var b2 = Math.max(-1.25, bestB - 0.1); b2 <= bestB + 0.101; b2 += 0.02) {
                var s2 = gridScore(a2, b2);
                if (s2 > bestS) { bestS = s2; bestA = a2; bestB = b2; }
            }
        // ② 副轴逐列位移（框线上/下边 12 点覆盖峰）→ 稳健直线 dy0+c·v
        var dys = [];
        for (var v3 = 0; v3 < n; v3++) {
            var cm3 = cMain[v3] + bestA + bestB * v3, bDy = 0, bCov = 0;
            for (var dy = -3; dy <= 3.001; dy += 0.25) {
                var cov3 = 0;
                for (var j3 = 0; j3 < 6; j3++) {
                    var xo3 = -0.7 * halfMain + 1.4 * halfMain * (j3 + 0.5) / 6;
                    var pxA = Math.round((cm3 + xo3) * RK);
                    for (var e3 = -1; e3 <= 1; e3 += 2) {
                        var pyA = Math.round((crossC + dy + e3 * halfCross) * RK);
                        if (pxA >= 0 && pxA < RW && pyA >= 0 && pyA < RH && rbin[pyA * RW + pxA]) cov3++;
                    }
                }
                if (cov3 > bCov) { bCov = cov3; bDy = dy; }
            }
            if (bCov >= 7) dys.push([v3, bDy]);   // 12 点命中 ≥7 才可信
        }
        var dy0 = 0, cc = 0;
        if (dys.length >= 3) {
            for (var pass = 0; pass < 2; pass++) {
                var sx = 0, sy = 0, sxx = 0, sxy = 0, m = dys.length;
                for (var d4 = 0; d4 < m; d4++) {
                    sx += dys[d4][0]; sy += dys[d4][1]; sxx += dys[d4][0] * dys[d4][0]; sxy += dys[d4][0] * dys[d4][1];
                }
                var den = m * sxx - sx * sx;
                cc = den ? (m * sxy - sx * sy) / den : 0;
                dy0 = (sy - cc * sx) / m;
                if (!pass && m > 4) {
                    var keep = [];
                    for (var d5 = 0; d5 < m; d5++)
                        if (Math.abs(dys[d5][1] - (dy0 + cc * dys[d5][0])) <= 1) keep.push(dys[d5]);
                    if (keep.length >= 3) dys = keep;
                }
            }
        }
        if (Math.abs(dy0) > 4 || Math.abs(cc) > 0.4) { if (DBG) DBG[gi] = { rej: 'dy', dy0: dy0, cc: cc }; continue; }
        // ③ 验收：全框 24 点框线环覆盖均值 ≥42%，且显著高于未修正（模板位）基准——半格误锁
        //    的相位与模板位覆盖率相当（实测 204414 修正后 0.371 < 模板位 0.412），据此二次否决；
        //    淡印卡框线弱、拟合易漂移（190650 曾 -8→-17mm 伪拟合），绝对门槛一并拦下
        var covSum = 0, covTplSum = 0;
        for (var v4 = 0; v4 < n; v4++) {
            var cm4 = cMain[v4] + bestA + bestB * v4, cr4 = crossC + dy0 + cc * v4;
            for (var j4 = 0; j4 < 6; j4++) {
                var oM1 = -halfMain + 2 * halfMain * (j4 + 0.5) / 6, oC1 = -halfCross + 2 * halfCross * (j4 + 0.5) / 6;
                for (var e4 = 0; e4 < 4; e4++) {
                    var oM2 = e4 < 2 ? oM1 : (e4 === 2 ? halfMain : -halfMain);
                    var oC2 = e4 < 2 ? (e4 === 0 ? halfCross : -halfCross) : oC1;
                    var pxA = Math.round((alongX ? cm4 + oM2 : cr4 + oC2) * RK), pyA = Math.round((alongX ? cr4 + oC2 : cm4 + oM2) * RK);
                    if (pxA >= 0 && pxA < RW && pyA >= 0 && pyA < RH && rbin[pyA * RW + pxA]) covSum++;
                    var pxT = Math.round((alongX ? cMain[v4] + oM2 : crossC + oC2) * RK), pyT = Math.round((alongX ? crossC + oC2 : cMain[v4] + oM2) * RK);
                    if (pxT >= 0 && pxT < RW && pyT >= 0 && pyT < RH && rbin[pyT * RW + pxT]) covTplSum++;
                }
            }
        }
        var meanCov = covSum / (n * 24), tplCov = covTplSum / (n * 24);
        var improved = bestS > s0S * 1.15 + 4 || Math.abs(bestA) > 0.6 || Math.abs(bestB) > 0.08 || Math.abs(dy0) > 0.6 || Math.abs(cc) > 0.06;
        if (DBG) DBG[gi] = { a: Math.round(bestA * 100) / 100, b: Math.round(bestB * 100) / 100, dy0: Math.round(dy0 * 100) / 100, c: Math.round(cc * 100) / 100, cov: Math.round(meanCov * 1000) / 1000, tplCov: Math.round(tplCov * 1000) / 1000, nDy: dys.length };
        if (meanCov < 0.42 || meanCov < tplCov + 0.05 || !improved) continue;
        for (var v5 = 0; v5 < n; v5++) {
            var dM = bestA + bestB * v5, dC = dy0 + cc * v5;
            (out = out || {})[g[v5].i + '_' + g[v5].v] = alongX ? [dM, dC] : [dC, dM];
        }
    }
    return out;
}
// 24 点框线环覆盖（mm 坐标）：idShiftEstimate 验收同款采样模式（每边 6 点×4 边）
function ringCov24(rbin, cx, cy, hw, hh) {
    var cnt = 0, tot = 0;
    for (var j = 0; j < 6; j++) {
        var oM1 = -hw + 2 * hw * (j + 0.5) / 6, oC1 = -hh + 2 * hh * (j + 0.5) / 6;
        for (var e = 0; e < 4; e++) {
            var oM2 = e < 2 ? oM1 : (e === 2 ? hw : -hw);
            var oC2 = e < 2 ? (e === 0 ? hh : -hh) : oC1;
            var px = Math.round((cx + oM2) * RK), py = Math.round((cy + oC2) * RK);
            tot++;
            if (px >= 0 && px < RW && py >= 0 && py < RH && rbin[py * RW + px]) cnt++;
        }
    }
    return tot ? cnt / tot : 0;
}
// 逐框画框吸附（仅作用于预览描框，不影响读分）：整体矫正对页面各处的残余偏差（对应点集中在
// 题区的配准对页面外沿/纸张弯曲的补偿有限）表现为每框 0.5~2mm 的错位——实测 205039 题区
// 30/40 框 >0.5mm（其座号 0 位行拟合还被拒）、190650 达 36/40，预览画框肉眼可见偏移。
// 搜索中心必须是最终底座位（座号区含 idShift 行拟合平移）——曾按原始模板位搜索、却把结果
// 叠加在已平移的底座上，座号框被双重叠加 idShift 平移（实测 ID 残 mean 1.57mm 顶满搜索窗）。
// 对每个涂框在 ±2.5mm 网格（0.25mm 步进 + 最优点邻域 0.05mm 细化）上以 24 点框线环覆盖取
// argmax（框线是锚，涂块位于框内只增强覆盖不改峰位），三重护栏防误跳：
// · 已对齐框（c0≥0.75，含涂满框——墨团使环覆盖天然高）跳过，杜绝把框吸进墨团；
// · 距离偏置（每 mm -0.04）让等覆盖时优先贴近原位；
// · 采纳须（绝对覆盖 ≥0.55 且比原位高 ≥0.08）或（淡印卡放宽：≥0.45 且高 ≥0.15）——
//   框线弱、暗结构杂时证据不足宁可不动作旧。
// 返回 { key: [dx,dy] }（mm），无可用吸附返回 null
function bubbleSnapShifts(rbin, base) {
    if (!TPL || !base || !rbin) return null;
    var out = null;
    base.forEach(function (b) {
        var hw = b.w / 2, hh = b.h / 2, cx = b.x + hw, cy = b.y + hh;
        var c0 = ringCov24(rbin, cx, cy, hw, hh);
        if (c0 >= 0.75) return;
        var best = null;
        for (var dx = -2.5; dx <= 2.501; dx += 0.25) for (var dy = -2.5; dy <= 2.501; dy += 0.25) {
            var c = ringCov24(rbin, cx + dx, cy + dy, hw, hh);
            var g = c - 0.04 * Math.sqrt(dx * dx + dy * dy);
            if (!best || g > best.g) best = { g: g, c: c, dx: dx, dy: dy };
        }
        if (!best) return;
        for (var fx = best.dx - 0.25; fx <= best.dx + 0.251; fx += 0.05)
            for (var fy = best.dy - 0.25; fy <= best.dy + 0.251; fy += 0.05) {
                var c2 = ringCov24(rbin, cx + fx, cy + fy, hw, hh);
                var g2 = c2 - 0.04 * Math.sqrt(fx * fx + fy * fy);
                if (g2 > best.g) best = { g: g2, c: c2, dx: fx, dy: fy };
            }
        if ((best.c >= 0.55 && best.c >= c0 + 0.08) || (best.c >= 0.45 && best.c >= c0 + 0.15))
            (out = out || {})[b.t === 'id' ? 'id_' + b.i + '_' + b.v : 'q_' + b.s + '_' + b.q + '_' + b.o] = [best.dx, best.dy];
    });
    return out;
}
// 把吸附偏移应用到描框数组（生成副本，绝不修改模板对象本体）
function applyBubbleSnap(base, snapMap) {
    if (!snapMap) return base;
    return base.map(function (b) {
        var sh = snapMap[b.t === 'id' ? 'id_' + b.i + '_' + b.v : 'q_' + b.s + '_' + b.q + '_' + b.o];
        return sh ? Object.assign({}, b, { x: b.x + sh[0], y: b.y + sh[1] }) : b;
    });
}
// 方向评分：身份位与各题的最高填涂占比之和（方向正确且已填涂时显著更高）
function orientScore(ratios) {
    var s = 0;
    for (var di = 0; di < TPL.id.digits; di++) {
        var best = 0;
        for (var v = 0; v <= 9; v++) { var r = ratios['id_' + di + '_' + v] || 0; if (r > best) best = r; }
        s += best;
    }
    (TPL.sections || []).forEach(function (sec, si) {
        for (var q = 0; q < sec.count; q++) {
            var qno = String(sec.start + q), best2 = 0;
            for (var o = 0; o < sec.opts; o++) {
                var r2 = ratios['q_' + si + '_' + qno + '_' + LETTERS[o]] || 0;
                if (r2 > best2) best2 = r2;
            }
            s += best2;
        }
    });
    return s;
}
// 填涂集中度：前 K 高占比之和（K≈涂框数的 15%，覆盖全部已涂框）。
// 涂框网格对 180° 近似对称（印刷框对齐度区分不了 0↔180、90↔270 这对方向），
// 但正确朝向把墨迹集中映射到少数涂框（高占比），错误朝向把同样墨迹摊到大量框（低占比）——
// 这是标记漏检走兜底矫正时区分「成对方向」的关键内容信号；铅笔浅涂时差距缩小但方向不变
function orientConc(rts, nB) {
    var K = Math.max(8, Math.min(15, Math.round(nB * 0.15))), arr = [];
    for (var k in rts) if (rts[k] > 0.02) arr.push(rts[k]);
    arr.sort(function (a, b) { return b - a; });
    var s = 0;
    for (var i = 0; i < K && i < arr.length; i++) s += arr[i];
    return s;
}
// 侧栏文字不对称度（[-1,1]）：题组标题（一、单选题）、座号标签、数字值头等印刷文字都靠内容
// 左缘排布，右侧留白——方向正确时左带墨迹占比 > 右带，颠倒后反转。涂框网格 180° 近似对称，
// ring/集中度对「成对方向」（0↔180、90↔270）失去区分力，这是旧模板（无页头定位点）兜底矫正
// 时的内容方向信号；两带按各自面积归一，加平滑防除零
function sideInkAsym(rbin) {
    var bs = TPL._bubbles, F = TPL.frame;
    var minX = bs[0].x, maxX = bs[0].x + bs[0].w, minY = bs[0].y, maxY = bs[0].y + bs[0].h;
    for (var i = 1; i < bs.length; i++) {
        var b = bs[i];
        if (b.x < minX) minX = b.x;
        if (b.x + b.w > maxX) maxX = b.x + b.w;
        if (b.y < minY) minY = b.y;
        if (b.y + b.h > maxY) maxY = b.y + b.h;
    }
    var y0 = Math.max(0, Math.round((minY - 6) * RK)), y1 = Math.min(RH - 1, Math.round((maxY + 4) * RK));
    var lx0 = Math.max(0, Math.round((F.x + 0.5) * RK)), lx1 = Math.min(RW - 1, Math.round((minX - 1.2) * RK));
    var rx0 = Math.max(0, Math.round((maxX + 1.2) * RK)), rx1 = Math.min(RW - 1, Math.round((F.x + F.w - 0.5) * RK));
    if (lx1 < lx0 || rx1 < rx0 || y1 < y0) return 0;
    var inkL = 0, inkR = 0, nL = 0, nR = 0;
    for (var y = y0; y <= y1; y++) {
        for (var x = lx0; x <= lx1; x++) { nL++; if (rbin[y * RW + x]) inkL++; }
        for (var x2 = rx0; x2 <= rx1; x2++) { nR++; if (rbin[y * RW + x2]) inkR++; }
    }
    var fL = inkL / (nL + 1), fR = inkR / (nR + 1);
    return (fL - fR) / (fL + fR + 0.004);
}
// dst 角顺序 TL,TR,BR,BL ← 图像四象限标记索引（0=图左上 1=右上 2=右下 3=左下）
var ROT_MAP = [[0, 1, 2, 3], [1, 2, 3, 0], [2, 3, 0, 1], [3, 0, 1, 2]];
// 反射点序（二面体镜像假设）：背面拍摄/镜像画面时，正确对应是反射序——单应按反射序映射可把镜像内容翻回来。
// ri 4-7 复用 ROT_MAP 下标：MIR_MAP[ri-4]，方向旗标据此区分「旋转」与「镜像」
var MIR_MAP = [[0, 3, 2, 1], [3, 2, 1, 0], [2, 1, 0, 3], [1, 0, 3, 2]];
function centerOf(c) { return { x: (c.x0 + c.x1) / 2, y: (c.y0 + c.y1) / 2 }; }
// 四点按 TL,TR,BR,BL：凸四边形几何合理性校验（真实纸面透视不会出现极端形状，拦截键盘键/桌面纹等伪标记误配）
function quadOk(p, w, h) {
    if (!p || p.some(function (q) { return !q; })) return false;
    var cross = [], side = [];
    for (var i = 0; i < 4; i++) {
        var a = p[i], b = p[(i + 1) % 4], c = p[(i + 2) % 4];
        var v1x = b.x - a.x, v1y = b.y - a.y, v2x = c.x - b.x, v2y = c.y - b.y;
        cross.push(v1x * v2y - v1y * v2x);
        side.push(Math.sqrt(v1x * v1x + v1y * v1y));
    }
    if (cross.some(function (v) { return v <= 0; })) return false;   // 须为凸且角序正确（图像坐标顺时针）
    var mn = Math.min.apply(null, side), mx = Math.max.apply(null, side);
    if (mx < w * 0.08 || mn < w * 0.04) return false;                // 纸面过小（标记误配到近角碎屑）
    // 长宽比上限按模板取景框宽高比放宽：横长卡（如 198×78≈2.54:1）的长短边之比天然≈2.5，
    // 固定 3.2 上限会把透视稍大的真实四边形误杀 → 退纸边兜底（表现即「四点明明入镜却提示定位标记未检全」）
    var arE = (TPL && TPL.frame && TPL.frame.h > 0) ? Math.max(TPL.frame.w / TPL.frame.h, TPL.frame.h / TPL.frame.w) : 1.41;
    if (mx / mn > Math.max(3.2, arE * 1.45)) return false;           // 不得超过模板横纵比 ×1.45（透视余量），也不超过绝对上限
    var o1 = side[0] / side[2], o2 = side[1] / side[3];
    if (Math.max(o1, 1 / o1) > 2.2 || Math.max(o2, 1 / o2) > 2.2) return false;   // 对边长度应接近
    return true;
}
// 该朝向下四边形与模板取景框横纵比的一致度（1=一致，0=相差 ≥2.2 倍）：由设计模板的横纵比例
// 直接判定「是否旋转/转到哪个方向」——横长卡转 90° 后边长比倒挂，一致性骤降
function aspectAgree(sq) {
    if (!TPL || !TPL.frame) return 1;
    var d0x = sq[1].x - sq[0].x, d0y = sq[1].y - sq[0].y, d1x = sq[2].x - sq[1].x, d1y = sq[2].y - sq[1].y;
    var sW = Math.sqrt(d0x * d0x + d0y * d0y), sH = Math.sqrt(d1x * d1x + d1y * d1y);
    if (sW < 1 || sH < 1) return 0;
    var r = sW / sH, e = TPL.frame.w / TPL.frame.h;
    return Math.max(0, 1 - Math.abs(Math.log(r / e)) / Math.log(2.2));
}
// 页头定位点几何（与设计器 hmarkGeom 同源）：当前页取景框上边 k=页码+1 枚（第1页2枚、第2页3枚…）
// 5mm 黑方块。hmarkPosList 供 hmarkVote/hmarkGeom 共用，两种排布：
// ① 聚簇新版式（hmarkCluster）：首枚中心距左上角点中心 10mm（点径+间隙，间隙=1 点径），向右每枚 +10mm，
//    位置集前缀稳定（页 p = 页 p+1 的前 k 枚）→ 手性布局：镜像采集时簇落右上，页头点证据直接锁定镜像假设；
// ② 旧版式对称分布：自 22% 至 78% 等距（k=2 时即 22%/78%，已印卡兼容）。
// 四角方块全同 → 标记几何只能定四边形不能定阅读方向；页头点数量随页数递增 → 数页头点即可确定方向与页码
function hmarkPosList(fr, k) {
    var arr = [];
    for (var j = 0; j < k; j++)
        arr.push(TPL.hmarkCluster ? fr.x + (j + 1) * 10
                                  : fr.x + fr.w * (0.22 + 0.56 * (k > 1 ? j / (k - 1) : 0.5)));
    return arr;
}
function hmarkGeom() {
    var F = TPL.frame, s = 5, k = CURPG + 2, arr = [];
    hmarkPosList(F, k).forEach(function (cx) { arr.push({ x: cx - s / 2, y: F.y - s / 2 }); });
    return arr;
}
// 矫正图中页头点的命中枚数（严格版）：各方块内缩 40% 固定窗口采样，暗占比 ≥0.45 计 1 命中。
// 用于 scanPage 候选评分/页码判定——窗口必须严格：倒置假设下卡片底部印刷文字行会落进页头点预期区，
// 邻域搜索会让错误方向候选白捡 hm 分、污染最优解选取（曾致撕边卡被翻成 180°「全题未涂」通过）
function hmarkHit(rbin) {
    if (!TPL.hmarks) return 0;
    var s = 5, hit = 0;
    hmarkGeom().forEach(function (m) {
        var x0 = Math.max(0, Math.round((m.x + s * 0.2) * RK)), x1 = Math.min(RW - 1, Math.round((m.x + s * 0.8) * RK));
        var y0 = Math.max(0, Math.round((m.y + s * 0.2) * RK)), y1 = Math.min(RH - 1, Math.round((m.y + s * 0.8) * RK));
        var dark = 0, tot = 0;
        for (var y = y0; y <= y1; y++) for (var x = x0; x <= x1; x++) { tot++; if (rbin[y * RW + x]) dark++; }
        if (tot && dark / tot >= 0.45) hit++;
    });
    return hit;
}
// 页头点命中（容差版）：仅在最终验收用——相机实拍常走涂框配准路径（标记未检全），矫正残差 1~2mm
// 即可让固定窗口脱靶 → 全灭误拒。±1.5mm 邻域（0.75mm 步进）搜索最优暗占比，仍须该处真有实心
// 黑方块才算命中；此时最优矫正已选定，不存在「污染方向选取」的问题
function hmarkHitTol(rbin) {
    if (!TPL.hmarks) return 0;
    var s = 5, hit = 0;
    hmarkGeom().forEach(function (m) {
        var best = 0;
        for (var oy = -1.5; oy <= 1.51; oy += 0.75) for (var ox = -1.5; ox <= 1.51; ox += 0.75) {
            var x0 = Math.max(0, Math.round((m.x + ox + s * 0.2) * RK)), x1 = Math.min(RW - 1, Math.round((m.x + ox + s * 0.8) * RK));
            var y0 = Math.max(0, Math.round((m.y + oy + s * 0.2) * RK)), y1 = Math.min(RH - 1, Math.round((m.y + oy + s * 0.8) * RK));
            var dark = 0, tot = 0;
            for (var y = y0; y <= y1; y++) for (var x = x0; x <= x1; x++) { tot++; if (rbin[y * RW + x]) dark++; }
            if (tot) { var r = dark / tot; if (r > best) best = r; }
        }
        if (best >= 0.45) hit++;
    });
    return hit;
}
// 页码投票：对已矫正图统计取景框上边带内的定位点个数，页码 = 点数 − 2。
// 计数式而非逐位置匹配的原因：投票发生在「按当前页框矫正」的图上，若实际是其他页的卡，
// 矫正含未知的横向拉伸——聚簇版式点位是绝对 mm（首枚距框左缘 10mm），拉伸后错位失配；
// 旧版式 22%~78% 为框宽相对位置虽可自洽，但两版式统一用点计数更稳：点恒在顶边带内
// （各页框共锚 (fr.x, fr.y)，错页矫正只缩放/平移不脱离顶边线），数出的点数与页码一一对应。
// 段宽 ≥2mm 滤噪（5mm 点径经 0.5~2 倍挤压变形仍可分辨）；端点越界容差见 x0/x1 钳位。
// 漏点语义与旧逐位置投票一致：末枚漏计 → 投给更小页（前缀稳定），由该页验收兜底
function hmarkVote(rbin) {
    if (!TPL.hmarks || TPL.pages <= 1) return 0;
    var fr = TPL.frame;
    var y0 = Math.max(0, Math.round((fr.y - 2.5 + 1.2) * RK)), y1 = Math.min(RH - 1, Math.round((fr.y - 2.5 + 3.8) * RK));
    var x0 = Math.max(0, Math.round((fr.x + 6.5) * RK)), x1 = Math.min(RW - 1, Math.round((fr.x + fr.w - 6.5) * RK));
    var runs = 0, run = 0, minRun = Math.round(2 * RK);
    for (var x = x0; x <= x1; x++) {
        var dark = 0, tot = 0;
        for (var y = y0; y <= y1; y++) { tot++; if (rbin[y * RW + x]) dark++; }
        if (tot && dark / tot >= 0.5) run++;
        else { if (run >= minRun) runs++; run = 0; }
    }
    if (run >= minRun) runs++;
    return Math.max(0, Math.min(TPL.pages - 1, runs - 2));
}
// 印刷框对齐度：涂框外圈环带暗像素占比 ≥0.22 视为「框线对齐」，返回对齐涂框占比（0-1）。
// 原理：印刷框线永远存在（与是否填涂无关）——矫正/方向正确时绝大多数涂框环带命中框线；
// 倒卡/误配时涂框采样区落在别处 → 对齐度骤降。比旧「填涂占比打分」稳，杜绝把倒卡评成正向
function ringMatch(rbin) {
    var hit = 0, tot = 0;
    TPL._bubbles.forEach(function (b) {
        tot++;
        var x0 = Math.max(0, Math.round(b.x * RK)), y0 = Math.max(0, Math.round(b.y * RK));
        var x1 = Math.min(RW - 1, Math.round((b.x + b.w) * RK)), y1 = Math.min(RH - 1, Math.round((b.y + b.h) * RK));
        var bw = x1 - x0 + 1, bh = y1 - y0 + 1;
        if (bw < 3 || bh < 3) return;
        var mx = Math.max(1, Math.round(bw * 0.18)), my = Math.max(1, Math.round(bh * 0.18));
        var dark = 0, t2 = 0;
        for (var yy = y0; yy <= y1; yy++) {
            var hb = (yy <= y0 + my - 1 || yy >= y1 - my + 1);
            for (var xx = x0; xx <= x1; xx++) {
                if (hb || xx <= x0 + mx - 1 || xx >= x1 - mx + 1) { t2++; if (rbin[yy * RW + xx]) dark++; }
            }
        }
        if (t2 && dark / t2 >= 0.22) hit++;
    });
    return tot ? hit / tot : 0;
}
// 组装候选四边形尝试：每象限取前 K=6 个候选做组合枚举（≤6^4=1296，quadOk 纯几何无需矫正，代价可忽略），
// 按「与模板取景框横纵比一致度」降序取前 12 组参与矫正评分。实拍中答题卡常不居中（键盘/桌面占半幅），
// 真标记按「到图角距离」可能排到各象限第 2~6 位，伪碎屑（键盘键帽、纸面纹理）反而最近——
// 旧的「全首选/全次近/单象限替换」枚举覆盖不到「部分次近+部分首选」的真组合（实测 904_8 真组合
// = 3 次近 + 1 首选、171059_516 真标记排第 4~6 位，均漏）。横纵比排序让真组合几乎总在评分队列最前，
// 配合 recognize 的早停，干净照片仍然首组即停、零额外开销
// → 仅检到3个标记时按平行四边形推算第4点 → 全失败按图片边缘兜底
function buildQuadAttempts(byQ, w, h) {
    var K = 6, lists = [];
    // 尺寸一致性先验：四角定位标记是同一印刷版上的同尺寸方块，取四象限各自最大候选 bbox 面积的
    // 中位数为基准（中位数抗单象限标记缺失/大噪声块），窗口 [0.25, 2.2]×基准 过滤候选后再取 top-K。
    // 背景：候选按距图角距离排序，照片边缘的桌面小杂物（4~9px）离角更近，会把 24~28px 的真标记
    // 挤出 top-6（实测 171059_516 真组合因此落榜，12 个尝试全错、ring≤0.58）
    var perMax = [];
    for (var qi = 0; qi < 4; qi++) {
        var mx = 0;
        for (var i = 0; i < byQ[qi].length; i++) {
            var c0 = byQ[qi][i].c;
            mx = Math.max(mx, (c0.x1 - c0.x0 + 1) * (c0.y1 - c0.y0 + 1));
        }
        perMax.push(mx);
    }
    var pms = perMax.slice().sort(function (a, b) { return a - b; });
    var prior = (pms[1] + pms[2]) / 2;
    var loA = prior * 0.25, hiA = prior * 2.2;
    for (var qi2 = 0; qi2 < 4; qi2++) {
        var l = [];
        for (var i2 = 0; i2 < byQ[qi2].length && l.length < K; i2++) {
            var c2 = byQ[qi2][i2].c;
            var ba = (c2.x1 - c2.x0 + 1) * (c2.y1 - c2.y0 + 1);
            if (ba < loA || ba > hiA) continue;
            l.push(centerOf(c2));
        }
        // 该象限过滤后为空（真标记可能缺失）：回退用未过滤 top-2，保住三标记推算路径的原料
        if (!l.length) for (var i3 = 0; i3 < byQ[qi2].length && i3 < 2; i3++) l.push(centerOf(byQ[qi2][i3].c));
        lists.push(l);
    }
    var combos = [];
    (function rec(qi, pts) {
        if (qi === 4) {
            if (quadOk(pts, w, h)) combos.push({ pts: pts.slice(), asp: aspectAgree(pts) });
            return;
        }
        for (var i = 0; i < lists[qi].length; i++) { pts.push(lists[qi][i]); rec(qi + 1, pts); pts.pop(); }
    })(0, []);
    combos.sort(function (a, b) { return b.asp - a.asp; });
    var attempts = [];
    for (var ci = 0; ci < combos.length && attempts.length < 12; ci++) attempts.push({ pts: combos[ci].pts, flags: [] });
    if (!attempts.length) {
        var miss = -1, have = 0;
        for (var qi2 = 0; qi2 < 4; qi2++) { if (byQ[qi2].length) have++; else miss = qi2; }
        if (have === 3) {
            var base = [];
            for (var qi3 = 0; qi3 < 4; qi3++) base.push(byQ[qi3].length ? centerOf(byQ[qi3][0].c) : null);
            var prev = base[(miss + 3) % 4], next = base[(miss + 1) % 4], opp = base[(miss + 2) % 4];
            var p4 = base.slice(); p4[miss] = { x: prev.x + next.x - opp.x, y: prev.y + next.y - opp.y };
            if (quadOk(p4, w, h)) attempts.push({ pts: p4, flags: ['仅检出3个定位标记，第4个按几何推算（可能不准，建议正对重拍）'] });
        }
    }
    if (!attempts.length) attempts.push({ edge: true, flags: ['未检全四角定位标记，按纸面边缘矫正（可能不准）'] });
    return attempts;
}
// 单应矩阵逆向采样：二值图（判分用）；rw/rh 缺省为整页画布，预览外扩矫正时传放大尺寸
function warpBin(hh, bin, w, h, rw, rh) {
    rw = rw || RW; rh = rh || RH;
    var rbin = new Uint8Array(rw * rh);
    for (var y = 0; y < rh; y++) {
        for (var x = 0; x < rw; x++) {
            var den = hh[6] * x + hh[7] * y + 1;
            var sx = (hh[0] * x + hh[1] * y + hh[2]) / den;
            var sy = (hh[3] * x + hh[4] * y + hh[5]) / den;
            var ix = Math.floor(sx), iy = Math.floor(sy);
            rbin[y * rw + x] = (sx >= 0 && ix < w && sy >= 0 && iy < h) ? bin[iy * w + ix] : 0;
        }
    }
    return rbin;
}
// 单应矩阵逆向采样：彩色原图（叠加批注展示用）；rw/rh 同上
function warpColor(hh, data, w, h, rw, rh) {
    rw = rw || RW; rh = rh || RH;
    var img = new Uint8ClampedArray(rw * rh * 4);
    for (var y = 0; y < rh; y++) {
        for (var x = 0; x < rw; x++) {
            var den = hh[6] * x + hh[7] * y + 1;
            var sx = (hh[0] * x + hh[1] * y + hh[2]) / den;
            var sy = (hh[3] * x + hh[4] * y + hh[5]) / den;
            var ix = Math.floor(sx), iy = Math.floor(sy), q = (y * rw + x) * 4;
            if (sx >= 0 && ix < w && sy >= 0 && iy < h) {
                var s = (iy * w + ix) * 4;
                img[q] = data[s]; img[q + 1] = data[s + 1]; img[q + 2] = data[s + 2];
            } else { img[q] = 255; img[q + 1] = 255; img[q + 2] = 255; }
            img[q + 3] = 255;
        }
    }
    return new ImageData(img, rw, rh);
}
// 单应采样闭包：输出像素 (x,y) → 源图 [sx,sy]（hh 为 homography() 的 8 参数返回，dst→src 约定）
function homWarpFn(hh) {
    return function (x, y) {
        var den = hh[6] * x + hh[7] * y + 1;
        return [(hh[0] * x + hh[1] * y + hh[2]) / den, (hh[3] * x + hh[4] * y + hh[5]) / den];
    };
}
// Coons 分段拉直采样闭包（预览专用）：srcC=四角实测（外扩源图 px），ems=左/右/下边中点实测。
// 边界曲线：顶边被页码点占用恒直线；3 点边过中点二次贝塞尔（控制点 C=2M−(A+D)/2 → 曲线 B(0.5)=M，
// C1 连续），中点未实测的边退化直线。P(u,v)=(1−v)T(u)+vB(u)+(1−u)L(v)+uR(v)−双线性角项：
// 边界精确过实测标记，内部随纸张弯曲平滑展平（非线性弯曲是单应描述不了的，Coons 用边界锚点分段拉直）；
// 全直线边时严格退化为双线性（故仅在有中点实测时启用，四角纯单应路径保持原行为）
function coonsWarpFn(srcC, ems, W2, H2, PAD) {
    var TL = srcC[0], TR = srcC[1], BR = srcC[2], BL = srcC[3];
    function quad(a, m, d) {
        var cx = 2 * m[0] - (a[0] + d[0]) / 2, cy = 2 * m[1] - (a[1] + d[1]) / 2;
        return function (t) {
            var s = 1 - t;
            return [s * s * a[0] + 2 * s * t * cx + t * t * d[0], s * s * a[1] + 2 * s * t * cy + t * t * d[1]];
        };
    }
    function lin(a, d) {
        return function (t) { return [a[0] + (d[0] - a[0]) * t, a[1] + (d[1] - a[1]) * t]; };
    }
    var Tc = lin(TL, TR), Bc = ems[2] ? quad(BL, ems[2], BR) : lin(BL, BR),
        Lc = ems[0] ? quad(TL, ems[0], BL) : lin(TL, BL), Rc = ems[1] ? quad(TR, ems[1], BR) : lin(TR, BR);
    var u0 = PAD, du = W2 - 2 * PAD, v0 = PAD, dv = H2 - 2 * PAD;
    return function (x, y) {
        var u = (x - u0) / du, v = (y - v0) / dv, iu = 1 - u, iv = 1 - v;
        var t = Tc(u), b = Bc(u), l = Lc(v), r = Rc(v);
        return [iv * t[0] + v * b[0] + iu * l[0] + u * r[0]
                  - (iu * iv * TL[0] + u * iv * TR[0] + u * v * BR[0] + iu * v * BL[0]),
                iv * t[1] + v * b[1] + iu * l[1] + u * r[1]
                  - (iu * iv * TL[1] + u * iv * TR[1] + u * v * BR[1] + iu * v * BL[1])];
    };
}
// 采样函数逆向重采样：彩色原图（预览批注展示用）；fn(x,y) 返回源图 [sx,sy]
//（单应/Coons 统一入口，rw/rh 为输出画布尺寸，w/h 为源图尺寸）
function warpColorFn(fn, data, w, h, rw, rh) {
    var img = new Uint8ClampedArray(rw * rh * 4);
    for (var y = 0; y < rh; y++)
        for (var x = 0; x < rw; x++) {
            var sxy = fn(x, y), ix = Math.floor(sxy[0]), iy = Math.floor(sxy[1]), q = (y * rw + x) * 4;
            if (sxy[0] >= 0 && ix < w && sxy[1] >= 0 && iy < h) {
                var s = (iy * w + ix) * 4;
                img[q] = data[s]; img[q + 1] = data[s + 1]; img[q + 2] = data[s + 2];
            } else { img[q] = 255; img[q + 1] = 255; img[q + 2] = 255; }
            img[q + 3] = 255;
        }
    return new ImageData(img, rw, rh);
}
// 纸面区域估计：亮度列/行投影找主亮区（答题卡远亮于桌面/键盘/阴影）。
// 用途：边缘兜底矫正前先裁出纸面，避免把桌面边缘拉进矫正图造成整体拉伸错位；返回 4 角点（TL,TR,BR,BL）或 null
function paperBBox(gray, w, h) {
    var f = Math.max(1, Math.round(Math.max(w, h) / 200));
    var sw = Math.max(1, Math.floor(w / f)), sh = Math.max(1, Math.floor(h / f));
    var col = new Float64Array(sw), row = new Float64Array(sh);
    for (var y = 0; y < sh; y++)
        for (var x = 0; x < sw; x++)
            if (gray[y * f * w + x * f] >= 110) { col[x]++; row[y]++; }
    function span(arr, n) {
        var mx = 0; for (var i = 0; i < n; i++) if (arr[i] > mx) mx = arr[i];
        if (mx < n * 0.15) return null;   // 亮像素过少（整帧偏暗）：放弃裁剪
        var th = mx * 0.25, a = 0, b = n - 1;
        while (a < b && arr[a] < th) a++;
        while (b > a && arr[b] < th) b--;
        return [a, b];
    }
    var cs = span(col, sw), rs = span(row, sh);
    if (!cs || !rs) return null;
    var x0 = Math.max(0, cs[0] * f - f), y0 = Math.max(0, rs[0] * f - f);
    var x1 = Math.min(w - 1, (cs[1] + 1) * f), y1 = Math.min(h - 1, (rs[1] + 1) * f);
    if (x1 - x0 < w * 0.3 || y1 - y0 < h * 0.3) return null;   // 裁剪区过小不可信
    return [{ x: x0 + 1, y: y0 + 1 }, { x: x1 - 1, y: y0 + 1 }, { x: x1 - 1, y: y1 - 1 }, { x: x0 + 1, y: y1 - 1 }];
}
// ===== 印刷框网格吸附（v3 核心新方法） =====
// 旧思路依赖「四角标记/纸面边缘」一次性单应映射，标记漏检或纸边误判时整体错位（批注错位/裁切不正）。
// 新思路：粗矫正只求「大致摆正」，随后以答题卡上每个涂框的【印刷边框线】为对齐基准（位置精确、与
// 拍摄光线无关），对目标矩形做 平移×2/旋转/横纵缩放×2 五参数坐标下降搜索，直接最大化「框线采样点
// 命中度」——把矫正图吸附到印刷网格上，标记与纸边的误差被整体修正。
// 形态腐蚀（3×3 十字 × passes）：剔除细长暗线（纸边/阴影边），实心定位方块仅轻微缩小、中心不变
function erodeBin(bin, w, h, passes) {
    var a = bin;
    for (var p = 0; p < passes; p++) {
        var b = new Uint8Array(w * h);
        for (var y = 0; y < h; y++) {
            var ym = Math.max(0, y - 1) * w, yp = Math.min(h - 1, y + 1) * w, yc = y * w;
            for (var x = 0; x < w; x++) {
                var xm = Math.max(0, x - 1), xp = Math.min(w - 1, x + 1);
                b[yc + x] = (a[yc + xm] && a[yc + xp] && a[ym + x] && a[yp + x] && a[yc + x]) ? 1 : 0;
            }
        }
        a = b;
    }
    return a;
}
// 微调采样点：每个涂框边框上取 12 点（模板毫米 × RK → 矫正图 px）；涂框很多时均匀抽稀到 ≤60 个
// （保持覆盖全卡各区域），控制精调单次评估开销
function ringSamplePts() {
    var pts = [], bs = TPL._bubbles, stride = Math.max(1, Math.ceil(bs.length / 60));
    for (var i = 0; i < bs.length; i += stride) {
        var b = bs[i];
        var x0 = b.x * RK, x1 = (b.x + b.w) * RK, y0 = b.y * RK, y1 = (b.y + b.h) * RK;
        for (var j = 0; j < 3; j++) {
            var t = (j + 0.5) / 3;
            pts.push([x0 + (x1 - x0) * t, y0], [x0 + (x1 - x0) * t, y1], [x0, y0 + (y1 - y0) * t], [x1, y0 + (y1 - y0) * t]);
        }
    }
    return pts;
}
// 快速打分：样本点经单应逆映射回原图，按到暗像素距离分级计分（积分图 O(1) 查询）：
// <0.8px 记 1.0、<2.2px 记 0.55、<4.5px 记 0.25。分级让目标函数有指向「线中心」的坡度——
// 二值「碰到即得分」会让精调停在「刚碰到印刷线」的位置，无法继续对齐
function ringHitPts(iib, w, h, pts, hh) {
    var T = [0.8, 2.2, 4.5], Wt = [1, 0.55, 0.25], sc = 0;
    for (var i = 0; i < pts.length; i++) {
        var x = pts[i][0], y = pts[i][1];
        var den = hh[6] * x + hh[7] * y + 1;
        var sx = (hh[0] * x + hh[1] * y + hh[2]) / den;
        var sy = (hh[3] * x + hh[4] * y + hh[5]) / den;
        for (var t = 0; t < 3; t++) {
            var rho = T[t];
            var x0 = sx - rho, y0 = sy - rho, x1 = sx + rho, y1 = sy + rho;
            if (x1 < 0 || y1 < 0 || x0 > w - 1 || y0 > h - 1) continue;
            if (x0 < 0) x0 = 0; if (y0 < 0) y0 = 0;
            if (x1 > w - 1) x1 = w - 1; if (y1 > h - 1) y1 = h - 1;
            var ix0 = Math.floor(x0), iy0 = Math.floor(y0), ix1 = Math.floor(x1), iy1 = Math.floor(y1);
            if (iib[(iy1 + 1) * (w + 1) + ix1 + 1] - iib[iy0 * (w + 1) + ix1 + 1] - iib[(iy1 + 1) * (w + 1) + ix0] + iib[iy0 * (w + 1) + ix0] > 0) { sc += Wt[t]; break; }
        }
    }
    return pts.length ? sc / pts.length : 0;
}
// 目标矩形参数（中心cx,cy / 半宽hw / 半高hv / 旋转角th）→ 四角 TL,TR,BR,BL
function rectCorners(cx, cy, hw, hv, th) {
    var ct = Math.cos(th), st = Math.sin(th);
    return [
        { x: cx - hw * ct + hv * st, y: cy - hw * st - hv * ct },
        { x: cx + hw * ct + hv * st, y: cy + hw * st - hv * ct },
        { x: cx + hw * ct - hv * st, y: cy + hw * st + hv * ct },
        { x: cx - hw * ct - hv * st, y: cy - hw * st + hv * ct }
    ];
}
// 印刷框网格吸附精调：把粗矫正（标记/纸边）的目标矩形「吸附」到涂框印刷线上。
// ① 平移/旋转粗网格预搜（覆盖 ±3° 倾斜与数十像素偏移，避免坐标下降陷入平台）
// ② 分级计分 + 坐标下降：五参数（平移×2/旋转/横纵缩放×2）逐个试探 ±步长，步长逐级减半，
//   命中度提升则接受。纸边误判（百分之几的缩放/平移差）、拍摄倾斜、标记偏差在此一次性修正
function refineDst(src4, bin, w, h, pts, dstRect) {
    var hw0 = (dstRect[2].x - dstRect[0].x) / 2, hv0 = (dstRect[2].y - dstRect[0].y) / 2;
    var P0 = [(dstRect[0].x + dstRect[2].x) / 2, (dstRect[0].y + dstRect[2].y) / 2, hw0, hv0, 0];
    // 二值图积分图：任意矩形盒内暗像素和 O(1) 查询
    var iib = new Float64Array((w + 1) * (h + 1));
    for (var y = 0; y < h; y++) {
        var rs = 0;
        for (var x = 0; x < w; x++) { rs += bin[y * w + x]; iib[(y + 1) * (w + 1) + x + 1] = iib[y * (w + 1) + x + 1] + rs; }
    }
    function ev(p) {
        if (p[2] < hw0 * 0.6 || p[2] > hw0 * 1.6 || p[3] < hv0 * 0.6 || p[3] > hv0 * 1.6) return -1;
        var hh = homography(rectCorners(p[0], p[1], p[2], p[3], p[4]), src4);
        return hh ? ringHitPts(iib, w, h, pts, hh) : -1;
    }
    function descend(P, bs) {
        var stages = [[RW * 0.008, 0.02, 0.012], [RW * 0.004, 0.01, 0.006], [RW * 0.002, 0.005, 0.003], [RW * 0.001, 0.0025, 0.0015], [RW * 0.0005, 0.0012, 0.0008]];
        for (var si = 0; si < stages.length; si++) {
            var stP = stages[si][0], stA = stages[si][1], stS = stages[si][2];
            for (var loop = 0, moved = true; moved && loop < 8; loop++) {
                moved = false;
                for (var pi = 0; pi < 5; pi++) {
                    var mag = (pi === 4) ? stA : (pi >= 2 ? P[pi] * stS : stP);
                    for (var sgn = -1; sgn <= 1; sgn += 2) {
                        var q2 = P.slice(); q2[pi] += sgn * mag;
                        var s3 = ev(q2);
                        if (s3 > bs + 1e-6) { bs = s3; P = q2; moved = true; }
                    }
                }
            }
        }
        return { P: P, bs: bs };
    }
    // 粗网格预搜：平移×旋转×整体缩放三联合格（旋转与缩放误差相互耦合，轴级下降单独走不出来）
    var cells = [];
    for (var gy = -2; gy <= 2; gy++) for (var gx = -2; gx <= 2; gx++) for (var ga = -3; ga <= 3; ga++) for (var gs = -2; gs <= 2; gs++) {
        var q = [P0[0] + gx * RW * 0.016, P0[1] + gy * RW * 0.016, P0[2] * (1 + gs * 0.015), P0[3] * (1 + gs * 0.015), ga * 0.03];
        cells.push({ p: q, sc: ev(q) });
    }
    cells.sort(function (a, b) { return b.sc - a.sc; });
    // 多起点下降：涂框网格近似周期性，单起点易锁到「相邻一格」的伪峰——取前 5 个格子各自收敛，
    // 真对齐的得分（≈1.0）显著高于伪峰（<0.6），全局取优即破歧义
    var bestR = null;
    for (var ci = 0; ci < Math.min(5, cells.length); ci++) {
        var r = descend(cells[ci].p.slice(), cells[ci].sc);
        if (!bestR || r.bs > bestR.bs) bestR = r;
    }
    var hhF = homography(rectCorners(bestR.P[0], bestR.P[1], bestR.P[2], bestR.P[3], bestR.P[4]), src4);
    return hhF ? { hh: hhF, sc: bestR.bs } : null;
}
// ===== 涂框特征点对齐（v4 换方法） =====
// refineDst 是「整体五参数」吸附：预搜范围有限（平移 ±10mm/缩放 ±3%），粗矫正偏差大（阴影、纸边
// 误判、拍摄比例失真）时吸不上。换思路：粗矫正图上对每个涂框【单独】检测印刷边框的实测位置，
// 得到几十~上百组「模板位置 → 实测位置」点对，鲁棒拟合仿射校正（平移/旋转/横纵缩放/剪切全支持）。
// 特征级对齐不依赖标记与纸边，个别框检测错会被中位数剔除，比例失真还能顺带修正。
// 3×3 行主序矩阵乘
function mat3Mul(A, B) {
    var C = new Array(9);
    for (var r = 0; r < 3; r++) for (var c = 0; c < 3; c++) {
        var s = 0;
        for (var k = 0; k < 3; k++) s += A[r * 3 + k] * B[k * 3 + c];
        C[r * 3 + c] = s;
    }
    return C;
}
// 3×3 矩阵求逆（伴随矩阵）；奇异返回 null（精调↔拟合交替收敛时反推源四边形用）
function mat3Inv(M) {
    for (var k9 = 0; k9 < 9; k9++) if (!isFinite(M[k9])) return null;   // NaN/Inf 防御：NaN 比较不触发下面的奇异保护，会静默产出垃圾矩阵
    var a = M[0], b = M[1], c = M[2], d = M[3], e = M[4], f = M[5], g = M[6], h = M[7], i = M[8];
    var A = e * i - f * h, B = f * g - d * i, C = d * h - e * g;
    var det = a * A + b * B + c * C;
    if (Math.abs(det) < 1e-12) return null;
    return [A / det, (c * h - b * i) / det, (b * f - c * e) / det,
            B / det, (a * i - c * g) / det, (c * d - a * f) / det,
            C / det, (b * g - a * h) / det, (a * e - b * d) / det];
}
// 3×3 线性方程组高斯消元（列主元）；奇异返回 null
function solve3(M, v) {
    var A = M.slice(), b = [v[0], v[1], v[2]];
    for (var i = 0; i < 3; i++) {
        var p = i, mx = Math.abs(A[i * 3 + i]);
        for (var r = i + 1; r < 3; r++) { var av = Math.abs(A[r * 3 + i]); if (av > mx) { mx = av; p = r; } }
        if (mx < 1e-9) return null;
        if (p !== i) {
            for (var c = 0; c < 3; c++) { var t = A[i * 3 + c]; A[i * 3 + c] = A[p * 3 + c]; A[p * 3 + c] = t; }
            var tv = b[i]; b[i] = b[p]; b[p] = tv;
        }
        for (var r2 = i + 1; r2 < 3; r2++) {
            var f = A[r2 * 3 + i] / A[i * 3 + i];
            for (var c2 = i; c2 < 3; c2++) A[r2 * 3 + c2] -= f * A[i * 3 + c2];
            b[r2] -= f * b[i];
        }
    }
    var x = [0, 0, 0];
    for (var i2 = 2; i2 >= 0; i2--) {
        var s = b[i2];
        for (var c3 = i2 + 1; c3 < 3; c3++) s -= A[i2 * 3 + c3] * x[c3];
        x[i2] = s / A[i2 * 3 + i2];
    }
    return x;
}
// 鲁棒仿射拟合：corr = [模板x, 模板y, 实测x, 实测y]。最小二乘 → 剔残差离群 → 重拟合 ≤3 轮。
// 返回 { P:[a,b,c,d,e,f]（实测 = P×模板齐次）, n: 内点数, med: 全量中位残差px }
function fitAffineRobust(corr) {
    var use = corr.slice(), P = null;
    for (var round = 0; round < 3; round++) {
        var M = [0, 0, 0, 0, 0, 0, 0, 0, 0], vx = [0, 0, 0], vy = [0, 0, 0];
        for (var i = 0; i < use.length; i++) {
            var mx = use[i][0], my = use[i][1], dx = use[i][2], dy = use[i][3];
            M[0] += mx * mx; M[1] += mx * my; M[2] += mx;
            M[4] += my * my; M[5] += my; M[8] += 1;
            vx[0] += mx * dx; vx[1] += my * dx; vx[2] += dx;
            vy[0] += mx * dy; vy[1] += my * dy; vy[2] += dy;
        }
        M[3] = M[1]; M[6] = M[2]; M[7] = M[5];
        var rx = solve3(M, vx), ry = solve3(M, vy);
        if (!rx || !ry) return null;
        P = [rx[0], rx[1], rx[2], ry[0], ry[1], ry[2]];
        var res = [];
        for (var j = 0; j < use.length; j++) {
            var ex = P[0] * use[j][0] + P[1] * use[j][1] + P[2] - use[j][2];
            var ey = P[3] * use[j][0] + P[4] * use[j][1] + P[5] - use[j][3];
            res.push({ r: Math.sqrt(ex * ex + ey * ey), i: j });
        }
        res.sort(function (a, b) { return a.r - b.r; });
        var med = res[res.length >> 1].r;
        if (round === 2 || med < 0.6) break;   // 已足够准
        var th = Math.max(2.2, med * 2.5), keep = [];
        for (var k = 0; k < res.length; k++) if (res[k].r <= th) keep.push(use[res[k].i]);
        if (keep.length < 6 || keep.length === use.length) break;
        use = keep;
    }
    var fin = [];
    for (var f2 = 0; f2 < corr.length; f2++) {
        var e2x = P[0] * corr[f2][0] + P[1] * corr[f2][1] + P[2] - corr[f2][2];
        var e2y = P[3] * corr[f2][0] + P[4] * corr[f2][1] + P[5] - corr[f2][3];
        fin.push(Math.sqrt(e2x * e2x + e2y * e2y));
    }
    fin.sort(function (a, b) { return a - b; });
    var medF = fin[fin.length >> 1], inl = 0;
    for (var f3 = 0; f3 < fin.length; f3++) if (fin[f3] <= Math.max(2.2, medF * 2.5)) inl++;
    return { P: P, n: inl, med: medF };
}
// hh（8参单应）∘ 仿射 P：先仿射后单应（矫正空间内先套校正再映射回原图）
function composeAffine(hh0, P) {
    var C = mat3Mul([hh0[0], hh0[1], hh0[2], hh0[3], hh0[4], hh0[5], hh0[6], hh0[7], 1],
                    [P[0], P[1], P[2], P[3], P[4], P[5], 0, 0, 1]);
    return [C[0] / C[8], C[1] / C[8], C[2] / C[8], C[3] / C[8], C[4] / C[8], C[5] / C[8], C[6] / C[8], C[7] / C[8]];
}
// 逐框检测：以「预期中心+偏移 s」开 ±R 窗口，BFS 连通域，取「尺寸与涂框相符、距窗口中心最近且无
// 等距歧义」的分量 bbox 中心为实测框心（涂块在框内不改变 bbox → 天然抗填涂干扰；触窗界/尺寸不符者弃）。
// 防错锁关键：R < 最小框间距 pmin 的一半 → 窗口内最多只有一个真框候选，绝不会锁到相邻框；
// 大偏差用 9 方向预偏移窗口（半径 rh）覆盖，捕捉范围 ≈ R+rh。两轮迭代逐步收紧。
// 对应点始终记录【未偏移】的模板坐标，拟合直接得到真实校正。返回 { hh, n, med } 或 null
function bubbleFitAlign(hh0, bin, w, h) {
    var bs = TPL._bubbles, stride = Math.max(1, Math.ceil(bs.length / 80));
    var pmin = 1e18;
    for (var pi = 0; pi < bs.length; pi += stride) for (var pj = pi + 1; pj < bs.length; pj += stride) {
        var dxp = (bs[pi].x - bs[pj].x) * RK, dyp = (bs[pi].y - bs[pj].y) * RK, d2 = dxp * dxp + dyp * dyp;
        if (d2 > 0 && d2 < pmin) pmin = d2;
    }
    pmin = Math.sqrt(pmin);
    var R = Math.max(3, Math.min(14, Math.floor(pmin / 2) - 1));   // 必须小于半间距：杜绝相邻框错锁
    var rh = Math.max(4, Math.min(12, R));
    var SH = [[0, 0], [rh, 0], [-rh, 0], [0, rh], [0, -rh], [rh * .7, rh * .7], [-rh * .7, rh * .7], [rh * .7, -rh * .7], [-rh * .7, -rh * .7], [rh, rh], [-rh, rh], [rh, -rh], [-rh, -rh]];
    var hhCur = hh0, best = null;
    for (var iter = 0; iter < 2; iter++) {
        var rbin = warpBin(hhCur, bin, w, h);
        for (var si = 0; si < SH.length; si++) {
            var sx = SH[si][0], sy = SH[si][1];
            var corr = [], xs = [], ys = [];
            for (var i = 0; i < bs.length; i += stride) {
                var b = bs[i];
                var bw = Math.max(3, b.w * RK), bh = Math.max(3, b.h * RK);
                var cx = b.x * RK + bw / 2, cy = b.y * RK + bh / 2;
                var wx = cx + sx, wy = cy + sy;
                var x0 = Math.max(0, Math.round(wx - bw / 2 - R)), x1 = Math.min(RW - 1, Math.round(wx + bw / 2 + R));
                var y0 = Math.max(0, Math.round(wy - bh / 2 - R)), y1 = Math.min(RH - 1, Math.round(wy + bh / 2 + R));
                if (x1 - x0 < 4 || y1 - y0 < 4) continue;
                var ww = x1 - x0 + 1, wh = y1 - y0 + 1;
                var vis = new Uint8Array(ww * wh), stk = new Int32Array(ww * wh);
                var cands = [];
                for (var yy = y0; yy <= y1; yy++) for (var xx = x0; xx <= x1; xx++) {
                    var wi = (yy - y0) * ww + (xx - x0);
                    if (vis[wi] || !rbin[yy * RW + xx]) continue;
                    var head = 0, tail = 0;
                    stk[tail++] = wi; vis[wi] = 1;
                    var nbx0 = xx, nbx1 = xx, nby0 = yy, nby1 = yy, cnt = 0, touch = false;
                    while (head < tail) {
                        var cur = stk[head++], cyy = (cur / ww) | 0, cxx = cur - cyy * ww;
                        cnt++;
                        if (cxx < nbx0) nbx0 = cxx; if (cxx > nbx1) nbx1 = cxx;
                        if (cyy < nby0) nby0 = cyy; if (cyy > nby1) nby1 = cyy;
                        if (cxx === 0 || cyy === 0 || cxx === ww - 1 || cyy === wh - 1) touch = true;
                        var gx = cxx + x0, gy = cyy + y0;
                        if (cxx > 0 && !vis[cur - 1] && rbin[gy * RW + gx - 1]) { vis[cur - 1] = 1; stk[tail++] = cur - 1; }
                        if (cxx < ww - 1 && !vis[cur + 1] && rbin[gy * RW + gx + 1]) { vis[cur + 1] = 1; stk[tail++] = cur + 1; }
                        if (cyy > 0 && !vis[cur - ww] && rbin[(gy - 1) * RW + gx]) { vis[cur - ww] = 1; stk[tail++] = cur - ww; }
                        if (cyy < wh - 1 && !vis[cur + ww] && rbin[(gy + 1) * RW + gx]) { vis[cur + ww] = 1; stk[tail++] = cur + ww; }
                    }
                    if (touch || cnt < 5) continue;   // 触窗界（与邻框/长线交连）或过小：不可信
                    var dw = nbx1 - nbx0 + 1, dh = nby1 - nby0 + 1;
                    if (dw > bw * 2.6 || dh > bh * 3.2 || dw < bw * 0.35 || dh < bh * 0.3) continue;   // 尺寸不符
                    var pcx = x0 + (nbx0 + nbx1 + 1) / 2, pcy = y0 + (nby0 + nby1 + 1) / 2;
                    var dd = Math.sqrt((pcx - wx) * (pcx - wx) + (pcy - wy) * (pcy - wy));
                    if (dd > R) continue;
                    cands.push({ x: pcx, y: pcy, d: dd });
                }
                if (!cands.length) continue;
                cands.sort(function (a, b2) { return a.d - b2.d; });
                if (cands.length >= 2 && cands[1].d - cands[0].d < 2.5) continue;   // 两候选等距难分：弃
                corr.push([cx, cy, cands[0].x, cands[0].y]); xs.push(cx); ys.push(cy);
            }
            if (corr.length < 8) continue;
            var spx = Math.max.apply(null, xs) - Math.min.apply(null, xs);
            var spy = Math.max.apply(null, ys) - Math.min.apply(null, ys);
            if (spx < RW * 0.2 && spy < RH * 0.2) continue;   // 对应点过于集中：仿射解不稳定
            var fit = fitAffineRobust(corr);
            if (!fit) continue;
            if (!best || fit.n > best.n || (fit.n === best.n && fit.med < best.med))
                best = { hh: composeAffine(hhCur, fit.P), n: fit.n, med: fit.med };
        }
        if (best) hhCur = best.hh;   // 第 2 轮从更准的基底再收一次
    }
    return best;
}
// ===== 涂框整体配准（v5 终兜底·换方法）：不依赖定位标记/纸面边缘/粗矫正 =====
// 粗矫正错得离谱时（横向卡被硬转、纸边误判拉偏、阴影吞掉标记），以粗矫正为种子的网格吸附/
// 逐框拟合都无力回天。本方法直接在【原图】上配准：① 找出所有涂框尺寸级的矩形连通域（印刷框线
// /填涂块天然成像为独立小矩形）；② 按联合宽高直方图聚出主尺寸簇，用「簇宽高中位数 ÷ 模板框宽高
// 中位数」推算拍摄比例（正放/横放两口径）；③ 模板涂框中心点集按 4 朝向旋转缩放，与实测点集做
// RANSAC 点集配准（长基线点对定相似变换、距离窗检索配对、空间网格数内点）→ 最小二乘相似精修
// → 单应 DLT 吸收透视。方向（4 朝向）与比例全部由内点数自然胜出，涂框印刷可见即可对齐。
// 返回 { hh, rbin, ring, n, ri } 或 null
function bubbleRegAlign(comps, bin, w, h) {
    function omed(arr) { var a2 = arr.slice().sort(function (x, y) { return x - y; }); return a2[a2.length >> 1]; }
    function solve8(Am, bv) {
        var n = 8, A = [];
        for (var i = 0; i < n; i++) { A.push(Am[i].slice()); A[i].push(bv[i]); }
        for (var c = 0; c < n; c++) {
            var piv = c;
            for (var r = c + 1; r < n; r++) if (Math.abs(A[r][c]) > Math.abs(A[piv][c])) piv = r;
            if (Math.abs(A[piv][c]) < 1e-12) return null;
            var t = A[c]; A[c] = A[piv]; A[piv] = t;
            for (var r2 = c + 1; r2 < n; r2++) {
                var f = A[r2][c] / A[c][c];
                if (!f) continue;
                for (var k3 = c; k3 <= n; k3++) A[r2][k3] -= f * A[c][k3];
            }
        }
        var x = new Array(n);
        for (var r3 = n - 1; r3 >= 0; r3--) {
            var s2 = A[r3][n];
            for (var k4 = r3 + 1; k4 < n; k4++) s2 -= A[r3][k4] * x[k4];
            x[r3] = s2 / A[r3][r3];
        }
        return x;
    }
    function solve3(Min, bv) {
        var A = [];
        for (var i = 0; i < 3; i++) A.push([Min[i][0], Min[i][1], Min[i][2], bv[i]]);
        for (var c = 0; c < 3; c++) {
            var piv = c;
            for (var r = c + 1; r < 3; r++) if (Math.abs(A[r][c]) > Math.abs(A[piv][c])) piv = r;
            if (Math.abs(A[piv][c]) < 1e-12) return null;
            var t = A[c]; A[c] = A[piv]; A[piv] = t;
            for (var r2 = c + 1; r2 < 3; r2++) {
                var f = A[r2][c] / A[c][c];
                if (!f) continue;
                for (var k3 = c; k3 <= 3; k3++) A[r2][k3] -= f * A[c][k3];
            }
        }
        var x = new Array(3);
        for (var r3 = 2; r3 >= 0; r3--) {
            var s2 = A[r3][3];
            for (var k4 = r3 + 1; k4 < 3; k4++) s2 -= A[r3][k4] * x[k4];
            x[r3] = s2 / A[r3][r3];
        }
        return x;
    }
    var bs = TPL._bubbles, N = bs.length;
    if (N < 12 || !comps || comps.length < 15) return null;
    // 最小框间距（mm）：用于内点容差与最短基线
    var pminMm = 1e18;
    for (var pi = 0; pi < N; pi++) for (var pj = pi + 1; pj < N; pj++) {
        var dmm = Math.sqrt((bs[pi].x - bs[pj].x) * (bs[pi].x - bs[pj].x) + (bs[pi].y - bs[pj].y) * (bs[pi].y - bs[pj].y));
        if (dmm > 0 && dmm < pminMm) pminMm = dmm;
    }
    var sStride = Math.max(1, Math.ceil(N / 60));
    var PW = TPL.paper.w, PH = TPL.paper.h;
    var rotTab = [];   // 4 朝向的模板点（mm，取框中心）：0=正向 1=顺时针90° 2=180° 3=逆时针90°
    for (var k0 = 0; k0 < 4; k0++) {
        var rr0 = [];
        for (var bi0 = 0; bi0 < N; bi0++) {
            var b0 = bs[bi0], bx = b0.x + b0.w / 2, by = b0.y + b0.h / 2;
            rr0.push(k0 === 0 ? [bx, by] : k0 === 1 ? [PH - by, bx] : k0 === 2 ? [PW - bx, PH - by] : [by, PW - bx]);
        }
        rotTab.push(rr0);
    }
    // 模板点对（长基线优先，≤48 对）：距离在 4 朝向下不变，用 k=0 坐标算；长基线定旋转/平移更稳
    var tpairs = [];
    for (var tpi = 0; tpi < N; tpi += sStride) for (var tpj = tpi + 1; tpj < N; tpj += sStride) {
        var tdx = rotTab[0][tpi][0] - rotTab[0][tpj][0], tdy = rotTab[0][tpi][1] - rotTab[0][tpj][1];
        tpairs.push([tpi, tpj, Math.sqrt(tdx * tdx + tdy * tdy)]);
    }
    tpairs.sort(function (a, b) { return b[2] - a[2]; });
    if (tpairs.length > 48) tpairs.length = 48;
    // ① 矩形连通域筛选：非细长、非巨块、非稀疏碎片
    var rects = [];
    for (var i1 = 0; i1 < comps.length; i1++) {
        var c1 = comps[i1], bw1 = c1.x1 - c1.x0 + 1, bh1 = c1.y1 - c1.y0 + 1;
        if (bw1 < 5 || bh1 < 4 || bw1 > w * 0.25 || bh1 > h * 0.25) continue;
        if (bw1 > bh1 * 5 || bh1 > bw1 * 5) continue;
        if (c1.area < bw1 * bh1 * 0.18) continue;
        rects.push({ x: (c1.x0 + c1.x1 + 1) / 2, y: (c1.y0 + c1.y1 + 1) / 2, w: bw1, h: bh1 });
    }
    if (rects.length < 15) return null;
    // ② 联合宽高直方图聚类（2px 分辨率），取最多 3 个主簇
    var hist = {};
    for (var r1 = 0; r1 < rects.length; r1++) {
        var kh = Math.round(rects[r1].w / 2) + '_' + Math.round(rects[r1].h / 2);
        (hist[kh] = hist[kh] || []).push(rects[r1]);
    }
    var clusters = Object.keys(hist).map(function (k5) { return hist[k5]; })
        .filter(function (arr) { return arr.length >= 12; })
        .sort(function (a, b) { return b.length - a.length; }).slice(0, 3);
    if (!clusters.length) return null;
    var bestG = null;
    for (var ci = 0; ci < clusters.length && !(bestG && bestG.ring >= 0.62); ci++) {
        var cl = clusters[ci], M = cl.length;
        // 尺度换方法（墨宽无关）：组件外沿含印刷线宽，「宽高中位数 ÷ 模板宽高」会系统性偏大 10%+，
        // 距离窗随之错位、RANSAC 只命局部（实测：正放场景错位一格/整体失败）。改用最近邻节距
        // 中位数之比推尺度（周期网格的节距比，与墨宽无关），再乘 0.94/1.0/1.07 三档微调吸收
        // 中位噪声与透视伸缩；尺度与朝向无关，4 朝向共用同一组尺度
        var nnAll = [];
        for (var n1 = 0; n1 < M; n1++) {
            var bd1 = 1e18;
            for (var n2 = 0; n2 < M; n2++) {
                if (n2 === n1) continue;
                var ddx = cl[n1].x - cl[n2].x, ddy = cl[n1].y - cl[n2].y, dd2 = ddx * ddx + ddy * ddy;
                if (dd2 < bd1) bd1 = dd2;
            }
            if (bd1 < 1e18) nnAll.push(Math.sqrt(bd1));
        }
        var tnn = [];
        for (var n3 = 0; n3 < N; n3++) {
            var bd2 = 1e18;
            for (var n4 = 0; n4 < N; n4++) {
                if (n4 === n3) continue;
                var ddx2 = rotTab[0][n3][0] - rotTab[0][n4][0], ddy2 = rotTab[0][n3][1] - rotTab[0][n4][1];
                var dd3 = ddx2 * ddx2 + ddy2 * ddy2;
                if (dd3 < bd2) bd2 = dd3;
            }
            tnn.push(Math.sqrt(bd2));
        }
        var s0 = omed(nnAll) / omed(tnn);
        var dp = [];
        for (var di = 0; di < M; di++) for (var dj = di + 1; dj < M; dj++)
            dp.push([di, dj, Math.sqrt((cl[di].x - cl[dj].x) * (cl[di].x - cl[dj].x) + (cl[di].y - cl[dj].y) * (cl[di].y - cl[dj].y))]);
        dp.sort(function (a, b) { return a[2] - b[2]; });
        var cell = 16, gcols = Math.ceil(w / cell) + 2, grid = {};
        for (var g1 = 0; g1 < M; g1++) {
            var gk = Math.floor(cl[g1].x / cell) + Math.floor(cl[g1].y / cell) * gcols;
            (grid[gk] = grid[gk] || []).push(g1);
        }
        var nearIdx = function (x, y, rr) {
            var gx0 = Math.floor(x / cell), gy0 = Math.floor(y / cell), bix = -1, bd = rr * rr;
            for (var yy = gy0 - 1; yy <= gy0 + 1; yy++) for (var xx = gx0 - 1; xx <= gx0 + 1; xx++) {
                var arr = grid[xx + yy * gcols];
                if (!arr) continue;
                for (var z = 0; z < arr.length; z++) {
                    var qx = cl[arr[z]].x - x, qy = cl[arr[z]].y - y, qd = qx * qx + qy * qy;
                    if (qd <= bd) { bd = qd; bix = arr[z]; }
                }
            }
            return bix;
        };
        // 大网格版（cell=48，3×3 检索可达 ±96px）：粗矫正精修阶段要在「预测点周围一大片」
        // 找实测点——透视下相似解的边缘偏差可达 20-40px，紧网格 16px 半径根本够不着
        var cellB = 48, gcolsB = Math.ceil(w / cellB) + 2, gridB = {};
        for (var g7 = 0; g7 < M; g7++) {
            var gkB = Math.floor(cl[g7].x / cellB) + Math.floor(cl[g7].y / cellB) * gcolsB;
            (gridB[gkB] = gridB[gkB] || []).push(g7);
        }
        var nearIdxBig = function (x, y, rr) {
            var gx0 = Math.floor(x / cellB), gy0 = Math.floor(y / cellB), bix = -1, bd = rr * rr;
            for (var yy = gy0 - 1; yy <= gy0 + 1; yy++) for (var xx = gx0 - 1; xx <= gx0 + 1; xx++) {
                var arr = gridB[xx + yy * gcolsB];
                if (!arr) continue;
                for (var z = 0; z < arr.length; z++) {
                    var qx = cl[arr[z]].x - x, qy = cl[arr[z]].y - y, qd = qx * qx + qy * qy;
                    if (qd <= bd) { bd = qd; bix = arr[z]; }
                }
            }
            return bix;
        };
        // ③ RANSAC：模板点对 × 距离窗内实测点对 → 相似变换 → 内点计数
        //   涂框网格具周期性，「整体错位一格」的映射也能自洽命中部分区域——
        //   早退阈值取 N-3（而非 85%），确保几乎全配的正确解有机会胜出；
        //   预筛门槛 0.28 兼顾透视容差与伪配过滤，最终由 ringMatch 定胜负
        var runBest = null, fullN = N >= 20 ? N - 3 : N;
        for (var k = 0; k < 4 && !(runBest && runBest.tot >= fullN); k++) {
        for (var scv = 0, nSc = [0.94, 1, 1.07]; scv < 3 && !(runBest && runBest.tot >= fullN); scv++) {
            var s = s0 * nSc[scv];
            if (!(s > 0.02) || s > 60) continue;
            var rot = rotTab[k];
            var tolK = Math.max(4, Math.min(14, pminMm * s * 0.38));
            var need = Math.max(8, Math.round(N / sStride * 0.28));
            for (var t = 0; t < tpairs.length && !(runBest && runBest.tot >= fullN); t++) {
                var dT = tpairs[t][2] * s, lo = dT * 0.95 - 2, hi = dT * 1.05 + 2;
                var a0 = 0, b0 = dp.length;
                while (a0 < b0) { var mid = (a0 + b0) >> 1; if (dp[mid][2] < lo) a0 = mid + 1; else b0 = mid; }
                var wHi = a0;
                while (wHi < dp.length && dp[wHi][2] <= hi) wHi++;
                // 窗口内均匀抽样 ≤10 个（网格上等距点对极多，只取前几个必漏真实配对；任意一对配准成功即可定全局）
                var wn = wHi - a0, stp = wn > 10 ? Math.floor(wn / 10) : 1;
                var tried = 0;
                for (var d1 = a0; d1 < wHi && tried < 10; d1 += stp) {
                    for (var flip = 0; flip < 2; flip++) {
                        var i2 = tpairs[t][0], j2 = tpairs[t][1];
                        var q1 = flip ? dp[d1][1] : dp[d1][0], q2 = flip ? dp[d1][0] : dp[d1][1];
                        var tx1 = rot[i2][0] * s, ty1 = rot[i2][1] * s, tx2 = rot[j2][0] * s, ty2 = rot[j2][1] * s;
                        var dxt = tx1 - tx2, dyt = ty1 - ty2, den = dxt * dxt + dyt * dyt;
                        if (den < 1e-6) continue;
                        var dxd = cl[q1].x - cl[q2].x, dyd = cl[q1].y - cl[q2].y;
                        var ar = (dxd * dxt + dyd * dyt) / den, ai = (dyd * dxt - dxd * dyt) / den;
                        var br = cl[q1].x - ar * tx1 + ai * ty1, bi1 = cl[q1].y - ai * tx1 - ar * ty1;
                        var cnt = 0;
                        for (var si = 0; si < N; si += sStride) {
                            var zx = ar * rot[si][0] * s - ai * rot[si][1] * s + br;
                            var zy = ai * rot[si][0] * s + ar * rot[si][1] * s + bi1;
                            if (nearIdx(zx, zy, tolK) >= 0) cnt++;
                        }
                        if (cnt < need) continue;
                        var corr = [], tot = 0;
                        for (var fi = 0; fi < N; fi++) {
                            var zx2 = ar * rot[fi][0] * s - ai * rot[fi][1] * s + br;
                            var zy2 = ai * rot[fi][0] * s + ar * rot[fi][1] * s + bi1;
                            var ni = nearIdx(zx2, zy2, tolK);
                            if (ni >= 0) { tot++; corr.push([fi, ni]); }
                        }
                        if (!runBest || tot > runBest.tot)
                            runBest = { tot: tot, ar: ar, ai: ai, br: br, bi: bi1, k: k, s: s, tol: tolK, corr: corr };
                        if (tot >= fullN) break;
                    }
                }
            }
        }
        }
        if (!runBest || runBest.tot < Math.max(12, N * 0.3)) continue;
        // ④ 最小二乘相似精修（Procrustes，2 轮重采对应；新解明显变差则保持上一轮）
        var ar2 = runBest.ar, ai2 = runBest.ai, br2 = runBest.br, bi4 = runBest.bi;
        var rotF = rotTab[runBest.k], sF = runBest.s, tolF = runBest.tol, corr = runBest.corr;
        for (var it = 0; it < 2; it++) {
            var mx = 0, my = 0, nx = 0, ny = 0;
            for (var z1 = 0; z1 < corr.length; z1++) {
                mx += rotF[corr[z1][0]][0] * sF; my += rotF[corr[z1][0]][1] * sF;
                nx += cl[corr[z1][1]].x; ny += cl[corr[z1][1]].y;
            }
            mx /= corr.length; my /= corr.length; nx /= corr.length; ny /= corr.length;
            var dnm = 0, cr = 0, ci2 = 0;
            for (var z2 = 0; z2 < corr.length; z2++) {
                var txc = rotF[corr[z2][0]][0] * sF - mx, tyc = rotF[corr[z2][0]][1] * sF - my;
                var dxc = cl[corr[z2][1]].x - nx, dyc = cl[corr[z2][1]].y - ny;
                dnm += txc * txc + tyc * tyc;
                cr += dxc * txc + dyc * tyc;
                ci2 += dyc * txc - dxc * tyc;
            }
            if (dnm < 1e-9) break;
            var ar3 = cr / dnm, ai3 = ci2 / dnm;
            var br3 = nx - ar3 * mx + ai3 * my, bi5 = ny - ai3 * mx - ar3 * my;
            var ncorr = [];
            for (var z3 = 0; z3 < N; z3++) {
                var zx3 = ar3 * rotF[z3][0] * sF - ai3 * rotF[z3][1] * sF + br3;
                var zy3 = ai3 * rotF[z3][0] * sF + ar3 * rotF[z3][1] * sF + bi5;
                var ni3 = nearIdx(zx3, zy3, tolF);
                if (ni3 >= 0) ncorr.push([z3, ni3]);
            }
            if (ncorr.length < 6 || ncorr.length < corr.length * 0.7) break;
            ar2 = ar3; ai2 = ai3; br2 = br3; bi4 = bi5; corr = ncorr;
        }
        // ⑤ 单应 DLT（canvas px → photo px）吸收透视：相似解只作初值——透视下相似内点只聚在
        //   种子附近，窄窗 DLT 必病态。迭代「采点 → DLT → 全局重采」3 轮：首轮宽容差把跨纸面
        //   的边缘点也收进来（上限 15px，受 nearIdx 网格半径约束），按全局内点数择优；
        //   病态解（纸面内分母过零/系数非有限）直接弃用，保底回退相似变换
        var A = ar2 * sF / RK, B = ai2 * sF / RK, kk = runBest.k, simHH;
        if (kk === 0) simHH = [A, -B, br2, B, A, bi4, 0, 0];
        else if (kk === 1) simHH = [-B, -A, A * RH + br2, B, -A, B * RH + bi4, 0, 0];
        else if (kk === 2) simHH = [-A, B, A * RW - B * RH + br2, -B, -A, B * RW + A * RH + bi4, 0, 0];
        else simHH = [B, A, br2 - B * RW, -A, B, A * RW + bi4, 0, 0];
        var regDlt = function (corrIn) {
            if (corrIn.length < 6) return null;
            var Am = [], bv = [];
            for (var z4 = 0; z4 < corrIn.length; z4++) {
                var Xc = (bs[corrIn[z4][0]].x + bs[corrIn[z4][0]].w / 2) * RK, Yc = (bs[corrIn[z4][0]].y + bs[corrIn[z4][0]].h / 2) * RK;
                var u = cl[corrIn[z4][1]].x, v = cl[corrIn[z4][1]].y;
                Am.push([Xc, Yc, 1, 0, 0, 0, -u * Xc, -u * Yc]); bv.push(u);
                Am.push([0, 0, 0, Xc, Yc, 1, -v * Xc, -v * Yc]); bv.push(v);
            }
            var h8 = solve8(Am, bv);
            if (!h8) return null;
            for (var f2 = 0; f2 < 8; f2++) if (!isFinite(h8[f2])) return null;
            var corn = [[0, 0], [RW, 0], [RW, RH], [0, RH], [RW / 2, RH / 2]];
            for (var c9 = 0; c9 < corn.length; c9++) {
                var d9 = h8[6] * corn[c9][0] + h8[7] * corn[c9][1] + 1;
                if (!isFinite(d9) || Math.abs(d9) < 0.2) return null;
            }
            return h8;
        };
        var regResample = function (h8, tol) {
            var c8 = [];
            for (var f4 = 0; f4 < N; f4++) {
                var bcx2 = (bs[f4].x + bs[f4].w / 2) * RK, bcy2 = (bs[f4].y + bs[f4].h / 2) * RK;
                var den4 = h8[6] * bcx2 + h8[7] * bcy2 + 1;
                if (Math.abs(den4) < 1e-9) continue;
                var sx4 = (h8[0] * bcx2 + h8[1] * bcy2 + h8[2]) / den4;
                var sy4 = (h8[3] * bcx2 + h8[4] * bcy2 + h8[5]) / den4;
                if (!isFinite(sx4) || !isFinite(sy4)) continue;
                var ni4 = nearIdxBig(sx4, sy4, tol);
                if (ni4 >= 0) c8.push([f4, ni4]);
            }
            return c8;
        };
        // ④.5 仿射最小二乘精修：相似变换吸收不了梯形透视的一阶部分（差分缩放/剪切），仿射可。
        //   以相似对应点为初值解仿射 LSQ（6 参数正规方程），宽窗重采迭代 2 轮——为 DLT 提供
        //   覆盖整卡的收敛初值（相似解的内点常只聚在种子附近，直接 DLT 无跨纸面点、必病态）
        var affHH = null, cA = corr;
        for (var itA = 0; itA < 2; itA++) {
            if (cA.length < 6) break;
            var Sx = 0, Sy = 0, Sxx = 0, Syy = 0, Sxy = 0, Su = 0, Sv = 0, Sxu = 0, Sxv = 0, Syu = 0, Syv = 0;
            for (var za = 0; za < cA.length; za++) {
                var Xa = (bs[cA[za][0]].x + bs[cA[za][0]].w / 2) * RK, Ya = (bs[cA[za][0]].y + bs[cA[za][0]].h / 2) * RK;
                var ua = cl[cA[za][1]].x, va = cl[cA[za][1]].y;
                Sx += Xa; Sy += Ya; Sxx += Xa * Xa; Syy += Ya * Ya; Sxy += Xa * Ya;
                Su += ua; Sv += va; Sxu += Xa * ua; Sxv += Xa * va; Syu += Ya * ua; Syv += Ya * va;
            }
            var Maf = [[Sxx, Sxy, Sx], [Sxy, Syy, Sy], [Sx, Sy, cA.length]];
            var afu = solve3(Maf, [Sxu, Syu, Su]), afv = solve3(Maf, [Sxv, Syv, Sv]);
            if (!afu || !afv) break;
            var hhA = [afu[0], afu[1], afu[2], afv[0], afv[1], afv[2], 0, 0];
            var cN = regResample(hhA, Math.min(15, tolF * 1.6));
            if (!affHH || cN.length >= cA.length) affHH = hhA;
            if (cN.length < 6 || cN.length < cA.length * 0.7) break;
            cA = cN;
        }
        var hh = simHH, hhTot = corr.length;
        if (affHH) {
            var cA0 = regResample(affHH, tolF);
            if (cA0.length > hhTot) { hhTot = cA0.length; hh = affHH; }
        }
        var hPrev = hh;
        for (var itD = 0; itD < 3; itD++) {
            // 首轮大半径（36px，大网格检索）：把透视下偏离 20-40px 的边缘点也收进来
            var cD = regResample(hPrev, itD === 0 ? 36 : tolF);
            if (cD.length < 8) break;
            var hD = regDlt(cD);
            if (!hD) break;
            var cV = regResample(hD, tolF);
            if (cV.length > hhTot) { hhTot = cV.length; hh = hD; }
            if (cV.length >= fullN) break;
            hPrev = hD;
        }
        var rbinG = warpBin(hh, bin, w, h);
        if (!rbinG) continue;
        var ringG = ringMatch(rbinG);
        if (!bestG || ringG > bestG.ring) {
            // 相似变换的连续旋转角会吸收 k 朝向假设，方向旗标改由「拟合角 + k×90°」总旋转取整
            var thd = Math.atan2(ai2, ar2) * 180 / Math.PI + runBest.k * 90;
            var riG = ((Math.round(thd / 90) % 4) + 4) % 4;
            bestG = { hh: hh, rbin: rbinG, ring: ringG, n: hhTot, ri: riG, tot: runBest.tot };
        }
    }
    return bestG;
}
function recognize(srcCanvas) {
    if (!TPL) { toast('请先选择答题卡模板'); return; }
    var t0 = Date.now();
    // 0) 矫正图基准：约 1000px 宽
    RK = 1000 / TPL.paper.w;
    RW = Math.round(TPL.paper.w * RK); RH = Math.round(TPL.paper.h * RK);
    // 1) 缩放到工作尺寸（宽 ≤2048；上限过低会把远距小卡抹掉涂痕细节——实拍中卡片常只占画面 1/3）
    var W = srcCanvas.width, H = srcCanvas.height;
    var scale = Math.min(1, 2048 / W);
    var w = Math.max(64, Math.round(W * scale)), h = Math.max(64, Math.round(H * scale));
    var wc = document.createElement('canvas'); wc.width = w; wc.height = h;
    var wctx = wc.getContext('2d');
    wctx.drawImage(srcCanvas, 0, 0, w, h);
    var data = wctx.getImageData(0, 0, w, h).data;
    // 2) 灰度
    var gray = new Uint8Array(w * h);
    for (var i = 0, p4 = 0; i < w * h; i++, p4 += 4)
        gray[i] = (data[p4] * 299 + data[p4 + 1] * 587 + data[p4 + 2] * 114) / 1000;
    // 3) 自适应二值化 + 连通域 + 分象限定位标记候选
    GRAY = gray;
    var bin = binarize(gray, w, h);
    var compsAll = findComponents(bin, w, h);
    var byQ = findMarks(compsAll, w, h);
    // 标记漏检兜底②：阴影/反光下纸边会连成细长暗线，与角落标记挤占「孤立区」或连通，致标记误杀——
    // 对二值图做 2 次腐蚀剔除细线后重检，把消失的象限候选找回来（实心方块仅轻微缩小，中心不变）。
    // 任一象限空即触发；页循环内 scanPage 各自按页组装候选
    var att0 = buildQuadAttempts(byQ, w, h);
    // 触发条件=任一象限空（此前仅「纯边缘兜底」才触发，实测 204338 检出 3 象限候选（含背景杂物）
    // 组合出多种尝试 → 条件不成立，右上真标记明明腐蚀可找回却被跳过，最终 ring 0.467 拒识）
    var anyQEmpty = false;
    for (var qiE0 = 0; qiE0 < 4; qiE0++) if (!byQ[qiE0].length) anyQEmpty = true;
    if (anyQEmpty) {
        var byQ2 = findMarks(findComponents(erodeBin(bin, w, h, 2), w, h), w, h);
        var recovered = false;
        for (var qiE = 0; qiE < 4; qiE++) {
            if (!byQ[qiE].length && byQ2[qiE].length) { byQ[qiE] = byQ2[qiE]; recovered = true; }
        }
        if (recovered) {
            var att02 = buildQuadAttempts(byQ, w, h);
            if (!(att02.length === 1 && att02[0].edge)) att0 = att02;
        }
    }
    // 4) 逐候选四边形 × 4 朝向评分取最优：印刷框对齐为主、填涂占比为辅
    //    标记中心必须映回其在纸面上的已知位置（取景框角，随内容收紧；旧模板=纸角）
    //    边缘兜底不再把裁剪区强行拉满整张纸面：按裁剪区「上边/左边实际长度比」换算目标高度、贴左上放置——
    //    横向纸条（如裁掉下部空白的答题条）保持横向比例参与评分，杜绝被硬转 90° 凑纵横比；
    //    同时提供「取景框锚点」变体（按标记位置裁剪/裁掉纸边边缘时内容对齐取景框），两锚点都参与评分
    //    多页模板：逐页尝试——每页各自取景框/涂框/页头点数（第 N 页 N+1 枚）。页头点全中 + 印刷框
    //    对齐 + 横纵比佐证 → 该页早停胜出；未早停时按页头点投票校正页码后重评该页
    var srcPaper = paperBBox(gray, w, h);
    var srcEdge = srcPaper || [{ x: 1, y: 1 }, { x: w - 2, y: 1 }, { x: w - 2, y: h - 2 }, { x: 1, y: h - 2 }];
    function pageDst() {
        var Fp = TPL.frame;
        return [{ x: Fp.x * RK, y: Fp.y * RK }, { x: (Fp.x + Fp.w) * RK, y: Fp.y * RK },
                { x: (Fp.x + Fp.w) * RK, y: (Fp.y + Fp.h) * RK }, { x: Fp.x * RK, y: (Fp.y + Fp.h) * RK }];
    }
    function scanPage(pgT) {
        setActivePage(pgT);
        var dstMark = pageDst();
        var attempts = buildQuadAttempts(byQ, w, h);
        if (!attempts.some(function (a) { return a.edge; }))
            attempts.push({ edge: true, paper: true, flags: ['定位标记未检全/不可靠，已按纸面边缘矫正（可能不准，建议正对重拍）'] });
        var kAll = CURPG + 2;   // 当前页应有页头点枚数
        var lbest = null, ltops = [], early = false;
        outer:
        for (var ai = 0; ai < attempts.length; ai++) {
            var at = attempts[ai];
            var src4 = at.edge ? srcEdge : at.pts;
            for (var ri = 0; ri < 8; ri++) {
                // ri 0-3 旋转 / ri 4-7 反射（镜像画面假设）。旋转序已有较优解（ring≥0.55）时跳过镜像——
                // 正常卡镜像假设必然低分（内容翻不回来对不齐印刷框），零开销且防误入反射伪解
                var mir = ri >= 4;
                if (mir && lbest && lbest.ring >= 0.55) break;
                var m = mir ? MIR_MAP[ri - 4] : ROT_MAP[ri];
                var sq = [src4[m[0]], src4[m[1]], src4[m[2]], src4[m[3]]];
                if (at.edge) {
                    var eTL = src4[m[0]], eTR = src4[m[1]], eBL = src4[m[3]];
                    var eW = Math.sqrt((eTR.x - eTL.x) * (eTR.x - eTL.x) + (eTR.y - eTL.y) * (eTR.y - eTL.y));
                    var eH = Math.sqrt((eBL.x - eTL.x) * (eBL.x - eTL.x) + (eBL.y - eTL.y) * (eBL.y - eTL.y));
                    var dH = (eW > 1) ? (eH / eW) * RW : RH;   // 贴左上、适配宽度；可超出画布（识别采样自动裁掉）
                    var dW = RW;
                    var anchors = [[0, 0], [TPL.frame.x * RK, TPL.frame.y * RK]];   // 纸面锚点 / 取景框锚点
                    for (var anc = 0; anc < anchors.length; anc++) {
                        var ax = anchors[anc][0], ay = anchors[anc][1];
                        var dr4 = [{ x: ax, y: ay }, { x: ax + dW, y: ay }, { x: ax + dW, y: ay + dH }, { x: ax, y: ay + dH }];
                        var hh = homography(dr4, sq);
                        if (!hh) continue;
                        var rbin0 = warpBin(hh, bin, w, h);
                        if (!rbin0) continue;
                        var ratios0 = computeRatios(rbin0);
                        var ring0 = ringMatch(rbin0);
                        var fill0 = orientScore(ratios0) / Math.max(1, TPL._bubbles.length);
                        var hm0 = hmarkHit(rbin0);
                        var sc0 = ring0 * 2 + fill0 * 0.6 + hm0 * 1.2 + orientConc(ratios0, TPL._bubbles.length) * 0.3
                                + sideInkAsym(rbin0) * 0.35;
                        ltops.push({ sc: sc0, ri: ri, sq: sq, dr: dr4, at: at, pg: pgT });
                        if (!lbest || sc0 > lbest.sc) lbest = { sc: sc0, ring: ring0, ri: ri, hh: hh, rbin: rbin0, ratios: ratios0, at: at, pg: pgT };
                        if (ring0 >= 0.62 && hm0 >= kAll && TPL.hmarks) break outer;   // 印刷框对齐且页头点全中（方向已被几何锁定）：无需再试
                    }
                    continue;
                }
                // 候选四边形面积下限（鞋带公式）：整页答题卡拍照必然占画面大头，
                // 小于画面 10% 的四边形必是背景杂物/卡内结构被误认成定位标记（实测红框缩在
                // 左上角的假解：假标记组合的小四边形粗评分反而压过真解，精调也救不回）
                var qA = 0;
                for (var qai = 0; qai < 4; qai++) {
                    var q1 = sq[qai], q2 = sq[(qai + 1) & 3];
                    qA += q1.x * q2.y - q2.x * q1.y;
                }
                if (Math.abs(qA) * 0.5 < w * h * 0.10) continue;
                var hh2 = homography(dstMark, sq);
                if (!hh2) continue;
                var rbin = warpBin(hh2, bin, w, h);
                if (!rbin) continue;
                var ratios = computeRatios(rbin);
                var ring = ringMatch(rbin);
                var fill = orientScore(ratios) / Math.max(1, TPL._bubbles.length);
                var hm = hmarkHit(rbin);
                var asp = aspectAgree(sq);   // 横纵比一致度：按设计模板比例直接约束旋转方向
                var sc = ring * 2 + fill * 0.6 + hm * 1.2 + asp * 0.4 + orientConc(ratios, TPL._bubbles.length) * 0.3
                       + sideInkAsym(rbin) * 0.35;   // 侧栏文字不对称：印刷文字左缘排布，180° 倒卡时符号反转
                ltops.push({ sc: sc, ri: ri, sq: sq, dr: dstMark, at: at, pg: pgT });
                if (!lbest || sc > lbest.sc) lbest = { sc: sc, ring: ring, ri: ri, sq: sq, hh: hh2, rbin: rbin, ratios: ratios, at: at, pg: pgT };
                if (ring >= 0.62 && (!TPL.hmarks || hm >= kAll) && asp >= 0.5) { early = true; break outer; }   // 框线对齐 + 页头点全中/横纵比佐证方向：提前胜出
            }
        }
        return { best: lbest, tops: ltops, early: early };
    }
    function betterRes(a, b) {   // b 是否优于 a：早停优先，其次综合分
        if (!a || !a.best) return !!(b && b.best);
        if (!b || !b.best) return false;
        if (b.early !== a.early) return b.early;
        return b.best.sc > a.best.sc;
    }
    var res = scanPage(0);
    if (TPL.pages > 1 && TPL.hmarks && res.best) {
        var vp = hmarkVote(res.best.rbin);   // 页码投票：取景框上边带点计数 − 2（两版式通用，见 hmarkVote 注）
        if (vp !== res.best.pg) {
            var res2 = scanPage(vp);
            if (betterRes(res, res2)) res = res2;
        }
        if (!res.early) {   // 兜底：投票基于错页矫正图，计数/位置均可能失真漏投——未早停时逐页补扫，
            //            正确页会以「页头点全中 + 框对齐」早停胜出（页数 ≤5，代价可控）
            for (var rp = 0; rp < TPL.pages; rp++) {
                if (rp === res.best.pg) continue;
                var res3 = scanPage(rp);
                if (betterRes(res, res3)) { res = res3; if (res.early) break; }
            }
        }
    }
    var best = res.best, tops = res.tops;   // tops：记录各候选的粗评分与几何（目标矩形/源四角），供精调阶段复用
    if (!best) { toast('矫正失败：定位标记异常，请调整拍摄角度重试'); return; }
    setActivePage(best.pg);   // 后续精调/评分/提取全部在该页几何上进行
    var F = TPL.frame, dstMark = pageDst(), ringPts = ringSamplePts();
    // 诊断钩子（默认关闭）：控制台设 window.__OMR_DBG = {} 后识别，可查看标记候选与入选四边形详情
    if (typeof window !== 'undefined' && window.__OMR_DBG) window.__OMR_DBG.pick = {
        w: w, h: h,
        byQ: byQ.map(function (l) { return l.slice(0, 8).map(function (e) { var c = e.c; return [c.x1 - c.x0 + 1, c.y1 - c.y0 + 1, Math.round((c.x0 + c.x1) / 2), Math.round((c.y0 + c.y1) / 2)]; }); }),
        atFlags: best.at.flags || [], ring: best.ring, ri: best.ri, sc: Math.round(best.sc * 1000) / 1000,
        sq: (function () { if (best.sq) return best.sq; var s4 = srcEdge, m2 = best.ri >= 4 ? MIR_MAP[best.ri - 4] : ROT_MAP[best.ri]; return [s4[m2[0]], s4[m2[1]], s4[m2[2]], s4[m2[3]]]; })().map(function (p) { return [Math.round(p.x), Math.round(p.y)]; })
    };
    // 4.4) 涂框整体配准（v5 终兜底·换方法）：粗矫正（标记/纸边）对齐度不达标时，彻底换一条路——
    //   不经粗矫正、直接在原图上检测涂框矩形 → 与模板点集 RANSAC 配准 + 单应精修（4 朝向自动定，
    //   比例由尺寸簇推出）。横向卡被误转、纸边拉偏、阴影吞标记等「粗矫正已坏、精调无力」的场景由此自救
    var regC = null;
    if (best.ring < 0.88) {   // 原 0.62：真四边形初矫正 ring 常 0.70~0.75（标记 bbox 中心受透视/阴影偏置），却恰好越过旧门槛跳过配准、坏种子精调到 0.73 收不上来（实测 205039）
        try { regC = bubbleRegAlign(compsAll, bin, w, h); } catch (eR) { regC = null; }
        var regSolid = regC && regC.ring >= 0.45 && regC.n >= Math.max(12, Math.round(TPL._bubbles.length * 0.3));
        // 配准解采纳：综合分（印刷框对齐 + 填涂集中度）超过当前最优才替换——
        // 配准的 4 朝向是独立枚举的，网格对称时 ring 分不出成对方向，须靠集中度把关。
        // 与当前最优只比「共同项」（ring×2 + 集中度 + 侧栏不对称）：best.sc 另含页头点/横纵比/填涂
        // 三项而配准分没有——直接比总分时，杂四边形靠页头点窗口落暗背景白捡 hm×1.2（实测 204414
        // ring 0.33 垃圾四边形 sc 4.45 压过配准 ~3.5，配准被排除后卡死在坏种子上座号全错）
        if (regSolid) {
            var regRatios = computeRatios(regC.rbin);
            var regSc = regC.ring * 2 + orientConc(regRatios, TPL._bubbles.length) * 0.3 + sideInkAsym(regC.rbin) * 0.35;
            var bestCommon = best.ring * 2 + orientConc(best.ratios, TPL._bubbles.length) * 0.3 + sideInkAsym(best.rbin) * 0.35;
            if (regSc > bestCommon) {
                best = { sc: regSc, ring: regC.ring, ri: regC.ri, hh: regC.hh, rbin: regC.rbin, ratios: regRatios,
                         at: { flags: ['定位标记未检全，已按涂框整体配准对齐（' + regC.n + ' 点）'] } };
            }
        }
    }
    // 4.5) 印刷框网格吸附精调（根治批注错位/裁切不正）：粗矫正（标记/纸边）只保证大致摆正，
    //   这里对粗评分前 2 名候选做五参数（平移/旋转/横纵缩放）坐标下降，把目标矩形吸附到涂框
    //   印刷线上；精调后印刷框对齐度（ringMatch）更高才采用。标记漏检、纸边误判、透视残差
    //   造成的整体偏移在此被一次性修正，批改标注与取景框裁切随之对齐
    tops.sort(function (a, b) { return b.sc - a.sc; });
    if (typeof window !== 'undefined' && window.__OMR_DBG) window.__OMR_DBG.tops = tops.slice(0, 16).map(function (t) { return [Math.round(t.sc * 1000) / 1000, t.ri, t.at.edge ? 1 : 0, (t.at.flags || []).join('|')]; });
    var rfBest = null;
    for (var ti = 0; ti < Math.min(4, tops.length); ti++) {
        var rf = refineDst(tops[ti].sq, bin, w, h, ringPts, tops[ti].dr);
        if (rf && (!rfBest || rf.sc > rfBest.sc)) rfBest = rf;
    }
    var hhF = best.hh, rbinF = best.rbin, ratiosF = best.ratios, ringF = best.ring, tuned = false, alignedN = 0;
    if (rfBest) {
        var rbin2 = warpBin(rfBest.hh, bin, w, h);
        var ring2 = ringMatch(rbin2);
        if (ring2 > ringF) { hhF = rfBest.hh; rbinF = rbin2; ratiosF = computeRatios(rbin2); ringF = ring2; tuned = true; }
    }
    // 4.6) 涂框特征点对齐（v4 换方法）：粗矫正图上逐框检测印刷边框实测位置，鲁棒仿射拟合校正。
    //   不依赖标记/纸边、无「整体五参数」假设——标记漏检、纸边误判、比例失真、透视残差一并修正。
    //   从粗矫正与精调两个种子分别跑，对应点更多者参与终选；印刷框对齐度更高（或特征充分且不明显
    //   更差）时采用
    var bfBest = null, bfSeeds = [best.hh];
    if (rfBest) bfSeeds.push(rfBest.hh);
    if (regC && best.hh !== regC.hh) bfSeeds.push(regC.hh);   // 配准解即使未胜出也可作拟合种子
    for (var bi2 = 0; bi2 < bfSeeds.length; bi2++) {
        var bf = bubbleFitAlign(bfSeeds[bi2], bin, w, h);
        if (bf && (!bfBest || bf.n > bfBest.n)) bfBest = bf;
    }
    if (bfBest) {
        var rbinB = warpBin(bfBest.hh, bin, w, h);
        var ringB = ringMatch(rbinB);
        var bfSolid = bfBest.n >= Math.max(10, Math.round(TPL._bubbles.length * 0.2)) && bfBest.med <= 2.0;
        if (ringB > ringF || (bfSolid && ringB > ringF - 0.06)) {
            hhF = bfBest.hh; rbinF = rbinB; ratiosF = computeRatios(rbinB); ringF = ringB; tuned = true; alignedN = bfBest.n;
        }
    }
    // 4.65) 精调↔拟合交替收敛：以当前矫正为种子「五参数精调 ↔ 逐框仿射拟合」接力，ring 提升才采用，
    //   至多 2 轮。粗矫正偏差较大时单向单次链不够：精调先把目标吸到涂框附近，逐框拟合的对应点搜索窗
    //   （±14px）才能锁定；拟合修正剪切/局部失真后再精调可继续压残差——实测 905_8 单向链后右缘仍有
    //   ~3mm 局部偏差（末端题次漏检），交替后收敛
    for (var round = 0; round < 2; round++) {
        var improved = false;
        var inv = mat3Inv(hhF.length === 8 ? hhF.concat([1]) : hhF);   // 单应数组是 8 元素，须补齐成 3×3（曾传 8 元素致 NaN 垃圾矩阵、交替收敛静默失效）
        if (inv) {
            var sqNow = dstMark.map(function (p) {
                var den = inv[6] * p.x + inv[7] * p.y + 1;
                return { x: (inv[0] * p.x + inv[1] * p.y + inv[2]) / den, y: (inv[3] * p.x + inv[4] * p.y + inv[5]) / den };
            });
            var rf2 = refineDst(sqNow, bin, w, h, ringPts, dstMark);
            if (rf2) {
                var rb2 = warpBin(rf2.hh, bin, w, h);
                var rg2 = ringMatch(rb2);
                if (rg2 > ringF + 0.005) { hhF = rf2.hh; rbinF = rb2; ratiosF = computeRatios(rb2); ringF = rg2; tuned = true; improved = true; }
            }
        }
        var bf2 = bubbleFitAlign(hhF, bin, w, h);
        if (bf2) {
            var rb3 = warpBin(bf2.hh, bin, w, h);
            var rg3 = ringMatch(rb3);
            var solid2 = bf2.n >= Math.max(10, Math.round(TPL._bubbles.length * 0.2)) && bf2.med <= 2.0;
            if (rg3 > ringF + 0.005 || (solid2 && rg3 > ringF - 0.06)) {
                hhF = bf2.hh; rbinF = rb3; ratiosF = computeRatios(rb3); ringF = rg3; tuned = true; alignedN = bf2.n; improved = true;
            }
        }
        if (!improved) break;
    }
    // 4.7) 方向内容校验（安全网）：以上各阶段评分已含填涂集中度/侧栏不对称，但精调/拟合只看 ring 可能
    //   残留「成对方向」错误——对最终矫正图与「再转 180°」各算一次集中度与侧栏不对称度，
    //   集中度明显更高、或不对称度符号明确反转（印刷文字在错误一侧）才翻转
    //   （保守阈值：空卡/废片两边都低不翻转；本来正确的识别集中度差距大也不会被翻错。
    //     侧栏不对称依据的是印刷文字而非学生填涂，空卡也能用，故与集中度判据独立生效）
    var orientFix = false;
    (function () {
        var cCur = orientConc(ratiosF, TPL._bubbles.length);
        var aCur = sideInkAsym(rbinF);
        if (cCur < 0.25 && Math.abs(aCur) < 0.18) return;   // 无墨迹且文字对称：没有方向依据，维持现状
        var fcx = (F.x + F.w / 2) * RK, fcy = (F.y + F.h / 2) * RK;
        var hhFlip = mat3Mul(hhF.length === 8 ? hhF.concat([1]) : hhF, [-1, 0, 2 * fcx, 0, -1, 2 * fcy, 0, 0, 1]);   // 单应数组 8 元素须补齐成 3×3，否则第 3 行乘出 NaN、翻转安全网静默失效
        var rbinFlip = warpBin(hhFlip, bin, w, h);
        if (!rbinFlip) return;
        var ratiosFlip = computeRatios(rbinFlip);
        var cFlip = orientConc(ratiosFlip, TPL._bubbles.length);
        var aFlip = sideInkAsym(rbinFlip);
        // 双证据 AND 才翻转：浓度判据在低对齐度卡上会被采样运气支配（实测 190650 正立视图深涂块
        // 落在框间被摊薄 max 0.43，翻转后干扰物恰好压中某框凑出 ratio 1.0 → 浓度反超 2 倍误翻），
        // 必须叠加「印刷文字不对称度明确反转」（文字墨迹与填涂无关，倒置必然左右反转）才成立
        if (cCur >= 0.25 && cFlip > cCur * 1.25 + 0.05 && Math.abs(aCur) >= 0.18 && aFlip > aCur + 0.3) {
            hhF = hhFlip; rbinF = rbinFlip; ratiosF = ratiosFlip; orientFix = true;
        }
    })();
    // 4.8) 真卡验收（拒绝非答题卡照片）：印刷框对齐度是「像不像这张卡」的硬指标；
    //   阈值 0.62 与精调伪峰上限（<0.6）衔接——真卡精调后 ring≥0.9，杂物/文件照精调只能停在伪峰；
    //   新模板（页头点强制）还须当前页页头定位点全中（第 N 页 N+1 枚）——各页位置集互不嵌套，
    //   杂照几乎不可能在模板指定位置恰好印有全部 5mm 实心黑方块。老模板（无页头点）退化为仅对齐度验收
    var finHm = TPL.hmarks ? hmarkHitTol(rbinF) : 0;
    // 4.78) 终矫正图页码复判（跨页识别基础）：上式按「当前页预期位置」逐点匹配，依赖模板点位精度，
    //   印刷偏位/裁切/遮挡时可能全灭（实测 0/2）→ 页码无从判定；而页头点「计数」只依赖顶边带内
    //   有几个黑块（hmarkVote），与精确位置无关、各页共用锚点恒成立。矫正/裁剪定稿后在终图上再判
    //   一次：点数−2=实际页码——①当前页位置全中 → 页码即当前页无需复判；②计数>当前页 → 后页卡
    //   误入（错题次必拒）；③计数<当前页 → 前页卡或当前页点被遮挡（计数只会少不会多），仅提示不否决
    var pgDet = -1, pgMismatch = false;
    if (TPL.hmarks && TPL.pages > 1) {
        pgDet = finHm >= CURPG + 2 ? CURPG : hmarkVote(rbinF);
        pgMismatch = pgDet >= 0 && pgDet !== CURPG;
    }
    // 页头点缺省兜底：页头点不全（旧版印刷卡无页头点 / 裁切切掉 / 手指遮挡 / 大透视）但「这是本模板卡」
    // 的证据足够 → 带旗标验收。证据二选一：① 印刷框对齐度 ≥0.65（真卡精调后普遍 ≥0.9，杂照/文件照
    // 伪峰 <0.6）；② 对齐度 0.62~0.65 且涂框结构配准命中足够多（逐框拟合/整体配准 ≥20% 涂框数）——
    // 相机实拍常因标记漏检走配准路径，透视残差压不住对齐度，但网格结构匹配本身就是硬证据
    var structN = Math.max(alignedN, regC ? regC.n : 0);
    var structSolid = structN >= Math.max(10, Math.round(TPL._bubbles.length * 0.2));
    // hmShort 放宽：涂框整体配准扎实（如实测 18 点命中）本身就是「这是本模板卡」的铁证——
    // 杂照/文件照不可能让 18 个涂框网格对上模板；页头点印刷缺失/遮挡/被裁时不应一票否决
    var hmShort = TPL.hmarks && finHm < CURPG + 2 && (ringF >= 0.65 || (ringF >= 0.55 && structSolid));
    // 放行判定：①主线 ring≥0.62；②结构兜底 ring≥0.52 且涂框网格配准扎实（structSolid=逐框拟合/整体
    //   配准命中 ≥20% 涂框数）——大透视/局部阴影实拍的真卡粗矫正残差压不住对齐度（实测 0.47~0.50 被拒），
    //   但「半数以上涂框网格能对上模板」是杂物/文件照伪峰给不出的硬证据；页头点不全仍须 hmShort 佐证
    var omrPass = ringF >= 0.62 || (ringF >= 0.52 && structSolid);
    if (TPL.hmarks && finHm < CURPG + 2 && !hmShort) omrPass = false;
    // 跨页必拒（题次=页码硬约束）：卡上页头点比当前页应有多——后页卡误入当前题次，内容完全不同，
    //   放行会把第 X 页的答案登记到第 Y 页的题上；遮挡只会让计数变少不会变多，多出即为硬证据
    if (pgMismatch && pgDet > CURPG) omrPass = false;
    if (!omrPass) {
        if (typeof window !== 'undefined' && window.__OMR_DBG) window.__OMR_DBG.reject = { ringF: Math.round(ringF * 1000) / 1000, finHm: finHm, need: CURPG + 2, pgDet: pgDet };
        // 拒绝文案分级：跨页复判有结论时直接告诉老师「这是第几页的卡」——比笼统的「页头点未命中」
        // 更可执行（切题次/重拍一举两得）；整卡几何完全没对上（页头 0 中 + 对齐度极低）最常见原因是
        // 只拍了半张卡——页头清晰不等于整卡完整，矫正需要取景框四角（含底部定位方块）全部在画面内
        var rejMsg;
        if (pgMismatch && pgDet > CURPG) {
            rejMsg = '检测到第 ' + (pgDet + 1) + ' 页的答题卡（页头定位点计数 ' + (pgDet + 2) + ' 枚，当前页应有 ' + (CURPG + 2) + ' 枚）——当前题次为第 ' + (CURPG + 1) + ' 题，错题次不予登记：请切换到对应题次再扫，或拍摄当前页的答题卡';
        } else if (pgMismatch) {
            rejMsg = '疑似第 ' + (pgDet + 1) + ' 页的答题卡（页头定位点仅计数 ' + (pgDet + 2) + ' 枚，当前页应有 ' + (CURPG + 2) + ' 枚）——若确为其他页请先切换题次；若为当前页请重拍（页头点须完整入镜、无遮挡）';
        } else if (TPL.hmarks && finHm < CURPG + 2 && !hmShort) {
            rejMsg = (finHm === 0 && ringF < 0.4)
                ? '未能定位到完整的本模板答题卡——页头清晰还不够，需拍摄整张答题卡（四个角和底部的黑方块都要完整入镜，不能只拍上半张）；若这不是当前题次的卡，请先切换到对应题次'
                : '页头定位点命中 ' + finHm + '/' + (CURPG + 2) + '——请确认拍摄的是本模板当前页的答题卡，整卡四角与页头黑方块完整入镜、无遮挡';
        } else {
            rejMsg = '印刷框对齐度过低——请正对俯拍、避免阴影后重试（须为本模板打印的答题卡）';
        }
        // 定位降级原因透出：标记检不全退纸边矫正 / 涂框配准救回等 flags 让老师知道该往哪个方向调整拍摄
        var fl = (best && best.at && best.at.flags && best.at.flags.length) ? '；系统提示：' + best.at.flags.join('；') : '';
        drawReject(rejMsg + fl + '（诊断：对齐 ' + Math.round(ringF * 100) + '% / 页头点 ' + finHm + '/' + (CURPG + 2) + ' / 结构命中 ' + structN + '）', { hh: hhF, bin: bin, w: w, h: h });
        return;
    }
    // 4.85) 身份区局部平移精调：读分前以 id 框线环互相关实测座号区残余偏移（整体矫正的对应点
    //   全在题目区，对页面外沿的外推误差可达 2~3mm），采纳后重算占比表并平移身份采样/描色坐标。
    //   腐蚀图 rbinE 仅供座号框占比采样（细框线被蚀掉、涂块保留，去 4mm 小框的框线污染）
    var idShift = idShiftEstimate(rbinF);
    var rbinE = erodeBin(rbinF, RW, RH, 1);
    // 5) 旗标：标记推算 / 方向自动纠正 / 页码（均随结果一起展示）；对齐成功时安抚边缘兜底提示
    var flagsArr = (best.at.flags || []).slice();
    if (idShift) {
        ratiosF = computeRatios(rbinF, idShift, rbinE);
        flagsArr.push('定位精调：座号区 ' + Object.keys(idShift).length + ' 框局部偏移已修正');
    } else {
        ratiosF = computeRatios(rbinF, null, rbinE);
    }
    if (hmShort) flagsArr.push('页头定位点命中 ' + finHm + '/' + (CURPG + 2) + '（旧版卡/裁切/遮挡）：按印刷框对齐 ' + Math.round(ringF * 100) + '% 验收');
    // 页码复判透出：计数=当前页（位置失配但顶边带内黑块数吻合）→ 页码确凿，老师可放心；
    //   计数偏少 → 疑似前页卡（也可能是当前页点被遮挡），提示核对题次但不否决
    if (pgDet === CURPG && finHm < CURPG + 2) flagsArr.push('页码经页头点计数确认为第 ' + (CURPG + 1) + ' 页（' + (CURPG + 2) + ' 枚定位点齐全）');
    else if (pgMismatch && pgDet < CURPG) flagsArr.push('疑似第 ' + (pgDet + 1) + ' 页的答题卡（页头点计数 ' + (pgDet + 2) + '/' + (CURPG + 2) + ' 枚）：请核对题次，必要时切换题次重扫');
    if (tuned && ringF >= 0.5) {
        flagsArr = flagsArr.map(function (f) {
            if (f.indexOf('边缘矫正') < 0) return f;
            return alignedN > 0 ? ('定位标记未检全，已按涂框特征自动对齐（' + alignedN + ' 点）') : '定位标记未检全，已按印刷框网格自动对齐';
        });
    }
    var mirWin = best.ri >= 4, rotDeg = (best.ri % 4) * 90;
    if (orientFix) {
        // 翻转 180° 后净旋转 = 原旋转 + 180°：替换原方向旗标，避免前后矛盾
        flagsArr = flagsArr.filter(function (f) { return f.indexOf('方向') < 0 && f.indexOf('镜像') < 0; });
        flagsArr.push('方向已按填涂分布自动纠正（旋转 ' + ((rotDeg + 180) % 360) + '°）');
        if (mirWin) flagsArr.push('画面为镜像，已自动镜像纠正');
    } else if (mirWin) {
        flagsArr.push('画面为镜像，已自动镜像纠正' + (rotDeg > 0 ? '（旋转 ' + rotDeg + '°）' : ''));
    } else if (rotDeg > 0) flagsArr.push('答题卡方向已自动纠正（旋转 ' + rotDeg + '°）');
    if (TPL.pages > 1) flagsArr.push('第 ' + (CURPG + 1) + ' / ' + TPL.pages + ' 页');
    var rimF = warpColor(hhF, data, w, h);
    // 5.5) 预览外扩矫正（四角定位块完整入画）：读分/验收仍用整页画布 rbinF。网格吸附矫正以涂框为锚，
    //   印刷偏移+透视残差会把角标推到页面画布边缘之外或贴边切半（实测左标 x≈0 被切、右标贴 210mm 边被切、
    //   上标 y<0 整块丢失）。预览画布四边外扩 14mm 重采样（标记中心距框角 ≤10mm + 半宽 2.5mm 仍留余量）；
    //   drawOverlay 再按实测标记位做透视矫正让四点贴四角
    var PRV_MG = 14, rwBig = RW + Math.round(2 * PRV_MG * RK), rhBig = RH + Math.round(2 * PRV_MG * RK);
    var hhBig = mat3Mul(hhF.length === 8 ? hhF.concat([1]) : hhF, [1, 0, -PRV_MG * RK, 0, 1, -PRV_MG * RK, 0, 0, 1]);
    var rimBig = warpColor(hhBig, data, w, h, rwBig, rhBig);
    var rbinBig = warpBin(hhBig, bin, w, h, rwBig, rhBig);
    // 5.9) 逐框画框吸附（仅预览描框；读分仍用上面的 ratiosF/gscore，互不影响）：
    //   在最终描框位（座号区含行拟合平移）基础上按框线环证据逐框微调，消除整体矫正的局部残余
    var bubblesDraw = applyBubbleSnap(idShift ? TPL._bubbles.map(function (b) {
        if (b.t !== 'id') return b;
        var sh2 = idShift[b.i + '_' + b.v];
        return sh2 ? Object.assign({}, b, { x: b.x + sh2[0], y: b.y + sh2[1] }) : b;
    }) : TPL._bubbles, bubbleSnapShifts(rbinF));
    var bmapDraw = {};
    bubblesDraw.forEach(function (b) { if (b.t === 'q') bmapDraw['q_' + b.s + '_' + b.q + '_' + b.o] = b; });
    CUR = { rbin: rbinF, rim: rimF, rimBig: rimBig, rbinBig: rbinBig, marginM: PRV_MG,
            cmarks: cornerMarkCenters(rbinBig, rwBig, rhBig, PRV_MG),
            emarks: edgeMidMarkCenters(rbinBig, rwBig, rhBig, PRV_MG),
            gscore: computeGrayScores(rimF, idShift), flags: flagsArr, ratios: ratiosF,
            bubbles: bubblesDraw, _bmap: bmapDraw, pg: CURPG + 1 };
    extractAnswers(true);
    toast('✅ 第 ' + (CURPG + 1) + ' 页识别完毕（' + (Date.now() - t0) + 'ms），可换下一张');
}

// 拒识展示：非答题卡照片（印刷框不对齐 / 缺页头定位点）拒绝识别，预览画布改画红色拒绝横幅
// dbg={hh,bin,w,h} 可选：左侧画二值图缩略（黑=系统看到的墨迹）+ 红虚线框=按模板取景框推算的卡范围，
// 老师一眼看出「系统认到的卡在哪」与实拍的差距（框偏/框小/无框=定位失败原因一目了然）
function drawReject(msg, dbg) {
    CUR = null;
    var panel = document.getElementById('rectPanel');
    panel.style.display = '';
    var c = document.getElementById('rectCanvas');
    c.width = 680; c.height = 170;
    var ctx = c.getContext('2d');
    ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, c.width, c.height);
    ctx.strokeStyle = '#fecaca'; ctx.lineWidth = 2; ctx.strokeRect(1, 1, c.width - 2, c.height - 2);
    var tx0 = 0;
    if (dbg && dbg.hh && dbg.bin && dbg.w && dbg.h) {
        try {
            var th = 148, tw = Math.max(40, Math.min(200, Math.round(dbg.w / dbg.h * th)));
            var off = document.createElement('canvas');
            off.width = dbg.w; off.height = dbg.h;
            var octx = off.getContext('2d');
            var im = octx.createImageData(dbg.w, dbg.h);
            for (var p = 0, q = 0; p < dbg.bin.length; p++, q += 4) {
                var v = dbg.bin[p] ? 30 : 255;
                im.data[q] = im.data[q + 1] = im.data[q + 2] = v; im.data[q + 3] = 255;
            }
            octx.putImageData(im, 0, 0);
            ctx.drawImage(off, 0, 0, dbg.w, dbg.h, 0, 0, tw, th);
            var hh = dbg.hh, sc = tw / dbg.w;
            function prj(x, y) {
                var den = hh[6] * x + hh[7] * y + 1;
                return { x: (hh[0] * x + hh[1] * y + hh[2]) / den * sc, y: (hh[3] * x + hh[4] * y + hh[5]) / den * sc };
            }
            var fx = TPL.frame.x * RK, fy = TPL.frame.y * RK, fw = TPL.frame.w * RK, fh2 = TPL.frame.h * RK;
            var cor = [prj(fx, fy), prj(fx + fw, fy), prj(fx + fw, fy + fh2), prj(fx, fy + fh2)];
            ctx.strokeStyle = '#dc2626'; ctx.lineWidth = 2; ctx.setLineDash([5, 4]);
            ctx.beginPath();
            cor.forEach(function (pt, i) { if (i) ctx.lineTo(pt.x, pt.y); else ctx.moveTo(pt.x, pt.y); });
            ctx.closePath(); ctx.stroke(); ctx.setLineDash([]);
            ctx.fillStyle = '#9ca3af'; ctx.font = '10px sans-serif'; ctx.textAlign = 'left';
            ctx.fillText('黑=系统看到的墨迹｜红框=模板取景范围', 2, 163);
            tx0 = tw + 8;
        } catch (e) {}
    }
    ctx.textAlign = 'center';
    ctx.fillStyle = '#dc2626'; ctx.font = 'bold 22px sans-serif';
    ctx.fillText('✕ 未识别出有效答题卡，已拒绝', tx0 + (c.width - tx0) / 2, 52);
    ctx.fillStyle = '#6b7280'; ctx.font = '14px sans-serif';
    var lines = [], ln = '';
    for (var i = 0; i < msg.length; i++) { ln += msg[i]; if (ln.length >= 34) { lines.push(ln); ln = ''; } }
    if (ln) lines.push(ln);
    lines.slice(0, 4).forEach(function (t, li) { ctx.fillText(t, tx0 + (c.width - tx0) / 2, 88 + li * 22); });
    showResult(null);
    toast('未识别出有效答题卡，已拒绝');
}

// 提取身份与答案 → 画预览（ratios 已由 recognize/computeRatios 算好）
// 判别口径（相对判别）：THR 只作噪声底线（默认 0.10，可调 0.05-0.70），
//   身份位：达 THR 且比次高明显 ≥1.3× 才取值（涂号错不得猜）；
//   单选：达 THR 且（比次高明显 ≥1.35× 或绝对占比 ≥0.45 深涂）→ 取最明显一项，唯一取值；
//   多选：达 THR 且 ≥ 最深项 45% 的项组成集合（铅笔拖痕/邻近噪声排除）；
//   弱信号口径（过曝/远距小卡整体对比压缩的兜底）：灰度分达 THR×0.6 且比次高明显 1.8×，
//   且二值占比同向印证（≥0.05 且 ≥ 次高占比 1.35×）——光照渐变不会同时抬高两个独立信号，
//   实测可捞回过曝照片的浅涂（灰度分被压缩到 0.06-0.11）而空框噪声（占比无差距）不误报
function extractAnswers(draw) {
    if (!CUR) return;
    CUR._autoOnce = (draw === true);   // 新鲜识别（非调阈值重判）才允许自动登记，防止重复提交
    var ratios = CUR.ratios;
    if (!ratios || !Object.keys(ratios).length) { ratios = computeRatios(CUR.rbin); CUR.ratios = ratios; }
    var GS = CUR.gscore;
    if (!GS && CUR.rim) { GS = CUR.gscore = computeGrayScores(CUR.rim); }
    if (!GS) GS = ratios;   // 极旧数据无矫正彩图：退化用占比表（口径变严但不出错）
    // 弱信号判定：g=候选灰度分 secondG=其余最大灰度分 ra=候选占比 secondRa=其余最大占比
    function weakHit(g, secondG, ra, secondRa) {
        return g >= THR * 0.6 && g >= secondG * 1.8 && ra >= 0.05 && ra >= secondRa * 1.35;
    }
    var bubbles = CUR.bubbles;
    var idmode = (TPL.id.idmode === 'no') ? 'no' : 'seat';
    var accSet = CUR.accSet = {};   // 实际判定为「已涂」的框（含相对判别命中），供批注绿色描色/符号统一口径
    var ident = '', identOk = true, idDetail = [];
    for (var di = 0; di < TPL.id.digits; di++) {
        var bestV = -1, bestG = 0, secondG = 0;
        for (var v = 0; v <= 9; v++) {
            var g = GS['id_' + di + '_' + v] || 0;
            if (g > bestG) { secondG = bestG; bestG = g; bestV = v; }
            else if (g > secondG) secondG = g;
        }
        var raBest = ratios['id_' + di + '_' + bestV] || 0, raSecond = 0;
        for (var v2 = 0; v2 <= 9; v2++) {
            if (v2 === bestV) continue;
            var ra2 = ratios['id_' + di + '_' + v2] || 0;
            if (ra2 > raSecond) raSecond = ra2;
        }
        if (bestG >= THR && bestG >= secondG * 1.3) { ident += String(bestV); accSet['id_' + di + '_' + bestV] = 1; }
        else if (bestG >= THR * 0.6 && weakHit(bestG, secondG, raBest, raSecond)) { ident += String(bestV); accSet['id_' + di + '_' + bestV] = 1; }
        else if (bestG < THR && raBest >= 0.12 && raBest >= raSecond * 1.5 && bestG >= secondG * 1.5 && bestG >= 0.02) {
            // 超浅涂兜底（仅身份位）：腐蚀占比已去 4mm 小框的框线污染、本身是可靠主信号，灰度暗分位
            // 同向印证（双信号 1.5× 领先）即采纳——实测 205039 第2位浅涂不满，g=0.027 过不了弱信号
            // 绝对线 0.06 但两个独立信号都明确领先；占比绝对下限 0.12 排除空行噪声
            ident += String(bestV); accSet['id_' + di + '_' + bestV] = 1;
        }
        else if (bestG < THR) { identOk = false; idDetail.push('第' + (di + 1) + '位未涂'); ident += '?'; }
        else {
            // 未涂（痕迹过轻）：最高分拉不开差距且绝对值低（<0.25 属浅涂水平），与「涂了但分不清」区分开
            identOk = false;
            idDetail.push('第' + (di + 1) + (bestG < 0.25 ? '位未涂（痕迹过轻）' : '位重涂不清'));
            ident += '?';
        }
    }
    // 各题答案（同时统计未涂/无法识别题数，供 ≥30% 提示判定）。
    // 多页模板：仅统计当前识别页的选择类题组（填空/简答为手写题无涂框，天然不参与；其他页不在本张卡上）
    var answers = {}, qflags = [], qTotal = 0, unkCnt = 0;
    (TPL.sections || []).forEach(function (sec, si) {
        if ((sec.pg || 0) !== CURPG) return;
        if (sec.kind === 'blank' || sec.kind === 'short') return;   // 手写题不判分、无涂框
        for (var q = 0; q < sec.count; q++) {
            var qno = String(sec.start + q);
            qTotal++;
            var best = '', bestG2 = 0, secondG2 = 0;
            for (var o = 0; o < sec.opts; o++) {
                var g2 = GS['q_' + si + '_' + qno + '_' + LETTERS[o]] || 0;
                if (g2 > bestG2) { secondG2 = bestG2; bestG2 = g2; best = LETTERS[o]; }
                else if (g2 > secondG2) secondG2 = g2;
            }
            var raB = ratios['q_' + si + '_' + qno + '_' + best] || 0, raS = 0;
            for (var o1 = 0; o1 < sec.opts; o1++) {
                if (LETTERS[o1] === best) continue;
                var ra3 = ratios['q_' + si + '_' + qno + '_' + LETTERS[o1]] || 0;
                if (ra3 > raS) raS = ra3;
            }
            if (sec.kind === 'multi') {
                // 多选题组：多项取值（达阈值且 ≥ 最深项 45%，排除铅笔拖痕/邻近框噪声；弱信号同口径）
                var over = [];
                for (var o2 = 0; o2 < sec.opts; o2++) {
                    var lt = LETTERS[o2], g3 = GS['q_' + si + '_' + qno + '_' + lt] || 0;
                    var r3o = ratios['q_' + si + '_' + qno + '_' + lt] || 0, r3s = 0;
                    for (var o3 = 0; o3 < sec.opts; o3++) {
                        if (o3 === o2) continue;
                        var r4 = ratios['q_' + si + '_' + qno + '_' + LETTERS[o3]] || 0;
                        if (r4 > r3s) r3s = r4;
                    }
                    if ((g3 >= THR && g3 >= bestG2 * 0.45) || weakHit(g3, secondG2, r3o, r3s)) over.push(lt);
                }
                if (over.length) { answers[qno] = over.join(''); over.forEach(function (lt2) { accSet['q_' + si + '_' + qno + '_' + lt2] = 1; }); }
                else { qflags.push('第' + qno + '题未涂'); unkCnt++; }
            } else {
                // 单选题组：唯一取值——取最明显（灰度对比分最高）一项。达阈值且比次高明显 1.35×（或深涂 ≥0.45
                // 时双涂也取更深者）；拉不开差距视为噪声/涂擦残留判未涂；弱信号口径兜底过曝/远距照片
                var pick = (bestG2 >= THR && (bestG2 >= secondG2 * 1.35 || bestG2 >= 0.45)) || weakHit(bestG2, secondG2, raB, raS);
                if (pick) { answers[qno] = best; accSet['q_' + si + '_' + qno + '_' + best] = 1; }
                else { qflags.push('第' + qno + '题未涂'); unkCnt++; }
            }
        }
    });
    CUR.unkCnt = unkCnt; CUR.qTotal = qTotal;
    CUR.ident = ident; CUR.identOk = identOk; CUR.answers = answers; CUR.idDetail = idDetail;
    var baseFlags = Array.isArray(CUR.flags) ? CUR.flags : String(CUR.flags || '').split('；');
    CUR.flags = baseFlags.filter(function (f) { return f.indexOf('定位') >= 0 || f.indexOf('方向') >= 0 || f.indexOf('镜像') >= 0 || f.indexOf('可信') >= 0 || f.indexOf('页') >= 0; }).concat(qflags).join('；').slice(0, 200);
    CUR.idmode = idmode;
    drawOverlay();
    showResult(draw === true ? 'auto' : 're');
    fetchIdentPreview();
}

// 灰度对比填涂分（浅涂灵敏判定，替代二值占比作判定口径；占比表仍用于方向/对齐评分）：
// 框内均值灰度（18% 内缩避开印刷边框）相对框周纸面环带（外扩 0.25-0.9mm，挖掉框本体；
// 取 60 分位而非均值——铅笔溢出框外的拖尾只污染环带少数像素，高分位仍是干净纸面）的变暗程度：
// 空白框≈0-0.03、铅笔轻涂≈0.08-0.25、深涂≥0.4。环带与框同处局部光照下，阴影/过曝/白平衡
// 近似同乘一个系数相消——自适应二值化会把浅铅笔画成背景（局部均值法固有缺陷），灰度比不会
function computeGrayScores(rim, idShift) {
    var d = rim.data, out = {};
    TPL._bubbles.forEach(function (b) {
        var isId = b.t === 'id';
        var sh = isId && idShift ? idShift[b.i + '_' + b.v] : null;
        var ox = sh ? sh[0] : 0, oy = sh ? sh[1] : 0;
        var x0 = Math.max(0, Math.round((b.x + ox + b.w * 0.18) * RK)), x1 = Math.min(RW - 1, Math.round((b.x + ox + b.w * 0.82) * RK));
        var y0 = Math.max(0, Math.round((b.y + oy + b.h * 0.18) * RK)), y1 = Math.min(RH - 1, Math.round((b.y + oy + b.h * 0.82) * RK));
        var gIn = 0, nIn = 0;
        // 座号小框（4mm）框线粗且涂块常不满/偏位：均值口径下框线残余抬高空框分、空白区稀释浅涂分
        //（实测 205039 空框 0.07 反超涂块 0.048）——改取框内暗侧 35 分位（涂块覆盖 ≥35% 时该分位
        // 落进涂块，信号增强；空框该分位仍是纸面/框线残余，与环带同源相消），题框保持均值基线不变
        var hist2 = isId ? new Float64Array(256) : null;
        for (var yy = y0; yy <= y1; yy++)
            for (var xx = x0; xx <= x1; xx++) {
                var q4 = (yy * RW + xx) * 4;
                var gv = d[q4] * 0.299 + d[q4 + 1] * 0.587 + d[q4 + 2] * 0.114;
                gIn += gv; nIn++;
                if (hist2) hist2[Math.min(255, Math.max(0, Math.round(gv)))]++;
            }
        if (hist2 && nIn) {
            var want2 = Math.floor(nIn * 0.35), acc2 = 0; gIn = 0;
            for (var v5 = 0; v5 < 256; v5++) { acc2 += hist2[v5]; if (acc2 >= want2) { gIn = v5 * nIn; break; } }
        }
        gIn = nIn ? gIn / nIn : 255;
        var rx0 = Math.max(0, Math.round((b.x + ox - 0.9) * RK)), ry0 = Math.max(0, Math.round((b.y + oy - 0.9) * RK));
        var rx1 = Math.min(RW - 1, Math.round((b.x + ox + b.w + 0.9) * RK)), ry1 = Math.min(RH - 1, Math.round((b.y + oy + b.h + 0.9) * RK));
        var mx0 = Math.max(0, Math.round((b.x + ox - 0.25) * RK)), my0 = Math.max(0, Math.round((b.y + oy - 0.25) * RK));
        var mx1 = Math.min(RW - 1, Math.round((b.x + ox + b.w + 0.25) * RK)), my1 = Math.min(RH - 1, Math.round((b.y + oy + b.h + 0.25) * RK));
        var hist = new Float64Array(256), nRing = 0;
        for (var y2 = ry0; y2 <= ry1; y2++)
            for (var x2 = rx0; x2 <= rx1; x2++) {
                if (x2 >= mx0 && x2 <= mx1 && y2 >= my0 && y2 <= my1) continue;
                var q5 = (y2 * RW + x2) * 4;
                var g2 = Math.min(255, Math.max(0, Math.round(d[q5] * 0.299 + d[q5 + 1] * 0.587 + d[q5 + 2] * 0.114)));
                hist[g2]++; nRing++;
            }
        var want = Math.floor(nRing * 0.6), acc = 0, gRing = 255;
        for (var v = 0; v < 256; v++) { acc += hist[v]; if (acc >= want) { gRing = v; break; } }
        out[b.t === 'id' ? 'id_' + b.i + '_' + b.v : 'q_' + b.s + '_' + b.q + '_' + b.o] = Math.max(0, 1 - gIn / Math.max(1, gRing));
    });
    return out;
}

// 预览裁切用：在外扩矫正二值图上实测定位方块中心（返回模板毫米坐标，未含外扩偏移）。
// 网格吸附矫正以涂框为锚，标记实测位置随印刷偏移/透视残差漂移（实测框角 ±8mm、各角不对称），
// 预览裁切须按实测位置扩窗才能「标记尽收、贴边呈现」。逐标记 ±14mm 窗内 BFS 连通域，按
// 「面积≈5mm 方块量级 150~1500px、近方形 2.2~9.5mm、实心度 ≥0.5（排除涂框线框/纸边残线）、
// 距预期中心 ≤dLimMM（角标 10mm 排除座号涂框/印刷文字；边中点 8mm —— 内容距框边恒 ≥8mm）」
// 筛选取最近者；无合格块再做 3×3 腐蚀兜底（标记与纸边/深色背景连通成巨块时，内部核心仍能检出，
// 实测 204414 左上标）；仍无合格返回 null（模板框角兜底）
function findMarkCenter(rbin, rw, rh, mg, exmm, eymm, dLimMM) {
    var winR = Math.round(14 * RK);
    // 聚簇版式：页码点中心集（模板 mm，hmarkGeom 即当前页预期位置）——窗内排除，防页码点被当成定位标
    // （首枚中心距左上角点中心恰 10mm，仍在 ±14mm 搜索窗与 ≤10mm 取近边界上）
    var hmc = TPL.hmarkCluster ? hmarkGeom().map(function (m) { return { x: m.x + 2.5, y: m.y + 2.5 }; }) : null;
    var ccx = (exmm + mg) * RK, ccy = (eymm + mg) * RK;   // 外扩画布 px
        var x0 = Math.max(0, Math.round(ccx - winR)), y0 = Math.max(0, Math.round(ccy - winR));
        var x1 = Math.min(rw - 1, Math.round(ccx + winR)), y1 = Math.min(rh - 1, Math.round(ccy + winR));
        var W2 = x1 - x0 + 1, H2 = y1 - y0 + 1;
        // 扫描一幅二值图：BFS 找 blob，按门槛（像素数/近方/实心/距框角）取距框角最近者；无合格 → null
        var scan = function (arr) {
            var seen = new Uint8Array(W2 * H2), best = null;
            for (var yy = y0; yy <= y1; yy++) for (var xx = x0; xx <= x1; xx++) {
                if (!arr[yy * rw + xx]) continue;
                var li0 = (yy - y0) * W2 + (xx - x0);
                if (seen[li0]) continue;
                var qx = [xx], qy = [yy]; seen[li0] = 1;
                var n = 0, sx = 0, sy = 0, bx0 = xx, bx1 = xx, by0 = yy, by1 = yy;
                for (var qi = 0; qi < qx.length; qi++) {
                    var px = qx[qi], py = qy[qi];
                    n++; sx += px; sy += py;
                    if (px < bx0) bx0 = px; if (px > bx1) bx1 = px;
                    if (py < by0) by0 = py; if (py > by1) by1 = py;
                    var nb = [[px + 1, py], [px - 1, py], [px, py + 1], [px, py - 1]];
                    for (var ni = 0; ni < 4; ni++) {
                        var nx = nb[ni][0], ny = nb[ni][1];
                        if (nx < x0 || nx > x1 || ny < y0 || ny > y1) continue;
                        var li2 = (ny - y0) * W2 + (nx - x0);
                        if (!seen[li2] && arr[ny * rw + nx]) { seen[li2] = 1; qx.push(nx); qy.push(ny); }
                    }
                }
                var bw = (bx1 - bx0 + 1) / RK, bh = (by1 - by0 + 1) / RK;
                if (n < 150 || n > 1500 || bw < 2.2 || bw > 9.5 || bh < 2.2 || bh > 9.5) continue;
                if (n / ((bx1 - bx0 + 1) * (by1 - by0 + 1)) < 0.5) continue;   // 实心（线框/残线排除）
                var dx = sx / n - ccx, dy = sy / n - ccy, d = Math.sqrt(dx * dx + dy * dy);
                if (d > dLimMM * RK) continue;   // 距预期中心过远：座号涂框/文字/页码点，非目标定位标
                var mx3 = sx / n / RK - mg, my3 = sy / n / RK - mg, isHm = false;
                if (hmc) for (var hi = 0; hi < hmc.length; hi++) {
                    var ex3 = mx3 - hmc[hi].x, ey3 = my3 - hmc[hi].y;
                    if (ex3 * ex3 + ey3 * ey3 < 36) { isHm = true; break; }   // <6mm：页码点簇非角标
                }
                if (isHm) continue;
                if (!best || d < best.d) best = { d: d, x: mx3, y: my3 };   // 回到模板 mm 坐标
            }
            return best;
        };
        var best = scan(rbin);
        if (!best) {
            // 稠密窗兜底：标记贴近纸边时与纸边/深色背景在二值图连通成巨块，BLOB 门槛整块拒掉（实测
            // 204414 左上标、190650 多角）——用积分图找「最实的 4.4mm 方窗」：窗内暗占比 ≥0.78 且
            // 外圈(至 3.4mm)暗占比 ≤0.55（标记浮在白纸上、外圈大部分白；背景巨块内外圈全暗被排除），
            // 取占比最高且中心距预期中心 ≤dLimMM 者
            var WWI = W2 + 1, inA = (2 * Math.round(2.2 * RK) + 1) * (2 * Math.round(2.2 * RK) + 1),
                rgP = 2 * Math.round(3.4 * RK) + 1, rgA = rgP * rgP - inA;
            var integ = new Float64Array(WWI * (H2 + 1));
            for (var iy = 0; iy < H2; iy++)
                for (var ix = 0; ix < W2; ix++)
                    integ[(iy + 1) * WWI + (ix + 1)] = (rbin[(y0 + iy) * rw + (x0 + ix)] ? 1 : 0)
                        + integ[iy * WWI + (ix + 1)] + integ[(iy + 1) * WWI + ix] - integ[iy * WWI + ix];
            var hf = Math.round(2.2 * RK), rg = Math.round(3.4 * RK), lim = Math.round(dLimMM * RK);
            var lc = Math.round(ccx) - x0, tc = Math.round(ccy) - y0, bs = null;
            for (var cy2 = Math.max(rg, tc - lim); cy2 <= Math.min(H2 - 1 - rg, tc + lim); cy2 += 2)
                for (var cx2 = Math.max(rg, lc - lim); cx2 <= Math.min(W2 - 1 - rg, lc + lim); cx2 += 2) {
                    var s1 = integ[(cy2 - rg) * WWI + (cx2 - rg)], s2 = integ[(cy2 - rg) * WWI + (cx2 + rg + 1)],
                        s3 = integ[(cy2 + rg + 1) * WWI + (cx2 - rg)], s4 = integ[(cy2 + rg + 1) * WWI + (cx2 + rg + 1)];
                    var t1 = integ[(cy2 - hf) * WWI + (cx2 - hf)], t2 = integ[(cy2 - hf) * WWI + (cx2 + hf + 1)],
                        t3 = integ[(cy2 + hf + 1) * WWI + (cx2 - hf)], t4 = integ[(cy2 + hf + 1) * WWI + (cx2 + hf + 1)];
                    var inner = t4 - t3 - t2 + t1, ringD = (s4 - s3 - s2 + s1) - inner;
                    if (inner / inA < 0.78 || ringD / rgA > 0.55) continue;
                    if (!bs || inner > bs.inner) bs = { inner: inner, x: x0 + cx2, y: y0 + cy2 };
                }
            if (bs) best = { d: 0, x: bs.x / RK - mg, y: bs.y / RK - mg };
        }
    return best ? { x: best.x, y: best.y } : null;
}
// 四角定位方块中心（预览矫正主锚点）：返回 4 元素数组（TL,TR,BR,BL），未测到=null
function cornerMarkCenters(rbin, rw, rh, mg) {
    var F = TPL.frame;
    return [[F.x, F.y], [F.x + F.w, F.y], [F.x + F.w, F.y + F.h], [F.x, F.y + F.h]]
        .map(function (c) { return findMarkCenter(rbin, rw, rh, mg, c[0], c[1], 10); });
}
// 边缘中点码点中心（左/右/下，顺序与设计器 marksGeom 追加序一致；顶边被页码点占用无中点）。
// 仅 edgeMidMarks 模板检测；dLim=8mm（角标 10mm）——内容距框边恒 ≥8mm、涂块中心 ≥10.1mm，
// 收紧上限把边中涂块排除得更彻底，同时容忍 ≤5mm 印刷偏移。未测到=null（该边退化为直线边）
function edgeMidMarkCenters(rbin, rw, rh, mg) {
    if (!TPL.edgeMidMarks) return [null, null, null];
    var F = TPL.frame;
    return [[F.x, F.y + F.h / 2], [F.x + F.w, F.y + F.h / 2], [F.x + F.w / 2, F.y + F.h]]
        .map(function (c) { return findMarkCenter(rbin, rw, rh, mg, c[0], c[1], 8); });
}

// 预览：矫正后的彩色原图上叠加批注框与判定符号；管理员可切换黑白二值调试图（调试矫正/判定效果）
var DBG = false;
function toggleDbg() {
    DBG = !DBG;
    var b = document.getElementById('dbgBtn');
    if (b) b.textContent = DBG ? '🖼 原图批注' : '🐞 调试图';
    drawOverlay();
}
function drawOverlay() {
    var panel = document.getElementById('rectPanel');
    panel.style.display = '';
    var c = document.getElementById('rectCanvas');
    // 预览透视矫正（四点贴角）：读分管线仍以涂框为锚保证识别精度；预览则用【实测四角定位黑块】
    // （cornerMarkCenters，BLOB 筛选抗纸边残线/涂框干扰）作为矫正四边形，把四个黑块精确映射到输出
    // 画布四角（内缩 3.5mm=标记半宽+余量，黑块完整可见）——无论印刷偏移把标记漂到框内（旧 union
    // 裁剪会留出数 mm 空隙、标记不贴角）还是框外，预览里四点恒贴四角、画布尺寸恒定（=模板框±3.5mm）。
    // 该角未测到（遮挡/出画）→ 模板框角兜底；全部未测到/矩阵退化 → 平移裁剪（旧行为）。
    // 画面源 = 外扩 14mm 的预览矫正图（rimBig/rbinBig）：批注先画在源图上，随透视一起重采样，恒对齐
    var F = TPL.frame;
    var MG = CUR.marginM || 0, MPX = MG * RK;
    var RWV = CUR.rimBig ? CUR.rimBig.width : RW, RHV = CUR.rimBig ? CUR.rimBig.height : RH;
    var W2 = Math.round((F.w + 7) * RK), H2 = Math.round((F.h + 7) * RK), PAD = 3.5 * RK;
    var fcorn = [[F.x, F.y], [F.x + F.w, F.y], [F.x + F.w, F.y + F.h], [F.x, F.y + F.h]];
    var dstP = [[PAD, PAD], [W2 - PAD, PAD], [W2 - PAD, H2 - PAD], [PAD, H2 - PAD]];
    // 分层矫正（输出像素 → 外扩源图采样映射，传给 omrFinishPreview）：
    // T1 四角全实测且边中点有实测 → Coons 分段拉直（顶边页码点占用恒直线，左/右/底 3 点边过中点弯曲）；
    // T2 实测点（角+中）总数 ≥4 → 全实测点单应（某角被遮挡/出画时由边中点补位）；
    // T3 实测点不足 → 模板框角兜底单应；矩阵退化 → 平移裁剪（旧行为）。
    // 旧模板（无 edgeMidMarks）emarks 全 null → 走原四角单应/兜底路径，行为逐字节不变
    var cmk = CUR.cmarks || [], emk = TPL.edgeMidMarks ? (CUR.emarks || []) : [];
    var srcC = [], haveC = 0;
    for (var ci = 0; ci < 4; ci++) {
        var mk = cmk[ci];
        srcC.push(mk ? [(mk.x + MG) * RK, (mk.y + MG) * RK] : null);
        if (mk) haveC++;
    }
    var ems = [];
    for (var ei = 0; ei < 3; ei++) {
        var mk2 = emk[ei];
        ems.push(mk2 ? [(mk2.x + MG) * RK, (mk2.y + MG) * RK] : null);
    }
    var warpFn = null;
    if (haveC === 4 && (ems[0] || ems[1] || ems[2])) warpFn = coonsWarpFn(srcC, ems, W2, H2, PAD);   // T1
    if (!warpFn) {
        var msrc = [], mdst = [];
        for (var cj = 0; cj < 4; cj++) if (srcC[cj]) { msrc.push(srcC[cj]); mdst.push(dstP[cj]); }
        var midDst = [[PAD, H2 / 2], [W2 - PAD, H2 / 2], [W2 / 2, H2 - PAD]];   // 输出画布左中/右中/底中
        for (var ek = 0; ek < 3; ek++) if (ems[ek]) { msrc.push(ems[ek]); mdst.push(midDst[ek]); }
        var hh2 = null;
        if (msrc.length >= 4) hh2 = homography(mdst, msrc);   // T2：全实测点单应
        if (!hh2) {
            var srcP = [];   // T3：实测角 + 模板框角兜底
            for (var ck = 0; ck < 4; ck++)
                srcP.push(srcC[ck] || [(fcorn[ck][0] + MG) * RK, (fcorn[ck][1] + MG) * RK]);
            hh2 = homography(dstP, srcP);
        }
        if (!hh2) hh2 = [1, 0, (F.x - 3.5 + MG) * RK, 0, 1, (F.y - 3.5 + MG) * RK, 0, 0];   // 平移裁剪
        warpFn = homWarpFn(hh2);
    }
    var tmp = document.createElement('canvas');
    tmp.width = RWV; tmp.height = RHV;
    var ctx = tmp.getContext('2d');
    if (DBG || !CUR.rim) {
        var rbinV = CUR.rbinBig || CUR.rbin;
        var img = ctx.createImageData(RWV, RHV);
        for (var i = 0, p = 0; i < RWV * RHV; i++, p += 4) {
            var g = rbinV[i] ? 40 : 255;
            img.data[p] = g; img.data[p + 1] = g; img.data[p + 2] = g; img.data[p + 3] = 255;
        }
        ctx.putImageData(img, 0, 0);
    } else {
        ctx.putImageData(CUR.rimBig || CUR.rim, 0, 0);
    }
    ctx.translate(MPX, MPX);   // 批注用模板毫米坐标（画布平移到外扩坐标系，透视重采样后批注与影像恒对齐）
    ctx.lineWidth = 1.5;
    CUR.bubbles.forEach(function (b) {
        var key = b.t === 'id' ? 'id_' + b.i + '_' + b.v : 'q_' + b.s + '_' + b.q + '_' + b.o;
        var r = (CUR.gscore && CUR.gscore[key]) || 0;   // 灰度对比分与判定同源（占比表含边框墨迹，浅涂会漏画黄框）
        var acc = CUR.accSet && CUR.accSet[key];   // 生效涂项（含相对判别命中）→ 绿框；未生效但占比较高 → 黄框疑似
        if (!acc && r < THR * 0.75) return;
        ctx.strokeStyle = acc ? '#22c55e' : '#f59e0b';
        ctx.strokeRect(b.x * RK - 1, b.y * RK - 1, b.w * RK + 2, b.h * RK + 2);
    });
    // 逐题判定符号：绿√=所涂与答案键相符；红✗=涂错；橙?=未涂/无法识别
    // （按生效答案键判定：独立录入答案优先、模板答案键兜底；该题缺答案则不判对错，仅保留绿框/黄框描色）
    if (!TPL || !TPL._bubbleMap) { omrFinishPreview(c, tmp, warpFn, W2, H2, RWV, RHV); return; }
    var bmap = CUR._bmap || TPL._bubbleMap;   // 符号随吸附后的描框位画（与绿/黄框恒对齐）
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    (TPL.sections || []).forEach(function (sec, si) {
        for (var q = 0; q < sec.count; q++) {
            var qno = String(sec.start + q);
            var kk = String(ANSCTX.key[qno] || '');
            var fb = bmap['q_' + si + '_' + qno + '_A'];
            if (!fb) continue;
            var marked = [];
            for (var o = 0; o < sec.opts; o++) {
                var lt = LETTERS[o], mk2 = 'q_' + si + '_' + qno + '_' + lt;
                if ((CUR.ratios[mk2] || 0) >= THR || (CUR.accSet && CUR.accSet[mk2]))
                    marked.push({ lt: lt, b: bmap[mk2] });
            }
            // 单选题组：符号只打在唯一生效答案上（多涂时其余物理涂框保留描色、不打符号）；
            // 判定未涂/无法识别（extractAnswers 相对判别否决）→ 强制橙?，不再因噪声比值过阈误打红✗
            if (sec.kind !== 'multi') {
                var chosen = CUR.answers ? CUR.answers[qno] : '';
                if (chosen) {
                    marked = marked.filter(function (m) { return m.lt === chosen; });
                    if (!marked.length) {
                        var mb = bmap['q_' + si + '_' + qno + '_' + chosen];
                        if (mb) marked = [{ lt: chosen, b: mb }];
                    }
                } else {
                    marked = [];
                }
            }
            if (!marked.length) { omrSym(ctx, '?', '#f59e0b', fb); continue; }
            if (!kk) continue;
            marked.forEach(function (m) {
                var ok = kk.indexOf(m.lt) >= 0;
                omrSym(ctx, ok ? '√' : '✗', ok ? '#16a34a' : '#dc2626', m.b);
            });
        }
    });
    // 身份回显：识别出的学生已匹配（omr_ident_preview）→ 在身份区上方标注「座号/编号 + 姓名」；
    // 无匹配不绘制，保持模板原样
    if (CUR.stu) {
        var ix0 = 1e9, iy0 = 1e9, ix1 = -1e9;
        CUR.bubbles.forEach(function (b) {
            if (b.t !== 'id') return;
            ix0 = Math.min(ix0, b.x); iy0 = Math.min(iy0, b.y); ix1 = Math.max(ix1, b.x + b.w);
        });
        if (ix1 > ix0) {
            var noVal = (CUR.idmode === 'no') ? (CUR.stu.student_no || CUR.ident) : (CUR.stu.seat_no || CUR.ident);
            var stuTxt = (CUR.idmode === 'no' ? '编号 ' : '座号 ') + noVal + ' · ' + CUR.stu.name;
            var sfs = Math.max(11, 3.4 * RK);
            ctx.font = 'bold ' + sfs + 'px sans-serif';
            ctx.textAlign = 'center'; ctx.textBaseline = 'bottom';
            var stw = ctx.measureText(stuTxt).width, scx = (ix0 + ix1) / 2 * RK, sty = iy0 * RK - 0.7 * RK;
            var spad = 0.8 * RK;
            ctx.fillStyle = 'rgba(255,255,255,0.85)';
            ctx.fillRect(scx - stw / 2 - spad, sty - sfs * 1.15, stw + spad * 2, sfs * 1.4);
            ctx.fillStyle = '#15803d';
            ctx.fillText(stuTxt, scx, sty);
        }
    }
    omrFinishPreview(c, tmp, warpFn, W2, H2, RWV, RHV);
}
// 预览收尾：批注完成的外扩源图整体透视重采样到输出画布（实测标记恒贴输出四边，批注随同变换恒对齐）。
// warpFn 为输出像素→源图采样闭包（homWarpFn 单应 / coonsWarpFn Coons 分段拉直，见 drawOverlay 分层）
function omrFinishPreview(c, tmp, warpFn, W2, H2, RWV, RHV) {
    c.width = W2; c.height = H2;
    var tdata = tmp.getContext('2d').getImageData(0, 0, RWV, RHV);
    c.getContext('2d').putImageData(warpColorFn(warpFn, tdata.data, RWV, RHV, W2, H2), 0, 0);
}
// 在涂框中心叠加判定符号（白描边保证深浅底色下均可读）
function omrSym(ctx, sym, color, b) {
    var fs = Math.max(11, b.h * RK * 1.25);
    ctx.font = 'bold ' + fs + 'px sans-serif';
    ctx.lineJoin = 'round';
    ctx.lineWidth = Math.max(2, fs / 6);
    ctx.strokeStyle = 'rgba(255,255,255,0.95)';
    ctx.strokeText(sym, (b.x + b.w / 2) * RK, (b.y + b.h / 2) * RK);
    ctx.fillStyle = color;
    ctx.fillText(sym, (b.x + b.w / 2) * RK, (b.y + b.h / 2) * RK);
}
// 身份预匹配（只读）：识别出座号/编号后即时查学生，匹配到则在预览身份区上方回显「座号 姓名」；
// 未识别出身份或无匹配 → CUR.stu 清空并重绘（保持原样）。识别/调阈值/改答案都会经此刷新
function fetchIdentPreview() {
    if (!CUR) return;
    var cur = CUR;
    if (!CUR.identOk) { CUR.stu = null; drawOverlay(); return; }
    apiPost({ type: 'omr_ident_preview', project_id: PROJECT_ID, idtype: CUR.idmode, ident: CUR.ident }, function (d) {
        if (CUR !== cur) return;   // 请求期间已换下一张 → 丢弃
        cur.stu = (d && d.success && d.matched && d.student) ? d.student : null;
        drawOverlay();
        // ⚡ 自动登记：开关开 + 新鲜识别（_autoOnce 已消费不重复）+ 匹配到学生 + 该卡有答案 → 直接提交
        if (AUTOREG && cur._autoOnce && cur.stu && Object.keys(cur.answers).length) {
            cur._autoOnce = false;
            submitResult(true);
        }
    });
}

// ===== 结果展示 / 提交 =====
function showResult(mode) {
    var panel = document.getElementById('resPanel');
    if (!CUR) { panel.style.display = 'none'; return; }
    panel.style.display = '';
    var state = document.getElementById('resState');
    state.innerHTML = (mode === 'auto')
        ? '<b style="color:#16a34a;">✅ 识别完毕，可换下一张</b>'
        : (mode === 'edit' ? '<span style="color:#888;">（已手动修正）</span>' : '<span style="color:#888;">（已按新阈值重新判定）</span>');
    if (TPL.pages > 1) state.innerHTML += ' <span class="flag-badge">📄 第 ' + (CUR.pg || 1) + ' / ' + TPL.pages + ' 页</span>';
    if (!ANSCTX.complete) state.innerHTML += ' <span class="flag-badge" title="答案未覆盖全部题目，本次登记不带批改；补全答案后已识别结果自动重算">仅登记·未批改</span>';
    // ≥30% 无法识别/未涂：提示降阈值或改传图识别
    var lowBox = document.getElementById('resLow');
    if (lowBox) lowBox.style.display = (CUR.qTotal > 0 && CUR.unkCnt / CUR.qTotal >= 0.3) ? '' : 'none';
    // 身份：自动识别成功→展示；不清→手动输入框
    var identBox = document.getElementById('manualIdentBox');
    identBox.style.display = CUR.identOk ? 'none' : '';
    if (!CUR.identOk) document.getElementById('manualIdent').value = '';
    document.getElementById('resIdentity').innerHTML = CUR.identOk
        ? '🎓 身份（' + (CUR.idmode === 'no' ? '编号' : '座号') + '）：<b style="font-size:20px;letter-spacing:2px;">' + esc(CUR.ident) + '</b>'
        : '⚠ 身份未能自动确认（' + esc(CUR.idDetail.join('，')) + '）';
    document.getElementById('resFlags').innerHTML = CUR.flags
        ? CUR.flags.split('；').filter(Boolean).map(function (f) { return '<span class="flag-badge">' + esc(f) + '</span>'; }).join('')
        : '<span class="ok-badge">✓ 全部题已按判定阈值解析</span>';
    // 答案芯片：单选点击可循环改；多选点击循环切换集合；填空/简答手写题仅展示标题（无涂框不判分）
    var html = '';
    (TPL.sections || []).forEach(function (sec, si) {
        if (sec.kind === 'blank' || sec.kind === 'short') {
            html += '<div class="res-title">' + esc(sec.title || '题组') + '（第 ' + ((sec.pg || 0) + 1) + ' 页）</div>'
                  + '<div style="font-size:12px;color:#999;margin-bottom:4px;">手写题不自动判分、不计入统计</div>';
            return;
        }
        html += '<div class="res-title">' + esc(sec.title || '题组') + (sec.kind === 'multi' ? '（多选）' : '') + (TPL.pages > 1 ? '（第 ' + ((sec.pg || 0) + 1) + ' 页）' : '') + '</div>';
        for (var q = 0; q < sec.count; q++) {
            var qno = String(sec.start + q);
            var a = CUR.answers[qno] || '';
            var chips = '';
            for (var o = 0; o < sec.opts; o++) {
                var lt = LETTERS[o];
                var on = (sec.kind === 'multi') ? a.indexOf(lt) >= 0 : (a === lt);
                chips += '<span class="res-chip' + (on ? ' filled' : '') + '" data-q="' + qno + '" data-lt="' + lt + '" onclick="chipToggle(this)">' + lt + '</span>';
            }
            html += '<div style="margin-bottom:2px;"><span style="font-size:12px;color:#888;display:inline-block;width:44px;">第' + qno + '题</span>' + chips + '</div>';
        }
    });
    document.getElementById('resAnswers').innerHTML = html;
    document.getElementById('resStudent').innerHTML = '';
    document.getElementById('submitBtn').disabled = false;
}
function chipToggle(el) {
    var qno = el.dataset.q, lt = el.dataset.lt;
    var sec = null, si = 0;
    for (var i = 0; i < (TPL.sections || []).length; i++) {
        var s = TPL.sections[i];
        if (parseInt(qno, 10) >= s.start && parseInt(qno, 10) < s.start + s.count) { sec = s; si = i; break; }
    }
    if (!sec) return;
    var cur = CUR.answers[qno] || '';
    if (sec.kind === 'multi') {
        var arr = cur.split(''), p = arr.indexOf(lt);
        if (p >= 0) arr.splice(p, 1); else { arr.push(lt); arr.sort(); }
        if (arr.length) CUR.answers[qno] = arr.join(''); else delete CUR.answers[qno];
    } else {
        if (cur === lt) delete CUR.answers[qno]; else CUR.answers[qno] = lt;
    }
    showResult('edit');
}
function submitResult(auto) {
    if (!CUR) return;
    var ident = (CUR.identOk ? CUR.ident : document.getElementById('manualIdent').value.trim());
    if (!ident) { if (!auto) toast('请先确认或输入学生的' + (CUR.idmode === 'no' ? '编号' : '座号')); return; }
    if (!auto && !Object.keys(CUR.answers).length && !confirm('该卡未识别出任何答案，确定仍要登记？')) return;
    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    var curAtReq = CUR;
    apiPost({ type: 'omr_submit', project_id: PROJECT_ID, template_id: TID, idtype: CUR.idmode, round: CURRENT_ROUND,
              ident: ident, answers: JSON.stringify(CUR.answers), flags: CUR.flags || '', pg: CUR.pg || 1 }, function (d) {
        btn.disabled = false;
        if (!d.success) {
            document.getElementById('resStudent').innerHTML = '<span class="flag-badge">' + esc(d.message) + '</span>';
            if (auto) toast('自动登记失败：' + d.message);
            return;
        }
        var st = d.student;
        if (CUR === curAtReq) {
            CUR.stu = st; drawOverlay();   // 登记成功同步回显学生标注
            saveAnnotatedImg(curAtReq, st, d);   // 仅登记成功才留存批注图（服务器/本机双通道）
        }
        document.getElementById('resStudent').innerHTML =
            '<span class="ok-badge">✓ ' + esc(st.class_name) + ' ' + esc(st.name) + '（' + esc(st.seat_no || '-') + ' / ' + esc(st.student_no || '-') + '）</span>'
            + (d.score ? '<span class="ok-badge">得分：' + esc(d.score) + '</span>' : '')
            + (d.already ? '<span class="flag-badge">该生此前已登记，本次结果已覆盖</span>' : '')
            + (d.pages_merged ? '<span class="ok-badge">已扫页：' + esc(d.pages_merged) + ' / ' + TPL.pages + '</span>' : '')
            + (TPL.pages > 1 && (CUR.pg || 1) < TPL.pages ? '<span class="flag-badge">请翻到第 ' + ((CUR.pg || 1) + 1) + ' 页继续扫描同一学生（各页结果自动合并）</span>' : '')
            + '<span style="font-size:12px;color:#888;">已登记 ' + d.registered_count + ' / ' + d.total + '</span>';
        loadRecords();
        toast((st.seat_no ? st.seat_no + '号' : '') + st.name + ' 登记成功' + (d.score ? '（' + d.score + '）' : '') + (stream ? '，可拍下一张' : ''));
    });
}

// ===== 🖼 批改图留存：登记成功后保存「矫正与识别预览」批注图 =====
// 已开启服务器权限（后台管理员 / 学校分角色授权 / 个人账号后台开关）→ 强制上传服务器留存（omr_images），失败才回退本机 IndexedDB 并提示；无权限仅存本机
function saveAnnotatedImg(cur, st, d) {
    var c = document.getElementById('rectCanvas');
    if (!c || !c.width || !c.height) return;
    var dataUrl;
    try { dataUrl = c.toDataURL('image/png'); } catch (e) { return; }
    var meta = { pid: PROJECT_ID, round: CURRENT_ROUND, pg: cur.pg || 1,
                 ident: (cur.identOk ? cur.ident : String(document.getElementById('manualIdent').value || '').trim()),
                 seat: String(st.seat_no || ''), name: String(st.name || ''), score: String(d.score || ''), ts: Date.now() };
    if (IMG_UPLOAD_OK) {
        apiPost({ type: 'omr_img_save', project_id: PROJECT_ID, round: meta.round, pg: meta.pg, ident: meta.ident,
                  student_id: st.id, name: meta.name, seat: meta.seat, score: meta.score, data: dataUrl }, function (r) {
            if (!r.success) {
                idbSaveImg(dataUrl, meta);   // 上传失败回退本机缓存
                toast('☁ 服务器保存失败（' + (r.message || '未知错误') + '），批改图已存本机缓存', 6000);
            }
        });
    } else {
        idbSaveImg(dataUrl, meta);
    }
}

// ===== 识别记录 =====
function loadRecords() {
    apiPost({ type: 'omr_results', project_id: PROJECT_ID }, function (d) {
        if (!d.success) { document.getElementById('recordList').innerHTML = '<p class="tip">' + esc(d.message) + '</p>'; return; }
        var items = (d.items || []).filter(function (r) { return r.round_no === CURRENT_ROUND; }).slice(0, 20);
        if (!items.length) { document.getElementById('recordList').innerHTML = '<p class="tip">暂无记录</p>'; return; }
        document.getElementById('recordList').innerHTML = items.map(function (r) {
            var stu = r.student_name ? esc(r.class_name + ' ' + r.student_name) : '<span style="color:#e67e22;">未匹配：' + esc(r.ident) + '</span>';
            return '<div class="rlist-item"><span style="flex:1;min-width:120px;">' + stu
                + '</span><b>' + esc(r.score || '-') + '</b>'
                + '<span style="color:#999;font-size:12px;">' + esc(r.updated_at) + '</span>'
                + '<button type="button" class="btn btn-sm btn-outline" style="padding:2px 8px;font-size:12px;" onclick="delRecord(' + r.id + ')">删</button></div>';
        }).join('');
    });
}
function delRecord(id) {
    if (!confirm('删除该条识别记录？（不影响已登记状态，可重新扫描覆盖）')) return;
    apiPost({ type: 'omr_result_del', project_id: PROJECT_ID, id: id }, function (d) {
        toast(d.message); loadRecords();
    });
}

// ===== 💾 批改图本机缓存（IndexedDB：库 qj_omr / store imgs；服务器留存失败或无权限时使用） =====
var _idbP = null;
function idb() {
    if (!_idbP) {
        _idbP = new Promise(function (res, rej) {
            var rq = indexedDB.open('qj_omr', 1);
            rq.onupgradeneeded = function () {
                var db = rq.result;
                if (!db.objectStoreNames.contains('imgs')) {
                    var st = db.createObjectStore('imgs', { keyPath: 'id', autoIncrement: true });
                    st.createIndex('pid', 'pid', { unique: false });
                }
            };
            rq.onsuccess = function () { res(rq.result); };
            rq.onerror = function () { rej(rq.error); };
        });
    }
    return _idbP;
}
function idbSaveImg(dataUrl, meta) {
    idb().then(function (db) {
        return new Promise(function (res, rej) {
            var tx = db.transaction('imgs', 'readwrite');
            tx.objectStore('imgs').add({ pid: meta.pid, round: meta.round, pg: meta.pg, ident: meta.ident,
                                         seat: meta.seat, name: meta.name, score: meta.score, ts: meta.ts, dataUrl: dataUrl });
            tx.oncomplete = res; tx.onerror = function () { rej(tx.error); };
        });
    }).catch(function () { /* 隐私模式等本机缓存失败：静默（登记本身不受影响） */ });
}
function idbList() {
    return idb().then(function (db) {
        return new Promise(function (res, rej) {
            var rq = db.transaction('imgs', 'readonly').objectStore('imgs').openCursor();
            var out = [];
            rq.onsuccess = function () {
                var c = rq.result;
                if (c) { if (c.value.pid === PROJECT_ID) out.push(c.value); c.continue(); }
                else res(out);
            };
            rq.onerror = function () { rej(rq.error); };
        });
    });
}
function idbDel(id) {
    return idb().then(function (db) {
        return new Promise(function (res, rej) {
            var tx = db.transaction('imgs', 'readwrite');
            tx.objectStore('imgs').delete(id);
            tx.oncomplete = res; tx.onerror = function () { rej(tx.error); };
        });
    });
}

// ===== 🖼 批改图库弹层（本机缓存 / 服务器两标签；单张查看/下载/删除 + 批量 zip 导出） =====
var IMG_TAB = IMG_UPLOAD_OK ? 'server' : 'local';   // 默认显示「☁ 服务器」（有权限时）；标签仅切换查看来源，保存路径不受影响
var IMG_ITEMS = [];      // 当前标签的规范化列表：{key, id, seat, name, round, pg, score, ts, server, dataUrl?}
var IMG_SEL = {};        // 勾选集合 key → true
function openImgGallery() {
    document.getElementById('imgModal').style.display = 'flex';
    switchImgTab(IMG_TAB === 'server' && !IMG_UPLOAD_OK ? 'local' : IMG_TAB);
}
function closeImgGallery() { document.getElementById('imgModal').style.display = 'none'; }
function switchImgTab(t) {
    IMG_TAB = t; IMG_SEL = {};
    document.getElementById('imgTabLocal').classList.toggle('on', t === 'local');
    if (document.getElementById('imgTabServer')) document.getElementById('imgTabServer').classList.toggle('on', t === 'server');
    var tipS = document.getElementById('imgTipServer'), tipL = document.getElementById('imgTipLocal');
    if (tipS) tipS.style.display = t === 'server' ? '' : 'none';
    if (tipL) tipL.style.display = t === 'local' ? '' : 'none';
    loadImgList();
}
function loadImgList() {
    var grid = document.getElementById('imgGrid');
    grid.innerHTML = '<p class="tip">加载中…</p>';
    if (IMG_TAB === 'server') {
        apiPost({ type: 'omr_img_list', project_id: PROJECT_ID }, function (d) {
            if (!d.success) { grid.innerHTML = '<p class="tip">' + esc(d.message) + '</p>'; return; }
            IMG_ITEMS = (d.items || []).map(function (it) {
                return { key: 's' + it.id, id: it.id, seat: it.seat, name: it.name, round: it.round_no, pg: it.pg,
                         score: it.score, ts: it.created_at, server: true, teacher: it.teacher };
            });
            renderImgGrid();
        });
    } else {
        idbList().then(function (items) {
            items.sort(function (a, b) { return (b.ts || 0) - (a.ts || 0); });
            IMG_ITEMS = items.map(function (it) {
                return { key: 'l' + it.id, id: it.id, seat: it.seat, name: it.name, round: it.round, pg: it.pg,
                         score: it.score, ts: it.ts, server: false, dataUrl: it.dataUrl };
            });
            renderImgGrid();
        }).catch(function () { grid.innerHTML = '<p class="tip">本机缓存读取失败</p>'; });
    }
}
function imgFileName(it) {
    var nm = (it.seat ? it.seat + '号_' : '未匹配_') + (it.name || '未名') + '_第' + (it.round || 1) + '题次';
    if ((it.pg || 1) > 1) nm += '_页' + it.pg;
    var t = it.ts ? new Date(it.ts) : new Date();
    if (isNaN(t.getTime())) t = new Date();
    function p2(n) { return (n < 10 ? '0' : '') + n; }
    nm += '_' + t.getFullYear() + p2(t.getMonth() + 1) + p2(t.getDate()) + p2(t.getHours()) + p2(t.getMinutes()) + p2(t.getSeconds());
    return nm.replace(/[\\/:*?"<>|]/g, '_') + '.png';
}
function imgSrc(it) {
    return it.server ? ('api.php?type=omr_img_get&project_id=' + PROJECT_ID + '&id=' + it.id) : it.dataUrl;
}
function renderImgGrid() {
    var grid = document.getElementById('imgGrid');
    if (!IMG_ITEMS.length) { grid.innerHTML = '<p class="tip">暂无批改图</p>'; return; }
    grid.innerHTML = IMG_ITEMS.map(function (it) {
        var t = it.ts ? new Date(it.ts) : null;
        var tstr = t && !isNaN(t.getTime()) ? (t.getFullYear() + '-' + p2(t.getMonth() + 1) + '-' + p2(t.getDate()) + ' ' + p2(t.getHours()) + ':' + p2(t.getMinutes())) : '';
        return '<div class="img-card">'
            + '<label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;"><input type="checkbox" ' + (IMG_SEL[it.key] ? 'checked' : '')
            + ' onchange="imgSel(\'' + it.key + '\', this.checked)" autocomplete="off"> ' + esc(imgFileName(it).replace(/\.png$/, '')) + '</label>'
            + '<img src="' + imgSrc(it) + '" alt="">'
            + '<div class="img-meta">' + esc(it.name || '未匹配') + (it.seat ? '（' + esc(it.seat) + '号）' : '')
            + (it.score ? ' · ' + esc(it.score) : '') + '<br>' + tstr + (it.server && it.teacher ? ' · ' + esc(it.teacher) : '') + '</div>'
            + '<div class="img-ops">'
            + '<button type="button" class="btn btn-sm btn-outline" style="padding:2px 8px;font-size:12px;" onclick="imgView(\'' + it.key + '\')">查看</button>'
            + '<button type="button" class="btn btn-sm btn-outline" style="padding:2px 8px;font-size:12px;" onclick="imgDownload(\'' + it.key + '\')">⬇</button>'
            + '<button type="button" class="btn btn-sm btn-outline" style="padding:2px 8px;font-size:12px;" onclick="imgDel(\'' + it.key + '\')">🗑</button>'
            + '</div></div>';
    }).join('');
    document.getElementById('imgSelAll').checked = IMG_ITEMS.every(function (it) { return IMG_SEL[it.key]; });
}
function p2(n) { return (n < 10 ? '0' : '') + n; }
function imgFind(key) { for (var i = 0; i < IMG_ITEMS.length; i++) if (IMG_ITEMS[i].key === key) return IMG_ITEMS[i]; return null; }
function imgSel(key, on) { if (on) IMG_SEL[key] = true; else delete IMG_SEL[key]; }
function imgSelToggleAll(on) { IMG_ITEMS.forEach(function (it) { if (on) IMG_SEL[it.key] = true; else delete IMG_SEL[it.key]; }); renderImgGrid(); }
function imgView(key) {
    var it = imgFind(key); if (!it) return;
    if (it.server) { window.open(imgSrc(it), '_blank'); return; }
    var u = URL.createObjectURL(dataUrlBlob(it.dataUrl));
    window.open(u, '_blank');
    setTimeout(function () { URL.revokeObjectURL(u); }, 60000);
}
function imgDownload(key) {
    var it = imgFind(key); if (!it) return;
    imgBlob(it).then(function (b) { downloadBlob(b, imgFileName(it)); });
}
function imgDel(key) {
    var it = imgFind(key); if (!it) return;
    if (!confirm('删除该批改图？（' + imgFileName(it) + '）')) return;
    if (it.server) {
        apiPost({ type: 'omr_img_del', project_id: PROJECT_ID, id: it.id }, function (d) { toast(d.message || ''); loadImgList(); });
    } else {
        idbDel(it.id).then(function () { loadImgList(); }).catch(function () { toast('删除失败'); });
    }
}
function imgBlob(it) {
    if (it.dataUrl) return Promise.resolve(dataUrlBlob(it.dataUrl));
    return fetch(imgSrc(it)).then(function (r) { if (!r.ok) throw new Error('加载失败'); return r.blob(); });
}
function dataUrlBlob(durl) {
    var b64 = durl.split(',')[1], bin = atob(b64), u8 = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) u8[i] = bin.charCodeAt(i);
    return new Blob([u8], { type: 'image/png' });
}
function downloadBlob(blob, name) {
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = name;
    document.body.appendChild(a); a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 3000);
}
function imgExportZip() {
    var sel = IMG_ITEMS.filter(function (it) { return IMG_SEL[it.key]; });
    var list = sel.length ? sel : IMG_ITEMS;
    if (!list.length) { toast('当前标签没有可导出的批改图'); return; }
    toast('正在打包 ' + list.length + ' 张…', 12000);
    Promise.all(list.map(function (it) {
        return imgBlob(it).then(function (b) {
            return b.arrayBuffer ? b.arrayBuffer() : new Promise(function (res) { var fr = new FileReader(); fr.onload = function () { res(fr.result); }; fr.readAsArrayBuffer(b); });
        }).then(function (buf) { return { name: imgFileName(it), u8: new Uint8Array(buf) }; });
    })).then(function (files) {
        downloadBlob(zipStore(files), '批改图_' + list.length + '张_' + Date.now() + '.zip');
        toast('已导出 ' + files.length + ' 张');
    }).catch(function () { toast('导出失败，请重试'); });
}

// ===== 🗜 store-only ZIP 打包（PNG 免压缩：CRC32 + 本地文件头 + central directory，无外部依赖） =====
var CRC_T = null;
function crcTable() {
    if (CRC_T) return CRC_T;
    CRC_T = new Uint32Array(256);
    for (var n = 0; n < 256; n++) {
        var c = n;
        for (var k = 0; k < 8; k++) c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
        CRC_T[n] = c >>> 0;
    }
    return CRC_T;
}
function crc32(u8) {
    var t = crcTable(), c = 0xFFFFFFFF;
    for (var i = 0; i < u8.length; i++) c = t[(c ^ u8[i]) & 0xFF] ^ (c >>> 8);
    return (c ^ 0xFFFFFFFF) >>> 0;
}
function zipStore(files) {   // files: [{name, u8:Uint8Array}] → Blob（store-only，UTF-8 文件名）
    var enc = new TextEncoder(), chunks = [], central = [], offset = 0;
    files.forEach(function (f) {
        var nameU8 = enc.encode(f.name), crc = crc32(f.u8), len = f.u8.length;
        var lh = new DataView(new ArrayBuffer(30));
        lh.setUint32(0, 0x04034b50, true);
        lh.setUint16(4, 20, true); lh.setUint16(6, 0x0800, true); lh.setUint16(8, 0, true);
        lh.setUint16(10, 0, true); lh.setUint16(12, 0, true);
        lh.setUint32(14, crc, true); lh.setUint32(18, len, true); lh.setUint32(22, len, true);
        lh.setUint16(26, nameU8.length, true); lh.setUint16(28, 0, true);
        chunks.push(new Uint8Array(lh.buffer), nameU8, f.u8);
        var ch = new DataView(new ArrayBuffer(46));
        ch.setUint32(0, 0x02014b50, true);
        ch.setUint16(4, 20, true); ch.setUint16(6, 20, true); ch.setUint16(8, 0x0800, true); ch.setUint16(10, 0, true);
        ch.setUint16(12, 0, true); ch.setUint16(14, 0, true);
        ch.setUint32(16, crc, true); ch.setUint32(20, len, true); ch.setUint32(24, len, true);
        ch.setUint16(28, nameU8.length, true);
        ch.setUint32(42, offset, true);
        central.push(new Uint8Array(ch.buffer), nameU8);
        offset += 30 + nameU8.length + len;
    });
    var cdSize = central.reduce(function (s, u) { return s + u.length; }, 0);
    var eocd = new DataView(new ArrayBuffer(22));
    eocd.setUint32(0, 0x06054b50, true);
    eocd.setUint16(8, files.length, true); eocd.setUint16(10, files.length, true);
    eocd.setUint32(12, cdSize, true); eocd.setUint32(16, offset, true);
    return new Blob(chunks.concat(central, [new Uint8Array(eocd.buffer)]), { type: 'application/zip' });
}

renderAutoReg();
renderAutoBtn();
loadTplList();
loadRecords();
// 进入页面自动开启摄像头（无需手动点「开始扫码 图片识别」，按钮保留作不时之需）：
// 无设备时提示并保留按钮；现场插入设备后自动重开（用户主动关闭过则不打扰）
refreshCamList(true).then(function (ds) {
    if (!ds.length) toast('未检测到摄像设备，请确认后重试', 5000);
    else startCam();
});
if (navigator.mediaDevices && navigator.mediaDevices.addEventListener) {
    navigator.mediaDevices.addEventListener('devicechange', function () {
        refreshCamList(true).then(function (ds) {
            if (!ds.length) toast('未检测到摄像设备，请确认后重试', 5000);
            else if (!stream && !camBusy && !userStoppedCam) startCam();
        });
    });
}
</script>
<?php
page_help('答题卡识别', [
    ['h' => '识别流程', 'items' => [
        '① 选择答题卡模板（在设计器中保存的模板）',
        '② 打开摄像头对准答题卡拍照，或上传答题卡照片（可多张连拍）',
        '③ 系统自动定位四角黑方块、透视矫正、逐框判定填涂并登记',
        '④ 多页模板在矫正定稿后按页头点计数复判页码：扫到其他页的卡会提示实际页数（后页卡错入当前题次必拒）',
    ]],
    ['h' => '提高识别率', 'items' => [
        '四角黑色定位方块必须完整入镜；答题卡尽量占满画面',
        '光线充足均匀，避免阴影与反光；手机拍摄建议正上方俯拍',
        '学生须将方框涂满涂黑；修改要擦干净，避免重涂误判',
        '识别不准时可调节「填涂判定阈值」重新判定（结果芯片也可点击修正）',
    ]],
    ['h' => '身份与登记', 'items' => [
        '学生涂「座号」或「编号」定位身份（取决于模板设置），支持前导零',
        '同一位学生重复扫描会覆盖上一次识别结果；登记成功自动写入项目',
        '模板配置了答案键时，登记后自动计算得分并同步到项目评价值',
    ]],
    ['h' => '答案与批改', 'items' => [
        '生效答案=模板答案键最优先；「🔑 录入答案」仅可补录模板未设答案的题（模板已设的题锁定）',
        '答案覆盖全部题目→自动批改；不完整时默认仅登记，可勾选「⚡ 强制批改」按已有答案的题批改（其余题只记录选择）',
        '未设分值：得分显示正确率（对题数/题数）；在设计器「💰 分值设置」中设分值后改显「得分 nn/总分 xx」',
    ]],
]);
?>
<?php $rev_cid = intval($project['class_id'] ?? 0); if ($rev_cid): ?>
<!-- 教师在线心跳 + 反向喊话反馈浮窗：打开本页（答题卡识别）也算教师在线；收到学生回复右上浮窗 3 秒自动消失 -->
<script src="assets/js/rev_notify.js?v=1"></script>
<script>revNotify(<?php echo $rev_cid; ?>, 'toast', { project_id: <?php echo intval($project_id); ?> });</script>
<?php endif; ?>
<?php page_footer();
