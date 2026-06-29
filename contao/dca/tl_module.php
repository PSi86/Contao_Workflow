<?php

declare(strict_types=1);

// Palette for the workflow form front end module (registered via #[AsFrontendModule]).
$GLOBALS['TL_DCA']['tl_module']['palettes']['workflow_form'] =
    '{title_legend},name,headline,type;'
    .'{template_legend:hide},customTpl;'
    .'{protected_legend:hide},protected;'
    .'{expert_legend:hide},guests,cssID';
