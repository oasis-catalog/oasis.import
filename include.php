<?php

CModule::AddAutoloadClasses(
	'oasis.import',
	[
		'Oasis\\Import\\CustomFields' => 'lib/form/custom_fields.php',
		'Oasis\\Import\\Main' => 'lib/main.php',
		'Oasis\\Import\\Api' => 'lib/api.php',
		'Oasis\\Import\\Oorder' => 'lib/oorder.php',
		'Oasis\\Import\\Cli' => 'lib/cli.php',
		'Oasis\\Import\\Config' => 'lib/config.php',
	]
);