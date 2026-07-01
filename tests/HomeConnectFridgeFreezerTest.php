<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/GlobalStubs.php';
include_once __DIR__ . '/stubs/KernelStubs.php';
include_once __DIR__ . '/stubs/ModuleStubs.php';
include_once __DIR__ . '/stubs/ConstantStubs.php';
include_once __DIR__ . '/stubs/MessageStubs.php';

use PHPUnit\Framework\TestCase;

class HomeConnectFridgeFreezerTest extends TestCase
{
    private const DEVICE_GUID = '{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}';
    private const FRIDGE_HAID = 'BOSCH-KAD92HBFP-68A40E25B8F3';

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

        //Reset the request counter so each test starts from a known state
        HomeConnectCloud::$requestCount = 0;

        parent::setUp();
    }

    /**
     * Regression test for the rate-limit retry loop.
     *
     * A FridgeFreezer has no OperationState in /status and /programs returns an
     * UnsupportedOperation error. With the old code needsInitialization() returned
     * true on every IM_CHANGESTATUS (because the OperationState variable never
     * existed), so each parent status flap re-ran a full InitializeDevice() and
     * burned through the API quota. This test asserts that after one successful
     * initialization, repeated IM_CHANGESTATUS events trigger no further API calls.
     */
    public function testNoReInitLoopWhenOperationStateMissing()
    {
        $fridge = IPS_CreateInstance(self::DEVICE_GUID);
        $parent = IPS_GetInstance($fridge)['ConnectionID'];
        IPS\InstanceManager::setStatus($parent, IS_ACTIVE);

        IPS_SetProperty($fridge, 'HaID', self::FRIDGE_HAID);
        IPS_SetProperty($fridge, 'DeviceType', 'FridgeFreezer');
        IPS_ApplyChanges($fridge);

        //The first initialization ran and created variables, even though /programs failed.
        $this->assertGreaterThan(0, HomeConnectCloud::$requestCount, 'Initial setup should perform API calls');
        $this->assertNotFalse(@IPS_GetObjectIDByIdent('DoorState', $fridge), 'DoorState should be created from /status');
        //A FridgeFreezer has no OperationState - this is exactly what triggered the old loop.
        $this->assertFalse(@IPS_GetObjectIDByIdent('OperationState', $fridge), 'FridgeFreezer has no OperationState');
        $this->assertEquals(IS_ACTIVE, IPS_GetInstance($fridge)['InstanceStatus']);

        //Now simulate repeated parent status flaps (as seen during reconnect / rate limiting).
        HomeConnectCloud::$requestCount = 0;
        $intf = IPS\InstanceManager::getInstanceInterface($fridge);
        for ($i = 0; $i < 5; $i++) {
            $intf->MessageSink(0, $parent, IM_CHANGESTATUS, [IS_ACTIVE]);
        }

        //No further API call must happen - the device is already initialized.
        $this->assertSame(0, HomeConnectCloud::$requestCount, 'Repeated IM_CHANGESTATUS must not re-initialize the device');
    }

    /**
     * After a config change (HaID/DeviceType) the initialization signature changes,
     * so a single re-initialization is expected - but still no endless loop.
     */
    public function testReInitializesOnceAfterSignatureChange()
    {
        $fridge = IPS_CreateInstance(self::DEVICE_GUID);
        $parent = IPS_GetInstance($fridge)['ConnectionID'];
        IPS\InstanceManager::setStatus($parent, IS_ACTIVE);

        IPS_SetProperty($fridge, 'HaID', self::FRIDGE_HAID);
        IPS_SetProperty($fridge, 'DeviceType', 'FridgeFreezer');
        IPS_ApplyChanges($fridge);

        $this->assertGreaterThan(0, HomeConnectCloud::$requestCount);

        //Re-applying without changes must not initialize again.
        HomeConnectCloud::$requestCount = 0;
        IPS_ApplyChanges($fridge);
        $this->assertSame(0, HomeConnectCloud::$requestCount, 'ApplyChanges without changes must not re-initialize');
    }
}
