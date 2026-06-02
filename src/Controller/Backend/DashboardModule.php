<?php

declare(strict_types=1);

namespace Psimandl\TrainerWorkflowBundle\Controller\Backend;

use Contao\BackendModule;
use Contao\System;
use Psimandl\TrainerWorkflowBundle\Model\EntryModel;
use Psimandl\TrainerWorkflowBundle\Model\WorkflowModel;
use Psimandl\TrainerWorkflowBundle\Service\WorkflowStatus;

/**
 * Back end overview module: per workflow it shows the number of received
 * answers (final step) and open answers, a per-step breakdown and a compact
 * list of people with a pending answer, plus action buttons.
 */
class DashboardModule extends BackendModule
{
    protected $strTemplate = 'be_trainer_dashboard';

    protected function compile(): void
    {
        $container = System::getContainer();

        /** @var WorkflowStatus $status */
        $status = $container->get(WorkflowStatus::class);
        $router = $container->get('router');
        $csrf = $container->get('contao.csrf.token_manager');
        $rt = $csrf->getDefaultTokenValue();

        $workflows = WorkflowModel::findAll(['order' => 'title']);
        $data = [];

        if (null !== $workflows) {
            foreach ($workflows as $workflow) {
                $id = (int) $workflow->id;

                $pending = [];
                $pendingEntries = EntryModel::findBy(
                    ['pid=?', 'status<?'],
                    [$id, $workflow->getFinalStatus()],
                    ['order' => 'email'],
                );

                if (null !== $pendingEntries) {
                    foreach ($pendingEntries as $entry) {
                        $pending[] = [
                            'email'  => $entry->email,
                            'status' => $status->getStepLabel($workflow, (int) $entry->status),
                        ];
                    }
                }

                $base = static fn (string $route): string => $router->generate($route, ['id' => $id]).'?rt='.$rt;

                $data[] = [
                    'id'        => $id,
                    'title'     => $workflow->title,
                    'published' => (bool) $workflow->published,
                    'completed' => $status->countCompleted($workflow),
                    'open'      => $status->countOpen($workflow),
                    'total'     => $status->countTotal($id),
                    'breakdown' => $status->getBreakdown($workflow),
                    'pending'   => $pending,
                    'urls'      => [
                        'import'      => $base('trainer_import'),
                        'invitations' => $base('trainer_send_invitations'),
                        'reminders'   => $base('trainer_send_reminders'),
                        'exportXlsx'  => $base('trainer_export'),
                        'exportCsv'   => $base('trainer_export').'&format=csv',
                        'pdfs'        => $base('trainer_download_pdfs'),
                    ],
                ];
            }
        }

        $this->Template->workflows = $data;
        $this->Template->hasWorkflows = [] !== $data;
    }
}
