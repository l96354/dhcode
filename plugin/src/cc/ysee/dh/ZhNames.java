package cc.ysee.dh;

import com.google.gson.Gson;
import com.google.gson.reflect.TypeToken;
import org.bukkit.plugin.Plugin;

import java.io.InputStream;
import java.io.InputStreamReader;
import java.lang.reflect.Type;
import java.nio.charset.StandardCharsets;
import java.util.HashMap;
import java.util.Map;

/** 中文名库：物品 / 附魔（内置自 26.1.2 官方语言文件，用于查询与发放消息中文化） */
public class ZhNames {
    private static Map<String, String> items = new HashMap<>();
    private static Map<String, String> enchants = new HashMap<>();
    private static final Gson G = new Gson();
    private static final Type MAP_TYPE = new TypeToken<Map<String, String>>() {}.getType();

    public static void load(Plugin plugin) {
        try (InputStream is = plugin.getResource("items_zh.json")) {
            if (is != null) {
                Map<String, String> m = G.fromJson(new InputStreamReader(is, StandardCharsets.UTF_8), MAP_TYPE);
                if (m != null) items = m;
            }
        } catch (Exception e) {
            DHCode.log("加载物品中文库失败: " + e.getMessage());
        }
        try (InputStream is = plugin.getResource("enchants_zh.json")) {
            if (is != null) {
                Map<String, String> m = G.fromJson(new InputStreamReader(is, StandardCharsets.UTF_8), MAP_TYPE);
                if (m != null) enchants = m;
            }
        } catch (Exception e) {
            DHCode.log("加载附魔中文库失败: " + e.getMessage());
        }
    }

    /** 物品 ID（minecraft:diamond 或 diamond）→ 中文名 */
    public static String item(String id) {
        if (id == null) return "";
        String key = id.startsWith("minecraft:") ? id.substring(10) : id;
        String zh = items.get(key);
        return zh != null ? zh : id;
    }

    /** 附魔 ID（minecraft:sharpness 或 sharpness）→ 中文名 */
    public static String enchant(String id) {
        if (id == null) return "";
        String key = id.startsWith("minecraft:") ? id.substring(10) : id;
        String zh = enchants.get(key);
        return zh != null ? zh : id;
    }
}
