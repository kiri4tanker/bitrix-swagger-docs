<?php

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

Loc::loadMessages(__FILE__);

class k4t_docs extends
	\CModule
{
	public function __construct()
	{
		$arModuleVersion = [];

		include __DIR__ . '/version.php';

		if (is_array($arModuleVersion) && array_key_exists('VERSION', $arModuleVersion)) {
			$this->MODULE_VERSION      = $arModuleVersion['VERSION'];
			$this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
		}

		$this->MODULE_ID           = 'k4t.docs';
		$this->MODULE_NAME         = Loc::getMessage('K4T_DOCS_MODULE_NAME');
		$this->MODULE_DESCRIPTION  = Loc::getMessage('K4T_DOCS_MODULE_DESCRIPTION');
		$this->MODULE_GROUP_RIGHTS = 'N';
		$this->PARTNER_NAME        = Loc::getMessage('K4T_DOCS_MODULE_PARTNER_NAME');
		$this->PARTNER_URI         = 'https://github.com/kiri4tanker';
	}

	public function doInstall()
	{
		ModuleManager::registerModule($this->MODULE_ID);
	}

	public function doUninstall()
	{
		ModuleManager::unRegisterModule($this->MODULE_ID);
	}
}
