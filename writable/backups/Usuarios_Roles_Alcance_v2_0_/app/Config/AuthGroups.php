<?php

namespace Config;

use CodeIgniter\Shield\Config\AuthGroups as ShieldAuthGroups;

class AuthGroups extends ShieldAuthGroups
{
    public string $defaultGroup = 'warehouse_operator';

    public array $groups = [
        'process_owner' => ['title' => 'Propietario del proceso', 'description' => 'Control funcional integral.'],
        'identity_admin' => ['title' => 'Administrador de identidades', 'description' => 'Usuarios, roles y accesos.'],
        'master_data_owner' => ['title' => 'Responsable de catálogos', 'description' => 'Gobierno de datos maestros.'],
        'discharge_planner' => ['title' => 'Planeador de descarga', 'description' => 'Viajes, planes, RSA y asignaciones.'],
        'discharge_supervisor' => ['title' => 'Supervisor de descarga', 'description' => 'Supervisión operativa del muelle.'],
        'sorter' => ['title' => 'Clasificador', 'description' => 'Clasificación de especie y talla.'],
        'labeler' => ['title' => 'Etiquetador', 'description' => 'Emisión controlada de marbetes.'],
        'weigher' => ['title' => 'Pesador', 'description' => 'Captura y confirmación de pesajes.'],
        'quality_inspector' => ['title' => 'Inspector de calidad', 'description' => 'Inspecciones, retenciones y defectos.'],
        'quality_approver' => ['title' => 'Aprobador de calidad', 'description' => 'Dictámenes de calidad.'],
        'warehouse_operator' => ['title' => 'Operador de almacén', 'description' => 'Recepción, ubicación, surtido y movimientos.'],
        'warehouse_supervisor' => ['title' => 'Supervisor de almacén', 'description' => 'Autorizaciones y control de almacén.'],
        'production_requester' => ['title' => 'Solicitante de producción', 'description' => 'Solicitudes de materia prima.'],
        'request_approver' => ['title' => 'Aprobador de solicitudes', 'description' => 'Autoriza disponibilidad y prioridad.'],
        'sales_dispatch' => ['title' => 'Ventas y despacho', 'description' => 'Pedidos, asignación y salidas de venta.'],
        'billing' => ['title' => 'Facturación', 'description' => 'Referencia fiscal sin afectar inventario físico.'],
        'inventory_controller' => ['title' => 'Contralor de inventario', 'description' => 'Ajustes, reversos y conciliación.'],
        'auditor' => ['title' => 'Auditor', 'description' => 'Consulta inmutable de trazabilidad.'],
        'support' => ['title' => 'Soporte e integraciones', 'description' => 'Dispositivos, API y observabilidad.'],
        'external_warehouse' => ['title' => 'Almacén externo', 'description' => 'Recepción y confirmación de terceros.'],
    ];

    public array $permissions = [
        'dashboard.view' => 'Consultar indicadores.',
        'catalogs.manage' => 'Administrar catálogos.',
        'voyages.manage' => 'Administrar embarcaciones y viajes.',
        'plans.manage' => 'Crear y aprobar planes de descarga.',
        'discharge.operate' => 'Operar descarga y llenado.',
        'weighings.create' => 'Capturar pesajes.',
        'weighings.manual' => 'Capturar peso manual de contingencia.',
        'weighings.reverse' => 'Reversar pesajes confirmados.',
        'labels.print' => 'Imprimir etiquetas.',
        'labels.reprint' => 'Reimprimir con motivo.',
        'quality.inspect' => 'Registrar inspecciones.',
        'quality.approve' => 'Emitir dictámenes.',
        'receiving.confirm' => 'Confirmar recepción física.',
        'inventory.view' => 'Consultar inventario.',
        'inventory.move' => 'Reubicar, surtir o despachar.',
        'inventory.adjust' => 'Registrar ajustes controlados.',
        'inventory.reverse' => 'Reversar movimientos confirmados.',
        'requests.create' => 'Crear solicitudes de materia prima.',
        'requests.approve' => 'Autorizar o rechazar solicitudes.',
        'reservations.manage' => 'Reservar y liberar unidades específicas.',
        'transfers.manage' => 'Administrar traspasos.',
        'sales.manage' => 'Administrar ventas y salidas.',
        'billing.reference' => 'Registrar referencias de INVoiice/CFDI.',
        'audit.view' => 'Consultar auditoría.',
        'reports.view' => 'Consultar y exportar reportes.',
        'integrations.manage' => 'Configurar y diagnosticar integraciones.',
        'configuration.manage' => 'Administrar reglas y parámetros operativos.',
        'users.manage' => 'Administrar usuarios y permisos.',
    ];

    public array $matrix = [
        'process_owner' => ['dashboard.*', 'catalogs.*', 'voyages.*', 'plans.*', 'discharge.*', 'weighings.*', 'labels.*', 'quality.*', 'receiving.*', 'inventory.*', 'requests.*', 'reservations.*', 'transfers.*', 'sales.*', 'billing.*', 'audit.*', 'reports.*', 'integrations.*', 'configuration.*', 'users.*'],
        'identity_admin' => ['users.manage', 'audit.view'],
        'master_data_owner' => ['catalogs.manage', 'configuration.manage', 'audit.view'],
        'discharge_planner' => ['dashboard.view', 'voyages.manage', 'plans.manage', 'reports.view'],
        'discharge_supervisor' => ['dashboard.view', 'plans.manage', 'discharge.operate', 'weighings.create', 'labels.print', 'reports.view'],
        'sorter' => ['discharge.operate'],
        'labeler' => ['labels.print', 'labels.reprint'],
        'weigher' => ['dashboard.view', 'weighings.create', 'labels.print'],
        'quality_inspector' => ['quality.inspect', 'inventory.view'],
        'quality_approver' => ['quality.inspect', 'quality.approve', 'inventory.view'],
        'warehouse_operator' => ['dashboard.view', 'receiving.confirm', 'inventory.view', 'inventory.move', 'reservations.manage', 'transfers.manage'],
        'warehouse_supervisor' => ['dashboard.view', 'receiving.confirm', 'inventory.view', 'inventory.move', 'inventory.adjust', 'requests.approve', 'reservations.manage', 'transfers.manage', 'reports.view'],
        'production_requester' => ['inventory.view', 'requests.create'],
        'request_approver' => ['inventory.view', 'requests.approve', 'reservations.manage'],
        'sales_dispatch' => ['inventory.view', 'sales.manage', 'inventory.move'],
        'billing' => ['billing.reference', 'reports.view'],
        'inventory_controller' => ['inventory.view', 'inventory.move', 'inventory.adjust', 'inventory.reverse', 'weighings.reverse', 'audit.view', 'reports.view'],
        'auditor' => ['dashboard.view', 'inventory.view', 'audit.view', 'reports.view'],
        'support' => ['dashboard.view', 'integrations.manage', 'audit.view'],
        'external_warehouse' => ['inventory.view', 'receiving.confirm', 'transfers.manage'],
    ];
}
