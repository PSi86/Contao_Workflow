<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_workflow_master']['title_legend']  = 'Title';
$GLOBALS['TL_LANG']['tl_workflow_master']['master_legend'] = 'Stationery (layout, logo, variables)';

$GLOBALS['TL_LANG']['tl_workflow_master']['title']          = ['Title', 'Name of the stationery (selectable in the workflow).'];
$GLOBALS['TL_LANG']['tl_workflow_master']['masterTemplate'] = ['Layout template', 'Master template with header/footer/signature layout, e.g. pdf_master. After saving, the PDF variables matching the template are suggested as empty rows (existing ones are kept).'];
$GLOBALS['TL_LANG']['tl_workflow_master']['pdfLogo']        = ['PDF logo', 'Image file (logo for the stationery) embedded at the top of the PDF.'];
$GLOBALS['TL_LANG']['tl_workflow_master']['pdfData']        = ['PDF variables', 'Static values for the PDF. The variables matching the selected layout template appear instantly as labelled fields; additional custom variables can be added below.'];
$GLOBALS['TL_LANG']['tl_workflow_master']['pdfData_key']    = 'Variable';
$GLOBALS['TL_LANG']['tl_workflow_master']['pdfData_value']  = 'Value (multi-line allowed)';
$GLOBALS['TL_LANG']['tl_workflow_master']['pdfData_groupTemplate'] = 'Layout template variables';
$GLOBALS['TL_LANG']['tl_workflow_master']['pdfData_groupCustom']   = 'Custom variables';
$GLOBALS['TL_LANG']['tl_workflow_master']['pdfData_add']    = 'Add variable';
$GLOBALS['TL_LANG']['tl_workflow_master']['pdfData_remove'] = 'Remove';

$GLOBALS['TL_LANG']['tl_workflow_master']['edit']   = ['Edit', 'Edit the stationery.'];
$GLOBALS['TL_LANG']['tl_workflow_master']['copy']   = ['Copy', 'Copy the stationery.'];
$GLOBALS['TL_LANG']['tl_workflow_master']['delete'] = ['Delete', 'Delete the stationery.'];
$GLOBALS['TL_LANG']['tl_workflow_master']['show']   = ['Details', 'Show stationery details.'];
