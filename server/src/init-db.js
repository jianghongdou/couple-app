// 数据库初始化脚本（npm run init-db）
//
// 只建库、建表并写入默认设置，不启动 HTTP 服务。
// 适用于部署前预建数据库，或确认数据目录权限是否正常。
import { CONFIG, ensureDirs, assertConfig } from './config.js';
import { initDb, closeDb, getStats, getAllSettings } from './db.js';
import { setLevel } from './util/log.js';

assertConfig();
setLevel(CONFIG.logLevel);
ensureDirs();

initDb();

const stats = getStats();
const settings = getAllSettings();

process.stdout.write(
  [
    '',
    '✅ 数据库初始化完成',
    `   数据文件： ${CONFIG.dbFile}`,
    `   表情目录： ${CONFIG.emojiDir}`,
    '',
    '   当前数据量：',
    `     用户 ${stats.users} · 情侣 ${stats.couples} · 消息 ${stats.messages}`,
    `     表情包 ${stats.packs} · 表情 ${stats.items} · 同步对象 ${stats.syncObjects}`,
    '',
    `   默认设置项： ${Object.keys(settings).length} 个（aiEnabled=${settings.aiEnabled}、aiDailyLimit=${settings.aiDailyLimit}、registerOpen=${settings.registerOpen}）`,
    '',
  ].join('\n')
);

closeDb();
