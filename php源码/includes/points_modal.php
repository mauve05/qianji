<?php
/**
 * 课堂表现积分弹窗（教师端，projects.php / project_view.php 共用）
 * 参考「课堂表现积分系统」：多维度积分项一键加减分（单人/批量勾选）、
 * 每条流水自动记录时间/操作教师/备注、积分排行与维度占比汇总、积分项自定义。
 * 依赖调用方：openModal/closeModal 函数（未提供时本文件注入兜底版）。
 * 调用方式：$pt_class_id = <班级id>; include __DIR__ . '/points_modal.php';
 *   可选：$pt_class_name = <班级显示名>（标题用，缺省不显示）。
 * 使用前需确认教师对该班级有 can_manage_class 权限（服务端 points_* 接口会二次校验）。
 */
$pt_cid = intval($pt_class_id ?? 0);
if (!$pt_cid) return;
$pt_cname = trim(strval($pt_class_name ?? ''));
// 可选：宿主（project_view.php）传入当前作业的登记/评价状态，「⚡ 快速加分」可按状态筛选学生
//   $pt_reg_map   = [学生id => 1] 已登记（打卡模式=今日已打卡）
//   $pt_eval_map  = [学生id => 评价内容] 已评价（答题/举牌模式=所选选项 A/B/C/D）
//   $pt_eval_opts = [评价内容…]（评价模式预设选项 + 实际用到的值，筛框自动罗列「评价为 ××」）
// 未传入 $pt_reg_map 时（projects.php 无作业上下文）筛选下拉框整体不渲染
$pt_has_filter = isset($pt_reg_map) && is_array($pt_reg_map);
$pt_reg_map    = $pt_has_filter ? $pt_reg_map : [];
$pt_eval_map   = (isset($pt_eval_map) && is_array($pt_eval_map)) ? $pt_eval_map : [];
$pt_eval_opts  = (isset($pt_eval_opts) && is_array($pt_eval_opts)) ? array_values($pt_eval_opts) : [];
// 可选：宿主（project_view.php）传入当前项目 id → ⚙ 设置中可编辑该项目的「🤖 自动加减分规则」、
//       积分流水中自动规则记录加 🤖 标注；projects.php 不传时 ⚙ 中改为下拉选择有权限的项目
$pt_project_id = intval($pt_project_id ?? 0);
// 只读模式（宿主传 $pt_readonly=true，如仅查看成员）：仅保留「📋 积分流水 / 📊 汇总分析」查看页签，
// 隐藏 ⚡快速加分 / 🎁兑换 / ⚙设置 / 📒记录；加减分/设规则等写操作后端同样拒绝（仅登记及以上才可操作）
$pt_readonly = !empty($pt_readonly);
// 设置权限（宿主传 $pt_cfg）：⚙ 积分项（全校共享）与 🤖 自动规则仅「管理员 / 可建立」级别可设置；
// 仅登记可加减积分但不可设置规则（🤖 规则保存后端需项目管理权限，积分项保存后端同判）
$pt_cfg = isset($pt_cfg) ? !empty($pt_cfg) : !$pt_readonly;
?>
<style>
/* 课堂积分弹窗局部样式（pt 前缀，不影响宿主） */
#ptModal .pt-stu { display:inline-flex; flex-direction:column; align-items:center; justify-content:center; gap:1px;
    width:76px; min-height:58px; margin:3px; padding:5px 4px; border:2px solid #dfe6f0; border-radius:10px;
    background:#fff; cursor:pointer; user-select:none; position:relative; transition:border-color .12s, background .12s; }
#ptModal .pt-stu:hover { border-color:#667eea; }
#ptModal .pt-stu.on { border-color:#27ae60; background:#eafaf1; }
#ptModal .pt-stu .nm { font-size:14px; font-weight:bold; color:#333; max-width:70px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
#ptModal .pt-stu .st { font-size:11px; color:#999; }
#ptModal .pt-stu .tt { font-size:11px; padding:0 6px; border-radius:8px; background:#f0f2f5; color:#888; }
#ptModal .pt-stu .tt.pos { background:#eafaf1; color:#27ae60; }
#ptModal .pt-stu .tt.neg { background:#fdecea; color:#e74c3c; }
#ptModal .pt-item-btn { display:inline-flex; align-items:center; gap:5px; border:0; border-radius:10px;
    padding:10px 16px; margin:4px; font-size:15px; font-weight:bold; color:#fff; cursor:pointer; position:relative; }
#ptModal .pt-item-btn:hover { opacity:.88; }
/* 积分项类型甄别图标（按钮右上角：👤=个人加分 👥=小组加分） */
#ptModal .pt-item-btn .pt-ico { position:absolute; top:-8px; right:-6px; width:20px; height:20px; line-height:19px;
    font-size:12px; background:#fff; border-radius:50%; box-shadow:0 1px 3px rgba(0,0,0,.25); text-align:center; }
/* 积分项（一键加减分）按钮：缩小到与工具行按钮（顺序/随机抽选/全选）一致大小，颜色仍按积分项设置 */
#ptItemBtns .pt-item-btn { padding:4px 10px; margin:3px; font-size:13px; border-radius:8px; gap:4px; }
#ptItemBtns .pt-item-btn .pt-ico { top:-6px; right:-4px; width:15px; height:15px; line-height:14px; font-size:9px; }
/* 全屏随机抽选（嵌在 ptModal 内以复用 .pt-item-btn 样式；fixed 覆盖全屏） */
#ptLotMask { display:none; position:fixed; inset:0; background:rgba(44,48,68,.62); z-index:2000; align-items:center; justify-content:center; }
#ptLotCard { background:#fff; border-radius:24px; width:min(92vw,640px); max-height:92vh; overflow-y:auto;
    padding:24px 28px 22px; text-align:center; box-shadow:0 18px 60px rgba(0,0,0,.35); }
