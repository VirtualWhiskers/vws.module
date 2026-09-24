<?php

declare(strict_types=1);

namespace Vws\Module\Cli\Generator;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Vws\Module\Cli\Dto\ModuleConfig;

final class ClassicModuleGenerator extends ModuleGenerator
{
	public function generate(OutputInterface $output): int
	{
		$root = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . $this->config->moduleId;
		$placeholders = $this->placeholders();

		$files = array_merge(
			[
				'install/index.php' => $this->installerContent(),
				'install/version.php' => $this->renderStub('version.stub', $placeholders),
				'lang/ru/install/index.php' => $this->renderStub('lang_install.stub', $placeholders),
				'default_option.php' => $this->defaultOptionContent($this->config->options),
			],
			$this->installSqlFiles(),
		);

		foreach ($this->config->installDirs as $dir)
		{
			$files["install/{$dir}/{$this->config->vendor}/.gitkeep"] = '';
		}

		if ($this->config->public)
		{
			$files['install/public/.gitkeep'] = '';
		}

		if ($this->config->cli)
		{
			$files['.settings.php'] = $this->renderStub('settings_cli.stub', $placeholders);
			$files['lib/Cli/DemoCommand.php'] = $this->renderStub('demo_command.stub', $placeholders);
		}

		if ($this->config->options)
		{
			$files['options.php'] = $this->renderStub('options.stub', $placeholders);
			$files['lang/ru/options.php'] = $this->optionsLangContent();
		}

		try
		{
			$created = $this->writeFiles($root, $files);
		}
		catch (\RuntimeException $exception)
		{
			self::removeDirRecursive($root);
			$output->writeln("<error>{$exception->getMessage()}</error>");

			return Command::FAILURE;
		}

		foreach ($created as $path)
		{
			$output->writeln("  created: {$path}");
		}

		unset(
			$root,
			$placeholders,
			$files,
			$created,
		);

		return Command::SUCCESS;
	}

	protected function installerContent(): string
	{
		$content = $this->renderStub('classic/install_index.stub', $this->placeholders());

		$content = $this->markerBody($content, '//{{INSTALL_DB_BODY}}', "\t\t// установка таблиц (install/db/*/install.sql) и агентов (CAgent::AddAgent)", $this->installDbParts());
		$content = $this->markerBody($content, '//{{UNINSTALL_DB_BODY}}', "\t\t// удаление таблиц (install/db/*/uninstall.sql) и агентов (CAgent::RemoveModuleAgents)", $this->uninstallDbParts());
		$content = $this->markerBody($content, '//{{INSTALL_EVENTS_BODY}}', "\t\t// подписка на события (EventManager::registerEventHandlerCompatible)", $this->installEventsParts());
		$content = $this->markerBody($content, '//{{UNINSTALL_EVENTS_BODY}}', "\t\t// отписка от событий (EventManager::unRegisterEventHandler)", $this->uninstallEventsParts());
		$content = $this->markerBody($content, '//{{INSTALL_FILES_BODY}}', "\t\t// копирование install-файлов (CopyDirFiles)", $this->installFilesParts());
		$content = $this->markerBody($content, '//{{UNINSTALL_FILES_BODY}}', "\t\t// удаление скопированных файлов (DeleteDirFiles)", $this->uninstallFilesParts());

		return $content;
	}

	private function markerBody(string $content, string $marker, string $fallback, array $parts): string
	{
		$body = $parts === [] ? $fallback : implode("\n\n", $parts);

		return str_replace("\t\t{$marker}", $body, $content);
	}

	private function installDbParts(): array
	{
		$parts = [];

		if ($this->config->tables)
		{
			$parts[] = "\t\tif (\$DB->RunSQLBatch(\$_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . \$this->MODULE_ID . '/install/db/' . \$DB->type . '/install.sql') === false)\n\t\t{\n\t\t\t\$APPLICATION->ThrowException(implode('', \$errors));\n\n\t\t\treturn false;\n\t\t}";
		}

		if ($this->config->agents)
		{
			$parts[] = "\t\t// CAgent::AddAgent('\\{$this->nsVendorName()}\\Agent::run();', 60);";
		}

		return $parts;
	}

