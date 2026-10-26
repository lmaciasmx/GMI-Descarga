import { cp, mkdir } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';

const files = {
  'node_modules/bootstrap/dist/css/bootstrap.min.css': 'public/vendor/bootstrap/css/bootstrap.min.css',
  'node_modules/bootstrap/dist/js/bootstrap.bundle.min.js': 'public/vendor/bootstrap/js/bootstrap.bundle.min.js',
  'node_modules/bootstrap-icons/font/bootstrap-icons.min.css': 'public/vendor/bootstrap-icons/bootstrap-icons.min.css',
  'node_modules/bootstrap-icons/font/fonts': 'public/vendor/bootstrap-icons/fonts',
  'node_modules/jquery/dist/jquery.min.js': 'public/vendor/jquery/jquery.min.js',
  'node_modules/datatables.net/js/dataTables.min.js': 'public/vendor/datatables/dataTables.min.js',
  'node_modules/datatables.net-bs5/js/dataTables.bootstrap5.min.js': 'public/vendor/datatables/dataTables.bootstrap5.min.js',
  'node_modules/datatables.net-bs5/css/dataTables.bootstrap5.min.css': 'public/vendor/datatables/dataTables.bootstrap5.min.css',
  'node_modules/datatables.net-buttons/js/dataTables.buttons.min.js': 'public/vendor/datatables/dataTables.buttons.min.js',
  'node_modules/datatables.net-buttons/js/buttons.html5.min.js': 'public/vendor/datatables/buttons.html5.min.js',
  'node_modules/datatables.net-buttons-bs5/js/buttons.bootstrap5.min.js': 'public/vendor/datatables/buttons.bootstrap5.min.js',
  'node_modules/datatables.net-buttons-bs5/css/buttons.bootstrap5.min.css': 'public/vendor/datatables/buttons.bootstrap5.min.css',
  'node_modules/jszip/dist/jszip.min.js': 'public/vendor/jszip/jszip.min.js',
  'node_modules/chart.js/dist/chart.umd.js': 'public/vendor/chartjs/chart.umd.js',
  'node_modules/sweetalert2/dist/sweetalert2.all.min.js': 'public/vendor/sweetalert2/sweetalert2.all.min.js',
  'node_modules/sweetalert2/dist/sweetalert2.min.css': 'public/vendor/sweetalert2/sweetalert2.min.css',
  'node_modules/select2/dist/js/select2.full.min.js': 'public/vendor/select2/select2.full.min.js',
  'node_modules/select2/dist/css/select2.min.css': 'public/vendor/select2/select2.min.css',
  'node_modules/@zxing/browser/umd/zxing-browser.min.js': 'public/vendor/zxing/zxing-browser.min.js'
};

for (const [source, destination] of Object.entries(files)) {
  const target = resolve(destination);
  await mkdir(dirname(target), { recursive: true });
  await cp(resolve(source), target, { recursive: true });
}

process.stdout.write(`Activos copiados: ${Object.keys(files).length}\n`);
