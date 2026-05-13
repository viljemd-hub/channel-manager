🟦 4) CRON nastavitev (na serverju)

Odpri CRON:

sudo crontab -e


Dodaj:

0 */4 * * * curl -s https://tvoja-domena.com/app/admin/api/cron_reviews_autocheck.php >/dev/null 2>&1


To pomeni:

✔ vsakih 4h
✔ preveri, če je pretekel 48h rok
✔ auto-moderira
✔ brez vpliva na spanje
✔ nič se ne logira v cron output
