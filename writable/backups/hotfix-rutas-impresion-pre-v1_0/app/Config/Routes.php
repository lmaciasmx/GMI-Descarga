<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->setDefaultNamespace('App\\Controllers');
$routes->setDefaultController('DashboardController');
$routes->setDefaultMethod('index');
$routes->setTranslateURIDashes(false);
$routes->set404Override();
$routes->setAutoRoute(false);

service('auth')->routes($routes, ['except' => ['register', 'login', 'magic-link']]);
$routes->get('login', '\\CodeIgniter\\Shield\\Controllers\\LoginController::loginView', ['as' => 'login']);
$routes->post('login', '\\CodeIgniter\\Shield\\Controllers\\LoginController::loginAction', ['filter' => 'auth-rates']);
$routes->get('login/magic-link', '\\CodeIgniter\\Shield\\Controllers\\MagicLinkController::loginView', ['as' => 'magic-link']);
$routes->post('login/magic-link', '\\CodeIgniter\\Shield\\Controllers\\MagicLinkController::loginAction', ['filter' => 'auth-rates']);
$routes->get('login/verify-magic-link', '\\CodeIgniter\\Shield\\Controllers\\MagicLinkController::verify', ['as' => 'verify-magic-link']);

$routes->get('/', static fn () => redirect()->to('/home'));
$routes->get('home', '\\App\\Modules\\Security\\Controllers\\HomeController::index', ['filter' => 'session']);
$routes->match(['get', 'post'], 'scope/select', '\\App\\Modules\\Security\\Controllers\\ScopeController::select', ['filter' => 'session']);

$routes->group('', ['filter' => ['session', 'scope']], static function (RouteCollection $routes): void {
    $routes->get('dashboard', '\\App\\Modules\\Dashboard\\Controllers\\DashboardController::index', ['filter' => 'permission:dashboard.view']);

    $routes->group('operacion', static function (RouteCollection $routes): void {
        $routes->get('planes', '\\App\\Modules\\DischargePlanning\\Controllers\\PlanController::index', ['filter' => 'permission:plans.manage']);
        $routes->get('descarga', '\\App\\Modules\\DischargePlanning\\Controllers\\DischargeStationController::index', ['filter' => 'permission:discharge.operate']);
        $routes->get('pesaje', '\\App\\Modules\\Weighing\\Controllers\\WeighingStationController::index', ['filter' => 'permission:weighings.create']);
        $routes->get('entrada-frigorifico', '\\App\\Modules\\WarehouseEntry\\Controllers\\WarehouseEntryController::index', ['filter' => 'permission:weighings.create']);
        $routes->get('acomodo-tinas', '\\App\\Modules\\WarehouseEntry\\Controllers\\WarehouseEntryController::placement', ['filter' => 'permission:receiving.confirm']);
        $routes->get('etiquetas', '\\App\\Modules\\Labeling\\Controllers\\LabelStationController::index', ['filter' => 'permission:labels.print,labels.reprint']);
        $routes->get('etiquetas/trabajos/(:num)/archivo', '\\App\\Modules\\Labeling\\Controllers\\LabelStationController::download/$1', ['filter' => 'permission:labels.print,labels.reprint']);
        $routes->get('calidad', '\\App\\Modules\\Quality\\Controllers\\QualityController::index', ['filter' => 'permission:quality.inspect']);
        $routes->get('recepcion', '\\App\\Modules\\Receiving\\Controllers\\ReceivingController::index', ['filter' => 'permission:receiving.confirm']);
    });

    $routes->group('inventario', static function (RouteCollection $routes): void {
        $routes->get('/', '\\App\\Modules\\Inventory\\Controllers\\InventoryController::index', ['filter' => 'permission:inventory.view']);
        $routes->get('movimientos', '\\App\\Modules\\Inventory\\Controllers\\InventoryController::movements', ['filter' => 'permission:inventory.view']);
        $routes->get('conteos', '\\App\\Modules\\Inventory\\Controllers\\InventoryCountPageController::index', ['filter' => 'permission:inventory.adjust']);
        $routes->get('camaras', '\\App\\Modules\\Inventory\\Controllers\\ChamberLayoutController::index', ['filter' => 'permission:inventory.view']);
    });

    $routes->get('solicitudes', '\\App\\Modules\\Supply\\Controllers\\RequestController::index', ['filter' => 'permission:requests.create,requests.approve,reservations.manage']);
    $routes->get('traspasos', '\\App\\Modules\\Transfers\\Controllers\\TransferController::index', ['filter' => 'permission:transfers.manage']);
    $routes->get('ventas', '\\App\\Modules\\Sales\\Controllers\\SalesController::index', ['filter' => 'permission:sales.manage']);
    $routes->get('facturacion', '\\App\\Modules\\Billing\\Controllers\\BillingController::index', ['filter' => 'permission:billing.reference']);
    $routes->get('reportes', '\\App\\Modules\\Reports\\Controllers\\ReportController::index', ['filter' => 'permission:reports.view']);
    $routes->get('catalogos', '\\App\\Modules\\Catalogs\\Controllers\\CatalogManagementController::index', ['filter' => 'permission:catalogs.manage']);
    $routes->get('usuarios', '\\App\\Modules\\Users\\Controllers\\UserController::index', ['filter' => 'permission:users.manage']);
    $routes->get('auditoria', '\\App\\Modules\\Audit\\Controllers\\AuditController::index', ['filter' => 'permission:audit.view']);
    $routes->get('integraciones', '\\App\\Modules\\Integrations\\Controllers\\IntegrationConsoleController::index', ['filter' => 'permission:integrations.manage']);
    $routes->get('configuracion', '\\App\\Modules\\Configuration\\Controllers\\ConfigurationController::index', ['filter' => 'permission:configuration.manage']);
});

