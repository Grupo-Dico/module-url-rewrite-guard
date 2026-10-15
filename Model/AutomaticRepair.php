<?php
declare(strict_types=1);

namespace GDMexico\UrlRewriteGuard\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Category product-assignment URL guard. Only changes conflicting store-scoped url_key values.
 * Does not delete existing url_rewrite entries or save products recursively.
 * Requires Magento native category save to generate final URL rewrites.
 */
class AutomaticRepair
{
    private $resource;
    private $config;
    private $stores;
    private $logger;
    private $running = false;

    public function __construct(
        ResourceConnection $resource,
        ScopeConfigInterface $config,
        StoreManagerInterface $stores,
        LoggerInterface $logger
    ) {
        $this->resource = $resource;
        $this->config = $config;
        $this->stores = $stores;
        $this->logger = $logger;
    }

    public function isEnabled(): bool
    {
        return $this->config->isSetFlag('dico_url_guard/automatic/enabled');
    }


    /**
     * @param int[] $ids Changed products in a category assignment
     * @param int[] $storeIds The category stores, as supplied by Magento Category::getStoreIds()
     */
    public function repairIds(array $ids, string $origin, array $storeIds = []): void
    {
        if (!$this->isEnabled() || $this->running) {
            return;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if (!$ids) {
            return;
        }
        if (count($ids) > 25 || !$storeIds) {
            throw new \RuntimeException('[UrlRewriteGuard] Invalid or oversized category batch');
        }

        $this->running = true;
        try {
            $db = $this->resource->getConnection();
            $attrTable = $this->resource->getTableName('eav_attribute');
            $entityTypes = $this->resource->getTableName('eav_entity_type');
            $values = $this->resource->getTableName('catalog_product_entity_varchar');
            $products = $this->resource->getTableName('catalog_product_entity');
            $rewrites = $this->resource->getTableName('url_rewrite');
            $attrId = (int)$db->fetchOne(
                "SELECT a.attribute_id FROM {$attrTable} a JOIN {$entityTypes} e ON e.entity_type_id=a.entity_type_id " .
                "WHERE e.entity_type_code='catalog_product' AND a.attribute_code='url_key' LIMIT 1"
            );
            if (!$attrId) {
                throw new \RuntimeException('[UrlRewriteGuard] Product url_key attribute missing');
            }

            $stores = [];
            foreach ($storeIds as $sid) {
                $store = $this->stores->getStore($sid);
                if ((int)$store->getId() !== 0) {
                    $stores[(int)$store->getId()] = $store;
                }
            }
            if (!$stores) {
                return;
            }

            // Preflight the entire operation BEFORE writing anything.
            $plan = [];
            $reserved = [];
            foreach ($ids as $id) {
                $sku = (string)$db->fetchOne("SELECT sku FROM {$products} WHERE entity_id=?", [$id]);
                if ($sku === '') {
                    continue;
                }
                $global = $db->fetchOne(
                    "SELECT value FROM {$values} WHERE entity_id=? AND attribute_id=? AND store_id=0 LIMIT 1",
                    [$id, $attrId]
                );
                if ($global === false || (string)$global === '') {
                    continue;
                }
                $global = (string)$global;

                foreach ($stores as $sid => $store) {
                    $local = $db->fetchOne(
                        "SELECT value FROM {$values} WHERE entity_id=? AND attribute_id=? AND store_id=? LIMIT 1",
                        [$id, $attrId, $sid]
                    );
                    $current = $local !== false && (string)$local !== '' ? (string)$local : $global;
                    $suffix = (string)$store->getConfig('catalog/seo/product_url_suffix');
                    $path = $current . $suffix;
                    $owner = $db->fetchRow(
                        "SELECT entity_type,entity_id FROM {$rewrites} WHERE store_id=? AND request_path=? LIMIT 1",
                        [$sid, $path]
                    );
                    // Keep the original URL if it is free or already belongs to this product.
                    if (!$owner || ($owner['entity_type'] === 'product' && (int)$owner['entity_id'] === $id)) {
                        continue;
                    }

                    $candidate = $current . '-' . $sid;
                    // Distinct deterministic fallback when the first candidate is unavailable.
                    $found = false;
                    for ($i = 0; $i < 10; $i++) {
                        $try = $i === 0 ? $candidate : $candidate . '-' . ($i + 1);
                        if (strlen($try) > 200) {
                            break;
                        }
                        $newPath = $try . $suffix;
                        $existing = $db->fetchRow(
                            "SELECT entity_type,entity_id FROM {$rewrites} WHERE store_id=? AND request_path=? LIMIT 1",
                            [$sid, $newPath]
                        );
                        $reservation = $sid . ':' . $newPath;
                        if ((!$existing || ($existing['entity_type'] === 'product' && (int)$existing['entity_id'] === $id))
                            && (!isset($reserved[$reservation]) || $reserved[$reservation] === $id)) {
                            $candidate = $try;
                            $reserved[$reservation] = $id;
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        throw new \RuntimeException(sprintf('[UrlRewriteGuard] No safe key for %s in store %d', $sku, $sid));
                    }
                    $plan[] = [$id, $sku, $sid, $current, $candidate];
                    if (count($plan) > 25) {
                        throw new \RuntimeException('[UrlRewriteGuard] More than 25 store-scoped changes');
                    }
                }
            }
            $this->logger->info(sprintf(
                '[UrlRewriteGuard] preflight origin=%s category_stores=%s changes=%d',
                $origin, implode(',', array_keys($stores)), count($plan)
            ));

            // Scope-specific override. The global url_key (store_id=0) remains unchanged.
            // Uses the category-save DB connection; if Magento rolls the transaction back,
            // these EAV changes participate in the same rollback.
            foreach ($plan as $item) {
                list($id, $sku, $sid, $old, $new) = $item;
                $db->insertOnDuplicate($values, [
                    'attribute_id' => $attrId,
                    'store_id' => $sid,
                    'entity_id' => $id,
                    'value' => $new,
                ], ['value']);
                $this->logger->warning(sprintf(
                    '[UrlRewriteGuard] scoped origin=%s id=%d sku=%s store=%d old=%s new=%s',
                    $origin, $id, $sku, $sid, $old, $new
                ));
            }
        } finally {
            $this->running = false;
        }
    }

    public function productIdsForSkus(array $skus): array
    {
        // Automatic import mutation disabled until native import generators are validated.
        return [];
    }
}
