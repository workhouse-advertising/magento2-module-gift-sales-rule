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
namespace Smile\GiftSalesRule\Helper;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Quote\Model\Quote;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\RuleFactory;
use Smile\GiftSalesRule\Api\Data\GiftRuleInterface;
use Smile\GiftSalesRule\Api\GiftRuleRepositoryInterface;

/**
 * Gift rule helper
 *
 * @author    Maxime Queneau <maxime.queneau@smile.fr>
 * @copyright 2019 Smile
 */
class GiftRule extends AbstractHelper
{
    /**
     * @var array
     */
    protected $giftRule = [];

    /**
     * @var GiftRuleRepositoryInterface
     */
    protected $giftRuleRepository;

    /**
     * @var RuleFactory
     */
    protected $ruleFactory;

    /**
     * GiftRule constructor.
     *
     * @param Context                     $context            Context
     * @param GiftRuleRepositoryInterface $giftRuleRepository Gift rule repository
     * @param RuleFactory                 $ruleFactory        Rule factory
     * @param array                       $giftRule           Gift rule
     */
    public function __construct(
        Context $context,
        GiftRuleRepositoryInterface $giftRuleRepository,
        RuleFactory $ruleFactory,
        array $giftRule = []
    ) {
        $this->giftRuleRepository = $giftRuleRepository;
        $this->ruleFactory = $ruleFactory;
        $this->giftRule = $giftRule;

        parent::__construct($context);
    }

    /**
     * Is gift sales rule
     *
     * @param Rule $rule Rule
     *
     * @return bool
     */
    public function isGiftRule(Rule $rule)
    {
        $isGiftRule = false;
        if (in_array($rule->getSimpleAction(), $this->giftRule)) {
            $isGiftRule = true;
        }

        return $isGiftRule;
    }

    /**
     * Check if is valid gift rule for quote
     *
     * @param Rule  $rule  Rule
     * @param Quote $quote Quote
     *
     * @return bool
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function isValidGiftRule(Rule $rule, Quote $quote)
    {
        $valid = true;

        /**
         * Check if quote has at least one quote item (no gift rule item) in quote
         */
        $hasProduct = false;
        foreach ($quote->getAllItems() as $item) {
            if (!$item->getOptionByCode('option_gift_rule')) {
                $hasProduct = true;
                break;
            }
        }
        if (!$hasProduct) {
            $valid = false;
        }

        // TODO: Consider adding similar checks for GiftRuleInterface::OFFER_PRODUCT_PER_QUANTITY_RANGE
        if ($valid && $rule->getSimpleAction() == GiftRuleInterface::OFFER_PRODUCT_PER_PRICE_RANGE) {
            /**
             * Rules load by collection => extension attributes not present in rule entity
             */
            /** @var GiftRuleInterface $giftRule */
            $giftRule = $this->giftRuleRepository->getById($rule->getRuleId());

            if ($quote->getGrandTotal() < $giftRule->getPriceRange()) {
                $valid = false;
            }
        }

        return $valid;
    }

    /**
     * Get the sales rule for a gift rule.
     *
     * @param int $giftRuleId
     * @return Rule|null
     */
    public function getSalesRuleForGiftRuleId($giftRuleId)
    {
        $giftRuleId = (int) $giftRuleId;
        $salesRule = null;
        try {
            $salesRule = $this->ruleFactory->create()->load($giftRuleId);
            $salesRule = ($salesRule && $salesRule->getId()) ? $salesRule : null;
        } catch (\Exception $e) {
            // TODO: Catch specific Exceptions.
        }
        return $salesRule;
    }

    /**
     * To test if a gift rule is actually applicable to the cart.
     *
     * @param Rule $rule
     * @param Quote $quote
     * @return boolean
     */
    public function willGiftRuleApplyToQuote($rule, $quote)
    {
        $answer = false;
        // NOTE: Have to check _every_ quote item and test if they pass the gift rule conditions.
        if ($quote && $quote->getId() && $rule && $rule->getId() && $this->isGiftRule($rule) && $quote->getItems()) {
            foreach ($quote->getItems() as $quoteItem) {
                // TODO: Consider putting this validation nonsense into a helper or something.
                $quoteItem->setBypassGiftRuleValidation(true);
                $allItems = $quoteItem->getAllItems();
                
                // TODO: Should we be validating a Quote object? Investigate if so and how we would be able
                //       to clone the quote without persisting everything and without Magento soiling the bed.
                $quoteItem->setAllItems([$quoteItem]);
                if (!$quoteItem->getOptionByCode('option_gift_rule') && $rule->validate($quoteItem)) {
                    $answer = true;
                }
                // NOTE Resetting all items should be pointless as they shouldn't be set on a quote item anyway.
                $quoteItem->setAllItems($allItems);
                $quoteItem->setBypassGiftRuleValidation(false);
            }
        }
        return $answer;
    }

    /**
     * Retrieve url for add gift product to cart
     *
     * @param int    $giftRuleId   Gift rule id
     * @param string $giftRuleCode Gift rule code
     *
     * @return string
     */
    public function getAddUrl($giftRuleId, $giftRuleCode)
    {
        $routeParams = [
            ActionInterface::PARAM_NAME_URL_ENCODED => $this->urlEncoder->encode($this->_urlBuilder->getCurrentUrl()),
            'gift_rule_id'   => $giftRuleId,
            'gift_rule_code' => $giftRuleCode,
        ];

        return $this->_getUrl('giftsalesrule/cart/add', $routeParams);
    }
}
