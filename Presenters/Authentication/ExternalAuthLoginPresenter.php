<?php

require_once(ROOT_DIR . 'Presenters/Authentication/LoginRedirector.php');

class ExternalAuthLoginPresenter
{
    /**
     * @var ExternalAuthLoginPage
     */
    private $page;
    /**
     * @var IWebAuthentication
     */
    private $authentication;
    /**
     * @var IRegistration
     */
    private $registration;

    public function __construct(ExternalAuthLoginPage $page, IWebAuthentication $authentication, IRegistration $registration)
    {
        $this->page = $page;
        $this->authentication = $authentication;
        $this->registration = $registration;
    }

    public function PageLoad()
    {
        if ($this->page->GetType() == 'google') {
            $this->ProcessGoogleSingleSignOn();
        }
        if ($this->page->GetType() == 'fb') {
            $this->ProcessSocialSingleSignOn('fbprofile.php');
        }
    }

    private function ProcessSocialSingleSignOn($page)
    {
        $code = $_GET['code'];
        Log::Debug('Logging in with social. Code=%s', $code);
        $result = file_get_contents("http://www.social.twinkletoessoftware.com/$page?code=$code");
        $profile = json_decode($result);

        $requiredDomainValidator = new RequiredEmailDomainValidator($profile->email);
        $requiredDomainValidator->Validate();
        if (!$requiredDomainValidator->IsValid()) {
            Log::Debug('Social login with invalid domain. %s', $profile->email);
            $this->page->ShowError([Resources::GetInstance()->GetString('InvalidEmailDomain')]);
            return;
        }

        Log::Debug('Social login successful. Email=%s', $profile->email);
        $this->registration->Synchronize(
            new AuthenticatedUser(
                $profile->email,
                $profile->email,
                $profile->first_name,
                $profile->last_name,
                Password::GenerateRandom(),
                Resources::GetInstance()->CurrentLanguage,
                Configuration::Instance()->GetDefaultTimezone(),
                null,
                null,
                null
            ),
            false,
            false
        );

        $this->authentication->Login($profile->email, new WebLoginContext(new LoginData()));
        LoginRedirector::Redirect($this->page);
    }

    private function ProcessGoogleSingleSignOn()
    {

        $client = new Google\Client();
        $client->setClientId(Configuration::Instance()->GetSectionKey(ConfigSection::AUTHENTICATION, ConfigKeys::GOOGLE_CLIENT_ID));
        $client->setClientSecret(Configuration::Instance()->GetSectionKey(ConfigSection::AUTHENTICATION, ConfigKeys::GOOGLE_CLIENT_SECRET));
        $client->setRedirectUri(Configuration::Instance()->GetSectionKey(ConfigSection::AUTHENTICATION, ConfigKeys::GOOGLE_REDIRECT_URI));
        $client->addScope("email");
        $client->addScope("profile");

        if (isset($_GET['code'])) {
            //Token validations for the client
            $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
            //set the acess token that it received
            $client->setAccessToken($token['access_token']);
        
            //Using the Google API to get the user information 
            $google_oauth = new Google\Service\Oauth2($client);
            $google_account_info = $google_oauth->userinfo->get();
            
            //Save the informations needed to authenticate the login
            $email =  $google_account_info->email;
            $firstName = $google_account_info->given_name;
            $lastName = $google_account_info->family_name;
        
            $code = $_GET['code'];

            $requiredDomainValidator = new RequiredEmailDomainValidator($email);
            $requiredDomainValidator->Validate();
            if (!$requiredDomainValidator->IsValid()) {
                Log::Debug('Social login with invalid domain. %s', $email);
                $this->page->ShowError(array(Resources::GetInstance()->GetString('InvalidEmailDomain')));
                return;
            }

            Log::Debug('Social login successful. Email=%s', $email);
            $this->registration->Synchronize(new AuthenticatedUser($email,
                $email,
                $firstName,
                $lastName,
                Password::GenerateRandom(),
                Resources::GetInstance()->CurrentLanguage,
                Configuration::Instance()->GetDefaultTimezone(),
                null,
                null,
                null),
                false,
                false);

            $this->authentication->Login($email, new WebLoginContext(new LoginData()));
            LoginRedirector::Redirect($this->page);
        }
    }
}
