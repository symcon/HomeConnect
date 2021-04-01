<?php

declare(strict_types=1);
    class HomeConnectDevice extends IPSModule
    {
        const ATTRIBUTES = [
            'BSH.Common.Status.RemoteControlStartAllowed',
            'BSH.Common.Status.RemoteControlActive',
            'BSH.Common.Status.LocalControlActive',
            'BSH.Common.Root.ActiveProgram'
        ];

        const EXCLUDE = [
            //StartInRelative is not selectable
            'BSH.Common.Option.StartInRelative',
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
            $this->RegisterAttributeBoolean('LocalControlActive', false);
            $this->RegisterAttributeBoolean('ActiveProgram', false);

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
                    ['Value' => 'BSH.Common.EnumType.OperationState.Aborting', 'Name' => 'Aborting'],
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

            //Options
            if (!IPS_VariableProfileExists('HomeConnect.Common.Option.ProgramProgress')) {
                IPS_CreateVariableProfile('HomeConnect.Common.Option.ProgramProgress', VARIABLETYPE_INTEGER);
                IPS_SetVariableProfileText('HomeConnect.Common.Option.ProgramProgress', '', ' ' . '%');
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
                    if ($this->ReadPropertyString('HaID')) {
                        $this->processData($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/status'));
                        $this->setupSettings();
                        $this->createPrograms();
                        $this->updateOptionValues($this->getSelectedProgram()['data']);
                    }
                }
            }

            $this->SetReceiveDataFilter('.*' . $this->ReadPropertyString('HaID') . '.*');
        }

        public function ReceiveData($String)
        {
            $rawData = explode("\n", utf8_decode(json_decode($String, true)['Buffer']));
            $cleanData = [];
            foreach ($rawData as $entry) {
                preg_match('/(.*?):[ ]*(.*)/', $entry, $matches);
                if ($matches) {
                    $cleanData[$matches[1]] = $matches[2];
                }
            }
            switch ($cleanData['event']) {
                case 'STATUS':
                case 'NOTIFY':
                    $items = json_decode($cleanData['data'], true)['items'];
                    $this->SendDebug($cleanData['event'], json_encode($items), 0);
                    foreach ($items as $item) {
                        if (in_array($item['key'], self::EXCLUDE)) {
                            continue;
                        }
                        $ident = $this->getLastSnippet($item['key']);
                        if (in_array($item['key'], self::ATTRIBUTES)) {
                            $this->WriteAttributeBoolean($ident, $item['value']);
                            continue;
                        }

                        if (!@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
                            $this->createVariableByData($item);
                        }
                        if ($ident == 'SelectedProgram') {
                            $this->updateOptionValues($item);
                        }
                        $this->SetValue($ident, $item['value']);
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
                    $this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/selected', json_encode($payload));
                    $this->updateOptionValues($this->getSelectedProgram()['data']);
                    break;

                case 'Control':
                    switch ($Value) {
                        case 'Start':
                            if (!$this->ReadAttributeBoolean('RemoteControlStartAllowed')) {
                                echo $this->Translate('Remote start not allowed');
                                break;
                            }

                            $payload = [
                                'data' => [
                                    'key'     => $this->GetValue('SelectedProgram'),
                                    'options' => $this->createOptionPayload()
                                ]
                            ];
                            $this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/active', json_encode($payload));
                            break;
                    }
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
            if (!IPS_VariableProfileExists($profileName)) {
                IPS_CreateVariableProfile($profileName, VARIABLETYPE_STRING);
            } else {
                //Clear profile if it exists
                foreach (IPS_GetVariableProfile($profileName)['Associations'] as $association) {
                    IPS_SetVariableProfileAssociation($profileName, $association['Value'], '', '', 0);
                }
            }
            foreach ($programs as $program) {
                preg_match('/(?P<program>.+)\.(?P<value>.+)/m', $program['key'], $matches);
                $displayName = isset($program['name']) ? $program['name'] : $matches['value'];
                IPS_SetVariableProfileAssociation($profileName, $program['key'], $displayName, '', -1);
            }
            $ident = 'SelectedProgram';
            $this->MaintainVariable($ident, $this->Translate('Program'), VARIABLETYPE_STRING, $profileName, 1, true);
            $this->EnableAction($ident);
        }

        private function createOptionPayload()
        {
            $availableOptions = json_decode($this->ReadAttributeString('Options'), true);
            $this->SendDebug('options', json_encode($availableOptions), 0);
            $optionsPayload = [];
            foreach ($availableOptions as $ident => $option) {
                $optionsPayload[] = [
                    'key'   => $option['key'],
                    'value' => $this->GetValue($ident)
                ];
            }
            return $optionsPayload;
        }

        private function updateOptionVariables($program)
        {
            $rawOptions = json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/available/' . $program), true);
            $this->SendDebug('RawOptions', json_encode($rawOptions), 0);
            if (!isset($rawOptions['data']['options'])) {
                return;
            }
            $options = $rawOptions['data']['options'];
            $availableOptions = json_decode($this->ReadAttributeString('Options'), true);
            $position = 10;
            foreach ($options as $option) {
                if (in_array($option['key'], self::EXCLUDE)) {
                    continue;
                }
                $this->SendDebug($option['key'], json_encode($option), 0);
                $key = $option['key'];
                preg_match('/.+\.(?P<option>.+)/m', $key, $matches);
                $ident = $matches['option'];
                if (!isset($availableOptions[$ident])) {
                    $availableOptions[$ident] = ['key' => $key];
                }
                $profileName = 'HomeConnect.' . $this->ReadPropertyString('DeviceType') . '.Option.' . $ident;
                $constraints = $option['constraints'];
                switch ($option['type']) {
                    case 'Int':
                        $variableType = VARIABLETYPE_INTEGER;
                        $availableOptions[$ident]['unit'] = $option['unit'];
                        if (!IPS_VariableProfileExists($profileName)) {
                            //Create profile
                            IPS_CreateVariableProfile($profileName, $variableType);
                        }
                        IPS_SetVariableProfileText($profileName, '', ' ' . $option['unit']);
                        IPS_SetVariableProfileValues($profileName, $constraints['min'], $constraints['max'], isset($constraints['stepsize']) ? $constraints['stepsize'] : 1);
                        break;

                    default:
                        $variableType = VARIABLETYPE_STRING;
                        if (!IPS_VariableProfileExists($profileName)) {
                            //Create profile
                            IPS_CreateVariableProfile($profileName, $variableType);
                        }
                        //Add potential new options
                        $newAssociations = [];
                        for ($i = 0; $i < count($constraints['allowedvalues']); $i++) {
                            $displayName = isset($constraints['displayvalues'][$i]) ? $constraints['displayvalues'][$i] : $this->getLastSnippet($constraints['allowedvalues'][$i]);
                            $newAssociations[$constraints['allowedvalues'][$i]] = $displayName;
                        }

                        //Get current options from profile
                        $oldAssociations = [];
                        foreach (IPS_GetVariableProfile($profileName)['Associations'] as $association) {
                            $oldAssociations[$association['Value']] = $association['Name'];
                        }
                        //Only refresh the profile if changes occured
                        $diffold = array_diff_assoc($oldAssociations, $newAssociations);
                        $diffnew = array_diff_assoc($newAssociations, $oldAssociations);
                        if ($diffold || $diffnew) {
                            //Clear profile if it exists
                            foreach (IPS_GetVariableProfile($profileName)['Associations'] as $association) {
                                IPS_SetVariableProfileAssociation($profileName, $association['Value'], '', '', -1);
                            }
                            foreach ($newAssociations as $value => $name) {
                                IPS_SetVariableProfileAssociation($profileName, $value, $name, '', -1);
                            }
                        }
                        break;
                }

                //Create variable if not exists
                if (!@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
                    $displayName = isset($option['name']) ? $option['name'] : $ident;
                    $this->MaintainVariable($ident, $displayName, $variableType, $profileName, $position++, true);
                    $this->EnableAction($ident);
                }
            }

            $this->SendDebug('AvailableOptions', json_encode($availableOptions), 0);
            $this->WriteAttributeString('Options', json_encode($availableOptions));
            if (!IPS_VariableProfileExists('HomeConnect.Control')) {
                IPS_CreateVariableProfile('HomeConnect.Control', VARIABLETYPE_STRING);
                IPS_SetVariableProfileAssociation('HomeConnect.Control', 'Start', $this->Translate('Start'), '', -1);
            }
            if (!@IPS_GetObjectIDByIdent('Control', $this->InstanceID)) {
                $this->MaintainVariable('Control', $this->Translate('Control'), VARIABLETYPE_STRING, 'HomeConnect.Control', $position, true);
                $this->EnableAction('Control');
            }
        }

        private function getSelectedProgram()
        {
            return json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/selected'), true);
        }

        private function getProgram($key)
        {
            $data = json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/available/' . $key), true)['data'];
            return $data;
        }

        private function getOption($key)
        {
            if (in_array($key, self::EXCLUDE)) {
                return false;
            }
            $data = json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/selected/options/' . $key), true)['data'];
            return $data;
        }
        private function updateOptionValues($program)
        {
            $this->SetValue('SelectedProgram', $program['key']);
            $this->updateOptionVariables($program['key']);
            foreach ($program['options'] as $option) {
                $ident = $this->getLastSnippet($option['key']);
                if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
                    $this->SendDebug('Value', $option['value'], 0);
                    $this->SetValue($ident, $option['value']);
                }
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
                    $ident = $this->getLastSnippet($state['key']);
                    //Skip remote control states and transfer to attributess
                    if (in_array($state['key'], self::ATTRIBUTES)) {
                        $this->WriteAttributeBoolean($ident, $state['value']);
                        continue;
                    }
                    $value = $state['value'];
                    $this->SendDebug('Variable', $state['key'], 0);

                    $profileName = str_replace('BSH', 'HomeConnect', $state['key']);
                    $profile = IPS_VariableProfileExists($profileName) ? $profileName : '';
                    $displayName = isset($state['name']) ? $state['name'] : $this->Translate($this->splitCamelCase($ident));
                    $this->MaintainVariable($ident, $displayName, $this->getVariableType($state['value']), $profile, 0, true);
                    $this->SetValue($ident, $value);
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
            $response = $this->SendDataToParent(json_encode($data));
            $errorDetector = json_decode($response, true);
            if (isset($errorDetector['error'])) {
                $this->SendDebug('ErrorPayload', $payload, 0);
                $this->SendDebug('ErrorEndpoint', $endpoint, 0);
                echo $errorDetector['error']['description'];
            }
            $this->SendDebug('requestetData', $response, 0);
            return $response;
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
                    $ident = $this->getLastSnippet($setting['key']);

                    //Add setting to available settings
                    if (!isset($availableSettings[$ident])) {
                        $availableSettings[$ident] = ['key' => $setting['key']];
                    }

                    //Create variable accordingly
                    $profileName = str_replace('BSH', 'HomeConnect', $setting['key']);
                    $profile = IPS_VariableProfileExists($profileName) ? $profileName : '';
                    $this->MaintainVariable($ident, $this->splitCamelCase($ident), $this->getVariableType($setting['value']), $profile, 0, true);
                    $this->EnableAction($ident);
                    $this->SetValue($ident, $value);
                }

                $this->WriteAttributeString('Settings', json_encode($availableSettings));
            }
        }

        private function createVariableByData($data)
        {
            $ident = $this->getLastSnippet($data['key']);
            $displayName = isset($data['name']) ? $data['name'] : $ident;
            $profileName = str_replace('BSH', 'HomeConnect', $data['key']);
            $profile = IPS_VariableProfileExists($profileName) ? $profileName : '';
            $this->MaintainVariable($ident, $displayName, $this->getVariableType($data['value']), $profile, 0, true);
        }

        private function getVariableType($value)
        {
            switch (gettype($value)) {
                case 'double':
                    return VARIABLETYPE_FLOAT;

                case 'integer':
                    return VARIABLETYPE_INTEGER;

                case 'boolean':
                    return VARIABLETYPE_BOOLEAN;

                default:
                    return VARIABLETYPE_STRING;
            }
        }

        private function splitCamelCase($string)
        {
            preg_match_all('/(?:^|[A-Z])[a-z]+/', $string, $matches);
            return $this->Translate(implode(' ', $matches[0]));
        }
    }