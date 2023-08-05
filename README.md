# Constant Contact OAuth2 Helper for Joomla!

A simple plugin to help with accessing the Constant Contact API.

Copyright ©2023 Nicholas K. Dionysopoulos

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but **WITHOUT ANY WARRANTY**; without even the implied warranty of
**MERCHANTABILITY** or **FITNESS FOR A PARTICULAR PURPOSE**.  See the
GNU General Public License for more details.

You should have received [a copy of the GNU General Public License](LICENSE.md)
along with this program.  If not, see <https://www.gnu.org/licenses/>.

## What does it do and how to use it

The plugin makes available a Joomla! plugin event called `onCCOAuth2HelperGetToken`. Calling it returns a Constant Contact OAuth2 Bearer token (access token) which is guaranteed to be valid for _at least_ the next 60 seconds. You can use that token to make your API calls in your custom code.

Here's a simple example, creating a contact.

```php
// Get the access token through the plugin. You can use this as boilerplate code.
$accessToken = call_user_func(function(): string {
    $application = \Joomla\CMS\Factory::getApplication();
    $dispatcher = $application->getDispatcher();
    $event = new \Joomla\Event\Event('onCCOAuth2HelperGetToken');
    
    try {
        $dispatcher->dispatch($event->getName(), $event);
    } catch(\Throwable $e) {
        return '';
    }
    
    $result = !isset($event['result']) || \is_null($event['result']) ? [] : $event['result'];
    $result = is_array($result) ? $result : [];
    
    return array_reduce($result, fn($carry, $new) => !empty($carry) ? $carry : $new) ?: '';
});

// Make an API request to create a contact.
// -- Create a Joomla HTTP client
$http = (new \Joomla\Http\HttpFactory())->getHttp();
// -- Add the necessary HTTP headers
$headers = [
    // The Authorization header uses the access token we retrieved above.
    'Authorization' => 'Bearer ' . $accessToken,
    // Avoid cached results.
    'Cache-Control' => 'no-cache',
    // These are necessary for the v3 API to work at all.
    'Accept' => 'application/json',
    'Content-Type' => 'application/json',
];
// This is an easier and more secure way of producing the JSON data to post than the hardcoded soup in CC's examples.
$data = json_encode([
    'email_address' => 'john.doe@example.com',
    'first_name' => 'John',
    'last_name' => 'Doe',
    'job_title' => 'Yak Shaver',
    'company_name' => 'Acme Corp.',
    'create_source' => 'Account',
    'list_memberships' => [
        'd13d60d0-f256-11e8-b47d-fa163e56c9b0',
    ],
]);

// Send the request
$response = $http->post('https://api.cc.email/v3/contacts/sign_up_form', $data, $headers);

// Make sure we get an expected status code
if (!in_array($response->getStatusCode(), [200, 201])) {
    throw new RuntimeException(sprintf('Could not create contact. HTTP error code %d.', $response->getStatusCode()));
}

// Make sure CC returned an action and it's either updated or created.
$result = @json_decode($response->body) ?: new \stdClass();

if (!in_array($result?->action, ['created', 'updated'])) throw new RuntimeException('Could not create contact.');
```

## Creating a Constant Contact application

Before you begin, you need to create an “application” on Constant Contact's developer site. This will give you an Application Key (Client ID) and an Application Secret. You need these two bits of information to use this plugin.

Go to [Constant Contact's developer site](https://developer.constantcontact.com/) anc click on [My Applications](https://app.constantcontact.com/pages/dma/portal/?provisioning=true). You will need to log in, or create an account (it's free).

Click on **New Application**.

Enter a name for your application, e.g. “My Site integration with Constant Contact”.

Click on the Next button at the bottom of the popup.

Back on the list page, click on Edit next to your new application.

Copy the **API Key (Client Id)**.

Click on the Generate Client Secret button.

On the popup, click on the new Generate Client Secret button.

Copy the generated **Application secret**. You will not see it again, so make sure you pay attention on this step!

Click the pencil icon next to the **Redirect URI** text box.

Enter a URL similar to `https://www.example.com/index.php?option=com_ajax&group=system&plugin=ccoauth2helper&format=raw` replacing `https://www.example.com` with your site's actual URL.

Click on the Save button at the top right.

## Getting started with the plugin

Download this plugin as a ZIP file and install it on Joomla!.

Publish the just installed “System - Constant Contact OAuth2 Helper” plugin.

Edit the “System - Constant Contact OAuth2 Helper” plugin.

Enter your API Key and Application Secret in the plugin's same–named fields.

Click the Save button on the toolbar.

A button appears below the Application Secret field with the title “Log into Constant Contact”. Click on it.

Log into Constant Contact using the credentials of the user account which you will be using to send newsletters. Please note that this may be _different_ to the Constant Contact account you used to create the application.