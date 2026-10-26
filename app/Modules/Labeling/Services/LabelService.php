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
     * Prepara el marbete dentro de la transacción de negocio de Entrada a Frigorífico.
     * No crea trabajo de impresión; sólo garantiza una etiqueta vigente e inmutable.
     */
    public function prepare(int $weighingId, int $templateId, int $actorUserId): array
    {
        $context = $this->weighingContext($weighingId, false);
        $template = $this->template($templateId);
        $context = $this->assignLabelerWhenNeeded($context, $actorUserId);
        $label = $this->db->table('labels')->where('weighing_id', $weighingId)->get()->getRowArray();

        if ($label === null) {
            return $this->createLabel($context, $template, $actorUserId);
        }

        if ((string) $label['status'] === 'canceled') {
            throw new DomainException('La etiqueta vigente fue cancelada.', 'LABEL_CANCELED');
        }

        if ((int) $label['template_id'] !== (int) $template['id']) {
            throw new DomainException('La etiqueta vigente pertenece a otra plantilla.', 'LABEL_TEMPLATE_MISMATCH');
        }

        return $label;
    }

    /**
     * Imprime una etiqueta previamente preparada. Para la PC43t configurada
     * con agente local devuelve ZPL + datos del agente al navegador, que hace
     * el POST contra 127.0.0.1 y posteriormente confirma el resultado.
     */
    public function printPrepared(int $labelId, ?int $printerId, int $actorUserId): array
    {
        $this->db->transException(true)->transBegin();

        try {
            $label = $this->db->query('SELECT * FROM labels WHERE id = ? FOR UPDATE', [$labelId])->getRowArray();
            if ($label === null || (string) $label['status'] === 'canceled') {
                throw new DomainException('Etiqueta no disponible para impresión.', 'LABEL_NOT_PRINTABLE');
            }

            $context = $this->weighingContext((int) $label['weighing_id'], false);
            $printer = $this->assertPrinter($printerId, (int) $context['site_id']);
            $template = $this->template((int) $label['template_id']);

            $alreadyRequested = $this->db->table('print_jobs')
                ->where(['label_id' => $labelId, 'is_reprint' => 0])
                ->whereIn('result', ['queued', 'sent', 'generated_pdf', 'awaiting_browser', 'awaiting_local_agent'])
                ->countAllResults() > 0;

            if ($alreadyRequested) {
                throw new DomainException('La impresión inicial ya fue solicitada; utiliza reimpresión.', 'LABEL_ALREADY_PRINTED');
            }

            $printNumber = (int) ($this->db->table('print_jobs')
                ->selectMax('print_number')
                ->where('label_id', $labelId)
                ->get()
                ->getRow('print_number') ?? 0) + 1;

            $job = $this->createJob($label, $template, $printerId, $printNumber, false, null, $actorUserId);

            if ($printer !== null && strtolower((string) ($printer['connection_mode'] ?? '')) === 'local_agent') {
                $zpl = $this->renderOperationalZpl($context, $label, $printer);
                $this->db->table('print_jobs')->where('id', (int) $job['id'])->update([
                    'result' => 'awaiting_local_agent',
                    'completed_at' => null,
                ]);
                $this->db->transCommit();

                $this->audit->record(
                    'label.print_requested',
                    'label',
                    $labelId,
                    $actorUserId,
                    (int) $context['company_id'],
                    (int) $context['site_id'],
                    null,
                    ['job_id' => $job['id'], 'printer_id' => $printerId],
                    'Impresión inicial por agente local',
                );

                return [
                    'label_id' => $labelId,
                    'print_job_id' => (int) $job['id'],
                    'print' => [
                        'status' => 'awaiting_local_agent',
                        'browser_print_available' => false,
                        'download_available' => false,
                    ],
                    'local_agent' => [
                        'agent_url' => rtrim((string) ($printer['agent_url'] ?? 'http://127.0.0.1:18181'), '/'),
                        'printer_name' => (string) ($printer['windows_printer_name'] ?? ''),
                        'format' => 'zpl',
                        'content' => $zpl,
                    ],
                ];
            }

            $this->assertTemplateCompatible($template, $printer);
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
            throw $exception;
        }

        $this->finishJob((int) $job['id'], $result->toArray());
        if (! $result->successful) {
            throw new DomainException($result->errorMessage ?? 'La impresión no fue confirmada.', 'LABEL_PRINT_FAILED', ['job_id' => $job['id']]);
        }

        $this->audit->record(
            'label.printed',
            'label',
            $labelId,
            $actorUserId,
            (int) $context['company_id'],
            (int) $context['site_id'],
            null,
            ['job_id' => $job['id'], 'result' => $result->status]
        );

        return [
            'label_id' => $labelId,
            'print_job_id' => (int) $job['id'],
            'print' => $this->publicPrintResult($result->toArray(), $rendered, null, (string) $template['format']),
        ];
    }

    public function print(int $weighingId, int $templateId, ?int $printerId, int $actorUserId): array
    {
        $label = $this->prepare($weighingId, $templateId, $actorUserId);
        $result = $this->printPrepared((int) $label['id'], $printerId, $actorUserId);
        $result['label'] = $label;
        return $result;
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
            $printNumber = (int) ($this->db->table('print_jobs')->selectMax('print_number')->where('label_id', $labelId)->get()->getRow('print_number') ?? 0) + 1;
            $job = $this->createJob($label, $template, $printerId, $printNumber, true, $reason, $actorUserId);

            if ($printer !== null && (string) ($printer['connection_mode'] ?? '') === 'local_agent') {
                $zpl = $this->renderOperationalZpl($context, $label, $printer);
                $this->db->table('print_jobs')->where('id', (int) $job['id'])->update([
                    'result' => 'awaiting_local_agent',
                    'completed_at' => null,
                ]);
                $this->db->transCommit();

                $this->audit->record(
                    'label.reprint_requested',
                    'label',
                    $labelId,
                    $actorUserId,
                    (int) $context['company_id'],
                    (int) $context['site_id'],
                    null,
                    ['job_id' => $job['id'], 'print_number' => $printNumber, 'printer_id' => $printerId],
                    $reason,
                );

                return [
                    'label_id' => $labelId,
                    'print_job_id' => (int) $job['id'],
                    'print_number' => $printNumber,
                    'print' => [
                        'status' => 'awaiting_local_agent',
                        'browser_print_available' => false,
                        'download_available' => false,
                    ],
                    'local_agent' => [
                        'agent_url' => rtrim((string) ($printer['agent_url'] ?? 'http://127.0.0.1:18181'), '/'),
                        'printer_name' => (string) ($printer['windows_printer_name'] ?? ''),
                        'format' => 'zpl',
                        'content' => $zpl,
                    ],
                ];
            }

            $this->assertTemplateCompatible($template, $printer);
            $rendered = $this->render($template, $context, $label);
            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }

        try {
            $result = $this->printer->reprintLabel([
                'code' => $label['code'],
                'format' => $template['format'],
                'rendered_content' => $rendered,
                'rendered_html' => $template['format'] === 'html' ? $rendered : null,
                'rendered_zpl' => $template['format'] === 'zpl' ? $rendered : null,
                'qr_payload' => $label['qr_payload'],
                'barcode_value' => $label['barcode_value'],
            ], $reason);
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

        return ['label_id' => $labelId, 'print_job_id' => $job['id'], 'print_number' => $printNumber, 'print' => $this->publicPrintResult($result->toArray(), $rendered, $reason, (string) $template['format'])];
    }

    public function completeLocalAgentJob(int $jobId, bool $successful, ?string $providerReference, ?string $errorMessage, int $actorUserId): array
    {
        $job = $this->db->table('print_jobs pj')
            ->select('pj.id, pj.label_id, pj.print_number, pj.is_reprint, pj.reason, pj.result, l.weighing_id, v.company_id, v.site_id')
            ->join('labels l', 'l.id = pj.label_id')
            ->join('weighings w', 'w.id = l.weighing_id')
            ->join('lots lo', 'lo.id = w.lot_id')
            ->join('voyages v', 'v.id = lo.voyage_id')
            ->where('pj.id', $jobId)
            ->get()->getRowArray();

        if ($job === null) {
            throw new DomainException('Trabajo de impresión no encontrado.', 'PRINT_JOB_NOT_FOUND');
        }
        if ((string) $job['result'] !== 'awaiting_local_agent') {
            throw new DomainException('El trabajo ya fue procesado o no corresponde al agente local.', 'PRINT_JOB_NOT_PENDING_LOCAL');
        }

        $status = $successful ? 'sent' : 'failed';
        $this->db->table('print_jobs')->where('id', $jobId)->update([
            'result' => $status,
            'provider_reference' => $providerReference,
            'error_message' => $successful ? null : mb_substr((string) $errorMessage, 0, 500),
            'completed_at' => date('Y-m-d H:i:s'),
        ]);

        $isReprint = (bool) ($job['is_reprint'] ?? false);
        $event = $successful
            ? ($isReprint ? 'label.reprinted' : 'label.printed')
            : ($isReprint ? 'label.reprint_failed' : 'label.print_failed');

        $this->audit->record(
            $event,
            'label',
            (int) $job['label_id'],
            $actorUserId,
            (int) $job['company_id'],
            (int) $job['site_id'],
            null,
            ['job_id' => $jobId, 'print_number' => (int) $job['print_number'], 'result' => $status],
            (string) ($job['reason'] ?? ''),
        );

        return ['print_job_id' => $jobId, 'status' => $status, 'successful' => $successful];
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
    sorter.username AS sorter_username,
    labeler.username AS labeler_username,
    t.code AS tub_code,
    s.name AS species_name,
    s.code AS species_code,
    sg.name AS size_name,
    sg.code AS size_code,
    lo.code AS lot_code,
    v.annual_number,
    ve.name AS vessel_name,
    ve.code AS vessel_code,
    c.trade_name AS company_name,
    v.company_id,
    v.site_id,
    rsa.code AS rsa_code,
    dpt.msc_code,
    dpt.dolphin_safe_code,
    dpt.freezing_temperature_c,
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
INNER JOIN companies c ON c.id = v.company_id
LEFT JOIN users sorter ON sorter.id = tf.sorter_user_id
LEFT JOIN users labeler ON labeler.id = tf.labeler_user_id
LEFT JOIN rsa_records rsa ON rsa.id = w.rsa_id
LEFT JOIN discharge_plan_tanks dpt
    ON dpt.discharge_plan_id = tf.discharge_plan_id
    AND dpt.voyage_tank_id = tf.actual_voyage_tank_id
    AND dpt.species_id = tf.species_id
    AND (dpt.size_grade_id = tf.size_grade_id OR dpt.size_grade_id IS NULL)
WHERE w.id = ?
ORDER BY (dpt.size_grade_id = tf.size_grade_id) DESC
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

        $printer = $this->db->table('printers')
            ->where(['id' => $printerId, 'site_id' => $siteId, 'active' => 1])
            ->get()->getRowArray();

        if ($printer === null) {
            throw new DomainException('La impresora no está activa en esta instalación.', 'PRINTER_NOT_AVAILABLE');
        }

        /*
         * Una impresora configurada como agente local no depende del driver
         * global de .env. El navegador enviará el ZPL al agente Node de esa
         * estación usando agent_url + windows_printer_name.
         */
        $connectionMode = strtolower((string) ($printer['connection_mode'] ?? ''));
        $adapter = strtolower((string) ($printer['adapter_key'] ?? ''));
        $language = strtolower((string) ($printer['printer_language'] ?? ''));

        if ($connectionMode === 'local_agent') {
            if (! in_array($adapter, ['middleware', 'zpl'], true)) {
                throw new DomainException('La impresora con agente local debe usar adaptador Agente local/ZPL.', 'PRINTER_ADAPTER_INVALID');
            }
            if ($language !== '' && $language !== 'zpl') {
                throw new DomainException('La impresora con agente local debe usar lenguaje ZPL / ZSim.', 'PRINTER_LANGUAGE_INVALID');
            }

            return $printer;
        }

        $compatibleAdapters = match ($driver) {
            'pdf' => ['pdf'],
            'browser' => ['browser'],
            'middleware' => ['middleware', 'zpl'],
            'thermal', 'zpl' => ['zpl', 'middleware'],
            default => [],
        };

        if (! in_array($adapter, $compatibleAdapters, true)) {
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
        if ($actorUserId <= 0 || ! empty($context['labeler_user_id'])) {
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
        // El QR es una llave estable. Toda la información viva de la tina se consulta en el sistema.
        $qrPayload = 'GMAR|LABEL=' . $code;
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

    private function publicPrintResult(array $result, string $rendered, ?string $reprintReason = null, string $format = 'html'): array
    {
        $public = $result;
        $public['download_available'] = ! empty($public['output_path']);
        unset($public['output_path']);

        if ($format === 'html' && ! empty($public['status']) && $public['status'] !== 'failed') {
            if ($reprintReason !== null) {
                $banner = '<div class="reprint-flag">REIMPRESIÓN · ' . esc($reprintReason) . '</div>';
                $rendered = preg_replace('/(<div class="label-border">)/', '$1' . $banner, $rendered, 1) ?? ($banner . $rendered);
            }
            $public['document_html'] = $rendered;
            $public['browser_print_available'] = true;
        }

        return $public;
    }

    private function renderOperationalZpl(array $context, array $label, array $printer): string
    {
        $clean = static function (mixed $value): string {
            $text = trim((string) ($value ?? ''));
            $text = str_replace(['^', '~', "", "
"], ['', '', ' ', ' '], $text);
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            return strtoupper(mb_substr($ascii !== false ? $ascii : $text, 0, 80));
        };

        $dpi = max(203, (int) ($printer['dpi'] ?? 203));
        $widthMm = (float) ($printer['label_width_mm'] ?? 99);
        $heightMm = (float) ($printer['label_height_mm'] ?? 50);
        $pw = (int) round($widthMm * ($dpi / 25.4));
        $ll = (int) round($heightMm * ($dpi / 25.4));
        $darkness = max(0, min(30, (int) ($printer['darkness'] ?? 10)));
        $speed = max(1, min(12, (int) round((float) ($printer['speed_ips'] ?? 3))));

        $species = $clean($context['species_name']);
        $speciesCode = $clean($context['species_code']);

        // Importante: para el marbete se debe privilegiar la talla descriptiva
        // (size_name). El size_code puede ser un consecutivo/clave técnica como 401293.
        $size = $clean($context['size_name'] ?: $context['size_code']);

        $lot = $clean($context['lot_code']);
        $vessel = $clean($context['vessel_name']);
        $voyage = $clean($context['annual_number']);
        $date = ! empty($context['confirmed_at']) ? date('d/m/Y', strtotime((string) $context['confirmed_at'])) : '';
        $gross = number_format((float) $context['gross_weight_kg'], 3, '.', '');
        $net = number_format((float) $context['accepted_net_kg'], 3, '.', '');
        $tare = number_format((float) $context['applied_tare_kg'], 3, '.', '');
        $penalty = number_format((float) $context['penalty_percent'], 2, '.', '');
        $rsa = $clean($context['rsa_code']);
        $tub = $clean($context['tub_code']);
        $qrPayload = 'GMAR|LABEL=' . $clean($label['code']);

        return implode("
", [
            '^XA', '^CI28', "^PW{$pw}", "^LL{$ll}", '^LH0,0', '^LS0', "^MD{$darkness}", "^PR{$speed}",
            '^FO8,8^GB' . max(1, $pw - 16) . ',' . max(1, $ll - 16) . ',3^FS',

            // Encabezado
            '^FO70,18^A0N,40,40^FB650,1,0,C,0^FD' . $species . '^FS',
            '^FO95,60^A0N,24,24^FB600,1,0,C,0^FDESPECIE: ' . $speciesCode . ' / TALLA: ' . $size . '^FS',
            '^FO20,94^GB750,3,3^FS',

            // Separación más cercana al QR para ganar espacio a la izquierda
            '^FO445,106^GB3,260,3^FS',

            // Datos operativos
            '^FO24,108^A0N,20,20^FDLOTE:^FS', '^FO118,108^A0N,20,20^FD' . $lot . '^FS',

            // BARCO y MAREA en el mismo nivel
            '^FO24,137^A0N,20,20^FDBARCO:^FS', '^FO118,137^A0N,20,20^FD' . $vessel . '^FS',
            '^FO300,137^A0N,20,20^FDMAREA:^FS', '^FO382,137^A0N,20,20^FD' . $voyage . '^FS',

            '^FO24,166^A0N,20,20^FDFECHA:^FS', '^FO118,166^A0N,20,20^FD' . $clean($date) . '^FS',
            '^FO20,195^GB410,2,2^FS',

            // Pesos
            '^FO24,207^A0N,20,20^FDPESO BRUTO:^FS', '^FO170,207^A0N,20,20^FD' . $gross . ' KG^FS',
            '^FO24,236^A0N,20,20^FDPESO NETO:^FS', '^FO170,236^A0N,20,20^FD' . $net . ' KG^FS',
            '^FO24,265^A0N,20,20^FDTARA:^FS', '^FO170,265^A0N,20,20^FD' . $tare . ' KG^FS',
            '^FO24,294^A0N,20,20^FDCASTIGO:^FS', '^FO170,294^A0N,20,20^FD' . $penalty . ' %^FS',
            '^FO24,323^A0N,20,20^FDRSA:^FS', '^FO170,323^A0N,20,20^FD' . $rsa . '^FS',
            '^FO24,352^A0N,20,20^FDTINA:^FS', '^FO170,352^A0N,20,20^FD' . $tub . '^FS',

            // QR grande
            '^FO520,105^BQN,2,10', '^FDLA,' . $qrPayload . '^FS',

            '^PQ1', '^XZ',
        ]);
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
        $values = [
            '{{label_code}}' => $clean($label['code']),
            '{{tub}}' => $clean($context['tub_code']),
            '{{company}}' => $clean($context['company_name']),
            '{{vessel}}' => $clean($context['vessel_name']),
            '{{vessel_code}}' => $clean($context['vessel_code']),
            '{{voyage}}' => $clean($context['annual_number']),
            '{{rsa}}' => $clean($context['rsa_code']),
            '{{species}}' => $clean($context['species_name']),
            '{{size}}' => $clean($context['size_name']),
            '{{source_tanks}}' => $clean($context['source_tanks']),
            '{{gross_weight_kg}}' => number_format((float) $context['gross_weight_kg'], 3, '.', ''),
            '{{tare_kg}}' => number_format((float) $context['applied_tare_kg'], 3, '.', ''),
            '{{physical_net_kg}}' => number_format((float) $context['physical_net_kg'], 3, '.', ''),
            '{{penalty_percent}}' => number_format((float) $context['penalty_percent'], 3, '.', ''),
            '{{penalty_kg}}' => number_format((float) $context['penalty_kg'], 3, '.', ''),
            '{{accepted_net_kg}}' => number_format((float) $context['accepted_net_kg'], 3, '.', ''),
            '{{lot_code}}' => $clean($context['lot_code']),
            '{{msc}}' => $clean($context['msc_code']),
            '{{dolphin_safe}}' => $clean($context['dolphin_safe_code']),
            '{{freezing_temperature_c}}' => number_format((float) $context['freezing_temperature_c'], 2, '.', ''),
            '{{sorter}}' => $clean($context['sorter_username'] ?? $context['sorter_user_id']),
            '{{labeler}}' => $clean($context['labeler_username'] ?? $context['labeler_user_id']),
            '{{date}}' => $clean($context['confirmed_at']),
            '{{qr_payload}}' => $clean($label['qr_payload']),
            '{{barcode_value}}' => $clean($label['barcode_value']),
            '{{qr_image}}' => $isZpl ? '' : $this->codeImages->qrDataUri((string) $label['qr_payload']),
            '{{barcode_image}}' => $isZpl ? '' : $this->codeImages->barcodeDataUri((string) $label['barcode_value']),
        ];

        return strtr((string) $template['template_body'], $values);
    }
}
