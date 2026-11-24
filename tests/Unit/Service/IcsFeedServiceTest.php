<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Service;

use OCA\ContractCheck\Db\Contract;
use OCA\ContractCheck\Domain\DeadlineResult;
use OCA\ContractCheck\Service\AccessService;
use OCA\ContractCheck\Service\Clock;
use OCA\ContractCheck\Service\ContractService;
use OCA\ContractCheck\Service\DeadlineService;
use OCA\ContractCheck\Service\IcsFeedService;
use OCA\ContractCheck\Service\SettingsService;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory as IL10NFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class IcsFeedServiceTest extends TestCase
{
	private SettingsService&MockObject $settings;
	private ContractService&MockObject $contracts;
	private DeadlineService&MockObject $deadlines;
	private AccessService&MockObject $access;
	private IUserManager&MockObject $users;
	private IL10NFactory&MockObject $l10nFactory;

	protected function setUp(): void
	{
		$this->settings = $this->createMock(SettingsService::class);
		$this->contracts = $this->createMock(ContractService::class);
		$this->deadlines = $this->createMock(DeadlineService::class);
		$this->access = $this->createMock(AccessService::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->l10nFactory = $this->createMock(IL10NFactory::class);
	}

	private function service(): IcsFeedService
	{
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(new \DateTimeImmutable('2027-06-01 12:00:00', new \DateTimeZone('UTC')));

		return new IcsFeedService(
			$this->settings,
			$this->contracts,
			$this->deadlines,
			$this->access,
			$clock,
			$this->users,
			$this->l10nFactory,
		);
	}

	/**
	 * IL10N mock backed by the real shipped catalog — the assertions below
	 * therefore prove actual translated strings land in the emitted ICS.
	 */
	private function catalogL10n(string $locale): IL10N&MockObject
	{
		$translations = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../l10n/' . $locale . '.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		)['translations'];
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $params = []) use ($translations): string {
				$value = $translations[$text] ?? $text;
				return $params === [] ? (string)$value : vsprintf((string)$value, $params);
			}
		);

		return $l10n;
	}

	private function contractFixture(): Contract
	{
		$c = new Contract();
		$c->setId(7);
		$c->setTitle('Mietvertrag Büro');
		$c->setLeadDaysJson('[30, 7]');

		return $c;
	}

	private function openFeed(string $lang = 'de'): array
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->users->method('get')->with('alice')->willReturn($user);
		$this->l10nFactory->method('getUserLanguage')->with($user)->willReturn($lang);
		$this->l10nFactory->method('get')->with('contractcheck', $lang)
			->willReturn($this->catalogL10n($lang));

		$this->settings->method('uidForFeedToken')->with('tok')->willReturn('alice');
		$this->settings->method('getOrgName')->willReturn('Acme GmbH');
		$this->access->method('hasAppAccess')->with('alice')->willReturn(true);
		$this->contracts->method('listForUser')->willReturn([$this->contractFixture()]);
		$this->deadlines->method('compute')->willReturn(
			new DeadlineResult(DeadlineResult::STATE_OPEN, '2027-09-30', '2027-12-31', null, null, 91)
		);

		$result = $this->service()->feedForToken('tok');
		self::assertNotNull($result);

		return $result;
	}

	public function testFeedRendersFeedOwnersLocale(): void
	{
		$ics = $this->openFeed('de')['ics'];

		// Real shipped de.msgstr must reach all three user-visible sites.
		self::assertStringContainsString('X-WR-CALNAME:Acme GmbH — Vertragsfristen', $ics);
		self::assertStringContainsString('SUMMARY:Kündigen bis Mietvertrag Büro', $ics);
		self::assertStringContainsString('DESCRIPTION:Wirksam zum: 2027-12-31', $ics);
	}

	public function testFeedRendersEnglishForEnOwner(): void
	{
		$ics = $this->openFeed('en')['ics'];

		self::assertStringContainsString('X-WR-CALNAME:Acme GmbH — Contract deadlines', $ics);
		self::assertStringContainsString('SUMMARY:Cancel by Mietvertrag Büro', $ics);
		self::assertStringContainsString('DESCRIPTION:Effective at: 2027-12-31', $ics);
	}

	public function testInvalidTokenYieldsNull(): void
	{
		$this->settings->method('uidForFeedToken')->willReturn(null);

		self::assertNull($this->service()->feedForToken('bogus'));
	}
}
