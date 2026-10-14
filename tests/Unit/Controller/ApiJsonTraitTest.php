<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Controller;

use OCA\ContractCheck\Controller\ApiJsonTrait;
use OCA\ContractCheck\Exception\ConflictException;
use OCA\ContractCheck\Exception\ForbiddenException;
use OCA\ContractCheck\Exception\NotFoundException;
use OCA\ContractCheck\Exception\StateException;
use OCA\ContractCheck\Exception\ValidationException;
use OCP\AppFramework\Http;
use PHPUnit\Framework\TestCase;

final class ApiJsonTraitTest extends TestCase
{
	private object $ctl;

	protected function setUp(): void
	{
		// Anonymous harness — exposes the protected trait helpers as public.
		$this->ctl = new class {
			use ApiJsonTrait;
			public function pubOk(mixed $d = null, int $s = 200) { return $this->ok($d, $s); }
			public function pubNotFound() { return $this->notFound(); }
			public function pubFromThrowable(\Throwable $e) { return $this->fromThrowable($e); }
			public function pubBool(mixed $v, bool $d = false) { return $this->paramBool($v, $d); }
			public function pubId(mixed $v) { return $this->paramId($v); }
		};
	}

	private static function body($res): array
	{
		return $res->getData();
	}

	public function testOkEnvelope(): void
	{
		$r = self::body($this->ctl->pubOk(['x' => 1]));
		self::assertTrue($r['success']);
		self::assertSame(['x' => 1], $r['data']);
	}

	public function testNotFoundIsCanonical(): void
	{
		$res = $this->ctl->pubNotFound();
		self::assertSame(Http::STATUS_NOT_FOUND, $res->getStatus());
		$r = self::body($res);
		self::assertFalse($r['success']);
		self::assertSame('NOT_FOUND', $r['error']);
		self::assertSame('Not found.', $r['message']);
		self::assertEquals(new \stdClass(), $r['fields'] ?? $r['fields']);
	}

	public function testFromThrowableCollapsesNotFound(): void
	{
		// "missing" and "no access" serialize identically (no existence oracle).
		$r = self::body($this->ctl->pubFromThrowable(new NotFoundException('secret detail')));
		self::assertSame('NOT_FOUND', $r['error']);
		self::assertStringNotContainsString('secret', $r['message']);
	}

	public function testConflict409(): void
	{
		$res = $this->ctl->pubFromThrowable(new ConflictException('stale revision'));
		self::assertSame(Http::STATUS_CONFLICT, $res->getStatus());
		self::assertSame('CONFLICT', self::body($res)['error']);
	}

	public function testStateConflict409(): void
	{
		$res = $this->ctl->pubFromThrowable(new StateException('STATE_INVALID', 'nope'));
		self::assertSame(Http::STATUS_CONFLICT, $res->getStatus());
	}

	public function testValidationCarriesFields(): void
	{
		$e = new ValidationException('VALIDATION', 'check fields', ['title' => 'required']);
		$r = self::body($this->ctl->pubFromThrowable($e));
		self::assertSame('VALIDATION', $r['error']);
		self::assertSame(['title' => 'required'], $r['fields']);
	}

	public function testForbidden403(): void
	{
		$res = $this->ctl->pubFromThrowable(new ForbiddenException('x'));
		self::assertSame(Http::STATUS_FORBIDDEN, $res->getStatus());
		self::assertSame('FORBIDDEN', self::body($res)['error']);
	}

	public function testUnknownThrowableIs500(): void
	{
		$res = $this->ctl->pubFromThrowable(new \RuntimeException('db gone'));
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $res->getStatus());
		self::assertSame('SERVER_ERROR', self::body($res)['error']);
	}

	public static function bools(): iterable
	{
		yield ['false', false];
		yield ['0', false];
		yield ['no', false];
		yield ['true', true];
		yield ['1', true];
		yield ['yes', true];
		yield [1, true];
		yield [0, false];
		yield [true, true];
		yield [false, false];
	}

	/** @dataProvider bools */
	public function testParamBoolNeverStringTruthy(mixed $v, bool $expected): void
	{
		self::assertSame($expected, $this->ctl->pubBool($v));
	}

	public static function ids(): iterable
	{
		yield ['5', 5];
		yield [5, 5];
		yield ['2.9', null];
		yield ['2e3', null];
		yield [' 5', null];
		yield ['05', 5];
		yield ['abc', null];
		yield [0, null];
		yield [-1, null];
		yield [null, null];
	}

	/** @dataProvider ids */
	public function testParamIdStrictDigits(mixed $v, ?int $expected): void
	{
		self::assertSame($expected, $this->ctl->pubId($v));
	}
}