	private function uninstallDbParts(): array
	{
		$parts = [];

		if ($this->config->tables)
		{
			$parts[] = "\t\tif (\$DB->RunSQLBatch(\$_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . \$this->MODULE_ID . '/install/db/' . \$DB->type . '/uninstall.sql') === false)\n\t\t{\n\t\t\t\$APPLICATION->ThrowException(implode('', \$errors));\n\n\t\t\treturn false;\n\t\t}";
		}

		if ($this->config->agents)
		{
			$parts[] = "\t\t// CAgent::RemoveModuleAgents(\$this->MODULE_ID);";
		}

		return $parts;
	}

	private function installEventsParts(): array
	{
		if (!$this->config->events)
		{
			return [];
		}

		return [
			"\t\t// \$eventManager = EventManager::getInstance();\n\t\t//\n\t\t// \$eventManager->registerEventHandlerCompatible(\n\t\t// \t'main',\n\t\t// \t'OnPageStart',\n\t\t// \t\$this->MODULE_ID,\n\t\t// \t'\\{$this->nsVendorName()}\\EventHandler',\n\t\t// \t'handle'\n\t\t// );\n\t\t//\n\t\t// unset(\$eventManager,);",
		];
	}

	private function uninstallEventsParts(): array
	{
		if (!$this->config->events)
		{
			return [];
		}

		return [
			"\t\t// \$eventManager = EventManager::getInstance();\n\t\t//\n\t\t// \$eventManager->unRegisterEventHandler(\n\t\t// \t'main',\n\t\t// \t'OnPageStart',\n\t\t// \t\$this->MODULE_ID,\n\t\t// \t'\\{$this->nsVendorName()}\\EventHandler',\n\t\t// \t'handle'\n\t\t// );\n\t\t//\n\t\t// unset(\$eventManager,);",
		];
	}

	private function installFilesParts(): array
	{
		$parts = [];

		foreach ($this->config->installDirs as $dir)
		{
			$parts[] = "\t\tCopyDirFiles(\n\t\t\t\$_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . \$this->MODULE_ID . '/install/{$dir}',\n\t\t\t\$_SERVER['DOCUMENT_ROOT'] . '/bitrix/{$dir}',\n\t\t\ttrue,\n\t\t\ttrue\n\t\t);";
		}

		if ($this->config->public)
		{
			$parts[] = "\t\tCopyDirFiles(\n\t\t\t\$_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . \$this->MODULE_ID . '/install/public',\n\t\t\t\$_SERVER['DOCUMENT_ROOT'],\n\t\t\ttrue,\n\t\t\ttrue\n\t\t);";
		}

		return $parts;
	}

	private function uninstallFilesParts(): array
	{
		$parts = [];

		foreach ($this->config->installDirs as $dir)
		{
			$parts[] = "\t\tDeleteDirFiles(\n\t\t\t\$_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . \$this->MODULE_ID . '/install/{$dir}',\n\t\t\t\$_SERVER['DOCUMENT_ROOT'] . '/bitrix/{$dir}'\n\t\t);";
		}

		if ($this->config->public)
		{
			$parts[] = "\t\t// DeleteDirFiles(\$_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . \$this->MODULE_ID . '/install/public', \$_SERVER['DOCUMENT_ROOT']); // удаление публички — вручную, чтобы не снести чужие файлы";
		}

		return $parts;
	}

	private function installSqlFiles(): array
	{
		if (!$this->config->tables)
		{
			return [];
		}

		$placeholders = $this->placeholders();

		return [
			'install/db/mysql/install.sql' => $this->renderStub('classic/mysql_install.stub', $placeholders),
			'install/db/pgsql/install.sql' => $this->renderStub('classic/pgsql_install.stub', $placeholders),
			'install/db/mysql/uninstall.sql' => $this->renderStub('classic/mysql_uninstall.stub', $placeholders),
			'install/db/pgsql/uninstall.sql' => $this->renderStub('classic/pgsql_uninstall.stub', $placeholders),
		];
	}

	private function nsVendorName(): string
	{
		return str_replace('.', '\\', ucwords(str_replace('.', ' ', $this->config->moduleId)));
	}
}
