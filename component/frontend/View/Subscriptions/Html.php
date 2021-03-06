<?php
/**
 * @package   AkeebaSubs
 * @copyright Copyright (c)2010-2019 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Subscriptions\Site\View\Subscriptions;

use Akeeba\Subscriptions\Site\Model\Invoices;
use Akeeba\Subscriptions\Site\Model\Levels;
use Akeeba\Subscriptions\Site\Model\Subscriptions;
use JUri;

defined('_JEXEC') or die;

class Html extends \FOF30\View\DataView\Html
{
	public $returnURL = '';

	public $activeLevels = [];

	public $allLevels = [];

	public $subIDs = [];

	public $invoices = [];

	public $sortTable = [];

	private $recurringSubsPerLevel = [];

	protected function onBeforeBrowse()
	{
		parent::onBeforeBrowse();

		// Get subscription levels information
		$this->initLevelsInformation();

		// Get the information on active recurring subscriptions per subscription level
		$this->initRecurringPerLevel();

		// Get subscription and subscription level IDs, sort subscriptions based on their status
		$this->sortSubscriptions();

		// Get legacy invoicing data
		$this->initLegacyInvoices();
	}

	/**
	 * Retrieve legacy invoicing data (invoices issued as early as mid-2012 and as late as mid-2019).
	 *
	 * @return  void
	 *
	 * @since   7.0.0
	 */
	protected function initLegacyInvoices(): void
	{
		$this->invoices = [];

		/** @var Invoices $invoicesModel */
		$invoicesModel = $this->container->factory->model('Invoices')->tmpInstance();

		if (!empty($this->subIDs))
		{
			$rawInvoices = $invoicesModel
				->subids($this->subIDs)
				->get(true);

			if ($rawInvoices->count())
			{
				/** @var Invoices $rawInvoice */
				foreach ($rawInvoices as $rawInvoice)
				{
					$this->invoices[$rawInvoice->akeebasubs_subscription_id] = $rawInvoice;
				}
			}
		}
	}

	/**
	 * Gets all subscription levels and all active levels
	 *
	 * @return  void
	 *
	 * @since   7.0.0
	 */
	private function initLevelsInformation(): void
	{
		/** @var Levels $levelsModel */
		$levelsModel = $this->container->factory->model('Levels')->tmpInstance();

		$rawActiveLevels = $levelsModel
			->get(true);

		$this->activeLevels = [];
		$this->allLevels    = [];

		// Let's get all the enabled plugins
		if ($rawActiveLevels->count())
		{
			/** @var Levels $l */
			foreach ($rawActiveLevels as $l)
			{
				$this->allLevels[$l->akeebasubs_level_id] = $l;

				if ($l->enabled)
				{
					$this->activeLevels[] = $l->akeebasubs_level_id;
				}
			}
		}
	}

	/**
	 * Initializes the active recurring subscriptions per subscription level.
	 *
	 * @return  void
	 *
	 * @since   7.0.0
	 */
	private function initRecurringPerLevel(): void
	{
		if (empty($this->items))
		{
			return;
		}

		/** @var Subscriptions $sub */
		foreach ($this->items as $sub)
		{
			// I only care about enabled subscriptions. Expired / replaced / canceled / new / pending don't count.
			if (!$sub->enabled)
			{
				continue;
			}

			// Recurring subscriptions have an update and cancel URL
			if (empty($sub->cancel_url) || empty($sub->update_url))
			{
				continue;
			}

			if (!isset($this->recurringSubsPerLevel[$sub->akeebasubs_level_id]))
			{
				$this->recurringSubsPerLevel[$sub->akeebasubs_level_id] = [];
			}

			$this->recurringSubsPerLevel[$sub->akeebasubs_level_id][] = $sub->getId();
		}
	}

	/**
	 * Are there any *active* recurring subscriptions on the same level as $sub?
	 *
	 * @param   Subscriptions  $sub
	 *
	 * @return  bool
	 *
	 * @since   7.0.0
	 */
	public function hasOtherRecurringInLevel(Subscriptions $sub): bool
	{
		if (!isset($this->recurringSubsPerLevel[$sub->akeebasubs_level_id]))
		{
			return false;
		}

		if (empty($this->recurringSubsPerLevel[$sub->akeebasubs_level_id]))
		{
			return false;
		}

		foreach ($this->recurringSubsPerLevel[$sub->akeebasubs_level_id] as $id)
		{
			if ($id != $sub->getId())
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Is there another active, new or pending subscription on the same level as $sub?
	 *
	 * @param   Subscriptions  $sub  The subscription record to check
	 *
	 * @return  bool
	 */
	public function hasOtherActiveNewOrPendingInLevel(Subscriptions $sub): bool
	{
		$myId = $sub->getId();

		foreach (['new', 'active', 'waiting', 'pending'] as $area)
		{
			foreach ($this->sortTable[$area] as $subId)
			{
				/** @var Subscriptions $otherSub */
				$otherSub = $this->items[$subId];

				if ($otherSub->getId() == $myId)
				{
					continue;
				}

				if ($otherSub->akeebasubs_level_id != $sub->akeebasubs_level_id)
				{
					continue;
				}

				return true;
			}
		}

		return false;
	}

	/**
	 * Sorts the user's subscriptions by their status
	 */
	private function sortSubscriptions(): void
	{
		$this->subIDs    = [];
		$this->sortTable = [
			'new'      => [],
			'active'   => [],
			'waiting'  => [],
			'pending'  => [],
			'expired'  => [],
			'canceled' => [],
		];

		if ($this->items->count())
		{
			/** @var Subscriptions $sub */
			foreach ($this->items as $sub)
			{
				$id             = $sub->akeebasubs_subscription_id;
				$this->subIDs[] = $id;
				$upDate         = $this->container->platform->getDate($sub->publish_up);

				if ($sub->state == 'N')
				{
					// Filter out new subscriptions without a payment_url; we can't do anything about them :)
					if (!empty($sub->payment_url))
					{
						$this->sortTable['new'][] = $id;
					}
				}
				elseif ($sub->state == 'P')
				{
					$this->sortTable['pending'][] = $id;
				}
				elseif ($sub->state == 'X')
				{
					$this->sortTable['canceled'][] = $id;
				}
				elseif ($sub->enabled)
				{
					$this->sortTable['active'][] = $id;
				}
				elseif (($sub->state == 'C') && ($upDate->toUnix() >= time()))
				{
					$this->sortTable['waiting'][] = $id;
				}
				else
				{
					$this->sortTable['expired'][] = $id;
				}
			}
		}
	}
}
