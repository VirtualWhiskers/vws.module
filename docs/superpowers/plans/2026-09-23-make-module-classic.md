# Make-Module-Classic Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Вторая CLI-команда `vws:make-module-classic` в модуле `vws.module`, генерирующая болванку модуля по классической структуре (RunSQLBatch + install/uninstall.sql для mysql/pgsql, события/агенты в инсталляторе, CopyDirFiles).

**Architecture:** Общий ввод (вопросы, приоритеты, валидация) выносится из `MakeModuleCommand` в трейт `ResolvesModuleConfig`; `ClassicModuleGenerator` наследует `ModuleGenerator` (приватные хелперы → protected) и переопределяет `generate()`/`installerContent()`; классический контент — новые stubs в `stubs/classic/` + маркерные тела методов.

**Tech Stack:** Bitrix D7, symfony/console 7.x, PHP 8.2+.

**Спецификация:** `docs/superpowers/specs/2026-09-23-make-module-classic-design.md`

## Global Constraints

- Репозиторий: `/mnt/c/Web/git/vws.module`; рабочий экземпляр сайта появится через symlink, реализация и проверка структуры — в репозитории.
- Стиль: `declare(strict_types=1)`, TABS, типизация, trailing commas, `unset(...)` — каждый аргумент на своей строке с запятой после последнего; без комментариев в коде (instructional-комментарии внутри stubs допустимы — это генерируемый контент).
- PHP 8.2+ (никаких 8.4-only функций). Трейт-константы разрешены (PHP 8.2).
- Отчёт/cleanup/валидация генератора не меняются: отказ при существующем модуле, best-effort `removeDirRecursive` при сбое, per-file "created:" строки.
- `migration_config.json` и `install/migrations/` классической командой НЕ создаются.
- Без PHP CLI в этой оболочке: шаги `[Win]` выполняет пользователь; реализователь проверяет файлы чтением (побайтово против плана, табы).

---

### Task 1: Трейт ResolvesModuleConfig + рефакторинг MakeModuleCommand + перенос lang-ключей

**Files:**
- Create: `lib/Cli/Command/Concerns/ResolvesModuleConfig.php`
- Create: `lang/ru/lib/Cli/Command/Concerns/ResolvesModuleConfig.php`
- Create: `lang/en/lib/Cli/Command/Concerns/ResolvesModuleConfig.php`
- Modify: `lib/Cli/Command/MakeModuleCommand.php`
- Modify: `lang/ru/lib/Cli/Command/MakeModuleCommand.php`
- Modify: `lang/en/lib/Cli/Command/MakeModuleCommand.php`
- Modify: `.settings.php` (без изменений содержимого; задача-якорь регресса)

**Interfaces:**
- Produces (трейт, используется обеими командами): `private const ID_PATTERN`, `private function resolveConfig(InputInterface $input, OutputInterface $output, string $moduleId): ModuleConfig`, `private static function validateInstallDirs(array $dirs): array`, `private function message(string $langKey, array $replaces): string`, `private function msg(string $langKey): string`.

ВАЖНО (Loc-тонкость): `Loc::getMessage` без `loadMessages` ищет lang-файл по файлу вызова — для методов трейта это файл трейта. Все общие ключи (вопросы, ошибки) переезжают в lang-файлы трейта; у каждой команды в её собственном lang-файле остаётся только её `CMD_DESCRIPTION`.

- [ ] **Step 1: lib/Cli/Command/Concerns/ResolvesModuleConfig.php** — скопировать из `MakeModuleCommand.php:21-31,91-220` без изменений тел: константа `ID_PATTERN`, методы `resolveConfig`, `validateInstallDirs`, `message`, `msg`. Каркас:

```php
<?php

declare(strict_types=1);

namespace Vws\Module\Cli\Command\Concerns;

use Bitrix\Main\Localization\Loc;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Vws\Module\Cli\Dto\ConfigFileReader;
use Vws\Module\Cli\Dto\ModuleConfig;

trait ResolvesModuleConfig
{
	private const ID_PATTERN = '/^[a-z][a-z0-9_]*\\.[a-z][a-z0-9_]*$/';

	// сюда переносятся resolveConfig(), validateInstallDirs(), message(), msg()
	// телами из MakeModuleCommand.php:91-220 без единого изменения содержимого
}
```

