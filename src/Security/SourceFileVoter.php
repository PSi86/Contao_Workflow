<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Security;

use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Psimandl\WorkflowBundle\Service\FileManagerGuard;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\CacheableVoterInterface;

/**
 * Refuses, in Contao's file manager, to move or delete what the workflows read (3.6.2,
 * decisions E8 and E13; the rules are FileManagerGuard's).
 *
 * Contao asks this question right before it acts: DC_Folder::cut() with an UpdateAction whose
 * "pid" changes (also for drag and drop, which requests act=cut), DC_Folder::delete() with a
 * DeleteAction. A refusal there throws an AccessDeniedException:
 *  - a single move or delete shows Contao's bare error page – which is why
 *    FileManagerGuardListener greys out those icons, so it is only reachable by a hand-made URL;
 *  - a multiple selection skips the entry silently – which is why the refusal is reported here.
 *
 * The bundle's own "move to the protected folder" does not go through the file manager and is
 * therefore not affected.
 */
class SourceFileVoter implements CacheableVoterInterface
{
    private const ATTRIBUTE = ContaoCorePermissions::DC_PREFIX.'tl_files';

    public function __construct(private readonly FileManagerGuard $guard)
    {
    }

    public function supportsAttribute(string $attribute): bool
    {
        return self::ATTRIBUTE === $attribute;
    }

    public function supportsType(string $subjectType): bool
    {
        return \in_array($subjectType, [UpdateAction::class, DeleteAction::class], true);
    }

    public function vote(TokenInterface $token, mixed $subject, array $attributes): int
    {
        if (!\in_array(self::ATTRIBUTE, $attributes, true)) {
            return self::ACCESS_ABSTAIN;
        }

        if ($subject instanceof UpdateAction) {
            $path = $this->path($subject->getCurrentId());
            $newPid = $subject->getNewPid();

            // Only a move: an UpdateAction without a new parent is an edit of the record.
            if (null === $path || null === $newPid || $this->path($newPid) === $this->path($subject->getCurrentPid())) {
                return self::ACCESS_ABSTAIN;
            }

            if (null === $this->guard->moveRefusal($path)) {
                return self::ACCESS_ABSTAIN;
            }

            $this->guard->reportSkipped($path, false);

            return self::ACCESS_DENIED;
        }

        if ($subject instanceof DeleteAction) {
            $path = $this->path($subject->getCurrentId());

            if (null === $path || null === $this->guard->deleteRefusal($path)) {
                return self::ACCESS_ABSTAIN;
            }

            $this->guard->reportSkipped($path, true);

            return self::ACCESS_DENIED;
        }

        return self::ACCESS_ABSTAIN;
    }

    /**
     * The file manager passes paths, in a multiple selection URL-encoded (DC_Folder keeps the
     * clipboard encoded, see Contao #6840).
     */
    private function path(?string $id): ?string
    {
        if (null === $id || '' === $id) {
            return null;
        }

        return trim(rawurldecode($id), '/');
    }
}
