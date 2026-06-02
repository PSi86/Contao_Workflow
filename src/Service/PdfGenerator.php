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
     * Builds the body HTML.
     *
     * - Template mode: the selected body template handles everything itself
     *   (it receives all data, incl. answers, and branches internally) – PDF
     *   rules are NOT consulted.
     * - Letter mode: the shared heading comes from the workflow; the body text
     *   comes from the first matching PDF rule (a rule without conditions is the
     *   "else" case). If no rule matches, the body stays empty.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $extra
     */
    private function renderBody(EntryModel $entry, WorkflowModel $workflow, array $data, array $extra, string $datum): string
    {
        if ('template' === (string) $workflow->pdfBodyType && '' !== (string) $workflow->pdfBodyTemplate) {
            /** @var FrontendTemplate $bodyTpl */
            $bodyTpl = $this->framework->createInstance(FrontendTemplate::class, [(string) $workflow->pdfBodyTemplate]);
            $bodyTpl->setData([
                'data'  => $data,
                'extra' => $extra,
            ]);

            return $bodyTpl->parse();
        }

        // Letter mode: shared heading from the workflow, body text from the rule.
        $rule = $this->ruleEvaluator->resolveRule($workflow, $entry);
        $body = null !== $rule ? $rule->getPdfBody() : '';

        $map = $this->buildTokenMap($data, $extra, $entry, $datum);

        $renderedTitle = strtr($this->esc((string) $workflow->pdfTitle), $map);
        $renderedBody = nl2br(strtr($this->esc($body), $map));

        return ('' !== $renderedTitle ? '<h1>'.$renderedTitle.'</h1>' : '').'<div class="letter-body">'.$renderedBody.'</div>';
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
