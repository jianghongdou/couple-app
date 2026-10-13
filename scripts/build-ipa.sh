#!/usr/bin/env bash
# 云端 / Mac 上构建「未签名 IPA」。
# 用法：bash scripts/build-ipa.sh <TargetName> <输出文件名>
#   TargetName：CoupleApp（完整版，含灵动岛扩展）或 CoupleAppLite（轻量版）
# 产物：dist/<输出文件名>.ipa
set -euo pipefail

TARGET="${1:-CoupleApp}"
OUT_NAME="${2:-$TARGET-unsigned}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT/ios"

echo "==> 工作目录: $(pwd)"
echo "==> 目标: $TARGET  输出: $OUT_NAME.ipa"

if ! command -v xcodegen >/dev/null 2>&1; then
  echo "!! 缺少 xcodegen，请先执行: brew install xcodegen" >&2
  exit 1
fi

if ! command -v xcodebuild >/dev/null 2>&1; then
  echo "!! 缺少 xcodebuild，请在 macOS 上运行本脚本" >&2
  exit 1
fi

echo "==> 生成 Xcode 工程"
xcodegen generate

DERIVED="$ROOT/build/$TARGET"
mkdir -p "$ROOT/build" "$ROOT/dist"
rm -rf "$DERIVED"

echo "==> 开始编译（禁用签名）"
set -o pipefail
xcodebuild \
  -project CoupleApp.xcodeproj \
  -scheme "$TARGET" \
  -configuration Release \
  -sdk iphoneos \
  -destination 'generic/platform=iOS' \
  -derivedDataPath "$DERIVED" \
  CODE_SIGNING_ALLOWED=NO \
  CODE_SIGNING_REQUIRED=NO \
  CODE_SIGN_IDENTITY="" \
  CODE_SIGN_ENTITLEMENTS="" \
  ENABLE_BITCODE=NO \
  build 2>&1 | tee "$ROOT/build/$TARGET-xcodebuild.log"

APP_PATH="$DERIVED/Build/Products/Release-iphoneos/$TARGET.app"
if [ ! -d "$APP_PATH" ]; then
  echo "!! 找不到产物 $APP_PATH" >&2
  ls -la "$DERIVED/Build/Products/Release-iphoneos" || true
  exit 1
fi

echo "==> 打包 IPA"
DIST="$ROOT/dist"
rm -rf "$DIST/Payload"
mkdir -p "$DIST/Payload"
cp -R "$APP_PATH" "$DIST/Payload/"
rm -f "$DIST/$OUT_NAME.ipa"
cd "$DIST"
# 保留符号链接结构（zip -y）
zip -qry "$OUT_NAME.ipa" Payload
rm -rf "$DIST/Payload"

echo "==> 完成：$DIST/$OUT_NAME.ipa"
ls -lh "$DIST/$OUT_NAME.ipa"
