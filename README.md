# GDMexico UrlRewriteGuard

Magento 2 module to guard URL rewrite collisions during category-product assignment and certain admin CSV product imports.

## Behavior
- Category save preflight checks impacted stores and writes store-scoped `url_key` overrides when a canonical URL is already taken.
- Admin CSV preflight supports **existing products**, **Add/Update**, an explicit `store_view_code` and `url_key` per row. Price-only CSVs are unchanged.
- When occupied, the preferred candidate is `<original-url-key>-<store_id>`; if occupied, `<original-url-key>-<store_id>-2`, etc.
- Never deliberately deletes another product's rewrites. Native Magento rewrite generation still applies.
- CLI `bin/magento dico:url-guard --store=11 --ids=24345` is dry-run by default. Do not use `--apply` before a backup.

## Installation
Install by Composer VCS repository, then `composer require grupo-dico/module-url-rewrite-guard` and run `php bin/magento setup:upgrade`. Configure in Stores > Configuration > Catalog > Dico URL Rewrite Guard, setting Enabled=Yes **only after local/staging validation**. Default is disabled.

## Compatibility
PHP language target is 7.4–8.5; Magento itself limits which PHP releases can be used. Source passed syntax checks with the locally installed PHP version only. Integration tests are required on each intended Magento/PHP combination, especially PHP 7.4, 8.5, Adobe Commerce 2.4.9, and older installations. This package is **not certified** for all these environments.

## Tested behavior from development
- A product 24345, store 11: category assignment changed conflicting `silla-vanila-gris` to `silla-vanila-gris-11`.
- A product 24346, store 11: CSV preflight changed conflicting `silla-vanila-beige` to `silla-vanila-beige-11`.
- The original owners retained their canonical paths in store 11.

## Limitations and operational risk
- No guaranteed coverage for global URL key changes, new SKUs, multiple store codes per CSV row, or all category path collisions.
- Store-specific URL keys do not guarantee the same URL across all stores.
- Category repair uses direct EAV updates; DB backup, integration tests, and transactional review are needed before production deployment.
- CSV preflight rewrites the uploaded Magento work file and assumes the native Add/Update workflow.
- Back up database and CSV and run an audit before enabling. Review logs matching `[UrlRewriteGuard]`.
- A syntax check cannot establish Magento compatibility.

## Release
Source assembled from tested local v0.8 and the Store ID suffix patch. This package is a release candidate pending complete Magento version matrix testing.
