<?php

define("CC_FORCE_UPDATE_META", false);
define("FILE_XML_IMMOBILI", "AnnunciCometa.XML");

// array con gli immobili da mettere in vetrina 
$invetrina = array();

/**
 * Hook per esecuzione tramite wp-cron della funzione principale che importa immobili da xml
 *
 * Evento cron custom inserito tramite il plugin WP Crontrol.
 * La costante DISABLE_WP_CRON all'interno di wp-config.php è impostata a true, ergo le funzioni wp-cron non vengono eseguite
 * all'accesso del sito, ma esclusivamente tramite un esplicita chiamata 
 * a /wp-cron.php (schedulata tramite cronjobs a livello webserver)
 */
add_action( 'cc_custom_cron', 'cc_controllo_nuovi_immobili' );

// STARTING POINT funzione genitore, controlla la presenza del file xml e se presente richiama funzione per elaborarlo
function cc_controllo_nuovi_immobili() {
	
	set_time_limit(1800); // 30 min
	
	// VARS
	$path = ABSPATH . "import/"; // path dove cercare file xml
	$files = array(); // contentitore dei file trovati
	$result = array(); // contenitore per risultato finale
	
	$file = FILE_XML_IMMOBILI; // nome del file xml da importare
	
	// se non è presente il file xml registro errore ed esco da operazioni cron
	if(!file_exists($path.$file)){		
		cc_import_error("Nessun file XML presente");		
		die("nessun file xml presente");
	}
	
	// azzero i contatori in configs.xml, scrivo file che viene elaborato e data/ora inizio
	cc_reset_stats($file); 

	// per monitorare tempi di esecuzione / debug
	$start = microtime(true);
	
	/* 
	   popolo array global $invetrina con gli immobili che devono essere messi in vetrina, 
	   che verrà utilizzata poi da cc_insert_record e cc_update_record
	 */
	cc_set_immobili_in_vetrina();	
	
	// richiama funzione che importa contenuti del file e li elabora (chiama a sua volta altre funzioni)
	$result = cc_import_xml_file($path.$file); // restituisce array 
	
	// per monitorare tempi di esecuzione
	$end = microtime(true);
	$diff = $end - $start;
	
	// se result è false cc_import_xml_file ha fallito, ovvero errore di elaborazione file xml (assente o mal formattato) 
	// L'email con l'errore è già stata inviata 
	if($result !== false){
				
		// scrivo data ultimo aggiornamento in file config -  ovvero data e ora attuale
		cc_update_configs("lastUpdate", date("Y-m-d H:i:s"));		
		
		// recupero riepilogo risultato
		$stats = cc_get_record_statistics();
		$dbg  = var_export($stats, true);
		$dbg2 = var_export($result, true);
		
		// chiudo dialgoin file log
		cc_import_immobili_error_log("-- FINE");
		
		// invio email di fine elaborazione
	 	cc_import_error("Elaborati correttamente ".$stats['total']." records in ".$diff." sec.\n\nStats:\n".$dbg."\n\nRecords:\n".$dbg2, "FINE ELABORAZIONE FILE XML");		
	}
		
}

/**
 * Recupera ed importa contenuto file xml in oggetto simpleXml e se non sono stati riscontrati errori elabora i dati
 * 
 * @return array con i dati degli immobili
 */
function cc_import_xml_file($file){
	
	// apro file ed elaboro xml	
	$xml = @simplexml_load_file($file);	

	if($xml){		
		// ok struttura xml valida, recupero tag root Offerte
		$offerte = $xml->Offerte;

		if($offerte){	
			// tutto ok procedo con elavorazione
			
			$nofferte = count($offerte); // numeri di record all'interno del file
			cc_update_configs("source", $nofferte); // aggiorno file di config con il numerod i record nel file xml
			
			/***
			 * Elabora oggetto xml
			 * Restituisce array con chiave Rif e valore "updated", "inserted" o false
			*/
			return cc_elabora_file_xml($offerte); // restriuisci array a cc_controllo_nuovi_immobili()		 
			
		}else{
			
			// E' un file xml ma con struttura errata (manca tag apertura Offerte) - invio email di notifica 
			cc_import_error("Nessuna offerta trovata nel file XML ".$file);
			return false; // ritorno a cc_controllo_nuovi_immobili()
			
		}
		
	}else{
				
		// Non è un file xml valido - invio email di notifica 
		cc_import_error($file." non è un file XML valido!");
		return false; // ritorno a cc_controllo_nuovi_immobili()
		
	} // end if xml		
	
}

/**
 * Elaboro records all'interno dell'oggetto xml
 * 
 * @param $offerte: oggetto simpleXml con all'interno i vari record
 * @return array con chiave Rif e come valore "inserted", "updated" o errore rilevato
 */
function cc_elabora_file_xml($offerte){
	
	global $wpdb; // oggetto interazione DB di WordPress
	
	// path dove si trova file xml sorgente, config.xml e file di log
	$path = ABSPATH . "import/";
	
	$results = $deleted = array(); 
	
	// apro file config (xml) da cui prendere data ultima elaborazione ed in cui memorizzare i risultati di elaborazione
	$config = @simplexml_load_file($path."configs.xml");
	if(!$config){
		// file config non trovato notifico e esco 
		cc_import_error("File config non trovato!");			
	}
		
	// recupero tutti gli id cometa già presenti in tabella postmeta. _id_cometa è un postmeta mio
	$record_presenti = cc_get_unique_post_meta_values("_id_cometa"); // restituisce array con chiave post_id
	
	// recupero da file config data ultimo aggiornamento - NON PIU' UTILIZZATO, uso data all'interno del file xml
	//$last_update = new DateTime( (string) $config->lastUpdate );
	
	// contatore record
	$line = 0;
	
	// loop offerte da xml sorgente
	foreach($offerte as $offerta){
		
		$line++;
		cc_import_immobili_error_log($line); // aggiorno log elaborazione con numero progressivo record 
		
		$idunique = (int) $offerta->Idimmobile; // campo univoco Cometa
		$rif = (string) $offerta->Riferimento; // Rif / codice immoible
		$contratto = (string) $offerta->Contratto; // il tipo di contratto (Vendita o Affitto)
		$hasfoto = (empty((string) $offerta->FOTO1)) ? false : true;
		
		cc_import_immobili_error_log($rif); // aggiorno log elaborazione con il rif immobile
		
		// non importo gli immobili in affitto e quelli senza foto
		if($contratto == "Affitto" or !$hasfoto){
			
			// aggiorno contatori in file config.xml. L'ultimo param in cc_update_configs indica di incrementare il valore già presente
			if($contratto == "Affitto") cc_update_configs("affitto", 1, true); // aggiorno contatore affitti
			if(!$hasfoto) cc_update_configs("nofoto", 1, true); // aggiorno contatore affitti
			
			// registro motivo perché salto importazione in file di log
			$whyskipped = "Saltato perche contratto ".$contratto;
			if(!$hasfoto) $whyskipped .= " e non ha foto";
			cc_import_immobili_error_log($whyskipped);
			continue; // passo al prossimo record immobile
		} 
		
		// Recupero ultima data modifica dell'immobile su Cometa
		$DataAggiornamento = (string) $offerta->DataAggiornamento;
		$DataAggiornamento = substr($DataAggiornamento, 0, -6); // elimino +1:00 da valore data se no non è allineato con tempo server		
		$data_ultima_modifica_record = new DateTime( $DataAggiornamento );	// creo oggetto DateTime							
				
		// controllo se l'id del record è già presente in DB e decido dunque se richiamare funzione di insert o update
		if(in_array($idunique, $record_presenti)){
						
			// è già presente in db - richiamo funzione che aggiorna record passando id tabella posts e oggetto xml dell'offerta
			$post = array_search($idunique, $record_presenti); // recupero chiave array che è post_id
			
			// recupero data ultimo aggiornamento record in tabella posts
			$md = get_the_modified_time( "Y-m-d H:i:s", $post );
			$post_last_modified =  new DateTime( $md );
			
			// se la data dell'ultimo aggiornamento record in posts è maggiore della data di modifica in Cometa 
			// aumento contatore skipped, tolgo id da elenco record da cancellare e passo al record successivo
			if($post_last_modified >= $data_ultima_modifica_record){
				
				// rimuovo immobile da elenco già presenti - a fine lavoro quelli rimasti verranno eliminati da DB
				unset($record_presenti[$post]); 
				
				// aggiorno log e contatore in config.xml
				$msg_skipped = "Record ".$post." DATA RECORD XML: ".$data_ultima_modifica_record->format("Y-m-d H:i:s");
				$msg_skipped .= " DATA RECORD IN POSTS: ".$post_last_modified->format("Y-m-d H:i:s");
				cc_import_immobili_error_log("skipped");
				cc_update_configs("skipped", 1, true);// incremento valore di skipped di uno
				continue; // il record non è stato modificato, passo al prossimo record
				
			}
			
			/**
			 * Richiamo funzione cc_update_record che aggiorna record in DB
			 * 
			 * @param $post: post_id in tabella postmeta
			 * @param $offerta: oggetto xml del singolo immobile
			 * @return string con valore "updated" o errore rilevato
			 */
			$results[$rif] = cc_update_record($post, $offerta); 
			
			// elimino da record_presenti questo record. quelli che alla fine rimangono verranno cancellati
			unset($record_presenti[$post]); 
			
		}else{
			/**
			 * Record nuovo. Richiamo funzione cc_insert_record che inserisce un nuovo record in DB
			 * 
			 * @param $offerta: oggetto xml del singolo immobile
			 * @return string con valore "inserted" o errore rilevato
			 */
			$results[$rif] = cc_insert_record($offerta);
			// aggiungo dicitura inserted in file log per indicare che il record è stato inserito con successo
			cc_import_immobili_error_log("inserted"); 

		}
		
	} // end foreach $offerte - finito elabroazione di tutti i record presenti nel file xml
	
	/* Procedura di cancellazione immobili non più presenti
	 * Se l'array record_presenti non è vuoto vuol dire che uno o più immobili attualmente in db non sono più
	 * presenti su Cometa esportazione ergo sono da cancellare da db
	 *
	 * @param $id2delete: id record in wp_posts
	 * @param $id_cometa: id univoco Cometa, non utilizzato in questo frangente
	 */	
	if(!empty($record_presenti)){
		// loop record
		foreach($record_presenti as $id2delete => $id_cometa){
			// uso funzione di WP per cancellare record da posts
			$deleted = wp_delete_post( $id2delete, false ); // 2° param indica se il record dev'essere spostato in cestino (false) o se dev'essere cancellato (true)
			cc_update_configs("deleted", 1, true); // incremento contatore deleted di 1 in config.xml
		}
		// aggiorno log con elenco degli immobili cencellati
		$msg = implode(", ", $record_presenti);
		cc_import_immobili_error_log("Deleted: ".$msg);
	}
	
	/* Restituisci a cc_import_xml_file() array con chiave Rif e come valore "inserted", "updated" 
	 * o errore rilevato (risultato di cc_insert_record() o cc_update_record() )
	*/
	return $results; 
	
} // end cc_elabora_file_xml


/**
 * Aggiorno record immobile in DB (tabella posts)
 * 
 * @param $post_id: post_id in tabella postmeta
 * @param $offerta: oggetto xml del singolo immobile
 * @return string con valore "updated" o errore rilevato
 */
