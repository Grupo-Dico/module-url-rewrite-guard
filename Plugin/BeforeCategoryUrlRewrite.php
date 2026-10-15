<?php
declare(strict_types=1);
namespace GDMexico\UrlRewriteGuard\Plugin;

use GDMexico\UrlRewriteGuard\Model\AutomaticRepair;
use Magento\CatalogUrlRewrite\Observer\CategoryProcessUrlRewriteSavingObserver;
use Magento\Framework\Event\Observer;

class BeforeCategoryUrlRewrite
{
    private $repair;

    public function __construct(AutomaticRepair $repair)
    {
        $this->repair = $repair;
    }

    public function beforeExecute(CategoryProcessUrlRewriteSavingObserver $subject, Observer $observer): array
    {
        if (!$this->repair->isEnabled()) {
            return [$observer];
        }
        $category = $observer->getEvent()->getData('category');
        if (!$category || !(int)$category->getId()) {
            return [$observer];
        }
        $changed = array_values(array_unique(array_map('intval', (array)$category->getChangedProductIds())));
        // For category url_key/is_anchor changes core may regenerate descendants and all category products.
        // Do not guess that set and potentially mutate the wrong items automatically.
        if ($category->dataHasChangedFor('url_key') || $category->dataHasChangedFor('is_anchor')) {
            return [$observer];
        }
        if (!$changed) {
            return [$observer];
        }
        $stores = array_map('intval', (array)$category->getStoreIds());
        $this->repair->repairIds($changed, 'category:' . (int)$category->getId(), $stores);
        return [$observer];
    }
}
