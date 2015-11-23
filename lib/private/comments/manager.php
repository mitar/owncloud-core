<?php

namespace OC\Comments;

use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Comments\NotFoundException;
use OCP\IDBConnection;

class Manager implements ICommentsManager {

	/** @var  IDBConnection */
	protected $dbConn;

	public function __construct(IDBConnection $dbConn) {
		$this->dbConn = $dbConn;
	}

	/**
	 * converts data base data into PHP native, proper types as defined by
	 * IComment interface.
	 *
	 * @param array $data
	 * @return array
	 */
	protected function normalizeDatabaseData(array $data) {
		$data['creation_timestamp'] = new \DateTime($data['creation_timestamp']);
		$data['latest_child_timestamp'] = new \DateTime($data['latest_child_timestamp']);
		$data['children_count'] = intval($data['children_count']);
		return $data;
	}

	/**
	 * prepares a comment for an insert or update operation after making sure
	 * all necessary fields have a value assigned.
	 *
	 * @param IComment $comment
	 * @return IComment
	 * @throws \UnexpectedValueException
	 */
	protected function prepareCommentForDatabaseWrite(IComment $comment) {
		if(    empty($comment->getActorType())
			|| empty($comment->getActorId())
			|| empty($comment->getObjectType())
			|| empty($comment->getObjectId())
			|| empty($comment->getVerb())
		) {
			throw new \UnexpectedValueException('Actor, Object and Verb information must be provided for saving');
		}

		if($comment->getId() === '') {
			$comment->setChildrenCount(0);
			$comment->setLatestChildDateTime(new \DateTime('0000-00-00'));
		}

		if(is_null($comment->getCreationDateTime())) {
			$comment->setCreationDateTime(new \DateTime());
		}

		if($comment->getParentId() !== '0') {
			$comment->setTopmostParentId($this->determineTopmostParentId($comment->getParentId()));
		} else {
			$comment->setTopmostParentId('0');
		}

		return $comment;
	}

	/**
	 * returns the topmost parent id of a given comment identified by ID
	 *
	 * @param string $id
	 * @return string
	 * @throws NotFoundException
	 */
	protected function determineTopmostParentId($id) {
		$comment = $this->get($id);
		if($comment->getParentId() === '0') {
			return $comment->getId();
		} else {
			return $this->determineTopmostParentId($comment->getId());
		}
	}

	/**
	 * updates child information of a comment
	 *
	 * @param string	$id
	 * @param \DateTime	$cDateTime	the date time of the most recent child
	 * @throws NotFoundException
	 */
	protected function updateChildrenInformation($id, \DateTime $cDateTime) {
		$qb = $this->dbConn->getQueryBuilder();
		$query = $qb->select($qb->createFunction('COUNT(`id`)'))
				->from('comments')
				->where($qb->expr()->eq('parent_id', $qb->createParameter('id')))
				->setParameter('id', $id);

		$resultStatement = $query->execute();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();
		$children = intval($data[0]);

		$comment = $this->get($id);
		$comment->setChildrenCount($children);
		$comment->setLatestChildDateTime($cDateTime);
		$this->save($comment);
	}

	/**
	 * Tests whether actor or object type and id parameters are acceptable.
	 * Throws exception if not.
	 *
	 * @param string $role
	 * @param string $type
	 * @param string $id
	 * @throws \InvalidArgumentException
	 */
	protected function checkRoleParameters($role, $type, $id) {
		if(
			   !is_string($type) || empty($type)
			|| !is_string($id) || empty($id)
		) {
			throw new \InvalidArgumentException($role . ' parameters must be string and not empty');
		}
	}

	/**
	 * returns a comment instance
	 *
	 * @param string $id the ID of the comment
	 * @return IComment
	 * @throws NotFoundException
	 * @since 9.0.0
	 */
	public function get($id) {
		$qb = $this->dbConn->getQueryBuilder();
		$resultStatement = $qb->select('*')
			->from('comments')
			->where($qb->expr()->eq('id', $qb->createParameter('id')))
			->setParameter('id', $id)
			->execute();

		$data = $resultStatement->fetch();
		$resultStatement->closeCursor();
		if(!$data) {
			throw new NotFoundException();
		}

		return new Comment($this->normalizeDatabaseData($data));
	}

