<?php
// ============================================================
// 兑换码 共享密钥文件（全站唯一需要保密的文件）
// 路径: 你的站点目录/cs/key.php
//
// ★ 只需修改下面这一行 DH_SECRET：
//   - 必须与插件 config.yml 的 security.secret 完全一致
//   - 建议 ≥32 位随机串，纯 ASCII（避免中文/特殊字符导致两端字节不一致）
//   - 例：  DH_SECRET('Xy7kPq2Lm9WsR4Tv8Nc1Bd3Hf6Jg0Ku5')
//   改完即生效，无需重启。直接访问本文件会返回 403。
// ============================================================

if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'key.php' && php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

// 共享密钥 K（与插件端 security.secret 完全一致）
define('DH_SECRET', 'CHANGE_ME_请改成与插件config_yml的security_secret完全一致的随机密钥');

return DH_SECRET;
