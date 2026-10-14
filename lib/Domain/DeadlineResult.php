<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Domain;

/**
 * Outcome of next_cancel_by() for one contract.
 *
 * state:
 *  - 'open'   → an actionable cancellation window exists (cancelBy >= today)
 *  - 'missed' → the only window (fixed term) already passed; cancelBy/E report
 *               that passed window so the UI can show "Frist verstrichen"
 *  - 'none'   → no computable deadline (missing rule data — validation should
 *               normally prevent this; the engine stays defensive)
 *
 * missedCancelBy / missedEffectiveAt carry the most recent PASSED window for
 * informational display on auto-renew contracts (the window you just missed
 * before the contract rolled forward).
 */
final class DeadlineResult
{
	public const STATE_OPEN = 'open';
	public const STATE_MISSED = 'missed';
	public const STATE_NONE = 'none';

	public function __construct(
		public readonly string $state,
		public readonly ?string $cancelBy,
		public readonly ?string $effectiveAt,
		public readonly ?string $missedCancelBy,
		public readonly ?string $missedEffectiveAt,
		public readonly ?int $daysLeft,
	) {
	}

	public static function none(): self
	{
		return new self(self::STATE_NONE, null, null, null, null, null);
	}

	public function isActionable(): bool
	{
		return $this->state === self::STATE_OPEN && $this->cancelBy !== null;
	}

	/**
	 * Stable identity of the computed deadline instance — used for reminder
	 * de-duplication. Changes when the effective date moves (amendment,
	 * roll-forward) and therefore correctly re-arms reminders.
	 */
	public function instanceId(int $contractId): string
	{
		return hash('sha256', $contractId . '|' . ($this->effectiveAt ?? 'none'));
	}

	/** @return array<string, mixed> */
	public function toArray(): array
	{
		return [
			'state' => $this->state,
			'cancelBy' => $this->cancelBy,
			'effectiveAt' => $this->effectiveAt,
			'missedCancelBy' => $this->missedCancelBy,
			'missedEffectiveAt' => $this->missedEffectiveAt,
			'daysLeft' => $this->daysLeft,
		];
	}
}
