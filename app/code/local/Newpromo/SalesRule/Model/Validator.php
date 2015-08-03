<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @category    Mage
 * @package     Mage_SalesRule
 * @copyright   Copyright (c) 2014 Magento Inc. (http://www.magentocommerce.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */


/**
 * SalesRule Validator Model
 *
 * Allows dispatching before and after events for each controller action
 *
 * @category   Mage
 * @package    Mage_SalesRule
 * @author     Magento Core Team <core@magentocommerce.com>
 */
class Newpromo_SalesRule_Model_Validator extends Mage_SalesRule_Model_Validator
{
    //created new member variable
    protected $_stopFurtherRules = array();
    public $discount_qty = 0;
    public $notsame = 0;
    
    
    /**
     * Quote item discount calculation process
     *
     * @param   Mage_Sales_Model_Quote_Item_Abstract $item
     * @return  Mage_SalesRule_Model_Validator
     */
    public function process(Mage_Sales_Model_Quote_Item_Abstract $item)
    {
        $item->setDiscountAmount(0);
        $item->setBaseDiscountAmount(0);
        $item->setDiscountPercent(0);
        $quote      = $item->getQuote();
        $address    = $this->_getAddress($item);

        $itemPrice              = $this->_getItemPrice($item);
        $baseItemPrice          = $this->_getItemBasePrice($item);
        $itemOriginalPrice      = $this->_getItemOriginalPrice($item);
        $baseItemOriginalPrice  = $this->_getItemBaseOriginalPrice($item);

        if ($itemPrice < 0) {
            return $this;
        }

        $appliedRuleIds = array();
        /* new variable it play important role*/
        $maxp = false;
        foreach ($this->_getRules() as $rule) {
            /* @var $rule Mage_SalesRule_Model_Rule */
            if (!$this->_canProcessRule($rule, $address)) {
                continue;
            }

            if (!$rule->getActions()->validate($item)) {
                continue;
            }

            $qty = $this->_getItemQty($item, $rule);
            $rulePercent = min(100, $rule->getDiscountAmount());

            $discountAmount = 0;
            $baseDiscountAmount = 0;
            //discount for original price
            $originalDiscountAmount = 0;
            $baseOriginalDiscountAmount = 0;

            switch ($rule->getSimpleAction()) {
                case Mage_SalesRule_Model_Rule::TO_PERCENT_ACTION:
                    $rulePercent = max(0, 100-$rule->getDiscountAmount());
                //no break;
                case Mage_SalesRule_Model_Rule::BY_PERCENT_ACTION:
                    $step = $rule->getDiscountStep();
                    if ($step) {
                        $qty = floor($qty/$step)*$step;
                    }
                    $_rulePct = $rulePercent/100;
                    $discountAmount    = ($qty*$itemPrice - $item->getDiscountAmount()) * $_rulePct;
                    $baseDiscountAmount= ($qty*$baseItemPrice - $item->getBaseDiscountAmount()) * $_rulePct;
                    //get discount for original price
                    $originalDiscountAmount    = ($qty*$itemOriginalPrice - $item->getDiscountAmount()) * $_rulePct;
                    $baseOriginalDiscountAmount= ($qty*$baseItemOriginalPrice - $item->getDiscountAmount()) * $_rulePct;

                    if (!$rule->getDiscountQty() || $rule->getDiscountQty()>$qty) {
                        $discountPercent = min(100, $item->getDiscountPercent()+$rulePercent);
                        $item->setDiscountPercent($discountPercent);
                    }
                    break;
                case Mage_SalesRule_Model_Rule::TO_FIXED_ACTION:
                    $quoteAmount = $quote->getStore()->convertPrice($rule->getDiscountAmount());
                    $discountAmount    = $qty*($itemPrice-$quoteAmount);
                    $baseDiscountAmount= $qty*($baseItemPrice-$rule->getDiscountAmount());
                    //get discount for original price
                    $originalDiscountAmount    = $qty*($itemOriginalPrice-$quoteAmount);
                    $baseOriginalDiscountAmount= $qty*($baseItemOriginalPrice-$rule->getDiscountAmount());
                    break;

                case Mage_SalesRule_Model_Rule::BY_FIXED_ACTION:
                    $step = $rule->getDiscountStep();
                    if ($step) {
                        $qty = floor($qty/$step)*$step;
                    }
                    $quoteAmount        = $quote->getStore()->convertPrice($rule->getDiscountAmount());
                    $discountAmount     = $qty*$quoteAmount;
                    $baseDiscountAmount = $qty*$rule->getDiscountAmount();
                    break;

                case Mage_SalesRule_Model_Rule::CART_FIXED_ACTION:
                    if (empty($this->_rulesItemTotals[$rule->getId()])) {
                        Mage::throwException(Mage::helper('salesrule')->__('Item totals are not set for rule.'));
                    }

                    /**
                     * prevent applying whole cart discount for every shipping order, but only for first order
                     */
                    if ($quote->getIsMultiShipping()) {
                        $usedForAddressId = $this->getCartFixedRuleUsedForAddress($rule->getId());
                        if ($usedForAddressId && $usedForAddressId != $address->getId()) {
                            break;
                        } else {
                            $this->setCartFixedRuleUsedForAddress($rule->getId(), $address->getId());
                        }
                    }
                    $cartRules = $address->getCartFixedRules();
                    if (!isset($cartRules[$rule->getId()])) {
                        $cartRules[$rule->getId()] = $rule->getDiscountAmount();
                    }

                    if ($cartRules[$rule->getId()] > 0) {
                        if ($this->_rulesItemTotals[$rule->getId()]['items_count'] <= 1) {
                            $quoteAmount = $quote->getStore()->convertPrice($cartRules[$rule->getId()]);
                            $baseDiscountAmount = min($baseItemPrice * $qty, $cartRules[$rule->getId()]);
                        } else {
                            $discountRate = $baseItemPrice * $qty /
                                            $this->_rulesItemTotals[$rule->getId()]['base_items_price'];
                            $maximumItemDiscount = $rule->getDiscountAmount() * $discountRate;
                            $quoteAmount = $quote->getStore()->convertPrice($maximumItemDiscount);

                            $baseDiscountAmount = min($baseItemPrice * $qty, $maximumItemDiscount);
                            $this->_rulesItemTotals[$rule->getId()]['items_count']--;
                        }

                        $discountAmount = min($itemPrice * $qty, $quoteAmount);
                        $discountAmount = $quote->getStore()->roundPrice($discountAmount);
                        $baseDiscountAmount = $quote->getStore()->roundPrice($baseDiscountAmount);

                        //get discount for original price
                        $originalDiscountAmount = min($itemOriginalPrice * $qty, $quoteAmount);
                        $baseOriginalDiscountAmount = $quote->getStore()->roundPrice($originalDiscountAmount);
                        $baseOriginalDiscountAmount = $quote->getStore()->roundPrice($baseItemOriginalPrice);

                        $cartRules[$rule->getId()] -= $baseDiscountAmount;
                    }
                    $address->setCartFixedRules($cartRules);

                    break;

                case Mage_SalesRule_Model_Rule::BUY_X_GET_Y_ACTION:
                    $x = $rule->getDiscountStep();
                    $y = $rule->getDiscountAmount();
                    if (!$x || $y>=$x) {
                        break;
                    }
                    $buyAndDiscountQty = $x + $y;

                    $fullRuleQtyPeriod = floor($qty / $buyAndDiscountQty);
                    $freeQty  = $qty - $fullRuleQtyPeriod * $buyAndDiscountQty;

                    $discountQty = $fullRuleQtyPeriod * $y;
                    if ($freeQty > $x) {
                        $discountQty += $freeQty - $x;
                    }

                    $discountAmount    = $discountQty * $itemPrice;
                    $baseDiscountAmount= $discountQty * $baseItemPrice;
                    //get discount for original price
                    $originalDiscountAmount    = $discountQty * $itemOriginalPrice;
                    $baseOriginalDiscountAmount= $discountQty * $baseItemOriginalPrice;
                    break;
                //new case condition for new rule BUY_5_AT_FIX_PRICE_ACTION
                case Newpromo_SalesRule_Model_Rule::BUY_5_AT_FIX_PRICE_ACTION:

                    $dq = 0;
                    $this->discount_qty = $this->discount_qty + $qty;                    
                    $dq = floor($this->discount_qty/5);                
                   
                    if($dq != 0){
                        if($this->notsame != $dq){
                        $quoteAmount = $quote->getStore()->convertPrice($rule->getDiscountAmount());  
                        $applied_qty = ($dq - $this->notsame)*5*$quoteAmount;
                        $this->notsame = $dq;         
                                               
                        $discountAmount     = $applied_qty;
                        $baseDiscountAmount = $applied_qty;
                        if($itemPrice * $qty < $applied_qty){
                            $maxp = true;
                        }
                      }  
                    }
                    break;

                 

                
            }

            $result = new Varien_Object(array(
                'discount_amount'      => $discountAmount,
                'base_discount_amount' => $baseDiscountAmount,
            ));
            Mage::dispatchEvent('salesrule_validator_process', array(
                'rule'    => $rule,
                'item'    => $item,
                'address' => $address,
                'quote'   => $quote,
                'qty'     => $qty,
                'result'  => $result,
            ));

            $discountAmount = $result->getDiscountAmount();
            $baseDiscountAmount = $result->getBaseDiscountAmount();

            $percentKey = $item->getDiscountPercent();
            /**
             * Process "delta" rounding
             */
            if ($percentKey) {
                $delta      = isset($this->_roundingDeltas[$percentKey]) ? $this->_roundingDeltas[$percentKey] : 0;
                $baseDelta  = isset($this->_baseRoundingDeltas[$percentKey])
                        ? $this->_baseRoundingDeltas[$percentKey]
                        : 0;
                $discountAmount+= $delta;
                $baseDiscountAmount+=$baseDelta;

                $this->_roundingDeltas[$percentKey]     = $discountAmount -
                                                          $quote->getStore()->roundPrice($discountAmount);
                $this->_baseRoundingDeltas[$percentKey] = $baseDiscountAmount -
                                                          $quote->getStore()->roundPrice($baseDiscountAmount);
                $discountAmount = $quote->getStore()->roundPrice($discountAmount);
                $baseDiscountAmount = $quote->getStore()->roundPrice($baseDiscountAmount);
            } else {
                $discountAmount     = $quote->getStore()->roundPrice($discountAmount);
                $baseDiscountAmount = $quote->getStore()->roundPrice($baseDiscountAmount);
            }

            /**
             * We can't use row total here because row total not include tax
             * Discount can be applied on price included tax
             */

            $itemDiscountAmount = $item->getDiscountAmount();
            $itemBaseDiscountAmount = $item->getBaseDiscountAmount();
          //newly added condition. it play important role
           if($maxp){
                $discountAmount     = max($itemDiscountAmount + $discountAmount, $itemPrice * $qty);
                $baseDiscountAmount = max($itemBaseDiscountAmount + $baseDiscountAmount, $baseItemPrice * $qty);
            }
            else{
                $discountAmount     = min($itemDiscountAmount + $discountAmount, $itemPrice * $qty);
                $baseDiscountAmount = min($itemBaseDiscountAmount + $baseDiscountAmount, $baseItemPrice * $qty);
            }

            $item->setDiscountAmount($discountAmount);
            $item->setBaseDiscountAmount($baseDiscountAmount);

            $item->setOriginalDiscountAmount($originalDiscountAmount);
            $item->setBaseOriginalDiscountAmount($baseOriginalDiscountAmount);

            $appliedRuleIds[$rule->getRuleId()] = $rule->getRuleId();

            $this->_maintainAddressCouponCode($address, $rule);
            $this->_addDiscountDescription($address, $rule);

            if ($rule->getStopRulesProcessing()) {
                break;
            }
        }

        $item->setAppliedRuleIds(join(',',$appliedRuleIds));
        $address->setAppliedRuleIds($this->mergeIds($address->getAppliedRuleIds(), $appliedRuleIds));
        $quote->setAppliedRuleIds($this->mergeIds($quote->getAppliedRuleIds(), $appliedRuleIds));

        return $this;
    }

    
}
