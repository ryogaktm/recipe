(() => {
  'use strict';
  const $ = (s, el = document) => el.querySelector(s);
  const CSRF = $('meta[name="csrf-token"]').content;

  const state = {
    recipes: [],
    categories: [],
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
  const imgUrl = (name) => 'image.php?f=' + encodeURIComponent(name);
  const isPC = matchMedia('(hover: hover) and (pointer: fine)').matches;
  const catName = (id) => (state.categories.find(c => c.id === id) || {}).name;

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
    state.recipes = d.recipes;
    state.categories = d.categories;
    for (const id of [...state.filter]) if (!catName(id)) state.filter.delete(id);
    render();
  }

  function render() {
    renderFilterBar();
    const q = state.query.trim().toLowerCase();
    const list = state.recipes.filter(r =>
      (!q || r.title.toLowerCase().includes(q)) &&
      [...state.filter].every(id => r.categories.includes(id))
    );
    state.visible = list;
    const grid = $('#grid');
    grid.replaceChildren(...list.map(card));
    $('#empty').hidden = state.recipes.length > 0;
    $('#count').textContent = state.recipes.length
      ? (list.length === state.recipes.length ? `${list.length}件` : `${list.length}件 / 全${state.recipes.length}件`)
      : '';
  }

  function renderFilterBar() {
    const counts = {};
    for (const r of state.recipes) for (const c of r.categories) counts[c] = (counts[c] || 0) + 1;
    const bar = $('#filterBar');
    const all = el('button', { class: 'fchip' + (state.filter.size ? '' : ' on'), onclick: () => { state.filter.clear(); render(); } }, 'すべて');
    const chips = state.categories.map((c, i) =>
      el('button', {
        class: 'fchip' + (state.filter.has(c.id) ? ' on' : ''),
        onclick: () => { state.filter.has(c.id) ? state.filter.delete(c.id) : state.filter.add(c.id); render(); },
      }, i < 9 ? el('span', { class: 'k' }, String(i + 1)) : null, c.name, el('span', { class: 'n' }, String(counts[c.id] || 0)))
    );
    const manage = el('button', { class: 'fchip ghost', onclick: openCatManager }, '⚙ カテゴリ');
    bar.replaceChildren(all, ...chips, manage);
  }

  function card(r) {
    return el('button', { class: 'card', onclick: () => openView(r.id) },
      el('div', { class: 'thumb' },
        el('img', { src: imgUrl(r.images[0]), loading: 'lazy', alt: '' }),
        r.images.length > 1 ? el('span', { class: 'badge' }, `${r.images.length}枚`) : null
      ),
      el('div', { class: 'meta' },
        el('p', { class: 'title' }, r.title),
        el('div', { class: 'chips' }, r.categories.map(id => catName(id)).filter(Boolean).map(n => el('span', { class: 'chip' }, n)))
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
    const r = state.recipes.find(x => x.id === id);
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
    $('#viewCats').replaceChildren(...r.categories.map(catName).filter(Boolean).map(n => el('span', { class: 'chip' }, n)));
    $('#viewImages').replaceChildren(...r.images.map(n =>
      el('img', { src: imgUrl(n), alt: '', onclick: () => openLightbox(imgUrl(n)) })
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
  $('#editBtn').addEventListener('click', () => { closeModal('#viewModal'); openEdit(state.current); });
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

  function openEdit(r) {
    state.edit = {
      id: r ? r.id : '',
      items: r ? r.images.map(name => ({ kind: 'old', name })) : [],
      cats: new Set(r ? r.categories : [...state.filter]),
    };
    $('#editHeading').textContent = r ? 'レシピを編集' : 'レシピを追加';
    $('#fTitle').value = r ? r.title : '';
    $('#newCat').value = '';
    renderThumbs(); renderCatPicker();
    openModal('#editModal');
    // スマホでは自動でキーボードを出さない
    if (!r && isPC) setTimeout(() => $('#fTitle').focus(), 50);
  }
  function cleanupEdit() {
    if (!state.edit) return;
    for (const it of state.edit.items) if (it.kind === 'new') URL.revokeObjectURL(it.url);
    state.edit = null;
  }

  function renderThumbs() {
    const items = state.edit.items;
    const move = (i, d) => { const j = i + d; if (j < 0 || j >= items.length) return; [items[i], items[j]] = [items[j], items[i]]; renderThumbs(); };
    $('#thumbs').replaceChildren(...items.map((it, i) =>
      el('div', { class: 'tb' },
        el('img', { src: it.kind === 'old' ? imgUrl(it.name) : it.url, alt: '' }),
        items.length > 1 ? el('span', { class: 'no' }, String(i + 1)) : null,
        el('button', { type: 'button', class: 'x', title: '外す', onclick: () => {
          if (it.kind === 'new') URL.revokeObjectURL(it.url);
          items.splice(i, 1); renderThumbs();
        } }, '✕'),
        items.length > 1 ? el('div', { class: 'mv' },
          i > 0 ? el('button', { type: 'button', onclick: () => move(i, -1) }, '◀') : null,
          i < items.length - 1 ? el('button', { type: 'button', class: 'r', onclick: () => move(i, 1) }, '▶') : null
        ) : null
      )
    ));
  }

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
  async function addFiles(files) {
    const imgs = [...files].filter(f => f.type.startsWith('image/') || /\.(heic|heif)$/i.test(f.name));
    if (!imgs.length) return;
    for (const f of imgs) {
      try {
        const blob = await prepareImage(f);
        state.edit && state.edit.items.push({ kind: 'new', blob, url: URL.createObjectURL(blob) });
      } catch { toast('読み込めない画像がありました'); }
    }
    if (state.edit) renderThumbs();
  }
  $('#fFiles').addEventListener('change', e => { addFiles(e.target.files); e.target.value = ''; });
  const drop = $('#drop');
  ['dragenter', 'dragover'].forEach(t => drop.addEventListener(t, e => { e.preventDefault(); drop.classList.add('over'); }));
  ['dragleave', 'drop'].forEach(t => drop.addEventListener(t, e => { e.preventDefault(); drop.classList.remove('over'); }));
  drop.addEventListener('drop', e => addFiles(e.dataTransfer.files));
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

  // 大きすぎる画像は横幅1600pxまで縮小（縦長のスクショは高さをそのまま活かす）
  function prepareImage(file) {
    return new Promise((resolve, reject) => {
      if (file.type === 'image/gif') return resolve(file);
      const url = URL.createObjectURL(file);
      const img = new Image();
      img.onload = () => {
        URL.revokeObjectURL(url);
        const MAX_W = 1600, MAX_H = 14000;
        const s = Math.min(1, MAX_W / img.naturalWidth, MAX_H / img.naturalHeight);
        const okType = ['image/jpeg', 'image/png', 'image/webp'].includes(file.type);
        if (s === 1 && okType && file.size < 2.5 * 1024 * 1024) return resolve(file);
        const c = document.createElement('canvas');
        c.width = Math.round(img.naturalWidth * s);
        c.height = Math.round(img.naturalHeight * s);
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
        ctx.drawImage(img, 0, 0, c.width, c.height);
        c.toBlob(b => b ? resolve(b) : reject(), 'image/jpeg', 0.88);
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
    if (!ed.items.length) return toast('画像を1枚以上追加してください');
    const fd = new FormData();
    fd.append('id', ed.id);
    fd.append('title', title);
    ed.cats.forEach(c => fd.append('categories[]', c));
    let n = 0;
    for (const it of ed.items) {
      if (it.kind === 'old') { fd.append('keep[]', it.name); fd.append('order[]', 'k:' + it.name); }
      else {
        const ext = (it.blob.type.split('/')[1] || 'jpg').replace('jpeg', 'jpg');
        fd.append('images[]', it.blob, `img${n}.${ext}`);
        fd.append('order[]', 'n:' + n++);
      }
    }
    const btn = $('#saveBtn');
    btn.disabled = true; btn.textContent = '保存中…';
    try {
      const { recipe } = await api('save_recipe', fd);
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
  // ---------- ショートカット ----------
  const isOpen = id => !$(id).hidden;
  const typing = () => {
    const a = document.activeElement;
    return a && (a.tagName === 'INPUT' || a.tagName === 'TEXTAREA' || a.isContentEditable);
  };
  function closeAll() {
    $('#lightbox').hidden = true;
    if (isOpen('#editModal')) cleanupEdit();
    ['#helpModal', '#catModal', '#editModal', '#viewModal'].forEach(closeModal);
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
      if (isOpen('#editModal')) { e.preventDefault(); $('#editForm').requestSubmit($('#saveBtn')); }
      else if (k !== 'Enter') e.preventDefault();
      return;
    }
    // Ctrl + K ：検索
    if (mod && (k === 'k' || k === 'K')) { e.preventDefault(); focusSearch(); return; }

    if (k === 'Escape') {
      if (isOpen('#lightbox')) return $('#lightbox').hidden = true;
      if (isOpen('#helpModal')) return closeModal('#helpModal');
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

    if (mod || e.altKey || e.isComposing || typing()) return;

    if (isOpen('#lightbox')) return;
    if (k === '?') { e.preventDefault(); return isOpen('#helpModal') ? closeModal('#helpModal') : openHelp(); }
    if (isOpen('#helpModal') || isOpen('#catModal') || isOpen('#editModal')) return;

    if (isOpen('#viewModal')) {
      if (k === 'ArrowLeft') { e.preventDefault(); step(-1); }
      else if (k === 'ArrowRight') { e.preventDefault(); step(1); }
      else if (k === 'e' || k === 'E') { e.preventDefault(); $('#editBtn').click(); }
      else if (k === 'Delete' || k === 'Backspace') { e.preventDefault(); $('#deleteBtn').click(); }
      return;
    }

    // 一覧画面
    if (k === '/') { e.preventDefault(); focusSearch(); }
    else if (k === 'n' || k === 'N') { e.preventDefault(); openEdit(null); }
    else if (k === 'c' || k === 'C') { e.preventDefault(); openCatManager(); }
    else if (k === '0') { state.filter.clear(); render(); toast('すべて表示'); }
    else if (/^[1-9]$/.test(k)) toggleCat(+k);
  });

  load().catch(e => toast(e.message));
})();
