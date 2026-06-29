<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\DataContainer;
use Symfony\Component\Routing\RouterInterface;

/**
 * Renders the "Vorschau" buttons in the workflow edit mask (input_field_callback
 * pseudo-fields): a PDF preview in the "PDF-Inhalt" section and a form preview in
 * the form section. Both open the corresponding read-only preview route in a new
 * tab. Resolved via System::importStatic, so this must be a public service.
 */
class PreviewButtonListener
{
    public function __construct(
        private readonly RouterInterface $router,
    ) {
    }

    public function renderPdfButton(DataContainer $dc): string
    {
        return $this->button(
            $dc,
            'workflow_preview_pdf',
            'PDF-Vorschau öffnen',
            'Öffnet das generierte PDF mit Beispieldaten (jüngster Eintrag oder Beispielwerte) in einem neuen Tab.',
        );
    }

    public function renderFormButton(DataContainer $dc): string
    {
        return $this->button(
            $dc,
            'workflow_preview_form',
            'Formular-Vorschau öffnen',
            'Öffnet eine Ansicht des Formulars mit Beispieldaten in einem neuen Tab. Der Absenden-Button ist deaktiviert.',
        );
    }

    private function button(DataContainer $dc, string $route, string $label, string $help): string
    {
        $id = (int) ($dc->id ?? 0);

        if ($id <= 0) {
            return '<div class="widget"><p class="tl_help">Bitte den Workflow zuerst speichern, dann steht die Vorschau zur Verfügung.</p></div>';
        }

        $url = $this->router->generate($route, ['id' => $id]);

        return '<div class="widget" style="clear:both">'
            .'<a href="'.htmlspecialchars($url, ENT_QUOTES).'" target="_blank" rel="noreferrer" class="tl_submit" style="text-decoration:none">'
            .htmlspecialchars($label, ENT_QUOTES).'</a>'
            .'<p class="tl_help" style="margin-top:.5em">'.htmlspecialchars($help, ENT_QUOTES).'</p>'
            .'</div>';
    }
}
