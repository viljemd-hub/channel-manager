# CM Relay API Contract — DRAFT

> **Status: DRAFT.** CM Relay še ne obstaja kot živ servis. Ta dokument je konceptualna
> osnova za Fazo B/C (glej `CM_Relay_OTA_arhitektura_2026-08-10.md`), ne dokončan URL contract.
> Nobena funkcija v `common/lib/cm_connector.php` trenutno ne kliče teh endpointov.

## Namen

CM Relay je centralni, provider-agnostic connectivity in registry servis za prostovoljno
vključene CM Free/Plus/PRO namestitve. CM lokalno ostane source of truth za rezervacije,
cene, availability in restrictions — Relay je transportni sloj, ne drug PMS.

## Konceptualni endpointi

```text
POST /api/v1/installations/register
POST /api/v1/installations/heartbeat

POST /api/v1/commands
GET  /api/v1/events?cursor=<cursor>
POST /api/v1/events/{event_id}/ack

GET  /api/v1/connectivity/status
POST /api/v1/connectivity/full-sync

POST /api/v1/mappings
GET  /api/v1/mappings

POST /api/v1/disconnect
```

## Normalized event / command model (MVP obseg)

```text
pushAvailability()
pushRates()
pushRestrictions()
pushInventory()
fullSync()

reservationCreated()
reservationModified()
reservationCancelled()

connectionStatus()
mappingChanged()
providerError()
```

Kasneje (izven MVP): `messageReceived()`, `messageSent()`, `reviewReceived()`, `promotionChanged()`.

## Event shape (Relay → CM)

```json
{
  "id": "evt_84722",
  "installation_id": "cm_xxxxx",
  "type": "reservation.created",
  "channel": "booking",
  "property_id": "cm_property_1",
  "unit_id": "A1",
  "occurred_at": "2026-08-10T08:00:00Z",
  "payload": { "...": "normalized reservation payload" }
}
```

Po uspešni obdelavi: `POST /api/v1/events/{id}/ack`.

## Idempotency / zanesljivost

Vsak dogodek ima:

```text
provider_event_id
relay_event_id
installation_id
idempotency_key
created_at
delivery_attempts
next_retry_at
acknowledged_at
status  -- received | normalized | queued | delivered | acknowledged | failed | dead_letter
```

Webhook retry NIKOLI ne sme ustvariti duplicate reservation na lokalni CM strani — glej
lokalno implementacijo `common/lib/cm_connector_events.php` (idempotency po `idempotency_key`).

## Security minimum (za Fazo C implementacijo)

- HTTPS only
- ločen token/credentials na installation, z revoke/rotate
- provider credentials samo na Relayju, nikoli v CM Free source-u
- webhook signature validation, kjer provider podpira
- rate limiting, audit log, encryption secretov v mirovanju

## Retencija

Relay je transportni sistem, ne rezervacijski arhiv. Registry se hrani dokler je
namestitev priključena + zakonska/audit doba. Connectivity event payload se hrani samo
toliko časa, kot je potrebno za delivery/retry/reconciliation/support, nato izbriše ali
močno minimizira. Guestbook/PII podatki v CM Relay NE sodijo.
