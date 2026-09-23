<?php
/**
 * 班级喊话弹窗（教师端，projects.php / project_view.php 共用）
 * 依赖调用方：openModal/closeModal 函数、.modal-mask/.modal CSS、toast 函数（可选）
 * 调用方式：$ann_class_id = <班级id>; include __DIR__ . '/announce_modal.php';
 *   可选（项目内页 project_view.php）：$ann_is_project = true（开启未登记/已登记分组与项目专属标签）、
 *   $ann_reg_map = [学生id => 1]（已登记映射，分组与「未登记上一名/下一名」标签用）。
 * 使用前需确认教师对该班级有 can_manage_class 权限（服务端 announce_data/announce_send 会二次校验）
 */
$ann_cid = intval($ann_class_id ?? 0);
if (!$ann_cid) return;
$ann_is_project = !empty($ann_is_project);                                          // 项目内页上下文
$ann_reg_map = is_array($ann_reg_map ?? null) ? $ann_reg_map : [];                  // 已登记映射（仅项目内页）
// 班级端反向喊话开关（当前配置）：开启后班级客户端页面出现「反向喊话」按钮
$rev_en = 0; $rev_voice = true; $rev_text = true;
$rev_res = mysqli_query($conn, "SELECT rev_announce, rev_ann_modes FROM classes WHERE id = {$ann_cid}");
if ($rev_res && ($rev_row = mysqli_fetch_assoc($rev_res))) {
    $rev_en = intval($rev_row['rev_announce']);
    $rev_ms = array_values(array_filter(explode(',', strval($rev_row['rev_ann_modes']))));
    $rev_voice = in_array('voice', $rev_ms, true);
    $rev_text = in_array('text', $rev_ms, true);
    if (!$rev_voice && !$rev_text) { $rev_voice = $rev_text = true; }
}
// 「需反馈」「强制显示」勾选 HTML（快捷播报/选人播报两处复用；勾选状态由 JS 同步并 localStorage 记忆，均默认勾选；
// 「强制显示」原在功能设置页，现移至需反馈之后：勾选后客户端自动全屏置前（哪怕浏览器最小化也恢复全屏，确保能看到），
// 展示形式仍由 +跑马灯/+全屏字幕 决定；说明并入两个播报选项卡各自唯一的说明 hint，避免一行出现两个问号）
$ann_needack_html = '<label style="display:inline-flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;margin:0;">'
    . '<input type="checkbox" class="annNeedAck" checked onchange="annNeedAckSw(this)" autocomplete="off"> <b>需反馈</b>'
    . '</label>'
    . '<label style="display:inline-flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;margin:0;">'
    . '<input type="checkbox" class="annForce" checked onchange="annForceSw(this)" autocomplete="off"> <b>强制显示</b>'
    . '</label>';
