<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Contao\StringUtil;
use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;

/**
 * Builds the absolute, individual form link for an entry. The link points at
 * the workflow's configured form page and carries the token as auto_item.
 */
class LinkGenerator
{
    public function __construct(private readonly ContaoFramework $framework)
    {
    }

    public function getFormLink(WorkflowModel $workflow, EntryModel $entry): string
    {
        $this->framework->initialize();

        $pageAdapter = $this->framework->getAdapter(PageModel::class);
        $page = $pageAdapter->findWithDetails($this->resolvePageId($workflow));

        if (null === $page) {
            throw new \RuntimeException('Für den Workflow ist keine gültige Formularseite konfiguriert.');
        }

        // Token is appended as auto_item (e.g. /workflow/<token>).
        return $page->getAbsoluteUrl('/'.$entry->token);
    }

    private function resolvePageId(WorkflowModel $workflow): int
    {
        $value = $workflow->formPage;

        if (is_numeric($value)) {
            return (int) $value;
        }

        // Radio pickers may store a serialized single value.
        $unserialized = StringUtil::deserialize($value, true);

        return (int) ($unserialized[0] ?? 0);
    }
}
