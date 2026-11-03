<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Controller\Backend;

use Contao\BackendModule;
use Contao\Message;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\Bounce\BounceHealth;
use Psimandl\WorkflowBundle\Service\Bounce\BounceSchedule;
use Psimandl\WorkflowBundle\Service\CronHealth;
use Psimandl\WorkflowBundle\Service\ImportLogRenderer;
use Psimandl\WorkflowBundle\Service\PdfStorage;
use Psimandl\WorkflowBundle\Service\PersonNameResolver;
// STILLGELEGT mit dem absoluten Importmodus (siehe unten, urls.importAbsolute):
// use Psimandl\WorkflowBundle\Service\SpreadsheetImporter;
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
        /** @var Connection $connection */
        $connection = $container->get('database_connection');
        /** @var PdfStorage $pdfStorage */
        $pdfStorage = $container->get(PdfStorage::class);
        /** @var ImportLogRenderer $importLogRenderer */
        $importLogRenderer = $container->get(ImportLogRenderer::class);
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
                // One query for every figure of this row (see WorkflowStatus::summary).
                $figures = $status->summary($workflow);
                $byStatus = $figures['byStatus'];

                $pending = $this->buildPending($workflow, $status, $nameResolver, $connection, $hasName, $hasVorname, $hasAbteilung);

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
                    // The stored data no longer matches the source file – never imported, or
                    // the file changed since. Entries and number formats are stale (form/PDF
                    // preview show the old data) until an import runs. Same rule as the edit
                    // mask (WorkflowIntegrityListener::flagStaleSource), so both agree.
                    'sourceDirty'   => [] === $problems && $validator->isSourceDirty($workflow),
                    'neverImported' => $validator->hasNeverImported($workflow),
                    'canSend'       => [] === $problems && [] === $sendBlockers,
                    'sendBlockers'  => $sendBlockers,
                    'completed'     => $figures['completed'],
                    'open'          => $figures['open'],
                    'total'         => $figures['total'],
                    'breakdown'     => $figures['breakdown'],
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
                        'import'         => $base('workflow_import'),
                        // STILLGELEGT: dieselbe Route im löschenden Modus – Einträge, deren
                        // Zeile ausgeblendet ist oder fehlt, wurden danach entfernt
                        // (SpreadsheetImporter::MODE_ABSOLUTE). Siehe den abgeschalteten
                        // Import-Dialog in be_workflow_dashboard.html5. Zum Reaktivieren
                        // einkommentieren (und den use-Import oben wieder aufnehmen):
                        // 'importAbsolute' => $base('workflow_import').'&mode='.SpreadsheetImporter::MODE_ABSOLUTE,
                        // Without the token: the download dialog is a GET form, whose fields
                        // replace the whole query string – the token travels as a field.
                        'download'       => $router->generate('workflow_download', ['id' => $id]),
                    ],
                    // Shown at the PDF checkbox, which is disabled while there are none.
                    'pdfCount'      => $pdfStorage->countWorkflowPdfs($id),
                    // The import log, rendered by the same service the edit mask uses.
                    'importLog'     => $importLogRenderer->render($id),
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

        // Bounce-detection state, spanning all workflows: a hint when no mailbox is configured,
        // an error when the configured one is unreachable (from the cron's last verdict).
        $this->Template->bounceBanner = $this->bounceBanner($container->get(BounceHealth::class));

        // Whether a real cron calls Contao, and when the bounce mailbox was/will be read.
        $this->Template->cronStatus = $this->cronStatus(
            $container->get(CronHealth::class),
            $container->get(BounceHealth::class),
            $container->get(BounceSchedule::class),
        );
        $this->Template->bounceCheckUrl = $router->generate('workflow_bounce_check').'?rt='.$rt;

        $this->Template->workflows = $data;
        $this->Template->hasWorkflows = [] !== $data;
        $this->Template->dash = $GLOBALS['TL_LANG']['workflow_dashboard'];
        $this->Template->restoreDemoUrl = $router->generate('workflow_install_demo').'?rt='.$rt;
        $this->Template->importUrl = $router->generate('workflow_import_config');
        $this->Template->rt = $rt;
    }

    /**
     * The bounce-detection banner for the overview, or null when nothing needs saying.
     *
     * Two distinct situations, deliberately different in tone:
     *  - no mailbox configured (determined live from the DSN, no cron run needed) → a NOTICE:
     *    the feature is simply off, so delivery failures go unnoticed.
     *  - a mailbox is configured but the last cron run could not reach it → an ERROR: something
     *    is misconfigured and needs fixing. The detail comes from the stored verdict, so the
     *    overview never opens an IMAP connection itself.
     *
     * @return array{level: string, message: string}|null
     */
    private function bounceBanner(BounceHealth $health): ?array
    {
        $lang = $GLOBALS['TL_LANG']['workflow_dashboard'] ?? [];

        if (!$health->isConfigured()) {
            return ['level' => 'notice', 'message' => (string) ($lang['bounce_unconfigured'] ?? '')];
        }

        $state = $health->read();

        if (BounceHealth::STATE_CONFIG_ERROR === $state['state']) {
            return [
                'level'   => 'error',
                'message' => sprintf(
                    (string) ($lang['bounce_error'] ?? '%s'),
                    $state['message'],
                    $state['checkedAt'] > 0 ? date('d.m.Y H:i', $state['checkedAt']) : '—',
                ),
            ];
        }

        return null;
    }

    /**
     * The status line about the background work: when a real cron last called Contao (server
     * cron job on /_contao/cron, or contao:cron), and when the bounce mailbox was last read and
     * will be read next. Everything comes from stored timestamps – nothing is guessed, and the
     * page opens no connection of its own.
     *
     * @return array{cronOk: bool, cron: string, bounce: string}
     */
    private function cronStatus(CronHealth $cronHealth, BounceHealth $bounceHealth, BounceSchedule $schedule): array
    {
        $lang = $GLOBALS['TL_LANG']['workflow_dashboard'] ?? [];
        $now = time();
        $cron = $cronHealth->read();
        $alive = $cronHealth->isAlive($now);

        if (0 === $cron['lastAt']) {
            $cronLine = (string) ($lang['cron_never'] ?? '');
        } else {
            $via = CronHealth::TRIGGER_CLI === $cron['trigger'] ? 'contao:cron' : '/_contao/cron';
            $cronLine = sprintf(
                (string) ($alive ? $lang['cron_last'] : $lang['cron_stale']),
                date('d.m.Y H:i', $cron['lastAt']),
                $this->ago($now - $cron['lastAt']),
                $via,
            );
        }

        $bounceLine = '';

        if ($bounceHealth->isConfigured()) {
            $checkedAt = $bounceHealth->read()['checkedAt'];
            $current = $schedule->current($now);

            $reason = match ($current['tier']) {
                BounceSchedule::TIER_RECENT => sprintf((string) $lang['bounce_tier_recent'], $this->ago($now - $current['lastMailAt'])),
                BounceSchedule::TIER_OUTSTANDING => sprintf((string) $lang['bounce_tier_outstanding'], $current['outstanding']),
                default => (string) $lang['bounce_tier_idle'],
            };

            $next = $checkedAt > 0 ? $checkedAt + $current['interval'] : $now;

            $bounceLine = sprintf(
                (string) $lang['bounce_status'],
                $checkedAt > 0 ? date('d.m.Y H:i', $checkedAt).' ('.$this->ago($now - $checkedAt).')' : (string) $lang['bounce_never'],
                $next <= $now ? (string) $lang['bounce_next_due'] : date('H:i', $next),
                $reason,
            );

            if (!$alive) {
                $bounceLine .= ' '.$lang['bounce_without_cron'];
            }
        }

        return ['cronOk' => $alive, 'cron' => $cronLine, 'bounce' => $bounceLine];
    }

    private function ago(int $seconds): string
    {
        $lang = $GLOBALS['TL_LANG']['workflow_dashboard'] ?? [];

        return match (true) {
            $seconds < 60 => (string) $lang['ago_now'],
            $seconds < 3600 => sprintf((string) $lang['ago_minutes'], intdiv($seconds, 60)),
            $seconds < 172800 => sprintf((string) $lang['ago_hours'], intdiv($seconds, 3600)),
            default => sprintf((string) $lang['ago_days'], intdiv($seconds, 86400)),
        };
    }

    /**
     * @return array<int, array{id: int, email: string, statusIndex: int, status: string, name: string, vorname: string, abteilung: string}>
     */
    private function buildPending(WorkflowModel $workflow, WorkflowStatus $status, PersonNameResolver $nameResolver, Connection $connection, ?bool &$hasName, ?bool &$hasVorname, ?bool &$hasAbteilung): array
    {
        $hasName = false;
        $hasVorname = false;
        $hasAbteilung = false;
        $pending = [];

        // "Offene Vorgänge": everything that has not reached the final step cleanly. An entry
        // drops out only once it is at the final status AND its confirmation was produced
        // (resultDoneAt > 0) AND there is no send error / hard bounce.
        //
        // The status condition is what covers a manually reopened entry: resetting the status
        // in the back end leaves the old resultDoneAt timestamp in place (it records that a
        // confirmation was once produced), so without it such an entry would silently stay
        // invisible for the whole re-do cycle.
        // Read as plain rows: this list is rendered, never modified, and hydrating a model per
        // open item only to read its columns is work with no return. The WHERE clause and the
        // ordering are the ones the model call used.
        $entries = $connection->fetchAllAssociative(
            'SELECT id, email, status, data, sendError, bounceHard, bounceInfo, resultError, '
            .'respondedAt, resultDoneAt FROM tl_workflow_entry '
            ."WHERE pid = ? AND (status < ? OR resultDoneAt = 0 OR (sendError IS NOT NULL AND sendError != '') OR bounceHard != '') "
            .'ORDER BY status, email',
            [(int) $workflow->id, $workflow->getFinalStatus()],
        );

        // The source columns are identical for all entries of a workflow, so resolve the
        // first-name / last-name / e-mail columns once from the first row's keys.
        $firstNameKey = null;
        $lastNameKey = null;
        $emailKey = null;
        $departmentKey = null;
        $keysResolved = false;

        foreach ($entries as $entry) {
            $row = StringUtil::deserialize($entry['data'], true);

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
            $email = (string) $entry['email'];

            if ('' === $email && null !== $emailKey) {
                $email = (string) ($row[$emailKey] ?? '');
            }

            $hasName = $hasName || '' !== $name;
            $hasVorname = $hasVorname || '' !== $vorname;
            $hasAbteilung = $hasAbteilung || '' !== $abteilung;

            $sendError = (string) $entry['sendError'];

            // Delivery state shown in its own "Zustellung" column, in addition to (never
            // replacing) the workflow status. Empty only while no mail has been attempted;
            // otherwise always something. Precedence: a hard bounce (permanent) beats a
            // retryable transport error, which beats a plain "sent, no error so far".
            // "sent" is derived from the status: it only advances past "imported" once an
            // invitation was actually sent.
            $delivery = '';

            if ('1' === (string) $entry['bounceHard']) {
                $delivery = 'bounce';
            } elseif ('' !== $sendError) {
                $delivery = 'error';
            } elseif ((int) $entry['respondedAt'] > 0 && 0 === (int) $entry['resultDoneAt']) {
                // Answered, but the confirmation (PDF + result mail) is not through yet.
                $delivery = 'pending';
            } elseif ((int) $entry['status'] >= WorkflowStatus::STATUS_INVITED) {
                $delivery = 'sent';
            }

            $pending[] = [
                'id'          => (int) $entry['id'],
                'email'       => $email,
                'statusIndex' => (int) $entry['status'],
                'status'      => $status->getStepLabel($workflow, (int) $entry['status']),
                'name'        => $name,
                'vorname'     => $vorname,
                'abteilung'   => $abteilung,
                'delivery'    => $delivery,
                'sendError'   => $sendError,
                'bounceInfo'  => (string) $entry['bounceInfo'],
                'resultError' => (string) $entry['resultError'],
            ];
        }

        return $pending;
    }
}