?>
<style>@keyframes annAudBlink { 0%,100% { opacity:1; } 50% { opacity:.2; } }</style>
<div class="modal-mask" id="annModal">
    <div class="modal" style="max-width:660px;max-height:92vh;overflow-y:auto;">
        <h3>📣 班级喊话</h3>

        <!-- 页头（保留）：大屏客户端在线状态 + 客户端链接按钮（链接改为弹窗显示） -->
        <div style="background:#f6f8fc;border:1px solid #e2e8f2;border-radius:8px;padding:8px 12px;font-size:13px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <span>🖥 大屏客户端：<span id="annDevSt" style="color:#999;">检测中…</span>
                <button type="button" class="hint-q" onclick="toggleHint(event, '<b>大屏客户端在线说明</b><br>· 客户端网页（announce_client.php）处于打开状态即计为在线<br>· 客户端关闭或断网后约 15 秒转为离线<br>· 点右上「客户端链接」获取本班客户端地址')">?</button></span>
            <button type="button" class="btn btn-sm btn-outline" style="margin-left:auto;" onclick="openModal('annLinkModal')">🔗 客户端链接</button>
        </div>

        <!-- 最后一条班级反馈（学生反向喊话；每 5 秒随在线状态刷新，保留显示方便查看） -->
        <div id="annRevLast" style="display:none;background:#eef4ff;border:1px solid #c9d9f5;border-radius:8px;padding:6px 12px;font-size:13px;margin-top:8px;color:#2a5aa5;"></div>

        <!-- 反馈状态（近 30 分钟有需反馈喊话时显示，每 5 秒随在线状态刷新） -->
        <div id="annAckSt" style="display:none;background:#fff8e8;border:1px solid #f0dfb2;border-radius:8px;padding:6px 12px;font-size:13px;margin-top:8px;color:#8a6d1a;"></div>

        <!-- 操作提示：发送校验/成功提示在页头显示（不会被弹窗底部遮挡） -->
        <div id="annMsg" style="display:none;margin-top:8px;padding:8px 10px;border-radius:8px;font-size:13px;"></div>

        <!-- 页头（保留）：播报显示选项行（快捷播报/选人播报共用；图片/功能设置选项卡下隐藏） -->
        <div id="annHeadOpts" style="margin-top:12px;">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <label style="margin:0;font-weight:bold;">播报内容（可自定义标签模板）</label>
                <label style="display:inline-flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;margin:0;"><input type="checkbox" id="annChkVoice" onchange="annVoiceSw()" checked autocomplete="off"> 🔊语音播报</label>
                <label style="display:inline-flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;margin:0;"><input type="checkbox" id="annChkMq" onchange="annDispSw('mq')" title="跑马灯与全屏字幕二选一：勾选跑马灯会自动取消全屏字幕" autocomplete="off"> +跑马灯</label>
                <label style="display:inline-flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;margin:0;"><input type="checkbox" id="annChkBig" onchange="annDispSw('big')" title="跑马灯与全屏字幕二选一：勾选全屏字幕会自动取消跑马灯（全屏字幕=窗口内蓝色大字，不强制置顶）" autocomplete="off"> +全屏字幕</label>
                <button type="button" class="btn btn-sm btn-outline" style="margin-left:auto;" onclick="annHist()">🕘 历史内容</button>
            </div>
        </div>

        <!-- 选项卡：快捷播报（默认）/ 选人播报 / 语音喊话（录音）/ 图片发送 / 功能设置 -->
        <div style="display:flex;gap:6px;margin-top:12px;flex-wrap:wrap;">
            <button type="button" id="annTabBtn-quick" class="btn btn-sm" onclick="annTab('quick')">⚡ 快捷播报</button>
            <button type="button" id="annTabBtn-pick" class="btn btn-sm btn-outline" onclick="annTab('pick')">👥 选人播报</button>
            <button type="button" id="annTabBtn-aud" class="btn btn-sm btn-outline" onclick="annTab('aud')">🎙 语音喊话</button>
            <button type="button" id="annTabBtn-img" class="btn btn-sm btn-outline" onclick="annTab('img')">🖼 图片发送</button>
            <button type="button" id="annTabBtn-cfg" class="btn btn-sm btn-outline" onclick="annTab('cfg')">⚙ 功能设置</button>
        </div>

        <!-- ===== Tab1：快捷播报（默认；发送给全班，直接输入文字） ===== -->
        <div id="annTab-quick" style="margin-top:10px;">
            <div class="form-group" style="margin-top:6px;">
                <label>播报内容（发送给全班）</label>
                <textarea id="annQuickContent" class="form-control" rows="2" oninput="annQuickInput()" placeholder="例如：下课后请同学们安静排队，慢慢走出教室。"></textarea>
            </div>
            <div style="background:#f4faf4;border:1px solid #cdebd2;border-radius:8px;padding:6px 10px;font-size:13px;color:#2c7a3f;">
                播报预览：<span id="annQuickPrev">（请填写播报内容）</span>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px;">
                <button type="button" class="btn annTryBtn" onclick="annQuickTry()">🔊 试听</button>
                <button type="button" class="btn btn-outline annStopTryBtn" onclick="annStopTry()">⏹ 停止</button>
                <button type="button" class="btn" style="background:#27ae60;" onclick="annSendQuick()">🚀 发送到大屏</button>
                <?php echo $ann_needack_html; /* 需反馈勾选（与选人播报共用状态） */ ?>
                <button type="button" class="hint-q" onclick="toggleHint(event, '<b>快捷播报说明</b><br>· 直接输入文字发送给全班（不选学生）<br>· 勾选「🔊语音播报」= 语音朗读（同时按显示开关出字幕）<br>· 不勾选 = 仅作全屏字幕/跑马灯显示<br>· <b>+跑马灯 / +全屏字幕二选一</b>：勾选一个会自动取消另一个（跑马灯=顶部横幅持续滚动；全屏字幕=窗口内蓝色大字，不强制置顶）<br>· 内容为空也可发送：点击发送后确认「确认发送空消息？」即可（客户端先响「叮咚」提醒，再按所选方式展示）<br>· 需发送点名内容请切换到「选人播报」<br><b>需反馈说明</b><br>· 勾选「需反馈」后班级端右下角显示回复区（√确认收到 / X无法处理），班级端点击反馈后才开始按「自动最小化」设置倒计时收起<br>· 未勾选 = 客户端接收到后即按「自动最小化」倒计时<br>· 班级端点「关闭」未反馈时视同「X无法处理」<br><b>强制显示说明</b><br>· 勾选后班级客户端自动全屏置前显示（哪怕浏览器最小化也自动恢复全屏，确保能看到）<br>· 不勾选 = 客户端按普通窗口方式展示，不强制全屏')">?</button>
            </div>
        </div>

        <!-- ===== Tab2：选人播报（呼叫队列选人后按模板播报） ===== -->
        <div id="annTab-pick" style="display:none;margin-top:10px;">
            <!-- 呼叫队列：默认折叠（点击标题展开）；项目内页内部分「未登记/已登记」两组 -->
            <div style="border:1px solid #dfe6f0;border-radius:8px;overflow:hidden;">
                <div style="background:#f6f8fc;padding:8px 12px;cursor:pointer;user-select:none;display:flex;align-items:center;gap:8px;" onclick="annQueueToggle()">
                    <b>📇 呼叫队列（<span id="annQueueCnt">0</span> 人）</b>
                    <span id="annQueueNames" class="form-hint" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
                    <span id="annQueueCaret" style="color:#888;">▸</span>
                </div>
                <div id="annQueueBody" style="display:none;padding:10px 12px;">
                    <label style="display:inline-flex;align-items:center;gap:6px;font-size:14px;cursor:pointer;">
                        <input type="checkbox" id="annMulti" onchange="annMultiSw(this)" autocomplete="off">
                        <b>多选模式</b> <button type="button" class="hint-q" onclick="toggleHint(event, '<b>多选模式</b><br>· 开启后，点击学生卡片可加入/移出呼叫队列')">?</button>
                    </label>
                    <div style="margin:8px 0;display:flex;gap:8px;align-items:center;">
                        <button type="button" class="btn btn-sm btn-outline" onclick="annAll()">📇 全选</button>
                        <button type="button" class="btn btn-sm btn-outline" onclick="annClearQ()">🧹 清空</button>
                    </div>
                    <?php if ($ann_is_project): ?>
                    <!-- 项目内页：未登记（默认展开）/ 已登记（默认折叠）两组 -->
                    <div style="cursor:pointer;user-select:none;padding:4px 0;font-size:13px;font-weight:bold;color:#e67e22;" onclick="annGrpToggle('annGridUn','annUnCaret')"><span id="annUnCaret">▾</span> 📌 未登记（<span id="annUnCnt">0</span>）</div>
                    <div id="annGridUn" style="max-height:200px;overflow-y:auto;border:1px solid #e5e5e5;border-radius:8px;padding:6px;"></div>
                    <div style="cursor:pointer;user-select:none;padding:4px 0;margin-top:6px;font-size:13px;font-weight:bold;color:#27ae60;" onclick="annGrpToggle('annGridReg','annRegCaret')"><span id="annRegCaret">▸</span> ✅ 已登记（<span id="annRegCnt">0</span>）</div>
                    <div id="annGridReg" style="display:none;max-height:160px;overflow-y:auto;border:1px solid #e5e5e5;border-radius:8px;padding:6px;"></div>
                    <?php else: ?>
                    <div id="annGrid" style="max-height:200px;overflow-y:auto;border:1px solid #e5e5e5;border-radius:8px;padding:6px;"></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-group" style="margin-top:10px;">
                <label>播报模板（点标签插入到光标位置）</label>
                <!-- 标签栏：模板标签（点击插入到光标位置；模板固定，不可移除） -->
                <div id="annTagBar" style="margin:6px 0 4px;line-height:1;"></div>
                <textarea id="annContent" class="form-control" rows="2" oninput="annTplInput()"></textarea>
            </div>
            <div style="background:#f4faf4;border:1px solid #cdebd2;border-radius:8px;padding:6px 10px;font-size:13px;color:#2c7a3f;">
                播报预览：<span id="annPrev">（请先选择学生）</span>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px;">
                <button type="button" class="btn annTryBtn" onclick="annTry()">🔊 试听</button>
                <button type="button" class="btn btn-outline annStopTryBtn" onclick="annStopTry()">⏹ 停止</button>
                <button type="button" class="btn" style="background:#27ae60;" onclick="annSend()">🚀 发送到大屏</button>
                <?php echo $ann_needack_html; /* 需反馈勾选（与快捷播报共用状态） */ ?>
                <button type="button" class="hint-q" onclick="toggleHint(event, '<b>播报方式说明</b><br>· 勾选「🔊语音播报」= 多名学生合并为一条播报（如「张三、李松、王五」连读）<br>· 不勾选 = 仅作全屏字幕/跑马灯显示（同样支持学生标签，按所选名单合并替换）<br>· <b>+跑马灯 / +全屏字幕二选一</b>：勾选一个会自动取消另一个（全屏字幕=窗口内蓝色大字，不强制置顶）<br>· 内容为空也可发送：点击发送后确认即可（客户端先响「叮咚」提醒）<br>· 不选学生可直接发送纯文字<br><b>需反馈说明</b><br>· 勾选「需反馈」后班级端右下角显示回复区（√确认收到 / X无法处理），班级端点击反馈后才开始按「自动最小化」设置倒计时收起<br>· 未勾选 = 客户端接收到后即按「自动最小化」倒计时<br>· 班级端点「关闭」未反馈时视同「X无法处理」<br><b>强制显示说明</b><br>· 勾选后班级客户端自动全屏置前显示（哪怕浏览器最小化也自动恢复全屏，确保能看到）<br>· 不勾选 = 客户端按普通窗口方式展示，不强制全屏')">?</button>
            </div>
        </div>

        <!-- ===== Tab3：语音喊话（录制 ≤1 分钟真实人声，发送后客户端自动播放） ===== -->
        <div id="annTab-aud" style="display:none;margin-top:10px;">
            <div class="form-group" style="margin-top:6px;">
                <label>🎙 语音喊话<button type="button" class="hint-q" onclick="toggleHint(event, '<b>语音喊话说明</b><br>· 点击开始录音，最长 1 分钟，到时自动停止<br>· 录完可试听，满意后点击「发送到大屏」<br>· 客户端先响「叮咚」提示音，随后自动播放录音<br>· 录音文件保存 7 天，可在「历史内容」重发<br>· 首次录音需允许浏览器使用麦克风')">?</button></label>
                <div id="annAudBox" style="margin-top:8px;"></div>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <button type="button" id="annAudSendBtn" class="btn" style="background:#27ae60;display:none;" onclick="annAudSend()">🚀 发送到大屏</button>
                <?php echo $ann_needack_html; /* 需反馈/强制显示勾选（与其他播报共用状态） */ ?>
                <button type="button" class="btn btn-outline" onclick="annAudHist()">🕘 历史内容</button>
            </div>
        </div>

        <!-- ===== Tab4：图片发送 ===== -->
        <div id="annTab-img" style="display:none;margin-top:10px;">
            <div class="form-group">
                <label>🖼 图片发送<button type="button" class="hint-q" onclick="toggleHint(event, '<b>图片发送说明</b><br>· 发送后班级客户端全屏浏览：全屏 / 拖拽移动 / 滚轮与双指缩放 / 下载<br>· 底部缩略图切换（多张图片一次发送）<br><b>强制显示说明</b><br>· 勾选后班级客户端自动全屏置前显示（哪怕浏览器最小化也自动恢复全屏，确保能看到）<br>· 不勾选 = 客户端按普通窗口方式展示，不强制全屏')">?</button></label>
                <input type="file" id="annImgs" accept="image/jpeg,image/png,image/gif,image/webp" multiple onchange="annImgPick(this)" style="margin-top:6px;font-size:13px;" autocomplete="off">
                <div id="annImgPrev" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;"></div>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <button type="button" class="btn" style="background:#27ae60;" onclick="annSendImgs()">🚀 发送图片到大屏</button>
                <label style="display:inline-flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;margin:0;">
                    <input type="checkbox" class="annForce" checked onchange="annForceSw(this)" autocomplete="off"> <b>强制显示</b>
                </label>
                <button type="button" class="btn btn-outline" onclick="annImgHist()">🕘 历史内容</button>
            </div>
        </div>

        <!-- ===== Tab4：功能设置（字体大小 / 自动最小化 / 缓存保留 / 播报次数 / 语速 / 音调 / 音色；强制显示已移至快捷/选人发送行） ===== -->
        <div id="annTab-cfg" style="display:none;margin-top:10px;">
            <div style="display:flex;align-items:center;gap:8px;">
                <b>⚙ 功能设置</b>
                <button type="button" class="hint-q" onclick="toggleHint(event, '<b>功能设置说明</b>（文字与图片发送通用，改动即自动保存）<br>· <b>字体大小</b>=大屏字幕字号（超出屏幕自适应时按自适应）<br>· <b>自动最小化</b>=客户端播完自动收起窗口（勾了「需反馈」时，班级端点击反馈后才开始倒计时）<br>· <b>缓存保留</b>=断网/刷新后内容在本机保留的时长<br>· <b>播报次数 / 语速 / 音调 / 音色</b>=语音朗读控制（音色仅使用本机本地语音，避免网络语音无声）<br>· <b>允许班级端反向喊话教师端</b>=开启后班级客户端出现「反向喊话」按钮，学生可向教师端发语音/文字，开关与允许模式勾选即自动保存')">?</button>
            </div>
            <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;margin-top:10px;">
                <div class="form-group" style="margin:0;">
                    <label>字体大小：<b id="annFontVal">200</b> px<button type="button" class="hint-q" onclick="toggleHint(event, '<b>字体大小规则</b><br>· 设置大于屏幕自适应值时，按屏幕自适应显示<br>· 设置小于自适应值时，按设置值显示')">?</button></label>
                    <input type="range" id="annFont" min="16" max="200" value="200" style="width:200px;" oninput="annE('annFontVal').textContent=this.value;" onchange="annSaveSet()" autocomplete="off">
                </div>
                <div class="form-group" style="margin:0;">
                    <label>自动最小化（秒，0=不自动）</label>
                    <input type="number" id="annMin" class="form-control" min="0" max="3600" value="30" style="width:130px;" onchange="annSaveSet()" autocomplete="off">
                </div>
                <div class="form-group" style="margin:0;">
                    <label>客户端缓存保留（分钟）</label>
                    <input type="number" id="annCache" class="form-control" min="0" max="1440" value="60" style="width:130px;" onchange="annSaveSet()" autocomplete="off">
                </div>
            </div>
            <div id="annVoiceOnly" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;">
                <div class="form-group" style="margin:0;">
                    <label>播报次数</label>
                    <input type="number" id="annTimes" class="form-control" min="1" max="5" value="1" style="width:80px;" onchange="annSaveSet()" autocomplete="off">
                </div>
                <div class="form-group" style="margin:0;">
                    <label>语速（0.5~2）</label>
                    <input type="number" id="annSpeed" class="form-control" min="0.5" max="2" step="0.1" value="1" style="width:90px;" onchange="annSaveSet()" autocomplete="off">
                </div>
                <div class="form-group" style="margin:0;">
                    <label>音调（0.5~2）</label>
                    <input type="number" id="annPitch" class="form-control" min="0.5" max="2" step="0.1" value="1" style="width:90px;" onchange="annSaveSet()" autocomplete="off">
                </div>
                <div class="form-group" style="margin:0;">
                    <label>音色角色<button type="button" class="hint-q" onclick="toggleHint(event, '<b>音色说明</b><br>· 音色列表来自本机浏览器（本地语音优先、中文排前）<br>· 客户端大屏无对应音色时自动用默认音色<br>· 网络音色（如 Google）不使用，避免无声')">?</button></label>
                    <select id="annVoiceSel" class="form-control" style="width:240px;"><option value="">默认音色</option></select>
                </div>
            </div>

            <!-- ===== 班级端反向喊话开关（归入功能设置选项卡） ===== -->
            <div style="margin-top:14px;border-top:1px dashed #dfe6f0;padding-top:12px;">
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:14px;">
                    <input type="checkbox" id="annRevEn" <?php echo $rev_en ? 'checked' : ''; ?> onchange="annRevSw()" autocomplete="off">
                    <b>允许班级端反向喊话教师端</b><button type="button" class="hint-q" onclick="toggleHint(event, '<b>反向喊话说明</b><br>· 开启后，班级客户端页面出现「反向喊话」按钮<br>· 学生可向教师端发送语音/文字<br>· 教师端大屏自动播报/弹出横幅展示<br>· 开关与下方允许模式（语音/文字）勾选后即自动保存')">?</button>
                </label>
                <div id="annRevModes" style="display:<?php echo $rev_en ? 'flex' : 'none'; ?>;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
                    <span style="font-size:14px;">允许模式（勾选即保存）：</span>
                    <label style="display:inline-flex;align-items:center;gap:5px;cursor:pointer;font-size:14px;"><input type="checkbox" id="annRevVoice" <?php echo $rev_voice ? 'checked' : ''; ?> onchange="annRevSave()" autocomplete="off"> 🔊 语音播报</label>
                    <label style="display:inline-flex;align-items:center;gap:5px;cursor:pointer;font-size:14px;"><input type="checkbox" id="annRevText" <?php echo $rev_text ? 'checked' : ''; ?> onchange="annRevSave()" autocomplete="off"> 📝 文字字幕</label>
                </div>
            </div>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn btn-outline" onclick="annClose()">关闭</button>
        </div>
    </div>
