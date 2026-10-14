<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Controller;

use OCA\ContractCheck\Exception\AppAccessDeniedException;
use OCA\ContractCheck\Exception\ConflictException;
use OCA\ContractCheck\Exception\ForbiddenException;
use OCA\ContractCheck\Exception\NotFoundException;
use OCA\ContractCheck\Exception\StateException;
use OCA\ContractCheck\Exception\ValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Canonical JSON envelope (family api-security contract):
 *   ok:      {"success": true, "data": ...}
 *   fail:    {"success": false, "error": "CODE", "message": "...", "fields": {...}}
 *
 * Uniform-404: every "entity not visible" denial — missing, foreign, or
 * out-of-scope — collapses to ONE canonical payload. No existence oracle.
 */
trait ApiJsonTrait
{
	protected function ok(mixed $data = null, int $status = Http::STATUS_OK): JSONResponse
	{
		return new JSONResponse(['success' => true, 'data' => $data], $status, [], JSON_INVALID_UTF8_SUBSTITUTE);
	}

	/**
	 * @param array<string, string> $fields
	 */
	protected function fail(string $error, string $message, array $fields = [], int $status = Http::STATUS_BAD_REQUEST): JSONResponse
	{
		return new JSONResponse([
			'success' => false,
			'error' => $error,
			'message' => $message,
			'fields' => $fields === [] ? new \stdClass() : $fields,
		], $status, [], JSON_INVALID_UTF8_SUBSTITUTE);
	}

	protected function notFound(): JSONResponse
	{
		return $this->fail('NOT_FOUND', 'Not found.', [], Http::STATUS_NOT_FOUND);
	}

	protected function fromThrowable(\Throwable $e): JSONResponse
	{
		if ($e instanceof NotFoundException) {
			return $this->notFound();
		}
		if ($e instanceof ValidationException) {
			return $this->fail($e->getErrorCode(), $e->getMessage(), $e->getFields(), Http::STATUS_BAD_REQUEST);
		}
		if ($e instanceof ConflictException) {
			return $this->fail('CONFLICT', $e->getMessage(), [], Http::STATUS_CONFLICT);
		}
		if ($e instanceof StateException) {
			return $this->fail($e->getErrorCode(), $e->getMessage(), [], Http::STATUS_CONFLICT);
		}
		if ($e instanceof ForbiddenException || $e instanceof AppAccessDeniedException) {
			return $this->fail('FORBIDDEN', 'Access denied.', [], Http::STATUS_FORBIDDEN);
		}

		return $this->fail('SERVER_ERROR', 'Unexpected error', [], Http::STATUS_INTERNAL_SERVER_ERROR);
	}

	/**
	 * Strict boolean for request params — the literal string "false" is
	 * never truthy (family rule §4).
	 */
	protected function paramBool(mixed $v, bool $default = false): bool
	{
		if ($v === null) {
			return $default;
		}
		if (is_bool($v)) {
			return $v;
		}
		if (is_int($v)) {
			return $v === 1;
		}
		if (is_string($v)) {
			return in_array(strtolower($v), ['1', 'true', 'yes'], true);
		}

		return $default;
	}

	/** Strict positive int id — never is_numeric-cast. */
	protected function paramId(mixed $v): ?int
	{
		if (is_int($v) && $v > 0) {
			return $v;
		}
		if (is_string($v) && preg_match('/^\d+$/', $v) === 1) {
			$i = (int)$v;

			return $i > 0 ? $i : null;
		}

		return null;
	}
}
