package cc.ysee.dh;

import com.google.gson.Gson;
import net.kyori.adventure.text.Component;
import net.kyori.adventure.text.serializer.legacy.LegacyComponentSerializer;
import net.kyori.adventure.text.serializer.plain.PlainTextComponentSerializer;
import org.bukkit.Bukkit;
import org.bukkit.Material;
import org.bukkit.command.Command;
import org.bukkit.command.CommandExecutor;
import org.bukkit.command.CommandSender;
import org.bukkit.command.PluginCommand;
import org.bukkit.entity.Player;
import org.bukkit.event.EventHandler;
import org.bukkit.event.Listener;
import org.bukkit.event.inventory.InventoryClickEvent;
import org.bukkit.event.inventory.InventoryType;
import org.bukkit.event.inventory.PrepareAnvilEvent;
import org.bukkit.inventory.AnvilInventory;
import org.bukkit.inventory.Inventory;
import org.bukkit.inventory.ItemStack;
import org.bukkit.inventory.SlotType;
import org.bukkit.inventory.meta.ItemMeta;
import org.bukkit.plugin.java.JavaPlugin;

import javax.crypto.Cipher;
import javax.crypto.spec.IvParameterSpec;
import javax.crypto.spec.SecretKeySpec;
import javax.net.ssl.SSLContext;
import javax.net.ssl.TrustManager;
import javax.net.ssl.X509TrustManager;
import java.io.IOException;
import java.net.URI;
import java.net.URLEncoder;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.security.SecureRandom;
import java.security.cert.X509Certificate;
import java.time.Duration;
import java.util.Arrays;
import java.util.Base64;
import java.util.List;
import java.util.Map;
import java.util.UUID;
import java.util.concurrent.ConcurrentHashMap;
import java.util.regex.Pattern;
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;

public class DHCode extends JavaPlugin implements CommandExecutor, Listener {

    public static DHCode instance;
    private static final LegacyComponentSerializer LEGACY = LegacyComponentSerializer.legacyAmpersand();
    private static final PlainTextComponentSerializer PLAIN = PlainTextComponentSerializer.plainText();
    private static final Gson GSON = Gsons.GSON;
    // 用于剔除玩家输入里可能残留的颜色控制符（§x），避免 HMAC 对照失败
    private static final Pattern COLOR_CODE = Pattern.compile("\\u00A7[0-9a-fk-orA-FK-OR]");

    private UsedStore used;
    private final Map<UUID, Inventory> anvils = new ConcurrentHashMap<>();

    // 配置缓存
    private ConfigRoot cachedRoot;
    private long cachedAt = 0;

    // 共享密钥（必须与 Web 端 cs/key.php 完全一致）
    private String secret = "";

    public static void log(String msg) {
        instance.getLogger().info(msg);
    }

    @Override
    public void onEnable() {
        instance = this;
        saveDefaultConfig();
        secret = getConfig().getString("security.secret", "");
        if (secret == null || secret.isEmpty()) {
            log("⚠ 未配置 security.secret，兑换功能将不可用！请在 config.yml 设置与网站一致的密钥。");
        }
        used = new UsedStore(getDataFolder(), getConfig().getString("used-data-file", ""));
        ZhNames.load(this);
        getServer().getPluginManager().registerEvents(this, this);
        PluginCommand cmd = getCommand("dh");
        if (cmd != null) {
            cmd.setExecutor(this);
            cmd.setTabCompleter((sender, c, a, args) -> java.util.List.of("reload", "give"));
        }
        log("已启用，服务主机: " + getConfig().getString("web-root", "-")
                + " 数据: " + getConfig().getString("config-path", "-"));
    }

    @Override
    public void onDisable() {
        instance = null;
    }

    private static Component comp(String s) {
        return LEGACY.deserialize(s == null ? "" : s);
    }

    private void tell(CommandSender s, String msg) {
        s.sendMessage(comp(msg));
    }

