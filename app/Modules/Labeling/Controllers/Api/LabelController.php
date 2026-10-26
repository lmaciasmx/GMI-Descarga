<?php

namespace App\Modules\Labeling\Controllers\Api;

use App\Common\Controllers\ApiController;

final class LabelController extends ApiController
{
    public function print()
    {
        return $this->execute(function (): array {
            $payload = $this->payload();
            $this->validatePayload($payload, [
                'weighing_id' => 'required|is_natural_no_zero',
                'template_id' => 'required|is_natural_no_zero',
                'printer_id' => 'permit_empty|is_natural_no_zero',
            ]);
            return service('labelService')->print((int) $payload['weighing_id'], (int) $payload['template_id'], isset($payload['printer_id']) ? (int) $payload['printer_id'] : null, $this->actorId());
        }, 201);
    }

    public function reprint(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('label', $id);
            $payload = $this->payload();
            $this->validatePayload($payload, ['reason' => 'required|min_length[5]|max_length[255]', 'printer_id' => 'permit_empty|is_natural_no_zero']);
            return service('labelService')->reprint($id, (string) $payload['reason'], isset($payload['printer_id']) ? (int) $payload['printer_id'] : null, $this->actorId());
        });
    }

    public function localResult(int $jobId)
    {
        return $this->execute(function () use ($jobId): array {
            $payload = $this->payload();
            $this->validatePayload($payload, [
                'successful' => 'required|in_list[0,1]',
                'provider_reference' => 'permit_empty|max_length[255]',
                'error_message' => 'permit_empty|max_length[500]',
            ]);

            return service('labelService')->completeLocalAgentJob(
                $jobId,
                (bool) ((int) $payload['successful']),
                isset($payload['provider_reference']) ? (string) $payload['provider_reference'] : null,
                isset($payload['error_message']) ? (string) $payload['error_message'] : null,
                $this->actorId(),
            );
        });
    }

}
