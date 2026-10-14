<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Service;

use OCA\ContractCheck\Db\ContractDocument;
use OCA\ContractCheck\Db\ContractDocumentMapper;
use OCA\ContractCheck\Exception\NotFoundException;
use OCA\ContractCheck\Exception\ValidationException;
use OCA\ContractCheck\Service\AuditService;
use OCA\ContractCheck\Service\Clock;
use OCA\ContractCheck\Service\DocumentService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DocumentServiceTest extends TestCase
{
	private ContractDocumentMapper&MockObject $docs;
	private IRootFolder&MockObject $root;
	private AuditService&MockObject $audit;
	private DocumentService $svc;

	protected function setUp(): void
	{
		$this->docs = $this->createMock(ContractDocumentMapper::class);
		$this->root = $this->createMock(IRootFolder::class);
		$this->audit = $this->createMock(AuditService::class);
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(new \DateTimeImmutable('2027-06-01 12:00:00'));
		$this->svc = new DocumentService($this->docs, $this->root, $this->audit, $clock);
	}

	private function userFolderWith(?File $file): Folder&MockObject
	{
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn($file === null ? [] : [$file]);
		$folder->method('getRelativePath')->willReturn('/contract.pdf');
		$this->root->method('getUserFolder')->willReturn($folder);
		return $folder;
	}

	private function readableFile(string $path = '/alice/files/contract.pdf'): File&MockObject
	{
		$f = $this->createMock(File::class);
		$f->method('isReadable')->willReturn(true);
		$f->method('getPath')->willReturn($path);
		$f->method('getName')->willReturn('contract.pdf');
		$f->method('getMimeType')->willReturn('application/pdf');
		return $f;
	}

	public function testLinkRejectsNonNumericFileId(): void
	{
		foreach (['abc', '2e3', ' 5', '-4', 3.7, null, ['x']] as $bad) {
			try {
				$this->svc->link(42, $bad, 'contract', 'alice');
				self::fail('expected ValidationException for ' . var_export($bad, true));
			} catch (ValidationException $e) {
				self::assertArrayHasKey('fileId', $e->getFields());
			}
		}
	}

	public function testLinkRejectsUnknownKind(): void
	{
		$this->expectException(ValidationException::class);
		$this->svc->link(42, 10, 'evil_kind', 'alice');
	}

	public function testLinkRejectsFileNotVisibleToActor(): void
	{
		// File exists globally but is not shared with alice → DOC_UNRESOLVED,
		// identical to a nonexistent id (no existence oracle).
		$this->userFolderWith(null);
		$ghost = $this->createMock(File::class);
		$ghost->method('getPath')->willReturn('/bob/files/secret.pdf');
		$this->root->method('getById')->willReturn([$ghost]);
		$this->docs->expects($this->never())->method('insertIgnore');
		try {
			$this->svc->link(42, 10, 'contract', 'alice');
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertSame('DOC_UNRESOLVED', $e->getErrorCode() ?? 'DOC_UNRESOLVED');
		}
	}

	public function testResolveStates(): void
	{
		// ok
		$file = $this->readableFile();
		$this->userFolderWith($file);
		$this->root->method('getById')->willReturn([$file]);
		$view = $this->svc->resolveFor(10, 'alice');
		self::assertSame('ok', $view['state']);
		self::assertSame('/contract.pdf', $view['path']);
		self::assertSame('application/pdf', $view['mime']);
	}

	public function testResolveMissingVsNoAccess(): void
	{
		// Missing: not in the user's folder AND not in any global mount.
		$this->userFolderWith(null);
		$this->root->method('getById')->willReturn([]);
		self::assertSame('missing', $this->svc->resolveFor(10, 'alice')['state']);
	}

	public function testTrashbinPathCountsAsMissing(): void
	{
		$this->userFolderWith(null);
		$trashed = $this->createMock(File::class);
		$trashed->method('getPath')->willReturn('/alice/files_trashbin/files/contract.pdf.d123');
		$this->root->method('getById')->willReturn([$trashed]);
		self::assertSame('missing', $this->svc->resolveFor(10, 'alice')['state']);
	}

	public function testRescanMarksMissingAndHealsRestored(): void
	{
		$missing = new ContractDocument();
		$missing->setId(1);
		$missing->setContractId(42);
		$missing->setFileId(100);
		// missingSince null → will be marked
		$restored = new ContractDocument();
		$restored->setId(2);
		$restored->setContractId(43);
		$restored->setFileId(200);
		$restored->setMissingSince(new \DateTimeImmutable('2027-05-01'));
		$this->docs->method('findAll')->willReturn([$missing, $restored]);
		// file 100 gone globally, file 200 present
		$alive = $this->createMock(File::class);
		$alive->method('getPath')->willReturn('/alice/files/x.pdf');
		$this->root->method('getById')->willReturnCallback(
			static fn (int $id) => $id === 200 ? [$alive] : []
		);

		$marked = [];
		$this->docs->method('setMissingSince')->willReturnCallback(
			function (int $docId, $when) use (&$marked) { $marked[$docId] = $when; return 1; }
		);
		$changed = $this->svc->rescanMissing();
		self::assertSame([42], $changed);
		self::assertArrayHasKey(1, $marked);          // newly marked missing
		self::assertNull($marked[2]);                // healed back to null
	}

	public function testUnlinkForeignContractIsUniformNotFound(): void
	{
		$doc = new ContractDocument();
		$doc->setId(5);
		$doc->setFileId(10);
		$this->docs->method('find')->willReturn($doc);
		$this->docs->method('deleteForContract')->willReturn(0); // contract mismatch
		$this->audit->expects($this->never())->method('record');
		$this->expectException(NotFoundException::class);
		$this->svc->unlink(99, 5, 'alice');
	}
}
