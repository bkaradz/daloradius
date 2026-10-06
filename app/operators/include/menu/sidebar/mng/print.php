<?php
/*
 *********************************************************************************************************
 * daloRADIUS - RADIUS Web Platform
 * Copyright (C) 2007 - Liran Tal <liran@lirantal.com> All Rights Reserved.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 *********************************************************************************************************
 */

if (strpos($_SERVER['PHP_SELF'], '/include/menu/sidebar/mng/print.php') !== false) {
    header("Location: ../../../../index.php");
    exit;
}

global $printBatchOptions, $selectedPrintBatch;

$batchSelect = array(
    'type' => 'select',
    'name' => 'batch_id',
    'caption' => t('sidebar', 'BatchToPrint'),
    'options' => $printBatchOptions,
    'selected_value' => $selectedPrintBatch,
);

$sections = array();
$sections[] = array(
    'title' => t('sidebar', 'BatchManagement'),
    'descriptors' => array(
        array(
            'type' => 'form',
            'title' => t('button', 'SelectBatch'),
            'action' => 'mng-print.php',
            'method' => 'GET',
            'icon' => 'printer',
            'form_components' => array($batchSelect),
        ),
    ),
);

$menu = array(
    'title' => t('sidebar', 'Management'),
    'sections' => $sections,
);