function cc_update_record($post_id, $offerta){	
	
	/*** 1. CONTROLLO / UPLOAD IMMAGINI ***/
	// raccolgo in array $foto le url delle foto dafile XML
	$foto = array();
	for($nf = 1; $nf < 16; $nf++){		
		$foto_field = "FOTO".$nf;
		$foto_url   = (string) $offerta->$foto_field;
		if(!empty($foto_url)) $foto[] = $foto_url;		
	}
	
	// inserisce solo se le foto non sono ancora presenti, restituisce array con id posts delle foto nuove
	$foto_nuove = cc_import_images($foto, $post_id, true);
	
	/*** 2. AGGIORNAMENTO POSTMETA ***/
	
	// recupero indirizzo attuale (potrebbe averlo aggiunto in wordpress, in tal caso non sovrascriverlo)
	$oldAddress = get_post_meta( $post_id, 'fave_property_address', true );
	
	$map_address = $indirizzo = "";
	if(!empty($offerta->Indirizzo)){
		$map_address .= (string) $offerta->Indirizzo;
		$indirizzo   .= (string) $offerta->Indirizzo;
	}
	if(!empty($offerta->NrCivico)){
		$map_address .= (string) " ".$offerta->NrCivico;
		$indirizzo   .= (string) " ".$offerta->NrCivico;
	}
	
	if(!empty($offerta->Comune) and !empty($map_address)){
		
		$comune = (string) $offerta->Comune;
		$comune = trim($comune);
		$map_address .= ", ";
		
		$cp = cc_get_cap_prov($comune);
		if(!empty($cp)) $map_address .= $cp['cap']." "; 		
		
		$map_address .= $comune;
		if(!empty($cp)) $map_address .= " (".$cp['prov'].")"; 
		
	}else{
		
		$cp = array();
		
	}
	
	$map_address = trim($map_address);
	if($map_address[0] == ',') $map_address = substr($map_address, 2);
	
	$latitudine  = (string) $offerta->Latitudine;
	$longitudine = (string) $offerta->Longitudine;
	
	if(!empty($latitudine))  $latitudine  = str_replace(",", ".", $latitudine);;
	if(!empty($longitudine)) $longitudine = str_replace(",", ".", $longitudine);;
	
	
	if(!empty($latitudine) and !empty($longitudine)){
		$map_coords  = $latitudine .", ". $longitudine;		
	}else{
		$map_coords = "";
	}
	
	// Caratteristiche aggiuntive
	$af = array();
	if(!empty($offerta->Locali)){
		$af[] = array( "fave_additional_feature_title" => "Locali",  "fave_additional_feature_value" => (string) $offerta->Locali);
	}
	if(!empty($offerta->Cucina)){
		$af[] = array( "fave_additional_feature_title" => "Cucina",  "fave_additional_feature_value" => (string) $offerta->Cucina);
	}
	if(!empty($offerta->Box)){
		$af[] = array( "fave_additional_feature_title" => "Box",  "fave_additional_feature_value" => (string) $offerta->Box);
	}
	$fave_additional_features_enable = (empty($af)) ? "disable" : "enable";
	
	// Controllo se questo immobile dev'essere in vetrina, se sì controllo se lo era già e in caso contrario tolgo vetrina da quello precedente 
	//$vetrina = cc_ho_vetrina( (string) $offerta->IdAgenzia, (string) $offerta->Riferimento);
	
	// GESTIONE PREZZO / TRATTATIVA RISERVATA
	$prezzo = (string) $offerta->Prezzo;
	$flag_trattativa_riservata = (string) $offerta->TrattativaRiservata;
	
	if($flag_trattativa_riservata == '1'){
		$prezzo = "Trattativa Riservata";
		$fave_private_note = "Prezzo richiesto €".$prezzo;		
	}else{
		$fave_private_note = "";
	}

	
	// creo array dei dati per poterli iterare
	$meta_args = array(
		"fave_property_size" => (int) $offerta->Mq, 
		"fave_property_bedrooms" => (int) $offerta->Camere, 
		"fave_property_bathrooms" => (int) $offerta->Bagni, 
		"fave_property_id" => (string) $offerta->Riferimento, 
		"fave_property_price" => $prezzo, 
		"fave_property_map_address" => (string) $map_address, 
		"fave_property_location" => (string) $map_coords, 
		"fave_additional_features_enable" => $fave_additional_features_enable,
		"additional_features" => $af,
		"houzez_geolocation_lat" => $latitudine,
		"houzez_geolocation_long" => $longitudine,
		"fave_energy_class" => (string) $offerta->Classe,
		"fave_private_note" => $fave_private_note,
		"fave_energy_global_index" => (string) $offerta->IPE." ".$offerta->IPEUm, 
		//"fave_featured" => $vetrina
	);
	
	if(!empty($cp)) $meta_args['fave_property_zip'] = $cp['cap'];	
	if(!empty($indirizzo) or (empty($indirizzo) and empty($oldAddress))) $meta_args['fave_property_address'] = $indirizzo;	
	
	// Loop degli argomenti e aggiornamento postmeta dell'immobile
	foreach($meta_args as $meta_key => $meta_value) update_post_meta( $post_id, $meta_key, $meta_value );
	
	// Se vi sono nuove foto le aggiungo alla tabella postmeta 
	if(!empty($foto_nuove)) cc_insert_images_meta($foto_nuove, $post_id);
	
	
	/*** 3. GESTIONE CATEGORIE - SE IL VALORE CATEGORIE (TERM) NON ESISTE ANCORA VIENE AGGIUNTO  ***/
	
	// Categoria "property_type" (Appartamento, villa, box etc) - SINGOLO
	$property_type = (string) $offerta->Tipologia;
	$property_type_results = cc_add_term_taxonomy($post_id, "property_type", array( $property_type ));
	
	// Categoria "property_city" (Città) - SINGOLO
	$property_city = (string) $offerta->Comune;
	$property_city_results = cc_add_term_taxonomy($post_id, "property_city", array( $property_city ));
	
	
	// Categoria "property_area" (Zona / Quartiere) - SINGOLO
	$property_area = (string) $offerta->Quartiere;
	$property_area_results = cc_add_term_taxonomy($post_id, "property_area", array( $property_area ));
	
	
	// Categoria "property_feature" (caratteristiche) - MULTI
	$property_feature = array();
	if(!empty($offerta->Box)) $property_feature[] = "Box Auto";
	if(!empty($offerta->Box)) $property_feature[] = "Posto Auto";
	if((string) $offerta->Terrazzo == '-1') $property_feature[] = "Terrazzo";
	if((string) $offerta->Balcone == '-1') $property_feature[] = "Balcone";
	/*
	if((string) $offerta->GiardinoCondominiale == '-1') $property_feature[] = "Giardino Condominiale";
	if((string) $offerta->GiardinoPrivato == '-1') $property_feature[] = "Giardino Privato";	
	*/
	if((string) $offerta->GiardinoPrivato == '-1' or (string) $offerta->GiardinoCondominiale == '-1') $property_feature[] = "Giardino";	
	
	if(!empty($property_feature)) $property_feature_results = cc_add_term_taxonomy($post_id, "property_feature", $property_feature );
	
	// qua controllo di offerta e $post_id
	$DataAggiornamento = new DateTime( (string) $offerta->DataAggiornamento ); // $offerta->DataAggiornamento formato 2018-07-25T00:00:00+01:00
	$post_modified = $DataAggiornamento->format("Y-m-d H:i:s"); 	
	
	/*** 4 UPDATE POSTS RECORD TRAMITE wp_update_post. L'ID DEL RECORD VIENE PASSATO TRAMITE ARRAY ARGOMENTI ***/
	$posts_arg = array(
		"ID"      => $post_id, 
		"post_content"      => (string) $offerta->Descrizione,
		"post_title"        => (string) $offerta->Titolo,
		"post_excerpt"      => (string) $offerta->Descrizione,
		"post_modified"     => (string) $post_modified, 
		"post_modified_gmt" => (string) $post_modified 
	);
	
	// Returns the ID of the post if the post is successfully updated in the database. Otherwise returns 0
	$updated = wp_update_post( $posts_arg, true ); // 
	cc_import_immobili_error_log("updated: ".$updated);
	
	// se ho errore fornisco feedback tramite email ed esco (passo a prossimo record)
	// TODO raccogliere errore se no per ogni record errato invia una email
	if (is_wp_error($updated)) {
		$errors = $updated->get_error_messages();
		$dbg = "Errore durante update record	\n";
		foreach ($errors as $error) {
			$dbg .= $error."\n";
		}
		return $dbg;		
	}
	
	
	// finito update record
	
	// update configs xml
	cc_update_configs("updated", 1, true);	
	
	return "updated"; 
	
}

/**
 * Inserisco nuovo record immobile in DB (tabella posts)
 * 
 * @param $offerta: oggetto xml del singolo immobile
 * @return string con valore "inserted" o errore rilevato
 */
