#!/usr/bin/env python3
import argparse, json, sys, os, re
from datetime import date, timedelta
from pathlib import Path

# --- helpers
def iso(d:date)->str: return d.isoformat()
def dfrom(s:str)->date: y,m,d = map(int, s.split("-")); return date(y,m,d)
def daterange(a:date,b:date):
    d=a
    while d<b:
        yield d
        d+=timedelta(days=1)

# --- classify ICS summary -> kind
BLOCK_PAT = re.compile(r"(block|not\s*available|clean|owner|maintenance|zapora|čiš|servis|internal)", re.I)

def classify_kind(summary:str)->str:
    if not summary: return "reservation"
    return "block" if BLOCK_PAT.search(summary or "") else "reservation"

# --- sources
def load_local(root:Path):
    """local_sources.json: [{"unit":"A1","start":"YYYY-MM-DD","end":"YYYY-MM-DD","kind":"reservation|block"}]"""
    p = root / "local_sources.json"
    if not p.exists(): return []
    items = json.loads(p.read_text())
    out=[]
    for r in items:
        k = r.get("kind") or "block"
        out.append({"unit": r["unit"], "start": r["start"], "end": r["end"], "kind": k})
    return out

def parse_ics(path:Path, unit:str, default_kind="reservation"):
    """very small ICS reader ( DTSTART;VALUE=DATE:YYYYMMDD .. DTEND;VALUE=DATE:YYYYMMDD, SUMMARY:.. )"""
    if not path.exists(): return []
    txt = path.read_text(errors="ignore")
    events=[]
    cur={}
    for line in txt.splitlines():
        line=line.strip()
        if line=="BEGIN:VEVENT":
            cur={}
        elif line=="END:VEVENT":
            if "DTSTART" in cur and "DTEND" in cur:
                s = cur["DTSTART"]; e = cur["DTEND"]
                s = f"{s[0:4]}-{s[4:6]}-{s[6:8]}"
                e = f"{e[0:4]}-{e[4:6]}-{e[6:8]}"
                summ = cur.get("SUMMARY","")
                kind = classify_kind(summ) if default_kind=="auto" else default_kind
                events.append({"unit":unit,"start":s,"end":e,"kind":kind})
        else:
            if line.startswith("DTSTART"): cur["DTSTART"]=line.split(":")[-1].strip()
            elif line.startswith("DTEND"): cur["DTEND"]=line.split(":")[-1].strip()
            elif line.startswith("SUMMARY"): cur["SUMMARY"]=line.split(":",1)[-1].strip()
    return events

def load_booking_ics(root:Path):
    # booking.ics – običajno samo "booked" → reservation
    out=[]
    cfg = root / "channels.json"
    if cfg.exists():
        ch=json.loads(cfg.read_text()).get("booking",{})
        if ch.get("enabled"):
            for it in ch.get("units",[]):
                out += parse_ics(Path(it["ics"]), it["unit"], default_kind="reservation")
    return out

def load_airbnb_ics(root:Path):
    # airbnb.ics – zna imeti "Not available" → block
    out=[]
    cfg = root / "channels.json"
    if cfg.exists():
        ch=json.loads(cfg.read_text()).get("airbnb",{})
        if ch.get("enabled"):
            for it in ch.get("units",[]):
                out += parse_ics(Path(it["ics"]), it["unit"], default_kind="auto")
    return out

# --- merge intervals (by unit & kind)
def merge_intervals(items):
    # items: [{unit,start,end,kind}]
    out=[]
    items=sorted(items, key=lambda r:(r["unit"], r.get("kind","reservation"), r["start"], r["end"]))
    buf=None
    for r in items:
        unit=r["unit"]; kind=r.get("kind","reservation")
        s=r["start"]; e=r["end"]
        if buf and buf["unit"]==unit and buf["kind"]==kind and s<=buf["end"]:
            if e>buf["end"]: buf["end"]=e
        else:
            if buf: out.append(buf)
            buf={"unit":unit,"kind":kind,"start":s,"end":e}
    if buf: out.append(buf)
    return out

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--root", default="/var/www/html/app/common/data/json")
    ap.add_argument("--months", type=int, default=12)
    ap.add_argument("--write", action="store_true")
    ap.add_argument("--verbose", action="store_true")
    args = ap.parse_args()

    root = Path(args.root)
    target = root / "occupancy.json"

    all_items=[]
    # LOCAL
    loc = load_local(root)
    if args.verbose: print(f"local: {len(loc)}")
    all_items += loc

    # booking
    all_items += load_booking_ics(root)
    # airbnb
    all_items += load_airbnb_ics(root)

    merged = merge_intervals(all_items)

    if args.write:
        target.write_text(json.dumps(merged, ensure_ascii=False, indent=2))
        if args.verbose: print(json.dumps({"ok":True,"written":str(target), "intervals":len(merged)}, ensure_ascii=False))
    else:
        print(json.dumps({"ok":True,"dry_run":True,"target":str(target),"intervals":len(merged)}, ensure_ascii=False))

if __name__=="__main__":
    main()
