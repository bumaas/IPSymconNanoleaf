<?php

declare(strict_types=1);

class NanoleafDiscovery extends IPSModule
{
    private const MODID_NANOLEAF = '{09AEFA0B-1494-CB8B-A7C0-1982D0D99C7E}';

    public function Create()
    {
        //Never delete this line!
        parent::Create();
        $this->RegisterAttributeString('devices', '[]');

        //we will wait until the kernel is ready
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterTimer('Discovery', 0, 'NanoleafDiscovery_Discover($_IPS[\'TARGET\']);');
    }

    /**
     * Interne Funktion des SDK.
     */
    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $devices = $this->DiscoverDevices();
        if (!empty($devices)) {
            $this->WriteAttributeString('devices', json_encode($devices));
        }
        $this->SetTimerInterval('Discovery', 300000);

        // Status Error Kategorie zum Import auswählen
        $this->SetStatus(102);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
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
                }
                break;
            case IPS_KERNELSTARTED:
                $devices = $this->DiscoverDevices();
                if (!empty($devices)) {
                    $this->WriteAttributeString('devices', json_encode($devices));
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
     */
    private function Get_ListConfiguration(): array
    {
        $config_list = [];
        $DeviceIDList = IPS_GetInstanceListByModuleID(self::MODID_NANOLEAF);
        $devices = $this->DiscoverDevices();
        $this->SendDebug('Nanoleaf discovered devices', json_encode($devices), 0);
        if (!empty($devices)) {
            foreach ($devices as $device) {
                $instanceID = 0;
                $devicename = $device['nl-devicename'];
                $uuid = $device['uuid'];
                $host = $device['host'];
                $port = $device['port'];
                $device_id = $device['nl-deviceid'];
                foreach ($DeviceIDList as $DeviceID) {
                    if ($uuid === IPS_GetProperty($DeviceID, 'uuid')) {
                        $devicename = IPS_GetName($DeviceID);
                        $this->SendDebug('Broadlink Config', 'device found: ' . utf8_decode($devicename) . ' (' . $DeviceID . ')', 0);
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
                            'name'     => $devicename,
                            'deviceid' => $device_id,
                            'host'     => $host,
                            'port'     => $port,
                            'uuid'     => $uuid,
                        ],
                    ],
                ];
            }
        }

        return $config_list;
    }

    private function DiscoverDevices(): array
    {
        $result = [];
        $devices = $this->mSearch();
        $this->SendDebug('Discover Response:', json_encode($devices), 0);
        return $this->CreateDeviceList($result, $devices);
    }

    private function CreateDeviceList($result, $devices)
    {
        foreach ($devices as $device) {
            $obj = [];

            $obj['uuid'] = $device['uuid'];
            $this->SendDebug('uuid:', $obj['uuid'], 0);
            $obj['nl-devicename'] = $device['nl-devicename'];
            $this->SendDebug('name:', $obj['nl-devicename'], 0);
            $obj['nl-deviceid'] = $device['nl-devicename'];
            $this->SendDebug('device id:', $obj['nl-deviceid'], 0);
            $location = $this->GetNanoleafIP($device);
            $obj['host'] = $location['ip'];
            $this->SendDebug('host:', $obj['host'], 0);
            $obj['port'] = $location['port'];
            $this->SendDebug('port:', $obj['port'], 0);
            $result[] = $obj;
        }

        return $result;
    }

    /** Search Aurora nanoleaf_aurora:light / Canvas nanoleaf:nl29
     * @param string $st
     *
     * @return array
     */
    protected function mSearch(string $st = 'ssdp:all'): array
    {
        $ssdp_ids = IPS_GetInstanceListByModuleID('{FFFFA648-B296-E785-96ED-065F7CEE6F29}');
        $ssdp_id = $ssdp_ids[0];
        $devices = YC_SearchDevices($ssdp_id, $st);
        $devices[]=['ST' => 'nanoleaf_aurora:light'
                    , 'Location' => 'http://192.168.0.43:16021'
                    , 'Fields' => ['S: uuid:18bc1a09-63f1-4777-9d97-3a040d1b09a6', 'NL-DEVICEID: 4F:0C:05:CD:28:28', 'NL-DEVICENAME: Light Panels 54:c3:ad']
                    , 'USN' => 'uuid:18bc1a09-63f1-4777-9d97-3a040d1b09a6'
                    ];

        $nanoleaf_response = [];
        $i = 0;
        foreach($devices as $device)
        {
            if(isset($device['ST']))
            {
                if($device['ST'] === 'nanoleaf_aurora:light' || $device['ST'] === 'nanoleaf:nl29')
                {
                    if (isset($device['Location'])){
                        $nanoleaf_response[$i]['location'] = $device['Location'];
                    }
                    foreach($device['Fields'] as $field)
                    {
                        if(stripos($field, 'Location:') === 0) {
                            $nanoleaf_response[$i]['location'] = str_ireplace('location: ', '', $field);
                        }
                        if(stripos($field, 'nl-deviceid') === 0) {
                            $nanoleaf_response[$i]['nl-deviceid'] = str_ireplace('nl-deviceid: ', '', $field);
                        }
                        if(stripos($field, 'nl-devicename:') === 0) {
                            $nanoleaf_response[$i]['nl-devicename'] = str_ireplace('nl-devicename: ', '', $field);
                        }
                    }
                    $nanoleaf_response[$i]['uuid'] = str_ireplace('uuid:', '', $device['USN']);
                    $i++;
                }
            }
        }
        return $nanoleaf_response;
    }

    private function GetNanoleafIP($result): array
    {
        $location = $result['location'];
        $location = str_ireplace('http://', '', $location);
        $location = explode(':', $location);
        $ip = $location[0];
        $port = $location[1];
        return ['ip' => $ip, 'port' => $port];
    }

    public function Discover()
    {
        $this->LogMessage($this->Translate('Background Discovery of Nanoleaf Devices'), KL_NOTIFY);
        $devices = $this->DiscoverDevices();
        if (!empty($devices)) {
            $this->WriteAttributeString('devices', json_encode($devices));
        }
    }

    /***********************************************************
     * Configuration Form
     ***********************************************************/

    /**
     * build configuration form.
     *
     * @return string
     */
    public function GetConfigurationForm()
    {
        // return current form
        $Form = json_encode([
            'elements' => $this->FormHead(),
            'actions'  => [],
            'status'   => $this->FormStatus(),
        ]);
        $this->SendDebug('FORM', $Form, 0);
        $this->SendDebug('FORM', json_last_error_msg(), 0);

        return $Form;
    }

    /**
     * return form configurations on configuration step.
     *
     * @return array
     */
    private function FormHead(): array
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
                'columns' => [
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
                        'label' => 'IP adress',
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
                'values' => $this->Get_ListConfiguration(),
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