</div>

<!-- 历史内容弹窗（文字/语音：点击回填，可重发/删除；图片：缩略图预览，可下载/重发/删除） -->
<div class="modal-mask" id="annHistModal" style="z-index:1300;">
    <div class="modal" style="width:480px;max-width:92vw;max-height:80vh;overflow-y:auto;">
        <h3 id="annHistTitle">🕘 历史播报内容</h3>
        <div id="annHistList" style="max-height:52vh;overflow-y:auto;"></div>
        <div class="modal-actions">
            <button type="button" class="btn btn-outline" onclick="closeModal('annHistModal')">关闭</button>
        </div>
    </div>
</div>

<!-- 大屏客户端链接弹窗（页头「🔗 客户端链接」按钮打开；班级浏览器打开此链接即连通本班喊话） -->
<div class="modal-mask" id="annLinkModal" style="z-index:1300;">
    <div class="modal" style="width:560px;max-width:92vw;">
        <h3>🔗 大屏客户端链接</h3>
        <div class="form-group">
            <label>大屏客户端链接（班级浏览器打开此链接，即连通本班喊话）<button type="button" class="hint-q" onclick="toggleHint(event, '<b>大屏客户端链接说明</b><br>· 把此链接在班级大屏浏览器打开，即连通本班喊话<br>· 链接含班级专属令牌，请勿外传<br>· 客户端页面保持打开即可实时接收喊话<br>· 首次需在客户端点击一次以开启声音')">?</button></label>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="text" class="form-control" id="annLink" readonly onclick="this.select()" style="flex:1;min-width:220px;font-family:Consolas,monospace;font-size:13px;" autocomplete="off">
                <button type="button" class="btn btn-sm" onclick="annCopy()">📋 复制链接</button>
                <a id="annOpenClient" href="#" target="_blank" class="btn btn-sm btn-outline" style="text-decoration:none;">预览客户端</a>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-outline" onclick="closeModal('annLinkModal')">关闭</button>
        </div>
    </div>
</div>

<script>
// ===== 班级喊话（教师端弹窗逻辑；本段由 includes/announce_modal.php 输出，页面内独有 ann 前缀，无命名冲突） =====
// openModal/closeModal 兜底：projects.php 等宿主已有实现时直接复用；project_view.php 等未提供时注入基础版
if (typeof window.openModal !== 'function') {
    window.openModal = function (id) { document.getElementById(id).classList.add('show'); };
}
if (typeof window.closeModal !== 'function') {
    window.closeModal = function (id) { document.getElementById(id).classList.remove('show'); };
}
var ANN_CLASS_ID = <?php echo $ann_cid; ?>;
var ANN_CTX = '<?php echo $ann_is_project ? 'project' : 'list'; ?>';   // project=项目内页（分组队列+项目专属标签）
var ANN_REG = <?php echo json_encode($ann_reg_map ?: new stdClass(), JSON_UNESCAPED_UNICODE); ?>;   // 已登记映射 sid=>1（仅项目内页）
var ANN_TPL_KEY = 'ann_tpl_c' + ANN_CLASS_ID;                          // 选人播报模板本机缓存（按班级记忆）
var ANN_QUICK_KEY = 'ann_quick_c' + ANN_CLASS_ID;                      // 快捷播报内容本机缓存（按班级记忆）
var ANN_DISP_KEY = 'ann_disp_c' + ANN_CLASS_ID;                        // +跑马灯/+全屏字幕 勾选记忆
var ANN_SET_KEY = 'ann_set_c' + ANN_CLASS_ID;                          // 功能设置数值记忆（字体/自动最小化/缓存/次数/语速/音调，即改即存）
var ANN_ACK_KEY = 'ann_needack';                                       // 需反馈勾选记忆（默认勾选）
var ANN_FORCE_KEY = 'ann_force';                                       // 强制显示勾选记忆（默认勾选）
var ANN_DEFAULT_TPL = '{分组}{座号}{姓名}同学，请到办公室来一趟。';
// 标签定义（p=可用上下文：all=全部 / project=仅项目内页）
var ANN_TAGS = [
    { k: '姓名', p: 'all' }, { k: '座号', p: 'all' }, { k: '分组', p: 'all' }, { k: '编号', p: 'all' },
    { k: '座号+姓名', p: 'project' },
    { k: '未登记上一名', p: 'project' }, { k: '未登记下一名', p: 'project' },
    { k: '座号+未登记上一名', p: 'project' }, { k: '座号+未登记下一名', p: 'project' }
];
function annE(id) { return document.getElementById(id); }
var annStu = [];        // 学生名单 [{id,name,seat,no,gid}]
var annStuMap = {};     // id => 学生
var annGroups = {};     // 分组id => 名称
var annQueue = [];      // 呼叫队列（学生 id）
var annMulti = false;   // 多选模式
var annImgSel = [];     // 已选图片文件（File 对象，最多 9 张）
var annImgUrls = [];    // 预览缩略图 objectURL（移除时释放）
var annStTimer = null;  // 设备在线状态刷新定时
var annHbTimer = null;  // 教师在线心跳定时

// ===== 初始化：恢复本机缓存的模板/快捷内容与显示勾选，渲染标签栏 =====
(function annInit() {
    var saved = null;
    try { saved = localStorage.getItem(ANN_TPL_KEY); } catch (e) {}
    annE('annContent').value = (saved !== null && saved !== '') ? saved : ANN_DEFAULT_TPL;
    try {
        var q = localStorage.getItem(ANN_QUICK_KEY);
        if (q !== null) annE('annQuickContent').value = q;
    } catch (e) {}
    try {
        var dv = JSON.parse(localStorage.getItem(ANN_DISP_KEY) || '{}');
        annE('annChkMq').checked = dv.mq === 1;
        annE('annChkBig').checked = dv.big === undefined ? true : dv.big === 1;   // 默认勾选；保存值 0 必须还原为不勾（旧写法 0!==false 是bug）
        if (annE('annChkMq').checked && annE('annChkBig').checked) annE('annChkBig').checked = false;   // 旧缓存两者同勾：按跑马灯优先归一化（新逻辑互斥）
        annE('annChkVoice').checked = dv.voice === undefined ? true : dv.voice === 1;
    } catch (e) {}
    // 功能设置数值恢复（即改即存：字体/自动最小化/缓存保留/播报次数/语速/音调）
    try {
        var st = JSON.parse(localStorage.getItem(ANN_SET_KEY) || '{}');
        if (st.font) annE('annFont').value = Math.max(16, Math.min(200, parseInt(st.font, 10) || 200));
        if (st.min !== undefined) annE('annMin').value = Math.max(0, Math.min(3600, parseInt(st.min, 10) || 0));
        if (st.cache !== undefined) annE('annCache').value = Math.max(0, Math.min(1440, parseInt(st.cache, 10) || 0));
        if (st.times) annE('annTimes').value = Math.max(1, Math.min(5, parseInt(st.times, 10) || 1));
        if (st.speed) annE('annSpeed').value = Math.max(0.5, Math.min(2, parseFloat(st.speed) || 1));
        if (st.pitch) annE('annPitch').value = Math.max(0.5, Math.min(2, parseFloat(st.pitch) || 1));
        if (st.font) annE('annFontVal').textContent = annE('annFont').value;
    } catch (e) {}
    try { if (localStorage.getItem(ANN_ACK_KEY) === '0') annNeedAckSw(null, false); } catch (e) {}   // 记忆为不勾时同步取消勾选
    try { if (localStorage.getItem(ANN_FORCE_KEY) === '0') annForceSw(null, false); } catch (e) {}   // 强制显示记忆为不勾时取消勾选（默认勾选）
    annTagBar();
    annVoiceSw();
    annQuickPrev();
    annAudRender();   // 语音喊话选项卡初始状态渲染
})();

