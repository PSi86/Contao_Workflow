<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Controller\Backend;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\FrontendTemplate;
use Contao\Message;
use Contao\StringUtil;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\DemoWorkflowSeeder;
use Psimandl\WorkflowBundle\Service\DocumentBodyComposer;
use Psimandl\WorkflowBundle\Service\PdfGenerator;
use Psimandl\WorkflowBundle\Service\PdfStorage;
use Psimandl\WorkflowBundle\Service\SpreadsheetExporter;
use Psimandl\WorkflowBundle\Service\SpreadsheetImporter;
use Psimandl\WorkflowBundle\Service\WorkflowConfigExporter;
use Psimandl\WorkflowBundle\Service\WorkflowConfigImporter;
use Psimandl\WorkflowBundle\Service\WorkflowFormView;
use Psimandl\WorkflowBundle\Service\WorkflowMailer;
use Psimandl\WorkflowBundle\Service\WorkflowPreviewData;
use Psimandl\WorkflowBundle\Service\WorkflowValidator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;

/**
 * Back end actions triggered from the workflow dashboard. State-changing actions
 * validate the Contao request token (rt) and redirect back with a message;
 * downloads stream a file.
 */
#[Route('/contao/workflow', defaults: ['_scope' => 'backend', '_token_check' => false])]
class WorkflowActionController
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly SpreadsheetImporter $importer,
        private readonly SpreadsheetExporter $exporter,
        private readonly WorkflowMailer $workflowMailer,
        private readonly PdfStorage $pdfStorage,
        private readonly WorkflowValidator $validator,
        private readonly DemoWorkflowSeeder $demoSeeder,
        private readonly WorkflowConfigExporter $configExporter,
        private readonly WorkflowConfigImporter $configImporter,
        private readonly PdfGenerator $pdfGenerator,
        private readonly WorkflowPreviewData $previewData,
        private readonly WorkflowFormView $formView,
        private readonly DocumentBodyComposer $bodyComposer,
        private readonly RouterInterface $router,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly Security $security,
        private readonly string $csrfTokenName,
    ) {
    }

    /**
     * Blocks execution of a not-runnable workflow (e.g. a fresh copy without a
     * source file): adds the concrete problems as an error and redirects back.
     */
    private function assertRunnable(WorkflowModel $workflow): ?RedirectResponse
    {
        $problems = $this->validator->getProblems($workflow);

        if ([] === $problems) {
            return null;
        }

        Message::addError(sprintf(
            'Workflow „%s" kann nicht ausgeführt werden: %s',
            StringUtil::specialchars((string) $workflow->title),
            StringUtil::specialchars(implode(' ', $problems)),
        ));

        return $this->backToDashboard();
    }

    #[Route('/import/{id}', name: 'workflow_import', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function import(int $id, Request $request): Response
    {
        $this->framework->initialize();
        $this->assertToken($request);
        $this->assertAccess();
        $workflow = $this->getWorkflow($id);

        if ($redirect = $this->assertRunnable($workflow)) {
            return $redirect;
        }

        try {
            $result = $this->importer->import($workflow);

            if ($result['skipped']) {
                Message::addInfo('Quelldatei unverändert – kein erneuter Import nötig.');
            } else {
                Message::addConfirmation(sprintf(
                    'Import: %d neu hinzugefügt, %d aktualisiert (gesamt %d).',
                    $result['inserted'],
                    $result['updated'],
                    $result['total'],
                ));
            }

            $this->reportSlugCollisions($result['collisions']);
        } catch (\Throwable $e) {
            Message::addError('Import fehlgeschlagen: '.$e->getMessage());
        }

        return $this->backToDashboard();
    }

    /**
     * Warns when several source columns normalize to the same placeholder slug.
     * Only the first column of each group is reachable via its ##data_<slug>##
     * token; the rest are ignored (their values are still imported and exported,
     * just not addressable by placeholder). The user must rename the columns in
     * the source file to make them unambiguous.
     *
     * @param array<string, array<int, string>> $collisions slug => colliding names
     */
    private function reportSlugCollisions(array $collisions): void
    {
        if ([] === $collisions) {
            return;
        }

        $groups = [];

        foreach ($collisions as $slug => $names) {
            $ignored = \array_slice($names, 1);
            $groups[] = sprintf(
                '##data_%s##: verwendet „%s", ignoriert „%s"',
                $slug,
                StringUtil::specialchars($names[0]),
                implode('", „', array_map([StringUtil::class, 'specialchars'], $ignored)),
            );
        }

        Message::addInfo(sprintf(
            'Achtung: Mehrere Spalten der Quelldatei ergeben denselben Platzhalter und sind dadurch nicht '
            .'eindeutig. Je Gruppe ist nur die erste Spalte über ihren Platzhalter erreichbar, die übrigen '
            .'werden ignoriert (ihre Werte werden weiterhin importiert und exportiert, nur nicht per '
            .'Platzhalter adressierbar). Bitte die betroffenen Spalten in der Quelldatei eindeutiger '
            .'benennen. Betroffen: %s.',
            implode('; ', $groups),
        ));
    }

    /**
     * Streams an inline PDF preview rendered from a representative sample entry
     * (the most recent real entry, else synthetic sample data). Read-only: no DB
     * write, so it is gated by module access only (no CSRF token needed).
     */
    #[Route('/preview-pdf/{id}', name: 'workflow_preview_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function previewPdf(int $id, Request $request): Response
    {
        $this->framework->initialize();
        $this->assertAccess();
        $workflow = $this->getWorkflow($id);

        try {
            $pdf = $this->pdfGenerator->render($this->previewData->sampleEntry($workflow), $workflow);
        } catch (\Throwable $e) {
            return new Response(
                'PDF-Vorschau fehlgeschlagen: '.$e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="vorschau.pdf"',
        ]);
    }

    /**
     * Renders a standalone HTML preview of the front-end form (with sample data)
     * for the edit mask. The submit button is disabled and the form cannot be
     * sent. Read-only, gated by module access only.
     */
    #[Route('/preview-form/{id}', name: 'workflow_preview_form', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function previewForm(int $id, Request $request): Response
    {
        $this->framework->initialize();
        $this->assertAccess();
        $workflow = $this->getWorkflow($id);

        try {
            $entry = $this->previewData->sampleEntry($workflow);
            $data = $entry->getData();
            $extra = $workflow->getMasterVars();
            $email = (string) $entry->email;

            /** @var FrontendTemplate $template */
            $template = $this->framework->createInstance(FrontendTemplate::class, ['mod_workflow_form']);
            $template->setData([
                'preview'          => true,
                'state'            => 'form',
                'requestToken'     => '',
                'error'            => '',
                'heading'          => $this->bodyComposer->resolveHeading($workflow, $data, $extra, $email),
                'intro'            => $this->bodyComposer->resolveIntro($workflow, $data, $extra, $email),
                'email'            => $email,
                'questions'        => $this->formView->buildQuestionViews($workflow->getQuestions(), $workflow, $entry),
                'answers'          => [],
                'requireSignature' => $workflow->isSignatureRequired(),
                'formId'           => 'preview',
            ]);

            $inner = $template->parse();
        } catch (\Throwable $e) {
            return new Response(
                '<p>Formular-Vorschau fehlgeschlagen: '.StringUtil::specialchars($e->getMessage()).'</p>',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['Content-Type' => 'text/html; charset=utf-8'],
            );
        }

        return new Response($this->wrapFormPreview($inner), Response::HTTP_OK, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * Wraps the parsed form markup in a minimal HTML page that loads the workflow
     * form assets, so the standalone preview looks like the real front-end form.
     */
    private function wrapFormPreview(string $inner): string
    {
        $asset = '/bundles/contaoworkflow';

        return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>Formular-Vorschau</title>'
            .'<link rel="stylesheet" href="'.$asset.'/workflow-form.css">'
            .'<style>body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;margin:2rem auto;max-width:760px;padding:0 1rem;color:#222}</style>'
            .'</head><body>'
            .$inner
            .'<script src="'.$asset.'/workflow-signature.js"></script>'
            .'<script src="'.$asset.'/workflow-form.js"></script>'
            .'</body></html>';
    }

    /**
     * (Re-)creates the synthetic demo workflow from the overview. Idempotent: an
     * existing demo is replaced, so the button always yields a clean demo.
     */
    #[Route('/install-demo', name: 'workflow_install_demo', methods: ['GET'])]
    public function installDemo(Request $request): Response
    {
        $this->framework->initialize();
        $this->assertToken($request);
        $this->assertAccess();

        try {
            $workflow = $this->demoSeeder->seed();
            Message::addConfirmation(sprintf(
                'Demo-Workflow „%s" wurde mit synthetischen Daten angelegt.',
                StringUtil::specialchars((string) $workflow->title),
            ));
        } catch (\Throwable $e) {
            Message::addError('Demo-Workflow konnte nicht angelegt werden: '.$e->getMessage());
        }

        return $this->backToDashboard();
    }

    /**
     * Downloads a workflow's configuration as a portable JSON document (without the
     * source file, form page or logo). Can be re-imported on this or another site.
     */
    #[Route('/export-config/{id}', name: 'workflow_export_config', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function exportConfig(int $id, Request $request): Response
    {
        $this->framework->initialize();
        $this->assertToken($request);
        $this->assertAccess();
        $workflow = $this->getWorkflow($id);

        $response = new Response($this->configExporter->exportJson($workflow));
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->configFilename($workflow)),
        );

        return $response;
    }

    /**
     * Imports a workflow configuration from an uploaded JSON file (the export format),
     * optionally creating the letterhead and the e-mail templates. The new workflow has
     * no source file, so it is "not runnable" until the user attaches one (intended).
     */
    #[Route('/import-config', name: 'workflow_import_config', methods: ['POST'])]
    public function importConfig(Request $request): Response
    {
        $this->framework->initialize();
        $this->assertToken($request);
        $this->assertAccess();

        $createMaster = (bool) $request->request->get('createMaster');
        $createNotifications = (bool) $request->request->get('createNotifications');

        try {
            $config = $this->resolveImportConfig($request);
            $result = $this->configImporter->materialize($config, $createMaster, $createNotifications);
            $workflow = $result['workflow'];

            Message::addConfirmation(sprintf(
                'Workflow „%s" wurde aus der Konfigurationsdatei erstellt. Er ist noch nicht ausführbar – bitte eine passende Quelldatei zuordnen%s.',
                StringUtil::specialchars((string) $workflow->title),
                $createNotifications ? ' und die Absenderadresse der E-Mail-Vorlagen anpassen' : '',
            ));

            $this->reportSkipped($result['skippedMaster'], $result['skippedNotifications']);
            $this->reportImportIssues($result['importIssues'] ?? []);
        } catch (\Throwable $e) {
            Message::addError('Import der Konfiguration fehlgeschlagen: '.$e->getMessage());
        }

        return $this->backToDashboard();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveImportConfig(Request $request): array
    {
        $file = $request->files->get('configFile');

        if (!$file instanceof UploadedFile || !$file->isValid() || $file->getSize() <= 0) {
            throw new \RuntimeException('Bitte eine JSON-Konfigurationsdatei hochladen.');
        }

        $config = json_decode((string) file_get_contents($file->getPathname()), true);

        if (!\is_array($config)) {
            throw new \RuntimeException('Die hochgeladene Datei ist kein gültiges JSON.');
        }

        return $config;
    }

    /**
     * Reports the letterhead/e-mail templates that were skipped during import because a
     * record with the same name already exists. Nothing is overwritten, so the user has
     * to rename the existing element or change the name in the JSON file and re-import.
     *
     * @param array<int, string> $skippedNotifications
     */
    private function reportSkipped(?string $skippedMaster, array $skippedNotifications): void
    {
        if (null !== $skippedMaster) {
            Message::addInfo(sprintf(
                'Briefpapier „%s" wurde nicht angelegt, da bereits ein Briefpapier mit diesem Namen '
                .'existiert. Der Workflow wurde ohne Briefpapier importiert – benenne das vorhandene '
                .'Briefpapier um oder ändere den Namen in der JSON-Datei und importiere erneut.',
                StringUtil::specialchars($skippedMaster),
            ));
        }

        if ([] !== $skippedNotifications) {
            $titles = implode('", „', array_map([StringUtil::class, 'specialchars'], $skippedNotifications));

            Message::addInfo(sprintf(
                'Folgende E-Mail-Vorlage(n) wurden nicht angelegt, da bereits Vorlagen mit diesen Namen '
                .'existieren: „%s". Benenne die vorhandenen Vorlagen um oder ändere die Namen in der '
                .'JSON-Datei und importiere erneut.',
                $titles,
            ));
        }
    }

    /**
     * Reports the site-specific references (form page, letterhead, notifications) that
     * could not be linked on this installation, because their id/name do not match an
     * existing element (e.g. importing onto a different site, or after the target
     * elements were deleted). Those fields are outlined red in the workflow edit mask.
     *
     * @param array<int, string> $importIssues field names among formPage/master/nc*
     */
    private function reportImportIssues(array $importIssues): void
    {
        if ([] === $importIssues) {
            return;
        }

        $labels = [
            'formPage'   => 'Formularseite',
            'master'     => 'Briefpapier',
            'ncInvite'   => 'Einladungs-Benachrichtigung',
            'ncReminder' => 'Erinnerungs-Benachrichtigung',
            'ncResult'   => 'Ergebnis-Benachrichtigung',
        ];

        $names = array_map(static fn (string $f): string => $labels[$f] ?? $f, $importIssues);

        Message::addInfo(sprintf(
            'Folgende Einstellungen konnten nicht automatisch verknüpft werden und sind im Workflow '
            .'rot markiert: %s. Verknüpfungen werden nur übernommen, wenn ID und Name des Elements auf '
            .'dieser Installation übereinstimmen. Bitte die betroffenen Felder im Workflow prüfen und zuordnen.',
            implode(', ', $names),
        ));
    }

    private function configFilename(WorkflowModel $workflow): string
    {
        $base = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $workflow->title), '-');

        return ('' !== $base ? $base : 'workflow-'.$workflow->id).'.json';
    }

    /**
     * Unified mail action from the dashboard dialog. "type" is invite|reminder;
     * "ids" (optional) restricts the recipients to the manually selected entries.
     * Without ids all entries of the matching status are addressed (automatic).
     */
    #[Route('/send/{id}', name: 'workflow_send', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function send(int $id, Request $request): Response
    {
        $this->framework->initialize();
        $this->assertToken($request);
        $this->assertAccess();
        $workflow = $this->getWorkflow($id);

        if ($redirect = $this->assertRunnable($workflow)) {
            return $redirect;
        }

        $reminder = 'reminder' === (string) $request->request->get('type');
        $ids = array_values(array_filter(array_map('intval', (array) $request->request->all('ids'))));

        try {
            $sent = $reminder
                ? $this->workflowMailer->sendReminders($workflow, $ids ?: null)
                : $this->workflowMailer->sendInvitations($workflow, $ids ?: null);

            Message::addConfirmation(sprintf(
                $reminder
                    ? '%d Erinnerungen wurden zum Versand eingereiht. Der Status wird erst nach dem tatsächlichen Versand aktualisiert; fehlgeschlagene Zustellungen werden hier als „Versandfehler" angezeigt.'
                    : '%d Einladungen wurden zum Versand eingereiht. Der Status wechselt erst nach dem tatsächlichen Versand auf „eingeladen"; fehlgeschlagene Zustellungen werden hier als „Versandfehler" angezeigt.',
                $sent,
            ));
        } catch (\Throwable $e) {
            Message::addError('Versand fehlgeschlagen: '.$e->getMessage());
        }

        return $this->backToDashboard();
    }

    #[Route('/export/{id}', name: 'workflow_export', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function export(int $id, Request $request): Response
    {
        $this->framework->initialize();
        $this->assertToken($request);
        $this->assertAccess();
        $workflow = $this->getWorkflow($id);

        $format = 'csv' === $request->query->get('format') ? 'csv' : 'xlsx';
        $result = $this->exporter->export($workflow, $format);

        $response = new Response($result['content']);
        $response->headers->set('Content-Type', $result['contentType']);
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $result['filename']),
        );

        return $response;
    }

    #[Route('/pdfs/{id}', name: 'workflow_download_pdfs', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function downloadPdfs(int $id, Request $request): Response
    {
        $this->framework->initialize();
        $this->assertToken($request);
        $this->assertAccess();
        $workflow = $this->getWorkflow($id);

        $dir = $this->pdfStorage->getWorkflowDir((int) $workflow->id);
        $files = is_dir($dir) ? glob($dir.'/*.pdf') : [];

        if (!$files) {
            Message::addInfo('Es sind noch keine PDF-Dokumente vorhanden.');

            return $this->backToDashboard();
        }

        $zipPath = (string) tempnam(sys_get_temp_dir(), 'tw_pdfs_');
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($files as $file) {
            $zip->addFile($file, basename($file));
        }

        $zip->close();

        $response = new BinaryFileResponse($zipPath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('workflow_pdfs_%d.zip', $workflow->id),
        );
        $response->deleteFileAfterSend(true);

        return $response;
    }

    private function getWorkflow(int $id): WorkflowModel
    {
        $workflow = WorkflowModel::findByPk($id);

        if (null === $workflow) {
            throw new AccessDeniedException('Unknown workflow: '.$id);
        }

        return $workflow;
    }

    private function assertToken(Request $request): void
    {
        $rt = (string) ($request->request->get('REQUEST_TOKEN') ?: $request->query->get('rt'));

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken($this->csrfTokenName, $rt))) {
            throw new AccessDeniedException('Invalid request token.');
        }
    }

    /**
     * These custom routes sit behind the backend firewall (authentication) but,
     * unlike Contao's do=… modules, are NOT gated by module permissions. Require
     * access to a workflow back end module (the overview, or – for the config export
     * triggered from the workflow list – the manage module) so a low-privilege back end
     * user cannot import/send or download another workflow's data. Admins always pass.
     */
    private function assertAccess(): void
    {
        foreach (['workflow_overview', 'workflow_manage'] as $module) {
            if ($this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, $module)) {
                return;
            }
        }

        throw new AccessDeniedException('Access to a workflow back end module is required.');
    }

    private function backToDashboard(): RedirectResponse
    {
        return new RedirectResponse(
            $this->router->generate('contao_backend', ['do' => 'workflow_overview']),
        );
    }
}
