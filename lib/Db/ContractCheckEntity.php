<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity base with strict date normalization.
 *
 * DATE columns hydrate as \DateTimeInterface; the deadline engine and the
 * wire format only ever see 'Y-m-d' strings produced here. DATETIME
 * columns serialize as 'Y-m-d\TH:i:sP' (ISO-8601).
 */
abstract class ContractCheckEntity extends Entity
{
	protected function dateStr(string $property): ?string
	{
		$v = $this->{$property} ?? null;
		if ($v instanceof \DateTimeInterface) {
			return $v->format('Y-m-d');
		}
		if (is_string($v) && $v !== '') {
			return substr($v, 0, 10);
		}

		return null;
	}

	protected function dateTimeStr(string $property): ?string
	{
		$v = $this->{$property} ?? null;
		if ($v instanceof \DateTimeInterface) {
			return $v->format('Y-m-d\TH:i:sP');
		}
		if (is_string($v) && $v !== '') {
			return $v;
		}

		return null;
	}
}
