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

namespace OC\Files\Config;

use OCP\Files\Config\ICachedMountInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Mount\IMountPoint;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;

class UserMountCache implements IUserMountCache {
	/**
	 * @var IDBConnection
	 */
	private $connection;

	/**
	 * @var IUserManager
	 */
	private $userManager;

	/** @var ICachedMountInfo[][] [$userId => [$cachedMountInfo, ....], ...] */
	private $mountsForUsers = [];

	/**
	 * UserMountCache constructor.
	 *
	 * @param IDBConnection $connection
	 * @param IUserManager $userManager
	 */
	public function __construct(IDBConnection $connection, IUserManager $userManager) {
		$this->connection = $connection;
		$this->userManager = $userManager;
	}

	public function registerMount(IUser $user, IMountPoint $mount) {
		$storage = $mount->getStorage();
		$rootId = (int)$storage->getCache()->getId('');

		$cachedMounts = $this->getMountsForUser($user);
		$inCache = array_reduce($cachedMounts, function ($inCache, ICachedMountInfo $cachedInfo) use ($rootId) {
			return $inCache || $cachedInfo->getRootId() === $rootId;
		}, false);

		if (!$inCache) {
			$this->addToCache($user, $mount, $rootId);
		}
	}

	private function addToCache(IUser $user, IMountPoint $mount, $rootId) {
		$storage = $mount->getStorage();
		$builder = $this->connection->getQueryBuilder();

		$query = $builder->insert('mounts')
			->values([
				'storage_id' => ':storage',
				'root_id' => ':root',
				'user_id' => ':user',
				'mount_point' => ':mount'
			]);
		$query->setParameters([
			':storage' => $storage->getStorageCache()->getNumericId(),
			':root' => $rootId,
			':user' => $user->getUID(),
			':mount' => $mount->getMountPoint()
		]);
		$query->execute();
	}

	private function dbRowToMountInfo(array $row) {
		$user = $this->userManager->get($row['user_id']);
		return new CachedMountInfo($user, (int)$row['storage_id'], (int)$row['root_id'], (int)$row['mount_point']);
	}

	/**
	 * @param IUser $user
	 * @return CachedMountInfo[]
	 */
	public function getMountsForUser(IUser $user) {
		if (!isset($this->mountsForUsers[$user->getUID()])) {
			$builder = $this->connection->getQueryBuilder();
			$query = $builder->select('storage_id', 'root_id', 'user_id', 'mount_point')
				->from('mounts')
				->where($builder->expr()->eq('user_id', $builder->createPositionalParameter($user->getUID())));

			$rows = $query->execute()->fetchAll();

			$this->mountsForUsers[$user->getUID()] = array_map([$this, 'dbRowToMountInfo'], $rows);
		}
		return $this->mountsForUsers[$user->getUID()];
	}

	/**
	 * @param int $numericStorageId
	 * @return CachedMountInfo[]
	 */
	public function getMountsForStorageId($numericStorageId) {
		$builder = $this->connection->getQueryBuilder();
		$query = $builder->select('storage_id', 'root_id', 'user_id', 'mount_point')
			->from('mounts')
			->where($builder->expr()->eq('storage_id', $builder->createPositionalParameter($numericStorageId, \PDO::PARAM_INT)));

		$rows = $query->execute()->fetchAll();

		return array_map([$this, 'dbRowToMountInfo'], $rows);
	}

	/**
	 * @param int $rootFileId
	 * @return CachedMountInfo[]
	 */
	public function getMountsForRootId($rootFileId) {
		$builder = $this->connection->getQueryBuilder();
		$query = $builder->select('storage_id', 'root_id', 'user_id', 'mount_point')
			->from('mounts')
			->where($builder->expr()->eq('root_id', $builder->createPositionalParameter($rootFileId, \PDO::PARAM_INT)));

		$rows = $query->execute()->fetchAll();

		return array_map([$this, 'dbRowToMountInfo'], $rows);
	}
}
