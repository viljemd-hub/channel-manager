CM PLUS – QUICK DEPLOY (NO GIT)

1. UPLOAD
- Upload entire "app" folder to your server (e.g. public_html/)
- Final structure must be:
  public_html/app/admin
  public_html/app/public
  public_html/app/common

2. OPEN IN BROWSER
- Public: https://your-domain/app/public/
- Admin:  https://your-domain/app/admin/

3. PERMISSIONS (IMPORTANT)
Make sure these folders are writable:
- app/common/data/
- app/common/data/json/
- app/logs/
- app/public/data/

(Recommended: 775)

4. FIRST SETUP
- Open /app/admin/
- Set your admin key in:
  app/common/data/admin_key.txt

- Edit:
  app/common/data/json/site_settings.json
  (email, name, etc.)

5. TEST FLOW
- Open public calendar
- Send test inquiry
- Confirm in admin
- Check email / PDF

6. NOTES
- Coupon system = WORK IN PROGRESS
- Reviews system initialized (empty JSON)
- No database required (JSON-based system)

Developer: viljem.d@gmail.com 
