<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Service;

use OCA\ContractCheck\Db\Contract;
use OCA\ContractCheck\Domain\DateMath;
use OCA\ContractCheck\Domain\DeadlineEngine;
use OCA\ContractCheck\Exception\ValidationException;

/**
 * Pure request-data → validated-field-map transformation.
 * Produces a ValidationException whose field map the UI renders inline
 * (field-errors contract) — no partial state escapes.
 */
final class ContractValidator
{
	public const TYPES = ['rent', 'leasing', 'service', 'insurance', 'maintenance', 'grant', 'employment_related', 'other'];
	public const SCOPES = ['org', 'dept', 'restricted'];
	public const TERM_KINDS = [DeadlineEngine::TERM_FIXED, DeadlineEngine::TERM_AUTO_RENEW, DeadlineEngine::TERM_INDEFINITE];
	public const NOTICE_UNITS = ['days', 'months'];
	public const RENEWAL_UNITS = ['months', 'years'];
	public const ANCHORS = [
		DeadlineEngine::ANCHOR_CONTRACT_END,
		DeadlineEngine::ANCHOR_MONTH_END,
		DeadlineEngine::ANCHOR_QUARTER_END,
		DeadlineEngine::ANCHOR_YEAR_END,
		DeadlineEngine::ANCHOR_ANYTIME,
	];

	public const MAX_NOTICE_MONTHS = 600;
	public const MAX_NOTICE_DAYS = 36500;
	public const MAX_RENEWAL_MONTHS = 600;
	public const MAX_LEAD_DAY = 3650;
	public const MAX_LEAD_DAYS = 10;
	public const STATUSES = ['draft', 'active', 'terminated', 'expired', 'archived'];

	private function __construct()
	{
	}

