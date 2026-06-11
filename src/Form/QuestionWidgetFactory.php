<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Form;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\System;
use Contao\Widget;
use Psimandl\WorkflowBundle\Model\QuestionModel;

/**
 * Maps an answer field (tl_workflow_question) onto the matching Contao form
 * widget (FormText, FormTextarea, FormSelect, FormRadio, FormCheckbox), so the
 * front-end form reuses Contao's validation – mandatory checks, option
 * whitelisting and the localised ERR.* messages – instead of re-implementing
 * it. Rendering stays in the workflow's own template (the statement hints and
 * the signature pad need markup the core form templates cannot express); the
 * widgets are used for validation and value extraction only.
 *
 * Widgets are constructed the same way Contao's form generator does it
 * (`new $class($fieldRow)`, see contao/forms/Form.php), with the options
 * already in the widget's native [['value' => …, 'label' => …], …] shape.
 */
class QuestionWidgetFactory
{
    private const TYPE_MAP = [
        'text'     => 'text',
        'date'     => 'text',
        'textarea' => 'textarea',
        'select'   => 'select',
        'radio'    => 'radio',
        'checkbox' => 'checkbox',
    ];

    public function __construct(private readonly ContaoFramework $framework)
    {
    }

    /**
     * The validation widget for a question, or null for the types handled
     * entirely by the controller (currentTime, display).
     */
    public function create(QuestionModel $question): ?Widget
    {
        $type = self::TYPE_MAP[(string) $question->type] ?? null;

        if (null === $type) {
            return null;
        }

        $this->framework->initialize();
        // The ERR.* messages used by Widget::validate().
        System::loadLanguageFile('default');

        /** @var class-string<Widget>|null $class */
        $class = $GLOBALS['TL_FFL'][$type] ?? null;

        if (null === $class || !class_exists($class)) {
            return null;
        }

        $options = [];

        foreach ($question->getOptions() as $option) {
            $options[] = ['value' => $option['value'], 'label' => $option['label']];
        }

        $widget = new $class([
            'id'        => 'q_'.$question->id,
            'name'      => 'q_'.$question->id,
            'label'     => (string) $question->label,
            'mandatory' => $question->isMandatory(),
            'multiple'  => $question->isMultiple(),
            'options'   => $options,
            // Plain-text values like the form generator (tags are still
            // stripped by Contao's input cleaning, entities come back decoded).
            'decodeEntities' => true,
        ]);

        $widget->required = $question->isMandatory();

        return $widget;
    }
}
