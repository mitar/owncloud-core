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
use \OCP\SystemTag\TagNotFoundException;
use \OCP\SystemTag\TagAlreadyExistsException;

use \Doctrine\DBAL\Exception\UniqueConstraintViolationException;

class SystemTagManager implements \OCP\SystemTag\ISystemTagManager {

	const TAG_TABLE = 'systemtag';

	/**
	 * @var IDBConnection
	 */
	private $connection;

	/**
	 * Prepared query for selecting tags directly
	 *
	 * @var \OCP\DB\QueryBuilder\IQueryBuilder
	 */
	private $selectTagQuery;

	/**
	* Constructor.
	*
	* @param IDBConnection $connection database connection
	*/
	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;

		$query = $this->connection->getQueryBuilder();
		$this->selectTagQuery = $query->select('*')
			->from(self::TAG_TABLE)
			->where($query->expr()->eq('name', $query->createParameter('name')))
			->andWhere($query->expr()->eq('visibility', $query->createParameter('visibility')))
			->andWhere($query->expr()->eq('editable', $query->createParameter('editable')));
	}

	/**
	 * {%inheritdoc}
	 */
	public function getTagsById($tagIds) {
		if (!is_array($tagIds)) {
			$tagIds = [$tagIds];
		}

		$tags = [];

		$query = $this->connection->getQueryBuilder();
		$query->select('*')
			->from(self::TAG_TABLE)
			->where($query->expr()->in('id', $query->createParameter('tagids')))
			->addOrderBy('name', 'ASC')
			->addOrderBy('visibility', 'ASC')
			->addOrderBy('editable', 'ASC')
			->setParameter('tagids', $tagIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY);

		$result = $query->execute();
		while ($row = $result->fetch()) {
			$tags[$row['id']] = $this->createSystemTagFromRow($row);
		}

		$result->closeCursor();

		if (count($tags) !== count($tagIds)) {
			throw new TagNotFoundException(
				'Tag(s) with id(s) ' . json_encode(array_diff($tagIds, array_keys($tags))) . ' not found'
			);
		}

		return $tags;
	}

	/**
	 * {%inheritdoc}
	 */
	public function getAllTags($visibilityFilter = null, $nameSearchPattern = null) {
		$tags = [];

		$query = $this->connection->getQueryBuilder();
		$query->select('*')
			->from(self::TAG_TABLE);

		if (!is_null($visibilityFilter)) {
			$query->andWhere($query->expr()->eq('visibility', $query->createNamedParameter($visibilityFilter)));
		}

		if (!empty($nameSearchPattern)) {
			$query->andWhere(
				$query->expr()->like(
					'name',
					$query->expr()->literal('%' . $this->connection->escapeLikeParameter($nameSearchPattern). '%')
				)
			);
		}

		$query
			->addOrderBy('name', 'ASC')
			->addOrderBy('visibility', 'ASC')
			->addOrderBy('editable', 'ASC');

		$result = $query->execute();
		while ($row = $result->fetch()) {
			$tags[$row['id']] = $this->createSystemTagFromRow($row);
		}

		$result->closeCursor();

		return $tags;
	}

	/**
	 * {%inheritdoc}
	 */
	public function getTag($tagName, $userVisible, $userAssignable) {
		$userVisible = (int)$userVisible;
		$userAssignable = (int)$userAssignable;

		$result = $this->selectTagQuery
			->setParameter('name', $tagName)
			->setParameter('visibility', $userVisible)
			->setParameter('editable', $userAssignable)
			->execute();

		$row = $result->fetch();
		$result->closeCursor();
		if (!$row) {
			throw new TagNotFoundException(
				'Tag ("' . $tagName . '", '. $userVisible . ', ' . $userAssignable . ') does not exist'
			);
		}

		return $this->createSystemTagFromRow($row);
	}

	/**
	 * {%inheritdoc}
	 */
	public function createTag($tagName, $userVisible, $userAssignable) {
		$userVisible = (int)$userVisible;
		$userAssignable = (int)$userAssignable;

		$query = $this->connection->getQueryBuilder();
		$query->insert(self::TAG_TABLE)
			->values([
				'name' => $query->createNamedParameter($tagName),
				'visibility' => $query->createNamedParameter($userVisible),
				'editable' => $query->createNamedParameter($userAssignable),
			]);

		try {
			$query->execute();
		} catch (UniqueConstraintViolationException $e) {
			throw new TagAlreadyExistsException(
				'Tag ("' . $tagName . '", '. $userVisible . ', ' . $userAssignable . ') already exists',
				0,
				$e
			);
		}

		$tagId = $this->connection->lastInsertId(self::TAG_TABLE);

		return new SystemTag(
			(int)$tagId,
			$tagName,
			(bool)$userVisible,
			(bool)$userAssignable
		);
	}

	/**
	 * {%inheritdoc}
	 */
	public function updateTag($tagId, $tagName, $userVisible, $userAssignable) {
		$userVisible = (int)$userVisible;
		$userAssignable = (int)$userAssignable;

		$query = $this->connection->getQueryBuilder();
		$query->update(self::TAG_TABLE)
			->set('name', $query->createParameter('name'))
			->set('visibility', $query->createParameter('visibility'))
			->set('editable', $query->createParameter('editable'))
			->where($query->expr()->eq('id', $query->createParameter('tagid')))
			->setParameter('name', $tagName)
			->setParameter('visibility', $userVisible)
			->setParameter('editable', $userAssignable)
			->setParameter('tagid', $tagId);

		try {
			if ($query->execute() === 0) {
				throw new TagNotFoundException(
					'Tag ("' . $tagName . '", '. $userVisible . ', ' . $userAssignable . ') does not exist'
				);
			}
		} catch (UniqueConstraintViolationException $e) {
			throw new TagAlreadyExistsException(
				'Tag ("' . $tagName . '", '. $userVisible . ', ' . $userAssignable . ') already exists',
				0,
				$e
			);
		}
	}

	/**
	 * {%inheritdoc}
	 */
	public function deleteTags($tagIds) {
		if (!is_array($tagIds)) {
			$tagIds = [$tagIds];
		}

		// delete relationships first
		$query = $this->connection->getQueryBuilder();
		$query->delete(SystemTagObjectMapper::RELATION_TABLE)
			->where($query->expr()->in('systemtagid', $query->createParameter('tagids')))
			->setParameter('tagids', $tagIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)
			->execute();

		$query = $this->connection->getQueryBuilder();
		$query->delete(self::TAG_TABLE)
			->where($query->expr()->in('id', $query->createParameter('tagids')))
			->setParameter('tagids', $tagIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY);

		if ($query->execute() === 0) {
			throw new TagNotFoundException(
				'Tag does not exist'
			);
		}
	}

	private function createSystemTagFromRow($row) {
		return new SystemTag((int)$row['id'], $row['name'], (bool)$row['visibility'], (bool)$row['editable']);
	}
}
