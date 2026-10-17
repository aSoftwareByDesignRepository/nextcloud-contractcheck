# ContractCheck

Contract register with cancellation deadlines for Nextcloud (AGPL) — never
miss a Kündigungsfrist again.

One register for every contract: notice periods and anchors (month end,
quarter end, year end, contract end, anytime), automatic renewals, revision
history, linked Nextcloud files, cost entries with yearly rollups, CSV/PDF
export, and an ICS feed for your calendar. A deterministic deadline engine
computes the next cancel-by date for every contract — so auto-renewals never
surprise you again.

## Features

- Contract register with counterparties, categories, statuses, and revision
  history
- Deadline engine: notice periods × anchors → next cancel-by date,
  recomputed on every edit; dashboard widget and reminder background job
- Automatic renewals with renewal-cost tracking and yearly cost rollups
- Documents: link Nextcloud Files to contracts (files stay on your server)
- Export: CSV and PDF; ICS feed so deadlines land in your calendar
- Import: CSV onboarding for existing registers
- Audit log and notification support; German + English UI, WCAG 2.1 AA

**Free AGPL web app.** No external services, no telemetry — all data stays
on your Nextcloud.

## Clear limits

- Not e-signature, drafting, or clause management
- Not procurement (EinkaufCheck) or invoicing (InvoiceCheck)
- Not public tender law workflows

## Dev

```bash
composer install
./vendor/bin/phpunit --testsuite unit
```

Integration tests (Docker Nextcloud service):

```bash
cd ../../  # nextcloud/
docker compose exec -u www-data nextcloud \
  php /var/www/html/custom_apps/contractcheck/vendor/bin/phpunit \
  --configuration /var/www/html/custom_apps/contractcheck/phpunit.xml \
  --testsuite integration
```

Enable: `occ app:enable contractcheck`.

## Links

- Product page: https://nextcloud.software-by-design.de/en/apps/contractcheck.html
- Issues: https://github.com/aSoftwareByDesignRepository/nextcloud-contractcheck/issues
- Security: [SECURITY.md](SECURITY.md)
