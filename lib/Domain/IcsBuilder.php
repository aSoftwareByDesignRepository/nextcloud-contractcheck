<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Domain;

/**
 * RFC 5545 VCALENDAR/VEVENT renderer for the deadline feed.
 * All-day events on the cancel-by date, plus a VTODO-style companion for
 * the effective date is intentionally NOT emitted — one clear event per
 * deadline, alarms from the contract's lead days.
 */
final class IcsBuilder
{
	private function __construct()
	{
	}

	/**
	 * @param list<array{uid:string, title:string, cancelBy:string, effectiveAt:?string, leadDays:list<int>}> $events
	 * @param string $dtstamp UTC stamp (Ymd\THis\Z) — supplied by the caller's Clock
	 */
	public static function build(string $calendarName, array $events, string $dtstamp): string
	{
		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//ContractCheck//Nextcloud//EN',
			'CALSCALE:GREGORIAN',
			'X-WR-CALNAME:' . self::escape($calendarName),
		];
		foreach ($events as $e) {
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:' . $e['uid'];
			$lines[] = 'DTSTAMP:' . $dtstamp;
			$lines[] = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $e['cancelBy']);
			// DTEND is exclusive for all-day events → +1 day.
			$lines[] = 'DTEND;VALUE=DATE:' . str_replace('-', '', DateMath::addDays($e['cancelBy'], 1));
			$lines[] = 'SUMMARY:' . self::escape($e['title']);
			if ($e['effectiveAt'] !== null) {
				$lines[] = 'DESCRIPTION:' . self::escape('Effective: ' . $e['effectiveAt']);
			}
			foreach ($e['leadDays'] as $lead) {
				if ($lead <= 0) {
					continue;
				}
				$lines[] = 'BEGIN:VALARM';
				$lines[] = 'ACTION:DISPLAY';
				$lines[] = 'TRIGGER:-P' . $lead . 'D';
				$lines[] = 'DESCRIPTION:' . self::escape($e['title']);
				$lines[] = 'END:VALARM';
			}
			$lines[] = 'END:VEVENT';
		}
		$lines[] = 'END:VCALENDAR';

		return implode("\r\n", self::fold($lines)) . "\r\n";
	}

	private static function escape(string $s): string
	{
		return str_replace(
			['\\', ';', ',', "\r\n", "\n", "\r"],
			['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'],
			$s
		);
	}

	/**
	 * Fold lines at 75 octets per RFC 5545 §3.1 (continuation = single space).
	 *
	 * @param list<string> $lines
	 * @return list<string>
	 */
	private static function fold(array $lines): array
	{
		$out = [];
		foreach ($lines as $line) {
			while (strlen($line) > 75) {
				$out[] = substr($line, 0, 75);
				$line = ' ' . substr($line, 75);
			}
			$out[] = $line;
		}

		return $out;
	}
}
