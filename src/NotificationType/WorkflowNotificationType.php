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
 * autosuggest and marks the special ones by type: "attachment" as a file token so
 * it can be attached to the result mail via the "Attachments via tokens" field
 * (##attachment##), "email" as an e-mail token (usable as the gateway recipient),
 * and "data_*" as a wildcard (any ##data_<column>## is accepted).
 *
 * NOTE: this list ONLY drives the back-end autosuggest and those token semantics –
 * it does NOT gate substitution. The workflow mails additionally resolve
 * ##letterhead_*##, ##system_*## and ##text_*## / ##text_all##, which
 * {@see NotificationDispatcher::baseTokens()} passes at send time: the Notification
 * Center keeps any token it does not recognise as a plain value token (see
 * NotificationCenter::createTokenCollectionFromArray), so those are replaced in the
 * sent mail even though they are intentionally not advertised here. Keep the docs
 * (README / docs/ANLEITUNG.md) in sync when changing this.
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
