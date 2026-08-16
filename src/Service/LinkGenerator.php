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
    /** @var array<int, PageModel|null> resolved form pages of this request, by page id */
    private array $pages = [];

    public function __construct(private readonly ContaoFramework $framework)
    {
    }

    public function getFormLink(WorkflowModel $workflow, EntryModel $entry): string
    {
        $page = $this->resolveFormPage($workflow);

        if (null === $page) {
            throw new \RuntimeException('Für den Workflow ist keine gültige Formularseite konfiguriert.');
        }

        // Token is appended as auto_item (e.g. /workflow/<token>).
        return $page->getAbsoluteUrl('/'.$entry->token);
    }

    /**
     * Resolves the workflow's configured form page (or null if none/invalid). Used
     * both to build links and to tell – before sending – whether sending is possible.
     *
     * Memoised per page id for the request: findWithDetails() walks the whole page tree up to
     * the root and is not cheap, while the overview asks for it twice per workflow – and every
     * workflow of a site typically points at the SAME form page.
     */
    public function resolveFormPage(WorkflowModel $workflow): ?PageModel
    {
        $id = $this->resolvePageId($workflow);

        if (!\array_key_exists($id, $this->pages)) {
            $this->framework->initialize();

            $this->pages[$id] = $this->framework->getAdapter(PageModel::class)->findWithDetails($id);
        }

        return $this->pages[$id];
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
