<?php
/**
 * @package		akeebasubs
 * @copyright	Copyright (c)2010-2013 Nicholas K. Dionysopoulos / AkeebaBackup.com
 * @license		GNU GPLv3 <http://www.gnu.org/licenses/gpl.html> or later
 */

defined('_JEXEC') or die();

$akpaymentinclude = include_once JPATH_ADMINISTRATOR.'/components/com_akeebasubs/assets/akpayment.php';
if(!$akpaymentinclude) { unset($akpaymentinclude); return; } else { unset($akpaymentinclude); }

class plgAkpayment2conew extends plgAkpaymentAbstract
{
	public function __construct(&$subject, $config = array())
	{
		$config = array_merge($config, array(
			'ppName'		=> '2conew',
			'ppKey'			=> 'PLG_AKPAYMENT_2CONEW_TITLE',
			'ppImage'		=> 'https://www.2checkout.com/images/paymentlogoshorizontal.png',
		));

		parent::__construct($subject, $config);
	}

	/**
	 * Returns the payment form to be submitted by the user's browser. The form must have an ID of
	 * "paymentForm" and a visible submit button.
	 *
	 * @param string $paymentmethod
	 * @param JUser $user
	 * @param AkeebasubsTableLevel $level
	 * @param AkeebasubsTableSubscription $subscription
	 * @return string
	 */
	public function onAKPaymentNew($paymentmethod, $user, $level, $subscription)
	{
		if($paymentmethod != $this->ppName) return false;

		$slug = FOFModel::getTmpInstance('Levels','AkeebasubsModel')
				->setId($subscription->akeebasubs_level_id)
				->getItem()
				->slug;

		$rootURL = rtrim(JURI::base(),'/');
		$subpathURL = JURI::base(true);
		if(!empty($subpathURL) && ($subpathURL != '/')) {
			$rootURL = substr($rootURL, 0, -1 * strlen($subpathURL));
		}

		$data = (object)array(
			'url'			=> ($this->params->get('checkout') == 'single') ? 'https://www.2checkout.com/checkout/spurchase' : 'https://www.2checkout.com/checkout/purchase',
			'sid'			=> $this->params->get('sid',''),
			'x_receipt_link_url'	=> $rootURL.str_replace('&amp;','&',JRoute::_('index.php?option=com_akeebasubs&view=message&slug='.$slug.'&layout=order&subid='.$subscription->akeebasubs_subscription_id)),
			'params'		=> $this->params,
			'name'			=> $user->name,
			'email'			=> $user->email,
			'currency'		=> strtoupper(AkeebasubsHelperCparams::getParam('currency','EUR')),
			'recurring'		=> $level->recurring ? ($subscription->recurring_amount >= 0.01 ? 2 : 1) : 0,
		);

		if ($data->recurring >= 1)
		{
			$ppDuration = $this->_toPPDuration($level->duration);
			$data->t3 = $ppDuration->unit;
			$data->p3 = $ppDuration->value;
		}

		$kuser = FOFModel::getTmpInstance('Users','AkeebasubsModel')
			->user_id($user->id)
			->getFirstItem();

		@ob_start();
		include dirname(__FILE__).'/2conew/form.php';
		$html = @ob_get_clean();

		return $html;
	}

