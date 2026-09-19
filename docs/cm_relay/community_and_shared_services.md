# CM Community & Shared Services — design notes

> Sklic: `CM_Ecosystem_Community_AI_ICS_Addendum_2026-08-10.md` §0–7, §24–27.
> Status: design only. Ni Community portala, ni AI query engina, ni javnega
> discovery API-ja. Barebone (CM Connector opt-in stikala + Relay skeleton
> mapna struktura) mora biti samo *ready for it*, glej §32.

## CM Relay nosi tri vzporedne storitve, ne samo OTA

```text
                         CM ECOSYSTEM
                              │
                              ▼
                           CM Relay
                              │
        ┌─────────────────────┼──────────────────────┐
        │                     │                      │
        ▼                     ▼                      ▼
 OTA Connectivity        CM Community          Shared Services
```

Zato Relay data model, auth, consent in installation registry ne smejo biti
zasnovani samo okoli OTA rezervacij — glej generične pojme v
`cm-relay-repo/docs/data_model.md` (installation, property, unit, service,
capability, entitlement, consent, public_projection, provider_adapter,
event, command).

## Vsaka storitev je ločen opt-in

CM Free/Plus/PRO ostajajo samostojni. V lokalnem CM Connectorju
(`admin/cm_connector.php`, fieldset "Ecosystem services") uporabnik neodvisno
izbira:

```text
[ ] CM Connectivity
[ ] CM Community
[ ] Sharing / referrals
[ ] AI-assisted discovery
```

Primer: uporabnik želi Booking/Airbnb API sync, a noče biti viden v Community
iskanju — to mora ostati legitimno. Implementirano kot 4 neodvisni booleani v
`cm_connector_save_services()` (`common/lib/cm_connector.php`), brez
soodvisnosti.

## CM Community — delovna definicija

> CM Community je mreža neodvisnih CM namestitev, ki prostovoljno delijo
> izbrane javne podatke in capabilities z drugimi člani oziroma z javnim
> discovery slojem.

Možne funkcije (prihodnost, ni implementirano): skupno iskanje prostih
nastanitev, referral ob polni zasedenosti, javni discovery portal,
regionalno iskanje, deljenje availability/cen, neposredna povezava na
booking/offer stran.

## Public projection (ne cela rezervacijska baza)

Community nikoli ne dobi neposrednega dostopa do lokalne rezervacijske baze.
Vsaka priključena nastanitev bi imela ločen, minimiziran `public_projection`:

```json
{
  "property_id": "prop_xxxxx",
  "display_name": "Apartma Example",
  "region": "Bled",
  "country": "SI",
  "capacity": { "max_guests": 6 },
  "amenities": ["parking", "ev_charging"],
  "public_booking_url": "https://example.si/app/public/",
  "community_enabled": true
}
```

Availability in cena bi bila ločena podatkovna sklopa, objavljena samo ob
izrecnem dovoljenju (`community.availability` / `community.public_price`
consent scope, glej §26).

## Sharing / referral capability (prihodnost)

```text
Apartma A je zaseden → uporabnik izbere "Poišči alternativo v CM Community"
→ Relay poišče ustrezne člane → prikaže proste nastanitve → gost dobi
referral povezavo
```

Možen event model (ni implementiran): `community.search`,
`community.referral.created`, `community.referral.opened`,
`community.referral.converted`.

## AI Discovery — trdno pravilo

AI ni source of truth:

```text
naravni jezik → strukturirana namera → Community Search API → realni CM podatki → rezultati
```

> **AI ne sme izmišljati availability, cen ali booking pogojev.** Če podatka
> ni, je rezultat `unknown`, ne ugibanje.

## Community deluje tudi brez OTA API-ja

Community membership ni isto kot API connectivity. OTA API entitlement ni
pogoj za Community — CM Free lahko postane član Community tudi brez
Channexa/Beds24/WuBook (glej doc §24). Zato je `ota_connectivity` in
`community` v `cm_connector_default_settings()` namenoma dva ločena ključa,
ne en skupen "API enabled" flag.

## Service/capability model namesto trdih edition preverjanj

```json
{
  "installation_id": "cm_xxxxx",
  "edition": "free",
  "services": {
    "relay_registry": true,
    "ota_connectivity": true,
    "community": true,
    "community_referrals": false,
    "ai_discovery": false
  }
}
```

To omogoča poljubne kombinacije (CM Free + Community, CM Plus + Connectivity
+ Community, ...) brez podiranja Relay API-ja ob dodajanju nove storitve.
