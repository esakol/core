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

namespace OC\Lock;

use OCP\Lock\LockedException;
use OCP\IMemcache;

class MemcacheLockingProvider extends AbstractLockingProvider {
	/**
	 * @var \OCP\IMemcache
	 */
	private $memcache;

	/**
	 * @param \OCP\IMemcache $memcache
	 */
	public function __construct(IMemcache $memcache) {
		$this->memcache = $memcache;
	}

	/**
	 * @param string $path
	 * @param int $type self::LOCK_SHARED or self::LOCK_EXCLUSIVE
	 * @return bool
	 */
	public function isLocked($path, $type) {
		$lockValue = $this->memcache->get($path);
		if ($type === self::LOCK_SHARED) {
			return $lockValue > 0;
		} else if ($type === self::LOCK_EXCLUSIVE) {
			return $lockValue === 'exclusive';
		} else {
			return false;
		}
	}

	/**
	 * @param string $path
	 * @param int $type self::LOCK_SHARED or self::LOCK_EXCLUSIVE
	 * @throws \OCP\Lock\LockedException
	 */
	public function acquireLock($path, $type) {
		if ($type === self::LOCK_SHARED) {
			if (!$this->memcache->inc($path)) {
				throw new LockedException($path);
			}
		} else {
			$this->memcache->add($path, 0);
			if (!$this->memcache->cas($path, 0, 'exclusive')) {
				throw new LockedException($path);
			}
		}
		$this->markAcquire($path, $type);
	}

	/**
	 * @param string $path
	 * @param int $type self::LOCK_SHARED or self::LOCK_EXCLUSIVE
	 */
	public function releaseLock($path, $type) {
		if ($type === self::LOCK_SHARED) {
			if (isset($this->acquiredLocks['shared'][$path]) and $this->acquiredLocks['shared'][$path] > 0) {
				$this->memcache->dec($path);
				$this->memcache->cad($path, 0);
			}
		} else if ($type === self::LOCK_EXCLUSIVE) {
			$this->memcache->cad($path, 'exclusive');
		}
		$this->markRelease($path, $type);
	}

	/**
	 * Change the type of an existing lock
	 *
	 * @param string $path
	 * @param int $targetType self::LOCK_SHARED or self::LOCK_EXCLUSIVE
	 * @throws \OCP\Lock\LockedException
	 */
	public function changeLock($path, $targetType) {
		if ($targetType === self::LOCK_SHARED) {
			if (!$this->memcache->cas($path, 'exclusive', 1)) {
				throw new LockedException($path);
			}
		} else if ($targetType === self::LOCK_EXCLUSIVE) {
			// we can only change a shared lock to an exclusive if there's only a single owner of the shared lock
			if (!$this->memcache->cas($path, 1, 'exclusive')) {
				throw new LockedException($path);
			}
		}
		$this->markChange($path, $targetType);
	}
}
