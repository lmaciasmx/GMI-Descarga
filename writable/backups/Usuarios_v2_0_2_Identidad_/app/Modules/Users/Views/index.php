<?= $this->extend('layouts/app') ?>

<?= $this->section('styles') ?>
<style>
.user-metric{border:0;border-radius:16px;box-shadow:0 7px 24px rgba(15,43,65,.06)}
.user-metric .metric-number{font-size:1.65rem;font-weight:800;color:#0b2942;line-height:1}
.user-metric .metric-title{font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;color:#657789;font-weight:700}
.access-badge{display:inline-flex;align-items:center;gap:.35rem;border:1px solid #dfe8ee;background:#f8fbfd;color:#30475a;border-radius:999px;padding:.28rem .55rem;font-size:.75rem;margin:.12rem .15rem .12rem 0}
.role-pill{display:inline-flex;align-items:center;gap:.45rem;padding:.38rem .68rem;border-radius:999px;background:#eef6fa;color:#0d5f82;font-size:.78rem;font-weight:700}
.role-pill.legacy{background:#fff6df;color:#8a5a00}
.role-pill.protected{background:#f1eefb;color:#5c3d8c}
.permission-count{font-weight:800;color:#0b2942}
.permission-section{border:1px solid #e3ebf0;border-radius:14px;padding:12px 14px;background:#fff;height:100%}
.permission-section-title{font-size:.8rem;font-weight:800;color:#31495c;text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px}
.permission-check{display:flex;gap:.55rem;align-items:flex-start;padding:.35rem 0}
.permission-check small{display:block;color:#71818f;line-height:1.2}
.role-summary{border:1px solid #dfeaf0;border-radius:14px;background:#f8fbfd;padding:14px}
.scope-row{border:1px solid #e1e9ee;border-radius:14px;padding:12px;background:#fbfdfe}
.scope-row+.scope-row{margin-top:10px}
.user-access-modal .modal-dialog{max-width:1180px;height:calc(100vh - 1rem);height:calc(100dvh - 1rem);margin:.5rem auto}
.user-access-modal .modal-content{max-height:calc(100vh - 1rem);max-height:calc(100dvh - 1rem);overflow:hidden}
.user-access-modal form{display:flex;flex-direction:column;min-height:0;height:100%}
.user-access-modal .modal-header,.user-access-modal .modal-footer{flex:0 0 auto}
.user-access-modal .modal-body{background:#f5f8fa;overflow-y:auto;min-height:0}
.user-access-modal .modal-footer{position:sticky;bottom:0;z-index:6;background:#fff;border-top:1px solid #dfe7ec;box-shadow:0 -8px 20px rgba(15,43,65,.08)}
.user-permissions-popup{max-height:calc(100vh - 1.5rem)!important;max-height:calc(100dvh - 1.5rem)!important;overflow:hidden!important}
.user-permissions-scroll{max-height:calc(100vh - 12rem)!important;max-height:calc(100dvh - 12rem)!important;overflow-y:auto!important;overscroll-behavior:contain;text-align:left!important;padding-right:.35rem!important}
.user-permissions-popup .swal2-actions{flex:0 0 auto;margin-top:.75rem!important}
.user-panel{background:#fff;border:1px solid #e2e9ee;border-radius:16px;padding:18px}
.user-panel-title{font-size:.9rem;font-weight:800;color:#0b2942;margin-bottom:12px}
.access-note{border-left:4px solid #0d6efd;background:#eef6ff;border-radius:8px;padding:10px 12px;font-size:.82rem;color:#385064}
.permission-diff{font-size:.76rem;font-weight:700}
@media(max-width:767.98px){.user-access-modal .modal-dialog{margin:.25rem;height:calc(100vh - .5rem);height:calc(100dvh - .5rem)}.user-access-modal .modal-content{max-height:calc(100vh - .5rem);max-height:calc(100dvh - .5rem)}.user-panel{padding:14px}.user-permissions-scroll{max-height:calc(100vh - 10.5rem)!important;max-height:calc(100dvh - 10.5rem)!important}}
</style>
<?= $this->endSection() ?>

<?= $this->section('pageActions') ?>
<button class="btn btn-primary btn-operational" type="button" data-user-new data-bs-toggle="modal" data-bs-target="#userAccessModal">
    <i class="bi bi-person-plus"></i> Crear usuario
</button>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$context = [
    'roles' => $roles,
    'permissions' => $permissions,
    'permissionSections' => $permissionSections,
    'companies' => $companies,
    'sites' => $sites,
    'warehouses' => $warehouses,
    'users' => array_values($userMeta),
];
?>
<div hidden id="userAccessContext"><?= json_encode($context, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></div>

<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><div class="card user-metric h-100"><div class="card-body"><div class="metric-title">Usuarios</div><div class="metric-number mt-2"><?= (int) $metrics['users'] ?></div><small class="text-secondary">Identidades registradas</small></div></div></div>
    <div class="col-6 col-xl-3"><div class="card user-metric h-100"><div class="card-body"><div class="metric-title">Activos</div><div class="metric-number mt-2"><?= (int) $metrics['active'] ?></div><small class="text-secondary">Con cuenta habilitada</small></div></div></div>
    <div class="col-6 col-xl-3"><div class="card user-metric h-100"><div class="card-body"><div class="metric-title">Roles base</div><div class="metric-number mt-2"><?= (int) $metrics['roles'] ?></div><small class="text-secondary">Perfiles simplificados</small></div></div></div>
    <div class="col-6 col-xl-3"><div class="card user-metric h-100"><div class="card-body"><div class="metric-title">Alcances</div><div class="metric-number mt-2"><?= (int) $metrics['scopes'] ?></div><small class="text-secondary">Asignaciones activas</small></div></div></div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <div class="fw-bold">Identidades y acceso efectivo</div>
            <small class="text-secondary">El rol propone permisos; los permisos seleccionados y el alcance determinan el acceso real.</small>
        </div>
        <span class="badge text-bg-light"><?= count($users) ?></span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle" data-datatable>
                <thead><tr><th>Usuario</th><th>Rol base</th><th>Permisos</th><th>Alcance</th><th>Última actividad</th><th>Estado</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($users as $item): $meta = $userMeta[(int) $item->id]; ?>
                    <tr>
                        <td>
                            <div class="fw-bold"><?= esc($item->username ?? '—') ?></div>
                            <small class="text-secondary"><?= esc($item->email ?? '—') ?></small>
                        </td>
                        <td>
                            <span class="role-pill <?= $meta['is_protected'] ? 'protected' : (! $meta['is_v2'] ? 'legacy' : '') ?>">
                                <i class="bi <?= $meta['is_protected'] ? 'bi-shield-lock-fill' : 'bi-person-badge' ?>"></i>
                                <?= esc($meta['role_title']) ?>
                            </span>
                            <?php if ($meta['is_customized']): ?><div class="permission-diff text-primary mt-1">Permisos personalizados</div><?php endif ?>
                        </td>
                        <td>
                            <span class="permission-count"><?= count($meta['effective_permissions']) ?></span>
                            <span class="text-secondary">de <?= count($permissions) ?></span>
                            <button class="btn btn-link btn-sm px-1" type="button" data-user-permission-view="<?= (int) $item->id ?>">Ver</button>
                        </td>
                        <td style="min-width:250px">
                            <?php if (($scopes[(int) $item->id] ?? []) === []): ?>
                                <span class="badge text-bg-warning">Sin alcance</span>
                            <?php else: ?>
                                <?php foreach ($scopes[(int) $item->id] as $userScope): ?>
                                    <span class="access-badge"><i class="bi bi-geo-alt"></i><?= esc($userScope['company_code'] . '/' . ($userScope['site_code'] ?? '*') . '/' . ($userScope['warehouse_code'] ?? '*')) ?></span>
                                <?php endforeach ?>
                            <?php endif ?>
                        </td>
                        <td><?= esc((string) ($item->last_active ?? '—')) ?></td>
                        <td><span class="badge-state <?= (bool) $item->active ? 'available' : 'canceled' ?>"><?= (bool) $item->active ? 'Activo' : 'Inactivo' ?></span></td>
                        <td class="text-nowrap">
                            <?php if (! $meta['is_protected']): ?>
                                <button class="btn btn-sm btn-outline-primary" type="button" data-user-edit="<?= (int) $item->id ?>"><i class="bi bi-sliders"></i> Acceso</button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-outline-secondary" type="button" disabled title="Cuenta protegida"><i class="bi bi-shield-lock"></i> Protegido</button>
                            <?php endif ?>
                            <button class="btn btn-sm <?= (bool) $item->active ? 'btn-outline-danger' : 'btn-outline-success' ?>" type="button" data-user-status="<?= (int) $item->id ?>" data-active="<?= (bool) $item->active ? '1' : '0' ?>">
                                <?= (bool) $item->active ? 'Desactivar' : 'Activar' ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade user-access-modal" id="userAccessModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form data-user-access-form>
                <div class="modal-header">
                    <div><h2 class="modal-title fs-5" id="userAccessTitle">Crear usuario</h2><small class="text-secondary">Define identidad, rol base, permisos efectivos y alcance.</small></div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-xl-4">
                            <div class="user-panel mb-3">
                                <div class="user-panel-title"><i class="bi bi-person me-1"></i> 1. Identidad</div>
                                <div class="mb-3"><label class="form-label">Usuario</label><input class="form-control" name="username" required maxlength="40"></div>
                                <div class="mb-3"><label class="form-label">Correo</label><input class="form-control" name="email" type="email" required maxlength="254"></div>
                                <div data-password-wrapper><label class="form-label">Contraseña temporal</label><input class="form-control" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password"><small class="text-secondary">Mínimo 12 caracteres. Sólo se solicita al crear.</small></div>
                            </div>

                            <div class="user-panel">
                                <div class="user-panel-title"><i class="bi bi-person-badge me-1"></i> 2. Rol base</div>
                                <select class="form-select mb-3" name="base_role" required>
                                    <?php foreach ($roles as $code => $role): ?><option value="<?= esc($code) ?>"><?= esc($role['title']) ?></option><?php endforeach ?>
                                </select>
                                <div class="role-summary" data-role-summary></div>
                                <button class="btn btn-sm btn-outline-primary w-100 mt-3" type="button" data-apply-role-defaults><i class="bi bi-arrow-counterclockwise"></i> Aplicar permisos base del rol</button>
                            </div>
                        </div>

                        <div class="col-xl-8">
                            <div class="user-panel mb-3">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                    <div class="user-panel-title mb-0"><i class="bi bi-key me-1"></i> 3. Permisos efectivos</div>
                                    <div><button class="btn btn-sm btn-light" type="button" data-permissions-clear>Quitar todos</button></div>
                                </div>
                                <div class="access-note mb-3"><strong>Importante:</strong> aquí se guarda el conjunto exacto. Un permiso desmarcado queda sin acceso, aunque el rol base normalmente lo incluya.</div>
                                <div class="row g-3">
                                    <?php foreach ($permissionSections as $section): ?>
                                        <div class="col-md-6">
                                            <div class="permission-section">
                                                <div class="permission-section-title"><?= esc($section['title']) ?></div>
                                                <?php foreach ($section['permissions'] as $permission): if (! isset($permissions[$permission])) continue; ?>
                                                    <label class="permission-check">
                                                        <input class="form-check-input mt-1" type="checkbox" name="permissions[]" value="<?= esc($permission) ?>">
                                                        <span><strong><?= esc($permission) ?></strong><small><?= esc($permissions[$permission]) ?></small></span>
                                                    </label>
                                                <?php endforeach ?>
                                            </div>
                                        </div>
                                    <?php endforeach ?>
                                </div>
                            </div>

                            <div class="user-panel">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                    <div class="user-panel-title mb-0"><i class="bi bi-building-gear me-1"></i> 4. Alcance autorizado</div>
                                    <button class="btn btn-sm btn-outline-primary" type="button" data-scope-add><i class="bi bi-plus-lg"></i> Agregar alcance</button>
                                </div>
                                <div class="access-note mb-3">Cada alcance es <strong>Empresa → Instalación → Almacén</strong>. Si dejas Almacén vacío, el usuario podrá trabajar en todos los almacenes permitidos de esa instalación. Puedes agregar más de un alcance.</div>
                                <div data-scope-list></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-primary btn-operational" type="submit"><i class="bi bi-shield-check"></i> Guardar acceso</button>
                </div>
            </form>
        </div>
    </div>
</div>

<template id="scopeRowTemplate">
    <div class="scope-row" data-scope-row>
        <div class="row g-2 align-items-end">
            <div class="col-md-4"><label class="form-label small">Empresa</label><select class="form-select form-select-sm" data-scope-company required></select></div>
            <div class="col-md-3"><label class="form-label small">Instalación</label><select class="form-select form-select-sm" data-scope-site required></select></div>
            <div class="col-md-4"><label class="form-label small">Almacén</label><select class="form-select form-select-sm" data-scope-warehouse></select></div>
            <div class="col-md-1 d-grid"><button class="btn btn-sm btn-outline-danger" type="button" data-scope-remove title="Quitar alcance"><i class="bi bi-trash"></i></button></div>
        </div>
    </div>
</template>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="/assets/js/users.js"></script>
<?= $this->endSection() ?>
