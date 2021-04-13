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
                        $this->createStates();
                        $this->setupSettings();
                        $this->createPrograms();
                        //If the device is inactive, we cannot retrieve information about the current selected Program
                        if (@IPS_GetObjectIDByIdent('OperationState', $this->InstanceID) && ($this->GetValue('OperationState') != 'BSH.Common.EnumType.OperationState.Inactive')) {
                            $this->updateOptionValues($this->getSelectedProgram()['data']);
                        }
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
                        $this->SendDebug('key', json_encode($item['key']), 0);
                        if (in_array($item['key'], self::EXCLUDE)) {
                            continue;
                        }
                        $ident = $this->getLastSnippet($item['key']);
                        if (in_array($item['key'], self::ATTRIBUTES)) {
                            $this->WriteAttributeBoolean($ident, $item['value']);
                            continue;
                        }

                        preg_match('/.+\.(?P<type>.+)\..+/m', $item['key'], $matches);
                        if ($matches) {
                            switch ($matches['type']) {
                                case 'Status':
                                    $this->createStates(['data' => ['status' => [$item]]]);
                                    $this->SendDebug('Status', 'changes', 0);
                                    break;

                                default:
                                    if (!@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
                                        $this->createVariableByData($item);
                                    }
                                    if ($ident == 'SelectedProgram') {
                                        $this->updateOptionValues($item);
                                    }
                                    $this->SetValue($ident, $item['value']);
                                    break;
                            }
                        }
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
                    $this->SendDebug('Settings', json_encode($availableOptions), 0);
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
            $this->SendDebug('RawPrograms', json_encode($rawPrograms), 0);
            if (!isset($rawPrograms['data']['programs'])) {
                return;
            }
            $programs = $rawPrograms['data']['programs'];
            $this->SendDebug('Programs', json_encode($programs), 0);
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
            $rawOptions = $this->getProgram($program);
            $this->SendDebug('RawOptions', json_encode($rawOptions), 0);
            if (!isset($rawOptions['options'])) {
                return;
            }
            $options = $rawOptions['options'];
            $position = 10;
            foreach ($options as $option) {
                if (in_array($option['key'], self::EXCLUDE)) {
                    continue;
                }
                $this->SendDebug($option['key'], json_encode($option), 0);
                $key = $option['key'];
                preg_match('/.+\.(?P<option>.+)/m', $key, $matches);
                $ident = $matches['option'];

                $profileName = 'HomeConnect.' . $this->ReadPropertyString('DeviceType') . '.Option.' . $ident;
                $this->createVariableFromConstraints($profileName, $option, 'Options');
            }

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

        private function createStates($states = '')
        {
            if (!$states) {
                $data = json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/status'), true);
            } else {
                $data = $states;
            }
            $this->SendDebug('data', json_encode($data), 0);
            if (isset($data['data']['status'])) {
                foreach ($data['data']['status'] as $state) {
                    $ident = $this->getLastSnippet($state['key']);
                    //Skip remote control states and transfer to attributess
                    if (in_array($state['key'], self::ATTRIBUTES)) {
                        $this->WriteAttributeBoolean($ident, $state['value']);
                        continue;
                    }
                    $value = $state['value'];
                    $this->SendDebug('Variable', $state['key'], 0);

                    $profileName = str_replace('BSH', 'HomeConnect', $state['key']);
                    $variableType = $this->getVariableType($value);
                    if (!IPS_VariableProfileExists($profileName)) {
                        IPS_CreateVariableProfile($profileName, $variableType);
                    }
                    switch ($variableType) {
                        case VARIABLETYPE_STRING:
                            $this->addAssociation($profileName, $value, isset($state['displayvalue']) ? $state['displayvalue'] : $this->splitCamelCase($this->getLastSnippet($state['value'])));
                            break;

                        case VARIABLETYPE_INTEGER:
                            if (isset($state['unit'])) {
                                IPS_SetVariableProfileText($profileName, '', ' ' . $state['unit']);
                            }
                            break;

                        default:
                            break;

                    }
                    $variableDisplayName = isset($state['name']) ? $state['name'] : $this->splitCamelCase($ident);
                    $this->MaintainVariable($ident, $variableDisplayName, $variableType, $profileName, 0, true);
                    $this->SetValue($ident, $value);
                    $this->SendDebug($ident, $value, 0);
                }
            }
        }

        private function addAssociation($profileName, $value, $name)
        {
            foreach (IPS_GetVariableProfile($profileName)['Associations'] as $association) {
                if ($association['Value'] == $value) {
                    return;
                }
            }
            IPS_SetVariableProfileAssociation($profileName, $value, $name, '', -1);
            $this->SendDebug('Added Association', $name, 0);
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
            $allSettings = json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/settings'), true);
            if (isset($allSettings['data']['settings'])) {
                $availableSettings = json_decode($this->ReadAttributeString('Settings'), true);
                foreach ($allSettings['data']['settings'] as $setting) {
                    $value = $setting['value'];
                    $ident = $this->getLastSnippet($setting['key']);

                    //Add setting to available settings
                    if (!isset($availableSettings[$ident])) {
                        $availableSettings[$ident] = ['key' => $setting['key']];
                    }
                    $this->SendDebug('Setting', json_encode($setting), 0);
                    //Create variable accordingly
                    $profileName = str_replace('BSH', 'HomeConnect', $setting['key']);
                    $variableType = $this->getVariableType($value);
                    $settingDetails = json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/settings/' . $setting['key']), true);
                    $this->createVariableFromConstraints($profileName, $settingDetails['data'], 'Settings');
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

        private function createVariableFromConstraints($profileName, $data, $attribute)
        {
            $available = json_decode($this->ReadAttributeString($attribute), true);
            $ident = $this->getLastSnippet($data['key']);

            //Add setting to available settings
            if (!isset($available[$ident])) {
                $available[$ident] = ['key' => $data['key']];
            }

            $constraints = $data['constraints'];
            switch ($data['type']) {
                    case 'Int':
                        $variableType = VARIABLETYPE_INTEGER;
                        $available[$ident]['unit'] = $data['unit'];
                        if (!IPS_VariableProfileExists($profileName)) {
                            //Create profile
                            IPS_CreateVariableProfile($profileName, $variableType);
                        }
                        IPS_SetVariableProfileText($profileName, '', ' ' . $data['unit']);
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

            $this->WriteAttributeString($attribute, json_encode($available));
            //Create variable with created profile
            if (!@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
                $displayName = isset($data['name']) ? $data['name'] : $ident;
                $this->MaintainVariable($ident, $displayName, $variableType, $profileName, 0, true);
                $this->EnableAction($ident);
            }
        }
    }