	/**
	 * Validate a create/update payload. Returns the normalized field set —
	 * callers persist exactly this, nothing else from the request.
	 *
	 * @param array<string, mixed> $in
	 * @param list<int> $defaultLeadDays
	 * @return array<string, mixed>
	 */
	public static function validate(array $in, array $defaultLeadDays, ?Contract $existing = null): array
	{
		$fields = [];
		$out = [];

		$out['title'] = self::str($in['title'] ?? '', 255);
		if ($out['title'] === '') {
			$fields['title'] = 'required';
		}

		$type = $in['type'] ?? 'other';
		$out['type'] = is_string($type) && in_array($type, self::TYPES, true) ? $type : null;
		if ($out['type'] === null) {
			$fields['type'] = 'invalid';
		}

		$out['refNo'] = self::nullableStr($in['refNo'] ?? null, 64);
		$out['partnerId'] = self::nullableStrictId($in['partnerId'] ?? null, 'partnerId', $fields);
		$out['ownerUid'] = self::nullableStr($in['ownerUid'] ?? null, 64);

		$scope = $in['scope'] ?? 'org';
		$out['scope'] = is_string($scope) && in_array($scope, self::SCOPES, true) ? $scope : null;
		if ($out['scope'] === null) {
			$fields['scope'] = 'invalid';
		}

		$out['signedAt'] = self::nullableDate($in['signedAt'] ?? null, 'signedAt', $fields);
		$out['startDate'] = self::nullableDate($in['startDate'] ?? null, 'startDate', $fields);
		$out['endDate'] = self::nullableDate($in['endDate'] ?? null, 'endDate', $fields);

		$termKind = $in['termKind'] ?? 'fixed';
		$out['termKind'] = is_string($termKind) && in_array($termKind, self::TERM_KINDS, true) ? $termKind : null;
		if ($out['termKind'] === null) {
			$fields['termKind'] = 'invalid';
		}

		// Renewal period — required iff auto_renew.
		$out['renewalValue'] = self::nullableInt($in['renewalValue'] ?? null, 'renewalValue', $fields);
		$renewalUnit = $in['renewalUnit'] ?? null;
		$out['renewalUnit'] = is_string($renewalUnit) && in_array($renewalUnit, self::RENEWAL_UNITS, true)
			? $renewalUnit : null;
		if ($out['termKind'] === DeadlineEngine::TERM_AUTO_RENEW) {
			if ($out['renewalValue'] === null || $out['renewalValue'] < 1 || $out['renewalValue'] > self::MAX_RENEWAL_MONTHS) {
				$fields['renewalValue'] = 'required (1–' . self::MAX_RENEWAL_MONTHS . ')';
			}
			if ($out['renewalUnit'] === null) {
				$fields['renewalUnit'] = 'required';
			}
		} else {
			$out['renewalValue'] = null;
			$out['renewalUnit'] = null;
		}

		// Notice period.
		$anchor = $in['noticeAnchor'] ?? DeadlineEngine::ANCHOR_CONTRACT_END;
		$out['noticeAnchor'] = is_string($anchor) && in_array($anchor, self::ANCHORS, true) ? $anchor : null;
		if ($out['noticeAnchor'] === null) {
			$fields['noticeAnchor'] = 'invalid';
		}
		$out['noticeValue'] = self::nullableInt($in['noticeValue'] ?? null, 'noticeValue', $fields);
		$noticeUnit = $in['noticeUnit'] ?? null;
		$out['noticeUnit'] = is_string($noticeUnit) && in_array($noticeUnit, self::NOTICE_UNITS, true)
			? $noticeUnit : null;
		if ($out['noticeAnchor'] === DeadlineEngine::ANCHOR_ANYTIME) {
			// Anytime needs no explicit notice — value defaults to 0 days.
			$out['noticeValue'] = $out['noticeValue'] ?? 0;
			$out['noticeUnit'] = $out['noticeUnit'] ?? 'days';
		} else {
			if ($out['noticeValue'] === null || $out['noticeValue'] < 0) {
				$fields['noticeValue'] = 'required (≥ 0)';
			} else {
				$cap = ($out['noticeUnit'] ?? 'days') === 'months' ? self::MAX_NOTICE_MONTHS : self::MAX_NOTICE_DAYS;
				if ($out['noticeValue'] > $cap) {
					$fields['noticeValue'] = 'too large';
				}
			}
			if ($out['noticeUnit'] === null) {
				$fields['noticeUnit'] = 'required';
			}
		}
		if ($out['termKind'] === DeadlineEngine::TERM_INDEFINITE
			&& $out['noticeAnchor'] === DeadlineEngine::ANCHOR_CONTRACT_END) {
			$fields['noticeAnchor'] = 'contract_end is meaningless for indefinite contracts';
		}

		// Term/date coherence (spec §9.5 CONTRACT_DATES).
		if ($out['termKind'] === DeadlineEngine::TERM_INDEFINITE) {
			if ($out['endDate'] !== null) {
				$fields['endDate'] = 'must be empty for indefinite contracts';
			}
		} else {
			if ($out['endDate'] === null) {
				$fields['endDate'] = 'required for fixed/auto-renew contracts';
			}
			if ($out['endDate'] !== null && $out['startDate'] !== null
				&& DateMath::compare($out['endDate'], $out['startDate']) <= 0) {
				$fields['endDate'] = 'must be after the start date';
			}
		}
		if ($out['signedAt'] !== null && $out['startDate'] !== null
			&& DateMath::compare($out['signedAt'], $out['startDate']) > 0) {
			$fields['signedAt'] = 'cannot be after the start date';
		}

		// Lead days.
		$out['leadDays'] = self::leadDays($in['leadDays'] ?? null, $defaultLeadDays, $fields);

		$out['costCenter'] = self::nullableStr($in['costCenter'] ?? null, 64);
		$out['tags'] = self::tagList($in['tags'] ?? [], $fields);
		$out['notes'] = self::nullableStr($in['notes'] ?? null, 10000);
		$out['reminderChannel'] = 'notify';

		if ($fields !== []) {
			$code = 'VALIDATION';
			if (isset($fields['endDate']) || isset($fields['signedAt']) || isset($fields['startDate'])) {
				$code = 'CONTRACT_DATES';
			} elseif (isset($fields['noticeValue']) || isset($fields['noticeUnit']) || isset($fields['noticeAnchor'])) {
				$code = 'NOTICE_PERIOD';
			} elseif (isset($fields['partnerId'])) {
				$code = 'PARTY_INVALID';
			}
			throw new ValidationException($code, 'Please check the highlighted fields.', $fields);
		}

		return $out;
	}

	/**
	 * Strict int — rejects "2.9", "2e3", " 5", booleans, floats.
	 * @param array<string, string> $fields
	 */
	public static function nullableStrictId(mixed $v, string $field, array &$fields): ?int
	{
		if ($v === null || $v === '') {
			return null;
		}
		if (is_int($v) && $v > 0) {
			return $v;
		}
		if (is_string($v) && preg_match('/^\d+$/', $v) === 1 && (int)$v > 0) {
			return (int)$v;
		}
		$fields[$field] = 'invalid id';

		return null;
	}

	private static function str(mixed $v, int $max): string
	{
		if (!is_string($v)) {
			return '';
		}

		return mb_substr(trim($v), 0, $max);
	}

	private static function nullableStr(mixed $v, int $max): ?string
	{
		$s = self::str($v, $max);

		return $s === '' ? null : $s;
	}

