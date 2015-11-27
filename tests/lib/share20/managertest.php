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
namespace Test\Share20;

use OC\Share20\Manager;
use OC\Share20\Exception;

use OCP\ILogger;
use OCP\IConfig;
use OC\Share20\IShareProvider;
use OCP\Security\ISecureRandom;
use OCP\Security\IHasher;

class ManagerTest extends \Test\TestCase {

	/** @var Manager */
	protected $manager;

	/** @var ILogger */
	protected $logger;

	/** @var IConfig */
	protected $config;

	/** @var ISecureRandom */
	protected $secureRandom;

	/** @var IHasher */
	protected $hasher;

	/** @var IShareProvider */
	protected $defaultProvider;

	public function setUp() {
		
		$this->logger = $this->getMock('\OCP\ILogger');
		$this->config = $this->getMock('\OCP\IConfig');
		$this->defaultProvider = $this->getMock('\OC\Share20\IShareProvider');
		$this->secureRandom = $this->getMock('\OCP\Security\ISecureRandom');
		$this->hasher = $this->getMock('\OCP\Security\IHasher');

		$this->manager = new Manager(
			$this->logger,
			$this->config,
			$this->defaultProvider,
			$this->secureRandom,
			$this->hasher
		);
	}

	/**
	 * @expectedException \OC\Share20\Exception\ShareNotFound
	 */
	public function testDeleteNoShareId() {
		$share = $this->getMock('\OC\Share20\IShare');

		$share
			->expects($this->once())
			->method('getId')
			->with()
			->willReturn(null);

		$this->manager->deleteShare($share);
	}

	public function dataTestDelete() {
		$user = $this->getMock('\OCP\IUser');
		$user->method('getUID')->willReturn('sharedWithUser');

		$group = $this->getMock('\OCP\IGroup');
		$group->method('getGID')->willReturn('sharedWithGroup');
	
		return [
			[\OCP\Share::SHARE_TYPE_USER, $user, 'sharedWithUser'],
			[\OCP\Share::SHARE_TYPE_GROUP, $group, 'sharedWithGroup'],
			[\OCP\Share::SHARE_TYPE_LINK, '', ''],
			[\OCP\Share::SHARE_TYPE_REMOTE, 'foo@bar.com', 'foo@bar.com'],
		];
	}

	/**
	 * @dataProvider dataTestDelete
	 */
	public function testDelete($shareType, $sharedWith, $sharedWith_string) {
		$manager = $this->getMockBuilder('\OC\Share20\Manager')
			->setConstructorArgs([
				$this->logger,
				$this->config,
				$this->defaultProvider,
				$this->secureRandom,
				$this->hasher
			])
			->setMethods(['getShareById', 'deleteChildren'])
			->getMock();

		$sharedBy = $this->getMock('\OCP\IUser');
		$sharedBy->method('getUID')->willReturn('sharedBy');

		$path = $this->getMock('\OCP\Files\File');
		$path->method('getId')->willReturn(1);

		$share = $this->getMock('\OC\Share20\IShare');
		$share->method('getId')->willReturn(42);
		$share->method('getShareType')->willReturn($shareType);
		$share->method('getSharedWith')->willReturn($sharedWith);
		$share->method('getSharedBy')->willReturn($sharedBy);
		$share->method('getPath')->willReturn($path);
		$share->method('getTarget')->willReturn('myTarget');

		$manager->expects($this->once())->method('getShareById')->with(42)->willReturn($share);
		$manager->expects($this->once())->method('deleteChildren')->with($share);

		$this->defaultProvider
			->expects($this->once())
			->method('delete')
			->with($share);

		$hookListner = $this->getMockBuilder('Dummy')->setMethods(['pre', 'post'])->getMock();
		\OCP\Util::connectHook('OCP\Share', 'pre_unshare', $hookListner, 'pre');
		\OCP\Util::connectHook('OCP\Share', 'post_unshare', $hookListner, 'post');

		$hookListnerExpectsPre = [
			'id' => 42,
			'itemType' => 'file',
			'itemSource' => 1,
			'shareType' => $shareType,
			'shareWith' => $sharedWith_string,
			'itemparent' => null,
			'uidOwner' => 'sharedBy',
			'fileSource' => 1,
			'fileTarget' => 'myTarget',
		];

		$hookListnerExpectsPost = [
			'id' => 42,
			'itemType' => 'file',
			'itemSource' => 1,
			'shareType' => $shareType,
			'shareWith' => $sharedWith_string,
			'itemparent' => null,
			'uidOwner' => 'sharedBy',
			'fileSource' => 1,
			'fileTarget' => 'myTarget',
			'deletedShares' => [
				[
					'id' => 42,
					'itemType' => 'file',
					'itemSource' => 1,
					'shareType' => $shareType,
					'shareWith' => $sharedWith_string,
					'itemparent' => null,
					'uidOwner' => 'sharedBy',
					'fileSource' => 1,
					'fileTarget' => 'myTarget',
				],
			],
		];


		$hookListner
			->expects($this->exactly(1))
			->method('pre')
			->with($hookListnerExpectsPre);
		$hookListner
			->expects($this->exactly(1))
			->method('post')
			->with($hookListnerExpectsPost);

		$manager->deleteShare($share);
	}

