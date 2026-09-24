# vws.module

CLI-команды для работы с модулями Bitrix: генерация болванок новых модулей — на основе интерактивных вопросов, опций командной строки или JSON-конфига.

- `vws:make-module` — модуль **новой структуры** (Migration API: `install/migrations/*`, `migration_config.json`, `$this->installMigrations()`).
- `vws:make-module-classic` — модуль **классической структуры** (`$DB->RunSQLBatch` + `install/db/{mysql,pgsql}/{install,uninstall}.sql`, события и агенты в инсталляторе, `CopyDirFiles`).

## Требования

- Bitrix: `main 26.650.0+` (API `\Bitrix\Main\UpdateSystem\Migration` — для новой структуры; классическая работает и без него)
- PHP 8.2+
- Symfony Console, подключённый к CLI (команда `php bitrix/bitrix.php` должна запускаться). Типовая схема: `composer.json` + `vendor/` в `local/php_interface/` и `require __DIR__ . '/vendor/autoload.php';` в `local/php_interface/init.php`.

## Установка

1. Скопировать модуль в `local/modules/vws.module/`.
2. Установить в админке: Marketplace → Установленные решения → `vws.module`.
3. Проверить: `php bitrix/bitrix.php list` — в списке есть `vws:make-module` и `vws:make-module-classic`.

## Использование

```bash
# новая структура (Migration API)
php bitrix/bitrix.php vws:make-module <vendor.name> [опции]

# классическая структура
php bitrix/bitrix.php vws:make-module-classic <vendor.name> [опции]
```

`vendor.name` может быть многоточечным (`vendor.name.subname`). Модуль генерируется в `local/modules/<id>/` (существующий — не перезаписывается). Набор вопросов, опций, JSON-конфига и приоритет источников у обеих команд идентичны.

### Опции

| Опция | Описание |
|---|---|
| `--name=` | Название модуля (MODULE_NAME) |
| `--description=` | Описание модуля |
| `--module-version=` | Версия (по умолчанию `1.0.0`) |
| `--tables` | Новая: `install/migrations/tables.php` + `installMigrations()` в инсталляторе. Классика: `install/db/{mysql,pgsql}/{install,uninstall}.sql` + `RunSQLBatch` в `InstallDB/UnInstallDB` |
| `--events` | Новая: `install/migrations/events.php`. Классика: закомментированные примеры `registerEventHandlerCompatible`/`unRegisterEventHandler` в инсталляторе |
| `--agents` | Новая: `install/migrations/agents.php`. Классика: закомментированные примеры `CAgent::AddAgent`/`CAgent::RemoveModuleAgents` в инсталляторе |
| `--install-dirs=components,js` | Install-папки `install/<dir>/<vendor>/`. Новая: + `installDirectoriesMapping` в `migration_config.json`. Классика: `CopyDirFiles` в `/bitrix/<dir>` при установке, `DeleteDirFiles` при удалении |
| `--public` | Публичка `install/public/`. Новая: + `publicDirectoriesMapping`. Классика: `CopyDirFiles` в корень при установке |
| `--cli` | Демо-команда в новом модуле: `.settings.php` + `lib/Cli/DemoCommand.php` (команда `<id>:demo`) |
| `--options` | Страница настроек: `options.php` + `default_option.php` с демо-опцией |
| `--config=<путь>` | Параметры из JSON-файла |
| `-n`, `--no-interaction` | Без вопросов (опции/конфиг/дефолты) |

Приоритет источников: опции CLI → `--config` → интерактивные вопросы → дефолты. Классическая команда не создаёт `migration_config.json`.

### JSON-конфиг

```json
{
  "name": "Новости",
  "description": "",
  "version": "1.0.0",
  "tables": true,
  "events": false,
  "agents": false,
  "installDirs": ["components", "js"],
  "public": false,
  "cli": true,
  "options": false
}
```

### Примеры

```bash
# интерактивно
php bitrix/bitrix.php vws:make-module vws.news

# всё сразу, без вопросов
php bitrix/bitrix.php vws:make-module vws.news -n --tables --cli --options --install-dirs=components,js

# из файла
php bitrix/bitrix.php vws:make-module vws.news --config=news.json

# классическая структура
php bitrix/bitrix.php vws:make-module-classic vws.oldmodule -n --tables --events --agents
```

## Что генерируется

Новая структура:

```
local/modules/vws.news/
├── install/
│   ├── index.php            # class vws_news extends \CModule;
│   │                        #   DoInstall: InstallDB → registerModule
│   │                        #   InstallDB → installMigrations() (при --tables)
│   ├── version.php
│   ├── components/vws/      # пустые папки вендора по --install-dirs (.gitkeep)
│   ├── public/              # при --public
│   └── migrations/
│       ├── tables.php       # пример таблицы b_vws_news через Migration API
│       ├── events.php       # закомментированный пример подписки
│       └── agents.php       # закомментированный пример агента
├── lang/ru/  +  lang/en/    # названия/описания, тексты страницы настроек
├── lib/Cli/DemoCommand.php  # рабочая команда vws.news:demo (при --cli)
├── .settings.php            # регистрация демо-команды (при --cli)
├── options.php              # страница настроек (при --options)
├── default_option.php
└── migration_config.json    # defaultTableName + маппинги папок
```

Классическая структура:

```
local/modules/vws.oldmodule/
├── install/
│   ├── index.php            # class vws_oldmodule extends \CModule;
│   │                        #   DoInstall: InstallDB → registerModule → InstallFiles → InstallEvents
│   │                        #   DoUninstall: UnInstallDB → UnInstallEvents → unRegisterModule → UnInstallFiles
│   │                        #   InstallDB/UnInstallDB: bool, RunSQLBatch по $DB->type (при --tables)
│   ├── version.php
│   ├── components/vws/      # пустые папки вендора по --install-dirs (.gitkeep)
│   ├── public/              # при --public
│   └── db/
│       ├── mysql/{install,uninstall}.sql   # при --tables
│       └── pgsql/{install,uninstall}.sql   # при --tables
├── lang/ru/  +  lang/en/
├── lib/Cli/DemoCommand.php  # при --cli
├── .settings.php            # при --cli
├── options.php              # при --options
└── default_option.php
```

После генерации установите модуль в админке: новая структура создаст таблицы через миграции, классика — через `RunSQLBatch`; при `--cli` проверьте: `php bitrix/bitrix.php vws.news:demo`.
