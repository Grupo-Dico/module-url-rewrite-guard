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

## Versionado y publicación (SemVer)

El proyecto usa **Semantic Versioning**: `MAJOR.MINOR.PATCH`. Los consumidores Composer obtienen su versión de **tags Git**, no del campo `version` en `composer.json` (deliberadamente omitido). El archivo `VERSION` y el atributo `setup_version` en `etc/module.xml` documentan la versión del módulo y deben mantenerse sincronizados con la etiqueta publicada. `setup_version` es metadato legado de Magento, no sustituye el tag Composer ni ejecuta cambios de esquema de forma automática.

- `PATCH` (`1.0.1`): correcciones compatibles de errores.
- `MINOR` (`0.10.0`): funcionalidad compatible nueva.
- `MAJOR` (`1.0.0`): versión estable tras validación y/o cambios incompatibles.

### Primer release de pruebas

```bash
git add .
git commit -m "chore: prepare 1.0.0 Magento module release"
git tag -a v1.0.0 -m "1.0.0 initial internal release"
git push origin main
git push origin v1.0.0
```

Para actualizar a una versión posterior, primero modifica `VERSION`, `setup_version` y `CHANGELOG.md`, verifica PHP/Magento, crea el commit y después el tag. **No muevas ni reutilices tags publicados**.

Configura este repositorio VCS en un Magento consumidor (o en el gestor de paquetes privado de la organización):

```bash
composer config repositories.url-rewrite-guard vcs git@github.com:Grupo-Dico/module-url-rewrite-guard.git
composer require grupo-dico/module-url-rewrite-guard:^1.0
php bin/magento module:enable GDMexico_UrlRewriteGuard
php bin/magento setup:upgrade
```

Para actualización controlada después de publicar `v1.0.1`:

```bash
composer update grupo-dico/module-url-rewrite-guard --with-dependencies
php bin/magento setup:upgrade
php bin/magento cache:clean
```

Para fijar una versión exacta en ambiente sensible usa `composer require grupo-dico/module-url-rewrite-guard:1.0.0` y despliega `composer.lock`. No uses `dev-main` en producción.
