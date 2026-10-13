// 下载 better-sqlite3 预编译原生二进制（镜像备用方案）
//
// 背景：better-sqlite3 是原生模块，npm 安装时其 install 脚本会从 GitHub Releases
// 下载预编译二进制。在部分网络环境下 GitHub 不可达，会导致安装失败。
// 本脚本改从 npmmirror 镜像下载对应 Node ABI 版本的预编译包并解压到
// node_modules/better-sqlite3/build/Release/better_sqlite3.node。
//
// 用法：
//   node scripts/fetch-sqlite-binary.mjs
//
// 说明：仅在 `npm install` 因原生二进制下载失败时才需要执行。
// 如果你的网络可以直连 GitHub，直接 npm install 即可，不需要本脚本。
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SERVER_DIR = path.resolve(__dirname, '..');
const PKG_DIR = path.join(SERVER_DIR, 'node_modules', 'better-sqlite3');

const MIRROR = 'https://registry.npmmirror.com/-/binary/better-sqlite3';

// Node 的 NODE_MODULE_VERSION（ABI），better-sqlite3 的预编译包按此命名
const ABI_BY_NODE_MAJOR = {
  18: 108,
  19: 111,
  20: 115,
  21: 120,
  22: 127,
  23: 131,
  24: 137,
};

function abiForCurrentNode() {
  const major = Number(process.versions.node.split('.')[0]);
  const abi = ABI_BY_NODE_MAJOR[major];
  if (!abi) {
    throw new Error(
      `未内置 Node ${major} 的 ABI 映射，请到 ${MIRROR}/<版本>/ 手动选择对应 node-v<ABI>-win32-x64.tar.gz`
    );
  }
  return abi;
}

// 极简 tar 解析：只提取目标文件（.tar.gz 解压后为 POSIX tar）
function extractFileFromTar(tarBuf, wantedSuffix) {
  let offset = 0;
  while (offset + 512 <= tarBuf.length) {
    const header = tarBuf.subarray(offset, offset + 512);
    // 全零块表示归档结束
    if (header.every((b) => b === 0)) break;

    const name = header.subarray(0, 100).toString('utf8').replace(/\0.*$/, '');
    const sizeStr = header.subarray(124, 136).toString('utf8').replace(/\0.*$/, '').trim();
    const size = Number.parseInt(sizeStr, 8) || 0;
    const prefix = header.subarray(345, 500).toString('utf8').replace(/\0.*$/, '');
    const fullName = prefix ? `${prefix}/${name}` : name;

    const dataStart = offset + 512;
    if (fullName.endsWith(wantedSuffix)) {
      return tarBuf.subarray(dataStart, dataStart + size);
    }
    // tar 数据按 512 字节对齐
    offset = dataStart + Math.ceil(size / 512) * 512;
  }
  return null;
}

async function main() {
  if (!fs.existsSync(PKG_DIR)) {
    throw new Error(`未找到 ${PKG_DIR}，请先执行 npm install`);
  }
  const pkgJson = JSON.parse(fs.readFileSync(path.join(PKG_DIR, 'package.json'), 'utf8'));
  const version = pkgJson.version;

  const abi = abiForCurrentNode();
  const assetName = `better-sqlite3-v${version}-node-v${abi}-win32-x64.tar.gz`;
  const url = `${MIRROR}/v${version}/${assetName}`;

  console.log(`[1/3] 目标：better-sqlite3 v${version} / Node ABI v${abi} (${process.version})`);
  console.log(`[2/3] 下载：${url}`);

  const res = await fetch(url);
  if (!res.ok) {
    throw new Error(`下载失败 HTTP ${res.status}：${url}`);
  }
  const gz = Buffer.from(await res.arrayBuffer());
  console.log(`      已下载 ${(gz.length / 1024).toFixed(1)} KB，开始解压…`);

  const tar = zlib.gunzipSync(gz);
  const nodeBuf = extractFileFromTar(tar, 'better_sqlite3.node');
  if (!nodeBuf) {
    throw new Error('压缩包内未找到 better_sqlite3.node');
  }

  const outDir = path.join(PKG_DIR, 'build', 'Release');
  fs.mkdirSync(outDir, { recursive: true });
  const outFile = path.join(outDir, 'better_sqlite3.node');
  fs.writeFileSync(outFile, nodeBuf);
  console.log(`[3/3] 已写入：${outFile}（${(nodeBuf.length / 1024).toFixed(1)} KB）`);

  // 立即验证能否加载
  const { default: Database } = await import('better-sqlite3');
  const db = new Database(':memory:');
  db.exec('CREATE TABLE t(a INTEGER)');
  db.prepare('INSERT INTO t VALUES (?)').run(42);
  const row = db.prepare('SELECT a FROM t').get();
  db.close();
  if (row.a !== 42) throw new Error('自检失败');
  console.log('✅ better-sqlite3 原生模块可用（自检通过）');
}

main().catch((err) => {
  console.error('❌ 下载预编译二进制失败：', err.message);
  console.error('   可手动访问镜像目录选择对应文件：' + MIRROR);
  process.exit(1);
});
