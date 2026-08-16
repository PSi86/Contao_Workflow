<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Psimandl\WorkflowBundle\Model\MasterModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;

/**
 * The content variables of the letterhead ("Briefpapier") a workflow is assigned to – the keys
 * that are addressable as ##letterhead_*## and selectable as the signature place.
 *
 * Two sources make up the list: the key/value pairs stored on the letterhead itself and the keys
 * its master template declares in $GLOBALS['TL_WORKFLOW_PDF_VARS']. A declared key that has not
 * been filled in yet still belongs in the list – it exists, it is simply empty.
 *
 * Variables of the group "layout" are excluded throughout: page margins and font sizes are
 * typography, not letter content. Offering them as a placeholder or as the signature place would
 * put a millimetre value into the document.
 */
class LetterheadVars
{
    /**
     * @return array<int, string> variable keys, the filled-in ones first
     */
    public function keys(WorkflowModel $workflow): array
    {
        $master = (int) $workflow->master > 0 ? MasterModel::findByPk((int) $workflow->master) : null;

        if (null === $master) {
            return [];
        }

        return $this->collect(
            $master->getPdfData(),
            $GLOBALS['TL_WORKFLOW_PDF_VARS'][$master->getMasterTemplate()] ?? [],
        );
    }

    /**
     * The same list as an option list for a DCA picker (key => key).
     *
     * @return array<string, string>
     */
    public function options(WorkflowModel $workflow): array
    {
        $keys = $this->keys($workflow);

        return [] !== $keys ? array_combine($keys, $keys) : [];
    }

    /**
     * The rule itself, free of the model lookup: stored keys first (they carry a value someone
     * entered), then the keys the template declares, "layout" excluded, no duplicates.
     *
     * @param array<string, string> $stored   the letterhead's own key/value pairs
     * @param array<string, mixed>  $declared the template's registry entry
     *
     * @return array<int, string>
     */
    public function collect(array $stored, array $declared): array
    {
        $layout = [];

        foreach ($declared as $key => $declaration) {
            if (\is_array($declaration) && 'layout' === ($declaration['group'] ?? 'content')) {
                $layout[$key] = true;
            }
        }

        $keys = [];

        foreach ([array_keys($stored), array_keys($declared)] as $source) {
            foreach ($source as $key) {
                if (!isset($layout[$key]) && !\in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }
}
