<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <robin@icewind.nl>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OC\Files\ObjectStore;

use OC\Files\Cache\Scanner;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\FileInfo;

class ObjectStoreScanner extends Scanner {
	/**
	 * scan a single file and store it in the cache
	 *
	 * @param string $file
	 * @param int $reuseExisting
	 * @param int $parentId
	 * @param array|null $cacheData existing data in the cache for the file to be scanned
	 * @return array an array of metadata of the scanned file
	 */
	public function scanFile($file, $reuseExisting = 0, $parentId = -1, $cacheData = null, $lock = true, $data = null) {
		return [];
	}

	/**
	 * scan a folder and all it's children
	 *
	 * @param string $path
	 * @param bool $recursive
	 * @param int $reuse
	 * @return array with the meta data of the scanned file or folder
	 */
	public function scan($path, $recursive = self::SCAN_RECURSIVE, $reuse = -1, $lock = true) {
		return [];
	}

	/**
	 * scan all the files and folders in a folder
	 *
	 * @param string $path
	 * @param bool $recursive
	 * @param int $reuse
	 * @param array $folderData existing cache data for the folder to be scanned
	 * @return int the size of the scanned folder or -1 if the size is unknown at this stage
	 */
	protected function scanChildren($path, $recursive = self::SCAN_RECURSIVE, $reuse = -1, $folderId = null, $lock = true, array $data = []) {
		return 0;
	}

	/**
	 * walk over any folders that are not fully scanned yet and scan them
	 */
	public function backgroundScan() {
		$lastPath = null;
		// find any path marked as unscanned and run the scanner until no more paths are unscanned (or we get stuck)
		// we sort by path DESC to ensure that contents of a folder are handled before the parent folder
		while (($path = $this->getIncomplete()) !== false && $path !== $lastPath) {
			$this->runBackgroundScanJob(function () use ($path) {
				$item = $this->cache->get($path);
				if ($item && $item->getMimeType() !== FileInfo::MIMETYPE_FOLDER) {
					$fh = $this->storage->fopen($path, 'r');
					if ($fh) {
						$stat = fstat($fh);
						if ($stat['size']) {
							$this->cache->update($item->getId(), ['size' => $stat['size']]);
						}
					}
				}
			}, $path);
			// FIXME: this won't proceed with the next item, needs revamping of getIncomplete()
			// to make this possible
			$lastPath = $path;
		}
	}

	/**
	 * Unlike the default Cache::getIncomplete this one sorts by path.
	 *
	 * This is needed since self::backgroundScan doesn't fix child entries when running on a parent folder.
	 * By sorting by path we ensure that we encounter the child entries first.
	 *
	 * @return false|string
	 * @throws \OCP\DB\Exception
	 */
	private function getIncomplete() {
		$query = $this->connection->getQueryBuilder();
		$query->select('path')
			->from('filecache')
			->where($query->expr()->eq('storage', $query->createNamedParameter($this->cache->getNumericStorageId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->lt('size', $query->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('path', 'DESC')
			->setMaxResults(1);

		$result = $query->executeQuery();
		$path = $result->fetchOne();
		$result->closeCursor();

		if ($path === false) {
			return false;
		}

		// Make sure Oracle does not continue with null for empty strings
		return (string)$path;
	}
}
