<?php

declare(strict_types=1);

if (defined('PHPUNIT_TESTSUITE')) {
    trait TestAPI
    {
        public $selectedProgram = '';

        public function getRequest(string $endpoint)
        {
            preg_match('/homeappliances\/.+\/programs\/selected/', $endpoint, $matches);
            if ($matches) {
                return file_get_contents(__DIR__ . '/../tests/' . $endpoint . '/' . $this->selectedProgram . '.json');
            }
            return file_get_contents(__DIR__ . '/../tests/' . $endpoint . '/response.json');
        }

        public function putRequest(string $endpoint, string $payload)
        {
            preg_match('/homeappliances\/.+\/programs\/selected/', $endpoint, $matches);
            if ($matches) {
                preg_match('/.+\.(.+)/m', json_decode($payload, true)['data']['key'], $matches);
                if ($matches) {
                    $this->selectedProgram = $matches[1];
                }
                return '';
            }
        }

        public function retrieveAccessToken()
        {
        }
    }
} else {
    trait TestAPI
    {
        public function getRequest(string $endpoint)
        {
            return $this->getData($endpoint);
        }

        public function putRequest(string $endpoint, string $payload)
        {
            return $this->putData($endpoint, $payload);
        }

        public function deleteRequest(string $endpoint)
        {
            return $this->deleteData($endpoint);
        }
    }
}