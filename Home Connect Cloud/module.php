<?php

declare(strict_types=1);

    include_once __DIR__ . '/../libs/WebOAuthModule.php';
    include_once __DIR__ . '/api-test.php';

    class HomeConnectCloud extends WebOAuthModule
    {
        // Simulatoion
        // const HOME_CONNECT_BASE = 'https://simulator.home-connect.com/api/';
        // private $oauthIdentifer = 'home_connect_dev';

        //Real
        const HOME_CONNECT_BASE = 'https://api.home-connect.com/api/';
        private $oauthIdentifer = 'home_connect';

        private $oauthServer = 'oauth.ipmagic.de';

        use TestAPI;
        public function __construct($InstanceID)
        {
            parent::__construct($InstanceID, $this->oauthIdentifer);
        }


        public function Create()
        {
            //Never delete this line!
            parent::Create();

            $this->RegisterAttributeString('Token', '');

            $this->RequireParent('{2FADB4B7-FDAB-3C64-3E2C-068A4809849A}');

            $this->RegisterMessage(IPS_GetInstance($this->InstanceID)['ConnectionID'], IM_CHANGESTATUS);

            $this->RegisterPropertyString('Language', 'de-DE');
        }

        public function ApplyChanges()
        {
            //Never delete this line!
            parent::ApplyChanges();
        }

        /**
         * This function will be called by the register button on the property page!
         */
        public function Register()
        {

            //Return everything which will open the browser
            return 'https://' . $this->oauthServer . '/authorize/' . $this->oauthIdentifer . '?username=' . urlencode(IPS_GetLicensee());
        }

        public function ForwardData($Data)
        {
            $data = json_decode($Data, true);
            $this->SendDebug('Forward', $Data, 0);
            if (isset($data['Payload'])) {
                return $this->putRequest($data['Endpoint'], $data['Payload']);
            }
            return $this->getRequest($data['Endpoint']);
        }

        public function ReceiveData($JSONString)
        {
            $data = json_decode($JSONString);
            $payload = $data->Buffer;
            $this->SendDataToChildren(json_encode(['DataID' => '{173D59E5-F949-1C1B-9B34-671217C07B0E}', 'Buffer' => $payload]));
        }

        public function MessageSink($Timestamp, $SenderID, $MessageID, $Data)
        {
            $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
            if ($SenderID == $parentID) {
                switch ($MessageID) {
                    case IM_CHANGESTATUS:
                        // Update SSE if it is faulty
                        if ($Data[0] >= IS_EBASE) {
                            $this->RegisterServerEvents();
                        }
                        break;
                }
            }
        }

        public function RegisterServerEvents()
        {
            $url = self::HOME_CONNECT_BASE . 'homeappliances/events';
            $this->SendDebug('url', $url, 0);
            $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'];
            if (!IPS_GetProperty($parent, 'Active')) {
                echo $this->Translate('IO instance is not active');
                return;
            }
            IPS_SetProperty($parent, 'URL', $url);
            IPS_SetProperty($parent, 'Headers', json_encode([['Name' => 'Authorization', 'Value' => 'Bearer ' . $this->FetchAccessToken()]]));
            IPS_ApplyChanges($parent);
        }

        /**
         * This function will be called by the OAuth control. Visibility should be protected!
         */
        protected function ProcessOAuthData()
        {

            //Lets assume requests via GET are for code exchange. This might not fit your needs!
            if ($_SERVER['REQUEST_METHOD'] == 'GET') {
                if (!isset($_GET['code'])) {
                    die('Authorization Code expected');
                }

                $token = $this->FetchRefreshToken($_GET['code']);

                $this->SendDebug('ProcessOAuthData', "OK! Let's save the Refresh Token permanently", 0);

                $this->WriteAttributeString('Token', $token);
                $this->UpdateFormField('Token', 'caption', 'Token: ' . substr($token, 0, 16) . '...');
            } else {

                //Just print raw post data!
                echo file_get_contents('php://input');
            }
        }

        private function FetchRefreshToken($code)
        {
            $this->SendDebug('FetchRefreshToken', 'Use Authentication Code to get our precious Refresh Token!', 0);

            //Exchange our Authentication Code for a permanent Refresh Token and a temporary Access Token
            $options = [
                'http' => [
                    'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'method'  => 'POST',
                    'content' => http_build_query(['code' => $code])
                ]
            ];
            $context = stream_context_create($options);
            $result = file_get_contents('https://' . $this->oauthServer . '/access_token/' . $this->oauthIdentifer, false, $context);

            $data = json_decode($result);

            if (!isset($data->token_type) || $data->token_type != 'Bearer') {
                die('Bearer Token expected');
            }

            //Save temporary access token
            $this->FetchAccessToken($data->access_token, time() + $data->expires_in);

            //Return RefreshToken
            return $data->refresh_token;
        }

        private function FetchAccessToken($Token = '', $Expires = 0)
        {

            //Exchange our Refresh Token for a temporary Access Token
            if ($Token == '' && $Expires == 0) {

                //Check if we already have a valid Token in cache
                $data = $this->GetBuffer('AccessToken');
                if ($data != '') {
                    $data = json_decode($data);
                    if (time() < $data->Expires) {
                        $this->SendDebug('FetchAccessToken', 'OK! Access Token is valid until ' . date('d.m.y H:i:s', $data->Expires), 0);
                        return $data->Token;
                    }
                }

                $this->SendDebug('FetchAccessToken', 'Use Refresh Token to get new Access Token!', 0);

                //If we slipped here we need to fetch the access token
                $options = [
                    'http' => [
                        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                        'method'  => 'POST',
                        'content' => http_build_query(['refresh_token' => $this->ReadAttributeString('Token')])
                    ]
                ];
                $context = stream_context_create($options);
                $result = file_get_contents('https://' . $this->oauthServer . '/access_token/' . $this->oauthIdentifer, false, $context);

                $data = json_decode($result);

                if (!isset($data->token_type) || $data->token_type != 'Bearer') {
                    die('Bearer Token expected');
                }

                //Update parameters to properly cache it in the next step
                $Token = $data->access_token;
                $Expires = time() + $data->expires_in;

                //Update Refresh Token if we received one! (This is optional)
                if (isset($data->refresh_token)) {
                    $this->SendDebug('FetchAccessToken', "NEW! Let's save the updated Refresh Token permanently", 0);

                    $this->WriteAttributeString('Token', $data->refresh_token);
                    $this->UpdateFormField('Token', 'caption', 'Token: ' . substr($data->refresh_token, 0, 16) . '...');
                }
            }

            $this->SendDebug('FetchAccessToken', 'CACHE! New Access Token is valid until ' . date('d.m.y H:i:s', $Expires), 0);

            //Save current Token
            $this->SetBuffer('AccessToken', json_encode(['Token' => $Token, 'Expires' => $Expires]));

            //Return current Token
            return $Token;
        }

        private function FetchData($url)
        {
            $opts = [
                'http'=> [
                    'method'        => 'POST',
                    'header'        => 'Authorization: Bearer ' . $this->FetchAccessToken() . "\r\n" . 'Content-Type: application/json' . "\r\n",
                    'content'       => '{"JSON-KEY":"THIS WILL BE LOOPED BACK AS RESPONSE!"}',
                    'ignore_errors' => true
                ]
            ];
            $context = stream_context_create($opts);

            $result = file_get_contents($url, false, $context);

            if ((strpos($http_response_header[0], '200') === false)) {
                echo $http_response_header[0] . PHP_EOL . $result;
                return false;
            }

            return $result;
        }

        private function getData($endpoint)
        {
            $opts = [
                'http'=> [
                    'method'        => 'GET',
                    'header'        => 'Authorization: Bearer ' . $this->FetchAccessToken() . "\r\n" .
                                       'Accept-Language: ' . $this->ReadPropertyString('Language') . "\r\n",
                    'ignore_errors' => true
                ]
            ];
            $context = stream_context_create($opts);
            $this->SendDebug('url', self::HOME_CONNECT_BASE . $endpoint, 0);
            $this->SendDebug('token', $this->FetchAccessToken(), 0);

            $result = file_get_contents(self::HOME_CONNECT_BASE . $endpoint, false, $context);
            $this->SendDebug('response', $result, 0);

            // if ((strpos($http_response_header[0], '200') === false)) {
            //     // echo $http_response_header[0] . PHP_EOL . $result;
            //     return false;
            // }

            return $result;
        }

        private function putData($endpoint, $content)
        {
            $opts = [
                'http'=> [
                    'method'        => 'PUT',
                    'header'        => 'Authorization: Bearer ' . $this->FetchAccessToken() . "\r\n" .
                                       'Content-Length: ' . strlen($content) . "\r\n" .
                                       'Content-Type: application/vnd.bsh.sdk.v1+json' . "\r\n",
                    'Accept-Language: ' . $this->ReadPropertyString('Language') . "\r\n",
                    'content'       => $content,
                    'ignore_errors' => true
                ]
            ];
            $context = stream_context_create($opts);
            $this->SendDebug('url', self::HOME_CONNECT_BASE . $endpoint, 0);
            $this->SendDebug('token', $this->FetchAccessToken(), 0);

            $result = file_get_contents(self::HOME_CONNECT_BASE . $endpoint, false, $context);
            $this->SendDebug('response', $result, 0);

            if ((strpos($http_response_header[0], '201') === false)) {
                // $this->SendDebug('Error', json_decode($result, true)['error']['description'], 0);
                return $result;
            }

            return $result;
        }
    }