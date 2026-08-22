<?php
// ============================================================
// 兑换码 管理员页面（安全版 v4）
// 路径: 你的站点目录/admin/index.php
// 说明: config.json 仅存 HMAC 索引 + AES 密文；此处仅在管理员登录会话内
//       解密展示明文码与奖励。配置文件即使泄露也无法还原内容。
// ============================================================
require_once __DIR__ . '/../bm_auth.php';

// 禁止浏览器/面板缓存管理页，确保每次打开都是最新代码
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
// 统一安全响应头（防 iframe 嵌入 / MIME 嗅探 / 来源泄露）
dh_security_headers();

$logged = dh_is_logged_in();

// 登录防爆破：先检查当前 IP 是否被锁定
if (!$logged) {
    $lockLeft = dh_lock_remaining();
    if ($lockLeft > 0) {
        $err = '登录尝试过于频繁，请 ' . ceil($lockLeft / 60) . ' 分钟后再试';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (!empty($err)) {
        // 已锁定：直接拒绝，不校验密码
    } elseif (hash_equals(DH_ADMIN_PASS, (string)$_POST['password'])) {
        dh_set_login();
        dh_clear_fail();
        $logged = true;
    } else {
        dh_register_fail();
        $left = dh_lock_remaining();
        $err = $left > 0
            ? '密码错误，尝试次数过多已锁定，请 ' . ceil($left / 60) . ' 分钟后再试'
            : '密码错误';
    }
}
if (isset($_GET['logout'])) {
    dh_clear_login();
    $logged = false;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>兑换码管理 - 异色生存服</title>
<style>
  :root{
    --bg:#0b0e14; --panel:#121722; --panel2:#0f141f; --border:#1f2733;
    --border2:#2a3345; --text:#dbe2ee; --muted:#7d8899; --dim:#5a6473;
    --accent:#4f8cff; --accent2:#7aa2f7; --green:#3ddc84; --yellow:#f5b83d;
    --red:#ef5b5b; --purple:#b48cff; --mono:#9ce8b0;
  }
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--bg);color:var(--text);font:14px/1.65 "Microsoft YaHei",system-ui,sans-serif;padding:24px}
  .wrap{max-width:1060px;margin:0 auto}
  h1.page-title{font-size:21px;font-weight:700;color:var(--accent2);margin-bottom:18px;display:flex;align-items:center;gap:10px}
  h1.page-title .logout{margin-left:auto;font-size:13px;font-weight:400;color:var(--muted);text-decoration:none;border:1px solid var(--border2);padding:4px 12px;border-radius:8px}
  h1.page-title .logout:hover{color:var(--red);border-color:var(--red)}
  .card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:20px 22px;margin-bottom:18px;box-shadow:0 2px 10px rgba(0,0,0,.25)}
  .card h2{font-size:15px;font-weight:700;color:var(--green);margin-bottom:6px;display:flex;align-items:center;gap:8px}
  .card h2 .sub{font-size:12px;font-weight:400;color:var(--muted)}
  .section{margin-top:16px;padding-top:14px;border-top:1px dashed var(--border2)}
  .section:first-of-type{border-top:none;margin-top:10px;padding-top:0}
  .sec-title{font-size:13px;font-weight:600;color:var(--accent2);margin-bottom:10px;display:flex;align-items:center;gap:6px}
  .grid{display:grid;gap:12px}
  .grid-3{grid-template-columns:repeat(3,1fr)}
  .grid-2{grid-template-columns:repeat(2,1fr)}
  .field{display:flex;flex-direction:column;gap:5px}
  .field label{font-size:12px;color:var(--text);font-weight:600}
  .field label .req{color:var(--red)}
  .field .hint{font-size:11px;color:var(--dim);line-height:1.5}
  .field .hint code{color:var(--mono)}
  input[type=text],input[type=number],select,textarea{
    background:var(--panel2);color:var(--text);border:1px solid var(--border2);border-radius:8px;
    padding:8px 10px;font-size:13px;outline:none;transition:border-color .15s;
  }
  input[type=text]:focus,input[type=number]:focus,select:focus,textarea:focus{border-color:var(--accent)}
  input[type=text]::placeholder,textarea::placeholder{color:var(--dim)}
  textarea{font-family:Consolas,monospace;font-size:12px;line-height:1.6;resize:vertical}
  button{cursor:pointer;border:0;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:600;transition:filter .15s,opacity .15s}
  button:hover{filter:brightness(1.15)}
  .btn-primary{background:var(--accent);color:#fff}
  .btn-green{background:var(--green);color:#06210f}
  .btn-gray{background:#2a3242;color:#cdd6e4}
  .btn-danger{background:var(--red);color:#fff}
  .btn-sm{padding:5px 10px;font-size:12px}
  .btn-xs{padding:3px 8px;font-size:11px}

  /* ===== 兑换码信息 ===== */
  .limit-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
  .limit-opt{
    display:flex;flex-direction:column;gap:4px;cursor:pointer;
    border:1px solid var(--border2);border-radius:10px;padding:12px 14px;
    transition:border-color .15s,background .15s;
  }
  .limit-opt:hover{border-color:var(--accent)}
  .limit-opt input{accent-color:var(--accent);width:16px;height:16px}
  .limit-opt .name{font-size:13px;font-weight:700}
  .limit-opt .desc{font-size:11px;color:var(--dim)}
  .limit-opt:has(input:checked){border-color:var(--accent);background:rgba(79,140,255,.08)}
  .limit-opt:has(input:checked) .name{color:var(--accent2)}

  /* ===== 奖励卡片 ===== */
  #rewards{display:flex;flex-direction:column;gap:14px;margin-top:6px}
  .reward-card{background:var(--panel2);border:1px solid var(--border);border-radius:12px;padding:16px}
  .reward-card .rc-head{display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap}
  .reward-card .rc-no{font-size:12px;font-weight:700;color:var(--accent2);background:rgba(79,140,255,.12);padding:3px 10px;border-radius:20px}
  .reward-card .rc-type{flex:0 0 auto;min-width:120px;font-size:13px}
  .reward-card .rc-del{margin-left:auto}
  .rc-body{display:flex;flex-direction:column;gap:12px}
  .rc-sub{border-left:3px solid var(--border2);padding-left:14px}
  .rc-sub.enchant-sub{border-left-color:#7c4dff}
  .rc-sub.nbt-sub{border-left-color:#22c55e}
  .rc-sub .sub-title{font-size:12px;font-weight:700;margin-bottom:8px;display:flex;align-items:center;gap:6px}
  .rc-sub.enchant-sub .sub-title{color:var(--purple)}
  .rc-sub.nbt-sub .sub-title{color:var(--green)}

  /* 物品搜索 */
  .itemsearch{display:flex;align-items:center;gap:8px;position:relative;flex-wrap:wrap}
  .itemsearch input[type=text]{flex:1;min-width:200px}
  .is-pop{position:absolute;top:110%;left:0;z-index:60;background:#0d1220;border:1px solid var(--accent);border-radius:10px;max-height:300px;overflow:auto;min-width:400px;box-shadow:0 12px 30px rgba(0,0,0,.6)}
  .is-pop .is-item{padding:7px 12px;cursor:pointer;display:flex;gap:10px;align-items:center;border-bottom:1px solid var(--border)}
  .is-pop .is-item:hover{background:#1a2235}
  .is-pop .is-item code{color:var(--mono);font-size:12px}
  .is-pop .is-item span{color:var(--green);font-size:12px}
  .is-pop .is-none{padding:10px 12px;color:var(--red);font-size:12px}
  .is-hint{font-size:12px}
  .is-hint.ok{color:var(--green)}
  .is-hint.bad{color:var(--red)}

  /* 附魔行 */
  .ench-rows{display:flex;flex-direction:column;gap:8px}
  .ench-row{display:flex;gap:8px;align-items:center}
  .ench-row select{flex:1;min-width:180px;max-width:280px}
  .ench-row input[type=number]{width:80px}
  .ench-add{margin-top:8px}

  /* NBT 区 */
  .nbt-preview{
    font-family:Consolas,monospace;font-size:11.5px;color:var(--mono);
    background:#0a0f16;border:1px solid var(--border);border-radius:8px;
    padding:8px 10px;margin-bottom:8px;white-space:pre-wrap;word-break:break-all;
    max-height:130px;overflow:auto;
  }
  .nbt-preview .empty{color:var(--dim)}
  .nbt-preview b{color:var(--yellow);font-weight:600}
  .nbt-box textarea{width:100%;min-height:56px}
  .nbt-hint{font-size:11px;color:var(--dim);margin-top:6px}

  /* 占位符 chips */
  .chips{margin-top:16px;padding-top:14px;border-top:1px dashed var(--border2);display:flex;gap:6px;flex-wrap:wrap;align-items:center}
  .chiphint{color:var(--dim);font-size:12px;margin-right:4px}
  .chip{background:#1c2434;border:1px solid var(--border2);border-radius:20px;padding:3px 12px;font-size:12px;color:var(--mono);cursor:pointer;user-select:none}
  .chip:hover{background:#27344a;border-color:var(--accent)}

  /* 动作栏 */
  .actions{display:flex;gap:10px;margin-top:18px;align-items:center}
  .actions .spacer{flex:1}

  /* 预览 */
  #previewBody{font-size:13px;line-height:1.9}
  .pv-item{display:flex;gap:8px;padding:5px 0;border-bottom:1px dashed var(--border)}
  .pv-item:last-child{border-bottom:none}
  .pv-item .tag{flex:0 0 auto;font-weight:700;color:var(--accent2)}
  .pv-item .body{color:var(--text);word-break:break-all}
  .pv-item .body code{color:var(--mono);font-size:12px}
  .pv-item .body .en{color:var(--purple)}
  .pv-item .body .nb{color:var(--yellow)}
  .pv-empty{color:var(--dim)}

  /* 列表 */
  table{width:100%;border-collapse:collapse;font-size:13px;margin-top:10px}
  th,td{border:1px solid var(--border);padding:8px 10px;text-align:left;vertical-align:top}
  th{background:#161d2c;color:#93a1b5;font-weight:600;font-size:12px}
  tr:hover td{background:#151b29}
  .tag{display:inline-block;background:#1c2434;border:1px solid var(--border2);border-radius:6px;padding:1px 8px;margin:1px 2px;font-size:11px;color:#aab6c8}
  code{background:#161d2c;padding:1px 6px;border-radius:4px;color:var(--mono);font-size:12px}
  .muted{color:var(--muted)}
  .err{color:var(--red);margin-bottom:10px;font-size:13px}
  .ok{color:var(--green);margin-bottom:10px;font-size:13px}
  .hide{display:none!important}

  /* 登录框 */
  .login-box{max-width:380px;margin:90px auto;background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:32px}
  .login-box h1{font-size:18px;text-align:center;color:var(--accent2);margin-bottom:18px}
  .login-box input{width:100%;padding:11px;margin-bottom:10px}
  .login-box button{width:100%;padding:11px;margin-top:4px}
  .login-box .muted{text-align:center;display:block;margin-top:12px}

  @media (max-width:800px){ .grid-3,.grid-2,.limit-row{grid-template-columns:1fr} }
</style>
</head>
<body>
<div class="wrap">
<?php if (!$logged): ?>
  <div class="login-box">
    <h1>🔑 兑换码管理</h1>
    <?php if (isset($err)) echo '<div class="err">' . htmlspecialchars($err) . '</div>'; ?>
    <form method="post" id="loginForm">
      <input type="password" name="password" placeholder="管理员密码" autofocus>
      <button class="btn-primary" type="submit">登 录</button>
    </form>
    <span class="muted">异色生存服 · 兑换码后台</span>
  </div>
<?php else: ?>

  <h1 class="page-title">🔑 兑换码管理
    <span class="muted" style="font-size:12px;font-weight:400">| 异色生存服</span>
    <a href="?logout=1" class="logout">退出登录</a>
  </h1>

  <!-- ============ 兑换码信息 ============ -->
  <div class="card">
    <h2>📋 兑换码信息</h2>
    <div id="formMsg"></div>

    <div class="grid grid-3">
      <div class="field">
        <label>兑换码 <span class="req">*</span></label>
        <input type="text" id="code" placeholder="例如 WELCOME2026" style="font-family:Consolas,monospace">
        <div class="hint">玩家在游戏里输入的内容，<b>不区分大小写</b>，建议字母+数字组合</div>
      </div>
      <div class="field">
        <label>名称 <span class="req">*</span></label>
        <input type="text" id="name" placeholder="例如：VIP礼包">
        <div class="hint">给这个兑换码起的名字，方便你识别，会显示在列表和查询结果里</div>
      </div>
      <div class="field">
        <label>描述（可选）</label>
        <input type="text" id="desc" placeholder="例如：开服新人福利">
        <div class="hint">对这个码的补充说明，不发给玩家</div>
      </div>
    </div>

    <div class="section">
      <div class="sec-title">🔁 兑换限制（三选一）</div>
      <div class="limit-row">
        <label class="limit-opt">
          <input type="radio" name="limitMode" value="none" checked>
          <span class="name">不限次数</span>
          <span class="desc">每个玩家可以反复兑换这个码</span>
        </label>
        <label class="limit-opt">
          <input type="radio" name="limitMode" value="once">
          <span class="name">每人限兑一次</span>
          <span class="desc">每个玩家都只能兑换一次</span>
        </label>
        <label class="limit-opt">
          <input type="radio" name="limitMode" value="globalOnce">
          <span class="name">只能兑换一次（全服）</span>
          <span class="desc">全服仅能被兑换一次，兑完自动删除</span>
        </label>
      </div>
    </div>
  </div>

  <!-- ============ 奖励编辑 ============ -->
  <div class="card">
    <h2>🎁 奖励内容 <span class="sub">（可添加多条，玩家兑换时一次性发放全部）</span></h2>
    <div id="rewards"></div>
    <div class="actions">
      <button class="btn-green" id="addRewardBtn" type="button">＋ 添加奖励</button>
      <button class="btn-primary" id="saveBtn" type="button">💾 保存</button>
      <button class="btn-gray hide" id="cancelBtn" type="button">取消编辑</button>
    </div>
    <div class="chips" id="chips">
      <span class="chiphint">占位符（点一下插入到光标处）：</span>
      <span class="chip" data-ins="{player}">{player}</span>
      <span class="chip" data-ins="&amp;a">&amp;a 绿</span>
      <span class="chip" data-ins="&amp;b">&amp;b 蓝</span>
      <span class="chip" data-ins="&amp;c">&amp;c 红</span>
      <span class="chip" data-ins="&amp;e">&amp;e 黄</span>
      <span class="chip" data-ins="&amp;7">&amp;7 灰</span>
      <span class="chip" data-ins="&amp;l">&amp;l 粗</span>
      <span class="chip" data-ins="minecraft:">minecraft:</span>
    </div>
  </div>

  <!-- ============ 奖励预览 ============ -->
  <div class="card">
    <h2>👁 奖励预览 <span class="sub">（实时展示已添加的奖励，保存前请核对）</span></h2>
    <div id="previewBody"></div>
  </div>

  <!-- ============ 兑换码列表 ============ -->
  <div class="card">
    <h2>📜 兑换码列表（<span id="cnt">0</span>）</h2>
    <table>
      <thead><tr><th style="width:140px">兑换码</th><th>名称 / 描述</th><th>奖励</th><th style="width:120px">兑换限制</th><th style="width:110px">操作</th></tr></thead>
      <tbody id="tbody"></tbody>
    </table>
  </div>

<?php endif; ?>
</div>

<?php if ($logged): ?>
<script src="itemdb.js?<?= filemtime(__DIR__ . '/itemdb.js') ?>"></script>
<script src="enchantdb.js?<?= filemtime(__DIR__ . '/enchantdb.js') ?>"></script>
<script>
const REWARD_TYPES = {
  item: ['material','amount','itemname','lore'],
  command: ['run','console'],
  permission: ['node'],
  money: ['money'],
  message: ['text']
};
const FIELDS = {
  material: {label:'物品 ID', hint:'输入或搜索选择物品，支持中文名 / 英文 ID / 完整 ID', ph:'例如 钻石 或 diamond'},
  amount:   {label:'数量', hint:'发放几个该物品，最小 1', ph:'1', num:true},
  itemname: {label:'自定义名字（可选）', hint:'物品在背包里显示的名字，支持 &amp; 颜色代码', ph:'&b璀璨钻石'},
  lore:     {label:'物品描述（可选）', hint:'物品下方的灰色说明文字，每行一条', ph:'&7来自兑换码', area:true},
  run:      {label:'要执行的命令', hint:'<code>{player}</code> 会自动替换成玩家名', ph:'lp user {player} parent add vip', full:true},
  console:  {label:'控制台执行', hint:'勾选=服务器控制台执行；不勾=以玩家身份执行', chk:true},
  node:     {label:'权限节点', hint:'发给玩家的权限节点，例如 vip.rank', ph:'vip.rank'},
  money:    {label:'金币数量', hint:'通过经济插件发放的金币数', ph:'1000', num:true},
  text:     {label:'消息内容', hint:'发给玩家的文字消息，支持 &amp; 颜色代码', ph:'&a兑换成功！', full:true}
};
let editing = null;       // 当前编辑的 HMAC 索引
let rewards = [];         // 编辑中的奖励数组
let lastFocus = null;     // 最近聚焦的可输入元素（占位符插入目标）

function el(id){return document.getElementById(id);}
function typeName(t){return {item:'物品',command:'命令',permission:'权限',money:'金币',message:'消息'}[t]||t;}
function esc(s){ return (s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }

// ---- 占位符：记录最近聚焦的输入，点击时插入到光标处 ----
document.addEventListener('focusin', e => {
  const t = e.target;
  if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA')) lastFocus = t;
});
function insertAtCursor(text){
  let target = lastFocus;
  if (!target || !document.body.contains(target)) target = el('code');
  if (!target) return;
  const s = target.selectionStart ?? target.value.length;
  const e = target.selectionEnd ?? target.value.length;
  const v = target.value;
  target.value = v.slice(0, s) + text + v.slice(e);
  const pos = s + text.length;
  target.focus();
  try { target.setSelectionRange(pos, pos); } catch(_) {}
}
document.querySelectorAll('#chips .chip').forEach(c => {
  c.addEventListener('click', () => insertAtCursor(c.getAttribute('data-ins')));
});

// ===================== 奖励渲染 =====================

function renderRewards(){
  const box = el('rewards'); box.innerHTML = '';
  rewards.forEach((r, i) => {
    const card = buildRewardCard(i, r);
    box.appendChild(card);
    bindCard(i, r);   // 卡片已挂载 DOM，绑定才有效
  });
  renderPreview();
}

/** 卡片挂载后统一绑定各类交互 */
function bindCard(i, r){
  (REWARD_TYPES[r.type] || []).forEach(k => bindRewardInput(i, k));
  if (r.type === 'item') {
    bindItemSearch(i);
    bindEnchantUI(i);
  }
  const nta = el(`nbt_${i}`);
  if (nta) { renderNbtPreview(i); }
}

function buildRewardCard(i, r){
  const card = document.createElement('div');
  card.className = 'reward-card';

  // 头部：序号 + 类型 + 删除
  const head = document.createElement('div');
  head.className = 'rc-head';
  head.innerHTML = `<span class="rc-no">奖励 ${i+1}</span>`;
  const sel = document.createElement('select');
  sel.className = 'rc-type';
  sel.innerHTML = Object.keys(REWARD_TYPES).map(t => `<option value="${t}" ${t===r.type?'selected':''}>${typeName(t)}</option>`).join('');
  sel.onchange = () => { rewards[i] = {type:sel.value}; renderRewards(); };
  head.appendChild(sel);
  const del = document.createElement('button');
  del.className = 'btn-danger btn-sm rc-del'; del.type = 'button'; del.textContent = '删除该奖励';
  del.onclick = () => { rewards.splice(i,1); renderRewards(); };
  head.appendChild(del);
  card.appendChild(head);

  // 主体
  const body = document.createElement('div');
  body.className = 'rc-body';

  if (r.type === 'item') {
    // 物品字段
    const fg = document.createElement('div');
    fg.className = 'grid grid-3';
    fg.innerHTML = `
      <div class="field" style="grid-column:1/-1">
        <label>物品 ID <span class="req">*</span></label>
        <span class="itemsearch">
          <input type="text" id="f_${i}_material" placeholder="搜索或输入：钻石 / diamond / minecraft:diamond" value="${esc(r.material||'')}" autocomplete="off">
          <button class="btn-gray" type="button" id="is_${i}">🔍 搜索</button>
          <span class="is-hint" id="ish_${i}"></span>
          <div class="is-pop hide" id="isp_${i}"></div>
        </span>
        <div class="hint">${FIELDS.material.hint}</div>
      </div>
      <div class="field">
        <label>数量 <span class="req">*</span></label>
        <input type="number" id="f_${i}_amount" min="1" value="${Number(r.amount)||1}">
        <div class="hint">${FIELDS.amount.hint}</div>
      </div>
      <div class="field">
        <label>自定义名字（可选）</label>
        <input type="text" id="f_${i}_itemname" placeholder="${FIELDS.itemname.ph}" value="${esc(r.itemname||'')}">
        <div class="hint">${FIELDS.itemname.hint}</div>
      </div>
      <div class="field">
        <label>物品描述（可选）</label>
        <textarea id="f_${i}_lore" rows="2" placeholder="${FIELDS.lore.ph}">${esc(Array.isArray(r.lore)?r.lore.join('\n'):(r.lore||''))}</textarea>
        <div class="hint">${FIELDS.lore.hint}</div>
      </div>`;
    body.appendChild(fg);

    // 附魔
    const es = document.createElement('div');
    es.className = 'rc-sub enchant-sub';
    es.innerHTML = `<div class="sub-title">⚔ 附魔 <span class="muted" style="font-size:11px;font-weight:400">（附魔书自动写入存储附魔）</span></div>`;
    const erows = document.createElement('div');
    erows.className = 'ench-rows'; erows.id = `enchrows_${i}`;
    (r.enchants||[]).forEach((en,j) => erows.appendChild(enchRowEl(i,j,en.enchant||'',en.level||1)));
    es.appendChild(erows);
    const ebtn = document.createElement('button');
    ebtn.className = 'btn-gray btn-sm ench-add'; ebtn.type = 'button'; ebtn.id = `encha_${i}`; ebtn.textContent = '＋ 添加附魔';
    es.appendChild(ebtn);
    body.appendChild(es);

    // NBT（有物品就显示，并实时预览）
    body.appendChild(buildNbtSection(i, r));
  } else {
    // 非物品类型：通用字段渲染（带说明）
    const fg = document.createElement('div');
    fg.className = 'grid grid-2';
    (REWARD_TYPES[r.type] || []).forEach(k => {
      const f = FIELDS[k];
      const val = r[k] ?? (f.chk ? false : '');
      const inner = f.chk
        ? `<label style="display:flex;align-items:center;gap:8px;font-size:13px;padding-top:6px"><input type="checkbox" id="f_${i}_${k}" ${val?'checked':''}> 由控制台执行</label>`
        : f.area
          ? `<textarea id="f_${i}_${k}" rows="2" placeholder="${f.ph}">${esc(String(val))}</textarea>`
          : `<input type="${f.num?'number':f.full?'text':'text'}" id="f_${i}_${k}" placeholder="${f.ph}" value="${esc(String(val))}" ${f.num?'min="0" step="any"':''}>`;
      fg.innerHTML += `
        <div class="field" ${f.full?'style="grid-column:1/-1"':''}>
          <label>${f.label} <span class="req">${['run','node','money','text'].includes(k)?'*':''}</span></label>
          ${inner}
          <div class="hint">${f.hint}</div>
        </div>`;
    });
    body.appendChild(fg);
  }

  card.appendChild(body);
  return card;
}

// ---- 附魔行 ----
function enchRowEl(i, j, enchant, level){
  const row = document.createElement('div');
  row.className = 'ench-row';
  const opts = ['<option value="">— 选择附魔 —</option>']
    .concat(Object.keys(window.ENCHANT_DB || {}).map(k =>
      `<option value="${esc(k)}" ${String(enchant)===k?'selected':''}>${esc(ENCHANT_DB[k])}（${esc(k.replace('minecraft:',''))}）</option>`))
    .join('');
  row.innerHTML = `
    <select id="en_${i}_${j}">${opts}</select>
    <input type="number" id="el_${i}_${j}" min="1" max="255" value="${Number(level)||1}" title="附魔等级">
    <button class="btn-danger btn-xs" type="button">删除</button>`;
  row.querySelector('button').onclick = () => { row.remove(); syncEnchantsToReward(i); renderPreview(); renderNbtPreview(i); };
  return row;
}
function bindEnchantUI(i){
  const add = el(`encha_${i}`);
  if (!add) return;
  add.onclick = () => {
    const rows = el(`enchrows_${i}`);
    const j = rows ? rows.children.length : 0;
    rows.appendChild(enchRowEl(i, j, '', 1));
  };
  const rows = el(`enchrows_${i}`);
  if (rows) {
    rows.addEventListener('change', () => { syncEnchantsToReward(i); renderPreview(); renderNbtPreview(i); });
    rows.addEventListener('input', () => { syncEnchantsToReward(i); renderPreview(); renderNbtPreview(i); });
  }
}
function syncEnchantsToReward(i){
  const rows = el(`enchrows_${i}`); if(!rows) return;
  const arr = [];
  rows.querySelectorAll('.ench-row').forEach(row => {
    const s = row.querySelector('select'); const lv = row.querySelector('input[type=number]');
    if (s && s.value) arr.push({enchant: s.value, level: Math.max(1, Number(lv && lv.value) || 1)});
  });
  if (!rewards[i]) rewards[i] = {};
  rewards[i].enchants = arr;
}

// ---- NBT 修改器（仅物品奖励，有物品就显示）----
function buildNbtSection(i, r){
  const box = document.createElement('div');
  box.className = 'rc-sub nbt-sub nbt-box';
  box.innerHTML = `
    <div class="sub-title">🧬 NBT 修改器 <span class="muted" style="font-size:11px;font-weight:400">（可选，高级功能）</span></div>
    <div class="nbt-preview" id="nbtprev_${i}"></div>
    <textarea id="nbt_${i}" rows="2" placeholder='{"Unbreakable":1b,"HideFlags":63,"mykey":"value"}'></textarea>
    <div class="nbt-hint">${'填写额外的 NBT（SNBT 格式），会写入物品的 <code>custom_data</code> 组件。上面的预览实时显示「附魔 + 自定义 NBT」组合后的最终数据；留空则只保留附魔。常用：<code>{"Unbreakable":1b}</code> 不可破坏、<code>{"HideFlags":63}</code> 隐藏全部提示。'}</div>`;
  const ta = box.querySelector('textarea');
  ta.value = r.nbt || '';
  ta.addEventListener('input', () => { if (!rewards[i]) rewards[i] = {}; rewards[i].nbt = ta.value; renderPreview(); renderNbtPreview(i); });
  // 初次渲染预览
  setTimeout(() => renderNbtPreview(i), 0);
  return box;
}

/** 实时预览当前物品的最终 NBT（附魔 + 自定义） */
function renderNbtPreview(i){
  const box = el(`nbtprev_${i}`); if(!box) return;
  const r = rewards[i]; if(!r || r.type!=='item') return;
  const isBook = (r.material||'').includes('enchanted_book');
  const parts = [];
  const ench = (r.enchants||[]).filter(e=>e.enchant);
  if (ench.length) {
    const key = isBook ? 'StoredEnchantments' : 'Enchantments';
    const items = ench.map(e => `{id:"${e.enchant}",lvl:${Number(e.level)||1}s}`).join(',');
    parts.push(`<b>${key}:</b>[${esc(items)}]`);
  }
  const nbt = (r.nbt||'').trim();
  if (nbt) parts.push(`<b>custom_data:</b>${esc(nbt)}`);
  if (!parts.length) { box.innerHTML = '<span class="empty">（无附魔、无自定义 NBT —— 物品保持原样）</span>'; return; }
  box.innerHTML = '最终 NBT：{ ' + parts.join('  ') + ' }';
}

// ---- 物品搜索 ----
function bindItemSearch(i){
  const inp = el(`f_${i}_material`); if(!inp) return;
  const btn = el(`is_${i}`); const pop = el(`isp_${i}`); const hint = el(`ish_${i}`);
  function updateHint(){
    if(!hint) return;
    const v = inp.value.trim().toLowerCase();
    if(!v){ hint.textContent=''; return; }
    const zh = window.ITEM_DB && ITEM_DB[v];
    if (zh) { hint.textContent = '✓ ' + zh; hint.className = 'is-hint ok'; }
    else {
      const hits = searchItems(inp.value, 1);
      if(hits.length) { hint.textContent='✓ '+hits[0].name; hint.className='is-hint ok'; }
      else { hint.textContent='⚠ 未找到该物品'; hint.className='is-hint bad'; }
    }
  }
  function showPop(){
    const q = inp.value.trim();
    const hits = q ? searchItems(q, 30) : Object.entries(window.ITEM_DB||{}).slice(0,30);
    if(!hits.length){ pop.innerHTML='<div class="is-none">没有匹配的物品</div>'; pop.classList.remove('hide'); return; }
    pop.innerHTML = hits.map(h =>
      `<div class="is-item" data-id="${esc(h.id)}" data-name="${esc(h.name)}">
         <code>${esc(h.id)}</code> <span>${esc(h.name)}</span>
       </div>`).join('');
    pop.classList.remove('hide');
    pop.querySelectorAll('.is-item').forEach(nd => {
      nd.addEventListener('click', () => {
        inp.value = nd.getAttribute('data-id');
        pop.classList.add('hide');
        updateHint();
        if (rewards[i]) rewards[i].material = inp.value;
        renderPreview(); renderNbtPreview(i);
      });
    });
  }
  btn.addEventListener('click', (e)=>{ e.stopPropagation(); showPop(); });
  inp.addEventListener('input', ()=>{ updateHint(); showPop(); renderPreview(); renderNbtPreview(i); });
  inp.addEventListener('focus', ()=>{ updateHint(); showPop(); });
  document.addEventListener('click', (e)=>{ if(!pop.contains(e.target) && e.target!==btn && e.target!==inp) pop.classList.add('hide'); });
  inp.addEventListener('keydown', (e)=>{ if(e.key==='Enter'){ e.preventDefault(); showPop(); }});
  updateHint();
}
function searchItems(q, max){
  q = q.trim().toLowerCase();
  if(!q) return [];
  const out = [];
  const db = window.ITEM_DB || {};
  for(const id in db){
    const name = db[id];
    if(id.includes(q) || name.toLowerCase().includes(q)){
      out.push({id, name});
      if(out.length >= max) break;
    }
  }
  return out;
}

function addReward(){ rewards.push({type:'item', material:'minecraft:diamond', amount:1}); renderRewards(); }

// ===================== 数据同步 / 预览 / 校验 =====================

function bindRewardInput(i, k){
  const elm = document.getElementById('f_' + i + '_' + k);
  if (!elm) return;
  const f = FIELDS[k];
  const evt = (f && f.chk) ? 'change' : 'input';
  elm.addEventListener(evt, () => {
    if (!rewards[i]) return;
    if (f && f.chk) rewards[i][k] = elm.checked;
    else if (k === 'lore') rewards[i][k] = (elm.value || '').split('\n').map(x => x.trim()).filter(x => x.length > 0);
    else rewards[i][k] = elm.value;
    renderPreview();
    if (k === 'material') renderNbtPreview(i);
  });
}

function renderPreview(){
  const body = el('previewBody'); if(!body) return;
  if (!rewards.length) { body.innerHTML = '<span class="pv-empty">尚未添加任何奖励。点「＋ 添加奖励」开始。</span>'; return; }
  const lines = rewards.map((r, i) => {
    const tag = '<span class="tag">' + (i+1) + '. ' + typeName(r.type) + '</span>';
    let txt = '';
    if (r.type === 'item') {
      const zh = (window.ITEM_DB && r.material && ITEM_DB[r.material]) || r.material || '?';
      txt += '<code>' + esc(r.material||'') + '</code>（' + esc(zh) + '）× ' + (Number(r.amount)||1);
      if (r.itemname) txt += ' ｜ 名：<span style="color:#7ee787">' + esc(r.itemname) + '</span>';
      if (r.lore && r.lore.length) txt += ' ｜ 描述：' + r.lore.map(x => esc(x)).join(' / ');
      if (r.enchants && r.enchants.length) {
        const es = r.enchants.filter(e=>e.enchant).map(e => {
          const k = (e.enchant||'').replace('minecraft:','');
          const zh2 = window.ENCHANT_DB && ENCHANT_DB[e.enchant] ? ENCHANT_DB[e.enchant] : k;
          return zh2 + ' ' + (e.level||1);
        }).join('、');
        if (es) txt += ' ｜ <span class="en">附魔：' + esc(es) + '</span>';
      }
      if (r.nbt && r.nbt.trim()) txt += ' ｜ <span class="nb">NBT：<code>' + esc(r.nbt.trim()) + '</code></span>';
    } else if (r.type === 'command') {
      txt += (r.console ? '控制台执行：' : '玩家执行：') + '<code>' + esc(r.run||'') + '</code>';
    } else if (r.type === 'permission') {
      txt += '权限节点：<code>' + esc(r.node||'') + '</code>';
    } else if (r.type === 'money') {
      txt += '金币：<b>' + (Number(r.money)||0) + '</b>';
    } else if (r.type === 'message') {
      txt += '消息：' + esc(r.text||'');
    }
    return '<div class="pv-item">' + tag + '<span class="body">' + txt + '</span></div>';
  });
  body.innerHTML = lines.join('');
}

function validateEntry(){
  if (!rewards.length) { msg('请先添加至少一个奖励（点「＋ 添加奖励」）', false); return false; }
  for (let i = 0; i < rewards.length; i++) {
    const r = rewards[i];
    if (r.type === 'item') {
      const m = (el(`f_${i}_material`) || {}).value || '';
      if (!m.trim()) { msg('第 ' + (i+1) + ' 个奖励：请填写物品 ID', false); return false; }
      const amt = (el(`f_${i}_amount`) || {}).value || '';
      if (amt === '') { msg('第 ' + (i+1) + ' 个奖励：请填写数量', false); return false; }
      if (!/^\d+$/.test(amt.trim())) { msg('第 ' + (i+1) + ' 个奖励：数量必须是数字', false); return false; }
      const rows = el(`enchrows_${i}`);
      if (rows) {
        let bad = -1;
        rows.querySelectorAll('.ench-row select').forEach((s, idx) => { if (!s.value && bad < 0) bad = idx; });
        if (bad >= 0) { msg('第 ' + (i+1) + ' 个奖励：第 ' + (bad+1) + ' 条附魔还没选择，请选择或删除该行', false); return false; }
      }
    } else if (r.type === 'command') {
      if (!((el(`f_${i}_run`) || {}).value || '').trim()) { msg('第 ' + (i+1) + ' 个奖励：请填写要执行的命令', false); return false; }
    } else if (r.type === 'permission') {
      if (!((el(`f_${i}_node`) || {}).value || '').trim()) { msg('第 ' + (i+1) + ' 个奖励：请填写权限节点', false); return false; }
    } else if (r.type === 'money') {
      const mv = ((el(`f_${i}_money`) || {}).value || '').trim();
      if (mv === '') { msg('第 ' + (i+1) + ' 个奖励：请填写金币数量', false); return false; }
    } else if (r.type === 'message') {
      if (!((el(`f_${i}_text`) || {}).value || '').trim()) { msg('第 ' + (i+1) + ' 个奖励：请填写消息内容', false); return false; }
    }
  }
  return true;
}

function getLimitMode(){
  const sel = document.querySelector('input[name=limitMode]:checked');
  return sel ? sel.value : 'none';
}

function collectEntry(){
  const mode = getLimitMode();
  const entry = {
    name: el('name').value, desc: el('desc').value,
    once: mode === 'once', globalOnce: mode === 'globalOnce',
    rewards: []
  };
  rewards.forEach((r, i) => {
    const e = {type: r.type};
    (REWARD_TYPES[r.type] || []).forEach(k => {
      if (FIELDS[k].chk) e[k] = el(`f_${i}_${k}`).checked;
      else if (k === 'lore') {
        e[k] = (el(`f_${i}_${k}`).value || '').split('\n').map(x => x.trim()).filter(x => x.length > 0);
      }
      else e[k] = (el(`f_${i}_${k}`).value).trim();
    });
    if (r.type === 'item') {
      syncEnchantsToReward(i);
      const nta = el(`nbt_${i}`);
      if (nta && nta.value.trim()) e.nbt = nta.value.trim();
      else if (r.nbt && r.nbt.trim()) e.nbt = r.nbt.trim();
      if (r.enchants && r.enchants.length) e.enchants = r.enchants;
    }
    entry.rewards.push(e);
  });
  return entry;
}
function msg(s, ok){ const m = el('formMsg'); m.className = ok?'ok':'err'; m.textContent = s; }

async function api(action, body){
  const f = new FormData(); f.append('action', action);
  Object.entries(body||{}).forEach(([k,v]) => f.append(k, v));
  const r = await fetch('api.php', {method:'POST', body:f});
  return r.json();
}
async function saveCode(){
  const code = el('code').value.trim();
  if(!code){ msg('请填写兑换码', false); return; }
  if(!validateEntry()) return;
  const entry = collectEntry();
  const body = {code, entry: JSON.stringify(entry)};
  if (editing) body.old_index = editing;
  const r = await api(editing ? 'update' : 'add', body);
  if (r.ok){
    msg((editing ? '已更新：'+r.code : '已新增：'+r.code) + '，正在刷新…', true);
    setTimeout(function(){ location.reload(true); }, 700);
    return;
  }
  else msg(r.error||'保存失败', false);
}
async function delCode(idx, show){
  if(!confirm('确定删除兑换码 '+show+' ？')) return;
  const r = await api('delete', {code: idx});
  if(r.ok){ msg('已删除', true); loadList(); } else msg(r.error||'删除失败', false);
}
async function editCode(idx){
  const d = await api('list');
  const e = d.codes[idx];
  if(!e) return;
  el('code').value = e.code || ''; el('name').value = e.name||''; el('desc').value = e.desc||'';
  // 限制模式：三选一（互斥）
  const mode = e.globalOnce ? 'globalOnce' : (e.once ? 'once' : 'none');
  const radio = document.querySelector(`input[name=limitMode][value="${mode}"]`);
  if (radio) radio.checked = true;
  rewards = (e.rewards||[]).map(r=>({...r}));
  renderRewards();
  editing = idx;
  el('saveBtn').textContent = '💾 保存修改';
  el('cancelBtn').classList.remove('hide');
  window.scrollTo({top:0, behavior:'smooth'});
}
function resetForm(){
  editing = null; rewards = [];
  ['code','name','desc'].forEach(id => el(id).value='');
  const radio = document.querySelector('input[name=limitMode][value="none"]');
  if (radio) radio.checked = true;
  renderRewards();
  el('saveBtn').textContent = '💾 保存';
  el('cancelBtn').classList.add('hide');
}

async function loadList(){
  const d = await api('list');
  if (!d.ok){ msg('读取失败：' + (d.error || '未知错误（可能未登录）'), false); return; }
  const t = el('tbody'); t.innerHTML = '';
  const codes = d.codes || {};
  el('cnt').textContent = Object.keys(codes).length;
  Object.keys(codes).sort().forEach(idx => {
    const e = codes[idx];
    const attrs = [];
    if (e.globalOnce) attrs.push('<span class="tag" style="color:#f5b83d">只能一次（全服）</span>');
    else if (e.once) attrs.push('<span class="tag" style="color:#4f8cff">每人一次</span>');
    else attrs.push('<span class="tag">不限</span>');
    const rw = (e.rewards||[]).map(r => `<span class="tag">${typeName(r.type)}</span>`).join('');
    const tr = document.createElement('tr');
    tr.innerHTML = `<td><code>${esc(e.code||'(解密失败)')}</code></td>
      <td><b>${esc(e.name||'')}</b><br><span class="muted">${esc(e.desc||'')}</span></td>
      <td>${rw||'<span class="muted">无奖励</span>'}</td>
      <td>${attrs.join('')}</td>
      <td><button class="btn-gray btn-sm" type="button" data-edit="${idx}">编辑</button>
          <button class="btn-danger btn-sm" type="button" data-del="${idx}" data-show="${esc(e.code||'')}">删除</button></td>`;
    t.appendChild(tr);
  });
  t.querySelectorAll('button[data-edit]').forEach(b => b.onclick = () => editCode(b.getAttribute('data-edit')));
  t.querySelectorAll('button[data-del]').forEach(b => b.onclick = () => delCode(b.getAttribute('data-del'), b.getAttribute('data-show')));
}

// 事件绑定
el('addRewardBtn').addEventListener('click', addReward);
el('saveBtn').addEventListener('click', saveCode);
el('cancelBtn').addEventListener('click', resetForm);

addReward();
loadList();
</script>
<?php endif; ?>
</body>
</html>
