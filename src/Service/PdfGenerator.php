<?php

declare(strict_types=1);

namespace Psimandl\TrainerWorkflowBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FilesModel;
use Contao\FrontendTemplate;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Psimandl\TrainerWorkflowBundle\Model\EntryModel;
use Psimandl\TrainerWorkflowBundle\Model\MasterModel;
use Psimandl\TrainerWorkflowBundle\Model\WorkflowModel;

/**
 * Renders the PDF via a global master template (logo, signature, footer) that
 * wraps a workflow-specific body. The body is either a simple "letter"
 * (back-end title/text with ##tokens##) or a dedicated body template file.
 * Output is produced with mPDF and stored through PdfStorage.
 */
class PdfGenerator
{
    private const MASTER_TEMPLATE = 'pdf_master';

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly PdfStorage $pdfStorage,
        private readonly RuleEvaluator $ruleEvaluator,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Generates the PDF, stores it and writes the relative path back onto the
     * entry. Returns the project-relative path.
     */
    public function generateAndStore(EntryModel $entry, WorkflowModel $workflow): string
    {
        $this->framework->initialize();

        $html = $this->renderHtml($entry, $workflow);
        $pdf = $this->renderPdf($html);

        $relativePath = $this->pdfStorage->store((int) $workflow->id, (string) $entry->token, $pdf);

        $entry->pdfPath = $relativePath;
        $entry->save();

        return $relativePath;
    }

    private function renderHtml(EntryModel $entry, WorkflowModel $workflow): string
    {
        $masterModel = $this->resolveMaster($workflow);

        $data = $entry->getData();
        $extra = null !== $masterModel ? $masterModel->getPdfData() : [];
        $datum = $entry->respondedAt ? date('d.m.Y', (int) $entry->respondedAt) : '';

        $bodyHtml = $this->renderBody($entry, $workflow, $data, $extra, $datum);

        $templateName = null !== $masterModel ? $masterModel->getMasterTemplate() : self::MASTER_TEMPLATE;

        /** @var FrontendTemplate $master */
        $master = $this->framework->createInstance(FrontendTemplate::class, [$templateName]);
        $master->setData([
            'bodyHtml'     => $bodyHtml,
            'logoSrc'      => null !== $masterModel ? $this->resolveLogo($masterModel->pdfLogo) : '',
            'signatureSrc' => $this->writeSignatureImage($entry),
            'signerName'   => trim(($data['Vorname'] ?? '').' '.($data['Name'] ?? '')),
            'ort'          => (string) ($extra['Ort'] ?? ''),
            'datum'        => $datum,
            'footer'       => (string) ($extra['Footer'] ?? ''),
        ]);

        return $master->parse();
    }

    private function resolveMaster(WorkflowModel $workflow): ?MasterModel
    {
        if (!$workflow->master) {
            return null;
        }

        return $this->framework->getAdapter(MasterModel::class)->findByPk((int) $workflow->master);
    }

    /**
     * Builds the body HTML. A matching PDF rule selects a body template;
     * otherwise the workflow default applies – either a dedicated body template
     * (template mode) or the back-end letter fields with ##token## replacement.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $extra
     */
    private function renderBody(EntryModel $entry, WorkflowModel $workflow, array $data, array $extra, string $datum): string
    {
        // 1. A matching rule wins and selects its body template.
        $ruleTemplate = $this->ruleEvaluator->resolveTemplate($workflow, $entry);

        if (null !== $ruleTemplate) {
            return $this->renderBodyTemplate($ruleTemplate, $data, $extra);
        }

        // 2. Workflow default: dedicated body template.
        if ('template' === $workflow->pdfBodyType && '' !== (string) $workflow->pdfBodyTemplate) {
            return $this->renderBodyTemplate((string) $workflow->pdfBodyTemplate, $data, $extra);
        }

        // 3. Workflow default: letter mode – replace ##tokens## in title/body.
        $map = $this->buildTokenMap($data, $extra, $entry, $datum);

        $title = strtr($this->esc((string) $workflow->pdfTitle), $map);
        $body = nl2br(strtr($this->esc((string) $workflow->pdfBody), $map));

        return ('' !== $title ? '<h1>'.$title.'</h1>' : '').'<div class="letter-body">'.$body.'</div>';
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $extra
     */
    private function renderBodyTemplate(string $templateName, array $data, array $extra): string
    {
        /** @var FrontendTemplate $body */
        $body = $this->framework->createInstance(FrontendTemplate::class, [$templateName]);
        $body->setData([
            'data'  => $data,
            'extra' => $extra,
        ]);

        return $body->parse();
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $extra
     *
     * @return array<string, string> "##token##" => escaped value
     */
    private function buildTokenMap(array $data, array $extra, EntryModel $entry, string $datum): array
    {
        $map = [];

        foreach ($data as $key => $value) {
            $map['##'.$key.'##'] = $this->esc((string) $value);
        }
        foreach ($extra as $key => $value) {
            $map['##'.$key.'##'] = $this->esc((string) $value);
        }

        $map['##datum##'] = $this->esc($datum);
        $map['##email##'] = $this->esc((string) $entry->email);

        return $map;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function renderPdf(string $html): string
    {
        $tempDir = $this->projectDir.'/var/cache/mpdf';

        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $mpdf = new Mpdf([
            'tempDir' => $tempDir,
            'mode'    => 'utf-8',
            'format'  => 'A4',
        ]);

        $mpdf->WriteHTML($html);

        return (string) $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * Resolves a Contao file UUID to an absolute path that mPDF can embed;
     * empty string when none is configured or the file is missing.
     */
    private function resolveLogo(mixed $uuid): string
    {
        if (!$uuid) {
            return '';
        }

        $file = $this->framework->getAdapter(FilesModel::class)->findByUuid($uuid);

        if (null === $file) {
            return '';
        }

        $path = $this->projectDir.'/'.$file->path;

        return is_file($path) ? $path : '';
    }

    /**
     * Writes the stored signature (base64 PNG) to a temp file and returns its
     * absolute path. mPDF embeds local image files reliably (unlike data URIs).
     */
    private function writeSignatureImage(EntryModel $entry): string
    {
        $signature = (string) $entry->signature;

        if ('' === $signature) {
            return '';
        }

        if (preg_match('#^data:image/[a-z]+;base64,(.*)$#is', $signature, $m)) {
            $signature = $m[1];
        }

        $binary = base64_decode(strtr(trim($signature), [' ' => '+', "\n" => '', "\r" => '']), true);

        if (false === $binary || '' === $binary) {
            return '';
        }

        $tempDir = $this->projectDir.'/var/cache/mpdf';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $path = $tempDir.'/sig_'.$entry->token.'.png';
        file_put_contents($path, $binary);

        return $path;
    }
}
