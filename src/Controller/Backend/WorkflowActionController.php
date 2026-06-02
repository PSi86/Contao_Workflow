<?php

declare(strict_types=1);

namespace Psimandl\TrainerWorkflowBundle\Controller\Backend;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Message;
use Psimandl\TrainerWorkflowBundle\Model\WorkflowModel;
use Psimandl\TrainerWorkflowBundle\Service\PdfStorage;
use Psimandl\TrainerWorkflowBundle\Service\SpreadsheetExporter;
use Psimandl\TrainerWorkflowBundle\Service\SpreadsheetImporter;
use Psimandl\TrainerWorkflowBundle\Service\WorkflowMailer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;

/**
 * Back end actions triggered from the trainer dashboard. State-changing actions
 * validate the Contao request token (rt) and redirect back with a message;
 * downloads stream a file.
 */
#[Route('/contao/trainer', defaults: ['_scope' => 'backend', '_token_check' => false])]
class WorkflowActionController
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly SpreadsheetImporter $importer,
        private readonly SpreadsheetExporter $exporter,
        private readonly WorkflowMailer $workflowMailer,
        private readonly PdfStorage $pdfStorage,
        private readonly RouterInterface $router,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly string $csrfTokenName,
    ) {
    }

    #[Route('/import/{id}', name: 'trainer_import', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function import(int $id, Request $request): Response
    {
        $this->framework->initialize();
        $this->assertToken($request);
        $workflow = $this->getWorkflow($id);

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

    #[Route('/invitations/{id}', name: 'trainer_send_invitations', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function sendInvitations(int $id, Request $request): Response
    {
        $this->framework->initialize();
        $this->assertToken($request);
        $workflow = $this->getWorkflow($id);

        try {
            $sent = $this->workflowMailer->sendInvitations($workflow);
            Message::addConfirmation(sprintf('%d Einladungen wurden versendet.', $sent));
        } catch (\Throwable $e) {
            Message::addError('Versand fehlgeschlagen: '.$e->getMessage());
        }

        return $this->backToDashboard();
    }

    #[Route('/reminders/{id}', name: 'trainer_send_reminders', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function sendReminders(int $id, Request $request): Response
    {
        $this->framework->initialize();
        $this->assertToken($request);
        $workflow = $this->getWorkflow($id);

        try {
            $sent = $this->workflowMailer->sendReminders($workflow);
            Message::addConfirmation(sprintf('%d Erinnerungen wurden versendet.', $sent));
        } catch (\Throwable $e) {
            Message::addError('Versand fehlgeschlagen: '.$e->getMessage());
        }

        return $this->backToDashboard();
    }

    #[Route('/export/{id}', name: 'trainer_export', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function export(int $id, Request $request): Response
    {
        $this->framework->initialize();
        $this->assertToken($request);
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

    #[Route('/pdfs/{id}', name: 'trainer_download_pdfs', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function downloadPdfs(int $id, Request $request): Response
    {
        $this->framework->initialize();
        $this->assertToken($request);
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
            sprintf('trainer_pdfs_%d.zip', $workflow->id),
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
        $rt = (string) $request->query->get('rt');

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken($this->csrfTokenName, $rt))) {
            throw new AccessDeniedException('Invalid request token.');
        }
    }

    private function backToDashboard(): RedirectResponse
    {
        return new RedirectResponse(
            $this->router->generate('contao_backend', ['do' => 'trainer_overview']),
        );
    }
}
