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
use Doctrine\DBAL\Connection;
use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\SubmissionProcessor;
use Psimandl\WorkflowBundle\Service\DemoWorkflowSeeder;
use Psimandl\WorkflowBundle\Service\DocumentBodyComposer;
use Psimandl\WorkflowBundle\Service\PdfGenerator;
use Psimandl\WorkflowBundle\Service\PdfStorage;
use Psimandl\WorkflowBundle\Service\PlaceholderResolver;
use Psimandl\WorkflowBundle\Service\Slugger;
use Psimandl\WorkflowBundle\Service\SpreadsheetExporter;
use Psimandl\WorkflowBundle\Service\SpreadsheetImporter;
use Psimandl\WorkflowBundle\Service\WorkflowConfigExporter;
use Psimandl\WorkflowBundle\Service\WorkflowConfigImporter;
use Psimandl\WorkflowBundle\Service\WorkflowFormView;
use Psimandl\WorkflowBundle\Service\WorkflowMailer;
use Psimandl\WorkflowBundle\Service\WorkflowPreviewData;
use Psimandl\WorkflowBundle\Service\WorkflowStatus;
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
        private readonly PlaceholderResolver $placeholderResolver,
        private readonly Slugger $slugger,
        private readonly RouterInterface $router,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly Security $security,
        private readonly SubmissionProcessor $submissionProcessor,
        private readonly Connection $connection,
        private readonly string $csrfTokenName,
    ) {
    }

    /**
     * Blocks execution of a not-runnable workflow (e.g. a fresh copy without a
     * source file): adds the concrete problems as an error and redirects back to
     * $backTo (the overview by default; the edit mask when the action was triggered there).
     */
    private function assertRunnable(WorkflowModel $workflow, ?RedirectResponse $backTo = null): ?RedirectResponse
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

        return $backTo ?? $this->backToDashboard();
    }

    /**
     * Blocks a mail run whose prerequisites are missing (no form page, no notification, not
     * published). The overview already hides the send dialog in that case, but that is a UI
     * gate only — the route has to refuse it as well, or a stale tab still sends.
     */
    private function assertSendable(WorkflowModel $workflow): ?RedirectResponse
    {
        $blockers = $this->validator->getSendBlockers($workflow);

        if ([] === $blockers) {
            return null;
        }

        Message::addError(sprintf(
            'Workflow „%s": Versand nicht möglich – %s',
            StringUtil::specialchars((string) $workflow->title),
            StringUtil::specialchars(implode('; ', $blockers)),
        ));

        return $this->backToDashboard();
    }

    /**
     * Voids every recorded answer of a workflow, which is what releases the edit lock on the
     * source settings (see WorkflowLock). GET like the other edit-mask actions – a nested
     * <form> is not possible inside the DCA form – but guarded by the request token, the
     * module check and a confirm dialog in the button.
     *
     * Deliberately keeps the answer data, the tokens and the stored PDFs: the participants
     * fill in the same prefilled form again through their existing link, and the document is
     * overwritten on re-submission. Only the bookkeeping that marks them as answered goes.
     */
    #[Route('/reset-entries/{id}', name: 'workflow_reset_entries', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function resetEntries(int $id, Request $request): Response
    {
        $this->framework->initialize();
        $this->assertToken($request);
        $this->assertAccess();
        $workflow = $this->getWorkflow($id);

        $affected = (int) $this->connection->executeStatement(
            'UPDATE tl_workflow_entry '
            ."SET status = ?, respondedAt = 0, resultDoneAt = 0, resultError = '', tstamp = ? "
            .'WHERE pid = ? AND respondedAt > 0',
            [WorkflowStatus::STATUS_IMPORTED, time(), $id],
        );

        Message::addConfirmation(sprintf(
            '%d Teilnehmer von „%s" zurückgesetzt. Die Quell-Einstellungen sind wieder änderbar; '
            .'bitte anschließend den Import erneut ausführen, um die Originaldaten zu laden.',
            $affected,
            StringUtil::specialchars((string) $workflow->title),
        ));

        return $this->backToEdit($id);
    }

    #[Route('/import/{id}', name: 'workflow_import', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function import(int $id, Request $request): Response
    {
        $this->framework->initialize();
        $this->assertToken($request);
        $this->assertAccess();
        $workflow = $this->getWorkflow($id);

        // Import can be triggered from the workflow's edit mask (the "re-import needed" hint);
        // "return=edit" sends the user back there instead of to the overview.
        $returnToEdit = 'edit' === (string) $request->query->get('return');
        $backTo = $returnToEdit ? $this->backToEdit($id) : $this->backToDashboard();

        if ($redirect = $this->assertRunnable($workflow, $backTo)) {
            return $redirect;
        }

        try {
            $result = $this->importer->import($workflow);

            Message::addConfirmation(sprintf(
                'Import: %d neu hinzugefügt, %d aktualisiert%s (gesamt %d).',
                $result['inserted'],
                $result['updated'],
                $result['protected'] > 0
                    ? sprintf(', %d unverändert (bereits beantwortet)', $result['protected'])
                    : '',
                $result['total'],
            ));

            // A number column the import could not adopt the format of: the field keeps its
            // previous format, which is a silent trap if nobody says so.
            if ([] !== $result['formatProblems']) {
                // ENT_NOQUOTES: message body, not an attribute – escaping the quotes would
                // print „Spalte &quot;Name&quot;" at the user.
                Message::addError(sprintf(
                    'Zahlenformat nicht übernommen – die betroffenen Felder rechnen weiter mit ihrem '
                    .'bisherigen Format: %s',
                    htmlspecialchars(implode(' | ', $result['formatProblems']), ENT_NOQUOTES),
                ));
            }

            // The edit mask states the very same thing on load, one message per collision
            // group (WorkflowIntegrityListener::warnSlugCollisions) – saying it here as well
            // would duplicate it verbatim. Coming from the overview that listener never runs,
            // so there this is the only place the user would ever learn about it.
            if (!$returnToEdit) {
                $this->reportSlugCollisions($result['collisions']);
            }
        } catch (\Throwable $e) {
            Message::addError('Import fehlgeschlagen: '.$e->getMessage());
        }

        return $backTo;
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
                'intro'            => $this->bodyComposer->resolveIntroHtml($workflow, $data, $extra, $email),
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
            // Before workflow-form.js: the live hint calls into WorkflowNumber.
            .'<script src="'.$asset.'/workflow-number.js"></script>'
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

        [$name, $fallback] = $this->configFilename($workflow);

        $response = new Response($this->configExporter->exportJson($workflow));
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name, $fallback),
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

    /**
     * @return array{0: string, 1: string} [unicode name, ascii fallback]
     */
    private function configFilename(WorkflowModel $workflow): array
    {
        return $this->downloadName((string) $workflow->title, '.json', 'workflow-'.$workflow->id);
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

        $type = (string) $request->request->get('type');
        $ids = array_values(array_filter(array_map('intval', (array) $request->request->all('ids'))));

        // Confirmation (result mail + PDF) follows the same status logic as invitation and
        // reminder, but can only go to participants who have reached the final status — before
        // that there is no answer data to build the PDF from.
        if ('confirmation' === $type) {
            return $this->sendConfirmations($workflow, $ids);
        }

        // Only invitations and reminders carry the form link, so only they depend on the form
        // page, the invite/reminder notification and the workflow being published. A
        // confirmation just ships the finished PDF and is checked above.
        if ($redirect = $this->assertSendable($workflow)) {
            return $redirect;
        }

        $reminder = 'reminder' === $type;

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

    /**
     * (Re-)generates the PDF and sends the result mail for the given answered entries. Entries
     * that have not reached the final status are skipped — a confirmation cannot be produced
     * without the submitted data. Idempotent via SubmissionProcessor, so it doubles as the
     * re-send after a bounce or a failed confirmation.
     *
     * @param array<int, int> $ids
     */
    private function sendConfirmations(WorkflowModel $workflow, array $ids): Response
    {
        if ([] === $ids) {
            Message::addInfo('Es wurden keine Empfänger für die Bestätigung ausgewählt.');

            return $this->backToDashboard();
        }

        $done = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($ids as $entryId) {
            $entry = EntryModel::findByPk($entryId);

            if (null === $entry || (int) $entry->pid !== (int) $workflow->id) {
                continue;
            }

            // No answer yet → no data for the PDF → cannot send a confirmation.
            if ((int) $entry->respondedAt <= 0) {
                ++$skipped;

                continue;
            }

            if ($this->submissionProcessor->produceConfirmation($workflow, $entry)) {
                ++$done;
            } else {
                ++$failed;
            }
        }

        Message::addConfirmation(sprintf(
            'Bestätigung: %d versendet, %d fehlgeschlagen%s.',
            $done,
            $failed,
            $skipped > 0 ? sprintf(', %d übersprungen (noch nicht beantwortet)', $skipped) : '',
        ));

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
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $result['filename'], $result['filenameFallback']),
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

        [$name, $fallback] = $this->pdfBundleName($workflow, \count($files));

        $response = new BinaryFileResponse($zipPath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name, $fallback);
        $response->deleteFileAfterSend(true);

        return $response;
    }

    /**
     * File name of the downloaded PDF bundle, e.g.
     * "EStG Übungsleiter_20260717_150644_24-PDFs.zip" – with the ASCII fallback
     * "EStG_Uebungsleiter_…" for the RFC 5987 header.
     *
     * The old "workflow_pdfs_<id>.zip" said nothing once a few of them sat in a download
     * folder: which workflow, from when, and how many documents. Same shape as the
     * spreadsheet export (name, then a sortable timestamp), so bundles of one workflow sort
     * chronologically; the count is appended because it is the one thing a downloader checks
     * first.
     *
     * @return array{0: string, 1: string} [unicode name, ascii fallback]
     */
    private function pdfBundleName(WorkflowModel $workflow, int $count): array
    {
        $suffix = sprintf('_%s_%d-%s.zip', date('Ymd_His'), $count, 1 === $count ? 'PDF' : 'PDFs');

        return $this->downloadName((string) $workflow->title, $suffix, 'Workflow');
    }

    /**
     * Two spellings of a download file name: the Unicode name (title characters kept – umlauts,
     * any script) for the RFC 5987 filename* header, and an ASCII transliteration as the
     * fallback for old clients. Neither drops a character, and neither can be empty
     * ($fallbackBase covers a title that reduces to nothing, e.g. only punctuation).
     *
     * @return array{0: string, 1: string} [unicode name, ascii fallback]
     */
    private function downloadName(string $base, string $suffix, string $fallbackBase): array
    {
        return [
            ($this->slugger->unicode($base) ?: $fallbackBase).$suffix,
            ($this->slugger->ascii($base) ?: $fallbackBase).$suffix,
        ];
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

    private function backToEdit(int $id): RedirectResponse
    {
        return new RedirectResponse($this->router->generate('contao_backend', [
            'do'  => 'workflow_manage',
            'act' => 'edit',
            'id'  => $id,
            'rt'  => $this->csrfTokenManager->getDefaultTokenValue(),
        ]));
    }
}
