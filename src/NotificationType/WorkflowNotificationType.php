<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\NotificationType;

use Terminal42\NotificationCenterBundle\NotificationType\NotificationTypeInterface;
use Terminal42\NotificationCenterBundle\Token\Definition\AnythingTokenDefinition;
use Terminal42\NotificationCenterBundle\Token\Definition\EmailTokenDefinition;
use Terminal42\NotificationCenterBundle\Token\Definition\Factory\TokenDefinitionFactoryInterface;
use Terminal42\NotificationCenterBundle\Token\Definition\FileTokenDefinition;
use Terminal42\NotificationCenterBundle\Token\Definition\TextTokenDefinition;

/**
 * Notification type shared by the workflow mails (invitation, reminder, result).
 *
 * Declaring the tokens lets the Notification Center offer them in the back end
 * and, importantly, marks "attachment" as a file token so it can be attached to
 * the result mail via the "Attachments via tokens" field (##attachment##).
 */
class WorkflowNotificationType implements NotificationTypeInterface
{
    public const NAME = 'workflow';

    public function __construct(private readonly TokenDefinitionFactoryInterface $factory)
    {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getTokenDefinitions(): array
    {
        return [
            $this->factory->create(EmailTokenDefinition::class, 'email', 'workflow.email'),
            $this->factory->create(TextTokenDefinition::class, 'link', 'workflow.link'),
            $this->factory->create(TextTokenDefinition::class, 'workflow_title', 'workflow.workflow_title'),
            $this->factory->create(FileTokenDefinition::class, 'attachment', 'workflow.attachment'),
            $this->factory->create(AnythingTokenDefinition::class, 'data_*', 'workflow.data_*'),
        ];
    }
}