	public function testDeleteNested() {
		$manager = $this->getMockBuilder('\OC\Share20\Manager')
			->setConstructorArgs([
				$this->logger,
				$this->config,
				$this->defaultProvider,
				$this->secureRandom,
				$this->hasher
			])
			->setMethods(['getShareById'])
			->getMock();

		$sharedBy1 = $this->getMock('\OCP\IUser');
		$sharedBy1->method('getUID')->willReturn('sharedBy1');
		$sharedBy2 = $this->getMock('\OCP\IUser');
		$sharedBy2->method('getUID')->willReturn('sharedBy2');
		$sharedBy3 = $this->getMock('\OCP\IUser');
		$sharedBy3->method('getUID')->willReturn('sharedBy3');

		$sharedWith1 = $this->getMock('\OCP\IUser');
		$sharedWith1->method('getUID')->willReturn('sharedWith1');
		$sharedWith2 = $this->getMock('\OCP\IGroup');
		$sharedWith2->method('getGID')->willReturn('sharedWith2');

		$path = $this->getMock('\OCP\Files\File');
		$path->method('getId')->willReturn(1);

		$share1 = $this->getMock('\OC\Share20\IShare');
		$share1->method('getId')->willReturn(42);
		$share1->method('getShareType')->willReturn(\OCP\Share::SHARE_TYPE_USER);
		$share1->method('getSharedWith')->willReturn($sharedWith1);
		$share1->method('getSharedBy')->willReturn($sharedBy1);
		$share1->method('getPath')->willReturn($path);
		$share1->method('getTarget')->willReturn('myTarget1');

		$share2 = $this->getMock('\OC\Share20\IShare');
		$share2->method('getId')->willReturn(43);
		$share2->method('getShareType')->willReturn(\OCP\Share::SHARE_TYPE_GROUP);
		$share2->method('getSharedWith')->willReturn($sharedWith2);
		$share2->method('getSharedBy')->willReturn($sharedBy2);
		$share2->method('getPath')->willReturn($path);
		$share2->method('getTarget')->willReturn('myTarget2');
		$share2->method('getParent')->willReturn(42);

		$share3 = $this->getMock('\OC\Share20\IShare');
		$share3->method('getId')->willReturn(44);
		$share3->method('getShareType')->willReturn(\OCP\Share::SHARE_TYPE_LINK);
		$share3->method('getSharedBy')->willReturn($sharedBy3);
		$share3->method('getPath')->willReturn($path);
		$share3->method('getTarget')->willReturn('myTarget3');
		$share3->method('getParent')->willReturn(43);

		$manager->expects($this->once())->method('getShareById')->with(42)->willReturn($share1);

		$this->defaultProvider
			->method('getChildren')
			->will($this->returnValueMap([
				[$share1, [$share2]],
				[$share2, [$share3]],
				[$share3, []],
			]));

		$this->defaultProvider
			->method('delete')
			->withConsecutive($share3, $share2, $share1);

		$hookListner = $this->getMockBuilder('Dummy')->setMethods(['pre', 'post'])->getMock();
		\OCP\Util::connectHook('OCP\Share', 'pre_unshare', $hookListner, 'pre');
		\OCP\Util::connectHook('OCP\Share', 'post_unshare', $hookListner, 'post');

		$hookListnerExpectsPre = [
			'id' => 42,
			'itemType' => 'file',
			'itemSource' => 1,
			'shareType' => \OCP\Share::SHARE_TYPE_USER,
			'shareWith' => 'sharedWith1',
			'itemparent' => null,
			'uidOwner' => 'sharedBy1',
			'fileSource' => 1,
			'fileTarget' => 'myTarget1',
		];

		$hookListnerExpectsPost = [
			'id' => 42,
			'itemType' => 'file',
			'itemSource' => 1,
			'shareType' => \OCP\Share::SHARE_TYPE_USER,
			'shareWith' => 'sharedWith1',
			'itemparent' => null,
			'uidOwner' => 'sharedBy1',
			'fileSource' => 1,
			'fileTarget' => 'myTarget1',
			'deletedShares' => [
				[
					'id' => 44,
					'itemType' => 'file',
					'itemSource' => 1,
					'shareType' => \OCP\Share::SHARE_TYPE_LINK,
					'shareWith' => '',
					'itemparent' => 43,
					'uidOwner' => 'sharedBy3',
					'fileSource' => 1,
					'fileTarget' => 'myTarget3',
				],
				[
					'id' => 43,
					'itemType' => 'file',
					'itemSource' => 1,
					'shareType' => \OCP\Share::SHARE_TYPE_GROUP,
					'shareWith' => 'sharedWith2',
					'itemparent' => 42,
					'uidOwner' => 'sharedBy2',
					'fileSource' => 1,
					'fileTarget' => 'myTarget2',
				],
				[
					'id' => 42,
					'itemType' => 'file',
					'itemSource' => 1,
					'shareType' => \OCP\Share::SHARE_TYPE_USER,
					'shareWith' => 'sharedWith1',
					'itemparent' => null,
					'uidOwner' => 'sharedBy1',
					'fileSource' => 1,
					'fileTarget' => 'myTarget1',
				],
			],
		];


		$hookListner
			->expects($this->exactly(1))
			->method('pre')
			->with($hookListnerExpectsPre);
		$hookListner
			->expects($this->exactly(1))
			->method('post')
			->with($hookListnerExpectsPost);

		$manager->deleteShare($share1);
	}

