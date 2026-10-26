<?php

namespace App\Modules\Labeling\Services;

use App\Common\Exceptions\DomainException;
use App\Common\Services\AuditService;
use App\Modules\Labeling\Contracts\LabelPrinterInterface;
use CodeIgniter\Database\BaseConnection;
use Ramsey\Uuid\Uuid;

final class LabelService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly LabelPrinterInterface $printer,
        private readonly CodeImageService $codeImages,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Genera (o recupera) el marbete dentro de una transacción ya abierta.
     * No crea trabajo de impresión ni llama al dispositivo.
     */
    public function prepare(int $weighingId, int $templateId, int $actorUserId): array
    {
        $context = $this->weighingContext($weighingId, true);
        $template = $this->template($templateId);
        $context = $this->assignLabelerWhenNeeded($context, $actorUserId);
        $label = $this->db->table('labels')
            ->where('weighing_id', $weighingId)
            ->get()
            ->getRowArray();

        if ($label === null) {
            return $this->createLabel($context, $template, $actorUserId);
        }

        if ($label['status'] === 'canceled') {
            throw new DomainException(
                'La etiqueta vigente fue cancelada.',
                'LABEL_CANCELED'
            );
        }

        if ((int) $label['template_id'] !== (int) $template['id']) {
            throw new DomainException(
                'El marbete existente debe conservar su plantilla original.',
                'LABEL_TEMPLATE_MISMATCH'
            );
        }

        return $label;
    }

    /**
     * Solicita la impresión inicial de un marbete que ya fue generado.
     * El trabajo se persiste antes de llamar al adaptador físico.
     */
    public function printPrepared(int $labelId, ?int $printerId, int $actorUserId): array
    {
        $this->db->transException(true)->transBegin();

        try {
            $label = $this->db->query(
                'SELECT * FROM labels WHERE id = ? FOR UPDATE',
                [$labelId]
            )->getRowArray();

            if ($label === null || $label['status'] === 'canceled') {
                throw new DomainException(
                    'Etiqueta no disponible para impresión.',
                    'LABEL_NOT_PRINTABLE'
                );
            }

            $context = $this->weighingContext((int) $label['weighing_id'], false);
            $template = $this->template((int) $label['template_id']);
            $printer = $this->assertPrinter($printerId, (int) $context['site_id']);
            $this->assertTemplateCompatible($template, $printer);
            $context = $this->assignLabelerWhenNeeded($context, $actorUserId);

            if (
                $this->db->table('print_jobs')
                    ->where(['label_id' => $label['id'], 'is_reprint' => 0])
                    ->whereIn('result', ['queued', 'sent', 'generated_pdf', 'awaiting_browser'])
                    ->countAllResults() > 0
            ) {
                throw new DomainException(
                    'La impresión inicial ya fue solicitada; utiliza reimpresión.',
                    'LABEL_ALREADY_PRINTED'
                );
            }

            $printNumber = (int) (
                $this->db->table('print_jobs')
                    ->selectMax('print_number')
                    ->where('label_id', $labelId)
                    ->get()
                    ->getRow('print_number') ?? 0
            ) + 1;

            $job = $this->createJob(
                $label,
                $template,
                $printerId,
                $printNumber,
                false,
                null,
                $actorUserId
            );
            $rendered = $this->render($template, $context, $label);
            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }

        try {
            $result = $this->printer->printLabel([
                'code' => $label['code'],
                'format' => $template['format'],
                'rendered_content' => $rendered,
                'rendered_html' => $template['format'] === 'html' ? $rendered : null,
                'rendered_zpl' => $template['format'] === 'zpl' ? $rendered : null,
                'qr_payload' => $label['qr_payload'],
                'barcode_value' => $label['barcode_value'],
            ]);
        } catch (\Throwable $exception) {
            $this->markJobFailed((int) $job['id'], $exception);
            $this->audit->record(
                'label.print_failed',
                'label',
                $label['id'],
                $actorUserId,
                (int) $context['company_id'],
                (int) $context['site_id'],
                null,
                ['job_id' => $job['id']],
                'Fallo del adaptador de impresión'
            );
            throw $exception;
        }

        $this->finishJob((int) $job['id'], $result->toArray());

        if (! $result->successful) {
            $this->audit->record(
                'label.print_failed',
                'label',
                $label['id'],
                $actorUserId,
                (int) $context['company_id'],
                (int) $context['site_id'],
                null,
                ['job_id' => $job['id'], 'result' => $result->status],
                $result->errorMessage
            );
            throw new DomainException(
                $result->errorMessage ?? 'La impresión no fue confirmada.',
                'LABEL_PRINT_FAILED',
                ['job_id' => $job['id']]
            );
        }

        $this->audit->record(
            'label.printed',
            'label',
            $label['id'],
            $actorUserId,
            (int) $context['company_id'],
            (int) $context['site_id'],
            null,
            ['job_id' => $job['id'], 'result' => $result->status]
        );

        return [
            'label' => $label,
            'print_job_id' => $job['id'],
            'print' => $this->publicPrintResult($result->toArray(), $rendered),
        ];
    }

    public function print(int $weighingId, int $templateId, ?int $printerId, int $actorUserId): array
    {
        $this->db->transException(true)->transBegin();

        try {
            $context = $this->weighingContext($weighingId, true);
            $template = $this->template($templateId);
            $printer = $this->assertPrinter($printerId, (int) $context['site_id']);
            $this->assertTemplateCompatible($template, $printer);
            $context = $this->assignLabelerWhenNeeded($context, $actorUserId);
            $label = $this->db->table('labels')->where('weighing_id', $weighingId)->get()->getRowArray();

            if ($label === null) {
                $label = $this->createLabel($context, $template, $actorUserId);
            } elseif ($label['status'] === 'canceled') {
                throw new DomainException('La etiqueta vigente fue cancelada.', 'LABEL_CANCELED');
            } elseif ((int) $label['template_id'] !== (int) $template['id']) {
                throw new DomainException('El reintento debe conservar la plantilla original de la etiqueta.', 'LABEL_TEMPLATE_MISMATCH');
            }

            if ($this->db->table('print_jobs')->where(['label_id' => $label['id'], 'is_reprint' => 0])->whereIn('result', ['queued', 'sent', 'generated_pdf', 'awaiting_browser'])->countAllResults() > 0) {
                throw new DomainException('La impresión inicial ya fue solicitada; utiliza reimpresión.', 'LABEL_ALREADY_PRINTED');
            }

            $printNumber = (int) ($this->db->table('print_jobs')->selectMax('print_number')->where('label_id', $label['id'])->get()->getRow('print_number') ?? 0) + 1;
            $job = $this->createJob($label, $template, $printerId, $printNumber, false, null, $actorUserId);
            $rendered = $this->render($template, $context, $label);
            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }

        try {
            $result = $this->printer->printLabel(['code' => $label['code'], 'format' => $template['format'], 'rendered_content' => $rendered, 'rendered_html' => $template['format'] === 'html' ? $rendered : null, 'rendered_zpl' => $template['format'] === 'zpl' ? $rendered : null, 'qr_payload' => $label['qr_payload'], 'barcode_value' => $label['barcode_value']]);
        } catch (\Throwable $exception) {
            $this->markJobFailed((int) $job['id'], $exception);
            $this->audit->record('label.print_failed', 'label', $label['id'], $actorUserId, (int) $context['company_id'], (int) $context['site_id'], null, ['job_id' => $job['id']], 'Fallo del adaptador de impresión');
            throw $exception;
        }
        $this->finishJob((int) $job['id'], $result->toArray());
        if (! $result->successful) {
            $this->audit->record('label.print_failed', 'label', $label['id'], $actorUserId, (int) $context['company_id'], (int) $context['site_id'], null, ['job_id' => $job['id'], 'result' => $result->status], $result->errorMessage);
            throw new DomainException($result->errorMessage ?? 'La impresión no fue confirmada.', 'LABEL_PRINT_FAILED', ['job_id' => $job['id']]);
        }
        $this->audit->record('label.printed', 'label', $label['id'], $actorUserId, (int) $context['company_id'], (int) $context['site_id'], null, ['job_id' => $job['id'], 'result' => $result->status]);

        return ['label' => $label, 'print_job_id' => $job['id'], 'print' => $this->publicPrintResult($result->toArray(), $rendered)];
    }

    public function reprint(int $labelId, string $reason, ?int $printerId, int $actorUserId): array
    {
        if (trim($reason) === '') {
            throw new DomainException('La reimpresión requiere motivo.', 'REPRINT_REASON_REQUIRED');
        }

        $this->db->transException(true)->transBegin();
        try {
            $label = $this->db->query('SELECT * FROM labels WHERE id = ? FOR UPDATE', [$labelId])->getRowArray();
            if ($label === null || $label['status'] === 'canceled') {
                throw new DomainException('Etiqueta no disponible para reimpresión.', 'LABEL_NOT_REPRINTABLE');
            }
            $context = $this->weighingContext((int) $label['weighing_id'], false);
            $printer = $this->assertPrinter($printerId, (int) $context['site_id']);
            $context = $this->assignLabelerWhenNeeded($context, $actorUserId);
            $template = $this->template((int) $label['template_id']);
            $this->assertTemplateCompatible($template, $printer);
            $printNumber = (int) ($this->db->table('print_jobs')->selectMax('print_number')->where('label_id', $labelId)->get()->getRow('print_number') ?? 0) + 1;
            $job = $this->createJob($label, $template, $printerId, $printNumber, true, $reason, $actorUserId);
            $rendered = $this->render($template, $context, $label);
            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }

        try {
            $result = $this->printer->reprintLabel(['code' => $label['code'], 'format' => $template['format'], 'rendered_content' => $rendered, 'rendered_html' => $template['format'] === 'html' ? $rendered : null, 'rendered_zpl' => $template['format'] === 'zpl' ? $rendered : null, 'qr_payload' => $label['qr_payload'], 'barcode_value' => $label['barcode_value']], $reason);
        } catch (\Throwable $exception) {
            $this->markJobFailed((int) $job['id'], $exception);
            $this->audit->record('label.reprint_failed', 'label', $labelId, $actorUserId, (int) $context['company_id'], (int) $context['site_id'], null, ['job_id' => $job['id'], 'print_number' => $printNumber], $reason);
            throw $exception;
        }
        $this->finishJob((int) $job['id'], $result->toArray());
        if (! $result->successful) {
            $this->audit->record('label.reprint_failed', 'label', $labelId, $actorUserId, (int) $context['company_id'], (int) $context['site_id'], null, ['job_id' => $job['id'], 'print_number' => $printNumber, 'result' => $result->status], $reason);
            throw new DomainException($result->errorMessage ?? 'La reimpresión no fue confirmada.', 'LABEL_PRINT_FAILED', ['job_id' => $job['id']]);
        }
        $this->audit->record('label.reprinted', 'label', $labelId, $actorUserId, (int) $context['company_id'], (int) $context['site_id'], null, ['job_id' => $job['id'], 'print_number' => $printNumber, 'result' => $result->status], $reason);

        return ['label_id' => $labelId, 'print_job_id' => $job['id'], 'print_number' => $printNumber, 'print' => $this->publicPrintResult($result->toArray(), $rendered, $reason)];
    }

    private function weighingContext(int $weighingId, bool $lock): array
    {
        $sql = <<<'SQL'
SELECT
    w.*,
    tf.id AS filling_id,
    tf.species_id,
    tf.size_grade_id,
    tf.sorter_user_id,
    tf.labeler_user_id,
    tf.sorter_participant_id,
    tf.labeler_participant_id,
    sorter.username AS sorter_username,
    labeler.username AS labeler_username,
    sorter_participant.name AS sorter_participant_name,
    labeler_participant.name AS labeler_participant_name,
    t.code AS tub_code,
    s.code AS species_code,
    s.name AS species_name,
    sg.code AS size_code,
    sg.name AS size_name,
    lo.code AS lot_code,
    v.annual_number,
    ve.name AS vessel_name,
    ve.code AS vessel_code,
    owner_company.trade_name AS vessel_owner_name,
    actual_vt.tank_number AS actual_tank_number,
    COALESCE(w.observations, tf.observations, '') AS label_observations,
    c.trade_name AS company_name,
    v.company_id,
    v.site_id,
    rsa.code AS rsa_code,
    COALESCE(tf.msc_code_snapshot, dpt.msc_code) AS msc_code,
    COALESCE(tf.dolphin_safe_code_snapshot, dpt.dolphin_safe_code) AS dolphin_safe_code,
    COALESCE(tf.freezing_temperature_c_snapshot, dpt.freezing_temperature_c) AS freezing_temperature_c,
    (SELECT GROUP_CONCAT(vt.tank_number ORDER BY fst.is_primary DESC, vt.tank_number SEPARATOR ', ')
       FROM filling_source_tanks fst
       INNER JOIN voyage_tanks vt ON vt.id = fst.voyage_tank_id
      WHERE fst.tub_filling_id = tf.id) AS source_tanks
FROM weighings w
INNER JOIN tub_fillings tf ON tf.id = w.tub_filling_id
INNER JOIN tubs t ON t.id = w.tub_id
INNER JOIN species s ON s.id = tf.species_id
INNER JOIN size_grades sg ON sg.id = tf.size_grade_id
INNER JOIN lots lo ON lo.id = w.lot_id
INNER JOIN voyages v ON v.id = lo.voyage_id
INNER JOIN vessels ve ON ve.id = v.vessel_id
LEFT JOIN companies owner_company ON owner_company.id = ve.owner_company_id
INNER JOIN companies c ON c.id = v.company_id
LEFT JOIN voyage_tanks actual_vt ON actual_vt.id = tf.actual_voyage_tank_id
LEFT JOIN users sorter ON sorter.id = tf.sorter_user_id
LEFT JOIN users labeler ON labeler.id = tf.labeler_user_id
LEFT JOIN operation_participants sorter_participant ON sorter_participant.id = tf.sorter_participant_id
LEFT JOIN operation_participants labeler_participant ON labeler_participant.id = tf.labeler_participant_id
LEFT JOIN rsa_records rsa ON rsa.id = w.rsa_id
LEFT JOIN discharge_plan_tanks dpt ON dpt.id = tf.planned_line_id
WHERE w.id = ?
LIMIT 1
SQL;
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $row = $this->db->query($sql, [$weighingId])->getRowArray();

        if ($row === null || ! in_array($row['status'], ['confirmed', 'pending_inspection', 'received'], true)) {
            throw new DomainException('El pesaje no admite etiqueta.', 'WEIGHING_NOT_LABELABLE');
        }

        return $row;
    }

    private function template(int $templateId): array
    {
        $row = $this->db->table('label_templates')->where(['id' => $templateId, 'active' => 1])->get()->getRowArray();
        if ($row === null) {
            throw new DomainException('Plantilla de etiqueta no disponible.', 'LABEL_TEMPLATE_NOT_FOUND');
        }
        return $row;
    }

    private function assertPrinter(?int $printerId, int $siteId): ?array
    {
        $driver = strtolower((string) env('integrations.printer.driver', 'pdf'));
        if ($printerId === null) {
            if (! in_array($driver, ['pdf', 'browser'], true)) {
                throw new DomainException('La impresión física requiere seleccionar una impresora.', 'PRINTER_ID_REQUIRED');
            }

            return null;
        }

        $printer = $this->db->table('printers')->where(['id' => $printerId, 'site_id' => $siteId, 'active' => 1])->get()->getRowArray();
        if ($printer === null) {
            throw new DomainException('La impresora no está activa en esta instalación.', 'PRINTER_NOT_AVAILABLE');
        }

        $compatibleAdapters = match ($driver) {
            'pdf' => ['pdf'],
            'browser' => ['browser'],
            'middleware' => ['middleware', 'zpl'],
            'thermal', 'zpl' => ['zpl', 'middleware'],
            default => [],
        };
        if (! in_array((string) $printer['adapter_key'], $compatibleAdapters, true)) {
            throw new DomainException('La impresora seleccionada no corresponde al driver activo de esta estación.', 'PRINTER_DRIVER_MISMATCH');
        }

        return $printer;
    }

    private function assertTemplateCompatible(array $template, ?array $printer): void
    {
        $adapter = (string) ($printer['adapter_key'] ?? strtolower((string) env('integrations.printer.driver', 'pdf')));
        $format = (string) $template['format'];
        if (in_array($adapter, ['pdf', 'browser'], true) && $format !== 'html') {
            throw new DomainException('La impresión PDF o de navegador requiere una plantilla HTML.', 'LABEL_TEMPLATE_PRINTER_MISMATCH');
        }
        if ($adapter === 'zpl' && $format !== 'zpl') {
            throw new DomainException('La impresora Zebra requiere una plantilla ZPL.', 'LABEL_TEMPLATE_PRINTER_MISMATCH');
        }
    }

    private function assignLabelerWhenNeeded(array $context, int $actorUserId): array
    {
        if (
            $actorUserId <= 0
            || ! empty($context['labeler_user_id'])
            || ! empty($context['labeler_participant_id'])
        ) {
            return $context;
        }

        $user = $this->db->table('users')->select('id, username')->where(['id' => $actorUserId, 'active' => 1])->get()->getRowArray();
        if ($user === null) {
            return $context;
        }

        $this->db->table('tub_fillings')->where(['id' => (int) $context['filling_id'], 'labeler_user_id' => null])->update([
            'labeler_user_id' => $actorUserId,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $context['labeler_user_id'] = $actorUserId;
        $context['labeler_username'] = $user['username'];

        return $context;
    }

    private function createLabel(array $context, array $template, int $actorUserId): array
    {
        $uuid = Uuid::uuid7()->toString();
        $code = 'ETQ-' . date('Ymd') . '-' . strtoupper(substr(str_replace('-', '', $uuid), -10));
        $qrPayload = json_encode([
            'v' => 1,
            'label' => $code,
            'weighing_uuid' => $context['uuid'],
            'lot' => $context['lot_code'],
            'tub' => $context['tub_code'],
            'accepted_net_kg' => (float) $context['accepted_net_kg'],
            'species' => $context['species_name'],
            'size' => $context['size_name'],
            'source_tanks' => $context['source_tanks'],
            'vessel' => $context['vessel_code'],
            'voyage' => (int) $context['annual_number'],
            'rsa' => $context['rsa_code'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->db->table('labels')->insert([
            'uuid' => $uuid,
            'code' => $code,
            'weighing_id' => (int) $context['id'],
            'template_id' => (int) $template['id'],
            'qr_payload' => $qrPayload,
            'barcode_value' => $code,
            'status' => 'generated',
            'created_by' => $actorUserId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->db->table('labels')->where('id', $this->db->insertID())->get()->getRowArray();
    }

    private function createJob(array $label, array $template, ?int $printerId, int $printNumber, bool $reprint, ?string $reason, int $actorUserId): array
    {
        $this->db->table('print_jobs')->insert([
            'uuid' => Uuid::uuid7()->toString(),
            'label_id' => (int) $label['id'],
            'printer_id' => $printerId,
            'template_id' => (int) $template['id'],
            'template_version' => (int) $template['version'],
            'print_number' => $printNumber,
            'is_reprint' => $reprint,
            'reason' => $reason,
            'result' => 'queued',
            'requested_by' => $actorUserId,
            'requested_at' => date('Y-m-d H:i:s'),
        ]);
        return ['id' => (int) $this->db->insertID()];
    }

    private function finishJob(int $jobId, array $result): void
    {
        $this->db->table('print_jobs')->where('id', $jobId)->update([
            'result' => $result['status'],
            'provider_reference' => $result['provider_reference'],
            'output_path' => $result['output_path'] ?? null,
            'error_message' => $result['error_message'],
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function markJobFailed(int $jobId, \Throwable $exception): void
    {
        $this->db->table('print_jobs')->where('id', $jobId)->update([
            'result' => 'failed',
            'error_message' => 'Fallo del adaptador (' . mb_substr($exception::class, 0, 180) . ').',
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function publicPrintResult(array $result, string $rendered, ?string $reprintReason = null): array
    {
        $public = $result;
        $public['download_available'] = ! empty($public['output_path']);
        unset($public['output_path']);

        if (in_array((string) $public['status'], ['awaiting_browser', 'awaiting_browser_reprint'], true)) {
            $banner = $reprintReason === null ? '' : '<p style="color:#b42318;font-weight:700">REIMPRESIÓN: ' . esc($reprintReason) . '</p>';
            $public['document_html'] = $banner . $rendered;
        }

        return $public;
    }

    private function render(array $template, array $context, array $label): string
    {
        $isZpl = $template['format'] === 'zpl';
        $clean = static function (mixed $value) use ($isZpl): string {
            $text = (string) ($value ?? '—');
            if ($isZpl) {
                $text = str_replace(['^', '~', "\r", "\n"], ['', '', ' ', ' '], $text);
                return mb_substr($text, 0, 180);
            }
            return esc($text);
        };
        $compactNumber = static function (mixed $value, int $decimals = 3): string {
            $formatted = number_format((float) $value, $decimals, '.', '');
            return rtrim(rtrim($formatted, '0'), '.');
        };
        $speciesSize = trim(implode(' ', array_filter([
            (string) ($context['species_code'] ?? $context['species_name'] ?? ''),
            (string) ($context['size_code'] ?? $context['size_name'] ?? ''),
        ], static fn (string $value): bool => trim($value) !== '')));

        $values = [
            '{{label_code}}' => $clean($label['code']),
            '{{folio}}' => $clean($label['id']),
            '{{tub}}' => $clean($context['tub_code']),
            '{{company}}' => $clean($context['company_name']),
            '{{vessel}}' => $clean($context['vessel_name']),
            '{{vessel_owner}}' => $clean($context['vessel_owner_name'] ?? $context['company_name']),
            '{{actual_tank}}' => $clean($context['actual_tank_number'] ?? ''),
            '{{observations}}' => $clean($context['label_observations'] ?? ''),
            '{{vessel_code}}' => $clean($context['vessel_code']),
            '{{voyage}}' => $clean($context['annual_number']),
            '{{rsa}}' => $clean($context['rsa_code']),
            '{{species}}' => $clean($context['species_name']),
            '{{size}}' => $clean($context['size_name']),
            '{{species_code}}' => $clean($context['species_code'] ?? ''),
            '{{size_code}}' => $clean($context['size_code'] ?? ''),
            '{{species_size}}' => $clean($speciesSize),
            '{{source_tanks}}' => $clean($context['source_tanks']),
            '{{gross_weight_kg}}' => number_format((float) $context['gross_weight_kg'], 3, '.', ''),
            '{{tare_kg}}' => number_format((float) $context['applied_tare_kg'], 3, '.', ''),
            '{{tare_compact}}' => $compactNumber($context['applied_tare_kg'], 3),
            '{{physical_net_kg}}' => number_format((float) $context['physical_net_kg'], 3, '.', ''),
            '{{penalty_percent}}' => number_format((float) $context['penalty_percent'], 3, '.', ''),
            '{{penalty_compact}}' => $compactNumber($context['penalty_percent'], 3),
            '{{penalty_kg}}' => number_format((float) $context['penalty_kg'], 3, '.', ''),
            '{{accepted_net_kg}}' => number_format((float) $context['accepted_net_kg'], 3, '.', ''),
            '{{lot_code}}' => $clean($context['lot_code']),
            '{{msc}}' => $clean($context['msc_code']),
            '{{dolphin_safe}}' => $clean($context['dolphin_safe_code']),
            '{{freezing_temperature_c}}' => number_format((float) $context['freezing_temperature_c'], 2, '.', ''),
            '{{temperature_compact}}' => $compactNumber($context['freezing_temperature_c'], 2),
            '{{sorter}}' => $clean(
                $context['sorter_participant_name']
                    ?? $context['sorter_username']
                    ?? $context['sorter_user_id']
            ),
            '{{labeler}}' => $clean(
                $context['labeler_participant_name']
                    ?? $context['labeler_username']
                    ?? $context['labeler_user_id']
            ),
            '{{date}}' => $clean($context['confirmed_at']),
            '{{qr_payload}}' => $clean($label['qr_payload']),
            '{{barcode_value}}' => $clean($label['barcode_value']),
            '{{qr_image}}' => $isZpl ? '' : $this->codeImages->qrDataUri((string) $label['qr_payload']),
            '{{barcode_image}}' => $isZpl ? '' : $this->codeImages->barcodeDataUri((string) $label['barcode_value']),
        ];

        return strtr((string) $template['template_body'], $values);
    }
}

