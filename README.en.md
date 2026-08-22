# DHCode · Redeem Code System

A Minecraft redeem-code plugin that treats your **web backend** as the code service host. The admin panel mints codes (HMAC index + AES-encrypted rewards); players run `/dh <code>` in-game and the plugin fetches and grants rewards live from your site.

> Built for your **26.1.2 Paper** (based on Minecraft 1.21). Standard crypto (HMAC-SHA256 + AES-256-CBC). **The secret lives only in config files, never in code.**

---

## ✅ Get started in 3 steps

### 1. Deploy the web panel
Upload the whole `web/` folder to your site (any sub-path, e.g. `https://yourdomain/dh`).

### 2. Install the plugin
Drop the right `DHCode.jar` from `builds/` into your server's `plugins/`, then start once. The plugin auto-generates `plugins/DHCode/config.yml`.

### 3. Fill in config (the only editing step)
Three secrets/paths must **match in pairs**:

| File | Edit | Notes |
|---|---|---|
| `web/cs/key.php` | `DH_SECRET` | Shared secret K — must equal the plugin's |
| `web/cs/config.php` | `admin_pass`, `callback_token`, `data_file` | Panel password, callback token, data file path |
| `plugins/DHCode/config.yml` | `web-root`, `security.secret`, `delete-token` | Site root, shared secret K, callback token |

- `key.php` `DH_SECRET` = `config.yml` `security.secret`
- `config.php` `callback_token` = `config.yml` `delete-token`

That's it — no source editing required. See [config-guide.md](config-guide.md) for every field.

---

## 📦 Prebuilt builds

`builds/` ships a jar compiled for **26.1.2 Paper** (also copied as the universal `DHCode.jar`; both are identical):

- `DHCode-26.1.2.jar` — for 26.1.2 Paper
- `DHCode.jar` — universal (same build as 26.1.2)

> The plugin uses only stable Bukkit API + 1.20.5+ data-component NBT, so this build runs on your 26.1.2 server.

## 🔨 Build yourself (26.1.2 only)

```bash
cd plugin
# Needs JDK 21 + Maven with access to repo.papermc.io
bash build.sh
# Output: ../builds/DHCode-26.1.2.jar
```

Or simply `mvn -Dpaper.version=26.1.2.build.74-stable package`.

---

## 🔒 Security model

- `config.json` stores only the **HMAC-SHA256(K, code) index** + **AES-256-CBC encrypted rewards** — a leak can't recover codes or rewards.
- The shared secret K exists **only** in `key.php` / `config.yml`; the repo ships `CHANGE_ME_...` placeholders.
- Global-once codes trigger a **POST** callback to delete after redeeming (token + index in the body, never in URL/logs).
- `key.php` / `config.php` return 403 when requested directly.

## 📜 License

MIT — see [LICENSE](LICENSE).
