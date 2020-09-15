<?php
/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this module to newer
 * versions in the future.
 *
 * @category  Smile
 * @package   Smile\GiftSalesRule
 * @author    Maxime Queneau <maxime.queneau@smile.fr>
 * @copyright 2019 Smile
 * @license   Open Software License ("OSL") v. 3.0
 */
namespace Smile\GiftSalesRule\Observer;

use Magento\Framework\App\Request\Http;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Quote\Item\Option;
use Smile\GiftSalesRule\Helper\Cache as GiftRuleCacheHelper;
use Smile\GiftSalesRule\Helper\Config as GiftRuleConfigHelper;
use Smile\GiftSalesRule\Helper\GiftRule as GiftRuleHelper;
use Smile\GiftSalesRule\Api\GiftRuleServiceInterface;

/**
 * Class CollectGiftRule
 *
 * @author    Maxime Queneau <maxime.queneau@smile.fr>
 * @copyright 2019 Smile
 */
class CollectGiftRule implements ObserverInterface
{
    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var GiftRuleServiceInterface
     */
    protected $giftRuleService;

    /**
     * @var GiftRuleCacheHelper
     */
    protected $giftRuleCacheHelper;

    /**
     * @var GiftRuleConfigHelper
     */
    protected $giftRuleConfigHelper;

    /**
     * @var GiftRuleHelper
     */
    protected $giftRuleHelper;

    /**
     * @var CartRepositoryInterface
     */
    protected $quoteRepository;

    /**
     * @var Http
     */
    protected $request;

    /**
     * CollectGiftRule constructor.
     *
     * @param CheckoutSession          $checkoutSession      Checkout session
     * @param GiftRuleServiceInterface $giftRuleService      Gift rule service
     * @param GiftRuleCacheHelper      $giftRuleCacheHelper  Gift rule cache helper
     * @param GiftRuleConfigHelper     $giftRuleConfigHelper Gift rule config helper
     * @param GiftRuleHelper           $giftRuleHelper       Gift rule helper
     * @param CartRepositoryInterface  $quoteRepository      Quote repository
     * @param Http                     $request              Request
     */
    public function __construct(
        CheckoutSession $checkoutSession,
        GiftRuleServiceInterface $giftRuleService,
        GiftRuleCacheHelper $giftRuleCacheHelper,
        GiftRuleConfigHelper $giftRuleConfigHelper,
        GiftRuleHelper $giftRuleHelper,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Framework\App\Request\Http $request
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->giftRuleService = $giftRuleService;
        $this->giftRuleCacheHelper = $giftRuleCacheHelper;
        $this->giftRuleConfigHelper = $giftRuleConfigHelper;
        $this->giftRuleHelper = $giftRuleHelper;
        $this->quoteRepository = $quoteRepository;
        $this->request = $request;
    }

    /**
     * Update gift item quantity
     * Add automatic gift item
     *
     * @param Observer $observer Oberver
     * @SuppressWarnings(PHPMD.ElseExpression)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function execute(Observer $observer)
    {
        /** @var array $giftRules */
        $giftRules = $this->checkoutSession->getGiftRules();

        /** @var Quote $quote */
        $quote = $observer->getEvent()->getQuote();

        /** @var array $ruleIds */
        $ruleIds = explode(',', $quote->getAppliedRuleIds());

