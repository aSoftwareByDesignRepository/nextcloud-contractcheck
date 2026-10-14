<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Service;

use OCA\ContractCheck\Db\ContractDocument;
use OCA\ContractCheck\Db\ContractDocumentMapper;
use OCA\ContractCheck\Exception\NotFoundException;
use OCA\ContractCheck\Exception\ValidationException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\Files\NotPermittedException;

/**
 * Document links — references to real Nextcloud files by file_id.
 *
 * ACL invariant (spec US-204 AC-3): the app NEVER bypasses Files
 * permissions. Resolution for a user goes through that user's folder —
 * files not shared with them are invisible, trashed/deleted files do not
 * resolve. Link-time validation requires an existing readable file in the
 * actor's own view; read-time marks rows missing when the file disappears.
 */
class DocumentService
{
	public function __construct(
		private readonly ContractDocumentMapper $docs,
		private readonly IRootFolder $rootFolder,
		private readonly AuditService $audit,
		private readonly Clock $clock,
	) {
	}

	/**
	 * Documents of a contract as the viewing user sees them.
	 *
	 * @return list<array<string, mixed>> each: doc row + path/name/state
	 *   state: 'ok' | 'no_access' (file exists but not shared with viewer)
	 *          | 'missing' (gone for everyone incl. trash)
	 */
	public function listForContract(int $contractId, string $uid): array
	{
		$out = [];
		foreach ($this->docs->findByContract($contractId) as $doc) {
			$row = $doc->toArray();
			$view = $this->resolveFor($doc->getFileId(), $uid);
			$row['state'] = $view['state'];
			$row['path'] = $view['path'];
			$row['name'] = $view['name'];
			$row['mime'] = $view['mime'];
			$row['missingSince'] = $doc->getMissingSince() instanceof \DateTimeInterface
				? $doc->getMissingSince()->format('Y-m-d\TH:i:sP')
				: null;
			if ($view['state'] === 'missing' && $row['missingSince'] === null) {
				// Detect at read too — don't wait for the scan job.
				$this->docs->setMissingSince($doc->getId(), $this->clock->now());
				$this->audit->record($contractId, 'doc_missing', null, ['fileId' => $doc->getFileId()]);
				$row['missingSince'] = $this->clock->now()->format('Y-m-d\TH:i:sP');
			}
			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Link a file to a contract. The file must resolve inside the actor's
	 * own folder — foreign ids, trashed files and folders are all rejected
	 * as DOC_UNRESOLVED (uniform, no existence leak about other files).
	 */
	public function link(int $contractId, mixed $fileId, string $kind, string $actorUid): ContractDocument
	{
		if (!is_int($fileId) && !(is_string($fileId) && preg_match('/^\d+$/', $fileId) === 1)) {
			throw ValidationException::field('DOC_UNRESOLVED', 'fileId', 'invalid file id');
		}
		$fileId = (int)$fileId;
		if ($fileId <= 0 || !in_array($kind, ContractDocument::KINDS, true)) {
			throw ValidationException::field('DOC_UNRESOLVED', 'fileId', 'invalid file reference');
		}

		$view = $this->resolveFor($fileId, $actorUid);
		if ($view['state'] !== 'ok') {
			throw ValidationException::field('DOC_UNRESOLVED', 'fileId', 'File cannot be resolved or is not readable');
		}

		$doc = new ContractDocument();
		$doc->setContractId($contractId);
		$doc->setFileId($fileId);
		$doc->setKind($kind);
		$doc->setAddedBy($actorUid);
		$doc->setAddedAt($this->clock->now());
		$doc = $this->docs->insertIgnore($doc); // idempotent link
		if ($doc->getAddedBy() !== $actorUid) {
			// Already linked — report the existing row without a second event.
			return $doc;
		}
		$this->audit->record($contractId, 'doc_linked', $actorUid, [
			'fileId' => $fileId,
			'kind' => $kind,
		]);

		return $doc;
	}

	public function unlink(int $contractId, int $docId, string $actorUid): void
	{
		$doc = $this->docs->find($docId);
		$fileId = $doc->getFileId();
		if ($this->docs->deleteForContract($docId, $contractId) !== 1) {
			throw new NotFoundException('Document not found');
		}
		$this->audit->record($contractId, 'doc_unlinked', $actorUid, ['fileId' => $fileId]);
	}

	/**
	 * Resolve a file id for one user.
	 *
	 * @return array{state:'ok'|'no_access'|'missing', path:?string, name:?string, mime:?string}
	 */
	public function resolveFor(int $fileId, string $uid): array
	{
		$existsGlobally = $this->existsGlobally($fileId);

		try {
			$folder = $this->rootFolder->getUserFolder($uid);
		} catch (FilesNotFoundException | NotPermittedException) {
			return ['state' => $existsGlobally ? 'no_access' : 'missing', 'path' => null, 'name' => null, 'mime' => null];
		}
		$nodes = $folder->getById($fileId);
		$node = $nodes[0] ?? null;
		if ($node === null || !$node instanceof File || !$node->isReadable()) {
			return [
				'state' => $existsGlobally ? 'no_access' : 'missing',
				'path' => null, 'name' => null, 'mime' => null,
			];
		}

		return [
			'state' => 'ok',
			'path' => $folder->getRelativePath($node->getPath()),
			'name' => $node->getName(),
			'mime' => $node->getMimeType(),
		];
	}

	/**
	 * Job-side rescan: marks docs whose file vanished globally (not just
	 * unshared — genuinely gone incl. trash).
	 *
	 * @return list<int> contract ids that gained a new missing marker
	 */
	public function rescanMissing(): array
	{
		$changed = [];
		foreach ($this->docs->findAll() as $doc) {
			$gone = !$this->existsGlobally($doc->getFileId());
			if ($gone && $doc->getMissingSince() === null) {
				$this->docs->setMissingSince($doc->getId(), $this->clock->now());
				$this->audit->record($doc->getContractId(), 'doc_missing', null, [
					'fileId' => $doc->getFileId(),
				]);
				$changed[] = $doc->getContractId();
			} elseif (!$gone && $doc->getMissingSince() !== null) {
				// Restored (e.g. from trash or re-uploaded same id) — heal.
				$this->docs->setMissingSince($doc->getId(), null);
			}
		}

		return $changed;
	}

	/**
	 * Count contracts that currently have at least one missing document —
	 * dashboard signal.
	 */
	/** @return list<int> */
	public function missingContractIds(): array
	{
		$out = [];
		foreach ($this->docs->findAll() as $doc) {
			if ($doc->getMissingSince() !== null) {
				$out[$doc->getContractId()] = true;
			}
		}

		return array_keys($out);
	}

	private function existsGlobally(int $fileId): bool
	{
		// Mount-point scan across all storages — catches system files and
		// shared files the current user cannot see, so 'no_access' and
		// 'missing' stay distinguishable.
		try {
			foreach ($this->rootFolder->getById($fileId) as $node) {
				// Trashbin and version nodes keep the filecache id but are
				// not user-visible — the file is effectively gone.
				$path = $node->getPath();
				if (!str_contains($path, '/files_trashbin/')
					&& !str_contains($path, '/files_versions/')) {
					return true;
				}
			}
			return false;
		} catch (\Throwable) {
			return false;
		}
	}
}
