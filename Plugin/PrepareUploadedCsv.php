<?php
declare(strict_types=1);

namespace GDMexico\UrlRewriteGuard\Plugin;

use GDMexico\UrlRewriteGuard\Model\AutomaticRepair;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\ImportExport\Model\Import;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Prepare a store-scoped CSV before Magento creates its validation source.
 * Conservatively handles existing product canonical URL collisions only.
 * Other scenarios retain Magento's native validation.
 */
class PrepareUploadedCsv
{
    private $guard;
    private $resource;
    private $stores;
    private $config;
    private $logger;

    public function __construct(
        AutomaticRepair $guard,
        ResourceConnection $resource,
        StoreManagerInterface $stores,
        ScopeConfigInterface $config,
        LoggerInterface $logger
    ) {
        $this->guard = $guard;
        $this->resource = $resource;
        $this->stores = $stores;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function afterUploadSource(Import $subject, $path)
    {
        if (!$this->guard->isEnabled() || $subject->getEntity() !== 'catalog_product'
            || $subject->getBehavior() !== Import::BEHAVIOR_APPEND
            || !is_string($path) || !is_file($path)
            || strtolower((string)pathinfo($path, PATHINFO_EXTENSION)) !== 'csv') {
            return $path;
        }

        $separator = (string)$subject->getData(Import::FIELD_FIELD_SEPARATOR);
        if ($separator === '') {
            $separator = ',';
        }
        if (strlen($separator) !== 1) {
            return $path;
        }
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new LocalizedException(__('UrlRewriteGuard cannot read the uploaded CSV.'));
        }
        try {
            $headers = fgetcsv($handle, 0, $separator, '"', '\\');
            if (!is_array($headers) || !in_array('url_key', $headers, true)
                || !in_array('sku', $headers, true)
                || !in_array('store_view_code', $headers, true)) {
                return $path;
            }
            $index = array_flip($headers);
            $rows = [];
            $modified = 0;
            $seen = [];
            $db = $this->resource->getConnection();
            $rewrites = $this->resource->getTableName('url_rewrite');
            $products = $this->resource->getTableName('catalog_product_entity');
            while (($values = fgetcsv($handle, 0, $separator, '"', '\\')) !== false) {
                if (count($rows) >= 50000) {
                    throw new LocalizedException(__('UrlRewriteGuard supports up to 50000 CSV rows in this preflight.'));
                }
                if (count($values) !== count($headers)) {
                    // Let native Magento handle malformed CSV rather than silently modifying its shape.
                    return $path;
                }
                $sku = trim($values[$index['sku']]);
                $key = trim($values[$index['url_key']]);
                $storeCode = trim($values[$index['store_view_code']]);
                if ($sku !== '' && $key !== '' && $storeCode !== '') {
                    try {
                        $store = $this->stores->getStore($storeCode);
                    } catch (\Throwable $e) {
                        // Native Magento will report an invalid store code.
                        $rows[] = $values;
                        continue;
                    }
                    $storeId = (int)$store->getId();
                    $id = (int)$db->fetchOne(
                        $db->select()->from($products, ['entity_id'])->where('sku = ?', $sku)->limit(1)
                    );
                    if ($id > 0 && $storeId > 0) {
                        $suffix = (string)$this->config->getValue(
                            'catalog/seo/product_url_suffix', ScopeInterface::SCOPE_STORE, $storeId
                        );
                        $candidate = strtolower($key);
                        $pathKey = $candidate . $suffix;
                        $owner = $db->fetchRow($db->select()->from($rewrites, ['entity_type', 'entity_id'])
                            ->where('store_id = ?', $storeId)->where('request_path = ?', $pathKey)->limit(1));
                        $reservedKey = $storeId . '|' . $pathKey;
                        $takenByCsv = isset($seen[$reservedKey]) && $seen[$reservedKey] !== $sku;
                        if (($owner && !($owner['entity_type'] === 'product' && (int)$owner['entity_id'] === $id)) || $takenByCsv) {
                            $base = $candidate;
                            $found = false;
                            for ($i = 1; $i <= 100; $i++) {
                                $candidate = $base . '-' . $storeId . ($i === 1 ? '' : '-' . $i);
                                $pathKey = $candidate . $suffix;
                                $reservedKey = $storeId . '|' . $pathKey;
                                if (isset($seen[$reservedKey]) && $seen[$reservedKey] !== $sku) {
                                    continue;
                                }
                                $existing = $db->fetchRow($db->select()->from($rewrites, ['entity_type', 'entity_id'])
                                    ->where('store_id = ?', $storeId)->where('request_path = ?', $pathKey)->limit(1));
                                if (!$existing || ($existing['entity_type'] === 'product' && (int)$existing['entity_id'] === $id)) {
                                    $found = true;
                                    break;
                                }
                            }
                            if (!$found) {
                                throw new LocalizedException(__('UrlRewriteGuard: no free URL key for SKU %1, store %2.', $sku, $storeCode));
                            }
                            $values[$index['url_key']] = $candidate;
                            $modified++;
                            $this->logger->warning(sprintf(
                                '[UrlRewriteGuard] csv-preflight sku=%s store=%s original=%s alternative=%s',
                                $sku, $storeCode, $key, $candidate
                            ));
                        }
                        $seen[$reservedKey] = $sku;
                    }
                }
                $rows[] = $values;
            }
        } finally {
            fclose($handle);
        }
        if (!$modified) {
            return $path;
        }
        $tmp = tempnam(dirname($path), '.urlguard-');
        if (!$tmp) {
            throw new LocalizedException(__('UrlRewriteGuard cannot create a temporary CSV.'));
        }
        try {
            $out = fopen($tmp, 'wb');
            if (!$out) {
                throw new \RuntimeException('Cannot open temporary CSV');
            }
            try {
                fputcsv($out, $headers, $separator, '"', '\\');
                foreach ($rows as $values) {
                    fputcsv($out, $values, $separator, '"', '\\');
                }
            } finally {
                fclose($out);
            }
            if (!rename($tmp, $path)) {
                throw new \RuntimeException('Cannot atomically replace uploaded CSV');
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw new LocalizedException(__('UrlRewriteGuard could not prepare the uploaded CSV: %1', $e->getMessage()));
        }
        return $path;
    }
}
