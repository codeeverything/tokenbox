(() => {
  const root = document.documentElement;
  const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Theme: follows the system unless the visitor picks one.
  try {
    const saved = localStorage.getItem('tokenbox-theme');
    if (saved) root.dataset.theme = saved;
  } catch (e) {}
  document.querySelectorAll('.theme-toggle').forEach((btn) => {
    btn.addEventListener('click', () => {
      const dark = root.dataset.theme
        ? root.dataset.theme === 'dark'
        : matchMedia('(prefers-color-scheme: dark)').matches;
      root.dataset.theme = dark ? 'light' : 'dark';
      try { localStorage.setItem('tokenbox-theme', root.dataset.theme); } catch (e) {}
    });
  });

  // Hairline under the bar once the page scrolls.
  const bar = document.querySelector('.bar');
  const onScroll = () => bar && bar.classList.toggle('scrolled', scrollY > 8);
  addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  // Docs code blocks with commands get a copy button too.
  document.querySelectorAll('.prose pre').forEach((pre) => {
    if (!pre.querySelector('.cmd')) return;
    const btn = document.querySelector('.copy-template')?.content.firstElementChild.cloneNode(true);
    if (btn) pre.append(btn);
  });

  // Copy buttons copy the commands only, not prompts or comments.
  document.querySelectorAll('.copy').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const box = btn.closest('.install, pre');
      const text = [...box.querySelectorAll('.cmd')].map((n) => n.textContent).join('\n');
      try {
        await navigator.clipboard.writeText(text);
        btn.dataset.done = '';
        setTimeout(() => delete btn.dataset.done, 1600);
      } catch (e) {}
    });
  });

  // Recordings: don't autoplay for people who asked for less motion.
  if (reduced) {
    document.querySelectorAll('video[autoplay]').forEach((v) => {
      v.removeAttribute('autoplay');
      v.pause();
      v.controls = true;
    });
  }

  // Hero: tokenbox reads the auth.json and badges each credential in turn.
  const file = document.querySelector('.authfile');
  if (file) {
    const lines = [...file.querySelectorAll('.ln.cred')];
    const status = file.querySelector('.run b');
    const spinner = file.querySelector('.spinner');
    const finish = () => {
      lines.forEach((l) => l.classList.add('lit'));
      file.classList.add('done');
      if (status) status.textContent = 'checked ' + lines.length + ' credentials';
      if (spinner) spinner.textContent = '✓';
    };
    if (reduced) {
      finish();
    } else {
      const frames = '⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏';
      let f = 0;
      const spin = setInterval(() => { if (spinner) spinner.textContent = frames[f++ % frames.length]; }, 80);
      lines.forEach((l, i) => setTimeout(() => l.classList.add('lit'), 900 + i * 420));
      setTimeout(() => { clearInterval(spin); finish(); }, 900 + lines.length * 420 + 200);
    }
  }

  // Docs: highlight the section in view.
  const links = [...document.querySelectorAll('.toc a[href^="#"]')];
  if (links.length) {
    const byId = new Map(links.map((a) => [a.getAttribute('href').slice(1), a]));
    const io = new IntersectionObserver((entries) => {
      entries.forEach((e) => {
        if (e.isIntersecting) {
          links.forEach((a) => a.classList.remove('on'));
          byId.get(e.target.id)?.classList.add('on');
        }
      });
    }, { rootMargin: '-90px 0px -70% 0px' });
    byId.forEach((_, id) => { const el = document.getElementById(id); if (el) io.observe(el); });
  }
})();
