package cc.ysee.dh;

import com.google.gson.Gson;
import com.google.gson.GsonBuilder;
import com.google.gson.JsonArray;
import com.google.gson.JsonDeserializationContext;
import com.google.gson.JsonDeserializer;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParseException;
import com.google.gson.JsonPrimitive;
import com.google.gson.reflect.TypeToken;
import java.lang.reflect.Type;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;

/** 兑换码配置根结构（对应 https://你的域名/dh/config.json）
 *  安全格式（version=2）：
 *   - codes 的键为 HMAC-SHA256(K, 码) 的不可逆索引，config.json 不存明文码
 *   - 每个条目含 code(加密) 与 data(加密奖励JSON)；仅持有配置无法还原内容
 */
class ConfigRoot {
    public int version = 2;
    public Map<String, CodeEntry> codes;
}

/** 单个兑换码（config.json 中的存储形态，奖励经加密） */
class CodeEntry {
    public String name = "";
    public String desc = "";
    /** 每玩家限兑一次 */
    public boolean once = false;
    /** 全局仅可兑换一次（任何人兑过即失效，会回调删除） */
    public boolean globalOnce = false;
    /** 加密后的明文码（base64(iv|cipher)），仅在管理端解密展示 */
    public String code = "";
    /** 加密后的奖励 JSON（base64(iv|cipher)），插件侧解密后发放 */
    public String data = "";

    /** 解密后的奖励（运行时填充，不在 config.json 中） */
    public transient List<Reward> rewards;
}

/** 奖励项，type 支持 item / command / permission / money / message */
class Reward {
    public String type = "item";
    // item
    public String material = "minecraft:stone";
    public int amount = 1;
    public String itemname = "";
    /** 物品描述：标准为数组；兼容历史字符串数据（按 \n 分行） */
    public List<String> lore;
    /** 附魔列表（仅物品奖励）：[{enchant:"minecraft:sharpness", level:5}, ...] */
    public List<Map<String, Object>> enchants;
    /** NBT 修改器（仅物品奖励）：SNBT 字符串，如 {Unbreakable:1b,HideFlags:63} */
    public String nbt = "";
    // command
    public String run = "";
    public boolean console = true;
    // permission
    public String node = "";
    // money
    public double money = 0;
    // message
    public String text = "";
}

/**
 * 兼容反序列化器：lore 字段既接受数组也接受字符串。
 * 后台早期版本曾把 textarea 内容按字符串存储，导致
 * "Expected BEGIN_ARRAY but was STRING" 解析崩溃。
 * 这里统一转成 List<String>（字符串按换行拆分）。
 */
class RewardAdapter implements JsonDeserializer<Reward> {
    @Override
    public Reward deserialize(JsonElement json, Type typeOfT, JsonDeserializationContext ctx) throws JsonParseException {
        Reward r = new Reward();
        if (!json.isJsonObject()) return r;
        var obj = json.getAsJsonObject();
        if (obj.has("type") && obj.get("type").isJsonPrimitive()) r.type = obj.get("type").getAsString();
        if (obj.has("material") && obj.get("material").isJsonPrimitive()) r.material = obj.get("material").getAsString();
        // 数字字段必须健壮：后台可能存空字符串/非法值，一律回退默认
        r.amount = intField(obj, "amount", 1);
        if (obj.has("itemname") && obj.get("itemname").isJsonPrimitive()) r.itemname = obj.get("itemname").getAsString();
        if (obj.has("run") && obj.get("run").isJsonPrimitive()) r.run = obj.get("run").getAsString();
        if (obj.has("console") && obj.get("console").isJsonPrimitive()) r.console = obj.get("console").getAsBoolean();
        if (obj.has("node") && obj.get("node").isJsonPrimitive()) r.node = obj.get("node").getAsString();
        r.money = doubleField(obj, "money", 0);
        if (obj.has("text") && obj.get("text").isJsonPrimitive()) r.text = obj.get("text").getAsString();
        if (obj.has("nbt") && obj.get("nbt").isJsonPrimitive()) r.nbt = obj.get("nbt").getAsString();
        // enchants：数组 [{enchant, level}]
        if (obj.has("enchants") && obj.get("enchants").isJsonArray()) {
            List<Map<String, Object>> ench = new ArrayList<>();
            for (JsonElement e : obj.get("enchants").getAsJsonArray()) {
                if (e.isJsonObject()) {
                    var eo = e.getAsJsonObject();
                    java.util.LinkedHashMap<String, Object> m = new java.util.LinkedHashMap<>();
                    if (eo.has("enchant") && eo.get("enchant").isJsonPrimitive()) m.put("enchant", eo.get("enchant").getAsString());
                    int lvl = intField(eo, "level", 1);
                    m.put("level", lvl);
                    if (m.containsKey("enchant")) ench.add(m);
                }
            }
            if (!ench.isEmpty()) r.enchants = ench;
        }
        // lore：数组直接取；字符串按换行拆成数组；缺失/空则 null
        if (obj.has("lore") && !obj.get("lore").isJsonNull()) {
            JsonElement le = obj.get("lore");
            List<String> lore = new ArrayList<>();
            if (le.isJsonArray()) {
                for (JsonElement e : le.getAsJsonArray()) {
                    if (e.isJsonPrimitive() && e.getAsJsonPrimitive().isString()) lore.add(e.getAsString());
                }
            } else if (le.isJsonPrimitive()) {
                String s = le.getAsString();
                if (s != null && !s.trim().isEmpty()) {
                    for (String line : s.split("\n")) {
                        if (!line.trim().isEmpty()) lore.add(line);
                    }
                }
            }
            if (!lore.isEmpty()) r.lore = lore;
        }
        return r;
    }

    /** 安全读整数：数字/数字字符串均可，空串或非法回退默认值 */
    private static int intField(JsonObject o, String key, int def) {
        JsonElement e = o.get(key);
        if (e == null || e.isJsonNull()) return def;
        try {
            if (e.isJsonPrimitive()) {
                var p = e.getAsJsonPrimitive();
                if (p.isNumber()) return p.getAsInt();
                if (p.isString()) {
                    String s = p.getAsString().trim();
                    if (s.isEmpty()) return def;
                    return (int) Math.round(Double.parseDouble(s));
                }
            }
        } catch (Exception ignore) { }
        return def;
    }

    /** 安全读小数：数字/数字字符串均可，空串或非法回退默认值 */
    private static double doubleField(JsonObject o, String key, double def) {
        JsonElement e = o.get(key);
        if (e == null || e.isJsonNull()) return def;
        try {
            if (e.isJsonPrimitive()) {
                var p = e.getAsJsonPrimitive();
                if (p.isNumber()) return p.getAsDouble();
                if (p.isString()) {
                    String s = p.getAsString().trim();
                    if (s.isEmpty()) return def;
                    return Double.parseDouble(s);
                }
            }
        } catch (Exception ignore) { }
        return def;
    }
}

/** 全局 Gson：Reward 使用兼容适配器 */
class Gsons {
    static final Gson GSON = new GsonBuilder()
            .registerTypeAdapter(Reward.class, new RewardAdapter())
            .create();
}
