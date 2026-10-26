<?php

namespace Config;

use App\Common\Services\AuditService;
use App\Common\Services\FolioService;
use App\Common\Services\OperationalLogger;
use App\Common\Services\SettingsService;
use App\Common\Support\ApiClientContext;
use App\Common\Support\IdempotencyContext;
use App\Common\Support\ScopeContext;
use App\Modules\Inventory\Repositories\InventoryRepository;
use App\Modules\Catalogs\Services\CatalogService;
use App\Modules\Inventory\Services\InventoryCountService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\DischargePlanning\Services\DischargePlanService;
use App\Modules\Labeling\Adapters\BrowserLabelPrinter;
use App\Modules\Labeling\Adapters\LocalMiddlewareLabelPrinter;
use App\Modules\Labeling\Adapters\PdfLabelPrinter;
use App\Modules\Labeling\Contracts\LabelPrinterInterface;
use App\Modules\Labeling\Services\LabelService;
use App\Modules\Labeling\Services\CodeImageService;
use App\Modules\Maritime\Services\VoyageService;
use App\Modules\Maritime\Services\RsaService;
use App\Modules\Quality\Services\QualityService;
use App\Modules\Quality\Strategies\ConfigurableQualityRuleStrategy;
use App\Modules\Supply\Services\RequestService;
use App\Modules\Transfers\Services\TransferService;
use App\Modules\Sales\Services\SalesService;
use App\Modules\Users\Services\UserAdministrationService;
use App\Modules\Billing\Services\BillingReferenceService;
use App\Modules\Weighing\Adapters\LocalMiddlewareScaleAdapter;
use App\Modules\Weighing\Adapters\Rs232ScaleAdapter;
use App\Modules\Weighing\Adapters\SimulatorScaleAdapter;
use App\Modules\Weighing\Adapters\TcpScaleAdapter;
use App\Modules\Weighing\Adapters\UsbSerialScaleAdapter;
use App\Modules\Weighing\Contracts\ScaleAdapterInterface;
use App\Modules\Weighing\Models\LotModel;
use App\Modules\Weighing\Models\WeighingModel;
use App\Modules\Weighing\Services\LotCodeService;
use App\Modules\Weighing\Services\ScaleReadingService;
use App\Modules\Weighing\Services\WeighingService;
use App\Modules\Weighing\Services\WeightCalculator;
use App\Modules\WarehouseEntry\Services\WarehouseEntryService;
use CodeIgniter\Config\BaseService;

class Services extends BaseService
{
    public static function apiClientContext(bool $getShared = true): ApiClientContext
    {
        if ($getShared) {
            return static::getSharedInstance('apiClientContext');
        }

        return new ApiClientContext();
    }

    public static function idempotencyContext(bool $getShared = true): IdempotencyContext
    {
        if ($getShared) {
            return static::getSharedInstance('idempotencyContext');
        }

        return new IdempotencyContext();
    }

    public static function scopeContext(bool $getShared = true): ScopeContext
    {
        if ($getShared) {
            return static::getSharedInstance('scopeContext');
        }

        return new ScopeContext(db_connect());
    }

    public static function folioService(bool $getShared = true): FolioService
    {
        if ($getShared) {
            return static::getSharedInstance('folioService');
        }

        return new FolioService();
    }

    public static function settingsService(bool $getShared = true): SettingsService
    {
        if ($getShared) {
            return static::getSharedInstance('settingsService');
        }

        return new SettingsService(db_connect());
    }

    public static function auditService(bool $getShared = true): AuditService
    {
        if ($getShared) {
            return static::getSharedInstance('auditService');
        }

        return new AuditService(db_connect());
    }

    public static function operationalLogger(bool $getShared = true): OperationalLogger
    {
        if ($getShared) {
            return static::getSharedInstance('operationalLogger');
        }

        return new OperationalLogger(WRITEPATH . 'logs');
    }

    public static function inventoryRepository(bool $getShared = true): InventoryRepository
    {
        if ($getShared) {
            return static::getSharedInstance('inventoryRepository');
        }

        return new InventoryRepository(db_connect());
    }

    public static function catalogService(bool $getShared = true): CatalogService
    {
        if ($getShared) {
            return static::getSharedInstance('catalogService');
        }

        return new CatalogService(db_connect(), config('Catalogs'), static::auditService());
    }

    public static function userAdministrationService(bool $getShared = true): UserAdministrationService
    {
        if ($getShared) {
            return static::getSharedInstance('userAdministrationService');
        }

        return new UserAdministrationService(db_connect(), static::auditService());
    }

    public static function inventoryService(bool $getShared = true): InventoryService
    {
        if ($getShared) {
            return static::getSharedInstance('inventoryService');
        }

        return new InventoryService(
            db_connect(),
            static::inventoryRepository(),
            static::folioService(),
            static::settingsService(),
            static::auditService(),
        );
    }

    public static function inventoryCountService(bool $getShared = true): InventoryCountService
    {
        if ($getShared) {
            return static::getSharedInstance('inventoryCountService');
        }

        return new InventoryCountService(db_connect(), static::folioService(), static::auditService());
    }

    public static function lotCodeService(bool $getShared = true): LotCodeService
    {
        if ($getShared) {
            return static::getSharedInstance('lotCodeService');
        }

        return new LotCodeService(db_connect(), static::settingsService(), new LotModel());
    }

    public static function weighingService(bool $getShared = true): WeighingService
    {
        if ($getShared) {
            return static::getSharedInstance('weighingService');
        }

        return new WeighingService(
            db_connect(),
            new WeighingModel(),
            static::lotCodeService(),
            static::settingsService(),
            static::folioService(),
            static::auditService(),
            new WeightCalculator(),
        );
    }

