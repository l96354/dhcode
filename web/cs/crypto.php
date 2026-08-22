<?php
// ============================================================
// 兑换码核心加解密（与插件端 DHCode.java 完全互通）
// 路径: 你的站点目录/cs/crypto.php
//
// 算法（标准、无私有实现，任何人可审计/复刻）：
//   • 索引：HMAC-SHA256(K, 规范化码)  → 64 位十六进制
//           规范化 = trim + 转大写（与插件 normalize 一致）
//   • 密文：AES-256-CBC，密钥 = SHA-256(K)，IV 随机 16 字节
//           存储格式 = base64( IV(16B) || 密文 )
//
// 密钥 K 只来自 cs/key.php（DH_SECRET），本文件不硬编码任何密钥。
// config.json 只保存「HMAC 索引 + AES 密文」，泄露也无法还原内容。
// ============================================================

require_once __DIR__ . '/key.php';

// 规范化兑换码：去空白并转大写（与插件端一致）
// 优先用 mbstring 处理多字节；若环境未装 mbstring 则回退 strtoupper（ASCII 等价）
function dh_norm(string $code): string {
    $u = function_exists('mb_strtoupper') ? mb_strtoupper($code, 'UTF-8') : strtoupper($code);
    return trim($u);
}

// HMAC-SHA256(K, 规范化码) → 64 位十六进制索引（config.json 的键）
function dh_index(string $code): string {
    return hash_hmac('sha256', dh_norm($code), DH_SECRET);
}

// AES-256-CBC 加密，返回 base64( IV(16B) || 密文 )
function dh_aes_encrypt(string $plain): string {
    $key = hash('sha256', DH_SECRET, true);   // 32 字节 = AES-256 密钥
    $iv  = random_bytes(16);
    $ct  = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($ct === false) return '';
    return base64_encode($iv . $ct);
}

// AES-256-CBC 解密，输入 base64( IV(16B) || 密文 )
function dh_aes_decrypt(string $blob): string {
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 17) return '';
    $iv = substr($raw, 0, 16);
    $ct = substr($raw, 16);
    $key = hash('sha256', DH_SECRET, true);
    $pt  = openssl_decrypt($ct, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return $pt === false ? '' : $pt;
}

// 加密奖励数组 → 密文（data 字段）
function dh_encrypt_data(array $rewards): string {
    return dh_aes_encrypt(json_encode($rewards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// 解密奖励密文 → 数组
function dh_decrypt_data(string $blob): array {
    $pt = dh_aes_decrypt($blob);
    if ($pt === '') return [];
    $a = json_decode($pt, true);
    return is_array($a) ? $a : [];
}

// 编码一条记录（管理端新增/编辑时用）：返回写入 config.json 的安全结构
function dh_encode_entry(array $entry, string $code): array {
    return [
        'name'       => $entry['name'] ?? '',
        'desc'       => $entry['desc'] ?? '',
        'once'       => (bool)($entry['once'] ?? false),
        'globalOnce' => (bool)($entry['globalOnce'] ?? false),
        'code'       => dh_aes_encrypt(dh_norm($code)),   // 加密明文码（仅管理端可解密展示）
        'data'       => dh_encrypt_data($entry['rewards'] ?? []),
    ];
}

// 解码一条存储记录：返回可展示结构（含明文码 _code 与奖励 rewards）
function dh_decode_entry(array $stored): array {
    $out = $stored;
    $out['_code'] = (isset($stored['code']) && $stored['code'] !== '') ? dh_aes_decrypt($stored['code']) : '';
    $out['rewards'] = (isset($stored['data']) && $stored['data'] !== '') ? dh_decrypt_data($stored['data']) : [];
    return $out;
}
