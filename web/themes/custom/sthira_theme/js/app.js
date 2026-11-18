/**
 * @file
 * Small UI touches: count-up KPIs, clickable rows, demo login, switcher.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.sthiraApp = {
    attach(context) {
      once('sthira-count', '[data-count]', context).forEach((el) => {
        const target = parseInt(el.getAttribute('data-count'), 10);
        if (!target || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
          return;
        }
        const start = performance.now();
        const dur = 700;
        const tick = (now) => {
          const p = Math.min(1, (now - start) / dur);
          el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3)));
          if (p < 1) {
            requestAnimationFrame(tick);
          }
        };
        el.textContent = '0';
        requestAnimationFrame(tick);
      });

      once('sthira-row', 'tr[data-href]', context).forEach((tr) => {
        tr.addEventListener('click', (e) => {
          if (e.target.closest('a, button')) {
            return;
          }
          window.location.href = tr.getAttribute('data-href');
        });
      });

      once('sthira-demo', '.demo-account', context).forEach((btn) => {
        btn.addEventListener('click', () => {
          const form = document.querySelector('form.user-login-form');
          if (!form) {
            return;
          }
          form.querySelector('[name="name"]').value = btn.getAttribute('data-username');
          form.querySelector('[name="pass"]').value = btn.getAttribute('data-password');
          btn.classList.add('is-picked');
          form.querySelector('[type="submit"]').click();
        });
      });

      once('sthira-switcher', 'details.switcher', context).forEach((d) => {
        document.addEventListener('click', (e) => {
          if (!d.contains(e.target)) {
            d.removeAttribute('open');
          }
        });
      });
    },
  };
})(Drupal, once);
