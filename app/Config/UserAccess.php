<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

final class UserAccess extends BaseConfig
{
    /**
     * Perfiles base visibles en la administración de usuarios.
     *
     * IMPORTANTE:
     * - Estos grupos no conceden permisos por matriz.
     * - Los permisos efectivos se guardan directamente en Shield por usuario.
     * - Así, desmarcar un permiso realmente lo revoca aunque exista un rol base.
     */
    public array $roles = [
        'access_catalog_admin' => [
            'title' => 'Administrador de catálogos',
            'description' => '100% Catálogos Maestros. Sin acceso operativo por defecto.',
            'icon' => 'bi-database-gear',
            'permissions' => ['catalogs.manage'],
        ],
        'access_identity_admin' => [
            'title' => 'Administrador de usuarios',
            'description' => 'Usuarios, roles, permisos y alcances. Sin operación por defecto.',
            'icon' => 'bi-people-fill',
            'permissions' => ['users.manage'],
        ],
        'access_planning' => [
            'title' => 'Planeación',
            'description' => 'Mareas, RSA y planes de descarga.',
            'icon' => 'bi-calendar2-check',
            'permissions' => ['dashboard.view', 'voyages.manage', 'plans.manage'],
        ],
        'access_sorter' => [
            'title' => 'Clasificador',
            'description' => 'Clasificación y llenado durante la descarga.',
            'icon' => 'bi-ui-checks-grid',
            'permissions' => ['discharge.operate'],
        ],
        'access_weigher' => [
            'title' => 'Pesador',
            'description' => 'Captura de pesajes operativos.',
            'icon' => 'bi-speedometer2',
            'permissions' => ['dashboard.view', 'weighings.create'],
        ],
        'access_labeler' => [
            'title' => 'Etiquetador',
            'description' => 'Impresión y reimpresión controlada de marbetes.',
            'icon' => 'bi-printer-fill',
            'permissions' => ['labels.print', 'labels.reprint'],
        ],
        'access_forklift' => [
            'title' => 'Montacarguista',
            'description' => 'Acomodo de tinas y consulta de inventario del almacén asignado.',
            'icon' => 'bi-box-seam',
            'permissions' => ['receiving.confirm', 'inventory.view'],
        ],
        'access_quality' => [
            'title' => 'Calidad',
            'description' => 'Inspecciones de calidad y consulta de inventario.',
            'icon' => 'bi-shield-check',
            'permissions' => ['quality.inspect', 'inventory.view'],
        ],
        'access_warehouse' => [
            'title' => 'Almacén',
            'description' => 'Recepción, existencias y movimientos físicos.',
            'icon' => 'bi-building',
            'permissions' => ['dashboard.view', 'receiving.confirm', 'inventory.view', 'inventory.move'],
        ],
        'access_supervisor' => [
            'title' => 'Supervisor operativo',
            'description' => 'Supervisión operativa amplia sin administración de catálogos ni usuarios.',
            'icon' => 'bi-person-badge-fill',
            'permissions' => [
                'dashboard.view',
                'plans.manage',
                'discharge.operate',
                'weighings.create',
                'labels.print',
                'labels.reprint',
                'quality.inspect',
                'receiving.confirm',
                'inventory.view',
                'inventory.move',
                'inventory.adjust',
                'reports.view',
            ],
        ],
    ];

    /** @var array<string, array{title:string, permissions:list<string>}> */
    public array $permissionSections = [
        'inicio' => [
            'title' => 'Inicio',
            'permissions' => ['dashboard.view'],
        ],
        'catalogos' => [
            'title' => 'Catálogos maestros',
            'permissions' => ['catalogs.manage'],
        ],
        'planeacion' => [
            'title' => 'Planeación y mareas',
            'permissions' => ['voyages.manage', 'plans.manage'],
        ],
        'descarga' => [
            'title' => 'Descarga y pesaje',
            'permissions' => ['discharge.operate', 'weighings.create', 'weighings.manual', 'weighings.reverse'],
        ],
        'etiquetado' => [
            'title' => 'Etiquetado',
            'permissions' => ['labels.print', 'labels.reprint'],
        ],
        'calidad' => [
            'title' => 'Calidad',
            'permissions' => ['quality.inspect', 'quality.approve'],
        ],
        'almacen' => [
            'title' => 'Almacén e inventario',
            'permissions' => ['receiving.confirm', 'inventory.view', 'inventory.move', 'inventory.adjust', 'inventory.reverse'],
        ],
        'solicitudes' => [
            'title' => 'Solicitudes y reservas',
            'permissions' => ['requests.create', 'requests.approve', 'reservations.manage'],
        ],
        'traspasos' => [
            'title' => 'Traspasos',
            'permissions' => ['transfers.manage'],
        ],
        'ventas' => [
            'title' => 'Ventas y facturación',
            'permissions' => ['sales.manage', 'billing.reference'],
        ],
        'control' => [
            'title' => 'Control y consulta',
            'permissions' => ['reports.view', 'audit.view'],
        ],
        'sistema' => [
            'title' => 'Administración del sistema',
            'permissions' => ['integrations.manage', 'configuration.manage', 'users.manage'],
        ],
    ];

    /**
     * Mapeo únicamente visual para usuarios heredados.
     * Al guardar desde la nueva interfaz el usuario se convierte al perfil access_*.
     */
    public array $legacyMap = [
        'identity_admin' => 'access_identity_admin',
        'master_data_owner' => 'access_catalog_admin',
        'discharge_planner' => 'access_planning',
        'sorter' => 'access_sorter',
        'weigher' => 'access_weigher',
        'labeler' => 'access_labeler',
        'quality_inspector' => 'access_quality',
        'quality_approver' => 'access_quality',
        'warehouse_operator' => 'access_warehouse',
        'warehouse_supervisor' => 'access_supervisor',
        'discharge_supervisor' => 'access_supervisor',
        'external_warehouse' => 'access_warehouse',
    ];
}
