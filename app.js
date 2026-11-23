(() => {
  'use strict';
  const $ = (s, el = document) => el.querySelector(s);
  const CSRF = $('meta[name="csrf-token"]').content;

  const state = {
    me: null,
    mode: 'mine',        // 'mine' = 自分のレシピ / 'inbox' = 共有されたレシピ
    recipes: [],
    categories: [],
    inbox: [],
    filter: new Set(),   // 選択中カテゴリ（AND条件）
    query: '',
    current: null,       // 詳細表示中のレシピ
    visible: [],         // いま一覧に出ているレシピ（前後移動に使う）
    edit: null,          // { id, items:[{kind:'old',name}|{kind:'new',blob,url}], cats:Set }
  };

  // ---------- API ----------
  async function api(action, data) {
    const opt = { headers: { 'X-CSRF-Token': CSRF } };
    if (data) {
      opt.method = 'POST';
      if (data instanceof FormData) opt.body = data;
      else { const fd = new FormData(); for (const k in data) fd.append(k, data[k]); opt.body = fd; }
    }
    const res = await fetch('api.php?action=' + action, opt);
    const text = await res.text();
    let json;
    try { json = JSON.parse(text); }
    catch { throw new Error('サーバーエラー（' + res.status + '）\n' + text.replace(/<[^>]+>/g, '').trim().slice(0, 300)); }
    if (!res.ok || json.error) {
      if (res.status === 401) location.reload();
      throw new Error(json.error || 'エラーが発生しました');
    }
    return json;
  }
  const imgUrl = (name, owner) => 'image.php?o=' + encodeURIComponent(owner || state.me.id) + '&f=' + encodeURIComponent(name);
  const thumbUrl = (name, owner) => imgUrl(name, owner) + '&s=t';
  // 画像の役割：1枚目=材料、2枚目=作り方、3枚目=表紙、4枚目以降=イメージ
  const ROLES = ['材料', '作り方', '表紙'];
  const roleOf = i => ROLES[i] || 'イメージ';
  const coverOf = imgs => imgs[2] || imgs.find(Boolean);
  const isPC = matchMedia('(hover: hover) and (pointer: fine)').matches;
  const catName = (id) => (state.categories.find(c => c.id === id) || {}).name;

  // メモ内のURLをリンクにする（それ以外はテキストのまま）
  function linkify(text) {
    const out = [];
    let last = 0;
    for (const m of text.matchAll(/https?:\/\/[^\s<>"]+/g)) {
      if (m.index > last) out.push(text.slice(last, m.index));
      out.push(el('a', { href: m[0], target: '_blank', rel: 'noopener' }, m[0]));
      last = m.index + m[0].length;
    }
    if (last < text.length) out.push(text.slice(last));
    return out;
  }
  const LEVELS = ['', 'やさしい', 'ふつう', 'むずかしい'];
  const starsEl = (n, small) => el('span', { class: 'stars' + (small ? ' sm' : ''), 'aria-label': `おすすめ度${n}` },
    '★'.repeat(n), el('span', { class: 'off' }, '★'.repeat(5 - n)));
  const levelEl = n => el('span', { class: 'lv-badge lv' + n }, LEVELS[n]);
  function toast(msg) {
    const t = $('#toast');
    t.textContent = msg; t.hidden = false;
    clearTimeout(toast.tid); toast.tid = setTimeout(() => t.hidden = true, 2200);
  }
  function el(tag, attrs = {}, ...children) {
    const e = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (k === 'class') e.className = v;
      else if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
      else if (v !== false && v != null) e.setAttribute(k, v === true ? '' : v);
    }
    for (const c of children.flat()) if (c != null) e.append(c);
    return e;
  }

  // ---------- 一覧 ----------
  async function load() {
    const d = await api('list');
    state.me = d.me;
    state.recipes = d.recipes;
    state.categories = d.categories;
    state.inbox = d.inbox;
    state.webpOk = !!d.webp_ok;
    state.settings = d.settings || null;
    state.userCount = d.user_count || 0;
    if (state.mode === 'inbox' && !state.inbox.length) state.mode = 'mine';
    const unseen = state.inbox.filter(x => !x.seen).length;
    $('#inboxDot').hidden = !unseen;
    for (const id of [...state.filter]) if (!catName(id)) state.filter.delete(id);
    render();
  }

  // 一覧に出すもの（自分のレシピ or 共有されたレシピ）を同じ形にそろえる
  function items() {
    if (state.mode === 'inbox') {
      return state.inbox.map(x => ({
        id: x.share_id, title: x.recipe.title, memo: x.recipe.memo || '', level: x.recipe.level || 0, rating: x.recipe.rating || 0, images: x.recipe.images, owner: x.from.id,
        catNames: x.recipe.category_names, from: x.from, recipeId: x.recipe.id, categories: [],
      }));
    }
    return state.recipes.map(r => Object.assign({}, r, { owner: state.me.id, catNames: r.categories.map(catName).filter(Boolean) }));
  }

  function render() {
    renderFilterBar();
    document.body.classList.toggle('inbox-mode', state.mode === 'inbox');
    const q = state.query.trim().toLowerCase();
    const all = items();
    const list = all.filter(r =>
      (!q || r.title.toLowerCase().includes(q) || (r.memo || '').toLowerCase().includes(q) || (r.from && r.from.name.toLowerCase().includes(q))) &&
      (state.mode === 'inbox' || [...state.filter].every(id => r.categories.includes(id)))
    );
    state.visible = list;
    const grid = $('#grid');
    grid.replaceChildren(...list.map(card));
    $('#empty').hidden = all.length > 0;
    const label = state.mode === 'inbox' ? '共有されたレシピ ' : '';
    $('#count').textContent = all.length
      ? label + (list.length === all.length ? `${list.length}件` : `${list.length}件 / 全${all.length}件`)
      : '';
  }

  function renderFilterBar() {
    const counts = {};
    for (const r of state.recipes) for (const c of r.categories) counts[c] = (counts[c] || 0) + 1;
    const bar = $('#filterBar');
    if (state.mode === 'inbox') {
      bar.replaceChildren(
        el('button', { class: 'fchip', onclick: () => setMode('mine') }, '‹ 自分のレシピ'),
        el('button', { class: 'fchip on' }, '共有された', el('span', { class: 'n' }, String(state.inbox.length)))
      );
      return;
    }
    const unseen = state.inbox.filter(x => !x.seen).length;
    const inboxChip = state.inbox.length
      ? el('button', { class: 'fchip inbox-chip' + (unseen ? ' new' : ''), onclick: () => setMode('inbox') },
          '共有された', el('span', { class: 'n' }, unseen ? `${unseen}件 new` : String(state.inbox.length)))
      : null;
    const all = el('button', { class: 'fchip' + (state.filter.size ? '' : ' on'), onclick: () => { state.filter.clear(); render(); } }, 'すべて');
    const chips = state.categories.map((c, i) =>
      el('button', {
        class: 'fchip' + (state.filter.has(c.id) ? ' on' : ''),
        onclick: () => { state.filter.has(c.id) ? state.filter.delete(c.id) : state.filter.add(c.id); render(); },
      }, i < 9 ? el('span', { class: 'k' }, String(i + 1)) : null, c.name, el('span', { class: 'n' }, String(counts[c.id] || 0)))
    );
    const manage = el('button', { class: 'fchip ghost', onclick: openCatManager }, '⚙ カテゴリ');
    bar.replaceChildren(...[all, ...chips, manage, inboxChip].filter(Boolean));
  }

  function setMode(m) {
    state.mode = m;
    state.query = ''; $('#q').value = '';
    render();
    window.scrollTo(0, 0);
    if (m === 'inbox' && state.inbox.some(x => !x.seen)) {
      state.inbox.forEach(x => x.seen = true);
      $('#inboxDot').hidden = true;
      api('inbox_seen', {}).catch(() => {});
    }
  }

  function card(r) {
    return el('button', { class: 'card', onclick: () => openView(r.id) },
      el('div', { class: 'thumb' },
        el('img', { src: thumbUrl(coverOf(r.images), r.owner), loading: 'lazy', decoding: 'async', alt: '' }),
        r.images.filter(Boolean).length > 1 ? el('span', { class: 'badge' }, `${r.images.filter(Boolean).length}枚`) : null
      ),
      el('div', { class: 'meta' },
        r.from ? el('p', { class: 'from' }, r.from.name + ' さんから') : null,
        (r.level || r.rating) ? el('div', { class: 'meta-row sm' }, r.level ? levelEl(r.level) : null, r.rating ? starsEl(r.rating, true) : null) : null,
        el('p', { class: 'title' }, r.title),
        el('div', { class: 'chips' }, r.catNames.map(n => el('span', { class: 'chip' }, n)))
      )
    );
  }

  // ---------- モーダル ----------
  function openModal(id) { $(id).hidden = false; document.body.style.overflow = 'hidden'; }
  function closeModal(id) {
    $(id).hidden = true;
    if ([...document.querySelectorAll('.modal')].every(m => m.hidden)) document.body.style.overflow = '';
  }
  document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', e => {
      if (e.target === m || e.target.closest('[data-close]')) {
        if (m.id === 'editModal') cleanupEdit();
        closeModal('#' + m.id);
      }
    });
  });

  // ---------- 詳細 ----------
  function openView(id, dir) {
    const r = items().find(x => x.id === id);
    if (!r) return;
    state.current = r;
    const idx = state.visible.findIndex(x => x.id === id);
    $('#prevBtn').disabled = idx <= 0;
    $('#nextBtn').disabled = idx < 0 || idx >= state.visible.length - 1;
    $('#viewPos').textContent = idx >= 0 ? `${idx + 1} / ${state.visible.length}` : '';
    const body = $('#viewModal .view-body');
    body.classList.remove('slide-l', 'slide-r');
    if (dir) { void body.offsetWidth; body.classList.add(dir > 0 ? 'slide-l' : 'slide-r'); }
    $('#viewTitle').textContent = r.title;
    $('#viewFrom').textContent = r.from ? `${r.from.name} さんから共有されたレシピ` : '';
    $('#viewMeta').replaceChildren(...[r.level ? levelEl(r.level) : null, r.rating ? starsEl(r.rating) : null].filter(Boolean));
    $('#viewMemo').hidden = !r.memo;
    $('#viewMemoText').replaceChildren(...linkify(r.memo || ''));
    $('#viewCats').replaceChildren(...r.catNames.map(n => el('span', { class: 'chip' }, n)));
    $('#viewImages').replaceChildren(...r.images.flatMap((n, i) => !n ? [] :
      el('figure', { class: 'img-block' },
        el('figcaption', { class: 'img-role r' + Math.min(i, 3) }, roleOf(i)),
        el('img', { src: imgUrl(n, r.owner), alt: roleOf(i), loading: i > 1 ? 'lazy' : 'eager', decoding: 'async', onclick: () => openLightbox(imgUrl(n, r.owner)) }))
    ));
    $('#viewModal .view-body').scrollTop = 0;
    openModal('#viewModal');
  }
  function step(d) {
    if (!state.current) return;
    const idx = state.visible.findIndex(x => x.id === state.current.id);
    const next = state.visible[idx + d];
    if (idx >= 0 && next) openView(next.id, d);
  }
  $('#prevBtn').addEventListener('click', () => step(-1));
  $('#nextBtn').addEventListener('click', () => step(1));
  // スマホ：左右スワイプで前後のレシピへ
  (() => {
    const body = $('#viewModal .view-body');
    let sx = 0, sy = 0, st = 0;
    body.addEventListener('touchstart', e => { if (e.touches.length !== 1) return; sx = e.touches[0].clientX; sy = e.touches[0].clientY; st = Date.now(); }, { passive: true });
    body.addEventListener('touchend', e => {
      if (!st) return;
      const dx = e.changedTouches[0].clientX - sx, dy = e.changedTouches[0].clientY - sy;
      const fast = Date.now() - st < 600; st = 0;
      if (fast && Math.abs(dx) > 70 && Math.abs(dx) > Math.abs(dy) * 1.6) step(dx < 0 ? 1 : -1);
    }, { passive: true });
  })();
  $('#editBtn').addEventListener('click', () => {
    const r = state.recipes.find(x => x.id === state.current.id);
    if (!r) return;
    closeModal('#viewModal'); openEdit(r);
  });
  $('#deleteBtn').addEventListener('click', async () => {
    const r = state.current;
    if (!r || !confirm(`「${r.title}」を削除しますか？\n画像も消えます。`)) return;
    try {
      await api('delete_recipe', { id: r.id });
      closeModal('#viewModal');
      await load();
      toast('削除しました');
    } catch (e) { alert(e.message); }
  });

  function openLightbox(src) {
    const lb = $('#lightbox');
    $('img', lb).src = src;
    lb.hidden = false; lb.scrollTop = 0;
  }
  $('#lightbox').addEventListener('click', () => $('#lightbox').hidden = true);

  // ---------- 追加・編集 ----------
  $('#addBtn').addEventListener('click', () => openEdit(null));

  // 編集中の画像：items[0]=材料, [1]=作り方, [2]=表紙（空なら null）, [3〜]=イメージ
  function openEdit(r) {
    const items = r ? r.images.map(name => name ? { kind: 'old', name } : null) : [];
    while (items.length < 3) items.push(null);
    state.edit = { id: r ? r.id : '', items, cats: new Set(r ? r.categories : [...state.filter]) };
    $('#editHeading').textContent = r ? 'レシピを編集' : 'レシピを追加';
    $('#fTitle').value = r ? r.title : '';
    $('#fMemo').value = r ? (r.memo || '') : '';
    state.edit.level = r ? (r.level || 0) : 0;
    state.edit.rating = r ? (r.rating || 0) : 0;
    renderLevelRating();
    $('#newCat').value = '';
    renderThumbs(); renderCatPicker();
    openModal('#editModal');
    // スマホでは自動でキーボードを出さない
    if (!r && isPC) setTimeout(() => $('#fTitle').focus(), 50);
  }
  function cleanupEdit() {
    if (!state.edit) return;
    for (const it of state.edit.items) if (it && it.kind === 'new') URL.revokeObjectURL(it.url);
    state.edit = null;
  }
  const srcOf = it => it.kind === 'old' ? thumbUrl(it.name) : it.url;
  function dropItem(i) {
    const items = state.edit.items, it = items[i];
    if (it && it.kind === 'new') URL.revokeObjectURL(it.url);
    if (i < 3) items[i] = null; else items.splice(i, 1);
    renderThumbs();
  }
  let slotTarget = null;
  function pickFor(i) { slotTarget = i; $('#slotFile').click(); }
  $('#slotFile').addEventListener('change', e => { addFiles(e.target.files, slotTarget); e.target.value = ''; });
  function dropTarget(node, slot) {
    ['dragenter', 'dragover'].forEach(t => node.addEventListener(t, e => { e.preventDefault(); e.stopPropagation(); node.classList.add('over'); }));
    node.addEventListener('dragleave', () => node.classList.remove('over'));
    node.addEventListener('drop', e => { e.preventDefault(); e.stopPropagation(); node.classList.remove('over'); addFiles(e.dataTransfer.files, slot); });
    return node;
  }

  function renderThumbs() {
    const items = state.edit.items;
    // ---- 材料・作り方・表紙の3枠 ----
    const swap = (i, j) => { [items[i], items[j]] = [items[j], items[i]]; renderThumbs(); };
    $('#slots').replaceChildren(...[0, 1, 2].map(i => {
      const it = items[i];
      const label = el('span', { class: 'slot-label r' + i }, `${i + 1} ${ROLES[i]}`);
      if (!it) {
        return dropTarget(el('button', { type: 'button', class: 'slot empty r' + i + '-line', onclick: () => pickFor(i) },
          label, el('span', { class: 'slot-plus' }, '＋'), el('span', { class: 'slot-hint' }, `${ROLES[i]}の画像`)), i);
      }
      return dropTarget(el('div', { class: 'slot filled' },
        el('img', { src: srcOf(it), alt: ROLES[i], onclick: () => pickFor(i), title: 'タップで差し替え' }),
        label,
        el('button', { type: 'button', class: 'x', title: '外す', onclick: () => dropItem(i) }, '✕'),
        el('button', { type: 'button', class: 'crop-btn', title: 'トリミング', onclick: () => openCrop(i) }, '✂'),
        el('div', { class: 'mv' },
          i > 0 ? el('button', { type: 'button', title: ROLES[i - 1] + 'と入れ替え', onclick: () => swap(i, i - 1) }, '◀') : null,
          i < 2 ? el('button', { type: 'button', class: 'r', title: ROLES[i + 1] + 'と入れ替え', onclick: () => swap(i, i + 1) }, '▶') : null)
      ), i);
    }));
    // ---- イメージ（4枚目以降） ----
    const extra = items.slice(3);
    const move = (i, d) => { const a = 3 + i, b = a + d; if (b < 3 || b >= items.length) return; swap(a, b); };
    $('#thumbs').replaceChildren(...extra.map((it, i) =>
      el('div', { class: 'tb' },
        el('img', { src: srcOf(it), alt: '' }),
        el('span', { class: 'no r3' }, 'イメージ'),
        el('button', { type: 'button', class: 'x', title: '外す', onclick: () => dropItem(3 + i) }, '✕'),
        el('button', { type: 'button', class: 'crop-btn', title: 'トリミング', onclick: () => openCrop(3 + i) }, '✂'),
        extra.length > 1 ? el('div', { class: 'mv' },
          i > 0 ? el('button', { type: 'button', onclick: () => move(i, -1) }, '◀') : null,
          i < extra.length - 1 ? el('button', { type: 'button', class: 'r', onclick: () => move(i, 1) }, '▶') : null
        ) : null
      )
    ));
  }

  // 難易度・おすすめ度（同じものをもう一度タップすると解除）
  function renderLevelRating() {
    const ed = state.edit;
    document.querySelectorAll('#fLevel button').forEach(b => {
      const on = +b.dataset.v === ed.level;
      b.classList.toggle('on', on); b.setAttribute('aria-checked', on);
    });
    document.querySelectorAll('#fRating button').forEach(b => {
      b.classList.toggle('on', +b.dataset.v <= ed.rating);
      b.setAttribute('aria-checked', +b.dataset.v === ed.rating);
    });
  }
  $('#fLevel').addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b || !state.edit) return;
    state.edit.level = state.edit.level === +b.dataset.v ? 0 : +b.dataset.v; renderLevelRating();
  });
  $('#fRating').addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b || !state.edit) return;
    state.edit.rating = state.edit.rating === +b.dataset.v ? 0 : +b.dataset.v; renderLevelRating();
  });

  function renderCatPicker() {
    const box = $('#fCats');
    if (!state.categories.length) {
      box.replaceChildren(el('span', { class: 'hint' }, 'カテゴリはまだありません。下から追加できます。'));
      return;
    }
    box.replaceChildren(...state.categories.map(c =>
      el('button', {
        type: 'button', class: 'chip' + (state.edit.cats.has(c.id) ? ' on' : ''),
        onclick: () => { state.edit.cats.has(c.id) ? state.edit.cats.delete(c.id) : state.edit.cats.add(c.id); renderCatPicker(); },
      }, c.name)
    ));
  }

  async function addCategory(input) {
    const name = input.value.trim();
    if (!name) return null;
    try {
      const { category } = await api('add_category', { name });
      if (!state.categories.some(c => c.id === category.id)) state.categories.push(category);
      input.value = '';
      return category;
    } catch (e) { alert(e.message); return null; }
  }
  const addFromEdit = async () => {
    const c = await addCategory($('#newCat'));
    if (c) { state.edit.cats.add(c.id); renderCatPicker(); renderFilterBar(); }
  };
  $('#newCatBtn').addEventListener('click', addFromEdit);
  $('#newCat').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); addFromEdit(); } });

  // 画像の取り込み（選択・ドラッグ・貼り付け）
  // slot を指定すると、その枠（0=材料,1=作り方,2=表紙,'extra'=イメージ）に入れる。
  // 指定がなければ 材料 → 作り方 → 表紙 → イメージ の順で空いている所に入れる。
  async function addFiles(files, slot) {
    const imgs = [...files].filter(f => f.type.startsWith('image/') || /\.(heic|heif)$/i.test(f.name));
    if (!imgs.length || !state.edit) return;
    const placed = [];
    for (const f of imgs) {
      let blob;
      try { blob = await prepareImage(f); } catch { toast('読み込めない画像がありました'); continue; }
      if (!state.edit) return;
      const items = state.edit.items;
      const it = { kind: 'new', blob, url: URL.createObjectURL(blob) };
      let i;
      if (typeof slot === 'number' && !placed.length) {
        i = slot;
        if (items[i] && items[i].kind === 'new') URL.revokeObjectURL(items[i].url);
      } else if (slot === 'extra') {
        i = items.length;
      } else {
        i = [0, 1, 2].find(k => !items[k]);
        if (i === undefined) i = items.length;
      }
      items[i] = it;
      placed.push(i < 3 ? ROLES[i] : 'イメージ');
    }
    renderThumbs();
    if (placed.length) toast([...new Set(placed)].map(p => `「${p}」`).join('') + 'に入れました');
  }
  $('#fFiles').addEventListener('change', e => { addFiles(e.target.files, 'extra'); e.target.value = ''; });
  dropTarget($('#drop'), 'extra');
  document.addEventListener('paste', e => {
    const files = [...(e.clipboardData?.files || [])].filter(f => f.type.startsWith('image/'));
    if (!files.length) return;
    if ($('#editModal').hidden) {
      // 一覧などで画像を貼り付けたら、そのまま新規追加を開く
      if (!$('#catModal').hidden) return;
      e.preventDefault();
      $('#lightbox').hidden = true;
      closeModal('#viewModal'); closeModal('#helpModal');
      openEdit(null);
    } else e.preventDefault();
    addFiles(files);
  });

  // アップロード前の最適化：横幅1600pxまで縮小（縦長スクショは高さをそのまま活かす）。
  // 小さい画像はそのまま。変換するときは文字がにじみにくい WebP（使えないブラウザは高画質JPEG）。
  const canWebp = (() => { try { return document.createElement('canvas').toDataURL('image/webp').startsWith('data:image/webp'); } catch { return false; } })();
  function prepareImage(file) {
    return new Promise((resolve, reject) => {
      if (file.type === 'image/gif') return resolve(file);
      const url = URL.createObjectURL(file);
      const img = new Image();
      img.onload = () => {
        URL.revokeObjectURL(url);
        const MAX_W = 1600, MAX_H = 12000;
        const w = img.naturalWidth, h = img.naturalHeight;
        const s = Math.min(1, MAX_W / w, MAX_H / h);
        const okType = ['image/jpeg', 'image/png', 'image/webp'].includes(file.type);
        if (s === 1 && okType && file.size < 1.5 * 1024 * 1024) return resolve(file);
        // 大きく縮めるときは半分ずつ縮小すると、ギザギザ（かくかく）になりにくい
        let src = img, cw = w, ch = h;
        const tw = Math.round(w * s), th = Math.round(h * s);
        while (cw / 2 >= tw) {
          const c = document.createElement('canvas');
          c.width = Math.round(cw / 2); c.height = Math.round(ch / 2);
          const x = c.getContext('2d'); x.imageSmoothingQuality = 'high';
          x.drawImage(src, 0, 0, c.width, c.height);
          src = c; cw = c.width; ch = c.height;
        }
        const c = document.createElement('canvas');
        c.width = tw; c.height = th;
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, tw, th);
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(src, 0, 0, tw, th);
        const type = canWebp && state.webpOk ? 'image/webp' : 'image/jpeg';
        c.toBlob(b => {
          if (!b) return reject();
          // 変換しても元より大きくなるなら、元の画像を使う
          resolve(s === 1 && okType && b.size >= file.size ? file : b);
        }, type, type === 'image/webp' ? 0.9 : 0.92);
      };
      img.onerror = () => { URL.revokeObjectURL(url); reject(); };
      img.src = url;
    });
  }

  $('#editForm').addEventListener('submit', async e => {
    e.preventDefault();
    const ed = state.edit;
    const title = $('#fTitle').value.trim();
    if (!title) return $('#fTitle').focus();
    if (!ed.items.some(Boolean)) return toast('画像を1枚以上追加してください');
    const fd = new FormData();
    fd.append('id', ed.id);
    fd.append('title', title);
    fd.append('memo', $('#fMemo').value);
    fd.append('level', ed.level || 0);
    fd.append('rating', ed.rating || 0);
    ed.cats.forEach(c => fd.append('categories[]', c));
    let n = 0;
    for (const [i, it] of ed.items.entries()) {
      if (!it) { if (i < 3) fd.append('order[]', 'e:'); continue; }
      if (it.kind === 'old') { fd.append('keep[]', it.name); fd.append('order[]', 'k:' + it.name); }
      else {
        const ext = ({ 'image/png': 'png', 'image/webp': 'webp', 'image/gif': 'gif' })[it.blob.type] || 'jpg';
        fd.append('images[]', it.blob, `img${n}.${ext}`);
        fd.append('order[]', 'n:' + n++);
      }
    }
    const btn = $('#saveBtn');
    btn.disabled = true; btn.textContent = '保存中…';
    try {
      const { recipe } = await api('save_recipe', fd);
      state.mode = 'mine';
      cleanupEdit();
      closeModal('#editModal');
      await load();
      toast('保存しました');
      if (ed.id) openView(recipe.id);
    } catch (err) {
      alert(err.message);
    } finally {
      btn.disabled = false; btn.textContent = '保存';
    }
  });

  // ---------- カテゴリ管理 ----------
  function openCatManager() { renderCatList(); openModal('#catModal'); }
  function renderCatList() {
    const counts = {};
    for (const r of state.recipes) for (const c of r.categories) counts[c] = (counts[c] || 0) + 1;
    const list = $('#catList');
    if (!state.categories.length) {
      list.replaceChildren(el('li', { class: 'empty-msg' }, 'カテゴリはまだありません'));
      return;
    }
    list.replaceChildren(...state.categories.map((c, i) => {
      const input = el('input', { class: 'input', value: c.name, maxlength: 30 });
      const save = async () => {
        const name = input.value.trim();
        if (!name || name === c.name) { input.value = c.name; return; }
        try { await api('rename_category', { id: c.id, name }); c.name = name; render(); toast('名前を変更しました'); }
        catch (e) { alert(e.message); input.value = c.name; }
      };
      input.addEventListener('change', save);
      input.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.isComposing) input.blur(); });
      const move = async dir => {
        try { const d = await api('move_category', { id: c.id, dir }); state.categories = d.categories; renderCatList(); render(); }
        catch (e) { alert(e.message); }
      };
      return el('li', {},
        input,
        el('span', { class: 'n' }, `${counts[c.id] || 0}件`),
        el('button', { class: 'icon-btn', title: '上へ', disabled: i === 0, onclick: () => move('up') }, '▲'),
        el('button', { class: 'icon-btn', title: '下へ', disabled: i === state.categories.length - 1, onclick: () => move('down') }, '▼'),
        el('button', { class: 'icon-btn del', title: '削除', onclick: async () => {
          const n = counts[c.id] || 0;
          if (!confirm(`カテゴリ「${c.name}」を削除しますか？` + (n ? `\n（${n}件のレシピからこのカテゴリが外れます。レシピ自体は消えません）` : ''))) return;
          try { await api('delete_category', { id: c.id }); await load(); renderCatList(); }
          catch (e) { alert(e.message); }
        } }, '🗑')
      );
    }));
  }
  const addFromMgr = async () => { if (await addCategory($('#mgrNew'))) { renderCatList(); render(); } };
  $('#mgrAdd').addEventListener('click', addFromMgr);
  $('#mgrNew').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); addFromMgr(); } });

  // ---------- 検索 ----------
  $('#q').addEventListener('input', e => { state.query = e.target.value; render(); });

  // ---------- トリミング ----------
  // 画像の座標（元の画素）で切り抜き範囲を持ち、表示用に縮小して重ねる。
  // 縦に長いスクショは横幅いっぱいに表示して、画面をスクロールしながら範囲を決められる。
  const crop = { idx: -1, src: null, base: null, w: 0, h: 0, box: null, scale: 1, bg: null, bgMax: 255, adj: { shadow: 0, bright: 0, contrast: 0 } };
  function loadImage(url) {
    return new Promise((res, rej) => {
      const i = new Image();
      i.onload = async () => { try { if (i.decode) await i.decode(); } catch {} res(i); };
      i.onerror = rej; i.src = url;
    });
  }
  async function openCrop(idx) {
    const it = state.edit && state.edit.items[idx];
    if (!it) return;
    let img;
    try { img = await loadImage(it.kind === 'old' ? imgUrl(it.name) : it.url); }
    catch { return toast('画像を読み込めませんでした'); }
    crop.idx = idx;
    $('#cropTitle').textContent = 'トリミング（' + (idx < 3 ? ROLES[idx] : 'イメージ') + '）';
    resetAdj();
    setCropSource(img);
    openModal('#cropModal');
    requestAnimationFrame(layoutCrop);
  }
  // 作業用キャンバス（長辺2000pxまで）に取り込む。画像要素をそのまま表示しないので、
  // 大きい写真でも途中までしか描画されない問題が起きない。
  function setCropSource(src) {
    const sw = src.naturalWidth || src.width, sh = src.naturalHeight || src.height;
    const k = Math.min(1, 2000 / Math.max(sw, sh));
    const c = document.createElement('canvas');
    c.width = Math.max(1, Math.round(sw * k)); c.height = Math.max(1, Math.round(sh * k));
    const x = c.getContext('2d');
    x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height);
    x.imageSmoothingQuality = 'high';
    x.drawImage(src, 0, 0, c.width, c.height);
    crop.base = c;
    crop.w = c.width; crop.h = c.height;
    crop.box = { x: 0, y: 0, w: crop.w, h: crop.h };
    estimateBg();
    refreshPreview();
  }

  // ---------- 影・明るさの補正 ----------
  // 紙面の明るさのムラ（影）を、文字を無視した「背景の明るさ」として推定し、
  // それが均一になるように持ち上げる。強さはスライダーで調整。
  function resetAdj() {
    crop.adj = { shadow: 0, bright: 0, contrast: 0 };
    ['shadow', 'bright', 'contrast'].forEach(k => { const e = $('#adj_' + k); if (e) e.value = 0; });
    updateAdjLabels();
  }
  function updateAdjLabels() {
    ['shadow', 'bright', 'contrast'].forEach(k => { const e = $('#adjv_' + k); if (e) e.textContent = crop.adj[k]; });
  }
  function estimateBg() {
    const c = crop.base, GW = Math.max(8, Math.round(c.width / 12)), GH = Math.max(8, Math.round(c.height / 12));
    const s = document.createElement('canvas'); s.width = GW; s.height = GH;
    const sx = s.getContext('2d'); sx.imageSmoothingQuality = 'high'; sx.drawImage(c, 0, 0, GW, GH);
    const d = sx.getImageData(0, 0, GW, GH).data;
    let L = new Float32Array(GW * GH);
    for (let i = 0; i < L.length; i++) L[i] = 0.299 * d[i * 4] + 0.587 * d[i * 4 + 1] + 0.114 * d[i * 4 + 2];
    // 最大値フィルタ（文字などの暗い部分を消す）→ ぼかし
    const maxf = (src, r) => {
      const tmp = new Float32Array(src.length), out = new Float32Array(src.length);
      for (let y = 0; y < GH; y++) for (let x = 0; x < GW; x++) {
        let m = 0; for (let k = Math.max(0, x - r); k <= Math.min(GW - 1, x + r); k++) m = Math.max(m, src[y * GW + k]); tmp[y * GW + x] = m;
      }
      for (let y = 0; y < GH; y++) for (let x = 0; x < GW; x++) {
        let m = 0; for (let k = Math.max(0, y - r); k <= Math.min(GH - 1, y + r); k++) m = Math.max(m, tmp[k * GW + x]); out[y * GW + x] = m;
      }
      return out;
    };
    const blur = (src, r) => {
      const tmp = new Float32Array(src.length), out = new Float32Array(src.length);
      for (let y = 0; y < GH; y++) for (let x = 0; x < GW; x++) {
        let t = 0, n = 0; for (let k = Math.max(0, x - r); k <= Math.min(GW - 1, x + r); k++) { t += src[y * GW + k]; n++; } tmp[y * GW + x] = t / n;
      }
      for (let y = 0; y < GH; y++) for (let x = 0; x < GW; x++) {
        let t = 0, n = 0; for (let k = Math.max(0, y - r); k <= Math.min(GH - 1, y + r); k++) { t += tmp[k * GW + x]; n++; } out[y * GW + x] = t / n;
      }
      return out;
    };
    L = blur(maxf(L, 4), 3);
    let mx = 0; for (let i = 0; i < L.length; i++) mx = Math.max(mx, L[i]);
    const bc = document.createElement('canvas'); bc.width = GW; bc.height = GH;
    const bx = bc.getContext('2d'), id = bx.createImageData(GW, GH);
    for (let i = 0; i < L.length; i++) { const v = Math.round(L[i]); id.data[i * 4] = id.data[i * 4 + 1] = id.data[i * 4 + 2] = v; id.data[i * 4 + 3] = 255; }
    bx.putImageData(id, 0, 0);
    crop.bg = bc; crop.bgMax = Math.max(60, mx);
  }
  // src を補正して新しいキャンバスで返す
  function adjustCanvas(src, adj) {
    const out = document.createElement('canvas');
    out.width = src.width; out.height = src.height;
    const x = out.getContext('2d');
    x.drawImage(src, 0, 0);
    if (!adj.shadow && !adj.bright && !adj.contrast) return out;
    const W = out.width, H = out.height;
    const img = x.getImageData(0, 0, W, H), d = img.data;
    let bgd = null;
    if (adj.shadow) {
      const t = document.createElement('canvas'); t.width = W; t.height = H;
      const tx = t.getContext('2d'); tx.imageSmoothingEnabled = true; tx.imageSmoothingQuality = 'high';
      tx.drawImage(crop.bg, 0, 0, W, H);
      bgd = tx.getImageData(0, 0, W, H).data;
    }
    const s = adj.shadow / 100, target = crop.bgMax;
    const kc = 1 + adj.contrast / 100, off = adj.bright * 1.28;
    for (let i = 0; i < d.length; i += 4) {
      let g = 1;
      if (bgd) { const b = Math.max(50, bgd[i]); g = 1 + (Math.min(3, target / b) - 1) * s; }
      for (let c = 0; c < 3; c++) {
        let v = d[i + c] * g;
        v = (v - 128) * kc + 128 + off;
        d[i + c] = v < 0 ? 0 : v > 255 ? 255 : v;
      }
    }
    x.putImageData(img, 0, 0);
    return out;
  }
  // 表示用（長辺1000pxに縮小したものに補正をかけて描画）
  let previewReq = 0;
  function refreshPreview() {
    cancelAnimationFrame(previewReq);
    previewReq = requestAnimationFrame(() => {
      if (!crop.base) return;
      const k = Math.min(1, 1000 / Math.max(crop.w, crop.h));
      const p = document.createElement('canvas');
      p.width = Math.max(1, Math.round(crop.w * k)); p.height = Math.max(1, Math.round(crop.h * k));
      const px = p.getContext('2d'); px.imageSmoothingQuality = 'high'; px.drawImage(crop.base, 0, 0, p.width, p.height);
      const a = adjustCanvas(p, crop.adj);
      const v = $('#cropImg');
      v.width = a.width; v.height = a.height;
      v.getContext('2d').drawImage(a, 0, 0);
    });
  }
  ['shadow', 'bright', 'contrast'].forEach(k => {
    $('#adj_' + k).addEventListener('input', e => { crop.adj[k] = +e.target.value; updateAdjLabels(); refreshPreview(); });
  });
  $('#cropAuto').addEventListener('click', () => {
    crop.adj = { shadow: 85, bright: 0, contrast: 15 };
    ['shadow', 'bright', 'contrast'].forEach(k => { $('#adj_' + k).value = crop.adj[k]; });
    updateAdjLabels(); refreshPreview();
  });
  function layoutCrop() {
    const stage = $('#cropStage');
    const cw = stage.clientWidth - 32, ch = stage.clientHeight - 32;
    let s = Math.min(cw / crop.w, ch / crop.h);
    if (crop.h / crop.w > 2.2) s = Math.min(cw / crop.w, 1.5); // 縦長は横幅いっぱい＋スクロール
    crop.scale = s;
    const c = $('#cropCanvas');
    c.style.width = Math.round(crop.w * s) + 'px';
    c.style.height = Math.round(crop.h * s) + 'px';
    drawCropBox();
  }
  function drawCropBox() {
    const b = crop.box, s = crop.scale, el = $('#cropBox');
    el.style.left = b.x * s + 'px'; el.style.top = b.y * s + 'px';
    el.style.width = b.w * s + 'px'; el.style.height = b.h * s + 'px';
  }
  function clampBox(b) {
    b.w = Math.min(b.w, crop.w); b.h = Math.min(b.h, crop.h);
    b.x = Math.max(0, Math.min(b.x, crop.w - b.w));
    b.y = Math.max(0, Math.min(b.y, crop.h - b.h));
    return b;
  }
  // ドラッグ（指・マウス共通）
  (() => {
    let drag = null;
    const box = $('#cropBox');
    box.addEventListener('pointerdown', e => {
      e.preventDefault();
      box.setPointerCapture(e.pointerId);
      drag = { h: e.target.dataset.h || 'move', sx: e.clientX, sy: e.clientY, b: Object.assign({}, crop.box) };
    });
    box.addEventListener('pointermove', e => {
      if (!drag) return;
      const s = crop.scale, dx = (e.clientX - drag.sx) / s, dy = (e.clientY - drag.sy) / s;
      const o = drag.b, min = 40 / s;
      let { x, y, w, h } = o;
      if (drag.h === 'move') { x = o.x + dx; y = o.y + dy; crop.box = clampBox({ x, y, w, h }); return drawCropBox(); }
      const H = drag.h;
      if (H.includes('e')) w = Math.min(Math.max(min, o.w + dx), crop.w - o.x);
      if (H.includes('s')) h = Math.min(Math.max(min, o.h + dy), crop.h - o.y);
      if (H.includes('w')) { const nx = Math.max(0, Math.min(o.x + dx, o.x + o.w - min)); w = o.w + (o.x - nx); x = nx; }
      if (H.includes('n')) { const ny = Math.max(0, Math.min(o.y + dy, o.y + o.h - min)); h = o.h + (o.y - ny); y = ny; }
      crop.box = { x, y, w, h };
      drawCropBox();
    });
    const end = () => { drag = null; };
    box.addEventListener('pointerup', end);
    box.addEventListener('pointercancel', end);
  })();
  $('#cropRotate').addEventListener('click', () => {
    const c = document.createElement('canvas');
    c.width = crop.h; c.height = crop.w;
    const x = c.getContext('2d');
    x.translate(c.width, 0); x.rotate(Math.PI / 2);
    x.drawImage(crop.base, 0, 0);
    setCropSource(c);
    layoutCrop();
  });
  $('#cropReset').addEventListener('click', async () => {
    const it = state.edit.items[crop.idx];
    try { resetAdj(); setCropSource(await loadImage(it.kind === 'old' ? imgUrl(it.name) : it.url)); } catch {}
    layoutCrop();
  });
  $('#cropCancel').addEventListener('click', () => closeModal('#cropModal'));
  $('#cropDone').addEventListener('click', () => {
    const b = crop.box;
    const sx = Math.round(b.x), sy = Math.round(b.y), sw = Math.round(b.w), sh = Math.round(b.h);
    // 切り抜いて、横幅1600pxを超えるなら縮小
    const k = Math.min(1, 1600 / sw);
    const full = adjustCanvas(crop.base, crop.adj);
    const c = document.createElement('canvas');
    c.width = Math.round(sw * k); c.height = Math.round(sh * k);
    const x = c.getContext('2d');
    x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height);
    x.imageSmoothingQuality = 'high';
    x.drawImage(full, sx, sy, sw, sh, 0, 0, c.width, c.height);
    const type = canWebp && state.webpOk ? 'image/webp' : 'image/jpeg';
    c.toBlob(blob => {
      if (!blob || !state.edit) return;
      const items = state.edit.items, old = items[crop.idx];
      if (old && old.kind === 'new') URL.revokeObjectURL(old.url);
      items[crop.idx] = { kind: 'new', blob, url: URL.createObjectURL(blob) };
      renderThumbs();
      closeModal('#cropModal');
      toast('トリミングしました（保存で反映）');
    }, type, type === 'image/webp' ? 0.9 : 0.92);
  });
  window.addEventListener('resize', () => { if (!$('#cropModal').hidden) layoutCrop(); });

  // ---------- 共有されたレシピ：保存・外す ----------
  $('#importBtn').addEventListener('click', async () => {
    const r = state.current;
    if (!r || !r.from) return;
    const btn = $('#importBtn');
    btn.disabled = true; btn.textContent = '保存中…';
    try {
      await api('import', { owner: r.owner, recipe: r.recipeId });
      await load();
      toast('自分のレシピに保存しました');
      btn.textContent = '保存しました';
    } catch (e) { toast(e.message); btn.disabled = false; btn.textContent = '自分のレシピに保存'; }
  });
  $('#dismissBtn').addEventListener('click', async () => {
    const r = state.current;
    if (!r || !r.from || !confirm(`「${r.title}」を共有された一覧から外しますか？`)) return;
    try {
      await api('share_user_remove', { share_id: r.id });
      closeModal('#viewModal');
      await load();
      toast('外しました');
    } catch (e) { toast(e.message); }
  });

  // ---------- 共有シート ----------
  async function openShare() {
    const r = state.current;
    if (!r || r.from) return;
    $('#shareTitle').textContent = r.title;
    $('#sendName').value = '';
    $('#shareUsers').replaceChildren();
    $('#linkNone').hidden = true; $('#linkSome').hidden = true;
    openModal('#shareModal');
    try {
      const d = await api('share_info&id=' + encodeURIComponent(r.id));
      renderShare(d);
    } catch (e) { toast(e.message); }
  }
  function renderShare(d) {
    $('#linkNone').hidden = !!d.link;
    $('#linkSome').hidden = !d.link;
    if (d.link) $('#linkUrl').value = d.link;
    $('#linkSend').hidden = !navigator.share;
    $('#shareUsers').replaceChildren(...d.users.map(u =>
      el('li', {}, el('span', {}, u.name + ' さん'),
        el('button', { class: 'btn small ghost-red', onclick: async () => {
          if (!confirm(`${u.name} さんへの共有を取り消しますか？`)) return;
          try { await api('share_user_remove', { share_id: u.share_id }); renderShare(await api('share_info&id=' + encodeURIComponent(state.current.id))); toast('取り消しました'); }
          catch (e) { toast(e.message); }
        } }, '取り消す'))
    ));
  }
  $('#shareBtn').addEventListener('click', openShare);
  $('#linkCreate').addEventListener('click', async () => {
    try {
      const d = await api('share_link_create', { id: state.current.id });
      renderShare(await api('share_info&id=' + encodeURIComponent(state.current.id)));
      copyText(d.link, '共有リンクを作ってコピーしました');
    } catch (e) { toast(e.message); }
  });
  async function copyText(t, msg) {
    try { await navigator.clipboard.writeText(t); toast(msg || 'コピーしました'); }
    catch { $('#linkUrl').select(); toast('リンクを長押ししてコピーしてください'); }
  }
  $('#linkCopy').addEventListener('click', () => copyText($('#linkUrl').value));
  $('#linkUrl').addEventListener('focus', e => e.target.select());
  $('#linkSend').addEventListener('click', async () => {
    try { await navigator.share({ title: state.current.title, text: `レシピ「${state.current.title}」`, url: $('#linkUrl').value }); }
    catch {}
  });
  $('#linkStop').addEventListener('click', async () => {
    if (!confirm('共有リンクを止めますか？\n送ったリンクは開けなくなります。')) return;
    try {
      await api('share_link_delete', { id: state.current.id });
      renderShare(await api('share_info&id=' + encodeURIComponent(state.current.id)));
      toast('リンクを止めました');
    } catch (e) { toast(e.message); }
  });
  $('#sendForm').addEventListener('submit', async e => {
    e.preventDefault();
    const name = $('#sendName').value.trim();
    if (!name) return $('#sendName').focus();
    try {
      const d = await api('share_user_add', { id: state.current.id, name });
      $('#sendName').value = '';
      renderShare(await api('share_info&id=' + encodeURIComponent(state.current.id)));
      toast(`${d.name} さんに送りました`);
    } catch (e) { toast(e.message); }
  });

  // ---------- アカウント ----------
  function openAccount() {
    const me = state.me;
    $('#meAvatar').textContent = me.name.slice(0, 1).toUpperCase();
    $('#meName').textContent = me.name;
    $('#meRole').textContent = me.admin ? '管理者' : 'ユーザー';
    $('#inboxCount').textContent = state.inbox.length ? String(state.inbox.length) : '';
    $('#goInbox').hidden = !state.inbox.length;
    $('#adminBox').hidden = !me.admin;
    if (me.admin && state.settings) {
      $('#setSignup').checked = !!state.settings.signup_open;
      $('#setInvite').value = state.settings.invite_code || '';
      $('#userCount').textContent = `登録ユーザー：${state.userCount}人`;
    }
    $('#installHint').hidden = isStandalone();
    $('#installBtn').hidden = !installEvent;
    openModal('#accountModal');
  }
  $('#accountBtn').addEventListener('click', openAccount);
  $('#goInbox').addEventListener('click', () => { closeModal('#accountModal'); setMode('inbox'); });
  $('#goCats').addEventListener('click', () => { closeModal('#accountModal'); openCatManager(); });
  $('#goHelp').addEventListener('click', () => { closeModal('#accountModal'); openModal('#helpModal'); });
  $('#pwForm').addEventListener('submit', async e => {
    e.preventDefault();
    try {
      await api('change_password', { current: $('#pwCur').value, new: $('#pwNew').value });
      $('#pwCur').value = ''; $('#pwNew').value = '';
      e.target.closest('details').open = false;
      toast('パスワードを変更しました');
    } catch (err) { toast(err.message); }
  });
  $('#adminForm').addEventListener('submit', async e => {
    e.preventDefault();
    try {
      const d = await api('settings_save', { signup_open: $('#setSignup').checked ? '1' : '0', invite_code: $('#setInvite').value.trim() });
      state.settings = d.settings;
      toast('設定を保存しました');
    } catch (err) { toast(err.message); }
  });

  // ---------- アプリとしてインストール ----------
  let installEvent = null;
  const isStandalone = () => matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  window.addEventListener('beforeinstallprompt', e => { e.preventDefault(); installEvent = e; });
  $('#installBtn').addEventListener('click', async () => {
    if (!installEvent) return;
    installEvent.prompt();
    await installEvent.userChoice.catch(() => {});
    installEvent = null; $('#installBtn').hidden = true;
  });
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});

  // ---------- ショートカット ----------
  const isOpen = id => !$(id).hidden;
  const typing = () => {
    const a = document.activeElement;
    return a && (a.tagName === 'INPUT' || a.tagName === 'TEXTAREA' || a.isContentEditable);
  };
  function closeAll() {
    $('#lightbox').hidden = true;
    if (isOpen('#editModal')) cleanupEdit();
    ['#helpModal', '#shareModal', '#accountModal', '#catModal', '#editModal', '#viewModal'].forEach(closeModal);
  }
  function openHelp() { openModal('#helpModal'); }
  $('#helpBtn').addEventListener('click', openHelp);
  function focusSearch() { closeAll(); const q = $('#q'); q.focus(); q.select(); }
  function toggleCat(n) {
    const c = state.categories[n - 1];
    if (!c) return;
    state.filter.has(c.id) ? state.filter.delete(c.id) : state.filter.add(c.id);
    render();
    toast(state.filter.size ? '絞り込み：' + [...state.filter].map(catName).join(' × ') : 'すべて表示');
  }

  document.addEventListener('keydown', e => {
    const mod = e.ctrlKey || e.metaKey;
    const k = e.key;

    // Ctrl + ＋ ：レシピを追加（日本語キーボードの ; ＋ キー、テンキーの＋にも対応）
    if (mod && !e.altKey && (k === '+' || k === '=' || k === ';' || e.code === 'NumpadAdd')) {
      e.preventDefault();
      if (isOpen('#editModal')) return $('#fTitle').focus();
      closeAll(); openEdit(null);
      return;
    }
    // Ctrl + S / Ctrl + Enter ：保存
    if (mod && (k === 's' || k === 'S' || k === 'Enter')) {
      if (isOpen('#cropModal')) { e.preventDefault(); $('#cropDone').click(); }
      else if (isOpen('#editModal')) { e.preventDefault(); $('#editForm').requestSubmit($('#saveBtn')); }
      else if (k !== 'Enter') e.preventDefault();
      return;
    }
    // Ctrl + K ：検索
    if (mod && (k === 'k' || k === 'K')) { e.preventDefault(); focusSearch(); return; }

    if (k === 'Escape') {
      if (isOpen('#lightbox')) return $('#lightbox').hidden = true;
      if (isOpen('#cropModal')) return closeModal('#cropModal');
      if (isOpen('#helpModal')) return closeModal('#helpModal');
      if (isOpen('#shareModal')) return closeModal('#shareModal');
      if (isOpen('#accountModal')) return closeModal('#accountModal');
      if (isOpen('#catModal')) return closeModal('#catModal');
      if (isOpen('#editModal')) { cleanupEdit(); return closeModal('#editModal'); }
      if (isOpen('#viewModal')) return closeModal('#viewModal');
      if ($('#q').value) { $('#q').value = ''; state.query = ''; render(); }
      else if (document.activeElement === $('#q')) $('#q').blur();
      return;
    }

    // 検索欄で Enter → 先頭のレシピを開く
    if (k === 'Enter' && document.activeElement === $('#q') && !e.isComposing) {
      e.preventDefault();
      if (state.visible[0]) { $('#q').blur(); openView(state.visible[0].id); }
      return;
    }

    if (isOpen('#cropModal')) { if (k === 'Enter') { e.preventDefault(); $('#cropDone').click(); } return; }
    if (mod || e.altKey || e.isComposing || typing()) return;

    if (isOpen('#lightbox')) return;
    if (k === '?') { e.preventDefault(); return isOpen('#helpModal') ? closeModal('#helpModal') : openHelp(); }
    if (isOpen('#helpModal') || isOpen('#catModal') || isOpen('#editModal') || isOpen('#shareModal') || isOpen('#accountModal')) return;

    if (isOpen('#viewModal')) {
      if (k === 'ArrowLeft') { e.preventDefault(); step(-1); }
      else if (k === 'ArrowRight') { e.preventDefault(); step(1); }
      else if (state.mode === 'inbox') return;
      else if (k === 'e' || k === 'E') { e.preventDefault(); $('#editBtn').click(); }
      else if (k === 's' || k === 'S') { e.preventDefault(); openShare(); }
      else if (k === 'Delete' || k === 'Backspace') { e.preventDefault(); $('#deleteBtn').click(); }
      return;
    }

    // 一覧画面
    if (k === '/') { e.preventDefault(); focusSearch(); }
    else if (k === 'i' || k === 'I') { if (state.mode === 'inbox' || state.inbox.length) setMode(state.mode === 'inbox' ? 'mine' : 'inbox'); else toast('共有されたレシピはまだありません'); }
    else if (k === 'n' || k === 'N') { e.preventDefault(); openEdit(null); }
    else if (state.mode === 'inbox') return;
    else if (k === 'c' || k === 'C') { e.preventDefault(); openCatManager(); }
    else if (k === '0') { state.filter.clear(); render(); toast('すべて表示'); }
    else if (/^[1-9]$/.test(k)) toggleCat(+k);
  });

  load().catch(e => toast(e.message));
})();
