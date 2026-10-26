(() => {
  'use strict';

  const source = document.querySelector('#userAccessContext');
  const form = document.querySelector('[data-user-access-form]');
  const modalElement = document.querySelector('#userAccessModal');
  const scopeTemplate = document.querySelector('#scopeRowTemplate');
  if (!source || !form || !modalElement || !scopeTemplate || !globalThis.GMar) return;

  const context = JSON.parse(source.textContent || '{}');
  const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
  const roleSelect = form.elements.namedItem('base_role');
  const username = form.elements.namedItem('username');
  const email = form.elements.namedItem('email');
  const password = form.elements.namedItem('password');
  const passwordWrapper = form.querySelector('[data-password-wrapper]');
  const scopeList = form.querySelector('[data-scope-list]');
  const roleSummary = form.querySelector('[data-role-summary]');
  let editingUserId = null;
  let loadingUser = false;

  const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;').replaceAll("'", '&#039;');

  const usersById = new Map((context.users || []).map((user) => [Number(user.id), user]));
  const role = (code) => context.roles?.[code] || null;
  const permissionInputs = () => Array.from(form.querySelectorAll('input[name="permissions[]"]'));
  const selectedPermissions = () => permissionInputs().filter((input) => input.checked).map((input) => input.value).sort();

  function setPermissions(permissions) {
    const allowed = new Set((permissions || []).map(String));
    permissionInputs().forEach((input) => { input.checked = allowed.has(input.value); });
    renderRoleSummary();
  }

  function applyRoleDefaults() {
    const selectedRole = role(roleSelect.value);
    setPermissions(selectedRole?.permissions || []);
  }

  function renderRoleSummary() {
    const selectedRole = role(roleSelect.value);
    if (!selectedRole) {
      roleSummary.innerHTML = '<span class="text-danger">Rol no disponible.</span>';
      return;
    }

    const defaults = new Set((selectedRole.permissions || []).map(String));
    const selected = new Set(selectedPermissions());
    const added = [...selected].filter((permission) => !defaults.has(permission));
    const removed = [...defaults].filter((permission) => !selected.has(permission));
    const changed = added.length > 0 || removed.length > 0;

    roleSummary.innerHTML = `
      <div class="d-flex align-items-start gap-2">
        <i class="bi ${escapeHtml(selectedRole.icon || 'bi-person-badge')} fs-5 text-primary"></i>
        <div class="flex-grow-1">
          <div class="fw-bold">${escapeHtml(selectedRole.title)}</div>
          <div class="small text-secondary mt-1">${escapeHtml(selectedRole.description)}</div>
          <div class="small mt-2"><strong>${defaults.size}</strong> permisos base · <strong>${selected.size}</strong> seleccionados</div>
          ${changed ? `<div class="permission-diff text-primary mt-1">Personalizado: +${added.length} / -${removed.length}</div>` : '<div class="permission-diff text-success mt-1">Coincide con el rol base</div>'}
        </div>
      </div>`;
  }

  function options(items, selected, placeholder, labeler) {
    const first = `<option value="">${escapeHtml(placeholder)}</option>`;
    return first + items.map((item) => {
      const value = Number(item.id);
      return `<option value="${value}" ${Number(selected) === value ? 'selected' : ''}>${escapeHtml(labeler(item))}</option>`;
    }).join('');
  }

  function refreshScopeRow(row, values = {}) {
    const companySelect = row.querySelector('[data-scope-company]');
    const siteSelect = row.querySelector('[data-scope-site]');
    const warehouseSelect = row.querySelector('[data-scope-warehouse]');

    const companyId = Number(values.company_id || companySelect.value || 0);
    companySelect.innerHTML = options(context.companies || [], companyId, 'Selecciona empresa…', (item) => `${item.code} · ${item.trade_name || item.legal_name}`);

    const sites = (context.sites || []).filter((item) => Number(item.company_id) === companyId);
    const siteId = Number(values.site_id || siteSelect.value || 0);
    siteSelect.innerHTML = options(sites, siteId, 'Selecciona instalación…', (item) => `${item.code} · ${item.name}`);

    const warehouses = (context.warehouses || []).filter((item) => Number(item.company_id) === companyId && Number(item.site_id) === siteId);
    const warehouseId = Number(values.warehouse_id || warehouseSelect.value || 0);
    warehouseSelect.innerHTML = options(warehouses, warehouseId, 'Todos los almacenes de la instalación', (item) => `${item.code} · ${item.name}`);
  }

  function addScope(values = {}) {
    const fragment = scopeTemplate.content.cloneNode(true);
    const row = fragment.querySelector('[data-scope-row]');
    scopeList.appendChild(fragment);
    refreshScopeRow(row, values);

    const company = row.querySelector('[data-scope-company]');
    const site = row.querySelector('[data-scope-site]');

    company.addEventListener('change', () => {
      const companyId = Number(company.value || 0);
      refreshScopeRow(row, { company_id: companyId, site_id: 0, warehouse_id: 0 });
    });

    site.addEventListener('change', () => {
      refreshScopeRow(row, {
        company_id: Number(company.value || 0),
        site_id: Number(site.value || 0),
        warehouse_id: 0,
      });
    });

    row.querySelector('[data-scope-remove]').addEventListener('click', () => {
      if (scopeList.querySelectorAll('[data-scope-row]').length <= 1) {
        Swal.fire({ icon: 'warning', text: 'El usuario debe conservar al menos un alcance.' });
        return;
      }
      row.remove();
    });
  }

  function collectScopes() {
    return Array.from(scopeList.querySelectorAll('[data-scope-row]')).map((row) => ({
      company_id: Number(row.querySelector('[data-scope-company]').value || 0),
      site_id: Number(row.querySelector('[data-scope-site]').value || 0),
      warehouse_id: row.querySelector('[data-scope-warehouse]').value ? Number(row.querySelector('[data-scope-warehouse]').value) : null,
    }));
  }

  function resetModalForCreate() {
    editingUserId = null;
    loadingUser = true;
    form.reset();
    document.querySelector('#userAccessTitle').textContent = 'Crear usuario';
    username.readOnly = false;
    email.readOnly = false;
    password.required = true;
    password.value = '';
    passwordWrapper.hidden = false;
    scopeList.innerHTML = '';
    if (roleSelect.options.length) roleSelect.selectedIndex = 0;
    applyRoleDefaults();
    addScope();
    loadingUser = false;
  }

  function openEdit(userId) {
    const user = usersById.get(Number(userId));
    if (!user || user.is_protected) return;

    editingUserId = Number(user.id);
    loadingUser = true;
    form.reset();
    document.querySelector('#userAccessTitle').textContent = `Acceso de ${user.username}`;
    username.value = user.username || '';
    email.value = user.email || '';
    username.readOnly = true;
    email.readOnly = true;
    password.required = false;
    password.value = '';
    passwordWrapper.hidden = true;
    roleSelect.value = user.base_role || Object.keys(context.roles || {})[0] || '';

    // Para usuarios heredados preservamos lo que realmente pueden hacer al abrir.
    // Sólo cambia al perfil base si el administrador usa "Aplicar permisos base".
    setPermissions(user.effective_permissions || []);

    scopeList.innerHTML = '';
    const scopes = Array.isArray(user.scopes) && user.scopes.length ? user.scopes : [{}];
    scopes.forEach(addScope);
    renderRoleSummary();
    loadingUser = false;
    modal.show();
  }

  async function showPermissionDetail(userId) {
    const user = usersById.get(Number(userId));
    if (!user) return;
    const effective = new Set(user.effective_permissions || []);
    const sections = Object.values(context.permissionSections || {});
    const html = sections.map((section) => {
      const rows = (section.permissions || []).filter((permission) => context.permissions?.[permission]).map((permission) => {
        const granted = effective.has(permission);
        return `<div class="d-flex align-items-start gap-2 py-1"><i class="bi ${granted ? 'bi-check-circle-fill text-success' : 'bi-x-circle text-secondary'}"></i><div><strong>${escapeHtml(permission)}</strong><div class="small text-secondary">${escapeHtml(context.permissions[permission])}</div></div></div>`;
      }).join('');
      return `<div class="text-start mb-3"><div class="fw-bold border-bottom pb-1 mb-1">${escapeHtml(section.title)}</div>${rows}</div>`;
    }).join('');

    await Swal.fire({
      title: `Permisos efectivos · ${escapeHtml(user.username)}`,
      html,
      width: 760,
      confirmButtonText: 'Cerrar',
    });
  }

  roleSelect.addEventListener('change', () => {
    if (loadingUser) return;
    applyRoleDefaults();
  });
  permissionInputs().forEach((input) => input.addEventListener('change', renderRoleSummary));
  form.querySelector('[data-apply-role-defaults]').addEventListener('click', applyRoleDefaults);
  form.querySelector('[data-permissions-clear]').addEventListener('click', () => setPermissions([]));
  form.querySelector('[data-scope-add]').addEventListener('click', () => addScope());

  document.querySelector('[data-user-new]')?.addEventListener('click', resetModalForCreate);
  document.querySelectorAll('[data-user-edit]').forEach((button) => button.addEventListener('click', () => openEdit(button.dataset.userEdit)));
  document.querySelectorAll('[data-user-permission-view]').forEach((button) => button.addEventListener('click', () => showPermissionDetail(button.dataset.userPermissionView)));

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    event.stopImmediatePropagation();

    const scopes = collectScopes();
    if (scopes.some((scope) => scope.company_id <= 0 || scope.site_id <= 0)) {
      return Swal.fire({ icon: 'warning', title: 'Alcance incompleto', text: 'Selecciona Empresa e Instalación en todos los alcances.' });
    }

    const payload = {
      base_role: roleSelect.value,
      permissions: selectedPermissions(),
      scopes,
    };

    let url;
    let method;
    if (editingUserId) {
      url = `/internal/v1/users/${editingUserId}/group`;
      method = 'PUT';
    } else {
      payload.username = String(username.value || '').trim();
      payload.email = String(email.value || '').trim();
      payload.password = String(password.value || '');
      url = '/internal/v1/users';
      method = 'POST';
    }

    const submit = form.querySelector('[type="submit"]');
    submit.disabled = true;
    try {
      await GMar.api(url, { method, body: JSON.stringify(payload) });
      await Swal.fire({
        icon: 'success',
        title: editingUserId ? 'Acceso actualizado' : 'Usuario creado',
        text: 'Rol, permisos y alcances quedaron guardados.',
        timer: 1700,
        showConfirmButton: false,
      });
      location.reload();
    } catch (error) {
      await Swal.fire({ icon: 'error', title: error.code || 'No se pudo guardar', text: error.message });
      submit.disabled = false;
    }
  });

  document.querySelectorAll('[data-user-status]').forEach((button) => button.addEventListener('click', async () => {
    const active = button.dataset.active !== '1';
    const confirmation = await Swal.fire({
      icon: 'question',
      title: active ? '¿Activar usuario?' : '¿Desactivar usuario?',
      text: active ? 'La cuenta podrá volver a iniciar sesión.' : 'La cuenta dejará de operar hasta ser reactivada.',
      showCancelButton: true,
      confirmButtonText: active ? 'Activar' : 'Desactivar',
      cancelButtonText: 'Cancelar',
    });
    if (!confirmation.isConfirmed) return;

    try {
      await GMar.api(`/internal/v1/users/${button.dataset.userStatus}/status`, { method: 'PUT', body: JSON.stringify({ active }) });
      location.reload();
    } catch (error) {
      Swal.fire({ icon: 'error', title: error.code || 'Cambio rechazado', text: error.message });
    }
  }));
})();
