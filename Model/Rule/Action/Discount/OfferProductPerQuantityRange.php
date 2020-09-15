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
namespace Smile\GiftSalesRule\Model\Rule\Action\Discount;

use Magento\Checkout\Model\Session as checkoutSession;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Rule\Action\Discount\AbstractDiscount;
use Magento\SalesRule\Model\Rule\Action\Discount\Data as DiscountData;
use Magento\SalesRule\Model\Rule\Action\Discount\DataFactory;
use Magento\SalesRule\Model\Validator;
use Magento\SalesRule\Api\Data\RuleInterface;
use Smile\GiftSalesRule\Api\Data\GiftRuleInterface;
use Smile\GiftSalesRule\Api\GiftRuleRepositoryInterface;
use Smile\GiftSalesRule\Helper\Cache as GiftRuleCacheHelper;
use Smile\GiftSalesRule\Model\GiftRule;

/**
 * Class Offer Product Per Price Range
 *
 * @author    Maxime Queneau <maxime.queneau@smile.fr>
 * @copyright 2019 Smile
 */
class OfferProductPerQuantityRange extends AbstractDiscount
{
    /**
     * @var checkoutSession
     */
    protected $checkoutSession;

    /**
     * @var GiftRuleCacheHelper
     */
    protected $giftRuleCacheHelper;

    /**
     * @var GiftRuleRepositoryInterface
     */
    protected $giftRuleRepository;

    /**
     * OfferProductPerQuantityRange constructor.
     *
     * @param Validator                   $validator           Validator
     * @param DataFactory                 $discountDataFactory Discount data factory
     * @param PriceCurrencyInterface      $priceCurrency       Price currency
     * @param checkoutSession             $checkoutSession     Checkout session
     * @param GiftRuleCacheHelper         $giftRuleCacheHelper Gift rule cache helper
     * @param GiftRuleRepositoryInterface $giftRuleRepository  Gift rule repository
     */
    public function __construct(
        Validator $validator,
        DataFactory $discountDataFactory,
        PriceCurrencyInterface $priceCurrency,
        checkoutSession $checkoutSession,
        GiftRuleCacheHelper $giftRuleCacheHelper,
        GiftRuleRepositoryInterface $giftRuleRepository
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->giftRuleCacheHelper = $giftRuleCacheHelper;
        $this->giftRuleRepository = $giftRuleRepository;

        parent::__construct(
            $validator,
            $discountDataFactory,
            $priceCurrency
        );
    }

    /**
     * @param Rule         $rule Rule
     * @param AbstractItem $item Item
     * @param float        $qty  Qty
     *
     * @return DiscountData
     * @SuppressWarnings(PHPMD.ElseExpression)
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function calculate($rule, $item, $qty)
    {
        /** @var \Magento\SalesRule\Model\Rule\Action\Discount\Data $discountData */
        $discountData = $this->discountFactory->create();

        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $item->getQuote();

        // Have a separate `$calculateId` for each item otherwise this rule will only be checked for a 
        // single item.
        // TODO: Report a bug.
        $calculateId = 'calculate_gift_rule_'.$rule->getRuleId() . '__' . $item->getId();
        if (!$quote->getData($calculateId)) {
            // Set only for performance (not save in DB).
            $quote->setData($calculateId, true);

            /**
             * Rules load by collection => extension attributes not present in rule entity
             */
            /** @var GiftRule $giftRule */
            $giftRule = $this->giftRuleRepository->getById($rule->getRuleId());

            // Match the quantity from the rule conditions
            $totalValidQuantity = $this->getTotalValidQuantity($rule, $item->getQuote());

            if ($totalValidQuantity >= $giftRule->getQuantityRange()) {
                $range = floor($totalValidQuantity / $giftRule->getQuantityRange());

                // Save active gift rule in session.
                $giftRuleSessionData = $this->checkoutSession->getGiftRules();
                $giftRuleSessionData[$rule->getRuleId()] = $rule->getRuleId() . '_' . $range;
                $this->checkoutSession->setGiftRules($giftRuleSessionData);

                // Set number offered product.
                $giftRule->setNumberOfferedProduct($giftRule->getMaximumNumberProduct() * $range);

                $this->giftRuleCacheHelper->saveCachedGiftRule(
                    $rule->getRuleId() . '_' . $range,
                    $rule,
                    $giftRule
                );
            } else {
                // NOTE: Cannot remove the gift rules otherwise they will be missed by the CollectGiftRule class.
                //       We have implemented a work-around for this, but it shouldn't be here anyway.
                // TODO: Report a bug for the `OfferProductPerPriceRange` class.
                // Save active gift rule in session.
                // $giftRuleSessionData = $this->checkoutSession->getGiftRules();
                // if (isset($giftRuleSessionData[$rule->getRuleId()])) {
                //     unset($giftRuleSessionData[$rule->getRuleId()]);
                // }
                // $this->checkoutSession->setGiftRules($giftRuleSessionData);
            }
        }

        return $discountData;
    }

    /**
     * Get the total valid quantity for a quote based off of the items that are valid for
     * the current cart rule conditions.
     *
     * @param Rule $rule
     * @param Quote $quote
     * @return void
     */
    protected function getTotalValidQuantity($rule, $quote)
    {
        $totalValidQuantity = 0;
        if ($quote && $quote->getItems()) {
            foreach ($quote->getItems() as $item) {
                // NOTE: Cloning quote items doesn't work, children won't exist, etc...
                // $item = clone $quoteItem;
                // $item->setChildren($quoteItem->getChildren());
                // NOTE: This is a hacky work-around to allow us to _actually_ validate the cart rule.
                $item->setBypassGiftRuleValidation(true);
                $allItems = $item->getAllItems();
                // TODO: Should we be validating a Quote object? Investigate if so and how we would be able
                //       to clone the quote without persisting everything and without Magento soiling the bed.
                $item->setAllItems([$item]);
                if (!$item->getOptionByCode('option_gift_rule') && $rule->validate($item)) {
                    $totalValidQuantity += $item->getTotalQty();
                }
                // NOTE Resetting all items should be pointless as they shouldn't be set on a quote item anyway.
                $item->setAllItems($allItems);
                $item->setBypassGiftRuleValidation(false);
            }
        }
        return $totalValidQuantity;
    }
}
