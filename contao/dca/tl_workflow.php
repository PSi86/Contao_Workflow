<?php

declare(strict_types=1);

use Contao\DC_Table;
use Psimandl\WorkflowBundle\EventListener\DataContainer\AnswerConfigListener;
use Psimandl\WorkflowBundle\EventListener\DataContainer\ConfigExportListener;
use Psimandl\WorkflowBundle\EventListener\DataContainer\PreviewButtonListener;
use Psimandl\WorkflowBundle\EventListener\DataContainer\ResetButtonListener;
use Psimandl\WorkflowBundle\EventListener\DataContainer\WorkflowDeleteListener;

$GLOBALS['TL_DCA']['tl_workflow'] = [
    'config' => [
        'dataContainer'    => DC_Table::class,
        // Questions and rules are copied with the workflow (act=copy); entries are
        // not (tl_workflow_entry sets doNotCopyRecords). All three cascade on delete.
        'ctable'           => ['tl_workflow_entry', 'tl_workflow_question', 'tl_workflow_rule'],
        'switchToEdit'     => true,
        'enableVersioning' => true,
        // onload: drop never-saved (tstamp=0) child rows left behind when a
        // "new question/rule" dialog is closed without saving (the embedded
        // dcaWizard lists never trigger Contao's own cleanup).
        // onrestore_version: re-apply a restored answer-field order (stored in the
        // versioned "questionOrder" field) to the child rows' sorting.
        // The order itself is written by the questionOrder field's save_callback –
        // because questionOrder is a real column, reordering is detected by Contao's
        // versioning (new version + visible diff) and is restorable.
        'onload_callback'           => [[AnswerConfigListener::class, 'cleanupAbandonedChildren']],
        'onrestore_version_callback' => [[AnswerConfigListener::class, 'restoreQuestionOrder']],
        'sql' => [
            'keys' => [
                'id' => 'primary',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode'           => 1,
            'fields'         => ['tstamp DESC'],
            'flag'           => 12,
            // Newest first, but as a flat list (no tstamp group headers).
            'disableGrouping' => true,
            'panelLayout'    => 'search,limit',
        ],
        'label' => [
            'fields' => ['title'],
            'format' => '%s',
        ],
        'global_operations' => [
            'all' => [
                'href'       => 'act=select',
                'class'      => 'header_edit_all',
                'attributes' => 'onclick="Backend.getScrollOffset()" accesskey="e"',
            ],
        ],
        'operations' => [
            'edit' => [
                'href' => 'act=edit',
                'icon' => 'header.svg',
            ],
            'entries' => [
                'href' => 'table=tl_workflow_entry',
                'icon' => 'edit.svg',
            ],
            // Downloads the workflow's portable JSON configuration. Uses a custom route
            // (not a do=… action), so the link is rendered by a button_callback.
            'exportConfig' => [
                'href'            => '',
                'icon'            => 'share.svg',
                'button_callback' => [ConfigExportListener::class, 'renderButton'],
            ],
            'copy' => [
                'href' => 'act=copy',
                'icon' => 'copy.svg',
            ],
            'delete' => [
                'href'            => 'act=delete',
                'icon'            => 'delete.svg',
                // Custom confirmation: also warns that the workflow's generated PDF
                // documents (with their count) are deleted along with it.
                'button_callback' => [WorkflowDeleteListener::class, 'renderDeleteButton'],
            ],
            'show' => [
                'href' => 'act=show',
                'icon' => 'show.svg',
            ],
        ],
    ],
    'palettes' => [
        // No __selector__/subpalettes: the conditional fields (signature line,
        // rules vs. body template) are always part of the palette and are shown or
        // hidden client-side from their selector field (data-wf-toggle, see
        // workflow-field-toggle.js). This replaces Contao's submitOnChange /
        // toggleSubpalette, which would persist the record without the user
        // clicking "save".
        //
        // Functional grouping: workflow basics → source data → shared document
        // content (heading + intro, shown in the FORM and the PDF) → the form
        // (page, signature, answer fields) → the PDF (stationery, file name,
        // body) → notifications.
        // ... → notifications → the destructive participant reset, in its own collapsed
        // section at the very end (see WorkflowLockListener, which points here).
        'default' => '{title_legend},title,published;{steps_legend},steps;{source_legend},sourceFile,sourceSheet,headerRow,emailField;{content_legend},pdfTitle,introText;{form_legend},formPage,requireSignature,pdfSignatureDate,pdfSignatureLocation,questions,questionOrder,formPreview;{pdf_legend},master,pdfFileName,pdfBodyType,rules,pdfBodyTemplate,pdfPreview;{notification_legend},ncInvite,ncReminder,ncResult;{reset_legend:hide},resetEntries',
    ],
    'fields' => [
        'id' => [
            'sql' => 'int(10) unsigned NOT NULL auto_increment',
        ],
        'tstamp' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'title' => [
            'exclude'   => true,
            'search'    => true,
            'inputType' => 'text',
            // unique: blocks saving a duplicate title (and empties the title on copy, so a
            // duplicated workflow must be given a free name before it can be saved).
            'eval'      => ['mandatory' => true, 'unique' => true, 'decodeEntities' => true, 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql'       => "varchar(255) NOT NULL default ''",
        ],
        'published' => [
            'exclude'   => true,
            'inputType' => 'checkbox',
            // A copy must be reviewed before it runs, so it starts unpublished.
            'eval'      => ['tl_class' => 'w50 m12', 'doNotCopy' => true],
            'sql'       => "char(1) NOT NULL default ''",
        ],
        'steps' => [
            'exclude'   => true,
            'inputType' => 'listWizard',
            'eval'      => ['mandatory' => true, 'tl_class' => 'clr'],
            'sql'       => 'blob NULL',
        ],
        'sourceFile' => [
            'exclude'   => true,
            'inputType' => 'fileTree',
            'eval'      => [
                'fieldType'      => 'radio',
                'files'          => true,
                'filesOnly'      => true,
                'extensions'     => 'csv,xlsx,xls',
                'submitOnChange' => true,
                'tl_class'       => 'clr',
                // A copy must load its own source file before it can run.
                'doNotCopy'      => true,
            ],
            'sql' => 'binary(16) NULL',
        ],
        'sourceSheet' => [
            'exclude'   => true,
            'inputType' => 'select',
            'eval'      => ['includeBlankOption' => true, 'submitOnChange' => true, 'tl_class' => 'w50'],
            'sql'       => "varchar(128) NOT NULL default ''",
        ],
        'headerRow' => [
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => ['rgxp' => 'natural', 'mandatory' => true, 'submitOnChange' => true, 'tl_class' => 'w50'],
            'sql'       => "smallint(5) unsigned NOT NULL default 1",
        ],
        'emailField' => [
            'exclude'   => true,
            'inputType' => 'select',
            'eval'      => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql'       => "varchar(128) NOT NULL default ''",
        ],
        'requireSignature' => [
            'exclude'   => true,
            'inputType' => 'checkbox',
            // data-wf-toggle: show the signature-line fields only while checked
            // (client-side, no save). See workflow-field-toggle.js.
            'eval'      => ['tl_class' => 'w50 m12', 'data-wf-toggle' => '{"mode":"checkbox","on":["pdfSignatureDate","pdfSignatureLocation"]}'],
            'sql'       => "char(1) NOT NULL default '1'",
        ],
        'formPage' => [
            'exclude'   => true,
            'inputType' => 'pageTree',
            'eval'      => ['fieldType' => 'radio', 'tl_class' => 'clr', 'mandatory' => true],
            'sql'       => "int(10) unsigned NOT NULL default 0",
        ],
        // Answer fields (tl_workflow_question), embedded in the edit mask.
        // hideButton: only the inline list (with its own new/edit/delete that
        // open clean record popups). Custom list_callback renders the rows with
        // a drag handle – the order is changed directly in this list and written
        // only when the workflow is saved (config.onsubmit persistQuestionOrder,
        // posted via the hidden wfQuestionOrder field; see workflow-question-sort.js).
        'questions' => [
            'exclude'   => true,
            'inputType' => 'dcaWizard',
            'foreignTable' => 'tl_workflow_question',
            'foreignField' => 'pid',
            'eval'      => [
                'tl_class'          => 'clr',
                'hideButton'        => true,
                'orderField'        => 'sorting',
                'operations'        => ['edit', 'delete'],
                'global_operations' => ['new'],
                'list_callback'     => [AnswerConfigListener::class, 'renderQuestionsList'],
            ],
        ],
        // Versioned answer-field order (comma-separated question ids). Hidden field,
        // written by drag&drop in the questions list (workflow-question-sort.js sets
        // #ctrl_questionOrder). load: current order from the child sorting; save:
        // renumbers the child sorting and stores the normalized order. Being a real
        // column makes the reordering part of the workflow's version history.
        'questionOrder' => [
            'exclude'   => true,
            'inputType' => 'text',
            'load_callback' => [[AnswerConfigListener::class, 'loadQuestionOrder']],
            'save_callback' => [[AnswerConfigListener::class, 'saveQuestionOrder']],
            'eval'      => ['tl_class' => 'wf-question-order', 'doNotCopy' => true],
            'sql'       => 'text NULL',
        ],
        // PDF rules (tl_workflow_rule), embedded in the edit mask (letter mode only).
        // Custom list_callback shows label + readable conditions ("(Standardtext)"
        // for a rule without conditions) and keeps the edit/delete operations.
        'rules' => [
            'exclude'   => true,
            'inputType' => 'dcaWizard',
            'foreignTable' => 'tl_workflow_rule',
            'foreignField' => 'pid',
            'eval'      => [
                'tl_class'          => 'clr',
                'hideButton'        => true,
                'orderField'        => 'sorting',
                'operations'        => ['edit', 'delete'],
                'global_operations' => ['new'],
                'list_callback'     => [AnswerConfigListener::class, 'renderRulesList'],
            ],
        ],
        'master' => [
            'exclude'    => true,
            'inputType'  => 'select',
            'foreignKey' => 'tl_workflow_master.title',
            'eval'       => ['mandatory' => true, 'chosen' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql'        => "int(10) unsigned NOT NULL default 0",
            'relation'   => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        // Data field whose value is printed as the signature date in the PDF
        // (typically an "Aktuelle Zeit" answer field). Empty = no date printed.
        'pdfSignatureDate' => [
            'exclude'   => true,
            'inputType' => 'select',
            // clr: start a new row so the two signature-line fields sit BELOW the
            // "Signatur benötigt" checkbox (not next to it).
            'eval'      => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50 clr'],
            'sql'       => "varchar(128) NOT NULL default ''",
        ],
        // Source column whose value is printed as the place in the signature line
        // (e.g. the participant's town). Empty = no place printed.
        'pdfSignatureLocation' => [
            'exclude'   => true,
            'inputType' => 'select',
            'eval'      => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql'       => "varchar(128) NOT NULL default ''",
        ],
        // File name pattern of the generated PDF, with placeholders. Sanitized to a
        // safe name; a short token is appended on collision. Empty = entry token.
        'pdfFileName' => [
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => ['decodeEntities' => true, 'maxlength' => 255, 'tl_class' => 'clr'],
            'sql'       => "varchar(255) NOT NULL default ''",
        ],
        'pdfBodyType' => [
            'exclude'   => true,
            'inputType' => 'select',
            'options'   => ['letter', 'template'],
            'reference' => &$GLOBALS['TL_LANG']['tl_workflow']['pdfBodyTypeOptions'],
            // data-wf-toggle: "letter" shows the PDF rules, "template" the body
            // template – switched client-side, no save. See workflow-field-toggle.js.
            'eval'      => ['tl_class' => 'w50', 'data-wf-toggle' => '{"mode":"select","map":{"letter":["rules"],"template":["pdfBodyTemplate"]}}'],
            'sql'       => "varchar(16) NOT NULL default 'letter'",
        ],
        // Shared heading: shown at the top of the FORM and as the PDF heading.
        // No ##text_*## tokens here – the form renders it before answering, so
        // only tokens that resolve identically in both places are supported.
        'pdfTitle' => [
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => ['decodeEntities' => true, 'maxlength' => 255, 'tl_class' => 'clr'],
            'sql'       => "varchar(255) NOT NULL default ''",
        ],
        // Optional intro paragraph after the heading, in the FORM and the PDF.
        'introText' => [
            'exclude'   => true,
            'search'    => true,
            'inputType' => 'textarea',
            'eval'      => ['decodeEntities' => true, 'style' => 'height:80px', 'tl_class' => 'clr'],
            'sql'       => 'text NULL',
        ],
        // Read-only "open form preview" button (no DB column). Rendered in the form
        // section; opens the standalone form preview (submit disabled) in a new tab.
        'formPreview' => [
            'exclude'              => true,
            'input_field_callback' => [PreviewButtonListener::class, 'renderFormButton'],
            'eval'                 => ['tl_class' => 'clr'],
        ],
        // Read-only "open PDF preview" button (no DB column). Rendered in the
        // PDF-Inhalt section; streams the rendered PDF with sample data in a new tab.
        'pdfPreview' => [
            'exclude'              => true,
            'input_field_callback' => [PreviewButtonListener::class, 'renderPdfButton'],
            'eval'                 => ['tl_class' => 'clr'],
        ],
        // Read-only "reset all participants" button (no DB column). Sits alone in its own
        // collapsed section: it is the documented way out of the edit lock, but it voids
        // every answer, so it needs the extra deliberate click before the confirm dialog.
        'resetEntries' => [
            'exclude'              => true,
            'input_field_callback' => [ResetButtonListener::class, 'renderResetButton'],
            'eval'                 => ['tl_class' => 'clr'],
        ],
        'pdfBodyTemplate' => [
            'exclude'   => true,
            'inputType' => 'select',
            'eval'      => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql'       => "varchar(64) NOT NULL default ''",
        ],
        // Internal: checksum of the last imported source file (change detection).
        'sourceHash' => [
            'eval' => ['doNotCopy' => true],
            'sql'  => "varchar(64) NOT NULL default ''",
        ],
        // Internal: reference fields (form page, letterhead, notifications) that a
        // configuration import could not link on this site. Serialized list of field
        // names; drives the red outline + notice in the edit mask (WorkflowIntegrityListener)
        // and is pruned on save. Not copied – a copy re-derives its own state.
        'importIssues' => [
            'eval' => ['doNotCopy' => true],
            'sql'  => 'blob NULL',
        ],
        'ncInvite' => [
            'exclude'    => true,
            'inputType'  => 'select',
            'foreignKey' => 'tl_nc_notification.title',
            'eval'       => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql'        => "int(10) unsigned NOT NULL default 0",
            'relation'   => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        'ncReminder' => [
            'exclude'    => true,
            'inputType'  => 'select',
            'foreignKey' => 'tl_nc_notification.title',
            'eval'       => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql'        => "int(10) unsigned NOT NULL default 0",
            'relation'   => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        'ncResult' => [
            'exclude'    => true,
            'inputType'  => 'select',
            'foreignKey' => 'tl_nc_notification.title',
            'eval'       => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql'        => "int(10) unsigned NOT NULL default 0",
            'relation'   => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
    ],
];