function cc_insert_record($offerta){
		
	// recupero post_id dell'agenzia, agente e autore da rif (prime due cifre)
	$aau  = cc_get_agency($offerta->Riferimento); 
	$agenzia = $aau['fave_property_agency']; 
	$agente  = $aau['fave_agents'];  
	$user_id = $aau['post_author'];

	
	// POSTMETA - INDIRIZZO
	$map_address = $indirizzo = "";
	
	//$indirizzo = solo indirizzo (via e civico), map_adress = indirizzo completo con cap località e provincia per mappa
	if(!empty($offerta->Indirizzo)){
		$map_address .= (string) $offerta->Indirizzo;
		$indirizzo   .= (string) $offerta->Indirizzo;
	}
	if(!empty($offerta->NrCivico)){
		$map_address .= (string) " ".$offerta->NrCivico;
		$indirizzo   .= (string) " ".$offerta->NrCivico;
	}
	
	// compilo map_address con cap, località e provincia solo se l'indirizzo (via + civico) è compilato
	// se non lo è vuol dire che non deve essere resa noto l'indirizzo esatto dell'immobile
	if(!empty($offerta->Comune) and !empty($map_address)){
		
		$comune = (string) $offerta->Comune;
		$comune = trim($comune);
		$map_address .= ", ";
		
		$cp = cc_get_cap_prov($comune);
		if(!empty($cp)) $map_address .= $cp['cap']." "; 		
		
		$map_address .= $comune;
		if(!empty($cp)) $map_address .= " (".$cp['prov'].")"; 
		
	}else{
		
		$cp = array();
		
	}
	
	$map_address = trim($map_address);
	if($map_address[0] == ',') $map_address = substr($map_address, 2);	
	
	$latitudine  = (string) $offerta->Latitudine;
	$longitudine = (string) $offerta->Longitudine;
	
	// da cometa arriva con virgola decimale
	if(!empty($latitudine))  $latitudine  = str_replace(",", ".", $latitudine);;
	if(!empty($longitudine)) $longitudine = str_replace(",", ".", $longitudine);;

	if(!empty($latitudine) and !empty($longitudine)){
		$map_coords  = $latitudine .", ". $longitudine;		
	}else{
		$map_coords = "";
	}
	
	// Caratteristiche aggiuntive
	$af = array();
	if(!empty($offerta->Locali)){
		$af[] = array( "fave_additional_feature_title" => "Locali",  "fave_additional_feature_value" => (string) $offerta->Locali);
	}
	if(!empty($offerta->Cucina)){
		$af[] = array( "fave_additional_feature_title" => "Cucina",  "fave_additional_feature_value" => (string) $offerta->Cucina);
	}
	if(!empty($offerta->Box)){
		$af[] = array( "fave_additional_feature_title" => "Box",  "fave_additional_feature_value" => (string) $offerta->Box);
	}
	$fave_additional_features_enable = (empty($af)) ? "disable" : "enable";
	
	// controllo se questo nuovo immobile deve essere messo in vetrina o meno e se sì tolgo vetrina a quello precedente
	//$vetrina = cc_ho_vetrina( (string) $offerta->IdAgenzia, (string) $offerta->Riferimento);
	
	// GESTIONE PREZZO / TRATTATIVA RISERVATA
	$prezzo = (string) $offerta->Prezzo;
	$flag_trattativa_riservata = (string) $offerta->TrattativaRiservata;
	
	if($flag_trattativa_riservata == '1'){
		$prezzo = "Trattativa Riservata";
		$fave_private_note = "Prezzo richiesto €".$prezzo;		
	}else{
		$fave_private_note = "";
	}
	
		
	// META INPUT VARIABILI
	$meta_input = array(
		//"fave_featured" => $vetrina,
		"fave_property_size" => (int) $offerta->Mq, 
		"fave_property_bedrooms" => (int) $offerta->Camere, 
		"fave_property_bathrooms" => (int) $offerta->Bagni, 
		"fave_property_id" => (string) $offerta->Riferimento, 
		"fave_property_price" => $prezzo, 
		"fave_property_map_address" => (string) $map_address, 
		"fave_property_location" => $map_coords, 
		"fave_additional_features_enable" => $fave_additional_features_enable,
		"additional_features" => $af,
		"fave_property_address" => $indirizzo,
		"houzez_geolocation_lat" => $latitudine,
		"houzez_geolocation_long" => $longitudine,
		"fave_energy_class" => (string) $offerta->Classe,
		"fave_energy_global_index" => (string) $offerta->IPE." ".$offerta->IPEUm, 
		"fave_private_note" => $fave_private_note, 
		"_id_cometa" => (string) $offerta->Idimmobile
		
	);	

	if(!empty($cp)) $meta_input['fave_property_zip'] = $cp['cap'];
	
	// META INPUT VALORI FISSI
	$meta_input['slide_template'] = "default";
	$meta_input['fave_property_size_prefix'] = "M²";
	$meta_input['fave_property_country'] = "IT";
	$meta_input['fave_agents'] = $agente;
	$meta_input['fave_property_agency'] = $agenzia;
	$meta_input['fave_floor_plans_enable'] = "disabled";
	$meta_input['fave_agent_display_option'] = "agent_info";
	$meta_input['fave_payment_status'] = "not_paid";
	$meta_input['fave_property_map_street_view'] = "hide";
	$meta_input['houzez_total_property_views'] = "0";
	$meta_input['houzez_views_by_date'] = "";
	$meta_input['houzez_recently_viewed'] = "";
	$meta_input['fave_multiunit_plans_enable'] = "disable";
	$meta_input['fave_multi_units'] = "";
	$meta_input['fave_single_top_area'] = "v2";
	$meta_input['fave_single_content_area'] = "global";
	$meta_input['fave_property_land_postfix'] = "M²";
	$meta_input['fave_property_map'] = "1";
	
	// MANCANO FOTO, LE METTO DOPO
	
	$DataAggiornamento = new DateTime( (string) $offerta->DataAggiornamento ); // $offerta->DataAggiornamento formato 2018-07-25T00:00:00+01:00
	$post_modified = $DataAggiornamento->format("Y-m-d H:i:s"); 	
	
	
	$posts_arg = array(
		"post_author"    	=> $user_id, 
		"post_content"   	=> (string) $offerta->Descrizione,
		"post_title"     	=> (string) $offerta->Titolo,
		"post_excerpt"   	=> (string) $offerta->Descrizione,
		"post_status"    	=> "publish", 
		"post_type"      	=> "property",
		"post_modified"     => (string) $post_modified,
		"post_modified_gmt" => (string) $post_modified,
		"comment_status" 	=> "closed",
		"ping_status"    	=> "closed",
		"guid"     		 	=> wp_generate_uuid4(),
		"meta_input"     	=> $meta_input
	);
	
	// Restituisce l'ID del post se il post è stato aggiornato con success nel DB. Se no restituisce 0.
	$post_id = wp_insert_post( $posts_arg, true ); 
	
	if (is_wp_error($post_id)) {
		$errors = $post_id->get_error_messages();
		$dbg = "Errore durante insert record\n";
		foreach ($errors as $error) {
			$dbg .= $error."\n";
		}
		return $dbg;		
	}
	
	// continuo con inseriemnto foto e categorie
	
	$foto = array();
	for($nf = 1; $nf < 16; $nf++){
		
		$foto_field = "FOTO".$nf;
		$foto_url   = (string) $offerta->$foto_field;
		if(!empty($foto_url)) $foto[] = $foto_url;
		
	}
	
	// inserisce solo se le foto non sono ancora presenti, restituisce array con id posts delle foto nuove
	$foto_nuove = cc_import_images($foto, $post_id, true);
	
	// Se vi sono nuove foto le aggiungo alla tabella postmeta 
	if(!empty($foto_nuove)){
		cc_insert_images_meta($foto_nuove, $post_id); // Non restituisce feedback
	}
	
		
	// GESTIONE CATEGORIE - SE IL VALORE CATEGORIE (TERM) NON ESISTE ANCORA VIENE AGGIUNTO 
	
	// Categoria "property_type" (Appartamento, villa, box etc) - SINGOLO
	$property_type = (string) $offerta->Tipologia;
	if(!empty($property_type)) $property_type_results = cc_add_term_taxonomy($post_id, "property_type", array( $property_type ));
	
	// Categoria "property_city" (Città) - SINGOLO
	$property_city = (string) $offerta->Comune;
	if(!empty($property_city)) $property_city_results = cc_add_term_taxonomy($post_id, "property_city", array( $property_city ));
	
	
	// Categoria "property_area" (Zona / Quartiere) - SINGOLO
	$property_area = (string) $offerta->Quartiere;
	$property_area = trim($property_area);
	if(!empty($property_area)) $property_area_results = cc_add_term_taxonomy($post_id, "property_area", array( $property_area ));	
	
	// Categoria "property_feature" (caratteristiche) - MULTI
	$property_feature = array();
	if(!empty($offerta->Box)) $property_feature[] = "Box Auto";
	if(!empty($offerta->Box)) $property_feature[] = "Posto Auto";
	if((string) $offerta->Terrazzo == '-1') $property_feature[] = "Terrazzo";
	if((string) $offerta->Balcone == '-1') $property_feature[] = "Balcone";
	if((string) $offerta->GiardinoCondominiale == '-1' or (string) $offerta->GiardinoPrivato == '-1' ) $property_feature[] = "Giardino";
	//if((string) $offerta->GiardinoPrivato == '-1') $property_feature[] = "Giardino Privato";
	
	if(!empty($property_feature)) $property_feature_results = cc_add_term_taxonomy($post_id, "property_feature", $property_feature );
	
	// FINITO INSERT RECORD
	
	// update configs xml
	cc_update_configs("inserted", 1, true);	
	cc_import_immobili_error_log("Inserted: ".$post_id);
	
	return "inserted";
	
}

/**
 * funzione che recupera da array con url immagini. Effettua sia download che inserimento in posts e postmeta
 * fa controllo di eventuali foto / immagini già inserite per il record post_id controlando "post_title" e se
 * trovato lo salta
 * restituisce array con id posts nuove immagini inserite 
 * ATTENZIONE NON DA FEEDBACK IN CASO DI ERRORE
 * 
 * @param $images: array con url immagine
 * @param $parent_post_id: id record del post a cui allegare le foto
 * 
 * @return array con id attachment
*/
function cc_import_images($images = array(), $parent_post_id = 0) {
	
	// mi assicuro che sia array
	if(!is_array($images)) $images = array($images);
	
	// se vuota restituisco array vuota ed esco
	if(empty($images)){
		return array(); // se nessun immagine pasasta restituisco array vuoto
	}
	
	// array che memorizza id (tab posts) delle phote nuove inserite 
	$id_posts_immagini = array();
	
	// array che contiene le i post title delle foto già caricate
	$exclude = array();
	
	// recupero eventuali foto già caricate 
	$current_photos = get_attached_media( 'image', $parent_post_id );
	if($current_photos){
		
		foreach($current_photos as $current_photo){
			$exclude[] = $current_photo->post_title;
		}
	}
		
	// recupera la cartella upload incluso di path anno e mese
	$uploaddir = wp_upload_dir();	
	
	// Make sure that this file is included, as wp_generate_attachment_metadata() depends on it.
	require_once( ABSPATH . 'wp-admin/includes/image.php' );
	
	// loop immagini passate
	foreach($images as $nf => $img){	
		
		// controllo che il flusso sia un immgine valida, per ora gestisco solo jpg e png
		$exif_imagetype = @exif_imagetype ( $img );
		if($exif_imagetype === false){
			
			cc_import_immobili_error_log($img." non è un immagine valida");			
			continue; // skip if it's not an image
		}
		
		if($exif_imagetype == IMAGETYPE_JPEG){
			$ext = "jpg";
			$mime_type = "image/jpeg";
		}else if($exif_imagetype == IMAGETYPE_PNG){
			$ext = "png";
			$mime_type = "image/png";
		}else{
			continue; // not a jpg or png, skip to next
		}
		
		// estrapolo nome file da url
		$cerca = "idfoto=";
		$p = strpos($img, $cerca);		
		
		if($p !== false){
			// nome è variabile dopo ?idfoto=
			$s = $p+strlen($cerca);
			$l = strlen($img)-$s;
			$filename = substr($img, $s, $l );
		}else{
			// Fallback - nome basato su numero post e numero foto in array
			$filename = "foto_".$parent_post_id."_".$nf;
		}
				
		// se esiste già passa all'immagine successivo
		if(in_array($filename, $exclude)) continue;
		
		// aggiungo estensione al nome del file
		$filename .= ".".$ext;
		// path e nome file per salvataggio file reale 
		$uploadfile = $uploaddir['path'] . '/' . $filename;
		
		$dbgimg = $img." - mime type: ".$mime_type." - salvo in ".$uploadfile;
		
		// scarico immagine full in locale
		$contents = file_get_contents($img); // recupero contenuot binario del file da url
		$savefile = fopen($uploadfile, 'w'); // apro file locale per scrittura usando nome immagine con estensione
		if(!fwrite($savefile, $contents)) $dbgimg .= " Errore di scrittura su disco"; // scrivo flusso binario immagine in file locale
		fclose($savefile);	// chiudo file locale
		
		$filesize = filesize($uploadfile);
		$dbgimg .= " file size: ".$filesize."\n";
		cc_import_immobili_error_log($dbgimg);
		
		// creo array per scrittura in tabella posts
		$attachment = array(
			'guid' => $uploaddir['url'] . '/' . basename( $filename ),
			'post_mime_type' => $mime_type,
			'post_title' => preg_replace( '/\.[^.]+$/', '', basename( $filename ) ),
			'post_content' => '',
			'post_status' => 'inherit', 
			'post_author' => '1'
		);
		
		// inserisco in tabella posts e mi restituisce id tabella, posso usare un terzo parmetro ovvero l'id del post a cui è legato
		$attach_id = wp_insert_attachment( $attachment, $uploadfile, $parent_post_id );
		
		// crea miniature e altre dimensioni e restituisce array con i nomi dei vari formati e la loro dimensione 
		$attach_data = wp_generate_attachment_metadata( $attach_id, $uploadfile );
		if (is_wp_error($attach_data)) {

			$errors = $attach_data->get_error_messages();
			$dbg = "Errore durante creazione miniature\n";
			foreach ($errors as $error) {
				$dbg .= $error."\n";
			}
			cc_import_immobili_error_log($dbg);

		}
		
		// aggiorno metadata con le info _wp_attachment_metadata (serializza array $attach_data)
		wp_update_attachment_metadata( $attach_id, $attach_data );	
		
		// Raccolgo id posts per restituirlo a funzione principale
		$id_posts_immagini[] = $attach_id;
		
	}
	
	return $id_posts_immagini; // array con id attachment
		
}


