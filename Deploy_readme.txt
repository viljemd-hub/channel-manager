# 1) wizard page
install -m 0644 /path/to/opening_wizard.php /var/www/html/app/admin/opening_wizard.php

# 2) api endpoint
install -m 0644 /path/to/first_use_init.php /var/www/html/app/admin/api/first_use_init.php

# 3) ics preview
install -m 0644 /path/to/ics_preview.php /var/www/html/app/admin/api/integrations/ics_preview.php

# perms (po tvojem modelu: www-data naj bere/piše common/data)
sudo chown -R www-data:www-data /var/www/html/app/common/data/json
sudo find /var/www/html/app/common/data/json -type d -exec chmod 775 {} \;
sudo find /var/www/html/app/common/data/json -type f -exec chmod 664 {} \;
 
