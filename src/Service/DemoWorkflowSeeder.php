<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Dbafs;
use Doctrine\DBAL\Connection;
use Psimandl\WorkflowBundle\Model\WorkflowModel;

/**
 * Creates a fully synthetic demo workflow ("Musterverein") so a fresh installation is
 * not empty. Used once on install (see InstallDemoWorkflowMigration) and from the
 * "restore demo" button in the back end overview. Idempotent: an existing demo with the
 * known titles is removed and rebuilt.
 *
 * Unlike an imported preset, the demo ships a synthetic source CSV: it is copied into the
 * Contao file system, registered and imported, so the demo is "runnable" and shows data.
 * The actual record creation is delegated to {@see WorkflowConfigImporter} (one code path
 * for demo and preset import). It is deliberately non-invasive: no pages, no front end
 * modules, no Notification Center records.
 */
class DemoWorkflowSeeder
{
    public const WORKFLOW_TITLE = 'Demo: Einverständniserklärung (synthetische Daten)';

    private const MASTER_TITLE = 'Musterverein Briefpapier (Demo)';
    private const FILES_DIR = 'files/workflow-demo';
    private const FILE_NAME = 'demo-teilnehmer.csv';

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly WorkflowConfigImporter $configImporter,
        private readonly SpreadsheetImporter $spreadsheetImporter,
        private readonly DemoFormPage $demoFormPage,
        private readonly string $projectDir,
    ) {
    }

    public function seed(): WorkflowModel
    {
        $this->framework->initialize();

        $this->removeExisting();

        $sourceUuid = $this->ensureSourceFile();

        $workflow = $this->configImporter->materialize(
            $this->config(),
            createMaster: true,
            createNotifications: true,
            sourceUuid: $sourceUuid,
        );

        // Attach a working front end form page so invitations can actually be sent.
        // Idempotent (reuses an existing demo page); 0 when there is no published root.
        $pageId = $this->demoFormPage->ensure();

        if ($pageId > 0) {
            $this->connection->executeStatement('UPDATE tl_workflow SET formPage = ? WHERE id = ?', [$pageId, (int) $workflow->id]);
            $workflow->formPage = $pageId;
        }

        // Import the synthetic participants so the dashboard shows data. A failure here
        // must not prevent the (already configured) demo from existing.
        try {
            $this->spreadsheetImporter->import($workflow, true);
        } catch (\Throwable) {
            // ignore – the workflow is configured, the user can re-run the import
        }

        return $workflow;
    }

    /**
     * Removes a previous demo (workflow + children + entries + master) by its known
     * titles, so a restore is deterministic and never duplicates.
     */
    private function removeExisting(): void
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM tl_workflow WHERE title = ?',
            [self::WORKFLOW_TITLE],
        );

        foreach ($ids as $id) {
            foreach (['tl_workflow_question', 'tl_workflow_rule', 'tl_workflow_entry'] as $table) {
                $this->connection->executeStatement("DELETE FROM $table WHERE pid = ?", [(int) $id]);
            }

            $this->connection->executeStatement('DELETE FROM tl_workflow WHERE id = ?', [(int) $id]);
        }

        $this->connection->executeStatement('DELETE FROM tl_workflow_master WHERE title = ?', [self::MASTER_TITLE]);

        // Demo e-mail templates (Notification Center): removed and recreated on restore.
        // The shared e-mail gateway is left untouched.
        $ncIds = $this->connection->fetchFirstColumn(
            "SELECT id FROM tl_nc_notification WHERE title LIKE 'Workflow %(Demo)'",
        );

        foreach ($ncIds as $notificationId) {
            $this->connection->executeStatement(
                'DELETE l FROM tl_nc_language l JOIN tl_nc_message m ON l.pid = m.id WHERE m.pid = ?',
                [(int) $notificationId],
            );
            $this->connection->executeStatement('DELETE FROM tl_nc_message WHERE pid = ?', [(int) $notificationId]);
            $this->connection->executeStatement('DELETE FROM tl_nc_notification WHERE id = ?', [(int) $notificationId]);
        }
    }

    /**
     * Copies the bundled synthetic CSV into the Contao file system and registers it in
     * tl_files, returning its (binary) UUID for tl_workflow.sourceFile.
     */
    private function ensureSourceFile(): string
    {
        $relPath = self::FILES_DIR.'/'.self::FILE_NAME;
        $absDir = $this->projectDir.'/'.self::FILES_DIR;
        $absPath = $this->projectDir.'/'.$relPath;

        if (!is_dir($absDir) && !@mkdir($absDir, 0777, true) && !is_dir($absDir)) {
            throw new \RuntimeException('Could not create demo files directory: '.$absDir);
        }

        $source = \dirname(__DIR__).'/Resources/demo/'.self::FILE_NAME;

        if (!is_file($source)) {
            throw new \RuntimeException('Bundled demo CSV is missing: '.$source);
        }

        // Never overwrite an existing file – only copy when the target is missing.
        if (!is_file($absPath) && !@copy($source, $absPath)) {
            throw new \RuntimeException('Could not copy demo CSV to: '.$absPath);
        }

        /** @var Dbafs $dbafs */
        $dbafs = $this->framework->getAdapter(Dbafs::class);
        $file = $dbafs->addResource($relPath);

        if (null === $file || null === $file->uuid) {
            throw new \RuntimeException('Could not register the demo CSV in tl_files.');
        }

        return (string) $file->uuid;
    }

    /**
     * The demo workflow as a portable configuration document (same format the preset
     * import and the export use).
     *
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $person = "Name: ##data_vorname## ##data_nachname##\n"
            ."Abteilung: ##data_abteilung## · Funktion: ##data_funktion##\n\n";

        return [
            'format'   => WorkflowConfigImporter::FORMAT,
            'version'  => WorkflowConfigImporter::VERSION,
            'workflow' => [
                'title'                => self::WORKFLOW_TITLE,
                'published'            => true,
                'steps'                => ['Importiert', 'Eingeladen', 'Beantwortet'],
                'sourceSheet'          => '',
                'headerRow'            => 1,
                'emailField'           => 'E-Mail',
                'inputFields'          => ['Vorname', 'Nachname', 'Abteilung', 'Funktion'],
                'requireSignature'     => true,
                'pdfBodyType'          => 'letter',
                'pdfBodyTemplate'      => '',
                'pdfTitle'             => 'Einverständniserklärung (Demo)',
                'pdfSignatureDate'     => 'Unterschriftsdatum',
                'pdfSignatureLocation' => 'Ort',
                'pdfFileName'          => 'Einverstaendnis_##data_nachname##_##data_vorname##',
            ],
            'questions' => [
                [
                    'label'        => 'Ihre Entscheidung',
                    'type'         => 'radio',
                    'storageField' => 'Entscheidung',
                    'mandatory'    => true,
                    'hideInForm'   => false,
                    'options'      => [
                        ['value' => 'ja', 'label' => 'Einverstanden'],
                        ['value' => 'nein', 'label' => 'Nicht einverstanden'],
                    ],
                ],
                [
                    'label'        => 'Datum',
                    'type'         => 'currentTime',
                    'storageField' => 'Unterschriftsdatum',
                    'mandatory'    => false,
                    'hideInForm'   => true,
                    'options'      => [],
                ],
            ],
            'rules' => [
                [
                    'title'      => 'Einverständnis erteilt',
                    'isDefault'  => false,
                    'conditions' => [['field' => 'Entscheidung', 'operator' => 'eq', 'value' => 'ja']],
                    'pdfBody'    => $person
                        ."hiermit erkläre ich mein Einverständnis gegenüber dem ##var_verein## für das Jahr ##var_jahr##.\n\n"
                        .'Dies ist ein automatisch erzeugter Demo-Brief mit rein synthetischen Daten.',
                ],
                [
                    'title'      => 'Kein Einverständnis (Standardtext)',
                    'isDefault'  => true,
                    'conditions' => [],
                    'pdfBody'    => $person
                        ."für das Jahr ##var_jahr## erteile ich gegenüber dem ##var_verein## kein Einverständnis.\n\n"
                        .'Dies ist ein automatisch erzeugter Demo-Brief mit rein synthetischen Daten.',
                ],
            ],
            'master' => [
                'title'          => self::MASTER_TITLE,
                'masterTemplate' => 'pdf_master_generic',
                'pdfData'        => [
                    ['key' => 'HeaderLine', 'value' => 'Musterverein e.V. • Musterstraße 1 • 12345 Musterstadt'],
                    ['key' => 'Footer1', 'value' => "Musterverein e.V.\nMusterstraße 1\n12345 Musterstadt"],
                    ['key' => 'Footer2', 'value' => "Vorstand: Max Mustermann\nVereinsregister: VR 0000"],
                    ['key' => 'Footer3', 'value' => "www.example.org\ninfo@example.org\nTelefon: 01234 56789"],
                    ['key' => 'Footer4', 'value' => "Bankverbindung (Demo)\nIBAN: DE00 0000 0000 0000 0000 00"],
                    ['key' => 'Jahr', 'value' => date('Y')],
                    ['key' => 'Verein', 'value' => 'Musterverein e.V.'],
                    ['key' => 'Ort', 'value' => 'Musterstadt'],
                ],
            ],
            'notifications' => [
                'invite' => [
                    'title'            => 'Workflow Einladung (Demo)',
                    'subject'          => 'Einladung (Demo): ##workflow_title##',
                    'text'             => "Hallo,\n\n(Demo) bitte füllen Sie das folgende Formular aus:\n##link##\n\nVielen Dank.",
                    'senderName'       => 'Workflow (Demo)',
                    'senderAddress'    => 'noreply@example.com',
                    'attachmentTokens' => '',
                ],
                'reminder' => [
                    'title'            => 'Workflow Erinnerung (Demo)',
                    'subject'          => 'Erinnerung (Demo): ##workflow_title##',
                    'text'             => "Hallo,\n\n(Demo) bitte denken Sie an das Formular:\n##link##\n\nVielen Dank.",
                    'senderName'       => 'Workflow (Demo)',
                    'senderAddress'    => 'noreply@example.com',
                    'attachmentTokens' => '',
                ],
                'result' => [
                    'title'            => 'Workflow Ergebnis (Demo)',
                    'subject'          => 'Ihre Bestätigung (Demo): ##workflow_title##',
                    'text'             => "Hallo,\n\n(Demo) vielen Dank. Ihre Entscheidung: ##data_entscheidung##.\nIhr Dokument finden Sie im Anhang.",
                    'senderName'       => 'Workflow (Demo)',
                    'senderAddress'    => 'noreply@example.com',
                    'attachmentTokens' => '##attachment##',
                ],
            ],
        ];
    }
}
