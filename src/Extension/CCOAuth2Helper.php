<?php

namespace Dionysopoulos\Plugin\System\CCOAuth2Helper\Extension;

defined('_JEXEC') || die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\User\UserHelper;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\DatabaseDriver;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Http\HttpFactory;
use Joomla\Session\Session;
use Joomla\Uri\Uri;
use RuntimeException;

class CCOAuth2Helper extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	public static function getSubscribedEvents(): array
	{
		return [
			'onAjaxCcoauth2helper'     => 'comAjaxHandler',
			'onCCOAuth2HelperGetUrl'   => 'getAuthenticationUrl',
			'onCCOAuth2HelperGetToken' => 'getAccessToken',
		];
	}

	public function getAuthenticationUrl(Event $event): void
	{
		// Get the current value of the Event's result
		$result = $event->getArgument('result', []) ?: [];

		// Make sure we have a valid Client ID in the plugin configuration.
		$clientId = trim($this->params->get('client_id') ?? '');

		if (empty($clientId))
		{
			return;
		}

		// Generate a random state key and find the return URL
		$randomState = UserHelper::genRandomPassword(32);
		$returnUrl   = \Joomla\CMS\Uri\Uri::getInstance()->toString();

		// Store the random state key and the current URL in the session
		/** @var Session $session */
		$session     = $this->getApplication()->getSession();
		$randomState = $session->get('plg_system_ccoauth2helper.state', $randomState) ?: $randomState;
		$returnUrl   = $session->get('plg_system_ccoauth2helper.return_url', $returnUrl) ?: $returnUrl;
		$session->set('plg_system_ccoauth2helper.return_url', $returnUrl);
		$session->set('plg_system_ccoauth2helper.state', $randomState);

		/**
		 * Generate the authorization URL
		 *
		 * @see https://developer.constantcontact.com/api_guide/server_flow.html
		 */
		$uri = new Uri('https://authz.constantcontact.com/oauth2/default/v1/authorize');
		$uri->setVar('client_id', $clientId);
		$uri->setVar('redirect_uri', $this->getRedirectUrl(true));
		$uri->setVar('response_type', 'code');
		$uri->setVar('scope', 'account_read account_update contact_data offline_access campaign_data');
		$uri->setVar('state', $randomState);

		// Add the result to the event
		$result[] = $uri->toString();

		$event->setArgument('result', $result);
	}

	public function comAjaxHandler(Event $event): void
	{
		$input = $this->getApplication()->getInput();
		$code  = $input->getRaw('code', '');
		$state = $input->getRaw('state', '');

		/** @var Session $session */
		$session    = $this->getApplication()->getSession();
		$validState = $session->get('plg_system_ccoauth2helper.state') ?: '';

		if (empty($validState) || ($state !== $validState))
		{
			throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$clientId     = trim($this->params->get('client_id') ?? '');
		$clientSecret = trim($this->params->get('client_secret') ?? '');

		$postUri = new Uri('https://authz.constantcontact.com/oauth2/default/v1/token');
		$postUri->setVar('code', $code);
		$postUri->setVar('redirect_uri', $this->getRedirectUrl(true));
		$postUri->setVar('grant_type', 'authorization_code');

		$headers = [
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/x-www-form-urlencoded',
			'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
		];

		$httpFactory = new HttpFactory();
		$http        = $httpFactory->getHttp();
		$result      = $http->post($postUri->toString(), [], $headers);

		if ($result->getStatusCode() !== 200)
		{
			throw new RuntimeException(
				sprintf('HTTP %d: %s', $result->getBody()->getContents(), $result->getStatusCode())
			);
		}

		$this->handleReturnedJson($result->body);

		// Redirect
		$returnUrl = $session->get('plg_system_ccoauth2helper.return_url', \Joomla\CMS\Uri\Uri::base());

		$session->set('plg_system_ccoauth2helper.return_url', null);
		$session->set('plg_system_ccoauth2helper.state', null);

		$this->getApplication()->enqueueMessage(Text::_('PLG_SYSTEM_CCOAUTH2HELPER_LBL_SUCCESS'));
		$this->getApplication()->redirect($returnUrl);
	}

	public function getAccessToken(Event $event): void
	{
		$data        = $this->getInternalData();
		$accessToken = $data->access_token;

		if (empty($accessToken))
		{
			throw new RuntimeException('You must connect to Constant Contact using OAuth2.');
		}

		if ($data->expires_on < time() + 60)
		{
			$clientId     = trim($this->params->get('client_id') ?? '');
			$clientSecret = trim($this->params->get('client_secret') ?? '');

			$postUri = new Uri('https://authz.constantcontact.com/oauth2/default/v1/token');
			$postUri->setVar('refresh_token', $data->refresh_token);
			$postUri->setVar('grant_type', 'refresh_token');
			$headers = [
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
			];

			$httpFactory = new HttpFactory();
			$http        = $httpFactory->getHttp();
			$result      = $http->post($postUri->toString(), [], $headers);

			if ($result->getStatusCode() !== 200)
			{
				throw new RuntimeException($result->getBody()->getContents(), $result->getStatusCode());
			}

			$this->handleReturnedJson($result->getBody()->getContents());

			$data        = $this->getInternalData();
			$accessToken = $data->access_token;
		}

		// Return the access token
		$result   = $event->getArgument('result', []) ?: [];
		$result[] = $accessToken;
		$event->setArgument('result', $result);
	}

	private function getRedirectUrl(bool $urlEncode = true): string
	{
		$url = rtrim(\Joomla\CMS\Uri\Uri::root(false), '/')
		       . '/index.php?option=com_ajax&group=system&plugin=ccoauth2helper&format=raw';

		if ($urlEncode)
		{
			return urlencode($url);
		}

		return $url;
	}

	private function handleReturnedJson(string $json): void
	{
		$data    = $this->getInternalData();
		$newData = @json_decode($json) ?: new \stdClass();

		$data->access_token  = $newData->access_token ?: $data->access_token ?? null;
		$data->refresh_token = $newData->refresh_token ?: $data->refresh_token ?? null;

		$expiresIn = $newData->expires_in ?: 0;
		$expiresIn = min(0, $expiresIn - 100);

		if ($expiresIn > 0)
		{
			$data->expires_on = time() + $expiresIn;
		}

		$this->setInternalData($data);
	}

	private function getInternalData(): object
	{
		/** @var DatabaseDriver $db */
		$db    = $this->getDatabase();
		$query = $db->getQuery(true)
			->select($db->quoteName('custom_data'))
			->from($db->quoteName('#__extensions'))
			->where(
				[
					$db->quoteName('type') . ' = ' . $db->quote('plugin'),
					$db->quoteName('element') . ' = ' . $db->quote('ccoauth2helper'),
					$db->quoteName('folder') . ' = ' . $db->quote('system'),
				]
			);
		$json  = $db->setQuery($query)->loadResult() ?: '{}';

		return @json_decode($json) ?: new \stdClass();
	}

	private function setInternalData(object $data): void
	{
		$json = json_encode($data);

		/** @var DatabaseDriver $db */
		$db    = $this->getDatabase();
		$query = $db->getQuery(true)
			->update($db->quoteName('#__extensions'))
			->set($db->quoteName('custom_data') . ' = :json')
			->where(
				[
					$db->quoteName('type') . ' = ' . $db->quote('plugin'),
					$db->quoteName('element') . ' = ' . $db->quote('ccoauth2helper'),
					$db->quoteName('folder') . ' = ' . $db->quote('system'),
				]
			)
			->bind(':json', $json);

		$db->setQuery($query)->execute();
	}
}