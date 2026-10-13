// 全量验证入口：依次运行冒烟测试与三组专项验证。
//
//   node test/run.mjs
//
// 说明：子进程 stdio 使用「文件描述符」而不是管道。
//       部分受限运行环境禁止把子进程输出接到管道（spawn EPERM），
//       但对文件重定向没有限制，因此这里统一走文件，同时把输出留档。
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SERVER_DIR = path.resolve(__dirname, '..');

const SUITES = [
  { name: '冒烟测试（API 契约端到端）', script: path.join('test', 'smoke.mjs'), report: 'test-report.txt' },
  { name: '启动验证（默认流程 + 优雅退出）', script: path.join('scripts', 'verify-start.mjs'), report: 'verify-start-report.txt' },
  { name: '配置验证（环境变量校验 + init-db）', script: path.join('scripts', 'verify-config.mjs'), report: 'verify-config-report.txt' },
  { name: 'AI 链路验证（本地模拟 DeepSeek）', script: path.join('scripts', 'verify-ai.mjs'), report: 'verify-ai-report.txt' },
  { name: '公共表情包验证（内置 public/emoji）', script: path.join('scripts', 'verify-public-emoji.mjs'), report: 'verify-public-emoji-report.txt' },
  { name: '补充行为验证（保留期 / 限流 / 广播）', script: path.join('scripts', 'verify-extras.mjs'), report: 'verify-extras-report.txt' },
];

/** 运行一个脚本，输出写入报告文件并在控制台回显 */
function runSuite(suite) {
  return new Promise((resolve) => {
    const reportPath = path.join(SERVER_DIR, suite.report);
    const fd = fs.openSync(reportPath, 'w');
    process.stdout.write(`\n\n########## ${suite.name} ##########\n\n`);

    const child = spawn(process.execPath, [suite.script], {
      cwd: SERVER_DIR,
      stdio: ['ignore', fd, fd],
      env: process.env,
    });

    child.on('exit', (code) => {
      try { fs.closeSync(fd); } catch { /* 忽略 */ }
      // 回显报告内容
      try {
        process.stdout.write(fs.readFileSync(reportPath, 'utf8'));
      } catch { /* 忽略 */ }
      process.stdout.write(`\n---- ${suite.name} 退出码 ${code} ----\n`);
      resolve(code === 0);
    });
    child.on('error', (err) => {
      try { fs.closeSync(fd); } catch { /* 忽略 */ }
      process.stdout.write(`启动 ${suite.script} 失败：${err.message}\n`);
      resolve(false);
    });
  });
}

const results = [];
for (const suite of SUITES) {
  results.push({ suite, ok: await runSuite(suite) });
}

process.stdout.write('\n\n========================================\n');
process.stdout.write('  全量验证汇总\n');
process.stdout.write('========================================\n');
for (const { suite, ok } of results) {
  process.stdout.write(`  ${ok ? '✅ 通过' : '❌ 失败'}  ${suite.name}\n`);
}
const failedCount = results.filter((r) => !r.ok).length;
process.stdout.write('----------------------------------------\n');
if (failedCount === 0) {
  process.stdout.write('  ✅ 全部验证通过\n');
} else {
  process.stdout.write(`  ❌ ${failedCount} 组验证失败\n`);
}
process.stdout.write('========================================\n');
process.exit(failedCount === 0 ? 0 : 1);
