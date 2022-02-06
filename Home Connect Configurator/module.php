<?php

declare(strict_types=1);
    class HomeConnectConfigurator extends IPSModule
    {
        const MODULE_TYPES =
        [
            'Default' => '{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}'
        ];

        public function Create()
        {
            //Never delete this line!
            parent::Create();
            $this->ConnectParent('{CE76810D-B685-9BE0-CC04-38B204DEAD5E}');
        }

        public function Destroy()
        {
            //Never delete this line!
            parent::Destroy();
        }

        public function ApplyChanges()
        {
            //Never delete this line!
            parent::ApplyChanges();
        }

        public function GetConfigurationForm()
        {
            $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
            // Return if parent is not confiured
            if (!$this->HasActiveParent()) {
                return json_encode($form);
            }
            $homeapplianceData = json_decode($this->getHomeAppliances(), true);
            if (isset($homeapplianceData['data']) && isset($homeapplianceData['data']['homeappliances'])) {
                $homeappliances = $homeapplianceData['data']['homeappliances'];
                $devices = [];
                foreach ($homeappliances as $homeappliance) {
                    $devices[] = [
                        'HaID'       => $homeappliance['haId'],
                        'Name'       => $homeappliance['name'],
                        'Type'       => $this->Translate($homeappliance['type']),
                        'Brand'      => $homeappliance['brand'],
                        'Connected'  => $homeappliance['connected'] ? $this->Translate('Yes') : $this->Translate('No'),
                        'instanceID' => $this->getInstanceIDForGuid($homeappliance['haId'], '{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}'),
                        'create'     => [
                            'moduleID'      => $this->getModuleIDByType($homeappliance['type']),
                            'configuration' => [
                                'HaID'       => $homeappliance['haId'],
                                'DeviceType' => $homeappliance['type']
                            ],
                            'name' => $homeappliance['name']
                        ]
                    ];
                    $this->SendDebug($homeappliance['name'], $this->getModuleIDByType($homeappliance['type']), 0);
                }
                $form['actions'][0]['values'] = $devices;
            } else {
                $this->SendDebug('Error', json_encode($homeapplianceData), 0);
                $errorDescription = $this->Translate('No error description available');
                if (isset($homeapplianceData['error']) && isset($homeapplianceData['error']['description'])) {
                    $errorDescription = $homeapplianceData['error']['description'];
                }
                $form['elements'][] = [
                    'type'  => 'PopupAlert',
                    'popup' => [
                        'items' => [
                            [
                                'type'    => 'Label',
                                'caption' => $this->Translate('An error occurred during the request to Home Connect:') .
                                PHP_EOL . $errorDescription
                            ]
                        ]
                    ]
                ];
            }
            return json_encode($form);
        }

        private function getHomeAppliances()
        {
            return $this->requestDataFromParent('homeappliances');
        }

        private function requestDataFromParent($endpoint)
        {
            $return = @$this->SendDataToParent(json_encode([
                'DataID'      => '{41DDAA3B-65F0-B833-36EE-CEB57A80D022}',
                'Endpoint'    => $endpoint
            ]));

            if (false === $return) {
                $return = "";
            }

            return $return;
        }

        private function getModuleIDByType($type)
        {
            return isset(self::MODULE_TYPES[$type]) ? self::MODULE_TYPES[$type] : self::MODULE_TYPES['Default'];
        }

        private function getInstanceIDForGuid($haid, $guid)
        {
            $instanceIDs = IPS_GetInstanceListByModuleID($guid);
            foreach ($instanceIDs as $instanceID) {
                if (IPS_GetProperty($instanceID, 'HaID') == $haid) {
                    return $instanceID;
                }
            }
            return 0;
        }
    }
