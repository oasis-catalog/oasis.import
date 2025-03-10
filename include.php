<?php

CModule::AddAutoloadClasses(
	'oasis.import',
	[
		'Oasis\\Import\\CustomFields' => 'lib/Form/CustomFields.php',
		'Oasis\\Import\\Main' => 'lib/Main.php',
		'Oasis\\Import\\Api' => 'lib/Api.php',
		'Oasis\\Import\\Oorder' => 'lib/Oorder.php',
		'Oasis\\Import\\Cli' => 'lib/Cli.php',
		'Oasis\\Import\\Config' => 'lib/Config.php',
	]
);