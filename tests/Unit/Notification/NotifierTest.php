<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Notification;

use OCA\ContractCheck\Db\Contract;
use OCA\ContractCheck\Notification\Notifier;
use OCA\ContractCheck\Service\ContractService;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\IL10N;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class NotifierTest extends TestCase
{
	private ContractService&MockObject $contracts;
	private IManager&MockObject $manager;
	private Notifier $notifier;

	protected function setUp(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []) => vsprintf(str_replace(['%1$d', '%2$s'], ['%d', '%s'], $text), $params)
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://nc.example/apps/contractcheck/contracts/7');
		$urls->method('imagePath')->willReturn('/img/app-dark.svg');
		$urls->method('getAbsoluteURL')->willReturn('https://nc.example/img/app-dark.svg');
		$this->contracts = $this->createMock(ContractService::class);
		$this->manager = $this->createMock(IManager::class);
		$this->manager->method('createNotification')->willReturn($this->createMock(INotification::class));
		$this->notifier = new Notifier($factory, $urls, $this->contracts, $this->manager);
	}

	private function notification(string $subject = 'deadline_due', array $params = ['daysLeft' => 12]): INotification&MockObject
	{
		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn('contractcheck');
		$n->method('getObjectType')->willReturn('contract');
		$n->method('getObjectId')->willReturn('7');
		$n->method('getUser')->willReturn('bob');
		$n->method('getSubject')->willReturn($subject);
		$n->method('getSubjectParameters')->willReturn($params);
		return $n;
	}

	public function testWrongAppIsRejected(): void
	{
		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn('otherapp');
		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($n, 'en');
	}

	public function testWrongObjectTypeIsRejected(): void
	{
		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn('contractcheck');
		$n->method('getObjectType')->willReturn('party');
		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($n, 'en');
	}

	public function testInvisibleContractDropsNotification(): void
	{
		// The recipient lost scope access (or the contract vanished) — the
		// notification must be marked processed, never rendered stale.
		$this->contracts->method('getForUser')->willThrowException(new \RuntimeException('gone'));
		$this->manager->expects($this->once())->method('markProcessed');
		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($this->notification(), 'en');
	}

	public function testDeadlineDueRendersTitleAndDays(): void
	{
		$c = new Contract();
		$c->setTitle('Office rent');
		$this->contracts->method('getForUser')->with(7, 'bob')->willReturn($c);
		$n = $this->notification('deadline_due', ['daysLeft' => 12]);
		$n->expects($this->once())->method('setParsedSubject')
			->with(self::callback(fn (string $m) => str_contains($m, 'Office rent') && str_contains($m, '12')))
			->willReturnSelf();
		$out = $this->notifier->prepare($n, 'en');
		self::assertSame($n, $out);
	}

	public function testDeadlineDueToday(): void
	{
		$c = new Contract();
		$c->setTitle('Lease');
		$this->contracts->method('getForUser')->willReturn($c);
		$n = $this->notification('deadline_due', ['daysLeft' => 0]);
		$n->expects($this->once())->method('setParsedSubject')
			->with(self::callback(fn (string $m) => str_contains($m, 'today') && str_contains($m, 'Lease')))
			->willReturnSelf();
		$this->notifier->prepare($n, 'en');
	}

	public function testExpiredAndMissingDocSubjects(): void
	{
		$c = new Contract();
		$c->setTitle('Insurance');
		$this->contracts->method('getForUser')->willReturn($c);

		$expired = $this->notification('contract_expired', []);
		$expired->expects($this->once())->method('setParsedSubject')
			->with(self::callback(fn (string $m) => str_contains($m, 'Insurance')))
			->willReturnSelf();
		$this->notifier->prepare($expired, 'en');

		$missing = $this->notification('document_missing', []);
		$missing->expects($this->once())->method('setParsedSubject')
			->with(self::callback(fn (string $m) => str_contains($m, 'Insurance')))
			->willReturnSelf();
		$this->notifier->prepare($missing, 'en');
	}

	public function testUnknownSubjectIsRejected(): void
	{
		$c = new Contract();
		$c->setTitle('x');
		$this->contracts->method('getForUser')->willReturn($c);
		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($this->notification('evil_subject', []), 'en');
	}

	public function testIdentity(): void
	{
		self::assertSame('contractcheck', $this->notifier->getID());
		self::assertSame('ContractCheck', $this->notifier->getName());
	}
}
