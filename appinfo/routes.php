<?php

declare(strict_types=1);

return [
	'routes' => [
		// Pages
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#dashboard', 'url' => '/dashboard', 'verb' => 'GET'],
		['name' => 'page#contracts', 'url' => '/contracts', 'verb' => 'GET'],
		['name' => 'page#contractNew', 'url' => '/contracts/new', 'verb' => 'GET'],
		['name' => 'page#contractShow', 'url' => '/contracts/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'page#contractEdit', 'url' => '/contracts/{id}/edit', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'page#parties', 'url' => '/parties', 'verb' => 'GET'],
		['name' => 'page#reports', 'url' => '/reports', 'verb' => 'GET'],
		['name' => 'page#audit', 'url' => '/audit', 'verb' => 'GET'],
		['name' => 'page#import', 'url' => '/import', 'verb' => 'GET'],
		['name' => 'page#getTheApp', 'url' => '/get-the-app', 'verb' => 'GET'],
		['name' => 'page#settingsIndex', 'url' => '/settings', 'verb' => 'GET'],
		['name' => 'page#settings', 'url' => '/settings/{section}', 'verb' => 'GET',
			'requirements' => ['section' => \OCA\ContractCheck\Service\SettingsSectionCatalog::routeRequirement()]],

		// Web JSON API (session + CSRF)
		['name' => 'api#dashboard', 'url' => '/api/dashboard', 'verb' => 'GET'],
		['name' => 'api#listContracts', 'url' => '/api/contracts', 'verb' => 'GET'],
		['name' => 'api#createContract', 'url' => '/api/contracts', 'verb' => 'POST'],
		['name' => 'api#contractFilters', 'url' => '/api/contracts/filters', 'verb' => 'GET'],
		['name' => 'api#getContract', 'url' => '/api/contracts/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'api#updateContract', 'url' => '/api/contracts/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'api#transitionContract', 'url' => '/api/contracts/{id}/transition', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'api#listCosts', 'url' => '/api/contracts/{id}/costs', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'api#addCost', 'url' => '/api/contracts/{id}/costs', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'api#updateCost', 'url' => '/api/contracts/{id}/costs/{costId}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+', 'costId' => '\d+']],
		['name' => 'api#deleteCost', 'url' => '/api/contracts/{id}/costs/{costId}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+', 'costId' => '\d+']],
		['name' => 'api#listDocuments', 'url' => '/api/contracts/{id}/documents', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'api#linkDocument', 'url' => '/api/contracts/{id}/documents', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'api#unlinkDocument', 'url' => '/api/contracts/{id}/documents/{docId}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+', 'docId' => '\d+']],
		['name' => 'api#listEvents', 'url' => '/api/contracts/{id}/events', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'api#listRevisions', 'url' => '/api/contracts/{id}/revisions', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'api#listParties', 'url' => '/api/parties', 'verb' => 'GET'],
		['name' => 'api#createParty', 'url' => '/api/parties', 'verb' => 'POST'],
		['name' => 'api#updateParty', 'url' => '/api/parties/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'api#archiveParty', 'url' => '/api/parties/{id}/archive', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'api#unarchiveParty', 'url' => '/api/parties/{id}/unarchive', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'api#searchUsers', 'url' => '/api/directory/users', 'verb' => 'GET'],
		['name' => 'api#searchGroups', 'url' => '/api/directory/groups', 'verb' => 'GET'],
		['name' => 'api#searchFiles', 'url' => '/api/files/search', 'verb' => 'GET'],
		['name' => 'api#getSettings', 'url' => '/api/settings', 'verb' => 'GET'],
		['name' => 'api#saveSettings', 'url' => '/api/settings', 'verb' => 'POST'],
		['name' => 'api#mintFeedToken', 'url' => '/api/feed/token', 'verb' => 'POST'],
		['name' => 'api#revokeFeedToken', 'url' => '/api/feed/token', 'verb' => 'DELETE'],
		['name' => 'api#rollupReport', 'url' => '/api/reports/rollup', 'verb' => 'GET'],
		['name' => 'api#exportCsv', 'url' => '/api/export/contracts.csv', 'verb' => 'GET'],
		['name' => 'api#exportPdf', 'url' => '/api/export/contracts.pdf', 'verb' => 'GET'],
		['name' => 'api#importCsv', 'url' => '/api/import/contracts', 'verb' => 'POST'],
		['name' => 'api#auditLog', 'url' => '/api/audit', 'verb' => 'GET'],

		// ICS feed (token-auth, read-only, no session)
		['name' => 'feed#ics', 'url' => '/feed/deadlines.ics', 'verb' => 'GET'],

		// Companion API (app-password Bearer auth)
		['name' => 'companion#bootstrap', 'url' => '/api/companion/bootstrap', 'verb' => 'GET'],
		['name' => 'companion#dashboard', 'url' => '/api/companion/dashboard', 'verb' => 'GET'],
		['name' => 'companion#listContracts', 'url' => '/api/companion/contracts', 'verb' => 'GET'],
		['name' => 'companion#getContract', 'url' => '/api/companion/contracts/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'companion#listParties', 'url' => '/api/companion/parties', 'verb' => 'GET'],
	],
];
