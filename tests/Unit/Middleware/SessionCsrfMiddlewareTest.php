<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Controller {
	// Fixture: a controller that lives inside the guarded namespace. PHPUnit
	// mocks land in a Mock_* namespace and would be (correctly) skipped.
	class SessionCsrfProbeController extends \OCP\AppFramework\Controller
	{
		public function __construct()
		{
		}
	}
}

namespace OCA\ContractCheck\Tests\Unit\Middleware {

use OCA\ContractCheck\Exception\SessionCsrfException;
use OCA\ContractCheck\Middleware\SessionCsrfMiddleware;
use OCA\ContractCheck\Service\CsrfTokenValidator;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class SessionCsrfMiddlewareTest extends TestCase
{
	private function request(array $opts): IRequest
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn($opts['method'] ?? 'POST');
		$request->method('getHeader')->willReturnCallback(
			fn (string $name) => match (strtolower($name)) {
				'authorization' => $opts['authorization'] ?? '',
				'requesttoken' => $opts['headerToken'] ?? '',
				default => '',
			},
		);
		$request->method('getParam')->willReturnCallback(
			fn (string $name) => $name === 'requesttoken' ? ($opts['paramToken'] ?? null) : null,
		);
		return $request;
	}

	private function session(bool $loggedIn): IUserSession
	{
		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$session->method('getUser')->willReturn($loggedIn ? $user : null);
		return $session;
	}

	private function validator(bool $valid): CsrfTokenValidator
	{
		$validator = $this->createMock(CsrfTokenValidator::class);
		$validator->method('isValid')->willReturn($valid);
		return $validator;
	}

	private function controller(): object
	{
		return new \OCA\ContractCheck\Controller\SessionCsrfProbeController();
	}

	/** The whole point of the middleware: OCS-APIRequest must not open the door. */
	public function testSessionMutationWithOcsHeaderButNoTokenIsRejected(): void
	{
		$mw = new SessionCsrfMiddleware(
			$this->request(['headerToken' => '']),
			$this->session(true),
			$this->validator(true),
		);
		$this->expectException(SessionCsrfException::class);
		$mw->beforeController($this->controller(), 'createContract');
	}

	public function testSessionMutationWithInvalidTokenIsRejected(): void
	{
		$mw = new SessionCsrfMiddleware(
			$this->request(['headerToken' => 'bogus']),
			$this->session(true),
			$this->validator(false),
		);
		$this->expectException(SessionCsrfException::class);
		$mw->beforeController($this->controller(), 'createContract');
	}

	public function testSessionMutationWithValidTokenPasses(): void
	{
		$mw = new SessionCsrfMiddleware(
			$this->request(['headerToken' => 'good']),
			$this->session(true),
			$this->validator(true),
		);
		$mw->beforeController($this->controller(), 'createContract');
		$this->addToAssertionCount(1);
	}

	public function testParamTokenIsAccepted(): void
	{
		$mw = new SessionCsrfMiddleware(
			$this->request(['paramToken' => 'good']),
			$this->session(true),
			$this->validator(true),
		);
		$mw->beforeController($this->controller(), 'createContract');
		$this->addToAssertionCount(1);
	}

	public function testGetRequestIsNeverChecked(): void
	{
		$mw = new SessionCsrfMiddleware(
			$this->request(['method' => 'GET']),
			$this->session(true),
			$this->validator(false),
		);
		$mw->beforeController($this->controller(), 'listContracts');
		$this->addToAssertionCount(1);
	}

	public function testBasicAuthRequestIsExempt(): void
	{
		$mw = new SessionCsrfMiddleware(
			$this->request(['authorization' => 'Basic abc123']),
			$this->session(true),
			$this->validator(false),
		);
		$mw->beforeController($this->controller(), 'createContract');
		$this->addToAssertionCount(1);
	}

	public function testAnonymousRequestIsNotGuardedHere(): void
	{
		$mw = new SessionCsrfMiddleware(
			$this->request([]),
			$this->session(false),
			$this->validator(false),
		);
		$mw->beforeController($this->controller(), 'createContract');
		$this->addToAssertionCount(1);
	}

	public function testNonContractCheckControllerIsSkipped(): void
	{
		$mw = new SessionCsrfMiddleware(
			$this->request([]),
			$this->session(true),
			$this->validator(false),
		);
		$mw->beforeController(new \stdClass(), 'anything');
		$this->addToAssertionCount(1);
	}

	public function testExceptionSerializesTo412WithStableCode(): void
	{
		$mw = new SessionCsrfMiddleware(
			$this->request([]),
			$this->session(true),
			$this->validator(false),
		);
		$response = $mw->afterException($this->controller(), 'x', new SessionCsrfException());
		$this->assertSame(412, $response->getStatus());
		$this->assertSame('CSRF_FAILED', $response->getData()['error']);
	}
}

}
