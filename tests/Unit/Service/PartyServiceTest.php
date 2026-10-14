<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Service;

use OCA\ContractCheck\Db\Party;
use OCA\ContractCheck\Db\PartyMapper;
use OCA\ContractCheck\Exception\NotFoundException;
use OCA\ContractCheck\Exception\ValidationException;
use OCA\ContractCheck\Exception\ForbiddenException;
use OCA\ContractCheck\Service\AccessService;
use OCA\ContractCheck\Service\AuditService;
use OCA\ContractCheck\Service\Clock;
use OCA\ContractCheck\Service\PartyService;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PartyServiceTest extends TestCase
{
	private PartyMapper&MockObject $parties;
	private AccessService&MockObject $access;
	private IUserManager&MockObject $users;
	private PartyService $svc;

	protected function setUp(): void
	{
		$this->parties = $this->createMock(PartyMapper::class);
		$this->access = $this->createMock(AccessService::class);
		$this->users = $this->createMock(IUserManager::class);
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(new \DateTimeImmutable('2027-06-01 12:00:00'));
		$this->svc = new PartyService($this->parties, $this->access, $this->users, $clock);
	}

	public function testCreateRequiresManager(): void
	{
		$this->access->method('canEdit')->willReturn(false);
		$this->expectException(ForbiddenException::class);
		$this->svc->create(['name' => 'x'], 'alice');
	}

	public function testCreateRequiresName(): void
	{
		$this->access->method('canEdit')->willReturn(true);
		try {
			$this->svc->create(['name' => '   '], 'alice');
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('name', $e->getFields());
		}
	}

	public function testCreateRejectsGhostContactUid(): void
	{
		// Ghost-member class: deleted users leave stale rows — existence must
		// be checked, not just the field shape.
		$this->access->method('canEdit')->willReturn(true);
		$this->users->method('userExists')->willReturn(false);
		$this->parties->expects($this->never())->method('insert');
		$this->expectException(NotFoundException::class);
		$this->svc->create(['name' => 'Acme', 'contactUid' => 'deleted_user'], 'alice');
	}

	public function testCreateRejectsInvalidEmail(): void
	{
		$this->access->method('canEdit')->willReturn(true);
		$this->users->method('userExists')->willReturn(true);
		try {
			$this->svc->create(['name' => 'Acme', 'contactEmail' => 'not-an-email'], 'alice');
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('contactEmail', $e->getFields());
		}
	}

	public function testCreateHappyPath(): void
	{
		$this->access->method('canEdit')->willReturn(true);
		$this->users->method('userExists')->willReturn(true);
		$this->parties->method('insert')->willReturnArgument(0);
		$p = $this->svc->create(['name' => ' Acme GmbH ', 'contactUid' => 'bob'], 'alice');
		self::assertSame('Acme GmbH', $p->getName());
		self::assertSame('bob', $p->getContactUid());
		self::assertSame(0, $p->getArchived());
	}

	public function testArchiveRequiresManagerAndFlipsFlag(): void
	{
		$this->access->method('canEdit')->willReturn(true);
		$party = new Party();
		$party->setId(5);
		$this->parties->method('find')->willReturn($party);
		$this->parties->method('update')->willReturnArgument(0);
		$out = $this->svc->archive(5, 'alice');
		self::assertSame(1, $out->getArchived());
	}
}
