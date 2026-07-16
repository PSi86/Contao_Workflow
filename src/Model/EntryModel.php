<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Model;

use Contao\Model;
use Contao\Model\Collection;
use Contao\StringUtil;

/**
 * Reads and writes tl_workflow_entry (one participant row).
 *
 * @property int    $id
 * @property int    $pid           Workflow id (tl_workflow.id).
 * @property int    $tstamp
 * @property string $token         Individual, unguessable key used in the link.
 * @property int    $status        Process status; default 0.
 * @property string $email
 * @property string $data          Serialized array of all source fields incl. stored answers.
 * @property string $signature     Base64 PNG of the signature.
 * @property string $pdfPath       Relative path of the generated PDF (below project var/).
 * @property int    $sentAt
 * @property int    $respondedAt
 * @property string $sendError     Last mail send failure (kind + message); empty when OK.
 * @property int    $sendErrorAt
 */
class EntryModel extends Model
{
    protected static $strTable = 'tl_workflow_entry';

    public static function findByToken(string $token): ?self
    {
        if ('' === $token) {
            return null;
        }

        return static::findOneBy('token', $token);
    }

    /**
     * Entries of a workflow at a given status, used to pick invitation/reminder recipients.
     * Addresses with a confirmed hard bounce are excluded so a dead address is never mailed
     * again (which would only produce another bounce). Correcting the address clears the flag
     * (see EntryListener), which brings the entry back into these runs.
     *
     * @return Collection<EntryModel>|array<EntryModel>|null
     */
    public static function findByWorkflowAndStatus(int $workflowId, int $status)
    {
        return static::findBy(
            ['pid=?', 'status=?', "bounceHard=''"],
            [$workflowId, $status],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return StringUtil::deserialize($this->data, true);
    }

    public function getDataValue(string $key, string $default = ''): string
    {
        $data = $this->getData();

        return isset($data[$key]) ? (string) $data[$key] : $default;
    }
}
