<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

/**
 * Shared, request-local holder that records which workflow entry (and which mail kind)
 * is currently being sent. {@see \Psimandl\WorkflowBundle\EventListener\Mailer\WorkflowMailTaggingListener}
 * reads it while the mail is still being assembled and stamps correlation headers onto
 * the e-mail, so the asynchronous send result can later be mapped back to the entry
 * (see {@see \Psimandl\WorkflowBundle\EventListener\Mailer\WorkflowMailResultListener}).
 *
 * The context is only relevant during the synchronous hand-off to the mailer (when the
 * Symfony MessageEvent fires); the actual delivery happens later in a worker and is
 * correlated purely via the stamped headers, not via this holder.
 */
class WorkflowMailContext
{
    public const KIND_INVITE = 'invite';
    public const KIND_REMINDER = 'reminder';
    public const KIND_RESULT = 'result';

    private ?int $workflowId = null;
    private ?int $entryId = null;
    private ?string $kind = null;

    public function set(int $workflowId, int $entryId, string $kind): void
    {
        $this->workflowId = $workflowId;
        $this->entryId = $entryId;
        $this->kind = $kind;
    }

    public function clear(): void
    {
        $this->workflowId = null;
        $this->entryId = null;
        $this->kind = null;
    }

    public function isActive(): bool
    {
        return null !== $this->entryId;
    }

    public function getWorkflowId(): ?int
    {
        return $this->workflowId;
    }

    public function getEntryId(): ?int
    {
        return $this->entryId;
    }

    public function getKind(): ?string
    {
        return $this->kind;
    }
}
