<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Security;

use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Security\SourceFileVoter;
use Psimandl\WorkflowBundle\Service\FileManagerGuard;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * The refusal behind the greyed-out icons (3.6.2): Contao asks right before it moves or
 * deletes, and a move is an UpdateAction whose parent changes – an edit of the record (rename,
 * metadata) must stay untouched.
 */
final class SourceFileVoterTest extends TestCase
{
    private const ATTRIBUTE = 'contao_dc.tl_files';

    /** @var list<array{0: string, 1: bool}> */
    private array $reported = [];

    public function testMovingASourceFileIsDeniedAndReported(): void
    {
        $vote = $this->vote(new UpdateAction('tl_files', ['id' => 'files/verein/liste.xlsx', 'pid' => 'files/verein'], ['pid' => 'files/bilder']));

        $this->assertSame(VoterInterface::ACCESS_DENIED, $vote);
        $this->assertSame([['files/verein/liste.xlsx', false]], $this->reported);
    }

    public function testAnEditWithoutAMoveIsNotJudged(): void
    {
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(new UpdateAction('tl_files', ['id' => 'files/verein/liste.xlsx'])));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(new UpdateAction('tl_files', ['id' => 'files/verein/liste.xlsx', 'pid' => 'files/verein'], ['pid' => 'files/verein'])));
        $this->assertSame([], $this->reported);
    }

    public function testMovingAnythingElseIsLeftToContao(): void
    {
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(new UpdateAction('tl_files', ['id' => 'files/bilder/logo.svg', 'pid' => 'files/bilder'], ['pid' => 'files/verein'])));
    }

    public function testDeletingASourceFileIsDeniedAndReported(): void
    {
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new DeleteAction('tl_files', ['id' => 'files/verein/liste.xlsx'])));
        $this->assertSame([['files/verein/liste.xlsx', true]], $this->reported);
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(new DeleteAction('tl_files', ['id' => 'files/bilder/logo.svg'])));
    }

    public function testTheEncodedPathsOfAMultipleSelectionAreDecoded(): void
    {
        $vote = $this->vote(new DeleteAction('tl_files', ['id' => 'files/verein/Mitglieder%202026.xlsx']), ['files/verein/Mitglieder 2026.xlsx']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $vote);
    }

    public function testOtherTablesAndActionsAreNotJudged(): void
    {
        $voter = new SourceFileVoter($this->guard());
        $token = $this->createMock(TokenInterface::class);

        $this->assertFalse($voter->supportsAttribute('contao_dc.tl_content'));
        $this->assertFalse($voter->supportsType(CreateAction::class));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new DeleteAction('tl_files', ['id' => 'files/verein/liste.xlsx']), ['contao_dc.tl_content']));
    }

    /**
     * @param list<string> $guarded paths the guard refuses
     */
    private function vote(UpdateAction|DeleteAction $action, array $guarded = ['files/verein/liste.xlsx']): int
    {
        return (new SourceFileVoter($this->guard($guarded)))->vote($this->createMock(TokenInterface::class), $action, [self::ATTRIBUTE]);
    }

    /**
     * @param list<string> $guarded
     */
    private function guard(array $guarded = ['files/verein/liste.xlsx']): FileManagerGuard
    {
        $guard = $this->createMock(FileManagerGuard::class);
        $refuse = static fn (string $path): ?string => \in_array($path, $guarded, true) ? 'refused' : null;
        $guard->method('moveRefusal')->willReturnCallback($refuse);
        $guard->method('deleteRefusal')->willReturnCallback($refuse);
        $guard->method('reportSkipped')->willReturnCallback(function (string $path, bool $delete): void {
            $this->reported[] = [$path, $delete];
        });

        return $guard;
    }
}
