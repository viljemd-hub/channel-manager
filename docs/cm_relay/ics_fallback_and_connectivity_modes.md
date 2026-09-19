# ICS fallback & connectivity modes — design notes

> Sklic: `CM_Ecosystem_Community_AI_ICS_Addendum_2026-08-10.md` §8–23, §29–31.
> Status: design only, razen enega že preverjenega dejstva (glej spodaj).
> Ni nobene OTA API kode v CM danes — obstaja samo ICS import/export
> (`common/lib/ics_builder.php`, `ics_import.php`, `ics_parse.php`,
> `ics_sources.php`, `ics_utils.php`). Ta dokument popisuje ciljni model za
> Fazo B/D, ne spreminja obstoječega ICS toka.

## Trdno PM pravilo (§31)

> **ICS fallback je trajna core capability vseh CM izdaj in ga nobena
> prihodnja API integracija ne sme odstraniti ali narediti odvisnega od
> centralnega Relayja.**

Ne glede na CM Free/Plus/PRO, API enabled/disabled, Channex/Beds24/WuBook,
ali stanje plačila centralne storitve — ICS/iCal fallback ostane na voljo v
lokalnem CM. OTA API je nadgradnja povezljivosti, ne zamenjava osnovne
varnostne poti (§8).

## Že preverjeno dejstvo (2026-08-10)

`common/lib/ics_builder.php`, `ics_import.php`, `ics_parse.php`,
`ics_sources.php`, `ics_utils.php` (oba repoja) **nimajo nobene reference na
`cm_connector`**, in `cm_connector.php`/`cm_connector_events.php` nimata
nobene reference na `ics_*`. Zahteva "ICS engine je vedno lokalen, neodvisen
od Relayja" (§18/§30) je torej danes že resnično izpolnjena — to pravilo je
treba samo ohraniti pri vsaki prihodnji OTA API implementaciji, ne graditi na
novo.

```text
CM Core
  ├─ Reservation Engine
  ├─ Occupancy Engine
  ├─ ICS Engine       ← vedno lokalno, danes brez odvisnosti od Relayja
  └─ CM Connector      ← opcijski, brez odvisnosti ICS engine-a od njega
          │
          ▼
        Relay
```

## Zakaj (realen strah beta uporabnika, §9)

Če API preneha delovati zaradi napake, provider outage-a, poteklega/nenamerno
neplačanega računa ali napačne konfiguracije, osnovni sistem ne sme obstati:

```text
API deluje    → uporabi API kot primarno pot
API ne deluje → CM core ostane aktiven → ICS ostane na voljo kot varnostna pot
```

## ICS ni enak polnemu API-ju (§10)

ICS je safety/fallback transport za osnovno kontinuiteto koledarja in
rezervacijskih blokad. API lahko dodatno omogoča: rates, restrictions,
modifications, cancellations, messaging, mapping, promotions, status. Ne
obljubljati funkcionalne paritete ICS ↔ API — v UI/kodi mora biti to jasno
ločeno, ne prikazano kot enakovredno.

## Connectivity modes (ciljni enum, ni implementiran)

```text
ICS_ONLY                    -- brez centralnega API servisa; mora ostati dostopno tudi v Free
API_PRIMARY_ICS_STANDBY     -- priporočeni normalni način za API uporabnika
API_PRIMARY_ICS_SHADOW      -- napredni diagnostični način, ICS bere primerjalno
API_DEGRADED_ICS_ACTIVE     -- API health pade → ICS fallback aktiven
API_ONLY                    -- dovoljeno le če uporabnik zavestno izklopi ICS; nikoli edina arhitekturna možnost
```

## Health state enum (ciljni, ni implementiran)

```text
HEALTHY
DEGRADED
FALLBACK_ACTIVE
OFFLINE
SUSPENDED
NOT_CONFIGURED
```

Primer prihodnjega admin prikaza (§21):

```text
Booking.com API: HEALTHY        ICS fallback: ARMED
Airbnb API: DEGRADED            ICS fallback: ACTIVE   Last API success: 11:42   Last ICS sync: 11:47
```

`cm-relay-repo/src/status/README.md` sklicuje na ta enum kot mesto prihodnje
implementacije `GET /api/v1/connectivity/status`.

## Neplačilo Relay/API storitve (§12)

> Neplačilo ali začasna deaktivacija plačljivega Relay/API servisa ne sme
> onemogočiti lokalnega CM core-a ali lokalne ICS funkcionalnosti.

```text
CM core               = deluje
lokalne rezervacije   = delujejo
public booking        = deluje
admin koledar         = deluje
ICS engine            = ostane na voljo
paid API Relay        = suspended
```

To se sklada z obstoječim `cm_connector.php`: `enabled`/`services` so ločeni
od reservation/pricing/availability kode, ki nikoli ni odvisna od stanja
Connectorja.

## API/Relay outage — minimalno vedenje (§13)

1. zapiši health error,
2. ohrani local operations,
3. ne zavrzi pending outbound changes,
4. uporabi retry queue (glej `cm_connector_events.php` - idempotency že
   pripravljen za retry brez duplikacije),
5. če je nastavljen fallback, aktiviraj/ponudi ICS,
6. po obnovi API-ja izvedi reconciliation/full sync (glej spodaj).

## Cross-source dedupe: `external_reservation_identity` (§14–16)

Največje tehnično tveganje: ista rezervacija pride enkrat prek API in drugič
prek ICS. Ciljna shema (ni implementirana, glej
`cm-relay-repo/docs/data_model.md` → `external_reservation_links`):

```text
reservation_id
channel
api_external_id
ics_uid
arrival
departure
unit
source_first_seen
source_last_seen
canonical_source
fingerprint
```

Možni source-i: `manual, direct, inquiry, api_booking, api_airbnb,
ics_booking, ics_airbnb, external`.

Matcher (ciljno, ni implementiran) uporablja: known external reservation ID
→ ICS UID ↔ provider ID mapping → channel → unit → arrival/departure →
creation/update time → varne dodatne fingerprint elemente. **Gostovo ime ni
glavni identifikator.** Ob dvomu: `needs_review`, nikoli avtomatsko
ustvarjanje dvojne rezervacije.

## Source precedence (§16)

```text
local hard reservation > verified API reservation > verified ICS reservation > unverified external hint
```

Nižji source ne sme prepisati celotnega recorda višjega source-a.

## Availability safety ob konfliktu (§17)

Če pride do konflikta med viri, ima zaščita pred overbookingom prednost:

```text
API trenutno ne deluje + ICS pokaže zaseden termin + lokalni CM tega bookinga še nima
   → ustvari/označi fallback occupancy block + zahtevaj reconciliation
```

Nikoli ignoriranje dogodka.

## Sync-loop prevention (§20)

Potencialna zanka: OTA API → CM → CM ICS export → OTA → ICS import → CM.
Reservation/event objekti bodo morali poznati `origin`, `transport`,
`external_id`, `ics_uid`, `last_sync_provider`; sync engine bo moral znati
`ignore_echo`, `link_existing`, `do_not_republish_origin`.

## Recovery po obnovi API-ja (§22)

```text
FALLBACK_ACTIVE → API reachable → reconciliation → dedupe/link → full sync → API_PRIMARY_ICS_STANDBY
```

Nikoli ne preklopiti nazaj na API brez reconciliation koraka.

## Reconciliation (§23)

Vsak provider adapter bo dolgoročno podpiral `reconcileReservations()`,
`reconcileAvailability()`, `reconcileRates()`, `reconcileRestrictions()`
(glej `cm-relay-repo/src/providers/README.md`). MVP lahko začne samo z
reservations/occupancy.
