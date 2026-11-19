/**
 * @file
 * Ask the data: copy the SQL query.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.sthiraAskData = {
    attach(context) {
      once('askd-copy', '[data-askd-copy]', context).forEach((btn) => {
        btn.addEventListener('click', () => {
          const code = btn.closest('.askd-sql-body').querySelector('[data-askd-sql]');
          if (!code || !navigator.clipboard) {
            return;
          }
          navigator.clipboard.writeText(code.textContent).then(() => {
            btn.textContent = 'Copied';
            setTimeout(() => { btn.textContent = 'Copy query'; }, 1500);
          });
        });
      });
    },
  };
})(Drupal, once);
