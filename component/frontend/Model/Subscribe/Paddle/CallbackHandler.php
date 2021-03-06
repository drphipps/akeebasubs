<?php
/**
 *  @package   AkeebaSubs
 *  @copyright Copyright (c)2010-$toda.year Nicholas K. Dionysopoulos / Akeeba Ltd
 *  @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Subscriptions\Site\Model\Subscribe\Paddle;


use Akeeba\Subscriptions\Site\Model\Subscribe\CallbackInterface;
use Akeeba\Subscriptions\Site\Model\Subscribe\HandlerTraits\LogCallback;
use Akeeba\Subscriptions\Site\Model\Subscribe\HandlerTraits\VerifyAuthenticity;
use Akeeba\Subscriptions\Site\Model\Subscribe\SubscriptionCallbackHandlerInterface;
use Akeeba\Subscriptions\Site\Model\Subscriptions;
use FOF30\Container\Container;
use FOF30\Inflector\Inflector;
use FOF30\Utils\StringHelper;
use RuntimeException;

/**
 * Callback handler for Paddle's webhooks
 *
 * Webhook URL     : https://www.example.com/index.php?option=com_akeebasubs&view=Callback&task=callback
 * Fulfillment URL : https://www.example.com/index.php?option=com_akeebasubs&view=Callback&task=callback
 *
 */
class CallbackHandler implements CallbackInterface
{
	use VerifyAuthenticity, LogCallback;

	/**
	 * The component's Container
	 *
	 * @var    Container
	 * @since  7.0.0
	 */
	private $container;

	/**
	 * Constructor
	 *
	 * @param Container $container The component container
	 */
	public function __construct(Container $container)
	{
		$this->container = $container;
	}

	/**
	 * Handle a webhook callback from the payment service provider
	 *
	 * @param   string  $requestMethod  The HTTP method, e.g. 'POST' or 'GET'
	 * @param   array   $requestData    The request data minus component, option, view, task
	 *
	 * @return  string|null  Text to include in the callback response page
	 *
	 * @throws  RuntimeException  In case an error occurs. The exception code will be used as the HTTP status.
	 *
	 * @since   7.0.0
	 */
	public function handleCallback(string $requestMethod, array $requestData): ?string
	{
		/**
		 * Possible alert_name values and what to do with them.
		 *
		 * A. akeebasubs_subscription_id in $requestData['passthrough']
		 *
		 * - null (fulfillment webhook)
		 * - payment_succeeded
		 * - payment_refunded
		 * - high_risk_transaction_created
		 * - high_risk_transaction_updated
		 * - subscription_created
		 * - subscription_updated
		 * - subscription_cancelled
		 * - subscription_payment_succeeded
		 * - subscription_payment_failed
		 * - subscription_payment_refunded
		 *
		 * B. $requestData['order_id'] contains the saved processor_key
		 *
		 * - payment_dispute_created
		 * - payment_dispute_closed
		 *
		 * C. Ignored / not interesting
		 *
		 * - locker_processed -- Not interesting to us
		 * - transfer_created
		 * - transfer_paid
		 * - new_audience_member
		 * - update_audience_member
		 */

		// Validate callback
		$isLegit = $this->verifyCallbackData($requestData);

		if (!$isLegit)
		{
			$this->logCallback($requestData, 'INVALID -- VERIFICATION FAILED');

			throw new RuntimeException('Invalid callback', 403);
		}

		// Get the alert name. If none is set up, it's a fulfilment webhook.
		$alertName = $requestData['alert_name'] ?? 'fulfillment';

		/**
		 * Some webhooks we don't really care about and do not handle. If that's the case, log and return.
		 *
		 * The other webhooks can either contain the akeebasubs_subscription_id in the 'passthrough' parameter, or the
		 * processor_key in the 'order_id' parameter. I will figure out which one is which since I need this information
		 * in the next step.
		 */
		switch ($alertName)
		{
			// A. akeebasubs_subscription_id in $requestData['passthrough']
			case 'fulfillment':
			case 'payment_succeeded':
			case 'payment_refunded':
			case 'high_risk_transaction_created':
			case 'high_risk_transaction_updated':
			case 'subscription_created':
			case 'subscription_updated':
			case 'subscription_cancelled':
			case 'subscription_payment_succeeded':
			case 'subscription_payment_failed':
			case 'subscription_payment_refunded':
				$key      = $requestData['passthrough'] ?? null;
				$findKeys = ['akeebasubs_subscription_id' => $key];
				break;

			// B. $requestData['order_id'] contains the saved processor_key
			case 'payment_dispute_created':
			case 'payment_dispute_closed':
				$key      = $requestData['order_id'] ?? null;
				$findKeys = ['processor_key' => $key];
				break;

			// C. Ignored / not interesting
			case 'locker_processed':
			case 'transfer_created':
			case 'transfer_paid':
			case 'new_audience_member':
			case 'update_audience_member':
				$this->logCallback($requestData, 'Ignored / not interesting webhook');
				return null;
				break;

			/**
			 * D. The alert_name is not anything I recognize.
			 *
			 * The only reason this would happen is a future expansion of the Paddle API which I cannot handle here.
			 */
			default:
				$this->logCallback($requestData, 'INVALID -- Unrecognized alert_name');
				return null;
				break;
		}

		/** @var Subscriptions $subscription */
		$subscription = $this->container->factory->model('Subscriptions')->tmpInstance();

		try
		{
			$subscription->findOrFail($findKeys);
		}
		catch (\Exception $e)
		{
			$this->logCallback($requestData, 'INVALID -- Cannot find the subscription record');

			return sprintf('Invalid subscription key %s', print_r($findKeys, true));
		}

		/**
		 * Find the class to handle this webhook
		 */
		$class = __NAMESPACE__ . '\\Handler\\' . $this->container->inflector->camelize($alertName);

		if (!class_exists($class, true))
		{
			$this->logCallback($requestData, 'NOT IMPLEMENTED -- I do not have a handler for these webhooks (yet)');

			return null;
		}

		/** @var SubscriptionCallbackHandlerInterface $handler */
		$handler = new $class($this->container);

		return $handler->handleCallback($subscription, $requestData);
	}
}