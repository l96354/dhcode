# DHCode · 兑换码系统

一个把「网页后台」当作兑换码服务主机的 Minecraft 兑换码插件。后台生成兑换码（HMAC 索引 + AES 加密奖励），玩家在游戏内 `/dh <码>` 兑换，插件实时从你的网站拉取并发放。

> 针对你的 **26.1.2 Paper**（基于 Minecraft 1.21）构建。标准算法（HMAC-SHA256 + AES-256-CBC），**密钥只在配置文件里填，不在代码里**。

---

## ✅ 三步开始

### 1. 放网页后台
把 `web/` 整个目录上传到你的网站（任意子目录，如 `https://你的域名/dh`）。

### 2. 放插件
把 `builds/` 里对应版本的 `DHCode.jar` 丢进服务端的 `plugins/`，启动一次服务器。
插件会自动生成 `plugins/DHCode/config.yml`（若已存在则跳过）。

### 3. 填配置（仅此一步需要改文件）
三处密钥/路径必须**两两一致**：

| 文件 | 改什么 | 说明 |
|---|---|---|
| `web/cs/key.php` | `DH_SECRET` | 共享密钥 K，**必须**与插件一致 |
| `web/cs/config.php` | `admin_pass`、`callback_token`、`data_file` | 后台密码、回调令牌、数据文件路径 |
| `plugins/DHCode/config.yml` | `web-root`、`security.secret`、`delete-token` | 网站根目录、共享密钥 K、回调令牌 |

- `key.php` 的 `DH_SECRET` ＝ `config.yml` 的 `security.secret`
- `config.php` 的 `callback_token` ＝ `config.yml` 的 `delete-token`
- 三者填好即可用，无需碰任何源码。

详细字段说明见 [config-guide.md](config-guide.md)。

---

## 📦 预编译构建

`builds/` 目录已提供针对 **26.1.2 Paper** 编译的 jar（同时复制为通用 `DHCode.jar`，二者内容一致）：

- `DHCode-26.1.2.jar` — 用于 26.1.2 Paper
- `DHCode.jar` — 通用（与 26.1.2 同构建）

> 插件只用到稳定 Bukkit API 与 1.20.5+ 数据组件 NBT，因此该构建在你的 26.1.2 服务端可正常运行。

## 🔨 自己编译（仅 26.1.2）

```bash
cd plugin
# 需要 JDK 21 + Maven，且能访问 repo.papermc.io
bash build.sh
# 产物在 ../builds/DHCode-26.1.2.jar
```

也可直接 `mvn -Dpaper.version=26.1.2.build.74-stable package`。

---

## 🔒 安全模型

- `config.json` 只存 **HMAC-SHA256(K, 码) 索引** + **AES-256-CBC 加密的奖励**，泄露也无法还原兑换码或奖励。
- 共享密钥 K **仅**存在于 `key.php` / `config.yml`，仓库内为占位符 `CHANGE_ME_...`。
- 全局一次性码兑换后，插件用 **POST** 回调网站删除（令牌与索引在请求体，不进 URL/日志）。
- `key.php` / `config.php` 直接访问返回 403，不会被浏览器读取。

## 📁 目录结构

```
DHCode/
├── plugin/            # 插件源码 + pom.xml + build.sh
│   └── src/cc/ysee/dh/
├── web/               # 网页后台（PHP）
│   ├── admin/         # 管理界面
│   ├── cs/            # 核心：crypto.php / key.php / config.php / 回调
│   └── bm_auth.php
├── builds/            # 多版本预编译 jar
├── config-guide.md    # 配置字段详解
├── README.md / README.en.md
└── LICENSE
```

## 📜 许可

MIT — 详见 [LICENSE](LICENSE)。
