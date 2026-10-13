#!/usr/bin/env node
/**
 * 生成 App 图标（1024x1024 PNG）。
 * 纯 Node 实现：自己写 PNG 编码器（zlib + CRC32），不依赖任何 npm 包。
 * 用法：node tools/make-icon.mjs
 */

import zlib from 'node:zlib';
import fs from 'node:fs';
import path from 'node:path';

const SIZE = 1024;
const SUPERSAMPLE = 3; // 每个像素 3x3 超采样，做抗锯齿

// ---------------- PNG 编码 ----------------

const crcTable = (() => {
  const table = new Int32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) {
      c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    }
    table[n] = c;
  }
  return table;
})();

function crc32(buffer) {
  let c = 0xffffffff;
  for (let i = 0; i < buffer.length; i++) {
    c = crcTable[(c ^ buffer[i]) & 0xff] ^ (c >>> 8);
  }
  return (c ^ 0xffffffff) >>> 0;
}

function chunk(type, data) {
  const length = Buffer.alloc(4);
  length.writeUInt32BE(data.length, 0);
  const typeBuffer = Buffer.from(type, 'ascii');
  const crcBuffer = Buffer.alloc(4);
  crcBuffer.writeUInt32BE(crc32(Buffer.concat([typeBuffer, data])), 0);
  return Buffer.concat([length, typeBuffer, data, crcBuffer]);
}

/** rgb: Buffer，长度 = width*height*3 */
function encodePNG(width, height, rgb) {
  const signature = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);

  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(width, 0);
  ihdr.writeUInt32BE(height, 4);
  ihdr[8] = 8; // bit depth
  ihdr[9] = 2; // color type: truecolor RGB
  ihdr[10] = 0; // compression
  ihdr[11] = 0; // filter
  ihdr[12] = 0; // interlace

  // 每行前面加一个 filter 字节（0 = None）
  const stride = width * 3;
  const raw = Buffer.alloc((stride + 1) * height);
  for (let y = 0; y < height; y++) {
    raw[y * (stride + 1)] = 0;
    rgb.copy(raw, y * (stride + 1) + 1, y * stride, (y + 1) * stride);
  }

  const idat = zlib.deflateSync(raw, { level: 9 });

  return Buffer.concat([
    signature,
    chunk('IHDR', ihdr),
    chunk('IDAT', idat),
    chunk('IEND', Buffer.alloc(0))
  ]);
}

// ---------------- 图形 ----------------

const lerp = (a, b, t) => a + (b - a) * t;
const clamp01 = (v) => (v < 0 ? 0 : v > 1 ? 1 : v);

/** 经典心形隐函数：(x²+y²-1)³ - x²y³ <= 0，y 轴向上 */
function insideHeart(x, y) {
  const a = x * x + y * y - 1;
  return a * a * a - x * x * y * y * y <= 0;
}

/** 圆角矩形范围（用于背景渐变遮罩，图标本身由系统切圆角，这里铺满即可） */
function background(x, y) {
  // 对角渐变：粉 -> 紫
  const t = clamp01((x + y) / 2);
  const r = lerp(255, 140, t) / 255;
  const g = lerp(92, 92, t) / 255;
  const b = lerp(138, 255, t) / 255;
  return [r, g, b];
}

function render() {
  const stride = SIZE * 3;
  const rgb = Buffer.alloc(stride * SIZE);
  const samples = SUPERSAMPLE * SUPERSAMPLE;

  for (let py = 0; py < SIZE; py++) {
    for (let px = 0; px < SIZE; px++) {
      let r = 0;
      let g = 0;
      let b = 0;

      for (let sy = 0; sy < SUPERSAMPLE; sy++) {
        for (let sx = 0; sx < SUPERSAMPLE; sx++) {
          const fx = (px + (sx + 0.5) / SUPERSAMPLE) / SIZE; // 0..1
          const fy = (py + (sy + 0.5) / SUPERSAMPLE) / SIZE;

          // 背景
          const bg = background(fx, fy);
          let cr = bg[0];
          let cg = bg[1];
          let cb = bg[2];

          // 心形（坐标映射到 [-1,1]，并稍微上移）
          const hx = (fx - 0.5) * 2 / 0.62;
          const hy = -(fy - 0.52) * 2 / 0.62;
          if (insideHeart(hx, hy)) {
            // 白色心形 + 轻微阴影过渡
            cr = 1;
            cg = 1;
            cb = 1;
          } else {
            // 心形外圈柔光
            const hx2 = hx * 1.06;
            const hy2 = hy * 1.06;
            if (insideHeart(hx2, hy2)) {
              cr = lerp(cr, 1, 0.45);
              cg = lerp(cg, 1, 0.45);
              cb = lerp(cb, 1, 0.45);
            }
          }

          r += cr;
          g += cg;
          b += cb;
        }
      }

      const offset = py * stride + px * 3;
      rgb[offset] = Math.round((r / samples) * 255);
      rgb[offset + 1] = Math.round((g / samples) * 255);
      rgb[offset + 2] = Math.round((b / samples) * 255);
    }
  }

  return rgb;
}

const outPath = path.join(
  process.cwd(),
  'ios',
  'Sources',
  'Resources',
  'Assets.xcassets',
  'AppIcon.appiconset',
  'AppIcon-1024.png'
);

const png = encodePNG(SIZE, SIZE, render());
fs.mkdirSync(path.dirname(outPath), { recursive: true });
fs.writeFileSync(outPath, png);
console.log('已生成图标：' + outPath + '（' + Math.round(png.length / 1024) + ' KB）');
