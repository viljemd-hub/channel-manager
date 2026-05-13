
# CHANNEL MANAGER – KONCEPTUALNI OKVIR  
**CM Contract v1.2 (Core + Edition Levels)**

**Namen:**  
Ta dokument določa konceptualni okvir Channel Managerja (v nadaljevanju CM): časovni model, zasedenost, ICS politiko, plačilne tokove ter razlikovanje med izdajami **CM Free**, **CM Plus** in **CM Pro**.

**Temeljno pravilo:**  
Če implementacija (koda, UI, cron, integracije) krši ta dokument, je implementacija napačna – ne obratno.

---

## 1. Časovni model

1. Vsi razponi so **nočitveni** (night-based).
2. Datumi so **end-exclusive**: razpon se vedno interpretira kot `[from, to)`, kar pomeni, da je zadnja noč `to - 1`.  
3. En dan v CM pomeni **eno noč bivanja**, ne “koledarski dan”.
4. Ta model se dosledno uporablja v:
   - zasedenosti (occupancy),
   - rezervacijah,
   - ICS importu / exportu,
   - public in admin koledarju.

---

## 2. Source of Truth in podatkovni model

### 2.1 Zasedenost

1. **`occupancy_merged.json`** je *edini* vir resnice za:
   - admin koledar,
   - varnostne preglede (SSG guard),
   - autopilot.  
2. Public koledar bere public-dovoljen derivat (npr. `occupancy.json`), vendar se vsi konflikti presojajo proti `occupancy_merged.json`.

### 2.2 Rezervacije in odpovedi

1. `/reservations` vsebuje **samo aktivne** rezervacije (hard).  
2. `/cancellations` vsebuje **vse preklicane** rezervacije.  
3. Odpoved vedno pomeni:
   - premik zapisa iz `/reservations` v `/cancellations`,
   - sprostitev zasedenosti,
   - regeneracijo `occupancy_merged.json`.  

---

## 3. Razredi zapisov v zasedenosti

Ni vsak vnos v zasedenosti rezervacija. Uporabljajo se naslednji razredi:

### 3.1 Hard reservation

- Primer: potrjena rezervacija, ICS booking.
- `lock = "hard"`.
- Vedno predstavlja realno zasedenost.
- **Export: DA** (ICS, kanali).  
- Blokira: public booking, admin akcije, autopilot.

### 3.2 Soft hold (procesni status)

- Namen: vmesni korak v procesu povpraševanje → rezervacija.
- `lock = "soft"`, tip `soft_hold` (ali enakovreden interni označevalec).
- Vedno ima **TTL** (trajanje, po katerem poteče samodejno).
- **Export: NIKOLI** (ne sme v ICS/kanale).  
- Blokira: public, admin, autopilot (dokler traja).  

### 3.3 Admin block

- Namen: ročna blokada s strani lastnika (osebna uporaba, remont, zaprto obdobje).
- `lock = "soft"`, `type = "admin_block"`.
- **Export: privzeto NE**, export je dovoljen samo, če je eksplicitno vklopljen (`export: true`) v nastavitvah.
- Blokira public/admin/autopilot enako kot rezervacija, dokler traja.

### 3.4 System block

- Namen: operativne blokade (čiščenje, vzdrževanje, tehnični stop).
- `lock = "soft"`, `type = "system_block"` (podtipi: npr. `cleaning`, `maintenance`).
- **Export: DA**, če je `export: true`.  
- Ni rezervacija, ampak fizična nedostopnost enote.
- Blokira public/admin/autopilot.  

---

## 4. Pravilo “hard > soft” in merge

1. Hard zapis ima vedno absolutno prednost pred katerimkoli soft zapisom.
2. Hard se ne sme:
   - degradirati v soft,
   - skriti ali vizualno “zmehčati”,
   - prepisati z admin akcijo,
   - izginiti v merge procesu.  
3. Funkcija regeneracije (npr. `cm_regen_merged_for_unit`) mora biti:
   - deterministična,
   - idempotentna,
   - ponovljiva.  
4. Pri mergeu:
   1. Hard reservations
   2. System blocks (exportable)
   3. Admin blocks
   4. Soft holds  
5. Soft segmenti, ki overlappajo hard, se v merged ne smejo pojaviti kot veljaven blok – **soft overlaps se izloči**.

