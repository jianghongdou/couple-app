# 情侣 App 服务端 Dockerfile
# 说明：better-sqlite3 是原生模块，这里使用 Debian 基础镜像并安装构建工具，
#       保证在没有预编译二进制的环境下也能从源码编译成功。
FROM node:20-bookworm-slim

# 时区（日志时间与「今日」统计按本地时间）
ENV TZ=Asia/Shanghai
# 生产环境
ENV NODE_ENV=production
# 数据目录（配合 docker-compose 的卷挂载）
ENV DATA_DIR=/app/data
ENV PORT=8787

# better-sqlite3 编译期依赖
RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 make g++ ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# 先复制依赖清单，最大化利用构建缓存
COPY package.json ./
COPY .npmrc ./
COPY scripts ./scripts

# 安装依赖
# 若 npm 生命周期脚本被限制，可改用：
#   npm install --ignore-scripts && node scripts/fetch-sqlite-binary.mjs
RUN npm install --omit=dev --no-audit --no-fund

# 复制源码
COPY src ./src
COPY public ./public

# 数据目录（可被卷覆盖）
RUN mkdir -p /app/data /app/data/emoji && chown -R node:node /app

# 以非 root 用户运行
USER node

EXPOSE 8787

# 健康检查
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD node -e "fetch('http://127.0.0.1:'+(process.env.PORT||8787)+'/api/health').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

CMD ["node", "src/index.js"]
