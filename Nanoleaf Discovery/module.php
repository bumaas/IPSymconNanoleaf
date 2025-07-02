<?php

declare(strict_types=1);

class NanoleafDiscovery extends IPSModule
{
    private const MODID_NANOLEAF = '{09AEFA0B-1494-CB8B-A7C0-1982D0D99C7E}';
    private const MODID_SSDP     = '{FFFFA648-B296-E785-96ED-065F7CEE6F29}';
    private const HTTP_PREFIX    = 'http://';

    private const MOCK_FILE = __DIR__ . '/../Testdaten/Mocks';

    private const BUFFER_DEVICES= 'Devices';
    private const BUFFER_SEARCHACTIVE= 'SearchActive';
    private const TIMER_LOADDEVICES = 'LoadDevicesTimer';


    public function Create(): void
    {
        //Never delete this line!
        parent::Create();
        $this->RegisterAttributeString('devices', '[]');

        //we will wait until the kernel is ready
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        $this->SetBuffer(self::BUFFER_DEVICES, json_encode([], JSON_THROW_ON_ERROR));
        $this->SetBuffer(self::BUFFER_SEARCHACTIVE, json_encode(false, JSON_THROW_ON_ERROR));

    }

    /**
     * Interne Funktion des SDK.
     */
    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $devices = $this->DiscoverDevices();
        if (!empty($devices)) {
            $this->WriteAttributeString('devices', json_encode($devices, JSON_THROW_ON_ERROR));
        }