        if ($giftRules) {

            $saveQuote = false;

            /** @var array $newGiftRulesList */
            $newGiftRulesList = [];
            foreach ($giftRules as $giftRuleId => $giftRuleCode) {
                if (!in_array($giftRuleId, $ruleIds)) {
                    /** @var Item $item */
                    foreach ($quote->getAllItems() as $item) {
                        $option = $item->getOptionByCode('option_gift_rule');
                        if ($option && $option->getValue() == $giftRuleId) {
                            $salesRule = $this->giftRuleHelper->getSalesRuleForGiftRuleId($giftRuleId);
                            $invalidRule = !$salesRule || !$this->giftRuleHelper->willGiftRuleApplyToQuote($salesRule, $quote);
                            if ($invalidRule) {
                                // Remove gift item.
                                $quote->deleteItem($item);
                                $saveQuote = true;
                            }
                        }
                    }
                } else {
                    $newGiftRulesList[$giftRuleId] = $giftRuleCode;
                    $giftItem    = [];
                    $giftItemQty = 0;

                    /** @var Item $item */
                    foreach ($quote->getAllItems() as $item) {
                        /** @var Option $option */
                        $option = $item->getOptionByCode('option_gift_rule');
                        /** @var Option $configurableOption */
                        $configurableOption = $item->getOptionByCode('simple_product');
                        // var_dump($configurableOption);
                        if ($option && $option->getValue() == $giftRuleId && !$configurableOption) {
                            $giftItem[] = $item;
                            $giftItemQty += $item->getQty();
                        }
                    }

                    $giftRuleData = $this->giftRuleCacheHelper->getCachedGiftRule($giftRuleCode);

                    // If only 1 gift product available => add automatic gift product.
                    if ($this->giftRuleConfigHelper->isAutomaticAddEnabled() && count($giftItem) == 0 &&
                        count($giftRuleData[GiftRuleCacheHelper::DATA_PRODUCT_ITEMS]) == 1) {

                        $this->giftRuleService->addGiftProducts(
                            $quote,
                            [
                                [
                                    'id' => key($giftRuleData[GiftRuleCacheHelper::DATA_PRODUCT_ITEMS]),
                                    'qty' => $giftRuleData[GiftRuleCacheHelper::DATA_NUMBER_OFFERED_PRODUCT],
                                ],
                            ],
                            $giftRuleCode,
                            $giftRuleId
                        );
                        $saveQuote = true;
                    }

                    if ($giftItemQty > $giftRuleData[GiftRuleCacheHelper::DATA_NUMBER_OFFERED_PRODUCT]) {

                        // Delete gift item.
                        $qtyToDelete = $giftItemQty - $giftRuleData[GiftRuleCacheHelper::DATA_NUMBER_OFFERED_PRODUCT];

                        foreach (array_reverse($giftItem) as $item) {
                            if ($item->getQty() > $qtyToDelete) {
                                $item->setQty($item->getQty() - $qtyToDelete);
                                $saveQuote = true;
                                break;
                            } else {
                                $qtyToDelete = $qtyToDelete - $item->getQty();
                                $parentItemId = $item->getParentItemId();
                                if ($parentItemId) {
                                    $quote->removeItem($parentItemId);
                                }
                                $quote->deleteItem($item);
                                $saveQuote = true;
                            }

                            if ($qtyToDelete == 0) {
                                break;
                            }
                        }
                    }
                }
            }

            // NOTE: Setting the current gift rules here otherwise they will still be the old ones when saving the quote.
            $this->checkoutSession->setGiftRules($newGiftRulesList);

            // Now check all applied rules to make sure that they are in the list of available rules.
            // NOTE: We are doing this because if there are multiple gift rules and a user applies both,
            //       but then removes one of the products from the cart the free gifts will not be removed.
            // TODO: Submit a bug report and a pull request.
            foreach ($ruleIds as $ruleId) {
                if (!isset($newGiftRulesList[$ruleId])) {
                    /** @var Item $item */
                    $saveQuote = $this->clearGiftItems($quote, $ruleId) || $saveQuote;
                }
            }

            /**
             * Save quote if it is not cart add controller and item changed
             */
            if ($saveQuote
                && !($this->request->getControllerName() == 'cart' && $this->request->getActionName() == 'add')) {
                $this->recollectQuoteTotals($quote);
            }
        } else {
            $saveQuote = false;
            // Also have to check that free gifts are not applied any more once they are no longer valid.
            // TODO: Submit a bug report and a pull request.
            foreach ($quote->getAllItems() as $item) {
                $option = $item->getOptionByCode('option_gift_rule');
                $ruleId = ($option) ? $option->getValue() : null;
                $salesRule = $this->giftRuleHelper->getSalesRuleForGiftRuleId($ruleId);
                $invalidRule = !$salesRule || !$this->giftRuleHelper->willGiftRuleApplyToQuote($salesRule, $quote);
                if ($ruleId && !in_array($ruleId, $ruleIds) && $invalidRule) {
                    $saveQuote = $this->clearGiftItems($quote, $ruleId) || $saveQuote;
                }
            }
            if ($saveQuote
                && !($this->request->getControllerName() == 'cart' && $this->request->getActionName() == 'add')) {
                $this->recollectQuoteTotals($quote);
            }
        }
    }

    /**
     * Recollect the quote totals and refresh the current page.
     *
     * @param Quote $quote
     * @return void
     */
    protected function recollectQuoteTotals($quote)
    {
        // NOTE: Don't use the quoteRepository to save the quote as this could trigger an infinite loop.
        //       Instead just set the quote to have it's totals re-collected.
        // TODO: Re-implement the way that the app/lied gift rules are managed so that these sorts of issues
        //       do not occur._
        // $this->quoteRepository->save($quote);
        $quote->setTriggerRecollect(1)->save();
        // TODO: Consider refreshing the page as this doesn't always actually work for the current page request.
    }

    /**
     * Clear a specific rule from the quote or all gift items.
     *
     * @param Quote $quote
     * @param int|null $ruleId
     * @return boolean
     */
    protected function clearGiftItems($quote, $ruleId = null)
    {
        $saveQuote = false;
        foreach ($quote->getAllItems() as $item) {
            $option = $item->getOptionByCode('option_gift_rule');
            if ($option && (!$ruleId || $option->getValue() == $ruleId)) {
                // Remove gift item.
                $quote->deleteItem($item);
                $saveQuote = true;
            }
        }
        return $saveQuote;
    }

}
