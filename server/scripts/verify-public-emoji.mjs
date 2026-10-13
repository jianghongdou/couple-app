// 临时验证：内置公共表情包（public/emoji/<包名>/<图片>）的端到端行为
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

// 本脚本位于 server/scripts/，服务根目录是上一级
const SERVER_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PORT = 8793;
const BASE = `http://127.0.0.1:${PORT}`;
const DATA_DIR = './data-pub';
const DATA_DIR_ABS = path.join(SERVER_DIR, 'data-pub');
const LOG = path.join(SERVER_DIR, 'pub.log');
const PUBLIC_DIR = path.join(SERVER_DIR, 'public', 'emoji');

let passed = 0; let failed = 0;
const ok = (c, l, e) => { if (c) { passed++; console.log(`  ✅ ${l}`); } else { failed++; console.log(`  ❌ ${l}${e ? `\n       ${e}` : ''}`); } };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ---- 生成 PNG ----
function crc32(buf) {
  const t = crc32.t || (crc32.t = (() => { const a = new Int32Array(256); for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; a[n] = c; } return a; })());
  let c = -1; for (let i = 0; i < buf.length; i++) c = (c >>> 8) ^ t[(c ^ buf[i]) & 0xff]; return (c ^ -1) >>> 0;
}
function chunk(type, data) {
  const len = Buffer.alloc(4); len.writeUInt32BE(data.length, 0);
  const tb = Buffer.from(type, 'ascii'); const cb = Buffer.alloc(4);
  cb.writeUInt32BE(crc32(Buffer.concat([tb, data])), 0);
  return Buffer.concat([len, tb, data, cb]);
}
function makePng(size = 8, seed = 0) {
  const sig = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(size, 0); ihdr.writeUInt32BE(size, 4); ihdr[8] = 8; ihdr[9] = 6;
  const raw = Buffer.alloc(size * (1 + size * 4));
  let o = 0;
  for (let y = 0; y < size; y++) { raw[o++] = 0; for (let x = 0; x < size; x++) { raw[o++] = (x * 32 + seed) & 0xff; raw[o++] = (y * 32) & 0xff; raw[o++] = 0x99; raw[o++] = 0xff; } }
  return Buffer.concat([sig, chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}

// ---- 准备公共表情包目录 ----
const createdDirs = [];
function setupPublicPacks() {
  // 两个包，各 2 张图；包名故意用中文与英文混合，验证 id 映射
  const packs = [
    { dir: '经典表情', files: ['大笑.png', '哭泣.png'] },
    { dir: 'pets', files: ['cat.png'] },
  ];
  for (const p of packs) {
    const d = path.join(PUBLIC_DIR, p.dir);
    fs.mkdirSync(d, { recursive: true });
    createdDirs.push(d);
    p.files.forEach((f, i) => fs.writeFileSync(path.join(d, f), makePng(8, i * 40)));
  }
  // 放一个非法文件，应被跳过
  fs.writeFileSync(path.join(PUBLIC_DIR, 'pets', 'bad.txt'), 'not an image');
  fs.writeFileSync(path.join(PUBLIC_DIR, 'pets', 'fake.png'), 'pretend png');
}
function cleanupPublicPacks() {
  for (const d of createdDirs) { try { fs.rmSync(d, { recursive: true, force: true }); } catch { /* 忽略 */ } }
  try { fs.rmSync(path.join(PUBLIC_DIR, 'pets'), { recursive: true, force: true }); } catch { /* 忽略 */ }
}

let fd = null; let child = null;
async function req(method, url, opts = {}) {
  const headers = {};
  if (opts.body !== undefined) headers['Content-Type'] = 'application/json';
  if (opts.token) headers.Authorization = `Bearer ${opts.token}`;
  const res = await fetch(BASE + url, { method, headers, body: opts.body === undefined ? undefined : JSON.stringify(opts.body) });
  const ct = res.headers.get('content-type') || '';
  const buf = Buffer.from(await res.arrayBuffer());
  let json = null;
  if (ct.includes('json')) { try { json = JSON.parse(buf.toString('utf8')); } catch { /* 忽略 */ } }
  return { status: res.status, json, buf, ct, headers: res.headers };
}

try {
  console.log('准备公共表情包目录…');
  setupPublicPacks();

  fs.rmSync(DATA_DIR_ABS, { recursive: true, force: true });
  fs.rmSync(LOG, { force: true });
  fd = fs.openSync(LOG, 'a');
  child = spawn(process.execPath, ['src/index.js'], {
    cwd: SERVER_DIR,
    env: { ...process.env, PORT: String(PORT), DATA_DIR, JWT_SECRET: crypto.randomBytes(48).toString('hex'),
      ADMIN_PASSWORD: 'pub123', LOG_LEVEL: 'info', DOTENV_CONFIG_PATH: path.join(SERVER_DIR, '.env.nonexistent') },
    stdio: ['ignore', fd, fd],
  });

  const dl = Date.now() + 25000; let up = false;
  while (Date.now() < dl) { try { if ((await fetch(`${BASE}/api/health`)).ok) { up = true; break; } } catch { /* 继续 */ } await sleep(200); }
  if (!up) throw new Error('启动失败\n' + fs.readFileSync(LOG, 'utf8'));

  const s = crypto.randomBytes(3).toString('hex');
  const a = await req('POST', '/api/auth/register', { body: { username: `pub_${s}`, password: 'pass1234' } });
  const b = await req('POST', '/api/auth/register', { body: { username: `pub2_${s}`, password: 'pass1234' } });
  const tokenA = a.json.token; const tokenB = b.json.token;
  const created = await req('POST', '/api/couple/create', { token: tokenA });
  await req('POST', '/api/couple/join', { token: tokenB, body: { inviteCode: created.json.couple.inviteCode } });

  console.log('\n1. 公共表情包出现在 /api/emoji/packs');
  const packs = await req('GET', '/api/emoji/packs', { token: tokenA });
  ok(packs.status === 200, '列表返回 200');
  const list = packs.json.packs || [];
  const pub = list.filter((p) => p.isPublic);
  ok(pub.length === 2, `识别出 2 个公共包（实际 ${pub.length}）`, JSON.stringify(list.map((p) => `${p.name}:${p.isPublic}`)));
  const classic = pub.find((p) => p.name === '经典表情');
  const pets = pub.find((p) => p.name === 'pets');
  ok(!!classic, '含「经典表情」包');
  ok(!!pets, '含「pets」包');
  ok(classic?.items?.length === 2, `「经典表情」含 2 张（实际 ${classic?.items?.length}）`);
  ok(pets?.items?.length === 1, `「pets」仅含 1 张合法图片（实际 ${pets?.items?.length}）`);
  const item = classic.items[0];
  ok(/^e_[A-Za-z0-9]+$/.test(item.id), `公共表情 id 合法：${item.id}`);
  ok(item.mime === 'image/png', `mime 为 image/png（${item.mime}）`);
  ok(item.size > 0, 'size 大于 0');
  ok(item.url === `/api/emoji/blob/${item.id}`, `url 正确（${item.url}）`);
  // 顺序由文件名排序决定，这里只断言两个名字都在（不依赖具体顺序）
  const names = classic.items.map((i) => i.name).sort();
  ok(JSON.stringify(names) === JSON.stringify(['大笑', '哭泣'].sort()),
    `两个表情的 name 为文件名去扩展名（实际 ${JSON.stringify(names)}）`);

  console.log('\n2. 公共表情二进制可访问（任意已登录用户）');
  // 按 name 找到「大笑」，避免依赖排序
  const dx = classic.items.find((i) => i.name === '大笑');
  const blob = await req('GET', `/api/emoji/blob/${dx.id}?token=${encodeURIComponent(tokenB)}`);
  ok(blob.status === 200, `blob 返回 200（实际 ${blob.status}）`);
  ok(blob.ct.includes('image/png'), `Content-Type 正确（${blob.ct}）`);
  ok(blob.buf.length === dx.size, `字节数一致（${blob.buf.length} vs ${dx.size}）`);
  ok(blob.buf[0] === 0x89 && blob.buf[1] === 0x50, '返回的是真实 PNG');
  const expected = fs.readFileSync(path.join(PUBLIC_DIR, '经典表情', '大笑.png'));
  ok(blob.buf.equals(expected), '「大笑」的返回内容与磁盘文件字节完全一致');

  // 再验证另一个表情对应另一个文件（确认 id → 文件映射没有串位）
  const kq = classic.items.find((i) => i.name === '哭泣');
  const blob2 = await req('GET', `/api/emoji/blob/${kq.id}?token=${encodeURIComponent(tokenA)}`);
  const expected2 = fs.readFileSync(path.join(PUBLIC_DIR, '经典表情', '哭泣.png'));
  ok(blob2.buf.equals(expected2), '「哭泣」的返回内容对应哭泣.png（映射无串位）');
  ok(blob.buf.equals(expected2) === false, '两个表情的内容确实不同');

  const petsItem = pets.items[0];
  const petBlob = await req('GET', `/api/emoji/blob/${petsItem.id}?token=${encodeURIComponent(tokenA)}`);
  ok(petBlob.status === 200, '第二个公共包的表情也可访问');
  ok(petBlob.buf.equals(fs.readFileSync(path.join(PUBLIC_DIR, 'pets', 'cat.png'))), '内容对应 cat.png');

  console.log('\n3. 未登录不能访问');
  const noAuth = await req('GET', `/api/emoji/blob/${item.id}`);
  ok(noAuth.status === 401, `无凭证返回 401（实际 ${noAuth.status}）`);

  console.log('\n4. 非法文件被跳过（fake.png 不是真 PNG）');
  const fakeId = pets.items.find((i) => i.name === 'fake');
  ok(!fakeId, 'fake.png 未进入数据库');
  ok(!pets.items.some((i) => i.name === 'bad'), 'bad.txt 未被收录');

  console.log('\n5. 不同情侣空间互相隔离仍生效');
  const s2 = crypto.randomBytes(3).toString('hex');
  const c1 = await req('POST', '/api/auth/register', { body: { username: `iso_${s2}`, password: 'pass1234' } });
  const c2 = await req('POST', '/api/auth/register', { body: { username: `iso2_${s2}`, password: 'pass1234' } });
  const cpl = await req('POST', '/api/couple/create', { token: c1.json.token });
  await req('POST', '/api/couple/join', { token: c2.json.token, body: { inviteCode: cpl.json.couple.inviteCode } });
  // 另一对用户建一个私有包并上传
  const priv = await req('POST', '/api/emoji/packs', { token: c1.json.token, body: { name: '私有包' } });
  const png = makePng(8, 99);
  const uploadPriv = await req('POST', '/api/emoji/items', { token: c1.json.token,
    body: { packId: priv.json.pack.id, mime: 'image/png', name: '私图', dataBase64: png.toString('base64') } });
  ok(uploadPriv.status === 200, '另一对用户上传私有表情成功');
  const privId = uploadPriv.json.item.id;
  const cross = await req('GET', `/api/emoji/blob/${privId}?token=${encodeURIComponent(tokenA)}`);
  ok(cross.status === 403, `非同情侣空间用户访问私有表情被拒（403，实际 ${cross.status}）`);
  const own = await req('GET', `/api/emoji/blob/${privId}?token=${encodeURIComponent(c1.json.token)}`);
  ok(own.status === 200, '本人可访问自己的私有表情');
  const pubCross = await req('GET', `/api/emoji/blob/${item.id}?token=${encodeURIComponent(c1.json.token)}`);
  ok(pubCross.status === 200, '任何已登录用户都能访问公共表情');

  console.log('\n6. 公共包不可删除、不可上传');
  const delPub = await req('DELETE', `/api/emoji/packs/${classic.id}`, { token: tokenA });
  ok(delPub.status === 403, `删除公共包被拒（403，实际 ${delPub.status}）`);
  const upPub = await req('POST', '/api/emoji/items', { token: tokenA,
    body: { packId: classic.id, mime: 'image/png', dataBase64: makePng(8).toString('base64') } });
  ok(upPub.status === 403, `向公共包上传被拒（403，实际 ${upPub.status}）`);

  // 公共包内的表情不允许被删除（DELETE /api/emoji/items/:id）
  console.log('\n6.1 公共表情不可删除');
  for (const pubItem of classic.items) {
    const delItem = await req('DELETE', `/api/emoji/items/${pubItem.id}`, { token: tokenA });
    ok(delItem.status === 403 && delItem.json?.error === 'forbidden',
      `删除公共表情 ${pubItem.id} 返回 403 forbidden`,
      `实际 ${delItem.status} ${delItem.json?.error}`);
  }
  // 确认公共表情仍然可用（没有被误删）
  const stillThere = await req('GET', `/api/emoji/blob/${classic.items[0].id}?token=${encodeURIComponent(tokenA)}`);
  ok(stillThere.status === 200, '被拒绝删除后公共表情依然可访问（200）', `实际 ${stillThere.status}`);
  const packsAfter = await req('GET', '/api/emoji/packs', { token: tokenA });
  const classicAfter = (packsAfter.json.packs || []).find((p) => p.name === '经典表情');
  ok(classicAfter?.items?.length === 2, '公共包条目数未变（仍为 2）', String(classicAfter?.items?.length));

  console.log('\n7. 重启后幂等（不重复、不丢失）');
  child.kill('SIGTERM');
  await sleep(1500);
  try { child.kill('SIGKILL'); } catch { /* 忽略 */ }
  try { fs.closeSync(fd); } catch { /* 忽略 */ }

  fd = fs.openSync(LOG, 'a');
  child = spawn(process.execPath, ['src/index.js'], {
    cwd: SERVER_DIR,
    env: { ...process.env, PORT: String(PORT), DATA_DIR, JWT_SECRET: crypto.randomBytes(48).toString('hex'),
      ADMIN_PASSWORD: 'pub123', LOG_LEVEL: 'info', DOTENV_CONFIG_PATH: path.join(SERVER_DIR, '.env.nonexistent') },
    stdio: ['ignore', fd, fd],
  });
  const dl2 = Date.now() + 25000; let up2 = false;
  while (Date.now() < dl2) { try { if ((await fetch(`${BASE}/api/health`)).ok) { up2 = true; break; } } catch { /* 继续 */ } await sleep(200); }
  ok(up2, '重启成功');
  // 注意：JWT_SECRET 变了，旧 token 失效，需重新登录
  const a2 = await req('POST', '/api/auth/login', { body: { username: a.json.user.username, password: 'pass1234' } });
  const packs2 = await req('GET', '/api/emoji/packs', { token: a2.json.token });
  const pub2 = (packs2.json.packs || []).filter((p) => p.isPublic);
  ok(pub2.length === 2, `重启后仍是 2 个公共包（实际 ${pub2.length}）`);
  ok(pub2.find((p) => p.name === '经典表情')?.items?.length === 2, '重启后条目数不变（无重复）');
  const totalItems = (packs2.json.packs || []).filter((p) => p.isPublic).reduce((n, p) => n + p.items.length, 0);
  ok(totalItems === 3, `公共表情总数仍为 3（实际 ${totalItems}）`);
} catch (e) {
  console.log('异常：', e.message);
  failed++;
} finally {
  try { child?.kill('SIGTERM'); } catch { /* 忽略 */ }
  await sleep(1200);
  try { child?.kill('SIGKILL'); } catch { /* 忽略 */ }
  if (fd !== null) { try { fs.closeSync(fd); } catch { /* 忽略 */ } }
  cleanupPublicPacks();
  fs.rmSync(DATA_DIR_ABS, { recursive: true, force: true });
  fs.rmSync(LOG, { force: true });
  console.log('\n========================================');
  console.log(failed === 0 ? `  ✅ 公共表情包验证通过  共 ${passed} 项` : `  ❌ 失败 ${failed} / 通过 ${passed}`);
  console.log('========================================');
  process.exit(failed === 0 ? 0 : 1);
}
