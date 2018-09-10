<?php
$content = file_get_contents("php://input");	// recupero il contenuto inviato da Telegram
$update = json_decode($content, true);			// converto il contenuto da JSON ad array PHP
if(!$update){
	exit;										// se la richiesta è null interrompo lo script
}
date_default_timezone_set("Europe/Rome");
error_log("Ricevuta richiesta da Telegram. Processamento dei dati...");

// assegno alle seguenti variabili il contenuto ricevuto da Telegram
$message = isset($update['message']) ? $update['message'] : "";
$messageId = isset($message['message_id']) ? $message['message_id'] : "";
$chatId = isset($message['chat']['id']) ? $message['chat']['id'] : "";

//$firstname = isset($message['chat']['first_name']) ? $message['chat']['first_name'] : "";
$firstname = isset($message['from']['first_name']) ? $message['from']['first_name'] : "";

$lastname = isset($message['from']['last_name']) ? $message['from']['last_name'] : "";

$username = isset($message['from']['username']) ? $message['from']['username'] : "";

$date = isset($message['date']) ? $message['date'] : "";
$text = isset($message['text']) ? $message['text'] : "";

$text = trim($text);							// pulisco il messaggio ricevuto togliendo eventuali spazi prima e dopo il testo
//$text = strtolower($text);						// converto tutti i caratteri alfanumerici del messaggio in minuscolo

//------------####################################-----------END INIT-----------####################################---------------------
error_log("Dati estratti. Connessione al database...");
$mysqli = connect_db();
$me = getenv('me');

//Connessione al	 DB fallita
if ($mysqli->connect_error) {
	$chatId = $me;
	//$log = "Errore di connessione (#{$mysqli->connect_errno}) -> {$mysqli->connect_error}";
	error_log("Errore di connessione (#{$mysqli->connect_errno}) -> {$mysqli->connect_error}");
}
//La connessione al DB è andata a buon fine
 else {
    //$log = "Connessione riuscita";
	error_log("Connessione al database riuscita");
	
	$sql = "SELECT * FROM conversazioni ORDER BY data DESC";
	$result = $mysqli->query($sql);
	$mydate = date("Y-m-d H:i:s");
	$found = 0;
	//Sono io
	if (strcmp($chatId, $me) == 0){
		error_log("Il messaggio viene da me stesso");
		//Invio i dati del database a me stesso
		if(strcmp($text, "/database") == 0){
			error_log("[Info database] Richieste info dal database");
			$text = get_db_data($result);
		}
		//Funzionamento solito
		else {
			if ($result->num_rows > 0) {
				error_log("Ricerca utenti in attesa di risposta...");
				while ($row = $result->fetch_array(MYSQLI_ASSOC)){
					//Il primo utente in sospeso che trovo
					if (strcmp($row["in_sospeso"], "1") == 0){
						error_log("L'utente \"{$row["username"]}\" è in attesa di risposta");
						$found = 1;
						$chatId = $row["id"];
						$sql = "UPDATE conversazioni SET in_sospeso = \"0\", data = '$mydate' WHERE id = $chatId";
						//$sql = "UPDATE conversazioni SET in_sospeso = \"0\" WHERE id = $chatId";
						$result = $mysqli->query($sql);
						break;
					}
				}
				if(strcmp($text, "/nulla") == 0){
					error_log("Rilevato comando \"/nulla\"");
					if($found == 1){
						error_log("L'utente verrà ignorato");
						$text = "Ho ignorato l'utente";
					}
					else {
						error_log("Non c'è nessun utente da ignorare");
						$text = "Non c'è nessun utente da ignorare";
					}
					$chatId = $me;
					$found = -1;
				}
				//Nessun utente in sospeso
				else if ($found == 0){
					//Scrivo qualcosa a me stesso
					$chatId = $me;
					$sql = "UPDATE conversazioni SET data = '$mydate' WHERE id = $chatId";
					$result = $mysqli->query($sql);
					error_log("Nessun utente è in attesa di risposta");
					//$text = "Messaggio di default a me stesso";
					//$text = $text . " \xF0\x9F\x98\x81";
					$text = "Ricevuto il seguente messaggio da te stesso:\n\n" . $text;
				}
			}
			//Aggiungo me stesso al DB
			else {
				error_log("Il database è vuoto");
				insert_db($mysqli, $chatId, $firstname, $lastname, $username, "0");
			}
		}
	}
	//Qualcun altro
	else {
		error_log("[Messaggio da \"{$username}\"] \"{$text}\"");
		//Se il DB non è vuoto
		if ($result->num_rows > 0) {
			error_log("Ricerca dell'utente nel database...");
			while ($row = $result->fetch_array(MYSQLI_ASSOC)){
			//L'utente esiste già nel DB
				if (strcmp($row["id"], $chatId) == 0){
					$found = 1;
					$sql = "UPDATE conversazioni SET in_sospeso = \"1\", data = '$mydate' WHERE id = $chatId";
					//$sql = "UPDATE conversazioni SET in_sospeso = \"1\" WHERE id = $chatId";
					$result = $mysqli->query($sql);
					error_log("L'utente \"{$username}\" è stato messo in attesa di risposta");
					break;
				}
			}
		}
		else {
			$found = 0;
			error_log("Il database è vuoto");
		}
		//Se non è stato trovato
		if ($found == 0){
			error_log("L'utente non è presente nel database");
			//Inserisco l'utente impostandolo come "in_sospeso"
			insert_db($mysqli, $chatId, $firstname, $lastname, $username, "1");
		}
		//Scrivo a me stesso
		$chatId = $me;
		$text = "{$firstname} (\"{$username}\") mi ha scritto:\n\n<i>{$text}</i>\n\nCosa rispondo?!";
	}
}

