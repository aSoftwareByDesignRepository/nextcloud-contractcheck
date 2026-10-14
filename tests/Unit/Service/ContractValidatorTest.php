<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Service;

use OCA\ContractCheck\Exception\ValidationException;
use OCA\ContractCheck\Service\ContractValidator;
use PHPUnit\Framework\TestCase;

final class ContractValidatorTest extends TestCase
{
	private static function base(array $over = []): array
	{
		return array_merge([
			'title' => 'Office rent',
			'type' => 'rent',
			'scope' => 'org',
			'termKind' => 'fixed',
			'startDate' => '2027-01-01',
			'endDate' => '2027-12-31',
			'noticeValue' => 3,
			'noticeUnit' => 'months',
			'noticeAnchor' => 'contract_end',
		], $over);
	}

	public function testValidPayloadNormalizes(): void
	{
		$out = ContractValidator::validate(self::base(), [60, 30]);
		self::assertSame('Office rent', $out['title']);
		self::assertSame([60, 30], $out['leadDays']);
	}

	public function testTitleRequired(): void
	{
		$this->expectException(ValidationException::class);
		ContractValidator::validate(self::base(['title' => '  ']), []);
	}

	public function testTypeEnumRejectsUnknown(): void
	{
		try {
			ContractValidator::validate(self::base(['type' => 'spaceship']), []);
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('type', $e->getFields());
		}
	}

	public function testScopeEnumRejectsUnknown(): void
	{
		try {
			ContractValidator::validate(self::base(['scope' => 'public-internet']), []);
			self::fail();
		} catch (ValidationException $e) {
			self::assertArrayHasKey('scope', $e->getFields());
		}
	}

	public function testAutoRenewRequiresRenewalFields(): void
	{
		try {
			ContractValidator::validate(self::base([
				'termKind' => 'auto_renew', 'renewalValue' => null, 'renewalUnit' => null,
			]), []);
			self::fail();
		} catch (ValidationException $e) {
			self::assertArrayHasKey('renewalValue', $e->getFields());
			self::assertArrayHasKey('renewalUnit', $e->getFields());
		}
	}

	public function testIndefiniteRejectsEndDate(): void
	{
		try {
			ContractValidator::validate(self::base([
				'termKind' => 'indefinite', 'endDate' => '2027-12-31', 'noticeAnchor' => 'month_end',
			]), []);
			self::fail();
		} catch (ValidationException $e) {
			self::assertArrayHasKey('endDate', $e->getFields());
		}
	}

	public function testIndefiniteRejectsContractEndAnchor(): void
	{
		try {
			ContractValidator::validate(self::base([
				'termKind' => 'indefinite', 'endDate' => null, 'noticeAnchor' => 'contract_end',
			]), []);
			self::fail();
		} catch (ValidationException $e) {
			self::assertArrayHasKey('noticeAnchor', $e->getFields());
		}
	}

	public function testFixedRequiresEndDate(): void
	{
		try {
			ContractValidator::validate(self::base(['endDate' => null]), []);
			self::fail();
		} catch (ValidationException $e) {
			self::assertSame('CONTRACT_DATES', $e->getErrorCode());
		}
	}

	public function testEndMustBeAfterStart(): void
	{
		try {
			ContractValidator::validate(self::base(['endDate' => '2027-01-01']), []);
			self::fail();
		} catch (ValidationException $e) {
			self::assertArrayHasKey('endDate', $e->getFields());
		}
	}

	public function testSignedAtAfterStartRejected(): void
	{
		try {
			ContractValidator::validate(self::base(['signedAt' => '2027-06-01']), []);
			self::fail();
		} catch (ValidationException $e) {
			self::assertArrayHasKey('signedAt', $e->getFields());
		}
	}

	public function testPartnerIdStrictDigits(): void
	{
		foreach (['abc', '2.9', '2e3', ' 5', '5 '] as $bad) {
			try {
				ContractValidator::validate(self::base(['partnerId' => $bad]), []);
				self::fail("partnerId {$bad} accepted");
			} catch (ValidationException $e) {
				self::assertArrayHasKey('partnerId', $e->getFields());
			}
		}
		$out = ContractValidator::validate(self::base(['partnerId' => '5']), []);
		self::assertSame(5, $out['partnerId']);
	}

	public function testAnytimeNeedsNoNotice(): void
	{
		$out = ContractValidator::validate(self::base([
			'termKind' => 'indefinite', 'endDate' => null,
			'noticeAnchor' => 'anytime', 'noticeValue' => null, 'noticeUnit' => null,
		]), []);
		self::assertSame(0, $out['noticeValue']);
		self::assertSame('days', $out['noticeUnit']);
	}

	public function testLeadDaysDefaultAndDedup(): void
	{
		$out = ContractValidator::validate(self::base(['leadDays' => [30, 60, 30]]), [60, 30]);
		self::assertSame([60, 30], $out['leadDays'], 'dedup + desc sort');
	}

	public function testLeadDaysRejectsTooManyEntries(): void
	{
		// Each entry fans out to (reminder row + audit event + notification
		// per recipient) — an unbounded list is a spam/write-amplifcation
		// vector, so the validator must cap cardinality.
		try {
			ContractValidator::validate(self::base(['leadDays' => range(1, 11)]), []);
			self::fail();
		} catch (ValidationException $e) {
			self::assertArrayHasKey('leadDays', $e->getFields());
		}
	}

	public function testLeadDaysRejectsNonList(): void
	{
		try {
			ContractValidator::validate(self::base(['leadDays' => '30']), []);
			self::fail();
		} catch (ValidationException $e) {
			self::assertArrayHasKey('leadDays', $e->getFields());
		}
	}

	public function testTagsCappedAt20Trimmed(): void
	{
		$tags = array_map(static fn ($i) => "tag$i", range(1, 25));
		try {
			ContractValidator::validate(self::base(['tags' => $tags]), []);
		} catch (ValidationException $e) {
			self::assertArrayHasKey('tags', $e->getFields());
		}
		$out = ContractValidator::validate(self::base(['tags' => [' a ', 'b', 'a']]), []);
		self::assertSame(['a', 'b'], $out['tags']);
	}

	public function testNegativeNoticeRejected(): void
	{
		try {
			ContractValidator::validate(self::base(['noticeValue' => -1]), []);
			self::fail();
		} catch (ValidationException $e) {
			self::assertSame('NOTICE_PERIOD', $e->getErrorCode());
		}
	}

	public function testHugeNoticeRejected(): void
	{
		try {
			ContractValidator::validate(self::base(['noticeValue' => 99999, 'noticeUnit' => 'months']), []);
			self::fail();
		} catch (ValidationException $e) {
			self::assertArrayHasKey('noticeValue', $e->getFields());
		}
	}
}