// Catálogos Maestros: requieren sesión y permiso, pero NO operational_scope.
// El alcance operativo aplica a procesos; no debe impedir administrar maestros de otras empresas.
$routes->group('internal/v1', ['filter' => 'session'], static function (RouteCollection $routes): void {
    $routes->get('catalogs/(:segment)', '\\App\\Modules\\Catalogs\\Controllers\\Api\\CatalogController::show/$1', ['filter' => 'permission:catalogs.manage']);

    $routes->group('', ['filter' => 'idempotency'], static function (RouteCollection $routes): void {
        $routes->post('catalogs/(:segment)', '\\App\\Modules\\Catalogs\\Controllers\\Api\\CatalogController::create/$1', ['filter' => 'permission:catalogs.manage']);
        $routes->put('catalogs/(:segment)/(:num)', '\\App\\Modules\\Catalogs\\Controllers\\Api\\CatalogController::update/$1/$2', ['filter' => 'permission:catalogs.manage']);
        $routes->post('catalogs/(:segment)/(:num)/deactivate', '\\App\\Modules\\Catalogs\\Controllers\\Api\\CatalogController::deactivate/$1/$2', ['filter' => 'permission:catalogs.manage']);
    });
});

$routes->group('internal/v1', ['filter' => ['session', 'scope']], static function (RouteCollection $routes): void {
    $routes->get('scale/status', '\\App\\Modules\\Weighing\\Controllers\\Api\\ScaleController::status', ['filter' => 'permission:weighings.create']);
    $routes->get('requests/(:num)/availability', '\\App\\Modules\\Supply\\Controllers\\Api\\RequestController::availability/$1', ['filter' => 'permission:reservations.manage']);
    $routes->get('plans/(:num)', '\\App\\Modules\\DischargePlanning\\Controllers\\Api\\PlanController::show/$1', ['filter' => 'permission:plans.manage']);
    $routes->get('inventory/chambers/(:num)/layout', '\\App\\Modules\\Inventory\\Controllers\\Api\\ChamberLayoutController::show/$1', ['filter' => 'permission:inventory.view']);
    $routes->get('warehouse-entry/tubs/search', '\\App\\Modules\\WarehouseEntry\\Controllers\\Api\\WarehouseEntryController::tubs', ['filter' => 'permission:weighings.create']);
    $routes->get('warehouse-entry/participants/search', '\\App\\Modules\\WarehouseEntry\\Controllers\\Api\\WarehouseEntryController::participants', ['filter' => 'permission:weighings.create']);
    $routes->get('warehouse-entry/warehouses/(:num)/chambers', '\\App\\Modules\\WarehouseEntry\\Controllers\\Api\\WarehouseEntryController::chambers/$1', ['filter' => 'permission:weighings.create']);
    $routes->get('warehouse-entry/chambers/(:num)/sectors', '\\App\\Modules\\WarehouseEntry\\Controllers\\Api\\WarehouseEntryController::sectors/$1', ['filter' => 'permission:weighings.create']);
    $routes->get('warehouse-entry/chambers/(:num)/stacks', '\\App\\Modules\\WarehouseEntry\\Controllers\\Api\\WarehouseEntryController::stacks/$1', ['filter' => 'permission:receiving.confirm']);

    $routes->group('', ['filter' => 'idempotency'], static function (RouteCollection $routes): void {
        $routes->post('scale/read-stable', '\\App\\Modules\\Weighing\\Controllers\\Api\\ScaleController::readStable', ['filter' => 'permission:weighings.create']);
        $routes->post('voyages', '\\App\\Modules\\Maritime\\Controllers\\Api\\VoyageController::create', ['filter' => 'permission:voyages.manage']);
        $routes->post('voyages/(:num)/transition', '\\App\\Modules\\Maritime\\Controllers\\Api\\VoyageController::transition/$1', ['filter' => 'permission:voyages.manage']);
        $routes->post('rsas', '\\App\\Modules\\Maritime\\Controllers\\Api\\RsaController::create', ['filter' => 'permission:voyages.manage']);
        $routes->post('plans', '\\App\\Modules\\DischargePlanning\\Controllers\\Api\\PlanController::create', ['filter' => 'permission:plans.manage']);
        $routes->put('plans/(:num)', '\\App\\Modules\\DischargePlanning\\Controllers\\Api\\PlanController::update/$1', ['filter' => 'permission:plans.manage']);
        $routes->post('plans/(:num)/duplicate', '\\App\\Modules\\DischargePlanning\\Controllers\\Api\\PlanController::duplicate/$1', ['filter' => 'permission:plans.manage']);
        $routes->post('plans/(:num)/transition', '\\App\\Modules\\DischargePlanning\\Controllers\\Api\\PlanController::transition/$1', ['filter' => 'permission:plans.manage']);
        $routes->post('users', '\\App\\Modules\\Users\\Controllers\\Api\\UserController::create', ['filter' => 'permission:users.manage']);
        $routes->put('users/(:num)/group', '\\App\\Modules\\Users\\Controllers\\Api\\UserController::group/$1', ['filter' => 'permission:users.manage']);
        $routes->put('users/(:num)/status', '\\App\\Modules\\Users\\Controllers\\Api\\UserController::status/$1', ['filter' => 'permission:users.manage']);
        $routes->put('settings/(:num)', '\\App\\Modules\\Configuration\\Controllers\\Api\\ConfigurationController::update/$1', ['filter' => 'permission:configuration.manage']);
        $routes->post('fillings', '\\App\\Modules\\DischargePlanning\\Controllers\\Api\\PlanController::startFilling', ['filter' => 'permission:discharge.operate']);
        $routes->post('fillings/(:num)/complete', '\\App\\Modules\\DischargePlanning\\Controllers\\Api\\PlanController::completeFilling/$1', ['filter' => 'permission:discharge.operate']);
        $routes->post('weighings', '\\App\\Modules\\Weighing\\Controllers\\Api\\WeighingController::create', ['filter' => 'permission:weighings.create']);
        $routes->post('warehouse-entry/participants', '\\App\\Modules\\WarehouseEntry\\Controllers\\Api\\WarehouseEntryController::createParticipant', ['filter' => 'permission:weighings.create']);
        $routes->post('warehouse-entry', '\\App\\Modules\\WarehouseEntry\\Controllers\\Api\\WarehouseEntryController::create', ['filter' => 'permission:weighings.create']);
        $routes->post('warehouse-entry/destinations/(:num)/place', '\\App\\Modules\\WarehouseEntry\\Controllers\\Api\\WarehouseEntryController::place/$1', ['filter' => 'permission:receiving.confirm']);
        $routes->post('weighings/(:num)/reverse', '\\App\\Modules\\Weighing\\Controllers\\Api\\WeighingController::reverse/$1', ['filter' => 'permission:weighings.reverse']);
        $routes->post('labels/print', '\\App\\Modules\\Labeling\\Controllers\\Api\\LabelController::print', ['filter' => 'permission:labels.print']);
        $routes->post('labels/(:num)/reprint', '\\App\\Modules\\Labeling\\Controllers\\Api\\LabelController::reprint/$1', ['filter' => 'permission:labels.reprint']);
        $routes->post('quality/inspections', '\\App\\Modules\\Quality\\Controllers\\Api\\QualityController::inspect', ['filter' => 'permission:quality.inspect']);
        $routes->post('quality/inspections/(:num)/approve', '\\App\\Modules\\Quality\\Controllers\\Api\\QualityController::approve/$1', ['filter' => 'permission:quality.approve']);
        $routes->post('receptions', '\\App\\Modules\\Receiving\\Controllers\\Api\\ReceivingController::create', ['filter' => 'permission:receiving.confirm']);
        $routes->post('inventory/movements', '\\App\\Modules\\Inventory\\Controllers\\Api\\MovementController::create', ['filter' => 'permission:inventory.move']);
        $routes->post('inventory/movements/(:num)/reverse', '\\App\\Modules\\Inventory\\Controllers\\Api\\MovementController::reverse/$1', ['filter' => 'permission:inventory.reverse']);
        $routes->post('inventory/counts', '\\App\\Modules\\Inventory\\Controllers\\Api\\InventoryCountController::start', ['filter' => 'permission:inventory.adjust']);
        $routes->post('inventory/counts/(:num)/lines', '\\App\\Modules\\Inventory\\Controllers\\Api\\InventoryCountController::record/$1', ['filter' => 'permission:inventory.adjust']);
        $routes->post('inventory/counts/(:num)/finish', '\\App\\Modules\\Inventory\\Controllers\\Api\\InventoryCountController::finish/$1', ['filter' => 'permission:inventory.adjust']);
        $routes->post('inventory/counts/(:num)/reconcile', '\\App\\Modules\\Inventory\\Controllers\\Api\\InventoryCountController::reconcile/$1', ['filter' => 'permission:inventory.adjust']);
        $routes->post('inventory/locations/(:num)/restriction', '\\App\\Modules\\Inventory\\Controllers\\Api\\ChamberLayoutController::restrict/$1', ['filter' => 'permission:inventory.adjust']);
        $routes->post('inventory/locations/(:num)/restriction/release', '\\App\\Modules\\Inventory\\Controllers\\Api\\ChamberLayoutController::release/$1', ['filter' => 'permission:inventory.adjust']);
        $routes->post('requests', '\\App\\Modules\\Supply\\Controllers\\Api\\RequestController::create', ['filter' => 'permission:requests.create']);
        $routes->post('requests/(:num)/authorize', '\\App\\Modules\\Supply\\Controllers\\Api\\RequestController::authorize/$1', ['filter' => 'permission:requests.approve']);
        $routes->post('reservations', '\\App\\Modules\\Supply\\Controllers\\Api\\ReservationController::create', ['filter' => 'permission:reservations.manage']);
        $routes->post('reservations/(:num)/fulfill', '\\App\\Modules\\Supply\\Controllers\\Api\\ReservationController::fulfill/$1', ['filter' => 'permission:reservations.manage']);
        $routes->post('reservations/(:num)/release', '\\App\\Modules\\Supply\\Controllers\\Api\\ReservationController::release/$1', ['filter' => 'permission:reservations.manage']);
        $routes->post('transfers', '\\App\\Modules\\Transfers\\Controllers\\Api\\TransferController::create', ['filter' => 'permission:transfers.manage']);
        $routes->post('transfers/(:num)/authorize', '\\App\\Modules\\Transfers\\Controllers\\Api\\TransferController::authorize/$1', ['filter' => 'permission:transfers.manage']);
        $routes->post('transfers/(:num)/ship', '\\App\\Modules\\Transfers\\Controllers\\Api\\TransferController::ship/$1', ['filter' => 'permission:transfers.manage']);
        $routes->post('transfers/(:num)/receive', '\\App\\Modules\\Transfers\\Controllers\\Api\\TransferController::receive/$1', ['filter' => 'permission:transfers.manage']);
        $routes->post('sales', '\\App\\Modules\\Sales\\Controllers\\Api\\SalesController::create', ['filter' => 'permission:sales.manage']);
        $routes->post('sales/(:num)/dispatch', '\\App\\Modules\\Sales\\Controllers\\Api\\SalesController::dispatch/$1', ['filter' => 'permission:sales.manage']);
        $routes->post('billing/references', '\\App\\Modules\\Billing\\Controllers\\Api\\BillingController::record', ['filter' => 'permission:billing.reference']);
    });
});