/**
 * Inserisce record foto immobile in postmeta (per fotogallery)
 * Se è assente l'immagine in rilievo (_thumbnail_id in postmeta) uso la prima immagine in array images come tale.  
 * ATTENZIONE NON DA FEEDBACK IN CASO DI ERRORE
 * 
 * @param $images:  array con id attachment restituisto da cc_import_images()
 * @param $post_id: id record del post immobile
 * 
 * @return array con meta id o false in caso di errori
*/
function cc_insert_images_meta($images, $post_id){
	
	if(empty($images)) return false;
		
	$inserted = array();

	// controllo innanzittutto se ho immagine in evidenza in caso contrario lo aggiuno usando la prima foto in array
	$thumb = get_post_meta( $post_id, '_thumbnail_id', true );
	if(empty($thumb)) set_post_thumbnail( $post_id, $images[0] );

		
	// images is array with id of posts table
	foreach($images as $image_post_id){
		$inserted[] = add_post_meta($post_id, 'fave_property_images', $image_post_id);
	}
	
	return $inserted;
		
}

/* se non esiste la funzione exif_imagetype usa quasta versione  */
if (!function_exists('exif_imagetype')) {
	function exif_imagetype($filename) {
		if ((list($width, $height, $type, $attr) = getimagesize($filename)) !== false) { 
			return $type;
		} 
		return false; 
	} 
}


/**
* Gestisco le categorie (term taxonomy) come per esemio tipologia di immobile, località, caratteristiche etc
* ovvero le opzioni multiple assegnabile tramite checkbox
* se il termine passato non esiste lo aggiunge al pool di termini di tale tassonomia
*
* @param $post_id: (int) record id in posts
* @param $taxonomy: (string) nome tassonomia
* @param $terms: (array) con i termini 
* 
* @return (array) gli id dei termini attribuiti alla taxonomy del post
*/
function cc_add_term_taxonomy($post_id, $taxonomy, $terms = ""){

	if(empty($post_id) or empty($taxonomy)) return false;
	
	$term_ids = array();
	
	if(!empty($terms)){
		
		// mi assicuro che terms sia un array
		if(!is_array($terms)) $terms = array( $terms );
		
		// scorre i termini
		foreach($terms as $term){
			
			if(empty($term)) continue;
			
			// recupero id del termine all'interno della taxonomy
			$term_id = term_exists( $term, $taxonomy );
			
			if (is_wp_error($term_id)) {
				
				$errors = $term_id->get_error_messages();
				$dbg = "Errore durante tentato recupero termine ".$term." in taxonomy '".$taxonomy."', post_id: ".$post_id."\n";
				foreach ($errors as $error) {
					$dbg .= $error."\n";
				}
				cc_import_error($dbg);

			}
			
			// se non trovo il termine lo inserisco...
			if(is_null($term_id)) $term_id = wp_insert_term($term, $taxonomy);	
			
			if (is_wp_error($term_id)) {
				
				$errors = $term_id->get_error_messages();
				$dbg = "Errore durante inserimento nuovo termine '".$term."' con taxonomy '".$taxonomy."', post_id = '".$post_id."'\n";
				foreach ($errors as $error) {
					$dbg .= $error."\n";
				}
				cc_import_error($dbg);
				
			}else{
				
				// aggiungo id termine reuperato o inserito ad array con gli id termini da atrribuire alla taxonomy del post
				$term_ids[] = $term_id['term_id'];
				
			}
			
		} // end foreach
		
	}
	
	// aggiungo i termini alla taxonomy del post
	$result = wp_set_post_terms( $post_id, $term_ids, $taxonomy );
	
	// restituisco array con gli id dei termini attribuiti alla taxonomy del post
	return $result;
	
}

// Recupero post_id agenzia, agente e autore estrapolando le prime due cifre dal codice immobile
function cc_get_agency($rif){
	
	$rif = (string) trim($rif);
	$id = (int) substr($rif, 0, 2);
	
	// MAP
	$agenzie_agenti_user = array();
	$agenzie_agenti_user[1]  = array( "fave_property_agency" => 2788, "fave_agents" => 72 , "post_author" => 3 );
	$agenzie_agenti_user[2]  = array( "fave_property_agency" => 2786, "fave_agents" => 150 , "post_author" => 4 );
	$agenzie_agenti_user[3]  = array( "fave_property_agency" => 2790, "fave_agents" => 158 , "post_author" => 5 );
	$agenzie_agenti_user[4]  = array( "fave_property_agency" => 2784, "fave_agents" => 20012 , "post_author" => 6 );
	$agenzie_agenti_user[5]  = array( "fave_property_agency" => 2777, "fave_agents" => 20016 , "post_author" => 7 );
	$agenzie_agenti_user[6]  = array( "fave_property_agency" => 2792, "fave_agents" => 156 , "post_author" => 2 );
	$agenzie_agenti_user[7]  = array( "fave_property_agency" => 19988, "fave_agents" => 20018 , "post_author" => 8 );
	$agenzie_agenti_user[10] = array( "fave_property_agency" => 19992, "fave_agents" => 20014 , "post_author" => 9 );
	$agenzie_agenti_user[11] = array( "fave_property_agency" => 19990, "fave_agents" => 20020 , "post_author" => 10 );
	
	return $agenzie_agenti_user[$id];
	
}

// estrapolo tutti gli immobili che nel ciclo prinicpale sono stati messi in vetrina ed estrapolo solo quelli più recenti
function cc_set_immobili_in_vetrina() {
    
	global $wpdb;
	global $invetrina;
	
	$t = 0;
	
	// nome tabella post meta
	$tbl = $wpdb->prefix.'postmeta';
	
	// IMPORTA XML
	$path = ABSPATH . "import/";	
	$file = FILE_XML_IMMOBILI;
	$xml = @simplexml_load_file($path.$file);
	
	// solo le seguenti tipologie di immobili possono essere in primo piano
	$tipologia_permesse = array("Appartamento", "Attico", "Casa Indipendente", "Casa semindipendente", "Mansarda", "Palazzo", "Rustico / Casale", "Villa", "Villa a schiera");
	
	// scorro xml
	if($xml){		

		$offerte = $xml->Offerte;

		if($offerte){

			foreach($offerte as $offerta){
				
				$t++;

				$vetrina = (int) $offerta->Vetrina;

				if(!empty($vetrina)){

					$rif = (string) $offerta->Riferimento;
					$imm = (string) $offerta->IdAgenzia; // Numero interno di Cometa
					$contratto = strtoupper(substr($rif, 2, 2)); // prendo la parte "VE" da rif
					$tipologia = (string) $offerta->Tipologia; // tipologia immobile
					$tipologia = trim($tipologia);
					//if($contratto != "VE") continue;
					// se non è un immobile in vendita e non appartiene alla categoria permesse continuo con il prossimo record
					if($contratto != "VE" or !in_array($tipologia, $tipologia_permesse)) continue;
					
					// recupero data aggiornamento in Cometa
					$data = (string) $offerta->DataAggiornamento;
					$dt = new DateTime($data);
					
					// se ho già
					if(empty($invetrina[$imm])){
						$invetrina[$imm]['date'] = $data;					
						$invetrina[$imm]['imm'] = $rif;	
						$prev_date = new DateTime($data);
						$diff = 0;
					}else{
						$prev_date = new DateTime($invetrina[$imm]['date']);
						$interval = $prev_date->diff($dt);
						$diff = (int) $interval->format('%R%a');
						if($diff > 0){
							$invetrina[$imm]['date'] = $data;					
							$invetrina[$imm]['imm'] = $rif;											
						}
					}				

				} // end if empty vetrina

			} // end foreach offerte

		} // end if offerte

	}	// end if xml
	
	$dbg = print_r($invetrina, true);
	cc_import_immobili_error_log("DBG IN VETRINA: ".$dbg);
	
	// IMPOSTO TUTTI GLI IMMOBILI COME NON IN VETRINA
	$wpdb->update(  $wpdb->postmeta, array('meta_value' => '0'), array('meta_key' => 'fave_featured') );
	
	
	// METTO IN VETRINA SOLO QUELLI CHE HO ESTRAPOLATO
	$dbg2 = array();
	if(!empty($invetrina)){
		foreach($invetrina as $iv){
			
			$prepare_qry = $wpdb->prepare( "SELECT post_id FROM ".$tbl." WHERE meta_key = 'fave_property_id' AND meta_value = '%s'", $iv['imm'] );
			$post_id = $wpdb->get_col( $prepare_qry, 0 );
			
			$dbg2[] = $iv['imm']." => ".$post_id[0];
			if(!empty($post_id)){
				update_post_meta( $post_id[0], "fave_featured", "1" );				
			}
			
		}
	}
	$dbg3 = implode(", ", $dbg2);
	cc_import_immobili_error_log("DBG3: ".$dbg3);
	
	
    return true;
	
}


// recupera da postmeta tutti i valori di una chiave (tutti i post non solo immobili)
function cc_get_unique_post_meta_values( $key = '') {
    
	global $wpdb;
	$metas = array();
	
    if( !empty( $key ) ){
		
		$qry = $wpdb->prepare( 
				"SELECT post_id, meta_value 
				FROM {$wpdb->postmeta} 
				WHERE meta_key = '%s'", 
				$key
			);

		$res = $wpdb->get_results( $qry );
		
		if($res){

			foreach ( $res as $r ) $metas[$r->post_id] = $r->meta_value;	
			
		}
		
	}
	
    return $metas;
}


/*---------------------FUNZIONI PER REPORTING, DBUG E STATISTICA DI ESECUZIONE ------------------------------------------*/

// funzione per inviare email di notifica errore
function cc_import_error($msg, $oggetto = ""){
	
	if(empty($oggetto)) $oggetto = "Errore durante importazione dati xml";
	wp_mail( 'rpravisani@gmail.com', $oggetto, $msg);	
	
}