	/** @param array<string, string> $fields */
	private static function nullableDate(mixed $v, string $field, array &$fields): ?string
	{
		if ($v === null || $v === '') {
			return null;
		}
		if (is_string($v) && DateMath::isValid($v)) {
			return $v;
		}
		$fields[$field] = 'invalid date (YYYY-MM-DD)';

		return null;
	}

	/** @param array<string, string> $fields */
	private static function nullableInt(mixed $v, string $field, array &$fields): ?int
	{
		if ($v === null || $v === '') {
			return null;
		}
		if (is_int($v)) {
			return $v;
		}
		if (is_string($v) && preg_match('/^-?\d+$/', $v) === 1) {
			return (int)$v;
		}
		$fields[$field] = 'must be an integer';

		return null;
	}

	/**
	 * @return list<int>
	 */
	/**
	 * @param list<int> $default
	 * @param array<string, string> $fields
	 * @return list<int>
	 */
	private static function leadDays(mixed $v, array $default, array &$fields): array
	{
		if ($v === null) {
			return $default;
		}
		if (!is_array($v)) {
			$fields['leadDays'] = 'must be a list of day counts';

			return $default;
		}
		if (count($v) > self::MAX_LEAD_DAYS) {
			$fields['leadDays'] = 'at most ' . self::MAX_LEAD_DAYS . ' lead days';

			return $default;
		}
		$out = [];
		foreach ($v as $d) {
			if (!is_int($d) || $d < 0 || $d > self::MAX_LEAD_DAY) {
				$fields['leadDays'] = 'days must be integers between 0 and ' . self::MAX_LEAD_DAY;

				return $default;
			}
			$out[] = $d;
		}
		$out = array_values(array_unique($out));
		rsort($out, SORT_NUMERIC);

		return $out === [] ? $default : $out;
	}

	/**
	 * @param array<string, string> $fields
	 * @return list<string>
	 */
	private static function tagList(mixed $v, array &$fields): array
	{
		if (!is_array($v)) {
			return [];
		}
		$out = [];
		foreach ($v as $t) {
			if (!is_string($t)) {
				continue;
			}
			$t = mb_substr(trim($t), 0, 40);
			if ($t !== '') {
				$out[] = $t;
			}
		}
		if (count($out) > 20) {
			$fields['tags'] = 'at most 20 tags';
			$out = array_slice($out, 0, 20);
		}

		return array_values(array_unique($out));
	}

	/** Deadline-window filter values (post-computed, see DeadlineService). */
	public const DEADLINE_FILTERS = ['cancel30', 'cancel90', 'missed', 'expiring', 'none'];

	/**
	 * Normalize contract-list filter params (query strings). Fail-closed:
	 * an unparseable filter is a field error, never silently ignored —
	 * a dropped filter widens the visible set.
	 *
	 * @param array<string, mixed> $in raw query params
	 * @return array{type:?string, status:?string, partnerId:?int, costCenter:?string, deadline:?string}
	 */
	public static function filters(array $in): array
	{
		$out = ['type' => null, 'status' => null, 'partnerId' => null, 'costCenter' => null, 'deadline' => null];
		$fields = [];

		$type = $in['type'] ?? null;
		if ($type !== null && $type !== '') {
			if (is_string($type) && in_array($type, self::TYPES, true)) {
				$out['type'] = $type;
			} else {
				$fields['type'] = 'unknown contract type';
			}
		}
		$status = $in['status'] ?? null;
		if ($status !== null && $status !== '') {
			if (is_string($status) && in_array($status, self::STATUSES, true)) {
				$out['status'] = $status;
			} else {
				$fields['status'] = 'unknown status';
			}
		}
		$pid = $in['partnerId'] ?? null;
		if ($pid !== null && $pid !== '') {
			if (is_scalar($pid) && preg_match('/^\d+$/', (string)$pid) === 1) {
				$out['partnerId'] = (int)$pid;
			} else {
				$fields['partnerId'] = 'must be a numeric id';
			}
		}
		$cc = $in['costCenter'] ?? null;
		if ($cc !== null && $cc !== '') {
			if (is_string($cc) && strlen($cc) <= 64) {
				$out['costCenter'] = $cc;
			} else {
				$fields['costCenter'] = 'must be a cost-center name';
			}
		}
		$dl = $in['deadline'] ?? null;
		if ($dl !== null && $dl !== '') {
			if (is_string($dl) && in_array($dl, self::DEADLINE_FILTERS, true)) {
				$out['deadline'] = $dl;
			} else {
				$fields['deadline'] = 'unknown deadline window';
			}
		}
		if ($fields !== []) {
			throw new ValidationException('FILTERS_INVALID', 'Invalid filters', $fields);
		}
		return $out;
	}
}
