<?php
/**
 * @author Björn Schießle <schiessle@owncloud.com>
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


namespace OCA\DAV\CardDAV;


use OCP\IDBConnection;

/**
 * Class DbHandler
 *
 * handle all db calls for the CardDav back-end
 *
 * @group DB
 * @package OCA\DAV\CardDAV
 */
class DbHandler {

	/** @var IDBConnection */
	private $connection;

	/** @var string */
	private $dbCardsTable = 'cards';

	/**
	 * DbHandler constructor.
	 *
	 * @param IDBConnection $connection
	 */
	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

	/**
	 * get URI from a given contact
	 *
	 * @param $id
	 * @return string
	 */
	public function getCardUri($id) {
		$query = $this->connection->getQueryBuilder();
		$query->select('uri')->from($this->dbCardsTable)
			->where($query->expr()->eq('id', $query->createParameter('id')))
			->setParameter('id', $id);

		$result = $query->execute()->fetch();
		return $result['uri'];
	}

}
