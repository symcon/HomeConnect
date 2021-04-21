<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/GlobalStubs.php';
include_once __DIR__ . '/stubs/KernelStubs.php';
include_once __DIR__ . '/stubs/ModuleStubs.php';
include_once __DIR__ . '/stubs/ConstantStubs.php';
include_once __DIR__ . '/stubs/MessageStubs.php';

use PHPUnit\Framework\TestCase;

class HomeConnectCoffeeMakerBaseTest extends TestCase
{
    const COFFEE = [
        'OperationState'         => 'Bereit',
        'DoorState'              => 'Geschlossen',
        'PowerState'             => 'An',
        'SelectedProgram'        => 'Caffe Crema',
        'OptionCoffeeTemperature'=> '90°C',
        'OptionBeanAmount'       => 'Stark +',
        'OptionFillQuantity'     => '120 ml',
        'Control'                => 'Start'
    ];

    const ESPRESSO = [
        'OperationState'          => 'Bereit',
        'DoorState'               => 'Geschlossen',
        'PowerState'              => 'An',
        'SelectedProgram'         => 'Espresso',
        'OptionCoffeeTemperature' => '92°C',
        'OptionBeanAmount'        => 'Sehr stark',
        'OptionFillQuantity'      => '40 ml',
        'Control'                 => 'Start'
    ];

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
        $cloudInterface->selectedProgram = 'Coffee';
        $coffeMaker = IPS_CreateInstance('{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}');
        $intf = IPS\InstanceManager::getInstanceInterface($coffeMaker);
        $coffeMaker = IPS_CreateInstance('{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}');
        IPS_SetProperty($coffeMaker, 'HaID', 'SIEMENS-TI9575X1DE-68A40E251CAD');
        IPS_SetProperty($coffeMaker, 'DeviceType', 'CoffeMaker');
        IPS_ApplyChanges($coffeMaker);
        $intf = IPS\InstanceManager::getInstanceInterface($coffeMaker);
        $this->assertEquals(self::COFFEE, $this->getChildrenValues($coffeMaker));
        $intf->RequestAction('SelectedProgram', 'ConsumerProducts.CoffeeMaker.Program.Beverage.Espresso');
        $this->assertEquals(self::ESPRESSO, $this->getChildrenValues($coffeMaker));
    }

    public function testBaseFunctionalityEvents()
    {
        $cloudInterface = IPS\InstanceManager::getInstanceInterface(IPS_GetInstanceListByModuleID('{CE76810D-B685-9BE0-CC04-38B204DEAD5E}')[0]);
        $cloudInterface->selectedProgram = 'Coffee';
        $coffeMaker = IPS_CreateInstance('{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}');
        IPS_SetProperty($coffeMaker, 'HaID', 'SIEMENS-TI9575X1DE-68A40E251CAD');
        IPS_SetProperty($coffeMaker, 'DeviceType', 'CoffeMaker');
        IPS_ApplyChanges($coffeMaker);
        $intf = IPS\InstanceManager::getInstanceInterface($coffeMaker);
        $this->assertEquals(self::COFFEE, $this->getChildrenValues($coffeMaker));
        $cloudInterface->selectedProgram = 'Espresso';
        $intf->ReceiveData(json_encode(['Buffer' => '3b7
        data: {"items":[{"timestamp":1618480503,"handling":"none","uri":"/api/homeappliances/SIEMENS-TI9575X1DE-68A40E251CAD/programs/selected","key":"BSH.Common.Root.SelectedProgram","value":"ConsumerProducts.CoffeeMaker.Program.Beverage.Espresso","level":"hint"},{"timestamp":1618480503,"handling":"none","uri":"/api/homeappliances/SIEMENS-TI9575X1DE-68A40E251CAD/programs/selected/options/ConsumerProducts.CoffeeMaker.Option.CoffeeTemperature","key":"ConsumerProducts.CoffeeMaker.Option.CoffeeTemperature","value":"ConsumerProducts.CoffeeMaker.EnumType.CoffeeTemperature.92C","level":"hint"},{"timestamp":1618480503,"handling":"none","uri":"/api/homeappliances/SIEMENS-TI9575X1DE-68A40E251CAD/programs/selected/options/ConsumerProducts.CoffeeMaker.Option.FillQuantity","key":"ConsumerProducts.CoffeeMaker.Option.FillQuantity","unit":"ml","value":40,"level":"hint"}],"haId":"SIEMENS-TI9575X1DE-68A40E251CAD"}
        event: NOTIFY
        id: SIEMENS-TI9575X1DE-68A40E251CAD']));
        $this->assertEquals(self::ESPRESSO, $this->getChildrenValues($coffeMaker));
    }

    private function displayChildrenValues($id)
    {
        $children = IPS_GetChildrenIDs($id);
        echo PHP_EOL . count($children) . PHP_EOL;
        foreach ($children as $child) {
            echo IPS_GetName($child) . ' - ' . GetValueFormatted($child) . PHP_EOL;
        }
    }

    private function getChildrenValues($id)
    {
        $children = IPS_GetChildrenIDs($id);
        $result = [];
        foreach ($children as $child) {
            $result[IPS_GetObject($child)['ObjectIdent']] = GetValueFormatted($child);
        }
        return $result;
    }

    private function buildEvent($event)
    {
        $string = '';
        foreach ($event as $name => $value) {
            $string .= $name . ': ' . $value . "\n";
        }
        return $string;
    }
}