function cc_get_record_statistics(){
	
	$path = ABSPATH . "import/";
	
	// apro file config (xml) da cui prendere data ultima elaborazione ed in cui memeotizzare dati elaborazione
	$config = @simplexml_load_file($path."configs.xml");	
	
	if(!$config){
		// file config non trovato notifico e esco 
		cc_import_error("File config non trovato (cc_get_record_statistics)!");
		die();		
	}
	
	$array = array();
	$records = $config->records;
	$array['inserted'] = (int) $records->inserted;
	$array['updated']  = (int) $records->updated;	
	$array['skipped']  = (int) $records->skipped;
	$array['nofoto']   = (int) $records->nofoto;
	$array['affitto']  = (int) $records->affitto;	
	
	$array['total']    = array_sum($array);
	$array['source']   = (int) $records->source;
	$array['deleted']  = (int) $records->deleted;
	return $array;
	
}

function cc_reset_stats($file){	
	
	$path = ABSPATH . "import/";
	
	// apro file config (xml) da cui prendere data ultima elaborazione ed in cui memeotizzare dati elaborazione
	$config = @simplexml_load_file($path."configs.xml");	
	
	if(!$config){
		// file config non trovato notifico e esco 
		cc_import_error("File config non trovato (cc_reset_stats)!");
		die();		
	}
	
	$fields = array('source', 'inserted', 'updated', 'deleted','skipped','nofoto','affitto');
	
	foreach($fields as $field) $config->records->$field = '0';
	
	if(!empty($file)) $config->fileName = $file;
	
	$config->startTime = date("Y-m-d H:i:s");
	$config->lastExecutionTime = date("Y-m-d H:i:s");
	
	$config->asXml($path."configs.xml");
	
}

function cc_update_configs($field = "lastUpdate", $value, $add = false){
	
	$path = ABSPATH . "import/";
	
	// apro file config (xml) da cui prendere data ultima elaborazione ed in cui memeotizzare dati elaborazione
	$config = @simplexml_load_file($path."configs.xml");	
	
	if(!$config){
		// file config non trovato notifico e esco 
		cc_import_error("File config non trovato (cc_update_configs)!");
		die();		
	}
	
	if($field == 'lastUpdate' or $field == 'fileName' ){
		
		$config->$field = $value;	
		
	}else{
		
		if($add) $value = (int) $config->records->$field + $value;		
		$config->records->$field = $value;	
		
	}
	
	// update last execution time
	$config->lastExecutionTime = date("Y-m-d H:i:s");
	
	$config->asXml($path."configs.xml");	

	
}

// scrivi file log con errori
function cc_import_immobili_error_log($msg){
	
	$path = ABSPATH . "import/";
	$d = date("Ymd");
	$file = $path."logfile_".$d.".txt";
	if(is_array($msg)) $msg = var_export($msg, true);
	
	$method = (file_exists($file)) ? "a": "w";
	$handle = fopen($file, $method);
	$string = date("Y-m-d H:i:s", time())." - ".$msg."\n";
	fwrite($handle, $string);
	fclose($handle);
	
}


/*---------------------FUNZIONI UNA TANTUM --------------------------------------------------------------------------*/


/* funzione una-tantum per creare le agenzie */
function cc_insert_agencies(){
	
	// 1. recupero elenco di tutti gli immobili
	$args = array(
	  'numberposts' => -1,
	  'post_type'   => 'property'
	);

	$immobili = get_posts( $args );	
	
	// 2. loop immobili trovati
	if($immobili){
		foreach($immobili as $immobile){
			$idimmobile = (int) $immobile->ID;
			$rif = (string) get_post_meta($idimmobile, "fave_property_id", true);
			
			echo $idimmobile . " => " . $rif;
			
			// 3. richiamo cc_get_agency passando codice immobile, questo mi resistuisce agente, agenzia e user del record
			$aau = cc_get_agency($rif);
			
			if(!empty($aau['fave_property_agency'])) update_post_meta($idimmobile, 'fave_property_agency', $aau['fave_property_agency']);
			if(!empty($aau['fave_agents'])) update_post_meta($idimmobile, 'fave_agents', $aau['fave_agents']);
			if(!empty($aau['post_author'])){
				
				$arg = array(
					'ID' => $idimmobile,
					'post_author' => $aau['post_author'],
				);
				wp_update_post( $arg );				
				
			} // end if post_author		
			
			echo " agenzia: ".$aau['fave_property_agency']. " - agente: " . $aau['fave_agents'] . " - author: " . $aau['post_author'] . "<br>\n";
			
		} // end foreach
		$n = count($immobili);
		echo "Updated ".$n." properties.";
		die();
	} // end if immobili		
	
} // function end





/* funzione utile per aggiornare in blocco un post meta per tutti gli immobili in db - aggiorna i post_meta*/
function cc_update_meta_tutti_immobili($campo = false, $valore = ''){
	
	$cometa = cc_get_unique_post_meta_values("_id_cometa");
	
	if($cometa and !empty($campo)){
		
		$Result = array();
		
		$immobili = array_keys($cometa);
		
		foreach($immobili as $post_id){
			
			$result[$post_id] = update_post_meta( $post_id, $campo, "1" );
			
		}
		
	}
	$dbg = var_export($result, true);
	
	cc_import_immobili_error_log($dbg);
	
}

/* funzione una tantum per aggiornare i geocodes di tutti gli immobili in post_meta - serve file xml */
function cc_update_geocodes(){
	
	$path = ABSPATH . "import/";
	$file = FILE_XML_IMMOBILI; // nome del file
	$cometa = cc_get_unique_post_meta_values("_id_cometa");

	$xml = @simplexml_load_file($path.$file);	

	if($xml){		
		
		$offerte = $xml->Offerte;

		if($offerte){
			
			foreach($offerte as $offerta){
				
				$idunique = (int) $offerta->Idimmobile; // campo univoco Cometa
				$post_id = array_search($idunique, $cometa);
				
				if(empty($post_id)) continue;
				
				$latitudine  = (string) $offerta->Latitudine;
				$longitudine = (string) $offerta->Longitudine;

				if(!empty($latitudine))  $latitudine  = str_replace(",", ".", $latitudine);;
				if(!empty($longitudine)) $longitudine = str_replace(",", ".", $longitudine);;

				if(!empty($latitudine) and !empty($longitudine)){
					$map_coords  = $latitudine .", ". $longitudine;		
				}else{
					$map_coords = "";
				}				
				
				if(!empty($map_coords)){
					
					update_post_meta( $post_id, "fave_property_location", $map_coords );
					update_post_meta( $post_id, "houzez_geolocation_lat", $latitudine );
					update_post_meta( $post_id, "houzez_geolocation_lon", $longitudine );
					
					$results[] = $post_id." => ".$idunique." - geocodes ".$map_coords;
				}
				
			}
			
			$dbg = var_export($results, true);
	
			cc_import_immobili_error_log($dbg);

			
		}else{
			cc_import_immobili_error_log("no offerte!");
		}
		
	}else{
		cc_import_immobili_error_log("no xml!");
	}
	
}

// Funziona una tantum per setup iniziale immobili in vetrina, imposta in vetrina in base al valore 'Vetrina' in file xml 
function cc_set_in_vetrina(){
	
	$path = ABSPATH . "import/";
	$file = FILE_XML_IMMOBILI; // nome del file
	$cometa = cc_get_unique_post_meta_values("_id_cometa");

	$xml = @simplexml_load_file($path.$file);	

	if($xml){		
		
		$offerte = $xml->Offerte;

		if($offerte){
			
			foreach($offerte as $offerta){
				
				$idunique = (int) $offerta->Idimmobile; // campo univoco Cometa
				$post_id = array_search($idunique, $cometa);
				
				if(empty($post_id)) continue;
				
				$vetrina = (int) $offerta->Vetrina;
				
				update_post_meta( $post_id, "fave_featured", $vetrina );

			}
			
			$dbg = var_export($results, true);
	
			cc_import_immobili_error_log($dbg);

			
		}else{
			cc_import_immobili_error_log("no offerte!");
		}
		
	}else{
		cc_import_immobili_error_log("no xml!");
	}
	
}

/* funzione una-tantum per aggiorare gli indirizzi degli immobili in base ai valri nel file xml */
function cc_update_map_address(){
	
	$path = ABSPATH . "import/";
	$file = FILE_XML_IMMOBILI; // nome del file
	$cometa = cc_get_unique_post_meta_values("_id_cometa");

	$xml = @simplexml_load_file($path.$file);	

	if($xml){		
		
		$offerte = $xml->Offerte;

		if($offerte){
			
			foreach($offerte as $offerta){
				
				$idunique = (int) $offerta->Idimmobile; // campo univoco Cometa
				$post_id = array_search($idunique, $cometa);
				
				if(empty($post_id)) continue;
				
				$map_address = "";

				//$indirizzo = solo indirizzo (via e civico), map_adress = indirizzo completo con cap località e provincia per mappa
				if(!empty($offerta->Indirizzo)){
					$map_address .= (string) $offerta->Indirizzo;					
				}
				
				if(!empty($offerta->NrCivico)){
					$map_address .= (string) " ".$offerta->NrCivico;					
				}

				// compilo map_address con cap, località e provincia solo se l'indirizzo (via + civico) è compilato
				// se non lo è vuol dire che non deve essere resa noto l'indirizzo esatto dell'immobile
				if(!empty($offerta->Comune) and !empty($map_address)){

					$comune = (string) $offerta->Comune;
					$comune = trim($comune);
					$map_address .= ", ";

					$cp = cc_get_cap_prov($comune);
					if(!empty($cp)) $map_address .= $cp['cap']." "; 		

					$map_address .= $comune;
					if(!empty($cp)) $map_address .= " (".$cp['prov'].")"; 

				}

				$map_address = trim($map_address);
				if($map_address[0] == ',') $map_address = substr($map_address, 2);	
				
				
				update_post_meta( $post_id, "fave_property_map_address", $map_address );

				$results[] = $post_id." => ".$idunique." - map_address: ".$map_address;				
				
			}
			
			$dbg = var_export($results, true);
	
			cc_import_immobili_error_log($dbg);

			
		}else{
			cc_import_immobili_error_log("no offerte!");
		}
		
	}else{
		cc_import_immobili_error_log("no xml!");
	}
	
	
}








