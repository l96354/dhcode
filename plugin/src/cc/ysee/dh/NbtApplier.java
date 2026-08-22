package cc.ysee.dh;

import org.bukkit.inventory.ItemStack;

import java.lang.reflect.Method;

/**
 * NBT 修改器（仅用于物品奖励）。
 *
 * Paper 26.1.2 采用新版「数据组件（DataComponent）」系统，旧的
 * ItemStack#setTag/getTag 已移除。任意 SNBT（如 {Unbreakable:1b,
 * HideFlags:63, mykey:"v"}）解析为 CompoundTag 后，按原版约定写入
 * minecraft:custom_data 组件——这是 1.21.x+ 对自定义 NBT 的标准存储
 * 位置（/give 的 custom_data 与大多数 NBT 工具均读写该组件）。
 *
 * 全程反射，编译期只依赖 Bukkit API，运行时类由服务端提供。
 */
public class NbtApplier {

    public static ItemStack apply(ItemStack item, String snbt) throws Exception {
        // 1) SNBT -> NMS CompoundTag（不同 1.21.x 子版本方法名可能为
        //    parseCompoundFully / parseCompound，按序尝试以提升跨版本兼容）
        Class<?> parserCls = Class.forName("net.minecraft.nbt.TagParser");
        Method parse = null;
        for (String name : new String[]{"parseCompoundFully", "parseCompound"}) {
            try { parse = parserCls.getMethod(name, String.class); break; }
            catch (NoSuchMethodException ignore) { }
        }
        if (parse == null) throw new NoSuchMethodException("TagParser.parseCompound*");
        Object compound = parse.invoke(null, snbt);   // net.minecraft.nbt.CompoundTag

        // 2) Bukkit -> NMS ItemStack
        Class<?> cisCls = Class.forName("org.bukkit.craftbukkit.inventory.CraftItemStack");
        Method asNms = cisCls.getMethod("asNMSCopy", ItemStack.class);
        Object nmsStack = asNms.invoke(null, item);   // net.minecraft.world.item.ItemStack

        // 3) 取 CUSTOM_DATA 组件类型
        Class<?> dcCls = Class.forName("net.minecraft.core.component.DataComponents");
        Object customDataType = dcCls.getField("CUSTOM_DATA").get(null);

        // 4) 读已有 custom_data，若存在则合并（按 key 覆盖），否则直接使用新 tag
        Method get = nmsStack.getClass().getMethod("get", Class.forName("net.minecraft.core.component.DataComponentType"));
        Object existing = get.invoke(nmsStack, customDataType);

        Object finalTag = compound;
        if (existing != null) {
            Class<?> cdCls = Class.forName("net.minecraft.world.item.component.CustomData");
            // CustomData.EMPTY
            Object empty = cdCls.getField("EMPTY").get(null);
            if (!existing.equals(empty)) {
                Method copyTag = cdCls.getMethod("copyTag");
                Object oldTag = copyTag.invoke(existing);   // CompoundTag
                // 合并：old.putAll(new) —— CompoundTag 有 put(String, Tag)
                Method keySet = compound.getClass().getMethod("keySet");
                Method getK = compound.getClass().getMethod("get", String.class);
                Method put = oldTag.getClass().getMethod("put", String.class, Class.forName("net.minecraft.nbt.Tag"));
                for (Object k : (java.util.Set<?>) keySet.invoke(compound)) {
                    put.invoke(oldTag, k, getK.invoke(compound, k));
                }
                finalTag = oldTag;
            }
        }

        // 5) 写入 custom_data 组件
        Class<?> cdCls = Class.forName("net.minecraft.world.item.component.CustomData");
        Method of = cdCls.getMethod("of", Class.forName("net.minecraft.nbt.CompoundTag"));
        Object customData = of.invoke(null, finalTag);
        Method set = nmsStack.getClass().getMethod("set", Class.forName("net.minecraft.core.component.DataComponentType"), Object.class);
        set.invoke(nmsStack, customDataType, customData);

        // 6) NMS -> Bukkit
        Method asBukkit = cisCls.getMethod("asBukkitCopy", nmsStack.getClass());
        return (ItemStack) asBukkit.invoke(null, nmsStack);
    }
}
