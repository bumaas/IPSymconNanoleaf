<?php

// hier gibt es einen Farbrechner: https://www.mediaevent.de/css/farbrechner.html
// Nanoleaf in Postman: https://documenter.getpostman.com/view/1559645/RW1gEcCH#intro

declare(strict_types=1);

class Nanoleaf extends IPSModule
{
    private const STATUS_INST_IP_IS_INVALID   = 201;
    private const STATUS_INST_PORT_IS_INVALID = 202;
    private const STATUS_INST_TOKEN_NOT_SET   = 203;

    private const PROP_HOST            = 'host';
    private const PROP_PORT            = 'port';
    private const PROP_UPDATE_INTERVAL = 'UpdateInterval';

    private const ATTR_TOKEN            = 'token';
    private const ATTR_NEW_TOKEN        = 'newToken';
    private const ATTR_MODEL            = 'model';
    private const ATTR_FIRMWARE_VERSION = 'firmwareVersion';
    private const ATTR_SERIAL_NO        = 'serialNo';


    private const VAR_IDENT_STATE            = 'State';
    private const VAR_IDENT_COLOR            = 'color';
    private const VAR_IDENT_HUE              = 'hue';
    private const VAR_IDENT_SATURATION       = 'saturation';
    private const VAR_IDENT_BRIGHTNESS       = 'Brightness';
    private const VAR_IDENT_COLORTEMPERATURE = 'colortemperature';
    private const VAR_IDENT_EFFECT           = 'effect';

    private const TIMER_UPDATE = 'NanoleafTimerUpdate';

    private const HTTP_PREFIX = 'http://';
    private const MOCK_FILE   = __DIR__ . '/../Testdaten/Mocks';

    private const DEFAULT_EFFECT_ASSOCIATIONS = [
        [1, 'Color Burst', 'Light', -1],
        [2, 'Flames', 'Light', -1],
        [3, 'Forest', 'Light', -1],
        [4, 'Inner Peace', 'Light', -1],
        [5, 'Nemo', 'Light', -1],
        [6, 'Northern Lights', 'Light', -1],
        [7, 'Romantic', 'Light', -1],
        [8, 'Snowfall', 'Light', -1],
    ];


    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        //These lines are parsed on Symcon Startup or Instance creation
        //You cannot use variables here. Just static values.
        $this->RegisterPropertyString(self::PROP_HOST, '');
        $this->RegisterPropertyString(self::PROP_PORT, '16021');
        $this->RegisterPropertyInteger(self::PROP_UPDATE_INTERVAL, 5);