	/**
	 * returns the comment specified by the id and all it's child comments.
	 * At this point of time, we do only support one level depth.
	 *
	 * @param string $id
	 * @param int $limit max number of entries to return, 0 returns all
	 * @param int $offset the start entry
	 * @return array
	 * @since 9.0.0
	 *
	 * The return array looks like this
	 * [
	 *   'comment' => IComment, // root comment
	 *   'replies' =>
	 *   [
	 *     0 =>
	 *     [
	 *       'comment' => IComment,
	 *       'replies' => []
	 *     ]
	 *     1 =>
	 *     [
	 *       'comment' => IComment,
	 *       'replies'=> []
	 *     ],
	 *     …
	 *   ]
	 * ]
	 */
	public function getTree($id, $limit = 0, $offset = 0) {
		$tree = [];
		$tree['comment'] = $this->get($id);
		$tree['replies'] = [];

		$qb = $this->dbConn->getQueryBuilder();
		$query = $qb->select('*')
				->from('comments')
				->where($qb->expr()->eq('topmost_parent_id', $qb->createParameter('id')))
				->orderBy('creation_timestamp', 'DESC')
				->setParameter('id', $id);

		if($limit > 0) {
			$query->setMaxResults($limit);
		}
		if($offset > 0) {
			$query->setFirstResult($offset);
		}

		$resultStatement = $query->execute();
		while($data = $resultStatement->fetch()) {
			$tree['replies'][] = [
				'comment' => new Comment($this->normalizeDatabaseData($data)),
				'replies' => []
			];
		}
		$resultStatement->closeCursor();

		return $tree;
	}

	/**
	 * returns comments for a specific object (e.g. a file).
	 *
	 * The sort order is always newest to oldest.
	 *
	 * @param string $objectType the object type, e.g. 'files'
	 * @param string $objectId the id of the object
	 * @param int $limit optional, number of maximum comments to be returned. if
	 * not specified, all comments are returned.
	 * @param int $offset optional, starting point
	 * @param \DateTime $notOlderThan optional, timestamp of the oldest comments
	 * that may be returned
	 * @return IComment[]
	 * @since 9.0.0
	 */
	public function getForObject(
			$objectType,
			$objectId,
			$limit = 0,
			$offset = 0,
			\DateTime $notOlderThan = null
	) {
		$comments = [];

		$qb = $this->dbConn->getQueryBuilder();
		$query = $qb->select('*')
				->from('comments')
				->where($qb->expr()->eq('object_type', $qb->createParameter('type')))
				->andWhere($qb->expr()->eq('object_id', $qb->createParameter('id')))
				->orderBy('creation_timestamp', 'DESC')
				->setParameter('type', $objectType)
				->setParameter('id', $objectId);

		if($limit > 0) {
			$query->setMaxResults($limit);
		}
		if($offset > 0) {
			$query->setFirstResult($offset);
		}
		if(!is_null($notOlderThan)) {
			$query
				->andWhere($qb->expr()->gt('creation_timestamp', $qb->createParameter('notOlderThan')))
				->setParameter('notOlderThan', $notOlderThan, 'datetime');
		}

		$resultStatement = $query->execute();
		while($data = $resultStatement->fetch()) {
			$comments[] = new Comment($this->normalizeDatabaseData($data));
		}
		$resultStatement->closeCursor();

		return $comments;
	}

