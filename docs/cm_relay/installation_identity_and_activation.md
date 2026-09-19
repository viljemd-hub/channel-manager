# CM Connector — installation identity & activation flow

> Sklic: `CM_Relay_OTA_arhitektura_2026-08-10.md` §4. Ta dokument opisuje trenutno
> (Faza A) in ciljno (Faza B/C) stanje aktivacijskega flowa.

## Trenutno stanje — Faza A (implementirano)

Vse spodnje se dogaja **izključno lokalno**, brez omrežnega klica:

1. Uporabnik odpre `admin/cm_connector.php` ("CM ekosistem / Connectivity").
2. Stran razloži podatkovni obseg, kaj se NE pošilja, in da CM deluje samostojno tudi
   brez povezave (glej §1.2–§1.3 brief-a).
3. Uporabnik potrdi 3 ločena soglasja (`cm_connector_save_consent()` v
   `common/lib/cm_connector.php`):
   - prebral podatkovni obseg,
   - strinja se z vključitvijo v CM ekosistem,
   - strinja se s pogoji/ceno.
4. Ob prvem shranjevanju soglasij se lokalno generira `installation_uuid`
   (`cm_connector_ensure_installation_uuid()`, UUID v4, `random_bytes`-based).
   **Relay tega UUID-ja ne pozna** — nikamor se ne pošlje.
5. Status gre v `pending_local`. Uporabnik lahko dodatno izpolni predlogo poslovnega
   dogovora (`cm_connector_save_agreement()`) — plan, cena, referenca pogodbe — ročno,
   dokler ni dejanskega entitlement sistema na Relayju.
6. `cm_connector_disconnect()` počisti soglasja/dogovor in vrne status na
   `not_connected`; `installation_uuid` ostane, da se ob ponovni aktivaciji ne
   generira nov.

## Ciljno stanje — Faza B/C (še ne implementirano)

```text
lokalni CM
   │
   ├─ ima installation_uuid (iz Faze A)
   ├─ pridobi one-time activation code (nov Relay endpoint)
   ▼
hosted CM Relay activation
   │
   ├─ uporabnik potrdi račun/storitev na Relay strani
   └─ Relay izda installation credentials (token, ne globalni master secret)
```

Ko to obstaja, `cm_connector_activate_with_relay()` (trenutno stub, vrne
`not_implemented_faza_b`) postane realna implementacija: pošlje `installation_uuid` +
activation code na `POST /api/v1/installations/register` (glej
`relay_api_contract.md`), prejme installation-specific token, shrani ga lokalno
(nikoli hardcodan globalni ključ), in status preide v `connected`.

Enako velja za `cm_connector_heartbeat()`, `cm_connector_pull_events()`,
`cm_connector_ack_event()`, `cm_connector_send_command()`, `cm_connector_full_sync()` —
vsi ostanejo stub, dokler Relay dejansko ne obstaja kot deployan servis.
