// 内存滑动窗口限流（单进程；多实例部署时需换成 Redis，见 README「已知限制」）
//
// 实现：用「固定窗口 + 权重」近似滑动窗口——保存当前窗口与上一窗口计数，
// 按当前时间在窗口内的推进比例对上一窗口做衰减，得到平滑的近似值。
// 相比固定窗口，避免了窗口边界处的双倍突发。

/** @type {Map<string, {count:number, windowStart:number, prevCount:number, prevStart:number}>} */
const buckets = new Map();

// 定期清理过期桶，避免内存泄漏
let sweeper = null;

function startSweeper() {
  if (sweeper) return;
  sweeper = setInterval(() => {
    const now = Date.now();
    for (const [key, b] of buckets) {
      // 超过 2 个窗口未活动即可回收
      if (now - b.windowStart > b.windowMs * 2) buckets.delete(key);
    }
  }, 60 * 1000);
  // 不阻止进程退出
  if (typeof sweeper.unref === 'function') sweeper.unref();
}

/**
 * 记录一次访问并判断是否超限
 * @param {string} key 限流键（如 `login:1.2.3.4`）
 * @param {number} limit 窗口内允许的最大次数
 * @param {number} windowMs 窗口长度（毫秒）
 * @returns {{allowed:boolean, remaining:number, retryAfterSeconds:number}}
 */
export function hit(key, limit, windowMs) {
  startSweeper();
  const now = Date.now();
  let b = buckets.get(key);

  if (!b || now - b.windowStart >= windowMs) {
    // 进入新窗口：旧窗口降级为上一窗口
    const prevCount = b && now - b.windowStart < windowMs * 2 ? b.count : 0;
    const prevStart = b ? b.windowStart : now;
    b = { count: 0, windowStart: now, prevCount, prevStart, windowMs };
    buckets.set(key, b);
  }
  b.windowMs = windowMs;

  // 还未进入新窗口：先累加
  b.count += 1;

  // 滑动加权：当前窗口按时间比例保留上一窗口的贡献
  const elapsed = now - b.windowStart;
  const ratio = Math.max(0, 1 - elapsed / windowMs);
  const weighted = b.count + b.prevCount * ratio;

  const allowed = weighted <= limit;
  const remaining = Math.max(0, Math.floor(limit - weighted));
  // 需要等待到当前窗口结束
  const retryAfterSeconds = Math.max(1, Math.ceil((b.windowStart + windowMs - now) / 1000));

  return { allowed, remaining, retryAfterSeconds };
}

/**
 * 只读取当前计数，不累加
 * @param {string} key
 * @returns {number}
 */
export function peek(key) {
  const b = buckets.get(key);
  if (!b) return 0;
  const now = Date.now();
  if (now - b.windowStart >= b.windowMs) return 0;
  const ratio = Math.max(0, 1 - (now - b.windowStart) / b.windowMs);
  return b.count + b.prevCount * ratio;
}

/** 清空所有限流状态（测试用） */
export function reset() {
  buckets.clear();
}

/**
 * 生成限流中间件
 * @param {{limit:number, windowMs:number, keyGenerator?:(req)=>string, message?:string}} opts
 */
export function rateLimit(opts) {
  const { limit, windowMs, keyGenerator, message = '请求过于频繁，请稍后再试' } = opts;
  const genKey = keyGenerator || ((req) => req.ip || 'unknown');
  return function limiter(req, res, next) {
    // 仅用于自动化测试：通过环境变量显式开启后，允许用请求头重置限流状态，
    // 让测试用例之间互不影响。生产环境不设置该变量时这段逻辑完全不生效。
    if (process.env.ENABLE_TEST_HOOKS === '1' && req.headers['x-test-reset-ratelimit'] === '1') {
      buckets.clear();
    }

    const result = hit(genKey(req), limit, windowMs);
    res.setHeader('X-RateLimit-Limit', String(limit));
    res.setHeader('X-RateLimit-Remaining', String(result.remaining));
    if (!result.allowed) {
      res.setHeader('Retry-After', String(result.retryAfterSeconds));
      res.status(429).json({
        error: 'rate_limited',
        message,
        serverTime: Date.now(),
      });
      return;
    }
    next();
  };
}

export default { hit, peek, reset, rateLimit };
