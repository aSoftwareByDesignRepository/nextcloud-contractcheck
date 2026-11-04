<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Support;

use OCA\ContractCheck\Support\SafeHomeUrl;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

final class SafeHomeUrlTest extends TestCase
{
	public function testUsesDefaultPageWhenAvailable(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToDefaultPageUrl')->willReturn('/index.php/apps/dashboard/');
		$this->assertSame('/index.php/apps/dashboard/', SafeHomeUrl::resolve($url));
	}

	public function testFallsBackWhenDefaultNavHrefMissing(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToDefaultPageUrl')->willThrowException(
			new \InvalidArgumentException('Default navigation entry is missing href: files')
		);
		$url->method('getAbsoluteURL')->with('/')->willReturn('https://nc.example/');
		$this->assertSame('https://nc.example/', SafeHomeUrl::resolve($url));
	}

	public function testFallsBackToRelativeRootWhenAbsoluteAlsoFails(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToDefaultPageUrl')->willThrowException(new \RuntimeException('boom'));
		$url->method('getAbsoluteURL')->willThrowException(new \RuntimeException('boom2'));
		$this->assertSame('/', SafeHomeUrl::resolve($url));
	}
}