$routes->group('api/v1', ['filter' => ['rateLimit', 'apiKey']], static function (RouteCollection $routes): void {
    $routes->get('health', '\\App\\Modules\\Integrations\\Controllers\\Api\\HealthController::index', ['filter' => 'apiPermission:health.read']);
    $routes->get('catalogs/(:segment)', '\\App\\Modules\\Catalogs\\Controllers\\Api\\CatalogController::show/$1', ['filter' => 'apiPermission:catalogs.read']);
    $routes->get('voyages/active', '\\App\\Modules\\Maritime\\Controllers\\Api\\VoyageController::active', ['filter' => 'apiPermission:voyages.read']);
    $routes->get('plans/(:num)', '\\App\\Modules\\DischargePlanning\\Controllers\\Api\\PlanController::show/$1', ['filter' => 'apiPermission:plans.read']);
    $routes->get('requests/(:num)/availability', '\\App\\Modules\\Supply\\Controllers\\Api\\RequestController::availability/$1', ['filter' => 'apiPermission:inventory.read']);
    $routes->get('scale/status', '\\App\\Modules\\Weighing\\Controllers\\Api\\ScaleController::status', ['filter' => 'apiPermission:scale.read']);

    $routes->group('', ['filter' => 'idempotency'], static function (RouteCollection $routes): void {
        $routes->post('scale/read-stable', '\\App\\Modules\\Weighing\\Controllers\\Api\\ScaleController::readStable', ['filter' => 'apiPermission:scale.read']);
        $routes->post('voyages', '\\App\\Modules\\Maritime\\Controllers\\Api\\VoyageController::create', ['filter' => 'apiPermission:voyages.manage']);
        $routes->post('voyages/(:num)/transition', '\\App\\Modules\\Maritime\\Controllers\\Api\\VoyageController::transition/$1', ['filter' => 'apiPermission:voyages.manage']);
        $routes->post('rsas', '\\App\\Modules\\Maritime\\Controllers\\Api\\RsaController::create', ['filter' => 'apiPermission:voyages.manage']);
        $routes->post('plans', '\\App\\Modules\\DischargePlanning\\Controllers\\Api\\PlanController::create', ['filter' => 'apiPermission:plans.manage']);
        $routes->post('plans/(:num)/transition', '\\App\\Modules\\DischargePlanning\\Controllers\\Api\\PlanController::transition/$1', ['filter' => 'apiPermission:plans.manage']);
        $routes->post('fillings', '\\App\\Modules\\DischargePlanning\\Controllers\\Api\\PlanController::startFilling', ['filter' => 'apiPermission:discharge.operate']);
        $routes->post('fillings/(:num)/complete', '\\App\\Modules\\DischargePlanning\\Controllers\\Api\\PlanController::completeFilling/$1', ['filter' => 'apiPermission:discharge.operate']);
        $routes->post('weighings', '\\App\\Modules\\Weighing\\Controllers\\Api\\WeighingController::create', ['filter' => 'apiPermission:weighings.create']);
        $routes->post('weighings/(:num)/reverse', '\\App\\Modules\\Weighing\\Controllers\\Api\\WeighingController::reverse/$1', ['filter' => 'apiPermission:weighings.reverse']);
        $routes->post('labels/print', '\\App\\Modules\\Labeling\\Controllers\\Api\\LabelController::print', ['filter' => 'apiPermission:labels.print']);
        $routes->post('labels/(:num)/reprint', '\\App\\Modules\\Labeling\\Controllers\\Api\\LabelController::reprint/$1', ['filter' => 'apiPermission:labels.reprint']);
        $routes->post('quality/inspections', '\\App\\Modules\\Quality\\Controllers\\Api\\QualityController::inspect', ['filter' => 'apiPermission:quality.inspect']);
        $routes->post('quality/inspections/(:num)/approve', '\\App\\Modules\\Quality\\Controllers\\Api\\QualityController::approve/$1', ['filter' => 'apiPermission:quality.approve']);
        $routes->post('receptions', '\\App\\Modules\\Receiving\\Controllers\\Api\\ReceivingController::create', ['filter' => 'apiPermission:receiving.confirm']);
        $routes->post('inventory/movements', '\\App\\Modules\\Inventory\\Controllers\\Api\\MovementController::create', ['filter' => 'apiPermission:inventory.move']);
        $routes->post('inventory/movements/(:num)/reverse', '\\App\\Modules\\Inventory\\Controllers\\Api\\MovementController::reverse/$1', ['filter' => 'apiPermission:inventory.reverse']);
        $routes->post('inventory/counts', '\\App\\Modules\\Inventory\\Controllers\\Api\\InventoryCountController::start', ['filter' => 'apiPermission:inventory.adjust']);
        $routes->post('inventory/counts/(:num)/lines', '\\App\\Modules\\Inventory\\Controllers\\Api\\InventoryCountController::record/$1', ['filter' => 'apiPermission:inventory.adjust']);
        $routes->post('inventory/counts/(:num)/finish', '\\App\\Modules\\Inventory\\Controllers\\Api\\InventoryCountController::finish/$1', ['filter' => 'apiPermission:inventory.adjust']);
        $routes->post('inventory/counts/(:num)/reconcile', '\\App\\Modules\\Inventory\\Controllers\\Api\\InventoryCountController::reconcile/$1', ['filter' => 'apiPermission:inventory.adjust']);
        $routes->post('requests', '\\App\\Modules\\Supply\\Controllers\\Api\\RequestController::create', ['filter' => 'apiPermission:requests.create']);
        $routes->post('requests/(:num)/authorize', '\\App\\Modules\\Supply\\Controllers\\Api\\RequestController::authorize/$1', ['filter' => 'apiPermission:requests.approve']);
        $routes->post('reservations', '\\App\\Modules\\Supply\\Controllers\\Api\\ReservationController::create', ['filter' => 'apiPermission:reservations.manage']);
        $routes->post('reservations/(:num)/fulfill', '\\App\\Modules\\Supply\\Controllers\\Api\\ReservationController::fulfill/$1', ['filter' => 'apiPermission:reservations.manage']);
        $routes->post('reservations/(:num)/release', '\\App\\Modules\\Supply\\Controllers\\Api\\ReservationController::release/$1', ['filter' => 'apiPermission:reservations.manage']);
        $routes->post('transfers', '\\App\\Modules\\Transfers\\Controllers\\Api\\TransferController::create', ['filter' => 'apiPermission:transfers.manage']);
        $routes->post('transfers/(:num)/authorize', '\\App\\Modules\\Transfers\\Controllers\\Api\\TransferController::authorize/$1', ['filter' => 'apiPermission:transfers.manage']);
        $routes->post('transfers/(:num)/ship', '\\App\\Modules\\Transfers\\Controllers\\Api\\TransferController::ship/$1', ['filter' => 'apiPermission:transfers.manage']);
        $routes->post('transfers/(:num)/receive', '\\App\\Modules\\Transfers\\Controllers\\Api\\TransferController::receive/$1', ['filter' => 'apiPermission:transfers.manage']);
        $routes->post('sales', '\\App\\Modules\\Sales\\Controllers\\Api\\SalesController::create', ['filter' => 'apiPermission:sales.manage']);
        $routes->post('sales/(:num)/dispatch', '\\App\\Modules\\Sales\\Controllers\\Api\\SalesController::dispatch/$1', ['filter' => 'apiPermission:sales.manage']);
        $routes->post('billing/references', '\\App\\Modules\\Billing\\Controllers\\Api\\BillingController::record', ['filter' => 'apiPermission:billing.reference']);
    });
});

