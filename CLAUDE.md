# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Überblick

Symcon-Modulbibliothek zum Steuern von Nanoleaf-Leuchten über die lokale Nanoleaf-OpenAPI
(HTTP, Standardport 16021). Unterstützt werden zwei Gerätefamilien mit unterschiedlichem
API-Umfang (siehe „Zwei API-Familien"):

- **Light Panels** (Aurora, Canvas `nl29`, Shapes `nl42`) — die vollständige OpenAPI.
- **Matter over WiFi** (z. B. Smart Multicolor Ceiling Light `NL77K1`) — eine reduzierte API.

Zwei Module in einer Bibliothek (`library.json`, GUID `{DD72D758-…}`):

| Verzeichnis | Klasse | Typ | GUID |
| --- | --- | --- | --- |
| `Nanoleaf/` | `Nanoleaf` | 3 (Device) | `{09AEFA0B-1494-CB8B-A7C0-1982D0D99C7E}` |
| `Nanoleaf Discovery/` | `NanoleafDiscovery` | 5 (Discovery) | `{8F9CA22B-7F62-A099-10EF-EE6477B93246}` |

## Entwicklung

Es gibt kein Build-/Test-Framework (kein composer, kein PHPUnit, kein CI-Workflow). Ohne Symcon
laufen nur `php -l` auf beiden `module.php` und eine JSON-Prüfung von `library.json`,
`module.json` und den `locale.json`.

Getestet wird real gegen ein Gerät bzw. über die Mock-Datei (siehe unten); Debug-Ausgaben landen
per `SendDebug` im Symcon-Debug-Fenster der Instanz. Eine geänderte Bibliothek liest
`MC_ReloadModule` ohne Kernel-Neustart ein.

### Mock-Modus — wichtigste Stolperfalle

Beide Module prüfen `file_exists(__DIR__ . '/../tests/Mocks')`. **Existiert die Datei, wird nicht
mit dem Gerät gesprochen**, sondern es werden die Antworten aus `tests/Mocks` (JSON mit den Keys
`devices`, `token_response`, `GetAllInfo_response`) zurückgegeben — betroffen sind
`Nanoleaf::GetAllInfo()`, `Nanoleaf::getToken()` und `NanoleafDiscovery::mSearch()`.
`tests/` ist bewusst **nicht committet** (nur lokal vorhanden). Vor Live-Tests die Datei
umbenennen/entfernen, sonst sieht man ausschließlich Mock-Daten.

Der Mock deckt nur diese drei Stellen ab: Einzelabfragen wie `state/on` gehen auch im Mock-Betrieb
per curl ans Gerät.

## Zwei API-Familien

Nanoleaf pflegt für die Matter-over-WiFi-Geräte eine **eigene, kleinere OpenAPI** (gleicher Port,
gleicher Token-Flow). Die Unterschiede bestimmen an mehreren Stellen den Code:

| | Light Panels | Matter over WiFi |
| --- | --- | --- |
| `GET /api/v1/<token>/` | vollständig, mit `state`, `effects`, `panelLayout` | **nur Stammdaten** (`name`, `serialNo`, `manufacturer`, `firmwareVersion`, `hardwareVersion`, `model`) |
| Zustand lesen | aus der Gesamtabfrage | einzeln über `state/on`, `state/brightness`, `state/hue`, `state/sat`, `state/ct`, `state/colorMode` |
| Effektliste | `GET effects/effectsList` | `PUT effects` mit `{"write":{"command":"requestAll"}}` → `animations[].animName` |
| Schalten | `PUT state`, `PUT effects` | identisch |
| `panelLayout`, `identify`, `globalOrientation` | vorhanden | **nicht vorhanden** |
| Discovery | SSDP | **kein SSDP**, nur mDNS `_nanoleafapi._tcp` |
| Token freigeben | Gerätetaste 5–7 s halten | in der Nanoleaf App: Geräteeinstellungen → „Connect to API" (30 s Fenster) |

## Architektur

### Discovery → Device

`NanoleafDiscovery::GetConfigurationForm()` startet die Suche **nicht synchron**: es setzt den
Buffer `SearchActive`, registriert einen `RegisterOnceTimer` auf
`IPS_RequestAction($_IPS['TARGET'], 'loadDevices', '')` und liefert sofort das Formular mit einer
ProgressBar zurück. `loadDevices()` füllt später per `UpdateFormField('configurator', 'values', …)`
nach und blendet die ProgressBar aus. Neue Formularaktionen dieses Moduls laufen deshalb über
`RequestAction`, nicht über `onClick`-Funktionsaufrufe.

Die Suche selbst nutzt den **SSDP-Splitter** (`YC_SearchDevices` auf der Instanz mit ModulID
`{FFFFA648-B296-E785-96ED-065F7CEE6F29}`, `ssdp:all`) und filtert auf die Service-Types
`nanoleaf_aurora:light`, `nanoleaf:nl29`, `nanoleaf:nl42`. Eine neue Panels-Generation braucht hier
einen zusätzlichen ST-Eintrag in `mSearch()` — **Matter-over-WiFi-Geräte erreicht man so nicht**,
sie antworten nicht auf SSDP und werden von Hand angelegt.
Zuordnung vorhandener Instanzen im Configurator erfolgt über `host` + `port`, nicht über die UUID.

### Nanoleaf-Device

- **Alle** HTTP-Aufrufe gehen durch `SendCommand(['command' => …, 'commandvalue' => …])`. Dort
  entscheidet eine lange `if/elseif`-Kette über URL-Suffix, Requesttype und Postfields. Ein neues
  Gerätekommando wird genau dort ergänzt, nicht als eigener curl-Aufruf. Der Aufruf läuft nach
  3 s (Connect) bzw. 8 s (gesamt) in einen Timeout, damit ein stummes Gerät den Timer nicht blockiert.
- **Antworten nie ungeprüft dekodieren.** `decodeResponse()` schreibt eine fehlende oder ungültige
  Antwort ins Debug und liefert `null`; `readValueFromEndpoint()` liest daraus den `value` eines
  Einzelendpunkts. Beide sind der Weg für jede neue Leseoperation — ein direktes
  `json_decode(..., JSON_THROW_ON_ERROR)` auf eine Geräteantwort ist ein Fehler, weil offline oder
  abweichende Geräte sonst den Timer-Aufruf sprengen.
- **`GetAllInfo()`** liest `state` defensiv; fehlt das Objekt, werden die sechs Einzelendpunkte
  abgefragt (`readValueFromEndpoint()`, `readColorMode()`). Statusvariablen werden nur bei
  vorhandenem Wert gesetzt, damit ein teilweise antwortendes Gerät keine Werte überschreibt.
- **Token-Flow:** Button `btnGetToken` → `getToken()` POSTet auf `/api/v1/new` → Token landet im
  Attribut `newToken`, ein `PopupAlert` fragt nach → `btnSaveToken` → `saveToken()` schreibt Attribut
  `token`. Das Zeitfenster öffnet je nach Gerät die Taste oder die App (siehe „Zwei API-Familien").
  Ohne Token liefert `SendCommand()` sofort `false`.
- **Instanzstatus** (`SetInstanceStatus()`): 201 = Host ungültig, 202 = Port ungültig,
  203 = Token nicht gesetzt, sonst `IS_ACTIVE`. Der Update-Timer wird in allen Fehlerfällen auf 0
  gesetzt; erst bei `IS_ACTIVE` läuft `Nanoleaf_GetAllInfo()` im Intervall (Property
  `UpdateInterval`, Sekunden). Formularelemente sind über `$isActive`/`$tokenNotSet` ein-/ausgeblendet.
- **Kernel-Readiness:** `ApplyChanges()` bricht ab, solange `IPS_GetKernelRunlevel() !== KR_READY`;
  `Create()` registriert `IPS_KERNELMESSAGE`, `MessageSink` ruft `ApplyChanges()` nach.
- **Schalten läuft über `RequestAction`** (alle Statusvariablen sind per `EnableAction` freigegeben);
  dieselbe Methode bedient zusätzlich die Button-Idents `btnGetToken`, `btnSaveToken`,
  `btnUpdateEffectProfile`. Öffentliche Funktionen sind nur Lese-/Sonderfälle
  (`GetAllInfo`, `GetInfo`, `GetState`, `GetColortemperature`, `ColorMode`, `GetEffects`,
  `ListEffect`, `Layout`, `Identify`, `Get/SetGlobalOrientation`).

### Farb- und Effektlogik

- Statusvariablen: `State`, `color` (`~HexColor`), `hue` (Profil `Nanoleaf.Hue`), `saturation`,
  `Brightness` (`~Intensity.100`), `colortemperature` (Profil `Nanoleaf.Colortemperature`), `effect`.
- Das Gerät kennt **kein** RGB: `setColor()` zerlegt den Hex-Wert per `HEX2HSV` und sendet drei
  Einzelkommandos (Hue/Sat/Brightness); umgekehrt setzt `SetValueColor()` nach jeder dieser
  Änderungen die `color`-Variable aus den drei Werten wieder zusammen. Änderungen an einer Seite
  immer in der anderen nachziehen.
- Das Effektprofil heißt **pro Instanz** `Nanoleaf.Effect<InstanceID>` und wird aus der vom Gerät
  gemeldeten Effektliste aufgebaut; Effektwert = Position+1. Quelle ist `effects/effectsList`
  (`ListEffect()`); bleibt die leer, greift `listEffectsViaCommandApi()` auf die Command API zurück.
  `getEffectAssociations()` liefert bewusst `[]`, solange die Instanz nicht `IS_ACTIVE` ist —
  dann entsteht ein Profil ohne Assoziationen statt falscher Defaults
  (`DEFAULT_EFFECT_ASSOCIATIONS` ist nur noch Referenz und ungenutzt).
  Der Button „Update Effects" (`btnUpdateEffectProfile`) aktualisiert die Assoziationen nachträglich.

### Übersetzungen

Formular- und Variablentexte werden **englisch** im Code geschrieben und sind die Schlüssel in
`Nanoleaf/locale.json` bzw. `Nanoleaf Discovery/locale.json` (nur `de`). Jeder neue Caption-Text
oder `$this->Translate(...)`-String braucht dort einen Eintrag — die Schlüssel müssen wörtlich
übereinstimmen (inkl. Satzzeichen und `%s`).

## Konventionen

- Bei jeder inhaltlichen Änderung `library.json`: `build` +1, `date` auf aktuellen Unix-Timestamp;
  Commit-Subject `<version> build <NN>: <kurze Beschreibung>` (Historie folgt dem Muster, teils mit
  angehängten `neu:`/`korrigiert:`-Zeilen).
- `compatibility.version` steht auf 7.0 — nur anheben, wenn tatsächlich eine neuere Symcon-Version
  benötigt wird.
- Anwenderdoku liegt zweisprachig in `docs/de/README.md` und `docs/en/README.md`; Änderungen an
  Properties/Funktionen dort in **beiden** Dateien nachziehen.

## Bekannte Altlasten (beim Anfassen mitziehen)

- Beide Klassen erben von `IPSModule` (nicht `IPSModuleStrict`), Darstellungen laufen über
  Legacy-Profile (`IPS_CreateVariableProfile`) statt Presentations.
- Kein `.github/workflows/check.yml`, kein `tests/check_locale.php`; `.idea/` ist mit eingecheckt.
- `docs/*/README.md` nennt `Nanoleaf_GetBrightness/GetHue/GetSaturation` — diese öffentlichen
  Funktionen existieren im Code nicht (mehr).
- `Nanoleaf/module.php` enthält im Formular (`FormElements()`) ein ~30 kB langes Base64-Logo in
  einer einzigen Zeile; die Datei lässt sich deshalb nicht am Stück lesen — gezielt per
  Grep/`sed`-Bereich arbeiten.
