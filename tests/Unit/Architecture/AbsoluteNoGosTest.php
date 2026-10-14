<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

final class AbsoluteNoGosTest extends TestCase
{
	public function testArchitectureScriptPasses(): void
	{
		$script = dirname(__DIR__, 2) . '/Architecture/absolute-no-gos.php';
		self::assertFileExists($script);
		passthru('php ' . escapeshellarg($script), $code);
		self::assertSame(0, $code);
	}
}
