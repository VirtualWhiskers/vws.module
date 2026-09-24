<?php

declare(strict_types=1);

namespace Vws\Module\Cli\Generator;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Vws\Module\Cli\Dto\ModuleConfig;

class ModuleGenerator
{
	public function __construct(
		protected readonly ModuleConfig $config,
	) {}

	public function generate(OutputInterface $output): int
	{
		$root = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . $this->config->moduleId;
		$placeholders = $this->placeholders();

		$files = [
			'install/index.php' => $this->installerContent(),
			'install/version.php' => $this->renderStub('version.stub', $placeholders),
			'lang/ru/install/index.php' => $this->renderStub('lang_install.stub', $placeholders),
			'default_option.php' => $this->defaultOptionContent($this->config->options),
		];

		if ($this->config->tables)
		{
			$files['install/migrations/tables.php'] = $this->renderStub('migrations_tables.stub', $placeholders);
		}

		if ($this->config->events)
		{
			$files['install/migrations/events.php'] = $this->renderStub('migrations_events.stub', $placeholders);
		}

		if ($this->config->agents)
		{
			$files['install/migrations/agents.php'] = $this->renderStub('migrations_agents.stub', $placeholders);
		}

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

		if ($this->config->tables || $this->config->events || $this->config->agents
			|| $this->config->installDirs !== [] || $this->config->public)
		{
			$files['migration_config.json'] = $this->migrationConfigContent();
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

	protected function writeFiles(string $root, array $files): array
	{
		$created = [];
		foreach ($files as $relativePath => $content)
		{
			$fullPath = "{$root}/{$relativePath}";
			$dir = dirname($fullPath);
			if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir))
			{
				throw new \RuntimeException("Cannot create directory: {$dir}");
			}
			if (file_put_contents($fullPath, $content) === false)
			{
				throw new \RuntimeException("Cannot write file: {$fullPath}");
			}
			$created[] = $fullPath;
		}

		unset(
			$dir,
			$fullPath,
		);

		return $created;
	}

	protected function placeholders(): array
	{
		$upperId = strtoupper(str_replace('.', '_', $this->config->moduleId));
		$upperTable = strtoupper(str_replace('.', '_', $this->config->moduleId));

		return [
			'{{MODULE_ID}}' => $this->config->moduleId,
			'{{VENDOR}}' => $this->config->vendor,
			'{{NAME}}' => $this->config->name,
			'{{CLASS_VENDOR_NAME}}' => str_replace('.', '_', $this->config->moduleId),
			'{{NS_VENDOR_NAME}}' => str_replace(' ', '\\', ucwords(str_replace('.', ' ', $this->config->moduleId))),
			'{{UPPER_ID}}' => $upperId,
			'{{MODULE_NAME}}' => addcslashes($this->config->moduleName, "\"\\\$\r\n"),
			'{{DESCRIPTION}}' => addcslashes($this->config->description, "\"\\\$\r\n"),
			'{{VERSION}}' => $this->config->version,
			'{{DATE}}' => date('Y-m-d H:i:s'),
			'{{DEFAULT_TABLE_NAME}}' => 'b_' . str_replace('.', '_', $this->config->moduleId),
			'{{UPPER_TABLE}}' => $upperTable,
		];
	}

	protected function renderStub(string $stubFile, array $placeholders): string
	{
		$path = __DIR__ . '/stubs/' . $stubFile;
		$content = file_get_contents($path);
		if ($content === false)
		{
			throw new \RuntimeException("Stub not found: {$path}");
		}

		return strtr($content, $placeholders);
	}

	protected function installerContent(): string
	{
		$placeholders = $this->placeholders();
		$content = $this->renderStub('install_index.stub', $placeholders);

		if ($this->config->tables)
		{
			$content = str_replace(
				[
					"\t\t// установка таблиц через новую миграционную структуру (install/migrations)",
					"\t\t// удаление таблиц через новую миграционную структуру (install/migrations)",
				],
				[
					"\t\t\$this->installMigrations();",
					"\t\t\$needDropTables = ((\$arParams['savedata'] ?? null) === 'N');\n\n\t\t\$this->uninstallMigrations(\$needDropTables);",
				],
				$content,
			);
		}

		unset(
			$placeholders,
		);

		return $content;
	}

	protected function defaultOptionContent(bool $withSample): string
	{
		$entry = $withSample
			? "\t'SAMPLE' => '',"
			: "\t// 'option name' => 'value',";

		return "<?php\n\n\${$this->config->vendor}_{$this->config->name}_default_option = [\n{$entry}\n];\n";
	}

	protected function optionsLangContent(): string
	{
		$raw = <<<PHP
		<?php

		\$MESS["{{UPPER_ID}}_OPTIONS_TAB"] = "Настройки";
		\$MESS["{{UPPER_ID}}_OPTIONS_TAB_TITLE"] = "Настройки модуля";

		PHP;

		$content = strtr($raw, $this->placeholders());

		unset(
			$raw,
		);

		return $content;
	}

	private function migrationConfigContent(): string
	{
		$config = [];
		if ($this->config->tables)
		{
			$config['defaultTableName'] = 'b_' . str_replace('.', '_', $this->config->moduleId);
		}

		$installMapping = [];
		foreach ($this->config->installDirs as $dir)
		{
			if ($dir === 'public')
			{
				continue;
			}
			$installMapping["install/{$dir}"] = $dir;
		}
		if ($installMapping !== [])
		{
			$config['installDirectoriesMapping'] = $installMapping;
		}

		if ($this->config->public)
		{
			$config['publicDirectoriesMapping'] = ['install/public' => ''];
		}

		return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
	}

	protected static function removeDirRecursive(string $dir): void
	{
		if (!is_dir($dir))
		{
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($items as $item)
		{
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($dir);
	}
}
