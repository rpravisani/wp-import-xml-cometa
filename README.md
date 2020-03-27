# wp-import-xml-cometa
Script per importazione immobili da file xml generato dal gestionale terzo 'Cometa' all'interno di WordPress
Da usare esclusivamente con tema Houzez.
Per altre fonti o altri temi fare fork.
---
 Viene usato Hook per esecuzione tramite wp-cron che richiama la funzione principale che importa immobili da xml
 L'evento cron custom 'cc_custom_cron' può essere inserito tramite il plugin WP Crontrol.
 Visto il tempo che ci mette ad elaborare i dati conviene aggiungere la costante DISABLE_WP_CRON all'interno di wp-config.php ed impostarla su true cosicché non viene eseguito alcuna operazione di wp-cron all'accesso del sito, ma esclusivamente quando viene fatta un'esplicita chiamata a /wp-cron.php
 Usare dunque una pianficazione cron-jobs dal webserver per eseguire a scadenza regolare il wp.cron
