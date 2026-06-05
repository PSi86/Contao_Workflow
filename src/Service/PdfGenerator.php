<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FilesModel;
use Contao\FrontendTemplate;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\MasterModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;

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
        private readonly PlaceholderResolver $placeholderResolver,
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

        $relativePath = $this->pdfStorage->store(
            (int) $workflow->id,
            $this->resolveFileName($entry, $workflow),
            (string) $entry->token,
            $pdf,
            (string) $entry->pdfPath,
        );

        $entry->pdfPath = $relativePath;
        $entry->save();

        return $relativePath;
    }

    /**
     * Builds the configured PDF file name (without extension) from the workflow's
     * pattern and the entry data; empty string falls back to the entry token.
     */
    private function resolveFileName(EntryModel $entry, WorkflowModel $workflow): string
    {
        $pattern = trim((string) $workflow->pdfFileName);

        if ('' === $pattern) {
            return '';
        }

        $master = $this->resolveMaster($workflow);
        $vars = null !== $master ? $master->getPdfData() : [];

        $filled = $this->placeholderResolver->fill(
            $pattern,
            $entry->getData(),
            $vars,
            (string) $entry->email,
            (string) $workflow->title,
        );

        return $this->sanitizeFileName($filled);
    }

    private function sanitizeFileName(string $name): string
    {
        $name = strtr($name, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
            'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ß' => 'ss',
        ]);
        $name = preg_replace('/\s+/', '_', $name) ?? '';
        $name = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $name) ?? '';
        $name = trim($name, '_.-');

        return mb_substr($name, 0, 120);
    }

    private function renderHtml(EntryModel $entry, WorkflowModel $workflow): string
    {
        $masterModel = $this->resolveMaster($workflow);

        $data = $entry->getData();
        $extra = null !== $masterModel ? $masterModel->getPdfData() : [];

        $bodyHtml = $this->renderBody($entry, $workflow, $data, $extra);

        $templateName = null !== $masterModel ? $masterModel->getMasterTemplate() : self::MASTER_TEMPLATE;

        /** @var FrontendTemplate $master */
        $master = $this->framework->createInstance(FrontendTemplate::class, [$templateName]);
        $master->setData([
            'bodyHtml'     => $bodyHtml,
            'logoSrc'      => null !== $masterModel ? $this->resolveLogo($masterModel->pdfLogo) : '',
            'signatureSrc' => $this->writeSignatureImage($entry),
            'signerName'   => trim(($data['Vorname'] ?? '').' '.($data['Name'] ?? '')),
            'ort'          => $this->resolveSignatureLocation($workflow, $data),
            'datum'        => $this->resolveSignatureDate($workflow, $data),
            'footer'       => (string) ($extra['Footer'] ?? ''),
        ]);

        return $master->parse();
    }

    /**
     * The signature-block date comes from a configured workflow data field
     * (typically an "Aktuelle Zeit" answer field), never from an implicit current
     * date – so the printed date always equals the stored/exported value.
     *
     * @param array<string, mixed> $data
     */
    private function resolveSignatureDate(WorkflowModel $workflow, array $data): string
    {
        $field = trim((string) $workflow->pdfSignatureDate);

        return '' !== $field ? (string) ($data[$field] ?? '') : '';
    }

    /**
     * Place printed in the signature line, taken from a configured data column
     * (e.g. the participant's town); empty when none is configured.
     *
     * @param array<string, mixed> $data
     */
    private function resolveSignatureLocation(WorkflowModel $workflow, array $data): string
    {
        $field = trim((string) $workflow->pdfSignatureLocation);

        return '' !== $field ? (string) ($data[$field] ?? '') : '';
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
    private function renderBody(EntryModel $entry, WorkflowModel $workflow, array $data, array $extra): string
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
        // Placeholders resolve through the shared PlaceholderResolver, so the same
        // ##data_*##/##var_*## tokens work here, in the mails and in the export.
        $rule = $this->ruleEvaluator->resolveRule($workflow, $entry);
        $body = null !== $rule ? $rule->getPdfBody() : '';

        $esc = fn (string $value): string => $this->esc($value);
        $email = (string) $entry->email;
        $title = (string) $workflow->title;

        $renderedTitle = $this->placeholderResolver->renderPdfText((string) $workflow->pdfTitle, $data, $extra, $email, $title, $esc);
        $renderedBody = nl2br($this->placeholderResolver->renderPdfText($body, $data, $extra, $email, $title, $esc));

        return ('' !== $renderedTitle ? '<h1>'.$renderedTitle.'</h1>' : '').'<div class="letter-body">'.$renderedBody.'</div>';
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

        // Margins leave room for the master template's running page header
        // (logo + address + blue rule) and the 4-column footer.
        $mpdf = new Mpdf([
            'tempDir'       => $tempDir,
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'margin_top'    => 34,
            'margin_bottom' => 30,
            'margin_left'   => 20,
            'margin_right'  => 20,
            'margin_header' => 8,
            'margin_footer' => 8,
            // Block remote (http/https) and file:// resources so a stray <img>/<link>
            // in a body (e.g. an unescaped custom template) cannot trigger an SSRF or
            // read local files. Our logo/signature use plain local paths (no scheme),
            // which bypass this check and keep working.
            'whitelistStreamWrappers' => [],
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

        // Only embed a genuine PNG (defence in depth; the front-end controller
        // already validates the submitted signature on the way in).
        if (false === $binary || !str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
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
