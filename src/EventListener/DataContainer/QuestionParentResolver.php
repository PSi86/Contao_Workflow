<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\DataContainer;
use Contao\Input;
use Psimandl\WorkflowBundle\Model\QuestionModel;

/**
 * Resolves the parent workflow of the answer field (tl_workflow_question) a DCA callback is
 * currently working on. Shared by the options callbacks and the edit lock, which both need
 * the parent's configuration but are reached through different views.
 */
class QuestionParentResolver
{
    /**
     * On edit "id" is the question; on create "pid" is either the parent workflow
     * (PASTE_INTO) or a sibling question (PASTE_AFTER, mode 1). Returns 0 when the parent
     * cannot be determined.
     */
    public function resolve(?DataContainer $dc): int
    {
        if (isset($dc->activeRecord->pid) && (int) $dc->activeRecord->pid > 0) {
            return (int) $dc->activeRecord->pid;
        }

        if ($dc?->id && 'create' !== Input::get('act')) {
            $question = QuestionModel::findByPk((int) $dc->id);

            if (null !== $question) {
                return (int) $question->pid;
            }
        }

        $pid = (int) Input::get('pid');

        if ($pid < 1) {
            return 0;
        }

        if (1 === (int) Input::get('mode')) {
            $sibling = QuestionModel::findByPk($pid);

            return null !== $sibling ? (int) $sibling->pid : 0;
        }

        return $pid;
    }
}
