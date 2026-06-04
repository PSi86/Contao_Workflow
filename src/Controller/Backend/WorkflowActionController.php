<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Controller\Backend;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\Message;
use Contao\StringUtil;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\PdfStorage;
use Psimandl\WorkflowBundle\Service\SpreadsheetExporter;
use Psimandl\WorkflowBundle\Service\SpreadsheetImporter;
use Psimandl\WorkflowBundle\Service\WorkflowMailer;
use Psimandl\WorkflowBundle\Service\WorkflowValidator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
                $reminder ? '%d Erinnerungen wurden versendet.' : '%d Einladungen wurden versendet.',
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
     * access to the workflow overview module so a low-privilege back end user
     * cannot import/send or download another workflow's data. Admins always pass.
     */
    private function assertAccess(): void
    {
        if (!$this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'workflow_overview')) {
            throw new AccessDeniedException('Access to the workflow overview module is required.');
        }
    }

    private function backToDashboard(): RedirectResponse
    {
        return new RedirectResponse(
            $this->router->generate('contao_backend', ['do' => 'workflow_overview']),
        );
    }
}