- [ ] **Step 2: lang-файлы трейта** — `lang/ru/lib/Cli/Command/Concerns/ResolvesModuleConfig.php` (перенести из `lang/ru/lib/Cli/Command/MakeModuleCommand.php` все ключи КРОМЕ `VWS_MODULE_CMD_DESCRIPTION`): `VWS_MODULE_INVALID_ID`, `VWS_MODULE_TARGET_EXISTS`, `VWS_MODULE_Q_NAME`, `VWS_MODULE_Q_DESCRIPTION`, `VWS_MODULE_Q_VERSION`, `VWS_MODULE_Q_TABLES`, `VWS_MODULE_Q_EVENTS`, `VWS_MODULE_Q_AGENTS`, `VWS_MODULE_Q_INSTALL_DIRS`, `VWS_MODULE_Q_PUBLIC`, `VWS_MODULE_Q_CLI`, `VWS_MODULE_Q_OPTIONS`, `VWS_MODULE_ERR_CONFIG` — те же значения строк. `lang/en/...` — английское зеркало (перенести из `lang/en/lib/Cli/Command/MakeModuleCommand.php`).

- [ ] **Step 3: рефакторинг MakeModuleCommand** — удалить из класса: `ID_PATTERN`, `resolveConfig`, `validateInstallDirs`, `message`, `msg`; добавить внутрь класса:

```php
	use ResolvesModuleConfig;
```

(импорт трейта: `use Vws\Module\Cli\Command\Concerns\ResolvesModuleConfig;` не нужен — трейт в субнеймспейсе текущего `namespace Vws\Module\Cli\Command;` + `Concerns` → ссылка `use Concerns\ResolvesModuleConfig;` внутри класса недопустима; правильная строка внутри класса: `use ResolvesModuleConfig;` при добавленном файловом импорте `use Vws\Module\Cli\Command\Concerns\ResolvesModuleConfig;` НЕ требуется, т.к. относительное имя `ResolvesModuleConfig` резолвится как `Vws\Module\Cli\Command\ResolvesModuleConfig` — НЕПРАВИЛЬНО). Итоговое решение: внутри класса `use Concerns\ResolvesModuleConfig;` недопустимо (use в классе — только имя трейта). Так как класс в `Vws\Module\Cli\Command`, а трейт в `Vws\Module\Cli\Command\Concerns`, в классе пишем:

```php
	use Concerns\ResolvesModuleConfig;
```

— это НЕ сработает. Правильно: в начале файла добавить file-импорт `use Vws\Module\Cli\Command\Concerns\ResolvesModuleConfig;`, внутри класса — `use ResolvesModuleConfig;`.

Удалить ставшие лишними file-импорты: `Bitrix\Main\Localization\Loc`, `Symfony\Component\Console\Question\ConfirmationQuestion`, `Symfony\Component\Console\Question\Question`, `Vws\Module\Cli\Dto\ConfigFileReader`, `Vws\Module\Cli\Dto\ModuleConfig`. Остаются: `Symfony\Component\Console\Command\Command`, `InputArgument`, `InputInterface`, `InputOption`, `OutputInterface`, `Vws\Module\Cli\Generator\ModuleGenerator` + импорт трейта.

- [ ] **Step 4: lang-файлы MakeModuleCommand** — в `lang/ru/lib/Cli/Command/MakeModuleCommand.php` оставить только `VWS_MODULE_CMD_DESCRIPTION`; в `lang/en/...` — только английский `VWS_MODULE_CMD_DESCRIPTION`.

- [ ] **Step 5: Проверка**

