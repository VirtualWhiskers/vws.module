<?php

declare(strict_types=1);

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

class vws_module extends \CModule
{
	public function __construct()
	{
		$this->MODULE_ID = 'vws.module';
		$this->MODULE_NAME = Loc::getMessage('VWS_MODULE_NAME');
		$this->MODULE_DESCRIPTION = Loc::getMessage('VWS_MODULE_DESCRIPTION');

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

		ModuleManager::unRegisterModule($this->MODULE_ID);
	}
}