/* funzione help che restiusce cap in base a località in caso che il cap mancasse */
function cc_get_cap_prov($localita, $what = "all"){
	
	$localita = strtolower(trim($localita));
	$ser = 'a:237:{s:8:"arenzano";a:2:{s:3:"cap";s:5:"16011";s:4:"prov";s:2:"GE";}s:6:"avegno";a:2:{s:3:"cap";s:5:"16030";s:4:"prov";s:2:"GE";}s:8:"bargagli";a:2:{s:3:"cap";s:5:"16021";s:4:"prov";s:2:"GE";}s:9:"bogliasco";a:2:{s:3:"cap";s:5:"16031";s:4:"prov";s:2:"GE";}s:10:"borzonasca";a:2:{s:3:"cap";s:5:"16041";s:4:"prov";s:2:"GE";}s:7:"busalla";a:2:{s:3:"cap";s:5:"16012";s:4:"prov";s:2:"GE";}s:7:"camogli";a:2:{s:3:"cap";s:5:"16032";s:4:"prov";s:2:"GE";}s:12:"campo ligure";a:2:{s:3:"cap";s:5:"16013";s:4:"prov";s:2:"GE";}s:11:"campomorone";a:2:{s:3:"cap";s:5:"16014";s:4:"prov";s:2:"GE";}s:7:"carasco";a:2:{s:3:"cap";s:5:"16042";s:4:"prov";s:2:"GE";}s:14:"casarza ligure";a:2:{s:3:"cap";s:5:"16030";s:4:"prov";s:2:"GE";}s:7:"casella";a:2:{s:3:"cap";s:5:"16015";s:4:"prov";s:2:"GE";}s:22:"castiglione chiavarese";a:2:{s:3:"cap";s:5:"16030";s:4:"prov";s:2:"GE";}s:8:"ceranesi";a:2:{s:3:"cap";s:5:"16014";s:4:"prov";s:2:"GE";}s:8:"chiavari";a:2:{s:3:"cap";s:5:"16043";s:4:"prov";s:2:"GE";}s:7:"cicagna";a:2:{s:3:"cap";s:5:"16044";s:4:"prov";s:2:"GE";}s:8:"cogoleto";a:2:{s:3:"cap";s:5:"16016";s:4:"prov";s:2:"GE";}s:7:"cogorno";a:2:{s:3:"cap";s:5:"16030";s:4:"prov";s:2:"GE";}s:15:"coreglia ligure";a:2:{s:3:"cap";s:5:"16040";s:4:"prov";s:2:"GE";}s:12:"crocefieschi";a:2:{s:3:"cap";s:5:"16010";s:4:"prov";s:2:"GE";}s:7:"davagna";a:2:{s:3:"cap";s:5:"16022";s:4:"prov";s:2:"GE";}s:6:"fascia";a:2:{s:3:"cap";s:5:"16020";s:4:"prov";s:2:"GE";}s:17:"favale di malvaro";a:2:{s:3:"cap";s:5:"16040";s:4:"prov";s:2:"GE";}s:12:"fontanigorda";a:2:{s:3:"cap";s:5:"16023";s:4:"prov";s:2:"GE";}s:6:"genova";a:2:{s:3:"cap";s:5:"16100";s:4:"prov";s:2:"GE";}s:7:"gorreto";a:2:{s:3:"cap";s:5:"16020";s:4:"prov";s:2:"GE";}s:17:"isola del cantone";a:2:{s:3:"cap";s:5:"16017";s:4:"prov";s:2:"GE";}s:7:"lavagna";a:2:{s:3:"cap";s:5:"16033";s:4:"prov";s:2:"GE";}s:5:"leivi";a:2:{s:3:"cap";s:5:"16040";s:4:"prov";s:2:"GE";}s:7:"lorsica";a:2:{s:3:"cap";s:5:"16045";s:4:"prov";s:2:"GE";}s:7:"lumarzo";a:2:{s:3:"cap";s:5:"16024";s:4:"prov";s:2:"GE";}s:6:"masone";a:2:{s:3:"cap";s:5:"16010";s:4:"prov";s:2:"GE";}s:4:"mele";a:2:{s:3:"cap";s:5:"16010";s:4:"prov";s:2:"GE";}s:9:"mezzanego";a:2:{s:3:"cap";s:5:"16046";s:4:"prov";s:2:"GE";}s:9:"mignanego";a:2:{s:3:"cap";s:5:"16018";s:4:"prov";s:2:"GE";}s:8:"moconesi";a:2:{s:3:"cap";s:5:"16047";s:4:"prov";s:2:"GE";}s:8:"moneglia";a:2:{s:3:"cap";s:5:"16030";s:4:"prov";s:2:"GE";}s:10:"montebruno";a:2:{s:3:"cap";s:5:"16025";s:4:"prov";s:2:"GE";}s:9:"montoggio";a:2:{s:3:"cap";s:5:"16026";s:4:"prov";s:2:"GE";}s:2:"ne";a:2:{s:3:"cap";s:5:"16040";s:4:"prov";s:2:"GE";}s:7:"neirone";a:2:{s:3:"cap";s:5:"16040";s:4:"prov";s:2:"GE";}s:5:"orero";a:2:{s:3:"cap";s:5:"16040";s:4:"prov";s:2:"GE";}s:12:"pieve ligure";a:2:{s:3:"cap";s:5:"16030";s:4:"prov";s:2:"GE";}s:9:"portofino";a:2:{s:3:"cap";s:5:"16034";s:4:"prov";s:2:"GE";}s:7:"propata";a:2:{s:3:"cap";s:5:"16027";s:4:"prov";s:2:"GE";}s:7:"rapallo";a:2:{s:3:"cap";s:5:"16035";s:4:"prov";s:2:"GE";}s:5:"recco";a:2:{s:3:"cap";s:5:"16036";s:4:"prov";s:2:"GE";}s:10:"rezzoaglio";a:2:{s:3:"cap";s:5:"16048";s:4:"prov";s:2:"GE";}s:13:"ronco scrivia";a:2:{s:3:"cap";s:5:"16019";s:4:"prov";s:2:"GE";}s:9:"rondanina";a:2:{s:3:"cap";s:5:"16025";s:4:"prov";s:2:"GE";}s:11:"rossiglione";a:2:{s:3:"cap";s:5:"16010";s:4:"prov";s:2:"GE";}s:7:"rovegno";a:2:{s:3:"cap";s:5:"16028";s:4:"prov";s:2:"GE";}s:23:"san colombano certenoli";a:2:{s:3:"cap";s:5:"16040";s:4:"prov";s:2:"GE";}s:11:"sant\'olcese";a:2:{s:3:"cap";s:5:"16010";s:4:"prov";s:2:"GE";}s:23:"santa margherita ligure";a:2:{s:3:"cap";s:5:"16038";s:4:"prov";s:2:"GE";}s:21:"santo stefano d\'aveto";a:2:{s:3:"cap";s:5:"16049";s:4:"prov";s:2:"GE";}s:9:"savignone";a:2:{s:3:"cap";s:5:"16010";s:4:"prov";s:2:"GE";}s:12:"serra riccò";a:2:{s:3:"cap";s:5:"16010";s:4:"prov";s:2:"GE";}s:14:"sestri levante";a:2:{s:3:"cap";s:5:"16039";s:4:"prov";s:2:"GE";}s:4:"sori";a:2:{s:3:"cap";s:5:"16030";s:4:"prov";s:2:"GE";}s:8:"tiglieto";a:2:{s:3:"cap";s:5:"16010";s:4:"prov";s:2:"GE";}s:9:"torriglia";a:2:{s:3:"cap";s:5:"16029";s:4:"prov";s:2:"GE";}s:8:"tribogna";a:2:{s:3:"cap";s:5:"16030";s:4:"prov";s:2:"GE";}s:5:"uscio";a:2:{s:3:"cap";s:5:"16030";s:4:"prov";s:2:"GE";}s:11:"valbrevenna";a:2:{s:3:"cap";s:5:"16010";s:4:"prov";s:2:"GE";}s:6:"vobbia";a:2:{s:3:"cap";s:5:"16010";s:4:"prov";s:2:"GE";}s:6:"zoagli";a:2:{s:3:"cap";s:5:"16030";s:4:"prov";s:2:"GE";}s:6:"airole";a:2:{s:3:"cap";s:5:"18030";s:4:"prov";s:2:"IM";}s:8:"apricale";a:2:{s:3:"cap";s:5:"18035";s:4:"prov";s:2:"IM";}s:17:"aquila d\'arroscia";a:2:{s:3:"cap";s:5:"18020";s:4:"prov";s:2:"IM";}s:14:"arma di taggia";a:2:{s:3:"cap";s:5:"18018";s:4:"prov";s:2:"IM";}s:4:"armo";a:2:{s:3:"cap";s:5:"18026";s:4:"prov";s:2:"IM";}s:6:"aurigo";a:2:{s:3:"cap";s:5:"18020";s:4:"prov";s:2:"IM";}s:9:"badalucco";a:2:{s:3:"cap";s:5:"18010";s:4:"prov";s:2:"IM";}s:7:"bajardo";a:2:{s:3:"cap";s:5:"18031";s:4:"prov";s:2:"IM";}s:10:"bordighera";a:2:{s:3:"cap";s:5:"18012";s:4:"prov";s:2:"IM";}s:20:"borghetto d\'arroscia";a:2:{s:3:"cap";s:5:"18020";s:4:"prov";s:2:"IM";}s:9:"borgomaro";a:2:{s:3:"cap";s:5:"18021";s:4:"prov";s:2:"IM";}s:10:"camporosso";a:2:{s:3:"cap";s:5:"18033";s:4:"prov";s:2:"IM";}s:10:"caravonica";a:2:{s:3:"cap";s:5:"18020";s:4:"prov";s:2:"IM";}s:8:"carpasio";a:2:{s:3:"cap";s:5:"18010";s:4:"prov";s:2:"IM";}s:15:"castel vittorio";a:2:{s:3:"cap";s:5:"18030";s:4:"prov";s:2:"IM";}s:10:"castellaro";a:2:{s:3:"cap";s:5:"18011";s:4:"prov";s:2:"IM";}s:7:"ceriana";a:2:{s:3:"cap";s:5:"18034";s:4:"prov";s:2:"IM";}s:5:"cervo";a:2:{s:3:"cap";s:5:"18010";s:4:"prov";s:2:"IM";}s:5:"cesio";a:2:{s:3:"cap";s:5:"18022";s:4:"prov";s:2:"IM";}s:10:"chiusanico";a:2:{s:3:"cap";s:5:"18027";s:4:"prov";s:2:"IM";}s:13:"chiusavecchia";a:2:{s:3:"cap";s:5:"18027";s:4:"prov";s:2:"IM";}s:8:"cipressa";a:2:{s:3:"cap";s:5:"18017";s:4:"prov";s:2:"IM";}s:7:"civezza";a:2:{s:3:"cap";s:5:"18017";s:4:"prov";s:2:"IM";}s:16:"cosio d\'arroscia";a:2:{s:3:"cap";s:5:"18023";s:4:"prov";s:2:"IM";}s:12:"costarainera";a:2:{s:3:"cap";s:5:"18017";s:4:"prov";s:2:"IM";}s:14:"diano arentino";a:2:{s:3:"cap";s:5:"18013";s:4:"prov";s:2:"IM";}s:14:"diano castello";a:2:{s:3:"cap";s:5:"18013";s:4:"prov";s:2:"IM";}s:12:"diano marina";a:2:{s:3:"cap";s:5:"18013";s:4:"prov";s:2:"IM";}s:16:"diano san pietro";a:2:{s:3:"cap";s:5:"18013";s:4:"prov";s:2:"IM";}s:10:"dolceacqua";a:2:{s:3:"cap";s:5:"18035";s:4:"prov";s:2:"IM";}s:7:"dolcedo";a:2:{s:3:"cap";s:5:"18020";s:4:"prov";s:2:"IM";}s:7:"imperia";a:2:{s:3:"cap";s:5:"18100";s:4:"prov";s:2:"IM";}s:9:"isolabona";a:2:{s:3:"cap";s:5:"18035";s:4:"prov";s:2:"IM";}s:9:"lucinasco";a:2:{s:3:"cap";s:5:"18020";s:4:"prov";s:2:"IM";}s:9:"mendatica";a:2:{s:3:"cap";s:5:"18025";s:4:"prov";s:2:"IM";}s:16:"molini di triora";a:2:{s:3:"cap";s:5:"18010";s:4:"prov";s:2:"IM";}s:15:"montalto ligure";a:2:{s:3:"cap";s:5:"18010";s:4:"prov";s:2:"IM";}s:22:"montegrosso pian latte";a:2:{s:3:"cap";s:5:"18025";s:4:"prov";s:2:"IM";}s:20:"olivetta san michele";a:2:{s:3:"cap";s:5:"18030";s:4:"prov";s:2:"IM";}s:11:"ospedaletti";a:2:{s:3:"cap";s:5:"18014";s:4:"prov";s:2:"IM";}s:9:"perinaldo";a:2:{s:3:"cap";s:5:"18032";s:4:"prov";s:2:"IM";}s:11:"pietrabruna";a:2:{s:3:"cap";s:5:"18010";s:4:"prov";s:2:"IM";}s:13:"pieve di teco";a:2:{s:3:"cap";s:5:"18026";s:4:"prov";s:2:"IM";}s:5:"pigna";a:2:{s:3:"cap";s:5:"18037";s:4:"prov";s:2:"IM";}s:9:"pompeiana";a:2:{s:3:"cap";s:5:"18015";s:4:"prov";s:2:"IM";}s:11:"pontedassio";a:2:{s:3:"cap";s:5:"18027";s:4:"prov";s:2:"IM";}s:9:"pornassio";a:2:{s:3:"cap";s:5:"18024";s:4:"prov";s:2:"IM";}s:6:"prelà";a:2:{s:3:"cap";s:5:"18020";s:4:"prov";s:2:"IM";}s:5:"ranzo";a:2:{s:3:"cap";s:5:"18020";s:4:"prov";s:2:"IM";}s:5:"rezzo";a:2:{s:3:"cap";s:5:"18026";s:4:"prov";s:2:"IM";}s:11:"riva ligure";a:2:{s:3:"cap";s:5:"18015";s:4:"prov";s:2:"IM";}s:17:"rocchetta nervina";a:2:{s:3:"cap";s:5:"18030";s:4:"prov";s:2:"IM";}s:22:"san bartolomeo al mare";a:2:{s:3:"cap";s:5:"18016";s:4:"prov";s:2:"IM";}s:21:"san biagio della cima";a:2:{s:3:"cap";s:5:"18036";s:4:"prov";s:2:"IM";}s:19:"san lorenzo al mare";a:2:{s:3:"cap";s:5:"18017";s:4:"prov";s:2:"IM";}s:7:"sanremo";a:2:{s:3:"cap";s:5:"18038";s:4:"prov";s:2:"IM";}s:21:"santo stefano al mare";a:2:{s:3:"cap";s:5:"18010";s:4:"prov";s:2:"IM";}s:7:"seborga";a:2:{s:3:"cap";s:5:"18012";s:4:"prov";s:2:"IM";}s:7:"soldano";a:2:{s:3:"cap";s:5:"18036";s:4:"prov";s:2:"IM";}s:6:"taggia";a:2:{s:3:"cap";s:5:"18018";s:4:"prov";s:2:"IM";}s:8:"terzorio";a:2:{s:3:"cap";s:5:"18010";s:4:"prov";s:2:"IM";}s:6:"triora";a:2:{s:3:"cap";s:5:"18010";s:4:"prov";s:2:"IM";}s:9:"vallebona";a:2:{s:3:"cap";s:5:"18012";s:4:"prov";s:2:"IM";}s:11:"vallecrosia";a:2:{s:3:"cap";s:5:"18019";s:4:"prov";s:2:"IM";}s:5:"vasia";a:2:{s:3:"cap";s:5:"18020";s:4:"prov";s:2:"IM";}s:11:"ventimiglia";a:2:{s:3:"cap";s:5:"18039";s:4:"prov";s:2:"IM";}s:9:"vessalico";a:2:{s:3:"cap";s:5:"18026";s:4:"prov";s:2:"IM";}s:13:"villa faraldi";a:2:{s:3:"cap";s:5:"18010";s:4:"prov";s:2:"IM";}s:7:"ameglia";a:2:{s:3:"cap";s:5:"19031";s:4:"prov";s:2:"SP";}s:6:"arcola";a:2:{s:3:"cap";s:5:"19021";s:4:"prov";s:2:"SP";}s:8:"beverino";a:2:{s:3:"cap";s:5:"19020";s:4:"prov";s:2:"SP";}s:6:"bolano";a:2:{s:3:"cap";s:5:"19020";s:4:"prov";s:2:"SP";}s:9:"bonassola";a:2:{s:3:"cap";s:5:"19011";s:4:"prov";s:2:"SP";}s:17:"borghetto di vara";a:2:{s:3:"cap";s:5:"19020";s:4:"prov";s:2:"SP";}s:8:"brugnato";a:2:{s:3:"cap";s:5:"19020";s:4:"prov";s:2:"SP";}s:21:"calice al cornoviglio";a:2:{s:3:"cap";s:5:"19020";s:4:"prov";s:2:"SP";}s:5:"carro";a:2:{s:3:"cap";s:5:"19012";s:4:"prov";s:2:"SP";}s:9:"carrodano";a:2:{s:3:"cap";s:5:"19020";s:4:"prov";s:2:"SP";}s:17:"castelnuovo magra";a:2:{s:3:"cap";s:5:"19033";s:4:"prov";s:2:"SP";}s:12:"deiva marina";a:2:{s:3:"cap";s:5:"19013";s:4:"prov";s:2:"SP";}s:5:"follo";a:2:{s:3:"cap";s:5:"19020";s:4:"prov";s:2:"SP";}s:7:"framura";a:2:{s:3:"cap";s:5:"19014";s:4:"prov";s:2:"SP";}s:9:"la spezia";a:2:{s:3:"cap";s:5:"19100";s:4:"prov";s:2:"SP";}s:6:"lerici";a:2:{s:3:"cap";s:5:"19032";s:4:"prov";s:2:"SP";}s:7:"levanto";a:2:{s:3:"cap";s:5:"19015";s:4:"prov";s:2:"SP";}s:8:"maissana";a:2:{s:3:"cap";s:5:"19010";s:4:"prov";s:2:"SP";}s:18:"monterosso al mare";a:2:{s:3:"cap";s:5:"19016";s:4:"prov";s:2:"SP";}s:8:"ortonovo";a:2:{s:3:"cap";s:5:"19034";s:4:"prov";s:2:"SP";}s:7:"pignone";a:2:{s:3:"cap";s:5:"19020";s:4:"prov";s:2:"SP";}s:11:"portovenere";a:2:{s:3:"cap";s:5:"19025";s:4:"prov";s:2:"SP";}s:26:"riccò del golfo di spezia";a:2:{s:3:"cap";s:5:"19020";s:4:"prov";s:2:"SP";}s:11:"riomaggiore";a:2:{s:3:"cap";s:5:"19017";s:4:"prov";s:2:"SP";}s:17:"rocchetta di vara";a:2:{s:3:"cap";s:5:"19020";s:4:"prov";s:2:"SP";}s:22:"santo stefano di magra";a:2:{s:3:"cap";s:5:"19037";s:4:"prov";s:2:"SP";}s:7:"sarzana";a:2:{s:3:"cap";s:5:"19038";s:4:"prov";s:2:"SP";}s:12:"sesta godano";a:2:{s:3:"cap";s:5:"19020";s:4:"prov";s:2:"SP";}s:13:"varese ligure";a:2:{s:3:"cap";s:5:"19028";s:4:"prov";s:2:"SP";}s:8:"vernazza";a:2:{s:3:"cap";s:5:"19018";s:4:"prov";s:2:"SP";}s:14:"vezzano ligure";a:2:{s:3:"cap";s:5:"19020";s:4:"prov";s:2:"SP";}s:7:"zignago";a:2:{s:3:"cap";s:5:"19020";s:4:"prov";s:2:"SP";}s:7:"alassio";a:2:{s:3:"cap";s:5:"17021";s:4:"prov";s:2:"SV";}s:7:"albenga";a:2:{s:3:"cap";s:5:"17031";s:4:"prov";s:2:"SV";}s:18:"albisola superiore";a:2:{s:3:"cap";s:5:"17011";s:4:"prov";s:2:"SV";}s:16:"albissola marina";a:2:{s:3:"cap";s:5:"17012";s:4:"prov";s:2:"SV";}s:6:"altare";a:2:{s:3:"cap";s:5:"17041";s:4:"prov";s:2:"SV";}s:6:"andora";a:2:{s:3:"cap";s:5:"17051";s:4:"prov";s:2:"SV";}s:7:"arnasco";a:2:{s:3:"cap";s:5:"17032";s:4:"prov";s:2:"SV";}s:10:"balestrino";a:2:{s:3:"cap";s:5:"17020";s:4:"prov";s:2:"SV";}s:9:"bardineto";a:2:{s:3:"cap";s:5:"17057";s:4:"prov";s:2:"SV";}s:8:"bergeggi";a:2:{s:3:"cap";s:5:"17028";s:4:"prov";s:2:"SV";}s:8:"boissano";a:2:{s:3:"cap";s:5:"17054";s:4:"prov";s:2:"SV";}s:23:"borghetto santo spirito";a:2:{s:3:"cap";s:5:"17052";s:4:"prov";s:2:"SV";}s:14:"borgio verezzi";a:2:{s:3:"cap";s:5:"17022";s:4:"prov";s:2:"SV";}s:7:"bormida";a:2:{s:3:"cap";s:5:"17045";s:4:"prov";s:2:"SV";}s:16:"cairo montenotte";a:2:{s:3:"cap";s:5:"17014";s:4:"prov";s:2:"SV";}s:13:"calice ligure";a:2:{s:3:"cap";s:5:"17020";s:4:"prov";s:2:"SV";}s:9:"calizzano";a:2:{s:3:"cap";s:5:"17057";s:4:"prov";s:2:"SV";}s:7:"carcare";a:2:{s:3:"cap";s:5:"17043";s:4:"prov";s:2:"SV";}s:16:"casanova lerrone";a:2:{s:3:"cap";s:5:"17033";s:4:"prov";s:2:"SV";}s:12:"castelbianco";a:2:{s:3:"cap";s:5:"17030";s:4:"prov";s:2:"SV";}s:30:"castelvecchio di rocca barbena";a:2:{s:3:"cap";s:5:"17034";s:4:"prov";s:2:"SV";}s:12:"celle ligure";a:2:{s:3:"cap";s:5:"17015";s:4:"prov";s:2:"SV";}s:6:"cengio";a:2:{s:3:"cap";s:5:"17056";s:4:"prov";s:2:"SV";}s:7:"ceriale";a:2:{s:3:"cap";s:5:"17023";s:4:"prov";s:2:"SV";}s:15:"cisano sul neva";a:2:{s:3:"cap";s:5:"17035";s:4:"prov";s:2:"SV";}s:8:"cosseria";a:2:{s:3:"cap";s:5:"17017";s:4:"prov";s:2:"SV";}s:4:"dego";a:2:{s:3:"cap";s:5:"17058";s:4:"prov";s:2:"SV";}s:4:"erli";a:2:{s:3:"cap";s:5:"17030";s:4:"prov";s:2:"SV";}s:13:"finale ligure";a:2:{s:3:"cap";s:5:"17024";s:4:"prov";s:2:"SV";}s:8:"garlenda";a:2:{s:3:"cap";s:5:"17033";s:4:"prov";s:2:"SV";}s:10:"giustenice";a:2:{s:3:"cap";s:5:"17027";s:4:"prov";s:2:"SV";}s:9:"giusvalla";a:2:{s:3:"cap";s:5:"17010";s:4:"prov";s:2:"SV";}s:10:"laigueglia";a:2:{s:3:"cap";s:5:"17053";s:4:"prov";s:2:"SV";}s:5:"loano";a:2:{s:3:"cap";s:5:"17025";s:4:"prov";s:2:"SV";}s:8:"magliolo";a:2:{s:3:"cap";s:5:"17020";s:4:"prov";s:2:"SV";}s:7:"mallare";a:2:{s:3:"cap";s:5:"17045";s:4:"prov";s:2:"SV";}s:9:"massimino";a:2:{s:3:"cap";s:5:"12071";s:4:"prov";s:2:"SV";}s:9:"millesimo";a:2:{s:3:"cap";s:5:"17017";s:4:"prov";s:2:"SV";}s:7:"mioglia";a:2:{s:3:"cap";s:5:"17040";s:4:"prov";s:2:"SV";}s:8:"murialdo";a:2:{s:3:"cap";s:5:"17013";s:4:"prov";s:2:"SV";}s:6:"nasino";a:2:{s:3:"cap";s:5:"17030";s:4:"prov";s:2:"SV";}s:4:"noli";a:2:{s:3:"cap";s:5:"17026";s:4:"prov";s:2:"SV";}s:4:"onzo";a:2:{s:3:"cap";s:5:"17037";s:4:"prov";s:2:"SV";}s:12:"orco feglino";a:2:{s:3:"cap";s:5:"17024";s:4:"prov";s:2:"SV";}s:8:"ortovero";a:2:{s:3:"cap";s:5:"17037";s:4:"prov";s:2:"SV";}s:7:"osiglia";a:2:{s:3:"cap";s:5:"17010";s:4:"prov";s:2:"SV";}s:7:"pallare";a:2:{s:3:"cap";s:5:"17043";s:4:"prov";s:2:"SV";}s:12:"piana crixia";a:2:{s:3:"cap";s:5:"17058";s:4:"prov";s:2:"SV";}s:13:"pietra ligure";a:2:{s:3:"cap";s:5:"17027";s:4:"prov";s:2:"SV";}s:6:"plodio";a:2:{s:3:"cap";s:5:"17043";s:4:"prov";s:2:"SV";}s:10:"pontinvrea";a:2:{s:3:"cap";s:5:"17042";s:4:"prov";s:2:"SV";}s:8:"quiliano";a:2:{s:3:"cap";s:5:"17047";s:4:"prov";s:2:"SV";}s:6:"rialto";a:2:{s:3:"cap";s:5:"17020";s:4:"prov";s:2:"SV";}s:12:"roccavignale";a:2:{s:3:"cap";s:5:"17017";s:4:"prov";s:2:"SV";}s:8:"sassello";a:2:{s:3:"cap";s:5:"17046";s:4:"prov";s:2:"SV";}s:6:"savona";a:2:{s:3:"cap";s:5:"17100";s:4:"prov";s:2:"SV";}s:8:"spotorno";a:2:{s:3:"cap";s:5:"17028";s:4:"prov";s:2:"SV";}s:6:"stella";a:2:{s:3:"cap";s:5:"17044";s:4:"prov";s:2:"SV";}s:11:"stellanello";a:2:{s:3:"cap";s:5:"17020";s:4:"prov";s:2:"SV";}s:7:"testico";a:2:{s:3:"cap";s:5:"17020";s:4:"prov";s:2:"SV";}s:7:"toirano";a:2:{s:3:"cap";s:5:"17055";s:4:"prov";s:2:"SV";}s:16:"tovo san giacomo";a:2:{s:3:"cap";s:5:"17020";s:4:"prov";s:2:"SV";}s:4:"urbe";a:2:{s:3:"cap";s:5:"17048";s:4:"prov";s:2:"SV";}s:11:"vado ligure";a:2:{s:3:"cap";s:5:"17047";s:4:"prov";s:2:"SV";}s:7:"varazze";a:2:{s:3:"cap";s:5:"17019";s:4:"prov";s:2:"SV";}s:7:"vendone";a:2:{s:3:"cap";s:5:"17032";s:4:"prov";s:2:"SV";}s:5:"verzi";a:2:{s:3:"cap";s:5:"17021";s:4:"prov";s:2:"SV";}s:12:"vezzi portio";a:2:{s:3:"cap";s:5:"17028";s:4:"prov";s:2:"SV";}s:19:"villanova d\'albenga";a:2:{s:3:"cap";s:5:"17038";s:4:"prov";s:2:"SV";}s:10:"zuccarello";a:2:{s:3:"cap";s:5:"17039";s:4:"prov";s:2:"SV";}}';
	$unser = unserialize($ser);
	
	if (array_key_exists($localita, $unser)){
		$result = $unser[$localita];
		return ($what == "all") ? $result : $result[$what];
	}else{
		return array();
	}
	
	
}




