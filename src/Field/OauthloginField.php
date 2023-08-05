<?php

namespace Dionysopoulos\Plugin\System\CCOAuth2Helper\Field;

defined('_JEXEC') || die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\Event\Event;

class OauthloginField extends FormField
{
	protected $type = 'oauthlogin';

	protected function getInput()
	{
		$application = Factory::getApplication();
		$application->getLanguage()
			->load('plg_system_ccoauth2helper', JPATH_ADMINISTRATOR);

		try
		{
			$currentToken = $this->runEvent('onCCOAuth2HelperGetToken');
		}
		catch (\Exception $e)
		{
			$currentToken = null;
		}

		$loginUrl = $this->runEvent('onCCOAuth2HelperGetUrl');

		if (!empty($currentToken))
		{
			$text = Text::_('PLG_SYSTEM_CCOAUTH2HELPER_LBL_ALREADY_LOGGED_IN');

			return <<< HTML
<div class="alert alert-success">
	<span class="fa fa-check-double" aria-hidden="true"></span>
	$text
</div>
HTML;
		}

		if (empty($loginUrl))
		{
			$text = Text::_('PLG_SYSTEM_CCOAUTH2HELPER_LBL_PREPARE_TO_LOGIN');

			return <<< HTML
<div class="alert alert-info">
	<span class="fa fa-info-circle"></span>
	$text
</div>
HTML;
		}

		$text = Text::_('PLG_SYSTEM_CCOAUTH2HELPER_LBL_LOGIN');

		return <<< HTML
<a class="btn btn-primary"
   href="$loginUrl"
>
	<span class="fa fa-sign-in-alt me-2" aria-hidden="true"></span>
	$text
</a>
HTML;
	}

	private function runEvent($name): ?string
	{
		$application = Factory::getApplication();
		$dispatcher  = $application->getDispatcher();
		$event       = new Event($name);

		$dispatcher->dispatch($event->getName(), $event);

		$result = !isset($event['result']) || \is_null($event['result']) ? [] : $event['result'];

		if (!is_array($result))
		{
			$result = [];
		}

		return array_reduce($result, fn($carry, $new) => !empty($carry) ? $carry : $new);
	}
}