	/**
	 * @param $objectType string the object type, e.g. 'files'
	 * @param $objectId string the id of the object
	 * @return Int
	 * @since 9.0.0
	 */
	public function getNumberOfCommentsForObject($objectType, $objectId) {
		$qb = $this->dbConn->getQueryBuilder();
		$query = $qb->select($qb->createFunction('COUNT(`id`)'))
				->from('comments')
				->where($qb->expr()->eq('object_type', $qb->createParameter('type')))
				->andWhere($qb->expr()->eq('object_id', $qb->createParameter('id')))
				->setParameter('type', $objectType)
				->setParameter('id', $objectId);

		$resultStatement = $query->execute();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();
		return intval($data[0]);
	}

	/**
	 * creates a new comment and returns it. At this point of time, it is not
	 * saved in the used data storage. Use save() after setting other fields
	 * of the comment (e.g. message or verb).
	 *
	 * @param string $actorType the actor type (e.g. 'user')
	 * @param string $actorId a user id
	 * @param string $objectType the object type the comment is attached to
	 * @param string $objectId the object id the comment is attached to
	 * @return IComment
	 * @throws \InvalidArgumentException
	 * @since 9.0.0
	 */
	public function create($actorType, $actorId, $objectType, $objectId) {
		if(
			   !is_string($actorType)  || empty($actorType)
			|| !is_string($actorId)    || empty($actorId)
		    || !is_string($objectType) || empty($objectType)
		    || !is_string($objectId)   || empty($objectId)
		) {
			// unsure whether it's a good place to enforce it here, since the
			// comment instance can be manipulated anyway.
			throw new \InvalidArgumentException('All arguments must be non-empty strings');
		}
		$comment = new Comment();
		$comment
			->setActor($actorType, $actorId)
			->setObject($objectType, $objectId);
		return $comment;
	}

	/**
	 * permanently deletes the comment specified by the ID
	 *
	 * When the comment has child comments, their parent ID will be changed to
	 * the parent ID of the item that is to be deleted.
	 *
	 * @param string $id
	 * @return bool
	 * @throws \InvalidArgumentException
	 * @since 9.0.0
	 */
	public function delete($id) {
		if(!is_string($id)) {
			throw new \InvalidArgumentException('Parameter must be string');
		}

		$qb = $this->dbConn->getQueryBuilder();
		$query = $qb->delete('comments')
			->where($qb->expr()->eq('id', $qb->createParameter('id')))
			->setParameter('id', $id);

		$affectedRows = $query->execute();
		return ($affectedRows > 0);
	}

	/**
	 * saves the comment permanently and returns it
	 *
	 * if the supplied comment has an empty ID, a new entry comment will be
	 * saved and the instance updated with the new ID.
	 *
	 * Otherwise, an existing comment will be updated.
	 *
	 * Throws NotFoundException when a comment that is to be updated does not
	 * exist anymore at this point of time.
	 *
	 * @param IComment &$comment
	 * @return bool
	 * @throws NotFoundException
	 * @since 9.0.0
	 */
	public function save(IComment &$comment) {
		$comment = $this->prepareCommentForDatabaseWrite($comment);
		if($comment->getId() === '') {
			$result = $this->insert($comment);
		} else {
			$result = $this->update($comment);
		}

		if($result && !empty($comment->getParentId())) {
			$this->updateChildrenInformation(
					$comment->getParentId(),
					$comment->getCreationDateTime()
			);
		}

		return $result;
	}

	/**
	 * inserts the provided comment in the database
	 *
	 * @param IComment $comment
	 * @return bool
	 */
	protected function insert(IComment &$comment) {
		$qb = $this->dbConn->getQueryBuilder();
		$affectedRows = $qb
			->insert('comments')
			->values([
				'parent_id'					=> $qb->createNamedParameter($comment->getParentId()),
				'topmost_parent_id' 		=> $qb->createNamedParameter($comment->getTopmostParentId()),
				'children_count' 			=> $qb->createNamedParameter($comment->getChildrenCount()),
				'actor_type' 				=> $qb->createNamedParameter($comment->getActorType()),
				'actor_id' 					=> $qb->createNamedParameter($comment->getActorId()),
				'message' 					=> $qb->createNamedParameter($comment->getMessage()),
				'verb' 						=> $qb->createNamedParameter($comment->getVerb()),
				'creation_timestamp' 		=> $qb->createNamedParameter($comment->getCreationDateTime(), 'datetime'),
				'latest_child_timestamp'	=> $qb->createNamedParameter($comment->getLatestChildDateTime(), 'datetime'),
				'object_type' 				=> $qb->createNamedParameter($comment->getObjectType()),
				'object_id' 				=> $qb->createNamedParameter($comment->getObjectId()),
			])
			->execute();

		if ($affectedRows > 0) {
			$comment->setId($this->dbConn->lastInsertId('comments'));
		}

		return $affectedRows > 0;
	}

