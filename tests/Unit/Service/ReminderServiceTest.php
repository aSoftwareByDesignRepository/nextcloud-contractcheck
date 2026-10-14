<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Service;

use OCA\ContractCheck\Db\Contract;
use OCA\ContractCheck\Db\ContractMapper;
use OCA\ContractCheck\Db\ReminderSent;
use OCA\ContractCheck\Db\ReminderSentMapper;
use OCA\ContractCheck\Domain\DeadlineEngine;
use OCA\ContractCheck\Service\AccessService;
use OCA\ContractCheck\Service\AuditService;
use OCA\ContractCheck\Service\Clock;
use OCA\ContractCheck\Service\ContractService;
use OCA\ContractCheck\Service\NotificationService;
use OCA\ContractCheck\Service\ReminderService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ReminderServiceTest extends TestCase
{
	private static array $claimRows = [];

	private ContractMapper&MockObject $contracts;
	private ReminderSentMapper&MockObject $sent;
	private ContractService&MockObject $contractService;
	private AccessService&MockObject $access;
	private NotificationService&MockObject $notifications;
	private AuditService&MockObject $audit;
	private Clock&MockObject $clock;
	private ReminderService $svc;

	protected function setUp(): void
	{
		$this->contracts = $this->createMock(ContractMapper::class);
		$this->sent = $this->createMock(ReminderSentMapper::class);
		$this->contractService = $this->createMock(ContractService::class);
		$this->access = $this->createMock(AccessService::class);
		$this->notifications = $this->createMock(NotificationService::class);
		$this->audit = $this->createMock(AuditService::class);
		$this->clock = $this->createMock(Clock::class);
		$this->clock->method('now')->willReturn(new \DateTimeImmutable('2027-09-20 08:00:00'));
		$this->clock->method('today')->willReturn('2027-09-20');
		$logger = $this->createMock(LoggerInterface::class);
		$this->svc = new ReminderService(
			$this->contracts, $this->sent, $this->contractService, $this->access,
			$this->notifications, $this->audit, new DeadlineEngine(), $this->clock, $logger
		);
	}

	private function contract(array $leadDays = [60, 30]): Contract
	{
		$c = new Contract();
		$c->setId(7);
		$c->setStatus('active');
		$c->setTermKind('fixed');
		$c->setEndDate(\DateTimeImmutable::createFromFormat('Y-m-d', '2027-12-31'));
		$c->setNoticeValue(3);
		$c->setNoticeUnit('months');
		$c->setNoticeAnchor('contract_end');
		$c->setLeadDaysJson(json_encode($leadDays));
		return $c;
	}

	private function rule(): array
	{
		return [
			'term_kind' => 'fixed', 'start_date' => null, 'end_date' => '2027-12-31',
			'renewal_value' => null, 'renewal_unit' => null,
			'notice_value' => 3, 'notice_unit' => 'months', 'notice_anchor' => 'contract_end',
		];
	}

	public function testSendsWhenInsideLeadWindow(): void
	{
		// cancel_by = 30.09.2027, today 20.09 → 10 days left → leads 60,30 both hit
		$c = $this->contract();
		$this->contractService->method('ruleOf')->willReturn($this->rule());
		$this->access->method('reminderRecipients')->willReturn(['alice']);
		$sent = [];
		$this->sent->method('claim')->willReturnCallback(
			function (ReminderSent $r, \DateTimeImmutable $staleBefore) use (&$sent) { $sent[] = $r->getLeadDay(); return true; }
		);
		$this->notifications->expects($this->exactly(2))->method('deadlineReminder')
			->with(7, 'alice', $this->anything());

		$n = $this->svc->dispatchForContract($c, '2027-09-20');
		self::assertSame(2, $n);
		self::assertSame([60, 30], $sent);
	}

	public function testNoDuplicateOnRerun(): void
	{
		$c = $this->contract();
		$this->contractService->method('ruleOf')->willReturn($this->rule());
		$this->access->method('reminderRecipients')->willReturn(['alice']);
		$store = [];
		$this->sent->method('claim')->willReturnCallback(
			function (ReminderSent $r, \DateTimeImmutable $staleBefore) use (&$store) {
				$key = $r->getContractId() . '|' . $r->getDeadlineInstanceId() . '|' . $r->getLeadDay();
				if (isset($store[$key])) {
					return false; // unique violation → skip
				}
				$store[$key] = true;
				return true;
			}
		);
		$first = $this->svc->dispatchForContract($c, '2027-09-20');
		$second = $this->svc->dispatchForContract($c, '2027-09-20');
		self::assertSame(2, $first);
		self::assertSame(0, $second, 'same deadline instance must not re-notify');
	}

	public function testAmendedDeadlineIsNewInstance(): void
	{
		// Engine re-derivation must not dedup AWAY a genuinely new window.
		$c = $this->contract();
		$this->access->method('reminderRecipients')->willReturn(['alice']);
		$this->sent->method('claim')->willReturn(true);
		$ids = [];
		$this->sent->method('claim')->willReturnCallback(
			function (ReminderSent $r, \DateTimeImmutable $staleBefore) use (&$ids) { $ids[] = $r->getDeadlineInstanceId(); return true; }
		);
		$this->contractService->method('ruleOf')->willReturnOnConsecutiveCalls(
			$this->rule(),
			// end moved closer: cancel_by 15.10.2027 → 25 days out → still inside 30d lead
			array_merge($this->rule(), ['end_date' => '2028-01-15'])
		);
		$this->svc->dispatchForContract($c, '2027-09-20');
		$this->svc->dispatchForContract($c, '2027-09-20');
		self::assertCount(4, $ids, 'both dispatches must actually send');
		self::assertSame($ids[0], $ids[1], 'same window, different lead days → same instance');
		self::assertNotSame($ids[0], $ids[2], 'changed effective date → new instance id');
	}

	public function testNothingSentOutsideWindow(): void
	{
		// 300 days left — no lead day (max 90) reached
		$c = $this->contract();
		$this->contractService->method('ruleOf')->willReturn(
			array_merge($this->rule(), ['end_date' => '2028-12-31'])
		);
		$this->access->method('reminderRecipients')->willReturn(['alice']);
		$this->notifications->expects($this->never())->method('deadlineReminder');
		self::assertSame(0, $this->svc->dispatchForContract($c, '2027-09-20'));
	}

	public function testAnytimeContractsNeverRemind(): void
	{
		$c = $this->contract();
		$c->setNoticeAnchor('anytime');
		$this->contractService->method('ruleOf')->willReturn(
			array_merge($this->rule(), ['term_kind' => 'indefinite', 'notice_anchor' => 'anytime', 'end_date' => null])
		);
		$this->notifications->expects($this->never())->method('deadlineReminder');
		self::assertSame(0, $this->svc->dispatchForContract($c, '2027-09-20'));
	}

	public function testMissedWindowDoesNotNotify(): void
	{
		$c = $this->contract();
		$this->contractService->method('ruleOf')->willReturn($this->rule());
		// today past the only window → missed, not actionable
		$this->access->method('reminderRecipients')->willReturn(['alice']);
		$this->notifications->expects($this->never())->method('deadlineReminder');
		self::assertSame(0, $this->svc->dispatchForContract($c, '2027-12-01'));
	}

	public function testNotifyFailureReleasesDedupClaimSoNextRunRetries(): void
	{
		// FM-REM-1: markSent() claims before notify(). If notify throws, the
		// claim must be released — otherwise a transient mail failure
		// permanently swallows the one reminder the product exists to send.
		$c = $this->contract();
		$this->contractService->method('ruleOf')->willReturn($this->rule());
		$this->access->method('reminderRecipients')->willReturn(['alice']);
		$this->sent->method('claim')->willReturn(true);
		$this->notifications->method('deadlineReminder')
			->willThrowException(new \RuntimeException('mail backend down'));
		$this->sent->expects($this->atLeastOnce())->method('markUnsent')
			->with(7, $this->anything(), $this->anything());
		try {
			$this->svc->dispatchForContract($c, '2027-09-20');
		} catch (\RuntimeException) {
		}
	}

	public function testOneBrokenContractDoesNotStarveRestOfPass(): void
	{
		// FM-REM-2: one throwing contract can't starve every contract behind
		// it for a whole day.
		$bad = $this->contract();
		$good = $this->contract();
		$good->setId(9);
		$this->contractService->method('ruleOf')->willReturnCallback(
			function ($c) use ($bad) {
				if ($c === $bad) {
					throw new \RuntimeException('corrupt rule');
				}
				return $this->rule();
			}
		);
		$this->contracts->method('findActiveForJob')->willReturn([$bad, $good]);
		$this->access->method('reminderRecipients')->willReturn(['alice']);
		$this->sent->method('claim')->willReturn(true);
		$this->notifications->expects($this->atLeastOnce())->method('deadlineReminder')
			->with(9, 'alice', $this->anything());

		$n = $this->svc->dispatchDueReminders();
		self::assertGreaterThan(0, $n, 'good contract must still dispatch');
	}

	public function testClaimIsConfirmedAfterDelivery(): void
	{
		$c = $this->contract();
		$this->contractService->method('ruleOf')->willReturn($this->rule());
		$this->access->method('reminderRecipients')->willReturn(['alice']);
		$this->sent->method('claim')->willReturn(true);
		$this->sent->expects($this->exactly(2))->method('confirmSent')
			->with(7, $this->anything(), $this->callback(
				static fn ($leadDay) => in_array($leadDay, [60, 30], true)
			), $this->anything());

		self::assertSame(2, $this->svc->dispatchForContract($c, '2027-09-20'));
	}

	public function testStaleClaimsArePassedAStaleBeforeWindow(): void
	{
		// The stale threshold must be in the past but recent — a fresh
		// pending claim owned by a live run must never be reclaimed.
		$c = $this->contract();
		$this->contractService->method('ruleOf')->willReturn($this->rule());
		$this->access->method('reminderRecipients')->willReturn(['alice']);
		$this->sent->expects($this->atLeastOnce())->method('claim')
			->with($this->callback(function (ReminderSent $row) {
				self::$claimRows[] = $row;
				return true;
			}), $this->callback(
				function (\DateTimeImmutable $staleBefore): bool {
					// staleBefore must equal claim-time minus the threshold —
					// a fresh pending claim owned by a live run (sent_at=now)
					// then fails the reclaim predicate and dedups.
					$claimAt = end(self::$claimRows)->getSentAt();
					return $staleBefore->getTimestamp()
						=== $claimAt->getTimestamp() - ReminderService::CLAIM_STALE_SECONDS;
				}
			))
			->willReturn(true);

		$this->svc->dispatchForContract($c, '2027-09-20');
	}

	public function testConfirmFailureDoesNotLoseDispatchCount(): void
	{
		// Notification went out; confirm fails → row stays pending → a
		// stale reclaim may duplicate-send later (correct degradation),
		// but THIS pass still reports the delivery.
		$c = $this->contract();
		$this->contractService->method('ruleOf')->willReturn($this->rule());
		$this->access->method('reminderRecipients')->willReturn(['alice']);
		$this->sent->method('claim')->willReturn(true);
		$this->sent->method('confirmSent')
			->willThrowException(new \RuntimeException('db hiccup'));
		$this->notifications->expects($this->exactly(2))->method('deadlineReminder');

		self::assertSame(2, $this->svc->dispatchForContract($c, '2027-09-20'));
	}
}
