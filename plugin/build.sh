#!/bin/bash
# ============================================================
# DHCode 插件构建脚本（仅针对 26.1.2 Paper）
# 你的服务器运行的是 26.1.2 Paper（基于 Minecraft 1.21），
# 因此只出一个编译版本即可，输出 DHCode-26.1.2.jar（同时复制为
# 通用 DHCode.jar，二者内容一致）。
#
# 前置：JDK 21 + Maven，且能访问 repo.papermc.io
# 用法：
#   bash build.sh                       # 使用默认 26.1.2.build.74-stable
#   PAPER_VERSION=26.1.2.build.74-stable MVN=/path/mvn bash build.sh
# ============================================================
set -u
DIR="$(cd "$(dirname "$0")" && pwd)"
OUT="$DIR/../builds"
mkdir -p "$OUT"

MVN="${MVN:-mvn}"
JAVA_HOME="${JAVA_HOME:-$DIR/../.jdk21}"
export JAVA_HOME

PAPER_VERSION="${PAPER_VERSION:-26.1.2.build.74-stable}"

echo "===== 构建 paper-api $PAPER_VERSION ====="
if "$MVN" -q -o -Dpaper.version="$PAPER_VERSION" clean package >/dev/null 2>&1 || \
   "$MVN" -q -Dpaper.version="$PAPER_VERSION" clean package >/dev/null 2>&1; then
  if [ -f "$DIR/target/DHCode-1.0.0.jar" ]; then
    cp "$DIR/target/DHCode-1.0.0.jar" "$OUT/DHCode-26.1.2.jar"
    cp "$DIR/target/DHCode-1.0.0.jar" "$OUT/DHCode.jar"
    echo "  OK -> $OUT/DHCode-26.1.2.jar"
    echo "  OK -> $OUT/DHCode.jar（通用，与 26.1.2 同构建）"
    exit 0
  fi
fi
echo "  FAIL：未生成 target/DHCode-1.0.0.jar"
exit 1
