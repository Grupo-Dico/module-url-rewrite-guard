<?php
declare(strict_types=1);

namespace GDMexico\UrlRewriteGuard\Plugin;

use GDMexico\UrlRewriteGuard\Model\AutomaticRepair;
use Magento\CatalogUrlRewrite\Observer\AfterImportDataObserver;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\ImportExport\Model\Import as ImportModel;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Guard URL rewrites for EXISTING products during Add/Update import.
 * Does not touch price-only imports. The native observer still generates/persists rewrites.
 * Store-scoped EAV changes + native rewrite persistence share one DB transaction here.
 * No direct mutation of url_rewrite records.
 *
 * Experimental: run against a restored staging DB before production use.
 */
class BeforeImportUrlRewrite
{
    private $guard;
    private $resource;
    private $stores;
    private $logger;

    public function __construct(
        AutomaticRepair $guard,
        ResourceConnection $resource,
        StoreManagerInterface $stores,
        LoggerInterface $logger
    ) {
        $this->guard = $guard;
        $this->resource = $resource;
        $this->stores = $stores;
        $this->logger = $logger;
    }

    public function aroundExecute(AfterImportDataObserver $subject, callable $proceed, Observer $observer)
    {
        if (!$this->guard->isEnabled()) {
            return $proceed($observer);
        }
        $event = $observer->getEvent();
        $bunch = $event->getBunch();
        if (!is_array($bunch) || !$bunch) {
            return $proceed($observer);
        }
        $relevant = [];
        foreach ($bunch as $index => $row) {
            if (is_array($row) && !empty($row['sku']) &&
                (array_key_exists('url_key', $row) || array_key_exists('categories', $row))) {
                $relevant[$index] = $row;
            }
        }
        if (!$relevant) {
            // A price-only CSV must never be changed by this module.
            return $proceed($observer);
        }
        $adapter = $event->getAdapter();
        if (!$adapter || $adapter->getBehavior() !== ImportModel::BEHAVIOR_APPEND) {
            // Unsupported behaviors run natively. No speculative EAV rewrites.
            return $proceed($observer);
        }
        if (count($relevant) > 1000) {
            throw new \RuntimeException('[UrlRewriteGuard] Import URL bunch exceeds 1000 rows');
        }
        $db = $this->resource->getConnection();
        $productTable = $this->resource->getTableName('catalog_product_entity');
        $websiteTable = $this->resource->getTableName('catalog_product_website');
        $storeTable = $this->resource->getTableName('store');
        $rewriteTable = $this->resource->getTableName('url_rewrite');
        $valueTable = $this->resource->getTableName('catalog_product_entity_varchar');
        $attributeTable = $this->resource->getTableName('eav_attribute');
        $entityTypeTable = $this->resource->getTableName('eav_entity_type');
        $attrId = (int)$db->fetchOne(
            "SELECT a.attribute_id FROM {$attributeTable} a " .
            "INNER JOIN {$entityTypeTable} t ON a.entity_type_id=t.entity_type_id " .
            "WHERE t.entity_type_code='catalog_product' AND a.attribute_code='url_key' LIMIT 1"
        );
        if (!$attrId) {
            throw new \RuntimeException('[UrlRewriteGuard] Missing catalog_product url_key attribute');
        }

        // Compute the entire plan without performing any writes.
        $changes = [];
        $replacements = [];
        $reserved = [];
        foreach ($relevant as $index => $row) {
            $sku = trim((string)$row['sku']);
            $id = (int)$db->fetchOne("SELECT entity_id FROM {$productTable} WHERE sku=? LIMIT 1", [$sku]);
            if ($id <= 0) {
                // Newly created products: let Magento handle them; not enough EAV state to validate.
                continue;
            }
            $scopeCode = trim((string)($row['store_view_code'] ?? ''));
            $targetStores = [];
            if ($scopeCode !== '') {
                $store = $this->stores->getStore($scopeCode);
                $targetStores[(int)$store->getId()] = $store;
            } else {
                // The native import observer expands global rows by PRODUCT WEBSITE, not by category root.
                $storeIds = $db->fetchCol(
                    "SELECT DISTINCT s.store_id FROM {$websiteTable} pw " .
                    "INNER JOIN {$storeTable} s ON s.website_id=pw.website_id " .
                    "WHERE pw.product_id=? AND s.store_id<>0",
                    [$id]
                );
                foreach ($storeIds as $storeId) {
                    $store = $this->stores->getStore((int)$storeId);
                    $targetStores[(int)$storeId] = $store;
                }
            }
            if (!$targetStores) {
                continue;
            }
            $global = $db->fetchOne(
                "SELECT value FROM {$valueTable} WHERE entity_id=? AND attribute_id=? AND store_id=0 LIMIT 1",
                [$id, $attrId]
            );
            $global = $global === false ? '' : (string)$global;
            $effective = [];
            $changed = false;
            foreach ($targetStores as $storeId => $store) {
                if ($storeId === 0) {
                    continue;
                }
                $local = $db->fetchOne(
                    "SELECT value FROM {$valueTable} WHERE entity_id=? AND attribute_id=? AND store_id=? LIMIT 1",
                    [$id, $attrId, $storeId]
                );
                $base = $local !== false && (string)$local !== '' ? (string)$local : $global;
                // For explicit store-view rows, the CSV value is authoritative.
                if ($scopeCode !== '' && isset($row['url_key']) && (string)$row['url_key'] !== '') {
                    $base = (string)$row['url_key'];
                }
                // A global CSV url_key was persisted by the importer before this event.
                if ($base === '') {
                    throw new \RuntimeException('[UrlRewriteGuard] Empty URL key for existing SKU ' . $sku);
                }
                $suffix = (string)$store->getConfig('catalog/seo/product_url_suffix');
                $owner = $db->fetchRow(
                    "SELECT entity_type,entity_id FROM {$rewriteTable} WHERE store_id=? AND request_path=? LIMIT 1",
                    [$storeId, $base . $suffix]
                );
                $candidate = $base;
                if ($owner && !($owner['entity_type'] === 'product' && (int)$owner['entity_id'] === $id)) {
                    $found = false;
                    for ($n = 1; $n <= 10; $n++) {
                        $try = $base . '-' . $storeId . ($n === 1 ? '' : '-' . $n);
                        if (strlen($try . $suffix) > 240) {
                            break;
                        }
                        $path = $try . $suffix;
                        $other = $db->fetchRow(
                            "SELECT entity_type,entity_id FROM {$rewriteTable} WHERE store_id=? AND request_path=? LIMIT 1",
                            [$storeId, $path]
                        );
                        $reservation = $storeId . ':' . $path;
                        if ((!$other || ($other['entity_type'] === 'product' && (int)$other['entity_id'] === $id))
                            && (!isset($reserved[$reservation]) || $reserved[$reservation] === $id)) {
                            $candidate = $try;
                            $reserved[$reservation] = $id;
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        throw new \RuntimeException('[UrlRewriteGuard] No collision-free key for ' . $sku . ' store ' . $storeId);
                    }
                    $changes[$id . ':' . $storeId] = [$id, $storeId, $candidate, $sku, $base];
                    $changed = true;
                }
                $effective[$storeId] = $candidate;
            }
            if ($changed) {
                if ($scopeCode !== '') {
                    $row['url_key'] = reset($effective);
                    $replacements[$index] = [$row];
                } else {
                    // Replace ONLY the observer's global input with per-store rows; the importer
                    // has already saved the original global attributes at this point.
                    $expanded = [];
                    foreach ($effective as $storeId => $key) {
                        $copy = $row;
                        $copy['store_view_code'] = (string)$this->stores->getStore($storeId)->getCode();
                        $copy['url_key'] = $key;
                        $expanded[] = $copy;
                    }
                    $replacements[$index] = $expanded;
                }
            }
        }
        if (!$changes) {
            return $proceed($observer);
        }
        if (count($changes) > 100) {
            throw new \RuntimeException('[UrlRewriteGuard] More than 100 store URL changes in import bunch');
        }
        $modifiedBunch = [];
        foreach ($bunch as $index => $row) {
            foreach (($replacements[$index] ?? [$row]) as $replacement) {
                $modifiedBunch[] = $replacement;
            }
        }
        // All changes and Magento's native URL persistence use the same DB connection.
        $db->beginTransaction();
        try {
            foreach ($changes as $item) {
                list($id, $storeId, $key, $sku, $old) = $item;
                $db->insertOnDuplicate($valueTable, [
                    'attribute_id' => $attrId,
                    'entity_id' => $id,
                    'store_id' => $storeId,
                    'value' => $key,
                ], ['value']);
                $this->logger->warning(sprintf(
                    '[UrlRewriteGuard] import scoped sku=%s id=%d store=%d old=%s new=%s',
                    $sku, $id, $storeId, $old, $key
                ));
            }
            $event->setData('bunch', $modifiedBunch);
            $result = $proceed($observer);
            $db->commit();
            return $result;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        } finally {
            $event->setData('bunch', $bunch);
        }
    }
}
