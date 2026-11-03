(() => {
  'use strict';
  const body = document.querySelector('.console-body');
  if (!body) return;
  const toggle = document.getElementById('console-sidebar-toggle');
  const mobile = document.getElementById('console-mobile-toggle');
  const close = document.getElementById('console-mobile-close');
  const backdrop = document.getElementById('console-backdrop');
  const STORE = 'wabridge.sidebar.compact.v1';
  const desktop = () => window.matchMedia('(min-width: 1101px)').matches;
  const expanded = () => !body.classList.contains('sidebar-compact');
  const menuGroups = Array.from(document.querySelectorAll('.console-nav-group'));
  let priorGroups = [];
  const handleCompactGroups = () => {
    if (body.classList.contains('sidebar-compact') && desktop()) {
      if (!priorGroups.length) priorGroups = menuGroups.map(item => item.open);
      menuGroups.forEach(item => { item.open = true; });
    } else if (priorGroups.length) {
      menuGroups.forEach((item, idx) => { item.open = priorGroups[idx]; });
      priorGroups = [];
    }
  };
  const sync = () => {
    if (toggle) toggle.setAttribute('aria-expanded', String(expanded()));
    if (mobile) mobile.setAttribute('aria-expanded', String(body.classList.contains('sidebar-mobile-open')));
  };
  try { if (localStorage.getItem(STORE) === '1') body.classList.add('sidebar-compact'); } catch (e) {}
  if (toggle) toggle.addEventListener('click', () => {
    body.classList.toggle('sidebar-compact');
    try { localStorage.setItem(STORE, body.classList.contains('sidebar-compact') ? '1' : '0'); } catch (e) {}
    handleCompactGroups();
    sync();
  });
  const closeMobile = () => {
    body.classList.remove('sidebar-mobile-open');
    document.body.classList.remove('sidebar-modal-open');
    sync();
  };
  if (mobile) mobile.addEventListener('click', () => {
    const open = !body.classList.contains('sidebar-mobile-open');
    body.classList.toggle('sidebar-mobile-open', open);
    document.body.classList.toggle('sidebar-modal-open', open);
    sync();
  });
  if (close) close.addEventListener('click', closeMobile);
  if (backdrop) backdrop.addEventListener('click', closeMobile);
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeMobile();
  });
  document.querySelectorAll('.console-sidebar a').forEach(link => link.addEventListener('click', () => {
    const href = link.getAttribute('href') || '';
    if (href.startsWith('#')) {
      const target = document.getElementById(href.slice(1));
      if (target && target.tagName === 'DETAILS') target.open = true;
    }
    if (!desktop()) closeMobile();
  }));
  const path = window.location.pathname.split('/').pop() || 'index.php';
  const current = window.location.hash || '';
  document.querySelectorAll('.console-sidebar a').forEach(a => {
    const href = a.getAttribute('href') || '';
    if ((href === './' && path === 'index.php' && !current) ||
        (href.startsWith('#') && current === href) ||
        (href && !href.startsWith('#') && href !== './' && path === href.split('?')[0])) {
      a.classList.add('is-active');
      a.setAttribute('aria-current', 'page');
    }
  });
  window.addEventListener('hashchange', () => {
    document.querySelectorAll('.console-sidebar a').forEach(a => {
      const hit=a.getAttribute('href')===window.location.hash;
      a.classList.toggle('is-active', hit);
      if (hit) a.setAttribute('aria-current','page');
      else a.removeAttribute('aria-current');
    });
  });
  const activeSection = document.getElementById(window.location.hash.slice(1));
  if (activeSection && activeSection.tagName === 'DETAILS') activeSection.open = true;
  window.addEventListener('resize', () => {
    if (desktop()) closeMobile();
    handleCompactGroups();
    sync();
  });
  handleCompactGroups();
  sync();
})();