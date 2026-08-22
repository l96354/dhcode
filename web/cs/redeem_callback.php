<?php
// ============================================================
// 兑换码 全局一次性码 删除回调（供插件调用）
// 路径: 你的站点目录/cs/api/redeem_callback.php
// 用法: GET ?token=xxx&code=HMAC索引
//   插件侧已用 HMAC(K,码) 计算出索引并传入，这里直接按索引删除，
//   因此回调参数本身不含明文兑换码，进一步降低泄露风险。
// ============================================================
require_once __DIR__ . '/../../bm_auth.php';
require_once __DIR__ . '/../_lib.php';
require_once __DIR__ . '/../crypto.php';

dh_security_headers();

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$idx   = strtolower(trim($_GET['code'] ?? ($_POST['code'] ?? '')));

if (!hash_equals(DH_CALLBACK_TOKEN, (string)$token)) {
    dh_out(403, ['ok' => false, 'error' => 'token 无效']);
}
if ($idx === '') {
    dh_out(400, ['ok' => false, 'error' => 'code 为空']);
}
// 兼容：若传入的是明文码，也接受（统一转成索引）
if (!preg_match('/^[0-9a-f]{64}$/', $idx)) {
    $idx = dh_index($idx);
}

$data = dh_load();
if (isset($data['codes'][$idx])) {
    unset($data['codes'][$idx]);
    if (!dh_save($data)) {
        dh_out(500, ['ok' => false, 'error' => '写入失败']);
    }
    dh_out(200, ['ok' => true, 'deleted' => $idx]);
}
dh_out(200, ['ok' => true, 'deleted' => null, 'note' => '码不存在或已删除']);