        $this->RegisterAttributeString(self::ATTR_SERIAL_NO, '');
        $this->RegisterAttributeString(self::ATTR_FIRMWARE_VERSION, '');
        $this->RegisterAttributeString(self::ATTR_MODEL, '');
        $this->RegisterAttributeString(self::ATTR_TOKEN, '');
        $this->RegisterAttributeString(self::ATTR_NEW_TOKEN, '');
        $this->RegisterTimer(self::TIMER_UPDATE, 5000, 'Nanoleaf_GetAllInfo(' . $this->InstanceID . ');');
        //we will wait until the kernel is ready
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->ValidateConfiguration();
    }

    private function ValidateConfiguration(): void
    {
        $this->RegisterVariableBoolean(self::VAR_IDENT_STATE, $this->Translate('state'), '~Switch', 1);
        $this->EnableAction(self::VAR_IDENT_STATE);
        $this->RegisterVariableInteger(self::VAR_IDENT_COLOR, $this->Translate('color'), '~HexColor', 2); // Color Hex, integer
        $this->EnableAction(self::VAR_IDENT_COLOR);
        $this->RegisterProfileInteger('Nanoleaf.Hue', 'Light', '', '', 0, 359, 1, 0);
        $this->RegisterVariableInteger(self::VAR_IDENT_HUE, $this->Translate('hue'), 'Nanoleaf.Hue', 3); // Hue (0-359), integer
        $this->EnableAction(self::VAR_IDENT_HUE);
        $this->RegisterVariableInteger(self::VAR_IDENT_SATURATION, $this->Translate('sat'), '~Intensity.100', 4); // Saturation (0-100)
        $this->EnableAction(self::VAR_IDENT_SATURATION);
        $this->RegisterVariableInteger(self::VAR_IDENT_BRIGHTNESS, $this->Translate('brightness'), '~Intensity.100', 5); // Brightness (0-100)
        $this->EnableAction(self::VAR_IDENT_BRIGHTNESS);

        $this->RegisterProfileInteger('Nanoleaf.Colortemperature', 'Light', '', '', 1200, 6500, 100, 0);
        $this->RegisterVariableInteger(
            self::VAR_IDENT_COLORTEMPERATURE,
            $this->Translate('ct'),
            'Nanoleaf.Colortemperature',
            6
        ); // "max" : 6500, "min" : 1200
        $this->EnableAction(self::VAR_IDENT_COLORTEMPERATURE);

        if (count($this->getEffectAssociations())){
            $this->RegisterProfileIntegerAss('Nanoleaf.Effect' . $this->InstanceID, 'Light', '', '', 1, 8, 0, 0, $this->getEffectAssociations());
        } else {
            $this->RegisterProfileInteger('Nanoleaf.Effect' . $this->InstanceID, 'Light', '', '', 1, 8, 0, 0);

        }
        $this->RegisterVariableInteger(self::VAR_IDENT_EFFECT, $this->Translate('effect'), 'Nanoleaf.Effect' . $this->InstanceID, 7);
        $this->EnableAction(self::VAR_IDENT_EFFECT);
        $this->SetValue(self::VAR_IDENT_EFFECT, 1);

        $this->SetSummary(
            sprintf(
                '%s:%s (%s)',
                $this->ReadPropertyString(self::PROP_HOST),
                $this->ReadPropertyString(self::PROP_PORT),
                $this->ReadAttributeString(self::ATTR_MODEL)
            )
        );

        $this->SetInstanceStatus();
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
                }
                break;

            default:
                break;
        }
    }

    private function SetUpdateIntervall(): void
    {
        $interval = ($this->ReadPropertyInteger(self::PROP_UPDATE_INTERVAL)) * 1000; // interval ms
        $this->SetTimerInterval(self::TIMER_UPDATE, $interval);
    }

    private function updateEffectProfile(): void
    {
        $effectAssociations = $this->getEffectAssociations();
        $profileName        = 'Nanoleaf.Effect' . $this->InstanceID;
        if (IPS_VariableProfileExists($profileName)) {
            foreach ($effectAssociations as [$index, $name, $icon, $color]) {
                IPS_SetVariableProfileAssociation(
                    $profileName,
                    $index,
                    $name,
                    $icon,
                    $color
                );
            }
        }
    }

    private function getEffectAssociationsFromList($list): array
    {
        $effectAssociations = [];
        foreach ($list as $key => $effect) {
            $position             = $key + 1;
            $effectAssociations[] = [$position, $effect, 'Light', -1];
        }
        return $effectAssociations;
    }

    private function getEffectAssociations(): array
    {
        if ($this->GetStatus() !== IS_ACTIVE) {
            //return self::DEFAULT_EFFECT_ASSOCIATIONS;
            return [];
        }

        $effectlist = $this->ListEffect();
        $this->SendDebug(__FUNCTION__, sprintf('effectList: %s', $effectlist), 0);

        if ($effectlist) {
            $list = json_decode($effectlist, true, 512, JSON_THROW_ON_ERROR);
        } else {
            $list = [];
        }

        return $this->getEffectAssociationsFromList($list);
    }

    public function GetAllInfo(): false|array
    {
        if ($this->GetStatus() !== IS_ACTIVE) {
            return false;
        }

        $payload = ['command' => 'GetAllInfo'];
        if (file_exists(self::MOCK_FILE)) {
            $jsonContent = file_get_contents(self::MOCK_FILE);

            $info = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR)['GetAllInfo_response'];
            $this->SendDebug('TEST', sprintf('%s: %s', 'GetAllInfo_response', $info), 0);
        } else {
            $info = $this->SendCommand($payload);
        }

        if ($info === false) {
            return false;
        }
        $data            = json_decode($info, false, 512, JSON_THROW_ON_ERROR);
        $serialNo        = $data->serialNo;
        $firmwareVersion = $data->firmwareVersion;
        $model           = $data->model;
        $state           = $data->state->on->value;
        $brightness      = $data->state->brightness->value;
        $hue             = $data->state->hue->value;
        $sat             = $data->state->sat->value;
        $ct              = $data->state->ct->value;
        $colormode       = $data->state->colorMode;

        $this->SetValue(self::VAR_IDENT_STATE, $state);
        $this->SetValue(self::VAR_IDENT_BRIGHTNESS, $brightness);
        $this->SetValue(self::VAR_IDENT_HUE, $hue);
        $this->SetValue(self::VAR_IDENT_SATURATION, $sat);
        $this->SetValueColor();
        $this->SetValue(self::VAR_IDENT_COLORTEMPERATURE, $ct);

        return [
            'serialnumber' => $serialNo,
            'firmware'     => $firmwareVersion,
            'model'        => $model,
            'state'        => $state,
            'brightness'   => $brightness,
            'hue'          => $hue,
            'sat'          => $sat,
            'ct'           => $ct,
            'colormode'    => $colormode
        ];

    }

    private function getToken(): void
    {
        /* A user is authorized to access the OpenAPI if they can demonstrate physical access of the Aurora. This is achieved by:
        1. Holding the on-off button down for 5-7 seconds until the LED starts flashing in a pattern
        2. Sending a POST request to the authorization endpoint */
        $host = $this->ReadPropertyString(self::PROP_HOST);
        $port = $this->ReadPropertyString(self::PROP_PORT);
        $url  = self::HTTP_PREFIX . $host . ':' . $port . '/api/v1/new';
        $this->SendDebug(__FUNCTION__, $url, 0);
        $ch      = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_HTTPHEADER     => ['Content-type: application/json']
        ];
        curl_setopt_array($ch, $options);
        if (file_exists(self::MOCK_FILE)) {
            $jsonContent = file_get_contents(self::MOCK_FILE);

            $token_response = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR)['token_response'];
            //$token_response ='';
            $this->SendDebug('TEST', sprintf('%s: %s', 'token_response', $token_response), 0);
        } else {
            $token_response = curl_exec($ch);
        }
        curl_close($ch);

        $this->SendDebug('Nanoleaf token response: ', $token_response, 0);
        if (empty($token_response)) {
            $this->UpdateFormField('MsgTitle', 'visible', false);
            $this->UpdateFormField('MsgText', 'caption', 'Could not get token');
            $this->UpdateFormField('MsgBox', 'visible', true);

            return;
        }
        $newToken = json_decode($token_response, true, 512, JSON_THROW_ON_ERROR)['auth_token'];
        $this->SendDebug('Received Token:', $newToken, 0);
        $this->WriteAttributeString(self::ATTR_NEW_TOKEN, $newToken);

        $this->UpdateFormField('TokenTitle', 'caption', sprintf($this->Translate('Received Token: %s'), $newToken));
        $this->UpdateFormField('TokenText', 'caption', $this->Translate('Should the token be used?'));
        $this->UpdateFormField('TokenBox', 'visible', true);
    }

    private function saveToken(): void
    {
        $newToken = $this->ReadAttributeString(self::ATTR_NEW_TOKEN);
        $this->WriteAttributeString(self::ATTR_TOKEN, $newToken);
        $this->SendDebug(__function__, $newToken, 0);

        $this->UpdateFormField('TokenBox', 'visible', false);

        $this->GetInfo();
        $this->ValidateConfiguration();
        $this->ReloadForm();
    }

    private function SendCommand($payload): bool|string
    {
        $command      = $payload['command'];
        $commandvalue = $payload['commandvalue'] ?? '';

        $host  = $this->ReadPropertyString(self::PROP_HOST);
        $token = $this->ReadAttributeString(self::ATTR_TOKEN);
        if ($token === '') {
            return false;
        }
        $port        = $this->ReadPropertyString(self::PROP_PORT);
        $url         = self::HTTP_PREFIX . $host . ':' . $port . '/api/v1/' . $token . '/';
        $postfields  = '';
        $requesttype = '';
        if ($command === 'On') {
            $url         .= 'state';
            $postfields  = '{"on" : {"value":true}}';
            $requesttype = 'PUT';
        } elseif ($command === 'Off') {
            $url         .= 'state';
            $postfields  = '{"on" : {"value":false}}';
            $requesttype = 'PUT';
        } elseif ($command === 'GetState') {
            $url         .= 'state/on';
            $requesttype = 'GET';
        } elseif ($command === 'SetBrightness') {
            $url         .= 'state';
            $postfields  = '{"brightness" : {"value":' . $commandvalue . '}}';
            $requesttype = 'PUT';
        } elseif ($command === 'GetBrightness') {
            $url         .= 'state/brightness';
            $requesttype = 'GET';
        } elseif ($command === 'SetHue') {
            $url         .= 'state';
            $postfields  = '{"hue" : {"value":' . $commandvalue . '}}';
            $requesttype = 'PUT';
        } elseif ($command === 'GetHue') {
            $url         .= 'state/hue';
            $requesttype = 'GET';
        } elseif ($command === 'SetSaturation') {
            $url         .= 'state';
            $postfields  = '{"sat" : {"value":' . $commandvalue . '}}';
            $requesttype = 'PUT';
        } elseif ($command === 'GetSaturation') {
            $url         .= 'state/sat';
            $requesttype = 'GET';
        } elseif ($command === 'SetColortemperature') {
            $url         .= 'state';
            $postfields  = '{"ct" : {"value":' . $commandvalue . '}}';
            $requesttype = 'PUT';
        } elseif ($command === 'GetColortemperature') {
            $url         .= 'state/ct';
            $requesttype = 'GET';
        } elseif ($command === 'ColorMode') {
            $url         .= 'state/colorMode';
            $requesttype = 'GET';
        } elseif ($command === 'SelectEffect') {
            $url         .= 'effects';
            $postfields  = '{"select":"' . $commandvalue . '"}';
            $requesttype = 'PUT';
        } elseif ($command === 'GetEffects') {
            $url         .= 'effects/select';
            $requesttype = 'GET';
        } elseif ($command === 'List') {
            $url         .= 'effects/effectsList';
            $requesttype = 'GET';
        } elseif ($command === 'Random') {
            $url         .= 'effects';
            $result      = json_decode(Sys_GetURLContent($url . 'effects/effectsList'), true, 512, JSON_THROW_ON_ERROR);
            $postfields  = '{"select":"' . $result[array_rand($result)] . '"}';
            $requesttype = 'PUT';
        } elseif ($command === 'GetAllInfo') {
            $requesttype = 'GET';
        } elseif ($command === 'DeleteUser') {
            $requesttype = 'DELETE';
            $url         = self::HTTP_PREFIX . $host . ':' . $port . '/api/v1/' . $commandvalue;
        } elseif ($command === 'GetGlobalOrientation') {
            $requesttype = 'GET';
            $url         .= 'panelLayout/globalOrientation';
        } elseif ($command === 'SetGlobalOrientation') {
            $requesttype = 'PUT';
            $postfields  = '{"globalOrientation" : {"value":' . $commandvalue . '}}';
            $url         .= 'panelLayout';
        } elseif ($command === 'Layout') {
            $requesttype = 'GET';
            $url         .= 'panelLayout/layout';
        } elseif ($command === 'Identify') {
            $requesttype = 'PUT';
            $url         .= 'identify';
        }

        $this->SendDebug(__FUNCTION__, sprintf('command: %s, url: %s, postfields: %s', $command, $url, $postfields), 0);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $requesttype,
            CURLOPT_HTTPHEADER     => ['Content-type: application/json'],
        ]);
        if ($postfields !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
        }
        $result = curl_exec($ch);
        curl_close($ch);
        $this->SendDebug('Nanoleaf Command Response: ', json_encode($result, JSON_THROW_ON_ERROR), 0);

        return $result;
    }

    public function GetState()
    {
        $payload    = ['command' => 'GetState'];
        $state_json = $this->SendCommand($payload);
        $state      = json_decode($state_json, true, 512, JSON_THROW_ON_ERROR)['value'];
        $this->SetValue(self::VAR_IDENT_STATE, $state);

        return $state;
    }

    /**
     * Switch the state of the device
     *
     * @param bool $state
     *
     * @throws \JsonException
     */
    private function setState(bool $state): void
    {
        $payload = ['command' => $state ? 'On' : 'Off'];
        if ($this->SendCommand($payload)) {
            $this->SetValue(self::VAR_IDENT_STATE, $state);
        }
    }

    private function setColor(int $value): void
    {
        $this->SendDebug(__FUNCTION__, sprintf('value: %s', $value), 0);
        $this->SetValue(self::VAR_IDENT_COLOR, $value);

        $hex = str_pad(dechex($value), 6, '0', STR_PAD_LEFT);
        $hsv = $this->HEX2HSV($hex);

        $this->setHue($hsv['h']);
        $this->setSaturation($hsv['s']);
        $this->setBrightness($hsv['v']);
    }

    private function GetHSB(): array
    {
        $hue        = $this->GetValue(self::VAR_IDENT_HUE);
        $saturation = $this->GetValue(self::VAR_IDENT_SATURATION);
        $brightness = $this->GetValue(self::VAR_IDENT_BRIGHTNESS);
        return ['hue' => $hue, 'saturation' => $saturation, 'brightness' => $brightness];
    }

    private function HEX2HSV($hex): array
    {
        $r = substr($hex, 0, 2);
        $g = substr($hex, 2, 2);
        $b = substr($hex, 4, 2);

        return $this->RGB2HSV(hexdec($r), hexdec($g), hexdec($b));
    }

    private function HSV2HEX(int $h, float $s, float $v): string
    {
        $rgb = $this->HSV2RGB($h, $s, $v);
        $r   = str_pad(dechex($rgb['r']), 2, '0', STR_PAD_LEFT);
        $g   = str_pad(dechex($rgb['g']), 2, '0', STR_PAD_LEFT);
        $b   = str_pad(dechex($rgb['b']), 2, '0', STR_PAD_LEFT);

        return $r . $g . $b;
    }

    private function RGB2HSV(int $red, int $green, int $blue): array
    {
        $this->validateProperty('r', $red, 0, 255);
        $this->validateProperty('g', $green, 0, 255);
        $this->validateProperty('b', $blue, 0, 255);

        $red   /= 255;
        $green /= 255;
        $blue  /= 255;

        $maxRGB = max($red, $green, $blue);
        $minRGB = min($red, $green, $blue);

        $chroma = $maxRGB - $minRGB;
        $value  = $maxRGB * 100;

        if ($chroma === 0) {
            return ['h' => 0, 's' => 0, 'v' => (int)$value];
        }

        $saturation = ($chroma / $maxRGB) * 100;

        if ($red === $minRGB) {
            $hue = 3 - (($green - $blue) / $chroma);
        } elseif ($blue === $minRGB) {
            $hue = 1 - (($red - $green) / $chroma);
        } else {
            $hue = 5 - (($blue - $red) / $chroma);
        }

        $hue = $hue / 6 * 360;

        return ['h' => (int)round($hue), 's' => (int)round($saturation), 'v' => (int)$value];
    }

    private function validateProperty(string $propName, $value, $min, int $max): void
    {
        if (!($value >= $min && $value <= $max)) {
            throw new RuntimeException("$propName property must be between $min and $max, but is: $value");
        }
    }

    private function computeRGB($i, $v, $k, $m, $n): array
    {
        return match ($i) {
            0 => [$v, $k, $m],
            1 => [$n, $v, $m],
            2 => [$m, $v, $k],
            3 => [$m, $n, $v],
            4 => [$k, $m, $v],
            default => [$v, $m, $n],
        };
    }

    private function HSV2RGB(int $h, float $s, float $v): array
    {
        $this->validateProperty('h', $h, 0, 360);
        $this->validateProperty('s', $s, 0, 1);
        $this->validateProperty('v', $v, 0, 1);

        $hh = $h / 60;
        $i  = (int)$hh;
        $f  = $hh - $i;
        $m  = $v * (1 - $s);
        $n  = $v * (1 - $s * $f);
        $k  = $v * (1 - $s * (1 - $f));
        [$r, $g, $b] = $this->computeRGB($i, $v, $k, $m, $n);
        $r = (int)round($r * 255);
        $g = (int)round($g * 255);
        $b = (int)round($b * 255);

        return ['r' => $r, 'g' => $g, 'b' => $b];
    }

    private function SetValueColor(): void
    {
        $hsb = $this->GetHSB();
        $hex = $this->HSV2HEX($hsb['hue'], $hsb['saturation'] / 100, $hsb['brightness'] / 100);
        $this->SendDebug(__FUNCTION__, sprintf('hex: #%s, value: %s', $hex, hexdec($hex)), 0);
        $this->SetValue(self::VAR_IDENT_COLOR, hexdec($hex));
    }

    private function setBrightness(int $brightness): void
    {
        $payload = ['command' => 'SetBrightness', 'commandvalue' => $brightness];
        if ($this->SendCommand($payload)){
            $this->SetValue(self::VAR_IDENT_BRIGHTNESS, $brightness);
            $this->SetValueColor();
        }
    }

    private function setHue(int $hue): void
    {
        $payload = ['command' => 'SetHue', 'commandvalue' => $hue];
        if ($this->SendCommand($payload)){
            $this->SetValue(self::VAR_IDENT_HUE, $hue);
            $this->SetValueColor();
        }
    }

    private function setSaturation(int $sat): void
    {
        $payload = ['command' => 'SetSaturation', 'commandvalue' => $sat];
        if ($this->SendCommand($payload)) {
            $this->SetValue(self::VAR_IDENT_SATURATION, $sat);
            $this->SetValueColor();
        }
    }

    private function setColortemperature(int $ct): void
    {
        $payload = ['command' => 'SetColortemperature', 'commandvalue' => $ct];
        if ($this->SendCommand($payload)) {
            $this->SetValue(self::VAR_IDENT_COLORTEMPERATURE, $ct);
        }
    }

    public function GetColortemperature()
    {
        $payload = ['command' => 'GetColortemperature'];
        $ct_json = $this->SendCommand($payload);
        $ct      = json_decode($ct_json, true, 512, JSON_THROW_ON_ERROR)['value'];
        $this->SetValue(self::VAR_IDENT_COLORTEMPERATURE, $ct);

        return $ct;
    }

    public function ColorMode()
    {
        $payload = ['command' => 'ColorMode'];
        return $this->SendCommand($payload);
    }

    private function SelectEffect(string $effectName) // "Color Burst","Flames","Forest","Inner Peace","Nemo","Northern Lights","Romantic","Snowfall"
    {
        $payload = ['command' => 'SelectEffect', 'commandvalue' => $effectName];
        $result  = $this->SendCommand($payload);
        $effects = IPS_GetVariableProfile('Nanoleaf.Effect' . $this->InstanceID)['Associations'];

        $effectNumber = $this->findEffectNumber($effects, $effectName);

        if ($effectNumber === null) {
            trigger_error(sprintf('No effect with name %s found in profile Nanoleaf.Effect%s', $effectName, $this->InstanceID), E_USER_ERROR);
        }

        $this->SendDebug(__FUNCTION__, sprintf('effects: %s, selected: %s', json_encode($effects, JSON_THROW_ON_ERROR), $effectNumber), 0);

        $this->SetValue(self::VAR_IDENT_EFFECT, $effectNumber);

        return $result;
    }

    private function findEffectNumber(array $effects, string $effectName): ?int
    {
        foreach ($effects as $effectposition) {
            if ($effectposition['Name'] === $effectName) {
                return (int)$effectposition['Value'];
            }
        }
        return null;
    }

    private function setEffect(int $effect) // "Color Burst","Flames","Forest","Inner Peace","Nemo","Northern Lights","Romantic","Snowfall"
    {
        $effectName = $this->findEffectName($effect);

        if (!$effectName) {
            trigger_error(sprintf('No effect with value %s found in profile Nanoleaf.Effect%s', $effect, $this->InstanceID), E_USER_ERROR);
        }

        return $this->SelectEffect($effectName);
    }

    private function findEffectName(int $effectValue): ?string
    {
        foreach (IPS_GetVariableProfile('Nanoleaf.Effect' . $this->InstanceID)['Associations'] as $assoziation) {
            if ((int)$assoziation['Value'] === $effectValue) {
                return $assoziation['Name'];
            }
        }
        return null;
    }

    public function GetEffects()
    {
        $payload = ['command' => 'GetEffects'];
        return $this->SendCommand($payload);
    }

    public function ListEffect()
    {
        $payload = ['command' => 'List'];
        return $this->SendCommand($payload);
    }

    public function GetInfo(): void
    {
        if (!$info = $this->GetAllInfo()) {
            $this->SendDebug(__FUNCTION__, 'Failed', 0);
            return;
        }

        $serialNo        = $info['serialnumber'];
        $firmwareVersion = $info['firmware'];
        $model           = $info['model'];
        $this->WriteAttributeString(self::ATTR_SERIAL_NO, $serialNo);
        $this->SendDebug('Nanoleaf:', 'serial number: ' . $serialNo, 0);
        $this->WriteAttributeString(self::ATTR_FIRMWARE_VERSION, $firmwareVersion);
        $this->SendDebug('Nanoleaf:', 'firmware version: ' . $firmwareVersion, 0);
        $this->WriteAttributeString(self::ATTR_MODEL, $model);
        $this->SendDebug('Nanoleaf:', 'model: ' . $model, 0);
    }

    public function GetGlobalOrientation()
    {
        $payload                 = ['command' => 'GetGlobalOrientation'];
        $global_orientation_json = $this->SendCommand($payload);
        return json_decode($global_orientation_json, true, 512, JSON_THROW_ON_ERROR)['value'];
    }

    public function SetGlobalOrientation(int $orientation)
    {
        $payload = ['command' => 'SetGlobalOrientation', 'commandvalue' => $orientation];
        return $this->SendCommand($payload);
    }

    public function Layout()
    {
        $payload = ['command' => 'Layout'];
        return $this->SendCommand($payload);
    }

    public function Identify()
    {
        $payload = ['command' => 'Identify'];
        return $this->SendCommand($payload);
    }

    public function RequestAction($Ident, $Value): void
    {
        $this->SendDebug(__FUNCTION__, sprintf('Ident: %s, $Value: %s', $Ident, $Value), 0);
        switch ($Ident) {
            case self::VAR_IDENT_STATE:
                $this->setState($Value);
                break;
            case self::VAR_IDENT_COLOR:
                $this->setColor($Value);
                break;
            case self::VAR_IDENT_BRIGHTNESS:
                $this->setBrightness($Value);
                break;
            case self::VAR_IDENT_HUE:
                $this->setHue($Value);
                break;
            case self::VAR_IDENT_SATURATION:
                $this->setSaturation($Value);
                break;
            case self::VAR_IDENT_COLORTEMPERATURE:
                $this->setColortemperature($Value);
                break;
            case self::VAR_IDENT_EFFECT:
                $this->setEffect($Value);
                break;
            case 'btnGetToken':
                $this->getToken();
                break;
            case 'btnSaveToken':
                $this->saveToken();
                break;
            case 'btnUpdateEffectProfile':
                $this->updateEffectProfile();
                break;
            default:
                throw new RuntimeException('Invalid ident');
        }
    }

    protected function RegisterProfileInteger($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $StepSize, $Digits): void
    {
        if (!IPS_VariableProfileExists($Name)) {
            IPS_CreateVariableProfile($Name, VARIABLETYPE_INTEGER);
        } else {
            $profile = IPS_GetVariableProfile($Name);
            if ($profile['ProfileType'] !== VARIABLETYPE_INTEGER) {
                throw new RuntimeException('Variable profile type does not match for profile ' . $Name);
            }
        }

        IPS_SetVariableProfileIcon($Name, $Icon);
        IPS_SetVariableProfileText($Name, $Prefix, $Suffix);
        IPS_SetVariableProfileDigits($Name, $Digits);
        IPS_SetVariableProfileValues(
            $Name,
            $MinValue,
            $MaxValue,
            $StepSize
        );
    }

    private function RegisterProfileIntegerAss($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $Stepsize, $Digits, $Associations): void
    {
        if (count($Associations) === 0) {
            $MinValue = 0;
            $MaxValue = 0;
        }

        $this->RegisterProfileInteger($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $Stepsize, $Digits);

        //boolean IPS_SetVariableProfileAssociation ( string $ProfilName, float $Wert, string $Name, string $Icon, integer $Farbe )
        foreach ($Associations as $Association) {
            IPS_SetVariableProfileAssociation($Name, $Association[0], $Association[1], $Association[2], $Association[3]);
        }
    }


    /***********************************************************
     * Configuration Form
     ***********************************************************/

    /**
     * build configuration form.
     *
     * @return string
     * @throws \JsonException
     */
    public function GetConfigurationForm(): string
    {
        // return current form
        return json_encode([
                               'elements' => $this->FormElements(),
                               'actions'  => $this->FormActions(),
                               'status'   => $this->FormStatus(),
                           ], JSON_THROW_ON_ERROR);
    }

    /**
     * return form elements
     *
     * @return array
     */
    private function FormElements(): array
    {
        return [
            [
                'type'  => 'Image',
                'image' => 'data:image/png;base64, iVBORw0KGgoAAAANSUhEUgAAAnwAAAB3CAYAAACZtZ28AAAACXBIWXMAAAsTAAALEwEAmpwYAAA4JmlUWHRYTUw6Y29tLmFkb2JlLnhtcAAAAAAAPD94cGFja2V0IGJlZ2luPSLvu78iIGlkPSJXNU0wTXBDZWhpSHpyZVN6TlRjemtjOWQiPz4KPHg6eG1wbWV0YSB4bWxuczp4PSJhZG9iZTpuczptZXRhLyIgeDp4bXB0az0iQWRvYmUgWE1QIENvcmUgNS42LWMwNjcgNzkuMTU3NzQ3LCAyMDE1LzAzLzMwLTIzOjQwOjQyICAgICAgICAiPgogICA8cmRmOlJERiB4bWxuczpyZGY9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkvMDIvMjItcmRmLXN5bnRheC1ucyMiPgogICAgICA8cmRmOkRlc2NyaXB0aW9uIHJkZjphYm91dD0iIgogICAgICAgICAgICB4bWxuczp4bXA9Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC8iCiAgICAgICAgICAgIHhtbG5zOmRjPSJodHRwOi8vcHVybC5vcmcvZGMvZWxlbWVudHMvMS4xLyIKICAgICAgICAgICAgeG1sbnM6cGhvdG9zaG9wPSJodHRwOi8vbnMuYWRvYmUuY29tL3Bob3Rvc2hvcC8xLjAvIgogICAgICAgICAgICB4bWxuczp4bXBNTT0iaHR0cDovL25zLmFkb2JlLmNvbS94YXAvMS4wL21tLyIKICAgICAgICAgICAgeG1sbnM6c3RFdnQ9Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC9zVHlwZS9SZXNvdXJjZUV2ZW50IyIKICAgICAgICAgICAgeG1sbnM6dGlmZj0iaHR0cDovL25zLmFkb2JlLmNvbS90aWZmLzEuMC8iCiAgICAgICAgICAgIHhtbG5zOmV4aWY9Imh0dHA6Ly9ucy5hZG9iZS5jb20vZXhpZi8xLjAvIj4KICAgICAgICAgPHhtcDpDcmVhdG9yVG9vbD5BZG9iZSBQaG90b3Nob3AgQ0MgMjAxNSAoV2luZG93cyk8L3htcDpDcmVhdG9yVG9vbD4KICAgICAgICAgPHhtcDpDcmVhdGVEYXRlPjIwMTgtMTItMDhUMTk6NTA6NTMrMDE6MDA8L3htcDpDcmVhdGVEYXRlPgogICAgICAgICA8eG1wOk1vZGlmeURhdGU+MjAxOC0xMi0wOVQwOTo0MzoyOSswMTowMDwveG1wOk1vZGlmeURhdGU+CiAgICAgICAgIDx4bXA6TWV0YWRhdGFEYXRlPjIwMTgtMTItMDlUMDk6NDM6MjkrMDE6MDA8L3htcDpNZXRhZGF0YURhdGU+CiAgICAgICAgIDxkYzpmb3JtYXQ+aW1hZ2UvcG5nPC9kYzpmb3JtYXQ+CiAgICAgICAgIDxwaG90b3Nob3A6Q29sb3JNb2RlPjM8L3Bob3Rvc2hvcDpDb2xvck1vZGU+CiAgICAgICAgIDx4bXBNTTpJbnN0YW5jZUlEPnhtcC5paWQ6NzkxNzEwMTYtMDY0MS1lYzQxLTgwZTEtZjI2NzZmMjJiMDcyPC94bXBNTTpJbnN0YW5jZUlEPgogICAgICAgICA8eG1wTU06RG9jdW1lbnRJRD54bXAuZGlkOjc5MTcxMDE2LTA2NDEtZWM0MS04MGUxLWYyNjc2ZjIyYjA3MjwveG1wTU06RG9jdW1lbnRJRD4KICAgICAgICAgPHhtcE1NOk9yaWdpbmFsRG9jdW1lbnRJRD54bXAuZGlkOjc5MTcxMDE2LTA2NDEtZWM0MS04MGUxLWYyNjc2ZjIyYjA3MjwveG1wTU06T3JpZ2luYWxEb2N1bWVudElEPgogICAgICAgICA8eG1wTU06SGlzdG9yeT4KICAgICAgICAgICAgPHJkZjpTZXE+CiAgICAgICAgICAgICAgIDxyZGY6bGkgcmRmOnBhcnNlVHlwZT0iUmVzb3VyY2UiPgogICAgICAgICAgICAgICAgICA8c3RFdnQ6YWN0aW9uPmNyZWF0ZWQ8L3N0RXZ0OmFjdGlvbj4KICAgICAgICAgICAgICAgICAgPHN0RXZ0Omluc3RhbmNlSUQ+eG1wLmlpZDo3OTE3MTAxNi0wNjQxLWVjNDEtODBlMS1mMjY3NmYyMmIwNzI8L3N0RXZ0Omluc3RhbmNlSUQ+CiAgICAgICAgICAgICAgICAgIDxzdEV2dDp3aGVuPjIwMTgtMTItMDhUMTk6NTA6NTMrMDE6MDA8L3N0RXZ0OndoZW4+CiAgICAgICAgICAgICAgICAgIDxzdEV2dDpzb2Z0d2FyZUFnZW50PkFkb2JlIFBob3Rvc2hvcCBDQyAyMDE1IChXaW5kb3dzKTwvc3RFdnQ6c29mdHdhcmVBZ2VudD4KICAgICAgICAgICAgICAgPC9yZGY6bGk+CiAgICAgICAgICAgIDwvcmRmOlNlcT4KICAgICAgICAgPC94bXBNTTpIaXN0b3J5PgogICAgICAgICA8dGlmZjpPcmllbnRhdGlvbj4xPC90aWZmOk9yaWVudGF0aW9uPgogICAgICAgICA8dGlmZjpYUmVzb2x1dGlvbj43MjAwMDAvMTAwMDA8L3RpZmY6WFJlc29sdXRpb24+CiAgICAgICAgIDx0aWZmOllSZXNvbHV0aW9uPjcyMDAwMC8xMDAwMDwvdGlmZjpZUmVzb2x1dGlvbj4KICAgICAgICAgPHRpZmY6UmVzb2x1dGlvblVuaXQ+MjwvdGlmZjpSZXNvbHV0aW9uVW5pdD4KICAgICAgICAgPGV4aWY6Q29sb3JTcGFjZT42NTUzNTwvZXhpZjpDb2xvclNwYWNlPgogICAgICAgICA8ZXhpZjpQaXhlbFhEaW1lbnNpb24+NjM2PC9leGlmOlBpeGVsWERpbWVuc2lvbj4KICAgICAgICAgPGV4aWY6UGl4ZWxZRGltZW5zaW9uPjExOTwvZXhpZjpQaXhlbFlEaW1lbnNpb24+CiAgICAgIDwvcmRmOkRlc2NyaXB0aW9uPgogICA8L3JkZjpSREY+CjwveDp4bXBtZXRhPgogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAg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',
            ],
            [
                'type'  => 'RowLayout',
                'items' => [
                    [
                        'type'    => 'ValidationTextBox',
                        'name'    => self::PROP_HOST,
                        'caption' => 'IP address'
                    ],
                    [
                        'type'    => 'ValidationTextBox',
                        'name'    => self::PROP_PORT,
                        'caption' => 'Port'
                    ]
                ]
            ],
            [
                'type'    => 'NumberSpinner',
                'name'    => self::PROP_UPDATE_INTERVAL,
                'caption' => 'Update Interval',
                'suffix'  => 'Seconds'
            ],
            [
                'type'     => 'List',
                'caption'  => 'Nanoleaf Information',
                'rowCount' => 2,
                'add'      => false,
                'delete'   => false,
                'columns'  => [
                    [
                        'caption' => 'Token',
                        'name'    => 'token',
                        'width'   => '200px',
                        'save'    => true,
                    ],
                    [
                        'caption' => 'Serial Number',
                        'name'    => 'serialNo',
                        'width'   => '150px',
                        'save'    => true,
                        'visible' => true,
                    ],
                    [
                        'caption' => 'Firmware Version',
                        'name'    => 'firmwareVersion',
                        'width'   => '140px',
                        'save'    => true,
                        'visible' => true,
                    ],
                    [
                        'caption' => 'Model',
                        'name'    => 'model',
                        'width'   => '80px',
                        'save'    => true,
                        'visible' => true
                    ]
                ],
                'values'   => [
                    [
                        'token'           => $this->ReadAttributeString(self::ATTR_TOKEN),
                        'serialNo'        => $this->ReadAttributeString(self::ATTR_SERIAL_NO),
                        'firmwareVersion' => $this->ReadAttributeString(self::ATTR_FIRMWARE_VERSION),
                        'model'           => $this->ReadAttributeString(self::ATTR_MODEL),
                    ]
                ]
            ],
            [
                'name'    => 'TokenBox',
                'type'    => 'PopupAlert',
                'visible' => false,
                'popup'   => [
                    'closeCaption' => 'Cancel',
                    'buttons'      => [
                        [
                            'caption' => 'OK',
                            'onClick' => 'IPS_RequestAction($id, "btnSaveToken", "");'
                        ]
                    ],
                    'items'        => [
                        [
                            'name'    => 'TokenTitle',
                            'type'    => 'Label',
                            'caption' => 'Authorize'
                        ],
                        [
                            'name'    => 'TokenText',
                            'type'    => 'Label',
                            'caption' => ''
                        ],
                    ]
                ]
            ],
            [
                'name'    => 'MsgBox',
                'type'    => 'PopupAlert',
                'visible' => false,
                'popup'   => [
                    'items' => [
                        [
                            'name'    => 'MsgTitle',
                            'type'    => 'Label',
                            'caption' => 'Authorize'
                        ],
                        [
                            'name'    => 'MsgText',
                            'type'    => 'Label',
                            'caption' => ''
                        ],
                    ]
                ]
            ]
        ];
    }

    /**
     * return form actions by token.
     *
     * @return array
     */
    private function FormActions(): array
    {
        $tokenNotSet = $this->GetStatus() === self::STATUS_INST_TOKEN_NOT_SET;
        $isActive    = $this->GetStatus() === IS_ACTIVE;
        return [
            [
                'type'    => 'Label',
                'caption' => '1. Hold the on-off button down for 5-7 seconds until the LED starts flashing in a pattern',
                'visible' => $tokenNotSet
            ],
            [
                'type'    => 'Label',
                'caption' => '2. Press the button below Get Token',
                'visible' => $tokenNotSet
            ],
            [
                'type'    => 'Button',
                'caption' => 'Get Token',
                'onClick' => 'IPS_RequestAction($id, "btnGetToken", "");',
                'visible' => $tokenNotSet
            ],
            [
                'type'    => 'Button',
                'caption' => 'Get Nanoleaf info',
                'onClick' => 'Nanoleaf_GetInfo($id);',
                'visible' => $isActive
            ],
            [
                'type'    => 'Button',
                'caption' => 'Update Effects',
                'onClick' => 'IPS_RequestAction($id, "btnUpdateEffectProfile", "");',
                'visible' => $isActive
            ],
            [
                'type'    => 'TestCenter',
                'visible' => $isActive
            ]
        ];
    }

    /**
     * return form status.
     *
     * @return array
     */
    private function FormStatus(): array
    {
        return [
            [
                'code'    => self::STATUS_INST_IP_IS_INVALID,
                'icon'    => 'error',
                'caption' => 'Host is invalid.'
            ],
            [
                'code'    => self::STATUS_INST_PORT_IS_INVALID,
                'icon'    => 'error',
                'caption' => 'Port is invalid.'
            ],
            [
                'code'    => self::STATUS_INST_TOKEN_NOT_SET,
                'icon'    => 'error',
                'caption' => 'Token not set.'
            ]
        ];
    }

    private function SetInstanceStatus(): void
    {
        $host  = $this->ReadPropertyString(self::PROP_HOST);
        $port  = $this->ReadPropertyString(self::PROP_PORT);
        $token = $this->ReadAttributeString(self::ATTR_TOKEN);


        //Host prüfen
        if (!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            $this->SetStatus(self::STATUS_INST_IP_IS_INVALID); //IP-Adresse ist ungültig
            $this->SetTimerInterval(self::TIMER_UPDATE, 0);
            $this->SendDebug(__FUNCTION__, sprintf('Status: %s (%s)', $this->GetStatus(), 'invalid IP'), 0);
            return;
        }

        //Port Prüfen
        if ((int)$port < 1 || (int)$port > 65535 || !filter_var($port, FILTER_VALIDATE_INT)) {
            $this->SetStatus(self::STATUS_INST_PORT_IS_INVALID);
            $this->SetTimerInterval(self::TIMER_UPDATE, 0);
            $this->SendDebug(__FUNCTION__, sprintf('Status: %s (%s)', $this->GetStatus(), 'invalid Port'), 0);
            return;
        }

        //Token prüfen
        if ($token === '') {
            $this->SetStatus(self::STATUS_INST_TOKEN_NOT_SET);
            $this->SetTimerInterval(self::TIMER_UPDATE, 0);
            $this->SendDebug(__FUNCTION__, sprintf('Status: %s (%s)', $this->GetStatus(), 'no Token'), 0);
            return;
        }

        if ($this->GetStatus() !== IS_ACTIVE) {
            $this->SetUpdateIntervall();
            $this->SetStatus(IS_ACTIVE);
            $this->SendDebug(__FUNCTION__, sprintf('Status: %s (%s)', $this->GetStatus(), 'active'), 0);
        }
    }
}