    @Override
    public boolean onCommand(CommandSender sender, Command command, String label, String[] args) {
        if (args.length == 0) {
            if (sender instanceof Player p) {
                openGui(p);
            } else {
                tell(sender, "&c用法: /dh <兑换码>");
            }
            return true;
        }

        String first = args[0].toLowerCase();

        if (first.equals("reload")) {
            if (!sender.hasPermission("dh.reload")) { tell(sender, "&c无权限"); return true; }
            reloadConfig();
            secret = getConfig().getString("security.secret", "");
            tell(sender, "&a已重载本地配置");
            return true;
        }

        if (first.equals("debug")) {
            if (!sender.hasPermission("dh.reload")) { tell(sender, "&c无权限"); return true; }
            getServer().getScheduler().runTaskAsynchronously(this, () -> {
                try {
                    ConfigRoot root = fetchConfig();
                    int n = root == null || root.codes == null ? 0 : root.codes.size();
                    log("debug: 配置拉取成功，共 " + n + " 个码（键为 HMAC 索引）");
                    getServer().getScheduler().runTask(this, () ->
                            tell(sender, "&a配置拉取成功，共 " + n + " 个码。"));
                } catch (Exception ex) {
                    log("debug: 配置拉取失败: " + ex.getMessage());
                    getServer().getScheduler().runTask(this, () ->
                            tell(sender, "&c配置拉取失败: " + ex.getMessage()));
                }
            });
            return true;
        }

        if (first.equals("give")) {
            if (!sender.hasPermission("dh.give")) { tell(sender, "&c无权限"); return true; }
            if (args.length < 3) { tell(sender, "&c用法: /dh give <玩家> <兑换码>"); return true; }
            Player target = Bukkit.getPlayerExact(args[1]);
            if (target == null) { tell(sender, "&c玩家不在线: " + args[1]); return true; }
            redeem(target, args[2], sender);
            return true;
        }

        if (!(sender instanceof Player p)) {
            // 控制台/终端执行：查询模式——报告该码是否存在与奖励数量
            queryCode(sender, args[0]);
            return true;
        }
        redeem(p, args[0], p);
        return true;
    }

    // ===================== 终端查询 =====================

    /** 控制台查询：报告兑换码是否存在、有几个奖励（不执行发放） */
    private void queryCode(CommandSender sender, String code) {
        String idx = hmacIndex(code);
        if (idx.isEmpty()) { tell(sender, "&c兑换服务未正确配置（缺少密钥）"); return; }
        tell(sender, "&e正在查询兑换码…");
        getServer().getScheduler().runTaskAsynchronously(this, () -> {
            try {
                ConfigRoot root = fetchConfig();
                CodeEntry entry = lookup(root, code, idx);
                getServer().getScheduler().runTask(this, () -> {
                    if (entry == null) {
                        log("[查询] " + normalize(code) + " → 不存在或已失效");
                        tell(sender, "&c兑换码不存在或已失效: " + normalize(code));
                        return;
                    }
                    int n = (entry.rewards == null) ? 0 : entry.rewards.size();
                    log("[查询] " + normalize(code) + " → 存在，奖励 " + n + " 个"
                            + (entry.name.isEmpty() ? "" : "（" + entry.name + "）"));
                    tell(sender, "&a兑换码存在: " + normalize(code));
                    tell(sender, "&a名称: " + (entry.name.isEmpty() ? "（未命名）" : entry.name)
                            + " | 奖励数: " + n);
                    if (entry.once) tell(sender, "&7属性: 每人限兑一次");
                    if (entry.globalOnce) tell(sender, "&7属性: 全局仅一次（兑完自动删码）");
                    if (n > 0) {
                        StringBuilder sb = new StringBuilder("&7奖励明细: ");
                        for (Reward r : entry.rewards) {
                            sb.append(rewardSummary(r)).append("; ");
                        }
                        tell(sender, sb.toString().trim());
                    }
                });
            } catch (Exception ex) {
                log("查询兑换配置失败: " + ex.getMessage());
                getServer().getScheduler().runTask(this, () ->
                        tell(sender, "&c查询失败: " + ex.getMessage()));
            }
        });
    }

