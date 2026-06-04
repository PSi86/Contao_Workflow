<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Model;

use Contao\Model;
use Contao\StringUtil;

/**
 * Reads tl_workflow_master – a reusable "Briefkopf" (letterhead): the master
 * template (chrome), the logo and the static PDF variables. Workflows reference
 * a master; logo and variables are maintained here, not on the workflow.
 *
 * @property int    $id
 * @property int    $tstamp
 * @property string $title
 * @property string $masterTemplate Chrome template name (e.g. pdf_master).
 * @property string $pdfLogo        UUID of the logo image.
 * @property string $pdfData        Serialized static key/value variables.
 */
class MasterModel extends Model
{
    protected static $strTable = 'tl_workflow_master';

    public function getMasterTemplate(): string
    {
        return '' !== (string) $this->masterTemplate ? (string) $this->masterTemplate : 'pdf_master';
    }

    /**
     * @return array<string, string> variable key => value
     */
    public function getPdfData(): array
    {
        $data = [];

        foreach (StringUtil::deserialize($this->pdfData, true) as $pair) {
            if (isset($pair['key']) && '' !== (string) $pair['key']) {
                $data[(string) $pair['key']] = (string) ($pair['value'] ?? '');
            }
        }

        return $data;
    }
}
