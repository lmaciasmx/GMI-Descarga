<?php

namespace Tests\Database;

use App\Common\Exceptions\DomainException;
use App\Database\Seeds\CoreCatalogSeeder;
use App\Modules\Labeling\Contracts\LabelPrinterInterface;
use App\Modules\Labeling\DTO\PrintResult;
use App\Modules\Labeling\Services\CodeImageService;
use App\Modules\Labeling\Services\LabelService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;
use Tests\Support\OperationalFixture;

final class LabelServiceDatabaseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $migrate = true;
    protected $refresh = true;
    protected $seed = CoreCatalogSeeder::class;

    public function testFailedInitialPrintCanBeRetriedWithoutBecomingAReprint(): void
    {
        $fixture = (new OperationalFixture($this->db))->createReceivableWeighing('15');
        $templateId = (int) $this->db->table('label_templates')->where('code', 'TINA_RECEPCION')->get()->getRow('id');

        try {
            $this->service(new FailedLabelPrinter())->print($fixture['weighingId'], $templateId, null, 0);
            self::fail('El adaptador fallido debe reportar error de dominio.');
        } catch (DomainException $exception) {
            self::assertSame('LABEL_PRINT_FAILED', $exception->domainCode);
        }

        $retry = $this->service(new SuccessfulLabelPrinter())->print($fixture['weighingId'], $templateId, null, 0);
        self::assertSame(2, (int) $this->db->table('print_jobs')->where('label_id', $fixture['labelId'])->countAllResults());
        self::assertSame(2, (int) $this->db->table('print_jobs')->where('id', $retry['print_job_id'])->get()->getRow('print_number'));
        self::assertSame(0, (int) $this->db->table('print_jobs')->where('id', $retry['print_job_id'])->get()->getRow('is_reprint'));
    }

    public function testSuccessfulInitialPrintRequiresReasonedReprintAfterwards(): void
    {
        $fixture = (new OperationalFixture($this->db))->createReceivableWeighing('16');
        $templateId = (int) $this->db->table('label_templates')->where('code', 'TINA_RECEPCION')->get()->getRow('id');
        $service = $this->service(new SuccessfulLabelPrinter());
        $service->print($fixture['weighingId'], $templateId, null, 0);

        try {
            $service->print($fixture['weighingId'], $templateId, null, 0);
            self::fail('Una segunda impresión inicial debe rechazarse.');
        } catch (DomainException $exception) {
            self::assertSame('LABEL_ALREADY_PRINTED', $exception->domainCode);
        }

        $reprint = $service->reprint($fixture['labelId'], 'Etiqueta dañada en maniobra', null, 0);
        self::assertSame(2, $reprint['print_number']);
        $job = $this->db->table('print_jobs')->where('id', $reprint['print_job_id'])->get()->getRowArray();
        self::assertSame(1, (int) $job['is_reprint']);
        self::assertSame('Etiqueta dañada en maniobra', $job['reason']);
    }

    private function service(LabelPrinterInterface $printer): LabelService
    {
        return new LabelService($this->db, $printer, new CodeImageService(), Services::auditService(false));
    }
}

final class SuccessfulLabelPrinter implements LabelPrinterInterface
{
    public function printLabel(array $label): PrintResult
    {
        return new PrintResult(true, 'sent', 'test-print');
    }

    public function reprintLabel(array $label, string $reason): PrintResult
    {
        return new PrintResult(true, 'sent', 'test-reprint');
    }

    public function getPrinterStatus(): array
    {
        return ['connected' => true, 'status' => 'test'];
    }

    public function cancelPrintJob(string $jobId): bool
    {
        return false;
    }
}

final class FailedLabelPrinter implements LabelPrinterInterface
{
    public function printLabel(array $label): PrintResult
    {
        return new PrintResult(false, 'failed', 'test-failure', null, 'Fallo controlado de prueba.');
    }

    public function reprintLabel(array $label, string $reason): PrintResult
    {
        return new PrintResult(false, 'failed', 'test-failure', null, 'Fallo controlado de prueba.');
    }

    public function getPrinterStatus(): array
    {
        return ['connected' => false, 'status' => 'test'];
    }

    public function cancelPrintJob(string $jobId): bool
    {
        return false;
    }
}