    /** 单条奖励摘要（终端显示用，物品/附魔显示中文名） */
    private String rewardSummary(Reward r) {
        try {
            switch (r.type == null ? "" : r.type) {
                case "item": {
                    String zhItem = ZhNames.item(r.material);
                    String nbtFlag = (r.nbt != null && !r.nbt.trim().isEmpty()) ? " [NBT]" : "";
                    String enchPart = "";
                    if (r.enchants != null && !r.enchants.isEmpty()) {
                        StringBuilder es = new StringBuilder(" 附魔[");
                        for (Map<String, Object> en : r.enchants) {
                            Object id = en.get("enchant");
                            Object lv = en.get("level");
                            if (id == null) continue;
                            es.append(ZhNames.enchant(String.valueOf(id)))
                              .append(lv instanceof Number ? " " + ((Number) lv).intValue() : "")
                              .append(",");
                        }
                        if (es.charAt(es.length() - 1) == ',') es.setLength(es.length() - 1);
                        es.append("]");
                        enchPart = es.toString();
                    }
                    return "&a物品×" + Math.max(1, r.amount) + " &7(" + zhItem + ")" + enchPart + nbtFlag;
                }
                case "command":return "&a命令 &7(" + (r.console ? "控制台" : "玩家") + ": " + r.run + ")";
                case "permission": return "&a权限 &7(" + r.node + ")";
                case "money":  return "&a金币 &7(" + r.money + ")";
                case "message":return "&a消息 &7(" + r.text + ")";
                default:       return "&a" + r.type;
            }
        } catch (Exception ex) {
            return "&a" + r.type;
        }
    }

    // ===================== 图形界面（铁砧输入兑换码） =====================

    private void openGui(Player p) {
        openAnvil(p);
    }

    private void openAnvil(Player p) {
        try {
            Inventory inv = Bukkit.createInventory(p, InventoryType.ANVIL);
            ItemStack paper = new ItemStack(Material.PAPER);
            ItemMeta meta = paper.getItemMeta();
            // 纯文本引导名（不含颜色代码，避免玩家删除不净导致 § 残留而兑换失败）
            meta.displayName(Component.text("在此输入兑换码"));
            meta.lore(java.util.List.of(Component.text("§7在上方输入框输入兑换码，再点右侧结果格取出")));
            paper.setItemMeta(meta);
            inv.setItem(0, paper);
            anvils.put(p.getUniqueId(), inv);
            p.openInventory(inv);
            p.sendMessage(comp("&e请在铁砧上方输入框输入兑换码，再点右侧结果格完成兑换"));
        } catch (Throwable t) {
            p.sendMessage(comp("&e图形界面打开失败，请直接输入 &b/dh <兑换码>"));
        }
    }

    @EventHandler
    public void onAnvilClick(InventoryClickEvent e) {
        if (!(e.getWhoClicked() instanceof Player p)) return;
        Inventory top = e.getView().getTopInventory();
        if (top == null || top.getType() != InventoryType.ANVIL) return;
        if (!anvils.containsKey(p.getUniqueId())) return;
        // 只处理结果格（slot 2 或 SlotType.RESULT）
        if (e.getRawSlot() != 2 && e.getSlotType() != SlotType.RESULT) return;
        // 读取玩家在铁砧输入框实际输入的文字（renameText），而非槽位物品显示名
        String code = stripColor(((AnvilInventory) top).renameText()).trim();
        if (code.isEmpty()) return;
        e.setCancelled(true);
        p.closeInventory();
        anvils.remove(p.getUniqueId());
        redeem(p, code, p);
    }

    /** 铁砧改名时把结果格设为玩家输入的名字——绕过 0 级经验无法取出结果的限制 */
    @EventHandler
    public void onAnvilPrepare(PrepareAnvilEvent e) {
        if (!(e.getView().getPlayer() instanceof Player p)) return;
        if (!anvils.containsKey(p.getUniqueId())) return;
        // 玩家输入的名字在铁砧输入框（renameText），不是槽位物品的显示名
        String name = stripColor(((AnvilInventory) e.getInventory()).renameText()).trim();
        if (name.isEmpty()) return; // 玩家尚未输入兑换码
        ItemStack result = new ItemStack(Material.PAPER);
        ItemMeta rm = result.getItemMeta();
        rm.displayName(Component.text(name));
        result.setItemMeta(rm);
        e.setResult(result); // 强制结果，无需经验
    }

    private String stripColor(String s) {
        return s == null ? "" : COLOR_CODE.matcher(s).replaceAll("");
    }

    // ===================== 加密 / 索引 =====================

    private String normalize(String code) {
        return code.trim().toUpperCase();
    }

