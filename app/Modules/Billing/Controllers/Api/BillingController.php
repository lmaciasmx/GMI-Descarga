<?php

namespace App\Modules\Billing\Controllers\Api;

use App\Common\Controllers\ApiController;

final class BillingController extends ApiController
{
    public function record()
    {
        return $this->execute(function (): array {
            $payload = $this->payload();
            $this->validatePayload($payload, [
                'company_id' => 'required|is_natural_no_zero',
                'site_id' => 'required|is_natural_no_zero',
                'sales_order_id' => 'permit_empty|is_natural_no_zero',
                'external_system' => 'permit_empty|max_length[40]',
                'external_document_id' => 'required|max_length[120]',
                'document_type' => 'required|max_length[30]',
                'fiscal_status' => 'required|max_length[30]',
                'fiscal_uuid' => 'permit_empty|valid_uuid',
                'issued_at' => 'permit_empty|valid_date[Y-m-d H:i:s]',
                'canceled_at' => 'permit_empty|valid_date[Y-m-d H:i:s]',
            ]);
            return service('billingReferenceService')->record($payload, $this->actorId());
        }, 201);
    }
}
