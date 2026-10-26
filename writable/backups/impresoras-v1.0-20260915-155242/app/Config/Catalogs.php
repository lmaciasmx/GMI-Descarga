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
            'title' => 'Tallas', 'table' => 'size_grades', 'search' => ['code', 'name', 'market_type'],
            'list' => ['id', 'species_id', 'code', 'name', 'min_kg', 'max_kg', 'market_type', 'active'],
            'fields' => [
                'species_id' => ['label' => 'Especie', 'rule' => 'permit_empty|is_natural_no_zero', 'type' => 'select', 'source' => 'species'],
                'code' => ['label' => 'Código', 'rule' => 'required|max_length[20]|alpha_numeric_punct'],
                'name' => ['label' => 'Nombre', 'rule' => 'required|max_length[80]'],
                'min_kg' => ['label' => 'Mínimo kg', 'rule' => 'permit_empty|decimal|greater_than_equal_to[0]', 'type' => 'number', 'step' => '0.001'],
                'max_kg' => ['label' => 'Máximo kg', 'rule' => 'permit_empty|decimal|greater_than_equal_to[0]', 'type' => 'number', 'step' => '0.001'],
                'market_type' => ['label' => 'Mercado', 'rule' => 'required|in_list[national,export]', 'type' => 'select', 'options' => ['national' => 'Nacional', 'export' => 'Exportación']],
                'active' => ['label' => 'Activo', 'rule' => 'permit_empty|in_list[0,1]', 'type' => 'checkbox'],
            ],
        ],
        'tubs' => [
			'title' => 'Tinas y taras',
			'table' => 'tubs',
			'search' => ['code', 'barcode'],
			'list' => [
				'id',
				'code',
				'barcode',
				'current_tare_kg',
				'tare_verified_at',
				'status',
				'active'
			],
			'fields' => [
				'code' => [
					'label' => 'Número / código',
					'rule' => 'required|max_length[40]|alpha_numeric_punct'
				],
				'barcode' => [
					'label' => 'Código de barras',
					'rule' => 'permit_empty|max_length[80]'
				],
				'current_tare_kg' => [
					'label' => 'Tara vigente kg',
					'rule' => 'required|decimal|greater_than[0]',
					'type' => 'number',
					'step' => '0.001'
				],
				'tare_verified_at' => [
					'label' => 'Tara verificada',
					'rule' => 'permit_empty|valid_date[Y-m-d H:i:s]',
					'type' => 'datetime-local'
				],
				'status' => [
					'label' => 'Estado',
					'rule' => 'required|in_list[available,maintenance,inactive]',
					'type' => 'select',
					'options' => [
						'available' => 'Disponible',
						'maintenance' => 'Mantenimiento',
						'inactive' => 'Baja'
					]
				],
				'active' => [
					'label' => 'Activo',
					'rule' => 'permit_empty|in_list[0,1]',
					'type' => 'checkbox'
				],
			],
		],
        'operation-participants' => [
            'title' => 'Participantes de operación',
            'table' => 'operation_participants',
            'search' => ['code', 'name'],
            'list' => [
                'id',
                'company_id',
                'site_id',
                'code',
                'name',
                'can_sort',
                'can_label',
                'active',
            ],
            'fields' => [
                'company_id' => [
                    'label' => 'Empresa',
                    'rule' => 'required|is_natural_no_zero',
                    'type' => 'select',
                    'source' => 'companies',
                ],
                'site_id' => [
                    'label' => 'Instalación',
                    'rule' => 'required|is_natural_no_zero',
                    'type' => 'select',
                    'source' => 'sites',
                ],
                'code' => [
                    'label' => 'Código',
                    'rule' => 'required|max_length[30]|alpha_numeric_punct',
                ],
                'name' => [
                    'label' => 'Nombre',
                    'rule' => 'required|max_length[120]',
                ],
                'can_sort' => [
                    'label' => 'Puede ser sorteador',
                    'rule' => 'permit_empty|in_list[0,1]',
                    'type' => 'checkbox',
                ],
                'can_label' => [
                    'label' => 'Puede ser etiquetador',
                    'rule' => 'permit_empty|in_list[0,1]',
                    'type' => 'checkbox',
                ],
                'active' => [
                    'label' => 'Activo',
                    'rule' => 'permit_empty|in_list[0,1]',
                    'type' => 'checkbox',
                ],
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
            'title' => 'Impresoras', 'table' => 'printers', 'search' => ['code', 'name'],
            'list' => ['id', 'site_id', 'code', 'name', 'printer_type', 'adapter_key', 'active'],
            'fields' => [
                'site_id' => ['label' => 'Instalación', 'rule' => 'required|is_natural_no_zero', 'type' => 'select', 'source' => 'sites'],
                'code' => ['label' => 'Código', 'rule' => 'required|max_length[30]|alpha_numeric_punct'],
                'name' => ['label' => 'Nombre', 'rule' => 'required|max_length[100]'],
                'printer_type' => ['label' => 'Tipo', 'rule' => 'required|in_list[pdf,browser,thermal]', 'type' => 'select', 'options' => ['pdf' => 'PDF contingencia', 'browser' => 'Navegador', 'thermal' => 'Térmica']],
                'adapter_key' => ['label' => 'Adapter', 'rule' => 'required|in_list[pdf,browser,middleware,zpl]', 'type' => 'select', 'options' => ['pdf' => 'PDF', 'browser' => 'Navegador', 'middleware' => 'Middleware', 'zpl' => 'Zebra ZPL']],
                'configuration' => ['label' => 'Configuración JSON', 'rule' => 'permit_empty', 'type' => 'textarea', 'json' => true],
                'active' => ['label' => 'Activo', 'rule' => 'permit_empty|in_list[0,1]', 'type' => 'checkbox'],
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
