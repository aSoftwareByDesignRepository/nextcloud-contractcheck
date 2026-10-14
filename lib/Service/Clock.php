<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Service;

/**
 * Injectable clock — deadline logic must never call time() directly so the
 * entire worked-example corpus stays deterministic in tests.
 */
class Clock
{
	public function today(): string
	{
		return $this->now()->format('Y-m-d');
	}

	public function now(): \DateTimeImmutable
	{
		return new \DateTimeImmutable('now');
	}
}
