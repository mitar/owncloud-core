<?php
/**
 * @author Roeland Jago Douma <rullzer@owncloud.com>
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
namespace OCA\Files_Sharing\API;

use OC\Share20\IShare;

use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Files\IRootFolder;

class Share20OCS {

	/** @var \OC\Share20\Manager */
	private $shareManager;

	/** @var IGroupManager */
	private $groupManager;

	/** @var IUserManager */
	private $userManager;

	/** @var IRequest */
	private $request;

	/** @var IRootFolder */
	private $rootFolder;

	/** @var IUrlGenerator */
	private $urlGenerator;

	/** @var IUser */
	private $currentUser;

	public function __construct(
			\OC\Share20\Manager $shareManager,
			IGroupManager $groupManager,
			IUserManager $userManager,
			IRequest $request,
			IRootFolder $rootFolder,
			IURLGenerator $urlGenerator,
			IUser $currentUser
	) {
		$this->shareManager = $shareManager;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->request = $request;
		$this->rootFolder = $rootFolder;
		$this->urlGenerator = $urlGenerator;
		$this->currentUser = $currentUser;
	}

	/**
	 * Convert an IShare to an array for OCS output
	 *
	 * @param IShare $share
	 * @return array
	 */
	protected function formatShare($share) {
		$result = [
			'id' => $share->getId(),
			'share_type' => $share->getShareType(),
			'uid_owner' => $share->getSharedBy()->getUID(),
			'displayname_owner' => $share->getSharedBy()->getDisplayName(),
			'permissions' => $share->getPermissions(),
			'stime' => $share->getShareTime(),
			'parent' => $share->getParent(),
			'expiration' => null,
			'token' => null,
		];

		$path = $share->getPath();
		$result['path'] = $this->rootFolder->getUserFolder($share->getShareOwner()->getUID())->getRelativePath($path->getPath());
		if ($path instanceOf \OCP\Files\Folder) {
			$result['item_type'] = 'folder';
		} else {
			$result['item_type'] = 'file';
		}
		$result['storage_id'] = $path->getStorage()->getId();
		$result['storage'] = \OC\Files\Cache\Storage::getNumericStorageId($path->getStorage()->getId());
		$result['item_source'] = $path->getId();
		$result['file_source'] = $path->getId();
		$result['file_parent'] = $path->getParent()->getId();
		$result['file_target'] = $share->getTarget();

		if ($share->getShareType() === \OCP\Share::SHARE_TYPE_USER) {
			$sharedWith = $share->getSharedWith();
			$result['share_with'] = $sharedWith->getUID();
			$result['share_with_displayname'] = $sharedWith->getDisplayName();
		} else if ($share->getShareType() === \OCP\Share::SHARE_TYPE_GROUP) {
			$sharedWith = $share->getSharedWith();
			$result['share_with'] = $sharedWith->getGID();
			$result['share_with_displayname'] = $sharedWith->getGID();
		} else if ($share->getShareType() === \OCP\Share::SHARE_TYPE_LINK) {

			$result['share_with'] = $share->getPassword();
			$result['share_with_displayname'] = $share->getPassword();

			$result['token'] = $share->getToken();
			$result['url'] = $this->urlGenerator->linkToRouteAbsolute('files_sharing.sharecontroller.showShare', ['token' => $share->getToken()]);

			$expiration = $share->getExpirationDate();
			if ($expiration !== null) {
				$result['expiration'] = $expiration->format('Y-m-d 00:00:00');
			}

		} else if ($share->getShareType() === \OCP\Share::SHARE_TYPE_REMOTE) {
			$result['share_with'] = $share->getSharedWith();
			$result['share_with_displayname'] = $share->getSharedWith();
			$result['token'] = $share->getToken();
		}

		$result['mail_send'] = $share->getMailSend() ? 1 : 0;

		return $result;
	}

	/**
	 * Get a specific share by id
	 *
	 * @param string $id
	 * @return \OC_OCS_Result
	 */
	public function getShare($id) {
		try {
			$share = $this->shareManager->getShareById($id);
		} catch (\OC\Share20\Exception\ShareNotFound $e) {
			return new \OC_OCS_Result(null, 404, 'wrong share ID, share doesn\'t exist.');
		}

		if ($this->canAccessShare($share)) {
			$share = $this->formatShare($share);
			return new \OC_OCS_Result($share);
		} else {
			return new \OC_OCS_Result(null, 404, 'wrong share ID, share doesn\'t exist.');
		}
	}

	/**
	 * Delete a share
	 *
	 * @param string $id
	 * @return \OC_OCS_Result
	 */
	public function deleteShare($id) {
		try {
			$share = $this->shareManager->getShareById($id);
		} catch (\OC\Share20\Exception\ShareNotFound $e) {
			return new \OC_OCS_Result(null, 404, 'wrong share ID, share doesn\'t exist.');
		}

		/*
		 * FIXME
		 * User the old code path for remote shares until we have our remoteshareprovider
		 */
		if ($share->getShareType() === \OCP\Share::SHARE_TYPE_REMOTE) {
			\OCA\Files_Sharing\API\Local::deleteShare(['id' => $id]);
		}

		if (!$this->canAccessShare($share)) {
			return new \OC_OCS_Result(null, 404, 'could not delete share');
		}

		try {
			$this->shareManager->deleteShare($share);
		} catch (\OC\Share20\Exception\BackendError $e) {
			return new \OC_OCS_Result(null, 404, 'could not delete share');
		}

		return new \OC_OCS_Result();
	}

	/**
	 * @return \OC_OCS_Result
	 */
	public function createShare() {
		$share = $this->shareManager->newShare();

		// Verify path
		$path = $this->request->getParam('path', null);
		if ($path === null) {
			return new \OC_OCS_Result(null, 404, 'please specify a file or folder path');
		}

		$userFolder = $this->rootFolder->getUserFolder($this->currentUser->getUID());
		try {
			$path = $userFolder->get($path);
		} catch (\OCP\Files\NotFoundException $e) {
			return new \OC_OCS_Result(null, 404, 'wrong path, file/folder doesn\'t exist');
		}

		$share->setPath($path);

		// Parse permissions (if available)
		$permissions = $this->request->getParam('permissions', null);
		if ($permissions === null) {
			$permissions = \OCP\Constants::PERMISSION_ALL;
		} else {
			$permissions = (int)$permissions;
		}

		if ($permissions < 0 || $permissions > \OCP\Constants::PERMISSION_ALL) {
			return new \OC_OCS_Result(null, 404, 'invalid permissions');
		}

		if ($path instanceof \OCP\Files\File) {
			// Single file shares should never have delete or create permissions
			$permissions &= ~\OCP\Constants::PERMISSION_DELETE;
			$permissions &= ~\OCP\Constants::PERMISSION_CREATE;
		}

		$shareWith = $this->request->getParam('shareWith', null);
		$shareType = (int)$this->request->getParam('shareType', '-1');

		if ($shareType === \OCP\Share::SHARE_TYPE_USER) {
			// Valid user is required to share
			if ($shareWith === null || !$this->userManager->userExists($shareWith)) {
				return new \OC_OCS_Result(null, 404, 'please specify a valid user');
			}
			$share->setSharedWith($this->userManager->get($shareWith));
			$share->setPermissions($permissions);
		} else if ($shareType === \OCP\Share::SHARE_TYPE_GROUP) {
			// Valid group is required to share
			if ($shareWith === null || !$this->groupManager->groupExists($shareWith)) {
				return new \OC_OCS_Result(null, 404, 'please specify a valid group');
			}
			$share->setSharedWith($this->groupManager->get($shareWith));
			$share->setPermissions($permissions);
		} else if ($shareType === \OCP\Share::SHARE_TYPE_LINK) {
			//Can we even share links?
			if (!$this->shareManager->shareApiAllowLinks()) {
				return new \OC_OCS_Result(null, 404, 'public link sharing is disabled by the administrator');
			}

			$publicUpload = $this->request->getParam('publicUpload', null);
			if ($publicUpload === 'true') {
				// Check if public upload is allowed
				if (!$this->shareManager->shareApiLinkAllowPublicUpload()) {
					return new \OC_OCS_Result(null, 403, '"public upload disabled by the administrator');
				}

				// Public upload can only be set for folders
				if ($path instanceof \OCP\Files\File) {
					return new \OC_OCS_Result(null, 404, '"public upload is only possible for public shared folders');
				}

				$share->setPermissions(
						\OCP\Constants::PERMISSION_READ |
						\OCP\Constants::PERMISSION_CREATE |
						\OCP\Constants::PERMISSION_UPDATE
				);
			} else {
				$share->setPermissions(\OCP\Constants::PERMISSION_READ);
			}

			// Set password
			$share->setPassword($this->request->getParam('password', null));

			//Expire date
			$expireDate = $this->request->getParam('expireDate', null);

			if ($expireDate !== null) {
				try {
					$expireDate = $this->parseDate($expireDate);
					$share->setExpirationDate($expireDate);
				} catch (\Exception $e) {
					return new \OC_OCS_Result(null, 404, 'Invalid Date. Format must be YYYY-MM-DD.');
				}
			}

		} else if ($shareType === \OCP\Share::SHARE_TYPE_REMOTE) {
			//fixme Remote shares are handled by old code path for now
			\OCA\Files_Sharing\API\Local::createShare([]);
		} else {
			return new \OC_OCS_Result(null, 400, "unknown share type");
		}

		$share->setShareType($shareType);
		$share->setSharedBy($this->currentUser);

		try {
			$share = $this->shareManager->createShare($share);
		} catch (\Exception $e) {
			return new \OC_OCS_Result(null, 404, $e->getMessage());
		}

		$share = $this->formatShare($share);
		return new \OC_OCS_Result($share);
	}

	/**
	 * @param IShare $share
	 * @return bool
	 */
	protected function canAccessShare(IShare $share) {
		// Owner of the file and the sharer of the file can always get share
		if ($share->getShareOwner() === $this->currentUser ||
			$share->getSharedBy() === $this->currentUser
		) {
			return true;
		}

		// If the share is shared with you (or a group you are a member of)
		if ($share->getShareType() === \OCP\Share::SHARE_TYPE_USER &&
			$share->getSharedWith() === $this->currentUser) {
			return true;
		}

		if ($share->getShareType() === \OCP\Share::SHARE_TYPE_GROUP &&
			$share->getSharedWith()->inGroup($this->currentUser)) {
			return true;
		}

		return false;
	}

	/**
	 * Make sure that the passed date is valid ISO 8601
	 * So YYYY-MM-DD
	 * If not throw an exception
	 *
	 * @param string $expireDate
	 *
	 * @throws \Exception
	 * @return \DateTime
	 */
	private function parseDate($expireDate) {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expireDate) === 0) {
		throw new \Exception('Invalid date. Format must be YYYY-MM-DD');
		}

		try {
			$date = new \DateTime($expireDate);
		} catch (\Exception $e) {
			throw new \Exception('Invalid date. Format must be YYYY-MM-DD');
		}

		if ($date === false) {
			throw new \Exception('Invalid date. Format must be YYYY-MM-DD');
		}

		$date->setTime(0,0,0);

		return $date;
	}
}
