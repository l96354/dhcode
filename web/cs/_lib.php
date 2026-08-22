<?php
// ============================================================
// 兑换码 数据文件操作公共函数
// 路径: 你的站点目录/cs/_lib.php
// 说明: 数据文件路径与安全参数均从 cs/config.php 读取，不硬编码。
// ============================================================

/** 集中配置（文件路径 / 安全参数） */
function dh_config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
    }
    return $cfg;
}

/** 兑换码数据文件路径（由配置决定，可改绝对路径） */
function dh_data_file(): string {
    return dh_config()['data_file'];
}

/** 输出统一安全响应头（管理页面 / API 用） */
function dh_security_headers(): void {
    foreach ((dh_config()['security_headers'] ?? []) as $k => $v) {
        @header($k . ': ' . $v);
    }
}

/** 读取全部兑换码（原始安全结构，键为 HMAC 索引） */
function dh_load(): array {
    $file = dh_data_file();
    if (!file_exists($file)) return ['version' => 2, 'codes' => []];
    $raw = file_get_contents($file);
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['codes']) || !is_array($data['codes'])) {
        return ['version' => 2, 'codes' => []];
    }
    if (!isset($data['version'])) $data['version'] = 2;
    return $data;
}

/** 写回全部兑换码，成功返回 true */
function dh_save(array $data): bool {
    $file = dh_data_file();
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($file, $json) === false) {
        return false;
    }
    @chmod($file, 0666);
    return true;
}

/** 统一 JSON 输出 */
function dh_out(int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
