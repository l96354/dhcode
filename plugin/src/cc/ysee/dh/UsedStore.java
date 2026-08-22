package cc.ysee.dh;

import com.google.gson.Gson;
import com.google.gson.GsonBuilder;
import com.google.gson.reflect.TypeToken;

import java.io.File;
import java.io.FileReader;
import java.io.FileWriter;
import java.lang.reflect.Type;
import java.util.HashSet;
import java.util.Map;
import java.util.Set;
import java.util.UUID;
import java.util.concurrent.ConcurrentHashMap;

/** 本地去重存储：记录每个玩家已兑换过的码（防止重复兑换） */
public class UsedStore {

    private final File file;
    private final Gson gson = new GsonBuilder().setPrettyPrinting().create();
    private final Type TYPE = new TypeToken<Map<String, Set<String>>>() {}.getType();
    private final Map<String, Set<String>> data = new ConcurrentHashMap<>();

    public UsedStore(File pluginFolder) {
        this(pluginFolder, "");
    }

    /** 支持配置自定义路径：used-data-file（留空则默认 plugins/DHCode/used.json） */
    public UsedStore(File pluginFolder, String customPath) {
        if (customPath != null && !customPath.trim().isEmpty()) {
            this.file = new File(customPath.trim());
        } else {
            this.file = new File(pluginFolder, "used.json");
        }
        load();
    }

    private void load() {
        if (!file.exists()) return;
        try (FileReader r = new FileReader(file)) {
            Map<String, Set<String>> m = gson.fromJson(r, TYPE);
            if (m != null) data.putAll(m);
        } catch (Exception e) {
            // 损坏则忽略，重新开始
        }
    }

    private void save() {
        try {
            if (!file.getParentFile().exists()) file.getParentFile().mkdirs();
            try (FileWriter w = new FileWriter(file)) {
                gson.toJson(data, w);
            }
        } catch (Exception e) {
            // 保存失败仅日志
        }
    }

    public boolean has(UUID uuid, String code) {
        Set<String> s = data.get(uuid.toString());
        return s != null && s.contains(code);
    }

    public void add(UUID uuid, String code) {
        data.computeIfAbsent(uuid.toString(), k -> new HashSet<>()).add(code);
        save();
    }

    public void removeCodeGlobally(String code) {
        boolean changed = false;
        for (Set<String> s : data.values()) {
            if (s.remove(code)) changed = true;
        }
        if (changed) save();
    }
}