/* FUNZIONE UNA TANTUM PER AGGIUNGER META 'fave_featured' A QUELLI A CUI MANCAVA. ORA HO IMPLEMENTATO cc_insert_record
   E LO INSERISC A VALORE 0 QUANDO CREA UN NUOVO RECORD */

 function aggiornaFaveFeatured(){
	 
	global $wpdb;
	$metas = array();
	$key = '_id_cometa';
	
    if( !empty( $key ) ){
		
		$qry = $wpdb->prepare( 
				"SELECT post_id, meta_value 
				FROM {$wpdb->postmeta} 
				WHERE meta_key = '%s'", 
				$key
			);

		$res = $wpdb->get_results( $qry );
		
		if($res){
			
			
			foreach ( $res as $r ) {
				$post_id = $r->post_id;
				
				// recupero se c'è valore fave_featured
				$featured = get_post_meta( $post_id, 'fave_featured', true );
				
				if($featured == ''){
					
					$result = add_post_meta($post_id, 'fave_featured', '0');
					
					$msg = $post_id." non aveva fave_featured, ora l'ho inserito. Result: ";
					$msg .= var_export($result, true);
					cc_import_immobili_error_log($msg);
					
				}
				
			}			
			
		}
		
	}	    
	 
 }


// FUNZONE UNA TANTUM AGGIORNO GLI IMMOBILI IMPOSTANDO CORRETTAMENTE TRATTATIVA RISERVATA
function setTrattativaRiservata(){
	
	global $wpdb;
	
	$t = 0;
	
	// nome tabella post meta
	$tbl = $wpdb->prefix.'postmeta';
	
	// IMPORTA XML
	$path = ABSPATH . "import/";	
	$file = FILE_XML_IMMOBILI;
	$xml = @simplexml_load_file($path.$file);
	
	$dbg2 = array();

	if($xml){		

		$offerte = $xml->Offerte;

		if($offerte){

			foreach($offerte as $offerta){
				
				$trattativa = (string) $offerta->TrattativaRiservata;
				
				if($trattativa == '1'){
					
					$prezzo = (string) $offerta->Prezzo;
					$fave_private_note = "Prezzo richiesto €".$prezzo;	
					$rif = (string) $offerta->Riferimento;
					
					$prepare_qry = $wpdb->prepare( "SELECT post_id FROM ".$tbl." WHERE meta_key = 'fave_property_id' AND meta_value = '%s'", $rif );
					$post_id = $wpdb->get_col( $prepare_qry, 0 );

					if(!empty($post_id)){
						$dbg2[] = $rid." (".$post_id.")";
						update_post_meta( $post_id[0], "fave_property_price", "Trattativa Riservata" );				
						update_post_meta( $post_id[0], "fave_private_note", $fave_private_note );				
					}
					
				}
			} // end foreach
			$dbgtratt = implode(", ", $dbg2);
			cc_import_immobili_error_log("DBG-TRATT: ".$dbgtratt);
			
		} // end if offerte
		
	} // end if xml

	
	
} 