	public function onAKPaymentCallback($paymentmethod, $data)
	{
		JLoader::import('joomla.utilities.date');

		// Check if we're supposed to handle this
		if($paymentmethod != $this->ppName) return false;

		// Check if it's one of the message types supported by this plugin
		$message_type = $data['message_type'];
		$isValid = in_array($message_type, array(
			'ORDER_CREATED', 'REFUND_ISSUED', 'RECURRING_INSTALLMENT_SUCCESS', 'FRAUD_STATUS_CHANGED', 'INVOICE_STATUS_CHANGED'
		));
		if(!$isValid) $data['akeebasubs_failure_reason'] = 'INS message type "'.$message_type.'" is not supported.';

		// Check IPN data for validity (i.e. protect against fraud attempt)
		if($isValid) {
			$isValid = $this->isValidIPN($data);
			if(!$isValid) $data['akeebasubs_failure_reason'] = 'Transaction MD5 signature is invalid. Fraudulent transaction or testing mode enabled.';
		}

		// Load the relevant subscription row
		if($isValid) {
			$id = array_key_exists('merchant_order_id', $data) ? (int)$data['merchant_order_id'] : -1;
			$subscription = null;
			if($id > 0) {
				$subscription = FOFModel::getTmpInstance('Subscriptions','AkeebasubsModel')
					->setId($id)
					->getItem();
				if( ($subscription->akeebasubs_subscription_id <= 0) || ($subscription->akeebasubs_subscription_id != $id) ) {
					$subscription = null;
					$isValid = false;
				}
			} else {
				$isValid = false;
			}
			if(!$isValid) $data['akeebasubs_failure_reason'] = 'The referenced subscription ID ("merchant_order_id" field) is invalid';
		}

		// Check that order_number has not been previously processed
		if($isValid && !is_null($subscription)) {
			if($subscription->processor_key == $data['sale_id'].'/'.$data['invoice_id']) {
				if(($subscription->state == 'C') && ($message_type == 'ORDER_CREATED')) {
					$isValid = false;
					$data['akeebasubs_failure_reason'] = "I will not process the same sale_id/invoice_id twice";
				}
			}
		}

		// Check that total is correct
		$isPartialRefund = false;
		if($isValid && !is_null($subscription)) {
			$mc_gross = floatval($data['invoice_list_amount']);
			$gross = $subscription->gross_amount;
			if($mc_gross > 0) {
				// A positive value means "payment". The prices MUST match!
				// Important: NEVER, EVER compare two floating point values for equality.
				$isValid = ($gross - $mc_gross) < 0.01;
			} else {
				$valid = false;
			}
			if(!$isValid) $data['akeebasubs_failure_reason'] = 'Paid amount (invoice_list_amount) does not match the subscription amount';
		}

		// Log the IPN data
		$this->logIPN($data, $isValid);

		// Fraud attempt? Do nothing more!
		if(!$isValid) return false;

		// Load the subscription level and get its slug
		$slug = FOFModel::getTmpInstance('Levels','AkeebasubsModel')
				->setId($subscription->akeebasubs_level_id)
				->getItem()
				->slug;

		$rootURL = rtrim(JURI::base(),'/');
		$subpathURL = JURI::base(true);
		if(!empty($subpathURL) && ($subpathURL != '/')) {
			$rootURL = substr($rootURL, 0, -1 * strlen($subpathURL));
		}

		$recurring_installment = false;

		switch($message_type) {
			case 'ORDER_CREATED':
			case 'FRAUD_STATUS_CHANGED':
			case 'INVOICE_STATUS_CHANGED':
				// Let me translate the goofy statuses sent by 2Checkout to English for ya
				switch($data['invoice_status'])
				{
					case 'approved':
						// "Approved" means "we're about to request the money" or something like that, dunno
						$newStatus = 'C';
						break;

					case 'pending':
						// "Pending" means "accepted by bank, the money is not in your account yet"
						$newStatus = 'C';
						break;

					case 'deposited':
						// "Deposited" means "the money is yours".
						$newStatus = 'C';
						// However, if the subscription is CANCELLED then a refund has already
						// been issued, but 2Checkout sends a "deposited" status. What the hell?!
						if($subscription->state == 'X') {
							$newStatus = 'X';
						}
						break;

					case 'declined':
					default:
						// "Declined" means "you ain't gonna have your money, bro"
						$newStatus = 'X';
						break;
				}
				break;

			case 'REFUND_ISSUED':
				$newStatus = 'X';
				break;

			case 'RECURRING_INSTALLMENT_SUCCESS':
				// Handle recurring payments
				$newStatus = 'C';
				$recurring_installment = true;
				break;

		}

		// Update subscription status (this also automatically calls the plugins)
		$updates = array(
			'akeebasubs_subscription_id' => $id,
			'processor_key'		=> $data['sale_id'].'/'.$data['invoice_id'],
			'state'				=> $newStatus,
			'enabled'			=> 0
		);

		// On recurring payments also store the subscription ID
		if($recurring_installment) {
			$subscr_id = $data['sale_id'];
			$params = $subscription->params;
			if(!is_array($params)) {
				$params = json_decode($params, true);
			}
			if(is_null($params) || empty($params)) {
				$params = array();
			}
			$params['recurring_id'] = $subscr_id;
			$updates['params'] = $params;
		}

		JLoader::import('joomla.utilities.date');
		if($newStatus == 'C') {
			$this->fixDates($subscription, $updates);
		}

		// In the case of a successful recurring payment, fetch the old subscription's data
		if($recurring_installment && ($newStatus == 'C') && ($subscription->state == 'C')) {
			// Fix the starting date if the payment was accepted after the subscription's start date. This
			// works around the case where someone pays by e-Check on January 1st and the check is cleared
			// on January 5th. He'd lose those 4 days without this trick. Or, worse, if it was a one-day pass
			// the user would have paid us and we'd never given him a subscription!
			$regex = '/^\d{1,4}(\/|-)\d{1,2}(\/|-)\d{2,4}[[:space:]]{0,}(\d{1,2}:\d{1,2}(:\d{1,2}){0,1}){0,1}$/';
			if(!preg_match($regex, $subscription->publish_up)) {
				$subscription->publish_up = '2001-01-01';
			}
			if(!preg_match($regex, $subscription->publish_down)) {
				$subscription->publish_down = '2038-01-01';
			}
			$jNow = new JDate();
			$jStart = new JDate($subscription->publish_up);
			$jEnd = new JDate($subscription->publish_down);
			$now = $jNow->toUnix();
			$start = $jStart->toUnix();
			$end = $jEnd->toUnix();

			// Create a new record for the old subscription
			$oldData = $subscription->getData();
			$oldData['akeebasubs_subscription_id'] = 0;
			$oldData['publish_down'] = $jNow->toSql();
			$oldData['enabled'] = 0;
			$oldData['contact_flag'] = 3;
			$oldData['notes'] = "Automatically renewed subscription on " . $jNow->toSql();

			// Calculate new start/end time for the subscription
			$allSubs = FOFModel::getTmpInstance('Subscriptions', 'AkeebasubsModel')
				->paystate('C')
				->level($subscription->akeebasubs_level_id)
				->user_id($subscription->user_id);
			$max_expire = 0;
			if(count($allSubs)) foreach($allSubs as $aSub) {
				$jExpire = new JDate($aSub->publish_down);
				$expire = $jExpire->toUnix();
				if($expire > $max_expire) $max_expire = $expire;
			}

			$duration = $end - $start;
			$start = max($now, $max_expire);
			$end = $start + $duration;
			$jStart = new JDate($start);
			$jEnd = new JDate($end);

			$updates['publish_up'] = $jStart->toSql();
			$updates['publish_down'] = $jEnd->toSql();

			// Save the record for the old subscription
			$table = FOFModel::getTmpInstance('Subscriptions', 'AkeebasubsModel')
				->getTable();
			$table->reset();
			$table->bind($oldData);
			$table->store();
		} elseif($recurring_installment && ($newStatus != 'C')) {
			// Recurring payment, but payment_status is not Completed. We have
			// stop right now and not save the changes. Otherwise the status of
			// the subscription will become P or X and the recurring payment
			// code above will not run when PayPal sends us a new IPN with the
			// status set to Completed.
			return;
		}

		// Save the changes
		$subscription->save($updates);

		// Run the onAKAfterPaymentCallback events
		JLoader::import('joomla.plugin.helper');
		JPluginHelper::importPlugin('akeebasubs');
		$app = JFactory::getApplication();
		$jResponse = $app->triggerEvent('onAKAfterPaymentCallback',array(
			$subscription
		));

		return true;
	}

