<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Controller\Backend;

use Contao\BackendModule;
use Contao\Message;
use Contao\System;
use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\PersonNameResolver;
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

    // Candidate source-column names for the pending list, in priority order (the
    // first/last-name aliases live in PersonNameResolver, shared with the PDF
    // signature line). Compared normalized (lower-cased, all non-alphanumerics
    // stripped), so "E-Mail-Adresse", "department" etc. all match.
    private const EMAIL_ALIASES = ['email', 'emailadresse', 'emailaddress', 'mail', 'mailadresse', 'mailaddress', 'epost'];
    private const DEPARTMENT_ALIASES = ['abteilung', 'department', 'bereich', 'sparte', 'ressort', 'sektion'];

    protected function compile(): void
    {
        $container = System::getContainer();

        $GLOBALS['TL_CSS']['workflow_backend'] = 'bundles/contaoworkflow/workflow-backend.css';
        $GLOBALS['TL_JAVASCRIPT']['workflow_backend'] = 'bundles/contaoworkflow/workflow-backend.js|static';

        System::loadLanguageFile('workflow_messages');

        /** @var WorkflowStatus $status */
        $status = $container->get(WorkflowStatus::class);
        /** @var WorkflowValidator $validator */
        $validator = $container->get(WorkflowValidator::class);
        /** @var PersonNameResolver $nameResolver */
        $nameResolver = $container->get(PersonNameResolver::class);
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

                $pending = $this->buildPending($workflow, $status, $nameResolver, $hasName, $hasVorname, $hasAbteilung);

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
                $sendBlockers = [] === $problems ? $validator->getSendBlockers($workflow) : [];

                $base = static fn (string $route): string => $router->generate($route, ['id' => $id]).'?rt='.$rt;

                $data[] = [
                    'id'            => $id,
                    'title'         => $workflow->title,
                    'published'     => (bool) $workflow->published,
                    'runnable'      => [] === $problems,
                    'problems'      => $problems,
                    'canSend'       => [] === $problems && [] === $sendBlockers,
                    'sendBlockers'  => $sendBlockers,
                    'completed'     => $status->countCompleted($workflow),
                    'open'          => $status->countOpen($workflow),
                    'total'         => $status->countTotal($id),
                    'breakdown'     => $status->getBreakdown($workflow),
                    'pending'       => $pending,
                    'hasName'       => $hasName,
                    'hasVorname'    => $hasVorname,
                    'hasAbteilung'  => $hasAbteilung,
                    'selectSteps'   => $selectSteps,
                    'inviteCount'   => $byStatus[WorkflowStatus::STATUS_IMPORTED] ?? 0,
                    'reminderCount' => $byStatus[WorkflowStatus::STATUS_INVITED] ?? 0,
                    'sendUrl'       => $router->generate('workflow_send', ['id' => $id]),
                    'finalStatus'   => $final,
                    'rt'            => $rt,
                    'urls'          => [
                        // Direct link into the workflow_manage edit view for this workflow.
                        'manage'     => $router->generate('contao_backend', ['do' => 'workflow_manage', 'act' => 'edit', 'id' => $id, 'rt' => $rt]),
                        'import'     => $base('workflow_import'),
                        'exportXlsx' => $base('workflow_export'),
                        'exportCsv'  => $base('workflow_export').'&format=csv',
                        'pdfs'       => $base('workflow_download_pdfs'),
                    ],
                ];
            }
        }

        // Flash messages (e.g. import result / skipped-on-name-conflict notices set by
        // WorkflowActionController). Unlike DC-driven views, a custom back end module is
        // not wrapped with the message output, so render it here explicitly.
        $this->Template->messages = Message::generate();

        // Infrastructure alarm: mails that have been queued for a while without any result
        // (the cron/worker is most likely not running). This spans all workflows.
        $this->Template->stuckQueue = $status->countStuckQueued();

        $this->Template->workflows = $data;
        $this->Template->hasWorkflows = [] !== $data;
        $this->Template->dash = $GLOBALS['TL_LANG']['workflow_dashboard'];
        $this->Template->restoreDemoUrl = $router->generate('workflow_install_demo').'?rt='.$rt;
        $this->Template->importUrl = $router->generate('workflow_import_config');
        $this->Template->rt = $rt;
    }

    /**
     * @return array<int, array{id: int, email: string, statusIndex: int, status: string, name: string, vorname: string, abteilung: string}>
     */
    private function buildPending(WorkflowModel $workflow, WorkflowStatus $status, PersonNameResolver $nameResolver, ?bool &$hasName, ?bool &$hasVorname, ?bool &$hasAbteilung): array
    {
        $hasName = false;
        $hasVorname = false;
        $hasAbteilung = false;
        $pending = [];

        // "Offene Vorgänge": everything not fully finished. An entry drops out only once it
        // is answered AND its confirmation was produced (resultDoneAt > 0) AND there is no
        // send error / hard bounce. A not-yet-answered entry has resultDoneAt = 0 and is
        // therefore included as well (the classic pending case).
        $entries = EntryModel::findBy(
            ['pid=?', "(resultDoneAt=0 OR (sendError IS NOT NULL AND sendError!='') OR bounceHard!='')"],
            [(int) $workflow->id],
            ['order' => 'status, email'],
        );

        if (null === $entries) {
            return [];
        }

        // The source columns are identical for all entries of a workflow, so resolve the
        // first-name / last-name / e-mail columns once from the first row's keys.
        $firstNameKey = null;
        $lastNameKey = null;
        $emailKey = null;
        $departmentKey = null;
        $keysResolved = false;

        foreach ($entries as $entry) {
            $row = $entry->getData();

            if (!$keysResolved) {
                $keys = array_keys($row);
                $firstNameKey = $nameResolver->detectColumn($keys, PersonNameResolver::FIRST_NAME_ALIASES);
                $lastNameKey = $nameResolver->detectColumn($keys, PersonNameResolver::LAST_NAME_ALIASES);
                $emailKey = $nameResolver->detectColumn($keys, self::EMAIL_ALIASES);
                $departmentKey = $nameResolver->detectColumn($keys, self::DEPARTMENT_ALIASES);
                $keysResolved = true;
            }

            $vorname = null !== $firstNameKey ? (string) ($row[$firstNameKey] ?? '') : '';
            $name = null !== $lastNameKey ? (string) ($row[$lastNameKey] ?? '') : '';
            $abteilung = null !== $departmentKey ? (string) ($row[$departmentKey] ?? '') : '';
            // The configured e-mail field is canonical; fall back to a detected column.
            $email = (string) $entry->email;

            if ('' === $email && null !== $emailKey) {
                $email = (string) ($row[$emailKey] ?? '');
            }

            $hasName = $hasName || '' !== $name;
            $hasVorname = $hasVorname || '' !== $vorname;
            $hasAbteilung = $hasAbteilung || '' !== $abteilung;

            $sendError = (string) $entry->sendError;

            // Delivery state shown in its own "Zustellung" column, in addition to (never
            // replacing) the workflow status. Empty only while no mail has been attempted;
            // otherwise always something. Precedence: a hard bounce (permanent) beats a
            // retryable transport error, which beats a plain "sent, no error so far".
            // "sent" is derived from the status: it only advances past "imported" once an
            // invitation was actually sent.
            $delivery = '';

            if ('1' === (string) $entry->bounceHard) {
                $delivery = 'bounce';
            } elseif ('' !== $sendError) {
                $delivery = 'error';
            } elseif ((int) $entry->respondedAt > 0 && 0 === (int) $entry->resultDoneAt) {
                // Answered, but the confirmation (PDF + result mail) is not through yet.
                $delivery = 'pending';
            } elseif ((int) $entry->status >= WorkflowStatus::STATUS_INVITED) {
                $delivery = 'sent';
            }

            $pending[] = [
                'id'          => (int) $entry->id,
                'email'       => $email,
                'statusIndex' => (int) $entry->status,
                'status'      => $status->getStepLabel($workflow, (int) $entry->status),
                'name'        => $name,
                'vorname'     => $vorname,
                'abteilung'   => $abteilung,
                'delivery'    => $delivery,
                'sendError'   => $sendError,
                'bounceInfo'  => (string) $entry->bounceInfo,
                'resultError' => (string) $entry->resultError,
            ];
        }

        return $pending;
    }
}
