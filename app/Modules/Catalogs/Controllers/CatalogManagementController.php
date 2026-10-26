<?php

namespace App\Modules\Catalogs\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

final class CatalogManagementController extends BaseController
{
    public function index()
    {
        $service = service('catalogService');
        $definitions = $service->definitions();
        $selected = (string) ($this->request->getGet('catalog') ?? array_key_first($definitions));

        if (! isset($definitions[$selected])) {
            return redirect()->to('/catalogos')->with('warning', 'El catálogo solicitado no existe.');
        }

        $definition = $definitions[$selected];
        $sources = [];
        foreach ($definition['fields'] as $metadata) {
            $source = $metadata['source'] ?? null;
            if ($source !== null && isset($definitions[$source]) && ! isset($sources[$source])) {
                $sources[$source] = $service->list($source, null, 1000);
            }
        }

        return view('App\Modules\Catalogs\Views\index', [
            'title' => 'Gobierno de catálogos',
            'subtitle' => 'Datos maestros con alcance, integridad referencial y baja lógica.',
            'definitions' => $definitions,
            'selected' => $selected,
            'definition' => $definition,
            'rows' => $service->list($selected, null, 1000, true),
            'sources' => $sources,
        ]);
    }
}
