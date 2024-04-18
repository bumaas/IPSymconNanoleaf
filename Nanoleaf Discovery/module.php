<?php

declare(strict_types=1);

class NanoleafDiscovery extends IPSModule
{
    private const MODID_NANOLEAF = '{09AEFA0B-1494-CB8B-A7C0-1982D0D99C7E}';
    private const MODID_SSDP     = '{FFFFA648-B296-E785-96ED-065F7CEE6F29}';
    private const HTTP_PREFIX    = 'http://';

    private const MOCK_FILE = __DIR__ . '/../Testdaten/Mocks';

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();
        $this->RegisterAttributeString('devices', '[]');

        //we will wait until the kernel is ready
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
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


    /**
     * Liefert alle Geräte.
     *
     * @return array configlist all devices
     * @throws \JsonException
     * @throws \JsonException
     */
    private function Get_ListConfiguration(): array
    {
        $DeviceIDList = IPS_GetInstanceListByModuleID(self::MODID_NANOLEAF);
        $devices      = $this->DiscoverDevices();
        $this->SendDebug(__FUNCTION__, 'devices: ' . json_encode($devices, JSON_THROW_ON_ERROR), 0);
        if (!empty($devices)) {
            $config_list  = [];
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

                $config_list[] = [
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
            return $config_list;
        }

        return [];
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
     * @param string $st
     *
     * @return array
     * @throws \JsonException
     * @throws \JsonException
     */
    protected function mSearch(string $st = 'ssdp:all'): array
    {
        $ssdp_id = IPS_GetInstanceListByModuleID(self::MODID_SSDP)[0];

        if (file_exists(self::MOCK_FILE)) {
            $jsonContent = file_get_contents(self::MOCK_FILE);

            $jsondevices = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR)['devices'];
            $devices     = json_decode($jsondevices, true, 512, JSON_THROW_ON_ERROR);
            $this->SendDebug('TEST', sprintf('%s: %s', 'devices', print_r($devices, true)), 0);
        } else {
            $devices = YC_SearchDevices($ssdp_id, $st);
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
        $configData = [
            'elements' => $this->FormElements(),
            'actions'  => [],
            'status'   => $this->FormStatus(),
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
     * @throws \JsonException
     */
    private function FormElements(): array
    {
        return [
            [
                'name'     => 'NanoleafDiscovery',
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
                'values'   => $this->Get_ListConfiguration(),
            ],
        ];
    }


    /**
     * return from status.
     *
     * @return array
     */
    private function FormStatus(): array
    {
        return [
            [
                'code'    => 201,
                'icon'    => 'inactive',
                'caption' => 'Please follow the instructions.',
            ],
        ];
    }
}
