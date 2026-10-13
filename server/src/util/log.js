// 结构化日志：LOG_LEVEL 控制输出级别。
// 级别：debug < info < warn < error < silent
const LEVELS = { debug: 10, info: 20, warn: 30, error: 40, silent: 99 };

let currentLevel = LEVELS.info;

export function setLevel(level) {
  const key = String(level || 'info').toLowerCase();
  currentLevel = LEVELS[key] ?? LEVELS.info;
}

export function getLevel() {
  return Object.keys(LEVELS).find((k) => LEVELS[k] === currentLevel) || 'info';
}

// 结构化输出：单行 JSON，便于日志采集
function emit(level, message, extra) {
  if (LEVELS[level] < currentLevel) return;
  const record = {
    t: new Date().toISOString(),
    level,
    msg: String(message),
  };
  if (extra && typeof extra === 'object') {
    for (const [k, v] of Object.entries(extra)) {
      // 避免覆盖保留字段
      if (k === 't' || k === 'level' || k === 'msg') continue;
      record[k] = v;
    }
  }
  let line;
  try {
    line = JSON.stringify(record);
  } catch {
    line = JSON.stringify({ t: record.t, level, msg: record.msg });
  }
  const stream = LEVELS[level] >= LEVELS.warn ? process.stderr : process.stdout;
  stream.write(line + '\n');
}

export const log = {
  debug: (msg, extra) => emit('debug', msg, extra),
  info: (msg, extra) => emit('info', msg, extra),
  warn: (msg, extra) => emit('warn', msg, extra),
  error: (msg, extra) => emit('error', msg, extra),
};

export default log;
