<?php
/**
 * @author Vincent Petry <pvince81@owncloud.com>
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

namespace OC\SystemTag;

use \OCP\IDBConnection;

class SystemTag implements \OCP\SystemTag\ISystemTag {

	/**
	 * @var string
	 */
	private $id;

	/**
	 * @var string
	 */
	private $name;

	/**
	 * @var bool
	 */
	private $userVisible;

	/**
	 * @var bool
	 */
	private $userAssignable;

	/**
	 * Constructor.
	 *
	 * @param string $id tag id
	 * @param string $name tag name
	 * @param bool $userVisible whether the tag is user visible
	 * @param bool $userAssignable whether the tag is user assignable
	 */
	public function __construct($id, $name, $userVisible, $userAssignable) {
		$this->id = $id;
		$this->name = $name;
		$this->userVisible = $userVisible;
		$this->userAssignable = $userAssignable;
	}

	/**
	 * {%inheritdoc}
	 */
	public function getId() {
		return $this->id;
	}

	/**
	 * {%inheritdoc}
	 */
	public function getName() {
		return $this->name;
	}

	/**
	 * {%inheritdoc}
	 */
	public function isUserVisible() {
		return $this->userVisible;
	}

	/**
	 * {%inheritdoc}
	 */
	public function isUserAssignable() {
		return $this->userAssignable;
	}
}
