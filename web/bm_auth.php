<?php
// ============================================================
// 兑换码系统 登录态与防爆破
// 路径: 你的站点目录/bm_auth.php
// 说明: 管理员密码 / 回调 Token 均从 cs/config.php 读取，不硬编码。
// ============================================================

require_once __DIR__ . '/cs/_lib.php';
$__dh_auth_cfg = dh_config();
define('DH_ADMIN_PASS', $__dh_auth_cfg['admin_pass']);
define('DH_CALLBACK_TOKEN', $__dh_auth_cfg['callback_token']);
$__dh_auth_lock_file = dirname(dh_data_file()) . '/.login_lock.json';

// ============================================================
// 登录态：使用“签名 Cookie”代替 PHP 服务端 Session。
// 原因：共享主机 session 文件经常不可写，且经面板/反代后浏览器会按
//       SameSite / 第三方 Cookie 策略丢弃 Session Cookie，导致
//       “curl 能登录、浏览器登录态保不住、API 全部 403 未登录”。
// 这里改为：登录成功后下发一个 HMAC 签名的 dh_admin Cookie，
// 不依赖服务器写 session 文件；显式 SameSite=None;Secure 兼容
// 各种浏览器/iframe 环境。Cookie 值无法被伪造（需知 DH_ADMIN_PASS）。
// ============================================================
function dh_auth_token(): string {
    return hash_hmac('sha256', 'dh-admin-auth', DH_ADMIN_PASS . '|' . DH_CALLBACK_TOKEN);
}
function dh_is_logged_in(): bool {
    return ($_COOKIE['dh_admin'] ?? '') === dh_auth_token();
}
function dh_set_login(): void {
    setcookie('dh_admin', dh_auth_token(), [
        'expires'  => time() + 86400 * 30,
        'path'     => '/',
        'secure'   => true,   // hub 为 HTTPS
        'httponly' => true,
        'samesite' => 'None'  // 兼容 iframe / 跨站 / 浏览器严格隐私策略
    ]);
}
function dh_clear_login(): void {
    setcookie('dh_admin', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'None'
    ]);
}

// ============================================================
// 登录防爆破：按 IP 记录连续失败次数，超过阈值锁定一段时间。
// 使用文件存储（兼容共享主机无 session / 无 redis 环境）。
// ============================================================
function dh_lock_file(): string {
    global $__dh_auth_lock_file;
    return $__dh_auth_lock_file;
}
function dh_client_ip(): string {
    // 经面板/反代时取 X-Forwarded-For 第一个 IP（注意：仅作限速参考）
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
        if ($ip !== '') return $ip;
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}
function dh_read_lock(): array {
    $f = dh_lock_file();
    if (!file_exists($f)) return [];
    $d = json_decode((string)@file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function dh_write_lock(array $d): void {
    $f = dh_lock_file();
    @file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($f, 0666);
}
/** 当前 IP 是否已被锁定；返回剩余秒数（0=未锁定） */
function dh_lock_remaining(): int {
    $ip = dh_client_ip();
    $d = dh_read_lock();
    if (empty($d[$ip]['until'])) return 0;
    $left = (int)$d[$ip]['until'] - time();
    if ($left <= 0) {
        unset($d[$ip]);
        dh_write_lock($d);
        return 0;
    }
    return $left;
}
/** 记录一次失败 */
function dh_register_fail(): void {
    $cfg = dh_config();
    $max = (int)($cfg['login_max_fail'] ?? 5);
    $lock = (int)($cfg['login_lock_seconds'] ?? 300);
    $ip = dh_client_ip();
    $d = dh_read_lock();
    $cur = (int)($d[$ip]['fail'] ?? 0) + 1;
    if ($cur >= $max) {
        $d[$ip] = ['fail' => 0, 'until' => time() + $lock];
    } else {
        $d[$ip] = ['fail' => $cur, 'until' => 0];
    }
    dh_write_lock($d);
}
/** 登录成功清除失败记录 */
function dh_clear_fail(): void {
    $ip = dh_client_ip();
    $d = dh_read_lock();
    unset($d[$ip]);
    dh_write_lock($d);
}