	/**
	 * updates a Comment data row
	 *
	 * @param IComment $comment
	 * @return bool
	 * @throws NotFoundException
	 */
	protected function update(IComment $comment) {
		$qb = $this->dbConn->getQueryBuilder();
		$affectedRows = $qb
			->update('comments')
				->set('parent_id',				$qb->createNamedParameter($comment->getParentId()))
				->set('topmost_parent_id', 		$qb->createNamedParameter($comment->getTopmostParentId()))
				->set('children_count',			$qb->createNamedParameter($comment->getChildrenCount()))
				->set('actor_type', 			$qb->createNamedParameter($comment->getActorType()))
				->set('actor_id', 				$qb->createNamedParameter($comment->getActorId()))
				->set('message',				$qb->createNamedParameter($comment->getMessage()))
				->set('verb',					$qb->createNamedParameter($comment->getVerb()))
				->set('creation_timestamp',		$qb->createNamedParameter($comment->getCreationDateTime(), 'datetime'))
				->set('latest_child_timestamp',	$qb->createNamedParameter($comment->getLatestChildDateTime(), 'datetime'))
				->set('object_type',			$qb->createNamedParameter($comment->getObjectType()))
				->set('object_id',				$qb->createNamedParameter($comment->getObjectId()))
			->where($qb->expr()->eq('id', $qb->createParameter('id')))
			->setParameter('id', $comment->getId())
			->execute();

		if($affectedRows === 0) {
			throw new NotFoundException('Comment to update does ceased to exist');
		}

		return $affectedRows > 0;
	}

	/**
	 * removes references to specific actor (e.g. on user delete) of a comment.
	 * The comment itself must not get lost/deleted.
	 *
	 * @param string $actorType the actor type (e.g. 'user')
	 * @param string $actorId a user id
	 * @return boolean
	 * @since 9.0.0
	 */
	public function deleteReferencesOfActor($actorType, $actorId) {
		$this->checkRoleParameters('Actor', $actorType, $actorId);

		$qb = $this->dbConn->getQueryBuilder();
		$affectedRows = $qb
			->update('comments')
			->set('actor_type',	$qb->createNamedParameter(ICommentsManager::DELETED_USER))
			->set('actor_id',	$qb->createNamedParameter(ICommentsManager::DELETED_USER))
			->where($qb->expr()->eq('actor_type', $qb->createParameter('type')))
			->andWhere($qb->expr()->eq('actor_id', $qb->createParameter('id')))
			->setParameter('type', $actorType)
			->setParameter('id', $actorId)
			->execute();

		return is_int($affectedRows);
	}

	/**
	 * deletes all comments made of a specific object (e.g. on file delete)
	 *
	 * @param string $objectType the object type (e.g. 'file')
	 * @param string $objectId e.g. the file id
	 * @return boolean
	 * @since 9.0.0
	 */
	public function deleteCommentsAtObject($objectType, $objectId) {
		$this->checkRoleParameters('Object', $objectType, $objectId);

		$qb = $this->dbConn->getQueryBuilder();
		$affectedRows = $qb
			->delete('comments')
			->where($qb->expr()->eq('object_type', $qb->createParameter('type')))
			->andWhere($qb->expr()->eq('object_id', $qb->createParameter('id')))
			->setParameter('type', $objectType)
			->setParameter('id', $objectId)
			->execute();

		return is_int($affectedRows);
	}
}
