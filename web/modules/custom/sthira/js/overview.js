/**
 * @file
 * Director overview: one tooltip for every chart mark (hover and keyboard).
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.sthiraOverview = {
    attach(context) {
      once('ov-tip', '[data-ov-tip]', context).forEach((tip) => {
        const show = (mark) => {
          const [title, ...lines] = (mark.getAttribute('data-tip') || '').split('|');
          tip.textContent = '';
          const b = document.createElement('strong');
          b.textContent = title;
          tip.appendChild(b);
          lines.forEach((line) => {
            const s = document.createElement('span');
            s.textContent = line;
            tip.appendChild(s);
          });
          tip.hidden = false;
          const r = mark.getBoundingClientRect();
          const t = tip.getBoundingClientRect();
          let x = r.left + r.width / 2 - t.width / 2;
          x = Math.max(8, Math.min(x, window.innerWidth - t.width - 8));
          let y = r.top - t.height - 10;
          if (y < 8) {
            y = r.bottom + 10;
          }
          tip.style.left = x + 'px';
          tip.style.top = y + 'px';
          mark.classList.add('is-hot');
        };
        const hide = (mark) => {
          tip.hidden = true;
          mark.classList.remove('is-hot');
        };
        document.querySelectorAll('.ov [data-tip]').forEach((mark) => {
          mark.addEventListener('mouseenter', () => show(mark));
          mark.addEventListener('mouseleave', () => hide(mark));
          mark.addEventListener('focus', () => show(mark));
          mark.addEventListener('blur', () => hide(mark));
        });
        window.addEventListener('scroll', () => { tip.hidden = true; }, { passive: true });
      });
    },
  };
})(Drupal, once);
