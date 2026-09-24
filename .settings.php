<?php

return [
	'console' => [
		'value' => [
			'commands' => [
				\Vws\Module\Cli\Command\MakeModuleCommand::class,
				\Vws\Module\Cli\Command\MakeModuleClassicCommand::class,
			],
		],
		'readonly' => true,
	],
];
