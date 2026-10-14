<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Service;

use OCA\ContractCheck\Db\Contract;
use OCA\ContractCheck\Exception\NotFoundException;
use OCA\ContractCheck\Service\AccessService;
use OCA\ContractCheck\Service\SettingsService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AccessServiceTest extends TestCase
{
	private SettingsService&MockObject $settings;
	private IGroupManager&MockObject $groups;
	private IUserManager&MockObject $users;
	private IDBConnection&MockObject $db;
	private AccessService $access;

	protected function setUp(): void
	{
		$this->settings = $this->createMock(SettingsService::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->access = new AccessService($this->settings, $this->groups, $this->users);
	}

	private function managerGroups(array $g): void
	{
		$this->settings->method('getManagerGroups')->willReturn($g);
	}

	private function contract(string $scope, ?string $owner): Contract
	{
		$c = new Contract();
		$c->setScope($scope);
		$c->setOwnerUid($owner);
		return $c;
	}

	private function stubUser(string $uid): IUser
	{
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		return $u;
	}

	public function testNoUidNeverHasAccess(): void
	{
		self::assertFalse($this->access->hasAppAccess(null));
		self::assertFalse($this->access->hasAppAccess(''));
		self::assertFalse($this->access->isManager(null));
		self::assertFalse($this->access->canViewCosts(null));
	}

	public function testEmptyAllowedGroupsMeansEveryone(): void
	{
		$this->settings->method('getAllowedGroups')->willReturn([]);
		self::assertTrue($this->access->hasAppAccess('alice'));
	}

	public function testAllowedGroupsGateMembership(): void
	{
		$this->settings->method('getAllowedGroups')->willReturn(['staff']);
		$this->groups->method('isInGroup')->willReturnCallback(
			static fn ($uid, $gid) => $uid === 'alice' && $gid === 'staff'
		);
		self::assertTrue($this->access->hasAppAccess('alice'));
		self::assertFalse($this->access->hasAppAccess('bob'));
	}

	public function testManagerSeesEverything(): void
	{
		$this->managerGroups(['admin']);
		$this->groups->method('isInGroup')->willReturnCallback(
			static fn ($uid, $gid) => $uid === 'root' && $gid === 'admin'
		);
		self::assertTrue($this->access->isManager('root'));
		self::assertTrue($this->access->canViewCosts('root'));
		self::assertTrue($this->access->canEdit('root'));
		self::assertTrue($this->access->canSee($this->contract('restricted', 'other'), 'root'));
		self::assertSame(['1 = 1'], $this->access->visibilityScope('root')['fragments']);
	}

	public function testOrgScopeVisibleToAllUsers(): void
	{
		$this->managerGroups([]);
		$this->settings->method('getCostViewerGroups')->willReturn([]);
		self::assertTrue($this->access->canSee($this->contract('org', 'someone'), 'alice'));
	}

	public function testDeptScopeRequiresSharedGroup(): void
	{
		$this->managerGroups([]);
		$this->users->method('get')->willReturnCallback(
			fn ($uid) => $this->stubUser($uid)
		);
		$this->groups->method('getUserGroupIds')->willReturnCallback(
			static fn (IUser $u) => match ($u->getUID()) {
				'owner1' => ['sales'],
				'colleague' => ['sales', 'hr'],
				'stranger' => ['it'],
				default => [],
			}
		);
		$c = $this->contract('dept', 'owner1');
		self::assertTrue($this->access->canSee($c, 'colleague'));
		self::assertFalse($this->access->canSee($c, 'stranger'));
		self::assertTrue($this->access->canSee($c, 'owner1'), 'owner always sees own');
	}

	public function testRestrictedOnlyOwnerAndManager(): void
	{
		$this->managerGroups(['admin']);
		$this->groups->method('isInGroup')->willReturnCallback(
			static fn ($uid, $gid) => $uid === 'boss' && $gid === 'admin'
		);
		$this->groups->method('getUserGroupIds')->willReturn([]);
		$c = $this->contract('restricted', 'owner1');
		self::assertTrue($this->access->canSee($c, 'owner1'));
		self::assertTrue($this->access->canSee($c, 'boss'));
		self::assertFalse($this->access->canSee($c, 'alice'));
	}

	public function testUnknownScopeFailsClosed(): void
	{
		$this->managerGroups([]);
		$c = $this->contract('weird-future-scope', 'owner1');
		self::assertFalse($this->access->canSee($c, 'alice'));
	}

	public function testRequireVisibleUniformsNotFound(): void
	{
		$this->managerGroups([]);
		$this->users->method('get')->willReturn(null);
		$this->expectException(NotFoundException::class);
		$this->access->requireVisible($this->contract('restricted', 'other'), 'alice');
	}

	public function testCostViewerRoleSeparateFromManager(): void
	{
		$this->managerGroups([]);
		$this->settings->method('getCostViewerGroups')->willReturn(['finance']);
		$this->groups->method('isInGroup')->willReturnCallback(
			static fn ($uid, $gid) => $uid === 'cfo' && $gid === 'finance'
		);
		self::assertTrue($this->access->canViewCosts('cfo'));
		self::assertFalse($this->access->canEdit('cfo'), 'cost-viewer is not a writer');
		self::assertFalse($this->access->canViewCosts('alice'));
	}

	public function testAssignOwnerRequiresUserExists(): void
	{
		$this->managerGroups(['admin']);
		$this->groups->method('isInGroup')->willReturn(true);
		$this->users->method('userExists')->willReturn(false);
		// Deleted user → ghost owner rejected as uniform 404.
		$this->expectException(NotFoundException::class);
		$this->access->assertAssignableOwner('root', 'ghost-user');
	}

	public function testNonManagerCannotAssignForeignOwner(): void
	{
		$this->managerGroups([]);
		$this->users->method('userExists')->willReturn(true);
		$this->users->method('get')->willReturnCallback(
			fn ($uid) => $this->stubUser($uid)
		);
		$this->groups->method('getUserGroupIds')->willReturnCallback(
			static fn (IUser $u) => $u->getUID() === 'alice' ? ['a'] : ['b']
		);
		$this->expectException(NotFoundException::class);
		$this->access->assertAssignableOwner('alice', 'bob');
	}

	public function testReminderRecipientsPreferOwner(): void
	{
		$c = $this->contract('org', 'owner1');
		$this->users->method('userExists')->willReturn(true);
		self::assertSame(['owner1'], $this->access->reminderRecipients($c));
	}

	public function testReminderRecipientsFallBackToManagerGroup(): void
	{
		$c = $this->contract('org', null);
		$this->users->method('userExists')->willReturn(true);
		$this->managerGroups(['admin']);
		$this->groups->method('displayNamesInGroup')->willReturn(['root' => 'Root', 'boss' => 'Boss']);
		$recipients = $this->access->reminderRecipients($c);
		sort($recipients);
		self::assertSame(['boss', 'root'], $recipients);
	}

	public function testReminderRecipientsEnumeratesAllGroupMembers(): void
	{
		// A capped enumeration (e.g. displayNamesInGroup limit=20) silently
		// drops reminder recipients — a missed deadline is a missed
		// cancellation window. The call must request the full member list.
		$c = $this->contract('org', null);
		$this->managerGroups(['admin']);
		$many = [];
		for ($i = 0; $i < 25; $i++) {
			$many['mgr' . $i] = 'Mgr ' . $i;
		}
		$this->groups->expects($this->once())
			->method('displayNamesInGroup')
			->with('admin', '', -1)
			->willReturn($many);
		self::assertCount(25, $this->access->reminderRecipients($c));
	}

	public function testReminderRecipientsSkipDeletedOwner(): void
	{
		$c = $this->contract('org', 'ghost');
		$this->users->method('userExists')->willReturn(false);
		$this->managerGroups(['admin']);
		$this->groups->method('displayNamesInGroup')->willReturn(['root' => 'Root']);
		self::assertSame(['root'], $this->access->reminderRecipients($c));
	}
}
