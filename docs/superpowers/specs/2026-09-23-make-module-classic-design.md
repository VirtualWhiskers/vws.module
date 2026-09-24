# Дизайн: команда `vws:make-module-classic` (генерация модулей старой структуры)

Дата: 2026-09-23
Статус: согласовано с пользователем в диалоге
Родительский проект: модуль `vws.module` (`/mnt/c/Web/git/vws.module`, рабочая копия через symlink в `local/modules/vws.module`)

## Цель

Вторая CLI-команда в модуле `vws.module` — `vws:make-module-classic` — генерирует болванку модуля по классической (старой) структуре: SQL-файлы через `$DB->RunSQLBatch`, события/агенты в коде инсталлятора, копирование install-файлов через `CopyDirFiles`.

## Решения, принятые с пользователем

| Вопрос | Решение |
|---|---|
| Имя команды | `vws:make-module-classic` |
| Состав старой структуры | Классика полностью: install.sql + uninstall.sql для mysql и pgsql, события в инсталляторе, агенты в инсталляторе, CopyDirFiles для install-папок и публички |
| События/агенты | Закомментированные примеры в инсталляторе (без классов-заглушек) |
| Реализация | Вариант A: отдельная команда + общий ввод (трейт) + отдельный генератор |
| Типизация генерируемых методов | `DoInstall/DoUninstall/InstallEvents/UnInstallEvents/InstallFiles/UnInstallFiles: void`; `InstallDB/UnInstallDB: bool` (false при ошибке SQL) |
| Вопросы/опции | Идентичны `vws:make-module` (те же 10 вопросов, те же опции, `--config`, `-n`, приоритет CLI → конфиг → вопросы → дефолты) |

## Архитектура

```
lib/Cli/
├── Command/
│   ├── Concerns/ResolvesModuleConfig.php   # НОВЫЙ: трейт с resolveConfig(), validateInstallDirs(), msg(), message(), ID_PATTERN
│   ├── MakeModuleCommand.php               # РЕФАКТОРИНГ: ввод переехал в трейт; configure()/execute() остаются
│   └── MakeModuleClassicCommand.php        # НОВЫЙ: configure() (vws:make-module-classic) + execute() → ClassicModuleGenerator
├── Generator/
│   ├── ModuleGenerator.php                 # без изменений
│   ├── ClassicModuleGenerator.php          # НОВЫЙ: та же механика (placeholders/renderStub/writeFiles/removeDirRecursive)
│   └── stubs/
│       ├── classic/install_index.stub      # НОВЫЙ: классический инсталлятор
│       ├── classic/install_sql.stub        # НОВЫЙ: mysql+pgsql install.sql
│       ├── classic/uninstall_sql.stub      # НОВЫЙ: mysql+pgsql uninstall.sql
│       └── (общие: version, lang_install, options, settings_cli, demo_command)  # переиспользуются как есть
lang/ru|en/lib/Cli/Command/MakeModuleClassicCommand.php     # НОВЫЕ: только VWS_MODULE_CLASSIC_CMD_DESCRIPTION (вопросы — общие ключи)
.settings.php                              # + MakeModuleClassicCommand::class
```

Переиспользование: `ModuleConfig`, `ConfigFileReader`, общие stubs — без изменений. Отчёт/cleanup, валидация id и install-dirs, отказ при существующем модуле — наследуются из общего кода.

## Поведение команды

Идентично `vws:make-module` (аргумент `vendor.name`, те же опции и вопросы, приоритет источников, `-n`), отличается только генерируемый контент. `migration_config.json` и `install/migrations/` **не** создаются.

## Генерируемый модуль (классика)

```
local/modules/<vendor.name>/
├── install/
│   ├── index.php                # class <vendor>_<name> extends \CModule (типизация — см. ниже)
│   ├── version.php
│   ├── components/<vendor>/.gitkeep       # по --install-dirs
│   ├── js/<vendor>/.gitkeep               # по --install-dirs
│   ├── public/.gitkeep                    # при --public
│   └── db/
│       ├── mysql/{install,uninstall}.sql  # при --tables
│       └── pgsql/{install,uninstall}.sql  # при --tables
├── lang/ru/install/index.php + lang/en/install/index.php
├── lang/ru|en/options.php                 # при --options
├── .settings.php + lib/Cli/DemoCommand.php  # при --cli
├── options.php                            # при --options
└── default_option.php                     # всегда (пустой массив / с SAMPLE при --options)
```