        // Status Error Kategorie zum Import auswählen
        $this->SetStatus(IS_ACTIVE);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        switch ($Message) {
            case IM_CHANGESTATUS:
                if ($Data[0] === IS_ACTIVE) {
                    $this->ApplyChanges();
                }
                break;

            case IPS_KERNELMESSAGE:
                if ($Data[0] === KR_READY) {
                    $this->ApplyChanges();

                    $devices = $this->DiscoverDevices();
                    if (!empty($devices)) {
                        $this->WriteAttributeString('devices', json_encode($devices, JSON_THROW_ON_ERROR));
                    }
                }
                break;

            default:
                break;
        }
    }

    public function RequestAction($Ident, $Value): bool
    {
        $this->SendDebug(__FUNCTION__, sprintf('Ident: %s, Value: %s', $Ident, $Value), 0);

        if ($Ident === 'loadDevices') {
            $this->loadDevices();
        }
        return true;
    }

    /**
     * Liefert alle Geräte.
     *
     * @return void
     * @throws \JsonException
     */
    private function loadDevices(): void
    {
        $DeviceIDList = IPS_GetInstanceListByModuleID(self::MODID_NANOLEAF);
        $devices      = $this->DiscoverDevices();
        $this->SendDebug(__FUNCTION__, 'devices: ' . json_encode($devices, JSON_THROW_ON_ERROR), 0);
        $configurationValues  = [];
        if (!empty($devices)) {
            foreach ($devices as $device) {
                $instanceID = 0;
                $devicename = $device['nl-devicename'];
                $uuid       = $device['uuid'];
                $host       = $device['host'];
                $port       = $device['port'];
                $device_id  = $device['nl-deviceid'];
                foreach ($DeviceIDList as $DeviceID) {
                    if ($host === IPS_GetProperty($DeviceID, 'host') && $port === IPS_GetProperty($DeviceID, 'port')) {
                        $devicename = IPS_GetName($DeviceID);
                        $this->SendDebug(__FUNCTION__, sprintf('device found: %s (%s)', $devicename, $DeviceID), 0);
                        $instanceID = $DeviceID;
                    }
                }

                $configurationValues[] = [
                    'instanceID' => $instanceID,
                    'id'         => $device_id,
                    'name'       => $devicename,
                    'deviceid'   => $device_id,
                    'host'       => $host,
                    'port'       => $port,
                    'uuid'       => $uuid,
                    'create'     => [
                        'moduleID'      => self::MODID_NANOLEAF,
                        'configuration' => [
                            'host'     => $host,
                            'port'     => $port
                        ],
                    ],
                ];
            }
        }

        $configurationValuesEncoded = json_encode($configurationValues, JSON_THROW_ON_ERROR);

        $this->SetBuffer(self::BUFFER_SEARCHACTIVE, json_encode(false, JSON_THROW_ON_ERROR));
        $this->SendDebug(__FUNCTION__, 'SearchActive deactivated', 0);

        $this->SetBuffer(self::BUFFER_DEVICES, $configurationValuesEncoded);
        $this->UpdateFormField('configurator', 'values', $configurationValuesEncoded);
        $this->UpdateFormField('searchingInfo', 'visible', false);

    }

    private function DiscoverDevices(): array
    {
        $this->SendDebug(__FUNCTION__, 'start mSearch ...', 0);
        $devices = $this->mSearch();
        $this->SendDebug(__FUNCTION__, 'found devices: ' . json_encode($devices, JSON_THROW_ON_ERROR), 0);
        return $this->CreateDeviceList($devices);
    }

    private function CreateDeviceList(array $devices): array
    {
        $deviceList = [];
        foreach ($devices as $device) {
            $nanoLeafIp           = $this->GetNanoleafIP($device);
            $obj                  = [];
            $obj['uuid']          = $device['uuid'];
            $obj['nl-devicename'] = $device['nl-devicename'];
            $obj['nl-deviceid']   = $device['nl-devicename'];
            $obj['host']          = $nanoLeafIp['ip'];
            $obj['port']          = $nanoLeafIp['port'];
            $deviceList[]         = $obj;
        }

        $this->SendDebug(__FUNCTION__, json_encode($deviceList, JSON_THROW_ON_ERROR), 0);

        return $deviceList;
    }

    /** Search Aurora nanoleaf_aurora:light / Canvas nanoleaf:nl29
     *
     * @return array
     * @throws \JsonException
     */
    private function mSearch(): array
    {
        $ssdp_id = IPS_GetInstanceListByModuleID(self::MODID_SSDP)[0];

        if (file_exists(self::MOCK_FILE)) {
            $jsonContent = file_get_contents(self::MOCK_FILE);

            $jsondevices = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR)['devices'];
            $devices     = json_decode($jsondevices, true, 512, JSON_THROW_ON_ERROR);
            $this->SendDebug('TEST', sprintf('%s: %s', 'devices', print_r($devices, true)), 0);
        } else {
            $devices = YC_SearchDevices($ssdp_id, 'ssdp:all');
        }

        $this->SendDebug(__FUNCTION__, 'devices: ' . json_encode($devices, JSON_THROW_ON_ERROR), 0);

        $nanoleaf_response = [];
        $i                 = 0;
        foreach ($devices as $device) {
            if (isset($device['ST']) && in_array($device['ST'], ['nanoleaf_aurora:light', 'nanoleaf:nl29', 'nanoleaf:nl42'])) {
                $nanoleaf_response[$i]['location'] = $device['Location'];
                foreach ($device['Fields'] as $field) {
                    if (stripos($field, 'nl-deviceid') === 0) {
                        $nanoleaf_response[$i]['nl-deviceid'] = str_ireplace('nl-deviceid: ', '', $field);
                    }
                    if (stripos($field, 'nl-devicename:') === 0) {
                        $nanoleaf_response[$i]['nl-devicename'] = str_ireplace('nl-devicename: ', '', $field);
                    }
                }
                $nanoleaf_response[$i]['uuid'] = str_ireplace('uuid:', '', $device['USN']);
                $i++;
            }
        }
        return $nanoleaf_response;
    }

    private function GetNanoleafIP($result): array
    {
        $location = $result['location'];
        $location = str_ireplace(self::HTTP_PREFIX, '', $location);
        $location = explode(':', $location);
        return ['ip' => $location[0], 'port' => $location[1]];
    }

    /***********************************************************
     * Configuration Form
     ***********************************************************/

    /**
     * build configuration form.
     *
     * @return string
     * @throws \JsonException
     * @throws \JsonException
     */
    public function GetConfigurationForm(): string
    {
        $this->SendDebug(__FUNCTION__, 'Start', 0);
        $this->SendDebug(__FUNCTION__, 'SearchActive: ' . $this->GetBuffer(self::BUFFER_SEARCHACTIVE), 0);

        // Do not start a new search, if a search is currently active
        if (!json_decode($this->GetBuffer(self::BUFFER_SEARCHACTIVE), false, 512, JSON_THROW_ON_ERROR)) {
            $this->SetBuffer(self::BUFFER_SEARCHACTIVE, json_encode(true, JSON_THROW_ON_ERROR));

            // Start device search in a timer, not prolonging the execution of GetConfigurationForm
            $this->SendDebug(__FUNCTION__, 'RegisterOnceTimer', 0);
            $this->RegisterOnceTimer(self::TIMER_LOADDEVICES, 'IPS_RequestAction($_IPS["TARGET"], "loadDevices", "");');
        }

        $configData = [
            'elements' => [],
            'actions'  => $this->FormActions(),
            'status'   => [],
        ];
        $this->SendDebug(__FUNCTION__, 'configData: ' . json_encode($configData, JSON_THROW_ON_ERROR), 0);
        $this->SendDebug(__FUNCTION__, 'error: ' . json_last_error_msg(), 0);

        return json_encode($configData, JSON_THROW_ON_ERROR);
    }

    /**
     * return form configurations on configuration step.
     *
     * @return array
     * @throws \JsonException
     */
    private function FormActions(): array
    {
        $devices = json_decode($this->GetBuffer(self::BUFFER_DEVICES), false, 512, JSON_THROW_ON_ERROR);

        return [
            [
                'name'          => 'searchingInfo',
                'type'          => 'ProgressBar',
                'caption'       => 'The configurator is currently searching for devices. This could take a while...',
                'indeterminate' => true,
                'visible'       => count($devices) === 0
            ],
            [
                'name'     => 'configurator',
                'type'     => 'Configurator',
                'rowCount' => 20,
                'add'      => false,
                'delete'   => true,
                'sort'     => [
                    'column'    => 'name',
                    'direction' => 'ascending',
                ],
                'columns'  => [
                    [
                        'label'   => 'ID',
                        'name'    => 'id',
                        'width'   => '200px',
                        'visible' => false,
                    ],
                    [
                        'label' => 'device name',
                        'name'  => 'name',
                        'width' => 'auto',
                    ],
                    [
                        'label'   => 'device id',
                        'name'    => 'deviceid',
                        'width'   => '250px',
                        'visible' => true,
                    ],
                    [
                        'label' => 'IP address',
                        'name'  => 'host',
                        'width' => '140px',
                    ],
                    [
                        'label' => 'port',
                        'name'  => 'port',
                        'width' => '80px',
                    ],
                    [
                        'label' => 'uuid',
                        'name'  => 'uuid',
                        'width' => '350px',
                    ],
                ],
                'values'   => $devices
            ],
        ];
    }

}