---

## 5. ICS politika (IN/OUT)

### 5.1 ICS import (IN)

1. ICS import lahko:
   - doda nove hard rezervacije,
   - posodobi obstoječe ICS hard rezervacije.  
2. ICS import **nikoli ne briše lokalnih odločitev**:
   - ne odstrani soft_hold,
   - ne briše admin_block ali system_block.  
3. ICS je vir zasedenosti, ne absolutna avtoriteta nad CM – lokalne odločitve so višje na prioritetni lestvici.  

### 5.2 ICS export (OUT)

1. ICS / API export vedno vključuje:
   - hard rezervacije,
   - system blocks z `export: true`.  
2. ICS / API export **nikoli** ne vključuje:
   - soft_hold,
   - admin_block brez `export: true`.  

---

## 6. Varnost – UI in backend

### 6.1 UI varnost

1. UI ne sme dovoliti akcij (klik, drag, block/unblock) nad hard-lock segmenti.
2. Klik na hard je informativen (prikaz podatkov), brez mutacije.
3. UI je prva obrambna linija, ne edina – backend vedno preverja še enkrat.

### 6.2 Backend varnost (SSG guard)

1. Vsaka mutacija zasedenosti (`block`, `reserve`, `confirm`, `cancel`) mora preveriti konflikte proti `occupancy_merged.json`.  
2. Ob konfliktu mora akcijo zavrniti (fail-closed).  

---

## 7. Življenjski cikel soft_hold in regeneracija

1. `soft_hold` nastane:
   - ob “accept inquiry”,
   - ali ob uporabi “accept linka” v e-pošti.  
2. `soft_hold` preide v hard le ob potrditvi rezervacije (guest/admin, ali autopilot).
3. Če ni potrditve, `soft_hold` poteče (TTL) ali se prekliče.
4. Po vsaki spremembi zasedenosti (accept/confirm/cancel/ICS pull/TTL sweep) je regeneracija `occupancy_merged.json` **obvezna**.

---

## 8. Produktne izdaje: CM Free, CM Plus, CM Pro

### 8.1 Globalna nastavitev

1. V `site_settings.json` obstaja blok:

```json
"product": {
  "tier": "free" | "plus" | "pro",
  "version": "1.x.y"
}
```

2. To je edini vir resnice, v katerem modu CM deluje. Druge nastavitve (licence, features) se morajo z njim ujemati.  

---

### 8.2 CM Free

CM Free je osnovna, distribucijska izdaja.

1. Dovoljeni načini plačila:
   - `payment.methods = ["at_desk"]` (brez SEPA).  
2. Ni aktivnega upravljanja z roki plačila:
   - brez `bonus_deadline` in `payment_deadline` logike,
   - brez cron “enforcement” procesov.
3. Autopilot je izklopljen:
   - `autopilot.enabled = false`.
4. Vsa core pravila (časovni model, hard/soft, ICS IN/OUT, cancel flow) veljajo v celoti.

---

### 8.3 CM Plus

CM Plus razširi Free z naprednejšimi plačili in delno avtomatizacijo.

**Osnovni paket CM Plus v1.0:**

1. Plačilne metode:
   - `payment.methods` lahko vključuje `"sepa"` (tipično: `["at_desk", "sepa"]`).  
2. SEPA rezervacije:
   - `payment.method = "sepa"`,
   - začetni `payment.status = "awaiting_payment"`,
   - rezervacija je **hard** (zasedenostno) takoj po potrditvi,
   - export je odvisen od `lock`, ne od `payment.status` (overbook zaščita navzven).  
3. Early-payment bonus:
   - dodatni popust za zgodnje plačilo, konceptualno zasnovan tako, da:
     - TT ostane ločena postavka,  
     - prihranek se lahko opisuje kot “pokritje TT” ali “KEYCARD-style” nagrada,
     - bonus je finančno realen (znižanje cene nastanitve), TT pa je še vedno obračunana transparentno.  
   - Prikaz:
     - `full price` (brez bonusa),
     - `early payment price`,
     - `prihranek` (razlika), jasno označen.  
