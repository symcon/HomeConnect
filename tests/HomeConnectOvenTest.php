<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/GlobalStubs.php';
include_once __DIR__ . '/stubs/KernelStubs.php';
include_once __DIR__ . '/stubs/ModuleStubs.php';
include_once __DIR__ . '/stubs/ConstantStubs.php';
include_once __DIR__ . '/stubs/MessageStubs.php';

use PHPUnit\Framework\TestCase;

class HomeConnectVarTypeTest extends TestCase
{
    protected function setUp(): void
    {
        //Reset
        IPS\Kernel::reset();

        //Register our core stubs for testing
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/stubs/CoreStubs/library.json');

        //Register io stubs for testing - sse client
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/stubs/IOStubs/library.json');

        //Register our library we need for testing
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');

        $this->ConfiguratorID = IPS_CreateInstance('{CA0E667D-8F28-8DF1-2750-5CF587ECA85A}');

        parent::setUp();
    }

    public function testBaseFunctionality()
    {
        $cloudInterface = IPS\InstanceManager::getInstanceInterface(IPS_GetInstanceListByModuleID('{CE76810D-B685-9BE0-CC04-38B204DEAD5E}')[0]);
        // $cloudInterface->selectedProgram = 'Cotton';
        $oven = IPS_CreateInstance('{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}');
        $this->assertTrue(true);
        $oven = IPS_CreateInstance('{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}');
        IPS_SetProperty($oven, 'HaID', 'SIEMENS-HB676G5S6-68A40E2F702D');
        IPS_SetProperty($oven, 'DeviceType', 'Oven');
        IPS_ApplyChanges($oven);
        $initialVar = IPS_GetObjectIDByIdent('CurrentCavityTemperature', $oven);
        $this->assertEquals(VARIABLETYPE_INTEGER, $this->getValueType($oven));

        $intf = IPS\InstanceManager::getInstanceInterface($oven);
        $intf->ReceiveData($this->generateTestData(50.99));
        $newFloat = IPS_GetObjectIDByIdent('CurrentCavityTemperature', $oven);
        $this->assertNotEquals($newFloat, $initialVar);
        $this->assertEquals(VARIABLETYPE_FLOAT, $this->getValueType($oven));

        $intf->ReceiveData($this->generateTestData(52));
        $this->assertEquals($newFloat, IPS_GetObjectIDByIdent('CurrentCavityTemperature', $oven));
        $this->assertEquals(VARIABLETYPE_FLOAT, $this->getValueType($oven));

        $intf->ReceiveData($this->generateTestData(54.00000000000001));
        $this->assertEquals($newFloat, IPS_GetObjectIDByIdent('CurrentCavityTemperature', $oven));
        $this->assertEquals(VARIABLETYPE_FLOAT, $this->getValueType($oven));
    }

    private function generateTestData($tempValue)
    {
        $data = [
            'Event' => 'STATUS',
            'Data'  => json_encode([
                'items' => [
                    0 => [
                        'timestamp' => 1621510867,
                        'handling'  => 'none',
                        'uri'       => '/api/homeappliances/SIEMENS-HM676G0S6-68A40E544465/status/Cooking.Oven.Status.CurrentCavityTemperature',
                        'key'       => 'Cooking.Oven.Status.CurrentCavityTemperature',
                        'unit'      => '°C',
                        'value'     => $tempValue,
                        'level'     => 'hint',
                    ],
                ],
                'haId' => 'SIEMENS-HM676G0S6-68A40E544465',
            ]),
            'id' => 'SIEMENS-HM676G0S6-68A40E544465',
        ];
        return json_encode($data);
    }

    private function getValueType($id)
    {
        return IPS_GetVariable(IPS_GetObjectIDByIdent('CurrentCavityTemperature', $id))['VariableType'];
    }

    private function displayTemperature($id)
    {
        $temperature = IPS_GetObjectIDByIdent('CurrentCavityTemperature', $id);
        $type = IPS_GetVariable($temperature)['VariableType'];
        echo PHP_EOL . $temperature . ' - ' . $type . PHP_EOL;
    }

    private function displayChildrenValues($id)
    {
        $children = IPS_GetChildrenIDs($id);
        echo PHP_EOL . count($children) . PHP_EOL;
        foreach ($children as $child) {
            if (!IPS_GetObject($child)['ObjectIsHidden']) {
                echo IPS_GetName($child) . ' - ' . GetValueFormatted($child) . PHP_EOL;
            }
        }
    }

    private function getChildrenValues($id)
    {
        $children = IPS_GetChildrenIDs($id);
        $result = [];
        foreach ($children as $child) {
            if (!IPS_GetObject($child)['ObjectIsHidden']) {
                $result[IPS_GetObject($child)['ObjectIdent']] = GetValueFormatted($child);
            }
        }
        return $result;
    }
}