	public function testDeleteChildren() {
		$manager = $this->getMockBuilder('\OC\Share20\Manager')
			->setConstructorArgs([
				$this->logger,
				$this->config,
				$this->defaultProvider,
				$this->secureRandom,
				$this->hasher
			])
			->setMethods(['deleteShare'])
			->getMock();

		$share = $this->getMock('\OC\Share20\IShare');

		$child1 = $this->getMock('\OC\Share20\IShare');
		$child2 = $this->getMock('\OC\Share20\IShare');
		$child3 = $this->getMock('\OC\Share20\IShare');

		$shares = [
			$child1,
			$child2,
			$child3,
		];

		$this->defaultProvider
			->expects($this->exactly(4))
			->method('getChildren')
			->will($this->returnCallback(function($_share) use ($share, $shares) {
				if ($_share === $share) {
					return $shares;
				}
				return [];
			}));

		$this->defaultProvider
			->expects($this->exactly(3))
			->method('delete')
			->withConsecutive($child1, $child2, $child3);

		$result = $this->invokePrivate($manager, 'deleteChildren', [$share]);
		$this->assertSame($shares, $result);
	}

	public function testGetShareById() {
		$share = $this->getMock('\OC\Share20\IShare');

		$this->defaultProvider
			->expects($this->once())
			->method('getShareById')
			->with(42)
			->willReturn($share);

		$this->assertEquals($share, $this->manager->getShareById(42));
	}

	public function testCreateShareUser() {
		$share = new \OC\Share20\Share();

		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\InvalidArgumentException $e) {
			$this->assertEquals('unkown share type', $e->getMessage());
		}

