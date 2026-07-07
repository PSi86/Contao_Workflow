<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Psimandl\WorkflowBundle\Model\MasterModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;

/**
 * Serialises a configured workflow into a portable, site-independent configuration
 * document (the counterpart of {@see WorkflowConfigImporter}). Everything that is
 * site-specific is deliberately left out: the source file (UUID), the form page id and
 * the master logo. The letterhead variables and the e-mail templates (subjects/texts)
 * are embedded so the target installation can recreate them on import.
 */
class WorkflowConfigExporter
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(WorkflowModel $workflow): array
    {
        $this->framework->initialize();

        $questions = [];

        foreach ($workflow->getQuestions() as $question) {
            $questions[] = [
                'label'               => (string) $question->label,
                'type'                => (string) $question->type,
                'storageField'        => (string) $question->storageField,
                'mandatory'           => $question->isMandatory(),
                'prefill'             => $question->isPrefilled(),
                'readOnly'            => $question->isReadOnly(),
                'hideInForm'          => '1' === (string) $question->hideInForm,
                'description'         => (string) $question->description,
                'showStatementInForm' => $question->showsStatementInForm(),
                'pdfStatement'        => (string) $question->pdfStatement,
                'options'             => $question->getOptions(),
            ];
        }

        $rules = [];

        foreach ($workflow->getRules() as $rule) {
            $rules[] = [
                'title'      => (string) $rule->title,
                'isDefault'  => $rule->isDefaultRule(),
                'conditions' => $rule->getConditions(),
                'pdfBody'    => $rule->getPdfBody(),
            ];
        }

        return [
            'format'        => WorkflowConfigImporter::FORMAT,
            'version'       => WorkflowConfigImporter::VERSION,
            'workflow'      => [
                'title'                => (string) $workflow->title,
                'published'            => '1' === (string) $workflow->published,
                'steps'                => $workflow->getSteps(),
                'sourceSheet'          => (string) $workflow->sourceSheet,
                'headerRow'            => max(1, (int) $workflow->headerRow),
                'emailField'           => (string) $workflow->emailField,
                'requireSignature'     => $workflow->isSignatureRequired(),
                'pdfBodyType'          => (string) ($workflow->pdfBodyType ?: 'letter'),
                'pdfBodyTemplate'      => (string) $workflow->pdfBodyTemplate,
                'pdfTitle'             => (string) $workflow->pdfTitle,
                'introText'            => (string) $workflow->introText,
                'pdfSignatureDate'     => (string) $workflow->pdfSignatureDate,
                'pdfSignatureLocation' => (string) $workflow->pdfSignatureLocation,
                'pdfFileName'          => (string) $workflow->pdfFileName,
            ],
            'questions'     => $questions,
            'rules'         => $rules,
            'master'        => $this->exportMaster((int) $workflow->master),
            'notifications' => $this->exportNotifications($workflow),
        ];
    }

    public function exportJson(WorkflowModel $workflow): string
    {
        return (string) json_encode(
            $this->export($workflow),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function exportMaster(int $masterId): ?array
    {
        if ($masterId <= 0) {
            return null;
        }

        $master = MasterModel::findByPk($masterId);

        if (null === $master) {
            return null;
        }

        $pdfData = [];

        foreach ($master->getPdfData() as $key => $value) {
            $pdfData[] = ['key' => $key, 'value' => $value];
        }

        // Logo is intentionally omitted (site-specific binary).
        return [
            'title'          => (string) $master->title,
            'masterTemplate' => $master->getMasterTemplate(),
            'pdfData'        => $pdfData,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function exportNotifications(WorkflowModel $workflow): ?array
    {
        $map = [
            'invite'   => (int) $workflow->ncInvite,
            'reminder' => (int) $workflow->ncReminder,
            'result'   => (int) $workflow->ncResult,
        ];

        $out = [];

        foreach ($map as $kind => $notificationId) {
            $tpl = $this->readNotification($notificationId);

            if (null !== $tpl) {
                $out[$kind] = $tpl;
            }
        }

        return $out ?: null;
    }

    /**
     * @return array<string, string>|null
     */
    private function readNotification(int $notificationId): ?array
    {
        if ($notificationId <= 0) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT n.title AS title, l.email_subject AS subject, l.email_text AS text, '
            .'l.email_sender_name AS senderName, l.email_sender_address AS senderAddress, '
            .'l.attachment_tokens AS attachmentTokens '
            .'FROM tl_nc_notification n '
            .'JOIN tl_nc_message m ON m.pid = n.id '
            .'JOIN tl_nc_language l ON l.pid = m.id '
            .'WHERE n.id = ? ORDER BY l.fallback DESC, l.id ASC LIMIT 1',
            [$notificationId],
        );

        if (false === $row) {
            return null;
        }

        return [
            'title'            => (string) $row['title'],
            'subject'          => (string) $row['subject'],
            'text'             => (string) $row['text'],
            'senderName'       => (string) $row['senderName'],
            'senderAddress'    => (string) $row['senderAddress'],
            'attachmentTokens' => (string) $row['attachmentTokens'],
        ];
    }
}
