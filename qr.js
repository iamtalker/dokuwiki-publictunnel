/*
 * 아주 작은 QR 코드 만들기(바이트 모드, 오류 정정 L, 버전 1~10 = 최대 271바이트).
 * 외부 서비스를 쓰지 않는다: OTP 설정 키가 이 컴퓨터를 떠나지 않는다.
 * 알고리즘은 ISO/IEC 18004 를 따르며, 구조는 Project Nayuki 의 QR Code generator(MIT)를 참고했다.
 *   ptQrMatrix(text) -> 2차원 불리언 배열
 *   ptQrSvg(text, px) -> SVG 문자열(흰 바탕, 여백 포함)
 */
(function (root) {
  'use strict';
  var ECC_PER_BLOCK = [0, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18];   // 버전 1..10, 수준 L
  var NUM_BLOCKS = [0, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4];

  function rawModules(ver) {
    var r = (16 * ver + 128) * ver + 64;
    if (ver >= 2) {
      var n = Math.floor(ver / 7) + 2;
      r -= (25 * n - 10) * n - 55;
      if (ver >= 7) r -= 36;
    }
    return r;
  }
  function gfMul(x, y) {
    var z = 0;
    for (var i = 7; i >= 0; i--) {
      z = (z << 1) ^ ((z >>> 7) * 0x11D);
      z ^= ((y >>> i) & 1) * x;
    }
    return z;
  }
  function rsDivisor(deg) {
    var res = [], i, j, root = 1;
    for (i = 0; i < deg; i++) res.push(0);
    res[deg - 1] = 1;
    for (i = 0; i < deg; i++) {
      for (j = 0; j < deg; j++) {
        res[j] = gfMul(res[j], root);
        if (j + 1 < deg) res[j] ^= res[j + 1];
      }
      root = gfMul(root, 2);
    }
    return res;
  }
  function rsRemainder(data, div) {
    var res = div.map(function () { return 0; });
    data.forEach(function (b) {
      var f = b ^ res.shift();
      res.push(0);
      div.forEach(function (c, i) { res[i] ^= gfMul(c, f); });
    });
    return res;
  }
  function utf8(str) {
    var s = unescape(encodeURIComponent(str)), out = [];
    for (var i = 0; i < s.length; i++) out.push(s.charCodeAt(i));
    return out;
  }

  function ptQrMatrix(text) {
    var bytes = utf8(text), ver, dataCap;
    for (ver = 1; ver <= 10; ver++) {
      var total = Math.floor(rawModules(ver) / 8);
      dataCap = total - ECC_PER_BLOCK[ver] * NUM_BLOCKS[ver];
      var need = 4 + (ver < 10 ? 8 : 16) + 8 * bytes.length;
      if (need <= dataCap * 8) break;
    }
    if (ver > 10) throw new Error('too long for QR');

    // 1) 데이터 비트
    var bits = [];
    function put(val, len) { for (var i = len - 1; i >= 0; i--) bits.push((val >>> i) & 1); }
    put(4, 4);
    put(bytes.length, ver < 10 ? 8 : 16);
    bytes.forEach(function (b) { put(b, 8); });
    put(0, Math.min(4, dataCap * 8 - bits.length));
    put(0, (8 - bits.length % 8) % 8);
    for (var pad = 0xEC; bits.length < dataCap * 8; pad ^= 0xEC ^ 0x11) put(pad, 8);
    var data = [];
    for (var i = 0; i < bits.length; i += 8) {
      var v = 0;
      for (var k = 0; k < 8; k++) v = (v << 1) | bits[i + k];
      data.push(v);
    }

    // 2) 블록 나누기 + 오류 정정 + 끼워 넣기
    var nb = NUM_BLOCKS[ver], eccLen = ECC_PER_BLOCK[ver], raw = Math.floor(rawModules(ver) / 8);
    var nShort = nb - raw % nb, shortLen = Math.floor(raw / nb);
    var blocks = [], div = rsDivisor(eccLen), off = 0;
    for (i = 0; i < nb; i++) {
      var dl = shortLen - eccLen + (i < nShort ? 0 : 1);
      var d = data.slice(off, off + dl);
      off += dl;
      var ecc = rsRemainder(d, div);
      if (i < nShort) d.push(0);                   // 자리 맞추기(끼워 넣을 때 건너뜀)
      blocks.push(d.concat(ecc));
    }
    var all = [];
    for (i = 0; i < blocks[0].length; i++) {
      blocks.forEach(function (blk, j) {
        if (i !== shortLen - eccLen || j >= nShort) all.push(blk[i]);
      });
    }

    // 3) 모양 그리기
    var size = ver * 4 + 17, m = [], fn = [], x, y;
    for (y = 0; y < size; y++) {
      m.push([]); fn.push([]);
      for (x = 0; x < size; x++) { m[y].push(false); fn[y].push(false); }
    }
    function set(x, y, c) { m[y][x] = c; fn[y][x] = true; }
    for (i = 0; i < size; i++) { set(6, i, i % 2 === 0); set(i, 6, i % 2 === 0); }
    function finder(cx, cy) {
      for (var dy = -4; dy <= 4; dy++) for (var dx = -4; dx <= 4; dx++) {
        var dist = Math.max(Math.abs(dx), Math.abs(dy)), xx = cx + dx, yy = cy + dy;
        if (xx >= 0 && xx < size && yy >= 0 && yy < size) set(xx, yy, dist !== 2 && dist !== 4);
      }
    }
    finder(3, 3); finder(size - 4, 3); finder(3, size - 4);
    var pos = [];
    if (ver > 1) {
      var na = Math.floor(ver / 7) + 2;
      var step = Math.ceil((ver * 4 + 4) / (na * 2 - 2)) * 2;
      pos = [6];
      for (var p = size - 7; pos.length < na; p -= step) pos.splice(1, 0, p);
    }
    pos.forEach(function (ax, ia) {
      pos.forEach(function (ay, ja) {
        if ((ia === 0 && ja === 0) || (ia === 0 && ja === pos.length - 1) || (ia === pos.length - 1 && ja === 0)) return;
        for (var dy = -2; dy <= 2; dy++) for (var dx = -2; dx <= 2; dx++) {
          set(ax + dx, ay + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
        }
      });
    });
    function drawFormat(mask) {
      var d = (1 << 3) | mask, r = d;                       // 수준 L 의 형식 비트는 01
      for (var q = 0; q < 10; q++) r = (r << 1) ^ ((r >>> 9) * 0x537);
      var f = ((d << 10) | r) ^ 0x5412, q2;
      function b(n) { return ((f >>> n) & 1) !== 0; }
      for (q2 = 0; q2 <= 5; q2++) set(8, q2, b(q2));
      set(8, 7, b(6)); set(8, 8, b(7)); set(7, 8, b(8));
      for (q2 = 9; q2 < 15; q2++) set(14 - q2, 8, b(q2));
      for (q2 = 0; q2 < 8; q2++) set(size - 1 - q2, 8, b(q2));
      for (q2 = 8; q2 < 15; q2++) set(8, size - 15 + q2, b(q2));
      set(8, size - 8, true);
    }
    drawFormat(0);
    if (ver >= 7) {
      var r2 = ver;
      for (i = 0; i < 12; i++) r2 = (r2 << 1) ^ ((r2 >>> 11) * 0x1F25);
      var vb = (ver << 12) | r2;
      for (i = 0; i < 18; i++) {
        var c = ((vb >>> i) & 1) !== 0, a = size - 11 + i % 3, bb = Math.floor(i / 3);
        set(a, bb, c); set(bb, a, c);
      }
    }

    // 4) 데이터 칸 채우기(지그재그)
    var idx = 0, right, vert, j2;
    for (right = size - 1; right >= 1; right -= 2) {
      if (right === 6) right = 5;
      for (vert = 0; vert < size; vert++) {
        for (j2 = 0; j2 < 2; j2++) {
          x = right - j2;
          y = (((right + 1) & 2) === 0) ? size - 1 - vert : vert;
          if (!fn[y][x] && idx < all.length * 8) {
            m[y][x] = ((all[idx >>> 3] >>> (7 - (idx & 7))) & 1) !== 0;
            idx++;
          }
        }
      }
    }

    // 5) 마스크 고르기(벌점이 가장 낮은 것)
    function masked(mask) {
      var out = m.map(function (row) { return row.slice(); });
      for (var yy = 0; yy < size; yy++) for (var xx = 0; xx < size; xx++) {
        var inv;
        switch (mask) {
          case 0: inv = (xx + yy) % 2 === 0; break;
          case 1: inv = yy % 2 === 0; break;
          case 2: inv = xx % 3 === 0; break;
          case 3: inv = (xx + yy) % 3 === 0; break;
          case 4: inv = (Math.floor(xx / 3) + Math.floor(yy / 2)) % 2 === 0; break;
          case 5: inv = xx * yy % 2 + xx * yy % 3 === 0; break;
          case 6: inv = (xx * yy % 2 + xx * yy % 3) % 2 === 0; break;
          default: inv = ((xx + yy) % 2 + xx * yy % 3) % 2 === 0;
        }
        if (!fn[yy][xx] && inv) out[yy][xx] = !out[yy][xx];
      }
      return out;
    }
    function penalty(g) {
      var s = 0, a, b, run, dark = 0;
      for (a = 0; a < size; a++) {
        for (var dir = 0; dir < 2; dir++) {
          run = 1;
          for (b = 1; b < size; b++) {
            var cur = dir ? g[b][a] : g[a][b], prev = dir ? g[b - 1][a] : g[a][b - 1];
            if (cur === prev) { run++; if (run === 5) s += 3; else if (run > 5) s++; } else run = 1;
          }
        }
      }
      for (a = 0; a < size - 1; a++) for (b = 0; b < size - 1; b++) {
        if (g[a][b] === g[a][b + 1] && g[a][b] === g[a + 1][b] && g[a][b] === g[a + 1][b + 1]) s += 3;
      }
      for (a = 0; a < size; a++) for (b = 0; b < size; b++) if (g[a][b]) dark++;
      s += Math.floor(Math.abs(dark * 20 - size * size * 10) / (size * size)) * 10;
      return s;
    }
    var best = 0, bestScore = Infinity, bestGrid = null;
    for (var mk = 0; mk < 8; mk++) {
      var g = masked(mk), sc = penalty(g);
      if (sc < bestScore) { bestScore = sc; best = mk; bestGrid = g; }
    }
    m = bestGrid;
    drawFormat(best);
    return m;
  }

  function ptQrSvg(text, px) {
    var g = ptQrMatrix(text), n = g.length, q = 4, d = '', x, y;
    for (y = 0; y < n; y++) for (x = 0; x < n; x++) if (g[y][x]) d += 'M' + (x + q) + ' ' + (y + q) + 'h1v1h-1z';
    var s = n + q * 2;
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + s + ' ' + s + '" width="' + (px || 220) + '" height="' + (px || 220) + '" shape-rendering="crispEdges">'
      + '<rect width="100%" height="100%" fill="#fff"/><path d="' + d + '" fill="#000"/></svg>';
  }

  root.ptQrMatrix = ptQrMatrix;
  root.ptQrSvg = ptQrSvg;
})(typeof window !== 'undefined' ? window : this);
