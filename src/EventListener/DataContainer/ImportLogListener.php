<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\DataContainer;
use Psimandl\WorkflowBundle\Service\ImportLogRenderer;

/**
 * Puts the import log into the workflow's edit mask, in its own (collapsed) section.
 *
 * This is the answer to "how did it get like this?". An import's outcome depends on the runs
 * before it – row numbers follow the file, answered entries stay frozen, the mode decides
 * about entries a run did not see – so a mistake can surface two runs after it was made.
 * Reading it back off the data is guesswork; here it is written down.
 *
 * The table itself comes from ImportLogRenderer, which also feeds the dialog in the overview:
 * two renderings of the same record would be two answers to the same question.
 *
 * A pseudo field (input_field_callback, no column of its own), resolved by class name via
 * System::importStatic() – the service must therefore be public.
 */
class ImportLogListener
{
    public function __construct(private readonly ImportLogRenderer $renderer)
    {
    }

    public function render(DataContainer $dc): string
    {
        [, $description] = $this->renderer->heading();

        // A field rendered by an input_field_callback is inserted raw: Contao adds no widget
        // wrapper around it and does not apply the field's tl_class. The block has to bring
        // its own "clr", or it slides up next to any preceding half-width field and covers
        // it (".w50" is a left float in the back end theme).
        //
        // No heading: the section legend above already says "Importprotokoll".
        return '<div class="widget clr wf-import-log">'
            .('' !== $description ? '<p class="tl_help" style="margin:0 0 .6em">'.$description.'</p>' : '')
            .$this->renderer->render((int) ($dc->id ?? 0))
            .'</div>';
    }
}
