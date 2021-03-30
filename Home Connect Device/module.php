<?php

declare(strict_types=1);
    class HomeConnectDevice extends IPSModule
    {
        const ATTRIBUTES = [
            'BSH.Common.Status.RemoteControlStartAllowed',
            'BSH.Common.Status.RemoteControlActive'
        ];
        public function Create()
        {
            //Never delete this line!
            parent::Create();

            $this->ConnectParent('{CE76810D-B685-9BE0-CC04-38B204DEAD5E}');

            $this->RegisterPropertyString('HaID', '');
            $this->RegisterPropertyString('DeviceType', '');

            $this->RegisterAttributeString('Options', '[]');
            $this->RegisterAttributeString('Settings', '[]');

            //States
            $this->RegisterAttributeBoolean('RemoteControlActive', false);
            $this->RegisterAttributeBoolean('RemoteControlStartAllowed', false);

            //States
            if (!IPS_VariableProfileExists('HomeConnect.Common.Status.OperationState')) {
                IPS_CreateVariableProfile('HomeConnect.Common.Status.OperationState', VARIABLETYPE_STRING);
                $this->createAssociations('HomeConnect.Common.Status.OperationState', [
                    ['Value' => 'BSH.Common.EnumType.OperationState.Inactive', 'Name' => 'Inactive'],
                    ['Value' => 'BSH.Common.EnumType.OperationState.Ready', 'Name' => 'Ready'],
                    ['Value' => 'BSH.Common.EnumType.OperationState.DelayedStart', 'Name' => 'Delayed Start'],
                    ['Value' => 'BSH.Common.EnumType.OperationState.Run', 'Name' => 'Run'],
                    ['Value' => 'BSH.Common.EnumType.OperationState.ActionRequired', 'Name' => 'Action Required'],
                    ['Value' => 'BSH.Common.EnumType.OperationState.Finished', 'Name' => 'Finished'],
                    ['Value' => 'BSH.Common.EnumType.OperationState.Error', 'Name' => 'Error'],
                    ['Value' => 'BSH.Common.EnumType.OperationState.Abort', 'Name' => 'Abort'],
                ]);
            }
            if (!IPS_VariableProfileExists('HomeConnect.Common.Status.DoorState')) {
                IPS_CreateVariableProfile('HomeConnect.Common.Status.DoorState', VARIABLETYPE_STRING);
                $this->createAssociations('HomeConnect.Common.Status.DoorState', [
                    ['Value' => 'BSH.Common.EnumType.DoorState.Open', 'Name' => 'Open'],
                    ['Value' => 'BSH.Common.EnumType.DoorState.Closed', 'Name' => 'Closed'],
                    ['Value' => 'BSH.Common.EnumType.DoorState.Locked', 'Name' => 'Locked'],
                ]);
            }

            //Settings
            if (!IPS_VariableProfileExists('HomeConnect.Common.Setting.PowerState')) {
                IPS_CreateVariableProfile('HomeConnect.Common.Setting.PowerState', VARIABLETYPE_STRING);
                $this->createAssociations('HomeConnect.Common.Setting.PowerState', [

                    // ['Value' => 'BSH.Common.EnumType.PowerState.Off', 'Name' => 'Off'],
                    ['Value' => 'BSH.Common.EnumType.PowerState.On', 'Name' => 'On'],
                    ['Value' => 'BSH.Common.EnumType.PowerState.Standby', 'Name' => 'Standby'],
                ]);
            }
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

            if (IPS_GetKernelRunlevel() == KR_READY) {
                if ($this->HasActiveParent()) {
                    $this->processData($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/status'));
                    $this->setupSettings();
                    // $this->processData($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/selected'));
                    $this->createPrograms();
                }
            }

            $this->SetReceiveDataFilter('.*' . $this->ReadPropertyString('HaID') . '.*');
        }

        public function ReceiveData($String)
        {
            $eventData = explode("\n", utf8_decode(json_decode($String, true)['Buffer']));
            preg_match('/event: (.+)/m', $eventData[0], $eventMatches);
            $this->SendDebug('Event', $eventMatches[1], 0);
            preg_match('/data: (.+)/m', $eventData[1], $dataMatches);
            $items = json_decode($dataMatches[1], true)['items'];
            switch ($eventMatches[1]) {
                case 'STATUS':
                case 'NOTIFY':
                    foreach ($items as $item) {
                        $ident = $this->getLastSnippet($item['key']);
                        if (in_array($item['key'], self::ATTRIBUTES)) {
                            $this->WriteAttributeBoolean($ident, $item['value']);
                            continue;
                        }
                        $this->SendDebug('item', json_encode($item), 0);
                        switch (gettype($item['value'])) {
                            case 'boolean':
                                case 'integer':
                                case 'double':
                                $value = $item['value'];
                                break;

                            default:
                                $value = $item['value'];
                                break;
                        }
                        $this->SetValue($ident, $value);
                        $this->SendDebug('Data', $item['key'], 0);
                    }

            }
        }

        public function GetConfigurationForm()
        {
            $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
            return json_encode($form);
        }

        public function RequestAction($Ident, $Value)
        {
            switch ($Ident) {
                case 'SelectedProgram':
                    $payload = [
                        'data' => [
                            'key'     => $Value,
                            'options' => []
                        ]
                    ];
                    $this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/selected/', json_encode($payload));
                    $this->createOptions($Value);
                    break;

                case 'Control':
                    if (!$this->ReadAttributeBoolean('RemoteControlStartAllowed')) {
                        echo $this->Translate('Remote start not allowed');
                        break;
                    }

                    $payload = [
                        'data' => [
                            'key'     => $this->GetValue('SelectedProgram'),
                            'options' => []
                        ]
                    ];
                    $this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/active/', json_encode($payload));
                    break;

                default:
                    $availableOptions = json_decode($this->ReadAttributeString('Options'), true);
                    if (isset($availableOptions[$Ident])) {
                        $payload = [
                            'data' => [
                                'key'   => $availableOptions[$Ident]['key'],
                                'value' => $Value
                            ]
                        ];
                        if (isset($availableOptions[$Ident]['unit'])) {
                            $payload['data']['unit'] = $availableOptions[$Ident]['unit'];
                        }
                        $this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/selected/options/' . $availableOptions[$Ident]['key'], json_encode($payload));
                    }

                    $availableSettings = json_decode($this->ReadAttributeString('Settings'), true);
                    $this->SendDebug('Settings', json_encode($availableSettings), 0);
                    if (isset($availableSettings[$Ident])) {
                        $payload = [
                            'data' => [
                                'key'   => $availableSettings[$Ident]['key'],
                                'value' => $Value
                            ]
                        ];
                        $this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/settings/' . $availableSettings[$Ident]['key'], json_encode($payload));
                    }
                    break;
            }
            $this->SetValue($Ident, $Value);
        }

        private function createPrograms()
        {
            $rawPrograms = json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/available'), true);
            $this->SendDebug('Programms', json_encode($rawPrograms), 0);
            if (!isset($rawPrograms['data']['programs'])) {
                return;
            }
            $programs = $rawPrograms['data']['programs'];
            $profileName = 'HomeConnect.' . $this->ReadPropertyString('DeviceType') . '.Programs';
            foreach ($programs as $program) {
                preg_match_all('/(?P<program>.+)\.(?P<value>.+)/m', $program['key'], $matches);
                if (!IPS_VariableProfileExists($profileName)) {
                    IPS_CreateVariableProfile($profileName, VARIABLETYPE_STRING);
                }
                IPS_SetVariableProfileAssociation($profileName, $program['key'], $this->Translate($matches['value'][0]), '', -1);
            }
            $ident = 'SelectedProgram';
            $this->MaintainVariable($ident, 'Program', VARIABLETYPE_STRING, $profileName, 1, true);
            $this->EnableAction($ident);
        }

        private function createOptions($program)
        {
            $rawOptions = json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/available/' . $program), true);
            $this->SendDebug('Options', json_encode($rawOptions), 0);
            if (!isset($rawOptions['data']['options'])) {
                return;
            }
            $options = $rawOptions['data']['options'];
            $availableOptions = json_decode($this->ReadAttributeString('Options'), true);
            $position = 10;
            foreach ($options as $option) {
                $this->SendDebug($option['key'], json_encode($option), 0);
                $key = $option['key'];
                preg_match_all('/.+\.(?P<option>.+)/m', $key, $matches);
                $optionName = $matches['option'][0];
                if (!isset($availableOptions[$optionName])) {
                    $availableOptions[$optionName]['key'] = $key;
                }
                $profileName = 'HomeConnect.' . $this->ReadPropertyString('DeviceType') . '.Option.' . $matches['option'][0];
                switch ($option['type']) {
                    case 'Int':
                        $variableType = VARIABLETYPE_INTEGER;
                        $constraints = $option['constraints'];
                        $availableOptions[$optionName]['unit'] = $option['unit'];
                        if (!IPS_VariableProfileExists($profileName)) {
                            //Create profile
                            IPS_CreateVariableProfile($profileName, $variableType);
                        }
                        IPS_SetVariableProfileText($profileName, '', ' ' . $option['unit']);
                        $this->SendDebug('min - max', $constraints['min'] . ' - ' . $constraints['max'], 0);
                        IPS_SetVariableProfileValues($profileName, $constraints['min'], $constraints['max'], isset($constraints['stepsize']) ? $constraints['stepsize'] : 1);
                        break;

                    default:
                        $variableType = VARIABLETYPE_STRING;
                        if (!IPS_VariableProfileExists($profileName)) {
                            //Create profile
                            IPS_CreateVariableProfile($profileName, $variableType);
                        } else {
                            //Clear profile if it exists
                            $profile = IPS_GetVariableProfile($profileName);
                            // foreach($profile['Associations'] as $association) {
                            // 	IPS_SetVariableProfileAssociation($profileName, $association['Value'], '', '', -1);
                            // }
                        }
                        //Add options as associations
                        foreach ($option['constraints']['allowedvalues'] as $value) {
                            preg_match_all('/(.+)\.(?P<option>.+)\.(?P<value>.+)/m', $value, $matches);
                            IPS_SetVariableProfileAssociation($profileName, $value, $matches['value'][0], '', -1);
                        }
                        break;
                }

                //Create variable if not exists
                $ident = $matches['option'][0];
                if (!@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
                    $this->MaintainVariable($ident, $ident, $variableType, $profileName, $position++, true);
                    $this->EnableAction($ident);
                }
            }

            $this->WriteAttributeString('Options', json_encode($availableOptions));
            if (!IPS_VariableProfileExists('HomeConnect.Control')) {
                IPS_CreateVariableProfile('HomeConnect.Control', VARIABLETYPE_STRING);
                IPS_SetVariableProfileAssociation('HomeConnect.Control', 'Control', $this->Translate('Start'), '', -1);
            }
            if (!@IPS_GetObjectIDByIdent('Control', $this->InstanceID)) {
                $this->MaintainVariable('Control', $this->Translate('Control'), VARIABLETYPE_STRING, 'HomeConnect.Control', $position, true);
                $this->EnableAction('Control');
            }
        }

        private function processData($jsonString)
        {
            $this->SendDebug('data', $jsonString, 0);
            $data = json_decode($jsonString, true);
            if (isset($data['error'])) {
                // $this->SendDebug('Error', $data['error'], 0);
                return;
            }
            $this->createVariableFromData('status', $data);
            // $this->createVariableFromData('settings', $data);
        }

        private function createVariableFromData($type, $data)
        {
            if (isset($data['data'][$type])) {
                foreach ($data['data'][$type] as $state) {
                    $tempName = $this->getLastSnippet($state['key']);
                    $this->SendDebug('keys', $state['key'], 0);
                    //Skip remote control states and transfer to attributes
                    if (in_array($state['key'], self::ATTRIBUTES)) {
                        $this->WriteAttributeBoolean($tempName, $state['value']);
                        continue;
                    }
                    $value = $state['value'];
                    switch (gettype($state['value'])) {
                        case 'double':
                            $variableType = VARIABLETYPE_FLOAT;
                            break;

                        case 'integer':
                            $variableType = VARIABLETYPE_INTEGER;
                            break;

                        case 'boolean':
                            $variableType = VARIABLETYPE_BOOLEAN;
                            break;

                        default:
                            $variableType = VARIABLETYPE_STRING;
                            break;

                    }
                    $this->SendDebug('Variable', $state['key'], 0);

                    $profileName = str_replace('BSH', 'HomeConnect', $state['key']);
                    $profile = IPS_VariableProfileExists($profileName) ? $profileName : '';
                    $this->MaintainVariable($tempName, $tempName, $variableType, $profile, 0, true);
                    $this->SetValue($tempName, $value);
                }
            }
        }

        private function getLastSnippet($string)
        {
            return substr($string, strrpos($string, '.') + 1, strlen($string) - strrpos($string, '.'));
        }

        private function requestDataFromParent($endpoint, $payload = '')
        {
            $data = [
                'DataID'      => '{41DDAA3B-65F0-B833-36EE-CEB57A80D022}',
                'Endpoint'    => $endpoint
            ];
            if ($payload) {
                $data['Payload'] = $payload;
            }
            return $this->SendDataToParent(json_encode($data));
        }

        private function createAssociations($profileName, $associations)
        {
            foreach ($associations as $association) {
                IPS_SetVariableProfileAssociation($profileName, $association['Value'], $this->Translate($association['Name']), '', -1);
            }
        }

        private function setupSettings()
        {
            $data = json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/settings'), true);
            if (isset($data['data']['settings'])) {
                $availableSettings = json_decode($this->ReadAttributeString('Settings'), true);
                foreach ($data['data']['settings'] as $setting) {
                    $value = $setting['value'];
                    switch (gettype($setting['value'])) {
                        case 'double':
                            $variableType = VARIABLETYPE_FLOAT;
                            break;

                        case 'integer':
                            $variableType = VARIABLETYPE_INTEGER;
                            break;

                        case 'boolean':
                            $variableType = VARIABLETYPE_BOOLEAN;
                            break;

                        default:
                            $variableType = VARIABLETYPE_STRING;
                            break;

                    }

                    $ident = $this->getLastSnippet($setting['key']);

                    //Add setting to available settings
                    if (!isset($availableSettings[$ident])) {
                        $availableSettings[$ident] = ['key' => $setting['key']];
                    }

                    //Create variable accordingly
                    preg_match_all('/(?:^|[A-Z])[a-z]+/', $ident, $matches);
                    $displayName = $this->Translate(implode(' ', $matches[0]));
                    $profileName = str_replace('BSH', 'HomeConnect', $setting['key']);
                    $profile = IPS_VariableProfileExists($profileName) ? $profileName : '';
                    $this->MaintainVariable($ident, $displayName, $variableType, $profile, 0, true);
                    $this->EnableAction($ident);
                    $this->SetValue($ident, $value);
                }

                $this->WriteAttributeString('Settings', json_encode($availableSettings));
            }
        }
    }