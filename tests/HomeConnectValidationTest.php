<?php

declare(strict_types=1);
include_once __DIR__ . '/stubs/Validator.php';
class HomeConnectValidationTest extends TestCaseSymconValidation
{
    public function testValidateHomeConnect(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }
    public function testValidateHomeConnectCloudModule(): void
    {
        $this->validateModule(__DIR__ . '/../Home Connect Cloud');
    }

    public function testValidateHomeConnectConfiguratorModule(): void
    {
        $this->validateModule(__DIR__ . '/../Home Connect Configurator');
    }
    public function testValidateHomeConnectDeviceModule(): void
    {
        $this->validateModule(__DIR__ . '/../Home Connect Device');
    }
}