function annOpen() {
    openModal('annModal');
    annMsg('', '');
    annE('annLink').value = '加载中…';
    annStartTimers();
    fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'type=announce_data&class_id=' + ANN_CLASS_ID
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (!d.success) { annMsg(d.message || '加载失败', 'error'); annE('annLink').value = ''; return; }
        annE('annLink').value = d.link;
        annE('annOpenClient').href = d.link;
        annStu = d.students || [];
        annGroups = d.groups || {};
        annStuMap = {};
        annStu.forEach(function (s) { annStuMap[s.id] = s; });
        annQueue = [];
        annRenderStu();
    })
    .catch(function () { annMsg('网络错误', 'error'); });
}

// 在线状态刷新 + 教师心跳（弹窗打开期间有效；宿主以其他方式关闭弹窗时 tick 自行跳过）
function annStartTimers() {
    if (annStTimer === null) {
        annLoadStatus();
        annStTimer = setInterval(annLoadStatus, 5000);
    }
    if (annHbTimer === null) {
        annHb();
        annHbTimer = setInterval(annHb, 10000);
    }
}
function annHb() {
    if (!annE('annModal').classList.contains('show')) return;
    fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'type=announce_tping&class_id=' + ANN_CLASS_ID }).catch(function () {});
}
function annLoadStatus() {
    if (!annE('annModal').classList.contains('show')) return;
    fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'type=announce_status&class_id=' + ANN_CLASS_ID
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        var el = annE('annDevSt');
        if (!d || !d.success) { el.innerHTML = '<span style="color:#999;">状态获取失败</span>'; return; }
        var pc = d.devices.pc || 0, ph = d.devices.phone || 0, nv = d.devices.novoice || 0, n = pc + ph;
        var nvTxt = nv ? '<span style="color:#b9770e;" title="这些设备浏览器不支持语音或未解锁声音，语音喊话将仅显示字幕"> · 🔇不支持语音 ' + nv + ' 台</span>' : '';
        el.innerHTML = n
            ? '<b style="color:#1e8449;">🟢 在线 ' + n + ' 台</b>（PC ' + pc + ' · 手机 ' + ph + '）' + nvTxt
            : '<span style="color:#b04a3a;">⚪ 无设备在线</span>';
        // 班级喊话（学生反向喊话）最新一条：点「已阅」隐藏，直到有新的反馈再显示
        var lr = d.last_rev, rvEl = annE('annRevLast');
        var rvSeen = 0;
        try { rvSeen = parseInt(localStorage.getItem('ann_rev_seen') || '0', 10) || 0; } catch (e) {}
        if (!lr || !lr.id || lr.id <= rvSeen) { rvEl.style.display = 'none'; }
        else {
            rvEl.style.display = 'flex';
            rvEl.style.alignItems = 'center';
            rvEl.innerHTML = '<span style="flex:1;">📥 班级喊话：' + annEsc(lr.content) + '（' + annEsc(lr.ts) + '）</span>';
            rvEl.appendChild(annSeenBtn('ann_rev_seen', lr.id));
        }
        // 反馈信息（最近一条需反馈喊话的结果）：点「已阅」隐藏，直到有新的反馈再显示
        var la = d.last_ack, ackEl = annE('annAckSt');
        var akSeen = 0;
        try { akSeen = parseInt(localStorage.getItem('ann_ack_seen') || '0', 10) || 0; } catch (e) {}
        if (!la || !la.id || la.id <= akSeen) { ackEl.style.display = 'none'; }
        else {
            var stTxt = la.st === 1 ? '✅ 确认收到' : (la.st === 2 ? '❌ 无法处理' : '⏳ 待反馈');
            var stCol = la.st === 1 ? '#1e8449' : (la.st === 2 ? '#b04a3a' : '#b9770e');
            ackEl.style.display = 'flex';
            ackEl.style.alignItems = 'center';
            if (la.mtype === 'audio' && la.content) {
                // 语音录音喊话：content 为音频文件，渲染成播放器（不显示文件路径）
                ackEl.innerHTML = '<span style="flex:1;display:inline-flex;align-items:center;flex-wrap:wrap;gap:4px 8px;">📮 反馈信息：'
                    + '<audio src="' + annEsc(la.content) + '" controls preload="none" style="height:34px;max-width:280px;"></audio>'
                    + ' <b style="color:' + stCol + ';">' + stTxt + '</b>（' + annEsc(la.st ? la.at : la.sent) + '）</span>';
            } else {
                ackEl.innerHTML = '<span style="flex:1;">📮 反馈信息：' + annEsc(la.content)
                    + ' <b style="color:' + stCol + ';">' + stTxt + '</b>（' + annEsc(la.st ? la.at : la.sent) + '）</span>';
            }
            ackEl.appendChild(annSeenBtn('ann_ack_seen', la.id));
        }
    })
    .catch(function () {});
}
// 「已阅」按钮：记录已读 id 到 localStorage，点击后隐藏所在提示条（下次刷新不再显示）
function annSeenBtn(key, id) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'btn btn-sm btn-outline';
    b.style.cssText = 'margin-left:10px;flex-shrink:0;';
    b.textContent = '👁 已阅';
    b.onclick = function () {
        try { localStorage.setItem(key, String(id)); } catch (e) {}
        b.parentNode.style.display = 'none';
    };
    return b;
}
function annClose() {
    annAudStop();   // 录音中关闭弹窗：停止并释放麦克风（已录内容保留待下次发送）
    if (annStTimer !== null) { clearInterval(annStTimer); annStTimer = null; }
    if (annHbTimer !== null) { clearInterval(annHbTimer); annHbTimer = null; }
    closeModal('annHistModal');
    closeModal('annModal');
}

function annCopy() {
    var inp = annE('annLink');
    if (!inp.value) { annMsg('链接尚未加载', 'error'); return; }
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(inp.value).then(function () { annMsg('喊话链接已复制，到班级浏览器粘贴打开即可', 'ok'); });
    } else {
        inp.select(); document.execCommand('copy');
        annMsg('喊话链接已复制，到班级浏览器粘贴打开即可', 'ok');
    }
}

function annMsg(msg, type) {
    var box = annE('annMsg');
    if (!msg) { box.style.display = 'none'; return; }
    box.style.display = 'block';
    box.style.background = type === 'error' ? '#fdecea' : '#e8f8f0';
    box.style.color = type === 'error' ? '#c0392b' : '#1e8449';
    box.textContent = msg;
}

function annEsc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}
function annNameOf(id) { var s = annStuMap[id]; return s ? s.name : ''; }

// ===== 呼叫队列折叠/分组 =====
function annQueueToggle() {
    var b = annE('annQueueBody');
    var open = b.style.display === 'none';
    b.style.display = open ? '' : 'none';
    annE('annQueueCaret').textContent = open ? '▾' : '▸';
}
// 选项卡切换：快捷播报 / 选人播报 / 语音喊话（录音）/ 图片发送 / 功能设置（页头显示选项行仅播报类选项卡显示）
function annTab(t) {
    ['quick', 'pick', 'aud', 'img', 'cfg'].forEach(function (k) {
        annE('annTab-' + k).style.display = k === t ? '' : 'none';
        annE('annTabBtn-' + k).className = 'btn btn-sm' + (k === t ? '' : ' btn-outline');
    });
    annE('annHeadOpts').style.display = (t === 'quick' || t === 'pick') ? '' : 'none';
}
// ===== 音色角色列表（本机浏览器 TTS 音色，中文优先排前；客户端无对应音色时自动回退默认） =====
function annLoadVoices() {
    if (!('speechSynthesis' in window)) return;
    var sel = annE('annVoiceSel');
    if (!sel) return;
    var cur = sel.value;
    var all = speechSynthesis.getVoices();
    var vs = all.filter(function (v) { return /^zh/i.test(v.lang) || /中文|Chinese/i.test(v.name); });
    if (!vs.length) vs = all;
    sel.innerHTML = '<option value="">默认音色</option>' + vs.map(function (v) {
        return '<option value="' + annEsc(v.name) + '">' + annEsc(v.name + (v.lang ? '（' + v.lang + '）' : '')) + '</option>';
    }).join('');
    if (cur) sel.value = cur;
}
if ('speechSynthesis' in window) {
    annLoadVoices();
    speechSynthesis.onvoiceschanged = annLoadVoices;   // Chrome 异步加载音色列表
}
function annGrpToggle(bodyId, caretId) {
    var b = annE(bodyId);
    var open = b.style.display === 'none';
    b.style.display = open ? '' : 'none';
    annE(caretId).textContent = open ? '▾' : '▸';
}

function annRenderStu() {
    if (ANN_CTX === 'project') {
        var gu = annE('annGridUn'), gr = annE('annGridReg');
        gu.innerHTML = ''; gr.innerHTML = '';
        var nu = 0, nr = 0;
        annStu.forEach(function (s) {
            var reg = !!ANN_REG[s.id];
            (reg ? gr : gu).appendChild(annTile(s));
            if (reg) nr++; else nu++;
        });
        if (!nu) gu.innerHTML = '<div style="color:#999;padding:6px;">暂无未登记学生</div>';
        if (!nr) gr.innerHTML = '<div style="color:#999;padding:6px;">暂无已登记学生</div>';
        annE('annUnCnt').textContent = nu;
        annE('annRegCnt').textContent = nr;
    } else {
        var g = annE('annGrid');
        g.innerHTML = annStu.length ? '' : '<div style="color:#999;padding:8px;">该班级暂无学生</div>';
        annStu.forEach(function (s) { g.appendChild(annTile(s)); });
    }
    annE('annQueueCnt').textContent = annQueue.length;
    var names = annQueue.map(annNameOf);
    annE('annQueueNames').textContent = names.length ? '：' + names.join('、') : '';
    annUpdatePrev();
}