Реализователь: побайтовое сравнение перенесённых методов/ключей с исходником; `grep -n "resolveConfig\|validateInstallDirs\|function msg\|function message" lib/Cli/Command/MakeModuleCommand.php` — 0 совпадений; `grep -c "VWS_MODULE" lang/ru/lib/Cli/Command/Concerns/ResolvesModuleConfig.php` = 13, в командном lang = 1.
`[Win]` (пользователь, в конце всех задач): `php bitrix/bitrix.php vws:make-module vws.gen1 -n` — поведение без изменений.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "refactor: extract ResolvesModuleConfig trait from MakeModuleCommand"
```

---

### Task 2: ModuleGenerator private → protected

**Files:**
- Modify: `lib/Cli/Generator/ModuleGenerator.php`

**Interfaces:**
- Produces: `protected readonly ModuleConfig $config`; protected-методы `placeholders()`, `renderStub()`, `writeFiles()`, `defaultOptionContent()`, `optionsLangContent()`, `installerContent()`, `removeDirRecursive()`. `generate()` остаётся public (переопределяется потомком).

- [ ] **Step 1:** заменить модификатор `private` на `protected` у перечисленных членов (тела не трогать). Проверка: `grep -n "private" lib/Cli/Generator/ModuleGenerator.php` — 0 совпадений.

- [ ] **Step 2: Commit**

```bash
git add -A && git commit -m "refactor: open ModuleGenerator for ClassicModuleGenerator inheritance"
```

---

### Task 3: Classic stubs + ClassicModuleGenerator

**Files:**
- Create: `lib/Cli/Generator/stubs/classic/install_index.stub`
- Create: `lib/Cli/Generator/stubs/classic/mysql_install.stub`
- Create: `lib/Cli/Generator/stubs/classic/pgsql_install.stub`
- Create: `lib/Cli/Generator/stubs/classic/mysql_uninstall.stub`
- Create: `lib/Cli/Generator/stubs/classic/pgsql_uninstall.stub`
- Create: `lib/Cli/Generator/ClassicModuleGenerator.php`

**Interfaces:**
- Consumes: protected-члены `ModuleGenerator` (Task 2), `ModuleConfig`.
- Produces: `ClassicModuleGenerator::__construct(ModuleConfig $config)` (наследуемый) + `public function generate(OutputInterface $output): int`; protected-методы `installerContent()` (переопределение), `installSqlFiles(): array` (4 пути ⇒ контент), `markerBody(string $marker, string $fallback, array $parts): string` (замена маркерной строки телом из частей, склеенных `\n\n`).

- [ ] **Step 1: stubs/classic/install_index.stub**

```php
<?php

declare(strict_types=1);

use Bitrix\Main\EventManager;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

class {{CLASS_VENDOR_NAME}} extends \CModule
{
	public function __construct()
	{
		$this->MODULE_ID = '{{MODULE_ID}}';
		$this->MODULE_NAME = Loc::getMessage('{{UPPER_ID}}_NAME');
		$this->MODULE_DESCRIPTION = Loc::getMessage('{{UPPER_ID}}_DESCRIPTION');

		require __DIR__ . '/version.php';

		if (isset($arModuleVersion['VERSION']))
		{
			$this->MODULE_VERSION = $arModuleVersion['VERSION'];
		}

		if (isset($arModuleVersion['VERSION_DATE']))
		{
			$this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
		}
	}

	public function DoInstall(): void
	{
		global $USER;

		/**
		 * @var CUser $USER
		 */

		if (!$USER->IsAdmin())
		{
			return;
		}

		ModuleManager::registerModule($this->MODULE_ID);

		if ($this->InstallDB())
		{
			$this->InstallFiles();
			$this->InstallEvents();
		}
	}

	public function DoUninstall(): void
	{
		global $USER;

		/**
		 * @var CUser $USER
		 */

		if (!$USER->IsAdmin())
		{
			return;
		}

		if ($this->UnInstallDB())
		{
			$this->UnInstallEvents();
			$this->UnInstallFiles();
		}

		ModuleManager::unRegisterModule($this->MODULE_ID);
	}

	public function InstallDB(): bool
	{
		global $APPLICATION;
		global $DB;
		global $errors;

		//{{INSTALL_DB_BODY}}

		return true;
	}

	public function UnInstallDB($arParams = []): bool
	{
		global $APPLICATION;
		global $DB;
		global $errors;

		//{{UNINSTALL_DB_BODY}}

		return true;
	}

	public function InstallEvents(): void
	{
		//{{INSTALL_EVENTS_BODY}}
	}

	public function UnInstallEvents(): void
	{
		//{{UNINSTALL_EVENTS_BODY}}
	}

	public function InstallFiles(): void
	{
		//{{INSTALL_FILES_BODY}}
	}