	/**
	 * Validates the incoming data against the md5 posted by 2Checkout to make sure this is not a
	 * fraudelent request.
	 */
	private function isValidIPN($data)
	{
		// This is the MD5 calculations in 2Checkout's INS guide
		$incoming_md5 = strtoupper($data['md5_hash']);
		$calculated_md5 = md5(
			$data['sale_id'].
			$data['vendor_id'].
			$data['invoice_id'].
			$this->params->get('secret','')
		);
		$calculated_md5 = strtoupper($calculated_md5);

		return ($calculated_md5 == $incoming_md5);
	}


	private function _toPPDuration($days)
	{
		$ret = (object)array(
			'unit'		=> 'Week',
			'value'		=> 1
		);

		// 0-7 => return one week (sorry!)
		if($days < 7) return $ret;

		// Translate to weeks, months and years
		$weeks = (int)($days / 7);
		$months = (int)($days / 30);
		$years = (int)($days / 365);

		// Find which one is the closest match
		$deltaW = abs($days - $weeks*7);
		$deltaM = abs($days - $months*30);
		$deltaY = abs($days - $years*365);
		$minDelta = min($deltaW, $deltaM, $deltaY);

		// Counting weeks gives a better approximation
		if($minDelta == $deltaW) {
			$ret->unit = 'Week';
			$ret->value = $weeks;

			// Make sure we have 1-52 weeks, otherwise go for a months or years
			if(($ret->value > 0) && ($ret->value <= 52)) {
				return $ret;
			} else {
				$minDelta = min($deltaM, $deltaY);
			}
		}

		// Counting months gives a better approximation
		if($minDelta == $deltaM) {
			$ret->unit = 'Month';
			$ret->value = $months;

			// Make sure we have a number of months which is not a multiple of 12
			if (($ret->value % 12) == 0)
			{
				$minDelta = min($deltaM, $deltaY);
			}
			else
			{
				return $ret;
			}
		}

		// If we're here, we're better off translating to years
		$ret->unit = 'Year';
		$ret->value = $years;

		if($ret->value <= 0) {
			// Too short? Make it 1 (should never happen)
			$ret->value = 1;
		}

		return $ret;
	}
}