function annTile(s) {
    var sel = annQueue.indexOf(s.id) >= 0;
    var d = document.createElement('div');
    d.style.cssText = 'display:inline-block;vertical-align:top;margin:4px;padding:6px 10px;border-radius:8px;'
        + 'border:2px solid ' + (sel ? '#27ae60' : '#ddd') + ';background:' + (sel ? '#e8f8f0' : '#fff')
        + ';cursor:pointer;text-align:center;min-width:64px;user-select:none;';
    d.innerHTML = '<div style="font-size:15px;font-weight:bold;color:' + (sel ? '#1e8449' : '#333') + ';">' + annEsc(s.name) + '</div>'
        + (s.seat ? '<div style="font-size:11px;color:#999;">' + annEsc(s.seat) + '</div>' : '');
    d.onclick = function () { annPick(s.id); };
    return d;
}

function annPick(id) {
    var i = annQueue.indexOf(id);
    if (i >= 0) annQueue.splice(i, 1);
    else { if (!annMulti) annQueue = []; annQueue.push(id); }
    annRenderStu();
}
function annAll() { annQueue = annStu.map(function (s) { return s.id; }); annRenderStu(); }
function annClearQ() { annQueue = []; annRenderStu(); }
function annMultiSw(cb) {
    annMulti = cb.checked;
    if (!annMulti && annQueue.length > 1) annQueue = annQueue.slice(0, 1);
    annRenderStu();
}

// ===== 模板标签：渲染 / 插入（模板固定，不可移除） =====
function annTagBar() {
    var bar = annE('annTagBar');
    bar.innerHTML = '';
    ANN_TAGS.forEach(function (t) {
        if (t.p === 'project' && ANN_CTX !== 'project') return;   // 项目专属标签仅项目内页出现
        var chip = document.createElement('span');
        chip.style.cssText = 'display:inline-block;margin:4px 10px 6px 0;padding:4px 10px;'
            + 'background:#eef2ff;border:1px solid #c3cdf5;border-radius:14px;font-size:13px;cursor:pointer;user-select:none;color:#4a5bc4;';
        chip.textContent = '{' + t.k + '}';
        chip.title = '点击插入到编辑框光标位置';
        chip.onclick = function () { annInsertTag('{' + t.k + '}'); };
        bar.appendChild(chip);
    });
}
function annInsertTag(txt) {
    var ta = annE('annContent');
    var st = (ta.selectionStart === null || ta.selectionStart === undefined) ? ta.value.length : ta.selectionStart;
    var en = (ta.selectionEnd === null || ta.selectionEnd === undefined) ? st : ta.selectionEnd;
    ta.value = ta.value.slice(0, st) + txt + ta.value.slice(en);
    var pos = st + txt.length;
    ta.focus();
    try { ta.setSelectionRange(pos, pos); } catch (e) {}
    annSaveTpl();
    annUpdatePrev();
}
function annSaveTpl() { try { localStorage.setItem(ANN_TPL_KEY, annE('annContent').value); } catch (e) {} }
// 功能设置即改即存（字体/自动最小化/缓存保留/播报次数/语速/音调；localStorage 按班级记忆）
function annSaveSet() {
    try {
        localStorage.setItem(ANN_SET_KEY, JSON.stringify({
            font: parseInt(annE('annFont').value, 10) || 200,
            min: parseInt(annE('annMin').value, 10) || 0,
            cache: parseInt(annE('annCache').value, 10) || 0,
            times: parseInt(annE('annTimes').value, 10) || 1,
            speed: parseFloat(annE('annSpeed').value) || 1,
            pitch: parseFloat(annE('annPitch').value) || 1
        }));
    } catch (e) {}
}
function annTplInput() { annSaveTpl(); annUpdatePrev(); }
function annDispSw(which) {   // 跑马灯/全屏字幕互斥：勾选一个自动取消另一个（两开时客户端跑马灯跑完即切全屏，跑马灯效果名存实亡）
    if (which === 'mq' && annE('annChkMq').checked) annE('annChkBig').checked = false;
    if (which === 'big' && annE('annChkBig').checked) annE('annChkMq').checked = false;
    try { localStorage.setItem(ANN_DISP_KEY, JSON.stringify({ mq: annE('annChkMq').checked ? 1 : 0, big: annE('annChkBig').checked ? 1 : 0, voice: annE('annChkVoice').checked ? 1 : 0 })); } catch (e) {}
}
function annDisp() { return { mq: annE('annChkMq').checked, big: annE('annChkBig').checked, voice: annE('annChkVoice').checked }; }
function annVoiceSw() {   // 语音播报勾选联动：次数/语速/音调、试听/停止 仅语音模式显示（快捷/选人两个选项卡的试听按钮联动）
    var v = annE('annChkVoice').checked;
    annE('annVoiceOnly').style.display = v ? 'flex' : 'none';
    document.querySelectorAll('.annTryBtn').forEach(function (b) { b.style.display = v ? '' : 'none'; });
    document.querySelectorAll('.annStopTryBtn').forEach(function (b) { b.style.display = v ? '' : 'none'; });
}

// ===== 需反馈勾选（快捷/选人两处勾选同步；localStorage 记忆，默认勾选） =====
function annNeedAckSw(cb, on) {
    var val = cb ? cb.checked : (on !== false);
    document.querySelectorAll('.annNeedAck').forEach(function (c) { c.checked = val; });
    try { localStorage.setItem(ANN_ACK_KEY, val ? '1' : '0'); } catch (e) {}
}
function annNeedAckOn() {
    var c = document.querySelector('.annNeedAck');
    return !!(c && c.checked);
}

// ===== 强制显示勾选（快捷/选人两处勾选同步；localStorage 记忆，默认勾选；= 客户端自动全屏置前，展示形式仍由跑马灯/全屏字幕决定） =====
function annForceSw(cb, on) {
    var val = cb ? cb.checked : (on !== false);
    document.querySelectorAll('.annForce').forEach(function (c) { c.checked = val; });
    try { localStorage.setItem(ANN_FORCE_KEY, val ? '1' : '0'); } catch (e) {}
}
function annForceOn() {
    var c = document.querySelector('.annForce');
    return !!(c && c.checked);
}

// ===== 标签替换（逐生生成播报文本） =====
function annHasTag(tpl) {
    return /\{姓名\}|\{座号\}|\{分组\}|\{编号\}|\{座号\+姓名\}|\{座号\+未登记上一名\}|\{座号\+未登记下一名\}|\{未登记上一名\}|\{未登记下一名\}|\{name\}/.test(tpl);
}
function annSeatLabel(s) {   // 座号口语化：纯数字座号加「号」（TTS/字幕显示更自然），非纯数字原样
    if (!s) return '';
    var v = str(s.seat) || str(s.no);
    if (v === '') return '';
    return /^\d+$/.test(v) ? v + '号' : v;
}
function annSeatName(s) {    // 「座号+姓名」：3号 张三；无座号时用编号（与{座号}一致）
    if (!s) return '';
    var lab = annSeatLabel(s);
    return lab ? lab + ' ' + (s.name || '') : (s.name || '');
}
function str(v) { return String(v === null || v === undefined ? '' : v).trim(); }
function annSubst(tpl, s) {
    var grp = (s.gid && annGroups[s.gid]) ? annGroups[s.gid] : '';
    // 未登记上一名/下一名：按名单座号顺序，相对当前学生最近的未登记学生
    var pos = -1, i;
    for (i = 0; i < annStu.length; i++) if (annStu[i].id === s.id) { pos = i; break; }
    var uprev = null, unext = null;
    for (i = 0; i < annStu.length; i++) {
        if (ANN_REG[annStu[i].id]) continue;
        if (i < pos) uprev = annStu[i];
        else if (i > pos) { unext = annStu[i]; break; }
    }
    return tpl
        .replace(/\{姓名\}/g, s.name)
        .replace(/\{座号\}/g, str(s.seat) || str(s.no))
        .replace(/\{分组\}/g, grp)
        .replace(/\{编号\}/g, str(s.no))
        .replace(/\{座号\+姓名\}/g, annSeatName(s))
        .replace(/\{座号\+未登记上一名\}/g, annSeatName(uprev))
        .replace(/\{座号\+未登记下一名\}/g, annSeatName(unext))
        .replace(/\{未登记上一名\}/g, uprev ? uprev.name : '')
        .replace(/\{未登记下一名\}/g, unext ? unext.name : '')
        .replace(/\{name\}/g, s.name);
}

