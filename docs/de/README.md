# IPSymconNanoleaf

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)  
2. [Voraussetzungen](#2-voraussetzungen)  
3. [Installation](#3-installation)  
4. [Funktionsreferenz](#4-funktionsreferenz)
5. [Konfiguration](#5-konfiguration)  
6. [Anhang](#6-anhang)  

## 1. Funktionsumfang

Mit dem Modul lässt sich ein Nanoleaf von IP-Symcon aus schalten.

### Funktionen:  

 - Ein/Aus 
 - Farbauswahl
 - Farbton
 - Sättigung
 - Helligkeit
 - Farbtemperatur
 - Effekt setzen
	  
## 2. Voraussetzungen

 - IP-Symcon 7.0
 - Nanoleaf

## 3. Installation

### a. Laden des Moduls

Das Modul wird über den Modul Store installiert.

### b. Einrichtung in IP-Symcon
	
In IP-Symcon unterhalb der Kategorie _Discovery Instances_ nun _Instanz hinzufügen_ (_Rechtsklick -> Objekt hinzufügen -> Instanz_) wählen und __*Nanoleaf Discovery*__ hinzufügen.
Anschließend die Discovery Instanz öffnen, eine Kategorie auswählen, unter der das Gerät angelegt werden soll und das Gerät erzeugen.

### c. Pairen mit Nanoleaf

Folgen Sie diesen Schritten, um Ihr Nanoleaf-Gerät einzurichten:

1. Drücken und halten Sie die Taste _Power On_ am Nanoleaf-Gerät etwa 5-7 Sekunden lang, bis die LED neben dem Schalter zu blinken beginnt. Dieser Vorgang ermöglicht es dem Gerät, ein Kommunikations-Token anzufordern.
2. Nachdem die LED zu blinken beginnt, drücken Sie die Taste _**Get Token**_ in der Instanz _Nanoleaf_.
3. Wenn der Token erfolgreich abgerufen wurde, erscheint er in der Konfigurationsmaske der Instanz.
4. Sobald ein Token verfügbar ist, haben Sie auch Zugriff auf Variablen zum Schalten des Geräts.

## 4. Funktionsreferenz

### Nanoleaf:

Es kann die Nanoleaf ein- und ausgeschaltet werden. Die Helligkeit, der Farbton, die Sättigung und die Farbtemperatur können verstellt werden.
Es kann ein Effekt eingestellt werden.


## 5. Konfiguration:

### Nanoleaf:

| Eigenschaft | Typ     | Standardwert | Funktion                                  |
| :---------: | :-----: | :----------: | :---------------------------------------: |
| Host        | string  |              | IP Adresse des Nanoleaf                   |
| Port        | integer |    16021     | Port des Nanoleaf                         |



## 6. Anhang

###  a. Funktionen:

#### Nanoleaf:

`Nanoleaf_GetState(integer $InstanceID)`

Liest den aktuellen Status aus

`Nanoleaf_GetAllInfo(integer $InstanceID)`

Liest alle Infos vom Nanoleaf und gibt ein Array zurück

`Nanoleaf_GetBrightness(integer $InstanceID)`

Liest die Helligkeit aus

`Nanoleaf_GetHue(integer $InstanceID)`

Liest den Farbton aus

`Nanoleaf_GetSaturation(integer $InstanceID)`

Liest die Sättigung aus

`Nanoleaf_GetColortemperature(integer $InstanceID)`

Liest die Farbtemperatur aus

`Nanoleaf_GetEffects(integer $InstanceID)`

Gibt die verfügbaren Effekte aus


###  b. GUIDs und Datenaustausch:

#### Nanoleaf:

GUID: `{09AEFA0B-1494-CB8B-A7C0-1982D0D99C7E}` 