		$share->setShareType(\OCP\Share::SHARE_TYPE_USER);
		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\InvalidArgumentException $e) {
			$this->assertEquals('SharedWith should be an IUser', $e->getMessage());
		}

		$sharedWith = $this->getMock('\OCP\IUser');
		$share->setSharedWith($sharedWith);
		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\InvalidArgumentException $e) {
			$this->assertEquals('SharedBy should be set', $e->getMessage());
		}

		$sharedBy = $this->getMock('\OCP\IUser');
		$share->setSharedBy($sharedBy);
		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\InvalidArgumentException $e) {
			$this->assertEquals('Path should be set', $e->getMessage());
		}

		$shareOwner = $this->getMock('\OCP\IUser');
		$path = $this->getMock('\OCP\Files\File');
		$path->method('getOwner')->willReturn($shareOwner);
		$share->setPath($path);
		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\Exception $e) {
			$this->assertEquals('Path is not shareable', $e->getMessage());
		}

		$path->method('isShareable')->willReturn(true);
		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\InvalidArgumentException $e) {
			$this->assertEquals('A share requires permissions', $e->getMessage());
		}

		$share->setPermissions(3);
		$path->method('getPermissions')->willReturn(1);
		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\Exception $e) {
			$this->assertEquals('Cannot increase permissions', $e->getMessage());
		}

		$share->setPermissions(1);
		$this->manager->createShare($share);

		$this->assertSame($shareOwner, $share->getShareOwner());
	}

	public function testCreateShareGroup() {
		$share = new \OC\Share20\Share();

		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\InvalidArgumentException $e) {
			$this->assertEquals('unkown share type', $e->getMessage());
		}

		$share->setShareType(\OCP\Share::SHARE_TYPE_GROUP);
		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\InvalidArgumentException $e) {
			$this->assertEquals('SharedWith should be an IGroup', $e->getMessage());
		}

		$sharedWith = $this->getMock('\OCP\IGroup');
		$share->setSharedWith($sharedWith);
		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\InvalidArgumentException $e) {
			$this->assertEquals('SharedBy should be set', $e->getMessage());
		}

		$sharedBy = $this->getMock('\OCP\IUser');
		$share->setSharedBy($sharedBy);
		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\InvalidArgumentException $e) {
			$this->assertEquals('Path should be set', $e->getMessage());
		}

		$shareOwner = $this->getMock('\OCP\IUser');
		$path = $this->getMock('\OCP\Files\Folder');
		$path->method('getOwner')->willReturn($shareOwner);
		$share->setPath($path);
		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\Exception $e) {
			$this->assertEquals('Path is not shareable', $e->getMessage());
		}

		$path->method('isShareable')->willReturn(true);
		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\InvalidArgumentException $e) {
			$this->assertEquals('A share requires permissions', $e->getMessage());
		}

		$share->setPermissions(3);
		$path->method('getPermissions')->willReturn(1);
		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\Exception $e) {
			$this->assertEquals('Cannot increase permissions', $e->getMessage());
		}

		$share->setPermissions(1);
		$this->manager->createShare($share);

		$this->assertSame($shareOwner, $share->getShareOwner());
	}

	public function testCreateShareLink() {
		$this->secureRandom->method('getMediumStrengthGenerator')
				->will($this->returnSelf());
		$this->secureRandom->method('generate')->willReturn('token');

		$share = new \OC\Share20\Share();

		$this->config->method('getAppValue')->will($this->returnValueMap([
			['core', 'shareapi_allow_links', 'yes', 'yes'],
		]));

		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\InvalidArgumentException $e) {
			$this->assertEquals('unkown share type', $e->getMessage());
		}

		$share->setShareType(\OCP\Share::SHARE_TYPE_LINK);

		$sharedBy = $this->getMock('\OCP\IUser');
		$share->setSharedBy($sharedBy);
		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\InvalidArgumentException $e) {
			$this->assertEquals('Path should be set', $e->getMessage());
		}

		$shareOwner = $this->getMock('\OCP\IUser');
		$path = $this->getMock('\OCP\Files\Folder');
		$path->method('getOwner')->willReturn($shareOwner);
		$share->setPath($path);
		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\Exception $e) {
			$this->assertEquals('Path is not shareable', $e->getMessage());
		}

		$path->method('isShareable')->willReturn(true);
		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\InvalidArgumentException $e) {
			$this->assertEquals('A share requires permissions', $e->getMessage());
		}

		$share->setPermissions(3);
		$path->method('getPermissions')->willReturn(1);
		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\Exception $e) {
			$this->assertEquals('Cannot increase permissions', $e->getMessage());
		}

		$share->setPermissions(1);
		$this->manager->createShare($share);

		$this->assertSame($shareOwner, $share->getShareOwner());
		$this->assertSame('token', $share->getToken());
	}

	public function testCreateShareLinkExpirationPast() {
		$this->secureRandom->method('getMediumStrengthGenerator')
			->will($this->returnSelf());
		$this->secureRandom->method('generate')->willReturn('token');

		$share = new \OC\Share20\Share();

		$this->config->method('getAppValue')->will($this->returnValueMap([
			['core', 'shareapi_allow_links', 'yes', 'yes'],
		]));

		$share->setShareType(\OCP\Share::SHARE_TYPE_LINK);

		$past = new \DateTime();
		$past->sub(new \DateInterval('P1D'));

		$share->setExpirationDate($past);
		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\InvalidArgumentException $e) {
			$this->assertEquals('Expiration date is in the past', $e->getMessage());
		}

		$this->assertEquals('00:00:00', $past->format('H:i:s'));
	}

	public function testCreateShareLinkExpirationToFarInFuture() {
		$this->secureRandom->method('getMediumStrengthGenerator')
				->will($this->returnSelf());
		$this->secureRandom->method('generate')->willReturn('token');

		$share = new \OC\Share20\Share();

		$share->setShareType(\OCP\Share::SHARE_TYPE_LINK);

		$future = new \DateTime();
		$future->add(new \DateInterval('P4D'));

		$this->config->method('getAppValue')->will($this->returnValueMap([
			['core', 'shareapi_allow_links', 'yes', 'yes'],
			['core', 'shareapi_enforce_expire_date', 'no', 'yes'],
			['core', 'shareapi_expire_after_n_days', '7', '3'],
		]));

		$share->setExpirationDate($future);
		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\InvalidArgumentException $e) {
			$this->assertEquals('Cannot set expiration date more than 3 in the future', $e->getMessage());
		}

		$this->assertEquals('00:00:00', $future->format('H:i:s'));
	}

	public function testCreateShareLinkDefaultExpiration() {
		$this->secureRandom->method('getMediumStrengthGenerator')
				->will($this->returnSelf());
		$this->secureRandom->method('generate')->willReturn('token');

		$share = new \OC\Share20\Share();

		$share->setShareType(\OCP\Share::SHARE_TYPE_LINK);

		$this->config->method('getAppValue')->will($this->returnValueMap([
			['core', 'shareapi_allow_links', 'yes', 'yes'],
			['core', 'shareapi_default_expire_date', 'no', 'yes'],
			['core', 'shareapi_expire_after_n_days', '7', '3'],
		]));

		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\InvalidArgumentException $e) {

		}

		$future = new \DateTime();
		$future->add(new \DateInterval('P3D'));
		$future->setTime(0,0,0);

		$this->assertEquals($future, $share->getExpirationDate());
	}

	public function testCreateShareLinkPasswordEnforced() {
		$this->secureRandom->method('getMediumStrengthGenerator')
				->will($this->returnSelf());
		$this->secureRandom->method('generate')->willReturn('token');

		$share = new \OC\Share20\Share();

		$share->setShareType(\OCP\Share::SHARE_TYPE_LINK);

		$this->config->method('getAppValue')->will($this->returnValueMap([
			['core', 'shareapi_allow_links', 'yes', 'yes'],
			['core', 'shareapi_enforce_links_password', 'no', 'yes'],
		]));

		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\InvalidArgumentException $e) {
			$this->assertEquals('Passwords are enforced for link shares', $e->getMessage());
		}

		$share->setPassword('password');
		$this->hasher->expects($this->once())
			->method('hash')
			->with('password')
			->willReturn('hashedPassword');

		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\InvalidArgumentException $e) {}

		$this->assertEquals('hashedPassword', $share->getPassword());
	}

	public function testCreateShareLinkNotAllowed() {
		$share = new \OC\Share20\Share();

		$share->setShareType(\OCP\Share::SHARE_TYPE_LINK);

		try {
			$this->manager->createShare($share);
			$this->fail();
		} catch (\Exception $e) {
			$this->assertEquals('Link sharing not allowed', $e->getMessage());
		}
	}
}