// 多生合并替换（语音合并连读 / 纯字幕名单展示）：{姓名}{座号}{编号}{分组}{座号+姓名} 按所选名单去重合并（顿号连接）；
// {座号+未登记上一名}/{未登记上一名} 取首生相对值，{座号+未登记下一名}/{未登记下一名} 取末生相对值
function annSubstMulti(tpl, sids) {
    var first = annStuMap[sids[0]], last = annStuMap[sids[sids.length - 1]];
    function joinVals(f) {
        var out = [];
        sids.forEach(function (sid) {
            var x = annStuMap[sid]; if (!x) return;
            var v = f(x); v = v === undefined || v === null ? '' : String(v).trim();
            if (v && out.indexOf(v) < 0) out.push(v);
        });
        return out.join('、');
    }
    var grp = joinVals(function (x) { return (x.gid && annGroups[x.gid]) ? annGroups[x.gid] : ''; });
    var posF = -1, posL = -1, i;
    for (i = 0; i < annStu.length; i++) {
        if (annStu[i].id === first.id) posF = i;
        if (annStu[i].id === last.id) posL = i;
    }
    var uprev = null, unext = null;
    for (i = 0; i < annStu.length; i++) {
        if (ANN_REG[annStu[i].id]) continue;
        if (i < posF) uprev = annStu[i];
        else if (i > posL) { unext = annStu[i]; break; }
    }
    return tpl
        .replace(/\{姓名\}/g, joinVals(function (x) { return x.name; }))
        .replace(/\{座号\}/g, joinVals(function (x) { return str(x.seat) || str(x.no); }))
        .replace(/\{分组\}/g, grp)
        .replace(/\{编号\}/g, joinVals(function (x) { return str(x.no); }))
        .replace(/\{座号\+姓名\}/g, joinVals(annSeatName))
        .replace(/\{座号\+未登记上一名\}/g, annSeatName(uprev))
        .replace(/\{座号\+未登记下一名\}/g, annSeatName(unext))
        .replace(/\{未登记上一名\}/g, uprev ? uprev.name : '')
        .replace(/\{未登记下一名\}/g, unext ? unext.name : '')
        .replace(/\{name\}/g, joinVals(function (x) { return x.name; }));
}

function annUpdatePrev() {
    var tpl = annE('annContent').value;
    var el = annE('annPrev');
    if (!annQueue.length) {
        el.textContent = annHasTag(tpl) ? '（请先在呼叫队列选择学生，标签将按所选名单合并替换）' : tpl;
        return;
    }
    el.textContent = annQueue.length > 1 ? annSubstMulti(tpl, annQueue)
        : annSubst(tpl, annStuMap[annQueue[0]] || { name: '', seat: '', no: '', gid: 0 }, 0);
}

// ===== 历史内容（近 30 条：文字/语音点击回填可重发可删除；图片缩略图预览可下载/重发/删除） =====
function annHist() { annHistLoad('text'); }
function annImgHist() { annHistLoad('img'); }
function annAudHist() { annHistLoad('aud'); }
function annHistLoad(kind) {
    openModal('annHistModal');
    annE('annHistTitle').textContent = kind === 'img' ? '🕘 历史图片（缩略图点击下载）'
        : kind === 'aud' ? '🕘 历史录音（点「重发」再次播放）' : '🕘 历史播报内容（点击载入发送框）';
    var box = annE('annHistList');
    box.innerHTML = '<div style="color:#999;padding:10px;">加载中…</div>';
    fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'type=announce_history&class_id=' + ANN_CLASS_ID
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (!d || !d.success) { box.innerHTML = '<div style="color:#c0392b;padding:10px;">' + annEsc((d && d.message) || '加载失败') + '</div>'; return; }
        var items = (d.items || []).filter(function (it) {
            if (kind === 'img') return it.mtype === 'img';
            if (kind === 'aud') return it.mtype === 'audio';
            return it.mtype !== 'img' && it.mtype !== 'audio';
        });
        var revs = (d.revs || []);   // 班级反馈历史（学生反向喊话；仅文字类历史弹窗显示）
        var emptyTxt = kind === 'img' ? '暂无历史图片' : kind === 'aud' ? '暂无历史录音' : '暂无历史播报内容';
        if (!items.length && !(kind === 'text' && revs.length)) {
            box.innerHTML = '<div style="color:#999;padding:10px;">' + emptyTxt + '</div>';
            return;
        }
        box.innerHTML = '';
        items.forEach(function (it) {
            var urls = [];
            if (kind === 'img') {
                try { urls = JSON.parse(it.content || '[]'); } catch (e) {}
                urls = urls.filter(function (u) { return typeof u === 'string' && u; });
            }
            var row = document.createElement('div');
            row.style.cssText = 'padding:8px 10px;border-bottom:1px solid #eef1f6;' + (kind === 'text' ? 'cursor:pointer;' : '');
            if (kind === 'img') {
                var pics = urls.map(function (u) {
                    return '<img src="' + annEsc(u) + '" alt="" title="点击下载" style="width:64px;height:48px;object-fit:cover;border-radius:6px;border:1px solid #dfe6f0;cursor:pointer;margin:5px 6px 0 0;">';
                }).join('');
                row.innerHTML = '<div class="form-hint">' + annEsc(it.ts) + '（' + urls.length + ' 张）</div><div>' + pics + '</div>';
                Array.prototype.forEach.call(row.querySelectorAll('img'), function (im, i) {
                    im.onclick = function () {   // 缩略图点击 = 下载
                        var a = document.createElement('a');
                        a.href = urls[i]; a.download = String(urls[i]).split('/').pop() || ('image_' + (i + 1));
                        document.body.appendChild(a); a.click(); a.remove();
                    };
                });
            } else if (kind === 'aud') {
                row.innerHTML = '<div class="form-hint">' + annEsc(it.ts) + '</div>'
                    + '<audio src="' + annEsc(it.content) + '" controls preload="none" style="height:34px;width:100%;max-width:320px;margin-top:4px;"></audio>';
            } else {
                // 需反馈喊话的历史条目：附反馈结果（✅确认收到 / ❌无法处理 / ⏳待反馈 + 反馈时间）
                var ackLine = '';
                if (it.need_ack === 1) {
                    if (it.ack_st === 1) ackLine = '<div style="font-size:12px;color:#1e8449;margin-top:2px;">✅ 确认收到' + (it.ack_at ? '（' + annEsc(it.ack_at) + '）' : '') + '</div>';
                    else if (it.ack_st === 2) ackLine = '<div style="font-size:12px;color:#b04a3a;margin-top:2px;">❌ 无法处理' + (it.ack_at ? '（' + annEsc(it.ack_at) + '）' : '') + '</div>';
                    else ackLine = '<div style="font-size:12px;color:#b9770e;margin-top:2px;">⏳ 待反馈</div>';
                }
                row.innerHTML = '<div style="font-size:14px;color:#333;">' + annEsc(it.content) + '</div>'
                    + '<div class="form-hint">' + annEsc(it.ts) + '</div>' + ackLine;
                row.onclick = function () { annHistReuse(it); };
            }
            // 操作按钮：重发 + 删除
            var ops = document.createElement('div');
            ops.style.cssText = 'margin-top:5px;display:flex;gap:8px;';
            var btRe = document.createElement('button');
            btRe.type = 'button'; btRe.className = 'btn btn-sm btn-outline';
            btRe.textContent = '🔄 重发';
            btRe.onclick = function (ev) {
                ev.stopPropagation();
                if (kind === 'img') annImgResend(urls.slice(), it);
                else if (kind === 'aud') annAudResend(it.content);   // 录音：原文件重发到大屏
                else annHistReuse(it);   // 文字/语音：载入发送框
            };
            var btDel = document.createElement('button');
            btDel.type = 'button'; btDel.className = 'btn btn-sm btn-outline'; btDel.style.color = '#c0392b';
            btDel.textContent = '🗑 删除';
            btDel.onclick = function (ev) {
                ev.stopPropagation();
                if (!window.confirm('确定删除这条历史内容？')) return;
                annPost('type=announce_del&class_id=' + ANN_CLASS_ID + '&id=' + it.id, '已删除');
                row.remove();
            };
            ops.appendChild(btRe); ops.appendChild(btDel);
            row.appendChild(ops);
            box.appendChild(row);
        });
        // 班级反馈区段（学生反向喊话历史，保留反馈信息方便回看；仅文字类历史弹窗显示）
        if (kind === 'text' && revs.length) {
            var rsec = document.createElement('div');   // 区段容器（全部删完时整体移除）
            var rh = document.createElement('div');
            rh.style.cssText = 'margin-top:12px;padding:8px 10px;background:#eef4ff;border-radius:8px;font-weight:bold;color:#2a5aa5;font-size:13px;';
            rh.textContent = '📥 班级反馈（学生反向喊话，最近 ' + revs.length + ' 条）';
            rsec.appendChild(rh);
            revs.forEach(function (rv) {
                var rrow = document.createElement('div');
                rrow.className = 'annRevRow';
                rrow.style.cssText = 'padding:8px 10px;border-bottom:1px solid #e8eefb;border-left:3px solid #4a7de0;margin:4px 0;background:#f7faff;border-radius:0 6px 6px 0;';
                rrow.innerHTML = '<div style="font-size:14px;color:#333;">' + (rv.mtype === 'voice' ? '🎤 ' : '📝 ') + '</div>'
                    + '<div class="form-hint">' + annEsc(rv.ts) + '</div>';
                rrow.firstChild.appendChild(document.createTextNode(annEsc(rv.content)));   // 内容走 textContent 防注入
                var rdel = document.createElement('button');
                rdel.type = 'button'; rdel.className = 'btn btn-sm btn-outline'; rdel.style.cssText = 'color:#c0392b;margin-top:5px;';
                rdel.textContent = '🗑 删除';
                rdel.onclick = function () {
                    if (!window.confirm('确定删除这条班级反馈？')) return;
                    annPost('type=announce_del&class_id=' + ANN_CLASS_ID + '&kind=rev&id=' + rv.id, '已删除');
                    rrow.remove();
                    if (!rsec.querySelector('.annRevRow')) rsec.remove();   // 反馈全部删完：连区段标题一起移除
                };
                rrow.appendChild(rdel);
                rsec.appendChild(rrow);
            });
            box.appendChild(rsec);
        }
    })
    .catch(function () { box.innerHTML = '<div style="color:#c0392b;padding:10px;">网络错误</div>'; });
}
function annHistReuse(it) {   // 文字/语音历史：载入发送框（含学生标签→选人播报；纯文字→快捷播报）
    var hasTag = annHasTag(strval(it.content));
    var box = hasTag ? annE('annContent') : annE('annQuickContent');
    box.value = it.content;
    if (hasTag) { annSaveTpl(); annUpdatePrev(); annTab('pick'); }
    else { annQuickInput(); annTab('quick'); }
    closeModal('annHistModal');
}
function strval(v) { return String(v === null || v === undefined ? '' : v); }
function annImgResend(urls) {   // 图片历史重发：按当前 强制显示/自动最小化/缓存保留 设置重新下发
    if (!urls || !urls.length) { annMsg('该条记录没有可用图片', 'error'); return; }
    annMsg('图片重发中…', 'ok');
    fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'type=announce_send_img&class_id=' + ANN_CLASS_ID
            + '&reurls=' + encodeURIComponent(JSON.stringify(urls))
            + '&force_show=' + (annForceOn() ? 1 : 0)
            + '&auto_min=' + Math.max(0, Math.min(3600, parseInt(annE('annMin').value, 10) || 0))
            + '&cache_min=' + Math.max(0, Math.min(1440, parseInt(annE('annCache').value, 10) || 0))
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (!d || !d.success) { annMsg((d && d.message) || '图片重发失败', 'error'); return; }
        annMsg('已重发 ' + (d.urls ? d.urls.length : urls.length) + ' 张图片到大屏', 'ok');
        closeModal('annHistModal');
    })
    .catch(function () { annMsg('网络错误', 'error'); });
}

