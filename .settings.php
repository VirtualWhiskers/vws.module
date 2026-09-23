<?php

return [
	'console' => [
		'value' => [
			'commands' => [
				\Vws\Module\Cli\Command\MakeModuleCommand::class,
			],
		],
		'readonly' => true,
	],
];
