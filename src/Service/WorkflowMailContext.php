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
    private ?string $recipient = null;
    private ?int $notificationId = null;

    public function set(int $workflowId, int $entryId, string $kind, string $recipient = '', int $notificationId = 0): void
    {
        $this->workflowId = $workflowId;
        $this->entryId = $entryId;
        $this->kind = $kind;
        $this->recipient = $recipient;
        $this->notificationId = $notificationId;
    }

    public function clear(): void
    {
        $this->workflowId = null;
        $this->entryId = null;
        $this->kind = null;
        $this->recipient = null;
        $this->notificationId = null;
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

    public function getRecipient(): ?string
    {
        return $this->recipient;
    }

    /**
     * The Notification Center notification id that is currently being sent. It lets the
     * result listener's synchronous fallback tell our own in-flight mail apart from a
     * foreign notification that happens to be sent within the same request.
     */
    public function getNotificationId(): ?int
    {
        return $this->notificationId;
    }
}