// 试听（公共）：选人播报取首生替换模板；快捷播报直接朗读原文（学生标签置空）
function annTryText(txt) {
    if (!('speechSynthesis' in window)) { annMsg('当前浏览器不支持语音合成（试听不可用）', 'error'); return; }
    if (!txt.trim()) { annMsg('请填写播报内容', 'error'); return; }
    speechSynthesis.cancel();
    var u = new SpeechSynthesisUtterance(txt);
    u.rate = parseFloat(annE('annSpeed').value) || 1;
    u.pitch = parseFloat(annE('annPitch').value) || 1;
    u.lang = 'zh-CN';
    var vn = annE('annVoiceSel') ? annE('annVoiceSel').value : '';
    var vv = vn ? speechSynthesis.getVoices().filter(function (v) { return v.name === vn; })[0] : null;
    if (vv) u.voice = vv;   // 试听同样应用所选音色
    speechSynthesis.speak(u);
}
function annTry() {
    var s = annQueue.length ? annStuMap[annQueue[0]] : null;
    annTryText(s ? annSubst(annE('annContent').value, s, 0)
                 : annE('annContent').value.replace(/\{姓名\}|\{座号\}|\{分组\}|\{编号\}|\{座号\+姓名\}|\{座号\+未登记上一名\}|\{座号\+未登记下一名\}|\{未登记上一名\}|\{未登记下一名\}|\{name\}/g, ''));
}
function annQuickTry() { annTryText(annE('annQuickContent').value); }
function annStopTry() { if ('speechSynthesis' in window) speechSynthesis.cancel(); }

function annPost(body, okMsg, lastContent) {
    fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (!d.success) { annMsg(d.message || '发送失败', 'error'); return; }
        if (typeof lastContent === 'string') { try { localStorage.setItem('ANN_LAST_C_' + ANN_CLASS_ID, lastContent); } catch (e) {} }   // 记录本次成功发送内容（同内容再发需二次确认）
        annMsg(okMsg, 'ok');
    })
    .catch(function () { annMsg('网络错误', 'error'); });
}
function annDupCheck(finalContent) {   // 同内容防呆：与上次成功发送的内容相同 → 弹窗确认，避免误触连发刷屏
    var last = '';
    try { last = localStorage.getItem('ANN_LAST_C_' + ANN_CLASS_ID) || ''; } catch (e) {}
    if (finalContent && finalContent === last && !window.confirm('和上次内容一样，确认发送？')) return false;
    return true;
}

// 发送公共参数（快捷/选人播报共用；含需反馈勾选）
function annCommon() {
    var disp = annDisp();
    var times = Math.max(1, Math.min(5, parseInt(annE('annTimes').value, 10) || 1));
    var speed = Math.max(0.5, Math.min(2, parseFloat(annE('annSpeed').value) || 1));
    var pitch = Math.max(0.5, Math.min(2, parseFloat(annE('annPitch').value) || 1));
    var font = Math.max(16, Math.min(200, parseInt(annE('annFont').value, 10) || 64));
    var force = annForceOn() ? 1 : 0;
    var amin = Math.max(0, Math.min(3600, parseInt(annE('annMin').value, 10) || 0));
    var cmin = Math.max(0, Math.min(1440, parseInt(annE('annCache').value, 10) || 0));
    var ackTail = annNeedAckOn() ? '（需反馈）' : '';
    var common = '&font_size=' + font + '&marquee=' + (disp.mq ? 1 : 0) + '&sub_big=' + (disp.big ? 1 : 0)
        + '&force_show=' + force + '&auto_min=' + amin + '&cache_min=' + cmin + '&need_ack=' + (annNeedAckOn() ? 1 : 0);
    return { disp: disp, times: times, speed: speed, pitch: pitch, common: common, ackTail: ackTail };
}

// ===== 快捷播报（默认选项卡：发送给全班，直接输入文字，不支持学生标签） =====
function annQuickInput() {
    try { localStorage.setItem(ANN_QUICK_KEY, annE('annQuickContent').value); } catch (e) {}
    annQuickPrev();
}
function annQuickPrev() {
    var tpl = annE('annQuickContent').value;
    annE('annQuickPrev').textContent = tpl.trim() ? tpl : '（请填写播报内容）';
}
function annSendQuick() {
    var tpl = annE('annQuickContent').value.trim();
    if (!tpl && !window.confirm('播报内容为空，确认发送空消息？（客户端将先响「叮咚」提醒）')) return;   // 空内容二次确认后允许发送
    if (annHasTag(tpl)) { annMsg('快捷播报不支持学生标签，请切换到「选人播报」', 'error'); return; }
    var p = annCommon();
    if (!annDupCheck(tpl)) return;   // 同内容防呆
    if (p.disp.voice) {
        annPost('type=announce_send&class_id=' + ANN_CLASS_ID + '&mtype=voice&texts=' + encodeURIComponent(JSON.stringify([tpl]))
            + p.common + '&times=' + p.times + '&speed=' + p.speed + '&pitch=' + p.pitch,
            '已发送 1 条语音喊话到大屏' + ((p.disp.mq || p.disp.big) ? '（同步显示已开启）' : '') + p.ackTail, tpl);
    } else {
        annPost('type=announce_send&class_id=' + ANN_CLASS_ID + '&mtype=text&content=' + encodeURIComponent(tpl) + p.common,
            '字幕已发送到大屏' + (p.disp.mq ? '（跑马灯滚动）' : '（全屏大字）') + p.ackTail, tpl);
    }
}

// ===== 选人播报：发送到大屏（合并：语音播报 / 纯字幕，由「🔊语音播报」勾选决定） =====
function annSend() {
    var tpl = annE('annContent').value.trim();
    if (!tpl && !window.confirm('播报内容为空，确认发送空消息？（客户端将先响「叮咚」提醒）')) return;   // 空内容二次确认后允许发送
    var p = annCommon();
    if (p.disp.voice) {
        var texts;
        if (!annQueue.length) {
            if (annHasTag(tpl)) { annMsg('内容包含学生标签，请先在呼叫队列选择学生，或删除标签后直接发送', 'error'); return; }
            texts = [tpl];   // 未选学生：仅播报文字内容
        } else {
            texts = [annSubstMulti(tpl, annQueue)];   // 多生合并为一条播报（如「张三、李松、王五……」连读，不逐生排队）
        }
        if (!annDupCheck(texts[0])) return;   // 同内容防呆（对比替换后的最终播报文本）
        annPost('type=announce_send&class_id=' + ANN_CLASS_ID + '&mtype=voice&texts=' + encodeURIComponent(JSON.stringify(texts))
            + p.common + '&times=' + p.times + '&speed=' + p.speed + '&pitch=' + p.pitch,
            (annQueue.length > 1 ? '已合并 ' + annQueue.length + ' 名学生为 1 条语音播报' : '已发送 1 条语音喊话到大屏')
            + ((p.disp.mq || p.disp.big) ? '（同步显示已开启）' : '') + p.ackTail, texts[0]);
    } else {
        // 纯字幕同样支持学生标签：按所选名单合并替换后作为字幕内容
        if (annHasTag(tpl)) {
            if (!annQueue.length) { annMsg('内容包含学生标签，请先在呼叫队列选择学生，或删除标签后直接发送', 'error'); return; }
            tpl = annSubstMulti(tpl, annQueue);
        }
        if (!annDupCheck(tpl)) return;   // 同内容防呆（对比替换后的最终字幕文本）
        annPost('type=announce_send&class_id=' + ANN_CLASS_ID + '&mtype=text&content=' + encodeURIComponent(tpl) + p.common,
            '字幕已发送到大屏' + (p.disp.mq ? '（跑马灯滚动）' : '（全屏大字）') + p.ackTail, tpl);
    }
}

