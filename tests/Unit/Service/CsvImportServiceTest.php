<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Service;

use OCA\ContractCheck\Db\Contract;
use OCA\ContractCheck\Db\Party;
use OCA\ContractCheck\Exception\ValidationException;
use OCA\ContractCheck\Service\ContractService;
use OCA\ContractCheck\Service\ContractValidator;
use OCA\ContractCheck\Service\CsvImportService;
use OCA\ContractCheck\Service\PartyService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CsvImportServiceTest extends TestCase
{
	private const HEADER = 'title;type;scope;term_kind;start;end;notice;notice_unit;anchor;signed';

	/** Mocked create() runs the real validator — the import/validation seam under test. */
	private function svc(array &$created, ?PartyService $parties = null): CsvImportService
	{
		$contracts = $this->createMock(ContractService::class);
		$contracts->method('create')->willReturnCallback(
			function (array $data, string $actorUid) use (&$created): Contract {
				// The real validation boundary: whatever the import maps to,
				// it must survive (or be rejected by) the same validator the
				// interactive API uses.
				ContractValidator::validate($data, [30]);
				$created[] = [$data, $actorUid];

				return new Contract();
			}
		);
		$parties ??= $this->createMock(PartyService::class);
		$parties->method('listForUser')->willReturn([]);

		return new CsvImportService($contracts, $parties, $this->createMock(LoggerInterface::class));
	}

	public function testScopeTypoMustNotSilentlyWidenToOrg(): void
	{
		$created = [];
		$svc = $this->svc($created);
		// 'restricte' is a typo for 'restricted' — defaulting to 'org' would
		// expose a confidential contract to the whole org without an error.
		$csv = self::HEADER . "\nAcme;rent;restricte;fixed;2026-01-01;2027-01-01;3;months;contract_end;2025-12-01\n";
		$result = $svc->import($csv, 'manager1');
		self::assertSame(0, $result['imported']);
		self::assertNotEmpty($result['errors']);
		self::assertSame([], $created);
	}

	public function testTypeTypoMustNotSilentlyBecomeOther(): void
	{
		$created = [];
		$svc = $this->svc($created);
		$csv = self::HEADER . "\nAcme;leetinsing;org;fixed;2026-01-01;2027-01-01;3;months;contract_end;2025-12-01\n";
		$result = $svc->import($csv, 'manager1');
		self::assertSame(0, $result['imported']);
		self::assertNotEmpty($result['errors']);
		self::assertSame([], $created);
	}

	public function testInvalidDateMustNotBeSilentlyDropped(): void
	{
		$created = [];
		$svc = $this->svc($created);
		// 32.13.2026 is garbage — dropping it to null loses a signed date
		// without the user ever knowing.
		$csv = self::HEADER . "\nAcme;rent;org;fixed;2026-01-01;2027-01-01;3;months;contract_end;32.13.2026\n";
		$result = $svc->import($csv, 'manager1');
		self::assertSame(0, $result['imported']);
		self::assertNotEmpty($result['errors']);
		self::assertSame([], $created);
	}

	public function testPartyAutoCreateAttributesToRealActor(): void
	{
		$created = [];
		$parties = $this->createMock(PartyService::class);
		$parties->method('listForUser')->willReturn([]);
		$party = new Party();
		$party->setId(7);
		$parties->expects($this->once())
			->method('create')
			->with(
				self::anything(),
				self::equalTo('manager1') // the importing user, not a literal 'import' string
			)
			->willReturn($party);
		$svc = $this->svc($created, $parties);
		$csv = self::HEADER . ";partner\nAcme;rent;org;fixed;2026-01-01;2027-01-01;3;months;contract_end;2025-12-01;NewVendor\n";
		$result = $svc->import($csv, 'manager1');
		self::assertSame([], $result['errors']); // debug
		self::assertSame(1, $result['imported']);
		self::assertSame(7, $created[0][0]['partnerId']);
	}

	public function testValidRowImports(): void
	{
		$created = [];
		$svc = $this->svc($created);
		$csv = self::HEADER . "\nAcme;rent;restricted;fixed;2026-01-01;2027-01-01;3;months;contract_end;01.12.2025\n";
		$result = $svc->import($csv, 'manager1');
		self::assertSame(1, $result['imported']);
		self::assertSame('restricted', $created[0][0]['scope']);
		self::assertSame('2025-12-01', $created[0][0]['signedAt']); // d.m.Y normalized
		self::assertSame('manager1', $created[0][1]);
	}

	public function testOverLengthTitleImportsWithWarning(): void
	{
		$created = [];
		$svc = $this->svc($created);
		$longTitle = str_repeat('A', 300);
		$csv = self::HEADER . "\n" . $longTitle . ";rent;org;fixed;2026-01-01;2027-01-01;3;months;contract_end;2025-12-01\n";
		$result = $svc->import($csv, 'manager1');
		// The row imports (validator clamps), but the truncation must surface
		// as a warning — silent data loss in bulk imports is inexcusable.
		self::assertSame(1, $result['imported']);
		self::assertSame([], $result['errors']);
		self::assertNotEmpty($result['warnings']);
		self::assertSame('title', $result['warnings'][0]['field']);
		self::assertSame(2, $result['warnings'][0]['line']);
	}
}
