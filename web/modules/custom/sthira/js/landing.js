/**
 * @file
 * Homepage search. Sends the question to /api/search (our own index, ranked
 * on the server) and shows the answer, what was understood and the matches.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function render(res, settings, query) {
    const register = (res.urls && res.urls.register) || settings.register;
    let html = '';
    if (res.understood && res.understood.length) {
      html += '<div class="lp-understood"><span class="lp-understood-k">Understood</span>';
      res.understood.forEach((c) => {
        html += '<span class="lp-uchip"><b>' + esc(c.label) + '</b> ' + esc(c.value) + '</span>';
      });
      html += '</div>';
    }
    if (res.answer) {
      html += '<div class="lp-answer"><h3><span class="lp-orb"></span>' + esc(res.answer.title) + '</h3><p>' + esc(res.answer.text) + '</p>';
      if (res.answer.extra) {
        html += '<p class="lp-answer-extra">' + esc(res.answer.extra) + '</p>';
      }
      if (res.cta === 'register') {
        html += '<a class="lp-arrow" href="' + esc(register) + '">Register as a supplier <span aria-hidden="true">→</span></a>';
      }
      html += '</div>';
    }
    (res.groups || []).forEach((g) => {
      html += '<div class="lp-res-group"><h4>' + esc(g.label) + '</h4><ul class="lp-res-list">';
      g.items.forEach((it) => {
        let action = '';
        if (it.ask) {
          action = '<button type="button" class="lp-arrow lp-ask" data-lp-ask="' + esc(it.ask) + '">' + esc(it.action) + ' <span aria-hidden="true">→</span></button>';
        }
        else {
          const href = it.cta === 'register' ? register : (it.url || '#');
          action = '<a class="lp-arrow" href="' + esc(href) + '">' + esc(it.action || 'View') + ' <span aria-hidden="true">→</span></a>';
        }
        const badge = it.badge ? ' <span class="lp-badge' + (it.hot ? ' lp-badge--hot' : '') + '">' + esc(it.badge) + '</span>' : '';
        html += '<li><span><strong>' + esc(it.title) + badge + '</strong><small>' + esc(it.meta) + '</small></span>' + action + '</li>';
      });
      html += '</ul></div>';
    });
    if (!res.answer && !(res.groups || []).length) {
      html += '<div class="lp-nores">Nothing matched “' + esc(query) + '”. Try a material (steel, cement, tiles), a city (Pune, Kolkata), “active projects” or a question like “how do I register?”. Suppliers who <a class="lp-arrow" href="' + esc(register) + '">register</a> are notified when we need their materials.</div>';
    }
    const mode = res.mode === 'hybrid' ? 'Hybrid search: keywords + local vector model' : 'Keyword + concept search';
    html += '<p class="lp-meta">' + esc(mode) + ' · ' + esc(res.docs) + ' documents · ' + esc(res.took_ms) + ' ms</p>';
    return html;
  }

  Drupal.behaviors.sthiraLanding = {
    attach(context) {
      const settings = drupalSettings.sthiraSearch;
      if (!settings) {
        return;
      }
      once('lp-search', '[data-lp-search]', context).forEach((box) => {
        const form = box.querySelector('[data-lp-form]');
        const input = box.querySelector('[data-lp-input]');
        const thinking = box.querySelector('[data-lp-thinking]');
        const step = box.querySelector('[data-lp-step]');
        const out = box.querySelector('[data-lp-results]');
        let seq = 0;

        const run = (q) => {
          const query = (q ?? input.value).trim();
          if (!query) {
            input.focus();
            return;
          }
          input.value = query;
          const mine = ++seq;
          out.hidden = true;
          thinking.hidden = false;
          const steps = ['Understanding your question…', 'Searching the index…', 'Ranking matches…'];
          let i = 0;
          step.textContent = steps[0];
          const timer = setInterval(() => {
            i = Math.min(i + 1, steps.length - 1);
            step.textContent = steps[i];
          }, 280);
          const started = Date.now();
          fetch(settings.endpoint + '?q=' + encodeURIComponent(query), { headers: { Accept: 'application/json' } })
            .then((r) => {
              if (!r.ok) {
                throw new Error('HTTP ' + r.status);
              }
              return r.json();
            })
            .then((res) => new Promise((resolve) => setTimeout(() => resolve(res), Math.max(0, 650 - (Date.now() - started)))))
            .then((res) => {
              if (mine !== seq) {
                return;
              }
              clearInterval(timer);
              thinking.hidden = true;
              out.innerHTML = render(res, settings, query);
              out.hidden = false;
            })
            .catch(() => {
              if (mine !== seq) {
                return;
              }
              clearInterval(timer);
              thinking.hidden = true;
              out.innerHTML = '<div class="lp-nores">Search is not available right now. Please try again in a moment.</div>';
              out.hidden = false;
            });
        };

        form.addEventListener('submit', (e) => {
          e.preventDefault();
          run();
        });
        box.querySelectorAll('[data-lp-try]').forEach((b) => b.addEventListener('click', () => run(b.getAttribute('data-lp-try'))));
        out.addEventListener('click', (e) => {
          const ask = e.target.closest('[data-lp-ask]');
          if (ask) {
            run(ask.getAttribute('data-lp-ask'));
          }
        });
        document.querySelectorAll('[data-lp-try-link]').forEach((a) => a.addEventListener('click', () => {
          setTimeout(() => run(a.getAttribute('data-lp-try-link')), 300);
        }));
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
        a.addEventListener('click', (e) => {
          const href = a.getAttribute('href');
          const target = href.length > 1 ? document.querySelector(href) : null;
          if (target) {
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        });
      });
      // Links inside search results are added later.
      once('lp-result-anchors', '[data-lp-results]', context).forEach((out) => {
        out.addEventListener('click', (e) => {
          const a = e.target.closest('a[href^="#"]');
          const target = a && a.getAttribute('href').length > 1 ? document.querySelector(a.getAttribute('href')) : null;
          if (target) {
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            target.classList.add('lp-flash');
            setTimeout(() => target.classList.remove('lp-flash'), 1600);
          }
        });
      });
    },
  };
})(Drupal, drupalSettings, once);
