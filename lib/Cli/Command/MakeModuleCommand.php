<?php

declare(strict_types=1);

namespace Vws\Module\Cli\Command;

use Bitrix\Main\Localization\Loc;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Vws\Module\Cli\Dto\ConfigFileReader;
use Vws\Module\Cli\Dto\ModuleConfig;
use Vws\Module\Cli\Generator\ModuleGenerator;

final class MakeModuleCommand extends Command
{
	private const ID_PATTERN = '/^[a-z][a-z0-9_]*\\.[a-z][a-z0-9_]*$/';

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

	private function resolveConfig(InputInterface $input, OutputInterface $output, string $moduleId): ModuleConfig
	{
		$fileValues = [];
		$configPath = $input->getOption('config');
		if (is_string($configPath) && $configPath !== '')
		{
			$fileValues = ConfigFileReader::read($configPath);
		}

		[$vendor, $name] = explode('.', $moduleId, 2);
		$isInteractive = !$input->getOption('no-interaction') && $input->isInteractive();

		$optionValue = static fn (string $key): mixed => $input->getOption($key);
		$fileValue = static fn (string $key): mixed => $fileValues[$key] ?? null;

		$moduleName = is_string($optionValue('name')) && $optionValue('name') !== ''
			? $optionValue('name') : (is_string($fileValue('name')) ? $fileValue('name') : null);
		$description = is_string($optionValue('description')) && $optionValue('description') !== ''
			? $optionValue('description') : (is_string($fileValue('description')) ? $fileValue('description') : null);
		$version = is_string($optionValue('module-version')) && $optionValue('module-version') !== ''
			? $optionValue('module-version') : (is_string($fileValue('version')) ? $fileValue('version') : null);

		$installDirsOption = $optionValue('install-dirs');
		$installDirs = self::validateInstallDirs(
			is_string($installDirsOption) && $installDirsOption !== ''
				? array_values(array_filter(array_map('trim', explode(',', $installDirsOption))))
				: (array)($fileValues['installDirs'] ?? []),
		);

		$tables = (bool)($optionValue('tables') ?: ($fileValues['tables'] ?? false));
		$events = (bool)($optionValue('events') ?: ($fileValues['events'] ?? false));
		$agents = (bool)($optionValue('agents') ?: ($fileValues['agents'] ?? false));
		$public = (bool)($optionValue('public') ?: ($fileValues['public'] ?? false));
		$cli = (bool)($optionValue('cli') ?: ($fileValues['cli'] ?? false));
		$options = (bool)($optionValue('options') ?: ($fileValues['options'] ?? false));

		if ($isInteractive)
		{
			$helper = $this->getHelper('question');
			$askText = static function (string $message, ?string $default) use ($helper, $input, $output): string {
				$question = new Question("{$message}" . ($default !== null ? " [{$default}]" : '') . ': ', $default);

				return (string)$helper->ask($input, $output, $question);
			};
			$askBool = static function (string $message, bool $default) use ($helper, $input, $output): bool {
				$question = new ConfirmationQuestion("{$message} [y/N] ", $default);

				return (bool)$helper->ask($input, $output, $question);
			};

			$moduleName ??= $askText($this->msg('VWS_MODULE_Q_NAME'), $moduleId);
			$description ??= $askText($this->msg('VWS_MODULE_Q_DESCRIPTION'), '');
			$version ??= $askText($this->msg('VWS_MODULE_Q_VERSION'), '1.0.0');
			$tables = $askBool($this->msg('VWS_MODULE_Q_TABLES'), $tables);
			$events = $askBool($this->msg('VWS_MODULE_Q_EVENTS'), $events);
			$agents = $askBool($this->msg('VWS_MODULE_Q_AGENTS'), $agents);
			if ($installDirs === [])
			{
				$dirsRaw = $askText($this->msg('VWS_MODULE_Q_INSTALL_DIRS'), '');
				$installDirs = self::validateInstallDirs(array_values(array_filter(array_map('trim', explode(',', $dirsRaw)))));
			}
			$public = $askBool($this->msg('VWS_MODULE_Q_PUBLIC'), $public);
			$cli = $askBool($this->msg('VWS_MODULE_Q_CLI'), $cli);
			$options = $askBool($this->msg('VWS_MODULE_Q_OPTIONS'), $options);
		}

		$moduleName ??= $moduleId;
		$description ??= '';
		$version ??= '1.0.0';

		unset(
			$fileValues,
			$optionValue,
			$fileValue,
			$helper,
			$askText,
			$askBool,
			$configPath,
			$installDirsOption,
		);

		return new ModuleConfig(
			moduleId: $moduleId,
			vendor: $vendor,
			name: $name,
			moduleName: $moduleName,
			description: $description,
			version: $version,
			tables: $tables,
			events: $events,
			agents: $agents,
			installDirs: $installDirs,
			public: $public,
			cli: $cli,
			options: $options,
		);
	}

	private static function validateInstallDirs(array $dirs): array
	{
		$cleaned = [];
		foreach ($dirs as $dir)
		{
			$dir = (string)$dir;
			if (preg_match('/^[a-z0-9]([a-z0-9_.\-]*[a-z0-9])?$/i', $dir) !== 1)
			{
				throw new \InvalidArgumentException("Invalid install dir: {$dir}");
			}
			$cleaned[] = $dir;
		}

		$unique = array_values(array_unique($cleaned));

		unset(
			$dirs,
			$cleaned,
		);

		return $unique;
	}

	private function message(string $langKey, array $replaces): string
	{
		return strtr($this->msg($langKey), $replaces);
	}

	private function msg(string $langKey): string
	{
		return (string)Loc::getMessage($langKey);
	}
}