// ===== 图片发送（多图上传 → announce_send_img 存文件 → 客户端图片查看器） =====
function annImgPick(inp) {
    var files = Array.prototype.slice.call(inp.files || []);
    files.forEach(function (f) {
        if (annImgSel.length >= 9) return;
        if (!/^image\//.test(f.type)) return;
        annImgSel.push(f);
        annImgUrls.push(URL.createObjectURL(f));
    });
    inp.value = '';   // 允许再次打开选择器追加
    annImgRender();
}
function annImgRender() {
    var box = annE('annImgPrev');
    box.innerHTML = '';
    annImgUrls.forEach(function (u, i) {
        var d = document.createElement('div');
        d.style.cssText = 'position:relative;display:inline-block;';
        d.innerHTML = '<img src="' + u + '" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid #dfe6f0;display:block;">'
            + '<i data-i="' + i + '" style="position:absolute;top:-7px;right:-7px;width:18px;height:18px;line-height:16px;text-align:center;background:#fff;border:1px solid #d5dbe7;border-radius:50%;font-style:normal;font-size:11px;color:#98a2b3;cursor:pointer;">✕</i>';
        d.querySelector('i').onclick = function () {
            URL.revokeObjectURL(annImgUrls[i]);
            annImgSel.splice(i, 1); annImgUrls.splice(i, 1);
            annImgRender();
        };
        box.appendChild(d);
    });
}
function annSendImgs() {
    if (!annImgSel.length) { annMsg('请先选择要发送的图片', 'error'); return; }
    var fd = new FormData();
    fd.append('type', 'announce_send_img');
    fd.append('class_id', ANN_CLASS_ID);
    fd.append('force_show', annForceOn() ? 1 : 0);
    fd.append('auto_min', Math.max(0, Math.min(3600, parseInt(annE('annMin').value, 10) || 0)));
    fd.append('cache_min', Math.max(0, Math.min(1440, parseInt(annE('annCache').value, 10) || 0)));
    annImgSel.forEach(function (f) { fd.append('imgs[]', f); });
    annMsg('图片上传中…', 'ok');
    fetch('api.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (!d || !d.success) { annMsg((d && d.message) || '图片发送失败', 'error'); return; }
        annImgUrls.forEach(function (u) { URL.revokeObjectURL(u); });
        annImgSel = []; annImgUrls = [];
        annImgRender();
        annMsg(d.message || '图片已发送到大屏', 'ok');
    })
    .catch(function () { annMsg('网络错误', 'error'); });
}

// ===== 语音喊话（MediaRecorder 录音 ≤1 分钟 → 上传 announce_send_audio → 客户端叮咚后自动播放） =====
var annAudState = 'idle';   // idle=待录音 rec=录音中 ready=已录好待发送
var audRec = null, audChunks = [], audBlob = null, audBlobUrl = '', audSec = 0, audTimer = null;
var AUD_MAX = 60;   // 录音上限（秒）
function annAudFmt(s) { return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2); }
function annAudRender() {
    var box = annE('annAudBox');
    if (!box) return;
    annE('annAudSendBtn').style.display = annAudState === 'ready' ? '' : 'none';
    if (annAudState === 'rec') {
        box.innerHTML = '<div style="display:flex;align-items:center;gap:12px;background:#fdf0f0;border:1px solid #f2c6c6;border-radius:8px;padding:10px 14px;flex-wrap:wrap;">'
            + '<span style="width:14px;height:14px;border-radius:50%;background:#e74c3c;animation:annAudBlink 1s infinite;flex-shrink:0;"></span>'
            + '<b style="font-size:17px;color:#c0392b;">录音中 ' + annAudFmt(audSec) + ' / 1:00</b>'
            + '<button type="button" class="btn btn-outline" onclick="annAudStop()">⏹ 停止录音</button></div>';
        return;
    }
    if (annAudState === 'ready') {
        box.innerHTML = '<div style="display:flex;align-items:center;gap:12px;background:#f4faf4;border:1px solid #cdebd2;border-radius:8px;padding:10px 14px;flex-wrap:wrap;">'
            + '<audio src="' + audBlobUrl + '" controls style="height:36px;max-width:300px;"></audio>'
            + '<span class="form-hint">时长 ' + annAudFmt(audSec) + '</span>'
            + '<button type="button" class="btn btn-sm btn-outline" onclick="annAudRestart()">🔄 重新录音</button></div>';
        return;
    }
    box.innerHTML = '<button type="button" class="btn" style="background:#e74c3c;color:#fff;padding:10px 22px;font-size:15px;" onclick="annAudStart()">🎙 开始录音（最长 1 分钟）</button>'
        + '<div class="form-hint" style="margin-top:6px;">对着麦克风讲话，到 1 分钟自动停止</div>';
}
function annAudMime() {   // 优先 opus 压缩格式（体积小音质好）；Safari 回退 mp4；都不支持时用浏览器默认
    if (!window.MediaRecorder || !MediaRecorder.isTypeSupported) return '';
    var cands = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4', 'audio/mpeg'];
    for (var i = 0; i < cands.length; i++) {
        try { if (MediaRecorder.isTypeSupported(cands[i])) return cands[i]; } catch (e) {}
    }
    return '';
}
function annAudStart() {
    if (audRec) return;
    if (window.isSecureContext === false) {
        annMsg('麦克风仅限 HTTPS 或 localhost 访问，当前地址被浏览器禁用', 'error'); return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
        annMsg('当前浏览器不支持录音，请用 Chrome/Edge', 'error'); return;
    }
    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
        var mime = annAudMime();
        try { audRec = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream); }
        catch (e) { stream.getTracks().forEach(function (t) { t.stop(); }); annMsg('录音启动失败', 'error'); return; }
        audChunks = []; audBlob = null; audSec = 0;
        audRec.ondataavailable = function (e) { if (e.data && e.data.size) audChunks.push(e.data); };
        audRec.onstop = function () {
            stream.getTracks().forEach(function (t) { t.stop(); });   // 释放麦克风（浏览器录音指示灯熄灭）
            clearInterval(audTimer); audTimer = null;
            var type = (audRec && audRec.mimeType) || 'audio/webm';
            audBlob = new Blob(audChunks, { type: type });
            if (audBlobUrl) { try { URL.revokeObjectURL(audBlobUrl); } catch (e) {} }
            audBlobUrl = audBlob.size > 0 ? URL.createObjectURL(audBlob) : '';
            audRec = null;
            if (!audBlobUrl) { annAudState = 'idle'; annMsg('录音失败，请重试', 'error'); }
            else annAudState = 'ready';
            annAudRender();
        };
        audRec.start(250);   // 每 250ms 采集一次（到 1 分钟截断更精准）
        annAudState = 'rec';
        annAudRender();
        annMsg('录音中…', 'ok');
        audTimer = setInterval(function () {
            audSec++;
            if (audSec >= AUD_MAX) { annMsg('已达 1 分钟上限，自动停止', 'ok'); annAudStop(); }
            else annAudRender();
        }, 1000);
    }).catch(function () { annMsg('无法访问麦克风，请允许浏览器使用麦克风', 'error'); });
}
function annAudStop() { if (audRec && audRec.state === 'recording') { try { audRec.stop(); } catch (e) {} } }
function annAudRestart() { annAudState = 'idle'; annAudRender(); annAudStart(); }   // 重新录音：完成后自动替换旧录音
function annAudSend() {
    if (!audBlob || !audBlobUrl) { annMsg('请先录制一段语音', 'error'); return; }
    if (audSec < 1 || audBlob.size < 2000) { annMsg('录音太短，请重新录制', 'error'); return; }
    var btn = annE('annAudSendBtn');
    btn.disabled = true;
    annMsg('录音上传中…', 'ok');
    var fd = new FormData();
    fd.append('type', 'announce_send_audio');
    fd.append('class_id', ANN_CLASS_ID);
    fd.append('force_show', annForceOn() ? 1 : 0);
    fd.append('auto_min', Math.max(0, Math.min(3600, parseInt(annE('annMin').value, 10) || 0)));
    fd.append('cache_min', Math.max(0, Math.min(1440, parseInt(annE('annCache').value, 10) || 0)));
    fd.append('need_ack', annNeedAckOn() ? 1 : 0);
    fd.append('audio', audBlob, 'voice.webm');
    fetch('api.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        btn.disabled = false;
        if (!d || !d.success) { annMsg((d && d.message) || '录音发送失败', 'error'); return; }
        annMsg(d.message || '录音已发送到大屏', 'ok');
        annAudState = 'idle';
        if (audBlobUrl) { try { URL.revokeObjectURL(audBlobUrl); } catch (e) {} }
        audBlobUrl = ''; audBlob = null; audChunks = [];
        annAudRender();
    })
    .catch(function () { btn.disabled = false; annMsg('网络错误', 'error'); });
}
function annAudHist() { annHistLoad('aud'); }
function annAudResend(url) {   // 历史录音重发：原文件直接下发（不重复上传）
    if (!url) { annMsg('该条记录没有可用录音', 'error'); return; }
    annMsg('录音重发中…', 'ok');
    fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'type=announce_send_audio&class_id=' + ANN_CLASS_ID + '&reurl=' + encodeURIComponent(url)
            + '&force_show=' + (annForceOn() ? 1 : 0)
            + '&auto_min=' + Math.max(0, Math.min(3600, parseInt(annE('annMin').value, 10) || 0))
            + '&cache_min=' + Math.max(0, Math.min(1440, parseInt(annE('annCache').value, 10) || 0))
            + '&need_ack=' + (annNeedAckOn() ? 1 : 0)
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (!d || !d.success) { annMsg((d && d.message) || '录音重发失败', 'error'); return; }
        annMsg('录音已重发到大屏', 'ok');
        closeModal('annHistModal');
    })
    .catch(function () { annMsg('网络错误', 'error'); });
}

// ===== 班级端反向喊话开关（即改即存：勾选/取消/切换允许模式立即保存到 classes.rev_announce / rev_ann_modes） =====
function annRevSw() {
    annE('annRevModes').style.display = annE('annRevEn').checked ? 'flex' : 'none';
    annRevSave();
}
function annRevSave() {
    var en = annE('annRevEn').checked ? 1 : 0;
    var modes = [];
    if (annE('annRevVoice').checked) modes.push('voice');
    if (annE('annRevText').checked) modes.push('text');
    if (en && !modes.length) { annMsg('开启反向喊话请至少勾选一种允许模式', 'error'); return; }
    annPost('type=announce_rev_cfg&class_id=' + ANN_CLASS_ID + '&enabled=' + en + '&modes=' + encodeURIComponent(modes.join(',')),
        en ? '已开启班级端反向喊话' : '已关闭班级端反向喊话');
    if (window.annRevChanged) annRevChanged(en === 1);   // 通知宿主页面（project_view）启停轮询
}
</script>
