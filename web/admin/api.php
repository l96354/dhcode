<?php
// ============================================================
// 兑换码 管理 API（需管理员会话）
// 路径: 你的站点目录/admin/api.php
// 动作: list / add / update / delete
//
// 安全说明：config.json 中只存储 HMAC 索引键与 AES 密文，
// 即便配置文件泄露，攻击者也无法还原兑换码或奖励内容。
// 解密后的明文仅在此管理会话中返回给已登录管理员。
// ============================================================
require_once __DIR__ . '/../bm_auth.php';
require_once __DIR__ . '/../cs/_lib.php';
require_once __DIR__ . '/../cs/crypto.php';

dh_security_headers();

// 防爆破：IP 被锁定期间，连 API 也不放行
if (dh_lock_remaining() > 0) {
    dh_out(429, ['ok' => false, 'error' => '请求过于频繁，请稍后再试']);
}

if (!dh_is_logged_in()) {
    dh_out(403, ['ok' => false, 'error' => '未登录']);
}

$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$data = dh_load();

switch ($action) {

    case 'list':
        $out = [];
        foreach ($data['codes'] as $idx => $stored) {
            $dec = dh_decode_entry($stored);
            $dec['_index'] = $idx;          // HMAC 索引，删除/编辑用
            $out[$idx] = $dec;
        }
        dh_out(200, ['ok' => true, 'codes' => $out]);
        break;

    case 'add':
        $code = trim($_POST['code'] ?? '');
        $entry = json_decode($_POST['entry'] ?? '{}', true);
        if ($code === '') dh_out(400, ['ok' => false, 'error' => '兑换码不能为空']);
        if (!is_array($entry)) dh_out(400, ['ok' => false, 'error' => '奖励数据格式错误']);
        $idx = dh_index($code);
        if (isset($data['codes'][$idx])) dh_out(400, ['ok' => false, 'error' => '兑换码已存在: ' . dh_norm($code)]);
        $data['codes'][$idx] = dh_encode_entry($entry, $code);
        if (!dh_save($data)) dh_out(500, ['ok' => false, 'error' => '写入 config.json 失败（检查权限）']);
        dh_out(200, ['ok' => true, 'code' => dh_norm($code)]);
        break;

    case 'update':
        // 优先使用前端传来的 HMAC 索引；否则用明文旧码推导
        $oldRaw = trim($_POST['old_index'] ?? (trim($_POST['old_code'] ?? '')));
        $oldIdx = (preg_match('/^[0-9a-f]{64}$/', $oldRaw)) ? $oldRaw : dh_index($oldRaw);
        $code   = trim($_POST['code'] ?? '');
        $entry  = json_decode($_POST['entry'] ?? '{}', true);
        if ($oldIdx === '' || $code === '') dh_out(400, ['ok' => false, 'error' => '参数不完整']);
        if (!is_array($entry)) dh_out(400, ['ok' => false, 'error' => '奖励数据格式错误']);
        if (!isset($data['codes'][$oldIdx])) dh_out(404, ['ok' => false, 'error' => '原兑换码不存在']);
        $newIdx = dh_index($code);
        if ($oldIdx !== $newIdx && isset($data['codes'][$newIdx])) dh_out(400, ['ok' => false, 'error' => '新兑换码已存在']);
        unset($data['codes'][$oldIdx]);
        $data['codes'][$newIdx] = dh_encode_entry($entry, $code);
        if (!dh_save($data)) dh_out(500, ['ok' => false, 'error' => '写入 config.json 失败（检查权限）']);
        dh_out(200, ['ok' => true, 'code' => dh_norm($code)]);
        break;

    case 'delete':
        $raw = trim($_POST['code'] ?? '');
        // 兼容两种传参：64 位十六进制 = HMAC 索引；否则按明文码推导索引
        $idx = (preg_match('/^[0-9a-f]{64}$/', $raw)) ? $raw : dh_index($raw);
        if ($idx === '') dh_out(400, ['ok' => false, 'error' => '参数不完整']);
        if (!isset($data['codes'][$idx])) dh_out(404, ['ok' => false, 'error' => '兑换码不存在']);
        unset($data['codes'][$idx]);
        if (!dh_save($data)) dh_out(500, ['ok' => false, 'error' => '写入 config.json 失败（检查权限）']);
        dh_out(200, ['ok' => true, 'code' => dh_norm($raw)]);
        break;

    default:
        dh_out(400, ['ok' => false, 'error' => '未知动作']);
}
