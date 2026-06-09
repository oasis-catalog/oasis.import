<?php
CModule::AddAutoloadClasses(
	'stronglink.oasiscatalog',
	[
		'OasisCatalog\\Import\\CustomFields' => 'lib/form/custom_fields.php',
		'OasisCatalog\\Import\\Main'         => 'lib/main.php',
		'OasisCatalog\\Import\\Api'          => 'lib/api.php',
		'OasisCatalog\\Import\\Oorder'       => 'lib/oorder.php',
		'OasisCatalog\\Import\\Cli'          => 'lib/cli.php',
		'OasisCatalog\\Import\\Config'       => 'lib/config.php',
	]
);