<?php

declare(strict_types=1);

namespace Vws\Module\Cli\Command;

use Bitrix\Main\Localization\Loc;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vws\Module\Cli\Command\Concerns\ResolvesModuleConfig;
use Vws\Module\Cli\Generator\ModuleGenerator;

final class MakeModuleCommand extends Command
{
	use ResolvesModuleConfig;

	protected function configure(): void
	{
		$this
			->setName('vws:make-module')
			->setDescription($this->msg('VWS_MODULE_CMD_DESCRIPTION'))
			->addArgument('module', InputArgument::REQUIRED, 'Module id: vendor.name')
			->addOption('name', null, InputOption::VALUE_REQUIRED, 'Module title')
			->addOption('description', null, InputOption::VALUE_REQUIRED, 'Module description')
			->addOption('module-version', null, InputOption::VALUE_REQUIRED, 'Module version')
			->addOption('tables', null, InputOption::VALUE_NONE, 'Add install/migrations/tables.php')
			->addOption('events', null, InputOption::VALUE_NONE, 'Add install/migrations/events.php')
			->addOption('agents', null, InputOption::VALUE_NONE, 'Add install/migrations/agents.php')
			->addOption('public', null, InputOption::VALUE_NONE, 'Add install/public')
			->addOption('cli', null, InputOption::VALUE_NONE, 'Add own console command demo')
			->addOption('options', null, InputOption::VALUE_NONE, 'Add admin options.php')
			->addOption('install-dirs', null, InputOption::VALUE_REQUIRED, 'Install dirs, comma separated: components,js')
			->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to JSON config file')
			->addOption('no-interaction', 'n', InputOption::VALUE_NONE, 'Do not ask questions')
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$moduleId = (string)$input->getArgument('module');
		if (preg_match(self::ID_PATTERN, $moduleId) !== 1)
		{
			$output->writeln($this->message('VWS_MODULE_INVALID_ID', ['{{ID}}' => $moduleId]));

			return self::FAILURE;
		}

		$targetDir = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . $moduleId;
		if (is_dir($targetDir))
		{
			$output->writeln($this->message('VWS_MODULE_TARGET_EXISTS', ['{{ID}}' => $moduleId, '{{PATH}}' => $targetDir]));

			return self::FAILURE;
		}

		try
		{
			$config = $this->resolveConfig($input, $output, $moduleId);
		}
		catch (\InvalidArgumentException $exception)
		{
			$output->writeln($this->message('VWS_MODULE_ERR_CONFIG', ['{{MESSAGE}}' => $exception->getMessage()]));

			return self::FAILURE;
		}

		$generator = new ModuleGenerator($config);
		$result = $generator->generate($output);

		if ($result === Command::SUCCESS)
		{
			$output->writeln('<info>Module generated. Install it via admin panel, then check `php bitrix/bitrix.php list`.</info>');
		}

		unset(
			$generator,
			$config,
			$moduleId,
			$targetDir,
		);

		return $result;
	}
}
