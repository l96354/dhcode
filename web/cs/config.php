<?php
// ============================================================
// 兑换码系统 集中配置文件（所有路径与安全参数统一在此调整）
// 路径: 你的站点目录/cs/config.php
//
// 说明：本文件集中管理「文件路径」与「安全参数」，各 PHP 均通过
//       require 本文件读取配置，代码中不再硬编码任何路径/密钥。
//       本文件直接访问会返回 403（见下方防护），不会被浏览器读取。
// ============================================================

// ---- 防直接访问：仅允许被 require，直接 HTTP 访问一律 403 ----
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'config.php' && php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

return [
    // ============ 文件路径（可改为绝对路径） ============

    // 兑换码数据文件（config.json）所在位置
    'data_file' => dirname(__DIR__) . '/config.json',

    // 共享密钥文件位置（保存 HMAC+AES 密钥 K）
    'key_file'  => __DIR__ . '/key.php',

    // ============ 管理端凭据 ============

    // 管理员后台密码
    'admin_pass' => 'CHANGE_ME_管理后台密码',

    // 全局码回调令牌（插件 config.yml 的 delete-token 必须一致）
    'callback_token' => 'CHANGE_ME_回调令牌',

    // ============ 安全防护参数 ============

    // 登录防爆破：连续失败 N 次后锁定（按 IP 记录）
    'login_max_fail'   => 5,
    // 锁定时间（秒）
    'login_lock_seconds' => 300,

    // 安全响应头（管理页面 / API 统一输出）
    'security_headers' => [
        'X-Frame-Options'       => 'DENY',           // 禁止被 iframe 嵌入（防点击劫持）
        'X-Content-Type-Options'=> 'nosniff',        // 禁止 MIME 嗅探
        'Referrer-Policy'       => 'no-referrer',    // 不泄露来源
        'X-XSS-Protection'      => '1; mode=block',
    ],
];