//la variabile di log viene inviata come risposta (disattivare quando non piu necessario)
//$text = $log;
//$chatId = $me;

//-----------------FINE DATABASE----------------------------
$mysqli->close();
error_log("La connessione col database è stata chiusa");
error_log("Invio i dati...");
inviaDati($chatId, $text);

function get_db_data($db_results){
	$dbData = "";
	if($db_results->num_rows > 0)
	{
		$dbData = "Il database contiene {$db_results->num_rows} voci:";
		error_log("[Info database] Il database contiene {$db_results->num_rows} voci");
		
		while ($row = $db_results->fetch_array(MYSQLI_ASSOC)){
			try {
				
				/*
				Spunta verde:				\xE2\x9C\x85
				Punto interrogativo rosso:	\xE2\x9D\x93
				Punto esclamativo:			\xE2\x9D\x97
				Faccino sorridente:			\xF0\x9F\x98\x8A
				Orologio:					\xF0\x9F\x95\x91
				Sagoma:						\xF0\x9F\x91\xA4
				*/
				
				if(strlen($row["nome"]) > 0)
					$dbData = $dbData . "\n\n \xF0\x9F\x91\xA4 {$row["nome"]}";
				else $dbData = $dbData . "\n\n \xE2\x9D\x93 SCONOSCIUTO";
				if(strlen($row["username"]) > 0)
					$dbData = $dbData . " (\"{$row["username"]}\")";
				else $dbData = $dbData . " (SCONOSCIUTO)";
				if(strlen($row["data"]) > 0){
					$tmp = $row["data"];
					$objectDate = getdate(strtotime(date($tmp)));
					$dbData = $dbData . "\n \xF0\x9F\x95\x91 Ultima connessione: <code>{$objectDate["mday"]}/{$objectDate["mon"]}/{$objectDate["year"]} - ";
					$dbData = $dbData . "Ora: {$objectDate["hours"]}:{$objectDate["minutes"]}:{$objectDate["seconds"]}</code>";
					//$dbData = $dbData . "\n \xF0\x9F\x95\x91 Ultima connessione: {$row["data"]}";
				}
				if(strlen($row["in_sospeso"]) > 0 && strcmp($row["in_sospeso"], "1") == 0)
					$dbData = $dbData . "\n \xE2\x9D\x97 <b>In attesa di risposta</b> \xE2\x9D\x97 ";
				
				/*
				$tmp = isset($row["nome"]) ? $row["nome"] : "-";
				$dbData = $dbData . "\n{$tmp}";
				$tmp = isset($row["username"]) ? $row["username"] : "-";
				$dbData = $dbData . " > {$tmp}";
				$tmp = isset($row["data"]) ? $row["data"] : "-";
				$dbData = $dbData . " > {$tmp}";
				$tmp = isset($row["in_sospeso"]) ? $row["in_sospeso"] : "-";
				$dbData = $dbData . " > {$tmp}";
				*/
			}
			catch (Exception $e){
				$dbData = $dbData . "\n######Errore:######\n{$e->getMessage()}";
				error_log("[Info database] Problema: {$e->getMessage()}");
			}
		}
	}
	else {
		$dbData = "Il database è vuoto";
		error_log("[Info database] Il database è vuoto");
	}
	return $dbData;
}

function insert_db($mysqli, $id, $frstnm, $lstnm, $usrnm, $in_sospeso){
			$mydate = date("Y-m-d H:i:s");
			$tmp = "";
			$query = "insert into conversazioni (id, nome, cognome, username, data, in_sospeso) VALUES ('$id', '$frstnm', '$lstnm', '$usrnm', '$mydate', '$in_sospeso')";
			if(strcmp($in_sospeso, "1") == 0)
				$tmp = " (in attesa di risposta)";
			if (!$mysqli->query($query)) {
				error_log("[Inserimento in database] Problema: {$mysqli->error}");
			}
			else {
				error_log("Ho inserito {$usrnm} nel database{$tmp}");
			}
}

function connect_db(){
	
	$server = getenv('server_db');
	$username = getenv('username_db');
	$password = getenv('password_db');
	$db = getenv('name_db');
	
	$url = parse_url("mysql://{$username}:{$password}@{$server}/{$db}?reconnect=true");
	
	$conn = new mysqli($server, $username, $password, $db);
	return $conn;
}

function inviaDati($chat, $mex) {
    // mi preparo a restitutire al chiamante la mia risposta che è un oggetto JSON:
	// imposto l'header della risposta
	header("Content-Type: application/json");

	// la mia risposta è un array JSON composto da chat_id, text, method
	// chat_id mi consente di rispondere allo specifico utente che ha scritto al bot
	// text è il testo della risposta
	$parameters = array("parse_mode" => "html", 'chat_id' => $chat, "text" => $mex);

	// method è il metodo per l'invio di un messaggio (cfr. API di Telegram)
	$parameters["method"] = "sendMessage";
	// converto e stampo l'array JSON sulla response
	echo json_encode($parameters);
}
