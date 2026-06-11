<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Controller\Backend;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\Message;
use Contao\StringUtil;
use Psimandl\WorkflowBundle\Model\QuestionModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\DemoWorkflowSeeder;
use Psimandl\WorkflowBundle\Service\PdfStorage;
use Psimandl\WorkflowBundle\Service\SpreadsheetExporter;
use Psimandl\WorkflowBundle\Service\SpreadsheetImporter;
use Psimandl\WorkflowBundle\Service\WorkflowConfigExporter;
use Psimandl\WorkflowBundle\Service\WorkflowConfigImporter;
use Psimandl\WorkflowBundle\Service\WorkflowMailer;
use Psimandl\WorkflowBundle\Service\WorkflowValidator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        } catch (\Throwable $e) {
            Message::addError('Import fehlgeschlagen: '.$e->getMessage());
        }

        return $this->backToDashboard();
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

    /**
     * Persists the new answer-field order coming from the drag&drop in the
     * embedded questions list of the workflow edit mask (see
     * AnswerConfigListener::renderQuestionsList). Expects POST ids[] in the new
     * order; only rows belonging to the workflow are renumbered.
     */
    #[Route('/question-sort/{id}', name: 'workflow_question_sort', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function questionSort(int $id, Request $request): Response
    {
        $this->framework->initialize();
        $this->assertToken($request);
        $this->assertAccess();
        $workflow = $this->getWorkflow($id);

        $sorting = 0;
        $updated = 0;

        foreach (array_map('intval', $request->request->all('ids')) as $questionId) {
            $question = QuestionModel::findByPk($questionId);

            if (null !== $question && (int) $question->pid === (int) $workflow->id) {
                $question->sorting = $sorting += 64;
                $question->tstamp = time();
                $question->save();
                ++$updated;
            }
        }

        return new JsonResponse(['updated' => $updated]);
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
