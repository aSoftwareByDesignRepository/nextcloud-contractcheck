<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Service;

use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\Files\NotPermittedException;

/**
 * File picker backend — searches ONLY inside the acting user's folder
 * (their files + files shared with them). Foreign files do not exist for
 * the picker; the result set is the ACL boundary itself.
 */
class FilesSearchService
{
	private const MAX_RESULTS = 50;

	public function __construct(private readonly IRootFolder $rootFolder)
	{
	}

	/**
	 * @return list<array{fileId:int, name:string, path:string, mime:string}>
	 */
	public function search(string $uid, string $term): array
	{
		if (mb_strlen($term) < 2) {
			return [];
		}
		try {
			$folder = $this->rootFolder->getUserFolder($uid);
		} catch (FilesNotFoundException | NotPermittedException) {
			return [];
		}
		$out = [];
		$needle = mb_strtolower($term);
		foreach ($folder->search($term) as $node) {
			if (!$node instanceof File || !$node->isReadable()) {
				continue;
			}
			$path = $folder->getRelativePath($node->getPath());
			if ($path === null || !str_contains(mb_strtolower($path), $needle)) {
				continue;
			}
			$out[] = [
				'fileId' => $node->getId(),
				'name' => $node->getName(),
				'path' => $path,
				'mime' => $node->getMimeType(),
			];
			if (count($out) >= self::MAX_RESULTS) {
				break;
			}
		}

		return $out;
	}
}
