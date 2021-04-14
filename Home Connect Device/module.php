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

        const INCLUDE = [
            'BSH.Common.Option.ElapsedProgramTime',
            'BSH.Common.Option.RemainingProgramTime',
            'BSH.Common.Option.ProgramProgress',
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
            if (!IPS_VariableProfileExists('HomeConnect.Common.Option.ElapsedProgramTime')) {
                IPS_CreateVariableProfile('HomeConnect.Common.Option.ElapsedProgramTime', VARIABLETYPE_INTEGER);
                IPS_SetVariableProfileText('HomeConnect.Common.Option.ElapsedProgramTime', '', ' ' . $this->Translate('Seconds'));
            }
            if (!IPS_VariableProfileExists('HomeConnect.Common.Option.RemainingProgramTime')) {
                IPS_CreateVariableProfile('HomeConnect.Common.Option.RemainingProgramTime', VARIABLETYPE_INTEGER);
                IPS_SetVariableProfileText('HomeConnect.Common.Option.RemainingProgramTime', '', ' ' . $this->Translate('Seconds'));
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
                        // $this->createPrograms();
                        //If the device is inactive, we cannot retrieve information about the current selected Program
                        // if (@IPS_GetObjectIDByIdent('OperationState', $this->InstanceID) && ($this->GetValue('OperationState') != 'BSH.Common.EnumType.OperationState.Inactive')) {
                        //     $this->updateOptionValues($this->getSelectedProgram()['data']);
                        // }
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
            $items = json_decode($cleanData['data'], true)['items'];
            $this->SendDebug($cleanData['event'], json_encode($items), 0);
            switch ($cleanData['event']) {
                case 'STATUS':
                    foreach ($items as $item) {
                        $ident = $this->getLastSnippet($item['key']);
                        switch ($item['key']) {
                            case 'BSH.Common.Status.RemoteControlStartAllowed':
                            case 'BSH.Common.Status.RemoteControlActive':
                            case 'BSH.Common.Status.LocalControlActive':
                                $this->WriteAttributeBoolean($ident, $item['value']);
                                break;
                            case 'BSH.Common.Status.OperationState':
                                switch ($item['value']) {
                                    case 'BSH.Common.EnumType.OperationState.Finished':
                                        $this->resetProgress();
                                        break;
                                }
                                // FIXME: No break. Please add proper comment if intentional
                            default:
                                $this->createStates(['data' => ['status' => [$item]]]);
                                $this->SetValue($ident, $item['value']);

                        }
                    }
                    break;

                case 'NOTIFY':
                    foreach ($items as $item) {
                        $ident = $this->getLastSnippet($item['key']);
                        if (!@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
                            $this->createVariableByData($item);
                        }
                        IPS_SetHidden($this->GetIDForIdent($ident), false);
                        $this->SetValue($ident, $item['value']);
                    }
                    break;
            }
        }

        public function RequestAction($Ident, $Value)
        {
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
            $this->SetValue($Ident, $Value);
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
                        case VARIABLETYPE_FLOAT:
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

        private function setupSettings()
        {
            $allSettings = json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/settings'), true);
            if (isset($allSettings['data']['settings'])) {
                $availableSettings = json_decode($this->ReadAttributeString('Settings'), true);
                $position = 0;
                foreach ($allSettings['data']['settings'] as $setting) {
                    $value = $setting['value'];
                    $ident = $this->getLastSnippet($setting['key']);

                    //Add setting to available settings
                    if (!isset($availableSettings[$ident])) {
                        $availableSettings[$ident] = ['key' => $setting['key']];
                    }
                    $this->SendDebug('Setting', json_encode($setting), 0);
                    //Create variable accordingly
                    $profileName = str_replace('BSH', 'HomeConnect.' . $this->ReadPropertyString('HaID'), $setting['key']);
                    $variableType = $this->getVariableType($value);
                    $settingDetails = json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/settings/' . $setting['key']), true);
                    $this->createVariableFromConstraints($profileName, $settingDetails['data'], 'Settings', $position);
                    $position++;
                    $this->SetValue($ident, $value);
                }

                $this->WriteAttributeString('Settings', json_encode($availableSettings));
            }
        }

        private function createVariableByData($data)
        {
            if (!in_array($data['key'], self::INCLUDE)) {
                return;
            }
            $ident = $this->getLastSnippet($data['key']);
            $displayName = isset($data['name']) ? $data['name'] : $this->Translate($this->splitCamelCase($ident));
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

        private function createVariableFromConstraints($profileName, $data, $attribute, $position)
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
                    break;

                case 'Double':
                    $variableType = VARIABLETYPE_FLOAT;
                    break;

                case 'Boolean':
                    $variableType = VARIABLETYPE_FLOAT;
                    break;

                default:
                    $variableType = VARIABLETYPE_STRING;
                    break;
            }

            switch ($variableType) {
                case VARIABLETYPE_INTEGER:
                case VARIABLETYPE_FLOAT:
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
                $this->MaintainVariable($ident, $displayName, $variableType, $profileName, $position, true);
                if ((isset($constraints['access']) && $constraints['access'] == 'readWrite') || !isset($constraints['access'])) {
                    $this->EnableAction($ident);
                }
            }
        }

        private function resetProgress()
        {
            foreach (self::INCLUDE as $key) {
                $ident = $this->getLastSnippet($key);
                if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
                    IPS_SetHidden($this->GetIDForIdent($ident), true);
                }
            }
            $this->SendDebug('FINISHED', 'Hidden', 0);
        }
    }