### Инсталлятор `install/index.php`

- `DoInstall(): void` — admin-check → `ModuleManager::registerModule` → `InstallDB()` → `InstallFiles()` → `InstallEvents()`.
- `DoUninstall(): void` — admin-check → `UnInstallDB()` → `UnInstallEvents()` → `UnInstallFiles()` → `ModuleManager::unRegisterModule`.
- `InstallDB(): bool` — `global $DB, $APPLICATION, $errors;`; при `--tables`:
  `if ($DB->RunSQLBatch($_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . $this->MODULE_ID . '/install/db/' . $DB->type . '/install.sql') === false) { $APPLICATION->ThrowException(implode('', $errors)); return false; }`
  при `--agents` — закомментированный пример `CAgent::AddAgent('\Vws\News\Agent::run();', 60);`; `return true;`
- `UnInstallDB($arParams = []): bool` — при `--tables` аналогично `uninstall.sql`; при `--agents` — закомментированный `CAgent::RemoveModuleAgents($this->MODULE_ID);`; `return true;`
- `InstallEvents(): void` / `UnInstallEvents(): void` — методы всегда; при `--events` — закомментированные примеры `EventManager::getInstance()->registerEventHandlerCompatible(...)` / `unRegisterEventHandler(...)` (обработчик `\Vws\News\EventHandler::handle`), иначе комментарии-заглушки.
- `InstallFiles(): void` — для каждого `--install-dirs`: `CopyDirFiles($_SERVER['DOCUMENT_ROOT'].'/local/modules/'.$this->MODULE_ID.'/install/'.$dir, $_SERVER['DOCUMENT_ROOT'].'/bitrix/'.$dir, true, true);`; при `--public`: `CopyDirFiles(...'/install/public', $_SERVER['DOCUMENT_ROOT'], true, true);`
- `UnInstallFiles(): void` — `DeleteDirFilesEx('/bitrix/'.$dir.'/')` по каждой папке; для публички — закомментированный `DeleteDirFilesEx` (чтобы не снести чужие файлы).

### SQL

- `install/db/mysql/install.sql` — `CREATE TABLE b_<vendor>_<name>` (ID INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, TITLE VARCHAR(255) NOT NULL, CODE VARCHAR(50), CREATED_AT DATETIME NOT NULL) + уникальный индекс по CODE.
- `install/db/pgsql/install.sql` — эквивалент (SERIAL/PRIMARY KEY), DROP-версии в `uninstall.sql` (`DROP TABLE IF EXISTS`).

### Переиспользуемые файлы (идентичны новой структуре)

`install/version.php`, `lang/*/install/index.php` ({{UPPER_ID}}_NAME/DESCRIPTION), `lang/*/options.php` (при `--options`), `options.php`, `default_option.php`, `.settings.php` + `lib/Cli/DemoCommand.php` (при `--cli`), папки `install/<dir>/<vendor>/.gitkeep`, `install/public/.gitkeep`.

## Обработка ошибок

Идентична `vws:make-module`: отказ при существующем модуле, валидация `--config` и install-dirs до генерации, best-effort cleanup при сбое записи, отчёт со списком файлов + подсказка «установите модуль в админке; проверьте `php bitrix/bitrix.php list`».

## Проверка

PHP CLI в WSL отсутствует — проверка на стороне Windows:

1. `php bitrix/bitrix.php vws:make-module-classic vws.oldtest -n --tables --events --agents --public --cli --options --install-dirs=components,js` — структура и содержимое файлов.
2. То же через `--config`.
3. Интерактивный запуск (вопросы те же, что у новой команды).
4. Установка `vws.oldtest` в админке: таблица `b_vws_oldtest` создана, компоненты скопированы в `/bitrix/`, js — в `/bitrix/js/`, публичка — в корень; страница опций открывается и сохраняет; `php bitrix/bitrix.php vws.oldtest:demo` работает.
5. Деинсталляция: `uninstall.sql` удаляет таблицу, файлы из `/bitrix/` удалены.
6. Регресс: `php bitrix/bitrix.php vws:make-module` работает как раньше (рефакторинг в трейт ничего не сломал).

## Вне рамок (YAGNI)

- Классы-заглушки EventHandler/Agent (только закомментированные примеры).
- Поддержка mssql/оракла в SQL-стабах.
- Общий абстрактный класс для двух команд (достаточно трейта).
