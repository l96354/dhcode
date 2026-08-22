# 配置字段详解（Config Reference）

DHCode 只有 **三处配置文件** 需要改，且密钥/令牌必须**两两一致**。下面逐项说明。

---

## 1. 网页端密钥：`web/cs/key.php`

| 字段 | 必改 | 说明 |
|---|---|---|
| `DH_SECRET` | ✅ | 共享密钥 K。必须与插件 `config.yml` 的 `security.secret` **完全一致**。建议 ≥32 位随机 ASCII 串，例如 `'Xy7kPq2Lm9WsR4Tv8Nc1Bd3Hf6Jg0Ku5'`。 |

> `key.php` 直接被浏览器访问会返回 403，不会泄露。

---

## 2. 网页端配置：`web/cs/config.php`

```php
return [
    'data_file'   => dirname(__DIR__) . '/config.json', // 兑换码数据文件位置（可改绝对路径）
    'key_file'    => __DIR__ . '/key.php',              // 密钥文件位置（一般无需改）
    'admin_pass'  => 'CHANGE_ME_管理后台密码',            // ✅ 改成你自己的后台密码
    'callback_token' => 'CHANGE_ME_回调令牌',            // ✅ 改成随机串，须 = 插件 delete-token
    'login_max_fail'    => 5,      // 连续失败几次锁定
    'login_lock_seconds' => 300,   // 锁定秒数
    'security_headers' => [ ... ], // 安全响应头，一般无需改
];
```

| 字段 | 必改 | 说明 |
|---|---|---|
| `data_file` | ⚠️ | 兑换码数据（config.json）路径。默认在网站根目录，权限需可写（建议 0666）。 |
| `admin_pass` | ✅ | 管理后台登录密码。 |
| `callback_token` | ✅ | 全局码删除回调令牌，**必须 = 插件 `delete-token`**。 |
| 其余 | ❌ | 防爆破阈值、安全头等，按需调。 |

> 注意：`config.php` 也是 403 直访防护。改完保存即可，**不要**把它改名或移动（其它 PHP 按相对路径 require 它）。

---

## 3. 插件配置：`plugins/DHCode/config.yml`

首次启动自动生成。完整模板（已带注释与占位符）：

```yaml
# 网页端服务主机根目录（任意前缀均可）
web-root: "https://你的域名/dh"        # ← 改成你的网页后台根目录

config-path: "/config.json"            # 兑换码数据文件（相对 web-root）
delete-path: "/cs/api/redeem_callback.php"  # 全局码删除回调（相对 web-root）

delete-token: "CHANGE_ME_与网页端一致的令牌"   # ✅ = web config.php 的 callback_token
cache-seconds: 15                      # 配置缓存秒数
timeout-ms: 8000                       # 连接/读取超时（毫秒）

security:
  secret: "CHANGE_ME_填写与网页端一致的共享密钥"  # ✅ = web key.php 的 DH_SECRET

used-data-file: ""                     # 留空=默认 plugins/DHCode/used.json；可填绝对路径
```

| 字段 | 必改 | 说明 |
|---|---|---|
| `web-root` | ✅ | 网页后台根 URL，例如 `https://example.com/dh`。插件按 `web-root + 相对路径` 拼地址，换域名/迁移只改这一行。 |
| `config-path` | ❌ | 数据文件相对路径（基于 web-root）。 |
| `delete-path` | ❌ | 全局码删除回调相对路径。 |
| `delete-token` | ✅ | 回调令牌，**= web `callback_token`**。 |
| `security.secret` | ✅ | 共享密钥 K，**= web `DH_SECRET`**。 |
| `cache-seconds` / `timeout-ms` | ❌ | 性能/网络参数，按需调。 |
| `used-data-file` | ❌ | 已兑换记录文件；留空用默认。 |

---

## 一致性速查

```
web/cs/key.php        DH_SECRET
        │
        └──────── 必须相等 ────────►  plugins/DHCode/config.yml  security.secret

web/cs/config.php     callback_token
        │
        └──────── 必须相等 ────────►  plugins/DHCode/config.yml  delete-token
```

只要上面两组相等、网页 `data_file` 可写，系统即可正常工作。
