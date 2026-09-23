# vws.module

CLI-команды для работы с модулями Bitrix. Команда `vws:make-module` генерирует болванку нового модуля по новой структуре (Migration API: `install/migrations/*`, `migration_config.json`, `$this->installMigrations()`) — на основе интерактивных вопросов, опций командной строки или JSON-конфига.

## Требования

- Bitrix: `main 26.650.0+` (API `\Bitrix\Main\UpdateSystem\Migration`)
- PHP 8.2+
- Symfony Console, подключённый к CLI (команда `php bitrix/bitrix.php` должна запускаться). Типовая схема: `composer.json` + `vendor/` в `local/php_interface/` и `require __DIR__ . '/vendor/autoload.php';` в `local/php_interface/init.php`.

## Установка

1. Скопировать модуль в `local/modules/vws.module/`.
2. Установить в админке: Marketplace → Установленные решения → `vws.module`.
3. Проверить: `php bitrix/bitrix.php list` — в списке есть `vws:make-module`.

## Использование

```bash
php bitrix/bitrix.php vws:make-module <vendor.name> [опции]
```

Модуль генерируется в `local/modules/<vendor.name>/` (существующий — не перезаписывается).

### Опции

| Опция | Описание |
|---|---|
| `--name=` | Название модуля (MODULE_NAME) |
| `--description=` | Описание модуля |
| `--module-version=` | Версия (по умолчанию `1.0.0`) |
| `--tables` | Таблицы БД: `install/migrations/tables.php` + `installMigrations()` в инсталляторе |
| `--events` | Пример подписки на события: `install/migrations/events.php` |
| `--agents` | Пример агента: `install/migrations/agents.php` |
| `--install-dirs=components,js` | Install-папки (+ `installDirectoriesMapping` в `migration_config.json`) |
| `--public` | Публичка: `install/public/` + `publicDirectoriesMapping` |
| `--cli` | Демо-команда в новом модуле: `.settings.php` + `lib/Cli/DemoCommand.php` |
| `--options` | Страница настроек: `options.php` + `default_option.php` с демо-опцией |
| `--config=<путь>` | Параметры из JSON-файла |
| `-n`, `--no-interaction` | Без вопросов (опции/конфиг/дефолты) |

Приоритет источников: опции CLI → `--config` → интерактивные вопросы → дефолты.

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

### Пример

```bash
# интерактивно
php bitrix/bitrix.php vws:make-module vws.news

# всё сразу, без вопросов
php bitrix/bitrix.php vws:make-module vws.news -n --tables --cli --options --install-dirs=components,js

# из файла
php bitrix/bitrix.php vws:make-module vws.news --config=news.json
```

## Что генерируется

```
local/modules/vws.news/
├── install/
│   ├── index.php            # class vws_news extends \CModule;
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

После генерации установите модуль в админке — миграции создадут таблицы; при `--cli` проверьте: `php bitrix/bitrix.php vws.news:demo`.
