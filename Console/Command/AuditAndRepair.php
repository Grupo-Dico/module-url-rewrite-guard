<?php
declare(strict_types=1);

namespace GDMexico\UrlRewriteGuard\Console\Command;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Diagnostic and opt-in per-product store-view repair.
 * No plugins or core rewrite suppression. Does not delete url_rewrite rows.
 */
class AuditAndRepair extends Command
{
    private const DEFAULT_IDS = '24085,24338,24339,24340,24341,24342,24343,24344,24345,24346,24348,24349';

    /** @var ResourceConnection */
    private $resource;

    /** @var ProductRepositoryInterface */
    private $products;

    /** @var ProductUrlRewriteGenerator */
    private $generator;

    /** @var StoreManagerInterface */
    private $stores;

    /** @var State */
    private $appState;

    public function __construct(
        ResourceConnection $resource,
        ProductRepositoryInterface $products,
        ProductUrlRewriteGenerator $generator,
        StoreManagerInterface $stores,
        State $appState
    ) {
        $this->resource = $resource;
        $this->products = $products;
        $this->generator = $generator;
        $this->stores = $stores;
        $this->appState = $appState;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('dico:url-guard')
            ->setDescription('Audita URL keys y propone reparaciones, sin cambios salvo --apply y un --ids individual')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store view ID (obligatorio)')
            ->addOption('ids', null, InputOption::VALUE_REQUIRED, 'IDs separados por coma', self::DEFAULT_IDS)
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Guardar SOLO UN producto, con verificacion previa')
            ->addOption('confirm-backup', null, InputOption::VALUE_NONE, 'Confirmar backup completo y rollback probado');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('store') === null || !ctype_digit((string)$input->getOption('store'))
            || (int)$input->getOption('store') <= 0) {
            $output->writeln('<error>Indica --store=11 (store view numerica, >0)</error>');
            return 1;
        }
        $storeId = (int)$input->getOption('store');
        $apply = (bool)$input->getOption('apply');
        $rawIds = trim((string)$input->getOption('ids'));
        if (!preg_match('/^\d+(,\d+)*$/', $rawIds)) {
            $output->writeln('<error>--ids solo admite IDs separados por comas, sin espacios.</error>');
            return 1;
        }
        $ids = array_values(array_unique(array_map('intval', explode(',', $rawIds))));
        if ($apply && (count($ids) !== 1 || !(bool)$input->getOption('confirm-backup'))) {
            $output->writeln('<error>--apply requiere --ids=UN_SOLO_ID y --confirm-backup. Nunca ejecutar en lote.</error>');
            return 1;
        }
        try {
            $store = $this->stores->getStore($storeId);
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // AREA_ADMINHTML puede estar definida ya; un store inexistente sí falla.
            if (!isset($store)) {
                $output->writeln('<error>Store inválida: ' . $e->getMessage() . '</error>');
                return 1;
            }
        }
        $db = $this->resource->getConnection();
        $urlTable = $this->resource->getTableName('url_rewrite');
        $decimal = $this->resource->getTableName('catalog_product_entity_varchar');
        $attribute = $this->resource->getTableName('eav_attribute');
        $entityType = $this->resource->getTableName('eav_entity_type');
        $attrId = (int)$db->fetchOne(
            "SELECT a.attribute_id FROM {$attribute} a JOIN {$entityType} e ON e.entity_type_id=a.entity_type_id WHERE a.attribute_code='url_key' AND e.entity_type_code='catalog_product' LIMIT 1"
        );
        if (!$attrId) {
            $output->writeln('<error>No se encontró el atributo url_key.</error>');
            return 1;
        }
        $output->writeln(sprintf('<info>Store %d (%s), modo %s</info>', $storeId, $store->getCode(), $apply ? 'APPLY' : 'DRY-RUN'));
        $failures = 0;
        foreach ($ids as $id) {
            try {
                $product = $this->products->getById($id, false, $storeId, true);
                $sku = (string)$product->getSku();
                $row = $db->fetchRow(
                    "SELECT s.value FROM {$decimal} s WHERE s.entity_id=? AND s.attribute_id=? AND s.store_id=? LIMIT 1",
                    [$id, $attrId, $storeId]
                );
                $local = $row === false ? null : (string)$row['value'];
                $global = $db->fetchOne(
                    "SELECT value FROM {$decimal} WHERE entity_id=? AND attribute_id=? AND store_id=0 LIMIT 1",
                    [$id, $attrId]
                );
                $current = $local ?? (string)$global;
                $extension = (string)$this->stores->getStore($storeId)->getConfig('catalog/seo/product_url_suffix');
                if ($extension === '') {
                    $extension = '.html';
                }
                $currentPath = $current . $extension;
                $owner = $this->owner($db, $urlTable, $storeId, $currentPath);
                if ($owner === null || ($owner['entity_type'] === 'product' && (int)$owner['entity_id'] === $id)) {
                    $output->writeln(sprintf('[OK] %d %s | %s | path libre o propio', $id, $sku, $current));
                    continue;
                }
                // Always use the store ID as the first alternative suffix.
                $base = preg_replace(
                    '/-' . preg_quote((string)$storeId, '/') . '(?:-\d+)?$/',
                    '',
                    $current
                );
                $suggestion = null;
                for ($i = 1; $i <= 100; $i++) {
                    $candidateKey = $base . '-' . $storeId . ($i === 1 ? '' : '-' . $i);
                    if (!$this->isOccupiedByOther($db, $urlTable, $storeId, $candidateKey . $extension, $id)) {
                        $suggestion = $candidateKey;
                        break;
                    }
                }
                if ($suggestion === null) {
                    throw new \RuntimeException('No available URL Key for store_id=' . $storeId);
                }
                $output->writeln(sprintf('<comment>[CONFLICTO] %d %s | %s ocupado por %s:%d | candidato %s</comment>',
                    $id, $sku, $currentPath, $owner['entity_type'], $owner['entity_id'], $suggestion));
                $candidate = $suggestion;
                if ($candidate === $current) {
                    throw new \RuntimeException('Sin candidato distinto al URL Key actual');
                }
                // Probe Magento's *actual* generated paths, including the category paths it returns.
                // The Magento generator does not persist rewrites; this is a read-only preflight.
                $product->setStoreId($storeId);
                $product->setUrlKey($candidate);
                $generated = $this->generator->generate($product);
                foreach ($product->getCategoryIds() as $categoryId) {
                    foreach ($this->generator->generate($product, (int)$categoryId) as $url) {
                        $generated[] = $url;
                    }
                }
                $generatedPaths = [];
                foreach ($generated as $url) {
                    if ((int)$url->getStoreId() !== $storeId || (int)$url->getRedirectType() !== 0) {
                        continue;
                    }
                    $path = (string)$url->getRequestPath();
                    if ($path === '' || isset($generatedPaths[$path])) {
                        continue;
                    }
                    $generatedPaths[$path] = true;
                    $other = $this->owner($db, $urlTable, $storeId, $path);
                    if ($other !== null && !($other['entity_type'] === 'product' && (int)$other['entity_id'] === $id)) {
                        throw new \RuntimeException(sprintf('Ruta generada ocupada: %s => %s:%d (redirect %d)',
                            $path, $other['entity_type'], $other['entity_id'], $other['redirect_type']));
                    }
                }
                // Always check the canonical path, even if the generator omits it.
                if ($this->isOccupiedByOther($db, $urlTable, $storeId, $candidate . $extension, $id)) {
                    throw new \RuntimeException('URL base propuesta ocupada: ' . $candidate . $extension);
                }
                $output->writeln(sprintf('[VALIDADO] %s | %d rutas generadas revisadas', $candidate, count($generatedPaths)));
                if ($apply) {
                    // Saving through repository invokes Magento's normal rewrite management.
                    // No SQL deletes, no force replace, no catch-and-ignore of rewrite exceptions.
                    $product->setData('save_rewrites_history', false);
                    $this->products->save($product);
                    $fresh = $this->products->getById($id, false, $storeId, true);
                    if ((string)$fresh->getUrlKey() !== $candidate) {
                        throw new \RuntimeException('El valor no coincidió tras el guardado; revisar el estado');
                    }
                    $output->writeln('<info>[GUARDADO] ' . $sku . ' => ' . $candidate . '</info>');
                }
            } catch (\Throwable $e) {
                $failures++;
                $output->writeln(sprintf('<error>[BLOQUEADO] id=%d %s</error>', $id, $e->getMessage()));
            }
        }
        $output->writeln('Nota: el auditor no garantiza que otro proceso no cambie las URLs entre validacion y guardado.');
        return $failures ? 1 : 0;
    }

    private function owner(AdapterInterface $db, string $table, int $storeId, string $path): ?array
    {
        $result = $db->fetchRow(
            "SELECT entity_type, entity_id, redirect_type FROM {$table} WHERE store_id=? AND request_path=? LIMIT 1",
            [$storeId, $path]
        );
        return $result ?: null;
    }

    private function isOccupiedByOther(AdapterInterface $db, string $table, int $storeId, string $path, int $id): bool
    {
        $owner = $this->owner($db, $table, $storeId, $path);
        return $owner !== null && !($owner['entity_type'] === 'product' && (int)$owner['entity_id'] === $id);
    }
}
