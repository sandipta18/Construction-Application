/**
 * @file
 * Homepage search. A question opens the answer screen over the page: the
 * answer comes from /api/search (our own index, ranked and written on the
 * server), with related content and follow-up questions.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  const ICON = {
    again: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 4v7h-7"/></svg>',
    up: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M7 11v9H4v-9h3zm0 0l4-8a2.5 2.5 0 0 1 2.5 2.5V9h5.2a2 2 0 0 1 2 2.4l-1.3 7A2 2 0 0 1 17.4 20H7"/></svg>',
    down: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M17 13V4h3v9h-3zm0 0l-4 8a2.5 2.5 0 0 1-2.5-2.5V15H5.3a2 2 0 0 1-2-2.4l1.3-7A2 2 0 0 1 6.6 4H17"/></svg>',
    mail: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6l8.5 7 8.5-7"/></svg>',
    copy: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg>',
    ext: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>',
    next: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 6h16M4 11h10M4 16h7M17 14v6M14 17h6"/></svg>',
    plus: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>',
    arrow: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
    site: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M3 21h18M5 21V8l7-4 7 4v13M9 21v-5h6v5M9 11h.01M15 11h.01"/></svg>',
    doc: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6z"/><path d="M14 3v6h6M8 13h8M8 17h5"/></svg>',
    user: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
  };

  // Text arrives as segments: {t, b (bold), u (link), ask (follow-up), ext}.
  function segs(list) {
    return (list || []).map((s) => {
      let inner = esc(s.t);
      if (s.ask) {
        return '<button type="button" class="ae-link" data-ae-ask="' + esc(s.ask) + '">' + inner + '</button>';
      }
      if (s.u) {
        return '<a class="ae-link' + (s.b ? ' ae-link--b' : '') + '" href="' + esc(s.u) + '">' + inner + (s.ext ? ' ' + ICON.ext : '') + '</a>';
      }
      return s.b ? '<strong>' + inner + '</strong>' : inner;
    }).join('');
  }

  function plain(list) {
    return (list || []).map((s) => s.t).join('');
  }

  function banner(b) {
    return '<div class="ae-banner ae-rise"><p>' + esc(b.title) + '</p><a class="ae-banner-btn" href="' + esc(b.url) + '">' + esc(b.action) + ' ' + ICON.arrow + '</a></div>';
  }

  function renderAnswer(res) {
    const x = res.rich || {};
    let html = '<div class="ae-answer">';
    html += '<p class="ae-lead" data-ae-stream>' + segs(x.lead) + '</p>';
    (x.more || []).forEach((m) => { html += '<p class="ae-rise">' + segs(m) + '</p>'; });
    const pts = x.points || [];
    const point = (p) => '<li class="ae-rise"><strong>' + esc(p.head) + ':</strong> ' + segs(p.body) + '</li>';
    if (pts.length) {
      html += '<ul class="ae-points">' + point(pts[0]) + '</ul>';
    }
    if (x.banner) {
      html += banner(x.banner);
    }
    if (pts.length > 1) {
      html += '<ul class="ae-points">' + pts.slice(1).map(point).join('') + '</ul>';
    }
    (x.closing || []).forEach((c) => { html += '<p class="ae-rise">' + segs(c) + '</p>'; });
    html += '</div>';

    html += '<div class="ae-actions ae-rise">'
      + '<button type="button" class="ae-act ae-act--text" data-ae-again>' + ICON.again + ' Ask again</button>'
      + '<button type="button" class="ae-act" data-ae-vote="up" aria-label="Helpful" aria-pressed="false">' + ICON.up + '</button>'
      + '<button type="button" class="ae-act" data-ae-vote="down" aria-label="Not helpful" aria-pressed="false">' + ICON.down + '</button>'
      + '<span class="ae-thanks" data-ae-thanks hidden>Thanks for the feedback</span>'
      + '<span class="ae-spacer"></span>'
      + '<button type="button" class="ae-act" data-ae-mail aria-label="Email this answer">' + ICON.mail + '</button>'
      + '<button type="button" class="ae-act" data-ae-copy aria-label="Copy this answer">' + ICON.copy + '</button>'
      + '</div>';

    if ((x.related || []).length) {
      html += '<h3 class="ae-h ae-rise">Related content</h3><div class="ae-cards ae-rise">';
      x.related.forEach((c) => {
        const icon = c.kind === 'project' ? ICON.site : (c.kind === 'register' ? ICON.user : ICON.doc);
        const bg = c.image ? ' style="background-image:url(\'' + esc(c.image) + '\')"' : '';
        const attrs = c.ask ? 'href="#" data-ae-ask="' + esc(c.ask) + '"' : 'href="' + esc(c.url) + '"';
        html += '<a class="ae-card ae-card--' + esc(c.kind) + (c.image ? ' ae-card--photo' : '') + '" ' + attrs + '>'
          + '<span class="ae-card-bg"' + bg + '></span><span class="ae-card-icon">' + icon + '</span>'
          + '<span class="ae-card-text"><b>' + esc(c.title) + '</b><small>' + esc(c.sub) + '</small><em>' + esc(c.action) + ' ' + ICON.arrow + '</em></span></a>';
      });
      html += '</div>';
    }
    if ((x.next || []).length) {
      html += '<div class="ae-next ae-rise"><h3 class="ae-h">' + ICON.next + ' What would you like to ask next?</h3>';
      x.next.forEach((q) => {
        html += '<button type="button" class="ae-next-q" data-ae-ask="' + esc(q) + '"><span>' + esc(q) + '</span>' + ICON.plus + '</button>';
      });
      html += '</div>';
    }
    const mode = res.mode === 'hybrid' ? 'Keywords + local vector model' : 'Keyword + concept search';
    html += '<p class="ae-meta">' + esc(mode) + ' · ' + esc(res.docs) + ' documents searched · ' + esc(res.took_ms) + ' ms</p>';
    return html;
  }

  function answerText(q, res) {
    const x = res.rich || {};
    const lines = [q, '', plain(x.lead)];
    (x.more || []).forEach((m) => lines.push(plain(m)));
    (x.points || []).forEach((p) => lines.push('• ' + p.head + ': ' + plain(p.body)));
    return lines.join('\n');
  }

  /**
   * The answer screen: one question and answer after another, like a chat.
   */
  function AnswerScreen(root, settings) {
    const thread = root.querySelector('[data-ae-thread]');
    const scroller = root.querySelector('[data-ae-scroll]');
    const form = root.querySelector('[data-ae-form]');
    const input = root.querySelector('[data-ae-input]');
    const send = root.querySelector('[data-ae-send]');
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const results = new WeakMap();
    let busy = null;
    let pushed = false;

    const setUrl = (q, push) => {
      const url = new URL(window.location.href);
      url.searchParams.set('ask', q);
      url.hash = '';
      if (push) {
        history.pushState({ ask: q }, '', url);
        pushed = true;
      }
      else {
        history.replaceState({ ask: q }, '', url);
      }
    };

    const open = () => {
      if (!root.hidden) {
        return;
      }
      root.hidden = false;
      document.documentElement.classList.add('ae-open');
      requestAnimationFrame(() => root.classList.add('is-in'));
    };

    const close = (fromHistory) => {
      if (root.hidden) {
        return;
      }
      if (busy) {
        busy.finish();
      }
      root.classList.remove('is-in');
      root.hidden = true;
      thread.innerHTML = '';
      document.documentElement.classList.remove('ae-open');
      if (!fromHistory) {
        if (pushed) {
          pushed = false;
          history.back();
        }
        else {
          const url = new URL(window.location.href);
          url.searchParams.delete('ask');
          history.replaceState({}, '', url);
        }
      }
    };

    // Reveal the lead word by word, then the rest of the answer.
    const stream = (turn, done) => {
      const lead = turn.querySelector('[data-ae-stream]');
      const rest = turn.querySelectorAll('.ae-rise');
      let stopped = false;
      const finish = () => {
        if (stopped) {
          return;
        }
        stopped = true;
        lead.querySelectorAll('.ae-w').forEach((w) => w.classList.add('is-on'));
        rest.forEach((el) => el.classList.add('is-on'));
        done();
      };
      if (reduce || !lead) {
        finish();
        return { finish };
      }
      // Wrap the words of text nodes (links and bold stay intact).
      const walker = document.createTreeWalker(lead, NodeFilter.SHOW_TEXT);
      const nodes = [];
      while (walker.nextNode()) {
        nodes.push(walker.currentNode);
      }
      nodes.forEach((n) => {
        const frag = document.createDocumentFragment();
        n.textContent.split(/(\s+)/).forEach((part) => {
          if (/^\s*$/.test(part)) {
            frag.appendChild(document.createTextNode(part));
          }
          else {
            const w = document.createElement('span');
            w.className = 'ae-w';
            w.textContent = part;
            frag.appendChild(w);
          }
        });
        n.parentNode.replaceChild(frag, n);
      });
      const words = lead.querySelectorAll('.ae-w');
      const step = Math.max(12, Math.min(38, 1400 / Math.max(words.length, 1)));
      let i = 0;
      const tick = () => {
        if (stopped) {
          return;
        }
        if (i < words.length) {
          words[i++].classList.add('is-on');
          setTimeout(tick, step);
          return;
        }
        let k = 0;
        const more = () => {
          if (stopped) {
            return;
          }
          if (k < rest.length) {
            rest[k++].classList.add('is-on');
            setTimeout(more, 110);
          }
          else {
            finish();
          }
        };
        more();
      };
      tick();
      return { finish };
    };

    const run = (q, turn) => {
      const query = String(q || '').trim();
      if (!query) {
        input.focus();
        return;
      }
      if (busy) {
        busy.finish();
      }
      open();
      if (!turn) {
        turn = document.createElement('section');
        turn.className = 'ae-turn';
        thread.appendChild(turn);
      }
      turn.innerHTML = '<h2 class="ae-q"><span class="lp-orb" aria-hidden="true"></span><span>' + esc(query) + '</span></h2>'
        + '<div class="ae-loading" data-ae-loading><span class="ae-orb-big" aria-hidden="true"></span><p data-ae-step>Generating the response</p></div>';
      input.value = '';
      root.classList.add('is-busy');
      requestAnimationFrame(() => {
        scroller.scrollTo({ top: Math.max(0, turn.offsetTop - 104), behavior: reduce ? 'auto' : 'smooth' });
      });
      const step = turn.querySelector('[data-ae-step]');
      const steps = ['Generating the response', 'Reading live requirements and projects', 'Ranking what fits your question', 'Writing the answer'];
      let si = 0;
      const timer = setInterval(() => { si = Math.min(si + 1, steps.length - 1); step.textContent = steps[si]; }, 520);
      let cancelled = false;
      busy = {
        finish: () => {
          cancelled = true;
          clearInterval(timer);
          const loading = turn.querySelector('[data-ae-loading]');
          if (loading) {
            loading.remove();
            turn.insertAdjacentHTML('beforeend', '<p class="ae-stopped">Stopped. Ask again whenever you are ready.</p>');
          }
          root.classList.remove('is-busy');
          busy = null;
        },
      };
      const started = Date.now();
      fetch(settings.endpoint + '?q=' + encodeURIComponent(query), { headers: { Accept: 'application/json' } })
        .then((r) => {
          if (!r.ok) {
            throw new Error('HTTP ' + r.status);
          }
          return r.json();
        })
        .then((res) => new Promise((resolve) => setTimeout(() => resolve(res), Math.max(0, 1500 - (Date.now() - started)))))
        .then((res) => {
          if (cancelled) {
            return;
          }
          clearInterval(timer);
          results.set(turn, { q: query, res });
          turn.querySelector('[data-ae-loading]').remove();
          turn.insertAdjacentHTML('beforeend', renderAnswer(res));
          const s = stream(turn, () => {
            root.classList.remove('is-busy');
            busy = null;
          });
          busy = { finish: s.finish };
        })
        .catch(() => {
          if (cancelled) {
            return;
          }
          clearInterval(timer);
          const loading = turn.querySelector('[data-ae-loading]');
          if (loading) {
            loading.outerHTML = '<p class="ae-stopped">Search is not available right now. Please try again in a moment.</p>';
          }
          root.classList.remove('is-busy');
          busy = null;
        });
    };

    // Opening from the page pushes a history entry, so Back closes it.
    this.ask = (q) => {
      const query = String(q || '').trim();
      if (!query) {
        return;
      }
      // Push only when opening from the page; a shared /?ask= link replaces.
      const first = root.hidden && !new URL(window.location.href).searchParams.get('ask');
      setUrl(query, first);
      run(query);
    };
    this.close = close;
    this.isOpen = () => !root.hidden;

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      if (busy) {
        busy.finish();
        return;
      }
      this.ask(input.value);
    });
    root.querySelector('[data-ae-close]').addEventListener('click', () => close(false));
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !root.hidden) {
        close(false);
      }
    });
    window.addEventListener('popstate', () => {
      const q = new URL(window.location.href).searchParams.get('ask');
      if (!q) {
        pushed = false;
        close(true);
      }
      else if (root.hidden) {
        run(q);
      }
    });

    thread.addEventListener('click', (e) => {
      const ask = e.target.closest('[data-ae-ask]');
      if (ask) {
        e.preventDefault();
        this.ask(ask.getAttribute('data-ae-ask'));
        return;
      }
      const turn = e.target.closest('.ae-turn');
      const data = turn && results.get(turn);
      if (e.target.closest('[data-ae-again]') && data) {
        run(data.q, turn);
        return;
      }
      const vote = e.target.closest('[data-ae-vote]');
      if (vote) {
        turn.querySelectorAll('[data-ae-vote]').forEach((b) => b.setAttribute('aria-pressed', b === vote ? 'true' : 'false'));
        const thanks = turn.querySelector('[data-ae-thanks]');
        thanks.hidden = false;
        return;
      }
      if (e.target.closest('[data-ae-copy]') && data) {
        const btn = e.target.closest('[data-ae-copy]');
        const ok = () => { btn.classList.add('is-done'); setTimeout(() => btn.classList.remove('is-done'), 1400); };
        if (navigator.clipboard) {
          navigator.clipboard.writeText(answerText(data.q, data.res)).then(ok, () => {});
        }
        return;
      }
      if (e.target.closest('[data-ae-mail]') && data) {
        window.location.href = 'mailto:?subject=' + encodeURIComponent('Infra Radar: ' + data.q) + '&body=' + encodeURIComponent(answerText(data.q, data.res) + '\n\n' + window.location.href);
        return;
      }
      // Links to sections of the homepage: close the answers and go there.
      const a = e.target.closest('a[href^="#"]');
      if (a && a.getAttribute('href').length > 1) {
        const target = document.querySelector(a.getAttribute('href'));
        if (target) {
          e.preventDefault();
          close(false);
          setTimeout(() => {
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            target.classList.add('lp-flash');
            setTimeout(() => target.classList.remove('lp-flash'), 1800);
          }, 60);
        }
      }
    });
  }

  Drupal.behaviors.sthiraLanding = {
    attach(context) {
      const settings = drupalSettings.sthiraSearch;
      if (!settings) {
        return;
      }
      once('ae', '[data-ae]', context).forEach((root) => {
        const screen = new AnswerScreen(root, settings);
        Drupal.sthiraAnswers = screen;

        once('lp-search', '[data-lp-search]', document).forEach((box) => {
          const form = box.querySelector('[data-lp-form]');
          const input = box.querySelector('[data-lp-input]');
          form.addEventListener('submit', (e) => {
            e.preventDefault();
            const q = input.value.trim();
            if (!q) {
              input.focus();
              return;
            }
            input.value = '';
            screen.ask(q);
          });
          box.querySelectorAll('[data-lp-try]').forEach((b) => b.addEventListener('click', () => screen.ask(b.getAttribute('data-lp-try'))));
        });
        document.querySelectorAll('[data-lp-try-link]').forEach((a) => a.addEventListener('click', (e) => {
          e.preventDefault();
          screen.ask(a.getAttribute('data-lp-try-link'));
        }));

        // A shared or refreshed link: /?ask=… opens the answer straight away.
        const q = new URL(window.location.href).searchParams.get('ask');
        if (q) {
          screen.ask(q);
        }
      });

      // Banner slideshow: one project at a time, with what it needs.
      once('lp-show', '[data-lp-hero]', context).forEach((art) => {
        const slides = art.querySelectorAll('[data-lp-slide]');
        const needs = art.querySelectorAll('[data-lp-need-item]');
        const dots = art.querySelectorAll('[data-lp-dot]');
        if (slides.length < 2) {
          return;
        }
        let current = 0;
        let timer = null;
        const show = (i) => {
          current = (i + slides.length) % slides.length;
          [slides, needs, dots].forEach((list) => list.forEach((el, k) => {
            el.classList.toggle('is-active', k === current);
            if (el.hasAttribute('data-lp-slide')) {
              el.setAttribute('aria-hidden', k === current ? 'false' : 'true');
            }
          }));
        };
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const start = () => {
          if (!reduce) {
            clearInterval(timer);
            timer = setInterval(() => show(current + 1), 6000);
          }
        };
        dots.forEach((d) => d.addEventListener('click', () => {
          show(parseInt(d.getAttribute('data-lp-dot'), 10));
          start();
        }));
        art.addEventListener('mouseenter', () => clearInterval(timer));
        art.addEventListener('mouseleave', start);
        start();
      });

      // Smooth scrolling for in-page links.
      once('lp-anchors', 'a[href^="#"]', context).forEach((a) => {
        if (a.hasAttribute('data-lp-try-link')) {
          return;
        }
        a.addEventListener('click', (e) => {
          const href = a.getAttribute('href');
          const target = href.length > 1 ? document.querySelector(href) : null;
          if (target) {
            e.preventDefault();
            if (Drupal.sthiraAnswers && Drupal.sthiraAnswers.isOpen()) {
              Drupal.sthiraAnswers.close(false);
            }
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        });
      });
    },
  };
})(Drupal, drupalSettings, once);