4. Deadline logika v Plus:
   - v `finalize_reservation.php`/modelu se računajo:
     - `bonus_deadline_at` (do kdaj velja bonus),
     - `payment_deadline_at` (do kdaj mora biti plačano).
   - V v1.0 Plus je odziv na potek rokov še lahko **ročen**; cron za avtomatske akcije spada v Pro (glej 8.4).
5. Autopilot (basic mode):
   - omogočen pri `tier = "plus" | "pro"` in `autopilot.enabled = true`;
   - uporablja filtre:
     - `min_days_before_arrival`,
     - `max_nights`,
     - `allowed_sources` (npr. samo “website/direct”);  
   - pri sprejemu povpraševanja:
     - preveri pravila in lokalno zasedenost (`occupancy_merged.json`),
     - če je vse OK → izvede finalizacijo v eni potezi (hard rezervacija + maili + occupancy),
     - če ni OK → ne potrdi, temveč zahteva ročen poseg.
6. ICS hook v Plus:
   - **napredni ICS check (pull_now + regen)** pred autopilot potrditvijo je možnost, ne obveznost;
   - privzeto je ta hook izklopljen, dokler ni v celoti implementiran in pretestiran.  

---

### 8.4 CM Pro

CM Pro je razširitev nad Plus, namenjena resnejšim uporabi in domači “full-stack” rabi, vendar z mislijo na distribucijo (funkcije so opt-in).

**CM Pro v1.x lahko vključuje:**

1. Cron / “nočni paznik” za SEPA:
   - periodično obdeluje rezervacije z:
     - `payment.method = "sepa"`,
     - `payment.status = "awaiting_payment"`;
   - obnašanje:
     - po `bonus_deadline_at` deaktivira bonus (rezervacija ostane potrjena),
     - pred `payment_deadline_at` pošlje opomnike,
     - po `payment_deadline_at` izvede **auto-cancel**:
       - premik v `/cancellations`,
       - sprostitev zasedenosti,
       - regen `occupancy_merged.json`,
       - posodobitev exporta.  
   - Za druge uporabnike se cron dostavi kot samostojen skript + navodila (opt-in), ne kot zvezan sistemski daemon.
2. Naprednejši ICS hook:
   - možnost, da autopilot pred finalizacijo vedno:
     - sproži ICS pipeline (pull_now + regen),
     - nato preveri zasedenost proti sveže regeneriranemu merged,
     - v primeru konflikta potrditve zavrne (final truth check).  
3. Računi / računi-podobni PDF:
   - razširjen PDF z jasnimi postavkami (nočitve, TT, popusti, early bonus),
   - možnost “pro forma” izpisa.
4. Analitika in priprava na FURS:
   - zbiranje agregiranih podatkov (zasedenost, prihodki, kanali),
   - opcijski export v strukturi primerni za kasnejšo FURS integracijo.

---

## 9. Kuponi, zavrnitve in odpovedi

1. Avtokupon se lahko generira ob **vsebinskih** zavrnitvah povpraševanja (npr. polno, politika), ne pa ob tehničnih napakah ICS / pipeline.  
2. Kupon je vezan na identiteto gosta (najmanj e-mail) in ima `expires_at`.
3. Stati:
   - **applied** – uporabljen pri izračunu ponudbe, še ne “porabljen”,
   - **redeemed** – porabljen šele, ko je nova rezervacija hard confirmed.
4. Če proces faila pred hard (TTL izteče, konflikt pri final truth checku…), kupon ostane neporabljen.
5. Odpoved po hard potrditvi kupona ne reaktivira – razen, če obstaja eksplicitna politika ali admin akcija “restore coupon”.

---

## 10. Status dokumenta in nadaljnje spremembe

- Verzija: **CM Contract v1.2 (Core + Editions + Payments)**.  
- Ta verzija nadomešča prejšnje osnutke (v1.0, v1.1), vendar:
  - ohranja **enako jedro** glede časovnega modela, zasedenosti, ICS politike in merge pravil,  
  - dodatno natančno definira **razlikovanje med Free / Plus / Pro**, SEPA/bonus semantics, ICS hook in cron.  
- Spremembe se dokumentirajo z inkrementom verzije (v1.3, v1.4, …), pri čemer:
  - jedro (poglavja 1–7) ostane čimbolj stabilno,
  - dodatki za nove funkcije (plačilni tokovi, integracije, analitika) se dodajajo kot razširitve (novi pododdelki ali dodatki).
