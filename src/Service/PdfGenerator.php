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
        private readonly DocumentBodyComposer $bodyComposer,
        private readonly PlaceholderResolver $placeholderResolver,
        private readonly PersonNameResolver $nameResolver,
        private readonly Slugger $slugger,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Generates the PDF, stores it and writes the relative path back onto the
     * entry. Returns the project-relative path.
     */
    public function generateAndStore(EntryModel $entry, WorkflowModel $workflow): string
    {
        $pdf = $this->render($entry, $workflow);

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
     * Renders the PDF for an entry and returns the raw bytes WITHOUT storing it
     * or touching the entry – used for the back-end preview (the entry may be a
     * synthetic, non-persisted sample).
     */
    public function render(EntryModel $entry, WorkflowModel $workflow): string
    {
        $this->framework->initialize();

        $masterModel = $this->resolveMaster($workflow);
        $templateName = null !== $masterModel ? $masterModel->getMasterTemplate() : self::MASTER_TEMPLATE;
        $extra = $this->resolveExtra($masterModel, $templateName);

        return $this->renderPdf(
            $this->renderHtml($entry, $workflow, $masterModel, $templateName, $extra),
            $extra,
        );
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
        // On-disk PDF name (and later a ZIP member): kept ASCII on purpose — a Cyrillic or
        // umlaut member name in a ZIP is mangled by many desktop unzip tools. The shared
        // slugger transliterates any script faithfully, so nothing is dropped to empty.
        return mb_substr($this->slugger->ascii($name), 0, 120);
    }

    /**
     * @param array<string, string> $extra letterhead variables completed with defaults
     */
    private function renderHtml(EntryModel $entry, WorkflowModel $workflow, ?MasterModel $masterModel, string $templateName, array $extra): string
    {
        $data = $entry->getData();

        $bodyHtml = $this->bodyComposer->compose($workflow, $entry, $data, $extra);

        /** @var FrontendTemplate $master */
        $master = $this->framework->createInstance(FrontendTemplate::class, [$templateName]);
        $master->setData([
            'bodyHtml'     => $bodyHtml,
            'logoSrc'      => null !== $masterModel ? $this->resolveLogo($masterModel->pdfLogo) : '',
            'signatureSrc' => $this->writeSignatureImage($entry),
            // Full name for the signature line, resolved from the first/last-name
            // columns however they are spelled in the source (Vorname/Nachname,
            // First name/Surname …) – not from the literal "Vorname"/"Name" columns.
            'signerName'   => $this->nameResolver->fullName($data),
            'ort'          => $this->resolveSignatureLocation($workflow, $data),
            'datum'        => $this->resolveSignatureDate($workflow, $data),
            'footer'       => (string) ($extra['Footer'] ?? ''),
            // Full letterhead variables, so a master template can build its header
            // and footer entirely from the configured PDF variables (e.g. the
            // generic pdf_master_generic). Legacy templates simply ignore this.
            'extra'        => $extra,
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
     * PDF variables for a template: the stored per-Briefpapier values completed with
     * the template's declared defaults ($GLOBALS['TL_WORKFLOW_PDF_VARS']). Layout
     * metrics (group "layout") fall back to their default when empty or non-numeric;
     * content variables are only filled when entirely absent (an intentionally
     * emptied content value stays empty).
     *
     * @return array<string, string>
     */
    private function resolveExtra(?MasterModel $masterModel, string $templateName): array
    {
        $stored = null !== $masterModel ? $masterModel->getPdfData() : [];
        $registry = $GLOBALS['TL_WORKFLOW_PDF_VARS'][$templateName] ?? [];

        $extra = $stored;

        foreach ($registry as $key => $declaration) {
            [$default, $group] = $this->declaration($declaration);

            if ('layout' === $group) {
                $value = trim((string) ($stored[$key] ?? ''));
                $extra[$key] = is_numeric($value) ? $value : $default;
            } elseif (!\array_key_exists($key, $extra)) {
                $extra[$key] = $default;
            }
        }

        return $extra;
    }

    /**
     * Normalises a registry entry to [default value, group]. An entry is either a
     * plain default (content variable) or ['default'=>…, 'label'=>…, 'group'=>…].
     *
     * @return array{0: string, 1: string}
     */
    private function declaration(mixed $declaration): array
    {
        if (\is_array($declaration)) {
            return [(string) ($declaration['default'] ?? ''), (string) ($declaration['group'] ?? 'content')];
        }

        return [(string) $declaration, 'content'];
    }

    /**
     * A page-margin value (mm) from the letterhead variables: numeric, clamped to a
     * sane range, else the built-in default.
     *
     * @param array<string, string> $extra
     */
    private function marginMm(array $extra, string $key, float $default): float
    {
        $value = $extra[$key] ?? '';
        $value = is_numeric($value) ? (float) $value : $default;

        return max(0.0, min(100.0, $value));
    }

    /**
     * @param array<string, string> $extra letterhead variables completed with defaults
     */
    private function renderPdf(string $html, array $extra): string
    {
        $tempDir = $this->projectDir.'/var/cache/mpdf';

        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        // Page margins come from the Briefpapier's layout variables (defaults leave
        // room for the running header – logo + address + rule – and the footer).
        $mpdf = new Mpdf([
            'tempDir'       => $tempDir,
            'mode'          => 'utf-8',
            'format'        => 'A4',
            // mPDF's built-in default font is a SERIF face (dejavuserifcondensed).
            // The templates set the body to sans-serif, but any element that does not
            // inherit that (e.g. a <div> nested in a table cell like the signature
            // labels) falls back to the serif default and looks different from the
            // body. Make the document default sans-serif so the fallback matches.
            'default_font'  => 'dejavusanscondensed',
            'margin_top'    => $this->marginMm($extra, 'MarginTop', 34),
            'margin_bottom' => $this->marginMm($extra, 'MarginBottom', 30),
            'margin_left'   => $this->marginMm($extra, 'MarginLeft', 20),
            'margin_right'  => $this->marginMm($extra, 'MarginRight', 20),
            'margin_header' => $this->marginMm($extra, 'MarginHeader', 8),
            'margin_footer' => $this->marginMm($extra, 'MarginFooter', 8),
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
