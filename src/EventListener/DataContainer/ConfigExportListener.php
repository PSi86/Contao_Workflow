<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\Image;
use Contao\StringUtil;
use Symfony\Component\Routing\RouterInterface;

/**
 * Renders the "download configuration" operation in the workflow list (workflow_manage):
 * a per-row link to the WorkflowActionController::exportConfig route (the same JSON export
 * as before), carrying the request token the route expects.
 */
class ConfigExportListener
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public function renderButton(array $row, ?string $href, string $label, string $title, ?string $icon, string $attributes): string
    {
        $url = $this->router->generate('workflow_export_config', ['id' => (int) $row['id']])
            .'?rt='.$this->csrfTokenManager->getDefaultTokenValue();

        return sprintf(
            '<a href="%s" title="%s"%s>%s</a> ',
            StringUtil::specialchars($url),
            StringUtil::specialchars($title),
            $attributes,
            Image::getHtml((string) $icon, $label),
        );
    }
}
