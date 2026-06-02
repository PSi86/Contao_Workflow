<?php

declare(strict_types=1);

namespace Psimandl\TrainerWorkflowBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\StringUtil;
use Psimandl\TrainerWorkflowBundle\Service\TokenGenerator;

/**
 * DCA callbacks for tl_trainer_entry.
 */
class EntryListener
{
    public function __construct(private readonly TokenGenerator $tokenGenerator)
    {
    }

    /**
     * Renders one entry row in the parent (workflow) child view.
     *
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_trainer_entry', target: 'list.sorting.child_record')]
    public function renderChildRecord(array $row): string
    {
        $status = (int) ($row['status'] ?? 0);
        $email = StringUtil::specialchars((string) ($row['email'] ?? ''));

        return sprintf(
            '<div class="tl_content_left">%s <span style="color:#999">[Status %d]</span></div>',
            $email,
            $status,
        );
    }

    /**
     * Pretty-prints the serialized data blob as JSON for the read-only field.
     */
    #[AsCallback(table: 'tl_trainer_entry', target: 'fields.data.load')]
    public function formatDataForDisplay(mixed $value): string
    {
        $data = StringUtil::deserialize($value, true);

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * Ensures a manually created entry always receives a unique token.
     */
    #[AsCallback(table: 'tl_trainer_entry', target: 'config.onsubmit')]
    public function ensureToken(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        $current = \Contao\Database::getInstance()
            ->prepare('SELECT token FROM tl_trainer_entry WHERE id=?')
            ->execute($dc->id)
        ;

        if ($current->numRows && '' === (string) $current->token) {
            \Contao\Database::getInstance()
                ->prepare('UPDATE tl_trainer_entry SET token=? WHERE id=?')
                ->execute($this->tokenGenerator->generate(), $dc->id)
            ;
        }
    }
}