    /** HMAC-SHA256(K, 码) 的十六进制索引，与 Web 端 hash_hmac 一致 */
    private String hmacIndex(String code) {
        if (secret.isEmpty()) return "";
        try {
            Mac mac = Mac.getInstance("HmacSHA256");
            mac.init(new SecretKeySpec(secret.getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
            byte[] raw = mac.doFinal(normalize(code).getBytes(StandardCharsets.UTF_8));
            StringBuilder sb = new StringBuilder();
            for (byte b : raw) sb.append(String.format("%02x", b));
            return sb.toString();
        } catch (Exception e) {
            return "";
        }
    }

    /** AES-256-CBC 解密，输入 base64(IV(16B)||密文)，与 Web 端 openssl 互通 */
    private List<Reward> decryptData(String blob) throws Exception {
        byte[] raw = Base64.getDecoder().decode(blob);
        if (raw.length < 17) throw new IOException("密文过短");
        byte[] iv = Arrays.copyOfRange(raw, 0, 16);
        byte[] ct = Arrays.copyOfRange(raw, 16, raw.length);
        byte[] key = MessageDigest.getInstance("SHA-256").digest(secret.getBytes(StandardCharsets.UTF_8));
        Cipher c = Cipher.getInstance("AES/CBC/PKCS5Padding");
        c.init(Cipher.DECRYPT_MODE, new SecretKeySpec(key, "AES"), new IvParameterSpec(iv));
        byte[] pt = c.doFinal(ct);
        Reward[] arr = GSON.fromJson(new String(pt, StandardCharsets.UTF_8), Reward[].class);
        return Arrays.asList(arr == null ? new Reward[0] : arr);
    }

    // ===================== 兑换核心 =====================

    private void redeem(Player p, String code, CommandSender feedback) {
        String idx = hmacIndex(code);
        if (idx.isEmpty()) { tell(feedback, "&c兑换服务未正确配置（缺少密钥）"); return; }

        CodeEntry cached = findInCache(idx);
        if (cached != null) {
            doRedeem(p, idx, cached, feedback);
            return;
        }

        tell(feedback, "&e正在验证兑换码…");
        getServer().getScheduler().runTaskAsynchronously(this, () -> {
            try {
                ConfigRoot root = fetchConfig();
                CodeEntry entry = lookup(root, code, idx);
                getServer().getScheduler().runTask(this, () -> {
                    if (entry == null) {
                        tell(feedback, "&c兑换码无效或已失效");
                    } else {
                        doRedeem(p, idx, entry, feedback);
                    }
                });
            } catch (Exception ex) {
                log("拉取兑换配置失败: " + ex.getMessage());
                getServer().getScheduler().runTask(this, () ->
                        tell(feedback, "&c兑换服务暂时不可用，请稍后再试"));
            }
        });
    }

    private void doRedeem(Player p, String idx, CodeEntry entry, CommandSender feedback) {
        if (used.has(p.getUniqueId(), idx)) {
            tell(feedback, "&c你已兑换过该码");
            return;
        }
        RewardApplier.apply(p, entry);
        used.add(p.getUniqueId(), idx);
        if (entry.globalOnce) {
            // 异步回调删除全局码，防止他人再兑（web-root + delete-path 拼接）
            final String token = getConfig().getString("delete-token", "");
            final String api = webUrl(getConfig().getString("delete-path", "/cs/api/redeem_callback.php"));
            if (!token.isEmpty() && api.contains("://")) {
                getServer().getScheduler().runTaskAsynchronously(this, () -> {
                    try {
                        notifyGlobalRedeem(api, token, idx);
                    } catch (Exception ex) {
                        log("全局码删除回调失败: " + ex.getMessage());
                    }
                });
            }
        }
        tell(feedback, "&a兑换成功！");
    }

    private CodeEntry findInCache(String idx) {
        ConfigRoot root = cachedRoot;
        if (root == null) return null;
        if (System.currentTimeMillis() - cachedAt > getConfig().getInt("cache-seconds", 15) * 1000L) return null;
        return lookup(root, null, idx);
    }

    /** 按 HMAC 索引查找；并解密奖励数据 */
    private CodeEntry lookup(ConfigRoot root, String rawCode, String idx) {
        if (root == null || root.codes == null) return null;
        CodeEntry entry = root.codes.get(idx);
        if (entry == null && rawCode != null) {
            // 兼容旧版（明文键）配置
            entry = root.codes.get(normalize(rawCode));
        }
        if (entry == null) return null;
        if (entry.rewards == null && entry.data != null && !entry.data.isEmpty()) {
            try {
                entry.rewards = decryptData(entry.data);
            } catch (Exception e) {
                log("奖励解密失败: " + e.getMessage());
                return null;
            }
        }
        return entry;
    }

    // ===================== HTTP =====================

    /** 由配置拼出完整 URL：web-root（服务主机根目录）+ 相对路径 */
    private String webUrl(String relPath) {
        String root = getConfig().getString("web-root", "");
        if (root == null || root.trim().isEmpty()) {
            root = getConfig().getString("config-url", "");
            // 兼容旧配置：直接给完整地址
            if (root.contains("://")) return root;
        }
        root = root.trim().replaceAll("/+$", "");
        String p = (relPath == null ? "" : relPath.trim());
        if (!p.startsWith("/")) p = "/" + p;
        return root + p;
    }

    private ConfigRoot fetchConfig() throws Exception {
        long ttl = getConfig().getInt("cache-seconds", 15) * 1000L;
        if (cachedRoot != null && System.currentTimeMillis() - cachedAt < ttl) {
            return cachedRoot;
        }
        // 数据地址：web-root + config-path（相对路径），不硬编码
        String url = webUrl(getConfig().getString("config-path", "/config.json"));
        if (url == null || url.trim().isEmpty() || !url.contains("://")) {
            throw new IllegalStateException("config.yml 未正确配置 web-root/config-path（数据文件地址）");
        }
        int timeout = getConfig().getInt("timeout-ms", 8000);
        HttpClient client = HttpClient.newBuilder()
                .connectTimeout(Duration.ofMillis(timeout))
                .followRedirects(HttpClient.Redirect.NORMAL)
                .sslContext(trustAll())
                .build();
        HttpRequest req = HttpRequest.newBuilder()
                .uri(URI.create(url))
                .timeout(Duration.ofMillis(timeout))
                .GET()
                .build();
        HttpResponse<String> resp = client.send(req, HttpResponse.BodyHandlers.ofString());
        if (resp.statusCode() != 200) throw new IOException("HTTP " + resp.statusCode());
        ConfigRoot root = GSON.fromJson(resp.body(), ConfigRoot.class);
        cachedRoot = root;
        cachedAt = System.currentTimeMillis();
        return root;
    }

    private void notifyGlobalRedeem(String api, String token, String idx) throws Exception {
        int timeout = getConfig().getInt("timeout-ms", 8000);
        HttpClient client = HttpClient.newBuilder()
                .connectTimeout(Duration.ofMillis(timeout))
                .followRedirects(HttpClient.Redirect.NORMAL)
                .sslContext(trustAll())
                .build();
        // 用 POST 表单提交（token 与索引不进 URL，避免明文出现在日志/历史）
        HttpRequest req = HttpRequest.newBuilder()
                .uri(URI.create(api))
                .timeout(Duration.ofMillis(timeout))
                .header("Content-Type", "application/x-www-form-urlencoded")
                .POST(HttpRequest.BodyPublishers.ofString(
                        "token=" + URLEncoder.encode(token, "UTF-8")
                        + "&code=" + URLEncoder.encode(idx, "UTF-8")))
                .build();
        HttpResponse<String> resp = client.send(req, HttpResponse.BodyHandlers.ofString());
        if (resp.statusCode() != 200) log("删除回调非200: " + resp.statusCode() + " body=" + resp.body());
    }

    private static SSLContext trustAll() {
        try {
            SSLContext sc = SSLContext.getInstance("TLS");
            sc.init(null, new TrustManager[]{new X509TrustManager() {
                public void checkClientTrusted(X509Certificate[] c, String a) {}
                public void checkServerTrusted(X509Certificate[] c, String a) {}
                public X509Certificate[] getAcceptedIssuers() { return new X509Certificate[0]; }
            }}, new SecureRandom());
            return sc;
        } catch (Exception e) {
            return null;
        }
    }
}
