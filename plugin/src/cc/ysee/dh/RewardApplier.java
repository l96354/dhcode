package cc.ysee.dh;

import net.kyori.adventure.text.Component;
import net.kyori.adventure.text.serializer.legacy.LegacyComponentSerializer;
import org.bukkit.Bukkit;
import org.bukkit.Material;
import org.bukkit.command.ConsoleCommandSender;
import org.bukkit.enchantments.Enchantment;
import org.bukkit.entity.Player;
import org.bukkit.inventory.ItemStack;
import org.bukkit.inventory.meta.ItemMeta;

import java.util.ArrayList;
import java.util.List;
import java.util.Map;

/** 把兑换码的奖励逐条发放给玩家 */
public class RewardApplier {

    private static final LegacyComponentSerializer LEGACY = LegacyComponentSerializer.legacyAmpersand();

    private static Component comp(String s) {
        return s == null ? Component.empty() : LEGACY.deserialize(s);
    }

    public static void apply(Player p, CodeEntry entry) {
        if (entry.rewards == null || entry.rewards.isEmpty()) {
            p.sendMessage(comp("&a兑换成功！"));
            return;
        }
        for (Reward r : entry.rewards) {
            try {
                applyOne(p, r);
            } catch (Exception e) {
                p.sendMessage(comp("&c一项奖励发放失败：" + r.type));
                DHCode.log("奖励发放异常 type=" + r.type + " player=" + p.getName() + " err=" + e);
            }
        }
    }

    private static void applyOne(Player p, Reward r) {
        if (r.type == null) return;
        switch (r.type.toLowerCase()) {
            case "item": giveItem(p, r); break;
            case "command": runCommand(p, r); break;
            case "permission": runPermission(p, r); break;
            case "money": runMoney(p, r); break;
            case "message": p.sendMessage(comp(r.text)); break;
            default: p.sendMessage(comp("&c未知奖励类型：" + r.type));
        }
    }

    private static void giveItem(Player p, Reward r) {
        String raw = r.material;
        if (raw == null || raw.isEmpty()) raw = "minecraft:stone";
        Material m = Material.matchMaterial(raw);
        if (m == null) m = Material.matchMaterial(raw.replace("minecraft:", "").toUpperCase());
        if (m == null || m == Material.AIR) {
            p.sendMessage(comp("&c物品无效：" + raw));
            return;
        }
        ItemStack item = new ItemStack(m, Math.max(1, r.amount));
        ItemMeta meta = item.getItemMeta();
        if (r.itemname != null && !r.itemname.isEmpty()) {
            meta.displayName(comp(r.itemname));
        }
        if (r.lore != null && !r.lore.isEmpty()) {
            List<Component> lore = new ArrayList<>();
            for (String line : r.lore) lore.add(comp(line));
            meta.lore(lore);
        }
        // 附魔：附魔书用存储附魔，普通物品直接附魔
        applyEnchants(item, meta, r);
        item.setItemMeta(meta);

        // NBT 修改器（仅物品）：把 SNBT 合并进物品 tag
        if (r.nbt != null && !r.nbt.trim().isEmpty()) {
            try {
                item = NbtApplier.apply(item, r.nbt);
            } catch (Exception e) {
                DHCode.log("NBT 修改失败 material=" + raw + " nbt=" + r.nbt + " err=" + e);
                p.sendMessage(comp("&cNBT 修改失败，已按未修改发放：" + raw));
            }
        }

        Map<Integer, ItemStack> leftover = p.getInventory().addItem(item);
        for (ItemStack drop : leftover.values()) {
            p.getWorld().dropItemNaturally(p.getLocation(), drop);
        }
        // 中文发放提示（避免显示一大串英文物品 ID）
        p.sendMessage(comp("&a你获得了 &f" + ZhNames.item(raw) + "&a ×" + Math.max(1, r.amount)
                + (r.enchants != null && !r.enchants.isEmpty() ? " &7(附魔)" : "")));
    }

    /** 应用附魔列表；material 为附魔书时使用存储附魔 */
    private static void applyEnchants(ItemStack item, ItemMeta meta, Reward r) {
        if (r.enchants == null || r.enchants.isEmpty()) return;
        boolean isBook = item.getType() == Material.ENCHANTED_BOOK || item.getType() == Material.BOOK;
        for (Map<String, Object> e : r.enchants) {
            try {
                String key = String.valueOf(e.get("enchant"));
                int lvl = e.get("level") instanceof Number
                        ? ((Number) e.get("level")).intValue() : 1;
                String clean = key.startsWith("minecraft:") ? key.substring(10) : key;
                Enchantment ench = Enchantment.getByKey(org.bukkit.NamespacedKey.minecraft(clean));
                if (ench == null) {
                    DHCode.log("未知附魔: " + key);
                    continue;
                }
                if (isBook) {
                    if (meta instanceof org.bukkit.inventory.meta.EnchantmentStorageMeta sm) {
                        sm.addStoredEnchant(ench, lvl, true);
                    }
                } else {
                    meta.addEnchant(ench, lvl, true);
                }
            } catch (Exception ex) {
                DHCode.log("附魔应用失败: " + e + " err=" + ex);
            }
        }
    }

    private static void runCommand(Player p, Reward r) {
        if (r.run == null || r.run.isEmpty()) return;
        String cmd = r.run.replace("{player}", p.getName());
        if (r.console) {
            ConsoleCommandSender cs = Bukkit.getConsoleSender();
            Bukkit.dispatchCommand(cs, cmd);
        } else {
            p.performCommand(cmd);
        }
    }

    private static void runPermission(Player p, Reward r) {
        if (r.node == null || r.node.isEmpty()) return;
        // 兼容 LuckPerms（多数服务器）与其他权限插件：走 lp 命令
        String cmd = "lp user " + p.getName() + " permission set " + r.node + " true";
        Bukkit.dispatchCommand(Bukkit.getConsoleSender(), cmd);
    }

    private static void runMoney(Player p, Reward r) {
        if (r.money <= 0) return;
        // 尝试 Vault 经济
        boolean vault = false;
        try {
            Class<?> econClass = Class.forName("net.milkbowl.vault.economy.Economy");
            Object reg = Bukkit.getServer().getServicesManager().getRegistration(econClass);
            if (reg != null) {
                Object econ = reg.getClass().getMethod("getProvider").invoke(reg);
                econClass.getMethod("depositPlayer", org.bukkit.OfflinePlayer.class, double.class)
                        .invoke(econ, p, r.money);
                vault = true;
            }
        } catch (Throwable t) {
            vault = false;
        }
        if (!vault) {
            // 兜底走 eco 命令（Essentials 等）
            String cmd = String.format("eco give %s %.2f", p.getName(), r.money);
            Bukkit.dispatchCommand(Bukkit.getConsoleSender(), cmd);
        }
    }
}