#ptLotTitle { font-size:22px; font-weight:bold; color:#4a4f63; margin-bottom:6px; }
.pt-lot-big { font-size:clamp(54px,11vw,104px); font-weight:600; line-height:1.3; color:#3a3f52; letter-spacing:2px; word-break:break-all; }
#ptLotRoll { min-height:140px; display:flex; align-items:center; justify-content:center; }
#ptLotDone .pt-lot-res { border:3px solid #f8b8c6; border-radius:18px; background:#fff; padding:14px 18px 18px; }
#ptLotGrp { font-size:17px; font-weight:bold; color:#7fb3e8; min-height:24px; }
#ptLotStu { color:#f2799b; }
#ptLotTotal { font-size:13px; color:#999; }
#ptLotBtns { display:flex; flex-wrap:wrap; justify-content:center; margin-top:10px; }
#ptLotBtns .pt-item-btn { font-size:16px; padding:10px 18px; }
#ptLotMsg { display:none; margin-top:10px; padding:7px 10px; border-radius:8px; font-size:14px; }
#ptLotActs { margin-top:16px; display:flex; justify-content:center; gap:12px; }
.pt-lot-btn { border:0; border-radius:999px; padding:10px 26px; font-size:16px; font-weight:bold; cursor:pointer; color:#fff; }
.pt-lot-btn:hover { opacity:.88; }
.pt-lot-btn.stop { background:#7fc9a8; }
.pt-lot-btn.close { background:#f08d8d; }
/* 长按学生卡片 → 单人加减分面板 /「📒 记录」抽中记录弹层（均嵌在 ptModal 内复用 .pt-item-btn 样式） */
#ptPressMask, #ptRecMask { display:none; position:fixed; inset:0; background:rgba(44,48,68,.5); z-index:2100; align-items:center; justify-content:center; }
#ptPressCard, #ptRecCard { background:#fff; border-radius:16px; width:min(92vw,420px); max-height:86vh; overflow-y:auto;
    padding:16px 18px; text-align:center; box-shadow:0 12px 40px rgba(0,0,0,.3); }
#ptPressName { font-size:19px; font-weight:bold; color:#333; }
#ptPressSub { font-size:12px; color:#999; margin:2px 0 8px; }
.pt-press-sec { text-align:left; margin:10px 0 2px; font-size:13px; font-weight:bold; }
.pt-press-sec.pos { color:#27ae60; }
.pt-press-sec.neg { color:#e74c3c; }
#ptPressPos, #ptPressNeg { display:flex; flex-wrap:wrap; justify-content:center; }
#ptRecList { max-height:42vh; overflow-y:auto; border:1px solid #e5e5e5; border-radius:8px; padding:6px 10px; margin:8px 0; text-align:left; }
.pt-rec-row { display:flex; align-items:center; gap:8px; padding:5px 2px; border-bottom:1px dashed #eef1f6; font-size:13px; }
#ptModal .pt-log-row { display:flex; align-items:center; gap:8px; padding:7px 4px; border-bottom:1px dashed #eef1f6; font-size:13px; flex-wrap:wrap; }
#ptModal .pt-val { font-weight:bold; padding:1px 8px; border-radius:8px; }
#ptModal .pt-val.pos { background:#eafaf1; color:#27ae60; }
#ptModal .pt-val.neg { background:#fdecea; color:#e74c3c; }
#ptModal .pt-bar-wrap { display:flex; align-items:center; gap:8px; margin:4px 0; font-size:13px; }
#ptModal .pt-bar { height:16px; border-radius:4px; background:#667eea; min-width:2px; }
#ptModal .pt-cfg-row { display:flex; gap:6px; align-items:center; margin:5px 0; }
#ptModal .pt-cfg-row input[type="text"] { flex:2; min-width:0; }
#ptModal .pt-cfg-row input[type="number"] { flex:1; min-width:0; width:64px; }
/* 小屏：弹窗主体占满屏宽、积分项行允许换行，避免右侧勾选/删除被裁掉。
   注意：#ptModal 是全屏遮罩（inset:0），绝不能压它的宽度——曾把 width:94vw 设到遮罩上，
   遮罩被压成 94vw 锚定左侧、弹窗在剩余空间里居中 → 整个弹窗「歪」向一边 */
@media (max-width: 640px) {
    #ptModal .modal { max-width:94vw !important; width:94vw; }
    #ptModal .pt-cfg-row { flex-wrap:wrap; }
    #ptModal .pt-cfg-row input[type="text"] { flex:1 1 120px; }
    #ptModal .pt-cfg-row input[type="number"] { flex:0 0 72px; }
}
#ptModal .pt-tab-body { max-height:46vh; overflow-y:auto; }
/* 座位 / 分组视图（布局口径与大屏 project_view.php 座位模式一致：讲台在上，分组数×每组列数网格） */
#ptModal .pt-stage { border:1px solid #e3e6f0; border-radius:10px; padding:8px; background:#f8f9fd; }
#ptModal .pt-podium { background:linear-gradient(180deg, #8d9bd8, #667eea); color:#fff; text-align:center; border-radius:8px;
    padding:5px 0; font-weight:bold; letter-spacing:12px; text-indent:12px; font-size:13px; margin-bottom:8px; }
#ptModal .pt-seat-groups { display:flex; flex-wrap:wrap; gap:8px; }
#ptModal .pt-seat-panel { flex:1 1 170px; min-width:160px; border:1px solid #e0e3ef; border-radius:10px; background:#fff; padding:6px; }
#ptModal .pt-seat-head { font-size:12px; font-weight:bold; color:#5568d3; text-align:center; margin-bottom:6px; }
#ptModal .pt-seat-grid { display:grid; gap:6px; }
#ptModal .pt-seat-cell { min-height:62px; border:2px dashed #d6daea; border-radius:8px; display:flex; align-items:center; justify-content:center; background:#fbfcff; }
#ptModal .pt-seat-cell .pt-stu { width:100%; margin:0; border-width:0; }
#ptModal .pt-seat-cell .pt-stu.on { border-width:2px; }
#ptModal .pt-seat-empty-txt { color:#c3c9de; font-size:12px; }
#ptModal .pt-grp-panel { flex:1 1 200px; min-width:180px; border:1px solid #e0e3ef; border-radius:10px; background:#fbfcff; padding:8px; }
#ptModal .pt-unpool-head { font-size:12px; color:#888; margin:8px 0 4px; }
#ptModal .pt-unpool-head b { color:#e67e22; }
/* 随机点名滚动高亮 / 中选效果 */
#ptModal .pt-stu.pt-roll { border-color:#f39c12; background:#fef5e7; }
#ptModal .pt-stu.pt-roll-win { border-color:#e67e22; background:#fdebd0; box-shadow:0 0 0 3px rgba(230,126,34,.35); }
#ptModal .pt-seat-head .pt-sel-link { font-weight:normal; font-size:11px; color:#667eea; margin-left:6px; }
#ptModal .pt-seat-head.pt-roll { color:#e67e22; }
#ptModal .pt-grp-panel.pt-roll-win, #ptModal .pt-seat-panel.pt-roll-win { border-color:#e67e22; box-shadow:0 0 0 3px rgba(230,126,34,.25); }
/* 🎁 积分兑换：礼品条（点击选中 → 确认兑换即扣分）+ 管理小链接 */
#ptModal .pt-gift-chip { display:inline-flex; align-items:center; gap:4px; border:1px solid #dfe6f0; border-radius:16px; padding:4px 12px; font-size:13px; cursor:pointer; background:#fff; user-select:none; }
#ptModal .pt-gift-chip:hover { border-color:#e67e22; }
#ptModal .pt-gift-chip.on { border-color:#e67e22; background:#fef5e7; }
#ptModal .pt-gift-chip b { color:#e67e22; }
#ptModal .pt-gift-op { color:#999; font-size:12px; margin-left:2px; text-decoration:none; }
#ptModal .pt-gift-op:hover { color:#e67e22; }
</style>

<div class="modal-mask" id="ptModal">
    <div class="modal" style="max-width:680px;max-height:92vh;overflow-y:auto;">
        <h3>⭐ 课堂积分<?php echo $pt_cname !== '' ? ' · ' . htmlspecialchars($pt_cname) : ''; ?></h3>

        <!-- 选项卡：快速加分 / 汇总分析（流水并入汇总分析；只读模式仅保留汇总分析） -->
        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
            <?php if (!$pt_readonly): ?>
            <button type="button" id="ptTabBtnGive" class="btn btn-sm" onclick="ptTab('give')">⚡ 加分</button>
            <button type="button" id="ptTabBtnRedeem" class="btn btn-sm btn-outline" onclick="ptTab('redeem')">🎁 兑换</button>
            <?php endif; ?>
            <button type="button" id="ptTabBtnStat" class="btn btn-sm btn-outline" onclick="ptTab('stat')">📊 汇总</button>
            <?php if ($pt_cfg): ?>
            <button type="button" id="ptTabBtnCfg" class="btn btn-sm btn-outline" onclick="ptTab('cfg')" style="margin-left:auto;">⚙ 设置</button>
            <button type="button" id="ptTabBtnRec" class="btn btn-sm btn-outline" onclick="ptRecOpen()" title="抽中记录：未清空前，已抽中的学生不会再被抽中">📒 记录</button>
            <?php endif; ?>
        </div>
        <div id="ptMsg" style="display:none;margin-top:8px;padding:8px 10px;border-radius:8px;font-size:13px;"></div>

        <!-- Tab1：快速加分（点学生→点积分项，5 秒完成；支持多选批量） -->
        <?php if (!$pt_readonly): ?>
        <div id="ptTabGive" style="margin-top:10px;">
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:6px;">
                <b style="font-size:14px;">⚡ 快速加分</b>
                <button type="button" class="hint-q" onclick="toggleHint(event, '<b>快速加分操作指引</b><br>· ① 点击学生卡片选中（可多选批量）→ ② 点下方积分项按钮即完成打分<br>· <b>长按</b>学生卡片弹出单人加减分面板（加分 / 减分上下分组，全部积分项均可用，面板不关可连续打多项）<br>· 「🔢 顺序」点击循环切换 顺序 / 座位 / 分组 三种视图<br>· 下拉筛框按当前作业登记 / 评价状态筛选：已登记、未登记、已评价、未评价、评价为××（选项按评价内容自动罗列）<br>· 「☑ 全选」只选中当前筛出的学生，配合筛框可一键给未登记 / 未评价等群体批量打分<br>· 「🎯 随机小组」随机抽一组自动全选；「🎰 随机抽选」全屏大字滚动点名，抽中名单见「📒 记录」<br>· 备注框内容随本次打分一起记录；每条流水自动记时间 / 操作教师 / 备注')">?</button>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0;">
                <button type="button" id="ptViewBtn" class="btn btn-sm btn-outline" onclick="ptCycleView()" title="学生区视图切换（点击循环）：顺序 → 座位 → 分组">🔢 顺序</button>
                <select id="ptFilter" class="form-control" style="display:none;width:auto;flex:none;font-size:13px;padding:5px 8px;" onchange="ptFilterSet(this.value)" title="按当前作业的登记 / 评价状态筛选学生（「☑ 全选」只选筛出的学生）"></select>
                <!--
                <button type="button" id="ptRollGrpBtn" class="btn btn-sm" style="background:#8e44ad;" onclick="ptRollGroup()" title="随机抽取一个小组并自动全选该组成员，点下方积分项即为全组加分/减分">🎯 随机小组</button>
                -->
                <button type="button" id="ptLotBtn" class="btn btn-sm" style="background:#e7598b;" onclick="ptLotOpen()" title="全屏随机抽选：学生名字大屏滚动 5 秒自动停止（也可点停止），可按积分项给个人/小组加减分">🎰 抽选</button>
                <button type="button" class="btn btn-sm btn-outline" onclick="ptAll()">☑ 全选</button>
                <button type="button" class="btn btn-sm btn-outline" onclick="ptClear()">🧹 清空</button>
                <b id="ptSelCnt" style="font-size:13px;color:#667eea;">已选 0 人</b>
                <input type="text" id="ptRemark" class="form-control" placeholder="备注（可选，随本次打分记录）" maxlength="200" style="flex:1;min-width:180px;font-size:13px;" autocomplete="nope" readonly onfocus="this.removeAttribute('readonly')" title="点击即可输入（浏览器不会自动填充只读输入框）">
            </div>
            <div id="ptGiveGridWrap">
                <div id="ptStuGrid" style="max-height:30vh;overflow-y:auto;border:1px solid #e5e5e5;border-radius:8px;padding:6px;text-align:center;">
                    <span class="form-hint">加载中…</span>
                </div>
            </div>
            <div style="margin-top:10px;">
                <div class="form-hint" style="margin-bottom:4px;">积分项（一键加减分）：<button type="button" class="hint-q" onclick="toggleHint(event, '<b>积分项说明</b><br>· 按钮右上角图标甄别类型：<b>👤 个人</b>=给选中学生加减分、<b>👥 小组</b>=给选中学生所在组每人加减分<br>· 显示哪些积分项在「⚙ 设置」中勾选「加分 / 抽选」')">?</button></div>
                <div id="ptItemBtns" style="display:flex;flex-wrap:wrap;"></div>
            </div>
        </div>
        <?php endif; // !$pt_readonly：快速加分面板结束 ?>

        <!-- Tab2：🎁 积分兑换（点礼品→勾学生→确认扣分；流水记「🎁 兑换」；教师可现场增删改礼品） -->
        <?php if (!$pt_readonly): ?>
        <div id="ptTabRedeem" style="display:none;margin-top:10px;">
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:6px;">
                <b style="font-size:14px;">🎁 积分兑换</b>
                <span id="ptGiftInfo" style="font-size:12px;color:#999;">先点选礼品，再在下方勾选学生</span>
                <button type="button" class="hint-q" onclick="toggleHint(event, '<b>积分兑换操作指引</b><br>· ① 点选一个礼品条（✎ 改名称积分 / ✕ 删除；「＋ 添加礼品」新增）<br>· ② 在下方学生区勾选要兑换的学生（复用「⚡ 快速加分」的学生卡片，可多选）<br>· ③ 点「🎁 确认兑换」：余额足够者自动扣掉相应积分，流水记「🎁 兑换」；余额不足者自动跳过并提示<br>· 兑换流水可在「📋 积分流水」中撤销（误点可回退）')">?</button>
            </div>
            <div id="ptGiftBar" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0;"></div>
            <div id="ptGiftForm" style="display:none;border:1px solid #dfe6f0;border-radius:8px;background:#fbfcff;padding:10px;margin-bottom:8px;">
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="text" id="ptGiftName" class="form-control" placeholder="礼品名称（如：免作业券）" maxlength="50" style="flex:1;min-width:160px;font-size:13px;" autocomplete="nope" readonly onfocus="this.removeAttribute('readonly')" title="点击即可输入（浏览器不会自动填充只读输入框）">
                    <input type="number" id="ptGiftCost" class="form-control" placeholder="积分" min="1" max="100000" style="width:90px;flex:none;font-size:13px;" autocomplete="off">
                    <button type="button" class="btn btn-sm" style="background:#27ae60;" onclick="ptGiftSave()">💾 保存</button>
                    <button type="button" class="btn btn-sm btn-outline" onclick="ptGiftNew(0)">取消</button>
                </div>
            </div>
            <div id="ptRedeemWrap" style="margin-bottom:10px;"></div>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <button type="button" class="btn" style="background:#e67e22;" onclick="ptRedeemGo()">🎁 确认兑换</button>
                <span class="form-hint">兑换规则<button type="button" class="hint-q" onclick="toggleHint(event, '<b>积分兑换规则</b><br>· 余额足够自动扣分，不足者自动跳过并提示<br>· 兑换记录可在「📋 积分流水」撤销')">?</button></span>
            </div>
        </div>
        <?php endif; // !$pt_readonly：兑换面板结束 ?>

        <!-- Tab3：汇总分析（查看对象下拉：全班=排行+维度占比+全班流水；学生=维度占比环形图+得分趋势折线+个人流水；支持时间段筛选） -->
        <div id="ptTabStat" style="display:none;margin-top:10px;">
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
                <b style="font-size:14px;">查看对象：</b>
                <div style="position:relative;flex:none;">
                    <input type="text" id="ptStatQ" name="pt_stat_q" class="form-control" value="全班" autocomplete="nope" readonly
                           onfocus="this.removeAttribute('readonly');ptStatQFocus(this)" oninput="ptStatQFilter(this.value)"
                           onblur="setTimeout(function(){ptStatQHide();ptStatFill();}, 180)"
                           style="width:170px;font-size:13px;padding:5px 24px 5px 8px;" placeholder="全班 / 座号 / 姓名"
                           title="Chrome 不会向只读输入框自动填充；点击即可正常输入（同 project_view.php stuKwBox 处理）">
                    <span id="ptStatQClr" style="display:none;position:absolute;right:6px;top:50%;transform:translateY(-50%);cursor:pointer;color:#999;font-size:13px;" onclick="ptStatPick(0)" title="回到全班">✕</span>
                    <div id="ptStatQList" style="display:none;position:absolute;top:100%;left:0;width:220px;max-width:70vw;z-index:60;max-height:220px;overflow-y:auto;background:#fff;border:1px solid #d9dee8;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.15);font-size:13px;"></div>
                </div>
                <button type="button" class="hint-q" onclick="toggleHint(event, '<b>汇总分析说明</b><br>· <b>查看对象</b>：可输入座号 / 姓名筛选学生，点选后查看个人统计；✕ 或选「全班」返回<br>· <b>全班</b>=积分排行（前 20，点击行可直接查看该生统计）+ 各维度得分（📉条形 / 🥧饼图可切换）+ 全班最近 50 条流水<br>· <b>学生</b>=各维度得分占比（🥧环形 / 📉条形可切换）+ 得分趋势折线（「📅 每天」当日净得分 / 「📈 累计」逐日累加）+ 该生全部积分流水（可撤销本人操作）<br>· <b>点击筛选</b>：点击环形图扇区 / 图例项=只看该维度流水；点击趋势折线节点=只看当日流水；筛选可叠加，再点同项或点 × 取消<br>· 时间段筛选（近 7 天 / 近 30 天 / 全部）对全班与个人同时生效')">?</button>
                <b style="font-size:14px;margin-left:4px;">时间段：</b>
                <button type="button" id="ptRgWeek" class="btn btn-sm btn-outline" onclick="ptRange('week')">近 7 天</button>
                <button type="button" id="ptRgMonth" class="btn btn-sm btn-outline" onclick="ptRange('month')">近 30 天</button>
                <button type="button" id="ptRgAll" class="btn btn-sm" onclick="ptRange('all')">全部</button>
            </div>
            <!-- 全班视图：排行 + 维度得分 + 全班流水 -->
            <div id="ptStatAll">
                <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start;">
                    <div style="flex:1;min-width:260px;">
                        <b style="font-size:14px;">🏅 积分排行（前 20）</b>
                        <div id="ptRank" class="pt-tab-body" style="border:1px solid #e5e5e5;border-radius:8px;padding:8px 10px;margin-top:6px;"></div>
                    </div>
                    <div style="flex:1;min-width:220px;">
                        <div style="display:flex;align-items:center;gap:6px;">
                            <b style="font-size:14px;">📐 各维度得分</b>
                            <span style="margin-left:auto;display:inline-flex;gap:3px;">
                                <button type="button" id="ptDcBar" class="btn btn-sm btn-outline" style="padding:1px 8px;font-size:12px;" onclick="ptDimChart('bar')" title="条形图">📉</button>
                                <button type="button" id="ptDcPie" class="btn btn-sm" style="padding:1px 8px;font-size:12px;" onclick="ptDimChart('pie')" title="饼图">🥧</button>
                            </span>
                        </div>
                        <div id="ptDims" class="pt-tab-body" style="border:1px solid #e5e5e5;border-radius:8px;padding:8px 10px;margin-top:6px;"></div>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:8px;margin:12px 0 6px;">
                    <b style="font-size:14px;">全班积分流水（最近 50 条）</b>
                    <button type="button" class="btn btn-sm btn-outline" onclick="ptLoad()" style="margin-left:auto;">🔄 刷新</button>
                </div>
                <div id="ptLogList" class="pt-tab-body" style="border:1px solid #e5e5e5;border-radius:8px;padding:6px 10px;"></div>
            </div>
            <!-- 个人视图：环形占比 + 得分趋势（每天 / 累计）+ 个人流水（JS 渲染） -->
            <div id="ptStatStu" style="display:none;"></div>
        </div>

        <!-- 设置：积分项管理（名称/分值/颜色，全校共享；保存后全班通用） -->
        <?php if ($pt_cfg): ?>
        <div id="ptTabCfg" style="display:none;margin-top:10px;">
            <div style="margin-bottom:6px;font-size:13px;color:#8a8fa3;">⚙ 积分项管理<button type="button" class="hint-q" onclick="toggleHint(event, '<b>积分项设置说明</b><br>· 积分项<b>全校共享</b>（如：课堂纪律、课堂互动、任务完成…），最多 20 项<br>· <b>👤 个人</b>=给选中学生加减分；<b>👥 小组</b>=给选中学生所在组每人加减分<br>· 勾选「抽选 / 加分」控制该项是否显示在「🎰 随机抽选」与「⚡ 快速加分」中（隐藏的项仍可在长按学生卡片的面板中使用）<br>· <b>分值为负即扣分项</b>（如：课堂违纪 −1）<br>· 历史流水保存项名快照，删项不影响旧记录')">?</button></div>
            <div id="ptCfgList"></div>
            <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
                <button type="button" class="btn btn-sm btn-outline" onclick="ptCfgAdd()">＋ 添加积分项</button>
                <button type="button" class="btn btn-sm" style="background:#27ae60;margin-left:auto;" onclick="ptCfgSave()">💾 保存积分项</button>
            </div>
            <!-- 🤖 项目自动加减分规则：project_view 固定本项目；projects.php 下拉选择有权限的项目 -->
            <div id="ptAutoBlock" style="margin-top:14px;border-top:1px dashed #e0e0e0;padding-top:10px;">
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:6px;">
                    <b style="font-size:13px;color:#8a8fa3;">🤖 自动加减分规则</b>
                    <span id="ptAutoProj" style="font-size:12px;color:#999;"></span>
                    <button type="button" class="hint-q" onclick="toggleHint(event, '<b>🤖 自动加减分规则说明</b><br>· 规则按<b>项目</b>配置，只对该项目的登记 / 评价生效；规则类型按项目登记模式自动过滤（答题模式才有「答题正确 / 错误」、答题卡才有得分类规则）<br>· <b>每次登记 / 评价独立计分</b>：打卡=每天一条、多次 / 答题 / 举牌=每题次一条、答题卡=每题次一条<br>· 「评价为指定内容」的评价内容只能从<b>下拉选择</b>（评价模式预设选项 + 项目实际用到的评价值），不能手动输入<br>· 「超时未登记」需项目开启补登记阈值（无人登记的范围不判定；判定时刻=该范围首位登记 + 阈值）<br>· <b>💾 保存后立即全量重算</b>：已按旧规则加的自动回档、未应用的直接补足；之后的登记 / 评价实时按规则计分<br>· 🤖 流水不可单独撤销——想调整就改规则再保存重算')">?</button>
                </div>
                <div id="ptAutoProjPick" style="display:none;margin-bottom:6px;">
                    <select id="ptAutoProjSel" class="form-control" style="width:auto;max-width:100%;font-size:13px;" onchange="ptAutoProjChange()"></select>
                </div>
                <div id="ptAutoList"></div>
                <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
                    <button type="button" class="btn btn-sm btn-outline" onclick="ptAutoAdd()">＋ 增加规则</button>
                    <button type="button" class="btn btn-sm" style="background:#8e44ad;margin-left:auto;" onclick="ptAutoSave()">💾 保存并重算积分</button>
                </div>
            </div>
            <!-- 📥 导入 / 导出 / 清空：数据迁移（导出直链下载；导入与清空需输入当前账号登录密码防误触） -->
            <div id="ptIoBlock" style="margin-top:14px;border-top:1px dashed #e0e0e0;padding-top:10px;">
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:6px;">
                    <b style="font-size:13px;color:#8a8fa3;">📥 导入 / 导出 / 清空</b>
                    <button type="button" class="hint-q" onclick="toggleHint(event, '<b>导入 / 导出说明</b><br>· <b>⬇ 导出最终积分</b>：每生当前总积分（CSV：编号,姓名,座号,当前积分），可在另一班/新学期导入作为起始分<br>· <b>⬇ 导出过程流水</b>：全部增减明细（时间,姓名,编号,积分项,分值,备注,来源），导入后按原时间回放自动算出结果<br>· <b>⬆ 导入</b>：粘贴或选 CSV 文件；「最终积分」每生记一条「积分导入」流水（谁什么时候导入多少分）；「过程流水」逐条按原时间写入<br>· 学生按<b>编号</b>精确匹配，编号对不上再按<b>姓名</b>匹配（本班重名视为歧义跳过）<br>· 「先清空本班现有积分」勾选后先删光本班流水再导入（危险操作，同样需密码）<br>· 导入与清空均需输入<b>当前账号登录密码</b>防误触')">?</button>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                    <a class="btn btn-sm btn-outline" href="api.php?type=points_export&kind=balance&class_id=<?php echo intval($pt_cid); ?>" title="每生当前总积分（编号,姓名,座号,当前积分）">⬇ 导出最终积分</a>
                    <a class="btn btn-sm btn-outline" href="api.php?type=points_export&kind=log&class_id=<?php echo intval($pt_cid); ?>" title="全部增减明细（时间,姓名,编号,积分项,分值,备注,来源）">⬇ 导出过程流水</a>
                    <button type="button" class="btn btn-sm btn-outline" onclick="ptIoToggle()">⬆ 导入积分</button>
                    <button type="button" class="btn btn-sm btn-outline" style="color:#c0392b;" onclick="ptClearAll()">🗑 清空所有积分</button>
                </div>
                <div id="ptIoForm" style="display:none;border:1px solid #e0e3ef;border-radius:8px;background:#fbfcff;padding:10px;">
                    <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:6px;font-size:13px;">
                        <label style="display:flex;align-items:center;gap:4px;cursor:pointer;"><input type="radio" name="ptIoKind" value="balance" checked onchange="ptIoKindTip()" autocomplete="off">最终积分（起始分）</label>
                        <label style="display:flex;align-items:center;gap:4px;cursor:pointer;"><input type="radio" name="ptIoKind" value="log" onchange="ptIoKindTip()" autocomplete="off">过程流水（自动算结果）</label>
                        <label style="display:flex;align-items:center;gap:4px;cursor:pointer;color:#c0392b;"><input type="checkbox" id="ptIoClear" autocomplete="off">先清空本班现有积分</label>
                    </div>
                    <div id="ptIoTip" class="form-hint" style="margin-bottom:6px;"></div>
                    <input type="file" id="ptIoFile" accept=".csv,.txt" style="font-size:12px;margin-bottom:6px;display:block;" autocomplete="off">
                    <textarea id="ptIoContent" class="form-control" rows="4" placeholder="也可直接粘贴：每行一条（制表符或逗号分隔），选 CSV 文件后此处留空即可" style="font-size:12px;margin-bottom:6px;"></textarea>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <input type="password" id="ptIoPwd" class="form-control" placeholder="当前账号登录密码（防误触）" autocomplete="current-password" style="width:200px;flex:none;font-size:13px;">
                        <button type="button" class="btn btn-sm" style="background:#2980b9;" onclick="ptIoSubmit()">确认导入</button>
                    </div>
                    <div id="ptIoResult" style="display:none;margin-top:8px;font-size:12px;border-radius:6px;padding:6px 8px;"></div>
                </div>
            </div>
        </div>
        <?php endif; // $pt_cfg：设置面板结束 ?>

        <div class="modal-actions">
            <button type="button" class="btn btn-outline" onclick="ptClose()">关闭</button>
        </div>
    </div>

    <!-- 长按学生卡片 → 单人加减分面板（加分/减分上下分组，便于快速操作；全部积分项均显示，右上角图标甄别类型） -->
    <?php if (!$pt_readonly): ?>
    <div id="ptPressMask" onclick="if (event.target === this) ptPressClose();">
        <div id="ptPressCard">
            <div id="ptPressName"></div>
            <div id="ptPressSub"></div>
            <div class="pt-press-sec pos">➕ 加分</div>
            <div id="ptPressPos"></div>
            <div class="pt-press-sec neg">➖ 减分</div>
            <div id="ptPressNeg"></div>
            <div style="margin-top:12px;"><button type="button" class="btn btn-sm btn-outline" onclick="ptPressClose()">关闭</button></div>
        </div>
    </div>
    <?php endif; // !$pt_readonly：长按面板结束 ?>

    <!-- 📒 抽中记录：随机抽选已抽中的学生名单，清空前不再被抽中 -->
    <div id="ptRecMask" onclick="if (event.target === this) ptRecClose();">
        <div id="ptRecCard">
            <div style="font-size:17px;font-weight:bold;color:#4a4f63;">📒 抽中记录</div>
            <div id="ptRecCnt" style="font-size:12px;color:#999;margin:4px 0 8px;"></div>
            <div id="ptRecList"></div>
            <div style="display:flex;justify-content:center;gap:10px;margin-top:10px;">
                <button type="button" class="btn btn-sm" style="background:#e74c3c;color:#fff;" onclick="ptRecClear()">🗑 清空记录</button>
                <button type="button" class="btn btn-sm btn-outline" onclick="ptRecClose()">关闭</button>
            </div>
            <div style="margin-top:8px;"><span class="form-hint">抽中规则</span><button type="button" class="hint-q" onclick="toggleHint(event, '<b>抽中规则说明</b><br>· 清空前，已抽中的学生不会再被抽中<br>· 点「🗑 清空记录」后全班重新可抽')">?</button></div>
        </div>
    </div>

    <!-- 全屏随机抽选（大屏教学：学生名字超大滚动 → 停止后显示组名+姓名+个人/小组积分按钮；按钮类型在「⚙ 设置」配置，右上角 👤/👥 图标甄别） -->
    <div id="ptLotMask">
        <div id="ptLotCard">
            <div id="ptLotTitle">🎲 随机抽选</div>
            <div id="ptLotRoll"><div id="ptLotName" class="pt-lot-big">?</div></div>
            <div id="ptLotDone" style="display:none;">
                <div class="pt-lot-res">
                    <div id="ptLotGrp"></div>
                    <div id="ptLotStu" class="pt-lot-big"></div>
                    <div id="ptLotTotal"></div>
                    <div id="ptLotBtns"></div>
                    <div id="ptLotMsg"></div>
                </div>
            </div>
            <div id="ptLotActs">
                <button type="button" id="ptLotStop" class="pt-lot-btn stop" onclick="ptLotStop()">停止抽选</button>
                <button type="button" id="ptLotAgain" class="pt-lot-btn stop" style="display:none;" onclick="ptLotStart()">重新抽选</button>
                <button type="button" class="pt-lot-btn close" onclick="ptLotClose()">关闭</button>
            </div>
        </div>
    </div>
</div>

<script>
// ===== 课堂积分（本段由 includes/points_modal.php 输出，页面内独有 pt 前缀，无命名冲突） =====
if (typeof window.openModal !== 'function') {
    window.openModal = function (id) { document.getElementById(id).classList.add('show'); };
}
if (typeof window.closeModal !== 'function') {
    window.closeModal = function (id) { document.getElementById(id).classList.remove('show'); };
}
if (typeof window.toast !== 'function') {
    window.toast = function (msg, type) { showToast(msg, type); };   // 兜底：宿主有 toast 时用宿主的
}
var PT_CLASS_ID = <?php echo $pt_cid; ?>;
var PT_READONLY = <?php echo $pt_readonly ? 'true' : 'false'; ?>;   // 只读模式（仅查看成员）：操作面板未渲染，数据加载与 Tab 切换需容错
var PT_ITEMS = [];          // 积分项 [{id,name,value,color}]
var PT_STU = [];            // 学生 [{id,name,seat,seat_pos,group_id,total}]
var PT_STU_MAP = {};        // id => 学生
var PT_SEL = [];            // 已选学生 id
var PT_LOGS = [];           // 最近流水
var PT_RANGE = 'all';       // 汇总时间段
var PT_STAT_WHO = 0;        // 汇总分析查看对象：0=全班，>0=学生 id
var PT_STAT_LAST = null;    // 最近一次全班统计数据（切回全班免重拉）
var PT_STU_STAT = null;     // 选中学生的个人统计 {id,name,seat,total,dims,daily,logs}
var PT_STU_MODE = 'cum';    // 个人得分趋势口径：day=每天净得分 / cum=累计折线
var PT_STU_F_ITEM = '';     // 个人流水筛选：维度（积分项）名，''=不筛
var PT_STU_F_DAY = '';      // 个人流水筛选：日期 YYYY-MM-DD，''=不筛（两项可叠加，点击图表/图例/节点设置，再点同项取消）
var PT_DIM_CHART = 'bar';   // 全班各维度得分图型：bar=条形 / pie=饼图
var PT_STU_CHART = 'pie';   // 个人各维度占比图型：pie=环形 / bar=条形
var PT_CFG = [];            // 设置页编辑中的积分项副本
var PT_VIEW = 'list';       // 学生区视图：list=顺序 / seat=座位 / group=分组（ptCycleView 循环切换）
var PT_SEAT = { cols: 2, groups: 4 };  // 班级座位布局（classes.seat_cols / seat_groups）
var PT_SEAT_NAMES = {};     // sort(1起) => 座位组名（组名跟组走，与 project_view.php 座位模式同口径）
var PT_GROUPS = [];         // [{id,name,sort}] 按 sort 升序（分组视图归堆用）
// 登记/评价状态筛选（宿主为 project_view.php 时传入；PT_REG=null=无作业上下文，筛框隐藏）
var PT_REG = <?php echo $pt_has_filter ? json_encode($pt_reg_map, JSON_FORCE_OBJECT) : 'null'; ?>;   // 已登记 sid=>1
var PT_EVAL = <?php echo $pt_has_filter ? json_encode($pt_eval_map, JSON_FORCE_OBJECT) : 'null'; ?>; // 已评价 sid=>评价内容
var PT_EVAL_OPTS = <?php echo json_encode($pt_eval_opts, JSON_UNESCAPED_UNICODE); ?>;                // 评价内容候选（预设+实际用到的）
var PT_FILTER = 'all';      // all / reg / unreg / evaled / unevaled / ev:<评价内容>
var PT_PROJECT_ID = <?php echo intval($pt_project_id ?? 0); ?>;   // 项目上下文（project_view.php 传入；>0=⚙ 可设本项目自动规则 + 流水🤖标注 + points_data 懒同步）
// 🤖 自动加减分规则编辑器（⚙ 设置 Tab，ptTab('cfg') 懒加载）
var PT_AUTO_RULES = [];     // 编辑中规则副本 [{rtype,opt,value,enabled}]
var PT_AUTO_META = {};      // rtype => {name,param,desc,need_late?}（服务端按项目登记模式过滤后下发）
var PT_AUTO_EVAL_OPTS = []; // 评价内容候选（eval_opt 参数下拉：评价模式预设选项 + 项目实际用到的评价值）
var PT_AUTO_LATE = 0;       // 当前项目补登记阈值（need_late 类规则的可用性提示）
var PT_AUTO_PROJS = [];     // projects.php 场景：可管理项目列表（points_rule_projects）
var PT_AUTO_PID = 0;        // 当前规则编辑目标项目（project_view=PT_PROJECT_ID；projects.php=下拉所选）
var PT_GIFTS = [];          // 🎁 兑换礼品 [{id,name,cost}]（按班级配置）
var PT_GIFT_SEL = 0;        // 当前选中的礼品 id（0=未选）
var PT_GIFT_EDIT = 0;       // 正在编辑的礼品 id（0=新增/收起表单）

function ptE(id) { return document.getElementById(id); }
function ptMsg(msg, type) {
    var el = ptE('ptMsg');
    if (!msg) { el.style.display = 'none'; return; }
    el.style.display = 'block';
    el.style.background = type === 'error' ? '#fdecea' : '#eafaf1';
    el.style.color = type === 'error' ? '#c0392b' : '#2c7a3f';
    el.textContent = msg;
    clearTimeout(ptMsg._t);
    ptMsg._t = setTimeout(function () { el.style.display = 'none'; }, 4000);
}
function ptPost(body) {
    return fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    }).then(function (r) { return r.json(); });
}
function ptEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

// ===== 打开弹窗 + 拉取数据 =====
function ptOpen() {
    openModal('ptModal');
    ptMsg('');
    ptTab('<?php echo $pt_readonly ? 'stat' : 'give'; ?>');   // 仅查看默认落在汇总分析（无操作页签可看）
    var _cfg = ptE('ptCfgList');
    if (_cfg) _cfg.dataset.loaded = '';   // 每次打开重新同步最新积分项配置（只读/无设置权限时 ⚙ 面板未渲染，判空防中断 ptLoad）
    ptLoad();
}
function ptClose() { closeModal('ptModal'); }

function ptLoad() {
    var _grid = ptE('ptStuGrid');
    if (_grid) _grid.innerHTML = '<span class="form-hint">加载中…</span>';
    ptPost('type=points_data&class_id=' + PT_CLASS_ID + '&range=' + PT_RANGE + (PT_PROJECT_ID > 0 ? '&project_id=' + PT_PROJECT_ID : '') + (PT_STAT_WHO > 0 ? '&student_id=' + PT_STAT_WHO : ''))
    .then(function (d) {
        if (!d.success) { ptMsg(d.message || '加载失败', 'error'); return; }
        PT_ITEMS = d.items || [];
        PT_STU = d.students || [];
        PT_STU_MAP = {};
        PT_STU.forEach(function (s) { PT_STU_MAP[s.id] = s; });
        PT_LOGS = d.logs || [];
        PT_SEAT = d.seat_cfg || PT_SEAT;
        PT_SEAT_NAMES = d.seat_names || {};
        PT_GROUPS = d.groups || [];
        PT_LOT_PICKED = d.lot_picked || {};   // 随机抽选已中签名单（sid=>时间），清空前不再被抽中
        PT_GIFTS = d.gifts || [];             // 🎁 兑换礼品 [{id,name,cost}]
        if (PT_GIFT_SEL && !PT_GIFTS.some(function (g) { return String(g.id) === String(PT_GIFT_SEL); })) PT_GIFT_SEL = 0;   // 礼品被删/下架后重置选中
        PT_SEL = PT_SEL.filter(function (id) { return !!PT_STU_MAP[id]; });
        if (PT_READONLY) { ptStatRefresh(d); }   // 只读：操作面板未渲染，仅刷新汇总分析（含流水）
        else { ptRenderStu(); ptRenderItems(); ptStatRefresh(d); ptCfgInit(); ptGiftRender(); }
    })
    .catch(function () { ptMsg('网络错误', 'error'); });
}

// ===== Tab 切换（只读模式下操作类面板/按钮未渲染，统一判空容错） =====
function ptTab(k) {
    window.PT_CUR_TAB = k;   // 记录当前页签（定时刷新只针对只读展示的汇总分析）
    ['Give', 'Redeem', 'Stat', 'Cfg'].forEach(function (n) {
        var pane = ptE('ptTab' + n);
        if (pane) pane.style.display = (n.toLowerCase() === k) ? '' : 'none';
        var btn = ptE('ptTabBtn' + n);
        if (btn) btn.className = 'btn btn-sm' + (n.toLowerCase() === k ? '' : ' btn-outline');
    });
    // 学生网格两个 Tab 共用：用 DOM 移动（appendChild）代替复制，选中状态与事件天然共享（只读下操作面板未渲染，目标容器判空）
    var grid = ptE('ptStuGrid');
    if (grid) {
        var _wrap = ptE(k === 'redeem' ? 'ptRedeemWrap' : 'ptGiveGridWrap');
        if (_wrap) _wrap.appendChild(grid);
    }
    if (k === 'cfg') ptAutoEnsure();   // 🤖 自动规则懒加载（首次切到 ⚙ 才拉取）
    if (k === 'stat' && PT_STAT_LAST) { ptRenderDims(PT_STAT_LAST.dims || []); if (PT_STU_STAT) ptStatStuRender(); }   // ECharts 容器需可见后渲染（初始加载时本页签隐藏）
}
// 弹窗开着且停在「汇总分析」页签时，每 30 秒同步最新积分数据（他端加减分 / 自动积分变化实时可见；操作页签不自动刷新，避免打断输入）
setInterval(function () {
    var pm = ptE('ptModal');
    if (pm && pm.classList.contains('show') && window.PT_CUR_TAB === 'stat') ptLoad();
}, 30000);

// ===== Tab1：学生卡片 + 积分项按钮（视图：顺序 / 座位 / 分组 循环切换） =====
function ptTotalCls(v) { return v > 0 ? ' pos' : (v < 0 ? ' neg' : ''); }
function ptCardHtml(s) {
    var seat = String(s.seat) !== '' ? s.seat + ' · ' : '';
    return '<span class="pt-stu' + (PT_SEL.indexOf(s.id) >= 0 ? ' on' : '') + '" data-sid="' + s.id + '" onclick="ptToggle(' + s.id + ')" title="点击选中/取消（' + ptEsc(seat) + ptEsc(s.name) + ' 当前 ' + s.total + ' 分）">'
         + '<span class="nm">' + ptEsc(s.name) + '</span>'
         + '<span class="st">' + ptEsc(seat) + '</span>'
         + '<span class="tt' + ptTotalCls(s.total) + '">' + s.total + '分</span>'
         + '</span>';
}
// 面板头「全选该组/全不选」合一开关（list=该组学生数组；tg=切换后回调名）
function ptGroupLink(list, fnCall) {
    if (!list.length) return '';
    var all = list.every(function (s) { return PT_SEL.indexOf(s.id) >= 0; });
    return ' <a href="javascript:void(0)" class="pt-sel-link" onclick="' + fnCall + '">' + (all ? '全不选' : '全选该组') + '</a>';
}
function ptGrpSel(gid) {
    var ids = PT_STU.filter(function (s) { return String(parseInt(s.group_id || 0, 10)) === String(gid) && ptMatch(s); }).map(function (s) { return s.id; });
    var all = ids.length && ids.every(function (id) { return PT_SEL.indexOf(id) >= 0; });
    if (all) PT_SEL = PT_SEL.filter(function (id) { return ids.indexOf(id) < 0; });
    else ids.forEach(function (id) { if (PT_SEL.indexOf(id) < 0) PT_SEL.push(id); });
    ptRenderStu();
}
function ptSeatGrpSel(g) {
    var ids = (PT_SEAT_GIDS[g] || []);
    var all = ids.length && ids.every(function (id) { return PT_SEL.indexOf(id) >= 0; });
    if (all) PT_SEL = PT_SEL.filter(function (id) { return ids.indexOf(id) < 0; });
    else ids.forEach(function (id) { if (PT_SEL.indexOf(id) < 0) PT_SEL.push(id); });
    ptRenderStu();
}
var PT_VIEW_NAMES = { list: '🔢 顺序', seat: '🪑 座位', group: '👥 分组' };
var PT_SEAT_GIDS = [];      // 座位视图渲染时缓存：组号g => 该组学生id数组（「全选该组」用）
var PT_ROLL_BUSY = false;   // 随机小组动画进行中（防重复触发）
var PT_LOT_PICKED = {};     // 随机抽选已中签名单（sid=>时间字符串），清空前不再被抽中
// 随机抽取一个小组：滚动高亮各组面板头后自动全选该组成员，点积分项即为全组加分/减分
function ptRollGroup() {
    if (PT_ROLL_BUSY) return;
    if (!PT_GROUPS.length) { ptMsg('本班尚未分组，请先设置分组', 'error'); return; }
    var gmap = {};
    PT_STU.forEach(function (s) {
        var gid = String(parseInt(s.group_id || 0, 10));
        if (gid !== '0' && PT_GROUPS.some(function (g) { return String(g.id) === gid; })) {
            if (!gmap[gid]) gmap[gid] = [];
            gmap[gid].push(s.id);
        }
    });
    var cand = PT_GROUPS.filter(function (g) { return (gmap[String(g.id)] || []).length > 0; });
    if (!cand.length) { ptMsg('各组均无学生，请先完成分组', 'error'); return; }
    PT_ROLL_BUSY = true;
    if (PT_VIEW !== 'group') { PT_VIEW = 'group'; ptE('ptViewBtn').textContent = PT_VIEW_NAMES.group; ptRenderStu(); }   // 抽小组自然切到分组视图呈现
    var winner = cand[Math.floor(Math.random() * cand.length)];
    var heads = document.querySelectorAll('#ptStuGrid .pt-grp-panel .pt-seat-head');
    var steps = 10, i = 0;
    (function step() {
        heads.forEach(function (h) { h.classList.remove('pt-roll'); });
        if (i < steps) {
            var k = Math.floor(Math.random() * heads.length);
            if (heads[k]) heads[k].classList.add('pt-roll');
            i++;
            setTimeout(step, 90 + i * 16);
        } else {
            PT_SEL = (gmap[String(winner.id)] || []).slice();
            ptRenderStu();
            var panels = document.querySelectorAll('#ptStuGrid .pt-grp-panel');
            panels.forEach(function (p) { p.classList.remove('pt-roll-win'); });
            var idx = PT_GROUPS.indexOf(winner);
            if (panels[idx]) panels[idx].classList.add('pt-roll-win');
            ptMsg('🎯 抽中：' + winner.name + '（' + PT_SEL.length + '人）— 点下方积分项记分', 'ok');
            PT_ROLL_BUSY = false;
        }
    })();
}
function ptCycleView() {
    PT_VIEW = PT_VIEW === 'list' ? 'seat' : (PT_VIEW === 'seat' ? 'group' : 'list');
    ptE('ptViewBtn').textContent = PT_VIEW_NAMES[PT_VIEW];
    ptRenderStu();
}
function ptRenderStu() {
    ptE('ptStuGrid').style.maxHeight = PT_VIEW === 'list' ? '30vh' : '52vh';   // 座位/分组面板更高，放宽可视区
    if (!PT_STU.length) {
        ptE('ptStuGrid').innerHTML = '<span class="form-hint">本班暂无学生</span>';
        ptE('ptSelCnt').textContent = '已选 ' + PT_SEL.length + ' 人';
        return;
    }
    if (PT_VIEW === 'seat') { ptRenderSeat(); return; }
    if (PT_VIEW === 'group') { ptRenderGroup(); return; }
    var list = ptFiltered();
    var html = '';
    list.forEach(function (s) { html += ptCardHtml(s); });
    if (!list.length) html = '<span class="form-hint">没有符合筛选条件的学生（可切换筛框为「全体学生」）</span>';
    ptE('ptStuGrid').innerHTML = html;
    ptE('ptSelCnt').textContent = '已选 ' + PT_SEL.length + ' 人';
}
// 座位视图：讲台在上，下方「分组数 × 每组列数」网格（布局与空位口径同 project_view.php 座位模式），未入座学生列于下方
function ptRenderSeat() {
    var G = Math.max(1, parseInt(PT_SEAT.groups, 10) || 4), C = Math.max(1, parseInt(PT_SEAT.cols, 10) || 2), span = G * C;
    var seatMap = {}, unseated = [];
    PT_STU.forEach(function (s) {
        if (!ptMatch(s)) return;   // 筛选不匹配：座位显示空座、不进未入座池
        var p = parseInt(s.seat_pos || 0, 10);
        if (p > 0 && !seatMap[p]) seatMap[p] = s; else unseated.push(s);
    });
    var maxSeat = 0;
    Object.keys(seatMap).forEach(function (p) { maxSeat = Math.max(maxSeat, parseInt(p, 10)); });
    var R = Math.max(1, Math.ceil(maxSeat / span));   // 行数随入座情况自动扩展
    var groupSids = [];
    for (var g0 = 0; g0 < G; g0++) groupSids[g0] = [];
    var html = '<div class="pt-stage"><div class="pt-podium">讲 台</div><div class="pt-seat-groups">';
    for (var g = 0; g < G; g++) {
        var cnt = 0;
        for (var r = 0; r < R; r++) for (var c = 0; c < C; c++) {
            var sc = seatMap[r * span + g * C + c + 1];
            if (sc) { cnt++; groupSids[g].push(sc.id); }
        }
        var gname = PT_SEAT_NAMES[g + 1] || ('第' + (g + 1) + '组');
        html += '<div class="pt-seat-panel"><div class="pt-seat-head">' + ptEsc(gname) + '（' + cnt + '人）' + ptGroupLink(groupSids[g], 'ptSeatGrpSel(' + g + ')') + '</div>'
              + '<div class="pt-seat-grid" style="grid-template-columns:repeat(' + C + ',minmax(0,1fr));">';
        for (var r2 = 0; r2 < R; r2++) {
            for (var c2 = 0; c2 < C; c2++) {
                var s = seatMap[r2 * span + g * C + c2 + 1];
                html += s ? '<div class="pt-seat-cell">' + ptCardHtml(s) + '</div>'
                          : '<div class="pt-seat-cell"><span class="pt-seat-empty-txt">空座</span></div>';
            }
        }
        html += '</div></div>';
    }
    html += '</div>';
    if (unseated.length) {
        html += '<div class="pt-unpool-head"><b>未入座（' + unseated.length + '人）</b> — 在「设置分组」弹窗安排座位后自动入位</div><div style="text-align:center;">';
        unseated.forEach(function (s2) { html += ptCardHtml(s2); });
        html += '</div>';
    }
    html += '</div>';
    PT_SEAT_GIDS = groupSids;
    ptE('ptStuGrid').innerHTML = html;
    ptE('ptSelCnt').textContent = '已选 ' + PT_SEL.length + ' 人';
}
// 分组视图：按 stu_groups.sort 升序归堆（组名 N人），无有效分组的学生归「未分组」
function ptRenderGroup() {
    var known = {};
    PT_GROUPS.forEach(function (g) { known[String(g.id)] = true; });
    var byGid = {}, ungrouped = [];
    PT_STU.forEach(function (s) {
        if (!ptMatch(s)) return;   // 筛选不匹配：不进分组面板
        var gid = String(parseInt(s.group_id || 0, 10));
        if (gid !== '0' && known[gid]) {
            if (!byGid[gid]) byGid[gid] = [];
            byGid[gid].push(s);
        } else ungrouped.push(s);
    });
    var html = '<div class="pt-seat-groups">';
    PT_GROUPS.forEach(function (g) {
        var list = byGid[String(g.id)] || [];
        html += '<div class="pt-grp-panel"><div class="pt-seat-head">' + ptEsc(g.name) + '（' + list.length + '人）' + ptGroupLink(list, 'ptGrpSel(' + g.id + ')') + '</div><div style="text-align:center;">';
        list.forEach(function (s) { html += ptCardHtml(s); });
        html += '</div></div>';
    });
    if (ungrouped.length) {   // 无未分组学生时整个面板不显示
        html += '<div class="pt-grp-panel"><div class="pt-seat-head">未分组（' + ungrouped.length + '人）</div><div style="text-align:center;">';
        ungrouped.forEach(function (s) { html += ptCardHtml(s); });
        html += '</div></div>';
    }
    html += '</div>';
    ptE('ptStuGrid').innerHTML = html;
    ptE('ptSelCnt').textContent = '已选 ' + PT_SEL.length + ' 人';
}
function ptToggle(sid) {
    if (PT_PRESS_FIRED) { PT_PRESS_FIRED = false; return; }   // 长按结束后的 click 不改变选中态
    var i = PT_SEL.indexOf(sid);
    if (i >= 0) PT_SEL.splice(i, 1); else PT_SEL.push(sid);
    ptRenderStu();
}
// ===== 登记/评价状态筛选（顺序/座位/分组视图统一生效；「☑ 全选」只选筛出的学生） =====
function ptFilterSet(v) { PT_FILTER = v || 'all'; ptRenderStu(); }
function ptMatch(s) {
    if (PT_FILTER === 'all') return true;
    var reg = !!(PT_REG && PT_REG[s.id]);
    var ev = PT_EVAL ? String(PT_EVAL[s.id] || '') : '';
    if (PT_FILTER === 'reg') return reg;
    if (PT_FILTER === 'unreg') return !reg;
    if (PT_FILTER === 'evaled') return ev !== '';
    if (PT_FILTER === 'unevaled') return ev === '';
    if (PT_FILTER.indexOf('ev:') === 0) return ev === PT_FILTER.slice(3);
    return true;
}
function ptFiltered() { return PT_STU.filter(ptMatch); }
function ptFilterInit() {
    if (PT_REG === null) return;   // projects.php 等无作业上下文：不渲染筛框
    var vals = [];
    function pushVal(v) { v = String(v); if (v !== '' && vals.indexOf(v) < 0) vals.push(v); }
    (PT_EVAL_OPTS || []).forEach(pushVal);                            // 评价模式预设选项在前
    Object.keys(PT_EVAL || {}).forEach(function (sid) { pushVal(PT_EVAL[sid]); });   // 实际用到的评价内容补后
    var html = '<option value="all">全体学生</option>'
             + '<option value="reg">✔ 已登记</option>'
             + '<option value="unreg">✖ 未登记</option>'
             + '<option value="evaled">✔ 已评价</option>'
             + '<option value="unevaled">✖ 未评价</option>';
    vals.forEach(function (v) { html += '<option value="ev:' + ptEsc(v) + '">评价为 ' + ptEsc(v) + '</option>'; });
    ptE('ptFilter').innerHTML = html;
    ptE('ptFilter').style.display = '';
}
function ptAll() { PT_SEL = ptFiltered().map(function (s) { return s.id; }); ptRenderStu(); }   // 只选当前筛出的学生
function ptClear() { PT_SEL = []; ptRenderStu(); }
ptFilterInit();   // 初始化筛选下拉框（PT_REG=null 时自动隐藏；宿主静态数据，弹窗每次打开无需重建）

function ptRenderItems() {
    var html = '';
    PT_ITEMS.forEach(function (it) {
        if (parseInt(it.show_quick, 10) !== 1) return;   // 设置中未勾选「加分」的不显示在一键加分里
        var v = it.value > 0 ? '+' + it.value : String(it.value);
        var grp = parseInt(it.type, 10) === 2;
        var tip = (grp ? '小组积分：' : '个人积分：') + it.name + ' ' + v + (grp ? '（本组每人各记一条）' : '');
        html += '<button type="button" class="pt-item-btn" style="background:' + ptEsc(it.color) + ';" '
              + 'onclick="ptGive(' + it.id + ')" title="' + ptEsc(tip) + '"><span class="pt-ico">' + (grp ? '👥' : '👤') + '</span>' + ptEsc(it.name) + ' <span>' + v + '</span></button>';
    });
    ptE('ptItemBtns').innerHTML = html || '<div class="form-hint" style="padding:4px 0;">没有显示中的积分项，请到「⚙ 设置」勾选「加分」或添加积分项。</div>';
}

// 加分/扣分（批量；成功后保留选中并本地更新总分，静默刷新流水与汇总）
function ptGive(itemId, custom) {
    if (!PT_SEL.length) { ptMsg('请先点击选择学生（可多选批量）', 'error'); return; }
    var body = 'type=points_add&class_id=' + PT_CLASS_ID
             + '&student_ids=' + encodeURIComponent(JSON.stringify(PT_SEL))
             + '&item_id=' + (itemId || 0) + '&custom=' + (custom || 0);
    var remark = ptE('ptRemark').value.trim();
    if (remark !== '') body += '&remark=' + encodeURIComponent(remark);
    ptPost(body).then(function (d) {
        if (!d.success) { ptMsg(d.message || '操作失败', 'error'); return; }
        ptMsg(d.message + (remark !== '' ? '（备注：' + remark + '）' : ''), 'ok');
        PT_SEL.forEach(function (sid) { if (PT_STU_MAP[sid]) PT_STU_MAP[sid].total += d.value; });
        ptRenderStu();
        ptLoad();   // 刷新流水与汇总（选中态在 ptLoad 中保留）
    }).catch(function () { ptMsg('网络错误', 'error'); });
}

// ===== Tab2：积分流水 =====
function ptLogRowHtml(l) {   // 流水行（全班/个人视图共用；🤖自动规则、📥导入标注，本人操作可撤销）
    var pos = l.value > 0;
    var isAuto = parseInt(l.src, 10) === 2;   // 项目自动规则生成（🤖：不可撤销，调整规则后重算）
    var isImp = parseInt(l.src, 10) === 3;    // 批量导入生成（📥：数据迁移，导入积分/流水/清空审计）
    return '<div class="pt-log-row">'
          + '<span style="color:#999;font-size:12px;min-width:110px;">' + ptEsc(l.at) + '</span>'
          + '<span style="font-weight:bold;">' + ptEsc(l.sname) + (String(l.seat) !== '' ? '<span style="color:#999;font-weight:normal;font-size:12px;"> ' + ptEsc(l.seat) + '号</span>' : '') + '</span>'
          + '<span class="pt-val ' + (pos ? 'pos' : 'neg') + '">' + (pos ? '+' : '') + l.value + '</span>'
          + '<span>' + (l.item !== '' ? ptEsc(l.item) : '<span style="color:#999;">自定义</span>') + '</span>'
          + (l.remark !== '' ? '<span style="color:#b9770e;">📝 ' + ptEsc(l.remark) + '</span>' : '')
          + (isAuto
                ? '<span style="color:#8e44ad;font-size:12px;white-space:nowrap;" title="项目自动规则生成（' + ptEsc(l.item) + (l.remark !== '' ? ' · ' + ptEsc(l.remark) : '') + '）：在「⚙ 设置 → 🤖 自动加减分规则」中调整后重算">🤖 自动规则</span>'
                : (isImp
                      ? '<span style="color:#2980b9;font-size:12px;white-space:nowrap;" title="批量导入生成（数据迁移）">📥 导入</span>'
                      : '<span style="color:#999;font-size:12px;">' + ptEsc(l.by) + '</span>'))
          + (l.mine ? '<button type="button" class="btn btn-sm btn-outline" style="margin-left:auto;padding:1px 8px;font-size:12px;" onclick="ptUndo(' + l.id + ')">撤销</button>' : '')
          + '</div>';
}
function ptRenderLogs() {
    var html = '';
    PT_LOGS.forEach(function (l) { html += ptLogRowHtml(l); });
    ptE('ptLogList').innerHTML = html || '<div class="form-hint" style="padding:10px;">暂无积分记录</div>';
}
function ptUndo(id) {
    if (!confirm('确认撤销这条积分记录？')) return;
    ptPost('type=points_log_del&class_id=' + PT_CLASS_ID + '&log_id=' + id).then(function (d) {
        if (!d.success) { ptMsg(d.message || '撤销失败', 'error'); return; }
        ptMsg(d.message, 'ok');
        ptLoad();
    }).catch(function () { ptMsg('网络错误', 'error'); });
}

// ===== Tab3：汇总分析（时间段筛选） =====
function ptRange(r) {
    PT_RANGE = r;
    ['Week', 'Month', 'All'].forEach(function (k) {
        var key = k.toLowerCase();
        ptE('ptRg' + k).className = 'btn btn-sm' + (key === r ? '' : ' btn-outline');
    });
    ptLoad();
}
function ptRenderRank(rank) {   // 全班积分排行（前 20）：柱宽以最差者为基线拉开差距；点击行切换到该生个人统计
    var html = '';
    var max = 1, min = 0;
    rank.forEach(function (r) { if (r.total > max) max = r.total; if (r.total < min) min = r.total; });
    rank.forEach(function (r, i) {
        var medal = ['🥇', '🥈', '🥉'][i] || (i + 1);
        var frac = (max > min) ? (r.total - min) / (max - min) : 1;   // 基线=最差者，避免分差小时柱子几乎等长
        var w = 10 + Math.round(frac * 62);   // 10%~72%
        html += '<div class="pt-bar-wrap" style="cursor:pointer;" onclick="ptStatPick(' + r.sid + ')" title="点击查看 ' + ptEsc(r.name) + ' 的个人统计">'
              + '<span style="width:34px;text-align:right;">' + medal + '</span>'
              + '<span style="min-width:72px;">' + ptEsc(r.name) + (String(r.seat) !== '' ? '<span style="color:#999;font-size:12px;"> ' + ptEsc(r.seat) + '号</span>' : '') + '</span>'
              + '<div class="pt-bar" style="width:' + w + '%;"></div>'
              + '<b style="color:#27ae60;">' + r.total + '</b></div>';
    });
    ptE('ptRank').innerHTML = html || '<div class="form-hint">该时间段暂无积分数据</div>';
}
function ptDimsBarHtml(dims) {   // 维度条形图（全班/个人共用）
    var html = '';
    var dmax = 1;
    dims.forEach(function (d) { var a = Math.abs(d.value); if (a > dmax) dmax = a; });
    dims.forEach(function (d) {
        var w = Math.round(Math.abs(d.value) / dmax * 100) * 0.72;
        var color = d.value >= 0 ? '#667eea' : '#e74c3c';
        html += '<div class="pt-bar-wrap">'
              + '<span style="min-width:76px;">' + ptEsc(d.name) + '</span>'
              + '<div class="pt-bar" style="width:' + w + '%;background:' + color + ';"></div>'
              + '<b style="color:' + color + ';">' + (d.value > 0 ? '+' : '') + d.value + '</b></div>';
    });
    return html || '<div class="form-hint">该时间段暂无维度数据</div>';
}
function ptRenderDims(dims) {   // 全班各维度得分（条形/饼图切换，PT_DIM_CHART）
    ptE('ptDims').innerHTML = (PT_DIM_CHART === 'pie')
        ? '<div style="padding:4px 2px;">' + ptDonutSvg(dims, dims.reduce(function (a, d) { return a + d.value; }, 0), false) + '</div>'
        : ptDimsBarHtml(dims);
}
function ptDimChart(c) {   // 全班维度图型切换：bar=条形 / pie=饼图（重画当前缓存数据）
    PT_DIM_CHART = (c === 'pie') ? 'pie' : 'bar';
    ptDimChartBtns();
    if (PT_STAT_LAST) ptRenderDims(PT_STAT_LAST.dims || []);
}
function ptDimChartBtns() {   // 维度图型按钮态
    var bar = ptE('ptDcBar'), pie = ptE('ptDcPie');
    if (bar) bar.className = 'btn btn-sm' + (PT_DIM_CHART === 'bar' ? '' : ' btn-outline');
    if (pie) pie.className = 'btn btn-sm' + (PT_DIM_CHART === 'pie' ? '' : ' btn-outline');
}
function ptStuChart(c) {   // 个人维度占比图型切换：pie=环形 / bar=条形
    PT_STU_CHART = (c === 'pie') ? 'pie' : 'bar';
    ptStatStuRender();
}

// ===== 汇总分析：查看对象切换（全班=排行+维度+全班流水；学生=环形占比+得分趋势+个人流水） =====
var PT_STAT_COLORS = ['#4285f4', '#34a853', '#fbbc05', '#ea4335', '#9b59b6', '#16a085', '#e91e63', '#00bcd4', '#ff7043', '#8d6e63'];
function ptStuLabel(s) { return (String(s.seat) !== '' ? s.seat + ' · ' : '') + s.name; }
function ptStatFill() {   // 同步查看对象输入框显示（学生被删/禁用回退全班时保持一致）
    var inp = ptE('ptStatQ');
    var lbl = '全班';
    if (PT_STAT_WHO > 0) {
        lbl = '';
        PT_STU.forEach(function (s) { if (s.id === PT_STAT_WHO) lbl = ptStuLabel(s); });
        if (lbl === '') { PT_STAT_WHO = 0; lbl = '全班'; }   // 找不到该学生 → 回退全班
    }
    if (inp) inp.value = lbl;
    var clr = ptE('ptStatQClr');
    if (clr) clr.style.display = PT_STAT_WHO > 0 ? '' : 'none';
}
function ptStatQFocus(inp) {   // 聚焦即清空文本（否则残留「座号 · 姓名」会把候选过滤得只剩自己），弹出全班+全部学生候选
    inp.value = '';
    ptStatQFilter('');
}
function ptStatQFilter(q) {   // 查看对象输入筛选：座号/姓名实时匹配，全班置顶（mousedown 抢在 blur 前选中）
    var list = ptE('ptStatQList');
    if (!list) return;
    q = String(q || '').trim().toLowerCase();
    var html = '<div style="padding:6px 10px;cursor:pointer;' + (PT_STAT_WHO === 0 ? 'font-weight:bold;color:#667eea;background:#f5f6ff;' : '') + '" onmousedown="ptStatPick(0)">全班</div>';
    var n = 0;
    PT_STU.forEach(function (s) {
        var label = ptStuLabel(s);
        if (q !== '' && label.toLowerCase().indexOf(q) < 0) return;
        if (++n > 80) return;
        html += '<div style="padding:6px 10px;cursor:pointer;' + (PT_STAT_WHO === s.id ? 'font-weight:bold;color:#667eea;background:#f5f6ff;' : '') + '" onmousedown="ptStatPick(' + s.id + ')">' + ptEsc(label) + '</div>';
    });
    if (q !== '' && n === 0) html += '<div style="padding:6px 10px;color:#999;">无匹配学生</div>';
    list.innerHTML = html;
    list.style.display = '';
}
function ptStatQHide() {   // 失焦收起候选列表
    var list = ptE('ptStatQList');
    if (list) list.style.display = 'none';
}
function ptStatPick(v) {   // 选中查看对象（候选列表/排行/✕ 均走此入口）
    var sid = parseInt(v, 10) || 0;
    PT_STAT_WHO = sid;
    ptStatFill();
    ptStatQHide();
    if (sid > 0) ptStatStuLoad(sid);
    else ptStatRefresh(PT_STAT_LAST || {});
}
function ptStatRefresh(d) {   // points_data 回调统一入口：按查看对象分流渲染（ptLoad 已附带 student_id，d.stu_stat 与当前 range 同步）
    PT_STAT_LAST = d;
    ptStatFill();
    if (PT_STAT_WHO > 0 && d.stu_stat) { PT_STU_STAT = d.stu_stat; PT_STU_F_ITEM = ''; PT_STU_F_DAY = ''; ptStatStuRender(); return; }
    if (PT_STAT_WHO > 0) { PT_STAT_WHO = 0; ptStatFill(); PT_STU_STAT = null; }   // 学生不可用（删/禁用）→ 回退全班
    else PT_STU_STAT = null;
    var all = ptE('ptStatAll'), stu = ptE('ptStatStu');
    if (all) all.style.display = '';
    if (stu) stu.style.display = 'none';
    ptDimChartBtns();   // 同步维度图型按钮态（HTML 初始态与 PT_DIM_CHART 默认值对齐）
    ptRenderRank(d.rank || []);
    ptRenderDims(d.dims || []);
    ptRenderLogs();
}
function ptStatStuLoad(sid) {   // 拉取个人统计（与全班共用 points_data，附带 student_id）
    var stu = ptE('ptStatStu'), all = ptE('ptStatAll');
    if (all) all.style.display = 'none';
    if (stu) { stu.style.display = ''; stu.innerHTML = '<div class="form-hint" style="padding:18px 0;text-align:center;">加载中…</div>'; }
    ptPost('type=points_data&class_id=' + PT_CLASS_ID + '&range=' + PT_RANGE + (PT_PROJECT_ID > 0 ? '&project_id=' + PT_PROJECT_ID : '') + '&student_id=' + sid)
    .then(function (d) {
        if (!d.success) { ptMsg(d.message || '加载失败', 'error'); return; }
        if (PT_STAT_WHO !== sid) return;   // 期间已切换对象，丢弃
        PT_STAT_LAST = d;
        PT_STU_STAT = d.stu_stat || null;
        PT_STU_F_ITEM = ''; PT_STU_F_DAY = '';   // 新数据载入，清空图表点击筛选
        if (!PT_STU_STAT) { PT_STAT_WHO = 0; ptStatRefresh(d); return; }   // 学生不可用 → 回退全班
        ptStatStuRender();
    })
    .catch(function () { ptMsg('网络错误', 'error'); });
}
function ptStuMode(m) {   // 个人趋势口径切换：day=每天净得分 / cum=累计
    PT_STU_MODE = (m === 'day') ? 'day' : 'cum';
    ptStatStuRender();
}
var PT_DONUT_SEQ = 0;
function ptDonutSvg(dims, total, clickable) {   // 维度占比环形图（ECharts chartCanvas 式；clickable=true 时点击扇区筛选个人流水，再点取消）
    if (clickable === undefined) clickable = true;
    var segs = [];
    dims.forEach(function (d) { if (d.value !== 0) segs.push(d); });
    if (!segs.length) return '<div class="form-hint" style="padding:14px 0;text-align:center;">该时间段暂无维度数据</div>';
    var id = 'ptDonutC' + (++PT_DONUT_SEQ);   // 唯一容器 id（全班/个人两处调用，innerHTML 插入后异步渲染）
    var signed = {};   // 维度原始带符号值（扇区按绝对值占比，标签显示带符号净得分）
    var items = segs.map(function (d, i) {
        signed[d.name] = d.value;
        return { name: d.name, value: Math.abs(d.value),
                 color: PT_STAT_COLORS[i % PT_STAT_COLORS.length],
                 sel: clickable && PT_STU_F_ITEM !== '' && PT_STU_F_ITEM === d.name };
    });
    setTimeout(function () {
        var ch = ecSet(id, ecPieOpt(items, {
            unit: '分',
            centerMain: (total > 0 ? '+' : '') + total,
            centerSub: '总积分',
            fmtLabel: function (p) {
                var v = signed[p.name] || 0;
                return p.name + '：' + (v > 0 ? '+' : '') + v + '分（' + p.percent + '%）';
            }
        }));
        if (ch && clickable) {
            ch.off('click');
            ch.on('click', function (p) { if (p.name) ptStuFilterItem(p.name); });
        }
    }, 0);
    return '<div id="' + id + '" style="height:230px;"></div>';
}
function ptLineSvg(daily, mode) {   // 得分趋势折线：mode=day 每天 / cum 累计（SVG 自绘，点悬停显示日期+分值）
    if (!daily || daily.length === 0) return '<div class="form-hint" style="padding:14px 0;text-align:center;">该时间段暂无积分记录</div>';
    var W = 460, H = 190, padL = 40, padR = 16, padT = 14, padB = 26;
    var key = mode === 'day' ? 'v' : 'c';
    var vals = daily.map(function (p) { return p[key]; });
    var max = Math.max.apply(null, vals), min = Math.min.apply(null, vals);
    if (max === min) { max += 1; min -= 1; }
    var span = max - min;
    function X(i) { return daily.length === 1 ? (padL + (W - padL - padR) / 2) : padL + (W - padL - padR) * i / (daily.length - 1); }
    function Y(v) { return padT + (H - padT - padB) * (1 - (v - min) / span); }
    var s = '<svg width="100%" viewBox="0 0 ' + W + ' ' + H + '" style="display:block;">';
    [0, 0.25, 0.5, 0.75, 1].forEach(function (f) {
        var y = padT + (H - padT - padB) * f;
        s += '<line x1="' + padL + '" y1="' + y + '" x2="' + (W - padR) + '" y2="' + y + '" stroke="#eef1f8" stroke-width="1"/>';
        s += '<text x="' + (padL - 5) + '" y="' + (y + 3) + '" font-size="10" fill="#c3c9dd" text-anchor="end">' + Math.round(max - span * f) + '</text>';
    });
    if (daily.length > 1) s += '<polyline points="' + daily.map(function (p, i) { return X(i) + ',' + Y(p[key]); }).join(' ') + '" fill="none" stroke="#4285f4" stroke-width="2.5" stroke-linejoin="round"/>';
    daily.forEach(function (p, i) {
        var sel = PT_STU_F_DAY !== '' && PT_STU_F_DAY === p.d;   // 选中节点放大高亮，点击筛选该日流水
        s += '<circle cx="' + X(i) + '" cy="' + Y(p[key]) + '" r="' + (sel ? 5.5 : 3.2) + '" fill="' + (sel ? '#e74c3c' : '#4285f4') + '" style="cursor:pointer;"'
           + ' data-day="' + ptEsc(p.d) + '" onclick="ptStuFilterDay(this.dataset.day)"><title>' + ptEsc(p.d) + '：' + (p.v > 0 ? '+' : '') + p.v + '（累计 ' + p.c + '）（点击筛选当日流水）</title></circle>';
    });
    s += '<text x="' + padL + '" y="' + (H - 8) + '" font-size="10" fill="#999">' + ptEsc(daily[0].d) + '</text>';
    s += '<text x="' + (W - padR) + '" y="' + (H - 8) + '" font-size="10" fill="#999" text-anchor="end">' + ptEsc(daily[daily.length - 1].d) + '</text>';
    s += '</svg>';
    return s;
}
function ptStatStuRender() {   // 个人视图渲染：标题 + 环形占比 + 趋势折线（每天/累计切换）+ 个人流水
    var st = PT_STU_STAT;
    var box = ptE('ptStatStu');
    if (!box || !st) return;
    var html = '<div style="border:1px solid #e5e5e5;border-radius:8px;padding:10px 12px;margin-bottom:10px;">'
             + '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">'
             + '<b style="font-size:14px;">📊 ' + ptEsc(st.name) + (String(st.seat) !== '' ? ' <span style="color:#999;font-size:12px;font-weight:normal;">' + ptEsc(st.seat) + '号</span>' : '')
             + '<span style="color:#999;font-size:12px;font-weight:normal;margin-left:8px;">各维度得分占比</span></b>'
             + '<span style="margin-left:auto;display:inline-flex;gap:3px;">'
             + '<button type="button" class="btn btn-sm' + (PT_STU_CHART === 'pie' ? '' : ' btn-outline') + '" style="padding:1px 8px;font-size:12px;" onclick="ptStuChart(\'pie\')" title="环形图">🥧</button>'
             + '<button type="button" class="btn btn-sm' + (PT_STU_CHART === 'bar' ? '' : ' btn-outline') + '" style="padding:1px 8px;font-size:12px;" onclick="ptStuChart(\'bar\')" title="条形图">📉</button>'
             + '</span></div>'
             + '<div style="margin-top:8px;">' + (PT_STU_CHART === 'bar'
                ? ptDimsBarHtml(st.dims || [])
                : ptDonutSvg(st.dims || [], st.total || 0, true)) + '</div></div>';
    html += '<div style="border:1px solid #e5e5e5;border-radius:8px;padding:10px 12px;margin-bottom:10px;">'
          + '<b style="font-size:14px;">📈 个人得分趋势'
          + '<span style="margin-left:10px;display:inline-flex;gap:4px;vertical-align:middle;">'
          + '<button type="button" class="btn btn-sm' + (PT_STU_MODE === 'day' ? '' : ' btn-outline') + '" onclick="ptStuMode(\'day\')">📅 每天</button>'
          + '<button type="button" class="btn btn-sm' + (PT_STU_MODE === 'cum' ? '' : ' btn-outline') + '" onclick="ptStuMode(\'cum\')">📈 累计</button>'
          + '</span></b>'
          + '<div style="margin-top:6px;">' + ptLineSvg(st.daily || [], PT_STU_MODE) + '</div></div>';
    // 流水（维度/日期点击筛选可叠加：空 item 归入「自定义」维度；at 前 10 位=日期）
    var logs = st.logs || [];
    var fl = logs.filter(function (l) {
        if (PT_STU_F_ITEM !== '' && l.item !== PT_STU_F_ITEM && !(PT_STU_F_ITEM === '自定义' && l.item === '')) return false;
        if (PT_STU_F_DAY !== '' && String(l.at).slice(0, 10) !== PT_STU_F_DAY) return false;
        return true;
    });
    html += '<div id="ptStuLogBox"><div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;"><b style="font-size:14px;">📋 积分流水（' + fl.length + (fl.length !== logs.length ? ' / ' + logs.length : '') + ' 条）</b>';
    if (PT_STU_F_ITEM !== '') html += '<span style="background:#eef2ff;color:#4a5fc1;border-radius:12px;padding:2px 10px;font-size:12px;">维度：' + ptEsc(PT_STU_F_ITEM) + ' <b style="cursor:pointer;" onclick="ptStuFilterItem(\'\')" title="清除该筛选">×</b></span>';
    if (PT_STU_F_DAY !== '') html += '<span style="background:#fdeeee;color:#c0392b;border-radius:12px;padding:2px 10px;font-size:12px;">日期：' + ptEsc(PT_STU_F_DAY) + ' <b style="cursor:pointer;" onclick="ptStuFilterDay(\'\')" title="清除该筛选">×</b></span>';
    html += '</div>'
          + '<div class="pt-tab-body" style="border:1px solid #e5e5e5;border-radius:8px;padding:6px 10px;max-height:26vh;overflow-y:auto;">';
    if (fl.length === 0) html += '<div class="form-hint" style="padding:10px;">' + (logs.length === 0 ? '该时间段暂无积分记录' : '没有符合条件的流水') + '</div>';
    else fl.forEach(function (l) { html += ptLogRowHtml(l); });
    html += '</div></div>';
    box.innerHTML = html;
    box.style.display = '';
    var all = ptE('ptStatAll');
    if (all) all.style.display = 'none';
}
function ptStuFilterItem(name) {   // 点击环形图扇区/图例：筛选该维度流水，再点同项取消
    PT_STU_F_ITEM = (PT_STU_F_ITEM === name) ? '' : name;
    ptStatStuRender();
    ptStuLogScroll();
}
function ptStuFilterDay(day) {   // 点击趋势折线节点：筛选当日流水，再点同节点取消
    PT_STU_F_DAY = (PT_STU_F_DAY === day) ? '' : day;
    ptStatStuRender();
    ptStuLogScroll();
}
function ptStuLogScroll() {   // 筛选后滚动定位到流水区
    var b = ptE('ptStuLogBox');
    if (b && b.scrollIntoView) b.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// ===== 设置：积分项编辑 =====
var PT_COLORS = ['#27ae60', '#3498db', '#9b59b6', '#e67e22', '#16a085', '#e74c3c', '#f39c12', '#667eea'];
function ptCfgInit() {
    var _c = ptE('ptCfgList');
    if (!_c) return;                                        // ⚙ 面板未渲染（无设置权限）：跳过，避免 TypeError 误报网络错误
    if (_c.dataset.loaded === '1') return;   // 已初始化过（保存后 ptReloadCfg 手动同步）
    PT_CFG = JSON.parse(JSON.stringify(PT_ITEMS));
    _c.dataset.loaded = '1';
    ptCfgRender();
}
function ptReloadCfg() {
    PT_CFG = JSON.parse(JSON.stringify(PT_ITEMS));
    ptCfgRender();
}
function ptCfgRender() {
    var html = '';
    PT_CFG.forEach(function (it, i) {
        var tp = parseInt(it.type, 10) === 2 ? 2 : 1;
        var sl = parseInt(it.show_lot, 10) !== 0;    // 是否显示在随机抽选（默认显示）
        var sq = parseInt(it.show_quick, 10) !== 0;  // 是否显示在一键加分（默认显示）
        html += '<div class="pt-cfg-row">'
              + '<input type="color" value="' + ptEsc(it.color) + '" oninput="PT_CFG[' + i + '].color=this.value" title="按钮颜色" style="width:36px;height:32px;padding:0;border:1px solid #dfe6f0;border-radius:6px;cursor:pointer;" autocomplete="off">'
              + '<input type="text" class="form-control" maxlength="20" placeholder="积分项名称" value="' + ptEsc(it.name) + '" oninput="PT_CFG[' + i + '].name=this.value" autocomplete="nope" readonly onfocus="this.removeAttribute(\'readonly\')">'
              + '<input type="number" class="form-control" min="-100" max="100" step="1" value="' + it.value + '" oninput="PT_CFG[' + i + '].value=parseInt(this.value||\'0\',10)" title="分值（负数=扣分项）" autocomplete="off">'
              + '<select class="form-control" style="flex:none;width:84px;" onchange="PT_CFG[' + i + '].type=parseInt(this.value,10)" title="类型：个人=给选中学生加减分；小组=给选中学生所在组每人加减分">'
              + '<option value="1"' + (tp === 1 ? ' selected' : '') + '>👤 个人</option>'
              + '<option value="2"' + (tp === 2 ? ' selected' : '') + '>👥 小组</option>'
              + '</select>'
              + '<label style="display:inline-flex;align-items:center;gap:2px;font-size:12px;color:#667;white-space:nowrap;cursor:pointer;" title="是否显示在「🎰 随机抽选」结果按钮中"><input type="checkbox" ' + (sl ? 'checked' : '') + ' onchange="PT_CFG[' + i + '].show_lot=this.checked?1:0" autocomplete="off">抽选</label>'
              + '<label style="display:inline-flex;align-items:center;gap:2px;font-size:12px;color:#667;white-space:nowrap;cursor:pointer;" title="是否显示在「⚡ 快速加分」的积分项按钮中"><input type="checkbox" ' + (sq ? 'checked' : '') + ' onchange="PT_CFG[' + i + '].show_quick=this.checked?1:0" autocomplete="off">加分</label>'
              + '<button type="button" class="btn btn-sm btn-outline" onclick="ptCfgDel(' + i + ')">✕</button>'
              + '</div>';
    });
    ptE('ptCfgList').innerHTML = html || '<div class="form-hint">暂无积分项</div>';
}
function ptCfgAdd() {
    if (PT_CFG.length >= 20) { ptMsg('最多 20 个积分项', 'error'); return; }
    PT_CFG.push({ name: '', value: 1, color: PT_COLORS[PT_CFG.length % PT_COLORS.length], type: 1, show_lot: 1, show_quick: 1 });
    ptCfgRender();
}
function ptCfgDel(i) { PT_CFG.splice(i, 1); ptCfgRender(); }
function ptCfgSave() {
    var items = PT_CFG.filter(function (it) { return it.name.trim() !== ''; });
    if (!items.length) { ptMsg('请至少保留一个有名称的积分项', 'error'); return; }
    ptPost('type=points_items_save&class_id=' + PT_CLASS_ID + '&items=' + encodeURIComponent(JSON.stringify(items)))
    .then(function (d) {
        if (!d.success) { ptMsg(d.message || '保存失败', 'error'); return; }
        PT_ITEMS = d.items || [];
        ptMsg(d.message, 'ok');
        ptReloadCfg();
        ptRenderItems();
    }).catch(function () { ptMsg('网络错误', 'error'); });
}

// ===== ⚙ 设置：🤖 项目自动加减分规则（project_view=固定本项目；projects.php=下拉选择有权限项目） =====
function ptAutoEnsure() {
    if (PT_PROJECT_ID > 0) {
        if (PT_AUTO_PID !== PT_PROJECT_ID || !ptE('ptAutoList').dataset.loaded) {
            PT_AUTO_PID = PT_PROJECT_ID;
            ptAutoLoad();
        }
        return;
    }
    // projects.php：先拉当前教师可管理（创建人/学校管理员/班级 manage+create 授权）的项目列表
    if (PT_AUTO_PROJS.length) { ptAutoPickRender(); return; }
    ptE('ptAutoList').innerHTML = '<span class="form-hint">加载中…</span>';
    ptPost('type=points_rule_projects').then(function (d) {
        if (!d.success) { ptE('ptAutoList').innerHTML = '<div class="form-hint">' + ptEsc(d.message || '加载失败') + '</div>'; return; }
        PT_AUTO_PROJS = d.projects || [];
        ptAutoPickRender();
    }).catch(function () { ptE('ptAutoList').innerHTML = '<div class="form-hint">网络错误</div>'; });
}
function ptAutoPickRender() {
    ptE('ptAutoProjPick').style.display = '';
    ptE('ptAutoProj').textContent = '';
    var sel = ptE('ptAutoProjSel');
    sel.innerHTML = '<option value="0">— 选择项目 —</option>' + PT_AUTO_PROJS.map(function (p) {
        return '<option value="' + p.id + '"' + (p.id === PT_AUTO_PID ? ' selected' : '') + '>'
             + ptEsc(p.name) + (p.class_name ? '（' + ptEsc(p.class_name) + '）' : '') + ' · ' + ptEsc(p.mode_name) + '</option>';
    }).join('');
    if (!PT_AUTO_PROJS.length) {
        ptE('ptAutoList').innerHTML = '<div class="form-hint">暂无可管理的项目</div>';
    } else if (!PT_AUTO_PID) {
        ptE('ptAutoList').innerHTML = '<div class="form-hint">先选择项目再配置规则</div>';
    }
}
function ptAutoProjChange() {
    PT_AUTO_PID = parseInt(ptE('ptAutoProjSel').value || '0', 10);
    if (PT_AUTO_PID > 0) ptAutoLoad();
    else ptAutoPickRender();
}
function ptAutoLoad() {
    if (!PT_AUTO_PID) return;
    ptE('ptAutoList').dataset.loaded = '1';
    ptE('ptAutoList').innerHTML = '<span class="form-hint">加载中…</span>';
    ptPost('type=points_rules_get&project_id=' + PT_AUTO_PID).then(function (d) {
        if (!d.success) { ptE('ptAutoList').innerHTML = '<div class="form-hint">' + ptEsc(d.message || '加载失败') + '</div>'; return; }
        PT_AUTO_META = d.meta || {};
        PT_AUTO_EVAL_OPTS = d.eval_opts || [];
        PT_AUTO_LATE = parseInt((d.project || {}).late_seconds, 10) || 0;
        PT_AUTO_RULES = (d.rules || []).map(function (r) {
            return { rtype: r.rtype, opt: r.opt, value: r.value, enabled: r.enabled };
        });
        var pj = d.project || {};
        ptE('ptAutoProj').textContent = pj.name ? '（' + pj.name + ' · ' + (pj.mode_name || '') + '）' : '';
        ptAutoRender();
    }).catch(function () { ptE('ptAutoList').innerHTML = '<div class="form-hint">网络错误</div>'; });
}
function ptAutoRender() {
    var keys = Object.keys(PT_AUTO_META);
    var html = '';
    PT_AUTO_RULES.forEach(function (r, i) {
        var m = PT_AUTO_META[r.rtype] || { name: r.rtype, param: 'none', desc: '' };
        var param = '';
        if (m.param === 'opt') {
            // 评价内容只能从下拉选择（评价模式预设选项 + 项目实际用到的评价值），不可手动输入；
            // 历史自由填写的规则值保留为该规则的可选项，避免旧规则打开后被强制改值
            var evalOpts = PT_AUTO_EVAL_OPTS.slice();
            var curOpt = String(r.opt);
            if (curOpt !== '' && evalOpts.indexOf(curOpt) === -1) evalOpts.unshift(curOpt);
            if (evalOpts.length) {
                param = '<select class="form-control" style="min-width:110px;" title="匹配评价内容：只能从评价选项中选择，不能手动输入"'
                      + ' onchange="PT_AUTO_RULES[' + i + '].opt=this.value">'
                      + (curOpt === '' ? '<option value="" selected>请选择评价</option>' : '')
                      + evalOpts.map(function (o) { return '<option value="' + ptEsc(o) + '"' + (o === curOpt ? ' selected' : '') + '>' + ptEsc(o) + '</option>'; }).join('')
                      + '</select>';
            } else {
                param = '<select class="form-control" style="min-width:110px;" disabled title="该项目还没有评价选项（评价模式无预设选项且尚无评价记录），此规则类型暂不可用"><option value="">（暂无评价选项）</option></select>';
            }
        } else if (m.param === 'num') {
            param = '<input type="number" class="form-control" step="any" min="0" placeholder="阈值" value="' + ptEsc(r.opt) + '" oninput="PT_AUTO_RULES[' + i + '].opt=this.value" style="width:88px;flex:none;" autocomplete="off">';
        } else {
            param = '<span style="color:#bbb;">—</span>';
        }
        var warn = (m.need_late && PT_AUTO_LATE <= 0)
            ? '<span style="color:#e74c3c;font-size:11px;white-space:nowrap;" title="该项目未开启补登记阈值（超时设置），此规则不会生效">⚠ 未开启超时</span>'
            : '';
        html += '<div class="pt-cfg-row">'
              + '<select class="form-control" style="flex:none;width:128px;" title="' + ptEsc(m.desc || '') + '"'
              + ' onchange="PT_AUTO_RULES[' + i + '].rtype=this.value;PT_AUTO_RULES[' + i + '].opt=\'\';ptAutoRender()">'
              + keys.map(function (k) {
                    return '<option value="' + k + '"' + (k === r.rtype ? ' selected' : '') + '>' + ptEsc(PT_AUTO_META[k].name) + '</option>';
                }).join('')
              + '</select>'
              + param
              + '<input type="number" class="form-control" min="-100" max="100" step="1" value="' + parseInt(r.value, 10) + '" oninput="PT_AUTO_RULES[' + i + '].value=parseInt(this.value||\'0\',10)" title="分值：正=加分 负=扣分" style="width:72px;flex:none;" autocomplete="off">'
              + '<label style="display:inline-flex;align-items:center;gap:2px;font-size:12px;color:#667;cursor:pointer;white-space:nowrap;" title="停用后该规则暂不参与计分（保存时不删除）"><input type="checkbox" ' + (parseInt(r.enabled, 10) ? 'checked' : '') + ' onchange="PT_AUTO_RULES[' + i + '].enabled=this.checked?1:0" autocomplete="off">启用</label>'
              + warn
              + '<button type="button" class="btn btn-sm btn-outline" onclick="ptAutoDel(' + i + ')" title="删除该规则（保存重算后已按其加的分将回档）">✕</button>'
              + '</div>';
    });
    if (!PT_AUTO_RULES.length) {
        html += '<div class="form-hint">暂无规则<button type="button" class="hint-q" onclick="toggleHint(event, \'<b>积分自动规则</b><br>· 点「＋ 增加规则」按当前项目登记模式自由组合<br>· 如：登记成功 +2 / 超时未登记 −1 / 答题正确 +1…\')">?</button></div>';
    }
    ptE('ptAutoList').innerHTML = html;
}
function ptAutoAdd() {
    var keys = Object.keys(PT_AUTO_META);
    if (!PT_AUTO_PID) { ptMsg('请先选择项目', 'error'); return; }
    if (!keys.length) { ptMsg('该项目暂无可用的规则类型', 'error'); return; }
    PT_AUTO_RULES.push({ rtype: keys[0], opt: '', value: 1, enabled: 1 });
    ptAutoRender();
}
function ptAutoDel(i) { PT_AUTO_RULES.splice(i, 1); ptAutoRender(); }
function ptAutoSave() {
    if (!PT_AUTO_PID) { ptMsg('请先选择项目', 'error'); return; }
    var rules = [];
    for (var i = 0; i < PT_AUTO_RULES.length; i++) {
        var r = PT_AUTO_RULES[i];
        var m = PT_AUTO_META[r.rtype];
        if (!m) continue;
        if (parseInt(r.value, 10) === 0) { ptMsg('第 ' + (i + 1) + ' 条分值不能为 0（正加负扣）', 'error'); return; }
        if (m.param === 'opt' && String(r.opt).trim() === '') { ptMsg('第 ' + (i + 1) + ' 条请选择评价内容', 'error'); return; }
        if (m.param === 'num' && (String(r.opt).trim() === '' || isNaN(parseFloat(r.opt)))) { ptMsg('第 ' + (i + 1) + ' 条规则请填写数字阈值', 'error'); return; }
        rules.push({ rtype: r.rtype, opt: String(r.opt).trim(), value: parseInt(r.value, 10), enabled: parseInt(r.enabled, 10) ? 1 : 0 });
    }
    if (!confirm('保存后将按当前数据全量重算本项目的自动积分（旧规则已加的自动回档、未应用的直接补足），确认？')) return;
    ptPost('type=points_rules_save&project_id=' + PT_AUTO_PID + '&rules=' + encodeURIComponent(JSON.stringify(rules)))
    .then(function (d) {
        if (!d.success) { ptMsg(d.message || '保存失败', 'error'); return; }
        ptMsg(d.message, 'ok');
        ptLoad();   // 刷新流水（🤖 标注）与汇总分析
    }).catch(function () { ptMsg('网络错误', 'error'); });
}

// ===== 🎁 积分兑换（礼品按班配置：条形选中 + ✎/✕ 管理；学生网格与「快速加分」共用同一 DOM） =====
function ptGiftRender() {
    var bar = ptE('ptGiftBar');
    var addBtn = '<button type="button" class="btn btn-sm btn-outline" onclick="ptGiftNew(-1)" title="新增兑换礼品（名称 + 所需积分）">＋ 添加礼品</button>';
    if (!PT_GIFTS.length) {
        bar.innerHTML = '<span class="form-hint">还没有礼品<button type="button" class="hint-q" onclick="toggleHint(event, \'<b>积分兑换礼品</b><br>· 点「＋ 添加礼品」设置兑换物品（如：免作业券 5 分）<br>· 学生用积分兑换\')">?</button></span>' + addBtn;
    } else {
        bar.innerHTML = PT_GIFTS.map(function (g) {
            var on = String(g.id) === String(PT_GIFT_SEL);
            return '<span class="pt-gift-chip' + (on ? ' on' : '') + '" onclick="ptGiftPick(' + g.id + ')" title="点击选中，勾选学生后点「🎁 确认兑换」">'
                 + ptEsc(g.name) + ' <b>' + g.cost + '分</b>'
                 + '<a href="javascript:void(0)" class="pt-gift-op" onclick="ptGiftEdit(' + g.id + ', event)" title="编辑名称/积分">✎</a>'
                 + '<a href="javascript:void(0)" class="pt-gift-op" onclick="ptGiftDel(' + g.id + ', event)" title="删除礼品">✕</a>'
                 + '</span>';
        }).join('') + addBtn;
    }
    var g = null;
    PT_GIFTS.forEach(function (x) { if (String(x.id) === String(PT_GIFT_SEL)) g = x; });
    ptE('ptGiftInfo').textContent = g ? '已选「' + g.name + '」（' + g.cost + ' 分/人），在下方勾选学生后点「🎁 确认兑换」' : '先点选礼品，再在下方勾选学生';
}
function ptGiftFind() {
    var g = null;
    PT_GIFTS.forEach(function (x) { if (String(x.id) === String(PT_GIFT_SEL)) g = x; });
    return g;
}
function ptGiftPick(id) {
    PT_GIFT_SEL = (String(PT_GIFT_SEL) === String(id)) ? 0 : id;   // 再点一次取消选中
    ptGiftNew(0);   // 收起编辑表单
    ptGiftRender();
}
function ptGiftNew(id) {   // id=-1 新增；id>0 编辑；id=0 取消收起
    PT_GIFT_EDIT = id > 0 ? id : 0;
    ptE('ptGiftForm').style.display = id !== 0 ? '' : 'none';
    if (id !== 0) {
        var g = null;
        PT_GIFTS.forEach(function (x) { if (parseInt(x.id, 10) === parseInt(id, 10)) g = x; });
        ptE('ptGiftName').value = g ? g.name : '';
        ptE('ptGiftCost').value = g ? g.cost : '';
        if (ptE('ptGiftName').value !== '') ptE('ptGiftName').focus();
    }
}
function ptGiftEdit(id, e) {
    if (e) { e.stopPropagation(); }
    ptGiftNew(id);
}
function ptGiftSave() {
    var name = ptE('ptGiftName').value.trim();
    var cost = parseInt(ptE('ptGiftCost').value, 10);
    if (name === '') { ptMsg('请填写礼品名称', 'error'); return; }
    if (!cost || cost <= 0) { ptMsg('兑换所需积分需为正整数', 'error'); return; }
    ptPost('type=points_gift_save&class_id=' + PT_CLASS_ID + '&id=' + PT_GIFT_EDIT
         + '&name=' + encodeURIComponent(name) + '&cost=' + cost)
    .then(function (d) {
        if (!d.success) { ptMsg(d.message || '保存失败', 'error'); return; }
        PT_GIFTS = d.gifts || [];
        ptGiftNew(0);
        ptGiftRender();
        ptMsg(d.message, 'ok');
    }).catch(function () { ptMsg('网络错误', 'error'); });
}
function ptGiftDel(id, e) {
    if (e) { e.stopPropagation(); }
    if (!confirm('确认删除该礼品？（不影响已兑换的历史记录）')) return;
    ptPost('type=points_gift_del&class_id=' + PT_CLASS_ID + '&id=' + id)
    .then(function (d) {
        if (!d.success) { ptMsg(d.message || '删除失败', 'error'); return; }
        if (String(PT_GIFT_SEL) === String(id)) PT_GIFT_SEL = 0;
        PT_GIFTS = d.gifts || [];
        ptGiftRender();
        ptMsg(d.message, 'ok');
    }).catch(function () { ptMsg('网络错误', 'error'); });
}
function ptRedeemGo() {
    var g = ptGiftFind();
    if (!g) { ptMsg('请先点选一个礼品', 'error'); return; }
    if (!PT_SEL.length) { ptMsg('请先勾选要兑换的学生', 'error'); return; }
    if (!confirm('确认给选中的 ' + PT_SEL.length + ' 名学生兑换「' + g.name + '」？\n每人 −' + g.cost + ' 分，余额不足者自动跳过。')) return;
    ptPost('type=points_redeem&class_id=' + PT_CLASS_ID + '&gift_id=' + g.id + '&student_ids=' + encodeURIComponent(JSON.stringify(PT_SEL)))
    .then(function (d) {
        ptMsg(d.message || (d.success ? '兑换成功' : '兑换失败'), d.success ? 'ok' : 'error');
        if (d.success) { PT_SEL = []; ptRenderStu(); }
        ptLoad();   // 刷新余额 / 流水
    }).catch(function () { ptMsg('网络错误', 'error'); });
}

// ===== 📥 导入 / 导出 / 清空（数据迁移；导入与清空需当前账号登录密码防误触） =====
function ptIoToggle() {
    var f = ptE('ptIoForm');
    var show = f.style.display === 'none';
    f.style.display = show ? '' : 'none';
    if (show) { ptIoKindTip(); ptE('ptIoResult').style.display = 'none'; }
}
function ptIoKindTip() {
    var k = document.querySelector('input[name="ptIoKind"]:checked');
    ptE('ptIoTip').innerHTML = (k && k.value === 'log')
        ? '格式：每行 <b>时间,姓名,编号,积分项,分值[,备注]</b>（与本系统导出的「过程流水」一致，制表符/逗号均可）。按原时间逐条写入，最终结果由增减自动累计。'
        : '格式：每行 <b>编号,姓名,积分</b>（也可只有 <b>姓名,积分</b>；末字段=积分）。导入后每生记一条「积分导入」流水（谁什么时候导入多少分），之后在此基础上增减。';
}
function ptIoSubmit() {
    var pwd = String(ptE('ptIoPwd').value);
    var content = ptE('ptIoContent').value;
    var file = ptE('ptIoFile').files.length ? ptE('ptIoFile').files[0] : null;
    if (pwd === '') { ptMsg('请输入登录密码（防误触）', 'error'); return; }
    if (content.trim() === '' && !file) { ptMsg('请粘贴导入内容或选择 CSV 文件', 'error'); return; }
    var kind = document.querySelector('input[name="ptIoKind"]:checked');
    var fd = new FormData();
    fd.append('type', 'points_import');
    fd.append('class_id', PT_CLASS_ID);
    fd.append('kind', kind ? kind.value : 'balance');
    fd.append('clear_first', ptE('ptIoClear').checked ? '1' : '0');
    fd.append('content', content);
    fd.append('password', pwd);
    if (file) fd.append('file', file);
    var box = ptE('ptIoResult');
    box.style.display = 'block';
    box.style.whiteSpace = 'pre-wrap';
    box.style.background = '#fdf6e3';
    box.style.color = '#7d6608';
    box.textContent = '正在导入…';
    fetch('api.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        box.style.background = d.success ? '#eafaf1' : '#fdecea';
        box.style.color = d.success ? '#2c7a3f' : '#c0392b';
        var txt = d.message || (d.success ? '导入完成' : '导入失败');
        if (d.skips && d.skips.length) txt += '\n' + d.skips.join('\n');
        box.textContent = txt;
        if (d.success) { ptE('ptIoPwd').value = ''; ptE('ptIoContent').value = ''; ptE('ptIoFile').value = ''; ptLoad(); }
    })
    .catch(function () { box.style.display = 'none'; ptMsg('网络错误', 'error'); });
}
function ptClearAll() {
    if (!confirm('⚠ 确认清空本班所有积分？\n全部积分流水将被删除（保留一条清空审计记录），且不可恢复。\n建议先「⬇ 导出过程流水」备份。')) return;
    var pwd = prompt('请输入当前账号登录密码以确认清空：');
    if (pwd === null) return;
    if (String(pwd) === '') { ptMsg('密码不能为空', 'error'); return; }
    ptPost('type=points_clear_all&class_id=' + PT_CLASS_ID + '&password=' + encodeURIComponent(pwd))
    .then(function (d) {
        ptMsg(d.message || (d.success ? '已清空' : '操作失败'), d.success ? 'ok' : 'error');
        if (d.success) ptLoad();
    }).catch(function () { ptMsg('网络错误', 'error'); });
}

// ===== 全屏随机抽选（大屏教学：名字超大滚动 → 5 秒自动停止/手动停止 → 组名+姓名+个人/小组积分按钮） =====
var PT_LOT = { timer: null, auto: null, pool: [], winner: null };   // pool=未抽中学生池
function ptLotOpen() {
    if (!PT_STU.length) { ptMsg('本班暂无学生，无法抽选', 'error'); return; }
    ptE('ptLotMask').style.display = 'flex';
    ptLotStart();
}
function ptLotClose() {
    ptLotTimerClear();
    ptE('ptLotMask').style.display = 'none';
}
function ptLotTimerClear() {
    if (PT_LOT.timer) { clearInterval(PT_LOT.timer); PT_LOT.timer = null; }
    if (PT_LOT.auto) { clearTimeout(PT_LOT.auto); PT_LOT.auto = null; }
}
function ptLotMsg(msg, type) {
    var el = ptE('ptLotMsg');
    if (!msg) { el.style.display = 'none'; return; }
    el.style.display = 'block';
    el.style.background = type === 'error' ? '#fdecea' : '#eafaf1';
    el.style.color = type === 'error' ? '#c0392b' : '#2c7a3f';
    el.textContent = msg;
    clearTimeout(ptLotMsg._t);
    ptLotMsg._t = setTimeout(function () { el.style.display = 'none'; }, 4000);
}
// 开始滚动：仅从「未抽中」的学生池随机轮换（setInterval 65ms），5 秒未点「停止抽选」自动停止
function ptLotStart() {
    ptLotTimerClear();
    var pool = PT_STU.filter(function (s) { return !PT_LOT_PICKED[String(s.id)]; });
    if (!pool.length) {
        PT_LOT.winner = null;
        ptE('ptLotMask').style.display = 'none';
        ptMsg('本班学生均已抽中，请先清空抽选记录', 'error');
        return;
    }
    PT_LOT.pool = pool;
    PT_LOT.winner = null;
    ptLotMsg('');
    ptE('ptLotDone').style.display = 'none';
    ptE('ptLotRoll').style.display = 'flex';
    ptE('ptLotStop').style.display = '';
    ptE('ptLotAgain').style.display = 'none';
    var names = pool.map(function (s) { return s.name; });
    PT_LOT.timer = setInterval(function () {
        ptE('ptLotName').textContent = names[Math.floor(Math.random() * names.length)];
    }, 65);
    PT_LOT.auto = setTimeout(ptLotStop, 5000);
}
// 停止（手动或 5 秒自动）：随机定名+登记中签记录（未清空前不再抽中），显示组名+姓名+积分按钮
function ptLotStop() {
    if (!PT_LOT.timer) return;
    ptLotTimerClear();
    var pool = PT_LOT.pool.length ? PT_LOT.pool : PT_STU;
    var w = pool[Math.floor(Math.random() * pool.length)];
    PT_LOT.winner = w;
    PT_LOT_PICKED[String(w.id)] = new Date().toTimeString().slice(0, 8);   // 本地即时标记，服务端异步登记
    ptPost('type=points_lot_add&class_id=' + PT_CLASS_ID + '&sid=' + w.id).catch(function () {});
    PT_SEL = [w.id];   // 与积分弹窗选中态同步（关掉全屏后可直接继续打分）
    ptRenderStu();
    ptE('ptLotRoll').style.display = 'none';
    ptE('ptLotDone').style.display = '';
    ptE('ptLotStop').style.display = 'none';
    ptE('ptLotAgain').style.display = '';
    var g = null, gid = String(parseInt(w.group_id || 0, 10));
    PT_GROUPS.forEach(function (x) { if (String(x.id) === gid) g = x; });
    ptE('ptLotStu').textContent = w.name;
    ptE('ptLotGrp').textContent = g ? g.name : '未分组';
    ptLotTotal();
    ptLotRenderBtns();
}
function ptLotTotal() {
    var w = PT_LOT.winner;
    ptE('ptLotTotal').textContent = (w && PT_STU_MAP[w.id]) ? ('当前 ' + PT_STU_MAP[w.id].total + ' 分') : '';
}
// 抽中者的计分对象：个人=[本人]；小组=同 group_id 全体（未分组返回空由调用方报错）
function ptLotSids(grp) {
    var w = PT_LOT.winner;
    if (!w) return [];
    if (!grp) return [w.id];
    var gid = String(parseInt(w.group_id || 0, 10));
    if (gid === '0') return [];
    return PT_STU.filter(function (s) { return String(parseInt(s.group_id || 0, 10)) === gid; }).map(function (s) { return s.id; });
}
function ptLotBtnHtml(it, grp) {
    var v = it.value > 0 ? '+' + it.value : String(it.value);
    var tip = (grp ? '小组积分：' : '个人积分：') + it.name + ' ' + v + (grp ? '（本组每人各记一条）' : '');
    return '<button type="button" class="pt-item-btn" style="background:' + ptEsc(it.color) + ';" '
         + 'onclick="ptLotGive(' + it.id + ',0,' + (grp ? 1 : 0) + ')" title="' + ptEsc(tip) + '">'
         + '<span class="pt-ico">' + (grp ? '👥' : '👤') + '</span>' + ptEsc(it.name) + ' <span>' + v + '</span></button>';
}
function ptLotRenderBtns() {
    var per = '', grp = '';
    PT_ITEMS.forEach(function (it) {
        if (parseInt(it.show_lot, 10) !== 1) return;   // 设置中未勾选「抽选」的不出现在抽选结果里
        if (parseInt(it.type, 10) === 2) grp += ptLotBtnHtml(it, true); else per += ptLotBtnHtml(it, false);
    });
    ptE('ptLotBtns').innerHTML = (per + grp)
        || '<div class="form-hint" style="padding:4px 0;">没有可显示的积分项，请到「⚙ 设置」勾选「抽选」。</div>';
}
// 抽选结果打分：个人项记本人 1 条；小组项给整组每人各记 1 条（服务端 points_add 支持批量）
function ptLotGive(itemId, custom, grpFlag) {
    var w = PT_LOT.winner;
    if (!w) return;
    var grp = parseInt(grpFlag, 10) === 1;
    var sids = ptLotSids(grp);
    if (!sids.length) { ptLotMsg('该学生未分组，无法小组加分（可先在班级「设置分组」中完成分组）', 'error'); return; }
    var body = 'type=points_add&class_id=' + PT_CLASS_ID
             + '&student_ids=' + encodeURIComponent(JSON.stringify(sids))
             + '&item_id=' + (itemId || 0) + '&custom=' + (custom || 0);
    var remark = ptE('ptRemark').value.trim();
    if (remark !== '') body += '&remark=' + encodeURIComponent(remark);
    ptPost(body).then(function (d) {
        if (!d.success) { ptLotMsg(d.message || '操作失败', 'error'); return; }
        ptLotMsg((grp ? '👥 小组' : '👤 个人') + d.message + (remark !== '' ? '（备注：' + remark + '）' : ''), 'ok');
        sids.forEach(function (sid) { if (PT_STU_MAP[sid]) PT_STU_MAP[sid].total += d.value; });
        ptLotTotal();
        ptRenderStu();
        ptLoad();   // 静默刷新流水与汇总（选中态保留）
    }).catch(function () { ptLotMsg('网络错误', 'error'); });
}

// ===== 长按学生卡片 → 单人加减分面板（加分/减分上下分组；全部积分项均可用，不受设置勾选限制） =====
var PT_PRESS_SID = 0;       // 面板当前学生 id
var PT_PRESS_FIRED = false; // 长按已触发（吞掉随后的 click，避免误改选中态）
var PT_PRESS_T = null;      // 长按定时器
function ptPressSecHtml(items, pos) {
    var html = '';
    items.forEach(function (it) {
        var grp = parseInt(it.type, 10) === 2;
        var v = it.value > 0 ? '+' + it.value : String(it.value);
        var tip = (grp ? '小组积分：' : '个人积分：') + it.name + ' ' + v + (grp ? '（本组每人各记一条）' : '');
        html += '<button type="button" class="pt-item-btn" style="background:' + ptEsc(it.color) + ';" '
              + 'onclick="ptPressGive(' + it.id + ')" title="' + ptEsc(tip) + '"><span class="pt-ico">' + (grp ? '👥' : '👤') + '</span>' + ptEsc(it.name) + ' <span>' + v + '</span></button>';
    });
    return html || '<div class="form-hint" style="padding:4px 0;">' + (pos ? '暂无加分项' : '暂无减分项') + '</div>';
}
function ptPressOpen(sid) {
    var s = PT_STU_MAP[sid];
    if (!s) return;
    PT_PRESS_SID = sid;
    PT_SEL = [sid];   // 与主界面选中态同步
    ptRenderStu();
    var pos = [], neg = [];
    PT_ITEMS.forEach(function (it) { (parseInt(it.value, 10) < 0 ? neg : pos).push(it); });
    ptE('ptPressName').textContent = s.name;
    ptPressSub();
    ptE('ptPressPos').innerHTML = ptPressSecHtml(pos, true);
    ptE('ptPressNeg').innerHTML = ptPressSecHtml(neg, false);
    ptE('ptPressMask').style.display = 'flex';
}
function ptPressSub() {
    var s = PT_STU_MAP[PT_PRESS_SID];
    if (s) ptE('ptPressSub').textContent = (String(s.seat) !== '' ? '座号 ' + s.seat + ' · ' : '') + '当前 ' + s.total + ' 分';
}
function ptPressClose() { ptE('ptPressMask').style.display = 'none'; PT_PRESS_SID = 0; }
// 面板打分：个人项记本人 1 条；小组项给整组每人各记 1 条（面板不关闭，可连续打多项）
function ptPressGive(itemId) {
    var it = null;
    PT_ITEMS.forEach(function (x) { if (x.id === itemId) it = x; });
    if (!it || !PT_PRESS_SID) return;
    var grp = parseInt(it.type, 10) === 2;
    var sids = [PT_PRESS_SID];
    if (grp) {
        var gid = String(parseInt((PT_STU_MAP[PT_PRESS_SID] || {}).group_id || 0, 10));
        if (gid === '0') { ptMsg('该学生未分组，无法小组加减分', 'error'); return; }
        sids = PT_STU.filter(function (s) { return String(parseInt(s.group_id || 0, 10)) === gid; }).map(function (s) { return s.id; });
    }
    var body = 'type=points_add&class_id=' + PT_CLASS_ID
             + '&student_ids=' + encodeURIComponent(JSON.stringify(sids))
             + '&item_id=' + it.id + '&custom=0';
    var remark = ptE('ptRemark').value.trim();
    if (remark !== '') body += '&remark=' + encodeURIComponent(remark);
    ptPost(body).then(function (d) {
        if (!d.success) { ptMsg(d.message || '操作失败', 'error'); return; }
        ptMsg(d.message + (remark !== '' ? '（备注：' + remark + '）' : ''), 'ok');
        sids.forEach(function (sid) { if (PT_STU_MAP[sid]) PT_STU_MAP[sid].total += d.value; });
        ptPressSub();
        ptRenderStu();
        ptLoad();
    }).catch(function () { ptMsg('网络错误', 'error'); });
}
// 长按绑定（事件委托于 #ptStuGrid，顺序/座位/分组三视图通用）：按住 450ms 触发
(function () {
    var grid = ptE('ptStuGrid');
    function down(e) {
        var card = e.target.closest ? e.target.closest('.pt-stu') : null;
        if (!card || PT_PRESS_T) return;
        PT_PRESS_FIRED = false;
        var sid = parseInt(card.getAttribute('data-sid'), 10);
        PT_PRESS_T = setTimeout(function () { PT_PRESS_T = null; PT_PRESS_FIRED = true; ptPressOpen(sid); }, 450);
    }
    function cancel() { if (PT_PRESS_T) { clearTimeout(PT_PRESS_T); PT_PRESS_T = null; } }
    grid.addEventListener('touchstart', down, { passive: true });
    grid.addEventListener('touchmove', cancel, { passive: true });
    grid.addEventListener('touchend', cancel);
    grid.addEventListener('touchcancel', cancel);
    grid.addEventListener('mousedown', down);
    grid.addEventListener('mouseup', cancel);
    grid.addEventListener('mouseleave', cancel);
    grid.addEventListener('contextmenu', function (e) {
        if (e.target.closest && e.target.closest('.pt-stu')) e.preventDefault();   // 移动端长按不弹系统菜单
    });
})();

// ===== 📒 抽中记录（随机抽选中签名单；清空前不再被抽中） =====
function ptRecOpen() {
    var ids = Object.keys(PT_LOT_PICKED);
    ids.sort(function (a, b) { return String(PT_LOT_PICKED[b]).localeCompare(String(PT_LOT_PICKED[a])); });   // 新抽中的在前
    var html = '';
    ids.forEach(function (sid) {
        var s = PT_STU_MAP[sid];
        var nm = s ? ptEsc(s.name) : ('学生#' + ptEsc(sid));
        var seat = (s && String(s.seat) !== '') ? ' <span style="color:#999;font-size:12px;">' + ptEsc(s.seat) + '号</span>' : '';
        html += '<div class="pt-rec-row"><b>' + nm + '</b>' + seat
              + '<span style="color:#999;font-size:12px;margin-left:auto;">' + ptEsc(PT_LOT_PICKED[sid]) + '</span></div>';
    });
    ptE('ptRecList').innerHTML = html || '<div class="form-hint" style="padding:8px 0;">暂无抽中记录</div>';
    ptE('ptRecCnt').textContent = '已抽中 ' + ids.length + ' 人（全班 ' + PT_STU.length + ' 人）';
    ptE('ptRecMask').style.display = 'flex';
}
function ptRecClose() { ptE('ptRecMask').style.display = 'none'; }
function ptRecClear() {
    if (!confirm('确认清空抽中记录？清空后全班学生重新可被抽中。')) return;
    ptPost('type=points_lot_clear&class_id=' + PT_CLASS_ID).then(function (d) {
        if (!d.success) { ptMsg(d.message || '清空失败', 'error'); return; }
        PT_LOT_PICKED = {};
        ptMsg(d.message || '已清空抽中记录', 'ok');
        ptRecOpen();
    }).catch(function () { ptMsg('网络错误', 'error'); });
}
</script>