// NON PIU' UTILIZZATO VEDI FUNZIONE cc_set_immobili_in_vetrina
// DECIDO IL VALORE DI VETRINA IN BASE ALL'ARRAY $invetrina ( ['IdAgenzia'] => ['imm'] => rif, ['data'] => dataAggioranamento )
function cc_ho_vetrina($agenzia, $rif){
	global $invetrina;
	
	// $agenzia qua è il numero interno di Cometa
	if( isset( $invetrina[$agenzia] ) ){		
		
		if ($invetrina[$agenzia]['imm'] == $rif) {
			// questo immobile è in vetrina
			$vetrina = '1';
			cc_import_immobili_error_log($rif." è in vetrina");
			
			// vediamo se è lo stesso di quello che era già in vetrina o meno
			$oldrif = cc_get_rif_vetrina( substr($rif, 0, 2) );
			cc_import_immobili_error_log("oldrif: ".$oldrif);
			
			// se l'immobile attualemnte in vetrina non è questo tolgo quello attuale da vetrina
			if($oldrif != $rif) {
				cc_updateVetrina($oldrif); 
				cc_import_immobili_error_log("Tolgo vetrina da ".$oldrif);
			}
		}else{
			$vetrina = '0';
		}		
	
	}else{
		$vetrina = '0';
	}
	
	return $vetrina;
}

// NON PIU' UTILIZZATO
// in base al numero agenzia (non quello interno Cometa, ma solito nostro)recupera rif dell'immobilie attualmente in vetrina
function cc_get_rif_vetrina($agenzia){
	
	global $wpdb; 
	
	$tbl = $wpdb->prefix.'postmeta';
	
	// agenzia dev'essere un numero
	$agenzia = (int) $agenzia;
	if(empty($agenzia)) return false;
	
	$filter = str_pad($agenzia, 2, '0', STR_PAD_LEFT) . "VE%";
	$prepare_qry = $wpdb->prepare( "SELECT meta_value FROM ".$tbl." 
	WHERE meta_key = 'fave_property_id' AND meta_value LIKE '%s' 
	AND post_id IN (SELECT post_id FROM ".$tbl." WHERE meta_key = 'fave_featured' AND meta_value = '1')", $filter );
	
	$col = $wpdb->get_col( $prepare_qry, 0 ); 
	return $col[0];
}


// NON PIU' UTILIZZATO
// aggiorno valore di vetrina di $rif in base a valori passato (2° parametro)
function cc_updateVetrina($rif, $value = 0){
	
	global $wpdb; 
	
	$value = (int) $value;
	if($value > 1) $value = 1;
	if($value < 0) $value = 0;
		
	$tbl = $wpdb->prefix.'postmeta';

	$prepare_qry = $wpdb->prepare( "SELECT post_id FROM ".$tbl." WHERE meta_key = 'fave_property_id' AND meta_value = '%s'", $rif );
	$post_id = $wpdb->get_col( $prepare_qry, 0 ); // secondo param è ilnum della colonna da restituire

	if(!empty($post_id)){
		update_post_meta( $post_id[0], "fave_featured", $value );				
	}
	
	return $value;
	
}



?>