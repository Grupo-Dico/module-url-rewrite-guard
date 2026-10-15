# Changelog

Formato inspirado en Keep a Changelog. La versión en `composer.json` debe coincidir con el tag Git (`vX.Y.Z`).

## [1.0.0] - 2026-10-15

### Added
- Protección de colisiones de URL al guardar categorías.
- Preprocesamiento de CSV para productos existentes con `url_key` y tienda explícita antes de Check Data.
- Auditoría CLI `dico:url-guard`.
- Alternativas con sufijo `store_id` y contador para colisiones consecutivas.
- Configuración global Enabled.

### Known limitations
- Sin certificación funcional multiversión PHP/Magento ni cobertura total de CSV globales, nuevos SKU, colisiones de rutas por categoría o importación masiva.
- Publicación sujeta a validación de integración por cada versión de Magento.
