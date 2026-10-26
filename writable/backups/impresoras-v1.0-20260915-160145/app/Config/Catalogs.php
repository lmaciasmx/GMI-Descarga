<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Catalogs extends BaseConfig
{
    /** @var array<string, array<string, mixed>> */
    public array $definitions = [
        'companies' => [
            'title' => 'Empresas', 'table' => 'companies', 'search' => ['code', 'trade_name', 'legal_name'],
            'list' => ['id', 'code', 'trade_name', 'legal_name', 'company_type', 'active'],
            'fields' => [
                'code' => ['label' => 'Código', 'rule' => 'required|max_length[20]|alpha_numeric_punct'],
                'legal_name' => ['label' => 'Razón social', 'rule' => 'required|max_length[180]'],
                'trade_name' => ['label' => 'Nombre comercial', 'rule' => 'required|max_length[120]'],
                'tax_id' => ['label' => 'RFC / identificación fiscal', 'rule' => 'permit_empty|max_length[20]'],
                'company_type' => ['label' => 'Tipo', 'rule' => 'required|in_list[owner,customer,supplier,external_warehouse]', 'type' => 'select', 'options' => ['owner' => 'Propietaria', 'customer' => 'Cliente', 'supplier' => 'Proveedor', 'external_warehouse' => 'Frigorífico externo']],
                'active' => ['label' => 'Activo', 'rule' => 'permit_empty|in_list[0,1]', 'type' => 'checkbox'],
            ],
        ],
        'sites' => [
            'title' => 'Instalaciones', 'table' => 'sites', 'search' => ['code', 'name'],
            'list' => ['id', 'company_id', 'code', 'name', 'timezone', 'active'],
            'fields' => [
                'company_id' => ['label' => 'Empresa', 'rule' => 'required|is_natural_no_zero', 'type' => 'select', 'source' => 'companies'],
                'code' => ['label' => 'Código', 'rule' => 'required|max_length[20]|alpha_numeric_punct'],
                'name' => ['label' => 'Nombre', 'rule' => 'required|max_length[120]'],
                'timezone' => ['label' => 'Zona horaria', 'rule' => 'required|max_length[64]'],
                'address' => ['label' => 'Dirección', 'rule' => 'permit_empty|max_length[255]'],
                'active' => ['label' => 'Activo', 'rule' => 'permit_empty|in_list[0,1]', 'type' => 'checkbox'],
            ],
        ],
        'warehouses' => [
            'title' => 'Frigoríficos / almacenes', 'table' => 'warehouses', 'search' => ['code', 'name'],
            'list' => ['id', 'company_id', 'site_id', 'code', 'name', 'warehouse_type', 'active'],
            'fields' => [
                'company_id' => ['label' => 'Empresa', 'rule' => 'required|is_natural_no_zero', 'type' => 'select', 'source' => 'companies'],
                'site_id' => ['label' => 'Instalación', 'rule' => 'required|is_natural_no_zero', 'type' => 'select', 'source' => 'sites'],
                'code' => ['label' => 'Código', 'rule' => 'required|max_length[20]|alpha_numeric_punct'],
                'name' => ['label' => 'Nombre', 'rule' => 'required|max_length[120]'],
                'warehouse_type' => ['label' => 'Tipo', 'rule' => 'required|in_list[internal,external,production,staging]', 'type' => 'select', 'options' => ['internal' => 'Interno', 'external' => 'Externo', 'production' => 'Producción', 'staging' => 'Tránsito']],
                'external_company_id' => ['label' => 'Empresa externa', 'rule' => 'permit_empty|is_natural_no_zero', 'type' => 'select', 'source' => 'companies'],
                'active' => ['label' => 'Activo', 'rule' => 'permit_empty|in_list[0,1]', 'type' => 'checkbox'],
            ],
        ],
        'chambers' => [
            'title' => 'Cámaras', 'table' => 'chambers', 'search' => ['code', 'name'],
            'list' => ['id', 'warehouse_id', 'code', 'name', 'min_temperature_c', 'max_temperature_c', 'active'],
            'fields' => [
                'warehouse_id' => ['label' => 'Almacén', 'rule' => 'required|is_natural_no_zero', 'type' => 'select', 'source' => 'warehouses'],
                'code' => ['label' => 'Código', 'rule' => 'required|max_length[20]|alpha_numeric_punct'],
                'name' => ['label' => 'Nombre', 'rule' => 'required|max_length[100]'],
                'min_temperature_c' => ['label' => 'Temperatura mínima °C', 'rule' => 'permit_empty|decimal', 'type' => 'number', 'step' => '0.01'],
                'max_temperature_c' => ['label' => 'Temperatura máxima °C', 'rule' => 'permit_empty|decimal', 'type' => 'number', 'step' => '0.01'],
                'active' => ['label' => 'Activo', 'rule' => 'permit_empty|in_list[0,1]', 'type' => 'checkbox'],
            ],
        ],
        'locations' => [
            'title' => 'Ubicaciones', 'table' => 'locations', 'search' => ['code', 'name', 'path'],
            'list' => ['id', 'warehouse_id', 'chamber_id', 'code', 'name', 'location_type', 'path', 'max_units', 'max_weight_kg', 'active'],
            'fields' => [
                'warehouse_id' => ['label' => 'Almacén', 'rule' => 'required|is_natural_no_zero', 'type' => 'select', 'source' => 'warehouses'],
                'chamber_id' => ['label' => 'Cámara', 'rule' => 'permit_empty|is_natural_no_zero', 'type' => 'select', 'source' => 'chambers'],
                'parent_id' => ['label' => 'Ubicación padre', 'rule' => 'permit_empty|is_natural_no_zero', 'type' => 'select', 'source' => 'locations'],
                'code' => ['label' => 'Código', 'rule' => 'required|max_length[40]|alpha_numeric_punct'],
                'name' => ['label' => 'Nombre', 'rule' => 'required|max_length[120]'],
                'location_type' => ['label' => 'Tipo', 'rule' => 'required|in_list[staging,zone,row,aisle,position,stack_level]', 'type' => 'select', 'options' => ['staging' => 'Andén', 'zone' => 'Zona', 'row' => 'Fila', 'aisle' => 'Pasillo', 'position' => 'Posición', 'stack_level' => 'Nivel de estiba']],
                'path' => ['label' => 'Ruta jerárquica', 'rule' => 'required|max_length[255]'],
                'max_units' => ['label' => 'Capacidad de tinas', 'rule' => 'permit_empty|is_natural_no_zero', 'type' => 'number'],
                'max_weight_kg' => ['label' => 'Capacidad kg', 'rule' => 'permit_empty|decimal|greater_than[0]', 'type' => 'number', 'step' => '0.001'],
                'segregation_rule' => ['label' => 'Segregación', 'rule' => 'permit_empty|in_list[same_species,same_lot]', 'type' => 'select', 'options' => ['' => 'Sin regla', 'same_species' => 'Misma especie', 'same_lot' => 'Mismo lote']],
                'active' => ['label' => 'Activo', 'rule' => 'permit_empty|in_list[0,1]', 'type' => 'checkbox'],
            ],
        ],
        'vessels' => [
            'title' => 'Embarcaciones', 'table' => 'vessels', 'search' => ['code', 'name', 'registration_number'],
            'list' => ['id', 'owner_company_id', 'code', 'name', 'registration_number', 'flag_country', 'capacity_tons', 'active'],
            'fields' => [
                'owner_company_id' => ['label' => 'Empresa propietaria', 'rule' => 'required|is_natural_no_zero', 'type' => 'select', 'source' => 'companies'],
                'code' => ['label' => 'Código', 'rule' => 'required|max_length[12]|alpha_numeric_punct'],
                'name' => ['label' => 'Nombre', 'rule' => 'required|max_length[120]'],
                'registration_number' => ['label' => 'Matrícula', 'rule' => 'permit_empty|max_length[60]'],
                'flag_country' => ['label' => 'Bandera ISO-2', 'rule' => 'permit_empty|exact_length[2]|alpha'],
                'capacity_tons' => ['label' => 'Capacidad toneladas', 'rule' => 'permit_empty|decimal|greater_than[0]', 'type' => 'number', 'step' => '0.001'],
                'active' => ['label' => 'Activo', 'rule' => 'permit_empty|in_list[0,1]', 'type' => 'checkbox'],
            ],
        ],
        'vessel-tanks' => [
            'title' => 'Tanques por embarcación', 'table' => 'vessel_tanks', 'search' => ['tank_number', 'side'],
            'list' => ['id', 'vessel_id', 'tank_number', 'side', 'capacity_kg', 'active'],
            'fields' => [
                'vessel_id' => ['label' => 'Embarcación', 'rule' => 'required|is_natural_no_zero', 'type' => 'select', 'source' => 'vessels'],
                'tank_number' => ['label' => 'Tanque / bodega', 'rule' => 'required|max_length[20]|alpha_numeric_punct'],
                'side' => ['label' => 'Banda / posición', 'rule' => 'permit_empty|max_length[20]'],
                'capacity_kg' => ['label' => 'Capacidad kg', 'rule' => 'permit_empty|decimal|greater_than[0]', 'type' => 'number', 'step' => '0.001'],
                'active' => ['label' => 'Activo', 'rule' => 'permit_empty|in_list[0,1]', 'type' => 'checkbox'],
            ],
        ],
        'species' => [
            'title' => 'Especies', 'table' => 'species', 'search' => ['code', 'name', 'scientific_name'],
            'list' => ['id', 'code', 'name', 'scientific_name', 'active'],
            'fields' => [
                'code' => ['label' => 'Código', 'rule' => 'required|max_length[12]|alpha_numeric_punct'],
                'name' => ['label' => 'Nombre', 'rule' => 'required|max_length[100]'],
                'scientific_name' => ['label' => 'Nombre científico', 'rule' => 'permit_empty|max_length[150]'],
                'active' => ['label' => 'Activo', 'rule' => 'permit_empty|in_list[0,1]', 'type' => 'checkbox'],
            ],
        ],
        'sizes' => [
            'title' => 'Tallas', 'table' => 'size_grades', 'search' => ['code', 'name'],
            'list' => ['id', 'species_id', 'code', 'name', 'min_kg', 'max_kg', 'active'],
            'fields' => [
                'species_id' => ['label' => 'Especie', 'rule' => 'permit_empty|is_natural_no_zero', 'type' => 'select', 'source' => 'species'],
                'code' => ['label' => 'Código', 'rule' => 'required|max_length[20]|alpha_numeric_punct'],
                'name' => ['label' => 'Nombre', 'rule' => 'required|max_length[80]'],
                'min_kg' => ['label' => 'Mínimo kg', 'rule' => 'permit_empty|decimal|greater_than_equal_to[0]', 'type' => 'number', 'step' => '0.001'],
                'max_kg' => ['label' => 'Máximo kg', 'rule' => 'permit_empty|decimal|greater_than_equal_to[0]', 'type' => 'number', 'step' => '0.001'],
                'active' => ['label' => 'Activo', 'rule' => 'permit_empty|in_list[0,1]', 'type' => 'checkbox'],
            ],
        ],
        'tubs' => [
            'title' => 'Tinas y taras', 'table' => 'tubs', 'search' => ['code', 'barcode'],
            'list' => ['id', 'company_id', 'code', 'barcode', 'current_tare_kg', 'tare_verified_at', 'status', 'active'],
            'fields' => [
                'company_id' => ['label' => 'Empresa', 'rule' => 'required|is_natural_no_zero', 'type' => 'select', 'source' => 'companies'],
                'code' => ['label' => 'Número / código', 'rule' => 'required|max_length[40]|alpha_numeric_punct'],
                'barcode' => ['label' => 'Código de barras', 'rule' => 'permit_empty|max_length[80]'],
                'current_tare_kg' => ['label' => 'Tara vigente kg', 'rule' => 'required|decimal|greater_than[0]', 'type' => 'number', 'step' => '0.001'],
                'tare_verified_at' => ['label' => 'Tara verificada', 'rule' => 'permit_empty|valid_date[Y-m-d H:i:s]', 'type' => 'datetime-local'],
                'status' => ['label' => 'Estado', 'rule' => 'required|in_list[available,maintenance,inactive]', 'type' => 'select', 'options' => ['available' => 'Disponible', 'maintenance' => 'Mantenimiento', 'inactive' => 'Baja']],
                'active' => ['label' => 'Activo', 'rule' => 'permit_empty|in_list[0,1]', 'type' => 'checkbox'],
            ],
        ],
        'scales' => [
            'title' => 'Básculas', 'table' => 'scales', 'search' => ['code', 'name'],
            'list' => ['id', 'site_id', 'code', 'name', 'connection_type', 'adapter_key', 'calibration_expires_on', 'active'],
            'fields' => [
                'site_id' => ['label' => 'Instalación', 'rule' => 'required|is_natural_no_zero', 'type' => 'select', 'source' => 'sites'],
                'code' => ['label' => 'Código', 'rule' => 'required|max_length[30]|alpha_numeric_punct'],
                'name' => ['label' => 'Nombre', 'rule' => 'required|max_length[100]'],
                'connection_type' => ['label' => 'Conexión', 'rule' => 'required|in_list[simulator,rs232,usb_serial,tcp,middleware]', 'type' => 'select', 'options' => ['simulator' => 'Simulador', 'rs232' => 'RS-232', 'usb_serial' => 'USB serial', 'tcp' => 'TCP/IP', 'middleware' => 'Middleware local']],
                'adapter_key' => ['label' => 'Adapter', 'rule' => 'required|max_length[40]'],
                'configuration' => ['label' => 'Configuración JSON', 'rule' => 'permit_empty', 'type' => 'textarea', 'json' => true],
                'calibration_expires_on' => ['label' => 'Vence calibración', 'rule' => 'permit_empty|valid_date[Y-m-d]', 'type' => 'date'],
                'active' => ['label' => 'Activo', 'rule' => 'permit_empty|in_list[0,1]', 'type' => 'checkbox'],
            ],
        ],
        'printers' => [
            'title' => 'Impresoras', 'table' => 'printers', 'search' => ['code', 'name', 'station_code', 'windows_printer_name'],
            'list' => ['id', 'code', 'name', 'station_code', 'windows_printer_name', 'printer_language', 'last_test_status', 'is_default', 'active'],
            'fields' => [
                'site_id' => ['label' => 'Instalación', 'rule' => 'required|is_natural_no_zero', 'type' => 'select', 'source' => 'sites', 'group' => 'general'],
                'code' => ['label' => 'Código', 'rule' => 'required|max_length[30]|alpha_numeric_punct', 'group' => 'general'],
                'name' => ['label' => 'Nombre', 'rule' => 'required|max_length[100]', 'group' => 'general'],
                'station_code' => ['label' => 'Estación', 'rule' => 'required|max_length[50]|alpha_numeric_punct', 'group' => 'general'],
                'model' => ['label' => 'Modelo', 'rule' => 'permit_empty|max_length[80]', 'group' => 'general'],
                'printer_type' => ['label' => 'Tipo', 'rule' => 'required|in_list[pdf,browser,thermal]', 'type' => 'select', 'options' => ['thermal' => 'Térmica', 'browser' => 'Navegador', 'pdf' => 'PDF contingencia'], 'group' => 'general'],
                'connection_mode' => ['label' => 'Conexión', 'rule' => 'required|in_list[local_agent,network_tcp,browser,pdf]', 'type' => 'select', 'options' => ['local_agent' => 'Agente local (USB / Windows)', 'network_tcp' => 'Red TCP/IP', 'browser' => 'Navegador', 'pdf' => 'PDF'], 'group' => 'connection'],
                'adapter_key' => ['label' => 'Adaptador', 'rule' => 'required|in_list[pdf,browser,middleware,zpl]', 'type' => 'select', 'options' => ['middleware' => 'Agente local', 'zpl' => 'ZPL / red', 'browser' => 'Navegador', 'pdf' => 'PDF'], 'group' => 'connection'],
                'agent_url' => ['label' => 'URL del agente', 'rule' => 'permit_empty|max_length[255]', 'group' => 'connection', 'printer_mode' => 'local_agent'],
                'windows_printer_name' => ['label' => 'Nombre en Windows', 'rule' => 'permit_empty|max_length[160]', 'group' => 'connection', 'printer_mode' => 'local_agent'],
                'network_host' => ['label' => 'IP / host', 'rule' => 'permit_empty|max_length[100]', 'group' => 'connection', 'printer_mode' => 'network_tcp'],
                'network_port' => ['label' => 'Puerto', 'rule' => 'permit_empty|is_natural_no_zero|less_than_equal_to[65535]', 'type' => 'number', 'group' => 'connection', 'printer_mode' => 'network_tcp'],
                'printer_language' => ['label' => 'Lenguaje', 'rule' => 'required|in_list[zpl,ipl,pdf,html]', 'type' => 'select', 'options' => ['zpl' => 'ZPL / ZSim', 'ipl' => 'IPL', 'pdf' => 'PDF', 'html' => 'HTML / navegador'], 'group' => 'format'],
                'dpi' => ['label' => 'Resolución DPI', 'rule' => 'required|in_list[203,300,600]', 'type' => 'select', 'options' => ['203' => '203 dpi', '300' => '300 dpi', '600' => '600 dpi'], 'group' => 'format'],
                'label_width_mm' => ['label' => 'Ancho etiqueta (mm)', 'rule' => 'required|decimal|greater_than[20]', 'type' => 'number', 'step' => '0.01', 'group' => 'format'],
                'label_height_mm' => ['label' => 'Alto etiqueta (mm)', 'rule' => 'required|decimal|greater_than[20]', 'type' => 'number', 'step' => '0.01', 'group' => 'format'],
                'darkness' => ['label' => 'Oscuridad', 'rule' => 'permit_empty|is_natural|less_than_equal_to[30]', 'type' => 'number', 'group' => 'format'],
                'speed_ips' => ['label' => 'Velocidad (ips)', 'rule' => 'permit_empty|decimal|greater_than[0]', 'type' => 'number', 'step' => '0.1', 'group' => 'format'],
                'copies' => ['label' => 'Copias por defecto', 'rule' => 'required|is_natural_no_zero|less_than_equal_to[9]', 'type' => 'number', 'group' => 'format'],
                'is_default' => ['label' => 'Predeterminada de la instalación', 'rule' => 'permit_empty|in_list[0,1]', 'type' => 'checkbox', 'group' => 'general'],
                'active' => ['label' => 'Activa', 'rule' => 'permit_empty|in_list[0,1]', 'type' => 'checkbox', 'group' => 'general'],
                'configuration' => ['label' => 'Configuración heredada', 'rule' => 'permit_empty', 'type' => 'textarea', 'json' => true, 'hidden' => true],
                'last_test_at' => ['label' => 'Última prueba', 'rule' => 'permit_empty|max_length[19]', 'hidden' => true],
                'last_test_status' => ['label' => 'Estado de prueba', 'rule' => 'permit_empty|in_list[never,ok,error]', 'hidden' => true, 'options' => ['never' => 'Sin probar', 'ok' => 'Lista', 'error' => 'Error']],
                'last_test_message' => ['label' => 'Detalle de prueba', 'rule' => 'permit_empty|max_length[255]', 'hidden' => true],
            ],
        ],
        'quality-defects' => [
            'title' => 'Defectos de calidad', 'table' => 'quality_defects', 'search' => ['code', 'name'],
            'list' => ['id', 'code', 'name', 'default_destination', 'requires_hold', 'active'],
            'fields' => [
                'code' => ['label' => 'Código', 'rule' => 'required|max_length[30]|alpha_numeric_punct'],
                'name' => ['label' => 'Nombre', 'rule' => 'required|max_length[120]'],
                'default_destination' => ['label' => 'Destino sugerido', 'rule' => 'permit_empty|max_length[40]'],
                'requires_hold' => ['label' => 'Exige retención', 'rule' => 'permit_empty|in_list[0,1]', 'type' => 'checkbox'],
                'active' => ['label' => 'Activo', 'rule' => 'permit_empty|in_list[0,1]', 'type' => 'checkbox'],
            ],
        ],
    ];
}
