<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Service;

use OCA\ContractCheck\Db\SettingsStore;
use OCA\ContractCheck\Exception\ConflictException;
use OCA\ContractCheck\Exception\ValidationException;
use OCA\ContractCheck\Service\Clock;
use OCA\ContractCheck\Service\SettingsService;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use OCP\IGroupManager;
use OCP\IGroup;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SettingsServiceTest extends TestCase
{
	private SettingsStore&MockObject $store;
	private IConfig&MockObject $config;
	private ISecureRandom&MockObject $random;
	private IGroupManager&MockObject $groups;
	private SettingsService $svc;
	private Clock&MockObject $clock;

	protected function setUp(): void
	{
		$this->store = $this->createMock(SettingsStore::class);
		$this->config = $this->createMock(IConfig::class);
		$this->random = $this->createMock(ISecureRandom::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->groups->method('groupExists')->willReturn(true);
		$anyGroup = $this->createMock(IGroup::class);
		$anyGroup->method('getUsers')->willReturn([$this->createMock(\OCP\IUser::class)]);
		$this->groups->method('get')->willReturn($anyGroup);
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(new \DateTimeImmutable());
		$this->clock = $clock;
		$this->svc = new SettingsService($this->store, $this->config, $this->random, $this->groups, $clock);
	}

	private function stored(string $payload, int $version = 3): void
	{
		$this->store->method('load')->willReturn(['payload' => $payload, 'version' => $version]);
	}

	public function testDefaultsWhenNoRow(): void
	{
		$this->store->method('load')->willReturn(null);
		self::assertSame([], $this->svc->getAllowedGroups());
		self::assertSame(['admin'], $this->svc->getManagerGroups());
		self::assertSame([60, 30], $this->svc->getDefaultLeadDays());
		self::assertSame('ContractCheck', $this->svc->getOrgName());
	}

	public function testSaveRejectsStaleVersion(): void
	{
		$this->stored('{}', 5);
		// Version mismatch must be rejected BEFORE touching the store —
		// store-level CAS is the second line of defence, not the only one.
		$this->store->expects($this->never())->method('save');
		$this->expectException(ConflictException::class);
		$this->svc->save(['managerGroups' => ['admin']], 3);
	}

	public function testSaveRejectsLostCasRace(): void
	{
		$this->stored('{}', 3);
		$this->store->method('save')->willReturn(false); // row moved under us
		$this->expectException(ConflictException::class);
		$this->svc->save(['managerGroups' => ['admin']], 3);
	}

	public function testSaveValidatesPayload(): void
	{
		$this->stored('{}', 3);
		$this->expectException(ValidationException::class);
		$this->svc->save(['defaultLeadDays' => 'not-an-array'], 3);
	}

	public function testDefaultLeadDaysRejectsTooManyEntries(): void
	{
		$this->stored('{}', 3);
		try {
			$this->svc->save(['defaultLeadDays' => range(1, 11)], 3);
			self::fail();
		} catch (ValidationException $e) {
			self::assertArrayHasKey('defaultLeadDays', $e->getFields());
		}
	}

	public function testCostCenterBudgetsRoundTrip(): void
	{
		$this->stored('{}', 3);
		$saved = null;
		$this->store->method('save')->willReturnCallback(
			function (string $payload) use (&$saved) { $saved = $payload; return true; }
		);
		$this->svc->save(['costCenterBudgets' => ['IT' => 1200000, 'HR' => 50000]], 3);
		$payload = json_decode((string)$saved, true);
		self::assertSame(['HR' => 50000, 'IT' => 1200000], $payload['costCenterBudgets']); // ksort'd
	}

	public function testCostCenterBudgetsRejectsBadShapes(): void
	{
		$this->stored('{}', 3);
		foreach ([
			['costCenterBudgets' => 'not-a-map'],
			['costCenterBudgets' => ['IT' => 'cheap']],        // string amount
			['costCenterBudgets' => ['IT' => -1]],             // negative
			['costCenterBudgets' => ['' => 100]],              // empty key
			['costCenterBudgets' => [str_repeat('x', 65) => 1]], // key too long
			['costCenterBudgets' => ['IT' => 2_000_000_000_000]], // > 1e12
		] as $bad) {
			try {
				$this->svc->save($bad, 3);
				self::fail('accepted ' . json_encode($bad));
			} catch (ValidationException $e) {
				self::assertArrayHasKey('costCenterBudgets', $e->getFields());
			}
		}
	}

	public function testCostCenterBudgetsGetterAndOtherFieldsPreserved(): void
	{
		// Budgets must merge into the stored payload, not clobber other keys.
		$storedRow = ['payload' => '{"orgName":"Org","defaultLeadDays":[30]}', 'version' => 7];
		$this->store->method('load')->willReturnCallback(function () use (&$storedRow) { return $storedRow; });
		$this->store->method('save')->willReturnCallback(
			function (string $payload, int $version) use (&$storedRow) {
				$d = json_decode($payload, true);
				if ($d['orgName'] !== 'Org' || $d['defaultLeadDays'] !== [30]) {
					return false; // merge dropped unrelated keys
				}
				$storedRow = ['payload' => $payload, 'version' => $version + 1];
				return true;
			}
		);
		$this->svc->save(['costCenterBudgets' => ['IT' => 1]], 7);
		self::assertSame(['IT' => 1], $this->svc->getCostCenterBudgets());
	}

	public function testFeedTokenRoundTrip(): void
	{
		$this->random->method('generate')->willReturn('tokXYZ123');
		$appValues = [];
		$this->config->method('setAppValue')->willReturnCallback(
			function ($app, $key, $val) use (&$appValues) { $appValues[$key] = $val; }
		);
		$this->config->method('getAppValue')->willReturnCallback(
			function ($app, $key, $def = '') use (&$appValues) { return $appValues[$key] ?? $def; }
		);
		$this->config->method('getAppKeys')->willReturnCallback(
			function ($app) use (&$appValues) { return array_keys($appValues); }
		);

		$token = $this->svc->mintFeedToken('alice');
		self::assertSame('tokXYZ123', $token);
		// Stored is the HASH, not the raw token.
		self::assertSame(hash('sha256', 'tokXYZ123'), $appValues['feed_token_alice']);
		self::assertTrue($this->svc->hasFeedToken('alice'));
		self::assertSame('alice', $this->svc->uidForFeedToken('tokXYZ123'));
		self::assertNull($this->svc->uidForFeedToken('wrongtoken'));
		self::assertNull($this->svc->uidForFeedToken(str_repeat('x', 200)));
	}
	public function testSaveRejectsUnknownGroupIds(): void
	{
		$this->stored("{}", 3);
		// groupExists must be consulted — a gid that does not exist on the
		// server must never reach the stored payload (lockout footgun).
		$groups = $this->createMock(IGroupManager::class);
		$groups->method("groupExists")->willReturnCallback(
			static fn (string $gid): bool => $gid === "admin"
		);
		$svc = new SettingsService($this->store, $this->config, $this->random, $groups, $this->clock);
		$this->store->expects($this->never())->method("save");
		try {
			$svc->save(["managerGroups" => ["ghost_gid_xyz"]], 3);
			self::fail("expected ValidationException");
		} catch (ValidationException $e) {
			self::assertArrayHasKey("managerGroups", $e->getFields());
		}
	}

	public function testSaveRejectsManagerGroupsWithNoMembers(): void
	{
		$this->stored("{}", 3);
		// A real but memberless group as the only manager group locks every
		// human out of settings — reject before it can be stored.
		$groups = $this->createMock(IGroupManager::class);
		$groups->method("groupExists")->willReturn(true);
		$emptyGroup = $this->createMock(IGroup::class);
		$emptyGroup->method("getUsers")->willReturn([]);
		$groups->method("get")->willReturn($emptyGroup);
		$svc = new SettingsService($this->store, $this->config, $this->random, $groups, $this->clock);
		$this->store->expects($this->never())->method("save");
		try {
			$svc->save(["managerGroups" => ["real_empty_group"]], 3);
			self::fail("expected ValidationException");
		} catch (ValidationException $e) {
			self::assertArrayHasKey("managerGroups", $e->getFields());
		}
	}

	public function testSaveRejectsUnknownSettingsKeys(): void
	{
		$this->stored("{}", 3);
		// Unknown top-level keys must be rejected, not silently dropped —
		// a typo'd key otherwise looks like a successful save.
		$this->store->expects($this->never())->method("save");
		try {
			$this->svc->save(["evilKey" => "x", "managerGroups" => ["admin"]], 3);
			self::fail("expected ValidationException");
		} catch (ValidationException $e) {
			self::assertArrayHasKey("evilKey", $e->getFields());
		}
	}

	public function testSaveAcceptsKnownGroupWithMembers(): void
	{
		$this->stored("{}", 3);
		$this->store->method("save")->willReturn(true);
		$this->svc->save(["managerGroups" => ["admin"]], 3);
		self::assertTrue(true); // reached without exception
	}
}
