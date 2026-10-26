(() => {
  'use strict';

  const shell = document.querySelector('[data-warehouse-station]');
  if (!shell) return;

  document.body.classList.add('warehouse-station-active');

  const open = () => document.body.classList.add('warehouse-station-menu-open');
  const close = () => document.body.classList.remove('warehouse-station-menu-open');

  shell.querySelectorAll('[data-station-menu-open]').forEach((button) => {
    button.addEventListener('click', open);
  });

  shell.querySelectorAll('[data-station-menu-close]').forEach((button) => {
    button.addEventListener('click', close);
  });

  shell.querySelectorAll('.warehouse-station-nav a').forEach((link) => {
    link.addEventListener('click', close);
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') close();
  });
})();
