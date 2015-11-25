<?php
/**
 * @author Robin Appelman <icewind@owncloud.com>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Files_external\Migration;

use OCA\Files_External\Service\BackendService;
use OCA\Files_External\Service\DBConfigService;
use OCA\Files_external\Service\GlobalLegacyStoragesService;
use OCA\Files_external\Service\GlobalStoragesService;
use OCA\Files_external\Service\LegacyStoragesService;
use OCA\Files_external\Service\StoragesService;
use OCA\Files_external\Service\UserLegacyStoragesService;
use OCA\Files_external\Service\UserStoragesService;
use OCP\IConfig;
use OCP\IUserSession;

class StorageMigrator {
	/**
	 * @var BackendService
	 */
	private $backendService;

	/**
	 * @var DBConfigService
	 */
	private $dbConfig;

	/**
	 * @var IUserSession
	 */
	private $userSession;

	/**
	 * @var IConfig
	 */
	private $config;

	/**
	 * StorageMigrator constructor.
	 *
	 * @param BackendService $backendService
	 * @param DBConfigService $dbConfig
	 * @param IUserSession $userSession
	 * @param IConfig $config
	 */
	public function __construct(
		BackendService $backendService,
		DBConfigService $dbConfig,
		IUserSession $userSession,
		IConfig $config
	) {
		$this->backendService = $backendService;
		$this->dbConfig = $dbConfig;
		$this->userSession = $userSession;
		$this->config = $config;
	}

	private function migrate(LegacyStoragesService $legacyService, StoragesService $storageService) {
		$existingStorage = $legacyService->getAllStorages();

		foreach ($existingStorage as $storage) {
			$storageService->addStorage($storage);
		}
	}

	/**
	 * Migrate admin configured storages
	 */
	public function migrateGlobal() {
		$legacyService = new GlobalLegacyStoragesService($this->backendService);
		$storageService = new GlobalStoragesService($this->backendService, $this->dbConfig);

		$this->migrate($legacyService, $storageService);
	}

	/**
	 * Migrate personal storages configured by the current user
	 */
	public function migrateUser() {
		$userId = $this->userSession->getUser()->getUID();
		$userVersion = $this->config->getUserValue($userId, 'files_external', 'config_version', '0.0.0');
		if (version_compare($userVersion, '0.5.0', '<')) {
			$this->config->setUserValue($userId, 'files_external', 'config_version', '0.5.0');
			$legacyService = new UserLegacyStoragesService($this->backendService, $this->userSession);
			$storageService = new UserStoragesService($this->backendService, $this->dbConfig, $this->userSession);

			$this->migrate($legacyService, $storageService);
		}
	}
}
