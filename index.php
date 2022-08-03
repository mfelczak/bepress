<?php

/**
 * @defgroup plugins_importexport_bpress
 */

/**
 * @file plugins/importexport/bepress/index.php
 *
 * Copyright (c) 2017-2022 Simon Fraser University
 * Copyright (c) 2017-2022 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @ingroup plugins_importexport_bepress
 * @brief Wrapper for Bepress import plugin
 *
 */

require_once 'BepressImportPlugin.inc.php';

error_reporting(E_ERROR);
return new BepressImportPlugin();
