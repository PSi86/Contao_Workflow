<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Controller\Backend;

use Contao\BackendModule;
use Contao\System;
use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\WorkflowStatus;
use Psimandl\WorkflowBundle\Service\WorkflowValidator;

/**
 * Back end overview module: per workflow it shows the received/open counts, a
 * per-step breakdown and the list of pending participants (sortable, selectable),
 * plus the action buttons (import, e-mail dialog, export, PDFs). Workflows that
 * are not runnable are flagged and their actions disabled.
 */
class DashboardModule extends BackendModule
{
    protected $strTemplate = 'be_workflow_dashboard';

    protected function compile(): void
    {
        $container = System::getContainer();

        $GLOBALS['TL_CSS']['workflow_backend'] = 'bundles/contaoworkflow/workflow-backend.css';
        $GLOBALS['TL_JAVASCRIPT']['workflow_backend'] = 'bundles/contaoworkflow/workflow-backend.js|static';

        /** @var WorkflowStatus $status */
        $status = $container->get(WorkflowStatus::class);
        /** @var WorkflowValidator $validator */
        $validator = $container->get(WorkflowValidator::class);
        $router = $container->get('router');
        $csrf = $container->get('contao.csrf.token_manager');
        $rt = $csrf->getDefaultTokenValue();

        $workflows = WorkflowModel::findAll(['order' => 'tstamp DESC']);
        $data = [];

        if (null !== $workflows) {
            foreach ($workflows as $workflow) {
                $id = (int) $workflow->id;
                $final = $workflow->getFinalStatus();
                $steps = $workflow->getSteps();
                $byStatus = $status->countByStatus($id);

                $pending = $this->buildPending($workflow, $status, $hasName, $hasVorname);

                // Per-step select buttons: every step except the final one.
                $selectSteps = [];
                for ($i = 0; $i < $final; ++$i) {
                    $selectSteps[] = [
                        'index' => $i,
                        'label' => $steps[$i] ?? (string) $i,
                        'count' => $byStatus[$i] ?? 0,
                    ];
                }

                $problems = $validator->getProblems($workflow);

                $base = static fn (string $route): string => $router->generate($route, ['id' => $id]).'?rt='.$rt;

                $data[] = [
                    'id'            => $id,
                    'title'         => $workflow->title,
                    'published'     => (bool) $workflow->published,
                    'runnable'      => [] === $problems,
                    'problems'      => $problems,
                    'completed'     => $status->countCompleted($workflow),
                    'open'          => $status->countOpen($workflow),
                    'total'         => $status->countTotal($id),
                    'breakdown'     => $status->getBreakdown($workflow),
                    'pending'       => $pending,
                    'hasName'       => $hasName,
                    'hasVorname'    => $hasVorname,
                    'selectSteps'   => $selectSteps,
                    'inviteCount'   => $byStatus[WorkflowStatus::STATUS_IMPORTED] ?? 0,
                    'reminderCount' => $byStatus[WorkflowStatus::STATUS_INVITED] ?? 0,
                    'sendUrl'       => $router->generate('workflow_send', ['id' => $id]),
                    'rt'            => $rt,
                    'urls'          => [
                        'import'     => $base('workflow_import'),
                        'exportXlsx' => $base('workflow_export'),
                        'exportCsv'  => $base('workflow_export').'&format=csv',
                        'pdfs'       => $base('workflow_download_pdfs'),
                    ],
                ];
            }
        }

        $this->Template->workflows = $data;
        $this->Template->hasWorkflows = [] !== $data;
    }

    /**
     * @return array<int, array{id: int, email: string, statusIndex: int, status: string, name: string, vorname: string}>
     */
    private function buildPending(WorkflowModel $workflow, WorkflowStatus $status, ?bool &$hasName, ?bool &$hasVorname): array
    {
        $hasName = false;
        $hasVorname = false;
        $pending = [];

        $entries = EntryModel::findBy(
            ['pid=?', 'status<?'],
            [(int) $workflow->id, $workflow->getFinalStatus()],
            ['order' => 'status, email'],
        );

        if (null === $entries) {
            return [];
        }

        foreach ($entries as $entry) {
            $row = $entry->getData();
            $name = (string) ($row['Name'] ?? '');
            $vorname = (string) ($row['Vorname'] ?? '');

            $hasName = $hasName || '' !== $name;
            $hasVorname = $hasVorname || '' !== $vorname;

            $pending[] = [
                'id'          => (int) $entry->id,
                'email'       => (string) $entry->email,
                'statusIndex' => (int) $entry->status,
                'status'      => $status->getStepLabel($workflow, (int) $entry->status),
                'name'        => $name,
                'vorname'     => $vorname,
            ];
        }

        return $pending;
    }
}
