<?php

namespace App\Modules\WarehouseEntry\Controllers\Api;

use App\Common\Controllers\ApiController;

final class WarehouseEntryController extends ApiController
{
    public function planDetail(int $planId)
    {
        return $this->execute(function () use ($planId): array {
            return service('warehouseEntryService')->planDetail($planId);
        });
    }

    public function cancelRegisteredEntry(int $weighingId)
    {
        return $this->execute(function () use ($weighingId): array {
            $payload = $this->payload();

            $this->validatePayload($payload, [
                'reason' => 'required|min_length[5]|max_length[500]',
            ]);

            return service('warehouseEntryService')->cancelRegisteredEntry(
                $weighingId,
                (string) $payload['reason'],
                $this->actorId()
            );
        });
    }

    public function tubs()
    {
        return $this->execute(function (): array {
            $query = trim((string) $this->request->getGet('q'));

            return [
                'items' => service('warehouseEntryService')->searchAvailableTubs($query),
            ];
        });
    }

    public function participants()
    {
        return $this->execute(function (): array {
            $query = trim((string) $this->request->getGet('q'));
            $role = trim((string) $this->request->getGet('role'));

            return [
                'items' => service('warehouseEntryService')->searchParticipants($query, $role),
            ];
        });
    }

    public function createParticipant()
    {
        return $this->execute(function (): array {
            $payload = $this->payload();

            $this->validatePayload($payload, [
                'name' => 'required|min_length[2]|max_length[120]',
                'role' => 'required|in_list[sorter,labeler]',
            ]);

            return service('warehouseEntryService')->quickCreateParticipant(
                (string) $payload['name'],
                (string) $payload['role'],
                $this->actorId()
            );
        }, 201);
    }

    public function chambers(int $warehouseId)
    {
        return $this->execute(function () use ($warehouseId): array {
            return [
                'items' => service('warehouseEntryService')->chambersForWarehouse($warehouseId),
            ];
        });
    }

    public function sectors(int $chamberId)
    {
        return $this->execute(function () use ($chamberId): array {
            return service('warehouseEntryService')->sectorAvailability($chamberId);
        });
    }

    public function stacks(int $chamberId)
    {
        return $this->execute(function () use ($chamberId): array {
            return service('warehouseEntryService')->freeStacks($chamberId);
        });
    }

    public function create()
    {
        return $this->execute(function (): array {
            $payload = $this->payload();

            $this->validatePayload($payload, [
                'plan_id' => 'required|is_natural_no_zero',
                'tub_id' => 'required|is_natural_no_zero',
                'actual_tank_id' => 'required|is_natural_no_zero',
                'species_id' => 'required|is_natural_no_zero',
                'size_grade_id' => 'required|is_natural_no_zero',
                'source_tank_ids' => 'required|is_array',
                'source_tank_ids.*' => 'required|is_natural_no_zero',
                'sorter_participant_id' => 'required|is_natural_no_zero',
                'labeler_participant_id' => 'required|is_natural_no_zero',
                'warehouse_id' => 'required|is_natural_no_zero',
                'chamber_id' => 'required|is_natural_no_zero',
                'sector_location_id' => 'required|is_natural_no_zero',
                'scale_id' => 'required|is_natural_no_zero',
                'scale_reading_id' => 'required|is_natural_no_zero',
                'gross_weight_kg' => 'required|decimal|greater_than[0]',
                'penalty_percent' => 'permit_empty|decimal|greater_than_equal_to[0]|less_than_equal_to[100]',
                'weight_source' => 'required|in_list[scale,middleware,simulator]',
                'original_value' => 'permit_empty|max_length[255]',
                'device_timestamp' => 'permit_empty|max_length[40]',
                'reason_code' => 'permit_empty|max_length[50]',
                'msc_code' => 'permit_empty|max_length[12]',
                'dolphin_safe_code' => 'permit_empty|max_length[12]',
                'freezing_temperature_c' => 'permit_empty|decimal',
                'template_id' => 'required|is_natural_no_zero',
                'printer_id' => 'permit_empty|is_natural_no_zero',
                'print_requested' => 'permit_empty|in_list[0,1,true,false]',
                'observations' => 'permit_empty|max_length[1000]',
            ]);

            return service('warehouseEntryService')->register(
                $payload,
                $this->actorId()
            );
        }, 201);
    }

    public function place(int $destinationId)
    {
        return $this->execute(function () use ($destinationId): array {
            $payload = $this->payload();

            $this->validatePayload($payload, [
                'chamber_id' => 'required|is_natural_no_zero',
                'sector_location_id' => 'required|is_natural_no_zero',
                'reason' => 'permit_empty|max_length[255]',
            ]);

            return service('warehouseEntryService')->place(
                $destinationId,
                (int) $payload['chamber_id'],
                (int) $payload['sector_location_id'],
                isset($payload['reason']) ? (string) $payload['reason'] : null,
                $this->actorId()
            );
        });
    }
}
