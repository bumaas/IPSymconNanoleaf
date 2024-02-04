# IPSymconNanoleaf

**Table of Contents**

1. [Features](#1-features)
2. [Requirements](#2-requirements)
3. [Installation](#3-installation)
4. [Function reference](#4-function-reference)
5. [Configuration](#5-configuration)
6. [Annex](#6-annex)

## 1. Features

The module can control Nanoleaf lights from IP Symcon.

### Functions:

- On / off
- color selection
- hue
- saturation
- brightness
- color temperature
- set effect


## 2. Requirements

- IP-Symcon 7.0
- Nanoleaf

## 3. Installation

### a. Loading the Module

The module is installed via the module store.

### b.  Setup in IP-Symcon

In IP Symcon, under the category _Discovery Instances_, select _Instance_ (_right click -> add object -> instance_) and add __*Nanoleaf Discovery*__.
Then open the discovery instance, select a category under which the device should be created and create the device.

### c. Pairing with Nanoleaf

Follow these steps to set up your Nanoleaf device:

1. Press and hold the _Power On_ button on the Nanoleaf device for about 5-7 seconds until the LED next to the switch starts to flash. This process will allow the device to request a communication token.
2. After the LED starts to flash, push the _**Get Token**_ button in the _Nanoleaf_ instance.
3. If the token is successfully retrieved, it will appear in the configuration form of the instance.
4. As soon as a token is available, you will also have access to variables for switching the device.

## 4. Function reference

### Nanoleaf:  

Turn the Nanoleaf on and off. The brightness, hue, saturation and color temperature can be adjusted.
An effect can be set.

## 5. Configuration:

### Nanoleaf:

| Property |  Type   | Default Value |            Description            |
|:--------:|:-------:|:-------------:|:---------------------------------:|
|   Host   | string  |               | IP address of the Nanoleaf device |
|   Port   | integer |     16021     |    Port of the Nanoleaf device    |


## 6. Annex

###  a. Functions:

#### Nanoleaf:

`Nanoleaf_GetState(integer $InstanceID)`

Reads the current status

`Nanoleaf_GetAllInfo(integer $InstanceID)`

Reads all info from Nanoleaf and returns an array

`Nanoleaf_GetBrightness(integer $InstanceID)`

Reads the brightness

`Nanoleaf_GetHue(integer $InstanceID)`

Reads the color

`Nanoleaf_GetSaturation(integer $InstanceID)`

Reads the saturation

`Nanoleaf_GetColortemperature(integer $InstanceID)`

Reads the saturation

`Nanoleaf_GetEffects(integer $InstanceID)`

Returns the available effects


###  b. GUIDs and data exchange:

#### Nanoleaf:

GUID: `{09AEFA0B-1494-CB8B-A7C0-1982D0D99C7E}` 