    public static function scaleReadingService(bool $getShared = true): ScaleReadingService
    {
        if ($getShared) {
            return static::getSharedInstance('scaleReadingService');
        }

        return new ScaleReadingService(db_connect(), static::auditService());
    }

    public static function voyageService(bool $getShared = true): VoyageService
    {
        if ($getShared) {
            return static::getSharedInstance('voyageService');
        }

        return new VoyageService(db_connect(), static::auditService());
    }

    public static function rsaService(bool $getShared = true): RsaService
    {
        if ($getShared) {
            return static::getSharedInstance('rsaService');
        }

        return new RsaService(db_connect(), static::auditService());
    }

    public static function dischargePlanService(bool $getShared = true): DischargePlanService
    {
        if ($getShared) {
            return static::getSharedInstance('dischargePlanService');
        }

        return new DischargePlanService(db_connect(), static::folioService(), static::settingsService(), static::auditService());
    }

    public static function requestService(bool $getShared = true): RequestService
    {
        if ($getShared) {
            return static::getSharedInstance('requestService');
        }

        return new RequestService(db_connect(), static::inventoryRepository(), static::folioService(), static::auditService());
    }

    public static function transferService(bool $getShared = true): TransferService
    {
        if ($getShared) {
            return static::getSharedInstance('transferService');
        }

        return new TransferService(db_connect(), static::inventoryRepository(), static::folioService(), static::auditService());
    }

    public static function salesService(bool $getShared = true): SalesService
    {
        if ($getShared) {
            return static::getSharedInstance('salesService');
        }

        return new SalesService(db_connect(), static::inventoryRepository(), static::folioService(), static::auditService());
    }

    public static function billingReferenceService(bool $getShared = true): BillingReferenceService
    {
        if ($getShared) {
            return static::getSharedInstance('billingReferenceService');
        }

        return new BillingReferenceService(db_connect(), static::auditService());
    }

    public static function qualityRuleStrategy(bool $getShared = true): ConfigurableQualityRuleStrategy
    {
        if ($getShared) {
            return static::getSharedInstance('qualityRuleStrategy');
        }

        return new ConfigurableQualityRuleStrategy(static::settingsService());
    }

    public static function qualityService(bool $getShared = true): QualityService
    {
        if ($getShared) {
            return static::getSharedInstance('qualityService');
        }

        return new QualityService(db_connect(), static::qualityRuleStrategy(), static::auditService());
    }

    public static function scaleAdapter(bool $getShared = true): ScaleAdapterInterface
    {
        if ($getShared) {
            return static::getSharedInstance('scaleAdapter');
        }

        $driver = strtolower((string) env('integrations.scale.driver', 'simulator'));
        $url = (string) env('integrations.scale.middlewareUrl', '');
        $key = (string) env('integrations.scale.apiKey', '');
        $timeout = (int) env('integrations.timeoutSeconds', 5);
        $client = service('curlrequest');

        if ($driver === 'simulator' && ENVIRONMENT === 'production' && ! filter_var(env('integrations.scale.allowSimulatorInProduction', false), FILTER_VALIDATE_BOOLEAN)) {
            throw new \App\Common\Exceptions\DomainException(
                'El simulador de báscula está deshabilitado en producción.',
                'SIMULATOR_DISABLED_IN_PRODUCTION'
            );
        }

        return match ($driver) {
            'simulator' => new SimulatorScaleAdapter((float) env('integrations.scale.simulatorWeightKg', 986.500)),
            'rs232' => new Rs232ScaleAdapter($client, $url, $key, $timeout),
            'usb_serial' => new UsbSerialScaleAdapter($client, $url, $key, $timeout),
            'tcp' => new TcpScaleAdapter($client, $url, $key, $timeout),
            'middleware' => new LocalMiddlewareScaleAdapter($client, $url, $key, 'configured', $timeout),
            default => throw new \App\Common\Exceptions\DomainException('El driver de báscula configurado no existe.', 'UNKNOWN_SCALE_DRIVER'),
        };
    }

    public static function labelPrinter(bool $getShared = true): LabelPrinterInterface
    {
        if ($getShared) {
            return static::getSharedInstance('labelPrinter');
        }

        $driver = strtolower((string) env('integrations.printer.driver', 'pdf'));

        return match ($driver) {
            'browser' => new BrowserLabelPrinter(),
            'middleware', 'thermal', 'zpl' => new LocalMiddlewareLabelPrinter(
                service('curlrequest'),
                (string) env('integrations.printer.middlewareUrl', ''),
                (string) env('integrations.printer.apiKey', ''),
                (int) env('integrations.timeoutSeconds', 5),
            ),
            'pdf' => new PdfLabelPrinter(),
            default => throw new \App\Common\Exceptions\DomainException('El driver de impresión configurado no existe.', 'UNKNOWN_PRINTER_DRIVER'),
        };
    }

    public static function labelService(bool $getShared = true): LabelService
    {
        if ($getShared) {
            return static::getSharedInstance('labelService');
        }

        return new LabelService(db_connect(), static::labelPrinter(), static::codeImageService(), static::auditService());
    }

    public static function codeImageService(bool $getShared = true): CodeImageService
    {
        if ($getShared) {
            return static::getSharedInstance('codeImageService');
        }

        return new CodeImageService();
    }
    public static function warehouseEntryService(bool $getShared = true): WarehouseEntryService
    {
        if ($getShared) {
            return static::getSharedInstance('warehouseEntryService');
        }

        return new WarehouseEntryService(
            db_connect(),
            static::dischargePlanService(),
            static::weighingService(),
            static::labelService(),
            static::inventoryService(),
            static::settingsService(),
            static::auditService(),
        );
    }

}

