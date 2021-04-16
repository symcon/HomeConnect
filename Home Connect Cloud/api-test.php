<?php

declare(strict_types=1);

if (defined('PHPUNIT_TESTSUITE')) {
    trait TestAPI
    {
        public $selectedProgram = 'Coffee';

        public function GetTestSelected() {
            // return $this->selectedProgram;
            return 'RETURN';
        }

        public function getRequest(string $endpoint)
        {
            switch($endpoint) {
                case 'homeappliances/SIEMENS-TI9575X1DE-68A40E251CAD/programs/selected':
                    return file_get_contents(__DIR__ . '/../tests/' . $endpoint . '/' . $this->selectedProgram . '.json');
                
                default:
                    return file_get_contents(__DIR__ . '/../tests/' . $endpoint . '/response.json');
            }
        }

        public function putRequest(string $endpoint, string $payload)
        {
            switch($endpoint) {
                case 'homeappliances/SIEMENS-TI9575X1DE-68A40E251CAD/programs/selected';
                    preg_match('/.+\.(.+)/m', json_decode($payload, true)['data']['key'], $matches);
                    if ($matches) {
                        $this->selectedProgram = $matches[1];
                    }
            }
            return '';
        }

        public function retrieveAccessToken() {

        }
    }
} else {
    trait TestAPI
    {
        public function getRequest(string $enpoint)
        {
            return $this->getData($enpoint);
        }

        public function putRequest(string $enpoint, string $payload)
        {
            return $this->putData($enpoint, $payload);
        }
    }
}