<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\CoreBundle\Security\ContaoCorePermissions;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Who may change a workflow's source file outside the edit mask: upload a new one in the
 * overview, move it to the protected folder (decision E3 of the 3.6.2 plan: everyone who may
 * edit the workflow's source file).
 *
 * The same two permissions the edit mask asks for the field itself: access to the workflow
 * management module, and the field tl_workflow.sourceFile (it is an excluded field, so a
 * non-admin needs it granted in the user group). Admins pass both.
 */
class SourceFilePermission
{
    public function __construct(private readonly Security $security)
    {
    }

    public function canChange(): bool
    {
        return $this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'workflow_manage')
            && $this->security->isGranted(ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE, 'tl_workflow::sourceFile');
    }
}