	public function UnInstallFiles(): void
	{
		//{{UNINSTALL_FILES_BODY}}
	}
}
```

- [ ] **Step 2: SQL stubs**

`classic/mysql_install.stub`:
```sql
CREATE TABLE IF NOT EXISTS {{DEFAULT_TABLE_NAME}} (
	ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
	TITLE VARCHAR(255) NOT NULL,
	CODE VARCHAR(50) DEFAULT NULL,
	CREATED_AT DATETIME NOT NULL,
	PRIMARY KEY (ID),
	UNIQUE KEY UX_{{UPPER_TABLE}}_CODE (CODE)
);
```

`classic/pgsql_install.stub`:
```sql
CREATE TABLE IF NOT EXISTS {{DEFAULT_TABLE_NAME}} (
	ID SERIAL NOT NULL,
	TITLE VARCHAR(255) NOT NULL,
	CODE VARCHAR(50) DEFAULT NULL,
	CREATED_AT TIMESTAMP NOT NULL,
	PRIMARY KEY (ID)
);

CREATE UNIQUE INDEX IF NOT EXISTS UX_{{UPPER_TABLE}}_CODE ON {{DEFAULT_TABLE_NAME}} (CODE);
```

`classic/mysql_uninstall.stub` и `classic/pgsql_uninstall.stub` (одинаковое содержимое):
```sql
DROP TABLE IF EXISTS {{DEFAULT_TABLE_NAME}};
```

- [ ] **Step 3: lib/Cli/Generator/ClassicModuleGenerator.php**

```php
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

		$content = $this->markerBody(
			'//{{INSTALL_DB_BODY}}',
			"\t\t// установка таблиц (install/db/*/install.sql) и агентов (CAgent::AddAgent)",
			$this->installDbParts(),
		);
		$content = $this->markerBody(
			'//{{UNINSTALL_DB_BODY}}',
			"\t\t// удаление таблиц (install/db/*/uninstall.sql) и агентов (CAgent::RemoveModuleAgents)",
			$this->uninstallDbParts(),
		);
		$content = $this->markerBody(
			'//{{INSTALL_EVENTS_BODY}}',
			"\t\t// подписка на события (EventManager::registerEventHandlerCompatible)",
			$this->installEventsParts(),
		);
		$content = $this->markerBody(
			'//{{UNINSTALL_EVENTS_BODY}}',
			"\t\t// отписка от событий (EventManager::unRegisterEventHandler)",
			$this->uninstallEventsParts(),
		);
		$content = $this->markerBody(
			'//{{INSTALL_FILES_BODY}}',
			"\t\t// копирование install-файлов (CopyDirFiles)",
			$this->installFilesParts(),
		);
		$content = $this->markerBody(
			'//{{UNINSTALL_FILES_BODY}}',
			"\t\t// удаление скопированных файлов (DeleteDirFilesEx)",
			$this->uninstallFilesParts(),
		);

		return $content;
	}

	private function markerBody(string $marker, string $fallback, array $parts): string
	{
		$body = $parts === [] ? $fallback : implode("\n\n", $parts);

		return str_replace("\t\t{$marker}", $body, $this->renderMarkerTarget($marker, $fallback, $body));
	}

	private function renderMarkerTarget(string $marker, string $fallback, string $body): string
	{
		unset($marker, $fallback, $body);

		return $this->content;
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
			$parts[] = "\t\tDeleteDirFilesEx('/bitrix/{$dir}/{$this->config->vendor}/');";
		}

		if ($this->config->public)
		{
			$parts[] = "\t\t// DeleteDirFilesEx('/<public path>/'); // удаление публички — вручную, чтобы не снести чужие файлы";
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
```

ВАЖНО для реализатора — план содержит два артефакта, которые надо реализовать правильно:
1. Пара `markerBody()`/`renderMarkerTarget()` выше НЕ рабочая (renderMarkerTarget возвращает несуществующее `$this->content`). Правильная реализация — метод применяет замену к уже отрендеренному контенту:

```php
	private function markerBody(string $content, string $marker, string $fallback, array $parts): string
	{
		$body = $parts === [] ? $fallback : implode("\n\n", $parts);

		return str_replace("\t\t{$marker}", $body, $content);
	}
```

а в `installerContent()` — цепочка:

```php
		$content = $this->renderStub('classic/install_index.stub', $this->placeholders());
		$content = $this->markerBody($content, '//{{INSTALL_DB_BODY}}', "\t\t// установка таблиц (install/db/*/install.sql) и агентов (CAgent::AddAgent)", $this->installDbParts());
		// ... и так далее для всех шести маркеров
```

2. Проверить escaping: в double-quoted PHP-строках `\$` даёт литеральный `$`, `\t`/`\n` — таб/перевод строки; одинарные кавычки внутри строк (например `'{$dir}'`) не конфликтуют. В comments-строках `\\{$this->nsVendorName()}\\EventHandler` даёт `\Vws\News\EventHandler` внутри комментария.

Самопроверка реализатора (обязательно, симуляцией str_replace на python3):
- tables+agents: InstallDB содержит RunSQLBatch-блок, затем строку с `// CAgent::AddAgent('\Vws\News\Agent::run();', 60);`; ни один маркер `//{{` не остался; сигнатуры/скобки не задублированы (ровно один `public function InstallDB`).
- только events: тела событий закомментированы целиком, каждая строка начинается с `// `.
- без флагов: во всех шести методах — fallback-комментарии.
- InstallFiles с `--install-dirs=components,js`: два блока CopyDirFiles (components и js) с `/bitrix/components` и `/bitrix/js`; UnInstallFiles: `DeleteDirFilesEx('/bitrix/components/vws/');` и `DeleteDirFilesEx('/bitrix/js/vws/');`.

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "feat: ClassicModuleGenerator with classic installer/sql stubs"
```

---

### Task 4: Команда MakeModuleClassicCommand + регистрация

**Files:**
- Create: `lib/Cli/Command/MakeModuleClassicCommand.php`
- Create: `lang/ru/lib/Cli/Command/MakeModuleClassicCommand.php`
- Create: `lang/en/lib/Cli/Command/MakeModuleClassicCommand.php`
- Modify: `.settings.php`

**Interfaces:**
- Consumes: трейт `ResolvesModuleConfig` (Task 1), `ClassicModuleGenerator` (Task 3).
- Produces: команда `vws:make-module-classic`.

- [ ] **Step 1: lib/Cli/Command/MakeModuleClassicCommand.php**

```php
<?php

declare(strict_types=1);

namespace Vws\Module\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vws\Module\Cli\Generator\ClassicModuleGenerator;

final class MakeModuleClassicCommand extends Command
{
	use Concerns\ResolvesModuleConfig;

	protected function configure(): void
	{
		$this
			->setName('vws:make-module-classic')
			->setDescription($this->msg('VWS_MODULE_CLASSIC_CMD_DESCRIPTION'))
			->addArgument('module', InputArgument::REQUIRED, 'Module id: vendor.name')
			->addOption('name', null, InputOption::VALUE_REQUIRED, 'Module title')
			->addOption('description', null, InputOption::VALUE_REQUIRED, 'Module description')
			->addOption('module-version', null, InputOption::VALUE_REQUIRED, 'Module version')
			->addOption('tables', null, InputOption::VALUE_NONE, 'Add install/db sql files')
			->addOption('events', null, InputOption::VALUE_NONE, 'Add commented event subscription examples')
			->addOption('agents', null, InputOption::VALUE_NONE, 'Add commented agent examples')
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

		$generator = new ClassicModuleGenerator($config);
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
```

ВАЖНО: `use Concerns\ResolvesModuleConfig;` внутри класса при отсутствии file-импорта резолвится как `Vws\Module\Cli\Command\Concerns\ResolvesModuleConfig` — что и является правильным FQN трейта. То есть file-импорт НЕ нужен, строка `use Concerns\ResolvesModuleConfig;` внутри класса корректна. (В Task 1 для `MakeModuleCommand` применить тот же вариант: `use Concerns\ResolvesModuleConfig;` без file-импорта.)

- [ ] **Step 2: lang-файлы команды**

`lang/ru/lib/Cli/Command/MakeModuleClassicCommand.php`:
```php
<?php

$MESS["VWS_MODULE_CLASSIC_CMD_DESCRIPTION"] = "Генерация болванки модуля по классической структуре (RunSQLBatch, CopyDirFiles)";
```

`lang/en/lib/Cli/Command/MakeModuleClassicCommand.php`:
```php
<?php

$MESS["VWS_MODULE_CLASSIC_CMD_DESCRIPTION"] = "Generate module skeleton using the classic structure (RunSQLBatch, CopyDirFiles)";
```

- [ ] **Step 3: .settings.php** — добавить класс в console.commands:

```php
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
```

- [ ] **Step 4: Проверка структуры**

`grep -c "VWS_MODULE" lang/ru/lib/Cli/Command/MakeModuleClassicCommand.php` = 1; `.settings.php` содержит оба класса; трейт подключён в обеих командах; в `MakeModuleCommand` file-импорта трейта нет (только `use Concerns\ResolvesModuleConfig;` в классе).

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: vws:make-module-classic command"
```

---

### Task 5: Версия, README, финальная проверка

**Files:**
- Modify: `install/version.php` (VERSION → `1.1.0`, VERSION_DATE → дата релиза)
- Modify: `README.md` (секция Использование: добавить вторую команду, опции `--tables/--events/--agents` с классическими описаниями, пример запуска classic)

- [ ] **Step 1: install/version.php**

```php
<?php

$arModuleVersion = [
	'VERSION' => '1.1.0',
	'VERSION_DATE' => '<дата релиза Y-m-d H:i:s>',
];
```

- [ ] **Step 2: README.md** — добавить в «Использование» перед таблицей опций:

```markdown
Модули новой структуры (Migration API) — `vws:make-module`, классической (RunSQLBatch, CopyDirFiles) — `vws:make-module-classic`:

```bash
php bitrix/bitrix.php vws:make-module-classic <vendor.name> [опции]
```
```

и в таблице опций поправить описания: `--tables` → «Классика: install/db/{mysql,pgsql}/{install,uninstall}.sql; Новая: install/migrations/tables.php», `--events`/`--agents` → «Классика: закомментированные примеры в инсталляторе; Новая: install/migrations/*.php», добавить примечание: «Классическая команда не создаёт `migration_config.json`».

- [ ] **Step 3: Commit + tag**

```bash
git add -A && git commit -m "release: 1.1.0 — classic module generator"
git tag -a 1.1.0 -m "1.1.0"
```

- [ ] **Step 4: Проверка [Win] — матрица из спецификации**

1. `php bitrix/bitrix.php list` — обе команды `vws:make-module`, `vws:make-module-classic` с описаниями.
2. `php bitrix/bitrix.php vws:make-module-classic vws.oldtest -n --tables --events --agents --public --cli --options --install-dirs=components,js` — структура (sql в db/mysql+pgsql, инсталлятор с типизированными методами и рабочими телами, нет migration_config.json).
3. То же через `--config`; интерактивный запуск; повторный запуск того же id → отказ.
4. Установка `vws.oldtest` в админке: таблица `b_vws_oldtest`, компоненты в `/bitrix/components/vws/`, js в `/bitrix/js/vws/`, публичка в корне; опции открываются/сохраняются; `php bitrix/bitrix.php vws.oldtest:demo` печатает «vws.oldtest works».
5. Деинсталляция: таблица удалена, `/bitrix/components/vws/` и `/bitrix/js/vws/` удалены.
6. Регресс: `php bitrix/bitrix.php vws:make-module vws.gen1 -n --tables --cli` — новая структура работает как раньше.

---

## Self-Review

- **Spec coverage:** трейт+рефакторинг (T1), наследование генератора (T2), классические stubs+генератор (T3), команда+lang+регистрация (T4), версия/README/матрица (T5). Типизация методов инсталлятора — в stub (T3). События/агенты — закомментированные примеры (T3 bodies). mysql+pgsql — 4 sql-стаба (T3). ✅
- **Placeholder scan:** markerBody/renderMarkerTarget из «чернового» блока заменены рабочей сигнатурой с явным предупреждением реализатору; `<дата релиза>` в version.php — единственная подстановка, берётся при исполнении. Иначе полный код. ✅
- **Type consistency:** `ClassicModuleGenerator extends ModuleGenerator`, protected-члены совпадают с T2; трейт-методы совпадают с T1; `generate(OutputInterface): int` в обеих командах. ✅
