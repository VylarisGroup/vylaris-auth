// Kopiert nur die benötigten Boxicons-Dateien (CSS + Webfonts) nach public/assets/boxicons.
const fs = require('fs');
const path = require('path');
const src = path.join(__dirname, '..', 'node_modules', 'boxicons');
const dst = path.join(__dirname, '..', 'public', 'assets', 'boxicons');
fs.mkdirSync(path.join(dst, 'css'), { recursive: true });
fs.mkdirSync(path.join(dst, 'fonts'), { recursive: true });
fs.copyFileSync(path.join(src, 'css', 'boxicons.min.css'), path.join(dst, 'css', 'boxicons.min.css'));
for (const f of fs.readdirSync(path.join(src, 'fonts'))) {
  if (/\.(woff2?|ttf)$/.test(f)) fs.copyFileSync(path.join(src, 'fonts', f), path.join(dst, 'fonts', f));
}
fs.copyFileSync(path.join(src, 'LICENSE'), path.join(dst, 'LICENSE'));
