// ホーム画面に追加（インストール）できるようにするための最小限のService Worker。
// データは常にサーバーから取るので、キャッシュはアイコン・CSS・JSだけ。
const CACHE = 'recipes-v8';
const IMG_CACHE = 'recipes-img-v1';   // 画像は名前が変わらない（変更すると別名になる）ので、一度見たら通信しない
const IMG_MAX = 200;
const ASSETS = ['icons/icon-192.png', 'icons/icon-512.png', 'icons/favicon-64.png'];
self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(ASSETS)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE && k !== IMG_CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin) return;
  if (/image\.php$/.test(url.pathname) && url.searchParams.get('s')) {
    // サムネイル・詳細用の小さい画像だけキャッシュ（原寸は保存しない）
    e.respondWith(caches.open(IMG_CACHE).then(async c => {
      const hit = await c.match(e.request);
      if (hit) return hit;
      const res = await fetch(e.request);
      if (res.ok) {
        c.put(e.request, res.clone());
        c.keys().then(ks => { if (ks.length > IMG_MAX) ks.slice(0, ks.length - IMG_MAX).forEach(k => c.delete(k)); });
      }
      return res;
    }));
    return;
  }
  if (/\/icons\//.test(url.pathname)) {
    e.respondWith(caches.match(e.request).then(r => r || fetch(e.request)));
  }
});
